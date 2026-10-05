<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;

/**
 * WP6B export cap (owner decision OD-6, fix round 1): one explicit primitive for every finance / pricing CSV export.
 *
 *  - assertWithin(): the authoritative scoped total is known before any CSV byte; above MAX_ROWS the export is refused
 *    with EXPORT_TOO_LARGE (exactly MAX_ROWS is allowed). An export is never truncated.
 *  - snapshot(): for the SQL export cursors, the count and the streamed rows are read in ONE read-only REPEATABLE READ
 *    snapshot, so the streamed set is exactly the counted set (a row committed after the count can neither push the
 *    export over the cap nor be cut off by it). Consistent reads take no locks; nothing is written.
 *
 * It holds no scope or query of its own: callers pass their screen query (same capability, scope and filters).
 */
final class ExportCap
{
    public const MAX_ROWS = 20000;
    public const MAX_DAYS = 366;

    /** Refuses (before any output) a total above the cap; exactly MAX_ROWS passes. */
    public static function assertWithin(int $total): void
    {
        if ($total > self::MAX_ROWS) {
            throw new DomainError('EXPORT_TOO_LARGE');
        }
    }

    /**
     * Counts and streams inside one read snapshot. $count returns the authoritative scoped total of exactly the rows
     * $stream will emit; $stream runs only when that total is within the cap and returns the rows it emitted.
     *
     * @param callable():int $count
     * @param callable():int $stream
     */
    public static function snapshot(\PDO $pdo, callable $count, callable $stream): int
    {
        if ($pdo->inTransaction()) {
            throw new \LogicException('an export cursor never runs inside another transaction');
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        try {
            self::assertWithin((int) $count());
            $rows = (int) $stream();
            $pdo->commit();
            return $rows;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
