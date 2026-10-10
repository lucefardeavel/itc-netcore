<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;

/**
 * WP8 S2 — real AAA and accounting on RouterOS CHR 7.24.5 with independent Linux clients (PPPoE: pppd CHAP in
 * namespaces wp8c1 / wp8c3; Hotspot: http-chap from wp8c2 / wp8c4). The RADIUS policy is the WP4 policy written by the
 * application provisioner for nuxr_wp8_* identities. Every proof is read back from the NAS itself (/ppp active,
 * /ip hotspot active, dynamic queues), from real traffic, and from radacct written by FreeRADIUS. Lab only.
 */
final class RealAaaTest extends TestCase
{
    use Wp8LabHelpers;

    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];

    protected function setUp(): void
    {
        $this->requireLab();
    }

    protected function tearDown(): void
    {
        $this->releaseClients();
    }

    private function pppoeCustomer(string $password, string $ip = ''): array
    {
        return ['username' => self::ident('ppp'), 'password' => $password, 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => $ip];
    }

    public function testPppoeDynamicPoolRateDirectionSessionAndAccounting(): void
    {
        $this->assertNoLocalSubscribers();
        $user = $this->provisionPolicy($this->pppoeCustomer('Dyn-pass-1'), ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
        Wp8Lab::agent('capture_start', ['name' => 's2-pppoe']);
        $disc = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Dyn-pass-1']);
        $this->assertTrue($disc['ok'], json_encode($disc['log']));
        $this->assertNotEmpty(preg_grep('/CHAP authentication succeeded/', $disc['log']), 'CHAP');
        $reply = self::lastReply($user);
        $this->assertSame('Access-Accept', $reply['code']);
        $this->assertSame('nuxr_wp8_pool_dyn', $reply['attrs']['Framed-Pool'] ?? null, 'RADIUS emits Framed-Pool');
        $this->assertArrayNotHasKey('Framed-IP-Address', $reply['attrs'], 'no static address overrides the pool');
        $this->assertSame('256K/512K', $reply['attrs']['Mikrotik-Rate-Limit'] ?? null);
        $this->assertMatchesRegularExpression('/^10\.88\.10\.(1[0-4]\d)$/', $disc['ip'], 'address from nuxr_wp8_pool_dyn (10.88.10.100-149)');

        $active = Wp8Lab::pppActive($user)[0];
        $this->assertSame(['pppoe', 'true', $disc['ip']], [$active['service'], $active['radius'], $active['address']]);
        $queue = Wp8Lab::queue('<pppoe-' . $user . '>')[0];
        $this->assertSame('256000/512000', $queue['max-limit'], 'dynamic queue: target upload / target download');

        // real traffic through the NAS: upload (client -> 10.88.0.40) is capped at rx-rate 256k, download at tx-rate 512k
        $up = Wp8Lab::agent('traffic', ['client' => 'c1', 'direction' => 'up', 'seconds' => 8, 'kbps_offer' => 2000], 90);
        $down = Wp8Lab::agent('traffic', ['client' => 'c1', 'direction' => 'down', 'seconds' => 8, 'kbps_offer' => 2000], 90);
        $this->assertGreaterThan(256 * 0.7, $up['measured_kbps'], 'upload reaches its limit');
        $this->assertLessThan(256 * 1.12, $up['measured_kbps'], 'Mikrotik-Rate-Limit rx (first value) = client upload');
        $this->assertGreaterThan(512 * 0.7, $down['measured_kbps'], 'download reaches its limit');
        $this->assertLessThan(512 * 1.12, $down['measured_kbps'], 'Mikrotik-Rate-Limit tx (second value) = client download');

        $interim = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctupdatetime'] > $r[0]['acctstarttime'] ? $r[0] : null, 90, 5);
        $this->assertIsArray($interim, 'Interim-Update');
        // the RouterOS API prints the session id as upper-case hex ("0x810000E1"), RADIUS carries it lower-case
        // ("810000e1"): the same number, compared case-insensitively on BOTH sides
        $this->assertSame([strtolower(substr($active['session-id'], 2)), '10.88.0.2', $disc['ip'], 'Ethernet', 'ether3'],
            [strtolower($interim['acctsessionid']), $interim['nasipaddress'], $interim['framedipaddress'], $interim['nasporttype'], $interim['nasportid']],
            'session id, NAS identity (== nas.nasname), framed address, port');
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $stop = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'] ? $r[0] : null, 30, 2);
        $this->assertIsArray($stop, 'Stop');
        $this->assertSame('User-Request', $stop['acctterminatecause']);
        $this->assertGreaterThan($up['bytes'], (int) $stop['acctinputoctets'], 'input octets = client upload');
        $this->assertGreaterThan($down['bytes'], (int) $stop['acctoutputoctets'], 'output octets = client download');
        $pk = Wp8Lab::agent('capture_stop', ['name' => 's2-pppoe'])['packets'];
        $areq = array_values(array_filter($pk, static fn($p) => $p['code'] === 'Access-Request'));
        $this->assertNotEmpty($areq);
        $this->assertSame([true], array_values(array_unique(array_column($areq, 'message_authenticator'))));
        Wp8Lab::evidence('S2.pppoe-dynamic', ['reply' => $reply, 'ip' => $disc['ip'], 'ppp_active' => $active, 'queue' => $queue['max-limit'],
            'traffic' => [$up, $down], 'radacct_interim' => $interim, 'radacct_stop' => $stop]);
    }

    public function testPppoeStaticAddressIsAssignedExactlyAndNoPoolIsUsed(): void
    {
        $user = $this->provisionPolicy($this->pppoeCustomer('Static-pass-1', '10.88.10.50'), ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
        $s = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Static-pass-1']);
        $this->assertTrue($s['ok'], json_encode($s['log']));
        $reply = self::lastReply($user)['attrs'];
        $this->assertSame('10.88.10.50', $reply['Framed-IP-Address'] ?? null);
        $this->assertArrayNotHasKey('Framed-Pool', $reply);
        $this->assertSame('10.88.10.50', $s['ip'], 'exact static address');
        $this->assertSame('10.88.10.50', Wp8Lab::pppActive($user)[0]['address']);
        Wp8Lab::evidence('S2.pppoe-static', ['reply' => $reply, 'ip' => $s['ip']]);
    }

    public function testPppoeSimultaneousUseAllowsOneSession(): void
    {
        $user = $this->provisionPolicy($this->pppoeCustomer('Simu-pass-1'), ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Simu-pass-1'])['ok']);
        Wp8Lab::until(fn() => $this->acct($user), 15, 1);
        $second = Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $user, 'password' => 'Simu-pass-1', 'wait' => 25]);
        $this->assertFalse($second['ok'], 'second session from another host refused');
        $this->assertSame('Access-Reject', self::lastReply($user)['code'], 'Simultaneous-Use 1 enforced by FreeRADIUS');
        $this->assertCount(1, Wp8Lab::pppActive($user));
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'], 30, 2);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $user, 'password' => 'Simu-pass-1'])['ok'], 'allowed once the first session closed');
        Wp8Lab::evidence('S2.pppoe-simultaneous-use', ['second_while_open' => $second['log'], 'after_close' => 'accepted']);
    }

    public function testPppoeNegativeCases(): void
    {
        $ok = $this->provisionPolicy($this->pppoeCustomer('Neg-pass-1'), ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
        $wrong = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $ok, 'password' => 'not-the-password', 'wait' => 25]);
        $this->assertFalse($wrong['ok']);
        $this->assertSame('Access-Reject', self::lastReply($ok)['code'], 'wrong password');

        $expired = $this->provisionPolicy($this->pppoeCustomer('Exp-pass-1'), ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('-1 minute'));
        $this->assertFalse(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $expired, 'password' => 'Exp-pass-1', 'wait' => 25])['ok']);
        $this->assertSame('Access-Reject', self::lastReply($expired)['code'], 'expired subscriber');

        $nopool = $this->provisionPolicy($this->pppoeCustomer('Nopool-pass-1'), ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_missing'], self::BW, new \DateTimeImmutable('+1 day'));
        $np = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $nopool, 'password' => 'Nopool-pass-1', 'wait' => 30]);
        $reply = self::lastReply($nopool);
        $this->assertSame('Access-Accept', $reply['code'], 'RADIUS accepts; the pool does not exist on the NAS');
        $this->assertSame('nuxr_wp8_pool_missing', $reply['attrs']['Framed-Pool'] ?? null);
        $this->assertFalse($np['ok'], 'no address can be assigned: the client gets no IPv4');
        $nasSide = Wp8Lab::pppActive($nopool);
        foreach ($nasSide as $a) {
            $this->assertSame('0.0.0.0', $a['address'], 'RouterOS 7.24.5 may keep the PPP link without any address');
        }
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $log = array_column(Wp8Lab::ros('/log/print'), 'message');
        Wp8Lab::evidence('S2.pppoe-negative', ['wrong_password' => $wrong['log'], 'expired' => 'Access-Reject',
            'nonexistent_pool' => ['reply' => $reply, 'client' => $np['log'], 'nas_active' => $nasSide,
                'routeros_log' => array_values(array_slice(preg_grep('/' . preg_quote($nopool, '/') . '|pool/i', $log), -6))]]);
    }

    public function testPppoeSessionEndsAtExpirationAndReauthenticationIsRefused(): void
    {
        $user = $this->provisionPolicy($this->pppoeCustomer('Ttl-pass-1'), ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+70 seconds'));
        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Ttl-pass-1']);
        $this->assertTrue($up['ok']);
        $timeout = (int) (self::lastReply($user)['attrs']['Session-Timeout'] ?? 0);
        $this->assertGreaterThan(30, $timeout);
        $this->assertLessThanOrEqual(70, $timeout, 'Session-Timeout up to the saved expiry');
        $down = Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1', 'wait' => 110], 150);
        $this->assertTrue($down['down'], 'the NAS ended the session at the expiry');
        $stop = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'] ? $r[0] : null, 30, 2);
        $this->assertSame('Session-Timeout', $stop['acctterminatecause'] ?? null);
        $this->assertFalse(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Ttl-pass-1', 'wait' => 25])['ok']);
        $this->assertSame('Access-Reject', self::lastReply($user)['code'], 're-authentication after the expiry refused');
        Wp8Lab::evidence('S2.pppoe-expiry', ['session_timeout' => $timeout, 'stop' => $stop]);
    }

    public function testHotspotHttpChapLoginRateAccountingLogoutAndReinterception(): void
    {
        $user = $this->provisionPolicy(['username' => self::ident('hs'), 'password' => 'Hs-pass-1'], ['type' => 'Hotspot', 'shared_users' => 1], self::BW, new \DateTimeImmutable('+1 day'));
        $lease = Wp8Lab::agent('hs_dhcp', ['client' => 'c2']);
        $this->assertTrue($lease['ok']);
        $probe = Wp8Lab::agent('http_probe', ['client' => 'c2']);
        $this->assertSame(302, $probe['status']);
        $this->assertStringStartsWith('http://10.88.2.1/login', (string) $probe['location']);
        $wrong = Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $user, 'password' => 'wrong-one']);
        $this->assertFalse($wrong['ok'], 'wrong password refused');
        $login = Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $user, 'password' => 'Hs-pass-1']);
        $this->assertTrue($login['ok'], $login['message']);
        $active = Wp8Lab::hotspotActive($user)[0];
        $this->assertSame(['http-chap', 'true', $lease['ip']], [$active['login-by'], $active['radius'], $active['address']]);
        $this->assertSame('Access-Accept', self::lastReply($user)['code']);
        $queue = Wp8Lab::queue('<hotspot-' . $user . '>');
        $this->assertSame('256000/512000', $queue[0]['max-limit'] ?? null, 'rate limit applied to the Hotspot session');
        $interim = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctupdatetime'] > $r[0]['acctstarttime'] ? $r[0] : null, 90, 5);
        $this->assertIsArray($interim);
        $this->assertSame(['10.88.0.2', $lease['ip']], [$interim['nasipaddress'], $interim['framedipaddress']]);
        Wp8Lab::agent('hs_logout', ['client' => 'c2']);
        $stop = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'] ? $r[0] : null, 30, 2);
        $this->assertSame('User-Request', $stop['acctterminatecause'] ?? null);
        $this->assertSame([], Wp8Lab::hotspotActive($user));
        $this->assertSame(302, Wp8Lab::agent('http_probe', ['client' => 'c2'])['status'], 'intercepted again');
        Wp8Lab::evidence('S2.hotspot', ['auth' => 'http-chap', 'active' => $active, 'queue' => $queue[0]['max-limit'], 'interim' => $interim, 'stop' => $stop, 'wrong_password' => $wrong['message']]);
    }

    public function testHotspotSessionLimitAndExpiration(): void
    {
        $user = $this->provisionPolicy(['username' => self::ident('hslim'), 'password' => 'Lim-pass-1'], ['type' => 'Hotspot', 'shared_users' => 1], self::BW, new \DateTimeImmutable('+70 seconds'));
        Wp8Lab::agent('hs_dhcp', ['client' => 'c2']);
        Wp8Lab::agent('hs_dhcp', ['client' => 'c4']);
        $this->assertTrue(Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $user, 'password' => 'Lim-pass-1'])['ok']);
        Wp8Lab::until(fn() => $this->acct($user), 15, 1);
        $second = Wp8Lab::agent('hs_login', ['client' => 'c4', 'user' => $user, 'password' => 'Lim-pass-1']);
        $this->assertFalse($second['ok'], 'shared_users = 1: second device refused');
        $reply = self::lastReply($user);
        if ((string) static::labRadius()->query('SELECT type FROM nas WHERE id = 1')->fetchColumn() === 'mikrotik_snmp') {
            // WP8 A1 production contract (NAS type mikrotik_snmp): the stock checkrad cannot prove the first session live
            // and defers, FreeRADIUS accepts, and the NAS enforces the Hotspot limit from the Port-Limit reply attribute
            // (the OD-W8-16 Option G gate observation, now from the normal provisioning path)
            $this->assertSame(['Access-Accept', '1'], [$reply['code'], $reply['attrs']['Port-Limit'] ?? null], 'FreeRADIUS accepts with Port-Limit 1; RouterOS refuses');
            $this->assertCount(1, Wp8Lab::hotspotActive($user), 'still exactly one Hotspot session on the NAS');
        } else {
            $this->assertSame('Access-Reject', $reply['code'], 'by FreeRADIUS Simultaneous-Use');
        }
        $ended = Wp8Lab::until(fn() => Wp8Lab::hotspotActive($user) === [], 110, 5);
        $this->assertTrue($ended, 'the Hotspot session ended at the expiry');
        $stop = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'] ? $r[0] : null, 30, 2);
        $this->assertSame('Session-Timeout', $stop['acctterminatecause'] ?? null);
        $this->assertFalse(Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $user, 'password' => 'Lim-pass-1'])['ok'], 'expired: refused');
        $this->assertSame(302, Wp8Lab::agent('http_probe', ['client' => 'c2'])['status']);
        Wp8Lab::evidence('S2.hotspot-limit-expiry', ['second_device' => $second['message'], 'stop' => $stop]);
    }
}
