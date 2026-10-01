<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/** A secret shorter than 32 characters is not accepted: every API request fails closed. */
final class ApiShortSecretTest extends Wp2HttpTestCase
{
    protected static function appOptions(): array
    {
        return ['api_secret' => 'too-short-secret'];
    }

    public function testApiFailsClosed(): void
    {
        foreach (['admin/post', 'customers/list', 'isValid', 'me'] as $r) {
            $resp = $this->client()->api('POST', $r, ['username' => 'sa', 'password' => Wp2Seed::password('sa')]);
            $this->assertFalse($resp->json()['success'] ?? true, $r);
            $this->assertStringContainsString('API disabled', $resp->body);
            $this->assertStringNotContainsString('a2.', $resp->body);
        }
    }
}
