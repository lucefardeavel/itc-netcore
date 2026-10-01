<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/** A "secret" equal to $db_pass (the forbidden legacy fallback) is refused: the API fails closed. */
final class ApiSecretEqualsDbPassTest extends Wp2HttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$app->writeConfig((string) getenv('WP1_DB_ROOT_PASSWORD'));
    }

    public function testApiFailsClosedWhenSecretEqualsDbPass(): void
    {
        $this->assertSame((string) getenv('WP1_DB_ROOT_PASSWORD'), static::$app->apiSecret());
        $resp = $this->client()->api('POST', 'admin/post', ['username' => 'sa', 'password' => Wp2Seed::password('sa')]);
        $this->assertFalse($resp->json()['success'] ?? true);
        $this->assertStringContainsString('API disabled', $resp->body);
        $this->assertStringNotContainsString('a2.', $resp->body);
    }
}
