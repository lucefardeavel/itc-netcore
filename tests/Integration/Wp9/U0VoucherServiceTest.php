<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 final hardening, O-U0-1 "voucher granted without service": Package::rechargeUser (pinned) catches every device
 * failure, records it and still writes the invoice; the voucher-only redemption then reported GRANTED (voucher consumed)
 * although the RADIUS login policy of the code was never written. Two current triggers without any fault injection:
 *  (a) a code the RADIUS key rules refuse (an interior control character);
 *  (b) a direct-DB / plugin insert of a customer with the code's name during the grant (the device ownership guard then
 *      refuses the write - the bypass the application lock cannot see).
 * Contract after the fix: the outcome is CLAIMED_REVIEW, never GRANTED - the voucher stays CLAIMED (never redeemable
 * again, no second grant), it is listed for operator review, nothing is written to RADIUS for the code. Controls: a
 * normal grant is GRANTED with its policy; replay of a granted voucher is NOT_VALID.
 */
final class U0VoucherServiceTest extends RadiusHttpTestCase
{
    use U0Fixture;

    private const BYPASS_PLUGIN = <<<'PHP'
<?php
// test-only (disposable app copy): during the voucher grant (hook recharge_user, before the device) and only while the flag
// exists, a DIRECT insert of a customer with the code's name (a plugin / direct-DB writer outside the application lock)
register_hook('recharge_user', 'wp9u0_bypass_insert');
function wp9u0_bypass_insert() {
    $flag = __DIR__ . '/../../wp2_probe/u0_bypass.json';
    if (!is_file($flag)) { return; }
    $j = json_decode((string) file_get_contents($flag), true);
    unlink($flag);
    // every NOT NULL column (a failing hook is swallowed by run_hook - N-02 - so the insert must be complete)
    ORM::get_db()->prepare("INSERT INTO tbl_customers (username, password, pppoe_username, pppoe_password, pppoe_ip, fullname, address, phonenumber, email, service_type, account_type, coordinates, status, balance, created_by, auto_renewal) VALUES (?, 'x', '', '', '', 'W9 bypass', '', '0700', 'w9u0@example.invalid', 'Hotspot', 'Personal', '', 'Active', 0, 1, 0)")->execute([$j['code']]);
    @file_put_contents(__DIR__ . '/../../wp2_probe/u0_bypass.done', 'inserted');
}
PHP;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
        // run_hook() calls only the FIRST registered function of a hook (system/autoload/Hookers.php), so the U0 barrier
        // plugin (also on recharge_user) is removed from this app copy; the bypass plugin is the only recharge_user hook
        unlink(static::$app->dir . '/system/plugin/wp9u0_barrier.php');
        file_put_contents(static::$app->dir . '/system/plugin/wp9u0_bypass.php', self::BYPASS_PLUGIN);
    }

    private function redeemCase(string $case, string $code, ?callable $during = null): array
    {
        $vid = $this->voucher($code);
        $tx0 = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions');
        if ($during !== null) {
            $during($code);
        }
        $status = (string) static::$app->exec(self::p6Code($code));
        $replay = (string) static::$app->exec(self::p6Code($code));
        $obs = ['status' => $status, 'voucher_status' => self::vstatus($vid), 'radius_rows_for_code' => count(self::exact(trim($code))),
            'replay' => $replay, 'transactions_added' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions') - $tx0,
            'drift_lists_voucher' => (bool) array_filter((array) static::$app->exec('$result = \Reseller\Activation\DriftReport::collect(false);'),
                static fn($r): bool => is_array($r) && ($r['kind'] ?? '') === 'VOUCHER_CLAIMED' && (int) ($r['voucher_id'] ?? 0) === $vid)];
        $obs['customers_with_code'] = count(self::holders(trim($code)));
        $obs['bypass_insert_ran'] = is_file(static::$app->dir . '/wp2_probe/u0_bypass.done');
        @unlink(static::$app->dir . '/wp2_probe/u0_bypass.done');
        $log = (string) @file_get_contents(static::$app->dir . '/wp2_probe/php_error.log');
        $obs['log_identity_ambiguous'] = substr_count($log, 'identity.ambiguous');
        $obs['log_device_identity_refused'] = substr_count($log, 'device.identity_refused');
        $phantom = $status === 'GRANTED' && $obs['radius_rows_for_code'] === 0;
        self::record("U0 O-U0-1 {$case}", $phantom ? 'DEFECT_REPRODUCED' : 'OBSERVED', $obs);
        return $obs;
    }

    public function testACodeTheRadiusKeyRulesRefuseIsNeverReportedGranted(): void
    {
        $obs = $this->redeemCase('device refused: interior control character', self::tag() . "\x01z");
        $this->assertSame('CLAIMED_REVIEW', $obs['status'], 'no GRANTED without a RADIUS policy ' . json_encode($obs));
        $this->assertSame('CLAIMED', $obs['voucher_status'], 'never redeemable again, operator review');
        $this->assertSame(0, $obs['radius_rows_for_code']);
        $this->assertSame('NOT_VALID', $obs['replay'], 'no second grant');
        $this->assertTrue($obs['drift_lists_voucher'], 'listed for operator review');
    }

    public function testABypassInsertDuringTheGrantIsNeverReportedGranted(): void
    {
        $obs = $this->redeemCase('device refused: direct-DB customer inserted during the grant', self::tag(),
            static fn(string $code) => file_put_contents(static::$app->dir . '/wp2_probe/u0_bypass.json', json_encode(['code' => $code])));
        $this->assertTrue($obs['bypass_insert_ran'], 'control: the direct-DB insert ran during the grant');
        $this->assertSame('CLAIMED_REVIEW', $obs['status'], json_encode($obs));
        $this->assertSame('CLAIMED', $obs['voucher_status']);
        $this->assertSame(0, $obs['radius_rows_for_code'], 'nothing written for the ambiguous key');
        $this->assertSame('NOT_VALID', $obs['replay']);
    }

    public function testANormalGrantIsUnchanged(): void
    {
        $obs = $this->redeemCase('control: normal grant', self::tag());
        $this->assertSame('GRANTED', $obs['status']);
        $this->assertSame('1', $obs['voucher_status']);
        $this->assertGreaterThan(0, $obs['radius_rows_for_code']);
        $this->assertSame('NOT_VALID', $obs['replay']);
        $this->assertSame(1, $obs['transactions_added']);
        $this->assertFalse($obs['drift_lists_voucher']);
    }
}
