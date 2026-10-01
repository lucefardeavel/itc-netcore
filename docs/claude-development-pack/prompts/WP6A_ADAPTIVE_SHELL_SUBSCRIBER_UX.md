Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP5B HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP6A Prompt - Adaptive Shell / Subscriber UX

## Prerequisite
WP5B accepted.

## Mission
Implement the role/device-adaptive shell and subscriber operational experience using the approved frontend strategy. Default is existing PHPNuxBill server-rendered/Smarty stack plus progressive enhancement.

## Required
- Admin/Reseller/Sales role-adaptive shell/navigation
- desktop/tablet/mobile layout behavior
- dashboard foundation
- subscriber search
- Subscriber 360
- create subscriber flow
- customer commercial owner + Sales assignment UX within permissions
- activation/renew/change confirmation, progress and outcome UX using accepted backend states
- RTL Arabic-first behavior with technical LTR tokens
- mobile cards instead of squeezed wide tables
- accessible dangerous-action confirmations
- UI never authorizes with cached/browser money values
- create `owner-review/WP6A/REPORT.md`

## Forbidden
- wallet/reseller/pricing/report UI except navigation placeholders needed for shell
- service worker/offline/PWA install work
- changing domain rules for visual convenience
- introducing React/Vue/other SPA framework without explicit owner decision
