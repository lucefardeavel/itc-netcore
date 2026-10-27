<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab UX finding UX-12 (ITC NetCore lab 2026-10-25): a duplicate username on customers/add-post answered
 * "Account already axist" and returned to an EMPTY form (everything typed was lost). The message reads
 * "Account already exists" and the form is offered again, once, with the non-secret fields; the login and PPPoE
 * passwords are never refilled (nor kept in the page). Nothing is written.
 */
final class W10CustomerAddRefillTest extends Wp2HttpTestCase
{
    public function testDuplicateUsernameKeepsTheNonSecretFieldsButNeverThePassword(): void
    {
        $tag = bin2hex(random_bytes(3));
        $secret = 'Pw' . $tag . 'Secret';
        $pppoeSecret = 'Pp' . $tag . 'Secret';
        $before = (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers');
        $c = $this->staff('ad');
        $r = $c->postWithToken('customers/add-post', [
            'username' => 'cA1', 'fullname' => 'Refill ' . $tag, 'password' => $secret,
            'pppoe_username' => 'pp' . $tag, 'pppoe_password' => $pppoeSecret, 'pppoe_ip' => '10.9.8.7',
            'email' => 'refill' . $tag . '@example.invalid', 'address' => 'Street ' . $tag, 'phonenumber' => '0790123456',
            'service_type' => 'PPPoE', 'account_type' => 'Business', 'coordinates' => '',
        ], [], 'customers/add');
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertStringContainsString('customers/add', (string) $r->location());
        $this->assertSame($before, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers'), 'nothing is written');

        $form = $c->get('customers/add');
        $this->assertSame(200, $form->status, $form->excerpt());
        $html = $form->body;
        $this->assertStringContainsString('Account already exists', $html, 'the corrected message');
        $this->assertStringNotContainsString('axist', $html, 'not the misspelt one');
        $this->assertMatchesRegularExpression('#id="fullname"[^>]*value="Refill ' . $tag . '"#', $html, 'the full name is refilled');
        $this->assertMatchesRegularExpression('#id="username"[^>]*value="cA1"#', $html, 'the username is refilled (to correct it)');
        $this->assertMatchesRegularExpression('#id="email"[^>]*value="refill' . $tag . '@example\.invalid"#', $html);
        $this->assertMatchesRegularExpression('#id="pppoe_username"[^>]*value="pp' . $tag . '"#', $html);
        $this->assertMatchesRegularExpression('#id="pppoe_ip"[^>]*value="10\.9\.8\.7"#', $html);
        $this->assertStringContainsString('Street ' . $tag . '</textarea>', $html);
        $this->assertMatchesRegularExpression('#<option value="PPPoE" selected>#', $html, 'the service type is kept');
        $this->assertMatchesRegularExpression('#<option value="Business" selected>#', $html, 'the account type is kept');
        $this->assertStringNotContainsString($secret, $html, 'the password is never refilled');
        $this->assertStringNotContainsString($pppoeSecret, $html, 'the PPPoE password is never refilled');
        $this->assertDoesNotMatchRegularExpression('#id="password"[^>]*value=#', $html);
        $this->assertDoesNotMatchRegularExpression('#id="pppoe_password"[^>]*value=#', $html);

        $again = $c->get('customers/add')->body;
        $this->assertStringNotContainsString('Refill ' . $tag, $again, 'the refill is shown once');
    }

    /** A refused add-post (duplicate username cA1) whose form is NOT opened afterwards: the refill stays in the session. */
    private function refusedAdd(\Reseller\Tests\Support\HttpClient $c, string $token, string $tag): void
    {
        $r = $c->post('customers/add-post', [
            'csrf_token' => $token, 'username' => 'cA1', 'fullname' => 'Stale ' . $tag, 'password' => 'Pw' . $tag . 'Secret',
            'email' => 'stale' . $tag . '@example.invalid', 'address' => 'Old street ' . $tag, 'phonenumber' => '0790123456',
            'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '',
        ]);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertStringContainsString('customers/add', (string) $r->location());
    }

    /** The session file of a client (the disposable app server stores sessions under its own directory). */
    private function sessionFile(\Reseller\Tests\Support\HttpClient $c): string
    {
        $sid = (string) ($c->cookies['PHPSESSID'] ?? '');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9,-]+$/', $sid, 'the staff session id');
        $file = static::$app->dir . '/wp2_sessions/sess_' . $sid;
        $this->assertFileExists($file);
        return $file;
    }

    /** UX review follow-up: a refill older than the limit (5 minutes) is dropped, never shown. */
    public function testStaleRefillIsNotShown(): void
    {
        $tag = bin2hex(random_bytes(3));
        $c = $this->staff('ad');
        $token = $c->token('dashboard'); // a page that does not consume the refill
        $this->refusedAdd($c, $token, $tag);

        // age the stored refill: its timestamp ("at", an int) moves 10 minutes into the past
        $file = $this->sessionFile($c);
        $data = (string) file_get_contents($file);
        $this->assertStringContainsString('Stale ' . $tag, $data, 'the refill was stored');
        $aged = preg_replace('/(nuxr_customer_add_refill(?:\||";)a:2:\{s:2:"at";i:)\d+;/', '${1}' . (time() - 600) . ';', $data, -1, $n);
        $this->assertSame(1, $n, 'the refill carries its timestamp: ' . substr($data, 0, 400));
        file_put_contents($file, $aged);

        $form = $c->get('customers/add');
        $this->assertSame(200, $form->status, $form->excerpt());
        $this->assertStringNotContainsString('Stale ' . $tag, $form->body, 'a stale refill is not shown');
        $this->assertStringNotContainsString('Old street ' . $tag, $form->body);
        $this->assertDoesNotMatchRegularExpression('#id="username"[^>]*value="cA1"#', $form->body);
        $this->assertStringNotContainsString('Stale ' . $tag, (string) @file_get_contents($file), 'and it is removed from the session');
    }

    /**
     * UX review follow-up: the next add-post clears an earlier refill, so after a refused add and then a successful add
     * of ANOTHER subscriber the form never comes back with the first subscriber's data.
     */
    public function testAddPostClearsAnEarlierRefill(): void
    {
        $tag = bin2hex(random_bytes(3));
        $c = $this->staff('ad');
        $token = $c->token('dashboard');
        $this->refusedAdd($c, $token, $tag);
        $this->assertStringContainsString('Stale ' . $tag, (string) file_get_contents($this->sessionFile($c)), 'the refill was stored');

        $new = 'w10rf' . $tag;
        $r = $c->post('customers/add-post', [
            'csrf_token' => $token, 'username' => $new, 'fullname' => 'Other ' . $tag, 'password' => 'Pw' . $tag . 'Other',
            'email' => $new . '@example.invalid', 'address' => '', 'phonenumber' => '0790765432',
            'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '',
        ]);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertStringContainsString('customers/view/', (string) $r->location(), 'the other subscriber is created');
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE username = ?', [$new]));
        $this->assertStringNotContainsString('Stale ' . $tag, (string) file_get_contents($this->sessionFile($c)), 'add-post cleared the refill');

        $form = $c->get('customers/add');
        $this->assertSame(200, $form->status, $form->excerpt());
        $this->assertStringNotContainsString('Stale ' . $tag, $form->body, 'the first subscriber\'s data does not reappear');
        $this->assertStringNotContainsString('Old street ' . $tag, $form->body);
        $this->assertDoesNotMatchRegularExpression('#id="username"[^>]*value="cA1"#', $form->body);
    }
}
