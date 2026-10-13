<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';

use PHPUnit\Framework\TestCase;
use Reseller\Radius\AccountingService;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;

/**
 * WP8 OD-W8-16 Option F — checkrad GATE (owner second resolution round, sections 4-15), LAB ONLY, before any runtime
 * change: SQL Simultaneous-Use + real-NAS verification of open radacct rows by the STOCK FreeRADIUS 3.2.8 checkrad
 * (nas.type mikrotik_snmp, SNMP read-only) against the real RouterOS CHR 7.24.5, with delete_stale_sessions = no.
 *
 * The gate switches (dev/wp8/checkrad_gate.py) restart FreeRADIUS, which the runner cannot survive (shared network
 * namespace), so each mode is a separate PHPUnit run driven by dev/wp8/checkrad_gate.sh; a test whose mode is not the
 * current lab mode is skipped. Every case records its evidence before asserting.
 *
 *   testSnmp*          nas.type mikrotik_snmp, SNMP healthy (Gates A, B, C, D router-side, Hotspot)
 *   testFaultWalk*     nas.type mikrotik_snmp, snmpwalk unavailable inside FreeRADIUS (Gate D)
 *   testFaultError*    nas.type mikrotik_snmp, stock checkrad returns 2 = error (Gate D)
 *   testOther*         nas.type other (Gate E control)
 *
 * GATE RESULT (2026-10-07): A, B, C, E and Hotspot 1 / 2 / accounting-lost PASSED; D FAILED (fails open, see below) ->
 * Option F gate FAILED, not implemented. Hotspot: checkrad cannot see Hotspot sessions (no <...-user> interface, exit
 * 0), so the SQL layer admits and RouterOS Port-Limit is the layer that refuses.
 */
