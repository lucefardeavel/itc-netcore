<?php
/**
 * Reseller hierarchy inspection / validation / dry-run planning tool (WP2 §12, owner decision C20).
 *
 * CLI ONLY. READ ONLY: every command runs inside START TRANSACTION READ ONLY and only reads. There is
 * NO apply in WP2: importing the legacy tbl_users.root field into the hierarchy requires an explicit
 * owner authorization; a future apply would replay a reviewed plan (plan_sha256) through
 * ResellerTreeService::placeExisting(). created_by is never used; no customer ownership is derived.
 *
 * Usage:
 *   php system/reseller/hierarchy.php <inspect|validate|plan|apply> [options]
 *
 * Options (same connection options as migrate.php):
 *   --config=<path>  --db-host= --db-name= --db-user=  --db-password-env=<VAR>  --json
 *
 * Exit codes: 0 OK, 2 findings (validate/plan found invalid nodes), 3 failed, 5 usage / refused.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/Migration/Outcome.php';
require_once __DIR__ . '/Migration/Db.php';
if (!defined('NUXR_RUNTIME')) {
    define('NUXR_RUNTIME', true);
}
require_once dirname(__DIR__) . '/autoload/Reseller/Domain/DomainError.php';
require_once dirname(__DIR__) . '/autoload/Reseller/Domain/Tx.php';
require_once dirname(__DIR__) . '/autoload/Reseller/Domain/ResellerTreeService.php';

use Reseller\Domain\ResellerTreeService;
use Reseller\Migration\Db;

const NUXR_H_OK = 0;
const NUXR_H_FINDINGS = 2;
const NUXR_H_FAILED = 3;
const NUXR_H_USAGE = 5;
const NUXR_H_FORMAT = 'nuxr-hierarchy-plan/1';

final class NuxrHierarchyUsage extends \RuntimeException
{
}

/** @return array{0:string,1:array<string,mixed>} */
function nuxr_h_args(array $argv): array
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
            throw new NuxrHierarchyUsage("unexpected argument '{$arg}'");
        }
    }
    foreach (array_keys($opts) as $k) {
        if (!in_array($k, ['config', 'db-host', 'db-name', 'db-user', 'db-password-env', 'json'], true)) {
            throw new NuxrHierarchyUsage("unknown option --{$k}");
        }
    }
    return [$command, $opts];
}

function nuxr_h_config(string $path): array
{
    if (!is_file($path)) {
        throw new NuxrHierarchyUsage("config file not found: {$path}");
    }
    $_SERVER += ['HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/index.php', 'SERVER_PORT' => 80];
    return (static function (string $__file): array {
        ob_start();
        include $__file;
        ob_end_clean();
        if (isset($db_password) && (!isset($db_pass) || $db_pass === '')) {
            $db_pass = $db_password;
        }
        return ['host' => $db_host ?? null, 'name' => $db_name ?? null, 'user' => $db_user ?? null, 'pass' => $db_pass ?? null];
    })($path);
}

/** @return array<int,array{id:int,username:string,role:string,status:string,root:int}> */
function nuxr_h_users(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, username, user_type, status, root FROM tbl_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['id']] = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['user_type'], 'status' => (string) $r['status'], 'root' => (int) $r['root']];
    }
    return $out;
}

function nuxr_h_has_table(PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (int) $st->fetchColumn() === 1;
}

