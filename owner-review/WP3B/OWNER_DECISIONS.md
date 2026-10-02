# WP3B Owner Review Decision and Owner Decisions

Recorded 2026-10-02 from the owner's explicit decision in the implementation session ("Accept WP3B, plan WP4").
This file records only that decision and the owner decisions given during WP3B planning and the two review fix rounds. Binding inputs below are carried forward from the accepted WP3B documentation (`docs/WP3B_PRICING_MODEL.md`, `owner-review/WP3B/REPORT.md`); they are not new owner statements.

## WP3B acceptance
- Owner status: **ACCEPTED**
- Accepted HEAD: `460714def5c4d9814fc80b18acf400a3b79a9029` (branch `wp3b/pricing-settlement`, pushed)
- Last implementation change: `9978311a1ebaa202e96c0bc06a51672645e03ee7` (fix round 2); later commits are evidence/docs only
- Base: `d4ba451437c3abf1b6349494b40b93257eaca553` (WP3A accepted at `c329145a` + owner metadata)
- No R0007; R0001–R0006 byte-identical (R0006 `f1a76119…7abd`)

## Owner decisions applied in WP3B (given during planning, 2026-10-01)
- F-1 Commission-recipient wallets: option A. WP3B never creates wallets; `COMMISSION_RECIPIENT_NO_WALLET` before provisioning.
- F-2 `LedgerService::postActivationSettlement` replaces `postActivationDebit`; the listed pinned-test changes are approved.
- F-3 A leaf wholesale of 0 is not activatable in V1 (`ZERO_WHOLESALE_NOT_SUPPORTED`).
- F-4 `tbl_plans.type = 'Balance'` is not resellable (`BALANCE_PLAN_NOT_RESELLABLE`).
- F-5 Retail invalidation: option B. An upstream raise is allowed; affected resellers are unsaleable until corrected. The wholesale-chain rule (OD-5) is unchanged.
- F-6 Commit can fail; typed `SettlementFailure`; headroom is re-checked at commit.
- F-7 One canonical lock order.
- F-8 The internal snapshot is complete; role visibility is a separate read model.
- OD-3 `pricing.child_availability` (SuperAdmin/Admin G, Agent C).
- OD-4 Retail authority (Agent S, SuperAdmin/Admin G).
- OD-5 Strict refusal of newly introduced D023 violations.

## Review fix rounds
1. Owner review of `2c543a63`: assertion totals were not deterministic. Fixed in `e7d2e7a3` (test only).
2. Owner code review of `ae636d14`: plan-delete TOCTOU race. Fixed in `9978311a` (`PlanGuard::deleteSerialized`: tree lock, plan-row lock, final check, legacy effects).

## Test evidence accepted (fix round 2)
- 470 tests / 43219 assertions per engine: MariaDB 10.11.19, MySQL 8.0.46, PHP 8.2.34.
- Plain and testdox runs pass; testdox 940/0; the registry matches.
- Route matrix 1442/1164/278, unchanged.
- PHP 8.2 lint 355/355; manifest 42/42.
- Details: `owner-review/WP3B/REPORT.md` §L.2.

## Binding inputs carried forward (from the accepted WP3B documentation)
- **WP5A:**
  - follow the handoff contract in `docs/WP3B_PRICING_MODEL.md` §9;
  - create activation / `tbl_plans` references only inside the tree-locked transaction that resolves the plan;
  - add an activation-vs-plan-delete race test;
  - own UNKNOWN / settlement reconciliation and refund / reversal.
- **WP4:** the legacy plan-delete device call (`remove_plan`) runs inside `PlanGuard::deleteSerialized` under the tree lock. RADIUS changes must keep that structure.

## Documented residuals (from the WP3B report, §N)
- Recipient overflow at commit, which is typed and atomic.
- Move-created chain violations fail closed.
- Global tree-lock serialization.
- Legacy customer-charge composition (WP5B).
- Application-level immutability (WP9/WP10).
- Refund / reversal (WP5A/WP5B).
- Historical commission visibility after a move.
- Plan delete holds the tree lock during the device call.
- The device can be ahead of the database after an infrastructure failure.
- Harness flakes: the `CliOnlyTest` healthcheck race and `AppServer` port reuse.
