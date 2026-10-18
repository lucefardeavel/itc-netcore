<?php
// CLI ONLY: refused before anything else
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * V1 data / time quota enforcer (owner QUOTA_V1_GB_SUPPORT_APPROVED, 2026-10-17). CLI only.
 *
 *   php system/reseller/quota.php enforce [--loop=55] [--every=15] [--json]   cron: every minute
 *   php system/reseller/quota.php report [--json]                             read-only: over-quota open sessions
 *   php system/reseller/quota.php usage USERNAME [--json]                     read-only: actual / billable / overshoot (Option G)
 *
 * The NAS already limits every session to its share of the remaining quota (FreeRADIUS replies; see
 * install/freeradius/README.md "V1 data quota"). This enforcer ends, from accounting, the sessions of subscribers
 * whose current-period usage reached the quota anyway (PPPoE upload + download together; several concurrent Hotspot
 * sessions): Disconnect-Request + confirmed Accounting-Stop (Reseller\Radius\SessionTermination), one audit record
 * ('quota.enforced') per subscriber and pass. Only one enforcer runs at a time (database lock). Plugins are not loaded.
 * Nothing is deleted. Exit: 0 done (or another enforcer is running), 3 the RADIUS database is unavailable, 5 usage.
 */

define('NUXR_DEFER_PLUGINS', true);
require dirname(__DIR__, 2) . '/init.php';

$args = array_slice($argv, 1);
$cmd = $args[0] ?? '';
$usageUser = null;
if ($cmd === 'usage' && isset($args[1]) && preg_match('/^[^-][^\s]{0,127}$/D', $args[1])) {
    $usageUser = $args[1];
    $args = array_merge([$cmd], array_slice($args, 2));
}
$opt = ['loop' => 0, 'every' => 15, 'json' => false];
foreach (array_slice($args, 1) as $a) {
    if (preg_match('/^--(loop|every)=([0-9]{1,4})$/', $a, $m)) {
        $opt[$m[1]] = (int) $m[2];
    } elseif ($a === '--json') {
        $opt['json'] = true;
    } else {
        fwrite(STDERR, "unknown option {$a}\n");
        exit(5);
    }
}
if (!in_array($cmd, ['enforce', 'report', 'usage'], true) || $opt['every'] < 5 || ($cmd === 'usage' && $usageUser === null)) {
    fwrite(STDERR, "usage: php system/reseller/quota.php enforce [--loop=55] [--every=15] [--json] | report [--json] | usage USERNAME [--json]\n");
    exit(5);
}

try {
    try {
        $radius = ORM::get_db('radius');
    } catch (Throwable) {
        $radius = ORM::get_db();
    }
    $enforcer = new \Reseller\Radius\QuotaEnforcer($radius);
} catch (Throwable $e) {
    fwrite(STDERR, 'RADIUS database unavailable (ref ' . \Reseller\Security\ErrorReporter::report($e, 'quota') . ")\n");
    exit(3);
}
$print = static function (array $data) use ($opt): void {
    echo $opt['json'] ? json_encode($data, JSON_UNESCAPED_SLASHES) . "\n" : print_r($data, true);
};

if ($cmd === 'usage') {
    // Option G: the raw accounting position of the current period; billable = min(actual, quota); the overshoot is never charged
    $print(['username' => $usageUser, 'periods' => (new \Reseller\Radius\QuotaUsage($radius))->current($usageUser)]);
    exit(0);
}

if ($cmd === 'report') {
    $print(array_map(static fn(array $o): array => ['username' => $o['username'], 'quota' => $o['quota'], 'limit' => $o['limit'], 'usage' => $o['usage'],
        'open_sessions' => array_map(static fn(array $s): int => (int) $s['radacctid'], $o['sessions'])], $enforcer->overQuota()));
    exit(0);
}

$lock = 'nuxr_quota_' . substr(sha1((string) $radius->query('SELECT DATABASE()')->fetchColumn()), 0, 20);
if ((int) $radius->query('SELECT GET_LOCK(' . $radius->quote($lock) . ', 0)')->fetchColumn() !== 1) {
    $print(['enforce' => 'another enforcer is running']);
    exit(0);
}
$audit = static function (array $action): void {
    try {
        if (!\Reseller\Domain\SchemaReadiness::isReady()) {
            \Reseller\Security\ErrorReporter::log('quota.enforced', 'username=' . $action['username'] . ' quota=' . $action['quota']);
            return;
        }
        $st = ORM::get_db()->prepare('SELECT id, manager_id FROM tbl_customers WHERE username = ? OR pppoe_username = ? ORDER BY id LIMIT 2');
        $st->execute([$action['username'], $action['username']]);
        $c = $st->fetchAll(PDO::FETCH_ASSOC);
        \Reseller\Audit\AuditLog::record('quota.enforced', \Reseller\Security\ActorContext::system('CLI'), [
            'target_type' => 'customer', 'target_customer_id' => count($c) === 1 ? (int) $c[0]['id'] : null,
            'target_user_id' => count($c) === 1 && $c[0]['manager_id'] !== null ? (int) $c[0]['manager_id'] : null,
            'target_snapshot' => ['username' => $action['username']],
            'after' => ['quota' => $action['quota'], 'limit' => $action['limit'], 'usage' => $action['usage'], 'sessions' => $action['ended']],
        ]);
    } catch (Throwable $e) {
        \Reseller\Security\ErrorReporter::report($e, 'quota-audit');
    }
};
// liveness for health.php (quota_enforcer): the UTC time of the latest completed pass
$heartbeat = static function (): void {
    try {
        $db = ORM::get_db();
        $up = $db->prepare("UPDATE tbl_appconfig SET value = ? WHERE setting = 'nuxr_quota_enforcer_heartbeat'");
        $up->execute([gmdate('Y-m-d H:i:s')]);
        if ($up->rowCount() === 0 && (int) $db->query("SELECT COUNT(*) FROM tbl_appconfig WHERE setting = 'nuxr_quota_enforcer_heartbeat'")->fetchColumn() === 0) {
            $db->prepare("INSERT INTO tbl_appconfig (setting, value) VALUES ('nuxr_quota_enforcer_heartbeat', ?)")->execute([gmdate('Y-m-d H:i:s')]);
        }
    } catch (Throwable $e) {
        \Reseller\Security\ErrorReporter::report($e, 'quota-heartbeat');
    }
};
$all = [];
$deadline = microtime(true) + $opt['loop'];
try {
    do {
        foreach ($enforcer->pass() as $action) {
            $audit($action);
            $all[] = $action;
        }
        $heartbeat();
        if (microtime(true) + $opt['every'] > $deadline) {
            break;
        }
        sleep($opt['every']);
    } while (true);
} catch (Throwable $e) {
    fwrite(STDERR, 'enforcement pass failed (ref ' . \Reseller\Security\ErrorReporter::report($e, 'quota') . ")\n");
    $radius->query('SELECT RELEASE_LOCK(' . $radius->quote($lock) . ')');
    exit(3);
}
$radius->query('SELECT RELEASE_LOCK(' . $radius->quote($lock) . ')');
$print(['enforce' => 'done', 'actions' => $all]);
exit(0);
