<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;

/**
 * WP8 OD-W8-16 Option E — Port-Limit GATE (owner sections 2-4), LAB ONLY, before any runtime change: does the real
 * RouterOS 7.24.5 enforce the RADIUS reply attribute Port-Limit on live PPPoE and Hotspot sessions?
 *
 * The Port-Limit reply row is injected directly into the disposable lab subscriber policy, and the Simultaneous-Use
 * check row is removed (the lab-only stand-in for "the SQL count ignores the row"), so the NAS is the only possible
 * enforcer. The NAS active table is sampled continuously while the competing client tries: at no point may more live
 * sessions exist than Port-Limit allows. Evidence is recorded before the assertions.
 *
 * GATE RESULT (2026-10-07): Hotspot PASSED (Port-Limit 1 and 2 enforced by the NAS, also with accounting lost);
 * PPPoE FAILED — RouterOS 7.24.5 admits a second PPPoE session although the Access-Accept carries Port-Limit = 1
 * (packet evidence). The two PPPoE gate tests therefore assert that observed NAS behaviour, so the suite documents the
 * fact; Option E was not implemented (owner stop, see owner-review/WP8/STOP_S6_LOST_STOP.md).
 */
final class PortLimitGateTest extends TestCase
{
    use Wp8LabHelpers;

    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];
    private array $rules = [];

    protected function setUp(): void
    {
        $this->requireLab();
    }

    protected function tearDown(): void
    {
        if (static::labRadius() !== null) {
            foreach ($this->rules as $id) {
                Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
            }
            $this->rules = [];
            $this->releaseClients();
        }
    }

    /** lab-only policy edit of a disposable subscriber: Port-Limit reply on, Simultaneous-Use check off */
    private function portLimitOnly(string $user, int $limit, bool $hotspot = false): void
    {
        $pdo = static::labRadius();
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Simultaneous-Use'")->execute([$user]);
        if ($hotspot) {
            // WP8 H-2B: a Hotspot login without Simultaneous-Use is refused (the admission slots need a limit; fail
            // closed). 99 = the largest limit: the slots never bind for 2-3 clients, so the NAS (Port-Limit) remains the
            // only possible enforcer, as this gate requires.
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Simultaneous-Use', ':=', '99')")->execute([$user]);
        }
        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Port-Limit'")->execute([$user]);
        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Port-Limit', ':=', ?)")->execute([$user, (string) $limit]);
    }

    private function blockAccounting(): void
    {
        $this->rules[] = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_drill');
    }

    private function pppoeUser(string $password): string
    {
        return $this->provisionPolicy(['username' => self::ident('pl'), 'password' => $password, 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
    }

    private function hotspotUser(string $password, int $shared): string
    {
        return $this->provisionPolicy(['username' => self::ident('plhs'), 'password' => $password], ['type' => 'Hotspot', 'shared_users' => $shared], self::BW, new \DateTimeImmutable('+1 day'));
    }

    private function routerLog(string $user): array
    {
        return array_values(array_map(static fn($r) => ($r['time'] ?? '') . ' ' . $r['message'], array_filter(Wp8Lab::ros('/log/print'), static fn($r) => str_contains($r['message'] ?? '', $user))));
    }

    /** PPPoE: C2 tries the same username while C1 is live; the NAS is watched the whole time */
    private function pppoeContest(string $user, string $password): array
    {
        $c1 = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => $password]);
        $this->assertTrue($c1['ok'], json_encode($c1['log']));
        $before = Wp8Lab::pppActive($user);
        Wp8Lab::agent('pppoe_start', ['client' => 'c3', 'user' => $user, 'password' => $password]);
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'ppp', 'user' => $user, 'seconds' => 35], 90);
        $after = Wp8Lab::pppActive($user);
        return ['c1' => ['ip' => $c1['ip']], 'nas_before' => array_column($before, 'caller-id'), 'watch' => $watch,
            'nas_after' => array_map(static fn($a) => [$a['caller-id'], $a['address'], $a['radius']], $after),
            'c1_state' => Wp8Lab::agent('pppoe_state', ['client' => 'c1']), 'c3_state' => Wp8Lab::agent('pppoe_state', ['client' => 'c3']),
            'reply_to_c3' => self::lastReply($user), 'router_log' => array_slice($this->routerLog($user), -8)];
    }

    public function testGatePppoePortLimit1IsNotEnforcedByRouterOs(): void
    {
        $user = $this->pppoeUser('Pl-ppp-1');
        $this->portLimitOnly($user, 1);
        Wp8Lab::agent('capture_start', ['name' => 'od16-gate-pppoe']);
        $r = $this->pppoeContest($user, 'Pl-ppp-1');
        $r['packets'] = array_map(static fn($p) => [$p['code'], $p['src'], $p['dst'], $p['id'], 'Port-Limit(62)=' . (in_array(62, $p['attrs'], true) ? 'yes' : 'no')],
            array_values(array_filter(Wp8Lab::agent('capture_stop', ['name' => 'od16-gate-pppoe'])['packets'], static fn($p) => str_starts_with($p['code'], 'Access'))));
        Wp8Lab::evidence('OD16.gate.pppoe-port-limit-1', $r);
        $this->assertSame('1', $r['reply_to_c3']['attrs']['Port-Limit'] ?? null, 'FreeRADIUS sent Port-Limit = 1 to the second client');
        $accepts = array_filter($r['packets'], static fn($p) => $p[0] === 'Access-Accept' && $p[4] === 'Port-Limit(62)=yes');
        $this->assertGreaterThanOrEqual(2, count($accepts), 'both Access-Accepts carried Port-Limit on the wire');
        // GATE FAILED (observed RouterOS 7.24.5 behaviour): the PPPoE server ignores Port-Limit, two live sessions
        $this->assertSame(2, $r['watch']['max'], 'observed: RouterOS 7.24.5 PPPoE does NOT enforce the RADIUS Port-Limit');
    }

    public function testGatePppoePortLimit1WithAccountingLostIsNotEnforcedByRouterOs(): void
    {
        $user = $this->pppoeUser('Pl-ppp-2');
        $c1 = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Pl-ppp-2']);
        $this->assertTrue($c1['ok']);
        Wp8Lab::until(fn() => $this->acct($user) ?: null, 20, 1);
        $this->blockAccounting();                                   // accounting UDP/1813 only; 1812 stays healthy
        sleep(190);                                                 // beyond the proposed 180 s stale window
        $this->portLimitOnly($user, 1);                             // LAB ONLY: the SQL count ignores the stale row
        $this->assertCount(1, Wp8Lab::pppActive($user), 'C1 is still genuinely live');
        Wp8Lab::agent('pppoe_start', ['client' => 'c3', 'user' => $user, 'password' => 'Pl-ppp-2']);
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'ppp', 'user' => $user, 'seconds' => 35], 90);
        $r = ['watch' => $watch, 'reply_to_c3' => self::lastReply($user), 'c1_state' => Wp8Lab::agent('pppoe_state', ['client' => 'c1']),
            'c3_state' => Wp8Lab::agent('pppoe_state', ['client' => 'c3']), 'nas_after' => array_map(static fn($a) => [$a['caller-id'], $a['address']], Wp8Lab::pppActive($user)),
            'radacct' => array_map(static fn($a) => [$a['acctsessionid'], $a['acctupdatetime'], $a['acctstoptime']], $this->acct($user)), 'router_log' => array_slice($this->routerLog($user), -8)];
        Wp8Lab::evidence('OD16.gate.pppoe-port-limit-1-accounting-lost', $r);
        $this->assertSame('1', $r['reply_to_c3']['attrs']['Port-Limit'] ?? null);
        // GATE FAILED (observed): with accounting lost and the SQL count bypassed, two real PPPoE sessions coexist
        $this->assertSame(2, $watch['max'], 'observed: Port-Limit does not protect a live PPPoE session on RouterOS 7.24.5');
    }

    /**
     * OBSERVATION ONLY (not Option E; informs the owner decision after the PPPoE gate failure): RouterOS' own NAS-side PPP
     * control "/ppp profile only-one=yes" on the PPPoE server profile, with Simultaneous-Use removed and accounting lost.
     * Observed over two runs: the newcomer is refused / torn down within ~1-2 s and the first session kept, but one run
     * sampled a transient second /ppp active entry (with an address) before the teardown. The lab profile is restored.
     */
    public function testObservationPppProfileOnlyOne(): void
    {
        $prof = Wp8Lab::ros('/ppp/profile/print', '?name=nuxr_wp8_pppoe')[0];
        Wp8Lab::ros('/ppp/profile/set', '=.id=' . $prof['.id'], '=only-one=yes');
        try {
            $user = $this->pppoeUser('Pl-ppp-3');
            $this->portLimitOnly($user, 1);
            $c1 = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Pl-ppp-3']);
            $this->assertTrue($c1['ok']);
            Wp8Lab::until(fn() => $this->acct($user) ?: null, 20, 1);
            $this->blockAccounting();
            sleep(20);
            Wp8Lab::agent('pppoe_start', ['client' => 'c3', 'user' => $user, 'password' => 'Pl-ppp-3']);
            $watch = Wp8Lab::agent('watch_active', ['kind' => 'ppp', 'user' => $user, 'seconds' => 35], 90);
            $r = ['only_one' => 'yes', 'watch' => $watch, 'reply_to_c3' => self::lastReply($user)['code'],
                'c1_state' => Wp8Lab::agent('pppoe_state', ['client' => 'c1']), 'c3_state' => Wp8Lab::agent('pppoe_state', ['client' => 'c3']),
                'nas_after' => array_map(static fn($a) => [$a['caller-id'], $a['address']], Wp8Lab::pppActive($user)), 'router_log' => array_slice($this->routerLog($user), -8)];
            Wp8Lab::evidence('OD16.observation.ppp-profile-only-one', $r);
            // observed: RouterOS may list the newcomer (with an address) for ~1-2 s, then tears it down; the first
            // session is kept, the second client never gets an IPv4 - NOT a strict "never two" guarantee
            $late = array_filter($watch['samples'], static fn($x) => $x[0] >= 5.0);
            $this->assertLessThanOrEqual(1, max(array_column($late, 1)), 'observed: no second session after the first seconds');
            $this->assertFalse($r['c3_state']['running']);
            $this->assertNull($r['c3_state']['ip'], 'the second client never gets an address');
            $this->assertTrue($r['c1_state']['running'], 'the first session is kept');
        } finally {
            Wp8Lab::ros('/ppp/profile/set', '=.id=' . $prof['.id'], '=only-one=default');
        }
    }

    /** Hotspot: logins from independent clients against the same username */
    private function hotspotContest(string $user, string $password, array $clients): array
    {
        $out = [];
        foreach ($clients as $c) {
            Wp8Lab::agent('hs_dhcp', ['client' => $c]);
        }
        foreach ($clients as $c) {
            $l = Wp8Lab::agent('hs_login', ['client' => $c, 'user' => $user, 'password' => $password]);
            $out[$c] = ['ok' => $l['ok'], 'message' => $l['message'], 'reply' => self::lastReply($user)['code'], 'active_now' => count(Wp8Lab::hotspotActive($user))];
            sleep(2);
        }
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'hotspot', 'user' => $user, 'seconds' => 15], 60);
        return ['logins' => $out, 'watch' => $watch, 'nas_after' => array_map(static fn($a) => [$a['mac-address'], $a['address'], $a['radius']], Wp8Lab::hotspotActive($user)),
            'router_log' => array_slice($this->routerLog($user), -8)];
    }

    public function testGateHotspotPortLimit1(): void
    {
        $user = $this->hotspotUser('Pl-hs-1', 1);
        $this->portLimitOnly($user, 1, true);
        $r = $this->hotspotContest($user, 'Pl-hs-1', ['c2', 'c4']);
        Wp8Lab::evidence('OD16.gate.hotspot-port-limit-1', $r);
        $this->assertTrue($r['logins']['c2']['ok'], 'first client allowed');
        $this->assertLessThanOrEqual(1, max(array_column($r['logins'], 'active_now')));
        $this->assertLessThanOrEqual(1, $r['watch']['max'], 'never two live Hotspot sessions for Port-Limit 1');
    }

    public function testGateHotspotPortLimit2(): void
    {
        $user = $this->hotspotUser('Pl-hs-2', 2);
        $this->portLimitOnly($user, 2, true);
        $r = $this->hotspotContest($user, 'Pl-hs-2', ['c2', 'c4', 'c5']);
        Wp8Lab::evidence('OD16.gate.hotspot-port-limit-2', $r);
        $this->assertTrue($r['logins']['c2']['ok'] && $r['logins']['c4']['ok'], 'first two sessions allowed');
        $this->assertFalse($r['logins']['c5']['ok'], 'third refused');
        $this->assertLessThanOrEqual(2, max(array_column($r['logins'], 'active_now')));
        $this->assertLessThanOrEqual(2, $r['watch']['max']);
    }

    public function testGateHotspotPortLimit1WithAccountingLost(): void
    {
        $user = $this->hotspotUser('Pl-hs-3', 1);
        Wp8Lab::agent('hs_dhcp', ['client' => 'c2']);
        Wp8Lab::agent('hs_dhcp', ['client' => 'c4']);
        $this->assertTrue(Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $user, 'password' => 'Pl-hs-3'])['ok']);
        Wp8Lab::until(fn() => $this->acct($user) ?: null, 20, 1);
        $this->blockAccounting();
        sleep(190);
        $this->portLimitOnly($user, 1, true);
        $this->assertCount(1, Wp8Lab::hotspotActive($user), 'C1 genuinely live');
        $l = Wp8Lab::agent('hs_login', ['client' => 'c4', 'user' => $user, 'password' => 'Pl-hs-3']);
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'hotspot', 'user' => $user, 'seconds' => 15], 60);
        $r = ['second_login' => $l, 'reply' => self::lastReply($user)['code'], 'watch' => $watch,
            'nas_after' => array_map(static fn($a) => [$a['mac-address'], $a['address']], Wp8Lab::hotspotActive($user)), 'router_log' => array_slice($this->routerLog($user), -8)];
        Wp8Lab::evidence('OD16.gate.hotspot-port-limit-1-accounting-lost', $r);
        $this->assertFalse($l['ok'], 'second device refused');
        $this->assertLessThanOrEqual(1, $watch['max']);
    }
}
