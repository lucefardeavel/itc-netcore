<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp9Evidence.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 T1-A file 2 - CHARACTERISATION of the application-runtime identity paths before any fix (owner 2026-10-15).
 * Disposable application copy + disposable database, synthetic data only, no FreeRADIUS process, no device (the
 * voucher grants of P3 use the seed Probe plan; the one RADIUS grant of V2 is a fresh voucher code that collides
 * with nothing). No case here writes RADIUS rows over an existing identity: the overwrite-capable cases (V1, V4,
 * reverse direction) are separate files run afterwards, so the owner stop rule can apply at the first proof.
 *   P2 register/post (public), P3 voucher + new username, P4 Admin customers/edit-post of an unassigned customer,
 *   V3 voucher code = an owned customer's name, V2 a customer created over a live voucher-only identity,
 *   bounded concurrency P1 x P3 (two processes per round, 3 rounds).
 */
final class T1aLegacyPathsTest extends RadiusHttpTestCase
{
    use Wp9Evidence;

    private static int $radiusHotspot = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $seed = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seed['id']);
        self::$radiusHotspot = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9_radius_hotspot', 'type' => 'Hotspot', 'device' => 'Radius',
            'routers' => 'radius', 'is_radius' => 1] + $seed);
    }

    private function person(?string $manager, array $extra = []): array
    {
        $u = 'w9lp' . bin2hex(random_bytes(3));
        $row = $extra + ['username' => $u, 'password' => 'Pw-' . bin2hex(random_bytes(4)), 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '',
            'fullname' => 'W9 ' . $u, 'address' => '', 'phonenumber' => '0700', 'email' => $u . '@example.invalid', 'service_type' => 'Hotspot',
            'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active', 'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0,
            'manager_id' => $manager !== null ? $this->sid($manager) : null];
        $row['id'] = static::$db->insertRow('tbl_customers', $row);
        return $row;
    }

    private function voucher(string $code, int $plan, string $routers): int
    {
        return static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => $routers, 'id_plan' => $plan, 'code' => $code,
            'user' => '0', 'status' => '0', 'generated_by' => $this->sid('ad')]);
    }

    /** customers whose username or pppoe_username equals $name under the column comparison */
    private function holders(string $name): array
    {
        return array_map('intval', array_column(static::$db->rows('SELECT id FROM tbl_customers WHERE username = ? OR pppoe_username = ?', [$name, $name]), 'id'));
    }

    private function run1(string $label, callable $fn, array $extra = []): array
    {
        $before = self::state(static::$db);
        $out = $fn();
        return ['outcome' => $out, 'state_delta' => self::delta($before, self::state(static::$db))] + $extra;
    }

    public function testP2PublicRegistrationCurrentBehaviour(): void
    {
        $v = $this->person('C', ['pppoe_username' => 'w9p2pp' . bin2hex(random_bytes(2))]);
        $cases = [
            'fresh name (positive control)' => ['w9p2ok' . bin2hex(random_bytes(2)), 'created'],
            'username = victim username (upper case)' => [strtoupper($v['username']), 'refused'],
            'username = victim pppoe_username' => [$v['pppoe_username'], 'collision'],
        ];
        foreach ($cases as $name => [$username, $kind]) {
            $n0 = count($this->holders($username));
            $r = $this->run1($name, function () use ($username): string {
                $res = $this->client()->postWithToken('register/post', ['username' => $username, 'fullname' => 'W9 Reg', 'email' => 'w9reg@example.invalid',
                    'password' => 'Pw-w9reg', 'cpassword' => 'Pw-w9reg', 'address' => 'x', 'phone_number' => '0700'], [], 'register');
                return 'HTTP ' . $res->status;
            });
            $created = count($this->holders($username)) > $n0;
            $data = ['input' => $username, 'created' => $created] + $r;
            if ($kind === 'created') {
                $this->assertTrue($created, "control {$name}: " . json_encode($r));
                self::record("P2 {$name}", 'CONTROL_PASS', $data);
            } elseif ($kind === 'refused') {
                $this->assertFalse($created, "control {$name}");
                $this->assertSame([], $r['state_delta']['business'], "control {$name}: no business change");
                self::record("P2 {$name}", 'CONTROL_PASS', $data);
            } else {
                self::record("P2 {$name}", $created ? 'DEFECT_REPRODUCED' : 'OBSERVED', $data);
            }
        }
    }

    public function testP3VoucherWithNewUsernameCurrentBehaviour(): void
    {
        $v = $this->person('C', ['pppoe_username' => 'w9p3pp' . bin2hex(random_bytes(2))]);
        $lead = $this->person(null, ['username' => ' w9p3lead' . bin2hex(random_bytes(2))]);
        $cases = [
            'fresh name (positive control)' => ['w9p3ok' . bin2hex(random_bytes(2)), 'GRANTED'],
            'name = victim pppoe_username' => [$v['pppoe_username'], 'USERNAME_TAKEN'],
            'name = victim username (upper case)' => [strtoupper($v['username']), 'USERNAME_TAKEN'],
            'name = trimmed form of a stored leading-space username' => [ltrim($lead['username']), null],
        ];
        foreach ($cases as $name => [$username, $expect]) {
            $code = 'W9P3' . strtoupper(bin2hex(random_bytes(4)));
            $this->voucher($code, static::$seed->planId, 'wp2_router');
            $r = $this->run1($name, fn() => static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeemForNewUsername(%s, %s, \Reseller\Domain\RedemptionSource::public())["status"];',
                var_export($code, true), var_export($username, true))));
            $data = ['input' => $username] + $r;
            if ($expect !== null) {
                $this->assertSame($expect, $r['outcome'], "control {$name}");
                if ($expect !== 'GRANTED') {
                    $this->assertSame([], $r['state_delta']['business'], "control {$name}: a refusal changes no business state");
                }
                self::record("P3 {$name}", 'CONTROL_PASS', $data);
            } else {
                self::record("P3 {$name}", $r['outcome'] === 'GRANTED' ? 'DEFECT_REPRODUCED' : 'OBSERVED',
                    $data + ['detail' => 'the new customer name equals the stored leading-space name after trim() (one RADIUS key)']);
            }
        }
    }

    public function testP4AdminEditOfAnUnassignedCustomerCurrentBehaviour(): void
    {
        $v = $this->person('C', ['pppoe_username' => 'w9p4pp' . bin2hex(random_bytes(2))]);
        $lead = $this->person(null, ['username' => ' w9p4lead' . bin2hex(random_bytes(2))]);
        $sa = $this->staff('sa');
        $cases = [
            'pppoe_username = a distinct name (positive control)' => [['pppoe_username' => 'w9p4free' . bin2hex(random_bytes(2))], 'changed'],
            'pppoe_username = victim username' => [['pppoe_username' => 'VICTIM_USERNAME'], 'unchanged'],
            'username = victim pppoe_username' => [['username' => 'VICTIM_PPPOE'], 'unchanged'],
            'pppoe_username = trimmed form of a stored leading-space username' => [['pppoe_username' => ltrim($lead['username'])], null],
            'case-only rename of its own username' => [['username' => 'SELF_UPPER'], null],
        ];
        foreach ($cases as $name => [$change, $expect]) {
            $u = $this->person(null);
            $change = array_map(static fn(string $x): string => match ($x) {
                'VICTIM_USERNAME' => $v['username'], 'VICTIM_PPPOE' => $v['pppoe_username'], 'SELF_UPPER' => strtoupper($u['username']), default => $x,
            }, $change);
            $form = ['id' => $u['id'], 'username' => $u['username'], 'fullname' => $u['fullname'], 'account_type' => 'Personal', 'password' => '',
                'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '', 'email' => $u['email'], 'address' => '', 'phonenumber' => $u['phonenumber'],
                'service_type' => 'Hotspot', 'coordinates' => '', 'status' => 'Active'];
            $r = $this->run1($name, fn() => 'HTTP ' . $sa->postWithToken('customers/edit-post', $change + $form)->status);
            $row = static::$db->rows('SELECT username, pppoe_username FROM tbl_customers WHERE id = ?', [$u['id']])[0];
            $changed = $row['username'] !== $u['username'] || $row['pppoe_username'] !== $u['pppoe_username'];
            $data = ['change' => $change, 'stored_after' => $row, 'changed' => $changed] + $r;
            if ($expect === 'changed') {
                $this->assertTrue($changed, "control {$name}: " . json_encode($r));
                self::record("P4 {$name}", 'CONTROL_PASS', $data);
            } elseif ($expect === 'unchanged') {
                $this->assertFalse($changed, "control {$name}");
                $this->assertSame([], array_values(array_diff($r['state_delta']['business'], [])), "control {$name}: no business change");
                self::record("P4 {$name}", 'CONTROL_PASS', $data);
            } elseif (str_starts_with($name, 'case-only')) {
                self::record("P4 {$name}", $changed ? 'OBSERVED' : 'OBSERVED', $data + ['detail' => $changed ? 'self-rename by case accepted' : 'FALSE REFUSAL: the username check does not exclude the customer itself (customers.php:716-722)']);
            } else {
                self::record("P4 {$name}", $changed ? 'DEFECT_REPRODUCED' : 'OBSERVED', $data + ['detail' => 'equal to the stored leading-space name after trim() (one RADIUS key)']);
            }
        }
    }

    public function testV3VoucherCodeEqualToAnOwnedNameIsRefused(): void
    {
        $o = $this->person('A', ['pppoe_username' => 'w9v3pp' . bin2hex(random_bytes(2))]);
        // positive control: a fresh voucher-only code on the RADIUS Hotspot plan is granted
        $fresh = 'w9v3ok' . bin2hex(random_bytes(3));
        $this->voucher($fresh, self::$radiusHotspot, 'radius');
        $ok = $this->run1('fresh', fn() => static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, \Reseller\Domain\RedemptionTarget::voucherOnly(%s), \Reseller\Domain\RedemptionSource::public())["status"];',
            var_export($fresh, true), var_export($fresh, true))));
        $this->assertSame('GRANTED', $ok['outcome'], 'control: a fresh voucher-only code is granted ' . json_encode($ok));
        $this->assertNotEmpty(self::radRows(static::$db, $fresh), 'control: the grant wrote the voucher identity to radcheck');
        self::record('V3 fresh voucher-only code (positive control)', 'CONTROL_PASS', $ok + ['radcheck_rows' => count(self::radRows(static::$db, $fresh))]);
        foreach (['exact' => $o['pppoe_username'], 'upper case' => strtoupper($o['pppoe_username']), 'owned username' => $o['username']] as $label => $code) {
            $this->voucher($code, self::$radiusHotspot, 'radius');
            $radBefore = self::radRows(static::$db, $o['pppoe_username']);
            $r = $this->run1($label, fn() => static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, \Reseller\Domain\RedemptionTarget::voucherOnly(%s), \Reseller\Domain\RedemptionSource::public())["status"];',
                var_export($code, true), var_export($code, true))));
            $data = ['code' => $code] + $r + ['owned_radcheck_unchanged' => self::radRows(static::$db, $o['pppoe_username']) === $radBefore];
            $this->assertNotSame('GRANTED', $r['outcome'], "V3 {$label}: a voucher code equal to an owned name is never granted");
            $this->assertSame([], array_values(array_diff($r['state_delta']['business'], ['tbl_voucher'])), "V3 {$label}: no business change other than the voucher row");
            $this->assertTrue($data['owned_radcheck_unchanged']);
            self::record("V3 code = owned {$label}", 'CONTROL_PASS', $data);
        }
    }

    public function testV2CustomerCreatedOverALiveVoucherOnlyIdentity(): void
    {
        $code = 'w9v2' . bin2hex(random_bytes(3));
        $this->voucher($code, self::$radiusHotspot, 'radius');
        $g = static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, \Reseller\Domain\RedemptionTarget::voucherOnly(%s), \Reseller\Domain\RedemptionSource::public())["status"];',
            var_export($code, true), var_export($code, true)));
        $this->assertSame('GRANTED', $g, 'control: the live voucher-only identity exists');
        $rad = self::radRows(static::$db, $code);
        $this->assertNotEmpty($rad);
        foreach (['Admin P1 create, username = code (upper case)' => [strtoupper($code), ''], 'Admin P1 create, pppoe_username = code' => ['w9v2n' . bin2hex(random_bytes(2)), $code]] as $name => [$username, $pppoe]) {
            $r = $this->run1($name, fn() => static::$app->exec(sprintf(
                '$u = ORM::for_table("tbl_users")->where("username", "ad")->find_one()->as_array(); try { $result = "CREATED id=" . \Reseller\Domain\CustomerService::create(\Reseller\Security\ActorContext::forStaff($u, "WEB"), ["username" => %s, "pppoe_username" => %s, "password" => "Pw-w9v2", "fullname" => "W9 V2"]); } catch (\Reseller\Domain\DomainError $e) { $result = "REFUSED " . $e->reason; }',
                var_export($username, true), var_export($pppoe, true))));
            $data = ['input' => ['username' => $username, 'pppoe_username' => $pppoe]] + $r + ['voucher_radcheck_unchanged' => self::radRows(static::$db, $code) === $rad];
            $this->assertTrue($data['voucher_radcheck_unchanged'], "{$name}: creation never writes RADIUS");
            self::record("V2 {$name}", str_starts_with((string) $r['outcome'], 'CREATED') ? 'DEFECT_REPRODUCED' : 'OBSERVED',
                $data + ['detail' => 'creation does not check live voucher-only identities (same radcheck key under the collation)']);
        }
    }

    public function testBoundedConcurrentCreationP1VersusP3(): void
    {
        $rounds = [];
        for ($i = 0; $i < 3; $i++) {
            $name = 'w9cc' . bin2hex(random_bytes(3));
            $code = 'W9CC' . strtoupper(bin2hex(random_bytes(4)));
            $this->voucher($code, static::$seed->planId, 'wp2_router');
            $p1 = static::$app->execStart(sprintf('$u = ORM::for_table("tbl_users")->where("username", "ad")->find_one()->as_array(); try { $result = "CREATED"; \Reseller\Domain\CustomerService::create(\Reseller\Security\ActorContext::forStaff($u, "WEB"), ["username" => %s, "password" => "Pw-w9cc", "fullname" => "W9 CC"]); } catch (\Reseller\Domain\DomainError $e) { $result = "REFUSED " . $e->reason; }',
                var_export($name, true)));
            $p3 = static::$app->execStart(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeemForNewUsername(%s, %s, \Reseller\Domain\RedemptionSource::public())["status"];',
                var_export($code, true), var_export(strtoupper($name), true)));
            $a = static::$app->execFinish($p1);
            $b = static::$app->execFinish($p3);
            $holders = $this->holders($name);
            $rounds[] = ['name' => $name, 'P1' => $a, 'P3' => $b, 'customers_with_the_name' => count($holders)];
            $this->assertGreaterThanOrEqual(1, count($holders), 'control: at least one creation succeeded');
        }
        $both = array_values(array_filter($rounds, static fn(array $r): bool => $r['customers_with_the_name'] > 1));
        self::record('concurrency P1 x P3 (2 processes, 3 rounds)', $both !== [] ? 'DEFECT_REPRODUCED' : 'OBSERVED',
            ['rounds' => $rounds, 'detail' => $both !== [] ? 'both creations succeeded in at least one round (race)' : 'one creation per round in these 3 rounds; not a proof of safety (P1 and P3 take different locks)']);
    }
}
