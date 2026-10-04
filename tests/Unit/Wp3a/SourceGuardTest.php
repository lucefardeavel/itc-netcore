<?php
namespace Reseller\Tests\Unit\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Finance\LedgerService;
use Reseller\Security\RouteMap;

/**
 * WP3A source guards (owner decision P1: application-level immutability). Static proof over every
 * runtime file that no code path other than the approved financial services can write the reseller
 * finance tables, that the ledger has no update/delete path, that finance code never touches the
 * legacy customer balance, and that no controller / API / plugin reaches the finance services.
 */
final class SourceGuardTest extends TestCase
{
    private const FIN = 'system/autoload/Reseller/Finance/';

    /**
     * base 3e634550 content hashes of files WP3A must not change. SchemaReadiness is deliberately not
     * pinned: the WP3A fix round adds the finance runtime tables to its required-table check.
     */
    private const PINNED = [
        'system/autoload/Reseller/Domain/ServiceGate.php' => 'f0d6f3d96283725b134433122aaf8442eb8db983148b0a45379d8a6cb4f0ed82',
        // WP5A (owner decision A7): the activation routes; WP6A (owner decision OD-C): + read-only activation/list;
        // WP6B (owner decision OD-1): the WP6B web surface and the explicit reseller API allow-list
        'system/autoload/Reseller/Security/RouteMap.php' => 'c0c998947e8fecea78dd4c9fbdca4861ddf261a8e8da4febeb39c89d516c372a',
        'system/autoload/Reseller/Domain/Tx.php' => '223cfb618ee7372c615bb93f37c86db4da739d47e2da03692f976bf3b2cbccc6',
        'system/autoload/Balance.php' => '9652370fcc1d7ca370ff9061c4631fa34531f2049a5da9c0b9b8601ab00c57ae',
        // WP4 (owner decision Q5): the device calls in rechargeUser run after the recharge row is saved
        'system/autoload/Package.php' => 'e4225dd06c4e4b5e555f141f8319f9a2575c4d7461f96ff30dc52b35f1f36346',
        // accepted WP1 migration runner
        'system/reseller/migrate.php' => '8420e021293260ae01f097e5d202221003a172e14e116933831e0087f44803fa',
        'system/reseller/Migration/Db.php' => 'd5123172de0d8bda244c3c8081f8c156e454766d50c3af49958b4f30048c1c51',
        'system/reseller/Migration/Grants.php' => '321f1e3a69d23000417a2bab638383c25d05fd62fa4048446b08a4e2b5ab1d58',
        'system/reseller/Migration/MigrationDefinition.php' => 'faf492d26e3598927ac7c99b2730ecdb57672ae86936e4b0a7923caa8393ddf2',
        'system/reseller/Migration/MigrationSet.php' => '7421f6f5ce66ca4bd87000d9c57a9e1afd03a07bb6b88353209699111c48187e',
        'system/reseller/Migration/Outcome.php' => 'af89a92118e38c2f6a952bfa3e7e9ce2d2f8d3f095ae6d5485174326e78ff491',
        'system/reseller/Migration/Registry.php' => 'dceb66f1c05450df45e4452d2407edcecf8fd60af24744dc9ef96d010e9a26a2',
        'system/reseller/Migration/Runner.php' => 'c0172038c69f6ee3da86faaee2ea0f777028d805d1361d4d9c1abc65dbdfc247',
        'system/reseller/Migration/SchemaInspector.php' => '7ee73bfd5319c81c7169c631a5de497af09502c2c0b39bd27dbf65c702c5a5a0',
        'system/reseller/Migration/VariantRegistry.php' => 'f11f0031041a2d610dbdb2675e92885f510ca8ac039e856f06cdb05e18d006d6',
        'system/reseller/Migration/variants.php' => '4d9f7f8a38c81d29164a474aaa36a0d29ef10bc7c12beecdc37f02b391022202',
        'system/reseller/migrations/R0001_manager_tree.php' => 'a0b52f7fb4bd5f5e317c701d85df6db7d2a95ace04ec1b1112873191e514a5a7',
        'system/reseller/migrations/R0002_pricing_foundation.php' => '38bceb1fb4f4be1b26bdb53c5317840109ce6f7e1ef7b98dc8af61b9b8c3b771',
        'system/reseller/migrations/R0003_activation_foundation.php' => '60e63156bdc90e9772dd479f69c9507fdc648f98dbbe301ab060ff59fe3f9acd',
        'system/reseller/migrations/R0004_wallet_finance_foundation.php' => '7f3bc5bc8479bfe0f186ec4ae00af4623cc0c8d7201f13897f7184d4b92dffa7',
        'system/reseller/migrations/R0005_customer_ownership.php' => 'b69135eab8b70f3bed2355946d959be356c3d48897df9db6cb41c6c7e45b3854',
        'system/reseller/migrations/R0006_reseller_audit.php' => 'f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd',
        'system/reseller/migrations/R0007_activation_reversal_states.php' => 'b7edce53accd75a5af7bce6cbf66a9bae02b90f8f67211000a5fb08328ef00f6',
    ];

