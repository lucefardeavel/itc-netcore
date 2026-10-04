<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\DataScope;
use Reseller\Security\PermissionService;

/**
 * WP6B: read-only reseller workspace model (tree and reseller detail). Scope is the accepted reseller.tree_read
 * (G: every enrolled account; T: the actor's own subtree). It holds no finance and creates no hierarchy semantics:
 * identities, roles, status, the derived "frozen" state (self or an ancestor not Active), direct / subtree subscriber
 * counts and Sales assignments only. An account above the viewer is never named (role label only).
 *
 * The tree data is also API-reachable (reseller/tree is an accepted API route), so it never carries money.
 */
final class ResellerReadService
{
    /**
     * WP6B navigation (OD-3): the Agent itself has at least one Active direct child reseller (its own subtree only;
     * a boolean, no identity). Memoized per request.
     */
    public static function hasActiveChildren(ActorContext $a): bool
    {
        static $memo = [];
        if (!$a->isStaff() || (string) $a->role !== 'Agent') {
            return false;
        }
        return $memo[(int) $a->userId] ??= \ORM::for_table('tbl_manager_tree')->table_alias('t')->join('tbl_users', ['u.id', '=', 't.descendant_id'], 'u')
            ->where('t.ancestor_id', (int) $a->userId)->where('t.depth', 1)->where('u.status', 'Active')->count() > 0;
    }

    /**
     * The visible forest in depth-first order, with the place / move data for Admin / SuperAdmin.
     *
     * @return array{nodes:list<array<string,mixed>>,children:array<int,list<int>>,roots:list<int>,unenrolled:list<array<string,mixed>>,agents:list<array<string,mixed>>,findings:list<array<string,mixed>>}
     */
    public static function tree(ActorContext $a): array
    {
        ResellerTreeService::authorize($a, C::RESELLER_TREE_READ);
        $pdo = \ORM::get_db();
        $members = self::members($pdo, $a, null);
        $ids = array_map(static fn(array $m): int => (int) $m['id'], $members);
        $stats = self::stats($pdo, $ids);
        $byParent = [];
        $visible = array_flip($ids);
        foreach ($members as $m) {
            $pid = $m['parent_id'] !== null && isset($visible[(int) $m['parent_id']]) ? (int) $m['parent_id'] : 0;
            $byParent[$pid][] = $m;
        }
        $nodes = [];
        $children = [];
        $walk = function (int $pid) use (&$walk, &$nodes, &$children, $byParent, $stats, $a): void {
            foreach ($byParent[$pid] ?? [] as $m) {
                $id = (int) $m['id'];
                $children[$pid][] = $id;
                $nodes[] = self::node($a, $m, $stats);
                $walk($id);
            }
        };
        $walk(0);
        $place = PermissionService::can($a, C::RESELLER_PLACE);
        return [
            'nodes' => $nodes, 'children' => $children, 'roots' => $children[0] ?? [],
            'unenrolled' => $place ? Tx::rows($pdo, "SELECT u.id, u.username, u.fullname, u.user_type, u.status, u.root FROM tbl_users u WHERE u.user_type IN ('Agent','Sales') AND NOT EXISTS (SELECT 1 FROM tbl_manager_tree t WHERE t.descendant_id = u.id) ORDER BY u.id") : [],
            'agents' => $place || PermissionService::can($a, C::RESELLER_MOVE) ? CustomerService::ownerOptions() : [],
            'findings' => PermissionService::scopeOf($a, C::RESELLER_TREE_READ) === 'G' ? ResellerTreeService::verify($pdo) : [],
        ];
    }

