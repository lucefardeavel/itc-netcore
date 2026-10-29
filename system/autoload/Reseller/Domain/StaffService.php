<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability;
use Reseller\Security\PermissionService;

/**
 * Staff lifecycle (SEC-04 / OD-12 / OD-13 / C20): who may create, edit, re-role, disable and delete
 * which staff accounts. Server-side target-role table; user_type and parent are never trusted from
 * the form; an actor never changes its own type, status or parent; every change is audited.
 *
 * Reseller lifecycle (V1, D033 / 18_BUSINESS_RULES): an Agent is a reseller identity. Agent permanent
 * deletion is prohibited in V1 and an Agent's role can never be changed; the lifecycle is
 * disable / reactivate. Both rules are explicit and do not depend on references or foreign keys.
 */
final class StaffService
{
    public const PROFILE_FIELDS = ['username', 'fullname', 'phone', 'email', 'city', 'subdistrict', 'ward'];
    public const TREE_ROLES = ['Agent', 'Sales'];

    /** @return list<string> roles the actor may create */
    public static function creatableRoles(ActorContext $a): array
    {
        if (!PermissionService::can($a, Capability::STAFF_CREATE)) {
            return [];
        }
        return match ($a->role) {
            'SuperAdmin' => ['SuperAdmin', 'Admin', 'Report', 'Agent', 'Sales'],
            'Admin' => ['Report', 'Agent', 'Sales'],
            'Agent' => ['Agent', 'Sales'],
            default => [],
        };
    }

    /** Roles an actor may manage (edit others' profile/password, disable, re-role among). */
    public static function manageableRoles(ActorContext $a): array
    {
        return match ($a->role) {
            'SuperAdmin' => ['SuperAdmin', 'Admin', 'Report', 'Agent', 'Sales'],
            'Admin' => ['Report', 'Agent', 'Sales'],
            'Agent' => ['Agent', 'Sales'],
            default => [],
        };
    }

    /**
     * May $a perform $capability on target row $t? Scope + target-role rules, never self for
     * status/role/delete. Profile edits of oneself use self.update_profile.
     */
    public static function canTarget(ActorContext $a, array $t, string $capability): bool
    {
        $tid = (int) $t['id'];
        if ($tid === (int) $a->userId) {
            return $capability === Capability::STAFF_UPDATE_PROFILE && PermissionService::can($a, Capability::SELF_UPDATE_PROFILE);
        }
        $scope = PermissionService::scopeOf($a, $capability);
        if ($scope === PermissionService::NONE || !in_array($t['user_type'], self::manageableRoles($a), true)) {
            return false;
        }
        if ($capability === Capability::STAFF_DELETE && $a->role === 'Admin' && $t['user_type'] !== 'Report') {
            return false; // §3: Admin deletes Report accounts only (others are disabled)
        }
        if ($scope === 'G') {
            return true;
        }
        // D / T scopes: strict descendants via the closure
        $st = \ORM::get_db()->prepare('SELECT depth FROM tbl_manager_tree WHERE ancestor_id = ? AND descendant_id = ?');
        $st->execute([(int) $a->userId, $tid]);
        $depth = $st->fetchColumn();
        return $depth !== false && (int) $depth > 0;
    }

