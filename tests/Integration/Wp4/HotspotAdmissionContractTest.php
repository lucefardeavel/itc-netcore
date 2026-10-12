<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\RadiusPacket;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RadiusClient;
use Reseller\Tests\Support\RadiusWire;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP8 H-2B (owner implementation authorization 2026-10-11) against the REAL FreeRADIUS 3.2.8 of the harness, BOTH
 * database engines, with the project site and the accepted RadiusPolicyBuilder Hotspot policy: the Hotspot admission
 * slots (install/freeradius/nuxr_hs_admission.sql) - claim v2, "nuxh1" Class correlation, token-bound accounting,
 * STRICT MODE (a PENDING slot is never released by age or by another request), the ACTIVE-only verifier failing closed
 * (TEST-NET NAS: 192.0.2.10 has a credential entry but never answers, 192.0.2.11 has none), fail-closed SQL, and the
 * Hotspot-only bypass of the stock Simultaneous-Use check. Requests are RouterOS 7.24.5-shaped Hotspot Access-Requests
 * built byte by byte (RadiusWire). The real-NAS behaviour (SNMP proof of a gone session, cross-NAS logins, stalls) is
 * proven by the WP8 lab gates (dev/wp8/h2b_gate.py).
 */
final class HotspotAdmissionContractTest extends TestCase
{
    private const DB = 'nuxr_radius';
    private const NAS = '192.0.2.31';
    private const UNREACHABLE = '192.0.2.10';
    private const NO_CREDENTIALS = '192.0.2.11';
    private const BW = ['rate_up' => '2', 'rate_up_unit' => 'Mbps', 'rate_down' => '4', 'rate_down_unit' => 'Mbps', 'burst' => ''];
    private static \PDO $pdo;
    private static RadiusWire $w;

    public static function setUpBeforeClass(): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        $admin = FixtureBuilder::connect($host, TestDatabase::rootPassword());
        $admin->exec('CREATE DATABASE IF NOT EXISTS `' . self::DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$pdo = FixtureBuilder::connect($host, TestDatabase::rootPassword(), self::DB);
        $fx = new FixtureBuilder(dirname(__DIR__, 3));
        $fx->loadSqlFile(self::$pdo, dirname(__DIR__, 3) . '/install/radius.sql');
        $fx->loadSqlFile(self::$pdo, dirname(__DIR__, 3) . '/install/freeradius/nuxr_admission.sql');
        $fx->loadSqlFile(self::$pdo, dirname(__DIR__, 3) . '/install/freeradius/nuxr_hs_admission.sql');
        RadiusClient::waitReady();
        self::$w = RadiusWire::harness();
    }

    private function provision(string $tag, int $shared, string $type = 'Hotspot'): array
    {
        $username = 'nuxr_hs_' . $tag . '_' . bin2hex(random_bytes(3));
        $password = 'H2b-' . bin2hex(random_bytes(4));
        $plan = ['id' => random_int(1000, 999999), 'type' => $type, 'typebp' => 'Unlimited', 'shared_users' => $shared, 'pool' => 'nuxr-pool'];
        $policy = RadiusPolicyBuilder::build(['username' => $username, 'password' => $password], $plan, self::BW, new \DateTimeImmutable('+2 hours'));
        $r = (new RadiusProvisioner(new RadiusRepository(self::$pdo)))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return [$policy->username, $password];
    }

    /** @return array{0:?RadiusPacket,1:array} */
    private static function login(string $u, string $pw, ?array $a = null, string $nas = self::NAS): array
    {
        $a ??= RadiusWire::attempt($nas);
        return [self::$w->send(self::$w->hotspotAccess($u, $pw, $a, 'chap')), $a];
    }

    private static function acct(string $u, array $a, string $status, ?RadiusPacket $accept, ?string $nas = null): void
    {
        $cls = RadiusWire::classHex($accept);
        self::$w->hotspotAccounting($u, $a, $status, $cls === null ? null : (string) hex2bin($cls), $nas);
    }

