<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\DataScope;

/**
 * The reseller wallet ledger (WP3A, owner decision P1): the ONLY runtime writer of tbl_manager_ledger
 * and of tbl_manager_wallets.balance. Append-only — there is no update, delete or edit method;
 * corrections are compensating REVERSAL entries.
 *
 * Writes accept authoritative operation ids only. The entry key, entry type, direction, amount,
 * counterparty and actor are derived here from the locked transfer / reservation row; nothing is taken
 * from a browser or caller-supplied balance, key or actor id. Each entry is written in the caller's
 * transaction together with the wallet balance it produces:
 *   balance_before = the locked wallet balance, balance_after = balance_before ± amount (checked
 *   int64, stored only within DECIMAL(18,2)); the wallet update is a compare-and-set on balance_before.
 */
final class LedgerService
{
    public const PAGE_SIZE = 100;
    /** deterministic key "<kind>:<operation id>:<leg>" (wide enough for later WP3B kinds) */
    private const KEY = '/^[a-z][a-z0-9_]*:[1-9][0-9]*:[a-z][a-z0-9_]*$/D';

    /**
     * One leg of a funding / withdrawal / adjustment / parent-child transfer / reversal row.
     * debit applies to from_manager_id, credit to to_manager_id.
     */
    public static function postTransferLeg(\PDO $pdo, LockedWallet $w, int $transferId, string $side): PostedEntry
    {
        FinanceTx::assertInTransaction($pdo);
        if ($side !== 'debit' && $side !== 'credit') {
            throw new \LogicException('invalid ledger side');
        }
        $t = Tx::row($pdo, 'SELECT * FROM tbl_manager_transfers WHERE id = ? FOR UPDATE', [$transferId]);
        if ($t === null || $t['status'] !== 'COMPLETED') {
            throw new \LogicException('ledger leg without a completed transfer row');
        }
        $manager = (int) ($side === 'debit' ? $t['from_manager_id'] : $t['to_manager_id']);
        if ($manager <= 0 || $manager !== $w->managerId) {
            throw new \LogicException('ledger leg does not match the locked wallet');
        }
        $isTransfer = $t['operation_type'] === 'PARENT_CHILD_TRANSFER';
        $counterparty = $isTransfer ? (int) ($side === 'debit' ? $t['to_manager_id'] : $t['from_manager_id']) : null;
        $reverses = null;
        if ($t['reverses_transfer_id'] !== null) {
            $type = 'REVERSAL';
            $key = "rev:{$transferId}:{$side}";
            $orig = Tx::rows($pdo, 'SELECT id FROM tbl_manager_ledger WHERE transfer_id = ? AND manager_id = ?', [(int) $t['reverses_transfer_id'], $manager]);
            if (count($orig) !== 1) {
                throw new \LogicException('original ledger entry of the reversed operation not found');
            }
            $reverses = (int) $orig[0]['id'];
        } else {
            [$type, $leg] = match ($t['operation_type'] . '/' . $side) {
                'PROVIDER_FUNDING/credit' => ['FUNDING', 'credit'],
                'PROVIDER_WITHDRAWAL/debit' => ['WITHDRAWAL', 'debit'],
                'ADJUSTMENT_CREDIT/credit' => ['ADJUSTMENT', 'credit'],
                'ADJUSTMENT_DEBIT/debit' => ['ADJUSTMENT', 'debit'],
                'PARENT_CHILD_TRANSFER/debit' => ['TRANSFER_OUT', 'out'],
                'PARENT_CHILD_TRANSFER/credit' => ['TRANSFER_IN', 'in'],
                default => throw new \LogicException('invalid ledger leg for the operation type'),
            };
            $key = "trf:{$transferId}:{$leg}";
        }
        return self::insert($pdo, $w, [
            'entry_key' => $key,
            'entry_type' => $type,
            'direction' => $side === 'debit' ? 'DEBIT' : 'CREDIT',
            'amount' => Money::fromDb($t['amount']),
            'activation_id' => null,
            'transfer_id' => $transferId,
            'counterparty_manager_id' => $counterparty,
            'reverses_entry_id' => $reverses,
            'actor_type' => FinanceActor::STAFF,
            'operator_id' => (int) $t['operator_id'],
            'actor_customer_id' => null,
            'reason' => (string) $t['reason'],
        ]);
    }

