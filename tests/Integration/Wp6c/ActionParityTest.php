<?php
namespace Reseller\Tests\Integration\Wp6c;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6C owner amendment A over real HTTP: on every page a role reaches through the shell, every rendered action link
 * (GET links and data-method="post" actions) is one the role may actually use under the accepted RouteMap rule
 * (Navigation::allowed, evaluated in the application context, so destructive links are never requested). The audit
 * found Active Customers "Edit" / "Recharge", plan / router links and Vouchers "+" rendered to Agent / Sales -> 403.
 * Backend authorization is unchanged and stays authoritative (the same rule).
 */
final class ActionParityTest extends Wp2HttpTestCase
{
    /** @return list<string> routes linked from the shell navigation regions */
    private static function navRoutes(string $html): array
    {
        $regions = '';
        foreach (['/<aside class="nuxr-sidebar".*?<\/aside>/s', '/<dialog id="nuxr-more-sheet".*?<\/dialog>/s', '/<dialog id="nuxr-quick-sheet".*?<\/dialog>/s'] as $re) {
            if (preg_match($re, $html, $m)) {
                $regions .= $m[0];
            }
        }
        preg_match_all('/href="[^"]*\?_route=([^"&#]+)/', $regions, $m);
        return array_values(array_unique(array_filter(array_map('html_entity_decode', $m[1]), static fn(string $r): bool => !str_starts_with($r, 'plugin/'))));
    }

    /** @return list<array{0:string,1:string}> [route, method] of every action link in the page content */
    private static function actions(string $html): array
    {
        $start = strpos($html, 'id="nuxr-main"');
        $main = $start === false ? $html : substr($html, $start, (int) strpos($html, '<footer', $start) - $start);
        preg_match_all('/<a\b([^>]*)>/', $main, $m);
        $out = [];
        foreach ($m[1] as $attrs) {
            if (!preg_match('/href="[^"]*\?_route=([^"#]+)"/', $attrs, $h)) {
                continue;
            }
            $route = explode('&', html_entity_decode($h[1]))[0];
            $out[$route . '|' . (str_contains($attrs, 'data-method="post"') ? 'POST' : 'GET')] = [$route, str_contains($attrs, 'data-method="post"') ? 'POST' : 'GET'];
        }
        return array_values($out);
    }

    public function testEveryRenderedActionIsAllowedForTheRole(): void
    {
        $denied = [];
        foreach (['A', 'A1', 'rp', 'ad'] as $u) {
            $c = $this->staff($u);
            $pages = self::navRoutes($c->get('dashboard')->body);
            $this->assertNotEmpty($pages, $u);
            $links = [];
            foreach ($pages as $page) {
                if (in_array($page, ['settings/docs', 'community', 'pluginmanager', 'settings/dbstatus'], true)) {
                    continue; // environment pages (external network / host tools), as the WP2 route matrix
                }
                $r = $c->get($page);
                if ($r->status !== 200) {
                    continue;
                }
                foreach (self::actions($r->body) as [$route, $method]) {
                    $links[$route . '|' . $method] = [$page, $route, $method];
                }
            }
            $uid = (int) static::$seed->staff[$u];
            $check = json_encode(array_values($links));
            $verdicts = static::$app->exec(<<<PHP
\$a = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->find_one({$uid})->as_array(), 'WEB');
\$result = [];
foreach (json_decode('{$check}', true) as [\$page, \$route, \$method]) {
    \$result[] = [\$page, \$route, \$method, \\Reseller\\Ui\\Navigation::allowed(\$a, \$route, \$method)];
}
PHP);
            $this->assertIsArray($verdicts, $u);
            if ($u !== 'rp') {
                $this->assertGreaterThan(3, count($verdicts), "{$u}: actions found");
            }
            foreach ($verdicts as [$page, $route, $method, $ok]) {
                if (!$ok) {
                    $denied[] = "{$u} on {$page}: {$method} {$route}";
                }
            }
        }
        $this->assertSame([], $denied, 'actions rendered to a role that may not use them');
    }

    public function testActiveCustomersAndVouchersOfferOnlyPermittedActions(): void
    {
        foreach (['A', 'A1'] as $u) {
            $list = $this->staff($u)->get('plan/list')->body;
            $this->assertStringNotContainsString('_route=plan/recharge"', $list, "{$u}: no Recharge Account");
            $this->assertDoesNotMatchRegularExpression('#_route=plan/edit/\d+#', $list, "{$u}: no Edit");
            $this->assertStringNotContainsString('_route=routers/list', $list, "{$u}: no router link");
            $this->assertStringNotContainsString('_route=plan/add-voucher', $this->staff($u)->get('plan/voucher')->body, "{$u}: no + Vouchers");
            $this->assertSame(403, $this->staff($u)->get('plan/recharge')->status, "{$u}: the backend still refuses");
        }
        $admin = $this->staff('ad');
        $this->assertStringContainsString('_route=plan/recharge', $admin->get('plan/list')->body, 'Admin keeps Recharge');
        $this->assertStringContainsString('_route=plan/add-voucher', $admin->get('plan/voucher')->body, 'Admin keeps + Vouchers');
        $this->assertStringContainsString('_route=settings/users-add', $admin->get('settings/users')->body, 'Admin keeps Add user');
    }
}
