<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * V1 plan forms (owner QUOTA_V1_GB_SUPPORT_APPROVED): Hotspot and PPPoE plans can be time, data (GB / MB) or
 * time + data limited. A Limited plan whose limit is zero, negative, non-numeric, of an unknown type or in an unknown
 * unit is refused when saved (nothing stored); a valid one is stored exactly; the stored unit is the unit the RADIUS
 * policy reads (a missing unit: minutes / MB); an Unlimited plan stores no limit.
 */
final class V1PlanQuotaFormTest extends RadiusHttpTestCase
{
    private static int $bw = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'v1pf', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 2, 'rate_up_unit' => 'Mbps']);
    }

    private function hotspot(string $name, array $quota): void
    {
        $r = $this->staff('ad')->postWithToken('services/add-post', $quota + ['name' => $name, 'plan_type' => 'Personal', 'radius' => '1', 'id_bw' => self::$bw, 'price' => '5',
            'sharedusers' => '1', 'validity' => '30', 'validity_unit' => 'Days', 'device' => 'Radius', 'enabled' => '1', 'prepaid' => 'yes'], [], 'services/add');
        $this->assertSame(302, $r->status, $r->excerpt());
    }

    private function pppoe(string $name, array $quota): void
    {
        $r = $this->staff('ad')->postWithToken('services/pppoe-add-post', $quota + ['name_plan' => $name, 'plan_type' => 'Personal', 'radius' => '1', 'id_bw' => self::$bw, 'price' => '7',
            'validity' => '30', 'validity_unit' => 'Days', 'device' => 'Radius', 'pool_name' => 'v1pool', 'enabled' => '1', 'prepaid' => 'yes'], [], 'services/pppoe-add');
        $this->assertSame(302, $r->status, $r->excerpt());
    }

    private function plan(string $name): ?array
    {
        $rows = static::$db->rows('SELECT type, typebp, limit_type, time_limit, time_unit, data_limit, data_unit FROM tbl_plans WHERE name_plan = ?', [$name]);
        return $rows === [] ? null : array_map(static fn($v) => $v === null ? null : (string) $v, $rows[0]);
    }

    public function testInvalidQuotasAreRefused(): void
    {
        $bad = [
            'zero data' => ['typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => '0', 'data_unit' => 'GB'],
            'negative data' => ['typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => '-5', 'data_unit' => 'GB'],
            'text data' => ['typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => '5GB', 'data_unit' => 'GB'],
            'unknown unit' => ['typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => '5', 'data_unit' => 'TB'],
            'zero time' => ['typebp' => 'Limited', 'limit_type' => 'Time_Limit', 'time_limit' => '0', 'time_unit' => 'Hrs'],
            'both, data missing' => ['typebp' => 'Limited', 'limit_type' => 'Both_Limit', 'time_limit' => '10', 'time_unit' => 'Hrs', 'data_limit' => '', 'data_unit' => 'GB'],
            'unknown type' => ['typebp' => 'Limited', 'limit_type' => 'Speed_Limit', 'data_limit' => '5', 'data_unit' => 'GB'],
            'huge' => ['typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => '99999999999', 'data_unit' => 'GB'],
        ];
        foreach ($bad as $why => $q) {
            $tag = substr(md5($why), 0, 6);
            $this->hotspot("v1hs_bad_{$tag}", $q);
            $this->pppoe("v1pp_bad_{$tag}", $q);
            $this->assertNull($this->plan("v1hs_bad_{$tag}"), "Hotspot {$why}: refused");
            $this->assertNull($this->plan("v1pp_bad_{$tag}"), "PPPoE {$why}: refused");
        }
    }

    public function testValidQuotasAreStoredAsTheRadiusPolicyReadsThem(): void
    {
        $this->hotspot('v1hs_gb', ['typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => '5', 'data_unit' => 'GB', 'time_limit' => '', 'time_unit' => 'Hrs']);
        $this->assertSame(['Hotspot', 'Limited', 'Data_Limit', '5', 'GB'], [$this->plan('v1hs_gb')['type'], ...array_values(array_intersect_key($this->plan('v1hs_gb'), array_flip(['typebp', 'limit_type', 'data_limit', 'data_unit'])))]);
        $this->pppoe('v1pp_both', ['typebp' => 'Limited', 'limit_type' => 'Both_Limit', 'time_limit' => '10', 'time_unit' => 'Hrs', 'data_limit' => '3', 'data_unit' => 'GB']);
        $this->assertSame(['type' => 'PPPOE', 'typebp' => 'Limited', 'limit_type' => 'Both_Limit', 'time_limit' => '10', 'time_unit' => 'Hrs', 'data_limit' => '3', 'data_unit' => 'GB'], $this->plan('v1pp_both'));
        // a crafted post without units: stored as the policy reads it (minutes / MB), never silently scaled up to hours / GB
        $this->pppoe('v1pp_nounit', ['typebp' => 'Limited', 'limit_type' => 'Both_Limit', 'time_limit' => '90', 'data_limit' => '500']);
        $this->assertSame(['90', 'Mins', '500', 'MB'], [$this->plan('v1pp_nounit')['time_limit'], $this->plan('v1pp_nounit')['time_unit'], $this->plan('v1pp_nounit')['data_limit'], $this->plan('v1pp_nounit')['data_unit']]);
        // an Unlimited plan stores no limit even when limit fields are posted
        $this->pppoe('v1pp_unl', ['typebp' => 'Unlimited', 'limit_type' => 'Data_Limit', 'data_limit' => '0', 'data_unit' => 'GB']);
        $this->assertSame(['Unlimited', null, '0', '0'], [$this->plan('v1pp_unl')['typebp'], $this->plan('v1pp_unl')['limit_type'], $this->plan('v1pp_unl')['time_limit'], $this->plan('v1pp_unl')['data_limit']]);
    }
}
