<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\FinanceActor;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\Money;
use Reseller\Finance\PricingService;
use Reseller\Finance\PricingSnapshot;
use Reseller\Finance\ReservationResult;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;

/**
 * Base class for WP3B pricing / settlement integration tests (same disposable database and invariants
 * as FinanceTestCase). Adds fresh plans, priced reseller chains and "sealed" activations: the fixture
 * mimics the future WP5A ActivationService (resolveInTx under the tree lock, then ONE activation INSERT
 * naming the five snapshot columns), because WP3B implements no activation state machine.
 */
abstract class PricingTestCase extends FinanceTestCase
{
    /** A fresh legacy plan (copy of the seeded Hotspot plan) with the given legacy price and type. */
    protected function plan(string $price = '1000', string $type = 'Hotspot'): int
    {
        $seed = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seed['id']);
        $seed['name_plan'] = 'wp3b_' . bin2hex(random_bytes(4));
        $seed['price'] = $price;
        $seed['type'] = $type;
        if ($type === 'Balance') {
            $seed['validity'] = 0;
            $seed['routers'] = '';
        }
        return static::$db->insertRow('tbl_plans', $seed);
    }

    /** Provider base cost (Admin) and, by default, resale enabled. */
    protected function base(int $plan, string $cost, bool $enable = true): void
    {
        PricingService::setBaseCost($this->ctx('ad'), $plan, $cost);
        if ($enable) {
            PricingService::setResaleEnabled($this->ctx('ad'), $plan, true);
        }
    }

    /**
     * A reseller chain top-level -> leaf; each element is a wholesale override (or null = inherit) set
     * by its parent (Admin for the top level). Every reseller is funded with $fund (wallet exists).
     *
     * @param list<string|null> $costs
     * @return list<int> manager ids, top-level first
     */
    protected function chain(int $plan, array $costs, ?string $fund = '1000'): array
    {
        $ids = [];
        $parent = null;
        foreach ($costs as $cost) {
            $id = $fund !== null ? $this->funded($fund, $parent) : $this->agent($parent);
            if ($cost !== null) {
                PricingService::setChildWholesale($this->ctx($parent ?? 'ad'), $id, $plan, $cost);
            }
            $ids[] = $id;
            $parent = $id;
        }
        return $ids;
    }

    /** Resolve under the tree lock and insert the activation with the sealed snapshot (WP5A fixture). */
    protected function sealedActivation(int $manager, int $plan, string $customer = 'cA0'): int
    {
        return FinanceTx::run(function (\PDO $pdo) use ($manager, $plan, $customer): int {
            $snap = PricingService::resolveInTx($pdo, $manager, $plan);
            return $this->insertActivation($pdo, $manager, $plan, $snap->activationColumns(), $customer);
        }, true);
    }

    /** @param array<string,string|null> $cols */
    protected function insertActivation(\PDO $pdo, int $manager, int $plan, array $cols, string $customer = 'cA0'): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $ref = 'ACT-' . bin2hex(random_bytes(8));
        $row = [
            'reference' => $ref, 'idempotency_key' => 'idem-' . $ref, 'action' => 'NEW', 'status' => 'VALIDATED',
            'channel' => 'STAFF_WEB', 'manager_id' => $manager, 'actor_type' => 'STAFF', 'actor_user_id' => $this->sid('ad'),
            'customer_id' => $this->cid($customer), 'plan_id' => $plan, 'created_at' => $now, 'updated_at' => $now,
        ] + $cols;
        $st = $pdo->prepare('INSERT INTO tbl_reseller_activations (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
        $st->execute(array_values($row));
        return (int) $pdo->lastInsertId();
    }

    protected function snapshotOf(int $activation): PricingSnapshot
    {
        return PricingSnapshot::fromActivationRow(static::$db->rows('SELECT * FROM tbl_reseller_activations WHERE id = ?', [$activation])[0]);
    }

    protected function reserveSealed(int $activation): ReservationResult
    {
        $a = static::$db->rows('SELECT manager_id, wholesale_cost FROM tbl_reseller_activations WHERE id = ?', [$activation])[0];
        return ReservationService::reserve($activation, (int) $a['manager_id'], Money::parsePositive($a['wholesale_cost']));
    }

    /** sealed activation + reserve + commit; returns [activation id, commit result]. @return array{0:int,1:ReservationResult} */
    protected function settle(int $manager, int $plan, ?FinanceActor $actor = null): array
    {
        $act = $this->sealedActivation($manager, $plan);
        $r = $this->reserveSealed($act);
        return [$act, ReservationService::commit($r->reservationId, $actor ?? FinanceActor::system('wp3b-activation'))];
    }

    /** @return array<int,string> manager_id => commission amount of one activation */
    protected function commissions(int $activation): array
    {
        $out = [];
        foreach (static::$db->rows("SELECT manager_id, amount FROM tbl_manager_ledger WHERE activation_id = ? AND entry_type = 'COMMISSION_CREDIT' ORDER BY manager_id", [$activation]) as $r) {
            $out[(int) $r['manager_id']] = $r['amount'];
        }
        return $out;
    }

    protected function fund(int $manager, string $amount): void
    {
        TransferService::fund($this->ctx('ad'), $manager, $amount, 'test funding', self::ref('F'));
    }

    /** @return array<string,mixed>|null */
    protected function priceRow(int $manager, int $plan): ?array
    {
        return static::$db->rows('SELECT * FROM tbl_manager_plan_prices WHERE manager_id = ? AND plan_id = ?', [$manager, $plan])[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    protected function audit(string $event): array
    {
        return static::$db->rows('SELECT * FROM tbl_reseller_audit_events WHERE event_type = ? ORDER BY id', [$event]);
    }
}
