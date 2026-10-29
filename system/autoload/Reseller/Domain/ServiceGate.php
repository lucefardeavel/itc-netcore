<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Security\ActorContext;
use Reseller\Security\ErrorReporter;

/**
 * Owner decision OD-11 (temporary safety gate until WP5B): for every customer with
 * manager_id IS NOT NULL ("reseller-owned"), every legacy operation that can create, extend,
 * re-provision or otherwise grant service — or mint customer balance — fails closed for EVERY role.
 * WP2 implements no finance; this gate only refuses.
 *
 * Ownership is read fresh from the database. If the reseller schema is not ready the gate cannot
 * evaluate ownership and therefore refuses every such operation (owner schema-readiness rule; a
 * missing column would otherwise read as NULL and fail open).
 */
final class ServiceGate
{
    public const MESSAGE = 'This service is managed by your reseller and cannot be changed here. Please contact your authorized reseller.';
    public const NOT_READY_MESSAGE = 'Service operations are unavailable: the reseller schema is not ready.';

    /** True when a legacy service operation for this customer id must be refused. */
    public static function isBlockedCustomerId(int|string|null $customerId): bool
    {
        if (!SchemaReadiness::isReady()) {
            return true;
        }
        $id = (int) $customerId;
        if ($id <= 0) {
            return false;
        }
        try {
            $st = \ORM::get_db()->prepare('SELECT manager_id FROM tbl_customers WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            return $row !== false && $row['manager_id'] !== null;
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'service-gate');
            return true;
        }
    }

    /** Same check by customer username (voucher codes, pppoe usernames, gift targets). */
    public static function isBlockedUsername(?string $username): bool
    {
        if (!SchemaReadiness::isReady()) {
            return true;
        }
        if ($username === null || $username === '') {
            return false;
        }
        try {
            $st = \ORM::get_db()->prepare('SELECT manager_id FROM tbl_customers WHERE username = ? OR pppoe_username = ?');
            $st->execute([$username, $username]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if ($row['manager_id'] !== null) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'service-gate');
            return true;
        }
    }

    public static function message(): string
    {
        $m = SchemaReadiness::isReady() ? self::MESSAGE : self::NOT_READY_MESSAGE;
        // lab UX-2: shown to customers and staff in the interface language (plain catalogue text, F-12)
        return class_exists('Lang') ? \Lang::T($m) : $m;
    }

    /**
     * Controller gate (web/API): if the customer is blocked, records the refusal and ends the request
     * with the generic message (redirect for web, success:false for the API). Returns only when allowed.
     */
    public static function guardCustomer(int|string|null $customerId, string $operation, string $redirectRoute): void
    {
        if (self::isBlockedCustomerId($customerId)) {
            self::refused($operation, $customerId);
            r2(getUrl($redirectRoute), 'e', self::message());
        }
    }

    /** Controller gate by username (voucher codes, gift targets, pppoe usernames). */
    public static function guardUsername(?string $username, string $operation, string $redirectRoute): void
    {
        if (self::isBlockedUsername($username)) {
            self::refused($operation, null, $username);
            r2(getUrl($redirectRoute), 'e', self::message());
        }
    }

    /**
     * Records a refused operation: audit event (when the schema allows) plus a sanitized log line.
     * Never throws.
     */
    public static function refused(string $operation, int|string|null $customerId = null, ?string $username = null): void
    {
        $detail = 'op=' . $operation . ' customer=' . (string) $customerId . ' username=' . (string) $username;
        ErrorReporter::log('service.refused', $detail);
        if (PHP_SAPI === 'cli' || !SchemaReadiness::isReady()) {
            return; // cron/CLI: logged only (no audit flood); not ready: audit table may be absent
        }
        try {
            AuditLog::record('service.refused', ActorContext::current(), [
                'target_type' => 'customer',
                'target_customer_id' => $customerId !== null && (int) $customerId > 0 ? (int) $customerId : null,
                'target_snapshot' => ['username' => $username],
                'after' => ['operation' => $operation],
                'reason' => 'OD-11 legacy service gate',
            ]);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'service-gate-audit');
        }
    }
}
