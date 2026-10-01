Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP3B HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP4 Prompt - RADIUS Hardening

## Prerequisite
WP3B accepted.

## Mission
Create the FreeRADIUS-first provisioning/accounting abstraction without activation finance orchestration yet.

## Required
- verify legacy Radius behavior
- RadiusRepository
- RadiusPolicyBuilder
- RadiusProvisioner
- AccountingService
- hardened CoAClient
- explicitly distinguish authoritative FreeRADIUS `radacct` from any local PHPNuxBill `rad_acct` helper table
- preserve accounting history
- remove/avoid destructive FreeRADIUS `radacct` deletion in normal lifecycle path
- PPPoE + Hotspot policy contract tests
- dynamic pool + static IPv4
- simultaneous use + rate + expiration
- structured ProvisionResult SUCCESS/FAILED/UNKNOWN
- create `owner-review/WP4/REPORT.md`

## Forbidden
- final activation saga
- reseller UI/PWA
- production NAS changes without explicit test environment instruction
