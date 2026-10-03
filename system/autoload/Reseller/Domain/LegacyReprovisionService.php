<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Activation\ActivationStore;
use Reseller\Activation\RadiusState;
use Reseller\Audit\AuditLog;
use Reseller\Radius\ProvisionFailure;
use Reseller\Radius\ProvisionResult;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\ErrorReporter;
use Reseller\Security\PermissionService;

/**
 * Bounded legacy re-provision of a RESELLER-OWNED customer (WP5B §4; owner decision OD-10): the legacy sync,
 * password change and identity edit stay available, but this service owns the whole operation and the shared
 * tree lock serializes it against activation opens and reversal starts (TreeSerialization::hold, no
 * transaction held across the device call). It never grants service: the billing rows are never written and
 * must be unchanged afterwards; no status, plan or expiry is touched.
 *
 *  1. hold the tree lock;  2. authorize from the database (reseller-owned; SA / A scope G for the admin
 *  operations, the customer itself for the portal ones);  3. no activation in flight (REVERSAL_PENDING
 *  included);  4. billing snapshot;  5. old values of the approved fields;  6. the approved mutation only
 *  (short transaction, customer row locked);  7. the device call with the private per-call permit;
 *  8. billing unchanged;  9. RADIUS verified for Radius-device plans;  10. release.
 *
 * Device outcome: SUCCESS + verified + billing unchanged -> done. FAILED (confirmed not applied) -> the DB
 * mutation is compensated (conditional restore of the old values). UNKNOWN / verify mismatch / billing
 * changed -> the new values are kept, no automatic revert and no retry: REPROVISION_UNCONFIRMED (audit, drift
 * report), the operator converges with ADMIN_SYNC.
 *
 * Device default deny (§4.3): the legacy device grant methods (add_customer / sync_customer / change_username)
 * refuse a reseller-owned customer unless called inside run() for that customer (assertDeviceAllowed).
 */
final class LegacyReprovisionService
{
    public const ADMIN_SYNC = 'ADMIN_SYNC';
    public const ADMIN_BULK_SYNC_ROW = 'ADMIN_BULK_SYNC_ROW';
    public const PORTAL_SYNC = 'PORTAL_SYNC';
    public const PASSWORD_CHANGE = 'PASSWORD_CHANGE';
    public const IDENTITY_EDIT = 'IDENTITY_EDIT';
    public const UNCONFIRMED = 'REPROVISION_UNCONFIRMED';
    /** the only tbl_customers columns a re-provision may change */
    public const IDENTITY_FIELDS = ['username', 'password', 'pppoe_username', 'pppoe_password', 'pppoe_ip'];
    private const OPS = [
        self::ADMIN_SYNC => C::CUSTOMER_SYNC, self::ADMIN_BULK_SYNC_ROW => C::SERVICE_SYNC_ALL, self::IDENTITY_EDIT => C::CUSTOMER_UPDATE,
        self::PORTAL_SYNC => null, self::PASSWORD_CHANGE => null,
    ];
    private const BILLING = ['id', 'plan_id', 'recharged_on', 'recharged_time', 'expiration', 'time', 'status', 'routers', 'type'];

    /** @var array{id:int,names:list<string>}|null the private per-call device permit (set and cleared only inside run) */
    private static ?array $permit = null;

