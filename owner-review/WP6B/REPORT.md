# WP6B — Finance, Reseller, Pricing, Reports and Operations UX — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED.**
- Fix round 1 is applied (three narrow fixes, §C1). The owner accepted FIX 1 and FIX 2.
- Fix round 2 is applied: the remaining FIX 3 blocker, closed at the authoritative writer (§C2). It is waiting for the owner's review.
- Final state: `WP6B_FIX_ROUND_2_READY_WAITING_OWNER_REVIEW`.

- Plan: `WP6B_PLAN.md` revision 2, recorded in commit `5d3b6040`. It was accepted by "WP6B OWNER REVIEW — FINAL PLAN ACCEPTED / IMPLEMENTATION GO" (OD-1 … OD-9).
- Model: `docs/WP6B_FINANCE_UX_MODEL.md`.
- Test evidence:
  - `owner-review/WP6B/TESTDOX.txt` (final full run, `f7496710`);
  - `owner-review/WP6B/FIX_ROUND_1_TESTDOX.txt` (fix round 1, focused, both engines);
  - `owner-review/WP6B/FIX_ROUND_1_REPRODUCTION.txt` (pre-fix reproductions on `f7496710`);
  - `owner-review/WP6B/FIX_ROUND_2_TESTDOX.txt` (fix round 2, focused, both engines; it includes the interleaving reproduction and its control).
- Visual evidence: `owner-review/WP6B/VISUAL_QA.md` and `owner-review/WP6B/visual/`.

## A. Ancestry / branch / heads

| Item | Value |
|---|---|
| Accepted prior head (base) | `feat/reseller-system-v1` = `81d492432ca6ae6e5f8ff67793542540001287df` (WP6A acceptance recorded) |
| Branch | `wp6b/finance-reseller-reports-ux` from `81d49243` (linear; no squash, amend or rebase) |
| First full-validation candidate | `a78ef1c380394f3ae406f10852c478d6f40b1b25` (run 1: 3 failing tests per engine, §F) |
| Reviewed implementation (owner code review) | `f7496710b44d5c5d387765cb13194156c6bf77d5`, evidence `0c6f1768` — FIX_REQUIRED (three fixes, §C1) |
| Fix round 1 (reviewed) | `22e534b2b03a2010de007c4b67f1c33eb9fbc06e`, evidence `d89e73bc`: FIX 1 / FIX 2 ACCEPTED; FIX 3 one blocker (§C2) |
| **Implementation HEAD (fix round 2)** | `bd3f6cf24d30564a967f433aa90db29ad089bb11` |
| Evidence HEAD | the evidence commit `docs(wp6b): fix round 2 — report, focused testdox` (its SHA is in the final response) |
| Worktree | clean, apart from the ignored `tests/vendor/` |
| `main` / `feat` | untouched: `main` = `500d3a2d`, `feat/reseller-system-v1` = `81d49243` |

## B. Scope implemented (commits)

All commits are authored by `ALI HENDI <ali.hahuba3@gmail.com>` and carry the `Co-Authored-By` trailer.

| Commit | Content |
|---|---|
| `5d3b6040` | The accepted plan (revision 2) recorded first |
| `4a086ee0` | **Slice 0.** OD-1 boundary guards:<br>• `Wp6bBoundaryTest`; WP3A/WP3B guards amended narrowly.<br>• `API_ALLOWED['reseller']` `'*'` → the explicit existing list.<br>**WP6A defect correction:** no dead Reconcile for VERIFIED / ROLLBACK_PENDING. `ActivationOperatorService` provides the operation table and the 17-state regression test. |
| `b0385729` | **Slice 1.**<br>• `Finance/LedgerReadModel` (SELECT-only): wallets, ledger, transfers, masking.<br>• `Backoffice/WalletDesk`.<br>• `Backoffice/ExportStream`: capped, streamed, audited.<br>• Finance read pages. |
| `9d4e8cf9` | **Slice 2.** Provider funding / withdrawal and parent → child transfer, review → execute:<br>• `Finance/WalletPreview`;<br>• `Backoffice/ConfirmToken` (HMAC, per session);<br>• typed withdrawal confirmation;<br>• Arabic-Indic digits mapped losslessly. |
| `0042b4c6` | **Slice 3 (OD-2).** Admin-only accounting corrections: debt-line review, Credit / Debit Adjustment (never opens a wallet), and reversal of an existing reversible transfer (`TransferService::reversalPreview`). |
| `fbbeec47` | **Slice 4.** Reseller workspace:<br>• `Domain/ResellerReadService`;<br>• tree rewritten as a phone drill-down (no horizontal overflow, translated labels);<br>• reseller detail;<br>• subscriber owner / Sales filters. |
| `4b30e2c3` | **Slice 5 (OD-4 / OD-9).** Pricing pages over bounded `PricingService` reads:<br>• 25 plans / 50 managers per page;<br>• one tree lock per page;<br>• `PricingReadModel` projection. |
| `d815a1c6` | **Slice 6.** `Finance/SettlementReadModel` (SELECT-only):<br>• settlement card on the activation page;<br>• sales / commissions / provider settlement (Admin) / performance reports and exports. |
| `5c54244c` | **Slice 7 (OD-7 / OD-8).** Web cancel / resume (Admin, stale only) and the Operations Center (`system.admin`). |
| `7ce54680` | **Slice 8 (OD-3).**<br>• Navigation groups; Agent phone primary Wallet.<br>• Quick actions; dashboard finance tiles.<br>• 297 Arabic strings; `Wp6bTemplateGuardTest`.<br>• Docs and amendment notes. |
| `a78ef1c3` | Pre-validation visual-smoke fixes (`VISUAL_QA.md` §"Found and fixed") |
| `bd3f6cf2` | **Fix round 2:** the reviewed debt-limit write compares the reviewed current limit under the writer's own `FOR UPDATE` lock (`WalletService::setDebtLimitIfCurrent`, §C2) |
| `22e534b2` | **Fix round 1 (owner code review of `f7496710`):** export caps never truncate (performance, pricing catalog, shared `ExportCap`); a stale debt-line review fails closed (§C1) |
| `f7496710` | Candidate-validation fixes:<br>• schema-not-ready navigation;<br>• WP5A economics assertion excludes the shell navigation (§F, §E). |

