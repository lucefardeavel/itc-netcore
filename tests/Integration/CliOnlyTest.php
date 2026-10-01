<?php
namespace Reseller\Tests\Integration;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\IntegrationTestCase;

/**
 * T21 / owner addition 6: the migration runner and every new PHP file that could touch schema or
 * data refuse non-CLI execution. The repository root is served as the web root (like the
 * production Dockerfile does) WITHOUT any .htaccess firewall.
 */
final class CliOnlyTest extends IntegrationTestCase
{
    private const PATHS = [
        '/system/reseller/migrate.php',
        '/system/reseller/migrate.php?command=migrate',
        '/system/reseller/hierarchy.php',
        '/system/reseller/hierarchy.php?command=plan',
        '/system/reseller/migrations/R0001_manager_tree.php',
        '/system/reseller/migrations/R0005_customer_ownership.php',
        '/system/reseller/Migration/Runner.php',
        '/system/reseller/Migration/Db.php',
        '/system/reseller/Migration/Registry.php',
        '/system/reseller/Migration/variants.php',
        '/tests/bootstrap.php',
        '/tests/Support/FixtureBuilder.php',
        '/tests/Support/TestDatabase.php',
        '/tests/Integration/PreflightTest.php',
        '/dev/tools/build-variant-registry.php',
    ];

    public function testNewPhpFilesRefuseHttpExecution(): void
    {
        $db = $this->newDatabase();
        $root = dirname(__DIR__, 2);
        $port = 18000 + random_int(0, 999);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($proc);
        try {
            $up = false;
            for ($i = 0; $i < 50 && !$up; $i++) {
                usleep(100000);
                $up = @fsockopen('127.0.0.1', $port) !== false;
            }
            $this->assertTrue($up, 'built-in web server started');
            $connectionsBefore = (int) $db->rows("SHOW GLOBAL STATUS LIKE 'Connections'")[0]['Value'];

            // control: the server does serve the repository
            [$code, $body] = $this->get($port, '/version.json');
            $this->assertSame(200, $code);
            $this->assertStringContainsString('version', $body);

            foreach (self::PATHS as $path) {
                [$code, $body] = $this->get($port, $path);
                $this->assertSame(404, $code, "{$path} must refuse HTTP execution");
                $this->assertSame('', $body, "{$path} must produce no output over HTTP");
            }
            $connectionsAfter = (int) $db->rows("SHOW GLOBAL STATUS LIKE 'Connections'")[0]['Value'];
            $this->assertSame($connectionsBefore, $connectionsAfter, 'no database connection was attempted by any HTTP request');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /** Every new PHP file in the WP1 scope starts with the CLI guard. */
    public function testEveryNewPhpFileCarriesTheCliGuard(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/system/reseller/*.php'),
            glob($root . '/system/reseller/*/*.php'),
            glob($root . '/dev/tools/*.php'),
            glob($root . '/tests/*.php'),
            glob($root . '/tests/*/*.php'),
        );
        // tests/vendor is third-party dev tooling: gitignored and never deployed
        $files = array_values(array_filter($files, static fn(string $f): bool => !str_contains($f, '/tests/vendor/')));
        $this->assertGreaterThanOrEqual(25, count($files));
        foreach ($files as $f) {
            $head = implode("\n", array_slice(file($f), 0, 25));
            $this->assertMatchesRegularExpression("/if \\(PHP_SAPI !== 'cli'\\) \\{\\s*http_response_code\\(404\\);\\s*exit;/", $head, "{$f} lacks the CLI guard");
        }
    }

    /** @return array{0:int,1:string} */
    private function get(int $port, string $path): array
    {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return [$status, $body];
    }
}
