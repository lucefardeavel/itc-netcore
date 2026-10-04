# WP6A — Adaptive Shell and Subscriber UX — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED**, waiting for the owner's code review. Final state: `WP6A_IMPLEMENTATION_COMPLETE_PUSHED_WAITING_OWNER_CODE_REVIEW`.

Model: `docs/WP6A_UX_MODEL.md`. Test evidence: `owner-review/WP6A/TESTDOX.txt`. Visual evidence: `owner-review/WP6A/VISUAL_QA.md` and `owner-review/WP6A/visual/`.

Plan: `WP6A_PLAN.md`, accepted by "OWNER REVIEW — WP6A PLAN ACCEPTED WITH DECISIONS / IMPLEMENTATION GO" (decisions OD-A … OD-L).

## A. Ancestry / branch / heads

| Item | Value |
|---|---|
| Accepted prior head (base) | `feat/reseller-system-v1` = `f806a6186ee1ff2b6037e4af702245a59113c9da` (WP5B acceptance recorded) |
| Branch | `wp6a/adaptive-shell-subscriber-ux` from `f806a618` (linear, no squash, nothing reset) |
| Full-validation candidate | `e21ff68778dde4763e4f58c0372be5641ed9bd4a` |
| Implementation HEAD | `4722fd53`: the visual-QA fix on top of the candidate (CSS / JS / template markup only, §F) |
| Evidence HEAD | the evidence commit `docs(wp6a): model, owner-review report, testdox, curated visual QA` (its SHA is in the final response) |
| Worktree | clean apart from the ignored `tests/vendor/` |
| `main` / `feat` | untouched: `main` = `500d3a2d`, `feat/reseller-system-v1` = `f806a618` |

## B. Scope implemented (commits)

All commits are authored `ALI HENDI <ali.hahuba3@gmail.com>` and carry the `Co-Authored-By` trailer.

| Commit | Content |
|---|---|
| `9a896c95` | Slice 1: activation read model (OD-A), money preview with the staleness guard (OD-B), `activation/list` (OD-C), SEC-25 closed |
| `2412173c` | Slice 2: adaptive shell for every staff page (OD-G), navigation model, RTL foundation, asset diet (OD-H) |
| `6a42ca9e` | Slice 3: scoped subscriber search and the adaptive subscriber list |
| `d612273a` | Slice 4: Subscriber 360 (OD-D session data from stored rows only; OD-E Disconnect / Suspend hidden) |
| `87348f95` | Slice 5: four-step create-subscriber flow, lazy maps |
| `0151a910` | Slice 6: activation confirmation, persisted-state status page and activation list |
| `85e0d564` | Slice 7: scoped dashboard foundation (OD-L) |
| `e21ff687` | Slice 8: Arabic catalog (OD-F), adaptive staff login, WP6A source guards |
| `4722fd53` | Candidate visual-QA fix: link contrast, search listbox semantics, card-head touch size |

Scope by owner decision:

| Decision | Implemented |
|---|---|
| OD-A | `ActivationReadService`: read-only `list`, `counts`, `view` and `customerFlags`, using the accepted `activation.read` visibility. |
| OD-B | `ActivationPreviewService` and `PreviewBinding`. Role-filtered live quote: Admin and the paying Agent see wholesale, retail, credit before / after and expiries; Sales sees retail, expiries and "credit sufficient yes/no". The HMAC fingerprint is re-checked inside `ActivationService::open` before any write. A mismatch fails closed with `ACTIVATION_PREVIEW_CHANGED`. No schema change. |
| OD-C | `activation/list` `GET` → `activation.read`. RouteMap re-pinned. |
| OD-D | Session state comes from stored `radacct` rows only: online / stale / offline / never / unavailable. No NAS call while rendering. |
| OD-E | Disconnect and Suspend are not rendered for any role. |
| OD-F | Arabic-first RTL. 400 strings added to `arabic.json`. Logical-property CSS with a system font. Technical tokens are LTR with Latin digits. SEC-25 closed: no runtime translation request and no runtime language-file writes. |
| OD-G | The shell (header / footer) applies to every staff page. |
| OD-H | One jQuery. Chart.js only on `reports` / the widgets tab. Summernote only where used. Leaflet and Google tiles load only on an opened map. Google Fonts removed. `7.css` dropped. |
| OD-J | Phone bottom navigation: Home / Customers / New (+) / Activations / More. No Wallet. |
| OD-K | Curated visual QA, 18 screenshots plus 3 tablet probes. |
| OD-L | Dashboard foundation in the actor's scope, with a 7-day expiring threshold. No finance figures. |

