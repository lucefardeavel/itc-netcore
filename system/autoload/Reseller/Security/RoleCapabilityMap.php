<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\Capability as C;

/**
 * Reviewed role → capability → scope map (WP2 §3 with owner decisions OD-4, OD-7, OD-10, OD-11,
 * OD-12, OD-13). Anything not listed is denied.
 *
 * Scopes:  G  global
 *          T  own subtree (closure, self included)
 *          D  strict descendants (closure depth > 0)
 *          C  direct children only (closure depth = 1)
 *          A  customers explicitly assigned to self (Sales, C21)
 *          S  self only
 */
final class RoleCapabilityMap
{
    public const SCOPES = ['G', 'T', 'D', 'C', 'A', 'S'];

    private const ADMIN_COMMON = [
        C::CUSTOMER_READ => 'G', C::CUSTOMER_CREATE => 'G', C::CUSTOMER_SYNC => 'G', C::CUSTOMER_UPDATE => 'G',
        C::CUSTOMER_DEACTIVATE => 'G', C::CUSTOMER_DELETE => 'G', C::CUSTOMER_IMPERSONATE => 'G', C::CUSTOMER_EXPORT => 'G',
        C::CUSTOMER_ASSIGN_SALES => 'G', C::CUSTOMER_TRANSFER_OWNER => 'G', C::CUSTOMER_ASSIGN_LEGACY_OWNER => 'G',
        C::SERVICE_RECHARGE => 'G', C::SERVICE_RECHARGE_FREE => 'G', C::SERVICE_REFILL_VOUCHER => 'G', C::SERVICE_DEPOSIT => 'G',
        C::SERVICE_EXTEND => 'G', C::SERVICE_PLAN_EDIT => 'G', C::SERVICE_SYNC_ALL => 'G',
        C::INVOICE_READ => 'G', C::INVOICE_SEND => 'G',
        C::VOUCHER_GENERATE => 'G', C::VOUCHER_READ => 'G', C::VOUCHER_DELETE => 'G',
        C::MESSAGE_SEND => 'G', C::MESSAGE_SEND_SELECTED => 'G', C::MESSAGE_BULK => 'G',
        C::REPORT_READ => 'G', C::DASHBOARD_VIEW => 'G',
        C::STAFF_READ => 'G', C::STAFF_CREATE => 'G', C::STAFF_UPDATE_PROFILE => 'G',
        C::SELF_UPDATE_PROFILE => 'S', C::SELF_CHANGE_PASSWORD => 'S',
        C::STAFF_CHANGE_ROLE => 'G', C::STAFF_DISABLE => 'G', C::STAFF_DELETE => 'G',
        C::RESELLER_PLACE => 'G', C::RESELLER_MOVE => 'G', C::RESELLER_TREE_READ => 'G',
        C::SYSTEM_ADMIN => 'G', C::SYSTEM_COUPONS => 'G', C::API_ACCESS => 'S',
        // WP3A finance (P2): provider funding/withdrawal, debt limit, adjustment and reversal are
        // Admin/SuperAdmin only; parent -> direct-child transfers keep the structural direct-child rule
        C::WALLET_READ => 'G', C::LEDGER_READ => 'G', C::WALLET_FUND_WITHDRAW => 'G', C::WALLET_DEBT_LIMIT => 'G',
        C::WALLET_ADJUST => 'G', C::WALLET_REVERSE => 'G', C::WALLET_TRANSFER => 'G',
        // WP3B pricing: provider base cost / resale and every reseller's wholesale, retail (OD-4) and
        // availability (OD-3) for administrative pricing; the structural parent/child rules still apply
        C::PRICING_READ => 'G', C::PRICING_BASE_COST => 'G', C::PRICING_CHILD_WHOLESALE => 'G',
        C::PRICING_OWN_RETAIL => 'G', C::PRICING_CHILD_AVAILABILITY => 'G',
        // WP5A activation (A5): any reseller-owned customer; the owner's wallet is always the one debited
        C::ACTIVATION_CREATE => 'G', C::ACTIVATION_READ => 'G',
    ];

