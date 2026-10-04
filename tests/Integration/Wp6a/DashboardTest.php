<?php
namespace Reseller\Tests\Integration\Wp6a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6A dashboard foundation (OD-L): every count is the actor's authoritative scope (customer.read DataScope); 7-day
 * expiring threshold; no finance tiles (WP6B); the landing page loads no chart bundle (OD-H); the legacy global
 * widgets stay a global-scope tab only (WP2 OD-4 unchanged); Report gets an honest empty state.
 */
final class DashboardTest extends Wp2HttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // a subscriber of A's subtree (cB, owned by B) expiring in 3 days; one of C expired
        $c = static::$seed->customers;
        static::$db->exec("UPDATE tbl_user_recharges SET expiration = '" . date('Y-m-d', strtotime('+3 days')) . "' WHERE customer_id = {$c['cB']}");
        static::$db->exec("UPDATE tbl_user_recharges SET expiration = '" . date('Y-m-d', strtotime('-3 days')) . "', status = 'off' WHERE customer_id = {$c['cC1']}");
    }

    /** @return array<string,int> stat label => value */
    private static function stats(string $html): array
    {
        preg_match_all('#<span class="nuxr-stat-value"><bdi dir="ltr">(\d+)</bdi></span><span class="nuxr-stat-label"><i[^>]*></i>([^<]+)</span>#', $html, $m);
        return array_combine(array_map('trim', $m[2]), array_map('intval', $m[1]));
    }

    private function scopedCount(string $actor): int
    {
        $s = static::$seed;
        return match ($actor) {
            'ad' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers'),
            'A' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE manager_id IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ?)', [$s->staff['A']]),
            'A1' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE assigned_sales_id = ? AND manager_id = ?', [$s->staff['A1'], $s->staff['A']]),
            'C' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE manager_id IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ?)', [$s->staff['C']]),
        };
    }

    public function testCountsAreTheActorsScope(): void
    {
        foreach (['ad', 'A', 'A1', 'C'] as $actor) {
            $html = $this->staff($actor)->get('dashboard')->body;
            $stats = self::stats($html);
            $this->assertSame($this->scopedCount($actor), $stats['All subscribers'] ?? -1, "{$actor}: all subscribers");
            $this->assertArrayHasKey('Expiring within 7 days', $stats);
            $this->assertArrayHasKey('Needs attention', $stats, "{$actor}: activation tiles (activation.read)");
            $this->assertDoesNotMatchRegularExpression('/wallet|ledger|commission|balance/i', strip_tags((string) preg_replace('#<(script|nav|aside|dialog)\b.*?</\1>#s', '', $html)), "{$actor}: no finance tiles");
            $this->assertStringNotContainsStringIgnoringCase('chart.min.js', $html, "{$actor}: no chart bundle on the landing page");
        }
        $this->assertSame(1, self::stats($this->staff('A')->get('dashboard')->body)['Expiring within 7 days'], 'A: cB (subtree) expires in 3 days');
        $this->assertSame(0, self::stats($this->staff('A1')->get('dashboard')->body)['Expiring within 7 days'], 'A1: cB is not assigned to it');
        $this->assertSame(0, self::stats($this->staff('C')->get('dashboard')->body)['Expiring within 7 days']);
        $this->assertSame(1, self::stats($this->staff('C')->get('dashboard')->body)['Expired'], 'C: cC1 expired');
        $this->assertStringContainsString('customers/view/' . static::$seed->customers['cB'], $this->staff('A')->get('dashboard')->body, 'expiring list');
        $this->assertStringNotContainsString('customers/view/' . static::$seed->customers['cB'], $this->staff('C')->get('dashboard')->body, 'never another tree');
    }

    public function testWidgetsTabIsGlobalOnlyAndReportGetsAnEmptyState(): void
    {
        $admin = $this->staff('ad');
        $this->assertStringContainsString('widgets=1', $admin->get('dashboard')->body);
        $w = $admin->get('dashboard', ['widgets' => 1]);
        $this->assertSame(200, $w->status);
        $this->assertStringContainsString('chart.min.js', $w->body, 'charts only on the widgets tab');
        foreach (['A', 'A1'] as $actor) {
            $html = $this->staff($actor)->get('dashboard', ['widgets' => 1])->body;
            $this->assertStringNotContainsString('chart.min.js', $html, "{$actor}: no global widgets (WP2 OD-4)");
            $this->assertStringNotContainsString('Charts and widgets', $html);
        }
        $rp = $this->staff('rp')->get('dashboard')->body;
        $this->assertStringContainsString('No data views are assigned to this role.', $rp);
        $this->assertSame([], self::stats($rp));
    }
}
