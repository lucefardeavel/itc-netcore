<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\Tx;

/**
 * Transaction envelope for the financial writers. Reuses the accepted WP2 envelope (Tx::run: strict
 * session sql_mode restored afterwards, rollback on any Throwable; Tx::withTreeLock: global hierarchy
 * lock taken before BEGIN and released after COMMIT/ROLLBACK).
 *
 * Deadlock (1213) is retried only around the ENTIRE DB-only unit of work: every attempt is a complete
 * Tx::withTreeLock / Tx::run call, so before the next attempt the transaction is rolled back, the
 * session sql_mode restored, all row locks gone and the tree lock released (and then re-acquired).
 * WP3A performs no network or other external side effect, and every effect is idempotent by
 * reference / activation / reservation id. Lock-wait timeout (1205) is not retried.
 */
final class FinanceTx
{
    public const MAX_DEADLOCK_RETRIES = 2;

    /**
     * @template T
     * @param callable(\PDO):T $fn
     * @return T
     */
    public static function run(callable $fn, bool $treeLock): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $treeLock ? Tx::withTreeLock($fn) : Tx::run($fn);
            } catch (\PDOException $e) {
                $code = self::driverCode($e);
                if ($code === 1213) {
                    if ($attempt < self::MAX_DEADLOCK_RETRIES) {
                        FinanceFaults::hit('tx.before_retry');
                        continue;
                    }
                    throw new DomainError('CONCURRENCY_RETRY');
                }
                if ($code === 1205) {
                    throw new DomainError('LOCK_TIMEOUT');
                }
                throw $e;
            }
        }
    }

    /** MySQL/MariaDB driver error number of a PDOException (1213 deadlock, 1205 lock wait timeout). */
    public static function driverCode(\PDOException $e): ?int
    {
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[1]) && is_numeric($info[1])) {
            return (int) $info[1];
        }
        return null;
    }

    /** The shared PDO in exception mode (read paths outside a transaction). */
    public static function pdo(): \PDO
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** Name of the accepted WP2 global hierarchy lock (same derivation as Tx::withTreeLock). */
    public static function treeLockName(\PDO $pdo): string
    {
        return 'nuxr_tree_' . substr(sha1((string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 20);
    }

    public static function assertInTransaction(\PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('financial writer called outside a transaction');
        }
    }

    /** The *InTx reservation primitive requires the caller's transaction AND this connection's tree lock. */
    public static function assertTreeLockHeld(\PDO $pdo): void
    {
        self::assertInTransaction($pdo);
        $st = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        $st->execute([self::treeLockName($pdo)]);
        if ((string) $st->fetchColumn() !== '1') {
            throw new DomainError('LOCK_REQUIRED');
        }
    }
}
