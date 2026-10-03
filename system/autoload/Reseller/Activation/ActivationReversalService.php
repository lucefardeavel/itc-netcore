<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;
use Reseller\Finance\FinanceInput;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\LedgerService;
use Reseller\Finance\LockedWallet;
use Reseller\Radius\ProvisionResult;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;

/**
 * Reversal of a COMPLETED activation (WP5B; owner decisions OD-11 .. OD-14, R0007): COMPLETED ->
 * REVERSAL_PENDING -> REVERSED. A dedicated saga, separate from the WP5A pre-completion rollback (it never calls
 * ReconciliationService::rollback and never releases a reservation: the reservation stays COMMITTED and the
 * original ledger legs are compensated by REVERSAL entries).
 *
 *  0. Safety proof (read-only): the reversal context was captured at completion; no later service-affecting or
 *     in-flight activation; the legacy recharge row and RADIUS still show exactly what this activation left; the
 *     prior RADIUS state is restorable (PRIOR_SECRET_UNRESTORABLE before any write).
 *  1. Financial preflight (tree lock, canonical lock order, writes nothing): the original legs can be reversed.
 *  2a. Start (the same tree lock ActivationService::open takes): re-check 0 under the lock, COMPLETED ->
 *     REVERSAL_PENDING. From then on the customer is in flight: an activation open or a re-provision refuses.
 *  2b. RADIUS compensation, outside any transaction: exact restore of the captured prior subscriber rows, read
 *     back; the verified restore is the durable evidence (PROVISIONING_RESULT event on REVERSAL_PENDING).
 *  3. Financial / legacy commit (one tree-locked transaction, all-or-nothing): leaf REVERSAL credit and one
 *     clawback per commission (LedgerService::postActivationReversal), legacy recharge restored, compensating
 *     'REV-<reference>' transaction, REVERSAL_PENDING -> REVERSED.
 * A repeated request resumes: REVERSAL_PENDING with evidence -> phase 3 only; without -> phase 2b; REVERSED ->
 * ACTIVATION_STATE_CONFLICT. There is no automatic customer refund (V1 staff activations collect no retail).
 */
final class ActivationReversalService
{
    private const SERVICE_AFFECTING_EXCLUDED = [ActivationState::CANCELLED, ActivationState::FAILED];

    /** @return array<string,mixed> the activation row after the request */
    public static function reverse(ActorContext $a, mixed $reference, mixed $reason): array
    {
        ResellerTreeService::authorize($a, C::ACTIVATION_REVERSE);
        if (PermissionService::scopeOf($a, C::ACTIVATION_REVERSE) !== 'G') {
            throw new DomainError('FORBIDDEN');
        }
        $reason = FinanceInput::reason($reason);
        if (!is_string($reference) || !preg_match('/^ACT-[0-9A-F]{16}$/D', $reference)) {
            throw new DomainError('NOT_FOUND');
        }
        $actor = ActivationActor::staff($a);
        $act = self::find($reference);
        $id = (int) $act['id'];
        if ($act['status'] === ActivationState::COMPLETED) {
            $context = self::prove($actor, $act);
            self::preflight($actor, $act);
            self::start($actor, $act, $context, $reason);
            ActivationFaults::hit('reversal.after_start');
        } elseif ($act['status'] !== ActivationState::REVERSAL_PENDING) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $act = self::find($reference);
        if (!self::restored(FinanceTx::pdo(), $id)) {
            self::compensateRadius($actor, $act);
            ActivationFaults::hit('reversal.after_evidence');
        }
        self::commit($actor, self::find($reference), $reason);
        return self::find($reference);
    }

    /**
     * The captured context: the legacy part (LegacyBridge) of the COMPLETED event and the RADIUS prior / target
     * projections of the PROVISIONING events, or null when an exact reversal is impossible.
     *
     * @return array{legacy:array{pre:array<string,mixed>,post:array<string,mixed>},prior:array,target:array}|null
     */
    public static function context(\PDO $pdo, int $id): ?array
    {
        $meta = ActivationStore::completion($pdo, $id);
        $legacy = LegacyBridge::context(is_array($meta) ? ($meta['reversal_context'] ?? null) : null);
        $radius = ActivationStore::radius($pdo, $id);
        if ($legacy === null || !is_array($radius['prior']) || !is_array($radius['target'])) {
            return null;
        }
        return ['legacy' => $legacy, 'prior' => $radius['prior'], 'target' => $radius['target']];
    }