    /** @return list<array{slot:int,state:string,nas:string,token:string,end_reason:?string}> */
    private static function slots(string $u): array
    {
        $st = self::$pdo->prepare('SELECT slot, state, nas, token, end_reason FROM nuxr_hs_admission WHERE username = ? ORDER BY slot');
        $st->execute([$u]);
        return array_map(static fn($r) => ['slot' => (int) $r['slot'], 'state' => $r['state'], 'nas' => $r['nas'], 'token' => $r['token'], 'end_reason' => $r['end_reason']],
            $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    private static function states(string $u): array
    {
        return array_column(self::slots($u), 'state');
    }

    private function assertAccept(?RadiusPacket $r, string $label): string
    {
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code, $label);
        $t = RadiusWire::hotspotToken($r);
        $this->assertNotNull($t, "{$label}: Class = nuxh1 + 128-bit token");
        $this->assertNull(RadiusWire::token($r), "{$label}: never the PPPoE Class");
        return (string) $t;
    }

    private function assertRefused(?RadiusPacket $r, string $label): void
    {
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $r?->code, $label);
        $this->assertSame('Session verification failed', $r->get('Reply-Message'), "{$label}: the admission refusal");
        $this->assertNull($r->get('Port-Limit'), "{$label}: policy attributes stripped");
        $this->assertNull(RadiusWire::classHex($r), "{$label}: no Class");
    }

    public function testLimitOneClaimAccountingAndRelease(): void
    {
        [$u, $pw] = $this->provision('one', 1);
        [$r1, $a1] = self::login($u, $pw);
        $t1 = $this->assertAccept($r1, 'first login');
        $this->assertSame(1, (int) $r1->get('Port-Limit'), 'Port-Limit kept as an additional local limit');
        $this->assertSame(['PENDING'], self::states($u));
        $this->assertSame($t1, self::slots($u)[0]['token']);
        [$r2] = self::login($u, $pw, null, '192.0.2.32');
        $this->assertRefused($r2, 'a second login, another NAS, while PENDING');
        self::acct($u, $a1, 'Start', $r1);
        $this->assertSame(['ACTIVE'], self::states($u));
        self::acct($u, $a1, 'Interim-Update', $r1);
        $this->assertSame(['ACTIVE'], self::states($u));
        [$r3] = self::login($u, $pw, null, '192.0.2.32');
        $this->assertRefused($r3, 'while ACTIVE (its NAS cannot be verified: no credential entry -> nothing released)');
        self::acct($u, $a1, 'Stop', $r1);
        $this->assertSame([['ENDED', 'acct_stop']], array_map(static fn($s) => [$s['state'], $s['end_reason']], self::slots($u)));
        [$r4] = self::login($u, $pw, null, '192.0.2.32');
        $t4 = $this->assertAccept($r4, 'after the Stop, from another NAS (roaming)');
        $this->assertNotSame($t1, $t4, 'a new token per claim');
    }

    public function testConcurrentLoginsNeverExceedTheLimit(): void
    {
        foreach ([1, 2, 3] as $limit) {
            [$u, $pw] = $this->provision('conc' . $limit, $limit);
            $raws = [];
            for ($i = 0; $i < 12; $i++) {
                $raws[] = self::$w->hotspotAccess($u, $pw, RadiusWire::attempt('192.0.2.' . (40 + $i % 3)), 'chap');
            }
            $replies = self::$w->sendMany($raws, 1812, 12.0);
            $acc = array_filter($replies, static fn($r) => $r?->code === RadiusPacket::ACCESS_ACCEPT);
            $this->assertCount($limit, $acc, "limit {$limit}: exactly the limit admitted out of 12 simultaneous logins");
            $this->assertSame(array_fill(0, $limit, 'PENDING'), self::states($u), "limit {$limit}: one PENDING slot per Accept");
            $tokens = array_map(static fn($r) => RadiusWire::hotspotToken($r), $acc);
            $this->assertCount($limit, array_unique($tokens), 'distinct tokens');
        }
    }

    public function testAnExactRetransmissionReusesItsClaimAndANewAttemptDoesNot(): void
    {
        [$u, $pw] = $this->provision('retx', 1);
        $raw = self::$w->hotspotAccess($u, $pw, RadiusWire::attempt(self::NAS), 'chap');
        $r1 = self::$w->send($raw);
        $t1 = $this->assertAccept($r1, 'first');
        sleep(6);                                              // > cleanup_delay (5 s): FreeRADIUS processes the copy anew
        $r2 = self::$w->send($raw);
        $this->assertSame($t1, $this->assertAccept($r2, 'the same bytes again'), 'same claim, same token');
        $this->assertRefused(self::login($u, $pw)[0], 'a new attempt (new authenticator) is refused');
        $st = self::$pdo->prepare('SELECT state, attach_count FROM nuxr_hs_admission WHERE username = ?');
        $st->execute([$u]);
        $this->assertSame([['PENDING', 1]], array_map(static fn($r) => [$r[0], (int) $r[1]], $st->fetchAll(\PDO::FETCH_NUM)));
    }

