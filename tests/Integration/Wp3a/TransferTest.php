<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\FinanceTestCase;

/** WP3A §9: parent reseller -> direct child reseller (P2: Admin/SuperAdmin under the same rule). */
final class TransferTest extends FinanceTestCase
{
    public function testDirectChildTransferIsBalancedAndAudited(): void
    {
        $p = $this->funded('200');
        $c = $this->agent($p);
        $ref = self::ref('TRF');
        $r = TransferService::transferToChild($this->ctx($p), $p, $c, '75.30', 'stock for my sub-reseller', $ref);
        $this->assertSame(['PARENT_CHILD_TRANSFER', $p, $c, 'COMPLETED', '75.30'], [$r->operationType, $r->fromManagerId, $r->toManagerId, $r->status, $r->amount->format()]);
        $this->assertSame(['124.70', '75.30'], [$this->balance($p), $this->balance($c)]);
        $out = $this->ledger($p)[1];
        $in = $this->ledger($c)[0];
        $this->assertSame(['TRANSFER_OUT', 'DEBIT', $c, $r->transferId, "trf:{$r->transferId}:out"], [$out['entry_type'], $out['direction'], (int) $out['counterparty_manager_id'], (int) $out['transfer_id'], $out['entry_key']]);
        $this->assertSame(['TRANSFER_IN', 'CREDIT', $p, $r->transferId, "trf:{$r->transferId}:in"], [$in['entry_type'], $in['direction'], (int) $in['counterparty_manager_id'], (int) $in['transfer_id'], $in['entry_key']]);
        $this->assertSame($out['amount'], $in['amount'], 'exact conservation');
        $this->assertSame([$p, $p], [(int) $out['operator_id'], (int) $in['operator_id']]);
        $this->assertSame('0.00', $in['balance_before'], 'the destination wallet was created by the transfer');
        $audit = static::$db->rows("SELECT * FROM tbl_reseller_audit_events WHERE event_type = 'finance.transfer' AND actor_user_id = ?", [$p]);
        $this->assertCount(1, $audit);
        $snap = json_decode((string) $audit[0]['target_snapshot'], true);
        $this->assertSame([$p, $c], [$snap['source']['id'], $snap['destination']['id']]);
        $after = json_decode((string) $audit[0]['after_json'], true);
        $this->assertSame([$ref, '75.30'], [$after['reference'], $after['amount']]);
        $this->assertCount(2, $after['wallets']);
    }

    /** Siblings, grandchildren, ancestors, Sales, customers, unrelated trees and self are refused. */
    public function testStructuralRefusals(): void
    {
        foreach (['A', 'B', 'B2', 'G', 'C'] as $u) {
            if ($this->wallet($this->sid($u)) === null) {
                TransferService::fund($this->ctx('ad'), $this->sid($u), '100', 'fixture', self::ref());
            }
        }
        $b = $this->ctx('B');
        $cases = [
            'sibling' => [$b, 'B', 'B2', 'NOT_FOUND'],
            'ancestor' => [$b, 'B', 'A', 'NOT_FOUND'],
            'unrelated tree' => [$b, 'B', 'C', 'NOT_FOUND'],
            'self' => [$b, 'B', 'B', 'SELF_TRANSFER'],
            'grandchild' => [$this->ctx('A'), 'A', 'G', 'NOT_FOUND'],
            'Sales child' => [$this->ctx('A'), 'A', 'A1', 'WALLET_OWNER_INVALID'],
            'forged source' => [$b, 'A', 'B2', 'NOT_FOUND'],
            'forged source own child' => [$b, 'A', 'B', 'NOT_FOUND'],
            'admin sibling' => [$this->ctx('ad'), 'B', 'B2', 'NOT_DIRECT_CHILD'],
            'admin grandchild' => [$this->ctx('ad'), 'A', 'G', 'NOT_DIRECT_CHILD'],
            'admin upward' => [$this->ctx('ad'), 'G', 'B', 'NOT_DIRECT_CHILD'],
            'admin unrelated' => [$this->ctx('sa'), 'A', 'C', 'NOT_DIRECT_CHILD'],
            'admin Sales' => [$this->ctx('ad'), 'A', 'A1', 'WALLET_OWNER_INVALID'],
            'admin self' => [$this->ctx('ad'), 'A', 'A', 'SELF_TRANSFER'],
        ];
        foreach ($cases as $label => [$actor, $from, $to, $reason]) {
            $this->refused($reason, fn() => TransferService::transferToChild($actor, $this->sid($from), $this->sid($to), '1', $label, self::ref()));
        }
        // customer id as target: customers are never wallet owners
        $this->refused('NOT_DIRECT_CHILD', fn() => TransferService::transferToChild($this->ctx('ad'), $this->sid('A'), $this->cid('cA1'), '1', 'customer id', self::ref()));
        $this->refused('NOT_DIRECT_CHILD', fn() => TransferService::transferToChild($this->ctx('ad'), $this->sid('A'), 99999999, '1', 'missing id', self::ref()));
        $this->refused('NOT_FOUND', fn() => TransferService::transferToChild($this->ctx('B'), $this->sid('B'), $this->cid('cB') + 500000, '1', 'customer id', self::ref()));
        // Sales and Report hold no transfer capability
        foreach (['A1', 'rp'] as $u) {
            $this->refused('FORBIDDEN', fn() => TransferService::transferToChild($this->ctx($u), $this->sid('A'), $this->sid('B'), '1', 'denied role', self::ref()));
        }
        // a non-enrolled Agent has no tree scope
        $this->refused('FORBIDDEN', fn() => TransferService::transferToChild($this->ctx('E'), $this->sid('E'), $this->sid('E1'), '1', 'not enrolled', self::ref()));
    }

