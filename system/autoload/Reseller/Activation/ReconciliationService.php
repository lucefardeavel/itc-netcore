<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Domain\DomainError;
use Reseller\Domain\Tx;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\ReservationService;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;

/**
 * Reconciliation of activations whose outcome is not known (D017; owner decision A4; owner fix round 1). Only
 * Admin / SuperAdmin staff (activation.create scope G) or the system (the CLI system/reseller/reconcile.php)
 * act here. Nothing is automatic: there is no timeout release (WP3A), stale activations are only reported.
 *
 *  - reconcile, split by the durable activation history:
 *    A. the timeline already reached VERIFIED (delivery proven, then the settlement failed): a FINANCIAL
 *       reconciliation. The current RADIUS state is NOT consulted; the exact idempotent settlement is retried
 *       (COMPLETED, or back to RECONCILIATION_REQUIRED with the reservation ACTIVE). A later foreign RADIUS
 *       edit never turns a verified delivery into NOT_APPLIED.
 *    B. never verified: the canonical RADIUS projection (RadiusState) is compared with the target and the
 *       prior state captured when provisioning started. Exactly the target -> VERIFIED -> settlement;
 *       exactly the prior state -> NOT_APPLIED (reservation released, FAILED); anything else ->
 *       EXTERNAL_STATE_AMBIGUOUS: nothing changes (RECONCILIATION_REQUIRED, reservation ACTIVE, no RADIUS
 *       write); the operator decides (rollback).
 *    In-flight states (PROVISIONING .. FINANCIAL_COMMITTING) are reconciled only when stale, so a request
 *    that is still running is never raced.
 *  - rollback (explicit operator decision): the captured prior subscriber state is restored exactly and
 *    verified by reading it back (complete projection); only then is the reservation released (ROLLED_BACK).
 *    A prior secret that cannot be reconstructed exactly is refused before any write
 *    (PRIOR_SECRET_UNRESTORABLE: ROLLBACK_PENDING, reservation held, RADIUS unchanged); an uncertain or failed
 *    restore also keeps ROLLBACK_PENDING with the reservation held; it can be repeated.
 *  - cancel: before any provisioning (FUNDS_RESERVED / RETRY_PENDING) -> reservation released -> CANCELLED.
 */
final class ReconciliationService
{
    public const STALE_SECONDS = 300;
    public const AMBIGUOUS = 'EXTERNAL_STATE_AMBIGUOUS';
    public const AMBIGUOUS_MESSAGE = 'The current RADIUS state differs from both the activation target and the state captured before provisioning. '
        . 'No funds were released; the reservation remains held. Operator review is required (rollback restores the captured prior state).';
    private const IN_FLIGHT = [ActivationState::PROVISIONING, ActivationState::PROVISIONED, ActivationState::VERIFYING, ActivationState::FINANCIAL_COMMITTING];

    public static function staffActor(ActorContext $a): ActivationActor
    {
        if (PermissionService::scopeOf($a, C::ACTIVATION_CREATE) !== 'G') {
            throw new DomainError('FORBIDDEN');
        }
        return ActivationActor::staff($a);
    }

