<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\AccountingService;
use Reseller\Radius\RadiusPacket;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RadiusClient;
use Reseller\Tests\Support\RadiusWire;
use Reseller\Tests\Support\TestDatabase;
use Reseller\Ui\Subscriber;

require_once dirname(__DIR__, 3) . '/system/autoload/Reseller/Ui/Subscriber.php';

/**
 * WP8 A4 (R-WP8-CARRY-03) accounting time contract, on MariaDB 10.11 and MySQL 8.0 with the real FreeRADIUS 3.2.8 writer
 * of the harness (project configuration, stock accounting queries):
 *
 *   - radacct DATETIMEs are written with FROM_UNIXTIME(Event-Timestamp or %l) in the writer's SQL SESSION zone; the
 *     application reads them as epochs with UNIX_TIMESTAMP() in its own session. The pair is lossless only when both
 *     sessions use the same zone (neither side sets one: the database server zone) and that zone has no DST.
 *   - every comparison and display is done on epochs, so the PHP (application) zone never changes a result;
 *   - a missing / invalid / future time is never a confirmed fresh ("online") session; a writer / reader zone mismatch
 *     shifts every epoch by exactly the zone difference and VOIDS the freshness guarantee (characterised below);
 *   - DST in the database zone is characterised (the repeated hour is ambiguous), UTC has no ambiguity: the contract.
 *
 * Rows "written as FreeRADIUS writes them" use the stock statement form FROM_UNIXTIME(<epoch>) in a session with the
 * given zone. Reads never change radacct (checksums). Named zones need the time zone tables (OD-A4-6: a missing table is
 * a FAILURE, never a skip). Optional evidence: NUXR_A4_EVIDENCE=<dir> appends JSON lines (no secrets).
 */
final class AccountingTimeContractTest extends TestCase
{
    private const DB = 'nuxr_radius';
    private const THRESHOLD = 120;
    private static string $tag;
    private string $zone;
    private mixed $config;

