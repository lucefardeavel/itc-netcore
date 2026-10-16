<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\TestDatabase;
use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * WP9-D / NEW-7: without a dedicated application key ($app_secret) there is no fallback to $db_pass.
 * Staff login fails closed with an explicit configuration message, customers get no remember cookie,
 * login links and public invoice links are refused. A key equal to $db_pass counts as missing.
 */
final class W9KeyMissingTest extends Wp2HttpTestCase
{
    protected static function appOptions(): array
    {
        // the "key" is the database password: must be treated exactly like a missing key
        return ['app_secret' => TestDatabase::rootPassword()];
    }

    public function testStaffLoginFailsClosed(): void
    {
        $c = $this->client();
        $r = $c->loginStaff('A', Wp2Seed::password('A'));
        $this->assertStringContainsString('application key is not configured', $r->body . ' ' . $c->get('admin')->body);
        $d = $c->get('dashboard');
        $this->assertSame(302, $d->status, 'no staff session without a key: ' . $d->excerpt());
    }

    public function testCustomerGetsNoRememberCookieAndLinksAreRefused(): void
    {
        $c = $this->client();
        $token = $c->token('login');
        $r = $c->post('login/post', ['username' => 'cA1', 'password' => Wp2Seed::customerPassword('cA1'), 'csrf_token' => $token]);
        $this->assertSame([], array_values(array_filter($r->headers['set-cookie'] ?? [], static fn(string $h): bool => str_starts_with($h, 'uid=c3'))), 'no signed cookie without a key');
        $id = $this->cid('cA1');
        $t = time();
        $legacy = $id . '.' . $t . '.' . sha1($id . '.' . $t . '.' . TestDatabase::rootPassword());
        $r = $this->client()->get('home', ['uid' => $legacy]);
        $this->assertTrue($r->status === 302 && str_contains((string) $r->location(), 'login'), $r->excerpt());
        $tid = (int) static::$db->value('SELECT MIN(id) FROM tbl_transactions');
        $this->assertSame(404, $this->client()->get('voucher/invoice/' . $tid . '/' . md5($tid . TestDatabase::rootPassword()))->status);
    }
}
