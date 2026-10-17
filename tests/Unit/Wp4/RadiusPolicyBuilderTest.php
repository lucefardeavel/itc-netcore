<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\RadiusPolicy;
use Reseller\Radius\RadiusPolicyBuilder;

/** The exact RADIUS policy per plan type (06_RADIUS_CONTRACT; owner decisions Q2, Q5, Q7). */
final class RadiusPolicyBuilderTest extends TestCase
{
    private const BW = ['rate_up' => '2', 'rate_up_unit' => 'Mbps', 'rate_down' => '8', 'rate_down_unit' => 'Mbps', 'burst' => ''];

    private static function expiry(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-01-31 23:59:59', new \DateTimeZone('Asia/Baghdad'));
    }

    public function testPppoeStaticAddressPolicy(): void
    {
        $p = RadiusPolicyBuilder::build(
            ['username' => 'acct1', 'password' => 'web-pass', 'pppoe_username' => 'ppp1', 'pppoe_password' => 'ppp-pass', 'pppoe_ip' => '10.1.2.3'],
            ['id' => 7, 'type' => 'PPPOE', 'shared_users' => 5, 'pool' => 'pool-a'], self::BW, self::expiry()
        );
        $this->assertSame('ppp1', $p->username, 'Q7: PPPoE key is pppoe_username');
        $this->assertSame(['Cleartext-Password' => 'ppp-pass', 'Expiration' => '31 Jan 2027 23:59:59', 'Simultaneous-Use' => '1'], $p->checkMap(), 'PPPoE: one session, PPPoE password, billing expiry');
        $this->assertSame(['Framed-IP-Address' => '10.1.2.3', 'Mikrotik-Rate-Limit' => '2M/8M'], $p->replyMap(), 'static address wins over the pool; reply list');
        $this->assertSame('plan_7', $p->group);
        foreach ($p->check as [, $op]) {
            $this->assertSame(':=', $op);
        }
    }

    public function testPppoeDynamicPoolAndFallbacks(): void
    {
        $p = RadiusPolicyBuilder::build(['username' => 'acct2', 'password' => 'web-pass', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '0.0.0.0'],
            ['id' => 8, 'type' => 'PPPOE', 'pool' => ' pool-b '], self::BW, self::expiry());
        $this->assertSame('acct2', $p->username, 'no pppoe_username: the account username');
        $this->assertSame('web-pass', $p->checkMap()['Cleartext-Password'], 'no PPPoE password: the account password');
        $this->assertSame(['Framed-Pool' => 'pool-b', 'Mikrotik-Rate-Limit' => '2M/8M'], $p->replyMap(), '0.0.0.0 is never written; pool trimmed');
        $none = RadiusPolicyBuilder::build(['username' => 'acct3', 'password' => 'x', 'pppoe_ip' => 'not-an-ip'], ['id' => 9, 'type' => 'PPPOE', 'pool' => ''], null, null);
        $this->assertSame([], $none->replyMap(), 'no pool, no valid address, no rate: no reply attributes');
        $this->assertArrayNotHasKey('Expiration', $none->checkMap(), 'no expiry: no Expiration');
    }

    public function testHotspotSharedUsersRateBurstAndTerminateTime(): void
    {
        $bw = ['rate_up' => '512', 'rate_up_unit' => 'Kbps', 'rate_down' => '2', 'rate_down_unit' => 'Mbps', 'burst' => '1M/4M 384K/1500K 16/16 8 256K/1M'];
        $p = RadiusPolicyBuilder::build(['username' => 'hs1', 'password' => 'p', 'pppoe_username' => 'ignored'], ['id' => 3, 'type' => 'Hotspot', 'shared_users' => 3, 'typebp' => 'Unlimited'], $bw, self::expiry());
        $this->assertSame('hs1', $p->username, 'Hotspot ignores pppoe_username');
        $this->assertSame('3', $p->checkMap()['Simultaneous-Use']);
        $this->assertSame('512K/2M 1M/4M 384K/1500K 16/16 8 256K/1M', $p->replyMap()['Mikrotik-Rate-Limit']);
        $this->assertSame('2027-01-31T23:59:59+03:00', $p->replyMap()['WISPr-Session-Terminate-Time']);
        $this->assertArrayNotHasKey('Max-All-Session', $p->checkMap(), 'unlimited: no quota');
        $this->assertSame('3', $p->replyMap()['Port-Limit'], 'WP8 A1: Hotspot Port-Limit = Simultaneous-Use, in the REPLY list');
        $this->assertArrayNotHasKey('Port-Limit', $p->checkMap(), 'never in the control list (the legacy placement never reached the NAS)');
        $bad = RadiusPolicyBuilder::build(['username' => 'hs2', 'password' => 'p'], ['id' => 3, 'type' => 'Hotspot'], ['burst' => "1M'; DROP"] + $bw, null);
        $this->assertSame('512K/2M', $bad->replyMap()['Mikrotik-Rate-Limit'], 'a malformed burst tail is dropped');
        $this->assertSame('1', $bad->checkMap()['Simultaneous-Use'], 'shared_users defaults to 1');
        $this->assertSame('1', $bad->replyMap()['Port-Limit'], 'Port-Limit follows the same max(1, shared_users)');
        $this->assertSame('1', RadiusPolicyBuilder::build(['username' => 'hs3', 'password' => 'p'], ['id' => 3, 'type' => 'Hotspot', 'shared_users' => 0], null, null)->replyMap()['Port-Limit'], 'never 0, even without rate or expiry');
    }