Search covers username, full name, phone, PPPoE username, IP (prefix and open RADIUS sessions), email, `#id` and numeric id. It is Arabic-safe, server-side, applied after `DataScope`, and bounded (2–64 characters; 8 typeahead results; paginated list).

## C. Files changed

`git diff --name-status f806a618..4722fd53`: 59 files (27 added, 32 modified); 6478 insertions(+), 1793 deletions(-). The evidence commit adds the docs and `owner-review/WP6A/`.

**Domain (read-only additions):**
- `Activation/{ActivationReadService, ActivationPreviewService, PreviewBinding}.php` (new);
- `Activation/ActivationStore.php` (four display reads);
- `Activation/ActivationService.php` (optional `PreviewBinding` check in `open()`; public wrappers `payerOf()` and `expiredDay()`);
- `Domain/CustomerSearch.php` (new);
- `Domain/DomainError.php` (+ `ACTIVATION_PREVIEW_CHANGED`);
- `Radius/AccountingService.php` (open-session lookup by IP and the last session; read-only);
- `Security/RouteMap.php` (+ `activation/list`, OD-C).

**UI helpers (new):** `Ui/{Navigation, Format, Subscriber, Dashboard}.php`.

**Runtime glue:**
- `system/boot.php`: `nuxr_dir`, `nuxr_lang`, `NuxrUi`, `_nav`;
- `system/autoload/Lang.php`: SEC-25;
- `system/lan/arabic.json`.

**Controllers:**
- `activation.php`: preview key, picker, list, read-model view;
- `customers.php`: list filters, 360, create redirect, owner → Sales map, `mb_strlen` name check;
- `dashboard.php`: foundation plus widgets tab;
- `search_user.php`: scoped typeahead;
- `routers.php`, `odp.php`: lazy map.

**Templates and assets:**
- `admin/header.tpl`, `admin/footer.tpl`, `admin/admin/login.tpl`, `admin/dashboard.tpl`;
- `customers/{list, view, add, edit}.tpl`;
- `activation/{create, view, list}.tpl`;
- `routers/edit.tpl`, `odp/{add, edit}.tpl`;
- new `admin/nuxr/{macros, nav-groups, nav-primary, pager, activation-rows, search-results}.tpl`;
- new `styles/nuxr-shell.css`, `styles/nuxr-rtl-legacy.css`, `scripts/nuxr-shell.js`;
- the Google Fonts `@import` removed from `phpnuxbill.css` and `modern-AdminLTE.min.css`.

**Tests:**
- 9 new WP6A test classes (34 tests);
- `tests/phpunit.xml` (suites `wp6a-unit` and `wp6a-integration`);
- accepted tests changed per §E.

**Not touched since `f806a618`:**
- `Package.php`, `Balance.php`, `Tx.php`, `ServiceGate.php`;
- the migration runner and R0001–R0007;
- `install/radius.sql`;
- the manifest / spec pack;
- every finance / pricing / wallet / ledger class.

## D. Important design notes

