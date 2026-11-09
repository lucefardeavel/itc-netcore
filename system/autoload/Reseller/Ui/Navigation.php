<?php
namespace Reseller\Ui;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Activation\ActivationService;
use Reseller\Domain\ResellerReadService;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability;
use Reseller\Security\PermissionService;
use Reseller\Security\RouteGuard;

/**
 * WP6A: the staff navigation model of the adaptive shell (desktop sidebar, tablet rail, phone bottom navigation,
 * More and Quick Action sheets). It is presentation only: a destination is listed when the route itself would be
 * authorized for the actor (RouteGuard::resolve + PermissionService::can, i.e. the accepted RouteMap rule), AND the
 * legacy feature / role condition of the former menu still holds. Hidden navigation is never authorization; every
 * route stays authorized server-side.
 *
 * No placeholder destinations (owner: prefer hiding until the WP exists). No reseller voucher item.
 *
 * WP6B (owner decision OD-3): finance, reseller, pricing and operations destinations (each item only where its
 * RouteMap rule allows the actor). UX 2026-11-09: grouped by daily work (Subscribers, Vouchers and recharge, Plans and
 * speeds, Resellers and wallets, Reports, Messages, Network, Settings, portal pages, Advanced, Help). Agent phone primary navigation: Home / Customers / + / Wallet / More (Wallet = its own
 * finance overview; an Agent without wallet.read keeps the WP6A navigation). Sales / Admin / Report primary unchanged.
 */