    /** @return array<string,mixed> the activation row after reconciliation */
    public static function reconcile(ActivationActor $actor, string $reference, int $staleSeconds = self::STALE_SECONDS): array
    {
        $act = self::find($reference);
        $id = (int) $act['id'];
        if (in_array($act['status'], self::IN_FLIGHT, true)) {
            if (self::age($act) < $staleSeconds) {
                throw new DomainError('ACTIVATION_IN_PROGRESS');
            }
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, self::IN_FLIGHT, ActivationState::RECONCILIATION_REQUIRED, $actor,
                ['event_type' => 'RECONCILIATION', 'error_code' => 'STALE_IN_FLIGHT'], ['error_code' => 'STALE_IN_FLIGHT', 'error_message' => 'The activation stopped while in flight.']));
            $act = self::find($reference);
        }
        if ($act['status'] !== ActivationState::RECONCILIATION_REQUIRED) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $pdo = FinanceTx::pdo();

        // A. delivery already proven by the durable timeline: financial reconciliation only
        if (ActivationStore::wasVerified($pdo, $id)) {
            self::audit($actor, 'activation.reconcile', $act, ['mode' => 'FINANCIAL']);
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::RECONCILIATION_REQUIRED], ActivationState::VERIFIED, $actor,
                ['event_type' => 'RECONCILIATION', 'metadata' => ['mode' => 'FINANCIAL']], ['error_code' => null, 'error_message' => null]));
            return ActivationService::execute($id, $actor);
        }

        // B. provisioning outcome never verified: target / prior / ambiguous
        $captured = ActivationStore::radius($pdo, $id);
        $username = $captured['target']['username'] ?? $captured['prior']['username'] ?? null;
        $current = null;
        if (is_string($username)) {
            try {
                $current = RadiusState::read($username, (int) ActivationService::plan($act)['id'], (string) $act['reference']);
            } catch (\Throwable $e) {
                \Reseller\Security\ErrorReporter::report($e, 'activation-reconcile');
                self::tx(static fn(\PDO $pdo) => ActivationStore::note($pdo, $id, ActivationState::RECONCILIATION_REQUIRED, $actor,
                    ['event_type' => 'RECONCILIATION', 'error_code' => 'RADIUS_READ_FAILED']));
                throw new DomainError('ACTIVATION_STATE_CONFLICT', 'The RADIUS state could not be read; nothing was changed.');
            }
        }
        if ($current !== null && $captured['target'] !== null && RadiusState::same($current, $captured['target'])) {
            self::audit($actor, 'activation.reconcile', $act, ['mode' => 'EXTERNAL', 'outcome' => 'TARGET']);
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::RECONCILIATION_REQUIRED], ActivationState::VERIFIED, $actor,
                ['event_type' => 'RECONCILIATION', 'provisioning_result' => 'SUCCESS', 'metadata' => ['mode' => 'EXTERNAL', 'outcome' => 'TARGET']],
                ['error_code' => null, 'error_message' => null]));
            return ActivationService::execute($id, $actor);
        }
        if ($current !== null && $captured['prior'] !== null && RadiusState::same($current, $captured['prior'])) {
            self::audit($actor, 'activation.reconcile', $act, ['mode' => 'EXTERNAL', 'outcome' => 'PRIOR']);
            ActivationService::fail($id, [ActivationState::RECONCILIATION_REQUIRED], $actor, 'NOT_APPLIED',
                'RADIUS shows exactly the state captured before provisioning: nothing was applied and nothing was charged.', 'RECONCILIATION', 'FAILED');
            return self::find($reference);
        }
        $diagnostic = ['mode' => 'EXTERNAL', 'outcome' => 'AMBIGUOUS',
            'differs_from_target' => $current !== null && $captured['target'] !== null ? RadiusState::diff($current, $captured['target']) : ['not_captured'],
            'differs_from_prior' => $current !== null && $captured['prior'] !== null ? RadiusState::diff($current, $captured['prior']) : ['not_captured']];
        self::audit($actor, 'activation.reconcile', $act, ['mode' => 'EXTERNAL', 'outcome' => 'AMBIGUOUS']);
        $events = ActivationStore::events($pdo, $id);
        $last = $events === [] ? null : $events[count($events) - 1];
        if ($act['error_code'] === self::AMBIGUOUS && $last !== null && $last['error_code'] === self::AMBIGUOUS
            && json_decode((string) $last['metadata'], true) === $diagnostic) {
            return $act; // the same ambiguity is already recorded: nothing new to record
        }
        self::tx(static fn(\PDO $pdo) => ActivationStore::note($pdo, $id, ActivationState::RECONCILIATION_REQUIRED, $actor,
            ['event_type' => 'RECONCILIATION', 'provisioning_result' => 'UNKNOWN', 'error_code' => self::AMBIGUOUS, 'error_message' => self::AMBIGUOUS_MESSAGE, 'metadata' => $diagnostic],
            ['error_code' => self::AMBIGUOUS, 'error_message' => self::AMBIGUOUS_MESSAGE]));
        return self::find($reference);
    }

    /**
     * Rollback (explicit operator decision): restore the captured prior subscriber state EXACTLY, verify it by
     * reading RADIUS back, and only then release the reservation (ROLLED_BACK). Everything the restore needs,
     * the prior password above all, is resolved BEFORE any RADIUS write: when the prior state was not captured
     * or a prior secret cannot be reconstructed exactly, nothing is written, the activation stays
     * ROLLBACK_PENDING with the reservation held (PRIOR_STATE_NOT_CAPTURED / PRIOR_SECRET_UNRESTORABLE).
     *
     * @return array<string,mixed>
     */
    public static function rollback(ActivationActor $actor, string $reference): array
    {
        $act = self::find($reference);
        $id = (int) $act['id'];
        if (!in_array($act['status'], [ActivationState::RECONCILIATION_REQUIRED, ActivationState::VERIFIED, ActivationState::ROLLBACK_PENDING], true)) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $restore = RestorePlan::build($act);
        if (isset($restore['refused'])) {
            $code = $restore['refused'];
            self::audit($actor, 'activation.rollback', $act, ['restore' => 'REFUSED:' . $code]);
            self::hold($actor, $act, $code);
            throw new DomainError($code);
        }
        if ($act['status'] !== ActivationState::ROLLBACK_PENDING) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::RECONCILIATION_REQUIRED, ActivationState::VERIFIED], ActivationState::ROLLBACK_PENDING, $actor,
                ['event_type' => 'RECONCILIATION', 'metadata' => ['rollback' => 'requested']]));
        }
        $result = RadiusProvisioner::connection()->restore($restore['username'], $restore['check'], $restore['reply'], $restore['groups']);
        if ($result->isSuccess()) {
            // released only when the complete subscriber projection equals the captured prior projection
            try {
                $restored = RadiusState::sameSubscriber(RadiusState::read($restore['username'], (int) ActivationService::plan($act)['id'], (string) $act['reference']), $restore['prior']);
            } catch (\Throwable $e) {
                \Reseller\Security\ErrorReporter::report($e, 'activation-rollback-verify');
                $restored = false;
            }
            if (!$restored) {
                $result = \Reseller\Radius\ProvisionResult::unknown('ROLLBACK_VERIFY_MISMATCH', 'RADIUS does not show the captured prior state after the restore.');
            }
        }
        self::audit($actor, 'activation.rollback', $act, ['restore' => $result->status . ':' . $result->code]);
        if (!$result->isSuccess()) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::note($pdo, $id, ActivationState::ROLLBACK_PENDING, $actor,
                ['event_type' => 'RECONCILIATION', 'provisioning_result' => $result->status, 'error_code' => $result->code, 'error_message' => $result->message],
                ['error_code' => $result->code, 'error_message' => 'The prior RADIUS state could not be restored and verified; the reservation remains held and the rollback can be repeated.']));
            throw new DomainError('ACTIVATION_STATE_CONFLICT', 'The previous RADIUS state could not be restored and verified (' . $result->code . '); the reservation stays held and the rollback can be repeated.');
        }
        $password = $restore['password'];
        self::tx(static function (\PDO $pdo) use ($id, $actor, $password): void {
            $reservation = ActivationStore::reservation($pdo, $id);
            if ($reservation !== null && $reservation['status'] === 'ACTIVE') {
                ReservationService::releaseInTx($pdo, (int) $reservation['id'], FinanceActor::system('wp5a-activation'), 'activation rolled back');
            }
            ActivationStore::transition($pdo, $id, [ActivationState::ROLLBACK_PENDING], ActivationState::ROLLED_BACK, $actor,
                ['event_type' => 'RECONCILIATION', 'provisioning_result' => 'SUCCESS', 'metadata' => ['rollback' => 'restored', 'password' => $password]],
                ['error_code' => 'ROLLED_BACK', 'error_message' => 'The activation was rolled back; nothing was charged.']);
        });
        return self::find($reference);
    }

    /**
     * A rollback refused before any RADIUS write: the activation is (or stays) ROLLBACK_PENDING with the refusal
     * recorded and the reservation held. Repeating the same refused rollback records nothing new.
     */
    private static function hold(ActivationActor $actor, array $act, string $code): void
    {
        $id = (int) $act['id'];
        $message = DomainError::MESSAGES[$code] ?? $code;
        $event = ['event_type' => 'RECONCILIATION', 'error_code' => $code, 'error_message' => $message, 'metadata' => ['rollback' => 'refused']];
        $columns = ['error_code' => $code, 'error_message' => $message];
        if ($act['status'] !== ActivationState::ROLLBACK_PENDING) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::RECONCILIATION_REQUIRED, ActivationState::VERIFIED], ActivationState::ROLLBACK_PENDING, $actor, $event, $columns));
        } elseif ($act['error_code'] !== $code) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::note($pdo, $id, ActivationState::ROLLBACK_PENDING, $actor, $event, $columns));
        }
    }

    /**
     * $minStaleSeconds (operator web cancel, OD-7 "stale only"): checked again on the locked row inside the transaction, so
     * an activation touched after the caller's unlocked staleness check is refused (lab closure FIN-3). The audit record
     * is written in the same transaction: a cancel is never committed without it (lab closure, audit after commit).
     *
     * @return array<string,mixed>
     */
    public static function cancel(ActivationActor $actor, string $reference, ?int $minStaleSeconds = null): array
    {
        $act = self::find($reference);
        if (!in_array($act['status'], [ActivationState::FUNDS_RESERVED, ActivationState::RETRY_PENDING], true)) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $id = (int) $act['id'];
        self::tx(static function (\PDO $pdo) use ($id, $actor, $act, $minStaleSeconds): void {
            $reservation = ActivationStore::reservation($pdo, $id);
            if ($reservation !== null && $reservation['status'] === 'ACTIVE') {
                ReservationService::releaseInTx($pdo, (int) $reservation['id'], FinanceActor::system('wp5a-activation'), 'activation cancelled');
            }
            // (ReservationService::lockReservation already holds this row when there was a reservation to release - it locks
            // activation -> wallet -> reservation - so no new lock order is introduced; a refusal rolls the release back)
            if ($minStaleSeconds !== null) {
                $now = Tx::row($pdo, 'SELECT updated_at FROM tbl_reseller_activations WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainError('NOT_FOUND');
                if (self::age($now) < $minStaleSeconds) {
                    throw new DomainError('ACTIVATION_IN_PROGRESS');
                }
            }
            ActivationStore::transition($pdo, $id, [ActivationState::FUNDS_RESERVED, ActivationState::RETRY_PENDING], ActivationState::CANCELLED, $actor,
                ['event_type' => 'RECONCILIATION', 'metadata' => ['cancel' => true]], ['error_code' => 'CANCELLED', 'error_message' => 'The activation was cancelled before provisioning.']);
            self::audit($actor, 'activation.cancel', $act, [], $pdo);
        });
        return self::find($reference);
    }

    /**
     * Non-terminal activations not updated for $seconds: report only (no action).
     *
     * @return list<array{reference:string,status:string,customer_id:int,manager_id:int,updated_at:string}>
     */
    public static function stale(int $seconds = self::STALE_SECONDS): array
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . max(0, $seconds) . ' seconds')->format('Y-m-d H:i:s.u');
        $rows = Tx::rows(FinanceTx::pdo(),
            'SELECT reference, status, customer_id, manager_id, updated_at FROM tbl_reseller_activations WHERE status NOT IN ('
            . implode(', ', array_fill(0, count(ActivationState::TERMINAL), '?')) . ') AND updated_at <= ? ORDER BY updated_at',
            [...ActivationState::TERMINAL, $cutoff]);
        return array_map(static fn(array $r): array => ['reference' => (string) $r['reference'], 'status' => (string) $r['status'],
            'customer_id' => (int) $r['customer_id'], 'manager_id' => (int) $r['manager_id'], 'updated_at' => (string) $r['updated_at']], $rows);
    }

    private static function find(string $reference): array
    {
        if (!preg_match('/^ACT-[0-9A-F]{16}$/D', $reference)) {
            throw new DomainError('NOT_FOUND');
        }
        return ActivationStore::byReference(FinanceTx::pdo(), $reference) ?? throw new DomainError('NOT_FOUND');
    }

    private static function age(array $act): int
    {
        $updated = new \DateTimeImmutable((string) $act['updated_at'], new \DateTimeZone('UTC'));
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $updated->getTimestamp();
    }

    private static function audit(ActivationActor $actor, string $event, array $act, array $after, ?\PDO $pdo = null): void
    {
        if ($actor->context === null) {
            return;
        }
        AuditLog::record($event, $actor->context, [
            'target_type' => 'activation', 'target_user_id' => (int) $act['manager_id'],
            'target_snapshot' => ['reference' => $act['reference'], 'customer_id' => (int) $act['customer_id']],
            'before' => ['status' => $act['status']], 'after' => $after,
        ], $pdo);
    }

    private static function tx(callable $fn): mixed
    {
        return ActivationService::tx($fn);
    }
}
