<?php
namespace Reseller\Tests\Unit\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Domain\DomainError;
use Reseller\Finance\LegacyRetailPrice;

/**
 * WP1 binding input "legacy VARCHAR price parsing/validation": tbl_plans.price (VARCHAR(40), legacy
 * validator /^\+?[0-9]+$/) is read with the exact WP3A Money grammar and never normalised.
 */
final class LegacyRetailPriceTest extends TestCase
{
    public function testAccepted(): void
    {
        foreach (['0' => '0.00', '10' => '10.00', '1000' => '1000.00', '10.5' => '10.50', '10.50' => '10.50', '9999999999999999.99' => '9999999999999999.99'] as $raw => $exp) {
            $this->assertSame($exp, LegacyRetailPrice::parse((string) $raw)->format(), (string) $raw);
        }
    }

    public function testRefusedWithoutNormalisation(): void
    {
        $bad = ['+1000', '0100', '00', '', ' 10', '10 ', "10\n", '1,000', '1.000,00', '1e3', '-5', '10.', '.5', '10.505',
            '12345678901234567', '١٠٠', '１００', 'abc', 'NaN', 'INF', null, 10, 10.5, true, []];
        foreach ($bad as $v) {
            try {
                LegacyRetailPrice::parse($v);
                $this->fail('accepted ' . var_export($v, true));
            } catch (DomainError $e) {
                $this->assertSame('LEGACY_RETAIL_INVALID', $e->reason);
            }
            $this->assertNull(LegacyRetailPrice::tryParse($v));
        }
    }
}
