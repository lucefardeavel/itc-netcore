<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability;
use Reseller\Security\PermissionService;

/**
 * Reseller hierarchy (closure table tbl_manager_tree) — WP2 §7. No finance.
 *
 * Every mutation: global tree lock taken before BEGIN → involved tbl_users rows locked FOR UPDATE in
 * ascending id order → actor and targets revalidated → closure + root compatibility field + audit
 * written in one transaction. A partial closure is never committed.
 *
 * Rules: parents are enrolled, Active, non-frozen Agents; Sales are leaves and always have an Agent
 * parent; no self-parent, no cycles; root (legacy field) always mirrors the depth-1 parent (0 = top).
 * tbl_users.root is never imported (C20) and never read as authority.
 */
final class ResellerTreeService
{
    /** @return array<int,int> ancestor_id => depth (self row included) */
    public static function ancestors(\PDO $pdo, int $id): array
    {
        $out = [];
        foreach (Tx::rows($pdo, 'SELECT ancestor_id, depth FROM tbl_manager_tree WHERE descendant_id = ? ORDER BY depth', [$id]) as $r) {
            $out[(int) $r['ancestor_id']] = (int) $r['depth'];
        }
        return $out;
    }

    /** @return array<int,int> descendant_id => depth (self row included) */
    public static function subtree(\PDO $pdo, int $id): array
    {
        $out = [];
        foreach (Tx::rows($pdo, 'SELECT descendant_id, depth FROM tbl_manager_tree WHERE ancestor_id = ? ORDER BY depth, descendant_id', [$id]) as $r) {
            $out[(int) $r['descendant_id']] = (int) $r['depth'];
        }
        return $out;
    }

    public static function isEnrolled(\PDO $pdo, int $id): bool
    {
        return Tx::row($pdo, 'SELECT 1 AS x FROM tbl_manager_tree WHERE ancestor_id = ? AND descendant_id = ? AND depth = 0', [$id, $id]) !== null;
    }

    /** @return array<int,array<string,mixed>> id => row, locked FOR UPDATE in ascending id order */
    public static function lockUsers(\PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        sort($ids);
        $out = [];
        foreach ($ids as $id) {
            $r = Tx::row($pdo, 'SELECT * FROM tbl_users WHERE id = ? FOR UPDATE', [$id]);
            if ($r !== null) {
                $out[$id] = $r;
            }
        }
        return $out;
    }

    /**
     * Re-reads and locks the actor inside the transaction; refuses if the actor changed role, is not
     * Active, is frozen (an ancestor is not Active) or lost enrolment since the context was built.
     *
     * @return array<string,mixed>
     */
    public static function revalidateActor(\PDO $pdo, ActorContext $actor): array
    {
        if (!$actor->isStaff()) {
            throw new DomainError('FORBIDDEN');
        }
        $row = self::lockUsers($pdo, [(int) $actor->userId])[(int) $actor->userId] ?? null;
        if ($row === null || $row['status'] !== 'Active' || $row['user_type'] !== $actor->role) {
            throw new DomainError('ACTOR_FROZEN');
        }
        if (in_array($actor->role, ['Agent', 'Sales'], true)) {
            $anc = self::ancestors($pdo, (int) $actor->userId);
            if (!isset($anc[(int) $actor->userId])) {
                throw new DomainError('NOT_ENROLLED');
            }
            if (self::anyInactive($pdo, array_keys($anc))) {
                throw new DomainError('ACTOR_FROZEN');
            }
        }
        return $row;
    }

    /**
     * A valid parent: role Agent, Active, enrolled, and no inactive ancestor.
     *
     * @return array<string,mixed>
     */
    public static function assertValidParent(\PDO $pdo, int $parentId): array
    {
        $row = Tx::row($pdo, 'SELECT * FROM tbl_users WHERE id = ? FOR UPDATE', [$parentId]);
        if ($row === null) {
            throw new DomainError('PARENT_INACTIVE');
        }
        if ($row['user_type'] !== 'Agent') {
            throw new DomainError($row['user_type'] === 'Sales' ? 'SALES_HAS_CHILDREN' : 'INVALID_PARENT_ROLE');
        }
        $anc = self::ancestors($pdo, $parentId);
        if ($row['status'] !== 'Active' || !isset($anc[$parentId]) || self::anyInactive($pdo, array_keys($anc))) {
            throw new DomainError('PARENT_INACTIVE');
        }
        return $row;
    }

