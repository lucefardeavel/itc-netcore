<?php
namespace Reseller\Tests\Lab;

// WP10 STAGING LOAD TEST (test tooling only). Not part of any PHPUnit suite: run explicitly by dev/wp10/load.sh on the
// disposable databases of the isolated nuxbill-reseller-test compose project. Never against a production service.
// Bounded by design: at most MAX_CONCURRENCY worker processes (one database connection each) at any moment, far below
// the server's max_connections; the peak connection count is measured and asserted. It is NOT a connection- or
// pool-exhaustion test and never tries to reach a limit (the forbidden SQL-exhaustion reproduction is never run).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

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

    private function assertClean(string $name, array $outcomes, int $peak): void
    {
        $this->assertSame([], array_values(array_filter($outcomes, static fn(string $o): bool => $o === 'REFUSED:ERROR')), "{$name}: no unexpected error");
        $this->assertSame(0, (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_activations WHERE status NOT IN ('COMPLETED','FAILED','CANCELLED','ROLLED_BACK','REVERSED')"), "{$name}: every activation is terminal");
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
        $this->assertSame($n, count(array_filter($out, static fn(string $o): bool => $o === 'OK:COMPLETED')), 'L2: every activation completes (serialized on the wallet, none lost)');
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