/** @return array<int,int|null> enrolled user id => closure parent (null = top level) */
function nuxr_h_enrolled(PDO $pdo): array
{
    if (!nuxr_h_has_table($pdo, 'tbl_manager_tree')) {
        return [];
    }
    $out = [];
    foreach ($pdo->query('SELECT descendant_id FROM tbl_manager_tree WHERE depth = 0')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $out[(int) $id] = null;
    }
    foreach ($pdo->query('SELECT ancestor_id, descendant_id FROM tbl_manager_tree WHERE depth = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['descendant_id']] = (int) $r['ancestor_id'];
    }
    return $out;
}

/**
 * Validation of the legacy root field as a potential (never automatic) import source.
 *
 * @return list<array{code:string,severity:string,user_id:int,detail:string}>
 */
function nuxr_h_validate(array $users, array $enrolled): array
{
    $f = [];
    $add = static function (string $code, string $sev, int $id, string $detail) use (&$f): void {
        $f[] = ['code' => $code, 'severity' => $sev, 'user_id' => $id, 'detail' => $detail];
    };
    $childrenOf = [];
    foreach ($users as $u) {
        if ($u['root'] !== 0) {
            $childrenOf[$u['root']][] = $u['id'];
        }
    }
    $reportedCycle = [];
    foreach ($users as $u) {
        $id = $u['id'];
        $root = $u['root'];
        $tree = in_array($u['role'], ['Agent', 'Sales'], true);
        if (!$tree) {
            if ($root !== 0) {
                $add('NON_TREE_ROLE_WITH_PARENT', 'warning', $id, $u['role'] . ' with root ' . $root . ' (ignored)');
            }
            continue;
        }
        if ($root === $id) {
            $add('SELF_PARENT', 'error', $id, 'root points to itself');
            continue;
        }
        if ($root !== 0 && !isset($users[$root])) {
            $add('MISSING_PARENT', 'error', $id, 'root ' . $root . ' does not exist');
            continue;
        }
        // cycle detection along root pointers (full path)
        $path = [$id];
        $seen = [$id => true];
        $cur = $root;
        while ($cur !== 0 && isset($users[$cur])) {
            if (isset($seen[$cur])) {
                $cycle = array_slice($path, (int) array_search($cur, $path, true));
                $key = min($cycle);
                if (!isset($reportedCycle[$key])) {
                    $reportedCycle[$key] = true;
                    $add('CYCLE', 'error', $key, 'cycle ' . implode(' -> ', array_merge($cycle, [$cur])));
                }
                break;
            }
            $seen[$cur] = true;
            $path[] = $cur;
            $cur = $users[$cur]['root'];
            if ($cur === $path[count($path) - 1]) {
                break; // self parent further up, reported for that node
            }
        }
        if ($root !== 0 && $users[$root]['role'] !== 'Agent') {
            $add('PARENT_NOT_AGENT', 'error', $id, 'parent ' . $root . ' is ' . $users[$root]['role']);
        }
        if ($u['role'] === 'Sales' && ($root === 0 || ($users[$root]['role'] ?? '') !== 'Agent')) {
            $add('SALES_WITHOUT_AGENT_PARENT', 'error', $id, 'Sales needs an Agent parent');
        }
        if ($u['role'] === 'Sales' && !empty($childrenOf[$id])) {
            $add('SALES_WITH_CHILDREN', 'error', $id, 'children ' . implode(',', $childrenOf[$id]));
        }
        if ($root !== 0 && isset($users[$root]) && $users[$root]['status'] !== 'Active') {
            $add('PARENT_INACTIVE', 'warning', $id, 'parent ' . $root . ' is ' . $users[$root]['status']);
        }
        if (array_key_exists($id, $enrolled) && ($enrolled[$id] ?? 0) !== $root) {
            $add('ENROLLED_CONFLICT', 'warning', $id, 'enrolled under ' . ($enrolled[$id] ?? 0) . ' but legacy root is ' . $root);
        }
    }
    usort($f, static fn($a, $b) => [$a['user_id'], $a['code']] <=> [$b['user_id'], $b['code']]);
    return $f;
}

/**
 * Deterministic dry-run rebuild proposal: topologically ordered place(user, parent) operations for
 * not-yet-enrolled valid nodes, parents first, ties by id. Invalid nodes and their descendants are
 * excluded with reasons.
 *
 * @return array<string,mixed>
 */
function nuxr_h_plan(array $users, array $enrolled, array $findings): array
{
    $invalid = [];
    foreach ($findings as $f) {
        if ($f['severity'] === 'error') {
            $invalid[$f['user_id']][] = $f['code'];
        }
    }
    // a cycle finding names one member; exclude every member of it
    foreach ($findings as $f) {
        if ($f['code'] === 'CYCLE' && preg_match_all('/\d+/', $f['detail'], $m)) {
            foreach ($m[0] as $id) {
                $invalid[(int) $id][] = 'CYCLE';
            }
        }
    }
    $isValid = function (int $id) use (&$isValid, $users, $invalid): bool {
        static $memo = [];
        if (isset($memo[$id])) {
            return $memo[$id];
        }
        $memo[$id] = false; // guards cycles
        $u = $users[$id] ?? null;
        if ($u === null || !in_array($u['role'], ['Agent', 'Sales'], true) || isset($invalid[$id])) {
            return $memo[$id] = false;
        }
        return $memo[$id] = ($u['root'] === 0 ? true : $isValid($u['root']));
    };
    $ops = [];
    $excluded = [];
    $depthOf = function (int $id) use (&$depthOf, $users): int {
        $d = 0;
        $cur = $users[$id]['root'];
        while ($cur !== 0 && isset($users[$cur]) && $d < 1000) {
            $d++;
            $cur = $users[$cur]['root'];
        }
        return $d;
    };
    foreach ($users as $u) {
        if (!in_array($u['role'], ['Agent', 'Sales'], true)) {
            continue;
        }
        if (!$isValid($u['id'])) {
            $reasons = $invalid[$u['id']] ?? ['INVALID_ANCESTOR'];
            $excluded[] = ['user_id' => $u['id'], 'username' => $u['username'], 'reasons' => array_values(array_unique($reasons))];
            continue;
        }
        if (array_key_exists($u['id'], $enrolled)) {
            continue; // already enrolled: never re-placed by a plan
        }
        $ops[] = ['op' => 'place', 'user_id' => $u['id'], 'username' => $u['username'], 'role' => $u['role'],
            'parent_id' => $u['root'] === 0 ? null : $u['root'], 'depth' => $depthOf($u['id'])];
    }
    usort($ops, static fn($a, $b) => [$a['depth'], $a['user_id']] <=> [$b['depth'], $b['user_id']]);
    usort($excluded, static fn($a, $b) => $a['user_id'] <=> $b['user_id']);
    $tuples = array_map(static fn($u) => [$u['id'], $u['role'], $u['status'], $u['root']], array_values($users));
    $fingerprint = hash('sha256', json_encode($tuples));
    $body = ['format' => NUXR_H_FORMAT, 'source_fingerprint' => $fingerprint, 'operations' => $ops, 'excluded' => $excluded];
    $body['plan_sha256'] = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $body;
}

$json = in_array('--json', $argv, true);
$exit = NUXR_H_USAGE;
$report = ['problems' => []];
$pdo = null;
try {
    [$command, $opts] = nuxr_h_args($argv);
    if (!in_array($command, ['inspect', 'validate', 'plan', 'apply'], true)) {
        throw new NuxrHierarchyUsage('command must be one of inspect|validate|plan|apply');
    }
    $report['command'] = $command;
    if ($command === 'apply') {
        // never implemented in WP2: no import / apply without explicit owner authorization (C20)
        throw new NuxrHierarchyUsage('apply is not available: hierarchy import requires explicit owner authorization (C20)');
    }
    $cfg = ['host' => null, 'name' => null, 'user' => null, 'pass' => null];
    if (isset($opts['config']) || !isset($opts['db-host'])) {
        $cfg = nuxr_h_config((string) ($opts['config'] ?? dirname(__DIR__, 2) . '/config.php'));
    }
    $host = $opts['db-host'] ?? $cfg['host'];
    $name = $opts['db-name'] ?? $cfg['name'];
    $user = $opts['db-user'] ?? $cfg['user'];
    $pass = isset($opts['db-password-env']) ? getenv((string) $opts['db-password-env']) : $cfg['pass'];
    if ($host === null || $name === null || $user === null || $pass === false || $pass === null) {
        throw new NuxrHierarchyUsage('incomplete database configuration');
    }
    $db = new Db((string) $host, (string) $name, (string) $user, (string) $pass);
    $pdo = $db->pdo;
    $pdo->exec('START TRANSACTION READ ONLY');
    $users = nuxr_h_users($pdo);
    $enrolled = nuxr_h_enrolled($pdo);
    $findings = nuxr_h_validate($users, $enrolled);
    $errors = array_values(array_filter($findings, static fn($f) => $f['severity'] === 'error'));
    $report['database'] = $db->database;
    switch ($command) {
        case 'inspect':
            $byRole = [];
            $withRoot = 0;
            foreach ($users as $u) {
                $byRole[$u['role']][$u['status']] = ($byRole[$u['role']][$u['status']] ?? 0) + 1;
                $withRoot += $u['root'] !== 0 ? 1 : 0;
            }
            ksort($byRole);
            $report['users_by_role_status'] = $byRole;
            $report['users_with_legacy_root'] = $withRoot;
            $report['enrolled'] = count($enrolled);
            $report['tree_rows'] = nuxr_h_has_table($pdo, 'tbl_manager_tree') ? (int) $pdo->query('SELECT COUNT(*) FROM tbl_manager_tree')->fetchColumn() : null;
            $hasOwner = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_customers' AND COLUMN_NAME = 'manager_id'")->fetchColumn() === 1;
            $report['customers_total'] = (int) $pdo->query('SELECT COUNT(*) FROM tbl_customers')->fetchColumn();
            $report['customers_with_owner'] = $hasOwner ? (int) $pdo->query('SELECT COUNT(*) FROM tbl_customers WHERE manager_id IS NOT NULL')->fetchColumn() : null;
            $report['tree_drift'] = nuxr_h_has_table($pdo, 'tbl_manager_tree') ? ResellerTreeService::verify($pdo) : [];
            $exit = NUXR_H_OK;
            break;
        case 'validate':
            $report['findings'] = $findings;
            $report['errors'] = count($errors);
            $exit = $errors === [] ? NUXR_H_OK : NUXR_H_FINDINGS;
            break;
        case 'plan':
            $report['plan'] = nuxr_h_plan($users, $enrolled, $findings);
            $report['findings'] = $findings;
            $exit = $report['plan']['excluded'] === [] ? NUXR_H_OK : NUXR_H_FINDINGS;
            break;
    }
} catch (NuxrHierarchyUsage $e) {
    $exit = NUXR_H_USAGE;
    $report['problems'][] = $e->getMessage();
} catch (\Throwable $e) {
    $exit = NUXR_H_FAILED;
    $report['problems'][] = 'unexpected error: ' . get_class($e);
} finally {
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    } elseif ($pdo !== null) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (\Throwable) {
        }
    }
}
$report['exit'] = $exit;
if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
} else {
    echo 'result: exit ', $exit, "\n";
    foreach ($report['problems'] as $p) {
        echo "problem: {$p}\n";
    }
    foreach ($report['findings'] ?? [] as $f) {
        echo sprintf("%-7s %-28s user %-6d %s\n", $f['severity'], $f['code'], $f['user_id'], $f['detail']);
    }
    if (isset($report['plan'])) {
        foreach ($report['plan']['operations'] as $o) {
            echo sprintf("place %-6d %-20s under %s\n", $o['user_id'], $o['username'], $o['parent_id'] ?? 'TOP');
        }
        echo 'plan_sha256: ', $report['plan']['plan_sha256'], "\n";
    }
    if (isset($report['users_by_role_status'])) {
        echo json_encode(array_diff_key($report, ['problems' => 1]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }
}
exit($exit);
