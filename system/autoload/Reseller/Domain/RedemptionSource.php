<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ActorContext;

/**
 * Who redeems a voucher (WP5B §6.1). STAFF and CUSTOMER carry their real, server-resolved ActorContext (audited
 * with it); PUBLIC (login page) and RADIUS (rlm_rest authorize) have no principal: none is fabricated, the
 * redemption is logged (RADIUS with the client address).
 */
final class RedemptionSource
{
    public const STAFF = 'STAFF';
    public const CUSTOMER = 'CUSTOMER';
    public const PUBLIC = 'PUBLIC';
    public const RADIUS = 'RADIUS';

    private function __construct(
        public readonly string $kind,
        public readonly ?ActorContext $context,
        public readonly ?string $client,
    ) {
    }

    public static function staff(ActorContext $a): self
    {
        if (!$a->isStaff()) {
            throw new \LogicException('staff redemption without a staff principal');
        }
        return new self(self::STAFF, $a, null);
    }

    public static function customer(ActorContext $a): self
    {
        if (!$a->isCustomer()) {
            throw new \LogicException('customer redemption without a customer principal');
        }
        return new self(self::CUSTOMER, $a, null);
    }

    public static function public(): self
    {
        return new self(self::PUBLIC, null, null);
    }

    public static function radius(string $client): self
    {
        return new self(self::RADIUS, null, substr($client, 0, 45));
    }
}