- **Backend authorization stays authoritative.** Navigation, quick actions and buttons use the accepted rules: `RouteGuard` + `PermissionService::can`, `$_can`, `ActivationService::eligible`, and the read-service visibility. Hidden UI is never authorization; every route is still refused server-side. Nothing in JavaScript duplicates permission logic.
- **The staleness guarantee (OD-B) fits WP5A transaction semantics unchanged** (stop condition F not hit). The binding is compared inside the existing activation transaction, after the authoritative `resolveInTx` and before the activation INSERT. A refusal therefore leaves no activation, reservation, wallet, provisioning or legacy row. Callers without a binding (API, CLI, earlier tests) behave exactly as before. Wall clock and available credit are excluded from the material by design; the reservation re-checks credit authoritatively.
- **The preview key is per session** (64 hex) and never rendered. A fingerprint from another session or customer is refused (`ActivationPreviewTest`).
- **The read model never exposes snapshot or pricing JSON.** Wholesale is shown only to G and the paying Agent. Credit is shown as an outcome word (reserved / charged / released / refunded), never as a balance.
- **No new mutation, capability, state, edge or business rule.** Disconnect and Suspend stay absent (OD-E). There is no reseller voucher commerce and no Wallet navigation.
- **Rendering makes no live NAS dependency** (OD-D).
- **No framework.** Server-rendered Smarty with one small vanilla script (dialogs, search typeahead, stepper, pending-submit, status poll, lazy map). The backend re-checks every action, so the dialogs and typed confirmations are a usability layer, not a security control.

## E. Migrations / data impact

**None.** No schema change, no migration, no data rewrite. R0001–R0007 are byte-identical: `a0b52f7f…`, `38bceb1f…`, `60e63156…`, `7f3bc5bc…`, `b69135ea…`, `f1a76119…`, `b7edce53…`.

`Lang` no longer writes `system/lan/*.json` at runtime.

**Accepted tests changed** (all within the plan / OD-C):

| Test | Change | Authority |
|---|---|---|
| `Integration/Wp5a/ActivationHttpTest` | `form()` also posts the rendered `preview` fingerprint, which the web confirm now requires | OD-B |
| `Unit/Wp3a/SourceGuardTest` | RouteMap pin re-set to `0a2610f6…` (+ `activation/list`) | OD-C |
| `tests/phpunit.xml` | `wp6a-unit` and `wp6a-integration` suites | plan |

**Backward-compatibility changes** (also in `docs/WP6A_UX_MODEL.md` §10):
- the web activation confirm requires the fingerprint;
- `customers/add-post` now redirects to the new subscriber's 360;
- the dashboard legacy widgets moved to `dashboard&widgets=1` (global scope only);
- the subscriber list gained an "All" status and a service filter;
- maps load on demand;
- full-name validation counts characters (`mb_strlen` 1–45);
- the Subscriber 360 tab keys are `overview / activations / invoices / order` (`activation` still lands on invoices).

## F. Test commands and exact results

**Focused, per slice** (owner cadence; `devloop.sh run <engine> …`, isolated `nuxbill-reseller-test` project): read model + preview 9/9; activation HTTP and guards; `wp5a-integration` 55/55; `wp2-http` 104/104; search 3/3; 360 4/4; create 3/3; activation UX 4/4; dashboard 2/2; guards (`Wp6a|ActivationReversal|LegacyEntrypointClosure|SourceGuard`) 84/84. All green, and new tests were also run on MySQL before each commit.

**One canonical full validation, on the first complete candidate `e21ff687`.** Dev host, isolated compose project `nuxbill-reseller-test` only (PHP 8.2.34, MariaDB 10.11.19, MySQL 8.0.46, FreeRADIUS 3.2.8 test containers), 2026-10-04T14:23Z – 15:37Z.

