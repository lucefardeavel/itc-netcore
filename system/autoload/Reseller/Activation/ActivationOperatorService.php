<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Finance\FinanceTx;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;

/**
 * WP6B (owner decisions OD-7 / OD-8, slice-0 correction): the operator-facing view of the accepted activation
 * primitives. It decides which operator actions a page may offer for an activation, from one table that mirrors the
 * state preconditions of the accepted primitives exactly, so no page renders an action the backend always refuses:
 *
 *  - reconcile  ReconciliationService::reconcile  RECONCILIATION_REQUIRED; an in-flight state only once stale
 *                                                 (>= ReconciliationService::STALE_SECONDS untouched)
 *  - rollback   ReconciliationService::rollback   RECONCILIATION_REQUIRED, VERIFIED, ROLLBACK_PENDING
 *  - reverse    ActivationReversalService::reverse COMPLETED, REVERSAL_PENDING
 *
 * It introduces no transition and no rule of its own: every action still goes to its own route and its own accepted
 * service, which re-checks capability, scope and state.
 */
final class ActivationOperatorService
{
    public const RECONCILE = 'reconcile';
    public const ROLLBACK = 'rollback';
    public const REVERSE = 'reverse';

    /** In-flight states: ReconciliationService::reconcile accepts them only once stale. */
    public const IN_FLIGHT = [ActivationState::PROVISIONING, ActivationState::PROVISIONED, ActivationState::VERIFYING, ActivationState::FINANCIAL_COMMITTING];

    /** @var array<string,list<string>> state => operations its accepted primitive accepts (stale-only ones excluded) */
    public const STATE_OPERATIONS = [
        ActivationState::RECONCILIATION_REQUIRED => [self::RECONCILE, self::ROLLBACK],
        ActivationState::VERIFIED => [self::ROLLBACK],
        ActivationState::ROLLBACK_PENDING => [self::ROLLBACK],
        ActivationState::COMPLETED => [self::REVERSE],
        ActivationState::REVERSAL_PENDING => [self::REVERSE],
    ];

    /** Capability (scope G) each operation requires; the services enforce it again. */
    public const CAPABILITY = [
        self::RECONCILE => C::ACTIVATION_CREATE,
        self::ROLLBACK => C::ACTIVATION_CREATE,
        self::REVERSE => C::ACTIVATION_REVERSE,
    ];

    /**
     * The operator actions this actor may be offered for the activation (by reference), in display order. Visibility of
     * the activation itself is the caller's (ActivationReadService::view); this only decides the actions.
     *
     * @return list<string>
     */
    public static function actionsFor(ActorContext $a, mixed $reference): array
    {
        if (!is_string($reference) || !preg_match('/^ACT-[0-9A-F]{16}$/D', $reference)) {
            throw new DomainError('NOT_FOUND');
        }
        $act = ActivationStore::byReference(FinanceTx::pdo(), $reference) ?? throw new DomainError('NOT_FOUND');
        return self::actions($a, (string) $act['status'], self::age((string) $act['updated_at']));
    }

    /**
     * The table lookup itself (state + seconds since the last update).
     *
     * @return list<string>
     */
    public static function actions(ActorContext $a, string $status, int $ageSeconds): array
    {
        $ops = self::STATE_OPERATIONS[$status] ?? [];
        if (in_array($status, self::IN_FLIGHT, true) && $ageSeconds >= ReconciliationService::STALE_SECONDS) {
            $ops = [self::RECONCILE];
        }
        return array_values(array_filter($ops, static fn(string $op): bool => PermissionService::scopeOf($a, self::CAPABILITY[$op]) === 'G'));
    }

    /** Seconds since the activation row was last updated (UTC), as ReconciliationService measures staleness. */
    private static function age(string $updatedAt): int
    {
        $updated = new \DateTimeImmutable($updatedAt, new \DateTimeZone('UTC'));
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $updated->getTimestamp();
    }
}
