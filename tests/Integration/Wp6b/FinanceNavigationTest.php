<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6B slice 8 over real HTTP (owner decision OD-3 / OD-5): the Finance / Resellers / Pricing / Operations
 * destinations follow the accepted RouteMap rules; Agent phone primary = Home / Customers / New (+) / Wallet / More;
 * money quick actions only where the capability applies; Sales / Report get no finance destination; the dashboard
 * finance tiles are role-specific. (Every rendered link answering is covered by the WP6A ShellNavigationTest.)
 */
final class FinanceNavigationTest extends Wp2HttpTestCase
{
    /** @return list<string> routes linked from the navigation regions */
    private static function routes(string $html, string $region = ''): array
    {
        $regions = '';
        foreach ($region !== '' ? [$region] : ['/<aside class="nuxr-sidebar".*?<\/aside>/s', '/<nav class="nuxr-bottomnav".*?<\/nav>/s',
            '/<dialog id="nuxr-more-sheet".*?<\/dialog>/s', '/<dialog id="nuxr-quick-sheet".*?<\/dialog>/s'] as $re) {
            if (preg_match($re, $html, $m)) {
                $regions .= $m[0];
            }
        }
        preg_match_all('/href="[^"]*\?_route=([^"&]+)/', $regions, $m);
        return array_values(array_unique(array_map('html_entity_decode', $m[1])));
    }

    /** @return list<string> */
    private static function primary(string $html): array
    {
        preg_match('/<nav class="nuxr-bottomnav".*?<\/nav>/s', $html, $m);
        preg_match_all('/<span>([^<]+)<\/span>/', $m[0] ?? '', $l);
        return $l[1];
    }

    public function testDestinationsFollowTheRole(): void
    {
        $finance = ['finance/overview', 'finance/transfers', 'finance/sales', 'finance/commissions', 'finance/performance', 'reseller/tree', 'pricing/plans'];
        $admin = self::routes($this->staff('ad')->get('dashboard')->body);
        foreach (array_merge($finance, ['finance/wallets', 'finance/settlement', 'operations/overview']) as $r) {
            $this->assertContains($r, $admin, "Admin: {$r}");
        }
        $this->assertNotContains('finance/ledger', $admin, 'a global viewer opens a ledger from a wallet');
        $agent = self::routes($this->staff('A')->get('dashboard')->body);
        foreach (array_merge($finance, ['finance/ledger', 'finance/wallets', 'finance/send']) as $r) {
            $this->assertContains($r, $agent, "Agent with a child: {$r}");
        }
        foreach (['finance/settlement', 'operations/overview', 'finance/funding'] as $r) {
            $this->assertNotContains($r, $agent, "Agent: no {$r}");
        }
        $leaf = self::routes($this->staff('B')->get('dashboard')->body);
        $this->assertContains('finance/overview', $leaf);
        $this->assertNotContains('finance/send', $leaf, 'no "Send" without a child reseller');
        $this->assertNotContains('finance/wallets', $leaf);
        foreach (['A1', 'rp', 'E'] as $u) {
            $routes = self::routes($this->staff($u)->get('dashboard')->body);
            foreach (['finance/', 'pricing/', 'operations/'] as $prefix) {
                $this->assertSame([], array_values(array_filter($routes, static fn(string $r): bool => str_starts_with($r, $prefix))), "{$u}: no {$prefix} destination");
            }
        }
        // the Agent's "Ledger" destination is its own ledger (no wallet id)
        $ledger = $this->staff('A')->get('finance/ledger');
        $this->assertSame(200, $ledger->status, $ledger->excerpt());
        $this->assertStringContainsString('_route=finance/ledger/' . static::$seed->staff['A'], $ledger->body);
        // the Reseller Tree moved from Settings to Resellers
        $html = $this->staff('ad')->get('dashboard')->body;
        preg_match('#<summary><i class="fa fa-cog[^>]*></i><span>Settings</span>.*?</details>#s', $html, $settings);
        $this->assertNotEmpty($settings);
        $this->assertStringNotContainsString('reseller/tree', $settings[0]);
        $this->assertStringContainsString('Transactions log', $html);
    }

    public function testPrimaryNavigationAndQuickActions(): void
    {
        $this->assertSame(['Home', 'Customers', 'New', 'Wallet', 'More'], self::primary($this->staff('A')->get('dashboard')->body));
        $this->assertSame(['Home', 'Customers', 'New', 'Wallet', 'More'], self::primary($this->staff('B')->get('dashboard')->body));
        $this->assertSame(['Home', 'Customers', 'New', 'Activations', 'More'], self::primary($this->staff('ad')->get('dashboard')->body));
        $this->assertSame(['Home', 'Customers', 'New', 'Activations', 'More'], self::primary($this->staff('A1')->get('dashboard')->body));
        $this->assertNotContains('Wallet', self::primary($this->staff('E')->get('dashboard')->body), 'unenrolled Agent: WP6A navigation');
        $quick = static fn(string $html): array => self::routes($html, '/<dialog id="nuxr-quick-sheet".*?<\/dialog>/s');
        $this->assertContains('finance/send', $quick($this->staff('A')->get('dashboard')->body));
        $this->assertContains('finance/wallets', $quick($this->staff('ad')->get('dashboard')->body), 'Admin: Fund reseller');
        $this->assertNotContains('finance/send', $quick($this->staff('B')->get('dashboard')->body));
        // the Wallet destination is active on the finance pages
        $page = $this->staff('A')->get('finance/overview')->body;
        preg_match('/<nav class="nuxr-bottomnav".*?<\/nav>/s', $page, $nav);
        $this->assertMatchesRegularExpression('#_route=finance/overview"[^>]*aria-current="page"#', $nav[0] ?? '');
    }

    public function testDashboardFinanceTilesAreRoleSpecific(): void
    {
        $agent = $this->staff('A')->get('dashboard')->body;
        $this->assertStringContainsString('id="nuxr-home-wallet"', $agent);
        $this->assertStringContainsString('No wallet yet', $agent, 'an unfunded Agent: never shown as an established zero');
        $admin = $this->staff('ad')->get('dashboard')->body;
        $this->assertStringContainsString('Wallets with no available credit', $admin);
        $this->assertStringContainsString('Operations: stale activations', $admin);
        foreach (['A1', 'rp', 'E'] as $u) {
            $html = $this->staff($u)->get('dashboard')->body;
            $this->assertStringNotContainsString('id="nuxr-home-wallet"', $html, $u);
            $this->assertStringNotContainsString('Wallets with no available credit', $html, $u);
        }
    }
}
