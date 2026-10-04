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
        if ((int) (Tx::row($pdo, 'SELECT COUNT(*) AS n FROM tbl_manager_ledger l WHERE ' . $where, $params)['n'] ?? 0) > LedgerReadModel::EXPORT_MAX_ROWS) {
            throw new DomainError('EXPORT_TOO_LARGE');
        }
        $n = 0;
        $last = PHP_INT_MAX;
        do {
            $rows = Tx::rows($pdo, self::LEG_SELECT . ' WHERE ' . $where . ' AND l.id < ? ORDER BY l.id DESC LIMIT 1000', array_merge($params, [$last]));
            foreach (self::legRows($a, $rows) as $r) {
                $sink($r);
                $n++;
            }
            $last = $rows !== [] ? (int) end($rows)['id'] : $last;
        } while (count($rows) === 1000 && $n < LedgerReadModel::EXPORT_MAX_ROWS);
        return $n;
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
        $n = 0;
        $last = PHP_INT_MAX;
        if ((int) (Tx::row($pdo, "SELECT COUNT(*) AS n FROM tbl_reseller_activations a JOIN tbl_manager_ledger l ON l.activation_id = a.id AND l.entry_type = 'ACTIVATION_DEBIT' WHERE " . $where, $params)['n'] ?? 0) > LedgerReadModel::EXPORT_MAX_ROWS) {
            throw new DomainError('EXPORT_TOO_LARGE');
        }
        do {
            $rows = Tx::rows($pdo, self::SETTLEMENT_SELECT . ' WHERE ' . $where . ' AND a.id < ? ORDER BY a.id DESC LIMIT 1000', array_merge($params, [$last]));
            foreach (self::settlementRows($a, $rows) as $r) {
                $sink($r);
                $n++;
            }
            $last = $rows !== [] ? (int) end($rows)['id'] : $last;
        } while (count($rows) === 1000 && $n < LedgerReadModel::EXPORT_MAX_ROWS);
        return $n;
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
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
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
}
