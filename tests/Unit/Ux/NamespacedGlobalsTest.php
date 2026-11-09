<?php
namespace Reseller\Tests\Unit\Ux;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * V1.0.1: a namespaced runtime class names the global application classes with a leading backslash. "Lang::T" inside
 * Reseller\Security\RouteGuard resolved to Reseller\Security\Lang, so a sign-in with the default credential answered
 * HTTP 500 instead of the redirect to the password change.
 */
final class NamespacedGlobalsTest extends TestCase
{
    private const GLOBALS = ['Lang', 'ORM', 'Text', 'Package', 'User', 'Admin', 'Csrf', 'Message', 'Password', 'App',
        'Paginator', 'File', 'Http', 'Log', 'Widget', 'Timezone', 'Validator', 'Balance', 'Invoice'];

    public function testGlobalClassesAreFullyQualified(): void
    {
        $root = dirname(__DIR__, 3);
        $bad = [];
        foreach (['system/autoload', 'system/reseller'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($f->getPathname());
                if (!preg_match('/^namespace\s/m', $src)) {
                    continue;
                }
                $imported = [];
                preg_match_all('/^use\s+([^;]+);/m', $src, $uses);
                foreach ($uses[1] as $u) {
                    $parts = preg_split('/\s+as\s+/', trim($u));
                    $imported[] = basename(str_replace('\\', '/', end($parts)));
                }
                $siblings = array_map(static fn(string $p): string => basename($p, '.php'), glob(dirname($f->getPathname()) . '/*.php') ?: []);
                foreach (preg_split('/\R/', $src) as $n => $line) {
                    if (preg_match('#^\s*(\*|//|/\*|\#)#', $line)) {
                        continue;
                    }
                    if (preg_match_all('/(?<![\w$>:\\\\])(' . implode('|', self::GLOBALS) . ')::/', $line, $m)) {
                        foreach ($m[1] as $class) {
                            if (!in_array($class, $imported, true) && !in_array($class, $siblings, true)) {
                                $bad[] = substr($f->getPathname(), strlen($root) + 1) . ':' . ($n + 1);
                            }
                        }
                    }
                }
            }
        }
        $this->assertSame([], $bad);
    }
}
