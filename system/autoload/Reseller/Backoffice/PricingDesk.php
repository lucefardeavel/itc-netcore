<?php
namespace Reseller\Backoffice;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Finance\PricingService;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;

/**
 * WP6B (owner decisions OD-1 / OD-4 / OD-9): the web gateway of the pricing pages. Every figure comes from the
 * accepted PricingService bounded reads, projected by PricingReadModel (the single redaction rule — nothing here
 * re-implements visibility). Edits are only *offered* from the capability scope and the viewer's relation to the
 * target; the accepted setters decide (floors, descendant conflicts, frozen targets, self-pricing).
 */
final class PricingDesk
{
    /** @return array<string,mixed> Admin: plan list with base costs; Agent: its own catalog */
    public static function plans(ActorContext $a, int $page): array
    {
        if (PermissionService::scopeOf($a, C::PRICING_READ) === 'G') {
            return ['mode' => 'admin', 'list' => PricingService::baseCosts($a, $page), 'can_base' => PermissionService::scopeOf($a, C::PRICING_BASE_COST) === 'G'];
        }
        return ['mode' => 'own'] + self::catalog($a, (int) $a->userId, $page);
    }

    /** @return array<string,mixed> one plan across resellers (bounded page) */
    public static function plan(ActorContext $a, mixed $planId, int $page): array
    {
        $m = PricingService::matrixForPlan($a, $planId, $page);
        foreach ($m['rows'] as $i => $r) {
            $m['rows'][$i]['offers'] = self::offers($a, $r['relation']);
            $m['rows'][$i]['viewer_margin'] = self::viewerMargin($a, $r);
        }
        $m['can_base'] = PermissionService::scopeOf($a, C::PRICING_BASE_COST) === 'G';
        // a global viewer earns no margin of its own: no "Your margin" column
        $m['show_margin'] = PermissionService::scopeOf($a, C::PRICING_READ) !== 'G';
        return $m;
    }

    /** @return array<string,mixed> one reseller's catalog (bounded page) with the edits the viewer may be offered */
    public static function catalog(ActorContext $a, mixed $managerId, int $page): array
    {
        $c = PricingService::catalogForManager($a, $managerId, $page);
        $c['offers'] = self::offers($a, $c['relation']);
        foreach ($c['rows'] as $i => $r) {
            $c['rows'][$i]['viewer_margin'] = self::viewerMargin($a, $r);
        }
        return $c;
    }

    /** @return array{wholesale:bool,availability:bool,retail:bool} */
    public static function offers(ActorContext $a, string $relation): array
    {
        $scope = static fn(string $cap): string => PermissionService::scopeOf($a, $cap);
        return [
            'wholesale' => ($relation === 'global' && $scope(C::PRICING_CHILD_WHOLESALE) === 'G') || ($relation === 'child' && $scope(C::PRICING_CHILD_WHOLESALE) === 'C'),
            'availability' => ($relation === 'global' && $scope(C::PRICING_CHILD_AVAILABILITY) === 'G') || ($relation === 'child' && $scope(C::PRICING_CHILD_AVAILABILITY) === 'C'),
            'retail' => ($relation === 'global' && $scope(C::PRICING_OWN_RETAIL) === 'G') || ($relation === 'self' && $scope(C::PRICING_OWN_RETAIL) === 'S'),
        ];
    }

