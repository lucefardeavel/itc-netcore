<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8DriverLabCase.php';

use Reseller\Tests\Support\Wp8DriverLabCase;
use Reseller\Tests\Support\Wp8Lab;

/**
 * WP8 S7 contract tests of the direct MikroTik drivers on the real, isolated RouterOS CHR 7.24.5 (lab only; incomplete,
 * never passed, without the lab). Local identities only (nuxr_wp8_local_*); RADIUS subscribers are never touched.
 *
 *   DC-X0 the temporary API credential is least privilege and sufficient (AM-S7-2)
 *   DC-H  Hotspot: plan profile add / update / remove; user add / sync / rename / remove / expiry (plan_expired)
 *   DC-H4 Hotspot: a real client logs in with a LOCAL user (no RADIUS request); online / disconnect / connect
 *   DC-P  PPPoE: profile and pool add / update / remove; secret add (static address) / sync / rename
 *   DC-P3 PPPoE: a real client dials with a LOCAL secret (no RADIUS request); online; removal; expiry (plan_expired)
 *   SEP   a RADIUS subscriber still authenticates through RADIUS, with exactly its own new accounting row (AM-S7-1)
 *   DC-V1 VPN at API level (OD-S7-2): secret + NAT rules created and removed, NAT isolation (AM-S7-3)
 *   DC-X1 RouterOS API unavailable; DC-X2 router address without ":port" (OBS-W8-4); DC-X3 a RouterOS !trap reply is
 *         ignored by the drivers (OBS-S7-1)
 * Driver tests must leave every RADIUS table byte-identical; the RouterOS inventory outside the S7 objects unchanged.
 */
final class DriverContractLabTest extends Wp8DriverLabCase
{
    private function ok(array $r, string $label): array
    {
        $this->assertTrue($r['ok'], $label . ': ' . json_encode($r));
        return $r;
    }

    // ------------------------------------------------------------------------------------------------- DC-X0

    public function testTheTemporaryApiCredentialIsLeastPrivilegeAndSufficient(): void
    {
        $group = Wp8Lab::ros('/user/group/print', '?name=' . self::API_GROUP)[0] ?? [];
        $user = Wp8Lab::ros('/user/print', '?name=' . self::API_USER)[0] ?? [];
        $granted = array_values(array_filter(explode(',', (string) ($group['policy'] ?? '')), static fn($p) => $p !== '' && !str_starts_with($p, '!')));
        sort($granted);
        $this->assertSame(['api', 'read', 'write'], $granted, 'least privilege: api, read, write only');
        $this->assertSame('10.88.0.10/32', $user['address'] ?? null, 'only the lab runner address');
        $probe = self::PREFIX . 'probe_' . bin2hex(random_bytes(2));
        // sufficiency: read, add, set, remove through the application's PEAR2 client with the temporary credential
        $r = $this->app(sprintf(<<<'PHP'
$r = ORM::for_table('tbl_routers')->where('name', '%s')->find_one();
$x = explode(':', $r['ip_address']);
$cl = new PEAR2\Net\RouterOS\Client($x[0], $r['username'], $r['password'], (int) $x[1]);
$run = static function ($cmd, array $args = []) use ($cl) {
    $q = new PEAR2\Net\RouterOS\Request($cmd);
    foreach ($args as $k => $v) { $q->setArgument($k, $v); }
    $out = [];
    foreach ($cl->sendSync($q) as $resp) { $out[] = $resp->getType(); }
    return implode(',', $out);
};
$res = [];
foreach (['/ppp/secret/print', '/ip/hotspot/user/print', '/ip/firewall/nat/print', '/ppp/active/print', '/ip/hotspot/active/print', '/ip/pool/print'] as $cmd) { $res[$cmd] = $run($cmd); }
$res['add'] = $run('/ppp/secret/add', ['name' => '%s', 'service' => 'pppoe', 'password' => 'x', 'comment' => 'nuxr_wp8_local_probe']);
$res['remove'] = $run('/ppp/secret/remove', ['numbers' => '%s']);
// least privilege: a user-management write needs the "policy" policy, which the group lacks
$res['user_add'] = $run('/user/add', ['name' => 'nuxr_wp8_local_denied', 'group' => 'read', 'password' => 'x' . bin2hex(random_bytes(6))]);
return $res;
PHP, self::ROUTER, $probe, $probe));
        $this->ok($r, 'credential probe');
        $v = $r['value'];
        foreach (['/ppp/secret/print', '/ip/hotspot/user/print', '/ip/firewall/nat/print', '/ppp/active/print', '/ip/hotspot/active/print', '/ip/pool/print'] as $cmd) {
            $this->assertStringNotContainsString('!trap', $v[$cmd], "{$cmd} readable");
        }
        $this->assertSame('!done', $v['add'], 'write allowed');
        $this->assertSame('!done', $v['remove'], 'remove allowed');
        $this->assertStringContainsString('!trap', $v['user_add'], 'no user management (least privilege)');
        $this->assertSame([], static::find('/user', 'nuxr_wp8_local_denied'));
        $this->assertSame([], static::find('/ppp/secret', $probe));
        static::evidence('DC-X0.credential', ['group_policy' => $group['policy'] ?? null, 'address' => $user['address'] ?? null, 'probe' => $v]);
    }

