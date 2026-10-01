<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\PricingService;
use Reseller\Finance\PricingSnapshot;
use Reseller\Tests\Support\PricingTestCase;

/** WP3B §6 / §17 / F-8: authoritative resolution, sealed snapshot, immutability, role-filtered quotes. */
final class ResolutionSnapshotTest extends PricingTestCase
{
    private const COLS = 'wholesale_cost, retail_price, provider_settlement_amount, plan_snapshot, pricing_chain_snapshot';

    public function testSealedSnapshotIsCompleteAndReadsBackByteIdentical(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$r1, $r2, $r3] = $this->chain($plan, ['12', null, '17']);
        PricingService::setRetail($this->ctx($r3), $r3, $plan, '25');
        $act = $this->sealedActivation($r3, $plan);
        $row = static::$db->rows('SELECT * FROM tbl_reseller_activations WHERE id = ?', [$act])[0];
        $s = PricingSnapshot::fromActivationRow($row);
        $this->assertSame($s->chainJson(), $row['pricing_chain_snapshot'], 'stored byte-identical (LONGTEXT, canonical)');
        $d = json_decode($row['pricing_chain_snapshot'], true);
        $this->assertSame([$plan, $r3], [$d['plan_id'], $d['leaf_manager_id']]);
        $this->assertSame([$r1, $r2, $r3], array_column($d['chain'], 'manager_id'));
        $this->assertSame([2, 1, 0], array_column($d['chain'], 'distance'));
        $this->assertSame(['OVERRIDE', 'INHERITED', 'OVERRIDE'], array_column($d['chain'], 'source'));
        $this->assertSame([$r1, $r1, $r3], array_column($d['chain'], 'source_manager_id'));
        $this->assertSame([$this->sid('ad'), $this->sid('ad'), $r2], array_column($d['chain'], 'set_by'), 'provenance of the applied override');
        $this->assertSame(['0.00', '5.00', '0.00'], array_column($d['chain'], 'margin'));
        $this->assertSame(['10.00', true, $this->sid('ad')], [$d['provider']['base_cost'], $d['provider']['resale_enabled'], $d['provider']['updated_by']]);
        $this->assertSame(['25.00', 'OVERRIDE', '100', $r3], [$d['retail']['effective'], $d['retail']['source'], $d['retail']['legacy_raw'], $d['retail']['set_by']]);
        $this->assertSame(['17.00', [['manager_id' => $r2, 'amount' => '5.00']], '12.00', '2.00'],
            [$d['settlement']['leaf_debit'], $d['settlement']['commissions'], $d['settlement']['provider_settlement'], $d['settlement']['provider_margin_over_base']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $d['resolved_at']);
        $this->assertSame(['17.00', '25.00', '12.00'], [$row['wholesale_cost'], $row['retail_price'], $row['provider_settlement_amount']]);
        $plan_ = json_decode($row['plan_snapshot'], true);
        $this->assertSame([(string) $plan, '100', 'Hotspot'], [$plan_['id'], $plan_['price'], $plan_['type']]);
        $this->assertArrayNotHasKey('on_login', $plan_);
        $this->assertArrayNotHasKey('price_old', $plan_);
    }

