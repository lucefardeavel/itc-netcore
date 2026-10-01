<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Runs the real CLI runner as a separate process (real exit codes, real argument parsing). */
final class RunnerProcess
{
    /**
     * @param array<string,string> $env extra environment (e.g. failure-injection hooks)
     * @return array{exit:int,report:array<string,mixed>,raw:string}
     */
    public static function run(TestDatabase $db, string $command, array $args = [], array $env = [], ?string $runnerPath = null): array
    {
        $runner = $runnerPath ?? dirname(__DIR__, 2) . '/system/reseller/migrate.php';
        $cmd = array_merge(
            [PHP_BINARY, $runner, $command, '--json', '--db-host=' . $db->host, '--db-name=' . $db->name, '--db-user=root', '--db-password-env=WP1_DB_ROOT_PASSWORD'],
            $args
        );
        return self::exec($cmd, $env);
    }

    /**
     * @param list<string> $cmd
     * @param array<string,string> $env
     * @return array{exit:int,report:array<string,mixed>,raw:string}
     */
    public static function exec(array $cmd, array $env = []): array
    {
        $fullEnv = array_merge(getenv(), $env);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $fullEnv);
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot start runner process');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $report = json_decode((string) $out, true);
        return ['exit' => $exit, 'report' => is_array($report) ? $report : [], 'raw' => $out . $err];
    }
}
