<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Code-defined capability catalogue (WP2 §3). There is deliberately no database editor for
 * capabilities: changes are code changes and go through review.
 */
final class Capability
{
    // customers
    public const CUSTOMER_READ = 'customer.read';
    public const CUSTOMER_CREATE = 'customer.create';
    public const CUSTOMER_SYNC = 'customer.sync';
    public const CUSTOMER_UPDATE = 'customer.update';
    public const CUSTOMER_DEACTIVATE = 'customer.deactivate';
    public const CUSTOMER_DELETE = 'customer.delete';
    public const CUSTOMER_IMPERSONATE = 'customer.impersonate';
    public const CUSTOMER_EXPORT = 'customer.export';
    public const CUSTOMER_ASSIGN_SALES = 'customer.assign_sales';
    public const CUSTOMER_TRANSFER_OWNER = 'customer.transfer_owner';
    public const CUSTOMER_ASSIGN_LEGACY_OWNER = 'customer.assign_legacy_owner';
    // legacy service (OD-11: Agent/Sales hold none of these in WP2)
    public const SERVICE_RECHARGE = 'service.recharge';
    public const SERVICE_RECHARGE_FREE = 'service.recharge_free';
    public const SERVICE_REFILL_VOUCHER = 'service.refill_voucher';
    public const SERVICE_DEPOSIT = 'service.deposit';
    public const SERVICE_EXTEND = 'service.extend';
    public const SERVICE_PLAN_EDIT = 'service.plan_edit';
    public const SERVICE_SYNC_ALL = 'service.sync_all';
    // invoices / vouchers / messages / reports
    public const INVOICE_READ = 'invoice.read';
    public const INVOICE_SEND = 'invoice.send';
    public const VOUCHER_GENERATE = 'voucher.generate';
    public const VOUCHER_READ = 'voucher.read';
    public const VOUCHER_DELETE = 'voucher.delete';
    public const MESSAGE_SEND = 'message.send';
    public const MESSAGE_SEND_SELECTED = 'message.send_selected';
    public const MESSAGE_BULK = 'message.bulk';
    public const REPORT_READ = 'report.read';
    public const DASHBOARD_VIEW = 'dashboard.view';
    // staff / hierarchy
    public const STAFF_READ = 'staff.read';
    public const STAFF_CREATE = 'staff.create';
    public const STAFF_UPDATE_PROFILE = 'staff.update_profile';
    public const SELF_UPDATE_PROFILE = 'self.update_profile';
    public const SELF_CHANGE_PASSWORD = 'self.change_password';
    public const STAFF_CHANGE_ROLE = 'staff.change_role';
    public const STAFF_DISABLE = 'staff.disable';
    public const STAFF_DELETE = 'staff.delete';
    public const RESELLER_PLACE = 'reseller.place';
    public const RESELLER_MOVE = 'reseller.move';
    public const RESELLER_TREE_READ = 'reseller.tree_read';
    // system
    public const SYSTEM_ADMIN = 'system.admin';
    public const SYSTEM_CODE = 'system.code';
    public const SYSTEM_COUPONS = 'system.coupons';
    public const SYSTEM_BACKUP = 'system.backup';
    public const SYSTEM_RESTORE = 'system.restore';
    public const SYSTEM_UPDATE = 'system.update';
    public const API_ACCESS = 'api.access';
    // reseller finance (WP3A; owner decisions P2/P3): granted by RoleCapabilityMap, enforced by Reseller\Finance
    public const WALLET_READ = 'wallet.read';
    public const LEDGER_READ = 'ledger.read';
    public const WALLET_FUND_WITHDRAW = 'wallet.fund_withdraw';
    public const WALLET_TRANSFER = 'wallet.transfer';
    public const WALLET_DEBT_LIMIT = 'wallet.debt_limit';
    public const WALLET_ADJUST = 'wallet.adjust';
    public const WALLET_REVERSE = 'wallet.reverse';
    // reseller pricing (WP3B; owner decisions OD-3/OD-4): granted by RoleCapabilityMap, enforced by Reseller\Finance\PricingService
    public const PRICING_READ = 'pricing.read';
    public const PRICING_BASE_COST = 'pricing.base_cost';
    public const PRICING_CHILD_WHOLESALE = 'pricing.child_wholesale';
    public const PRICING_OWN_RETAIL = 'pricing.own_retail';
    public const PRICING_CHILD_AVAILABILITY = 'pricing.child_availability';
    // reseller activation (WP5A; owner decision A5): granted by RoleCapabilityMap, enforced by Reseller\Activation
    public const ACTIVATION_CREATE = 'activation.create';
    public const ACTIVATION_READ = 'activation.read';

