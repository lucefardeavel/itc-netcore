<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\Tx;
use Reseller\Finance\FinanceFaults;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\TransferService;
use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\FinanceTestCase;

/**
 * Owner deadlock rule: 1213 is retried only around the entire DB-only unit; between attempts the
 * transaction is rolled back, the strict sql_mode envelope restored, every row lock gone and the tree
 * lock released (then re-acquired); retries are bounded. 1205 is not retried.
 */
final class FinanceTxTest extends FinanceTestCase
{
    private static function deadlock(int $code = 1213): \PDOException
    {
        $e = new \PDOException("SQLSTATE[40001]: Serialization failure: {$code} injected");
        $e->errorInfo = [$code === 1213 ? '40001' : 'HY000', $code, 'injected'];
        return $e;
    }

    /** sql_mode as a set (the server normalizes the order of the flags). */
    private static function modes(string $mode): array
    {
        $m = explode(',', $mode);
        sort($m);
        return $m;
    }

    private function lockName(): string
    {
        return 'nuxr_tree_' . substr(sha1(static::$db->name), 0, 20);
    }

    public function testDeadlockIsRetriedWithACleanEnvelope(): void
    {
        $id = $this->agent();
        $ref = self::ref('DL');
        static::$svc->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $observed = [];
        $failures = 1;
        FinanceFaults::$hook = function (string $p) use (&$observed, &$failures, $ref, $id): void {
            if ($p === 'fund.after_ledger') {
                $observed[] = ['in_tx_mode' => self::modes((string) static::$svc->query('SELECT @@SESSION.sql_mode')->fetchColumn()),
                    'lock_owner_is_me' => (string) static::$svc->query("SELECT IS_USED_LOCK('{$this->lockName()}') = CONNECTION_ID()")->fetchColumn()];
                if ($failures-- > 0) {
                    throw self::deadlock();
                }
            }
            if ($p === 'tx.before_retry') {
                $observed[] = [
                    'in_transaction' => static::$svc->inTransaction(),
                    'session_mode' => static::$svc->query('SELECT @@SESSION.sql_mode')->fetchColumn(),
                    'tree_lock' => static::$db->value('SELECT IS_USED_LOCK(?)', [$this->lockName()]),
                    'rows_left' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_transfers WHERE reference = ?', [$ref]),
                    'wallet_left' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_wallets WHERE manager_id = ?', [$id]),
                    'locks_left' => (int) static::$db->value("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ?", [(int) static::$svc->query('SELECT CONNECTION_ID()')->fetchColumn()]),
                ];
            }
        };
        $r = TransferService::fund($this->ctx('ad'), $id, '5', 'retried funding', $ref);
        FinanceFaults::$hook = null;
        $this->assertSame([
            ['in_tx_mode' => self::modes(Tx::STRICT_MODE), 'lock_owner_is_me' => '1'],
            ['in_transaction' => false, 'session_mode' => 'NO_ENGINE_SUBSTITUTION', 'tree_lock' => null, 'rows_left' => 0, 'wallet_left' => 0, 'locks_left' => 0],
            ['in_tx_mode' => self::modes(Tx::STRICT_MODE), 'lock_owner_is_me' => '1'],
        ], $observed);
        $this->assertFalse($r->replayed);
        $this->assertSame(1, $this->rowCount('tbl_manager_transfers', 'reference = ?', [$ref]), 'exactly one effect');
        $this->assertSame('5.00', $this->balance($id));
        $this->assertSame('NO_ENGINE_SUBSTITUTION', static::$svc->query('SELECT @@SESSION.sql_mode')->fetchColumn(), 'restored after success');
    }

    public function testRetriesAreBounded(): void
    {
        $id = $this->agent();
        $attempts = 0;
        $retries = 0;
        FinanceFaults::$hook = function (string $p) use (&$attempts, &$retries): void {
            if ($p === 'fund.after_ledger') {
                $attempts++;
                throw self::deadlock();
            }
            if ($p === 'tx.before_retry') {
                $retries++;
            }
        };
        $this->refused('CONCURRENCY_RETRY', fn() => TransferService::fund($this->ctx('ad'), $id, '5', 'always deadlocks', self::ref()));
        FinanceFaults::$hook = null;
        $this->assertSame([1 + FinanceTx::MAX_DEADLOCK_RETRIES, FinanceTx::MAX_DEADLOCK_RETRIES], [$attempts, $retries]);
        $this->assertSame(2, FinanceTx::MAX_DEADLOCK_RETRIES);
    }

    public function testLockWaitTimeoutAndOtherErrorsAreNotRetried(): void
    {
        $id = $this->agent();
        $retries = 0;
        FinanceFaults::$hook = function (string $p) use (&$retries): void {
            if ($p === 'fund.after_ledger') {
                throw self::deadlock(1205);
            }
            if ($p === 'tx.before_retry') {
                $retries++;
            }
        };
        $this->refused('LOCK_TIMEOUT', fn() => TransferService::fund($this->ctx('ad'), $id, '5', 'lock wait timeout', self::ref()));
        FinanceFaults::$hook = function (string $p) use (&$retries): void {
            if ($p === 'fund.after_ledger') {
                throw self::deadlock(1062);
            }
            if ($p === 'tx.before_retry') {
                $retries++;
            }
        };
        $before = DbProbe::checksums(static::$db, ['tbl_logs']);
        try {
            TransferService::fund($this->ctx('ad'), $id, '5', 'other database error', self::ref());
            $this->fail('expected the PDOException');
        } catch (\PDOException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
        }
        FinanceFaults::$hook = null;
        $this->assertSame(0, $retries);
        $this->assertSame([], DbProbe::changed($before, DbProbe::checksums(static::$db, ['tbl_logs'])));
    }

    /** Strict SQL inside the financial envelope only; the legacy session mode is restored on every path. */
    public function testStrictModeEnvelopeOnSuccessAndFailure(): void
    {
        $id = $this->funded('10');
        static::$svc->exec("SET SESSION sql_mode = ''");
        TransferService::fund($this->ctx('ad'), $id, '1', 'success path', self::ref());
        $this->assertSame('', static::$svc->query('SELECT @@SESSION.sql_mode')->fetchColumn());
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::withdraw($this->ctx('ad'), $id, '100', 'failure path', self::ref()));
        $this->assertSame('', static::$svc->query('SELECT @@SESSION.sql_mode')->fetchColumn());
        static::$svc->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    }

    public function testTreeLockNameIsTheAcceptedWp2Lock(): void
    {
        $same = Tx::withTreeLock(fn(\PDO $pdo) => (string) $pdo->query("SELECT IS_USED_LOCK('" . FinanceTx::treeLockName($pdo) . "') = CONNECTION_ID()")->fetchColumn());
        $this->assertSame('1', $same);
        $this->assertSame($this->lockName(), FinanceTx::treeLockName(static::$svc));
    }
}
