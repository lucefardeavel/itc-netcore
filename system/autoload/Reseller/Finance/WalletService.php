<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\DataScope;

/**
 * Reseller wallets (WP3A). Reads are scoped by the accepted WP2 PermissionService / DataScope
 * (wallet.read: G for Admin/SuperAdmin, T = own + subtree for Agents; Sales/Report: none).
 *
 * The debt limit is operational credit, not value: changing it never writes a ledger entry; it is an
 * audited configuration change (finance.debt_limit). Balances are written only by LedgerService.
 */
final class WalletService
{
    public const PAGE_SIZE = 50;

    /** One wallet in the actor's scope; out-of-scope and nonexistent ids are both NOT_FOUND. */
    public static function view(ActorContext $a, mixed $managerId): WalletView
    {
        ResellerTreeService::authorize($a, C::WALLET_READ);
        $id = FinanceInput::id($managerId);
        [$pred, $params] = self::scope($a, C::WALLET_READ, 'u.id');
        $row = Tx::row(FinanceTx::pdo(), self::SELECT . " WHERE u.id = ? AND u.user_type = 'Agent' AND {$pred}", array_merge([$id], $params));
        if ($row === null) {
            throw new DomainError('NOT_FOUND');
        }
        return self::viewOf($row);
    }

    /** @return list<array<string,mixed>> wallets of the reseller Agents in scope (absent wallets synthesized) */
    public static function listScoped(ActorContext $a, int $page = 1): array
    {
        ResellerTreeService::authorize($a, C::WALLET_READ);
        [$pred, $params] = self::scope($a, C::WALLET_READ, 'u.id');
        $offset = (max(1, $page) - 1) * self::PAGE_SIZE;
        $rows = Tx::rows(
            FinanceTx::pdo(),
            self::SELECT . " WHERE u.user_type = 'Agent' AND {$pred} ORDER BY u.id LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (int) $offset,
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['username' => (string) $r['username'], 'status' => (string) $r['status']] + self::viewOf($r)->toArray();
        }
        return $out;
    }

    /**
     * Sets the (absolute) debt limit of a valid reseller wallet. An absent wallet is created only for a
     * positive limit; setDebtLimit(0) on an absent wallet is a no-op (no wallet, no audit). A decrease
     * is refused when balance + new limit - ACTIVE reservations would be negative.
     */
    public static function setDebtLimit(ActorContext $a, mixed $managerId, mixed $newLimit, mixed $reason): WalletView
    {
        return self::writeDebtLimit($a, $managerId, $newLimit, false, null, $reason);
    }

    /**
     * WP6B fix round 2 (owner-authorized, additive): the reviewed, compare-and-set debt-limit write. Exactly
     * setDebtLimit() (same capability, scope, tree lock, transaction, rules, audit; no ledger entry), plus one
     * condition evaluated under the locks: once the wallet row is locked FOR UPDATE, its current debt limit (0.00 for
     * an absent wallet) must equal $expectedCurrent - the value the caller's review was classified against. Otherwise
     * it refuses with DEBT_LIMIT_CHANGED before any wallet creation, validation, update or audit (nothing written).
     */
    public static function setDebtLimitIfCurrent(ActorContext $a, mixed $managerId, mixed $newLimit, mixed $expectedCurrent, mixed $reason): WalletView
    {
        return self::writeDebtLimit($a, $managerId, $newLimit, true, $expectedCurrent, $reason);
    }

