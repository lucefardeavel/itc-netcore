<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\TestDatabase;
use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * WP9-D / NEW-7 + N-03 + NEW-6 (owner decision O7-A): nothing signed with the database password is
 * accepted any more, and the new customer tokens follow their contract.
 *
 * Threat model: $db_pass is known to the attacker (config / backup / container leak). Every legacy
 * signature derived from it (customer cookie and ?uid= login token, staff remember cookie, public
 * voucher-invoice link) must be refused. Positive controls use tokens signed with the dedicated
 * application key of the app copy (minted here exactly as the application does).
 */
final class W9KeySeparationTest extends Wp2HttpTestCase
{
    private function dbPass(): string
    {
        return TestDatabase::rootPassword();
    }

    /** customer token minted with the app copy's dedicated key (application format) */
    private function mint(string $kind, int $uid, int $iat, int $exp, ?string $password = null, string $purpose = ''): string
    {
        $password ??= (string) static::$db->value('SELECT password FROM tbl_customers WHERE id = ?', [$uid]);
        $purpose = $purpose !== '' ? $purpose : ($kind === 'c3' ? 'customer-cookie' : 'customer-link');
        $sub = hash_hmac('sha256', 'nuxr-subkey|v1|' . $purpose, (string) static::$app->appSecret(), true);
        $mac = hash_hmac('sha256', $kind . '|' . $uid . '|' . $iat . '|' . $exp . '|' . $password, $sub);
        return "{$kind}.{$uid}.{$iat}.{$exp}.{$mac}";
    }

    private function isCustomer(HttpClient $c, array $query = []): bool
    {
        $r = $c->get('home', $query);
        return $r->status === 200 && !str_contains((string) $r->location(), 'login') && str_contains($r->body, 'logout');
    }

    private function withCookie(string $name, string $value): HttpClient
    {
        $c = $this->client();
        $c->cookies[$name] = $value;
        return $c;
    }

    public function testCustomerLoginPositiveControl(): void
    {
        $c = $this->customer('cA1');
        $this->assertTrue($this->isCustomer($c), 'a real customer login works');
    }

    public function testLegacyCustomerCookieSignedWithDbPassIsRefused(): void
    {
        $id = $this->cid('cA1');
        $t = time();
        $legacy = $id . '.' . $t . '.' . sha1($id . '.' . $t . '.' . $this->dbPass());
        $this->assertFalse($this->isCustomer($this->withCookie('uid', $legacy)), 'legacy db_pass cookie must not authenticate');
    }

    public function testLegacyLoginLinkSignedWithDbPassIsRefused(): void
    {
        $id = $this->cid('cA1');
        $future = time() + 86400 * 29; // the legacy 59-day magic-link shape
        $legacy = $id . '.' . $future . '.' . sha1($id . '.' . $future . '.' . $this->dbPass());
        $this->assertFalse($this->isCustomer($this->client(), ['uid' => $legacy]), 'legacy db_pass ?uid= token must not authenticate');
    }

