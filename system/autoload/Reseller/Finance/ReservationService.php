<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;

/**
 * Wallet reservation primitives (WP3A; settlement extended by WP3B). Internal domain API for the future
 * ActivationService (WP5A): no route, no capability. The amount is authoritative internal input (the
 * activation's wholesale snapshot when set), never a browser price.
 *
 *   reserve  ACTIVE exposure for one authoritative activation (at most one ACTIVE per activation).
 *            For an activation with a sealed pricing snapshot every deterministic precondition is
 *            checked here, before provisioning: the snapshot is valid and still equals a fresh
 *            authoritative resolution (PRICING_SNAPSHOT_STALE), the plan is saleable (incl.
 *            ZERO_WHOLESALE_NOT_SUPPORTED), every positive-margin commission recipient already has a
 *            wallet (COMMISSION_RECIPIENT_NO_WALLET; WP3B never creates wallets) with headroom for its
 *            commission (COMMISSION_RECIPIENT_CAPACITY), and the leaf has available credit.
 *   commit   ACTIVE -> COMMITTED exactly once: one ACTIVATION_DEBIT plus every positive intermediary
 *            COMMISSION_CREDIT (LedgerService::postActivationSettlement) in one transaction. State can
 *            change after reserve, so storage headroom and the leaf floor are re-checked under the final
 *            wallet locks; a failure rolls the whole settlement back (SettlementFailure, reservation
 *            stays ACTIVE).
 *   release  ACTIVE -> RELEASED exactly once, no ledger entry (no value moves)
 *
 * There is no expiry primitive, timer or cron: UNKNOWN external outcomes belong to WP5A
 * reconciliation and are never released because time passed (expires_at stays NULL).
 * Commit and release settle already-created exposure and remain possible for a frozen reseller (and
 * for a frozen commission recipient).
 *
 * Canonical lock order (shared with every finance writer, WP3B F-7): tree lock (reserve only) ->
 * identity rows (commit: FK-referenced tbl_users rows LOCK IN SHARE MODE, ascending) -> activation row
 * (+ the activation-scoped reservation probe) -> wallet rows (one ascending lockMany) -> manager-scoped
 * reservation rows / the reservation row -> writes.
 */
final class ReservationService
{
    public static function reserve(int $activationId, int $managerId, Money $amount): ReservationResult
    {
        return FinanceTx::run(static fn(\PDO $pdo) => self::reserveInTx($pdo, $activationId, $managerId, $amount), true);
    }

