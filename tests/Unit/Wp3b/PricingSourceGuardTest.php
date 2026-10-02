<?php
namespace Reseller\Tests\Unit\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Finance\LedgerService;
use Reseller\Security\RouteMap;

/**
 * WP3B source guards (§22, application-level immutability; the WP3A guards stay in force): only the
 * approved writers touch the pricing tables, activation pricing snapshots have no overwrite path, one
 * ledger authority posts activation settlements, pricing code never writes tbl_plans / wallets / the
 * ledger, and no controller, API, plugin, cron or template reaches pricing.
 */
final class PricingSourceGuardTest extends TestCase
{
    private const FIN = 'system/autoload/Reseller/Finance/';
    private const PRICING_FILES = ['PricingService.php', 'PricingSnapshot.php', 'PricingLink.php', 'PricingResult.php', 'PricingReadModel.php', 'LegacyRetailPrice.php'];
    private const SNAPSHOT_COLUMNS = ['wholesale_cost', 'retail_price', 'provider_settlement_amount', 'plan_snapshot', 'pricing_chain_snapshot'];

    private static ?array $files = null;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> relative path => source of every runtime PHP/TPL file (comments stripped) */
    private static function runtime(): array
    {
        if (self::$files !== null) {
            return self::$files;
        }
        $root = self::root();
        $out = [];
        foreach (['system', 'ui', 'install'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = substr($f->getPathname(), strlen($root) + 1);
                if (!preg_match('/\.(php|tpl)$/', $rel) || str_starts_with($rel, 'system/vendor/') || str_starts_with($rel, 'system/reseller/migrations/')) {
                    continue;
                }
                $out[$rel] = self::code((string) file_get_contents($f->getPathname()));
            }
        }
        foreach (['index.php', 'init.php', 'radius.php', 'update.php'] as $f) {
            $out[$f] = self::code((string) file_get_contents($root . '/' . $f));
        }
        ksort($out);
        return self::$files = $out;
    }

