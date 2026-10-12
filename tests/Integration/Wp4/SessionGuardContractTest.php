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
 * WP8 A1 (OD-W8-16 Option G, owner decisions D1-D4 of 2026-10-09): the PERMANENT fail-closed PPPoE session guard
 * evaluated by the real FreeRADIUS 3.2.8 test server with the project configuration (install/freeradius, installed by
 * dev/test/freeradius/start.sh exactly as README.md describes) on BOTH database engines - the open-row lookup is SQL
 * xlat behaviour. No NAS is needed: the open rows name TEST-NET-1 NAS identities of type mikrotik_snmp
 * (dev/test/freeradius/clients.conf), 192.0.2.10 with a guard credential entry but unreachable (SNMP health probe
 * fails), 192.0.2.11 without an entry. Real-NAS behaviour (LIVE / SAFE on RouterOS) is covered by
 * tests/Integration/Wp8/OptionGGateTest.php in the WP8 lab.
 */
final class SessionGuardContractTest extends TestCase
{
    private const DB = 'nuxr_radius';
    private const PPPOE = [['Service-Type', 2], ['Framed-Protocol', 1], ['NAS-Port-Type', 15]];     // Framed-User, PPP, Ethernet
    private const HOTSPOT = [['Service-Type', 1], ['NAS-Port-Type', 19]];                         // Login-User, Wireless-802.11
    private const UNREACHABLE = '192.0.2.10';
    private const NO_CREDENTIALS = '192.0.2.11';
    private const BW = ['rate_up' => '2', 'rate_up_unit' => 'Mbps', 'rate_down' => '4', 'rate_down_unit' => 'Mbps', 'burst' => ''];
    private static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        $admin = FixtureBuilder::connect($host, TestDatabase::rootPassword());
        $admin->exec('CREATE DATABASE IF NOT EXISTS `' . self::DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$pdo = FixtureBuilder::connect($host, TestDatabase::rootPassword(), self::DB);
        (new FixtureBuilder(dirname(__DIR__, 3)))->loadSqlFile(self::$pdo, dirname(__DIR__, 3) . '/install/radius.sql');
        // WP8 A2-R: the PPPoE path of the site also needs the additive admission schema (README install order)
        (new FixtureBuilder(dirname(__DIR__, 3)))->loadSqlFile(self::$pdo, dirname(__DIR__, 3) . '/install/freeradius/nuxr_admission.sql');
        // WP8 H-2B: the Hotspot path needs the additive admission slot schema
        (new FixtureBuilder(dirname(__DIR__, 3)))->loadSqlFile(self::$pdo, dirname(__DIR__, 3) . '/install/freeradius/nuxr_hs_admission.sql');
        RadiusClient::waitReady();
    }

