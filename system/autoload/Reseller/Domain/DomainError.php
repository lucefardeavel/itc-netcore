<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * A refused domain operation with a stable reason code (e.g. CYCLE, SELF_PARENT, NOT_FOUND).
 * The message is safe to show to the actor; no internals.
 */
final class DomainError extends \RuntimeException
{
    public const MESSAGES = [
        'NOT_FOUND' => 'Not found.',
        'FORBIDDEN' => 'You do not have permission to perform this action.',
        'SCHEMA_NOT_READY' => 'The reseller schema is not ready; this operation is unavailable.',
        'ACTOR_FROZEN' => 'Your account cannot perform changes while it or a parent account is inactive.',
        'SELF_PARENT' => 'An account cannot be its own parent.',
        'CYCLE' => 'The move would create a cycle in the hierarchy.',
        'INVALID_PARENT_ROLE' => 'The parent must be an Agent.',
        'INVALID_ROLE' => 'This role cannot be used here.',
        'PARENT_INACTIVE' => 'The parent account is not active or not enrolled.',
        'NOT_ENROLLED' => 'The account is not enrolled in the reseller hierarchy.',
        'ALREADY_ENROLLED' => 'The account is already enrolled in the reseller hierarchy.',
        'SALES_HAS_CHILDREN' => 'Sales accounts cannot have child accounts.',
        'SALES_NEEDS_PARENT' => 'A Sales account must be placed under an Agent.',
        'LOCK_TIMEOUT' => 'The hierarchy is busy; please try again.',
        'USERNAME_TAKEN' => 'The username is already used.',
        'INVALID_INPUT' => 'Invalid input.',
        'INVALID_SALES' => 'The selected Sales account is not valid for this customer owner.',
        'INVALID_OWNER' => 'The selected owner must be an active, enrolled Agent.',
        'NOT_UNASSIGNED' => 'Only unassigned (legacy) customers can be given an owner this way.',
        'REFERENCED' => 'This record is referenced by reseller data and cannot be deleted; disable it instead.',
        'SELF_TARGET' => 'You cannot perform this action on your own account.',
        'LAST_SUPERADMIN' => 'The last active SuperAdmin cannot be disabled, deleted or demoted.',
        'ROLE_CHANGE_UNSAFE' => 'The role of an account that is part of the reseller hierarchy cannot be changed; disable it and create a new account instead.',
        'AGENT_DELETE_PROHIBITED' => 'Agent (reseller) accounts cannot be deleted in V1; disable the account instead.',
        'AGENT_ROLE_PERMANENT' => 'The role of an Agent (reseller) account cannot be changed in V1; disable the account instead.',
    ];

    public function __construct(public readonly string $reason, ?string $detail = null)
    {
        parent::__construct($detail ?? (self::MESSAGES[$reason] ?? 'Operation refused.'));
    }
}
