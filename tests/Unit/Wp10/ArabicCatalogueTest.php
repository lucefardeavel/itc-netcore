<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * Arabic-first interface (owner, 2026-10-18): every literal string the application passes to Lang::T - in every template and
 * in the application PHP (third-party vendor code, plugins and payment gateways excluded) - has an entry in
 * system/lan/arabic.json, so no screen of the Arabic interface falls back to English. A new string without its Arabic
 * entry fails here.
 */
final class ArabicCatalogueTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> literal => file */
    private static function literals(): array
    {
        $root = self::root();
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/ui/ui', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), '.tpl')) {
                $files[] = $f->getPathname();
            }
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/system', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (str_ends_with($p, '.php') && !preg_match('#/system/(vendor|plugin|paymentgateway)/#', $p)) {
                $files[] = $p;
            }
        }
        $out = [];
        foreach ($files as $p) {
            preg_match_all('/Lang::T\(\s*([\'"])(.*?)(?<!\\\\)\1\s*\)/s', (string) file_get_contents($p), $m);
            foreach ($m[2] as $s) {
                $s = (string) preg_replace('/\s+/', ' ', str_replace("\\'", "'", $s));
                if (str_contains($s, '$') || !preg_match('/[A-Za-z]/', $s)) {
                    continue; // a variable or a non-text argument
                }
                $out[$s] ??= substr($p, strlen($root) + 1);
            }
        }
        return $out;
    }

    public function testEveryInterfaceStringHasAnArabicEntry(): void
    {
        $raw = (string) file_get_contents(self::root() . '/system/lan/arabic.json');
        $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $literals = self::literals();
        $this->assertGreaterThan(1500, count($literals), 'the scan finds the interface strings');
        $missing = [];
        foreach ($literals as $s => $file) {
            if (!isset($catalog[$s]) && !isset($catalog[(string) preg_replace('/[^A-Za-z0-9]/', '_', $s)])) {
                $missing[] = "{$file}: {$s}";
            }
        }
        $this->assertSame([], $missing, 'strings without an Arabic entry in system/lan/arabic.json');
    }

    public function testTheCatalogueIsWellFormed(): void
    {
        $raw = (string) file_get_contents(self::root() . '/system/lan/arabic.json');
        $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/m', $raw, $keys);
        $this->assertSame(count($keys[1]), count(array_unique($keys[1])), 'no key is defined twice (a duplicate silently overrides)');
        foreach ($catalog as $k => $v) {
            $this->assertIsString($v, $k);
            $this->assertNotSame('', trim($v), "{$k} has an empty translation");
        }
    }

    public function testActivationNoticesShowTheTranslatedState(): void
    {
        $src = (string) file_get_contents(self::root() . '/system/controllers/activation.php');
        $this->assertDoesNotMatchRegularExpression("/': ' \\. \\$(out|row)\\['status'\\]/", $src, 'a raw state code (COMPLETED, FAILED ...) is never shown to the user');
        $this->assertGreaterThanOrEqual(4, substr_count($src, "Lang::T(\\Reseller\\Ui\\Format::stateLabel("));
    }
}