    public function testInactiveOrFrozenSourceOrTargetIsRefused(): void
    {
        $p = $this->funded('50');
        $c = $this->agent($p);
        $g = $this->agent($c);
        TransferService::fund($this->ctx('ad'), $c, '50', 'fixture', self::ref());
        // inactive target
        $this->setStatus($c, 'Inactive');
        $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::transferToChild($this->ctx($p), $p, $c, '1', 'inactive target', self::ref()));
        $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::transferToChild($this->ctx('ad'), $p, $c, '1', 'inactive target', self::ref()));
        // the frozen (inactive) Agent cannot transfer: PermissionService refuses the mutating capability
        $this->refused('FORBIDDEN', fn() => TransferService::transferToChild($this->ctx($c), $c, $g, '1', 'inactive source', self::ref()));
        // Admin cannot move value out of / into a frozen subtree either
        $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::transferToChild($this->ctx('ad'), $c, $g, '1', 'frozen source', self::ref()));
        $this->setStatus($c, 'Active');
        // inactive source
        $this->setStatus($p, 'Inactive');
        $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::transferToChild($this->ctx('ad'), $p, $c, '1', 'inactive source', self::ref()));
        // frozen by ancestor: c's parent p is inactive
        $this->refused('FORBIDDEN', fn() => TransferService::transferToChild($this->ctx($c), $c, $g, '1', 'frozen by ancestor', self::ref()));
        $this->refused('WALLET_OWNER_FROZEN', fn() => TransferService::transferToChild($this->ctx('ad'), $c, $g, '1', 'frozen by ancestor', self::ref()));
        $this->setStatus($p, 'Active');
        // the frozen Agent's context is re-validated inside the transaction (stale context)
        $stale = $this->ctx($c);
        $this->setStatus($c, 'Inactive');
        $this->refused('ACTOR_FROZEN', fn() => TransferService::transferToChild($stale, $c, $g, '1', 'stale context', self::ref()));
        $this->setStatus($c, 'Active');
        TransferService::transferToChild($this->ctx($c), $c, $g, '1', 'now allowed', self::ref());
        $this->assertSame('1.00', $this->balance($g));
    }

    public function testAvailableCreditBoundaryIncludesReservationsAndDebtLine(): void
    {
        $p = $this->funded('100');
        $c = $this->agent($p);
        WalletService::setDebtLimit($this->ctx('ad'), $p, '10', 'credit line');
        ReservationService::reserve($this->activation($p), $p, Money::parsePositive('40'));
        // available = 100 + 10 - 40 = 70
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::transferToChild($this->ctx($p), $p, $c, '70.01', 'over', self::ref()));
        $this->assertNull($this->wallet($c), 'the destination wallet is not created by a refused transfer');
        TransferService::transferToChild($this->ctx($p), $p, $c, '70', 'exact', self::ref());
        $this->assertSame(['30.00', '70.00'], [$this->balance($p), $this->balance($c)]);
        $this->assertSame('0.00', WalletService::view($this->ctx('ad'), $p)->available()->format());
        // a parent without a wallet cannot transfer
        $q = $this->agent();
        $qc = $this->agent($q);
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::transferToChild($this->ctx($q), $q, $qc, '1', 'no wallet', self::ref()));
    }

    public function testAdminTransferUsesTheSameRuleAndRecordsTheOperator(): void
    {
        $p = $this->funded('20');
        $c = $this->agent($p);
        $r = TransferService::transferToChild($this->ctx('ad'), $p, $c, '5', 'admin rebalancing', self::ref());
        $t = static::$db->rows('SELECT * FROM tbl_manager_transfers WHERE id = ?', [$r->transferId])[0];
        $this->assertSame([$this->sid('ad'), $p, $c], [(int) $t['operator_id'], (int) $t['from_manager_id'], (int) $t['to_manager_id']]);
        foreach ($this->ledger($c) as $e) {
            $this->assertSame($this->sid('ad'), (int) $e['operator_id']);
        }
    }

    public function testIdempotentTransferReplayAndConflict(): void
    {
        $p = $this->funded('100');
        $c = $this->agent($p);
        $c2 = $this->agent($p);
        $ref = self::ref('TRFI');
        $first = TransferService::transferToChild($this->ctx($p), $p, $c, '10', 'idempotent transfer', $ref);
        $replay = TransferService::transferToChild($this->ctx($p), $p, $c, '10.00', 'idempotent transfer', $ref);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->transferId, $replay->transferId);
        $this->assertSame(['90.00', '10.00'], [$this->balance($p), $this->balance($c)], 'no second effect');
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => TransferService::transferToChild($this->ctx($p), $p, $c2, '10', 'idempotent transfer', $ref));
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => TransferService::transferToChild($this->ctx($p), $p, $c, '11', 'idempotent transfer', $ref));
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => TransferService::transferToChild($this->ctx($p), $p, $c, '10', 'other reason', $ref));
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => TransferService::transferToChild($this->ctx('ad'), $p, $c, '10', 'idempotent transfer', $ref));
    }

    /** Owner money test: overflow at the destination is rejected atomically before any SQL write. */
    public function testDestinationStorageOverflowIsAtomic(): void
    {
        $max = '9999999999999999.99';
        $p = $this->funded('10');
        $c = $this->funded($max, $p);
        $before = $this->checksums();
        $this->refused('STORAGE_RANGE_EXCEEDED', fn() => TransferService::transferToChild($this->ctx($p), $p, $c, '0.01', 'overflow', self::ref()));
        $this->assertSame($before, $this->checksums());
        $this->assertSame(['10.00', $max], [$this->balance($p), $this->balance($c)]);
    }
}
