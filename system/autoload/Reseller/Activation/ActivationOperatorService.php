<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
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
 *  - cancel     ReconciliationService::cancel     FUNDS_RESERVED, RETRY_PENDING                 (stale only, OD-7)
 *  - resume     ActivationService::execute        FUNDS_RESERVED, RETRY_PENDING, VERIFIED       (stale only, OD-7)
 *
 * Cancel / resume (owner decision OD-7) were CLI-only; this wrapper exposes them to Admin / SuperAdmin on the web
 * with the actor's own context (audited). They are offered and accepted only once the activation is stale (untouched
 * for ReconciliationService::STALE_SECONDS) so an operator never races a request that is still running; the
 * primitives themselves are unchanged.
 *
 * It introduces no transition and no rule of its own: every action still goes to its own route and its own accepted
 * service, which re-checks capability, scope and state.
 */
final class ActivationOperatorService
{
    public const RECONCILE = 'reconcile';
    public const ROLLBACK = 'rollback';
    public const REVERSE = 'reverse';
    public const CANCEL = 'cancel';
    public const RESUME = 'resume';
    public const PAGE_SIZE = 50;

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

    /** @var array<string,list<string>> state => operations accepted only once the activation is stale (OD-7) */
    public const STALE_OPERATIONS = [
        ActivationState::FUNDS_RESERVED => [self::CANCEL, self::RESUME],
        ActivationState::RETRY_PENDING => [self::CANCEL, self::RESUME],
        ActivationState::VERIFIED => [self::RESUME],
    ];

    /** Capability (scope G) each operation requires; the services enforce it again. */
    public const CAPABILITY = [
        self::RECONCILE => C::ACTIVATION_CREATE,
        self::ROLLBACK => C::ACTIVATION_CREATE,
        self::REVERSE => C::ACTIVATION_REVERSE,
        self::CANCEL => C::ACTIVATION_CREATE,
        self::RESUME => C::ACTIVATION_CREATE,
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

    /** @param array<string,mixed> $row a display row with status / updated_at @return list<string> */
    public static function actionsForRow(ActorContext $a, array $row): array
    {
        return self::actions($a, (string) $row['status'], self::age((string) $row['updated_at']));
    }

    /**
     * The table lookup itself (state + seconds since the last update).
     *
     * @return list<string>
     */
    public static function actions(ActorContext $a, string $status, int $ageSeconds): array
    {
        $ops = self::STATE_OPERATIONS[$status] ?? [];
        if ($ageSeconds >= ReconciliationService::STALE_SECONDS) {
            if (in_array($status, self::IN_FLIGHT, true)) {
                $ops = [self::RECONCILE];
            }
            $ops = array_values(array_unique(array_merge($ops, self::STALE_OPERATIONS[$status] ?? [])));
        }
        return array_values(array_filter($ops, static fn(string $op): bool => PermissionService::scopeOf($a, self::CAPABILITY[$op]) === 'G'));
    }

    /**
     * Admin / SuperAdmin web cancel of a stale pre-provision activation (FUNDS_RESERVED / RETRY_PENDING): the accepted
     * ReconciliationService::cancel (reservation released, CANCELLED) with the operator's own context (audited).
     *
     * @return array<string,mixed> the activation row after the primitive
     */
    public static function cancel(ActorContext $a, mixed $reference): array
    {
        $actor = ReconciliationService::staffActor($a);
        $act = self::stale($reference, array_keys(array_filter(self::STALE_OPERATIONS, static fn(array $ops): bool => in_array(self::CANCEL, $ops, true))));
        return ReconciliationService::cancel($actor, (string) $act['reference']);
    }

    /**
     * Admin / SuperAdmin web resume of a stale FUNDS_RESERVED / RETRY_PENDING / VERIFIED activation: the accepted
     * ActivationService::execute (the same as the CLI "resume"; RADIUS I/O outside database transactions, VERIFIED only
     * settles), with the operator's own context. Audited as activation.resume (attribution only).
     *
     * @return array<string,mixed> the activation row after the primitive
     */
    public static function resume(ActorContext $a, mixed $reference): array
    {
        $actor = ReconciliationService::staffActor($a);
        $act = self::stale($reference, array_keys(self::STALE_OPERATIONS));
        AuditLog::record('activation.resume', $a, [
            'target_type' => 'activation', 'target_user_id' => (int) $act['manager_id'],
            'target_snapshot' => ['reference' => (string) $act['reference'], 'customer_id' => (int) $act['customer_id']],
            'before' => ['status' => (string) $act['status']], 'after' => ['requested' => 'resume'],
        ]);
        return ActivationService::execute((int) $act['id'], $actor);
    }

    /**
     * Stale activations (not terminal and not already "needs attention", untouched for STALE_SECONDS), one bounded page, Admin / SuperAdmin only, each
     * with the operations it may be offered.
     *
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function stalePage(ActorContext $a, int $page = 1): array
    {
        ReconciliationService::staffActor($a);
        $pdo = FinanceTx::pdo();
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . ReconciliationService::STALE_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
        // the "needs attention" states are listed there already (ActivationReadService group), never twice
        $exclude = [...ActivationState::TERMINAL, ...ActivationReadService::GROUPS['attention']];
        $total = ActivationStore::staleCount($pdo, $cutoff, $exclude);
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = [];
        foreach (ActivationStore::stalePage($pdo, $cutoff, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE, $exclude) as $r) {
            $rows[] = $r + ['actions' => self::actions($a, (string) $r['status'], self::age((string) $r['updated_at']))];
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @param list<string> $states @return array<string,mixed> the activation, refused unless in $states and stale */
    private static function stale(mixed $reference, array $states): array
    {
        if (!is_string($reference) || !preg_match('/^ACT-[0-9A-F]{16}$/D', $reference)) {
            throw new DomainError('NOT_FOUND');
        }
        $act = ActivationStore::byReference(FinanceTx::pdo(), $reference) ?? throw new DomainError('NOT_FOUND');
        if (!in_array($act['status'], $states, true)) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        if (self::age((string) $act['updated_at']) < ReconciliationService::STALE_SECONDS) {
            throw new DomainError('ACTIVATION_IN_PROGRESS');
        }
        return $act;
    }

    /** Seconds since the activation row was last updated (UTC), as ReconciliationService measures staleness. */
    private static function age(string $updatedAt): int
    {
        $updated = new \DateTimeImmutable($updatedAt, new \DateTimeZone('UTC'));
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $updated->getTimestamp();
    }
}
