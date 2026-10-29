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
     * WP8 A3 (R-WP8-CARRY-02): the old-identity captures the Radius device hands over inside run() (deferOldSessions)
     * and the Radius rename steps attempted; read and cleared by run().
     *
     * @var list<array<string,mixed>>|null
     */
    private static ?array $oldSessions = null;
    /** WP9 / W8-17: the captures of an Admin rename of an unassigned legacy customer (legacyRenameBegin / legacyRenameFinish) */
    private static ?array $legacyCaptures = null;
    /** @var list<array{0:string,1:string}> */
    private static array $renameAttempts = [];
    /** WP8 A3 (OD-A3R-6): total bound for the Accounting-Stop confirmation of the Disconnects, outside every lock */
    private const OLD_SESSION_STOP_BOUND = 6.0;
    /** WP8 A3 rename pre-check (renameCheck): OK, or the refusal and its operator message (nothing is changed) */
    public const RENAME_OK = 'OK';
    public const RENAME_REFUSALS = [
        'AMBIGUOUS_IDENTITY' => 'Another RADIUS identity is equivalent to the old or the new name; nothing was changed.',
        'IDENTITY_TAKEN' => 'The new name already holds a RADIUS policy that is not this customer\'s; nothing was changed.',
        'CHECK_UNAVAILABLE' => 'The identity check could not be completed (database unavailable); nothing was changed.',
    ];
    /**
     * WP8 A3 (owner D-3): the exact RADIUS keys the customer being renamed may replace (its own old keys, then each
     * key this run has renamed into); null outside a rename scope, where an existing target is never replaced.
     *
     * @var list<string>|null
     */
    private static ?array $renameOwn = null;
    /** @var array<int,true> radacctids already sent a Disconnect inside the current rename scope (legacy path) */
    private static array $scopeSent = [];
    /**
     * V1 (owner RENEWAL_OPTION_B_APPROVED): the sessions the device re-provision of this rename operation already ended AND
     * confirmed (Disconnect-ACK + its Accounting-Stop, or NAK 503), by exact radacctid with the NAS and Acct-Session-Id they
     * were ended on. A3's own Disconnect for such a captured row is not sent again (a vanished session answers NAK 406 and
     * would turn a confirmed end into UNCONFIRMED). Opened with the scope, kept past its close (the confirmation runs after
     * it), cleared by the next scope and after the confirmation.
     *
     * @var array<int,array{nas:string,sid:string}>
     */
    private static array $scopeEnded = [];

    /**
     * WP8 A3: `old_session` is reported separately from the re-provision status and never changes it:
     * NOT_APPLICABLE (no RADIUS key renamed), CONFIRMED (every targeted session: Disconnect-ACK + its Accounting-Stop
     * within the bound, or Disconnect-NAK 503), UNCONFIRMED (anything else), NO_ACCOUNTING_EVIDENCE (no open accounting
     * row of the old identity: NOT proof that it is offline). The Disconnects are sent after the tree lock is released.
     *
     * @param array<string,mixed> $requested the requested values of the approved fields (IDENTITY_EDIT / PASSWORD_CHANGE)
     * @return array{status:string,routers:list<string>,reference:?string,old_session:string}
     */
    public static function run(ActorContext $a, int $customerId, string $op, array $requested = []): array
    {
        if (!array_key_exists($op, self::OPS)) {
            throw new \LogicException('unknown re-provision operation');
        }
        // WP9-B / R-4: a re-provision of the customer's service never interleaves with the cron expiry or an
        // activation of the same customer (per-customer service lock, outermost; busy = SERVICE_BUSY, no change)
        if (!ServiceLock::acquire($customerId)) {
            throw new DomainError('SERVICE_BUSY');
        }
        try { // WP9-B / R-4: released in the finally block at the end of run()
        $handover = null;
        $error = null;
        $result = null;
        try {
            $result = TreeSerialization::hold(static function (\PDO $pdo) use ($a, $customerId, $op, $requested, &$handover): array {
            $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [$customerId]) ?? throw new DomainError('NOT_FOUND');
            self::authorize($a, $customer, $op);
            // lab F-6 Q3 (expert review 2026-10-26): a customer that is not Active is never re-provisioned by a sync
            if (($op === self::ADMIN_SYNC || $op === self::ADMIN_BULK_SYNC_ROW) && (string) $customer['status'] !== 'Active') {
                throw new DomainError('CUSTOMER_INACTIVE');
            }
            if (self::inFlight($pdo, $customerId)) {
                throw new DomainError('ACTIVATION_IN_PROGRESS');
            }
            $billing = self::billing($pdo, $customerId);
            $old = array_intersect_key($customer, array_flip(self::IDENTITY_FIELDS));
            $new = $old;
            if ($op === self::PASSWORD_CHANGE || $op === self::IDENTITY_EDIT) {
                $new = self::validate($pdo, $customer, $op, $requested);
                // WP8 A3 (OD-A3R-9, owner D-3): a RADIUS rename that meets another identity (equivalent key, a policy
                // that is not this customer's, or an unreadable RADIUS database) is refused BEFORE any write
                if ($op === self::IDENTITY_EDIT && ($check = self::renameCheck($billing, $old, $new)) !== self::RENAME_OK) {
                    self::audit($a, $customer, $op, ['outcome' => 'REFUSED_' . $check, 'changed' => array_keys(array_diff_assoc($new, $old))]);
                    throw new DomainError('RENAME_' . $check, self::RENAME_REFUSALS[$check]);
                }
                if ($new !== $old) {
                    $write = static function () use ($customerId, $old, $new): void {
                        Tx::run(static function (\PDO $pdo) use ($customerId, $old, $new): void {
                            self::write($pdo, $customerId, $old, $new);
                        });
                    };
                    // WP9 U0 (P5, AM-W9-1): an acquired name is checked and written under the identity lock (L-1 -> L-3 ->
                    // BEGIN); the RADIUS reads above and the device steps below stay outside it
                    $acquired = CustomerIdentity::acquired($old, $new);
                    // R-HF-CARRY-01 F-2: a changed static IP is checked (no other customer holds it) under the identity lock too.
                    // Round 3 (review N-3): compared trimmed on both sides, so a kept stored value with edge whitespace (legacy
                    // data; e.g. on a portal PASSWORD_CHANGE) is not re-judged
                    $ip = trim((string) $new['pppoe_ip']) !== trim((string) $old['pppoe_ip']) ? trim((string) $new['pppoe_ip']) : '';
                    if ($acquired === [] && $ip === '') {
                        $write();
                    } else {
                        CustomerIdentity::hold(static function (\PDO $pdo) use ($acquired, $customerId, $old, $write, $ip): void {
                            if ($acquired !== []) {
                                CustomerIdentity::assertAvailable($pdo, $acquired, $customerId, CustomerIdentity::keysOf((string) $old['username'], (string) $old['pppoe_username']));
                            }
                            CustomerIdentity::assertStaticIpAvailable($pdo, $ip, $customerId);
                            $write();
                        });
                    }
                }
            }
            if ($billing === []) {
                self::audit($a, $customer, $op, ['outcome' => 'NO_ACTIVE_PLAN', 'changed' => array_keys(array_diff_assoc($new, $old))]);
                return ['status' => 'NO_ACTIVE_PLAN', 'routers' => [], 'reference' => null];
            }
            $fresh = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [$customerId]);
            // test seam (no-op unless a test hook is set): between the pre-check / identity write and the device steps
            \Reseller\Activation\ActivationFaults::hit('reprovision.before_devices');
            self::$oldSessions = [];
            self::$renameAttempts = [];
            [$outcome, $routers, $verified] = self::devices($fresh, $old, $new, $op, $billing);
            // WP8 A3: the old-identity captures leave the lock with the outcome; the main audit announces them (PENDING)
            $handover = ['customer' => $customer, 'captures' => self::$oldSessions ?? [], 'attempts' => self::$renameAttempts, 'outcome' => 'ERROR'];
            $handover['pending'] = $handover['captures'] !== [] || $handover['attempts'] !== [];
            self::$oldSessions = null;
            self::$renameAttempts = [];
            $mark = $handover['pending'] ? 'PENDING' : 'NOT_APPLICABLE';
            $billingSame = self::billing($pdo, $customerId) === $billing;
            $changed = array_keys(array_diff_assoc($new, $old));
            if ($outcome === ProvisionResult::SUCCESS && $verified !== false && $billingSame) {
                $handover['outcome'] = 'DONE';
                self::audit($a, $customer, $op, ['outcome' => 'DONE', 'changed' => $changed, 'verified' => $verified, 'old_session' => $mark]);
                return ['status' => 'DONE', 'routers' => $routers, 'reference' => null];
            }
            if ($outcome === ProvisionResult::FAILED && $billingSame) {
                $reverted = $new === $old || self::revert($customerId, $old, $new);
                if ($reverted) {
                    // nothing was applied on any device and the identity is back: the old identity is current again
                    $handover['pending'] = false;
                    $handover['outcome'] = 'FAILED_REVERTED';
                    self::audit($a, $customer, $op, ['outcome' => 'FAILED_REVERTED', 'changed' => $changed, 'old_session' => 'NOT_APPLICABLE']);
                    throw new DomainError('REPROVISION_FAILED');
                }
            }
            $handover['outcome'] = self::UNCONFIRMED;
            $ref = substr(ErrorReporter::requestId(), 0, 12);
            ErrorReporter::log('reprovision.unconfirmed', 'op=' . $op . ' customer=' . $customerId . ' outcome=' . $outcome
                . ' verified=' . var_export($verified, true) . ' billing_unchanged=' . var_export($billingSame, true));
            self::audit($a, $customer, $op, ['outcome' => self::UNCONFIRMED, 'changed' => $changed, 'device' => $outcome,
                'verified' => $verified, 'billing_unchanged' => $billingSame, 'old_name_may_remain' => in_array('username', $changed, true) || in_array('pppoe_username', $changed, true),
                'old_session' => $mark]);
            throw new DomainError(self::UNCONFIRMED, DomainError::MESSAGES[self::UNCONFIRMED] . ' (ref ' . $ref . ')');
            });
        } catch (\Throwable $e) {
            $error = $e;
        } finally {
            self::$oldSessions = null;
            self::$renameAttempts = [];
        }
        $oldSession = 'NOT_APPLICABLE';
        if ($handover !== null && $handover['pending']) {
            // after the tree lock is released, outside any transaction (also when the re-provision ended UNCONFIRMED)
            $oldSession = self::confirmOldSessions($a, $handover['customer'], $op, $handover['captures'], $handover['attempts'], $handover['outcome']);
        }
        if ($error !== null) {
            throw $error;
        }
        return $result + ['old_session' => $oldSession];
        } finally {
            ServiceLock::release($customerId); // WP9-B / R-4
        }
    }

    public static function assertDeviceAllowed(mixed $customer = null, ?string ...$usernames): void
    {
        $row = is_object($customer) && method_exists($customer, 'as_array') ? $customer->as_array() : (is_array($customer) ? $customer : []);
        $id = (int) ($row['id'] ?? 0);
        $names = array_values(array_filter(array_merge([(string) ($row['username'] ?? ''), (string) ($row['pppoe_username'] ?? '')], array_map('strval', $usernames)), static fn(string $n): bool => $n !== ''));
        if (self::$permit !== null && ($id === 0 || $id === self::$permit['id']) && array_diff($names, self::$permit['names']) === []) {
            self::singleOwner($row, $id);
            return;
        }
        if (self::deviceOwned($id, $names)) {
            ServiceGate::refused('deviceGrant', $id > 0 ? $id : null, $names[0] ?? null);
            throw new ProvisionFailure(ProvisionResult::failed('RESELLER_OWNED_REFUSED', 'Device changes for reseller-managed customers go through the reseller activation or re-provision service.', false));
        }
        // WP9 U0 R-FC-4: a voucher-only row (no customer id; username = the code) is served only when the voucher state
        // machine granted it (or no voucher row remains) - never a voucher under review / in flight elsewhere, never an
        // unredeemed one. Shared by every driver's grant methods; nothing is written, the code is not logged
        if ($id === 0 && $row !== [] && ($why = VoucherRedemptionService::voucherOnlyServiceRefusal((string) ($row['username'] ?? ''))) !== null) {
            ErrorReporter::log('device.voucher_refused', 'reason=' . $why);
            throw new ProvisionFailure(ProvisionResult::failed('VOUCHER_NOT_SERVABLE', 'The voucher is not granted (under review or not redeemed); nothing was applied.', false));
        }
        self::singleOwner($row, $id);
    }

    /**
     * WP9 U0 final hardening (D-U0-R1): a grant for a row whose username / PPPoE username has MORE THAN ONE logical owner
     * (grandfathered duplicate) is refused before anything is written - inside run() too, and for the pre-write check of
     * Package::rechargeUser (WP8 S7-FC). The WP8 reseller-owned refusal keeps precedence (its reason and refusal record
     * are unchanged); the rename names are judged by renameCheck().
     */
    private static function singleOwner(array $row, int $id): void
    {
        if ($row === []) {
            return;
        }
        try {
            CustomerIdentity::assertSingleOwner((string) ($row['username'] ?? ''), (string) ($row['pppoe_username'] ?? ''));
        } catch (DomainError $e) {
            ErrorReporter::log('device.identity_refused', 'reason=' . $e->reason . ' customer=' . $id);
            throw new ProvisionFailure(ProvisionResult::failed($e->reason === 'IDENTITY_AMBIGUOUS' ? 'RADIUS_IDENTITY_AMBIGUOUS' : 'RADIUS_IDENTITY_CHECK_UNAVAILABLE', $e->getMessage(), false));
        }
    }

    /**
     * WP8 A3: the RADIUS identity renames an identity edit implies for one plan type, in order: the username, then
     * (PPPoE plans) the PPPoE key: set -> the new username becomes the old key, cleared -> back to the username.
     *
     * @param array<string,mixed> $old the old identity fields (username, pppoe_username)
     * @param array<string,mixed> $new the new identity fields
     * @return list<array{0:string,1:string}> [from, to]
     */
    public static function renamePairs(array $old, array $new, string $planType): array
    {
        $pairs = [];
        if ((string) $new['username'] !== (string) $old['username']) {
            $pairs[] = [(string) $old['username'], (string) $new['username']];
        }
        if ((string) $new['pppoe_username'] !== (string) $old['pppoe_username'] && $planType === 'PPPOE') {
            if (empty($old['pppoe_username']) && !empty($new['pppoe_username'])) {
                $pairs[] = [(string) $new['username'], (string) $new['pppoe_username']];
            } elseif (empty($new['pppoe_username']) && !empty($old['pppoe_username'])) {
                $pairs[] = [(string) $old['pppoe_username'], (string) $new['username']];
            } else {
                $pairs[] = [(string) $old['pppoe_username'], (string) $new['pppoe_username']];
            }
        }
        // WP8 A3 (FIX-3): a pair that does not change the key (e.g. a PPPoE username equal to the username, set or
        // cleared) is not a rename; the plan is still re-provisioned by the run
        return array_values(array_filter($pairs, static fn(array $p): bool => $p[0] !== $p[1]));
    }

    /**
     * WP8 A3 (OD-A3R-9, owner D-1 / D-3): the pre-check of the RADIUS renames an identity edit implies on its
     * Radius-device plans, BEFORE any write, in run order (each old key once):
     *  - AMBIGUOUS_IDENTITY: another exact key that the collation treats as equal to a source or a target (the
     *    sources and targets of the EARLIER pairs of this edit count as the customer's own: chained pairs are not
     *    ambiguous, and the pre-check sees exactly what the write transaction of each pair will see);
     *  - IDENTITY_TAKEN: the exact target already holds RADIUS rows and is neither an old key of this customer nor a
     *    key this edit renames into first (another subscriber's policy, or an orphan one, is never replaced);
     *  - CHECK_UNAVAILABLE: the RADIUS database could not be read (fail closed, reported separately).
     * The rename re-checks both conditions inside its own write transaction (RadiusRepository::renameUser).
     *
     * @param list<array<string,?string>> $billing
     */
    public static function renameCheck(array $billing, array $old, array $new): string
    {
        try {
            // every pair in run order, also one whose old key the run renames only once: its target is still
            // written by the plan's re-provisioning
            $pairs = [];
            foreach ($billing as $b) {
                $plan = \ORM::for_table('tbl_plans')->find_one((int) $b['plan_id']);
                if (!$plan || $plan['device'] !== 'Radius') {
                    continue;
                }
                foreach (self::renamePairs($old, $new, (string) $b['type']) as $pair) {
                    $pairs[] = $pair;
                }
            }
            if ($pairs === []) {
                return self::RENAME_OK;
            }
            $repo = \Reseller\Radius\RadiusRepository::connection();
            $own = self::ownKeys($old);
            $earlier = [];   // the sources and targets of the earlier pairs of this edit (as the write will see them)
            foreach ($pairs as [$from, $to]) {
                // WP9 U0 final hardening (D-U0-R1): a source key that another identity also holds (grandfathered duplicate,
                // even byte-identical) is never moved: its rows may be the other owner's policy
                try {
                    CustomerIdentity::assertSingleOwner($from);
                } catch (DomainError $e) {
                    return $e->reason === 'IDENTITY_AMBIGUOUS' ? 'AMBIGUOUS_IDENTITY' : 'CHECK_UNAVAILABLE';
                }
                if ($repo->equivalentKeys($from, $to, false, $earlier) !== []) {
                    return 'AMBIGUOUS_IDENTITY';
                }
                if (!in_array($to, $own, true) && $repo->exactRows($to) > 0) {
                    return 'IDENTITY_TAKEN';
                }
                $own[] = $to;
                $earlier[] = $from;
                $earlier[] = $to;
            }
            return self::RENAME_OK;
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'rename-check');
            return 'CHECK_UNAVAILABLE';
        }
    }

    /** WP8 A3 (owner D-3): true when another customer holds $name as its username or PPPoE username (collation). */
    public static function nameTakenByOther(string $name, int $customerId, ?\PDO $pdo = null): bool
    {
        $pdo ??= \ORM::get_db();
        return Tx::row($pdo, 'SELECT id FROM tbl_customers WHERE (username = ? OR pppoe_username = ?) AND id <> ? LIMIT 1', [$name, $name, $customerId]) !== null;
    }

    /**
     * WP8 A3 (owner D-3): opens (old identity) or closes (null) the rename scope of one customer. Inside it the Radius
     * device may replace an existing exact target only when it is an old key of that customer or a key this scope has
     * already renamed into (targetReplaceable); outside it never.
     *
     * @param array<string,mixed>|null $oldIdentity
     */
    public static function renameScope(?array $oldIdentity): void
    {
        self::$renameOwn = $oldIdentity === null ? null : self::ownKeys($oldIdentity);
        self::$scopeSent = [];
        if ($oldIdentity !== null) {
            self::$scopeEnded = [];   // closing the scope keeps the evidence for the confirmation that follows
        }
    }

    /**
     * V1: the Radius device reports a session it ended inside the open rename scope (SessionTermination result). Only a
     * CONFIRMED end is evidence; anything else (ACK without Stop, NAK 406, no answer, unknown) is ignored here and the
     * session stays A3's to disconnect and judge. Ignored when no rename scope is open.
     *
     * @param array<string,mixed> $ended radacctid, acct_session_id, nas, confirmed
     */
    public static function recordEnded(array $ended): void
    {
        $rid = (int) ($ended['radacctid'] ?? 0);
        if (self::$renameOwn === null || $rid <= 0 || ($ended['confirmed'] ?? false) !== true) {
            return;
        }
        self::$scopeEnded[$rid] = ['nas' => (string) ($ended['nas'] ?? ''), 'sid' => (string) ($ended['acct_session_id'] ?? '')];
    }

    /** V1: true only for the exact accounting row (radacctid + NAS + Acct-Session-Id) that was ended and confirmed in this operation. */
    public static function endedInScope(array $row): bool
    {
        $e = self::$scopeEnded[(int) ($row['radacctid'] ?? 0)] ?? null;
        return $e !== null && $e['nas'] !== '' && $e['nas'] === (string) ($row['nasipaddress'] ?? '') && $e['sid'] === (string) ($row['acctsessionid'] ?? '');
    }

    public static function targetReplaceable(string $to): bool
    {
        return self::$renameOwn !== null && in_array($to, self::$renameOwn, true);
    }

    public static function renameApplied(string $to): void
    {
        if (self::$renameOwn !== null) {
            self::$renameOwn[] = $to;
        }
    }

    /** @return list<string> the non-empty exact keys of an identity (username, PPPoE username) */
    private static function ownKeys(array $identity): array
    {
        return array_values(array_filter([(string) ($identity['username'] ?? ''), (string) ($identity['pppoe_username'] ?? '')], static fn(string $k): bool => $k !== ''));
    }

    /**
     * WP8 A3: called by the Radius device after a rename. Inside run() (the per-call permit is set) the capture is kept
     * for the confirmation after the tree lock; inside the legacy rename of the Admin edit form (W8-17) it is kept for
     * legacyRenameFinish(); returns false elsewhere (other legacy callers: the device sends at once).
     *
     * @param array<string,mixed> $capture
     */
    public static function deferOldSessions(array $capture): bool
    {
        if (self::$permit === null && self::$legacyCaptures !== null) {
            self::$legacyCaptures[] = $capture;
            return true;
        }
        if (self::$permit === null || self::$oldSessions === null) {
            return false;
        }
        self::$oldSessions[] = $capture;
        return true;
    }

    /** WP9 / W8-17: the Admin edit form starts the rename of an unassigned legacy customer (the device calls follow). */
    public static function legacyRenameBegin(): void
    {
        self::$legacyCaptures = [];
    }

    /**
     * WP9 / W8-17: after the device calls of that rename (no transaction, no lock held): the same Disconnect / Accounting-
     * Stop confirmation and 'rename.old_session' audit as the reseller path (operation LEGACY_RENAME). Returns the
     * old_session state for the operator notice, or null when nothing was renamed on a Radius device.
     *
     * @param array<string,mixed>|\ArrayAccess $customer
     */
    public static function legacyRenameFinish(ActorContext $a, array|\ArrayAccess $customer): ?string
    {
        $captures = self::$legacyCaptures;
        self::$legacyCaptures = null;
        if ($captures === null || $captures === []) {
            return null;
        }
        $row = ['id' => (int) $customer['id'], 'manager_id' => $customer['manager_id'] ?? null, 'username' => (string) $customer['username']];
        return self::confirmOldSessions($a, $row, 'LEGACY_RENAME', $captures, [], 'LEGACY');
    }

    /**
     * WP8 A3: one Disconnect-Request per captured accounting row, to that row's NAS (CoAClient: 1 + 2 identical sends,
     * never a new request). Never throws; a non-success is logged without secrets.
     *
     * @param array<string,mixed> $capture
     * @return list<array{row:array<string,mixed>,result:ProvisionResult}>
     */
    public static function disconnectCaptured(array $capture): array
    {
        if (($capture['state'] ?? '') !== 'TARGETED' || ($capture['targets'] ?? []) === []) {
            // WP8 A3 (FIX-6): nothing can be targeted; never silent (the state only: no name, no secret)
            ErrorReporter::log('radius.old_session', 'capture=' . (($capture['state'] ?? '') === 'TARGETED' ? 'NO_ACCOUNTING_EVIDENCE' : (string) ($capture['state'] ?? 'UNKNOWN')));
            return [];
        }
        $out = [];
        $coa = null;
        try {
            $coa = \Reseller\Radius\CoAClient::connection();
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'rename-old-session');
        }
        foreach ($capture['targets'] as $row) {
            // WP8 A3 (FIX-4): inside one rename scope (legacy path: sent at once) a session is sent one Disconnect
            $rid = (int) ($row['radacctid'] ?? 0);
            if (self::endedInScope($row)) {
                // V1: ended and confirmed by this operation's device re-provision (exact row, NAS and Acct-Session-Id): no
                // second Disconnect; the Accounting-Stop is still read from accounting by the confirmation
                $out[] = ['row' => $row, 'result' => ProvisionResult::success('COA_ACK', 'Ended and confirmed by the device re-provision of this operation (Disconnect-ACK and Accounting-Stop).')];
                continue;
            }
            if (self::$renameOwn !== null) {
                if (isset(self::$scopeSent[$rid])) {
                    continue;
                }
                self::$scopeSent[$rid] = true;
            }
            try {
                $r = $coa !== null ? $coa->disconnectSession($row)
                    : ProvisionResult::unknown('COA_EXCEPTION', 'The Disconnect could not be sent; the session state is unknown.');
            } catch (\Throwable $e) {
                ErrorReporter::report($e, 'rename-old-session');
                $r = ProvisionResult::unknown('COA_EXCEPTION', 'The Disconnect could not be sent; the session state is unknown.');
            }
            if (!$r->isSuccess()) {
                ErrorReporter::log('radius.old_session', 'status=' . $r->status . ' code=' . $r->code);
            }
            $out[] = ['row' => $row, 'result' => $r];
        }
        return $out;
    }

    /**
     * WP8 A3: the state of one targeted session. CONFIRMED only on NAS-side evidence of its end: Disconnect-ACK with its
     * Accounting-Stop seen, or Disconnect-NAK 503 (no such session); otherwise the reason (an ACK alone is not proof).
     */
    public static function sessionState(string $status, string $code, bool $stopSeen): string
    {
        if ($status === ProvisionResult::SUCCESS && $code === 'SESSION_NOT_FOUND') {
            return 'CONFIRMED';
        }
        if ($status === ProvisionResult::SUCCESS && $code === 'COA_ACK') {
            return $stopSeen ? 'CONFIRMED' : 'ack_without_stop';
        }
        return $code;
    }

    /**
     * WP8 A3: the state of one renamed identity from its capture state and its session states.
     *
     * @param list<string> $sessionStates
     * @return array{0:string,1:?string} [state, reason]
     */
    public static function identityState(string $capture, array $sessionStates): array
    {
        $reasons = ['AMBIGUOUS' => 'ambiguous_identity', 'CAPTURE_FAILED' => 'capture_failed', 'RENAME_NOT_CONFIRMED' => 'rename_not_confirmed'];
        if (isset($reasons[$capture])) {
            return ['UNCONFIRMED', $reasons[$capture]];
        }
        if ($sessionStates === []) {
            return ['NO_ACCOUNTING_EVIDENCE', null];
        }
        foreach ($sessionStates as $s) {
            if ($s !== 'CONFIRMED') {
                return ['UNCONFIRMED', $s];
            }
        }
        return ['CONFIRMED', null];
    }

    /** @param list<string> $states WP8 A3: the run value is the worst identity state */
    public static function worstOldSession(array $states): string
    {
        $rank = ['NOT_APPLICABLE' => 0, 'CONFIRMED' => 1, 'NO_ACCOUNTING_EVIDENCE' => 2, 'UNCONFIRMED' => 3];
        $worst = 'NOT_APPLICABLE';
        foreach ($states as $s) {
            if (($rank[$s] ?? 3) > $rank[$worst]) {
                $worst = isset($rank[$s]) ? $s : 'UNCONFIRMED';
            }
        }
        return $worst;
    }

    /** WP8 A3 (FIX-5, plan §4.4): UNCONFIRMED and NO_ACCOUNTING_EVIDENCE are shown as warnings, never as success. */
    public static function oldSessionNoticeType(?string $state): string
    {
        return in_array($state, ['UNCONFIRMED', 'NO_ACCOUNTING_EVIDENCE'], true) ? 'w' : 's';
    }

    /** WP8 A3: the operator sentence for an old_session value ('' when nothing to say). */
    public static function oldSessionNote(?string $state): string
    {
        return match ($state) {
            'CONFIRMED' => ' The sessions of the old name were ended.',
            'NO_ACCOUNTING_EVIDENCE' => ' No session of the old name is recorded; this does not prove that it is offline.',
            'UNCONFIRMED' => ' The end of the old name\'s sessions could not be confirmed (see the drift report).',
            default => '',
        };
    }

    /**
     * WP8 A3: after the tree lock is released and outside any transaction: the Disconnects of the captured rows, the
     * Accounting-Stop confirmation (OLD_SESSION_STOP_BOUND in total), the classification and the 'rename.old_session'
     * audit (its own event type: the REPROVISION_UNCONFIRMED drift reads 'reprovision.%').
     *
     * @param array<string,mixed> $customer
     * @param list<array<string,mixed>> $captures
     * @param list<array{0:string,1:string}> $attempts
     */
    private static function confirmOldSessions(ActorContext $a, array $customer, string $op, array $captures, array $attempts, string $reprovision): string
    {
        $identities = [];
        foreach ($captures as $c) {
            $identities[$c['from'] . "\0" . $c['to']] ??= $c;
        }
        foreach ($attempts as [$from, $to]) {
            $identities[$from . "\0" . $to] ??= ['from' => $from, 'to' => $to, 'equivalent' => null, 'state' => 'RENAME_NOT_CONFIRMED', 'targets' => []];
        }
        \Reseller\Activation\ActivationFaults::hit('reprovision.old_session_before_send');
        // WP8 A3 (FIX-4): one Disconnect per accounting row per run; a row captured again reuses the first result
        $sent = [];
        $byRow = [];
        foreach ($identities as $k => $c) {
            $todo = $c;
            $todo['targets'] = array_values(array_filter($c['targets'] ?? [], static fn(array $r): bool => !isset($byRow[(int) $r['radacctid']])));
            if ($todo['targets'] !== [] || ($c['targets'] ?? []) === []) {
                foreach (self::disconnectCaptured($todo) as $s) {
                    $byRow[(int) $s['row']['radacctid']] = $s;
                }
            }
            $sent[$k] = ($c['state'] ?? '') === 'TARGETED' ? array_map(static fn(array $r): array => $byRow[(int) $r['radacctid']], $c['targets'] ?? []) : [];
        }
        \Reseller\Activation\ActivationFaults::hit('reprovision.old_session_after_send');
        // Accounting-Stop of the ACKed sessions, polled by radacctid (never by name), bounded in total
        $pending = [];
        foreach ($sent as $k => $list) {
            foreach ($list as $s) {
                if ($s['result']->isSuccess() && $s['result']->code === 'COA_ACK') {
                    $pending[$k][(int) $s['row']['radacctid']] = true;
                }
            }
        }
        $deadline = microtime(true) + self::OLD_SESSION_STOP_BOUND;
        while ($pending !== []) {
            try {
                $acct = \Reseller\Radius\AccountingService::connection();
                foreach ($pending as $k => $ids) {
                    $open = array_map(static fn(array $r): int => (int) $r['radacctid'], $acct->openSessions((string) $identities[$k]['from']));
                    $pending[$k] = array_intersect_key($ids, array_flip($open));
                    if ($pending[$k] === []) {
                        unset($pending[$k]);
                    }
                }
            } catch (\Throwable $e) {
                ErrorReporter::report($e, 'rename-old-session');
                break;
            }
            if ($pending === [] || microtime(true) >= $deadline) {
                break;
            }
            usleep(250000);
        }
        $out = [];
        $states = [];
        foreach ($identities as $k => $c) {
            $sessions = [];
            foreach ($sent[$k] as $s) {
                $stop = !isset($pending[$k][(int) $s['row']['radacctid']]);
                $sessions[] = ['radacctid' => (int) $s['row']['radacctid'], 'acct_session_id' => (string) ($s['row']['acctsessionid'] ?? ''),
                    'nas' => (string) ($s['row']['nasipaddress'] ?? ''), 'nas_id' => $s['result']->nasId, 'result' => $s['result']->code,
                    'stop_seen' => $s['result']->code === 'COA_ACK' ? $stop : null, 'state' => self::sessionState($s['result']->status, $s['result']->code, $stop)];
            }
            [$state, $reason] = self::identityState((string) $c['state'], array_column($sessions, 'state'));
            $states[] = $state;
            $out[] = ['from' => (string) $c['from'], 'to' => (string) $c['to'], 'equivalent' => $c['equivalent'], 'state' => $state, 'reason' => $reason, 'sessions' => $sessions];
        }
        $overall = self::worstOldSession($states);
        self::$scopeEnded = [];   // the evidence belongs to this operation only
        try {
            AuditLog::record('rename.old_session', $a, [
                'target_type' => 'customer', 'target_customer_id' => (int) $customer['id'],
                'target_user_id' => $customer['manager_id'] !== null ? (int) $customer['manager_id'] : null,
                'target_snapshot' => ['username' => $customer['username']],
                'after' => ['old_session' => $overall, 'operation' => $op, 'reprovision_outcome' => $reprovision, 'identities' => $out],
            ]);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'rename-old-session-audit');
        }
        return $overall;
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
        // WP9 U0 (I-5): a changed name fits its column (username 45, PPPoE username 32), never truncated
        if ($username !== $customer['username'] && strlen($username) > 45) {
            throw new DomainError('INVALID_INPUT', 'Username should be at most 45 characters');
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
        // R-HF-CARRY-01 F-2: a changed static IP is '' or a valid IPv4 address (a kept stored value is not re-judged; round 3,
        // review N-3: compared trimmed on both sides)
        if (trim((string) $new['pppoe_ip']) !== trim((string) $customer['pppoe_ip']) && !CustomerIdentity::validStaticIp(trim((string) $new['pppoe_ip']))) {
            throw new DomainError('STATIC_IP_INVALID');
        }
        if ((string) $new['pppoe_username'] !== (string) $customer['pppoe_username']
            && (mb_strlen((string) $new['pppoe_username']) > 32 || preg_match('/[\x00-\x1F\x7F]/', (string) $new['pppoe_username']))) {
            throw new DomainError('INVALID_INPUT', 'PPPoE username should be at most 32 characters, without control characters');
        }
        // WP8 A3 (owner D-3): a PPPoE username is a RADIUS key too; it never takes another customer's name
        if ((string) $new['pppoe_username'] !== '' && (string) $new['pppoe_username'] !== (string) $customer['pppoe_username']
            && self::nameTakenByOther((string) $new['pppoe_username'], (int) $customer['id'], $pdo)) {
            throw new DomainError('PPPOE_USERNAME_TAKEN', 'PPPoE username already used by another customer');
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
     * WP9 U0 (P5r): the identity write-back after a CONFIRMED device failure. A name the revert would re-acquire is
     * checked under the identity lock first; when it was taken in the meantime (or the check cannot run) nothing is
     * written and the caller reports REPROVISION_UNCONFIRMED for an operator (no blind revert).
     */
    private static function revert(int $customerId, array $old, array $new): bool
    {
        $write = static fn(): bool => Tx::run(static fn(\PDO $pdo): bool => self::write($pdo, $customerId, $new, $old, false));
        $back = CustomerIdentity::acquired($new, $old);
        if ($back === []) {
            return $write();
        }
        try {
            return CustomerIdentity::hold(static function (\PDO $pdo) use ($back, $customerId, $old, $new, $write): bool {
                $taken = CustomerIdentity::conflicts($pdo, $back, $customerId, CustomerIdentity::keysOf((string) $old['username'], (string) $old['pppoe_username'],
                    (string) $new['username'], (string) $new['pppoe_username']));
                if ($taken !== []) {
                    ErrorReporter::log('reprovision.revert_refused', 'customer=' . $customerId . ' reason=' . ($taken === null ? 'CHECK_UNAVAILABLE' : 'IDENTITY_TAKEN'));
                    return false;
                }
                return $write();
            });
        } catch (DomainError $e) {
            ErrorReporter::log('reprovision.revert_refused', 'customer=' . $customerId . ' reason=' . $e->reason);
            return false;
        }
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
        self::renameScope($old);
        $outcome = ProvisionResult::SUCCESS;
        $routers = [];
        $radius = [];
        // WP5B fix round 1 (blocker 3): external progress is tracked per target and per service-affecting step. A
        // confirmed FAILED is "nothing applied" only while no earlier step succeeded; once any step has changed the
        // external state, a later failure is promoted to UNKNOWN (REPROVISION_UNCONFIRMED: no DB revert, no retry).
        // The first non-success stops every further step (no blind continuation).
        $applied = 0;
        $renamed = [];
        $step = static function (callable $call) use (&$applied, &$outcome): bool {
            try {
                $call();
                $applied++;
                return true;
            } catch (ProvisionFailure $e) {
                $outcome = $e->result->status === ProvisionResult::FAILED && $applied === 0 ? ProvisionResult::FAILED : ProvisionResult::UNKNOWN;
                ErrorReporter::report($e, 'reprovision-device');
            } catch (\Throwable $e) {
                $outcome = ProvisionResult::UNKNOWN;
                ErrorReporter::report($e, 'reprovision-device');
            }
            return false;
        };
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
                $steps = [];
                if ($op === self::IDENTITY_EDIT) {
                    foreach (self::renamePairs($old, $new, (string) $b['type']) as [$from, $to]) {
                        // WP8 A3 (F-4, FIX-4): the shared RADIUS database renames each old key once per run; a second
                        // plan on the same key is only re-provisioned (a repeated rename deleted the rows the first plan
                        // wrote; after the first rename the old exact key holds nothing, so a second target is a no-op)
                        if ($plan['device'] === 'Radius') {
                            if (isset($renamed[$from])) {
                                continue;
                            }
                            $renamed[$from] = true;
                            // WP8 A3: an attempted rename without a capture (failed / unknown) is an unconfirmed old session
                            $steps[] = static function () use ($device, $plan, $from, $to): void {
                                self::$renameAttempts[] = [$from, $to];
                                $device->change_username($plan, $from, $to);
                            };
                            continue;
                        }
                        $steps[] = static fn() => $device->change_username($plan, $from, $to);
                    }
                    $steps[] = static fn() => $device->add_customer(\ORM::for_table('tbl_customers')->find_one((int) $customer['id']), $plan);
                } elseif ($op === self::PASSWORD_CHANGE || !method_exists($device, 'sync_customer')) {
                    $steps[] = static fn() => $device->add_customer(\ORM::for_table('tbl_customers')->find_one((int) $customer['id']), $plan);
                } else {
                    $steps[] = static fn() => $device->sync_customer(\ORM::for_table('tbl_customers')->find_one((int) $customer['id']), $plan);
                }
                foreach ($steps as $call) {
                    if (!$step($call)) {
                        break 2;
                    }
                }
                if ($plan['device'] === 'Radius') {
                    $radius[] = [$plan->as_array(), $b];
                }
            }
        } finally {
            self::$permit = null;
            self::renameScope(null);
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
                    $policy = RadiusPolicyBuilder::build($c, $p, $bw ? $bw->as_array() : null, $expiry, $start, \Reseller\Radius\PeriodBoundary::forConnection());
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