    // ---------------------------------------------------------------------------------------------- DC-H

    public function testHotspotPlanAndUserContracts(): void
    {
        $others = static::others();
        $radius = static::radiusChecksums();
        $plan = $this->plan('Hotspot', 'h');
        $exp = $this->plan('Hotspot', 'hexp');
        // DC-H1 profile add / update / remove
        $this->ok($this->app("(new MikrotikHotspot())->add_plan(\$p({$plan['id']})); (new MikrotikHotspot())->add_plan(\$p({$exp['id']}));"), 'add_plan');
        $prof = static::find('/ip/hotspot/user/profile', $plan['name'])[0] ?? null;
        $this->assertSame(['1M/2M', '1'], [$prof['rate-limit'] ?? null, $prof['shared-users'] ?? null], 'profile rate (up/down) and shared users');
        static::$db->exec('UPDATE tbl_bandwidth SET rate_down = 4 WHERE id = ' . static::$bw);
        $this->ok($this->app("\$o = \$p({$plan['id']}); \$n = \$p({$plan['id']}); \$n->shared_users = 2; (new MikrotikHotspot())->update_plan(\$o, \$n);"), 'update_plan');
        $prof = static::find('/ip/hotspot/user/profile', $plan['name'])[0] ?? null;
        $this->assertSame(['1M/4M', '2'], [$prof['rate-limit'] ?? null, $prof['shared-users'] ?? null]);
        static::$db->exec('UPDATE tbl_bandwidth SET rate_down = 2 WHERE id = ' . static::$bw);
        // DC-H2 user add (unassigned local customer) / sync, idempotent
        $u = $this->localCustomer('hs', null);
        $this->recharge($u, $plan, 'Hotspot');
        $this->ok($this->app("(new MikrotikHotspot())->add_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'add_customer');
        $users = static::find('/ip/hotspot/user', $u['username']);
        $this->assertCount(1, $users);
        $this->assertSame($plan['name'], $users[0]['profile']);
        $this->ok($this->app("(new MikrotikHotspot())->sync_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'sync_customer');
        $this->assertCount(1, static::find('/ip/hotspot/user', $u['username']), 'sync is idempotent (one user)');
        // DC-H3 rename (real-router variant of the WP5B 4A fix): the old name is gone
        $to = $u['username'] . 'r';
        $this->ok($this->app("(new MikrotikHotspot())->change_username(\$p({$plan['id']}), '{$u['username']}', '{$to}');"), 'change_username');
        $this->assertSame([], static::find('/ip/hotspot/user', $u['username']));
        $this->assertCount(1, static::find('/ip/hotspot/user', $to));
        static::$db->pdo->prepare('UPDATE tbl_customers SET username = ? WHERE id = ?')->execute([$to, $u['id']]);
        // DC-H5 expiry with an expired plan: the restricted profile, the user kept
        static::$db->exec("UPDATE tbl_plans SET plan_expired = {$exp['id']} WHERE id = {$plan['id']}");
        $this->ok($this->app("(new MikrotikHotspot())->remove_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'remove_customer (expired plan)');
        $this->assertSame($exp['name'], static::find('/ip/hotspot/user', $to)[0]['profile'] ?? null, 'expiry applies the expired plan');
        // DC-H5 removal without an expired plan
        static::$db->exec("UPDATE tbl_plans SET plan_expired = 0 WHERE id = {$plan['id']}");
        $this->ok($this->app("(new MikrotikHotspot())->remove_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'remove_customer');
        $this->assertSame([], static::find('/ip/hotspot/user', $to));
        $this->ok($this->app("(new MikrotikHotspot())->remove_plan(\$p({$plan['id']})); (new MikrotikHotspot())->remove_plan(\$p({$exp['id']}));"), 'remove_plan');
        $this->assertSame([], static::find('/ip/hotspot/user/profile', $plan['name']));
        $this->assertSame($others, static::others(), 'nothing outside the S7 objects changed');
        $this->assertSame($radius, static::radiusChecksums(), 'driver tests never touch RADIUS (AM-S7-1)');
        $this->assertNoRadiusOverlap([$u['username'], $to]);
        static::evidence('DC-H.contracts', ['plan' => $plan['name'], 'user' => $to, 'radius_unchanged' => true]);
    }

    public function testHotspotLocalLoginIsNotRadiusAndTheSessionMethodsWork(): void
    {
        $radius = static::radiusChecksums();
        $plan = $this->plan('Hotspot', 'hl');
        $u = $this->localCustomer('hsl', null);
        $this->ok($this->app("(new MikrotikHotspot())->add_plan(\$p({$plan['id']})); (new MikrotikHotspot())->add_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'provision');
        Wp8Lab::agent('hs_dhcp', ['client' => 'c2']);
        $login = Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $u['username'], 'password' => $u['password']]);
        $this->assertTrue($login['ok'], 'local Hotspot user logs in: ' . json_encode($login));
        $active = static::find('/ip/hotspot/active', $u['username'], 'user');
        $this->assertCount(1, $active);
        $this->assertSame([], static::radiusSaw($u['username']), 'RADIUS never consulted for a local user (local users mask RADIUS)');
        $online = $this->ok($this->app("return (new MikrotikHotspot())->online_customer(\$c({$u['id']}), '" . self::ROUTER . "');"), 'online_customer');
        $this->assertSame($active[0]['.id'], $online['value']);
        $this->ok($this->app("(new MikrotikHotspot())->disconnect_customer(\$c({$u['id']}), '" . self::ROUTER . "');"), 'disconnect_customer');
        $this->assertSame([], static::find('/ip/hotspot/active', $u['username'], 'user'));
        $this->ok($this->app(sprintf("(new MikrotikHotspot())->connect_customer(\$c(%d), '%s', '%s', '%s');", $u['id'], $active[0]['address'], $active[0]['mac-address'], self::ROUTER)), 'connect_customer');
        $this->assertCount(1, static::find('/ip/hotspot/active', $u['username'], 'user'), 'connect_customer logged the host in again');
        $this->ok($this->app("(new MikrotikHotspot())->remove_customer(\$c({$u['id']}), \$p({$plan['id']})); (new MikrotikHotspot())->remove_plan(\$p({$plan['id']}));"), 'removal');
        $this->assertSame([], static::find('/ip/hotspot/active', $u['username'], 'user'));
        $this->assertSame([], static::find('/ip/hotspot/user', $u['username']));
        Wp8Lab::agent('hs_logout', ['client' => 'c2']);
        $this->assertSame($radius, static::radiusChecksums(), 'a local login writes no RADIUS row (AM-S7-1)');
        static::evidence('DC-H4.local-login', ['user' => $u['username'], 'login' => $login['state'], 'radius_log_lines' => 0, 'address' => $active[0]['address']]);
    }

    // ---------------------------------------------------------------------------------------------- DC-P

    public function testPppoeProfilePoolAndSecretContracts(): void
    {
        $others = static::others();
        $radius = static::radiusChecksums();
        $pool = self::PREFIX . 'pool_' . bin2hex(random_bytes(2));
        static::$db->insertRow('tbl_pool', ['pool_name' => $pool, 'local_ip' => '10.88.250.1', 'range_ip' => '10.88.250.100-10.88.250.120', 'routers' => self::ROUTER]);
        $plan = $this->plan('PPPOE', 'p', ['pool' => $pool]);
        // DC-P1 pool add / update / remove; profile add / update / remove
        $poolRow = static::$db->rows('SELECT * FROM tbl_pool WHERE pool_name = ?', [$pool])[0];
        $this->ok($this->app("(new MikrotikPppoe())->add_pool(ORM::for_table('tbl_pool')->find_one({$poolRow['id']}));"), 'add_pool');
        $this->assertSame('10.88.250.100-10.88.250.120', static::find('/ip/pool', $pool)[0]['ranges'] ?? null);
        static::$db->exec("UPDATE tbl_pool SET range_ip = '10.88.250.100-10.88.250.130' WHERE id = {$poolRow['id']}");
        $this->ok($this->app("\$x = ORM::for_table('tbl_pool')->find_one({$poolRow['id']}); (new MikrotikPppoe())->update_pool(\$x, \$x);"), 'update_pool');
        $this->assertSame('10.88.250.100-10.88.250.130', static::find('/ip/pool', $pool)[0]['ranges'] ?? null);
        $this->ok($this->app("(new MikrotikPppoe())->add_plan(\$p({$plan['id']}));"), 'add_plan');
        $prof = static::find('/ppp/profile', $plan['name'])[0] ?? null;
        $this->assertSame(['10.88.250.1', $pool, '1M/2M'], [$prof['local-address'] ?? null, $prof['remote-address'] ?? null, $prof['rate-limit'] ?? null]);
        static::$db->exec('UPDATE tbl_bandwidth SET rate_up = 3 WHERE id = ' . static::$bw);
        $this->ok($this->app("(new MikrotikPppoe())->update_plan(\$p({$plan['id']}), \$p({$plan['id']}));"), 'update_plan');
        $this->assertSame('3M/2M', static::find('/ppp/profile', $plan['name'])[0]['rate-limit'] ?? null);
        static::$db->exec('UPDATE tbl_bandwidth SET rate_up = 1 WHERE id = ' . static::$bw);
        // DC-P2 secret with a PPPoE username and a static address; sync; rename
        $u = $this->localCustomer('pp', null, ['pppoe_ip' => '10.88.250.140']);
        $key = $u['username'] . 'k';
        static::$db->pdo->prepare('UPDATE tbl_customers SET pppoe_username = ?, pppoe_password = ? WHERE id = ?')->execute([$key, 'Pk' . substr($u['password'], 2), $u['id']]);
        $this->ok($this->app("(new MikrotikPppoe())->add_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'add_customer');
        $s = static::find('/ppp/secret', $key);
        $this->assertCount(1, $s);
        $this->assertSame(['pppoe', $plan['name'], '10.88.250.140'], [$s[0]['service'], $s[0]['profile'], $s[0]['remote-address'] ?? null], 'the PPPoE username is the secret name');
        $this->assertSame([], static::find('/ppp/secret', $u['username']), 'not the account username');
        static::$db->exec("UPDATE tbl_customers SET pppoe_ip = '' WHERE id = {$u['id']}");
        $this->ok($this->app("(new MikrotikPppoe())->sync_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'sync_customer');
        $s = static::find('/ppp/secret', $key);
        $this->assertCount(1, $s, 'sync updates the same secret');
        $this->assertArrayNotHasKey('remote-address', array_filter($s[0], static fn($v) => $v !== ''), 'the static address is unset');
        $to = $key . 'r';
        $this->ok($this->app("(new MikrotikPppoe())->change_username(\$p({$plan['id']}), '{$key}', '{$to}');"), 'change_username');
        $this->assertSame([], static::find('/ppp/secret', $key));
        $this->assertCount(1, static::find('/ppp/secret', $to));
        static::$db->pdo->prepare('UPDATE tbl_customers SET pppoe_username = ? WHERE id = ?')->execute([$to, $u['id']]);
        $this->ok($this->app("(new MikrotikPppoe())->remove_customer(\$c({$u['id']}), \$p({$plan['id']})); (new MikrotikPppoe())->remove_plan(\$p({$plan['id']})); (new MikrotikPppoe())->remove_pool(ORM::for_table('tbl_pool')->find_one({$poolRow['id']}));"), 'removal');
        $this->assertSame([], static::find('/ppp/secret', $to));
        $this->assertSame([], static::find('/ppp/profile', $plan['name']));
        $this->assertSame([], static::find('/ip/pool', $pool));
        $this->assertSame($others, static::others());
        $this->assertSame($radius, static::radiusChecksums(), 'driver tests never touch RADIUS (AM-S7-1)');
        $this->assertNoRadiusOverlap([$u['username'], $key, $to]);
        static::evidence('DC-P.contracts', ['pool' => $pool, 'profile' => $plan['name'], 'secret' => $to]);
    }

    public function testPppoeLocalDialIsNotRadiusAndRemovalAndExpiryWork(): void
    {
        $radius = static::radiusChecksums();
        $pool = self::PREFIX . 'pool_' . bin2hex(random_bytes(2));
        $poolId = static::$db->insertRow('tbl_pool', ['pool_name' => $pool, 'local_ip' => '10.88.250.1', 'range_ip' => '10.88.250.100-10.88.250.120', 'routers' => self::ROUTER]);
        $plan = $this->plan('PPPOE', 'pd', ['pool' => $pool]);
        $exp = $this->plan('PPPOE', 'pdexp', ['pool' => $pool]);
        $u = $this->localCustomer('ppd', null);
        $this->ok($this->app("(new MikrotikPppoe())->add_pool(ORM::for_table('tbl_pool')->find_one({$poolId})); (new MikrotikPppoe())->add_plan(\$p({$plan['id']})); (new MikrotikPppoe())->add_plan(\$p({$exp['id']})); (new MikrotikPppoe())->add_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'provision');
        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $u['username'], 'password' => $u['password']]);
        $this->assertTrue($up['ok'], 'local PPPoE secret dials: ' . json_encode(array_diff_key($up, ['log' => 1])));
        $this->assertStringStartsWith('10.88.250.', (string) $up['ip'], 'address from the local pool');
        $this->assertSame([], static::radiusSaw($u['username']), 'RADIUS never consulted for a local secret');
        $online = $this->ok($this->app("return (new MikrotikPppoe())->online_customer(\$c({$u['id']}), '" . self::ROUTER . "');"), 'online_customer');
        $this->assertNotEmpty($online['value']);
        // expiry with an expired plan: the secret moves to the restricted profile and the session is dropped
        static::$db->exec("UPDATE tbl_plans SET plan_expired = {$exp['id']} WHERE id = {$plan['id']}");
        $this->ok($this->app("(new MikrotikPppoe())->remove_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'expiry');
        $this->assertSame($exp['name'], static::find('/ppp/secret', $u['username'])[0]['profile'] ?? null);
        $this->assertTrue((bool) Wp8Lab::until(fn() => static::find('/ppp/active', $u['username']) === [], 15, 1), 'the session is dropped');
        // removal without an expired plan
        static::$db->exec("UPDATE tbl_plans SET plan_expired = 0 WHERE id = {$plan['id']}");
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $this->ok($this->app("(new MikrotikPppoe())->remove_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'remove_customer');
        $this->assertSame([], static::find('/ppp/secret', $u['username']));
        $this->ok($this->app("(new MikrotikPppoe())->remove_plan(\$p({$plan['id']})); (new MikrotikPppoe())->remove_plan(\$p({$exp['id']})); (new MikrotikPppoe())->remove_pool(ORM::for_table('tbl_pool')->find_one({$poolId}));"), 'cleanup');
        $this->assertSame($radius, static::radiusChecksums(), 'a local dial writes no RADIUS row (AM-S7-1)');
        static::evidence('DC-P3.local-dial', ['user' => $u['username'], 'ip' => $up['ip'], 'radius_log_lines' => 0]);
    }

    // --------------------------------------------------------------------------- SEP: RADIUS still RADIUS (AM-S7-1)

    public function testARadiusSubscriberStillAuthenticatesThroughRadiusWithItsOwnAccountingRow(): void
    {
        $user = $this->provisionPolicy(['username' => self::ident('s7rad'), 'password' => 'S7-rad-1', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''],
            new \DateTimeImmutable('+1 day'));
        $pdo = static::labRadius();
        $policy = [static::radiusChecksums()['radcheck'], static::radiusChecksums()['radreply'], static::radiusChecksums()['radusergroup']];
        $max = (int) $pdo->query('SELECT COALESCE(MAX(radacctid), 0) FROM radacct')->fetchColumn();
        $history = hash('sha256', json_encode($pdo->query("SELECT * FROM radacct WHERE radacctid <= {$max} ORDER BY radacctid")->fetchAll(\PDO::FETCH_ASSOC)));
        $this->assertSame([], static::find('/ppp/secret', $user), 'no local secret masks the RADIUS user');
        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'S7-rad-1']);
        $this->assertTrue($up['ok'], json_encode(array_diff_key($up, ['log' => 1])));
        $this->assertNotSame([], static::radiusSaw($user), 'the RADIUS user was authenticated by FreeRADIUS');
        $row = Wp8Lab::until(fn() => $this->acct($user) ?: null, 20, 1);
        $this->assertNotNull($row, 'accounting Start written');
        $new = $pdo->query("SELECT username FROM radacct WHERE radacctid > {$max}")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([$user], $new, 'exactly one new accounting row, the subscriber\'s own (expected positive-control accounting)');
        $this->assertSame($history, hash('sha256', json_encode($pdo->query("SELECT * FROM radacct WHERE radacctid <= {$max} ORDER BY radacctid")->fetchAll(\PDO::FETCH_ASSOC))), 'older accounting rows unchanged');
        $this->assertSame($policy, [static::radiusChecksums()['radcheck'], static::radiusChecksums()['radreply'], static::radiusChecksums()['radusergroup']], 'the login changed no policy row');
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        static::evidence('SEP.radius-user', ['user' => $user, 'ip' => $up['ip'] ?? null, 'new_radacct_rows' => count($new)]);
    }

    // --------------------------------------------------------------------------------------------- DC-V1

    public function testVpnApiObjectsAndNatContractsStayInsideTheTestRules(): void
    {
        $natBefore = Wp8Lab::ros('/ip/firewall/nat/print');
        $others = static::others();
        $radius = static::radiusChecksums();
        $ip = '10.88.251.10';
        // AM-S7-3: the test addresses are free; the read-only probe of what an EMPTY pppoe_ip would make rmNat() match
        $this->assertSame([], Wp8Lab::ros('/ip/firewall/nat/print', '?to-addresses=' . $ip), 'no existing NAT rule uses the test address');
        $this->assertSame([], Wp8Lab::ros('/ip/firewall/nat/print', '?dst-address=10.88.251.250'), 'no existing NAT rule uses the test public address');
        $emptyMatch = Wp8Lab::ros('/ip/firewall/nat/print', '?to-addresses=');
        $pool = self::PREFIX . 'vpool_' . bin2hex(random_bytes(2));
        $poolId = static::$db->insertRow('tbl_pool', ['pool_name' => $pool, 'local_ip' => '10.88.251.1', 'range_ip' => '10.88.251.10-10.88.251.20', 'routers' => self::ROUTER]);
        static::$db->insertRow('tbl_port_pool', ['public_ip' => '10.88.251.250', 'port_name' => self::PREFIX . 'ports', 'range_port' => '41000-41010', 'routers' => self::ROUTER]);
        $plan = $this->plan('VPN', 'v', ['pool' => $pool]);
        $u = $this->localCustomer('vpn', null, ['pppoe_ip' => $ip, 'service_type' => 'VPN']);
        $this->ok($this->app("(new MikrotikPppoe())->add_pool(ORM::for_table('tbl_pool')->find_one({$poolId})); (new MikrotikVpn())->add_plan(\$p({$plan['id']}));"), 'add_plan');
        $this->ok($this->app("(new MikrotikVpn())->add_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'add_customer');
        $s = static::find('/ppp/secret', $u['username']);
        $this->assertCount(1, $s);
        $this->assertSame(['any', $plan['name'], $ip], [$s[0]['service'], $s[0]['profile'], $s[0]['remote-address'] ?? null]);
        $nat = Wp8Lab::ros('/ip/firewall/nat/print', '?to-addresses=' . $ip);
        $this->assertCount(3, $nat, 'Winbox / Api / Web dst-nat rules');
        foreach ($nat as $r) {
            $this->assertSame(['dstnat', 'dst-nat', '10.88.251.250'], [$r['chain'], $r['action'], $r['dst-address']]);
            $this->assertStringEndsWith(' || ' . $u['username'], $r['comment']);
        }
        $to = $u['username'] . 'r';
        $this->ok($this->app("(new MikrotikVpn())->change_username(\$p({$plan['id']}), '{$u['username']}', '{$to}');"), 'change_username');
        $this->assertCount(1, static::find('/ppp/secret', $to));
        $staleComments = count(array_filter(Wp8Lab::ros('/ip/firewall/nat/print', '?to-addresses=' . $ip), static fn($r) => str_ends_with($r['comment'], ' || ' . $u['username'])));
        static::$db->pdo->prepare('UPDATE tbl_customers SET username = ? WHERE id = ?')->execute([$to, $u['id']]);
        // AM-S7-3 pre-removal guard: rmNat() removes by to-addresses = the customer's pppoe_ip; it must match test rules only
        $match = Wp8Lab::ros('/ip/firewall/nat/print', '?to-addresses=' . $ip);
        $this->assertSame([], array_values(array_filter($match, static fn($r) => !static::isTestObject($r))), 'the removal can only reach the test rules');
        $this->ok($this->app("(new MikrotikVpn())->remove_customer(\$c({$u['id']}), \$p({$plan['id']}));"), 'remove_customer');
        $this->assertSame([], static::find('/ppp/secret', $to));
        $this->assertSame([], Wp8Lab::ros('/ip/firewall/nat/print', '?to-addresses=' . $ip), 'its NAT rules removed');
        $this->ok($this->app("(new MikrotikVpn())->remove_plan(\$p({$plan['id']})); (new MikrotikPppoe())->remove_pool(ORM::for_table('tbl_pool')->find_one({$poolId}));"), 'cleanup');
        $this->assertSame($natBefore, Wp8Lab::ros('/ip/firewall/nat/print'), 'the full NAT table is exactly as before');
        $this->assertSame($others, static::others());
        $this->assertSame($radius, static::radiusChecksums());
        static::evidence('DC-V1.vpn-nat', ['nat_before' => $natBefore, 'empty_to_addresses_query_matches' => $emptyMatch, 'test_rules' => 3,
            'rename_left_nat_comments_with_old_name' => $staleComments]);
    }

    // --------------------------------------------------------------------------------------------- DC-X

    public function testApiUnavailableAndRouterAddressWithoutPort(): void
    {
        $others = static::others();
        $plan = $this->plan('PPPOE', 'x');
        $u = $this->localCustomer('x', null);
        // DC-X1: the lab firewall drops the API from the runner; the driver fails, measured, without a partial object
        $rule = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=input', '=protocol=tcp', '=dst-port=8728', '=src-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_local_drill');
        try {
            $r = $this->app("(new MikrotikPppoe())->add_customer(\$c({$u['id']}), \$p({$plan['id']}));");
        } finally {
            Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $rule);
        }
        $this->assertFalse($r['ok'], 'API unavailable: the call fails');
        $this->assertSame([], static::find('/ppp/secret', $u['username']), 'no partial object');
        // DC-X2: a router address without ":port" (legacy form) -> PHP warning recorded, connection on the default port
        static::$db->exec("UPDATE tbl_routers SET ip_address = '" . Wp8Lab::CHR . "' WHERE name = '" . self::ROUTER . "'");
        try {
            $r2 = $this->app("return (new MikrotikPppoe())->online_customer(\$c({$u['id']}), '" . self::ROUTER . "');");
        } finally {
            static::$db->exec("UPDATE tbl_routers SET ip_address = '" . Wp8Lab::CHR . ":8728' WHERE name = '" . self::ROUTER . "'");
        }
        $this->assertTrue($r2['ok'], 'without ":port" the default API port is used');
        $this->assertNotSame([], array_filter($r2['warnings'], static fn($w) => str_contains($w, 'Undefined array key 1')), 'OBS-W8-4: the missing port raises a PHP warning');
        // DC-X3 (OBS-S7-1): the drivers never check a RouterOS !trap reply: a grant the router rejects (here an e-mail
        // address RouterOS refuses) is reported as success while nothing was created (characterisation; no fix in S7)
        $bad = $this->localCustomer('xt', null, ['email' => 'rejected@example.invalid']);
        $hp = $this->plan('Hotspot', 'xt', ['name_plan' => 'default']);
        $r3 = $this->app("(new MikrotikHotspot())->add_customer(\$c({$bad['id']}), \$p({$hp['id']}));");
        $this->assertTrue($r3['ok'], 'the driver reports success');
        $this->assertSame([], static::find('/ip/hotspot/user', $bad['username']), 'but RouterOS rejected the user: a silent no-op');
        $this->assertSame($others, static::others());
        static::evidence('DC-X.transport', ['api_unavailable' => ['class' => $r['class'] ?? null, 'ms' => $r['ms'], 'msg' => $r['msg'] ?? null],
            'no_port' => ['ok' => $r2['ok'], 'warnings' => $r2['warnings']], 'trap_ignored' => ['driver_ok' => $r3['ok'], 'user_created' => false]]);
    }
}
