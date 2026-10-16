<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * Architecture Lock gate P3 / WP6A R-15 (owner pre-authorization: lowest-risk "freeze / bind / verify"): the bandwidth
 * terms the reseller confirmed are the terms provisioned. A bandwidth edit between the confirmation (open) and the
 * provisioning never changes that activation's service: it fails BEFORE any RADIUS write, the reservation is released
 * (nothing charged) and the reseller confirms again. Positive control: unchanged terms complete.
 */
final class W9BandwidthTermsTest extends ActivationTestCase
{
    public function testBandwidthEditAfterConfirmationIsNotProvisioned(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $bw = (int) static::$db->value('SELECT id_bw FROM tbl_plans WHERE id = ?', [$plan]);
        $orig = static::$db->rows('SELECT rate_down, rate_up FROM tbl_bandwidth WHERE id = ?', [$bw])[0];
        $before = [$this->balance($leaf), $this->balance($top)];
        $pdo = static::$db->db()->pdo;
        ActivationFaults::$hook = static function (string $p) use ($pdo, $bw): void {
            if ($p === 'activation.before_execute') {
                $pdo->exec("UPDATE tbl_bandwidth SET rate_down = rate_down + 7 WHERE id = {$bw}"); // an Admin edits the plan's bandwidth
            }
        };
        try {
            $out = $this->activate($leaf, $c, $plan, 'NEW');
        } finally {
            ActivationFaults::$hook = null;
            $pdo->prepare('UPDATE tbl_bandwidth SET rate_down = ?, rate_up = ? WHERE id = ?')->execute([$orig['rate_down'], $orig['rate_up'], $bw]);
        }
        $this->assertSame(['FAILED', 'BANDWIDTH_TERMS_CHANGED'], [$out['status'], $out['error_code']], json_encode($out));
        $this->assertSame($before, [$this->balance($leaf), $this->balance($top)], 'nothing charged');
        $this->assertSame([], $this->radcheck($this->username($c)), 'nothing provisioned');
        $this->assertSame('RELEASED', static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [(int) $this->act($out['reference'])['id']]));
        // positive control: the same activation with unchanged terms completes
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
    }
}
