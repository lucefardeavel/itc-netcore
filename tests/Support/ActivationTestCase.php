<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Activation\ActivationService;
use Reseller\Activation\ActivationState;
use Reseller\Finance\FinanceFaults;
use Reseller\Radius\RadiusFaults;

/**
 * WP5A activation tests: the WP3B pricing database (migrated V2, Wp2Seed + FinanceSeed) plus the FreeRADIUS
 * schema in the same database, with the ORM 'radius' connection on it (the installer's layout).
 *
 * After every test: tbl_customers is unchanged by activation (the baseline is re-taken only when a test
 * itself creates a customer), the WP3A/WP3B financial invariants hold, and every activation is consistent:
 * contiguous event sequence starting at CREATED, only allowed transitions, and the reservation state that
 * matches the activation state (COMPLETED <-> COMMITTED; FAILED / CANCELLED / ROLLED_BACK <-> RELEASED;
 * every other state <-> ACTIVE).
 */
abstract class ActivationTestCase extends PricingTestCase
{
    private string $customersBaseline = '';
    private static string|false $errorLog = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        (new FixtureBuilder(dirname(__DIR__, 2)))->loadSqlFile(static::$db->pdo, dirname(__DIR__, 2) . '/install/radius.sql');
        static::$db->strictSession();
        \ORM::set_db(static::$svc, 'radius');
        ActivationFaults::$hook = null;
        RadiusFaults::$hook = null;
        self::$errorLog = ini_set('error_log', '/dev/null');
    }

    public static function tearDownAfterClass(): void
    {
        ActivationFaults::$hook = null;
        RadiusFaults::$hook = null;
        if (self::$errorLog !== false) {
            ini_set('error_log', (string) self::$errorLog);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        ActivationFaults::$hook = null;
        RadiusFaults::$hook = null;
        $this->customersBaseline = $this->customersChecksum();
    }

    protected function assertPostConditions(): void
    {
        FinanceFaults::$hook = null;
        ActivationFaults::$hook = null;
        RadiusFaults::$hook = null;
        $this->assertSame($this->customersBaseline, $this->customersChecksum(), 'tbl_customers must never change through activation');
        $this->assertSame([], FinanceInvariants::check(static::$db), 'financial invariants');
        $this->assertSame([], self::activationProblems(), 'activation invariants');
    }

    /** @return list<string> */
    protected static function activationProblems(): array
    {
        $problems = [];
        foreach (static::$db->rows('SELECT * FROM tbl_reseller_activations ORDER BY id') as $a) {
            $events = static::$db->rows('SELECT * FROM tbl_reseller_activation_events WHERE activation_id = ? ORDER BY sequence_no', [$a['id']]);
            $tag = $a['reference'] . ' ';
            if ($events === [] || $events[0]['to_state'] !== 'CREATED' || $events[0]['from_state'] !== null) {
                $problems[] = $tag . 'timeline does not start at CREATED';
            }
            $state = null;
            foreach ($events as $i => $e) {
                if ((int) $e['sequence_no'] !== $i + 1) {
                    $problems[] = $tag . 'sequence gap at ' . $e['sequence_no'];
                }
                if ($i > 0 && $e['from_state'] !== $state) {
                    $problems[] = $tag . 'event ' . $e['sequence_no'] . ' does not continue from ' . $state;
                }
                if ($i > 0 && $e['from_state'] !== $e['to_state'] && !ActivationState::allowed((string) $e['from_state'], (string) $e['to_state'])) {
                    $problems[] = $tag . 'forbidden transition ' . $e['from_state'] . ' -> ' . $e['to_state'];
                }
                $state = $e['to_state'];
            }
            if ($state !== $a['status']) {
                $problems[] = $tag . 'status ' . $a['status'] . ' differs from the timeline ' . $state;
            }
            $res = static::$db->rows('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [$a['id']]);
            $statuses = array_column($res, 'status');
            $want = match ($a['status']) {
                // WP5B: a reversal keeps the COMMITTED reservation; the ledger REVERSAL entries undo its effects
                'COMPLETED', 'REVERSAL_PENDING', 'REVERSED' => ['COMMITTED'],
                'FAILED', 'CANCELLED', 'ROLLED_BACK' => ['RELEASED'],
                'CREATED', 'VALIDATED' => [],
                default => ['ACTIVE'],
            };
            if ($statuses !== $want) {
                $problems[] = $tag . $a['status'] . ' with reservations ' . json_encode($statuses);
            }
            if (in_array($a['status'], ['COMPLETED', 'REVERSAL_PENDING', 'REVERSED'], true) && ($a['legacy_recharge_id'] === null || $a['legacy_transaction_id'] === null || $a['legacy_invoice'] !== 'INV-' . $a['reference'])) {
                $problems[] = $tag . 'COMPLETED without its legacy records';
            }
        }
        return $problems;
    }

    /** A FreeRADIUS-device plan (copy of the seeded plan) with the given validity. */
    protected function radiusPlan(string $price = '1000', string $type = 'Hotspot', int $validity = 30, string $unit = 'Days', array $extra = []): int
    {
        $id = $this->plan($price, $type);
        static::$db->exec("UPDATE tbl_plans SET device = 'Radius', is_radius = 1, routers = 'radius', enabled = 1, validity = {$validity}, validity_unit = '{$unit}' WHERE id = {$id}");
        foreach ($extra as $col => $value) {
            static::$db->pdo->prepare("UPDATE tbl_plans SET {$col} = ? WHERE id = ?")->execute([$value, $id]);
        }
        return $id;
    }

    /** A priced plan and a funded reseller chain; returns [plan id, manager ids top-level first]. */
    protected function pricedChain(array $costs = ['12', '15'], string $base = '10', string $legacyPrice = '20', string $type = 'Hotspot'): array
    {
        $plan = $this->radiusPlan($legacyPrice, $type);
        $this->base($plan, $base);
        return [$plan, $this->chain($plan, $costs)];
    }

    /** A reseller-owned customer (re-takes the tbl_customers baseline: the test created it). */
    protected function customer(?int $managerId, ?int $salesId = null, array $extra = []): int
    {
        $u = 'c5a' . bin2hex(random_bytes(4));
        $id = static::$db->insertRow('tbl_customers', $extra + [
            'username' => $u, 'password' => 'pw-' . $u, 'fullname' => 'Customer ' . $u, 'address' => '', 'phonenumber' => '0700',
            'email' => $u . '@example.invalid', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active',
            'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0, 'manager_id' => $managerId, 'assigned_sales_id' => $salesId,
        ]);
        $this->customersBaseline = $this->customersChecksum();
        return $id;
    }

    /** A legacy recharge row (test fixture for RENEW / REACTIVATE / CHANGE_PLAN). */
    protected function recharge(int $customer, int $plan, string $expiration, string $time, string $status = 'on', string $type = 'Hotspot'): int
    {
        $c = static::$db->rows('SELECT username FROM tbl_customers WHERE id = ?', [$customer])[0];
        return static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => $customer, 'username' => $c['username'], 'plan_id' => $plan, 'namebp' => 'fixture', 'recharged_on' => '2026-01-01',
            'recharged_time' => '00:00:00', 'expiration' => $expiration, 'time' => $time, 'status' => $status, 'method' => 'fixture',
            'routers' => 'radius', 'type' => $type, 'admin_id' => 1,
        ]);
    }

    protected static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return array{reference:string,status:string,action:string,replayed:bool,error_code:?string,error_message:?string} */
    protected function activate(string|int $actor, int $customer, int $plan, string $action, ?string $nonce = null): array
    {
        return ActivationService::create($this->ctx($actor), $customer, $plan, $action, $nonce ?? self::nonce());
    }

    /** @return array<string,mixed> */
    protected function act(string $reference): array
    {
        return static::$db->rows('SELECT * FROM tbl_reseller_activations WHERE reference = ?', [$reference])[0];
    }

    /** @return list<string> "EVENT_TYPE:from->to[:result]" */
    protected function timeline(string $reference): array
    {
        return array_map(static fn(array $e): string => $e['event_type'] . ':' . ($e['from_state'] ?? '-') . '->' . $e['to_state'] . ($e['provisioning_result'] !== null ? ':' . $e['provisioning_result'] : ''),
            static::$db->rows('SELECT e.* FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ? ORDER BY e.sequence_no', [$reference]));
    }

    /** @return array<string,string> radcheck attribute => value */
    protected function radcheck(string $username): array
    {
        $out = [];
        foreach (static::$db->rows('SELECT attribute, value FROM radcheck WHERE username = ? ORDER BY id', [$username]) as $r) {
            $out[$r['attribute']] = $r['value'];
        }
        return $out;
    }

    /** Sum of the ACTIVE reservations of a manager. */
    protected function reserved(int $manager): string
    {
        return (string) static::$db->value("SELECT COALESCE(SUM(amount), 0.00) FROM tbl_manager_wallet_reservations WHERE manager_id = ? AND status = 'ACTIVE'", [$manager]);
    }

    protected function expectExceptionReason(string $reason, callable $fn, ?string $label = null): void
    {
        try {
            $fn();
            $this->fail('expected ' . $reason . ($label ? ': ' . $label : ''));
        } catch (\Reseller\Domain\DomainError $e) {
            $this->assertSame($reason, $e->reason, $label ?? '');
        }
    }

    protected function username(int $customer): string
    {
        return (string) static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$customer]);
    }
}
