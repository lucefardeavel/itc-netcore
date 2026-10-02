<?php
namespace Reseller\Tests\Unit\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP5A source guards (owner decisions A1, A6; WP3B §9; WP4 §10):
 *  - activations are inserted only by ActivationStore::insert (one sealed INSERT), only from
 *    ActivationService::open, which runs only inside FinanceTx::run(..., true) (the tree lock);
 *  - no activation snapshot column is ever updated; the event timeline is append-only;
 *  - the activation layer never calls Package / hooks / notifications, never writes the ledger, wallets,
 *    reservations, transfers, pricing or the RADIUS tables directly (finance and RADIUS services only);
 *  - no request input inside Reseller\Activation; the failure hook is test-only; the CLI tool is CLI-only.
 */
final class ActivationSourceGuardTest extends TestCase
{
    private const DIR = 'system/autoload/Reseller/Activation/';
    private const SNAPSHOT_COLUMNS = ['wholesale_cost', 'retail_price', 'provider_settlement_amount', 'plan_snapshot', 'pricing_chain_snapshot'];

    /** @var array<string,string>|null */
    private static ?array $files = null;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> runtime PHP/TPL files (comments stripped) */
    private static function runtime(): array
    {
        if (self::$files !== null) {
            return self::$files;
        }
        $out = [];
        foreach (['system', 'ui'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = substr($f->getPathname(), strlen(self::root()) + 1);
                if (preg_match('/\.(php|tpl)$/', $rel) && !str_starts_with($rel, 'system/vendor/')) {
                    $out[$rel] = self::code((string) file_get_contents($f->getPathname()));
                }
            }
        }
        foreach (glob(self::root() . '/*.php') as $f) {
            $out[basename($f)] = self::code((string) file_get_contents($f));
        }
        return self::$files = $out;
    }

    private static function code(string $src): string
    {
        $out = '';
        foreach (@token_get_all($src) as $t) {
            if (!is_array($t) || ($t[0] !== T_COMMENT && $t[0] !== T_DOC_COMMENT)) {
                $out .= is_array($t) ? $t[1] : $t;
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function activation(): array
    {
        return array_filter(self::runtime(), static fn(string $rel): bool => str_starts_with($rel, self::DIR), ARRAY_FILTER_USE_KEY);
    }

    public function testOneSealedInsertOnlyUnderTheTreeLock(): void
    {
        $inserters = array_keys(array_filter(self::runtime(), static fn(string $s): bool => (bool) preg_match('/INSERT\s+INTO\s+`?tbl_reseller_activations\b/i', $s)));
        $this->assertSame([self::DIR . 'ActivationStore.php'], $inserters);
        $store = self::runtime()[self::DIR . 'ActivationStore.php'];
        $this->assertSame(1, preg_match_all('/INSERT\s+INTO\s+tbl_reseller_activations\b/i', $store), 'one INSERT');
        $callers = array_keys(array_filter(self::runtime(), static fn(string $s): bool => str_contains($s, 'ActivationStore::insert(')));
        $this->assertSame([self::DIR . 'ActivationService.php'], $callers);
        $svc = self::runtime()[self::DIR . 'ActivationService.php'];
        $this->assertSame(1, substr_count($svc, 'ActivationStore::insert('));
        $open = substr($svc, (int) strpos($svc, 'private static function open('));
        $open = substr($open, 0, (int) strpos($open, "\n    }\n") );
        $this->assertStringContainsString('ActivationStore::insert(', $open, 'inserted only in open()');
        $this->assertStringContainsString('PricingService::resolveInTx($pdo', $open, 'with the price resolved in the same transaction');
        $this->assertSame(1, substr_count($svc, 'self::open('), 'open() has one caller');
        $this->assertMatchesRegularExpression('/FinanceTx::run\(\s*static fn\(\\\\PDO \$pdo\): array => self::open\([^;]*\),\s*true\s*\)/s', $svc, 'open() runs only under the tree lock');
    }

    public function testSnapshotsAreNeverUpdatedAndEventsAreAppendOnly(): void
    {
        $store = self::runtime()[self::DIR . 'ActivationStore.php'];
        preg_match('/\$allowed = \[([^\]]*)\];/', $store, $m);
        $this->assertNotEmpty($m, 'the updatable column list exists');
        foreach (self::SNAPSHOT_COLUMNS as $c) {
            $this->assertStringNotContainsString("'{$c}'", $m[1], "{$c} is never updatable");
        }
        foreach (['plan_id', 'customer_id', 'manager_id', 'action', 'idempotency_key', 'reference', 'previous_plan_id'] as $c) {
            $this->assertStringNotContainsString("'{$c}'", $m[1], "{$c} is never updatable");
        }
        foreach (self::runtime() as $rel => $src) {
            $this->assertDoesNotMatchRegularExpression('/\b(UPDATE|DELETE\s+FROM|REPLACE(\s+INTO)?|TRUNCATE(\s+TABLE)?)\s+`?tbl_reseller_activation_events\b/i', $src, "{$rel}: events are append-only");
            $this->assertDoesNotMatchRegularExpression("/for_table\\(\\s*['\"]tbl_reseller_activation_events/", $src, "{$rel}: no ORM path to events");
        }
    }

    public function testTheActivationLayerUsesOnlyTheAuthoritativeServices(): void
    {
        $this->assertNotEmpty(self::activation());
        foreach (self::activation() as $rel => $src) {
            $this->assertStringContainsString("if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }", $src, "{$rel}: runtime guard");
            $this->assertDoesNotMatchRegularExpression('/\bPackage::|rechargeUser|\brun_hook\(|\bMessage::|sendTelegram|User::/', $src, "{$rel}: no legacy recharge, hooks or messages");
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?tbl_(manager_(ledger|wallets|wallet_reservations|transfers|plan_prices)|reseller_plan_base_costs|plans|customers)\b/i', $src, "{$rel}: no direct finance / pricing / plan / customer writes");
            $this->assertDoesNotMatchRegularExpression('/\b(radcheck|radreply|radusergroup|radgroupreply|radacct|rad_acct)\b/', $src, "{$rel}: RADIUS only through Reseller\\Radius");
            $this->assertDoesNotMatchRegularExpression('/\$_(POST|GET|REQUEST|COOKIE|SERVER)\b|\b_(post|get|req)\(/', $src, "{$rel}: no request input");
            $this->assertDoesNotMatchRegularExpression('/\bfloat\b|\bround\(|number_format\(/', $src, "{$rel}: no float money");
        }
        $this->assertSame([], array_keys(array_filter(self::runtime(), static fn(string $s): bool => (bool) preg_match('/ActivationFaults::\$hook\s*=/', $s))), 'the failure hook is test-only');
        $cli = (string) file_get_contents(self::root() . '/system/reseller/reconcile.php');
        $this->assertMatchesRegularExpression("/if \\(PHP_SAPI !== 'cli'\\) \\{\\s*http_response_code\\(404\\);\\s*exit;/", $cli, 'the reconciliation tool is CLI-only');
    }

    public function testTheControllerStaysThin(): void
    {
        $c = self::runtime()['system/controllers/activation.php'];
        $this->assertDoesNotMatchRegularExpression('/Reseller\\\\+Finance|Reseller\\\\+Radius|tbl_reseller_activations|tbl_manager_/', $c, 'no finance, RADIUS or table access in the controller');
        $this->assertDoesNotMatchRegularExpression('/_post\(\s*[\'"](price|amount|wholesale|retail|cost|expiry|expiration)/i', $c, 'no browser-supplied money or period');
    }
}
