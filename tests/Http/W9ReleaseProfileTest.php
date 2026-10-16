<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP9 release profile: an installation WITHOUT `$legacy_devices_enabled = true` (the release default) serves
 * subscribers through FreeRADIUS only. The legacy MikroTik-API drivers and the rlm_rest device cannot be reached:
 *  - they refuse construction (DEVICE_DISABLED_BY_RELEASE_PROFILE), so no device operation can run;
 *  - Package::rechargeUser refuses a plan of a disabled device BEFORE any recharge / invoice / balance write;
 *  - the plan forms do not offer them and refuse them on save; the device page marks them disabled.
 * Positive controls: Radius and Dummy construct; the same calls with the opt-in work (every other harness class).
 */
final class W9ReleaseProfileTest extends Wp2HttpTestCase
{
    protected static function appOptions(): array
    {
        return ['legacy_devices' => false];
    }

    public function testDisabledDriversRefuseConstruction(): void
    {
        $r = static::$app->exec(<<<'PHP'
$out = [];
foreach (["MikrotikHotspot", "MikrotikPppoe", "MikrotikVpn", "RadiusRest", "Radius", "Dummy"] as $d) {
    require_once $DEVICE_PATH . "/" . $d . ".php";
    try {
        new $d();
        $out[$d] = "constructed";
    } catch (\Reseller\Radius\ProvisionFailure $e) {
        $out[$d] = $e->result->code;
    }
}
$result = $out;
PHP);
        foreach (['MikrotikHotspot', 'MikrotikPppoe', 'MikrotikVpn', 'RadiusRest'] as $d) {
            $this->assertSame('DEVICE_DISABLED_BY_RELEASE_PROFILE', $r[$d], $d);
        }
        $this->assertSame('constructed', $r['Radius']);
        $this->assertSame('constructed', $r['Dummy']);
    }

    public function testLegacyRechargeOfADisabledDevicePlanWritesNothing(): void
    {
        $plan = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($plan['id']);
        $plan['name_plan'] = 'w9_legacy_pppoe';
        $plan['device'] = 'MikrotikPppoe';
        $plan['type'] = 'PPPOE';
        $pid = static::$db->insertRow('tbl_plans', $plan);
        $before = $this->snapshot();
        $maxAudit = (int) static::$db->value('SELECT COALESCE(MAX(id), 0) FROM tbl_reseller_audit_events');
        $r = static::$app->exec(sprintf('$result = Package::rechargeUser(%d, "wp2_router", %d, "Recharge", "admin");', $this->cid('cNULL'), $pid));
        $this->assertFalse($r);
        $this->assertSame([], static::$db->rows('SELECT event_type, after_json FROM tbl_reseller_audit_events WHERE id > ?', [$maxAudit]), 'no audit side effect either');
        $this->assertUnchanged($before, 'a disabled-device plan is refused before any write');
    }

    public function testPlanFormsAndDevicePageFollowTheProfile(): void
    {
        $sa = $this->staff('sa');
        $body = $sa->get('settings/devices')->body;
        $this->assertStringContainsString('disabled in the release profile', $body);
        $form = $sa->get('services/add')->body;
        $this->assertStringNotContainsString('value="MikrotikHotspot"', $form, 'not offered');
        $this->assertStringContainsString('value="Radius"', $form, 'positive control: FreeRADIUS offered');
    }
}
