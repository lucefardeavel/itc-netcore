<?php
namespace Reseller\Tests\Unit\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP6B owner fix round 1 (OD-6) audit, as a guard: every CSV export (Backoffice\ExportStream::csv producer) is either
 * an authoritative Finance export cursor that counts and streams in one ExportCap snapshot (refused above the cap,
 * never truncated), or the pricing catalog, pre-refused with ExportCap::assertWithin and structurally bounded by its
 * preflight pages. No producer stops at the cap (a silent truncation).
 */
final class ExportCapGuardTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    /** @return array<string,string> "Class::method" => body of every public static export cursor in the Finance read models */
    private static function cursors(): array
    {
        $out = [];
        foreach (glob(self::root() . '/system/autoload/Reseller/Finance/*.php') ?: [] as $f) {
            $s = (string) file_get_contents($f);
            if (!preg_match_all('/public static function (export[A-Z]\w*)\([^)]*callable \$sink\)[^{]*\{/', $s, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[1] as $i => [$name]) {
                $start = $m[0][$i][1];
                $out[basename($f, '.php') . '::' . $name] = substr($s, $start, (int) strpos($s, "\n    }\n", $start) - $start);
            }
        }
        return $out;
    }

    public function testEveryFinanceExportCursorCountsAndStreamsInOneCappedSnapshot(): void
    {
        $cursors = self::cursors();
        $this->assertSame(['LedgerReadModel::exportLedger', 'LedgerReadModel::exportTransfers', 'LedgerReadModel::exportWallets', 'SettlementReadModel::exportLegs',
            'SettlementReadModel::exportPerformance', 'SettlementReadModel::exportSettlement'], (static function (array $k): array { sort($k); return $k; })(array_keys($cursors)));
        foreach ($cursors as $name => $body) {
            $this->assertStringContainsString('ExportCap::snapshot($pdo,', $body, "{$name}: count + stream in one capped snapshot");
            $this->assertDoesNotMatchRegularExpression('/EXPORT_MAX_ROWS|MAX_ROWS|\$n\s*[<>]=?/', $body, "{$name}: never stops at the cap (no silent truncation)");
        }
    }

    public function testEveryCsvProducerIsACappedCursorOrThePreRefusedCatalog(): void
    {
        $producers = 0;
        foreach (glob(self::root() . '/system/autoload/Reseller/Backoffice/*.php') ?: [] as $f) {
            $s = (string) file_get_contents($f);
            $this->assertDoesNotMatchRegularExpression('/EXPORT_MAX_ROWS|MAX_ROWS/', $s, basename($f) . ': no cap arithmetic in the gateway');
            preg_match_all('/ExportStream::csv\(/', $s, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [, $at]) {
                $producers++;
                $call = substr($s, $at, (int) strpos($s, ".csv');", $at) - $at);
                if (str_contains($call, "'pricing_catalog'")) {
                    continue; // asserted below
                }
                $this->assertMatchesRegularExpression('/static fn\(callable \$sink\): int => (LedgerReadModel|SettlementReadModel)::export[A-Z]\w*\(/', $call,
                    basename($f) . ': the producer is a capped Finance export cursor');
            }
        }
        $this->assertSame(7, $producers, 'wallets, ledger, transfers, sales / commissions, settlement, performance, pricing catalog');
        $desk = self::src('system/autoload/Reseller/Backoffice/PricingDesk.php');
        $start = (int) strpos($desk, 'public static function exportCatalog(');
        $body = substr($desk, $start, (int) strpos($desk, "\n    }\n", $start) - $start);
        $this->assertLessThan(strpos($body, 'ExportStream::csv('), strpos($body, "ExportCap::assertWithin((int) \$first['total']);"), 'catalog: refused before any output');
        $this->assertStringContainsString('if ($page >= $pages)', $body, 'catalog: exactly the preflight pages');
        preg_match('/PAGE_PLANS = (\d+);/', self::src('system/autoload/Reseller/Finance/PricingService.php'), $page);
        preg_match('/MAX_ROWS = (\d+);/', self::src('system/autoload/Reseller/Finance/ExportCap.php'), $cap);
        $this->assertSame(0, (int) $cap[1] % (int) $page[1], 'pages x PAGE_PLANS can never exceed the cap');
        $this->assertSame('20000', $cap[1]);
    }
}
