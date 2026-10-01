<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * One reseller of a resolved wholesale chain (top-level -> leaf), immutable.
 *
 *   distance            0 for the leaf (the selling reseller), 1 for its parent, ...
 *   effectiveWholesale  the reseller's effective wholesale cost c_i
 *   source              OVERRIDE (own explicit override), INHERITED (nearest ancestor override,
 *                       sourceManagerId) or BASE (provider base cost)
 *   setBy / setAt       provenance of the override that applies (null for BASE)
 *   availability        the reseller's own availability override (null = inherit, 0, 1)
 *   margin              c_{i+1} - c_i earned by this intermediary (0.00 for the leaf)
 */
final class PricingLink
{
    public const OVERRIDE = 'OVERRIDE';
    public const INHERITED = 'INHERITED';
    public const BASE = 'BASE';

    public function __construct(
        public readonly int $managerId,
        public readonly int $distance,
        public readonly Money $effectiveWholesale,
        public readonly string $source,
        public readonly ?int $sourceManagerId,
        public readonly ?int $setBy,
        public readonly ?string $setAt,
        public readonly ?int $availability,
        public readonly Money $margin,
    ) {
    }

    /** @return array<string,mixed> canonical JSON shape (fixed key order) */
    public function toArray(): array
    {
        return [
            'manager_id' => $this->managerId,
            'distance' => $this->distance,
            'effective_wholesale' => $this->effectiveWholesale->format(),
            'source' => $this->source,
            'source_manager_id' => $this->sourceManagerId,
            'set_by' => $this->setBy,
            'set_at' => $this->setAt,
            'availability' => $this->availability,
            'margin' => $this->margin->format(),
        ];
    }
}
