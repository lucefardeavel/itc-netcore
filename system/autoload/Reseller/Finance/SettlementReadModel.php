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
 * WP6B (owner decisions OD-1 / OD-4 / OD-6): read-only commission / provider-settlement / sales projections. SELECT
 * only. Every figure comes from immutable history — the ledger legs of an activation (ACTIVATION_DEBIT,
 * COMMISSION_CREDIT, REVERSAL) and the activation's sealed snapshot columns — never from today's pricing.
 *
 * Visibility (ledger.read): Admin / SuperAdmin (G) everything, including the provider settlement, the conservation
 * check and the provider base cost / margin from the sealed snapshot; an Agent (T) only the ledger legs of wallets in
 * its own subtree (its own debits, its own and its descendants' commissions) — never the provider settlement, the base
 * cost, the snapshot or anything above itself (parties outside the scope are role labels). Sales / Report: none.
 */
final class SettlementReadModel
{
    public const PAGE_SIZE = 50;

    /**
     * Settlement of one activation as the actor may see it (NOT_FOUND when nothing of it is in scope).
     *
     * @return array<string,mixed>
     */
    public static function forActivation(ActorContext $a, int $activationId): array
    {
        $scope = ResellerTreeService::authorize($a, C::LEDGER_READ);
        $pdo = FinanceTx::pdo();
        $act = Tx::row($pdo, 'SELECT id, reference, status, manager_id, plan_id, wholesale_cost, retail_price, provider_settlement_amount, plan_snapshot, '
            . 'pricing_chain_snapshot FROM tbl_reseller_activations WHERE id = ?', [$activationId]) ?? throw new DomainError('NOT_FOUND');
        [$pred, $params] = DataScope::staffPredicate($a, C::LEDGER_READ, 'l.manager_id') ?? ['(1 = 1)', []];
        $legs = Tx::rows($pdo, "SELECT l.* FROM tbl_manager_ledger l WHERE l.activation_id = ? AND {$pred} ORDER BY l.id", array_merge([$activationId], $params));
        if ($scope !== 'G' && $legs === []) {
            throw new DomainError('NOT_FOUND');
        }
        $ids = [];
        foreach ($legs as $l) {
            $ids[] = (int) $l['manager_id'];
            $ids[] = $l['counterparty_manager_id'] !== null ? (int) $l['counterparty_manager_id'] : null;
        }
        $names = LedgerReadModel::names($a, C::LEDGER_READ, array_merge($ids, [(int) $act['manager_id']]));
        $debit = null;
        $commissions = [];
        $reversals = [];
        $commissionTotal = Money::zero();
        foreach ($legs as $l) {
            $leg = ['entry_id' => (int) $l['id'], 'manager' => $names[(int) $l['manager_id']], 'amount' => Money::fromDb($l['amount'])->format(),
                'direction' => (string) $l['direction'], 'created_at' => substr((string) $l['created_at'], 0, 19)];
            if ($l['entry_type'] === 'ACTIVATION_DEBIT') {
                $debit = $leg;
            } elseif ($l['entry_type'] === 'COMMISSION_CREDIT') {
                $commissions[] = $leg;
                $commissionTotal = $commissionTotal->plus(Money::fromDb($l['amount']));
            } elseif ($l['entry_type'] === 'REVERSAL') {
                $reversals[] = $leg + ['reverses_entry_id' => $l['reverses_entry_id'] !== null ? (int) $l['reverses_entry_id'] : null, 'reason' => $l['reason']];
            }
        }
        $out = [
            'activation_id' => (int) $act['id'], 'reference' => (string) $act['reference'], 'status' => (string) $act['status'],
            'seller' => $names[(int) $act['manager_id']], 'debit' => $debit, 'commissions' => $commissions, 'reversals' => $reversals,
            'settled' => $debit !== null || ($scope === 'G' && Tx::row($pdo, "SELECT id FROM tbl_manager_ledger WHERE activation_id = ? AND entry_type = 'ACTIVATION_DEBIT'", [$activationId]) !== null),
            'full' => $scope === 'G',
        ];
        if ($scope !== 'G') {
            return $out;
        }
        $wholesale = $act['wholesale_cost'] !== null ? Money::fromDb($act['wholesale_cost']) : null;
        $provider = $act['provider_settlement_amount'] !== null ? Money::fromDb($act['provider_settlement_amount']) : null;
        $out['wholesale'] = $wholesale?->format();
        $out['retail'] = $act['retail_price'] !== null ? Money::fromDb($act['retail_price'])->format() : null;
        $out['provider_settlement'] = $provider?->format();
        $out['commission_total'] = $commissionTotal->format();
        // conservation of the sealed settlement: leaf debit = commissions + provider settlement (only once settled)
        $out['conserved'] = $out['settled'] && $wholesale !== null && $provider !== null ? $wholesale->equals($commissionTotal->plus($provider)) : null;
        $out['base_cost'] = null;
        $out['provider_margin'] = null;
        if ($act['pricing_chain_snapshot'] !== null) {
            try {
                $snap = PricingSnapshot::fromActivationRow($act);
                $out['base_cost'] = $snap->baseCost->format();
                $out['provider_margin'] = $snap->providerMarginOverBase->format();
            } catch (DomainError) {
                $out['snapshot_invalid'] = true;
            }
        }
        return $out;
    }

    /**
     * Activation sales (wholesale debits) of wallets in scope, newest first.
     *
     * @param array<string,mixed> $f from / to (Y-m-d UTC), manager (id in scope)
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,sum:string}
     */
    public static function sales(ActorContext $a, array $f, int $page = 1): array
    {
        return self::legPage($a, 'ACTIVATION_DEBIT', $f, $page);
    }

    /** Commissions credited to wallets in scope, newest first. @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,sum:string} */
    public static function commissions(ActorContext $a, array $f, int $page = 1): array
    {
        return self::legPage($a, 'COMMISSION_CREDIT', $f, $page);
    }

    /** CSV cursor of sales or commissions (same scope / filters; date range required). */
    public static function exportLegs(ActorContext $a, string $type, array $f, callable $sink): int
    {
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        LedgerReadModel::exportRange($f);
        [$where, $params] = self::legWhere($a, self::legType($type), $f);
        $pdo = FinanceTx::pdo();
        return ExportCap::snapshot($pdo,
            static fn(): int => (int) (Tx::row($pdo, 'SELECT COUNT(*) AS n FROM tbl_manager_ledger l WHERE ' . $where, $params)['n'] ?? 0),
            static function () use ($pdo, $a, $where, $params, $sink): int {
                $n = 0;
                $last = PHP_INT_MAX;
                do {
                    $rows = Tx::rows($pdo, self::LEG_SELECT . ' WHERE ' . $where . ' AND l.id < ? ORDER BY l.id DESC LIMIT 1000', array_merge($params, [$last]));
                    foreach (self::legRows($a, $rows) as $r) {
                        $sink($r);
                        $n++;
                    }
                    $last = $rows !== [] ? (int) end($rows)['id'] : $last;
                } while (count($rows) === 1000);
                return $n;
            });
    }

    /**
     * Provider settlement per settled activation (Admin / SuperAdmin only), newest first, with the conservation check.
     *
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,totals:array<string,string>}
     */
    public static function settlement(ActorContext $a, array $f, int $page = 1): array
    {
        self::requireGlobal($a);
        [$where, $params] = self::settlementWhere($f);
        $pdo = FinanceTx::pdo();
        $t = Tx::row($pdo, 'SELECT COUNT(*) AS n, SUM(a.wholesale_cost) AS w, SUM(a.provider_settlement_amount) AS p, '
            . "SUM((SELECT SUM(c.amount) FROM tbl_manager_ledger c WHERE c.activation_id = a.id AND c.entry_type = 'COMMISSION_CREDIT')) AS c "
            . 'FROM tbl_reseller_activations a JOIN tbl_manager_ledger l ON l.activation_id = a.id AND l.entry_type = \'ACTIVATION_DEBIT\' WHERE ' . $where, $params) ?? [];
        $total = (int) ($t['n'] ?? 0);
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = Tx::rows($pdo, self::SETTLEMENT_SELECT . ' WHERE ' . $where . ' ORDER BY a.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $params);
        return ['rows' => self::settlementRows($a, $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'totals' => [
            'wholesale' => Money::fromDbAggregate($t['w'] ?? null)->format(), 'commissions' => Money::fromDbAggregate($t['c'] ?? null)->format(),
            'provider' => Money::fromDbAggregate($t['p'] ?? null)->format(),
        ]];
    }

    /** CSV cursor of the provider settlement (Admin; date range required). */
    public static function exportSettlement(ActorContext $a, array $f, callable $sink): int
    {
        self::requireGlobal($a);
        LedgerReadModel::exportRange($f);
        [$where, $params] = self::settlementWhere($f);
        $pdo = FinanceTx::pdo();
        return ExportCap::snapshot($pdo,
            static fn(): int => (int) (Tx::row($pdo, "SELECT COUNT(*) AS n FROM tbl_reseller_activations a JOIN tbl_manager_ledger l ON l.activation_id = a.id AND l.entry_type = 'ACTIVATION_DEBIT' WHERE " . $where, $params)['n'] ?? 0),
            static function () use ($pdo, $a, $where, $params, $sink): int {
                $n = 0;
                $last = PHP_INT_MAX;
                do {
                    $rows = Tx::rows($pdo, self::SETTLEMENT_SELECT . ' WHERE ' . $where . ' AND a.id < ? ORDER BY a.id DESC LIMIT 1000', array_merge($params, [$last]));
                    foreach (self::settlementRows($a, $rows) as $r) {
                        $sink($r);
                        $n++;
                    }
                    $last = $rows !== [] ? (int) end($rows)['id'] : $last;
                } while (count($rows) === 1000);
                return $n;
            });
    }

    /**
     * Reseller performance over a period: per reseller wallet in wallet.read scope, the number of activation debits,
     * the wholesale debited, the commissions received and the reversal entries — from the ledger only.
     *
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function performance(ActorContext $a, array $f, int $page = 1): array
    {
        ResellerTreeService::authorize($a, C::WALLET_READ);
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        [$pred, $params] = DataScope::staffPredicate($a, C::WALLET_READ, 'u.id') ?? ['(1 = 1)', []];
        $pdo = FinanceTx::pdo();
        $total = (int) (Tx::row($pdo, "SELECT COUNT(*) AS n FROM tbl_users u WHERE u.user_type = 'Agent' AND {$pred}", $params)['n'] ?? 0);
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $who = Tx::rows($pdo, "SELECT u.id, u.username, u.fullname, u.status FROM tbl_users u WHERE u.user_type = 'Agent' AND {$pred} ORDER BY u.username LIMIT "
            . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $params);
        $rows = self::performanceRows($pdo, $who, $f);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * CSV cursor of the reseller performance (fix round 1, OD-6): the same scope and query as the screen; the
     * authoritative scoped reseller count is read before any output and refused above the cap (never truncated);
     * count and rows come from one read snapshot (ExportCap). Date range required.
     */
    public static function exportPerformance(ActorContext $a, array $f, callable $sink): int
    {
        ResellerTreeService::authorize($a, C::WALLET_READ);
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        LedgerReadModel::exportRange($f);
        [$pred, $params] = DataScope::staffPredicate($a, C::WALLET_READ, 'u.id') ?? ['(1 = 1)', []];
        $pdo = FinanceTx::pdo();
        return ExportCap::snapshot($pdo,
            static fn(): int => (int) (Tx::row($pdo, "SELECT COUNT(*) AS n FROM tbl_users u WHERE u.user_type = 'Agent' AND {$pred}", $params)['n'] ?? 0),
            static function () use ($pdo, $pred, $params, $f, $sink): int {
                // the screen's order; offset paging is exact inside the snapshot
                $n = 0;
                do {
                    $who = Tx::rows($pdo, "SELECT u.id, u.username, u.fullname, u.status FROM tbl_users u WHERE u.user_type = 'Agent' AND {$pred} "
                        . 'ORDER BY u.username, u.id LIMIT 1000 OFFSET ' . $n, $params);
                    foreach (self::performanceRows($pdo, $who, $f) as $r) {
                        $sink($r);
                        $n++;
                    }
                } while (count($who) === 1000);
                return $n;
            });
    }

    /**
     * Ledger metrics (activation debits, wholesale, commissions, reversal entries over the period) for resellers.
     *
     * @param list<array<string,mixed>> $who id / username / fullname / status
     * @return list<array<string,mixed>>
     */
    private static function performanceRows(\PDO $pdo, array $who, array $f): array
    {
        $ids = array_map(static fn(array $w): int => (int) $w['id'], $who);
        $metrics = [];
        if ($ids !== []) {
            [$from, $to] = LedgerReadModel::range($f);
            $time = '';
            $tp = [];
            if ($from !== null) {
                $time .= ' AND l.created_at >= ?';
                $tp[] = $from . ' 00:00:00';
            }
            if ($to !== null) {
                $time .= ' AND l.created_at < ?';
                $tp[] = (new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Tx::rows($pdo, "SELECT l.manager_id, l.entry_type, COUNT(*) AS n, SUM(l.amount) AS s FROM tbl_manager_ledger l WHERE l.manager_id IN ({$ph}) "
                . "AND l.entry_type IN ('ACTIVATION_DEBIT', 'COMMISSION_CREDIT', 'REVERSAL'){$time} GROUP BY l.manager_id, l.entry_type", array_merge($ids, $tp)) as $m) {
                $metrics[(int) $m['manager_id']][(string) $m['entry_type']] = ['n' => (int) $m['n'], 's' => Money::fromDbAggregate($m['s'])->format()];
            }
        }
        $rows = [];
        foreach ($who as $w) {
            $m = $metrics[(int) $w['id']] ?? [];
            $rows[] = [
                'id' => (int) $w['id'], 'username' => (string) $w['username'], 'fullname' => (string) $w['fullname'], 'status' => (string) $w['status'],
                'activations' => $m['ACTIVATION_DEBIT']['n'] ?? 0, 'wholesale' => $m['ACTIVATION_DEBIT']['s'] ?? '0.00',
                'commissions_count' => $m['COMMISSION_CREDIT']['n'] ?? 0, 'commissions' => $m['COMMISSION_CREDIT']['s'] ?? '0.00',
                'reversals' => $m['REVERSAL']['n'] ?? 0,
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------------ helpers

    private const LEG_SELECT = 'SELECT l.*, a.reference, a.plan_id, a.retail_price, a.action, p.name_plan, '
        . '(SELECT r.id FROM tbl_manager_ledger r WHERE r.reverses_entry_id = l.id) AS reversed_by '
        . 'FROM tbl_manager_ledger l JOIN tbl_reseller_activations a ON a.id = l.activation_id LEFT JOIN tbl_plans p ON p.id = a.plan_id';

    private const SETTLEMENT_SELECT = 'SELECT a.id, a.reference, a.status, a.manager_id, a.wholesale_cost, a.provider_settlement_amount, a.retail_price, '
        . "(SELECT SUM(c.amount) FROM tbl_manager_ledger c WHERE c.activation_id = a.id AND c.entry_type = 'COMMISSION_CREDIT') AS commissions, "
        . 'l.created_at AS settled_at, p.name_plan '
        . "FROM tbl_reseller_activations a JOIN tbl_manager_ledger l ON l.activation_id = a.id AND l.entry_type = 'ACTIVATION_DEBIT' LEFT JOIN tbl_plans p ON p.id = a.plan_id";

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,sum:string} */
    private static function legPage(ActorContext $a, string $type, array $f, int $page): array
    {
        ResellerTreeService::authorize($a, C::LEDGER_READ);
        [$where, $params] = self::legWhere($a, $type, $f);
        $pdo = FinanceTx::pdo();
        $t = Tx::row($pdo, 'SELECT COUNT(*) AS n, SUM(l.amount) AS s FROM tbl_manager_ledger l WHERE ' . $where, $params) ?? [];
        $total = (int) ($t['n'] ?? 0);
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = Tx::rows($pdo, self::LEG_SELECT . ' WHERE ' . $where . ' ORDER BY l.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $params);
        return ['rows' => self::legRows($a, $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'sum' => Money::fromDbAggregate($t['s'] ?? null)->format()];
    }

    private static function legType(string $type): string
    {
        return match ($type) {
            'sales' => 'ACTIVATION_DEBIT',
            'commissions' => 'COMMISSION_CREDIT',
            default => throw new DomainError('NOT_FOUND'),
        };
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function legWhere(ActorContext $a, string $entryType, array $f): array
    {
        [$pred, $params] = DataScope::staffPredicate($a, C::LEDGER_READ, 'l.manager_id') ?? ['(1 = 1)', []];
        $where = "l.entry_type = ? AND {$pred}";
        array_unshift($params, $entryType);
        if (isset($f['manager']) && DataScope::validId($f['manager'])) {
            $where .= ' AND l.manager_id = ?';
            $params[] = (int) $f['manager'];
        }
        [$from, $to] = LedgerReadModel::range($f);
        if ($from !== null) {
            $where .= ' AND l.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where .= ' AND l.created_at < ?';
            $params[] = (new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        return [$where, $params];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private static function legRows(ActorContext $a, array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['manager_id'];
            $ids[] = $r['counterparty_manager_id'] !== null ? (int) $r['counterparty_manager_id'] : null;
        }
        $names = LedgerReadModel::names($a, C::LEDGER_READ, $ids);
        return array_map(static fn(array $r): array => [
            'entry_id' => (int) $r['id'], 'created_at' => substr((string) $r['created_at'], 0, 19), 'reference' => (string) $r['reference'],
            'activation_id' => (int) $r['activation_id'], 'plan' => (string) ($r['name_plan'] ?? ''), 'action' => (string) $r['action'],
            'manager' => $names[(int) $r['manager_id']], 'seller' => $r['counterparty_manager_id'] !== null ? $names[(int) $r['counterparty_manager_id']] : null,
            'amount' => Money::fromDb($r['amount'])->format(),
            'retail' => $r['retail_price'] !== null && $r['entry_type'] === 'ACTIVATION_DEBIT' ? Money::fromDb($r['retail_price'])->format() : null,
            'reversed' => $r['reversed_by'] !== null,
        ], $rows);
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function settlementWhere(array $f): array
    {
        $where = '1 = 1';
        $params = [];
        if (isset($f['manager']) && DataScope::validId($f['manager'])) {
            $where .= ' AND a.manager_id = ?';
            $params[] = (int) $f['manager'];
        }
        [$from, $to] = LedgerReadModel::range($f);
        if ($from !== null) {
            $where .= ' AND l.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where .= ' AND l.created_at < ?';
            $params[] = (new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        return [$where, $params];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private static function settlementRows(ActorContext $a, array $rows): array
    {
        $names = LedgerReadModel::names($a, C::LEDGER_READ, array_map(static fn(array $r): int => (int) $r['manager_id'], $rows));
        return array_map(static function (array $r) use ($names): array {
            $w = $r['wholesale_cost'] !== null ? Money::fromDb($r['wholesale_cost']) : null;
            $p = $r['provider_settlement_amount'] !== null ? Money::fromDb($r['provider_settlement_amount']) : null;
            $c = Money::fromDbAggregate($r['commissions']);
            return [
                'activation_id' => (int) $r['id'], 'reference' => (string) $r['reference'], 'status' => (string) $r['status'], 'plan' => (string) ($r['name_plan'] ?? ''),
                'seller' => $names[(int) $r['manager_id']], 'settled_at' => substr((string) $r['settled_at'], 0, 19),
                'wholesale' => $w?->format(), 'commissions' => $c->format(), 'provider_settlement' => $p?->format(),
                'retail' => $r['retail_price'] !== null ? Money::fromDb($r['retail_price'])->format() : null,
                'conserved' => $w !== null && $p !== null ? $w->equals($c->plus($p)) : null,
            ];
        }, $rows);
    }

    private static function requireGlobal(ActorContext $a): void
    {
        if (ResellerTreeService::authorize($a, C::LEDGER_READ) !== 'G' || PermissionService::scopeOf($a, C::WALLET_READ) !== 'G') {
            throw new DomainError('NOT_FOUND');
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // WP9-OPS / TAMPER (owner §3 "finance and ledger consistency"): READ-ONLY whole-database integrity report of the
    // reseller finance and activation records, for the operator CLI (`reconcile.php finance-check`, through
    // Reseller\Activation\DriftReport::financeIntegrity) and the WP10 health check. It is the runtime form of the
    // WP3A-WP5B test invariants: ledger continuity per wallet and wallet = last balance_after, available >= 0, transfer
    // legs, reversal links, reservation <-> debit, WP3B settlement against the sealed snapshot, activation timelines and
    // their reservation / legacy records. It never writes (Reader refuses anything but SELECT); a non-empty result means
    // tampering, a bug or a write outside the finance services.
    // ---------------------------------------------------------------------------------------------------------------

    /** Read-only integrity report of the CURRENT database (operator CLI). @return list<string> */
    public static function integrity(): array
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db = new Reader($pdo);
        return array_merge(self::integrityCheck($db), self::activationIntegrity($db));
    }

    /** @return list<string> */
    public static function integrityCheck(Reader $db): array
    {
        $issues = [];
        $ledger = $db->rows('SELECT * FROM tbl_manager_ledger ORDER BY id');
        $byId = [];
        $byManager = [];
        $byTransfer = [];
        foreach ($ledger as $e) {
            $byId[(int) $e['id']] = $e;
            $byManager[(int) $e['manager_id']][] = $e;
            if ($e['transfer_id'] !== null) {
                $byTransfer[(int) $e['transfer_id']][] = $e;
            }
            if (!preg_match('/^(trf:[1-9][0-9]*:(credit|debit|out|in)|rev:[1-9][0-9]*:(credit|debit|e[1-9][0-9]*)|res:[1-9][0-9]*:commit|com:[1-9][0-9]*:m[1-9][0-9]*)$/D', (string) $e['entry_key'])) {
                $issues[] = "ledger {$e['id']}: unexpected entry key {$e['entry_key']}";
            }
            if ($e['transfer_id'] === null && !in_array($e['entry_type'], ['ACTIVATION_DEBIT', 'COMMISSION_CREDIT'], true)
                && !($e['entry_type'] === 'REVERSAL' && $e['activation_id'] !== null)) {
                $issues[] = "ledger {$e['id']}: {$e['entry_type']} without an operation";
            }
        }
        $reservations = $db->rows('SELECT * FROM tbl_manager_wallet_reservations ORDER BY id');
        $activeByManager = [];
        foreach ($reservations as $r) {
            if ($r['status'] === 'ACTIVE') {
                $activeByManager[(int) $r['manager_id']] = ($activeByManager[(int) $r['manager_id']] ?? Money::zero())->plus(Money::fromDb($r['amount']));
            }
        }
        foreach ($db->rows('SELECT * FROM tbl_manager_wallets ORDER BY manager_id') as $w) {
            $m = (int) $w['manager_id'];
            $prev = Money::zero();
            foreach ($byManager[$m] ?? [] as $e) {
                $before = Money::fromDb($e['balance_before']);
                $after = Money::fromDb($e['balance_after']);
                $amount = Money::fromDb($e['amount']);
                if (!$before->equals($prev)) {
                    $issues[] = "wallet {$m} ledger {$e['id']}: balance_before {$before->format()} != previous {$prev->format()}";
                }
                $expect = $e['direction'] === 'CREDIT' ? $before->plus($amount) : $before->minus($amount);
                if (!$after->equals($expect) || !$amount->isPositive()) {
                    $issues[] = "wallet {$m} ledger {$e['id']}: arithmetic";
                }
                $prev = $after;
            }
            $balance = Money::fromDb($w['balance']);
            $debt = Money::fromDb($w['debt_limit']);
            if (!$balance->equals($prev)) {
                $issues[] = "wallet {$m}: balance {$balance->format()} != ledger {$prev->format()}";
            }
            if ($debt->isNegative()) {
                $issues[] = "wallet {$m}: negative debt limit";
            }
            $available = $balance->plus($debt)->minus($activeByManager[$m] ?? Money::zero());
            if ($available->isNegative()) {
                $issues[] = "wallet {$m}: available {$available->format()} < 0";
            }
        }
        $transfers = [];
        foreach ($db->rows('SELECT * FROM tbl_manager_transfers ORDER BY id') as $t) {
            $transfers[(int) $t['id']] = $t;
        }
        $reversedBy = [];
        foreach ($transfers as $id => $t) {
            if (!in_array($t['status'], ['COMPLETED', 'REVERSED'], true)) {
                $issues[] = "transfer {$id}: status {$t['status']}";
            }
            if ($t['completed_at'] === null) {
                $issues[] = "transfer {$id}: no completed_at";
            }
            $from = $t['from_manager_id'] !== null ? (int) $t['from_manager_id'] : null;
            $to = $t['to_manager_id'] !== null ? (int) $t['to_manager_id'] : null;
            $rev = $t['reverses_transfer_id'] !== null ? (int) $t['reverses_transfer_id'] : null;
            $expected = [];
            if ($rev === null) {
                $map = [
                    'PROVIDER_FUNDING' => [['credit', 'FUNDING', $to, 'credit']],
                    'PROVIDER_WITHDRAWAL' => [['debit', 'WITHDRAWAL', $from, 'debit']],
                    'ADJUSTMENT_CREDIT' => [['credit', 'ADJUSTMENT', $to, 'credit']],
                    'ADJUSTMENT_DEBIT' => [['debit', 'ADJUSTMENT', $from, 'debit']],
                    'PARENT_CHILD_TRANSFER' => [['debit', 'TRANSFER_OUT', $from, 'out'], ['credit', 'TRANSFER_IN', $to, 'in']],
                ];
                foreach ($map[$t['operation_type']] as [$side, $type, $mgr, $leg]) {
                    $expected["trf:{$id}:{$leg}"] = [$side, $type, $mgr];
                }
            } else {
                $reversedBy[$rev][] = $id;
                if ($from !== null) {
                    $expected["rev:{$id}:debit"] = ['debit', 'REVERSAL', $from];
                }
                if ($to !== null) {
                    $expected["rev:{$id}:credit"] = ['credit', 'REVERSAL', $to];
                }
                $o = $transfers[$rev] ?? null;
                $inverse = ['PROVIDER_FUNDING' => 'PROVIDER_WITHDRAWAL', 'PROVIDER_WITHDRAWAL' => 'PROVIDER_FUNDING', 'ADJUSTMENT_CREDIT' => 'ADJUSTMENT_DEBIT',
                    'ADJUSTMENT_DEBIT' => 'ADJUSTMENT_CREDIT', 'PARENT_CHILD_TRANSFER' => 'PARENT_CHILD_TRANSFER'];
                if ($o === null || $o['status'] !== 'REVERSED' || $o['reverses_transfer_id'] !== null
                    || $inverse[$o['operation_type']] !== $t['operation_type'] || $o['amount'] !== $t['amount']
                    || $o['from_manager_id'] !== $t['to_manager_id'] || $o['to_manager_id'] !== $t['from_manager_id'] || $t['status'] !== 'COMPLETED') {
                    $issues[] = "transfer {$id}: reversal of {$rev} is not a mirrored compensation";
                }
            }
            $entries = $byTransfer[$id] ?? [];
            if (count($entries) !== count($expected)) {
                $issues[] = "transfer {$id}: " . count($entries) . ' ledger entries, expected ' . count($expected);
            }
            foreach ($entries as $e) {
                $x = $expected[(string) $e['entry_key']] ?? null;
                if ($x === null || strtolower((string) $e['direction']) !== $x[0] || $e['entry_type'] !== $x[1] || (int) $e['manager_id'] !== $x[2]) {
                    $issues[] = "transfer {$id}: unexpected entry {$e['entry_key']}";
                    continue;
                }
                if ($e['amount'] !== $t['amount'] || $e['actor_type'] !== 'STAFF' || (int) $e['operator_id'] !== (int) $t['operator_id']) {
                    $issues[] = "transfer {$id}: entry {$e['id']} amount/actor differs from the operation";
                }
                if ($rev !== null) {
                    $orig = $byId[(int) ($e['reverses_entry_id'] ?? 0)] ?? null;
                    if ($orig === null || (int) $orig['transfer_id'] !== $rev || (int) $orig['manager_id'] !== (int) $e['manager_id'] || $orig['direction'] === $e['direction']) {
                        $issues[] = "transfer {$id}: entry {$e['id']} does not compensate an entry of {$rev}";
                    }
                } elseif ($e['reverses_entry_id'] !== null) {
                    $issues[] = "transfer {$id}: entry {$e['id']} has a reverses_entry_id";
                }
            }
            if ($t['status'] === 'REVERSED' && count($reversedBy[$id] ?? []) === 0) {
                // the reversal row has a higher id; checked again below
            }
        }
        foreach ($transfers as $id => $t) {
            $n = count($reversedBy[$id] ?? []);
            if (($t['status'] === 'REVERSED') !== ($n === 1) || $n > 1) {
                $issues[] = "transfer {$id}: status {$t['status']} with {$n} reversal(s)";
            }
        }
        $debitRefs = [];
        foreach ($reservations as $r) {
            $id = (int) $r['id'];
            if ($r['expires_at'] !== null) {
                $issues[] = "reservation {$id}: expires_at set (WP3A never schedules expiry)";
            }
            if ($r['status'] === 'COMMITTED') {
                $e = $byId[(int) ($r['committed_ledger_entry_id'] ?? 0)] ?? null;
                if ($e === null || $e['entry_type'] !== 'ACTIVATION_DEBIT' || $e['direction'] !== 'DEBIT' || $e['amount'] !== $r['amount']
                    || (int) $e['manager_id'] !== (int) $r['manager_id'] || (int) $e['activation_id'] !== (int) $r['activation_id']
                    || $e['entry_key'] !== "res:{$id}:commit") {
                    $issues[] = "reservation {$id}: committed without its exact ACTIVATION_DEBIT";
                } else {
                    $debitRefs[(int) $e['id']] = ($debitRefs[(int) $e['id']] ?? 0) + 1;
                }
            } elseif ($r['committed_ledger_entry_id'] !== null) {
                $issues[] = "reservation {$id}: {$r['status']} with a ledger entry";
            }
            if (($r['status'] === 'ACTIVE') !== ($r['resolved_at'] === null)) {
                $issues[] = "reservation {$id}: resolution timestamp inconsistent";
            }
        }
        foreach ($ledger as $e) {
            if ($e['entry_type'] === 'ACTIVATION_DEBIT' && ($debitRefs[(int) $e['id']] ?? 0) !== 1) {
                $issues[] = "ledger {$e['id']}: ACTIVATION_DEBIT not linked to exactly one committed reservation";
            }
        }
        return array_merge($issues, self::integritySettlements($db, $ledger, $reservations, $byId), self::integrityReversals($db, $ledger, $byId));
    }

    /**
     * WP5B completed-activation reversals: every activation REVERSAL entry compensates exactly one original leg
     * of its activation (ACTIVATION_DEBIT / COMMISSION_CREDIT: same wallet and amount, opposite direction, key
     * rev:{activation}:e{original}, STAFF actor with a reason); a REVERSED activation has every original leg
     * reversed exactly once and any other activation none (the reversal commits all-or-nothing).
     *
     * @param list<array<string,mixed>> $ledger
     * @param array<int,array<string,mixed>> $byId
     * @return list<string>
     */
    private static function integrityReversals(Reader $db, array $ledger, array $byId): array
    {
        $issues = [];
        $legs = [];
        $reversed = [];
        foreach ($ledger as $e) {
            if ($e['activation_id'] !== null && in_array($e['entry_type'], ['ACTIVATION_DEBIT', 'COMMISSION_CREDIT'], true)) {
                $legs[(int) $e['activation_id']][] = (int) $e['id'];
            }
            if ($e['entry_type'] !== 'REVERSAL' || $e['transfer_id'] !== null) {
                continue;
            }
            $act = (int) $e['activation_id'];
            $o = $byId[(int) ($e['reverses_entry_id'] ?? 0)] ?? null;
            if ($o === null || (int) $o['activation_id'] !== $act || !in_array($o['entry_type'], ['ACTIVATION_DEBIT', 'COMMISSION_CREDIT'], true)
                || (int) $o['manager_id'] !== (int) $e['manager_id'] || $o['direction'] === $e['direction'] || $o['amount'] !== $e['amount']
                || $e['entry_key'] !== "rev:{$act}:e{$o['id']}" || $e['actor_type'] !== 'STAFF' || $e['operator_id'] === null || $e['reason'] === null) {
                $issues[] = "ledger {$e['id']}: activation reversal does not compensate one original leg of activation {$act}";
                continue;
            }
            $reversed[$act][] = (int) $o['id'];
        }
        $status = [];
        foreach ($db->rows('SELECT id, status FROM tbl_reseller_activations') as $a) {
            $status[(int) $a['id']] = $a['status'];
        }
        foreach ($status as $act => $st) {
            $want = $st === 'REVERSED' ? ($legs[$act] ?? []) : [];
            $got = $reversed[$act] ?? [];
            sort($want);
            sort($got);
            if ($want !== $got || ($st === 'REVERSED' && $want === [])) {
                $issues[] = "activation {$act}: {$st} with reversed legs " . json_encode($got) . ', expected ' . json_encode($want);
            }
        }
        return $issues;
    }

    /**
     * WP3B activation settlements: commission legs and exact conservation per committed activation.
     *
     * @param list<array<string,mixed>> $ledger
     * @param list<array<string,mixed>> $reservations
     * @param array<int,array<string,mixed>> $byId
     * @return list<string>
     */
    private static function integritySettlements(Reader $db, array $ledger, array $reservations, array $byId): array
    {
        $issues = [];
        $commissions = [];
        foreach ($ledger as $e) {
            if ($e['entry_type'] === 'COMMISSION_CREDIT') {
                $commissions[(int) $e['activation_id']][] = $e;
                if ($e['direction'] !== 'CREDIT' || $e['transfer_id'] !== null || $e['reverses_entry_id'] !== null) {
                    $issues[] = "ledger {$e['id']}: malformed COMMISSION_CREDIT";
                }
            }
        }
        $committed = [];
        foreach ($reservations as $r) {
            if ($r['status'] === 'COMMITTED') {
                $committed[(int) $r['activation_id']][] = $r;
            }
        }
        foreach ($commissions as $act => $entries) {
            if (count($committed[$act] ?? []) !== 1) {
                $issues[] = "activation {$act}: commission credits without exactly one committed reservation";
            }
        }
        foreach ($committed as $act => $rows) {
            if (count($rows) !== 1) {
                $issues[] = "activation {$act}: more than one committed reservation";
                continue;
            }
            $r = $rows[0];
            $debit = $byId[(int) ($r['committed_ledger_entry_id'] ?? 0)] ?? null;
            $a = $db->rows('SELECT * FROM tbl_reseller_activations WHERE id = ?', [$act])[0] ?? null;
            if ($a === null || $debit === null) {
                $issues[] = "activation {$act}: settlement without activation or debit";
                continue;
            }
            $entries = $commissions[$act] ?? [];
            if ($a['pricing_chain_snapshot'] === null) {
                if ($entries !== []) {
                    $issues[] = "activation {$act}: commission credits without a pricing snapshot";
                }
                continue;
            }
            try {
                $snap = PricingSnapshot::fromActivationRow($a);
            } catch (DomainError $e) {
                $issues[] = "activation {$act}: committed with an invalid pricing snapshot ({$e->getMessage()})";
                continue;
            }
            if ($debit['amount'] !== $snap->leafDebit->format() || $a['wholesale_cost'] !== $snap->leafDebit->format() || (int) $debit['manager_id'] !== $snap->leafManagerId) {
                $issues[] = "activation {$act}: debit differs from the snapshot leaf wholesale";
            }
            $expected = [];
            foreach ($snap->commissionRecipients() as $m => $amount) {
                $expected["com:{$act}:m{$m}"] = [$m, $amount->format()];
            }
            $sum = $snap->providerSettlement;
            $seen = [];
            foreach ($entries as $e) {
                $x = $expected[(string) $e['entry_key']] ?? null;
                if ($x === null || (int) $e['manager_id'] !== $x[0] || $e['amount'] !== $x[1] || (int) $e['counterparty_manager_id'] !== $snap->leafManagerId
                    || $e['actor_type'] !== $debit['actor_type'] || $e['operator_id'] !== $debit['operator_id'] || $e['actor_customer_id'] !== $debit['actor_customer_id']) {
                    $issues[] = "activation {$act}: unexpected commission entry {$e['entry_key']}";
                    continue;
                }
                $seen[(string) $e['entry_key']] = true;
                $sum = $sum->plus(Money::fromDb($e['amount']));
            }
            if (count($seen) !== count($expected) || count($entries) !== count($expected)) {
                $issues[] = "activation {$act}: commission set differs from the snapshot";
            }
            if (!$sum->equals(Money::fromDb($debit['amount'])) || $a['provider_settlement_amount'] !== $snap->providerSettlement->format()) {
                $issues[] = "activation {$act}: settlement does not conserve the leaf debit";
            }
        }
        return $issues;
    }

    /**
     * Every activation's timeline is contiguous from CREATED with allowed transitions only, its status equals the timeline,
     * its reservation state matches its status, a COMPLETED / reversed activation has its legacy records and exactly the
     * compensating reversal transaction (the WP5A / WP5B activation invariants).
     *
     * @return list<string>
     */
    public static function activationIntegrity(Reader $db): array
    {
        $problems = [];
        foreach ($db->rows('SELECT * FROM tbl_reseller_activations ORDER BY id') as $a) {
            $events = $db->rows('SELECT * FROM tbl_reseller_activation_events WHERE activation_id = ? ORDER BY sequence_no', [$a['id']]);
            $tag = $a['reference'] . ' ';
            if ($events === [] || $events[0]['to_state'] !== 'CREATED' || $events[0]['from_state'] !== null) {
                $problems[] = $tag . 'timeline does not start at CREATED';
            }
            $state = null;
            foreach ($events as $i => $e) {
                if ((int) $e['sequence_no'] !== $i + 1) {
                    $problems[] = $tag . 'sequence gap at ' . $e['sequence_no'];
                }
                if ($i > 0 && $e['from_state'] !== $state) {
                    $problems[] = $tag . 'event ' . $e['sequence_no'] . ' does not continue from ' . $state;
                }
                if ($i > 0 && $e['from_state'] !== $e['to_state'] && !\Reseller\Activation\ActivationState::allowed((string) $e['from_state'], (string) $e['to_state'])) {
                    $problems[] = $tag . 'forbidden transition ' . $e['from_state'] . ' -> ' . $e['to_state'];
                }
                $state = $e['to_state'];
            }
            if ($state !== $a['status']) {
                $problems[] = $tag . 'status ' . $a['status'] . ' differs from the timeline ' . $state;
            }
            $statuses = array_column($db->rows('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [$a['id']]), 'status');
            $want = match ($a['status']) {
                'COMPLETED', 'REVERSAL_PENDING', 'REVERSED' => ['COMMITTED'],
                'FAILED', 'CANCELLED', 'ROLLED_BACK' => ['RELEASED'],
                'CREATED', 'VALIDATED' => [],
                default => ['ACTIVE'],
            };
            if ($statuses !== $want) {
                $problems[] = $tag . $a['status'] . ' with reservations ' . json_encode($statuses);
            }
            if (in_array($a['status'], ['COMPLETED', 'REVERSAL_PENDING', 'REVERSED'], true) && ($a['legacy_recharge_id'] === null || $a['legacy_transaction_id'] === null || $a['legacy_invoice'] !== 'INV-' . $a['reference'])) {
                $problems[] = $tag . 'COMPLETED without its legacy records';
            }
            $rev = (int) ($db->rows('SELECT COUNT(*) AS n FROM tbl_transactions WHERE invoice = ?', [\Reseller\Activation\LegacyBridge::reversalInvoice((string) $a['reference'])])[0]['n'] ?? 0);
            if ($rev !== ($a['status'] === 'REVERSED' ? 1 : 0)) {
                $problems[] = $tag . $a['status'] . " with {$rev} reversal transaction(s)";
            }
        }
        return $problems;
    }
}
