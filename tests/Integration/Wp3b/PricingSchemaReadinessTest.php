<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Domain\DomainError;
use Reseller\Domain\SchemaReadiness;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\FinanceFaults;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\PricingService;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;
use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\PricingTestCase;

/**
 * WP3B §21: the pricing tables are runtime authorities. Each one missing makes the reseller schema
 * NOT READY: every pricing, finance and tree capability is refused for every role (no admin bypass),
 * and pricing / settlement operations are refused with SCHEMA_NOT_READY before any SQL business work.
 * EXPECTED stays R0001–R0006. Restoring the table restores readiness and real operations.
 */
final class PricingSchemaReadinessTest extends PricingTestCase
{
    private const OFFLINE = '_wp3b_off';
    private const CAPS = [C::PRICING_READ, C::PRICING_BASE_COST, C::PRICING_CHILD_WHOLESALE, C::PRICING_OWN_RETAIL, C::PRICING_CHILD_AVAILABILITY, C::WALLET_READ, C::WALLET_FUND_WITHDRAW, C::RESELLER_TREE_READ];

    /** @return array<string,array{0:string}> */
    public static function pricingTables(): array
    {
        return ['tbl_reseller_plan_base_costs' => ['tbl_reseller_plan_base_costs'], 'tbl_manager_plan_prices' => ['tbl_manager_plan_prices']];
    }

    private static function resetReadiness(): void
    {
        SchemaReadiness::reset();
        ActorContext::reset();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::pricingTables()) as $t) {
            if ((int) static::$db->value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t . self::OFFLINE]) === 1) {
                static::$db->exec("RENAME TABLE `{$t}" . self::OFFLINE . "` TO `{$t}`");
            }
        }
        self::resetReadiness();
        parent::tearDown();
    }

    public function testExpectedIsR0001ToR0008(): void
    {
        $this->assertSame(['R0001_manager_tree', 'R0002_pricing_foundation', 'R0003_activation_foundation',
            'R0004_wallet_finance_foundation', 'R0005_customer_ownership', 'R0006_reseller_audit',
            // WP5B (owner decision OD-14 option B): the activation reversal states
            'R0007_activation_reversal_states',
            // V1.0.1 (owner decision 2026-11-09): username-and-password cards
            'R0008_voucher_logins'], SchemaReadiness::EXPECTED);
        self::resetReadiness();
        $this->assertSame(['ready' => true, 'problems' => []], SchemaReadiness::status());
    }

    #[DataProvider('pricingTables')]
    public function testMissingPricingTableFailsClosedAndRecovers(string $table): void
    {
        self::resetReadiness();
        $plan = $this->plan('100');
        $this->base($plan, '10');
        [$top, $leaf] = $this->chain($plan, ['12', '15'], '100');
        PricingService::setRetail($this->ctx($leaf), $leaf, $plan, '20'); // a row in tbl_manager_plan_prices
        $sealed = $this->sealedActivation($leaf, $plan);
        $reserved = $this->reserveSealed($this->sealedActivation($leaf, $plan));

        static::$db->exec("RENAME TABLE `{$table}` TO `{$table}" . self::OFFLINE . '`');
        try {
            self::resetReadiness();
            $this->assertSame(['ready' => false, 'problems' => ["table {$table} missing"]], SchemaReadiness::status());
            $actors = ['sa' => $this->ctx('sa'), 'ad' => $this->ctx('ad'), 'top' => $this->ctx($top), 'leaf' => $this->ctx($leaf)];
            foreach ($actors as $label => $a) {
                $this->assertFalse($a->schemaReady);
                foreach (self::CAPS as $cap) {
                    $this->assertSame(PermissionService::NONE, PermissionService::scopeOf($a, $cap), "{$label}: {$cap} (no admin bypass)");
                }
            }
            $reached = [];
            FinanceFaults::$hook = static function (string $p) use (&$reached): void {
                $reached[] = $p;
            };
            $before = $this->checksums();
            $calls = [
                'base cost (SuperAdmin)' => fn() => PricingService::setBaseCost($actors['sa'], $plan, '9'),
                'resale (Admin)' => fn() => PricingService::setResaleEnabled($actors['ad'], $plan, false),
                'wholesale (Agent)' => fn() => PricingService::setChildWholesale($actors['top'], $leaf, $plan, '16'),
                'wholesale (Admin)' => fn() => PricingService::setChildWholesale($actors['ad'], $leaf, $plan, '16'),
                'retail (Agent)' => fn() => PricingService::setRetail($actors['leaf'], $leaf, $plan, '21'),
                'availability (Admin)' => fn() => PricingService::setChildAvailability($actors['ad'], $leaf, $plan, false),
                'quote (Admin)' => fn() => PricingService::quote($actors['ad'], $leaf, $plan),
                'validate (Admin)' => fn() => PricingService::validateTree($actors['ad'], $plan),
                'resolve (internal)' => fn() => FinanceTx::run(static fn(\PDO $pdo) => PricingService::resolveInTx($pdo, $leaf, $plan), true),
                'reserve sealed' => fn() => $this->reserveSealed($sealed),
                'commit settlement' => fn() => ReservationService::commit($reserved->reservationId, FinanceActor::system('wp3b-activation')),
                'funding (Admin)' => fn() => TransferService::fund($actors['ad'], $leaf, '1', 'blocked funding', self::ref()),
            ];
            foreach ($calls as $label => $fn) {
                try {
                    $fn();
                    $this->fail("{$label} must be refused");
                } catch (DomainError $e) {
                    $this->assertSame('SCHEMA_NOT_READY', $e->reason, $label);
                }
            }
            FinanceFaults::$hook = null;
            $this->assertSame([], $reached, 'no pricing / settlement step was reached');
            $this->assertSame([], DbProbe::changed($before, $this->checksums()), 'nothing written');
        } finally {
            FinanceFaults::$hook = null;
            static::$db->exec("RENAME TABLE `{$table}" . self::OFFLINE . "` TO `{$table}`");
        }
        self::resetReadiness();
        $this->assertSame(['ready' => true, 'problems' => []], SchemaReadiness::status());
        // real operations over the restored table and its foreign keys
        $this->assertTrue(PricingService::setChildWholesale($this->ctx($top), $leaf, $plan, '15.50')->changed);
        $this->assertTrue(PricingService::setRetail($this->ctx($leaf), $leaf, $plan, '21')->changed);
        $ok = ReservationService::commit($reserved->reservationId, FinanceActor::system('wp3b-activation'));
        $this->assertSame('COMMITTED', $ok->status);
        $this->assertSame([$top => '3.00'], $this->commissions($ok->activationId));
    }
}
