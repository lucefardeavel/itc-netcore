Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP2 HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP3A Prompt - Wallet / Ledger / Funding / Transfer

## Prerequisite
WP2 accepted and isolation tests pass.

## Mission
Implement only the reseller wallet accounting foundation with concurrency safety. Pricing/commission settlement is WP3B.

## Required
- WalletService
- reservation lifecycle primitives
- immutable LedgerService semantics
- Admin/SuperAdmin provider funding/withdrawal with reason/reference
- debt limit and available-credit calculation
- block funding/withdrawal/debt-limit changes that invalidate active exposure
- parent -> direct-child reseller transfer policy
- row locks/transactions/idempotency as required
- concurrent spend/transfer tests
- reversal/compensation tests
- create `owner-review/WP3A/REPORT.md`

## Forbidden
- plan wholesale/retail pricing implementation
- commission settlement
- network provisioning
- activation orchestration
- reseller voucher-commerce
- broad UI
