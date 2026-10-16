<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Security\ErrorReporter;

/**
 * The single voucher redemption path (WP5B §6; owner decisions OD-4 / OD-9): staff refill (E2), customer
 * portal (E7), public login (E12 / E13) and RADIUS authorize (E15). Reseller-owned targets are refused before
 * the voucher is touched (no reseller voucher commerce in V1).
 *
 * Claim semantics (no lock or transaction is held across the grant):
 *  1. atomic claim '0' -> 'CLAIMED' (exactly one request wins a code);
 *  2. the legacy grant Package::rechargeUser;
 *  3. finalize: '1' on success; back to '0' ONLY when rechargeUser returned exactly false (its pre-write
 *     refusals); anything else (exception, exit, unknown result) stays 'CLAIMED': never redeemable again,
 *     operator review (drift report).
 * Every redemption has a real source (RedemptionSource): STAFF / CUSTOMER are audited with their own
 * ActorContext; PUBLIC / RADIUS have no principal and are logged (code hash, target, outcome). No actor is
 * fabricated.
 */
final class VoucherRedemptionService
{
    public const CLAIMED = 'CLAIMED';
    public const GRANTED = 'GRANTED';
    public const NOT_VALID = 'NOT_VALID';
    public const NOT_GRANTED = 'NOT_GRANTED';
    public const REVIEW = 'CLAIMED_REVIEW';
    public const REFUSED = 'REFUSED';
    public const USERNAME_TAKEN = 'USERNAME_TAKEN';
    private const LOCK_TIMEOUT = 10;

    /**
     * Redeems $code for an existing customer (customerId > 0) or a voucher-only subscriber (customerId 0,
     * username = the code).
     *
     * @return array{status:string,voucher:?array<string,mixed>,result:mixed}
     */
    public static function redeem(string $code, RedemptionTarget $target, RedemptionSource $source): array
    {
        if ($code === '' || self::reseller($target)) {
            self::record($source, $code, $target, self::REFUSED);
            return ['status' => self::REFUSED, 'voucher' => null, 'result' => null];
        }
        if ($target->customerId === 0) {
            // WP9-A1 / U0 (OBS-W9-V1, P6): a voucher-only subscriber's RADIUS identity is the code. Under the shared
            // identity lock the code must be no customer's identity, no other live voucher identity and no RADIUS key in
            // use; the claim inside the same lock is the durable reservation of the code (a CLAIMED or voucher-only
            // granted code is an identity for every other acquirer). The grant stays OUTSIDE the lock. Refused before
            // the claim: the voucher stays redeemable, nothing is written.
            $candidateId = null;
            try {
                $v = CustomerIdentity::hold(static function (\PDO $pdo) use ($code, $target, $source, &$candidateId): ?array {
                    // nothing redeemable (unknown, used, or this code's own CLAIMED reservation): NOT_VALID, as before U0
                    $candidate = self::candidate($code, $source->kind === RedemptionSource::RADIUS);
                    if ($candidate === null) {
                        return null;
                    }
                    $candidateId = (int) $candidate['id'];
                    CustomerIdentity::assertAvailable($pdo, [$target->username]);
                    return self::claim($code, $source->kind === RedemptionSource::RADIUS);
                }, $source->kind === RedemptionSource::RADIUS ? CustomerIdentity::RADIUS_TIMEOUT : CustomerIdentity::TIMEOUT);
            } catch (DomainError $e) {
                self::record($source, $code, $target, self::REFUSED . ':' . ($e->reason === 'IDENTITY_TAKEN' ? 'IDENTITY_IN_USE' : $e->reason), $candidateId);
                return ['status' => self::REFUSED, 'voucher' => null, 'result' => null];
            }
        } else {
            $v = self::claim($code, $source->kind === RedemptionSource::RADIUS);
        }
        if ($v === null) {
            self::record($source, $code, $target, self::NOT_VALID);
            return ['status' => self::NOT_VALID, 'voucher' => null, 'result' => null];
        }
        return self::grant($v, $code, $target, $source);
    }

