<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;

/**
 * WP8 A2 - CONCURRENT FIRST-LOGIN RACE GATE (owner plan approval 2026-10-09, decisions D-A2-1..4) on the real RouterOS
 * CHR 7.24.5 with the PERMANENT A1 configuration (Simultaneous-Use + session { sql } + fail-closed PPPoE post-auth
 * guard; NAS type mikrotik_snmp + guard credentials = the production contract). Investigation only: no fix.
 *
 * Two independent PPPoE clients (namespaces wp8c1 / wp8c3, own MACs, same PPPoE segment) log in with the SAME username,
 * released by a barrier inside the lab agent (`pppoe_race`) with a measured launch skew. Independent observers: the NAS
 * active table (own API connection, ~150 ms), each client's ppp address and ICMP over its own link, the RADIUS pcap
 * (host clock = the agent clock), the FreeRADIUS per-request decisions, radacct, the RouterOS log.
 *
 * Verdict per trial (owner D-A2-2): RACE_PROVEN (PERSISTENT_ / TRANSIENT_DOUBLE) only when two Access-Accepts, two
 * distinct live NAS sessions in the same NAS sample, both clients up at the same time AND both links carrying traffic
 * within 0.5 s of each other. Evidence is recorded BEFORE any assertion; the gate assertion is "at most one live
 * session". Clients and firewall rules are always cleaned up.
 *
 * GATE STATUS (owner review 2026-10-09): evidence ACCEPTED, SECURITY GATE FAILED - A2_RACE_PROVEN (B0, C, E3; see
 * owner-review/WP8/evidence/a2-concurrent-first-login/A2_RESULTS.md). This test FAILS BY DESIGN until the A2-R
 * remediation is implemented; it runs only in the separate PHPUnit suite `wp8-security-gates` (excluded from
 * `wp8-integration`), and its assertions must not be weakened to obtain a pass.
 */