    private static ?array $files = null;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> relative path => source of every runtime PHP/TPL file */
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
                if (!preg_match('/\.(php|tpl)$/', $rel) || str_starts_with($rel, 'system/vendor/')) {
                    continue;
                }
                $out[$rel] = (string) file_get_contents($f->getPathname());
            }
        }
        foreach (['index.php', 'init.php', 'radius.php', 'update.php'] as $f) {
            $out[$f] = (string) file_get_contents($root . '/' . $f);
        }
        ksort($out);
        return self::$files = $out;
    }

    /** @return list<string> files (excluding the accepted migration definitions) whose source matches */
    private static function hits(string $pattern): array
    {
        $hits = [];
        foreach (self::runtime() as $rel => $src) {
            if (str_starts_with($rel, 'system/reseller/migrations/')) {
                continue;
            }
            $n = preg_match_all($pattern, $src);
            for ($i = 0; $i < $n; $i++) {
                $hits[] = $rel;
            }
        }
        return $hits;
    }

    /** Source without comments (a docblock may name a service; code may not call it). */
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

    public function testScanCoversTheRuntime(): void
    {
        $this->assertGreaterThan(150, count(self::runtime()));
        $this->assertArrayHasKey(self::FIN . 'LedgerService.php', self::runtime());
        $this->assertArrayHasKey('system/controllers/settings.php', self::runtime());
        $this->assertArrayHasKey('system/api.php', self::runtime());
    }

    /** P1: the ledger is append-only; LedgerService is its only writer. */
    public function testLedgerHasNoUpdateOrDeletePathAndOneWriter(): void
    {
        $this->assertSame([], self::hits('/\b(UPDATE|DELETE\s+FROM|REPLACE(\s+INTO)?|TRUNCATE(\s+TABLE)?|ALTER\s+TABLE|DROP\s+TABLE)\s+`?tbl_manager_ledger\b/i'));
        $this->assertSame([self::FIN . 'LedgerService.php'], self::hits('/\bINSERT\s+(IGNORE\s+)?INTO\s+`?tbl_manager_ledger\b/i'));
        $this->assertSame([], self::hits('/LOAD\s+DATA[^;]*tbl_manager_/i'));
    }

    public function testWalletWriters(): void
    {
        $this->assertSame([self::FIN . 'LockedWallet.php'], self::hits('/\bINSERT\s+(IGNORE\s+)?INTO\s+`?tbl_manager_wallets\b/i'), 'only LockedWallet creates a wallet');
        $this->assertSame([self::FIN . 'LedgerService.php'], self::hits('/\bUPDATE\s+`?tbl_manager_wallets`?\s+SET\s+balance\b/i'), 'only LedgerService writes a balance');
        $this->assertSame([self::FIN . 'WalletService.php'], self::hits('/\bUPDATE\s+`?tbl_manager_wallets`?\s+SET\s+debt_limit\b/i'), 'only WalletService writes a debt limit');
        $this->assertSame(
            [self::FIN . 'LedgerService.php', self::FIN . 'WalletService.php'],
            self::hits('/\bUPDATE\s+`?tbl_manager_wallets\b/i'),
            'no other wallet update'
        );
        $this->assertSame([], self::hits('/\b(DELETE\s+FROM|REPLACE(\s+INTO)?|TRUNCATE(\s+TABLE)?|ALTER\s+TABLE|DROP\s+TABLE)\s+`?tbl_manager_wallets\b/i'));
    }

    public function testReservationAndTransferWriters(): void
    {
        $res = self::hits('/\b(INSERT\s+INTO|UPDATE)\s+`?tbl_manager_wallet_reservations\b/i');
        $this->assertNotEmpty($res);
        $this->assertSame([self::FIN . 'ReservationService.php'], array_values(array_unique($res)));
        $trf = self::hits('/\b(INSERT\s+INTO|UPDATE)\s+`?tbl_manager_transfers\b/i');
        $this->assertNotEmpty($trf);
        $this->assertSame([self::FIN . 'TransferService.php'], array_values(array_unique($trf)));
        // the only transfer UPDATE is the COMPLETED -> REVERSED state transition of a reversed operation
        preg_match_all('/"UPDATE\s+tbl_manager_transfers\s+SET\s+([^"]+)"/i', self::runtime()[self::FIN . 'TransferService.php'], $m);
        $this->assertSame(["status = 'REVERSED' WHERE id = ? AND status = 'COMPLETED'"], $m[1]);
        foreach (['tbl_manager_wallet_reservations', 'tbl_manager_transfers'] as $t) {
            $this->assertSame([], self::hits('/\b(DELETE\s+FROM|REPLACE(\s+INTO)?|TRUNCATE(\s+TABLE)?|ALTER\s+TABLE|DROP\s+TABLE)\s+`?' . $t . '\b/i'), $t);
        }
    }

    public function testNoOrmAccessToFinanceTables(): void
    {
        $this->assertSame([], self::hits("/for_table\\(\\s*['\"]tbl_manager_(wallets|ledger|transfers|wallet_reservations)/"));
        $this->assertSame([], self::hits("/for_table\\(\\s*['\"]tbl_reseller_activations/"));
    }

    /** Customer balance separation and no request input / floats inside the financial services. */
    public function testFinanceCodeIsSeparatedFromLegacyMoneyAndInput(): void
    {
        $finance = array_filter(self::runtime(), static fn(string $src, string $rel) => str_starts_with($rel, self::FIN), ARRAY_FILTER_USE_BOTH);
        $this->assertGreaterThanOrEqual(15, count($finance));
        foreach ($finance as $rel => $src) {
            $this->assertStringContainsString("if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }", $src, "{$rel} runtime guard");
            $this->assertDoesNotMatchRegularExpression('/\bBalance::|\bPackage::|tbl_customers|\bUser::|\bTransaction::/', $src, "{$rel}: legacy money / customer balance");
            $this->assertDoesNotMatchRegularExpression('/\$_(POST|GET|REQUEST|COOKIE|FILES|SESSION|SERVER)\b|\b_(post|get|req)\(/', $src, "{$rel}: request input");
            $this->assertDoesNotMatchRegularExpression('/\(float\)|\(double\)|\bfloatval\(|\bround\(|\bnumber_format\(|\bbcadd\(|\bgmp_/i', $src, "{$rel}: float or arbitrary-precision helpers");
            foreach (token_get_all($src) as $t) {
                if (is_array($t)) {
                    $this->assertNotSame(T_DNUMBER, $t[0], "{$rel}: float literal {$t[1]}");
                    if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $this->assertNotContains(strtoupper(trim($t[1], '\'"')), ['API'], "{$rel}: WP3A never emits actor_type API");
                    }
                }
            }
            $this->assertDoesNotMatchRegularExpression('/curl_|file_get_contents\(|fsockopen|Http::|sendTelegram|Message::/i', $src, "{$rel}: no network or external side effect");
        }
    }

    /** No controller, API, plugin, widget, cron or template reaches the financial services (P7). */
    /**
     * WP6B owner decision OD-1 (narrow amendment of the WP3A P7 guard): the finance web surface exists only as the
     * exact 'finance' controller actions below, reached through the Reseller\\Backoffice gateway; nothing else in the
     * runtime (no controller, template or other class) reaches the finance services, and nothing finance-named is
     * API-reachable.
     */
    public const WP6B_FINANCE_ACTIONS = [
        'overview', 'wallets', 'wallet', 'ledger', 'entry', 'transfers', 'transfer',
        'funding', 'funding-review', 'funding-post', 'send', 'send-review', 'send-post',
        'debt-limit', 'debt-limit-review', 'debt-limit-post', 'adjust', 'adjust-review', 'adjust-post',
        'reverse', 'reverse-review', 'reverse-post', 'sales', 'commissions', 'settlement', 'performance',
    ];

    public function testNoEntrypointReachesTheFinanceServices(): void
    {
        $outside = [];
        foreach (self::runtime() as $rel => $src) {
            // WP5A (owner decision A1): the activation orchestration is an authorized finance caller;
            // WP6B (OD-1): the Backoffice gateway is the only other one (controllers / templates never)
            if (str_starts_with($rel, self::FIN) || str_starts_with($rel, 'system/autoload/Reseller/Activation/')
                || str_starts_with($rel, 'system/autoload/Reseller/Backoffice/')) {
                continue;
            }
            if (preg_match('/Reseller\\\\+Finance|\b(TransferService|WalletService|LedgerService|ReservationService|LockedWallet|FinanceTx|FinanceFaults)\b/', self::code($src))) {
                $outside[] = $rel;
            }
        }
        $this->assertSame([], $outside);
        foreach (RouteMap::ROUTES as $controller => $actions) {
            if ($controller === 'finance') {
                $named = array_values(array_filter(array_keys($actions), static fn($k): bool => $k !== '' && $k !== '*'));
                $this->assertSame([], array_values(array_diff($named, self::WP6B_FINANCE_ACTIONS)), 'finance: only the owner-approved WP6B actions');
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/wallet|ledger|finance|reservation/i', (string) $controller);
            foreach (array_keys($actions) as $action) {
                $this->assertDoesNotMatchRegularExpression('/wallet|ledger|finance|reservation|debt|fund/i', (string) $action, "{$controller}/{$action}");
            }
        }
        $this->assertArrayNotHasKey('finance', RouteMap::API_ALLOWED, 'the finance web surface is never API-reachable');
        foreach (RouteMap::API_ALLOWED as $k => $v) {
            $this->assertDoesNotMatchRegularExpression('/wallet|ledger|finance|reservation|debt|fund/i', is_string($k) ? $k : (string) json_encode($v));
        }
    }

    public function testFaultHookIsNeverAssignedAtRuntime(): void
    {
        $this->assertSame([], self::hits('/FinanceFaults::\$hook\s*=/'));
        $tests = (string) file_get_contents(self::root() . '/tests/Support/FinanceTestCase.php');
        $this->assertMatchesRegularExpression('/FinanceFaults::\$hook\s*=/', $tests, 'the hook is test-only');
    }

    public function testNoGenericLedgerWriter(): void
    {
        $rc = new \ReflectionClass(LedgerService::class);
        $writes = array_values(array_filter(
            array_map(static fn(\ReflectionMethod $m) => $m->getName(), $rc->getMethods(\ReflectionMethod::IS_PUBLIC)),
            static fn(string $n) => str_starts_with($n, 'post')
        ));
        sort($writes);
        // WP3B F-2: the single activation financial posting primitive replaced postActivationDebit
        // WP5B (OD-12): the single completed-activation reversal posting
        $this->assertSame(['postActivationReversal', 'postActivationSettlement', 'postTransferLeg'], $writes);
        $src = self::runtime()[self::FIN . 'LedgerService.php'];
        $this->assertSame(1, preg_match_all('/INSERT INTO tbl_manager_ledger/', $src));
        $this->assertMatchesRegularExpression('/private static function insert\(/', $src);
    }

    /**
     * Accepted R0001–R0006, the migration runner, the WP2 ServiceGate and other untouched files stay byte-identical.
     * WP5B: the owner-approved R0007 (activation reversal states) and the runner's 'redefines' primitive are pinned.
     */
    public function testAcceptedFilesAreByteIdentical(): void
    {
        foreach (self::PINNED as $rel => $sha) {
            $this->assertSame($sha, hash_file('sha256', self::root() . '/' . $rel), "{$rel} changed");
        }
        $migrations = array_map('basename', glob(self::root() . '/system/reseller/migrations/*.php'));
        sort($migrations);
        $this->assertSame(['R0001_manager_tree.php', 'R0002_pricing_foundation.php', 'R0003_activation_foundation.php',
            'R0004_wallet_finance_foundation.php', 'R0005_customer_ownership.php', 'R0006_reseller_audit.php',
            'R0007_activation_reversal_states.php'], $migrations, 'R0001–R0007 only');
        $this->assertSame([], self::hits('/CREATE\s+TRIGGER/i'), 'no triggers (P1)');
    }
}
