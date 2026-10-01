<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Concurrent finance workers (separate PHP processes, separate DB connections) for the WP3A
 * concurrency tests, plus a deterministic barrier: the test holds a lock (a wallet row or the global
 * tree lock), starts the workers, waits until every worker is blocked on a database lock, then
 * releases it so that all workers race for the same state.
 */
final class FinanceWorkers
{
    /** @param array<string,mixed> $op @return array{proc:resource,pipes:array} */
    public static function start(TestDatabase $db, array $op): array
    {
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/finance_worker.php', $db->name, json_encode($op, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('worker did not start');
        }
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /** @return array<string,mixed> {ok, result|reason, error?} */
    public static function finish(array $h): array
    {
        $out = stream_get_contents($h['pipes'][1]) . stream_get_contents($h['pipes'][2]);
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        proc_close($h['proc']);
        $pos = strrpos($out, '@@FINANCE@@');
        if ($pos === false) {
            throw new \RuntimeException('worker failed: ' . $out);
        }
        return json_decode(trim(substr($out, $pos + 11)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Waits until $n worker connections of the test database are blocked on a row lock (a locking read,
     * exclusive or - since the WP3B settlement locks its FK identity rows first - shared) or on the tree
     * lock (GET_LOCK). Engine-agnostic: a connection counts once it has been executing
     * the same locking statement for at least 5 consecutive polls (InnoDB lock-wait views do not list
     * locking-read-only transactions on every engine).
     */
    public static function waitBlocked(TestDatabase $db, int $n, float $timeout = 30.0): void
    {
        $deadline = microtime(true) + $timeout;
        $sql = "SELECT ID, INFO FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID() AND COMMAND = 'Query' "
            . "AND (STATE = 'User lock' OR INFO LIKE '%FOR UPDATE%' OR INFO LIKE '%LOCK IN SHARE MODE%' OR INFO LIKE '%GET_LOCK%')";
        $streak = [];
        while (microtime(true) < $deadline) {
            $now = [];
            foreach ($db->rows($sql, [$db->name]) as $r) {
                $key = $r['ID'] . '|' . $r['INFO'];
                $now[$key] = ($streak[$key] ?? 0) + 1;
            }
            $streak = $now;
            if (count(array_filter($streak, static fn(int $c) => $c >= 5)) >= $n) {
                return;
            }
            usleep(10000);
        }
        throw new \RuntimeException("barrier: fewer than {$n} blocked workers after {$timeout}s");
    }

    /** A separate connection holding the barrier lock. */
    public static function holdWallet(TestDatabase $db, int $managerId): \PDO
    {
        $pdo = FixtureBuilder::connect($db->host, TestDatabase::rootPassword(), $db->name);
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT manager_id FROM tbl_manager_wallets WHERE manager_id = ? FOR UPDATE');
        $st->execute([$managerId]);
        return $pdo;
    }

    public static function holdTree(TestDatabase $db): \PDO
    {
        $pdo = FixtureBuilder::connect($db->host, TestDatabase::rootPassword(), $db->name);
        $st = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $st->execute(['nuxr_tree_' . substr(sha1($db->name), 0, 20)]);
        if ((string) $st->fetchColumn() !== '1') {
            throw new \RuntimeException('barrier: tree lock not acquired');
        }
        return $pdo;
    }

    public static function release(\PDO $hold): void
    {
        if ($hold->inTransaction()) {
            $hold->commit();
        }
        $hold->query('SELECT RELEASE_ALL_LOCKS()')->fetchAll();
    }

    /**
     * Runs $ops concurrently behind a barrier and returns their results in input order.
     *
     * @param list<array<string,mixed>> $ops
     * @return list<array<string,mixed>>
     */
    public static function race(TestDatabase $db, \PDO $hold, array $ops): array
    {
        $handles = [];
        $error = null;
        try {
            foreach ($ops as $op) {
                $handles[] = self::start($db, $op);
            }
            self::waitBlocked($db, count($ops));
        } catch (\Throwable $e) {
            $error = $e;
        } finally {
            self::release($hold);
        }
        $results = array_map([self::class, 'finish'], $handles);
        if ($error !== null) {
            throw $error;
        }
        return $results;
    }

    /**
     * Free-running race without a lock barrier: every worker boots, then all start at once when the
     * go file appears.
     *
     * @param list<array<string,mixed>> $ops
     * @return list<array<string,mixed>>
     */
    public static function stampede(TestDatabase $db, array $ops): array
    {
        $go = sys_get_temp_dir() . '/wp3a_go_' . bin2hex(random_bytes(6));
        $handles = [];
        foreach ($ops as $op) {
            $handles[] = self::start($db, $op + ['go_file' => $go]);
        }
        usleep(300000);
        touch($go);
        try {
            return array_map([self::class, 'finish'], $handles);
        } finally {
            @unlink($go);
        }
    }
}
