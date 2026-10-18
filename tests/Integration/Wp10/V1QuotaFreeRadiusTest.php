<?php
namespace Reseller\Tests\Integration\Wp10;

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
 * V1 quota contract on the real FreeRADIUS 3.2.8 harness (owner QUOTA_V1_GB_SUPPORT_APPROVED; lock gates Q1 / Q2):
 *  - Q2: the Access-Accept of a data-quota subscriber carries the in-session limit the NAS enforces - the per-session
 *    share of the remaining period quota (remaining / Simultaneous-Use) as Mikrotik-Total-Limit (Hotspot) and
 *    Mikrotik-Recv-Limit / Mikrotik-Xmit-Limit (PPPoE: RouterOS enforces only these, per direction), each with its
 *    Gigawords part (> 4 GiB), and Acct-Interim-Interval = 30; a share of 0 (RouterOS: unlimited) is never sent:
 *    the request is rejected; an exhausted quota is rejected;
 *  - accounting integrity: a stale Interim-Update (smaller counters, or after the Stop) never lowers the stored
 *    usage; 64-bit counters (Gigawords) are counted;
 *  - Q1: usage is attributed to the period its session started in (a session crossing a renewal is not counted in
 *    the new period; the renewal ends it - Option B, tested at the activation level); history is never deleted.
 */
final class V1QuotaFreeRadiusTest extends TestCase
{
    private const DB = 'nuxr_radius';
    private const BW = ['rate_up' => '5', 'rate_up_unit' => 'Mbps', 'rate_down' => '20', 'rate_down_unit' => 'Mbps', 'burst' => ''];
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

    private function provision(array $plan, string $pw, \DateTimeImmutable $period, ?string $user = null): string
    {
        $customer = ['username' => $user ?? 'v1q-' . bin2hex(random_bytes(4)), 'password' => $pw];
        $policy = RadiusPolicyBuilder::build($customer, $plan + ['id' => random_int(1000, 999999), 'type' => 'Hotspot', 'typebp' => 'Limited', 'shared_users' => 1, 'pool' => ''],
            self::BW, new \DateTimeImmutable('+1 day'), $period);
        $r = (new RadiusProvisioner(new RadiusRepository(self::$pdo)))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    private static function acct(string $user, int $status, string $session, array $extra = [], string $nas = '127.0.0.1'): void
    {
        $r = RadiusClient::accounting([['User-Name', $user], ['Acct-Status-Type', $status], ['Acct-Session-Id', $session], ['NAS-Port', 9], ['Framed-IP-Address', '10.30.0.9'], ...$extra], $nas);
        self::assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $r?->code, 'accounting answered');
    }

