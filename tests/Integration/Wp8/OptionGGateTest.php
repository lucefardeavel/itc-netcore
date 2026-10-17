<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';
require_once dirname(__DIR__, 2) . '/Support/Wp8Radius.php';
require_once dirname(__DIR__, 2) . '/Support/RadiusWire.php';

use PHPUnit\Framework\TestCase;
use Reseller\Radius\RadiusPacket;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;
use Reseller\Tests\Support\RadiusWire;
use Reseller\Tests\Support\Wp8Radius;

/**
 * WP8 OD-W8-16 Option G — fail-closed PPPoE post-auth session guard GATE (owner third resolution round) against the real
 * RouterOS CHR 7.24.5. The 2026-10-07 gate ran the CANDIDATE copy (dev/wp8/freeradius/option-g, kept as the reference);
 * since WP8 A1 (2026-10-09) the same tests run the PERMANENT configuration from install/freeradius (bin + named exec
 * module + post-auth policy of the project virtual server, net-snmp in the FreeRADIUS image, persistent guard credential
 * file), Simultaneous-Use and session { sql } unchanged, delete_stale_sessions = no, and Hotspot users provisioned by
 * the normal RadiusPolicyBuilder path (Port-Limit in radreply). Gate switches: dev/wp8/option_g_gate.py (`on` restarts
 * FreeRADIUS, so each stock-checkrad variant is its own PHPUnit run; faults and the fake second NAS 10.88.0.30 are
 * switched by the tests themselves).
 *
 *   testDiscriminator*   any mode     RouterOS PPPoE vs Hotspot Access-Request attributes
 *   testShipped*         on shipped   stock checkrad exactly as shipped (cannot SNMP -> defers): the guard is the backstop
 *   testFunctional*      on functional lab-only functional stock checkrad: the stock layer rejects first
 */
