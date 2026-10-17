<?php
namespace Reseller\Tests\Integration\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationFaults;
use Reseller\Activation\ReconciliationService;
use Reseller\Finance\Money;
use Reseller\Finance\TransferService;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\StubNas;

/**
 * Failure injection at every boundary (D017; 18_BUSINESS_RULES failure semantics; WP4 §10; A3, A4):
 *  - confirmed RADIUS failure -> FAILED, reservation released, nothing charged, RADIUS unchanged;
 *  - retryable failure -> RETRY_PENDING -> identical retry (bounded);
 *  - UNKNOWN / crash in flight -> RECONCILIATION_REQUIRED, reservation kept, never retried blindly;
 *  - verified provisioning + settlement failure -> service active but the reservation stays ACTIVE (never free),
 *    then reconciliation settles it exactly once;
 *  - rollback restores the previous RADIUS state; cancel before provisioning.
 */
final class ActivationFailureTest extends ActivationTestCase
{
    private function system(): ActivationActor
    {
        return ActivationActor::system('wp5a-test');
    }

    private static function failAt(string $point, ?\Throwable $e = null): void
    {
        $hook = static function (string $p) use ($point, $e): void {
            if ($p === $point) {
                throw $e ?? new \RuntimeException('injected at ' . $point);
            }
        };
        if (str_starts_with($point, 'radius.')) {
            RadiusFaults::$hook = \Closure::fromCallable($hook);
        } else {
            ActivationFaults::$hook = \Closure::fromCallable($hook);
        }
    }

