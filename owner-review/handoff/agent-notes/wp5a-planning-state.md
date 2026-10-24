---
name: wp5a-planning-state
description: "WP3B+WP4+WP5A integrated: feat/reseller-system-v1 = 427799f5 on origin (WP5A ACCEPTED_WITH_NOTES at 8e3c6ec9 recorded); WP5B awaits owner go"
metadata:
  type: project
---

WP5A (activation core) state as of 2026-10-02.
- Plan: `/root/Downloads/WP5A_PLAN.md`. The owner answered "Approve all A1–A8".
- Code: implemented on the local branch `wp5a/activation-core`, which starts at `feat/reseller-system-v1` = `0c080a7a`. The implementation HEAD is `c0302b61`, followed by the evidence commit `608260d9` (candidate HEAD). Final validation: 594 tests / 45903 assertions per engine; route matrix 1462/1174/288 (20 new activation rows only).
- Docs: `docs/WP5A_ACTIVATION_MODEL.md` and `owner-review/WP5A/{REPORT.md,TESTDOX.txt}`.
- Status: IMPLEMENTED_NOT_ACCEPTED, STOPPED_WAITING_OWNER_REVIEW. Nothing is pushed.

Decisions as built:
- **A1:** the `Reseller\Activation` namespace. The accepted guards were extended for it: the WP3A `SourceGuardTest` (including the RouteMap re-pin) and the WP3B `PricingSourceGuardTest` allowlists.
- **A2:** the server derives the action. The C14 period is frozen in the VALIDATED event.
- **A3:** 24 edges.
- **A4:** the CLI `system/reseller/reconcile.php` and Admin view buttons. No timeout release.
- **A5:** create is SA/Admin G, Agent S, Sales A. Read is G / T / A.
- **A6:** legacy rows are written at COMPLETED.
- **A7:** the routes `activation/create|create-post|view|reconcile|rollback`.
- **A8:** refunds are deferred to WP5B.

Late fixes: `commitStep` classifies settlement failures with `SettlementFailure::classify`. The bare `activation` route (RouteMap `''`/`*`) is now view; it was create, which made the accepted RouteMatrixTest fail for a non-enrolled Agent.

**Why:** the next session must resume from here, not re-explore. The ledger is owner-controlled.

**How to apply:**
- On owner acceptance, record it on `feat` only if the owner authorizes, the same way as WP3B/WP4.
- WP5B (legacy entrypoint closure, refunds) is next and must not start before the decision.
- Related: [[wp4-planning-state]], [[wp3b-plan-decisions]], [[phpnuxbill-dev-env]].

**Owner fix round 1 (2026-10-03), DONE and PUSHED.**
- The owner's findings on candidate `608260d9`: an unenrolled Agent reached the create form; the global plan catalog was exposed; reconcile released funds on any RADIUS mismatch.
- **Fixes:**
  - **Eligibility.** `Capability::PLACEMENT_REQUIRED` in `PermissionService`, plus `ActivationService::eligible/assertEligible`.
  - **Catalog.** `ActivationService::catalog()` lists only the plans that PricingService says are saleable.
  - **Reconciliation.** It is split by durable VERIFIED evidence. `RadiusState` holds a captured prior/target projection in the PROVISIONING event metadata, with the password digested. An unclear external state gives `EXTERNAL_STATE_AMBIGUOUS` and the funds stay held. Rollback restores the exact prior state, verified, and only then releases.
  - **RADIUS layer.** Additive methods `RadiusRepository::snapshotUser/snapshotGroup/restoreUser` and `RadiusProvisioner::restore`.
- No schema change.
- **Validation on `c00eb868`:** 613 tests / 46426 assertions per engine. Route matrix 1465/1178/287; all 1442 accepted rows are unchanged and 23 activation rows were added.
- **Push:** evidence commit `ca1b12c7`. Only `wp5a/activation-core` was pushed (normal push); local == origin == `ca1b12c70d16fa75935d11cd266bb9342c5c323b`.
  - The branch history also carries `0c080a7a`, `f2269a06` and the WP4 commits to origin, but no `wp4` or `feat` ref was pushed.
  - Not pushed: `feat` and `main` (still `d4ba4514` / `500d3a2d`).
- **State:** no PR or merge; WP5B not started. Waiting for the owner's code review.

**Final owner code review (2026-10-03), exact-rollback blocker FIXED and PUSHED.**
- **Finding (candidate `ca1b12c7`):** rollback re-keyed an unknown prior password and still reported ROLLED_BACK.
- **Fix `7e65cb6c`, tests `06ee8275`:**
  - `restorePlan` resolves every row before any write.
  - A prior Cleartext-Password is restored only when it exactly equals `customer.password` or `customer.pppoe_password`. Otherwise the result is `PRIOR_SECRET_UNRESTORABLE`: ROLLBACK_PENDING, RADIUS untouched, funds held, and the refusal is repeat-safe.
  - The reservation is released only when the full subscriber projection equals the captured prior state.
- **Validation on `06ee8275`:** 618/46587 per engine. The MySQL plain leg hit one AppServer port-reuse flake in `Wp3b PlanDeleteGuardTest`; the isolated reruns (3/3) and a full-leg rerun were clean. Route matrix unchanged at 1465/1178/287.
- **Push:** evidence commit `8e3c6ec9`, pushed. Local == origin == `8e3c6ec975a5698d2ae74b089f8ddaf54460e8cf`.
- **State:** waiting for the owner's final review. No merge or PR; WP5B not started.

**OWNER VERDICT 2026-10-03: WP5A ACCEPTED_WITH_NOTES at `8e3c6ec9`.**
- Not yet recorded in the ledger: the WP5A row on local feat `0c080a7a` is still PENDING, and recording needs the owner's authorization.
- Do not modify WP5A. WP5B not started; nothing merged.
- Then: WP4 catch-up code review. `wp4/radius-hardening` @ `58e3e458` (base `f2269a06`) was pushed to origin (local == origin) with no code change and no rerun. WP4 was already ACCEPTED_WITH_NOTES in the local ledger.

**INTEGRATED 2026-10-03.**
- `feat/reseller-system-v1` was fast-forwarded to `8e3c6ec9`, and the WP5A acceptance was recorded in metadata commit `427799f5` (ledger row + `owner-review/WP5A/OWNER_DECISIONS.md`).
- Pushed: origin feat = `427799f53a570846659796b13e0fbad82175eb6e` (was `d4ba4514`). `main` is unchanged at `500d3a2d`.
- The `wp3b`, `wp4` and `wp5a` branches are unchanged on origin.
- Next: WP5B only on the owner's explicit go.
