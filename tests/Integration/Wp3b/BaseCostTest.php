<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\FinanceTx;
use Reseller\Finance\PricingService;
use Reseller\Tests\Support\PricingTestCase;

/** WP3B §7: provider base cost, resale enablement, Balance plans (F-4), zero wholesale (F-3). */
final class BaseCostTest extends PricingTestCase
{
    private function resolve(int $manager, int $plan): mixed
    {
        return FinanceTx::run(static fn(\PDO $pdo) => PricingService::resolveInTx($pdo, $manager, $plan), true);
    }

    private function reasons(int $manager, int $plan): array
    {
        return PricingService::quote($this->ctx('ad'), $manager, $plan)['reasons'];
    }

    public function testMissingBaseRowMeansNotResellable(): void
    {
        $plan = $this->plan();
        $t = $this->funded('100');
        $this->assertSame(['PLAN_NOT_RESELLABLE'], $this->reasons($t, $plan));
        $this->refused('PLAN_NOT_RESELLABLE', fn() => $this->resolve($t, $plan));
        $this->refused('PLAN_NOT_RESELLABLE', fn() => PricingService::setChildWholesale($this->ctx('ad'), $t, $plan, '5'));
        $this->refused('PLAN_NOT_RESELLABLE', fn() => PricingService::setRetail($this->ctx($t), $t, $plan, '5'));
        $this->refused('PLAN_NOT_RESELLABLE', fn() => PricingService::setChildAvailability($this->ctx('ad'), $t, $plan, false));
        $this->refused('PLAN_NOT_RESELLABLE', fn() => PricingService::setResaleEnabled($this->ctx('ad'), $plan, true));
    }

    public function testBaseIsCreatedDisabledAuditedAndThenEnabled(): void
    {
        $plan = $this->plan('20');
        $t = $this->funded('100');
        $audits = count($this->audit('pricing.base_cost_set'));
        $r = PricingService::setBaseCost($this->ctx('ad'), $plan, '10', 'provider price list 2026');
        $this->assertTrue($r->changed);
        $row = static::$db->rows('SELECT * FROM tbl_reseller_plan_base_costs WHERE plan_id = ?', [$plan])[0];
        $this->assertSame(['10.00', '0', $this->sid('ad'), $this->sid('ad')], [$row['base_cost'], (string) $row['is_resale_enabled'], (int) $row['created_by'], (int) $row['updated_by']]);
        $a = $this->audit('pricing.base_cost_set');
        $this->assertCount($audits + 1, $a);
        $last = end($a);
        $this->assertSame(['plan_id' => $plan, 'name_plan' => static::$db->value('SELECT name_plan FROM tbl_plans WHERE id = ?', [$plan]), 'type' => 'Hotspot'], json_decode($last['target_snapshot'], true));
        $this->assertSame(['base_cost' => null, 'resale_enabled' => null], json_decode($last['before_json'], true));
        $this->assertSame(['base_cost' => '10.00', 'resale_enabled' => false], json_decode($last['after_json'], true));
        $this->assertSame('provider price list 2026', $last['reason']);
        $this->assertSame(['PLAN_RESALE_DISABLED'], $this->reasons($t, $plan));
        $this->refused('PLAN_RESALE_DISABLED', fn() => $this->resolve($t, $plan));
        $e = PricingService::setResaleEnabled($this->ctx('ad'), $plan, true);
        $this->assertTrue($e->changed);
        $this->assertCount(1, array_filter($this->audit('pricing.resale_enable'), static fn($x) => json_decode($x['target_snapshot'], true)['plan_id'] === $plan));
        $snap = $this->resolve($t, $plan);
        $this->assertSame(['10.00', '20.00', '10.00'], [$snap->leafDebit->format(), $snap->retail->format(), $snap->providerSettlement->format()]);
        // identical values are idempotent no-ops (nothing written, nothing audited)
        $before = $this->checksums();
        $this->assertFalse(PricingService::setBaseCost($this->ctx('ad'), $plan, '10.00')->changed);
        $this->assertFalse(PricingService::setResaleEnabled($this->ctx('ad'), $plan, true)->changed);
        $this->assertSame([], \Reseller\Tests\Support\DbProbe::changed($before, $this->checksums()));
    }

    public function testResaleDisableBlocksEveryoneAndReenables(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        $ids = $this->chain($plan, ['12', '15', null]);
        PricingService::setResaleEnabled($this->ctx('sa'), $plan, false, 'provider stop-sell');
        foreach ($ids as $id) {
            $this->assertSame(['PLAN_RESALE_DISABLED'], $this->reasons($id, $plan));
        }
        $this->assertCount(1, array_filter($this->audit('pricing.resale_disable'), static fn($x) => json_decode($x['target_snapshot'], true)['plan_id'] === $plan));
        PricingService::setResaleEnabled($this->ctx('ad'), $plan, true);
        foreach ($ids as $id) {
            $this->assertSame([], $this->reasons($id, $plan));
        }
    }

