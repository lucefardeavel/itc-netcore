# WP6C — System-wide Mobile-First Adaptive UX Hardening ("One System, Two UX Modes") — Owner review report

Candidate for owner review. **Status: WP6C_CANDIDATE_READY_WAITING_OWNER_REVIEW.** This is not self-accepted.

> **Fix round 1 addendum (§K).** The owner review "FIX_REQUIRED ROUND 1" (on `cd7b43c0` / `9cb22105`) was answered by `105d567b` and `44505d59`. The owner then accepted the fix-round-1 code ("FIX ROUND 1 CODE ACCEPTED / FINISH EVIDENCE ONLY").
> - Final implementation: `44505d59`.
> - Sections A–J below are the original candidate report, kept unchanged.
> - **Current status:** WP6C_FIX_ROUND_1_READY_WAITING_OWNER_REVIEW.

| Item | Value |
|---|---|
| Implementation SHA (candidate) | `cd7b43c0` (the full validation ran on `8ffbf54a`; `cd7b43c0` = `8ffbf54a` + the regression fix in §G) |
| Evidence SHA | the commit that adds this report (evidence only, on top of `cd7b43c0`) |
| Branch | `wp6c/mobile-adaptive-ux` (pushed; it is the only branch pushed) |
| Base | `1148559d` (`feat/reseller-system-v1`, WP6B accepted) |
| `feat/reseller-system-v1` | `1148559d`, unchanged |
| `main` | `500d3a2d`, unchanged |
| Plan / decisions | `owner-review/WP6C/PLAN.md` §24 (commit `ed709a4a`) |
| Model | `docs/WP6C_ADAPTIVE_UX_MODEL.md` |

## A. Owner decisions applied

| Decision | Implementation |
|---|---|
| **OD-1 = A**, Adaptive Layer | **Daily-use pages get the deeper conversion:** cards, filter disclosure, row menus, the sticky action row, touch-safe reveal.<br>**Admin / config pages get the layer:** `.nuxr-stack` stacked lists, labelled 44 px controls, single-column phone forms, the sticky Save, two-per-row filters, AA button colours, and named help popovers. There is no brand redesign. Desktop keeps tables, toolbars and multi-column forms. |
| **OD-2 = A**, pricing | One row-level **Edit** opens one editing surface: full screen on a phone, a side panel docked to the inline end from 768 px. The surface holds exactly the forms the `PricingReadModel` projection offers (same routes, fields and CSRF). The inline `<details>` wall is gone, and the surface is rendered once per row. There is no pricing logic in templates or JS. `PricingUxTest` (WP6B) passes unchanged. |
| **OD-3** | The customer portal is out of scope. This is recorded in the model doc, and the guards scan staff templates only. |
| **A**, permission mismatches | Legacy actions are gated by `NuxrUi::allowed(route, method)` → `Navigation::allowed` (the central RouteGuard / RouteMap verdict, no role names). This covers: Active Customers (Recharge, Edit, Delete, Extend, Sync, plans / routers links), Vouchers (Add, Print, Remove, Delete, Delete Selected, staff link) and Staff (Add, Edit, Delete). `ActionParityTest` proves every rendered action is allowed. The backend still refuses on its own. |
| **B**, Back / Up | `Navigation::UP` in the UI layer (the RouteMap is untouched). The first allowed parent wins; otherwise Up goes to `dashboard`. Phone / tablet: an Up button in the top bar. Desktop: a parent line above the title. |
| **C**, 403 / 404 in the shell | `403.tpl` builds the actor's own shell (`Navigation::deniedShell()`, RouteGuard unchanged). 404 uses the same `state-page.tpl`. The page explains the state and offers Up and Home, with no object details. `error.tpl` is a standalone adaptive page. |
| **D**, 768 px | Container queries (`.nuxr-adaptive`, tiers ≥ 800 / 980 / 1100 px; `.nuxr-stack` < 760 px), with an `@supports not` fallback. Verified: subscribers, activations, wallets, pricing, the reseller tree and the dense admin lists at 768. |
| **E**, safe areas | `viewport-fit=cover` and `--nx-safe-*` tokens on the top bar, rail, sidebar, bottom navigation (exact height plus inset), sheets, full-screen surfaces and sticky rows. Verified with a simulated notch (captures 36–37). |
| **F**, keyboard | A CSS `:has(:focus)` rule on text-entry controls: the navigation hides and the sticky row drops to the edge. Both return after 0.4 s, so a tap on Save lands. There is no UA / device detection and no new `matchMedia` (guarded). Long form tested with the keyboard open (J12, captures 11 / 12). |
| **G**, hover reveals | Router IP / username and coupon / voucher codes: masked plus a reveal button (`secret.tpl`). Nine hover-revealed password fields: a Show toggle (`data-nuxr-peek`). 37 help popovers: named, focus-triggered. Only values the page already rendered for that role; no server visibility change. |
| **H**, audit defects | Router-edit (plus ODP add / edit and customer-edit) Smarty comment leak fixed. ODP search markup fixed. DB-status 500 investigated: `new mysqli` with the extension absent from the shipped and test images. Fixed by using the application's PDO connection in the legacy controller. No Domain / Finance / Activation / Security change, so no Owner Decision was needed. The settings accordion's misused tab roles and nested links were also fixed. |