    public function testStrictModeAnOldPendingSlotIsNeverReleased(): void
    {
        [$u, $pw] = $this->provision('strict', 1);
        [$r1] = self::login($u, $pw, null, self::UNREACHABLE);
        $this->assertAccept($r1, 'claim (its Access-Accept may be lost: no accounting follows)');
        // age the claim by one day: time is never a release condition
        self::$pdo->prepare('UPDATE nuxr_hs_admission SET created_at = created_at - INTERVAL 1 DAY, updated_at = updated_at - INTERVAL 1 DAY WHERE username = ?')->execute([$u]);
        foreach (['192.0.2.33', self::UNREACHABLE] as $nas) {
            $this->assertRefused(self::login($u, $pw, null, $nas)[0], "a day-old PENDING slot still refuses ({$nas})");
        }
        $this->assertSame(['PENDING'], self::states($u));
        $st = self::$pdo->prepare('SELECT COUNT(*) FROM nuxr_hs_admission_audit WHERE username = ?');
        $st->execute([$u]);
        $this->assertSame(0, (int) $st->fetchColumn(), 'the verifier never ran for a PENDING slot');
    }

    public function testAnActiveSlotIsKeptWhenItsNasCannotBeVerified(): void
    {
        foreach ([self::UNREACHABLE => 'SNMP health probe times out', self::NO_CREDENTIALS => 'no credential entry'] as $nas => $why) {
            [$u, $pw] = $this->provision('ver', 1);
            [$r1, $a1] = self::login($u, $pw, null, $nas);
            $this->assertAccept($r1, 'claim');
            self::acct($u, $a1, 'Start', $r1);
            $this->assertSame(['ACTIVE'], self::states($u));
            $t = microtime(true);
            [$r2] = self::login($u, $pw);
            $ms = (microtime(true) - $t) * 1000;
            $this->assertRefused($r2, "{$why}: verifier ERROR -> refused");
            $this->assertSame(['ACTIVE'], self::states($u), "{$why}: nothing released");
            $this->assertLessThan(4500, $ms, "{$why}: bounded (verifier deadline 1.5 s + reject_delay)");
        }
    }

    public function testAccountingIsBoundToItsTokenNasSessionAndMac(): void
    {
        [$u, $pw] = $this->provision('acct', 1);
        $a = RadiusWire::attempt(self::NAS, '8a000001', '02:AA:00:00:00:01');
        [$r1] = self::login($u, $pw, $a);
        $this->assertAccept($r1, 'claim 1');
        self::acct($u, $a, 'Stop', $r1);                       // Stop before Start
        $this->assertSame(['ENDED'], self::states($u));
        self::acct($u, $a, 'Start', $r1);                      // the late Start does not revive it
        self::acct($u, $a, 'Interim-Update', $r1);
        $this->assertSame(['ENDED'], self::states($u), 'ENDED is never revived');
        [$r2] = self::login($u, $pw, $a);                      // SAME Acct-Session-Id and MAC (identity re-use), new claim
        $t2 = $this->assertAccept($r2, 'claim 2, same session id and MAC');
        self::acct($u, $a, 'Start', $r2);
        self::acct($u, $a, 'Start', $r2);                      // duplicate Start
        $this->assertSame(['ACTIVE'], self::states($u));
        self::acct($u, $a, 'Stop', $r1);                       // a stale Stop of claim 1
        self::acct($u, $a, 'Stop', $r2, '192.0.2.99');         // the right Class from another NAS
        self::acct($u, $a, 'Stop', null);                      // a Stop without Class
        $this->assertSame(['ACTIVE'], self::states($u), 'only claim 2 itself can end claim 2');
        $this->assertSame($t2, self::slots($u)[0]['token']);
        self::acct($u, $a, 'Stop', $r2);
        self::acct($u, $a, 'Stop', $r2);                       // duplicate Stop
        $this->assertSame([['ENDED', 'acct_stop']], array_map(static fn($s) => [$s['state'], $s['end_reason']], self::slots($u)));
        $st = self::$pdo->prepare("SELECT outcome FROM nuxr_hs_admission_audit WHERE username = ? AND action = 'orphan_accounting' ORDER BY id");
        $st->execute([$u]);
        $this->assertSame(['Start', 'Interim-Update'], $st->fetchAll(\PDO::FETCH_COLUMN), 'a Start / Interim-Update of no live slot is audited (alarm); Stops are not');
    }

