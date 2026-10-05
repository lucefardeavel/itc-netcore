<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpResponse;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6B owner fix round 1 (OD-6): an export above 20,000 rows is refused with EXPORT_TOO_LARGE before any CSV byte;
 * exactly 20,000 rows is allowed and complete; an export is never silently truncated. Real data at the boundary:
 * 20,000 / 20,001 Agents in the Admin scope (reseller performance, wallets) and 20,000 / 20,001 resellable plans
 * (pricing catalog of one reseller, bounded one-tree-lock-per-page reads).
 */
final class ExportCapTest extends Wp2HttpTestCase
{
    private const CAP = 20000;

    /** Inserts $count rows through one set-based statement over a 0..99,999 digit cross join (portable on both engines). */
    private static function seq(int $count): string
    {
        $d = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 '
            . 'UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        return "(SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d AS n FROM {$d} a CROSS JOIN {$d} b CROSS JOIN {$d} c CROSS JOIN {$d} e CROSS JOIN {$d} f "
            . 'WHERE a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d < ' . $count . ') s';
    }

    /** Brings the number of Agents (the Admin performance / wallets scope) to exactly $n. */
    private static function agents(int $n): void
    {
        $have = (int) static::$db->value("SELECT COUNT(*) FROM tbl_users WHERE user_type = 'Agent'");
        if ($n > $have) {
            static::$db->exec("INSERT INTO tbl_users (username, fullname, password, user_type, status, root, creationdate, last_login) SELECT CONCAT('capag', s.n + "
                . $have . "), CONCAT('Cap Agent ', s.n), 'x', 'Agent', 'Active', 0, NOW(), NOW() FROM " . self::seq($n - $have));
        }
        self::assertSame($n, (int) static::$db->value("SELECT COUNT(*) FROM tbl_users WHERE user_type = 'Agent'"));
    }

    /** Brings the number of resellable (base-cost) plans to exactly $n, copying one real plan. */
    private static function plans(int $n): void
    {
        $have = (int) static::$db->value("SELECT COUNT(*) FROM tbl_plans p JOIN tbl_reseller_plan_base_costs b ON b.plan_id = p.id WHERE p.type <> 'Balance'");
        if ($n < $have) {
            $ids = array_column(static::$db->rows("SELECT p.id FROM tbl_plans p WHERE p.name_plan LIKE 'capplan%' ORDER BY p.id DESC LIMIT " . ($have - $n)), 'id');
            static::$db->exec('DELETE FROM tbl_reseller_plan_base_costs WHERE plan_id IN (' . implode(',', array_map('intval', $ids)) . ')');
        } elseif ($n > $have) {
            $cols = array_values(array_diff(array_column(static::$db->rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_plans' ORDER BY ORDINAL_POSITION"), 'COLUMN_NAME'), ['id', 'name_plan']));
            $list = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $cols));
            $src = (int) static::$db->value("SELECT id FROM tbl_plans WHERE name_plan = 'wp6b_cap_plan'");
            $first = (int) static::$db->value('SELECT COALESCE(MAX(id), 0) FROM tbl_plans');
            static::$db->exec("INSERT INTO tbl_plans (name_plan, {$list}) SELECT CONCAT('capplan', LPAD(s.n + {$first}, 9, '0')), {$list} FROM tbl_plans p CROSS JOIN "
                . self::seq($n - $have) . " WHERE p.id = {$src}");
            $ad = static::$seed->staff['ad'];
            static::$db->exec("INSERT INTO tbl_reseller_plan_base_costs (plan_id, base_cost, is_resale_enabled, created_by, created_at, updated_by, updated_at) "
                . "SELECT p.id, '10.00', 1, {$ad}, UTC_TIMESTAMP(6), {$ad}, UTC_TIMESTAMP(6) FROM tbl_plans p WHERE p.id > {$first} AND p.name_plan LIKE 'capplan%'");
        }
        self::assertSame($n, (int) static::$db->value("SELECT COUNT(*) FROM tbl_plans p JOIN tbl_reseller_plan_base_costs b ON b.plan_id = p.id WHERE p.type <> 'Balance'"));
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp6b_cap_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp6b_cap_plan', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
    }

    /** @return int CSV data rows (BOM + header line excluded) */
    private static function csvRows(HttpResponse $r): int
    {
        $lines = array_values(array_filter(explode("\n", $r->body), static fn(string $l): bool => trim($l) !== ''));
        return max(0, count($lines) - 1);
    }

    private function assertRefusedBeforeOutput(HttpResponse $r, int $auditsBefore, string $what): void
    {
        $this->assertSame(302, $r->status, "{$what}: refused with a redirect, " . $r->excerpt());
        $this->assertStringNotContainsString('text/csv', (string) $r->header('content-type'), "{$what}: no CSV response");
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $r->body, "{$what}: zero CSV bytes");
        $this->assertArrayNotHasKey('content-disposition', $r->headers, "{$what}: no attachment");
        $this->assertSame($auditsBefore, $this->exports(), "{$what}: a refused export is not recorded as an export");
    }

