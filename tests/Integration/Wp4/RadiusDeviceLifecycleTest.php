<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\ProvisionFailure;
use Reseller\Tests\Support\RadiusTestCase;

/**
 * The legacy Radius device (system/devices/Radius.php) used by every legacy caller (owner decisions Q5, C17):
 * the RADIUS Expiration is the saved billing expiry, and no lifecycle operation deletes or rewrites the
 * accounting history (the pre-WP4 device purged radacct on every activation, renewal, sync, password /
 * username change and expiry).
 */
final class RadiusDeviceLifecycleTest extends RadiusTestCase
{
    private static int $bw;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/system/devices/Radius.php';
        self::$bw = static::appInsert('tbl_bandwidth', ['name_bw' => 'wp4_bw', 'rate_down' => 8, 'rate_down_unit' => 'Mbps', 'rate_up' => 2, 'rate_up_unit' => 'Mbps']);
    }

    private static function plan(string $type, array $extra = []): array
    {
        $values = $extra + [
            'name_plan' => 'wp4_' . bin2hex(random_bytes(3)), 'id_bw' => self::$bw, 'price' => '1000', 'type' => $type, 'typebp' => 'Unlimited',
            'limit_type' => 'Time_Limit', 'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB',
            'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1, 'routers' => 'radius', 'is_radius' => 1,
            'pool' => 'wp4-pool', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ];
        $values['id'] = static::appInsert('tbl_plans', $values);
        return $values;
    }

    private static function customer(array $extra = []): array
    {
        $u = 'wp4c' . bin2hex(random_bytes(3));
        $values = $extra + ['username' => $u, 'password' => 'pw-' . $u, 'fullname' => 'WP4 ' . $u, 'address' => '', 'phonenumber' => '0700',
            'email' => $u . '@example.invalid', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active', 'balance' => 0];
        $values['id'] = static::appInsert('tbl_customers', $values);
        return $values;
    }

    private static function recharge(array $customer, array $plan, string $expiration, string $time, string $status = 'on', string $on = '2026-01-01', string $at = '08:00:00'): int
    {
        return static::appInsert('tbl_user_recharges', [
            'customer_id' => $customer['id'], 'username' => $customer['username'], 'plan_id' => $plan['id'], 'namebp' => $plan['name_plan'],
            'recharged_on' => $on, 'recharged_time' => $at, 'expiration' => $expiration, 'time' => $time, 'status' => $status,
            'method' => 'Admin - test', 'routers' => 'radius', 'type' => $plan['type'], 'admin_id' => 1,
        ]);
    }

    private static function check(string $username): array
    {
        $out = [];
        foreach (static::rows('SELECT attribute, value FROM radcheck WHERE username = ?', [$username]) as $r) {
            $out[$r['attribute']] = $r['value'];
        }
        return $out;
    }

    /** Q5: the RADIUS Expiration is exactly the saved billing expiry (not "now + validity"). */
    public function testExpirationIsTheSavedBillingExpiry(): void
    {
        $plan = self::plan('Hotspot');
        $c = self::customer();
        self::recharge($c, $plan, '2031-03-15', '21:30:00');
        (new \Radius())->add_customer($c, $plan);
        $this->assertSame('15 Mar 2031 21:30:00', self::check($c['username'])['Expiration'], 'the billing row, not 30 days from now');
        // an edit of the billing row followed by a sync moves the RADIUS expiry with it
        static::$app->prepare('UPDATE tbl_user_recharges SET expiration = ?, time = ? WHERE customer_id = ?')->execute(['2031-04-01', '06:00:00', $c['id']]);
        (new \Radius())->sync_customer($c, $plan);
        $this->assertSame('01 Apr 2031 06:00:00', self::check($c['username'])['Expiration']);
    }

    public function testNoActiveRechargeRefusesWithoutWriting(): void
    {
        $plan = self::plan('Hotspot');
        $c = self::customer();
        self::recharge($c, $plan, '2020-01-01', '00:00:00', 'off');
        $before = [static::checksum('radcheck'), static::checksum('radreply'), static::checksum('radusergroup')];
        try {
            (new \Radius())->add_customer($c, $plan);
            $this->fail('provisioned without an active recharge');
        } catch (ProvisionFailure $e) {
            $this->assertSame(['FAILED', 'NO_ACTIVE_RECHARGE'], [$e->result->status, $e->result->code]);
        }
        $this->assertSame($before, [static::checksum('radcheck'), static::checksum('radreply'), static::checksum('radusergroup')]);
    }

    /** C17: every lifecycle path that reaches RADIUS provisioning leaves radacct byte-identical. */
    public function testNoLifecyclePathTouchesAccountingHistory(): void
    {
        // WP9-B / N-01: the expired plan is read from the stored plan (a caller can only narrow it to 0)
        $expired = self::plan('Hotspot', ['name_plan' => 'wp4_expired_' . bin2hex(random_bytes(2))]);
        $plan = self::plan('PPPOE', ['plan_expired' => $expired['id']]);
        $c = self::customer(['pppoe_username' => '', 'pppoe_password' => 'ppp-secret', 'pppoe_ip' => '']);
        self::recharge($c, $plan, '2031-01-01', '00:00:00');
        foreach (['h1' => gmdate('Y-m-d H:i:s'), 'h2' => gmdate('Y-m-d H:i:s'), 'open' => null] as $session => $stop) {
            static::acct($c['username'], $session, $stop);
        }
        static::acct('someone-else', 'other', null);
        $history = static::checksum('radacct');
        $count = count(static::rows('SELECT radacctid FROM radacct'));
        $dev = new \Radius();
        $steps = [
            'activation (add_customer)' => fn() => $dev->add_customer($c, $plan),
            'renewal / sync' => fn() => $dev->sync_customer($c, $plan),
            'password change re-provision' => function () use ($dev, $c, $plan): void {
                $c['pppoe_password'] = 'changed-secret';
                $dev->add_customer($c, $plan);
            },
            'static address edit' => function () use ($dev, $c, $plan): void {
                $c['pppoe_ip'] = '10.44.0.4';
                $dev->add_customer($c, $plan);
            },
            'expiry to the expired plan' => fn() => $dev->remove_customer($c, $plan + ['plan_expired' => $expired['id']]),
            'deactivate (remove_customer)' => fn() => $dev->remove_customer($c, ['plan_expired' => 0] + $plan),
            'username change' => fn() => $dev->change_username($plan, $c['username'], $c['username'] . 'x'),
            'username change back' => fn() => $dev->change_username($plan, $c['username'] . 'x', $c['username']),
            'plan update' => fn() => $dev->update_plan($plan['name_plan'], $plan),
            'disconnect' => fn() => $dev->disconnect_customer($c, 'radius'),
            'delete' => fn() => $dev->customerDelete($c['username']),
            'plan removal' => fn() => $dev->remove_plan($plan),
        ];
        foreach ($steps as $label => $step) {
            $step();
            $this->assertSame($history, static::checksum('radacct'), "{$label}: accounting history untouched");
        }
        $this->assertCount($count, static::rows('SELECT radacctid FROM radacct'));
    }

    public function testExpiredPlanAndSuspensionSemantics(): void
    {
        // WP9-B / N-01: the expired plan is read from the stored plan (a caller can only narrow it to 0)
        $expired = self::plan('Hotspot', ['shared_users' => 1]);
        $plan = self::plan('Hotspot', ['plan_expired' => $expired['id']]);
        $c = self::customer();
        self::recharge($c, $plan, '2031-01-01', '00:00:00');
        $dev = new \Radius();
        $dev->add_customer($c, $plan);
        $dev->remove_customer($c, $plan + ['plan_expired' => $expired['id']]);
        $after = self::check($c['username']);
        $this->assertSame('pw-' . $c['username'], $after['Cleartext-Password'], 'the expired plan keeps the login (legacy behaviour)');
        $this->assertArrayNotHasKey('Expiration', $after, 'restricted expired-plan profile without an end date (legacy behaviour)');
        $this->assertSame(['plan_' . $expired['id']], array_column(static::rows('SELECT groupname FROM radusergroup WHERE username = ?', [$c['username']]), 'groupname'));
        $dev->remove_customer($c, ['plan_expired' => 0] + $plan);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', self::check($c['username'])['Cleartext-Password'], 'deactivation scrambles the password');
    }

    public function testPppoeKeyAndRenameAcrossAllTables(): void
    {
        $plan = self::plan('PPPOE');
        $c = self::customer(['pppoe_username' => 'pp' . bin2hex(random_bytes(3)), 'pppoe_password' => 'pp-secret', 'pppoe_ip' => '10.7.7.7']);
        self::recharge($c, $plan, '2031-01-01', '00:00:00');
        $dev = new \Radius();
        $dev->add_customer($c, $plan);
        $this->assertSame([], self::check($c['username']), 'Q7: no policy under the account username');
        $this->assertSame('pp-secret', self::check($c['pppoe_username'])['Cleartext-Password']);
        $new = $c['pppoe_username'] . 'n';
        $dev->change_username($plan, $c['pppoe_username'], $new);
        $this->assertSame('10.7.7.7', static::rows("SELECT value FROM radreply WHERE username = ? AND attribute = 'Framed-IP-Address'", [$new])[0]['value'], 'radreply renamed too');
        $this->assertSame([], static::rows('SELECT id FROM radreply WHERE username = ?', [$c['pppoe_username']]));
    }

    public function testPlanGroupAndNasThroughTheDevice(): void
    {
        $plan = self::plan('Hotspot');
        $dev = new \Radius();
        $dev->add_plan($plan);
        $this->assertSame('2M/8M', static::rows("SELECT value FROM radgroupreply WHERE groupname = ? AND attribute = 'Mikrotik-Rate-Limit'", ['plan_' . $plan['id']])[0]['value']);
        $id = $dev->nasAdd('wp4nas', '192.0.2.9', '3799', 'nas-secret', '', 'test');
        $this->assertGreaterThan(0, $id);
        $this->assertTrue($dev->nasUpdate($id, 'wp4nas2', '192.0.2.9', '3799', '', '', 'test'));
        $this->assertSame(['wp4nas2', 'nas-secret'], array_values(static::rows('SELECT shortname, secret FROM nas WHERE id = ?', [$id])[0]), 'an empty secret keeps the stored one');
        $this->assertTrue($dev->nasUpdate($id, 'wp4nas2', '192.0.2.9', '3799', 'rotated', '', 'test'));
        $this->assertSame('rotated', static::rows('SELECT secret FROM nas WHERE id = ?', [$id])[0]['secret']);
        $this->assertFalse($dev->nasUpdate(987654, 'x', '192.0.2.1', null, 'y'));
    }
}
