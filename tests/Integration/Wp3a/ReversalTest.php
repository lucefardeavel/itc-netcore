<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\ResellerTreeService;
use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\FinanceTestCase;

/**
 * WP3A §12 with owner decision P4 (modified) and the reversal-idempotency ordering: compensating
 * entries only, at most once, original counterparties, no wallet creation, current floor on debits.
 */
final class ReversalTest extends FinanceTestCase
{
    /** @return array<int,array<string,mixed>> ledger rows of a transfer keyed by id */
    private function entriesOf(int $transferId): array
    {
        $out = [];
        foreach (static::$db->rows('SELECT * FROM tbl_manager_ledger WHERE transfer_id = ? ORDER BY id', [$transferId]) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    public function testFundingReversal(): void
    {
        $id = $this->agent();
        $f = TransferService::fund($this->ctx('ad'), $id, '80', 'funded the wrong reseller', self::ref());
        $original = $this->entriesOf($f->transferId);
        $r = TransferService::reverse($this->ctx('sa'), $f->transferId, 'funding sent to the wrong reseller', self::ref('REV'));
        $this->assertSame(['PROVIDER_WITHDRAWAL', $id, null, $f->transferId, '80.00', 'COMPLETED'], [$r->operationType, $r->fromManagerId, $r->toManagerId, $r->reversesTransferId, $r->amount->format(), $r->status]);
        $this->assertSame('REVERSED', static::$db->value('SELECT status FROM tbl_manager_transfers WHERE id = ?', [$f->transferId]));
        $this->assertSame('0.00', $this->balance($id));
        $e = $this->entriesOf($r->transferId);
        $this->assertCount(1, $e);
        $rev = reset($e);
        $this->assertSame(['REVERSAL', 'DEBIT', array_key_first($original), "rev:{$r->transferId}:debit", $this->sid('sa')],
            [$rev['entry_type'], $rev['direction'], (int) $rev['reverses_entry_id'], $rev['entry_key'], (int) $rev['operator_id']]);
        $this->assertSame($original, $this->entriesOf($f->transferId), 'the original ledger rows are untouched');
        $audit = static::$db->rows("SELECT * FROM tbl_reseller_audit_events WHERE event_type = 'finance.reverse' AND target_user_id = ?", [$id]);
        $this->assertCount(1, $audit, 'audit is mandatory');
        $after = json_decode((string) $audit[0]['after_json'], true);
        $this->assertSame([$f->transferId, 'PROVIDER_FUNDING', $f->transferId], [$after['original_transfer_id'], $after['original_operation_type'], $after['reverses_transfer_id']]);
    }

    public function testWithdrawalAndAdjustmentReversals(): void
    {
        $id = $this->funded('100');
        $w = TransferService::withdraw($this->ctx('ad'), $id, '30', 'withdrawal', self::ref());
        $rw = TransferService::reverse($this->ctx('ad'), $w->transferId, 'withdrawal was not paid out', self::ref());
        $this->assertSame(['PROVIDER_FUNDING', null, $id], [$rw->operationType, $rw->fromManagerId, $rw->toManagerId]);
        $this->assertSame('100.00', $this->balance($id));
        $ac = TransferService::adjustCredit($this->ctx('ad'), $id, '5', 'credit correction', self::ref());
        $ad = TransferService::adjustDebit($this->ctx('ad'), $id, '7', 'debit correction', self::ref());
        $rac = TransferService::reverse($this->ctx('ad'), $ac->transferId, 'undo credit correction', self::ref());
        $rad = TransferService::reverse($this->ctx('ad'), $ad->transferId, 'undo debit correction', self::ref());
        $this->assertSame(['ADJUSTMENT_DEBIT', 'ADJUSTMENT_CREDIT'], [$rac->operationType, $rad->operationType]);
        $this->assertSame('100.00', $this->balance($id));
        foreach ([$rw, $rac, $rad] as $r) {
            foreach ($this->entriesOf($r->transferId) as $e) {
                $this->assertSame('REVERSAL', $e['entry_type']);
            }
        }
    }

    /** Historical parent-child reversal unwinds the ORIGINAL counterparties, even after a subtree move. */
    public function testTransferReversalAfterSubtreeMove(): void
    {
        $p = $this->funded('100');
        $c = $this->agent($p);
        $q = $this->agent();
        $t = TransferService::transferToChild($this->ctx($p), $p, $c, '40', 'stock', self::ref());
        ResellerTreeService::moveSubtree($this->ctx('ad'), $c, $q); // c is no longer p's child
        $r = TransferService::reverse($this->ctx('ad'), $t->transferId, 'transfer sent in error', self::ref());
        $this->assertSame(['PARENT_CHILD_TRANSFER', $c, $p, $t->transferId], [$r->operationType, $r->fromManagerId, $r->toManagerId, $r->reversesTransferId]);
        $this->assertSame(['100.00', '0.00'], [$this->balance($p), $this->balance($c)]);
        $byManager = [];
        foreach ($this->entriesOf($r->transferId) as $e) {
            $byManager[(int) $e['manager_id']] = $e;
        }
        $this->assertSame(['REVERSAL', 'DEBIT', $p], [$byManager[$c]['entry_type'], $byManager[$c]['direction'], (int) $byManager[$c]['counterparty_manager_id']]);
        $this->assertSame(['REVERSAL', 'CREDIT', $c], [$byManager[$p]['entry_type'], $byManager[$p]['direction'], (int) $byManager[$p]['counterparty_manager_id']]);
        // a normal child->parent transfer is impossible: the reversal is not reinterpreted as one
        $this->refused('NOT_DIRECT_CHILD', fn() => TransferService::transferToChild($this->ctx('ad'), $c, $p, '1', 'upward', self::ref()));
    }

    /** Owner ordering: the new reference first (lost-response replay), then ALREADY_REVERSED. */
    public function testReversalIdempotencyOrdering(): void
    {
        $id = $this->agent();
        $f = TransferService::fund($this->ctx('ad'), $id, '10', 'funding', self::ref());
        $ref = self::ref('REVO');
        $first = TransferService::reverse($this->ctx('ad'), $f->transferId, 'reverse it', $ref);
        $before = $this->checksums();
        $replay = TransferService::reverse($this->ctx('ad'), $f->transferId, 'reverse it', $ref);
        $this->assertSame([true, $first->transferId], [$replay->replayed, $replay->transferId]);
        $this->assertSame($before, $this->checksums(), 'a lost-response replay has no new effect');
        $this->refused('ALREADY_REVERSED', fn() => TransferService::reverse($this->ctx('ad'), $f->transferId, 'reverse it', self::ref('REVO')));
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => TransferService::reverse($this->ctx('ad'), $f->transferId, 'another reason', $ref));
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => TransferService::reverse($this->ctx('sa'), $f->transferId, 'reverse it', $ref));
        $g = TransferService::fund($this->ctx('ad'), $id, '10', 'funding 2', self::ref());
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => TransferService::reverse($this->ctx('ad'), $g->transferId, 'reverse it', $ref));
        // a reversal is never itself reversible
        $this->refused('NOT_REVERSIBLE', fn() => TransferService::reverse($this->ctx('ad'), $first->transferId, 'reverse the reversal', self::ref()));
        $this->refused('NOT_FOUND', fn() => TransferService::reverse($this->ctx('ad'), 99999999, 'missing', self::ref()));
    }

    public function testReversalBlockedWhenTheDebitSideCannotAffordIt(): void
    {
        $p = $this->funded('50');
        $c = $this->agent($p);
        $t = TransferService::transferToChild($this->ctx($p), $p, $c, '50', 'all of it', self::ref());
        ReservationService::reserve($this->activation($c), $c, Money::parsePositive('30'));
        // c: balance 50, reserved 30 -> available 20 < 50
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::reverse($this->ctx('ad'), $t->transferId, 'too late', self::ref()));
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_manager_transfers WHERE id = ?', [$t->transferId]));
        $f = TransferService::fund($this->ctx('ad'), $p, '5', 'fund', self::ref());
        TransferService::withdraw($this->ctx('ad'), $p, '5', 'withdraw', self::ref());
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::reverse($this->ctx('ad'), $f->transferId, 'funds consumed', self::ref()));
        // a smaller explicit correction within the current available credit remains possible
        TransferService::adjustDebit($this->ctx('ad'), $c, '20', 'partial correction', self::ref());
        $this->assertSame(['30.00', '0.00'], [$this->balance($c), WalletService::view($this->ctx('ad'), $c)->available()->format()]);
    }

    /** P4 (modified): inactive/frozen status does not block the administrative correction. */
    public function testReversalOnFrozenHistoricalCounterparty(): void
    {
        $p = $this->funded('100');
        $c = $this->agent($p);
        $t = TransferService::transferToChild($this->ctx($p), $p, $c, '25', 'stock', self::ref());
        $f = TransferService::fund($this->ctx('ad'), $c, '10', 'direct funding', self::ref());
        $this->setStatus($p, 'Inactive'); // p inactive, c frozen through p
        try {
            $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::fund($this->ctx('ad'), $c, '1', 'blocked business op', self::ref()));
            $r1 = TransferService::reverse($this->ctx('ad'), $t->transferId, 'correct a historical transfer', self::ref());
            $r2 = TransferService::reverse($this->ctx('sa'), $f->transferId, 'correct a historical funding', self::ref());
            $this->assertSame(['COMPLETED', 'COMPLETED'], [$r1->status, $r2->status]);
            $this->assertSame(['100.00', '0.00'], [$this->balance($p), $this->balance($c)]);
        } finally {
            $this->setStatus($p, 'Active');
        }
        // Agents never reverse (Admin/SuperAdmin only); the exception is not generalized
        $t2 = TransferService::transferToChild($this->ctx($p), $p, $c, '1', 'stock', self::ref());
        foreach (['A', 'A1', 'rp'] as $u) {
            $this->refused('FORBIDDEN', fn() => TransferService::reverse($this->ctx($u), $t2->transferId, 'agent reversal', self::ref()));
        }
        $this->refused('FORBIDDEN', fn() => TransferService::reverse($this->ctx($p), $t2->transferId, 'parent reversal', self::ref()));
    }

    public function testReversalNeverCreatesAWallet(): void
    {
        $id = $this->agent();
        $f = TransferService::fund($this->ctx('ad'), $id, '10', 'funding', self::ref());
        $walletsBefore = $this->rowCount('tbl_manager_wallets');
        TransferService::reverse($this->ctx('ad'), $f->transferId, 'reverse', self::ref());
        $this->assertSame($walletsBefore, $this->rowCount('tbl_manager_wallets'));
    }
}
