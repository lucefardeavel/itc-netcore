<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ActorContext;

/**
 * Role-filtered presentation of a pricing resolution (WP3B owner decision F-8). The INTERNAL
 * authoritative resolution / PricingSnapshot is complete and is never weakened; this projection
 * decides what a viewer may see. No route, API or UI uses it in WP3B: it is the future-facing
 * security contract for WP6B.
 *
 *   Admin / SuperAdmin (scope G)  everything: provider base cost, the full chain, every margin,
 *                                 provider settlement.
 *   Agent (scope T)               only the part of the chain at or below itself: its own and its
 *                                 descendants' effective wholesale, retail, availability and the
 *                                 margins it and its descendants earn. Never the provider base cost,
 *                                 resale flag, ancestor identities / costs / margins or the provider
 *                                 settlement. A value inherited from above is shown as UPSTREAM.
 *   anyone else                   nothing.
 */
final class PricingReadModel
{
    /** @param array<string,mixed> $ev internal evaluation from PricingService @return array<string,mixed> */
    public static function project(ActorContext $a, string $scope, array $ev): array
    {
        if ($scope === 'G') {
            return self::full($ev);
        }
        if ($scope !== 'T' || $a->userId === null) {
            return [];
        }
        $viewer = (int) $a->userId;
        $chain = $ev['chain'] ?? [];
        $from = null;
        foreach ($chain as $i => $l) {
            if ($l['manager_id'] === $viewer) {
                $from = $i;
            }
        }
        $visible = $from === null ? [] : array_slice($chain, $from);
        $visibleIds = array_column($visible, 'manager_id');
        $leaf = $chain === [] ? null : $chain[count($chain) - 1];
        $source = null;
        if ($leaf !== null) {
            $source = match (true) {
                $leaf['source'] === PricingLink::OVERRIDE => PricingLink::OVERRIDE,
                $leaf['source'] === PricingLink::INHERITED && in_array($leaf['source_manager_id'], $visibleIds, true) => PricingLink::INHERITED,
                default => 'UPSTREAM',
            };
        }
        return [
            'plan_id' => $ev['plan_id'],
            'manager_id' => $ev['manager_id'],
            'saleable' => $ev['reasons'] === [],
            'reasons' => $ev['reasons'],
            'effective_wholesale' => $leaf !== null ? $leaf['effective_wholesale']->format() : null,
            'wholesale_source' => $source,
            'effective_retail' => isset($ev['retail']) ? $ev['retail']->format() : null,
            'retail_source' => $ev['retail_source'] ?? null,
            'available' => $ev['available'] ?? false,
            'chain' => array_map(static fn(array $l) => [
                'manager_id' => $l['manager_id'],
                'effective_wholesale' => $l['effective_wholesale']->format(),
                'margin' => $l['margin']->format(),
            ], $visible),
        ];
    }

    /** @param array<string,mixed> $ev @return array<string,mixed> */
    private static function full(array $ev): array
    {
        $chain = $ev['chain'] ?? [];
        $leaf = $chain === [] ? null : $chain[count($chain) - 1];
        $commissions = [];
        for ($i = count($chain) - 2; $i >= 0; $i--) {
            if ($chain[$i]['margin']->isPositive()) {
                $commissions[] = ['manager_id' => $chain[$i]['manager_id'], 'amount' => $chain[$i]['margin']->format()];
            }
        }
        return [
            'plan_id' => $ev['plan_id'],
            'manager_id' => $ev['manager_id'],
            'saleable' => $ev['reasons'] === [],
            'reasons' => $ev['reasons'],
            'base_cost' => isset($ev['base']) ? $ev['base']->format() : null,
            'resale_enabled' => $ev['resale_enabled'] ?? false,
            'effective_wholesale' => $leaf !== null ? $leaf['effective_wholesale']->format() : null,
            'wholesale_source' => $leaf['source'] ?? null,
            'effective_retail' => isset($ev['retail']) ? $ev['retail']->format() : null,
            'retail_source' => $ev['retail_source'] ?? null,
            'available' => $ev['available'] ?? false,
            'chain' => array_map(static fn(array $l) => [
                'manager_id' => $l['manager_id'], 'distance' => $l['distance'],
                'effective_wholesale' => $l['effective_wholesale']->format(), 'source' => $l['source'],
                'source_manager_id' => $l['source_manager_id'], 'availability' => $l['availability'], 'margin' => $l['margin']->format(),
            ], $chain),
            'commissions' => $commissions,
            'provider_settlement' => $chain === [] ? null : $chain[0]['effective_wholesale']->format(),
        ];
    }
}