    public function testLimitDecreaseAndIncrease(): void
    {
        [$u, $pw] = $this->provision('lim', 2);
        [$r1, $a1] = self::login($u, $pw);
        [$r2, $a2] = self::login($u, $pw, null, '192.0.2.34');
        $this->assertAccept($r1, 'slot 1');
        $this->assertAccept($r2, 'slot 2');
        self::acct($u, $a1, 'Start', $r1);
        self::acct($u, $a2, 'Start', $r2, '192.0.2.34');
        self::$pdo->prepare("UPDATE radcheck SET value = '1' WHERE username = ? AND attribute = 'Simultaneous-Use'")->execute([$u]);
        $this->assertRefused(self::login($u, $pw)[0], 'decrease 2 -> 1 with two live: refused');
        self::acct($u, $a2, 'Stop', $r2, '192.0.2.34');
        $this->assertSame(['ACTIVE', 'ENDED'], self::states($u), 'the slot above the new limit still ends by its own Stop');
        $this->assertRefused(self::login($u, $pw)[0], 'one live = the new limit: refused');
        self::acct($u, $a1, 'Stop', $r1);
        $this->assertAccept(self::login($u, $pw)[0], 'none live: admitted');
        self::$pdo->prepare("UPDATE radcheck SET value = '3' WHERE username = ? AND attribute = 'Simultaneous-Use'")->execute([$u]);
        $this->assertAccept(self::login($u, $pw)[0], 'increase to 3: admitted');
        $this->assertAccept(self::login($u, $pw)[0], 'third slot created on demand');
        $this->assertRefused(self::login($u, $pw)[0], 'and not a fourth');
        $this->assertSame([1, 2, 3], array_column(self::slots($u), 'slot'));
    }

    public function testConcurrentLoginsDuringALimitDecreaseNeverExceedTheNewLimit(): void
    {
        // review M1: limit 3 -> 2 while slot 3 is live and slots 1-2 are free. Without the "no live slot above the limit"
        // pre-check, two concurrent logins could both pass the pre-check and claim slots 1 and 2 (3 live under limit 2).
        foreach (range(1, 3) as $round) {
            [$u, $pw] = $this->provision('decr', 3);
            $live = [];
            foreach (range(1, 3) as $i) {
                [$r, $a] = self::login($u, $pw);
                $this->assertAccept($r, "slot {$i}");
                self::acct($u, $a, 'Start', $r);
                $live[] = [$r, $a];
            }
            self::acct($u, $live[0][1], 'Stop', $live[0][0]);
            self::acct($u, $live[1][1], 'Stop', $live[1][0]);
            $this->assertSame(['ENDED', 'ENDED', 'ACTIVE'], self::states($u));
            self::$pdo->prepare("UPDATE radcheck SET value = '2' WHERE username = ? AND attribute = 'Simultaneous-Use'")->execute([$u]);
            $raws = [];
            for ($i = 0; $i < 10; $i++) {
                $raws[] = self::$w->hotspotAccess($u, $pw, RadiusWire::attempt('192.0.2.' . (50 + $i % 2)), 'chap');
            }
            $replies = self::$w->sendMany($raws, 1812, 12.0);
            $acc = count(array_filter($replies, static fn($r) => $r?->code === RadiusPacket::ACCESS_ACCEPT));
            $inUse = count(array_filter(self::states($u), static fn($s) => $s !== 'ENDED'));
            $this->assertLessThanOrEqual(2, $inUse, "round {$round}: never more live slots than the new limit");
            $this->assertSame(0, $acc, "round {$round}: refused while a slot above the new limit is live (its NAS cannot be verified here)");
            self::acct($u, $live[2][1], 'Stop', $live[2][0]);
            $this->assertAccept(self::login($u, $pw)[0], "round {$round}: admitted once the slot above the limit ended");
        }
    }

