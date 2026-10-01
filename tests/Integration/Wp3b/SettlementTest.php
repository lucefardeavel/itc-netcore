<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\ResellerTreeService;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\Money;
use Reseller\Finance\PricingService;
use Reseller\Finance\ReservationService;
use Reseller\Finance\SettlementFailure;
use Reseller\Finance\TransferService;
use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\PricingTestCase;

/**
 * WP3B §13–§17, owner decisions F-1/F-2/F-6: one atomic settlement posts the leaf debit and every
 * positive commission; provider settlement is never a wallet credit; WP3B never creates wallets;
 * reserve checks every deterministic precondition before provisioning; commit re-checks under the
 * final locks and fails atomically with a typed failure.
 */
final class SettlementTest extends PricingTestCase
{
    /** @return array<string,string> manager => balance */
    private function balances(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->balance($id);
        }
        return $out;
    }

    public function testSpecChainSettlesExactlyAndConserves(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$r1, $r2, $r3] = $this->chain($plan, ['12', '15', '17'], '1000');
        $walletsBefore = $this->rowCount('tbl_manager_wallets');
        [$act, $res] = $this->settle($r3, $plan);
        $this->assertSame('COMMITTED', $res->status);
        $this->assertSame(['983.00', '1003.00', '1002.00'], [$this->balance($r3), $this->balance($r1), $this->balance($r2)], 'R3 -17, R1 +3, R2 +2');
        $this->assertSame([$r1 => '3.00', $r2 => '2.00'], $this->commissions($act));
        $debit = static::$db->rows("SELECT * FROM tbl_manager_ledger WHERE activation_id = ? AND entry_type = 'ACTIVATION_DEBIT'", [$act]);
        $this->assertCount(1, $debit);
        $this->assertSame(["res:{$res->reservationId}:commit", '17.00', 'SYSTEM'], [$debit[0]['entry_key'], $debit[0]['amount'], $debit[0]['actor_type']]);
        foreach (static::$db->rows("SELECT * FROM tbl_manager_ledger WHERE activation_id = ? AND entry_type = 'COMMISSION_CREDIT'", [$act]) as $c) {
            $this->assertSame(["com:{$act}:m{$c['manager_id']}", 'CREDIT', $r3], [$c['entry_key'], $c['direction'], (int) $c['counterparty_manager_id']]);
        }
        $this->assertSame('12.00', static::$db->value('SELECT provider_settlement_amount FROM tbl_reseller_activations WHERE id = ?', [$act]));
        $this->assertSame($walletsBefore, $this->rowCount('tbl_manager_wallets'), 'no provider / root wallet, no wallet created');
        // replay: same result, nothing written
        $before = $this->checksums();
        $again = ReservationService::commit($res->reservationId, FinanceActor::system('wp3b-activation'));
        $this->assertTrue($again->replayed);
        $this->assertSame([], DbProbe::changed($before, $this->checksums()));
    }

    public function testOneTwoLevelInheritedEqualAndBaseZeroChains(): void
    {
        $cases = [
            // base, overrides top->leaf, expected leaf debit, expected commissions by chain index
            ['10', ['12'], '12.00', []],
            ['10', [null], '10.00', []],
            ['10', ['12', '15'], '15.00', [0 => '3.00']],
            ['10', ['12', null, '17'], '17.00', [1 => '5.00']],
            ['10', ['12', '12', '12'], '12.00', []],
            ['0', ['5', '8'], '8.00', [0 => '3.00']],
            ['0', [null, '5'], '5.00', [0 => '5.00']],
        ];
        foreach ($cases as [$base, $overrides, $leaf, $commissions]) {
            $plan = $this->plan('100');
            $this->base($plan, $base);
            $ids = $this->chain($plan, $overrides, '100');
            $leafId = end($ids);
            [$act] = $this->settle($leafId, $plan);
            $expected = [];
            foreach ($commissions as $i => $amount) {
                $expected[$ids[$i]] = $amount;
            }
            ksort($expected);
            $this->assertSame($expected, $this->commissions($act), json_encode($overrides));
            $this->assertSame(Money::parsePositive('100')->minus(Money::parsePositive($leaf))->format(), $this->balance($leafId));
            $this->assertSame(0, $this->rowCount('tbl_manager_ledger', "activation_id = ? AND entry_type = 'COMMISSION_CREDIT' AND amount = 0", [$act]), 'no zero-value entry');
        }
    }

    /** F-1: WP3B never creates a wallet; a positive commission recipient without a wallet fails before provisioning. */
    public function testCommissionRecipientMustAlreadyHaveAWallet(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        $top = $this->agent();                      // never funded: no wallet
        PricingService::setChildWholesale($this->ctx('ad'), $top, $plan, '12');
        $mid = $this->agent($top);                  // inherits 12 -> zero margin to top, no wallet needed
        $leaf = $this->funded('100', $mid);
        PricingService::setChildWholesale($this->ctx($mid), $leaf, $plan, '15'); // mid earns 3 and has no wallet
        $act = $this->sealedActivation($leaf, $plan);
        $wallets = $this->rowCount('tbl_manager_wallets');
        $this->refused('COMMISSION_RECIPIENT_NO_WALLET', fn() => $this->reserveSealed($act));
        $this->assertSame($wallets, $this->rowCount('tbl_manager_wallets'), 'no wallet created by a reservation');
        $this->assertNull($this->wallet($mid));
        // an approved WP3A creation path (provider funding) establishes the wallet; then the sale works
        $this->fund($mid, '1');
        $r = $this->reserveSealed($act);
        ReservationService::commit($r->reservationId, FinanceActor::system('wp3b-activation'));
        $this->assertSame([$mid => '3.00'], $this->commissions($act));
        $this->assertNull($this->wallet($top), 'the zero-margin top-level reseller still has no wallet');
        $this->assertSame('4.00', $this->balance($mid));
    }

    public function testRecipientCapacityIsCheckedAtReserveAndAgainAtCommit(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$top, $leaf] = $this->chain($plan, ['12', '15'], '100');
        // reserve: no headroom for the 3.00 commission
        $this->fund($top, '9999999999999899.00'); // 9999999999999999.00 total
        $act = $this->sealedActivation($leaf, $plan);
        $this->refused('COMMISSION_RECIPIENT_CAPACITY', fn() => $this->reserveSealed($act));
        TransferService::withdraw($this->ctx('ad'), $top, '10', 'make headroom', self::ref());
        $r = $this->reserveSealed($act);
        // state changes after reserve: the recipient is funded towards the limit
        $this->fund($top, '9.00');
        $before = $this->checksums();
        try {
            ReservationService::commit($r->reservationId, FinanceActor::system('wp3b-activation'));
            $this->fail('commit must fail on recipient storage headroom');
        } catch (SettlementFailure $e) {
            $this->assertSame([SettlementFailure::CAPACITY, 'COMMISSION_RECIPIENT_CAPACITY', $r->reservationId, $act, SettlementFailure::EFFECTS_NONE],
                [$e->kind, $e->reason, $e->reservationId, $e->activationId, $e->effects]);
        }
        $this->assertSame([], DbProbe::changed($before, $this->checksums()), 'no partial debit, no partial commission');
        $this->assertSame('ACTIVE', static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE id = ?', [$r->reservationId]));
        // administrative fix, then exactly one settlement
        TransferService::withdraw($this->ctx('ad'), $top, '9', 'make headroom', self::ref());
        $ok = ReservationService::commit($r->reservationId, FinanceActor::system('wp3b-activation'));
        $this->assertSame(['COMMITTED', false], [$ok->status, $ok->replayed]);
        $this->assertSame([$top => '3.00'], $this->commissions($act));
    }

    public function testFrozenRecipientIsStillCreditedAndMoveAfterReserveKeepsTheSnapshot(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$r1, $r2, $r3] = $this->chain($plan, ['12', '15', '17'], '100');
        $other = $this->funded('100');
        $act = $this->sealedActivation($r3, $plan);
        $r = $this->reserveSealed($act);
        // after reserve: an intermediary becomes inactive and the leaf moves to another tree
        $this->setStatus($r1, 'Inactive');
        $this->setStatus($r1, 'Active');
        ResellerTreeService::moveSubtree($this->ctx('ad'), $r3, $other);
        $this->setStatus($r2, 'Inactive');
        ReservationService::commit($r->reservationId, FinanceActor::staff($this->ctx('ad')));
        $this->assertSame([$r1 => '3.00', $r2 => '2.00'], $this->commissions($act), 'historical settlement follows the snapshotted chain');
        $this->assertNull(static::$db->rows("SELECT id FROM tbl_manager_ledger WHERE manager_id = ? AND activation_id = ?", [$other, $act])[0] ?? null, 'the new parent earns nothing');
        foreach (static::$db->rows("SELECT * FROM tbl_manager_ledger WHERE activation_id = ?", [$act]) as $e) {
            $this->assertSame(['STAFF', $this->sid('ad')], [$e['actor_type'], (int) $e['operator_id']], 'same actor on every leg');
        }
        $this->setStatus($r2, 'Active');
    }

    public function testStalePartialZeroAndNullSnapshotActivations(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$top, $leaf] = $this->chain($plan, ['12', '15'], '100');
        // stale: prices changed between pricing and reservation
        $act = $this->sealedActivation($leaf, $plan);
        PricingService::setChildWholesale($this->ctx($top), $leaf, $plan, '16');
        $this->refused('PRICING_SNAPSHOT_STALE', fn() => $this->reserveSealed($act));
        // partial snapshot columns are never accepted
        $partial = FinanceTx::run(fn(\PDO $pdo) => $this->insertActivation($pdo, $leaf, $plan, ['wholesale_cost' => '16.00', 'provider_settlement_amount' => '12.00']), true);
        $this->refused('PRICING_SNAPSHOT_INVALID', fn() => ReservationService::reserve($partial, $leaf, Money::parsePositive('16')));
        // a zero wholesale is never reservable (F-3)
        $zero = FinanceTx::run(fn(\PDO $pdo) => $this->insertActivation($pdo, $leaf, $plan, ['wholesale_cost' => '0.00']), true);
        $this->refused('ZERO_WHOLESALE_NOT_SUPPORTED', fn() => ReservationService::reserve($zero, $leaf, Money::parsePositive('1')));
        // WP3A fixture shape (no snapshot): debit only, even for a child reseller
        $legacy = $this->activation($leaf, '7');
        $r = ReservationService::reserve($legacy, $leaf, Money::parsePositive('7'));
        ReservationService::commit($r->reservationId, FinanceActor::system('wp3b-activation'));
        $this->assertSame([], $this->commissions($legacy));
        $this->assertSame('93.00', $this->balance($leaf));
        $this->assertSame('100.00', $this->balance($top));
    }

    public function testCustomerActorAndTamperedSnapshotAtCommit(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$top, $leaf] = $this->chain($plan, ['12', '15'], '100');
        $act = $this->sealedActivation($leaf, $plan);
        $r = $this->reserveSealed($act);
        // tampering after reserve is detected at commit: integrity failure, nothing written
        $orig = static::$db->value('SELECT provider_settlement_amount FROM tbl_reseller_activations WHERE id = ?', [$act]);
        static::$db->exec("UPDATE tbl_reseller_activations SET provider_settlement_amount = '1.00' WHERE id = {$act}");
        $before = $this->checksums();
        try {
            ReservationService::commit($r->reservationId, FinanceActor::system('wp3b-activation'));
            $this->fail('tampered snapshot');
        } catch (SettlementFailure $e) {
            $this->assertSame([SettlementFailure::INTEGRITY, 'PRICING_SNAPSHOT_INVALID'], [$e->kind, $e->reason]);
        }
        $this->assertSame([], DbProbe::changed($before, $this->checksums()));
        static::$db->exec("UPDATE tbl_reseller_activations SET provider_settlement_amount = '{$orig}' WHERE id = {$act}");
        // a customer-initiated activation keeps the customer as actor on every leg
        $customer = FinanceActor::customer($this->customerCtx('cA0'));
        ReservationService::commit($r->reservationId, $customer);
        foreach (static::$db->rows('SELECT * FROM tbl_manager_ledger WHERE activation_id = ?', [$act]) as $e) {
            $this->assertSame(['CUSTOMER', $this->cid('cA0'), null], [$e['actor_type'], (int) $e['actor_customer_id'], $e['operator_id']]);
        }
        $this->assertSame([$top => '3.00'], $this->commissions($act));
    }
}
