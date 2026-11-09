<?php
namespace Reseller\Tests\Unit\Ux;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * Voucher printing (owner request 2026-11-09): 80 mm thermal cards are the default, the A4 sheet stays available,
 * every value printed on a card is escaped, and the card text matches the redemption rule (portal voucher activation
 * is for subscribers managed directly by the company).
 */
final class VoucherPrintTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testThermalCardsAreTheDefaultAndA4StaysAvailable(): void
    {
        $plan = self::read('system/controllers/plan.php');
        $this->assertStringContainsString("\$printFormat = _post('format') === 'a4' ? 'a4' : 'thermal80';", $plan);
        $this->assertStringContainsString("\$printFormat = _post('print_format') === 'a4' ? 'a4' : 'thermal80';", $plan);
        $this->assertSame(2, substr_count($plan, "\$ui->display('admin/print/voucher-80mm.tpl');"), 'print page and "print now"');
        $this->assertStringContainsString("\$ui->display('admin/print/voucher.tpl');", $plan, 'the A4 sheet is kept');
        $this->assertStringContainsString('<option value="thermal80"', self::read('ui/ui/admin/print/voucher.tpl'), 'A4 page offers the thermal printer');
    }

    public function testEveryCardValueIsEscaped(): void
    {
        $tpl = self::read('ui/ui/admin/print/voucher-80mm.tpl');
        preg_match_all('/\{\$c\.(\w+)([^}]*)\}/', $tpl, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as $use) {
            $this->assertMatchesRegularExpression('/\|escape/', $use[2], "\$c.{$use[1]} is escaped");
        }
        $this->assertStringContainsString('@page { size: 80mm auto; margin: 0; }', $tpl);
    }

    public function testActivationCardTextMatchesTheRedemptionRule(): void
    {
        $tpl = self::read('ui/ui/admin/print/voucher-80mm.tpl');
        $this->assertStringContainsString('Enter this code in the subscriber portal under Voucher Activation.', $tpl);
        $this->assertStringNotContainsString('give it to your reseller', $tpl, 'a reseller cannot redeem a voucher for its subscriber');
        $ar = json_decode(self::read('system/lan/arabic.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('Enter_this_code_in_the_subscriber_portal_under_Voucher_Activation_', $ar);
        $this->assertArrayHasKey('Hotspot_internet_card', $ar);
    }
}