    /**
     * E13: a voucher with a NEW username (public login). The username is serialized by a per-username advisory
     * lock; an existing customer (username or PPPoE username) is refused untouched (OD-9). The provisional
     * customer is created once, the lock is released before the grant; when the grant is proven not started the
     * provisional row is removed and the voucher released, in ONE short transaction under the username lock.
     *
     * @return array{status:string,voucher:?array<string,mixed>,result:mixed,customer_id:?int}
     */
    public static function redeemForNewUsername(string $code, string $username, RedemptionSource $source): array
    {
        $normal = \Text::alphanumeric($username, '+_.@-');
        $target = new RedemptionTarget(0, $username);
        if ($code === '' || $normal !== $username || $username === '' || strlen($username) > 45) {
            self::record($source, $code, $target, self::REFUSED);
            return ['status' => self::REFUSED, 'voucher' => null, 'result' => null, 'customer_id' => null];
        }
        $pdo = self::pdo();
        $created = self::withUsernameLock($pdo, $username, static function (\PDO $pdo) use ($code, $username): array {
            if (Tx::row($pdo, 'SELECT id FROM tbl_customers WHERE username = ? OR pppoe_username = ? LIMIT 1', [$username, $username]) !== null) {
                return [self::USERNAME_TAKEN, null, null];
            }
            // WP9 U0 (P3, AM-W9-1): voucher username lock (L-2) -> identity lock (L-3); the canonical check, the claim and
            // the provisional customer insert run under the identity lock; the grant runs after both locks (unchanged)
            return CustomerIdentity::hold(static function (\PDO $pdo) use ($code, $username): array {
                try {
                    CustomerIdentity::assertAvailable($pdo, [$username]);
                } catch (DomainError $e) {
                    return [$e->reason === 'IDENTITY_TAKEN' ? self::USERNAME_TAKEN : self::REFUSED, null, null];
                }
                $v = self::claim($code, false);
                if ($v === null) {
                    return [self::NOT_VALID, null, null];
                }
                $id = Tx::run(static function (\PDO $pdo) use ($code, $username): int {
                    Tx::exec($pdo, "INSERT INTO tbl_customers (username, password, fullname, address, email, phonenumber) VALUES (?, ?, '', '', '', ?)",
                        [$username, $code, strlen($username) < 21 ? $username : '']);
                    return (int) $pdo->lastInsertId();
                });
                return ['CREATED', $v, $id];
            });
        });
        [$state, $v, $id] = $created;
        if ($state !== 'CREATED') {
            self::record($source, $code, $target, $state);
            return ['status' => $state, 'voucher' => null, 'result' => null, 'customer_id' => null];
        }
        $out = self::grant($v, $code, new RedemptionTarget($id, $username), $source, static function () use ($pdo, $id, $username, $code, $v): bool {
            // proven not started: remove the provisional customer and release the voucher atomically
            return self::withUsernameLock($pdo, $username, static fn(\PDO $pdo): bool => Tx::run(static function (\PDO $pdo) use ($id, $username, $code, $v): bool {
                $c = Tx::row($pdo, 'SELECT id, username, password FROM tbl_customers WHERE id = ? FOR UPDATE', [$id]);
                $untouched = $c !== null && $c['username'] === $username && $c['password'] === $code
                    && Tx::row($pdo, 'SELECT id FROM tbl_user_recharges WHERE customer_id = ? LIMIT 1', [$id]) === null
                    && Tx::row($pdo, 'SELECT id FROM tbl_transactions WHERE user_id = ? OR username = ? LIMIT 1', [$id, $username]) === null
                    && Tx::row($pdo, 'SELECT id FROM tbl_payment_gateway WHERE user_id = ? OR username = ? LIMIT 1', [$id, $username]) === null;
                if (!$untouched) {
                    return false;
                }
                Tx::exec($pdo, 'DELETE FROM tbl_customers WHERE id = ?', [$id]);
                return Tx::exec($pdo, "UPDATE tbl_voucher SET status = '0' WHERE id = ? AND status = '" . self::CLAIMED . "'", [(int) $v['id']]) === 1
                    ?: throw new \LogicException('claimed voucher changed concurrently');
            }));
        });
        return $out + ['customer_id' => $out['status'] === self::NOT_GRANTED ? null : $id];
    }