final class CheckradGateTest extends TestCase
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

    /** the lab gate mode this test needs (set between runs by dev/wp8/checkrad_gate.py) */
    private function requireMode(string $type, string $fault = 'none'): array
    {
        $st = Wp8Lab::agent('checkrad_state');
        $fr = implode("\n", $st['freeradius']);
        $nas = explode("\t", $st['nas'][0]);
        $now = !str_contains($fr, 'marker=1') ? 'gate-off'
            : (str_contains($fr, 'snmpwalk_exec=0') ? 'walk' : ($nas[2] !== 'mikrotik_snmp' || str_contains($fr, 'naspasswd_entry=10.88.0.2 SNMP ') ? 'none' : 'error'));
        if ($nas[2] !== $type || $now !== $fault) {
            $this->markTestSkipped("checkrad gate mode {$type}/{$fault} required, lab is {$nas[2]}/{$now} (dev/wp8/checkrad_gate.sh)");
        }
        $this->assertStringContainsString('delete_stale_sessions = no', $fr);
        return $st;
    }

    private function pppoeUser(string $password): string
    {
        $u = $this->provisionPolicy(['username' => self::ident('cr'), 'password' => $password, 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
        $this->assertSame([['Simultaneous-Use', ':=', '1']], $this->rows("SELECT attribute, op, value FROM radcheck WHERE username = ? AND attribute IN ('Simultaneous-Use', 'Port-Limit')", $u));
        $this->assertSame([], $this->rows("SELECT attribute FROM radreply WHERE username = ? AND attribute = 'Port-Limit'", $u), 'PPPoE: no Port-Limit dependency');
        return $u;
    }

    /** Hotspot: Simultaneous-Use (accepted builder) + Port-Limit max(1, shared_users) injected into the LAB policy only */
    private function hotspotUser(string $password, int $shared): string
    {
        $u = $this->provisionPolicy(['username' => self::ident('crhs'), 'password' => $password], ['type' => 'Hotspot', 'shared_users' => $shared], self::BW, new \DateTimeImmutable('+1 day'));
        static::labRadius()->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Port-Limit', ':=', ?)")->execute([$u, (string) max(1, $shared)]);
        $this->assertSame([['Simultaneous-Use', ':=', (string) $shared]], $this->rows("SELECT attribute, op, value FROM radcheck WHERE username = ? AND attribute = 'Simultaneous-Use'", $u));
        return $u;
    }

    private function rows(string $sql, string ...$p): array
    {
        $st = static::labRadius()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_NUM);
    }

    /** the complete radacct row (every column) - the byte-for-byte history comparison */
    private function fullRow(int $id): array
    {
        $st = static::labRadius()->prepare('SELECT * FROM radacct WHERE radacctid = ?');
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC);
    }

    private function ageSeconds(int $id): int
    {
        return (int) $this->rows('SELECT TIMESTAMPDIFF(SECOND, COALESCE(acctupdatetime, acctstarttime), NOW()) FROM radacct WHERE radacctid = ?', (string) $id)[0][0];
    }

    private function blockAccounting(): string
    {
        return $this->rules[] = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_drill');
    }

    private function unblock(string $id): void
    {
        Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
        $this->rules = array_values(array_diff($this->rules, [$id]));
    }

    private function liveC1(string $user, string $password): array
    {
        $c1 = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => $password]);
        $this->assertTrue($c1['ok'], json_encode($c1['log'] ?? null));
        $row = Wp8Lab::until(fn() => $this->acct($user)[0] ?? null, 20, 1);
        $this->assertNotNull($row, 'radacct Start recorded');
        $this->assertNull($row['acctstoptime']);
        $this->assertCount(1, Wp8Lab::pppActive($user));
        return ['ip' => $c1['ip'], 'row' => $row];
    }

    /** the checkrad runs for $user (trace wrapper) and the FreeRADIUS session-section lines of its last Access-Request */
    private function decision(string $user): array
    {
        $lines = Wp8Lab::frLog('^\(\d+\) (Received Access-Request|  User-Name = |.*[Ss]ession|.*[Ss]imul|.*SELECT (COUNT|radacctid)|.*Failed to check|.*Multiple|Sent Access-|  Reply-Message)|^\[wp8-checkrad\]|checkrad|Check-TS');
        $req = null;
        $from = 0;
        foreach ($lines as $i => $l) {
            if (preg_match('/^\((\d+)\)   User-Name = "' . preg_quote($user, '/') . '"$/', $l, $m) && preg_match('/^\(' . $m[1] . '\) Received Access-Request/', $lines[$i - 1] ?? '')) {
                [$req, $from] = [$m[1], $i - 1];
            }
        }
        // request numbers restart with every FreeRADIUS restart: only the lines from that Access-Request on
        $own = $req === null ? [] : array_values(array_filter(array_slice($lines, $from), static fn($l) => str_starts_with($l, '(' . $req . ') ') && !str_contains($l, 'User-Name')));
        $own = array_map(static fn($l) => preg_replace("/'[^']{40,}'/", "'…'", $l), $own);
        $runs = array_values(array_filter($lines, static fn($l) => str_contains($l, 'user=' . $user . ' ')));
        return ['request' => $req, 'session_section' => $own, 'checkrad_runs' => array_slice($runs, -6),
            'checkrad_exits' => array_map(static fn($l) => (int) preg_replace('/.* exit=(\d+) .*/', '$1', $l), array_slice($runs, -6)),
            'other_type_notice' => array_values(array_filter($lines, static fn($l) => str_contains($l, 'not checking')))];
    }

    /** C2 (independent client c3) tries the same username; the NAS is sampled the whole time */
    private function contest(string $user, string $password, int $seconds = 35): array
    {
        Wp8Lab::agent('capture_start', ['name' => 'od16f-' . substr($user, -6)]);
        Wp8Lab::agent('pppoe_start', ['client' => 'c3', 'user' => $user, 'password' => $password]);
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'ppp', 'user' => $user, 'seconds' => $seconds], $seconds + 60);
        $cap = Wp8Lab::agent('capture_stop', ['name' => 'od16f-' . substr($user, -6)]);
        $packets = array_map(static fn($p) => [$p['t'], $p['code'], $p['src'], $p['dst'], $p['id']],
            array_values(array_filter($cap['packets'], static fn($p) => str_starts_with($p['code'], 'Access'))));
        return ['watch' => $watch, 'packets' => array_slice($packets, -8), 'reply_to_c2' => self::lastReply($user), 'decision' => $this->decision($user),
            'nas_after' => array_map(static fn($a) => [$a['caller-id'], $a['address'], $a['radius']], Wp8Lab::pppActive($user)),
            'c1_state' => array_intersect_key(Wp8Lab::agent('pppoe_state', ['client' => 'c1']), ['running' => 1, 'ip' => 1]),
            'c2_state' => array_intersect_key(Wp8Lab::agent('pppoe_state', ['client' => 'c3']), ['running' => 1, 'ip' => 1]),
            'router_log' => array_slice($this->routerLog($user), -8)];
    }

    private function routerLog(string $user): array
    {
        return array_values(array_map(static fn($r) => ($r['time'] ?? '') . ' ' . $r['message'],
            array_filter(Wp8Lab::ros('/log/print'), static fn($r) => str_contains($r['message'] ?? '', $user) || str_contains($r['message'] ?? '', 'radius'))));
    }

    private function radacctView(string $user): array
    {
        return array_map(static fn($a) => [$a['radacctid'], $a['acctsessionid'], $a['acctstarttime'], $a['acctupdatetime'], $a['acctstoptime'], $a['acctterminatecause']], $this->acct($user));
    }

    // ------------------------------------------------------------------ Gate A: healthy live PPPoE session

    public function testSnmpGateAHealthyLiveSessionDuplicateIsRejectedByCheckrad(): void
    {
        $this->requireMode('mikrotik_snmp');
        $user = $this->pppoeUser('Cr-a-1');
        $c1 = $this->liveC1($user, 'Cr-a-1');
        $interim = Wp8Lab::until(fn() => ($r = $this->acct($user)[0]) && $r['acctupdatetime'] !== null && $r['acctupdatetime'] > $r['acctstarttime'] ? $r : null, 90, 2);
        $this->assertNotNull($interim, 'Interim-Update received');
        $r = ['c1' => $c1['ip'], 'interim' => [$interim['acctstarttime'], $interim['acctupdatetime']]] + $this->contest($user, 'Cr-a-1');
        $r['radacct'] = $this->radacctView($user);
        Wp8Lab::evidence('OD16F.gateA.healthy-live-duplicate', $r);
        $this->assertSame('Access-Reject', $r['reply_to_c2']['code'], 'C2 rejected');
        $this->assertContains(1, $r['decision']['checkrad_exits'], 'checkrad asked the NAS and reported the session LIVE (exit 1)');
        $this->assertNotEmpty(array_filter($r['decision']['session_section'], static fn($l) => str_contains($l, 'SELECT radacctid')), 'simul_verify_query ran');
        $this->assertSame(1, $r['watch']['max'], 'never two live PPPoE sessions');
        $this->assertTrue($r['c1_state']['running']);
        $this->assertNull($r['c2_state']['ip']);
    }

    // ------------------------------------------------------------------ Gate B: lost Stop, session really gone

    public function testSnmpGateBLostStopGoneSessionAllowsLoginAndKeepsHistory(): void
    {
        $this->requireMode('mikrotik_snmp');
        $user = $this->pppoeUser('Cr-b-1');
        $c1 = $this->liveC1($user, 'Cr-b-1');
        $old = (int) $c1['row']['radacctid'];
        $rule = $this->blockAccounting();
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $gone = Wp8Lab::until(fn() => Wp8Lab::pppActive($user) === [], 30, 1);
        $this->assertTrue($gone, 'no live RouterOS session for the user');
        sleep(5);                                                   // the Stop was sent into the drop rule
        $this->unblock($rule);
        $before = $this->fullRow($old);
        $this->assertNull($before['acctstoptime'], 'Stop lost: the radacct row stays OPEN');
        $again = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Cr-b-1']);
        $r = ['relogin' => ['ok' => $again['ok'], 'ip' => $again['ip'] ?? null], 'reply' => self::lastReply($user), 'decision' => $this->decision($user),
            'nas_after' => array_map(static fn($a) => [$a['caller-id'], $a['address']], Wp8Lab::pppActive($user))];
        $two = Wp8Lab::until(fn() => count($this->acct($user)) === 2, 20, 1);
        $afterLogin = $this->fullRow($old);
        // AccountingService::staleSessions() must still report the historical row once it is older than the threshold
        while ($this->ageSeconds($old) < 125) {
            sleep(5);
        }
        // WP8 A4 (R-WP8-CARRY-03 / OBS-W8-8 fixed): staleSessions() compares epochs (UNIX_TIMESTAMP in the database session),
        // so it is given the APPLICATION clock (Asia/Baghdad) while the lab database is UTC; the former database-clock
        // workaround (OD-A4-5) is gone and the application clock must report the historical row only, never the live one
        $dbNow = $this->rows('SELECT NOW()')[0][0];
        $appNow = new \DateTimeImmutable('now');
        $this->assertNotSame(0, $appNow->getOffset(), 'discriminating: the application zone is not the database zone (UTC)');
        $svc = new AccountingService(static::labRadius());
        $staleIds = array_map('intval', array_column(array_filter($svc->staleSessions($appNow, 120), static fn($s) => $s['username'] === $user), 'radacctid'));
        $afterStale = $this->fullRow($old);
        $r += ['radacct' => $this->radacctView($user), 'stale_reported' => $staleIds, 'obs_w8_8_app_clock' => ['app_now' => $appNow->format('Y-m-d H:i:s T'), 'db_now' => $dbNow, 'stale_reported_with_app_clock' => $staleIds], 'old_row_age_s' => $this->ageSeconds($old),
            'old_row_unchanged_after_login' => $before === $afterLogin, 'old_row_unchanged_after_stale_check' => $before === $afterStale,
            'old_row_truth' => array_intersect_key($before, array_flip(['radacctid', 'acctsessionid', 'acctuniqueid', 'acctstarttime', 'acctupdatetime', 'acctstoptime', 'acctsessiontime', 'acctinputoctets', 'acctoutputoctets', 'acctterminatecause']))];
        Wp8Lab::evidence('OD16F.gateB.lost-stop-session-gone', $r);
        $this->assertTrue($again['ok'], 'new login ALLOWED without reboot, freshness timeout, delete or fabricated Stop');
        $this->assertSame('Access-Accept', $r['reply']['code']);
        $this->assertContains(0, $r['decision']['checkrad_exits'], 'checkrad asked the NAS: old session not live (exit 0)');
        $this->assertTrue($two, 'radacct: the stale historical row AND the new genuine session row');
        $this->assertSame($before, $afterLogin, 'old row byte-for-byte unchanged by the admission');
        $this->assertSame($before, $afterStale);
        $this->assertNull($afterStale['acctstoptime']);
        $this->assertSame('', (string) $afterStale['acctterminatecause']);
        $this->assertSame([$old], $staleIds, 'staleSessions() with the application clock reports the historical row, not the live one (OBS-W8-8 fixed)');
    }

    // ------------------------------------------------------------------ Gate C: accounting lost, session still live

    public function testSnmpGateCAccountingLostLiveSessionStillProtected(): void
    {
        $this->requireMode('mikrotik_snmp');
        $user = $this->pppoeUser('Cr-c-1');
        $c1 = $this->liveC1($user, 'Cr-c-1');
        $this->blockAccounting();                                   // UDP/1813 only; 1812 stays healthy
        sleep(190);
        $this->assertCount(1, Wp8Lab::pppActive($user), 'C1 genuinely live on RouterOS');
        $age = $this->ageSeconds((int) $c1['row']['radacctid']);
        $r = ['acct_age_s_at_attempt' => $age] + $this->contest($user, 'Cr-c-1');
        $r['radacct'] = $this->radacctView($user);
        Wp8Lab::evidence('OD16F.gateC.accounting-lost-live', $r);
        $this->assertGreaterThanOrEqual(180, $age, 'no fresh accounting for > 180 s');
        $this->assertSame('Access-Reject', $r['reply_to_c2']['code']);
        $this->assertContains(1, $r['decision']['checkrad_exits'], 'the NAS reported the session LIVE');
        $this->assertSame(1, $r['watch']['max'], 'never two real PPPoE sessions');
        $this->assertTrue($r['c1_state']['running']);
    }

    // ------------------------------------------------------------------ Gate D: verification failure must fail closed

    private function gateD(string $case, string $password, ?callable $fault = null): array
    {
        $user = $this->pppoeUser($password);
        $c1 = $this->liveC1($user, $password);
        $f = $fault ? $fault() : null;
        $r = ['fault' => $f] + $this->contest($user, $password, 40);
        $r['radacct'] = $this->radacctView($user);
        Wp8Lab::evidence('OD16F.gateD.' . $case, $r);
        return $r;
    }

    /*
     * GATE D RESULT (2026-10-07): FAILED - verification failure FAILS OPEN on FreeRADIUS 3.2.8. The three tests below
     * assert the OBSERVED behaviour so the suite documents the fact; Option F was not implemented (owner stop).
     *   stock checkrad mikrotik_snmp: an SNMP walk that returns nothing (timeout, no SNMP client, wrong community)
     *     counts as "user not logged in" (exit 0) - the open row is treated as stale;
     *   rlm_sql / process_checksimul: checkrad exit 2 -> "Failed to check the terminal server" -> session = fail ->
     *     the Simultaneous-Use check is skipped and the request is ACCEPTED.
     */
    public function testSnmpGateDRouterSnmpUnreachableDecisionFailsOpen(): void
    {
        $this->requireMode('mikrotik_snmp');
        $r = $this->gateD('router-snmp-dropped', 'Cr-d-1', function () {
            $this->rules[] = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=input', '=protocol=udp', '=dst-port=161', '=action=drop', '=comment=nuxr_wp8_drill');
            return 'RouterOS input drop udp/161 (SNMP unreachable from FreeRADIUS)';
        });
        $run = end($r['decision']['checkrad_runs']);
        $this->assertSame([0], array_slice($r['decision']['checkrad_exits'], -1), 'observed: the SNMP timeout is reported as "not logged in"');
        $this->assertGreaterThanOrEqual(9000, (int) preg_replace('/.* ms=(\d+)$/', '$1', $run), 'after the ~10 s snmpwalk timeout');
        $this->assertSame('Access-Accept', $r['reply_to_c2']['code'], 'observed: FreeRADIUS ADMITS the duplicate');
        // no second session here ONLY because RouterOS (timeout 3 s, 3 tries) had already given up ("radius timeout")
        $this->assertSame(1, $r['watch']['max'], 'observed: the late Accept was discarded by RouterOS');
        $this->assertNotEmpty(array_filter($r['router_log'], static fn($l) => str_contains($l, 'radius timeout')));
    }

    public function testFaultWalkGateDSnmpClientUnavailableFailsOpen(): void
    {
        $this->requireMode('mikrotik_snmp', 'walk');
        $r = $this->gateD('snmpwalk-unavailable', 'Cr-d-2');
        $this->assertSame([0], array_slice($r['decision']['checkrad_exits'], -1), 'observed: no SNMP client -> "not logged in"');
        $this->assertSame('Access-Accept', $r['reply_to_c2']['code'], 'observed: FreeRADIUS ADMITS the duplicate');
        $this->assertSame(2, $r['watch']['max'], 'observed: TWO genuine PPPoE sessions for Simultaneous-Use 1');
        $this->assertNotNull($r['c2_state']['ip']);
    }

    public function testFaultErrorGateDCheckradErrorFailsOpen(): void
    {
        $this->requireMode('mikrotik_snmp', 'error');
        $r = $this->gateD('checkrad-exit-2', 'Cr-d-3');
        $this->assertSame([2], array_slice($r['decision']['checkrad_exits'], -1), 'checkrad reported an error (exit 2)');
        $this->assertNotEmpty(array_filter($r['decision']['session_section'], static fn($l) => str_contains($l, 'Failed to check the terminal server')));
        $this->assertNotEmpty(array_filter($r['decision']['session_section'], static fn($l) => str_contains($l, '} # session = fail')));
        $this->assertSame('Access-Accept', $r['reply_to_c2']['code'], 'observed: session = fail is IGNORED, the duplicate is ADMITTED');
        $this->assertSame(2, $r['watch']['max'], 'observed: TWO genuine PPPoE sessions for Simultaneous-Use 1');
        $this->assertNotNull($r['c2_state']['ip']);
    }

    // ------------------------------------------------------------------ Gate E: nas.type other control

    public function testOtherGateENoNasVerificationLostStopLocksOut(): void
    {
        $this->requireMode('other');
        $user = $this->pppoeUser('Cr-e-1');
        $c1 = $this->liveC1($user, 'Cr-e-1');
        $rule = $this->blockAccounting();
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $this->assertTrue(Wp8Lab::until(fn() => Wp8Lab::pppActive($user) === [], 30, 1));
        sleep(5);
        $this->unblock($rule);
        $again = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Cr-e-1', 'wait' => 25]);
        $r = ['relogin_ok' => $again['ok'], 'reply' => self::lastReply($user), 'decision' => $this->decision($user), 'radacct' => $this->radacctView($user),
            'nas_after' => Wp8Lab::pppActive($user)];
        Wp8Lab::evidence('OD16F.gateE.type-other-control', $r);
        $this->assertFalse($again['ok'], 'type other: the stale row is trusted, the user is locked out');
        $this->assertSame('Access-Reject', $r['reply']['code']);
        $this->assertSame([], $r['decision']['checkrad_runs'], 'checkrad never ran');
        $this->assertNotEmpty($r['decision']['other_type_notice']);
    }

    // ------------------------------------------------------------------ Hotspot: Simultaneous-Use + Port-Limit

    private function hotspotContest(string $user, string $password, array $clients): array
    {
        $out = [];
        foreach ($clients as $c) {
            Wp8Lab::agent('hs_dhcp', ['client' => $c]);
        }
        foreach ($clients as $c) {
            $l = Wp8Lab::agent('hs_login', ['client' => $c, 'user' => $user, 'password' => $password]);
            $out[$c] = ['ok' => $l['ok'], 'message' => $l['message'], 'freeradius' => self::lastReply($user)['code'],
                'checkrad_exits' => $this->decision($user)['checkrad_exits'], 'active_now' => count(Wp8Lab::hotspotActive($user))];
            sleep(2);
        }
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'hotspot', 'user' => $user, 'seconds' => 15], 60);
        return ['logins' => $out, 'watch' => $watch, 'nas_after' => array_map(static fn($a) => [$a['mac-address'], $a['address'], $a['radius']], Wp8Lab::hotspotActive($user)),
            'radacct' => $this->radacctView($user), 'router_log' => array_slice($this->routerLog($user), -8)];
    }

    public function testSnmpHotspotShared1(): void
    {
        $this->requireMode('mikrotik_snmp');
        $user = $this->hotspotUser('Cr-hs-1', 1);
        $r = $this->hotspotContest($user, 'Cr-hs-1', ['c2', 'c4']);
        Wp8Lab::evidence('OD16F.hotspot.shared-1', $r);
        $this->assertTrue($r['logins']['c2']['ok']);
        $this->assertFalse($r['logins']['c4']['ok'], 'second device refused');
        $this->assertLessThanOrEqual(1, $r['watch']['max']);
    }

    public function testSnmpHotspotShared2(): void
    {
        $this->requireMode('mikrotik_snmp');
        $user = $this->hotspotUser('Cr-hs-2', 2);
        $r = $this->hotspotContest($user, 'Cr-hs-2', ['c2', 'c4', 'c5']);
        Wp8Lab::evidence('OD16F.hotspot.shared-2', $r);
        $this->assertTrue($r['logins']['c2']['ok'] && $r['logins']['c4']['ok'], 'first two allowed');
        $this->assertFalse($r['logins']['c5']['ok'], 'third refused');
        $this->assertLessThanOrEqual(2, $r['watch']['max']);
    }

    public function testSnmpHotspotShared1AccountingLost(): void
    {
        $this->requireMode('mikrotik_snmp');
        $user = $this->hotspotUser('Cr-hs-3', 1);
        Wp8Lab::agent('hs_dhcp', ['client' => 'c2']);
        Wp8Lab::agent('hs_dhcp', ['client' => 'c4']);
        $this->assertTrue(Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $user, 'password' => 'Cr-hs-3'])['ok']);
        Wp8Lab::until(fn() => $this->acct($user) ?: null, 20, 1);
        $this->blockAccounting();
        sleep(190);
        $this->assertCount(1, Wp8Lab::hotspotActive($user), 'first device genuinely live');
        $l = Wp8Lab::agent('hs_login', ['client' => 'c4', 'user' => $user, 'password' => 'Cr-hs-3']);
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'hotspot', 'user' => $user, 'seconds' => 15], 60);
        $r = ['second_login' => ['ok' => $l['ok'], 'message' => $l['message']], 'freeradius' => self::lastReply($user)['code'], 'decision' => $this->decision($user),
            'watch' => $watch, 'nas_after' => array_map(static fn($a) => [$a['mac-address'], $a['address']], Wp8Lab::hotspotActive($user)),
            'radacct' => $this->radacctView($user), 'router_log' => array_slice($this->routerLog($user), -8)];
        Wp8Lab::evidence('OD16F.hotspot.shared-1-accounting-lost', $r);
        $this->assertFalse($l['ok'], 'second device refused');
        $this->assertLessThanOrEqual(1, $watch['max']);
    }
}
