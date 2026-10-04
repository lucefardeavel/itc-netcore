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
 * WP6B (owner decision OD-3): Finance / Resellers / Pricing / Operations groups (each item only where its RouteMap
 * rule allows the actor); the Reseller Tree moves from Settings to Resellers; legacy reports are labelled
 * "Transactions (legacy)". Agent phone primary navigation: Home / Customers / + / Wallet / More (Wallet = its own
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
        $groups[] = self::group('subscribers', 'Subscribers', 'fa-users', $a, $current, [
            ['customers/list', 'All subscribers', 'fa-list', true, ['customers/list', 'customers/view', 'customers/edit', 'customers']],
            ['activation/list', 'Activations', 'fa-bolt', true, ['activation/list', 'activation/view', 'activation/create', 'activation']],
            ['customers/add', 'Add subscriber', 'fa-user-plus', true, ['customers/add']],
            ['plan/list', 'Active Customers', 'fa-signal', true],
        ], 'CUSTOMERS', 'AFTER_CUSTOMERS');
        $groups[] = self::group('finance', 'Finance', 'fa-credit-card', $a, $current, [
            ['finance/overview', 'Wallet overview', 'fa-credit-card', true, ['finance/overview', 'finance', 'finance/wallet', 'finance/funding', 'finance/send', 'finance/debt-limit', 'finance/adjust']],
            ['finance/wallets', 'Wallets', 'fa-list', $admin || self::hasChildren($a)],
            ['finance/ledger', 'Ledger', 'fa-book', true, ['finance/ledger', 'finance/entry']],
            ['finance/transfers', 'Transfers', 'fa-exchange', true, ['finance/transfers', 'finance/transfer', 'finance/reverse']],
        ], null, null);
        $groups[] = self::group('resellers', 'Resellers', 'fa-sitemap', $a, $current, [
            ['reseller/tree', 'Reseller Tree', 'fa-sitemap', true, ['reseller/tree', 'reseller', 'reseller/detail']],
        ], null, null);
        $groups[] = self::group('pricing', 'Pricing', 'fa-tags', $a, $current, [
            ['pricing/plans', 'Plans and prices', 'fa-tags', true, ['pricing/plans', 'pricing', 'pricing/plan', 'pricing/catalog']],
        ], null, null);
        $groups[] = self::group('operations', 'Operations', 'fa-stethoscope', $a, $current, [
            ['operations/overview', 'Operations Center', 'fa-stethoscope', $admin, ['operations/overview', 'operations', 'operations/activations', 'operations/drift', 'operations/held', 'operations/renewals']],
        ], null, null);
        if ($role !== 'Report') {
            $groups[] = self::group('services', 'Services', 'fa-ticket', $a, $current, [
                ['plan/refill', 'Refill Customer', 'fa-credit-card', $voucher],
                ['plan/voucher', 'Vouchers', 'fa-ticket', $voucher],
                ['coupons', 'Coupons', 'fa-tag', $on('enable_coupons')],
                ['plan/recharge', 'Recharge Customer', 'fa-refresh', true],
                ['plan/deposit', 'Refill Balance', 'fa-money', $on('enable_balance')],
            ], 'SERVICES', 'AFTER_SERVICES');
        }
        $groups[] = self::group('plans', 'Internet Plan', 'fa-cube', $a, $current, [
            ['services/hotspot', 'Hotspot', 'fa-wifi', $admin],
            ['services/pppoe', 'PPPOE', 'fa-plug', $admin],
            ['services/vpn', 'VPN', 'fa-lock', $admin],
            ['bandwidth/list', 'Bandwidth', 'fa-tachometer', $admin],
            ['services/balance', 'Customer Balance', 'fa-money', $admin && $on('enable_balance')],
        ], 'PLANS', 'AFTER_PLANS');
        $groups[] = self::group('maps', 'Maps', 'fa-map-marker', $a, $current, [
            ['maps/customer', 'Customer', 'fa-user', true],
            ['maps/routers', 'Routers', 'fa-server', true],
            ['maps/odp', 'ODPs', 'fa-sitemap', true],
        ], 'MAPS', null);
        $groups[] = self::group('reports', 'Reports', 'fa-bar-chart', $a, $current, [
            ['finance/sales', 'Sales', 'fa-line-chart', true],
            ['finance/commissions', 'Commissions', 'fa-percent', true],
            ['finance/settlement', 'Provider settlement', 'fa-balance-scale', $admin],
            ['finance/performance', 'Reseller performance', 'fa-trophy', true],
            ['reports', 'Transactions (legacy)', 'fa-calendar', true],
            ['reports/activation', 'Activation History (legacy)', 'fa-history', true],
        ], 'REPORTS', 'AFTER_REPORTS');
        $groups[] = self::group('message', 'Send Message', 'fa-comments', $a, $current, [
            ['message/send', 'Single Customer', 'fa-comment', true],
            ['message/send_bulk', 'Bulk Customers', 'fa-bullhorn', true],
        ], 'MESSAGE', 'AFTER_MESSAGE');
        $groups[] = self::group('network', 'Network', 'fa-sitemap', $a, $current, [
            ['routers', 'Routers', 'fa-server', $admin],
            ['pool/list', 'IP Pool', 'fa-database', $admin],
            ['pool/port', 'Port Pool', 'fa-random', $admin],
            ['odp', 'ODP List', 'fa-share-alt', $admin],
            ['radius/nas-list', 'Radius NAS', 'fa-database', $admin && $radius],
        ], 'NETWORK', 'AFTER_NETWORKS');
        $groups[] = self::group('pages', 'Static Pages', 'fa-file-text-o', $a, $current, array_map(
            static fn(array $p): array => ['pages/' . $p[0], $p[1], 'fa-file-o', $admin],
            [['Order_Voucher', 'Order Voucher'], ['Voucher', 'Theme Voucher'], ['Announcement', 'Announcement'], ['Announcement_Customer', 'Customer Announcement'],
                ['Registration_Info', 'Registration Info'], ['Payment_Info', 'Payment Info'], ['Privacy_Policy', 'Privacy Policy'], ['Terms_and_Conditions', 'Terms and Conditions']]
        ), 'PAGES', 'AFTER_PAGES');
        $groups[] = self::group('settings', 'Settings', 'fa-cog', $a, $current, [
            ['settings/app', 'General Settings', 'fa-sliders', $admin],
            ['settings/localisation', 'Localisation', 'fa-globe', $admin],
            ['customfield', 'Custom Fields', 'fa-list-alt', $admin],
            ['settings/miscellaneous', 'Miscellaneous', 'fa-wrench', $admin],
            ['settings/maintenance', 'Maintenance Mode', 'fa-exclamation-triangle', $admin],
            ['widgets', 'Widgets', 'fa-th-large', $admin],
            ['settings/notifications', 'User Notification', 'fa-bell', $admin],
            ['settings/devices', 'Devices', 'fa-mobile', $admin],
            ['settings/users', 'Administrator Users', 'fa-user-secret', in_array($role, ['SuperAdmin', 'Admin', 'Agent'], true)],
            ['settings/dbstatus', 'Backup/Restore', 'fa-hdd-o', $admin],
            ['paymentgateway', 'Payment Gateway', 'fa-credit-card', $admin],
            ['pluginmanager', 'Plugin Manager', 'fa-puzzle-piece', $admin],
        ], 'SETTINGS', 'AFTER_SETTINGS');
        $groups[] = self::group('logs', 'Logs', 'fa-clock-o', $a, $current, [
            ['logs/phpnuxbill', 'PhpNuxBill', 'fa-file-text', $admin],
            ['logs/radius', 'Radius', 'fa-file-text', $admin && $radius],
            ['logs/message', 'Message', 'fa-file-text', $admin],
        ], 'LOGS', 'AFTER_LOGS');
        $groups[] = self::group('help', 'Help', 'fa-question-circle', $a, $current, [
            ['settings/docs', 'Documentation', 'fa-book', $admin],
            ['community', 'Community', 'fa-comments-o', $admin],
        ], null, 'AFTER_COMMUNITY');
        $groups = array_values(array_filter($groups, static fn(array $g): bool => $g['items'] !== []
            || ($g['slot'] !== null && ($plugins[$g['slot']] ?? '') !== '') || ($g['after'] !== null && ($plugins[$g['after']] ?? '') !== '')));

        $home = self::item('dashboard', 'Home', 'fa-home', $a, $current, ['dashboard', '']);
        $customers = self::item('customers/list', 'Customers', 'fa-users', $a, $current, ['customers/list', 'customers/view', 'customers/edit', 'customers/add', 'customers']);
        $activations = self::item('activation/list', 'Activations', 'fa-bolt', $a, $current, ['activation/list', 'activation/view', 'activation', 'activation/create']);
        $wallet = $role === 'Agent' ? self::item('finance/overview', 'Wallet', 'fa-credit-card', $a, $current, ['finance/overview', 'finance', 'finance/wallet', 'finance/send', 'finance/ledger', 'finance/entry', 'finance/transfers', 'finance/transfer']) : null;
        $quick = array_values(array_filter([
            self::item('customers/add', 'Add subscriber', 'fa-user-plus', $a, $current),
            ActivationService::eligible($a) ? self::item('activation/create', 'Activate / Renew', 'fa-bolt', $a, $current) : null,
            // WP6B (OD-3): money quick actions only where the accepted capability allows them
            $role === 'Agent' && PermissionService::scopeOf($a, Capability::WALLET_TRANSFER) === 'C' && self::hasChildren($a) ? self::item('finance/send', 'Send to child reseller', 'fa-exchange', $a, $current) : null,
            $admin && PermissionService::scopeOf($a, Capability::WALLET_FUND_WITHDRAW) === 'G' ? self::item('finance/wallets', 'Fund reseller', 'fa-plus-circle', $a, $current) : null,
        ]));
        $primary = array_values(array_filter([$home, $customers, $quick !== [] ? ['id' => 'quick', 'label' => 'New', 'icon' => 'fa-plus', 'sheet' => 'quick'] : null,
            $wallet ?? $activations, ['id' => 'more', 'label' => 'More', 'icon' => 'fa-bars', 'sheet' => 'more']]));
        return ['primary' => $primary, 'quick' => $quick, 'groups' => $groups, 'plugins' => array_map('strval', $plugins)];
    }

    /** WP6B: the Agent has at least one Active direct child reseller (quick "Send" / Wallets destination). */
    private static function hasChildren(ActorContext $a): bool
    {
        return ResellerReadService::hasActiveChildren($a);
    }

    /** True when the actor may open $route by GET (the accepted RouteMap rule; staff navigation only). */
    public static function allowed(ActorContext $a, string $route): bool
    {
        $parts = explode('/', $route);
        $r = RouteGuard::resolve($parts[0], $parts, [], 'GET');
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
