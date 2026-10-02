<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Security\ActorContext;
use Reseller\Security\ErrorReporter;

/**
 * Safe legacy plan deletion (WP1 binding input to WP3B, WP1_SCHEMA_CONTRACT §3). Reseller rows reference
 * tbl_plans with ON DELETE RESTRICT (provider base cost, per-manager price overrides, activation
 * plan_id / previous_plan_id). The legacy delete paths remove the plan from the router / RADIUS FIRST,
 * so a delete that the database then refuses would leave the device and the database inconsistent.
 *
 * The controller therefore hands the whole legacy delete (hook, device removal, row removal) to
 * deleteSerialized(), which runs the final reference check and every delete-side effect as ONE unit:
 *  1. the global reseller-tree lock (Tx::withTreeLock: the lock every pricing writer, and every future
 *     activation writer, holds while it creates a plan reference) is taken BEFORE the final check and
 *     released only after the row removal has committed or rolled back, so no in-application reference
 *     can be created between the decision and the delete;
 *  2. inside the transaction the plan row is locked FOR UPDATE before the check: a reference insert by
 *     any other writer (its foreign-key check needs a shared lock on the plan row) either committed
 *     before the check, and is seen, or waits for this unit and then fails on the missing plan;
 *  3. a referenced plan is refused with no hook, no device call and no database change; a lock timeout
 *     or any error before the effects fails closed the same way.
 * All-NULL manager price rows never persist (PricingService removes them), so an emptied configuration
 * never blocks a delete. SELECT-only besides the locks; no finance code.
 *
 * Reference discovery is schema-driven (every foreign key to tbl_plans.id), so an unmigrated legacy
 * installation (no reseller tables, no foreign keys) deletes exactly as before.
 */
final class PlanGuard
{
    /**
     * Runs the legacy plan delete $delete (hook, device removal, row removal on the ORM connection)
     * serialized against every plan-reference writer. Returns null when the delete ran and committed,
     * or the refusal message when nothing was done (referenced, missing, lock timeout, guard error).
     * Once the effects have started, a foreign-key refusal is reported as REFERENCED and any other error
     * is rethrown unchanged (legacy behaviour); the database part is rolled back in both cases.
     *
     * @param callable():void $delete
     */
    public static function deleteSerialized(int $planId, callable $delete): ?string
    {
        $refusal = null;
        $effects = false;
        try {
            $pdo = \ORM::get_db();
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            // the legacy effects keep the request's own sql_mode (Tx::run makes the unit strict)
            $legacyMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            Tx::withTreeLock(static function (\PDO $pdo) use ($planId, $delete, $legacyMode, &$refusal, &$effects): void {
                if (Tx::row($pdo, 'SELECT id FROM tbl_plans WHERE id = ? FOR UPDATE', [$planId]) === null) {
                    $refusal = 'NOT_FOUND';
                    return;
                }
                if (StaffService::isReferenced($pdo, 'tbl_plans', $planId)) {
                    $refusal = 'REFERENCED';
                    return;
                }
                $pdo->prepare('SET SESSION sql_mode = ?')->execute([$legacyMode]);
                $effects = true;
                $delete();
            });
        } catch (\Throwable $e) {
            if (!$effects) {
                // nothing was attempted: fail closed (a lock timeout keeps its retry message)
                ErrorReporter::report($e, 'plan-delete-guard');
                $refusal = $e instanceof DomainError && $e->reason === 'LOCK_TIMEOUT' ? 'LOCK_TIMEOUT' : 'REFERENCED';
            } elseif ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1451) {
                ErrorReporter::report($e, 'plan-delete-fk');
                return DomainError::MESSAGES['REFERENCED'];
            } else {
                throw $e;
            }
        }
        if ($refusal === null) {
            return null;
        }
        if ($refusal === 'REFERENCED') {
            self::refused($planId);
        }
        return DomainError::MESSAGES[$refusal];
    }

    /** Evidence of the refusal (log always; audit when the reseller schema is ready, never in CLI). */
    private static function refused(int $planId): void
    {
        ErrorReporter::log('plan.delete_refused', 'plan=' . $planId);
        if (PHP_SAPI === 'cli' || !SchemaReadiness::isReady()) {
            return;
        }
        try {
            AuditLog::record('plan.delete_refused', ActorContext::current(), [
                'target_type' => 'plan',
                'target_snapshot' => ['plan_id' => $planId],
                'after' => ['operation' => 'plan delete'],
                'reason' => 'plan is referenced by reseller pricing or activation data',
            ]);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'plan-delete-guard-audit');
        }
    }
}
