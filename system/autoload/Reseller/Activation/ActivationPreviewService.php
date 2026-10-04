<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\Tx;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\LockedWallet;
use Reseller\Finance\Money;
use Reseller\Finance\PricingService;
use Reseller\Finance\PricingSnapshot;
use Reseller\Finance\WalletService;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;

/**
 * WP6A (owner decision OD-B): the live, server-calculated activation confirmation. Read-only: it never writes and
 * holds no authority; ActivationService::create re-derives everything and refuses a changed quote
 * (ACTIVATION_PREVIEW_CHANGED) when the confirm carries this quote's fingerprint.
 *
 * The values come from the current authoritative state in one tree-locked transaction: the pricing resolution
 * (PricingService::resolveInTx, the snapshot the activation would seal) and the paying owner's locked wallet
 * (balance + debt limit - ACTIVE reservations, the figure the reservation checks).
 *
 * Visibility (activation.create scope):
 *  - G (Admin / SuperAdmin): customer, plan, action, current and projected expiry, wholesale, retail, the paying
 *    owner, available credit before and after;
 *  - S (Agent, own customers, own wallet): the same, for its own wallet;
 *  - A (Sales): customer, plan, action, expiries, retail and "credit sufficient: yes / no" only. Never wholesale,
 *    balance, reservations, debt limit or an available amount.
 */
final class ActivationPreviewService
{
    /** @return array<string,mixed> */
    public static function quote(ActorContext $a, mixed $customerId, mixed $planId, string $key): array
    {
        // the accepted preview is the authorization gate: eligibility, scope, catalog / saleability with redacted reasons
        ActivationService::preview($a, $customerId, $planId);
        $cid = (int) $customerId;
        $pid = (int) $planId;
        $scope = PermissionService::scopeOf($a, C::ACTIVATION_CREATE);
        try {
            $q = FinanceTx::run(static function (\PDO $pdo) use ($a, $cid, $pid): array {
                $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [$cid]) ?? throw new DomainError('NOT_FOUND');
                $manager = ActivationService::payerOf($a, $customer);
                $plan = Tx::row($pdo, 'SELECT * FROM tbl_plans WHERE id = ?', [$pid]) ?? throw new DomainError('NOT_FOUND');
                $now = ActivationService::now();
                $current = LegacyBridge::currentRecharge($pdo, $cid, (string) $plan['type']);
                $action = ActivationService::deriveAction($current, $pid, $now);
                $day = ActivationService::expiredDay($pdo, $cid, $plan);
                [$start, $expiry] = ServicePeriod::compute($action, $plan, $action === 'RENEW' ? $current : null, $now, $day);
                $snap = PricingService::resolveInTx($pdo, $manager, $pid);
                $wallet = LockedWallet::lock($pdo, $manager);
                $available = $wallet !== null ? WalletService::available($wallet, WalletService::activeReservedLocked($pdo, $wallet)) : Money::zero();
                $payer = Tx::row($pdo, 'SELECT username FROM tbl_users WHERE id = ?', [$manager]);
                return [
                    'customer' => $customer, 'plan' => $plan, 'action' => $action, 'current' => $current, 'start' => $start, 'expiry' => $expiry,
                    'snap' => $snap, 'available' => $available, 'payer' => (string) ($payer['username'] ?? ''),
                    'material' => self::material($cid, $pid, $manager, $action, $current, $plan, $snap, $day),
                ];
            }, true);
        } catch (DomainError $e) {
            // as the accepted preview: Agent / Sales never learn why a plan is not offered
            throw new DomainError($scope === 'G' ? $e->reason : 'NOT_FOUND');
        }
        $snap = $q['snap'];
        $after = $q['available']->minus($snap->leafDebit);
        $out = [
            'customer_id' => $cid, 'customer_username' => (string) $q['customer']['username'], 'customer_fullname' => (string) $q['customer']['fullname'],
            'plan_id' => $pid, 'plan_name' => (string) $q['plan']['name_plan'], 'plan_type' => (string) $q['plan']['type'],
            'validity' => (int) $q['plan']['validity'], 'validity_unit' => (string) $q['plan']['validity_unit'],
            'action' => $q['action'],
            'current_expiry' => $q['current'] !== null ? trim((string) $q['current']['expiration'] . ' ' . (string) $q['current']['time']) : null,
            'current_plan_id' => $q['current'] !== null ? (int) $q['current']['plan_id'] : null,
            'start' => $q['start']->format('Y-m-d H:i:s'), 'expiry' => $q['expiry']->format('Y-m-d H:i:s'),
            'retail' => $snap->retail->format(),
            'credit_sufficient' => !$after->isNegative(),
            'fingerprint' => PreviewBinding::fingerprint($q['material'], $key),
            'quoted_at' => ActivationService::now()->format('Y-m-d H:i:s'),
            'full' => in_array($scope, ['G', 'S'], true),
        ];
        if ($out['full']) {
            $out['wholesale'] = $snap->leafDebit->format();
            $out['available_before'] = $q['available']->format();
            $out['available_after'] = $after->format();
            $out['payer'] = $q['payer'];
        }
        return $out;
    }

    /**
     * The material quote: what is bought, from whom and for how much, and the basis of the service period. It is
     * compared with the confirmed fingerprint (HMAC) inside ActivationService::open. Wall-clock time is deliberately
     * not part of it (start / expiry of NEW move with the clock); the available credit is not either (the reservation
     * re-checks it authoritatively and refuses an insufficient credit).
     *
     * @param array<string,mixed>|null $current
     * @param array<string,mixed>      $plan
     */
    public static function material(int $customerId, int $planId, int $manager, string $action, ?array $current, array $plan, PricingSnapshot $snap, int $day): string
    {
        return (string) json_encode([
            'v' => 1, 'customer' => $customerId, 'plan' => $planId, 'payer' => $manager, 'action' => $action,
            'wholesale' => $snap->leafDebit->format(), 'retail' => $snap->retail->format(),
            'current' => $current === null ? null : [(int) $current['id'], (int) $current['plan_id'], (string) $current['expiration'], (string) $current['time'], (string) $current['status']],
            'period' => [(string) $plan['type'], (string) $plan['validity'], (string) $plan['validity_unit'], $day],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
