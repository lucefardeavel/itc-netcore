<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\FinanceInvariants;
use Reseller\Tests\Support\FinanceTestCase;
use Reseller\Tests\Support\FinanceWorkers;

/**
 * WP3A §22: real concurrency with separate PHP processes and database connections. A barrier holds
 * the contended lock (a wallet row, or the global tree lock) until every worker is blocked on the
 * database, then releases it so the workers race for the same state. Every case runs ROUNDS times
 * on fresh resellers; exactly the financially valid outcome may commit, no worker may fail with an
 * unexpected error (deadlock, timeout), and the whole-database invariants hold after every round.
 */
final class ConcurrencyTest extends FinanceTestCase
{
    private const ROUNDS = 8;

    /** @param list<array<string,mixed>> $ops */
    private function race(int $walletId, array $ops): array
    {
        return $this->checked(FinanceWorkers::race(static::$db, FinanceWorkers::holdWallet(static::$db, $walletId), $ops));
    }

    /** @param list<array<string,mixed>> $results */
    private function checked(array $results): array
    {
        foreach ($results as $r) {
            $this->assertNotSame('ERROR', $r['reason'] ?? null, 'unexpected worker error: ' . ($r['error'] ?? ''));
        }
        $this->assertSame([], FinanceInvariants::check(static::$db), 'invariants after the round');
        return $results;
    }

    /** @return array{0:int,1:list<string>} number of successes, sorted refusal reasons */
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

    private function ad(): int
    {
        return $this->sid('ad');
    }

