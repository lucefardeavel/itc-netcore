<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/** SEC-04: server-side target-role table, no self type/status/parent change, audited lifecycle. */
final class StaffManagementTest extends Wp2HttpTestCase
{
    private function create(string $as, array $post): \Reseller\Tests\Support\HttpResponse
    {
        return $this->staff($as)->postWithToken('settings/users-post', $post + ['fullname' => 'New Account', 'password' => 'pw-new-123']);
    }

    private function exists(string $username): bool
    {
        return (int) static::$db->value('SELECT COUNT(*) FROM tbl_users WHERE username = ?', [$username]) === 1;
    }

    public function testRoleEscalationOnCreateIsRejected(): void
    {
        foreach (['A' => ['SuperAdmin', 'Admin', 'Report'], 'ad' => ['SuperAdmin', 'Admin'], 'A1' => ['Sales', 'Agent', 'Report'], 'rp' => ['Report']] as $as => $roles) {
            foreach ($roles as $role) {
                $u = 'esc_' . strtolower($as) . '_' . strtolower($role);
                $this->create($as, ['username' => $u, 'user_type' => $role]);
                $this->assertFalse($this->exists($u), "{$as} must not create {$role}");
            }
        }
        $this->create('sa', ['username' => 'sa_made_admin', 'user_type' => 'Admin']);
        $this->assertTrue($this->exists('sa_made_admin'), 'SuperAdmin creates Admin');
    }

    public function testAgentCreatesOnlyDirectChildrenAndParentIsNeverTakenFromTheForm(): void
    {
        $this->create('A', ['username' => 'a_child_sales', 'user_type' => 'Sales', 'parent_id' => $this->sid('C'), 'root' => $this->sid('C')]);
        $id = (int) static::$db->value("SELECT id FROM tbl_users WHERE username = 'a_child_sales'");
        $this->assertGreaterThan(0, $id);
        $this->assertSame($this->sid('A'), (int) static::$db->value('SELECT root FROM tbl_users WHERE id = ?', [$id]), 'forged parent ignored');
        $this->assertSame($this->sid('A'), (int) static::$db->value('SELECT ancestor_id FROM tbl_manager_tree WHERE descendant_id = ? AND depth = 1', [$id]));
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree WHERE descendant_id = ? AND depth = 0', [$id]), 'enrolled');
        $rows = $this->auditRows('staff.create');
        $this->assertSame($id, (int) end($rows)['target_user_id']);
        // a frozen Agent (F, parent D inactive) cannot create
        $this->create('F', ['username' => 'f_child', 'user_type' => 'Sales']);
        $this->assertFalse($this->exists('f_child'));
        // a non-enrolled legacy Agent cannot create
        $this->create('E', ['username' => 'e_child', 'user_type' => 'Sales']);
        $this->assertFalse($this->exists('e_child'));
    }

    public function testAdminCreatesTreeAccountsUnderValidParentsOnly(): void
    {
        $this->create('ad', ['username' => 'ad_sales_under_c', 'user_type' => 'Sales', 'parent_id' => $this->sid('C')]);
        $this->assertTrue($this->exists('ad_sales_under_c'));
        foreach (['parent_is_sales' => $this->sid('A1'), 'parent_inactive' => $this->sid('D'), 'parent_not_enrolled' => $this->sid('E'), 'no_parent' => ''] as $name => $parent) {
            $this->create('ad', ['username' => 'ad_' . $name, 'user_type' => 'Sales', 'parent_id' => $parent]);
            $this->assertFalse($this->exists('ad_' . $name), $name);
        }
        $this->create('ad', ['username' => 'ad_top_agent', 'user_type' => 'Agent', 'parent_id' => '']);
        $this->assertTrue($this->exists('ad_top_agent'), 'top-level Agent');
    }

    public function testSelfCannotChangeOwnTypeStatusOrParent(): void
    {
        $before = static::$db->rows('SELECT user_type, status, root FROM tbl_users WHERE id = ?', [$this->sid('A2')]);
        $this->staff('A2')->postWithToken('settings/users-edit-post', ['id' => $this->sid('A2'), 'username' => 'A2', 'fullname' => 'Staff A2',
            'user_type' => 'SuperAdmin', 'status' => 'Inactive', 'root' => $this->sid('C'), 'password' => '', 'cpassword' => '']);
        $this->assertSame($before, static::$db->rows('SELECT user_type, status, root FROM tbl_users WHERE id = ?', [$this->sid('A2')]));
        $before = static::$db->rows('SELECT user_type, status, root FROM tbl_users WHERE id = ?', [$this->sid('ad')]);
        $this->staff('ad')->postWithToken('settings/users-edit-post', ['id' => $this->sid('ad'), 'username' => 'ad', 'fullname' => 'Staff ad', 'user_type' => 'SuperAdmin', 'status' => 'Inactive']);
        $this->assertSame($before, static::$db->rows('SELECT user_type, status, root FROM tbl_users WHERE id = ?', [$this->sid('ad')]));
    }

