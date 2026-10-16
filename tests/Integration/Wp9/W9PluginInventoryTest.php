<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\RunnerProcess;

/** WP9-B / N-02 (B2): the plugin inventory lists exactly the loaded plugin files and reports every drift. */
final class W9PluginInventoryTest extends TestCase
{
    public function testInventoryAndDrift(): void
    {
        $dir = sys_get_temp_dir() . '/w9plugins_' . bin2hex(random_bytes(4));
        mkdir($dir . '/sub', 0777, true);
        file_put_contents($dir . '/a.php', "<?php\n// a\n");
        file_put_contents($dir . '/b.php', "<?php\n// b\n");
        file_put_contents($dir . '/sub/ignored.php', "<?php\n");
        $cli = dirname(__DIR__, 3) . '/system/reseller/plugins.php';
        try {
            $r = RunnerProcess::exec([PHP_BINARY, $cli, '--dir=' . $dir]);
            $this->assertSame(0, $r['exit']);
            $this->assertSame(hash_file('sha256', $dir . '/a.php') . "  a.php\n" . hash_file('sha256', $dir . '/b.php') . "  b.php\n", $r['raw']);
            file_put_contents($dir . '/inventory.txt', $r['raw']);
            $this->assertSame(0, RunnerProcess::exec([PHP_BINARY, $cli, '--dir=' . $dir, '--expect=' . $dir . '/inventory.txt'])['exit']);
            file_put_contents($dir . '/b.php', "<?php\n// b changed\n");
            file_put_contents($dir . '/c.php', "<?php\n");
            unlink($dir . '/a.php');
            $r = RunnerProcess::exec([PHP_BINARY, $cli, '--dir=' . $dir, '--expect=' . $dir . '/inventory.txt']);
            $this->assertSame(4, $r['exit']);
            $this->assertStringContainsString('MODIFIED b.php', $r['raw']);
            $this->assertStringContainsString('UNKNOWN  c.php', $r['raw']);
            $this->assertStringContainsString('MISSING  a.php', $r['raw']);
        } finally {
            array_map('unlink', array_merge(glob($dir . '/*.*'), glob($dir . '/sub/*')));
            rmdir($dir . '/sub');
            rmdir($dir);
        }
    }
}
