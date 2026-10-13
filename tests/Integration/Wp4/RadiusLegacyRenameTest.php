<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP8 A3 rename integrity on the LEGACY path (R2: customers/edit-post for an unassigned customer, Radius device; plan
 * rev. 3 §R2 F-3 / F-4, owner OD-A3R-9 / 10):
 *  - a username rename across several Radius plans (Hotspot on the username, PPPoE on its own key): the username pair is
 *    applied once and the result equals a fresh legacy sync (repeated, it deleted the rows the first plan had written);
 *  - an ambiguous rename (another RADIUS key equivalent to the old or the new name) is refused before the customer row
 *    is saved: nothing changes in the application or in RADIUS.
 */
final class RadiusLegacyRenameTest extends RadiusHttpTestCase
{
    private static int $hotspot;
    private static int $pppoe;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'a3_ren', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 2, 'rate_up_unit' => 'Mbps']);
        $plan = static fn(string $type, string $pool): int => static::$db->insertRow('tbl_plans', [
            'name_plan' => 'a3_ren_' . strtolower($type), 'id_bw' => $bw, 'price' => '1000', 'type' => $type, 'typebp' => 'Unlimited',
            'limit_type' => 'Time_Limit', 'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB',
            'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1, 'routers' => 'radius', 'is_radius' => 1,
            'pool' => $pool, 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        self::$hotspot = $plan('Hotspot', '');
        self::$pppoe = $plan('PPPOE', 'nuxr-pool');
    }

    /** an unassigned customer with active Hotspot (username key) and PPPoE (own PPPoE username key) recharges, provisioned */
    private function legacyCustomer(string $username): int
    {
        $id = static::$db->insertRow('tbl_customers', [
            'username' => $username, 'pppoe_username' => 'p' . $username, 'password' => 'pw-' . $username, 'fullname' => 'A3 ' . $username, 'address' => '', 'phonenumber' => '0700',
            'email' => $username . '@example.invalid', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active',
            'balance' => 0, 'created_by' => 1, 'auto_renewal' => 0,
        ]);
        foreach ([[self::$hotspot, 'Hotspot'], [self::$pppoe, 'PPPOE']] as [$plan, $type]) {
            static::$db->insertRow('tbl_user_recharges', [
                'customer_id' => $id, 'username' => $username, 'plan_id' => $plan, 'namebp' => 'a3', 'recharged_on' => gmdate('Y-m-d'),
                'recharged_time' => '00:00:00', 'expiration' => '2031-01-01', 'time' => '10:00:00', 'status' => 'on', 'method' => 'fixture',
                'routers' => 'radius', 'type' => $type, 'admin_id' => 1,
            ]);
        }
        $this->sync($id);
        return $id;
    }

    private function sync(int $id): void
    {
        $r = $this->staff('sa')->postWithToken('customers/sync/' . $id, []);
        $this->assertContains($r->status, [200, 302], $r->excerpt());
    }

    private function form(int $id, string $username): array
    {
        $row = static::$db->rows('SELECT fullname, email, address, phonenumber FROM tbl_customers WHERE id = ?', [$id])[0];
        return ['id' => $id, 'username' => $username, 'fullname' => $row['fullname'], 'account_type' => 'Personal', 'password' => '',
            'pppoe_username' => (string) static::$db->value('SELECT pppoe_username FROM tbl_customers WHERE id = ?', [$id]), 'pppoe_password' => '', 'pppoe_ip' => '', 'email' => $row['email'], 'address' => (string) $row['address'],
            'phonenumber' => $row['phonenumber'], 'service_type' => 'Hotspot', 'coordinates' => '', 'status' => 'Active'];
    }

    /** @return list<string> the policy rows of one EXACT key, sorted */
    private static function exact(string $key): array
    {
        $out = [];
        foreach (['radcheck', 'radreply'] as $t) {
            foreach (static::$db->rows("SELECT HEX(username) AS h, attribute, op, value FROM {$t}") as $r) {
                if (strtolower($r['h']) === bin2hex($key)) {
                    $out[] = "{$t}|{$r['attribute']}|{$r['op']}|{$r['value']}";
                }
            }
        }
        foreach (static::$db->rows('SELECT HEX(username) AS h, groupname, priority FROM radusergroup') as $r) {
            if (strtolower($r['h']) === bin2hex($key)) {
                $out[] = "radusergroup|{$r['groupname']}|{$r['priority']}";
            }
        }
        sort($out);
        return $out;
    }

    private static function radiusChecksum(): string
    {
        $rows = [];
        foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
            foreach (static::$db->rows("SELECT * FROM {$t} ORDER BY id") as $r) {
                $rows[] = $t . json_encode($r);
            }
        }
        return hash('sha256', implode("\n", $rows));
    }

    public function testUsernameRenameAcrossSeveralPlansRunsOnceAndEqualsAFreshSync(): void
    {
        $old = 'rl' . bin2hex(random_bytes(4));
        $id = $this->legacyCustomer($old);
        $this->assertNotSame([], self::exact($old));
        $new = 'rm' . bin2hex(random_bytes(4));
        $r = $this->staff('sa')->postWithToken('customers/edit-post', $this->form($id, $new));
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame($new, static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$id]));
        $this->assertSame([], self::exact($old), 'nothing under the old exact key');
        $after = self::exact($new);
        $this->assertNotSame([], $after);
        $this->sync($id);
        $this->assertSame($after, self::exact($new), 'a fresh legacy sync changes nothing: the rename lost nothing');
    }

    public function testAmbiguousRenameIsRefusedBeforeTheCustomerIsSaved(): void
    {
        foreach (['old', 'new'] as $where) {
            $old = 'rx' . bin2hex(random_bytes(4));
            $id = $this->legacyCustomer($old);
            $new = 'ry' . bin2hex(random_bytes(4));
            static::$db->insertRow('radcheck', ['username' => strtoupper($where === 'old' ? $old : $new), 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'foreign']);
            $radius = self::radiusChecksum();
            $r = $this->staff('sa')->postWithToken('customers/edit-post', $this->form($id, $new));
            $this->assertContains($r->status, [200, 302], $r->excerpt());
            $this->assertSame($old, static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$id]), "{$where}: refused before the save");
            $this->assertSame($radius, self::radiusChecksum(), "{$where}: RADIUS untouched");
            static::$db->exec("DELETE FROM radcheck WHERE value = 'foreign'");
        }
    }

    // ------------------------------------------------------------------ WP8 A3 final remediation, owner D-3 (R2)

    /** @return array<string,string> the identity and password columns of a customer */
    private static function row(int $id): array
    {
        return static::$db->rows('SELECT username, password, pppoe_username, pppoe_password FROM tbl_customers WHERE id = ?', [$id])[0];
    }

    public function testPppoeUsernameHeldByAnotherCustomerIsRefusedBeforeTheSave(): void
    {
        $xName = 'rv' . bin2hex(random_bytes(4));
        $x = $this->legacyCustomer($xName);
        $y = $this->legacyCustomer('rw' . bin2hex(random_bytes(4)));
        $yName = (string) static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$y]);
        foreach ([$xName, strtoupper($xName), 'p' . $xName] as $taken) {
            $before = [self::row($x), self::row($y), self::radiusChecksum(), self::exact($xName), self::exact('p' . $xName)];
            $form = ['pppoe_username' => $taken] + $this->form($y, $yName);
            $r = $this->staff('sa')->postWithToken('customers/edit-post', $form);
            $this->assertContains($r->status, [200, 302], $r->excerpt());
            $this->assertSame($before, [self::row($x), self::row($y), self::radiusChecksum(), self::exact($xName), self::exact('p' . $xName)],
                "{$taken}: refused before the save; the other customer's identity, password and policy are unchanged");
        }
    }

    public function testAPolicyUnderTheNewNameThatIsNotTheCustomersIsRefusedBeforeTheSave(): void
    {
        $old = 'ru' . bin2hex(random_bytes(4));
        $id = $this->legacyCustomer($old);
        $new = 'rt' . bin2hex(random_bytes(4));
        static::$db->insertRow('radcheck', ['username' => $new, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'orphan']);
        $before = [self::row($id), self::radiusChecksum(), self::exact($new)];
        $r = $this->staff('sa')->postWithToken('customers/edit-post', $this->form($id, $new));
        $this->assertContains($r->status, [200, 302], $r->excerpt());
        $this->assertSame($before, [self::row($id), self::radiusChecksum(), self::exact($new)], 'refused before the save; the existing policy is byte-identical');
        static::$db->exec("DELETE FROM radcheck WHERE value = 'orphan'");
    }

    /** R-4: numeric-looking names compare strictly (loosely "100" == "1e2"); the collision is still refused */
    public function testANumericLookingPppoeUsernameIsComparedStrictly(): void
    {
        $k = (string) random_int(1000, 9999);
        $x = $this->legacyCustomer('rn' . bin2hex(random_bytes(4)));
        static::$db->exec("UPDATE tbl_customers SET username = '" . $k . "e2' WHERE id = " . $x);
        $y = $this->legacyCustomer('rm' . bin2hex(random_bytes(4)));
        static::$db->exec("UPDATE tbl_customers SET pppoe_username = '" . $k . "00' WHERE id = " . $y);
        $yName = (string) static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$y]);
        $before = [self::row($x), self::row($y), self::radiusChecksum()];
        $r = $this->staff('sa')->postWithToken('customers/edit-post', ['pppoe_username' => $k . 'e2'] + $this->form($y, $yName));
        $this->assertContains($r->status, [200, 302], $r->excerpt());
        $this->assertSame($before, [self::row($x), self::row($y), self::radiusChecksum()], 'refused: "' . $k . '00" -> "' . $k . 'e2" is a change, and the name is taken');
    }
}
