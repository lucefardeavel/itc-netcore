<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Transaction envelope for reseller-domain writers: strict session sql_mode for the duration of the
 * unit of work (restored afterwards, so legacy code in the same request is unaffected), commit on
 * success, rollback on any Throwable.
 */
final class Tx
{
    public const STRICT_MODE = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /**
     * @template T
     * @param callable(\PDO):T $fn
     * @return T
     */
    public static function run(callable $fn): mixed
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $old = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = '" . self::STRICT_MODE . "'");
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $st = $pdo->prepare('SET SESSION sql_mode = ?');
            $st->execute([$old]);
        }
    }

    /**
     * Runs $fn under the global reseller-tree lock, taken BEFORE the transaction starts so the
     * transaction's snapshot sees every previously committed tree change (concurrent cross-moves
     * cannot both pass the cycle check). Lock timeout fails closed (LOCK_TIMEOUT).
     *
     * @template T
     * @param callable(\PDO):T $fn
     * @return T
     */
    public static function withTreeLock(callable $fn, int $timeout = 10): mixed
    {
        $pdo = \ORM::get_db();
        $name = 'nuxr_tree_' . substr(sha1((string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 20);
        $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute([$name, $timeout]);
        if ((string) $st->fetchColumn() !== '1') {
            throw new DomainError('LOCK_TIMEOUT');
        }
        try {
            return self::run($fn);
        } finally {
            $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$name]);
        }
    }

    public static function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    /** @return array<string,mixed>|null */
    public static function row(\PDO $pdo, string $sql, array $params = []): ?array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return list<array<string,mixed>> */
    public static function rows(\PDO $pdo, string $sql, array $params = []): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function exec(\PDO $pdo, string $sql, array $params = []): int
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }
}