    private static function code(string $src): string
    {
        $out = '';
        foreach (@token_get_all($src) as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    /** @return list<string> one entry per match */
    private static function hits(string $pattern): array
    {
        $hits = [];
        foreach (self::runtime() as $rel => $src) {
            $n = preg_match_all($pattern, $src);
            for ($i = 0; $i < $n; $i++) {
                $hits[] = $rel;
            }
        }
        return $hits;
    }

    public function testPricingTablesHaveOnlyTheApprovedWriter(): void
    {
        foreach (['tbl_reseller_plan_base_costs', 'tbl_manager_plan_prices'] as $t) {
            $w = self::hits('/\b(INSERT\s+(IGNORE\s+)?INTO|UPDATE)\s+`?' . $t . '\b/i');
            $this->assertNotEmpty($w, $t);
            $this->assertSame([self::FIN . 'PricingService.php'], array_values(array_unique($w)), "{$t}: only PricingService writes");
            $this->assertSame([], self::hits('/\b(REPLACE(\s+INTO)?|TRUNCATE(\s+TABLE)?|ALTER\s+TABLE|DROP\s+TABLE)\s+`?' . $t . '\b/i'), $t);
            $this->assertSame([], self::hits('/ON\s+DUPLICATE\s+KEY[^;]*' . $t . '|' . $t . '[^;]*ON\s+DUPLICATE\s+KEY/is'), "{$t}: no upsert");
            $this->assertSame([], self::hits('/LOAD\s+DATA[^;]*' . $t . '/i'), $t);
            $this->assertSame([], self::hits("/for_table\\(\\s*['\"]{$t}/"), "{$t}: no ORM access");
        }
        $this->assertSame([], self::hits('/DELETE\s+FROM\s+`?tbl_reseller_plan_base_costs\b/i'), 'base rows are never deleted');
        preg_match_all('/[\'"](DELETE\s+FROM\s+tbl_manager_plan_prices[^\'"]*)[\'"]/i', self::runtime()[self::FIN . 'PricingService.php'], $m);
        $this->assertSame(['DELETE FROM tbl_manager_plan_prices WHERE id = ? AND wholesale_cost IS NULL AND retail_price IS NULL AND is_enabled IS NULL'], $m[1], 'the only delete removes an all-NULL row');
        $this->assertSame([self::FIN . 'PricingService.php'], self::hits('/DELETE\s+FROM\s+`?tbl_manager_plan_prices\b/i'));
    }

    /** Activation pricing snapshots are write-once: no update, upsert, delete or ORM path exists. */
    public function testActivationSnapshotsAreWriteOnce(): void
    {
        $refs = [];
        foreach (self::runtime() as $rel => $src) {
            if (str_contains($src, 'tbl_reseller_activations')) {
                $refs[] = $rel;
            }
        }
        $this->assertSame([
            'system/autoload/Reseller/Domain/SchemaReadiness.php',
            self::FIN . 'LedgerService.php',
            self::FIN . 'ReservationService.php',
        ], $refs, 'only the approved files reference the activation table (WP5A will add its ActivationService)');
        foreach (self::runtime() as $rel => $src) {
            if (preg_match_all('/UPDATE\s+`?tbl_reseller_activations`?\s+SET([^\'"]*)/i', $src, $m)) {
                foreach ($m[1] as $set) {
                    foreach (self::SNAPSHOT_COLUMNS as $c) {
                        $this->assertDoesNotMatchRegularExpression('/\b' . $c . '\b/', $set, "{$rel}: {$c} must never be updated");
                    }
                }
            }
            if (preg_match_all('/INSERT\s+INTO\s+`?tbl_reseller_activations`?\s*\(([^)]*)\)/i', $src, $m)) {
                foreach ($m[1] as $cols) {
                    foreach (self::SNAPSHOT_COLUMNS as $c) {
                        $this->assertMatchesRegularExpression('/\b' . $c . '\b/', $cols, "{$rel}: an activation INSERT must seal {$c}");
                    }
                }
            }
        }
        $this->assertSame([], self::hits('/\b(DELETE\s+FROM|REPLACE(\s+INTO)?|TRUNCATE(\s+TABLE)?|ALTER\s+TABLE|DROP\s+TABLE)\s+`?tbl_reseller_activations\b/i'));
        $this->assertSame([], self::hits('/ON\s+DUPLICATE\s+KEY[^;]*tbl_reseller_activations|tbl_reseller_activations[^;]*ON\s+DUPLICATE\s+KEY/is'));
        $this->assertSame([], self::hits('/LOAD\s+DATA[^;]*tbl_reseller_activations/i'));
        $this->assertSame([], self::hits("/for_table\\(\\s*['\"]tbl_reseller_activations/"));
    }

    /** One activation-settlement ledger authority; no generic writer. */
    public function testOneSettlementAuthority(): void
    {
        $this->assertSame([self::FIN . 'LedgerService.php'], array_values(array_unique(self::hits('/COMMISSION_CREDIT/'))));
        $this->assertSame([self::FIN . 'LedgerService.php'], array_values(array_unique(self::hits('/ACTIVATION_DEBIT/'))));
        $rc = new \ReflectionClass(LedgerService::class);
        $this->assertFalse($rc->hasMethod('postActivationDebit'), 'the old independent activation-debit path is removed');
        $p = array_map(static fn(\ReflectionParameter $x) => $x->getName(), $rc->getMethod('postActivationSettlement')->getParameters());
        $this->assertSame(['pdo', 'reservationId', 'actor', 'leaf', 'recipients'], $p, 'ids, actor and locked wallets only: no amount, key or balance');
        $this->assertTrue($rc->getMethod('postActivationSettlement')->getParameters()[4]->isVariadic());
        $this->assertSame([self::FIN . 'ReservationService.php'], array_values(array_unique(self::hits('/LedgerService::postActivationSettlement\(/'))), 'only the commit primitive posts a settlement');
    }

    /** Pricing never writes legacy prices, wallets, transfers, reservations or the ledger. */
    public function testPricingCodeWritesNoMoney(): void
    {
        foreach (self::PRICING_FILES as $f) {
            $src = self::runtime()[self::FIN . $f] ?? '';
            $this->assertNotSame('', $src, $f);
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT\s+(IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE(\s+INTO)?)\s+`?tbl_(manager_(wallets|ledger|transfers|wallet_reservations)|plans|customers)\b/i', $src, "{$f}: money / legacy write");
            $this->assertDoesNotMatchRegularExpression('/\b(LedgerService|TransferService|ReservationService|LockedWallet)::/', $src, "{$f}: no finance writer call");
            $this->assertDoesNotMatchRegularExpression("/for_table\\(\\s*['\"]tbl_plans/", $src, "{$f}: no ORM plan write");
            $this->assertDoesNotMatchRegularExpression('/\$_(POST|GET|REQUEST|COOKIE)\b|\b_(post|get|req)\(/', $src, "{$f}: no request input");
        }
        foreach (self::runtime() as $rel => $src) {
            if (str_starts_with($rel, 'system/autoload/Reseller/')) {
                $this->assertDoesNotMatchRegularExpression('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?tbl_plans\b|for_table\(\s*[\'"]tbl_plans[\'"]\s*\)[^;]*->(save|delete)\(/i', $src, "{$rel}: reseller code never writes tbl_plans");
            }
        }
        $guard = self::runtime()['system/autoload/Reseller/Domain/PlanGuard.php'];
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT\s+INTO|(?<!FOR )UPDATE|DELETE\s+FROM|REPLACE)\b/i', $guard, 'PlanGuard is SELECT-only (besides its FOR UPDATE row lock)');
    }

    /** No controller, API, plugin, widget, cron or template reaches pricing or settlement; no pricing route. */
    public function testNoEntrypointOrRoute(): void
    {
        $outside = [];
        foreach (self::runtime() as $rel => $src) {
            if (str_starts_with($rel, self::FIN)) {
                continue;
            }
            if (preg_match('/\b(PricingService|PricingSnapshot|PricingLink|PricingResult|PricingReadModel|LegacyRetailPrice|SettlementFailure|PostedSettlement)\b/', $src)) {
                $outside[] = $rel;
            }
        }
        $this->assertSame([], $outside);
        $pattern = '/pric|wholesale|retail|base_cost|commission|availability|settlement/i';
        foreach (RouteMap::ROUTES as $controller => $actions) {
            $this->assertDoesNotMatchRegularExpression($pattern, (string) $controller);
            foreach ($actions as $action => $rule) {
                $this->assertDoesNotMatchRegularExpression($pattern, (string) $action, "{$controller}/{$action}");
                $this->assertDoesNotMatchRegularExpression('/^pricing\./', is_string($rule) ? $rule : (string) json_encode($rule), "{$controller}/{$action}: no pricing capability on a route");
            }
        }
        foreach (RouteMap::API_ALLOWED as $k => $v) {
            $this->assertDoesNotMatchRegularExpression($pattern, is_string($k) ? $k : (string) json_encode($v));
        }
    }

    /**
     * The legacy plan delete paths hand the hook, the device call and the delete to PlanGuard, which runs
     * the final reference check and all of them as one unit under the reseller tree lock (no TOCTOU gap).
     */
    public function testPlanDeleteIsGuardedBeforeAnySideEffect(): void
    {
        $src = (string) file_get_contents(self::root() . '/system/controllers/services.php');
        $this->assertStringNotContainsString('PlanGuard::deleteRefusal(', $src, 'no unserialized pre-check remains');
        foreach (['delete' => 'delete_plan', 'pppoe-delete' => 'delete_ppoe', 'balance-delete' => 'delete_balance', 'vpn-delete' => 'delete_vpn'] as $case => $hook) {
            $start = strpos($src, "case '{$case}':");
            $this->assertNotFalse($start, $case);
            $body = substr($src, $start, (int) strpos($src, 'break;', $start) - $start);
            $open = strpos($body, 'PlanGuard::deleteSerialized((int) $d[\'id\'], function () use ($d');
            $this->assertNotFalse($open, "{$case}: serialized delete");
            $close = strpos($body, "\n            });", $open);
            $this->assertNotFalse($close, "{$case}: closure end");
            $unit = substr($body, $open, $close - $open);
            $outside = substr($body, 0, $open) . substr($body, $close);
            $effects = ["run_hook('{$hook}')", '$d->delete()'];
            if (str_contains($body, 'remove_plan')) {
                $effects[] = 'remove_plan';
            }
            foreach ($effects as $effect) {
                $this->assertSame(1, substr_count($unit, $effect), "{$case}: {$effect} inside the serialized unit");
                $this->assertStringNotContainsString($effect, $outside, "{$case}: {$effect} never outside the unit");
            }
            $this->assertStringNotContainsString('catch (', $body, "{$case}: no controller-level catch (PlanGuard owns the failure policy)");
            $this->assertMatchesRegularExpression('/if \(\$nuxrRefusal !== null\) \{\s*r2\(getUrl\(\'services\/[a-z]+\'\), \'e\', \$nuxrRefusal\);/', $outside, "{$case}: clear refusal");
        }
        $this->assertStringContainsString("\r\n", $src, 'the legacy CRLF line endings are preserved');
        $this->assertStringNotContainsString("\n\n\n\n", str_replace("\r\n", "\n", $src) . '', 'no stray blank blocks');

        // the guard: tree lock first, then the plan row lock, then the reference check, then the effects
        $guard = self::runtime()['system/autoload/Reseller/Domain/PlanGuard.php'];
        $order = [];
        foreach (['Tx::withTreeLock(', "SELECT id FROM tbl_plans WHERE id = ? FOR UPDATE", "StaffService::isReferenced(\$pdo, 'tbl_plans'", '$effects = true;', '$delete();'] as $step) {
            $pos = strpos($guard, $step);
            $this->assertNotFalse($pos, $step);
            $order[] = $pos;
        }
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'lock, row lock, check, effects');
        $this->assertSame(1, substr_count($guard, '$delete();'), 'the effects run only inside the locked unit');
    }

    /**
     * Shared serialization contract for plan references (WP3B fix round 2): every runtime writer of a table
     * that references tbl_plans runs under the reseller tree lock, the same lock plan deletion holds.
     */
    public function testPlanReferenceWritersHoldTheTreeLock(): void
    {
        $refTables = 'tbl_reseller_plan_base_costs|tbl_manager_plan_prices|tbl_reseller_activations';
        $writers = [];
        foreach (self::runtime() as $rel => $src) {
            if (preg_match('/\b(INSERT\s+(IGNORE\s+)?INTO|REPLACE(\s+INTO)?)\s+`?(' . $refTables . ')\b/i', $src)) {
                $writers[] = $rel;
            }
        }
        // ReservationService inserts reservations only; activations are inserted by WP5A (none yet)
        $this->assertSame([self::FIN . 'PricingService.php'], $writers, 'the only runtime plan-reference writer (WP5A ActivationService must join under the same contract)');
        $src = self::runtime()[self::FIN . 'PricingService.php'];
        preg_match_all('/FinanceTx::run\(static function[^{]*\{.*?\n        \}, (true|false)\);/s', $src, $m);
        $this->assertSame(substr_count($src, 'FinanceTx::run('), count($m[1]), 'every pricing transaction is parsed');
        $this->assertNotEmpty($m[1]);
        $this->assertSame(array_fill(0, count($m[1]), 'true'), $m[1], 'every pricing transaction holds the tree lock');
    }
}
