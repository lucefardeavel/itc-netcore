<?php
namespace Reseller\Tests\Integration\Wp7;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP7 owner amendment I over real HTTP: the staff pages load the PWA client (which defines the offline mutation gate)
 * BEFORE the CSRF shim, and the shim's token injection is byte-for-byte the WP2 logic; the gate can only stop a
 * request. Customer-portal and public pages do not load the PWA client, so window.nuxrMutationGate is absent there and
 * their behaviour is unchanged. The server-side CSRF check is unchanged (a token-less POST is still refused).
 */
final class PwaCsrfGateTest extends Wp2HttpTestCase
{
    private const INJECTION = [
        "input.type = 'hidden'; input.name = 'csrf_token'; input.value = token;",
        "if (this._nuxrSame) { try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) { } }",
        "h.set('X-CSRF-Token', token);",
    ];

    public function testStaffPagesLoadTheGateBeforeTheShimAndKeepTokenInjection(): void
    {
        foreach (['ad' => 'dashboard', 'A' => 'customers/list', 'A1' => 'dashboard'] as $u => $page) {
            $html = $this->staff($u)->get($page)->body;
            $pwa = strpos($html, 'nuxr-pwa.js?');
            $shim = strpos($html, 'window.nuxrCsrfToken =');
            $this->assertNotFalse($pwa, "{$u} {$page}: PWA client loaded");
            $this->assertNotFalse($shim, "{$u} {$page}: CSRF shim present");
            $this->assertLessThan($shim, $pwa, 'the PWA client (gate) loads before the CSRF shim');
            foreach (self::INJECTION as $line) {
                $this->assertStringContainsString($line, $html, "token injection unchanged: {$line}");
            }
            $this->assertMatchesRegularExpression('/window\.nuxrCsrfToken = "[0-9a-f]{64}";/', $html);
            $this->assertStringContainsString("ping: '" . 'http://127.0.0.1:' , $html, 'heartbeat URL is the application origin');
        }
        // the staff login page too (the login POST is also stopped offline)
        $login = $this->client()->get('admin')->body;
        $this->assertLessThan(strpos($login, 'window.nuxrCsrfToken ='), strpos($login, 'nuxr-pwa.js?'));
    }

    public function testCustomerAndPublicPagesHaveNoGate(): void
    {
        foreach (['login', 'register', 'forgot'] as $route) {
            $html = $this->client()->get($route)->body;
            $this->assertStringNotContainsString('nuxr-pwa.js', $html, "{$route}: no staff PWA client");
            $this->assertStringNotContainsString('window.nuxrPwa', $html, "{$route}: no PWA configuration");
            $this->assertStringNotContainsString('rel="manifest"', $html, "{$route}: the staff manifest is not offered");
        }
        $home = $this->customer('cA1')->get('home');
        $this->assertSame(200, $home->status);
        $this->assertStringNotContainsString('nuxr-pwa.js', $home->body, 'customer portal: no staff PWA client');
        $this->assertStringContainsString('window.nuxrCsrfToken =', $home->body, 'customer portal keeps its CSRF shim');
    }

    public function testServerSideCsrfIsUnchanged(): void
    {
        $c = $this->staff('ad');
        $r = $c->post('settings/change-password-post', ['password' => 'x', 'npass' => 'y1-Newpass!', 'cnpass' => 'y1-Newpass!']);
        $this->assertSame(403, $r->status, 'a POST without the session token is still refused by the server');
    }
}
