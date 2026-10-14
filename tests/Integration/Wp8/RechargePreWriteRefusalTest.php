<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP8 S7-FC (owner decision OD-S7-FC-1, S7_FC_PREWRITE_REFUSAL_APPROVED): Package::rechargeUser() judges the device
 * grant (LegacyReprovisionService::assertDeviceAllowed, which checks username AND pppoe_username since S7-F1) before the
 * recharge cycle. A legacy (unassigned) customer whose identity collides with a reseller-owned customer's key - as
 * username or PPPoE username, exactly or in a form the database compares as equal - is refused with no hook, no
 * recharge insert / update, no transaction / invoice, no device call, no RADIUS write and no notification; the
 * refusal is recorded once (op=deviceGrant) without secrets. Every other recharge keeps the WP4 order (recharge row
 * saved, then the device) and the legacy device-failure behaviour ("sync manually"). Runs inside the disposable
 * application (full init.php) with RADIUS enabled; MikroTik plans point at a closed local port (no router).
 */
final class RechargePreWriteRefusalTest extends RadiusHttpTestCase
{
    private const HOOKS = <<<'PHP'
function s7fc_hook_recharge($a = null) { $GLOBALS['s7fc_hooks'][] = 'recharge_user'; }
function s7fc_hook_finish($a = null) { $GLOBALS['s7fc_hooks'][] = 'recharge_user_finish'; }
$GLOBALS['s7fc_hooks'] = [];
register_hook('recharge_user', 's7fc_hook_recharge');
register_hook('recharge_user_finish', 's7fc_hook_finish');
PHP;

