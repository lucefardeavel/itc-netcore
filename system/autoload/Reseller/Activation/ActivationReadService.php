<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\LegacyReprovisionService;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\Money;
use Reseller\Finance\SettlementReadModel;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\DataScope;
use Reseller\Security\PermissionService;

/**
 * WP6A (owner decision OD-A): the read-only activation read model of the staff UI (Subscriber 360 history,
 * activation list / status, dashboard counts). It holds no mutation authority.
 *
 * Visibility is exactly ActivationService::status() (activation.read): G every activation; T activations whose
 * paying manager (historical owner) is in the actor's subtree; A activations of customers currently assigned to
 * the Sales account. Out-of-scope and nonexistent references are both NOT_FOUND.
 *
 * Only display fields leave this service. Snapshots, chains, provider settlement and wallet internals never do;
 * the approved money display fields are the activation's sealed wholesale cost (Admin / SuperAdmin, and an Agent
 * for activations paid by its own wallet) and its sealed retail price (every reader), plus a credit state word
 * (reserved / charged / released) without amounts, so "released" is only said when the reservation proves it.
 */
final class ActivationReadService
{
    public const PAGE_SIZE = 30;

    /** Status groups of the list filter and the dashboard. */
    public const GROUPS = [
        'processing' => [ActivationState::CREATED, ActivationState::VALIDATED, ActivationState::FUNDS_RESERVED, ActivationState::PROVISIONING,
            ActivationState::PROVISIONED, ActivationState::VERIFYING, ActivationState::VERIFIED, ActivationState::FINANCIAL_COMMITTING, ActivationState::RETRY_PENDING],
        'attention' => [ActivationState::RECONCILIATION_REQUIRED, ActivationState::ROLLBACK_PENDING, ActivationState::REVERSAL_PENDING],
        'completed' => [ActivationState::COMPLETED],
        'failed' => [ActivationState::FAILED, ActivationState::CANCELLED, ActivationState::ROLLED_BACK],
        'reversed' => [ActivationState::REVERSED],
    ];

