<?php
namespace Reseller\Tests\Unit\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP6B template guards: every variable in the WP6B templates is escaped (only the CSRF token and the application URL
 * are output raw), actions use dialogs / typed confirmations instead of window.confirm, and every WP6B user-facing
 * string has an Arabic catalog entry (owner decision: no English fallback on the WP6B screens; SEC-25 unchanged).
 */
final class Wp6bTemplateGuardTest extends TestCase
{
    private const SAFE = '/^\$(csrf_token|app_url)$/';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> the WP6B templates (relative paths) */
    private static function templates(): array
    {
        $out = [];
        foreach (['finance', 'pricing', 'operations'] as $dir) {
            foreach (glob(self::root() . '/ui/ui/admin/' . $dir . '/*.tpl') ?: [] as $f) {
                $out[] = substr($f, strlen(self::root()) + 1);
            }
        }
        return array_merge($out, ['ui/ui/admin/reseller/detail.tpl', 'ui/ui/admin/reseller/tree.tpl']);
    }

    public function testEveryVariableIsEscaped(): void
    {
        $bad = [];
        $this->assertGreaterThan(20, count(self::templates()));
        foreach (self::templates() as $rel) {
            $src = (string) preg_replace('/\{literal\}.*?\{\/literal\}/s', '', (string) file_get_contents(self::root() . '/' . $rel));
            preg_match_all('/\{(\$[^}\s|]+)((?:\|[^}]*)?)\}/', $src, $m, PREG_SET_ORDER);
            foreach ($m as [$all, $var, $mods]) {
                if (!str_contains($mods, 'escape') && !preg_match(self::SAFE, $var)) {
                    $bad[] = "{$rel}: {$all}";
                }
            }
            // dynamic text through a helper is escaped as well
            preg_match_all('/\{(?:NuxrUi|Lang)::\w+\((?!\'[^\']*\'\))[^}]*\}/', $src, $calls);
            foreach ($calls[0] as $call) {
                if (!str_contains($call, '|escape') && !preg_match('/^\{Lang::T\(\'/', $call)) {
                    $bad[] = "{$rel}: {$call}";
                }
            }
            $this->assertDoesNotMatchRegularExpression('/onclick="return (ask|confirm)\(/', $src, "{$rel}: no window.confirm for actions");
        }
        $this->assertSame([], $bad, 'unescaped template output');
    }

    /** @return array<string,string> WP6B source text => file */
    private static function strings(): array
    {
        $root = self::root();
        $files = array_merge(self::templates(), array_map(static fn(string $f): string => substr($f, strlen($root) + 1), array_merge(
            glob($root . '/ui/ui/admin/nuxr/*.tpl') ?: [],
            glob($root . '/system/autoload/Reseller/Backoffice/*.php') ?: [],
            [$root . '/ui/ui/admin/activation/view.tpl', $root . '/ui/ui/admin/dashboard.tpl', $root . '/system/autoload/Reseller/Ui/Navigation.php',
                $root . '/system/autoload/Reseller/Ui/Format.php', $root . '/system/controllers/finance.php', $root . '/system/controllers/pricing.php',
                $root . '/system/controllers/operations.php', $root . '/system/controllers/reseller.php'],
        )));
        $out = [];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root . '/' . $rel);
            preg_match_all("/Lang::T\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", $src, $m);
            foreach ($m[1] as $s) {
                $out[stripslashes($s)] = $rel;
            }
            if (str_ends_with($rel, '.tpl')) {
                preg_match_all('/\{foreach \[(.*?)\] as/s', $src, $arr);
                foreach ($arr[1] as $a) {
                    preg_match_all("/=> '([^']+)'/", $a, $v);
                    foreach ($v[1] as $s) {
                        $out[$s] = $rel;
                    }
                }
            } else {
                preg_match_all("/'(?:label|blocked)' => (?:[^,\\]]*\\? )?'([A-Z][^']+)'/", $src, $v);
                foreach ($v[1] as $s) {
                    $out[$s] = $rel;
                }
                if (str_ends_with($rel, 'Navigation.php')) {
                    preg_match_all("/\\['[a-z\\/\\-_]+', '([^']+)', 'fa-/", $src, $v);
                    foreach ($v[1] as $s) {
                        $out[$s] = $rel;
                    }
                }
            }
        }
        // ledger / transfer type labels and the WP6B refusal messages (read as source text: no runtime class loaded)
        $format = (string) file_get_contents($root . '/system/autoload/Reseller/Ui/Format.php');
        preg_match_all('/public const (?:LEDGER|TRANSFER)_TYPE = \[(.*?)\];/s', $format, $blocks);
        foreach ($blocks[1] as $b) {
            preg_match_all("/=> '([^']+)'/", $b, $v);
            foreach ($v[1] as $s) {
                $out[$s] = 'Ui/Format.php';
            }
        }
        $errors = (string) file_get_contents($root . '/system/autoload/Reseller/Domain/DomainError.php');
        foreach (['EXPORT_RANGE_REQUIRED', 'EXPORT_TOO_LARGE', 'REVIEW_EXPIRED', 'CONFIRMATION_MISMATCH'] as $code) {
            preg_match("/'{$code}' => '([^']+)'/", $errors, $v);
            $out[$v[1] ?? $code] = 'Domain/DomainError.php';
        }
        return $out;
    }

    public function testEveryWp6bStringHasAnArabicEntry(): void
    {
        $catalog = json_decode((string) file_get_contents(self::root() . '/system/lan/arabic.json'), true, 512, JSON_THROW_ON_ERROR);
        $strings = self::strings();
        $this->assertGreaterThan(300, count($strings));
        $missing = [];
        foreach ($strings as $s => $file) {
            $s = (string) preg_replace('/\s+/', ' ', $s);
            $key = (string) preg_replace('/[^A-Za-z0-9]/', '_', $s);
            if (!isset($catalog[$s]) && !isset($catalog[$key])) {
                $missing[] = "{$file}: {$s}";
            }
        }
        $this->assertSame([], $missing, 'WP6B strings without an Arabic entry');
    }
}