    public function testDoubleSpendOfTheLastCredit(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $id = $this->funded('100');
            [$a1, $a2] = [$this->activation($id), $this->activation($id)];
            $r = $this->race($id, [
                ['op' => 'reserve', 'activation' => $a1, 'manager' => $id, 'amount' => '60'],
                ['op' => 'reserve', 'activation' => $a2, 'manager' => $id, 'amount' => '60'],
            ]);
            $this->assertSame([1, ['INSUFFICIENT_AVAILABLE_CREDIT']], self::outcome($r));
            $this->assertSame('40.00', WalletService::view($this->ctx('ad'), $id)->available()->format());
        }
    }

    public function testTwoTransfersFromOneParentCannotOverspend(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $p = $this->funded('100');
            [$c1, $c2] = [$this->agent($p), $this->agent($p)];
            $r = $this->race($p, [
                ['op' => 'transfer', 'actor' => $p, 'from' => $p, 'to' => $c1, 'amount' => '70', 'reference' => self::ref('C1')],
                ['op' => 'transfer', 'actor' => $p, 'from' => $p, 'to' => $c2, 'amount' => '70', 'reference' => self::ref('C2')],
            ]);
            $this->assertSame([1, ['INSUFFICIENT_AVAILABLE_CREDIT']], self::outcome($r));
            $this->assertSame('30.00', $this->balance($p));
            $this->assertSame(1, $this->rowCount('tbl_manager_wallets', 'manager_id IN (?, ?)', [$c1, $c2]), 'the losing destination wallet was never created');
        }
    }

    public function testReservationVersusTransfer(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $p = $this->funded('100');
            $c = $this->agent($p);
            $act = $this->activation($p);
            $r = $this->race($p, [
                ['op' => 'reserve', 'activation' => $act, 'manager' => $p, 'amount' => '70'],
                ['op' => 'transfer', 'actor' => $p, 'from' => $p, 'to' => $c, 'amount' => '70', 'reference' => self::ref()],
            ]);
            $this->assertSame([1, ['INSUFFICIENT_AVAILABLE_CREDIT']], self::outcome($r));
            $this->assertSame('30.00', WalletService::view($this->ctx('ad'), $p)->available()->format());
        }
    }

    public function testReservationVersusWithdrawal(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $id = $this->funded('100');
            $act = $this->activation($id);
            $r = $this->race($id, [
                ['op' => 'reserve', 'activation' => $act, 'manager' => $id, 'amount' => '70'],
                ['op' => 'withdraw', 'actor' => $this->ad(), 'manager' => $id, 'amount' => '70', 'reference' => self::ref()],
            ]);
            $this->assertSame([1, ['INSUFFICIENT_AVAILABLE_CREDIT']], self::outcome($r));
            $this->assertSame('30.00', WalletService::view($this->ctx('ad'), $id)->available()->format());
        }
    }

    public function testReservationVersusDebtLimitReduction(): void
    {
        $orders = [];
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $id = $this->agent();
            WalletService::setDebtLimit($this->ctx('ad'), $id, '100', 'credit line only');
            $act = $this->activation($id);
            $r = $this->race($id, [
                ['op' => 'reserve', 'activation' => $act, 'manager' => $id, 'amount' => '70'],
                ['op' => 'debt_limit', 'actor' => $this->ad(), 'manager' => $id, 'limit' => '50'],
            ]);
            [$ok, $reasons] = self::outcome($r);
            $this->assertSame(1, $ok);
            if ($r[0]['ok']) {
                $this->assertSame(['DEBT_LIMIT_EXPOSURE'], $reasons);
                $this->assertSame(['100.00', '30.00'], [$this->wallet($id)['debt_limit'], WalletService::view($this->ctx('ad'), $id)->available()->format()]);
            } else {
                $this->assertSame(['INSUFFICIENT_AVAILABLE_CREDIT'], $reasons);
                $this->assertSame(['50.00', '50.00'], [$this->wallet($id)['debt_limit'], WalletService::view($this->ctx('ad'), $id)->available()->format()]);
            }
            $orders[$r[0]['ok'] ? 'reserve-first' : 'cut-first'] = true;
        }
        $this->assertNotEmpty($orders);
    }

    /** Tree-locked transfers and a non-tree-locked commit on the same wallets: no deadlock, exact balances. */
    public function testConcurrentOperationsOnTheSameWallets(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $p = $this->funded('100');
            $c = $this->funded('5', $p);
            $res = ReservationService::reserve($this->activation($p), $p, Money::parsePositive('10'));
            $r = $this->race($p, [
                ['op' => 'transfer', 'actor' => $p, 'from' => $p, 'to' => $c, 'amount' => '25', 'reference' => self::ref('S1')],
                ['op' => 'transfer', 'actor' => $p, 'from' => $p, 'to' => $c, 'amount' => '25', 'reference' => self::ref('S2')],
                ['op' => 'transfer', 'actor' => $this->ad(), 'from' => $p, 'to' => $c, 'amount' => '25', 'reference' => self::ref('S3')],
                ['op' => 'commit', 'reservation' => $res->reservationId],
            ]);
            $this->assertSame([4, []], self::outcome($r));
            $this->assertSame(['15.00', '80.00'], [$this->balance($p), $this->balance($c)]);
        }
    }

    public function testCommitVersusDuplicateCommit(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $id = $this->funded('50');
            $res = ReservationService::reserve($this->activation($id), $id, Money::parsePositive('20'));
            $r = $this->race($id, [['op' => 'commit', 'reservation' => $res->reservationId], ['op' => 'commit', 'reservation' => $res->reservationId]]);
            $this->assertSame([2, []], self::outcome($r));
            $this->assertSame([false, true], [min($r[0]['result']['replayed'], $r[1]['result']['replayed']), max($r[0]['result']['replayed'], $r[1]['result']['replayed'])]);
            $this->assertSame($r[0]['result']['committed_ledger_entry_id'], $r[1]['result']['committed_ledger_entry_id']);
            $this->assertSame(1, $this->rowCount('tbl_manager_ledger', "manager_id = ? AND entry_type = 'ACTIVATION_DEBIT'", [$id]));
            $this->assertSame('30.00', $this->balance($id));
        }
    }

    public function testCommitVersusRelease(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $id = $this->funded('50');
            $res = ReservationService::reserve($this->activation($id), $id, Money::parsePositive('20'));
            $r = $this->race($id, [['op' => 'commit', 'reservation' => $res->reservationId], ['op' => 'release', 'reservation' => $res->reservationId]]);
            [$ok, $reasons] = self::outcome($r);
            $this->assertSame(1, $ok);
            $status = static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE id = ?', [$res->reservationId]);
            if ($status === 'COMMITTED') {
                $this->assertSame(['RESERVATION_ALREADY_COMMITTED'], $reasons);
                $this->assertSame('30.00', $this->balance($id));
            } else {
                $this->assertSame(['RELEASED', ['RESERVATION_NOT_ACTIVE']], [$status, $reasons]);
                $this->assertSame('50.00', $this->balance($id));
            }
        }
    }

    public function testTwoRequestsForTheSameActivation(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $id = $this->funded('50');
            $act = $this->activation($id);
            $r = $this->race($id, [
                ['op' => 'reserve', 'activation' => $act, 'manager' => $id, 'amount' => '10'],
                ['op' => 'reserve', 'activation' => $act, 'manager' => $id, 'amount' => '10'],
            ]);
            $this->assertSame([2, []], self::outcome($r));
            $this->assertSame($r[0]['result']['reservation_id'], $r[1]['result']['reservation_id']);
            $act2 = $this->activation($id);
            $r = $this->race($id, [
                ['op' => 'reserve', 'activation' => $act2, 'manager' => $id, 'amount' => '10'],
                ['op' => 'reserve', 'activation' => $act2, 'manager' => $id, 'amount' => '11'],
            ]);
            $this->assertSame([1, ['RESERVATION_CONFLICT']], self::outcome($r));
            $this->assertSame(1, $this->rowCount('tbl_manager_wallet_reservations', 'activation_id = ?', [$act2]));
        }
    }

    public function testSameFundingReferenceTwice(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $id = $this->funded('1');
            $ref = self::ref('DUP');
            $op = ['op' => 'fund', 'actor' => $this->ad(), 'manager' => $id, 'amount' => '10', 'reference' => $ref];
            $r = $this->race($id, [$op, $op]);
            $this->assertSame([2, []], self::outcome($r));
            $this->assertSame([false, true], [min($r[0]['result']['replayed'], $r[1]['result']['replayed']), max($r[0]['result']['replayed'], $r[1]['result']['replayed'])]);
            $this->assertSame(1, $this->rowCount('tbl_manager_transfers', 'reference = ?', [$ref]));
            $this->assertSame('11.00', $this->balance($id));
        }
    }

    public function testDoubleReversal(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $p = $this->funded('100');
            $c = $this->agent($p);
            $t1 = TransferService::transferToChild($this->ctx($p), $p, $c, '10', 'stock', self::ref());
            $t2 = TransferService::transferToChild($this->ctx($p), $p, $c, '10', 'stock', self::ref());
            $same = self::ref('RSAME');
            $r = $this->race($p, [
                ['op' => 'reverse', 'actor' => $this->ad(), 'transfer' => $t1->transferId, 'reference' => self::ref('RA')],
                ['op' => 'reverse', 'actor' => $this->ad(), 'transfer' => $t1->transferId, 'reference' => self::ref('RB')],
                ['op' => 'reverse', 'actor' => $this->ad(), 'transfer' => $t2->transferId, 'reference' => $same],
                ['op' => 'reverse', 'actor' => $this->ad(), 'transfer' => $t2->transferId, 'reference' => $same],
            ]);
            $this->assertSame([1, ['ALREADY_REVERSED']], self::outcome([$r[0], $r[1]]));
            $this->assertSame([2, []], self::outcome([$r[2], $r[3]]));
            $this->assertSame($r[2]['result']['transfer_id'], $r[3]['result']['transfer_id']);
            $this->assertSame(1, $this->rowCount('tbl_manager_transfers', 'reverses_transfer_id = ?', [$t1->transferId]));
            $this->assertSame(1, $this->rowCount('tbl_manager_transfers', 'reverses_transfer_id = ?', [$t2->transferId]));
            $this->assertSame(['100.00', '0.00'], [$this->balance($p), $this->balance($c)]);
        }
    }

    /** A subtree move and a transfer serialize on the accepted WP2 tree lock: never a transfer to a former child. */
    public function testSubtreeMoveVersusTransfer(): void
    {
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $p = $this->funded('100');
            $q = $this->agent();
            $c = $this->agent($p);
            $r = $this->checked(FinanceWorkers::race(static::$db, FinanceWorkers::holdTree(static::$db), [
                ['op' => 'transfer', 'actor' => $this->ad(), 'from' => $p, 'to' => $c, 'amount' => '10', 'reference' => self::ref('MV')],
                ['op' => 'move', 'actor' => $this->ad(), 'node' => $c, 'parent' => $q],
            ]));
            $this->assertTrue($r[1]['ok'], 'the move always succeeds');
            $moveAt = (string) static::$db->value("SELECT MAX(created_at) FROM tbl_reseller_audit_events WHERE event_type = 'tree.move' AND target_user_id = ?", [$c]);
            if ($r[0]['ok']) {
                $at = (string) static::$db->value('SELECT created_at FROM tbl_manager_transfers WHERE id = ?', [$r[0]['result']['transfer_id']]);
                $this->assertLessThan($moveAt, $at, 'the transfer committed while c was still a direct child');
                $this->assertSame('10.00', $this->balance($c));
            } else {
                $this->assertSame('NOT_DIRECT_CHILD', $r[0]['reason']);
                $this->assertNull($this->wallet($c));
            }
            $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree WHERE ancestor_id = ? AND descendant_id = ? AND depth = 1', [$q, $c]));
        }
    }

    /** Free-running stampede (no barrier): six reservations of 30 against 100 -> exactly three. */
    public function testStampedeCannotOverspend(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $id = $this->funded('100');
            $ops = [];
            for ($k = 0; $k < 6; $k++) {
                $ops[] = ['op' => 'reserve', 'activation' => $this->activation($id), 'manager' => $id, 'amount' => '30'];
            }
            $r = $this->checked(FinanceWorkers::stampede(static::$db, $ops));
            $this->assertSame([3, array_fill(0, 3, 'INSUFFICIENT_AVAILABLE_CREDIT')], self::outcome($r));
            $this->assertSame('10.00', WalletService::view($this->ctx('ad'), $id)->available()->format());
        }
    }
}
