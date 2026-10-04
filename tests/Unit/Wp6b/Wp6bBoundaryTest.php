<?php
namespace Reseller\Tests\Unit\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Security\RouteMap;

/**
 * WP6B boundary (owner decision OD-1): Controllers / Templates -> Reseller\Backoffice -> Reseller\Finance /
 * Reseller\Activation. The guards prove the new boundary positively:
 *  - nothing WP6B adds is API-reachable; the reseller API allow-list is the exact accepted action list;
 *  - Backoffice is a gateway only: no SQL, no table names, no money arithmetic, no ledger posting, no internal finance
 *    primitives, no request input of its own;
 *  - controllers stay thin: no finance / RADIUS / table access and no ActivationStore orchestration;
 *  - the new Finance read models are read-only;
 *  - finance / pricing table writers stay exactly the accepted services (WP3A / WP3B guards, unchanged).
 */
final class Wp6bBoundaryTest extends TestCase
{
    /** The accepted reseller API actions (WP2), made explicit by WP6B instead of '*'. */
    private const RESELLER_API = ['tree', 'place-post', 'move-post', 'status-post', 'customer-assign-sales', 'customer-clear-sales', 'customer-transfer', 'customer-assign-owner'];

    /** WP6B web controllers: never API-reachable. */
    private const WEB_ONLY = ['finance', 'pricing', 'operations', 'activation'];

