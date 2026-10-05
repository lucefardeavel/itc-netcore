<?php
namespace Reseller\Backoffice;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Security\ActorContext;
use Reseller\Ui\Format;

/**
 * WP6B (owner decision OD-6): streams a CSV export produced by an authoritative, scoped export cursor (the same query
 * and filters as the screen). The caps are Finance\ExportCap's (at most 366 days and 20,000 rows): the scoped total is
 * known before the first byte and an export above the cap is refused, never truncated (fix round 1).
 * Headers are sent only when the first row exists, so a refusal before any row still lets the caller redirect with a
 * message. The export is audited with metadata only (type, filters, target, row count) — never the exported data.
 */
final class ExportStream
{
    /**
     * @param list<array{0:string,1:callable}> $columns header => fn(array $row): mixed
     * @param callable                          $producer fn(callable $sink): int (the scoped export cursor)
     * @param array<string,mixed>               $filters  the requested filters (audit metadata)
     */
    public static function csv(ActorContext $a, string $type, ?int $target, array $filters, array $columns, callable $producer, string $filename): int
    {
        $out = null;
        $open = static function () use (&$out, $columns, $filename): void {
            if ($out !== null) {
                return;
            }
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map([Format::class, 'csvCell'], array_column($columns, 0)), ';');
        };
        $rows = $producer(static function (array $row) use (&$out, $open, $columns): void {
            $open();
            fputcsv($out, array_map(static fn(array $c): string => Format::csvCell(($c[1])($row)), $columns), ';');
        });
        $open();
        fflush($out);
        AuditLog::record('export.finance', $a, [
            'target_type' => 'export', 'target_user_id' => $target,
            'after' => ['export' => $type, 'filters' => self::filters($filters), 'rows' => $rows],
        ]);
        return $rows;
    }

    /** @return array<string,string> request filters as plain strings (bounded) for the audit record */
    private static function filters(array $f): array
    {
        $out = [];
        foreach ($f as $k => $v) {
            if (is_string($k) && (is_string($v) || is_int($v)) && (string) $v !== '') {
                $out[substr($k, 0, 32)] = mb_substr((string) $v, 0, 64);
            }
        }
        return $out;
    }
}
