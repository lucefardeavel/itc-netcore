<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Lock-only holder of the accepted WP2 global reseller-tree lock (WP5B §4.1). The SAME named lock as
 * Tx::withTreeLock / FinanceTx::run(…, true) (same name derivation; not a second namespace), held WITHOUT a
 * transaction, so a bounded legacy re-provision can keep activation opens, reversal starts and pricing work out
 * of its window while it performs short transactions and a device / network call of its own. Tx.php (pinned)
 * is not changed. Named locks are per session and re-entrant: a nested FinanceTx::run(…, true) inside hold()
 * re-acquires and releases the same lock without ending the outer hold. Timeout fails closed (LOCK_TIMEOUT).
 */
final class TreeSerialization
{
    public const TIMEOUT = 10;

    public static function name(\PDO $pdo): string
    {
        return 'nuxr_tree_' . substr(sha1((string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 20);
    }

    /**
     * @template T
     * @param callable(\PDO):T $fn
     * @return T
     */
    public static function hold(callable $fn, int $timeout = self::TIMEOUT): mixed
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if ($pdo->inTransaction()) {
            throw new \LogicException('the tree lock is taken before any transaction');
        }
        $name = self::name($pdo);
        $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute([$name, $timeout]);
        if ((string) $st->fetchColumn() !== '1') {
            throw new DomainError('LOCK_TIMEOUT');
        }
        try {
            return $fn($pdo);
        } finally {
            $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$name]);
        }
    }

    /** Whether this session holds the tree lock (the permit check of the device default deny). */
    public static function held(\PDO $pdo): bool
    {
        $st = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        $st->execute([self::name($pdo)]);
        return (string) $st->fetchColumn() === '1';
    }
}
