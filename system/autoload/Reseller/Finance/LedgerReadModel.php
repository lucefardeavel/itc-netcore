<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\DataScope;
use Reseller\Security\PermissionService;

/**
 * WP6B (owner decisions OD-1, OD-4, OD-6): read-only finance projections for the staff web UI. SELECT only; it holds
 * no authority and writes nothing. Scope is applied inside every query with the accepted WP2 mechanism
 * (PermissionService::scopeOf + DataScope::staffPredicate), exactly as WalletService / LedgerService do:
 * wallet.read / ledger.read are G for Admin / SuperAdmin and T (own + subtree) for an Agent; Sales / Report have none.
 * Out-of-scope and nonexistent ids are both NOT_FOUND; nothing is queried globally and filtered afterwards.
 *
 * Identities: a party (counterparty, operator, from / to manager, parent) is named only when it is inside the
 * viewer's scope; otherwise only a role label is returned ("provider" for Admin / SuperAdmin operators and provider
 * operations, "upstream" for a reseller above the viewer) — no id, no name. In-scope ledger reasons are returned
 * unchanged (OD-4: the accepted ledger.read contract).
 *
 * Exports reuse the same scoped query (keyset chunks, newest first) with the WP6B safety caps: a date range of at most
 * 366 days and at most 20,000 rows (refused above, never truncated).
 */
final class LedgerReadModel
{
    public const PAGE_SIZE = 50;
    public const EXPORT_MAX_ROWS = ExportCap::MAX_ROWS;
    public const EXPORT_MAX_DAYS = ExportCap::MAX_DAYS;
    private const CHUNK = 1000;

    public const ENTRY_TYPES = ['FUNDING', 'WITHDRAWAL', 'ACTIVATION_DEBIT', 'COMMISSION_CREDIT', 'TRANSFER_OUT', 'TRANSFER_IN', 'REFUND', 'REVERSAL', 'ADJUSTMENT'];
    public const DIRECTIONS = ['CREDIT', 'DEBIT'];
    public const TRANSFER_TYPES = ['PROVIDER_FUNDING', 'PROVIDER_WITHDRAWAL', 'ADJUSTMENT_CREDIT', 'ADJUSTMENT_DEBIT', 'PARENT_CHILD_TRANSFER'];
    public const TRANSFER_STATUSES = ['COMPLETED', 'REVERSED'];
    public const WALLET_FILTERS = ['with', 'none', 'negative', 'exhausted'];

    private const WALLET_SELECT = 'SELECT u.id, u.username, u.fullname, u.status, w.manager_id AS wallet_id, w.balance, w.debt_limit, w.updated_at, '
        . "(SELECT SUM(r.amount) FROM tbl_manager_wallet_reservations r WHERE r.manager_id = u.id AND r.status = 'ACTIVE') AS reserved, "
        . '(SELECT p.ancestor_id FROM tbl_manager_tree p WHERE p.descendant_id = u.id AND p.depth = 1) AS parent_id, '
        . 'EXISTS (SELECT 1 FROM tbl_manager_tree s WHERE s.descendant_id = u.id AND s.depth = 0) AS enrolled, '
        . "EXISTS (SELECT 1 FROM tbl_manager_tree f JOIN tbl_users fu ON fu.id = f.ancestor_id WHERE f.descendant_id = u.id AND fu.status <> 'Active') AS frozen "
        . 'FROM tbl_users u LEFT JOIN tbl_manager_wallets w ON w.manager_id = u.id';

    // ------------------------------------------------------------------ wallets