    public function testFailClosedIdentityLimitAndSchema(): void
    {
        [$u, $pw] = $this->provision('fc', 1);
        foreach (['Acct-Session-Id', 'Calling-Station-Id'] as $attr) {
            $r = self::$w->send(self::$w->hotspotAccess($u, $pw, RadiusWire::attempt(self::NAS), 'chap', [$attr]));
            $this->assertRefused($r, "no {$attr}");
        }
        $r = self::$w->send(self::$w->hotspotAccess($u, $pw, RadiusWire::attempt(self::NAS, "8a'x"), 'chap'));
        $this->assertRefused($r, 'malformed Acct-Session-Id');
        $r = self::$w->send(self::$w->hotspotAccess($u, $pw, RadiusWire::attempt(self::NAS, null, '02AA00000001'), 'chap'));
        $this->assertRefused($r, 'a Calling-Station-Id that is not a MAC the verifier can compare');
        $this->assertSame([], self::slots($u), 'nothing written before the identity is valid');
        self::$pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Simultaneous-Use'")->execute([$u]);
        $this->assertRefused(self::login($u, $pw)[0], 'no Simultaneous-Use: no limit to enforce');
        self::$pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Simultaneous-Use', ':=', '1')")->execute([$u]);
        self::$pdo->exec('RENAME TABLE nuxr_hs_admission TO nuxr_hs_admission_off');
        try {
            $this->assertRefused(self::login($u, $pw)[0], 'slot table missing');
        } finally {
            self::$pdo->exec('RENAME TABLE nuxr_hs_admission_off TO nuxr_hs_admission');
        }
        $this->assertAccept(self::login($u, $pw)[0], 'table back');
    }

