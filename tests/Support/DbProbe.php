<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Whole-database "no mutation" probe: CHECKSUM TABLE of every table (both engines). Activity tables
 * that legitimately change on a refused request (tbl_logs, the append-only audit table) are excluded
 * by default and asserted separately where relevant.
 */
final class DbProbe
{
    public const DEFAULT_EXCLUDE = ['tbl_logs', 'tbl_reseller_audit_events'];

    /** @return array<string,string> table => checksum */
    public static function checksums(TestDatabase $db, array $exclude = self::DEFAULT_EXCLUDE): array
    {
        $out = [];
        foreach ($db->tables() as $t) {
            if (in_array($t, $exclude, true)) {
                continue;
            }
            $row = $db->rows('CHECKSUM TABLE `' . $t . '` EXTENDED');
            $out[$t] = (string) ($row[0]['Checksum'] ?? '');
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> tables whose checksum differs */
    public static function changed(array $before, array $after): array
    {
        $changed = [];
        foreach ($before + $after as $t => $_) {
            if (($before[$t] ?? null) !== ($after[$t] ?? null)) {
                $changed[] = $t;
            }
        }
        sort($changed);
        return $changed;
    }
}
