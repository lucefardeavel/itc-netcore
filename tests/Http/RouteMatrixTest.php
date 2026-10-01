<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Security\ActorContext;
use Reseller\Security\PermissionService;
use Reseller\Security\RouteMap;
use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * SEC-06 / SEC-07: role × route matrix generated from RouteMap. For every staff route and every
 * seeded role, a principal lacking the capability is refused on GET and on a valid-CSRF POST before
 * any controller code runs (403 / 405), with no database change. Allowed read routes are reachable.
 * UI hiding never counts: every case is a raw request with a real session.
 */
final class RouteMatrixTest extends Wp2HttpTestCase
{
    /** Allowed GET routes that need network access or extensions absent from the test image. */
    private const SKIP_ALLOWED_GET = ['pluginmanager/list', 'settings/dbstatus', 'export/print-by-date', 'export/pdf-by-date',
        'export/print-by-period', 'export/pdf-by-period', 'settings/docs', 'community/view', 'paymentgateway/gateway', 'autoload/customer_is_active',
        'reports/ajax', 'settings/devices', 'radius/*', 'logs/radius', 'logs/radius-csv'];

    private function actor(string $u): ActorContext
    {
        [$role, $parent, $status, $enrolled] = Wp2Seed::STAFF[$u];
        $manager = $role === 'Agent' ? $this->sid($u) : ($role === 'Sales' && $parent !== null ? $this->sid($parent) : null);
        $frozen = $status !== 'Active' || ($parent !== null && Wp2Seed::STAFF[$parent][2] !== 'Active');
        return ActorContext::make(['type' => ActorContext::STAFF, 'channel' => 'WEB', 'userId' => $this->sid($u), 'role' => $role, 'status' => $status,
            'enrolled' => $enrolled, 'frozen' => $frozen, 'managerId' => $manager, 'schemaReady' => true]);
    }

    private static function url(string $controller, string $key): array
    {
        [$base, $variant] = array_pad(explode('#', $key, 2), 2, null);
        $route = $controller . '/' . $base;
        $query = [];
        if ($controller === 'pages') {
            $route = 'pages/Announcement' . ($base === 'page-post' ? '-post' : ($base === 'page-reset' ? '-reset' : ''));
        } elseif ($controller === 'paymentgateway' && $base === 'gateway') {
            $route = 'paymentgateway/NoSuchGateway';
        } elseif (in_array($base, ['list', 'view', 'search', 'nas-list', 'daily-report', 'tree', 'resolve', 'dispatch', 'dashboard', '404', 'none', 'notify', 'login-display', 'register-display', 'forgot', 'logout'], true)
            && !in_array($controller, ['customers', 'plan', 'logs', 'bandwidth', 'routers', 'odp', 'pool'], true)) {
            $route = $controller;
        }
        switch ($variant) {
            case 'deletePhoto':
                $route .= '/999999/deletePhoto';
                break;
            case 'send':
                $route .= '/999999/send';
                break;
            case 'refresh':
                $query['refresh'] = '';
                break;
            case 'test':
                $query['testTg'] = 'x';
                break;
        }
        return [$route, $query];
    }

