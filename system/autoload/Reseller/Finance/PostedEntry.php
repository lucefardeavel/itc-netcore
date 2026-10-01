<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/** A ledger entry just written by LedgerService, with the wallet state after the write. */
final class PostedEntry
{
    public function __construct(
        public readonly int $entryId,
        public readonly LockedWallet $wallet,
        public readonly Money $balanceBefore,
        public readonly Money $balanceAfter,
    ) {
    }
}
