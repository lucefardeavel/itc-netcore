<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;

/**
 * Wallet reservation primitives (WP3A). Internal domain API for the future ActivationService (WP5A):
 * no route, no capability, no pricing. The amount is authoritative internal input (the activation's
 * wholesale snapshot when set), never a browser price.
 *
 *   reserve  ACTIVE exposure for one authoritative activation (at most one ACTIVE per activation)
 *   commit   ACTIVE -> COMMITTED exactly once, with one ACTIVATION_DEBIT ledger entry
 *   release  ACTIVE -> RELEASED exactly once, no ledger entry (no value moves)
 *
 * There is no expiry primitive, timer or cron: UNKNOWN external outcomes belong to WP5A
 * reconciliation and are never released because time passed (expires_at stays NULL).
 * Commit and release settle already-created exposure and remain possible for a frozen reseller.
 *
 * Lock order (shared with every finance writer): tree lock (reserve only) -> activation row ->
 * wallet row -> reservation rows.
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
        $act = Tx::row($pdo, 'SELECT id, manager_id, wholesale_cost FROM tbl_reseller_activations WHERE id = ? FOR UPDATE', [$activationId])
            ?? throw new DomainError('NOT_FOUND');
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
        WalletOwner::assertValid($pdo, $managerId);
        $w = LockedWallet::lock($pdo, $managerId) ?? throw new DomainError('INSUFFICIENT_AVAILABLE_CREDIT');
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

    public static function commit(int $reservationId, FinanceActor $actor): ReservationResult
    {
        return FinanceTx::run(static fn(\PDO $pdo) => self::commitInTx($pdo, $reservationId, $actor), false);
    }

    /**
     * Exactly-once settlement of an ACTIVE reservation: one ACTIVATION_DEBIT of exactly the reserved
     * amount, reservation COMMITTED and linked to it. A repeated commit returns the existing entry and
     * never rewrites the recorded actor.
     */
    public static function commitInTx(\PDO $pdo, int $reservationId, FinanceActor $actor): ReservationResult
    {
        [$w, $r] = self::lockReservation($pdo, $reservationId);
        if ($r['status'] === 'COMMITTED') {
            return self::result($r, true);
        }
        if ($r['status'] !== 'ACTIVE') {
            throw new DomainError('RESERVATION_NOT_ACTIVE');
        }
        $posted = LedgerService::postActivationDebit($pdo, $w, $reservationId, $actor);
        FinanceFaults::hit('commit.after_ledger_before_status');
        $n = Tx::exec(
            $pdo,
            "UPDATE tbl_manager_wallet_reservations SET status = 'COMMITTED', committed_ledger_entry_id = ?, resolved_at = ? WHERE id = ? AND status = 'ACTIVE'",
            [$posted->entryId, Tx::utcNow(), $reservationId]
        );
        if ($n !== 1) {
            throw new DomainError('RESERVATION_NOT_ACTIVE');
        }
        FinanceFaults::hit('commit.after_status');
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
