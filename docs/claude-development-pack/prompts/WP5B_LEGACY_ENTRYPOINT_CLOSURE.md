Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP5A HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP5B Prompt - Legacy Service Entrypoint Closure

## Prerequisite
WP5A accepted and WP0 service-entrypoint inventory is available.

## Mission
Close every legacy path that can grant/extend service for reseller-owned customers without the accepted ActivationService and wholesale settlement.

## Required
- work from the WP0 inventory, not from assumptions
- adapt or fail-close every applicable reseller-owned service path, including:
  - manual legacy recharge not already covered
  - cron auto-renew
  - customer balance/self-service order
  - payment-gateway completion/callback
  - API/service endpoints
  - voucher redemption
  - plugin/hook callers
- customer retail-balance and reseller wholesale finance must both be safe/idempotent where customer-balance purchase/auto-renew remains enabled
- payment callback replay cannot double-provision or double-charge
- system/cron actor and deterministic/idempotent renewal identity
- unassigned/admin legacy scope may remain compatible only where proven safe
- reseller voucher generation remains disabled unless a separate owner-approved settlement addendum exists
- comprehensive bypass regression tests
- create `owner-review/WP5B/REPORT.md`

## Forbidden
- inventing new reseller voucher-commerce model
- broad UI redesign
- PWA work
- production deploy
