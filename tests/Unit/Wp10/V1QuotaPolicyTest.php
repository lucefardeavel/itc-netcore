<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\QuotaPolicy;
use Reseller\Radius\RadiusPolicyBuilder;

/**
 * V1 quota contract (owner QUOTA_V1_GB_SUPPORT_APPROVED), policy level:
 *  - PPPoE plans support the same Limited quotas as Hotspot plans (time, data in MB / GB, both);
 *  - a data quota asks the NAS for 60 s interim accounting (the enforcer's input);
 *  - Unlimited plans carry no quota attribute;
 *  - a Limited plan with a zero, negative or non-numeric limit is refused (fail closed: never sold as unlimited,
 *    never provisioned as a 0 quota).
 */
final class V1QuotaPolicyTest extends TestCase
{
    private const BW = ['rate_up' => '5', 'rate_up_unit' => 'Mbps', 'rate_down' => '20', 'rate_down_unit' => 'Mbps', 'burst' => ''];
    private const CUST = ['username' => 'q1', 'password' => 'p', 'pppoe_username' => 'ppp-q1', 'pppoe_password' => 'pp'];

    private static function plan(string $type, array $limits): array
    {
        return $limits + ['id' => 41, 'type' => $type, 'shared_users' => 1, 'pool' => 'pool-q', 'typebp' => 'Limited'];
    }

    public function testPppoeDataTimeAndCompositePlans(): void
    {
        $start = new \DateTimeImmutable('@1790000000');
        $expiry = new \DateTimeImmutable('2027-01-31 23:59:59');
        $gb = RadiusPolicyBuilder::build(self::CUST, self::plan('PPPOE', ['limit_type' => 'Data_Limit', 'data_limit' => 50, 'data_unit' => 'GB']), self::BW, $expiry, $start);
        $this->assertSame('ppp-q1', $gb->username);
        $this->assertSame('50000000000', $gb->checkMap()['Max-Data'], 'PPPoE GB plan: 50 GB (decimal) per period');
        $this->assertSame('1790000000', $gb->checkMap()['Nuxr-Period-Start']);
        $this->assertSame('1', $gb->checkMap()['Simultaneous-Use']);
        $this->assertSame('60', $gb->replyMap()['Acct-Interim-Interval'], 'data quota: 60 s interim accounting');
        $this->assertSame('5M/20M', $gb->replyMap()['Mikrotik-Rate-Limit']);

        $time = RadiusPolicyBuilder::build(self::CUST, self::plan('PPPOE', ['limit_type' => 'Time_Limit', 'time_limit' => 10, 'time_unit' => 'Hrs']), self::BW, $expiry, $start);
        $this->assertSame('36000', $time->checkMap()['Max-All-Session'], 'PPPoE time quota');
        $this->assertArrayNotHasKey('Max-Data', $time->checkMap());
        $this->assertArrayNotHasKey('Acct-Interim-Interval', $time->replyMap(), 'a time quota is enforced by Session-Timeout alone');

        $both = RadiusPolicyBuilder::build(self::CUST, self::plan('PPPOE', ['limit_type' => 'Both_Limit', 'time_limit' => 90, 'time_unit' => 'Mins', 'data_limit' => 500, 'data_unit' => 'MB']), self::BW, $expiry, $start);
        $this->assertSame(['5400', '500000000'], [$both->checkMap()['Max-All-Session'], $both->checkMap()['Max-Data']], 'composite: time + data + speed + validity');
        $this->assertSame('60', $both->replyMap()['Acct-Interim-Interval']);
    }

    public function testHotspotDataPlanAsksForInterimAccounting(): void
    {
        $p = RadiusPolicyBuilder::build(self::CUST, self::plan('Hotspot', ['limit_type' => 'Data_Limit', 'data_limit' => 2, 'data_unit' => 'GB', 'shared_users' => 2]), self::BW, null, new \DateTimeImmutable('@1790000000'));
        $this->assertSame('2000000000', $p->checkMap()['Max-Data']);
        $this->assertSame('60', $p->replyMap()['Acct-Interim-Interval']);
        $this->assertSame('2', $p->checkMap()['Simultaneous-Use']);
    }

    public function testUnlimitedPlansCarryNoQuota(): void
    {
        foreach (['PPPOE', 'Hotspot'] as $type) {
            $p = RadiusPolicyBuilder::build(self::CUST, ['id' => 42, 'type' => $type, 'typebp' => 'Unlimited', 'limit_type' => 'Data_Limit', 'data_limit' => 5, 'data_unit' => 'GB', 'pool' => 'x'], self::BW, null);
            foreach (['Max-Data', 'Max-All-Session', 'Nuxr-Period-Start'] as $a) {
                $this->assertArrayNotHasKey($a, $p->checkMap(), "{$type} unlimited: no {$a}");
            }
            $this->assertArrayNotHasKey('Acct-Interim-Interval', $p->replyMap());
        }
    }

    public function testInvalidLimitsAreRefused(): void
    {
        $bad = [
            'data 0' => ['limit_type' => 'Data_Limit', 'data_limit' => 0, 'data_unit' => 'GB'],
            'data negative' => ['limit_type' => 'Data_Limit', 'data_limit' => -5, 'data_unit' => 'GB'],
            'data text' => ['limit_type' => 'Data_Limit', 'data_limit' => '5abc', 'data_unit' => 'GB'],
            'data unit' => ['limit_type' => 'Data_Limit', 'data_limit' => 5, 'data_unit' => 'TB'],
            'time 0' => ['limit_type' => 'Time_Limit', 'time_limit' => 0, 'time_unit' => 'Hrs'],
            'both, data 0' => ['limit_type' => 'Both_Limit', 'time_limit' => 5, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB'],
            'unknown limit type' => ['limit_type' => 'Speed_Limit'],
        ];
        foreach ($bad as $label => $limits) {
            foreach (['PPPOE', 'Hotspot'] as $type) {
                $plan = self::plan($type, $limits);
                $this->assertNotNull(QuotaPolicy::problem($plan), "{$type} {$label}: refused by validation");
                try {
                    RadiusPolicyBuilder::build(self::CUST, $plan, self::BW, null, new \DateTimeImmutable('@1790000000'));
                    $this->fail("{$type} {$label}: provisioned");
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame('POLICY_QUOTA_INVALID', $e->getMessage(), "{$type} {$label}");
                }
            }
        }
        $this->assertNull(QuotaPolicy::problem(self::plan('PPPOE', ['limit_type' => 'Data_Limit', 'data_limit' => '50', 'data_unit' => 'GB'])), 'positive control');
        $this->assertNull(QuotaPolicy::problem(['typebp' => 'Unlimited', 'limit_type' => 'Data_Limit', 'data_limit' => 0]), 'Unlimited ignores the limit fields');
        $this->assertSame(50_000_000_000, QuotaPolicy::bytes(['data_limit' => 50, 'data_unit' => 'GB']));
    }
}
