<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\Capability as C;

/**
 * Central route authorization map (WP2 §5 / §9). Every (controller, action[, variant]) reachable
 * through index.php or system/api.php must have an entry; anything unmapped is denied for every role,
 * SuperAdmin included. A completeness test compares this map with the actions found in
 * system/controllers/*.php.
 *
 * Rule values:
 *   'public'    no authentication required (login, register, forgot, callbacks; the flow checks itself)
 *   'customer'  customer portal; the controller's own customer authentication applies
 *   'staff'     any authenticated, active staff principal (dashboard, own profile, logout)
 *   'plugin'    plugin/{fn}: registered and authorized plugin menu functions only (OD-9)
 *   'deny'      disabled for everyone
 *   '<cap>'     authenticated staff holding the capability (PermissionService)
 *
 * An entry is either a rule string (GET and POST accepted) or [METHOD => rule]. An action whose entry
 * has no 'GET' key is a mutation: GET/HEAD requests to it are refused with 405 and no side effect.
 * '' names the action used for an empty action segment, '*' the action used for unknown actions
 * (each controller's real default branch).
 */
final class RouteMap
{
    public const GET_POST = ['GET', 'POST'];

    /** @var array<string,array<string,string|array<string,string>>> */
    public const ROUTES = [
        // ---------- public / session ----------
        'admin' => ['' => 'login-display', '*' => 'login-display', 'login-display' => 'public', 'post' => ['POST' => 'public']],
        'login' => ['' => 'login-display', '*' => 'login-display', 'login-display' => 'public', 'post' => ['POST' => 'public'], 'activation' => ['POST' => 'public']],
        'register' => ['' => 'register-display', '*' => 'register-display', 'register-display' => 'public', 'post' => ['POST' => 'public']],
        'forgot' => ['' => 'forgot', '*' => 'forgot', 'forgot' => 'public'],
        'logout' => ['' => 'logout', '*' => 'logout', 'logout' => 'public'],
        'callback' => ['' => 'notify', '*' => 'notify', 'notify' => 'public'],
        'plugin' => ['' => 'dispatch', '*' => 'dispatch', 'dispatch' => 'plugin'],

        // ---------- customer portal ----------
        'home' => [
            '' => 'dashboard', '*' => 'dashboard', 'dashboard' => 'customer',
            'dashboard#renewal' => ['POST' => 'customer'], 'dashboard#sync' => ['POST' => 'customer'],
            'dashboard#extend' => ['POST' => 'customer'], 'dashboard#deactivate' => ['POST' => 'customer'],
        ],
        'accounts' => [
            '' => '404', '*' => '404', '404' => 'customer',
            'change-password' => 'customer', 'change-password-post' => ['POST' => 'customer'],
            'profile' => 'customer', 'edit-profile-post' => ['POST' => 'customer'],
            'phone-update' => 'customer', 'phone-update-otp' => ['POST' => 'customer'], 'phone-update-post' => ['POST' => 'customer'],
            'email-update' => 'customer', 'email-update-otp' => ['POST' => 'customer'], 'email-update-post' => ['POST' => 'customer'],
            'language-update-post' => ['POST' => 'customer'],
        ],
        'autoload_user' => ['' => 'none', '*' => 'none', 'none' => 'customer', 'isLogin' => 'customer', 'bw_name' => 'customer', 'inbox_unread' => 'customer', 'inbox' => 'customer', 'language' => 'customer'],
        'mail' => ['' => 'list', '*' => 'list', 'list' => 'customer', 'view' => 'customer', 'delete' => ['POST' => 'customer']],
        'order' => [
            '' => 'package', '*' => 'package',
            'voucher' => 'customer', 'history' => 'customer', 'balance' => 'customer', 'package' => 'customer',
            'unpaid' => 'customer', 'view' => 'customer', 'send' => 'customer', 'gateway' => 'customer',
            // WP5B §7 / §7a: purchases and payment-state changes are POST (+ CSRF) only
            'pay' => ['POST' => 'customer'], 'buy' => ['POST' => 'customer'],
            'view#check' => ['POST' => 'customer'], 'view#cancel' => ['POST' => 'customer'],
        ],
        'page' => ['' => 'view', '*' => 'view', 'view' => 'customer'],
        'voucher' => ['' => '404', '*' => '404', '404' => 'customer', 'activation' => 'customer', 'activation-post' => ['POST' => 'customer'], 'list-activated' => 'customer', 'invoice' => 'customer'],

        // ---------- staff: dashboard / search ----------
        'dashboard' => ['' => 'view', '*' => 'view', 'view' => C::DASHBOARD_VIEW, 'view#refresh' => ['POST' => C::SYSTEM_ADMIN]],
        'search_user' => ['' => 'search', '*' => 'search', 'search' => C::CUSTOMER_READ],

        // ---------- customers ----------
        'customers' => [
            '' => 'list', '*' => 'list',
            'list' => C::CUSTOMER_READ,
            'csv' => ['POST' => C::CUSTOMER_EXPORT], 'csv-prepaid' => ['POST' => C::CUSTOMER_EXPORT],
            'add' => ['GET' => C::CUSTOMER_CREATE], 'add-post' => ['POST' => C::CUSTOMER_CREATE],
            'view' => C::CUSTOMER_READ, 'viewu' => C::CUSTOMER_READ,
            'edit' => ['GET' => C::CUSTOMER_UPDATE], 'edit#deletePhoto' => ['POST' => C::CUSTOMER_UPDATE],
            'edit-post' => ['POST' => C::CUSTOMER_UPDATE],
            'sync' => ['POST' => C::CUSTOMER_SYNC],
            'recharge' => ['GET' => C::SERVICE_RECHARGE],
            'deactivate' => ['POST' => C::CUSTOMER_DEACTIVATE], 'delete' => ['POST' => C::CUSTOMER_DELETE],
            'login' => ['POST' => C::CUSTOMER_IMPERSONATE],
        ],

        // ---------- plan / service / vouchers / invoices ----------
        'plan' => [
            '' => 'list', '*' => 'list',
            'list' => C::CUSTOMER_READ,
            'sync' => ['POST' => C::SERVICE_SYNC_ALL],
            'recharge' => ['GET' => C::SERVICE_RECHARGE], 'recharge-confirm' => ['POST' => C::SERVICE_RECHARGE],
            'recharge-post' => ['POST' => C::SERVICE_RECHARGE],
            'view' => ['GET' => C::INVOICE_READ], 'view#send' => ['POST' => C::INVOICE_SEND],
            'print' => C::INVOICE_READ,
            'edit' => ['GET' => C::SERVICE_PLAN_EDIT], 'edit-post' => ['POST' => C::SERVICE_PLAN_EDIT],
            'delete' => ['POST' => C::SERVICE_PLAN_EDIT],
            'voucher' => C::VOUCHER_READ, 'voucher-view' => ['GET' => C::VOUCHER_READ], 'print-voucher' => C::VOUCHER_READ,
            'add-voucher' => ['GET' => C::VOUCHER_GENERATE], 'voucher-post' => ['POST' => C::VOUCHER_GENERATE],
            'remove-voucher' => ['POST' => C::VOUCHER_DELETE], 'voucher-delete' => ['POST' => C::VOUCHER_DELETE],
            'voucher-delete-many' => ['POST' => C::VOUCHER_DELETE],
            'refill' => ['GET' => C::SERVICE_REFILL_VOUCHER], 'refill-post' => ['POST' => C::SERVICE_REFILL_VOUCHER],
            'deposit' => ['GET' => C::SERVICE_DEPOSIT], 'deposit-post' => ['POST' => C::SERVICE_DEPOSIT],
            'extend' => ['POST' => C::SERVICE_EXTEND],
        ],

        // ---------- messages / reports ----------
        'message' => [
            '' => 'send', '*' => 'send',
            'send' => ['GET' => C::MESSAGE_SEND], 'send-post' => ['POST' => C::MESSAGE_SEND],
            'send_bulk' => ['GET' => C::MESSAGE_BULK], 'send_bulk_ajax' => ['POST' => C::MESSAGE_BULK],
            'send_bulk_selected' => ['POST' => C::MESSAGE_SEND_SELECTED],
        ],
        'reports' => [
            '' => 'daily-report', '*' => 'daily-report',
            'daily-report' => C::REPORT_READ, 'by-date' => C::REPORT_READ, 'activation' => C::REPORT_READ,
            'by-period' => C::REPORT_READ, 'period-view' => C::REPORT_READ, 'ajax' => C::REPORT_READ,
        ],
        'export' => [
            '' => '404', '*' => '404', '404' => C::REPORT_READ,
            'print-by-date' => C::REPORT_READ, 'pdf-by-date' => C::REPORT_READ,
            'print-by-period' => C::REPORT_READ, 'pdf-by-period' => C::REPORT_READ,
        ],
        'maps' => ['' => 'customer', '*' => 'customer', 'customer' => C::CUSTOMER_READ, 'routers' => C::SYSTEM_ADMIN, 'odp' => C::SYSTEM_ADMIN],
        'autoload' => [
            '' => '404', '*' => '404', '404' => C::CUSTOMER_READ,
            'pool' => C::SYSTEM_ADMIN, 'bw_name' => C::CUSTOMER_READ, 'balance' => C::SERVICE_RECHARGE,
            'server' => C::CUSTOMER_READ, 'plan' => C::CUSTOMER_READ,
            'pppoe_ip_used' => C::CUSTOMER_READ, 'pppoe_username_used' => C::CUSTOMER_READ,
            'customer_is_active' => C::CUSTOMER_READ, 'plan_is_active' => C::CUSTOMER_READ, 'customer_select2' => C::CUSTOMER_READ,
        ],

        // ---------- staff / settings ----------
        'settings' => [
            '' => '404', '*' => '404', '404' => C::SYSTEM_ADMIN,
            'docs' => C::SYSTEM_ADMIN, 'devices' => C::SYSTEM_ADMIN,
            'app' => ['GET' => C::SYSTEM_ADMIN], 'app#test' => ['POST' => C::SYSTEM_ADMIN], 'app-post' => ['POST' => C::SYSTEM_ADMIN],
            'localisation' => ['GET' => C::SYSTEM_ADMIN], 'localisation-post' => ['POST' => C::SYSTEM_ADMIN],
            'users' => C::STAFF_READ, 'users-add' => ['GET' => C::STAFF_CREATE], 'users-post' => ['POST' => C::STAFF_CREATE],
            'users-view' => ['GET' => 'staff'], 'users-edit' => ['GET' => 'staff'],
            'users-edit#deletePhoto' => ['POST' => 'staff'], 'users-edit-post' => ['POST' => 'staff'],
            'users-delete' => ['POST' => C::STAFF_DELETE],
            'change-password' => ['GET' => C::SELF_CHANGE_PASSWORD], 'change-password-post' => ['POST' => C::SELF_CHANGE_PASSWORD],
            'notifications' => ['GET' => C::SYSTEM_ADMIN], 'notifications-post' => ['POST' => C::SYSTEM_ADMIN],
            'dbstatus' => ['GET' => C::SYSTEM_ADMIN],
            'dbbackup' => ['POST' => C::SYSTEM_BACKUP], 'dbrestore' => ['POST' => C::SYSTEM_RESTORE],
            'language' => ['GET' => C::SYSTEM_ADMIN], 'lang-post' => ['POST' => C::SYSTEM_ADMIN],
            'maintenance' => C::SYSTEM_ADMIN, 'miscellaneous' => C::SYSTEM_ADMIN,
        ],
        // WP5A reseller activation (owner decision A7): minimal staff flow; reconcile / rollback are further
        // restricted to Admin / SuperAdmin inside Reseller\Activation\ReconciliationService
        'activation' => [
            '' => 'view', '*' => 'view',
            'create' => ['GET' => C::ACTIVATION_CREATE],
            'create-post' => ['POST' => C::ACTIVATION_CREATE],
            'view' => ['GET' => C::ACTIVATION_READ],
            // WP6A (owner decision OD-C): read-only activation list (ActivationReadService visibility)
            'list' => ['GET' => C::ACTIVATION_READ],
            'reconcile' => ['POST' => C::ACTIVATION_CREATE],
            'rollback' => ['POST' => C::ACTIVATION_CREATE],
            // WP5B (OD-11): reversal of a COMPLETED activation; Admin / SuperAdmin only (activation.reverse G)
            'reverse' => ['POST' => C::ACTIVATION_REVERSE],
        ],
        // WP6B (owner decision OD-1): the finance web surface through Reseller\Backoffice; web only (never API_ALLOWED);
        // reads are scoped again by the Finance read models (wallet.read / ledger.read: G Admin, T Agent)
        'finance' => [
            '' => 'overview', '*' => 'overview',
            'overview' => ['GET' => C::WALLET_READ],
            'wallets' => ['GET' => C::WALLET_READ],
            'wallet' => ['GET' => C::WALLET_READ],
            'ledger' => ['GET' => C::LEDGER_READ],
            'entry' => ['GET' => C::LEDGER_READ],
            'transfers' => ['GET' => C::LEDGER_READ],
            'transfer' => ['GET' => C::LEDGER_READ],
            // review (no write) -> execute through the accepted TransferService (OD-1 / plan §8)
            'funding' => ['GET' => C::WALLET_FUND_WITHDRAW],
            'funding-review' => ['POST' => C::WALLET_FUND_WITHDRAW],
            'funding-post' => ['POST' => C::WALLET_FUND_WITHDRAW],
            'send' => ['GET' => C::WALLET_TRANSFER],
            'send-review' => ['POST' => C::WALLET_TRANSFER],
            'send-post' => ['POST' => C::WALLET_TRANSFER],
            // administrative accounting corrections, Admin / SuperAdmin only (OD-2)
            'debt-limit' => ['GET' => C::WALLET_DEBT_LIMIT],
            'debt-limit-review' => ['POST' => C::WALLET_DEBT_LIMIT],
            'debt-limit-post' => ['POST' => C::WALLET_DEBT_LIMIT],
            'adjust' => ['GET' => C::WALLET_ADJUST],
            'adjust-review' => ['POST' => C::WALLET_ADJUST],
            'adjust-post' => ['POST' => C::WALLET_ADJUST],
            'reverse' => ['GET' => C::WALLET_REVERSE],
            'reverse-review' => ['POST' => C::WALLET_REVERSE],
            'reverse-post' => ['POST' => C::WALLET_REVERSE],
            // finance reports from immutable history (settlement: the read model requires the global scope)
            'sales' => ['GET' => C::LEDGER_READ],
            'commissions' => ['GET' => C::LEDGER_READ],
            'settlement' => ['GET' => C::LEDGER_READ],
            'performance' => ['GET' => C::WALLET_READ],
        ],
        // WP6B (owner decisions OD-1 / OD-9): the pricing web surface through Reseller\Backoffice; web only; reads are
        // PricingReadModel projections; the setters are the accepted PricingService methods
        'pricing' => [
            '' => 'plans', '*' => 'plans',
            'plans' => ['GET' => C::PRICING_READ],
            'plan' => ['GET' => C::PRICING_READ],
            'catalog' => ['GET' => C::PRICING_READ],
            'base-cost-post' => ['POST' => C::PRICING_BASE_COST],
            'resale-post' => ['POST' => C::PRICING_BASE_COST],
            'wholesale-post' => ['POST' => C::PRICING_CHILD_WHOLESALE],
            'retail-post' => ['POST' => C::PRICING_OWN_RETAIL],
            'availability-post' => ['POST' => C::PRICING_CHILD_AVAILABILITY],
        ],
        'reseller' => [
            '' => 'tree', '*' => 'tree',
            'tree' => ['GET' => C::RESELLER_TREE_READ],
            // WP6B: reseller detail (web only; never in API_ALLOWED)
            'detail' => ['GET' => C::RESELLER_TREE_READ],
            'place-post' => ['POST' => C::RESELLER_PLACE],
            'move-post' => ['POST' => C::RESELLER_MOVE],
            'status-post' => ['POST' => C::STAFF_DISABLE],
            'customer-assign-sales' => ['POST' => C::CUSTOMER_ASSIGN_SALES],
            'customer-clear-sales' => ['POST' => C::CUSTOMER_ASSIGN_SALES],
            'customer-transfer' => ['POST' => C::CUSTOMER_TRANSFER_OWNER],
            'customer-assign-owner' => ['POST' => C::CUSTOMER_ASSIGN_LEGACY_OWNER],
        ],

        // ---------- system administration (SuperAdmin/Admin) ----------
        'bandwidth' => [
            '' => 'list', '*' => 'list', 'list' => C::SYSTEM_ADMIN, 'add' => ['GET' => C::SYSTEM_ADMIN], 'add-post' => ['POST' => C::SYSTEM_ADMIN],
            'edit' => ['GET' => C::SYSTEM_ADMIN], 'edit-post' => ['POST' => C::SYSTEM_ADMIN], 'delete' => ['POST' => C::SYSTEM_ADMIN],
        ],
        'routers' => [
            '' => 'list', '*' => 'list', 'list' => C::SYSTEM_ADMIN, 'add' => ['GET' => C::SYSTEM_ADMIN], 'add-post' => ['POST' => C::SYSTEM_ADMIN],
            'edit' => ['GET' => C::SYSTEM_ADMIN], 'edit-post' => ['POST' => C::SYSTEM_ADMIN], 'delete' => ['POST' => C::SYSTEM_ADMIN],
        ],
        'odp' => [
            '' => 'list', '*' => 'list', 'list' => C::SYSTEM_ADMIN, 'add' => ['GET' => C::SYSTEM_ADMIN], 'add-post' => ['POST' => C::SYSTEM_ADMIN],
            'edit' => ['GET' => C::SYSTEM_ADMIN], 'edit-post' => ['POST' => C::SYSTEM_ADMIN], 'delete' => ['POST' => C::SYSTEM_ADMIN],
        ],
        'radius' => [
            '' => 'nas-list', '*' => 'nas-list', 'nas-list' => C::SYSTEM_ADMIN,
            'nas-add' => ['GET' => C::SYSTEM_ADMIN], 'nas-add-post' => ['POST' => C::SYSTEM_ADMIN],
            'nas-edit' => ['GET' => C::SYSTEM_ADMIN], 'nas-edit-post' => ['POST' => C::SYSTEM_ADMIN], 'nas-delete' => ['POST' => C::SYSTEM_ADMIN],
        ],
        'pool' => [
            '' => 'list', '*' => 'list', 'list' => C::SYSTEM_ADMIN, 'add' => ['GET' => C::SYSTEM_ADMIN], 'add-post' => ['POST' => C::SYSTEM_ADMIN],
            'edit' => ['GET' => C::SYSTEM_ADMIN], 'edit-post' => ['POST' => C::SYSTEM_ADMIN], 'delete' => ['POST' => C::SYSTEM_ADMIN],
            'sync' => ['POST' => C::SYSTEM_ADMIN], 'port' => C::SYSTEM_ADMIN, 'add-port' => ['GET' => C::SYSTEM_ADMIN],
            'add-port-post' => ['POST' => C::SYSTEM_ADMIN], 'edit-port' => ['GET' => C::SYSTEM_ADMIN],
            'edit-port-post' => ['POST' => C::SYSTEM_ADMIN], 'delete-port' => ['POST' => C::SYSTEM_ADMIN],
        ],
        'services' => [
            '' => 'hotspot', '*' => 'hotspot',
            'hotspot' => C::SYSTEM_ADMIN, 'add' => ['GET' => C::SYSTEM_ADMIN], 'add-post' => ['POST' => C::SYSTEM_ADMIN],
            'edit' => ['GET' => C::SYSTEM_ADMIN], 'edit-post' => ['POST' => C::SYSTEM_ADMIN], 'delete' => ['POST' => C::SYSTEM_ADMIN],
            'pppoe' => C::SYSTEM_ADMIN, 'pppoe-add' => ['GET' => C::SYSTEM_ADMIN], 'pppoe-add-post' => ['POST' => C::SYSTEM_ADMIN],
            'pppoe-edit' => ['GET' => C::SYSTEM_ADMIN], 'edit-pppoe-post' => ['POST' => C::SYSTEM_ADMIN], 'pppoe-delete' => ['POST' => C::SYSTEM_ADMIN],
            'vpn' => C::SYSTEM_ADMIN, 'vpn-add' => ['GET' => C::SYSTEM_ADMIN], 'vpn-add-post' => ['POST' => C::SYSTEM_ADMIN],
            'vpn-edit' => ['GET' => C::SYSTEM_ADMIN], 'edit-vpn-post' => ['POST' => C::SYSTEM_ADMIN], 'vpn-delete' => ['POST' => C::SYSTEM_ADMIN],
            'balance' => C::SYSTEM_ADMIN, 'balance-add' => ['GET' => C::SYSTEM_ADMIN], 'balance-add-post' => ['POST' => C::SYSTEM_ADMIN],
            'balance-edit' => ['GET' => C::SYSTEM_ADMIN], 'balance-edit-post' => ['POST' => C::SYSTEM_ADMIN], 'balance-delete' => ['POST' => C::SYSTEM_ADMIN],
            'sync' => ['POST' => C::SYSTEM_ADMIN],
        ],
        'logs' => [
            '' => 'list', '*' => 'list',
            'list' => C::SYSTEM_ADMIN, 'list-csv' => C::SYSTEM_ADMIN, 'radius' => C::SYSTEM_ADMIN, 'radius-csv' => C::SYSTEM_ADMIN,
            'message' => C::SYSTEM_ADMIN, 'message-csv' => C::SYSTEM_ADMIN,
        ],
        'customfield' => ['' => 'list', '*' => 'list', 'list' => ['GET' => C::SYSTEM_ADMIN], 'save' => ['POST' => C::SYSTEM_ADMIN]],
        'community' => ['' => 'view', '*' => 'view', 'view' => ['GET' => C::SYSTEM_ADMIN]],
        'coupons' => [
            '' => 'list', '*' => 'list', 'list' => C::SYSTEM_COUPONS,
            'add' => ['GET' => C::SYSTEM_COUPONS], 'add-post' => ['POST' => C::SYSTEM_COUPONS],
            'edit' => ['GET' => C::SYSTEM_COUPONS], 'edit-post' => ['POST' => C::SYSTEM_COUPONS],
            'delete' => ['POST' => C::SYSTEM_COUPONS], 'status' => ['POST' => C::SYSTEM_COUPONS],
        ],
        'paymentgateway' => [
            '' => 'list', '*' => 'gateway',
            'list' => C::SYSTEM_ADMIN, 'audit' => C::SYSTEM_ADMIN, 'auditview' => C::SYSTEM_ADMIN,
            'delete' => ['POST' => C::SYSTEM_CODE], 'gateway' => C::SYSTEM_CODE,
        ],
        'pluginmanager' => [
            '' => 'list', '*' => 'list', 'list' => ['GET' => C::SYSTEM_ADMIN], 'refresh' => ['POST' => C::SYSTEM_ADMIN],
            'dlinstall' => ['POST' => C::SYSTEM_CODE], 'install' => ['POST' => C::SYSTEM_CODE], 'delete' => ['POST' => C::SYSTEM_CODE],
        ],
        'widgets' => [
            '' => 'list', '*' => 'list', 'list' => C::SYSTEM_ADMIN,
            'add' => C::SYSTEM_ADMIN, 'edit' => C::SYSTEM_ADMIN, 'delete' => ['POST' => C::SYSTEM_ADMIN], 'pos' => ['POST' => C::SYSTEM_ADMIN],
        ],
        // OD-12: pages/*.html (and pages/vouchers/*.html) are Smarty-included templates, so writing or
        // resetting them is a code surface (SuperAdmin only); reading the editor stays system.admin
        'pages' => ['' => 'page', '*' => 'page', 'page' => ['GET' => C::SYSTEM_ADMIN], 'page-post' => ['POST' => C::SYSTEM_CODE], 'page-reset' => ['POST' => C::SYSTEM_CODE]],
        'default' => ['' => 'resolve', '*' => 'resolve', 'resolve' => 'public'],
    ];

