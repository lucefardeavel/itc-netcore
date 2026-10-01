<?php
namespace Reseller\Tests\Integration;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PDOException;
use Reseller\Migration\Outcome;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;
use Reseller\Tests\Support\TestDatabase;

/**
 * T15 and owner addition 5: the structural foundation enforces what it promises.
 * Rows are written directly here ONLY to prove constraints in a throwaway database; WP1 ships no
 * service that writes these tables.
 */
final class ConstraintTest extends IntegrationTestCase
{
    public const STATES = [
        'CREATED', 'VALIDATED', 'FUNDS_RESERVED', 'PROVISIONING', 'PROVISIONED', 'VERIFYING', 'VERIFIED',
        'FINANCIAL_COMMITTING', 'COMPLETED', 'FAILED', 'RETRY_PENDING', 'RECONCILIATION_REQUIRED',
        'ROLLBACK_PENDING', 'ROLLED_BACK', 'CANCELLED',
    ];

    private TestDatabase $db;
    private int $admin;
    private int $agent;
    private int $child;
    private int $customer;
    private int $customer2;
    private int $plan;
    private int $plan2;

    protected function setUp(): void
    {
        $this->db = $this->newDatabase(FixtureBuilder::V2);
        $this->assertOutcome(Outcome::OK, $this->run_($this->db, 'migrate'));
        $this->admin = $this->db->insertRow('tbl_users', ['username' => 'admin_c', 'user_type' => 'SuperAdmin', 'status' => 'Active']);
        $this->agent = $this->db->insertRow('tbl_users', ['username' => 'agent_c', 'user_type' => 'Agent', 'status' => 'Active']);
        $this->child = $this->db->insertRow('tbl_users', ['username' => 'child_c', 'user_type' => 'Agent', 'status' => 'Active']);
        $this->customer = $this->db->insertRow('tbl_customers', ['username' => 'cust_c', 'password' => 'p', 'fullname' => 'c', 'balance' => 0]);
        $this->customer2 = $this->db->insertRow('tbl_customers', ['username' => 'cust_c2', 'password' => 'p', 'fullname' => 'c2', 'balance' => 0]);
        $this->plan = $this->db->insertRow('tbl_plans', ['name_plan' => 'p1', 'price' => '1000', 'type' => 'Hotspot']);
        $this->plan2 = $this->db->insertRow('tbl_plans', ['name_plan' => 'p2', 'price' => '2000', 'type' => 'Hotspot']);
    }

    private function rejects(string $sql, string $why): void
    {
        try {
            $this->db->exec($sql);
        } catch (PDOException $e) {
            // a constraint/data rejection, never a syntax or unknown-object error (which would hide a broken test)
            $this->assertStringStartsNotWith('42', (string) $e->errorInfo[0], $why . ': unexpected error class: ' . $e->getMessage());
            return;
        }
        $this->fail("expected rejection: {$why}\n{$sql}");
    }

    private function now(): string
    {
        return 'UTC_TIMESTAMP(6)';
    }

