<?php
namespace Reseller\Migration;

// CLI-only: this file must never execute through the web server (the web root is the repo root).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Conservative privilege check from SHOW GRANTS FOR CURRENT_USER().
 * Only global (*.*) and database-level grants are understood. Anything that cannot be proven
 * (e.g. privileges only reachable through roles) results in "missing" and therefore NOT_READY.
 */
final class Grants
{
    public const REQUIRED = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'INDEX', 'DROP', 'REFERENCES'];

    /**
     * @param list<string> $grantLines
     * @return list<string> missing privileges (empty = sufficient)
     */
    public static function missing(array $grantLines, string $database): array
    {
        $have = [];
        foreach ($grantLines as $line) {
            if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', $line, $m)) {
                continue;
            }
            $scope = $m[2];
            if (!self::scopeCoversDatabase($scope, $database)) {
                continue;
            }
            $privs = strtoupper($m[1]);
            if (str_starts_with($privs, 'ALL')) {
                return [];
            }
            // strip column lists such as "SELECT (col1, col2)"
            $privs = (string) preg_replace('/\([^)]*\)/', '', $privs);
            foreach (explode(',', $privs) as $p) {
                $have[trim($p)] = true;
            }
        }
        return array_values(array_filter(self::REQUIRED, static fn(string $p): bool => !isset($have[$p])));
    }

    private static function scopeCoversDatabase(string $scope, string $database): bool
    {
        if ($scope === '*.*') {
            return true;
        }
        if (!preg_match('/^`?((?:[^`]|``)+?)`?\.\*$/', $scope, $m)) {
            return false; // table-level or unknown scope: not sufficient for schema changes
        }
        $pattern = $m[1];
        // MySQL/MariaDB grant patterns: unescaped % and _ are wildcards, \_ and \% are literals
        $regex = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($ch === '%') {
                $regex .= '.*';
            } elseif ($ch === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($ch, '/');
            }
        }
        return (bool) preg_match('/^' . $regex . '$/', $database);
    }
}