    /** Inserts the closure rows for a newly enrolled leaf $id under $parentId (null = top level). */
    public static function insertClosure(\PDO $pdo, int $id, ?int $parentId): void
    {
        $now = Tx::utcNow();
        Tx::exec($pdo, 'INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES (?, ?, 0, ?)', [$id, $id, $now]);
        if ($parentId !== null) {
            foreach (self::ancestors($pdo, $parentId) as $anc => $depth) {
                Tx::exec($pdo, 'INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES (?, ?, ?, ?)', [$anc, $id, $depth + 1, $now]);
            }
        }
        Tx::exec($pdo, 'UPDATE tbl_users SET root = ? WHERE id = ?', [$parentId ?? 0, $id]);
    }

    /**
     * OD-6: explicit onboarding of an existing, not yet enrolled Agent/Sales by SuperAdmin/Admin.
     * The legacy root value is shown to the admin but never applied automatically; it is overwritten
     * to match the chosen parent and the old value is audited.
     */
    public static function placeExisting(ActorContext $actor, int $userId, ?int $parentId): void
    {
        self::authorize($actor, Capability::RESELLER_PLACE);
        Tx::withTreeLock(function (\PDO $pdo) use ($actor, $userId, $parentId): void {
            self::revalidateActor($pdo, $actor);
            $locked = self::lockUsers($pdo, array_filter([$userId, $parentId]));
            $user = $locked[$userId] ?? throw new DomainError('NOT_FOUND');
            if (!in_array($user['user_type'], ['Agent', 'Sales'], true)) {
                throw new DomainError('INVALID_ROLE');
            }
            if (self::isEnrolled($pdo, $userId)) {
                throw new DomainError('ALREADY_ENROLLED');
            }
            if ($parentId !== null && $parentId === $userId) {
                throw new DomainError('SELF_PARENT');
            }
            if ($user['user_type'] === 'Sales' && $parentId === null) {
                throw new DomainError('SALES_NEEDS_PARENT');
            }
            if ($parentId !== null) {
                self::assertValidParent($pdo, $parentId);
            }
            self::insertClosure($pdo, $userId, $parentId);
            AuditLog::record('tree.place', $actor, [
                'target_type' => 'staff', 'target_user_id' => $userId,
                'target_snapshot' => self::snapshot($user),
                'before' => ['enrolled' => false, 'legacy_root' => (int) $user['root']],
                'after' => ['enrolled' => true, 'parent_id' => $parentId, 'root' => $parentId ?? 0],
            ], $pdo);
        });
    }

