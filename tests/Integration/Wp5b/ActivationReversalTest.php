<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Activation\ActivationReversalService;
use Reseller\Finance\TransferService;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * WP5B completed-activation reversal (owner decisions OD-11 .. OD-14, R0007): COMPLETED -> REVERSAL_PENDING ->
 * REVERSED, with the RADIUS prior state restored and verified before the original ledger legs are compensated,
 * the legacy recharge restored and a 'REV-' transaction recorded; every refusal changes nothing (one audit row).
 */
final class ActivationReversalTest extends ActivationTestCase
{
    private const REASON = 'customer complaint, service not delivered';

    /** @return array{0:int,1:int,2:int,3:int} plan, top, leaf, customer */
    private function setupSale(): array
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        return [$plan, $top, $leaf, $this->customer($leaf)];
    }

    /** @return array{0:string,1:int,2:int,3:int,4:int} reference, plan, top, leaf, customer */
    private function completed(): array
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame('COMPLETED', $out['status']);
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
        return [$out['reference'], $plan, $top, $leaf, $c];
    }

    private function reverse(string $reference, string|int $actor = 'ad', mixed $reason = self::REASON): array
    {
        return ActivationReversalService::reverse($this->ctx($actor), $reference, $reason);
    }

    private function id(string $reference): int
    {
        return (int) $this->act($reference)['id'];
    }

    /** Everything a refused reversal must leave unchanged (the audit trail excepted). */
    private function state(string $reference, int $customer): array
    {
        $id = $this->id($reference);
        $u = $this->username($customer);
        return [
            static::$db->rows('SELECT status, error_code, updated_at FROM tbl_reseller_activations WHERE id = ?', [$id]),
            $this->rowCount('tbl_reseller_activation_events', 'activation_id = ?', [$id]),
            static::$db->rows('SELECT id, manager_id, entry_type, amount FROM tbl_manager_ledger ORDER BY id'),
            static::$db->rows('SELECT manager_id, balance FROM tbl_manager_wallets ORDER BY manager_id'),
            static::$db->rows('SELECT * FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id', [$customer]),
            static::$db->rows('SELECT * FROM tbl_transactions WHERE user_id = ? ORDER BY id', [$customer]),
            static::$db->rows('SELECT attribute, op, value FROM radcheck WHERE username = ? ORDER BY id', [$u]),
            static::$db->rows('SELECT attribute, op, value FROM radreply WHERE username = ? ORDER BY id', [$u]),
            static::$db->rows('SELECT groupname, priority FROM radusergroup WHERE username = ? ORDER BY id', [$u]),
        ];
    }

    private function auditCount(): int
    {
        return $this->rowCount('tbl_reseller_audit_events', "event_type = 'activation.reverse'");
    }

    private function assertRefusedUnchanged(string $code, string $reference, int $customer, callable $fn, string $label = ''): void
    {
        $before = $this->state($reference, $customer);
        $audits = $this->auditCount();
        $this->expectExceptionReason($code, $fn, $label);
        $this->assertSame($before, $this->state($reference, $customer), "{$label}: nothing changed");
        $this->assertSame($audits + 1, $this->auditCount(), "{$label}: one audit row for the refusal");
    }

    public function testFullReversalOfANewActivation(): void
    {
        [$ref, $plan, $top, $leaf, $c] = $this->completed();
        $id = $this->id($ref);
        $u = $this->username($c);
        $this->assertNotSame([], $this->radcheck($u));
        $recharge = (int) $this->act($ref)['legacy_recharge_id'];

        $row = $this->reverse($ref);
        $this->assertSame(['REVERSED', 'REVERSED'], [$row['status'], $row['error_code']]);
        // money: the leaf is credited back, the intermediary's commission clawed back, the reservation stays COMMITTED
        $this->assertSame(['1000.00', '1000.00'], [$this->balance($leaf), $this->balance($top)]);
        $this->assertSame('COMMITTED', static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [$id]));
        $rev = static::$db->rows("SELECT l.*, o.entry_type AS orig_type FROM tbl_manager_ledger l JOIN tbl_manager_ledger o ON o.id = l.reverses_entry_id WHERE l.activation_id = ? AND l.entry_type = 'REVERSAL' ORDER BY l.id", [$id]);
        $this->assertSame([[(string) $leaf, 'CREDIT', '15.00', 'ACTIVATION_DEBIT'], [(string) $top, 'DEBIT', '3.00', 'COMMISSION_CREDIT']],
            array_map(static fn(array $r): array => [(string) $r['manager_id'], $r['direction'], $r['amount'], $r['orig_type']], $rev));
        foreach ($rev as $r) {
            $this->assertSame("rev:{$id}:e{$r['reverses_entry_id']}", $r['entry_key']);
            $this->assertSame(['STAFF', (string) $this->sid('ad'), self::REASON], [$r['actor_type'], (string) $r['operator_id'], $r['reason']]);
        }
        // RADIUS: the captured prior state (nothing, for NEW) restored
        $this->assertSame([], $this->radcheck($u));
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM radusergroup WHERE username = ?', [$u]));
        // legacy: the inserted recharge row switched off (nothing deleted), the compensating transaction recorded
        $r = static::$db->rows('SELECT status, method FROM tbl_user_recharges WHERE id = ?', [$recharge])[0];
        $this->assertSame(['off', 'Reseller - Reversed ' . $ref], [$r['status'], $r['method']]);
        $t = static::$db->rows("SELECT * FROM tbl_transactions WHERE invoice = ?", ['REV-' . $ref]);
        $this->assertCount(1, $t);
        $this->assertSame(['-20.00', 'Reseller - Reversal ' . $ref, (string) $c], [$t[0]['price'], $t[0]['method'], (string) $t[0]['user_id']]);
        $this->assertStringStartsWith('Reversal of INV-' . $ref . ': ' . self::REASON, $t[0]['note']);
        $this->assertSame(1, $this->rowCount('tbl_transactions', 'invoice = ?', ['INV-' . $ref]), 'the original invoice is kept');
        // timeline and audit
        $tl = $this->timeline($ref);
        $this->assertSame(['RECONCILIATION:COMPLETED->REVERSAL_PENDING', 'PROVISIONING_RESULT:REVERSAL_PENDING->REVERSAL_PENDING:SUCCESS', 'FINANCIAL:REVERSAL_PENDING->REVERSED'], array_slice($tl, -3));
        $audit = static::$db->rows("SELECT reason, after_json FROM tbl_reseller_audit_events WHERE event_type = 'activation.reverse' AND target_snapshot LIKE ? ORDER BY id", ['%' . $ref . '%']);
        $this->assertSame([self::REASON, self::REASON], array_column($audit, 'reason'), 'started + completed, with the reason');

        // a repeated reversal changes nothing
        $before = $this->state($ref, $c);
        $this->expectExceptionReason('ACTIVATION_STATE_CONFLICT', fn() => $this->reverse($ref));
        $this->assertSame($before, $this->state($ref, $c));
        // and the customer can be activated again (nothing in flight; the off row is reactivated)
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'REACTIVATE')['status']);
    }

    public function testReversalOfARenewalRestoresThePreviousRechargeRowExactly(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $first = static::$db->rows('SELECT * FROM tbl_user_recharges WHERE customer_id = ?', [$c]);
        $radius = $this->radcheck($this->username($c));
        $renew = $this->activate($leaf, $c, $plan, 'RENEW');
        $this->assertSame('COMPLETED', $renew['status']);
        $this->assertNotSame($first, static::$db->rows('SELECT * FROM tbl_user_recharges WHERE customer_id = ?', [$c]), 'the renewal extended the row');
        $this->assertSame('REVERSED', $this->reverse($renew['reference'])['status']);
        $this->assertSame($first, static::$db->rows('SELECT * FROM tbl_user_recharges WHERE customer_id = ?', [$c]), 'the pre-renewal row, column for column');
        $this->assertSame($radius, $this->radcheck($this->username($c)), 'the pre-renewal RADIUS policy');
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)], 'only the renewal was reversed');
    }

    public function testLaterActivationRefusesWithoutAnyWrite(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $first = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'RENEW')['status']);
        $this->assertRefusedUnchanged('REVERSAL_EXTERNAL_STATE_CHANGED', $first['reference'], $c, fn() => $this->reverse($first['reference']), 'later activation');
        $audit = static::$db->value("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'activation.reverse' ORDER BY id DESC LIMIT 1");
        $this->assertStringContainsString('later_activation', (string) $audit);
    }

    public function testExternalRadiusEditRefusesWithoutAnyWrite(): void
    {
        [$ref, , , , $c] = $this->completed();
        static::$db->pdo->prepare("UPDATE radcheck SET value = 'edited-secret' WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$this->username($c)]);
        $this->assertRefusedUnchanged('REVERSAL_EXTERNAL_STATE_CHANGED', $ref, $c, fn() => $this->reverse($ref), 'radius edit');
        $audit = (string) static::$db->value("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'activation.reverse' ORDER BY id DESC LIMIT 1");
        $this->assertStringContainsString('radius:check:Cleartext-Password', $audit, 'the component is named');
        $this->assertStringNotContainsString('edited-secret', $audit, 'never the value');
    }

    public function testLegacyRechargeDriftRefusesWithoutAnyWrite(): void
    {
        [$ref, , , , $c] = $this->completed();
        static::$db->pdo->prepare("UPDATE tbl_user_recharges SET expiration = '2040-01-01' WHERE customer_id = ?")->execute([$c]);
        $this->assertRefusedUnchanged('REVERSAL_EXTERNAL_STATE_CHANGED', $ref, $c, fn() => $this->reverse($ref), 'legacy drift');
    }

    public function testActivationWithoutCapturedContextIsNotReversible(): void
    {
        [$ref, , , , $c] = $this->completed();
        // a COMPLETED activation from before WP5B: its COMPLETED event has no reversal context
        static::$db->pdo->prepare("UPDATE tbl_reseller_activation_events SET metadata = JSON_REMOVE(metadata, '$.reversal_context') WHERE activation_id = ? AND to_state = 'COMPLETED'")->execute([$this->id($ref)]);
        $this->assertRefusedUnchanged('REVERSAL_CONTEXT_NOT_CAPTURED', $ref, $c, fn() => $this->reverse($ref), 'no context');
    }

    public function testFinancialPreflightRefusalWritesNothing(): void
    {
        [$ref, , $top, , $c] = $this->completed();
        // the intermediary spent its commission: its clawback would leave negative available credit
        TransferService::withdraw($this->ctx('ad'), $top, '1003.00', 'provider withdrawal', self::ref('W'));
        $this->assertRefusedUnchanged('REVERSAL_FINANCIAL_REFUSED', $ref, $c, fn() => $this->reverse($ref), 'clawback floor');
        $this->assertSame('COMPLETED', $this->act($ref)['status']);
    }

    public function testUnrestorablePriorSecretIsRefusedBeforeAnyWrite(): void
    {
        [$plan, , $leaf, $c] = $this->setupSale();
        // a previous RADIUS account whose password is not one of the customer's own passwords
        static::$db->pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', 'foreign-secret-x')")->execute([$this->username($c)]);
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame('COMPLETED', $out['status']);
        $this->assertRefusedUnchanged('PRIOR_SECRET_UNRESTORABLE', $out['reference'], $c, fn() => $this->reverse($out['reference']), 'prior secret');
    }

    public function testOnlyAdminAndSuperAdminMayReverseAndAReasonIsRequired(): void
    {
        [$ref, , , $leaf, $c] = $this->completed();
        $before = $this->state($ref, $c);
        $audits = $this->auditCount();
        foreach ([$leaf, 'A1', 'rp'] as $actor) { // the owner Agent, a Sales account, Report
            $this->expectExceptionReason('FORBIDDEN', fn() => $this->reverse($ref, $actor), (string) $actor);
        }
        foreach (['', 'ab', str_repeat('x', 256), ['x'], null] as $reason) {
            $this->expectExceptionReason('INVALID_REASON', fn() => $this->reverse($ref, 'ad', $reason));
        }
        $this->expectExceptionReason('NOT_FOUND', fn() => ActivationReversalService::reverse($this->ctx('ad'), 'ACT-0000000000000000', self::REASON));
        $this->expectExceptionReason('NOT_FOUND', fn() => ActivationReversalService::reverse($this->ctx('ad'), 'x', self::REASON));
        $this->assertSame($before, $this->state($ref, $c));
        $this->assertSame($audits, $this->auditCount(), 'unauthorized or invalid requests leave no reversal audit');
        $this->assertSame('REVERSED', $this->reverse($ref, 'sa')['status'], 'SuperAdmin');
    }

    public function testCrashAfterStartResumesWithTheRadiusCompensation(): void
    {
        [$ref, , $top, $leaf, $c] = $this->completed();
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'reversal.after_start') {
                throw new \RuntimeException('injected: crash after the reversal started');
            }
        };
        try {
            $this->reverse($ref);
            $this->fail('crash expected');
        } catch (\RuntimeException) {
        }
        ActivationFaults::$hook = null;
        $this->assertSame('REVERSAL_PENDING', $this->act($ref)['status']);
        $this->assertNotSame([], $this->radcheck($this->username($c)), 'RADIUS untouched so far');
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
        $this->expectExceptionReason('ACTIVATION_IN_PROGRESS', fn() => $this->activate($leaf, $c, (int) $this->act($ref)['plan_id'], 'RENEW'), 'in flight');
        $this->assertSame('REVERSED', $this->reverse($ref)['status'], 'resumed');
        $this->assertSame(['1000.00', '1000.00'], [$this->balance($leaf), $this->balance($top)]);
        $this->assertSame([], $this->radcheck($this->username($c)));
    }

    public function testCrashAfterTheRestoreRecordsTheEvidenceWithoutASecondWrite(): void
    {
        [$ref, , $top, $leaf, $c] = $this->completed();
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'reversal.after_restore') {
                throw new \RuntimeException('injected: crash after the RADIUS restore');
            }
        };
        try {
            $this->reverse($ref);
            $this->fail('crash expected');
        } catch (\RuntimeException) {
        }
        ActivationFaults::$hook = null;
        $this->assertSame([], $this->radcheck($this->username($c)), 'restored');
        $this->assertFalse(ActivationReversalService::restored(static::$db->pdo, $this->id($ref)), 'but no evidence yet');
        $writes = 0;
        RadiusFaults::$hook = static function (string $p) use (&$writes): void {
            if ($p === 'radius.after_write') {
                $writes++;
            }
        };
        $this->assertSame('REVERSED', $this->reverse($ref)['status']);
        RadiusFaults::$hook = null;
        $this->assertSame(0, $writes, 'the resume reads RADIUS, finds the prior state and does not write again');
        $this->assertSame(['1000.00', '1000.00'], [$this->balance($leaf), $this->balance($top)]);
    }

    public function testFinancialCommitFailureAfterTheRestoreHoldsAndResumesWithoutASecondRadiusWrite(): void
    {
        [$ref, , $top, $leaf, $c] = $this->completed();
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'reversal.after_ledger') {
                throw new \RuntimeException('injected: crash inside the reversal transaction');
            }
        };
        try {
            $this->reverse($ref);
            $this->fail('crash expected');
        } catch (\RuntimeException) {
        }
        ActivationFaults::$hook = null;
        $id = $this->id($ref);
        $this->assertSame('REVERSAL_PENDING', $this->act($ref)['status']);
        $this->assertTrue(ActivationReversalService::restored(static::$db->pdo, $id), 'the RADIUS evidence is durable');
        $this->assertSame(0, $this->rowCount('tbl_manager_ledger', "activation_id = ? AND entry_type = 'REVERSAL'", [$id]), 'no partial ledger');
        $this->assertSame(0, $this->rowCount('tbl_transactions', 'invoice = ?', ['REV-' . $ref]), 'no partial legacy');
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
        $writes = 0;
        RadiusFaults::$hook = static function (string $p) use (&$writes): void {
            if ($p === 'radius.after_write') {
                $writes++;
            }
        };
        $this->assertSame('REVERSED', $this->reverse($ref)['status'], 'phase 3 only');
        RadiusFaults::$hook = null;
        $this->assertSame(0, $writes);
        $this->assertSame(['1000.00', '1000.00'], [$this->balance($leaf), $this->balance($top)]);
        $this->assertSame(2, $this->rowCount('tbl_manager_ledger', "activation_id = ? AND entry_type = 'REVERSAL'", [$id]), 'each leg exactly once');
    }

    public function testFinancialRefusalAfterTheRestoreKeepsReversalPendingForTheOperator(): void
    {
        [$ref, , $top, $leaf, $c] = $this->completed();
        ActivationFaults::$hook = function (string $p) use ($top): void {
            if ($p === 'reversal.after_evidence') {
                ActivationFaults::$hook = null;
                // between the RADIUS restore and the financial commit, the intermediary spends its commission
                TransferService::withdraw($this->ctx('ad'), $top, '1003.00', 'provider withdrawal', self::ref('W'));
            }
        };
        $this->expectExceptionReason('REVERSAL_FINANCIAL_REFUSED', fn() => $this->reverse($ref));
        $act = $this->act($ref);
        $this->assertSame(['REVERSAL_PENDING', 'REVERSAL_FINANCIAL_REFUSED'], [$act['status'], $act['error_code']]);
        $this->assertSame([], $this->radcheck($this->username($c)), 'RADIUS restored (R-4: no service, money settled until the operator acts)');
        $this->assertSame(0, $this->rowCount('tbl_manager_ledger', "activation_id = ? AND entry_type = 'REVERSAL'", [$this->id($ref)]));
        TransferService::fund($this->ctx('ad'), $top, '3.00', 'operator correction', self::ref('F'));
        $this->assertSame('REVERSED', $this->reverse($ref)['status'], 'resumed after the operator funded the clawback');
        $this->assertSame(['1000.00', '0.00'], [$this->balance($leaf), $this->balance($top)]);
    }
}