## C1. Fix round 1 (owner code review of `f7496710`, evidence `0c6f1768`: FIX_REQUIRED, three narrow fixes)

No redesign. No schema, capability, finance / pricing / hierarchy semantics or activation transition changed. RouteMap is unchanged (the pin is still `d319647c…`), and no template layout changed. Commits are additive only.

| Fix | Defect on `f7496710` (reproduced, `FIX_ROUND_1_REPRODUCTION.txt`) | Change |
|---|---|---|
| **1. Performance CSV truncation** | `SettlementDesk::export('performance')` paged the screen read and stopped at `EXPORT_MAX_ROWS`. With 20,001 Agents in scope it answered **HTTP 200 text/csv with the first 20,000 rows**. | New authoritative cursor `SettlementReadModel::exportPerformance`: the same scope / query / metrics as the screen (shared private `performanceRows`), in the screen order. The scoped count is read **before any output**: above 20,000 → `EXPORT_TOO_LARGE`, zero CSV bytes, no export audit. Exactly 20,000 → complete. No truncation guard exists any more. The gateway only delegates. |
| **2. Pricing catalog export without a cap** | `PricingDesk::exportCatalog` walked every page with no preflight. With 20,001 resellable plans it was **still streaming CSV** (331 KB) when the test client timed out at 60 s. | Preflight with `ExportCap::assertWithin($first['total'])`. The total comes from the first bounded page, i.e. the same tree-locked `PricingReadModel` read as the screen. Above the cap → refused before any byte. Then **exactly the preflight's pages**, one tree lock per page (never an unbounded lock). `PAGE_PLANS` (25) divides the cap, so the stream can never exceed 20,000 rows. |
| **3. Debt-limit review staleness** | The review signed a `decrease` flag. Reproduction: limit 50 → review 100 (an increase, no typed confirmation) → limit changed to 200 by the accepted service → the old review was submitted → **the limit became 100.00 (a decrease) with no typed confirmation**. | The review binds the authoritative **current limit** it was classified against (`current`; no `decrease` field any more). Execute re-reads the limit through the accepted read path (`WalletPreview::debtLimit` → `WalletService::view`). If it differs from the bound value → `REVIEW_EXPIRED`: nothing written, a fresh review required. The typed confirmation is required whenever the new value is a decrease **against the current limit** (server-derived, never from the browser or the signed review). `WalletService::setDebtLimit` remains the only writer. |

**Shared primitive.** `Reseller\Finance\ExportCap` (new; it holds no scope or query):
- `MAX_ROWS` = 20,000 and `MAX_DAYS` = 366. `LedgerReadModel::EXPORT_MAX_*` now alias them.
- `assertWithin(total)`: refused above the cap; exactly the cap passes.
- `snapshot($pdo, count, stream)`: count and stream inside **one read-only REPEATABLE READ snapshot**, so the streamed set is exactly the counted set. Consistent reads take no locks, and it refuses to run inside another transaction.

All five existing SQL cursors move onto it (`exportWallets`, `exportLedger`, `exportTransfers`, `exportLegs`, `exportSettlement`). Each already counted before output. The audit finding was that their stream loops still stopped at `$n < EXPORT_MAX_ROWS`, so a row committed between the count and the stream could have pushed the export to the cap and cut it off silently. That cut-off is now impossible, and the loops have no cap condition.

**Export audit (every `ExportStream::csv()` producer, enforced by `Unit/Wp6b/ExportCapGuardTest`):**

| Export | Producer | Cap |
|---|---|---|
| wallets | `LedgerReadModel::exportWallets` | count + stream in one `ExportCap` snapshot; refused above 20,000 |
| ledger | `LedgerReadModel::exportLedger` (date range ≤ 366 days) | same |
| transfers | `LedgerReadModel::exportTransfers` (date range ≤ 366 days) | same |
| sales / commissions | `SettlementReadModel::exportLegs` (date range ≤ 366 days) | same |
| provider settlement | `SettlementReadModel::exportSettlement` (Admin, date range ≤ 366 days) | same |
| performance | `SettlementReadModel::exportPerformance` (date range ≤ 366 days) | same (**fix 1**) |
| pricing catalog | `PricingDesk::exportCatalog` (bounded tree-locked pages) | `assertWithin` before output; at most the preflight's pages × 25 ≤ 20,000 (**fix 2**) |