    /**
     * Moves the subtree rooted at $nodeId under $newParentId (null = top level, Agents only).
     * Sales assignments that no longer match the moved Sales user's manager are cleared and audited
     * (customer ownership itself never changes). Returns the ids of customers whose Sales was cleared.
     *
     * @return list<int>
     */
    public static function moveSubtree(ActorContext $actor, int $nodeId, ?int $newParentId): array
    {
        self::authorize($actor, Capability::RESELLER_MOVE);
        return Tx::withTreeLock(function (\PDO $pdo) use ($actor, $nodeId, $newParentId): array {
            self::revalidateActor($pdo, $actor);
            if ($newParentId !== null && $newParentId === $nodeId) {
                throw new DomainError('SELF_PARENT');
            }
            $oldAnc = self::ancestors($pdo, $nodeId);
            if (!isset($oldAnc[$nodeId])) {
                throw new DomainError('NOT_ENROLLED');
            }
            $sub = self::subtree($pdo, $nodeId);
            $lockIds = array_merge([$nodeId], array_keys($oldAnc), $newParentId !== null ? array_keys(self::ancestors($pdo, $newParentId)) : []);
            $locked = self::lockUsers($pdo, $lockIds);
            $node = $locked[$nodeId] ?? throw new DomainError('NOT_FOUND');
            if (!in_array($node['user_type'], ['Agent', 'Sales'], true)) {
                throw new DomainError('INVALID_ROLE');
            }
            if ($node['user_type'] === 'Sales' && $newParentId === null) {
                throw new DomainError('SALES_NEEDS_PARENT');
            }
            if ($newParentId !== null && isset($sub[$newParentId])) {
                throw new DomainError('CYCLE');
            }
            if ($newParentId !== null) {
                self::assertValidParent($pdo, $newParentId);
            }
            $oldParent = null;
            foreach ($oldAnc as $a => $d) {
                if ($d === 1) {
                    $oldParent = $a;
                }
            }
            // detach: remove every link between the node's strict ancestors and the subtree members
            $subIds = array_keys($sub);
            $ancIds = array_keys(array_filter($oldAnc, static fn(int $d) => $d > 0));
            if ($ancIds !== []) {
                Tx::exec(
                    $pdo,
                    'DELETE FROM tbl_manager_tree WHERE descendant_id IN (' . self::ph($subIds) . ') AND ancestor_id IN (' . self::ph($ancIds) . ')',
                    array_merge($subIds, $ancIds)
                );
            }
            // attach: cross product ancestors(newParent) ∪ {newParent} × subtree
            if ($newParentId !== null) {
                $now = Tx::utcNow();
                foreach (self::ancestors($pdo, $newParentId) as $a => $da) {
                    foreach ($sub as $s => $ds) {
                        Tx::exec($pdo, 'INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES (?, ?, ?, ?)', [$a, $s, $da + $ds + 1, $now]);
                    }
                }
            }
            Tx::exec($pdo, 'UPDATE tbl_users SET root = ? WHERE id = ?', [$newParentId ?? 0, $nodeId]);
            $cleared = [];
            if ($node['user_type'] === 'Sales') {
                foreach (Tx::rows($pdo, 'SELECT id, username, manager_id, assigned_sales_id FROM tbl_customers WHERE assigned_sales_id = ? AND (manager_id IS NULL OR manager_id <> ?) FOR UPDATE', [$nodeId, (int) $newParentId]) as $c) {
                    Tx::exec($pdo, 'UPDATE tbl_customers SET assigned_sales_id = NULL WHERE id = ?', [(int) $c['id']]);
                    $cleared[] = (int) $c['id'];
                    AuditLog::record('customer.clear_sales', $actor, [
                        'target_type' => 'customer', 'target_customer_id' => (int) $c['id'],
                        'target_snapshot' => ['username' => $c['username'], 'manager_id' => $c['manager_id']],
                        'before' => ['assigned_sales_id' => (int) $c['assigned_sales_id']], 'after' => ['assigned_sales_id' => null],
                        'reason' => 'Sales moved to another manager',
                    ], $pdo);
                }
            }
            AuditLog::record('tree.move', $actor, [
                'target_type' => 'staff', 'target_user_id' => $nodeId, 'target_snapshot' => self::snapshot($node),
                'before' => ['parent_id' => $oldParent, 'root' => (int) $node['root']],
                'after' => ['parent_id' => $newParentId, 'root' => $newParentId ?? 0, 'subtree_size' => count($sub), 'cleared_sales_customers' => count($cleared)],
            ], $pdo);
            return $cleared;
        });
    }

