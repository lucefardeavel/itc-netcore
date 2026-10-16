<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP9-B / R-HF-CARRY-01 (N-12): the legacy autoload router gave tree-scoped staff (Agent / Sales) the
 * router list, the plan catalogue of any router (names and prices) and bandwidth names, and let them make
 * the server open a device connection for one of their customers with ANY plan id (any router).
 * Tree-scoped staff now get none of that metadata; customer_is_active only queries a plan the customer
 * holds. Positive controls: Admin keeps the legacy metadata and a held plan is still queried.
 */
final class W9AutoloadExposureTest extends Wp2HttpTestCase
{
    private static int $foreignPlan = 0;
    private static int $bw = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $plan = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($plan['id']);
        $plan['name_plan'] = 'w9_foreign_router_plan';
        self::$foreignPlan = static::$db->insertRow('tbl_plans', $plan);
        self::$bw = (int) $plan['id_bw'];
        static::$db->exec("DELETE FROM tbl_appconfig WHERE setting = 'check_customer_online'");
        static::$db->insertRow('tbl_appconfig', ['setting' => 'check_customer_online', 'value' => 'yes']);
    }

    private function deviceCalls(): int
    {
        $f = static::$app->dir . '/wp2_probe/device.log';
        return is_file($f) ? substr_count((string) file_get_contents($f), '"online_customer"') : 0;
    }

    public function testTreeScopedStaffGetNoRouterPlanOrBandwidthMetadata(): void
    {
        foreach (['A', 'A1'] as $who) {
            $c = $this->staff($who);
            $this->assertStringNotContainsString('wp2_router', $c->get('autoload/server')->body, "{$who}: router list");
            foreach (['Hotspot', 'PPPOE'] as $jenis) {
                $body = $c->postWithToken('autoload/plan', ['server' => 'wp2_router', 'jenis' => $jenis])->body;
                $this->assertStringNotContainsString('wp2_plan', $body, "{$who}: plan catalogue {$jenis}");
                $this->assertStringNotContainsString('w9_foreign_router_plan', $body, "{$who}: plan catalogue {$jenis}");
            }
            $bwName = (string) static::$db->value('SELECT name_bw FROM tbl_bandwidth WHERE id = ?', [self::$bw]);
            if ($bwName !== '') {
                $this->assertStringNotContainsString($bwName, $c->get('autoload/bw_name/' . self::$bw)->body, "{$who}: bandwidth name");
            }
        }
    }

    public function testAdminKeepsLegacyMetadata(): void
    {
        $this->assertStringContainsString('wp2_router', $this->staff('ad')->get('autoload/server')->body);
    }

    public function testDeviceQueryOnlyForAPlanTheCustomerHolds(): void
    {
        $c = $this->staff('A');
        $before = $this->deviceCalls();
        $c->get('autoload/customer_is_active/cA1/' . self::$foreignPlan);
        $this->assertSame($before, $this->deviceCalls(), 'a plan the customer does not hold must not trigger a device query');
        $c->get('autoload/customer_is_active/cA1/' . static::$seed->planId);
        $this->assertSame($before + 1, $this->deviceCalls(), 'positive control: the held plan is queried');
    }
}
