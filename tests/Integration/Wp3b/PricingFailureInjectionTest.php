<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\FinanceFaults;
use Reseller\Finance\PricingResult;
use Reseller\Finance\PricingService;
use Reseller\Finance\ReservationResult;
use Reseller\Finance\ReservationService;
use Reseller\Finance\SettlementFailure;
use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\FinanceInvariants;
use Reseller\Tests\Support\PricingTestCase;

/**
 * WP3B failure atomicity: a failure injected after every internal step of a pricing mutation and of an
 * activation settlement (debit, commissions, status) rolls the whole unit back: no partial debit, no
 * partial commission, no configuration without its audit row. The retry of the same command then
 * succeeds exactly once, and the next call is a replay / no-op.
 */
final class PricingFailureInjectionTest extends PricingTestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function points(): array
    {
        $out = [];
        foreach (['base', 'resale', 'wholesale', 'retail', 'availability'] as $s) {
            foreach (['pricing.after_write', 'pricing.after_audit'] as $p) {
                $out["{$s} {$p}"] = [$p, $s];
            }
        }
        foreach (['commit.after_debit_before_commissions', 'commit.after_commissions', 'commit.after_ledger_before_status', 'commit.after_status'] as $p) {
            $out["settle {$p}"] = [$p, 'settle'];
        }
        $out['reserve-sealed reserve.after_insert'] = ['reserve.after_insert', 'reserve'];
        return $out;
    }

    /** @return callable():mixed */
    private function scenario(string $name): callable
    {
        $plan = $this->plan('100');
        $ad = $this->ctx('ad');
        if ($name === 'base') {
            return fn() => PricingService::setBaseCost($ad, $plan, '10', 'injected');
        }
        $this->base($plan, '10');
        [$top, $mid, $leaf] = $this->chain($plan, ['12', '15', '17'], '100');
        $ctxTop = $this->ctx($top);
        $ctxLeaf = $this->ctx($leaf);
        return match ($name) {
            'resale' => fn() => PricingService::setResaleEnabled($ad, $plan, false),
            'wholesale' => fn() => PricingService::setChildWholesale($ctxTop, $mid, $plan, '16'),
            'retail' => fn() => PricingService::setRetail($ctxLeaf, $leaf, $plan, '30'),
            'availability' => fn() => PricingService::setChildAvailability($ctxTop, $mid, $plan, false),
            'reserve' => (function () use ($leaf, $plan) {
                $act = $this->sealedActivation($leaf, $plan);
                return fn() => $this->reserveSealed($act);
            })(),
            'settle' => (function () use ($leaf, $plan) {
                $r = $this->reserveSealed($this->sealedActivation($leaf, $plan));
                $actor = FinanceActor::system('wp3b-activation');
                return fn() => ReservationService::commit($r->reservationId, $actor);
            })(),
        };
    }

    #[DataProvider('points')]
    public function testFailureAtEveryStepRollsBackCompletely(string $point, string $scenario): void
    {
        $command = $this->scenario($scenario);
        $reached = [];
        FinanceFaults::$hook = static function (string $p) use ($point, &$reached): void {
            $reached[] = $p;
            if ($p === $point) {
                throw new \RuntimeException('injected failure at ' . $p);
            }
        };
        static::$svc->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $before = DbProbe::checksums(static::$db, ['tbl_logs']);
        try {
            $command();
            $this->fail("no failure injected at {$point}");
        } catch (\RuntimeException $e) {
            $this->assertSame('injected failure at ' . $point, $e->getMessage());
            if ($scenario === 'settle') {
                $this->assertInstanceOf(SettlementFailure::class, $e, 'a settlement failure is typed');
                $this->assertSame([SettlementFailure::UNKNOWN, SettlementFailure::EFFECTS_NONE], [$e->kind, $e->effects]);
            }
        }
        FinanceFaults::$hook = null;
        $this->assertContains($point, $reached, 'the injection point was reached');
        $this->assertSame([], DbProbe::changed($before, DbProbe::checksums(static::$db, ['tbl_logs'])), "rollback after {$point}");
        $this->assertSame([], FinanceInvariants::check(static::$db));
        $this->assertFalse(static::$svc->inTransaction(), 'transaction closed');
        $this->assertSame('NO_ENGINE_SUBSTITUTION', static::$svc->query('SELECT @@SESSION.sql_mode')->fetchColumn(), 'the session sql_mode is restored');
        $this->assertNull(static::$db->value('SELECT IS_USED_LOCK(?)', ['nuxr_tree_' . substr(sha1(static::$db->name), 0, 20)]), 'the tree lock is released');
        // the same command succeeds exactly once, then replays / is a no-op
        $first = $command();
        $mid = DbProbe::checksums(static::$db, ['tbl_logs']);
        $second = $command();
        $this->assertSame($mid, DbProbe::checksums(static::$db, ['tbl_logs']), 'the repeat has no effect');
        if ($first instanceof PricingResult) {
            $this->assertTrue($first->changed);
            $this->assertFalse($second->changed);
        } else {
            $this->assertInstanceOf(ReservationResult::class, $first);
            $this->assertFalse($first->replayed);
            $this->assertTrue($second->replayed);
        }
    }
}