## B. Commits

| SHA | Commit |
|---|---|
| `ed709a4a` | docs: the owner-accepted plan and decisions |
| `241e2cf8` | slice 0: tokens, safe areas, component-width tables, keyboard, RTL containment |
| `498ed9f7` | slice 1: Up / Back, shell error states, permission-aware legacy actions |
| `4433b337` | slice 2: adaptive forms and sticky primary actions |
| `63ca041a` | slice 3: daily-use lists as adaptive cards, filter disclosure, touch-safe reveal, row menus |
| `396c9a04` | slice 4: pricing row-level editing surface, Subscriber 360 tabs |
| `daebdb90` | slice 5: admin / config Adaptive Layer and audit defects |
| `8c99f04c` | slice 6: Arabic strings, confirmation sheet, guards, docs |
| `ce8c2fdc` | fixes from the visual QA pre-flight (Agent voucher JS null, collapse-button name, tablet touch size) |
| `8ffbf54a` | fixes from the real journeys (tap while typing; field validation before the question). **The full candidate validation ran on this commit.** |
| `cd7b43c0` | fix for the 2 failures that validation found: the confirmation sheet is built on first use (§G) |

No squash, amend or rebase.

## C. Files changed (`1148559d..cd7b43c0`: 100 files, +3863 / −1366)
- **Shell assets** (3): `ui/ui/scripts/nuxr-shell.js`, `ui/ui/styles/nuxr-rtl-legacy.css`, `ui/ui/styles/nuxr-shell.css`
- **UI layer (PHP)** (2): `system/autoload/Reseller/Ui/Format.php`, `system/autoload/Reseller/Ui/Navigation.php`
- **Legacy controller (Amendment H)** (1): `system/controllers/settings.php`
- **Arabic catalog** (1): `system/lan/arabic.json`
- **Tests** (6): `tests/Integration/Wp6c/ActionParityTest.php` (new), `tests/Integration/Wp6c/AdaptiveShellTest.php` (new), `tests/Integration/Wp6c/LegacyAdaptiveTest.php` (new), `tests/Integration/Wp6c/PricingEditorTest.php` (new), `tests/Unit/Wp6c/Wp6cGuardTest.php` (new), `tests/phpunit.xml`
- **Docs / plan** (3): `docs/WP6A_UX_MODEL.md`, `docs/WP6C_ADAPTIVE_UX_MODEL.md` (new), `owner-review/WP6C/PLAN.md` (new)
- **Staff templates** (84, under `ui/ui/admin/`): see §E.
- Evidence (this commit): `owner-review/WP6C/{REPORT.md, VISUAL_QA.md, JOURNEYS.md, TESTDOX.txt, FIX_TESTDOX.txt, ROUTE_AND_LINT.txt, visual/}`.

**Untouched (diff empty):**
- `system/autoload/Reseller/{Finance,Activation,Security,Domain,Backoffice}`;
- `system/reseller` (migrations);
- `install/`;
- `system/api.php`;
- `Package.php` and `Balance.php`;
- the claude-development-pack.

There is no schema change, migration, RBAC capability, DataScope, API authority or RouteMap change.

## D. Components introduced (WP6A / WP6B components reused first)