    public function testLegacyStaffCookieSignedWithDbPassIsRefused(): void
    {
        $id = $this->sid('A');
        $t = time();
        $hash = (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$id]);
        $legacy = $id . '.' . $t . '.' . hash_hmac('sha256', 'nuxr-admin-cookie|v2|' . $id . '|' . $t . '|' . $hash, $this->dbPass());
        $r = $this->withCookie('aid', $legacy)->get('dashboard');
        $this->assertTrue($r->status === 302 && (str_contains((string) $r->location(), 'login') || str_contains((string) $r->location(), 'admin')), 'legacy db_pass staff cookie must not authenticate: ' . $r->excerpt());
    }

    public function testStaffLoginPositiveControl(): void
    {
        $r = $this->staff('A')->get('dashboard');
        $this->assertSame(200, $r->status, $r->excerpt());
    }

    public function testLegacyVoucherInvoiceSignatureIsRefusedAndNewOneWorks(): void
    {
        $id = (int) static::$db->value('SELECT MIN(id) FROM tbl_transactions');
        $this->assertGreaterThan(0, $id);
        $r = $this->client()->get('voucher/invoice/' . $id . '/' . md5($id . $this->dbPass()));
        $this->assertSame(404, $r->status, 'legacy md5(id . db_pass) link must be refused');
        $sub = hash_hmac('sha256', 'nuxr-subkey|v1|voucher-invoice', (string) static::$app->appSecret(), true);
        $r = $this->client()->get('voucher/invoice/' . $id . '/' . hash_hmac('sha256', 'id|' . $id, $sub));
        $this->assertSame(200, $r->status, 'the AppKey-signed link works: ' . $r->excerpt());
    }

    public function testNewCustomerCookieContract(): void
    {
        $id = $this->cid('cA1');
        $now = time();
        $this->assertTrue($this->isCustomer($this->withCookie('uid', $this->mint('c3', $id, $now, $now + 3600))), 'valid c3 cookie');
        $this->assertFalse($this->isCustomer($this->withCookie('uid', $this->mint('c3', $id, $now + 3600, $now + 7200))), 'future issue time');
        $this->assertFalse($this->isCustomer($this->withCookie('uid', $this->mint('c3', $id, $now - 7200, $now - 1))), 'expired');
        $this->assertFalse($this->isCustomer($this->withCookie('uid', $this->mint('c3', $id, $now, $now + 86400 * 31))), 'longer than 30 days');
        $this->assertFalse($this->isCustomer($this->withCookie('uid', $this->mint('l3', $id, $now, $now + 3600))), 'a link token is not a cookie');
        $this->assertFalse($this->isCustomer($this->withCookie('uid', $this->mint('c3', $id, $now, $now + 3600, 'other-password'))), 'bound to the stored password');
        $this->assertFalse($this->isCustomer($this->withCookie('uid', $this->mint('c3', $id, $now, $now + 3600, null, 'customer-link'))), 'wrong purpose subkey');
        $other = $this->cid('cB');
        $good = $this->mint('c3', $id, $now, $now + 3600);
        $this->assertFalse($this->isCustomer($this->withCookie('uid', preg_replace('/^c3\.\d+\./', "c3.{$other}.", $good))), 'uid swap');
        $this->assertFalse($this->isCustomer($this->withCookie('uid', '0e1.0e1.0e1.0e1.0e1')), 'loose-compare shape');
    }

    public function testNewLoginLinkContract(): void
    {
        $id = $this->cid('cA2');
        $now = time();
        $this->assertTrue($this->isCustomer($this->client(), ['uid' => $this->mint('l3', $id, $now, $now + 86400)]), 'valid l3 link');
        $this->assertFalse($this->isCustomer($this->client(), ['uid' => $this->mint('c3', $id, $now, $now + 86400)]), 'a cookie token is not a link');
        $this->assertFalse($this->isCustomer($this->client(), ['uid' => $this->mint('l3', $id, $now - 90000, $now - 3600)]), 'expired link');
    }

    public function testPasswordChangeRevokesCustomerTokens(): void
    {
        $id = $this->cid('cC1');
        $now = time();
        $cookie = $this->mint('c3', $id, $now, $now + 3600);
        $this->assertTrue($this->isCustomer($this->withCookie('uid', $cookie)));
        $old = (string) static::$db->value('SELECT password FROM tbl_customers WHERE id = ?', [$id]);
        static::$db->exec("UPDATE tbl_customers SET password = 'changed-pw-x' WHERE id = {$id}");
        try {
            $this->assertFalse($this->isCustomer($this->withCookie('uid', $cookie)), 'old cookie revoked by the password change');
        } finally {
            static::$db->exec('UPDATE tbl_customers SET password = ' . static::$db->db()->pdo->quote($old) . " WHERE id = {$id}");
        }
    }

    public function testCustomerCookieFlags(): void
    {
        $c = $this->client();
        $token = $c->token('login');
        $r = $c->post('login/post', ['username' => 'cF', 'password' => Wp2Seed::customerPassword('cF'), 'csrf_token' => $token]);
        $set = array_values(array_filter($r->headers['set-cookie'] ?? [], static fn(string $h): bool => str_starts_with($h, 'uid=')));
        $this->assertCount(1, $set, 'one remember cookie: ' . json_encode($r->headers['set-cookie'] ?? []));
        $this->assertMatchesRegularExpression('/^uid=c3\.\d+\.\d+\.\d+\.[0-9a-f]{64};/', $set[0]);
        $this->assertStringContainsStringIgnoringCase('httponly', $set[0]);
        $this->assertStringContainsStringIgnoringCase('samesite=lax', $set[0]);
    }
}
