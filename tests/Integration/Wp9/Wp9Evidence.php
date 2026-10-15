<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP9 T1-A (owner: WP9_T1A_CHARACTERIZATION_AUTHORIZED_CONDITIONALLY) - evidence recording for characterisation tests.
 *
 * Characterisation tests assert their CONTROLS and the CURRENT behaviour; each observation is also appended as one JSON
 * line to the file named by the environment variable WP9_EVIDENCE (no output during tests: PHPUnit is strict about it).
 * Classifications:
 *   CONTROL_PASS       positive / negative control behaved as expected
 *   OBSERVED           current behaviour recorded (no judgement)
 *   DEFECT_REPRODUCED  behaviour the WP9 plan expected to be unsafe was reproduced (not a security acceptance)
 *   STOP_TRIGGER       an owner stop condition was proven (cross-customer identity overwrite, unauthorized RADIUS policy
 *                      mutation, unauthorized grant, refused-operation mutation, cross-tenant access, WP8 contradiction)
 * State deltas are split: business = every table except the audit / log tables; audit = tbl_reseller_audit_events, tbl_logs.
 */
trait Wp9Evidence
{
    private const AUDIT_TABLES = ['tbl_logs', 'tbl_reseller_audit_events'];

    protected static function engine(): string
    {
        return (string) getenv('WP1_DB_HOST');
    }

    /** @param array<string,mixed> $data */
    protected static function record(string $case, string $classification, array $data = []): void
    {
        $file = getenv('WP9_EVIDENCE');
        if (!is_string($file) || $file === '') {
            return;
        }
        $line = json_encode(['engine' => self::engine(), 'test' => static::class, 'case' => $case, 'classification' => $classification] + $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return array<string,string> every table's checksum (audit / log tables included) */
    protected static function state(TestDatabase $db): array
    {
        return DbProbe::checksums($db, []);
    }

    /** @return array{business:list<string>,audit:list<string>} tables changed between two state() snapshots */
    protected static function delta(array $before, array $after): array
    {
        $changed = DbProbe::changed($before, $after);
        return ['business' => array_values(array_diff($changed, self::AUDIT_TABLES)), 'audit' => array_values(array_intersect($changed, self::AUDIT_TABLES))];
    }

    /** radcheck rows whose username equals $name under the column collation (the RADIUS lookup) - exact bytes kept */
    protected static function radRows(TestDatabase $db, string $name): array
    {
        return $db->rows('SELECT username, HEX(username) AS hex, attribute, op, value FROM radcheck WHERE username = ? ORDER BY id', [$name]);
    }
}
