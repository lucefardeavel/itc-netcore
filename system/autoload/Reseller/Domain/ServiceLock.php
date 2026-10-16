<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP9-B / R-4 (N-04): per-customer service lock L-S ("nuxr_svc_<db>_<customer id>").
 *
 * The cron expiry decided on a snapshot of the recharge row and shared no lock with the activation that
 * renews / reactivates / changes the same customer's service, so a renewal that provisioned (or completed)
 * while the cron was expiring the old period could end as a paid COMPLETED activation with suspended RADIUS
 * and the recharge row 'off'. Every writer of a customer's SERVICE PERIOD now runs under L-S:
 *  - ActivationService::execute (provision -> verify -> settlement + legacy bridge),
 *  - LegacyReprovisionService::run (bounded re-provision),
 *  - the cron expiry (re-reads the recharge row under the lock and skips a period renewed meanwhile).
 *
 * Lock order: L-S is the OUTERMOST lock. It is never taken inside a transaction or while this session holds
 * the tree lock (L-1) or the identity lock (L-3); the U0 order L-1 -> L-2 -> L-3 -> BEGIN applies inside it.
 * A busy lock is never waited for indefinitely: the caller gets SERVICE_BUSY and makes no state change.
 */
final class ServiceLock
{
    public const TIMEOUT = 15;

    public static function name(\PDO $pdo, int $customerId): string
    {
        $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        return 'nuxr_svc_' . substr(sha1($db), 0, 20) . '_' . $customerId;
    }

    /**
     * Runs $fn while this session holds L-S of $customerId.
     *
     * @throws DomainError SERVICE_BUSY when the lock is not obtained within $timeout seconds
     */
    public static function hold(int $customerId, callable $fn, int $timeout = self::TIMEOUT): mixed
    {
        if ($customerId <= 0) {
            return $fn();
        }
        if (!self::acquire($customerId, $timeout)) {
            throw new DomainError('SERVICE_BUSY');
        }
        try {
            return $fn();
        } finally {
            self::release($customerId);
        }
    }

    /** Low-level form for the procedural cron loop: true when L-S of $customerId is now held by this session. */
    public static function acquire(int $customerId, int $timeout = self::TIMEOUT): bool
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if ($pdo->inTransaction() || TreeSerialization::held($pdo) || CustomerIdentity::held($pdo)) {
            throw new \LogicException('the service lock is the outermost lock (never inside a transaction, the tree or the identity lock)');
        }
        $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute([self::name($pdo, $customerId), $timeout]);
        return (string) $st->fetchColumn() === '1';
    }

    public static function release(int $customerId): void
    {
        $pdo = \ORM::get_db();
        $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $rel->execute([self::name($pdo, $customerId)]);
    }
}