    public function testRoleRouteMatrix(): void
    {
        $roles = ['sa', 'ad', 'A', 'A1', 'rp', 'E', 'F'];
        $clients = [];
        foreach ($roles as $u) {
            $clients[$u] = $this->staff($u);
        }
        $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events', 'tbl_appconfig']);
        $rows = [];
        $serverErrors = [];
        $denials = 0;
        $allowedChecked = 0;
        foreach (RouteMap::ROUTES as $controller => $map) {
            foreach ($map as $key => $entry) {
                if ($key === '' || $key === '*') {
                    continue;
                }
                $rules = is_array($entry) ? $entry : ['GET' => $entry, 'POST' => $entry];
                foreach ($rules as $method => $rule) {
                    if (in_array($rule, ['public', 'customer', 'plugin'], true)) {
                        continue;
                    }
                    [$route, $query] = self::url($controller, $key);
                    foreach ($roles as $u) {
                        $allowed = $rule === 'staff' ? true : ($rule === 'deny' ? false : PermissionService::can($this->actor($u), $rule));
                        if ($allowed && ($method === 'POST' || in_array($controller . '/' . explode('#', $key)[0], self::SKIP_ALLOWED_GET, true)
                            || in_array($controller . '/*', self::SKIP_ALLOWED_GET, true))) {
                            continue; // allowed mutations are exercised by the functional suites, never blindly here
                        }
                        $c = $clients[$u];
                        $r = $method === 'GET' ? $c->get($route, $query) : $c->postWithToken($route, ['id' => 999999], $query);
                        $rows[] = sprintf('%-4s %-4s %-45s %-28s %s %d', $u, $method, $route, $rule, $allowed ? 'ALLOW' : 'DENY ', $r->status);
                        if ($allowed) {
                            $allowedChecked++;
                            $this->assertNotContains($r->status, [401, 403, 405], "{$u} {$method} {$route} should be allowed: " . $r->excerpt());
                            if ($r->status === 500) {
                                $serverErrors[] = "{$u} {$method} {$route}";
                            }
                        } else {
                            $denials++;
                            $this->assertSame(403, $r->status, "{$u} {$method} {$route} ({$rule}) must be denied: " . $r->excerpt());
                        }
                        if ($route !== 'settings/app') { // shows the install path in the cron instructions (configuration, not an error)
                            $this->assertFalse($r->leaksInternals(), "{$u} {$method} {$route}");
                        }
                    }
                }
            }
        }
        $this->assertSame([], $serverErrors, 'allowed read routes must not fail: ' . substr(static::$app->serverLog(), -6000));
        $this->assertUnchanged($before, 'route matrix', ['tbl_logs', 'tbl_reseller_audit_events', 'tbl_appconfig']);
        $this->assertGreaterThan(500, $denials);
        $this->assertGreaterThan(100, $allowedChecked);
        if (getenv('WP2_EVIDENCE')) {
            fwrite(STDERR, "ROUTE MATRIX (" . count($rows) . " requests, {$denials} denials)\n" . implode("\n", $rows) . "\n");
        }
    }

    /** GET on every POST-only (mutating) staff route is refused with 405 for an authorized SuperAdmin. */
    public function testMutatingRoutesRefuseGet(): void
    {
        $sa = $this->staff('sa');
        $before = $this->snapshot();
        $n = 0;
        foreach (RouteMap::ROUTES as $controller => $map) {
            foreach ($map as $key => $entry) {
                if ($key === '' || $key === '*' || !is_array($entry) || isset($entry['GET']) || in_array($entry['POST'], ['public', 'customer'], true)) {
                    continue;
                }
                [$route, $query] = self::url($controller, $key);
                $r = $sa->get($route, $query);
                $this->assertSame(405, $r->status, "GET {$route} must be refused: " . $r->excerpt());
                $n++;
            }
        }
        $this->assertUnchanged($before, 'GET on mutating routes');
        $this->assertGreaterThan(60, $n);
    }

    /** Unmapped routes are denied for every role, SuperAdmin included (a new, unmapped controller). */
    public function testUnmappedRoutesAreDeniedForEveryone(): void
    {
        foreach (['sa', 'ad', 'A'] as $u) {
            $c = $this->staff($u);
            foreach (['wp2probe', 'wp2probe/anything', 'customers/edit#deletePhoto/1'] as $route) {
                $r = $c->get($route);
                $this->assertSame(404, $r->status, "{$u} {$route}: " . $r->excerpt());
                $this->assertStringNotContainsString('WP2PROBE_CONTROLLER', $r->body);
                $r = $c->postWithToken($route);
                $this->assertSame(404, $r->status, "{$u} POST {$route}");
                $this->assertStringNotContainsString('WP2PROBE_CONTROLLER', $r->body);
            }
            foreach (['../init', 'CUSTOMERS', 'customers.php', 'customers%00'] as $route) {
                $r = $c->get($route);
                $this->assertContains($r->status, [302, 404], "{$u} {$route}");
                $this->assertFalse($r->leaksInternals());
            }
        }
        // the same unmapped controller through the API with a valid SuperAdmin token
        $token = $this->apiToken('sa');
        foreach (['GET', 'POST'] as $method) {
            $r = $this->client()->api($method, 'wp2probe', [], $token);
            $this->assertFalse(($r->json()['success'] ?? true), "API {$method} wp2probe: " . $r->excerpt());
            $this->assertStringNotContainsString('WP2PROBE_CONTROLLER', $r->body);
        }
        $this->assertSame([], static::$app->probe('controller'), 'the unmapped controller never ran');
    }
}