    public function testEditTargetsAreScoped(): void
    {
        // Agent → unrelated Sales; Admin → SuperAdmin; Sales → its Agent; Report → anyone
        $cases = [['A', 'C1'], ['ad', 'sa'], ['A1', 'A'], ['rp', 'A1'], ['B', 'A']];
        $clients = [];
        foreach ($cases as [$as]) {
            $clients[$as] ??= $this->staff($as);
            $clients[$as]->token();
        }
        $before = $this->snapshot();
        foreach ($cases as [$as, $target]) {
            $clients[$as]->postWithToken('settings/users-edit-post', ['id' => $this->sid($target), 'username' => 'pwned_' . $target, 'fullname' => 'Pwned Name',
                'password' => 'pwned-pass', 'cpassword' => 'pwned-pass', 'user_type' => 'Report', 'status' => 'Inactive']);
        }
        $this->assertUnchanged($before, 'out-of-scope staff edits');
        // Agent manages its strict descendants (profile + status), audited
        $r = $this->staff('A')->postWithToken('settings/users-edit-post', ['id' => $this->sid('A2'), 'username' => 'A2-renamed', 'fullname' => 'Staff A2', 'password' => '', 'cpassword' => '']);
        $this->assertSame(302, $r->status);
        $this->assertSame('A2-renamed', static::$db->value('SELECT username FROM tbl_users WHERE id = ?', [$this->sid('A2')]));
        static::$db->exec("UPDATE tbl_users SET username = 'A2' WHERE id = " . $this->sid('A2'));
        $rows = $this->auditRows('staff.update');
        $this->assertStringContainsString('A2-renamed', end($rows)['after_json']);
    }

    public function testDeleteRules(): void
    {
        $ad = $this->staff('ad');
        $ad->postWithToken('settings/users-delete/' . $this->sid('sa'));
        $this->assertTrue($this->exists('sa'), 'Admin cannot delete a SuperAdmin');
        $ad->postWithToken('settings/users-delete/' . $this->sid('C1'));
        $this->assertTrue($this->exists('C1'), 'Admin deletes Report accounts only');
        $sa = $this->staff('sa');
        $r = $sa->postWithToken('settings/users-delete/' . $this->sid('A'));
        $this->assertSame(302, $r->status);
        $this->assertTrue($this->exists('A'), 'enrolled/referenced account is not deleted (disable instead)');
        $page = $sa->get('settings/users');
        $this->assertStringContainsString('cannot be deleted', $page->body);
        $sa->postWithToken('settings/users-delete/' . $this->sid('sa'));
        $this->assertTrue($this->exists('sa'), 'no self delete');
    }

    public function testRoleChangeIsTreeSafeAndLastSuperAdminProtected(): void
    {
        $sa = $this->staff('sa');
        $sa->postWithToken('settings/users-edit-post', ['id' => $this->sid('A'), 'username' => 'A', 'fullname' => 'Staff A', 'user_type' => 'Admin']);
        $this->assertSame('Agent', static::$db->value('SELECT user_type FROM tbl_users WHERE id = ?', [$this->sid('A')]), 'enrolled Agent cannot become Admin');
        $sa->postWithToken('settings/users-edit-post', ['id' => $this->sid('rp'), 'username' => 'rp_', 'fullname' => 'Staff rp', 'user_type' => 'Sales']);
        $this->assertSame('Sales', static::$db->value('SELECT user_type FROM tbl_users WHERE id = ?', [$this->sid('rp')]), 'unreferenced Report → Sales is tree-safe (not enrolled)');
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree WHERE descendant_id = ?', [$this->sid('rp')]));
        static::$db->exec("UPDATE tbl_users SET user_type = 'Report', username = 'rp' WHERE id = " . $this->sid('rp'));
        $this->assertNotEmpty($this->auditRows('staff.role_change'));
        // the only other active SuperAdmin ('admin') is disabled → sa is last
        static::$db->exec("UPDATE tbl_users SET status = 'Inactive' WHERE username = 'admin'");
        try {
            $sa->postWithToken('settings/users-post', ['username' => 'sa2', 'fullname' => 'Second SA', 'password' => 'pw-sa2-123', 'user_type' => 'SuperAdmin']);
            $sa2 = (int) static::$db->value("SELECT id FROM tbl_users WHERE username = 'sa2'");
            $c = $this->client();
            $c->loginStaff('sa2', 'pw-sa2-123');
            $c->postWithToken('reseller/status-post', ['user_id' => $this->sid('sa'), 'status' => 'Inactive']);
            $this->assertSame('Inactive', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('sa')]), 'another SA may disable sa');
            $this->staff('ad')->postWithToken('reseller/status-post', ['user_id' => $sa2, 'status' => 'Inactive']);
            $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$sa2]), 'Admin cannot disable a SuperAdmin; last SA stays active');
        } finally {
            static::$db->exec("UPDATE tbl_users SET status = 'Active' WHERE username IN ('admin', 'sa')");
        }
    }
}