    /** For a caller that already holds this connection's tree lock and an open transaction (WP5A). */
    public static function reserveInTx(\PDO $pdo, int $activationId, int $managerId, Money $amount): ReservationResult
    {
        FinanceTx::assertTreeLockHeld($pdo);
        ResellerTreeService::requireReady();
        if ($activationId <= 0 || $managerId <= 0) {
            throw new DomainError('NOT_FOUND');
        }
        if (!$amount->isPositive()) {
            throw new DomainError('INVALID_AMOUNT');
        }
        $amount->toStorage();
        $act = Tx::row(
            $pdo,
            'SELECT id, manager_id, plan_id, wholesale_cost, retail_price, provider_settlement_amount, plan_snapshot, pricing_chain_snapshot '
            . 'FROM tbl_reseller_activations WHERE id = ? FOR UPDATE',
            [$activationId]
        ) ?? throw new DomainError('NOT_FOUND');
        if ($act['wholesale_cost'] !== null && Money::fromDb($act['wholesale_cost'])->isZero()) {
            throw new DomainError('ZERO_WHOLESALE_NOT_SUPPORTED'); // F-3: no zero-reservation path
        }
        if ((int) $act['manager_id'] !== $managerId
            || ($act['wholesale_cost'] !== null && !Money::fromDb($act['wholesale_cost'])->equals($amount))) {
            throw new DomainError('ACTIVATION_MISMATCH');
        }
        $existing = Tx::row(
            $pdo,
            "SELECT * FROM tbl_manager_wallet_reservations WHERE activation_id = ? ORDER BY status = 'ACTIVE' DESC, status = 'COMMITTED' DESC, id DESC LIMIT 1 FOR UPDATE",
            [$activationId]
        );
        if ($existing !== null) {
            if ((int) $existing['manager_id'] !== $managerId || !Money::fromDb($existing['amount'])->equals($amount)) {
                throw new DomainError('RESERVATION_CONFLICT');
            }
            if ($existing['status'] === 'ACTIVE' || $existing['status'] === 'COMMITTED') {
                return self::result($existing, true); // never a second exposure row
            }
            throw new DomainError('RESERVATION_RESOLVED'); // RELEASED / EXPIRED: re-reservation is WP5A's decision (P6)
        }
        $recipients = [];
        if ($act['pricing_chain_snapshot'] !== null) {
            $snap = PricingSnapshot::fromActivationRow($act);
            // the price is committed now: it must still be exactly the current authoritative resolution
            if (!$snap->sameTermsAs(PricingService::resolveInTx($pdo, $managerId, (int) $act['plan_id']))) {
                throw new DomainError('PRICING_SNAPSHOT_STALE');
            }
            $recipients = $snap->commissionRecipients();
        } elseif ($act['provider_settlement_amount'] !== null || $act['plan_snapshot'] !== null) {
            throw new DomainError('PRICING_SNAPSHOT_INVALID'); // a partial snapshot is never accepted
        }
        WalletOwner::assertValid($pdo, $managerId);
        $locked = LockedWallet::lockMany($pdo, array_merge([$managerId], array_keys($recipients)), []);
        $w = $locked[$managerId] ?? throw new DomainError('INSUFFICIENT_AVAILABLE_CREDIT');
        foreach ($recipients as $m => $margin) {
            $rw = $locked[$m] ?? throw new DomainError('COMMISSION_RECIPIENT_NO_WALLET');
            if (!$rw->balance->plus($margin)->isStorable()) {
                throw new DomainError('COMMISSION_RECIPIENT_CAPACITY');
            }
        }
        $reserved = WalletService::activeReservedLocked($pdo, $w);
        if (WalletService::available($w, $reserved)->minus($amount)->isNegative()) {
            throw new DomainError('INSUFFICIENT_AVAILABLE_CREDIT');
        }
        Tx::exec(
            $pdo,
            "INSERT INTO tbl_manager_wallet_reservations (manager_id, activation_id, amount, status, created_at) VALUES (?, ?, ?, 'ACTIVE', ?)",
            [$managerId, $activationId, $amount->toStorage(), Tx::utcNow()]
        );
        $id = (int) $pdo->lastInsertId();
        FinanceFaults::hit('reserve.after_insert');
        return self::result(self::row($pdo, $id), false);
    }

    /**
     * Own-transaction settlement. Precondition refusals stay DomainError; a failure of the settlement
     * itself (including concurrency that outlived the bounded deadlock retry) is a SettlementFailure.
     */
    public static function commit(int $reservationId, FinanceActor $actor): ReservationResult
    {
        try {
            return FinanceTx::run(static fn(\PDO $pdo) => self::commitInTx($pdo, $reservationId, $actor), false);
        } catch (DomainError $e) {
            if (in_array($e->reason, ['CONCURRENCY_RETRY', 'LOCK_TIMEOUT'], true)) {
                throw SettlementFailure::wrap($e, $reservationId, null);
            }
            throw $e;
        } catch (\PDOException $e) {
            throw SettlementFailure::wrap($e, $reservationId, null);
        }
    }

