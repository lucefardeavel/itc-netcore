---
name: wp6b-planning-state
description: WP6B ACCEPTED_WITH_NOTES; origin feat = 1148559d (acceptance recorded, canonical baseline); main 500d3a2d untouched; WP7 awaits owner go
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-04T22:15:04.329Z
---

**ACCEPTED (2026-10-05):** owner verdict ACCEPTED_WITH_NOTES (impl `bd3f6cf2`, evidence `20bcfcaf`). feat fast-forwarded --ff-only to `20bcfcaf` + docs-only acceptance commit `1148559d` (ledger row + owner-review/WP6B/OWNER_DECISIONS.md), pushed; origin feat = `1148559d` = new canonical baseline. WP6B branch left at `20bcfcaf`; main `500d3a2d` untouched. Note: the ledger lives under gitignored docs/claude-development-pack, so `git add -f` it (tracked file). R-13 closed. Next: WP7 awaits owner go.

**Fix round 2 (2026-10-05):** owner accepted FIX 1/2; FIX 3 blocker (TOCTOU between pre-read and setDebtLimit). Owner-authorized WalletService change: additive `setDebtLimitIfCurrent` sharing private `writeDebtLimit`; compare after LockedWallet::lock FOR UPDATE -> DEBT_LIMIT_CHANGED -> REVIEW_EXPIRED in WalletDesk. Impl `bd3f6cf2`, evidence `20bcfcaf`, pushed (branch only). Focused 211/211 per engine. R-13 CLOSED. Waiting owner review (`WP6B_FIX_ROUND_2_READY_WAITING_OWNER_REVIEW`).

**Fix round 1 (2026-10-05):** owner FIX_REQUIRED on f7496710 (3 fixes: performance CSV truncation, catalog export cap, stale debt-line review). Fix impl `22e534b2`, evidence `d89e73bc`, pushed (branch only). Shared `Finance/ExportCap` (assertWithin + snapshot count/stream in one RR snapshot); debt-line review binds `current`, execute re-reads -> REVIEW_EXPIRED. Focused only (no 3rd full run): 143+9 per engine green. Waiting owner review (`WP6B_FIX_ROUND_1_READY_WAITING_OWNER_REVIEW`).

**Candidate (2026-10-05):** WP6B_CANDIDATE_READY_WAITING_OWNER_REVIEW. Implementation `f7496710`, evidence `0c6f1768`, pushed `origin/wp6b/finance-reseller-reports-ux` (normal push). Final run 2: 866/866 per engine, route 1727/1354/373 (+250 vs WP6A 1477, 0 removed), lint 482/482, AVAIA unchanged. Run 1 (a78ef1c3) failures fixed: SchemaNotMigrated nav query (wallet.read guard) + Wp5a ActivationHttpTest regex excluding nav (deviation D-1, not pre-listed in plan §20). Visual QA 29 captures, all clean. Two full runs used (cadence max). Next: owner code review; record acceptance only on owner decision.

**Implementation (2026-10-04, owner GO "FINAL PLAN ACCEPTED / IMPLEMENTATION GO"):** branch `wp6b/finance-reseller-reports-ux` from `81d49243`.
- Commits:
  - `5d3b6040`: plan record.
  - Slices 0–8: `4a086ee0`, `b0385729`, `9d4e8cf9`, `0042b4c6`, `fbbeec47`, `4b30e2c3`, `d815a1c6`, `5c54244c`, `7ce54680`.
  - Visual-smoke fixes: `a78ef1c3`.
- The candidate runtime is `a78ef1c3`. feat `81d49243` and main `500d3a2d` must stay untouched. Push only the WP6B branch. No PR, deploy or WP7.
- Final message must end with `WP6B_CANDIDATE_READY_WAITING_OWNER_REVIEW`.

**Architecture (OD-1):** controllers → `Reseller\Backoffice` (WalletDesk, PricingDesk, SettlementDesk, ResellerDesk, OperationsDesk, ExportStream, ConfirmToken) → `Finance` / `Activation` / `Domain`.
- Guarded by `Wp6bBoundaryTest` and the amended Wp3a/Wp3b guards.
- The RouteMap pin must be re-pinned after every RouteMap edit.

**Validation method:** run the WP6A method in `scratchpad/wp6bval/` (`run.sh` + `sampler.sh`, AVAIA snapshot).
- Route rows land in `scratchpad/wp3b/route-*.rows`. Baseline WP6A rows: `scratchpad/wp3b/prev-wp6a/` (1477 rows).
- The lint list is `git ls-files '*.php'` excluding `tests/vendor` and `system/vendor` (482 files).

**Lab:** `scratchpad/wp6b/visualqa.py <ip> <out> [prefixes]`.
- 25 captures plus 3 tablet probes, with a 360 px re-measure of phone pages.
- The lab seed (`/srv/phpnuxbill/uxlab/tests/Lab/UxLabTest.php`) adds a sale by B and a stale FUNDS_RESERVED activation (`refs.karrar`, `refs.stale`).

**Residuals to report:**
- tree `canTarget` makes N queries;
- `held`/`renewals` use global lists sliced in PHP;
- drift is heavy;
- the Arabic copy is a draft;
- devices are emulated;
- UTC date filters;
- `PricingReadModel` reasons;
- the stale threshold applies to cancel/resume;
- deviation: `ActivationReadService` gained additive reads;
- deviation: `TransferService` compensation was extracted;
- deviation: the legacy `custom.js` radio guard.

Related: [[wp6a-planning-state]], [[shared-host-avaia-boundary]], [[phpnuxbill-dev-env]].
