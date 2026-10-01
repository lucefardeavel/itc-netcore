Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP9 HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP10 Prompt - Release / Handover

## Prerequisite
WP9 owner-accepted with zero blockers.

## Mission
Prepare a production-ready release candidate and handover material. Do not deploy unless the owner explicitly adds deployment authorization to this prompt.

## Required
- final version/commit inventory and ancestry from upstream baseline through accepted WPs
- installation/upgrade guide
- create a staging backup and **perform an actual restore drill**; report commands/results
- backup/restore runbook
- rollback plan and release-candidate rollback verification
- environment variables/secrets guide without exposing secrets
- monitoring/logging checklist
- upstream merge strategy
- resolved license-metadata/notices report; preserve notices and document remaining legal uncertainty if any
- source distribution obligations only to the extent supported by verified licensing evidence; do not invent certainty
- known limitations
- operator/admin guide
- owner acceptance checklist
- create `owner-review/WP10/REPORT.md`

## Default
NO PRODUCTION DEPLOYMENT.
