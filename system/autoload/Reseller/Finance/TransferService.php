<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\DataScope;

/**
 * Value-moving staff commands on reseller wallets (WP3A): provider funding / withdrawal, explicit
 * adjustments, parent -> direct-child transfer and administrative reversal. The only runtime writer of
 * tbl_manager_transfers.
 *
 * Every command: capability (WP2 PermissionService) -> strict input -> global tree lock -> BEGIN
 * (strict sql_mode) -> actor revalidated -> idempotency reference probe -> owner rules -> wallets
 * locked in ascending manager_id -> locked available-credit / storage-range checks -> operation row
 * (COMPLETED) -> ledger entries (LedgerService) -> R0006 audit -> COMMIT. A refusal persists nothing.
 *
 * Idempotency: the same reference with the same canonical payload returns the existing operation
 * (replayed, no new effect, no new audit); the same reference with any different field is
 * IDEMPOTENCY_CONFLICT.
 */
final class TransferService
{
    private const EVENTS = [
        'PROVIDER_FUNDING' => ['finance.fund', 'fund', C::WALLET_FUND_WITHDRAW],
        'PROVIDER_WITHDRAWAL' => ['finance.withdraw', 'withdraw', C::WALLET_FUND_WITHDRAW],
        'ADJUSTMENT_CREDIT' => ['finance.adjust_credit', 'adjust', C::WALLET_ADJUST],
        'ADJUSTMENT_DEBIT' => ['finance.adjust_debit', 'adjust', C::WALLET_ADJUST],
    ];

    /** Admin/SuperAdmin provider funding of a valid reseller wallet (creates the wallet if absent). */
    public static function fund(ActorContext $a, mixed $managerId, mixed $amount, mixed $reason, mixed $reference, mixed $externalReference = null): TransferResult
    {
        return self::single($a, 'PROVIDER_FUNDING', $managerId, $amount, $reason, $reference, $externalReference);
    }

    /** Admin/SuperAdmin provider withdrawal; AVAILABLE credit may not become negative. */
    public static function withdraw(ActorContext $a, mixed $managerId, mixed $amount, mixed $reason, mixed $reference, mixed $externalReference = null): TransferResult
    {
        return self::single($a, 'PROVIDER_WITHDRAWAL', $managerId, $amount, $reason, $reference, $externalReference);
    }

    /** Explicit credit correction of an EXISTING wallet (never creates one: WALLET_NOT_FOUND). */
    public static function adjustCredit(ActorContext $a, mixed $managerId, mixed $amount, mixed $reason, mixed $reference, mixed $externalReference = null): TransferResult
    {
        return self::single($a, 'ADJUSTMENT_CREDIT', $managerId, $amount, $reason, $reference, $externalReference);
    }

    /** Explicit debit correction of an EXISTING wallet; obeys the AVAILABLE >= 0 floor. */
    public static function adjustDebit(ActorContext $a, mixed $managerId, mixed $amount, mixed $reason, mixed $reference, mixed $externalReference = null): TransferResult
    {
        return self::single($a, 'ADJUSTMENT_DEBIT', $managerId, $amount, $reason, $reference, $externalReference);
    }

