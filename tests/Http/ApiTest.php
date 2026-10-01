<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * OD-3 / OD-15 / SEC-21: actor-bound a2 tokens signed with a dedicated secret; password change and
 * disable revoke; retired formats refused; scoped like the web; sanitized responses; allowlist.
 */
final class ApiTest extends Wp2HttpTestCase
{
    private function forge(int $uid, int $iat, string $passwordHash, ?string $secret = null): string
    {
        $secret ??= (string) static::$app->apiSecret();
        return 'a2.' . $uid . '.' . $iat . '.' . hash_hmac('sha256', 'nuxr-api-staff|v2|' . $uid . '|' . $iat . '|' . $passwordHash, $secret);
    }

    private function hash(string $u): string
    {
        return (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$this->sid($u)]);
    }

    public function testValidTokenWorksAndResponsesAreSanitized(): void
    {
        $t = $this->apiToken('sa');
        $r = $this->client()->api('GET', 'customers/list', [], $t);
        $j = $r->json();
        $this->assertTrue($j['success'], $r->excerpt());
        $flat = json_encode($j);
        foreach (['"password"', '"pppoe_password"', '"login_token"', '"_c"', '"csrf_token"', '"api_key"', 'cpw-cA1', (string) getenv('WP1_DB_ROOT_PASSWORD')] as $needle) {
            $this->assertStringNotContainsString($needle, $flat, "API response must not contain {$needle}");
        }
        $this->assertStringContainsString('cA1', $flat);
        $me = $this->client()->api('GET', 'me', [], $t)->json();
        $this->assertSame(['id', 'username', 'fullname', 'user_type'], array_keys($me['result']));
        $this->assertSame('sa', $me['result']['username']);
        // bearer header and legacy token parameter both work
        $this->assertTrue($this->client()->api('GET', 'isValid', [], $t, [], false)->json()['success']);
    }

    public function testInvalidTokensAreRefused(): void
    {
        $uid = $this->sid('ad');
        $hash = $this->hash('ad');
        $now = time();
        $valid = $this->forge($uid, $now, $hash);
        $this->assertTrue($this->client()->api('GET', 'isValid', [], $valid)->json()['success'], 'control: a correctly signed token works');
        $bad = [
            'tampered uid' => 'a2.' . $this->sid('sa') . substr($valid, strlen('a2.' . $uid)),
            'tampered iat' => 'a2.' . $uid . '.' . ($now - 5) . substr($valid, strlen('a2.' . $uid . '.' . $now)),
            'tampered mac' => substr($valid, 0, -1) . (substr($valid, -1) === 'a' ? 'b' : 'a'),
            'expired' => $this->forge($uid, $now - 7776000 - 10, $hash),
            'future' => $this->forge($uid, $now + 3600, $hash),
            'time zero' => $this->forge($uid, 0, $hash),
            'wrong secret' => $this->forge($uid, $now, $hash, str_repeat('z', 64)),
            'signed with db_pass' => $this->forge($uid, $now, $hash, (string) getenv('WP1_DB_ROOT_PASSWORD')),
            'legacy a.' => 'a.' . $uid . '.' . $now . '.' . sha1($uid . '.' . $now . '.' . (string) getenv('WP1_DB_ROOT_PASSWORD')),
            'legacy c.' => 'c.' . $this->cid('cA1') . '.' . $now . '.' . sha1('x'),
            'legacy u.' => 'u.Array',
            'garbage' => 'a2.x.y.z',
            'global api_key' => 'global-api-key-value-for-tests',
        ];
        static::$db->exec("DELETE FROM tbl_appconfig WHERE setting = 'api_key'");
        static::$db->insertRow('tbl_appconfig', ['setting' => 'api_key', 'value' => 'global-api-key-value-for-tests']);
        foreach ($bad as $name => $token) {
            foreach ([true, false] as $bearer) {
                $r = $this->client()->api('GET', 'customers/list', [], $token, [], $bearer);
                $this->assertFalse($r->json()['success'] ?? true, "{$name} must be refused");
                $this->assertStringNotContainsString('cA1', $r->body, $name);
                $this->assertFalse($r->leaksInternals(), $name);
            }
        }
    }

    public function testPasswordChangeRevokesEarlierTokens(): void
    {
        // self change through the web form
        $old = $this->apiToken('A2');
        $c = $this->staff('A2');
        $r = $c->postWithToken('settings/change-password-post', ['password' => Wp2Seed::password('A2'), 'npass' => 'newpass-A2', 'cnpass' => 'newpass-A2']);
        $this->assertSame(302, $r->status);
        $this->assertFalse($this->client()->api('GET', 'isValid', [], $old)->json()['success'], 'token revoked by own password change');
        $new = $this->apiToken('A2', 'newpass-A2');
        $this->assertTrue($this->client()->api('GET', 'isValid', [], $new)->json()['success']);
        // change by an administrator (users-edit-post)
        $old = $this->apiToken('C1');
        $r = $this->staff('ad')->postWithToken('settings/users-edit-post', [
            'id' => $this->sid('C1'), 'username' => 'C1-renamed', 'fullname' => 'Staff C1', 'password' => 'reset-C1-pw', 'cpassword' => 'reset-C1-pw',
            'phone' => '', 'email' => '', 'city' => '', 'subdistrict' => '', 'ward' => '',
        ]);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertFalse($this->client()->api('GET', 'isValid', [], $old)->json()['success'], 'token revoked by admin password reset');
        $this->assertTrue($this->client()->api('GET', 'isValid', [], $this->apiToken('C1-renamed', 'reset-C1-pw'))->json()['success']);
    }

    public function testDisabledUserTokenIsRefused(): void
    {
        $t = $this->apiToken('rp');
        $this->assertTrue($this->client()->api('GET', 'isValid', [], $t)->json()['success']);
        static::$db->exec("UPDATE tbl_users SET status = 'Inactive' WHERE id = " . $this->sid('rp'));
        try {
            $this->assertFalse($this->client()->api('GET', 'isValid', [], $t)->json()['success']);
            $r = $this->client()->api('POST', 'admin/post', ['username' => 'rp', 'password' => Wp2Seed::password('rp')]);
            $this->assertFalse($r->json()['success'], 'inactive users cannot obtain a token');
        } finally {
            static::$db->exec("UPDATE tbl_users SET status = 'Active' WHERE id = " . $this->sid('rp'));
        }
    }

    public function testApiInheritsTheActorScopeAndAllowlist(): void
    {
        $a1 = $this->apiToken('A1');
        $list = $this->client()->api('GET', 'customers/list', [], $a1);
        $this->assertTrue($list->json()['success'], $list->excerpt());
        $this->assertStringContainsString('cA1', $list->body);
        foreach (['cA2', 'cC1', 'cNULL', 'cB'] as $other) {
            $this->assertStringNotContainsString('"' . $other . '"', $list->body, "Sales API list must not contain {$other}");
            $r = $this->client()->api('GET', 'customers/view/' . $this->cid($other), [], $a1);
            $this->assertFalse($r->json()['success'], "Sales API view {$other}");
        }
        $sa = $this->apiToken('sa');
        foreach (['plugin/phpinfo', 'plugin/wp2probe_admin_page', 'customers/login/' . $this->cid('cNULL'), 'settings/dbbackup', 'settings/dbrestore',
            'pluginmanager', 'widgets', 'pages/Announcement', 'paymentgateway', 'callback/x', 'home', 'order/package', 'wp2probe', 'settings/app-post'] as $route) {
            $r = $this->client()->api('POST', $route, [], $sa);
            $this->assertFalse($r->json()['success'] ?? true, "API {$route} must not be available");
            $this->assertStringNotContainsString('WP2PROBE', $r->body);
            $this->assertStringNotContainsString('phpinfo', strtolower(str_replace('plugin/phpinfo', '', $r->body)));
        }
        // no token: anonymous → staff routes denied
        foreach (['customers/list', 'settings/users', 'reseller/tree'] as $route) {
            $this->assertFalse($this->client()->api('GET', $route)->json()['success'], "anonymous API {$route}");
        }
        // the token carries the user's own capabilities: Report has no customer access
        $rp = $this->apiToken('rp');
        $this->assertFalse($this->client()->api('GET', 'customers/list', [], $rp)->json()['success']);
    }
}

