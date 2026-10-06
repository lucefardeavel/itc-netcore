<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8ActivationLabCase.php';

use Reseller\Activation\ActivationFaults;
use Reseller\Activation\ActivationPreviewService;
use Reseller\Activation\ActivationReadService;
use Reseller\Domain\DomainError;
use Reseller\Finance\LedgerService;
use Reseller\Finance\Money;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\Wp8ActivationLabCase;
use Reseller\Tests\Support\Wp8Lab;

/**
 * WP8 S4 — the real reseller business flow end to end: Provider/Admin -> Agent A -> Agent B -> Agent C -> Sales ->
 * customer, built only through accepted services; a NEW activation by the Sales user through ActivationService; the
 * RADIUS policy it writes is served by the real FreeRADIUS to the real RouterOS CHR 7.24.5, where an independent PPPoE
 * client authenticates BEFORE settlement (observed at the accepted activation.before_commit point, nothing injected);
 * then the accepted settlement completes and the original ledger rows prove conservation:
 *     leaf debit (C, wholesale L) = commission(B) + commission(A) + provider settlement (P)
 */
final class ResellerBusinessFlowTest extends Wp8ActivationLabCase
{
    public function testAToBToCToSalesCustomerActivationOnTheRealNasConservesValue(): void
    {
        $h = $this->hierarchy();
        $balance0 = ['A' => $this->balance($h['A']), 'B' => $this->balance($h['B']), 'C' => $this->balance($h['C'])];
        $this->assertSame(['A' => '700.00', 'B' => '200.00', 'C' => '100.00'], $balance0, 'wallets after provider funding 1000, A->B 300, B->C 100');
        $plan = $this->chainPlan($h, ['10.00', '12.00', '15.00', '17.00'], '22.00');
        $cust = $this->labCustomer($h['C'], $h['sales'], 'S4-cust-pass');
        $user = $this->username($cust);
        $row = static::$db->rows('SELECT manager_id, assigned_sales_id FROM tbl_customers WHERE id = ?', [$cust])[0];
        $this->assertSame([$h['C'], $h['sales']], [(int) $row['manager_id'], (int) $row['assigned_sales_id']], 'customer owned by C, assigned to the Sales user');

        // Sales sees no wholesale / no wallet information
        $quote = ActivationPreviewService::quote($this->ctx($h['sales']), $cust, $plan, str_repeat('k', 40));
        $this->assertFalse($quote['full']);
        foreach (['wholesale', 'available_before', 'available_after', 'payer'] as $k) {
            $this->assertArrayNotHasKey($k, $quote, "Sales quote has no {$k}");
        }
        $this->assertSame('22.00', $quote['retail']);
        foreach (['A', 'B', 'C'] as $m) {
            try {
                WalletService::view($this->ctx($h['sales']), $h[$m]);
                $this->fail("Sales read wallet {$m}");
            } catch (DomainError $e) {
                $this->assertContains($e->reason, ['FORBIDDEN', 'NOT_FOUND']);
            }
        }

        // the real NAS is observed at the accepted pre-settlement point
        $seen = [];
        ActivationFaults::$hook = function (string $p) use (&$seen, $cust, $user, $h): void {
            if ($p !== 'activation.before_commit') {
                return;
            }
            ActivationFaults::$hook = null;
            $act = $this->latestActivation($cust);
            $seen['state'] = $act['status'];
            $seen['reservations'] = $this->reservations((int) $act['id']);
            $seen['ledger_before_settlement'] = $this->activationLedger((int) $act['id']);
            $seen['reserved_C'] = $this->reserved($h['C']);
            $seen['policy'] = $this->policy($user);
            $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'S4-cust-pass']);
            $seen['pppoe'] = $up;
            $seen['nas'] = Wp8Lab::pppActive($user);
            $seen['queue'] = Wp8Lab::queue('<pppoe-' . $user . '>')[0]['max-limit'] ?? null;
            $seen['start'] = Wp8Lab::until(fn() => $this->acct($user)[0] ?? null, 20, 1);
        };
        $nonce = self::nonce();
        $out = $this->activate($h['sales'], $cust, $plan, 'NEW', $nonce);
        $this->assertSame(['COMPLETED', 'NEW', false], [$out['status'], $out['action'], $out['replayed']], json_encode($out));
        $act = $this->act($out['reference']);
        $id = (int) $act['id'];

        // before settlement: provisioned + verified, funds reserved, nothing posted, the real session up
        $this->assertSame('FINANCIAL_COMMITTING', $seen['state']);
        $this->assertSame([[$h['C'], '17.00', 'ACTIVE']], array_map(static fn($r) => [(int) $r['manager_id'], $r['amount'], $r['status']], $seen['reservations']));
        $this->assertSame([], $seen['ledger_before_settlement'], 'no ledger leg before the settlement');
        $this->assertSame('17.00', $seen['reserved_C']);
        $this->assertSame('S4-cust-pass', $seen['policy']['check']['Cleartext-Password'] ?? null);
        $this->assertSame(['1', 'nuxr_wp8_pool_dyn', '256K/512K'], [$seen['policy']['check']['Simultaneous-Use'] ?? null, $seen['policy']['reply']['Framed-Pool'] ?? null, $seen['policy']['reply']['Mikrotik-Rate-Limit'] ?? null]);
        $this->assertSame(['plan_' . $plan], $seen['policy']['groups']);
        $this->assertTrue($seen['pppoe']['ok'], json_encode($seen['pppoe']['log']));
        $this->assertSame(['pppoe', 'true'], [$seen['nas'][0]['service'], $seen['nas'][0]['radius']]);
        $this->assertSame('256000/512000', $seen['queue']);
        $this->assertSame(['10.88.0.2', $seen['pppoe']['ip']], [$seen['start']['nasipaddress'], $seen['start']['framedipaddress']], 'accounting Start, NAS identity');

        // after: settlement from the original ledger rows
        $this->assertSame(['CREATED->VALIDATED', 'VALIDATED->FUNDS_RESERVED', 'FUNDS_RESERVED->PROVISIONING'], array_slice(array_map(static fn($e) => preg_replace('/^[A-Z_]+:|:.*$/', '', $e), $this->timeline($out['reference'])), 1, 3));
        $this->assertSame(['17.00', '22.00', '12.00'], [$act['wholesale_cost'], $act['retail_price'], $act['provider_settlement_amount']]);
        $legs = $this->activationLedger($id);
        $shape = array_map(static fn($l) => [(int) $l['manager_id'], $l['entry_type'], $l['direction'], $l['amount']], $legs);
        $this->assertEqualsCanonicalizing([[$h['C'], 'ACTIVATION_DEBIT', 'DEBIT', '17.00'], [$h['B'], 'COMMISSION_CREDIT', 'CREDIT', '2.00'], [$h['A'], 'COMMISSION_CREDIT', 'CREDIT', '3.00']], $shape);
        $this->assertCount(3, array_unique(array_column($legs, 'entry_key')), 'no duplicated leg');
        $debit = Money::fromDb(array_values(array_filter($legs, static fn($l) => $l['entry_type'] === 'ACTIVATION_DEBIT'))[0]['amount']);
        $commissions = array_reduce(array_filter($legs, static fn($l) => $l['entry_type'] === 'COMMISSION_CREDIT'), static fn(Money $s, $l) => $s->plus(Money::fromDb($l['amount'])), Money::zero());
        $provider = Money::fromDb($act['provider_settlement_amount']);
        $this->assertSame($debit->format(), $commissions->plus($provider)->format(), 'CONSERVATION: leaf debit = commissions + provider settlement');
        $res = $this->reservations($id);
        $this->assertSame([['COMMITTED', '17.00']], array_map(static fn($r) => [$r['status'], $r['amount']], $res));
        $this->assertSame((string) array_values(array_filter($legs, static fn($l) => $l['entry_type'] === 'ACTIVATION_DEBIT'))[0]['id'], (string) $res[0]['committed_ledger_entry_id']);
        $this->assertSame('0.00', $this->reserved($h['C']), 'no orphan reservation');
        $after = ['A' => $this->balance($h['A']), 'B' => $this->balance($h['B']), 'C' => $this->balance($h['C'])];
        $this->assertSame(['A' => '703.00', 'B' => '202.00', 'C' => '83.00'], $after);
        foreach (['A', 'B', 'C'] as $m) {
            $sum = array_reduce($this->ledger($h[$m]), static fn(Money $s, $l) => $l['direction'] === 'CREDIT' ? $s->plus(Money::fromDb($l['amount'])) : $s->minus(Money::fromDb($l['amount'])), Money::zero());
            $this->assertSame($after[$m], $sum->format(), "wallet {$m} = sum of its ledger rows");
            $this->assertSame([], LedgerService::continuity(static::$svc, $h[$m]), "ledger {$m}: continuous balance chain");
        }

        // replay: same idempotency key -> same activation, nothing posted twice
        $ledgerHash = md5(json_encode(static::$db->rows('SELECT * FROM tbl_manager_ledger ORDER BY id')));
        $again = $this->activate($h['sales'], $cust, $plan, 'NEW', $nonce);
        $this->assertSame([$out['reference'], true], [$again['reference'], $again['replayed']]);
        $this->assertSame($ledgerHash, md5(json_encode(static::$db->rows('SELECT * FROM tbl_manager_ledger ORDER BY id'))), 'no double settlement; ledger rows unchanged (immutable)');

        // Sales read model: no wholesale; Admin sees it
        $salesView = ActivationReadService::view($this->ctx($h['sales']), $out['reference'])['activation'];
        $this->assertArrayHasKey('wholesale', $salesView);
        $this->assertNull($salesView['wholesale'], 'Sales never receives wholesale');
        $this->assertSame('22.00', $salesView['retail'], 'Sales sees the retail value');
        $this->assertSame('17.00', ActivationReadService::view($this->ctx('ad'), $out['reference'])['activation']['wholesale'], 'Admin sees the sealed wholesale');
        $this->assertSame('17.00', ActivationReadService::view($this->ctx($h['C']), $out['reference'])['activation']['wholesale'], 'the paying Agent sees its own wholesale');

        // Sales scope: a customer assigned to another Sales user of the same Agent is refused, nothing written
        $other = $this->labCustomer($h['C'], $h['sales2'], 'S4-other-pass');
        $count = static fn() => (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations');
        $n = $count();
        try {
            $this->activate($h['sales'], $other, $plan, 'NEW');
            $this->fail('Sales activated a customer outside its assignment');
        } catch (DomainError $e) {
            $refusal = $e->reason;
        }
        $this->assertSame($n, $count(), 'no activation row');
        $this->assertSame([], $this->policy($this->username($other))['check'], 'no RADIUS subscriber for the refused customer');

        Wp8Lab::evidence('S4.business-flow', [
            'hierarchy' => ['A' => $h['A'], 'B' => $h['B'], 'C' => $h['C'], 'sales' => $h['sales']],
            'wallets_before' => $balance0, 'wallets_after' => $after,
            'pricing' => ['base' => '10.00', 'A' => '12.00', 'B' => '15.00', 'C (leaf wholesale L)' => '17.00', 'retail C' => '22.00'],
            'activation' => ['reference' => $out['reference'], 'status' => $out['status'], 'wholesale' => $act['wholesale_cost'], 'retail' => $act['retail_price'], 'provider_settlement' => $act['provider_settlement_amount']],
            'pre_settlement' => ['state' => $seen['state'], 'reservation' => $seen['reservations'], 'ledger' => $seen['ledger_before_settlement'], 'pppoe_ip' => $seen['pppoe']['ip'], 'nas' => $seen['nas'][0], 'queue' => $seen['queue'], 'start' => $seen['start']],
            'ledger_legs' => $legs, 'conservation' => sprintf('%s = %s + %s', $debit->format(), $commissions->format(), $provider->format()),
            'reservation_after' => $res, 'replay' => $again, 'sales_scope_refusal' => $refusal, 'timeline' => $this->timeline($out['reference'])]);
    }
}