**Other review flows checked for a state-derived risk direction (fix 3 scope):**
- Provider funding / withdrawal: the operation is the user's explicit, signed choice; withdrawal is always typed.
- Transfer to child and credit / debit adjustment: explicit signed operation; debit is always typed.
- Transfer reversal: always typed.

Only the debt line derived its risk direction from mutable state, so nothing else changed.

**New / changed tests:**
- `Integration/Wp6b/ExportCapTest`, real data at the boundary:
  - performance and wallets with exactly 20,000 Agents in scope (complete CSV, 20,000 rows) and 20,001 (302, no `text/csv`, no BOM, no attachment, no export audit row);
  - the pricing catalog: 60 plans over 3 pages (all rows, once); 20,001 plans refused before output; exactly 20,000 plans complete.

    The 20,000 catalog run goes through the application context: at about 10 ms per evaluated plan it takes minutes, longer than the HTTP test client's 60 s.
- `Unit/Wp6b/ExportCapGuardTest`: every Finance export cursor uses `ExportCap::snapshot` with no cap cut-off; every producer is such a cursor or the pre-refused catalog; `PAGE_PLANS` divides the cap.
- `Integration/Wp6b/FinanceCorrectionTest::testAStaleDebtLineReviewNeverChangesItsRiskDirection`, the owner's reproduction:
  - the stale review (also with a typed confirmation or a forged `decrease`) → `REVIEW_EXPIRED`, the limit stays 200, and nothing is written (no wallet / ledger / transfer / audit change);
  - a tampered `current` is refused by the token;
  - a fresh review is a decrease with the typed username, then applied;
  - a normal increase needs no typed confirmation and is applied;
  - no ledger or transfer row.

**Files changed in fix round 1** (`git diff --stat 0c6f1768..22e534b2`: 11 files, +498 / −97):
- new: `system/autoload/Reseller/Finance/ExportCap.php`, `tests/Integration/Wp6b/ExportCapTest.php`, `tests/Unit/Wp6b/ExportCapGuardTest.php`;
- modified: `system/autoload/Reseller/Backoffice/{ExportStream, PricingDesk, SettlementDesk, WalletDesk}.php`, `system/autoload/Reseller/Finance/{LedgerReadModel, SettlementReadModel}.php`, `system/controllers/finance.php` (the debt-line execute fields: `current` instead of `decrease`), `tests/Integration/Wp6b/FinanceCorrectionTest.php`;
- not changed: RouteMap (pin `d319647c…`), capabilities, schema / migrations, the pinned services (incl. `WalletService`, `TransferService`, `PricingService`, `PricingReadModel`), templates and CSS.

**Focused validation (owner cadence: no third full run)** on the fix tree (= `22e534b2`). Dev host, isolated `nuxbill-reseller-test` project, 2026-10-05T03:36Z – 03:51Z (`FIX_ROUND_1_TESTDOX.txt`):

| Run | MariaDB 10.11 | MySQL 8.0 |
|---|---|---|
| `--testsuite wp6b-unit,wp6b-integration,wp3a-unit,wp3b-unit` (the WP6B boundary / template / export guards, `ExportCapTest`, `SettlementReportTest`, `FinanceReadTest`, `FinanceMutationTest`, `FinanceCorrectionTest`, `PricingUxTest`, `OperationsCenterTest`, …; WP3A `SourceGuardTest`, WP3B `PricingSourceGuardTest` and finance unit tests) | **OK (143 tests, 39 724 assertions)** | **OK (143 tests, 39 724 assertions)** |
| `--filter 'AppSurfaceTest\|RouteMapTest'` (finance API isolation, RouteMap) | **OK (9 tests, 1 815 assertions)** | **OK (9 tests, 1 815 assertions)** |

- PHP 8.2 lint: all changed PHP files are clean.
- The secret sweep of `0c6f1768..22e534b2` is clean.
- The route matrix was not re-run: RouteMap is unchanged.
- Visual QA was not repeated: no template, layout or CSS change. The debt-line review form carries the same hidden-field mechanism (`current` instead of `decrease`).

## C2. Fix round 2 (owner code review of `22e534b2`, evidence `d89e73bc`: FIX 1 / FIX 2 ACCEPTED; FIX 3 one blocker)

**Blocker.** The fix-round-1 execute re-read the debt limit and only then called `WalletService::setDebtLimit()`, which takes its own locks. A change committed between that re-read and the writer's `FOR UPDATE` lock could still turn a reviewed increase into an unconfirmed decrease.

**Fix (owner-authorized, narrow change of `system/autoload/Reseller/Finance/WalletService.php`):**