    /**
     * Consistency report used by tests, the hierarchy CLI and diagnostics.
     *
     * @return list<array{code:string,user_id:int,detail:string}>
     */
    public static function verify(\PDO $pdo): array
    {
        $issues = [];
        $users = [];
        foreach (Tx::rows($pdo, 'SELECT id, user_type, status, root FROM tbl_users') as $u) {
            $users[(int) $u['id']] = $u;
        }
        $rows = Tx::rows($pdo, 'SELECT ancestor_id, descendant_id, depth FROM tbl_manager_tree');
        $byDesc = [];
        foreach ($rows as $r) {
            $byDesc[(int) $r['descendant_id']][(int) $r['ancestor_id']] = (int) $r['depth'];
        }
        foreach ($byDesc as $d => $ancs) {
            if (($ancs[$d] ?? null) !== 0) {
                $issues[] = ['code' => 'MISSING_SELF_ROW', 'user_id' => $d, 'detail' => 'no depth-0 row'];
            }
            $depths = array_values($ancs);
            sort($depths);
            if ($depths !== range(0, count($depths) - 1)) {
                $issues[] = ['code' => 'DEPTH_GAP', 'user_id' => $d, 'detail' => 'depths ' . implode(',', $depths)];
            }
            $parent = array_search(1, $ancs, true);
            $root = (int) ($users[$d]['root'] ?? -1);
            if (($parent === false ? 0 : (int) $parent) !== $root) {
                $issues[] = ['code' => 'ROOT_MISMATCH', 'user_id' => $d, 'detail' => 'closure parent ' . ($parent === false ? 0 : $parent) . ' root ' . $root];
            }
            if (!isset($users[$d])) {
                $issues[] = ['code' => 'ORPHAN', 'user_id' => $d, 'detail' => 'unknown user'];
                continue;
            }
            if (!in_array($users[$d]['user_type'], ['Agent', 'Sales'], true)) {
                $issues[] = ['code' => 'NON_TREE_ROLE', 'user_id' => $d, 'detail' => (string) $users[$d]['user_type']];
            }
            if ($parent !== false && ($users[(int) $parent]['user_type'] ?? '') !== 'Agent') {
                $issues[] = ['code' => 'PARENT_NOT_AGENT', 'user_id' => $d, 'detail' => 'parent ' . $parent];
            }
            if ($users[$d]['user_type'] === 'Sales' && $parent === false) {
                $issues[] = ['code' => 'SALES_WITHOUT_PARENT', 'user_id' => $d, 'detail' => ''];
            }
            // transitivity: every ancestor of my parent is my ancestor at +1
            if ($parent !== false) {
                foreach ($byDesc[(int) $parent] ?? [] as $pa => $pd) {
                    if (($ancs[$pa] ?? null) !== $pd + 1) {
                        $issues[] = ['code' => 'NOT_TRANSITIVE', 'user_id' => $d, 'detail' => 'missing ancestor ' . $pa];
                    }
                }
            }
        }
        return $issues;
    }

    /** Capability + schema readiness check that throws (services never render responses). */
    public static function authorize(ActorContext $actor, string $capability): string
    {
        self::requireReady();
        $scope = PermissionService::scopeOf($actor, $capability);
        if ($scope === PermissionService::NONE) {
            throw new DomainError('FORBIDDEN');
        }
        return $scope;
    }

    public static function requireReady(): void
    {
        if (!SchemaReadiness::isReady()) {
            throw new DomainError('SCHEMA_NOT_READY');
        }
    }

    /** @param list<int> $ids */
    private static function anyInactive(\PDO $pdo, array $ids): bool
    {
        if ($ids === []) {
            return false;
        }
        $r = Tx::row($pdo, "SELECT COUNT(*) AS n FROM tbl_users WHERE id IN (" . self::ph($ids) . ") AND status <> 'Active'", $ids);
        return (int) ($r['n'] ?? 0) > 0;
    }

    /** @param list<mixed> $values */
    public static function ph(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /** Identity snapshot for audit (no credentials). @param array<string,mixed> $u */
    public static function snapshot(array $u): array
    {
        return [
            'id' => (int) $u['id'], 'username' => (string) $u['username'], 'user_type' => (string) $u['user_type'],
            'status' => (string) $u['status'], 'root' => (int) ($u['root'] ?? 0),
        ];
    }
}
