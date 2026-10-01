<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\Money;
use Reseller\Finance\PricingService;
use Reseller\Finance\ReservationService;
use Reseller\Tests\Support\PricingTestCase;

/** WP3B §9/§10 + owner decision F-5 (retail invalidation) and OD-4 (retail authority). */
final class RetailTest extends PricingTestCase
{
    private function q(int $manager, int $plan): array
    {
        return PricingService::quote($this->ctx('ad'), $manager, $plan);
    }

    private function plansChecksum(): string
    {
        return (string) (static::$db->rows('CHECKSUM TABLE tbl_plans EXTENDED')[0]['Checksum'] ?? '');
    }

    public function testExplicitRetailClearToLegacyDefaultAndAuthority(): void
    {
        $plan = $this->plan('20');
        $plans = $this->plansChecksum();
        $this->base($plan, '10');
        [$top, $child] = $this->chain($plan, ['12', null]);
        $this->assertSame(['20.00', 'LEGACY_DEFAULT'], [$this->q($top, $plan)['effective_retail'], $this->q($top, $plan)['retail_source']]);
        $this->refused('RETAIL_BELOW_WHOLESALE', fn() => PricingService::setRetail($this->ctx($top), $top, $plan, '11.99'));
        $this->assertTrue(PricingService::setRetail($this->ctx($top), $top, $plan, '12', 'promotion')->changed, 'equal to wholesale is allowed (no loss-leader below it)');
        $this->assertSame(['12.00', 'OVERRIDE'], [$this->q($top, $plan)['effective_retail'], $this->q($top, $plan)['retail_source']]);
        $this->assertTrue(PricingService::setRetail($this->ctx($top), $top, $plan, null)->changed);
        $this->assertSame(['20.00', 'LEGACY_DEFAULT'], [$this->q($top, $plan)['effective_retail'], $this->q($top, $plan)['retail_source']]);
        $row = $this->priceRow($top, $plan);
        $this->assertSame(['12.00', null, null, null], [$row['wholesale_cost'], $row['retail_price'], $row['retail_set_by'], $row['retail_set_at']], 'clearing retail keeps the wholesale override');
        // OD-4: an Agent sets only its own retail; Admin/SuperAdmin may set any reseller's
        $this->refused('NOT_FOUND', fn() => PricingService::setRetail($this->ctx($top), $child, $plan, '30'));
        $this->assertTrue(PricingService::setRetail($this->ctx('ad'), $child, $plan, '30', 'administrative correction')->changed);
        $a = $this->audit('pricing.retail_set');
        $last = end($a);
        $this->assertSame([$child, $this->sid('ad')], [(int) $last['target_user_id'], (int) $last['actor_user_id']]);
        foreach (['A1', 'rp'] as $u) {
            $this->refused('FORBIDDEN', fn() => PricingService::setRetail($this->ctx($u), $child, $plan, '30'));
        }
        $this->refused('NOT_ENROLLED', fn() => PricingService::setRetail($this->ctx('E'), $this->sid('E'), $plan, '30'), 'a non-enrolled Agent is refused by the WP2 actor revalidation');
        $this->refused('PRICING_TARGET_INVALID', fn() => PricingService::setRetail($this->ctx('ad'), $this->sid('E'), $plan, '30'), 'a non-enrolled Agent has no retail');
        $this->assertSame($plans, $this->plansChecksum(), 'tbl_plans.price is never written');
    }

    public function testMalformedOrLowLegacyDefaultFailsClosed(): void
    {
        foreach (['+1000', '0100', '1,000', ''] as $raw) {
            $plan = $this->plan($raw);
            $this->base($plan, '10');
            [$top] = $this->chain($plan, [null]);
            $this->assertSame(['LEGACY_RETAIL_INVALID'], $this->q($top, $plan)['reasons'], "legacy '{$raw}' is never normalised");
            PricingService::setRetail($this->ctx($top), $top, $plan, '15');
            $this->assertSame([], $this->q($top, $plan)['reasons'], 'an explicit retail makes it saleable');
            $this->refused('RETAIL_CLEAR_INVALID', fn() => PricingService::setRetail($this->ctx($top), $top, $plan, null));
        }
        $plan = $this->plan('11');
        $this->base($plan, '10');
        [$top] = $this->chain($plan, ['12']);
        $this->assertSame(['RETAIL_BELOW_WHOLESALE'], $this->q($top, $plan)['reasons'], 'legacy default below wholesale');
        PricingService::setRetail($this->ctx($top), $top, $plan, '12.50');
        $this->refused('RETAIL_CLEAR_INVALID', fn() => PricingService::setRetail($this->ctx($top), $top, $plan, null));
    }

