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
 * Backend authorization is unchanged and stays authoritative (the same rule). Fix round 1: route-level parity is not
 * the only oracle any more - rendered object links on the converted pages are followed over HTTP (read-only GETs).
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

    /**
     * Route-level parity (above) is not proof for a link to a specific object. On the converted daily-use / staff pages
     * every rendered internal GET link to an object (view / edit / detail pages) is actually opened with the role's
     * session and must not end in a denial: no 403 / 404, no state page, no "Account Not Found", no legacy permission
     * alert. Read-only GETs only; POST actions are never executed (their object rules are covered by TargetParityTest).
     */
    public function testRenderedObjectLinksOpenForTheTarget(): void
    {
        $pages = ['settings/users', 'plan/voucher', 'plan/list', 'customers/list', 'activation/list', 'reports/activation', 'reports', 'logs/list'];
        $target = '#^(settings/users-(view|edit)|customers/(view|viewu|edit)|plan/(view|edit|voucher-view)|activation/view|reseller/detail|finance/(wallet|transfer|entry|ledger)|services/(edit|pppoe-edit|vpn-edit)|routers/edit|bandwidth/edit|pool/edit)/[^/]+#';
        $bad = [];
        $opened = 0;
        foreach (['A', 'A1', 'rp', 'ad'] as $u) {
            $c = $this->staff($u);
            $seen = [];
            foreach ($pages as $page) {
                $r = $c->get($page);
                if ($r->status !== 200 || str_contains($r->body, 'nuxr-state-page')) {
                    continue; // a page the role cannot open renders no object links
                }
                $start = strpos($r->body, 'id="nuxr-main"');
                $main = $start === false ? $r->body : substr($r->body, $start, (int) strpos($r->body, '<footer', $start) - $start);
                preg_match_all('/<a\b([^>]*)>/', $main, $m);
                foreach ($m[1] as $attrs) {
                    if (str_contains($attrs, 'data-method="post"') || !preg_match('/href="[^"]*\?_route=([^"#]+)"/', $attrs, $h)) {
                        continue;
                    }
                    $route = html_entity_decode($h[1]);
                    if (preg_match($target, explode('&', $route)[0]) && !isset($seen[$route]) && count($seen) < 60) {
                        $seen[$route] = $page;
                    }
                }
            }
            foreach ($seen as $route => $page) {
                $res = $c->get($route);
                $opened++;
                $denied = $res->status !== 200 || str_contains($res->body, 'nuxr-state-page') || str_contains($res->body, 'Account Not Found')
                    || str_contains($res->body, 'You do not have permission to access this page');
                if ($denied) {
                    $bad[] = "{$u} on {$page}: {$route} -> {$res->status}";
                    $c->get('dashboard'); // a legacy denial leaves a flash message for the next page: consume it
                }
            }
        }
        $this->assertGreaterThan(20, $opened, 'object links were actually followed');
        $this->assertSame([], $bad, 'a rendered object link leads to a denial');
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
