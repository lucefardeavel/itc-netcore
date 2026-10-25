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
}
