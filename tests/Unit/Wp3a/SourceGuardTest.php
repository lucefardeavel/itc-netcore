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

    /** base 3e634550 content hashes of files WP3A must not change */
    private const PINNED = [
        'system/autoload/Reseller/Domain/ServiceGate.php' => 'f0d6f3d96283725b134433122aaf8442eb8db983148b0a45379d8a6cb4f0ed82',
        'system/autoload/Reseller/Security/RouteMap.php' => '9ae1260a4e4b1b7734816088bd81d0762247d38baf26c4c00389ab35718ffc53',
        'system/autoload/Reseller/Domain/Tx.php' => '223cfb618ee7372c615bb93f37c86db4da739d47e2da03692f976bf3b2cbccc6',
        'system/autoload/Reseller/Domain/SchemaReadiness.php' => '7c280e094d9ea7fccd62a7dc47a52fc3790508a6cd1e865c3104483aaec7c265',
        'system/autoload/Balance.php' => '143cad4966e580de20a3f575144f347d55f20b36501f482da7dc764443e011ae',
        'system/autoload/Package.php' => '9f3cc352bc563d689e3cb5e41015b297d1a9f57a2034b618597fd8c80e4ebd4c',
        'system/reseller/migrations/R0001_manager_tree.php' => 'a0b52f7fb4bd5f5e317c701d85df6db7d2a95ace04ec1b1112873191e514a5a7',
        'system/reseller/migrations/R0002_pricing_foundation.php' => '38bceb1fb4f4be1b26bdb53c5317840109ce6f7e1ef7b98dc8af61b9b8c3b771',
        'system/reseller/migrations/R0003_activation_foundation.php' => '60e63156bdc90e9772dd479f69c9507fdc648f98dbbe301ab060ff59fe3f9acd',
        'system/reseller/migrations/R0004_wallet_finance_foundation.php' => '7f3bc5bc8479bfe0f186ec4ae00af4623cc0c8d7201f13897f7184d4b92dffa7',
        'system/reseller/migrations/R0005_customer_ownership.php' => 'b69135eab8b70f3bed2355946d959be356c3d48897df9db6cb41c6c7e45b3854',
        'system/reseller/migrations/R0006_reseller_audit.php' => 'f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd',
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
    public function testNoEntrypointReachesTheFinanceServices(): void
    {
        $outside = [];
        foreach (self::runtime() as $rel => $src) {
            if (str_starts_with($rel, self::FIN)) {
                continue;
            }
            if (preg_match('/Reseller\\\\+Finance|\b(TransferService|WalletService|LedgerService|ReservationService|LockedWallet|FinanceTx|FinanceFaults)\b/', self::code($src))) {
                $outside[] = $rel;
            }
        }
        $this->assertSame([], $outside);
        foreach (RouteMap::ROUTES as $controller => $actions) {
            $this->assertDoesNotMatchRegularExpression('/wallet|ledger|finance|reservation/i', (string) $controller);
            foreach (array_keys($actions) as $action) {
                $this->assertDoesNotMatchRegularExpression('/wallet|ledger|finance|reservation|debt|fund/i', (string) $action, "{$controller}/{$action}");
            }
        }
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
        $this->assertSame(['postActivationDebit', 'postTransferLeg'], $writes);
        $src = self::runtime()[self::FIN . 'LedgerService.php'];
        $this->assertSame(1, preg_match_all('/INSERT INTO tbl_manager_ledger/', $src));
        $this->assertMatchesRegularExpression('/private static function insert\(/', $src);
    }

    /** Accepted R0001–R0006, the WP2 ServiceGate and other untouched files stay byte-identical; no R0007. */
    public function testAcceptedFilesAreByteIdentical(): void
    {
        foreach (self::PINNED as $rel => $sha) {
            $this->assertSame($sha, hash_file('sha256', self::root() . '/' . $rel), "{$rel} changed");
        }
        $migrations = array_map('basename', glob(self::root() . '/system/reseller/migrations/*.php'));
        sort($migrations);
        $this->assertSame(['R0001_manager_tree.php', 'R0002_pricing_foundation.php', 'R0003_activation_foundation.php',
            'R0004_wallet_finance_foundation.php', 'R0005_customer_ownership.php', 'R0006_reseller_audit.php'], $migrations, 'no R0007');
        $this->assertSame([], self::hits('/CREATE\s+TRIGGER/i'), 'no triggers (P1)');
    }
}