    /** Resolve at T1, change everything at T2, T1 stays byte-identical, T3 resolves the new values. */
    public function testHistoricalSnapshotSurvivesEveryLaterChange(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$r1, $r2, $r3] = $this->chain($plan, ['12', '15', '17']);
        $other = $this->funded('100');
        $t1 = $this->sealedActivation($r3, $plan);
        $before = static::$db->rows('SELECT ' . self::COLS . ' FROM tbl_reseller_activations WHERE id = ?', [$t1])[0];
        // T2: provider price, ancestor prices, retail, availability and hierarchy placement change
        PricingService::setBaseCost($this->ctx('ad'), $plan, '9');
        PricingService::setChildWholesale($this->ctx('ad'), $r1, $plan, '11');
        PricingService::setChildWholesale($this->ctx($r1), $r2, $plan, '14');
        PricingService::setRetail($this->ctx($r3), $r3, $plan, '40');
        PricingService::setChildAvailability($this->ctx($r2), $r3, $plan, true);
        PricingService::setChildWholesale($this->ctx('ad'), $other, $plan, '11.50');
        ResellerTreeService::moveSubtree($this->ctx('ad'), $r3, $other);
        $this->assertSame($before, static::$db->rows('SELECT ' . self::COLS . ' FROM tbl_reseller_activations WHERE id = ?', [$t1])[0], 'T1 snapshot unchanged');
        $old = $this->snapshotOf($t1);
        $this->assertSame([$r1, $r2, $r3], array_map(static fn($l) => $l->managerId, $old->chain), 'historical chain kept');
        // T3: a new resolution uses the current state (new parent, new prices, explicit retail)
        $t3 = FinanceTx::run(static fn(\PDO $pdo) => PricingService::resolveInTx($pdo, $r3, $plan), true);
        $this->assertSame([$other, $r3], array_map(static fn($l) => $l->managerId, $t3->chain));
        $this->assertSame(['17.00', '11.50', '40.00', '9.00'], [$t3->leafDebit->format(), $t3->providerSettlement->format(), $t3->retail->format(), $t3->baseCost->format()]);
        $this->assertFalse($t3->sameTermsAs($old));
    }

    public function testResolutionRequiresTheTreeLockAndATransaction(): void
    {
        $plan = $this->plan();
        $this->base($plan, '10');
        [$t] = $this->chain($plan, [null]);
        $this->refused('LOCK_REQUIRED', fn() => Tx::run(static fn(\PDO $pdo) => PricingService::resolveInTx($pdo, $t, $plan)));
        try {
            PricingService::resolveInTx(static::$svc, $t, $plan);
            $this->fail('outside a transaction');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->refused('NOT_FOUND', fn() => FinanceTx::run(static fn(\PDO $pdo) => PricingService::resolveInTx($pdo, 0, $plan), true));
        $this->refused('NOT_FOUND', fn() => FinanceTx::run(static fn(\PDO $pdo) => PricingService::resolveInTx($pdo, $t, 99999999), true));
        $this->refused('WALLET_OWNER_INVALID', fn() => FinanceTx::run(fn(\PDO $pdo) => PricingService::resolveInTx($pdo, $this->sid('A1'), $plan), true), 'Sales is never a pricing leaf');
    }

    public function testQuotesAreScopedAndRoleFiltered(): void
    {
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$r1, $r2, $r3] = $this->chain($plan, ['12', '15', '17']);
        $other = $this->funded('10');
        $admin = PricingService::quote($this->ctx('ad'), $r3, $plan);
        $this->assertSame(['10.00', '12.00'], [$admin['base_cost'], $admin['provider_settlement']]);
        $agent = PricingService::quote($this->ctx($r2), $r3, $plan);
        $this->assertSame([$r2, $r3], array_column($agent['chain'], 'manager_id'));
        $json = json_encode($agent);
        foreach (['base_cost', 'provider_settlement', 'resale_enabled', '"10.00"', '"12.00"', (string) $r1] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json, "Agent view leaks {$hidden}");
        }
        $own = PricingService::quote($this->ctx($r3), $r3, $plan);
        $this->assertSame(['17.00', 'OVERRIDE', true], [$own['effective_wholesale'], $own['wholesale_source'], $own['saleable']]);
        $this->refused('NOT_FOUND', fn() => PricingService::quote($this->ctx($r3), $r2, $plan), 'never upwards');
        $this->refused('NOT_FOUND', fn() => PricingService::quote($this->ctx($r3), $other, $plan), 'unrelated tree');
        foreach (['A1', 'rp'] as $u) {
            $this->refused('FORBIDDEN', fn() => PricingService::quote($this->ctx($u), $r3, $plan));
        }
        $this->refused('FORBIDDEN', fn() => PricingService::validateTree($this->ctx($r1), $plan), 'diagnostics are administrative');
        $diag = PricingService::validateTree($this->ctx('ad'), $plan);
        $this->assertSame([], $diag['plan_reasons']);
        $this->assertGreaterThanOrEqual(4, count($diag['managers']));
    }
}
