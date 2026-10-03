<?php
namespace Reseller\Tests\Integration\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationService;
use Reseller\Domain\ResellerTreeService;
use Reseller\Finance\PricingService;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceSeed as Seed;

/**
 * Activation actor eligibility and the activation plan catalog (owner fix round 1):
 *  - an Agent / Sales must be a validly placed reseller identity; an unplaced one is refused before any data;
 *  - Agent: only the plans saleable for itself; Sales: only the plans saleable for the authoritative owner of a
 *    customer in its scope; Admin / SuperAdmin: the administrative list (unchanged);
 *  - every PricingService fail-closed case and every unsupported plan is absent; no economics are returned.
 */
final class ActivationCatalogTest extends ActivationTestCase
{
    /** @return list<int> */
    private function ids(array $catalog): array
    {
        return array_column($catalog, 'id');
    }

    /** Base 10, top-level wholesale 12 (Admin), leaf wholesale 15 (top): the leaf pays 15. */
    private function price(int $plan, int $top, int $leaf, string $base = '10'): void
    {
        $this->base($plan, $base);
        PricingService::setChildWholesale($this->ctx('ad'), $top, $plan, '12');
        PricingService::setChildWholesale($this->ctx($top), $leaf, $plan, '15');
    }

    public function testUnplacedAgentIsRefusedBeforeAnyData(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain();
        $c = $this->customer($leaf);
        $e = $this->agent(null, 'Active', false); // Active, role Agent, never enrolled
        $ctx = $this->ctx($e);
        $this->assertFalse(PermissionService::can($ctx, C::ACTIVATION_CREATE), 'the central guard refuses (placement required)');
        $this->assertSame('S', \Reseller\Security\RoleCapabilityMap::scope('Agent', C::ACTIVATION_CREATE), 'the approved A5 scope is unchanged');
        $this->assertFalse(ActivationService::eligible($ctx));
        $this->refused('FORBIDDEN', fn() => ActivationService::catalog($ctx));
        $this->refused('FORBIDDEN', fn() => ActivationService::preview($ctx, $c, $plan)); // an existing customer ...
        $this->refused('FORBIDDEN', fn() => ActivationService::preview($ctx, 99999999, $plan)); // ... and a missing one: no existence leak
        $this->refused('FORBIDDEN', fn() => ActivationService::create($ctx, $c, $plan, 'NEW', self::nonce()));
        // an enrolled Agent whose ancestor is not Active (frozen) and an unplaced Sales are refused too
        [, [$top2, $leaf2]] = $this->pricedChain();
        $this->setStatus($top2, 'Inactive');
        $this->refused('FORBIDDEN', fn() => ActivationService::catalog($this->ctx($leaf2)));
        $sales = Seed::staff(static::$db, 'su' . bin2hex(random_bytes(3)), null, 'Active', false, 'Sales');
        $this->refused('FORBIDDEN', fn() => ActivationService::catalog($this->ctx($sales)));
    }