    public function testTheStockSimultaneousUseCheckStillRunsForEveryNonHotspotRequest(): void
    {
        // an open radacct row without Class (a stale session) on a NAS of type "other": the stock check treats it as live
        [$u, $pw] = $this->provision('stock', 1);
        RadiusClient::accounting([['User-Name', $u], ['Acct-Status-Type', 1], ['Acct-Session-Id', '8b' . bin2hex(random_bytes(3))]], '127.0.0.1');
        $vpn = RadiusClient::access($u, $pw, false, [['Service-Type', 2], ['Framed-Protocol', 1], ['NAS-Port-Type', 5]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $vpn?->code, 'VPN-shaped (Virtual): the stock Simultaneous-Use check, unchanged');
        $this->assertNotSame('Session verification failed', $vpn->get('Reply-Message'));
        $this->assertNull(RadiusWire::classHex($vpn));
        $this->assertSame([], self::slots($u), 'no slot for a non-Hotspot request');
        $this->assertAccept(self::login($u, $pw)[0], 'Hotspot: the slots decide (stock radacct check not run)');
    }

    public function testMigrationIsAdditiveIdempotentAndCheckable(): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        $db = 'nuxr_h2b_chk_' . bin2hex(random_bytes(3));
        $admin = FixtureBuilder::connect($host, TestDatabase::rootPassword());
        $admin->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        try {
            $p = FixtureBuilder::connect($host, TestDatabase::rootPassword(), $db);
            $fx = new FixtureBuilder(dirname(__DIR__, 3));
            $check = static fn(): string => (string) $p->query((string) file_get_contents(dirname(__DIR__, 3) . '/install/freeradius/nuxr_hs_admission_check.sql'))->fetchColumn();
            $fx->loadSqlFile($p, dirname(__DIR__, 3) . '/install/radius.sql');
            $this->assertStringStartsWith('nuxr_hs_admission=NOT_READY:table,audit_table,columns,primary_key,token_unique,check_constraints', $check(), 'before the upgrade');
            $fx->loadSqlFile($p, dirname(__DIR__, 3) . '/install/freeradius/nuxr_hs_admission.sql');
            $this->assertSame('nuxr_hs_admission=READY', $check());
            $p->exec("INSERT INTO nuxr_hs_admission (username, slot, state, token, nas, acct_session_id, calling_station_id, request_authenticator, session_user, created_at, updated_at) VALUES ('keep', 1, 'ACTIVE', REPEAT('a', 32), '1.2.3.4', '1', 'm', REPEAT('b', 32), 'keep', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
            $fx->loadSqlFile($p, dirname(__DIR__, 3) . '/install/freeradius/nuxr_hs_admission.sql');
            $this->assertSame('nuxr_hs_admission=READY', $check(), 'running the upgrade again is harmless');
            $this->assertSame(1, (int) $p->query("SELECT COUNT(*) FROM nuxr_hs_admission WHERE username = 'keep' AND state = 'ACTIVE'")->fetchColumn(), 'and keeps the data');
            $report = (string) file_get_contents(dirname(__DIR__, 3) . '/install/freeradius/nuxr_hs_admission_report.sql');
            $p->exec("INSERT INTO nuxr_hs_admission (username, slot, state, token, nas, acct_session_id, calling_station_id, request_authenticator, session_user, created_at, updated_at) VALUES ('stuck', 1, 'PENDING', REPEAT('c', 32), '1.2.3.4', '2', 'm', REPEAT('d', 32), 'stuck', UTC_TIMESTAMP(6) - INTERVAL 2 MINUTE, UTC_TIMESTAMP(6))");
            $before = $p->query('SELECT state, token FROM nuxr_hs_admission ORDER BY username')->fetchAll(\PDO::FETCH_NUM);
            $alerts = [];
            foreach (array_filter(array_map('trim', explode(";\n", (string) preg_replace('/^--.*$/m', '', $report)))) as $q) {
                foreach ($p->query($q)->fetchAll(\PDO::FETCH_NUM) as $row) {
                    $alerts[] = $row[0] . ' ' . $row[1];
                }
            }
            $this->assertContains('ALERT pending_over_1_minute stuck', $alerts, 'the read-only report alerts on a PENDING slot older than 1 minute');
            $this->assertSame($before, $p->query('SELECT state, token FROM nuxr_hs_admission ORDER BY username')->fetchAll(\PDO::FETCH_NUM), 'and changes nothing');
            // an empty token or a claimed slot without its identity is refused by the database (both engines)
            foreach (["(username, slot, state, token, nas, acct_session_id, calling_station_id, request_authenticator, session_user, created_at, updated_at) VALUES ('e', 1, 'PENDING', '', '1.2.3.4', '1', 'm', REPEAT('b', 32), 'e', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                      "(username, slot, state, token, nas, acct_session_id, calling_station_id, request_authenticator, session_user, created_at, updated_at) VALUES ('e', 1, 'PENDING', REPEAT('e', 32), '', '1', 'm', REPEAT('b', 32), 'e', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                      "(username, slot, state, token, nas, acct_session_id, calling_station_id, request_authenticator, session_user, created_at, updated_at) VALUES ('e', 1, 'PENDING', REPEAT('e', 32), '1.2.3.4', '', 'm', REPEAT('b', 32), 'e', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"] as $i => $values) {
                try {
                    $p->exec('INSERT INTO nuxr_hs_admission ' . $values);
                    $this->fail("constraint case {$i} accepted");
                } catch (\PDOException $e) {
                    $this->assertStringContainsStringIgnoringCase('ck_nuxr_hs_admission', $e->getMessage(), "constraint case {$i}");
                }
            }
            $this->assertSame(0, (int) $p->query("SELECT COUNT(*) FROM nuxr_hs_admission WHERE username = 'e'")->fetchColumn());
            $p->exec("UPDATE nuxr_hs_admission SET state = 'ENDED' WHERE username = 'keep'");
            try {
                $p->exec("UPDATE nuxr_hs_admission SET state = 'PENDING', token = '' WHERE username = 'keep'");
                $this->fail('an empty token on claim accepted');
            } catch (\PDOException $e) {
                $this->assertStringContainsStringIgnoringCase('ck_nuxr_hs_admission', $e->getMessage());
            }
            $p->exec('ALTER TABLE nuxr_hs_admission DROP INDEX uq_nuxr_hs_admission_token');
            $this->assertSame('nuxr_hs_admission=NOT_READY:token_unique', $check());
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `{$db}`");
        }
    }

    public function testPppoeIsNotTouchedByTheHotspotSlots(): void
    {
        [$u, $pw] = $this->provision('ppp', 1, 'PPPOE');
        $r = self::$w->send(self::$w->pppoeAccess($u, $pw, RadiusWire::attempt(self::NAS), 'pap'));
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertNotNull(RadiusWire::token($r), 'the PPPoE Class (nuxr1)');
        $this->assertNull(RadiusWire::hotspotToken($r));
        $this->assertSame([], self::slots($u), 'no Hotspot slot for PPPoE');
    }
}