    private static function user(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4));
    }

    private function provision(string $type, string $username, string $password, int $shared = 1): string
    {
        $plan = ['id' => random_int(1000, 999999), 'type' => $type, 'typebp' => 'Unlimited', 'shared_users' => $shared, 'pool' => 'nuxr-pool'];
        $policy = RadiusPolicyBuilder::build(['username' => $username, 'password' => $password], $plan, self::BW, new \DateTimeImmutable('+2 hours'));
        $r = (new RadiusProvisioner(new RadiusRepository(self::$pdo)))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    /** an open radacct row written by FreeRADIUS itself (accounting Start) on the given NAS */
    private static function openRow(string $username, string $nas): array
    {
        $r = RadiusClient::accounting([['User-Name', $username], ['Acct-Status-Type', 1], ['Acct-Session-Id', bin2hex(random_bytes(6))],
            ['NAS-Port', 7], ['Framed-IP-Address', '10.20.0.7'], ['Event-Timestamp', time()]], $nas);
        self::assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $r?->code, 'accounting answered');
        $st = self::$pdo->prepare('SELECT * FROM radacct WHERE nasipaddress = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1');
        $st->execute([$nas]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private static function row(int $id): array
    {
        $st = self::$pdo->prepare('SELECT * FROM radacct WHERE radacctid = ?');
        $st->execute([$id]);
        return (array) $st->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array{0:?RadiusPacket,1:float} reply and wall time in ms */
    private static function access(string $username, string $password, array $attrs): array
    {
        $t = microtime(true);
        // WP8 A2-R: a PPPoE Access-Request carries the RouterOS attempt identity (Acct-Session-Id, Calling-Station-Id,
        // NAS-Port-Id), as every real RouterOS PPPoE request does; without it the site refuses the login (fail closed)
        // WP8 H-2B: likewise a Hotspot Access-Request (Acct-Session-Id, Calling-Station-Id)
        $r = match ($attrs) {
            self::PPPOE => RadiusWire::harness()->send(RadiusWire::harness()->pppoeAccess($username, $password, RadiusWire::attempt('127.0.0.1'), 'pap')),
            self::HOTSPOT => RadiusWire::harness()->send(RadiusWire::harness()->hotspotAccess($username, $password, RadiusWire::attempt('127.0.0.1'), 'pap')),
            default => RadiusClient::access($username, $password, false, $attrs),
        };
        return [$r, (microtime(true) - $t) * 1000];
    }

    private function assertGuardReject(?RadiusPacket $r, float $ms, string $label): void
    {
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $r?->code, "{$label}: Access-Reject, never Accept");
        $this->assertSame('Session verification failed', $r->get('Reply-Message'), "{$label}: the guard's reject");
        $names = array_values(array_diff(array_unique(array_column($r->attributes, 0)), ['Message-Authenticator']));
        $this->assertSame(['Reply-Message'], $names, "{$label}: no policy attribute leaks into the reject (attr_filter.access_reject)");
        $this->assertLessThan(2500, $ms, "{$label}: answered well inside a 3 s RouterOS /radius timeout");
    }

    public function testFirstPppoeLoginWithoutAnOpenRowNeedsNoVerification(): void
    {
        $u = $this->provision('PPPOE', self::user('g-first'), 'G-first-1');
        [$r, $ms] = self::access($u, 'G-first-1', self::PPPOE);
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame('nuxr-pool', $r->get('Framed-Pool'), 'normal PPPoE policy');
        $this->assertNull($r->get('Port-Limit'), 'PPPoE never relies on Port-Limit');
        $this->assertLessThan(900, $ms, 'no NAS query on the first login');
    }

    public function testOpenRowOnAnUnverifiableNasRejectsAndKeepsTheHistory(): void
    {
        $u = $this->provision('PPPOE', self::user('g-unreach'), 'G-unreach-1');
        $row = self::openRow($u, self::UNREACHABLE);
        [$r, $ms] = self::access($u, 'G-unreach-1', self::PPPOE);
        $this->assertGuardReject($r, $ms, 'SNMP health probe fails');
        $this->assertGreaterThan(900, $ms, 'the probe really ran (1 s SNMP timeout) - proves the credential entry was found next to the program');
        $this->assertSame($row, self::row((int) $row['radacctid']), 'the stale row is byte-for-byte unchanged: no zap, no fabricated Stop');
    }

    public function testOpenRowOnANasWithoutCredentialsRejects(): void
    {
        $u = $this->provision('PPPOE', self::user('g-nocred'), 'G-nocred-1');
        $row = self::openRow($u, self::NO_CREDENTIALS);
        [$r, $ms] = self::access($u, 'G-nocred-1', self::PPPOE);
        $this->assertGuardReject($r, $ms, 'missing credential entry');
        $this->assertSame($row, self::row((int) $row['radacctid']));
    }

    public function testMultiNasAnyUnverifiableNasRejects(): void
    {
        $u = $this->provision('PPPOE', self::user('g-multi'), 'G-multi-1', 1);
        self::openRow($u, self::NO_CREDENTIALS);
        self::openRow($u, self::UNREACHABLE);
        [$r, $ms] = self::access($u, 'G-multi-1', self::PPPOE);
        $this->assertGuardReject($r, $ms, 'two NAS, both unverifiable');
    }

    public function testAnOpenRowOnANasOfTypeOtherStaysWithTheStockLayer(): void
    {
        // the nas type is an operator setting (README contract: mikrotik_snmp). With "other" the stock checkrad
        // trusts the row as live: the login is refused before post-auth (fail-safe lockout, never an Accept).
        $u = $this->provision('PPPOE', self::user('g-other'), 'G-other-1');
        self::openRow($u, '127.0.0.1');
        [$r] = self::access($u, 'G-other-1', self::PPPOE);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $r?->code);
        $this->assertNotSame('Session verification failed', $r->get('Reply-Message'), 'the stock Simultaneous-Use reject, not the guard');
    }

    public function testHotspotNeverReachesTheGuardAndCarriesThePortLimit(): void
    {
        $u = $this->provision('Hotspot', self::user('g-hs'), 'G-hs-1', 2);
        self::openRow($u, self::UNREACHABLE);
        [$r, $ms] = self::access($u, 'G-hs-1', self::HOTSPOT);
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code, 'one stale row: the guard does not run for Hotspot (WP8 H-2B: the stock Simultaneous-Use check neither - the slots decide)');
        $this->assertSame(2, $r->get('Port-Limit'), 'the NAS enforces the Hotspot limit (Port-Limit in radreply)');
        $this->assertLessThan(900, $ms, 'no SNMP probe was made');
    }

    public function testHostileUsernamesAreMatchedOnTheStockSqlKey(): void
    {
        foreach (["g-q'uote", 'g-d"quote', 'g-back`id`', 'g-semi;id', 'g-$(id)', 'g-back\\slash', 'g-%{User-Password}', '-g-dash'] as $i => $base) {
            $u = $base . $i . bin2hex(random_bytes(2));
            $near = $u . 'x';
            foreach ([$u, $near] as $name) {
                $key = self::frSqlKey($name);
                self::$pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', 'G-hostile-1'), (?, 'Simultaneous-Use', ':=', '1')")->execute([$key, $key]);
            }
            $row = self::openRow($u, self::NO_CREDENTIALS);
            $this->assertSame(self::frSqlKey($u), $row['username'], "{$base}: FreeRADIUS stored the row under the stock key");
            [$r, $ms] = self::access($u, 'G-hostile-1', self::PPPOE);
            $this->assertGuardReject($r, $ms, "{$base}: the open row was found (exact key) and verification failed closed");
            [$r] = self::access($near, 'G-hostile-1', self::PPPOE);
            $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code, "{$base}: a near-miss name is another subscriber without open rows");
        }
    }

    /** the stock FreeRADIUS 3.2.8 rlm_sql key: '"' and '\\' backslash-escaped, then bytes outside safe_characters as =XX */
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
}