| Check | Result |
|---|---|
| `dev/test/run-tests.sh --testdox` | MariaDB **OK (812 tests, 51 284 assertions)**; MySQL **OK (812 tests, 51 284 assertions)**; **1624 ✔, 0 ✘** (`TESTDOX.txt`); exit 0 (66 m 16 s). WP5B had 778; the +34 are exactly the new WP6A tests. |
| Variant registry reproducibility | matches freshly built fixtures |
| Route matrix (`RouteMatrixTest`, `WP2_EVIDENCE=1`) | **1477 requests / 1185 denials / 292 allowed**, identical on both engines; 0 rows removed (§H) |
| PHP 8.2 lint | 456 / 456 |
| Manifest | 42 / 42 |
| R0001–R0007 | byte-identical (§E) |
| Untouched since `f806a618` | `Package.php`, `Balance.php`, `Tx.php`, `ServiceGate.php`, the runner, migrations, `install/radius.sql` |
| Secret sweep | `git diff f806a618..4722fd53` clean (no credentials, keys or tokens) |
| Shared host | observe-only sampler: max load 9.26, min MemAvailable 39 653 MiB. The AVAIA container set (23 names, Up / Exited) is identical before and after. No AVAIA container was touched. Test and lab containers were removed. |

**Delta `e21ff687` → `4722fd53`.** Six files, 33 insertions / 8 deletions, **CSS / JS / template markup only**: link colour token, footer underline, card-head link height, the search box `role` switch, the "See all results" option role, and asset version strings. No PHP, route, permission, query or data change.

That delta came from the curated visual QA and was covered by:
- the focused `wp6a-unit` + `wp6a-integration` suites on both engines: **34 / 34 (1166 assertions) each**, including the source guards (escaping, assets, dialogs) and the navigation / search / 360 HTTP tests;
- `node --check` on `nuxr-shell.js`;
- the final curated visual QA pass.

Under the owner's cadence (at most two full runs), the second full run is kept for a possible owner-fix round.

**Curated visual QA, once, on the candidate** (`VISUAL_QA.md`). 18 screenshots across desktop / phone, Arabic RTL, and Admin / Agent / Sales / Report, plus 3 tablet probes.
- First pass on `e21ff687`: axe `color-contrast` (links), `link-in-text-block` (footer) and `aria-required-children` (search box). These were fixed in `4722fd53`.
- Final pass: **0 axe serious / critical violations on all 18**, 0 horizontal overflow, 0 external requests, 0 console errors, `dir="rtl"` on every Arabic capture.

## G. Security / failure cases tested (new tests)

| Test | Cases |
|---|---|
| `ActivationReadModelTest` | list / view visibility equals status visibility (G / T / A; out of scope → NOT_FOUND); view is display-only with role-filtered money; groups, counts and customer flags |
| `ActivationPreviewTest` | quote values and role visibility write nothing; a confirmed quote executes; changed material (the leaf's own wholesale, the retail, the plan's period definition) is refused **before any write** (checksums of every table unchanged); payer, plan and current recharge are fingerprinted by the same material; non-material changes keep the quote; missing, forged and foreign (other session / customer) fingerprints are refused; replay returns the same activation; insufficient credit is only yes/no for Sales |
| `ActivationUxTest` | confirmation shows live server values by role; browser-posted money is ignored and a stale quote writes nothing; the customer picker is scoped to activatable customers; every state maps to an honest status page |
| `ShellNavigationTest` | every navigation link rendered for each of the 7 roles is opened and is not denied (except the six environment pages the route matrix also skips and the test probe plugins); primary navigation / quick actions follow capabilities; document direction / language / landmarks / assets; missing translations never leave the server (SEC-25) |
| `SubscriberSearchTest` | every identifier matches within scope only (Arabic names, phone, PPPoE, IP, session IP, `#id`); bounds, wildcards (`%`, `_`) and escaping; list filters and role actions |
| `Subscriber360Test` | actions follow capabilities and nothing rendered is denied; secrets / escaping / tokens / assets; session state comes from accounting only; operational flags (in flight, re-provision unconfirmed) and tabs |
| `CreateFlowTest` | the form offers only the actor's ownership choices; the server resolves ownership and forgeries fail; Arabic names and the activate-now prompt |
| `DashboardTest` | counts are the actor's scope; the widgets tab is global-only and Report gets an empty state |
| `Wp6aSourceGuardTest` | every template variable escaped or allow-listed; dialogs not `confirm()`; no external asset beyond the documented residuals; no legacy plan price in the activation flow; UI helpers read-only and outside finance; SEC-25 |

