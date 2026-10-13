<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\AccountingService;
use Reseller\Ui\Subscriber;

require_once dirname(__DIR__, 3) . '/system/autoload/Reseller/Ui/Subscriber.php';

/**
 * WP8 A4 (R-WP8-CARRY-03) accounting time contract, pure parts: radacct times are compared and displayed only as epoch
 * seconds (UNIX_TIMESTAMP in the RADIUS database session that wrote them), never as PHP-local strings. The session
 * state boundaries (±1 s), the clock tolerance for future times, missing / invalid times (never a confirmed "online"),
 * the display in the application zone with its UTC offset across DST changes, and source guards for the contract.
 */
final class AccountingTimeUnitTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    private static function src(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }

    /** the body of one method (from its signature to the next method) */
    private static function method(string $file, string $name): string
    {
        $s = self::src($file);
        $at = strpos($s, "function {$name}(");
        self::assertNotFalse($at, "{$file}: {$name}()");
        $next = preg_match('/\n    (public|private|protected) (static )?function /', $s, $m, PREG_OFFSET_CAPTURE, $at + 10) ? $m[0][1] : strlen($s);
        return substr($s, $at, $next - $at);
    }

    public function testStateBoundariesAroundTheStaleThreshold(): void
    {
        $now = 1_760_000_000;
        $t = Subscriber::STALE_SECONDS;
        $this->assertSame(1800, $t);
        $this->assertSame('online', Subscriber::sessionState(true, $now, $now));
        $this->assertSame('online', Subscriber::sessionState(true, $now - ($t - 1), $now), 'threshold - 1 s');
        $this->assertSame('online', Subscriber::sessionState(true, $now - $t, $now), 'exactly the threshold is still online (legacy "> threshold")');
        $this->assertSame('stale', Subscriber::sessionState(true, $now - ($t + 1), $now), 'threshold + 1 s');
        foreach ([null, $now, $now - 99999, $now + 99999] as $seen) {
            $this->assertSame('offline', Subscriber::sessionState(false, $seen, $now), 'a closed session is offline whatever its times');
        }
    }

    public function testMissingInvalidOrFutureTimesAreNeverAConfirmedOnline(): void
    {
        $now = 1_760_000_000;
        $tol = AccountingService::CLOCK_TOLERANCE_SECONDS;
        $this->assertSame(300, $tol);
        $this->assertSame('stale', Subscriber::sessionState(true, null, $now), 'no valid accounting time');
        $this->assertSame('stale', Subscriber::sessionState(true, 0, $now));
        $this->assertSame('stale', Subscriber::sessionState(true, -1, $now));
        $this->assertSame('online', Subscriber::sessionState(true, $now + ($tol - 1), $now), 'small clock difference tolerated');
        $this->assertSame('online', Subscriber::sessionState(true, $now + $tol, $now));
        $this->assertSame('stale', Subscriber::sessionState(true, $now + ($tol + 1), $now), 'a time in the future breaks the time contract: unknown, never online');
        $this->assertSame('stale', Subscriber::sessionState(true, $now + 900, $now), 'the smallest real zone difference (15 min) is never online');
        $this->assertSame('stale', Subscriber::sessionState(true, $now + 3 * 3600, $now), 'writer zone ahead of the reader zone');
        $this->assertSame('stale', Subscriber::sessionState(true, $now - 3 * 3600, $now), 'writer zone behind the reader zone');
    }

    public function testEpochNormalisation(): void
    {
        $this->assertSame(1_760_000_000, AccountingService::epoch('1760000000'));
        $this->assertSame(1_760_000_000, AccountingService::epoch(1_760_000_000));
        $this->assertSame(1_760_000_000, AccountingService::epoch('1760000000.000000'), 'a DECIMAL result of UNIX_TIMESTAMP');
        foreach ([null, '', '0', 0, '-5', -5, 'abc', '0000-00-00 00:00:00', '2026-10-13 10:00:00', false, [], '1.5e9'] as $bad) {
            $this->assertNull(AccountingService::epoch($bad), var_export($bad, true));
        }
    }

    /** @return list<array{string,int,string}> zone, epoch, expected display */
    public static function displayCases(): array
    {
        $u = static fn(string $utc): int => (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
        return [
            // Europe/Berlin spring forward 2026-03-29 01:00 UTC (02:00 CET -> 03:00 CEST)
            ['Europe/Berlin', $u('2026-03-29 00:59:59'), '2026-03-29 01:59:59 UTC+01:00'],
            ['Europe/Berlin', $u('2026-03-29 01:00:00'), '2026-03-29 03:00:00 UTC+02:00'],
            // Europe/Berlin fall back 2026-10-25 01:00 UTC (03:00 CEST -> 02:00 CET): the repeated hour stays unambiguous
            ['Europe/Berlin', $u('2026-10-25 00:30:00'), '2026-10-25 02:30:00 UTC+02:00'],
            ['Europe/Berlin', $u('2026-10-25 01:30:00'), '2026-10-25 02:30:00 UTC+01:00'],
            // America/New_York spring forward 2026-03-08 07:00 UTC, fall back 2026-11-01 06:00 UTC
            ['America/New_York', $u('2026-03-08 06:59:59'), '2026-03-08 01:59:59 UTC-05:00'],
            ['America/New_York', $u('2026-03-08 07:00:00'), '2026-03-08 03:00:00 UTC-04:00'],
            ['America/New_York', $u('2026-11-01 05:30:00'), '2026-11-01 01:30:00 UTC-04:00'],
            ['America/New_York', $u('2026-11-01 06:30:00'), '2026-11-01 01:30:00 UTC-05:00'],
            // no DST
            ['Asia/Baghdad', $u('2026-10-13 09:35:13'), '2026-10-13 12:35:13 UTC+03:00'],
            ['UTC', $u('2026-10-13 09:35:13'), '2026-10-13 09:35:13 UTC+00:00'],
        ];
    }

    public function testDisplayIsTheApplicationZoneWithItsOffsetAcrossDstChanges(): void
    {
        foreach (self::displayCases() as [$zone, $epoch, $expected]) {
            date_default_timezone_set($zone);
            $this->assertSame($expected, Subscriber::displayTime($epoch), "{$zone} @{$epoch}");
            $back = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s \U\T\CP', $expected);
            $this->assertSame($epoch, $back->getTimestamp(), 'the label identifies exactly one instant');
        }
        $this->assertNull(Subscriber::displayTime(null));
        $this->assertNull(Subscriber::displayTime(0));
    }

    public function testTheSameInstantIsShownConsistentlyInEveryApplicationZone(): void
    {
        $epoch = 1_760_348_113;
        $seen = [];
        foreach (['UTC', 'Asia/Baghdad', 'America/New_York', 'Europe/Berlin', 'Asia/Kathmandu'] as $zone) {
            date_default_timezone_set($zone);
            $label = Subscriber::displayTime($epoch);
            $seen[$label] = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s \U\T\CP', $label)->getTimestamp();
        }
        $this->assertCount(5, $seen, 'five different wall clocks');
        $this->assertSame([$epoch], array_values(array_unique($seen)), 'one instant');
    }

    public function testSourceGuardsOfTheTimeContract(): void
    {
        $session = self::method('system/autoload/Reseller/Ui/Subscriber.php', 'session');
        $this->assertStringNotContainsString('strtotime(', $session, 'no PHP-local parsing of radacct strings');
        foreach (['acctstarttime', 'acctupdatetime', 'acctstoptime'] as $col) {
            $this->assertStringNotContainsString("(string) \$row['{$col}']", $session, "{$col}: the display comes from the epochs, not the DB-local string");
        }
        $this->assertStringContainsString('acctupdate_epoch', $session);
        $stale = self::method('system/autoload/Reseller/Radius/AccountingService.php', 'staleSessions');
        $this->assertStringContainsString('getTimestamp()', $stale, 'the cutoff is an instant');
        $this->assertStringContainsString('UNIX_TIMESTAMP(COALESCE(acctupdatetime, acctstarttime))', $stale);
        $this->assertStringNotContainsString('->format(', $stale, 'no PHP-formatted date compared with a DATETIME');
        $this->assertStringNotContainsString('->modify(', $stale);
        $acct = self::src('system/autoload/Reseller/Radius/AccountingService.php');
        foreach (['UNIX_TIMESTAMP(acctstarttime) AS acctstart_epoch', 'UNIX_TIMESTAMP(acctupdatetime) AS acctupdate_epoch', 'UNIX_TIMESTAMP(acctstoptime) AS acctstop_epoch'] as $col) {
            $this->assertStringContainsString($col, $acct, 'additive epoch columns on every row read');
        }
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER)\b/', preg_replace('#/\*.*?\*/#s', '', $acct), 'AccountingService stays read only');
        // the contract precondition: neither the writer (FreeRADIUS sql module) nor the reader (init.php 'radius'
        // connection) overrides the session time zone, so both use the RADIUS database server zone
        $this->assertDoesNotMatchRegularExpression('/time_zone/i', self::src('init.php'));
        foreach (array_merge(glob(dirname(__DIR__, 3) . '/install/freeradius/mods-available/*'), [dirname(__DIR__, 3) . '/dev/test/freeradius/sql']) as $f) {
            $this->assertDoesNotMatchRegularExpression('/time_zone/i', (string) file_get_contents($f), basename($f));
        }
    }
}
