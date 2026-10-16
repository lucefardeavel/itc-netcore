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
        // WP9 U0: the shared identity check (generic: never says which identity holds the name)
        'IDENTITY_TAKEN' => 'This username or PPPoE username is already in use.',
        'IDENTITY_CHECK_UNAVAILABLE' => 'The username could not be checked right now; nothing was changed. Please try again.',
        'IDENTITY_AMBIGUOUS' => 'This RADIUS identity belongs to more than one subscriber; nothing was changed. An operator must resolve the duplicate first.',
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
        // reseller finance (WP3A)
        'INVALID_AMOUNT' => 'The amount must be a positive decimal with at most two decimal places.',
        'INVALID_REASON' => 'A reason of 3 to 255 characters is required.',
        'INVALID_REFERENCE' => 'The reference must be 8 to 64 characters (letters, digits, dot, underscore, colon or hyphen).',
        'INVALID_ACTOR' => 'This actor cannot perform this financial operation.',
        'STORAGE_RANGE_EXCEEDED' => 'The resulting amount exceeds the supported money range.',
        'MONEY_OVERFLOW' => 'The money calculation exceeds the supported range.',
        'INSUFFICIENT_AVAILABLE_CREDIT' => 'Insufficient available credit.',
        'WALLET_NOT_FOUND' => 'The reseller has no wallet; use provider funding for the first credit.',
        'WALLET_OWNER_INVALID' => 'The account is not a valid, enrolled reseller wallet owner.',
        'WALLET_OWNER_FROZEN' => 'The reseller or a parent reseller is inactive; financial changes are blocked.',
        'IDEMPOTENCY_CONFLICT' => 'The reference was already used for a different operation.',
        'SELF_TRANSFER' => 'A wallet cannot transfer to itself.',
        'NOT_DIRECT_CHILD' => 'Transfers are allowed only from a reseller to its direct child reseller.',
        'DEBT_LIMIT_EXPOSURE' => 'The debt limit cannot be reduced below the current balance and active reservations.',
        'RESERVATION_CONFLICT' => 'A different reservation already exists for this activation.',
        'RESERVATION_RESOLVED' => 'The reservation for this activation was already released or expired.',
        'RESERVATION_NOT_ACTIVE' => 'The reservation is not active.',
        'RESERVATION_ALREADY_COMMITTED' => 'The reservation was already committed.',
        'ACTIVATION_MISMATCH' => 'The reservation does not match the activation.',
        'ALREADY_REVERSED' => 'The operation was already reversed.',
        'NOT_REVERSIBLE' => 'This operation cannot be reversed.',
        'LOCK_REQUIRED' => 'The operation requires the reseller hierarchy lock.',
        'CONCURRENCY_RETRY' => 'The financial operation could not complete because of concurrent activity; please try again.',
        'LEDGER_STATE_MISMATCH' => 'The wallet changed unexpectedly; the operation was not applied.',
        // reseller pricing and settlement (WP3B)
        'BALANCE_PLAN_NOT_RESELLABLE' => 'Balance (customer top-up) plans are not available for reseller commerce.',
        'PLAN_NOT_RESELLABLE' => 'The plan has no provider base cost and is not resellable.',
        'PLAN_RESALE_DISABLED' => 'Reseller commerce is disabled for this plan.',
        'PLAN_DISABLED_BY_ANCESTOR' => 'The plan was disabled by a parent reseller.',
        'PLAN_DISABLED_BY_SELF' => 'The plan is disabled for this reseller.',
        'PRICING_CHAIN_INVALID' => 'A wholesale price in the reseller chain is below its parent price; the plan cannot be sold until it is corrected.',
        'ZERO_WHOLESALE_NOT_SUPPORTED' => 'A zero wholesale price cannot be used for a reseller activation.',
        'LEGACY_RETAIL_INVALID' => 'The plan default price is not a valid money value; set an explicit retail price.',
        'RETAIL_BELOW_WHOLESALE' => 'The retail price is below the wholesale price; the plan cannot be sold until it is corrected.',
        'RETAIL_CLEAR_INVALID' => 'The plan default price would be below the wholesale price or is invalid; keep an explicit retail price.',
        'WHOLESALE_BELOW_PARENT' => 'A reseller wholesale price cannot be below its parent wholesale price.',
        'WHOLESALE_DESCENDANT_CONFLICT' => 'The change would put a sub-reseller price below its new parent price; raise the sub-reseller prices first.',
        'BASE_COST_DESCENDANT_CONFLICT' => 'The new base cost would exceed an existing reseller price; raise those prices first.',
        'SELF_PRICING_FORBIDDEN' => 'A reseller cannot set its own wholesale price or availability.',
        'PRICING_TARGET_INVALID' => 'The account is not a valid, enrolled reseller.',
        'PRICING_TARGET_FROZEN' => 'The reseller or a parent reseller is inactive; pricing changes are blocked.',
        'PRICING_SNAPSHOT_INVALID' => 'The activation pricing snapshot is missing, incomplete or inconsistent.',
        'PRICING_SNAPSHOT_STALE' => 'Prices changed since the activation was priced; price it again.',
        'COMMISSION_RECIPIENT_NO_WALLET' => 'A parent reseller that earns a commission on this sale has no wallet yet.',
        'COMMISSION_RECIPIENT_CAPACITY' => 'A parent reseller wallet cannot receive this commission (money range exceeded).',
        // WP5A activation
        'ACTION_MISMATCH' => 'The customer\'s current service changed; review the activation again.',
        'ACTIVATION_IN_PROGRESS' => 'Another activation for this customer is still in progress; check its status first.',
        // WP6A (OD-B): the confirmed quote no longer matches the authoritative one; nothing was written
        'ACTIVATION_PREVIEW_CHANGED' => 'The price or terms changed since you reviewed them. Nothing was charged; review the updated confirmation.',
        'ACTIVATION_STATE_CONFLICT' => 'The activation is not in a state that allows this step.',
        'CUSTOMER_NOT_RESELLER_OWNED' => 'Only customers owned by a reseller can be activated here.',
        'CUSTOMER_INACTIVE' => 'The customer account is not active.',
        'PLAN_DEVICE_NOT_SUPPORTED' => 'This plan is not provisioned through FreeRADIUS and cannot be activated here.',
        'PLAN_DISABLED' => 'The plan is disabled.',
        'PLAN_VALIDITY_INVALID' => 'The plan validity is not valid.',
        'INVALID_IDEMPOTENCY_KEY' => 'The request identifier is missing or invalid; reload the form.',
        'PRIOR_STATE_NOT_CAPTURED' => 'The RADIUS state before this activation was not captured; it cannot be restored automatically. RADIUS was not changed and the reservation remains held.',
        'PRIOR_SECRET_UNRESTORABLE' => 'The exact prior authentication state cannot be restored automatically: the previous RADIUS password is not one of the customer\'s own passwords. RADIUS was not changed and the reservation remains held; operator review is required.',
        // WP5B completed-activation reversal and legacy entrypoint closure
        'REVERSAL_CONTEXT_NOT_CAPTURED' => 'This activation was completed without the context needed to reverse it exactly; nothing was changed. Operator review is required.',
        'REVERSAL_EXTERNAL_STATE_CHANGED' => 'The customer\'s service changed after this activation (a later activation, a RADIUS edit or a billing change); nothing was reversed.',
        'REVERSAL_RADIUS_NOT_RESTORED' => 'RADIUS still shows the activation\'s service: the restore did not apply. Nothing else changed; the reversal can be repeated.',
        'REPROVISION_FAILED' => 'The device rejected the change; nothing was changed.',
        'REPROVISION_UNCONFIRMED' => 'The change was saved but the device result could not be confirmed. It was not retried; an administrator must synchronize this customer.',
        'CUSTOMER_RESELLER_OWNED' => 'This operation is not available for reseller-managed customers.',
        'INSUFFICIENT_BALANCE' => 'Insufficient balance.',
        'COUPON_INVALID' => 'The coupon is not valid.',
        'BALANCE_OPERATION_BUSY' => 'This balance operation is being processed right now; nothing was changed. Try again shortly.',
        'REVERSAL_FINANCIAL_REFUSED' => 'The settlement of this activation cannot be reversed now; nothing was changed.',
        // WP6B finance / reseller / reports UX (presentation safety limits and review binding; no business rule)
        'EXPORT_RANGE_REQUIRED' => 'Choose a date range of at most 366 days for this export.',
        'EXPORT_TOO_LARGE' => 'The export would exceed 20,000 rows; narrow the filters and try again.',
        'REVIEW_EXPIRED' => 'This confirmation is no longer valid; nothing was changed. Review the operation again.',
        'CONFIRMATION_MISMATCH' => 'The typed confirmation does not match; nothing was changed.',
        // WP6B fix round 2: WalletService::setDebtLimitIfCurrent found a different locked current limit
        'DEBT_LIMIT_CHANGED' => 'The debt limit changed after it was reviewed; nothing was changed.',
    ];

    public function __construct(public readonly string $reason, ?string $detail = null)
    {
        parent::__construct($detail ?? (self::MESSAGES[$reason] ?? 'Operation refused.'));
    }
}
