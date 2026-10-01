<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Object/list scoping (WP2 §6). Emits parenthesized, parameter-bound predicates that are added to
 * the query with where_raw() BEFORE count()/sum()/pagination, so rows are never filtered in PHP and
 * counts never leak. Subqueries (not joins) keep the legacy unqualified column references intact.
 *
 *   G         no restriction
 *   T         customer.manager_id within the actor's closure subtree (self row included)
 *   A         customer explicitly assigned to the Sales actor AND owned by the Sales actor's manager (C21)
 *   otherwise (1 = 0)
 *
 * Unassigned (manager_id IS NULL) customers are visible to G scope only. Derived objects (recharges,
 * transactions, payment-gateway rows) are scoped by customer id only — never by username.
 */
final class DataScope
{
    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /** @return array{0:string,1:list<mixed>}|null null = unrestricted */
    public static function customerPredicate(ActorContext $a, string $capability, string $table = 'tbl_customers'): ?array
    {
        self::ident($table);
        $scope = PermissionService::scopeOf($a, $capability);
        return match ($scope) {
            'G' => null,
            'T' => ["({$table}.manager_id IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ?))", [(int) $a->userId]],
            'A' => ["({$table}.assigned_sales_id = ? AND {$table}.manager_id = ?)", [(int) $a->userId, (int) $a->managerId]],
            default => ['(1 = 0)', []],
        };
    }

    /** Predicate for a column holding a customer id (recharges, transactions.user_id, payment gateway). */
    public static function byCustomerColumnPredicate(ActorContext $a, string $capability, string $column): ?array
    {
        self::ident($column);
        $p = self::customerPredicate($a, $capability, 'sc');
        if ($p === null) {
            return null;
        }
        if ($p[0] === '(1 = 0)') {
            return $p;
        }
        return ["({$column} IN (SELECT sc.id FROM tbl_customers sc WHERE {$p[0]}))", $p[1]];
    }

    public static function voucherPredicate(ActorContext $a, string $capability, string $column = 'tbl_voucher.generated_by'): ?array
    {
        self::ident($column);
        return match (PermissionService::scopeOf($a, $capability)) {
            'G' => null,
            'T' => ["({$column} IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ?))", [(int) $a->userId]],
            'S' => ["({$column} = ?)", [(int) $a->userId]],
            default => ['(1 = 0)', []],
        };
    }

    public static function staffPredicate(ActorContext $a, string $capability, string $column = 'tbl_users.id'): ?array
    {
        self::ident($column);
        return match (PermissionService::scopeOf($a, $capability)) {
            'G' => null,
            'T' => ["({$column} IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ?))", [(int) $a->userId]],
            'D' => ["({$column} IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ? AND depth > 0))", [(int) $a->userId]],
            'C' => ["({$column} IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ? AND depth = 1))", [(int) $a->userId]],
            'S' => ["({$column} = ?)", [(int) $a->userId]],
            default => ['(1 = 0)', []],
        };
    }

    public static function scopeCustomers(\ORM $q, ActorContext $a, string $capability, string $table = 'tbl_customers'): \ORM
    {
        return self::apply($q, self::customerPredicate($a, $capability, $table));
    }

    public static function scopeByCustomerColumn(\ORM $q, ActorContext $a, string $capability, string $column): \ORM
    {
        return self::apply($q, self::byCustomerColumnPredicate($a, $capability, $column));
    }

    public static function scopeVouchers(\ORM $q, ActorContext $a, string $capability, string $column = 'tbl_voucher.generated_by'): \ORM
    {
        return self::apply($q, self::voucherPredicate($a, $capability, $column));
    }

    public static function scopeStaff(\ORM $q, ActorContext $a, string $capability, string $column = 'tbl_users.id'): \ORM
    {
        return self::apply($q, self::staffPredicate($a, $capability, $column));
    }

    /** Capability + scope in ONE query; null for out-of-scope and nonexistent alike (no oracle). */
    public static function findCustomer(ActorContext $a, string $capability, mixed $id): ?\ORM
    {
        if (!self::validId($id)) {
            return null;
        }
        $row = self::scopeCustomers(\ORM::for_table('tbl_customers'), $a, $capability)->where('tbl_customers.id', (int) $id)->find_one();
        return $row ?: null;
    }

    public static function findCustomerByUsername(ActorContext $a, string $capability, mixed $username): ?\ORM
    {
        if (!is_string($username) || $username === '') {
            return null;
        }
        $row = self::scopeCustomers(\ORM::for_table('tbl_customers'), $a, $capability)->where('tbl_customers.username', $username)->find_one();
        return $row ?: null;
    }

    public static function customerInScope(ActorContext $a, string $capability, mixed $id): bool
    {
        return self::findCustomer($a, $capability, $id) !== null;
    }

    /** All ids must be in scope (all-or-nothing). */
    public static function allCustomersInScope(ActorContext $a, string $capability, array $ids): bool
    {
        if ($ids === []) {
            return false;
        }
        $clean = [];
        foreach ($ids as $id) {
            if (!self::validId($id)) {
                return false;
            }
            $clean[(int) $id] = true;
        }
        $n = self::scopeCustomers(\ORM::for_table('tbl_customers'), $a, $capability)->where_in('tbl_customers.id', array_keys($clean))->count();
        return $n === count($clean);
    }

    public static function findStaff(ActorContext $a, string $capability, mixed $id): ?\ORM
    {
        if (!self::validId($id)) {
            return null;
        }
        $row = self::scopeStaff(\ORM::for_table('tbl_users'), $a, $capability)->where('tbl_users.id', (int) $id)->find_one();
        return $row ?: null;
    }

    public static function validId(mixed $id): bool
    {
        return (is_int($id) && $id > 0) || (is_string($id) && preg_match('/^[1-9][0-9]{0,18}$/', $id) === 1);
    }

    private static function apply(\ORM $q, ?array $predicate): \ORM
    {
        if ($predicate !== null) {
            $q->where_raw($predicate[0], $predicate[1]);
        }
        return $q;
    }

    private static function ident(string $column): void
    {
        if (!preg_match(self::IDENT, $column)) {
            throw new \InvalidArgumentException('invalid scope column');
        }
    }
}
