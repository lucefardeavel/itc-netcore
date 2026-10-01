<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\FinanceTestCase;
use Reseller\Tests\Support\TestDatabase;

/** WP3A §8/§14/§17: provider funding and withdrawal, idempotency and operator audit. */
final class FundingTest extends FinanceTestCase
{
    public function testProviderFundingIsOneOperationOneCreditAndOneAudit(): void
    {
        $id = $this->agent();
        $ref = self::ref('FUND');
        $r = TransferService::fund($this->ctx('sa'), $id, '1000', 'bank transfer received', $ref, 'BANK-TX-7781');
        $this->assertFalse($r->replayed);
        $this->assertSame(['PROVIDER_FUNDING', 'COMPLETED', '1000.00', null, $id], [$r->operationType, $r->status, $r->amount->format(), $r->fromManagerId, $r->toManagerId]);
        $t = static::$db->rows('SELECT * FROM tbl_manager_transfers WHERE id = ?', [$r->transferId])[0];
        $this->assertNull($t['from_manager_id'], 'no fake provider/root wallet');
        $this->assertSame([$ref, 'bank transfer received', 'BANK-TX-7781', $this->sid('sa')], [$t['reference'], $t['reason'], $t['external_reference'], (int) $t['operator_id']]);
        $this->assertNotNull($t['completed_at']);
        $e = $this->ledger($id);
        $this->assertCount(1, $e);
        $this->assertSame(['FUNDING', 'CREDIT', '1000.00', 'STAFF', $this->sid('sa'), null], [$e[0]['entry_type'], $e[0]['direction'], $e[0]['amount'], $e[0]['actor_type'], (int) $e[0]['operator_id'], $e[0]['counterparty_manager_id']]);
        $this->assertSame([(int) $e[0]['id']], $r->ledgerEntryIds);
        $audit = $this->auditFor('finance.fund', $r->transferId);
        $this->assertSame('bank transfer received', $audit['reason']);
        $after = json_decode((string) $audit['after_json'], true);
        $this->assertSame([$ref, '1000.00', 'PROVIDER_FUNDING', null, $id], [$after['reference'], $after['amount'], $after['operation_type'], $after['from_manager_id'], $after['to_manager_id']]);
        $this->assertSame(['0.00', '1000.00', true], [json_decode((string) $audit['before_json'], true)['wallets'][0]['balance'], $after['wallets'][0]['balance'], $after['wallets'][0]['wallet_created']]);
        $this->assertSame(['sa', 'SuperAdmin'], [json_decode((string) $audit['actor_snapshot'], true)['username'], json_decode((string) $audit['actor_snapshot'], true)['role']]);
        $this->assertSame([$this->sid('sa'), $id, 'wallet'], [(int) $audit['actor_user_id'], (int) $audit['target_user_id'], $audit['target_type']]);
        $snap = json_decode((string) $audit['target_snapshot'], true);
        $this->assertSame([$id, 'Agent'], [$snap['id'], $snap['user_type']]);
        $this->assertSecretFree($audit);
    }

