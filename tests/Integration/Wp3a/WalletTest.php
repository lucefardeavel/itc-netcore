<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\FinanceTestCase;

/** WP3A §3/§10: wallet lifecycle, read scope and the debt limit (an audited configuration change). */
final class WalletTest extends FinanceTestCase
{
    public function testAbsentWalletIsSynthesizedWithoutAnyWrite(): void
    {
        $id = $this->agent($this->sid('A'));
        $before = $this->checksums();
        $v = WalletService::view($this->ctx('ad'), $id);
        $this->assertFalse($v->exists);
        $this->assertSame(['manager_id' => $id, 'exists' => false, 'balance' => '0.00', 'debt_limit' => '0.00', 'reserved' => '0.00', 'available' => '0.00', 'updated_at' => null], $v->toArray());
        $this->assertSame($v->toArray(), WalletService::view($this->ctx('A'), $id)->toArray(), 'own subtree (T) sees it too');
        $this->assertSame($before, $this->checksums(), 'a read never creates a wallet');
        $this->assertNull($this->wallet($id));
    }

    public function testFirstFundingCreatesTheWalletWithInitialZeroState(): void
    {
        $id = $this->agent();
        $r = TransferService::fund($this->ctx('ad'), $id, '150.25', 'opening balance', self::ref());
        $w = $this->wallet($id);
        $this->assertSame('150.25', $w['balance']);
        $this->assertSame('0.00', $w['debt_limit']);
        $entries = $this->ledger($id);
        $this->assertCount(1, $entries);
        $this->assertSame('0.00', $entries[0]['balance_before'], 'the wallet starts at zero');
        $this->assertSame('150.25', $entries[0]['balance_after']);
        $this->assertSame("trf:{$r->transferId}:credit", $entries[0]['entry_key']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}$/', (string) $w['created_at']);
    }

    public function testDebtLimitZeroOnAbsentWalletIsANoOp(): void
    {
        $id = $this->agent();
        $before = $this->checksums();
        $v = WalletService::setDebtLimit($this->ctx('ad'), $id, '0', 'no credit line');
        $this->assertFalse($v->exists);
        $this->assertSame($before, $this->checksums(), 'no wallet, no audit');
        $this->assertSame(0, $this->rowCount('tbl_reseller_audit_events', "event_type = 'finance.debt_limit' AND target_user_id = ?", [$id]));
    }

    public function testPositiveDebtLimitCreatesTheWalletWithoutLedgerEntry(): void
    {
        $id = $this->agent();
        $v = WalletService::setDebtLimit($this->ctx('ad'), $id, '500', 'credit line for a trusted reseller');
        $this->assertTrue($v->exists);
        $this->assertSame('500.00', $v->available()->format());
        $this->assertSame(['0.00', '500.00'], [$this->wallet($id)['balance'], $this->wallet($id)['debt_limit']]);
        $this->assertSame([], $this->ledger($id), 'a debt limit does not move value: no ledger entry');
        $audit = static::$db->rows("SELECT * FROM tbl_reseller_audit_events WHERE event_type = 'finance.debt_limit' AND target_user_id = ?", [$id]);
        $this->assertCount(1, $audit);
        $after = json_decode((string) $audit[0]['after_json'], true);
        $this->assertSame(['500.00', '500.00', true], [$after['debt_limit'], $after['available'], $after['wallet_created']]);
        $this->assertSame('credit line for a trusted reseller', $audit[0]['reason']);
        $this->assertSame((int) $this->sid('ad'), (int) $audit[0]['actor_user_id']);
        // same value again: no-op, no second audit row
        WalletService::setDebtLimit($this->ctx('ad'), $id, '500.00', 'unchanged');
        $this->assertSame(1, $this->rowCount('tbl_reseller_audit_events', "event_type = 'finance.debt_limit' AND target_user_id = ?", [$id]));
    }

    /** available = balance + debt_limit - ACTIVE reservations; a decrease may not invalidate exposure. */
    public function testDebtLimitIncreaseAndDecreaseAgainstExactExposure(): void
    {
        $id = $this->funded('100');
        WalletService::setDebtLimit($this->ctx('ad'), $id, '50', 'increase');
        $this->assertSame('150.00', WalletService::view($this->ctx('ad'), $id)->available()->format());
        $act = $this->activation($id);
        ReservationService::reserve($act, $id, Money::parsePositive('120'));
        $v = WalletService::view($this->ctx('ad'), $id);
        $this->assertSame(['100.00', '50.00', '120.00', '30.00'], [$v->balance->format(), $v->debtLimit->format(), $v->reserved->format(), $v->available()->format()]);
        // exposure is 120 against a balance of 100: the limit may drop to exactly 20.00 but not 19.99
        $this->refused('DEBT_LIMIT_EXPOSURE', fn() => WalletService::setDebtLimit($this->ctx('ad'), $id, '19.99', 'too low'));
        $v = WalletService::setDebtLimit($this->ctx('ad'), $id, '20', 'exact boundary');
        $this->assertSame('0.00', $v->available()->format());
        $this->assertSame('20.00', $this->wallet($id)['debt_limit']);
        // increase after the boundary is always allowed
        $this->assertSame('5.00', WalletService::setDebtLimit($this->ctx('ad'), $id, '25', 'raise')->available()->format());
    }