    /**
     * The single locked debt-limit writer. $conditional = false is the unconditional setDebtLimit() behaviour; when
     * true, $expectedCurrent is mandatory and validated like any amount (a missing / malformed value is refused).
     */
    private static function writeDebtLimit(ActorContext $a, mixed $managerId, mixed $newLimit, bool $conditional, mixed $expectedCurrent, mixed $reason): WalletView
    {
        ResellerTreeService::authorize($a, C::WALLET_DEBT_LIMIT);
        $id = FinanceInput::id($managerId);
        $limit = Money::parseNonNegative($newLimit);
        $limit->toStorage();
        $expected = $conditional ? Money::parseNonNegative($expectedCurrent) : null;
        $reason = FinanceInput::reason($reason);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $id, $limit, $expected, $reason): WalletView {
            ResellerTreeService::revalidateActor($pdo, $a);
            $owner = WalletOwner::assertValid($pdo, $id);
            $w = LockedWallet::lock($pdo, $id);
            // reviewed write: compare the LOCKED current limit with the reviewed one before anything else happens
            if ($expected !== null && !($w === null ? Money::zero() : $w->debtLimit)->equals($expected)) {
                throw new DomainError('DEBT_LIMIT_CHANGED');
            }
            if ($w === null) {
                if ($limit->isZero()) {
                    return new WalletView($id, false, Money::zero(), Money::zero(), Money::zero(), null);
                }
                $w = LockedWallet::lock($pdo, $id, true);
            }
            $reserved = self::activeReservedLocked($pdo, $w);
            if ($w->debtLimit->equals($limit)) {
                return self::lockedView($pdo, $w, $reserved);
            }
            $availableBefore = self::available($w, $reserved);
            $after = $w->withDebtLimit($limit);
            $availableAfter = self::available($after, $reserved);
            if ($limit->compare($w->debtLimit) < 0 && $availableAfter->isNegative()) {
                throw new DomainError('DEBT_LIMIT_EXPOSURE');
            }
            $n = Tx::exec(
                $pdo,
                'UPDATE tbl_manager_wallets SET debt_limit = ?, updated_at = ? WHERE manager_id = ? AND debt_limit = CAST(? AS DECIMAL(18,2))',
                [$limit->toStorage(), Tx::utcNow(), $id, $w->debtLimit->toStorage()]
            );
            if ($n !== 1) {
                throw new DomainError('LEDGER_STATE_MISMATCH');
            }
            FinanceFaults::hit('debt_limit.after_update');
            AuditLog::record('finance.debt_limit', $a, [
                'target_type' => 'wallet', 'target_user_id' => $id, 'target_snapshot' => WalletOwner::snapshot($owner),
                'before' => ['debt_limit' => $w->debtLimit->format(), 'available' => $availableBefore->format(), 'wallet_exists' => !$w->created],
                'after' => [
                    'debt_limit' => $limit->format(), 'available' => $availableAfter->format(), 'balance' => $w->balance->format(),
                    'reserved' => $reserved->format(), 'wallet_created' => $w->created,
                ],
                'reason' => $reason,
            ], $pdo);
            FinanceFaults::hit('debt_limit.after_audit');
            return self::lockedView($pdo, $after, $reserved);
        }, true);
    }

    /**
     * Locking SUM of the wallet's ACTIVE reservations (sees the latest committed rows whatever the
     * transaction snapshot). May exceed one DECIMAL(18,2) column; parsed into the calculation range.
     */
    public static function activeReservedLocked(\PDO $pdo, LockedWallet $w): Money
    {
        FinanceTx::assertInTransaction($pdo);
        $r = Tx::row($pdo, "SELECT SUM(amount) AS s FROM tbl_manager_wallet_reservations WHERE manager_id = ? AND status = 'ACTIVE' FOR UPDATE", [$w->managerId]);
        return Money::fromDbAggregate($r['s'] ?? null);
    }

    /** available = balance + debt_limit - SUM(ACTIVE reservations), from locked state only. */
    public static function available(LockedWallet $w, Money $reserved): Money
    {
        return $w->balance->plus($w->debtLimit)->minus($reserved);
    }

    private const SELECT = 'SELECT u.id, u.username, u.status, w.manager_id AS wallet_id, w.balance, w.debt_limit, w.updated_at, '
        . "(SELECT SUM(r.amount) FROM tbl_manager_wallet_reservations r WHERE r.manager_id = u.id AND r.status = 'ACTIVE') AS reserved "
        . 'FROM tbl_users u LEFT JOIN tbl_manager_wallets w ON w.manager_id = u.id';

    /** @param array<string,mixed> $row */
    private static function viewOf(array $row): WalletView
    {
        if ($row['wallet_id'] === null) {
            return new WalletView((int) $row['id'], false, Money::zero(), Money::zero(), Money::zero(), null);
        }
        return new WalletView(
            (int) $row['id'],
            true,
            Money::fromDb($row['balance']),
            Money::fromDb($row['debt_limit']),
            Money::fromDbAggregate($row['reserved']),
            (string) $row['updated_at'],
        );
    }

    private static function lockedView(\PDO $pdo, LockedWallet $w, Money $reserved): WalletView
    {
        $r = Tx::row($pdo, 'SELECT updated_at FROM tbl_manager_wallets WHERE manager_id = ?', [$w->managerId]);
        return new WalletView($w->managerId, true, $w->balance, $w->debtLimit, $reserved, $r !== null ? (string) $r['updated_at'] : null);
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function scope(ActorContext $a, string $capability, string $column): array
    {
        return DataScope::staffPredicate($a, $capability, $column) ?? ['(1 = 1)', []];
    }
}