    /**
     * Exactly-once settlement of an ACTIVE reservation: one ACTIVATION_DEBIT of exactly the reserved
     * amount plus every positive COMMISSION_CREDIT of the activation's sealed pricing snapshot, the
     * reservation COMMITTED and linked to the debit. A repeated commit returns the existing result and
     * never rewrites the recorded actor. Within a caller's (WP5A) transaction this must be the first
     * locking work, so the canonical lock order holds.
     */
    public static function commitInTx(\PDO $pdo, int $reservationId, FinanceActor $actor): ReservationResult
    {
        FinanceTx::assertInTransaction($pdo);
        ResellerTreeService::requireReady();
        if ($reservationId <= 0) {
            throw new DomainError('NOT_FOUND');
        }
        // activation_id and manager_id never change after insert
        $ids = Tx::row($pdo, 'SELECT activation_id, manager_id FROM tbl_manager_wallet_reservations WHERE id = ?', [$reservationId])
            ?? throw new DomainError('NOT_FOUND');
        $leafId = (int) $ids['manager_id'];
        $activationId = (int) $ids['activation_id'];
        // identity rows the ledger INSERTs will foreign-key check, shared-locked first (canonical order)
        $identities = array_values(array_unique(array_filter([$leafId, $actor->operatorId], static fn($v) => $v !== null && $v > 0)));
        sort($identities);
        Tx::rows($pdo, 'SELECT id FROM tbl_users WHERE id IN (' . ResellerTreeService::ph($identities) . ') ORDER BY id LOCK IN SHARE MODE', $identities);
        $act = Tx::row(
            $pdo,
            'SELECT id, manager_id, plan_id, wholesale_cost, retail_price, provider_settlement_amount, plan_snapshot, pricing_chain_snapshot '
            . 'FROM tbl_reseller_activations WHERE id = ? FOR UPDATE',
            [$activationId]
        );
        $recipients = [];
        $snapshotError = null;
        if ($act !== null && $act['pricing_chain_snapshot'] !== null) {
            try {
                $recipients = PricingSnapshot::fromActivationRow($act)->commissionRecipients();
            } catch (DomainError $e) {
                $snapshotError = $e; // raised only for a reservation that still has to settle
            }
        }
        $locked = LockedWallet::lockMany($pdo, array_merge([$leafId], array_keys($recipients)), []);
        $r = self::row($pdo, $reservationId, true);
        if ($r['status'] === 'COMMITTED') {
            return self::result($r, true);
        }
        if ($r['status'] !== 'ACTIVE') {
            throw new DomainError('RESERVATION_NOT_ACTIVE');
        }
        try {
            if ($act === null) {
                throw new \LogicException('reservation without its activation');
            }
            if ($snapshotError !== null) {
                throw $snapshotError;
            }
            $leaf = $locked[$leafId] ?? throw new \LogicException('reservation without a wallet');
            $wallets = [];
            foreach (array_keys($recipients) as $m) {
                $wallets[] = $locked[$m] ?? throw new DomainError('COMMISSION_RECIPIENT_NO_WALLET');
            }
            $posted = LedgerService::postActivationSettlement($pdo, $reservationId, $actor, $leaf, ...$wallets);
            FinanceFaults::hit('commit.after_ledger_before_status');
            $n = Tx::exec(
                $pdo,
                "UPDATE tbl_manager_wallet_reservations SET status = 'COMMITTED', committed_ledger_entry_id = ?, resolved_at = ? WHERE id = ? AND status = 'ACTIVE'",
                [$posted->debit->entryId, Tx::utcNow(), $reservationId]
            );
            if ($n !== 1) {
                throw new DomainError('RESERVATION_NOT_ACTIVE');
            }
            FinanceFaults::hit('commit.after_status');
        } catch (\PDOException $e) {
            if (in_array(FinanceTx::driverCode($e), [1213, 1205], true)) {
                throw $e; // keep the accepted whole-unit deadlock retry / LOCK_TIMEOUT classification
            }
            throw SettlementFailure::wrap($e, $reservationId, $activationId);
        } catch (\Throwable $e) {
            throw SettlementFailure::wrap($e, $reservationId, $activationId);
        }
        return self::result(self::row($pdo, $reservationId), false);
    }

    public static function release(int $reservationId, FinanceActor $actor, mixed $note): ReservationResult
    {
        return FinanceTx::run(static fn(\PDO $pdo) => self::releaseInTx($pdo, $reservationId, $actor, $note), false);
    }

