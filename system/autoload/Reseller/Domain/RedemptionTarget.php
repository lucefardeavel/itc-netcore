<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/** The subscriber a voucher is redeemed for: an existing customer (id > 0) or a voucher-only subscriber (id 0, username = the code). */
final class RedemptionTarget
{
    public function __construct(
        public readonly int $customerId,
        public readonly string $username,
    ) {
    }

    /** @param array<string,mixed>|object $customer */
    public static function customer(mixed $customer): self
    {
        return new self((int) $customer['id'], (string) $customer['username']);
    }

    public static function voucherOnly(string $code): self
    {
        return new self(0, $code);
    }
}
