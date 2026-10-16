<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\PricingService;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * WP9 / W8-14 (same-key ADMIN_SYNC; owner pre-authorization: lowest-risk option = forbid the configuration): a customer
 * whose Hotspot service is active cannot get a PPPoE service on the SAME RADIUS key (no PPPoE username set): refused
 * before any write (no reservation, no RADIUS change). Positive control: with a distinct PPPoE username both services
 * run side by side on their own keys.
 */
final class W9SharedRadiusKeyTest extends ActivationTestCase
{
    public function testSecondServiceTypeOnTheSameKeyIsRefused(): void
    {
        [$hotspot, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $pppoe = $this->radiusPlan('20', 'PPPOE');
        $this->base($pppoe, '10');
        PricingService::setChildWholesale($this->ctx('ad'), $top, $pppoe, '12');
        PricingService::setChildWholesale($this->ctx($top), $leaf, $pppoe, '15');
        $c = $this->customer($leaf, null, ['pppoe_username' => '']);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $hotspot, 'NEW')['status']);
        $before = $this->checksums();
        $this->refused('RADIUS_KEY_SHARED', fn() => $this->activate($leaf, $c, $pppoe, 'NEW'), false);
        $this->assertSame($before, $this->checksums(), 'nothing written by the refusal');
        // positive control: a distinct PPPoE username -> its own RADIUS key
        static::$db->exec("UPDATE tbl_customers SET pppoe_username = 'w9pp" . bin2hex(random_bytes(3)) . "' WHERE id = {$c}");
        $this->customersChanged();
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $pppoe, 'NEW')['status']);
    }
}
