<?php
namespace Reseller\Tests\Integration\Wp6c;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6C slice 4 (OD-2) over real HTTP: pricing rows have one row-level Edit that opens one editing surface per row
 * (a <dialog>: full screen on a phone, a side panel on wider screens) holding exactly the accepted forms the row
 * offers - same routes, fields and CSRF - instead of an inline wall of forms. The surface is rendered once (no
 * duplicate ids between the table and the cards), every control is labelled, and nothing new is exposed: the
 * offers and values still come from the PricingReadModel projection.
 *
 * Chain: provider base 10.37 -> A 12.11 -> B 15.53; default retail (plan price) 20.
 */
final class PricingEditorTest extends Wp2HttpTestCase
{
    private static int $plan;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $ids = static::$seed->staff;
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp6c_price_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp6c_price_plan', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
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

    private static function xp(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        return new \DOMXPath($doc);
    }

    /** @return array<string,list<string>> dialog id => form actions (route part) */
    private static function editors(\DOMXPath $xp): array
    {
        $out = [];
        foreach ($xp->query('//dialog[contains(@class, "nuxr-sheet-edit")]') as $d) {
            /** @var \DOMElement $d */
            $actions = [];
            foreach ($xp->query('.//form', $d) as $f) {
                /** @var \DOMElement $f */
                preg_match('#pricing/[a-z-]+#', $f->getAttribute('action'), $m);
                $actions[] = $m[0] ?? $f->getAttribute('action');
                // every visible control labelled
                foreach ($xp->query('.//input[not(@type="hidden")]|.//select', $f) as $c) {
                    /** @var \DOMElement $c */
                    $id = $c->getAttribute('id');
                    self::assertTrue($id !== '' && $xp->query('//label[@for="' . $id . '"]')->length === 1, 'labelled control ' . $c->getAttribute('name'));
                }
                self::assertSame(1, $xp->query('.//input[@name="csrf_token"]', $f)->length, 'CSRF kept');
            }
            $out[$d->getAttribute('id')] = $actions;
        }
        return $out;
    }

    private static function assertUniqueIds(\DOMXPath $xp): void
    {
        $seen = [];
        foreach ($xp->query('//*[@id]') as $e) {
            /** @var \DOMElement $e */
            $seen[$e->getAttribute('id')] = ($seen[$e->getAttribute('id')] ?? 0) + 1;
        }
        self::assertSame([], array_keys(array_filter($seen, static fn(int $n): bool => $n > 1)), 'no duplicate ids');
    }

    public function testOneRowLevelEditOpensTheRowsAcceptedForms(): void
    {
        $id = static::$seed->staff;
        $html = $this->staff('ad')->get('pricing/plan/' . self::$plan)->body;
        $xp = self::xp($html);
        $this->assertSame(0, $xp->query('//details[contains(@class, "nuxr-inline-edit")]')->length, 'no inline form wall');
        self::assertUniqueIds($xp);
        $editors = self::editors($xp);
        $this->assertNotEmpty($editors);
        $key = 'nuxr-pe-' . $id['B'] . '-' . self::$plan;
        $this->assertArrayHasKey($key, $editors);
        $this->assertSame(['pricing/wholesale-post', 'pricing/retail-post', 'pricing/availability-post'], $editors[$key], 'Admin: the three accepted setters');
        // each editor is opened from its row in the table and from its card - and only from there
        foreach (array_keys($editors) as $dialog) {
            $this->assertSame(2, $xp->query('//*[@data-nuxr-open="' . $dialog . '"]')->length, "{$dialog}: one Edit per row (table + card)");
        }
    }

    public function testTheEditorOffersOnlyWhatTheProjectionAllows(): void
    {
        $id = static::$seed->staff;
        // the parent Agent on its direct child: wholesale + availability, never retail; no provider base cost anywhere
        $parent = $this->staff('A')->get('pricing/catalog/' . $id['B'])->body;
        $editors = self::editors(self::xp($parent));
        $this->assertSame(['pricing/wholesale-post', 'pricing/availability-post'], $editors['nuxr-pe-' . $id['B'] . '-' . self::$plan]);
        $this->assertDoesNotMatchRegularExpression('/10[.,]37/', $parent);
        // the child Agent on itself: own retail only, nothing upstream inside the surface either
        $child = $this->staff('B')->get('pricing')->body;
        $editors = self::editors(self::xp($child));
        $this->assertSame(['pricing/retail-post'], $editors['nuxr-pe-' . $id['B'] . '-' . self::$plan]);
        foreach (['/10[.,]37/', '/12[.,]11/', '/Staff A\b/'] as $secret) {
            $this->assertDoesNotMatchRegularExpression($secret, $child);
        }
        // Admin plan administration: base cost + reseller commerce in one surface per plan
        $plans = self::xp($this->staff('ad')->get('pricing/plans')->body);
        self::assertUniqueIds($plans);
        $this->assertSame(['pricing/base-cost-post', 'pricing/resale-post'], self::editors($plans)['nuxr-pb-' . self::$plan]);
    }
}
