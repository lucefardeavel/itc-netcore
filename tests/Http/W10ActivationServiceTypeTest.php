<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * Lab UX finding UX-3 (ITC NetCore lab 2026-10-25): activation/create let an operator confirm a PPPoE plan for a
 * Hotspot subscriber with no hint, and printed a bare "-" as the current expiry when the subscriber had no service of
 * the plan's type. The subscriber's service type is shown (read only), the confirmation warns when the plan type
 * differs from it, and the empty expiry reads "No active service of this type". Display only: the confirmation is
 * NOT blocked (blocking is an owner decision).
 */
final class W10ActivationServiceTypeTest extends RadiusHttpTestCase
{
    private static int $hotspot;
    private static int $pppoe;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'w10_ux3_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        $plan = static fn(string $name, string $type): int => (int) static::$db->insertRow('tbl_plans', [
            'name_plan' => $name, 'id_bw' => $bw, 'price' => '20', 'type' => $type, 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        self::$hotspot = $plan('w10_ux3_hs', 'Hotspot');
        self::$pppoe = $plan('w10_ux3_pp', 'PPPOE');
        $hs = self::$hotspot;
        $pp = self::$pppoe;
        $a = static::$seed->staff['A'];
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
foreach ([{$hs}, {$pp}] as \$p) {
    \\Reseller\\Finance\\PricingService::setBaseCost(\$ad, \$p, '10');
    \\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, \$p, true);
}
\\Reseller\\Finance\\TransferService::fund(\$ad, {$a}, '100', 'initial provider funding', 'F-w10-ux3');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    public function testPppoePlanForAHotspotSubscriberWarnsWithoutBlocking(): void
    {
        $cid = $this->cid('cA1'); // seeded with service_type Hotspot
        $this->assertSame('Hotspot', (string) static::$db->value('SELECT service_type FROM tbl_customers WHERE id = ?', [$cid]), 'fixture');
        $c = $this->staff('ad');
        $page = $c->get('activation/create', ['customer' => $cid, 'plan' => self::$pppoe]);
        $this->assertSame(200, $page->status, $page->excerpt());
        $html = $page->body;
        $this->assertStringContainsString('id="nuxr-confirm-form"', $html, 'the confirmation step is shown');
        $this->assertStringContainsString('id="nuxr-service-type"', $html, 'the subscriber\'s service type is shown');
        $this->assertStringContainsString('id="nuxr-service-mismatch"', $html, 'the mismatch is flagged');
        $this->assertStringContainsString('The plan type does not match the service type of this subscriber.', $html);
        $this->assertStringContainsString('No active service of this type', $html, 'no PPPoE service yet: an explicit current expiry');
        $this->assertDoesNotMatchRegularExpression('#Current expiry</dt><dd>-</dd>#', $html, 'not a bare dash');
        $this->assertStringContainsString('Credit sufficient: yes', $html, 'fixture: funded');
        $this->assertDoesNotMatchRegularExpression('#type="submit"\s+disabled#', $html, 'display only: the confirm button stays enabled');
    }

    public function testMatchingPlanTypeShowsNoWarning(): void
    {
        $page = $this->staff('ad')->get('activation/create', ['customer' => $this->cid('cA0'), 'plan' => self::$hotspot]);
        $this->assertSame(200, $page->status, $page->excerpt());
        $this->assertStringContainsString('id="nuxr-confirm-form"', $page->body, 'the confirmation step is shown');
        $this->assertStringNotContainsString('id="nuxr-service-mismatch"', $page->body, 'Hotspot plan for a Hotspot subscriber: no warning');
    }
}