| Item | Detail |
|---|---|
| New additive API | `WalletService::setDebtLimitIfCurrent(ActorContext $a, mixed $managerId, mixed $newLimit, mixed $expectedCurrent, mixed $reason): WalletView` |
| Single writer | `setDebtLimit()` and `setDebtLimitIfCurrent()` both delegate to one private locked writer, `writeDebtLimit()`. It holds the former `setDebtLimit` body unchanged, plus one condition. There is still exactly one debt-limit `UPDATE`. |
| Where the comparison runs | Inside `FinanceTx::run(…, true)`, i.e. the tree lock plus one transaction:<br>1. `revalidateActor`;<br>2. `WalletOwner::assertValid`;<br>3. `LockedWallet::lock($pdo, $id)` (`FOR UPDATE`);<br>4. **the comparison**: the locked current limit (`0.00` for an absent wallet) must equal `$expectedCurrent`, else `DEBT_LIMIT_CHANGED`;<br>5. only then the accepted steps: wallet creation for a positive limit, reserved exposure, no-op, `DEBT_LIMIT_EXPOSURE`, the guarded `UPDATE`, the `finance.debt_limit` audit. |
| Refusal | `DEBT_LIMIT_CHANGED` (new `DomainError` reason, a concurrency result, not a business rule) is thrown before any write. The transaction rolls back: no wallet change or creation, no ledger entry, no transfer, no audit event. |
| Expectation mandatory | The conditional variant validates `$expectedCurrent` like any amount; a missing / malformed value is `INVALID_AMOUNT`. The conditional API can never act unconditionally. |
| Unconditional callers | `setDebtLimit()` behaviour is unchanged for every existing caller: WP3A services, tests and workers. Its pinned WP3A tests (`WalletTest`, `ConcurrencyTest`, `FailureInjectionTest`, …) are green, and the control run below shows it unchanged. |
| Web path | `WalletDesk::executeDebtLimit` keeps the fix-round-1 pre-read, which now serves only presentation and the typed confirmation. It writes through `setDebtLimitIfCurrent(…, $fields['current'], …)` and reports `DEBT_LIMIT_CHANGED` as `REVIEW_EXPIRED`, a stale review that needs a fresh review. No Backoffice code calls the unconditional writer any more. |

**Proof the comparison occurs under the locked transaction** (`Integration/Wp6b/DebtLimitReviewedWriterTest::testTheExpectationIsComparedUnderTheWritersOwnLocks`, deterministic):
1. The limit is 50.
2. Another accepted `setDebtLimit(200)` runs in a worker and pauses at the existing fault point `debt_limit.after_update`, holding the tree lock and the locked, updated wallet row, uncommitted.
3. The committed limit is asserted to be still **50**, which is what any pre-read would see.
4. The reviewed writer (expected 50, new 100) starts in a second worker and is verified to be blocked on the locks.
5. The first worker is released and commits 200. The reviewed writer then acquires the locks, sees 200 and refuses with `DEBT_LIMIT_CHANGED`. The limit stays 200, with no audit row for it and no ledger row.

**Control (the pre-fix gap, reproduced):** the same interleaving with the unconditional `setDebtLimit` (what the `22e534b2` web path called after its pre-read) writes **100**. That is the reviewed increase silently applied as a decrease.

**Other new regression cases:**
- On the writer, no Backoffice involved: 50 → 200 by another accepted operation, then `setDebtLimitIfCurrent(100, expected 50)` → refused, the limit stays 200, and nothing is written anywhere (database checksums; no audit, ledger or transfer).
- An absent wallet with a different expectation → refused, no wallet created. Expected `0.00` → the accepted rules open it.
- Matching expectations:
  - an increase is applied and audited;
  - the exposure floor (`DEBT_LIMIT_EXPOSURE`) is identical for both variants;
  - a confirmed decrease to the exact floor is applied;
  - a no-op writes nothing;
  - capability (`FORBIDDEN` for an Agent) and mandatory expectation (`INVALID_AMOUNT` for null / empty / malformed / negative / non-string).
- A source contract: the web execute uses the conditional writer and maps its refusal to `REVIEW_EXPIRED`. There is a single debt-limit `UPDATE`, and in `writeDebtLimit` the comparison follows the `FOR UPDATE` lock and precedes wallet creation, the exposure rule, the `UPDATE` and the audit.
- The web flows in `FinanceCorrectionTest` (the stale review, the confirmed decrease, the normal increase, the exposure floor) are unchanged and green.

**R-13 is CLOSED** (not carried forward).

**Files changed in fix round 2:**
- `system/autoload/Reseller/Finance/WalletService.php` (owner-authorized; additive API + shared private writer)
- `system/autoload/Reseller/Domain/DomainError.php` (+ `DEBT_LIMIT_CHANGED`)
- `system/autoload/Reseller/Backoffice/WalletDesk.php` (execute uses the conditional writer)
- `tests/Support/finance_worker.php` (+ `debt_limit_if_current` worker operation)
- `tests/Integration/Wp6b/DebtLimitReviewedWriterTest.php` (new)
- `docs/WP3A_FINANCE_MODEL.md` (WP6B fix round 2 amendment)
- `docs/WP6B_FINANCE_UX_MODEL.md`

Not changed: schema / migrations, capabilities, RouteMap, `LockedWallet`, `FinanceTx`, the tree lock, the exposure rule, audit semantics, the export work.