| Component | Consumers |
|---|---|
| `admin/nuxr/actionbar.tpl` (shared sticky action row) | 9 daily forms (slice 2) |
| the same row shape on legacy forms (`form > .nuxr-step-nav.nuxr-actionbar-form`) | 24 admin / config forms (slice 5) |
| `#nuxr-ask-sheet` confirmation sheet (`data-nuxr-ask`) | 28 forms that had `ask()` (= `window.confirm`) |
| `admin/nuxr/secret.tpl` (touch / keyboard reveal) | vouchers, RADIUS log, routers, coupons |
| `data-nuxr-peek` password toggle (JS enhancement) | 9 password fields |
| `.nuxr-stack` (stacked legacy lists) | 12 admin / config lists |
| `admin/nuxr/state-page.tpl` | 403, 404, 405 |
| `Navigation::UP` / `up()` / `deniedShell()` / `allowed(…, method)`, `NuxrUi::allowed()` / `deniedShell()` | shell, legacy gating |
| `.nuxr-sheet-edit` editing surface | pricing catalog / plan / plans |

Reused from WP6A / WP6B: `.nuxr-adaptive` table / card pairs (now container-driven), `.nuxr-mlist` / `.nuxr-mcard` cards, `<details>` disclosure, `.nuxr-rowmenu`, `<dialog class="nuxr-sheet">`, `.nuxr-tabs`.

## E. Legacy templates converted (84 staff templates touched)
84 templates (new: `nuxr/actionbar.tpl`, `nuxr/secret.tpl`, `nuxr/state-page.tpl`).

| Group | Templates |
|---|---|
| Shell and states | `header.tpl`, `footer.tpl`, `admin/login.tpl`, `403.tpl`, `404.tpl`, `error.tpl` |
| WP6A / WP6B pages (container-driven tables, pricing editor, tabs) | `customers/list`, `customers/view`, `finance/reports`, `finance/wallet-rows`, `nuxr/activation-rows`, `nuxr/ledger-rows`, `nuxr/transfer-rows`, `nuxr/pricing-row`, `pricing/catalog`, `pricing/plan`, `pricing/plans`, `reseller/tree` |
| Daily-use legacy forms (sticky action row, labels, confirmation sheet) | `customers/edit`, `plan/recharge`, `plan/refill`, `plan/edit`, `message/single`, `message/bulk`, `change-password`, `admin/add`, `admin/edit`, `admin/view`, `voucher/add` |
| Daily-use legacy lists (cards, filter disclosure, row menus, reveal, permission-aware actions) | `plan/active`, `voucher/list`, `admin/list`, `logs/system`, `logs/radius`, `logs/message`, `reports/list`, `reports/activation` |
| Admin / config Adaptive Layer (stacked lists, labels, sticky Save) | `hotspot/{list,add,edit}`, `pppoe/{list,add,edit}`, `vpn/{list,add,edit}`, `balance/{list,add,edit}`, `bandwidth/{list,add,edit}`, `routers/{list,add,edit}`, `pool/{list,add,edit}`, `port/{list,add,edit}`, `odp/{list,add,edit}`, `radius/{nas,nas-add,nas-edit}`, `coupons/{list,add,edit}`, `settings/{app,localisation,maintenance-mode,miscellaneous,notifications,customfield,widgets,widgets_add_edit,dbstatus}`, `paymentgateway/{list,audit}` |


## F. Role / UI mismatches fixed
- **Before (audit):** Agent / Sales saw plan-list Edit / Recharge / Delete / Extend, the vouchers "+", and staff "Add". All of them answered 403.
- **After:**
  - The lab parity probe gives 0 denied links for Agent, Sales and Report across every page their shell renders, while Admin keeps all 289 links.
  - `ActionParityTest` checks every navigation route → page → rendered action against `Navigation::allowed` for `ad`, `A`, `A1` and `rp`.
  - J17: Agent sees 0 row menus on Active Customers.

## G. Validation
Run on the implementation candidate `8ffbf54a`, 2026-10-05 08:31–10:23 UTC. It used only the `nuxbill-reseller-test` compose project and the unchanged harness (`dev/test/run-tests.sh --testdox`, `RouteMatrixTest` with `WP2_EVIDENCE=1`).

