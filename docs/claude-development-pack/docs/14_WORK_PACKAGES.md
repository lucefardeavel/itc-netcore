# Work Packages

Each WP is executed separately and ends in owner review. WP0 starts from the pinned upstream baseline. WP1+ starts from the owner-accepted prior-WP HEAD and must not reset to the upstream baseline.

## WP0 - Repository Reconnaissance & Baseline Verification
No feature implementation.
- checkout/pin baseline
- inspect actual architecture/schema/runtime
- verify planning findings
- inventory every service-granting entrypoint: manual recharge, cron auto-renew, customer balance/order, payment callbacks, voucher, API, plugin/hook callers
- distinguish FreeRADIUS `radacct` from any PHPNuxBill-local accounting helper table such as `rad_acct`
- verify actual semantics/usages of `tbl_plans.price`
- verify Agent/Sales/Report behavior and current voucher exposure
- verify frontend stack and propose smallest compliant adaptive/PWA integration approach
- verify conflicting upstream license metadata/notices
- create gap report
- identify conflicts between spec and repository
- establish test/dev environment
Gate: owner accepts reconnaissance and any decision adjustments.

## WP1 - Database Foundation
- migration + rollback + verification
- commercial owner + Sales assignment
- tree closure
- wallets/reservations/ledger
- explicit wholesale base-cost storage
- manager price configuration
- funding/transfers
- activations/events
No feature UI or live business behavior.

## WP2 - Hierarchy + Permission Core
- ActorContext
- tree service
- permission service
- DataScope
- accessible customer lookup
- customer owner + Sales-assignment enforcement
- unassigned legacy-customer admin-only rule
- Report role deny-by-default for new domain unless owner approved mapping
- isolation tests
Gate before finance.

## WP3A - Wallet / Ledger / Funding / Transfer
- wallet service
- reservation primitives
- immutable ledger
- Admin/SuperAdmin provider funding/withdrawal
- debt limit
- parent -> direct-child transfer
- concurrency/idempotency tests

## WP3B - Pricing / Commission / Provider Settlement
- explicit wholesale base cost
- reseller wholesale inheritance
- retail-vs-wholesale separation
- parent-set child cost rules
- commission chain
- provider/system settlement conservation
- pricing snapshots
- no network activation yet

## WP4 - RADIUS Hardening
- repository/policy/provisioner/accounting separation
- preserve accounting history
- hardened CoA client
- RADIUS contract tests

## WP5A - Activation Core
- activation service/state machine/events
- idempotency
- NEW/RENEW/CHANGE_PLAN/REACTIVATE for authoritative new-domain calls
- wallet reservation + RADIUS provision/verify + financial settlement
- reconciliation
- compatibility bridge for legacy recharge/invoice recording where needed
- failure injection

## WP5B - Legacy Service Entrypoint Closure
- adapt/gate all reseller-owned service-granting entrypoints inventoried in WP0
- auto-renew integration
- customer-balance/order integration where enabled
- payment-gateway completion integration where enabled
- API/plugin/hook entrypoint gating
- voucher-redemption bypass prevention
- callback/cron/replay idempotency tests

## WP6A - Adaptive Shell / Subscriber UX
- role/device adaptive shell
- dashboard foundation
- subscriber search
- Subscriber 360
- create customer + owner/Sales assignment UX
- activation/renew/change confirmation/progress/outcome UX
- RTL/LTR/accessibility

## WP6B - Finance / Reseller / Reports UX
- wallet/ledger/funding/transfer UX
- reseller tree/detail/Sales UX
- wholesale/retail pricing UX
- commission/provider settlement presentation
- scoped reports/exports
- reconciliation/admin operational views
- no reseller voucher-generation UI without approved settlement addendum

## WP7 - PWA
- manifest/service worker
- offline read-only shell/cache
- per-user cache hygiene and logout/account-switch clearing
- connectivity states
- install/update/deep links
- notification-ready plumbing

## WP8 - Integration POC
- Ubuntu staging
- MySQL
- FreeRADIUS
- private management/WireGuard
- MikroTik test NAS
- PPPoE + Hotspot
- multi-level reseller
- wallet/commission activation
- expiry/renew/change
- adapted auto-renew/customer-order path where enabled
- failure/recovery drills

## WP9 - Red-Team & Release Candidate
- IDOR
- privilege escalation
- wallet double spend
- replay/idempotency
- hierarchy attacks
- forged retail/wholesale price
- legacy service-entrypoint bypass attempts
- export leakage
- CoA injection
- reservation leak
- recovery tests
- PWA cross-user cache leakage/stale-authority tests

## WP10 - Release/Handover
- final docs
- perform and document staging backup/restore drill
- deployment/rollback runbook
- monitoring/logging checklist
- upgrade strategy from pinned upstream ancestry
- resolved licensing/notices summary
- owner acceptance
- only then optional production deployment under explicit owner instruction
