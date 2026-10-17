<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\AccountingService;
use Reseller\Radius\PeriodBoundary;
use Reseller\Radius\QuotaEnforcer;
use Reseller\Radius\RadiusPacket;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RadiusClient;
use Reseller\Tests\Support\TestDatabase;

require_once dirname(__DIR__, 2) . '/Support/Wp8Radius.php';

/**
 * N2 (owner CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED): a session belongs to a billing period by the ORDER IN WHICH ITS
 * ACCOUNTING ROW ARRIVED (radacct.radacctid, server side), not by the NAS clock. The boundary is MAX(radacctid) when the
 * period starts (Nuxr-Period-Acct-Id); the time boundary (period start - 10 s) stays the fallback for rows without it.
 * On the real FreeRADIUS 3.2.8 harness (counters, in-session share) and the enforcer:
 *  - NAS clock skew of -300 .. +300 s changes nothing: the first session after a renewal is counted, a session that
 *    started seconds before the renewal is NOT (no over-count, which the 10 s time allowance had);
 *  - a late Interim / duplicate Start / delayed Start cannot move a row between periods (the row id decides);
 *  - a re-provision of the SAME period (password, rename, re-sync) keeps the boundary: usage is never reset; a new period
 *    (renewal) moves it; a period provisioned before this change (no id) keeps the time rule until it ends;
 *  - an id the table cannot have produced (restore of an older accounting table) falls back to the time rule; a malformed
 *    id fails closed at login.
 */
final class V1PeriodOwnershipTest extends TestCase
{
    private const DB = 'nuxr_radius';
    private const BW = ['rate_up' => '5', 'rate_up_unit' => 'Mbps', 'rate_down' => '20', 'rate_down_unit' => 'Mbps', 'burst' => ''];
    private const PLAN = ['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'GB', 'shared_users' => 4];
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

    /** the period starts now: the policy exactly as the application builds and applies it (boundary resolved against the table) */
    private function provision(string $user, string $pw, \DateTimeImmutable $period, array $plan = self::PLAN): void
    {
        $repo = new RadiusRepository(self::$pdo);
        $policy = RadiusPolicyBuilder::build(['username' => $user, 'password' => $pw], $plan + ['id' => random_int(1000, 999999), 'type' => 'Hotspot', 'typebp' => 'Limited', 'pool' => ''],
            self::BW, new \DateTimeImmutable('+1 day'), $period, PeriodBoundary::resolver($repo, new AccountingService(self::$pdo)));
        $r = (new RadiusProvisioner($repo))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
    }

    private function user(): string
    {
        return 'v1p-' . bin2hex(random_bytes(4));
    }

    private static function acct(string $user, int $status, string $session, array $extra = []): void
    {
        $r = RadiusClient::accounting([['User-Name', $user], ['Acct-Status-Type', $status], ['Acct-Session-Id', $session], ['NAS-Port', 9], ['Framed-IP-Address', '10.30.0.9'], ...$extra]);
        self::assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $r?->code, 'accounting answered');
    }

    /** a session (Start + one Interim, left open) with $out bytes whose NAS-stamped start is $startAt (the NAS clock) */
    private static function session(string $user, int $startAt, int $out, ?string $sid = null): string
    {
        $sid ??= bin2hex(random_bytes(6));
        self::acct($user, 1, $sid, [['Event-Timestamp', $startAt]]);
        self::acct($user, 3, $sid, [['Event-Timestamp', $startAt + 5], ['Acct-Session-Time', 5], ['Acct-Output-Octets', $out]]);
        return $sid;
    }

    /** the first limit FreeRADIUS replies at login = the remaining quota / 4 shared sessions (null when refused) */
    private static function share(string $user, string $pw): ?int
    {
        $r = RadiusClient::access($user, $pw);
        return $r?->code === RadiusPacket::ACCESS_ACCEPT ? (int) $r->get('Mikrotik-Total-Limit') + ((int) $r->get('Mikrotik-Total-Limit-Gigawords') << 32) : null;
    }