| Check | Result |
|---|---|
| MariaDB 10.11 | 897 tests, 65 941 assertions, **2 failures** |
| MySQL 8.0 | 897 tests, 65 938 assertions, **2 failures** (the same two) |
| Variant registry | reproducible on both engines (V1 / V2 / V3 fingerprints unchanged) |
| Route matrix | 1727 requests / 1354 denials / 373 allowed on both engines; **rows byte-identical to the WP6B baseline** and between the engines |
| PHP 8.2 lint | 482 / 482 |
| Manifest | 42 / 42 |
| Migrations / `install/` | unchanged (empty diff since `1148559d`) |
| Secret sweep (WP6C diff) | clean. Ephemeral CSRF / review tokens in the failure dumps of `TESTDOX.txt` are redacted. |
| PHPUnit warnings | 5: "Cannot add file … already added to test suite" for the new `wp6c-*` suite entries, which also match the generic `unit` / `integration` suites. They are harmless; the tests run once. |

**The 2 failures (both engines).** Both are in the accepted WP6B `FinanceCorrectionTest`:
- `testDebtLineReviewTypedDecreaseAndExposureFloor` (line 77);
- `testAdjustmentsAreExplicitAndNeverOpenAWallet` (line 102).

These tests assert that a no-change or missing-wallet review renders no Confirm button. They check for the markup `fa-check" aria-hidden="true"></i>Confirm`. The WP6C confirmation sheet, a closed `<dialog>` in every page footer, contained that same markup. So the regression was WP6C's: it was a markup collision, and finance behaviour was unchanged.

**Fix `cd7b43c0`.** The sheet is now built by `nuxr-shell.js` on first use, so pages carry no extra markup. The accepted test is unchanged.

**Focused re-validation on `cd7b43c0`, both engines** (`FIX_TESTDOX.txt`):
- `wp6a-unit` + `wp6b-unit` + `wp6c-unit` + `wp6c-integration`: 36 tests / 753 assertions, OK;
- `FinanceCorrectionTest` + `FinanceMutationTest` + `PricingUxTest` + `ShellNavigationTest`: 17 tests / 469 assertions, OK.

The final visual QA and journeys were re-captured on `cd7b43c0`.

**Cadence note.** Per the owner cadence, only one complete validation was run (on `8ffbf54a`); it is not repeated after the fix. The final full run allowed after owner review is still available if the owner wants a clean complete run on `cd7b43c0`.

**Shared host / AVAIA.**
- The guard snapshot taken before the run and compared after it shows `avaia-v3-prod-api`, `avaia-v3-prod-render-reconciler` and `avaia-v3-prod-web` with a new start time: 09:50:56, 09:50:57 and 09:53:11 UTC, during the run.
- RestartCount is unchanged (0), so this was not a crash or restart-policy loop. The same three containers had also been (re)started at 06:37 / 06:42 UTC, before the run.
- Host metrics at that moment: about 40 GB of memory available, load 6–7, and no OOM in the kernel log.
- The validation issued no command against any AVAIA container: only the `nuxbill-reseller-test` project, no prune, no daemon change.
- The rolling start sequence is consistent with an AVAIA-side deploy. This is recorded as observed, not as a WP6C effect.
- All AVAIA containers are running and healthy after the run; the exited ones are unchanged from the baseline (`wp6cval` sampler / guard logs).
- The known CliOnlyTest healthcheck race did not occur in this run.

## H. Viewport, RTL, accessibility and journey results
- **Visual QA:** `owner-review/WP6C/VISUAL_QA.md`, 37 captures across 360 / 390 / 430 / 768 / 844×390 / 1440, Arabic-heavy. Result: **0 overflow, 0 bottom-navigation clipping, 0 action-row overlap, 0 controls under the navigation, 0 axe serious / critical across 37 captures**. The only notes are the documented residuals in §I.
- **Config audit (lab, before the candidate):** 31 admin / config routes × 360 ar / 768 / 1440 ar show 0 overflow, 0 unnamed controls and 0 axe serious / critical. The exceptions are the third-party residuals listed in §I.
- **Journeys:** `owner-review/WP6C/JOURNEYS.md`. **20 / 20 PASS.** They cover login / logout, dashboard, primary navigation, More, list → detail, search, filters, create / edit, validation failure, save, Back / Up, keyboard-open long form, activation, finance Review → Confirm, stale review, pricing editing, permission-restricted, empty / error, Operations Center, and phone / tablet / landscape.