final class Navigation
{
    /**
     * @param array<string,mixed>  $config  application settings ($config)
     * @param array<string,string> $plugins plugin menu HTML per position (boot.php, WP2 OD-9 filtered)
     * @param list<string>         $routes  current route segments
     * @return array{primary:list<array<string,mixed>>,quick:list<array<string,mixed>>,groups:list<array<string,mixed>>,plugins:array<string,string>}
     */
    public static function for(ActorContext $a, array $config, array $plugins = [], array $routes = []): array
    {
        $current = implode('/', array_slice(array_values(array_filter($routes, 'is_string')), 0, 2));
        $role = (string) $a->role;
        $admin = in_array($role, ['SuperAdmin', 'Admin'], true);
        $on = static fn(string $k): bool => ($config[$k] ?? '') === 'yes';
        $voucher = ($config['disable_voucher'] ?? '') !== 'yes';
        $radius = !empty($config['radius_enable']);

        $groups = [];
        // UX 2026-11-09 (owner: simple and clear): groups follow the operator's daily work; technical pages are under
        // Advanced. Every item keeps its route and RouteMap rule; only grouping and labels changed.
        $groups[] = self::group('subscribers', 'Subscribers', 'fa-users', $a, $current, [
            ['customers/list', 'All subscribers', 'fa-list', true, ['customers/list', 'customers/view', 'customers/edit', 'customers']],
            ['customers/add', 'Add subscriber', 'fa-user-plus', true, ['customers/add']],
            ['activation/list', 'Activations and renewals', 'fa-bolt', true, ['activation/list', 'activation/view', 'activation/create', 'activation']],
            ['plan/list', 'Active subscriptions', 'fa-signal', true],
            ['operations/overview', 'Follow-up center', 'fa-stethoscope', $admin, ['operations/overview', 'operations', 'operations/activations', 'operations/drift', 'operations/held', 'operations/renewals']],
            ['maps/customer', 'Subscribers map', 'fa-map-marker', true],
        ], 'CUSTOMERS', 'AFTER_CUSTOMERS');
        if ($role !== 'Report') {
            $groups[] = self::group('services', 'Vouchers and recharge', 'fa-ticket', $a, $current, [
                ['plan/voucher', 'Vouchers', 'fa-ticket', $voucher],
                ['plan/refill', 'Recharge with a voucher', 'fa-credit-card', $voucher],
                ['plan/recharge', 'Recharge a subscription', 'fa-refresh', true],
                ['coupons', 'Coupons', 'fa-tag', $on('enable_coupons')],
                ['plan/deposit', 'Refill Balance', 'fa-money', $on('enable_balance')],
            ], 'SERVICES', 'AFTER_SERVICES');
        }
        $groups[] = self::group('plans', 'Plans and speeds', 'fa-cube', $a, $current, [
            ['services/hotspot', 'Hotspot plans', 'fa-wifi', $admin],
            ['services/pppoe', 'PPPoE plans', 'fa-plug', $admin],
            ['services/vpn', 'VPN plans', 'fa-lock', $admin],
            ['bandwidth/list', 'Speeds', 'fa-tachometer', $admin],
            ['pricing/plans', 'Prices for resellers', 'fa-tags', true, ['pricing/plans', 'pricing', 'pricing/plan', 'pricing/catalog']],
            ['services/balance', 'Customer Balance', 'fa-money', $admin && $on('enable_balance')],
        ], 'PLANS', 'AFTER_PLANS');
        $groups[] = self::group('finance', 'Resellers and wallets', 'fa-sitemap', $a, $current, [
            ['reseller/tree', 'Reseller Tree', 'fa-sitemap', true, ['reseller/tree', 'reseller', 'reseller/detail']],
            ['finance/overview', 'Wallet overview', 'fa-credit-card', true, ['finance/overview', 'finance', 'finance/wallet', 'finance/funding', 'finance/send', 'finance/debt-limit', 'finance/adjust']],
            ['finance/wallets', 'Wallets', 'fa-list', $admin || self::hasChildren($a)],
            ['finance/ledger', 'Ledger', 'fa-book', !$admin, ['finance/ledger', 'finance/entry']], // a global viewer opens a ledger from a wallet
            ['finance/transfers', 'Transfers', 'fa-exchange', true, ['finance/transfers', 'finance/transfer', 'finance/reverse']],
        ], null, null);
        $groups[] = self::group('reports', 'Reports', 'fa-bar-chart', $a, $current, [
            ['finance/sales', 'Sales report', 'fa-line-chart', true],
            ['finance/commissions', 'Commissions', 'fa-percent', true],
            ['finance/settlement', 'Provider settlement', 'fa-balance-scale', $admin],
            ['finance/performance', 'Reseller performance', 'fa-trophy', true],
            ['reports', 'Transactions log', 'fa-calendar', true],
            ['reports/activation', 'Activation log', 'fa-history', true],
        ], 'REPORTS', 'AFTER_REPORTS');
        $groups[] = self::group('message', 'Messages', 'fa-comments', $a, $current, [
            ['message/send', 'Message to one subscriber', 'fa-comment', true],
            ['message/send_bulk', 'Message to many subscribers', 'fa-bullhorn', true],
            ['settings/notifications', 'Automatic message texts', 'fa-bell', $admin],
            ['settings/app&open=WhatsappNotification', 'Connect WhatsApp and SMS', 'fa-whatsapp', $admin, []],
        ], 'MESSAGE', 'AFTER_MESSAGE');
        $groups[] = self::group('network', 'Network', 'fa-server', $a, $current, [
            ['routers', 'Routers', 'fa-server', $admin],
            ['radius/nas-list', 'NAS devices (RADIUS)', 'fa-database', $admin && $radius],
            ['pool/list', 'IP Pool', 'fa-database', $admin],
            ['odp', 'Distribution points (ODP)', 'fa-share-alt', $admin],
            ['maps/routers', 'Routers map', 'fa-map-o', true],
            ['maps/odp', 'Distribution points map', 'fa-map-o', true],
        ], 'NETWORK', 'AFTER_NETWORKS');
        $groups[] = self::group('settings', 'Settings', 'fa-cog', $a, $current, [
            ['settings/app', 'General Settings', 'fa-sliders', $admin],
            ['settings/localisation', 'Language, time and currency', 'fa-globe', $admin],
            ['settings/users', 'Users and permissions', 'fa-user-secret', in_array($role, ['SuperAdmin', 'Admin', 'Agent'], true)],
            ['paymentgateway', 'Payment Gateway', 'fa-credit-card', $admin],
            ['settings/dbstatus', 'Backup/Restore', 'fa-hdd-o', $admin],
        ], 'SETTINGS', 'AFTER_SETTINGS');
        $groups[] = self::group('pages', 'Subscriber portal pages', 'fa-file-text-o', $a, $current, array_map(
            static fn(array $p): array => ['pages/' . $p[0], $p[1], 'fa-file-o', $admin],
            [['Order_Voucher', 'Order Voucher'], ['Voucher', 'Theme Voucher'], ['Announcement', 'Announcement'], ['Announcement_Customer', 'Customer Announcement'],
                ['Registration_Info', 'Registration Info'], ['Payment_Info', 'Payment Info'], ['Privacy_Policy', 'Privacy Policy'], ['Terms_and_Conditions', 'Terms and Conditions']]
        ), 'PAGES', 'AFTER_PAGES');
        $groups[] = self::group('advanced', 'Advanced', 'fa-wrench', $a, $current, [
            ['widgets', 'Dashboard widgets', 'fa-th-large', $admin],
            ['customfield', 'Custom Fields', 'fa-list-alt', $admin],
            ['settings/miscellaneous', 'Miscellaneous', 'fa-wrench', $admin],
            ['settings/maintenance', 'Maintenance Mode', 'fa-exclamation-triangle', $admin],
            ['settings/devices', 'Device types', 'fa-mobile', $admin],
            ['pool/port', 'Port Pool', 'fa-random', $admin],
            ['pluginmanager', 'Plugin Manager', 'fa-puzzle-piece', $admin],
            ['logs/phpnuxbill', 'System log', 'fa-file-text', $admin],  // route name unchanged (internal)
            ['logs/radius', 'RADIUS log', 'fa-file-text', $admin && $radius],
            ['logs/message', 'Message log', 'fa-file-text', $admin],
        ], 'LOGS', 'AFTER_LOGS');
        $groups[] = self::group('help', 'Help', 'fa-question-circle', $a, $current, [
            ['settings/docs', 'Documentation', 'fa-book', $admin],
            ['community', 'About', 'fa-info-circle', $admin],  // About ITC NetCore (route name unchanged)
        ], null, 'AFTER_COMMUNITY');
        $groups = array_values(array_filter($groups, static fn(array $g): bool => $g['items'] !== []
            || ($g['slot'] !== null && ($plugins[$g['slot']] ?? '') !== '') || ($g['after'] !== null && ($plugins[$g['after']] ?? '') !== '')));

        $home = self::item('dashboard', 'Home', 'fa-home', $a, $current, ['dashboard', '']);
        $customers = self::item('customers/list', 'Customers', 'fa-users', $a, $current, ['customers/list', 'customers/view', 'customers/edit', 'customers/add', 'customers']);
        $activations = self::item('activation/list', 'Activations', 'fa-bolt', $a, $current, ['activation/list', 'activation/view', 'activation', 'activation/create']);
        $wallet = $role === 'Agent' ? self::item('finance/overview', 'Wallet', 'fa-credit-card', $a, $current, ['finance/overview', 'finance', 'finance/wallet', 'finance/send', 'finance/send-review', 'finance/send-post', 'finance/ledger', 'finance/entry', 'finance/transfers', 'finance/transfer']) : null;
        $quick = array_values(array_filter([
            self::item('customers/add', 'Add subscriber', 'fa-user-plus', $a, $current),
            ActivationService::eligible($a) ? self::item('activation/create', 'Activate / Renew', 'fa-bolt', $a, $current) : null,
            // WP6B (OD-3): money quick actions only where the accepted capability allows them
            $role === 'Agent' && PermissionService::scopeOf($a, Capability::WALLET_TRANSFER) === 'C' && self::hasChildren($a) ? self::item('finance/send', 'Send to child reseller', 'fa-exchange', $a, $current) : null,
            $admin && PermissionService::scopeOf($a, Capability::WALLET_FUND_WITHDRAW) === 'G' ? self::item('finance/wallets', 'Fund reseller', 'fa-plus-circle', $a, $current) : null,
        ]));
        $primary = array_values(array_filter([$home, $customers, $quick !== [] ? ['id' => 'quick', 'label' => 'New', 'icon' => 'fa-plus', 'sheet' => 'quick'] : null,
            $wallet ?? $activations, ['id' => 'more', 'label' => 'More', 'icon' => 'fa-bars', 'sheet' => 'more']]));
        return ['primary' => $primary, 'quick' => $quick, 'groups' => $groups, 'plugins' => array_map('strval', $plugins), 'up' => self::up($a, $routes)];
    }

