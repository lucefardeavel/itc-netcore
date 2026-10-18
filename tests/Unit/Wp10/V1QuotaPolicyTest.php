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
 *  - a data quota asks the NAS for 30 s interim accounting (the enforcer's input);
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
        $this->assertSame((string) (1790000000 - QuotaPolicy::PERIOD_SKEW_SECONDS), $gb->checkMap()['Nuxr-Period-Start'], 'period start minus the NAS clock allowance');
        $this->assertSame('1', $gb->checkMap()['Simultaneous-Use']);
        $this->assertSame('30', $gb->replyMap()['Acct-Interim-Interval'], 'data quota: 30 s interim accounting (N1 = A + G)');
        $this->assertSame('5M/20M', $gb->replyMap()['Mikrotik-Rate-Limit']);

        $time = RadiusPolicyBuilder::build(self::CUST, self::plan('PPPOE', ['limit_type' => 'Time_Limit', 'time_limit' => 10, 'time_unit' => 'Hrs']), self::BW, $expiry, $start);
        $this->assertSame('36000', $time->checkMap()['Max-All-Session'], 'PPPoE time quota');
        $this->assertArrayNotHasKey('Max-Data', $time->checkMap());
        $this->assertArrayNotHasKey('Acct-Interim-Interval', $time->replyMap(), 'a time quota is enforced by Session-Timeout alone');

        $both = RadiusPolicyBuilder::build(self::CUST, self::plan('PPPOE', ['limit_type' => 'Both_Limit', 'time_limit' => 90, 'time_unit' => 'Mins', 'data_limit' => 500, 'data_unit' => 'MB']), self::BW, $expiry, $start);
        $this->assertSame(['5400', '500000000'], [$both->checkMap()['Max-All-Session'], $both->checkMap()['Max-Data']], 'composite: time + data + speed + validity');
        $this->assertSame('30', $both->replyMap()['Acct-Interim-Interval']);
    }

    /**
     * Measured on the lab RouterOS CHR 7.24.5: the NAS stamps Accounting-Start 1.1 - 1.7 s EARLIER than the host receives
     * it (integer-second Event-Timestamp + NAS clock lag). Sessions are attributed by acctstarttime >= Nuxr-Period-Start,
     * so a login within ~2 s of the period start (the PPPoE client redials right after the renewal Disconnect) was booked
     * to the PREVIOUS period and its traffic escaped the new quota. The stored boundary is therefore the period start
     * minus PERIOD_SKEW_SECONDS (one write; the counters, the FreeRADIUS share and the enforcer all read it).
     */
    public function testAttributionBoundaryAllowsForNasClockSkew(): void
    {
        $this->assertGreaterThanOrEqual(5, QuotaPolicy::PERIOD_SKEW_SECONDS, 'covers the measured 1.7 s with margin');
        $this->assertLessThanOrEqual(30, QuotaPolicy::PERIOD_SKEW_SECONDS, 'an over-count is bounded by this many seconds of one session');
        $plan = self::plan('PPPOE', ['limit_type' => 'Data_Limit', 'data_limit' => 5, 'data_unit' => 'GB']);
        $p = RadiusPolicyBuilder::build(self::CUST, $plan, self::BW, new \DateTimeImmutable('2027-01-31 23:59:59'), new \DateTimeImmutable('@1790000000'));
        $this->assertSame((string) (1790000000 - QuotaPolicy::PERIOD_SKEW_SECONDS), $p->checkMap()['Nuxr-Period-Start']);
        $this->assertSame('0', RadiusPolicyBuilder::build(self::CUST, $plan, self::BW, null, new \DateTimeImmutable('@3'))->checkMap()['Nuxr-Period-Start'], 'never negative');
        $this->assertSame('0', RadiusPolicyBuilder::build(self::CUST, $plan, self::BW, null, null)->checkMap()['Nuxr-Period-Start'], 'unknown start: since the beginning');
    }

    /** N2: the builder stores the arrival-order boundary of the period, only for a quota plan with a known period start */
    public function testBuilderStoresTheArrivalOrderBoundaryOfThePeriod(): void
    {
        $calls = [];
        $resolver = static function (string $user, int $timeBoundary) use (&$calls): ?int {
            $calls[] = [$user, $timeBoundary];
            return 4711;
        };
        $plan = self::plan('PPPOE', ['limit_type' => 'Data_Limit', 'data_limit' => 5, 'data_unit' => 'GB']);
        $expiry = new \DateTimeImmutable('2027-01-31 23:59:59');
        $p = RadiusPolicyBuilder::build(self::CUST, $plan, self::BW, $expiry, new \DateTimeImmutable('@1790000000'), $resolver);
        $this->assertSame('4711', $p->checkMap()['Nuxr-Period-Acct-Id']);
        $this->assertSame([['ppp-q1', 1790000000 - QuotaPolicy::PERIOD_SKEW_SECONDS]], $calls, 'asked for the RADIUS key and the time boundary of the period');
        // the resolver keeps an unchanged period as it is (or has no boundary for it): no attribute
        $none = RadiusPolicyBuilder::build(self::CUST, $plan, self::BW, $expiry, new \DateTimeImmutable('@1790000000'), static fn(string $u, int $t): ?int => null);
        $this->assertArrayNotHasKey('Nuxr-Period-Acct-Id', $none->checkMap());
        // 0 is a valid boundary (an empty table: every row is new)
        $zero = RadiusPolicyBuilder::build(self::CUST, $plan, self::BW, $expiry, new \DateTimeImmutable('@1790000000'), static fn(string $u, int $t): ?int => 0);
        $this->assertSame('0', $zero->checkMap()['Nuxr-Period-Acct-Id']);
        // unknown period start, unlimited plan: the resolver is never asked
        $calls = [];
        RadiusPolicyBuilder::build(self::CUST, $plan, self::BW, $expiry, null, $resolver);
        RadiusPolicyBuilder::build(self::CUST, ['typebp' => 'Unlimited'] + $plan, self::BW, $expiry, new \DateTimeImmutable('@1790000000'), $resolver);
        $this->assertSame([], $calls);
        $this->assertContains('Nuxr-Period-Acct-Id', \Reseller\Radius\RadiusPolicy::MANAGED_CHECK, 'managed: replaced and removed with the rest of the policy');
    }

    /** N1: the interim interval (the enforcer's input) is configurable within 10 - 300 s; anything else keeps the default 30 s */
    public function testInterimIntervalIsConfigurableWithinBounds(): void
    {
        $plan = self::plan('PPPOE', ['limit_type' => 'Data_Limit', 'data_limit' => 5, 'data_unit' => 'GB']);
        $interim = static fn(): string => RadiusPolicyBuilder::build(self::CUST, $plan, self::BW, null, null)->replyMap()['Acct-Interim-Interval'];
        try {
            unset($GLOBALS['config']['nuxr_quota_interim_seconds']);
            $this->assertSame('30', $interim(), 'default');
            foreach ([['60', '60'], [15, '15'], ['10', '10'], ['300', '300'], [9, '30'], [301, '30'], ['x', '30'], ['', '30'], [-5, '30'], ['30.5', '30']] as [$configured, $expected]) {
                $GLOBALS['config']['nuxr_quota_interim_seconds'] = $configured;
                $this->assertSame($expected, $interim(), 'configured ' . var_export($configured, true));
            }
        } finally {
            unset($GLOBALS['config']['nuxr_quota_interim_seconds']);
        }
    }

    public function testHotspotDataPlanAsksForInterimAccounting(): void
    {
        $p = RadiusPolicyBuilder::build(self::CUST, self::plan('Hotspot', ['limit_type' => 'Data_Limit', 'data_limit' => 2, 'data_unit' => 'GB', 'shared_users' => 2]), self::BW, null, new \DateTimeImmutable('@1790000000'));
        $this->assertSame('2000000000', $p->checkMap()['Max-Data']);
        $this->assertSame('30', $p->replyMap()['Acct-Interim-Interval']);
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
