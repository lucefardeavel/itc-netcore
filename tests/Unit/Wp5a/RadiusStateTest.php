<?php
namespace Reseller\Tests\Unit\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Activation\RadiusState;
use Reseller\Radius\RadiusPolicyBuilder;

/** The canonical RADIUS projection used by reconciliation (owner fix round 1): exact, password-free, value-free diagnostics. */
final class RadiusStateTest extends TestCase
{
    private static function target(string $password = 'pw-secret-1', string $expiry = '2026-11-01 10:00:00'): array
    {
        $customer = ['username' => 'cust1', 'password' => $password, 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''];
        $plan = ['id' => 7, 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'shared_users' => 2, 'pool' => ''];
        $bw = ['rate_down' => 10, 'rate_down_unit' => 'Mbps', 'rate_up' => 5, 'rate_up_unit' => 'Mbps'];
        $policy = RadiusPolicyBuilder::build($customer, $plan, $bw, new \DateTimeImmutable($expiry, new \DateTimeZone('UTC')));
        return RadiusState::target($policy, $bw, 'ACT-0000000000000001');
    }

    public function testTheTargetIsTheProvisionerContractWithoutThePassword(): void
    {
        $t = self::target();
        $this->assertSame('cust1', $t['username']);
        $this->assertSame([['plan_7', 1]], $t['subscriber']['groups']);
        $this->assertSame(['Cleartext-Password', 'Expiration', 'Simultaneous-Use'], array_column($t['subscriber']['check'], 0));
        $this->assertSame(['Mikrotik-Rate-Limit', 'WISPr-Session-Terminate-Time'], array_column($t['subscriber']['reply'], 0));
        $this->assertSame(['Ascend-Data-Rate', 'Ascend-Xmit-Rate', 'Mikrotik-Rate-Limit'], array_column($t['plan_group'], 0));
        $this->assertStringNotContainsString('pw-secret-1', json_encode($t), 'the password is never in the projection');
        $this->assertSame(RadiusState::digest('ACT-0000000000000001', 'pw-secret-1'), $t['subscriber']['check'][0][2]);
        $this->assertNotSame(RadiusState::digest('ACT-0000000000000002', 'pw-secret-1'), $t['subscriber']['check'][0][2], 'salted per activation');
    }

    public function testEqualityIsExactAndCanonical(): void
    {
        $t = self::target();
        $this->assertTrue(RadiusState::same($t, json_decode(json_encode($t), true)), 'survives the JSON round trip of the event history');
        $shuffled = $t;
        $shuffled['subscriber']['check'] = array_reverse($t['subscriber']['check']);
        $this->assertTrue(RadiusState::same($t, $shuffled), 'row order is not significant');
        $this->assertFalse(RadiusState::same($t, self::target('another-password')), 'a different password differs');
        $this->assertFalse(RadiusState::same($t, self::target('pw-secret-1', '2026-11-02 10:00:00')));
        $extra = $t;
        $extra['subscriber']['groups'][] = ['foreign', 2];
        $this->assertFalse(RadiusState::same($t, $extra), 'an extra group membership differs');
        $this->assertTrue(RadiusState::sameSubscriber($t, array_replace($t, ['plan_group' => []])), 'subscriber-only comparison ignores the plan group');
        $this->assertFalse(RadiusState::same($t, array_replace($t, ['plan_group' => []])));
        $this->assertFalse(RadiusState::same($t, ['garbage' => true]), 'a malformed stored projection never matches');
    }

    public function testDiffNamesComponentsNeverValues(): void
    {
        $t = self::target();
        $other = self::target('another-password', '2035-12-31 23:59:59');
        $other['subscriber']['groups'][] = ['foreign', 2];
        $diff = RadiusState::diff($other, $t);
        $this->assertSame(['check:Cleartext-Password', 'check:Expiration', 'reply:WISPr-Session-Terminate-Time', 'groups'], $diff);
        $this->assertStringNotContainsString('2035', json_encode($diff));
        $this->assertStringNotContainsString('another-password', json_encode($diff));
        $this->assertSame([], RadiusState::diff($t, $t));
    }
}
