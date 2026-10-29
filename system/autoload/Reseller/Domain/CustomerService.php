<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability;
use Reseller\Security\DataScope;
use Reseller\Security\ErrorReporter;
use Reseller\Security\PermissionService;

/**
 * Customer ownership (WP2 §8; C21, OD-6, OD-7). Ownership is resolved server-side; posted
 * manager_id / assigned_sales_id are never trusted from Agent/Sales. created_by stays a legacy audit
 * field and is never read as ownership. No automatic assignment of legacy customers.
 *
 *   Sales creates  → manager = the Sales user's manager (depth-1 Agent), sales = self
 *   Agent creates  → manager = self; optional Sales = a direct Sales child of self (else rejected)
 *   Admin/SA       → manager NULL (legacy-unassigned) or an enrolled Active Agent; optional Sales
 *                    only with a manager and only a direct Sales child of that manager
 */
final class CustomerService
{
    private const PROJECT_TABLE = '/^tbl_(reseller|manager|project)_/';

    /**
     * @param array<string,mixed> $in legacy add-post fields + optional manager_id / assigned_sales_id (G scope only)
     * @return int new customer id
     */
    public static function create(ActorContext $a, array $in, array $customFields = []): int
    {
        $scope = ResellerTreeService::authorize($a, Capability::CUSTOMER_CREATE);
        self::validateCustomer($in);
        $manager = null;
        $sales = null;
        switch ($scope) {
            case 'A':
                $manager = (int) $a->managerId;
                $sales = (int) $a->userId;
                break;
            case 'T':
                $manager = (int) $a->userId;
                $sales = self::optionalId($in['assigned_sales_id'] ?? null);
                break;
            case 'G':
                $manager = self::optionalId($in['manager_id'] ?? null);
                $sales = $manager === null ? null : self::optionalId($in['assigned_sales_id'] ?? null);
                if ($manager === null && self::optionalId($in['assigned_sales_id'] ?? null) !== null) {
                    throw new DomainError('INVALID_SALES');
                }
                break;
            default:
                throw new DomainError('FORBIDDEN');
        }
        // WP9 U0 (P1, AM-W9-1): tree lock (L-1) -> identity lock (L-3) -> BEGIN; the same tree-lock semantics as
        // Tx::withTreeLock (same lock, taken before the transaction); the identity check runs inside the transaction
        // that inserts, which commits before the identity lock is released
        return TreeSerialization::hold(static fn(): int => CustomerIdentity::hold(static fn(): int => Tx::run(function (\PDO $pdo) use ($a, $in, $customFields, $manager, $sales, $scope): int {
            ResellerTreeService::revalidateActor($pdo, $a);
            if ($manager !== null) {
                self::assertOwner($pdo, $manager);
            }
            if ($sales !== null) {
                self::assertSalesOf($pdo, $sales, (int) $manager);
            }
            Tx::exec(
                $pdo,
                'INSERT INTO tbl_customers (username, password, pppoe_username, pppoe_password, pppoe_ip, email, account_type, fullname, address, created_by, phonenumber, service_type, coordinates, city, district, state, zip, manager_id, assigned_sales_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $in['username'], $in['password'], (string) ($in['pppoe_username'] ?? ''), (string) ($in['pppoe_password'] ?? ''), (string) ($in['pppoe_ip'] ?? ''),
                    (string) ($in['email'] ?? ''), self::accountType($in['account_type'] ?? null), $in['fullname'], (string) ($in['address'] ?? ''),
                    (int) $a->userId, (string) ($in['phonenumber'] ?? ''), self::serviceType($pdo, $in['service_type'] ?? null), (string) ($in['coordinates'] ?? ''),
                    (string) ($in['city'] ?? ''), (string) ($in['district'] ?? ''), (string) ($in['state'] ?? ''), (string) ($in['zip'] ?? ''),
                    $manager, $sales,
                ]
            );
            $id = (int) $pdo->lastInsertId();
            foreach ($customFields as $name => $value) {
                if (is_string($name) && $name !== '') {
                    Tx::exec($pdo, 'INSERT INTO tbl_customers_fields (customer_id, field_name, field_value) VALUES (?, ?, ?)', [$id, mb_substr($name, 0, 255), mb_substr((string) $value, 0, 255)]);
                }
            }
            AuditLog::record('customer.create', $a, [
                'target_type' => 'customer', 'target_customer_id' => $id,
                'target_snapshot' => ['id' => $id, 'username' => $in['username']],
                'after' => ['manager_id' => $manager, 'assigned_sales_id' => $sales],
            ], $pdo);
            // R-HF-CARRY-01 F-1: the uniqueness checks run LAST, after every other step of this create (validation, owner
            // and Sales checks, the row, its custom fields, its audit event; a refusal rolls all of it back), so a refusal
            // can only answer a request that would otherwise have created a real customer. Tree-scoped actors get ONE
            // generic refusal whichever identity collides (no known-taken name can be combined with a probe name).
            try {
                if (Tx::row($pdo, 'SELECT id FROM tbl_customers WHERE username = ? AND id <> ? FOR UPDATE', [$in['username'], $id]) !== null) {
                    throw new DomainError('USERNAME_TAKEN', 'Account already exists');
                }
                // I-1 (cross-field, canonical, customers + live voucher identities + RADIUS keys; the new row excluded)
                CustomerIdentity::assertAvailable($pdo, array_values(array_filter([(string) $in['username'], (string) ($in['pppoe_username'] ?? '')], static fn(string $n): bool => trim($n) !== '')), $id);
            } catch (DomainError $e) {
                if ($scope === 'G' || !in_array($e->reason, ['USERNAME_TAKEN', 'IDENTITY_TAKEN'], true)) {
                    throw $e;
                }
                ErrorReporter::log('customer.create_identity_refused', 'actor=' . (int) $a->userId . ' scope=' . $scope);
                throw new DomainError('CUSTOMER_IDENTITY_TAKEN');
            }
            return $id;
        })));
    }

    /** OD-7: assign (or clear with null) the Sales of an owned customer inside the actor's scope. */
    public static function assignSales(ActorContext $a, int $customerId, ?int $salesId): void
    {
        ResellerTreeService::authorize($a, Capability::CUSTOMER_ASSIGN_SALES);
        Tx::withTreeLock(function (\PDO $pdo) use ($a, $customerId, $salesId): void {
            ResellerTreeService::revalidateActor($pdo, $a);
            $c = self::lockScopedCustomer($pdo, $a, Capability::CUSTOMER_ASSIGN_SALES, $customerId);
            if ($c['manager_id'] === null) {
                throw new DomainError('INVALID_SALES', 'Unassigned customers have no Sales; set an owner first.');
            }
            // WP9-B / N-15 P4 (OD-W9-6 option B): only the owning reseller (or a global administrator) re-assigns the
            // Sales of a customer; an ancestor Agent no longer re-assigns a child Agent's customer
            if (PermissionService::scopeOf($a, Capability::CUSTOMER_ASSIGN_SALES) !== 'G' && (int) $c['manager_id'] !== (int) $a->userId) {
                throw new DomainError('SALES_ASSIGN_OWNER_ONLY');
            }
            if ($salesId !== null) {
                self::assertSalesOf($pdo, $salesId, (int) $c['manager_id']);
            }
            $old = $c['assigned_sales_id'] === null ? null : (int) $c['assigned_sales_id'];
            if ($old === $salesId) {
                return;
            }
            Tx::exec($pdo, 'UPDATE tbl_customers SET assigned_sales_id = ? WHERE id = ?', [$salesId, $customerId]);
            AuditLog::record($salesId === null ? 'customer.clear_sales' : 'customer.assign_sales', $a, [
                'target_type' => 'customer', 'target_customer_id' => $customerId,
                'target_snapshot' => ['username' => $c['username'], 'manager_id' => (int) $c['manager_id']],
                'before' => ['assigned_sales_id' => $old], 'after' => ['assigned_sales_id' => $salesId],
            ], $pdo);
        });
    }

    /**
     * OD-7: owner transfer (SuperAdmin/Admin only). The current Sales is cleared unless it already
     * belongs to the new owner, or replaced by a valid Sales of the new owner. History is untouched.
     */
    public static function transferOwner(ActorContext $a, int $customerId, int $newManagerId, ?int $replacementSalesId = null): void
    {
        ResellerTreeService::authorize($a, Capability::CUSTOMER_TRANSFER_OWNER);
        Tx::withTreeLock(function (\PDO $pdo) use ($a, $customerId, $newManagerId, $replacementSalesId): void {
            ResellerTreeService::revalidateActor($pdo, $a);
            $c = self::lockScopedCustomer($pdo, $a, Capability::CUSTOMER_TRANSFER_OWNER, $customerId);
            self::assertOwner($pdo, $newManagerId);
            $oldSales = $c['assigned_sales_id'] === null ? null : (int) $c['assigned_sales_id'];
            $newSales = null;
            if ($replacementSalesId !== null) {
                self::assertSalesOf($pdo, $replacementSalesId, $newManagerId);
                $newSales = $replacementSalesId;
            } elseif ($oldSales !== null && self::isSalesOf($pdo, $oldSales, $newManagerId)) {
                $newSales = $oldSales;
            }
            Tx::exec($pdo, 'UPDATE tbl_customers SET manager_id = ?, assigned_sales_id = ? WHERE id = ?', [$newManagerId, $newSales, $customerId]);
            AuditLog::record('customer.transfer_owner', $a, [
                'target_type' => 'customer', 'target_customer_id' => $customerId,
                'target_snapshot' => ['username' => $c['username']],
                'before' => ['manager_id' => $c['manager_id'] === null ? null : (int) $c['manager_id'], 'assigned_sales_id' => $oldSales],
                'after' => ['manager_id' => $newManagerId, 'assigned_sales_id' => $newSales],
            ], $pdo);
        });
    }

    /**
     * OD-6: explicit admin selection of legacy (manager_id IS NULL) customers to give an owner
     * (bulk allowed, all-or-nothing). Never derived from created_by. One audit event per customer.
     *
     * @param list<int> $customerIds
     */
    public static function assignLegacyOwner(ActorContext $a, array $customerIds, int $managerId, ?int $salesId = null): int
    {
        ResellerTreeService::authorize($a, Capability::CUSTOMER_ASSIGN_LEGACY_OWNER);
        $ids = array_values(array_unique(array_map('intval', $customerIds)));
        if ($ids === [] || count($ids) > 500 || min($ids) <= 0) {
            throw new DomainError('INVALID_INPUT');
        }
        return Tx::withTreeLock(function (\PDO $pdo) use ($a, $ids, $managerId, $salesId): int {
            ResellerTreeService::revalidateActor($pdo, $a);
            self::assertOwner($pdo, $managerId);
            if ($salesId !== null) {
                self::assertSalesOf($pdo, $salesId, $managerId);
            }
            sort($ids);
            $rows = Tx::rows($pdo, 'SELECT id, username, manager_id FROM tbl_customers WHERE id IN (' . ResellerTreeService::ph($ids) . ') ORDER BY id FOR UPDATE', $ids);
            if (count($rows) !== count($ids)) {
                throw new DomainError('NOT_FOUND');
            }
            foreach ($rows as $r) {
                if ($r['manager_id'] !== null) {
                    throw new DomainError('NOT_UNASSIGNED');
                }
            }
            foreach ($rows as $r) {
                Tx::exec($pdo, 'UPDATE tbl_customers SET manager_id = ?, assigned_sales_id = ? WHERE id = ?', [$managerId, $salesId, (int) $r['id']]);
                AuditLog::record('customer.assign_legacy_owner', $a, [
                    'target_type' => 'customer', 'target_customer_id' => (int) $r['id'],
                    'target_snapshot' => ['username' => $r['username']],
                    'before' => ['manager_id' => null, 'assigned_sales_id' => null],
                    'after' => ['manager_id' => $managerId, 'assigned_sales_id' => $salesId],
                ], $pdo);
            }
            return count($rows);
        });
    }

    /**
     * Reference pre-check BEFORE any side effect of a customer delete: refused when reseller-domain
     * records (activations, events, ledger) reference the customer. Returns the customer row.
     *
     * @return array<string,mixed>
     */
    public static function assertDeletable(ActorContext $a, int $customerId): array
    {
        ResellerTreeService::authorize($a, Capability::CUSTOMER_DELETE);
        $pdo = \ORM::get_db();
        $c = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [$customerId]) ?? throw new DomainError('NOT_FOUND');
        foreach (Tx::rows($pdo, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tbl_customers' AND REFERENCED_COLUMN_NAME = 'id'") as $r) {
            $t = (string) $r['TABLE_NAME'];
            $col = (string) $r['COLUMN_NAME'];
            if (!preg_match(self::PROJECT_TABLE, $t)) {
                continue; // legacy dependents (custom fields, recharges) are removed by the legacy delete flow
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $t . $col) || Tx::row($pdo, "SELECT 1 AS x FROM `{$t}` WHERE `{$col}` = ? LIMIT 1", [$customerId]) !== null) {
                throw new DomainError('REFERENCED');
            }
        }
        return $c;
    }

    /** Legacy DB part of a customer delete (after assertDeletable and device removal), audited. */
    public static function deleteRecords(ActorContext $a, int $customerId): void
    {
        $c = self::assertDeletable($a, $customerId);
        Tx::run(function (\PDO $pdo) use ($a, $c, $customerId): void {
            ResellerTreeService::revalidateActor($pdo, $a);
            Tx::exec($pdo, 'DELETE FROM tbl_customers_fields WHERE customer_id = ?', [$customerId]);
            Tx::exec($pdo, 'DELETE FROM tbl_user_recharges WHERE customer_id = ?', [$customerId]);
            Tx::exec($pdo, 'DELETE FROM tbl_customers WHERE id = ?', [$customerId]);
            AuditLog::record('customer.delete', $a, [
                'target_type' => 'customer', 'target_customer_id' => $customerId,
                'target_snapshot' => ['id' => $customerId, 'username' => $c['username'], 'manager_id' => $c['manager_id'], 'assigned_sales_id' => $c['assigned_sales_id']],
                'before' => ['username' => $c['username'], 'status' => $c['status']],
            ], $pdo);
        });
    }

    /** @return list<array{id:int,username:string,fullname:string}> Sales accounts usable for a customer owned by $managerId */
    public static function salesOptions(int $managerId): array
    {
        $st = \ORM::get_db()->prepare(
            "SELECT u.id, u.username, u.fullname FROM tbl_manager_tree t JOIN tbl_users u ON u.id = t.descendant_id "
            . "WHERE t.ancestor_id = ? AND t.depth = 1 AND u.user_type = 'Sales' AND u.status = 'Active' ORDER BY u.username"
        );
        $st->execute([$managerId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array{id:int,username:string,fullname:string}> enrolled Active Agents (owner choices for SA/Admin) */
    public static function ownerOptions(): array
    {
        $st = \ORM::get_db()->query(
            "SELECT u.id, u.username, u.fullname FROM tbl_users u JOIN tbl_manager_tree t ON t.ancestor_id = u.id AND t.descendant_id = u.id AND t.depth = 0 "
            . "WHERE u.user_type = 'Agent' AND u.status = 'Active' ORDER BY u.username"
        );
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function lockScopedCustomer(\PDO $pdo, ActorContext $a, string $cap, int $customerId): array
    {
        $p = DataScope::customerPredicate($a, $cap, 'tbl_customers');
        $sql = 'SELECT * FROM tbl_customers WHERE tbl_customers.id = ?' . ($p !== null ? ' AND ' . $p[0] : '') . ' FOR UPDATE';
        $row = Tx::row($pdo, $sql, array_merge([$customerId], $p[1] ?? []));
        if ($row === null) {
            throw new DomainError('NOT_FOUND');
        }
        return $row;
    }

    private static function assertOwner(\PDO $pdo, int $managerId): void
    {
        try {
            ResellerTreeService::assertValidParent($pdo, $managerId);
        } catch (DomainError) {
            throw new DomainError('INVALID_OWNER');
        }
    }

    private static function isSalesOf(\PDO $pdo, int $salesId, int $managerId): bool
    {
        $r = Tx::row(
            $pdo,
            "SELECT u.id FROM tbl_users u JOIN tbl_manager_tree t ON t.descendant_id = u.id AND t.ancestor_id = ? AND t.depth = 1 "
            . "WHERE u.id = ? AND u.user_type = 'Sales' AND u.status = 'Active' FOR UPDATE",
            [$managerId, $salesId]
        );
        return $r !== null;
    }

    private static function assertSalesOf(\PDO $pdo, int $salesId, int $managerId): void
    {
        if (!self::isSalesOf($pdo, $salesId, $managerId)) {
            throw new DomainError('INVALID_SALES');
        }
    }

    private static function optionalId(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === '0' || $v === 0) {
            return null;
        }
        if (!DataScope::validId($v)) {
            throw new DomainError('INVALID_INPUT');
        }
        return (int) $v;
    }

    /** @param array<string,mixed> $in */
    private static function validateCustomer(array $in): void
    {
        $u = (string) ($in['username'] ?? '');
        if (mb_strlen($u) < 3 || mb_strlen($u) > 45) {
            throw new DomainError('INVALID_INPUT', 'Username should be between 3 to 45 characters');
        }
        $f = (string) ($in['fullname'] ?? '');
        if (mb_strlen($f) < 1 || mb_strlen($f) > 45) {
            throw new DomainError('INVALID_INPUT', 'Full Name should be between 1 to 45 characters');
        }
        $p = (string) ($in['password'] ?? '');
        if (mb_strlen($p) < 2 || mb_strlen($p) > 45) {
            throw new DomainError('INVALID_INPUT', 'Password should be between 3 to 35 characters');
        }
        // WP9 U0 (I-5): a PPPoE username is a RADIUS key: it fits the column and has no control character (refused, never truncated)
        $pp = (string) ($in['pppoe_username'] ?? '');
        if (mb_strlen($pp) > 32 || preg_match('/[\x00-\x1F\x7F]/', $pp)) {
            throw new DomainError('INVALID_INPUT', 'PPPoE username should be at most 32 characters, without control characters');
        }
    }

    private static function accountType(mixed $v): string
    {
        return in_array($v, ['Business', 'Personal'], true) ? $v : 'Personal';
    }

    private static function serviceType(\PDO $pdo, mixed $v): string
    {
        $st = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_customers' AND COLUMN_NAME = 'service_type'");
        $type = (string) $st->fetchColumn();
        preg_match_all("/'([^']*)'/", $type, $m);
        $allowed = $m[1] ?? [];
        return is_string($v) && in_array($v, $allowed, true) ? $v : (in_array('Others', $allowed, true) ? 'Others' : (string) ($allowed[0] ?? 'Others'));
    }
}
