<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp9Evidence.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 T1-A file 3 - V1 CHARACTERISATION (owner 2026-10-15): a voucher-only code equal to the username of an UNASSIGNED
 * (legacy) customer that already holds RADIUS rows. ServiceGate::isBlockedUsername() refuses only names of reseller-owned
 * customers, so the voucher-only grant may write RADIUS rows under the same key. Disposable database and application copy,
 * synthetic data, no FreeRADIUS process, no device. If the customer's own RADIUS policy changes, the case is recorded as
 * STOP_TRIGGER (unauthorized RADIUS policy mutation / cross-identity overwrite) and T1-A stops (owner §5).
 */
final class T1aVoucherV1Test extends RadiusHttpTestCase
{
    use Wp9Evidence;

    private static int $radiusHotspot = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $seed = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seed['id']);
        self::$radiusHotspot = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9_v1_radius_hotspot', 'type' => 'Hotspot', 'device' => 'Radius',
            'routers' => 'radius', 'is_radius' => 1] + $seed);
    }

    /** the exact-byte RADIUS rows of one key */
    private static function exact(string $key): array
    {
        return static::$db->rows('SELECT username, attribute, op, value FROM radcheck WHERE BINARY username = ? ORDER BY attribute', [$key]);
    }

    public function testV1VoucherCodeEqualToAnUnassignedCustomersUsername(): void
    {
        $u = 'w9v1' . bin2hex(random_bytes(3));
        $pw = 'Pw-' . bin2hex(random_bytes(4));
        $id = static::$db->insertRow('tbl_customers', ['username' => $u, 'password' => $pw, 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '',
            'fullname' => 'W9 V1', 'address' => '', 'phonenumber' => '0700', 'email' => $u . '@example.invalid', 'service_type' => 'Hotspot',
            'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active', 'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0,
            'manager_id' => null]);
        // control: the unassigned customer's own legitimate grant writes its RADIUS policy
        $g = static::$app->exec(sprintf('$result = Package::rechargeUser(%d, "radius", %d, "Recharge", "admin");', $id, self::$radiusHotspot));
        $mine = self::exact($u);
        $pwRow = array_values(array_filter($mine, static fn(array $r): bool => $r['attribute'] === 'Cleartext-Password'));
        $this->assertNotEmpty($pwRow, 'control: the customer has RADIUS rows after its own grant ' . json_encode($g));
        $this->assertSame($pw, $pwRow[0]['value'], 'control: its password is the customer password');
        self::record('V1 control: unassigned customer provisioned', 'CONTROL_PASS', ['grant' => $g, 'radcheck_rows' => count($mine)]);
        // V1: a voucher-only code equal to that username
        static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => 'radius', 'id_plan' => self::$radiusHotspot, 'code' => $u,
            'user' => '0', 'status' => '0', 'generated_by' => $this->sid('ad')]);
        $before = self::state(static::$db);
        $out = static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, \Reseller\Domain\RedemptionTarget::voucherOnly(%s), \Reseller\Domain\RedemptionSource::public())["status"];',
            var_export($u, true), var_export($u, true)));
        $d = self::delta($before, self::state(static::$db));
        $after = self::exact($u);
        $pwAfter = array_values(array_filter($after, static fn(array $r): bool => $r['attribute'] === 'Cleartext-Password'));
        $customerRow = static::$db->rows('SELECT username, password FROM tbl_customers WHERE id = ?', [$id])[0];
        $overwritten = $after !== $mine;
        $data = ['voucher_outcome' => $out, 'state_delta' => $d, 'customer_radcheck_before' => self::redact($mine, $pw, $u),
            'customer_radcheck_after' => self::redact($after, $pw, $u), 'customer_password_row_after_equals_customer_password' => ($pwAfter[0]['value'] ?? null) === $pw,
            'customer_row_unchanged' => $customerRow === ['username' => $u, 'password' => $pw], 'radius_policy_changed' => $overwritten];
        if ($overwritten) {
            self::record('V1 voucher code = unassigned customer username', 'STOP_TRIGGER',
                $data + ['detail' => 'a voucher-only grant changed the RADIUS policy of an existing (unassigned) customer: unauthorized RADIUS policy mutation / cross-identity overwrite']);
        } else {
            self::record('V1 voucher code = unassigned customer username', 'OBSERVED', $data + ['detail' => 'the customer RADIUS policy is unchanged']);
        }
        $this->assertTrue($data['customer_row_unchanged'], 'the customer row itself is not changed by a voucher grant');
    }

    /** fixture secrets are synthetic, but the evidence keeps only their role, never a value */
    private static function redact(array $rows, string $pw, string $code): array
    {
        return array_map(static fn(array $r): array => ['attribute' => $r['attribute'], 'op' => $r['op'],
            'value' => $r['value'] === $pw ? '<customer password>' : ($r['value'] === $code ? '<voucher code>' : (in_array($r['attribute'], ['Cleartext-Password'], true) ? '<other secret>' : $r['value']))], $rows);
    }
}