    /**
     * Creates a staff account. Agent/Sales accounts are enrolled in the hierarchy in the same
     * transaction. For an Agent actor the parent is always the actor itself (form value ignored).
     *
     * @param array{username:string,fullname:string,password:string,user_type:string,parent_id?:int|null,phone?:string,email?:string,city?:string,subdistrict?:string,ward?:string} $in
     */
    public static function create(ActorContext $a, array $in): int
    {
        $scope = ResellerTreeService::authorize($a, Capability::STAFF_CREATE);
        $role = (string) ($in['user_type'] ?? '');
        if (!in_array($role, self::creatableRoles($a), true)) {
            throw new DomainError('INVALID_ROLE');
        }
        self::validateProfile($in, true);
        $parentId = null;
        if (in_array($role, self::TREE_ROLES, true)) {
            if ($scope === 'C') {
                $parentId = (int) $a->userId; // Agent: direct children of self only
            } else {
                $p = $in['parent_id'] ?? null;
                $parentId = ($p === null || $p === '' || (int) $p === 0) ? null : (int) $p;
            }
            if ($role === 'Sales' && $parentId === null) {
                throw new DomainError('SALES_NEEDS_PARENT');
            }
        }
        $work = function (\PDO $pdo) use ($a, $in, $role, $parentId): int {
            ResellerTreeService::revalidateActor($pdo, $a);
            if (Tx::row($pdo, 'SELECT id FROM tbl_users WHERE username = ? FOR UPDATE', [$in['username']]) !== null) {
                throw new DomainError('USERNAME_TAKEN');
            }
            if ($parentId !== null) {
                ResellerTreeService::assertValidParent($pdo, $parentId);
            }
            Tx::exec(
                $pdo,
                "INSERT INTO tbl_users (username, fullname, password, user_type, phone, email, city, subdistrict, ward, status, root, creationdate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 0, ?)",
                [$in['username'], $in['fullname'], password_hash((string) $in['password'], PASSWORD_BCRYPT), $role, (string) ($in['phone'] ?? ''), (string) ($in['email'] ?? ''),
                    (string) ($in['city'] ?? ''), (string) ($in['subdistrict'] ?? ''), (string) ($in['ward'] ?? ''), gmdate('Y-m-d H:i:s')]
            );
            $id = (int) $pdo->lastInsertId();
            if (in_array($role, self::TREE_ROLES, true)) {
                ResellerTreeService::insertClosure($pdo, $id, $parentId);
            }
            AuditLog::record('staff.create', $a, [
                'target_type' => 'staff', 'target_user_id' => $id,
                'target_snapshot' => ['id' => $id, 'username' => $in['username'], 'user_type' => $role, 'status' => 'Active', 'root' => $parentId ?? 0],
                'after' => ['user_type' => $role, 'parent_id' => $parentId, 'enrolled' => in_array($role, self::TREE_ROLES, true)],
            ], $pdo);
            return $id;
        };
        return in_array($role, self::TREE_ROLES, true) ? Tx::withTreeLock($work) : Tx::run($work);
    }

