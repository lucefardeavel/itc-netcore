<?php
namespace Reseller\Tests\Unit\Ux;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Domain\VoucherLogin;

/**
 * Username-and-password hotspot cards (owner decision 2026-11-09, R0008): digits from the CSPRNG within 4..12, the
 * portal asks for the card password before any redemption, the network receives the card password on every voucher
 * provisioning path, no card password outlives its voucher, and the printed values are escaped.
 */
final class VoucherLoginTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testGeneratedPasswordsAreDigitsWithinTheAllowedLength(): void
    {
        foreach ([1, 4, 6, 12, 40] as $asked) {
            $p = VoucherLogin::generate($asked);
            $this->assertMatchesRegularExpression('/^[0-9]+$/', $p);
            $this->assertSame(max(4, min(12, $asked)), strlen($p), "length {$asked}");
        }
        $this->assertNotSame(VoucherLogin::generate(12), VoucherLogin::generate(12), 'not a constant');
    }

    public function testThePortalChecksTheCardPasswordBeforeAnyRedemption(): void
    {
        $login = self::read('system/controllers/login.php');
        $check = strpos($login, "VoucherLogin::accepts(\$voucher, (string) _post('voucher_password'))");
        $this->assertNotFalse($check);
        $this->assertLessThan(strpos($login, 'VoucherRedemptionService::redeem($voucher'), $check, 'checked before the redemption');
        $this->assertLessThan(strpos($login, "->where('customer_id', '0') // Voucher Only"), $check, 'and before the re-activation lookup');
        $this->assertSame(2, substr_count($login, "'password' => \\Reseller\\Domain\\VoucherLogin::networkPassword(\$voucher),"), 'both device calls');
        $this->assertStringNotContainsString("'password' => \$voucher,", $login);
        $this->assertStringContainsString('hash_equals($p, $typed)', self::read('system/autoload/Reseller/Domain/VoucherLogin.php'), 'constant-time comparison');
    }

    public function testTheVoucherOnlyRechargeProvisionsTheCardPassword(): void
    {
        $this->assertStringContainsString("'password' => \\Reseller\\Domain\\VoucherLogin::networkPassword((string) \$channel),", self::read('system/autoload/Package.php'));
    }

    public function testNoCardPasswordOutlivesItsVoucher(): void
    {
        $plan = self::read('system/controllers/plan.php');
        $this->assertSame(3, substr_count($plan, 'VoucherLogin::pruneOrphans();'), 'single delete, bulk delete, used-voucher cleanup');
        $this->assertStringContainsString('$d->delete();', $plan, 'a voucher whose password cannot be stored is removed');
    }

    public function testCardsShowBothValuesEscapedAndTheSchemaIsRequired(): void
    {
        $tpl = self::read('ui/ui/admin/print/voucher-80mm.tpl');
        $this->assertStringContainsString('{$c.password|escape}', $tpl);
        $this->assertStringContainsString("{Lang::T('User name')}", $tpl);
        $ready = self::read('system/autoload/Reseller/Domain/SchemaReadiness.php');
        $this->assertStringContainsString("'R0008_voucher_logins'", $ready);
        $this->assertStringContainsString("'tbl_voucher_logins'", $ready);
    }
}
