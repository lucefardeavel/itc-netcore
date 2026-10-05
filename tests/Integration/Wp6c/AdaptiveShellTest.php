<?php
namespace Reseller\Tests\Integration\Wp6c;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6C owner amendments B / C / E over real HTTP: the presentation Up registry (only a parent the actor may open,
 * never the RouteMap), permission-denied / not-found pages inside the shell (navigation, recovery, status kept, no
 * object detail), and the viewport declaration for safe areas.
 */
final class AdaptiveShellTest extends Wp2HttpTestCase
{
    private static function up(string $html): ?string
    {
        return preg_match('#class="nuxr-icon-btn nuxr-up" href="[^"]*\?_route=([^"]+)"#', $html, $m) ? html_entity_decode($m[1]) : null;
    }

    public function testUpLeadsToTheParentTheActorMayOpen(): void
    {
        $s = static::$seed;
        $this->assertSame('customers/list', self::up($this->staff('A')->get('customers/view/' . $s->customers['cA1'])->body));
        $this->assertSame('settings/users', self::up($this->staff('ad')->get('settings/users-view/' . $s->staff['A'])->body));
        $this->assertSame('reseller/tree', self::up($this->staff('ad')->get('reseller/detail/' . $s->staff['A'])->body));
        $this->assertNull(self::up($this->staff('ad')->get('customers/list')->body), 'top-level destinations have no Up');
        $this->assertNull(self::up($this->staff('A')->get('dashboard')->body));
        // the registry never offers a parent the actor may not open (Sales: no wallet pages -> Home)
        $ids = ['A1' => $s->staff['A1'], 'A' => $s->staff['A'], 'ad' => $s->staff['ad']];
        $map = json_encode($ids);
        $out = static::$app->exec(<<<PHP
\$result = [];
foreach (json_decode('{$map}', true) as \$u => \$id) {
    \$a = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->find_one(\$id)->as_array(), 'WEB');
    foreach ([['finance', 'wallet', '5'], ['finance', 'entry', '7'], ['plan', 'edit', '1'], ['customers', 'edit', '3'], ['operations', 'held']] as \$r) {
        \$up = \\Reseller\\Ui\\Navigation::up(\$a, \$r);
        \$result[\$u][implode('/', \$r)] = \$up === null ? null : [\$up['route'], \\Reseller\\Ui\\Navigation::allowed(\$a, \$up['route'])];
    }
}
PHP);
        foreach ($out as $u => $rows) {
            foreach ($rows as $route => $up) {
                $this->assertNotNull($up, "{$u} {$route}: a detail / form route always offers Up");
                $this->assertTrue($up[1], "{$u} {$route}: Up -> {$up[0]} must be openable by the actor");
            }
        }
        $this->assertSame('dashboard', $out['A1']['finance/wallet/5'][0], 'Sales has no wallet pages: Up falls back to Home');
        $this->assertSame('plan/list', $out['A']['plan/edit/1'][0]);
        $this->assertSame('operations/overview', $out['ad']['operations/held'][0]);
    }

    public function testDeniedAndMissingPagesStayInsideTheShell(): void
    {
        $agent = $this->staff('A');
        $r = $agent->get('plan/edit/1');
        $this->assertSame(403, $r->status);
        $this->assertStringContainsString('class="nuxr-bottomnav"', $r->body, 'the shell navigation is kept');
        $this->assertStringContainsString('nuxr-state-page', $r->body);
        $this->assertStringContainsString('_route=plan/list', $r->body, 'recovery: the parent the Agent may open');
        $this->assertStringContainsString('_route=dashboard', $r->body);
        $this->assertStringNotContainsString('_route=plan/edit/1', $r->body, 'the denied object is not linked or named');
        $this->assertStringNotContainsString('maximum-scale', $r->body, 'zoom allowed');
        $rp = $this->staff('rp')->get('customers/list');
        $this->assertSame(403, $rp->status);
        $this->assertStringContainsString('nuxr-state-page', $rp->body);
        $this->assertStringNotContainsString('_route=customers/', $rp->body, 'no customer destination for Report');
        // a controller-rendered not-found (static page that does not exist): same shell state page
        $missing = $this->staff('ad')->get('pages/No_Such_Page');
        $this->assertStringContainsString('nuxr-state-page', $missing->body);
        $this->assertStringContainsString('class="nuxr-bottomnav"', $missing->body);
    }

    public function testViewportDeclaresSafeAreas(): void
    {
        foreach (['dashboard', 'customers/list'] as $route) {
            $this->assertMatchesRegularExpression('#<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">#', $this->staff('A')->get($route)->body);
        }
        $this->assertStringContainsString('viewport-fit=cover', $this->client()->get('admin')->body, 'staff login');
    }
}
