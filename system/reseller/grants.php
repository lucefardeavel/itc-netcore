<?php
// CLI ONLY: refused before anything else
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * WP9 TAMPER / LPDB -> WP10 least-privilege runtime accounts. CLI ONLY.
 *
 * Prints the GRANT statements (no passwords, no CREATE USER) for the two RUNTIME database accounts of ONE ISP instance,
 * derived from the tables that exist in the database (`php system/reseller/grants.php --app=<'user'@'host'>
 * [--radius=<'user'@'host'>]`, connection from config.php). The operator creates the accounts (password from the
 * instance secret store), reviews the statements and applies them with an administrative account. Migrations run with
 * a separate, privileged migration account (system/reseller/migrate.php checks its privileges).
 *
 * Application account (the PHP runtime, config.php $db_user):
 *  - append-only records: SELECT, INSERT only on the ledger, the audit tables and the activation timeline - so the
 *    application account itself cannot rewrite financial or audit history (TAMPER: the application-level immutability
 *    becomes a database guarantee);
 *  - reseller domain tables: SELECT, INSERT, UPDATE (DELETE only where the domain deletes: closure rows, price overrides);
 *  - migration registry and journal: SELECT (readiness);
 *  - FreeRADIUS accounting (radacct): SELECT (FreeRADIUS writes it; the release profile has no rlm_rest counter);
 *  - FreeRADIUS admission tables: SELECT; nuxr_admission UPDATE and nuxr_admission_audit INSERT for the audited
 *    operator recovery CLI;
 *  - every other (legacy PHPNuxBill / RADIUS policy) table: SELECT, INSERT, UPDATE, DELETE.
 * FreeRADIUS account (--radius): SELECT on the policy tables it reads, INSERT/UPDATE on radacct and nasreload, the admission tables
 * it maintains (no DELETE anywhere, no access to tbl_* billing tables).
 *
 * Exit: 0 printed; 5 usage.
 */

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(app|radius|config)=(.+)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "usage: php system/reseller/grants.php --app=\"'user'@'host'\" [--radius=\"'user'@'host'\"] [--config=<config.php>]\n");
        exit(5);
    }
}
$account = static fn(string $a): bool => (bool) preg_match("/^'[A-Za-z0-9_.-]{1,32}'@'[A-Za-z0-9_.%:-]{1,255}'$/", $a);
if (!isset($opt['app']) || !$account($opt['app']) || (isset($opt['radius']) && !$account($opt['radius']))) {
    fwrite(STDERR, "usage: php system/reseller/grants.php --app=\"'user'@'host'\" [--radius=\"'user'@'host'\"]\n");
    exit(5);
}
$config = $opt['config'] ?? dirname(__DIR__, 2) . '/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "config.php not found\n");
    exit(5);
}
(static function (string $f): void {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    require $f;
    $GLOBALS['nuxr_db'] = [$db_host ?? 'localhost', $db_name ?? '', $db_user ?? '', $db_pass ?? ''];
})($config);
[$host, $name, $user, $pass] = $GLOBALS['nuxr_db'];
$pdo = new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo nuxr_grants($pdo, $name, $opt['app'], $opt['radius'] ?? null);
exit(0);

/** @return string the GRANT statements */
function nuxr_grants(PDO $pdo, string $db, string $app, ?string $radius): string
{
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $appendOnly = ['tbl_manager_ledger', 'tbl_reseller_audit_events', 'tbl_reseller_activation_events'];
    // reseller-domain tables: no DELETE, except where the domain itself deletes (closure rows, all-NULL price overrides)
    $withDelete = ['tbl_manager_tree', 'tbl_manager_plan_prices'];
    $domain = static fn(string $t): bool => str_starts_with($t, 'tbl_manager_') || str_starts_with($t, 'tbl_reseller_');
    $readOnly = ['tbl_project_migrations', 'tbl_project_migration_journal', 'radacct', 'nuxr_hs_admission', 'nuxr_hs_admission_audit'];
    $special = ['nuxr_admission' => 'SELECT, UPDATE', 'nuxr_admission_audit' => 'SELECT, INSERT'];
    $q = static fn(string $t): string => '`' . str_replace('`', '``', $db) . '`.`' . str_replace('`', '``', $t) . '`';
    $out = "-- WP9/WP10 least-privilege grants for database `{$db}` (generated " . gmdate('Y-m-d H:i:s') . " UTC; review before applying)\n";
    $out .= "-- application runtime account {$app}\n";
    foreach ($tables as $t) {
        $priv = $special[$t] ?? (in_array($t, $appendOnly, true) ? 'SELECT, INSERT'
            : (in_array($t, $readOnly, true) ? 'SELECT'
            : (in_array($t, $withDelete, true) ? 'SELECT, INSERT, UPDATE, DELETE'
            : ($domain($t) ? 'SELECT, INSERT, UPDATE'
            : (str_starts_with($t, 'nuxr_') ? 'SELECT' : 'SELECT, INSERT, UPDATE, DELETE')))));
        $out .= "GRANT {$priv} ON {$q($t)} TO {$app};\n";
    }
    if ($radius !== null) {
        $out .= "-- FreeRADIUS runtime account {$radius} (policy read, accounting and admission writes; no billing tables)\n";
        $rad = ['radcheck' => 'SELECT', 'radreply' => 'SELECT', 'radusergroup' => 'SELECT', 'radgroupcheck' => 'SELECT', 'radgroupreply' => 'SELECT', 'nas' => 'SELECT',
            'radacct' => 'SELECT, INSERT, UPDATE',
            // lab finding B-L04: the stock simultaneous-use query joins nasreload and Accounting-On upserts it (FreeRADIUS's
            // own setup.sql grants SELECT, INSERT, UPDATE); without it the stock Simultaneous-Use layer failed on every request
            'nasreload' => 'SELECT, INSERT, UPDATE',
            'nuxr_admission' => 'SELECT, INSERT, UPDATE',
            'nuxr_hs_admission' => 'SELECT, INSERT, UPDATE', 'nuxr_hs_admission_audit' => 'INSERT'];
        foreach ($rad as $t => $priv) {
            if (in_array($t, $tables, true)) {
                $out .= "GRANT {$priv} ON {$q($t)} TO {$radius};\n";
            }
        }
    }
    return $out;
}