## H. Git diff summary / route matrix delta

The code diff is §C; the route matrix delta follows.

Against the WP5B accepted matrix (1470 requests / 1183 denials / 287 allowed), all 1470 rows are unchanged and **7 rows were added**, all `GET activation/list` (`activation.read`). The harness URL helper renders `list` as the bare `activation` path, as it does for `view`.

| Role | Result |
|---|---|
| SuperAdmin `sa` | ALLOW |
| Admin `ad` | ALLOW |
| Agent `A` | ALLOW |
| Sales `A1` | ALLOW |
| Agent `F` | ALLOW |
| unenrolled Agent `E` | **DENY 403** |
| Report `rp` | **DENY 403** |

Totals: **1477 / 1185 / 292**. The full list behaviour is covered by `ActivationReadModelTest` and `ActivationUxTest`.

## I. Known risks / residuals

| ID | Residual |
|---|---|
| R-1 | Chart.js loads from the jsDelivr CDN on `reports` and the dashboard widgets tab (global scope only). |
| R-2 | An opened map loads Leaflet from unpkg and Google map tiles (documented, OD-H). |
| R-3 | The legacy widgets tab keeps its optional version check. |
| R-4 | Legacy templates outside WP6A still print unescaped variables. WP6A templates are guarded; Smarty has no autoescape. |
| R-5 | jQuery 1.11.1 / Bootstrap 3.4.1 / AdminLTE 2 are end-of-life; kept (no framework change in WP6A). |
| R-6 | The Arabic copy (about 400 strings) needs the owner's language review. |
| R-7 | Visual QA used emulated Chromium devices only. No physical phone / tablet; no Safari / Firefox. |
| R-8 | Search uses `LIKE` scans (bounded and scoped; index tuning is WP9). |
| R-9 | The activation POST is synchronous (WP5A saga). The confirm button is disabled until the response and the status page polls, but a slow NAS still holds the request. |
| R-10 | Legacy pages request a missing avatar thumbnail (404). The shell uses initials. |
| R-11 | Bulk-select row checkboxes (desktop / tablet table) and the labelled welcome-message checkbox are below the 44 px shell target. They meet WCAG 2.5.8 through its spacing exception. |
| R-12 | Lab fixture plugins ("WP2 Probe / Fail …") appear in the lab navigation. They are test fixtures and do not ship. |
| R-13 | The preview fingerprint binds the web confirm only. API / CLI callers keep the accepted WP5A behaviour (re-derivation without a binding). |
| R-14 | The harness flakes are known and unchanged: the AppServer port-reuse flake and the CliOnlyTest healthcheck race. Neither occurred in this run. |

**Stop conditions:** none encountered. No schema, no business-semantics change, no new mutation or capability, no framework, no pinned-file change beyond the authorized RouteMap, and the staleness guarantee did not require WP5A transaction changes.

## J. Production status

Not deployed. No production system or AVAIA container was touched.

## K. Push status

Only `wp6a/adaptive-shell-subscriber-ux` is pushed (normal, non-force). `feat/reseller-system-v1` (`f806a618`) and `main` (`500d3a2d`) are untouched. No PR, merge, tag or deploy. WP6B and WP7 are not started.

## L. Owner-review artifact path

`owner-review/WP6A/`:
- `REPORT.md`
- `TESTDOX.txt`
- `VISUAL_QA.md`
- `visual/`: 18 PNG, `visualqa-final.json`, `visualqa-found-e21ff687.json`, `visualqa-tablet.json`, `visualqa.py`

`docs/WP6A_UX_MODEL.md`, with the amendments in `docs/WP5A_ACTIVATION_MODEL.md` and `docs/WP2_PERMISSION_MODEL.md`.

## M. Final state

`STOPPED_WAITING_OWNER_REVIEW`, reported as `WP6A_IMPLEMENTATION_COMPLETE_PUSHED_WAITING_OWNER_CODE_REVIEW`.
