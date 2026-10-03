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
        $v = self::claim($code, $source->kind === RedemptionSource::RADIUS);
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

    /** Atomic '0' -> 'CLAIMED' of one voucher with this code; null when none is redeemable. */
    private static function claim(string $code, bool $radiusOnly): ?array
    {
        $pdo = self::pdo();
        for ($i = 0; $i < 3; $i++) {
            $v = Tx::row($pdo, "SELECT * FROM tbl_voucher WHERE BINARY code = ? AND status = '0'" . ($radiusOnly ? " AND routers = 'radius'" : '') . ' ORDER BY id LIMIT 1', [$code]);
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
        try {
            $result = \Package::rechargeUser($target->customerId, $v['routers'], $v['id_plan'], 'Voucher', $code);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'voucher-grant');
            self::record($source, $code, $target, self::REVIEW);
            return ['status' => self::REVIEW, 'voucher' => $v, 'result' => null];
        }
        if ($result === false) {
            $released = $release !== null ? $release()
                : Tx::exec($pdo, "UPDATE tbl_voucher SET status = '0' WHERE id = ? AND status = '" . self::CLAIMED . "'", [$id]) === 1;
            $status = $released ? self::NOT_GRANTED : self::REVIEW;
            self::record($source, $code, $target, $status);
            return ['status' => $status, 'voucher' => $v, 'result' => false];
        }
        if ($result === null || $result === true || !is_string($result) || $result === '') {
            // anything but an invoice: the grant may have happened; never redeemable again, never re-granted
            self::record($source, $code, $target, self::REVIEW);
            return ['status' => self::REVIEW, 'voucher' => $v, 'result' => $result];
        }
        Tx::exec($pdo, "UPDATE tbl_voucher SET status = '1', used_date = ?, user = ? WHERE id = ? AND status = '" . self::CLAIMED . "'",
            [date('Y-m-d H:i:s'), $target->customerId > 0 ? $target->username : (string) ($v['user'] ?? ''), $id]);
        self::record($source, $code, $target, self::GRANTED);
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

    private static function record(RedemptionSource $source, string $code, RedemptionTarget $target, string $outcome): void
    {
        if ($source->context !== null) {
            try {
                AuditLog::record('voucher.redeem', $source->context, [
                    'target_type' => 'customer', 'target_customer_id' => $target->customerId > 0 ? $target->customerId : null,
                    'target_snapshot' => ['username' => $target->username, 'voucher' => self::codeRef($code)],
                    'after' => ['outcome' => $outcome, 'source' => $source->kind],
                ]);
                return;
            } catch (\Throwable $e) {
                ErrorReporter::report($e, 'voucher-audit');
            }
        }
        ErrorReporter::log('voucher.redeem', 'source=' . $source->kind . ' voucher=' . self::codeRef($code) . ' target=' . $target->username
            . ($source->client !== null ? ' client=' . $source->client : '') . ' outcome=' . $outcome);
    }

    /** A non-reversible reference of a voucher code for logs (never the code). */
    private static function codeRef(string $code): string
    {
        return substr(hash('sha256', $code), 0, 16);
    }

    private static function pdo(): \PDO
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
