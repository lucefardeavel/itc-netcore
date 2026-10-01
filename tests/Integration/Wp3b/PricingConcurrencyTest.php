<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\Money;
use Reseller\Finance\PricingService;
use Reseller\Finance\TransferService;
use Reseller\Tests\Support\FinanceInvariants;
use Reseller\Tests\Support\FinanceWorkers;
use Reseller\Tests\Support\PricingTestCase;

/**
 * WP3B F-7: real concurrency (separate PHP processes and connections, deterministic barriers, ROUNDS
 * rounds on fresh resellers). The multi-wallet activation settlement races every WP3A writer that
 * touches the commission recipient or the leaf, another settlement sharing recipients, the FK identity
 * row of the leaf, and a near-maximum recipient balance; pricing mutations race each other, resolution
 * and hierarchy moves. Only financially valid outcomes may commit, no worker may fail with a deadlock /
 * timeout / unexpected error, and the whole-database invariants hold after every round.
 */
final class PricingConcurrencyTest extends PricingTestCase
{
    private const ROUNDS = 8;

    /** @param list<array<string,mixed>> $results */
    private function checked(array $results): array
    {
        foreach ($results as $r) {
            $reason = (string) ($r['reason'] ?? '');
            $this->assertNotSame('ERROR', $reason, 'unexpected worker error: ' . ($r['error'] ?? ''));
            $this->assertStringNotContainsString('CONCURRENCY_RETRY', $reason, 'deadlock surfaced');
            $this->assertStringNotContainsString('LOCK_TIMEOUT', $reason);
            $this->assertStringNotContainsString('TRANSIENT', $reason);
        }
        $this->assertSame([], FinanceInvariants::check(static::$db), 'invariants after the round');
        return $results;
    }

    private function raceWallet(int $wallet, array $ops): array
    {
        return $this->checked(FinanceWorkers::race(static::$db, FinanceWorkers::holdWallet(static::$db, $wallet), $ops));
    }

    private function raceTree(array $ops): array
    {
        return $this->checked(FinanceWorkers::race(static::$db, FinanceWorkers::holdTree(static::$db), $ops));
    }

    /** @return array{0:int,1:list<string>} */
    private static function outcome(array $results): array
    {
        $ok = 0;
        $reasons = [];
        foreach ($results as $r) {
            if ($r['ok']) {
                $ok++;
            } else {
                $reasons[] = (string) $r['reason'];
            }
        }
        sort($reasons);
        return [$ok, $reasons];
    }

