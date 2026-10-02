<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Finance\FinanceActor;
use Reseller\Security\ActorContext;

/**
 * Who drives an activation step: a staff member (web) or the system (the reconciliation CLI). Recorded on
 * every event (R0003 actor columns) and mapped onto the finance actor of the settlement.
 */
final class ActivationActor
{
    private function __construct(
        public readonly string $type,
        public readonly ?int $userId,
        public readonly ?ActorContext $context,
        public readonly string $label,
    ) {
    }

    public static function staff(ActorContext $a): self
    {
        return new self('STAFF', (int) $a->userId, $a, 'staff');
    }

    /** The system (CLI reconciliation, internal continuation); label as in FinanceActor::system(). */
    public static function system(string $label = 'wp5a-activation'): self
    {
        return new self('SYSTEM', null, null, $label);
    }

    public function finance(): FinanceActor
    {
        return $this->context !== null ? FinanceActor::staff($this->context) : FinanceActor::system($this->label);
    }
}
