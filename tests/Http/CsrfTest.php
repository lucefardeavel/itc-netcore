<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * OD-8 / SEC-09: central CSRF regardless of csrf_enabled. Every browser POST needs the session
 * token; mutating GETs are refused (405); the API (bearer token) and callbacks are separate. Public
 * login / register / forgot / voucher flows keep working with the token of their rendered page and
 * fail without it, with no side effect.
 */
final class CsrfTest extends Wp2HttpTestCase
{
    public function testCsrfEnforcedEvenWhenLegacySwitchIsOff(): void
    {
        static::$db->exec("DELETE FROM tbl_appconfig WHERE setting = 'csrf_enabled'");
        static::$db->insertRow('tbl_appconfig', ['setting' => 'csrf_enabled', 'value' => 'no']);
        $sa = $this->staff('sa');
        $other = $this->staff('ad');
        $foreign = $other->token();
        $before = $this->snapshot();
        $post = ['username' => 'csrf_victim_x', 'fullname' => 'CSRF Victim', 'password' => 'pw-12345', 'user_type' => 'Report'];
        foreach ([null, '', $foreign, str_repeat('a', 64), 'x'] as $t) {
            $data = $post + ($t === null ? [] : ['csrf_token' => $t]);
            $r = $sa->post('settings/users-post', $data);
            $this->assertSame(403, $r->status, 'missing/invalid/foreign token: ' . var_export($t, true));
            $r = $sa->post('settings/users-post', $post, [], ['X-CSRF-Token: ' . (string) $t]);
            $this->assertSame(403, $r->status);
        }
        $this->assertUnchanged($before, 'rejected CSRF');
        $this->assertSame(0, (int) static::$db->value("SELECT COUNT(*) FROM tbl_users WHERE username = 'csrf_victim_x'"));
        // valid token (form field and header) + capability + scope → success
        $r = $sa->postWithToken('settings/users-post', $post);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_users WHERE username = 'csrf_victim_x'"));
        $r = $sa->post('settings/users-post', ['username' => 'csrf_victim_y'] + $post, [], ['X-CSRF-Token: ' . $sa->token()]);
        $this->assertSame(302, $r->status);
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_users WHERE username = 'csrf_victim_y'"));
    }

    public function testTokenIsStablePerSessionAndRotatesOnLogin(): void
    {
        $c = $this->client();
        $t1 = $c->token('admin');
        $this->assertSame($t1, $c->token('admin'), 'stable across renders (multiple tabs keep working)');
        $c->loginStaff('sa', Wp2Seed::password('sa'));
        $t2 = $c->token();
        $this->assertNotSame($t1, $t2, 'rotated on login');
        $this->assertSame(403, $c->post('settings/users-post', ['csrf_token' => $t1, 'username' => 'zz'])->status, 'pre-login token is dead');
    }

    public function testGetOnMutatingRoutesIsRefused(): void
    {
        $sa = $this->staff('sa');
        $c = $this->customer('cNULL');
        $before = $this->snapshot();
        foreach (['customers/delete/' . $this->cid('cNULL2'), 'settings/users-delete/' . $this->sid('rp'), 'plan/sync', 'dashboard',
            'customers/sync/' . $this->cid('cNULL'), 'plan/voucher-delete/' . static::$seed->vouchers['ad'], 'customers/login/' . $this->cid('cNULL')] as $route) {
            $q = $route === 'dashboard' ? ['refresh' => ''] : [];
            $r = $sa->get($route, $q + ['token' => $sa->token()]);
            $this->assertSame(405, $r->status, $route);
        }
        foreach ([['home', ['renewal' => '0']], ['home', ['extend' => '1', 'stoken' => 'x']], ['home', ['deactivate' => (string) static::$seed->recharges['cNULL']]],
            ['mail/delete/1', []], ['accounts/language-update-post', ['lang' => 'english']]] as [$route, $q]) {
            $this->assertSame(405, $c->get($route, $q)->status, $route);
        }
        $this->assertUnchanged($before, 'GET on mutating routes');
    }

    public function testPublicLoginFlowsWithAndWithoutToken(): void
    {
        // staff login without token: not logged in, no session established
        $c = $this->client();
        $c->get('admin');
        $r = $c->post('admin/post', ['username' => 'sa', 'password' => Wp2Seed::password('sa')]);
        $this->assertSame(302, $r->status);
        $this->assertStringContainsString('admin', (string) $r->location());
        $this->assertSame(302, $c->get('dashboard')->status, 'still anonymous');
        // with the token of the rendered login page
        $this->assertStringNotContainsString('Invalid', $c->loginStaff('sa', Wp2Seed::password('sa'))->body);
        $this->assertSame(200, $c->get('dashboard')->status);
        // customer login
        $c = $this->client();
        $c->get('login');
        $c->post('login/post', ['username' => 'cNULL', 'password' => Wp2Seed::customerPassword('cNULL')]);
        $this->assertNotSame(200, $c->get('home')->status, 'customer login without token refused');
        $c->loginCustomer('cNULL', Wp2Seed::customerPassword('cNULL'));
        $this->assertSame(200, $c->get('home')->status, 'customer login with token works');
    }

