<?php
namespace Reseller\Tests\Integration\Wp6a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6A adaptive shell (OD-G, OD-J, OD-H, OD-F): one navigation model for every staff page; no dead UI (every link
 * the shell renders is authorized for that actor); role-specific primary navigation; document language / direction;
 * no global external or heavy assets.
 */
final class ShellNavigationTest extends Wp2HttpTestCase
{
    /** Legacy Admin pages the accepted RouteMatrixTest does not GET in the harness either (host tools / network). */
    private const ENVIRONMENT_PAGES = ['settings/dbstatus', 'settings/docs', 'community', 'settings/devices', 'pluginmanager', 'logs/radius'];

    /** @return list<string> distinct routes linked from the shell's navigation regions */
    private static function navRoutes(string $html): array
    {
        $regions = '';
        foreach (['/<aside class="nuxr-sidebar".*?<\/aside>/s', '/<nav class="nuxr-rail".*?<\/nav>/s', '/<nav class="nuxr-bottomnav".*?<\/nav>/s',
            '/<dialog id="nuxr-more-sheet".*?<\/dialog>/s', '/<dialog id="nuxr-quick-sheet".*?<\/dialog>/s'] as $re) {
            if (preg_match($re, $html, $m)) {
                $regions .= $m[0];
            }
        }
        preg_match_all('/href="[^"]*\?_route=([^"&]+)/', $regions, $m);
        return array_values(array_unique(array_map('html_entity_decode', $m[1])));
    }

    /** @return list<string> labels of the primary destinations (bottom navigation) */
    private static function primary(string $html): array
    {
        preg_match('/<nav class="nuxr-bottomnav".*?<\/nav>/s', $html, $m);
        preg_match_all('/<span>([^<]+)<\/span>/', $m[0] ?? '', $l);
        return $l[1];
    }

    public function testEveryRenderedNavigationLinkIsAuthorizedForTheActor(): void
    {
        foreach (['sa', 'ad', 'A', 'A1', 'rp', 'E', 'F'] as $u) {
            $c = $this->staff($u);
            $page = $c->get('dashboard');
            $this->assertSame(200, $page->status, $u);
            $routes = self::navRoutes($page->body);
            $this->assertContains('dashboard', $routes, "{$u}: home");
            foreach ($routes as $route) {
                if (str_starts_with($route, 'plugin/') || in_array($route, self::ENVIRONMENT_PAGES, true)) {
                    continue; // test-only probe plugins; legacy pages that need host tools / network (as RouteMatrixTest)
                }
                $r = $c->get($route);
                $this->assertNotContains($r->status, [401, 403, 404, 405, 500], "{$u}: the shell links {$route} but it answers {$r->status}");
            }
        }
    }

    public function testPrimaryNavigationAndQuickActionsFollowCapabilities(): void
    {
        $expected = [
            'ad' => ['Home', 'Customers', 'New', 'Activations', 'More'],
            'A' => ['Home', 'Customers', 'New', 'Activations', 'More'],
            'A1' => ['Home', 'Customers', 'New', 'Activations', 'More'],
            'rp' => ['Home', 'More'],
        ];
        foreach ($expected as $u => $labels) {
            $html = $this->staff($u)->get('dashboard')->body;
            $this->assertSame($labels, self::primary($html), "{$u}: primary destinations (no Wallet before WP6B)");
            $this->assertStringNotContainsStringIgnoringCase('wallet', $html, "{$u}: no wallet destination");
        }
        // an unenrolled Agent may not activate: no activation quick action, but it may still add customers
        $e = $this->staff('E')->get('dashboard')->body;
        $this->assertStringNotContainsString('_route=activation/create', $e);
        // Agent / Sales: no legacy service-granting or admin destinations
        foreach (['A', 'A1'] as $u) {
            $routes = self::navRoutes($this->staff($u)->get('dashboard')->body);
            foreach (['plan/recharge', 'plan/deposit', 'plan/refill', 'settings/app', 'routers', 'services/hotspot', 'reports', 'customers/edit'] as $denied) {
                $this->assertNotContains($denied, $routes, "{$u}: {$denied}");
            }
        }
        $sales = self::navRoutes($this->staff('A1')->get('dashboard')->body);
        $this->assertNotContains('reseller/tree', $sales, 'Sales has no tree');
        $this->assertNotContains('message/send_bulk', $sales);
    }

    public function testDocumentDirectionLanguageLandmarksAndAssets(): void
    {
        $c = $this->staff('A');
        $en = $c->get('activation/list')->body;
        $this->assertMatchesRegularExpression('/<html lang="en" dir="ltr">/', $en);
        $this->assertStringContainsString('class="nuxr-skip" href="#nuxr-main"', $en);
        $this->assertStringContainsString('id="nuxr-main"', $en);
        $this->assertStringNotContainsString('maximum-scale', $en, 'zoom is never disabled');
        foreach (['fonts.googleapis.com', 'code.jquery.com', '7.css', 'chart.js', 'summernote.min.js', 'unpkg.com', 'google.com/vt'] as $asset) {
            $this->assertStringNotContainsStringIgnoringCase($asset, $en, "no global {$asset}");
        }
        $c->cookies['user_language'] = 'arabic';
        $ar = $c->get('customers/list')->body;
        $this->assertMatchesRegularExpression('/<html lang="ar" dir="rtl">/', $ar);
        $this->assertStringContainsString('nuxr-rtl-legacy.css', $ar);
        $this->assertStringContainsString('chart.min.js', $this->staff('ad')->get('dashboard')->body, 'Chart.js only where charts are drawn');
    }

    public function testMissingTranslationsNeverLeaveTheServer(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/system/autoload/Lang.php');
        $this->assertDoesNotMatchRegularExpression('/curl_|translate\.google|file_get_contents\(\s*["\']https?:|file_put_contents\(\s*\$lan_file/', $src, 'SEC-25: no external translation, no runtime language-file rewrite');
        $c = $this->staff('A');
        $c->cookies['user_language'] = 'arabic';
        $lan = static::$app->dir . '/system/lan/arabic.json';
        $before = hash_file('sha256', $lan);
        $this->assertSame(200, $c->get('activation/list')->status);
        $this->assertSame(200, $c->get('customers/list', ['search' => 'string-without-translation-' . bin2hex(random_bytes(3))])->status);
        $this->assertSame($before, hash_file('sha256', $lan), 'a missing string is a local fallback: the served catalog is never rewritten');
    }
}
