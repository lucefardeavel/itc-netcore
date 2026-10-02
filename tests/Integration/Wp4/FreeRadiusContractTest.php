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
use Reseller\Tests\Support\TestDatabase;

/**
 * RADIUS contract (06_RADIUS_CONTRACT verification list; owner decisions C16 / Q1 / Q2): the policies written by
 * the WP4 provisioner are evaluated by a real FreeRADIUS 3.2.8 with the project configuration
 * (install/freeradius) on the same database engine. Requests are real RADIUS packets over UDP.
 *
 * PPPoE and Hotspot authentication (PAP and CHAP), rate policy (user and plan group), dynamic pool, static
 * IPv4, simultaneous use, expiration, time and data quotas per billing period with the accounting history
 * retained (C17).
 */
final class FreeRadiusContractTest extends TestCase
{
    private const DB = 'nuxr_radius';
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

    private function provisioner(): RadiusProvisioner
    {
        return new RadiusProvisioner(new RadiusRepository(self::$pdo));
    }

    private static function user(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4));
    }

    private function provision(array $customer, array $plan, ?array $bw, ?\DateTimeImmutable $expiry, ?\DateTimeImmutable $period = null): string
    {
        $policy = RadiusPolicyBuilder::build($customer, $plan, $bw, $expiry, $period);
        $r = $this->provisioner()->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    private static function plan(string $type, array $extra = []): array
    {
        return $extra + ['id' => random_int(1000, 999999), 'type' => $type, 'typebp' => 'Unlimited', 'shared_users' => 1, 'pool' => ''];
    }

    private const BW = ['rate_up' => '2', 'rate_up_unit' => 'Mbps', 'rate_down' => '4', 'rate_down_unit' => 'Mbps', 'burst' => ''];

    /** @param list<array{0:string,1:string|int}> $extra */
    private static function acct(string $username, int $status, string $session, array $extra = []): void
    {
        $r = RadiusClient::accounting([['User-Name', $username], ['Acct-Status-Type', $status], ['Acct-Session-Id', $session],
            ['NAS-Port', 7], ['Framed-IP-Address', '10.20.0.7'], ...$extra]);
        self::assertNotNull($r, 'accounting answered');
        self::assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $r->code);
    }

    private static function sessions(string $username): int
    {
        $st = self::$pdo->prepare('SELECT COUNT(*) FROM radacct WHERE username = ?');
        $st->execute([$username]);
        return (int) $st->fetchColumn();
    }

    public function testPppoePapAndChapWithStaticAddressAndRate(): void
    {
        $login = self::user('ppp');
        $customer = ['username' => self::user('acct'), 'password' => 'acct-pass', 'pppoe_username' => $login, 'pppoe_password' => 'Pp-secret-1', 'pppoe_ip' => '10.10.0.5'];
        $key = $this->provision($customer, self::plan('PPPOE', ['pool' => 'pool-a']), self::BW, new \DateTimeImmutable('+1 day'));
        $this->assertSame($login, $key, 'PPPoE key is pppoe_username (Q7)');

        $pap = RadiusClient::access($login, 'Pp-secret-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $pap?->code, 'PAP accept');
        $this->assertSame('10.10.0.5', $pap->get('Framed-IP-Address'), 'static IPv4');
        $this->assertNull($pap->get('Framed-Pool'), 'no pool with a static address');
        $this->assertSame('2M/4M', $pap->get('Mikrotik-Rate-Limit'));
        $this->assertGreaterThan(0, (int) $pap->get('Session-Timeout'));
        $this->assertLessThanOrEqual(86400, (int) $pap->get('Session-Timeout'), 'Session-Timeout up to the expiration');

        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, RadiusClient::access($login, 'Pp-secret-1', true)?->code, 'CHAP accept');
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($login, 'wrong')?->code, 'PAP wrong password');
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($login, 'wrong', true)?->code, 'CHAP wrong password');
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($customer['username'], 'Pp-secret-1')?->code, 'the account username is not the PPPoE key');
    }

    public function testPppoeDynamicPool(): void
    {
        $customer = ['username' => self::user('pool'), 'password' => 'Pool-pass-1', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''];
        $key = $this->provision($customer, self::plan('PPPOE', ['pool' => 'pool-b']), self::BW, new \DateTimeImmutable('+1 day'));
        $r = RadiusClient::access($key, 'Pool-pass-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame('pool-b', $r->get('Framed-Pool'), 'dynamic pool');
        $this->assertNull($r->get('Framed-IP-Address'), 'no 0.0.0.0 placeholder');
    }

    public function testHotspotGroupRateAndSimultaneousUse(): void
    {
        $plan = self::plan('Hotspot', ['shared_users' => 1]);
        $this->assertSame('SUCCESS', $this->provisioner()->applyPlan($plan['id'], ['rate_up' => '512', 'rate_up_unit' => 'Kbps', 'rate_down' => '1', 'rate_down_unit' => 'Mbps', 'burst' => ''])->status);
        $customer = ['username' => self::user('hs'), 'password' => 'Hs-pass-1'];
        $key = $this->provision($customer, $plan, null, new \DateTimeImmutable('+2 hours'));
        $r = RadiusClient::access($key, 'Hs-pass-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame('512K/1M', $r->get('Mikrotik-Rate-Limit'), 'plan group rate (radgroupreply)');
        $this->assertSame(1, preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', (string) $r->get('WISPr-Session-Terminate-Time')));

        $session = bin2hex(random_bytes(6));
        self::acct($key, 1, $session, [['Event-Timestamp', time()]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($key, 'Hs-pass-1')?->code, 'Simultaneous-Use 1 with an open session');
        self::acct($key, 2, $session, [['Event-Timestamp', time()], ['Acct-Session-Time', 5]]);
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, RadiusClient::access($key, 'Hs-pass-1')?->code, 'session closed');
        $this->assertSame(1, self::sessions($key), 'accounting history written by FreeRADIUS');
    }

    public function testExpiration(): void
    {
        $customer = ['username' => self::user('exp'), 'password' => 'Exp-pass-1'];
        $key = $this->provision($customer, self::plan('Hotspot'), self::BW, new \DateTimeImmutable('-1 minute'));
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($key, 'Exp-pass-1')?->code, 'expired');
        $this->provision($customer, self::plan('Hotspot'), self::BW, new \DateTimeImmutable('+10 minutes'));
        $r = RadiusClient::access($key, 'Exp-pass-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code, 'renewed');
        $this->assertLessThanOrEqual(600, (int) $r->get('Session-Timeout'));
    }

    public function testTimeQuotaPerPeriodKeepsHistory(): void
    {
        $plan = self::plan('Hotspot', ['typebp' => 'Limited', 'limit_type' => 'Time_Limit', 'time_limit' => 1, 'time_unit' => 'Mins']);
        $customer = ['username' => self::user('tq'), 'password' => 'Tq-pass-1'];
        $key = $this->provision($customer, $plan, self::BW, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('-10 minutes'));
        $first = RadiusClient::access($key, 'Tq-pass-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $first?->code);
        $this->assertLessThanOrEqual(60, (int) $first->get('Session-Timeout'), 'remaining time quota');

        $session = bin2hex(random_bytes(6));
        self::acct($key, 1, $session, [['Event-Timestamp', time() - 300]]);
        self::acct($key, 2, $session, [['Event-Timestamp', time() - 200], ['Acct-Session-Time', 61]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($key, 'Tq-pass-1')?->code, 'time quota used');

        // renewal: a new billing period starts; the old session is kept but no longer counted
        $this->provision($customer, $plan, self::BW, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('now'));
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, RadiusClient::access($key, 'Tq-pass-1')?->code, 'fresh quota after renewal');
        $this->assertSame(1, self::sessions($key), 'accounting history retained (never deleted to reset a quota)');
    }

    public function testDataQuotaPerPeriodKeepsHistory(): void
    {
        $plan = self::plan('Hotspot', ['typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'MB']);
        $customer = ['username' => self::user('dq'), 'password' => 'Dq-pass-1'];
        $key = $this->provision($customer, $plan, self::BW, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('-10 minutes'));
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, RadiusClient::access($key, 'Dq-pass-1')?->code);
        $session = bin2hex(random_bytes(6));
        self::acct($key, 1, $session, [['Event-Timestamp', time() - 300]]);
        self::acct($key, 2, $session, [['Event-Timestamp', time() - 200], ['Acct-Session-Time', 30], ['Acct-Input-Octets', 600000], ['Acct-Output-Octets', 600000]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($key, 'Dq-pass-1')?->code, 'data quota used');
        $this->provision($customer, $plan, self::BW, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('now'));
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, RadiusClient::access($key, 'Dq-pass-1')?->code, 'fresh data quota after renewal');
        $this->assertSame(1, self::sessions($key));
    }

    public function testSuspendedAndRemovedSubscribersAreRejectedAndHistoryKept(): void
    {
        $customer = ['username' => self::user('sus'), 'password' => 'Sus-pass-1'];
        $key = $this->provision($customer, self::plan('Hotspot', ['shared_users' => 2]), self::BW, new \DateTimeImmutable('+1 day'));
        $session = bin2hex(random_bytes(6));
        self::acct($key, 1, $session, [['Event-Timestamp', time()]]);
        self::acct($key, 2, $session, [['Event-Timestamp', time()], ['Acct-Session-Time', 3]]);
        $this->assertSame('SUCCESS', $this->provisioner()->suspend($key)->status);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($key, 'Sus-pass-1')?->code, 'suspended');
        $this->assertSame('SUCCESS', $this->provisioner()->remove($key)->status);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($key, 'Sus-pass-1')?->code, 'removed');
        $this->assertSame(1, self::sessions($key), 'accounting history kept after suspend and remove');
    }
}