**Focused validation (owner cadence: no full suite, no visual QA, no route matrix; RouteMap unchanged)**
- Tree: the fix tree (= `bd3f6cf2`).
- Command: `--testsuite wp6b-unit,wp6b-integration,wp3a-unit,wp3a-integration --testdox`.
- It includes `DebtLimitReviewedWriterTest`, `FinanceCorrectionTest`, WP3A `WalletTest` / `ConcurrencyTest` / `FailureInjectionTest` / `AppSurfaceTest` / `SourceGuardTest`, `Wp6bBoundaryTest` and the export tests.
- Dev host, isolated `nuxbill-reseller-test` project, 2026-10-05T04:05Z – 04:32Z (`FIX_ROUND_2_TESTDOX.txt`).

| Engine | Result |
|---|---|
| MariaDB 10.11 | **OK (211 tests, 41 819 assertions)**, 0 failed |
| MySQL 8.0 | **OK (211 tests, 41 819 assertions)**, 0 failed |

PHP 8.2 lint on the changed PHP files is clean, and the secret sweep of `d89e73bc..bd3f6cf2` is clean.

## C. Files changed

`git diff --stat 81d49243..f7496710`: **89 files, +7 931 / −208** (59 added, 30 modified).

**New runtime**
- `system/autoload/Reseller/Backoffice/{WalletDesk, PricingDesk, SettlementDesk, ResellerDesk, OperationsDesk, ExportStream, ConfirmToken}.php`
- `system/autoload/Reseller/Finance/{LedgerReadModel, SettlementReadModel, WalletPreview}.php`
- `system/autoload/Reseller/Activation/ActivationOperatorService.php`
- `system/autoload/Reseller/Domain/ResellerReadService.php`
- `system/controllers/{finance, pricing, operations}.php`
- templates `ui/ui/admin/finance/*` (15), `pricing/*` (3), `operations/*` (6), `reseller/detail.tpl`, `nuxr/{wallet-money, list-pager, ledger-rows, transfer-rows, pricing-row, settlement-card}.tpl`

**Modified runtime**

| File | Change |
|---|---|
| `Security/RouteMap.php` | **Pinned; re-pinned** to `d319647c…`. New `finance` / `pricing` / `operations` routes, `reseller/detail`, `activation/cancel` / `resume`, explicit reseller API list. |
| `Finance/TransferService.php` | + `reversalPreview()` (read). The compensation computation was extracted into a private helper shared with `reverse()`; behaviour is unchanged (WP3A reversal suites green). |
| `Finance/PricingService.php` | + bounded reads `catalogForManager` / `matrixForPlan` / `baseCosts` / `relation` |
| `Activation/ActivationStore.php` | + `stalePage` / `staleCount` (reads) |
| `Activation/ActivationReadService.php` | + `references()` and the `settlement` block of `view()` (additive reads) |
| `Domain/DomainError.php` | + `EXPORT_RANGE_REQUIRED`, `EXPORT_TOO_LARGE`, `REVIEW_EXPIRED`, `CONFIRMATION_MISMATCH` |
| `Ui/{Navigation, Format}.php` | Navigation groups / primary / quick actions; ledger / transfer type labels, party masking, CSV cells |
| `controllers/{activation, reseller, customers, dashboard}.php` | Activation actions / cancel / resume; tree via read service + detail; owner / Sales filters; finance tiles |
| `ui/ui/admin/{activation/view, reseller/tree, customers/list, dashboard}.tpl` | Action rendering, settlement card, tree drill-down, filters, finance tiles |
| `ui/ui/styles/nuxr-shell.css` | Drill-down, finance and sub-line link rules |
| `ui/ui/scripts/custom.js` | Legacy radio handler limited to the recharge form (§D) |
| `system/lan/arabic.json` | +305 strings |

**Docs**
- New: `docs/WP6B_FINANCE_UX_MODEL.md`.
- WP6B amendment notes in `docs/{WP2_PERMISSION_MODEL, WP3A_FINANCE_MODEL, WP3B_PRICING_MODEL, WP5A_ACTIVATION_MODEL, WP6A_UX_MODEL}.md`.

**Tests**
- New: `tests/Unit/Wp6b/*` (2 classes) and `tests/Integration/Wp6b/*` (9 classes); 38 tests.
- `tests/phpunit.xml`: `wp6b-unit` and `wp6b-integration` suites.

**Pinned / untouched since `81d49243`** (verified, empty diff):
- `Package.php`, `Balance.php`, `Domain/Tx.php`, `Domain/ServiceGate.php`, `Radius/RadiusPolicyBuilder.php`;
- the migration runner (`system/reseller/Migration/*`), R0001–R0007, `install/*`;
- `Finance/{LedgerService, ReservationService, LockedWallet, Money, FinanceTx, FinanceInput, PricingReadModel, PricingSnapshot, WalletOwner}`; `Finance/WalletService` was untouched until fix round 2, which made the owner-authorized additive change in §C2;
- `Activation/{ActivationService, ReconciliationService, ActivationReversalService, DriftReport}`;
- `Domain/{LegacyCustomerBalanceService, VoucherRedemptionService, LegacyReprovisionService, AutoRenewPolicy, ResellerTreeService, StaffService, CustomerService}`;
- `Security/{Capability, RoleCapabilityMap, PermissionService, DataScope}` (**no capability change**).

