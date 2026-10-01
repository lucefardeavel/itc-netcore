<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/** Outcome of a funding / withdrawal / adjustment / transfer / reversal command (replayed = idempotent). */
final class TransferResult implements \JsonSerializable
{
    /** @param list<int> $ledgerEntryIds */
    public function __construct(
        public readonly int $transferId,
        public readonly string $reference,
        public readonly string $operationType,
        public readonly string $status,
        public readonly Money $amount,
        public readonly ?int $fromManagerId,
        public readonly ?int $toManagerId,
        public readonly ?int $reversesTransferId,
        public readonly array $ledgerEntryIds,
        public readonly bool $replayed,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'transfer_id' => $this->transferId, 'reference' => $this->reference, 'operation_type' => $this->operationType,
            'status' => $this->status, 'amount' => $this->amount->format(), 'from_manager_id' => $this->fromManagerId,
            'to_manager_id' => $this->toManagerId, 'reverses_transfer_id' => $this->reversesTransferId,
            'ledger_entry_ids' => $this->ledgerEntryIds, 'replayed' => $this->replayed,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
