<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP2 §8 / C21 / OD-6 / OD-7: ownership resolved server-side, Sales assignment inside the owner's
 * direct Sales children, owner transfer SuperAdmin/Admin only, explicit legacy assignment, created_by
 * never used, every change audited.
 */
final class CustomerOwnershipTest extends Wp2HttpTestCase
{
    private function add(string $as, string $username, array $extra = []): \Reseller\Tests\Support\HttpResponse
    {
        return $this->staff($as)->postWithToken('customers/add-post', $extra + ['username' => $username, 'fullname' => 'Cust ' . $username, 'password' => 'cpw-123',
            'service_type' => 'Hotspot', 'account_type' => 'Personal', 'email' => '', 'address' => '', 'phonenumber' => '']);
    }

    private function owner(string $username): array
    {
        $r = static::$db->rows('SELECT manager_id, assigned_sales_id, created_by FROM tbl_customers WHERE username = ?', [$username]);
        return $r[0] ?? [];
    }

    public function testSalesCreatesForItsManagerAndSelfIgnoringForgedFields(): void
    {
        $this->add('A1', 'own_by_sales', ['manager_id' => $this->sid('C'), 'assigned_sales_id' => $this->sid('C1')]);
        $o = $this->owner('own_by_sales');
        $this->assertSame([$this->sid('A'), $this->sid('A1')], [(int) $o['manager_id'], (int) $o['assigned_sales_id']]);
        $this->assertSame(200, $this->staff('A1')->get('customers/viewu/own_by_sales')->status);
        $this->assertNotSame(200, $this->staff('A2')->get('customers/viewu/own_by_sales')->status, 'C21: sibling Sales does not see it');
    }

    public function testAgentCreatesForSelfWithOptionalOwnSales(): void
    {
        $this->add('A', 'own_by_agent', ['manager_id' => $this->sid('C')]);
        $o = $this->owner('own_by_agent');
        $this->assertSame([$this->sid('A'), null], [(int) $o['manager_id'], $o['assigned_sales_id']]);
        $this->add('A', 'own_by_agent_s', ['assigned_sales_id' => $this->sid('A2')]);
        $this->assertSame($this->sid('A2'), (int) $this->owner('own_by_agent_s')['assigned_sales_id']);
        foreach (['C1' => 'other tree', 'B' => 'not a Sales', 'E1' => 'not enrolled'] as $s => $why) {
            $this->add('A', 'own_bad_' . strtolower($s), ['assigned_sales_id' => $this->sid($s)]);
            $this->assertSame([], $this->owner('own_bad_' . strtolower($s)), "rejected ({$why}), never silently dropped");
        }
    }

    public function testAdminCreatesUnassignedOrForAnEnrolledAgent(): void
    {
        $this->add('ad', 'own_by_admin_null');
        $o = $this->owner('own_by_admin_null');
        $this->assertNull($o['manager_id']);
        $this->assertSame($this->sid('ad'), (int) $o['created_by'], 'created_by remains a legacy audit field only');
        $this->add('ad', 'own_by_admin_c', ['manager_id' => $this->sid('C'), 'assigned_sales_id' => $this->sid('C1')]);
        $this->assertSame([$this->sid('C'), $this->sid('C1')], array_map('intval', array_values(array_slice($this->owner('own_by_admin_c'), 0, 2))));
        $this->add('ad', 'own_bad_mix', ['manager_id' => $this->sid('C'), 'assigned_sales_id' => $this->sid('A1')]);
        $this->assertSame([], $this->owner('own_bad_mix'));
        $this->add('ad', 'own_bad_owner', ['manager_id' => $this->sid('A1')]);
        $this->assertSame([], $this->owner('own_bad_owner'), 'owner must be an enrolled Active Agent');
        $this->add('ad', 'own_bad_inactive', ['manager_id' => $this->sid('D')]);
        $this->assertSame([], $this->owner('own_bad_inactive'));
        $this->assertNotEmpty($this->auditRows('customer.create'));
    }

    public function testReportAndNonEnrolledCannotCreate(): void
    {
        foreach (['rp', 'E', 'E1'] as $u) {
            $this->assertSame(403, $this->add($u, 'own_by_' . strtolower($u))->status);
            $this->assertSame([], $this->owner('own_by_' . strtolower($u)));
        }
    }

    public function testSalesAssignmentInsideScope(): void
    {
        $a = $this->staff('A');
        $a->postWithToken('reseller/customer-assign-sales', ['customer_id' => $this->cid('cA0'), 'sales_id' => $this->sid('A2')]);
        $this->assertSame($this->sid('A2'), (int) $this->owner('cA0')['assigned_sales_id']);
        $a->postWithToken('reseller/customer-assign-sales', ['customer_id' => $this->cid('cA0'), 'sales_id' => $this->sid('C1')]);
        $this->assertSame($this->sid('A2'), (int) $this->owner('cA0')['assigned_sales_id'], 'Sales of another tree refused');
        $a->postWithToken('reseller/customer-assign-sales', ['customer_id' => $this->cid('cC1'), 'sales_id' => $this->sid('A1')]);
        $this->assertSame($this->sid('C1'), (int) $this->owner('cC1')['assigned_sales_id'], 'customer outside the Agent subtree refused');
        $a->postWithToken('reseller/customer-clear-sales', ['customer_id' => $this->cid('cA0')]);
        $this->assertNull($this->owner('cA0')['assigned_sales_id']);
        $this->assertSame(403, $this->staff('A1')->postWithToken('reseller/customer-assign-sales', ['customer_id' => $this->cid('cA1'), 'sales_id' => $this->sid('A2')])->status);
        $this->assertNotEmpty($this->auditRows('customer.assign_sales'));
        $this->assertNotEmpty($this->auditRows('customer.clear_sales'));
    }

