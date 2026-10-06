<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8Lab.php';

use PHPUnit\Framework\TestCase;
use Reseller\Radius\AccountingService;
use Reseller\Radius\CoAClient;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;
use Reseller\Tests\Support\Wp8Lab;

/**
 * WP8 S1 closure on the canonical RouterOS CHR 7.24.5 (amendment W8-A2) with the OD-W8-13 virtual server and
 * production-style SQL NAS clients: independent-namespace PPPoE (CHAP) and Hotspot (http-chap) clients authenticate;
 * every RouterOS Access-Request carries Message-Authenticator; Accounting Start / Interim / Stop arrive WITHOUT it and
 * are answered and written; the APPLICATION CoAClient, running in the runner that shares the FreeRADIUS address
 * (OD-W8-11), disconnects both sessions (ACK) and the Hotspot client is intercepted again. Lab only.
 */
final class RouterOsClosureTest extends TestCase
{
    private static ?\PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        if (Wp8Lab::enabled()) {
            self::$pdo = Wp8Lab::radiusPdo();
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestIncomplete('WP8 real-NAS lab not running (dev/wp8/lab.sh up; dev/wp8/lab.sh test)');
        }
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
            Wp8Lab::agent('hs_logout', ['client' => 'c2']);
        }
    }

    private function provision(array $customer, array $plan): string
    {
        $bw = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];
        $policy = RadiusPolicyBuilder::build($customer, $plan + ['id' => random_int(100000, 999999), 'typebp' => 'Unlimited', 'shared_users' => 1], $bw, new \DateTimeImmutable('+1 day'));
        $r = (new RadiusProvisioner(new RadiusRepository(self::$pdo)))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    private function acct(string $user): array
    {
        $st = self::$pdo->prepare('SELECT radacctid, acctsessionid, nasipaddress, framedipaddress, acctstarttime, acctupdatetime, acctstoptime, acctterminatecause, acctinputoctets, acctoutputoctets FROM radacct WHERE username = ? ORDER BY radacctid');
        $st->execute([$user]);
        return $st->fetchAll();
    }

    public function testRouterOs7245AuthenticatesAccountsAndDisconnectsUnderTheHardenedPolicy(): void
    {
        $ppp = $this->provision(['username' => 'nuxr_wp8_s1_ppp_' . bin2hex(random_bytes(3)), 'password' => 'S1-ppp-pass', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''], ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn']);
        $hs = $this->provision(['username' => 'nuxr_wp8_s1_hs_' . bin2hex(random_bytes(3)), 'password' => 'S1-hs-pass'], ['type' => 'Hotspot', 'pool' => '']);
        $this->assertSame([], Wp8Lab::ros('/ppp/secret/print'), 'no local PPP secret can mask RADIUS');
        Wp8Lab::agent('capture_start', ['name' => 's1-closure']);

        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $ppp, 'password' => 'S1-ppp-pass']);
        $this->assertTrue($up['ok'], 'PPPoE session: ' . json_encode($up['log']));
        $this->assertNotEmpty(preg_grep('/CHAP authentication succeeded/', $up['log']));
        $this->assertMatchesRegularExpression('/^10\.88\.10\.(1[0-4]\d)$/', $up['ip'], 'address from the RADIUS Framed-Pool nuxr_wp8_pool_dyn');
        $active = Wp8Lab::pppActive($ppp);
        $this->assertCount(1, $active);
        $this->assertSame(['pppoe', 'true', $up['ip']], [$active[0]['service'], $active[0]['radius'], $active[0]['address']]);

        $this->assertNotNull(Wp8Lab::agent('hs_dhcp', ['client' => 'c2'])['ip']);
        $probe = Wp8Lab::agent('http_probe', ['client' => 'c2']);
        $this->assertSame(302, $probe['status'], 'unauthenticated HTTP is intercepted');
        $login = Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $hs, 'password' => 'S1-hs-pass']);
        $this->assertTrue($login['ok'], 'Hotspot login: ' . $login['message']);
        $hsActive = Wp8Lab::hotspotActive($hs);
        $this->assertCount(1, $hsActive);
        $this->assertSame(['http-chap', 'true'], [$hsActive[0]['login-by'], $hsActive[0]['radius']]);

        // Interim-Update interval is 1 minute on both services
        $interim = Wp8Lab::until(fn() => ($a = $this->acct($ppp)) && ($b = $this->acct($hs)) && $a[0]['acctupdatetime'] > $a[0]['acctstarttime'] && $b[0]['acctupdatetime'] > $b[0]['acctstarttime'], 100, 5);
        $this->assertTrue((bool) $interim, 'Interim-Update written for both sessions');

        $coa = new CoAClient(new AccountingService(self::$pdo), new RadiusRepository(self::$pdo));
        foreach ([$ppp, $hs] as $user) {
            $res = array_map(static fn($r) => $r->toArray(), $coa->disconnect($user));
            $this->assertCount(1, $res, 'one open session');
            $this->assertSame(['SUCCESS', 'COA_ACK', '1'], [$res[0]['status'], $res[0]['code'], $res[0]['nas_id']], 'nas row found by radacct.nasipaddress == nas.nasname, ACK');
        }
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1'])['down'], 'the PPPoE client saw the disconnect');
        $this->assertSame([], Wp8Lab::pppActive($ppp));
        $this->assertSame([], Wp8Lab::hotspotActive($hs));
        $this->assertSame(302, Wp8Lab::agent('http_probe', ['client' => 'c2'])['status'], 'the Hotspot client is intercepted again');
        $stops = Wp8Lab::until(fn() => ($a = $this->acct($ppp)) && ($b = $this->acct($hs)) && $a[0]['acctstoptime'] && $b[0]['acctstoptime'] ? [$a[0], $b[0]] : null, 30, 2);
        $this->assertIsArray($stops, 'Stop written for both sessions');
        $this->assertSame(['10.88.0.2', '10.88.0.2'], [$stops[0]['nasipaddress'], $stops[1]['nasipaddress']], 'NAS identity: radacct.nasipaddress == nas.nasname');

        $pk = Wp8Lab::agent('capture_stop', ['name' => 's1-closure'])['packets'];
        $by = static fn(string $code, string $src) => array_values(array_filter($pk, static fn($p) => $p['code'] === $code && str_starts_with($p['src'], $src . ':')));
        $areq = $by('Access-Request', '10.88.0.2');
        $this->assertGreaterThanOrEqual(2, count($areq));
        $this->assertSame([true], array_values(array_unique(array_column($areq, 'message_authenticator'))), 'every RouterOS Access-Request carries Message-Authenticator');
        $this->assertGreaterThanOrEqual(2, count($by('Access-Accept', '10.88.0.10')));
        $acctAll = $by('Accounting-Request', '10.88.0.2');
        $this->assertSame([false], array_values(array_unique(array_column($acctAll, 'message_authenticator'))), 'RouterOS accounting carries no Message-Authenticator');
        // subscriber records carry User-Name (1); a NAS-level Accounting-On (no User-Name) is recorded separately:
        // the stock accounting-on query returns noop when the NAS has no open session, and FreeRADIUS 3 then sends
        // no Accounting-Response (pre-existing WP4 behaviour, observation OBS-W8-7; open sessions would be closed
        // as NAS-Reboot by UPDATE, never deleted)
        $acct = array_values(array_filter($acctAll, static fn($p) => in_array(1, $p['attrs'], true)));
        $nasLevel = array_values(array_filter($acctAll, static fn($p) => !in_array(1, $p['attrs'], true)));
        $this->assertGreaterThanOrEqual(6, count($acct), 'Start + Interim + Stop for both sessions');
        $answered = array_column($by('Accounting-Response', '10.88.0.10'), 'id');
        foreach ($acct as $p) {
            $this->assertContains($p['id'], $answered, 'subscriber accounting record answered');
        }
        $this->assertCount(2, $by('Disconnect-Request', '10.88.0.10'), 'application CoA leaves from the RouterOS /radius server address');
        $this->assertCount(2, $by('Disconnect-ACK', '10.88.0.2'));
        Wp8Lab::evidence('S1.routeros-7.24.5-closure', ['pppoe' => ['ip' => $up['ip'], 'seconds' => $up['seconds']], 'hotspot' => $hsActive[0]['login-by'],
            'accounting_on_unanswered' => count($nasLevel), 'packets' => array_map(static fn($p) => [$p['code'], $p['src'], $p['dst'], $p['message_authenticator']], $pk), 'radacct' => $stops]);
    }
}
