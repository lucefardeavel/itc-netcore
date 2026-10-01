<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\Tx;

/**
 * A wallet row read with SELECT ... FOR UPDATE inside the current transaction. Instances can only be
 * obtained through lock()/lockMany(), so every balance/debt-limit write starts from locked,
 * authoritative state (LedgerService and WalletService accept nothing else). Multi-wallet locks are
 * always taken in ascending manager_id order (deterministic lock order, no deadlock between finance
 * operations).
 *
 * This is the only place that creates a wallet row: lazily, inside the caller's transaction, and only
 * for ids the caller explicitly allows (provider funding, positive debt-limit establishment, valid
 * parent-child transfer destination).
 */
final class LockedWallet
{
    private function __construct(
        public readonly int $managerId,
        public readonly Money $balance,
        public readonly Money $debtLimit,
        public readonly bool $created,
    ) {
    }

    /** Locks one wallet; null when absent and creation is not allowed. */
    public static function lock(\PDO $pdo, int $managerId, bool $create = false): ?self
    {
        return self::lockMany($pdo, [$managerId], $create ? [$managerId] : [])[$managerId] ?? null;
    }

    /**
     * @param list<int> $ids
     * @param list<int> $createIds ids that may be created when absent
     * @return array<int,self> manager_id => locked wallet (absent, non-created wallets are omitted)
     */
    public static function lockMany(\PDO $pdo, array $ids, array $createIds = []): array
    {
        FinanceTx::assertInTransaction($pdo);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        sort($ids);
        $create = array_map('intval', $createIds);
        $out = [];
        foreach ($ids as $id) {
            $row = self::select($pdo, $id);
            $created = false;
            if ($row === null && in_array($id, $create, true)) {
                $u = Tx::row($pdo, 'SELECT user_type FROM tbl_users WHERE id = ?', [$id]);
                if ($u === null || $u['user_type'] !== 'Agent') {
                    throw new DomainError('WALLET_OWNER_INVALID');
                }
                $now = Tx::utcNow();
                Tx::exec(
                    $pdo,
                    'INSERT INTO tbl_manager_wallets (manager_id, balance, debt_limit, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, '0.00', '0.00', $now, $now]
                );
                $row = self::select($pdo, $id);
                $created = true;
            }
            if ($row !== null) {
                $out[$id] = new self($id, Money::fromDb($row['balance']), Money::fromDb($row['debt_limit']), $created);
            }
        }
        return $out;
    }

    /**
     * @internal State after a compare-and-set balance write by LedgerService. A forged value cannot be
     * persisted: the write is conditional on the stored balance being exactly $this->balance.
     */
    public function withBalance(Money $balance): self
    {
        return new self($this->managerId, $balance, $this->debtLimit, $this->created);
    }

    /** @internal State after a compare-and-set debt-limit write by WalletService. */
    public function withDebtLimit(Money $debtLimit): self
    {
        return new self($this->managerId, $this->balance, $debtLimit, $this->created);
    }

    /** @return array<string,mixed>|null */
    private static function select(\PDO $pdo, int $id): ?array
    {
        return Tx::row($pdo, 'SELECT manager_id, balance, debt_limit FROM tbl_manager_wallets WHERE manager_id = ? FOR UPDATE', [$id]);
    }
}