    /** The exactly-once ACTIVATION_DEBIT of an ACTIVE reservation (amount = reserved amount). */
    public static function postActivationDebit(\PDO $pdo, LockedWallet $w, int $reservationId, FinanceActor $actor): PostedEntry
    {
        FinanceTx::assertInTransaction($pdo);
        $r = Tx::row($pdo, 'SELECT * FROM tbl_manager_wallet_reservations WHERE id = ? FOR UPDATE', [$reservationId]);
        if ($r === null || $r['status'] !== 'ACTIVE' || (int) $r['manager_id'] !== $w->managerId) {
            throw new \LogicException('activation debit without the locked ACTIVE reservation');
        }
        return self::insert($pdo, $w, [
            'entry_key' => "res:{$reservationId}:commit",
            'entry_type' => 'ACTIVATION_DEBIT',
            'direction' => 'DEBIT',
            'amount' => Money::fromDb($r['amount']),
            'activation_id' => (int) $r['activation_id'],
            'transfer_id' => null,
            'counterparty_manager_id' => null,
            'reverses_entry_id' => null,
            'actor_type' => $actor->type,
            'operator_id' => $actor->operatorId,
            'actor_customer_id' => $actor->customerId,
            'reason' => null,
        ]);
    }

    /** @return list<array<string,mixed>> entries of one wallet in the actor's ledger.read scope */
    public static function entries(ActorContext $a, mixed $managerId, int $page = 1): array
    {
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        $id = FinanceInput::id($managerId);
        [$pred, $params] = DataScope::staffPredicate($a, C::LEDGER_READ, 'u.id') ?? ['(1 = 1)', []];
        $pdo = FinanceTx::pdo();
        if (Tx::row($pdo, "SELECT u.id FROM tbl_users u WHERE u.id = ? AND u.user_type = 'Agent' AND {$pred}", array_merge([$id], $params)) === null) {
            throw new DomainError('NOT_FOUND');
        }
        $offset = (max(1, $page) - 1) * self::PAGE_SIZE;
        $rows = Tx::rows($pdo, 'SELECT * FROM tbl_manager_ledger WHERE manager_id = ? ORDER BY id LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (int) $offset, [$id]);
        return array_map([self::class, 'present'], $rows);
    }

    /** One entry in the actor's ledger.read scope; out-of-scope and nonexistent are both NOT_FOUND. */
    public static function entry(ActorContext $a, mixed $entryId): array
    {
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        $id = FinanceInput::id($entryId);
        [$pred, $params] = DataScope::staffPredicate($a, C::LEDGER_READ, 'l.manager_id') ?? ['(1 = 1)', []];
        $row = Tx::row(FinanceTx::pdo(), "SELECT l.* FROM tbl_manager_ledger l WHERE l.id = ? AND {$pred}", array_merge([$id], $params));
        if ($row === null) {
            throw new DomainError('NOT_FOUND');
        }
        return self::present($row);
    }

    /**
     * Continuity diagnostic: entries ordered by id start at 0.00, each balance_before equals the
     * previous balance_after, and the last balance_after equals the wallet balance.
     *
     * @return list<string> issues (empty = continuous)
     */
    public static function continuity(\PDO $pdo, int $managerId): array
    {
        $issues = [];
        $w = Tx::row($pdo, 'SELECT balance FROM tbl_manager_wallets WHERE manager_id = ?', [$managerId]);
        $prev = Money::zero();
        foreach (Tx::rows($pdo, 'SELECT id, direction, amount, balance_before, balance_after FROM tbl_manager_ledger WHERE manager_id = ? ORDER BY id', [$managerId]) as $e) {
            $before = Money::fromDb($e['balance_before']);
            $after = Money::fromDb($e['balance_after']);
            $amount = Money::fromDb($e['amount']);
            if (!$before->equals($prev)) {
                $issues[] = "entry {$e['id']}: balance_before {$before->format()} != previous {$prev->format()}";
            }
            $expected = $e['direction'] === 'CREDIT' ? $before->plus($amount) : $before->minus($amount);
            if (!$after->equals($expected)) {
                $issues[] = "entry {$e['id']}: balance_after {$after->format()} != {$expected->format()}";
            }
            $prev = $after;
        }
        $balance = $w !== null ? Money::fromDb($w['balance']) : Money::zero();
        if (!$balance->equals($prev)) {
            $issues[] = "wallet balance {$balance->format()} != last balance_after {$prev->format()}";
        }
        return $issues;
    }

    /** @param array<string,mixed> $e */
    private static function insert(\PDO $pdo, LockedWallet $w, array $e): PostedEntry
    {
        if (!preg_match(self::KEY, $e['entry_key']) || strlen($e['entry_key']) > 128) {
            throw new \LogicException('invalid ledger entry key');
        }
        /** @var Money $amount */
        $amount = $e['amount'];
        if (!$amount->isPositive()) {
            throw new \LogicException('ledger amount must be positive');
        }
        match ($e['actor_type']) {
            FinanceActor::STAFF => $e['operator_id'] !== null && $e['operator_id'] > 0 ?: throw new DomainError('INVALID_ACTOR'),
            FinanceActor::CUSTOMER => $e['actor_customer_id'] !== null && $e['activation_id'] !== null ?: throw new DomainError('INVALID_ACTOR'),
            FinanceActor::SYSTEM, FinanceActor::CRON => $e['entry_type'] === 'ACTIVATION_DEBIT' ?: throw new DomainError('INVALID_ACTOR'),
            default => throw new DomainError('INVALID_ACTOR'),
        };
        $before = $w->balance;
        $after = $e['direction'] === 'CREDIT' ? $before->plus($amount) : $before->minus($amount);
        $afterDb = $after->toStorage();
        $now = Tx::utcNow();
        $n = Tx::exec(
            $pdo,
            'UPDATE tbl_manager_wallets SET balance = ?, updated_at = ? WHERE manager_id = ? AND balance = CAST(? AS DECIMAL(18,2))',
            [$afterDb, $now, $w->managerId, $before->toStorage()]
        );
        if ($n !== 1) {
            throw new DomainError('LEDGER_STATE_MISMATCH');
        }
        Tx::exec(
            $pdo,
            'INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, '
            . 'activation_id, transfer_id, counterparty_manager_id, reverses_entry_id, operator_id, actor_type, actor_customer_id, reason, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $e['entry_key'], $w->managerId, $e['entry_type'], $e['direction'], $amount->toStorage(), $before->toStorage(), $afterDb,
                $e['activation_id'], $e['transfer_id'], $e['counterparty_manager_id'], $e['reverses_entry_id'],
                $e['operator_id'], $e['actor_type'], $e['actor_customer_id'], $e['reason'], $now,
            ]
        );
        return new PostedEntry((int) $pdo->lastInsertId(), $w->withBalance($after), $before, $after);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function present(array $r): array
    {
        $int = static fn($v) => $v === null ? null : (int) $v;
        return [
            'id' => (int) $r['id'], 'entry_key' => (string) $r['entry_key'], 'manager_id' => (int) $r['manager_id'],
            'entry_type' => (string) $r['entry_type'], 'direction' => (string) $r['direction'],
            'amount' => Money::fromDb($r['amount'])->format(),
            'balance_before' => Money::fromDb($r['balance_before'])->format(),
            'balance_after' => Money::fromDb($r['balance_after'])->format(),
            'activation_id' => $int($r['activation_id']), 'transfer_id' => $int($r['transfer_id']),
            'counterparty_manager_id' => $int($r['counterparty_manager_id']), 'reverses_entry_id' => $int($r['reverses_entry_id']),
            'operator_id' => $int($r['operator_id']), 'actor_type' => (string) $r['actor_type'],
            'actor_customer_id' => $int($r['actor_customer_id']), 'reason' => $r['reason'], 'created_at' => (string) $r['created_at'],
        ];
    }
}
