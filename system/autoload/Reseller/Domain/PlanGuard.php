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
 * The controller therefore asks this guard BEFORE any hook, device call or delete: a referenced plan is
 * refused with no side effect. All-NULL manager price rows never persist (PricingService deletes them),
 * so an emptied configuration never blocks a delete. SELECT-only; no finance code.
 *
 * Reference discovery is schema-driven (every foreign key to tbl_plans.id), so an unmigrated legacy
 * installation (no reseller tables, no foreign keys) behaves exactly as before. Any error fails closed.
 */
final class PlanGuard
{
    /** Refusal message when the plan must not be deleted, null when the legacy delete may proceed. */
    public static function deleteRefusal(int $planId): ?string
    {
        try {
            $pdo = \ORM::get_db();
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            if (!StaffService::isReferenced($pdo, 'tbl_plans', $planId)) {
                return null;
            }
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'plan-delete-guard');
        }
        self::refused($planId);
        return DomainError::MESSAGES['REFERENCED'];
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
