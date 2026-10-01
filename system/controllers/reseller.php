<?php
// Controller: only reachable through the dispatcher (index.php / system/api.php).
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP2 — reseller hierarchy and customer ownership administration (minimal server-rendered UI).
 *
 * Every action is authorized centrally (RouteGuard / RouteMap) and again inside the domain services
 * (capability + scope + actor revalidation inside the transaction). All mutations are POST + CSRF and
 * audited. No finance.
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

    case 'tree':
    default:
        \Reseller\Domain\ResellerTreeService::requireReady();
        $pdo = ORM::get_db();
        $scope = \Reseller\Security\PermissionService::scopeOf($nuxrActor, $C::RESELLER_TREE_READ);
        if ($scope === 'G') {
            $members = \Reseller\Domain\Tx::rows($pdo, 'SELECT u.id, u.username, u.fullname, u.user_type, u.status, u.root FROM tbl_users u JOIN tbl_manager_tree t ON t.ancestor_id = u.id AND t.descendant_id = u.id ORDER BY u.id');
        } else {
            $members = \Reseller\Domain\Tx::rows($pdo, 'SELECT u.id, u.username, u.fullname, u.user_type, u.status, u.root FROM tbl_users u JOIN tbl_manager_tree t ON t.descendant_id = u.id WHERE t.ancestor_id = ? ORDER BY u.id', [(int) $nuxrActor->userId]);
        }
        $ids = array_map(static fn($m) => (int) $m['id'], $members);
        $parent = [];
        $depth = [];
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (\Reseller\Domain\Tx::rows($pdo, "SELECT ancestor_id, descendant_id FROM tbl_manager_tree WHERE depth = 1 AND descendant_id IN ($ph)", $ids) as $r) {
                $parent[(int) $r['descendant_id']] = (int) $r['ancestor_id'];
            }
            foreach (\Reseller\Domain\Tx::rows($pdo, "SELECT descendant_id, MAX(depth) AS d FROM tbl_manager_tree WHERE descendant_id IN ($ph) GROUP BY descendant_id", $ids) as $r) {
                $depth[(int) $r['descendant_id']] = (int) $r['d'];
            }
        }
        $counts = [];
        if ($ids !== []) {
            foreach (\Reseller\Domain\Tx::rows($pdo, "SELECT manager_id, COUNT(*) AS n FROM tbl_customers WHERE manager_id IN ($ph) GROUP BY manager_id", $ids) as $r) {
                $counts[(int) $r['manager_id']] = (int) $r['n'];
            }
        }
        // depth-first order for display
        $children = [];
        foreach ($members as $m) {
            $children[$parent[(int) $m['id']] ?? 0][] = $m;
        }
        $ordered = [];
        $walk = function (int $pid) use (&$walk, &$ordered, $children, $depth, $counts, $nuxrActor, $C) {
            foreach ($children[$pid] ?? [] as $m) {
                $id = (int) $m['id'];
                $m['level'] = $depth[$id] ?? 0;
                $m['customers'] = $counts[$id] ?? 0;
                $m['can_status'] = $id !== (int) $nuxrActor->userId && \Reseller\Domain\StaffService::canTarget($nuxrActor, $m, $C::STAFF_DISABLE);
                $ordered[] = $m;
                $walk($id);
            }
        };
        // roots of the visible forest: members whose parent is not visible
        $visible = array_flip($ids);
        foreach ($members as $m) {
            $pid = $parent[(int) $m['id']] ?? 0;
            if ($pid !== 0 && !isset($visible[$pid])) {
                $children[0][] = $m;
            }
        }
        $walk(0);
        $ui->assign('nuxr_tree', $ordered);
        $ui->assign('nuxr_can_place', \Reseller\Security\PermissionService::can($nuxrActor, $C::RESELLER_PLACE));
        $ui->assign('nuxr_can_move', \Reseller\Security\PermissionService::can($nuxrActor, $C::RESELLER_MOVE));
        if (\Reseller\Security\PermissionService::can($nuxrActor, $C::RESELLER_PLACE)) {
            $ui->assign('nuxr_unenrolled', \Reseller\Domain\Tx::rows($pdo, "SELECT u.id, u.username, u.fullname, u.user_type, u.status, u.root FROM tbl_users u WHERE u.user_type IN ('Agent','Sales') AND NOT EXISTS (SELECT 1 FROM tbl_manager_tree t WHERE t.descendant_id = u.id) ORDER BY u.id"));
            $ui->assign('nuxr_agents', \Reseller\Domain\CustomerService::ownerOptions());
        }
        $ui->assign('nuxr_verify', $scope === 'G' ? \Reseller\Domain\ResellerTreeService::verify($pdo) : []);
        $ui->display('admin/reseller/tree.tpl');
        break;
}
