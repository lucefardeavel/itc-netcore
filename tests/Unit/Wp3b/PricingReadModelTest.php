<?php
namespace Reseller\Tests\Unit\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Finance\Money;
use Reseller\Finance\PricingLink;
use Reseller\Finance\PricingReadModel;
use Reseller\Security\ActorContext;

/**
 * WP3B F-8: role visibility is separate from the complete internal resolution. An Agent never sees the
 * provider base cost, the resale flag, ancestor identities / costs / margins or the provider settlement.
 */
final class PricingReadModelTest extends TestCase
{
    /** chain 101 (12, override) -> 102 (15, override) -> 103 (inherits 15) -> 104 (17, override, leaf) */
    private static function evaluation(): array
    {
        $m = static fn(string $v) => Money::parseNonNegative($v);
        $l = static fn(int $id, int $d, string $c, string $src, ?int $srcId, string $margin) => [
            'manager_id' => $id, 'distance' => $d, 'effective_wholesale' => $m($c), 'source' => $src, 'source_manager_id' => $srcId,
            'set_by' => 1, 'set_at' => 'x', 'availability' => null, 'margin' => $m($margin),
        ];
        return [
            'plan_id' => 7, 'manager_id' => 104, 'reasons' => [], 'base' => $m('10'), 'resale_enabled' => true, 'available' => true,
            'retail' => $m('20'), 'retail_source' => 'OVERRIDE',
            'chain' => [
                $l(101, 3, '12', PricingLink::OVERRIDE, 101, '3'),
                $l(102, 2, '15', PricingLink::OVERRIDE, 102, '0'),
                $l(103, 1, '15', PricingLink::INHERITED, 102, '2'),
                $l(104, 0, '17', PricingLink::OVERRIDE, 104, '0'),
            ],
        ];
    }

    private static function agent(int $id): ActorContext
    {
        return ActorContext::make(['type' => ActorContext::STAFF, 'channel' => 'WEB', 'userId' => $id, 'role' => 'Agent', 'status' => 'Active', 'enrolled' => true, 'schemaReady' => true]);
    }

    public function testAdminSeesEverything(): void
    {
        $v = PricingReadModel::project(ActorContext::make(['type' => ActorContext::STAFF, 'userId' => 1, 'role' => 'Admin']), 'G', self::evaluation());
        $this->assertSame('10.00', $v['base_cost']);
        $this->assertSame('12.00', $v['provider_settlement']);
        $this->assertSame([101, 102, 103, 104], array_column($v['chain'], 'manager_id'));
        $this->assertSame([['manager_id' => 103, 'amount' => '2.00'], ['manager_id' => 101, 'amount' => '3.00']], $v['commissions']);
    }

    public function testAgentSeesOnlyItsPartOfTheChain(): void
    {
        $v = PricingReadModel::project(self::agent(103), 'T', self::evaluation());
        $json = json_encode($v);
        foreach (['base_cost', 'provider_settlement', 'resale_enabled', 'commissions', 'source_manager_id', 'set_by'] as $k) {
            $this->assertArrayNotHasKey($k, $v, $k);
            $this->assertStringNotContainsString('"' . $k . '"', $json, $k);
        }
        $this->assertSame([103, 104], array_column($v['chain'], 'manager_id'), 'no ancestor identity');
        $this->assertStringNotContainsString('"12.00"', $json, 'no ancestor cost');
        $this->assertStringNotContainsString('"10.00"', $json, 'no provider base cost');
        $this->assertSame(['17.00', 'OVERRIDE', '20.00'], [$v['effective_wholesale'], $v['wholesale_source'], $v['effective_retail']]);
        // the leaf itself: its cost is visible, an inherited source from above is only "UPSTREAM"
        $own = PricingReadModel::project(self::agent(104), 'T', ['manager_id' => 104] + self::evaluation());
        $this->assertSame([104], array_column($own['chain'], 'manager_id'));
        $ev = self::evaluation();
        $ev['chain'][3]['source'] = PricingLink::INHERITED;
        $ev['chain'][3]['source_manager_id'] = 102;
        $this->assertSame('UPSTREAM', PricingReadModel::project(self::agent(104), 'T', $ev)['wholesale_source']);
        $this->assertSame([], PricingReadModel::project(self::agent(104), 'NONE', self::evaluation()), 'no scope, no data');
    }
}
