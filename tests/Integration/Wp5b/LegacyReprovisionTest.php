<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Domain\TreeSerialization;
use Reseller\Finance\FinanceTx;
use Reseller\Radius\ProvisionFailure;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceWorkers;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP5B §4 bounded re-provision of reseller-owned customers (OD-10) and the device default deny (§4.3):
 * serialized by the shared tree lock, the service owns the mutation, billing is never changed, a confirmed
 * device failure reverts the DB, an uncertain outcome is kept and reported (REPROVISION_UNCONFIRMED).
 */
final class LegacyReprovisionTest extends ActivationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $root = dirname(__DIR__, 3);
        $GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
        $GLOBALS['_app_stage'] = 'Live';
        foreach (['Package', 'Text'] as $c) {
            require_once $root . "/system/autoload/{$c}.php";
        }
        require_once $root . '/system/devices/Radius.php';
    }

    /** @return array{0:int,1:int,2:int,3:string} plan, leaf, customer, activation reference */
    private function activated(): array
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame('COMPLETED', $out['status']);
        return [$plan, $leaf, $c, $out['reference']];
    }

    private function billing(int $c): array
    {
        return static::$db->rows('SELECT id, plan_id, recharged_on, recharged_time, expiration, time, status FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id', [$c]);
    }

    private function customerRow(int $c): array
    {
        return static::$db->rows('SELECT username, password, pppoe_username, pppoe_password, pppoe_ip, status FROM tbl_customers WHERE id = ?', [$c])[0];
    }

    private function self(int $c): \Reseller\Security\ActorContext
    {
        return \Reseller\Security\ActorContext::forCustomer(static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$c])[0], 'WEB');
    }

    private function audits(string $op): array
    {
        return array_map(static fn(array $r): array => json_decode((string) $r['after_json'], true),
            static::$db->rows("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = ? ORDER BY id", ['reprovision.' . strtolower($op)]));
    }

    public function testTreeSerializationIsTheSameLockAsTheFinanceTreeLock(): void
    {
        $pdo = FinanceTx::pdo();
        $this->assertSame(FinanceTx::treeLockName($pdo), TreeSerialization::name($pdo));
        $other = FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword(), static::$db->name);
        $try = static function () use ($other, $pdo): string {
            $st = $other->prepare('SELECT GET_LOCK(?, 0)');
            $st->execute([TreeSerialization::name($pdo)]);
            $got = (string) $st->fetchColumn();
            $other->query('SELECT RELEASE_ALL_LOCKS()')->fetchAll();
            return $got;
        };
        $this->assertSame('0', TreeSerialization::hold(static fn(): string => $try()), 'another session blocks while hold() is active');
        $this->assertFalse($pdo->inTransaction(), 'hold() opens no transaction');
        $this->assertSame('0', FinanceTx::run(static fn(): string => $try(), true), 'and while the finance tree lock is held');
        $this->assertSame('1', $try(), 'free again afterwards');
        // re-entrant: a nested tree-locked transaction inside hold() keeps the outer hold
        TreeSerialization::hold(function () use ($try): void {
            FinanceTx::run(static fn() => null, true);
            $this->assertSame('0', $try(), 'still held after the nested release');
        });
    }

    public function testAdminSyncReprovisionsWithoutTouchingBilling(): void
    {
        [, , $c] = $this->activated();
        $u = $this->username($c);
        $billing = $this->billing($c);
        $radius = $this->radcheck($u);
        static::$db->pdo->prepare('DELETE FROM radcheck WHERE username = ?')->execute([$u]); // RADIUS lost its rows
        $r = R::run($this->ctx('ad'), $c, R::ADMIN_SYNC);
        $this->assertSame(['DONE', ['radius']], [$r['status'], $r['routers']]);
        $this->assertSame($radius, $this->radcheck($u), 'the saved billing policy is back');
        $this->assertSame($billing, $this->billing($c), 'billing unchanged');
        $this->assertSame('DONE', $this->audits(R::ADMIN_SYNC)[count($this->audits(R::ADMIN_SYNC)) - 1]['outcome']);
    }

    public function testRefusedWhileAnActivationIsInFlightWithoutAnyDeviceCall(): void
    {
        [$plan, $leaf, $c] = $this->activated();
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.before_provision') {
                throw new \RuntimeException('injected: in flight');
            }
        };
        try {
            $this->activate($leaf, $c, $plan, 'RENEW');
        } catch (\RuntimeException) {
        }
        ActivationFaults::$hook = null;
        $writes = 0;
        RadiusFaults::$hook = static function (string $p) use (&$writes): void {
            if ($p === 'radius.after_write') {
                $writes++;
            }
        };
        foreach ([[$this->ctx('ad'), R::ADMIN_SYNC, []], [$this->self($c), R::PORTAL_SYNC, []], [$this->self($c), R::PASSWORD_CHANGE, ['password' => 'new-pass-1']]] as [$a, $op, $req]) {
            $this->expectExceptionReason('ACTIVATION_IN_PROGRESS', fn() => R::run($a, $c, $op, $req), $op);
        }
        RadiusFaults::$hook = null;
        $this->assertSame(0, $writes);
        $this->assertSame('pw-' . $this->username($c), $this->customerRow($c)['password']);
    }

    public function testPasswordChangeByTheCustomerIsAppliedAndVerified(): void
    {
        [, , $c] = $this->activated();
        $billing = $this->billing($c);
        $r = R::run($this->self($c), $c, R::PASSWORD_CHANGE, ['password' => 'fresh-secret']);
        $this->assertSame('DONE', $r['status']);
        $this->assertSame('fresh-secret', $this->customerRow($c)['password']);
        $this->assertSame('fresh-secret', $this->radcheck($this->username($c))['Cleartext-Password']);
        $this->assertSame($billing, $this->billing($c));
        $this->customersChanged();
    }

    public function testConfirmedDeviceFailureRevertsTheDatabase(): void
    {
        [, , $c] = $this->activated();
        $before = $this->customerRow($c);
        $radius = $this->radcheck($this->username($c));
        RadiusFaults::$hook = static function (string $p): void {
            if ($p === 'radius.after_write') {
                throw new \RuntimeException('injected: write rejected');
            }
        };
        $this->expectExceptionReason('REPROVISION_FAILED', fn() => R::run($this->self($c), $c, R::PASSWORD_CHANGE, ['password' => 'fresh-secret']));
        RadiusFaults::$hook = null;
        $this->assertSame($before, $this->customerRow($c), 'old password restored');
        $this->assertSame($radius, $this->radcheck($this->username($c)), 'RADIUS unchanged (rolled back)');
        $this->assertSame('FAILED_REVERTED', $this->audits(R::PASSWORD_CHANGE)[count($this->audits(R::PASSWORD_CHANGE)) - 1]['outcome']);
    }

    public function testUnknownDeviceOutcomeKeepsTheNewValuesAndIsReported(): void
    {
        [, , $c] = $this->activated();
        $billing = $this->billing($c);
        RadiusFaults::$hook = static function (string $p): void {
            if ($p === 'radius.commit_lost') {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $this->expectExceptionReason(R::UNCONFIRMED, fn() => R::run($this->self($c), $c, R::PASSWORD_CHANGE, ['password' => 'fresh-secret']));
        RadiusFaults::$hook = null;
        $this->assertSame('fresh-secret', $this->customerRow($c)['password'], 'kept: no automatic revert');
        $this->assertSame($billing, $this->billing($c));
        $last = $this->audits(R::PASSWORD_CHANGE)[count($this->audits(R::PASSWORD_CHANGE)) - 1];
        $this->assertSame([R::UNCONFIRMED, ['password']], [$last['outcome'], $last['changed']]);
        $this->assertStringNotContainsString('fresh-secret', json_encode($last), 'no secret in the audit');
        // the operator converges with an idempotent ADMIN_SYNC
        $this->assertSame('DONE', R::run($this->ctx('ad'), $c, R::ADMIN_SYNC)['status']);
        $this->assertSame('fresh-secret', $this->radcheck($this->username($c))['Cleartext-Password']);
        $this->customersChanged();
    }

    public function testIdentityEditRenamesTheSubscriberAndNeverActivates(): void
    {
        [, , $c] = $this->activated();
        $old = $this->username($c);
        $billing = $this->billing($c);
        $new = 'rn' . bin2hex(random_bytes(4));
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']);
        $this->assertSame('DONE', $r['status']);
        $this->assertSame($new, $this->customerRow($c)['username']);
        $this->assertSame([], $this->radcheck($old), 'no rows left under the old key');
        $this->assertNotSame([], $this->radcheck($new));
        $this->assertSame($billing, $this->billing($c), 'billing columns unchanged');
        $this->assertSame([$new], array_column(static::$db->rows('SELECT DISTINCT username FROM tbl_user_recharges WHERE customer_id = ?', [$c]), 'username'), 'the recharge rows follow the username (legacy)');
        $this->expectExceptionReason('INVALID_INPUT', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['status' => 'Active']), 'status is never an identity field');
        $this->expectExceptionReason('USERNAME_TAKEN', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'cA1']));
        $this->customersChanged();
    }

    public function testAuthorizationAndOwnership(): void
    {
        [, $leaf, $c] = $this->activated();
        $other = $this->customer($leaf);
        $this->expectExceptionReason('FORBIDDEN', fn() => R::run($this->ctx($leaf), $c, R::ADMIN_SYNC), 'the owner Agent');
        $this->expectExceptionReason('FORBIDDEN', fn() => R::run($this->ctx('A1'), $c, R::IDENTITY_EDIT, ['password' => 'x1']), 'Sales');
        $this->expectExceptionReason('FORBIDDEN', fn() => R::run($this->self($other), $c, R::PORTAL_SYNC), 'another customer');
        $this->expectExceptionReason('FORBIDDEN', fn() => R::run($this->self($c), $c, R::ADMIN_SYNC), 'a customer on an admin operation');
        $this->expectExceptionReason('CUSTOMER_NOT_RESELLER_OWNED', fn() => R::run($this->ctx('ad'), $this->cid('cNULL'), R::ADMIN_SYNC));
        $this->expectExceptionReason('NOT_FOUND', fn() => R::run($this->ctx('ad'), 999999, R::ADMIN_SYNC));
    }

    public function testDeviceDefaultDenyForResellerOwnedCustomers(): void
    {
        [$plan, , $c] = $this->activated();
        $u = $this->username($c);
        $radius = $this->radcheck($u);
        $customer = static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$c])[0];
        $p = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [$plan])[0];
        foreach (['add_customer' => fn() => (new \Radius())->add_customer($customer, $p), 'sync_customer' => fn() => (new \Radius())->sync_customer($customer, $p),
            'change_username' => fn() => (new \Radius())->change_username($p, $u, 'hijack' . bin2hex(random_bytes(3)))] as $m => $call) {
            try {
                $call();
                $this->fail("{$m} must be refused");
            } catch (ProvisionFailure $e) {
                $this->assertSame('RESELLER_OWNED_REFUSED', $e->result->code, $m);
            }
        }
        $this->assertSame($radius, $this->radcheck($u), 'nothing written');
        // an unassigned customer keeps the legacy device behaviour
        $legacy = static::$db->rows('SELECT * FROM tbl_customers WHERE username = ?', ['cNULL'])[0];
        $this->recharge((int) $legacy['id'], $plan, '2031-01-01', '10:00:00');
        (new \Radius())->add_customer($legacy, $p);
        $this->assertSame('01 Jan 2031 10:00:00', $this->radcheck('cNULL')['Expiration']);
    }

    public function testActivationOpenAndAdminSyncAreSerialized(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        for ($round = 1; $round <= 4; $round++) {
            $c = $this->customer($leaf);
            $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
            [$open, $sync] = FinanceWorkers::stampede(static::$db, [
                ['op' => 'activate', 'actor' => $leaf, 'customer' => $c, 'plan' => $plan, 'action' => 'RENEW', 'nonce' => self::nonce()],
                ['op' => 'reprovision', 'actor' => $this->sid('ad'), 'customer' => $c, 'reprovision' => R::ADMIN_SYNC],
            ]);
            $this->assertTrue($open['ok'] && $open['result']['status'] === 'COMPLETED', json_encode($open));
            $this->assertTrue($sync['ok'] ? $sync['result']['status'] === 'DONE' : $sync['reason'] === 'ACTIVATION_IN_PROGRESS', json_encode($sync));
            // whichever order: RADIUS shows exactly the renewal (a sync never applied a stale policy over it)
            $ref = (string) static::$db->value('SELECT reference FROM tbl_reseller_activations WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$c]);
            $target = \Reseller\Activation\ActivationStore::radius(static::$db->pdo, (int) $this->act($ref)['id'])['target'];
            $this->assertTrue(\Reseller\Activation\RadiusState::same(\Reseller\Activation\RadiusState::read($this->username($c), $plan, $ref), $target), "round {$round}");
        }
    }

    /** §10: activation open vs PASSWORD_CHANGE of the same customer: serialized by the tree lock. */
    public function testActivationOpenAndPasswordChangeAreSerialized(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        for ($round = 1; $round <= 4; $round++) {
            $c = $this->customer($leaf);
            $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
            $customer = static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$c])[0];
            [$open, $pw] = FinanceWorkers::stampede(static::$db, [
                ['op' => 'activate', 'actor' => $leaf, 'customer' => $c, 'plan' => $plan, 'action' => 'RENEW', 'nonce' => self::nonce()],
                ['op' => 'reprovision_customer', 'customer' => $c, 'reprovision' => R::PASSWORD_CHANGE, 'requested' => ['password' => 'race-pass-' . $round]],
            ]);
            $this->assertTrue($open['ok'] && $open['result']['status'] === 'COMPLETED', json_encode($open));
            $this->assertTrue($pw['ok'] ? $pw['result']['status'] === 'DONE' : $pw['reason'] === 'ACTIVATION_IN_PROGRESS', json_encode($pw));
            // whichever order: RADIUS holds the renewal with the customer's current password
            $now = (string) static::$db->value('SELECT password FROM tbl_customers WHERE id = ?', [$c]);
            $this->assertSame($pw['ok'] ? 'race-pass-' . $round : $customer['password'], $now);
            $this->assertSame($now, $this->radcheck($this->username($c))['Cleartext-Password'], "round {$round}");
            $this->customersChanged();
        }
    }

    /** Fails the $n-th RADIUS write (a confirmed FAILED: the write transaction is rolled back). */
    private function failWrite(int $n): void
    {
        $count = 0;
        RadiusFaults::$hook = static function (string $p) use (&$count, $n): void {
            if ($p === 'radius.after_write' && ++$count === $n) {
                throw new \RuntimeException('injected: write rejected');
            }
        };
    }

    /** Fix round 1, blocker 3A: target 1 applied, target 2 confirmed FAILED -> UNCONFIRMED, the new values stay. */
    public function testPartialSuccessAcrossTargetsIsUnconfirmedAndNeverReverted(): void
    {
        [$plan, , $c] = $this->activated();
        $pppoe = $this->radiusPlan('20', 'PPPOE');
        $this->recharge($c, $pppoe, '2031-01-01', '10:00:00', 'on', 'PPPOE');
        $this->failWrite(2);
        $this->expectExceptionReason(R::UNCONFIRMED, fn() => R::run($this->self($c), $c, R::PASSWORD_CHANGE, ['password' => 'partial-secret']));
        RadiusFaults::$hook = null;
        $this->assertSame('partial-secret', $this->customerRow($c)['password'], 'no DB revert after an external step applied');
        $this->assertSame('partial-secret', $this->radcheck($this->username($c))['Cleartext-Password'], 'the first target holds the new state');
        $last = $this->audits(R::PASSWORD_CHANGE)[count($this->audits(R::PASSWORD_CHANGE)) - 1];
        $this->assertSame(R::UNCONFIRMED, $last['outcome']);
        $this->assertStringNotContainsString('partial-secret', json_encode($last), 'no secret in the audit');
        $this->customersChanged();
    }

    /** Fix round 1, blocker 3B: change_username applied, the following add_customer confirmed FAILED -> UNCONFIRMED. */
    public function testRenameAppliedThenAddFailedIsUnconfirmedAndNeverReverted(): void
    {
        [, , $c] = $this->activated();
        $old = $this->username($c);
        $new = 'rn' . bin2hex(random_bytes(4));
        $this->failWrite(2);
        $this->expectExceptionReason(R::UNCONFIRMED, fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']));
        RadiusFaults::$hook = null;
        $this->assertSame($new, $this->customerRow($c)['username'], 'no DB rollback of the identity');
        $this->assertSame([], $this->radcheck($old), 'the rename did apply on RADIUS');
        $this->assertNotSame([], $this->radcheck($new));
        $this->customersChanged();
    }

    /** Fix round 1, blocker 3C: the very first external operation confirmed FAILED -> FAILED_REVERTED stays allowed. */
    public function testFirstOperationFailedIsStillReverted(): void
    {
        [, , $c] = $this->activated();
        $before = $this->customerRow($c);
        $this->failWrite(1);
        $this->expectExceptionReason('REPROVISION_FAILED', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'rn' . bin2hex(random_bytes(4)), 'password' => '']));
        RadiusFaults::$hook = null;
        $this->assertSame($before, $this->customerRow($c));
        $this->assertSame('FAILED_REVERTED', $this->audits(R::IDENTITY_EDIT)[count($this->audits(R::IDENTITY_EDIT)) - 1]['outcome']);
    }
}