    public function testOwnerTransferIsAdminOnlyAndClearsForeignSales(): void
    {
        $this->assertSame(403, $this->staff('A')->postWithToken('reseller/customer-transfer', ['customer_id' => $this->cid('cA1'), 'manager_id' => $this->sid('C')])->status, 'OD-7');
        $sa = $this->staff('sa');
        $sa->postWithToken('reseller/customer-transfer', ['customer_id' => $this->cid('cA1'), 'manager_id' => $this->sid('C')]);
        $o = $this->owner('cA1');
        $this->assertSame($this->sid('C'), (int) $o['manager_id']);
        $this->assertNull($o['assigned_sales_id'], 'A1 is not a Sales of C: cleared');
        $sa->postWithToken('reseller/customer-transfer', ['customer_id' => $this->cid('cA1'), 'manager_id' => $this->sid('A'), 'sales_id' => $this->sid('C1')]);
        $this->assertSame($this->sid('C'), (int) $this->owner('cA1')['manager_id'], 'invalid replacement Sales → whole transfer rejected');
        $sa->postWithToken('reseller/customer-transfer', ['customer_id' => $this->cid('cA1'), 'manager_id' => $this->sid('A'), 'sales_id' => $this->sid('A1')]);
        $o = $this->owner('cA1');
        $this->assertSame([$this->sid('A'), $this->sid('A1')], [(int) $o['manager_id'], (int) $o['assigned_sales_id']]);
        $rows = $this->auditRows('customer.transfer_owner');
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('"manager_id":' . $this->sid('C'), $rows[0]['after_json']);
    }

    public function testExplicitLegacyOwnerAssignmentOnly(): void
    {
        $this->assertSame(403, $this->staff('A')->postWithToken('reseller/customer-assign-owner', ['customer_ids' => [$this->cid('cNULL2')], 'manager_id' => $this->sid('A')])->status);
        $sa = $this->staff('sa');
        $sa->postWithToken('reseller/customer-assign-owner', ['customer_ids' => [$this->cid('cNULL2'), $this->cid('cA1')], 'manager_id' => $this->sid('C')]);
        $this->assertNull($this->owner('cNULL2')['manager_id'], 'all-or-nothing: an already-owned customer in the batch rejects it');
        $sa->postWithToken('reseller/customer-assign-owner', ['customer_ids' => [$this->cid('cNULL2')], 'manager_id' => $this->sid('C'), 'sales_id' => $this->sid('C1')]);
        $this->assertSame([$this->sid('C'), $this->sid('C1')], [(int) $this->owner('cNULL2')['manager_id'], (int) $this->owner('cNULL2')['assigned_sales_id']]);
        $this->assertCount(1, $this->auditRows('customer.assign_legacy_owner'));
        // after assignment the OD-11 gate applies to it immediately
        $r = $sa->postWithToken('plan/recharge-post', ['id_customer' => $this->cid('cNULL2'), 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => 'cash']);
        $this->assertSame(302, $r->status, 'now reseller-managed → legacy recharge refused');
        // no automatic assignment of any other legacy customer happened
        $this->assertNull($this->owner('cNULL')['manager_id']);
    }

    public function testCustomerDeleteHasReferencePreCheckAndIsAudited(): void
    {
        $sa = $this->staff('sa');
        $this->add('sa', 'to_delete_1');
        $id = (int) static::$db->value("SELECT id FROM tbl_customers WHERE username = 'to_delete_1'");
        $r = $sa->postWithToken('customers/delete/' . $id, [], ['token' => $sa->token()]);
        $this->assertSame(302, $r->status);
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE id = ?', [$id]));
        $rows = $this->auditRows('customer.delete');
        $this->assertStringContainsString('to_delete_1', end($rows)['target_snapshot']);
        $this->assertSame(403, $this->staff('A')->postWithToken('customers/delete/' . $this->cid('cA0'))->status, 'Agents never delete (D033)');
    }

    /** Reference pre-check BEFORE any side effect: reseller-domain records block the delete. */
    public function testCustomerReferencedByResellerDomainIsNotDeleted(): void
    {
        $db = static::$db;
        $cid = $this->cid('cNULL');
        $db->exec('SET SESSION foreign_key_checks = 0');
        $evt = $db->insertRow('tbl_reseller_activation_events', ['activation_id' => 999999, 'sequence_no' => 1, 'event_type' => 'ANNOTATION',
            'from_state' => null, 'to_state' => 'CREATED', 'actor_type' => 'CUSTOMER', 'actor_customer_id' => $cid, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->exec('SET SESSION foreign_key_checks = 1');
        try {
            $sa = $this->staff('sa');
            static::$app->clearProbes();
            $before = $this->snapshot();
            $r = $sa->postWithToken('customers/delete/' . $cid, [], ['token' => $sa->token()]);
            $this->assertSame(302, $r->status);
            $this->assertUnchanged($before, 'referenced customer delete');
            $this->assertSame([], static::$app->probe('device'), 'no device removal before the pre-check');
            $this->assertStringContainsString('cannot be deleted', $sa->get('customers/view/' . $cid)->body);
        } finally {
            $db->exec('DELETE FROM tbl_reseller_activation_events WHERE id = ' . (int) $evt);
        }
    }
}
