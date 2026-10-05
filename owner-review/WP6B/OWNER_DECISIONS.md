# WP6B Owner Review Decision and Owner Decisions

Recorded 2026-10-05 from the owner's explicit decisions in the implementation session. The verdict was "WP6B OWNER VERDICT — ACCEPTED_WITH_NOTES / CANONICAL INTEGRATION GO": status **ACCEPTED_WITH_NOTES**, accepted implementation `bd3f6cf2`, accepted evidence / branch HEAD `20bcfcaf`.

The owner instructed:
- record the acceptance;
- fast-forward `feat/reseller-system-v1` (`--ff-only`) and push it;
- leave `wp6b/finance-reseller-reports-ux` at its accepted evidence HEAD;
- change nothing in `main`.

No further full validation, visual QA or route-matrix run was requested. WP7 is not started.

This file records that verdict and the WP6B owner decisions given during planning and review. The scope and notes below are the owner's verdict. Their technical detail is in `docs/WP6B_FINANCE_UX_MODEL.md` and `owner-review/WP6B/REPORT.md`.

## WP6B acceptance

| Item | Value |
|---|---|
| Owner status | **ACCEPTED_WITH_NOTES** |
| Accepted implementation | `bd3f6cf24d30564a967f433aa90db29ad089bb11` |
| Accepted evidence / branch HEAD | `20bcfcaf1d5023c4d4856f8b1a11cc649c4c1f27`. Branch `wp6b/finance-reseller-reports-ux`, on origin, unchanged. |
| Base | `81d492432ca6ae6e5f8ff67793542540001287df` (WP6A acceptance recorded) |
| `feat/reseller-system-v1` | fast-forwarded (`--ff-only`) to `20bcfcaf`, plus this metadata commit. No squash or rewrite. `main` (`500d3a2d`) unchanged. |
| Schema | none: no R0008. R0001–R0007, the migration runner and `install/*` are unchanged since `81d49243`. |
| Pinned files | The pinned `RouteMap` changed with owner authorization (OD-1) and was re-pinned (`d319647c…`). The WP6B surfaces are: `finance`, `pricing`, `operations`, `reseller/detail`, `activation/cancel` / `resume`, and the explicit reseller API list. `WalletService` received the owner-authorized additive `setDebtLimitIfCurrent` in fix round 2. `Package.php`, `Balance.php`, `Tx.php`, `ServiceGate.php` and `RadiusPolicyBuilder.php` are unchanged. |
| Capabilities | unchanged. The Report role received no new reseller-finance authority (OD-5). |
| API | no WP6B finance / pricing / operations route in `API_ALLOWED`. `API_ALLOWED['reseller']` is the explicit pre-existing list, so `reseller/detail` is not API-reachable. |

## Owner decisions applied in WP6B

From "WP6B OWNER REVIEW — PLAN ACCEPTED WITH AMENDMENTS / OWNER DECISIONS" and "FINAL PLAN ACCEPTED / IMPLEMENTATION GO", on `WP6B_PLAN.md` revision 2 (`owner-review/WP6B/PLAN.md` §28):

| Decision | Content |
|---|---|
| OD-1 | Option A with a strict boundary: controllers / templates → `Reseller\Backoffice` (gateway only: no finance / pricing SQL, no money arithmetic, no ledger posting, no duplicated pricing, no alternate authorization) → `Reseller\Finance` / `Reseller\Activation`. Finance reads are additive read models in `Reseller\Finance`. The WP3A / WP3B guards are amended to prove the boundary. No WP6B controller in `API_ALLOWED`. `API_ALLOWED['reseller']` was made explicit before any new reseller action. RouteMap change authorized and re-pinned; no capability change. |
| Slice-0 correction | The dead Reconcile button for VERIFIED and ROLLBACK_PENDING was removed: Reconcile only for RECONCILIATION_REQUIRED (and stale in-flight states), Rollback kept. A 17-state regression test proves no always-refused action is rendered. Recorded as a narrow WP6A UI defect found in WP6B reconnaissance. |
| OD-2 | Debt limit, Credit / Debit Adjustment and transfer reversal, Admin / SuperAdmin only, as visibly administrative accounting operations. Never "edit balance"; an adjustment never creates a wallet; only an existing reversible transfer is reversed. |
| OD-3 | Agent phone primary navigation: Home / Customers / + / Wallet / More. Sales and Admin keep the WP6A navigation. No empty destinations. |
| OD-4 | Pricing visibility exactly through `PricingReadModel` (no duplicated redaction). In-scope ledger reasons are not newly redacted. No upstream identities, margins or provider data for an Agent. |
| OD-5 | (A) The Report role gets nothing new: no `wallet.read`, `ledger.read`, `pricing.read`, `activation.read` or finance export. |
| OD-6 | Exports use the same capability, scope and query as the screen. Server-side, at most 20,000 rows and 366 days, streamed, audited with metadata only. `report.read` grants no finance export. |
| OD-7 | Narrow wrapper (`Reseller\Activation\ActivationOperatorService`): web reconcile / rollback / reverse / cancel / resume, Admin / SuperAdmin only. Cancel / resume only once stale (300 s), through the accepted primitives. HELD / CLAIMED / PRIOR_SECRET resolutions stay CLI / informational. |
| OD-8 | Operations Center gated by `system.admin`, no new capability. Viewing mutates nothing; each button maps to its own authorized operation. |
| OD-9 | Bounded pricing reads: additive bounded APIs, one tree lock per bounded page, results through `PricingReadModel`. |
| Also recorded | Read-model ownership rule; Operations Center categories; the tree phone defect fixed with drill-down cards; no schema (stop if one were required); R-15 not reopened. |
| Testing cadence | Focused tests per slice → one canonical full validation at the complete candidate → focused owner-fix tests → at most one final full run. Curated visual QA only. |

