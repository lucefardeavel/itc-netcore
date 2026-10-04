<?php
namespace Reseller\Ui;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Activation\ActivationReadService;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\DataScope;
use Reseller\Security\PermissionService;

/**
 * WP6A dashboard foundation (OD-L): counts and short lists, always within the actor's authoritative scope
 * (customer.read DataScope, ActivationReadService visibility). Expiring = an active service ending within 7 days.
 * No wallet, ledger or report figures (WP6B).
 */
final class Dashboard
{
    public const EXPIRING_DAYS = 7;

    /** @return array<string,mixed> */
    public static function foundation(ActorContext $a): array
    {
        $out = ['customers' => null, 'activations' => null, 'expiring' => [], 'attention' => []];
        if (PermissionService::can($a, C::CUSTOMER_READ)) {
            $today = date('Y-m-d');
            $soon = date('Y-m-d', strtotime('+' . self::EXPIRING_DAYS . ' days'));
            $scoped = static fn(): \ORM => DataScope::scopeCustomers(\ORM::for_table('tbl_customers'), $a, C::CUSTOMER_READ);
            $on = "EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = tbl_customers.id AND sr.status = 'on' AND sr.expiration >= ?)";
            $out['customers'] = [
                'total' => $scoped()->count(),
                'active' => $scoped()->where_raw($on, [$today])->count(),
                'expiring' => $scoped()->where_raw("EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = tbl_customers.id AND sr.status = 'on' AND sr.expiration BETWEEN ? AND ?)", [$today, $soon])->count(),
                'expired' => $scoped()->where_raw("NOT {$on} AND EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = tbl_customers.id)", [$today])->count(),
            ];
            $out['expiring'] = $scoped()->select('tbl_customers.id')->select('tbl_customers.username')->select('tbl_customers.fullname')
                ->select('er.namebp', 'plan')->select('er.expiration', 'expiration')
                ->join('tbl_user_recharges', ['er.customer_id', '=', 'tbl_customers.id'], 'er')
                ->where('er.status', 'on')->where_gte('er.expiration', $today)->where_lte('er.expiration', $soon)
                ->order_by_asc('er.expiration')->limit(5)->find_array();
        }
        if (PermissionService::can($a, C::ACTIVATION_READ)) {
            try {
                $out['activations'] = ActivationReadService::counts($a);
                $attention = ActivationReadService::list($a, ['group' => 'attention'])['rows'];
                $processing = ActivationReadService::list($a, ['group' => 'processing'])['rows'];
                $out['attention'] = array_slice(array_merge($attention, $processing), 0, 5);
            } catch (\Reseller\Domain\DomainError $e) {
                $out['activations'] = null; // e.g. schema not ready: the tile is omitted, never guessed
            }
        }
        return $out;
    }
}