    /**
     * WP6C (owner amendment B): presentation-only "Up" relationships for detail / form workflows. This registry is NOT
     * authorization and does not touch the RouteMap: each candidate parent is rendered only when the actor may open it
     * (allowed()), the first allowed candidate wins, otherwise Home. Top-level destinations have no Up. "{2}" is the
     * current route's id segment (validated to a safe token).
     *
     * @var array<string,array{0:list<string>,1:string}> "controller/action" => [parent candidates, label]
     */
    private const UP = [
        'customers/view' => [['customers/list'], 'Subscribers'],
        'customers/edit' => [['customers/view/{2}', 'customers/list'], 'Subscriber'],
        'customers/add' => [['customers/list'], 'Subscribers'],
        'activation/view' => [['activation/list'], 'Activations'],
        'activation/create' => [['activation/list'], 'Activations'],
        'finance/wallet' => [['finance/wallets', 'finance/overview'], 'Wallets'],
        'finance/ledger' => [['finance/wallet/{2}', 'finance/overview'], 'Wallet'],
        'finance/entry' => [['finance/overview'], 'Wallet'],
        'finance/transfer' => [['finance/transfers'], 'Transfers'],
        'finance/send' => [['finance/overview'], 'Wallet'],
        'finance/funding' => [['finance/wallet/{2}'], 'Wallet'],
        'finance/debt-limit' => [['finance/wallet/{2}'], 'Wallet'],
        'finance/adjust' => [['finance/wallet/{2}'], 'Wallet'],
        'finance/reverse' => [['finance/transfer/{2}'], 'Transfer'],
        'pricing/plan' => [['pricing/plans'], 'Prices for resellers'],
        'pricing/catalog' => [['pricing/plans'], 'Prices for resellers'],
        'reseller/detail' => [['reseller/tree'], 'Reseller Tree'],
        'operations/activations' => [['operations/overview'], 'Follow-up center'],
        'operations/held' => [['operations/overview'], 'Follow-up center'],
        'operations/renewals' => [['operations/overview'], 'Follow-up center'],
        'operations/drift' => [['operations/overview'], 'Follow-up center'],
        'plan/edit' => [['plan/list'], 'Active subscriptions'],
        'plan/voucher-view' => [['plan/voucher'], 'Vouchers'],
        'plan/add-voucher' => [['plan/voucher'], 'Vouchers'],
        'settings/users-view' => [['settings/users'], 'Users and permissions'],
        'settings/users-edit' => [['settings/users-view/{2}', 'settings/users'], 'Users and permissions'],
        'settings/users-add' => [['settings/users'], 'Users and permissions'],
        'settings/change-password' => [['dashboard'], 'Home'],
        'services/edit' => [['services/hotspot'], 'Hotspot plans'],
        'services/add' => [['services/hotspot'], 'Hotspot plans'],
        'services/pppoe-edit' => [['services/pppoe'], 'PPPoE plans'],
        'services/pppoe-add' => [['services/pppoe'], 'PPPoE plans'],
        'services/vpn-edit' => [['services/vpn'], 'VPN plans'],
        'services/vpn-add' => [['services/vpn'], 'VPN plans'],
        'services/balance-edit' => [['services/balance'], 'Customer Balance'],
        'services/balance-add' => [['services/balance'], 'Customer Balance'],
        'bandwidth/edit' => [['bandwidth/list'], 'Speeds'],
        'bandwidth/add' => [['bandwidth/list'], 'Speeds'],
        'routers/edit' => [['routers'], 'Routers'],
        'routers/add' => [['routers'], 'Routers'],
        'pool/edit' => [['pool/list'], 'IP Pool'],
        'pool/add' => [['pool/list'], 'IP Pool'],
        'pool/port-edit' => [['pool/port'], 'Port Pool'],
        'pool/port-add' => [['pool/port'], 'Port Pool'],
        'odp/edit' => [['odp'], 'Distribution points (ODP)'],
        'odp/add' => [['odp'], 'Distribution points (ODP)'],
        'radius/nas-edit' => [['radius/nas-list'], 'NAS devices (RADIUS)'],
        'radius/nas-add' => [['radius/nas-list'], 'NAS devices (RADIUS)'],
        'coupons/edit' => [['coupons'], 'Coupons'],
        'coupons/add' => [['coupons'], 'Coupons'],
    ];

