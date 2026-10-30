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
        public readonly ?string $operator = null,
        public readonly ?string $reason = null,
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

    /**
     * A named operator at the reconciliation CLI (final QA F-1): still the system actor for the finance and event
     * records (no staff session), but every decision it takes is audited with the operator name and the reason.
     */
    public static function operator(string $operator, string $reason, string $label = 'wp5a-reconcile'): self
    {
        if (strlen(trim($operator)) < 2 || strlen(trim($reason)) < 10) {
            throw new \Reseller\Domain\DomainError('INVALID_INPUT', 'operator (>= 2) and reason (>= 10 characters) are required');
        }
        return new self('SYSTEM', null, ActorContext::system('CLI'), $label, mb_substr(trim($operator), 0, 64), mb_substr(trim($reason), 0, 255));
    }

    public function finance(): FinanceActor
    {
        return $this->type === 'STAFF' ? FinanceActor::staff($this->context) : FinanceActor::system($this->label);
    }
}
