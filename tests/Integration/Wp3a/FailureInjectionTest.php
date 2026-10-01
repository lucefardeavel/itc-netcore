<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\FinanceFaults;
use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\FinanceInvariants;
use Reseller\Tests\Support\FinanceTestCase;

/**
 * WP3A §21 failure atomicity: a failure injected after every internal step rolls the whole unit back.
 * No half-transfer, no orphan ledger row, no debit without ledger, no completed operation without all
 * of its entries, no reservation status without its ledger entry — and the retry of the same command
 * then succeeds exactly once (and replays afterwards).
 */
final class FailureInjectionTest extends FinanceTestCase
{
    /** @return array<string,array{0:string,1:string}> point => [point, scenario] */
    public static function points(): array
    {
        $map = [
            'fund.after_transfer_row' => 'fund', 'fund.after_ledger' => 'fund', 'fund.after_audit' => 'fund',
            'withdraw.after_transfer_row' => 'withdraw', 'withdraw.after_ledger' => 'withdraw', 'withdraw.after_audit' => 'withdraw',
            'adjust.after_ledger' => 'adjust', 'adjust.after_audit' => 'adjust',
            'transfer.after_wallet_locks' => 'transfer', 'transfer.after_transfer_row' => 'transfer', 'transfer.after_first_leg' => 'transfer',
            'transfer.after_second_leg' => 'transfer', 'transfer.after_audit' => 'transfer',
            'debt_limit.after_update' => 'debt_limit', 'debt_limit.after_audit' => 'debt_limit',
            'reverse.after_reversal_row' => 'reverse', 'reverse.after_first_leg' => 'reverse', 'reverse.after_status_update' => 'reverse',
            'reverse.after_audit' => 'reverse',
            'reserve.after_insert' => 'reserve',
            'commit.after_ledger_before_status' => 'commit', 'commit.after_status' => 'commit',
            'release.after_status' => 'release',
        ];
        $out = [];
        foreach ($map as $point => $scenario) {
            $out[$point] = [$point, $scenario];
        }
        return $out;
    }

    /** @return callable():mixed the command (fixed references / ids, so a retry is the same command) */
    private function scenario(string $name): callable
    {
        $ad = $this->ctx('ad');
        $ref = self::ref('FI');
        switch ($name) {
            case 'fund':
                $id = $this->agent(); // the wallet is created by this command: its creation must roll back too
                return fn() => TransferService::fund($ad, $id, '12.34', 'injected funding', $ref);
            case 'withdraw':
                $id = $this->funded('50');
                return fn() => TransferService::withdraw($ad, $id, '20', 'injected withdrawal', $ref);
            case 'adjust':
                $id = $this->funded('50');
                return fn() => TransferService::adjustDebit($ad, $id, '20', 'injected adjustment', $ref);
            case 'transfer':
                $p = $this->funded('50');
                $c = $this->agent($p); // destination wallet created inside the failed unit
                $ctx = $this->ctx($p);
                return fn() => TransferService::transferToChild($ctx, $p, $c, '20', 'injected transfer', $ref);
            case 'debt_limit':
                $id = $this->agent();
                return fn() => WalletService::setDebtLimit($ad, $id, '75', 'injected debt limit');
            case 'reverse':
                $p = $this->funded('50');
                $c = $this->agent($p);
                $t = TransferService::transferToChild($this->ctx($p), $p, $c, '20', 'to be reversed', self::ref());
                return fn() => TransferService::reverse($ad, $t->transferId, 'injected reversal', $ref);
            case 'reserve':
                $id = $this->funded('50');
                $act = $this->activation($id);
                return fn() => ReservationService::reserve($act, $id, Money::parsePositive('20'));
            case 'commit':
                $id = $this->funded('50');
                $r = ReservationService::reserve($this->activation($id), $id, Money::parsePositive('20'));
                $actor = FinanceActor::staff($ad);
                return fn() => ReservationService::commit($r->reservationId, $actor);
            case 'release':
                $id = $this->funded('50');
                $r = ReservationService::reserve($this->activation($id), $id, Money::parsePositive('20'));
                $actor = FinanceActor::staff($ad);
                return fn() => ReservationService::release($r->reservationId, $actor, 'confirmed unused');
        }
        throw new \LogicException($name);
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
        }
        FinanceFaults::$hook = null;
        $this->assertContains($point, $reached, 'the injection point was reached');
        $this->assertSame([], DbProbe::changed($before, DbProbe::checksums(static::$db, ['tbl_logs'])), "rollback after {$point} (finance, audit, customers, users)");
        $this->assertSame([], FinanceInvariants::check(static::$db));
        $this->assertFalse(static::$svc->inTransaction(), 'the transaction is closed');
        $this->assertSame('NO_ENGINE_SUBSTITUTION', static::$svc->query('SELECT @@SESSION.sql_mode')->fetchColumn(), 'the session sql_mode is restored');
        $this->assertNull(static::$db->value('SELECT IS_USED_LOCK(?)', ['nuxr_tree_' . substr(sha1(static::$db->name), 0, 20)]), 'the tree lock is released');
        // the same command now succeeds exactly once, then replays
        $first = $command();
        $mid = DbProbe::checksums(static::$db, ['tbl_logs']);
        $again = $command();
        if ($scenario === 'debt_limit') {
            // an absolute value: repeating it is a no-op (no second audit)
            $this->assertTrue($first->exists);
            $this->assertSame($first->debtLimit->format(), $again->debtLimit->format());
        } else {
            $this->assertFalse($first->replayed);
            $this->assertTrue($again->replayed);
        }
        $this->assertSame($mid, DbProbe::checksums(static::$db, ['tbl_logs']), 'the repeat has no effect');
    }
}
