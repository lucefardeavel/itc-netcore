---
name: wp5b-planning-state
description: "WP5B ACCEPTED_WITH_NOTES (impl cdaebba2, evidence cfef0129); integrated into feat at f806a618 (pushed); WP6 awaits owner go"
metadata:
  type: project
---

**Status (2026-10-03).** WP5B is at the planning stage only.
- Plan: `/root/Downloads/WP5B_PLAN.md`.
- Base: `feat/reseller-system-v1` = `427799f5`.
- No `wp5b/legacy-entrypoint-closure` branch, code or push yet.

**Key findings:**
- Still open for all customers:
  - SEC-13: the gateway include in `order.php` buy has no allowlist.
  - SEC-14: the POST `discount` reduces the gateway charge.
  - SEC-15: a negative balance transfer is accepted in `home.php`.
  - SEC-16: voucher + username overwrites an existing customer's password.
  - SEC-23: voucher redemption is check-then-set.
- `service.recharge_free` is never checked.
- The `order` routes accept GET without CSRF, and order cancel can overwrite a paid row.
- `cron.php` is web-allowed in `system/.htaccess`.
- No gateway plugin is shipped.
- The activation schema already has channel / CUSTOMER actor / external_reference / retail snapshot.
- `ActivationStore::insert` hard-codes STAFF_WEB, and `event()` lacks `actor_customer_id`.

**Recommendations in the plan:**
- Adapt Admin recharge (E1) and cron auto-renew (E11) to ActivationService.
- Allow the re-provision-only paths E19 / E20 / E21.
- Fail closed for all other reseller-owned grants.
- Blocking OD-1: who owns retail money for reseller-owned customers.
- Blocking OD-14: reversal state representation (recommended (a): extend A3 COMPLETED → ROLLBACK_PENDING → ROLLED_BACK).

**Why:** resume WP5B from this plan; do not re-sweep the code.

**How to apply:** implement only after the owner answers the ODs and gives an explicit go. Related: [[wp5a-planning-state]], [[phpnuxbill-dev-env]].

**Revision 1 (2026-10-03), after the owner review "PLAN FIX REQUIRED".**
- **Owner decisions:**
  - OD-1, OD-2, OD-3: fail closed (no retail, payment or cron activation machinery).
  - OD-6: Admin web recharge redirects to `activation/create`; `zero` / `balance` refused; the API is refused.
  - OD-10: bounded `LegacyReprovisionService` plus device default deny.
  - OD-14: R0007 with only the `REVERSAL_PENDING` / `REVERSED` ENUM extension.
- **Reversal:** `ActivationReversalService` saga, phases 0 (safety proof) / 1 (financial preflight) / 2 (RADIUS restore + durable evidence) / 3 (ledger + legacy commit). `REV-<ref>` row; LegacyBridge PRE/POST `reversal_context`; pre-WP5B activations → `REVERSAL_CONTEXT_NOT_CAPTURED`.
- **Vouchers:** atomic claim `status '0' → 'CLAIMED'` (VARCHAR(25), no schema).
- **Next:** wait for the owner's implementation go.

**Revision 2 (2026-10-03), after the final plan review.**
- **Legacy customer balance:** `LegacyCustomerBalanceService`, unassigned customers only.
  - Purchases E8 / E9 / E1-balance / E11 use a `tbl_payment_gateway` journal: gateway 'Balance', key in `gateway_trx_id`, status 5 = HELD / 2 = done / 4 = refunded, serialized by the customer row lock.
  - E18 is an atomic locked transfer.
- **Reversal start:** under the tree lock (`FinanceTx::run(true)`).
- **Re-provision:** under `TreeSerialization::hold`, a lock-only holder of the same tree lock name. It owns the mutation: FAILED → DB reverted; UNKNOWN → kept + `REPROVISION_UNCONFIRMED`.
- **E13:** per-username GET_LOCK; the provisional customer row is deleted if proven not started.
- **Vouchers:** CLAIMED is protected server-side; source model STAFF / CUSTOMER / PUBLIC / RADIUS.
- **Cron:** 404 for non-CLI; remove the `.htaccess` cron allow.
- **Open:** the `Balance.php` atomic relative-update delta needs a pin approval; if declined, R-11 is a residual.

