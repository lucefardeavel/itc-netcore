<?php
namespace Reseller\Tests\Unit\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP9-C #14 (CoA / command injection): no runtime PHP file of the application starts an operating-system process with
 * request data. Token scan of every runtime file (system/ incl. controllers, devices, autoload, plugins shipped with
 * the release; the root entry points) for exec / shell_exec / system / passthru / proc_open / popen / pcntl_exec calls
 * and the backtick operator. The only allowed call is the constant `which php` probe of the settings page.
 * Third-party libraries (system/vendor, the vendored PEAR2 client, the vendored PHPMailer whose sendmail transport
 * escapes its arguments itself) are out of scope.
 */
final class W9NoShellSourceTest extends TestCase
{
    private const FUNCTIONS = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];
    private const ALLOWED = ["system/controllers/settings.php: shell_exec('which php')"];

    public function testNoRuntimeFileStartsAProcess(): void
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(glob($root . '/*.php'), $this->walk($root . '/system'));
        $this->assertGreaterThan(150, count($files));
        $found = [];
        foreach ($files as $f) {
            $rel = substr($f, strlen($root) + 1);
            $tokens = token_get_all((string) file_get_contents($f));
            foreach ($tokens as $i => $t) {
                if ($t === '`') {
                    $found[] = "{$rel}: backtick operator";
                    continue;
                }
                if (!is_array($t) || $t[0] !== T_STRING || !in_array(strtolower($t[1]), self::FUNCTIONS, true)) {
                    continue;
                }
                $prev = $this->prevSignificant($tokens, $i);
                $next = $this->nextSignificant($tokens, $i);
                if ($next !== '(' || in_array($prev, ['->', '?->', '::', 'function', 'new', 'const'], true)) {
                    continue; // a method / declaration, not the global function
                }
                $call = $t[1] . '(';
                for ($j = $i + 2, $depth = 1; $j < count($tokens) && $depth > 0; $j++) {
                    $s = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                    $depth += $s === '(' ? 1 : ($s === ')' ? -1 : 0);
                    $call .= $s;
                }
                $found[] = "{$rel}: {$call}";
            }
        }
        $this->assertSame(self::ALLOWED, $found);
    }

    /** @return list<string> */
    private function walk(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (str_ends_with($p, '.php') && !str_contains($p, '/system/vendor/') && !str_contains($p, '/system/autoload/PEAR2/') && !str_contains($p, '/system/autoload/mail/') && !str_contains($p, '/system/reseller/')) {
                $out[] = $p;
            }
        }
        sort($out);
        return $out;
    }

    private function prevSignificant(array $t, int $i): string
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($t[$j]) && in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($t[$j]) ? strtolower($t[$j][1]) : $t[$j];
        }
        return '';
    }

    private function nextSignificant(array $t, int $i): string
    {
        for ($j = $i + 1; $j < count($t); $j++) {
            if (is_array($t[$j]) && in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($t[$j]) ? $t[$j][1] : $t[$j];
        }
        return '';
    }
}