    /** Durable evidence of phase 2b: RADIUS was restored to the captured prior state and verified. */
    public static function restored(\PDO $pdo, int $id): bool
    {
        foreach (ActivationStore::results($pdo, $id, ActivationState::REVERSAL_PENDING, 'SUCCESS') as $meta) {
            if (is_array($meta) && ($meta['reversal'] ?? null) === 'radius_restored' && ($meta['verified'] ?? null) === true) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ phase 0

    /** Read-only safety proof; a refusal records one audit row and writes nothing else. */
    private static function prove(ActivationActor $actor, array $act): array
    {
        $pdo = FinanceTx::pdo();
        $context = self::context($pdo, (int) $act['id']);
        if ($context === null) {
            self::refuse($actor, $act, 'REVERSAL_CONTEXT_NOT_CAPTURED', ['context']);
        }
        $failed = self::externalChecks($pdo, $act, $context);
        if ($failed !== []) {
            self::refuse($actor, $act, 'REVERSAL_EXTERNAL_STATE_CHANGED', $failed);
        }
        $plan = RestorePlan::build($act);
        if (isset($plan['refused'])) {
            self::refuse($actor, $act, $plan['refused'], ['prior']);
        }
        return $context;
    }

    /**
     * The checks that prove nothing changed the customer's service since this activation: names of the failed
     * checks (and of the differing RADIUS components), never values.
     *
     * @return list<string>
     */
    private static function externalChecks(\PDO $pdo, array $act, array $context): array
    {
        $failed = [];
        if (ActivationStore::later($pdo, (int) $act['customer_id'], (int) $act['id'], self::SERVICE_AFFECTING_EXCLUDED) !== null) {
            $failed[] = 'later_activation';
        }
        $inFlight = ActivationStore::inFlight($pdo, (int) $act['customer_id']);
        if ($inFlight !== null && (int) $inFlight['id'] !== (int) $act['id']) {
            $failed[] = 'in_flight';
        }
        if (!LegacyBridge::unchangedSince($pdo, $context['legacy'])) {
            $failed[] = 'legacy_recharge';
        }
        try {
            $current = RadiusState::read((string) $context['target']['username'], (int) ActivationService::plan($act)['id'], (string) $act['reference']);
            if (!RadiusState::same($current, $context['target'])) {
                foreach (RadiusState::diff($current, $context['target']) as $component) {
                    $failed[] = 'radius:' . $component;
                }
            }
        } catch (\Throwable $e) {
            \Reseller\Security\ErrorReporter::report($e, 'activation-reversal-read');
            $failed[] = 'radius_unreadable';
        }
        return $failed;
    }

    /** @param list<string> $checks */
    private static function refuse(ActivationActor $actor, array $act, string $code, array $checks): never
    {
        self::audit($actor, $act, ['refused' => $code, 'checks' => $checks], null);
        throw new DomainError($code);
    }

    // ------------------------------------------------------------------ phase 1

    /** Deterministic financial preflight under the tree lock: locks, validates, writes nothing. */
    private static function preflight(ActivationActor $actor, array $act): void
    {
        try {
            FinanceTx::run(static function (\PDO $pdo) use ($actor, $act): void {
                self::lockLegs($pdo, $actor, (int) $act['id'], [ActivationState::COMPLETED]);
            }, true);
        } catch (DomainError $e) {
            if (in_array($e->reason, ['REVERSAL_FINANCIAL_REFUSED', 'ALREADY_REVERSED'], true)) {
                self::audit($actor, $act, ['refused' => 'REVERSAL_FINANCIAL_REFUSED', 'detail' => $e->getMessage()], null);
                throw new DomainError('REVERSAL_FINANCIAL_REFUSED', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Canonical lock order (WP3B F-7): identity rows shared -> activation -> wallets ascending (no creation) ->
     * reservation rows (inside the check). Returns the legs and the locked wallets.
     *
     * @param list<string> $states acceptable activation states
     * @return array{0:array<string,mixed>,1:LockedWallet,2:list<LockedWallet>,3:array<string,mixed>}
     */
    private static function lockLegs(\PDO $pdo, ActivationActor $actor, int $id, array $states): array
    {
        $legs = LedgerService::activationReversalLegs($pdo, $id);
        $managers = array_merge([$legs['leaf']], array_keys($legs['credits']));
        $identities = array_values(array_unique(array_filter(array_merge($managers, [$actor->userId]), static fn($v) => $v !== null && $v > 0)));
        sort($identities);
        Tx::rows($pdo, 'SELECT id FROM tbl_users WHERE id IN (' . ResellerTreeService::ph($identities) . ') ORDER BY id LOCK IN SHARE MODE', $identities);
        $act = ActivationStore::lock($pdo, $id);
        if (!in_array($act['status'], $states, true)) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $locked = LockedWallet::lockMany($pdo, $managers, []);
        $leaf = $locked[$legs['leaf']] ?? throw new DomainError('REVERSAL_FINANCIAL_REFUSED', 'The reseller wallet no longer exists.');
        $recipients = [];
        foreach (array_keys($legs['credits']) as $m) {
            $recipients[] = $locked[$m] ?? throw new DomainError('REVERSAL_FINANCIAL_REFUSED', 'A parent reseller wallet no longer exists.');
        }
        LedgerService::assertActivationReversible($pdo, $legs, $leaf, ...$recipients);
        return [$legs, $leaf, $recipients, $act];
    }

    // ------------------------------------------------------------------ phase 2a

    /** COMPLETED -> REVERSAL_PENDING under the tree lock, after re-proving phase 0 inside it. */
    private static function start(ActivationActor $actor, array $act, array $context, string $reason): void
    {
        $id = (int) $act['id'];
        try {
            FinanceTx::run(static function (\PDO $pdo) use ($actor, $act, $id, $context, $reason): void {
                $row = ActivationStore::lock($pdo, $id);
                if ($row['status'] !== ActivationState::COMPLETED) {
                    throw new DomainError('ACTIVATION_STATE_CONFLICT');
                }
                $failed = self::externalChecks($pdo, $row, $context);
                if ($failed !== []) {
                    throw new DomainError('REVERSAL_EXTERNAL_STATE_CHANGED', implode(',', $failed));
                }
                $legs = LedgerService::activationReversalLegs($pdo, $id);
                ActivationStore::transition($pdo, $id, [ActivationState::COMPLETED], ActivationState::REVERSAL_PENDING, $actor, [
                    'event_type' => 'RECONCILIATION',
                    'metadata' => ['reversal' => 'started', 'reason' => $reason, 'retail' => 'not_collected_by_system', 'legs' => [
                        'leaf' => $legs['leaf'], 'debit' => $legs['debit']['amount']->format(),
                        'commissions' => array_map(static fn(array $l): string => $l['amount']->format(), $legs['credits']),
                        'provider_settlement' => $act['provider_settlement_amount'],
                    ]],
                ], ['error_code' => null, 'error_message' => null]);
                self::audit($actor, $act, ['reversal' => 'started'], $pdo, $reason);
            }, true);
        } catch (DomainError $e) {
            if ($e->reason === 'REVERSAL_EXTERNAL_STATE_CHANGED') {
                self::audit($actor, $act, ['refused' => $e->reason, 'checks' => explode(',', $e->getMessage())], null);
                throw new DomainError('REVERSAL_EXTERNAL_STATE_CHANGED');
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------ phase 2b

    /**
     * RADIUS compensation outside any transaction. Current state = target -> restore the captured prior rows
     * and read back; = prior -> record the evidence; anything else -> hold (REVERSAL_PENDING + error).
     */
    private static function compensateRadius(ActivationActor $actor, array $act): void
    {
        $pdo = FinanceTx::pdo();
        $id = (int) $act['id'];
        $context = self::context($pdo, $id) ?? throw new \LogicException('reversal started without its context');
        $planId = (int) ActivationService::plan($act)['id'];
        $salt = (string) $act['reference'];
        $read = static function () use ($context, $planId, $salt): ?array {
            try {
                return RadiusState::read((string) $context['target']['username'], $planId, $salt);
            } catch (\Throwable $e) {
                \Reseller\Security\ErrorReporter::report($e, 'activation-reversal-read');
                return null;
            }
        };
        $current = $read();
        $result = null;
        if ($current !== null && RadiusState::same($current, $context['target'])) {
            $plan = RestorePlan::build($act);
            if (isset($plan['refused'])) {
                self::hold($actor, $id, $plan['refused'], null);
            }
            $result = RadiusProvisioner::connection()->restore($plan['username'], $plan['check'], $plan['reply'], $plan['groups']);
            ActivationFaults::hit('reversal.after_restore');
            $current = $read();
        }
        if ($current !== null && RadiusState::sameSubscriber($current, $context['prior'])) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::note($pdo, $id, ActivationState::REVERSAL_PENDING, $actor,
                ['event_type' => 'PROVISIONING_RESULT', 'provisioning_result' => ProvisionResult::SUCCESS,
                    'metadata' => ['reversal' => 'radius_restored', 'verified' => true, 'restore' => $result === null ? 'already_prior' : $result->status . ':' . $result->code]],
                ['error_code' => null, 'error_message' => null]));
            return;
        }
        if ($current !== null && RadiusState::same($current, $context['target'])) {
            // the restore did not change RADIUS (confirmed failure): nothing changed, the reversal can be repeated
            self::hold($actor, $id, 'REVERSAL_RADIUS_NOT_RESTORED', $result);
        }
        self::hold($actor, $id, 'REVERSAL_EXTERNAL_STATE_CHANGED', $result, $current === null ? ['radius_unreadable']
            : array_merge(array_map(static fn(string $c): string => 'prior:' . $c, RadiusState::diff($current, $context['prior'])),
                array_map(static fn(string $c): string => 'target:' . $c, RadiusState::diff($current, $context['target']))));
    }

    /** @param list<string> $components */
    private static function hold(ActivationActor $actor, int $id, string $code, ?ProvisionResult $result, array $components = []): never
    {
        $message = DomainError::MESSAGES[$code] ?? 'The RADIUS state could not be restored; the reversal is held and can be repeated.';
        self::tx(static fn(\PDO $pdo) => ActivationStore::note($pdo, $id, ActivationState::REVERSAL_PENDING, $actor,
            ['event_type' => 'PROVISIONING_RESULT', 'provisioning_result' => $result?->status ?? ProvisionResult::UNKNOWN, 'error_code' => $code,
                'error_message' => $result?->message ?? $message, 'metadata' => ['reversal' => 'radius_held', 'differs' => $components]],
            ['error_code' => $code, 'error_message' => $message]));
        throw new DomainError($code, $message);
    }

    // ------------------------------------------------------------------ phase 3

    private static function commit(ActivationActor $actor, array $act, string $reason): void
    {
        $id = (int) $act['id'];
        $context = self::context(FinanceTx::pdo(), $id) ?? throw new \LogicException('reversal started without its context');
        try {
            FinanceTx::run(static function (\PDO $pdo) use ($actor, $id, $context, $reason): void {
                [$legs, $leaf, $recipients, $row] = self::lockLegs($pdo, $actor, $id, [ActivationState::REVERSAL_PENDING]);
                if (!self::restored($pdo, $id)) {
                    throw new DomainError('ACTIVATION_STATE_CONFLICT');
                }
                if (!LegacyBridge::unchangedSince($pdo, $context['legacy'], true)) {
                    throw new DomainError('REVERSAL_EXTERNAL_STATE_CHANGED', 'legacy_recharge');
                }
                $posted = LedgerService::postActivationReversal($pdo, $id, $actor->finance(), $reason, $leaf, ...$recipients);
                $legacy = LegacyBridge::reverse($pdo, $row, $context['legacy'], $reason, $actor->userId);
                ActivationFaults::hit('reversal.after_ledger');
                ActivationStore::transition($pdo, $id, [ActivationState::REVERSAL_PENDING], ActivationState::REVERSED, $actor, [
                    'event_type' => 'FINANCIAL',
                    'metadata' => ['reversal' => 'completed', 'credit_entry_id' => $posted->credit->entryId,
                        'clawback_entry_ids' => array_map(static fn($e): int => $e->entryId, $posted->clawbacks),
                        'provider_settlement_reversed' => $row['provider_settlement_amount'],
                        'invoice' => $legacy['invoice'], 'recharge_id' => $legacy['recharge'], 'transaction_id' => $legacy['transaction']],
                ], ['error_code' => 'REVERSED', 'error_message' => 'The activation was reversed.']);
                self::audit($actor, $row, ['reversal' => 'completed', 'invoice' => $legacy['invoice']], $pdo, $reason);
            }, true);
        } catch (DomainError $e) {
            if (!in_array($e->reason, ['REVERSAL_FINANCIAL_REFUSED', 'REVERSAL_EXTERNAL_STATE_CHANGED', 'ALREADY_REVERSED'], true)) {
                throw $e;
            }
            $code = $e->reason === 'ALREADY_REVERSED' ? 'REVERSAL_FINANCIAL_REFUSED' : $e->reason;
            // nothing of phase 3 committed; RADIUS is restored (evidence kept); visible to the operator
            self::tx(static fn(\PDO $pdo) => ActivationStore::note($pdo, $id, ActivationState::REVERSAL_PENDING, $actor,
                ['event_type' => 'ANNOTATION', 'error_code' => $code, 'error_message' => $e->getMessage(), 'metadata' => ['reversal' => 'commit_refused']],
                ['error_code' => $code, 'error_message' => (DomainError::MESSAGES[$code] ?? $code) . ' RADIUS was restored; the reversal is pending operator review.']));
            throw new DomainError($code, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ helpers

    private static function find(string $reference): array
    {
        return ActivationStore::byReference(FinanceTx::pdo(), $reference) ?? throw new DomainError('NOT_FOUND');
    }

    private static function audit(ActivationActor $actor, array $act, array $after, ?\PDO $pdo, ?string $reason = null): void
    {
        if ($actor->context === null) {
            return;
        }
        AuditLog::record('activation.reverse', $actor->context, [
            'target_type' => 'activation', 'target_user_id' => (int) $act['manager_id'],
            'target_snapshot' => ['reference' => $act['reference'], 'customer_id' => (int) $act['customer_id']],
            'before' => ['status' => $act['status']], 'after' => $after, 'reason' => $reason,
        ], $pdo);
    }

    private static function tx(callable $fn): mixed
    {
        return ActivationService::tx($fn);
    }
}
