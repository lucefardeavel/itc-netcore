<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\ResellerTreeService;
use Reseller\Finance\PricingService;
use Reseller\Tests\Support\FinanceSeed;
use Reseller\Tests\Support\PricingTestCase;

/** WP3B §8: wholesale inheritance and parent -> direct-child overrides (D011/D023, OD-5). */
final class WholesaleTest extends PricingTestCase
{
    private function q(int $manager, int $plan): array
    {
        return PricingService::quote($this->ctx('ad'), $manager, $plan);
    }

    public function testAdminSetsTopLevelParentSetsDirectChildFloorIsTheParent(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        [$top, $child] = $this->chain($plan, [null, null]);
        $this->refused('WHOLESALE_BELOW_PARENT', fn() => PricingService::setChildWholesale($this->ctx('ad'), $top, $plan, '9.99'), 'top level floor is the base cost');
        $this->assertTrue(PricingService::setChildWholesale($this->ctx('ad'), $top, $plan, '12')->changed);
        $this->refused('WHOLESALE_BELOW_PARENT', fn() => PricingService::setChildWholesale($this->ctx($top), $child, $plan, '11.99'));
        $r = PricingService::setChildWholesale($this->ctx($top), $child, $plan, '12', 'match parent');
        $this->assertTrue($r->changed, 'equal to the parent effective cost is allowed');
        $row = $this->priceRow($child, $plan);
        $this->assertSame(['12.00', $top], [$row['wholesale_cost'], (int) $row['wholesale_set_by']]);
        $this->assertNull($row['retail_price']);
        $this->assertNull($row['is_enabled']);
        $a = $this->audit('pricing.wholesale_set');
        $last = end($a);
        $this->assertSame([$child, 'pricing', $top, 'match parent'], [(int) $last['target_user_id'], $last['target_type'], (int) $last['actor_user_id'], $last['reason']]);
        $this->assertSame(['wholesale' => null, 'set_by' => null, 'set_at' => null], json_decode($last['before_json'], true));
        $this->assertSame(['12.00', $top], [json_decode($last['after_json'], true)['wholesale'], json_decode($last['after_json'], true)['set_by']]);
        $this->assertSame($plan, json_decode($last['target_snapshot'], true)['plan']['plan_id']);
    }

    public function testScopeSelfSiblingGrandchildUnrelatedAndForgedIds(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        [$top, $child, $grand] = $this->chain($plan, ['12', null, null]);
        $sibling = $this->funded('10', $top);
        $other = $this->funded('10');
        $t = $this->ctx($top);
        $this->refused('SELF_PRICING_FORBIDDEN', fn() => PricingService::setChildWholesale($t, $top, $plan, '15'), 'a reseller never sets its own wholesale');
        $this->refused('NOT_FOUND', fn() => PricingService::setChildWholesale($t, $grand, $plan, '15'), 'grandchild: only its own parent may');
        $this->refused('NOT_FOUND', fn() => PricingService::setChildWholesale($this->ctx($child), $sibling, $plan, '15'), 'sibling');
        $this->refused('NOT_FOUND', fn() => PricingService::setChildWholesale($t, $other, $plan, '15'), 'unrelated tree');
        $this->refused('NOT_FOUND', fn() => PricingService::setChildWholesale($t, 99999999, $plan, '15'), 'forged manager id');
        $this->refused('NOT_FOUND', fn() => PricingService::setChildWholesale($t, $child, 99999999, '15'), 'forged plan id');
        $this->refused('NOT_FOUND', fn() => PricingService::setChildWholesale($t, '1 OR 1=1', $plan, '15'), 'malformed id');
        $this->refused('NOT_FOUND', fn() => PricingService::setChildWholesale($this->ctx('ad'), 99999999, $plan, '15'));
        $this->refused('PRICING_TARGET_INVALID', fn() => PricingService::setChildWholesale($this->ctx('ad'), $this->sid('A1'), $plan, '15'), 'Sales never has a wholesale price');
        $this->refused('PRICING_TARGET_INVALID', fn() => PricingService::setChildWholesale($this->ctx('ad'), $this->sid('E'), $plan, '15'), 'non-enrolled Agent');
        $this->refused('PRICING_TARGET_INVALID', fn() => PricingService::setChildWholesale($this->ctx('ad'), $this->sid('ad'), $plan, '15'), 'Admin is not a reseller');
        foreach (['A1', 'rp'] as $u) {
            $this->refused('FORBIDDEN', fn() => PricingService::setChildWholesale($this->ctx($u), $child, $plan, '15'));
        }
        $this->assertTrue(PricingService::setChildWholesale($this->ctx($child), $grand, $plan, '13')->changed, 'the direct parent may');
    }

