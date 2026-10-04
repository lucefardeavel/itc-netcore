<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6B slice 5 over real HTTP (owner decisions OD-4 / OD-9): pricing pages are PricingReadModel projections. An Agent
 * never sees the provider base cost, provider settlement or anything above itself (sentinel values, HTML and CSV); the
 * parent sees its own margin on a direct child; edits are offered only where the accepted capability + relation allow
 * and the accepted setters stay authoritative (self wholesale, below-parent floor). Sales / Report: nothing.
 *
 * Chain (sentinels): provider base 10.37 -> A 12.11 -> B 15.53; default retail (plan price) 20.
 */
final class PricingUxTest extends Wp2HttpTestCase
{
    private static int $plan;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $ids = static::$seed->staff;
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp6b_price_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp6b_price_plan', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        $plan = self::$plan;
        $out = static::$app->exec(<<<PHP
\$ctx = static fn(string \$u) => \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', \$u)->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\PricingService::setBaseCost(\$ctx('ad'), {$plan}, '10.37');
\\Reseller\\Finance\\PricingService::setResaleEnabled(\$ctx('ad'), {$plan}, true);
\\Reseller\\Finance\\PricingService::setChildWholesale(\$ctx('ad'), {$ids['A']}, {$plan}, '12.11');
\\Reseller\\Finance\\PricingService::setChildWholesale(\$ctx('A'), {$ids['B']}, {$plan}, '15.53');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    private function main(string $html): string
    {
        $start = strpos($html, 'id="nuxr-main"');
        return $start !== false ? substr($html, $start, max(0, (int) strpos($html, '<footer', $start) - $start)) : $html;
    }

    public function testAgentsSeeOnlyTheirProjectionAndAdminSeesEverything(): void
    {
        $id = static::$seed->staff;
        $admin = $this->main($this->staff('ad')->get('pricing/catalog/' . $id['B'])->body);
        $this->assertMatchesRegularExpression('/10[.,]37/', $admin, 'Admin sees the provider base cost');
        $this->assertMatchesRegularExpression('/15[.,]53/', $admin);
        $this->assertStringContainsString('set for this reseller', $admin);
        // the parent Agent on its direct child: the child's wholesale, its own margin, child edits only
        $parent = $this->main($this->staff('A')->get('pricing/catalog/' . $id['B'])->body);
        $this->assertMatchesRegularExpression('/15[.,]53/', $parent);
        $this->assertMatchesRegularExpression('/3[.,]42/', $parent, "A's margin on B (15.53 - 12.11)");
        $this->assertDoesNotMatchRegularExpression('/10[.,]37/', $parent, 'no provider base cost for an Agent');
        $this->assertStringNotContainsString('Provider base cost', $parent);
        $this->assertStringContainsString('pricing/wholesale-post', $parent);
        $this->assertStringContainsString('pricing/availability-post', $parent);
        $this->assertStringNotContainsString('pricing/retail-post', $parent, "A cannot set B's retail");
        // the child Agent on itself: its own wholesale as a value from upstream, own retail edit only
        $child = $this->main($this->staff('B')->get('pricing')->body);
        $this->assertMatchesRegularExpression('/15[.,]53/', $child);
        foreach (['/10[.,]37/', '/12[.,]11/', '/Staff A\b/', '/Provider base cost/'] as $secret) {
            $this->assertDoesNotMatchRegularExpression($secret, $child, "B never sees {$secret}");
        }
        $this->assertStringContainsString('pricing/retail-post', $child);
        $this->assertStringNotContainsString('pricing/wholesale-post', $child, 'never its own wholesale');
        // CSV: same projection
        $csv = $this->staff('B')->get('pricing', ['format' => 'csv'])->body;
        $this->assertMatchesRegularExpression('/15[.,]53/', $csv);
        $this->assertDoesNotMatchRegularExpression('/10[.,]37/', $csv);
        $this->assertDoesNotMatchRegularExpression('/12[.,]11/', $csv);
        // out of scope / Sales / Report
        $this->assertSame(302, $this->staff('B')->get('pricing/catalog/' . $id['A'])->status, "B cannot open its parent's catalog");
        $this->assertSame(302, $this->staff('A')->get('pricing/catalog/' . $id['C'])->status);
        foreach (['A1', 'rp'] as $u) {
            $this->assertDenied($this->staff($u)->get('pricing'), "{$u} pricing");
            $this->assertDenied($this->staff($u)->postWithToken('pricing/retail-post', ['manager_id' => (string) $id['A'], 'plan_id' => (string) self::$plan, 'price' => '1']), "{$u} retail-post");
        }
    }

    public function testTheAcceptedSettersStayAuthoritative(): void
    {
        $id = static::$seed->staff;
        $plan = (string) self::$plan;
        $b = $this->staff('B');
        $b->get('dashboard');
        $a = $this->staff('A');
        $a->get('dashboard');
        $before = $this->snapshot();
        // B can never set its own wholesale (route allowed by its child scope; the service refuses)
        $r = $b->postWithToken('pricing/wholesale-post', ['manager_id' => (string) $id['B'], 'plan_id' => $plan, 'cost' => '1', 'back' => 'catalog']);
        $this->assertSame(302, $r->status);
        // A cannot set B below A's own wholesale
        $a->postWithToken('pricing/wholesale-post', ['manager_id' => (string) $id['B'], 'plan_id' => $plan, 'cost' => '11', 'back' => 'catalog', 'manager_id' => (string) $id['B']]);
        $this->assertUnchanged($before, 'refused pricing changes write nothing');
        $this->assertStringContainsString('cannot be below its parent', $a->get('pricing/catalog/' . $id['B'])->body);
        // B sets its own retail (Arabic-Indic digits); the same value again is "No change"
        $r = $b->postWithToken('pricing/retail-post', ['manager_id' => (string) $id['B'], 'plan_id' => $plan, 'price' => '٢٢', 'back' => 'catalog']);
        $this->assertSame(302, $r->status);
        $this->assertSame('22.00', static::$db->value('SELECT retail_price FROM tbl_manager_plan_prices WHERE manager_id = ? AND plan_id = ?', [$id['B'], self::$plan]));
        $this->assertStringContainsString('Saved', $b->get('pricing')->body);
        $b->postWithToken('pricing/retail-post', ['manager_id' => (string) $id['B'], 'plan_id' => $plan, 'price' => '22', 'back' => 'catalog']);
        $this->assertStringContainsString('No change', $b->get('pricing')->body);
    }

    public function testAdminBaseCostResaleAndRetailWarnings(): void
    {
        $id = static::$seed->staff;
        $ad = $this->staff('ad');
        $list = $ad->get('pricing')->body;
        $this->assertStringContainsString('wp6b_price_plan', $list);
        $this->assertStringContainsString('Default retail (plan price)', $list);
        // raising A's wholesale above B's default-retail sellers makes B unsaleable: accepted (F-5), with a warning
        $ad->postWithToken('pricing/wholesale-post', ['manager_id' => (string) $id['B'], 'plan_id' => (string) self::$plan, 'cost' => '', 'back' => 'plan']);
        $ad->postWithToken('pricing/retail-post', ['manager_id' => (string) $id['B'], 'plan_id' => (string) self::$plan, 'price' => '', 'back' => 'plan']);
        $r = $ad->postWithToken('pricing/wholesale-post', ['manager_id' => (string) $id['A'], 'plan_id' => (string) self::$plan, 'cost' => '25', 'back' => 'plan']);
        $this->assertSame(302, $r->status);
        $grid = $ad->get('pricing/plan/' . self::$plan)->body;
        $this->assertStringContainsString('can no longer sell this plan', $grid);
        $this->assertStringContainsString('Not saleable', $grid);
        // resale off / on
        $ad->postWithToken('pricing/resale-post', ['plan_id' => (string) self::$plan, 'enabled' => '0']);
        $this->assertSame('0', (string) static::$db->value('SELECT is_resale_enabled FROM tbl_reseller_plan_base_costs WHERE plan_id = ?', [self::$plan]));
        $ad->postWithToken('pricing/resale-post', ['plan_id' => (string) self::$plan, 'enabled' => '1']);
        $this->assertSame('1', (string) static::$db->value('SELECT is_resale_enabled FROM tbl_reseller_plan_base_costs WHERE plan_id = ?', [self::$plan]));
        // an Agent never reaches the provider base cost setters
        $this->assertDenied($this->staff('A')->postWithToken('pricing/base-cost-post', ['plan_id' => (string) self::$plan, 'cost' => '1']));
    }
}
