<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP4 source guards: accounting history is never deleted or rewritten (D018, C17); no shell in any RADIUS
 * path (SEC-11); no interpolated SQL in the RADIUS endpoint and classes (SEC-01); no finance writes from
 * RADIUS code (WP3A binding input); the NAS secret is never rendered (SEC-17); the device calls run after the
 * billing row is saved (Q5).
 */
final class RadiusSourceGuardTest extends TestCase
{
    private const RADIUS_DIR = 'system/autoload/Reseller/Radius/';
    private const RADIUS_PATHS = ['radius.php', 'system/devices/Radius.php', 'system/devices/RadiusRest.php', 'system/controllers/radius.php'];

    /** @var array<string,string>|null */
    private static ?array $files = null;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> relative path => source (comments stripped) of every runtime file and root entrypoint */
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
                $out[$rel] = self::code((string) file_get_contents($f->getPathname()));
            }
        }
        foreach (glob($root . '/*.php') as $f) {
            $out[basename($f)] = self::code((string) file_get_contents($f));
        }
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

    /** @return array<string,string> the RADIUS code paths */
    private static function radiusCode(): array
    {
        return array_filter(self::runtime(), static fn(string $rel): bool => str_starts_with($rel, self::RADIUS_DIR) || in_array($rel, self::RADIUS_PATHS, true), ARRAY_FILTER_USE_KEY);
    }

    public function testAccountingHistoryIsNeverDeletedOrRewritten(): void
    {
        $hits = [];
        foreach (self::runtime() as $rel => $src) {
            if (preg_match('/\b(DELETE\s+FROM|UPDATE|TRUNCATE(\s+TABLE)?|REPLACE(\s+INTO)?|INSERT\s+(IGNORE\s+)?INTO)\s+`?radacct\b/i', $src)
                || preg_match("/for_table\\(\\s*['\"]radacct['\"][^;]*->(delete|delete_many|save|set)\\(/i", $src)
                || preg_match('/getTableAcct\(\)[^;]*->(delete|delete_many|save|set)\(/i', $src)) {
                $hits[] = $rel;
            }
        }
        $this->assertSame([], $hits, 'radacct is written by FreeRADIUS only');
    }

    public function testNoShellInAnyRadiusPath(): void
    {
        $this->assertNotEmpty(self::radiusCode());
        foreach (array_keys(self::radiusCode()) as $rel) {
            $tokens = token_get_all((string) file_get_contents(self::root() . '/' . $rel));
            $prev = null;
            foreach ($tokens as $t) {
                if ($t === '`') {
                    $this->fail("{$rel}: backtick shell execution");
                }
                if (is_array($t) && $t[0] === T_STRING && in_array(strtolower($t[1]), ['shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'], true)
                    && !(is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true))) {
                    $this->fail("{$rel}: process execution via {$t[1]}()");
                }
                if (!is_array($t) || $t[0] !== T_WHITESPACE) {
                    $prev = $t;
                }
            }
            $this->assertStringNotContainsStringIgnoringCase('radclient', self::radiusCode()[$rel], "{$rel}: no radclient");
        }
    }

    public function testNoInterpolatedSqlInTheEndpointAndRadiusClasses(): void
    {
        foreach (self::radiusCode() as $rel => $src) {
            $this->assertDoesNotMatchRegularExpression('/(where_?[rR]aw|raw_query|raw_execute|whereRaw)\(\s*"[^"]*\$/', $src, "{$rel}: no variable inside raw SQL");
            $this->assertDoesNotMatchRegularExpression('/(where_?[rR]aw|raw_query|raw_execute|whereRaw)\([^,;)]*\.\s*[\$\'"]/', $src, "{$rel}: no concatenated raw SQL");
        }
        foreach (self::runtime() as $rel => $src) {
            if (str_starts_with($rel, self::RADIUS_DIR)) {
                $this->assertDoesNotMatchRegularExpression('/\$_(POST|GET|REQUEST|COOKIE|SERVER)\b|\b_(post|get|req)\(/', $src, "{$rel}: no request input in the RADIUS classes");
                $this->assertStringContainsString("if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }", $src, "{$rel}: runtime guard");
            }
        }
    }

    public function testRadiusCodeMakesNoFinanceOrBillingWrites(): void
    {
        foreach (self::runtime() as $rel => $src) {
            if (!str_starts_with($rel, self::RADIUS_DIR)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/Reseller\\\\+Finance|\b(TransferService|WalletService|LedgerService|ReservationService|PricingService|FinanceTx)\b/', $src, "{$rel}: no finance code");
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?tbl_/i', $src, "{$rel}: no application-table writes");
            $this->assertDoesNotMatchRegularExpression('/tbl_reseller_|tbl_manager_/', $src, "{$rel}: no reseller-domain tables");
        }
        $this->assertSame([], array_keys(array_filter(self::runtime(), static fn(string $s): bool => (bool) preg_match('/RadiusFaults::\$hook\s*=/', $s))), 'the failure hook is test-only');
    }

    public function testNasSecretIsNeverRendered(): void
    {
        foreach (self::runtime() as $rel => $src) {
            if (str_starts_with($rel, 'ui/') && str_contains($rel, 'radius')) {
                $this->assertDoesNotMatchRegularExpression('/\$d(\[[\'"]secret[\'"]\]|\.secret)/', $src, "{$rel}: the NAS secret is never rendered");
            }
        }
    }

    public function testEndpointGateRunsBeforeAnyAction(): void
    {
        $src = self::runtime()['radius.php'];
        $gate = strpos($src, 'RestGate::refusal(');
        $this->assertNotFalse($gate);
        $this->assertLessThan(strpos($src, 'switch ($action)'), $gate);
        $this->assertLessThan(strpos($src, '$_SERVER[\'HTTP_X_FREERADIUS_SECTION\']'), $gate);
    }

    /** Q5: in both rechargeUser branches the recharge row is saved before the device is called. */
    public function testPackageCallsTheDeviceAfterSavingTheRecharge(): void
    {
        $src = self::runtime()['system/autoload/Package.php'];
        $start = strpos($src, 'public static function rechargeUser(');
        $body = substr($src, $start, (int) strpos($src, 'public static function', $start + 30) - $start);
        $calls = [];
        $offset = 0;
        while (($p = strpos($body, '->add_customer($c, $p)', $offset)) !== false) {
            $calls[] = $p;
            $offset = $p + 1;
        }
        $this->assertCount(2, $calls, 'two device calls (renewal and new activation)');
        $this->assertLessThan($calls[0], strpos($body, '$b->save();'), 'renewal: saved first');
        $this->assertLessThan($calls[1], strpos($body, '$d->save();'), 'activation: saved first');
        $this->assertGreaterThan($calls[0], strpos($body, '$d->save();'), 'the activation save belongs to the second branch');
    }
}
