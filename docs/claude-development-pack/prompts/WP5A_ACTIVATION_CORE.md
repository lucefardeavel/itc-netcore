Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP4 HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP5A Prompt - Activation Core

## Prerequisite
WP4 accepted.

## Mission
Implement the persistent authoritative activation state machine for the new/manual reseller flow only. Legacy cron/payment/API/voucher entrypoint closure is WP5B.

## Required
- ActivationService
- state transitions/events
- unique idempotency
- NEW/RENEW/CHANGE_PLAN/REACTIVATE for authoritative new-domain calls
- permission/ownership checks
- live pricing resolution + immutable snapshots
- wallet reservation
- RADIUS provisioning + verification
- financial commit + commission/provider settlement
- UNKNOWN -> RECONCILIATION_REQUIRED
- verified provisioning + finance-commit failure remains recoverable and cannot become free service
- no blind retry
- safe compatibility bridge for recharge/transaction/invoice history as necessary for this authoritative flow
- failure injection tests
- prove no double service period/debit/commission on retries/reloads
- create `owner-review/WP5A/REPORT.md`

## Forbidden
- cron auto-renew adaptation
- customer self-service/order adaptation
- payment-gateway callback adaptation
- API/plugin/voucher closure except temporary fail-closed guards strictly required to prevent immediate bypass
- broad UI redesign
- PWA work
- production deploy
