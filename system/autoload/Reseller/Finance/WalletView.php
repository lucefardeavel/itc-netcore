<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Read model of a reseller wallet. An absent wallet is synthesized as zero (exists = false) without
 * any write. available = balance + debt_limit - SUM(ACTIVE reservations) (calculation range).
 */
final class WalletView implements \JsonSerializable
{
    public function __construct(
        public readonly int $managerId,
        public readonly bool $exists,
        public readonly Money $balance,
        public readonly Money $debtLimit,
        public readonly Money $reserved,
        public readonly ?string $updatedAt,
    ) {
    }

    public function available(): Money
    {
        return $this->balance->plus($this->debtLimit)->minus($this->reserved);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'manager_id' => $this->managerId,
            'exists' => $this->exists,
            'balance' => $this->balance->format(),
            'debt_limit' => $this->debtLimit->format(),
            'reserved' => $this->reserved->format(),
            'available' => $this->available()->format(),
            'updated_at' => $this->updatedAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
