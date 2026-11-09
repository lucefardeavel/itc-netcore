<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ErrorReporter;

/**
 * Owner rule (WP2 schema readiness): if the reseller ownership / hierarchy / audit schema is missing,
 * partial or inconsistent, every business mutation that depends on it fails closed for EVERY role,
 * SuperAdmin and Admin included.
 *
 * Web-side check that mirrors the strict project runner's readiness rule without loading the
 * CLI-only runner classes: the registry must exist, every expected migration must be APPLIED with
 * an unchanged file checksum, no migration may be in a blocking/unknown state, the required
 * tables/columns must exist and no migration run may be in progress. Any error ⇒ not ready.
 *
 * A registry row saying a migration is APPLIED is not proof that its runtime tables still exist, so
 * every table a runtime authority depends on is checked directly: the WP2 hierarchy / audit /
 * ownership tables and the WP3A finance authorities (activation rows used by reservations, wallets,
 * transfers / idempotency, the ledger, reservation exposure). A missing finance table therefore makes
 * PermissionService refuse every finance capability for every role before any finance SQL runs.
 * WP3B makes the pricing tables runtime-authoritative (provider base cost / resale enablement and the
 * per-manager wholesale / retail / availability overrides), so they are required as well: a missing
 * pricing table closes every pricing, finance and hierarchy capability for every role.
 */
final class SchemaReadiness
{
    public const EXPECTED = [
        'R0001_manager_tree',
        'R0002_pricing_foundation',
        'R0003_activation_foundation',
        'R0004_wallet_finance_foundation',
        'R0005_customer_ownership',
        'R0006_reseller_audit',
        'R0007_activation_reversal_states',
        'R0008_voucher_logins',
    ];
    public const BLOCKING = ['APPLYING', 'FAILED_PARTIAL', 'ROLLBACK_REQUIRED', 'ROLLING_BACK', 'ROLLBACK_FAILED'];
    private const REQUIRED_TABLES = [
        'tbl_manager_tree', 'tbl_reseller_audit_events', 'tbl_customers',
        // R0008: username-and-password hotspot cards
        'tbl_voucher_logins',
        // WP3A finance runtime dependencies
        'tbl_reseller_activations', 'tbl_manager_wallets', 'tbl_manager_transfers', 'tbl_manager_ledger', 'tbl_manager_wallet_reservations',
        // WP3B pricing runtime authorities
        'tbl_reseller_plan_base_costs', 'tbl_manager_plan_prices',
        // WP5A activation timeline
        'tbl_reseller_activation_events',
    ];
    private const REQUIRED_COLUMNS = [['tbl_customers', 'manager_id'], ['tbl_customers', 'assigned_sales_id']];

    /** @var array{ready:bool,problems:list<string>}|null */
    private static ?array $cache = null;

    public static function isReady(): bool
    {
        return self::status()['ready'];
    }

    /** @return array{ready:bool,problems:list<string>} */
    public static function status(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $problems = [];
        try {
            $pdo = \ORM::get_db();
            $has = static function (string $sql, array $p = []) use ($pdo): int {
                $st = $pdo->prepare($sql);
                $st->execute($p);
                return (int) $st->fetchColumn();
            };
            if ($has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_project_migrations'") !== 1) {
                $problems[] = 'migration registry missing';
            } else {
                $rows = [];
                foreach ($pdo->query('SELECT migration_id, status, checksum FROM tbl_project_migrations')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $rows[(string) $r['migration_id']] = $r;
                }
                foreach (self::EXPECTED as $id) {
                    if (!isset($rows[$id])) {
                        $problems[] = "{$id} not applied";
                        continue;
                    }
                    if ($rows[$id]['status'] !== 'APPLIED') {
                        $problems[] = "{$id} is {$rows[$id]['status']}";
                    }
                    $file = self::migrationsDir() . $id . '.php';
                    if (!is_file($file) || !hash_equals((string) $rows[$id]['checksum'], (string) hash_file('sha256', $file))) {
                        $problems[] = "{$id} checksum drift";
                    }
                }
                foreach ($rows as $id => $r) {
                    if (!in_array($id, self::EXPECTED, true)) {
                        $problems[] = "unknown registry entry {$id}";
                    }
                    if (in_array($r['status'], self::BLOCKING, true)) {
                        $problems[] = "{$id} in blocking state {$r['status']}";
                    }
                }
            }
            // WP9-D / WP1-CHECK: the CHECK constraints of the applied migrations must exist with their exact
            // (normalized) expressions and be enforced on this connection; a same-name weakened, dropped or
            // NOT ENFORCED CHECK, or a MariaDB session with check_constraint_checks = 0, is not ready
            $expected = [];
            foreach (self::EXPECTED as $id) {
                $file = self::migrationsDir() . $id . '.php';
                if (is_file($file)) {
                    $expected = CheckConstraints::expectedFromSql((string) file_get_contents($file)) + $expected;
                }
            }
            $engine = stripos((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false ? 'mariadb' : 'mysql';
            foreach (CheckConstraints::problems($pdo, $engine, $expected) as $c) {
                $problems[] = $c;
            }
            foreach (self::REQUIRED_TABLES as $t) {
                if ($has('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) !== 1) {
                    $problems[] = "table {$t} missing";
                }
            }
            foreach (self::REQUIRED_COLUMNS as [$t, $c]) {
                if ($has('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]) !== 1) {
                    $problems[] = "column {$t}.{$c} missing";
                }
            }
            $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $lock = 'nuxr_mig_' . substr(sha1($db), 0, 20);
            $st = $pdo->prepare('SELECT IS_USED_LOCK(?)');
            $st->execute([$lock]);
            if ($st->fetchColumn() !== null) {
                $problems[] = 'migration run in progress';
            }
        } catch (\Throwable $e) {
            $problems[] = 'readiness check failed (ref ' . ErrorReporter::report($e, 'schema-readiness') . ')';
        }
        self::$cache = ['ready' => $problems === [], 'problems' => $problems];
        return self::$cache;
    }

    /** Test/runtime hook: forget the per-request cache. */
    public static function reset(): void
    {
        self::$cache = null;
    }

    private static function migrationsDir(): string
    {
        $root = $GLOBALS['root_path'] ?? (dirname(__DIR__, 4) . DIRECTORY_SEPARATOR);
        return rtrim((string) $root, '/\\') . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'reseller' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;
    }
}
