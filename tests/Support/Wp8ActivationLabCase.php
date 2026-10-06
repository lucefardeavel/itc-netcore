<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp8LabHelpers.php';

use Reseller\Finance\PricingService;
use Reseller\Finance\TransferService;

/**
 * WP8 S4-S6 base: the accepted activation fixture (disposable application database, migrations, accepted seed, finance
 * and activation invariants after every test) with the application's 'radius' connection pointed at the WP8 lab RADIUS
 * database that the real FreeRADIUS serves to the real RouterOS CHR. All business objects are created through the
 * accepted services (FinanceSeed staff fixture, TransferService, PricingService, ActivationService). Lab only.
 */
abstract class Wp8ActivationLabCase extends ActivationTestCase
{
    use Wp8LabHelpers;

    protected const BW_A = ['name_bw' => 'nuxr_wp8_bw_256k_512k', 'rate_up' => 256, 'rate_up_unit' => 'Kbps', 'rate_down' => 512, 'rate_down_unit' => 'Kbps', 'burst' => ''];

    public static function setUpBeforeClass(): void
    {
        if (!Wp8Lab::enabled()) {
            return;
        }
        parent::setUpBeforeClass();
        // plan ids name the RADIUS groups (plan_<id>) in the shared lab RADIUS database: keep them unique per class
        static::$db->exec('ALTER TABLE tbl_plans AUTO_INCREMENT = ' . random_int(100000, 900000));
        \ORM::set_db(static::labRadius(), 'radius');
    }

    public static function tearDownAfterClass(): void
    {
        if (Wp8Lab::enabled()) {
            parent::tearDownAfterClass();
        }
    }

    protected function setUp(): void
    {
        $this->requireLab();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (Wp8Lab::enabled()) {
            $this->releaseClients();
            parent::tearDown();
        }
    }

    protected function assertPostConditions(): void
    {
        if (Wp8Lab::enabled()) {
            parent::assertPostConditions();
        }
    }

    protected function bandwidth(array $bw = self::BW_A): int
    {
        return static::$db->insertRow('tbl_bandwidth', ['name_bw' => $bw['name_bw'] . '_' . bin2hex(random_bytes(2))] + $bw);
    }

    /**
     * Provider/Admin -> Agent A -> Agent B -> Agent C -> Sales, funded A (provider), transfers A->B and B->C.
     * @return array{A:int,B:int,C:int,sales:int,sales2:int,tag:string}
     */
    protected function hierarchy(string $fund = '1000.00', string $toB = '300.00', string $toC = '100.00'): array
    {
        $tag = bin2hex(random_bytes(2));
        $a = FinanceSeed::staff(static::$db, "nuxr_wp8_agent_a_{$tag}", null, 'Active', true, 'Agent');
        $b = FinanceSeed::staff(static::$db, "nuxr_wp8_agent_b_{$tag}", $a, 'Active', true, 'Agent');
        $c = FinanceSeed::staff(static::$db, "nuxr_wp8_agent_c_{$tag}", $b, 'Active', true, 'Agent');
        $sales = FinanceSeed::staff(static::$db, "nuxr_wp8_sales_{$tag}", $c, 'Active', true, 'Sales');
        $sales2 = FinanceSeed::staff(static::$db, "nuxr_wp8_sales2_{$tag}", $c, 'Active', true, 'Sales');
        TransferService::fund($this->ctx('ad'), $a, $fund, 'wp8 provider funding', self::ref('F'));
        TransferService::transferToChild($this->ctx($a), $a, $b, $toB, 'wp8 A to B', self::ref('T'));
        TransferService::transferToChild($this->ctx($b), $b, $c, $toC, 'wp8 B to C', self::ref('T'));
        return ['A' => $a, 'B' => $b, 'C' => $c, 'sales' => $sales, 'sales2' => $sales2, 'tag' => $tag];
    }

    /**
     * A resellable PPPoE (or Hotspot) RADIUS plan with base cost and the wholesale chain A -> B -> C, each override set
     * by the authorized actor (Admin for the top level, then each parent for its direct child), and C's retail.
     * @param array{A:int,B:int,C:int} $h
     */
    protected function chainPlan(array $h, array $costs = ['10.00', '12.00', '15.00', '17.00'], ?string $retail = '22.00', string $type = 'PPPOE', array $extra = []): int
    {
        $plan = $this->radiusPlan('20', $type, 30, 'Days', $extra + ['id_bw' => $this->bandwidth(), 'pool' => $type === 'PPPOE' ? 'nuxr_wp8_pool_dyn' : '']);
        [$base, $a, $b, $c] = $costs;
        PricingService::setBaseCost($this->ctx('ad'), $plan, $base);
        PricingService::setResaleEnabled($this->ctx('ad'), $plan, true);
        PricingService::setChildWholesale($this->ctx('ad'), $h['A'], $plan, $a);
        PricingService::setChildWholesale($this->ctx($h['A']), $h['B'], $plan, $b);
        PricingService::setChildWholesale($this->ctx($h['B']), $h['C'], $plan, $c);
        if ($retail !== null) {
            PricingService::setRetail($this->ctx($h['C']), $h['C'], $plan, $retail);
        }
        return $plan;
    }

    /** a PPPoE (or Hotspot) customer owned by C and assigned to the Sales user, with a nuxr_wp8_ identity */
    protected function labCustomer(int $manager, ?int $sales, string $password, string $type = 'PPPoE', array $extra = []): int
    {
        $u = self::ident('cust');
        return $this->customer($manager, $sales, $extra + ['username' => $u, 'password' => $password, 'service_type' => $type,
            'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '']);
    }

    /** @return list<array<string,mixed>> ledger rows of an activation */
    protected function activationLedger(int $activationId): array
    {
        return static::$db->rows('SELECT id, entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, counterparty_manager_id FROM tbl_manager_ledger WHERE activation_id = ? ORDER BY id', [$activationId]);
    }

    /** @return list<array<string,mixed>> */
    protected function reservations(int $activationId): array
    {
        return static::$db->rows('SELECT id, manager_id, amount, status, committed_ledger_entry_id FROM tbl_manager_wallet_reservations WHERE activation_id = ? ORDER BY id', [$activationId]);
    }

    protected function latestActivation(int $customer): ?array
    {
        return static::$db->rows('SELECT * FROM tbl_reseller_activations WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$customer])[0] ?? null;
    }

    /** RADIUS policy of $user in the lab RADIUS database (read back through the application repository) */
    protected function policy(string $user): array
    {
        return (new \Reseller\Radius\RadiusRepository(static::labRadius()))->readUser($user);
    }
}
