<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\FinanceTestCase;

/**
 * Owner amendment: ADJUSTMENT_CREDIT / ADJUSTMENT_DEBIT correct an EXISTING financial account; they
 * never create a wallet (WALLET_NOT_FOUND) — provider funding makes the first credit.
 */
final class AdjustmentTest extends FinanceTestCase
{
    public function testAdjustmentsNeverCreateAWallet(): void
    {
        $id = $this->agent($this->sid('A'));
        $this->refused('WALLET_NOT_FOUND', fn() => TransferService::adjustCredit($this->ctx('ad'), $id, '10', 'correction', self::ref()));
        $this->refused('WALLET_NOT_FOUND', fn() => TransferService::adjustDebit($this->ctx('ad'), $id, '10', 'correction', self::ref()));
        $this->assertNull($this->wallet($id));
        // provider funding creates it; then adjustments are possible
        TransferService::fund($this->ctx('ad'), $id, '10', 'first credit', self::ref());
        TransferService::adjustCredit($this->ctx('ad'), $id, '2.50', 'commission correction', self::ref());
        $this->assertSame('12.50', $this->balance($id));
    }

    public function testCreditAndDebitAdjustmentLedgerShape(): void
    {
        $id = $this->funded('100');
        $c = TransferService::adjustCredit($this->ctx('sa'), $id, '7.25', 'manual credit correction', self::ref('ADJ'));
        $d = TransferService::adjustDebit($this->ctx('ad'), $id, '17.25', 'manual debit correction', self::ref('ADJ'));
        $this->assertSame(['ADJUSTMENT_CREDIT', null, $id], [$c->operationType, $c->fromManagerId, $c->toManagerId]);
        $this->assertSame(['ADJUSTMENT_DEBIT', $id, null], [$d->operationType, $d->fromManagerId, $d->toManagerId]);
        $e = $this->ledger($id);
        $this->assertSame(['ADJUSTMENT', 'CREDIT', '100.00', '107.25', "trf:{$c->transferId}:credit"], [$e[1]['entry_type'], $e[1]['direction'], $e[1]['balance_before'], $e[1]['balance_after'], $e[1]['entry_key']]);
        $this->assertSame(['ADJUSTMENT', 'DEBIT', '107.25', '90.00', "trf:{$d->transferId}:debit"], [$e[2]['entry_type'], $e[2]['direction'], $e[2]['balance_before'], $e[2]['balance_after'], $e[2]['entry_key']]);
        $this->assertSame(1, $this->rowCount('tbl_reseller_audit_events', "event_type = 'finance.adjust_credit' AND target_user_id = ?", [$id]));
        $this->assertSame(1, $this->rowCount('tbl_reseller_audit_events', "event_type = 'finance.adjust_debit' AND target_user_id = ?", [$id]));
    }

    public function testDebitAdjustmentObeysTheAvailableFloor(): void
    {
        $id = $this->funded('30');
        WalletService::setDebtLimit($this->ctx('ad'), $id, '5', 'small credit line');
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::adjustDebit($this->ctx('ad'), $id, '35.01', 'too much', self::ref()));
        TransferService::adjustDebit($this->ctx('ad'), $id, '35', 'exact', self::ref());
        $this->assertSame('-5.00', $this->balance($id));
    }

    public function testAdjustmentPermissionsAndFrozenOwner(): void
    {
        $id = $this->funded('10', $this->sid('A'));
        foreach (['A', 'A1', 'rp'] as $u) {
            $this->refused('FORBIDDEN', fn() => TransferService::adjustCredit($this->ctx($u), $id, '1', 'not allowed', self::ref()));
            $this->refused('FORBIDDEN', fn() => TransferService::adjustDebit($this->ctx($u), $id, '1', 'not allowed', self::ref()));
        }
        // P4: adjustment on a frozen reseller (inactive ancestor) stays blocked
        $this->setStatus($this->sid('A'), 'Inactive');
        try {
            $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::adjustCredit($this->ctx('ad'), $id, '1', 'frozen', self::ref()));
            $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::adjustDebit($this->ctx('ad'), $id, '1', 'frozen', self::ref()));
            $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::withdraw($this->ctx('ad'), $id, '1', 'frozen', self::ref()));
            $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::fund($this->ctx('ad'), $id, '1', 'frozen', self::ref()));
        } finally {
            $this->setStatus($this->sid('A'), 'Active');
        }
    }
}
