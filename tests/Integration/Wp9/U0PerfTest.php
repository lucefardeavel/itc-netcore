<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 §8 (owner): small, local, single-host OBSERVATIONS of the global identity lock - NOT a load or capacity test
 * (WP10). Synthetic customers are bulk-inserted into the disposable database (1 000 / 10 000 / 50 000 rows, plus 10 000
 * used vouchers at the largest size); per size: the time of one availability check, of a voucher-only redemption and
 * of a P1 creation (median of 5); at the largest size: 8 voucher-only redemptions in parallel (wall time, slowest
 * process). Numbers are recorded as evidence; the only assertions are completion and correctness of the outcomes.
 */
final class U0PerfTest extends RadiusHttpTestCase
{
    use U0Fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
    }

    private static function seedCustomers(int $from, int $to): void
    {
        for ($i = $from; $i < $to; $i += 1000) {
            $rows = [];
            for ($j = $i; $j < min($to, $i + 1000); $j++) {
                $rows[] = "('w9u0p{$j}', 'x', '', '', '', '')";
            }
            static::$db->exec('INSERT INTO tbl_customers (username, password, fullname, address, email, phonenumber) VALUES ' . implode(',', $rows));
        }
    }

    private static function median(array $v): float
    {
        sort($v);
        return round($v[intdiv(count($v), 2)], 1);
    }

    /** exec() with the app copy's PHP error log in the failure message (diagnostics only) */
    private static function appRun(string $code): mixed
    {
        try {
            return static::$app->exec($code);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage() . ' | php_error.log: ' . substr((string) @file_get_contents(static::$app->dir . '/wp2_probe/php_error.log'), -1500), 0, $e);
        }
    }

    public function testObservedCostOfTheIdentityLockByCustomerCount(): void
    {
        $out = [];
        $have = 0;
        foreach ([1000, 10000, 50000] as $n) {
            self::seedCustomers($have, $n);
            $have = $n;
            if ($n === 50000) {
                $rows = [];
                for ($j = 0; $j < 10000; $j++) {
                    $rows[] = "('Hotspot', 'radius', " . self::$hotspot . ", 'w9u0used{$j}', '0', '1', 1)";
                    if (count($rows) === 1000) {
                        static::$db->exec('INSERT INTO tbl_voucher (type, routers, id_plan, code, user, status, generated_by) VALUES ' . implode(',', $rows));
                        $rows = [];
                    }
                }
            }
            $check = $redeem = $create = [];
            for ($k = 0; $k < 5; $k++) {
                $check[] = (float) self::appRun(sprintf('$w9t0 = microtime(true); $r = \Reseller\Domain\CustomerIdentity::conflicts(ORM::get_db(), [%s]); $result = $r === [] ? (microtime(true) - $w9t0) * 1000 : -1;', var_export(self::tag(), true)));
                $code = self::tag();
                $this->voucher($code);
                $redeem[] = (float) self::appRun(sprintf('$w9t0 = microtime(true); $s = \Reseller\Domain\VoucherRedemptionService::redeem(%s, \Reseller\Domain\RedemptionTarget::voucherOnly(%s), \Reseller\Domain\RedemptionSource::public())["status"]; $result = $s === "GRANTED" ? (microtime(true) - $w9t0) * 1000 : -1;', var_export($code, true), var_export($code, true)));
                $create[] = (float) self::appRun(sprintf('$u = ORM::for_table("tbl_users")->where("username", "ad")->find_one()->as_array(); $w9t0 = microtime(true); \Reseller\Domain\CustomerService::create(\Reseller\Security\ActorContext::forStaff($u, "WEB"), ["username" => %s, "password" => "Pw-perf", "fullname" => "W9 U0 perf"]); $result = (microtime(true) - $w9t0) * 1000;', var_export(self::tag(), true)));
            }
            $this->assertNotContains(-1.0, array_merge($check, $redeem), 'every measured operation succeeded');
            $out["customers={$n}"] = ['availability_check_ms' => self::median($check), 'voucher_only_redeem_ms' => self::median($redeem), 'p1_create_ms' => self::median($create)];
        }
        // contention at the largest size: 8 voucher-only redemptions at once (distinct codes)
        $hs = [];
        for ($k = 0; $k < 8; $k++) {
            $code = self::tag();
            $this->voucher($code);
            $hs[] = static::$app->execStart(sprintf('$w9t0 = microtime(true); $s = \Reseller\Domain\VoucherRedemptionService::redeem(%s, \Reseller\Domain\RedemptionTarget::voucherOnly(%s), \Reseller\Domain\RedemptionSource::public())["status"]; $result = [$s, round((microtime(true) - $w9t0) * 1000, 1)];', var_export($code, true), var_export($code, true)));
        }
        $t = microtime(true);
        $par = array_map(static fn(array $h): array => static::$app->execFinish($h), $hs);
        $wall = round((microtime(true) - $t) * 1000, 1);
        $this->assertSame(array_fill(0, 8, 'GRANTED'), array_column($par, 0), 'all parallel redemptions granted');
        $out['parallel_8_voucher_only_redemptions_at_50000'] = ['wall_ms' => $wall, 'slowest_process_ms' => max(array_column($par, 1)), 'per_process_ms' => array_column($par, 1)];
        self::record('U0 perf observation (not a load test)', 'OBSERVED', ['engine_version' => static::$db->value('SELECT VERSION()'), 'results' => $out]);
    }
}
