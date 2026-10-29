<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab candidate F-3 (ITC NetCore lab 2026-10-24, owner direction D-21): settings/users-view, users-edit and
 * users-edit-post are reachable by every staff role at the route level; the per-target scope check lives in the
 * controller only. Reproduce or dismiss: staff outside the target's subtree (another Agent's tree, a Sales user, the
 * Report role) must neither see another account's details nor change them, through the real routes.
 */
final class W10StaffAccountScopeTest extends Wp2HttpTestCase
{
    public function testStaffOutsideTheSubtreeCannotViewOrEditAnotherAccount(): void
    {
        $cases = [
            ['C', 'A1'],   // an Agent opens a Sales account of another Agent's tree
            ['C', 'A'],    // an Agent opens another top-level Agent
            ['C1', 'A1'],  // a Sales user opens a Sales user of another tree
            ['A1', 'A2'],  // a Sales user opens a sibling Sales user
            ['A1', 'A'],   // a Sales user opens its own parent Agent
            ['B', 'A'],    // a sub-Agent opens its parent
            ['rp', 'A1'],  // Report opens a Sales account
        ];
        foreach ($cases as [$actor, $target]) {
            $tid = static::$seed->staff[$target];
            $row = static::$db->rows('SELECT username, fullname, user_type, root, status, password, phone, email FROM tbl_users WHERE id = ?', [$tid])[0];
            $c = $this->staff($actor);
            foreach (["settings/users-view/{$tid}", "settings/users-edit/{$tid}"] as $route) {
                $r = $c->get($route);
                // the target's full name is a seed value ("Staff <name>" or similar): a page that renders the edit form of the
                // target carries its username in the username input
                $leak = $r->status === 200 && preg_match('/name="username"[^>]*value="' . preg_quote($target, '/') . '"/', $r->body) === 1;
                $this->assertFalse($leak, "{$actor} -> {$route}: the edit form of {$target} is rendered (HTTP {$r->status})");
            }
            $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events', 'tbl_users']);
            $c->postWithToken('settings/users-edit-post', ['id' => $tid, 'username' => $target, 'fullname' => 'F3 ' . $actor, 'user_type' => $row['user_type'],
                'status' => 'Inactive', 'phone' => '0700999', 'email' => 'f3@example.invalid', 'password' => '', 'cpassword' => ''], [], 'settings/users');
            $after = static::$db->rows('SELECT username, fullname, user_type, root, status, password, phone, email FROM tbl_users WHERE id = ?', [$tid])[0];
            $this->assertSame($row, $after, "{$actor} changed {$target} through settings/users-edit-post");
            $this->assertUnchanged($before, "{$actor} -> users-edit-post {$target}", ['tbl_logs', 'tbl_reseller_audit_events', 'tbl_users']);
        }
    }

    public function testPositiveControlSuperAdminAndTheParentAgentCanOpenTheAccount(): void
    {
        $tid = static::$seed->staff['A1'];
        foreach (['sa', 'A'] as $actor) {
            $r = $this->staff($actor)->get("settings/users-edit/{$tid}");
            $this->assertSame(200, $r->status, "{$actor} opens A1's edit page");
            $this->assertMatchesRegularExpression('/name="username"[^>]*value="A1"/', $r->body, "{$actor}: the form of A1 is rendered (selector valid)");
        }
    }
}
