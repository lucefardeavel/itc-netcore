<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;

/**
 * The canonical, immutable, INTERNAL pricing snapshot of one saleable (manager, plan) resolution
 * (WP3B, D024/D025). It is complete enough for settlement, conservation proof, audit, reconciliation
 * and historical replay: provider base cost, the complete effective wholesale chain (top-level ->
 * leaf) with provenance, every intermediary margin including zero margins, the provider/system
 * settlement, the retail resolution and the availability resolution. Role visibility is a separate
 * concern (PricingReadModel); nothing is removed here for presentation.
 *
 * Settlement math (Money, integer minor units, no float):
 *   L  = c_k               leaf wholesale debit (always > 0 in a sealed snapshot)
 *   P  = c_1               provider/system settlement (not a wallet credit)
 *   m_i = c_{i+1} - c_i    margin of intermediary i (>= 0); only positive margins are commissions
 *   P + sum(m_i) = L       exact conservation
 *
 * A snapshot is built only by PricingService::resolveInTx (seal) or decoded from an activation row
 * (fromActivationRow). Every construction re-verifies all invariants; decoding additionally requires
 * the stored JSON to be byte-identical to the canonical encoding and cross-checks the activation's
 * wholesale_cost / retail_price / provider_settlement_amount / plan_snapshot columns.
 */
final class PricingSnapshot
{
    public const VERSION = 1;
    public const RETAIL_OVERRIDE = 'OVERRIDE';
    public const RETAIL_LEGACY_DEFAULT = 'LEGACY_DEFAULT';
    /** tbl_plans columns copied into the plan snapshot (scripts on_login / on_logout and price_old excluded) */
    public const PLAN_COLUMNS = [
        'id', 'name_plan', 'id_bw', 'type', 'typebp', 'limit_type', 'time_limit', 'time_unit', 'data_limit', 'data_unit',
        'validity', 'validity_unit', 'shared_users', 'routers', 'is_radius', 'pool', 'plan_expired', 'expired_date',
        'enabled', 'prepaid', 'plan_type', 'device', 'price',
    ];
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
    private const MONEY = '/^(0|[1-9][0-9]{0,15})\.[0-9]{2}$/D';
    private const STAMP = '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?$/D';

    public readonly Money $leafDebit;
    public readonly Money $providerSettlement;
    public readonly Money $providerMarginOverBase;
    /** @var array<int,Money> manager_id => positive commission, leaf -> top order */
    private readonly array $commissions;

    /** @param list<PricingLink> $chain top-level -> leaf */
    private function __construct(
        public readonly int $planId,
        public readonly int $leafManagerId,
        public readonly string $resolvedAt,
        public readonly Money $baseCost,
        public readonly bool $resaleEnabled,
        public readonly ?int $baseUpdatedBy,
        public readonly ?string $baseUpdatedAt,
        public readonly array $chain,
        public readonly Money $retail,
        public readonly string $retailSource,
        public readonly string $legacyRaw,
        public readonly ?int $retailSetBy,
        public readonly ?string $retailSetAt,
        public readonly string $planJson,
    ) {
        $k = count($chain);
        if ($k === 0) {
            self::invalid('empty chain');
        }
        $this->leafDebit = $chain[$k - 1]->effectiveWholesale;
        $this->providerSettlement = $chain[0]->effectiveWholesale;
        $this->providerMarginOverBase = $this->providerSettlement->minus($baseCost);
        $commissions = [];
        for ($i = $k - 2; $i >= 0; $i--) {
            if ($chain[$i]->margin->isPositive()) {
                $commissions[$chain[$i]->managerId] = $chain[$i]->margin;
            }
        }
        $this->commissions = $commissions;
        $this->verify();
    }

    /** @internal Built by PricingService::resolveInTx from authoritative locked state only. @param list<PricingLink> $chain */
    public static function seal(
        int $planId, int $leafManagerId, string $resolvedAt, Money $baseCost, bool $resaleEnabled, ?int $baseUpdatedBy, ?string $baseUpdatedAt,
        array $chain, Money $retail, string $retailSource, string $legacyRaw, ?int $retailSetBy, ?string $retailSetAt, string $planJson,
    ): self {
        return new self($planId, $leafManagerId, $resolvedAt, $baseCost, $resaleEnabled, $baseUpdatedBy, $baseUpdatedAt,
            array_values($chain), $retail, $retailSource, $legacyRaw, $retailSetBy, $retailSetAt, $planJson);
    }