    /** WP8 A1 / OD-W8-16: RouterOS ignores Port-Limit for PPPoE, so PPPoE (and VPN) never get one. */
    public function testPortLimitIsHotspotOnly(): void
    {
        foreach (['PPPOE', 'VPN'] as $type) {
            $p = RadiusPolicyBuilder::build(['username' => 'u', 'password' => 'p', 'pppoe_ip' => '10.0.0.9'], ['id' => 5, 'type' => $type, 'shared_users' => 4, 'pool' => 'x'], self::BW, self::expiry());
            $this->assertArrayNotHasKey('Port-Limit', $p->replyMap(), $type);
            $this->assertArrayNotHasKey('Port-Limit', $p->checkMap(), $type);
        }
    }

    public function testHotspotQuotasAreNotOverwrittenByTheExpiry(): void
    {
        $start = new \DateTimeImmutable('2027-01-01 00:00:00', new \DateTimeZone('UTC'));
        $time = RadiusPolicyBuilder::build(['username' => 'q1', 'password' => 'p'],
            ['id' => 4, 'type' => 'Hotspot', 'typebp' => 'Limited', 'limit_type' => 'Time_Limit', 'time_limit' => 2, 'time_unit' => 'Hrs'], null, self::expiry(), $start);
        $this->assertSame('7200', $time->checkMap()['Max-All-Session'], 'the time quota (the legacy device replaced it by the seconds to expiry)');
        // V1 (2026-10-17, measured NAS Event-Timestamp lag of 1.1 - 1.7 s on RouterOS CHR 7.24.5): the stored attribution boundary
        // is the period start minus the NAS clock allowance; the contract that the counters read the stored value is unchanged
        $this->assertSame((string) ($start->getTimestamp() - \Reseller\Radius\QuotaPolicy::PERIOD_SKEW_SECONDS), $time->checkMap()['Nuxr-Period-Start']);
        $data = RadiusPolicyBuilder::build(['username' => 'q2', 'password' => 'p'],
            ['id' => 4, 'type' => 'Hotspot', 'typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => 3, 'data_unit' => 'GB'], null, null);
        $this->assertSame('3000000000', $data->checkMap()['Max-Data']);
        $this->assertSame('0', $data->checkMap()['Nuxr-Period-Start'], 'unknown period start: since the beginning');
        $both = RadiusPolicyBuilder::build(['username' => 'q3', 'password' => 'p'],
            ['id' => 4, 'type' => 'Hotspot', 'typebp' => 'Limited', 'limit_type' => 'Both_Limit', 'time_limit' => 30, 'time_unit' => 'Mins', 'data_limit' => 500, 'data_unit' => 'MB'], null, null);
        $this->assertSame(['1800', '500000000'], [$both->checkMap()['Max-All-Session'], $both->checkMap()['Max-Data']]);
        $pppoe = RadiusPolicyBuilder::build(['username' => 'q4', 'password' => 'p'], ['id' => 4, 'type' => 'PPPOE', 'typebp' => 'Limited', 'limit_type' => 'Time_Limit', 'time_limit' => 1], null, null);
        // owner decision QUOTA_V1_GB_SUPPORT_APPROVED (2026-10-17) replaced the WP4 "quotas are Hotspot-only" contract:
        // PPPoE Limited plans carry their quota too (V1QuotaPolicyTest)
        $this->assertSame('60', $pppoe->checkMap()['Max-All-Session'], 'V1: PPPoE time quota (1, legacy default unit: minutes)');
    }

    public function testGroupReplyUsesTheUploadRateForXmit(): void
    {
        $rows = RadiusPolicyBuilder::groupReply(['rate_up' => '1', 'rate_up_unit' => 'Mbps', 'rate_down' => '10', 'rate_down_unit' => 'Mbps', 'burst' => '']);
        $this->assertSame([['Ascend-Data-Rate', ':=', '10000000'], ['Ascend-Xmit-Rate', ':=', '1000000'], ['Mikrotik-Rate-Limit', ':=', '1M/10M']], $rows);
        $this->assertSame([], RadiusPolicyBuilder::groupReply(['rate_up' => '0', 'rate_up_unit' => 'Mbps', 'rate_down' => '1', 'rate_down_unit' => 'Mbps']), 'no usable rate: no rows');
    }

    public function testInvalidInputIsRefused(): void
    {
        foreach ([[['username' => '', 'password' => 'p'], 'POLICY_INVALID_USERNAME'], [['username' => "a\nb", 'password' => 'p'], 'POLICY_INVALID_USERNAME'],
            [['username' => str_repeat('x', 65), 'password' => 'p'], 'POLICY_INVALID_USERNAME'], [['username' => 'u', 'password' => ''], 'POLICY_NO_PASSWORD']] as [$customer, $code]) {
            try {
                RadiusPolicyBuilder::build($customer, ['id' => 1, 'type' => 'Hotspot'], null, null);
                $this->fail($code);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($code, $e->getMessage());
            }
        }
    }

    public function testManagedAttributeSetsCoverEveryPolicyAttribute(): void
    {
        $p = RadiusPolicyBuilder::build(['username' => 'm', 'password' => 'p', 'pppoe_ip' => '10.0.0.1'],
            ['id' => 1, 'type' => 'Hotspot', 'typebp' => 'Limited', 'limit_type' => 'Both_Limit', 'time_limit' => 1, 'data_limit' => 1], self::BW, self::expiry());
        $this->assertSame([], array_diff(array_keys($p->checkMap()), RadiusPolicy::MANAGED_CHECK));
        $this->assertSame([], array_diff(array_keys($p->replyMap()), RadiusPolicy::MANAGED_REPLY));
        $this->assertNotContains('radacct', [...RadiusPolicy::MANAGED_CHECK, ...RadiusPolicy::MANAGED_REPLY]);
    }
}