    /**
     * One reseller / Sales account inside the actor's tree scope (NOT_FOUND otherwise): identity, state, the parent
     * (named only inside the scope), direct children with their counts, Sales accounts and subscriber counts.
     *
     * @return array<string,mixed>
     */
    public static function detail(ActorContext $a, mixed $id): array
    {
        ResellerTreeService::authorize($a, C::RESELLER_TREE_READ);
        if (!DataScope::validId($id)) {
            throw new DomainError('NOT_FOUND');
        }
        $pdo = \ORM::get_db();
        $rows = self::members($pdo, $a, (int) $id);
        if ($rows === []) {
            throw new DomainError('NOT_FOUND');
        }
        $kids = Tx::rows($pdo, 'SELECT u.id, u.username, u.fullname, u.user_type, u.status, t.ancestor_id AS parent_id FROM tbl_manager_tree t JOIN tbl_users u ON u.id = t.descendant_id '
            . 'WHERE t.ancestor_id = ? AND t.depth = 1 ORDER BY u.user_type, u.username', [(int) $id]);
        $ids = array_merge([(int) $id], array_map(static fn(array $k): int => (int) $k['id'], $kids));
        $stats = self::stats($pdo, $ids);
        $node = self::node($a, $rows[0], $stats);
        $node['children'] = array_map(fn(array $k): array => self::node($a, $k + ['frozen' => $node['frozen'] || $k['status'] !== 'Active'], $stats), $kids);
        $node['parent'] = self::parent($a, $rows[0]['parent_id'] !== null ? (int) $rows[0]['parent_id'] : null);
        $node['services'] = $node['user_type'] === 'Agent' ? self::serviceCounts($pdo, 'manager_id', (int) $id) : self::serviceCounts($pdo, 'assigned_sales_id', (int) $id);
        $node['can_move'] = PermissionService::can($a, C::RESELLER_MOVE);
        $node['can_create_child'] = $node['user_type'] === 'Agent' && !$node['frozen'] && (
            PermissionService::scopeOf($a, C::STAFF_CREATE) === 'G' || (PermissionService::scopeOf($a, C::STAFF_CREATE) === 'C' && (int) $id === (int) $a->userId));
        return $node;
    }

