<?php
namespace Reseller\Tests\Integration\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationService;
use Reseller\Activation\ReconciliationService;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceSeed as Seed;

/**
 * Who may activate for whom (owner decision A5), eligibility refusals (A5, WP3B), idempotency (A2/§3.4):
 * every refusal writes nothing; a replay never adds a period, a debit or a commission.
 */
final class ActivationAuthorizationTest extends ActivationTestCase
{
    public function testOwnerSalesAndAdminMayActivateOnTheOwnersWallet(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $sales = Seed::staff(static::$db, 'sl' . bin2hex(random_bytes(3)), $leaf, 'Active', true, 'Sales');
        $own = $this->customer($leaf);
        $assigned = $this->customer($leaf, $sales);
        $admin = $this->customer($leaf);
        $this->assertSame('COMPLETED', $this->activate($leaf, $own, $plan, 'NEW')['status'], 'owning Agent');
        $this->assertSame('COMPLETED', $this->activate($sales, $assigned, $plan, 'NEW')['status'], 'Sales, assigned customer');
        $this->assertSame('COMPLETED', $this->activate('ad', $admin, $plan, 'NEW')['status'], 'Admin');
        $this->assertSame(['955.00', '1009.00'], [$this->balance($leaf), $this->balance($top)], 'three sales, always on the owner (leaf) wallet');
        $this->assertSame([$leaf, $leaf, $leaf], array_map('intval', array_column(static::$db->rows('SELECT manager_id FROM tbl_reseller_activations WHERE customer_id IN (?, ?, ?) ORDER BY id', [$own, $assigned, $admin]), 'manager_id')));
    }

    public function testScopeRefusalsWriteNothing(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain();
        $other = $this->funded('1000');
        $sales = Seed::staff(static::$db, 'sl' . bin2hex(random_bytes(3)), $leaf, 'Active', true, 'Sales');
        $c = $this->customer($leaf);
        $legacy = $this->customer(null);
        $this->refused('NOT_FOUND', fn() => $this->activate($top, $c, $plan, 'NEW'), true); // A5: an ancestor never spends the child's wallet
        $this->refused('NOT_FOUND', fn() => $this->activate($other, $c, $plan, 'NEW'));
        $this->refused('NOT_FOUND', fn() => $this->activate($sales, $c, $plan, 'NEW'), true); // not assigned
        $this->refused('FORBIDDEN', fn() => $this->activate('rp', $c, $plan, 'NEW'));
        $this->refused('NOT_FOUND', fn() => $this->activate($leaf, $legacy, $plan, 'NEW'));
        $this->refused('CUSTOMER_NOT_RESELLER_OWNED', fn() => $this->activate('ad', $legacy, $plan, 'NEW'));
        foreach ([0, -1, '1 OR 1=1', 'abc', 99999999] as $forged) {
            $this->refused('NOT_FOUND', fn() => ActivationService::create($this->ctx($leaf), $forged, $plan, 'NEW', self::nonce()));
        }
        $this->refused('INVALID_INPUT', fn() => ActivationService::create($this->ctx($leaf), $c, $plan, 'UPGRADE', self::nonce()));
        foreach (['', 'short', str_repeat('x', 65), "bad'nonce-0123456789"] as $bad) {
            $this->refused('INVALID_IDEMPOTENCY_KEY', fn() => ActivationService::create($this->ctx($leaf), $c, $plan, 'NEW', $bad));
        }
    }

