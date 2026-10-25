<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Ui\Format;

/**
 * UX-15 (lab 2026-10-25): the activation page read "1 أيام", and a plan change replaced the remaining paid time without
 * saying so. Format::days() words a day count for the interface language; Format::lostDays() / lostDaysNotice() word the
 * paid time a CHANGE_PLAN replaces, from the quote's own dates, for display only.
 */
final class DayCountDisplayTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/system/autoload/Reseller/Ui/Format.php';
    }

    public function testArabicDayCounts(): void
    {
        $cases = [
            1 => 'يوم واحد', 2 => 'يومان', 3 => '3 أيام', 7 => '7 أيام', 10 => '10 أيام', 11 => '11 يوماً', 30 => '30 يوماً', 99 => '99 يوماً',
            100 => '100 يوم', 103 => '103 أيام', 180 => '180 يوماً', 365 => '365 يوماً', 0 => '0 يوم',
        ];
        foreach ($cases as $n => $want) {
            $this->assertSame($want, Format::days($n, 'arabic'), (string) $n);
        }
        $this->assertSame('30 يوماً', Format::days('30', 'arabic'), 'a plan validity arrives as a string');
    }

    public function testEnglishDayCounts(): void
    {
        $this->assertSame('1 day', Format::days(1, 'english'));
        $this->assertSame('2 days', Format::days(2, 'english'));
        $this->assertSame('30 days', Format::days('30', 'english'));
        $this->assertSame('0 days', Format::days(0, 'english'));
    }

    public function testTheInterfaceLanguageIsTheDefault(): void
    {
        $had = array_key_exists('config', $GLOBALS);
        $old = $GLOBALS['config'] ?? null;
        try {
            $GLOBALS['config'] = ['language' => 'arabic'];
            $this->assertSame('يوم واحد', Format::days(1));
            $GLOBALS['config'] = ['language' => 'english'];
            $this->assertSame('1 day', Format::days(1));
        } finally {
            if ($had) {
                $GLOBALS['config'] = $old;
            } else {
                unset($GLOBALS['config']);
            }
        }
    }

    public function testLostDaysFromTheQuoteDates(): void
    {
        $this->assertSame(21, Format::lostDays('2026-11-14 23:59:59', '2026-10-25 12:00:00'), 'a started day counts');
        $this->assertSame(1, Format::lostDays('2026-10-25 13:00:00', '2026-10-25 12:00:00'));
        $this->assertSame(20, Format::lostDays('2026-11-14 12:00:00', '2026-10-25 12:00:00'));
        $this->assertSame(21, Format::lostDays('2026-11-14', '2026-10-25 12:00:00'), 'a date without time runs to the end of the day');
        $this->assertNull(Format::lostDays('2026-10-25 12:00:00', '2026-10-25 12:00:00'), 'nothing left');
        $this->assertNull(Format::lostDays('2026-10-01 23:59:59', '2026-10-25 12:00:00'), 'already expired');
        $this->assertNull(Format::lostDays(null, '2026-10-25 12:00:00'), 'no current period');
        $this->assertNull(Format::lostDays('', '2026-10-25 12:00:00'));
        $this->assertNull(Format::lostDays('garbage', '2026-10-25 12:00:00'));
    }

    public function testTheNoticeText(): void
    {
        $this->assertSame('5 days of paid service (until 2026-10-30) will be lost: the current period is replaced now and the remaining time is not refunded or carried over.',
            Format::lostDaysNotice(5, '2026-10-30 23:59:59', 'english'));
        $this->assertStringStartsWith('1 day of paid service (until 2026-10-26)', Format::lostDaysNotice(1, '2026-10-26 08:00:00', 'english'));
        $this->assertStringNotContainsString('<', Format::lostDaysNotice(2, '2026-10-26 08:00:00', 'arabic'));
    }

    public function testTheCatalogueEntriesKeepThePlaceholdersAndNoMarkup(): void
    {
        $key = (string) preg_replace('/[^A-Za-z0-9]/', '_', '[days] of paid service (until [date]) will be lost: the current period is replaced now and the remaining time is not refunded or carried over.');
        foreach (['arabic', 'english'] as $lang) {
            $cat = json_decode((string) file_get_contents(dirname(__DIR__, 3) . "/system/lan/{$lang}.json"), true, 512, JSON_THROW_ON_ERROR);
            foreach ([$key] as $k) {
                $this->assertArrayHasKey($k, $cat, "{$lang}: {$k}");
                $this->assertDoesNotMatchRegularExpression('/[<>]/', $cat[$k], "{$lang}: plain text (F-12)");
            }
            $this->assertStringContainsString('[days]', $cat[$key], $lang);
            $this->assertStringContainsString('[date]', $cat[$key], $lang);
        }
    }
}
