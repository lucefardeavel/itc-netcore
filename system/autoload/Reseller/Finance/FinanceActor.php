<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Security\ActorContext;

/**
 * Ledger actor identity, constructed explicitly (owner decision): never inferred from PHP_SAPI or the
 * request channel. CLI and API are transports, not identities — an authenticated staff or customer
 * principal stays STAFF / CUSTOMER whatever the channel. WP3A never emits actor_type API.
 */
final class FinanceActor
{
    public const STAFF = 'STAFF';
    public const CUSTOMER = 'CUSTOMER';
    public const SYSTEM = 'SYSTEM';
    public const CRON = 'CRON';

    private const LABEL = '/^[a-z][a-z0-9_.-]{1,63}$/D';

    private function __construct(
        public readonly string $type,
        public readonly ?int $operatorId,
        public readonly ?int $customerId,
        public readonly ?string $label,
        public readonly ?ActorContext $context,
    ) {
    }

    /** An authenticated, server-resolved staff principal (any channel). */
    public static function staff(ActorContext $a): self
    {
        if (!$a->isStaff() || $a->userId === null || $a->userId <= 0) {
            throw new DomainError('INVALID_ACTOR');
        }
        return new self(self::STAFF, $a->userId, null, null, $a);
    }

    /** An authenticated customer principal (any channel); may differ from an activation's target customer. */
    public static function customer(ActorContext $a): self
    {
        if (!$a->isCustomer() || $a->customerId === null || $a->customerId <= 0) {
            throw new DomainError('INVALID_ACTOR');
        }
        return new self(self::CUSTOMER, null, $a->customerId, null, $a);
    }

    /** An explicitly named internal system component. */
    public static function system(string $component): self
    {
        if (!preg_match(self::LABEL, $component)) {
            throw new DomainError('INVALID_ACTOR');
        }
        return new self(self::SYSTEM, null, null, $component, null);
    }

    /** An explicitly named scheduled job. */
    public static function cron(string $job): self
    {
        if (!preg_match(self::LABEL, $job)) {
            throw new DomainError('INVALID_ACTOR');
        }
        return new self(self::CRON, null, null, $job, null);
    }

    public function isStaff(): bool
    {
        return $this->type === self::STAFF;
    }
}
