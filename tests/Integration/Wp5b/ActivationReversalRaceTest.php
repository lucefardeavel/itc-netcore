<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceWorkers;

/**
 * WP5B §2.4 (revision 2, correction 2): the reversal start and an activation open of the same customer are
 * serialized by the same tree lock and each re-validates after acquiring it. Real worker processes, several
 * rounds: either the reversal wins (the open is refused) or the open wins (the reversal is refused before any
 * RADIUS work); never both.
 */
final class ActivationReversalRaceTest extends ActivationTestCase
{
    private const ROUNDS = 6;

    public function testReversalStartAndActivationOpenNeverBothProceed(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $outcomes = [];
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $c = $this->customer($leaf);
            $first = $this->activate($leaf, $c, $plan, 'NEW');
            $this->assertSame('COMPLETED', $first['status']);
            $id = (int) $this->act($first['reference'])['id'];
            [$rev, $open] = FinanceWorkers::stampede(static::$db, [
                ['op' => 'reverse_activation', 'actor' => $this->sid('ad'), 'reference' => $first['reference'], 'reason' => 'concurrency reversal'],
                ['op' => 'activate', 'actor' => $leaf, 'customer' => $c, 'plan' => $plan, 'action' => 'RENEW', 'nonce' => self::nonce()],
            ]);
            $reversed = $rev['ok'] && $rev['result']['status'] === 'REVERSED';
            $opened = $open['ok'] && in_array($open['result']['status'], ['COMPLETED', 'RECONCILIATION_REQUIRED'], true);
            $this->assertFalse($reversed && $opened, "round {$round}: both proceeded");
            $this->assertTrue($reversed || $opened, "round {$round}: neither proceeded " . json_encode([$rev, $open]));
            if ($reversed) {
                // A: the open waited on the same lock, then saw REVERSAL_PENDING (in flight) or the reversed service
                $this->assertContains($open['reason'] ?? null, ['ACTIVATION_IN_PROGRESS', 'ACTION_MISMATCH'], "round {$round}");
                $this->assertSame(1, $this->rowCount('tbl_reseller_activations', 'customer_id = ?', [$c]), 'no second activation');
                $outcomes['A'] = ($outcomes['A'] ?? 0) + 1;
            } else {
                // B: the reversal saw the newer activation and refused before any RADIUS work
                $this->assertSame('REVERSAL_EXTERNAL_STATE_CHANGED', $rev['reason'] ?? null, "round {$round}");
                $this->assertSame('COMPLETED', $this->act($first['reference'])['status']);
                $this->assertSame(0, $this->rowCount('tbl_reseller_activation_events', "activation_id = ? AND to_state IN ('REVERSAL_PENDING', 'REVERSED')", [$id]),
                    'no reversal step was recorded (no restore happened)');
                $this->assertNotSame([], $this->radcheck($this->username($c)), 'RADIUS keeps the renewed service');
                $outcomes['B'] = ($outcomes['B'] ?? 0) + 1;
            }
        }
        $this->addToAssertionCount(count($outcomes));
    }

    /** §10: reversal start vs a bounded re-provision of the same customer: one owns the window, never both overlap. */
    public function testReversalStartAndReprovisionAreSerialized(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        for ($round = 1; $round <= 4; $round++) {
            $c = $this->customer($leaf);
            $first = $this->activate($leaf, $c, $plan, 'NEW');
            [$rev, $sync] = FinanceWorkers::stampede(static::$db, [
                ['op' => 'reverse_activation', 'actor' => $this->sid('ad'), 'reference' => $first['reference'], 'reason' => 'concurrency reversal'],
                ['op' => 'reprovision', 'actor' => $this->sid('ad'), 'customer' => $c, 'reprovision' => 'ADMIN_SYNC'],
            ]);
            $this->assertTrue($rev['ok'] && $rev['result']['status'] === 'REVERSED', "round {$round}: " . json_encode($rev));
            // the sync ran entirely before the reversal (DONE, the target was re-applied identically) or saw it in
            // flight / reversed (refused, or nothing active to sync); it never re-applied the service after the restore
            $this->assertTrue($sync['ok'] ? in_array($sync['result']['status'], ['DONE', 'NO_ACTIVE_PLAN'], true) : $sync['reason'] === 'ACTIVATION_IN_PROGRESS',
                "round {$round}: " . json_encode($sync));
            $this->assertSame([], $this->radcheck($this->username($c)), "round {$round}: the prior state (nothing) stays restored");
        }
    }
}