    private static array $plans = [];
    private static array $owned = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $seed = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seed['id']);
        foreach ([
            'radius_pppoe' => ['type' => 'PPPOE', 'device' => 'Radius', 'routers' => 'radius', 'is_radius' => 1],
            'mikrotik_pppoe' => ['type' => 'PPPOE', 'device' => 'MikrotikPppoe'],
            'mikrotik_vpn' => ['type' => 'VPN', 'device' => 'MikrotikVpn'],
            'mikrotik_hotspot' => ['type' => 'Hotspot', 'device' => 'MikrotikHotspot'],
            'probe_pppoe' => ['type' => 'PPPOE', 'device' => 'Probe'],
            'probe_hotspot' => ['type' => 'Hotspot', 'device' => 'Probe'],
        ] as $k => $v) {
            self::$plans[$k] = static::$db->insertRow('tbl_plans', ['name_plan' => 's7fc_' . $k] + $v + $seed);
        }
    }

    /** a customer row: manager 'A' = reseller-owned, null = unassigned legacy */
    private function person(?string $manager, array $extra = []): array
    {
        $u = 's7fc' . bin2hex(random_bytes(3));
        $row = $extra + ['username' => $u, 'password' => 'Pw-' . bin2hex(random_bytes(4)), 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '',
            'fullname' => 'S7FC ' . $u, 'address' => '', 'phonenumber' => '0700', 'email' => $u . '@example.invalid', 'service_type' => 'Hotspot',
            'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active', 'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0,
            'manager_id' => $manager !== null ? $this->sid($manager) : null];
        $row['id'] = static::$db->insertRow('tbl_customers', $row);
        return $row;
    }

    private function existing(array $c, string $plan, string $status): void
    {
        $p = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [self::$plans[$plan]])[0];
        static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => $c['id'], 'username' => $c['username'], 'plan_id' => $p['id'], 'namebp' => $p['name_plan'], 'recharged_on' => '2026-01-01',
            'recharged_time' => '00:00:00', 'expiration' => $status === 'on' ? '2031-01-01' : '2026-02-01', 'time' => '10:00:00', 'status' => $status,
            'method' => 'Admin - fixture', 'routers' => $p['routers'], 'type' => $p['type'], 'admin_id' => $this->sid('ad'),
        ]);
    }

    /** Package::rechargeUser in the application runtime, with the recharge hooks observed */
    private function recharge(int $customerId, string $plan, string $gateway = 'Recharge', string $channel = 'S7FC', ?string $router = null): array
    {
        $p = static::$db->rows('SELECT routers FROM tbl_plans WHERE id = ?', [self::$plans[$plan] ?? (int) $plan])[0];
        return static::$app->exec(self::HOOKS . sprintf("\n\$v = Package::rechargeUser(%d, %s, %d, %s, %s);\n\$result = ['value' => \$v, 'hooks' => \$GLOBALS['s7fc_hooks']];",
            $customerId, var_export($router ?? $p['routers'], true), self::$plans[$plan] ?? (int) $plan, var_export($gateway, true), var_export($channel, true)));
    }

    /** the observation of one refused call: everything that must stay empty / unchanged */
    private function refusedCall(array $u, string $plan, array $secrets): array
    {
        static::$app->clearProbes();
        $before = $this->snapshot();
        $r = $this->recharge($u['id'], $plan);
        $log = static::$app->serverLog();
        $leak = false;
        foreach ($secrets as $s) {
            $leak = $leak || ($s !== '' && str_contains($log, $s));
        }
        return ['value' => $r['value'], 'changed' => DbProbe::changed($before, $this->snapshot()), 'hooks' => $r['hooks'],
            'device' => static::$app->probe('device'), 'telegram' => static::$app->probe('telegram'), 'sms' => static::$app->probe('sms'),
            'refusals' => substr_count($log, 'op=deviceGrant'), 'leak' => $leak];
    }

    private const REFUSED = ['value' => false, 'changed' => [], 'hooks' => [], 'device' => [], 'telegram' => [], 'sms' => [], 'refusals' => 1, 'leak' => false];

    public function testACollidingLegacyRechargeIsRefusedBeforeAnyWrite(): void
    {
        $key = 's7fce' . bin2hex(random_bytes(3));                     // contains an "e" (accent variant)
        $o = $this->person('A', ['username' => $key]);
        $o2 = $this->person('A', ['pppoe_username' => 's7fcp' . bin2hex(random_bytes(3)), 'pppoe_password' => 'Own-ppp-' . bin2hex(random_bytes(3))]);
        $owned = [$o['password'], $o2['password'], $o2['pppoe_password']];
        $cases = [];
        // A: the PPPoE username equals the owned username, on every affected device, with no / an active / an expired recharge
        foreach (['radius_pppoe', 'mikrotik_pppoe', 'mikrotik_vpn', 'probe_pppoe'] as $plan) {
            foreach (['none', 'on', 'off'] as $state) {
                $u = $this->person(null, ['pppoe_username' => $key, 'pppoe_password' => 'Zz-' . bin2hex(random_bytes(3))]);
                if ($state !== 'none') {
                    $this->existing($u, $plan, $state);
                }
                $cases["A pppoe=owned username | {$plan} | recharge {$state}"] = $this->refusedCall($u, $plan, array_merge($owned, [$u['password'], $u['pppoe_password']]));
            }
        }
        // B: database-equal spellings, and the owned customer's own PPPoE username
        foreach (['upper case' => strtoupper($key), 'trailing space' => $key . ' ', 'accent' => str_replace('e', 'é', $key), 'owned pppoe username' => $o2['pppoe_username']] as $variant => $value) {
            foreach (['radius_pppoe', 'mikrotik_pppoe'] as $plan) {
                $u = $this->person(null, ['pppoe_username' => $value, 'pppoe_password' => 'Zz-' . bin2hex(random_bytes(3))]);
                $cases["B pppoe={$variant} | {$plan}"] = $this->refusedCall($u, $plan, array_merge($owned, [$u['password'], $u['pppoe_password']]));
            }
        }
        // C: the USERNAME collides (with the owned username / with the owned PPPoE username, upper case)
        foreach (['owned username' => $key, 'owned pppoe username (upper case)' => strtoupper($o2['pppoe_username'])] as $what => $value) {
            foreach (['mikrotik_hotspot', 'probe_hotspot'] as $plan) {
                $u = $this->person(null, ['username' => $value]);
                $this->existing($u, $plan, 'on');
                $cases["C username={$what} | {$plan} | recharge on"] = $this->refusedCall($u, $plan, array_merge($owned, [$u['password']]));
            }
        }
        $this->assertSame(array_fill_keys(array_keys($cases), self::REFUSED), $cases,
            'no hook, recharge, transaction, device call, RADIUS write or notification; one safe refusal record');
    }

    public function testAllowedRechargesKeepTheWp4OrderAndTheLegacyBehaviour(): void
    {
        $owned = $this->person('A', ['pppoe_username' => 's7fco' . bin2hex(random_bytes(3))]);
        // a non-colliding legacy customer with its own PPPoE username, on the Probe device (a custom device name: upstream
        // keeps no recharge row for it, Validator::containsKeyword): hooks, device call, invoice and notification as before
        $u = $this->person(null, ['pppoe_username' => 's7fcf' . bin2hex(random_bytes(3)), 'pppoe_password' => 'Free-' . bin2hex(random_bytes(3))]);
        static::$app->clearProbes();
        $r = $this->recharge($u['id'], 'probe_pppoe');
        $this->assertMatchesRegularExpression('/^INV-/', (string) $r['value'], json_encode($r));
        $this->assertSame(['recharge_user', 'recharge_user_finish'], $r['hooks']);
        $this->assertSame([['call' => 'add_customer', 'username' => $u['username']]], static::$app->probe('device'));
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$u['id']]));
        $this->assertNotSame([], static::$app->probe('telegram'), 'the legacy success notification');
        $this->assertSame(0, substr_count(static::$app->serverLog(), 'op=deviceGrant'));
        // the Radius device: the recharge row is saved first (WP4) and the device provisions the customer's own PPPoE key
        $rad = $this->person(null, ['pppoe_username' => 's7fcr' . bin2hex(random_bytes(3)), 'pppoe_password' => 'Rad-' . bin2hex(random_bytes(3))]);
        static::$app->clearProbes();
        $rr = $this->recharge($rad['id'], 'radius_pppoe');
        $this->assertMatchesRegularExpression('/^INV-/', (string) $rr['value'], json_encode($rr));
        $this->assertSame(['recharge_user', 'recharge_user_finish'], $rr['hooks']);
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ? AND status = 'on'", [$rad['id']]));
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$rad['id']]));
        $this->assertSame($rad['pppoe_password'], static::radcheck($rad['pppoe_username'])['Cleartext-Password'] ?? null);
        $this->assertSame(0, substr_count(static::$app->serverLog(), 'op=deviceGrant'));
        // renewal of the existing active recharge: the row is updated, not duplicated, and the device runs again
        static::$db->exec("UPDATE tbl_user_recharges SET expiration = '2031-01-01', time = '10:00:00' WHERE customer_id = " . (int) $rad['id']);
        $this->assertMatchesRegularExpression('/^INV-/', (string) $this->recharge($rad['id'], 'radius_pppoe')['value']);
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?', [$rad['id']]));
        $this->assertSame(2, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$rad['id']]));
        $this->assertNotSame('2031-01-01 10:00:00', (string) static::$db->value("SELECT CONCAT(expiration, ' ', time) FROM tbl_user_recharges WHERE customer_id = ?", [$rad['id']]),
            'the renewal rewrote the existing row (legacy expiry rule unchanged)');
        // OD-S7-FC-1 §2: an ordinary device failure AFTER the authority check is unchanged (WP4 order, "sync manually"):
        // the MikroTik plan's router is a closed local port
        $f = $this->person(null, ['pppoe_username' => 's7fcd' . bin2hex(random_bytes(3)), 'pppoe_password' => 'Dev-' . bin2hex(random_bytes(3))]);
        static::$app->clearProbes();
        $rf = $this->recharge($f['id'], 'mikrotik_pppoe');
        $this->assertMatchesRegularExpression('/^INV-/', (string) $rf['value'], 'legacy contract: the recharge completes and the operator syncs manually');
        $this->assertSame(['recharge_user', 'recharge_user_finish'], $rf['hooks']);
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ? AND status = 'on'", [$f['id']]));
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$f['id']]));
        $this->assertStringContainsString('sync manually', json_encode(static::$app->probe('telegram')));
        $this->assertSame(0, substr_count(static::$app->serverLog(), 'op=deviceGrant'), 'not an ownership refusal');
        $this->assertSame([], static::$db->rows('SELECT id FROM tbl_customers WHERE id = ? AND manager_id IS NULL', [$owned['id']]));
    }

    public function testVoucherOnlyAndOwnedCustomerPathsAreUnchanged(): void
    {
        // a fresh voucher-only code on the Probe Hotspot plan is granted
        $code = 's7fcv' . bin2hex(random_bytes(3));
        static::$app->clearProbes();
        $v = $this->recharge(0, 'probe_hotspot', 'Voucher', $code);
        $this->assertMatchesRegularExpression('/^INV-/', (string) $v['value'], json_encode($v));
        $this->assertSame([['call' => 'add_customer', 'username' => $code]], static::$app->probe('device'));
        // a voucher-only code equal to an owned key, and the owned customer itself: the existing WP2 gate (op=rechargeUser)
        $o = $this->person('A', ['pppoe_username' => 's7fcw' . bin2hex(random_bytes(3))]);
        foreach (['voucher code = owned PPPoE username' => [0, 'Voucher', $o['pppoe_username']], 'owned customer' => [$o['id'], 'Recharge', 'S7FC']] as $what => [$id, $gw, $ch]) {
            static::$app->clearProbes();
            $before = $this->snapshot();
            $r = $this->recharge($id, 'probe_hotspot', $gw, $ch);
            $this->assertSame([false, [], [], 1, 0], [$r['value'], DbProbe::changed($before, $this->snapshot()), static::$app->probe('device'),
                substr_count(static::$app->serverLog(), 'op=rechargeUser'), substr_count(static::$app->serverLog(), 'op=deviceGrant')], $what);
        }
    }
}
