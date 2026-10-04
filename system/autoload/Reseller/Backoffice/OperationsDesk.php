<?php
namespace Reseller\Backoffice;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Activation\ActivationOperatorService;
use Reseller\Activation\ActivationReadService;
use Reseller\Activation\DriftReport;
use Reseller\Domain\DomainError;
use Reseller\Domain\LegacyCustomerBalanceService;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;

/**
 * WP6B Operations Center (owner decisions OD-7 / OD-8): Admin / SuperAdmin read model over the accepted operational
 * readers. Viewing authorizes nothing: every action button posts to its own route and accepted primitive (reconcile /
 * rollback / reverse / cancel / resume); items without an accepted primitive are informational ("CLI / operator action
 * required"). Uncertain outcomes stay uncertain. Drift runs only on demand. No SQL here.
 */
final class OperationsDesk
{
    public const PAGE_SIZE = 50;

    /** @return array<string,mixed> category counts (cheap readers only; drift is on demand) */
    public static function overview(ActorContext $a): array
    {
        self::require($a);
        $counts = ActivationReadService::counts($a);
        return [
            'attention' => $counts['attention'] ?? 0,
            'stale' => ActivationOperatorService::stalePage($a, 1)['total'],
            'held' => count(LegacyCustomerBalanceService::held()),
            'renewals' => count(DriftReport::autoRenewUnavailable()),
            'resolved' => ($counts['completed'] ?? 0) + ($counts['failed'] ?? 0) + ($counts['reversed'] ?? 0),
        ];
    }

    /** @return array<string,mixed> attention (scoped list, each with its accepted actions) and the stale page */
    public static function activations(ActorContext $a, int $page): array
    {
        self::require($a);
        $attention = ActivationReadService::list($a, ['group' => 'attention'], $page);
        foreach ($attention['rows'] as $i => $r) {
            $attention['rows'][$i]['actions'] = ActivationOperatorService::actionsForRow($a, $r);
        }
        return ['attention' => $attention, 'stale' => ActivationOperatorService::stalePage($a, $page),
            'history' => ActivationReadService::list($a, ['group' => 'reversed'], 1)];
    }

    /**
     * Drift findings by kind (on demand; RADIUS part opt-in). Each finding becomes identifiers only ("k=v" pairs) plus
     * the subscriber id when present; the readers never return secrets or voucher codes.
     *
     * @return array<string,array{count:int,items:list<array{customer_id:?int,detail:string}>}>
     */
    public static function drift(ActorContext $a, bool $radius): array
    {
        self::require($a);
        $out = [];
        foreach (DriftReport::collect($radius) as $f) {
            $detail = [];
            foreach ($f as $k => $v) {
                if ($k !== 'kind') {
                    $detail[] = $k . '=' . (is_array($v) ? implode(',', array_map('strval', $v)) : (string) $v);
                }
            }
            $kind = (string) $f['kind'];
            $out[$kind]['items'][] = ['customer_id' => isset($f['customer_id']) ? (int) $f['customer_id'] : null, 'detail' => implode(' ', $detail)];
            $out[$kind]['count'] = count($out[$kind]['items']);
        }
        ksort($out);
        return $out;
    }

    /** @return list<array<string,mixed>> legacy balance operations HELD for an operator decision (CLI only) */
    public static function held(ActorContext $a): array
    {
        self::require($a);
        return array_map(static fn(array $h): array => [
            'id' => (int) $h['id'], 'username' => (string) $h['username'], 'customer_id' => (int) $h['user_id'], 'plan' => (string) $h['plan_name'],
            'amount' => (string) $h['price'], 'channel' => (string) $h['payment_channel'], 'created' => (string) $h['created_date'],
        ], LegacyCustomerBalanceService::held());
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int} auto-renew-unavailable records, newest first */
    public static function renewals(ActorContext $a, int $page): array
    {
        self::require($a);
        $all = array_reverse(DriftReport::autoRenewUnavailable());
        $total = count($all);
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        return ['rows' => array_slice($all, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    private static function require(ActorContext $a): void
    {
        if (PermissionService::scopeOf($a, C::SYSTEM_ADMIN) !== 'G') {
            throw new DomainError('FORBIDDEN');
        }
    }
}
