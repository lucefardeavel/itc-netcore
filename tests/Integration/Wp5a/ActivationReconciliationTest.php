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