    /**
     * Reseller wallets in the actor's wallet.read scope (absent wallets are synthesized: exists = false).
     *
     * @param array<string,mixed> $f q (username / name), status (Active | Inactive), wallet (with | none | negative | exhausted), parent (direct children of)
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,counts:array<string,int>,reserved_total:string}
     */
    public static function wallets(ActorContext $a, array $f, int $page = 1): array
    {
        ResellerTreeService::authorize($a, C::WALLET_READ);
        [$where, $params] = self::walletWhere($a, $f);
        $pdo = FinanceTx::pdo();
        $total = (int) (Tx::row($pdo, 'SELECT COUNT(*) AS n FROM tbl_users u LEFT JOIN tbl_manager_wallets w ON w.manager_id = u.id WHERE ' . $where, $params)['n'] ?? 0);
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = Tx::rows($pdo, self::WALLET_SELECT . ' WHERE ' . $where . ' ORDER BY u.username LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $params);
        [$scopeWhere, $scopeParams] = self::walletWhere($a, []);
        $c = Tx::row($pdo, 'SELECT COUNT(*) AS wallets, '
            . 'SUM(CASE WHEN w.manager_id IS NOT NULL AND w.balance < 0 THEN 1 ELSE 0 END) AS negative, '
            . 'SUM(CASE WHEN w.manager_id IS NULL THEN 1 ELSE 0 END) AS none '
            . 'FROM tbl_users u LEFT JOIN tbl_manager_wallets w ON w.manager_id = u.id WHERE ' . $scopeWhere, $scopeParams) ?? [];
        $reserved = Tx::row($pdo, "SELECT SUM(r.amount) AS s FROM tbl_manager_wallet_reservations r JOIN tbl_users u ON u.id = r.manager_id WHERE r.status = 'ACTIVE' AND " . $scopeWhere, $scopeParams);
        return [
            'rows' => self::walletRows($a, $rows),
            'total' => $total, 'page' => $page, 'pages' => $pages,
            'counts' => ['wallets' => (int) ($c['wallets'] ?? 0), 'negative' => (int) ($c['negative'] ?? 0), 'none' => (int) ($c['none'] ?? 0)],
            'reserved_total' => Money::fromDbAggregate($reserved['s'] ?? null)->format(),
        ];
    }

    /**
     * One wallet with its owner's identity and state (wallet.read scope; NOT_FOUND otherwise).
     *
     * @return array<string,mixed>
     */
    public static function wallet(ActorContext $a, mixed $managerId): array
    {
        ResellerTreeService::authorize($a, C::WALLET_READ);
        $id = FinanceInput::id($managerId);
        [$where, $params] = self::walletWhere($a, []);
        $row = Tx::row(FinanceTx::pdo(), self::WALLET_SELECT . ' WHERE u.id = ? AND ' . $where, array_merge([$id], $params));
        if ($row === null) {
            throw new DomainError('NOT_FOUND');
        }
        return self::walletRows($a, [$row])[0];
    }

    /** CSV export of the wallets in scope (same filters / scope as the screen). */
    public static function exportWallets(ActorContext $a, array $f, callable $sink): int
    {
        ResellerTreeService::authorize($a, C::WALLET_READ);
        [$where, $params] = self::walletWhere($a, $f);
        $pdo = FinanceTx::pdo();
        return ExportCap::snapshot($pdo,
            static fn(): int => (int) (Tx::row($pdo, 'SELECT COUNT(*) AS n FROM tbl_users u LEFT JOIN tbl_manager_wallets w ON w.manager_id = u.id WHERE ' . $where, $params)['n'] ?? 0),
            static function () use ($pdo, $a, $where, $params, $sink): int {
                $n = 0;
                $last = 0;
                do {
                    $rows = Tx::rows($pdo, self::WALLET_SELECT . ' WHERE ' . $where . ' AND u.id > ? ORDER BY u.id LIMIT ' . self::CHUNK, array_merge($params, [$last]));
                    foreach (self::walletRows($a, $rows) as $r) {
                        $sink($r);
                        $n++;
                    }
                    $last = $rows !== [] ? (int) end($rows)['id'] : $last;
                } while (count($rows) === self::CHUNK);
                return $n;
            });
    }

    // ------------------------------------------------------------------ ledger

    /**
     * Ledger of one wallet in the actor's ledger.read scope, newest first.
     *
     * @param array<string,mixed> $f from / to (Y-m-d, UTC), type (entry type), direction (CREDIT | DEBIT)
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,owner:array<string,mixed>}
     */
    public static function ledger(ActorContext $a, mixed $managerId, array $f, int $page = 1): array
    {
        [$id, $owner] = self::ledgerOwner($a, $managerId);
        [$where, $params] = self::ledgerWhere($id, $f);
        $pdo = FinanceTx::pdo();
        $total = (int) (Tx::row($pdo, 'SELECT COUNT(*) AS n FROM tbl_manager_ledger l WHERE ' . $where, $params)['n'] ?? 0);
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = Tx::rows($pdo, 'SELECT l.* FROM tbl_manager_ledger l WHERE ' . $where . ' ORDER BY l.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $params);
        return ['rows' => self::ledgerRows($a, $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'owner' => $owner];
    }

    /** The latest $limit entries of one wallet (wallet page). @return list<array<string,mixed>> */
    public static function recent(ActorContext $a, mixed $managerId, int $limit = 10): array
    {
        [$id] = self::ledgerOwner($a, $managerId);
        $rows = Tx::rows(FinanceTx::pdo(), 'SELECT l.* FROM tbl_manager_ledger l WHERE l.manager_id = ? ORDER BY l.id DESC LIMIT ' . max(1, min(50, $limit)), [$id]);
        return self::ledgerRows($a, $rows);
    }

    /** CSV export of one wallet's ledger (same scope / filters; date range required). */
    public static function exportLedger(ActorContext $a, mixed $managerId, array $f, callable $sink): int
    {
        [$id] = self::ledgerOwner($a, $managerId);
        self::exportRange($f);
        [$where, $params] = self::ledgerWhere($id, $f);
        $pdo = FinanceTx::pdo();
        return ExportCap::snapshot($pdo,
            static fn(): int => (int) (Tx::row($pdo, 'SELECT COUNT(*) AS n FROM tbl_manager_ledger l WHERE ' . $where, $params)['n'] ?? 0),
            static function () use ($pdo, $a, $where, $params, $sink): int {
                $n = 0;
                $last = PHP_INT_MAX;
                do {
                    $rows = Tx::rows($pdo, 'SELECT l.* FROM tbl_manager_ledger l WHERE ' . $where . ' AND l.id < ? ORDER BY l.id DESC LIMIT ' . self::CHUNK, array_merge($params, [$last]));
                    foreach (self::ledgerRows($a, $rows) as $r) {
                        $sink($r);
                        $n++;
                    }
                    $last = $rows !== [] ? (int) end($rows)['id'] : $last;
                } while (count($rows) === self::CHUNK);
                return $n;
            });
    }

    /**
     * One ledger entry (ledger.read scope) with its links: the transfer (reference, type, status, reversal), the
     * activation id (resolved to a reference by the activation layer) and the entry it reverses / that reverses it.
     *
     * @return array<string,mixed>
     */
    public static function entry(ActorContext $a, mixed $entryId): array
    {
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        $id = FinanceInput::id($entryId);
        [$pred, $params] = DataScope::staffPredicate($a, C::LEDGER_READ, 'l.manager_id') ?? ['(1 = 1)', []];
        $pdo = FinanceTx::pdo();
        $row = Tx::row($pdo, "SELECT l.* FROM tbl_manager_ledger l WHERE l.id = ? AND {$pred}", array_merge([$id], $params));
        if ($row === null) {
            throw new DomainError('NOT_FOUND');
        }
        $out = self::ledgerRows($a, [$row])[0];
        $out['transfer'] = $row['transfer_id'] !== null ? self::transferSummary($a, (int) $row['transfer_id']) : null;
        $out['reversed_by_entry_id'] = null;
        $rev = Tx::row($pdo, "SELECT l.id FROM tbl_manager_ledger l WHERE l.reverses_entry_id = ? AND {$pred}", array_merge([$id], $params));
        if ($rev !== null) {
            $out['reversed_by_entry_id'] = (int) $rev['id'];
        }
        $out['owner'] = self::ledgerOwner($a, (int) $row['manager_id'])[1];
        return $out;
    }

    // ------------------------------------------------------------------ transfers

    /**
     * Transfers whose source or destination wallet is in the actor's ledger.read scope (G: all), newest first.
     *
     * @param array<string,mixed> $f type, status, from / to (Y-m-d UTC), manager (id in scope), reference (exact)
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function transfers(ActorContext $a, array $f, int $page = 1): array
    {
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        [$where, $params] = self::transferWhere($a, $f);
        $pdo = FinanceTx::pdo();
        $total = (int) (Tx::row($pdo, 'SELECT COUNT(*) AS n FROM tbl_manager_transfers t WHERE ' . $where, $params)['n'] ?? 0);
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = Tx::rows($pdo, 'SELECT t.* FROM tbl_manager_transfers t WHERE ' . $where . ' ORDER BY t.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $params);
        return ['rows' => self::transferRows($a, $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** CSV export of the transfers in scope (same filters; date range required). */
    public static function exportTransfers(ActorContext $a, array $f, callable $sink): int
    {
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        self::exportRange($f);
        [$where, $params] = self::transferWhere($a, $f);
        $pdo = FinanceTx::pdo();
        return ExportCap::snapshot($pdo,
            static fn(): int => (int) (Tx::row($pdo, 'SELECT COUNT(*) AS n FROM tbl_manager_transfers t WHERE ' . $where, $params)['n'] ?? 0),
            static function () use ($pdo, $a, $where, $params, $sink): int {
                $n = 0;
                $last = PHP_INT_MAX;
                do {
                    $rows = Tx::rows($pdo, 'SELECT t.* FROM tbl_manager_transfers t WHERE ' . $where . ' AND t.id < ? ORDER BY t.id DESC LIMIT ' . self::CHUNK, array_merge($params, [$last]));
                    foreach (self::transferRows($a, $rows) as $r) {
                        $sink($r);
                        $n++;
                    }
                    $last = $rows !== [] ? (int) end($rows)['id'] : $last;
                } while (count($rows) === self::CHUNK);
                return $n;
            });
    }

    /**
     * One transfer in scope with its in-scope ledger legs and its reversal links.
     *
     * @return array<string,mixed>
     */
    public static function transfer(ActorContext $a, mixed $transferId): array
    {
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        $id = FinanceInput::id($transferId);
        [$where, $params] = self::transferWhere($a, []);
        $pdo = FinanceTx::pdo();
        $row = Tx::row($pdo, 'SELECT t.* FROM tbl_manager_transfers t WHERE t.id = ? AND ' . $where, array_merge([$id], $params));
        if ($row === null) {
            throw new DomainError('NOT_FOUND');
        }
        $out = self::transferRows($a, [$row])[0];
        [$pred, $pp] = DataScope::staffPredicate($a, C::LEDGER_READ, 'l.manager_id') ?? ['(1 = 1)', []];
        $out['legs'] = self::ledgerRows($a, Tx::rows($pdo, "SELECT l.* FROM tbl_manager_ledger l WHERE l.transfer_id = ? AND {$pred} ORDER BY l.id", array_merge([$id], $pp)));
        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0:string,1:list<mixed>} */
    private static function walletWhere(ActorContext $a, array $f): array
    {
        [$pred, $params] = DataScope::staffPredicate($a, C::WALLET_READ, 'u.id') ?? ['(1 = 1)', []];
        $where = "u.user_type = 'Agent' AND {$pred}";
        $q = isset($f['q']) && is_string($f['q']) ? trim($f['q']) : '';
        if ($q !== '' && mb_strlen($q) <= 64) {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where .= ' AND (u.username LIKE ? OR u.fullname LIKE ?)';
            array_push($params, $like, $like);
        }
        if (isset($f['parent']) && DataScope::validId($f['parent'])) {
            // direct children of one reseller (still inside the scope predicate above)
            $where .= ' AND u.id IN (SELECT c.descendant_id FROM tbl_manager_tree c WHERE c.ancestor_id = ? AND c.depth = 1)';
            $params[] = (int) $f['parent'];
        }
        if (in_array($f['status'] ?? null, ['Active', 'Inactive'], true)) {
            $where .= ' AND u.status = ?';
            $params[] = $f['status'];
        }
        $where .= match ($f['wallet'] ?? null) {
            'with' => ' AND w.manager_id IS NOT NULL',
            'none' => ' AND w.manager_id IS NULL',
            'negative' => ' AND w.manager_id IS NOT NULL AND w.balance < 0',
            'exhausted' => " AND w.manager_id IS NOT NULL AND (w.balance + w.debt_limit - COALESCE((SELECT SUM(x.amount) FROM tbl_manager_wallet_reservations x WHERE x.manager_id = u.id AND x.status = 'ACTIVE'), 0)) <= 0",
            default => '',
        };
        return [$where, $params];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function walletRows(ActorContext $a, array $rows): array
    {
        $names = self::names($a, C::WALLET_READ, array_map(static fn(array $r): ?int => $r['parent_id'] !== null ? (int) $r['parent_id'] : null, $rows));
        $out = [];
        foreach ($rows as $r) {
            $view = $r['wallet_id'] === null
                ? new WalletView((int) $r['id'], false, Money::zero(), Money::zero(), Money::zero(), null)
                : new WalletView((int) $r['id'], true, Money::fromDb($r['balance']), Money::fromDb($r['debt_limit']), Money::fromDbAggregate($r['reserved']), (string) $r['updated_at']);
            $out[] = [
                'id' => (int) $r['id'], 'username' => (string) $r['username'], 'fullname' => (string) $r['fullname'],
                'status' => (string) $r['status'], 'enrolled' => (bool) $r['enrolled'], 'frozen' => (bool) $r['frozen'] || (string) $r['status'] !== 'Active',
                'parent' => $r['parent_id'] !== null ? $names[(int) $r['parent_id']] : null,
                'available_negative' => $view->available()->isNegative(), 'available_zero' => $view->available()->isZero(),
                'balance_negative' => $view->balance->isNegative(),
            ] + $view->toArray();
        }
        return $out;
    }

    /** @return array{0:int,1:array<string,mixed>} the wallet owner id and its identity (ledger.read scope) */
    private static function ledgerOwner(ActorContext $a, mixed $managerId): array
    {
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        $id = FinanceInput::id($managerId);
        [$pred, $params] = DataScope::staffPredicate($a, C::LEDGER_READ, 'u.id') ?? ['(1 = 1)', []];
        $row = Tx::row(FinanceTx::pdo(), "SELECT u.id, u.username, u.fullname, u.status FROM tbl_users u WHERE u.id = ? AND u.user_type = 'Agent' AND {$pred}", array_merge([$id], $params));
        if ($row === null) {
            throw new DomainError('NOT_FOUND');
        }
        return [$id, ['id' => $id, 'username' => (string) $row['username'], 'fullname' => (string) $row['fullname'], 'status' => (string) $row['status']]];
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function ledgerWhere(int $managerId, array $f): array
    {
        $where = 'l.manager_id = ?';
        $params = [$managerId];
        [$from, $to] = self::bounds($f);
        if ($from !== null) {
            $where .= ' AND l.created_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where .= ' AND l.created_at < ?';
            $params[] = $to;
        }
        if (in_array($f['type'] ?? null, self::ENTRY_TYPES, true)) {
            $where .= ' AND l.entry_type = ?';
            $params[] = $f['type'];
        }
        if (in_array($f['direction'] ?? null, self::DIRECTIONS, true)) {
            $where .= ' AND l.direction = ?';
            $params[] = $f['direction'];
        }
        return [$where, $params];
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function transferWhere(ActorContext $a, array $f): array
    {
        $scope = DataScope::staffPredicate($a, C::LEDGER_READ, 't.from_manager_id');
        if ($scope === null) {
            $where = '(1 = 1)';
            $params = [];
        } else {
            [$to, $toParams] = DataScope::staffPredicate($a, C::LEDGER_READ, 't.to_manager_id');
            $where = "({$scope[0]} OR {$to})";
            $params = array_merge($scope[1], $toParams);
        }
        if (in_array($f['type'] ?? null, self::TRANSFER_TYPES, true)) {
            $where .= ' AND t.operation_type = ?';
            $params[] = $f['type'];
        }
        if (in_array($f['status'] ?? null, self::TRANSFER_STATUSES, true)) {
            $where .= ' AND t.status = ?';
            $params[] = $f['status'];
        }
        if (isset($f['manager']) && DataScope::validId($f['manager'])) {
            $where .= ' AND (t.from_manager_id = ? OR t.to_manager_id = ?)';
            array_push($params, (int) $f['manager'], (int) $f['manager']);
        }
        if (isset($f['reference']) && is_string($f['reference']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/D', $f['reference'])) {
            $where .= ' AND t.reference = ?';
            $params[] = $f['reference'];
        }
        [$from, $to] = self::bounds($f);
        if ($from !== null) {
            $where .= ' AND t.created_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where .= ' AND t.created_at < ?';
            $params[] = $to;
        }
        return [$where, $params];
    }

    /** @return array{0:?string,1:?string} strict Y-m-d bounds (invalid values are ignored); shared by the WP6B finance read models */
    public static function range(array $f): array
    {
        $d = static function (mixed $v): ?string {
            if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) {
                return null;
            }
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, new \DateTimeZone('UTC'));
            return $dt !== false && $dt->format('Y-m-d') === $v ? $v : null;
        };
        return [$d($f['from'] ?? null), $d($f['to'] ?? null)];
    }

    /**
     * V1.0.1 (owner decision 2026-11-09): the from / to days are days of the application time zone. Returns the bounds
     * of the stored UTC column ("Y-m-d H:i:s"): from = that day's local midnight (inclusive), to = the next local
     * midnight (exclusive); null for a side not set. Shared by the WP6B finance read models.
     *
     * @return array{0:?string,1:?string}
     */
    public static function bounds(array $f): array
    {
        [$from, $to] = self::range($f);
        $zone = new \DateTimeZone(date_default_timezone_get());
        $utc = static fn(string $day, string $shift): string => (new \DateTimeImmutable($day . ' 00:00:00', $zone))
            ->modify($shift)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return [$from === null ? null : $utc($from, '+0 day'), $to === null ? null : $utc($to, '+1 day')];
    }

    /** Exports of time series require a valid range of at most EXPORT_MAX_DAYS days (shared by the WP6B finance read models). */
    public static function exportRange(array $f): void
    {
        [$from, $to] = self::range($f);
        if ($from === null || $to === null || $from > $to) {
            throw new DomainError('EXPORT_RANGE_REQUIRED');
        }
        $days = (int) (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->days + 1;
        if ($days > self::EXPORT_MAX_DAYS) {
            throw new DomainError('EXPORT_RANGE_REQUIRED');
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function ledgerRows(ActorContext $a, array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = $r['counterparty_manager_id'] !== null ? (int) $r['counterparty_manager_id'] : null;
            $ids[] = $r['operator_id'] !== null ? (int) $r['operator_id'] : null;
        }
        $names = self::names($a, C::LEDGER_READ, $ids);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'manager_id' => (int) $r['manager_id'],
                'entry_type' => (string) $r['entry_type'], 'direction' => (string) $r['direction'],
                'amount' => Money::fromDb($r['amount'])->format(),
                'balance_before' => Money::fromDb($r['balance_before'])->format(),
                'balance_after' => Money::fromDb($r['balance_after'])->format(),
                'activation_id' => $r['activation_id'] !== null ? (int) $r['activation_id'] : null,
                'transfer_id' => $r['transfer_id'] !== null ? (int) $r['transfer_id'] : null,
                'reverses_entry_id' => $r['reverses_entry_id'] !== null ? (int) $r['reverses_entry_id'] : null,
                'counterparty' => $r['counterparty_manager_id'] !== null ? $names[(int) $r['counterparty_manager_id']] : null,
                'operator' => $r['operator_id'] !== null ? $names[(int) $r['operator_id']] : null,
                'actor_type' => (string) $r['actor_type'],
                'reason' => $r['reason'] !== null ? (string) $r['reason'] : null,
                'created_at' => substr((string) $r['created_at'], 0, 19),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function transferRows(ActorContext $a, array $rows): array
    {
        $pdo = FinanceTx::pdo();
        $ids = [];
        foreach ($rows as $r) {
            foreach (['from_manager_id', 'to_manager_id', 'operator_id'] as $k) {
                $ids[] = $r[$k] !== null ? (int) $r[$k] : null;
            }
        }
        $names = self::names($a, C::LEDGER_READ, $ids);
        $reversedBy = [];
        $rowIds = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        if ($rowIds !== []) {
            foreach (Tx::rows($pdo, 'SELECT id, reference, reverses_transfer_id FROM tbl_manager_transfers WHERE reverses_transfer_id IN (' . implode(',', array_fill(0, count($rowIds), '?')) . ')', $rowIds) as $x) {
                $reversedBy[(int) $x['reverses_transfer_id']] = ['id' => (int) $x['id'], 'reference' => (string) $x['reference']];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'reference' => (string) $r['reference'], 'operation_type' => (string) $r['operation_type'],
                'status' => (string) $r['status'], 'amount' => Money::fromDb($r['amount'])->format(),
                'from' => $r['from_manager_id'] !== null ? $names[(int) $r['from_manager_id']] : null,
                'to' => $r['to_manager_id'] !== null ? $names[(int) $r['to_manager_id']] : null,
                'operator' => $names[(int) $r['operator_id']] ?? null,
                'reason' => (string) $r['reason'],
                'external_reference' => $r['external_reference'] !== null ? (string) $r['external_reference'] : null,
                'reverses_transfer_id' => $r['reverses_transfer_id'] !== null ? (int) $r['reverses_transfer_id'] : null,
                'reversed_by' => $reversedBy[(int) $r['id']] ?? null,
                'created_at' => substr((string) $r['created_at'], 0, 19),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null the in-scope summary of a transfer (null when outside the actor's scope) */
    private static function transferSummary(ActorContext $a, int $transferId): ?array
    {
        [$where, $params] = self::transferWhere($a, []);
        $row = Tx::row(FinanceTx::pdo(), 'SELECT t.* FROM tbl_manager_transfers t WHERE t.id = ? AND ' . $where, array_merge([$transferId], $params));
        return $row !== null ? self::transferRows($a, [$row])[0] : null;
    }

    /**
     * Identity of each referenced staff id for this viewer: the username / name inside the capability's scope, a role
     * label otherwise ("provider" for Admin / SuperAdmin, "upstream" for a reseller above the viewer, "staff" else).
     *
     * @param list<?int> $ids
     * @return array<int,array{id:?int,username:?string,fullname:?string,label:?string}>
     */
    public static function names(ActorContext $a, string $capability, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn($v): bool => is_int($v) && $v > 0)));
        if ($ids === []) {
            return [];
        }
        $pdo = FinanceTx::pdo();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $users = [];
        foreach (Tx::rows($pdo, "SELECT id, username, fullname, user_type FROM tbl_users WHERE id IN ({$ph})", $ids) as $u) {
            $users[(int) $u['id']] = $u;
        }
        $visible = [];
        $scope = PermissionService::scopeOf($a, $capability);
        if ($scope === 'G') {
            $visible = array_fill_keys($ids, true);
        } else {
            [$pred, $params] = DataScope::staffPredicate($a, $capability, 'id') ?? ['(1 = 0)', []];
            foreach (Tx::rows($pdo, "SELECT id FROM tbl_users WHERE id IN ({$ph}) AND {$pred}", array_merge($ids, $params)) as $v) {
                $visible[(int) $v['id']] = true;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $u = $users[$id] ?? null;
            if ($u !== null && isset($visible[$id])) {
                $out[$id] = ['id' => $id, 'username' => (string) $u['username'], 'fullname' => (string) $u['fullname'], 'label' => null];
                continue;
            }
            $type = $u['user_type'] ?? '';
            $out[$id] = ['id' => null, 'username' => null, 'fullname' => null,
                'label' => in_array($type, ['SuperAdmin', 'Admin'], true) ? 'provider' : ($type === 'Agent' ? 'upstream' : 'staff')];
        }
        return $out;
    }
}
