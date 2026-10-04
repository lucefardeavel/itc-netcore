<?php
// Controller: only reachable through the dispatcher (index.php / system/api.php).
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP2 — reseller hierarchy and customer ownership administration (minimal server-rendered UI).
 *
 * Every action is authorized centrally (RouteGuard / RouteMap) and again inside the domain services
 * (capability + scope + actor revalidation inside the transaction). All mutations are POST + CSRF and
 * audited. No finance on the tree (API-reachable); WP6B adds the web-only reseller detail through
 * Reseller\\Backoffice\\ResellerDesk.
 */

_admin();
$ui->assign('_title', Lang::T('Reseller Tree'));
$ui->assign('_system_menu', 'settings');
$ui->assign('_admin', $admin);

$action = $routes['1'] ?? '';
$nuxrActor = \Reseller\Security\ActorContext::current();
$C = \Reseller\Security\Capability::class;

$nuxrBack = static function (string $route, string $type, string $msg): void {
    r2(getUrl($route), $type, $msg);
};
$nuxrRun = static function (callable $fn, string $back, string $ok) use ($nuxrBack): void {
    try {
        $fn();
    } catch (\Reseller\Domain\DomainError $e) {
        $nuxrBack($back, 'e', Lang::T($e->getMessage()));
    }
    $nuxrBack($back, 's', Lang::T($ok));
};
$nuxrId = static function (string $key): ?int {
    $v = _post($key);
    if ($v === '' || $v === '0') {
        return null;
    }
    return \Reseller\Security\DataScope::validId($v) ? (int) $v : -1;
};

switch ($action) {
    case 'place-post':
        $userId = $nuxrId('user_id');
        $parentId = $nuxrId('parent_id');
        $nuxrRun(static fn() => \Reseller\Domain\ResellerTreeService::placeExisting($nuxrActor, (int) $userId, $parentId), 'reseller/tree', 'Account placed in the hierarchy');
        break;

    case 'move-post':
        $nodeId = $nuxrId('node_id');
        $parentId = $nuxrId('parent_id');
        $nuxrRun(static fn() => \Reseller\Domain\ResellerTreeService::moveSubtree($nuxrActor, (int) $nodeId, $parentId), 'reseller/tree', 'Account moved');
        break;

    case 'status-post':
        $userId = (int) $nuxrId('user_id');
        $status = _post('status');
        $nuxrRun(static fn() => \Reseller\Domain\StaffService::setStatus($nuxrActor, $userId, $status), 'reseller/tree', 'Status updated');
        break;

    case 'customer-assign-sales':
        $customerId = (int) $nuxrId('customer_id');
        $salesId = $nuxrId('sales_id');
        $nuxrRun(static fn() => \Reseller\Domain\CustomerService::assignSales($nuxrActor, $customerId, $salesId === null ? -1 : $salesId), 'customers/view/' . $customerId, 'Sales assigned');
        break;

    case 'customer-clear-sales':
        $customerId = (int) $nuxrId('customer_id');
        $nuxrRun(static fn() => \Reseller\Domain\CustomerService::assignSales($nuxrActor, $customerId, null), 'customers/view/' . $customerId, 'Sales cleared');
        break;

    case 'customer-transfer':
        $customerId = (int) $nuxrId('customer_id');
        $managerId = (int) $nuxrId('manager_id');
        $salesId = $nuxrId('sales_id');
        $nuxrRun(static fn() => \Reseller\Domain\CustomerService::transferOwner($nuxrActor, $customerId, $managerId, $salesId), 'customers/view/' . $customerId, 'Owner transferred');
        break;

    case 'customer-assign-owner':
        $ids = $_POST['customer_ids'] ?? [];
        $managerId = (int) $nuxrId('manager_id');
        $salesId = $nuxrId('sales_id');
        $nuxrRun(static fn() => \Reseller\Domain\CustomerService::assignLegacyOwner($nuxrActor, is_array($ids) ? $ids : [], $managerId, $salesId), 'customers/list', 'Owner assigned');
        break;

    case 'detail':
        try {
            $node = \Reseller\Backoffice\ResellerDesk::detail($nuxrActor, $routes['2'] ?? '');
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrBack('reseller/tree', 'e', Lang::T($e->getMessage()));
        }
        $ui->assign('_title', $node['fullname']);
        $ui->assign('nuxr_node', $node);
        $ui->assign('nuxr_agents', $node['can_move'] ? \Reseller\Domain\CustomerService::ownerOptions() : []);
        $ui->display('admin/reseller/detail.tpl');
        break;

    case 'tree':
    default:
        // WP6B: read model in Reseller\Domain (scope reseller.tree_read); this page is API-reachable, so it carries no money
        try {
            $tree = \Reseller\Domain\ResellerReadService::tree($nuxrActor);
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrBack('dashboard', 'e', Lang::T($e->getMessage()));
        }
        $focus = \Reseller\Security\DataScope::validId(_get('node')) ? (int) _get('node') : 0;
        $ui->assign('nuxr_tree', $tree['nodes']);
        $ui->assign('nuxr_children', $tree['children']);
        $byId = array_column($tree['nodes'], null, 'id');
        $ui->assign('nuxr_total', count($tree['nodes']));
        // phone drill-down: the cards of one level (the roots, or the children of the focused account)
        $ui->assign('nuxr_level', array_values(array_filter(array_map(static fn(int $id) => $byId[$id] ?? null, $tree['children'][$focus] ?? []))));
        $ui->assign('nuxr_focus', $focus);
        $ui->assign('nuxr_path', $focus > 0 ? \Reseller\Domain\ResellerReadService::path($tree, $focus) : []);
        $ui->assign('nuxr_can_place', \Reseller\Security\PermissionService::can($nuxrActor, $C::RESELLER_PLACE));
        $ui->assign('nuxr_can_move', \Reseller\Security\PermissionService::can($nuxrActor, $C::RESELLER_MOVE));
        $ui->assign('nuxr_can_create', \Reseller\Security\PermissionService::can($nuxrActor, $C::STAFF_CREATE));
        $ui->assign('nuxr_unenrolled', $tree['unenrolled']);
        $ui->assign('nuxr_agents', $tree['agents']);
        $ui->assign('nuxr_verify', $tree['findings']);
        $ui->display('admin/reseller/tree.tpl');
        break;
}
