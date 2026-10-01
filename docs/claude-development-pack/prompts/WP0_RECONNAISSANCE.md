Repository target: hotspotbilling/phpnuxbill
Upstream baseline / WP0 execution base: d3e0596287322944ee21fcc14dc1798158463140

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP0 Prompt - Repository Reconnaissance & Baseline Verification

## Mission
Verify the real pinned repository before feature implementation. This WP is inspection, environment setup, and evidence only, except minimal non-product test harness/bootstrap changes if strictly required and clearly isolated.

## Required work
- checkout/pin exact baseline commit
- show branch/worktree/HEAD and clean state
- inspect runtime requirements, Docker files, composer/dependencies, install schema, update mechanism, plugin/autoload structure
- inspect roles/root, Agent/Sales/Report behavior, customer ownership/assignment possibilities, customer list/view/edit paths
- inspect Balance, Package recharge, Radius, MikroTik PPPoE/Hotspot handlers
- verify each item in `docs/17_REFERENCE_FINDINGS.md`
- map FreeRADIUS `radacct` versus any local `rad_acct` helper table
- prove actual uses/meaning of `tbl_plans.price`; do not assume it is wholesale
- inventory **every service-granting entrypoint**: manual recharge, cron auto-renew, customer balance/order, payment-gateway completion/callback, voucher redemption/generation, API endpoints, plugin/hook callers
- inspect voucher exposure by Admin/Agent/Sales and classify whether reseller generation currently exists
- inspect existing test/CI state
- identify PHP/runtime inconsistency if any
- inspect frontend stack and recommend the smallest compliant adaptive/PWA approach; default preference is existing Smarty/server-rendered stack + progressive enhancement
- inspect license/copyright metadata and classify the README/LICENSE/composer conflict without inventing legal certainty
- produce `GAP_ANALYSIS.md` and `BASELINE_VERIFICATION.md` in repo docs area
- create `owner-review/WP0/REPORT.md`
- propose the smallest test/dev environment necessary for WP1+

## Forbidden
- no reseller feature implementation
- no schema migration
- no UI redesign
- no production operations
- no license notice removal/relicensing

## Acceptance
Every reference finding classified CONFIRMED / CHANGED_UPSTREAM / NOT_REPRODUCED with file/function evidence. List any spec conflicts that require owner decision. Provide a complete service-entrypoint inventory and explicit candidate bypass list.
