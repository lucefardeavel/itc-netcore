<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * C24 / OD-5: the legacy JSON restore (TRUNCATE + re-insert with foreign-key checks disabled) fails
 * closed BEFORE any mutation whenever reseller/project schema or state exists. It would silently
 * NULL ownership, truncate the migration registry/journal, hierarchy, ledger and audit, and leave
 * foreign-key-violating rows behind. Reseller installations use the DB-level runbook (WP10).
 *
 * On a pure legacy installation the restore stays available, hardened: SuperAdmin + CSRF, table
 * names validated against SHOW TABLES and quoted, project tables refused, no GLOBAL setting changes.
 */
final class RestoreGuard
{
    public const PROJECT_TABLE = '/^tbl_(manager|reseller|project)_/';

    /** @return string|null refusal reason, or null when a legacy restore may proceed */
    public static function refusalReason(): ?string
    {
        try {
            $pdo = \ORM::get_db();
            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $t) {
                if (preg_match(self::PROJECT_TABLE, (string) $t)) {
                    if ($t === 'tbl_project_migrations') {
                        $n = (int) $pdo->query("SELECT COUNT(*) FROM tbl_project_migrations WHERE status <> 'ROLLED_BACK'")->fetchColumn();
                        if ($n > 0) {
                            return 'reseller migrations are applied or in progress';
                        }
                        continue;
                    }
                    return 'reseller schema present (' . $t . ')';
                }
            }
            $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_customers' AND COLUMN_NAME IN ('manager_id','assigned_sales_id')")->fetchAll(\PDO::FETCH_COLUMN);
            if ($cols !== []) {
                return 'customer ownership columns present';
            }
            return null;
        } catch (\Throwable $e) {
            return 'restore guard check failed (ref ' . ErrorReporter::report($e, 'restore-guard') . ')';
        }
    }

    /**
     * Validates a decoded backup for a legacy restore: every key must be an existing, non-project
     * table. Returns the validated table list.
     *
     * @param mixed $json
     * @return list<string>|null null when invalid
     */
    public static function validTables(mixed $json): ?array
    {
        if (!is_array($json) || $json === []) {
            return null;
        }
        $existing = \ORM::get_db()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $out = [];
        foreach ($json as $table => $records) {
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) || !in_array($table, $existing, true)
                || preg_match(self::PROJECT_TABLE, $table) || !is_array($records)) {
                return null;
            }
            $out[] = $table;
        }
        return $out;
    }

    /** @return list<string> tables allowed in a backup request (existing tables only) */
    public static function backupTables(mixed $requested): array
    {
        if (!is_array($requested)) {
            return [];
        }
        $existing = \ORM::get_db()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $out = [];
        foreach ($requested as $t) {
            if (is_string($t) && in_array($t, $existing, true)) {
                $out[] = $t;
            }
        }
        return array_values(array_unique($out));
    }
}
