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
 * Reseller pricing (WP3B). The ONLY runtime writer of tbl_reseller_plan_base_costs and
 * tbl_manager_plan_prices; never writes tbl_plans, wallets or the ledger.
 *
 * Price domains (D009/D010): the provider base cost (tbl_reseller_plan_base_costs) is the wholesale
 * floor; every reseller has an effective wholesale cost = its own override, else the nearest
 * ancestor override, else the base cost (D011/D023: a child's effective cost is never below its
 * parent's). Retail is separate: an explicit retail override, else the legacy default tbl_plans.price
 * parsed strictly (LegacyRetailPrice). Availability: a missing base row or resale disabled closes the
 * plan for everyone; an explicit 0 anywhere on the path closes it for that subtree; an explicit 1
 * never overrides an ancestor/global 0.
 *
 * Owner decisions: a Balance-type plan is never resellable (F-4); a leaf wholesale of 0 is not
 * activatable (F-3); an upstream wholesale/base raise that would put an explicit descendant
 * wholesale override below its new parent price is rejected (OD-5), while one that puts a
 * descendant's retail below its wholesale is accepted and that descendant becomes not saleable
 * until corrected (F-5, reported as warnings).
 *
 * Every configuration mutation: capability (WP2 PermissionService) -> strict input -> global tree
 * lock -> BEGIN (strict sql_mode) -> actor revalidated -> base row FOR UPDATE -> the plan's override
 * rows FOR UPDATE (ascending manager id) -> authoritative validation -> write -> R0006 audit ->
 * COMMIT. Resolution for an activation (resolveInTx) runs under the same tree lock, inside the
 * caller's transaction (WP5A), from authoritative rows only; no browser value is ever used.
 */
final class PricingService
{
    private const OVERRIDE_COLUMNS = [
        'wholesale' => ['wholesale_cost', 'wholesale_set_by', 'wholesale_set_at'],
        'retail' => ['retail_price', 'retail_set_by', 'retail_set_at'],
        'availability' => ['is_enabled', 'availability_set_by', 'availability_set_at'],
    ];
    private const WARNING_IDS = 50;

    // ----------------------------------------------------------------- configuration mutations

    /** Provider base cost of a plan (created with resale disabled; enable it separately). */
    public static function setBaseCost(ActorContext $a, mixed $planId, mixed $baseCost, mixed $reason = null): PricingResult
    {
        ResellerTreeService::authorize($a, C::PRICING_BASE_COST);
        $plan = FinanceInput::id($planId);
        $cost = Money::parseNonNegative($baseCost);
        $cost->toStorage();
        $reason = self::reason($reason);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $plan, $cost, $reason): PricingResult {
            ResellerTreeService::revalidateActor($pdo, $a);
            $p = self::plan($pdo, $plan);
            $base = self::baseRow($pdo, $plan, true);
            $rows = self::overrideRows($pdo, $plan, true);
            $old = $base !== null ? Money::fromDb($base['base_cost']) : null;
            $before = ['base_cost' => $old?->format(), 'resale_enabled' => $base !== null ? (int) $base['is_resale_enabled'] === 1 : null];
            if ($old !== null && $old->equals($cost)) {
                return new PricingResult(false, $before, $before);
            }
            $agents = self::agents($pdo);
            $wholesale = self::wholesaleOverrides($rows);
            $violBefore = $old !== null ? self::violations($agents, $old, $wholesale) : [];
            $violAfter = self::violations($agents, $cost, $wholesale);
            if (array_diff_key($violAfter, $violBefore) !== []) {
                throw new DomainError('BASE_COST_DESCENDANT_CONFLICT', DomainError::MESSAGES['BASE_COST_DESCENDANT_CONFLICT'] . ' (' . count(array_diff_key($violAfter, $violBefore)) . ' reseller price(s))');
            }
            $warnings = $old !== null
                ? self::newlyUnsaleable($agents, $p, $rows, self::effective($agents, $old, $wholesale), self::effective($agents, $cost, $wholesale))
                : [];
            $now = Tx::utcNow();
            if ($base === null) {
                Tx::exec(
                    $pdo,
                    'INSERT INTO tbl_reseller_plan_base_costs (plan_id, base_cost, is_resale_enabled, created_by, created_at, updated_by, updated_at) VALUES (?, ?, 0, ?, ?, ?, ?)',
                    [$plan, $cost->toStorage(), (int) $a->userId, $now, (int) $a->userId, $now]
                );
            } else {
                Tx::exec(
                    $pdo,
                    'UPDATE tbl_reseller_plan_base_costs SET base_cost = ?, updated_by = ?, updated_at = ? WHERE plan_id = ?',
                    [$cost->toStorage(), (int) $a->userId, $now, $plan]
                );
            }
            FinanceFaults::hit('pricing.after_write');
            $after = ['base_cost' => $cost->format(), 'resale_enabled' => $base !== null && (int) $base['is_resale_enabled'] === 1];
            AuditLog::record('pricing.base_cost_set', $a, [
                'target_type' => 'plan', 'target_snapshot' => self::planIdentity($p),
                'before' => $before, 'after' => $after + self::warningAudit($warnings), 'reason' => $reason,
            ], $pdo);
            FinanceFaults::hit('pricing.after_audit');
            return new PricingResult(true, $before, $after, $warnings);
        }, true);
    }

    /** Global reseller-commerce enablement of a plan (requires a base cost). */
    public static function setResaleEnabled(ActorContext $a, mixed $planId, bool $enabled, mixed $reason = null): PricingResult
    {
        ResellerTreeService::authorize($a, C::PRICING_BASE_COST);
        $plan = FinanceInput::id($planId);
        $reason = self::reason($reason);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $plan, $enabled, $reason): PricingResult {
            ResellerTreeService::revalidateActor($pdo, $a);
            $p = self::plan($pdo, $plan);
            $base = self::baseRow($pdo, $plan, true) ?? throw new DomainError('PLAN_NOT_RESELLABLE');
            $was = (int) $base['is_resale_enabled'] === 1;
            $before = ['resale_enabled' => $was];
            if ($was === $enabled) {
                return new PricingResult(false, $before, $before);
            }
            Tx::exec(
                $pdo,
                'UPDATE tbl_reseller_plan_base_costs SET is_resale_enabled = ?, updated_by = ?, updated_at = ? WHERE plan_id = ?',
                [$enabled ? 1 : 0, (int) $a->userId, Tx::utcNow(), $plan]
            );
            FinanceFaults::hit('pricing.after_write');
            $after = ['resale_enabled' => $enabled];
            AuditLog::record($enabled ? 'pricing.resale_enable' : 'pricing.resale_disable', $a, [
                'target_type' => 'plan', 'target_snapshot' => self::planIdentity($p),
                'before' => $before + ['base_cost' => Money::fromDb($base['base_cost'])->format()], 'after' => $after, 'reason' => $reason,
            ], $pdo);
            FinanceFaults::hit('pricing.after_audit');
            return new PricingResult(true, $before, $after);
        }, true);
    }

    /**
     * Wholesale override of a reseller (null = clear, inherit). Agent: a direct child only, never itself;
     * Admin/SuperAdmin: any reseller. The value must be >= the parent's effective wholesale (or the base
     * cost for a top-level reseller), and the change must not introduce an explicit descendant override
     * below its new parent price (OD-5). Descendants whose retail falls below their new wholesale are
     * reported (F-5), never rewritten.
     */
    public static function setChildWholesale(ActorContext $a, mixed $managerId, mixed $planId, mixed $cost, mixed $reason = null): PricingResult
    {
        $scope = ResellerTreeService::authorize($a, C::PRICING_CHILD_WHOLESALE);
        $target = FinanceInput::id($managerId);
        $plan = FinanceInput::id($planId);
        $value = $cost === null ? null : Money::parseNonNegative($cost);
        $value?->toStorage();
        $reason = self::reason($reason);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $scope, $target, $plan, $value, $reason): PricingResult {
            ResellerTreeService::revalidateActor($pdo, $a);
            $user = self::target($pdo, $a, $scope, C::PRICING_CHILD_WHOLESALE, $target, true);
            $p = self::plan($pdo, $plan);
            $base = self::baseRow($pdo, $plan, true) ?? throw new DomainError('PLAN_NOT_RESELLABLE');
            $rows = self::overrideRows($pdo, $plan, true);
            $baseCost = Money::fromDb($base['base_cost']);
            $current = isset($rows[$target]) && $rows[$target]['wholesale_cost'] !== null ? Money::fromDb($rows[$target]['wholesale_cost']) : null;
            $before = self::overrideState($rows[$target] ?? null, 'wholesale');
            if (($current === null && $value === null) || ($current !== null && $value !== null && $current->equals($value))) {
                return new PricingResult(false, $before, $before);
            }
            $agents = self::agents($pdo);
            $wholesale = self::wholesaleOverrides($rows);
            $effBefore = self::effective($agents, $baseCost, $wholesale);
            if ($value !== null) {
                $parent = $agents[$target]['parent'] ?? null;
                $floor = $parent !== null && isset($effBefore[$parent]) ? $effBefore[$parent]['e'] : $baseCost;
                if ($value->compare($floor) < 0) {
                    throw new DomainError('WHOLESALE_BELOW_PARENT');
                }
            }
            $wholesaleAfter = $wholesale;
            if ($value === null) {
                unset($wholesaleAfter[$target]);
            } else {
                $wholesaleAfter[$target] = $value;
            }
            $introduced = array_diff_key(self::violations($agents, $baseCost, $wholesaleAfter), self::violations($agents, $baseCost, $wholesale));
            if ($introduced !== []) {
                throw new DomainError('WHOLESALE_DESCENDANT_CONFLICT', DomainError::MESSAGES['WHOLESALE_DESCENDANT_CONFLICT'] . ' (' . count($introduced) . ' sub-reseller price(s))');
            }
            $warnings = self::newlyUnsaleable($agents, $p, $rows, $effBefore, self::effective($agents, $baseCost, $wholesaleAfter));
            self::writeOverride($pdo, $rows[$target] ?? null, $target, $plan, 'wholesale', $value?->toStorage(), (int) $a->userId);
            FinanceFaults::hit('pricing.after_write');
            $after = self::overrideState(self::overrideRows($pdo, $plan, false)[$target] ?? null, 'wholesale');
            AuditLog::record($value === null ? 'pricing.wholesale_clear' : 'pricing.wholesale_set', $a, [
                'target_type' => 'pricing', 'target_user_id' => $target,
                'target_snapshot' => WalletOwner::snapshot($user) + ['plan' => self::planIdentity($p)],
                'before' => $before, 'after' => $after + self::warningAudit($warnings), 'reason' => $reason,
            ], $pdo);
            FinanceFaults::hit('pricing.after_audit');
            return new PricingResult(true, $before, $after, $warnings);
        }, true);
    }

    /**
     * Retail (customer-facing) override of a reseller (null = clear, use the legacy default price).
     * Agent: its own retail only; Admin/SuperAdmin: any reseller (OD-4). The resulting effective retail
     * must be >= the reseller's effective wholesale; clearing is refused when the legacy default would
     * be invalid or below wholesale. tbl_plans.price is never written.
     */
    public static function setRetail(ActorContext $a, mixed $managerId, mixed $planId, mixed $price, mixed $reason = null): PricingResult
    {
        $scope = ResellerTreeService::authorize($a, C::PRICING_OWN_RETAIL);
        $target = FinanceInput::id($managerId);
        $plan = FinanceInput::id($planId);
        $value = $price === null ? null : Money::parseNonNegative($price);
        $value?->toStorage();
        $reason = self::reason($reason);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $scope, $target, $plan, $value, $reason): PricingResult {
            ResellerTreeService::revalidateActor($pdo, $a);
            $user = self::target($pdo, $a, $scope, C::PRICING_OWN_RETAIL, $target, false);
            $p = self::plan($pdo, $plan);
            $base = self::baseRow($pdo, $plan, true) ?? throw new DomainError('PLAN_NOT_RESELLABLE');
            $rows = self::overrideRows($pdo, $plan, true);
            $current = isset($rows[$target]) && $rows[$target]['retail_price'] !== null ? Money::fromDb($rows[$target]['retail_price']) : null;
            $before = self::overrideState($rows[$target] ?? null, 'retail');
            if (($current === null && $value === null) || ($current !== null && $value !== null && $current->equals($value))) {
                return new PricingResult(false, $before, $before);
            }
            $agents = self::agents($pdo);
            $eff = self::effective($agents, Money::fromDb($base['base_cost']), self::wholesaleOverrides($rows));
            $wholesale = $eff[$target]['e'] ?? Money::fromDb($base['base_cost']);
            if ($value !== null) {
                if ($value->compare($wholesale) < 0) {
                    throw new DomainError('RETAIL_BELOW_WHOLESALE');
                }
            } else {
                $legacy = LegacyRetailPrice::tryParse($p['price']);
                if ($legacy === null || $legacy->compare($wholesale) < 0) {
                    throw new DomainError('RETAIL_CLEAR_INVALID');
                }
            }
            self::writeOverride($pdo, $rows[$target] ?? null, $target, $plan, 'retail', $value?->toStorage(), (int) $a->userId);
            FinanceFaults::hit('pricing.after_write');
            $after = self::overrideState(self::overrideRows($pdo, $plan, false)[$target] ?? null, 'retail');
            AuditLog::record($value === null ? 'pricing.retail_clear' : 'pricing.retail_set', $a, [
                'target_type' => 'pricing', 'target_user_id' => $target,
                'target_snapshot' => WalletOwner::snapshot($user) + ['plan' => self::planIdentity($p)],
                'before' => $before, 'after' => $after, 'reason' => $reason,
            ], $pdo);
            FinanceFaults::hit('pricing.after_audit');
            return new PricingResult(true, $before, $after);
        }, true);
    }

    /**
     * Availability override of a reseller (true/false; null = clear, inherit). Agent: a direct child only,
     * never itself; Admin/SuperAdmin: any reseller (OD-3). Restrict-only: an explicit enable never
     * overrides an ancestor or global disable.
     */
    public static function setChildAvailability(ActorContext $a, mixed $managerId, mixed $planId, ?bool $enabled, mixed $reason = null): PricingResult
    {
        $scope = ResellerTreeService::authorize($a, C::PRICING_CHILD_AVAILABILITY);
        $target = FinanceInput::id($managerId);
        $plan = FinanceInput::id($planId);
        $reason = self::reason($reason);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $scope, $target, $plan, $enabled, $reason): PricingResult {
            ResellerTreeService::revalidateActor($pdo, $a);
            $user = self::target($pdo, $a, $scope, C::PRICING_CHILD_AVAILABILITY, $target, true);
            $p = self::plan($pdo, $plan);
            self::baseRow($pdo, $plan, true) ?? throw new DomainError('PLAN_NOT_RESELLABLE');
            $rows = self::overrideRows($pdo, $plan, true);
            $current = isset($rows[$target]) && $rows[$target]['is_enabled'] !== null ? (int) $rows[$target]['is_enabled'] : null;
            $new = $enabled === null ? null : ($enabled ? 1 : 0);
            $before = self::overrideState($rows[$target] ?? null, 'availability');
            if ($current === $new) {
                return new PricingResult(false, $before, $before);
            }
            self::writeOverride($pdo, $rows[$target] ?? null, $target, $plan, 'availability', $new, (int) $a->userId);
            FinanceFaults::hit('pricing.after_write');
            $after = self::overrideState(self::overrideRows($pdo, $plan, false)[$target] ?? null, 'availability');
            AuditLog::record($new === null ? 'pricing.availability_clear' : 'pricing.availability_set', $a, [
                'target_type' => 'pricing', 'target_user_id' => $target,
                'target_snapshot' => WalletOwner::snapshot($user) + ['plan' => self::planIdentity($p)],
                'before' => $before, 'after' => $after, 'reason' => $reason,
            ], $pdo);
            FinanceFaults::hit('pricing.after_audit');
            return new PricingResult(true, $before, $after);
        }, true);
    }

    // ----------------------------------------------------------------- resolution

    /**
     * Authoritative resolution for an activation (internal API for WP5A and reserveInTx; no capability
     * of its own, like the reservation primitives). Requires the caller's transaction AND this
     * connection's tree lock, so the result is consistent with every committed pricing / hierarchy
     * change. A not-saleable resolution throws its first reason (deterministic order); a saleable one
     * returns the sealed snapshot.
     */
    public static function resolveInTx(\PDO $pdo, int $managerId, int $planId): PricingSnapshot
    {
        FinanceTx::assertTreeLockHeld($pdo);
        ResellerTreeService::requireReady();
        if ($managerId <= 0 || $planId <= 0) {
            throw new DomainError('NOT_FOUND');
        }
        $ev = self::evaluate($pdo, $managerId, $planId, null);
        if ($ev['reasons'] !== []) {
            throw new DomainError($ev['reasons'][0]);
        }
        $chain = array_map(static fn(array $l) => new PricingLink(
            $l['manager_id'], $l['distance'], $l['effective_wholesale'], $l['source'], $l['source_manager_id'],
            $l['set_by'], $l['set_at'], $l['availability'], $l['margin'],
        ), $ev['chain']);
        return PricingSnapshot::seal(
            $planId, $managerId, Tx::utcNow(), $ev['base'], $ev['resale_enabled'], $ev['base_updated_by'], $ev['base_updated_at'],
            $chain, $ev['retail'], $ev['retail_source'], $ev['legacy_raw'], $ev['retail_set_by'], $ev['retail_set_at'], $ev['plan_json'],
        );
    }

    /**
     * Non-authoritative, role-filtered pricing view (pricing.read: Admin any reseller, Agent its own
     * subtree). Never an input to a reservation or settlement.
     *
     * @return array<string,mixed>
     */
    public static function quote(ActorContext $a, mixed $managerId, mixed $planId): array
    {
        $scope = ResellerTreeService::authorize($a, C::PRICING_READ);
        $target = FinanceInput::id($managerId);
        $plan = FinanceInput::id($planId);
        return FinanceTx::run(static function (\PDO $pdo) use ($a, $scope, $target, $plan): array {
            if ($scope !== 'G') {
                [$pred, $params] = DataScope::staffPredicate($a, C::PRICING_READ, 'u.id') ?? ['(1 = 1)', []];
                if (Tx::row($pdo, "SELECT u.id FROM tbl_users u WHERE u.id = ? AND u.user_type = 'Agent' AND {$pred}", array_merge([$target], $params)) === null) {
                    throw new DomainError('NOT_FOUND');
                }
            }
            return PricingReadModel::project($a, $scope, self::evaluate($pdo, $target, $plan, null));
        }, true);
    }

    /**
     * Administrative diagnostics of one plan (Admin/SuperAdmin): every reseller with its effective
     * wholesale / retail and every reason it is not saleable (including wholesale-chain violations
     * created by hierarchy moves, retail below wholesale, an invalid legacy price, zero wholesale),
     * plus the positive-margin commission recipients that have no wallet.
     *
     * @return array<string,mixed>
     */
    public static function validateTree(ActorContext $a, mixed $planId): array
    {
        if (ResellerTreeService::authorize($a, C::PRICING_READ) !== 'G') {
            throw new DomainError('FORBIDDEN');
        }
        $plan = FinanceInput::id($planId);
        return FinanceTx::run(static function (\PDO $pdo) use ($plan): array {
            $state = self::state($pdo, $plan);
            $managers = [];
            $noWallet = [];
            foreach (array_keys(self::agents($pdo)) as $id) {
                $ev = self::evaluate($pdo, $id, $plan, $state);
                $chain = $ev['chain'];
                $leaf = $chain === [] ? null : $chain[count($chain) - 1];
                $managers[] = [
                    'manager_id' => $id,
                    'saleable' => $ev['reasons'] === [],
                    'reasons' => $ev['reasons'],
                    'effective_wholesale' => $leaf !== null ? $leaf['effective_wholesale']->format() : null,
                    'effective_retail' => isset($ev['retail']) ? $ev['retail']->format() : null,
                ];
                foreach ($chain as $i => $l) {
                    if ($i < count($chain) - 1 && $l['margin']->isPositive() && !isset($noWallet[$l['manager_id']])
                        && Tx::row($pdo, 'SELECT manager_id FROM tbl_manager_wallets WHERE manager_id = ?', [$l['manager_id']]) === null) {
                        $noWallet[$l['manager_id']] = true;
                    }
                }
            }
            $ids = array_keys($noWallet);
            sort($ids);
            return [
                'plan_id' => $plan,
                'plan_reasons' => $state['plan_reasons'],
                'managers' => $managers,
                'commission_recipients_without_wallet' => $ids,
            ];
        }, true);
    }

    // ----------------------------------------------------------------- internals

    /**
     * Full evaluation of (manager, plan): reasons in the deterministic saleability order, the chain with
     * provenance and margins, retail and availability. Every reason is collected (first = authoritative).
     *
     * @param array<string,mixed>|null $state
     * @return array<string,mixed>
     */
    private static function evaluate(\PDO $pdo, int $managerId, int $planId, ?array $state): array
    {
        $state ??= self::state($pdo, $planId);
        $ev = [
            'plan_id' => $planId, 'manager_id' => $managerId, 'reasons' => $state['plan_reasons'], 'chain' => [],
            'base' => $state['base_cost'], 'resale_enabled' => $state['resale_enabled'],
            'base_updated_by' => $state['base_updated_by'], 'base_updated_at' => $state['base_updated_at'],
            'available' => false, 'plan_json' => $state['plan_json'], 'legacy_raw' => $state['legacy_raw'],
        ];
        if ($state['plan'] === null || $state['base_cost'] === null || $state['balance']) {
            return $ev;
        }
        $reasons = $state['plan_reasons'];
        try {
            WalletOwner::assertValid($pdo, $managerId);
        } catch (DomainError $e) {
            $reasons[] = $e->reason; // NOT_FOUND, WALLET_OWNER_INVALID or WALLET_OWNER_FROZEN
            if ($e->reason !== 'WALLET_OWNER_FROZEN') {
                $ev['reasons'] = $reasons;
                return $ev;
            }
        }
        $anc = ResellerTreeService::ancestors($pdo, $managerId);
        arsort($anc); // top-level first
        $path = array_keys($anc);
        $rows = $state['rows'];
        $chain = [];
        $e = $state['base_cost'];
        $last = null;
        $disabledBy = null;
        $violation = false;
        foreach ($path as $id) {
            $row = $rows[$id] ?? null;
            $o = $row !== null && $row['wholesale_cost'] !== null ? Money::fromDb($row['wholesale_cost']) : null;
            if ($o !== null) {
                $violation = $violation || $o->compare($e) < 0;
                $e = $o;
                $last = ['id' => $id, 'by' => (int) $row['wholesale_set_by'], 'at' => (string) $row['wholesale_set_at']];
                $link = ['source' => PricingLink::OVERRIDE, 'source_manager_id' => $id, 'set_by' => $last['by'], 'set_at' => $last['at']];
            } elseif ($last !== null) {
                $link = ['source' => PricingLink::INHERITED, 'source_manager_id' => $last['id'], 'set_by' => $last['by'], 'set_at' => $last['at']];
            } else {
                $link = ['source' => PricingLink::BASE, 'source_manager_id' => null, 'set_by' => null, 'set_at' => null];
            }
            $avail = $row !== null && $row['is_enabled'] !== null ? (int) $row['is_enabled'] : null;
            if ($avail === 0 && $disabledBy === null) {
                $disabledBy = $id;
            }
            $chain[] = ['manager_id' => $id, 'distance' => $anc[$id], 'effective_wholesale' => $e, 'availability' => $avail] + $link;
        }
        $k = count($chain);
        foreach ($chain as $i => $l) {
            $chain[$i]['margin'] = $i === $k - 1 ? Money::zero() : $chain[$i + 1]['effective_wholesale']->minus($l['effective_wholesale']);
        }
        $ev['chain'] = $chain;
        $leafCost = $chain[$k - 1]['effective_wholesale'];
        if ($disabledBy !== null) {
            $reasons[] = $disabledBy === $managerId ? 'PLAN_DISABLED_BY_SELF' : 'PLAN_DISABLED_BY_ANCESTOR';
        }
        if ($violation) {
            $reasons[] = 'PRICING_CHAIN_INVALID';
        }
        if ($leafCost->isZero()) {
            $reasons[] = 'ZERO_WHOLESALE_NOT_SUPPORTED';
        }
        $own = $rows[$managerId] ?? null;
        if ($own !== null && $own['retail_price'] !== null) {
            $ev['retail'] = Money::fromDb($own['retail_price']);
            $ev['retail_source'] = PricingSnapshot::RETAIL_OVERRIDE;
            $ev['retail_set_by'] = (int) $own['retail_set_by'];
            $ev['retail_set_at'] = (string) $own['retail_set_at'];
        } else {
            $legacy = LegacyRetailPrice::tryParse($state['legacy_raw']);
            $ev['retail_source'] = PricingSnapshot::RETAIL_LEGACY_DEFAULT;
            $ev['retail_set_by'] = null;
            $ev['retail_set_at'] = null;
            if ($legacy === null) {
                $reasons[] = 'LEGACY_RETAIL_INVALID';
            } else {
                $ev['retail'] = $legacy;
            }
        }
        if (isset($ev['retail']) && $ev['retail']->compare($leafCost) < 0) {
            $reasons[] = 'RETAIL_BELOW_WHOLESALE';
        }
        $ev['available'] = $state['resale_enabled'] && $disabledBy === null;
        $ev['reasons'] = $reasons;
        return $ev;
    }

    /** Plan-level state of one plan (plain reads: callers hold the tree lock). @return array<string,mixed> */
    private static function state(\PDO $pdo, int $planId): array
    {
        $plan = Tx::row($pdo, 'SELECT * FROM tbl_plans WHERE id = ?', [$planId]);
        $base = $plan !== null ? self::baseRow($pdo, $planId, false) : null;
        $reasons = [];
        if ($plan === null) {
            $reasons[] = 'NOT_FOUND';
        } elseif ($plan['type'] === 'Balance') {
            $reasons[] = 'BALANCE_PLAN_NOT_RESELLABLE';
        } elseif ($base === null) {
            $reasons[] = 'PLAN_NOT_RESELLABLE';
        } elseif ((int) $base['is_resale_enabled'] !== 1) {
            $reasons[] = 'PLAN_RESALE_DISABLED';
        }
        return [
            'plan' => $plan,
            'balance' => $plan !== null && $plan['type'] === 'Balance',
            'plan_reasons' => $reasons,
            'plan_json' => $plan !== null ? PricingSnapshot::planJson($plan) : null,
            'legacy_raw' => $plan !== null ? (string) $plan['price'] : null,
            'base_cost' => $base !== null ? Money::fromDb($base['base_cost']) : null,
            'resale_enabled' => $base !== null && (int) $base['is_resale_enabled'] === 1,
            'base_updated_by' => $base !== null ? (int) $base['updated_by'] : null,
            'base_updated_at' => $base !== null ? (string) $base['updated_at'] : null,
            'rows' => $plan !== null ? self::overrideRows($pdo, $planId, false) : [],
        ];
    }

    /** The plan row; unknown -> NOT_FOUND, Balance type -> BALANCE_PLAN_NOT_RESELLABLE (F-4). @return array<string,mixed> */
    private static function plan(\PDO $pdo, int $planId): array
    {
        $p = Tx::row($pdo, 'SELECT * FROM tbl_plans WHERE id = ?', [$planId]) ?? throw new DomainError('NOT_FOUND');
        if ($p['type'] === 'Balance') {
            throw new DomainError('BALANCE_PLAN_NOT_RESELLABLE');
        }
        return $p;
    }

    /** @return array<string,mixed>|null */
    private static function baseRow(\PDO $pdo, int $planId, bool $lock): ?array
    {
        return Tx::row($pdo, 'SELECT * FROM tbl_reseller_plan_base_costs WHERE plan_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$planId]);
    }

    /** @return array<int,array<string,mixed>> manager_id => override row (ascending manager_id) */
    private static function overrideRows(\PDO $pdo, int $planId, bool $lock): array
    {
        $out = [];
        foreach (Tx::rows($pdo, 'SELECT * FROM tbl_manager_plan_prices WHERE plan_id = ? ORDER BY manager_id' . ($lock ? ' FOR UPDATE' : ''), [$planId]) as $r) {
            $out[(int) $r['manager_id']] = $r;
        }
        return $out;
    }

    /**
     * Every enrolled reseller Agent with its direct parent Agent, ordered top-down (closure depth).
     *
     * @return array<int,array{parent:?int,level:int}>
     */
    private static function agents(\PDO $pdo): array
    {
        $levels = Tx::rows(
            $pdo,
            "SELECT t.descendant_id AS id, MAX(t.depth) AS lvl FROM tbl_manager_tree t JOIN tbl_users u ON u.id = t.descendant_id "
            . "WHERE u.user_type = 'Agent' GROUP BY t.descendant_id ORDER BY lvl, t.descendant_id"
        );
        $parents = [];
        foreach (Tx::rows($pdo, "SELECT t.descendant_id AS id, t.ancestor_id AS parent FROM tbl_manager_tree t JOIN tbl_users u ON u.id = t.ancestor_id AND u.user_type = 'Agent' WHERE t.depth = 1") as $r) {
            $parents[(int) $r['id']] = (int) $r['parent'];
        }
        $out = [];
        foreach ($levels as $r) {
            $id = (int) $r['id'];
            $out[$id] = ['parent' => $parents[$id] ?? null, 'level' => (int) $r['lvl']];
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,Money> */
    private static function wholesaleOverrides(array $rows): array
    {
        $out = [];
        foreach ($rows as $id => $r) {
            if ($r['wholesale_cost'] !== null) {
                $out[$id] = Money::fromDb($r['wholesale_cost']);
            }
        }
        return $out;
    }

    /**
     * Effective wholesale of every reseller (top-down) and whether its explicit override is below its
     * floor (parent's effective cost, or the base cost at the top level).
     *
     * @param array<int,array{parent:?int,level:int}> $agents
     * @param array<int,Money> $wholesale
     * @return array<int,array{e:Money,violation:bool}>
     */
    private static function effective(array $agents, Money $base, array $wholesale): array
    {
        $out = [];
        foreach ($agents as $id => $a) {
            $floor = $a['parent'] !== null && isset($out[$a['parent']]) ? $out[$a['parent']]['e'] : $base;
            if (isset($wholesale[$id])) {
                $out[$id] = ['e' => $wholesale[$id], 'violation' => $wholesale[$id]->compare($floor) < 0];
            } else {
                $out[$id] = ['e' => $floor, 'violation' => false];
            }
        }
        return $out;
    }

    /** @return array<int,true> resellers whose explicit wholesale override is below its floor */
    private static function violations(array $agents, Money $base, array $wholesale): array
    {
        $out = [];
        foreach (self::effective($agents, $base, $wholesale) as $id => $e) {
            if ($e['violation']) {
                $out[$id] = true;
            }
        }
        return $out;
    }

    /**
     * Resellers whose effective retail was >= their wholesale before and is below it after (F-5).
     *
     * @return array<string,list<int>>
     */
    private static function newlyUnsaleable(array $agents, array $plan, array $rows, array $before, array $after): array
    {
        $legacy = LegacyRetailPrice::tryParse($plan['price']);
        $ids = [];
        foreach (array_keys($agents) as $id) {
            $retail = isset($rows[$id]) && $rows[$id]['retail_price'] !== null ? Money::fromDb($rows[$id]['retail_price']) : $legacy;
            if ($retail === null || !isset($before[$id], $after[$id])) {
                continue;
            }
            if ($retail->compare($before[$id]['e']) >= 0 && $retail->compare($after[$id]['e']) < 0) {
                $ids[] = $id;
            }
        }
        return $ids === [] ? [] : ['RETAIL_BELOW_WHOLESALE' => $ids];
    }

    /**
     * Target reseller of a manager-scoped mutation. Agent (scope C): a depth-1 child, never itself
     * (out of scope = NOT_FOUND); Agent (scope S): itself only; Admin/SuperAdmin (G): any reseller.
     * The target must be an enrolled, consistent reseller Agent; an inactive/frozen target is refused
     * for Agents and allowed for Admin/SuperAdmin (configuration correction; resolution stays closed).
     *
     * @return array<string,mixed> tbl_users row
     */
    private static function target(\PDO $pdo, ActorContext $a, string $scope, string $capability, int $id, bool $selfForbidden): array
    {
        if ($scope !== 'G') {
            if ($id === (int) $a->userId) {
                if ($selfForbidden) {
                    throw new DomainError('SELF_PRICING_FORBIDDEN');
                }
            } elseif ($scope === 'S') {
                throw new DomainError('NOT_FOUND');
            } else {
                [$pred, $params] = DataScope::staffPredicate($a, $capability, 'u.id') ?? ['(1 = 1)', []];
                if (Tx::row($pdo, "SELECT u.id FROM tbl_users u WHERE u.id = ? AND {$pred}", array_merge([$id], $params)) === null) {
                    throw new DomainError('NOT_FOUND');
                }
            }
        }
        $user = Tx::row($pdo, 'SELECT id, username, user_type, status, root FROM tbl_users WHERE id = ?', [$id]) ?? throw new DomainError('NOT_FOUND');
        try {
            WalletOwner::assertValid($pdo, $id);
        } catch (DomainError $e) {
            if ($e->reason === 'WALLET_OWNER_INVALID') {
                throw new DomainError('PRICING_TARGET_INVALID');
            }
            if ($e->reason !== 'WALLET_OWNER_FROZEN' || $scope !== 'G') {
                throw new DomainError($e->reason === 'WALLET_OWNER_FROZEN' ? 'PRICING_TARGET_FROZEN' : $e->reason);
            }
        }
        return $user;
    }

    /**
     * Sets or clears one override of a (manager, plan) row. A row whose last override becomes NULL is
     * deleted (WP1 binding input: an all-NULL row behaves exactly like no row), so plan deletion is never
     * blocked by an empty configuration row.
     *
     * @param array<string,mixed>|null $row
     */
    private static function writeOverride(\PDO $pdo, ?array $row, int $managerId, int $planId, string $kind, string|int|null $value, int $actorId): void
    {
        [$col, $by, $at] = self::OVERRIDE_COLUMNS[$kind];
        $now = Tx::utcNow();
        if ($row === null) {
            Tx::exec(
                $pdo,
                "INSERT INTO tbl_manager_plan_prices (manager_id, plan_id, {$col}, {$by}, {$at}, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$managerId, $planId, $value, $actorId, $now, $now, $now]
            );
            return;
        }
        Tx::exec(
            $pdo,
            "UPDATE tbl_manager_plan_prices SET {$col} = ?, {$by} = ?, {$at} = ?, updated_at = ? WHERE id = ?",
            [$value, $value === null ? null : $actorId, $value === null ? null : $now, $now, (int) $row['id']]
        );
        if ($value === null) {
            Tx::exec(
                $pdo,
                'DELETE FROM tbl_manager_plan_prices WHERE id = ? AND wholesale_cost IS NULL AND retail_price IS NULL AND is_enabled IS NULL',
                [(int) $row['id']]
            );
        }
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed> */
    private static function overrideState(?array $row, string $kind): array
    {
        [$col, $by, $at] = self::OVERRIDE_COLUMNS[$kind];
        $v = $row[$col] ?? null;
        $value = $v === null ? null : ($kind === 'availability' ? (int) $v === 1 : Money::fromDb($v)->format());
        return [$kind => $value, 'set_by' => isset($row[$by]) ? (int) $row[$by] : null, 'set_at' => $row[$at] ?? null];
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function planIdentity(array $p): array
    {
        return ['plan_id' => (int) $p['id'], 'name_plan' => (string) $p['name_plan'], 'type' => (string) $p['type']];
    }

    /** @param array<string,list<int>> $warnings @return array<string,mixed> */
    private static function warningAudit(array $warnings): array
    {
        if ($warnings === []) {
            return [];
        }
        $out = [];
        foreach ($warnings as $reason => $ids) {
            $out[] = ['reason' => $reason, 'count' => count($ids), 'manager_ids' => array_slice($ids, 0, self::WARNING_IDS)];
        }
        return ['unsaleable_descendants' => $out];
    }

    private static function reason(mixed $reason): ?string
    {
        return $reason === null ? null : FinanceInput::reason($reason);
    }
}