    /** Capabilities defined for a later work package and never granted until then (none since WP5A). */
    public const RESERVED = [];

    /** Capabilities that change state: refused for a frozen, inactive or inconsistent principal. */
    public const MUTATING = [
        self::CUSTOMER_CREATE, self::CUSTOMER_SYNC, self::CUSTOMER_UPDATE, self::CUSTOMER_DEACTIVATE,
        self::CUSTOMER_DELETE, self::CUSTOMER_IMPERSONATE, self::CUSTOMER_ASSIGN_SALES,
        self::CUSTOMER_TRANSFER_OWNER, self::CUSTOMER_ASSIGN_LEGACY_OWNER,
        self::SERVICE_RECHARGE, self::SERVICE_RECHARGE_FREE, self::SERVICE_REFILL_VOUCHER, self::SERVICE_DEPOSIT,
        self::SERVICE_EXTEND, self::SERVICE_PLAN_EDIT, self::SERVICE_SYNC_ALL,
        self::INVOICE_SEND, self::VOUCHER_GENERATE, self::VOUCHER_DELETE,
        self::MESSAGE_SEND, self::MESSAGE_SEND_SELECTED, self::MESSAGE_BULK,
        self::STAFF_CREATE, self::STAFF_UPDATE_PROFILE, self::STAFF_CHANGE_ROLE, self::STAFF_DISABLE, self::STAFF_DELETE,
        self::RESELLER_PLACE, self::RESELLER_MOVE,
        self::SYSTEM_ADMIN, self::SYSTEM_CODE, self::SYSTEM_COUPONS, self::SYSTEM_BACKUP, self::SYSTEM_RESTORE, self::SYSTEM_UPDATE,
        self::WALLET_FUND_WITHDRAW, self::WALLET_TRANSFER, self::WALLET_DEBT_LIMIT, self::WALLET_ADJUST, self::WALLET_REVERSE,
        self::PRICING_BASE_COST, self::PRICING_CHILD_WHOLESALE, self::PRICING_OWN_RETAIL, self::PRICING_CHILD_AVAILABILITY,
        self::ACTIVATION_CREATE,
    ];

    /**
     * Capabilities that depend on the reseller ownership / hierarchy / audit schema. When the schema
     * is not ready (SchemaReadiness) they are refused for EVERY role, SuperAdmin included.
     */
    public const SCHEMA_DEPENDENT = [
        self::CUSTOMER_CREATE, self::CUSTOMER_DELETE, self::CUSTOMER_ASSIGN_SALES,
        self::CUSTOMER_TRANSFER_OWNER, self::CUSTOMER_ASSIGN_LEGACY_OWNER,
        self::STAFF_CREATE, self::STAFF_UPDATE_PROFILE, self::STAFF_CHANGE_ROLE, self::STAFF_DISABLE, self::STAFF_DELETE,
        self::RESELLER_PLACE, self::RESELLER_MOVE, self::RESELLER_TREE_READ,
        self::WALLET_READ, self::LEDGER_READ, self::WALLET_FUND_WITHDRAW, self::WALLET_TRANSFER,
        self::WALLET_DEBT_LIMIT, self::WALLET_ADJUST, self::WALLET_REVERSE,
        self::PRICING_READ, self::PRICING_BASE_COST, self::PRICING_CHILD_WHOLESALE, self::PRICING_OWN_RETAIL, self::PRICING_CHILD_AVAILABILITY,
        self::ACTIVATION_CREATE, self::ACTIVATION_READ,
    ];

    /** @return list<string> every defined capability */
    public static function all(): array
    {
        $out = [];
        foreach ((new \ReflectionClass(self::class))->getConstants() as $name => $value) {
            if (is_string($value) && str_contains($value, '.')) {
                $out[] = $value;
            }
        }
        return $out;
    }
}
