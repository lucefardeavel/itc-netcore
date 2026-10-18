<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Ui\Format;

// the test bootstrap autoloads Reseller\{Security,Domain,Audit,Finance,Radius,Activation} only; Format is in Reseller\Ui
require_once dirname(__DIR__, 3) . '/system/autoload/Reseller/Ui/Format.php';

/** D8 (owner V1_OWNER_DECISIONS_APPROVED): display masking of a voucher code; the stored value is never changed. */
final class VoucherMaskTest extends TestCase
{
    public function testMethodMasksOnlyAVoucherCode(): void
    {
        $this->assertSame('Cash', Format::method('Cash'));
        $this->assertSame('Bank - REF123456', Format::method('Bank - REF123456'));
        $this->assertSame('Voucher - AB****GH', Format::method('Voucher - ABCDEFGH'));
        $this->assertSame('Voucher - AB****GH', Format::method('Voucher - ABCDXXXXXXXXXXXXXXGH'), 'the length of the code is not revealed');
        $this->assertSame('Voucher - ****', Format::method('Voucher - AB12'));
        $this->assertSame('', Format::method(null));
        $this->assertSame('Voucher - ', Format::method('Voucher - '), 'no code, nothing to mask');
    }

    public function testHolderMasksOnlyAVoucherOnlyRecharge(): void
    {
        $this->assertSame('AB****GH', Format::holder('ABCDEFGH', 'Voucher - ABCDEFGH'), 'customer 0: the username IS the code');
        $this->assertSame('alice', Format::holder('alice', 'Voucher - ABCDEFGH'), 'a customer who redeemed a voucher keeps the name');
        $this->assertSame('alice', Format::holder('alice', 'Cash'));
        $this->assertSame('', Format::holder('', 'Voucher - '));
    }
}