    public function testDebtLimitInputAndTargetRules(): void
    {
        $id = $this->funded('10');
        foreach (['-1', '1.001', 'NaN', '1e2', ' 5'] as $bad) {
            $this->refused('INVALID_AMOUNT', fn() => WalletService::setDebtLimit($this->ctx('ad'), $id, $bad, 'bad limit'));
        }
        $this->refused('INVALID_REASON', fn() => WalletService::setDebtLimit($this->ctx('ad'), $id, '5', '  '));
        $this->refused('WALLET_OWNER_INVALID', fn() => WalletService::setDebtLimit($this->ctx('ad'), $this->sid('A1'), '5', 'sales target'));
        $this->refused('WALLET_OWNER_INVALID', fn() => WalletService::setDebtLimit($this->ctx('ad'), $this->sid('rp'), '5', 'report target'));
        $this->refused('WALLET_OWNER_INVALID', fn() => WalletService::setDebtLimit($this->ctx('ad'), $this->sid('ad'), '5', 'admin target'));
        $this->refused('WALLET_OWNER_INVALID', fn() => WalletService::setDebtLimit($this->ctx('ad'), $this->sid('E'), '5', 'not enrolled'));
        $this->refused('WALLET_OWNER_FROZEN', fn() => WalletService::setDebtLimit($this->ctx('ad'), $this->sid('D'), '5', 'inactive'));
        $this->refused('WALLET_OWNER_FROZEN', fn() => WalletService::setDebtLimit($this->ctx('ad'), $this->sid('F'), '5', 'frozen by ancestor'));
        $this->refused('NOT_FOUND', fn() => WalletService::setDebtLimit($this->ctx('ad'), 99999999, '5', 'missing'));
        $this->refused('FORBIDDEN', fn() => WalletService::setDebtLimit($this->ctx('A'), $this->sid('B'), '5', 'agent cannot'));
        $this->refused('FORBIDDEN', fn() => WalletService::setDebtLimit($this->ctx('A1'), $this->sid('A'), '5', 'sales cannot'));
        // frozen owner: blocked even for a decrease to zero (P4)
        $this->setStatus($id, 'Inactive');
        $this->refused('WALLET_OWNER_FROZEN', fn() => WalletService::setDebtLimit($this->ctx('ad'), $id, '0', 'blocked'));
        $this->setStatus($id, 'Active');
    }

    /** P3: Agents see own + subtree; never siblings, ancestors or unrelated trees. Sales/Report: nothing. */
    public function testReadScope(): void
    {
        foreach (['A', 'B', 'B2', 'G', 'C'] as $u) {
            if ($this->wallet($this->sid($u)) === null) {
                TransferService::fund($this->ctx('ad'), $this->sid($u), '1', 'scope fixture', self::ref());
            }
        }
        $b = $this->ctx('B');
        WalletService::view($b, $this->sid('B'));
        WalletService::view($b, $this->sid('G'));
        foreach (['A', 'B2', 'C', 'A1', 'ad'] as $u) {
            $this->refused('NOT_FOUND', fn() => WalletService::view($b, $this->sid($u)), false);
        }
        $this->refused('NOT_FOUND', fn() => WalletService::view($b, 99999999), false);
        $this->refused('NOT_FOUND', fn() => WalletService::view($b, '1 OR 1=1'), false);
        $listed = array_column(WalletService::listScoped($this->ctx('A')), 'manager_id');
        foreach (['A', 'B', 'B2', 'G'] as $u) {
            $this->assertContains($this->sid($u), $listed);
        }
        $this->assertNotContains($this->sid('C'), $listed);
        $this->assertNotContains($this->sid('A1'), $listed, 'Sales are never wallet owners');
        $this->assertContains($this->sid('C'), array_column(WalletService::listScoped($this->ctx('ad')), 'manager_id'));
        foreach (['A1', 'rp'] as $u) {
            $this->refused('FORBIDDEN', fn() => WalletService::view($this->ctx($u), $this->sid('A')), false);
            $this->refused('FORBIDDEN', fn() => WalletService::listScoped($this->ctx($u)), false);
        }
        // a non-enrolled Agent has an empty tree scope; a frozen Agent keeps read visibility
        $this->refused('FORBIDDEN', fn() => WalletService::view($this->ctx('E'), $this->sid('E')), false);
        $this->assertSame(0, WalletService::view($this->ctx('F'), $this->sid('F'))->balance->minor);
    }
}
