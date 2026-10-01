<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * SEC-10 / SEC-18 (minimal): status re-checked on every request, password-bound sessions and
 * cookies, session regeneration, logout, default-credential restriction.
 */
final class AuthSessionTest extends Wp2HttpTestCase
{
    public function testInactiveStaffCannotLoginAndGetsTheSameMessage(): void
    {
        $c = $this->client();
        $bad = $c->loginStaff('sa', 'wrong-password');
        $inactive = $this->client()->loginStaff('D', Wp2Seed::password('D'));
        $this->assertStringContainsString('Invalid Username or Password', $inactive->body);
        $this->assertSame($bad->status, $inactive->status);
        $this->assertSame(302, $c->get('dashboard')->status);
    }

    public function testDisabledMidSessionIsCutOffImmediately(): void
    {
        $b = $this->staff('B');
        $this->assertSame(200, $b->get('dashboard')->status);
        $token = $b->token();
        // A (B's parent) disables B (OD-13: strict descendant)
        $r = $this->staff('A')->postWithToken('reseller/status-post', ['user_id' => $this->sid('B'), 'status' => 'Inactive']);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame('Inactive', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('B')]));
        $r = $b->get('dashboard');
        $this->assertSame(302, $r->status, 'disabled user is logged out on the next request');
        $this->assertStringContainsString('login', (string) $r->location());
        $before = $this->snapshot();
        $r = $b->post('customers/add-post', ['csrf_token' => $token, 'username' => 'by_disabled', 'fullname' => 'X', 'password' => 'abc123']);
        $this->assertNotSame(200, $r->status);
        $this->assertUnchanged($before, 'forged POST by a disabled user');
        $this->assertNotEmpty($this->auditRows('staff.disable'));
        // reactivate for the remaining tests
        $this->staff('A')->postWithToken('reseller/status-post', ['user_id' => $this->sid('B'), 'status' => 'Active']);
        $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('B')]));
        $this->assertNotEmpty($this->auditRows('staff.reactivate'));
    }

    public function testPasswordChangeInvalidatesOtherSessionsAndCookies(): void
    {
        $s1 = $this->staff('A1');
        $s2 = $this->staff('A1');
        $cookie = $s2->cookies['aid'] ?? null;
        $this->assertNotNull($cookie, 'remember cookie issued at login');
        $r = $s1->postWithToken('settings/change-password-post', ['password' => Wp2Seed::password('A1'), 'npass' => 'new-A1-pass', 'cnpass' => 'new-A1-pass']);
        $this->assertSame(302, $r->status);
        $this->assertSame(302, $s2->get('dashboard')->status, 'other session invalidated by the password change');
        // the old remember cookie alone (fresh session) no longer authenticates
        $c = $this->client();
        $c->cookies = ['aid' => $cookie];
        $this->assertSame(302, $c->get('dashboard')->status, 'old remember cookie refused');
        $c = $this->client();
        $c->loginStaff('A1', 'new-A1-pass');
        $this->assertSame(200, $c->get('dashboard')->status);
        static::$db->exec("UPDATE tbl_users SET password = '" . sha1(Wp2Seed::password('A1')) . "' WHERE id = " . $this->sid('A1'));
    }

    public function testRememberCookieWorksAndIsBoundToUserAndPassword(): void
    {
        $c = $this->staff('C');
        $cookie = $c->cookies['aid'];
        $fresh = $this->client();
        $fresh->cookies = ['aid' => $cookie];
        $this->assertSame(200, $fresh->get('dashboard')->status, 'valid remember cookie re-authenticates');
        [$id, $time, $mac] = explode('.', $cookie);
        foreach ([$this->sid('sa') . '.' . $time . '.' . $mac, $id . '.' . ($time + 1) . '.' . $mac, $id . '.' . $time . '.' . sha1($id . '.' . $time . '.' . getenv('WP1_DB_ROOT_PASSWORD'))] as $forged) {
            $f = $this->client();
            $f->cookies = ['aid' => $forged];
            $this->assertSame(302, $f->get('dashboard')->status, 'forged/legacy cookie refused: ' . $forged);
        }
    }

    public function testSessionIdRegeneratedOnLoginAndLogoutClearsEverything(): void
    {
        $c = $this->client();
        $c->get('admin');
        $before = $c->cookies['PHPSESSID'] ?? null;
        $this->assertNotNull($before);
        $c->loginStaff('C', Wp2Seed::password('C'));
        $this->assertNotSame($before, $c->cookies['PHPSESSID'] ?? null, 'session fixation: id regenerated on login');
        $this->assertNotEmpty(static::$db->value('SELECT login_token FROM tbl_users WHERE id = ?', [$this->sid('C')]));
        $c->get('logout');
        $this->assertSame('', (string) static::$db->value('SELECT login_token FROM tbl_users WHERE id = ?', [$this->sid('C')]), 'login token cleared');
        $this->assertSame(302, $c->get('dashboard')->status);
        $this->assertArrayNotHasKey('aid', $c->cookies);
    }

    public function testDeletedUserSessionIsUnauthenticated(): void
    {
        $sa = $this->staff('sa');
        $sa->postWithToken('settings/users-post', ['username' => 'tmp_report_x', 'fullname' => 'Tmp Report', 'password' => 'pw-tmp-123', 'user_type' => 'Report']);
        $id = (int) static::$db->value("SELECT id FROM tbl_users WHERE username = 'tmp_report_x'");
        $this->assertGreaterThan(0, $id);
        $victim = $this->client();
        $victim->loginStaff('tmp_report_x', 'pw-tmp-123');
        $this->assertSame(200, $victim->get('dashboard')->status);
        $r = $sa->postWithToken('settings/users-delete/' . $id);
        $this->assertSame(302, $r->status);
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_users WHERE id = ?', [$id]));
        $this->assertSame(302, $victim->get('dashboard')->status, 'deleted user is unauthenticated');
        $rows = $this->auditRows('staff.delete');
        $this->assertStringContainsString('tmp_report_x', end($rows)['target_snapshot'], 'identity snapshot survives the deletion');
    }

    public function testDefaultCredentialMustBeChangedFirst(): void
    {
        static::$db->exec("UPDATE tbl_users SET password = '" . sha1('admin') . "' WHERE username = 'admin'");
        try {
            $c = $this->client();
            $c->loginStaff('admin', 'admin');
            foreach (['dashboard', 'customers/list', 'settings/users', 'settings/app'] as $route) {
                $r = $c->get($route);
                $this->assertSame(302, $r->status, $route);
                $this->assertStringContainsString('change-password', (string) $r->location(), $route);
            }
            $this->assertSame(200, $c->get('settings/change-password')->status);
            $r = $c->postWithToken('settings/change-password-post', ['password' => 'admin', 'npass' => 'changed-admin', 'cnpass' => 'changed-admin'], [], 'settings/change-password');
            $this->assertSame(302, $r->status);
            $c2 = $this->client();
            $c2->loginStaff('admin', 'changed-admin');
            $this->assertSame(200, $c2->get('dashboard')->status, 'after changing the default credential the account works');
        } finally {
            static::$db->exec("UPDATE tbl_users SET password = '" . sha1('wp2-default-admin-changed') . "' WHERE username = 'admin'");
        }
    }
}
