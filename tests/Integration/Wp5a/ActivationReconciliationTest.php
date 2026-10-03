<?php
namespace Reseller\Tests\Integration\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationFaults;
use Reseller\Activation\ActivationService;
use Reseller\Activation\ReconciliationService;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\TestDatabase;

/**
 * Reconciliation split by the durable activation history (owner fix round 1, scenarios A-I):
 *  - delivery already VERIFIED, then the settlement failed: financial reconciliation only; a later foreign
 *    RADIUS edit never releases the reservation (A, B);
 *  - never verified: the canonical RADIUS projection against the captured target / prior state: exactly the
 *    target -> settle once (C); exactly the prior -> NOT_APPLIED, released (D); anything else ->
 *    EXTERNAL_STATE_AMBIGUOUS: nothing changes, funds held (E, F);
 *  - rollback is an explicit decision: the captured prior state is restored and verified before any release
 *    (G); an uncertain restore keeps the funds held (H); repeated commands are idempotent (I).
 */
final class ActivationReconciliationTest extends ActivationTestCase
{
    private function system(): ActivationActor
    {
        return ActivationActor::system('wp5a-test');
    }

    /** @return array{0:int,1:int,2:int,3:int} plan, top, leaf, customer */
    private function setupSale(): array
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        return [$plan, $top, $leaf, $this->customer($leaf)];
    }

    private function id(string $reference): int
    {
        return (int) $this->act($reference)['id'];
    }

    private function reservation(string $reference): string
    {
        return (string) static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [$this->id($reference)]);
    }

    private function ledgerRows(string $reference): int
    {
        return $this->rowCount('tbl_manager_ledger', 'activation_id = ?', [$this->id($reference)]);
    }

    private function events(string $reference): int
    {
        return $this->rowCount('tbl_reseller_activation_events', 'activation_id = ?', [$this->id($reference)]);
    }

    /** The subscriber's RADIUS rows (all of them, unmanaged included), for "reconcile wrote nothing". */
    private function radiusRows(string $username): array
    {
        return [static::$db->rows('SELECT attribute, op, value FROM radcheck WHERE username = ? ORDER BY id', [$username]),
            static::$db->rows('SELECT attribute, op, value FROM radreply WHERE username = ? ORDER BY id', [$username]),
            static::$db->rows('SELECT groupname, priority FROM radusergroup WHERE username = ? ORDER BY id', [$username])];
    }

    /** Provisioned and verified, then the settlement failed: RECONCILIATION_REQUIRED with durable VERIFIED evidence. */
    private function verifiedThenSettlementFailed(): array
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.after_settlement') {
                throw new \RuntimeException('injected: settlement interrupted');
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        ActivationFaults::$hook = null;
        $this->assertSame(['RECONCILIATION_REQUIRED', 'SETTLEMENT_UNKNOWN'], [$out['status'], $out['error_code']]);
        $this->assertContains('VERIFICATION_RESULT:VERIFYING->VERIFIED:SUCCESS', $this->timeline($out['reference']), 'durable evidence');
        return [$out['reference'], $top, $leaf, $c];
    }

    /** The subscriber write committed but its acknowledgement was lost: never verified, outcome unknown. */
    private function unknownProvisioning(): array
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        $calls = 0;
        RadiusFaults::$hook = static function (string $p) use (&$calls): void {
            if ($p === 'radius.commit_lost' && ++$calls === 2) { // the subscriber policy write (the plan group was the first)
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        RadiusFaults::$hook = null;
        $this->assertSame(['RECONCILIATION_REQUIRED', 'RADIUS_COMMIT_UNKNOWN'], [$out['status'], $out['error_code']]);
        $this->assertNotContains('VERIFIED', array_column(static::$db->rows('SELECT to_state FROM tbl_reseller_activation_events WHERE activation_id = ?', [$this->id($out['reference'])]), 'to_state'));
        return [$out['reference'], $top, $leaf, $c];
    }

    private function foreignExpiry(int $customer): void
    {
        static::$db->pdo->prepare("UPDATE radcheck SET value = '31 Dec 2035 23:59:59' WHERE username = ? AND attribute = 'Expiration'")->execute([$this->username($customer)]);
    }

    private function foreignGroup(int $customer): void
    {
        static::$db->pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, 'foreign_group', 2)")->execute([$this->username($customer)]);
    }

    /** A, B: a foreign edit after VERIFIED never releases the reservation; the settlement is retried. */
    public function testVerifiedDeliveryIsReconciledFinanciallyDespiteForeignEdits(): void
    {
        foreach (['expiry' => fn(int $c) => $this->foreignExpiry($c), 'group' => fn(int $c) => $this->foreignGroup($c)] as $case => $edit) {
            [$ref, $top, $leaf, $c] = $this->verifiedThenSettlementFailed();
            $edit($c);
            $radius = $this->radiusRows($this->username($c));
            $row = ReconciliationService::reconcile($this->system(), $ref);
            $this->assertSame('COMPLETED', $row['status'], "{$case}: the verified delivery is settled, not classified NOT_APPLIED");
            $this->assertSame('COMMITTED', $this->reservation($ref), "{$case}: never released");
            $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)], "{$case}: exactly one debit and one commission");
            $this->assertSame($radius, $this->radiusRows($this->username($c)), "{$case}: reconciliation does not touch RADIUS");
            $this->assertContains('RECONCILIATION:RECONCILIATION_REQUIRED->VERIFIED', $this->timeline($ref));
            $this->assertStringContainsString('"mode":"FINANCIAL"', (string) static::$db->value("SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND event_type = 'RECONCILIATION' AND to_state = 'VERIFIED'", [$this->id($ref)]));
        }
    }

    /** A, I: while the settlement keeps failing the reservation stays ACTIVE, with no partial ledger result. */
    public function testFinancialReconciliationThatStillFailsKeepsTheFundsHeld(): void
    {
        [$ref, $top, $leaf, $c] = $this->verifiedThenSettlementFailed();
        $this->foreignExpiry($c);
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.after_settlement') {
                throw new \LogicException('injected: still failing');
            }
        };
        foreach ([1, 2] as $attempt) {
            $row = ReconciliationService::reconcile($this->system(), $ref);
            $this->assertSame(['RECONCILIATION_REQUIRED', 'SETTLEMENT_INTEGRITY'], [$row['status'], $row['error_code']], "attempt {$attempt}");
            $this->assertSame('ACTIVE', $this->reservation($ref));
            $this->assertSame(0, $this->ledgerRows($ref), 'no partial ledger result');
            $this->assertSame(['1000.00', '15.00'], [$this->balance($leaf), $this->reserved($leaf)]);
        }
        ActivationFaults::$hook = null;
        $this->assertSame('COMPLETED', ReconciliationService::reconcile($this->system(), $ref)['status']);
        $this->assertSame(1, $this->rowCount('tbl_manager_ledger', "activation_id = ? AND entry_type = 'ACTIVATION_DEBIT'", [$this->id($ref)]));
    }

    /** C: never verified, RADIUS shows exactly the target: verification continues, settled exactly once. */
    public function testUnverifiedButExactlyTheTargetIsSettledOnce(): void
    {
        [$ref, $top, $leaf] = $this->unknownProvisioning();
        $row = ReconciliationService::reconcile($this->system(), $ref);
        $this->assertSame('COMPLETED', $row['status']);
        $this->assertContains('RECONCILIATION:RECONCILIATION_REQUIRED->VERIFIED:SUCCESS', $this->timeline($ref));
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
        $this->assertSame(1, $this->rowCount('tbl_manager_ledger', "activation_id = ? AND entry_type = 'ACTIVATION_DEBIT'", [$this->id($ref)]));
        $this->expectExceptionReason('ACTIVATION_STATE_CONFLICT', fn() => ReconciliationService::reconcile($this->system(), $ref), 'I: a second reconcile changes nothing');
        $this->assertSame(1, $this->rowCount('tbl_manager_ledger', "activation_id = ? AND entry_type = 'ACTIVATION_DEBIT'", [$this->id($ref)]), 'no double debit');
    }

    /** D: never verified, RADIUS shows exactly the captured prior state (NEW: nothing; RENEW: the previous service). */
    public function testUnverifiedAndExactlyThePriorStateIsNotApplied(): void
    {
        [$plan, $top, $leaf, $c] = $this->setupSale();
        foreach (['NEW', 'RENEW'] as $action) {
            if ($action === 'RENEW') {
                $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
            }
            $before = [$this->balance($leaf), $this->balance($top)];
            ActivationFaults::$hook = static function (string $p): void {
                if ($p === 'activation.before_provision') {
                    throw new \RuntimeException('injected: crash before the RADIUS call');
                }
            };
            try {
                $this->activate($leaf, $c, $plan, $action);
                $this->fail('crash expected');
            } catch (\RuntimeException) {
            }
            ActivationFaults::$hook = null;
            $ref = (string) static::$db->value('SELECT reference FROM tbl_reseller_activations WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$c]);
            $row = ReconciliationService::reconcile($this->system(), $ref, 0);
            $this->assertSame(['FAILED', 'NOT_APPLIED'], [$row['status'], $row['error_code']], $action);
            $this->assertSame('RELEASED', $this->reservation($ref));
            $this->assertSame(0, $this->ledgerRows($ref), "{$action}: no debit, no commission");
            $this->assertSame($before, [$this->balance($leaf), $this->balance($top)]);
        }
    }

    /** E, F: never verified and a foreign edit: EXTERNAL_STATE_AMBIGUOUS; nothing changes, nothing is released. */
    public function testUnverifiedForeignStateIsAmbiguousAndHoldsTheFunds(): void
    {
        foreach (['expiry' => [fn(int $c) => $this->foreignExpiry($c), 'check:Expiration'], 'group' => [fn(int $c) => $this->foreignGroup($c), 'groups']] as $case => [$edit, $component]) {
            [$ref, $top, $leaf, $c] = $this->unknownProvisioning();
            $edit($c);
            $radius = $this->radiusRows($this->username($c));
            $row = ReconciliationService::reconcile($this->system(), $ref);
            $this->assertSame(['RECONCILIATION_REQUIRED', ReconciliationService::AMBIGUOUS], [$row['status'], $row['error_code']], $case);
            $this->assertSame('ACTIVE', $this->reservation($ref), "{$case}: reservation held");
            $this->assertSame(0, $this->ledgerRows($ref), "{$case}: zero ledger movement");
            $this->assertSame(['1000.00', '1000.00', '15.00'], [$this->balance($leaf), $this->balance($top), $this->reserved($leaf)]);
            $this->assertSame(0, $this->rowCount('tbl_user_recharges', 'customer_id = ?', [$c]), "{$case}: no legacy finalization");
            $this->assertSame($radius, $this->radiusRows($this->username($c)), "{$case}: RADIUS not modified");
            $meta = json_decode((string) static::$db->value("SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND error_code = ? ORDER BY sequence_no DESC LIMIT 1",
                [$this->id($ref), ReconciliationService::AMBIGUOUS]), true);
            $this->assertSame('AMBIGUOUS', $meta['outcome']);
            $this->assertContains($component, $meta['differs_from_target'], "{$case}: the differing component is named");
            $this->assertStringNotContainsString('31 Dec 2035', (string) json_encode($meta), 'names only, never values');
            // I: the same reconcile again records nothing new and changes nothing
            $events = $this->events($ref);
            $again = ReconciliationService::reconcile($this->system(), $ref);
            $this->assertSame([ReconciliationService::AMBIGUOUS, $events, 'ACTIVE'], [$again['error_code'], $this->events($ref), $this->reservation($ref)], "{$case}: idempotent");
        }
    }

    /** G, I: from the ambiguous state the operator rolls back: prior restored and verified, then released exactly once. */
    public function testRollbackFromTheAmbiguousStateRestoresAndReleasesOnce(): void
    {
        [$ref, $top, $leaf, $c] = $this->unknownProvisioning();
        $this->foreignGroup($c);
        $this->assertSame(ReconciliationService::AMBIGUOUS, ReconciliationService::reconcile($this->system(), $ref)['error_code']);
        $row = ReconciliationService::rollback($this->system(), $ref);
        $this->assertSame('ROLLED_BACK', $row['status']);
        $u = $this->username($c);
        $this->assertSame([], $this->radcheck($u), 'the captured prior state (nothing) is restored');
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM radusergroup WHERE username = ?', [$u]), 'including group memberships');
        $this->assertSame('RELEASED', $this->reservation($ref));
        $this->assertSame(['1000.00', '1000.00', '0.00'], [$this->balance($leaf), $this->balance($top), $this->reserved($leaf)]);
        $this->expectExceptionReason('ACTIVATION_STATE_CONFLICT', fn() => ReconciliationService::rollback($this->system(), $ref), 'I: no second rollback');
        $this->expectExceptionReason('ACTIVATION_STATE_CONFLICT', fn() => ReconciliationService::reconcile($this->system(), $ref), 'I: terminal');
        $this->assertSame(1, $this->rowCount('tbl_manager_wallet_reservations', "activation_id = ? AND status = 'RELEASED'", [$this->id($ref)]), 'released exactly once');
    }

    /** H: an uncertain or failed restore never releases; the rollback can be repeated. */
    public function testUncertainRollbackKeepsTheFundsHeld(): void
    {
        [$ref, $top, $leaf, $c] = $this->unknownProvisioning();
        $this->foreignExpiry($c);
        ReconciliationService::reconcile($this->system(), $ref);
        foreach (['radius.commit_lost' => new \PDOException('MySQL server has gone away'), 'radius.after_write' => new \RuntimeException('injected: restore rejected')] as $point => $e) {
            RadiusFaults::$hook = static function (string $p) use ($point, $e): void {
                if ($p === $point) {
                    throw $e;
                }
            };
            $this->expectExceptionReason('ACTIVATION_STATE_CONFLICT', fn() => ReconciliationService::rollback($this->system(), $ref), $point);
            RadiusFaults::$hook = null;
            $this->assertSame(['ROLLBACK_PENDING', 'ACTIVE'], [$this->act($ref)['status'], $this->reservation($ref)], "{$point}: funds held");
            $this->assertSame(['1000.00', '15.00'], [$this->balance($leaf), $this->reserved($leaf)]);
        }
        $this->assertSame('ROLLED_BACK', ReconciliationService::rollback($this->system(), $ref)['status'], 'repeated rollback completes');
        $this->assertSame(['1000.00', '0.00'], [$this->balance($leaf), $this->reserved($leaf)]);
    }

    // ---- exact rollback (final owner review of ca1b12c7): the captured prior secret must be reconstructed exactly ----

    /** Every RADIUS row of the subscriber with its id: "row-for-row unchanged". */
    private function radiusExact(string $username): array
    {
        return [static::$db->rows('SELECT id, attribute, op, value FROM radcheck WHERE username = ? ORDER BY id', [$username]),
            static::$db->rows('SELECT id, attribute, op, value FROM radreply WHERE username = ? ORDER BY id', [$username]),
            static::$db->rows('SELECT id, groupname, priority FROM radusergroup WHERE username = ? ORDER BY id', [$username])];
    }

    /** The captured prior projection of an activation, compared with what RADIUS holds now. */
    private function priorRestored(string $reference): bool
    {
        $prior = \Reseller\Activation\ActivationStore::radius(static::$svc, $this->id($reference))['prior'];
        return \Reseller\Activation\RadiusState::sameSubscriber(
            \Reseller\Activation\RadiusState::read((string) $prior['username'], (int) $this->act($reference)['plan_id'], $reference), $prior);
    }

    /**
     * A completed NEW (the previous service), an optional change before the renewal, then a RENEW whose
     * settlement fails after VERIFIED: RECONCILIATION_REQUIRED, with the previous service as the captured prior.
     *
     * @return array{0:string,1:int,2:int,3:int}
     */
    private function renewOverPreviousService(string $type = 'Hotspot', array $customer = [], ?callable $beforeRenew = null): array
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20', $type);
        $c = $this->customer($leaf, null, $customer);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        if ($beforeRenew !== null) {
            $beforeRenew($c);
        }
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.after_settlement') {
                throw new \RuntimeException('injected: settlement interrupted');
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'RENEW');
        ActivationFaults::$hook = null;
        $this->assertSame('RECONCILIATION_REQUIRED', $out['status']);
        return [$out['reference'], $top, $leaf, $c];
    }

    private function assertRefusedUnrestorable(string $ref, int $leaf, int $c): void
    {
        $u = $this->username($c);
        $rows = $this->radiusExact($u);
        $events = null;
        foreach ([1, 2, 3] as $attempt) {
            $this->expectExceptionReason('PRIOR_SECRET_UNRESTORABLE', fn() => ReconciliationService::rollback($this->system(), $ref), "attempt {$attempt}");
            $this->assertSame($rows, $this->radiusExact($u), "attempt {$attempt}: RADIUS row-for-row unchanged");
            $act = $this->act($ref);
            $this->assertSame(['ROLLBACK_PENDING', 'PRIOR_SECRET_UNRESTORABLE', 'ACTIVE'], [$act['status'], $act['error_code'], $this->reservation($ref)], "attempt {$attempt}: funds held");
            $this->assertSame(0, $this->ledgerRows($ref), 'zero ledger movement');
            $this->assertSame('15.00', $this->reserved($leaf));
            $events ??= $this->events($ref);
            $this->assertSame($events, $this->events($ref), "attempt {$attempt}: a repeated refusal records nothing new");
        }
    }

    /** 1: the prior password is customer.password: restored exactly, full projection verified, then released. */
    public function testRollbackRestoresTheCustomerPasswordExactly(): void
    {
        $previous = null;
        [$ref, $top, $leaf, $c] = $this->renewOverPreviousService('Hotspot', [], function (int $c) use (&$previous): void {
            $previous = $this->radcheck($this->username($c));
        });
        $this->assertSame('ROLLED_BACK', ReconciliationService::rollback($this->system(), $ref)['status']);
        $this->assertSame($previous, $this->radcheck($this->username($c)), 'the previous service, password included');
        $this->assertSame('pw-' . $this->username($c), $this->radcheck($this->username($c))['Cleartext-Password']);
        $this->assertTrue($this->priorRestored($ref), 'complete subscriber projection == captured prior');
        $this->assertSame(['RELEASED', '0.00', 0], [$this->reservation($ref), $this->reserved($leaf), $this->ledgerRows($ref)]);
    }

    /** 2: the prior password is customer.pppoe_password (PPPoE): restored exactly. */
    public function testRollbackRestoresThePppoePasswordExactly(): void
    {
        $pppoe = 'ppp-' . bin2hex(random_bytes(6));
        [$ref, , $leaf, $c] = $this->renewOverPreviousService('PPPOE', ['pppoe_password' => $pppoe, 'service_type' => 'PPPoE']);
        $this->assertSame($pppoe, $this->radcheck($this->username($c))['Cleartext-Password'], 'PPPoE authenticates with pppoe_password');
        $this->assertSame('ROLLED_BACK', ReconciliationService::rollback($this->system(), $ref)['status']);
        $this->assertSame($pppoe, $this->radcheck($this->username($c))['Cleartext-Password']);
        $this->assertTrue($this->priorRestored($ref));
        $this->assertSame(['RELEASED', '0.00'], [$this->reservation($ref), $this->reserved($leaf)]);
    }

    /** 3, 5: the prior password is foreign / unknown: refused before any write, repeatedly; funds held. */
    public function testUnknownPriorPasswordIsRefusedBeforeAnyWrite(): void
    {
        [$ref, $top, $leaf, $c] = $this->renewOverPreviousService('Hotspot', [], function (int $c): void {
            static::$db->pdo->prepare("UPDATE radcheck SET value = 'foreign-secret-x' WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$this->username($c)]);
        });
        $this->assertSame('pw-' . $this->username($c), $this->radcheck($this->username($c))['Cleartext-Password'], 'the renewal wrote the customer password');
        $before = [$this->balance($leaf), $this->balance($top)];
        $this->assertRefusedUnrestorable($ref, $leaf, $c);
        $this->assertSame($before, [$this->balance($leaf), $this->balance($top)]);
        $this->assertSame(1, $this->rowCount('tbl_user_recharges', 'customer_id = ?', [$c]), 'no legacy financial finalization of the renewal');
        $this->assertStringContainsString('cannot be restored automatically', (string) $this->act($ref)['error_message']);
    }

    /** 4: a prior state without a password row is restored as exactly that absence. */
    public function testPriorWithoutPasswordRestoresTheAbsence(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $u = $this->username($c);
        static::$db->pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Simultaneous-Use', ':=', '7')")->execute([$u]);
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.after_settlement') {
                throw new \RuntimeException('injected: settlement interrupted');
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        ActivationFaults::$hook = null;
        $this->assertArrayHasKey('Cleartext-Password', $this->radcheck($u));
        $this->assertSame('ROLLED_BACK', ReconciliationService::rollback($this->system(), $out['reference'])['status']);
        $this->assertSame(['Simultaneous-Use' => '7'], $this->radcheck($u), 'exactly the prior rows; no password invented');
        $this->assertTrue($this->priorRestored($out['reference']));
        $this->assertSame('RELEASED', $this->reservation($out['reference']));
        $this->assertStringContainsString('"password":"none"', (string) static::$db->value("SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND to_state = 'ROLLED_BACK'", [$this->id($out['reference'])]));
    }

    /** 6: ambiguous external state + an unknown prior password: never converted into a random-password state. */
    public function testAmbiguousStateWithUnknownPriorPasswordStaysUntouched(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $u = $this->username($c);
        static::$db->pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', 'legacy-foreign')")->execute([$u]);
        $calls = 0;
        RadiusFaults::$hook = static function (string $p) use (&$calls): void {
            if ($p === 'radius.commit_lost' && ++$calls === 2) {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        RadiusFaults::$hook = null;
        $ref = $out['reference'];
        $this->foreignGroup($c);
        $this->assertSame(ReconciliationService::AMBIGUOUS, ReconciliationService::reconcile($this->system(), $ref)['error_code']);
        $this->assertRefusedUnrestorable($ref, $leaf, $c);
        $this->assertSame('pw-' . $u, $this->radcheck($u)['Cleartext-Password'], 'not re-keyed to a random value');
    }

    /** The prior and target states are durable, captured before any write, and hold no password. */
    public function testCaptureIsDurableAndHoldsNoSecret(): void
    {
        [$plan, , $leaf, $c] = $this->setupSale();
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame('COMPLETED', $out['status']);
        $meta = json_decode((string) static::$db->value("SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND to_state = 'PROVISIONING'", [$this->id($out['reference'])]), true);
        $this->assertSame(['check' => [], 'reply' => [], 'groups' => []], $meta['radius']['prior']['subscriber'], 'NEW: nothing before');
        $password = array_values(array_filter($meta['radius']['target']['subscriber']['check'], static fn(array $r): bool => $r[0] === 'Cleartext-Password'))[0][2];
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $password, 'a salted digest');
        $secret = (string) static::$db->value('SELECT password FROM tbl_customers WHERE id = ?', [$c]);
        foreach (static::$db->rows('SELECT metadata FROM tbl_reseller_activation_events WHERE metadata IS NOT NULL') as $e) {
            $this->assertStringNotContainsString($secret, (string) $e['metadata'], 'no password in the activation history');
        }
    }

    /** Without a readable prior state provisioning never starts: nothing written, funds reserved, resumable. */
    public function testUnreadableRadiusNeverStartsProvisioning(): void
    {
        [$plan, , $leaf, $c] = $this->setupSale();
        \ORM::set_db(FixtureBuilder::connect((string) getenv('WP1_DB_HOST'), TestDatabase::rootPassword(), 'information_schema'), 'radius');
        try {
            $out = $this->activate($leaf, $c, $plan, 'NEW');
        } finally {
            \ORM::set_db(static::$svc, 'radius');
        }
        $this->assertSame(['FUNDS_RESERVED', 'RADIUS_READ_FAILED'], [$out['status'], $out['error_code']]);
        $this->assertNotContains('PROVISIONING', array_column(static::$db->rows('SELECT to_state FROM tbl_reseller_activation_events WHERE activation_id = ?', [$this->id($out['reference'])]), 'to_state'));
        $this->assertSame([], $this->radcheck($this->username($c)));
        $this->assertSame('COMPLETED', ActivationService::execute($this->id($out['reference']), $this->system())['status'], 'resumed once RADIUS is readable');
    }
}