## I. Residuals and deviations (recorded, not hidden)
1. **Deviation:** filters use the WP6A `<details>` disclosure, not a bottom sheet. It is the same function with no extra JS. The plan's wording "filter sheet" is met as a disclosure.
2. **Deviation:** confirmation prompts on converted forms moved from `window.confirm` to the shell sheet. This was required by the accepted WP6A guard, and the questions are unchanged. Unconverted legacy pages keep `ask()`.
3. **Third-party CDN residuals (WP6A):** Chart.js (reports), CodeMirror (plan edit; its editor input is now named), DataTables, map tiles.
4. Third-party rich-text toolbars in the page editors (`pages/*`) are unnamed buttons, out of the Adaptive Layer. `settings/devices` lists host device files.
5. The legacy system-log table still prints its description raw; the card view escapes it.
6. `settings/app` keeps per-section Save (70 controls in collapsible sections).
7. Radio groups in legacy plan forms are named per option, with no fieldset / legend group name.
8. **Incidental legacy findings, out of WP6C scope, not changed:**
   - `bandwidth/add-post` with an empty rate field (only possible with browser validation bypassed) is a PHP 8 TypeError 500, at `bandwidth.php:100`;
   - the upstream default avatar `/user.default.jpg` is not shipped in the repository, so a customer without a photo requests a missing image.
9. The customer portal is out of scope (OD-3).
10. Arabic copy: 121 drafted entries for owner language review. Visual QA uses emulated devices; there is no physical-device test.
11. R-15 (WP6A) is unchanged and out of scope.

## J. Production / push status
- No deploy, PR, tag or merge.
- Only `wp6c/mobile-adaptive-ux` is pushed.
- `feat` stays `1148559d` and `main` stays `500d3a2d`.
- WP7 is not started.

WP6C_CANDIDATE_READY_WAITING_OWNER_REVIEW

---

## K. Fix round 1 (owner review "FIX_REQUIRED ROUND 1", then "FIX ROUND 1 CODE ACCEPTED / FINISH EVIDENCE ONLY")

| Item | Value |
|---|---|
| Reviewed candidate | implementation `cd7b43c0`, evidence `9cb22105` |
| Fix commits (additive) | `105d567b`: target-aware UI permission parity; no duplicate suites. `44505d59`: names for the voucher selection checkboxes. |
| **Final implementation SHA** | `44505d59cedeaa3cccd492f623bb0473a96625d4` |
| Evidence | this commit (evidence only) |
| Base / feat / main | `1148559d` / `1148559d` / `500d3a2d`, unchanged |

### K1. Files changed in fix round 1 (`9cb22105..44505d59`: 9 files, +242 / −26)
- **Runtime, presentation metadata only:**
  - `system/controllers/settings.php` (staff list per-row view / edit / delete and parent visibility);
  - `system/controllers/plan.php` (voucher customer visibility);
  - `system/controllers/reports.php` (activation-report customer visibility);
  - `ui/ui/admin/admin/list.tpl`, `ui/ui/admin/voucher/list.tpl`, `ui/ui/admin/reports/activation.tpl`.
- **Tests:** `tests/Integration/Wp6c/TargetParityTest.php` (new), `tests/Integration/Wp6c/ActionParityTest.php`, `tests/phpunit.xml`.
- **Unchanged:** RouteMap, capabilities, DataScope authority, `StaffService`, Finance, Activation, Security, schema, migrations, `install/`.