    /** plan (base 10) + top (12, funded 100) -> leaf (15, funded 100) + one reserved sealed activation */
    private function settlementFixture(): array
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$top, $leaf] = $this->chain($plan, ['12', '15'], '100');
        $act = $this->sealedActivation($leaf, $plan);
        $res = $this->reserveSealed($act);
        return [$plan, $top, $leaf, $act, $res->reservationId];
    }

    private function commitOp(int $reservation): array
    {
        return ['op' => 'commit', 'reservation' => $reservation];
    }

    /** The settlement against each WP3A writer on the commission recipient. */
    public function testSettlementVersusEveryWriterOnTheRecipient(): void
    {
        $ad = $this->sid('ad');
        $writers = [
            'transfer from recipient' => fn(int $top, int $leaf) => ['op' => 'transfer', 'actor' => $top, 'from' => $top, 'to' => $leaf, 'amount' => '10', 'reference' => self::ref('CT')],
            'provider funding' => fn(int $top, int $leaf) => ['op' => 'fund', 'actor' => $ad, 'manager' => $top, 'amount' => '10', 'reference' => self::ref('CF')],
            'provider withdrawal' => fn(int $top, int $leaf) => ['op' => 'withdraw', 'actor' => $ad, 'manager' => $top, 'amount' => '10', 'reference' => self::ref('CW')],
            'debt limit' => fn(int $top, int $leaf) => ['op' => 'debt_limit', 'actor' => $ad, 'manager' => $top, 'limit' => '10'],
            'credit adjustment' => fn(int $top, int $leaf) => ['op' => 'adjust_credit', 'actor' => $ad, 'manager' => $top, 'amount' => '10', 'reference' => self::ref('CA')],
            'debit adjustment' => fn(int $top, int $leaf) => ['op' => 'adjust_debit', 'actor' => $ad, 'manager' => $top, 'amount' => '10', 'reference' => self::ref('CD')],
        ];
        $expectTop = ['transfer from recipient' => '93.00', 'provider funding' => '113.00', 'provider withdrawal' => '93.00', 'debt limit' => '103.00', 'credit adjustment' => '113.00', 'debit adjustment' => '93.00'];
        foreach ($writers as $name => $op) {
            for ($i = 0; $i < self::ROUNDS; $i++) {
                [, $top, $leaf, $act, $res] = $this->settlementFixture();
                $r = $this->raceWallet($top, [$this->commitOp($res), $op($top, $leaf)]);
                $this->assertSame([2, []], self::outcome($r), $name);
                $this->assertSame([$top => '3.00'], $this->commissions($act), $name);
                $this->assertSame($expectTop[$name], $this->balance($top), $name);
            }
        }
    }

    public function testSettlementVersusReversalAndReserveAndAnotherSettlement(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            [$plan, $top, $leaf, $act, $res] = $this->settlementFixture();
            $t = TransferService::transferToChild($this->ctx($top), $top, $leaf, '5', 'to be reversed', self::ref());
            $second = $this->reserveSealed($this->sealedActivation($leaf, $plan));
            $third = $this->sealedActivation($leaf, $plan);
            $r = $this->raceWallet($top, [
                $this->commitOp($res),
                $this->commitOp($second->reservationId),
                ['op' => 'reverse', 'actor' => $this->sid('ad'), 'transfer' => $t->transferId, 'reference' => self::ref('CR')],
                ['op' => 'reserve', 'activation' => $third, 'manager' => $leaf, 'amount' => '15'],
            ]);
            $this->assertSame([4, []], self::outcome($r));
            $this->assertSame('106.00', $this->balance($top), '100 - 5 + 5 (reversal) + 2 x 3 commissions');
            $this->assertSame('70.00', $this->balance($leaf), '100 + 5 - 5 - 2 x 15');
        }
    }

    /** The leaf's own identity row: its tree-locked transfer races the settlement of its activation. */
    public function testSettlementVersusTheLeafAgentsOwnTransfer(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            [$plan, $top, $leaf, $act, $res] = $this->settlementFixture();
            $child = $this->agent($leaf);
            $r = $this->raceWallet($leaf, [
                ['op' => 'commit_staff', 'actor' => $this->sid('ad'), 'reservation' => $res],
                ['op' => 'transfer', 'actor' => $leaf, 'from' => $leaf, 'to' => $child, 'amount' => '20', 'reference' => self::ref('CL')],
            ]);
            $this->assertSame([2, []], self::outcome($r));
            $this->assertSame(['65.00', '20.00', '103.00'], [$this->balance($leaf), $this->balance($child), $this->balance($top)]);
        }
    }

    /** Near the money-range limit: exactly one of the commission and a funding fits. */
    public function testNearMaximumRecipientBalanceRace(): void
    {
        $max = Money::ofMinor(Money::STORAGE_MAX_MINOR);
        $orders = [];
        for ($i = 0; $i < self::ROUNDS; $i++) {
            [, $top, $leaf, $act, $res] = $this->settlementFixture();
            // top: 100.00 -> MAX - 5.00 (the 3.00 commission fits now; 3.00 + 4.00 does not)
            $this->fund($top, $max->minus(Money::parsePositive('105'))->format());
            $r = $this->raceWallet($top, [$this->commitOp($res), ['op' => 'fund', 'actor' => $this->sid('ad'), 'manager' => $top, 'amount' => '4', 'reference' => self::ref('CM')]]);
            [$ok, $reasons] = self::outcome($r);
            $this->assertSame(1, $ok);
            if ($r[0]['ok']) {
                $this->assertSame(['STORAGE_RANGE_EXCEEDED'], $reasons);
                $this->assertSame([$top => '3.00'], $this->commissions($act));
                $orders['commit-first'] = true;
            } else {
                $this->assertSame(['SETTLEMENT_CAPACITY:COMMISSION_RECIPIENT_CAPACITY'], $reasons);
                $this->assertSame([], $this->commissions($act), 'no partial settlement');
                $this->assertSame('ACTIVE', static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE id = ?', [$res]));
                $this->assertSame('100.00', $this->balance($leaf), 'no partial debit');
                $orders['fund-first'] = true;
                // an administrative fix makes the settlement possible exactly once
                TransferService::withdraw($this->ctx('ad'), $top, '10', 'headroom', self::ref());
                \Reseller\Finance\ReservationService::commit($res, \Reseller\Finance\FinanceActor::system('wp3b-activation'));
            }
        }
        $this->assertNotEmpty($orders);
    }

    /** Pricing mutations serialize on the tree lock and never leave an invalid wholesale tree. */
    public function testPricingMutationRaces(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $plan = $this->plan('100');
            $this->base($plan, '10');
            [$top, $child, $grand] = $this->chain($plan, ['12', null, null], null);
            // parent child-set versus an ancestor raise: exactly one valid order commits
            $r = $this->raceTree([
                ['op' => 'set_wholesale', 'actor' => $top, 'manager' => $child, 'plan' => $plan, 'cost' => '15'],
                ['op' => 'set_wholesale', 'actor' => $this->sid('ad'), 'manager' => $top, 'plan' => $plan, 'cost' => '16'],
            ]);
            [$ok, $reasons] = self::outcome($r);
            $this->assertSame(1, $ok);
            $this->assertContains($reasons[0], ['WHOLESALE_BELOW_PARENT', 'WHOLESALE_DESCENDANT_CONFLICT']);
            foreach (PricingService::validateTree($this->ctx('ad'), $plan)['managers'] as $m) {
                $this->assertNotContains('PRICING_CHAIN_INVALID', $m['reasons'], 'never an invalid wholesale tree');
            }
            // own retail versus an upstream wholesale raise: never saleable below wholesale
            $current = PricingService::quote($this->ctx('ad'), $child, $plan)['effective_wholesale'];
            $raised = Money::parsePositive($current)->plus(Money::parsePositive('2'))->format();
            $retail = Money::parsePositive($current)->plus(Money::parsePositive('1'))->format();
            $this->raceTree([
                ['op' => 'set_retail', 'actor' => $child, 'manager' => $child, 'plan' => $plan, 'price' => $retail],
                ['op' => 'set_wholesale', 'actor' => $top, 'manager' => $child, 'plan' => $plan, 'cost' => $raised],
            ]);
            $q = PricingService::quote($this->ctx('ad'), $child, $plan);
            if ($q['effective_retail'] !== null && Money::parsePositive($q['effective_retail'])->compare(Money::parsePositive($q['effective_wholesale'])) < 0) {
                $this->assertContains('RETAIL_BELOW_WHOLESALE', $q['reasons'], 'an invalid retail is never saleable');
            } else {
                $this->assertSame([], $q['reasons']);
            }
            // availability versus an ancestor disable: restrict-only, any order gives the same result
            $r = $this->raceTree([
                ['op' => 'set_availability', 'actor' => $top, 'manager' => $child, 'plan' => $plan, 'enabled' => false],
                ['op' => 'set_availability', 'actor' => $child, 'manager' => $grand, 'plan' => $plan, 'enabled' => true],
            ]);
            $this->assertSame([2, []], self::outcome($r));
            $this->assertSame(['PLAN_DISABLED_BY_ANCESTOR'], PricingService::quote($this->ctx('ad'), $grand, $plan)['reasons']);
        }
    }

    /** A reservation commits its price only if it is still current; a price change or a move makes it stale. */
    public function testReserveVersusPriceChangeAndMove(): void
    {
        $orders = [];
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $plan = $this->plan('100');
            $this->base($plan, '10');
            [$top, $leaf] = $this->chain($plan, ['12', '15'], '100');
            $other = $this->funded('100');
            PricingService::setChildWholesale($this->ctx('ad'), $other, $plan, '12');
            $act = $this->sealedActivation($leaf, $plan);
            $r = $this->raceTree([
                ['op' => 'reserve', 'activation' => $act, 'manager' => $leaf, 'amount' => '15'],
                ['op' => 'set_wholesale', 'actor' => $top, 'manager' => $leaf, 'plan' => $plan, 'cost' => '16'],
            ]);
            $this->assertTrue($r[1]['ok']);
            $this->assertTrue($r[0]['ok'] || $r[0]['reason'] === 'PRICING_SNAPSHOT_STALE', 'reserve: committed before the change, or stale');
            $orders[$r[0]['ok'] ? 'reserve-first' : 'change-first'] = true;
            $act2 = $this->sealedActivation($leaf, $plan);
            $r = $this->raceTree([
                ['op' => 'reserve', 'activation' => $act2, 'manager' => $leaf, 'amount' => '16'],
                ['op' => 'move', 'actor' => $this->sid('ad'), 'node' => $leaf, 'parent' => $other],
            ]);
            $this->assertTrue($r[1]['ok']);
            $this->assertTrue($r[0]['ok'] || $r[0]['reason'] === 'PRICING_SNAPSHOT_STALE', 'reserve versus move');
        }
        $this->assertNotEmpty($orders);
    }
}
