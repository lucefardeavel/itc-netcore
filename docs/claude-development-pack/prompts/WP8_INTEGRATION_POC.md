Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP7 HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP8 Prompt - Integration POC

## Prerequisite
WP7 accepted and disposable/staging FreeRADIUS + MikroTik test NAS access is available. If not available, return NOT_READY rather than simulating a pass.

## Mission
Validate the system end-to-end in a disposable/staging environment with real FreeRADIUS and MikroTik test NAS.

## Required
- document environment topology
- private management path/WireGuard where available
- PPPoE test
- Hotspot test
- dynamic pool
- static IPv4
- online session/accounting
- CoA/disconnect
- A -> B -> C reseller chain
- Admin funding + parent-child transfer
- wallet/commission activation and provider settlement conservation
- Sales-assigned customer operation
- expiry/renew/change
- adapted auto-renew/customer-balance/payment path where those features are enabled
- verify disabled/unadapted legacy service paths fail closed for reseller-owned customer
- network loss and recovery drills
- capture exact evidence
- create `owner-review/WP8/REPORT.md`

## Forbidden
- production rollout
