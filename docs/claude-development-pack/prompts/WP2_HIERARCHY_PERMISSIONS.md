Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP1 HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP2 Prompt - Hierarchy + Permission Core

## Prerequisite
WP1 accepted and current repository HEAD matches/descends from accepted WP1 HEAD.

## Mission
Implement server-side hierarchy, actor context, permissions, customer/Sales assignment enforcement and centralized data scoping before finance features.

## Required
- ActorContext or repository-convention equivalent
- recursive reseller tree support with cycle prevention
- closure-table maintenance or approved equivalent
- PermissionService
- DataScope for customers/managers and extension pattern for future objects
- CustomerService accessible lookup replacing unscoped reseller-route access
- Agent can create authorized sub-reseller per frozen model
- Sales remains operational child, not wallet owner
- Sales sees only explicitly assigned customers
- Sales-created customer owner/assignment rules
- owner transfer clears/validates Sales assignment
- unassigned legacy customers Admin/SuperAdmin-only in new reseller domain
- Report role denied on new domain by default unless owner approved a mapping after WP0 findings
- direct URL/forged POST isolation tests
- no fail-open fallback
- create `owner-review/WP2/REPORT.md`

## Forbidden
- wallet/ledger/pricing implementation
- RADIUS redesign
- broad UI/PWA redesign beyond minimal plumbing needed to test permissions
