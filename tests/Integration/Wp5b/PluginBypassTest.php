<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP5B §5: a plugin (code running inside the application) that calls the legacy core writers or the legacy
 * device grant methods for a reseller-owned customer is refused and writes nothing; hooks stay notification-only.
 */
final class PluginBypassTest extends Wp2HttpTestCase
{
    public function testCoreWritersAndDeviceGrantsRefuseResellerOwnedCustomers(): void
    {
        $before = $this->snapshot();
        static::$app->clearProbes();
        $res = static::$app->exec(sprintf(<<<'PHP'
$c = ORM::for_table("tbl_customers")->find_one(%1$d);
$p = ORM::for_table("tbl_plans")->find_one(%2$d);
$out = [
    "rechargeUser" => Package::rechargeUser(%1$d, "wp2_router", %2$d, "Recharge", "plugin"),
    "rechargeBalance" => Package::rechargeBalance($c, ["name_plan" => "Balance", "price" => 100], "Deposit", "plugin"),
    "rechargeCustomBalance" => Package::rechargeCustomBalance($c, ["name_plan" => "Balance", "price" => 100], "Deposit", "plugin"),
    "transfer" => Balance::transfer(%1$d, "cNULL", 10),
];
foreach (["Radius", "RadiusRest", "MikrotikHotspot", "MikrotikPppoe", "MikrotikVpn"] as $d) {
    require_once $DEVICE_PATH . "/" . $d . ".php";
    foreach (["add_customer", "sync_customer", "change_username"] as $m) {
        try {
            $m === "change_username" ? (new $d())->change_username($p, $c["username"], "hijacked") : (new $d())->$m($c, $p);
            $out["$d::$m"] = "ran";
        } catch (\Reseller\Radius\ProvisionFailure $e) {
            $out["$d::$m"] = $e->result->code;
        } catch (\Throwable $e) {
            $out["$d::$m"] = get_class($e);
        }
    }
}
$result = $out;
PHP, $this->cid('cA1'), static::$seed->planId));
        foreach (['rechargeUser', 'rechargeBalance', 'rechargeCustomBalance', 'transfer'] as $k) {
            $this->assertFalse($res[$k], $k);
        }
        foreach (['Radius', 'RadiusRest', 'MikrotikHotspot', 'MikrotikPppoe', 'MikrotikVpn'] as $d) {
            foreach (['add_customer', 'sync_customer', 'change_username'] as $m) {
                $this->assertSame('RESELLER_OWNED_REFUSED', $res["{$d}::{$m}"], "{$d}::{$m}");
            }
        }
        $this->assertUnchanged($before, 'plugin bypass attempts');
    }
}
