<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\RadiusPacket;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RadiusClient;
use Reseller\Tests\Support\TestDatabase;

require_once dirname(__DIR__, 2) . '/Support/Wp8Radius.php';

/**
 * Architecture Lock gates Q1-Q4 (FUP / quota correctness, "mandatory", lock axis 6): CHARACTERISATION on the real
 * FreeRADIUS 3.2.8 harness of what the shipped counters (install/freeradius/mods-available/nuxr_counters) and the
 * policy builder do today. It records facts for the owner decision; it does not change behaviour.
 *  - Q2 (time): the Access-Accept carries Session-Timeout = the remaining time quota, so the NAS ends the session when
 *    the time quota is reached mid-session.
 *  - Q2 (data): the Access-Accept carries NO in-session data limit (no Mikrotik-Total-Limit / -Gigawords); Max-Data is
 *    only checked at the next authentication, so a running session can exceed its data quota.
 *  - Q1 (cross-period): usage is counted for sessions STARTED in the current period; a session that started before a
 *    renewal and keeps running is attributed entirely to the previous period (its usage after the renewal is not counted).
 *  - Q4 (no destructive reset): accounting history is kept across the renewal (also FreeRadiusContractTest).
 */
final class W9QuotaGateCharacterisationTest extends TestCase
{
    private const DB = 'nuxr_radius';
    private const BW = ['rate_up' => '2', 'rate_up_unit' => 'Mbps', 'rate_down' => '4', 'rate_down_unit' => 'Mbps', 'burst' => ''];
    private static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        $admin = FixtureBuilder::connect($host, TestDatabase::rootPassword());
        $admin->exec('CREATE DATABASE IF NOT EXISTS `' . self::DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$pdo = FixtureBuilder::connect($host, TestDatabase::rootPassword(), self::DB);
        (new FixtureBuilder(dirname(__DIR__, 3)))->loadSqlFile(self::$pdo, dirname(__DIR__, 3) . '/install/radius.sql');
        RadiusClient::waitReady();
    }

    private function provision(array $customer, array $plan, \DateTimeImmutable $period): string
    {
        $policy = RadiusPolicyBuilder::build($customer, $plan, self::BW, new \DateTimeImmutable('+1 day'), $period);
        $r = (new RadiusProvisioner(new RadiusRepository(self::$pdo)))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    private static function plan(array $extra): array
    {
        return $extra + ['id' => random_int(1000, 999999), 'type' => 'Hotspot', 'typebp' => 'Limited', 'shared_users' => 3, 'pool' => ''];
    }

    private static function acct(string $username, int $status, string $session, array $extra = []): void
    {
        $r = RadiusClient::accounting([['User-Name', $username], ['Acct-Status-Type', $status], ['Acct-Session-Id', $session],
            ['NAS-Port', 7], ['Framed-IP-Address', '10.20.0.9'], ...$extra]);
        self::assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $r?->code);
    }

    public function testQ1CrossPeriodSessionIsAttributedToThePreviousPeriod(): void
    {
        $plan = self::plan(['limit_type' => 'Time_Limit', 'time_limit' => 2, 'time_unit' => 'Mins']);
        $customer = ['username' => 'w9q1-' . bin2hex(random_bytes(3)), 'password' => 'Q1-pass-1'];
        $key = $this->provision($customer, $plan, new \DateTimeImmutable('-30 minutes'));
        $session = bin2hex(random_bytes(6));
        self::acct($key, 1, $session, [['Event-Timestamp', time() - 600]]);                       // started 10 min ago
        self::acct($key, 3, $session, [['Event-Timestamp', time() - 300], ['Acct-Session-Time', 300]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($key, 'Q1-pass-1')?->code, 'positive control: 5 of 2 minutes used');
        // renewal: a new period starts now while the session keeps running and keeps reporting usage
        $this->provision($customer, $plan, new \DateTimeImmutable('now'));
        self::acct($key, 3, $session, [['Event-Timestamp', time()], ['Acct-Session-Time', 900]]);    // +10 min, partly after the renewal
        $r = RadiusClient::access($key, 'Q1-pass-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code, 'Q1 fact: the running cross-period session is not counted in the new period');
        $this->assertSame(120, (int) $r->get('Session-Timeout'), 'Q1 fact: the whole quota is available again');
        $this->assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM radacct WHERE username = " . self::$pdo->quote($key))->fetchColumn(), 'Q4: history kept');
    }

    public function testQ2TimeQuotaEndsTheSessionButDataQuotaHasNoInSessionLimit(): void
    {
        $time = self::plan(['limit_type' => 'Time_Limit', 'time_limit' => 2, 'time_unit' => 'Mins']);
        $tk = $this->provision(['username' => 'w9q2t-' . bin2hex(random_bytes(3)), 'password' => 'Q2-pass-1'], $time, new \DateTimeImmutable('-10 minutes'));
        $r = RadiusClient::access($tk, 'Q2-pass-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame(120, (int) $r->get('Session-Timeout'), 'Q2 time: the NAS ends the session at the remaining quota');

        $data = self::plan(['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'MB']);
        $dk = $this->provision(['username' => 'w9q2d-' . bin2hex(random_bytes(3)), 'password' => 'Q2-pass-2'], $data, new \DateTimeImmutable('-10 minutes'));
        $r = RadiusClient::access($dk, 'Q2-pass-2');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertNull($r->get('Mikrotik-Total-Limit'), 'Q2 data fact: no in-session data limit is sent');
        $this->assertNull($r->get('Mikrotik-Total-Limit-Gigawords'), 'Q2 data fact: no in-session data limit is sent');
        // the quota is enforced only at the next authentication
        $s = bin2hex(random_bytes(6));
        self::acct($dk, 1, $s, [['Event-Timestamp', time() - 120]]);
        self::acct($dk, 3, $s, [['Event-Timestamp', time()], ['Acct-Session-Time', 120], ['Acct-Input-Octets', 3000000], ['Acct-Output-Octets', 3000000]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($dk, 'Q2-pass-2')?->code, 'Q2 data: refused at the next authentication');
    }
}