**IMPLEMENTATION STARTED, then STOPPED (2026-10-03).**
- The owner approved plan revision 2 and the `Balance.php` delta. The delta must resolve one id by username (`ORDER BY id LIMIT 1`) and then update by id; a multi-row update is NOT approved. E13 cleanup is one transaction.
- Branch `wp5b/legacy-entrypoint-closure` was created from `427799f5`.
- **Slice 1 is local and UNCOMMITTED:**
  - R0007 file (sha `b7edce53…`);
  - `ActivationState` 17 states / 26 edges, `TERMINAL` + `FINAL`;
  - `SchemaReadiness` += R0007;
  - Migration framework `redefines` support in `MigrationDefinition` / `Runner` (pinned files).
- **Stop condition E hit.** Accepted tests whose contract changes:
  - `MigrationDefinitionsTest` ("after R0005 only CREATE TABLE");
  - `ForwardMigrationTest` T3 (it compares the raw R0003 contract).
- Also asking for approval of the `Runner` / `MigrationDefinition` framework change.
- 66/73 WP1 unit+integration tests pass with R0007; the 7 failures are listed in the stop report.

**RESUMED (2026-10-03) after owner approval "R0007 RUNNER EXTENSION APPROVED".**
- Slice 1 committed `975413d3` (green both engines, 182 tests each). Not pushed.
- Strict redefines rules + set-level target check implemented; dedicated tests `R0007ReversalStatesMigrationTest`, `MigrationRedefinesTest`.
- Planned accepted-test updates (rev1 §9): ActivationStateAndPeriodTest, ActivationCapabilityTest, ActivationSourceGuardTest, SourceGuardTest (pins + post* list), LedgerTest public API, readiness tests, PricingSourceGuardTest, ServiceGateTest, ActivationTestCase/FinanceInvariants, migration fixtures.
- Lesson: never edit runtime files while a test run is in progress (containers mount the repo live).
- Progress 2026-10-03 ~18:10: slices 1-8 committed locally (975413d3, 3f422e67, bb55d3f0, 08a670f0, 96202960, 5d7c92f2, f261c7f8, 59f3fa90). Next: slice 9 controllers/RouteMap, 10 drift/ops CLI, 11 races/guards, 12 full validation, 13 evidence, push wp5b only.
- Helpers in session scratchpad: devloop.sh, snaprun.sh (rsync snapshot run in nuxbill-reseller-test network), wp5b/eol.py (CRLF-preserving edits: controllers/cron are CRLF!), wp5b/patch_reprovision_controllers.py (not yet applied).
- **PUSHED 2026-10-03:** origin `wp5b/legacy-entrypoint-closure` = `0d9d6eb7` (evidence), implementation HEAD `bb15ffc8`. Canonical 758/758 per engine, testdox 1516/0, route 1470/1183/287 (+5 activation/reverse denials), lint 437/437. Waiting for owner code review; feat still 427799f5, main 500d3a2d.
- **FIX ROUND 1 PUSHED 2026-10-04:** owner review of bb15ffc8 = FIX_REQUIRED (5 blockers: balance HELD resolve race, RADIUS read in reversal tx, reprovision partial success, MikroTik rename/expiry, stale coupon). Fixes 3a0a7210..5d76a09b (additive), evidence 152b43cc on origin. Canonical 774/774 per engine, testdox 1548/0. Waiting for owner final review.
- **FIX ROUND 2 PUSHED 2026-10-04:** owner final review closed blockers 1–5; blocker 5B = the buy-time coupon basis must equal the preview's legacy rule (current `Invoice` attribute overrides the plan price). Fix `cdaebba2` (order.php only + 4 PaymentHardeningTest tests); reproduction on 5d76a09b = 3 failures. Canonical at cdaebba2: 778/778 per engine, testdox 1556/0, route 1470/1183/287, lint 439/439. Evidence `cfef0129` on origin. Waiting for owner FINAL ACCEPTANCE; feat 427799f5, main 500d3a2d untouched. Owner STOP rule: making Invoice Period-only, or adding bills/tax to the coupon basis, needs an owner decision.
- **ACCEPTED + INTEGRATED 2026-10-04:** owner verdict WP5B ACCEPTED_WITH_NOTES (impl cdaebba2, evidence cfef0129). feat/reseller-system-v1 fast-forwarded to cfef0129, then acceptance metadata commit f806a618 (ledger row + owner-review/WP5B/OWNER_DECISIONS.md); origin feat = f806a618. main 500d3a2d and wp5b branch cfef0129 unchanged. WP6 NOT started; waits for the next owner go. Gotcha: docs/claude-development-pack is gitignored, so stage the tracked ledger with `git add -u <path>`.