    /** @var array<string,array<string,string>> */
    private const MAP = [
        'SuperAdmin' => self::ADMIN_COMMON + [
            C::SYSTEM_CODE => 'G', C::SYSTEM_BACKUP => 'G', C::SYSTEM_RESTORE => 'G',
        ],
        // OD-12: no code-execution surfaces, no backup/restore; staff targets limited in StaffPolicy
        'Admin' => self::ADMIN_COMMON,
        'Agent' => [
            C::CUSTOMER_READ => 'T', C::CUSTOMER_CREATE => 'T', C::CUSTOMER_ASSIGN_SALES => 'T',
            C::INVOICE_READ => 'T', C::INVOICE_SEND => 'T', C::VOUCHER_READ => 'T',
            C::MESSAGE_SEND => 'T', C::MESSAGE_SEND_SELECTED => 'T',
            C::DASHBOARD_VIEW => 'S',
            C::STAFF_READ => 'T', C::STAFF_CREATE => 'C', C::STAFF_UPDATE_PROFILE => 'D', C::STAFF_DISABLE => 'D',
            C::RESELLER_TREE_READ => 'T',
            C::SELF_UPDATE_PROFILE => 'S', C::SELF_CHANGE_PASSWORD => 'S', C::API_ACCESS => 'S',
            // WP3A finance (P3): own + subtree visibility; transfer from own wallet to a direct child only
            C::WALLET_READ => 'T', C::LEDGER_READ => 'T', C::WALLET_TRANSFER => 'C',
            // WP3B pricing: own + subtree visibility; wholesale and availability of direct children only
            // (never its own wholesale); its own retail price only
            C::PRICING_READ => 'T', C::PRICING_CHILD_WHOLESALE => 'C', C::PRICING_CHILD_AVAILABILITY => 'C', C::PRICING_OWN_RETAIL => 'S',
            // WP5A activation (A5): creates only for customers it owns itself (S = own wallet, never a
            // descendant's); reads its whole subtree
            C::ACTIVATION_CREATE => 'S', C::ACTIVATION_READ => 'T',
        ],
        'Sales' => [
            C::CUSTOMER_READ => 'A', C::CUSTOMER_CREATE => 'A',
            C::INVOICE_READ => 'A', C::INVOICE_SEND => 'A', C::VOUCHER_READ => 'S',
            C::MESSAGE_SEND => 'A', C::MESSAGE_SEND_SELECTED => 'A',
            C::DASHBOARD_VIEW => 'S', C::STAFF_READ => 'S',
            C::SELF_UPDATE_PROFILE => 'S', C::SELF_CHANGE_PASSWORD => 'S', C::API_ACCESS => 'S',
            // WP5A activation (A5): customers explicitly assigned to the Sales account, on its parent's wallet
            C::ACTIVATION_CREATE => 'A', C::ACTIVATION_READ => 'A',
        ],
        // OD-4: login, an empty dashboard, own profile/password, logout — no data access
        'Report' => [
            C::DASHBOARD_VIEW => 'S', C::STAFF_READ => 'S',
            C::SELF_UPDATE_PROFILE => 'S', C::SELF_CHANGE_PASSWORD => 'S', C::API_ACCESS => 'S',
        ],
    ];

    public const ROLES = ['SuperAdmin', 'Admin', 'Agent', 'Sales', 'Report'];

    /** Raw scope for role/capability, or null when not granted. Reserved capabilities are never granted. */
    public static function scope(?string $role, string $capability): ?string
    {
        if ($role === null || in_array($capability, C::RESERVED, true)) {
            return null;
        }
        return self::MAP[$role][$capability] ?? null;
    }

    /** @return array<string,array<string,string>> */
    public static function map(): array
    {
        return self::MAP;
    }
}
