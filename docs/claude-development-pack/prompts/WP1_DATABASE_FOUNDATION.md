Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP0 HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP1 Prompt - Database Foundation

## Prerequisite
WP0 accepted by owner and current repository HEAD matches/descends from accepted WP0 HEAD.

## Mission
Implement only the schema foundation described in `docs/04_DATABASE_REQUIREMENTS.md`.

## Required
- inspect current accepted schema first
- choose migration mechanism compatible with repository while preserving strict failure detection
- create reversible migration(s)
- create verification/preflight where appropriate
- add customer commercial owner and optional Sales assignment semantics
- no guessed existing-customer owner/Sales backfill
- add hierarchy/finance/activation foundation
- add explicit wholesale base-cost storage without repurposing legacy `tbl_plans.price`
- indexes/unique constraints/FKs appropriate to actual column signedness/types
- database changes must not activate new business behavior
- tests against fresh/disposable DB and representative upgraded DB
- prove rollback path or explicitly document irreversible step if unavoidable (requires owner decision)
- create `owner-review/WP1/REPORT.md`

## Forbidden
- no UI
- no wallet business logic
- no RADIUS behavior change
- no MikroTik behavior change
- no activation service
- no speculative reseller voucher-commerce schema unless owner approved a separate settlement addendum
- no production apply