    /**
     * One page of the activations visible to the actor, newest first.
     *
     * @param array{group?:string,customer?:int} $filter
     * @return array{rows:list<array<string,mixed>>,page:int,pages:int,total:int}
     */
    public static function list(ActorContext $a, array $filter = [], int $page = 1): array
    {
        [$where, $params] = self::visible($a);
        $group = $filter['group'] ?? '';
        if ($group !== '' && isset(self::GROUPS[$group])) {
            $where .= ' AND a.status IN (' . implode(', ', array_fill(0, count(self::GROUPS[$group]), '?')) . ')';
            $params = [...$params, ...self::GROUPS[$group]];
        }
        if (isset($filter['customer'])) {
            $where .= ' AND a.customer_id = ?';
            $params[] = (int) $filter['customer'];
        }
        $pdo = FinanceTx::pdo();
        $total = ActivationStore::displayCount($pdo, $where, $params);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = ActivationStore::displayPage($pdo, $where, $params, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        return ['rows' => array_map(static fn(array $r): array => self::shapeRow($r), $rows), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /**
     * Dashboard counts within the actor's visibility: per group, all time for open groups (processing, attention),
     * the last 7 days for terminal groups.
     *
     * @return array<string,int>
     */
    public static function counts(ActorContext $a): array
    {
        [$where, $params] = self::visible($a);
        $pdo = FinanceTx::pdo();
        $open = ActivationStore::statusCounts($pdo, $where, $params);
        $recent = ActivationStore::statusCounts($pdo, $where, $params, gmdate('Y-m-d H:i:s', time() - 7 * 86400));
        $out = [];
        foreach (self::GROUPS as $g => $states) {
            $src = in_array($g, ['processing', 'attention'], true) ? $open : $recent;
            $out[$g] = array_sum(array_map(static fn(string $s): int => $src[$s] ?? 0, $states));
        }
        return $out;
    }

    /**
     * The status view of one activation: display fields, the role-filtered sealed money fields, the credit state,
     * the frozen service period and the event timeline (no metadata).
     *
     * @return array{activation:array<string,mixed>,events:list<array<string,mixed>>}
     */
    public static function view(ActorContext $a, mixed $reference): array
    {
        $status = ActivationService::status($a, $reference); // activation.read visibility (NOT_FOUND otherwise)
        $act = $status['activation'];
        $pdo = FinanceTx::pdo();
        $row = ActivationStore::displayById($pdo, (int) $act['id']) ?? throw new DomainError('NOT_FOUND');
        $out = self::shapeRow($row);
        $out['error_code'] = $act['error_code'] !== null ? (string) $act['error_code'] : null;
        $out['retail'] = $act['retail_price'] !== null ? Money::fromDb($act['retail_price'])->format() : null;
        $out['wholesale'] = self::seesWholesale($a, (int) $act['manager_id']) && $act['wholesale_cost'] !== null ? Money::fromDb($act['wholesale_cost'])->format() : null;
        $reservation = ActivationStore::reservation($pdo, (int) $act['id']);
        $out['credit'] = match ($reservation['status'] ?? null) {
            'ACTIVE' => 'reserved',
            'COMMITTED' => $act['status'] === ActivationState::REVERSED ? 'refunded' : 'charged',
            'RELEASED', 'EXPIRED' => 'released',
            default => null,
        };
        try {
            $period = ActivationStore::period($pdo, (int) $act['id'])['period'];
            $out['period_start'] = (string) $period['start'];
            $out['period_expiry'] = (string) $period['expiry'];
        } catch (\LogicException) {
            $out['period_start'] = $out['period_expiry'] = null;
        }
        $out['terminal'] = ActivationState::isTerminal((string) $act['status']);
        $events = array_map(static fn(array $e): array => [
            'sequence_no' => (int) $e['sequence_no'], 'event_type' => (string) $e['event_type'], 'from_state' => $e['from_state'] !== null ? (string) $e['from_state'] : null,
            'to_state' => (string) $e['to_state'], 'provisioning_result' => $e['provisioning_result'] !== null ? (string) $e['provisioning_result'] : null,
            'error_code' => $e['error_code'] !== null ? (string) $e['error_code'] : null, 'created_at' => (string) $e['created_at'],
        ], $status['events']);
        // WP6B: the settlement card (immutable ledger legs + sealed snapshot, role-projected by the finance read model);
        // absent when nothing of it is in the actor's ledger.read scope (e.g. Sales)
        $settlement = null;
        if (PermissionService::can($a, C::LEDGER_READ)) {
            try {
                $settlement = SettlementReadModel::forActivation($a, (int) $act['id']);
            } catch (DomainError) {
                $settlement = null;
            }
        }
        return ['activation' => $out, 'events' => $events, 'settlement' => $settlement];
    }

    /**
     * WP6B: references of the given activation ids that the actor may read (activation.read visibility; others are
     * simply absent). Used to link finance rows (ledger / settlement) to their activation pages.
     *
     * @param list<int> $ids
     * @return array<int,string> id => reference
     */
    public static function references(ActorContext $a, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn($v): bool => is_int($v) && $v > 0)));
        if ($ids === [] || !PermissionService::can($a, C::ACTIVATION_READ)) {
            return [];
        }
        [$pred, $params] = self::visible($a);
        $rows = ActivationStore::displayPage(FinanceTx::pdo(), 'a.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND ' . $pred,
            array_merge($ids, $params), count($ids), 0);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['reference'];
        }
        return $out;
    }

    /**
     * Subscriber 360 operational flags for a customer the actor may read: the visible in-flight activation (if any)
     * and an unconfirmed legacy re-provision (the latest reprovision.* audit outcome, as the drift report).
     *
     * @return array{in_flight:?array{reference:string,status:string},reprovision_unconfirmed:bool}
     */
    public static function customerFlags(ActorContext $a, int $customerId): array
    {
        if (!DataScope::customerInScope($a, C::CUSTOMER_READ, $customerId)) {
            throw new DomainError('NOT_FOUND');
        }
        $pdo = FinanceTx::pdo();
        $inFlight = null;
        $flight = ActivationStore::inFlight($pdo, $customerId);
        if ($flight !== null && PermissionService::can($a, C::ACTIVATION_READ)) {
            [$where, $params] = self::visible($a);
            if (ActivationStore::displayCount($pdo, $where . ' AND a.id = ?', [...$params, (int) $flight['id']]) === 1) {
                $inFlight = ['reference' => (string) $flight['reference'], 'status' => (string) $flight['status']];
            }
        }
        $last = Tx::row($pdo, "SELECT after_json FROM tbl_reseller_audit_events WHERE event_type LIKE 'reprovision.%' AND target_customer_id = ? ORDER BY id DESC LIMIT 1", [$customerId]);
        $after = $last !== null ? json_decode((string) $last['after_json'], true) : null;
        return ['in_flight' => $inFlight, 'reprovision_unconfirmed' => is_array($after) && ($after['outcome'] ?? null) === LegacyReprovisionService::UNCONFIRMED];
    }

    /** @return array{0:string,1:list<mixed>} the activation.read visibility predicate over alias `a` */
    private static function visible(ActorContext $a): array
    {
        ResellerTreeService::authorize($a, C::ACTIVATION_READ);
        return match (PermissionService::scopeOf($a, C::ACTIVATION_READ)) {
            'G' => ['(1 = 1)', []],
            'T' => ['(a.manager_id IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ?))', [(int) $a->userId]],
            // WP9-B / N-15 P5: a Sales account sees only activations paid by the customer's CURRENT owner
            'A' => (static function (?array $p): array {
                return $p === null || $p[0] === '(1 = 0)' ? ['(1 = 0)', []]
                    : ['(' . $p[0] . ' AND a.manager_id = (SELECT pco.manager_id FROM tbl_customers pco WHERE pco.id = a.customer_id))', $p[1]];
            })(DataScope::byCustomerColumnPredicate($a, C::ACTIVATION_READ, 'a.customer_id')),
            default => ['(1 = 0)', []],
        };
    }

    /** Wholesale is shown to the global scope, and to an Agent only for activations paid by its own wallet. */
    private static function seesWholesale(ActorContext $a, int $managerId): bool
    {
        $scope = PermissionService::scopeOf($a, C::ACTIVATION_READ);
        return $scope === 'G' || ($scope === 'T' && $a->role === 'Agent' && $managerId === (int) $a->userId);
    }

    /** @param array<string,mixed> $r */
    private static function shapeRow(array $r): array
    {
        return [
            'reference' => (string) $r['reference'], 'action' => (string) $r['action'], 'status' => (string) $r['status'],
            'error_code' => $r['error_code'] !== null ? (string) $r['error_code'] : null,
            'customer_id' => (int) $r['customer_id'], 'customer_username' => (string) ($r['customer_username'] ?? ''), 'customer_fullname' => (string) ($r['customer_fullname'] ?? ''),
            'plan_id' => (int) $r['plan_id'], 'plan_name' => (string) ($r['plan_name'] ?? ''), 'plan_type' => (string) ($r['plan_type'] ?? ''),
            'manager_username' => (string) ($r['manager_username'] ?? ''), 'actor_username' => (string) ($r['actor_username'] ?? ''), 'actor_type' => (string) $r['actor_type'],
            'legacy_invoice' => $r['legacy_invoice'] !== null ? (string) $r['legacy_invoice'] : null,
            'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at'],
        ];
    }
}
