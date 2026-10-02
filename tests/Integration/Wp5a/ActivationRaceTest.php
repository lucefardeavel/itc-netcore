<?php
namespace Reseller\Tests\Integration\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceWorkers;

/**
 * Concurrency (real worker processes started on one signal; 13_TEST_ACCEPTANCE_MATRIX "two devices same
 * account/operation attempts", "two concurrent requests cannot overspend"; WP3B §9.4 plan-reference race):
 * every round ends in a coherent state and the outcome record is asserted the same way whatever the order.
 */
final class ActivationRaceTest extends ActivationTestCase
{
    private const ROUNDS = 4;

    private function op(int $actor, int $customer, int $plan, string $action, string $nonce): array
    {
        return ['op' => 'activate', 'actor' => $actor, 'customer' => $customer, 'plan' => $plan, 'action' => $action, 'nonce' => $nonce];
    }

    private static function outcome(array $r): string
    {
        return $r['ok'] ? 'OK:' . $r['result']['status'] . ($r['result']['replayed'] ? ':replay' : '') : 'REFUSED:' . $r['reason'];
    }

    public function testDoubleSubmitWithTheSameKeyIsOneActivation(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $c = $this->customer($leaf);
            $nonce = self::nonce();
            $r = FinanceWorkers::stampede(static::$db, [$this->op($leaf, $c, $plan, 'NEW', $nonce), $this->op($leaf, $c, $plan, 'NEW', $nonce)]);
            // both requests return the same activation (one opened it, the other replayed it); whichever of them
            // drives a step, each step runs once: the request that loses a step reports the state it reached
            $this->assertTrue($r[0]['ok'] && $r[1]['ok'], "round {$i}: " . json_encode($r));
            $this->assertSame($r[0]['result']['reference'], $r[1]['result']['reference'], 'same activation');
            $this->assertSame([false, true], [min($r[0]['result']['replayed'], $r[1]['result']['replayed']), max($r[0]['result']['replayed'], $r[1]['result']['replayed'])], 'one opened, one replayed');
            $this->assertSame(1, $this->rowCount('tbl_reseller_activations', 'customer_id = ?', [$c]), 'one activation');
            $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE customer_id = ?', [$c]));
            $this->assertSame(1, $this->rowCount('tbl_transactions', 'user_id = ?', [$c]), 'one invoice');
        }
        $this->assertSame([(string) (1000 - 15 * self::ROUNDS) . '.00', (string) (1000 + 3 * self::ROUNDS) . '.00'], [$this->balance($leaf), $this->balance($top)], 'one debit and one commission per customer');
    }

    public function testTwoDevicesWithDifferentKeysYieldOnePeriod(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $c = $this->customer($leaf);
            $r = FinanceWorkers::stampede(static::$db, [$this->op($leaf, $c, $plan, 'NEW', self::nonce()), $this->op($leaf, $c, $plan, 'NEW', self::nonce())]);
            $outcomes = array_map([self::class, 'outcome'], $r);
            sort($outcomes);
            $this->assertContains($outcomes, [['OK:COMPLETED', 'REFUSED:ACTIVATION_IN_PROGRESS'], ['OK:COMPLETED', 'REFUSED:ACTION_MISMATCH']], "round {$i}: " . json_encode($outcomes));
            $this->assertSame(1, $this->rowCount('tbl_reseller_activations', 'customer_id = ?', [$c]), 'one period');
        }
    }

    public function testConcurrentActivationsCannotOverspend(): void
    {
        $plan = $this->radiusPlan('20');
        $this->base($plan, '10');
        [, $leaf] = $this->chain($plan, ['12', '15'], '20'); // room for one 15.00 sale
        $customers = [$this->customer($leaf), $this->customer($leaf), $this->customer($leaf)];
        $r = FinanceWorkers::stampede(static::$db, array_map(fn(int $c) => $this->op($leaf, $c, $plan, 'NEW', self::nonce()), $customers));
        $outcomes = array_map([self::class, 'outcome'], $r);
        sort($outcomes);
        $this->assertSame(['OK:COMPLETED', 'REFUSED:INSUFFICIENT_AVAILABLE_CREDIT', 'REFUSED:INSUFFICIENT_AVAILABLE_CREDIT'], $outcomes);
        $this->assertSame('5.00', $this->balance($leaf), 'never below the floor');
    }

    /** A price change racing an activation: the sealed price is the old or the new one, and the debit equals it. */
    public function testActivationVersusPriceChangeIsNeverAMix(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '30');
        $seen = [];
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $c = $this->customer($leaf);
            $new = (string) (16 + $i);
            $r = FinanceWorkers::stampede(static::$db, [
                $this->op($leaf, $c, $plan, 'NEW', self::nonce()),
                ['op' => 'set_wholesale', 'actor' => $top, 'manager' => $leaf, 'plan' => $plan, 'cost' => $new],
            ]);
            $this->assertTrue($r[0]['ok'] && $r[1]['ok'], json_encode($r));
            $act = static::$db->rows('SELECT id, wholesale_cost FROM tbl_reseller_activations WHERE customer_id = ?', [$c])[0];
            $old = $i === 0 ? '15.00' : (16 + $i - 1) . '.00';
            $this->assertContains($act['wholesale_cost'], [$old, $new . '.00'], "round {$i}: the sealed price is one of the two");
            $debit = static::$db->value("SELECT amount FROM tbl_manager_ledger WHERE activation_id = ? AND entry_type = 'ACTIVATION_DEBIT'", [$act['id']]);
            $this->assertSame($act['wholesale_cost'], $debit, 'debit = sealed price');
            $seen[] = $act['wholesale_cost'] === $old ? 'activation-first' : 'price-first';
        }
        if (getenv('WP3B_EVIDENCE')) {
            fwrite(STDERR, 'ACTIVATION/PRICE RACE: ' . json_encode(array_count_values($seen)) . "\n");
        }
    }

    /** WP3B §9.4: an activation and a plan delete are serialized; neither can see a half state. */
    public function testActivationVersusPlanDelete(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $r = FinanceWorkers::stampede(static::$db, [$this->op($leaf, $c, $plan, 'NEW', self::nonce()), ['op' => 'plan_delete', 'plan' => $plan]]);
        $this->assertSame('OK:COMPLETED', self::outcome($r[0]), 'a priced plan is referenced (base cost): the delete never wins');
        $this->assertTrue($r[1]['ok'] && $r[1]['result'] !== 'DELETED', json_encode($r[1]));
        $this->assertSame(1, $this->rowCount('tbl_plans', 'id = ?', [$plan]));
        // an unpriced plan: the delete may win; the activation then finds no plan, and never the reverse
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $bare = $this->radiusPlan('20');
            $c2 = $this->customer($leaf);
            $r = FinanceWorkers::stampede(static::$db, [$this->op($leaf, $c2, $bare, 'NEW', self::nonce()), ['op' => 'plan_delete', 'plan' => $bare]]);
            $this->assertContains(self::outcome($r[0]), ['REFUSED:NOT_FOUND', 'REFUSED:PLAN_NOT_RESELLABLE'], json_encode($r));
            $this->assertSame('DELETED', $r[1]['result'] ?? null, json_encode($r[1]));
            $this->assertSame(0, $this->rowCount('tbl_reseller_activations', 'customer_id = ?', [$c2]));
        }
    }
}
