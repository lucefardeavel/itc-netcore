<?php
namespace Reseller\Tests\Integration\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationService;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * NEW / RENEW / REACTIVATE / CHANGE_PLAN end to end (WP5A; C14; A2, A6): sealed snapshot, reservation, RADIUS
 * policy with the frozen period, exactly-once settlement with commissions, legacy bridge rows, full timeline.
 */
final class ActivationLifecycleTest extends ActivationTestCase
{
    private const NOMINAL = [
        'STATE_TRANSITION:-->CREATED', 'STATE_TRANSITION:CREATED->VALIDATED', 'FINANCIAL:VALIDATED->FUNDS_RESERVED',
        'STATE_TRANSITION:FUNDS_RESERVED->PROVISIONING', 'PROVISIONING_RESULT:PROVISIONING->PROVISIONED:SUCCESS',
        'STATE_TRANSITION:PROVISIONED->VERIFYING', 'VERIFICATION_RESULT:VERIFYING->VERIFIED:SUCCESS',
        'STATE_TRANSITION:VERIFIED->FINANCIAL_COMMITTING', 'FINANCIAL:FINANCIAL_COMMITTING->COMPLETED',
    ];

    public function testNewActivationCompletesEndToEnd(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame(['COMPLETED', 'NEW', false, null], [$out['status'], $out['action'], $out['replayed'], $out['error_code']]);
        $this->assertSame(self::NOMINAL, $this->timeline($out['reference']));
        $act = $this->act($out['reference']);
        $this->assertSame(['15.00', '20.00', '12.00', $leaf, $c, $plan], [$act['wholesale_cost'], $act['retail_price'], $act['provider_settlement_amount'],
            (int) $act['manager_id'], (int) $act['customer_id'], (int) $act['plan_id']], 'sealed snapshot and owner');
        // money: leaf -15, top +3 commission, provider settlement 12 stays on the activation (WP3B)
        $this->assertSame(['985.00', '1003.00'], [$this->balance($leaf), $this->balance($top)]);
        $this->assertSame([$top => '3.00'], $this->commissions((int) $act['id']));
        // RADIUS: the frozen period expiry, plan group
        $u = $this->username($c);
        $period = ActivationService::now();
        $rad = $this->radcheck($u);
        $this->assertSame('pw-' . $u, $rad['Cleartext-Password']);
        $exp = \DateTimeImmutable::createFromFormat('d M Y H:i:s', $rad['Expiration'], $period->getTimezone());
        $this->assertEqualsWithDelta($period->modify('+30 day')->getTimestamp(), $exp->getTimestamp(), 120, 'NEW starts now: +30 days');
        $this->assertSame(['plan_' . $plan], array_column(static::$db->rows('SELECT groupname FROM radusergroup WHERE username = ?', [$u]), 'groupname'));
        // legacy bridge
        $rech = static::$db->rows('SELECT * FROM tbl_user_recharges WHERE id = ?', [(int) $act['legacy_recharge_id']])[0];
        $this->assertSame([$c, $plan, 'on', 'radius', 'Hotspot', 'Reseller - ' . $out['reference']], [(int) $rech['customer_id'], (int) $rech['plan_id'], $rech['status'], $rech['routers'], $rech['type'], $rech['method']]);
        $this->assertSame($exp->format('Y-m-d H:i:s'), $rech['expiration'] . ' ' . $rech['time'], 'recharge row = RADIUS Expiration');
        $trx = static::$db->rows('SELECT * FROM tbl_transactions WHERE id = ?', [(int) $act['legacy_transaction_id']])[0];
        $this->assertSame(['INV-' . $out['reference'], '20.00', $u, $c], [$trx['invoice'], $trx['price'], $trx['username'], (int) $trx['user_id']], 'retail invoice');
    }

    public function testRenewExtendsFromTheCurrentExpiry(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain();
        $c = $this->customer($leaf);
        $rid = $this->recharge($c, $plan, '2031-03-10', '12:00:00');
        $out = $this->activate($leaf, $c, $plan, 'RENEW');
        $this->assertSame(['COMPLETED', 'RENEW'], [$out['status'], $out['action']]);
        $this->assertSame('09 Apr 2031 12:00:00', $this->radcheck($this->username($c))['Expiration'], 'C14: extended from the current expiry');
        $act = $this->act($out['reference']);
        $this->assertSame($rid, (int) $act['legacy_recharge_id'], 'the current recharge row is updated in place');
        $this->assertSame(['2031-04-09', '12:00:00', 1], array_values(array_map(static fn($v) => is_numeric($v) && !str_contains((string) $v, '-') ? (int) $v : $v,
            static::$db->rows('SELECT expiration, time, (SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?) AS n FROM tbl_user_recharges WHERE id = ?', [$c, $rid])[0])));
        $this->assertNull($act['previous_plan_id']);
    }

    public function testReactivateAfterExpiryStartsNow(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain();
        $c = $this->customer($leaf);
        $this->recharge($c, $plan, '2020-01-01', '00:00:00', 'off');
        $out = $this->activate($leaf, $c, $plan, 'REACTIVATE');
        $this->assertSame(['COMPLETED', 'REACTIVATE'], [$out['status'], $out['action']]);
        $exp = \DateTimeImmutable::createFromFormat('d M Y H:i:s', $this->radcheck($this->username($c))['Expiration'], ActivationService::now()->getTimezone());
        $this->assertEqualsWithDelta(ActivationService::now()->modify('+30 day')->getTimestamp(), $exp->getTimestamp(), 120, 'C14: after expiry from now');
    }

    public function testChangePlanStartsNowAndRecordsThePreviousPlan(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain();
        $old = $this->radiusPlan('20');
        $c = $this->customer($leaf);
        $rid = $this->recharge($c, $old, '2031-03-10', '12:00:00');
        $out = $this->activate($leaf, $c, $plan, 'CHANGE_PLAN');
        $this->assertSame(['COMPLETED', 'CHANGE_PLAN'], [$out['status'], $out['action']]);
        $act = $this->act($out['reference']);
        $this->assertSame($old, (int) $act['previous_plan_id']);
        $exp = \DateTimeImmutable::createFromFormat('d M Y H:i:s', $this->radcheck($this->username($c))['Expiration'], ActivationService::now()->getTimezone());
        $this->assertEqualsWithDelta(ActivationService::now()->modify('+30 day')->getTimestamp(), $exp->getTimestamp(), 120, 'C14: a change starts now, no proration');
        $this->assertSame([$plan], array_map('intval', array_column(static::$db->rows('SELECT plan_id FROM tbl_user_recharges WHERE id = ?', [$rid]), 'plan_id')));
    }

    public function testTheActionIsServerDerivedAndAMismatchIsRefused(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain();
        $c = $this->customer($leaf);
        foreach (['RENEW', 'REACTIVATE', 'CHANGE_PLAN'] as $wrong) {
            $this->refused('ACTION_MISMATCH', fn() => $this->activate($leaf, $c, $plan, $wrong));
        }
        $this->recharge($c, $plan, '2031-01-01', '00:00:00');
        $this->refused('ACTION_MISMATCH', fn() => $this->activate($leaf, $c, $plan, 'NEW'), true);
    }
}