    /**
     * Profile (and optional password) update of self or a manageable target. user_type, status and
     * parent are NOT changed here (see changeRole / setStatus / ResellerTreeService).
     *
     * @param array<string,mixed> $in
     */
    public static function updateProfile(ActorContext $a, int $id, array $in, ?string $newPassword = null): void
    {
        self::validateProfile($in, false);
        $ready = SchemaReadiness::isReady();
        if (!$ready && $id !== (int) $a->userId) {
            throw new DomainError('SCHEMA_NOT_READY'); // only the own profile remains editable
        }
        Tx::run(function (\PDO $pdo) use ($a, $id, $in, $newPassword, $ready): void {
            if ($ready) {
                ResellerTreeService::revalidateActor($pdo, $a);
            } elseif (($r = Tx::row($pdo, 'SELECT status FROM tbl_users WHERE id = ? FOR UPDATE', [(int) $a->userId])) === null || $r['status'] !== 'Active') {
                throw new DomainError('ACTOR_FROZEN');
            }
            $t = Tx::row($pdo, 'SELECT * FROM tbl_users WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainError('NOT_FOUND');
            if (!self::canTarget($a, $t, Capability::STAFF_UPDATE_PROFILE)) {
                throw new DomainError('NOT_FOUND');
            }
            // final QA S-2: a non-administrator cannot rename itself. A free self-rename would otherwise answer "is this
            // username taken anywhere?" for any name (e.g. the administrator's login) at no cost; refused before the lookup
            if ($in['username'] !== $t['username'] && $id === (int) $a->userId && !$a->isGlobalAdmin()) {
                throw new DomainError('USERNAME_SELF_CHANGE_ADMIN_ONLY');
            }
            if ($in['username'] !== $t['username'] && Tx::row($pdo, 'SELECT id FROM tbl_users WHERE username = ? AND id <> ?', [$in['username'], $id]) !== null) {
                throw new DomainError('USERNAME_TAKEN');
            }
            $before = [];
            $after = [];
            $sets = [];
            $vals = [];
            foreach (self::PROFILE_FIELDS as $f) {
                if (array_key_exists($f, $in) && (string) $in[$f] !== (string) $t[$f]) {
                    $before[$f] = $t[$f];
                    $after[$f] = (string) $in[$f];
                    $sets[] = "`{$f}` = ?";
                    $vals[] = (string) $in[$f];
                }
            }
            if ($newPassword !== null && $newPassword !== '') {
                // WP9-B / N-15 P3 (OD-W9-6 option B): a tree-scoped actor resets the password of a DIRECT child only
                // (audited; existing sessions end because the staff fingerprint binds the password hash)
                if ($id !== (int) $a->userId && PermissionService::scopeOf($a, Capability::STAFF_UPDATE_PROFILE) !== 'G'
                    && Tx::row($pdo, 'SELECT 1 AS x FROM tbl_manager_tree WHERE ancestor_id = ? AND descendant_id = ? AND depth = 1', [(int) $a->userId, $id]) === null) {
                    throw new DomainError('STAFF_PASSWORD_DIRECT_CHILD_ONLY');
                }
                if (strlen($newPassword) < 6) {
                    throw new DomainError('INVALID_INPUT', 'Password should be minimum 6 characters');
                }
                $sets[] = '`password` = ?';
                $vals[] = password_hash($newPassword, PASSWORD_BCRYPT);   // WP9 / SEC-17
                $after['password_changed'] = true;
            }
            if (isset($in['photo']) && is_string($in['photo'])) {
                $sets[] = '`photo` = ?';
                $vals[] = $in['photo'];
            }
            if ($sets === []) {
                return;
            }
            $vals[] = $id;
            Tx::exec($pdo, 'UPDATE tbl_users SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
            if (!$ready) {
                return; // audit table not available; own-profile change only
            }
            AuditLog::record('staff.update', $a, [
                'target_type' => 'staff', 'target_user_id' => $id, 'target_snapshot' => ResellerTreeService::snapshot($t),
                'before' => $before, 'after' => $after,
            ], $pdo);
        });
    }

    /** OD-13: disable / reactivate (status only; descendants become frozen, history untouched). */
    public static function setStatus(ActorContext $a, int $id, string $status): void
    {
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            throw new DomainError('INVALID_INPUT');
        }
        ResellerTreeService::authorize($a, Capability::STAFF_DISABLE);
        if ($id === (int) $a->userId) {
            throw new DomainError('SELF_TARGET');
        }
        Tx::withTreeLock(function (\PDO $pdo) use ($a, $id, $status): void {
            ResellerTreeService::revalidateActor($pdo, $a);
            $t = ResellerTreeService::lockUsers($pdo, [$id])[$id] ?? throw new DomainError('NOT_FOUND');
            if (!self::canTarget($a, $t, Capability::STAFF_DISABLE)) {
                throw new DomainError('NOT_FOUND');
            }
            if ($t['status'] === $status) {
                return;
            }
            // WP9-B / N-15 P2 (OD-W9-6 option B): an account disabled by a global administrator (or by an unknown path)
            // is reactivated by a global administrator only; an Agent may still reactivate what it disabled itself
            if ($status === 'Active' && PermissionService::scopeOf($a, Capability::STAFF_DISABLE) !== 'G') {
                $by = Tx::row($pdo, "SELECT actor_snapshot FROM tbl_reseller_audit_events WHERE event_type = 'staff.disable' AND target_user_id = ? ORDER BY id DESC LIMIT 1", [$id]);
                $role = $by === null ? null : (json_decode((string) $by['actor_snapshot'], true)['role'] ?? null);
                if ($by === null || in_array($role, ['SuperAdmin', 'Admin'], true)) {
                    throw new DomainError('STAFF_REACTIVATION_ADMIN_ONLY');
                }
            }
            if ($status === 'Inactive' && $t['user_type'] === 'SuperAdmin') {
                self::assertNotLastSuperAdmin($pdo, $id);
            }
            Tx::exec($pdo, 'UPDATE tbl_users SET status = ? WHERE id = ?', [$status, $id]);
            AuditLog::record($status === 'Inactive' ? 'staff.disable' : 'staff.reactivate', $a, [
                'target_type' => 'staff', 'target_user_id' => $id, 'target_snapshot' => ResellerTreeService::snapshot($t),
                'before' => ['status' => $t['status']], 'after' => ['status' => $status],
            ], $pdo);
        });
    }

    /**
     * "Tree-safe" role change only: refused for an account that is enrolled, owns or is assigned
     * customers, or is anyone's legacy root. Disable it and create a new one instead.
     * An Agent's role is never changed (reseller identity, V1): re-labelling an Agent would otherwise
     * open a path to its permanent deletion.
     */
    public static function changeRole(ActorContext $a, int $id, string $role): void
    {
        ResellerTreeService::authorize($a, Capability::STAFF_CHANGE_ROLE);
        if ($id === (int) $a->userId) {
            throw new DomainError('SELF_TARGET');
        }
        if (!in_array($role, self::manageableRoles($a), true)) {
            throw new DomainError('INVALID_ROLE');
        }
        Tx::withTreeLock(function (\PDO $pdo) use ($a, $id, $role): void {
            ResellerTreeService::revalidateActor($pdo, $a);
            $t = ResellerTreeService::lockUsers($pdo, [$id])[$id] ?? throw new DomainError('NOT_FOUND');
            if (!self::canTarget($a, $t, Capability::STAFF_CHANGE_ROLE)) {
                throw new DomainError('NOT_FOUND');
            }
            if ($t['user_type'] === $role) {
                return;
            }
            if ($t['user_type'] === 'Agent') {
                throw new DomainError('AGENT_ROLE_PERMANENT'); // reseller identity: disable / reactivate only
            }
            if ($t['user_type'] === 'SuperAdmin') {
                self::assertNotLastSuperAdmin($pdo, $id);
            }
            $ref = Tx::row(
                $pdo,
                'SELECT (SELECT COUNT(*) FROM tbl_manager_tree WHERE ancestor_id = ? OR descendant_id = ?) + (SELECT COUNT(*) FROM tbl_customers WHERE manager_id = ? OR assigned_sales_id = ?) + (SELECT COUNT(*) FROM tbl_users WHERE root = ?) AS n',
                [$id, $id, $id, $id, $id]
            );
            if ((int) ($ref['n'] ?? 1) > 0) {
                throw new DomainError('ROLE_CHANGE_UNSAFE');
            }
            // a re-labelled Agent/Sales is NOT enrolled: it has no tree scope until explicitly placed (OD-6)
            Tx::exec($pdo, 'UPDATE tbl_users SET user_type = ? WHERE id = ?', [$role, $id]);
            AuditLog::record('staff.role_change', $a, [
                'target_type' => 'staff', 'target_user_id' => $id, 'target_snapshot' => ResellerTreeService::snapshot($t),
                'before' => ['user_type' => $t['user_type']], 'after' => ['user_type' => $role],
            ], $pdo);
        });
    }

    /**
     * Deletes an unreferenced, never-enrolled non-Agent account; otherwise refuses (disable instead).
     * Agent permanent deletion is prohibited in V1 (D033): always refused, for every actor, whether the
     * Agent is enrolled or legacy, referenced or not. The lifecycle is disable / reactivate (setStatus).
     */
    public static function delete(ActorContext $a, int $id): void
    {
        ResellerTreeService::authorize($a, Capability::STAFF_DELETE);
        if ($id === (int) $a->userId) {
            throw new DomainError('SELF_TARGET');
        }
        Tx::withTreeLock(function (\PDO $pdo) use ($a, $id): void {
            ResellerTreeService::revalidateActor($pdo, $a);
            $t = ResellerTreeService::lockUsers($pdo, [$id])[$id] ?? throw new DomainError('NOT_FOUND');
            if (!self::canTarget($a, $t, Capability::STAFF_DELETE)) {
                throw new DomainError('NOT_FOUND');
            }
            if ($t['user_type'] === 'Agent') {
                throw new DomainError('AGENT_DELETE_PROHIBITED'); // explicit V1 rule, independent of references
            }
            if ($t['user_type'] === 'SuperAdmin') {
                self::assertNotLastSuperAdmin($pdo, $id);
            }
            if (self::isReferenced($pdo, 'tbl_users', $id) || Tx::row($pdo, 'SELECT id FROM tbl_users WHERE root = ? LIMIT 1', [$id]) !== null) {
                throw new DomainError('REFERENCED');
            }
            // the role predicate repeats the rule at the statement level (defence in depth)
            Tx::exec($pdo, "DELETE FROM tbl_users WHERE id = ? AND user_type <> 'Agent'", [$id]);
            AuditLog::record('staff.delete', $a, [
                'target_type' => 'staff', 'target_user_id' => $id, 'target_snapshot' => ResellerTreeService::snapshot($t),
                'before' => ResellerTreeService::snapshot($t),
            ], $pdo);
        });
    }

    /** True if any foreign key in the schema references $table.id = $id (generic, schema-driven). */
    public static function isReferenced(\PDO $pdo, string $table, int $id): bool
    {
        $refs = Tx::rows(
            $pdo,
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = 'id'",
            [$table]
        );
        foreach ($refs as $r) {
            $t = (string) $r['TABLE_NAME'];
            $c = (string) $r['COLUMN_NAME'];
            if (!preg_match('/^[A-Za-z0-9_]+$/', $t . $c)) {
                return true;
            }
            if (Tx::row($pdo, "SELECT 1 AS x FROM `{$t}` WHERE `{$c}` = ? LIMIT 1", [$id]) !== null) {
                return true;
            }
        }
        return false;
    }

    private static function assertNotLastSuperAdmin(\PDO $pdo, int $id): void
    {
        $r = Tx::row($pdo, "SELECT COUNT(*) AS n FROM tbl_users WHERE user_type = 'SuperAdmin' AND status = 'Active' AND id <> ?", [$id]);
        if ((int) ($r['n'] ?? 0) === 0) {
            throw new DomainError('LAST_SUPERADMIN');
        }
    }

    /** @param array<string,mixed> $in */
    private static function validateProfile(array $in, bool $create): void
    {
        $u = (string) ($in['username'] ?? '');
        $f = (string) ($in['fullname'] ?? '');
        if (mb_strlen($u) < 3 || mb_strlen($u) > 45 || mb_strlen($f) < 3 || mb_strlen($f) > 45) {
            throw new DomainError('INVALID_INPUT', 'Username and full name must be between 3 and 45 characters');
        }
        if ($create && strlen((string) ($in['password'] ?? '')) < 6) {
            throw new DomainError('INVALID_INPUT', 'Password should be minimum 6 characters');
        }
        foreach (['phone' => 32, 'email' => 128, 'city' => 64, 'subdistrict' => 64, 'ward' => 64] as $k => $max) {
            if (isset($in[$k]) && mb_strlen((string) $in[$k]) > $max) {
                throw new DomainError('INVALID_INPUT');
            }
        }
    }
}
