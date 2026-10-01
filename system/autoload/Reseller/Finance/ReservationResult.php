<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/** State of a wallet reservation after reserve / commit / release (replayed = idempotent outcome). */
final class ReservationResult implements \JsonSerializable
{
    public function __construct(
        public readonly int $reservationId,
        public readonly int $activationId,
        public readonly int $managerId,
        public readonly Money $amount,
        public readonly string $status,
        public readonly ?int $committedLedgerEntryId,
        public readonly bool $replayed,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'reservation_id' => $this->reservationId, 'activation_id' => $this->activationId, 'manager_id' => $this->managerId,
            'amount' => $this->amount->format(), 'status' => $this->status,
            'committed_ledger_entry_id' => $this->committedLedgerEntryId, 'replayed' => $this->replayed,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