    public function testInheritanceNearestAncestorBaseFallbackAndClear(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        $ids = $this->chain($plan, [null, '12', null, null, '14']);
        $q = array_map(fn(int $id) => $this->q($id, $plan), $ids);
        $this->assertSame(['10.00', '12.00', '12.00', '12.00', '14.00'], array_column($q, 'effective_wholesale'));
        $this->assertSame(['BASE', 'OVERRIDE', 'INHERITED', 'INHERITED', 'OVERRIDE'], array_column($q, 'wholesale_source'));
        $leafChain = end($q)['chain'];
        $this->assertSame([null, $ids[1], $ids[1], $ids[1], $ids[4]], array_column($leafChain, 'source_manager_id'), 'nearest overriding ancestor wins');
        $this->assertSame(['2.00', '0.00', '0.00', '2.00', '0.00'], array_column($leafChain, 'margin'));
        // clearing an override returns to inheritance; the emptied row is deleted (all-NULL row == no row)
        $r = PricingService::setChildWholesale($this->ctx($ids[3]), $ids[4], $plan, null);
        $this->assertTrue($r->changed);
        $this->assertNull($this->priceRow($ids[4], $plan));
        $this->assertSame(['12.00', 'INHERITED'], [$this->q($ids[4], $plan)['effective_wholesale'], $this->q($ids[4], $plan)['wholesale_source']]);
        $this->assertCount(1, array_filter($this->audit('pricing.wholesale_clear'), static fn($x) => (int) $x['target_user_id'] === $ids[4]));
        $this->assertFalse(PricingService::setChildWholesale($this->ctx($ids[3]), $ids[4], $plan, null)->changed, 'clearing nothing is a no-op');
        // a row keeping another override survives a clear
        PricingService::setChildWholesale($this->ctx($ids[3]), $ids[4], $plan, '13');
        PricingService::setRetail($this->ctx($ids[4]), $ids[4], $plan, '20');
        PricingService::setChildWholesale($this->ctx($ids[3]), $ids[4], $plan, null);
        $row = $this->priceRow($ids[4], $plan);
        $this->assertSame([null, null, null, '20.00'], [$row['wholesale_cost'], $row['wholesale_set_by'], $row['wholesale_set_at'], $row['retail_price']]);
    }

    /** OD-5: an upstream change may never leave an explicit descendant override below its new parent price. */
    public function testIntroducedDescendantConflictIsRejectedNeverRewritten(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        [$top, $child, $grand] = $this->chain($plan, ['12', '15', '16']);
        $this->refused('WHOLESALE_DESCENDANT_CONFLICT', fn() => PricingService::setChildWholesale($this->ctx('ad'), $top, $plan, '15.01'));
        $this->assertTrue(PricingService::setChildWholesale($this->ctx('ad'), $top, $plan, '15')->changed, 'up to the child price is fine');
        $this->refused('WHOLESALE_DESCENDANT_CONFLICT', fn() => PricingService::setChildWholesale($this->ctx($top), $child, $plan, '17'));
        $this->assertSame('16.00', $this->priceRow($grand, $plan)['wholesale_cost'], 'never silently rewritten');
        // bottom-up: the grandchild's parent raises first, then the upstream raise succeeds
        PricingService::setChildWholesale($this->ctx($child), $grand, $plan, '17');
        $this->assertTrue(PricingService::setChildWholesale($this->ctx($top), $child, $plan, '17')->changed);
        // a decrease never conflicts
        $this->assertTrue(PricingService::setChildWholesale($this->ctx('ad'), $top, $plan, '11')->changed);
    }

    /** A WP2 hierarchy move can create a violation; it fails closed and never blocks corrections. */
    public function testPreExistingViolationFromAMove(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        [$t1] = $this->chain($plan, ['12']);
        [$t2] = $this->chain($plan, ['20']);
        $x = $this->funded('10', $t1);
        PricingService::setChildWholesale($this->ctx($t1), $x, $plan, '13');
        ResellerTreeService::moveSubtree($this->ctx('ad'), $x, $t2);
        $this->assertContains('PRICING_CHAIN_INVALID', $this->q($x, $plan)['reasons'], 'fails closed');
        $diag = PricingService::validateTree($this->ctx('ad'), $plan);
        $row = array_values(array_filter($diag['managers'], static fn($m) => $m['manager_id'] === $x))[0];
        $this->assertSame([false, ['PRICING_CHAIN_INVALID']], [$row['saleable'], $row['reasons']]);
        // unrelated and corrective changes are not blocked by the pre-existing violation
        $y = $this->funded('10', $t2);
        $this->assertTrue(PricingService::setChildWholesale($this->ctx($t2), $y, $plan, '21')->changed);
        $this->assertTrue(PricingService::setChildWholesale($this->ctx('ad'), $t2, $plan, '20.50')->changed);
        $this->assertTrue(PricingService::setChildWholesale($this->ctx($t2), $x, $plan, '20.50')->changed, 'the new parent corrects its child');
        $this->assertSame([], $this->q($x, $plan)['reasons']);
    }

    public function testDeepChainAndFrozenPolicy(): void
    {
        $plan = $this->plan();
        $this->base($plan, '1');
        $ids = $this->chain($plan, ['2', '3', '4', '5', '6', '7']);
        $this->assertSame('7.00', $this->q(end($ids), $plan)['effective_wholesale']);
        // an inactive child: its parent (an Agent) may not change it; Admin may (configuration correction)
        $this->setStatus($ids[5], 'Inactive');
        $this->refused('PRICING_TARGET_FROZEN', fn() => PricingService::setChildWholesale($this->ctx($ids[4]), $ids[5], $plan, '8'));
        $this->assertTrue(PricingService::setChildWholesale($this->ctx('ad'), $ids[5], $plan, '8')->changed);
        $this->assertSame(['WALLET_OWNER_FROZEN'], $this->q($ids[5], $plan)['reasons'], 'an inactive reseller is never saleable');
        $this->setStatus($ids[5], 'Active');
        // a frozen actor (inactive ancestor) cannot mutate at all
        $this->setStatus($ids[0], 'Inactive');
        $this->refused('FORBIDDEN', fn() => PricingService::setChildWholesale($this->ctx($ids[2]), $ids[3], $plan, '4.5'));
        $this->setStatus($ids[0], 'Active');
        // a non-enrolled child created with a closure-less parent pointer is not a valid target
        $orphan = FinanceSeed::staff(static::$db, 'orph' . bin2hex(random_bytes(3)), $ids[0], 'Active', false);
        $this->refused('NOT_FOUND', fn() => PricingService::setChildWholesale($this->ctx($ids[0]), $orphan, $plan, '9'));
    }
}
