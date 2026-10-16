<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9-B / N-01 + N-02 (Phase B, OD-W9-9 B1' / B2):
 *  - the public legacy grant Radius::customerAddPlan() refuses a reseller-owned customer (it had no guard);
 *  - remove_customer() never applies a caller-supplied expired plan: only the stored plan's plan_expired, which
 *    a caller can narrow to 0 (deactivate) but not change; a reseller-owned customer never gets a sellable
 *    (resale-enabled) plan as the free fallback profile;
 *  - a plugin that throws while loading is reported instead of silently ignored;
 *  - radius.php loads no plugin before the FreeRADIUS request is authenticated (RestGate).
 * Positive controls: the unassigned legacy customer and a non-sellable expired plan keep the legacy behaviour.
 */
final class W9PhaseBAuthorityTest extends RadiusHttpTestCase
{
    private static int $full = 0;
    private static int $restricted = 0;
    private static int $base = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $plan = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($plan['id']);
        $plan['device'] = 'Radius';
        $plan['is_radius'] = 1;
        $plan['routers'] = 'radius';
        $plan['plan_expired'] = 0;
        self::$full = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9_full_sellable'] + $plan);
        self::$restricted = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9_restricted'] + $plan);
        self::$base = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9_base'] + $plan);
        static::$db->insertRow('tbl_reseller_plan_base_costs', ['plan_id' => self::$full, 'base_cost' => '1.00', 'is_resale_enabled' => 1, 'created_by' => static::$seed->staff['sa'], 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => static::$seed->staff['sa'], 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    private function radGroups(string $username): array
    {
        return array_column(static::$db->rows('SELECT groupname FROM radusergroup WHERE username = ?', [$username]), 'groupname');
    }

    private function device(string $code): mixed
    {
        return static::$app->exec('require_once $DEVICE_PATH . "/Radius.php"; $d = new Radius(); try { ' . $code . ' $result = "ran"; } catch (\Reseller\Radius\ProvisionFailure $e) { $result = $e->result->code; } catch (\Throwable $e) { $result = get_class($e) . ": " . $e->getMessage(); }');
    }

    public function testCustomerAddPlanRefusesResellerOwnedCustomers(): void
    {
        $before = $this->snapshot();
        $r = $this->device(sprintf('$d->customerAddPlan(ORM::for_table("tbl_customers")->find_one(%d)->as_array(), ORM::for_table("tbl_plans")->find_one(%d)->as_array(), "");', $this->cid('cA1'), self::$full));
        $this->assertSame('RESELLER_OWNED_REFUSED', $r);
        $this->assertUnchanged($before, 'customerAddPlan on a reseller-owned customer');
        $r = $this->device(sprintf('$d->customerAddPlan(ORM::for_table("tbl_customers")->find_one(%d)->as_array(), ORM::for_table("tbl_plans")->find_one(%d)->as_array(), "");', $this->cid('cNULL'), self::$restricted));
        $this->assertSame('ran', $r, 'positive control: the unassigned legacy customer');
    }

    public function testCallerCannotInjectAnExpiredPlan(): void
    {
        foreach (['cA2', 'cNULL2'] as $who) {
            $r = $this->device(sprintf('$p = ORM::for_table("tbl_plans")->find_one(%d)->as_array(); $p["plan_expired"] = %d; $d->remove_customer(ORM::for_table("tbl_customers")->find_one(%d)->as_array(), $p);', self::$base, self::$full, $this->cid($who)));
            $this->assertSame('ran', $r, $who);
            $this->assertNotContains('plan_' . self::$full, $this->radGroups($who), "{$who}: a caller-supplied plan_expired must not be applied");
        }
    }

    public function testResellerOwnedCustomerNeverGetsASellablePlanAsFallback(): void
    {
        static::$db->exec('UPDATE tbl_plans SET plan_expired = ' . self::$full . ' WHERE id = ' . self::$base);
        try {
            $this->device(sprintf('$d->remove_customer(ORM::for_table("tbl_customers")->find_one(%d)->as_array(), ORM::for_table("tbl_plans")->find_one(%d)->as_array());', $this->cid('cB'), self::$base));
            $this->assertNotContains('plan_' . self::$full, $this->radGroups('cB'), 'reseller-owned: no sellable fallback');
            static::$db->exec('UPDATE tbl_plans SET plan_expired = ' . self::$restricted . ' WHERE id = ' . self::$base);
            $this->device(sprintf('$d->remove_customer(ORM::for_table("tbl_customers")->find_one(%d)->as_array(), ORM::for_table("tbl_plans")->find_one(%d)->as_array());', $this->cid('cB'), self::$base));
            $this->assertContains('plan_' . self::$restricted, $this->radGroups('cB'), 'positive control: a non-sellable expired plan is still applied');
        } finally {
            static::$db->exec('UPDATE tbl_plans SET plan_expired = 0 WHERE id = ' . self::$base);
        }
    }

    public function testThrowingPluginIsReported(): void
    {
        $f = static::$app->dir . '/system/plugin/w9_throwing.php';
        file_put_contents($f, "<?php\nthrow new RuntimeException('w9 plugin load failure');\n");
        try {
            $log = static::$app->dir . '/wp2_probe/php_error.log';
            $before = is_file($log) ? (string) file_get_contents($log) : '';
            $this->assertSame(200, $this->client()->get('login')->status);
            $after = (string) @file_get_contents($log);
            $this->assertStringContainsString('plugin-load w9_throwing.php', substr($after, strlen($before)), 'the plugin load error is reported');
        } finally {
            unlink($f);
        }
    }

    public function testRadiusRestLoadsNoPluginBeforeAuthentication(): void
    {
        $marker = static::$app->dir . '/wp2_probe/w9_radius_plugin.log';
        $f = static::$app->dir . '/system/plugin/w9_marker.php';
        file_put_contents($f, "<?php\n@file_put_contents(__DIR__ . '/../../wp2_probe/w9_radius_plugin.log', (\$_SERVER['SCRIPT_NAME'] ?? '') . \"\\n\", FILE_APPEND);\n");
        @unlink($marker);
        try {
            $r = $this->client()->raw('POST', '/radius.php?action=authenticate', ['username' => 'x', 'password' => 'y']);
            $this->assertSame(401, $r->status, $r->excerpt());
            $this->assertStringNotContainsString('radius.php', (string) @file_get_contents($marker), 'no plugin code before RestGate');
            $this->client()->get('login');
            $this->assertStringContainsString('index.php', (string) @file_get_contents($marker), 'positive control: plugins load on the web entry point');
        } finally {
            unlink($f);
        }
    }
}
