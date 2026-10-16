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
 * WP8 A2-R (owner authorization 2026-10-09) - PPPoE ADMISSION RESERVATION contract on the real FreeRADIUS 3.2.8 of the
 * WP4 harness, against BOTH engines (freeradius-mariadb -> MariaDB 10.11, freeradius-mysql -> MySQL 8.0), with the
 * production site (install/freeradius/sites-available/phpnuxbill, threaded server) and the additive schema
 * (install/freeradius/nuxr_admission.sql). Requests are RouterOS-shaped PPPoE Access-Requests built byte by byte
 * (tests/Support/RadiusWire.php). The attempts use TEST-NET NAS addresses that have no SNMP credential entry, so any
 * path that would need the A1 SNMP verification is refused here (the real-NAS gates cover it).
 */
final class AdmissionReservationContractTest extends TestCase
{
    private const DB = 'nuxr_radius';
    private const NAS = '192.0.2.20';
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
        $fx->loadSqlFile(self::$pdo, dirname(__DIR__, 3) . '/install/freeradius/nuxr_hs_admission.sql');      // WP8 H-2B (Hotspot logins)
        RadiusClient::waitReady();
        self::$w = RadiusWire::harness();
    }

    private function provision(string $username, string $password, string $type = 'PPPOE'): string
    {
        $plan = ['id' => random_int(1000, 999999), 'type' => $type, 'typebp' => 'Unlimited', 'shared_users' => 1, 'pool' => 'nuxr-pool'];
        $policy = RadiusPolicyBuilder::build(['username' => $username, 'password' => $password], $plan, self::BW, new \DateTimeImmutable('+2 hours'));
        $r = (new RadiusProvisioner(new RadiusRepository(self::$pdo)))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    private static function uname(string $tag): string
    {
        return 'nuxr_a2r_' . $tag . '_' . bin2hex(random_bytes(3));
    }

    /** the reservation row of the canonical subscriber of $name (the radcheck lookup) */
    private static function claim(string $name): ?array
    {
        $st = self::$pdo->prepare('SELECT * FROM nuxr_admission WHERE username = (SELECT username FROM radcheck WHERE username = ? ORDER BY CAST(username AS BINARY) LIMIT 1)');
        $st->execute([$name]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private static function claims(string $name): int
    {
        $st = self::$pdo->prepare('SELECT COUNT(*) FROM nuxr_admission WHERE username = ?');
        $st->execute([$name]);
        return (int) $st->fetchColumn();
    }

    private function login(string $user, string $pw, ?array $a = null, string $method = 'chap'): array
    {
        $a ??= RadiusWire::attempt(self::NAS);
        $raw = self::$w->pppoeAccess($user, $pw, $a, $method);
        return [self::$w->send($raw), $a, $raw];
    }

    private function assertAccept(?RadiusPacket $r, string $why): string
    {
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code, $why);
        $t = RadiusWire::token($r);
        $this->assertNotNull($t, "{$why}: Class = nuxr1 + 128-bit token");
        return $t;
    }

    private function assertRefused(?RadiusPacket $r, string $why): void
    {
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $r?->code, $why);
        $this->assertSame('Session verification failed', $r->get('Reply-Message'), $why);
        $names = array_values(array_diff(array_unique(array_column($r->attributes, 0)), ['Message-Authenticator']));
        $this->assertSame(['Reply-Message'], $names, "{$why}: no policy attribute and no Class in the reject");
    }

    /** refused by ANY layer (the stock Simultaneous-Use layer may answer first when radacct has an open row): no Accept, no Class */
    private function assertNotAdmitted(?RadiusPacket $r, string $why): void
    {
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $r?->code, $why);
        $this->assertNull(RadiusWire::classHex($r), "{$why}: no Class");
        $this->assertNull($r->get('Framed-Pool'), "{$why}: no policy attribute");
    }

    private static function radacctCount(): int
    {
        return (int) self::$pdo->query('SELECT COUNT(*) FROM radacct')->fetchColumn();
    }

    // -------------------------------------------------------------------------------------------- schema contract

    public function testUpgradeIsIdempotentAndTheReadinessCheckDetectsAnIncompleteSchema(): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        $db = 'nuxr_a2r_chk_' . bin2hex(random_bytes(3));
        $admin = FixtureBuilder::connect($host, TestDatabase::rootPassword());
        $admin->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        try {
            $p = FixtureBuilder::connect($host, TestDatabase::rootPassword(), $db);
            $fx = new FixtureBuilder(dirname(__DIR__, 3));
            $check = static fn(): string => (string) $p->query((string) file_get_contents(dirname(__DIR__, 3) . '/install/freeradius/nuxr_admission_check.sql'))->fetchColumn();
            $fx->loadSqlFile($p, dirname(__DIR__, 3) . '/install/radius.sql');
            $this->assertStringStartsWith('nuxr_admission=NOT_READY:table,audit_table,columns,primary_key,token_unique', $check(), 'before the upgrade');
            $fx->loadSqlFile($p, dirname(__DIR__, 3) . '/install/freeradius/nuxr_admission.sql');
            $this->assertSame('nuxr_admission=READY', $check());
            $p->exec("INSERT INTO nuxr_admission (username, state, token, nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator, session_user, created_at, updated_at) VALUES ('keep', 'ENDED', REPEAT('a', 32), '1.2.3.4', '1', 'm', 'p', REPEAT('b', 32), 'keep', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
            $fx->loadSqlFile($p, dirname(__DIR__, 3) . '/install/freeradius/nuxr_admission.sql');
            $this->assertSame('nuxr_admission=READY', $check(), 'running the upgrade again is harmless');
            $this->assertSame(1, (int) $p->query("SELECT COUNT(*) FROM nuxr_admission WHERE username = 'keep'")->fetchColumn(), 'and keeps the data');
            $p->exec('ALTER TABLE nuxr_admission DROP INDEX uq_nuxr_admission_token');
            $this->assertSame('nuxr_admission=NOT_READY:token_unique', $check());
            $p->exec('DROP TABLE nuxr_admission_audit');
            $this->assertSame('nuxr_admission=NOT_READY:audit_table,token_unique', $check());
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `{$db}`");
        }
    }

    public function testNoDistinctSubscribersCollideUnderTheLookupCollation(): void
    {
        // S-1 pre-check (owner): two DIFFERENT radcheck usernames that the lookup collation treats as equal would share one
        // reservation (and already share their check items today). The harness data must contain none.
        $n = (int) self::$pdo->query('SELECT COUNT(*) FROM (SELECT DISTINCT a.username FROM radcheck a JOIN radcheck b ON a.username = b.username AND CAST(a.username AS BINARY) <> CAST(b.username AS BINARY)) x')->fetchColumn();
        $this->assertSame(0, $n, 'no colliding subscribers in the test data');
    }

    // -------------------------------------------------------------------------------------------- admission

    public function testFirstLoginClaimsOneReservationWithAPerAttemptToken(): void
    {
        foreach (['chap', 'pap'] as $method) {
            $u = $this->provision(self::uname('first' . $method), 'A2r-first-1');
            $before = self::radacctCount();
            [$r, $a, $raw] = $this->login($u, 'A2r-first-1', null, $method);
            $t = $this->assertAccept($r, "{$method}: first login");
            $this->assertSame('nuxr-pool', $r->get('Framed-Pool'), 'the normal PPPoE policy is unchanged');
            $c = self::claim($u);
            $this->assertSame(['PENDING', $t, self::NAS, $a['sid'], $a['mac'], $a['port'], bin2hex(substr($raw, 4, 16)), $u, '0'],
                [$c['state'], $c['token'], $c['nas'], $c['acct_session_id'], $c['calling_station_id'], $c['nas_port_id'], $c['request_authenticator'], $c['session_user'], (string) $c['attach_count']]);
            $this->assertSame($before, self::radacctCount(), 'post-auth never writes radacct');
        }
    }

    public function testConcurrentDifferentAttemptsExactlyOneIsAccepted(): void
    {
        $u = $this->provision(self::uname('race'), 'A2r-race-1');
        $rounds = [];
        for ($i = 0; $i < 10; $i++) {
            $att = array_map(static fn() => RadiusWire::attempt(self::NAS), range(1, 8));
            $raws = array_map(fn($a) => self::$w->pppoeAccess($u, 'A2r-race-1', $a), $att);
            $res = self::$w->sendMany($raws);
            $acc = array_keys(array_filter($res, static fn($r) => $r?->code === RadiusPacket::ACCESS_ACCEPT));
            $rounds[] = [count($acc), count(array_filter($res, static fn($r) => $r?->code === RadiusPacket::ACCESS_REJECT)), count(array_filter($res, static fn($r) => $r === null))];
            $this->assertCount(1, $acc, "round {$i}: exactly one of 8 concurrent attempts is accepted " . json_encode($rounds));
            $winner = $att[$acc[0]];
            $c = self::claim($u);
            $this->assertSame([$winner['sid'], RadiusWire::token($res[$acc[0]])], [$c['acct_session_id'], $c['token']], 'the reservation belongs to the accepted attempt');
            self::$w->pppoeAccounting($u, $winner, 'Stop', hex2bin((string) RadiusWire::classHex($res[$acc[0]])));
            $this->assertSame('ENDED', self::claim($u)['state']);
        }
        $this->assertSame(1, self::claims(self::claim($u)['username']), 'one reservation row per subscriber');
    }

    public function testRetransmissionReusesTheClaimAndANewAttemptIsRefused(): void
    {
        $u = $this->provision(self::uname('retx'), 'A2r-retx-1');
        $a = RadiusWire::attempt(self::NAS);
        $raw = self::$w->pppoeAccess($u, 'A2r-retx-1', $a);
        $s = self::$w->socket();
        try {
            $r1 = self::$w->send($raw, 1812, 5.0, $s);
            $r2 = self::$w->send($raw, 1812, 5.0, $s);          // the exact bytes from the same source port
        } finally {
            fclose($s);
        }
        $t = $this->assertAccept($r1, 'original');
        $this->assertSame($r1->raw, $r2?->raw, 'an exact retransmission gets the identical reply');
        $this->assertSame(['PENDING', $t, '0'], [self::claim($u)['state'], self::claim($u)['token'], (string) self::claim($u)['attach_count']]);
        // the same identity with a NEW Request Authenticator is a new attempt (RouterOS never re-authenticates a PPP
        // session that way; its retransmissions are exact) - refused while the claim is PENDING
        [$r] = $this->login($u, 'A2r-retx-1', $a);
        $this->assertRefused($r, 'same identity, new Request Authenticator');
        foreach (['new session' => RadiusWire::attempt(self::NAS), 'same Acct-Session-Id, other MAC' => RadiusWire::attempt(self::NAS, $a['sid']),
                  'same Acct-Session-Id + MAC, other port' => RadiusWire::attempt(self::NAS, $a['sid'], $a['mac'], 'ether9'),
                  'same Acct-Session-Id + MAC + port, other NAS' => RadiusWire::attempt('192.0.2.21', $a['sid'], $a['mac'], $a['port'])] as $why => $b) {
            [$r] = $this->login($u, 'A2r-retx-1', $b);
            $this->assertRefused($r, $why);
        }
        $this->assertSame(['PENDING', $t], [self::claim($u)['state'], self::claim($u)['token']], 'the claim is untouched by refused attempts');
    }

    public function testAttemptIdentityAndMethodsFailClosed(): void
    {
        $u = $this->provision(self::uname('ident'), 'A2r-ident-1');
        $cases = ['no Acct-Session-Id' => [['Acct-Session-Id'], []], 'no Calling-Station-Id' => [['Calling-Station-Id'], []], 'no NAS-Port-Id' => [['NAS-Port-Id'], []]];
        foreach ($cases as $why => [$drop]) {
            $r = self::$w->send(self::$w->pppoeAccess($u, 'A2r-ident-1', RadiusWire::attempt(self::NAS), 'chap', $drop));
            $this->assertRefused($r, $why);
        }
        foreach (['malformed Acct-Session-Id' => RadiusWire::attempt(self::NAS, "81'; DROP--"), 'over-long Acct-Session-Id' => RadiusWire::attempt(self::NAS, str_repeat('A', 40)),
                  'malformed Calling-Station-Id' => RadiusWire::attempt(self::NAS, null, 'zz:zz<b>')] as $why => $a) {
            [$r] = $this->login($u, 'A2r-ident-1', $a);
            $this->assertRefused($r, $why);
        }
        $r = self::$w->send(self::$w->pppoeAccess($u, 'A2r-ident-1', RadiusWire::attempt(self::NAS), 'mschapv2'));
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $r?->code, 'MS-CHAPv2 is not a method of this server: refused');
        $this->assertNull(self::claim($u), 'no reservation from any refused request');
    }

    public function testAccountingTransitionsAreTokenNasAndIdentityBound(): void
    {
        $u = $this->provision(self::uname('acct'), 'A2r-acct-1');
        [$r, $a] = $this->login($u, 'A2r-acct-1');
        $t = $this->assertAccept($r, 'claim');
        $cls = '6e75787231' . $t;
        // missing / invalid / foreign Class: nothing changes (sent as Stop: a wrong match would END the claim)
        $bad = ['no Class' => [null, null, $a], 'garbage Class' => [bin2hex('garbage'), null, $a], 'other prefix' => ['6e75787232' . $t, null, $a],
                'unknown token' => ['6e75787231' . bin2hex(random_bytes(16)), null, $a], 'right token, other NAS' => [$cls, '192.0.2.21', $a],
                'right token, other Acct-Session-Id' => [$cls, null, ['sid' => 'deadbeef'] + $a], 'right token, other MAC' => [$cls, null, ['mac' => '02:A2:00:00:00:01'] + $a]];
        foreach ($bad as $why => [$c, $nas, $att]) {
            $resp = self::$w->pppoeAccounting($u, $att, 'Stop', $c === null ? null : hex2bin($c), $nas);
            $this->assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $resp?->code, "{$why}: still answered");
            $this->assertSame('PENDING', self::claim($u)['state'], "{$why}: the claim is unchanged");
        }
        // lost Start: the first Interim-Update with the token confirms
        self::$w->pppoeAccounting($u, $a, 'Interim-Update', hex2bin($cls));
        $c = self::claim($u);
        $this->assertSame('ACTIVE', $c['state']);
        $this->assertNotNull($c['confirmed_at']);
        self::$w->pppoeAccounting($u, $a, 'Accounting-On');
        $this->assertSame('ACTIVE', self::claim($u)['state'], 'Accounting-On changes no reservation');
        self::$w->pppoeAccounting($u, $a, 'Stop', hex2bin($cls));
        $this->assertSame(['ENDED', 'acct_stop'], [self::claim($u)['state'], self::claim($u)['end_reason']]);
        self::$w->pppoeAccounting($u, $a, 'Start', hex2bin($cls));
        $this->assertSame('ENDED', self::claim($u)['state'], 'a late Start never revives an ENDED claim');
        // the next attempt takes the ENDED row over; old-token accounting cannot touch it
        [$r2, $b] = $this->login($u, 'A2r-acct-1');
        $t2 = $this->assertAccept($r2, 'next login');
        $c = self::claim($u);
        $this->assertSame([$t2, $t, 'ENDED'], [$c['token'], $c['prev_token'], $c['prev_state']]);
        foreach (['Start', 'Stop'] as $st) {
            self::$w->pppoeAccounting($u, $a, $st, hex2bin($cls));
        }
        $this->assertSame([$c['state'], $c['token'], $c['updated_at']], [self::claim($u)['state'], self::claim($u)['token'], self::claim($u)['updated_at']], 'old accounting changes nothing');
        // Stop before Start of the new attempt
        self::$w->pppoeAccounting($u, $b, 'Stop', RadiusWire::classHex($r2) === null ? null : hex2bin(RadiusWire::classHex($r2)));
        $this->assertSame('ENDED', self::claim($u)['state'], 'Stop without Start ends the PENDING claim');
    }

    public function testS1EquivalentSpellingsShareOneReservation(): void
    {
        // NAS 192.0.2.11 (clients.conf nas_type mikrotik_snmp): the stock checkrad as shipped answers "not logged in" for
        // an open row, so the post-auth policy itself must refuse (with nas_type other the stock layer refuses first)
        $nas = '192.0.2.11';
        $base = 'nuxr_a2r_s1_cafe_' . bin2hex(random_bytes(2));
        $u = $this->provision($base, 'A2r-s1-1');
        $variants = [strtoupper($base), str_replace('cafe', 'café', $base), str_replace('cafe', 'CAFÉ', $base), str_replace('a2r', 'á2r', $base)];
        [$r, $a] = $this->login($u, 'A2r-s1-1', RadiusWire::attempt($nas));
        $t = $this->assertAccept($r, 'base spelling');
        foreach ($variants as $v) {
            [$rv] = $this->login($v, 'A2r-s1-1', RadiusWire::attempt($nas));
            $this->assertRefused($rv, "equivalent spelling {$v} while the claim is PENDING");
        }
        $this->assertSame(1, self::claims($u));
        // end it, then admit a VARIANT: the same row (canonical key) now carries the variant's session
        self::$w->pppoeAccounting($u, $a, 'Stop', hex2bin('6e75787231' . $t));
        [$rv, $av] = $this->login($variants[1], 'A2r-s1-1', RadiusWire::attempt($nas));
        $tv = $this->assertAccept($rv, 'a variant after the end');
        $c = self::claim($u);
        $this->assertSame([$u, $variants[1], 'ENDED'], [$c['username'], $c['session_user'], $c['prev_state']], 'canonical key = radcheck.username; spelling recorded');
        self::$w->pppoeAccounting($variants[1], $av, 'Start', hex2bin('6e75787231' . $tv));
        $this->assertSame('ACTIVE', self::claim($u)['state']);
        foreach ([$u, $variants[0], $variants[3]] as $v) {
            [$rx] = $this->login($v, 'A2r-s1-1', RadiusWire::attempt($nas));
            $this->assertRefused($rx, "{$v} while the ACTIVE session is under another spelling");
        }
        $this->assertSame(1, self::claims($u), 'never a second reservation for an equivalent spelling');
        // a radacct row under another spelling WITHOUT a reservation (legacy / pre-upgrade session): refused
        $base2 = 'nuxr_a2r_s1_legacy_' . bin2hex(random_bytes(2));
        $u2 = $this->provision($base2, 'A2r-s1-2');
        self::$w->pppoeAccounting(strtoupper($base2), RadiusWire::attempt($nas), 'Start');
        [$rl] = $this->login($u2, 'A2r-s1-2', RadiusWire::attempt($nas));
        $this->assertRefused($rl, 'open radacct row under another spelling');
        $this->assertNull(self::claim($u2));
    }

    public function testCreatorReleasesOnlyAnUnusedLateClaimAndAMissingTableFailsClosed(): void
    {
        // LATE CLAIM: the claim statement is held by a row lock (another session holds the ENDED row FOR UPDATE) longer
        // than the 5000 ms guard (6.5 s) -> the claim completes late, its creator releases it and refuses; nothing is
        // accepted. (Until the owner decision D-AV4 of 2026-10-10 the guard was 2000 ms and the holds were 3 s.)
        $u = $this->provision(self::uname('late'), 'A2r-late-1');
        [$r, $a] = $this->login($u, 'A2r-late-1');
        $t = $this->assertAccept($r, 'claim');
        self::$w->pppoeAccounting($u, $a, 'Stop', hex2bin('6e75787231' . $t));
        $lock = FixtureBuilder::connect((string) getenv('WP1_DB_HOST'), TestDatabase::rootPassword(), self::DB);
        $key = self::claim($u)['username'];
        $lock->beginTransaction();
        $lock->prepare('SELECT state FROM nuxr_admission WHERE username = ? FOR UPDATE')->execute([$key]);
        $raw = self::$w->pppoeAccess($u, 'A2r-late-1', RadiusWire::attempt(self::NAS));
        $s = self::$w->begin($raw);
        usleep(6_500_000);
        $lock->commit();
        $rl = self::$w->finish($s, $raw);
        $this->assertRefused($rl, 'late claim');
        $c = self::claim($u);
        $this->assertSame(['ENDED', 'late_claim', 'ENDED', '0'], [$c['state'], $c['end_reason'], $c['prev_state'], (string) $c['attach_count']], 'claimed late, released by its creator');
        // LATE BEFORE THE CLAIM: the whole request is held (radcheck locked) - refused, no claim at all
        $lock->exec('LOCK TABLES radcheck WRITE');
        $raw = self::$w->pppoeAccess($u, 'A2r-late-1', RadiusWire::attempt(self::NAS));
        $s = self::$w->begin($raw);
        usleep(6_500_000);
        $lock->exec('UNLOCK TABLES');
        $rl = self::$w->finish($s, $raw);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $rl?->code, 'held before the claim: refused');
        $this->assertSame([$c['token'], $c['updated_at']], [self::claim($u)['token'], self::claim($u)['updated_at']], 'no claim was made');
        // inside the budget: a 3 s hold of the claim statement (a slow A1 verification path) is admitted, and its
        // claim is the one the accounting then confirms
        $lock->beginTransaction();
        $lock->prepare('SELECT state FROM nuxr_admission WHERE username = ? FOR UPDATE')->execute([$key]);
        $a3 = RadiusWire::attempt(self::NAS);
        $raw = self::$w->pppoeAccess($u, 'A2r-late-1', $a3);
        $s = self::$w->begin($raw);
        usleep(3_000_000);
        $lock->commit();
        $t3 = $this->assertAccept(self::$w->finish($s, $raw), 'a 3 s hold is inside the 5000 ms budget');
        $this->assertSame(['PENDING', $t3], [self::claim($u)['state'], self::claim($u)['token']]);
        self::$w->pppoeAccounting($u, $a3, 'Stop', hex2bin('6e75787231' . $t3));
        // control: a short hold (0.5 s) is admitted normally
        $lock->beginTransaction();
        $lock->prepare('SELECT state FROM nuxr_admission WHERE username = ? FOR UPDATE')->execute([$key]);
        $raw = self::$w->pppoeAccess($u, 'A2r-late-1', RadiusWire::attempt(self::NAS));
        $s = self::$w->begin($raw);
        usleep(500_000);
        $lock->commit();
        $this->assertAccept(self::$w->finish($s, $raw), 'a short hold is admitted');
        // the table missing (upgrade not applied / renamed): every PPPoE login refused, reply carries only Reply-Message
        $u3 = $this->provision(self::uname('notable'), 'A2r-notable-1');
        self::$pdo->exec('RENAME TABLE nuxr_admission TO nuxr_admission_gate_off');
        try {
            [$rm] = $this->login($u3, 'A2r-notable-1');
            $this->assertRefused($rm, 'admission table missing');
        } finally {
            self::$pdo->exec('RENAME TABLE nuxr_admission_gate_off TO nuxr_admission');
        }
        [$ok] = $this->login($u3, 'A2r-notable-1');
        $this->assertAccept($ok, 'table back: admitted');
    }

    public function testOperatorRecoveryReleasesOnlyThatPendingClaimAndAuditsEveryDecision(): void
    {
        $u = $this->provision(self::uname('rec'), 'A2r-rec-1');
        [$r] = $this->login($u, 'A2r-rec-1');
        $t = $this->assertAccept($r, 'claim');
        $key = self::claim($u)['username'];
        $run = function (array $vars): string {
            foreach ($vars as $k => $v) {
                self::$pdo->prepare("SET @nuxr_{$k} = ?")->execute([$v]);
            }
            $st = self::$pdo->query((string) file_get_contents(dirname(__DIR__, 3) . '/install/freeradius/nuxr_admission_release.sql'));
            $last = null;
            do {
                if ($st->columnCount() > 0) {
                    $last = $st->fetchColumn();
                }
            } while ($st->nextRowset());
            return (string) $last;
        };
        $vars = ['username' => $key, 'token' => $t, 'operator' => 'noc-test', 'reason' => 'Accept lost, user locked out', 'evidence' => 'NAS rebooted after created_at (lab test)'];
        $this->assertSame('nuxr_admission_release=REFUSED', $run(['reason' => 'short'] + $vars), 'no reason, no release');
        $this->assertSame('nuxr_admission_release=REFUSED', $run(['token' => str_repeat('0', 32)] + $vars), 'another token: never a newer claim');
        $this->assertSame('PENDING', self::claim($u)['state']);
        $this->assertSame('nuxr_admission_release=RELEASED', $run($vars));
        $this->assertSame(['ENDED', 'operator_recovery'], [self::claim($u)['state'], self::claim($u)['end_reason']]);
        $this->assertSame('nuxr_admission_release=REFUSED', $run($vars), 'only once');
        $st = self::$pdo->prepare('SELECT outcome, operator, reason, evidence FROM nuxr_admission_audit WHERE username = ? ORDER BY id');
        $st->execute([$key]);
        $audit = $st->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(['REFUSED', 'REFUSED', 'RELEASED', 'REFUSED'], array_column($audit, 'outcome'), 'every decision is audited');
        // WP9 AV-3: the database-attested account and connection precede the declared evidence (not chosen by the client)
        $this->assertMatchesRegularExpression('/^\[db_account=root@[^ ]+ connection=[0-9]+\] NAS rebooted after created_at \(lab test\)$/', (string) $audit[2]['evidence']);
        $this->assertSame('noc-test', $audit[2]['operator'], 'the declared operator name is kept as declared');
        [$r2, $b] = $this->login($u, 'A2r-rec-1');
        $t2 = $this->assertAccept($r2, 'admitted after the release');
        self::$w->pppoeAccounting($u, $b, 'Start', hex2bin('6e75787231' . $t2));
        $this->assertSame('nuxr_admission_release=REFUSED', $run(['token' => $t2] + $vars), 'an ACTIVE claim is never released by the operator procedure');
    }

    public function testHotspotIsNotTouchedByTheAdmission(): void
    {
        // WP8 H-2B: a Hotspot login gets its OWN admission slot ("nuxh1" Class, nuxr_hs_admission) - never the PPPoE
        // reservation ("nuxr1" Class, nuxr_admission)
        $u = $this->provision(self::uname('hs'), 'A2r-hs-1', 'Hotspot');
        $r = self::$w->send(self::$w->hotspotAccess($u, 'A2r-hs-1', RadiusWire::attempt(self::NAS), 'pap'));
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertNull(RadiusWire::token($r), 'no PPPoE admission Class');
        $this->assertNotNull(RadiusWire::hotspotToken($r), 'the Hotspot slot Class');
        $this->assertNull(self::claim($u), 'no PPPoE reservation');
    }
}