    /**
     * Node ids from a root of the visible forest down to $focus (phone drill-down breadcrumb).
     *
     * @param array{nodes:list<array<string,mixed>>} $tree
     * @return list<array<string,mixed>>
     */
    public static function path(array $tree, int $focus): array
    {
        $byId = [];
        foreach ($tree['nodes'] as $n) {
            $byId[$n['id']] = $n;
        }
        $out = [];
        for ($id = $focus; isset($byId[$id]) && count($out) < 64; $id = (int) ($byId[$id]['visible_parent_id'] ?? 0)) {
            array_unshift($out, $byId[$id]);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> the tree members in scope (one id when $only is given) */
    private static function members(\PDO $pdo, ActorContext $a, ?int $only): array
    {
        $scope = PermissionService::scopeOf($a, C::RESELLER_TREE_READ);
        $sql = 'SELECT u.id, u.username, u.fullname, u.user_type, u.status, '
            . '(SELECT p.ancestor_id FROM tbl_manager_tree p WHERE p.descendant_id = u.id AND p.depth = 1) AS parent_id, '
            . "EXISTS (SELECT 1 FROM tbl_manager_tree f JOIN tbl_users fu ON fu.id = f.ancestor_id WHERE f.descendant_id = u.id AND fu.status <> 'Active') AS frozen, "
            . '(SELECT MAX(d.depth) FROM tbl_manager_tree d WHERE d.descendant_id = u.id) AS level '
            . 'FROM tbl_users u JOIN tbl_manager_tree s ON s.descendant_id = u.id AND s.depth = 0 ';
        $params = [];
        if ($scope === 'G') {
            $sql .= 'WHERE 1 = 1';
        } else {
            $sql .= 'WHERE u.id IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ?)';
            $params[] = (int) $a->userId;
        }
        if ($only !== null) {
            $sql .= ' AND u.id = ?';
            $params[] = $only;
        }
        return Tx::rows($pdo, $sql . ' ORDER BY u.username', $params);
    }

    /** @param list<int> $ids @return array<string,array<int,int>> direct / subtree subscribers, Sales children, assigned customers */
    private static function stats(\PDO $pdo, array $ids): array
    {
        $out = ['direct' => [], 'subtree' => [], 'sales' => [], 'assigned' => [], 'children' => []];
        if ($ids === []) {
            return $out;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (Tx::rows($pdo, "SELECT manager_id, COUNT(*) AS n FROM tbl_customers WHERE manager_id IN ({$ph}) GROUP BY manager_id", $ids) as $r) {
            $out['direct'][(int) $r['manager_id']] = (int) $r['n'];
        }
        foreach (Tx::rows($pdo, "SELECT t.ancestor_id, COUNT(c.id) AS n FROM tbl_manager_tree t JOIN tbl_customers c ON c.manager_id = t.descendant_id WHERE t.ancestor_id IN ({$ph}) GROUP BY t.ancestor_id", $ids) as $r) {
            $out['subtree'][(int) $r['ancestor_id']] = (int) $r['n'];
        }
        foreach (Tx::rows($pdo, "SELECT t.ancestor_id, SUM(u.user_type = 'Sales') AS sales, COUNT(*) AS kids FROM tbl_manager_tree t JOIN tbl_users u ON u.id = t.descendant_id WHERE t.depth = 1 AND t.ancestor_id IN ({$ph}) GROUP BY t.ancestor_id", $ids) as $r) {
            $out['sales'][(int) $r['ancestor_id']] = (int) $r['sales'];
            $out['children'][(int) $r['ancestor_id']] = (int) $r['kids'];
        }
        foreach (Tx::rows($pdo, "SELECT assigned_sales_id, COUNT(*) AS n FROM tbl_customers WHERE assigned_sales_id IN ({$ph}) GROUP BY assigned_sales_id", $ids) as $r) {
            $out['assigned'][(int) $r['assigned_sales_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @param array<string,mixed> $m @param array<string,array<int,int>> $s @return array<string,mixed> */
    private static function node(ActorContext $a, array $m, array $s): array
    {
        $id = (int) $m['id'];
        $agent = $m['user_type'] === 'Agent';
        return [
            'id' => $id, 'username' => (string) $m['username'], 'fullname' => (string) $m['fullname'], 'user_type' => (string) $m['user_type'],
            'status' => (string) $m['status'], 'frozen' => (bool) ($m['frozen'] ?? false) || $m['status'] !== 'Active',
            'level' => (int) ($m['level'] ?? 0), 'visible_parent_id' => isset($m['parent_id']) && $m['parent_id'] !== null ? (int) $m['parent_id'] : null,
            'customers_direct' => $s['direct'][$id] ?? 0, 'customers_subtree' => $agent ? ($s['subtree'][$id] ?? 0) : null,
            'customers_assigned' => $agent ? null : ($s['assigned'][$id] ?? 0),
            'sales_count' => $agent ? ($s['sales'][$id] ?? 0) : null, 'children_count' => $s['children'][$id] ?? 0,
            'can_status' => $id !== (int) $a->userId && StaffService::canTarget($a, $m, C::STAFF_DISABLE),
        ];
    }

    /** @return array{id:?int,username:?string,fullname:?string,label:?string}|null the parent, named only inside the scope */
    private static function parent(ActorContext $a, ?int $parentId): ?array
    {
        if ($parentId === null) {
            return null;
        }
        if (PermissionService::scopeOf($a, C::RESELLER_TREE_READ) === 'G' || $parentId === (int) $a->userId
            || DataScope::findStaff($a, C::RESELLER_TREE_READ, $parentId) !== null) {
            $u = \ORM::for_table('tbl_users')->select_many('id', 'username', 'fullname')->find_one($parentId);
            if ($u) {
                return ['id' => (int) $u['id'], 'username' => (string) $u['username'], 'fullname' => (string) $u['fullname'], 'label' => null];
            }
        }
        return ['id' => null, 'username' => null, 'fullname' => null, 'label' => 'upstream'];
    }

    /** @return array{total:int,active:int,expiring:int,expired:int} service-state counts (7-day expiring threshold, OD-L) */
    private static function serviceCounts(\PDO $pdo, string $column, int $id): array
    {
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+7 days'));
        $on = "EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = c.id AND sr.status = 'on' AND sr.expiration >= ?)";
        $r = Tx::row($pdo, 'SELECT COUNT(*) AS total, '
            . "SUM(CASE WHEN {$on} THEN 1 ELSE 0 END) AS active, "
            . "SUM(CASE WHEN EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = c.id AND sr.status = 'on' AND sr.expiration BETWEEN ? AND ?) THEN 1 ELSE 0 END) AS expiring, "
            . "SUM(CASE WHEN NOT {$on} AND EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = c.id) THEN 1 ELSE 0 END) AS expired "
            . "FROM tbl_customers c WHERE c.{$column} = ?", [$today, $today, $soon, $today, $id]) ?? [];
        return ['total' => (int) ($r['total'] ?? 0), 'active' => (int) ($r['active'] ?? 0), 'expiring' => (int) ($r['expiring'] ?? 0), 'expired' => (int) ($r['expired'] ?? 0)];
    }
}