## D. Important design notes

- **Boundary (OD-1).** Controllers / templates → `Reseller\Backoffice` → `Finance` / `Activation` / `Domain`. Proven by `Wp6bBoundaryTest`:
  - Backoffice: no SQL, `tbl_*`, `Tx` / ORM / `FinanceTx`, Money arithmetic, ledger posting, reservations, `LockedWallet` / `resolveInTx`, superglobals, floats or network.
  - Thin controllers.
  - SELECT-only finance read models.
  - One tree lock per pricing page.
  - Exact reseller API list.
  - The amended WP3A / WP3B guards admit exactly the WP6B surface.
  - Templates never name finance services.
- **Every money mutation is the accepted service:** `TransferService::{fund, withdraw, transferToChild, setDebtLimit, adjust, reverse}`, the `PricingService` setters, and the activation primitives. Review → execute is UI integrity only (HMAC token; typed confirmations); the services still authorize and validate everything.
- **Visibility.**
  - `PricingReadModel` exactly (OD-4). An Agent never sees the provider base cost, provider settlement or upstream identities / costs / margins.
  - Ledger parties outside the viewer's subtree are role labels.
  - In-scope reasons are shown.
  - Report / Sales get nothing new (OD-5).
- **Exports (OD-6):** the same capability / scope / query as the screen; ≤ 20 000 rows, ≤ 366 days; streamed; one `export.finance` audit row with metadata only.
- **Operations (OD-7 / OD-8).**
  - Cancel / resume only once stale (≥ 300 s; a fresh row gets `ACTIVATION_IN_PROGRESS` and nothing is written).
  - HELD / CLAIMED / PRIOR_SECRET resolutions stay CLI / informational.
  - Uncertain outcomes stay uncertain.
  - Drift runs on demand only.
- **Navigation (OD-3).** Agent primary is Home / Customers / New / **Wallet** / More; Admin / Sales / Report are unchanged. An unenrolled Agent keeps WP6A.
- **Legacy `custom.js`.** It posted `autoload/server` (no CSRF → 403, nothing written) on *any* radio change on any page, e.g. choosing the child on the send form. It now runs only where the legacy `#server` select exists. The legacy recharge / voucher forms are unchanged.

## E. Migrations / data impact

**None.** No schema change, migration or R0008 (stop condition not reached). R0001–R0007 and the migration runner are unchanged since `81d49243`.

**Accepted tests changed:**

| Test | Change | Authority |
|---|---|---|
| `Unit/Wp3a/SourceGuardTest` | Finance callers = `Finance/`, `Activation/`, `Backoffice/`; exact `finance` action allow-list; `finance` not in `API_ALLOWED`; RouteMap pin re-set (`d319647c…`) | OD-1, plan §20 |
| `Unit/Wp3b/PricingSourceGuardTest` | Exact WP6B pricing / report routes; `pricing.*` rules only on `pricing`; read-only display files admitted for the settlement literal (never-writer asserted); `SettlementReadModel` in the activation allow-list | OD-1, plan §20 |
| `Integration/Wp3a/AppSurfaceTest` (P7) | API GET+POST denied for every WP6B action, `finance`, `reseller/detail`, `activation/cancel`/`resume`; unmapped finance names denied; reseller alias pages carry no wallet / ledger / debt text; no finance-table writes | OD-1, plan §20 |
| `Integration/Wp6a/ShellNavigationTest` | Agent primary `Wallet` (was `Activations`); the "no wallet" assertion is kept for Sales / Report | OD-3, plan §20 |
| `Integration/Wp6a/DashboardTest` | Finance words only inside the finance tiles (wallet.read holders); none for Sales | OD-3, plan §20 |
| `Integration/Wp5a/ActivationHttpTest` | **Not pre-listed in §20 (deviation D-1).** The "no upstream economics" regex now strips the shell navigation (`nav` / `aside` / `dialog`), exactly as the DashboardTest change. Reason: the Agent navigation lists its own Commissions report (OD-3); the page content is still checked. | OD-3 |
| `tests/phpunit.xml` | `wp6b-unit`, `wp6b-integration` suites | plan |

`Http/HierarchyTest` needed no change: the tree keeps its pinned markup.

## F. Test commands and exact results

**Focused, per slice** (owner cadence, `devloop.sh run <engine> …`, isolated `nuxbill-reseller-test` project; new tests also on MySQL before each commit). Examples:
- `wp3a` suites 183/183;
- `wp3b` + `wp6b` 125/125;
- `wp6a-integration` + `wp6b` + `wp5a-integration` 128/128;
- guards + route matrix 54/54;
- slice 7: 62/62 + `wp5a-integration` / `wp6a-integration` 100/100;
- slice 8: 64/64 (MariaDB), 29/29 (MySQL).

All green.

**Full validation run 1** (first complete candidate `a78ef1c3`, 2026-10-04T22:14Z – 23:44Z):
- MariaDB and MySQL each 866 tests, **1 error + 2 failures** per engine:
  - `Http/SchemaNotMigratedTest` ×2: the Agent dashboard answered 500 with the reseller schema not migrated. The new "has an active child reseller" navigation check queried `tbl_manager_tree` before any capability check.
  - `Wp5a/ActivationHttpTest` "no upstream economics": the regex also read the new Agent navigation label "Commissions".
