<?php
namespace Reseller\Tests\Unit\Wp7;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Security\RouteMap;

/**
 * WP7 source guards (owner plan 245e46bf, decisions OD-W7-1..4, amendments A-O). The staff PWA is presentation,
 * connectivity and safe offline behaviour only: two public read-only routes, no business authority, no business cache.
 */
final class Wp7GuardTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    public function testPwaRoutesArePublicGetOnlyAndNeverApiAllowed(): void
    {
        $map = RouteMap::ROUTES['pwa'] ?? null;
        $this->assertIsArray($map);
        $this->assertSame(['', '*', 'notfound', 'manifest', 'ping'], array_keys($map), 'exactly manifest + ping (+ the 404 default)');
        foreach (['notfound', 'manifest', 'ping'] as $k) {
            $this->assertSame(['GET' => 'public'], $map[$k], "pwa/{$k}: GET-only, public, no capability");
        }
        $this->assertArrayNotHasKey('pwa', RouteMap::API_ALLOWED);
    }

    public function testThePwaControllerHasNoBusinessOrMutatingCode(): void
    {
        $src = self::read('system/controllers/pwa.php');
        $this->assertStringContainsString("if (!defined('NUXR_RUNTIME'))", $src);
        foreach (['/ORM::/', '/\$_POST/', '/->save\(/', '/ActorContext/', '/Admin::/', '/User::/', '/\$_SESSION\[/', '/file_put_contents|fopen|unlink/',
                  '/Finance|Activation|Wallet|Pricing|DataScope|PermissionService/', '/\$ui->/'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $src, "pwa.php: {$re}");
        }
        preg_match_all("/case '([a-z-]+)':/", $src, $m);
        $this->assertSame(['manifest', 'ping', 'notfound'], $m[1]);
        // heartbeat session disposal only for the cookie-less throwaway session
        $this->assertMatchesRegularExpression('/if \(!isset\(\$_COOKIE\[session_name\(\)\]\) && session_status\(\) === PHP_SESSION_ACTIVE\)/', $src);
    }

    public function testThePwaBuilderIsPureAndUserNeutral(): void
    {
        $src = self::read('system/autoload/Reseller/Ui/Pwa.php');
        foreach (['/ORM::/', '/\$_(SESSION|COOKIE|GET|POST|SERVER)/', '/ActorContext|Admin::|User::/', '/file_get_contents|fopen|curl_/'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $src, "Pwa.php: {$re}");
        }
        $this->assertMatchesRegularExpression("/'start_url' => '\.\/\?_route=admin'/", $src);
    }
}
