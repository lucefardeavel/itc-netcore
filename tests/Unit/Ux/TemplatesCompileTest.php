<?php
namespace Reseller\Tests\Unit\Ux;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * Every interface template compiles (lab finding 2026-11-09: an apostrophe inside a single-quoted Lang::T() string broke
 * the voucher form with a 500). Compilation only: nothing is rendered or executed.
 */
final class TemplatesCompileTest extends TestCase
{
    public function testEveryTemplateCompiles(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/system/vendor/autoload.php';
        $dir = sys_get_temp_dir() . '/nuxr_tpl_' . getmypid();
        @mkdir($dir);
        $smarty = new \Smarty();
        $smarty->setTemplateDir($root . '/ui/ui');
        $smarty->setCompileDir($dir);
        // the runtime registration of system/boot.php (compilation only needs the name)
        $smarty->registerPlugin('function', 'nuxr_page', static fn(): string => '');
        $failed = [];
        $count = 0;
        $old = error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/ui/ui', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!str_ends_with($f->getFilename(), '.tpl')) {
                continue;
            }
            $rel = substr($f->getPathname(), strlen($root . '/ui/ui/'));
            $count++;
            try {
                $smarty->createTemplate($rel)->compileTemplateSource();
            } catch (\Throwable $e) {
                $failed[] = $rel . ': ' . substr($e->getMessage(), 0, 160);
            }
        }
        error_reporting($old);
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
        $this->assertGreaterThan(100, $count);
        $this->assertSame([], $failed, 'templates that do not compile');
    }
}