    public function testZeroBaseIsValidButAZeroLeafIsNotActivatable(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '0');
        [$top, $child] = $this->chain($plan, [null, null]);
        $q = PricingService::quote($this->ctx('ad'), $child, $plan);
        $this->assertSame(['0.00', ['ZERO_WHOLESALE_NOT_SUPPORTED']], [$q['effective_wholesale'], $q['reasons']], 'zero is configuration data, not activatable');
        $this->refused('ZERO_WHOLESALE_NOT_SUPPORTED', fn() => $this->resolve($child, $plan));
        // base 0 with a positive leaf is valid (owner example: base 0 -> R1 5 -> R2 8)
        PricingService::setChildWholesale($this->ctx('ad'), $top, $plan, '5');
        PricingService::setChildWholesale($this->ctx($top), $child, $plan, '8');
        $s = $this->resolve($child, $plan);
        $this->assertSame(['8.00', '5.00', '5.00'], [$s->leafDebit->format(), $s->providerSettlement->format(), $s->providerMarginOverBase->format()]);
        $this->assertSame([$top => '3.00'], array_map(static fn($m) => $m->format(), $s->commissionRecipients()));
    }

    public function testMoneyBoundariesAndMalformedInput(): void
    {
        $plan = $this->plan('9999999999999999.99');
        PricingService::setBaseCost($this->ctx('ad'), $plan, '9999999999999999.99');
        $this->assertSame('9999999999999999.99', static::$db->value('SELECT base_cost FROM tbl_reseller_plan_base_costs WHERE plan_id = ?', [$plan]));
        foreach (['10000000000000000', '10000000000000000.00', '1,000', '-1', '1e3', '0x10', ' 5', '5.001', '', 10, 10.5, null, true] as $bad) {
            $this->refused('INVALID_AMOUNT', fn() => PricingService::setBaseCost($this->ctx('ad'), $plan, $bad));
        }
        $this->refused('NOT_FOUND', fn() => PricingService::setBaseCost($this->ctx('ad'), 99999999, '5'));
        $this->refused('NOT_FOUND', fn() => PricingService::setBaseCost($this->ctx('ad'), 'x', '5'));
        $this->refused('INVALID_REASON', fn() => PricingService::setBaseCost($this->ctx('ad'), $plan, '5', 'x'));
    }

    public function testBalancePlansAreNotResellable(): void
    {
        $plan = $this->plan('50', 'Balance');
        $t = $this->funded('100');
        $this->refused('BALANCE_PLAN_NOT_RESELLABLE', fn() => PricingService::setBaseCost($this->ctx('ad'), $plan, '5'));
        $this->assertSame(['BALANCE_PLAN_NOT_RESELLABLE'], $this->reasons($t, $plan));
        $this->refused('BALANCE_PLAN_NOT_RESELLABLE', fn() => $this->resolve($t, $plan));
        // Hotspot, PPPOE and VPN service plans are eligible (no other type is classified)
        foreach (['PPPOE', 'Hotspot'] as $type) {
            $p = $this->plan('50', $type);
            $this->base($p, '5');
            $this->assertSame('5.00', $this->resolve($t, $p)->leafDebit->format(), $type);
        }
        if (in_array('VPN', $this->planTypes(), true)) {
            $p = $this->plan('50', 'VPN');
            $this->base($p, '5');
            $this->assertSame('5.00', $this->resolve($t, $p)->leafDebit->format(), 'VPN');
        }
    }

    public function testOnlyAdminAndSuperAdminMayMutate(): void
    {
        $plan = $this->plan();
        foreach (['A', 'A1', 'rp', 'B'] as $u) {
            $this->refused('FORBIDDEN', fn() => PricingService::setBaseCost($this->ctx($u), $plan, '5'));
        }
        PricingService::setBaseCost($this->ctx('sa'), $plan, '5');
        foreach (['A', 'A1', 'rp'] as $u) {
            $this->refused('FORBIDDEN', fn() => PricingService::setResaleEnabled($this->ctx($u), $plan, true));
        }
    }

    public function testUpstreamBaseRaiseCannotInvalidateAnExplicitOverride(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        [$top, $child] = $this->chain($plan, ['12', null]);
        $this->refused('BASE_COST_DESCENDANT_CONFLICT', fn() => PricingService::setBaseCost($this->ctx('ad'), $plan, '13'));
        $this->assertTrue(PricingService::setBaseCost($this->ctx('ad'), $plan, '12')->changed, 'equal to the override is allowed');
        $this->assertTrue(PricingService::setBaseCost($this->ctx('ad'), $plan, '5')->changed, 'a decrease is always valid');
        // Admin can always fix bottom-up: raise the override first, then the base
        PricingService::setChildWholesale($this->ctx('ad'), $top, $plan, '20');
        $this->assertTrue(PricingService::setBaseCost($this->ctx('ad'), $plan, '13')->changed);
        $this->assertSame('20.00', PricingService::quote($this->ctx('ad'), $child, $plan)['effective_wholesale'], 'inheriting descendant follows its parent');
    }

    /** @return list<string> */
    private function planTypes(): array
    {
        $t = (string) static::$db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_plans' AND COLUMN_NAME = 'type'");
        preg_match_all("/'([^']+)'/", $t, $m);
        return $m[1];
    }
}
