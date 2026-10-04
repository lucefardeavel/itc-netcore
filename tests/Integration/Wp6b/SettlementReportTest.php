<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP6B slice 6 over real HTTP: commission / provider-settlement presentation from immutable history. A real activation
 * sold by B (chain: provider base 10.37 -> A 12.11 -> B 15.53) settles as debit 15.53 = A's commission 3.42 + provider
 * settlement 12.11. Admin sees everything incl. the conservation check; A (B's parent) sees B's debit and its own
 * commission, never the provider settlement or base cost; B sees only its own debit. Reports and CSV follow the same
 * scopes; the provider settlement report is Admin-only.
 */
final class SettlementReportTest extends RadiusHttpTestCase
{
    private static int $plan;
    private static string $ref = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp6b_settle_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp6b_settle_plan', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        $plan = self::$plan;
        $ids = static::$seed->staff;
        $out = static::$app->exec(<<<PHP
\$ctx = static fn(string \$u) => \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', \$u)->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\PricingService::setBaseCost(\$ctx('ad'), {$plan}, '10.37');
\\Reseller\\Finance\\PricingService::setResaleEnabled(\$ctx('ad'), {$plan}, true);
\\Reseller\\Finance\\PricingService::setChildWholesale(\$ctx('ad'), {$ids['A']}, {$plan}, '12.11');
\\Reseller\\Finance\\PricingService::setChildWholesale(\$ctx('A'), {$ids['B']}, {$plan}, '15.53');
\\Reseller\\Finance\\TransferService::fund(\$ctx('ad'), {$ids['A']}, '100', 'funding A', 'WP6B-S-A-0001');
\\Reseller\\Finance\\TransferService::fund(\$ctx('ad'), {$ids['B']}, '100', 'funding B', 'WP6B-S-B-0001');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    private function sold(): string
    {
        if (self::$ref !== '') {
            return self::$ref;
        }
        $c = $this->staff('B');
        $page = $c->get('activation/create', ['customer' => static::$seed->customers['cB'], 'plan' => self::$plan]);
        $f = [];
        foreach (['customer_id', 'plan_id', 'expected_action', 'nonce', 'preview'] as $n) {
            preg_match('/name="' . $n . '" value="([^"]+)"/', $page->body, $m);
            $f[$n] = html_entity_decode($m[1] ?? '');
        }
        $r = $c->postWithToken('activation/create-post', $f);
        preg_match('#(ACT-[0-9A-F]{16})#', (string) $r->location(), $m);
        $this->assertNotEmpty($m[1] ?? null, $r->excerpt());
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$m[1]]));
        return self::$ref = $m[1];
    }

    private static function card(string $html): string
    {
        $start = strpos($html, 'id="nuxr-settle-t"');
        return $start === false ? '' : substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    public function testTheSettlementCardFollowsTheRoleProjection(): void
    {
        $ref = $this->sold();
        $admin = self::card($this->staff('ad')->get('activation/view/' . $ref)->body);
        foreach (['/15[.,]53/', '/3[.,]42/', '/12[.,]11/', '/10[.,]37/', '/Conserved/', '/Provider settlement/'] as $rx) {
            $this->assertMatchesRegularExpression($rx, $admin, "Admin card {$rx}");
        }
        $parent = self::card($this->staff('A')->get('activation/view/' . $ref)->body);
        $this->assertMatchesRegularExpression('/15[.,]53/', $parent, "B's debit (inside A's subtree)");
        $this->assertMatchesRegularExpression('/3[.,]42/', $parent, "A's own commission");
        foreach (['/12[.,]11/', '/10[.,]37/', '/Provider settlement/', '/Conserved/'] as $rx) {
            $this->assertDoesNotMatchRegularExpression($rx, $parent, "A never sees {$rx}");
        }
        $seller = self::card($this->staff('B')->get('activation/view/' . $ref)->body);
        $this->assertMatchesRegularExpression('/15[.,]53/', $seller);
        foreach (['/3[.,]42/', '/12[.,]11/', '/10[.,]37/', '/Staff A\b/'] as $rx) {
            $this->assertDoesNotMatchRegularExpression($rx, $seller, "B never sees {$rx}");
        }
    }

    public function testReportsAndExportsFollowTheSameScopes(): void
    {
        $ref = $this->sold();
        $range = ['from' => gmdate('Y-m-d', time() - 86400), 'to' => gmdate('Y-m-d', time() + 86400)];
        $settle = $this->staff('ad')->get('finance/settlement', $range)->body;
        $this->assertStringContainsString($ref, $settle);
        $this->assertStringContainsString('Conserved', $settle);
        $a = $this->staff('A');
        $this->assertSame(302, $a->get('finance/settlement', $range)->status, 'provider settlement is Admin-only');
        $commissions = $a->get('finance/commissions', $range)->body;
        $this->assertStringContainsString($ref, $commissions);
        $this->assertMatchesRegularExpression('/3[.,]42/', $commissions);
        $this->assertStringNotContainsString($ref, $this->staff('B')->get('finance/commissions', $range)->body, 'B received no commission');
        $sales = $a->get('finance/sales', $range)->body;
        $this->assertStringContainsString($ref, $sales, "a descendant's sale is in A's ledger.read scope");
        $csv = $a->get('finance/commissions', $range + ['format' => 'csv']);
        $this->assertSame(200, $csv->status);
        $this->assertStringContainsString($ref, $csv->body);
        $this->assertStringContainsString('3.42', $csv->body);
        $this->assertDoesNotMatchRegularExpression('/12[.,]11|10[.,]37/', $csv->body);
        $perf = $a->get('finance/performance', $range)->body;
        $this->assertStringContainsString('Staff B', $perf);
        $this->assertStringNotContainsString('Staff C', $perf);
        foreach (['A1', 'rp'] as $u) {
            $this->assertDenied($this->staff($u)->get('finance/sales'), "{$u} sales");
        }
    }
}