    private function exports(): int
    {
        return (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE event_type = 'export.finance'");
    }

    private static function range(): array
    {
        return ['from' => gmdate('Y-m-d', time() - 86400), 'to' => gmdate('Y-m-d', time() + 86400), 'format' => 'csv'];
    }

    public function testPerformanceExportIsCompleteAtTheCapAndRefusedAboveIt(): void
    {
        $ad = $this->staff('ad');
        self::agents(self::CAP);
        $r = $ad->get('finance/performance', self::range());
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertStringContainsString('text/csv', (string) $r->header('content-type'));
        $this->assertSame(self::CAP, self::csvRows($r), 'exactly 20,000 rows: allowed and complete');
        self::agents(self::CAP + 1);
        $before = $this->exports();
        // f7496710: a valid-looking CSV with only the first 20,000 rows (silent truncation)
        $this->assertRefusedBeforeOutput($ad->get('finance/performance', self::range()), $before, 'performance 20,001');
        // the same scoped population through the wallets export (pre-refused cursor, audited fix-round invariant)
        $this->assertRefusedBeforeOutput($ad->get('finance/wallets', ['format' => 'csv']), $before, 'wallets 20,001');
        // the screens themselves stay available (bounded pages)
        $this->assertSame(200, $ad->get('finance/performance', array_diff_key(self::range(), ['format' => 1]))->status);
    }

    public function testPricingCatalogExportIsCompleteAtTheCapAndRefusedAboveIt(): void
    {
        $ad = $this->staff('ad');
        $target = static::$seed->staff['A'];
        // below the cap, across several bounded pages: every row, once
        self::plans(60);
        $r = $ad->get('pricing/catalog/' . $target, ['format' => 'csv']);
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertSame(60, self::csvRows($r), 'three pages of 25: all 60 rows');
        $this->assertSame(60, count(array_unique(array_filter(explode("\n", $r->body), static fn(string $l): bool => str_contains($l, 'capplan') || str_contains($l, 'wp6b_cap_plan')))));
        self::plans(self::CAP + 1);
        $before = $this->exports();
        // f7496710: no preflight - the whole catalog streamed whatever its size
        $this->assertRefusedBeforeOutput($ad->get('pricing/catalog/' . $target, ['format' => 'csv']), $before, 'catalog 20,001');
        // exactly the cap: allowed and complete (run in the application context: at ~1 evaluated plan per 10 ms the
        // stream takes minutes, longer than the HTTP test client's 60 s budget)
        self::plans(self::CAP);
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
ob_start();
\$n = \\Reseller\\Backoffice\\PricingDesk::exportCatalog(\$ad, {$target});
\$csv = (string) ob_get_clean();
\$lines = array_values(array_filter(explode("\\n", \$csv), static fn(string \$l): bool => trim(\$l) !== ''));
\$result = ['rows' => \$n, 'data_lines' => count(\$lines) - 1, 'bom' => str_starts_with(\$csv, "\\xEF\\xBB\\xBF"), 'distinct' => count(array_unique(\$lines)) - 1];
PHP);
        $this->assertSame(['rows' => self::CAP, 'data_lines' => self::CAP, 'bom' => true, 'distinct' => self::CAP], $out, 'exactly 20,000 rows: allowed and complete');
        $this->assertSame($before + 1, $this->exports(), 'the allowed export is audited once');
        $this->assertSame(200, $ad->get('pricing/catalog/' . $target)->status, 'the screen stays available');
    }
}