    /**
     * Releases confirmed-unused exposure (ACTIVE -> RELEASED) without touching the balance. A replay is
     * idempotent only with the same resolver and note; a replay that would alter the recorded
     * resolution is RESERVATION_CONFLICT. COMMITTED and EXPIRED reservations cannot be released.
     */
    public static function releaseInTx(\PDO $pdo, int $reservationId, FinanceActor $actor, mixed $note): ReservationResult
    {
        $note = FinanceInput::reason($note);
        $resolver = match ($actor->type) {
            FinanceActor::STAFF => $actor->operatorId,
            FinanceActor::SYSTEM, FinanceActor::CRON => null,
            default => throw new DomainError('INVALID_ACTOR'),
        };
        [, $r] = self::lockReservation($pdo, $reservationId);
        switch ($r['status']) {
            case 'RELEASED':
                $sameResolver = ($r['resolved_by'] === null ? null : (int) $r['resolved_by']) === $resolver;
                if (!$sameResolver || $r['resolution_note'] !== $note) {
                    throw new DomainError('RESERVATION_CONFLICT');
                }
                return self::result($r, true);
            case 'COMMITTED':
                throw new DomainError('RESERVATION_ALREADY_COMMITTED');
            case 'ACTIVE':
                break;
            default:
                throw new DomainError('RESERVATION_NOT_ACTIVE');
        }
        $n = Tx::exec(
            $pdo,
            "UPDATE tbl_manager_wallet_reservations SET status = 'RELEASED', resolved_at = ?, resolved_by = ?, resolution_note = ? WHERE id = ? AND status = 'ACTIVE'",
            [Tx::utcNow(), $resolver, $note, $reservationId]
        );
        if ($n !== 1) {
            throw new DomainError('RESERVATION_NOT_ACTIVE');
        }
        FinanceFaults::hit('release.after_status');
        if ($actor->isStaff() && $actor->context !== null) {
            AuditLog::record('finance.reservation_release', $actor->context, [
                'target_type' => 'wallet', 'target_user_id' => (int) $r['manager_id'],
                'before' => ['reservation_id' => $reservationId, 'status' => 'ACTIVE'],
                'after' => [
                    'reservation_id' => $reservationId, 'activation_id' => (int) $r['activation_id'], 'status' => 'RELEASED',
                    'amount' => Money::fromDb($r['amount'])->format(), 'resolved_by' => $resolver,
                ],
                'reason' => $note,
            ], $pdo);
        }
        return self::result(self::row($pdo, $reservationId), false);
    }

    /**
     * Global lock order for settlement: activation row -> wallet row -> reservation row.
     *
     * @return array{0:LockedWallet,1:array<string,mixed>}
     */
    private static function lockReservation(\PDO $pdo, int $reservationId): array
    {
        FinanceTx::assertInTransaction($pdo);
        ResellerTreeService::requireReady();
        if ($reservationId <= 0) {
            throw new DomainError('NOT_FOUND');
        }
        // activation_id and manager_id never change after insert
        $ids = Tx::row($pdo, 'SELECT activation_id, manager_id FROM tbl_manager_wallet_reservations WHERE id = ?', [$reservationId])
            ?? throw new DomainError('NOT_FOUND');
        Tx::row($pdo, 'SELECT id FROM tbl_reseller_activations WHERE id = ? FOR UPDATE', [(int) $ids['activation_id']]);
        $w = LockedWallet::lock($pdo, (int) $ids['manager_id']) ?? throw new \LogicException('reservation without a wallet');
        return [$w, self::row($pdo, $reservationId, true)];
    }

    /** @return array<string,mixed> */
    private static function row(\PDO $pdo, int $id, bool $lock = false): array
    {
        return Tx::row($pdo, 'SELECT * FROM tbl_manager_wallet_reservations WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id])
            ?? throw new DomainError('NOT_FOUND');
    }

    /** @param array<string,mixed> $r */
    private static function result(array $r, bool $replayed): ReservationResult
    {
        return new ReservationResult(
            (int) $r['id'], (int) $r['activation_id'], (int) $r['manager_id'], Money::fromDb($r['amount']), (string) $r['status'],
            $r['committed_ledger_entry_id'] !== null ? (int) $r['committed_ledger_entry_id'] : null, $replayed,
        );
    }
}
