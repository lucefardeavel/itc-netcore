<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * V1 (owner QUOTA_V1_GB_SUPPORT_APPROVED): voucher redemption of a GB plan. The voucher-only grant provisions the same
 * quota policy as a sale (Max-Data in decimal bytes, the attribution boundary, 60 s interim accounting, Simultaneous-Use);
 * a voucher whose plan has an invalid quota (zero / unusable) is NOT granted: nothing is written to RADIUS and the voucher
 * is held CLAIMED_REVIEW for the operator (the established device-failure contract, runbook 2) - never sold as unlimited,
 * never provisioned as a 0 quota.
 */
final class V1VoucherQuotaTest extends RadiusHttpTestCase
{
    private static int $goodPlan = 0;
    private static int $badPlan = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'v1vq', 'rate_down' => 2, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        $plan = static fn(string $name, int $gb): int => static::$db->insertRow('tbl_plans', ['name_plan' => $name, 'id_bw' => $bw, 'price' => '5', 'type' => 'Hotspot',
            'typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => $gb, 'data_unit' => 'GB', 'validity' => 30,
            'validity_unit' => 'Days', 'shared_users' => 1, 'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes',
            'plan_type' => 'Personal', 'device' => 'Radius']);
        self::$goodPlan = $plan('v1vq_good_2gb', 2);
        self::$badPlan = $plan('v1vq_bad_zero', 0);
    }

    private function voucher(string $code, int $plan): int
    {
        return static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => 'radius', 'id_plan' => $plan, 'code' => $code, 'user' => '0', 'status' => '0',
            'generated_by' => $this->sid('ad')]);
    }

    private function redeem(string $code): string
    {
        return (string) static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, \Reseller\Domain\RedemptionTarget::voucherOnly(%s), \Reseller\Domain\RedemptionSource::public())["status"];',
            var_export($code, true), var_export($code, true)));
    }

    /** @return array<string,string> */
    private function rows(string $table, string $user): array
    {
        $out = [];
        foreach (static::$db->rows("SELECT attribute, value FROM {$table} WHERE BINARY username = ?", [$user]) as $r) {
            $out[$r['attribute']] = (string) $r['value'];
        }
        return $out;
    }

    public function testGbVoucherProvisionsTheQuotaPolicy(): void
    {
        $code = 'v1vg' . bin2hex(random_bytes(4));
        $this->voucher($code, self::$goodPlan);
        $before = time();
        $this->assertSame('GRANTED', $this->redeem($code));
        $check = $this->rows('radcheck', $code);
        $this->assertSame('2000000000', $check['Max-Data'] ?? null, '2 GB = 2 x 10^9 bytes');
        $this->assertSame('1', $check['Simultaneous-Use'] ?? null);
        $boundary = (int) ($check['Nuxr-Period-Start'] ?? 0);
        $this->assertGreaterThanOrEqual($before - 10 - 2, $boundary, 'the period start minus the NAS clock allowance (10 s)');
        $this->assertLessThanOrEqual(time() - 10 + 2, $boundary);
        $this->assertSame('60', $this->rows('radreply', $code)['Acct-Interim-Interval'] ?? null, 'a data quota asks for 60 s interim accounting');
        $this->assertArrayNotHasKey('Max-All-Session', $check, 'a data-only plan has no time quota');
    }

    public function testVoucherOfAnInvalidQuotaPlanIsNotGrantedAndWritesNothing(): void
    {
        $code = 'v1vb' . bin2hex(random_bytes(4));
        $vid = $this->voucher($code, self::$badPlan);
        $status = $this->redeem($code);
        $this->assertNotSame('GRANTED', $status, 'a zero GB quota is refused (fail closed)');
        $this->assertSame('CLAIMED_REVIEW', $status, 'a failed device grant is held for the operator, never silently released (runbook 2)');
        $this->assertSame([], $this->rows('radcheck', $code), 'nothing written to RADIUS');
        $this->assertSame([], $this->rows('radreply', $code));
        $this->assertSame('CLAIMED', (string) static::$db->value('SELECT status FROM tbl_voucher WHERE id = ?', [$vid]),
            'the voucher is not redeemable again until the operator decides (voucher-resolve ... release: nothing of the grant exists)');
    }
}
