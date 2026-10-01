Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP6B HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP7 Prompt - PWA

## Prerequisite
WP6B accepted.

## Mission
Add installable PWA behavior without changing business authority.

## Required
- manifest
- service worker
- app shell caching
- safe read-only cached experience
- ONLINE/SLOW/OFFLINE/RECONNECTING states
- critical mutation disabled offline
- install UX
- controlled update UX
- deep links
- notification-ready architecture
- verify browser and installed-PWA parity for core online operations
- cached finance/price/permission/state never used as mutation authority
- user-specific cache namespace/clearing on logout and account switch as appropriate
- prove no cross-user cached customer/finance leakage on shared device
- do not cache auth/mutation responses for replay
- create `owner-review/WP7/REPORT.md`

## Forbidden
- offline activation/renew/transfer/funding/suspend
- forced reload during critical operation