    /** New WP6B read models inside Reseller\Finance (SELECT-only). */
    private const FINANCE_READ_MODELS = ['LedgerReadModel.php', 'SettlementReadModel.php', 'WalletPreview.php'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> relative path => source */
    private static function files(string $dir, string $ext = 'php'): array
    {
        $out = [];
        foreach (glob(self::root() . '/' . $dir . '/*.' . $ext) ?: [] as $f) {
            $out[substr($f, strlen(self::root()) + 1)] = (string) file_get_contents($f);
        }
        return $out;
    }

    /** Source without comments (a docblock may name a table or a service; code may not use it). */
    private static function code(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    public function testNothingWp6bAddsIsApiReachable(): void
    {
        foreach (self::WEB_ONLY as $controller) {
            $this->assertArrayNotHasKey($controller, RouteMap::API_ALLOWED, "{$controller} is web-only");
        }
        $this->assertSame(self::RESELLER_API, RouteMap::API_ALLOWED['reseller'], "reseller API access is the exact accepted list (never '*')");
        foreach (array_keys(RouteMap::ROUTES['reseller']) as $action) {
            if ($action === '' || $action === '*') {
                continue;
            }
            $this->assertTrue(in_array($action, self::RESELLER_API, true) || !in_array($action, (array) RouteMap::API_ALLOWED['reseller'], true),
                "reseller/{$action}: a new reseller action is not API-reachable");
        }
        $this->assertNotContains('detail', RouteMap::API_ALLOWED['reseller'], 'reseller/detail is web-only');
    }

    public function testBackofficeIsAGatewayOnly(): void
    {
        $gateway = self::files('system/autoload/Reseller/Backoffice');
        $this->assertIsArray($gateway);
        foreach ($gateway as $rel => $src) {
            $code = self::code($src);
            $this->assertStringContainsString("if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }", $src, "{$rel}: runtime guard");
            $this->assertDoesNotMatchRegularExpression('/\btbl_[a-z_]+/', $code, "{$rel}: no table access of any kind");
            $this->assertDoesNotMatchRegularExpression('/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE)\s+/', $code, "{$rel}: no SQL");
            $this->assertDoesNotMatchRegularExpression('/\bTx::|for_table\(|->prepare\(|->query\(|->exec\(|FinanceTx\b|\bORM::/', $code, "{$rel}: no database handle");
            $this->assertDoesNotMatchRegularExpression('/->(plus|minus|times|negate)\(|Money::(fromMinor|zero)\(/', $code, "{$rel}: no money arithmetic");
            $this->assertDoesNotMatchRegularExpression('/LedgerService::post|ReservationService|LockedWallet|resolveInTx|FinanceFaults|ActivationStore/', $code, "{$rel}: no internal primitives");
            $this->assertDoesNotMatchRegularExpression('/\$_(POST|GET|REQUEST|COOKIE|FILES|SERVER)\b|\b_(post|get|req)\(/', $code, "{$rel}: request input is the controller's");
            $this->assertDoesNotMatchRegularExpression('/\(float\)|\bfloatval\(|\bround\(|\bnumber_format\(/', $code, "{$rel}: no float money");
            $this->assertDoesNotMatchRegularExpression('/curl_|file_get_contents\(|fsockopen|Http::/', $code, "{$rel}: no network");
        }
    }

    public function testControllersStayThin(): void
    {
        foreach (['finance', 'pricing', 'operations', 'activation', 'reseller'] as $name) {
            $file = self::root() . '/system/controllers/' . $name . '.php';
            if (!is_file($file)) {
                continue;
            }
            $code = self::code((string) file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression('/Reseller\\\\+Finance|Reseller\\\\+Radius|tbl_manager_|tbl_reseller_activations|tbl_reseller_plan_base_costs/', $code, "{$name}: no finance / RADIUS / table access");
            $this->assertDoesNotMatchRegularExpression('/ActivationStore|ReservationService|LedgerService/', $code, "{$name}: no low-level orchestration");
            $this->assertDoesNotMatchRegularExpression('/_post\(\s*[\'"](balance|available|wholesale|retail_effective|commission|settlement|provider|reserved)/i', $code, "{$name}: no browser-supplied financial authority");
        }
    }

    /** OD-9: each bounded pricing read is ONE tree-locked evaluation (never quote() per plan, never an unbounded catalog). */
    public function testPricingPagesTakeOneTreeLockPerPage(): void
    {
        $src = (string) file_get_contents(self::root() . '/system/autoload/Reseller/Finance/PricingService.php');
        foreach (['catalogForManager', 'matrixForPlan'] as $fn) {
            $start = strpos($src, 'public static function ' . $fn . '(');
            $this->assertNotFalse($start, $fn);
            $body = substr($src, (int) $start, (int) strpos($src, "\n    }\n", (int) $start) - (int) $start);
            $this->assertSame(1, substr_count($body, 'FinanceTx::run('), "{$fn}: one tree-locked read");
            $this->assertStringContainsString('}, true);', $body, "{$fn}: under the tree lock");
            $this->assertStringNotContainsString('self::quote(', $body, "{$fn}: no quote() per row");
            $this->assertMatchesRegularExpression('/LIMIT \' \. self::PAGE_(PLANS|MANAGERS)/', $body, "{$fn}: bounded page");
            $this->assertStringContainsString('PricingReadModel::project(', $body, "{$fn}: the accepted projection");
        }
        foreach (self::files('system/autoload/Reseller/Backoffice') as $rel => $code) {
            $this->assertDoesNotMatchRegularExpression('/PricingService::(quote|validateTree)\(/', self::code($code), "{$rel}: pages use the bounded reads");
        }
    }

    public function testFinanceReadModelsAreReadOnly(): void
    {
        $this->assertNotEmpty(self::FINANCE_READ_MODELS);
        foreach (self::FINANCE_READ_MODELS as $f) {
            $file = self::root() . '/system/autoload/Reseller/Finance/' . $f;
            if (!is_file($file)) {
                continue;
            }
            $code = self::code((string) file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP)\s+(INTO\s+|FROM\s+|TABLE\s+)?`?tbl_/i', $code, "{$f}: SELECT-only");
            $this->assertDoesNotMatchRegularExpression('/FinanceTx::run\(|LockedWallet|LedgerService::post|->save\(|->delete\(/', $code, "{$f}: no write path");
        }
    }
}
