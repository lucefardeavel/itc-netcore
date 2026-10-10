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
 * WP8 H-1 H1-A (owner authorization 2026-10-10) against the REAL FreeRADIUS 3.2.8 of the harness (both engines), with
 * the project site and the accepted RadiusPolicyBuilder policy: a Hotspot login is admitted only under the exact
 * stored spelling. Every equivalent spelling (case, accent, sharp s) that the lookup authenticates - proven by the
 * same spelling being admitted as PPPoE (S-1 / A2-R) - is refused for Hotspot, and that refusal is indistinguishable
 * from a wrong password and from an unknown user (code, attributes, no Reply-Message, reject_delay). An ambiguous
 * identity (two byte-distinct stored spellings) is refused for both. Accounting is unchanged.
 */
final class HotspotSpellingContractTest extends TestCase
{
    private const DB = 'nuxr_radius';
    private const NAS = '192.0.2.21';
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
        RadiusClient::waitReady();
        self::$w = RadiusWire::harness();
    }

    private function provision(string $username, string $password, int $shared = 1): string
    {
        $plan = ['id' => random_int(1000, 999999), 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'shared_users' => $shared];
        $policy = RadiusPolicyBuilder::build(['username' => $username, 'password' => $password], $plan, self::BW, new \DateTimeImmutable('+2 hours'));
        $r = (new RadiusProvisioner(new RadiusRepository(self::$pdo)))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    private static function base(string $tag): string
    {
        return 'nuxr_h1_' . $tag . '_cafes_' . bin2hex(random_bytes(2));
    }

    /** @return array{0:?RadiusPacket,1:int} reply and elapsed ms */
    private static function hotspot(string $user, string $pw, bool $chap = true): array
    {
        $t = microtime(true);
        $r = RadiusClient::access($user, $pw, $chap, RadiusWire::HOTSPOT);
        return [$r, (int) round((microtime(true) - $t) * 1000)];
    }

    /** the reply attributes a client can see (the Message-Authenticator differs per packet) */
    private static function visible(?RadiusPacket $r): array
    {
        return [$r?->code, array_values(array_filter($r?->attributes ?? [], static fn($a) => $a[0] !== 'Message-Authenticator'))];
    }

    public function testExactSpellingIsAdmittedWithTheUnchangedPolicy(): void
    {
        $u = $this->provision(self::base('ok'), 'H1a-ok-1');
        foreach ([true, false] as $chap) {
            [$r] = self::hotspot($u, 'H1a-ok-1', $chap);
            $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code, ($chap ? 'CHAP' : 'PAP') . ': exact spelling admitted');
            $this->assertSame(1, (int) $r->get('Port-Limit'), 'Port-Limit = shared_users');
            $this->assertNotNull($r->get('Mikrotik-Rate-Limit'), 'rate limit unchanged');
            $this->assertNotNull($r->get('Session-Timeout'), 'expiry -> Session-Timeout unchanged');
            $this->assertNull($r->get('User-Name'), 'no User-Name in the reply (H1-B is not used)');
            $this->assertNull($r->get('Class'), 'no admission Class for Hotspot');
            $this->assertNull($r->get('Reply-Message'));
        }
        $st = self::$pdo->prepare('SELECT COUNT(*) FROM nuxr_admission WHERE username = ?');
        $st->execute([$u]);
        $this->assertSame(0, (int) $st->fetchColumn(), 'no reservation for Hotspot');
        // accounting of the admitted session is written by the unchanged accounting SQL
        $sid = bin2hex(random_bytes(4));
        $acct = [['User-Name', $u], ['Acct-Session-Id', $sid], ['NAS-Port-Type', 19], ['Service-Type', 1], ['Framed-IP-Address', '10.99.1.10']];
        $this->assertSame(RadiusPacket::ACCOUNTING_RESPONSE, RadiusClient::accounting([['Acct-Status-Type', 1], ...$acct], self::NAS)?->code);
        $this->assertSame(RadiusPacket::ACCOUNTING_RESPONSE, RadiusClient::accounting([['Acct-Status-Type', 2], ['Acct-Session-Time', 5], ...$acct], self::NAS)?->code);
        $st = self::$pdo->prepare('SELECT username, acctstoptime IS NOT NULL FROM radacct WHERE acctsessionid = ?');
        $st->execute([$sid]);
        $this->assertSame([[$u, 1]], array_map(static fn($r) => [$r[0], (int) $r[1]], $st->fetchAll(\PDO::FETCH_NUM)), 'Start + Stop under the exact spelling');
    }

    public function testEquivalentSpellingsAuthenticateButAreRefusedForHotspot(): void
    {
        $u = $this->provision(self::base('var'), 'H1a-var-1');
        $variants = ['upper' => strtoupper($u), 'first_upper' => ucfirst($u), 'accent' => str_replace('cafe', 'café', $u), 'sharp_s' => str_replace('cafes', 'cafeß', $u)];
        foreach ($variants as $label => $v) {
            $this->assertNotSame($u, $v);
            // the lookup authenticates this spelling: as PPPoE it is admitted (S-1: it shares the subscriber's reservation)
            $a = RadiusWire::attempt(self::NAS);
            $p = self::$w->send(self::$w->pppoeAccess($v, 'H1a-var-1', $a, 'pap'));
            $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $p?->code, "{$label}: the lookup authenticates the spelling (PPPoE)");
            self::$w->pppoeAccounting($v, $a, 'Stop', (string) hex2bin((string) RadiusWire::classHex($p)));
            foreach ([true, false] as $chap) {
                [$r] = self::hotspot($v, 'H1a-var-1', $chap);
                $this->assertSame(RadiusPacket::ACCESS_REJECT, $r?->code, "{$label} / " . ($chap ? 'CHAP' : 'PAP') . ': refused for Hotspot');
            }
        }
        [$ok] = self::hotspot($u, 'H1a-var-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $ok?->code, 'the exact spelling still logs in');
    }

    public function testTheRefusalLooksLikeAWrongPasswordAndAnUnknownUser(): void
    {
        $u = $this->provision(self::base('rej'), 'H1a-rej-1');
        [$variant, $tv] = self::hotspot(strtoupper($u), 'H1a-rej-1');
        [$wrong, $tw] = self::hotspot($u, 'not-the-password');
        [$unknown, $tu] = self::hotspot('nuxr_h1_nobody_' . bin2hex(random_bytes(3)), 'H1a-rej-1');
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $variant?->code);
        $this->assertSame(self::visible($wrong), self::visible($variant), 'same code and attributes as a wrong password');
        $this->assertSame(self::visible($unknown), self::visible($variant), 'same code and attributes as an unknown user');
        $this->assertNull($variant->get('Reply-Message'), 'no reason disclosed');
        foreach (['variant' => $tv, 'wrong password' => $tw, 'unknown user' => $tu] as $what => $ms) {
            $this->assertGreaterThanOrEqual(900, $ms, "{$what}: reject_delay applies");
        }
    }

    public function testAnAmbiguousStoredIdentityIsRefusedForEverySpelling(): void
    {
        // two byte-distinct stored spellings that the lookup collation treats as one subscriber (the A2-R collision
        // pre-check must find none in production; here they are made on purpose): never pick one, never merge
        $lower = self::base('dup');
        $upper = strtoupper($lower);
        $ins = self::$pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', 'H1a-dup-1'), (?, 'Simultaneous-Use', ':=', '1')");
        $ins->execute([$lower, $lower]);
        $ins->execute([$upper, $upper]);
        try {
            foreach ([$lower, $upper] as $spelling) {
                [$r] = self::hotspot($spelling, 'H1a-dup-1');
                $this->assertSame(RadiusPacket::ACCESS_REJECT, $r?->code, "ambiguous identity refused ({$spelling})");
            }
        } finally {
            self::$pdo->prepare('DELETE FROM radcheck WHERE username = ?')->execute([$lower]);
        }
        $st = self::$pdo->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ?');
        $st->execute([$lower]);
        $this->assertSame(0, (int) $st->fetchColumn(), 'cleanup');
    }

    public function testSharedUsersTwoKeepsItsPortLimitForTheExactSpelling(): void
    {
        $u = $this->provision(self::base('two'), 'H1a-two-1', 2);
        [$r] = self::hotspot($u, 'H1a-two-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame(2, (int) $r->get('Port-Limit'));
        [$v] = self::hotspot(strtoupper($u), 'H1a-two-1');
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $v?->code, 'a variant is refused below the limit too');
    }
}
