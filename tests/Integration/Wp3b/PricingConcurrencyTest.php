<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\FinanceActor;
use Reseller\Finance\Money;
use Reseller\Finance\PricingService;
use Reseller\Finance\ReservationService;
use Reseller\Finance\SettlementFailure;
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

    /** Expected state of a near-maximum settlement fixture for each approved outcome (one common structure). */
    private const NEAR_MAX_OUTCOMES = [
        // commit first: the commission fits, the funding is refused by the money range
        'settlement-first' => ['ok' => 1, 'refusal' => ['STORAGE_RANGE_EXCEEDED'], 'commissions' => ['3.00'], 'reservation' => 'COMMITTED', 'leaf' => '85.00'],
        // funding first: the settlement is refused atomically (typed), nothing is debited or credited
        'funding-first' => ['ok' => 1, 'refusal' => ['SETTLEMENT_CAPACITY:COMMISSION_RECIPIENT_CAPACITY'], 'commissions' => [], 'reservation' => 'ACTIVE', 'leaf' => '100.00'],
    ];

    /** @return array{0:int,1:int,2:int,3:int} [top, leaf, activation, reservation] with top at MAX - 5.00 */
    private function nearMaximumFixture(): array
    {
        [, $top, $leaf, $act, $res] = $this->settlementFixture();
        // top: 100.00 -> MAX - 5.00 (the 3.00 commission fits now; 3.00 + a 4.00 funding does not)
        $this->fund($top, Money::ofMinor(Money::STORAGE_MAX_MINOR)->minus(Money::parsePositive('105'))->format());
        return [$top, $leaf, $act, $res];
    }

    /** Observed state in the common outcome structure. @param list<array<string,mixed>> $results */
    private function nearMaximumState(array $results, int $leaf, int $act, int $res): array
    {
        [$ok, $reasons] = self::outcome($results);
        return [
            'ok' => $ok,
            'refusal' => $reasons,
            'commissions' => array_values($this->commissions($act)),
            'reservation' => (string) static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE id = ?', [$res]),
            'leaf' => $this->balance($leaf),
        ];
    }

    /**
     * Both outcomes converge after the administrative fix of the funding-first path: exactly one
     * settlement (one debit, one commission), the funding either committed or refused, never both.
     */
    private function assertSettledOnce(int $top, int $leaf, int $act, int $res, string $winner): void
    {
        $admin = $this->ctx('ad'); // built on every path: ctx() itself asserts the staff row exists
        if ($winner === 'funding-first') {
            TransferService::withdraw($admin, $top, '10', 'headroom', self::ref());
            ReservationService::commit($res, FinanceActor::system('wp3b-activation'));
        }
        $this->assertSame(
            ['COMMITTED', ['3.00'], '85.00', 1],
            [
                (string) static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE id = ?', [$res]),
                array_values($this->commissions($act)),
                $this->balance($leaf),
                $this->rowCount('tbl_manager_ledger', "activation_id = ? AND entry_type = 'ACTIVATION_DEBIT'", [$act]),
            ],
            "{$winner}: settled exactly once"
        );
    }

    /** Deterministic ordering 1: the settlement commits first, then the funding no longer fits. */
    public function testNearMaximumSettlementFirst(): void
    {
        [$top, $leaf, $act, $res] = $this->nearMaximumFixture();
        $commit = $this->capture(fn() => ReservationService::commit($res, FinanceActor::system('wp3b-activation')));
        $fund = $this->capture(fn() => TransferService::fund($this->ctx('ad'), $top, '4', 'test funding', self::ref('CM')));
        $results = [$commit, $fund];
        $this->assertSame(self::NEAR_MAX_OUTCOMES['settlement-first'], $this->nearMaximumState($results, $leaf, $act, $res));
        $this->assertSettledOnce($top, $leaf, $act, $res, 'settlement-first');
    }

    /** Deterministic ordering 2: the funding commits first, then the settlement fails atomically. */
    public function testNearMaximumFundingFirst(): void
    {
        [$top, $leaf, $act, $res] = $this->nearMaximumFixture();
        $fund = $this->capture(fn() => TransferService::fund($this->ctx('ad'), $top, '4', 'test funding', self::ref('CM')));
        $commit = $this->capture(fn() => ReservationService::commit($res, FinanceActor::system('wp3b-activation')));
        $results = [$commit, $fund]; // same order as the race: [settlement, funding]
        $this->assertSame(self::NEAR_MAX_OUTCOMES['funding-first'], $this->nearMaximumState($results, $leaf, $act, $res));
        $this->assertSettledOnce($top, $leaf, $act, $res, 'funding-first');
    }

    /**
     * The real race (separate processes, barrier): whichever worker wins, exactly one approved outcome
     * occurs, it is validated with the same assertions as its deterministic case, and both converge to
     * exactly one settlement. Every round performs the same number of assertions whatever the winner.
     */
    public function testNearMaximumRecipientBalanceRace(): void
    {
        $winners = [];
        for ($i = 0; $i < self::ROUNDS; $i++) {
            [$top, $leaf, $act, $res] = $this->nearMaximumFixture();
            $r = $this->raceWallet($top, [$this->commitOp($res), ['op' => 'fund', 'actor' => $this->sid('ad'), 'manager' => $top, 'amount' => '4', 'reference' => self::ref('CM')]]);
            $winner = $r[0]['ok'] ? 'settlement-first' : 'funding-first';
            $winners[] = $winner;
            $this->assertSame(self::NEAR_MAX_OUTCOMES[$winner], $this->nearMaximumState($r, $leaf, $act, $res), "round {$i}: {$winner}");
            $this->assertSettledOnce($top, $leaf, $act, $res, $winner);
        }
        if (getenv('WP3B_EVIDENCE')) {
            fwrite(STDERR, 'NEAR-MAX RACE WINNERS: ' . json_encode(array_count_values($winners)) . "\n");
        }
    }

    /** Same result shape as a worker: {ok, reason} for a refusal. @return array<string,mixed> */
    private function capture(callable $fn): array
    {
        try {
            $fn();
            return ['ok' => true];
        } catch (\Reseller\Domain\DomainError $e) {
            return ['ok' => false, 'reason' => $e->reason];
        } catch (SettlementFailure $e) {
            return ['ok' => false, 'reason' => 'SETTLEMENT_' . $e->kind . ':' . $e->reason];
        }
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
            // one assertion whatever the order: an invalid retail is never saleable, a valid one always is
            $invalid = Money::parsePositive($q['effective_retail'])->compare(Money::parsePositive($q['effective_wholesale'])) < 0;
            $this->assertSame($invalid ? ['RETAIL_BELOW_WHOLESALE'] : [], $q['reasons'], 'saleability follows the stored prices');
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
