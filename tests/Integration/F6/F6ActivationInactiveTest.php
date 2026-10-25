<?php
namespace Reseller\Tests\Integration\F6;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationFaults;
use Reseller\Activation\ActivationService;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * F6 (ITC NetCore lab, 2026-10-24): reseller activation (ActivationService, staff activation/create) for customers
 * that are not Active. Every test asserts the SAFE behaviour: a failure is a finding.
 */
final class F6ActivationInactiveTest extends ActivationTestCase
{
    private const NON_ACTIVE = ['Suspended', 'Disabled', 'Inactive', 'Banned'];

    private function setCustomerStatus(int $customer, string $status): void
    {
        static::$db->pdo->prepare('UPDATE tbl_customers SET status = ? WHERE id = ?')->execute([$status, $customer]);
        $this->customersChanged();
    }

    /** NEW, RENEW and REACTIVATE are refused with CUSTOMER_INACTIVE, nothing reserved, nothing written to RADIUS. */
    public function testCreateRefusesEveryNonActiveStatus(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        foreach (self::NON_ACTIVE as $status) {
            $fresh = $this->customer($leaf, null, ['status' => $status]);
            $this->expectExceptionReason('CUSTOMER_INACTIVE', fn() => $this->activate($leaf, $fresh, $plan, 'NEW'), "NEW {$status}");
            $this->expectExceptionReason('CUSTOMER_INACTIVE', fn() => $this->activate('ad', $fresh, $plan, 'NEW'), "NEW by Admin {$status}");
            $renew = $this->customer($leaf);
            $this->recharge($renew, $plan, gmdate('Y-m-d', time() + 86400 * 5), '23:59:59', 'on');
            $react = $this->customer($leaf);
            $this->recharge($react, $plan, gmdate('Y-m-d', time() - 86400 * 5), '00:00:00', 'off');
            $this->setCustomerStatus($renew, $status);
            $this->setCustomerStatus($react, $status);
            $this->expectExceptionReason('CUSTOMER_INACTIVE', fn() => $this->activate($leaf, $renew, $plan, 'RENEW'), "RENEW {$status}");
            $this->expectExceptionReason('CUSTOMER_INACTIVE', fn() => $this->activate($leaf, $react, $plan, 'REACTIVATE'), "REACTIVATE {$status}");
            foreach ([$fresh, $renew, $react] as $c) {
                $this->assertSame([], $this->radcheck($this->username($c)), "{$status}: no RADIUS policy");
            }
        }
        $this->assertSame(['1000.00', '1000.00'], [$this->balance($top), $this->balance($leaf)], 'no wallet movement');
        $this->assertSame(0, (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_wallet_reservations WHERE status = 'ACTIVE'"));
    }

    /**
     * An activation accepted while the customer was Active, interrupted before provisioning (FUNDS_RESERVED), then the
     * customer is suspended: neither the operator resume (ActivationService::execute, reconcile CLI / operations UI)
     * nor the idempotent replay of the same request may provision the now non-Active customer.
     */
    public function testResumeOrReplayAfterSuspensionDoesNotProvision(): void
    {
        // Lab finding F6-3, confirmed on both engines (lab/f6-probe): the fix is inside the architecture-locked
        // ActivationService flow and waits for the owner decision (OWNER_ACTION_REQUIRED §1). Marked incomplete, never
        // counted as PASS; remove this line once a decided fix is integrated.
        $this->markTestIncomplete('F6-3: owner decision pending (ActivationService resume / replay after suspension)');
        $bad = [];
        foreach (['resume', 'replay'] as $how) {
            foreach (self::NON_ACTIVE as $status) {
                [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
                $c = $this->customer($leaf);
                $nonce = self::nonce();
                ActivationFaults::$hook = static function (string $p): void {
                    if ($p === 'activation.before_execute') {
                        throw new \RuntimeException('injected crash before execute');
                    }
                };
                try {
                    $this->activate($leaf, $c, $plan, 'NEW', $nonce);
                    $this->fail('crash expected');
                } catch (\RuntimeException) {
                }
                ActivationFaults::$hook = null;
                $ref = (string) static::$db->value('SELECT reference FROM tbl_reseller_activations WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$c]);
                $this->assertSame('FUNDS_RESERVED', $this->act($ref)['status']);
                $this->setCustomerStatus($c, $status);
                $out = $how === 'resume'
                    ? ActivationService::execute((int) $this->act($ref)['id'], ActivationActor::system('f6-test'))
                    : $this->activate($leaf, $c, $plan, 'NEW', $nonce);
                $state = $this->act($ref)['status'];
                $rad = $this->radcheck($this->username($c));
                if ($state === 'COMPLETED' || $rad !== []) {
                    $bad["{$how}/{$status}"] = ['state' => $state, 'radcheck' => array_keys($rad)];
                }
            }
        }
        $this->assertSame([], $bad, 'FINDING: an activation started while Active completed and provisioned RADIUS after the customer became non-Active '
            . '(ActivationService::execute / replay in open() do not re-check tbl_customers.status): ' . json_encode($bad));
    }
}
