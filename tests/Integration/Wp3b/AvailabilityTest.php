<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\FinanceTx;
use Reseller\Finance\PricingService;
use Reseller\Tests\Support\PricingTestCase;

/** WP3B §11 + OD-3: availability inheritance; an explicit 1 never overrides a global / ancestor 0. */
final class AvailabilityTest extends PricingTestCase
{
    private function reasons(int $manager, int $plan): array
    {
        return PricingService::quote($this->ctx('ad'), $manager, $plan)['reasons'];
    }

    public function testAncestorDisableBlocksTheSubtreeAndExplicitEnableCannotOverride(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        [$top, $child, $grand, $great] = $this->chain($plan, [null, null, null, null]);
        $this->assertTrue(PricingService::setChildAvailability($this->ctx($top), $child, $plan, false, 'not sold in this area')->changed);
        $this->assertSame([], $this->reasons($top, $plan));
        $this->assertSame(['PLAN_DISABLED_BY_SELF'], $this->reasons($child, $plan));
        $this->assertSame(['PLAN_DISABLED_BY_ANCESTOR'], $this->reasons($grand, $plan));
        $this->assertSame(['PLAN_DISABLED_BY_ANCESTOR'], $this->reasons($great, $plan));
        $this->refused('PLAN_DISABLED_BY_ANCESTOR', fn() => FinanceTx::run(static fn(\PDO $pdo) => PricingService::resolveInTx($pdo, $great, $plan), true));
        // the disabled reseller may still configure its child, but an explicit 1 cannot re-enable
        $this->assertTrue(PricingService::setChildAvailability($this->ctx($child), $grand, $plan, true)->changed);
        $this->assertSame('1', (string) $this->priceRow($grand, $plan)['is_enabled']);
        $this->assertSame(['PLAN_DISABLED_BY_ANCESTOR'], $this->reasons($grand, $plan));
        $this->assertFalse(PricingService::quote($this->ctx('ad'), $grand, $plan)['available']);
        // global disable wins over everything, explicit 1 included
        PricingService::setChildAvailability($this->ctx($top), $child, $plan, null);
        $this->assertNull($this->priceRow($child, $plan), 'cleared availability: the empty row is removed');
        $this->assertSame([], $this->reasons($grand, $plan), 'NULL inherits again');
        PricingService::setResaleEnabled($this->ctx('ad'), $plan, false);
        $this->assertSame(['PLAN_RESALE_DISABLED'], $this->reasons($grand, $plan));
        PricingService::setResaleEnabled($this->ctx('ad'), $plan, true);
        $this->assertCount(1, array_filter($this->audit('pricing.availability_clear'), static fn($x) => (int) $x['target_user_id'] === $child));
        $set = array_values(array_filter($this->audit('pricing.availability_set'), static fn($x) => (int) $x['target_user_id'] === $child))[0];
        $this->assertSame(['availability' => null, 'set_by' => null, 'set_at' => null], json_decode($set['before_json'], true));
        $this->assertSame([false, $top], [json_decode($set['after_json'], true)['availability'], json_decode($set['after_json'], true)['set_by']]);
    }

    public function testAuthority(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        [$top, $child, $grand] = $this->chain($plan, [null, null, null]);
        $this->refused('SELF_PRICING_FORBIDDEN', fn() => PricingService::setChildAvailability($this->ctx($child), $child, $plan, false), 'no self-disable / self-enable');
        $this->refused('NOT_FOUND', fn() => PricingService::setChildAvailability($this->ctx($top), $grand, $plan, false), 'direct children only');
        $this->refused('NOT_FOUND', fn() => PricingService::setChildAvailability($this->ctx($grand), $child, $plan, false), 'never upwards');
        foreach (['A1', 'rp'] as $u) {
            $this->refused('FORBIDDEN', fn() => PricingService::setChildAvailability($this->ctx($u), $child, $plan, false));
        }
        $this->assertTrue(PricingService::setChildAvailability($this->ctx('sa'), $grand, $plan, false)->changed, 'Admin/SuperAdmin: any reseller');
        $this->assertFalse(PricingService::setChildAvailability($this->ctx('ad'), $grand, $plan, false)->changed, 'identical value: no-op');
        $this->setStatus($grand, 'Inactive');
        $this->refused('PRICING_TARGET_FROZEN', fn() => PricingService::setChildAvailability($this->ctx($child), $grand, $plan, true));
        $this->assertTrue(PricingService::setChildAvailability($this->ctx('ad'), $grand, $plan, null)->changed);
        $this->setStatus($grand, 'Active');
    }
}
