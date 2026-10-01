<?php
/**
 * Project migration runner for the reseller domain (WP1, owner decision C13).
 *
 * CLI ONLY. The repository root is the web root and the legacy .htaccess firewall is not active
 * by default, so this file refuses any non-CLI execution before doing anything else.
 *
 * Usage:
 *   php system/reseller/migrate.php <status|preflight|migrate|verify|rollback> [options]
 *
 * Options:
 *   --config=<path>            legacy config.php to read $db_host/$db_name/$db_user/$db_pass from
 *                              (default: <repo>/config.php)
 *   --db-host= --db-name= --db-user=  explicit connection (overrides config.php values)
 *   --db-password-env=<VAR>    read the password from this environment variable
 *   --to=<migration id>        rollback: keep migrations up to and including this id
 *   --all                      rollback: roll back every project migration
 *   --json                     machine-readable report on stdout
 *
 * Exit codes: 0 OK, 2 NOT_READY, 3 FAILED, 4 DRIFT, 5 USAGE (see system/reseller/README.md).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/Migration/Outcome.php';
require_once __DIR__ . '/Migration/Db.php';
require_once __DIR__ . '/Migration/SchemaInspector.php';
require_once __DIR__ . '/Migration/Grants.php';
require_once __DIR__ . '/Migration/MigrationDefinition.php';
require_once __DIR__ . '/Migration/MigrationSet.php';
require_once __DIR__ . '/Migration/VariantRegistry.php';
require_once __DIR__ . '/Migration/Registry.php';
require_once __DIR__ . '/Migration/Runner.php';

use Reseller\Migration\Db;
use Reseller\Migration\MigrationSet;
use Reseller\Migration\Outcome;
use Reseller\Migration\Runner;
use Reseller\Migration\StopException;
use Reseller\Migration\VariantRegistry;

/** @return array{0:string,1:array<string,mixed>} */
function nuxr_parse_args(array $argv): array
{
    $command = '';
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $eq = strpos($arg, '=');
            if ($eq === false) {
                $opts[substr($arg, 2)] = true;
            } else {
                $opts[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
            }
        } elseif ($command === '') {
            $command = $arg;
        } else {
            throw new StopException(Outcome::USAGE, "unexpected argument '{$arg}'");
        }
    }
    $known = ['config', 'db-host', 'db-name', 'db-user', 'db-password-env', 'to', 'all', 'json'];
    foreach (array_keys($opts) as $k) {
        if (!in_array($k, $known, true)) {
            throw new StopException(Outcome::USAGE, "unknown option --{$k}");
        }
    }
    return [$command, $opts];
}

/** Reads the legacy config.php in isolation (no init.php, no plugins, no session). */
function nuxr_read_config(string $path): array
{
    if (!is_file($path)) {
        throw new StopException(Outcome::USAGE, "config file not found: {$path}");
    }
    $_SERVER += ['HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/index.php', 'SERVER_PORT' => 80];
    $vars = (static function (string $__file): array {
        ob_start();
        include $__file;
        ob_end_clean();
        if (isset($db_password) && (!isset($db_pass) || $db_pass === '')) {
            $db_pass = $db_password; // legacy compatibility, mirrors update.php
        }
        return [
            'host' => $db_host ?? null,
            'name' => $db_name ?? null,
            'user' => $db_user ?? null,
            'pass' => $db_pass ?? null,
        ];
    })($path);
    return $vars;
}

function nuxr_executed_by(): string
{
    $user = getenv('USER') ?: (function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : 'unknown');
    return $user . '@' . (gethostname() ?: 'unknown');
}

$json = in_array('--json', $argv, true);
$exit = Outcome::USAGE;
$report = ['problems' => []];
try {
    [$command, $opts] = nuxr_parse_args($argv);
    if ($command === '') {
        throw new StopException(Outcome::USAGE, 'missing command (status|preflight|migrate|verify|rollback)');
    }
    $cfg = ['host' => null, 'name' => null, 'user' => null, 'pass' => null];
    $configPath = $opts['config'] ?? dirname(__DIR__, 2) . '/config.php';
    if (isset($opts['config']) || !isset($opts['db-host'])) {
        $cfg = nuxr_read_config((string) $configPath);
    }
    $host = $opts['db-host'] ?? $cfg['host'];
    $name = $opts['db-name'] ?? $cfg['name'];
    $user = $opts['db-user'] ?? $cfg['user'];
    $pass = isset($opts['db-password-env']) ? getenv((string) $opts['db-password-env']) : $cfg['pass'];
    if ($host === null || $name === null || $user === null || $pass === false || $pass === null) {
        throw new StopException(Outcome::USAGE, 'incomplete database configuration');
    }
    $db = new Db((string) $host, (string) $name, (string) $user, (string) $pass);
    $set = MigrationSet::fromDirectory(__DIR__ . '/migrations');
    $variants = VariantRegistry::load(__DIR__ . '/Migration/variants.php');
    $whoDb = (string) $db->value('SELECT CURRENT_USER()');
    $runner = new Runner($db, $set, $variants, nuxr_executed_by() . ' as ' . $whoDb);
    $exit = $runner->run($command, $opts);
    $report = $runner->report();
} catch (StopException $e) {
    $exit = $e->outcome;
    $report['problems'][] = $e->getMessage();
    foreach ($e->details as $d) {
        $report['problems'][] = $d;
    }
} catch (\PDOException $e) {
    $exit = Outcome::NOT_READY;
    $report['problems'][] = 'database error: ' . Runner::sanitize($e->getMessage());
} catch (\Throwable $e) {
    $exit = Outcome::FAILED;
    $report['problems'][] = 'unexpected error: ' . get_class($e) . ': ' . Runner::sanitize($e->getMessage());
}
$report['outcome'] = $exit;
$report['outcome_name'] = Outcome::name($exit);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
} else {
    echo 'result: ', $report['outcome_name'], " (exit {$exit})\n";
    foreach (['database', 'host', 'engine', 'server_version', 'variant', 'core_fingerprint'] as $k) {
        if (isset($report[$k])) {
            echo "{$k}: {$report[$k]}\n";
        }
    }
    foreach ($report['migrations'] ?? [] as $m) {
        echo sprintf("  %-34s %-18s steps %d/%d\n", $m['id'], $m['status'], $m['steps_completed'], $m['steps_total']);
    }
    if (!empty($report['extra_tables'])) {
        echo 'extra tables (not part of the core contract, untouched): ', implode(', ', $report['extra_tables']), "\n";
    }
    foreach ($report['messages'] ?? [] as $m) {
        echo "info: {$m}\n";
    }
    foreach ($report['problems'] ?? [] as $p) {
        echo "problem: {$p}\n";
    }
    foreach ($report['drift'] ?? [] as $d) {
        echo "drift: {$d}\n";
    }
}
exit($exit);
