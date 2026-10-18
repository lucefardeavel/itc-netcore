<?php
namespace Reseller\Tests\Lab;

// WP10 STAGING LOAD TEST (test tooling only). Not part of any PHPUnit suite: run explicitly by dev/wp10/load.sh on the
// disposable databases of the isolated nuxbill-reseller-test compose project. Never against a production service.
// Bounded by design: at most MAX_CONCURRENCY worker processes (one database connection each) at any moment, far below
// the server's max_connections; the peak connection count is measured and asserted. It is NOT a connection- or
// pool-exhaustion test and never tries to reach a limit (the forbidden SQL-exhaustion reproduction is never run).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ReconciliationService;
use Reseller\Finance\SettlementReadModel;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceWorkers;

/**
 *  L1 distributed: waves of concurrent NEW activations by different leaf managers of one parent (shared ancestors);
 *  L2 contention: every worker of a wave activates under the SAME manager wallet;
 *  L3 same customer: concurrent RENEW of one customer (service lock) - exactly one proceeds per wave, the rest are
 *     refused cleanly (SERVICE_BUSY / ACTION_CHANGED / replay), never a double charge;
 * After every scenario: finance integrity (ledger = wallets, reservations, settlements) is clean, every activation is
 * terminal, no ERROR outcome, and the peak connections stay within the bound.
 */
final class StagingLoadTest extends ActivationTestCase
{
    private const MAX_CONCURRENCY = 12;
    private const WAVES = 8;

    private static function log(string $line): void
    {
        $dir = getenv('WP10_LOAD_OUT') ?: '/tmp';
        file_put_contents($dir . '/load.log', gmdate('Y-m-d\TH:i:s\Z ') . $line . "\n", FILE_APPEND);
        fwrite(STDERR, $line . "\n");
    }

    /** the server's connection high-water mark since the scenario's FLUSH STATUS */
    private function peak(): int
    {
        return (int) static::$db->rows("SHOW GLOBAL STATUS LIKE 'Max_used_connections'")[0]['Value'];
    }

    /** runs the waves; returns [outcomes, per-wave seconds, peak connections] */
    private function waves(callable $opsForWave): array
    {
        $outcomes = [];
        $times = [];
        $peak = 0;
        // disposable test server only: restart the connection high-water mark for this scenario
        static::$db->exec('FLUSH STATUS');
        for ($w = 0; $w < self::WAVES; $w++) {
            $ops = $opsForWave($w);
            $this->assertLessThanOrEqual(self::MAX_CONCURRENCY, count($ops));
            $t = microtime(true);
            $res = FinanceWorkers::stampede(static::$db, $ops);
            $times[] = microtime(true) - $t;
            $peak = max($peak, $this->peak());
            foreach ($res as $r) {
                $outcomes[] = $r['ok'] ? ('OK:' . ($r['result']['status'] ?? '?') . ($r['result']['replayed'] ?? false ? ':replayed' : '')) : ('REFUSED:' . $r['reason']);
                if (!$r['ok'] && $r['reason'] === 'ERROR') {
                    self::log('ERROR outcome: ' . ($r['error'] ?? ''));
                }
            }
        }
        return [$outcomes, $times, $peak];
    }

    private function report(string $name, array $outcomes, array $times, int $peak, int $ops): void
    {
        sort($times);
        $total = array_sum($times);
        $counts = array_count_values($outcomes);
        ksort($counts);
        self::log(sprintf('%s: %d ops in %d waves of <= %d, total %.2f s, %.1f ops/s, wave p50 %.2f s, wave max %.2f s, peak Max_used_connections %d (max_connections %s), outcomes %s',
            $name, $ops, self::WAVES, self::MAX_CONCURRENCY, $total, $ops / max($total, 0.001), $times[intdiv(count($times), 2)], end($times), $peak,
            static::$db->value('SELECT @@GLOBAL.max_connections'), json_encode($counts)));
    }

