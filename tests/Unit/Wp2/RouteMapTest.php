<?php
namespace Reseller\Tests\Unit\Wp2;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Security\Capability;
use Reseller\Security\RouteGuard;
use Reseller\Security\RouteMap;
use Reseller\Tests\Support\RouteInventory;

/**
 * WP2 §5: every controller and every action label found in system/controllers/*.php has an explicit
 * RouteMap entry (unmapped routes are denied for every role); no stale entries; every rule is a
 * known capability or special rule; fall-through entry points never widen access.
 */
final class RouteMapTest extends TestCase
{
    /** Controllers whose action segment is dynamic (resolved by RouteGuard, not a case label). */
    private const DYNAMIC = ['pages' => ['Voucher']];
    /** Map keys that are dispatcher aliases rather than case labels. */
    private const ALIASES = [
        'customers' => ['list'], 'plan' => ['list'], 'admin' => ['login-display'], 'login' => ['login-display'],
        'register' => ['register-display'], 'forgot' => ['forgot'], 'logout' => ['logout'], 'callback' => ['notify'],
        'plugin' => ['dispatch'], 'home' => ['dashboard'], 'accounts' => ['404'], 'autoload_user' => ['none'],
        'mail' => ['list'], 'page' => ['view'], 'voucher' => ['404'], 'dashboard' => ['view'], 'search_user' => ['search'],
        'reports' => [], 'export' => ['404'], 'autoload' => ['404'], 'settings' => ['404'], 'reseller' => [],
        'bandwidth' => ['list'], 'routers' => ['list'], 'odp' => ['list'], 'radius' => ['nas-list'], 'pool' => ['list'],
        'services' => [], 'logs' => [], 'customfield' => ['list'], 'community' => ['view'], 'coupons' => ['list'],
        'paymentgateway' => ['list', 'gateway'], 'pluginmanager' => ['list'], 'widgets' => ['list'],
        'pages' => ['page', 'page-post', 'page-reset'], 'default' => ['resolve'], 'maps' => [], 'message' => [], 'order' => [],
    ];

    private static function baseKeys(string $controller): array
    {
        $keys = [];
        foreach (array_keys(RouteMap::ROUTES[$controller]) as $k) {
            if ($k === '' || $k === '*' || str_contains($k, '#')) {
                continue;
            }
            $keys[] = $k;
        }
        sort($keys);
        return $keys;
    }

    public function testEveryControllerIsMapped(): void
    {
        $inventory = RouteInventory::scan(dirname(__DIR__, 3) . '/system/controllers');
        $this->assertGreaterThanOrEqual(40, count($inventory));
        foreach (array_keys($inventory) as $controller) {
            $this->assertArrayHasKey($controller, RouteMap::ROUTES, "controller {$controller} has no RouteMap entry (it would be denied for everyone)");
        }
        foreach (array_keys(RouteMap::ROUTES) as $controller) {
            $this->assertArrayHasKey($controller, $inventory, "stale RouteMap controller {$controller}");
        }
    }

    public function testEveryActionLabelIsMappedAndNoStaleEntries(): void
    {
        $inventory = RouteInventory::scan(dirname(__DIR__, 3) . '/system/controllers');
        foreach ($inventory as $controller => $actions) {
            $actions = array_values(array_diff($actions, self::DYNAMIC[$controller] ?? []));
            $keys = self::baseKeys($controller);
            $missing = array_diff($actions, $keys);
            $this->assertSame([], array_values($missing), "{$controller}: unmapped actions");
            $stale = array_diff($keys, $actions, self::ALIASES[$controller] ?? []);
            $this->assertSame([], array_values($stale), "{$controller}: stale RouteMap keys");
        }
    }

    public function testEveryRuleIsKnownAndEveryAliasResolves(): void
    {
        $special = ['public', 'customer', 'staff', 'plugin', 'deny'];
        $caps = Capability::all();
        foreach (RouteMap::ROUTES as $controller => $map) {
            foreach (['', '*'] as $alias) {
                $this->assertArrayHasKey($alias, $map, "{$controller} lacks the '{$alias}' alias");
                if ($controller !== 'pages' || $alias !== '') {
                    $this->assertArrayHasKey($map[$alias], $map, "{$controller}: alias '{$alias}' → '{$map[$alias]}' is not mapped");
                }
            }
            foreach ($map as $key => $entry) {
                if ($key === '' || $key === '*') {
                    continue;
                }
                $rules = is_array($entry) ? $entry : ['GET' => $entry, 'POST' => $entry];
                foreach ($rules as $method => $rule) {
                    $this->assertContains($method, ['GET', 'POST'], "{$controller}/{$key}");
                    $this->assertTrue(in_array($rule, $special, true) || in_array($rule, $caps, true), "{$controller}/{$key}: unknown rule {$rule}");
                }
            }
        }
    }