    private static function check(string $user): array
    {
        $out = [];
        $st = self::$pdo->prepare('SELECT attribute, value FROM radcheck WHERE username = ?');
        $st->execute([$user]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['attribute']] = (string) $r['value'];
        }
        return $out;
    }

    public function testClockSkewNeverMovesASessionBetweenPeriods(): void
    {
        foreach ([-300, -30, -5, 0, 5, 30, 300] as $skew) {
            $u = $this->user();
            $this->provision($u, 'Pw-a', new \DateTimeImmutable('-1 hour'));
            // an old-period session that started 3 s before the renewal, 400 MB; its Start arrived BEFORE the renewal
            self::session($u, time() - 3 + $skew, 400_000_000);
            // the renewal: a new period starts now; the stored boundary moves to the table's current end
            $this->provision($u, 'Pw-a', new \DateTimeImmutable('now'));
            $this->assertArrayHasKey('Nuxr-Period-Acct-Id', self::check($u), "skew {$skew}");
            $this->assertSame(250_000_000, self::share($u, 'Pw-a'), "skew {$skew}: the session started 3 s before the renewal is NOT counted in the new period");
            // the first session of the new period (its Start arrives after the renewal), 300 MB, stamped by a NAS whose clock is off
            self::session($u, time() + $skew, 300_000_000);
            $this->assertSame(175_000_000, self::share($u, 'Pw-a'), "skew {$skew}: the first new-period session is counted whatever the NAS clock says");
        }
    }

    /**
     * Characterisation of the FALLBACK (a period without a boundary id): the time rule with its 10 s allowance counts a
     * session that started up to 10 s BEFORE the renewal in the new period (the answer to "can the allowance attribute
     * old consumption to the new period": yes, only on this fallback, and only for sessions opened in that window).
     */
    public function testTheTimeRuleAloneOverCountsASessionStartedJustBeforeTheRenewal(): void
    {
        $u = $this->user();
        $this->provision($u, 'Pw-g', new \DateTimeImmutable('-1 hour'));
        self::session($u, time() - 3, 400_000_000);   // the old period, 3 s before the renewal
        $policy = RadiusPolicyBuilder::build(['username' => $u, 'password' => 'Pw-g'], self::PLAN + ['id' => 4242, 'type' => 'Hotspot', 'typebp' => 'Limited', 'pool' => ''],
            self::BW, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('now'));   // no boundary resolver: the time rule only
        $this->assertSame('SUCCESS', (new RadiusProvisioner(new RadiusRepository(self::$pdo)))->provision($policy)->status);
        $this->assertArrayNotHasKey('Nuxr-Period-Acct-Id', self::check($u));
        $this->assertSame(150_000_000, self::share($u, 'Pw-g'), 'the fallback counts the 400 MB of the session opened 3 s before the renewal');
    }

    public function testLateInterimAndDuplicateStartNeverMoveARow(): void
    {
        $u = $this->user();
        $this->provision($u, 'Pw-b', new \DateTimeImmutable('-1 hour'));
        $old = bin2hex(random_bytes(6));
        self::acct($u, 1, $old, [['Event-Timestamp', time() - 100]]);
        self::acct($u, 3, $old, [['Event-Timestamp', time() - 90], ['Acct-Session-Time', 10], ['Acct-Output-Octets', 100_000_000]]);
        $this->provision($u, 'Pw-b', new \DateTimeImmutable('now'));   // renewal while the old session is still open
        $boundary = (int) self::check($u)['Nuxr-Period-Acct-Id'];
        // the old session keeps reporting after the renewal (late Interim), is retransmitted (duplicate Start), then stops
        self::acct($u, 1, $old, [['Event-Timestamp', time() - 100]]);
        self::acct($u, 3, $old, [['Event-Timestamp', time() + 60], ['Acct-Session-Time', 160], ['Acct-Output-Octets', 500_000_000]]);
        self::acct($u, 2, $old, [['Event-Timestamp', time() + 61], ['Acct-Session-Time', 161], ['Acct-Output-Octets', 520_000_000]]);
        $rows = self::$pdo->prepare('SELECT radacctid FROM radacct WHERE username = ? AND acctsessionid = ?');
        $rows->execute([$u, $old]);
        $ids = array_map('intval', array_column($rows->fetchAll(\PDO::FETCH_ASSOC), 'radacctid'));
        $this->assertCount(1, $ids, 'one row per session');
        $this->assertLessThanOrEqual($boundary, $ids[0], 'the row keeps its place: it belongs to the period it was opened in');
        $this->assertSame(250_000_000, self::share($u, 'Pw-b'), 'its later traffic (520 MB) is not counted in the new period');
    }

    public function testReprovisionOfTheSamePeriodKeepsTheBoundaryAndARenewalMovesIt(): void
    {
        $u = $this->user();
        $start = new \DateTimeImmutable('-1 hour');
        $this->provision($u, 'Pw-c', $start);
        self::session($u, time() - 600, 100_000_000);
        $first = self::check($u);
        $this->assertArrayHasKey('Nuxr-Period-Acct-Id', $first);
        // traffic after the boundary, then a password change (re-provision of the same period): nothing is reset
        self::session($u, time() - 300, 200_000_000);
        $before = self::share($u, 'Pw-c');
        $this->provision($u, 'Pw-c2', $start);
        $same = self::check($u);
        $this->assertSame($first['Nuxr-Period-Acct-Id'], $same['Nuxr-Period-Acct-Id'], 'the same period keeps its boundary');
        $this->assertSame($before, self::share($u, 'Pw-c2'), 'usage is not reset by a re-provision');
        // a renewal: the boundary moves to the table's current end
        $this->provision($u, 'Pw-c2', new \DateTimeImmutable('now'));
        $this->assertGreaterThan((int) $first['Nuxr-Period-Acct-Id'], (int) self::check($u)['Nuxr-Period-Acct-Id']);
        $this->assertSame(250_000_000, self::share($u, 'Pw-c2'), 'new period: full quota');
    }

    public function testAPeriodProvisionedBeforeThisChangeKeepsTheTimeRule(): void
    {
        $u = $this->user();
        $start = new \DateTimeImmutable('-1 hour');
        $this->provision($u, 'Pw-d', $start);
        self::$pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Nuxr-Period-Acct-Id'")->execute([$u]);   // rows as written before V1.1
        self::session($u, time() - 600, 400_000_000);
        $this->provision($u, 'Pw-d2', $start);   // a password change after the upgrade
        $this->assertArrayNotHasKey('Nuxr-Period-Acct-Id', self::check($u), 'an existing period is not given a boundary that would reset its usage');
        $this->assertSame(150_000_000, self::share($u, 'Pw-d2'), 'the 400 MB of the running period are still counted (time rule)');
    }

    public function testAnImpossibleIdFallsBackToTheTimeRuleAndAMalformedIdFailsClosed(): void
    {
        $u = $this->user();
        $this->provision($u, 'Pw-e', new \DateTimeImmutable('-1 hour'));
        self::session($u, time() - 600, 400_000_000);
        // an id beyond the table's end (an accounting table restored from an older backup): the time rule applies
        self::$pdo->prepare("UPDATE radcheck SET value = '9000000000' WHERE username = ? AND attribute = 'Nuxr-Period-Acct-Id'")->execute([$u]);
        $this->assertSame(150_000_000, self::share($u, 'Pw-e'), 'impossible id: the time rule counts the running period');
        $enf = (new QuotaEnforcer(self::$pdo, static fn(array $r): array => []));
        $this->assertSame([], $enf->overQuota(), 'and the enforcer agrees (400 MB of 1 GB: not over quota)');
        // a malformed value is never trusted
        self::$pdo->prepare("UPDATE radcheck SET value = '12x' WHERE username = ? AND attribute = 'Nuxr-Period-Acct-Id'")->execute([$u]);
        $this->assertNull(self::share($u, 'Pw-e'), 'malformed boundary: the login is refused (fail closed)');
    }

    public function testEnforcerUsesTheSameOwnership(): void
    {
        $u = $this->user();
        $this->provision($u, 'Pw-f', new \DateTimeImmutable('-1 hour'), ['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'MB', 'shared_users' => 4]);
        // an open session of the previous period (row before the boundary), 5 MB, started 3 s before the renewal
        $old = bin2hex(random_bytes(6));
        self::acct($u, 1, $old, [['Event-Timestamp', time() - 3]]);
        self::acct($u, 3, $old, [['Event-Timestamp', time()], ['Acct-Session-Time', 3], ['Acct-Output-Octets', 5_000_000]]);
        $this->provision($u, 'Pw-f', new \DateTimeImmutable('now'), ['limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'MB', 'shared_users' => 4]);
        $enf = new QuotaEnforcer(self::$pdo, static fn(array $r): array => []);
        $this->assertSame([], $enf->overQuota(), 'the previous-period session is not enforced against the new quota');
        // the first session of the new period, 2 MB, open, stamped 60 s early by a lagging NAS clock
        $new = bin2hex(random_bytes(6));
        self::acct($u, 1, $new, [['Event-Timestamp', time() - 60]]);
        self::acct($u, 3, $new, [['Event-Timestamp', time() - 55], ['Acct-Session-Time', 5], ['Acct-Output-Octets', 2_000_000]]);
        $over = $enf->overQuota();
        $this->assertCount(1, $over);
        $this->assertSame('2000000', $over[0]['usage'], 'only the new-period session is counted');
    }
}