    /** F-5: an upstream raise is allowed; the stored retail is kept; the reseller is unsaleable until corrected. */
    public function testUpstreamRaiseInvalidatesRetailWithoutVetoOrRewrite(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$top, $child, $grand] = $this->chain($plan, ['12', '15', null]);
        PricingService::setRetail($this->ctx($child), $child, $plan, '16');
        $before = $this->sealedActivation($child, $plan);
        $beforeCols = static::$db->rows('SELECT wholesale_cost, retail_price, provider_settlement_amount, plan_snapshot, pricing_chain_snapshot FROM tbl_reseller_activations WHERE id = ?', [$before])[0];
        $r = PricingService::setChildWholesale($this->ctx($top), $child, $plan, '17', 'cost increase');
        $this->assertTrue($r->changed, 'no downstream veto');
        $this->assertSame(['RETAIL_BELOW_WHOLESALE' => [$child]], $r->warnings, 'the grandchild uses the legacy default 100 and stays saleable');
        $this->assertSame('16.00', $this->priceRow($child, $plan)['retail_price'], 'stored retail never rewritten');
        $this->assertSame(['RETAIL_BELOW_WHOLESALE'], $this->q($child, $plan)['reasons']);
        $this->assertSame([], $this->q($grand, $plan)['reasons']);
        $audits = $this->audit('pricing.wholesale_set');
        $after = json_decode((string) end($audits)['after_json'], true);
        $this->assertSame([['reason' => 'RETAIL_BELOW_WHOLESALE', 'count' => 1, 'manager_ids' => [$child]]], $after['unsaleable_descendants']);
        $diag = PricingService::validateTree($this->ctx('ad'), $plan);
        $this->assertSame(['RETAIL_BELOW_WHOLESALE'], array_values(array_filter($diag['managers'], static fn($m) => $m['manager_id'] === $child))[0]['reasons']);
        // the historical snapshot is unchanged, but it can no longer be reserved (stale)
        $this->assertSame($beforeCols, static::$db->rows('SELECT wholesale_cost, retail_price, provider_settlement_amount, plan_snapshot, pricing_chain_snapshot FROM tbl_reseller_activations WHERE id = ?', [$before])[0]);
        $this->refused('RETAIL_BELOW_WHOLESALE', fn() => $this->reserveSealed($before));
        // corrected by the reseller -> saleable again
        PricingService::setRetail($this->ctx($child), $child, $plan, '17');
        $this->assertSame([], $this->q($child, $plan)['reasons']);
    }

    public function testBrowserMoneyIsNeverAuthoritative(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$top] = $this->chain($plan, ['12']);
        $act = $this->sealedActivation($top, $plan);
        // a forged amount is refused before any write
        $this->refused('ACTIVATION_MISMATCH', fn() => ReservationService::reserve($act, $top, Money::parsePositive('1')));
        // a forged retail / settlement column on the activation is refused (the snapshot is cross-checked)
        static::$db->exec("UPDATE tbl_reseller_activations SET retail_price = '1.00' WHERE id = {$act}");
        $this->refused('PRICING_SNAPSHOT_INVALID', fn() => $this->reserveSealed($act));
        static::$db->exec("UPDATE tbl_reseller_activations SET retail_price = '100.00', provider_settlement_amount = '0.01' WHERE id = {$act}");
        $this->refused('PRICING_SNAPSHOT_INVALID', fn() => $this->reserveSealed($act));
        $this->refused('INVALID_AMOUNT', fn() => PricingService::setRetail($this->ctx($top), $top, $plan, 12.5));
        $this->refused('INVALID_AMOUNT', fn() => PricingService::setRetail($this->ctx($top), $top, $plan, '12.505'));
    }
}