    /** @param list<string> $routes current route segments @return array{route:string,label:string}|null */
    public static function up(ActorContext $a, array $routes): ?array
    {
        $routes = array_values(array_filter($routes, 'is_string'));
        $key = ($routes[0] ?? '') . '/' . ($routes[1] ?? '');
        if (!isset(self::UP[$key]) || !$a->isStaff()) {
            return null;
        }
        $id = (string) ($routes[2] ?? '');
        foreach (self::UP[$key][0] as $candidate) {
            if (str_contains($candidate, '{2}')) {
                if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
                    continue;
                }
                $candidate = str_replace('{2}', $id, $candidate);
            }
            if (self::allowed($a, $candidate)) {
                return ['route' => $candidate, 'label' => self::UP[$key][1]];
            }
        }
        return ['route' => 'dashboard', 'label' => 'Home'];
    }

    /**
     * WP6C (owner amendment C): the shell for a page rendered before the dispatcher assigned it (RouteGuard's 403 / 404
     * page): the actor's own navigation (only destinations it may open), capability flags and identity. Presentation
     * only; nothing about the denied object is exposed.
     *
     * @return array{nav:array<string,mixed>,can:array<string,bool>,admin:array<string,mixed>}
     */
    public static function deniedShell(): array
    {
        $a = ActorContext::current();
        $config = is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [];
        $routes = is_array($GLOBALS['routes'] ?? null) ? $GLOBALS['routes'] : [];
        $admin = $a->isStaff() && class_exists('Admin', false) ? (\Admin::_info() ?: []) : [];
        return ['nav' => $a->isStaff() ? self::for($a, $config, [], $routes) : [], 'can' => PermissionService::flags($a),
            'admin' => ['id' => (int) ($admin['id'] ?? 0), 'fullname' => (string) ($admin['fullname'] ?? ''), 'user_type' => (string) ($admin['user_type'] ?? '')]];
    }

    /** WP6B: the Agent has at least one Active direct child reseller (quick "Send" / Wallets destination). */
    private static function hasChildren(ActorContext $a): bool
    {
        // wallet.read first: schema-dependent, so nothing is queried while the reseller schema is not ready
        return PermissionService::can($a, Capability::WALLET_READ) && ResellerReadService::hasActiveChildren($a);
    }

    /** True when the actor may open $route by GET (or submit it, $method = POST) under the accepted RouteMap rule (staff only). */
    public static function allowed(ActorContext $a, string $route, string $method = 'GET'): bool
    {
        $parts = explode('/', explode('&', $route)[0]);
        $r = RouteGuard::resolve($parts[0], $parts, [], $method === 'POST' ? 'POST' : 'GET');
        if (!$r['mapped'] || !$r['method_allowed'] || !$a->isStaff()) {
            return false;
        }
        return match ((string) $r['rule']) {
            'public', 'customer', 'deny', 'plugin' => false,
            'staff' => true,
            default => PermissionService::can($a, (string) $r['rule']),
        };
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:bool,4?:list<string>}> $defs
     * @return array<string,mixed>
     */
    private static function group(string $id, string $label, string $icon, ActorContext $a, string $current, array $defs, ?string $slot, ?string $after): array
    {
        $items = [];
        foreach ($defs as $d) {
            if ($d[3] && ($item = self::item($d[0], $d[1], $d[2], $a, $current, $d[4] ?? [$d[0]])) !== null) {
                $items[] = $item;
            }
        }
        $active = array_filter($items, static fn(array $i): bool => $i['active']) !== [];
        return ['id' => $id, 'label' => $label, 'icon' => $icon, 'items' => $items, 'active' => $active, 'slot' => $slot, 'after' => $after];
    }

    /** @param list<string> $match route prefixes that mark the item active */
    private static function item(string $route, string $label, string $icon, ActorContext $a, string $current, array $match = []): ?array
    {
        if (!self::allowed($a, $route)) {
            return null;
        }
        $match = $match === [] ? [$route] : $match;
        return ['id' => str_replace('/', '-', $route), 'route' => $route, 'label' => $label, 'icon' => $icon, 'active' => in_array($current, $match, true)];
    }

    /** RouteMap is the single source of truth for every listed route (used by the navigation tests). */
    public static function routes(array $nav): array
    {
        $out = [];
        foreach ($nav['groups'] as $g) {
            foreach ($g['items'] as $i) {
                $out[] = $i['route'];
            }
        }
        foreach (array_merge($nav['primary'], $nav['quick']) as $i) {
            if (isset($i['route'])) {
                $out[] = $i['route'];
            }
        }
        return array_values(array_unique($out));
    }
}