    public function testWithdrawalBoundaryCountsActiveReservations(): void
    {
        $id = $this->funded('100');
        WalletService::setDebtLimit($this->ctx('ad'), $id, '20', 'credit line');
        ReservationService::reserve($this->activation($id), $id, Money::parsePositive('30'));
        // available = 100 + 20 - 30 = 90
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::withdraw($this->ctx('ad'), $id, '90.01', 'too much', self::ref()));
        $r = TransferService::withdraw($this->ctx('ad'), $id, '90.00', 'exact boundary', self::ref());
        $this->assertSame('COMPLETED', $r->status);
        $v = WalletService::view($this->ctx('ad'), $id);
        $this->assertSame(['10.00', '0.00'], [$v->balance->format(), $v->available()->format()], 'P5: AVAILABLE >= 0, the debt line is real credit');
        $e = $this->ledger($id);
        $this->assertSame(['WITHDRAWAL', 'DEBIT', '100.00', '10.00'], [end($e)['entry_type'], end($e)['direction'], end($e)['balance_before'], end($e)['balance_after']]);
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::withdraw($this->ctx('ad'), $id, '0.01', 'nothing left', self::ref()));
        $this->assertSame(1, $this->rowCount('tbl_reseller_audit_events', "event_type = 'finance.withdraw' AND target_user_id = ?", [$id]));
    }

    public function testWithdrawalCanDrawIntoTheDebtLine(): void
    {
        $id = $this->funded('10');
        WalletService::setDebtLimit($this->ctx('ad'), $id, '40', 'credit line');
        TransferService::withdraw($this->ctx('ad'), $id, '50', 'draw into credit', self::ref());
        $this->assertSame('-40.00', $this->balance($id), 'balance may be negative down to -debt_limit');
        $this->refused('DEBT_LIMIT_EXPOSURE', fn() => WalletService::setDebtLimit($this->ctx('ad'), $id, '39.99', 'cannot cut below the debt'));
    }

    public function testInsufficientCreditOnAbsentWalletWritesNothing(): void
    {
        $id = $this->agent();
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::withdraw($this->ctx('ad'), $id, '0.01', 'no wallet', self::ref()));
        $this->assertNull($this->wallet($id), 'a debit never creates a wallet');
    }

    /** Same reference + same canonical payload = replay; any different field = IDEMPOTENCY_CONFLICT. */
    public function testIdempotencyReplayAndConflictPerField(): void
    {
        $id = $this->agent();
        $other = $this->agent();
        $ref = self::ref('IDEM');
        $first = TransferService::fund($this->ctx('ad'), $id, '25.50', 'idempotent funding', $ref, 'EXT-1');
        $before = $this->checksums();
        $again = TransferService::fund($this->ctx('ad'), $id, '25.5', 'idempotent funding', $ref, 'EXT-1');
        $this->assertTrue($again->replayed);
        $this->assertSame($first->transferId, $again->transferId);
        $this->assertSame($first->ledgerEntryIds, $again->ledgerEntryIds);
        $this->assertSame($before, $this->checksums(), 'a replay writes nothing (no second effect, no audit)');
        $this->assertSame('25.50', $this->balance($id));
        $variants = [
            'amount' => fn() => TransferService::fund($this->ctx('ad'), $id, '25.51', 'idempotent funding', $ref, 'EXT-1'),
            'target' => fn() => TransferService::fund($this->ctx('ad'), $other, '25.50', 'idempotent funding', $ref, 'EXT-1'),
            'reason' => fn() => TransferService::fund($this->ctx('ad'), $id, '25.50', 'different reason', $ref, 'EXT-1'),
            'external' => fn() => TransferService::fund($this->ctx('ad'), $id, '25.50', 'idempotent funding', $ref, 'EXT-2'),
            'no external' => fn() => TransferService::fund($this->ctx('ad'), $id, '25.50', 'idempotent funding', $ref),
            'operator' => fn() => TransferService::fund($this->ctx('sa'), $id, '25.50', 'idempotent funding', $ref, 'EXT-1'),
            'operation' => fn() => TransferService::withdraw($this->ctx('ad'), $id, '25.50', 'idempotent funding', $ref, 'EXT-1'),
            'adjustment' => fn() => TransferService::adjustCredit($this->ctx('ad'), $id, '25.50', 'idempotent funding', $ref, 'EXT-1'),
            'reference case' => fn() => TransferService::fund($this->ctx('ad'), $id, '25.50', 'idempotent funding', strtolower($ref), 'EXT-1'),
        ];
        foreach ($variants as $field => $fn) {
            $this->refused('IDEMPOTENCY_CONFLICT', $fn);
        }
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => TransferService::reverse($this->ctx('ad'), $first->transferId, 'idempotent funding', $ref));
    }

    public function testFundingTargetsAndPermissions(): void
    {
        $this->refused('FORBIDDEN', fn() => TransferService::fund($this->ctx('A'), $this->sid('B'), '1', 'agent funding', self::ref()));
        $this->refused('FORBIDDEN', fn() => TransferService::withdraw($this->ctx('A'), $this->sid('B'), '1', 'agent withdrawal', self::ref()));
        foreach (['A1', 'rp', 'E'] as $u) {
            $this->refused('FORBIDDEN', fn() => TransferService::fund($this->ctx($u), $this->sid('A'), '1', 'not allowed', self::ref()));
        }
        foreach (['A1' => 'WALLET_OWNER_INVALID', 'rp' => 'WALLET_OWNER_INVALID', 'sa' => 'WALLET_OWNER_INVALID', 'E' => 'WALLET_OWNER_INVALID',
            'D' => 'WALLET_OWNER_FROZEN', 'F' => 'WALLET_OWNER_FROZEN'] as $u => $reason) {
            $this->refused($reason, fn() => TransferService::fund($this->ctx('ad'), $this->sid($u), '1', 'invalid target', self::ref()));
        }
        $this->refused('NOT_FOUND', fn() => TransferService::fund($this->ctx('ad'), $this->cid('cA1') + 100000, '1', 'customer id', self::ref()));
        $this->refused('NOT_FOUND', fn() => TransferService::fund($this->ctx('ad'), '0', '1', 'bad id', self::ref()));
        $this->refused('INVALID_REFERENCE', fn() => TransferService::fund($this->ctx('ad'), $this->sid('A'), '1', 'short ref', 'short'));
        $this->refused('INVALID_REFERENCE', fn() => TransferService::fund($this->ctx('ad'), $this->sid('A'), '1', 'bad ref', 'ref with space'));
        $this->refused('INVALID_REASON', fn() => TransferService::fund($this->ctx('ad'), $this->sid('A'), '1', 'ab', self::ref()));
        $this->refused('INVALID_REASON', fn() => TransferService::fund($this->ctx('ad'), $this->sid('A'), '1', str_repeat('r', 256), self::ref()));
        $this->refused('INVALID_INPUT', fn() => TransferService::fund($this->ctx('ad'), $this->sid('A'), '1', 'bad external', self::ref(), "x\ny"));
        $this->refused('INVALID_AMOUNT', fn() => TransferService::fund($this->ctx('ad'), $this->sid('A'), '-5', 'negative', self::ref()));
        $this->refused('INVALID_AMOUNT', fn() => TransferService::fund($this->ctx('ad'), $this->sid('A'), 5.0, 'float', self::ref()));
        // an inactive Admin cannot fund (PermissionService: mutating capability)
        $this->setStatus($this->sid('ad'), 'Inactive');
        try {
            $this->refused('FORBIDDEN', fn() => TransferService::fund($this->ctx('ad'), $this->sid('A'), '1', 'inactive admin', self::ref()));
        } finally {
            $this->setStatus($this->sid('ad'), 'Active');
        }
    }

    /** A refused reference stays usable (refusals persist nothing). */
    public function testRefusedReferenceIsReusable(): void
    {
        $id = $this->agent();
        $ref = self::ref('REUSE');
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => TransferService::withdraw($this->ctx('ad'), $id, '5', 'first attempt', $ref));
        $r = TransferService::fund($this->ctx('ad'), $id, '5', 'second attempt', $ref);
        $this->assertFalse($r->replayed);
        $this->assertSame($ref, $r->reference);
    }

    /** @return array<string,mixed> */
    private function auditFor(string $event, int $transferId): array
    {
        foreach (static::$db->rows('SELECT * FROM tbl_reseller_audit_events WHERE event_type = ? ORDER BY id', [$event]) as $row) {
            if ((json_decode((string) $row['after_json'], true)['transfer_id'] ?? null) === $transferId) {
                return $row;
            }
        }
        $this->fail("no {$event} audit for transfer {$transferId}");
    }

    private function assertSecretFree(array $row): void
    {
        $flat = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (static::$db->rows('SELECT password FROM tbl_users') as $u) {
            $this->assertStringNotContainsString((string) $u['password'], $flat);
        }
        $this->assertStringNotContainsString(TestDatabase::rootPassword(), $flat);
        $this->assertDoesNotMatchRegularExpression('/"[^"]*(pass|secret|token|otp|signature|salt)[^"]*"\s*:/i', $flat);
    }
}