- Route matrix and lint were green.
- Fixed in `f7496710`:
  - the check now runs only after the schema-dependent `wallet.read`;
  - D-1, as in §E.
- Focused re-run: `SchemaNotMigratedTest | ActivationHttpTest | FinanceNavigationTest | ShellNavigationTest | DashboardTest` 15/15 on both engines.

**Final canonical full validation (run 2, the last allowed), on `f7496710`.** Dev host, isolated compose project `nuxbill-reseller-test` only (PHP 8.2.34, MariaDB 10.11, MySQL 8.0, FreeRADIUS 3.2.8 test containers), 2026-10-04T23:51Z – 2026-10-05T01:05Z.

| Check | Result |
|---|---|
| `dev/test/run-tests.sh --testdox` | MariaDB **OK (866 tests, 64 626 assertions)**; MySQL **OK (866 tests, 64 626 assertions)**; **1732 ✔, 0 ✘** (`TESTDOX.txt`); exit 0 (65 m 19 s). WP6A had 828; the +38 are exactly the new WP6B tests. |
| Variant registry reproducibility | matches freshly built fixtures |
| Route matrix (`RouteMatrixTest`, `WP2_EVIDENCE=1`) | **1727 requests / 1354 denials / 373 allowed**, identical on both engines and byte-identical to run 1 (§H) |
| PHP 8.2 lint | 482 / 482 (all tracked PHP outside `tests/vendor` and `system/vendor`; WP6A 456 + 26 new files) |
| Manifest | 42 / 42 |
| R0001–R0007, runner | unchanged since `81d49243` |
| Pinned files (§C) | empty diff since `81d49243` |
| Secret sweep | `git diff 81d49243..f7496710` clean: no credentials, keys or tokens; the only long hex literal is the RouteMap pin hash |
| Shared host | observe-only sampler: max load 8.13, min MemAvailable 40 993 MiB. The AVAIA / other container set (24 containers) is identical before and after, including status, health, restart count and start time. No AVAIA container was touched. Test and lab containers were removed (0 `nuxbill` containers left). |

**Curated visual QA** (`VISUAL_QA.md`), lab, on the validated runtime `f7496710` (runtime files synced from the candidate tree):
- 29 captures: 14 desktop, 12 phone (each re-measured at 360 px), 3 tablet. Arabic RTL; Admin / Agent / Sales / Report.
- **0 axe serious / critical**, 0 overflow at 390 px and 360 px, bottom navigation fully visible on every phone page, 0 external requests, 0 console errors.
- The pre-validation smoke findings were fixed in `a78ef1c3`.

## G. Security / failure cases tested (new tests, 38)

| Test | Cases |
|---|---|
| `Wp6bBoundaryTest` | Reseller API list exact; Backoffice gateway-only; thin controllers; finance read models read-only; one tree lock per pricing page |
| `Wp6bTemplateGuardTest` | Every variable / dynamic label escaped in the WP6B templates (only `csrf_token` / `app_url` raw); no `window.confirm`; every WP6B string (templates, gateway labels, CSV headers, type labels, new refusal messages) has an Arabic entry |
| `ActivationActionMatrixTest` | All 17 states fresh and stale render exactly the accepted operations; an Agent gets none; Reconcile on VERIFIED / ROLLBACK_PENDING is refused and writes nothing; drift guard: the operation table equals the primitives' source preconditions (reconcile / rollback / reverse / cancel / execute) |
| `FinanceReadTest` | Scopes follow `wallet.read` / `ledger.read`; identities above the viewer are role labels, in-scope reasons stay; exports equal the screen, are capped and audited without contents; read pages write nothing |
| `FinanceMutationTest` | Funding review → execute → replay; tampered review refused before any service call; withdrawal needs the typed username; an Agent sends only from its own wallet to a direct child; roles without the capability denied |
| `FinanceCorrectionTest` | Debt-line review, typed decrease and exposure floor; explicit adjustments never open a wallet; only an existing reversible transfer can be reversed; corrections are Admin-only |
| `PricingUxTest` | Agent sees only its `PricingReadModel` projection (sentinels 10.37 / 12.11 / 15.53 never leak upward); the accepted setters stay authoritative; Admin base cost / resale / retail warnings |
| `SettlementReportTest` | Settlement card per role (Admin all + conservation; parent its commission; seller its debit); reports and CSV follow the same scopes; provider settlement Admin-only |
| `ResellerWorkspaceTest` | Tree scope, drill-down, no money (API-reachable tree); detail scoped and masks accounts above the viewer; subscriber filters only narrow the scope |
| `OperationsCenterTest` | Admin-only; cancel refused while fresh (nothing written), Agent refused, cancels once stale (reservation RELEASED, audited); resume completes a stale activation (COMMITTED, audited); an attention item is never listed twice; operator-review items offer no action; informational pages carry no action / POST form |
| `FinanceNavigationTest` | Destinations follow the role (Sales / Report / unenrolled Agent get none); the Reseller Tree is out of Settings; primary navigation and quick actions; the Agent "Ledger" is its own; dashboard finance tiles are role-specific |