    /** Canonical plan snapshot JSON of a tbl_plans row (raw column text, fixed key order). @param array<string,mixed> $plan */
    public static function planJson(array $plan): string
    {
        $out = [];
        foreach (self::PLAN_COLUMNS as $c) {
            if (array_key_exists($c, $plan)) {
                $v = $plan[$c];
                $out[$c] = $v === null ? null : (string) $v;
            }
        }
        return json_encode($out, self::JSON_FLAGS);
    }

    /**
     * Decodes and re-verifies the sealed snapshot stored on an activation row. Any missing column,
     * non-canonical JSON, inconsistent column or broken invariant is PRICING_SNAPSHOT_INVALID.
     *
     * @param array<string,mixed> $row tbl_reseller_activations row (manager_id, plan_id and the five snapshot columns)
     */
    public static function fromActivationRow(array $row): self
    {
        foreach (['manager_id', 'plan_id', 'wholesale_cost', 'retail_price', 'provider_settlement_amount', 'plan_snapshot', 'pricing_chain_snapshot'] as $c) {
            if (!array_key_exists($c, $row) || $row[$c] === null) {
                self::invalid("column {$c} missing");
            }
        }
        $chainJson = (string) $row['pricing_chain_snapshot'];
        $planJson = (string) $row['plan_snapshot'];
        try {
            $d = json_decode($chainJson, true, 16, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            $plan = json_decode($planJson, true, 4, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid('not JSON');
        }
        if (!is_array($d) || !is_array($plan)) {
            self::invalid('not an object');
        }
        self::keys($d, ['v', 'plan_id', 'leaf_manager_id', 'resolved_at', 'provider', 'chain', 'retail', 'availability', 'settlement']);
        if ($d['v'] !== self::VERSION) {
            self::invalid('unknown version');
        }
        self::keys($d['provider'], ['base_cost', 'resale_enabled', 'updated_by', 'updated_at']);
        self::keys($d['retail'], ['effective', 'source', 'legacy_raw', 'set_by', 'set_at']);
        if (!is_array($d['chain']) || !array_is_list($d['chain'])) {
            self::invalid('chain');
        }
        $chain = [];
        foreach ($d['chain'] as $l) {
            self::keys($l, ['manager_id', 'distance', 'effective_wholesale', 'source', 'source_manager_id', 'set_by', 'set_at', 'availability', 'margin']);
            if (!in_array($l['source'], [PricingLink::OVERRIDE, PricingLink::INHERITED, PricingLink::BASE], true)
                || !($l['availability'] === null || $l['availability'] === 0 || $l['availability'] === 1)) {
                self::invalid('chain link');
            }
            $chain[] = new PricingLink(
                self::int($l['manager_id']), self::count($l['distance']), self::money($l['effective_wholesale']), $l['source'],
                self::intOrNull($l['source_manager_id']), self::intOrNull($l['set_by']), self::stampOrNull($l['set_at']),
                $l['availability'], self::money($l['margin']),
            );
        }
        if (!is_string($d['retail']['source']) || !is_string($d['retail']['legacy_raw']) || !is_bool($d['provider']['resale_enabled'])) {
            self::invalid('retail / provider');
        }
        $s = new self(
            self::int($d['plan_id']), self::int($d['leaf_manager_id']), self::stamp($d['resolved_at']),
            self::money($d['provider']['base_cost']), $d['provider']['resale_enabled'],
            self::intOrNull($d['provider']['updated_by']), self::stampOrNull($d['provider']['updated_at']),
            $chain, self::money($d['retail']['effective']), $d['retail']['source'], $d['retail']['legacy_raw'],
            self::intOrNull($d['retail']['set_by']), self::stampOrNull($d['retail']['set_at']), $planJson,
        );
        // byte-identical canonical form: no foreign keys, reordering, number formats or whitespace
        if ($s->chainJson() !== $chainJson) {
            self::invalid('non-canonical pricing_chain_snapshot');
        }
        if ((int) $row['manager_id'] !== $s->leafManagerId || (int) $row['plan_id'] !== $s->planId) {
            self::invalid('activation manager/plan mismatch');
        }
        foreach ([['wholesale_cost', $s->leafDebit], ['retail_price', $s->retail], ['provider_settlement_amount', $s->providerSettlement]] as [$c, $m]) {
            try {
                $col = Money::fromDb($row[$c]);
            } catch (\UnexpectedValueException) {
                self::invalid("column {$c}");
            }
            if (!$col->equals($m)) {
                self::invalid("column {$c} differs from the snapshot");
            }
        }
        return $s;
    }

    /** The five activation columns WP5A writes in its single activation INSERT (write-once). @return array<string,string> */
    public function activationColumns(): array
    {
        return [
            'wholesale_cost' => $this->leafDebit->toStorage(),
            'retail_price' => $this->retail->toStorage(),
            'provider_settlement_amount' => $this->providerSettlement->toStorage(),
            'plan_snapshot' => $this->planJson,
            'pricing_chain_snapshot' => $this->chainJson(),
        ];
    }

    /** @return array<int,Money> positive intermediary commissions, manager_id => amount (leaf -> top) */
    public function commissionRecipients(): array
    {
        return $this->commissions;
    }

    /** Same prices, chain, provenance, availability and plan as $other; only resolved_at may differ. */
    public function sameTermsAs(self $other): bool
    {
        $a = $this->toArray();
        $b = $other->toArray();
        unset($a['resolved_at'], $b['resolved_at']);
        return $a === $b && $this->planJson === $other->planJson;
    }

    /** Canonical pricing_chain_snapshot JSON. */
    public function chainJson(): string
    {
        return json_encode($this->toArray(), self::JSON_FLAGS);
    }

    /** @return array<string,mixed> canonical structure (fixed key order) */
    public function toArray(): array
    {
        $explicit = false;
        foreach ($this->chain as $l) {
            $explicit = $explicit || $l->availability === 1;
        }
        $commissions = [];
        foreach ($this->commissions as $m => $amount) {
            $commissions[] = ['manager_id' => $m, 'amount' => $amount->format()];
        }
        return [
            'v' => self::VERSION,
            'plan_id' => $this->planId,
            'leaf_manager_id' => $this->leafManagerId,
            'resolved_at' => $this->resolvedAt,
            'provider' => [
                'base_cost' => $this->baseCost->format(), 'resale_enabled' => $this->resaleEnabled,
                'updated_by' => $this->baseUpdatedBy, 'updated_at' => $this->baseUpdatedAt,
            ],
            'chain' => array_map(static fn(PricingLink $l) => $l->toArray(), $this->chain),
            'retail' => [
                'effective' => $this->retail->format(), 'source' => $this->retailSource, 'legacy_raw' => $this->legacyRaw,
                'set_by' => $this->retailSetBy, 'set_at' => $this->retailSetAt,
            ],
            'availability' => ['effective' => true, 'decided_by' => $explicit ? 'EXPLICIT' : 'DEFAULT'],
            'settlement' => [
                'leaf_debit' => $this->leafDebit->format(),
                'commissions' => $commissions,
                'provider_settlement' => $this->providerSettlement->format(),
                'provider_margin_over_base' => $this->providerMarginOverBase->format(),
            ],
        ];
    }

    /** Every invariant of a sealed, saleable snapshot. */
    private function verify(): void
    {
        $k = count($this->chain);
        $seen = [];
        $prev = $this->baseCost;
        $lastOverride = null;
        foreach ($this->chain as $i => $l) {
            if (isset($seen[$l->managerId]) || $l->managerId <= 0 || $l->distance !== $k - 1 - $i) {
                self::invalid('chain order');
            }
            $seen[$l->managerId] = true;
            $c = $l->effectiveWholesale;
            if (!$c->isStorable() || $c->isNegative() || $c->compare($prev) < 0) {
                self::invalid('chain is not monotone from the base cost');
            }
            if ($l->availability === 0) {
                self::invalid('disabled reseller in a saleable chain');
            }
            switch ($l->source) {
                case PricingLink::OVERRIDE:
                    if ($l->sourceManagerId !== $l->managerId || $l->setBy === null || $l->setAt === null) {
                        self::invalid('override provenance');
                    }
                    $lastOverride = [$l->managerId, $c];
                    break;
                case PricingLink::INHERITED:
                    if ($lastOverride === null || $l->sourceManagerId !== $lastOverride[0] || !$c->equals($lastOverride[1])
                        || $l->setBy === null || $l->setAt === null) {
                        self::invalid('inherited provenance');
                    }
                    break;
                case PricingLink::BASE:
                    if ($lastOverride !== null || !$c->equals($this->baseCost) || $l->sourceManagerId !== null || $l->setBy !== null || $l->setAt !== null) {
                        self::invalid('base provenance');
                    }
                    break;
                default:
                    self::invalid('source');
            }
            $expectedMargin = $i === $k - 1 ? Money::zero() : $this->chain[$i + 1]->effectiveWholesale->minus($c);
            if (!$l->margin->equals($expectedMargin)) {
                self::invalid('margin');
            }
            $prev = $c;
        }
        if ($this->chain[$k - 1]->managerId !== $this->leafManagerId || $this->planId <= 0) {
            self::invalid('leaf');
        }
        if (!$this->baseCost->isStorable() || $this->baseCost->isNegative() || !$this->resaleEnabled) {
            self::invalid('provider');
        }
        if (!$this->leafDebit->isPositive()) {
            self::invalid('zero leaf wholesale');
        }
        $sum = $this->providerSettlement;
        foreach ($this->commissions as $amount) {
            $sum = $sum->plus($amount);
        }
        if (!$sum->equals($this->leafDebit)) {
            self::invalid('conservation');
        }
        if (!$this->retail->isStorable() || $this->retail->compare($this->leafDebit) < 0) {
            self::invalid('retail below wholesale');
        }
        if ($this->retailSource === self::RETAIL_OVERRIDE) {
            if ($this->retailSetBy === null || $this->retailSetAt === null) {
                self::invalid('retail provenance');
            }
        } elseif ($this->retailSource === self::RETAIL_LEGACY_DEFAULT) {
            $legacy = LegacyRetailPrice::tryParse($this->legacyRaw);
            if ($this->retailSetBy !== null || $this->retailSetAt !== null || $legacy === null || !$legacy->equals($this->retail)) {
                self::invalid('legacy retail');
            }
        } else {
            self::invalid('retail source');
        }
        $plan = json_decode($this->planJson, true, 4, JSON_BIGINT_AS_STRING);
        if (!is_array($plan) || ($plan['id'] ?? null) !== (string) $this->planId || ($plan['price'] ?? null) !== $this->legacyRaw
            || ($plan['type'] ?? null) === 'Balance' || json_encode($plan, self::JSON_FLAGS) !== $this->planJson) {
            self::invalid('plan snapshot');
        }
        foreach ($plan as $c => $v) {
            if (!in_array($c, self::PLAN_COLUMNS, true) || !($v === null || is_string($v))) {
                self::invalid('plan snapshot column');
            }
        }
    }

    /** @param mixed $a */
    private static function keys(mixed $a, array $expected): void
    {
        if (!is_array($a) || array_keys($a) !== $expected) {
            self::invalid('unexpected keys');
        }
    }

    private static function money(mixed $v): Money
    {
        if (!is_string($v) || !preg_match(self::MONEY, $v)) {
            self::invalid('money');
        }
        return Money::fromDb($v);
    }

    private static function int(mixed $v): int
    {
        if (!is_int($v) || $v <= 0) {
            self::invalid('id');
        }
        return $v;
    }

    private static function count(mixed $v): int
    {
        if (!is_int($v) || $v < 0) {
            self::invalid('distance');
        }
        return $v;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : self::int($v);
    }

    private static function stamp(mixed $v): string
    {
        if (!is_string($v) || !preg_match(self::STAMP, $v)) {
            self::invalid('timestamp');
        }
        return $v;
    }

    private static function stampOrNull(mixed $v): ?string
    {
        return $v === null ? null : self::stamp($v);
    }

    private static function invalid(string $detail): never
    {
        throw new DomainError('PRICING_SNAPSHOT_INVALID', DomainError::MESSAGES['PRICING_SNAPSHOT_INVALID'] . ' (' . $detail . ')');
    }
}