    /**
     * CSV of one reseller's catalog (same capability / scope / projection as the screen): page by page, each page one
     * bounded tree-locked read (never one lock over the whole catalog). Audited without contents.
     */
    public static function exportCatalog(ActorContext $a, mixed $managerId): int
    {
        $first = self::catalog($a, $managerId, 1);
        $t = static fn(string $s): string => \Lang::T($s);
        $full = PermissionService::scopeOf($a, C::PRICING_READ) === 'G';
        $columns = [
            [$t('Plan'), static fn($r) => $r['plan_name']], [$t('Type'), static fn($r) => $r['plan_type']],
            [$t('Saleable'), static fn($r) => $r['saleable']], [$t('Effective wholesale (reseller cost)'), static fn($r) => $r['effective_wholesale']],
            [$t('Wholesale source'), static fn($r) => $r['wholesale_source']], [$t('Retail (customer price)'), static fn($r) => $r['effective_retail']],
            [$t('Retail source'), static fn($r) => $r['retail_source']], [$t('Available'), static fn($r) => $r['available']],
            [$t('Reasons'), static fn($r) => implode(' ', $r['reasons'])],
        ];
        if ($full) {
            $columns[] = [$t('Provider base cost'), static fn($r) => $r['base_cost'] ?? null];
        }
        return ExportStream::csv($a, 'pricing_catalog', $first['target']['id'], [], $columns, static function (callable $sink) use ($a, $managerId, $first): int {
            $n = 0;
            for ($page = 1, $c = $first; ; $c = self::catalog($a, $managerId, ++$page)) {
                foreach ($c['rows'] as $r) {
                    $sink($r);
                    $n++;
                }
                if ($page >= $c['pages']) {
                    return $n;
                }
            }
        }, 'pricing-' . $first['target']['username'] . '.csv');
    }

    // ------------------------------------------------------------------ setters (accepted PricingService, unchanged)

    /** @return array<string,mixed> PricingResult */
    public static function setBaseCost(ActorContext $a, mixed $planId, mixed $cost, mixed $reason): array
    {
        return PricingService::setBaseCost($a, $planId, WalletDesk::amount($cost), self::reason($reason))->toArray();
    }

    /** @return array<string,mixed> */
    public static function setResale(ActorContext $a, mixed $planId, mixed $enabled, mixed $reason): array
    {
        if (!in_array($enabled, ['1', '0'], true)) {
            throw new DomainError('INVALID_INPUT');
        }
        return PricingService::setResaleEnabled($a, $planId, $enabled === '1', self::reason($reason))->toArray();
    }

    /** @return array<string,mixed> an empty value clears the child override (inherit) */
    public static function setWholesale(ActorContext $a, mixed $managerId, mixed $planId, mixed $cost, mixed $reason): array
    {
        $value = is_string($cost) && trim($cost) === '' ? null : WalletDesk::amount($cost);
        return PricingService::setChildWholesale($a, $managerId, $planId, $value, self::reason($reason))->toArray();
    }

    /** @return array<string,mixed> an empty value clears the retail override (default retail = plan price) */
    public static function setRetail(ActorContext $a, mixed $managerId, mixed $planId, mixed $price, mixed $reason): array
    {
        $value = is_string($price) && trim($price) === '' ? null : WalletDesk::amount($price);
        return PricingService::setRetail($a, $managerId, $planId, $value, self::reason($reason))->toArray();
    }

    /** @return array<string,mixed> enable | disable | inherit (clear) */
    public static function setAvailability(ActorContext $a, mixed $managerId, mixed $planId, mixed $state, mixed $reason): array
    {
        $value = match ($state) {
            'enable' => true,
            'disable' => false,
            'inherit' => null,
            default => throw new DomainError('INVALID_INPUT'),
        };
        return PricingService::setChildAvailability($a, $managerId, $planId, $value, self::reason($reason))->toArray();
    }

    /**
     * The viewer's own margin on a sale by the row's reseller, read from the projected chain (the viewer's link when it
     * is not the selling reseller itself); null when the viewer is the seller or not in the chain.
     *
     * @param array<string,mixed> $row
     */
    private static function viewerMargin(ActorContext $a, array $row): ?string
    {
        $chain = $row['chain'] ?? [];
        foreach ($chain as $i => $l) {
            if ($l['manager_id'] === (int) $a->userId && $i < count($chain) - 1) {
                return $l['margin'];
            }
        }
        return null;
    }

    /** The pricing reason is optional (WP3B); blank means none. */
    private static function reason(mixed $reason): ?string
    {
        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }
}