    /** @return array{0:int,1:int,2:int,3:int} plan, top, leaf, customer */
    private function setupSale(): array
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        return [$plan, $top, $leaf, $this->customer($leaf)];
    }

    private function noMoneyMoved(int $top, int $leaf): void
    {
        $this->assertSame(['1000.00', '1000.00'], [$this->balance($top), $this->balance($leaf)], 'nothing charged, no commission');
    }

    public function testConfirmedRadiusFailureReleasesAndChargesNothing(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        self::failAt('radius.after_write');
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame(['FAILED', 'RADIUS_ERROR'], [$out['status'], $out['error_code']]);
        $this->noMoneyMoved($top, $leaf);
        $this->assertSame([], $this->radcheck($this->username($c)), 'RADIUS unchanged (rolled back)');
        $this->assertSame(0, $this->rowCount('tbl_user_recharges', 'customer_id = ?', [$c]));
        $this->assertSame(0, $this->rowCount('tbl_transactions', 'user_id = ?', [$c]));
        $this->assertSame('RELEASED', static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [(int) $this->act($out['reference'])['id']]));
    }

    public function testRetryableFailureIsRetriedIdenticallyThenSucceeds(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $calls = 0;
        RadiusFaults::$hook = static function (string $p) use (&$calls): void {
            if ($p === 'radius.after_write' && ++$calls === 2) { // the user policy write (the plan group was the first)
                $e = new \PDOException('Deadlock found');
                $e->errorInfo = ['40001', 1213, 'Deadlock found'];
                throw $e;
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame('COMPLETED', $out['status']);
        $this->assertContains('PROVISIONING_RESULT:PROVISIONING->RETRY_PENDING:FAILED', $this->timeline($out['reference']));
        $this->assertContains('STATE_TRANSITION:RETRY_PENDING->PROVISIONING', $this->timeline($out['reference']));
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)], 'charged exactly once');
    }

    public function testRetriesAreBounded(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $e = new \PDOException('Lock wait timeout');
        $e->errorInfo = ['HY000', 1205, 'Lock wait timeout'];
        self::failAt('radius.after_write', $e);
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame(['FAILED', 'PROVISION_RETRIES_EXHAUSTED'], [$out['status'], $out['error_code']]);
        $this->assertSame(3, count(array_filter($this->timeline($out['reference']), static fn($t) => str_ends_with($t, '->PROVISIONING'))), 'one attempt + 2 identical retries');
        $this->noMoneyMoved($top, $leaf);
    }

    public function testUnknownKeepsTheReservationAndReconciliationSettlesOnce(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $calls = 0;
        RadiusFaults::$hook = static function (string $p) use (&$calls): void {
            if ($p === 'radius.commit_lost' && ++$calls === 2) {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame(['RECONCILIATION_REQUIRED', 'RADIUS_COMMIT_UNKNOWN'], [$out['status'], $out['error_code']]);
        $this->noMoneyMoved($top, $leaf);
        $this->assertSame('ACTIVE', static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [(int) $this->act($out['reference'])['id']]), 'UNKNOWN: never released');
        $this->assertSame(['1000.00', '15.00'], [$this->balance($leaf), $this->reserved($leaf)], 'the funds stay reserved');
        // the same request again is a replay, not a second attempt
        RadiusFaults::$hook = null;
        $row = ReconciliationService::reconcile($this->system(), $out['reference']);
        $this->assertSame('COMPLETED', $row['status'], 'RADIUS shows the policy: settle');
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
        $this->expectExceptionReason('ACTIVATION_STATE_CONFLICT', fn() => ReconciliationService::reconcile($this->system(), $out['reference']));
    }

    public function testCrashBeforeTheRadiusCallReconcilesToFailed(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        self::failAt('activation.before_provision');
        try {
            $this->activate($leaf, $c, $plan, 'NEW');
            $this->fail('crash expected');
        } catch (\RuntimeException) {
        }
        ActivationFaults::$hook = null;
        $ref = (string) static::$db->value('SELECT reference FROM tbl_reseller_activations WHERE customer_id = ?', [$c]);
        $this->assertSame('PROVISIONING', $this->act($ref)['status'], 'stopped in flight');
        $this->expectExceptionReason('ACTIVATION_IN_PROGRESS', fn() => ReconciliationService::reconcile($this->system(), $ref), 'a fresh in-flight activation is not raced');
        $this->expectExceptionReason('ACTIVATION_IN_PROGRESS', fn() => $this->activate($leaf, $c, $plan, 'NEW'), 'no second activation while one is open');
        $row = ReconciliationService::reconcile($this->system(), $ref, 0);
        $this->assertSame(['FAILED', 'NOT_APPLIED'], [$row['status'], $row['error_code']], 'RADIUS does not show it: release');
        $this->noMoneyMoved($top, $leaf);
    }

    public function testCrashAfterTheRadiusCallReconcilesToCompleted(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        self::failAt('activation.after_provision');
        try {
            $this->activate($leaf, $c, $plan, 'NEW');
            $this->fail('crash expected');
        } catch (\RuntimeException) {
        }
        ActivationFaults::$hook = null;
        $ref = (string) static::$db->value('SELECT reference FROM tbl_reseller_activations WHERE customer_id = ?', [$c]);
        $row = ReconciliationService::reconcile($this->system(), $ref, 0);
        $this->assertSame('COMPLETED', $row['status']);
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
    }

    /** Verified provisioning + settlement failure: active service with funds reserved (never free), settled once later. */
    public function testSettlementFailureNeverBecomesFreeService(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        self::failAt('activation.after_settlement');
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame('RECONCILIATION_REQUIRED', $out['status']);
        $this->assertSame('SETTLEMENT_UNKNOWN', $out['error_code']);
        $this->assertNotSame([], $this->radcheck($this->username($c)), 'service provisioned');
        $this->noMoneyMoved($top, $leaf);
        $this->assertSame(['1000.00', '15.00'], [$this->balance($leaf), $this->reserved($leaf)], 'but the funds are reserved: not free');
        $this->assertSame(0, $this->rowCount('tbl_transactions', 'user_id = ?', [$c]), 'the legacy rows rolled back with the settlement');
        ActivationFaults::$hook = null;
        $this->assertSame('COMPLETED', ReconciliationService::reconcile($this->system(), $out['reference'])['status']);
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)], 'exactly one debit and one commission');
        $this->assertSame(1, $this->rowCount('tbl_transactions', 'user_id = ?', [$c]));
    }

    public function testCapacityFailureNeedsAnAdministrativeFix(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        // the commission recipient fills up between reserve and commit (WP3B F-6)
        ActivationFaults::$hook = function (string $p) use ($top): void {
            if ($p === 'activation.before_commit') {
                ActivationFaults::$hook = null;
                TransferService::fund($this->ctx('ad'), $top, Money::ofMinor(Money::STORAGE_MAX_MINOR)->minus(Money::parsePositive('1001'))->format(), 'fill up', self::ref('F'));
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame(['RECONCILIATION_REQUIRED', 'SETTLEMENT_CAPACITY'], [$out['status'], $out['error_code']]);
        $again = ReconciliationService::reconcile($this->system(), $out['reference']);
        $this->assertSame(['RECONCILIATION_REQUIRED', 'SETTLEMENT_CAPACITY'], [$again['status'], $again['error_code']], 'still no room: unchanged, still reserved');
        TransferService::withdraw($this->ctx('ad'), $top, '10', 'make room', self::ref('W'));
        $this->assertSame('COMPLETED', ReconciliationService::reconcile($this->system(), $out['reference'])['status']);
        $this->assertSame('985.00', $this->balance($leaf));
    }

    /** WP3B F-6: whatever the settlement unit raises is classified; the service is never free and settles once. */
    public function testSettlementFailuresAreClassified(): void
    {
        $deadlock = static function (): \PDOException {
            $e = new \PDOException('Deadlock found');
            $e->errorInfo = ['40001', 1213, 'Deadlock found'];
            return $e;
        };
        // one deadlock: FinanceTx retries the whole unit, the activation completes in the same request
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $calls = 0;
        ActivationFaults::$hook = static function (string $p) use (&$calls, $deadlock): void {
            if ($p === 'activation.after_settlement' && ++$calls === 1) {
                throw $deadlock();
            }
        };
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)], 'retried unit settled exactly once');
        foreach (['SETTLEMENT_TRANSIENT' => static fn() => throw $deadlock(), 'SETTLEMENT_INTEGRITY' => static fn() => throw new \LogicException('injected integrity fault')] as $code => $fault) {
            [$plan, $top, $leaf, $c] = $this->setupSale();
            ActivationFaults::$hook = static function (string $p) use ($fault): void {
                if ($p === 'activation.after_settlement') {
                    $fault();
                }
            };
            $out = $this->activate($leaf, $c, $plan, 'NEW');
            $this->assertSame(['RECONCILIATION_REQUIRED', $code], [$out['status'], $out['error_code']], $code);
            $this->assertSame(['1000.00', '15.00'], [$this->balance($leaf), $this->reserved($leaf)], "{$code}: nothing charged, funds reserved");
            $this->assertSame(0, $this->rowCount('tbl_transactions', 'user_id = ?', [$c]));
            ActivationFaults::$hook = null;
            $this->assertSame('COMPLETED', ReconciliationService::reconcile($this->system(), $out['reference'])['status']);
            $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)], "{$code}: settled once by reconciliation");
        }
    }

    /** The read-back after provisioning disagrees: never settled or released blindly; settled once RADIUS confirms. */
    public function testVerifyMismatchKeepsTheFundsReservedUntilReconciled(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $u = $this->username($c);
        $original = null;
        ActivationFaults::$hook = static function (string $p) use ($u, &$original): void {
            if ($p === 'activation.after_provision') {
                ActivationFaults::$hook = null;
                $original = (string) static::$db->value("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Expiration'", [$u]);
                static::$db->pdo->prepare("UPDATE radcheck SET value = '01 Jan 2020 00:00:00' WHERE username = ? AND attribute = 'Expiration'")->execute([$u]);
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame(['RECONCILIATION_REQUIRED', 'VERIFY_MISMATCH'], [$out['status'], $out['error_code']]);
        $this->assertContains('VERIFICATION_RESULT:VERIFYING->RECONCILIATION_REQUIRED:UNKNOWN', $this->timeline($out['reference']));
        $this->noMoneyMoved($top, $leaf);
        $this->assertSame(['1000.00', '15.00'], [$this->balance($leaf), $this->reserved($leaf)], 'not released: the service may be live');
        $this->assertSame(0, $this->rowCount('tbl_user_recharges', 'customer_id = ?', [$c]));
        // RADIUS later shows the activation's policy (e.g. a transient foreign write was corrected): settle exactly once
        static::$db->pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Expiration'")->execute([$original, $u]);
        $this->assertSame('COMPLETED', ReconciliationService::reconcile($this->system(), $out['reference'])['status']);
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
    }

    /** CHANGE_PLAN ends the open sessions best-effort: a silent NAS never blocks or fails the activation; the result is recorded. */
    public function testDisconnectIsBestEffortAndRecorded(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $old = $this->radiusPlan('20');
        $this->recharge($c, $old, '2031-03-10', '12:00:00');
        $u = $this->username($c);
        $secret = 'wp5a-coa-' . bin2hex(random_bytes(4));
        $stub = new StubNas($secret, 'silent');
        $nas = static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'wp5a-stub', 'type' => 'other', 'ports' => $stub->port, 'secret' => $secret, 'description' => 'wp5a']);
        $acctId = static::$db->insertRow('radacct', [
            'acctsessionid' => 'wp5a-open', 'acctuniqueid' => substr(md5($u . 'wp5a-open'), 0, 32), 'username' => $u, 'nasipaddress' => '127.0.0.1',
            'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 600), 'acctstoptime' => null, 'acctsessiontime' => 60, 'acctinputoctets' => 0, 'acctoutputoctets' => 0,
            'framedipaddress' => '10.0.0.9', 'calledstationid' => '', 'callingstationid' => '', 'acctterminatecause' => '', 'acctauthentic' => '',
            'connectinfo_start' => '', 'connectinfo_stop' => '', 'servicetype' => '', 'framedprotocol' => '', 'nasporttype' => '', 'realm' => '',
        ]);
        try {
            $out = $this->activate($leaf, $c, $plan, 'CHANGE_PLAN');
            $got = $stub->received();
        } finally {
            static::$db->exec('DELETE FROM nas WHERE id = ' . (int) $nas);
        }
        $this->assertSame('COMPLETED', $out['status'], 'a silent NAS does not block the activation');
        $this->assertCount(3, $got, 'one Disconnect-Request and two identical retransmissions, never a new request');
        $note = static::$db->value("SELECT e.metadata FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ? AND e.event_type = 'ANNOTATION'", [$out['reference']]);
        // V1 / owner RENEWAL_OPTION_B_APPROVED: the annotation also records, per session, whether its end was confirmed
        // (additive: the 'disconnect' outcome list is unchanged)
        $this->assertSame(['disconnect' => ['UNKNOWN:COA_NO_RESPONSE'], 'sessions' => [['radacctid' => $acctId, 'acct_session_id' => 'wp5a-open', 'nas' => '127.0.0.1',
            'result' => 'UNKNOWN:COA_NO_RESPONSE', 'state' => 'COA_NO_RESPONSE', 'confirmed' => false]], 'confirmed' => false], json_decode((string) $note, true), 'the outcome is on the timeline');
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
    }

    /** Rollback restores the RADIUS state captured before provisioning (here a real previous service) and only then releases. */
    public function testRollbackRestoresThePreviousService(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status'], 'the previous service');
        $u = $this->username($c);
        $previous = $this->radcheck($u);
        self::failAt('activation.after_settlement');
        $out = $this->activate($leaf, $c, $plan, 'RENEW');
        $this->assertNotSame($previous['Expiration'], $this->radcheck($u)['Expiration'], 'the renewal reached RADIUS');
        ActivationFaults::$hook = null;
        $row = ReconciliationService::rollback($this->system(), $out['reference']);
        $this->assertSame('ROLLED_BACK', $row['status']);
        $this->assertSame($previous, $this->radcheck($u), 'the captured previous state is back, password included');
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)], 'only the first activation was charged');
        $this->assertSame('0.00', $this->reserved($leaf), 'the renewal reservation was released');
        $this->assertStringContainsString('"password":"restored"', (string) static::$db->value("SELECT e.metadata FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ? AND e.to_state = 'ROLLED_BACK'", [$out['reference']]));
    }

    /** A NEW activation rolled back: the captured prior state was empty, so the subscriber's managed rows are removed. */
    public function testRollbackOfANewServiceRestoresTheEmptyPriorState(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        self::failAt('activation.after_settlement');
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        ActivationFaults::$hook = null;
        $this->assertNotSame([], $this->radcheck($this->username($c)));
        $this->assertSame('ROLLED_BACK', ReconciliationService::rollback($this->system(), $out['reference'])['status']);
        $this->assertSame([], $this->radcheck($this->username($c)), 'no access remains');
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM radusergroup WHERE username = ?', [$this->username($c)]));
        $this->noMoneyMoved($top, $leaf);
    }

    public function testCancelBeforeProvisioningAndResume(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        self::failAt('activation.before_execute');
        foreach ([1, 2] as $i) {
            try {
                $this->activate($leaf, $c, $plan, 'NEW');
                $this->fail('crash expected');
            } catch (\RuntimeException) {
            }
            $ref = (string) static::$db->value('SELECT reference FROM tbl_reseller_activations WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$c]);
            $this->assertSame('FUNDS_RESERVED', $this->act($ref)['status']);
            if ($i === 1) {
                $this->assertSame('CANCELLED', ReconciliationService::cancel($this->system(), $ref)['status']);
                $this->noMoneyMoved($top, $leaf);
            }
        }
        ActivationFaults::$hook = null;
        $mine = fn(): array => array_values(array_filter(array_map(static fn($r) => ['reference' => $r['reference'], 'status' => $r['status']], ReconciliationService::stale(0)),
            static fn($r) => $r['reference'] === $ref));
        $this->assertSame([['reference' => $ref, 'status' => 'FUNDS_RESERVED']], $mine(), 'stale report lists it');
        $this->assertSame('COMPLETED', \Reseller\Activation\ActivationService::execute((int) $this->act($ref)['id'], $this->system())['status'], 'resume continues');
        $this->assertSame([], $mine());
    }
}