    /**
     * @param array<string,mixed> $requested the requested values of the approved fields (IDENTITY_EDIT / PASSWORD_CHANGE)
     * @return array{status:string,routers:list<string>,reference:?string}
     */
    public static function run(ActorContext $a, int $customerId, string $op, array $requested = []): array
    {
        if (!array_key_exists($op, self::OPS)) {
            throw new \LogicException('unknown re-provision operation');
        }
        return TreeSerialization::hold(static function (\PDO $pdo) use ($a, $customerId, $op, $requested): array {
            $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [$customerId]) ?? throw new DomainError('NOT_FOUND');
            self::authorize($a, $customer, $op);
            if (self::inFlight($pdo, $customerId)) {
                throw new DomainError('ACTIVATION_IN_PROGRESS');
            }
            $billing = self::billing($pdo, $customerId);
            $old = array_intersect_key($customer, array_flip(self::IDENTITY_FIELDS));
            $new = $old;
            if ($op === self::PASSWORD_CHANGE || $op === self::IDENTITY_EDIT) {
                $new = self::validate($pdo, $customer, $op, $requested);
                if ($new !== $old) {
                    Tx::run(static function (\PDO $pdo) use ($customerId, $old, $new): void {
                        self::write($pdo, $customerId, $old, $new);
                    });
                }
            }
            if ($billing === []) {
                self::audit($a, $customer, $op, ['outcome' => 'NO_ACTIVE_PLAN', 'changed' => array_keys(array_diff_assoc($new, $old))]);
                return ['status' => 'NO_ACTIVE_PLAN', 'routers' => [], 'reference' => null];
            }
            $fresh = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [$customerId]);
            [$outcome, $routers, $verified] = self::devices($fresh, $old, $new, $op, $billing);
            $billingSame = self::billing($pdo, $customerId) === $billing;
            $changed = array_keys(array_diff_assoc($new, $old));
            if ($outcome === ProvisionResult::SUCCESS && $verified !== false && $billingSame) {
                self::audit($a, $customer, $op, ['outcome' => 'DONE', 'changed' => $changed, 'verified' => $verified]);
                return ['status' => 'DONE', 'routers' => $routers, 'reference' => null];
            }
            if ($outcome === ProvisionResult::FAILED && $billingSame) {
                $reverted = $new === $old || Tx::run(static fn(\PDO $pdo): bool => self::write($pdo, $customerId, $new, $old, false));
                if ($reverted) {
                    self::audit($a, $customer, $op, ['outcome' => 'FAILED_REVERTED', 'changed' => $changed]);
                    throw new DomainError('REPROVISION_FAILED');
                }
            }
            $ref = substr(ErrorReporter::requestId(), 0, 12);
            ErrorReporter::log('reprovision.unconfirmed', 'op=' . $op . ' customer=' . $customerId . ' outcome=' . $outcome
                . ' verified=' . var_export($verified, true) . ' billing_unchanged=' . var_export($billingSame, true));
            self::audit($a, $customer, $op, ['outcome' => self::UNCONFIRMED, 'changed' => $changed, 'device' => $outcome,
                'verified' => $verified, 'billing_unchanged' => $billingSame, 'old_name_may_remain' => in_array('username', $changed, true) || in_array('pppoe_username', $changed, true)]);
            throw new DomainError(self::UNCONFIRMED, DomainError::MESSAGES[self::UNCONFIRMED] . ' (ref ' . $ref . ')');
        });
    }

    /**
     * Device default deny (§4.3), called first by every legacy device grant method: a reseller-owned customer
     * (or one whose ownership cannot be evaluated) is refused unless the call is made inside run() for that
     * same customer. Refusals are recorded (ServiceGate) and raised as a confirmed, non-retryable failure.
     */
    public static function assertDeviceAllowed(mixed $customer = null, ?string ...$usernames): void
    {
        $row = is_object($customer) && method_exists($customer, 'as_array') ? $customer->as_array() : (is_array($customer) ? $customer : []);
        $id = (int) ($row['id'] ?? 0);
        $names = array_values(array_filter(array_merge([(string) ($row['username'] ?? '')], array_map('strval', $usernames)), static fn(string $n): bool => $n !== ''));
        if (self::$permit !== null && ($id === 0 || $id === self::$permit['id']) && array_diff($names, self::$permit['names']) === []) {
            return;
        }
        if (self::deviceOwned($id, $names)) {
            ServiceGate::refused('deviceGrant', $id > 0 ? $id : null, $names[0] ?? null);
            throw new ProvisionFailure(ProvisionResult::failed('RESELLER_OWNED_REFUSED', 'Device changes for reseller-managed customers go through the reseller activation or re-provision service.', false));
        }
    }

    /** True when the customer is reseller-owned (schema ready and manager_id set, read fresh); false otherwise. */
    public static function owned(mixed $customerId): bool
    {
        return SchemaReadiness::isReady() && (int) $customerId > 0 && ServiceGate::isBlockedCustomerId((int) $customerId);
    }

    /**
     * Web adapter for the legacy controllers: runs the operation for the current principal and ends the request
     * with the legacy redirect (success message, or the refusal / failure message). Never returns.
     */
    public static function web(string $op, mixed $customerId, array $requested, string $route, ?string $success = null): never
    {
        try {
            $r = self::run(ActorContext::current(), (int) $customerId, $op, $requested);
            $message = $r['status'] === 'NO_ACTIVE_PLAN' ? 'Cannot find active plan'
                : ($success ?? 'Sync success to ' . implode(', ', $r['routers']));
            r2(getUrl($route), $r['status'] === 'NO_ACTIVE_PLAN' && $op !== self::IDENTITY_EDIT && $op !== self::PASSWORD_CHANGE ? 'e' : 's', $message);
        } catch (DomainError $e) {
            r2(getUrl($route), 'e', $e->getMessage());
        }
        exit;
    }

    /**
     * Ownership as the device sees it: true when the customer (by id, or any of the usernames as username or PPPoE
     * username) has manager_id set. A legacy schema without the ownership column has no reseller-owned customer
     * (false); any other error fails closed (true). Independent of SchemaReadiness on purpose: the device must
     * keep serving unassigned legacy customers on an unmigrated installation.
     *
     * @param list<string> $names
     */
    private static function deviceOwned(int $id, array $names): bool
    {
        try {
            $pdo = \ORM::get_db();
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            if ($id > 0 && Tx::row($pdo, 'SELECT id FROM tbl_customers WHERE id = ? AND manager_id IS NOT NULL', [$id]) !== null) {
                return true;
            }
            foreach ($names as $n) {
                if (Tx::row($pdo, 'SELECT id FROM tbl_customers WHERE (username = ? OR pppoe_username = ?) AND manager_id IS NOT NULL LIMIT 1', [$n, $n]) !== null) {
                    return true;
                }
            }
            return false;
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1054) {
                return false; // no ownership column: no reseller-owned customer exists
            }
            ErrorReporter::report($e, 'device-default-deny');
            return true;
        }
    }

    // ------------------------------------------------------------------ steps

    private static function authorize(ActorContext $a, array $customer, string $op): void
    {
        if ($customer['manager_id'] === null) {
            throw new DomainError('CUSTOMER_NOT_RESELLER_OWNED');
        }
        $capability = self::OPS[$op];
        $ok = $capability !== null
            ? $a->isStaff() && PermissionService::scopeOf($a, $capability) === 'G'
            : $a->isCustomer() && (int) $a->customerId === (int) $customer['id'] && $customer['status'] === 'Active';
        if (!$ok) {
            throw new DomainError('FORBIDDEN');
        }
    }

    private static function inFlight(\PDO $pdo, int $customerId): bool
    {
        // the activation in-flight guard (REVERSAL_PENDING included: it is not TERMINAL)
        return ActivationStore::inFlight($pdo, $customerId) !== null;
    }

    /** @return list<array<string,?string>> the customer's active recharge rows (billing columns only) */
    private static function billing(\PDO $pdo, int $customerId): array
    {
        return array_map(
            static fn(array $r): array => array_map(static fn($v): ?string => $v === null ? null : (string) $v, array_intersect_key($r, array_flip(self::BILLING))),
            Tx::rows($pdo, "SELECT * FROM tbl_user_recharges WHERE customer_id = ? AND status = 'on' ORDER BY id", [$customerId])
        );
    }

    /**
     * The new values of the approved fields, validated as the legacy controllers do; nothing else.
     *
     * @return array<string,mixed>
     */
    private static function validate(\PDO $pdo, array $customer, string $op, array $requested): array
    {
        $new = array_intersect_key($customer, array_flip(self::IDENTITY_FIELDS));
        if (array_diff(array_keys($requested), self::IDENTITY_FIELDS) !== []) {
            throw new DomainError('INVALID_INPUT');
        }
        foreach ($requested as $k => $v) {
            if (!is_string($v)) {
                throw new DomainError('INVALID_INPUT');
            }
        }
        if ($op === self::PASSWORD_CHANGE) {
            $password = $requested['password'] ?? '';
            if (array_keys($requested) !== ['password'] || strlen($password) < 2 || strlen($password) > 35) {
                throw new DomainError('INVALID_INPUT', 'New Password must be 2 to 35 character');
            }
            // legacy: the portal changes the login password only
            $new['password'] = $password;
            return $new;
        }
        $username = (string) ($requested['username'] ?? $customer['username']);
        if ($username !== \Text::alphanumeric($username, ':+_.@-') || strlen($username) < 2 || strlen($username) > 54) {
            throw new DomainError('INVALID_INPUT', 'Username should be between 3 to 54 characters');
        }
        if ($username !== $customer['username']
            && Tx::row($pdo, 'SELECT id FROM tbl_customers WHERE (username = ? OR pppoe_username = ?) AND id <> ? LIMIT 1', [$username, $username, (int) $customer['id']]) !== null) {
            throw new DomainError('USERNAME_TAKEN', 'Username already used by another customer');
        }
        $new['username'] = $username;
        foreach (['pppoe_username', 'pppoe_password', 'pppoe_ip'] as $k) {
            if (array_key_exists($k, $requested)) {
                $new[$k] = trim($requested[$k]);
            }
        }
        if (($requested['password'] ?? '') !== '') {
            $new['password'] = trim($requested['password']);
        }
        return $new;
    }

    /**
     * Conditional write of the approved fields: only while the row still holds $from (a concurrent edit is never
     * overwritten). The recharge rows follow a username change (legacy behaviour; billing columns untouched).
     */
    private static function write(\PDO $pdo, int $customerId, array $from, array $to, bool $strict = true): bool
    {
        $row = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ? FOR UPDATE', [$customerId]);
        if ($row === null || array_intersect_key($row, $from) != $from) {
            if ($strict) {
                throw new DomainError('ACTIVATION_STATE_CONFLICT', 'The customer changed concurrently; nothing was changed.');
            }
            return false;
        }
        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($to)));
        Tx::exec($pdo, "UPDATE tbl_customers SET {$set} WHERE id = ?", [...array_values($to), $customerId]);
        if ($to['username'] !== $from['username']) {
            Tx::exec($pdo, 'UPDATE tbl_user_recharges SET username = ? WHERE customer_id = ?', [$to['username'], $customerId]);
        }
        return true;
    }

    /**
     * The legacy device calls, with the per-call permit, outside any transaction (the tree lock is held).
     *
     * @param list<array<string,?string>> $billing
     * @return array{0:string,1:list<string>,2:?bool} [SUCCESS|FAILED|UNKNOWN, routers, verified (null: no Radius-device plan)]
     */
    private static function devices(array $customer, array $old, array $new, string $op, array $billing): array
    {
        global $_app_stage;
        $names = array_values(array_unique(array_filter([(string) $old['username'], (string) $new['username'], (string) $old['pppoe_username'], (string) $new['pppoe_username']])));
        self::$permit = ['id' => (int) $customer['id'], 'names' => $names];
        $outcome = ProvisionResult::SUCCESS;
        $routers = [];
        $radius = [];
        try {
            foreach ($billing as $b) {
                $plan = \ORM::for_table('tbl_plans')->find_one((int) $b['plan_id']);
                if (!$plan) {
                    continue;
                }
                $routers[] = (string) $b['routers'];
                $dvc = \Package::getDevice($plan);
                if (($_app_stage ?? '') === 'demo' || !file_exists($dvc)) {
                    continue;
                }
                require_once $dvc;
                $device = new $plan['device']();
                $orm = \ORM::for_table('tbl_customers')->find_one((int) $customer['id']);
                try {
                    if ($op === self::IDENTITY_EDIT) {
                        if ($new['username'] !== $old['username']) {
                            $device->change_username($plan, $old['username'], $new['username']);
                        }
                        if ($new['pppoe_username'] !== $old['pppoe_username'] && $b['type'] === 'PPPOE') {
                            if (empty($old['pppoe_username']) && !empty($new['pppoe_username'])) {
                                $device->change_username($plan, $new['username'], $new['pppoe_username']);
                            } elseif (empty($new['pppoe_username']) && !empty($old['pppoe_username'])) {
                                $device->change_username($plan, $old['pppoe_username'], $new['username']);
                            } else {
                                $device->change_username($plan, $old['pppoe_username'], $new['pppoe_username']);
                            }
                        }
                        $device->add_customer($orm, $plan);
                    } elseif ($op === self::PASSWORD_CHANGE || !method_exists($device, 'sync_customer')) {
                        $device->add_customer($orm, $plan);
                    } else {
                        $device->sync_customer($orm, $plan);
                    }
                } catch (ProvisionFailure $e) {
                    $outcome = self::worse($outcome, $e->result->status);
                    ErrorReporter::report($e, 'reprovision-device');
                } catch (\Throwable $e) {
                    $outcome = self::worse($outcome, ProvisionResult::UNKNOWN);
                    ErrorReporter::report($e, 'reprovision-device');
                }
                if ($plan['device'] === 'Radius') {
                    $radius[] = [$plan->as_array(), $b];
                }
            }
        } finally {
            self::$permit = null;
        }
        $verified = null;
        if ($outcome === ProvisionResult::SUCCESS && $radius !== []) {
            $verified = true;
            $c = \ORM::for_table('tbl_customers')->find_one((int) $customer['id'])->as_array();
            foreach ($radius as [$p, $b]) {
                try {
                    $bw = \ORM::for_table('tbl_bandwidth')->find_one($p['id_bw']);
                    $zone = new \DateTimeZone(date_default_timezone_get());
                    $expiry = new \DateTimeImmutable($b['expiration'] . ' ' . ($b['time'] ?: '23:59:59'), $zone);
                    $start = new \DateTimeImmutable($b['recharged_on'] . ' ' . ($b['recharged_time'] ?: '00:00:00'), $zone);
                    $policy = RadiusPolicyBuilder::build($c, $p, $bw ? $bw->as_array() : null, $expiry, $start);
                    $verified = $verified && RadiusState::sameSubscriber(RadiusState::read($policy->username, (int) $p['id'], 'reprovision'),
                        RadiusState::target($policy, $bw ? $bw->as_array() : null, 'reprovision'));
                } catch (\Throwable $e) {
                    ErrorReporter::report($e, 'reprovision-verify');
                    $verified = false;
                }
            }
        }
        return [$outcome, array_values(array_unique($routers)), $verified];
    }

    private static function worse(string $a, string $b): string
    {
        $rank = [ProvisionResult::SUCCESS => 0, ProvisionResult::FAILED => 1, ProvisionResult::UNKNOWN => 2];
        return $rank[$b] > $rank[$a] ? $b : $a;
    }

    private static function audit(ActorContext $a, array $customer, string $op, array $after): void
    {
        try {
            AuditLog::record('reprovision.' . strtolower($op), $a, [
                'target_type' => 'customer', 'target_customer_id' => (int) $customer['id'],
                'target_user_id' => $customer['manager_id'] !== null ? (int) $customer['manager_id'] : null,
                'target_snapshot' => ['username' => $customer['username']], 'after' => $after,
            ]);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'reprovision-audit');
        }
    }
}