    public static function setUpBeforeClass(): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        FixtureBuilder::connect($host, TestDatabase::rootPassword())
            ->exec('CREATE DATABASE IF NOT EXISTS `' . self::DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        (new FixtureBuilder(dirname(__DIR__, 3)))->loadSqlFile(self::conn(null), dirname(__DIR__, 3) . '/install/radius.sql');
        require_once dirname(__DIR__, 3) . '/system/orm.php';
        RadiusClient::waitReady();
        self::$tag = 'a4' . bin2hex(random_bytes(3));
    }

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        $this->config = $GLOBALS['config'] ?? null;
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        $GLOBALS['config'] = $this->config;
        \ORM::reset_db();
    }

    /** a connection to the RADIUS database; $zone null = no session override (exactly like init.php and FreeRADIUS) */
    private static function conn(?string $zone): \PDO
    {
        $pdo = FixtureBuilder::connect((string) getenv('WP1_DB_HOST'), TestDatabase::rootPassword(), self::DB);
        if ($zone !== null) {
            $pdo->exec('SET time_zone = ' . $pdo->quote($zone));
        }
        return $pdo;
    }

    private static function q(\PDO $pdo, string $sql, array $params = []): array
    {
        $st = $pdo->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : ($v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR));
        }
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function one(\PDO $pdo, string $sql, array $params = []): mixed
    {
        $r = self::q($pdo, $sql, $params);
        return $r === [] ? null : array_values($r[0])[0];
    }

    private static function user(string $what): string
    {
        return self::$tag . '-' . $what . '-' . bin2hex(random_bytes(2));
    }

    private static function utc(string $when): int
    {
        return (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /** an accounting row as FreeRADIUS writes it: FROM_UNIXTIME(epoch) in the writer session ($w and its zone) */
    private static function write(\PDO $w, string $user, ?int $start, ?int $update, ?int $stop = null): int
    {
        self::q($w, 'INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, framedipaddress, acctstarttime, acctupdatetime, acctstoptime)'
            . " VALUES (?, ?, ?, '192.0.2.44', '10.44.0.1', FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?))",
            [bin2hex(random_bytes(4)), bin2hex(random_bytes(16)), $user, $start, $update, $stop]);
        return (int) self::one($w, 'SELECT LAST_INSERT_ID()');
    }

    /** a legacy / damaged row with literal DATETIME text (zero date, before 1970, ...) */
    private static function writeRaw(string $user, ?string $start, ?string $update): int
    {
        $w = self::conn(null);
        $w->exec("SET SESSION sql_mode = ''");
        self::q($w, "INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime, acctupdatetime) VALUES (?, ?, ?, '192.0.2.44', ?, ?)",
            [bin2hex(random_bytes(4)), bin2hex(random_bytes(16)), $user, $start, $update]);
        return (int) self::one($w, 'SELECT LAST_INSERT_ID()');
    }

    private static function checksum(): string
    {
        $rows = array_map('json_encode', self::q(self::conn(null), 'SELECT * FROM radacct ORDER BY radacctid'));
        return hash('sha256', implode("\n", $rows));
    }

    /** @return list<string> the usernames reported stale, restricted to $users */
    private static function stale(\PDO $reader, \DateTimeImmutable $now, array $users, int $threshold = self::THRESHOLD): array
    {
        $out = array_values(array_intersect(array_column((new AccountingService($reader))->staleSessions($now, $threshold), 'username'), $users));
        sort($out);
        return $out;
    }

    /** Subscriber::session() of a one-name customer, reading through $reader as the ORM 'radius' connection */
    private static function session(\PDO $reader, string $user): array
    {
        $GLOBALS['config'] = ['radius_enable' => 1] + (is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : []);
        \ORM::reset_db();
        \ORM::set_db($reader, 'radius');
        return Subscriber::session(['username' => $user, 'pppoe_username' => ''], true);
    }

    private static function label(int $epoch, string $zone): string
    {
        $d = (new \DateTimeImmutable('@' . $epoch))->setTimezone(new \DateTimeZone($zone));
        return $d->format('Y-m-d H:i:s') . ' UTC' . $d->format('P');
    }

    private static function evidence(string $case, array $data): void
    {
        $dir = getenv('NUXR_A4_EVIDENCE');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            $line = json_encode(['t' => gmdate('c'), 'engine' => getenv('WP1_DB_HOST'), 'case' => $case] + $data, JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($dir . '/contract-' . getenv('WP1_DB_HOST') . '.jsonl', $line, FILE_APPEND | LOCK_EX);
        }
    }

    /** real FreeRADIUS accounting (stock queries); $event null = no Event-Timestamp (FreeRADIUS uses its clock, %l) */
    private static function acct(string $user, string $sid, string $status, ?int $event, array $extra = []): void
    {
        $code = ['Start' => 1, 'Stop' => 2, 'Interim-Update' => 3][$status];
        $attrs = [['User-Name', $user], ['NAS-IP-Address', '127.0.0.1'], ['Acct-Status-Type', $code], ['Acct-Delay-Time', 0], ['Acct-Session-Id', $sid],
            ['NAS-Port-Id', 'ether9'], ['NAS-Port-Type', 15], ['Service-Type', 2], ['Framed-Protocol', 1], ['Framed-IP-Address', '10.44.0.9'], ['Calling-Station-Id', '02:A4:00:00:00:09'], ...$extra];
        if ($event !== null) {
            $attrs[] = ['Event-Timestamp', $event];
        }
        $w = RadiusWire::harness();
        $r = $w->send($w->accountingBytes($attrs), 1813);
        self::assertNotNull($r, "{$status} answered");
        self::assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $r->code);
    }

    // ------------------------------------------------------------------------------------------------ preconditions

    public function testTheTimeZoneTablesArePresent(): void
    {
        $pdo = self::conn(null);
        $n = (int) self::one($pdo, 'SELECT COUNT(*) FROM mysql.time_zone_name');
        $this->assertGreaterThan(0, $n, 'OD-A4-6: the DST tests need the named time zones (mysql.time_zone_name is empty); this is a failure, not a skip');
        foreach (['UTC', 'Europe/Berlin', 'America/New_York', 'Asia/Baghdad'] as $z) {
            $pdo->exec('SET time_zone = ' . $pdo->quote($z));
            $this->assertSame($z, self::one($pdo, 'SELECT @@session.time_zone'));
        }
        self::evidence('tz-tables', ['time_zone_names' => $n, 'version' => self::one($pdo, 'SELECT VERSION()')]);
    }

    // -------------------------------------------------------------------------- 1. the writer / reader time reference

    public function testTheFreeRadiusWriterAndTheApplicationReaderShareOneTimeReference(): void
    {
        $app = self::conn(null);                                        // exactly like init.php: no session zone
        $zones = self::q($app, 'SELECT @@global.time_zone AS g, @@session.time_zone AS s, @@system_time_zone AS sys, UNIX_TIMESTAMP() AS db_epoch')[0];
        $user = self::user('rw');
        $sid = bin2hex(random_bytes(4));
        $e = time() - 1000;
        self::acct($user, $sid, 'Start', $e);
        self::acct($user, $sid, 'Interim-Update', $e + 60, [['Acct-Session-Time', 60]]);
        $row = self::q($app, 'SELECT radacctid, acctstarttime, acctupdatetime, acctinterval, UNIX_TIMESTAMP(acctstarttime) AS s, UNIX_TIMESTAMP(acctupdatetime) AS u, FROM_UNIXTIME(?) AS reader_text FROM radacct WHERE username = ?', [$e, $user]);
        $this->assertCount(1, $row);
        $row = $row[0];
        $this->assertSame($row['reader_text'], $row['acctstarttime'], 'the text FreeRADIUS stored for epoch E is the text the application session gives for E: same session zone');
        $this->assertSame($e, (int) $row['s'], 'UNIX_TIMESTAMP in the application session gives back the Event-Timestamp');
        $this->assertSame($e + 60, (int) $row['u']);
        $this->assertSame(60, (int) $row['acctinterval'], 'FreeRADIUS own round trip (epoch - UNIX_TIMESTAMP(old update)) is exact in its session');
        self::acct($user, $sid, 'Stop', $e + 120, [['Acct-Session-Time', 120], ['Acct-Terminate-Cause', 1]]);
        $this->assertSame($e + 120, (int) self::one($app, 'SELECT UNIX_TIMESTAMP(acctstoptime) FROM radacct WHERE username = ?', [$user]));
        // the AccountingService epoch columns
        $hist = (new AccountingService($app))->history($user);
        $this->assertSame([$e, $e + 60, $e + 120], [AccountingService::epoch($hist[0]['acctstart_epoch']), AccountingService::epoch($hist[0]['acctupdate_epoch']), AccountingService::epoch($hist[0]['acctstop_epoch'])]);
        $this->assertSame((string) $row['acctstarttime'], (string) $hist[0]['acctstarttime'], 'existing keys unchanged');

        // without Event-Timestamp FreeRADIUS uses its receive clock (%l): same instant as the application clock
        $u2 = self::user('rw-l');
        $before = time();
        self::acct($u2, bin2hex(random_bytes(4)), 'Start', null);
        $after = time();
        $s2 = (int) self::one($app, 'SELECT UNIX_TIMESTAMP(acctstarttime) FROM radacct WHERE username = ?', [$u2]);
        $this->assertGreaterThanOrEqual($before - 2, $s2);
        $this->assertLessThanOrEqual($after + 2, $s2, '%l and time() agree (one host clock) and the zones agree');
        $this->assertSame('online', self::session($app, $u2)['state']);
        self::evidence('writer-reader', ['zones' => $zones, 'event_timestamp' => $e, 'stored_text' => $row['acctstarttime'], 'reader_unix' => (int) $row['s'],
            'interval' => (int) $row['acctinterval'], 'no_event_timestamp' => ['php_before' => $before, 'php_after' => $after, 'stored_epoch' => $s2]]);
    }

    // ----------------------------------------------------------------------- 2. FROM_UNIXTIME / UNIX_TIMESTAMP per zone

    public function testFromUnixtimeAndUnixTimestampConvertExactlyInEveryNonDstZone(): void
    {
        $epochs = [self::utc('2026-01-15 12:00:00'), self::utc('2026-07-15 12:00:00'), self::utc('2026-10-13 09:35:13'), time(),
            self::utc('2026-03-29 00:59:59'), self::utc('2026-03-29 01:00:00'), self::utc('2026-10-25 00:30:00'), self::utc('2026-10-25 01:30:00')];
        foreach (['+00:00', '+03:00', '-05:00', '+05:45', 'UTC', 'Asia/Baghdad'] as $zone) {
            $pdo = self::conn($zone);
            foreach ($epochs as $e) {
                $r = self::q($pdo, 'SELECT FROM_UNIXTIME(?) AS t, UNIX_TIMESTAMP(FROM_UNIXTIME(?)) AS back', [$e, $e])[0];
                $this->assertSame((new \DateTimeImmutable('@' . $e))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i:s'), $r['t'], "{$zone} FROM_UNIXTIME({$e})");
                $this->assertSame($e, (int) $r['back'], "{$zone}: lossless round trip");
            }
        }
    }

    // ---------------------------------------------------------- 3. the PHP zone never changes a result (D-1 fixed)

    public function testStaleSessionsIsTheSameForEveryDatabaseZoneAndApplicationZone(): void
    {
        $results = [];
        foreach (['+00:00', '+03:00', '-05:00'] as $dbz) {
            foreach (['UTC', 'Asia/Baghdad', 'America/New_York'] as $phpz) {
                date_default_timezone_set($phpz);
                $w = self::conn($dbz);
                $n = time();
                $rows = [   // name => [start, update, stop, stale at 120 s, stale at the 60 s floor]
                    'live' => [$n - 600, $n - 30, null, false, false],
                    'old' => [$n - 7200, $n - 3600, null, true, true],
                    'cut-1' => [$n - 600, $n - 121, null, true, true],
                    'cut' => [$n - 600, $n - 120, null, false, true],
                    'cut+1' => [$n - 600, $n - 119, null, false, true],
                    'floor+1' => [$n - 600, $n - 61, null, false, true],
                    'floor' => [$n - 600, $n - 60, null, false, false],
                    'noupd-live' => [$n - 30, null, null, false, false],
                    'noupd-old' => [$n - 3600, null, null, true, true],
                    'future-tol' => [$n - 600, $n + 300, null, false, false],
                    'future-tol+1' => [$n - 600, $n + 301, null, true, true],
                    'future-3h' => [$n - 600, $n + 10800, null, true, true],
                    'no-times' => [null, null, null, true, true],
                    'closed' => [$n - 7200, $n - 3600, $n - 3000, false, false],
                ];
                $users = $exp120 = $exp60 = [];
                foreach ($rows as $name => [$s, $u, $stop, $st120, $st60]) {
                    $users[$name] = $user = self::user("m{$name}");
                    self::write($w, $user, $s, $u, $stop);
                    if ($st120) {
                        $exp120[] = $user;
                    }
                    if ($st60) {
                        $exp60[] = $user;
                    }
                }
                foreach ([['zero', '0000-00-00 00:00:00'], ['pre1970', '1960-01-01 00:00:00']] as [$name, $text]) {
                    $users[$name] = $user = self::user("m{$name}");
                    self::writeRaw($user, $text, $text);
                    $exp120[] = $exp60[] = $user;
                }
                sort($exp120);
                sort($exp60);
                $reader = self::conn($dbz);
                $sum = self::checksum();
                foreach (['UTC', '+03:00', '-05:00', 'Asia/Baghdad'] as $nowZone) {     // the zone of $now is irrelevant (an instant)
                    $now = (new \DateTimeImmutable('@' . $n))->setTimezone(new \DateTimeZone($nowZone));
                    $this->assertSame($exp120, self::stale($reader, $now, array_values($users)), "db {$dbz} / php {$phpz} / now {$nowZone}: threshold 120 s");
                    $this->assertSame($exp60, self::stale($reader, $now, array_values($users), 10), "db {$dbz} / php {$phpz}: the 60 s floor");
                }
                $this->assertSame($sum, self::checksum(), 'reports never change radacct');
                $results["{$dbz}|{$phpz}"] = array_map(static fn($u) => array_search($u, $users, true), $exp120);
            }
        }
        self::evidence('stale-matrix', ['stale_at_120' => $results]);
    }

    // ------------------------------------------------- Subscriber::session state and display (D-2 / D-3 fixed)

    public function testSubscriberSessionStateAndDisplayAreTheSameInstantInEveryZoneCombination(): void
    {
        $seen = [];
        foreach (['+00:00', '+03:00', '-05:00'] as $dbz) {
            foreach (['UTC', 'Asia/Baghdad', 'America/New_York'] as $phpz) {
                date_default_timezone_set($phpz);
                $w = self::conn($dbz);
                $reader = self::conn($dbz);
                $n = time();
                $cases = [   // name => [start, update, stop, expected state]
                    'fresh' => [$n - 600, $n - 30, null, 'online'],
                    'fresh-noupd' => [$n - 40, null, null, 'online'],
                    'stale' => [$n - 7200, $n - 3600, null, 'stale'],
                    'stopped' => [$n - 7200, $n - 3600, $n - 1800, 'offline'],
                    'future' => [$n - 600, $n + 3600, null, 'stale'],
                    'no-times' => [null, null, null, 'stale'],
                ];
                foreach ($cases as $name => [$s, $u, $stop, $state]) {
                    $user = self::user("s{$name}");
                    self::write($w, $user, $s, $u, $stop);
                    $sum = self::checksum();
                    $out = self::session($reader, $user);
                    $this->assertSame($sum, self::checksum());
                    $ctx = "db {$dbz} / php {$phpz} / {$name}";
                    $this->assertSame($state, $out['state'], $ctx);
                    $this->assertSame($s === null ? '' : self::label($s, $phpz), $out['started'], "{$ctx}: started in the application zone with its offset");
                    $this->assertSame(($u ?? $s) === null ? '' : self::label($u ?? $s, $phpz), $out['last_update'], "{$ctx}: last update");
                    $this->assertSame($stop === null ? null : self::label($stop, $phpz), $out['stopped'], "{$ctx}: stopped");
                    $this->assertSame([$s, $u ?? $s, $stop], [$out['started_epoch'], $out['last_update_epoch'], $out['stopped_epoch']], "{$ctx}: epochs");
                    $seen[$name][] = $out['state'];
                }
                // a legacy zero date never makes an open session "online"
                $user = self::user('szero');
                self::writeRaw($user, '0000-00-00 00:00:00', '0000-00-00 00:00:00');
                $out = self::session($reader, $user);
                $this->assertSame('stale', $out['state'], "db {$dbz} / php {$phpz}: zero date");
                $this->assertSame(['', ''], [$out['started'], $out['last_update']], 'an invalid time is not displayed as a time');
            }
        }
        self::evidence('subscriber-matrix', ['states' => array_map(static fn($v) => array_values(array_unique($v)), $seen)]);
    }

    public function testChangingTheApplicationZoneBetweenWriteAndReadChangesNothing(): void
    {
        date_default_timezone_set('Asia/Baghdad');
        $w = self::conn(null);
        $n = time();
        $user = self::user('appzone');
        self::write($w, $user, $n - 600, $n - 30);
        $labels = [];
        foreach (['Asia/Baghdad', 'UTC', 'America/New_York', 'Europe/Berlin', 'Asia/Kathmandu'] as $z) {
            date_default_timezone_set($z);
            $out = self::session(self::conn(null), $user);
            $this->assertSame('online', $out['state'], $z);
            $this->assertSame([$n - 600, $n - 30], [$out['started_epoch'], $out['last_update_epoch']], $z);
            $labels[$z] = $out['last_update'];
            $this->assertSame($n - 30, \DateTimeImmutable::createFromFormat('Y-m-d H:i:s \U\T\CP', $out['last_update'])->getTimestamp(), "{$z}: the label is the same instant");
            $this->assertSame([], self::stale(self::conn(null), new \DateTimeImmutable('now'), [$user]), "{$z}: not stale");
        }
        $this->assertCount(5, array_unique($labels), 'each zone shows its own wall clock');
    }

    // ------------------------------------------------------------------------------ accounting interruption (T-I2)

    public function testAccountingInterruptionWithTheRealWriter(): void
    {
        $app = self::conn(null);
        $user = self::user('outage');
        $sid = bin2hex(random_bytes(4));
        $hist = self::user('outage-hist');
        $n = time();
        self::write($app, $hist, $n - 90000, $n - 86400, $n - 86000);       // retained history of another session
        $histRow = self::q($app, 'SELECT * FROM radacct WHERE username = ?', [$hist]);
        self::acct($user, $sid, 'Start', $n - 4000);
        self::acct($user, $sid, 'Interim-Update', $n - 3940, [['Acct-Session-Time', 60]]);
        // the NAS stops sending Interim-Updates (accounting path lost): the session becomes a stale candidate
        $this->assertSame([$user], self::stale($app, new \DateTimeImmutable('now'), [$user, $hist]), 'no accounting for 3940 s: stale');
        $this->assertSame('stale', self::session($app, $user)['state'], 'status unknown (> 1800 s), never online');
        // Interim-Updates resume
        self::acct($user, $sid, 'Interim-Update', time() - 5, [['Acct-Session-Time', 3995]]);
        $this->assertSame([], self::stale($app, new \DateTimeImmutable('now'), [$user, $hist]), 'fresh again');
        $this->assertSame('online', self::session($app, $user)['state']);
        // Stop
        self::acct($user, $sid, 'Stop', time(), [['Acct-Session-Time', 600], ['Acct-Terminate-Cause', 1]]);
        $this->assertSame([], self::stale($app, new \DateTimeImmutable('now'), [$user, $hist]), 'a stopped session is never stale');
        $out = self::session($app, $user);
        $this->assertSame('offline', $out['state']);
        $this->assertCount(1, self::q($app, 'SELECT radacctid FROM radacct WHERE username = ?', [$user]), 'one session row, never duplicated');
        $this->assertSame($histRow, self::q($app, 'SELECT * FROM radacct WHERE username = ?', [$hist]), 'retained history byte-identical');
        self::evidence('outage', ['session' => self::q($app, 'SELECT acctstarttime, acctupdatetime, acctstoptime, acctinterval, acctsessiontime FROM radacct WHERE username = ?', [$user])[0]]);
    }

    // -------------------------------------- 4. a writer / reader session zone mismatch never passes as a correct state

    public function testAZoneMismatchShiftsEveryEpochExactlyAndVoidsTheFreshnessGuarantee(): void
    {
        // the REAL FreeRADIUS writer (server zone) and a reader whose session zone differs (a one-sided override)
        $user = self::user('mismatch');
        $e = time() - 30;
        self::acct($user, bin2hex(random_bytes(4)), 'Start', $e);
        $truth = (int) self::one(self::conn(null), 'SELECT UNIX_TIMESTAMP(acctstarttime) FROM radacct WHERE username = ?', [$user]);
        $this->assertSame($e, $truth, 'same zone: exact');
        $sum = self::checksum();
        $seen = [];
        $serverOffset = (int) self::one(self::conn(null), 'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
        // reader zone => [shift against the UTC instant, expected Subscriber state]
        foreach (['+03:00' => [-10800, 'stale'], '-05:00' => [18000, 'stale'], '-00:15' => [900, 'stale'], '+00:15' => [-900, 'online']] as $readerZone => [$shift, $state]) {
            $reader = self::conn($readerZone);
            $read = (int) self::one($reader, 'SELECT UNIX_TIMESTAMP(acctstarttime) FROM radacct WHERE username = ?', [$user]);
            $this->assertSame($shift + $serverOffset, $read - $e, "reader {$readerZone}: the epoch is off by exactly the zone difference");
            $this->assertSame([$user], self::stale($reader, new \DateTimeImmutable('now'), [$user]), "reader {$readerZone}: reported for review (threshold 120 s), never fresh");
            $out = self::session($reader, $user);
            // writer ahead of the reader by any real zone step (>= 15 min): a future time, never online. Writer behind:
            // the session looks older by the difference; only a difference below STALE_SECONDS - age (a 15 / 30 / 45 min
            // zone pair) can still show "online", with every displayed time off by exactly that difference (limit L-A4-2)
            $this->assertSame($state, $out['state'], "reader {$readerZone}");
            $this->assertSame($read, $out['started_epoch'], "reader {$readerZone}: the shifted instant is what is shown");
            $seen[$readerZone] = ['epoch_shift' => $read - $e, 'state' => $out['state'], 'started' => $out['started']];
        }
        $this->assertSame($sum, self::checksum(), 'nothing is rewritten');

        // the other direction with a writer session zone ahead of / behind the reader (SQL writer, same statements)
        foreach ([['+03:00', '+00:00'], ['+00:00', '+03:00'], ['-05:00', '+00:00']] as [$wz, $rz]) {
            $u = self::user('mm');
            self::write(self::conn($wz), $u, time() - 600, time() - 30);
            $this->assertSame([$u], self::stale(self::conn($rz), new \DateTimeImmutable('now'), [$u]), "writer {$wz} / reader {$rz}: a freshly written row is never fresh");
            $this->assertSame('stale', self::session(self::conn($rz), $u)['state'], "writer {$wz} / reader {$rz}");
        }
        // ... but the guarantee is VOID under a mismatch: with the writer 3 h ahead, a session whose accounting went
        // silent about 3 h ago reads as just updated (limit L-A4-2; the deployment contract forbids the mismatch)
        $silent = self::user('mm-silent');
        $n = time();
        self::write(self::conn('+03:00'), $silent, $n - 14000, $n - 10800 - 60);
        $this->assertSame([], self::stale(self::conn('+00:00'), new \DateTimeImmutable('@' . $n), [$silent]), 'silent for 3 h 01 min, not reported (reads 60 s old)');
        $this->assertSame('online', self::session(self::conn('+00:00'), $silent)['state'], 'silent for 3 h 01 min, shown Online: the mismatch voids freshness');
        self::evidence('zone-mismatch', ['real_writer_epoch' => $e, 'readers' => $seen]);
    }

    public function testMixedHistoryWrittenBeforeAndAfterADatabaseZoneChangeIsReadNeverRewritten(): void
    {
        // the RADIUS database zone was +03:00 and is now UTC: old rows keep their +03:00 wall clock (no zone in DATETIME)
        $user = self::user('mixed');
        $t1 = self::utc('2026-06-01 10:00:00');
        $t2 = self::utc('2026-09-01 10:00:00');
        self::write(self::conn('+03:00'), $user, $t1, $t1 + 600, $t1 + 3600);
        $open = time() - 30;
        self::write(self::conn('+03:00'), $user, $open, $open);           // a session open across the change
        self::write(self::conn('+00:00'), $user, $t2, $t2 + 600, $t2 + 3600);
        $sum = self::checksum();
        $reader = self::conn('+00:00');
        $hist = (new AccountingService($reader))->history($user);
        $this->assertCount(3, $hist);
        $byStart = [];
        foreach ($hist as $h) {
            $byStart[] = (int) $h['acctstart_epoch'];
        }
        sort($byStart);
        $this->assertSame([$t1 + 10800, $t2, $open + 10800], $byStart, 'rows written before the change read 3 h off (documented, never corrected in place)');
        $this->assertSame([$user], self::stale($reader, new \DateTimeImmutable('now'), [$user]), 'the session open across the change is reported, never fresh');
        date_default_timezone_set('UTC');
        $out = self::session($reader, $user);
        $this->assertSame('stale', $out['state'], 'never a confirmed online');
        $this->assertSame($sum, self::checksum(), 'history byte-identical after every read');
        self::evidence('mixed-history', ['start_epochs_read' => $byStart, 'truth' => [$t1, $t2, $open]]);
    }

    // -------------------------------------------------------------------------------------- DST characterisation

    /**
     * OD-A4-2 basis (H-1 / H-2): in a DST session zone the repeated autumn hour maps two instants to one DATETIME text, so
     * UNIX_TIMESTAMP() returns one of them for both; freshness, FreeRADIUS durations (acctinterval / accounting-on
     * acctsessiontime) and the counter period edge can be wrong by 3600 s in that hour. FROM_UNIXTIME never writes a time
     * in the spring gap, so spring is exact. A UTC (non-DST) zone has no ambiguity.
     */
    public function testDstTransitionsAreCharacterisedAndUtcHasNoAmbiguity(): void
    {
        $report = [];
        $autumn = ['Europe/Berlin' => self::utc('2026-10-25 01:00:00'), 'America/New_York' => self::utc('2026-11-01 06:00:00')];
        $spring = ['Europe/Berlin' => self::utc('2026-03-29 01:00:00'), 'America/New_York' => self::utc('2026-03-08 07:00:00')];
        foreach (['Europe/Berlin', 'America/New_York', 'UTC', '+00:00'] as $zone) {
            $dst = isset($autumn[$zone]);
            $fall = $autumn[$zone] ?? self::utc('2026-10-25 01:00:00');
            $a1 = $fall - 1800;                        // first pass of the repeated hour (summer time)
            $a2 = $fall + 1800;                        // second pass (standard time), one hour later
            $pdo = self::conn($zone);
            $r = self::q($pdo, 'SELECT FROM_UNIXTIME(?) AS t1, FROM_UNIXTIME(?) AS t2, UNIX_TIMESTAMP(FROM_UNIXTIME(?)) AS b1, UNIX_TIMESTAMP(FROM_UNIXTIME(?)) AS b2', [$a1, $a2, $a1, $a2])[0];
            $collide = $r['t1'] === $r['t2'];
            $this->assertSame($dst, $collide, "{$zone}: the repeated hour gives one text for two instants only in a DST zone");
            if ($dst) {
                $this->assertSame((int) $r['b1'], (int) $r['b2'], "{$zone}: UNIX_TIMESTAMP returns one instant for both");
                $this->assertContains((int) $r['b1'], [$a1, $a2]);
            } else {
                $this->assertSame([$a1, $a2], [(int) $r['b1'], (int) $r['b2']], "{$zone}: exact");
            }
            // freshness at $a2 + 60 (threshold 120): r1 is 3660 s old (stale), r2 is 60 s old (fresh)
            $u1 = self::user('dst1');
            $u2 = self::user('dst2');
            self::write($pdo, $u1, $a1 - 600, $a1);
            self::write($pdo, $u2, $a2 - 300, $a2);
            $now = new \DateTimeImmutable('@' . ($a2 + 60));
            $got = self::stale($pdo, $now, [$u1, $u2]);
            $wrong = ($got === [$u1] ? 0 : count(array_diff([$u1], $got)) + count(array_diff($got, [$u1])));
            $this->assertSame($dst ? 1 : 0, $wrong, "{$zone}: freshness misclassifications in the repeated hour");
            // FreeRADIUS durations: Interim acctinterval = epoch - UNIX_TIMESTAMP(previous update); true value 3600
            $interval = (int) self::one($pdo, 'SELECT ? - UNIX_TIMESTAMP(FROM_UNIXTIME(?))', [$a2, $a1]);
            $this->assertSame($dst ? ((int) $r['b1'] === $a1 ? 3600 : 0) : 3600, $interval, "{$zone}: acctinterval across the repeated hour");
            // the other direction: both Interims inside the second pass (true interval 60 s)
            $interval2 = (int) self::one($pdo, 'SELECT ? - UNIX_TIMESTAMP(FROM_UNIXTIME(?))', [$a2 + 60, $a2]);
            $this->assertSame($dst ? ((int) $r['b2'] === $a2 ? 60 : 3660) : 60, $interval2, "{$zone}: acctinterval inside the second pass");
            // the quota counter period edge (nuxr_counters: acctstarttime >= FROM_UNIXTIME(period start)), period starts at $a2
            $uq = self::user('dstq');
            self::write($pdo, $uq, $a1, $a1 + 60, $a1 + 120);                  // a session that began BEFORE the period
            $counted = (int) self::one($pdo, 'SELECT COUNT(*) FROM radacct WHERE username = ? AND acctstarttime >= FROM_UNIXTIME(?)', [$uq, $a2]);
            $this->assertSame($dst ? 1 : 0, $counted, "{$zone}: a session before the period start counted in the period (repeated hour)");
            // spring: FROM_UNIXTIME never writes a time inside the gap, so values written by FreeRADIUS round trip exactly
            $sp = $spring[$zone] ?? self::utc('2026-03-29 01:00:00');
            foreach ([$sp - 1, $sp, $sp + 1800] as $e) {
                $this->assertSame($e, (int) self::one($pdo, 'SELECT UNIX_TIMESTAMP(FROM_UNIXTIME(?))', [$e]), "{$zone}: spring transition exact");
            }
            $this->assertSame(1, (int) self::one($pdo, 'SELECT UNIX_TIMESTAMP(FROM_UNIXTIME(?)) - UNIX_TIMESTAMP(FROM_UNIXTIME(?))', [$sp, $sp - 1]), "{$zone}: 1 s across the spring gap");
            $us = self::user('dsts');
            self::write($pdo, $us, $sp - 600, $sp - 30);
            $this->assertSame([], self::stale($pdo, new \DateTimeImmutable('@' . ($sp + 30)), [$us]), "{$zone}: 60 s old across the spring gap is fresh");
            $report[$zone] = ['repeated_hour_text' => [$r['t1'], $r['t2']], 'unix_timestamp_back' => [(int) $r['b1'], (int) $r['b2']], 'truth' => [$a1, $a2],
                'stale_misclassified' => $wrong, 'interval_true_3600' => $interval, 'interval_true_60' => $interval2, 'counter_edge_wrongly_counted' => $counted];
        }
        self::evidence('dst', $report);
    }

    // ----------------------------------------------------------------------------------------------- performance

    public function testTheStaleQueryKeepsTheAccessPathOfThePreviousQuery(): void
    {
        $pdo = self::conn(null);
        $prefix = self::user('perf');
        self::q($pdo, "INSERT INTO radacct (acctsessionid, acctuniqueid, username, acctstarttime, acctupdatetime, acctstoptime) VALUES ('p', MD5(CONCAT(?, '-seed')), ?, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 1 DAY)", [$prefix, $prefix]);
        // unique keys without randomness (row id + doubling step): MySQL RAND() can repeat a value for the same row
        for ($i = 0; $i < 15; $i++) {      // 32768 closed rows
            self::q($pdo, "INSERT INTO radacct (acctsessionid, acctuniqueid, username, acctstarttime, acctupdatetime, acctstoptime) SELECT 'p', MD5(CONCAT(radacctid, '-', ?)), username, acctstarttime, acctupdatetime, acctstoptime FROM radacct WHERE username = ?", [$prefix . '-' . $i, $prefix]);
        }
        self::q($pdo, 'UPDATE radacct SET acctstoptime = NULL WHERE username = ? ORDER BY radacctid LIMIT 200', [$prefix]);
        self::q($pdo, 'ANALYZE TABLE radacct');
        $recorder = new class('mysql:host=' . getenv('WP1_DB_HOST') . ';dbname=' . self::DB . ';charset=utf8mb4', 'root', TestDatabase::rootPassword()) extends \PDO {
            public array $sql = [];
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->sql[] = $query;
                return parent::prepare($query, $options);
            }
        };
        $now = new \DateTimeImmutable('now');
        $rows = (new AccountingService($recorder))->staleSessions($now, self::THRESHOLD);
        $this->assertGreaterThanOrEqual(200, count(array_filter($rows, static fn($r) => $r['username'] === $prefix)));
        $new = $recorder->sql[0];
        $old = 'SELECT radacctid, acctsessionid, acctuniqueid, username, nasipaddress, framedipaddress, acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets, callingstationid FROM radacct WHERE acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) < ? ORDER BY radacctid';
        $cut = $now->getTimestamp() - self::THRESHOLD;
        $plan = static function (string $sql, array $params) use ($pdo): array {
            return array_map(static fn($r) => array_intersect_key(array_change_key_case($r), array_flip(['type', 'key', 'rows', 'extra'])), self::q($pdo, 'EXPLAIN ' . $sql, $params));
        };
        $oldPlan = $plan($old, [gmdate('Y-m-d H:i:s', $cut)]);
        $newPlan = $plan($new, [$cut, $now->getTimestamp() + AccountingService::CLOCK_TOLERANCE_SECONDS]);
        $this->assertSame(array_column($oldPlan, 'type'), array_column($newPlan, 'type'), 'same access type');
        $this->assertSame(array_column($oldPlan, 'key'), array_column($newPlan, 'key'), 'same index');
        $this->assertNotContains('ALL', array_column($newPlan, 'type'), 'no full table scan');
        $time = static function (string $sql, array $params) use ($pdo): float {
            $t = [];
            for ($i = 0; $i < 7; $i++) {
                $s = hrtime(true);
                self::q($pdo, $sql, $params);
                $t[] = (hrtime(true) - $s) / 1e6;
            }
            sort($t);
            return round($t[3], 3);
        };
        $total = (int) self::one($pdo, 'SELECT COUNT(*) FROM radacct');
        self::evidence('performance', ['radacct_rows' => $total, 'open_rows' => (int) self::one($pdo, 'SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL'),
            'old_plan' => $oldPlan, 'new_plan' => $newPlan, 'old_median_ms' => $time($old, [gmdate('Y-m-d H:i:s', $cut)]),
            'new_median_ms' => $time($new, [$cut, $now->getTimestamp() + AccountingService::CLOCK_TOLERANCE_SECONDS]), 'new_sql' => $new]);
        self::q($pdo, 'DELETE FROM radacct WHERE username = ?', [$prefix]);    // test rows only (disposable harness database)
    }
}