    public function testEligibilityRefusalsWriteNothing(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain();
        $c = $this->customer($leaf);
        $inactive = $this->customer($leaf, null, ['status' => 'Banned']);
        $this->refused('CUSTOMER_INACTIVE', fn() => $this->activate($leaf, $inactive, $plan, 'NEW'));
        $mikrotik = $this->radiusPlan('20', 'Hotspot', 30, 'Days', ['device' => 'MikrotikHotspot']);
        $this->refused('PLAN_DEVICE_NOT_SUPPORTED', fn() => $this->activate($leaf, $c, $mikrotik, 'NEW'));
        $vpn = $this->radiusPlan('20', 'Hotspot', 30, 'Days', ['type' => 'VPN']);
        $this->refused('PLAN_DEVICE_NOT_SUPPORTED', fn() => $this->activate($leaf, $c, $vpn, 'NEW'));
        $disabled = $this->radiusPlan('20', 'Hotspot', 30, 'Days', ['enabled' => 0]);
        $this->refused('PLAN_DISABLED', fn() => $this->activate($leaf, $c, $disabled, 'NEW'));
        $unpriced = $this->radiusPlan('20');
        $this->refused('PLAN_NOT_RESELLABLE', fn() => $this->activate($leaf, $c, $unpriced, 'NEW'));
        // insufficient credit: the leaf cannot pay 15
        $plan2 = $this->radiusPlan('20');
        $this->base($plan2, '10');
        [, $poor] = $this->chain($plan2, ['12', '15'], '10');
        $poorCustomer = $this->customer($poor);
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => $this->activate($poor, $poorCustomer, $plan2, 'NEW'));
    }

    public function testFrozenResellerCannotStartButSettlementCompletes(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain();
        $c = $this->customer($leaf);
        $this->setStatus($leaf, 'Inactive');
        $this->refused('FORBIDDEN', fn() => $this->activate($leaf, $c, $plan, 'NEW'), true); // frozen: no mutating scope
        $this->refused('WALLET_OWNER_FROZEN', fn() => $this->activate('ad', $c, $plan, 'NEW'), false);
        $this->setStatus($leaf, 'Active');
        // a reseller frozen after provisioning still settles (WP3A: commit stays possible)
        \Reseller\Activation\ActivationFaults::$hook = function (string $p) use ($leaf): void {
            if ($p === 'activation.before_commit') {
                $this->setStatus($leaf, 'Inactive');
            }
        };
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $this->setStatus($leaf, 'Active');
    }

    /** The activation timeline is a required table: without it nothing activates, for any role, before any write. */
    public function testSchemaNotReadyRefusesBeforeAnyWrite(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain();
        $c = $this->customer($leaf);
        static::$db->exec('RENAME TABLE `tbl_reseller_activation_events` TO `tbl_reseller_activation_events_wp5a_off`');
        \Reseller\Domain\SchemaReadiness::reset();
        try {
            foreach ([$leaf, 'ad', 'sa'] as $who) {
                try {
                    $this->activate($who, $c, $plan, 'NEW');
                    $this->fail('activated without the timeline table');
                } catch (\Reseller\Domain\DomainError $e) {
                    $this->assertContains($e->reason, ['SCHEMA_NOT_READY', 'FORBIDDEN'], (string) $who);
                }
            }
            $this->assertSame(0, $this->rowCount('tbl_reseller_activations', 'customer_id = ?', [$c]));
        } finally {
            static::$db->exec('RENAME TABLE `tbl_reseller_activation_events_wp5a_off` TO `tbl_reseller_activation_events`');
            \Reseller\Domain\SchemaReadiness::reset();
        }
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status'], 'restored: works again');
    }

    public function testReplayNeverDoublesAPeriodDebitOrCommission(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $nonce = self::nonce();
        $first = $this->activate($leaf, $c, $plan, 'NEW', $nonce);
        $before = $this->checksums();
        foreach ([1, 2, 3] as $i) {
            $again = $this->activate($leaf, $c, $plan, 'NEW', $nonce);
            $this->assertSame([$first['reference'], 'COMPLETED', true], [$again['reference'], $again['status'], $again['replayed']], "replay {$i}");
        }
        $this->assertSame([], \Reseller\Tests\Support\DbProbe::changed($before, $this->checksums()), 'a replay writes nothing');
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => $this->activate($leaf, $c, $plan, 'RENEW', $nonce), true);
        $second = $this->customer($leaf);
        $this->refused('IDEMPOTENCY_CONFLICT', fn() => $this->activate($leaf, $second, $plan, 'NEW', $nonce), true);
        // the same nonce from another staff member is another key
        $other = $this->activate('ad', $c, $plan, 'RENEW', $nonce);
        $this->assertNotSame($first['reference'], $other['reference']);
    }

    public function testReadScopeAndReconciliationAuthority(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain();
        $sales = Seed::staff(static::$db, 'sl' . bin2hex(random_bytes(3)), $leaf, 'Active', true, 'Sales');
        $other = $this->funded('1000');
        $c = $this->customer($leaf, $sales);
        $ref = $this->activate($leaf, $c, $plan, 'NEW')['reference'];
        foreach ([$leaf, $top, $sales, 'ad', 'sa'] as $who) {
            $this->assertSame($ref, ActivationService::status($this->ctx($who), $ref)['activation']['reference'], "reader {$who}");
        }
        foreach ([$other, 'rp'] as $who) {
            try {
                ActivationService::status($this->ctx($who), $ref);
                $this->fail("{$who} must not read");
            } catch (\Reseller\Domain\DomainError $e) {
                $this->assertContains($e->reason, ['NOT_FOUND', 'FORBIDDEN']);
            }
        }
        foreach ([$leaf, $top, $sales] as $who) {
            $this->refused('FORBIDDEN', fn() => ReconciliationService::staffActor($this->ctx($who)), false);
        }
        $this->assertInstanceOf(ActivationActor::class, ReconciliationService::staffActor($this->ctx('ad')));
    }
}
