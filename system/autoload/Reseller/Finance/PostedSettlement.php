<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * The ledger result of one activation financial settlement (WP3B): the leaf ACTIVATION_DEBIT and every
 * positive intermediary COMMISSION_CREDIT, all written in one transaction by
 * LedgerService::postActivationSettlement.
 */
final class PostedSettlement
{
    /** @param list<PostedEntry> $commissions ascending manager_id */
    public function __construct(
        public readonly PostedEntry $debit,
        public readonly array $commissions,
    ) {
    }
}