    public function testPublicRegisterFlow(): void
    {
        $c = $this->client();
        $c->get('register');
        $data = ['username' => 'reg_csrf_1', 'password' => 'abc123', 'cpassword' => 'abc123', 'fullname' => 'Reg User', 'address' => 'x', 'email' => 'r@example.invalid'];
        $r = $c->post('register/post', $data);
        $this->assertSame(302, $r->status);
        $this->assertSame(0, (int) static::$db->value("SELECT COUNT(*) FROM tbl_customers WHERE username = 'reg_csrf_1'"), 'no customer row without token');
        $r = $c->post('register/post', $data + ['csrf_token' => $c->token('register')]);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_customers WHERE username = 'reg_csrf_1'"), 'registration works with the page token');
        $this->assertNull(static::$db->value("SELECT manager_id FROM tbl_customers WHERE username = 'reg_csrf_1'"), 'self-registered customers are unassigned');
    }

    public function testPublicForgotFlow(): void
    {
        $c = $this->client();
        $c->get('forgot');
        static::$app->clearProbes();
        $otpDir = static::$app->dir . '/system/cache/forgot';
        $countOtp = static fn() => is_dir($otpDir) ? count(glob($otpDir . '/*.txt')) : 0;
        $n = $countOtp();
        $r = $c->post('forgot', ['username' => 'cNULL', 'step' => '1']);
        $this->assertSame(302, $r->status);
        $this->assertSame($n, $countOtp(), 'no OTP file without token');
        $this->assertSame([], static::$app->probe('sms'), 'no SMS without token');
        $r = $c->post('forgot', ['username' => 'cNULL', 'step' => '1', 'csrf_token' => $c->token('forgot')]);
        $this->assertSame(200, $r->status);
        $this->assertSame($n + 1, $countOtp(), 'OTP issued with the page token');
        $this->assertNotSame([], static::$app->probe('sms'));
    }

    public function testPublicVoucherActivationFlow(): void
    {
        $code = static::$seed->voucherCode('ad');
        $c = $this->client();
        $c->get('login');
        $before = $this->snapshot();
        $r = $c->post('login/activation', ['username' => 'vouch_new_1', 'voucher' => $code]);
        $this->assertSame(302, $r->status);
        $this->assertUnchanged($before, 'voucher activation without token');
        $r = $c->post('login/activation', ['username' => 'vouch_new_1', 'voucher' => $code, 'csrf_token' => $c->token('login')]);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame('1', (string) static::$db->value('SELECT status FROM tbl_voucher WHERE code = ?', [$code]), 'unassigned voucher activation works with the token');
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_customers WHERE username = 'vouch_new_1' AND manager_id IS NULL"));
    }

    public function testApiIsNotSubjectToCsrfButCallbacksAreExempt(): void
    {
        $token = $this->apiToken('sa');
        $r = $this->client()->api('POST', 'settings/users-post', ['username' => 'api_no_csrf', 'fullname' => 'Api NoCsrf', 'password' => 'pw-12345', 'user_type' => 'Report'], $token);
        $this->assertTrue(($r->json()['success'] ?? null) === true || $r->status === 200, $r->excerpt());
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_users WHERE username = 'api_no_csrf'"));
        $r = $this->client()->post('callback/nosuchgateway', ['x' => 1]);
        $this->assertSame(404, $r->status, 'callbacks are not CSRF-checked (server-to-server) — unknown gateway is 404, not 403');
    }

    /** CSRF-through-the-API vector: the browser's admin cookie never authorizes an API call. */
    public function testBrowserCookieGivesNoAuthorityThroughTheApi(): void
    {
        $sa = $this->staff('sa');
        $attacker = $this->apiToken('rp');
        $before = $this->snapshot();
        foreach ([null, $attacker] as $t) {
            $r = $sa->api('POST', 'customers/delete/' . $this->cid('cNULL2'), [], $t, [], true, true);
            $this->assertDenied($r, 'API with browser cookies');
            $r = $sa->api('GET', 'customers/list', [], $t, [], true, true);
            $this->assertDenied($r, 'API list with browser cookies');
        }
        $this->assertUnchanged($before, 'API with ambient browser identity');
    }
}
