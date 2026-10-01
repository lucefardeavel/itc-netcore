Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP8 HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP9 Prompt - Red-Team / Release Candidate

## Prerequisite
WP8 accepted.

## Mission
Attempt to break the release candidate against the frozen security/business contracts.

## Required attack/test areas
- cross-reseller IDOR
- unassigned legacy customer access
- Sales assignment bypass
- forged POST IDs
- privilege escalation
- forged retail/wholesale/base price
- manager attempts to modify own wholesale cost
- wallet double-spend
- provider funding/withdrawal abuse
- concurrent activation
- replay/idempotency
- hierarchy cycle/move abuse
- CSV/report leakage
- ledger mutation attempts
- reservation leak
- ambiguous network reply/retry
- verified provision + finance commit failure
- legacy recharge/cron/customer-order/payment/API/plugin/voucher bypass attempts
- CoA command injection
- stale client/PWA cache authority
- cross-user PWA cache leakage after logout/account switch
- secret leakage in UI/logs/review artifacts
- create `owner-review/WP9/REPORT.md`

## Output
Pass/fail evidence for every test. Any BLOCKER means release is not acceptable.