## Owner code-review fix rounds

| Round | Reviewed | Findings | Fix |
|---|---|---|---|
| Fix round 1 ("WP6B OWNER CODE REVIEW — FIX_REQUIRED") | `f7496710` (evidence `0c6f1768`) | FIX 1: the performance CSV silently truncated at 20,000 rows. FIX 2: the pricing catalog CSV had no 20,000-row preflight. FIX 3: the debt-limit review signed a `decrease` flag, so a stale review could become an unconfirmed decrease. | `22e534b2`, evidence `d89e73bc`. FIX 1 and FIX 2 **accepted**; FIX 3 partially fixed. |
| Fix round 2 ("FIX_REQUIRED ROUND 2") | `22e534b2` (evidence `d89e73bc`) | FIX 3 blocker: a concurrent change between the execute re-read and `WalletService::setDebtLimit`'s lock could still turn a reviewed increase into a decrease (R-13). | `bd3f6cf2`, evidence `20bcfcaf`. **Accepted; R-13 closed.** |

Both rounds were additive: no amend, squash or rebase. The defects were reproduced before the fixes (`owner-review/WP6B/FIX_ROUND_1_REPRODUCTION.txt`; the fix-round-2 interleaving test and its control run).

**ExportCap correction (fix round 1).** `Reseller\Finance\ExportCap` makes the authoritative scoped total known before any CSV byte:
- above 20,000 rows → `EXPORT_TOO_LARGE`, with zero bytes;
- exactly 20,000 is allowed;
- an export is never truncated.

The SQL export cursors count and stream inside one REPEATABLE READ snapshot, and the `$n < cap` cut-off was removed. The pricing catalog is pre-refused and bounded page by page (one tree lock per page).

**Debt-limit atomic expected-current correction (fix rounds 1–2).** The review binds the current limit it was classified against. The reviewed write is `WalletService::setDebtLimitIfCurrent(...)`. It compares the expectation:
1. inside `FinanceTx::run(..., true)`;
2. after `LockedWallet::lock(... FOR UPDATE)`;
3. before wallet creation, the debt-limit mutation and the audit.

A mismatch is `DEBT_LIMIT_CHANGED` → `REVIEW_EXPIRED`, and nothing is written. `setDebtLimit(...)` remains backward compatible, and `WalletService` keeps exactly one debt-limit UPDATE.

## Accepted scope
- Finance read pages: wallets, ledger, transfers, entries, scoped masking. Exports per OD-6.
- Provider funding / withdrawal and parent → child transfer: review → execute through the accepted `TransferService`, with HMAC-bound reviews and typed confirmations.
- Administrative accounting corrections (OD-2).
- Reseller workspace: the tree as a phone drill-down, reseller detail, subscriber owner / Sales filters.
- Pricing pages over bounded `PricingReadModel` projections and the accepted `PricingService` setters.
- Settlement card and finance reports: sales, commissions, provider settlement (Admin), performance.
- Operations Center and web cancel / resume (OD-7 / OD-8).
- Navigation groups, Agent Wallet primary navigation, quick actions, dashboard finance tiles. Arabic catalog (+305 strings) and WP6B template guards.

## Test evidence (accepted)

Canonical full validation on the candidate `f7496710` (the second and last full run; the first, on `a78ef1c3`, found 3 failing tests per engine, all fixed before the second):

| Check | Result |
|---|---|
| MariaDB | 866 tests, 64 626 assertions |
| MySQL | 866 tests, 64 626 assertions |
| Total | 1732 passed, 0 failed |
| Variant registry | reproducible |
| Route matrix | 1727 / 1354 / 373, identical on both engines (all 1477 WP6A rows unchanged; +250 WP6B rows; Sales / Report / unenrolled Agent denied on every new row) |
| PHP 8.2 lint | 482 / 482 |
| Manifest | 42 / 42 |
| Migrations | unchanged |
| Secret sweep | clean |

The owner fix rounds were validated with focused tests on both engines, as instructed:

| Round | MariaDB | MySQL |
|---|---|---|
| Fix round 1 | 143 + 9 tests | 143 + 9 tests |
| Fix round 2 | 211 tests / 41 819 assertions, 0 failures | 211 tests / 41 819 assertions, 0 failures |

Curated visual QA: 29 captures (desktop / phone at 390 and 360 px / tablet; Arabic RTL; Admin / Agent / Sales / Report). 0 axe serious / critical violations, no overflow, bottom navigation visible, no external requests or console errors. It remains valid because the fix rounds changed no visual layout.

## Accepted notes
- Large pricing-catalog exports near the 20,000-row cap may be operationally slow (minutes; one pricing evaluation per plan).
- Provider settlement keeps the existing RouteMap / read-model double gate: an Agent passes the `ledger.read` route rule and is refused by the read model (R-9).
- The Arabic copy is a drafted translation for owner language review. Visual QA used emulated devices only.
- WP6A R-15 remains unchanged and out of WP6B scope.
- Real NAS / MikroTik behaviour remains for WP8.
- Performance, load and concurrency stress beyond the accepted functional checks remains for WP9.
- The other documented residuals remain recorded in `owner-review/WP6B/REPORT.md` §I as part of the accepted evidence.
- **R-13 is CLOSED** (fix round 2) and is not carried forward.

## Not authorized by this decision
- No deploy.
- No change to `main`.
- No PR or tag.
- No WP7 implementation.
