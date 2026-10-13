<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\LegacyCustomerBalanceService;
use Reseller\Domain\LegacyReprovisionService;
use Reseller\Domain\Tx;
use Reseller\Domain\VoucherRedemptionService;
use Reseller\Finance\FinanceTx;

/**
 * Detective control of WP5B (§5): what the preventive closure cannot stop (installed plugins with direct database
 * access, a direct table write, an interrupted operation) is reported for the operator. Read only; it never
 * changes anything. Findings name records and kinds, never secrets.
 *
 *  - PERIOD_NOT_BACKED: an active recharge row of a reseller-owned customer that is not the row written by a
 *    COMPLETED activation (method 'Reseller - <reference>');
 *  - RADIUS_DRIFT: the RADIUS subscriber rows of a reseller-owned customer differ from the target of its latest
 *    COMPLETED activation;
 *  - PAYMENT_FOR_RESELLER_OWNED: a paid legacy payment row of a reseller-owned customer;
 *  - VOUCHER_CLAIMED: a voucher whose redemption is in progress or under review;
 *  - REPROVISION_UNCONFIRMED: a re-provision whose device outcome was not confirmed and that no later successful
 *    re-provision of the customer superseded;
 *  - OLD_SESSION_UNCONFIRMED (WP8 A3): a rename whose old-identity sessions are not confirmed ended (state UNCONFIRMED),
 *    or whose confirmation was never recorded (state CONFIRMATION_MISSING);
 *  - BALANCE_HELD: a legacy balance operation HELD for review.
 */
final class DriftReport
{
    /** @return list<array<string,mixed>> */
    public static function collect(bool $radius = true): array
    {
        $pdo = FinanceTx::pdo();
        $out = [];
        $owned = Tx::rows($pdo, 'SELECT id, username FROM tbl_customers WHERE manager_id IS NOT NULL ORDER BY id');
        foreach ($owned as $c) {
            $cid = (int) $c['id'];
            foreach (Tx::rows($pdo, "SELECT id, method FROM tbl_user_recharges WHERE customer_id = ? AND status = 'on' ORDER BY id", [$cid]) as $r) {
                $ref = str_starts_with((string) $r['method'], 'Reseller - ') ? substr((string) $r['method'], 11) : '';
                $act = $ref !== '' ? ActivationStore::byReference($pdo, $ref) : null;
                if ($act === null || $act['status'] !== ActivationState::COMPLETED || (int) $act['customer_id'] !== $cid || (int) $act['legacy_recharge_id'] !== (int) $r['id']) {
                    $out[] = ['kind' => 'PERIOD_NOT_BACKED', 'customer_id' => $cid, 'recharge_id' => (int) $r['id']];
                }
            }
            if ($radius) {
                $latest = ActivationStore::latest($pdo, $cid, ActivationState::COMPLETED);
                if ($latest !== null) {
                    $target = ActivationStore::radius($pdo, (int) $latest['id'])['target'];
                    try {
                        $current = is_array($target) ? RadiusState::read((string) $target['username'], (int) $latest['plan_id'], (string) $latest['reference']) : null;
                        if ($current === null || !RadiusState::sameSubscriber($current, $target)) {
                            $out[] = ['kind' => 'RADIUS_DRIFT', 'customer_id' => $cid, 'activation' => (string) $latest['reference'],
                                'differs' => $current === null ? ['not_captured'] : RadiusState::diff($current, $target)];
                        }
                    } catch (\Throwable $e) {
                        $out[] = ['kind' => 'RADIUS_DRIFT', 'customer_id' => $cid, 'activation' => (string) $latest['reference'], 'differs' => ['radius_unreadable']];
                    }
                }
            }
        }
        foreach (Tx::rows($pdo, 'SELECT p.id, p.user_id FROM tbl_payment_gateway p JOIN tbl_customers c ON c.id = p.user_id WHERE c.manager_id IS NOT NULL AND p.status = 2 ORDER BY p.id') as $p) {
            $out[] = ['kind' => 'PAYMENT_FOR_RESELLER_OWNED', 'payment_id' => (int) $p['id'], 'customer_id' => (int) $p['user_id']];
        }
        foreach (Tx::rows($pdo, 'SELECT id, id_plan, routers FROM tbl_voucher WHERE status = ? ORDER BY id', [VoucherRedemptionService::CLAIMED]) as $v) {
            $out[] = ['kind' => 'VOUCHER_CLAIMED', 'voucher_id' => (int) $v['id'], 'plan_id' => (int) $v['id_plan']];
        }
        $last = [];
        foreach (Tx::rows($pdo, "SELECT id, target_customer_id, after_json FROM tbl_reseller_audit_events WHERE event_type LIKE 'reprovision.%' ORDER BY id") as $a) {
            $after = json_decode((string) $a['after_json'], true);
            // WP8 A3 (FIX-1): a refusal changed nothing, so it never supersedes an earlier unresolved outcome
            if (is_array($after) && str_starts_with((string) ($after['outcome'] ?? ''), 'REFUSED_')) {
                continue;
            }
            $last[(int) $a['target_customer_id']] = is_array($after) ? ($after['outcome'] ?? null) : null;
        }
        foreach ($last as $cid => $outcome) {
            if ($outcome === LegacyReprovisionService::UNCONFIRMED) {
                $out[] = ['kind' => 'REPROVISION_UNCONFIRMED', 'customer_id' => $cid];
            }
        }
        // WP8 A3 (OD-A3R-5): a rename whose old-identity sessions are not confirmed ended, or whose confirmation event
        // was never written (the main re-provision audit announced it as PENDING)
        $old = [];
        foreach (Tx::rows($pdo, "SELECT target_customer_id, event_type, after_json FROM tbl_reseller_audit_events WHERE event_type LIKE 'reprovision.%' OR event_type = 'rename.old_session' ORDER BY id") as $a) {
            $after = json_decode((string) $a['after_json'], true);
            $state = is_array($after) ? ($after['old_session'] ?? null) : null;
            if ($a['event_type'] === 'rename.old_session') {
                $old[(int) $a['target_customer_id']] = $state;
            } elseif ($state === 'PENDING') {
                $old[(int) $a['target_customer_id']] = 'PENDING';
            }
        }
        foreach ($old as $cid => $state) {
            if ($state === 'PENDING' || $state === 'UNCONFIRMED') {
                $out[] = ['kind' => 'OLD_SESSION_UNCONFIRMED', 'customer_id' => $cid, 'state' => $state === 'PENDING' ? 'CONFIRMATION_MISSING' : 'UNCONFIRMED'];
            }
        }
        foreach (LegacyCustomerBalanceService::held() as $h) {
            $out[] = ['kind' => 'BALANCE_HELD', 'journal_id' => (int) $h['id'], 'customer_id' => (int) $h['user_id'], 'channel' => (string) $h['payment_channel']];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> AUTO_RENEW_UNAVAILABLE records (reseller-owned obligations not renewed) */
    public static function autoRenewUnavailable(): array
    {
        return array_map(static function (array $a): array {
            $after = json_decode((string) $a['after_json'], true);
            return ['customer_id' => (int) $a['target_customer_id'], 'obligation' => is_array($after) ? ($after['obligation'] ?? null) : null, 'at' => (string) $a['created_at']];
        }, Tx::rows(FinanceTx::pdo(), "SELECT target_customer_id, after_json, created_at FROM tbl_reseller_audit_events WHERE event_type = 'service.auto_renew_unavailable' ORDER BY id"));
    }
}