    // ------------------------------------------------------------------ steps

    /** True when the target is reseller-owned or its ownership cannot be evaluated (fail closed). */
    private static function reseller(RedemptionTarget $target): bool
    {
        return $target->customerId > 0 ? ServiceGate::isBlockedCustomerId($target->customerId) : ServiceGate::isBlockedUsername($target->username);
    }

    /**
     * O-U0-1 / owner §5: null when the voucher-only identity's RADIUS policy is CONFIRMED after the grant, otherwise the
     * review reason. Confirmed = this request's own write of the code's key returned SUCCESS (committed and read back equal
     * to the requested policy: RadiusProvisioner::lastProvision) AND the rows are still there (exact key bytes, so an
     * equivalent key of another owner never counts). Rows that merely exist under the name - stale, partial or foreign,
     * left by a refused or failed write - are never proof. NOT_CONFIRMED: no write of the key ran (refused before it, or
     * no device step); DEVICE_FAILED: the write was rolled back; DEVICE_UNKNOWN: the commit or its read-back is
     * unconfirmed; NO_SERVICE: confirmed, but the rows are gone. Plans on other devices cannot be verified here and keep
     * the WP5B behaviour. A read error counts as not confirmed (review, never a false GRANTED).
     */
    private static function unconfirmed(array $v, string $code): ?string
    {
        try {
            $plan = Tx::row(self::pdo(), 'SELECT device FROM tbl_plans WHERE id = ?', [(int) $v['id_plan']]);
            if ($plan === null || (string) $plan['device'] !== 'Radius') {
                return null;
            }
            $r = \Reseller\Radius\RadiusProvisioner::lastProvision(trim($code));
            if ($r === null) {
                return 'NOT_CONFIRMED';
            }
            if ($r->status === \Reseller\Radius\ProvisionResult::UNKNOWN) {
                return 'DEVICE_UNKNOWN';
            }
            if (!$r->isSuccess()) {
                return 'DEVICE_FAILED';
            }
            return \Reseller\Radius\RadiusRepository::connection()->exactRows(trim($code)) > 0 ? null : 'NO_SERVICE';
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'voucher-service-check');
            return 'NOT_CONFIRMED';
        }
    }

    /** The redeemable ('0') voucher with this code, null when none (read only). */
    private static function candidate(string $code, bool $radiusOnly): ?array
    {
        return Tx::row(self::pdo(), "SELECT * FROM tbl_voucher WHERE BINARY code = ? AND status = '0'" . ($radiusOnly ? " AND routers = 'radius'" : '') . ' ORDER BY id LIMIT 1', [$code]);
    }

    /** Atomic '0' -> 'CLAIMED' of one voucher with this code; null when none is redeemable. */
    private static function claim(string $code, bool $radiusOnly): ?array
    {
        $pdo = self::pdo();
        for ($i = 0; $i < 3; $i++) {
            $v = self::candidate($code, $radiusOnly);
            if ($v === null) {
                return null;
            }
            if (Tx::exec($pdo, "UPDATE tbl_voucher SET status = '" . self::CLAIMED . "' WHERE id = ? AND status = '0'", [(int) $v['id']]) === 1) {
                return $v;
            }
        }
        return null;
    }

    /** @param (callable():bool)|null $release the proven-not-started release (default: the voucher back to '0') */
    private static function grant(array $v, string $code, RedemptionTarget $target, RedemptionSource $source, ?callable $release = null): array
    {
        $pdo = self::pdo();
        $id = (int) $v['id'];
        // owner §5: only a write of THIS grant can confirm the voucher-only service (no earlier result in this process)
        \Reseller\Radius\RadiusProvisioner::forgetProvision(trim($code));
        try {
            $result = \Package::rechargeUser($target->customerId, $v['routers'], $v['id_plan'], 'Voucher', $code);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'voucher-grant');
            self::record($source, $code, $target, self::REVIEW, (int) $v['id']);
            return ['status' => self::REVIEW, 'voucher' => $v, 'result' => null];
        }
        if ($result === false) {
            $released = $release !== null ? $release()
                : Tx::exec($pdo, "UPDATE tbl_voucher SET status = '0' WHERE id = ? AND status = '" . self::CLAIMED . "'", [$id]) === 1;
            $status = $released ? self::NOT_GRANTED : self::REVIEW;
            self::record($source, $code, $target, $status, $id);
            return ['status' => $status, 'voucher' => $v, 'result' => false];
        }
        if ($result === null || $result === true || !is_string($result) || $result === '') {
            // anything but an invoice: the grant may have happened; never redeemable again, never re-granted
            self::record($source, $code, $target, self::REVIEW, $id);
            return ['status' => self::REVIEW, 'voucher' => $v, 'result' => $result];
        }
        if ($target->customerId === 0 && ($why = self::unconfirmed($v, $code)) !== null) {
            // WP9 U0 final hardening (O-U0-1, owner §5): Package::rechargeUser (pinned) returns an invoice even when its
            // device step failed (it records the failure and continues). A voucher-only identity whose RADIUS policy was
            // not confirmed written by this grant is NOT granted: the voucher stays CLAIMED (never redeemable again,
            // operator review), no second grant
            self::record($source, $code, $target, self::REVIEW . ':' . $why, $id);
            return ['status' => self::REVIEW, 'voucher' => $v, 'result' => $result];
        }
        Tx::exec($pdo, "UPDATE tbl_voucher SET status = '1', used_date = ?, user = ? WHERE id = ? AND status = '" . self::CLAIMED . "'",
            [date('Y-m-d H:i:s'), $target->customerId > 0 ? $target->username : (string) ($v['user'] ?? ''), $id]);
        self::record($source, $code, $target, self::GRANTED, $id);
        return ['status' => self::GRANTED, 'voucher' => $v, 'result' => $result];
    }

    /**
     * @template T
     * @param callable(\PDO):T $fn
     * @return T
     */
    private static function withUsernameLock(\PDO $pdo, string $username, callable $fn): mixed
    {
        $name = 'nuxr_user_' . sha1((string) $pdo->query('SELECT DATABASE()')->fetchColumn() . '|' . strtolower($username));
        $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute([$name, self::LOCK_TIMEOUT]);
        if ((string) $st->fetchColumn() !== '1') {
            throw new DomainError('LOCK_TIMEOUT');
        }
        try {
            return $fn($pdo);
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    /**
     * WP9 U0 final hardening (N-07): a voucher code is a credential (the voucher-only username AND password). Neither the
     * code nor a derivative of it (an unsalted short hash of a short code is easy to reverse) is ever logged: the record
     * names the voucher by its row id (when known) and the target by kind; the outcome keeps the refusal reason.
     */
    private static function record(RedemptionSource $source, string $code, RedemptionTarget $target, string $outcome, ?int $voucherId = null): void
    {
        if ($source->context !== null) {
            try {
                AuditLog::record('voucher.redeem', $source->context, [
                    'target_type' => 'customer', 'target_customer_id' => $target->customerId > 0 ? $target->customerId : null,
                    'target_snapshot' => ['username' => $target->customerId > 0 ? $target->username : null, 'voucher_id' => $voucherId],
                    'after' => ['outcome' => $outcome, 'source' => $source->kind],
                ]);
                return;
            } catch (\Throwable $e) {
                ErrorReporter::report($e, 'voucher-audit');
            }
        }
        $kind = $target->customerId > 0 ? 'customer:' . $target->customerId : ($target->username === $code ? 'voucher-only' : 'new-customer');
        ErrorReporter::log('voucher.redeem', 'source=' . $source->kind . ' voucher_id=' . ($voucherId ?? 'none') . ' target=' . $kind
            . ($source->client !== null ? ' client=' . $source->client : '') . ' outcome=' . $outcome);
    }

    private static function pdo(): \PDO
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
