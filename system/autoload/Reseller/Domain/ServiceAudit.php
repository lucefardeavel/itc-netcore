<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Security\ActorContext;
use Reseller\Security\ErrorReporter;

/**
 * WP5B (OD-7): the kept legacy service actions on UNASSIGNED customers (E3 deposit, E4 extend, E5 plan edit;
 * SuperAdmin / Admin only by RouteMap) leave an audit record of who changed whose service. Never throws: the
 * legacy action already happened when this is called.
 */
final class ServiceAudit
{
    /** @param array<string,mixed> $after */
    public static function record(string $event, mixed $customerId, array $after): void
    {
        try {
            AuditLog::record($event, ActorContext::current(), [
                'target_type' => 'customer', 'target_customer_id' => (int) $customerId > 0 ? (int) $customerId : null, 'after' => $after,
            ]);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'service-audit');
        }
    }
}
