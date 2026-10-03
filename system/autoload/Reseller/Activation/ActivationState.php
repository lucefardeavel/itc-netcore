<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;

/**
 * The activation state machine (D016; owner decision A3; WP5B OD-14 / R0007). R0003 + R0007 store the 17 states but do not
 * enforce transitions: this table is the only authority, and every state change goes through
 * ActivationStore::transition(), which appends the matching event.
 *
 * Nominal: CREATED -> VALIDATED -> FUNDS_RESERVED -> PROVISIONING -> PROVISIONED -> VERIFYING -> VERIFIED
 *          -> FINANCIAL_COMMITTING -> COMPLETED
 * Exceptional:
 *  - FUNDS_RESERVED / PROVISIONING -> RETRY_PENDING -> PROVISIONING   (a confirmed, retryable failure)
 *  - PROVISIONING / RETRY_PENDING -> FAILED                           (confirmed failure; reservation released)
 *  - PROVISIONING / PROVISIONED / VERIFYING / FINANCIAL_COMMITTING -> RECONCILIATION_REQUIRED (UNKNOWN,
 *    verify mismatch, settlement failure; the reservation stays ACTIVE: never free service)
 *  - RECONCILIATION_REQUIRED -> VERIFIED (read-back proves the policy applied) | FAILED (proves it did not)
 *  - RECONCILIATION_REQUIRED / VERIFIED -> ROLLBACK_PENDING -> ROLLED_BACK (Admin: previous policy restored)
 *  - FUNDS_RESERVED / RETRY_PENDING -> CANCELLED (nothing provisioned; reservation released)
 *  - COMPLETED -> REVERSAL_PENDING -> REVERSED (WP5B, R0007: Admin reversal of a settled activation; a dedicated
 *    flow, distinct from the pre-completion ROLLBACK_PENDING / ROLLED_BACK whose reservation is RELEASED)
 * Not in flight (TERMINAL): COMPLETED, FAILED, ROLLED_BACK, CANCELLED, REVERSED.
 * Final (no outgoing transition): FAILED, ROLLED_BACK, CANCELLED, REVERSED. COMPLETED only leads to a reversal.
 */
final class ActivationState
{
    public const CREATED = 'CREATED';
    public const VALIDATED = 'VALIDATED';
    public const FUNDS_RESERVED = 'FUNDS_RESERVED';
    public const PROVISIONING = 'PROVISIONING';
    public const PROVISIONED = 'PROVISIONED';
    public const VERIFYING = 'VERIFYING';
    public const VERIFIED = 'VERIFIED';
    public const FINANCIAL_COMMITTING = 'FINANCIAL_COMMITTING';
    public const COMPLETED = 'COMPLETED';
    public const FAILED = 'FAILED';
    public const RETRY_PENDING = 'RETRY_PENDING';
    public const RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';
    public const ROLLBACK_PENDING = 'ROLLBACK_PENDING';
    public const ROLLED_BACK = 'ROLLED_BACK';
    public const CANCELLED = 'CANCELLED';
    public const REVERSAL_PENDING = 'REVERSAL_PENDING';
    public const REVERSED = 'REVERSED';

    public const ALL = [
        self::CREATED, self::VALIDATED, self::FUNDS_RESERVED, self::PROVISIONING, self::PROVISIONED, self::VERIFYING,
        self::VERIFIED, self::FINANCIAL_COMMITTING, self::COMPLETED, self::FAILED, self::RETRY_PENDING,
        self::RECONCILIATION_REQUIRED, self::ROLLBACK_PENDING, self::ROLLED_BACK, self::CANCELLED,
        self::REVERSAL_PENDING, self::REVERSED,
    ];

    /** Not in flight: no step of this activation can still run (the in-flight guard and stale report use this). */
    public const TERMINAL = [self::COMPLETED, self::FAILED, self::ROLLED_BACK, self::CANCELLED, self::REVERSED];

    /** No outgoing transition at all. */
    public const FINAL = [self::FAILED, self::ROLLED_BACK, self::CANCELLED, self::REVERSED];

    /** @var array<string,list<string>> from => allowed targets */
    public const TRANSITIONS = [
        self::CREATED => [self::VALIDATED],
        self::VALIDATED => [self::FUNDS_RESERVED],
        self::FUNDS_RESERVED => [self::PROVISIONING, self::RETRY_PENDING, self::CANCELLED],
        self::PROVISIONING => [self::PROVISIONED, self::RETRY_PENDING, self::FAILED, self::RECONCILIATION_REQUIRED],
        self::RETRY_PENDING => [self::PROVISIONING, self::FAILED, self::CANCELLED],
        self::PROVISIONED => [self::VERIFYING, self::RECONCILIATION_REQUIRED],
        self::VERIFYING => [self::VERIFIED, self::RECONCILIATION_REQUIRED],
        self::VERIFIED => [self::FINANCIAL_COMMITTING, self::ROLLBACK_PENDING],
        self::FINANCIAL_COMMITTING => [self::COMPLETED, self::RECONCILIATION_REQUIRED],
        self::RECONCILIATION_REQUIRED => [self::VERIFIED, self::FAILED, self::ROLLBACK_PENDING],
        self::ROLLBACK_PENDING => [self::ROLLED_BACK],
        self::COMPLETED => [self::REVERSAL_PENDING],
        self::FAILED => [],
        self::ROLLED_BACK => [],
        self::CANCELLED => [],
        self::REVERSAL_PENDING => [self::REVERSED],
        self::REVERSED => [],
    ];

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    public static function allowed(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::allowed($from, $to)) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
    }
}