    /** operator recovery of the activations a deadlock refusal left behind; @return int how many were recovered */
    private function recoverHeldActivations(): int
    {
        $held = static::$db->rows("SELECT a.reference, a.status, (SELECT GROUP_CONCAT(r.status) FROM tbl_manager_wallet_reservations r WHERE r.activation_id = a.id) AS reservation
            FROM tbl_reseller_activations a WHERE a.status NOT IN ('COMPLETED','FAILED','CANCELLED','ROLLED_BACK','REVERSED')");
        foreach ($held as $h) {
            $this->assertSame(['FUNDS_RESERVED', 'ACTIVE'], [$h['status'], $h['reservation']], 'a refused activation is held, never half-granted: ' . json_encode($h));
            // stale (the CLI refuses a live one): the same age the operator waits for
            static::$db->pdo->prepare('UPDATE tbl_reseller_activations SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 1 HOUR WHERE reference = ?')->execute([$h['reference']]);
            $this->assertSame('CANCELLED', ReconciliationService::cancel(ActivationActor::system('staging-load'), $h['reference'])['status']);
        }
        return count($held);
    }

    private function assertClean(string $name, array $outcomes, int $peak): void
    {
        $this->assertSame([], array_values(array_filter($outcomes, static fn(string $o): bool => $o === 'REFUSED:ERROR')), "{$name}: no unexpected error");
        $open = static::$db->rows("SELECT a.reference, a.status, a.action, (SELECT GROUP_CONCAT(CONCAT(e.sequence_no, ':', IFNULL(e.from_state, '-'), '>', e.to_state, ' ', IFNULL(e.error_code, '')) ORDER BY e.sequence_no SEPARATOR ' | ') FROM tbl_reseller_activation_events e WHERE e.activation_id = a.id) AS timeline,
            (SELECT GROUP_CONCAT(r.status) FROM tbl_manager_wallet_reservations r WHERE r.activation_id = a.id) AS reservation FROM tbl_reseller_activations a
            WHERE a.status NOT IN ('COMPLETED','FAILED','CANCELLED','ROLLED_BACK','REVERSED')");
        self::log("{$name}: non-terminal activations: " . json_encode($open));
        $this->assertSame([], $open, "{$name}: every activation is terminal");
        $this->assertSame(0, (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_wallet_reservations WHERE status = 'ACTIVE'"), "{$name}: no reservation left");
        $this->assertSame([], SettlementReadModel::integrity(), "{$name}: finance integrity");
        $this->assertLessThanOrEqual(self::MAX_CONCURRENCY + 10, $peak, "{$name}: connections stay bounded (workers + the harness)");
    }

    public function testBoundedLoad(): void
    {
        self::log('==== load start engine=' . static::$db->engine() . ' ' . static::$db->value('SELECT VERSION()'));
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $this->fund($top, '20000');
        $this->fund($leaf, '20000');

        // L1: distributed - each worker a different customer; managers alternate between the parent and its child
        $n = 0;
        [$out, $times, $peak] = $this->waves(function (int $w) use ($plan, $top, $leaf, &$n): array {
            $ops = [];
            for ($i = 0; $i < self::MAX_CONCURRENCY; $i++) {
                $m = $i % 2 ? $leaf : $top;
                $ops[] = ['op' => 'activate', 'actor' => $m, 'customer' => $this->customer($m), 'plan' => $plan, 'action' => 'NEW', 'nonce' => self::nonce()];
                $n++;
            }
            return $ops;
        });
        $this->report('L1 distributed', $out, $times, $peak, $n);
        $this->assertSame($n, count(array_filter($out, static fn(string $o): bool => $o === 'OK:COMPLETED')), 'L1: every activation completes');
        $this->assertClean('L1', $out, $peak);

        // L2: contention on one manager wallet
        $completedBefore = (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_activations WHERE status = 'COMPLETED'");
        $n = 0;
        [$out, $times, $peak] = $this->waves(function (int $w) use ($plan, $leaf, &$n): array {
            $ops = [];
            for ($i = 0; $i < self::MAX_CONCURRENCY; $i++) {
                $ops[] = ['op' => 'activate', 'actor' => $leaf, 'customer' => $this->customer($leaf), 'plan' => $plan, 'action' => 'NEW', 'nonce' => self::nonce()];
                $n++;
            }
            return $ops;
        });
        $this->report('L2 one wallet', $out, $times, $peak, $n);
        // Accepted contract (WP3A FinanceTx): a deadlock victim is retried MAX_DEADLOCK_RETRIES (2) times and then refused CLEANLY with
        // CONCURRENCY_RETRY ("please try again"). Under a 12-way stampede on ONE wallet an occasional such refusal is the designed
        // outcome (measured 2026-10-18: about 1 run in 4 on MariaDB sees one, also on the previous candidate). It must never be anything
        // else, never exceed a few operations, and must leave NOTHING behind: every other operation completes, the COMPLETED activations
        // are exactly the OK outcomes, no reservation is left, the ledger / wallets reconcile.
        $ok = count(array_filter($out, static fn(string $o): bool => $o === 'OK:COMPLETED'));
        $retry = count(array_filter($out, static fn(string $o): bool => $o === 'REFUSED:CONCURRENCY_RETRY'));
        $this->assertSame($n, $ok + $retry, 'L2: every activation completes or is cleanly refused with CONCURRENCY_RETRY (nothing else, none lost): ' . json_encode(array_count_values($out)));
        $this->assertLessThanOrEqual(3, $retry, 'L2: at most a handful of deadlock refusals');
        self::log("L2 clean refusals (CONCURRENCY_RETRY): {$retry} of {$n}");
        $this->assertSame($ok, (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_activations WHERE status = 'COMPLETED'") - $completedBefore, 'L2: the COMPLETED activations are exactly the OK outcomes (a refusal left no service and no charge)');
        // Accepted design (WP5A, runbook section 1): a refusal after the funds were reserved leaves the activation FUNDS_RESERVED with an
        // ACTIVE reservation - funds HELD, nothing charged, nothing granted - which the operator resumes or cancels once it is stale.
        // Proven here for exactly those leftovers: the state is exactly that, and the operator cancel releases it cleanly.
        $this->assertSame($retry, $this->recoverHeldActivations(), 'L2: one held (FUNDS_RESERVED, reservation ACTIVE) activation per refusal, cancelled cleanly by the operator tool');
        $this->assertClean('L2', $out, $peak);

        // L3: one customer, concurrent RENEW (service lock / action re-derivation): never a double charge
        $c = $this->customer($leaf);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $n = 0;
        [$out, $times, $peak] = $this->waves(function (int $w) use ($plan, $leaf, $c, &$n): array {
            $ops = [];
            for ($i = 0; $i < 6; $i++) {
                $ops[] = ['op' => 'activate', 'actor' => $leaf, 'customer' => $c, 'plan' => $plan, 'action' => 'RENEW', 'nonce' => self::nonce()];
                $n++;
            }
            return $ops;
        });
        $this->report('L3 one customer', $out, $times, $peak, $n);
        $completed = (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = ? AND status = 'COMPLETED'", [$c]);
        $charged = (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_activations a WHERE a.customer_id = ? AND EXISTS (SELECT 1 FROM tbl_manager_wallet_reservations r WHERE r.activation_id = a.id AND r.status = 'COMMITTED')", [$c]);
        $this->assertSame($completed, $charged, 'L3: exactly one charge per completed activation');
        $this->assertSame($completed - 1, count(array_filter($out, static fn(string $o): bool => $o === 'OK:COMPLETED')), 'L3: every reported completion is one charged activation');
        $this->assertClean('L3', $out, $peak);
        self::log('==== load PASS');
    }
}