final class OptionGGateTest extends TestCase
{
    use Wp8LabHelpers;

    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];

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

    /** every attribute of the LAST Access-Request FreeRADIUS received for $user (secrets omitted) */
    private static function requestAttrs(string $user): array
    {
        $lines = Wp8Lab::frLog('^\(\d+\) (Received Access-Request|  [A-Za-z][\w-]+ = )');
        $found = [];
        $cur = null;
        foreach ($lines as $l) {
            if (preg_match('/^\((\d+)\) Received Access-Request/', $l, $m)) {
                $cur = ['id' => $m[1], 'attrs' => []];
                continue;
            }
            if ($cur !== null && preg_match('/^\(' . $cur['id'] . '\)   ([A-Za-z][\w-]+) = (.*)$/', $l, $m)) {
                $cur['attrs'][$m[1]] = in_array($m[1], ['CHAP-Password', 'CHAP-Challenge', 'User-Password', 'Message-Authenticator'], true) ? '[omitted]' : trim($m[2], '"');
                if ($m[1] === 'NAS-IP-Address' || $m[1] === 'Message-Authenticator') {
                    if (($cur['attrs']['User-Name'] ?? null) === $user) {
                        $found = $cur['attrs'];
                    }
                }
            }
        }
        return $found;
    }

    public function testDiscriminatorPppoeVersusHotspot(): void
    {
        $ppp = $this->provisionPolicy(['username' => self::ident('gd'), 'password' => 'Gd-ppp-1', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
        $hs = $this->provisionPolicy(['username' => self::ident('gdhs'), 'password' => 'Gd-hs-1'], ['type' => 'Hotspot', 'shared_users' => 1], self::BW, new \DateTimeImmutable('+1 day'));
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $ppp, 'password' => 'Gd-ppp-1'])['ok']);
        Wp8Lab::agent('hs_dhcp', ['client' => 'c2']);
        $this->assertTrue(Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $hs, 'password' => 'Gd-hs-1'])['ok']);
        $r = ['pppoe' => self::requestAttrs($ppp), 'hotspot' => self::requestAttrs($hs)];
        Wp8Lab::evidence('OD16G.discriminator', $r);
        fwrite(STDERR, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->assertNotEmpty($r['pppoe']);
        $this->assertNotEmpty($r['hotspot']);
    }
    // ================================================================== helpers

    private array $rules = [];

    /** the lab gate mode a test needs */
    private function requireMode(string $stock): array
    {
        $st = Wp8Lab::agent('option_g', ['cmd' => 'state']);
        $fr = implode("\n", $st['freeradius']);
        $now = !str_contains($fr, 'option_g=1') ? 'off' : (str_contains($fr, 'marker=1') ? 'functional' : 'shipped');
        if ($now !== $stock) {
            $this->markTestSkipped("Option G gate mode {$stock} required, lab is {$now} (dev/wp8/option_g_gate.sh)");
        }
        $this->assertStringContainsString('delete_stale_sessions = no', $fr);
        $this->assertStringContainsString('post_auth_policy=1', $fr, 'the guard runs exactly once from the project site');
        // WP8 A1: the permanent configuration, not the gate copy
        foreach (['guard_installed=1', 'guard_module=1', 'lab_policy_refs=0', 'guard_is_install_copy=1', 'persistent_creds=1'] as $needle) {
            $this->assertStringContainsString($needle, $fr, 'permanent Option G: ' . $needle);
        }
        $this->assertStringContainsString('snmpget_exec=1', $fr, 'no fault left over');
        // the stock checkrad of the pinned image, byte-identical (sha256 recorded in the OD-W8-16 Option F gate)
        $this->assertStringContainsString('stock_checkrad_sha256=be0093605a2a032942631067fefe74ab1725f4de3b4460cc7dbfe48957cf5738', $fr);
        return $st;
    }

    private function pppoeUser(string $password): string
    {
        $u = $this->provisionPolicy(['username' => self::ident('og'), 'password' => $password, 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
        $this->assertSame([['Simultaneous-Use', ':=', '1']], $this->rows("SELECT attribute, op, value FROM radcheck WHERE username = ? AND attribute = 'Simultaneous-Use'", $u), 'Simultaneous-Use stays');
        $this->assertSame([], $this->rows("SELECT attribute FROM radreply WHERE username = ? AND attribute = 'Port-Limit'", $u), 'PPPoE: no Port-Limit');
        return $u;
    }

    private function hotspotUser(string $password, int $shared): string
    {
        $u = $this->provisionPolicy(['username' => self::ident('oghs'), 'password' => $password], ['type' => 'Hotspot', 'shared_users' => $shared], self::BW, new \DateTimeImmutable('+1 day'));
        // WP8 A1: provisioned by the normal RadiusPolicyBuilder path (the 2026-10-07 gate injected this row by hand)
        $this->assertSame([['Port-Limit', ':=', (string) max(1, $shared)]], $this->rows("SELECT attribute, op, value FROM radreply WHERE username = ? AND attribute = 'Port-Limit'", $u),
            'Hotspot Port-Limit written by the builder');
        $this->assertSame([], $this->rows("SELECT attribute FROM radcheck WHERE username = ? AND attribute = 'Port-Limit'", $u), 'never in the control list');
        return $u;
    }

    private function rows(string $sql, string ...$p): array
    {
        $st = static::labRadius()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_NUM);
    }

    private function fullRows(string $user): array
    {
        $st = static::labRadius()->prepare('SELECT * FROM radacct WHERE username = ? ORDER BY radacctid');
        $st->execute([$user]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function radacctView(string $user): array
    {
        return array_map(static fn($a) => [$a['radacctid'], $a['nasipaddress'], $a['acctsessionid'], $a['acctstarttime'], $a['acctupdatetime'], $a['acctstoptime'], $a['acctterminatecause']], $this->fullRows($user));
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
        $this->assertCount(1, Wp8Lab::pppActive($user));
        return ['ip' => $c1['ip'], 'row' => $row];
    }

    /** the post-auth guard trace and the stock session / checkrad lines of the LAST Access-Request for $user */
    private function decision(string $user): array
    {
        $lines = Wp8Lab::frLog('^\(\d+\) (Received Access-Request|  [A-Za-z][\w-]+ :?= |.*[Ss]ession|.*Tmp-String-[6789]|.*nuxr_pppoe_session_guard|.*Program |.*[Rr]eject|Sent Access-|  Reply-Message|.*Post-Auth-Type)|^\[wp8-checkrad\]');
        $req = null;
        $from = 0;
        foreach ($lines as $i => $l) {
            if (preg_match('/^\((\d+)\)   User-Name = "' . preg_quote(addcslashes($user, '"\\\\'), '/') . '"$/', $l, $m)) {
                for ($j = $i - 1; $j >= 0 && $j >= $i - 20; $j--) {
                    if (preg_match('/^\(' . $m[1] . '\) Received Access-Request/', $lines[$j])) {
                        [$req, $from] = [$m[1], $j];
                        break;
                    }
                }
            }
        }
        $own = $req === null ? [] : array_values(array_filter(array_slice($lines, $from), static fn($l) => str_starts_with($l, '(' . $req . ') ') && !str_contains($l, 'User-Name')));
        $verdict = null;
        $detail = null;
        foreach ($own as $l) {
            if (preg_match('/Tmp-String-7 = "(\w+)"/', $l, $m)) {
                $verdict = $m[1];
            }
            if (preg_match('/Tmp-String-6 = "([^"]*)"/', $l, $m)) {
                $detail = $m[1];
            }
        }
        $sent = array_values(array_filter($own, static fn($l) => str_contains($l, ') Sent Access-')));
        $replyAttrs = [];
        if ($sent) {
            $after = array_slice($own, (int) array_search(end($sent), $own, true) + 1);
            foreach ($after as $l) {
                if (!preg_match('/^\(\d+\)   ([A-Za-z][\w-]+) :?= /', $l, $m)) {
                    break;
                }
                $replyAttrs[] = $m[1];
            }
        }
        $own = array_values(array_filter($own, static fn($l) => !preg_match('/^\(\d+\)   [A-Za-z][\w-]+ = /', $l)));
        $own = array_map(static fn($l) => preg_replace("/'[^']{60,}'/", "'…'", $l), $own);
        $runs = array_values(array_filter($lines, static fn($l) => str_starts_with($l, '[wp8-checkrad]') && str_contains($l, 'user=' . $user . ' ')));
        return ['request' => $req, 'reply' => $sent ? preg_replace('/.*Sent (Access-\w+).*/', '$1', end($sent)) : null, 'reply_attrs' => $replyAttrs,
            // the value the decision used: the LAST assignment (WP9 A2-R: the open-session probe first carries its echo
            // ";u=...;k=..." and is normalised back to "n=...;list" before the decision), as Wp8LabHelpers reads it
            'open_rows' => (static function () use ($own) { $v = null; foreach ($own as $l) { if (preg_match('/Tmp-String-8 := "?(n=[^"]*?)"?$/', $l, $m)) { $v = $m[1]; } } return $v; })(),
            // the PPPoE guard by name: since WP8 H-2B a refused Hotspot login may run the (different) Hotspot slot verifier
            'guard_ran' => (bool) array_filter($own, static fn($l) => str_contains($l, 'nuxr_pppoe_session_guard') && (str_contains($l, 'Program returned code') || str_contains($l, 'Program executed') || str_contains($l, 'nuxr_pppoe_session_guard ('))),
            'hotspot_verifier_ran' => (bool) array_filter($own, static fn($l) => str_contains($l, 'nuxr_hotspot_slot_verifier') && (str_contains($l, 'Program returned code') || str_contains($l, 'Program executed'))),
            'guard_verdict' => $verdict, 'guard_detail' => $detail,
            'stock_checkrad_exits' => array_map(static fn($l) => (int) preg_replace('/.* exit=(\d+) .*/', '$1', $l), array_slice($runs, -4)),
            'trace' => array_slice($own, -40)];
    }

    /** request -> reply latency (ms) of the last Access exchange in a capture */
    private static function latency(array $packets): ?int
    {
        $req = null;
        $out = null;
        foreach ($packets as $p) {
            if ($p['code'] === 'Access-Request' && ($req === null || $p['id'] !== $req['id'] || $out !== null)) {
                $req = $p;
                $out = null;
            } elseif ($req !== null && $out === null && in_array($p['code'], ['Access-Accept', 'Access-Reject'], true) && $p['id'] === $req['id']) {
                $out = (int) round(($p['t'] - $req['t']) * 1000);
            }
        }
        return $out;
    }

    /** C2 (independent client c3) tries the same username; the NAS is sampled the whole time */
    private function contest(string $user, string $password, int $seconds = 30): array
    {
        $cap = 'od16g-' . substr($user, -6);
        Wp8Lab::agent('capture_start', ['name' => $cap]);
        Wp8Lab::agent('pppoe_start', ['client' => 'c3', 'user' => $user, 'password' => $password]);
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'ppp', 'user' => $user, 'seconds' => $seconds], $seconds + 60);
        $packets = array_values(array_filter(Wp8Lab::agent('capture_stop', ['name' => $cap])['packets'], static fn($p) => str_starts_with($p['code'], 'Access')));
        return ['watch' => ['max' => $watch['max'], 'n' => $watch['n']], 'packets' => array_map(static fn($p) => [$p['t'], $p['code'], $p['id']], array_slice($packets, -6)),
            'latency_ms' => self::latency($packets), 'decision' => $this->decision($user),
            'nas_after' => array_map(static fn($a) => [$a['caller-id'], $a['address']], Wp8Lab::pppActive($user)),
            'c1' => array_intersect_key(Wp8Lab::agent('pppoe_state', ['client' => 'c1']), ['running' => 1, 'ip' => 1]),
            'c2' => array_intersect_key(Wp8Lab::agent('pppoe_state', ['client' => 'c3']), ['running' => 1, 'ip' => 1]),
            'router_log' => array_slice(array_values(array_map(static fn($r) => ($r['time'] ?? '') . ' ' . $r['message'],
                array_filter(Wp8Lab::ros('/log/print'), static fn($r) => str_contains($r['message'] ?? '', $user) || str_contains($r['message'] ?? '', 'radius timeout')))), -6)];
    }

    private function assertRejectedByGuard(array $r, string $verdict, string $why): void
    {
        $this->assertSame('Access-Reject', $r['decision']['reply'], "{$why}: FreeRADIUS decision");
        $this->assertTrue($r['decision']['guard_ran'], "{$why}: the post-auth guard ran");
        $this->assertSame($verdict, $r['decision']['guard_verdict'], $why);
        $this->assertContains('Access-Reject', array_column($r['packets'], 1), "{$why}: an explicit Access-Reject on the wire");
        $this->assertNotContains('Access-Accept', array_column($r['packets'], 1), "{$why}: never an Access-Accept");
        $this->assertSame(1, $r['watch']['max'], "{$why}: never two live PPPoE sessions");
        $this->assertNull($r['c2']['ip']);
        $this->assertLessThan(3000, $r['latency_ms'], "{$why}: answered within the RouterOS RADIUS timeout");
        $this->assertSame(['Reply-Message'], $r['decision']['reply_attrs'], "{$why}: the Access-Reject carries only Reply-Message (no policy attributes)");
    }

    /** a synthetic accounting Start written by FreeRADIUS itself for $user as if from NAS $nasIp (lab fixture) */
    private function syntheticOpenRow(string $user, string $nasIp): string
    {
        $sid = 'og' . bin2hex(random_bytes(4));
        $c = new Wp8Radius('10.88.0.10', (string) getenv('NUXR_RADIUS_SECRET'), $nasIp);
        $this->assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $c->accounting([['User-Name', $user], ['Acct-Status-Type', 1], ['Acct-Session-Id', $sid], ['Event-Timestamp', time()],
            ['NAS-Port-Type', 15], ['Service-Type', 2], ['Framed-Protocol', 1]])?->code);
        return $sid;
    }

    /** a PPPoE-shaped Access-Request (PAP) from the co-located runner (SQL NAS client 10.88.0.10) */
    private function pppoeAccess(string $user, string $password): array
    {
        $t = microtime(true);
        // WP8 A2-R: with the RouterOS PPPoE attempt identity (Acct-Session-Id, Calling-Station-Id, NAS-Port-Id), as every
        // real RouterOS PPPoE Access-Request carries it; without it the site refuses the login before the guard
        $w = RadiusWire::lab();
        $attempt = RadiusWire::attempt('10.88.0.10');
        $r = $w->send($w->pppoeAccess($user, $password, $attempt, 'pap'));
        $this->lastAttempt = $r?->code === RadiusPacket::ACCESS_ACCEPT ? [$user, $attempt, RadiusWire::classHex($r)] : null;
        return ['code' => $r?->code === RadiusPacket::ACCESS_ACCEPT ? 'Access-Accept' : ($r?->code === RadiusPacket::ACCESS_REJECT ? 'Access-Reject' : 'none'),
            'reply_message' => $r?->get('Reply-Message'), 'ms' => (int) round((microtime(true) - $t) * 1000), 'decision' => $this->decision($user)];
    }

    /** WP8 A2-R: the last synthetic attempt that was ACCEPTED (user, attempt identity, Class hex) */
    private ?array $lastAttempt = null;

    /**
     * WP8 A2-R: a synthetic Access-Accept that is never followed by accounting leaves an uncertain admission claim
     * (PENDING) that refuses every other attempt of the subscriber (Strict Mode). A real NAS ends its session with an
     * Accounting-Stop carrying the Class; the synthetic NAS does the same here so the next case starts clean.
     */
    private function endSyntheticAttempt(): void
    {
        if ($this->lastAttempt !== null) {
            [$user, $attempt, $class] = $this->lastAttempt;
            RadiusWire::lab()->pppoeAccounting($user, $attempt, 'Stop', hex2bin((string) $class));
            $this->lastAttempt = null;
        }
    }

    private function nasb(array $names): void
    {
        $r = Wp8Lab::agent('option_g', ['cmd' => 'nasb', 'arg' => ['up', array_map('bin2hex', $names)]], 300);
        $this->assertSame('up', $r['nasb']);
    }

    private function fault(string $f): void
    {
        Wp8Lab::agent('option_g', ['cmd' => 'fault', 'arg' => $f], 120);
    }

    // ================================================================== Gate A: normal first login

    public function testShippedGateAFirstLoginNoVerification(): void
    {
        $this->requireMode('shipped');
        $user = $this->pppoeUser('Og-a-1');
        Wp8Lab::agent('capture_start', ['name' => 'od16g-a']);
        $c1 = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Og-a-1']);
        $packets = array_values(array_filter(Wp8Lab::agent('capture_stop', ['name' => 'od16g-a'])['packets'], static fn($p) => str_starts_with($p['code'], 'Access')));
        $r = ['c1' => ['ok' => $c1['ok'], 'ip' => $c1['ip'] ?? null], 'latency_ms' => self::latency($packets), 'decision' => $this->decision($user)];
        Wp8Lab::evidence('OD16G.gateA.first-login', $r);
        $this->assertTrue($c1['ok']);
        $this->assertSame('Access-Accept', $r['decision']['reply']);
        $this->assertSame('n=0;', $r['decision']['open_rows'], 'no open accounting row');
        $this->assertFalse($r['decision']['guard_ran'], 'no NAS query without open rows');
        $this->assertSame([], $r['decision']['stock_checkrad_exits'], 'the stock count was below the limit: no checkrad either');
    }

    // ================================================================== Gate B: healthy duplicate (forced backstop)

    public function testShippedGateBDuplicateRejectedByTheGuardBackstop(): void
    {
        $this->requireMode('shipped');
        $user = $this->pppoeUser('Og-b-1');
        $this->liveC1($user, 'Og-b-1');
        $r = $this->contest($user, 'Og-b-1');
        $r['radacct'] = $this->radacctView($user);
        Wp8Lab::evidence('OD16G.gateB.forced-backstop', $r);
        $this->assertContains(0, $r['decision']['stock_checkrad_exits'], 'stock layer (checkrad as shipped) let the duplicate through');
        $this->assertRejectedByGuard($r, 'LIVE', 'healthy duplicate');
        $this->assertStringContainsString('10.88.0.2=LIVE:1', (string) $r['decision']['guard_detail']);
        $this->assertTrue($r['c1']['running']);
    }

    // ================================================================== Gate C: lost Stop, session gone

    public function testShippedGateCLostStopGoneSessionAllowedHistoryUnchanged(): void
    {
        $this->requireMode('shipped');
        $user = $this->pppoeUser('Og-c-1');
        $this->liveC1($user, 'Og-c-1');
        $rule = $this->blockAccounting();
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $this->assertTrue((bool) Wp8Lab::until(fn() => Wp8Lab::pppActive($user) === [], 30, 1), 'RouterOS session gone');
        sleep(5);
        $this->unblock($rule);
        $before = $this->fullRows($user);
        $this->assertCount(1, $before);
        $this->assertNull($before[0]['acctstoptime'], 'Stop lost: row OPEN');
        Wp8Lab::agent('capture_start', ['name' => 'od16g-c']);
        $again = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Og-c-1']);
        $packets = array_values(array_filter(Wp8Lab::agent('capture_stop', ['name' => 'od16g-c'])['packets'], static fn($p) => str_starts_with($p['code'], 'Access')));
        $two = Wp8Lab::until(fn() => count($this->fullRows($user)) === 2, 20, 1);
        $after = $this->fullRows($user);
        $r = ['relogin' => ['ok' => $again['ok'], 'ip' => $again['ip'] ?? null], 'latency_ms' => self::latency($packets), 'decision' => $this->decision($user),
            'radacct' => $this->radacctView($user), 'old_row_unchanged' => $before[0] === ($after[0] ?? null)];
        Wp8Lab::evidence('OD16G.gateC.lost-stop-gone', $r);
        $this->assertTrue($again['ok'], 'login allowed: no reboot, no freshness wait, no delete, no fake Stop');
        $this->assertSame('Access-Accept', $r['decision']['reply']);
        $this->assertSame('SAFE', $r['decision']['guard_verdict'], 'the guard verified the NAS: user not live');
        $this->assertTrue($two, 'the stale historical row AND the new genuine row');
        $this->assertSame($before[0], $after[0], 'old row byte-for-byte unchanged');
        $this->assertNull($after[0]['acctstoptime']);
        $this->assertSame('', (string) $after[0]['acctterminatecause']);
    }

    // ================================================================== Gate D: accounting lost, session live

    public function testShippedGateDAccountingLostLiveSessionRejected(): void
    {
        $this->requireMode('shipped');
        $user = $this->pppoeUser('Og-d-1');
        $c1 = $this->liveC1($user, 'Og-d-1');
        $this->blockAccounting();
        sleep(195);
        $this->assertCount(1, Wp8Lab::pppActive($user), 'C1 genuinely live');
        $age = (int) $this->rows('SELECT TIMESTAMPDIFF(SECOND, COALESCE(acctupdatetime, acctstarttime), NOW()) FROM radacct WHERE radacctid = ?', (string) $c1['row']['radacctid'])[0][0];
        $r = ['acct_age_s' => $age] + $this->contest($user, 'Og-d-1');
        Wp8Lab::evidence('OD16G.gateD.accounting-lost-live', $r);
        $this->assertGreaterThan(190, $age);
        $this->assertRejectedByGuard($r, 'LIVE', 'accounting lost, session live');
        $this->assertTrue($r['c1']['running']);
    }

    // ================================================================== Gates E, F, G: verification failure FAILS CLOSED

    private function failureCase(string $case, string $password, callable $fault, callable $restore, string $detail): array
    {
        $user = $this->pppoeUser($password);
        $this->liveC1($user, $password);
        $f = $fault();
        try {
            $r = ['fault' => $f] + $this->contest($user, $password);
        } finally {
            $restore();
        }
        Wp8Lab::evidence('OD16G.' . $case, $r);
        $this->assertRejectedByGuard($r, 'ERROR', $case);
        $this->assertStringContainsString($detail, (string) $r['decision']['guard_detail']);
        return $r;
    }

    public function testShippedGateESnmpUnreachableRejects(): void
    {
        $this->requireMode('shipped');
        $this->failureCase('gateE.snmp-unreachable', 'Og-e-1', function () {
            $this->rules[] = $id = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=input', '=protocol=udp', '=dst-port=161', '=action=drop', '=comment=nuxr_wp8_drill');
            return 'RouterOS input drop udp/161';
        }, function () {
            foreach ($this->rules as $id) {
                Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
            }
            $this->rules = [];
        }, '10.88.0.2=ERROR:health');
    }

    public function testShippedGateFSnmpClientUnavailableRejects(): void
    {
        $this->requireMode('shipped');
        $this->failureCase('gateF.snmpget-unavailable', 'Og-f-1', fn() => $this->fault('get-missing') ?? 'snmpget not executable', fn() => $this->fault('restore'), '10.88.0.2=ERROR:health');
        $this->failureCase('gateF.snmpbulkwalk-unavailable', 'Og-f-2', fn() => $this->fault('walk-missing') ?? 'snmpbulkwalk not executable', fn() => $this->fault('restore'), '10.88.0.2=ERROR:walk');
    }

    public function testShippedGateGWrongCommunityRejects(): void
    {
        $this->requireMode('shipped');
        $this->failureCase('gateG.wrong-community', 'Og-g-1', fn() => $this->fault('bad-community') ?? 'wrong lab-only community', fn() => $this->fault('restore'), '10.88.0.2=ERROR:health');
    }

    // ================================================================== Gate H: unverifiable NAS input

    public function testShippedGateHMissingCredentialsRejects(): void
    {
        $this->requireMode('shipped');
        $this->failureCase('gateH.missing-credentials', 'Og-h-1', fn() => $this->fault('no-creds') ?? 'credential entry of 10.88.0.2 removed', fn() => $this->fault('restore'), '10.88.0.2=ERROR:credentials');
    }

    public function testShippedGateHUnknownNasAndMalformedInput(): void
    {
        $this->requireMode('shipped');
        // RADIUS level: an open row on a NAS FreeRADIUS does not know -> the stock layer already treats it as live
        $user = $this->pppoeUser('Og-h-2');
        $this->syntheticOpenRow($user, '10.88.0.99');
        $unknown = $this->pppoeAccess($user, 'Og-h-2');
        // verifier level: every unverifiable input is ERROR (exit 2)
        $u1 = bin2hex('u1');
        $cases = [
            'malformed-ip' => [$u1, '1', '10.88.0.256'], 'leading-zero' => [$u1, '1', '010.88.0.2'], 'ipv6' => [$u1, '1', '::1'],
            'empty-list' => [$u1, '1', ''], 'unknown-nas-no-credentials' => [$u1, '1', '10.88.0.99'], 'mixed-unknown' => [$u1, '1', '10.88.0.2,10.88.0.99'],
            'bad-limit' => [$u1, '0', '10.88.0.2'], 'non-numeric-limit' => [$u1, 'x', '10.88.0.2'], 'argc' => [$u1, '1'],
            'empty-username' => ['', '1', '10.88.0.2'], 'non-hex-username' => ['u1', '1', '10.88.0.2'], 'odd-hex-username' => ['abc', '1', '10.88.0.2'],
            'control-char-username' => [bin2hex("a\nb"), '1', '10.88.0.2'], 'list-injection' => [$u1, '1', '10.88.0.2;id'],
            'too-many-nas' => [$u1, '1', implode(',', array_map(static fn($i) => "10.88.1.{$i}", range(1, 17)))],
        ];
        $direct = [];
        foreach ($cases as $k => $argv) {
            $g = Wp8Lab::agent('guard_invoke', ['argv' => $argv]);
            $direct[$k] = [$g['rc'], trim(preg_replace('/\s+/', ' ', $g['out']))];
        }
        Wp8Lab::evidence('OD16G.gateH.unknown-and-malformed', ['radius_unknown_nas_row' => $unknown, 'verifier' => $direct]);
        $this->assertSame('Access-Reject', $unknown['code'], 'unknown NAS: rejected');
        foreach ($direct as $k => [$rc, $out]) {
            $this->assertSame(2, $rc, "{$k}: exit 2");
            $this->assertStringContainsString('Tmp-String-7 = "ERROR"', $out, $k);
        }
    }

    // ================================================================== Gate I: multiple NAS

    public function testShippedGateIMultipleNas(): void
    {
        $this->requireMode('shipped');
        $out = [];
        // I1: NAS B (fake 10.88.0.30) SAFE + NAS A (real CHR) LIVE -> LIVE / reject, end to end
        $u1 = $this->pppoeUser('Og-i-1');
        $this->nasb(['lo', 'ether1']);
        $this->liveC1($u1, 'Og-i-1');
        $this->syntheticOpenRow($u1, '10.88.0.30');
        $out['I1.B-safe-A-live'] = $this->contest($u1, 'Og-i-1', 20);
        $this->releaseClients();
        // I2: NAS B LIVE + NAS A SAFE (stale synthetic row, nobody live on the CHR) -> LIVE / reject
        $u2 = $this->pppoeUser('Og-i-2');
        $this->syntheticOpenRow($u2, '10.88.0.30');
        $this->syntheticOpenRow($u2, '10.88.0.2');
        $this->nasb(['lo', 'ether1', "<pppoe-{$u2}>"]);
        $out['I2.B-live-A-safe'] = $this->pppoeAccess($u2, 'Og-i-2');
        // I3: NAS B ERROR (empty interface table) + NAS A SAFE -> ERROR / reject
        $this->nasb([]);
        $out['I3.B-error-A-safe'] = $this->pppoeAccess($u2, 'Og-i-2');
        // I4: all SAFE -> SAFE / accept
        $this->nasb(['lo', 'ether1']);
        $out['I4.all-safe'] = $this->pppoeAccess($u2, 'Og-i-2');
        $this->endSyntheticAttempt();
        // I5: real PPPoE login through the CHR with rows on both NAS, all SAFE -> the session comes up
        $c = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $u2, 'password' => 'Og-i-2']);
        $out['I5.real-login-all-safe'] = ['ok' => $c['ok'], 'decision' => $this->decision($u2), 'radacct' => $this->radacctView($u2)];
        Wp8Lab::evidence('OD16G.gateI.multiple-nas', $out);
        $this->assertSame('LIVE', $out['I1.B-safe-A-live']['decision']['guard_verdict']);
        $this->assertSame(1, $out['I1.B-safe-A-live']['watch']['max']);
        $this->assertSame('Access-Reject', $out['I1.B-safe-A-live']['decision']['reply']);
        $this->assertStringContainsString('10.88.0.30=SAFE', (string) $out['I1.B-safe-A-live']['decision']['guard_detail']);
        $this->assertSame(['Access-Reject', 'LIVE'], [$out['I2.B-live-A-safe']['code'], $out['I2.B-live-A-safe']['decision']['guard_verdict']]);
        $this->assertSame(['Access-Reject', 'ERROR'], [$out['I3.B-error-A-safe']['code'], $out['I3.B-error-A-safe']['decision']['guard_verdict']]);
        $this->assertStringContainsString('10.88.0.2=SAFE', (string) $out['I3.B-error-A-safe']['decision']['guard_detail']);
        $this->assertSame(['Access-Accept', 'SAFE'], [$out['I4.all-safe']['code'], $out['I4.all-safe']['decision']['guard_verdict']]);
        $this->assertTrue($c['ok']);
        $this->assertSame('SAFE', $out['I5.real-login-all-safe']['decision']['guard_verdict']);
    }

    // ================================================================== hostile usernames (no shell, exact match)

    public function testShippedHostileUsernames(): void
    {
        $this->requireMode('shipped');
        // whitespace is refused by the stock filter_username before any module (recorded); every other payload must
        // reach the guard and be matched byte-exactly
        $names = ["nuxr_wp8_q'uote", 'nuxr_wp8_d"quote', 'nuxr_wp8_back`id`tick', 'nuxr_wp8_semi;id', 'nuxr_wp8_$(id)', 'nuxr_wp8_${IFS}x',
            'nuxr_wp8_back\\slash', 'nuxr_wp8_%{User-Password}', 'nuxr_wp8_star*', '-nuxr_wp8_dash', 'nuxr_wp8_amp&&id|x>y',
            'nuxr_wp8_sp ace', 'nuxr_wp8_semi;touch /tmp/nuxr_pwn_semi', 'nuxr_wp8_$(touch /tmp/nuxr_pwn_dollar)'];
        $pdo = static::labRadius();
        $out = [];
        foreach ($names as $u) {
            // stored under the key the stock rlm_sql lookup uses (safe_characters escaping, see OBS-W8-9)
            $key = self::frSqlKey($u);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$key]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', 'Og-hostile-1'), (?, 'Simultaneous-Use', ':=', '1')")->execute([$key, $key]);
            $this->syntheticOpenRow($u, '10.88.0.30');
            $this->nasb(['lo', 'ether1', "<pppoe-{$u}>"]);
            $live = $this->pppoeAccess($u, 'Og-hostile-1');
            $this->nasb(['lo', 'ether1', "<pppoe-{$u}x>", "<pppoe-{$u}-1a>"]);
            $safe = $this->pppoeAccess($u, 'Og-hostile-1');
            $rows = $this->rows('SELECT username, nasipaddress, acctstoptime FROM radacct WHERE username = ?', $key);
            $out[$u] = ['sql_key' => $key, 'radacct_rows_under_key' => count($rows), 'live_on_nas' => [$live['code'], $live['decision']['guard_verdict'], $live['reply_message']],
                'not_live_on_nas' => [$safe['code'], $safe['decision']['guard_verdict']], 'filtered_before_guard' => !$live['decision']['guard_ran']];
        }
        $canaries = Wp8Lab::agent('fr_canaries');
        Wp8Lab::evidence('OD16G.hostile-usernames', ['results' => $out, 'injection_canaries' => $canaries]);
        $this->assertSame([], $canaries['found'], 'no command ever executed from a username');
        foreach ($out as $u => $r) {
            if ($r['filtered_before_guard']) {
                $this->assertSame('Access-Reject', $r['live_on_nas'][0], "{$u}: refused before the guard (filter_username)");
                continue;
            }
            $this->assertSame(['Access-Reject', 'LIVE'], array_slice($r['live_on_nas'], 0, 2), "{$u}: exact live match");
            $this->assertSame(['Access-Accept', 'SAFE'], $r['not_live_on_nas'], "{$u}: near-miss names are not the user");
        }
        $this->assertSame(11, count(array_filter($out, static fn($r) => !$r['filtered_before_guard'])), 'every payload without whitespace reached the guard');
    }

    /** the stock FreeRADIUS 3.2.8 rlm_sql key for a username: '"' and '\\' backslash-escaped by the xlat, then every byte
     *  outside safe_characters as =XX (mods-config/sql/main/mysql/queries.conf) */
    private static function frSqlKey(string $u): string
    {
        $v = str_replace(['\\', '"'], ['\\\\', '\\"'], $u);
        $safe = '@abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-_: /';
        $out = '';
        foreach (str_split($v) as $ch) {
            $out .= str_contains($safe, $ch) ? $ch : sprintf('=%02X', ord($ch));
        }
        return $out;
    }

    // ================================================================== Hotspot: Simultaneous-Use + Port-Limit, guard skipped

    private function hotspotContest(string $user, string $password, array $clients): array
    {
        $out = [];
        foreach ($clients as $c) {
            Wp8Lab::agent('hs_dhcp', ['client' => $c]);
        }
        foreach ($clients as $c) {
            $l = Wp8Lab::agent('hs_login', ['client' => $c, 'user' => $user, 'password' => $password]);
            $d = $this->decision($user);
            $out[$c] = ['ok' => $l['ok'], 'freeradius' => $d['reply'], 'guard_ran' => $d['guard_ran'], 'active_now' => count(Wp8Lab::hotspotActive($user))];
            sleep(2);
        }
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'hotspot', 'user' => $user, 'seconds' => 15], 60);
        return ['logins' => $out, 'watch' => ['max' => $watch['max']],
            'router_log' => array_slice(array_values(array_map(static fn($r) => ($r['time'] ?? '') . ' ' . $r['message'], array_filter(Wp8Lab::ros('/log/print'), static fn($r) => str_contains($r['message'] ?? '', $user)))), -4)];
    }

    public function testShippedHotspotShared1(): void
    {
        $this->requireMode('shipped');
        $user = $this->hotspotUser('Og-hs-1', 1);
        $r = $this->hotspotContest($user, 'Og-hs-1', ['c2', 'c4']);
        Wp8Lab::evidence('OD16G.hotspot.shared-1', $r);
        $this->assertTrue($r['logins']['c2']['ok']);
        $this->assertFalse($r['logins']['c4']['ok'], 'second device refused');
        $this->assertLessThanOrEqual(1, $r['watch']['max']);
        $this->assertFalse($r['logins']['c4']['guard_ran'], 'the PPPoE guard never runs for Hotspot');
    }

    public function testShippedHotspotShared2(): void
    {
        $this->requireMode('shipped');
        $user = $this->hotspotUser('Og-hs-2', 2);
        $r = $this->hotspotContest($user, 'Og-hs-2', ['c2', 'c4', 'c5']);
        Wp8Lab::evidence('OD16G.hotspot.shared-2', $r);
        $this->assertTrue($r['logins']['c2']['ok'] && $r['logins']['c4']['ok']);
        $this->assertFalse($r['logins']['c5']['ok'], 'third refused');
        $this->assertLessThanOrEqual(2, $r['watch']['max']);
        $this->assertFalse($r['logins']['c5']['guard_ran']);
    }

    public function testShippedHotspotShared1AccountingLost(): void
    {
        $this->requireMode('shipped');
        $user = $this->hotspotUser('Og-hs-3', 1);
        Wp8Lab::agent('hs_dhcp', ['client' => 'c2']);
        Wp8Lab::agent('hs_dhcp', ['client' => 'c4']);
        $this->assertTrue(Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $user, 'password' => 'Og-hs-3'])['ok']);
        Wp8Lab::until(fn() => $this->acct($user) ?: null, 20, 1);
        $this->blockAccounting();
        sleep(190);
        $this->assertCount(1, Wp8Lab::hotspotActive($user));
        $l = Wp8Lab::agent('hs_login', ['client' => 'c4', 'user' => $user, 'password' => 'Og-hs-3']);
        $watch = Wp8Lab::agent('watch_active', ['kind' => 'hotspot', 'user' => $user, 'seconds' => 15], 60);
        $d = $this->decision($user);
        $r = ['second' => ['ok' => $l['ok']], 'freeradius' => $d['reply'], 'guard_ran' => $d['guard_ran'], 'watch' => ['max' => $watch['max']], 'radacct' => $this->radacctView($user)];
        Wp8Lab::evidence('OD16G.hotspot.shared-1-accounting-lost', $r);
        $this->assertFalse($l['ok'], 'second device refused (WP8 H-2B: by the Hotspot admission slots; RouterOS Port-Limit stays the per-NAS layer)');
        $this->assertLessThanOrEqual(1, $watch['max']);
    }

    // ================================================================== functional stock checkrad (lab-only variant)

    public function testFunctionalGateBStockLayerRejectsFirst(): void
    {
        $this->requireMode('functional');
        $user = $this->pppoeUser('Og-fb-1');
        $this->liveC1($user, 'Og-fb-1');
        $r = $this->contest($user, 'Og-fb-1');
        Wp8Lab::evidence('OD16G.functional.gateB.stock-rejects', $r);
        $this->assertSame('Access-Reject', $r['decision']['reply']);
        $this->assertContains(1, $r['decision']['stock_checkrad_exits'], 'stock checkrad found the session live');
        $this->assertFalse($r['decision']['guard_ran'], 'rejected before post-auth: the guard is not needed');
        $this->assertSame(1, $r['watch']['max']);
    }

    public function testFunctionalSnmpUnreachableStockFailsOpenGuardRejects(): void
    {
        $this->requireMode('functional');
        $user = $this->pppoeUser('Og-fe-1');
        $this->liveC1($user, 'Og-fe-1');
        $this->rules[] = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=input', '=protocol=udp', '=dst-port=161', '=action=drop', '=comment=nuxr_wp8_drill');
        $r = $this->contest($user, 'Og-fe-1', 35);
        Wp8Lab::evidence('OD16G.functional.snmp-unreachable', $r);
        $this->assertContains(0, $r['decision']['stock_checkrad_exits'], 'observed: stock checkrad fails open after ~10 s');
        $this->assertSame('Access-Reject', $r['decision']['reply'], 'the guard still turns it into Access-Reject');
        $this->assertSame('ERROR', $r['decision']['guard_verdict']);
        $this->assertNotContains('Access-Accept', array_column($r['packets'], 1));
        $this->assertSame(1, $r['watch']['max']);
    }
}
