<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Owner schema-readiness rule on an unmigrated (pure legacy) database: every business mutation that
 * depends on the reseller schema fails closed for EVERY role (SuperAdmin included); login, dashboard,
 * own profile/password and logout remain.
 */
final class SchemaNotMigratedTest extends Wp2HttpTestCase
{
    protected static function migrate(): bool
    {
        return false;
    }

    private static int $customer = 0;
    private static int $plan = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $db = static::$db;
        $db->exec("UPDATE tbl_users SET password = '" . sha1('legacy-admin-pw') . "' WHERE username = 'admin'");
        foreach (['ag_legacy' => 'Agent', 'ad_legacy' => 'Admin'] as $u => $role) {
            $db->insertRow('tbl_users', ['username' => $u, 'fullname' => 'Legacy ' . $u, 'password' => sha1('pw-' . $u), 'user_type' => $role, 'status' => 'Active', 'creationdate' => gmdate('Y-m-d H:i:s')]);
        }
        self::$customer = $db->insertRow('tbl_customers', ['username' => 'legacy_cust', 'password' => 'x', 'fullname' => 'Legacy Cust', 'status' => 'Active', 'balance' => 5000]);
        $db->insertRow('tbl_routers', ['name' => 'wp2_router', 'ip_address' => '127.0.0.1:9', 'username' => 'u', 'password' => 'p', 'description' => '', 'enabled' => 1]);
        $bw = $db->insertRow('tbl_bandwidth', ['name_bw' => 'bw', 'rate_down' => 1, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = $db->insertRow('tbl_plans', ['name_plan' => 'p', 'id_bw' => $bw, 'price' => '10', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'validity' => 1,
            'validity_unit' => 'Days', 'routers' => 'wp2_router', 'is_radius' => 0, 'enabled' => 1, 'device' => 'Probe']);
    }

    public function testDependentMutationsFailClosedForEveryRole(): void
    {
        $admin = $this->client();
        $admin->loginStaff('admin', 'legacy-admin-pw');
        $ad = $this->client();
        $ad->loginStaff('ad_legacy', 'pw-ad_legacy');
        $ag = $this->client();
        $ag->loginStaff('ag_legacy', 'pw-ag_legacy');
        static::$app->clearProbes();
        $before = $this->snapshot();
        foreach (['SuperAdmin' => $admin, 'Admin' => $ad] as $role => $c) {
            $this->assertStringContainsString('Reseller schema is not ready', $c->get('dashboard')->body, "{$role} sees the diagnostic banner");
            $r = $c->postWithToken('plan/recharge-post', ['id_customer' => self::$customer, 'server' => 'wp2_router', 'plan' => self::$plan, 'using' => 'cash']);
            $this->assertSame(302, $r->status, "{$role} recharge refused (ServiceGate cannot evaluate ownership)");
            $this->assertSame(403, $c->postWithToken('customers/add-post', ['username' => 'nr_' . $role, 'fullname' => 'X', 'password' => 'abc123'])->status, "{$role} customer.create");
            $this->assertSame(403, $c->postWithToken('settings/users-post', ['username' => 'nr_u_' . $role, 'fullname' => 'Xyz', 'password' => 'abc12345', 'user_type' => 'Report'])->status, "{$role} staff.create");
            $this->assertSame(403, $c->get('reseller/tree')->status, "{$role} tree");
            $this->assertSame(200, $c->get('customers/list')->status, "{$role} legacy read stays");
            $this->assertSame(200, $c->get('routers/list')->status, "{$role} pure legacy system administration stays");
        }
        $this->assertSame(200, $ag->get('dashboard')->status);
        foreach (['customers/list', 'plan/list', 'message/send', 'settings/users'] as $route) {
            $this->assertSame(403, $ag->get($route)->status, "Agent {$route}");
        }
        $this->assertSame(200, $ag->get('settings/change-password')->status);
        $this->assertUnchanged($before, 'not-ready mutations');
        $this->assertSame([], static::$app->probe('device'));
        // cron on an unmigrated database never renews
        static::$db->exec("UPDATE tbl_appconfig SET value = 'yes' WHERE setting = 'enable_balance'");
        $r = static::$app->exec(sprintf('$result = Package::rechargeUser(%d, "wp2_router", %d, "Recharge", "x");', self::$customer, self::$plan));
        $this->assertFalse($r, 'core guard refuses while the schema is not ready');
    }

    public function testOwnProfilePasswordAndLogoutRemain(): void
    {
        $c = $this->client();
        $c->loginStaff('ag_legacy', 'pw-ag_legacy');
        $r = $c->postWithToken('settings/users-edit-post', ['id' => (int) static::$db->value("SELECT id FROM tbl_users WHERE username = 'ag_legacy'"), 'username' => 'ag_legacy',
            'fullname' => 'Renamed Legacy', 'password' => '', 'cpassword' => '', 'phone' => '', 'email' => '', 'city' => '', 'subdistrict' => '', 'ward' => '']);
        $this->assertSame(302, $r->status);
        $this->assertSame('Renamed Legacy', static::$db->value("SELECT fullname FROM tbl_users WHERE username = 'ag_legacy'"), 'own profile editable');
        $r = $c->postWithToken('settings/change-password-post', ['password' => 'pw-ag_legacy', 'npass' => 'new-pw-123', 'cnpass' => 'new-pw-123']);
        $this->assertSame(302, $r->status);
        // WP9 SEC-17: stored with password_hash (bcrypt): the change is proven by verification
        $this->assertTrue(password_verify('new-pw-123', (string) static::$db->value("SELECT password FROM tbl_users WHERE username = 'ag_legacy'")));
        $c2 = $this->client();
        $c2->loginStaff('ag_legacy', 'new-pw-123');
        $this->assertSame(200, $c2->get('dashboard')->status);
        $c2->get('logout');
        $this->assertSame(302, $c2->get('dashboard')->status);
    }
}
