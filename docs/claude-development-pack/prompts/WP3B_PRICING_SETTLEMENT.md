Repository target: hotspotbilling/phpnuxbill
Upstream ancestry baseline: d3e0596287322944ee21fcc14dc1798158463140
Execution base: owner-accepted WP3A HEAD. Do not reset to upstream baseline.

Read START_HERE.md in mandatory order before changing code. Follow docs/15_CLAUDE_RULES.md and docs/DEFINITION_OF_READY.md exactly. Do not push, merge, tag, deploy, or touch production. End with STOPPED_WAITING_OWNER_REVIEW.

# WP3B Prompt - Pricing / Commission / Provider Settlement

## Prerequisite
WP3A accepted.

## Mission
Implement wholesale/retail pricing resolution and multi-level commission/provider settlement on top of the accepted wallet/ledger foundation, without network activation orchestration.

## Required
- PricingService
- explicit wholesale base cost separate from legacy `tbl_plans.price`
- effective wholesale inheritance
- parent/Admin sets child cost; reseller cannot set own wholesale cost
- optional retail override semantics separate from wholesale
- plan availability inheritance/ancestor disable behavior
- price hierarchy validation
- immutable pricing snapshot API for later activation use
- multi-level commission/margin calculation
- provider/system settlement represented audibly without crediting it as normal reseller-wallet value
- conservation tests across 1-level and multi-level chains
- browser/forged price tests at service boundary where applicable
- create `owner-review/WP3B/REPORT.md`

## Forbidden
- network provisioning
- activation state machine
- reseller voucher-commerce implementation without owner addendum
- broad UI
