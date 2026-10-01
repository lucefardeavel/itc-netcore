<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Frozen V1 reseller lifecycle (D033 / 18_BUSINESS_RULES, owner review fix 1): an Agent is a reseller
 * identity. Agent permanent deletion is prohibited in V1 for every actor — enrolled or legacy,
 * referenced or not — and an Agent's role can never be changed (that would open a re-label-then-delete
 * path). The lifecycle is disable / reactivate. Deletion rules of the other roles are unchanged.
 */
final class ResellerLifecycleTest extends Wp2HttpTestCase
{
    /** Activity tables that legitimately change on a refused request (audit of refusals, logs). */
    private const ACTIVITY = ['tbl_logs', 'tbl_reseller_audit_events'];

    private function userId(string $username): int
    {
        return (int) static::$db->value('SELECT id FROM tbl_users WHERE username = ?', [$username]);
    }

    private function exists(int $id): bool
    {
        return (int) static::$db->value('SELECT COUNT(*) FROM tbl_users WHERE id = ?', [$id]) === 1;
    }

    /** A legacy Agent row as it exists on upgraded installations: not enrolled, no parent, no references. */
    private function legacyAgent(string $username): int
    {
        return static::$db->insertRow('tbl_users', [
            'username' => $username, 'fullname' => 'Legacy ' . $username, 'password' => sha1('pw-' . $username),
            'user_type' => 'Agent', 'status' => 'Active', 'root' => 0,
            'creationdate' => gmdate('Y-m-d H:i:s'), 'last_login' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function deleteAudits(int $id): int
    {
        return (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE event_type = 'staff.delete' AND target_user_id = ?", [$id]);
    }

    /** Owner tests 1 + 3: SuperAdmin cannot delete an enrolled Agent; zero mutation. */
    public function testSuperAdminCannotDeleteAnEnrolledAgent(): void
    {
        $sa = $this->staff('sa');
        $sa->postWithToken('settings/users-post', ['username' => 'lc_enrolled', 'fullname' => 'Lifecycle Enrolled', 'password' => 'pw-lc-enrolled', 'user_type' => 'Agent', 'parent_id' => '']);
        $fresh = $this->userId('lc_enrolled');
        $this->assertGreaterThan(0, $fresh);
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree WHERE descendant_id = ? AND depth = 0', [$fresh]), 'enrolled');
        foreach (['seeded Agent A (children, customers)' => $this->sid('A'), 'fresh enrolled Agent (no customers, no children)' => $fresh] as $label => $id) {
            $before = $this->snapshot(self::ACTIVITY);
            $r = $sa->postWithToken('settings/users-delete/' . $id);
            $this->assertSame(302, $r->status, $label);
            $this->assertTrue($this->exists($id), "{$label} still exists");
            $this->assertUnchanged($before, $label, self::ACTIVITY);
            $this->assertSame(0, $this->deleteAudits($id));
            $this->assertStringContainsString('Agent (reseller) accounts cannot be deleted in V1', $sa->get('settings/users')->body, $label);
        }
    }

    /** Owner tests 2 + 3: an unreferenced, non-enrolled (legacy) Agent cannot be deleted either. */
    public function testSuperAdminCannotDeleteAnUnreferencedNonEnrolledAgent(): void
    {
        $id = $this->legacyAgent('lc_legacy');
        // precondition: nothing references the account, so only the explicit V1 rule can refuse
        $probe = static::$app->exec(sprintf(
            '$pdo = ORM::get_db(); $result = ['
            . '"referenced" => \Reseller\Domain\StaffService::isReferenced($pdo, "tbl_users", %1$d),'
            . '"children" => (int) $pdo->query("SELECT COUNT(*) FROM tbl_users WHERE root = %1$d")->fetchColumn(),'
            . '"tree" => (int) $pdo->query("SELECT COUNT(*) FROM tbl_manager_tree WHERE ancestor_id = %1$d OR descendant_id = %1$d")->fetchColumn(),'
            . '"customers" => (int) $pdo->query("SELECT COUNT(*) FROM tbl_customers WHERE manager_id = %1$d OR assigned_sales_id = %1$d")->fetchColumn()];',
            $id
        ));
        $this->assertSame(['referenced' => false, 'children' => 0, 'tree' => 0, 'customers' => 0], $probe, 'the Agent is unreferenced and not enrolled');

        $sa = $this->staff('sa');
        $before = $this->snapshot(self::ACTIVITY);
        $r = $sa->postWithToken('settings/users-delete/' . $id);
        $this->assertSame(302, $r->status);
        $this->assertTrue($this->exists($id), 'unreferenced legacy Agent is NOT deleted');
        $this->assertUnchanged($before, 'denied delete of an unreferenced legacy Agent', self::ACTIVITY);
        $this->assertSame(0, $this->deleteAudits($id));
        $this->assertStringContainsString('Agent (reseller) accounts cannot be deleted in V1', $sa->get('settings/users')->body);
        // the staff list offers no delete control for Agent rows (cosmetic only; the rule is server-side)
        $list = $sa->get('settings/users', ['search' => 'lc_legacy'])->body;
        $this->assertStringContainsString('lc_legacy', $list);
        $this->assertStringNotContainsString('settings/users-delete/' . $id . '"', $list);
        $list = $sa->get('settings/users', ['search' => 'rp'])->body;
        $this->assertStringContainsString('settings/users-delete/' . $this->sid('rp') . '"', $list, 'delete control still shown for other roles');

        // the service itself refuses, independent of routes and UI (real SuperAdmin context, CLI channel)
        $res = static::$app->exec(sprintf(
            '$row = ORM::for_table("tbl_users")->find_one(%d)->as_array();'
            . '$a = \Reseller\Security\ActorContext::forStaff($row, "CLI");'
            . 'try { \Reseller\Domain\StaffService::delete($a, %d); $result = "deleted"; }'
            . ' catch (\Reseller\Domain\DomainError $e) { $result = $e->reason; }',
            $this->sid('sa'),
            $id
        ));
        $this->assertSame('AGENT_DELETE_PROHIBITED', $res);
        $this->assertTrue($this->exists($id));
        $this->assertUnchanged($before, 'direct service call', self::ACTIVITY);
    }

    /** Owner tests 4 + 5: the lifecycle is disable / reactivate. */
    public function testTheSameAgentCanBeDisabledAndReactivated(): void
    {
        $id = $this->legacyAgent('lc_cycle');
        $sa = $this->staff('sa');
        $r = $sa->postWithToken('settings/users-delete/' . $id);
        $this->assertSame(302, $r->status);
        $this->assertTrue($this->exists($id));
        $sa->postWithToken('reseller/status-post', ['user_id' => $id, 'status' => 'Inactive']);
        $this->assertSame('Inactive', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$id]), 'disabled');
        $sa->postWithToken('reseller/status-post', ['user_id' => $id, 'status' => 'Active']);
        $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$id]), 'reactivated');
        $disable = static::$db->rows("SELECT event_type FROM tbl_reseller_audit_events WHERE target_user_id = ? AND event_type IN ('staff.disable','staff.reactivate') ORDER BY id", [$id]);
        $this->assertSame(['staff.disable', 'staff.reactivate'], array_column($disable, 'event_type'));
        // an Agent disabling/reactivating its strict descendant Agent (OD-13) still works
        $a = $this->staff('A');
        $a->postWithToken('reseller/status-post', ['user_id' => $this->sid('B'), 'status' => 'Inactive']);
        $this->assertSame('Inactive', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('B')]));
        $a->postWithToken('reseller/status-post', ['user_id' => $this->sid('B'), 'status' => 'Active']);
        $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('B')]));
    }

    /** Owner test 6: no bypass through forged POST, the API, a direct URL or a re-label-then-delete. */
    public function testNoRoleCanBypassTheRule(): void
    {
        $id = $this->legacyAgent('lc_bypass');
        $clients = ['ad' => $this->staff('ad'), 'A' => $this->staff('A'), 'A1' => $this->staff('A1'), 'rp' => $this->staff('rp'), 'sa' => $this->staff('sa')];
        foreach ($clients as $c) {
            $c->token();
        }
        // API logins update last_login: obtain the tokens before the no-mutation window
        $tokens = ['sa' => $this->apiToken('sa'), 'ad' => $this->apiToken('ad')];
        $before = $this->snapshot(self::ACTIVITY);
        // forged POSTs (valid CSRF) by every role: Admin passes RouteGuard but is refused by the service;
        // Agent / Sales / Report do not hold staff.delete at all
        $this->assertSame(302, $clients['ad']->postWithToken('settings/users-delete/' . $id)->status);
        $this->assertSame(302, $clients['ad']->postWithToken('settings/users-delete/' . $this->sid('B'))->status);
        foreach (['A' => $this->sid('B'), 'A1' => $id, 'rp' => $id] as $u => $target) {
            $this->assertSame(403, $clients[$u]->postWithToken('settings/users-delete/' . $target)->status, "{$u} has no staff.delete");
            $this->assertSame(403, $clients[$u]->postWithToken('settings/users-delete/' . $id)->status, "{$u} has no staff.delete");
        }
        // direct URL (GET) is refused before any controller code
        foreach ($clients as $u => $c) {
            $this->assertContains($c->get('settings/users-delete/' . $id)->status, [403, 405], "{$u} GET");
        }
        $this->assertSame(405, $clients['sa']->get('settings/users-delete/' . $id)->status, 'GET on a POST-only mutation');
        // API with SuperAdmin and Admin tokens
        foreach (['sa', 'ad'] as $u) {
            $token = $tokens[$u];
            foreach (['POST', 'GET'] as $method) {
                $r = $this->client()->api($method, 'settings/users-delete/' . $id, [], $token);
                $this->assertFalse($r->json()['success'] ?? true, "API {$u} {$method}: " . $r->excerpt());
            }
            $r = $this->client()->api('POST', 'settings/users-delete/' . $this->sid('B'), [], $token);
            $this->assertFalse($r->json()['success'] ?? true, "API {$u} enrolled Agent");
        }
        $this->assertTrue($this->exists($id));
        $this->assertTrue($this->exists($this->sid('B')));
        $this->assertUnchanged($before, 'every bypass attempt', self::ACTIVITY);

        // two-step bypass: re-label the Agent as Report (role change), then delete it — refused at step 1
        $profile = ['id' => $id, 'username' => 'lc_bypass', 'fullname' => 'Legacy lc_bypass', 'password' => '', 'cpassword' => '',
            'phone' => '', 'email' => '', 'city' => '', 'subdistrict' => '', 'ward' => ''];
        foreach (['sa', 'ad'] as $u) {
            foreach (['Admin', 'Sales', 'Report'] as $role) {
                $r = $clients[$u]->postWithToken('settings/users-edit-post', ['user_type' => $role] + $profile);
                $this->assertSame(302, $r->status, "{$u} → {$role}");
                $this->assertSame('Agent', static::$db->value('SELECT user_type FROM tbl_users WHERE id = ?', [$id]), "{$u} cannot re-label an Agent as {$role}");
            }
            // the last refusal (→ Report, a role both may otherwise assign) names the V1 rule
            $this->assertStringContainsString('cannot be changed in V1', $clients[$u]->get('settings/users-edit/' . $id)->body, $u);
            $clients[$u]->postWithToken('settings/users-delete/' . $id);
            $this->assertTrue($this->exists($id), "{$u}: still not deletable");
        }
        // the role selector is not offered for an Agent target (cosmetic; changeRole() enforces it)
        $this->assertStringNotContainsString('name="user_type"', $clients['sa']->get('settings/users-edit/' . $id)->body);
        $token = $tokens['sa'];
        $r = $this->client()->api('POST', 'settings/users-edit-post', ['user_type' => 'Report'] + $profile, $token);
        $this->assertFalse($r->json()['success'] ?? true, 'API re-label: ' . $r->excerpt());
        $this->assertSame('Agent', static::$db->value('SELECT user_type FROM tbl_users WHERE id = ?', [$id]));
        $this->client()->api('POST', 'settings/users-delete/' . $id, [], $token);
        $this->assertTrue($this->exists($id));
        $this->assertSame(0, (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE target_user_id = ? AND event_type IN ('staff.delete','staff.role_change')", [$id]));
        // the service refuses the role change directly as well
        $res = static::$app->exec(sprintf(
            '$row = ORM::for_table("tbl_users")->find_one(%d)->as_array();'
            . '$a = \Reseller\Security\ActorContext::forStaff($row, "CLI");'
            . 'try { \Reseller\Domain\StaffService::changeRole($a, %d, "Report"); $result = "changed"; }'
            . ' catch (\Reseller\Domain\DomainError $e) { $result = $e->reason; }',
            $this->sid('sa'),
            $id
        ));
        $this->assertSame('AGENT_ROLE_PERMANENT', $res);
    }

    /** Owner test 7: permitted deletions of non-reseller roles are unchanged (accepted matrix). */
    public function testPermittedDeletionsOfOtherRolesAreUnchanged(): void
    {
        $sa = $this->staff('sa');
        $ad = $this->staff('ad');
        $mk = function (string $username, string $role): int {
            return static::$db->insertRow('tbl_users', [
                'username' => $username, 'fullname' => 'Del ' . $username, 'password' => sha1('pw-' . $username),
                'user_type' => $role, 'status' => 'Active', 'root' => 0,
                'creationdate' => gmdate('Y-m-d H:i:s'), 'last_login' => gmdate('Y-m-d H:i:s'),
            ]);
        };
        // SuperAdmin: unreferenced, never-enrolled Report / Sales / Admin accounts
        foreach (['lc_del_report' => 'Report', 'lc_del_sales' => 'Sales', 'lc_del_admin' => 'Admin'] as $u => $role) {
            $id = $mk($u, $role);
            $r = $sa->postWithToken('settings/users-delete/' . $id);
            $this->assertSame(302, $r->status);
            $this->assertFalse($this->exists($id), "SuperAdmin deletes an unreferenced {$role}");
            $this->assertSame(1, $this->deleteAudits($id), "{$role} delete audited");
        }
        // Admin: Report accounts only
        $rep = $mk('lc_ad_report', 'Report');
        $ad->postWithToken('settings/users-delete/' . $rep);
        $this->assertFalse($this->exists($rep), 'Admin deletes an unreferenced Report');
        $adm = $mk('lc_ad_admin', 'Admin');
        $ad->postWithToken('settings/users-delete/' . $adm);
        $this->assertTrue($this->exists($adm), 'Admin cannot delete an Admin');
        $ad->postWithToken('settings/users-delete/' . $this->sid('sa'));
        $this->assertTrue($this->exists($this->sid('sa')), 'Admin cannot delete a SuperAdmin');
        // an enrolled Sales account stays non-deletable (referenced by the hierarchy): disable instead
        $sa->postWithToken('settings/users-delete/' . $this->sid('A2'));
        $this->assertTrue($this->exists($this->sid('A2')));
        // role changes TO Agent keep the accepted tree-safe behaviour (the account is not enrolled)
        $toAgent = $mk('lc_to_agent', 'Report');
        $sa->postWithToken('settings/users-edit-post', ['id' => $toAgent, 'username' => 'lc_to_agent', 'fullname' => 'Del lc_to_agent', 'password' => '', 'cpassword' => '',
            'phone' => '', 'email' => '', 'city' => '', 'subdistrict' => '', 'ward' => '', 'user_type' => 'Agent']);
        $this->assertSame('Agent', static::$db->value('SELECT user_type FROM tbl_users WHERE id = ?', [$toAgent]));
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree WHERE descendant_id = ?', [$toAgent]), 'not enrolled by a role change');
        // ... and from then on it is a reseller identity
        $sa->postWithToken('settings/users-delete/' . $toAgent);
        $this->assertTrue($this->exists($toAgent));
    }
}
