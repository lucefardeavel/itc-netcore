<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * The ledger result of one completed-activation reversal (WP5B, owner decision OD-12 / OD-14): the leaf REVERSAL
 * credit of the original ACTIVATION_DEBIT and one REVERSAL debit (clawback) per original COMMISSION_CREDIT, all
 * written in one transaction by LedgerService::postActivationReversal.
 */
final class PostedReversal
{
    /** @param list<PostedEntry> $clawbacks ascending manager_id */
    public function __construct(
        public readonly PostedEntry $credit,
        public readonly array $clawbacks,
    ) {
    }
}