### K2. Pre-fix reproduction (`FIX_ROUND_1_REPRODUCTION.txt`)
The fix-round-1 tests were run against the unfixed `cd7b43c0` runtime on both engines: **5 of 6 fail**. The Report-delete positive case passes, as it should.
1. Admin staff list rendered Delete for a Sales account. `StaffService::canTarget(STAFF_DELETE)` lets Admin delete Report accounts only.
2. Sales linked its parent Agent (`settings/users-view/<A>`), which the backend refuses with a 302 "Account Not Found" (STAFF_READ = S).
3. Admin's voucher list linked the generator of a SuperAdmin-generated voucher, which `users-view` refuses for Admin.
4. Agent A's voucher list linked a customer outside A's customer scope (a subtree voucher redeemed by C's customer). This was found by the requested audit.
5. The HTTP crawl found rendered object links ending in a denial: `A1 → users-view/<A>` (302) and `ad → customers/viewu/VOUCHERONLY` (302; a voucher-only username without a customer record). This was also found by the audit.

### K3. Per-row permission fix
The legacy controllers hand the views per-row presentation metadata computed only with the accepted policies. The authoritative services and controllers still decide on execution.

| Surface | View / link | Edit | Delete |
|---|---|---|---|
| Staff list | `nuxr_staff_visible()` (the `users-view` rule) | self, or `StaffService::canTarget(STAFF_UPDATE_PROFILE)` (the `users-edit` rule) | `StaffService::canTarget(STAFF_DELETE)` and not an Agent (the explicit V1 rule of `StaffService::delete`) |
| Staff parent column | linked only when `nuxr_staff_visible()` holds for that parent; otherwise plain text | – | – |
| Voucher "Generated By" | always plain text: the voucher scope does not prove staff visibility | – | – |
| Voucher customer, activation-report customer | linked only when `DataScope::findCustomerByUsername(actor, CUSTOMER_READ, user)` (the `customers/viewu` lookup) finds it; otherwise plain text | – | – |

Every link is also still gated by the route rule (`NuxrUi::allowed`). There are no role-name rules in the templates and no new authorization framework.

### K4. ActionParityTest improvement
Route-level parity is kept. The new `testRenderedObjectLinksOpenForTheTarget` covers the converted daily-use / staff pages (`settings/users`, `plan/voucher`, `plan/list`, `customers/list`, `activation/list`, `reports/activation`, `reports`, `logs/list`) for Agent, Sales, Report and Admin:
- It follows every rendered object link (`settings/users-view|edit`, `customers/view|viewu|edit`, `plan/view|edit|voucher-view`, `activation/view`, `reseller/detail`, `finance/*` detail and config edit pages) with the role's session.
- A link fails on a non-200, a shell state page, "Account Not Found" or the legacy permission alert.
- Only read-only GETs are followed; POST actions are never executed. Object-specific POST rules are covered by `TargetParityTest`.

### K5. PHPUnit suite duplication
`tests/phpunit.xml` now excludes `Unit/Wp6c` and `Integration/Wp6c` from the generic `unit` / `integration` suites, as for WP6A / WP6B, and keeps `wp6c-unit` / `wp6c-integration`.
- The complete run contains 903 tests, the 897 before plus the 6 new fix-round-1 tests, so no test disappeared.
- There are **zero** PHPUnit warnings in every run since.

### K6. Focused validation (`FIX_ROUND_1_TESTDOX.txt`)
On `105d567b`, both engines:

| Suite set | MariaDB | MySQL |
|---|---|---|
| `wp6a-unit` + `wp6b-unit` + `wp6c-unit` + `wp6c-integration` | OK, 42 tests / 848 assertions | OK, 42 / 848 |
| Staff / security / shell: `StaffManagement`, `ScopeIsolation`, `SecurityRoutes`, `CustomerOwnership`, `Hierarchy`, `ShellNavigation`, `Subscriber360`, `Dashboard` | OK, 49 / 1474 | OK, 49 / 1474 |

All runs are plain "OK": zero failures, errors and warnings.

`44505d59` changes one template only (checkbox names). It was checked on MariaDB (LegacyAdaptive / TargetParity / ActionParity / WP6A and WP6C guards: OK, 28 / 521) and is covered by the final complete validation.

Explicitly confirmed:
- Admin cannot see Delete for Sales.
- Admin can see Delete for a Report account.
- Sales gets no parent-Agent link.
- The out-of-scope voucher generator and customer are not linked.
- Permitted Agent / Admin staff and customer links still open.
- There are zero duplicate-suite warnings.

### K7. Final complete validation on `44505d59` (the one permitted final complete run; 2026-10-05 11:22:58–12:57:35 UTC)

| Check | Result |
|---|---|
| **MariaDB 10.11** | **903 tests, 66 053 assertions: PASS, zero PHPUnit warnings** |
| **MySQL 8.0** | **903 tests, 65 807 assertions: 7 errors + 1 failure, not green.** All 8 are confined to `ServiceGateTest` (WP2). |
| Route matrix | **1727 requests / 1354 denials / 373 allowed** on both engines, byte-identical to the WP6B baseline and between the engines; route step **exit 0** |
| PHP 8.2 lint | 482 / 482 |
| Variant registry | reproducible on both engines (in the complete run and the isolated rerun) |

Files: `FINAL_TESTDOX.txt` (ephemeral tokens redacted) and `FINAL_ROUTE_AND_LINT.txt`.

**MySQL `ServiceGateTest` classification (owner decision).**
- All 8 failures were confined to `ServiceGateTest`.
- The first observed failure had the known stale AppServer / `php -S` worker signature: "RuntimeException: no CSRF token on admin: 404", that is, a reused random port answered by a leftover worker.
- The saved log does not prove that every one of the 8 stack traces has an identical text, and this report does not claim it.
- The complete affected test class then passed cleanly in isolation on both engines (K8).
- No WP6C runtime or domain code was changed to make it pass.
- Per the project's infrastructure-flake rule (exact known failure class plus an isolated rerun PASS), the owner classified it as the known AppServer / `php -S` infrastructure flake, not a product regression.

The full MySQL run itself is **not** represented as green. No further complete suite was run.

### K8. Isolated `ServiceGateTest` rerun on `44505d59` (`SERVICEGATE_RERUN.txt`)
Command: `WP1_KEEP_CONTAINERS=0 dev/test/run-tests.sh --filter ServiceGateTest --testdox`.
- **MariaDB:** OK, 10 / 10 tests, 264 assertions.
- **MySQL:** OK, 10 / 10 tests, 264 assertions.
- Variant registry matches freshly built fixtures.
- Exit 0, with zero failures, errors and warnings.

The owner independently obtained the same result.

### K9. Static checks on `44505d59` (`FIX_ROUND_1_STATIC_CHECKS.txt`)
- Manifest: 42 / 42.
- Migration / `install/` diff since `1148559d`: empty.
- Boundary diff (Finance / Activation / Security / Domain / Backoffice / `api.php` / `Package` / `Balance`) since `1148559d`: empty.
- Secret sweep: 0 matches, for both fix round 1 and the whole of WP6C. The evidence files contain no credentials or unredacted tokens.
- PHPNuxBill test containers: none left.

**AVAIA.** The guard against the snapshot taken before the final run shows that `avaia-v3-prod-web` got a new start time at 11:33:54 UTC, during the run.
- RestartCount is unchanged (0).
- Host: about 40 GB of memory available, load 5–9, no OOM in the kernel log.
- The validation issued no command against any AVAIA container: only the `nuxbill-reseller-test` project, no prune, no daemon change.

It is recorded as observed: the same pattern as the 09:50–09:53 AVAIA restarts noted in §G. All AVAIA production containers are running and healthy now; the exited ones are unchanged from the baseline.

### K10. Targeted browser checks (`FIX_ROUND_1_CHECKS.md`, `visual/fix-round-1/`)
Nine lab checks on `44505d59`: the staff list as Admin (desktop and phone, Arabic), Sales (phone, Arabic) and Agent; vouchers as Admin (desktop and phone, Arabic) with a SuperAdmin-generated fixture and as Agent; the activation report; and J17 (Agent 403 in the shell, Arabic).
- Admin sees Delete only on the Report row.
- Sales sees only its own row, with the parent as plain text.
- The generator is plain text.
- All 67 rendered object links open, with no denials.
- No overflow, axe serious / critical 0, no JS errors.

The desktop voucher check first found the legacy selection checkboxes unnamed (axe `label` ×7); this was fixed in `44505d59` and re-checked. The 37-capture visual QA and the 20 journeys were not repeated, as instructed.

### K11. Git
Additive commits only: no amend, squash or rebase.
- Implementation commits `105d567b` and `44505d59` are unchanged; this evidence commit sits on top.
- Only `wp6c/mobile-adaptive-ux` is pushed.
- `feat/reseller-system-v1` is `1148559d` and `main` is `500d3a2d`, unchanged.
- No deploy, PR, tag or WP7.

WP6C_FIX_ROUND_1_READY_WAITING_OWNER_REVIEW