final class ConcurrentFirstLoginGateTest extends TestCase
{
    use Wp8LabHelpers;

    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];
    private const CLIENTS = ['c1', 'c3'];
    /** set once a race is proven: later scenarios run once each (root-cause characterization only, owner rule) */
    private static bool $raceProven = false;
    private array $rules = [];

    /** true once a trial of THIS lab run proved the race (this process, or an earlier group recorded in the evidence) */
    private static function raceProven(): bool
    {
        if (self::$raceProven) {
            return true;
        }
        $f = '/wp8ctl/evidence.jsonl';
        return is_file($f) && (bool) preg_match('/"case":"A2\\.[^"]*","verdict":\{"verdict":"RACE_PROVEN/', (string) file_get_contents($f));
    }

    protected function setUp(): void
    {
        $this->requireLab();
        $st = Wp8Lab::agent('option_g', ['cmd' => 'state']);
        $fr = implode("\n", $st['freeradius']);
        $nas = explode("\t", (string) ($st['nas'][0] ?? ''));
        if (($nas[2] ?? '') !== 'mikrotik_snmp' || !str_contains($fr, 'persistent_creds=1') || !str_contains($fr, 'post_auth_policy=1')) {
            $this->markTestSkipped('A2 needs the production-contract lab (dev/wp8/option_g_gate.py on shipped)');
        }
        $this->assertStringContainsString('delete_stale_sessions = no', $fr);
        $this->assertStringContainsString('lab_policy_refs=0', $fr, 'the permanent A1 policy, not the gate copy');
    }

    protected function tearDown(): void
    {
        if (static::labRadius() === null) {
            return;
        }
        foreach ($this->rules as $id) {
            Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
        }
        $this->rules = [];
        foreach (self::CLIENTS as $c) {
            Wp8Lab::agent('pppoe_down', ['client' => $c]);
        }
    }

    private function block(string ...$words): string
    {
        return $this->rules[] = Wp8Lab::rosAdd('/ip/firewall/filter/add', ...[...$words, '=action=drop', '=comment=nuxr_wp8_a2']);
    }

    private function unblock(string $id): void
    {
        Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
        $this->rules = array_values(array_diff($this->rules, [$id]));
    }

    private function blockAccounting(): string
    {
        return $this->block('=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10');
    }

    private function blockSnmp(): string
    {
        return $this->block('=chain=input', '=protocol=udp', '=dst-port=161');
    }

    private function user(string $password): string
    {
        return $this->provisionPolicy(['username' => self::ident('a2'), 'password' => $password, 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
    }

    private function cleanup(string $user): void
    {
        foreach (self::CLIENTS as $c) {
            Wp8Lab::agent('pppoe_down', ['client' => $c]);
        }
        Wp8Lab::until(fn() => Wp8Lab::pppActive($user) === [], 20, 0.5);
    }

    /** radacct rows of $user (history is never touched) */
    private function rows(string $user): array
    {
        $st = static::labRadius()->prepare('SELECT radacctid, acctsessionid, nasipaddress, callingstationid, acctstarttime, acctupdatetime, acctstoptime, acctterminatecause FROM radacct WHERE username = ? ORDER BY radacctid');
        $st->execute([$user]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * One race trial. Returns the classification and records the evidence (before any assertion).
     *
     * @return array<string,mixed>
     */
    private function race(string $case, string $user, string $password, int $offsetMs, int $observe = 20, ?callable $after = null): array
    {
        $cap = 'a2-' . substr($user, -6) . '-' . $offsetMs;
        Wp8Lab::agent('capture_start', ['name' => $cap]);
        $r = Wp8Lab::agent('pppoe_race', ['clients' => self::CLIENTS, 'user' => $user, 'password' => $password, 'offset_ms' => $offsetMs, 'observe' => $observe], $observe + 90);
        $extra = $after ? $after() : null;
        $pcap = Wp8Lab::agent('capture_stop', ['name' => $cap])['packets'];
        // only the Access-Requests of THIS trial (joined with its own pcap on RADIUS Id + NAS source port): setup
        // logins of the same user (e.g. the session that left the stale row in E1 / E2) are never counted
        $own = [];
        foreach ($pcap as $p) {
            if ($p['code'] === 'Access-Request') {
                $own[$p['id'] . '/' . (int) substr(strrchr($p['src'], ':'), 1)] = true;
            }
        }
        $decisions = array_values(array_filter(self::accessDecisions($user), static fn($d) => isset($own[$d['radius_id'] . '/' . $d['src_port']])));
        $log = array_values(array_filter(Wp8Lab::ros('/log/print'), static fn($x) => str_contains($x['message'] ?? '', $user)));
        $rows = $this->rows($user);
        $v = self::classify($r, $decisions, $offsetMs);
        $t0 = (int) $r['t_start_ns'];
        $rel = static fn($ns) => $ns === null ? null : round(((int) $ns - $t0) / 1e6, 3);             // ms since the trial start
        $transitions = [];
        $prev = null;
        foreach ($r['samples'] as $s) {
            $key = $s['n'] . '|' . implode(',', array_column($s['rows'], 'session-id'));
            if ($key !== $prev) {
                $transitions[] = ['t_ms' => $rel($s['t0_ns']), 't1_ms' => $rel($s['t1_ns']), 'n' => $s['n'], 'err' => $s['err'],
                    'sessions' => array_map(static fn($x) => [$x['session-id'], $x['caller-id'], $x['address'], $x['uptime']], $s['rows'])];
                $prev = $key;
            }
        }
        $clients = [];
        foreach (self::CLIENTS as $c) {
            $o = $r['clients'][$c];
            $clients[$c] = ['launch_ms' => $rel($o['launch_ns']), 'ip_up_ms' => $rel($o['ip_up_ns']), 'ip_down_ms' => $rel($o['ip_down_ns']), 'ip' => $o['ip'],
                'pppd_exit' => $o['pppd_exit'] ? [$o['pppd_exit'][0], $rel($o['pppd_exit'][1])] : null,
                'ping_ok' => count($o['ping_ok_ns']), 'ping_sent' => $o['ping_sent'],
                'ping_first_ms' => $rel($o['ping_ok_ns'][0] ?? null), 'ping_last_ms' => $rel($o['ping_ok_ns'] ? end($o['ping_ok_ns']) : null), 'pppd' => $r['logs'][$c]];
        }
        $evidence = ['verdict' => $v, 'user' => $user, 'offset_ms' => $offsetMs, 't_start_ns' => $t0, 'time_base' => 'host CLOCK_REALTIME ns (agent and tcpdump on the same host)',
            'launch_skew_ms' => round($r['launch_skew_ns'] / 1e6, 3), 'clients' => $clients,
            'nas' => ['max' => $r['nas_max'], 'double_samples' => $r['nas_double_samples'], 'double_first_ms' => $rel($r['nas_double_first_ns']), 'double_last_ms' => $rel($r['nas_double_last_ns']),
                'session_ids' => $r['nas_session_ids'], 'samples' => $r['sample_count'], 'max_gap_ms' => $r['max_gap_ms'], 'mean_api_ms' => $r['mean_api_ms'], 'errors' => $r['sample_errors'], 'transitions' => $transitions],
            'client_overlap_ms' => $r['client_overlap_ns'] ? [$rel($r['client_overlap_ns'][0]), $rel($r['client_overlap_ns'][1])] : null, 'ping_pairs_within_500ms' => $r['ping_pairs_within_500ms'],
            'radius' => array_map(static fn($p) => ['t_ms' => round(($p['t_us'] * 1000 - $t0) / 1e6, 3), 't_us' => $p['t_us'], 'code' => $p['code'], 'id' => $p['id'], 'src' => $p['src'], 'dst' => $p['dst']], $pcap),
            'decisions' => $decisions, 'radacct' => $rows, 'routeros_log' => array_map(static fn($x) => [$x['time'] ?? null, $x['topics'] ?? null, $x['message'] ?? null], array_slice($log, -12)),
            'routeros_clock' => $r['ros_clock'], 'extra' => $extra];
        Wp8Lab::evidence('A2.' . $case, $evidence);
        $this->cleanup($user);
        if (str_starts_with($v['verdict'], 'RACE_PROVEN')) {
            self::$raceProven = true;
        }
        return $evidence;
    }

    /** owner D-A2-2: a double session counts only with independent, simultaneous proof from NAS, clients and traffic */
    private static function classify(array $r, array $decisions, int $offsetMs): array
    {
        $accepts = count(array_filter($decisions, static fn($d) => $d['reply'] === 'Access-Accept'));
        $doubleNas = false;
        foreach ($r['samples'] as $s) {
            $ids = array_unique(array_filter(array_column($s['rows'], 'session-id')));
            $macs = array_unique(array_filter(array_column($s['rows'], 'caller-id')));
            if ($s['n'] >= 2 && count($ids) >= 2 && count($macs) >= 2) {
                $doubleNas = true;
            }
        }
        $proof = ['two_accepts' => $accepts >= 2, 'nas_two_distinct_sessions' => $doubleNas, 'clients_up_together' => $r['client_overlap_ns'] !== null,
            'traffic_on_both_links' => $r['ping_pairs_within_500ms'] > 0];
        $quality = ['launch_skew_ms' => round(abs($r['launch_skew_ns'] - $offsetMs * 1_000_000) / 1e6, 3), 'max_gap_ms' => $r['max_gap_ms'], 'sample_errors' => $r['sample_errors']];
        if (!in_array(false, $proof, true)) {
            $persistent = $r['nas_double_last_ns'] !== null && end($r['samples'])['n'] >= 2;
            return ['verdict' => 'RACE_PROVEN_' . ($persistent ? 'PERSISTENT_DOUBLE' : 'TRANSIENT_DOUBLE'), 'proof' => $proof, 'quality' => $quality];
        }
        // fewer than two Access-Accepts is decisive from the RADIUS decisions alone: the NAS cannot open a RADIUS
        // session without an Accept, so NAS sampling gaps cannot hide a second session in that case
        if ($accepts < 2) {
            return ['verdict' => $accepts === 1 ? 'SINGLE_SESSION' : 'NO_SESSION', 'proof' => $proof, 'quality' => $quality];
        }
        if ($quality['launch_skew_ms'] > 50 || $quality['max_gap_ms'] === null || $quality['max_gap_ms'] > 300 || $quality['sample_errors'] > 0) {
            return ['verdict' => 'INCONCLUSIVE', 'proof' => $proof, 'quality' => $quality];
        }
        return ['verdict' => 'RADIUS_DOUBLE_ACCEPT_NAS_HELD', 'proof' => $proof, 'quality' => $quality];
    }

    private function assertGate(array $trials): void
    {
        foreach ($trials as $i => $t) {
            $this->assertStringStartsNotWith('RACE_PROVEN', $t['verdict']['verdict'],
                "trial {$i} ({$t['offset_ms']} ms): two live PPPoE sessions of one username - SECURITY GATE FAILED (" . json_encode($t['verdict']['proof']) . ')');
        }
    }

    // ================================================================== A: first-login baseline (window size)

    public function testA_FirstLoginBaselineWindow(): void
    {
        $out = [];
        for ($i = 0; $i < (self::raceProven() ? 1 : 5); $i++) {
            $u = $this->user('A2-base-1');
            Wp8Lab::agent('capture_start', ['name' => 'a2-base-' . $i]);
            $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $u, 'password' => 'A2-base-1']);
            $row = Wp8Lab::until(fn() => $this->rows($u), 20, 0.25);
            $snmp = Wp8Lab::agent('guard_invoke', ['argv' => [bin2hex($u), '1', '10.88.0.2']]);
            $pcap = Wp8Lab::agent('capture_stop', ['name' => 'a2-base-' . $i])['packets'];
            $accept = null;
            $start = null;
            foreach ($pcap as $p) {
                if ($accept === null && $p['code'] === 'Access-Accept') {
                    $accept = $p['t_us'];
                } elseif ($accept !== null && $start === null && $p['code'] === 'Accounting-Request' && str_starts_with($p['src'], '10.88.0.2:')) {
                    $start = $p['t_us'];
                }
            }
            $out[] = ['ok' => $up['ok'], 'accept_to_accounting_start_ms' => $accept && $start ? round(($start - $accept) / 1000, 3) : null,
                'radacct_rows' => count($row ?: []), 'guard_after_start' => $snmp['out'] ?? null, 'decisions' => self::accessDecisions($u)];
            $this->cleanup($u);
        }
        Wp8Lab::evidence('A2.A.baseline', ['trials' => $out]);
        foreach ($out as $t) {
            $this->assertTrue($t['ok'], 'a single first login works');
            $this->assertNotNull($t['accept_to_accounting_start_ms'], 'Accept and Accounting-Start both captured');
            $this->assertSame('n=0;', $t['decisions'][0]['open_rows'] ?? null, 'first login: no open row, no verification (A1 contract)');
            $this->assertFalse($t['decisions'][0]['guard_ran']);
        }
    }

    // ================================================================== B0: concurrent same-NAS logins

    public function testB0_ConcurrentSameNas(): void
    {
        $trials = [];
        $proven = 0;
        for ($i = 0; $i < 10 && $proven < 2; $i++) {
            $u = $this->user('A2-b0-1');
            $t = $this->race('B0.' . $i, $u, 'A2-b0-1', 0);
            $trials[] = $t;
            $proven += str_starts_with($t['verdict']['verdict'], 'RACE_PROVEN') ? 1 : 0;
        }
        Wp8Lab::evidence('A2.B0.summary', ['verdicts' => array_map(static fn($t) => [$t['verdict']['verdict'], $t['launch_skew_ms']], $trials)]);
        $this->assertGate($trials);
    }

    // ================================================================== B-scan: staggered second login

    public function testBscan_StaggeredSecondLogin(): void
    {
        $trials = [];
        foreach ([100, 250, 500, 1000, 2000, 3000, 5000] as $off) {
            for ($i = 0; $i < (self::raceProven() ? 1 : 3); $i++) {
                $u = $this->user('A2-bs-1');
                $trials[] = $this->race("Bscan.{$off}.{$i}", $u, 'A2-bs-1', $off, 20 + (int) ceil($off / 1000));
            }
        }
        Wp8Lab::evidence('A2.Bscan.summary', ['verdicts' => array_map(static fn($t) => [$t['offset_ms'], $t['verdict']['verdict']], $trials)]);
        $this->assertGate($trials);
    }

    // ================================================================== C: delayed / lost Accounting-Start

    public function testC_LostAccountingStartWindow(): void
    {
        $trials = [];
        foreach ([5000, 30000] as $off) {
            for ($i = 0; $i < (self::raceProven() ? 1 : 3); $i++) {
                $u = $this->user('A2-c-1');
                $rule = $this->blockAccounting();
                $trials[] = $this->race("C.{$off}.{$i}", $u, 'A2-c-1', $off, (int) ceil($off / 1000) + 20, function () use ($rule, $u) {
                    // the path comes back while both clients are still up: does RouterOS send the lost Start later?
                    $this->unblock($rule);
                    $late = Wp8Lab::until(fn() => $this->rows($u), 75, 3);
                    return ['rows_after_unblock_75s' => $late ? count($late) : 0];
                });
            }
        }
        $this->assertGate($trials);
    }

    // ================================================================== E: failure conditions

    /** a real lost-Stop stale row: session up, accounting blocked, client gone, path restored (the Stop is not resent) */
    private function staleRow(string $u, string $password): array
    {
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $u, 'password' => $password])['ok']);
        $this->assertNotNull(Wp8Lab::until(fn() => $this->rows($u), 20, 0.5), 'Start recorded');
        $rule = $this->blockAccounting();
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $this->assertTrue((bool) Wp8Lab::until(fn() => Wp8Lab::pppActive($u) === [], 20, 0.5), 'the NAS session is gone');
        sleep(8);
        $this->unblock($rule);
        $rows = $this->rows($u);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['acctstoptime'], 'stale row open (Stop lost)');
        return $rows[0];
    }

    public function testE1_StaleRowAndSnmpFailureConcurrent(): void
    {
        $trials = [];
        for ($i = 0; $i < (self::raceProven() ? 1 : 3); $i++) {
            $u = $this->user('A2-e1-1');
            $stale = $this->staleRow($u, 'A2-e1-1');
            $snmp = $this->blockSnmp();
            $t = $this->race("E1.{$i}", $u, 'A2-e1-1', 0);
            $this->unblock($snmp);
            $t['stale_row_unchanged'] = $this->rows($u)[0] === $stale;
            $trials[] = $t;
        }
        Wp8Lab::evidence('A2.E1.summary', ['trials' => array_map(static fn($t) => [$t['verdict']['verdict'],
            array_map(static fn($d) => [$d['reply'], $d['open_rows'], $d['guard_ran'], $d['guard_verdict'], $d['stock_session_reject']], $t['decisions']), $t['stale_row_unchanged']], $trials)]);
        foreach ($trials as $t) {
            $this->assertTrue($t['stale_row_unchanged']);
            foreach ($t['decisions'] as $d) {
                $this->assertSame('Access-Reject', $d['reply'], 'stale row + SNMP down: every concurrent login refused (fail closed)');
            }
        }
        $this->assertGate($trials);
    }

    public function testE2_StaleRowAndHealthySnmpConcurrent(): void
    {
        $trials = [];
        // the planned 5 trials even after a proven first-login race: E2 is a DIFFERENT mechanism (both requests see
        // the stale row and the guard runs for both - threat T5), needed for the root cause / remediation scope
        for ($i = 0; $i < 5; $i++) {
            $u = $this->user('A2-e2-1');
            $this->staleRow($u, 'A2-e2-1');
            $trials[] = $this->race("E2.{$i}", $u, 'A2-e2-1', 0);
        }
        $this->assertGate($trials);
    }

    public function testE3_MissingAccountingAndSnmpFailureConcurrent(): void
    {
        $trials = [];
        for ($i = 0; $i < (self::raceProven() ? 1 : 3); $i++) {
            $u = $this->user('A2-e3-1');
            $acct = $this->blockAccounting();
            $snmp = $this->blockSnmp();
            $trials[] = $this->race("E3.{$i}", $u, 'A2-e3-1', 0);
            $this->unblock($snmp);
            $this->unblock($acct);
        }
        // owner E3 correction: with n=0 the guard is not called at all - record what actually happened, no inference
        Wp8Lab::evidence('A2.E3.summary', ['trials' => array_map(static fn($t) => [$t['verdict']['verdict'],
            array_map(static fn($d) => ['reply' => $d['reply'], 'open_rows' => $d['open_rows'], 'guard_ran' => $d['guard_ran']], $t['decisions'])], $trials)]);
        $this->assertGate($trials);
    }
}