    public function testAgentSeesOnlyPlansSaleableForItself(): void
    {
        [$ok, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $absent = [];
        $absent['resale disabled'] = $p = $this->radiusPlan('20');
        $this->base($p, '10', false);
        $absent['no base cost'] = $this->radiusPlan('20');
        $absent['disabled by an ancestor'] = $p = $this->radiusPlan('20');
        $this->price($p, $top, $leaf);
        PricingService::setChildAvailability($this->ctx($top), $leaf, $p, false);
        $absent['retail below wholesale'] = $p = $this->radiusPlan('14');
        $this->price($p, $top, $leaf);
        $absent['malformed legacy retail'] = $p = $this->radiusPlan('+20');
        $this->price($p, $top, $leaf);
        $absent['zero wholesale'] = $p = $this->radiusPlan('20');
        $this->base($p, '0');
        $absent['plan disabled'] = $p = $this->radiusPlan('20', 'Hotspot', 30, 'Days', ['enabled' => 0]);
        $this->price($p, $top, $leaf);
        $absent['MikroTik device'] = $p = $this->radiusPlan('20', 'Hotspot', 30, 'Days', ['device' => 'MikrotikHotspot']);
        $this->price($p, $top, $leaf);
        $absent['VPN type'] = $p = $this->radiusPlan('20', 'VPN');
        $this->price($p, $top, $leaf);
        $absent['Balance plan'] = $this->radiusPlan('20', 'Balance');
        $catalog = ActivationService::catalog($this->ctx($leaf));
        $this->assertContains($ok, $this->ids($catalog));
        foreach ($absent as $why => $plan) {
            $this->assertNotContains($plan, $this->ids($catalog), $why);
        }
        foreach ($catalog as $row) {
            $this->assertSame(['id', 'name_plan', 'type'], array_keys($row), 'no price, cost, chain, commission or settlement data');
        }
        // the catalog follows the actor's own resolution: the ancestor-disabled plan is saleable for the top-level Agent
        $this->assertContains($absent['disabled by an ancestor'], $this->ids(ActivationService::catalog($this->ctx($top))));
        // a plan outside the catalog is not previewed either, and the reason is not disclosed
        $c = $this->customer($leaf);
        $this->refused('NOT_FOUND', fn() => ActivationService::preview($this->ctx($leaf), $c, $absent['retail below wholesale']));
        $this->assertSame('NEW', ActivationService::preview($this->ctx($leaf), $c, $ok)['action']);
    }

    public function testInvalidWholesaleChainIsAbsent(): void
    {
        $plan = $this->radiusPlan('30');
        $this->base($plan, '10');
        [$t1] = $this->chain($plan, ['12']);
        [$t2] = $this->chain($plan, ['20']);
        $x = $this->funded('100', $t1);
        PricingService::setChildWholesale($this->ctx($t1), $x, $plan, '13');
        ResellerTreeService::moveSubtree($this->ctx('ad'), $x, $t2); // 13 < 20: PRICING_CHAIN_INVALID
        $this->assertNotContains($plan, $this->ids(ActivationService::catalog($this->ctx($x))));
        $this->assertContains($plan, $this->ids(ActivationService::catalog($this->ctx($t2))));
    }

    public function testSalesCatalogFollowsTheAuthoritativeCustomerOwner(): void
    {
        [$ok, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $sales = Seed::staff(static::$db, 'sl' . bin2hex(random_bytes(3)), $leaf, 'Active', true, 'Sales');
        $mine = $this->customer($leaf, $sales);
        // a plan saleable only for another reseller (Admin disabled it for this chain's top level)
        $other = $this->funded('100');
        $theirs = $this->radiusPlan('20');
        $this->base($theirs, '10');
        PricingService::setChildAvailability($this->ctx('ad'), $top, $theirs, false);
        $foreign = $this->customer($other);
        $forged = $this->customer($other, $sales); // assigned to this Sales but owned by another reseller
        $ctx = $this->ctx($sales);
        $this->assertSame([], ActivationService::catalog($ctx), 'no customer selected: no plans');
        $catalog = $this->ids(ActivationService::catalog($ctx, $mine));
        $this->assertContains($ok, $catalog);
        $this->assertNotContains($theirs, $catalog, 'the owner of the customer decides, not the plan list');
        $this->assertContains($theirs, $this->ids(ActivationService::catalog($this->ctx($other))), 'it is saleable for its own reseller');
        foreach (['another reseller customer' => $foreign, 'assigned but owned elsewhere' => $forged, 'missing' => 99999999, 'injection' => '1 OR 1=1'] as $why => $customer) {
            $this->refused('NOT_FOUND', fn() => ActivationService::catalog($ctx, $customer)); // {$why}
        }
        $this->refused('NOT_FOUND', fn() => ActivationService::preview($ctx, $mine, $theirs));
        $this->assertSame('COMPLETED', $this->activate($sales, $mine, $ok, 'NEW')['status'], 'the approved Sales flow on the owner wallet');
        $this->assertSame('985.00', $this->balance($leaf));
    }

    public function testAdminKeepsTheAdministrativeList(): void
    {
        [$ok, [, $leaf]] = $this->pricedChain();
        $unpriced = $this->radiusPlan('20');
        $catalog = $this->ids(ActivationService::catalog($this->ctx('ad')));
        $this->assertContains($ok, $catalog);
        $this->assertContains($unpriced, $catalog, 'Admin / SuperAdmin: every enabled RADIUS Hotspot / PPPoE plan (unchanged)');
        $this->assertSame($catalog, $this->ids(ActivationService::catalog($this->ctx('sa'))));
        $c = $this->customer($leaf);
        $this->refused('PLAN_NOT_RESELLABLE', fn() => ActivationService::preview($this->ctx('ad'), $c, $unpriced)); // Admin sees the reason
        $this->assertSame('COMPLETED', $this->activate('ad', $c, $ok, 'NEW')['status']);
    }
}
