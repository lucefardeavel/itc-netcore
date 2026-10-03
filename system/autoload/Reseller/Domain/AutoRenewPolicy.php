<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Security\ActorContext;
use Reseller\Security\ErrorReporter;

/**
 * Cron auto-renew of a RESELLER-OWNED customer (WP5B; owner decision OD-3): not available in V1. The legacy
 * auto_renewal flag is never reseller consent; the expiry is enforced by the cron as for everyone, and no
 * activation, wallet debit or customer-balance debit happens. Exactly one AUTO_RENEW_UNAVAILABLE record per
 * obligation (recharge row + its expiry), however often the cron runs.
 */
final class AutoRenewPolicy
{
    public const EVENT = 'service.auto_renew_unavailable';

    /** @param array<string,mixed> $recharge the expired tbl_user_recharges row */
    public static function unavailable(array $recharge): bool
    {
        $obligation = (int) $recharge['id'] . ':' . $recharge['expiration'] . 'T' . $recharge['time'];
        try {
            $pdo = \ORM::get_db();
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $seen = Tx::row($pdo, 'SELECT id FROM tbl_reseller_audit_events WHERE event_type = ? AND target_customer_id = ? AND after_json LIKE ? LIMIT 1',
                [self::EVENT, (int) $recharge['customer_id'], '%"obligation":"' . $obligation . '"%']);
            if ($seen !== null) {
                return false;
            }
            AuditLog::record(self::EVENT, ActorContext::system('CLI'), [
                'target_type' => 'customer', 'target_customer_id' => (int) $recharge['customer_id'],
                'target_snapshot' => ['username' => (string) $recharge['username']],
                'after' => ['outcome' => 'AUTO_RENEW_UNAVAILABLE', 'obligation' => $obligation, 'plan_id' => (int) $recharge['plan_id']],
                'reason' => 'OD-3: no reseller auto-renew in V1',
            ], $pdo);
            return true;
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'auto-renew-unavailable');
            return false;
        }
    }
}
