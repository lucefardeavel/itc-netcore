<?php
namespace Reseller\Tests\Unit\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/** WP5B static guards over the runtime sources (comments stripped). */
final class Wp5bSourceGuardTest extends TestCase
{
    /** @var array<string,string>|null */
    private static ?array $files = null;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> */
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

    /** @return list<string> files matching $re */
    private static function hits(string $re): array
    {
        return array_keys(array_filter(self::runtime(), static fn(string $s): bool => (bool) preg_match($re, $s)));
    }

    private const VOUCHER_SERVICE = 'system/autoload/Reseller/Domain/VoucherRedemptionService.php';
    private const REDEMPTION_PATHS = ['system/controllers/plan.php', 'system/controllers/voucher.php', 'system/controllers/login.php', 'radius.php'];

    /** §6.1 / §6.4: one redemption path; no other code redeems, finalizes or re-issues a voucher. */
    public function testSingleVoucherRedemptionPath(): void
    {
        foreach (self::REDEMPTION_PATHS as $rel) {
            $src = self::runtime()[$rel];
            $this->assertStringContainsString('VoucherRedemptionService::redeem', $src, "{$rel} redeems through the service");
            $this->assertDoesNotMatchRegularExpression("/tbl_voucher['\"]\\)[^;]*->where\\(\\s*['\"]status['\"]\\s*,\\s*['\"]?0['\"]?\\s*\\)/", $src, "{$rel}: no status-0 redemption query");
            $this->assertDoesNotMatchRegularExpression('/\$v1?->status\s*=\s*[\'"]?[01][\'"]?\s*;/', $src, "{$rel}: no voucher status write");
        }
        $this->assertSame([self::VOUCHER_SERVICE], self::hits("/UPDATE\\s+tbl_voucher\\s+SET\\s+status/i"), 'only the service changes a voucher status');
        $svc = self::runtime()[self::VOUCHER_SERVICE];
        $this->assertSame(preg_match_all("/SET status = '0'/", $svc), preg_match_all("/SET status = '0' WHERE id = \\? AND status = '\" \\. self::CLAIMED/", $svc),
            'every release to 0 is conditional on CLAIMED (proven not started only)');
        $this->assertDoesNotMatchRegularExpression('/ActorContext::(make|forStaff|forCustomer|current)\(/', $svc, 'no fabricated or implicit principal');
        $this->assertDoesNotMatchRegularExpression('/ActorContext::(make|forStaff|forCustomer)\(/', self::runtime()['system/autoload/Reseller/Domain/RedemptionSource.php']);
    }

    /** §6.4: every voucher delete is conditional on its status (CLAIMED is never deleted). */
    public function testVoucherDeletesAreConditionalOnStatus(): void
    {
        $plan = self::runtime()['system/controllers/plan.php'];
        preg_match_all("/for_table\\('tbl_voucher'\\)[^;]*?(->delete_many\\(\\)|->delete\\(\\))/s", $plan, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $stmt) {
            $this->assertMatchesRegularExpression("/where\\('status'|where_not_equal\\('status'/", $stmt, 'conditional delete: ' . substr($stmt, 0, 120));
        }
        $this->assertDoesNotMatchRegularExpression('/\$d->delete\(\);\s*r2\(getUrl\(\'plan\/voucher\'\)/', $plan, 'no unconditional single voucher delete');
        foreach (self::runtime() as $rel => $src) {
            if ($rel !== 'system/controllers/plan.php') {
                $this->assertDoesNotMatchRegularExpression('/DELETE\s+FROM\s+`?tbl_voucher\b/i', $src, $rel);
            }
        }
    }

    /** §7a / §14: the hardened legacy balance paths never use the legacy Balance writers or reseller finance. */
    public function testHardenedBalancePathsNeverCallBalance(): void
    {
        $svc = self::runtime()['system/autoload/Reseller/Domain/LegacyCustomerBalanceService.php'];
        $this->assertDoesNotMatchRegularExpression('/\bBalance::|Reseller\\\\Finance|LedgerService|tbl_manager_/', $svc);
        $this->assertMatchesRegularExpression('/FOR UPDATE/', $svc, 'balances are read under the row lock');
        $this->assertDoesNotMatchRegularExpression('/\bfloat\b|\(float\)/', $svc, 'no float money');
        $balance = self::runtime()['system/autoload/Balance.php'];
        $this->assertDoesNotMatchRegularExpression('/->save\(\)|->balance\s*=/', $balance, 'no absolute write-back of a read balance');
        $this->assertSame(2, substr_count($balance, "WHERE username = ? ORDER BY id LIMIT 1") + substr_count($balance, 'idByUsername($phone_customer)') - 1,
            'plusByPhone / minByPhone resolve exactly one id by username');
    }

    /** E6: voucher codes come from the CSPRNG. */
    public function testVoucherGenerationUsesTheCsprng(): void
    {
        $plan = self::runtime()['system/controllers/plan.php'];
        $gen = substr($plan, (int) strpos($plan, "case 'voucher-post':"), 6000);
        $this->assertDoesNotMatchRegularExpression('/\brand\(|mt_rand\(|md5\(time\(\)|randomUpLowCase/', $gen);
        $this->assertDoesNotMatchRegularExpression('/\$characters\[rand\(/', self::runtime()['init.php']);
    }
}