    private function activation(string $key, string $action = 'NEW', ?int $previous = null): int
    {
        $prev = $previous === null ? 'NULL' : (string) $previous;
        $this->db->exec("INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, actor_user_id, customer_id, plan_id, previous_plan_id, plan_snapshot, pricing_chain_snapshot, created_at, updated_at)
            VALUES ('ref-{$key}', '{$key}', '{$action}', 'CREATED', 'STAFF_WEB', {$this->agent}, 'STAFF', {$this->agent}, {$this->customer}, {$this->plan}, {$prev}, '{\"plan\":1}', '[{\"m\":1}]', {$this->now()}, {$this->now()})");
        return (int) $this->db->value("SELECT id FROM tbl_reseller_activations WHERE idempotency_key = ?", [$key]);
    }

    public function testActivationIdempotencyAndChecks(): void
    {
        $this->activation('k1');
        $this->rejects("INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, customer_id, plan_id, created_at, updated_at) VALUES ('ref-x', 'k1', 'NEW', 'CREATED', 'API', {$this->agent}, 'API', {$this->customer}, {$this->plan}, {$this->now()}, {$this->now()})", 'duplicate idempotency key');
        $this->rejects("INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, customer_id, plan_id, created_at, updated_at) VALUES ('ref-k1', 'k-other', 'NEW', 'CREATED', 'API', {$this->agent}, 'API', {$this->customer}, {$this->plan}, {$this->now()}, {$this->now()})", 'duplicate reference');
        $this->rejects("INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, customer_id, plan_id, created_at, updated_at) VALUES ('r2', 'k2', 'CHANGE_PLAN', 'CREATED', 'API', {$this->agent}, 'API', {$this->customer}, {$this->plan}, {$this->now()}, {$this->now()})", 'CHANGE_PLAN requires previous_plan_id');
        $this->activation('k3', 'CHANGE_PLAN', $this->plan2);
        $this->rejects("INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, customer_id, plan_id, plan_snapshot, created_at, updated_at) VALUES ('r4', 'k4', 'NEW', 'CREATED', 'API', {$this->agent}, 'API', {$this->customer}, {$this->plan}, '{not json', {$this->now()}, {$this->now()})", 'invalid JSON snapshot');
        $this->rejects("INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, customer_id, plan_id, wholesale_cost, created_at, updated_at) VALUES ('r5', 'k5', 'NEW', 'CREATED', 'API', {$this->agent}, 'API', {$this->customer}, {$this->plan}, -1, {$this->now()}, {$this->now()})", 'negative wholesale snapshot');
        $this->rejects("INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, customer_id, plan_id, created_at, updated_at) VALUES ('r6', 'k6', 'NEW', 'CREATED', 'STAFF_WEB', {$this->agent}, 'STAFF', {$this->customer}, {$this->plan}, {$this->now()}, {$this->now()})", 'STAFF actor needs actor_user_id');
        $this->rejects("INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, customer_id, plan_id, created_at, updated_at) VALUES ('r7', 'k7', 'NEW', 'BOGUS', 'API', {$this->agent}, 'API', {$this->customer}, {$this->plan}, {$this->now()}, {$this->now()})", 'unknown state');
        $this->rejects("INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, customer_id, plan_id, created_at, updated_at) VALUES ('r8', 'k8', 'NEW', 'CREATED', 'API', {$this->agent}, 'API', 999999, {$this->plan}, {$this->now()}, {$this->now()})", 'unknown customer (FK)');
    }

    public function testActivationEventsRepresentEveryFrozenTransition(): void
    {
        $act = $this->activation('k-events');
        $seq = 0;
        // initial event into CREATED
        $this->db->exec("INSERT INTO tbl_reseller_activation_events (activation_id, sequence_no, event_type, from_state, to_state, actor_type, actor_user_id, created_at) VALUES ({$act}, " . (++$seq) . ", 'STATE_TRANSITION', NULL, 'CREATED', 'STAFF', {$this->agent}, {$this->now()})");
        // every (from, to) pair of the frozen 15-state machine is representable; validity is WP5A logic
        foreach (self::STATES as $from) {
            foreach (self::STATES as $to) {
                $this->db->exec("INSERT INTO tbl_reseller_activation_events (activation_id, sequence_no, event_type, from_state, to_state, actor_type, created_at) VALUES ({$act}, " . (++$seq) . ", 'STATE_TRANSITION', '{$from}', '{$to}', 'SYSTEM', {$this->now()})");
            }
        }
        // provisioning outcome attached to the relevant transition, incl. UNKNOWN -> RECONCILIATION_REQUIRED
        foreach ([['PROVISIONING', 'PROVISIONED', 'SUCCESS'], ['PROVISIONING', 'FAILED', 'FAILED'], ['PROVISIONING', 'RECONCILIATION_REQUIRED', 'UNKNOWN']] as [$f, $t, $res]) {
            $this->db->exec("INSERT INTO tbl_reseller_activation_events (activation_id, sequence_no, event_type, from_state, to_state, provisioning_result, error_code, error_message, actor_type, metadata, created_at) VALUES ({$act}, " . (++$seq) . ", 'PROVISIONING_RESULT', '{$f}', '{$t}', '{$res}', 'E_TEST', 'sanitized', 'SYSTEM', '{\"nas\":\"10.0.0.1\"}', {$this->now()})");
        }
        $this->assertSame(1 + 15 * 15 + 3, (int) $this->db->value('SELECT COUNT(*) FROM tbl_reseller_activation_events WHERE activation_id = ?', [$act]));
        $this->assertSame(15, (int) $this->db->value('SELECT COUNT(DISTINCT to_state) FROM tbl_reseller_activation_events'));

        $this->rejects("INSERT INTO tbl_reseller_activation_events (activation_id, sequence_no, event_type, from_state, to_state, actor_type, created_at) VALUES ({$act}, 1, 'ANNOTATION', 'CREATED', 'CREATED', 'SYSTEM', {$this->now()})", 'duplicate sequence per activation');
        $this->rejects("INSERT INTO tbl_reseller_activation_events (activation_id, sequence_no, event_type, from_state, to_state, actor_type, created_at) VALUES ({$act}, 9999, 'STATE_TRANSITION', NULL, 'VALIDATED', 'SYSTEM', {$this->now()})", 'only the initial event may omit from_state');
        $this->rejects("INSERT INTO tbl_reseller_activation_events (activation_id, sequence_no, event_type, from_state, to_state, actor_type, metadata, created_at) VALUES ({$act}, 10000, 'ANNOTATION', 'CREATED', 'CREATED', 'SYSTEM', 'nope', {$this->now()})", 'metadata must be JSON');
        $this->rejects("INSERT INTO tbl_reseller_activation_events (activation_id, sequence_no, event_type, from_state, to_state, provisioning_result, actor_type, created_at) VALUES ({$act}, 10001, 'PROVISIONING_RESULT', 'PROVISIONING', 'PROVISIONED', 'MAYBE', 'SYSTEM', {$this->now()})", 'provisioning result vocabulary');
    }

    public function testTreeConstraints(): void
    {
        $this->db->exec("INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES ({$this->agent}, {$this->agent}, 0, {$this->now()}), ({$this->agent}, {$this->child}, 1, {$this->now()})");
        $this->rejects("INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES ({$this->agent}, {$this->child}, 1, {$this->now()})", 'duplicate closure pair');
        $this->rejects("INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES ({$this->child}, {$this->child}, 1, {$this->now()})", 'self-parent at depth > 0');
        $this->rejects("INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES ({$this->agent}, {$this->child}, 0, {$this->now()})", 'depth 0 must be a self row');
        $this->rejects("INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES (999999, {$this->child}, 1, {$this->now()})", 'unknown user');
        $this->rejects("DELETE FROM tbl_users WHERE id = {$this->child}", 'RESTRICT: referenced user cannot be deleted');
    }

    public function testPricingConstraints(): void
    {
        $this->db->exec("INSERT INTO tbl_reseller_plan_base_costs (plan_id, base_cost, is_resale_enabled, created_by, created_at, updated_by, updated_at) VALUES ({$this->plan}, 10.00, 1, {$this->admin}, {$this->now()}, {$this->admin}, {$this->now()})");
        $this->rejects("INSERT INTO tbl_reseller_plan_base_costs (plan_id, base_cost, created_by, created_at, updated_by, updated_at) VALUES ({$this->plan}, 11.00, {$this->admin}, {$this->now()}, {$this->admin}, {$this->now()})", 'one base cost per plan');
        $this->rejects("INSERT INTO tbl_reseller_plan_base_costs (plan_id, base_cost, created_by, created_at, updated_by, updated_at) VALUES ({$this->plan2}, -0.01, {$this->admin}, {$this->now()}, {$this->admin}, {$this->now()})", 'negative base cost');
        $this->rejects("INSERT INTO tbl_reseller_plan_base_costs (plan_id, base_cost, created_by, created_at, updated_by, updated_at) VALUES ({$this->plan2}, 1, 999999, {$this->now()}, {$this->admin}, {$this->now()})", 'audit actor must exist');
        $this->db->exec("INSERT INTO tbl_reseller_plan_base_costs (plan_id, base_cost, created_by, created_at, updated_by, updated_at) VALUES ({$this->plan2}, 1, {$this->admin}, {$this->now()}, {$this->admin}, {$this->now()})");
        $this->assertSame('0', (string) $this->db->value("SELECT is_resale_enabled FROM tbl_reseller_plan_base_costs WHERE plan_id = {$this->plan2}"), 'resale disabled by default (fail closed)');
        $this->db->exec("INSERT INTO tbl_manager_plan_prices (manager_id, plan_id, wholesale_cost, wholesale_set_by, wholesale_set_at, created_at, updated_at) VALUES ({$this->child}, {$this->plan}, 12.00, {$this->agent}, {$this->now()}, {$this->now()}, {$this->now()})");
        $this->rejects("INSERT INTO tbl_manager_plan_prices (manager_id, plan_id, created_at, updated_at) VALUES ({$this->child}, {$this->plan}, {$this->now()}, {$this->now()})", 'one configuration per manager and plan');
        $this->rejects("INSERT INTO tbl_manager_plan_prices (manager_id, plan_id, wholesale_cost, created_at, updated_at) VALUES ({$this->agent}, {$this->plan}, 5, {$this->now()}, {$this->now()})", 'wholesale override requires who/when audit');
        $this->rejects("INSERT INTO tbl_manager_plan_prices (manager_id, plan_id, retail_price, retail_set_by, retail_set_at, created_at, updated_at) VALUES ({$this->agent}, {$this->plan2}, -5, {$this->agent}, {$this->now()}, {$this->now()}, {$this->now()})", 'negative retail');
        $this->rejects("DELETE FROM tbl_plans WHERE id = {$this->plan}", 'RESTRICT: priced plan cannot be deleted');
    }

    /** Owner WP1 fix 1: availability is an optional, audited override (NULL = inherit, 0 = disabled, 1 = enabled). */
    public function testManagerPlanAvailabilityOverrideIsOptionalAndAudited(): void
    {
        $now = $this->now();
        $cols = 'manager_id, plan_id, created_at, updated_at';
        $row = fn (int $manager, int $plan, string $extraCols = '', string $extraVals = ''): string =>
            "INSERT INTO tbl_manager_plan_prices ({$cols}{$extraCols}) VALUES ({$manager}, {$plan}, {$now}, {$now}{$extraVals})";
        $avail = fn (int $manager, int $plan): array => $this->db->rows(
            'SELECT is_enabled, availability_set_by, availability_set_at FROM tbl_manager_plan_prices WHERE manager_id = ? AND plan_id = ?',
            [$manager, $plan]
        )[0];

        // 1. a pricing-only row carries no availability override and no availability audit (inherit)
        $this->db->exec($row($this->child, $this->plan, ', wholesale_cost, wholesale_set_by, wholesale_set_at', ", 12.00, {$this->agent}, {$now}"));
        $this->assertSame(['is_enabled' => null, 'availability_set_by' => null, 'availability_set_at' => null], $avail($this->child, $this->plan), 'default = NULL = inherit');
        $this->db->exec($row($this->child, $this->plan2, ', is_enabled', ', NULL'));
        $this->assertNull($avail($this->child, $this->plan2)['is_enabled'], 'explicit NULL = inherit, no audit required');
        $this->rejects($row($this->agent, $this->plan, ', availability_set_by', ", {$this->admin}"), 'inherit (NULL) must not carry an availability actor');
        $this->rejects($row($this->agent, $this->plan, ', availability_set_at', ", {$now}"), 'inherit (NULL) must not carry an availability time');
        $this->rejects($row($this->agent, $this->plan, ', is_enabled, availability_set_by, availability_set_at', ", NULL, {$this->admin}, {$now}"), 'inherit (NULL) must not carry availability audit');

        // 2./3. an explicit override (0 or 1) requires who + when
        foreach ([0, 1] as $v) {
            $this->rejects($row($this->agent, $this->plan, ', is_enabled', ", {$v}"), "is_enabled = {$v} without audit");
            $this->rejects($row($this->agent, $this->plan, ', is_enabled, availability_set_by', ", {$v}, {$this->admin}"), "is_enabled = {$v} without availability_set_at");
            $this->rejects($row($this->agent, $this->plan, ', is_enabled, availability_set_at', ", {$v}, {$now}"), "is_enabled = {$v} without availability_set_by");
        }
        $this->db->exec($row($this->agent, $this->plan, ', is_enabled, availability_set_by, availability_set_at', ", 0, {$this->admin}, {$now}"));
        $this->db->exec($row($this->agent, $this->plan2, ', is_enabled, availability_set_by, availability_set_at', ", 1, {$this->admin}, {$now}"));
        $disabled = $avail($this->agent, $this->plan);
        $enabled = $avail($this->agent, $this->plan2);
        $this->assertSame(['0', (string) $this->admin], [(string) $disabled['is_enabled'], (string) $disabled['availability_set_by']]);
        $this->assertSame(['1', (string) $this->admin], [(string) $enabled['is_enabled'], (string) $enabled['availability_set_by']]);
        $this->assertNotNull($disabled['availability_set_at']);
        $this->assertNotNull($enabled['availability_set_at']);

        // 4. only 0 / 1 / NULL
        foreach (['2', '-1', '127'] as $bad) {
            $this->rejects($row($this->admin, $this->plan, ', is_enabled, availability_set_by, availability_set_at', ", {$bad}, {$this->admin}, {$now}"), "is_enabled = {$bad}");
        }
        $this->rejects($row($this->admin, $this->plan, ', is_enabled, availability_set_by, availability_set_at', ", 'yes', {$this->admin}, {$now}"), "is_enabled = 'yes'");
        $this->rejects("UPDATE tbl_manager_plan_prices SET is_enabled = 2 WHERE manager_id = {$this->agent} AND plan_id = {$this->plan}", 'update to an invalid availability value');

        // 5. the availability actor must be an existing user
        $this->rejects($row($this->admin, $this->plan, ', is_enabled, availability_set_by, availability_set_at', ", 1, 999999, {$now}"), 'availability_set_by FK');

        // returning to inherit must clear the audit together with the value; clearing everything is allowed
        $this->rejects("UPDATE tbl_manager_plan_prices SET is_enabled = NULL WHERE manager_id = {$this->agent} AND plan_id = {$this->plan}", 'inherit with a stale availability audit');
        $this->rejects("UPDATE tbl_manager_plan_prices SET availability_set_by = NULL WHERE manager_id = {$this->agent} AND plan_id = {$this->plan}", 'override without its actor');
        $this->db->exec("UPDATE tbl_manager_plan_prices SET is_enabled = NULL, availability_set_by = NULL, availability_set_at = NULL WHERE manager_id = {$this->agent} AND plan_id = {$this->plan}");
        $this->assertSame(['is_enabled' => null, 'availability_set_by' => null, 'availability_set_at' => null], $avail($this->agent, $this->plan));
        $this->rejects("DELETE FROM tbl_users WHERE id = {$this->admin}", 'RESTRICT: user recorded as availability actor cannot be deleted');
        $this->assertSame(4, (int) $this->db->value('SELECT COUNT(*) FROM tbl_manager_plan_prices'));
    }

    /**
     * Owner WP1 final fix 2: *_set_by / *_set_at describe only the CURRENT explicit wholesale /
     * retail override (NULL value <=> no setter; explicit value <=> setter + time).
     */
    public function testPriceOverrideSetterFieldsDescribeOnlyTheCurrentOverride(): void
    {
        $now = $this->now();
        foreach ([
            'wholesale' => [$this->agent, 'wholesale_cost', 'wholesale_set_by', 'wholesale_set_at', '12.50'],
            'retail' => [$this->child, 'retail_price', 'retail_set_by', 'retail_set_at', '19.99'],
        ] as $name => [$manager, $value, $by, $at, $amount]) {
            $insert = fn (int $plan, string $cols, string $vals): string =>
                "INSERT INTO tbl_manager_plan_prices (manager_id, plan_id, created_at, updated_at{$cols}) VALUES ({$manager}, {$plan}, {$now}, {$now}{$vals})";
            $where = "WHERE manager_id = {$manager} AND plan_id = {$this->plan2}";
            $get = fn (int $plan): array => $this->db->rows(
                "SELECT {$value} AS v, {$by} AS b, {$at} AS t FROM tbl_manager_plan_prices WHERE manager_id = ? AND plan_id = ?",
                [$manager, $plan]
            )[0];

            // NULL with no setter is accepted (inherit), both implicitly and explicitly
            $this->db->exec($insert($this->plan, ", {$value}", ', NULL'));
            $this->assertSame(['v' => null, 'b' => null, 't' => null], $get($this->plan), "{$name}: NULL = inherit, no setter");
            // NULL with a stale setter / time is rejected (insert and update)
            $this->rejects($insert($this->plan2, ", {$by}", ", {$this->admin}"), "{$name}: NULL with stale setter");
            $this->rejects($insert($this->plan2, ", {$at}", ", {$now}"), "{$name}: NULL with stale time");
            $this->rejects($insert($this->plan2, ", {$value}, {$by}, {$at}", ", NULL, {$this->admin}, {$now}"), "{$name}: NULL with stale setter + time");
            $this->rejects("UPDATE tbl_manager_plan_prices SET {$by} = {$this->admin}, {$at} = {$now} WHERE manager_id = {$manager} AND plan_id = {$this->plan}", "{$name}: setter attached to an inherited value");
            // an explicit value requires setter + time
            $this->rejects($insert($this->plan2, ", {$value}", ", {$amount}"), "{$name}: explicit value without setter/time");
            $this->rejects($insert($this->plan2, ", {$value}, {$by}", ", {$amount}, {$this->admin}"), "{$name}: explicit value without time");
            $this->rejects($insert($this->plan2, ", {$value}, {$at}", ", {$amount}, {$now}"), "{$name}: explicit value without setter");
            $this->db->exec($insert($this->plan2, ", {$value}, {$by}, {$at}", ", {$amount}, {$this->admin}, {$now}"));
            $row = $get($this->plan2);
            $this->assertSame([$amount, (string) $this->admin], [(string) $row['v'], (string) $row['b']]);
            $this->assertNotNull($row['t']);
            // clearing the value requires clearing setter + time together
            $this->rejects("UPDATE tbl_manager_plan_prices SET {$value} = NULL {$where}", "{$name}: clear value but keep setter + time");
            $this->rejects("UPDATE tbl_manager_plan_prices SET {$value} = NULL, {$by} = NULL {$where}", "{$name}: clear value + setter but keep time");
            $this->rejects("UPDATE tbl_manager_plan_prices SET {$value} = NULL, {$at} = NULL {$where}", "{$name}: clear value + time but keep setter");
            $this->rejects("UPDATE tbl_manager_plan_prices SET {$by} = NULL, {$at} = NULL {$where}", "{$name}: explicit value cannot lose its setter");
            $this->db->exec("UPDATE tbl_manager_plan_prices SET {$value} = NULL, {$by} = NULL, {$at} = NULL {$where}");
            $this->assertSame(['v' => null, 'b' => null, 't' => null], $get($this->plan2), "{$name}: cleared override leaves no provenance");
        }
        $this->assertSame(4, (int) $this->db->value('SELECT COUNT(*) FROM tbl_manager_plan_prices'));
    }

    private function actorActivation(string $key, string $actorType, ?int $actorUser, ?int $actorCustomer, int $targetCustomer): string
    {
        $u = $actorUser === null ? 'NULL' : (string) $actorUser;
        $cu = $actorCustomer === null ? 'NULL' : (string) $actorCustomer;
        return "INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, actor_user_id, actor_customer_id, customer_id, plan_id, created_at, updated_at)
            VALUES ('ref-{$key}', '{$key}', 'NEW', 'CREATED', 'CUSTOMER_BALANCE', {$this->agent}, '{$actorType}', {$u}, {$cu}, {$targetCustomer}, {$this->plan}, {$this->now()}, {$this->now()})";
    }

    private function actorEvent(int $activation, int $seq, string $actorType, ?int $actorUser, ?int $actorCustomer): string
    {
        $u = $actorUser === null ? 'NULL' : (string) $actorUser;
        $cu = $actorCustomer === null ? 'NULL' : (string) $actorCustomer;
        $from = $seq === 1 ? 'NULL' : "'CREATED'";
        $to = $seq === 1 ? 'CREATED' : 'VALIDATED';
        return "INSERT INTO tbl_reseller_activation_events (activation_id, sequence_no, event_type, from_state, to_state, actor_type, actor_user_id, actor_customer_id, created_at)
            VALUES ({$activation}, {$seq}, 'STATE_TRANSITION', {$from}, '{$to}', '{$actorType}', {$u}, {$cu}, {$this->now()})";
    }

    /** Owner WP1 fix 2: a CUSTOMER actor is a persisted customer identity, independent of the target customer. */
    public function testCustomerActorIdentityOnActivations(): void
    {
        // 7. initiating customer A (gift / self-service for another customer) targets customer B
        $this->db->exec($this->actorActivation('gift-a-to-b', 'CUSTOMER', null, $this->customer, $this->customer2));
        $gift = $this->db->rows("SELECT actor_type, actor_user_id, actor_customer_id, customer_id FROM tbl_reseller_activations WHERE idempotency_key = 'gift-a-to-b'")[0];
        $this->assertSame('CUSTOMER', $gift['actor_type']);
        $this->assertNull($gift['actor_user_id']);
        $this->assertSame((string) $this->customer, (string) $gift['actor_customer_id'], 'initiator persisted');
        $this->assertSame((string) $this->customer2, (string) $gift['customer_id'], 'target persisted separately');
        $this->assertNotSame((string) $gift['actor_customer_id'], (string) $gift['customer_id']);
        // the same customer may also act for themself
        $this->db->exec($this->actorActivation('self-b', 'CUSTOMER', null, $this->customer2, $this->customer2));

        // 6. CUSTOMER without actor_customer_id is rejected; an unknown customer is rejected by the FK
        $this->rejects($this->actorActivation('cust-missing', 'CUSTOMER', null, null, $this->customer2), 'CUSTOMER actor requires actor_customer_id');
        $this->rejects($this->actorActivation('cust-missing-u', 'CUSTOMER', $this->agent, null, $this->customer2), 'a staff id is not a customer identity');
        $this->rejects($this->actorActivation('cust-bad-fk', 'CUSTOMER', null, 999999, $this->customer2), 'actor_customer_id FK');
        // STAFF still requires actor_user_id
        $this->rejects($this->actorActivation('staff-missing', 'STAFF', null, null, $this->customer2), 'STAFF actor requires actor_user_id');
        $this->rejects($this->actorActivation('staff-cust-only', 'STAFF', null, $this->customer, $this->customer2), 'a customer id is not a staff identity');
        $this->db->exec($this->actorActivation('staff-ok', 'STAFF', $this->agent, null, $this->customer2));
        // SYSTEM / CRON may carry neither; API has no identity rule in WP1
        foreach (['SYSTEM', 'CRON', 'API'] as $t) {
            $this->db->exec($this->actorActivation('no-identity-' . $t, $t, null, null, $this->customer2));
        }
        $this->assertSame(6, (int) $this->db->value('SELECT COUNT(*) FROM tbl_reseller_activations'));
        // customer A is referenced ONLY as an initiator (never as a target): RESTRICT comes from fk_act_actor_customer
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = {$this->customer}"));
        $this->rejects("DELETE FROM tbl_customers WHERE id = {$this->customer}", 'RESTRICT: an initiating customer cannot be deleted');
        $this->rejects("UPDATE tbl_reseller_activations SET actor_customer_id = NULL WHERE idempotency_key = 'gift-a-to-b'", 'CUSTOMER identity cannot be removed');
    }

    /** Owner WP1 fix 2: the transition timeline records the customer identity per event. */
    public function testCustomerActorIdentityOnActivationEvents(): void
    {
        $this->db->exec($this->actorActivation('evt-gift', 'CUSTOMER', null, $this->customer, $this->customer2));
        $act = (int) $this->db->value("SELECT id FROM tbl_reseller_activations WHERE idempotency_key = 'evt-gift'");

        // 8. a CUSTOMER event requires actor_customer_id; FK enforced; STAFF still requires actor_user_id
        $this->rejects($this->actorEvent($act, 1, 'CUSTOMER', null, null), 'CUSTOMER event requires actor_customer_id');
        $this->rejects($this->actorEvent($act, 1, 'CUSTOMER', null, 999999), 'event actor_customer_id FK');
        $this->rejects($this->actorEvent($act, 1, 'STAFF', null, null), 'STAFF event requires actor_user_id');
        $this->rejects($this->actorEvent($act, 1, 'STAFF', null, $this->customer), 'a customer id is not a staff identity');

        // initiating customer A recorded on the event of an activation that targets customer B
        $this->db->exec($this->actorEvent($act, 1, 'CUSTOMER', null, $this->customer));
        $this->db->exec($this->actorEvent($act, 2, 'SYSTEM', null, null));
        $this->db->exec($this->actorEvent($act, 3, 'STAFF', $this->agent, null));
        $rows = $this->db->rows('SELECT e.sequence_no, e.actor_type, e.actor_user_id, e.actor_customer_id, a.customer_id FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE e.activation_id = ? ORDER BY e.sequence_no', [$act]);
        $this->assertCount(3, $rows);
        $this->assertSame(['CUSTOMER', (string) $this->customer, (string) $this->customer2], [$rows[0]['actor_type'], (string) $rows[0]['actor_customer_id'], (string) $rows[0]['customer_id']]);
        $this->assertNull($rows[0]['actor_user_id']);
        $this->assertSame(['SYSTEM', null, null], [$rows[1]['actor_type'], $rows[1]['actor_user_id'], $rows[1]['actor_customer_id']]);
        $this->assertSame(['STAFF', (string) $this->agent], [$rows[2]['actor_type'], (string) $rows[2]['actor_user_id']]);
    }

    private function ledgerRow(string $key, string $actorType, ?int $operator, ?int $actorCustomer, ?int $activation): string
    {
        $op = $operator === null ? 'NULL' : (string) $operator;
        $cu = $actorCustomer === null ? 'NULL' : (string) $actorCustomer;
        $act = $activation === null ? 'NULL' : (string) $activation;
        return "INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, activation_id, operator_id, actor_type, actor_customer_id, created_at)
            VALUES ('{$key}', {$this->agent}, 'ACTIVATION_DEBIT', 'DEBIT', 10.00, 100.00, 90.00, {$act}, {$op}, '{$actorType}', {$cu}, {$this->now()})";
    }

    /** Owner WP1 final fix 1: the ledger row itself persists the customer actor identity. */
    public function testLedgerCustomerActorIdentity(): void
    {
        $now = $this->now();
        $this->db->exec("INSERT INTO tbl_manager_wallets (manager_id, balance, debt_limit, created_at, updated_at) VALUES ({$this->agent}, 100.00, 0, {$now}, {$now})");
        // customer A initiates an activation whose target is customer B
        $this->db->exec($this->actorActivation('ledger-gift', 'CUSTOMER', null, $this->customer, $this->customer2));
        $act = (int) $this->db->value("SELECT id FROM tbl_reseller_activations WHERE idempotency_key = 'ledger-gift'");

        // CUSTOMER entry with actor_customer_id succeeds; the actor (A) differs from the activation target (B)
        $this->db->exec($this->ledgerRow('l-gift', 'CUSTOMER', null, $this->customer, $act));
        $r = $this->db->rows("SELECT l.actor_type, l.operator_id, l.actor_customer_id, a.customer_id AS target FROM tbl_manager_ledger l JOIN tbl_reseller_activations a ON a.id = l.activation_id WHERE l.entry_key = 'l-gift'")[0];
        $this->assertSame('CUSTOMER', $r['actor_type']);
        $this->assertNull($r['operator_id']);
        $this->assertSame((string) $this->customer, (string) $r['actor_customer_id'], 'ledger identifies the initiating customer A');
        $this->assertSame((string) $this->customer2, (string) $r['target'], 'the activation target stays customer B');
        $this->assertNotSame((string) $r['actor_customer_id'], (string) $r['target']);
        // the identity lives on the ledger row: no activation link is needed to record it (WP3A decides that rule)
        $this->db->exec($this->ledgerRow('l-cust-no-act', 'CUSTOMER', null, $this->customer2, null));

        // CUSTOMER without actor_customer_id fails, even when an activation carrying the identity is linked
        $this->rejects($this->ledgerRow('l-cust-missing', 'CUSTOMER', null, null, $act), 'CUSTOMER ledger actor requires actor_customer_id');
        $this->rejects($this->ledgerRow('l-cust-op-only', 'CUSTOMER', $this->agent, null, null), 'a staff operator is not a customer identity');
        // unknown customer is rejected by the FK
        $this->rejects($this->ledgerRow('l-cust-bad-fk', 'CUSTOMER', null, 999999, null), 'ledger actor_customer_id FK');
        // STAFF without operator_id still fails
        $this->rejects($this->ledgerRow('l-staff-missing', 'STAFF', null, null, null), 'STAFF ledger entry requires operator_id');
        $this->rejects($this->ledgerRow('l-staff-cust-only', 'STAFF', null, $this->customer, null), 'a customer id is not a staff operator');
        $this->db->exec($this->ledgerRow('l-staff-ok', 'STAFF', $this->admin, null, null));
        // SYSTEM / CRON / API may carry no customer identity
        foreach (['SYSTEM', 'CRON', 'API'] as $t) {
            $this->db->exec($this->ledgerRow('l-' . strtolower($t), $t, null, null, null));
        }
        $this->assertSame(6, (int) $this->db->value('SELECT COUNT(*) FROM tbl_manager_ledger'));

        // a customer referenced ONLY as a ledger actor cannot be deleted (RESTRICT via fk_ledger_actor_customer)
        $c3 = $this->db->insertRow('tbl_customers', ['username' => 'cust_c3', 'password' => 'p', 'fullname' => 'c3', 'balance' => 0]);
        $this->db->exec($this->ledgerRow('l-c3', 'CUSTOMER', null, $c3, null));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = {$c3} OR actor_customer_id = {$c3}"));
        $this->rejects("DELETE FROM tbl_customers WHERE id = {$c3}", 'RESTRICT: ledger actor customer cannot be deleted');
        $this->rejects("UPDATE tbl_manager_ledger SET actor_customer_id = NULL WHERE entry_key = 'l-c3'", 'CUSTOMER ledger identity cannot be removed');
    }

    public function testWalletLedgerTransferReservationConstraints(): void
    {
        $now = $this->now();
        $this->db->exec("INSERT INTO tbl_manager_wallets (manager_id, balance, debt_limit, created_at, updated_at) VALUES ({$this->agent}, 100.00, 50.00, {$now}, {$now}), ({$this->child}, 0, 0, {$now}, {$now})");
        $this->rejects("INSERT INTO tbl_manager_wallets (manager_id, balance, debt_limit, created_at, updated_at) VALUES ({$this->agent}, 0, 0, {$now}, {$now})", 'one wallet per manager');
        $this->rejects("UPDATE tbl_manager_wallets SET balance = -50.01 WHERE manager_id = {$this->agent}", 'balance + debt_limit must stay >= 0');
        $this->rejects("UPDATE tbl_manager_wallets SET debt_limit = -1 WHERE manager_id = {$this->agent}", 'debt limit non-negative');
        $this->db->exec("UPDATE tbl_manager_wallets SET balance = -50.00 WHERE manager_id = {$this->agent}");
        $this->db->exec("UPDATE tbl_manager_wallets SET balance = 100.00 WHERE manager_id = {$this->agent}");

        // transfers: provider funding without a source wallet; parent->child needs both, different
        $this->db->exec("INSERT INTO tbl_manager_transfers (reference, operation_type, from_manager_id, to_manager_id, amount, status, reason, operator_id, created_at) VALUES ('t1', 'PROVIDER_FUNDING', NULL, {$this->agent}, 100, 'COMPLETED', 'initial funding', {$this->admin}, {$now})");
        $this->db->exec("INSERT INTO tbl_manager_transfers (reference, operation_type, from_manager_id, to_manager_id, amount, status, reason, operator_id, created_at) VALUES ('t2', 'PARENT_CHILD_TRANSFER', {$this->agent}, {$this->child}, 10, 'COMPLETED', 'top-up', {$this->agent}, {$now})");
        $this->rejects("INSERT INTO tbl_manager_transfers (reference, operation_type, from_manager_id, to_manager_id, amount, status, reason, operator_id, created_at) VALUES ('t3', 'PROVIDER_FUNDING', {$this->agent}, {$this->child}, 1, 'PENDING', 'r', {$this->admin}, {$now})", 'provider funding has no source wallet');
        $this->rejects("INSERT INTO tbl_manager_transfers (reference, operation_type, from_manager_id, to_manager_id, amount, status, reason, operator_id, created_at) VALUES ('t4', 'PARENT_CHILD_TRANSFER', {$this->agent}, {$this->agent}, 1, 'PENDING', 'r', {$this->agent}, {$now})", 'no self transfer');
        $this->rejects("INSERT INTO tbl_manager_transfers (reference, operation_type, from_manager_id, to_manager_id, amount, status, reason, operator_id, created_at) VALUES ('t5', 'PROVIDER_WITHDRAWAL', {$this->agent}, NULL, 0, 'PENDING', 'r', {$this->admin}, {$now})", 'amount > 0');
        $this->rejects("INSERT INTO tbl_manager_transfers (reference, operation_type, from_manager_id, to_manager_id, amount, status, reason, operator_id, created_at) VALUES ('t1', 'PROVIDER_FUNDING', NULL, {$this->agent}, 1, 'PENDING', 'r', {$this->admin}, {$now})", 'unique transfer reference');
        $t1 = (int) $this->db->value("SELECT id FROM tbl_manager_transfers WHERE reference = 't1'");
        $this->db->exec("INSERT INTO tbl_manager_transfers (reference, operation_type, from_manager_id, to_manager_id, amount, status, reason, operator_id, reverses_transfer_id, created_at) VALUES ('t1-rev', 'PROVIDER_WITHDRAWAL', {$this->agent}, NULL, 100, 'COMPLETED', 'reverse t1', {$this->admin}, {$t1}, {$now})");
        $this->rejects("INSERT INTO tbl_manager_transfers (reference, operation_type, from_manager_id, to_manager_id, amount, status, reason, operator_id, reverses_transfer_id, created_at) VALUES ('t1-rev2', 'PROVIDER_WITHDRAWAL', {$this->agent}, NULL, 100, 'COMPLETED', 'again', {$this->admin}, {$t1}, {$now})", 'a transfer is reversed at most once');

        // ledger: arithmetic, positive amounts, unique entry key, single reversal
        $this->db->exec("INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, transfer_id, operator_id, actor_type, reason, created_at) VALUES ('e1', {$this->agent}, 'FUNDING', 'CREDIT', 100.00, 0.00, 100.00, {$t1}, {$this->admin}, 'STAFF', 'funding', {$now})");
        $this->rejects("INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, actor_type, created_at) VALUES ('e2', {$this->agent}, 'ADJUSTMENT', 'DEBIT', 10.00, 100.00, 95.00, 'SYSTEM', {$now})", 'ledger arithmetic');
        $this->rejects("INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, actor_type, created_at) VALUES ('e3', {$this->agent}, 'ADJUSTMENT', 'CREDIT', -1.00, 100.00, 99.00, 'SYSTEM', {$now})", 'ledger amount positive');
        $this->rejects("INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, actor_type, created_at) VALUES ('e1', {$this->agent}, 'ADJUSTMENT', 'CREDIT', 1.00, 100.00, 101.00, 'SYSTEM', {$now})", 'unique entry key');
        $this->rejects("INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, actor_type, created_at) VALUES ('e4', {$this->agent}, 'ADJUSTMENT', 'CREDIT', 1.00, 100.00, 101.00, 'STAFF', {$now})", 'STAFF entries need an operator');
        $this->rejects("INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, actor_type, created_at) VALUES ('e5', {$this->admin}, 'ADJUSTMENT', 'CREDIT', 1.00, 0.00, 1.00, 'SYSTEM', {$now})", 'ledger rows need an existing wallet');
        $e1 = (int) $this->db->value("SELECT id FROM tbl_manager_ledger WHERE entry_key = 'e1'");
        $this->db->exec("INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, reverses_entry_id, operator_id, actor_type, reason, created_at) VALUES ('e1-rev', {$this->agent}, 'REVERSAL', 'DEBIT', 100.00, 100.00, 0.00, {$e1}, {$this->admin}, 'STAFF', 'reverse e1', {$now})");
        $this->rejects("INSERT INTO tbl_manager_ledger (entry_key, manager_id, entry_type, direction, amount, balance_before, balance_after, reverses_entry_id, operator_id, actor_type, created_at) VALUES ('e1-rev2', {$this->agent}, 'REVERSAL', 'DEBIT', 100.00, 0.00, -100.00, {$e1}, {$this->admin}, 'STAFF', {$now})", 'an entry is reversed at most once');
        $this->rejects("DELETE FROM tbl_manager_transfers WHERE id = {$t1}", 'RESTRICT: transfer referenced by ledger');

        // reservations: at most one ACTIVE per activation, history allowed
        $act = $this->activation('k-res');
        $this->db->exec("INSERT INTO tbl_manager_wallet_reservations (manager_id, activation_id, amount, status, created_at) VALUES ({$this->agent}, {$act}, 17.00, 'ACTIVE', {$now})");
        $this->rejects("INSERT INTO tbl_manager_wallet_reservations (manager_id, activation_id, amount, status, created_at) VALUES ({$this->agent}, {$act}, 17.00, 'ACTIVE', {$now})", 'second ACTIVE reservation for the same activation');
        $this->db->exec("UPDATE tbl_manager_wallet_reservations SET status = 'RELEASED', resolved_at = {$now} WHERE activation_id = {$act}");
        $this->db->exec("INSERT INTO tbl_manager_wallet_reservations (manager_id, activation_id, amount, status, created_at) VALUES ({$this->agent}, {$act}, 17.00, 'ACTIVE', {$now})");
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM tbl_manager_wallet_reservations WHERE activation_id = {$act}"));
        $this->rejects("INSERT INTO tbl_manager_wallet_reservations (manager_id, activation_id, amount, status, created_at) VALUES ({$this->agent}, {$act}, 1.00, 'RELEASED', {$now})", 'resolved state needs resolved_at');
        $this->rejects("INSERT INTO tbl_manager_wallet_reservations (manager_id, activation_id, amount, status, resolved_at, created_at) VALUES ({$this->agent}, {$act}, 1.00, 'COMMITTED', {$now}, {$now})", 'COMMITTED needs a ledger entry link');
        $this->rejects("INSERT INTO tbl_manager_wallet_reservations (manager_id, activation_id, amount, status, created_at) VALUES ({$this->agent}, {$act}, 0, 'ACTIVE', {$now})", 'reservation amount > 0');
        $this->rejects("DELETE FROM tbl_customers WHERE id = {$this->customer}", 'RESTRICT: customer with activation history cannot be deleted');
    }

    public function testCustomerOwnershipForeignKeys(): void
    {
        $this->db->exec("UPDATE tbl_customers SET manager_id = {$this->agent}, assigned_sales_id = {$this->child} WHERE id = {$this->customer}");
        $this->rejects("UPDATE tbl_customers SET manager_id = 999999 WHERE id = {$this->customer}", 'manager must be an existing user');
        $this->rejects("DELETE FROM tbl_users WHERE id = {$this->agent}", 'RESTRICT: owner user cannot be deleted while owning customers');
        $this->db->exec("UPDATE tbl_customers SET manager_id = NULL, assigned_sales_id = NULL WHERE id = {$this->customer}");
    }
}
