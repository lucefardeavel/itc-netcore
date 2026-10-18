<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\QuotaPolicy;
use Reseller\Ui\Format;

/**
 * The quota card shows sizes in the same decimal units the plan's data quota is defined in: a 50 GB plan reads "50 GB".
 * Found while documenting the product (2026-10-18): the card divided by 1024 and showed a 50 GB plan as "46.6 GB".
 */
final class QuotaDisplayUnitsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/system/autoload/Reseller/Ui/Format.php';
    }

    public function testAPlanQuotaReadsInItsOwnUnit(): void
    {
        $this->assertSame('50 GB', Format::bytes(QuotaPolicy::bytes(['data_limit' => 50, 'data_unit' => 'GB'])));
        $this->assertSame('500 MB', Format::bytes(QuotaPolicy::bytes(['data_limit' => 500, 'data_unit' => 'MB'])));
        $this->assertSame('20 MB', Format::bytes(QuotaPolicy::bytes(['data_limit' => 20, 'data_unit' => 'MB'])));
    }

    public function testDecimalSteps(): void
    {
        $this->assertSame('0 B', Format::bytes(0));
        $this->assertSame('999 B', Format::bytes(999));
        $this->assertSame('1 KB', Format::bytes(1000));
        $this->assertSame('1.5 MB', Format::bytes(1_500_000));
        $this->assertSame('46.6 GB', Format::bytes(46_600_000_000));
        $this->assertSame('1.2 TB', Format::bytes(1_234_000_000_000));
        $this->assertSame('50.1 GB', Format::bytes(50_060_000_000), 'one decimal, rounded');
    }
}
