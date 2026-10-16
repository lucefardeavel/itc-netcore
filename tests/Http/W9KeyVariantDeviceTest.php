<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9-C / OBS-S7-4 re-test after U0: grandfathered unassigned customers whose PPPoE name is a whitespace variant of a
 * reseller-owned customer's RADIUS key (trailing space: equal under the PAD SPACE collation; leading space / TAB:
 * different bytes, same canonical key) are never provisioned onto that key through the legacy recharge / device path.
 * The owned customer's RADIUS rows stay byte-identical and no recharge / invoice is written for the variant.
 * Positive control: an unrelated unassigned customer is provisioned.
 */
final class W9KeyVariantDeviceTest extends RadiusHttpTestCase
{
    private static int $plan = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'w9kv', 'rate_down' => 2, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9kv_pppoe', 'id_bw' => $bw, 'price' => '5', 'type' => 'PPPOE', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => 'w9pool', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius']);
        // the reseller-owned customer cA1 is served on its username key
        static::$db->insertRow('radcheck', ['username' => 'cA1', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'owned-secret']);
    }

    private function grandfathered(string $pppoe): int
    {
        // written directly: U0 refuses creating such a customer through the application
        return static::$db->insertRow('tbl_customers', ['username' => 'w9kv' . bin2hex(random_bytes(3)), 'password' => 'p-' . bin2hex(random_bytes(3)), 'fullname' => 'W9 variant',
            'pppoe_username' => $pppoe, 'pppoe_password' => 'variant-secret', 'service_type' => 'PPPoE', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active', 'balance' => 0]);
    }

    private function recharge(int $id): mixed
    {
        try {
            return static::$app->exec('$result = Package::rechargeUser(' . $id . ', "radius", ' . self::$plan . ', "Recharge", "admin");');
        } catch (\RuntimeException $e) {
            return 'ENDED';
        }
    }

    public function testWhitespaceVariantsNeverReachTheOwnedKey(): void
    {
        $owned = static::$db->rows("SELECT attribute, op, value FROM radcheck WHERE CAST(username AS BINARY) = 'cA1' ORDER BY id");
        foreach (['trailing space' => "cA1 ", 'leading space' => " cA1", 'leading tab' => "\tcA1", 'case' => 'CA1'] as $label => $variant) {
            $id = $this->grandfathered($variant);
            $r = $this->recharge($id);
            $this->assertNotSame(true, $r, $label);
            $this->assertFalse(is_string($r) && str_starts_with($r, 'INV'), "{$label}: no invoice");
            $this->assertSame($owned, static::$db->rows("SELECT attribute, op, value FROM radcheck WHERE CAST(username AS BINARY) = 'cA1' ORDER BY id"), "{$label}: the owned key is untouched");
            $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?', [$id]), "{$label}: no recharge row");
        }
        // positive control: an unrelated unassigned customer gets its own key
        $ok = $this->grandfathered('w9kvfree' . bin2hex(random_bytes(2)));
        $this->assertStringStartsWith('INV', (string) $this->recharge($ok));
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?', [$ok]));
    }
}