    /**
     * Variants: mutations hidden in a sub-route or query parameter of an otherwise readable action.
     * [type, key, value|null, variant]   type 'seg' (routes[key] === value) | 'query' (isset($_GET[key])).
     */
    public const VARIANTS = [
        'customers/edit' => [['seg', 3, 'deletePhoto', 'edit#deletePhoto']],
        'settings/users-edit' => [['seg', 3, 'deletePhoto', 'users-edit#deletePhoto']],
        'plan/view' => [['seg', 3, 'send', 'view#send']],
        'order/view' => [['seg', 3, 'check', 'view#check'], ['seg', 3, 'cancel', 'view#cancel']],
        'dashboard/view' => [['query', 'refresh', null, 'view#refresh']],
        'settings/app' => [
            ['query', 'testWa', null, 'app#test'], ['query', 'testSms', null, 'app#test'],
            ['query', 'testEmail', null, 'app#test'], ['query', 'testTg', null, 'app#test'],
        ],
        'home/dashboard' => [
            ['query', 'renewal', null, 'dashboard#renewal'], ['query', 'sync', null, 'dashboard#sync'],
            ['query', 'extend', null, 'dashboard#extend'], ['query', 'deactivate', null, 'dashboard#deactivate'],
        ],
    ];

    /**
     * API allowlist (deny by default): controllers reachable with an a2 staff token. Never impersonation,
     * backup/restore, plugin/code surfaces, the updater, callbacks or customer-portal controllers.
     *
     * @var array<string,list<string>|'*'>
     */
    public const API_ALLOWED = [
        'admin' => ['post'],
        'customers' => ['list', 'add-post', 'view', 'viewu', 'edit-post', 'sync', 'deactivate', 'delete', 'recharge'],
        'plan' => '*',
        'autoload' => '*',
        'message' => ['send-post', 'send_bulk_ajax', 'send_bulk_selected'],
        'reports' => '*',
        'dashboard' => ['view'],
        'search_user' => '*',
        'maps' => '*',
        'settings' => ['users', 'users-view', 'users-post', 'users-edit-post', 'users-delete', 'change-password-post'],
        // WP6B (owner decision OD-1): the exact accepted reseller actions instead of '*', so a new reseller web action
        // (reseller/detail) never becomes API-reachable by default; unknown actions still resolve to 'tree' as before
        'reseller' => ['tree', 'place-post', 'move-post', 'status-post', 'customer-assign-sales', 'customer-clear-sales', 'customer-transfer', 'customer-assign-owner'],
        'bandwidth' => '*', 'routers' => '*', 'odp' => '*', 'radius' => '*', 'pool' => '*', 'services' => '*',
        'coupons' => '*', 'logs' => ['list', 'radius', 'message'],
    ];
}