    public function testVariantsAndApiAllowlistReferToMappedEntries(): void
    {
        foreach (RouteMap::VARIANTS as $base => $variants) {
            [$controller, $key] = explode('/', $base, 2);
            $this->assertArrayHasKey($key, RouteMap::ROUTES[$controller], $base);
            foreach ($variants as [, , , $variant]) {
                $this->assertArrayHasKey($variant, RouteMap::ROUTES[$controller], "{$base} variant {$variant}");
                $this->assertArrayNotHasKey('GET', (array) RouteMap::ROUTES[$controller][$variant], "mutating variant {$variant} must be POST-only");
            }
        }
        foreach (RouteMap::API_ALLOWED as $controller => $actions) {
            $this->assertArrayHasKey($controller, RouteMap::ROUTES);
            if ($actions !== '*') {
                foreach ($actions as $a) {
                    $this->assertArrayHasKey($a, RouteMap::ROUTES[$controller], "API allowlist {$controller}/{$a}");
                }
            }
        }
        foreach (['plugin', 'pluginmanager', 'widgets', 'pages', 'paymentgateway', 'callback', 'customfield', 'community', 'home', 'order', 'voucher', 'accounts'] as $never) {
            $this->assertArrayNotHasKey($never, RouteMap::API_ALLOWED, "{$never} must not be reachable through the API");
        }
        $this->assertNotContains('login', RouteMap::API_ALLOWED['customers'], 'impersonation is never available through the API');
        $this->assertNotContains('dbbackup', RouteMap::API_ALLOWED['settings']);
        $this->assertNotContains('dbrestore', RouteMap::API_ALLOWED['settings']);
    }

    /** Fall-through code paths (viewu→view, remove-voucher→print-voucher) never widen access. */
    public function testFallThroughEntriesAreNotWiderThanTheirTargets(): void
    {
        $r = RouteMap::ROUTES;
        $this->assertSame($r['customers']['view'], $r['customers']['viewu']);
        $this->assertSame(Capability::VOUCHER_DELETE, $r['plan']['remove-voucher']['POST']);
        $this->assertArrayNotHasKey('GET', $r['plan']['remove-voucher']);
    }

    /**
     * OD-12 code boundary (owner review fix 2): page templates are Smarty-included, so every Pages
     * write/reset variant requires system.code (SuperAdmin only); reading stays system.admin; the API
     * never reaches the Pages controller.
     */
    public function testPagesWriteAndResetAreACodeSurface(): void
    {
        $r = RouteMap::ROUTES['pages'];
        $this->assertSame(['GET' => Capability::SYSTEM_ADMIN], $r['page']);
        $this->assertSame(['POST' => Capability::SYSTEM_CODE], $r['page-post']);
        $this->assertSame(['POST' => Capability::SYSTEM_CODE], $r['page-reset']);
        foreach (['Announcement-post', 'Voucher-post', 'Custom_Page-post', 'x-reset-post'] as $action) {
            $res = RouteGuard::resolve('pages', ['pages', $action], [], 'POST');
            $this->assertSame(Capability::SYSTEM_CODE, $res['rule'], $action);
        }
        foreach (['Announcement-reset', 'Voucher-reset', 'Terms_and_Conditions-reset'] as $action) {
            $res = RouteGuard::resolve('pages', ['pages', $action], [], 'POST');
            $this->assertSame(Capability::SYSTEM_CODE, $res['rule'], $action);
            $this->assertFalse(RouteGuard::resolve('pages', ['pages', $action], [], 'GET')['method_allowed'], "{$action} via GET");
        }
        $view = RouteGuard::resolve('pages', ['pages', 'Announcement'], [], 'GET');
        $this->assertSame(Capability::SYSTEM_ADMIN, $view['rule']);
        $this->assertFalse(RouteGuard::resolve('pages', ['pages', 'Announcement'], [], 'POST')['method_allowed'], 'no write through the read entry');
        $this->assertArrayNotHasKey('pages', RouteMap::API_ALLOWED);
    }

    public function testResolutionOfKeysVariantsAndMethods(): void
    {
        $r = RouteGuard::resolve('customers', ['customers', 'edit', '5', 'deletePhoto'], [], 'GET');
        $this->assertSame('edit#deletePhoto', $r['key']);
        $this->assertFalse($r['method_allowed'], 'deletePhoto via GET is refused (405)');
        $r = RouteGuard::resolve('customers', ['customers', 'edit', '5'], [], 'GET');
        $this->assertTrue($r['method_allowed']);
        $r = RouteGuard::resolve('customers', ['customers', 'edit#deletePhoto', '5'], [], 'POST');
        $this->assertFalse($r['mapped'], 'variant keys are never addressable directly (denied as unmapped)');
        $r = RouteGuard::resolve('dashboard', ['dashboard'], ['refresh' => ''], 'GET');
        $this->assertSame('view#refresh', $r['key']);
        $this->assertFalse($r['method_allowed']);
        $r = RouteGuard::resolve('home', ['home'], ['extend' => '1'], 'GET');
        $this->assertFalse($r['method_allowed']);
        $r = RouteGuard::resolve('pages', ['pages', 'Voucher-post'], [], 'POST');
        $this->assertSame('page-post', $r['key']);
        $r = RouteGuard::resolve('nosuch', ['nosuch'], [], 'GET');
        $this->assertFalse($r['mapped']);
        $r = RouteGuard::resolve('plan', ['plan', 'extend', '1', '3'], [], 'HEAD');
        $this->assertFalse($r['method_allowed'], 'HEAD is treated as GET');
        $r = RouteGuard::resolve('customers', ['customers', 'list'], [], 'PUT');
        $this->assertFalse($r['method_allowed']);
    }
}
