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