    /**
     * Parent reseller -> direct child reseller. Agent (scope C): source = the Agent itself, target a
     * depth-1 child Agent. Admin/SuperAdmin (scope G): any pair satisfying the same structural rule.
     */
    public static function transferToChild(ActorContext $a, mixed $fromId, mixed $toId, mixed $amount, mixed $reason, mixed $reference): TransferResult
    {
        $scope = ResellerTreeService::authorize($a, C::WALLET_TRANSFER);
        $from = FinanceInput::id($fromId);
        $to = FinanceInput::id($toId);
        $req = self::request($a, 'PARENT_CHILD_TRANSFER', $from, $to, $amount, $reason, $reference, null);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $scope, $from, $to, $req): TransferResult {
            ResellerTreeService::revalidateActor($pdo, $a);
            $replay = self::replay($pdo, $req);
            if ($replay !== null) {
                return $replay;
            }
            $agent = $scope !== 'G';
            if ($agent && $from !== (int) $a->userId) {
                throw new DomainError('NOT_FOUND'); // an Agent transfers from its own wallet only
            }
            if ($from === $to) {
                throw new DomainError('SELF_TRANSFER');
            }
            if ($agent) {
                // the accepted WP2 DataScope boundary: scope C = the actor's depth-1 children
                [$pred, $params] = DataScope::staffPredicate($a, C::WALLET_TRANSFER, 'u.id') ?? ['(1 = 1)', []];
                if (Tx::row($pdo, "SELECT u.id FROM tbl_users u WHERE u.id = ? AND {$pred}", array_merge([$to], $params)) === null) {
                    throw new DomainError('NOT_FOUND');
                }
            }
            if (Tx::row($pdo, 'SELECT 1 AS x FROM tbl_manager_tree WHERE ancestor_id = ? AND descendant_id = ? AND depth = 1', [$from, $to]) === null) {
                throw new DomainError($agent ? 'NOT_FOUND' : 'NOT_DIRECT_CHILD');
            }
            $owners = [$from => WalletOwner::assertValid($pdo, $from), $to => WalletOwner::assertValid($pdo, $to)];
            $locked = LockedWallet::lockMany($pdo, [$from, $to], [$to]);
            $source = $locked[$from] ?? throw new DomainError('INSUFFICIENT_AVAILABLE_CREDIT');
            $dest = $locked[$to];
            FinanceFaults::hit('transfer.after_wallet_locks');
            $reserved = [$from => WalletService::activeReservedLocked($pdo, $source), $to => WalletService::activeReservedLocked($pdo, $dest)];
            if (WalletService::available($source, $reserved[$from])->minus($req['amount'])->isNegative()) {
                throw new DomainError('INSUFFICIENT_AVAILABLE_CREDIT');
            }
            $dest->balance->plus($req['amount'])->toStorage();
            $id = self::insertTransfer($pdo, $req);
            FinanceFaults::hit('transfer.after_transfer_row');
            $out = LedgerService::postTransferLeg($pdo, $source, $id, 'debit');
            FinanceFaults::hit('transfer.after_first_leg');
            $in = LedgerService::postTransferLeg($pdo, $dest, $id, 'credit');
            FinanceFaults::hit('transfer.after_second_leg');
            self::audit('finance.transfer', $a, $pdo, $id, $req, $owners, [$out, $in], $reserved);
            FinanceFaults::hit('transfer.after_audit');
            return self::result($pdo, $id, false);
        }, true);
    }

    /**
     * WP6B (owner decision OD-2): read-only review of a transfer reversal for the web confirmation. It never locks or
     * writes and is never authority: reverse() re-checks everything atomically. It reports whether the original is
     * reversible now (NOT_REVERSIBLE / ALREADY_REVERSED otherwise), the compensating operation (the same mapping
     * reverse() uses) and the informational effect on each wallet ("hint" = the likely refusal with today's figures).
     *
     * @return array<string,mixed>
     */
    public static function reversalPreview(ActorContext $a, mixed $transferId): array
    {
        ResellerTreeService::authorize($a, C::WALLET_REVERSE);
        $id = FinanceInput::id($transferId);
        $pdo = FinanceTx::pdo();
        $o = Tx::row($pdo, 'SELECT * FROM tbl_manager_transfers WHERE id = ?', [$id]) ?? throw new DomainError('NOT_FOUND');
        $refusal = null;
        if ($o['reverses_transfer_id'] !== null) {
            $refusal = 'NOT_REVERSIBLE';
        } elseif ($o['status'] === 'REVERSED' || Tx::row($pdo, 'SELECT id FROM tbl_manager_transfers WHERE reverses_transfer_id = ?', [$id]) !== null) {
            $refusal = 'ALREADY_REVERSED';
        } elseif ($o['status'] !== 'COMPLETED') {
            $refusal = 'NOT_REVERSIBLE';
        }
        $amount = Money::fromDb($o['amount']);
        $out = [
            'transfer_id' => $id, 'reference' => (string) $o['reference'], 'operation_type' => (string) $o['operation_type'],
            'amount' => $amount->format(), 'status' => (string) $o['status'], 'reason' => (string) $o['reason'],
            'created_at' => substr((string) $o['created_at'], 0, 19), 'refusal' => $refusal, 'compensating_type' => null,
            'debit' => null, 'credit' => null, 'hint' => null,
        ];
        try {
            [$op, $from, $to] = self::compensation((string) $o['operation_type'], $o['from_manager_id'] !== null ? (int) $o['from_manager_id'] : null,
                $o['to_manager_id'] !== null ? (int) $o['to_manager_id'] : null);
        } catch (DomainError) {
            $out['refusal'] ??= 'NOT_REVERSIBLE';
            return $out;
        }
        $out['compensating_type'] = $op;
        if ($from !== null) {
            $w = WalletService::view($a, $from);
            $after = $w->available()->minus($amount);
            $out['debit'] = ['manager_id' => $from, 'exists' => $w->exists, 'available' => [$w->available()->format(), $after->format()],
                'balance' => [$w->balance->format(), $w->balance->minus($amount)->format()]];
            $out['hint'] = !$w->exists ? 'WALLET_NOT_FOUND' : ($after->isNegative() ? 'INSUFFICIENT_AVAILABLE_CREDIT' : null);
        }
        if ($to !== null) {
            $w = WalletService::view($a, $to);
            $out['credit'] = ['manager_id' => $to, 'exists' => $w->exists, 'balance' => [$w->balance->format(), $w->balance->plus($amount)->format()],
                'available' => [$w->available()->format(), $w->available()->plus($amount)->format()]];
            $out['hint'] ??= !$w->exists ? 'WALLET_NOT_FOUND' : null;
        }
        return $out;
    }

    /**
     * Administrative compensating reversal of a completed funding / withdrawal / adjustment /
     * parent-child transfer (owner decision P4). The new reference is checked first (a lost-response
     * replay returns the existing reversal); only a new reference inspects the original, which may be
     * reversed at most once. The original counterparties are unwound whatever the current hierarchy;
     * inactive/frozen status does not block the correction; no wallet is created; every debit leg
     * obeys the current AVAILABLE >= 0 floor. Original ledger rows are never changed.
     */
    public static function reverse(ActorContext $a, mixed $transferId, mixed $reason, mixed $reference): TransferResult
    {
        ResellerTreeService::authorize($a, C::WALLET_REVERSE);
        $origId = FinanceInput::id($transferId);
        $reason = FinanceInput::reason($reason);
        $reference = FinanceInput::reference($reference);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $origId, $reason, $reference): TransferResult {
            ResellerTreeService::revalidateActor($pdo, $a);
            // 1. the new reference first
            $existing = Tx::row($pdo, 'SELECT * FROM tbl_manager_transfers WHERE reference = ? FOR UPDATE', [$reference]);
            if ($existing !== null) {
                $same = $existing['reference'] === $reference
                    && (int) ($existing['reverses_transfer_id'] ?? 0) === $origId
                    && $existing['reason'] === $reason
                    && (int) $existing['operator_id'] === (int) $a->userId
                    && $existing['external_reference'] === null;
                if (!$same) {
                    throw new DomainError('IDEMPOTENCY_CONFLICT');
                }
                return self::result($pdo, (int) $existing['id'], true);
            }
            // 2. only a new reference inspects the original
            $o = Tx::row($pdo, 'SELECT * FROM tbl_manager_transfers WHERE id = ?', [$origId]) ?? throw new DomainError('NOT_FOUND');
            if ($o['reverses_transfer_id'] !== null) {
                throw new DomainError('NOT_REVERSIBLE');
            }
            if ($o['status'] === 'REVERSED' || Tx::row($pdo, 'SELECT id FROM tbl_manager_transfers WHERE reverses_transfer_id = ?', [$origId]) !== null) {
                throw new DomainError('ALREADY_REVERSED');
            }
            if ($o['status'] !== 'COMPLETED') {
                throw new DomainError('NOT_REVERSIBLE');
            }
            $origType = (string) $o['operation_type'];
            $origFrom = $o['from_manager_id'] !== null ? (int) $o['from_manager_id'] : null;
            $origTo = $o['to_manager_id'] !== null ? (int) $o['to_manager_id'] : null;
            [$op, $from, $to] = self::compensation($origType, $origFrom, $origTo);
            $amount = Money::fromDb($o['amount']);
            $parties = array_values(array_filter([$from, $to], static fn($v) => $v !== null));
            $owners = [];
            foreach ($parties as $m) {
                $owners[$m] = WalletOwner::assertIdentifiable($pdo, $m);
            }
            $locked = LockedWallet::lockMany($pdo, $parties, []);
            foreach ($parties as $m) {
                if (!isset($locked[$m])) {
                    throw new DomainError('WALLET_NOT_FOUND');
                }
            }
            // the original operation row is locked after the wallets (global lock order)
            $origLocked = Tx::row($pdo, 'SELECT status FROM tbl_manager_transfers WHERE id = ? FOR UPDATE', [$origId]);
            if ($origLocked === null || $origLocked['status'] !== 'COMPLETED') {
                throw new DomainError('ALREADY_REVERSED');
            }
            $reserved = [];
            foreach ($parties as $m) {
                $reserved[$m] = WalletService::activeReservedLocked($pdo, $locked[$m]);
            }
            if ($from !== null && WalletService::available($locked[$from], $reserved[$from])->minus($amount)->isNegative()) {
                throw new DomainError('INSUFFICIENT_AVAILABLE_CREDIT');
            }
            if ($to !== null) {
                $locked[$to]->balance->plus($amount)->toStorage();
            }
            $req = [
                'operation_type' => $op, 'from' => $from, 'to' => $to, 'amount' => $amount, 'reason' => $reason,
                'external_reference' => null, 'operator_id' => (int) $a->userId, 'reverses_transfer_id' => $origId, 'reference' => $reference,
            ];
            $id = self::insertTransfer($pdo, $req);
            FinanceFaults::hit('reverse.after_reversal_row');
            $posted = [];
            if ($from !== null) {
                $posted[] = LedgerService::postTransferLeg($pdo, $locked[$from], $id, 'debit');
                FinanceFaults::hit('reverse.after_first_leg');
            }
            if ($to !== null) {
                $posted[] = LedgerService::postTransferLeg($pdo, $locked[$to], $id, 'credit');
            }
            if (Tx::exec($pdo, "UPDATE tbl_manager_transfers SET status = 'REVERSED' WHERE id = ? AND status = 'COMPLETED'", [$origId]) !== 1) {
                throw new DomainError('ALREADY_REVERSED');
            }
            FinanceFaults::hit('reverse.after_status_update');
            self::audit('finance.reverse', $a, $pdo, $id, $req, $owners, $posted, $reserved, [
                'original_transfer_id' => $origId, 'original_operation_type' => $origType,
            ]);
            FinanceFaults::hit('reverse.after_audit');
            return self::result($pdo, $id, false);
        }, true);
    }

    private static function single(ActorContext $a, string $op, mixed $managerId, mixed $amount, mixed $reason, mixed $reference, mixed $externalReference): TransferResult
    {
        [$event, $point, $capability] = self::EVENTS[$op];
        ResellerTreeService::authorize($a, $capability);
        $credit = $op === 'PROVIDER_FUNDING' || $op === 'ADJUSTMENT_CREDIT';
        $id = FinanceInput::id($managerId);
        $req = self::request($a, $op, $credit ? null : $id, $credit ? $id : null, $amount, $reason, $reference, $externalReference);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $op, $event, $point, $credit, $id, $req): TransferResult {
            ResellerTreeService::revalidateActor($pdo, $a);
            $replay = self::replay($pdo, $req);
            if ($replay !== null) {
                return $replay;
            }
            $owner = WalletOwner::assertValid($pdo, $id);
            // only provider funding creates a wallet; adjustments correct an existing account
            $w = LockedWallet::lock($pdo, $id, $op === 'PROVIDER_FUNDING');
            if ($w === null) {
                throw new DomainError($op === 'PROVIDER_WITHDRAWAL' ? 'INSUFFICIENT_AVAILABLE_CREDIT' : 'WALLET_NOT_FOUND');
            }
            $reserved = [$id => WalletService::activeReservedLocked($pdo, $w)];
            if ($credit) {
                $w->balance->plus($req['amount'])->toStorage();
            } elseif (WalletService::available($w, $reserved[$id])->minus($req['amount'])->isNegative()) {
                throw new DomainError('INSUFFICIENT_AVAILABLE_CREDIT');
            }
            $tid = self::insertTransfer($pdo, $req);
            FinanceFaults::hit("{$point}.after_transfer_row");
            $posted = LedgerService::postTransferLeg($pdo, $w, $tid, $credit ? 'credit' : 'debit');
            FinanceFaults::hit("{$point}.after_ledger");
            self::audit($event, $a, $pdo, $tid, $req, [$id => $owner], [$posted], $reserved);
            FinanceFaults::hit("{$point}.after_audit");
            return self::result($pdo, $tid, false);
        }, true);
    }

    /** @return array<string,mixed> canonical command payload */
    private static function request(ActorContext $a, string $op, ?int $from, ?int $to, mixed $amount, mixed $reason, mixed $reference, mixed $externalReference): array
    {
        $money = Money::parsePositive($amount);
        $money->toStorage();
        return [
            'operation_type' => $op, 'from' => $from, 'to' => $to, 'amount' => $money,
            'reason' => FinanceInput::reason($reason), 'external_reference' => FinanceInput::externalReference($externalReference),
            'operator_id' => (int) $a->userId, 'reverses_transfer_id' => null, 'reference' => FinanceInput::reference($reference),
        ];
    }

    /** Idempotency probe (locking read of the unique reference). @param array<string,mixed> $req */
    private static function replay(\PDO $pdo, array $req): ?TransferResult
    {
        $row = Tx::row($pdo, 'SELECT * FROM tbl_manager_transfers WHERE reference = ? FOR UPDATE', [$req['reference']]);
        if ($row === null) {
            return null;
        }
        $nullableInt = static fn($v) => $v === null ? null : (int) $v;
        $same = $row['reference'] === $req['reference']
            && $row['operation_type'] === $req['operation_type']
            && $nullableInt($row['from_manager_id']) === $req['from']
            && $nullableInt($row['to_manager_id']) === $req['to']
            && Money::fromDb($row['amount'])->equals($req['amount'])
            && $row['reason'] === $req['reason']
            && $row['external_reference'] === $req['external_reference']
            && (int) $row['operator_id'] === $req['operator_id']
            && $nullableInt($row['reverses_transfer_id']) === $req['reverses_transfer_id'];
        if (!$same) {
            throw new DomainError('IDEMPOTENCY_CONFLICT');
        }
        return self::result($pdo, (int) $row['id'], true);
    }

    /** @param array<string,mixed> $req */
    /**
     * The compensating operation of an original transfer: [operation type, debited manager, credited manager]
     * (owner decision P4). Shared by reverse() and reversalPreview().
     *
     * @return array{0:string,1:?int,2:?int}
     */
    private static function compensation(string $origType, ?int $origFrom, ?int $origTo): array
    {
        return match ($origType) {
            'PROVIDER_FUNDING' => ['PROVIDER_WITHDRAWAL', $origTo, null],
            'PROVIDER_WITHDRAWAL' => ['PROVIDER_FUNDING', null, $origFrom],
            'ADJUSTMENT_CREDIT' => ['ADJUSTMENT_DEBIT', $origTo, null],
            'ADJUSTMENT_DEBIT' => ['ADJUSTMENT_CREDIT', null, $origFrom],
            'PARENT_CHILD_TRANSFER' => ['PARENT_CHILD_TRANSFER', $origTo, $origFrom],
            default => throw new DomainError('NOT_REVERSIBLE'),
        };
    }

    private static function insertTransfer(\PDO $pdo, array $req): int
    {
        $now = Tx::utcNow();
        Tx::exec(
            $pdo,
            'INSERT INTO tbl_manager_transfers (reference, operation_type, from_manager_id, to_manager_id, amount, status, reason, '
            . "external_reference, operator_id, reverses_transfer_id, created_at, completed_at) VALUES (?, ?, ?, ?, ?, 'COMPLETED', ?, ?, ?, ?, ?, ?)",
            [
                $req['reference'], $req['operation_type'], $req['from'], $req['to'], $req['amount']->toStorage(), $req['reason'],
                $req['external_reference'], $req['operator_id'], $req['reverses_transfer_id'], $now, $now,
            ]
        );
        return (int) $pdo->lastInsertId();
    }

    private static function result(\PDO $pdo, int $id, bool $replayed): TransferResult
    {
        $t = Tx::row($pdo, 'SELECT * FROM tbl_manager_transfers WHERE id = ?', [$id]) ?? throw new \LogicException('transfer row missing');
        $entries = array_map(static fn(array $r) => (int) $r['id'], Tx::rows($pdo, 'SELECT id FROM tbl_manager_ledger WHERE transfer_id = ? ORDER BY id', [$id]));
        return new TransferResult(
            $id, (string) $t['reference'], (string) $t['operation_type'], (string) $t['status'], Money::fromDb($t['amount']),
            $t['from_manager_id'] !== null ? (int) $t['from_manager_id'] : null,
            $t['to_manager_id'] !== null ? (int) $t['to_manager_id'] : null,
            $t['reverses_transfer_id'] !== null ? (int) $t['reverses_transfer_id'] : null,
            $entries, $replayed,
        );
    }

    /**
     * R0006 operator audit: who, what, target, reference, reason and before/after wallet state.
     * Ids and amounts only — the ledger stays the financial truth (no ledger copy, no secrets).
     *
     * @param array<string,mixed> $req
     * @param array<int,array<string,mixed>> $owners manager_id => tbl_users row
     * @param list<PostedEntry> $posted
     * @param array<int,Money> $reserved manager_id => locked ACTIVE reservations
     */
    private static function audit(string $event, ActorContext $a, \PDO $pdo, int $transferId, array $req, array $owners, array $posted, array $reserved, array $extra = []): void
    {
        $wallets = [];
        foreach ($posted as $p) {
            $m = $p->wallet->managerId;
            $res = $reserved[$m] ?? Money::zero();
            $wallets[] = [
                'manager_id' => $m,
                'ledger_entry_id' => $p->entryId,
                'balance_before' => $p->balanceBefore->format(),
                'balance_after' => $p->balanceAfter->format(),
                'available_before' => $p->balanceBefore->plus($p->wallet->debtLimit)->minus($res)->format(),
                'available_after' => $p->balanceAfter->plus($p->wallet->debtLimit)->minus($res)->format(),
                'wallet_created' => $p->wallet->created,
            ];
        }
        $target = $req['to'] ?? $req['from'];
        $snapshots = [];
        foreach ($owners as $m => $u) {
            $snapshots[$m === $req['from'] ? 'source' : 'destination'] = WalletOwner::snapshot($u);
        }
        AuditLog::record($event, $a, [
            'target_type' => 'wallet',
            'target_user_id' => $target,
            'target_snapshot' => count($snapshots) === 1 ? reset($snapshots) : $snapshots,
            'before' => ['wallets' => array_map(static fn(array $w) => [
                'manager_id' => $w['manager_id'], 'balance' => $w['balance_before'], 'available' => $w['available_before'], 'wallet_existed' => !$w['wallet_created'],
            ], $wallets)],
            'after' => $extra + [
                'transfer_id' => $transferId, 'reference' => $req['reference'], 'operation_type' => $req['operation_type'],
                'amount' => $req['amount']->format(), 'from_manager_id' => $req['from'], 'to_manager_id' => $req['to'],
                'external_reference' => $req['external_reference'], 'reverses_transfer_id' => $req['reverses_transfer_id'],
                'wallets' => array_map(static fn(array $w) => [
                    'manager_id' => $w['manager_id'], 'ledger_entry_id' => $w['ledger_entry_id'], 'balance' => $w['balance_after'],
                    'available' => $w['available_after'], 'wallet_created' => $w['wallet_created'],
                ], $wallets),
            ],
            'reason' => $req['reason'],
        ], $pdo);
    }
}
