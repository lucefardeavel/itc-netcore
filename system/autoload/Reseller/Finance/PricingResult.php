<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Outcome of one pricing configuration mutation. changed = false is an idempotent no-op (identical
 * value: nothing written, nothing audited). warnings lists, per reason, the resellers that became
 * not saleable because of this upstream change (e.g. RETAIL_BELOW_WHOLESALE, owner decision F-5).
 */
final class PricingResult implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,list<int>> $warnings reason => manager ids
     */
    public function __construct(
        public readonly bool $changed,
        public readonly array $before,
        public readonly array $after,
        public readonly array $warnings = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['changed' => $this->changed, 'before' => $this->before, 'after' => $this->after, 'warnings' => $this->warnings];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
