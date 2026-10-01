<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\FinanceActor;
use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\FinanceTestCase;

/**
 * Owner money-model correction against the real engines: intermediates (balance + debt_limit,
 * SUM(ACTIVE reservations), available) may exceed one DECIMAL(18,2) column; only persisted values are
 * bounded, and an out-of-range write is refused before SQL.
 */
final class MoneyBoundaryTest extends FinanceTestCase
{
    private const MAX = '9999999999999999.99';

    public function testMaxBalancePlusMaxDebtAndReservationSumBeyondOneColumn(): void
    {
        $id = $this->agent();
        TransferService::fund($this->ctx('ad'), $id, self::MAX, 'maximum funding', self::ref());
        WalletService::setDebtLimit($this->ctx('ad'), $id, self::MAX, 'maximum credit line');
        $v = WalletService::view($this->ctx('ad'), $id);
        $this->assertSame([self::MAX, self::MAX, '0.00', '19999999999999999.98'], [$v->balance->format(), $v->debtLimit->format(), $v->reserved->format(), $v->available()->format()]);
        $max = Money::parsePositive(self::MAX);
        $r1 = ReservationService::reserve($this->activation($id), $id, $max);
        $r2 = ReservationService::reserve($this->activation($id), $id, $max);
        $v = WalletService::view($this->ctx('ad'), $id);
        $this->assertSame('19999999999999999.98', $v->reserved->format(), 'SUM(ACTIVE) beyond one column is parsed exactly');
        $this->assertSame('0.00', $v->available()->format());
        $this->assertSame('19999999999999999.98', (string) static::$db->value("SELECT SUM(amount) FROM tbl_manager_wallet_reservations WHERE manager_id = ? AND status = 'ACTIVE'", [$id]));
        $extra = $this->activation($id);
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => ReservationService::reserve($extra, $id, Money::parsePositive('0.01')));
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::withdraw($this->ctx('ad'), $id, '0.01', 'nothing available', self::ref()));
        $this->refused('DEBT_LIMIT_EXPOSURE', fn() => WalletService::setDebtLimit($this->ctx('ad'), $id, '9999999999999999.98', 'cut by one cent'));
        // settling both maximum reservations reaches exactly -MAX (still storable)
        ReservationService::commit($r1->reservationId, FinanceActor::system('activation-service'));
        ReservationService::commit($r2->reservationId, FinanceActor::system('activation-service'));
        $this->assertSame('-' . self::MAX, $this->balance($id));
        $this->assertSame(['0.00', '-' . self::MAX], array_column(array_slice($this->ledger($id), 1), 'balance_after'));
    }

    public function testCreditBeyondStorageIsRejectedBeforeSql(): void
    {
        $id = $this->agent();
        TransferService::fund($this->ctx('ad'), $id, self::MAX, 'maximum funding', self::ref());
        $this->refused('STORAGE_RANGE_EXCEEDED', fn() => TransferService::fund($this->ctx('ad'), $id, '0.01', 'one cent too many', self::ref()));
        $this->refused('STORAGE_RANGE_EXCEEDED', fn() => TransferService::adjustCredit($this->ctx('ad'), $id, '0.01', 'one cent too many', self::ref()));
        $this->refused('INVALID_AMOUNT', fn() => TransferService::fund($this->ctx('ad'), $id, '10000000000000000', 'beyond the column', self::ref()));
        $this->refused('INVALID_AMOUNT', fn() => WalletService::setDebtLimit($this->ctx('ad'), $id, '10000000000000000.00', 'beyond the column'));
        $this->assertSame(self::MAX, $this->balance($id));
    }

    /** A reversal credit that would overflow the original source is atomic as well. */
    public function testReversalCreditOverflowIsAtomic(): void
    {
        $p = $this->funded('10');
        $c = $this->agent($p);
        $t = TransferService::transferToChild($this->ctx($p), $p, $c, '10', 'stock', self::ref());
        TransferService::fund($this->ctx('ad'), $p, self::MAX, 'refill to the maximum', self::ref());
        $before = $this->checksums();
        $this->refused('STORAGE_RANGE_EXCEEDED', fn() => TransferService::reverse($this->ctx('ad'), $t->transferId, 'would overflow', self::ref()));
        $this->assertSame($before, $this->checksums());
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_manager_transfers WHERE id = ?', [$t->transferId]));
    }
}