## H. Route matrix delta

Against the WP6A accepted matrix (**1477** requests / 1185 denials / 292 allowed): all 1477 rows are unchanged (0 removed, 0 changed) and **250 rows were added** (finance 160, pricing 42, operations 35, reseller/detail 7, activation cancel/resume 6). The total is **1727 / 1354 / 373**, identical on both engines.

- `sa` / `ad`: ALLOW on every new GET/POST rule they hold (25 rows each).
- Agent `A`: ALLOW on the `wallet.read` / `ledger.read` / `pricing.read` / `wallet.transfer` / `reseller.tree_read` GETs (16). DENY on every Admin-only route (19).
  - `finance/settlement` passes the route rule (`ledger.read`) but the read model requires G (`SettlementReportTest` asserts the redirect).
- Agent `F` (frozen ancestor): the reads (15) are allowed; transfers are denied.
- Sales `A1`, Report `rp`, unenrolled Agent `E`: **DENY on all 40–42 new rows.**

## I. Known risks / residuals

| ID | Residual |
|---|---|
| R-1 | Reseller tree: `StaffService::canTarget` is evaluated per node for the status actions (N small queries per tree page). |
| R-2 | Operations Center "Waiting / held" and "Auto-renew not available" read the accepted global readers and page them in PHP (bounded output, unbounded read). |
| R-3 | The drift check is heavy (all reseller-owned customers; RADIUS opt-in) and runs on demand only. |
| R-4 | The Arabic copy (+305 strings) is a drafted translation for the owner's language review. |
| R-5 | Visual QA used emulated Chromium devices only; no physical phone / tablet, Safari or Firefox. |
| R-6 | Ledger / report date filters are UTC calendar days (labelled "(UTC)"). |
| R-7 | `PricingReadModel` reasons may name an upstream cause category (accepted projection; no identity or amount). |
| R-8 | Cancel / resume are offered only after 300 s without progress (`STALE_SECONDS`), by design, so an operator never races a running request. |
| R-9 | `finance/settlement` is route-allowed for an Agent (`ledger.read`) and refused by the read model (redirect), not by the RouteGuard. |
| R-10 | Legacy jQuery / Bootstrap / AdminLTE stay (no framework change); legacy templates outside WP6A / WP6B still print unescaped variables. |
| R-12 | Fix round 1: a pricing catalog export at the 20,000 cap takes minutes (one pricing evaluation per plan, about 10 ms; measured in `ExportCapTest`). A web server execution time limit shorter than that could end the stream early. The catalog's real size is the number of resellable plans; recorded, not changed this round. |
| R-13 | **CLOSED in fix round 2** (§C2). The reviewed debt-limit write compares the reviewed current limit with the locked row inside `WalletService` (`setDebtLimitIfCurrent`). A concurrent change before the writer's lock is refused with nothing written. Not carried forward. |
| R-11 | Known harness flakes are unchanged: the AppServer port-reuse flake and the CliOnlyTest healthcheck race. Neither occurred in the final run. |

**Deviations from the plan text:**
- **D-1:** the extra accepted-test change in `Wp5a/ActivationHttpTest` (§E).
- **D-2:** `ActivationReadService` (listed in plan §21 as "behaviour, reused") gained additive reads only (`references()`, the `settlement` block of `view()`); no existing output changed.
- **D-3:** `TransferService` compensation logic was extracted into a private helper to serve `reversalPreview()` (no behaviour change).
- **D-4:** the legacy `custom.js` guard (§D).
- **D-5:** visual QA has 29 captures instead of "~21 + 3", to cover the Operations Center, the cancel dialog and the Sales role.

**Stop conditions:** none encountered. No schema change, no capability change, no new money rule, no framework, and no pinned-file change beyond the authorized RouteMap.

**Defect correction recorded:** WP6A rendered Reconcile for VERIFIED and ROLLBACK_PENDING, which the backend always refused. This was found in WP6B reconnaissance and corrected in slice 0 under owner instruction.

## J. Production status

Not deployed. No production system or AVAIA container was touched.

## K. Push status

Only `wp6b/finance-reseller-reports-ux` is pushed (normal, non-force). `feat/reseller-system-v1` (`81d49243`) and `main` (`500d3a2d`) are untouched. No PR, merge, tag or deploy. WP7 is not started.

## L. Owner-review artifact path

`owner-review/WP6B/`:
- `PLAN.md` (the accepted plan, first commit)
- `REPORT.md`
- `TESTDOX.txt` (final full run, `f7496710`)
- `FIX_ROUND_1_REPRODUCTION.txt`
- `FIX_ROUND_1_TESTDOX.txt`
- `FIX_ROUND_2_TESTDOX.txt`
- `VISUAL_QA.md`
- `visual/`: 29 PNG, `visualqa.json`, `visualqa.py`

`docs/WP6B_FINANCE_UX_MODEL.md` and the WP6B amendment notes in the WP2 / WP3A / WP3B / WP5A / WP6A model docs.

## M. Final state

`WP6B_FIX_ROUND_2_READY_WAITING_OWNER_REVIEW`
