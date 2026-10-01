<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\Tx;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\FinanceTestCase;

/**
 * WP3A §11 with the owner's idempotency clarification: reserve / commit / release primitives, no
 * ActivationService, no pricing, no expiry.
 */
final class ReservationTest extends FinanceTestCase
{
    private static function m(string $v): Money
    {
        return Money::parsePositive($v);
    }

    public function testReserveCountsAgainstAvailableWithoutLedger(): void
    {
        $id = $this->funded('100');
        $act = $this->activation($id);
        $r = ReservationService::reserve($act, $id, self::m('60'));
        $this->assertSame(['ACTIVE', false, $act, $id, '60.00', null], [$r->status, $r->replayed, $r->activationId, $r->managerId, $r->amount->format(), $r->committedLedgerEntryId]);
        $this->assertCount(1, $this->ledger($id), 'a reservation moves no value');
        $this->assertSame('100.00', $this->balance($id));
        $this->assertSame('40.00', WalletService::view($this->ctx('ad'), $id)->available()->format());
        $row = static::$db->rows('SELECT * FROM tbl_manager_wallet_reservations WHERE id = ?', [$r->reservationId])[0];
        $this->assertNull($row['expires_at'], 'no expiry is scheduled');
        $this->assertNull($row['resolved_at']);
        // insufficient: available 40
        $over = $this->activation($id);
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => ReservationService::reserve($over, $id, self::m('40.01')));
        ReservationService::reserve($this->activation($id), $id, self::m('40'));
        $this->assertSame('0.00', WalletService::view($this->ctx('ad'), $id)->available()->format());
        // no wallet: no exposure possible
        $none = $this->agent();
        $noneAct = $this->activation($none);
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => ReservationService::reserve($noneAct, $none, self::m('1')));
    }

    /** Owner clarification: the reservation history decides the outcome; never a second exposure row. */
    public function testReserveHistoryStates(): void
    {
        $id = $this->funded('100');
        // ACTIVE + same manager/amount: replay
        $act = $this->activation($id);
        $first = ReservationService::reserve($act, $id, self::m('10'));
        $again = ReservationService::reserve($act, $id, self::m('10.00'));
        $this->assertTrue($again->replayed);
        $this->assertSame($first->reservationId, $again->reservationId);
        // ACTIVE + different amount / manager: conflict
        $this->refused('RESERVATION_CONFLICT', fn() => ReservationService::reserve($act, $id, self::m('11')));
        $other = $this->funded('100');
        $this->refused('ACTIVATION_MISMATCH', fn() => ReservationService::reserve($act, $other, self::m('10')));
        // COMMITTED + same: returned as COMMITTED, no new exposure
        ReservationService::commit($first->reservationId, FinanceActor::system('activation-service'));
        $before = $this->checksums();
        $committed = ReservationService::reserve($act, $id, self::m('10'));
        $this->assertSame(['COMMITTED', true, $first->reservationId], [$committed->status, $committed->replayed, $committed->reservationId]);
        $this->assertNotNull($committed->committedLedgerEntryId);
        $this->assertSame($before, $this->checksums());
        $this->refused('RESERVATION_CONFLICT', fn() => ReservationService::reserve($act, $id, self::m('9')));
        // RELEASED: resolved, no re-reservation in WP3A (P6)
        $act2 = $this->activation($id);
        $r2 = ReservationService::reserve($act2, $id, self::m('5'));
        ReservationService::release($r2->reservationId, FinanceActor::system('activation-service'), 'confirmed failure');
        $this->refused('RESERVATION_RESOLVED', fn() => ReservationService::reserve($act2, $id, self::m('5')));
        $this->refused('RESERVATION_CONFLICT', fn() => ReservationService::reserve($act2, $id, self::m('6')));
        // EXPIRED (only a later WP may write it; fixture row): resolved, terminal, not exposure
        $act3 = $this->activation($id);
        static::$db->pdo->prepare("INSERT INTO tbl_manager_wallet_reservations (manager_id, activation_id, amount, status, created_at, resolved_at) VALUES (?, ?, '7.00', 'EXPIRED', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")->execute([$id, $act3]);
        $this->refused('RESERVATION_RESOLVED', fn() => ReservationService::reserve($act3, $id, self::m('7')));
        $this->assertSame(1, $this->rowCount('tbl_manager_wallet_reservations', 'activation_id = ?', [$act3]));
        $this->assertSame(1, $this->rowCount('tbl_manager_wallet_reservations', 'activation_id = ?', [$act]));
    }

    public function testReserveValidatesTheActivationAndOwner(): void
    {
        $id = $this->funded('100');
        $this->refused('NOT_FOUND', fn() => ReservationService::reserve(99999999, $id, self::m('1')));
        $priced = $this->activation($id, '12.50');
        $this->refused('ACTIVATION_MISMATCH', fn() => ReservationService::reserve($priced, $id, self::m('12.49')));
        $ok = ReservationService::reserve($priced, $id, self::m('12.50'));
        $this->assertSame('ACTIVE', $ok->status);
        // frozen / inactive owner: no new exposure (P4)
        $p = $this->funded('100');
        $c = $this->funded('100', $p);
        [$ap, $ac, $as] = [$this->activation($p), $this->activation($c), $this->activation($this->sid('A1'))];
        $this->setStatus($p, 'Inactive');
        $this->refused('WALLET_OWNER_FROZEN', fn() => ReservationService::reserve($ap, $p, self::m('1')));
        $this->refused('WALLET_OWNER_FROZEN', fn() => ReservationService::reserve($ac, $c, self::m('1')));
        $this->setStatus($p, 'Active');
        $this->refused('WALLET_OWNER_INVALID', fn() => ReservationService::reserve($as, $this->sid('A1'), self::m('1')));
    }

    /** reserveInTx requires the caller's transaction AND this connection's tree lock. */
    public function testInTxVariantRequiresTheTreeLock(): void
    {
        $id = $this->funded('10');
        $act = $this->activation($id);
        try {
            Tx::run(fn(\PDO $pdo) => ReservationService::reserveInTx($pdo, $act, $id, self::m('1')));
            $this->fail('reserveInTx without the tree lock');
        } catch (\Reseller\Domain\DomainError $e) {
            $this->assertSame('LOCK_REQUIRED', $e->reason);
        }
        try {
            ReservationService::commitInTx(static::$svc, 1, FinanceActor::system('x-test'));
            $this->fail('commitInTx outside a transaction');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $r = Tx::withTreeLock(fn(\PDO $pdo) => ReservationService::reserveInTx($pdo, $act, $id, self::m('1')));
        $this->assertSame('ACTIVE', $r->status);
        $this->assertSame('nuxr_tree_' . substr(sha1(static::$db->name), 0, 20), FinanceTx::treeLockName(static::$svc), 'same lock as Tx::withTreeLock');
    }

    public function testCommitExactlyOnceWithoutRewritingTheActor(): void
    {
        $id = $this->funded('100');
        $r = ReservationService::reserve($this->activation($id), $id, self::m('33.33'));
        $staff = FinanceActor::staff($this->ctx('ad'));
        $c = ReservationService::commit($r->reservationId, $staff);
        $this->assertSame(['COMMITTED', false], [$c->status, $c->replayed]);
        $e = static::$db->rows('SELECT * FROM tbl_manager_ledger WHERE id = ?', [$c->committedLedgerEntryId])[0];
        $this->assertSame(['ACTIVATION_DEBIT', 'DEBIT', '33.33', '100.00', '66.67', "res:{$r->reservationId}:commit", 'STAFF', $this->sid('ad')],
            [$e['entry_type'], $e['direction'], $e['amount'], $e['balance_before'], $e['balance_after'], $e['entry_key'], $e['actor_type'], (int) $e['operator_id']]);
        $this->assertSame($r->activationId, (int) $e['activation_id']);
        $this->assertSame('66.67', WalletService::view($this->ctx('ad'), $id)->available()->format(), 'commit converts exposure into a debit: available unchanged');
        $before = $this->checksums();
        $again = ReservationService::commit($r->reservationId, FinanceActor::system('another-caller'));
        $this->assertSame([true, $c->committedLedgerEntryId], [$again->replayed, $again->committedLedgerEntryId]);
        $this->assertSame($before, $this->checksums(), 'no second debit, no actor rewrite');
        $this->assertSame('STAFF', static::$db->value('SELECT actor_type FROM tbl_manager_ledger WHERE id = ?', [$c->committedLedgerEntryId]));
        // commit after release is refused
        $r2 = ReservationService::reserve($this->activation($id), $id, self::m('1'));
        ReservationService::release($r2->reservationId, FinanceActor::system('activation-service'), 'confirmed unused');
        $this->refused('RESERVATION_NOT_ACTIVE', fn() => ReservationService::commit($r2->reservationId, $staff));
        $this->refused('NOT_FOUND', fn() => ReservationService::commit(99999999, $staff));
    }

    /** Owner correction: CUSTOMER actor identity preserved; it may differ from the activation's target customer. */
    public function testCustomerActorIsPreservedAndMayDifferFromTheTarget(): void
    {
        $id = $this->funded('50');
        $act = $this->activation($id, null, 'cA0');
        $r = ReservationService::reserve($act, $id, self::m('5'));
        $payer = FinanceActor::customer($this->customerCtx('cA1', 'API'));
        $c = ReservationService::commit($r->reservationId, $payer);
        $e = static::$db->rows('SELECT * FROM tbl_manager_ledger WHERE id = ?', [$c->committedLedgerEntryId])[0];
        $this->assertSame(['CUSTOMER', $this->cid('cA1'), null], [$e['actor_type'], (int) $e['actor_customer_id'], $e['operator_id']]);
        $this->assertSame($this->cid('cA0'), (int) static::$db->value('SELECT customer_id FROM tbl_reseller_activations WHERE id = ?', [$act]));
        $this->assertNotSame((int) $e['actor_customer_id'], $this->cid('cA0'));
        // CRON and SYSTEM are explicit; never API
        $r2 = ReservationService::reserve($this->activation($id), $id, self::m('1'));
        $c2 = ReservationService::commit($r2->reservationId, FinanceActor::cron('autorenew'));
        $this->assertSame('CRON', static::$db->value('SELECT actor_type FROM tbl_manager_ledger WHERE id = ?', [$c2->committedLedgerEntryId]));
        $this->assertSame(0, $this->rowCount('tbl_manager_ledger', "actor_type = 'API'"));
    }

    public function testReleaseIdempotencyAndConflicts(): void
    {
        $id = $this->funded('100');
        $r = ReservationService::reserve($this->activation($id), $id, self::m('25'));
        $ad = FinanceActor::staff($this->ctx('ad'));
        $rel = ReservationService::release($r->reservationId, $ad, 'provisioning confirmed failed');
        $this->assertSame(['RELEASED', false], [$rel->status, $rel->replayed]);
        $row = static::$db->rows('SELECT * FROM tbl_manager_wallet_reservations WHERE id = ?', [$r->reservationId])[0];
        $this->assertSame([$this->sid('ad'), 'provisioning confirmed failed'], [(int) $row['resolved_by'], $row['resolution_note']]);
        $this->assertNotNull($row['resolved_at']);
        $this->assertSame('100.00', $this->balance($id), 'release moves no value');
        $this->assertCount(1, $this->ledger($id), 'no fake ledger entry');
        $this->assertSame('100.00', WalletService::view($this->ctx('ad'), $id)->available()->format());
        $audit = static::$db->rows("SELECT * FROM tbl_reseller_audit_events WHERE event_type = 'finance.reservation_release' AND target_user_id = ?", [$id]);
        $this->assertCount(1, $audit);
        // same resolver + note: replay; altered note or resolver: conflict
        $this->assertTrue(ReservationService::release($r->reservationId, $ad, 'provisioning confirmed failed')->replayed);
        $this->refused('RESERVATION_CONFLICT', fn() => ReservationService::release($r->reservationId, $ad, 'a different note'));
        $this->refused('RESERVATION_CONFLICT', fn() => ReservationService::release($r->reservationId, FinanceActor::staff($this->ctx('sa')), 'provisioning confirmed failed'));
        $this->refused('RESERVATION_CONFLICT', fn() => ReservationService::release($r->reservationId, FinanceActor::system('activation-service'), 'provisioning confirmed failed'));
        // COMMITTED and EXPIRED cannot be released; customers never release
        $r2 = ReservationService::reserve($this->activation($id), $id, self::m('1'));
        ReservationService::commit($r2->reservationId, $ad);
        $this->refused('RESERVATION_ALREADY_COMMITTED', fn() => ReservationService::release($r2->reservationId, $ad, 'too late'));
        $act3 = $this->activation($id);
        static::$db->pdo->prepare("INSERT INTO tbl_manager_wallet_reservations (manager_id, activation_id, amount, status, created_at, resolved_at) VALUES (?, ?, '2.00', 'EXPIRED', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")->execute([$id, $act3]);
        $expired = (int) static::$db->value('SELECT id FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [$act3]);
        $this->refused('RESERVATION_NOT_ACTIVE', fn() => ReservationService::release($expired, $ad, 'expired'));
        $r4 = ReservationService::reserve($this->activation($id), $id, self::m('1'));
        $this->refused('INVALID_ACTOR', fn() => ReservationService::release($r4->reservationId, FinanceActor::customer($this->customerCtx('cA1')), 'customer release'));
        $this->refused('INVALID_REASON', fn() => ReservationService::release($r4->reservationId, $ad, ''));
    }

    /** Commit/release settle existing exposure and stay possible for a frozen reseller (P4). */
    public function testCommitAndReleaseAllowedForAFrozenOwner(): void
    {
        $p = $this->funded('100');
        $c = $this->funded('100', $p);
        $rc = ReservationService::reserve($this->activation($c), $c, self::m('10'));
        $rr = ReservationService::reserve($this->activation($c), $c, self::m('20'));
        $this->setStatus($p, 'Inactive'); // c is frozen through its ancestor
        try {
            $this->assertSame('COMMITTED', ReservationService::commit($rc->reservationId, FinanceActor::system('activation-service'))->status);
            $this->assertSame('RELEASED', ReservationService::release($rr->reservationId, FinanceActor::system('activation-service'), 'confirmed unused')->status);
            $this->assertSame('90.00', $this->balance($c));
        } finally {
            $this->setStatus($p, 'Active');
        }
    }

    /** No blind expiry: a long-lived ACTIVE reservation stays ACTIVE; WP3A has no expiry primitive. */
    public function testNoBlindExpiry(): void
    {
        $id = $this->funded('10');
        $r = ReservationService::reserve($this->activation($id), $id, self::m('5'));
        static::$db->pdo->prepare('UPDATE tbl_manager_wallet_reservations SET created_at = ? WHERE id = ?')->execute(['2020-01-01 00:00:00.000000', $r->reservationId]);
        // any other finance activity on the wallet never resolves it
        ReservationService::reserve($this->activation($id), $id, self::m('1'));
        \Reseller\Finance\TransferService::fund($this->ctx('ad'), $id, '1', 'activity', self::ref());
        $this->assertSame('ACTIVE', static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE id = ?', [$r->reservationId]));
        $methods = array_map(static fn(\ReflectionMethod $m) => strtolower($m->getName()), (new \ReflectionClass(ReservationService::class))->getMethods());
        foreach ($methods as $m) {
            $this->assertStringNotContainsString('expire', $m);
        }
    }
}
