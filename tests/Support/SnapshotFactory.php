<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\LegacyRetailPrice;
use Reseller\Finance\Money;
use Reseller\Finance\PricingLink;
use Reseller\Finance\PricingSnapshot;

/** Builds sealed snapshots for unit tests with the same provenance rules as PricingService. */
final class SnapshotFactory
{
    public const PLAN = 7;
    public const STAMP = '2026-10-01 10:00:00.000000';

    /**
     * @param list<string|null> $overrides top-level -> leaf (null = inherit); managers are 101, 102, ...
     * @param array<int,int|null> $availability index => own availability override
     */
    public static function seal(string $base, array $overrides, ?string $retail = null, string $legacy = '1000', array $availability = [], string $type = 'Hotspot'): PricingSnapshot
    {
        $baseCost = Money::parseNonNegative($base);
        $k = count($overrides);
        $rows = [];
        $e = $baseCost;
        $last = null;
        foreach ($overrides as $i => $o) {
            $id = 101 + $i;
            if ($o !== null) {
                $e = Money::parseNonNegative($o);
                $last = $id;
                $rows[] = [$id, $e, PricingLink::OVERRIDE, $id, 1, self::STAMP];
            } elseif ($last !== null) {
                $rows[] = [$id, $e, PricingLink::INHERITED, $last, 1, self::STAMP];
            } else {
                $rows[] = [$id, $e, PricingLink::BASE, null, null, null];
            }
        }
        $links = [];
        foreach ($rows as $i => [$id, $c, $src, $srcId, $by, $at]) {
            $margin = $i === $k - 1 ? Money::zero() : $rows[$i + 1][1]->minus($c);
            $links[] = new PricingLink($id, $k - 1 - $i, $c, $src, $srcId, $by, $at, $availability[$i] ?? null, $margin);
        }
        $plan = PricingSnapshot::planJson(['id' => (string) self::PLAN, 'name_plan' => 'p', 'type' => $type, 'price' => $legacy]);
        $r = $retail !== null ? Money::parseNonNegative($retail) : (LegacyRetailPrice::tryParse($legacy) ?? Money::parseNonNegative('1000'));
        return PricingSnapshot::seal(
            self::PLAN, 100 + $k, self::STAMP, $baseCost, true, 1, self::STAMP, $links,
            $r, $retail !== null ? PricingSnapshot::RETAIL_OVERRIDE : PricingSnapshot::RETAIL_LEGACY_DEFAULT, $legacy,
            $retail !== null ? 100 + $k : null, $retail !== null ? self::STAMP : null, $plan,
        );
    }

    /** @return array<string,mixed> an activation row carrying the sealed snapshot */
    public static function row(PricingSnapshot $s): array
    {
        return ['manager_id' => (string) $s->leafManagerId, 'plan_id' => (string) $s->planId] + $s->activationColumns();
    }
}
