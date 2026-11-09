<?php
namespace Reseller\Tests\Unit\Ux;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Finance\LedgerReadModel;
use Reseller\Ui\Format;

/**
 * V1.0.1 (owner decision 2026-11-09): finance and reseller screens show times in the application time zone (Baghdad on
 * an Iraq install) and the from / to filters are days of that zone. The stored columns and the queries stay UTC.
 */
final class LocalTimeDisplayTest extends TestCase
{
    private string $zone;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        if (!defined('NUXR_RUNTIME')) {
            define('NUXR_RUNTIME', true);
        }
        require_once $root . '/system/autoload/Reseller/Ui/Format.php';
        require_once $root . '/system/autoload/Reseller/Finance/LedgerReadModel.php';
    }

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testStoredUtcIsShownInTheApplicationZone(): void
    {
        date_default_timezone_set('Asia/Baghdad');
        $this->assertSame('2026-11-09 03:30:00', Format::localTime('2026-11-09 00:30:00'));
        $this->assertSame('2026-11-09 03:30:00', Format::localTime('2026-11-09 00:30:00.123456'), 'microseconds ignored');
        $this->assertSame('2026-11-09 02:59', Format::localTime('2026-11-08 23:59:59', 16), 'the day changes');
        $this->assertSame('2026-11-09', Format::localTime('2026-11-08 21:00:00', 10));
        $this->assertSame('', Format::localTime(null));
        $this->assertSame('', Format::localTime(''));
        $this->assertSame('n/a', Format::localTime('n/a'), 'an unreadable value is shown as it is');

        date_default_timezone_set('UTC');
        $this->assertSame('2026-11-09 00:30:00', Format::localTime('2026-11-09 00:30:00'), 'a UTC install is unchanged');
    }

    public function testFilterDaysAreDaysOfTheApplicationZone(): void
    {
        date_default_timezone_set('Asia/Baghdad');
        $this->assertSame(['2026-11-08 21:00:00', '2026-11-09 21:00:00'], LedgerReadModel::bounds(['from' => '2026-11-09', 'to' => '2026-11-09']));
        $this->assertSame([null, '2026-12-31 21:00:00'], LedgerReadModel::bounds(['to' => '2026-12-31']));
        $this->assertSame([null, null], LedgerReadModel::bounds(['from' => '2026-02-30', 'to' => 'x']), 'invalid days are ignored');

        date_default_timezone_set('UTC');
        $this->assertSame(['2026-11-09 00:00:00', '2026-11-10 00:00:00'], LedgerReadModel::bounds(['from' => '2026-11-09', 'to' => '2026-11-09']));
    }

    public function testNoFinanceScreenSaysUtc(): void
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(glob($root . '/ui/ui/admin/finance/*.tpl') ?: [], glob($root . '/ui/ui/admin/nuxr/*.tpl') ?: [],
            glob($root . '/ui/ui/admin/operations/*.tpl') ?: [], [$root . '/ui/ui/admin/activation/view.tpl', $root . '/ui/ui/admin/customers/view.tpl']);
        $this->assertGreaterThan(10, count($files));
        foreach ($files as $f) {
            $this->assertStringNotContainsString('UTC', (string) file_get_contents($f), basename($f));
        }
    }
}