    private static function stored(string $user, string $session): array
    {
        $st = self::$pdo->prepare('SELECT acctinputoctets, acctoutputoctets, acctstoptime FROM radacct WHERE username = ? AND acctsessionid = ?');
        $st->execute([$user, $session]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        // the driver returns integers or strings depending on the engine / emulation: compare as strings
        return array_map(static fn($v) => $v === null ? null : (string) $v, $row);
    }

    /** [Total, Recv, Xmit] limits of an Access-Accept as 64-bit values (Gigawords << 32 | limit) */
    private static function limits(RadiusPacket $r): array
    {
        $v = static fn(string $a) => ((int) $r->get($a . '-Gigawords') << 32) | (int) $r->get($a);
        return [$v('Mikrotik-Total-Limit'), $v('Mikrotik-Recv-Limit'), $v('Mikrotik-Xmit-Limit')];
    }

    public function testInSessionLimitIsTheRemainingShare(): void
    {
        // 4 shared sessions (the open session below would otherwise refuse the second login: Simultaneous-Use)
        $plan = ['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'GB', 'shared_users' => 4];
        $u = $this->provision($plan, 'Pw-1', new \DateTimeImmutable('-1 hour'));
        $r = RadiusClient::access($u, 'Pw-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame([250_000_000, 250_000_000, 250_000_000], self::limits($r), 'fresh period: the whole quota / 4 sessions, every limit');
        $this->assertSame(30, (int) $r->get('Acct-Interim-Interval'));
        $s = bin2hex(random_bytes(6));
        self::acct($u, 1, $s, [['Event-Timestamp', time() - 300]]);
        self::acct($u, 3, $s, [['Event-Timestamp', time() - 60], ['Acct-Session-Time', 240], ['Acct-Input-Octets', 100_000_000], ['Acct-Output-Octets', 300_000_000]]);
        $r = RadiusClient::access($u, 'Pw-1');
        $this->assertSame([150_000_000, 150_000_000, 150_000_000], self::limits($r), '(quota - period usage incl. the open session) / 4');
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM radacct WHERE username = ' . self::$pdo->quote($u))->fetchColumn(), 'history kept');
    }

    public function testGigawordsAndSharedUsers(): void
    {
        $u = $this->provision(['limit_type' => 'Data_Limit', 'data_limit' => 10, 'data_unit' => 'GB'], 'Pw-2', new \DateTimeImmutable('-1 hour'));
        $r = RadiusClient::access($u, 'Pw-2');
        $this->assertSame(2, (int) $r->get('Mikrotik-Total-Limit-Gigawords'), '10 GB = 2 x 2^32 + 1 410 065 408');
        $this->assertSame(1_410_065_408, (int) $r->get('Mikrotik-Total-Limit'));
        $this->assertSame([10_000_000_000, 10_000_000_000, 10_000_000_000], self::limits($r));

        $shared = $this->provision(['limit_type' => 'Data_Limit', 'data_limit' => 900, 'data_unit' => 'MB', 'shared_users' => 3], 'Pw-3', new \DateTimeImmutable('-1 hour'));
        $this->assertSame([300_000_000, 300_000_000, 300_000_000], self::limits(RadiusClient::access($shared, 'Pw-3')), 'Hotspot shared users: every session gets 1/n of the remaining quota');
    }

    public function testExhaustedAndZeroShareAreRejected(): void
    {
        $u = $this->provision(['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'MB'], 'Pw-4', new \DateTimeImmutable('-1 hour'));
        $s = bin2hex(random_bytes(6));
        self::acct($u, 1, $s, [['Event-Timestamp', time() - 200]]);
        self::acct($u, 2, $s, [['Event-Timestamp', time() - 100], ['Acct-Session-Time', 100], ['Acct-Input-Octets', 600_000], ['Acct-Output-Octets', 400_000]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($u, 'Pw-4')?->code, 'quota used up');

        // 2 bytes left for 3 shared sessions -> share 0: RouterOS would read 0 as "no limit" - rejected instead
        $z = $this->provision(['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'MB', 'shared_users' => 3], 'Pw-5', new \DateTimeImmutable('-1 hour'));
        $s = bin2hex(random_bytes(6));
        self::acct($z, 1, $s, [['Event-Timestamp', time() - 200]]);
        self::acct($z, 2, $s, [['Event-Timestamp', time() - 100], ['Acct-Session-Time', 100], ['Acct-Input-Octets', 999_998]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($z, 'Pw-5')?->code, 'zero share is never sent as a limit');

        // positive control: an unlimited subscriber has no limit attribute at all
        $free = $this->provision(['typebp' => 'Unlimited'], 'Pw-6', new \DateTimeImmutable('-1 hour'));
        $r = RadiusClient::access($free, 'Pw-6');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame([null, null, null, null], [$r->get('Mikrotik-Total-Limit'), $r->get('Mikrotik-Recv-Limit'), $r->get('Mikrotik-Xmit-Limit'), $r->get('Acct-Interim-Interval')]);
    }

    public function testStaleInterimNeverLowersUsageAndGigawordsCount(): void
    {
        $u = $this->provision(['limit_type' => 'Data_Limit', 'data_limit' => 20, 'data_unit' => 'GB'], 'Pw-7', new \DateTimeImmutable('-1 hour'));
        $s = bin2hex(random_bytes(6));
        self::acct($u, 1, $s, [['Event-Timestamp', time() - 400]]);
        self::acct($u, 3, $s, [['Event-Timestamp', time() - 200], ['Acct-Session-Time', 200], ['Acct-Input-Octets', 5000], ['Acct-Output-Octets', 9000], ['Acct-Output-Gigawords', 1]]);
        $this->assertSame(['5000', (string) ((1 << 32) + 9000)], array_values(array_slice(self::stored($u, $s), 0, 2)), '64-bit output counter');
        // an older Interim-Update arriving late (re-ordered / retransmitted): smaller counters are not written
        self::acct($u, 3, $s, [['Event-Timestamp', time() - 300], ['Acct-Session-Time', 100], ['Acct-Input-Octets', 1000], ['Acct-Output-Octets', 2000]]);
        $this->assertSame(['5000', (string) ((1 << 32) + 9000)], array_values(array_slice(self::stored($u, $s), 0, 2)), 'a stale interim never lowers the usage');
        self::acct($u, 2, $s, [['Event-Timestamp', time() - 100], ['Acct-Session-Time', 300], ['Acct-Input-Octets', 6000], ['Acct-Output-Octets', 9500], ['Acct-Output-Gigawords', 1]]);
        // an interim after the Stop changes nothing
        self::acct($u, 3, $s, [['Event-Timestamp', time() - 150], ['Acct-Session-Time', 250], ['Acct-Input-Octets', 5500], ['Acct-Output-Octets', 9200], ['Acct-Output-Gigawords', 1]]);
        $row = self::stored($u, $s);
        $this->assertSame(['6000', (string) ((1 << 32) + 9500)], [$row['acctinputoctets'], $row['acctoutputoctets']], 'the Stop counters are final');
        $this->assertNotNull($row['acctstoptime'], 'the session stays stopped');
        $r = RadiusClient::access($u, 'Pw-7');
        $this->assertSame([20_000_000_000 - 6000 - (1 << 32) - 9500], [self::limits($r)[0]], 'the 64-bit usage is counted against the quota');
    }

    public function testQ1CrossPeriodSessionStaysInItsOwnPeriod(): void
    {
        $plan = ['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'GB', 'shared_users' => 4];
        $u = $this->provision($plan, 'Pw-8', new \DateTimeImmutable('-2 hours'));
        $s = bin2hex(random_bytes(6));
        self::acct($u, 1, $s, [['Event-Timestamp', time() - 3600]]);
        self::acct($u, 3, $s, [['Event-Timestamp', time() - 1800], ['Acct-Session-Time', 1800], ['Acct-Output-Octets', 700_000_000]]);
        $this->assertSame(75_000_000, self::limits(RadiusClient::access($u, 'Pw-8'))[0], 'old period: 700 MB used, 300 MB / 4');
        // renewal: the new period starts now; the old session belongs to the old period (and is ended by Option B)
        $this->provision($plan, 'Pw-8', new \DateTimeImmutable('now'), $u);
        $this->assertSame(250_000_000, self::limits(RadiusClient::access($u, 'Pw-8'))[0], 'new period: the full quota / 4, old usage not mixed in');
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM radacct WHERE username = ' . self::$pdo->quote($u))->fetchColumn(), 'history kept');
    }

    /**
     * The lab NAS stamps Accounting-Start up to ~2 s before the host's clock (measured 1.1 - 1.7 s). The session of a
     * subscriber who logs in right after the renewal must count against the NEW period; a session that really started
     * before the renewal must not.
     */
    public function testSessionStampedJustBeforeThePeriodStartIsCountedInTheNewPeriod(): void
    {
        $plan = ['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'GB', 'shared_users' => 4];
        $u = $this->provision($plan, 'Pw-12', new \DateTimeImmutable('now'));
        $near = bin2hex(random_bytes(6));
        self::acct($u, 1, $near, [['Event-Timestamp', time() - 2]]);
        self::acct($u, 3, $near, [['Event-Timestamp', time()], ['Acct-Session-Time', 2], ['Acct-Output-Octets', 400_000_000]]);
        $old = bin2hex(random_bytes(6));
        self::acct($u, 1, $old, [['Event-Timestamp', time() - 3600]]);
        self::acct($u, 3, $old, [['Event-Timestamp', time() - 1800], ['Acct-Session-Time', 1800], ['Acct-Output-Octets', 300_000_000]]);
        $this->assertSame(150_000_000, self::limits(RadiusClient::access($u, 'Pw-12'))[0],
            '(1 GB - 400 MB of the session stamped 2 s before the period start) / 4; the real previous-period session (300 MB) is not counted');
    }

    public function testDuplicateWrapMultiNasMalformedAndTimeQuota(): void
    {
        $plan = ['limit_type' => 'Data_Limit', 'data_limit' => 10, 'data_unit' => 'GB', 'shared_users' => 4];
        $u = $this->provision($plan, 'Pw-9', new \DateTimeImmutable('-1 hour'));
        // counter wrap: the 32-bit octets fall while the Gigawords rise - a larger 64-bit value, written
        $s = bin2hex(random_bytes(6));
        self::acct($u, 1, $s, [['Event-Timestamp', time() - 500]]);
        self::acct($u, 3, $s, [['Event-Timestamp', time() - 400], ['Acct-Session-Time', 100], ['Acct-Output-Octets', 4_294_000_000]]);
        self::acct($u, 3, $s, [['Event-Timestamp', time() - 300], ['Acct-Session-Time', 200], ['Acct-Output-Octets', 1_000], ['Acct-Output-Gigawords', 1]]);
        $this->assertSame((string) ((1 << 32) + 1_000), self::stored($u, $s)['acctoutputoctets'], 'a wrapped counter is counted (64-bit)');
        // duplicate Start and duplicate (retransmitted) Stop: one row, final counters, no double count
        self::acct($u, 1, $s, [['Event-Timestamp', time() - 500]]);
        self::acct($u, 2, $s, [['Event-Timestamp', time() - 200], ['Acct-Session-Time', 300], ['Acct-Output-Octets', 2_000], ['Acct-Output-Gigawords', 1]]);
        self::acct($u, 2, $s, [['Event-Timestamp', time() - 200], ['Acct-Session-Time', 300], ['Acct-Output-Octets', 2_000], ['Acct-Output-Gigawords', 1]]);
        $this->assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM radacct WHERE acctsessionid = '{$s}'")->fetchColumn(), 'one row per session');
        $this->assertSame((string) ((1 << 32) + 2_000), self::stored($u, $s)['acctoutputoctets']);
        // a second session on ANOTHER NAS counts against the same quota
        $s2 = bin2hex(random_bytes(6));
        self::acct($u, 1, $s2, [['Event-Timestamp', time() - 100]], '10.99.0.2');
        self::acct($u, 3, $s2, [['Event-Timestamp', time() - 50], ['Acct-Session-Time', 50], ['Acct-Input-Octets', 1_000_000]], '10.99.0.2');
        $used = (1 << 32) + 2_000 + 1_000_000;
        $this->assertSame(intdiv(10_000_000_000 - $used, 4), self::limits(RadiusClient::access($u, 'Pw-9'))[0], 'usage of every NAS is summed');

        // a malformed quota row (written outside the application): refused, never served unlimited
        $bad = $this->provision(['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'GB'], 'Pw-10', new \DateTimeImmutable('-1 hour'));
        self::$pdo->prepare("UPDATE radcheck SET value = '1e9' WHERE username = ? AND attribute = 'Max-Data'")->execute([$bad]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($bad, 'Pw-10')?->code, 'malformed Max-Data: fail closed');

        // time quota: Session-Timeout = the remaining time of the period; exhausted -> refused
        $t = $this->provision(['limit_type' => 'Time_Limit', 'time_limit' => 10, 'time_unit' => 'Mins', 'shared_users' => 4], 'Pw-11', new \DateTimeImmutable('-1 hour'));
        $st = bin2hex(random_bytes(6));
        self::acct($t, 1, $st, [['Event-Timestamp', time() - 400]]);
        self::acct($t, 2, $st, [['Event-Timestamp', time() - 160], ['Acct-Session-Time', 240]]);
        $r = RadiusClient::access($t, 'Pw-11');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame(360, (int) $r->get('Session-Timeout'), '600 s - 240 s used');
        $this->assertNull($r->get('Mikrotik-Total-Limit'), 'a time-only plan has no byte limit');
        $st2 = bin2hex(random_bytes(6));
        self::acct($t, 1, $st2, [['Event-Timestamp', time() - 100]]);
        self::acct($t, 2, $st2, [['Event-Timestamp', time() - 10], ['Acct-Session-Time', 360]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($t, 'Pw-11')?->code, 'time quota used up');
    }
}
