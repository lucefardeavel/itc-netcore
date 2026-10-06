# WP7 — Staff PWA: Candidate Report

**Status:** `WP7_CANDIDATE_READY_WAITING_OWNER_REVIEW`. This is not self-accepted. The owner decides.

## 1. Identification

| Item | Value |
|---|---|
| Branch | `wp7/staff-pwa` (from `245e46bf`, the locked plan; parent `8becd6b9`) |
| Candidate implementation SHA (validated) | `10408df921f0ad8c95eb8d9da0db9d5e166133cd` |
| Test-tooling commit after validation | `a98fba5c` (`dev/pwa-lab/pwa_visual.py` only, no runtime change) |
| Evidence commit | the commit that adds this file (see the final hand-off message) |
| Untouched | `feat/reseller-system-v1` = `8becd6b9`, `wp6c/mobile-adaptive-ux` = `757d8de9`, `main` = `500d3a2d` (local and origin) |

### Implementation commits (additive; no squash, amend or rebase)

| Commit | Slice |
|---|---|
| `a120b426`, `b9fa3023` | Slice 0: secure-context lab (OD-W7-4); removal of an accidentally committed bytecode cache |
| `be55c8ea` | Slice 1: public `pwa/manifest` + `pwa/ping`; RouteMap entry + authorized re-pin; interim icons (OD-W7-1/2) |
| `958d58d6` | Slice 2: static worker, offline document, registration |
| `736a5606` | Slice 3: connectivity state machine, status UI, offline mutation gate + CSRF hook |
| `587cb758` | Slice 4: cross-user hygiene inside the installation namespace |
| `21716c95` | Slice 5: user-initiated install UX |
| `0614e48c` | Slice 6: controlled update UX with critical-flow deferral |
| `bd92412e` | Slice 7: deep-link / online parity, Arabic strings, accessibility |
| `10408df9` | Docs: `docs/WP7_PWA_MODEL.md` + WP6A / WP6C amendment notes |
| `a98fba5c` | Lab: curated visual / a11y QA script (test tooling) |

## 2. Changed files (`245e46bf..HEAD`, plus this evidence)

**Runtime: new**
- `nuxr-sw.js`
- `nuxr-offline.html`
- `system/controllers/pwa.php`
- `system/autoload/Reseller/Ui/Pwa.php`
- `ui/ui/scripts/nuxr-pwa.js`
- `ui/ui/admin/nuxr/pwa-config.tpl`
- `ui/ui/images/pwa/icon-180.png`, `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`

**Runtime: modified**
- `system/autoload/Reseller/Security/RouteMap.php`: the `pwa` entry only; authorized re-pin; new sha256 `3af52d3bacb588373ea109acba8da91c7f6eaf7cac4aed48aea28447c2005867`.
- `ui/ui/nuxr-csrf.tpl`: the gate hook.
- `ui/ui/admin/header.tpl`, `ui/ui/admin/footer.tpl`, `ui/ui/admin/admin/login.tpl`.
- `ui/ui/admin/error.tpl`: asset query only.
- `ui/ui/admin/activation/create.tpl`, `ui/ui/admin/finance/review.tpl`: the `data-nuxr-critical` attribute only.
- `ui/ui/styles/nuxr-shell.css`.
- `system/lan/arabic.json`: +11 entries.

**Tests**
- New: `tests/Unit/Wp7/Wp7GuardTest.php`, `tests/Unit/Wp7/PwaManifestTest.php`, `tests/Integration/Wp7/PwaRoutesTest.php`, `tests/Integration/Wp7/PwaCsrfGateTest.php`.
- Modified: `tests/phpunit.xml` (suites `wp7-unit` / `wp7-integration`, which the generic suites exclude); `tests/Unit/Wp3a/SourceGuardTest.php` (RouteMap pin, owner-authorized).
- Lab (test tooling, OD-W7-4): `dev/pwa-lab/PwaLabTest.php`, `lab.sh`, `README.md`, `make_icons.py`, `pwa_suite.py`, `pwa_visual.py`.

**Docs**
- `docs/WP7_PWA_MODEL.md` (new).
- `docs/WP6A_UX_MODEL.md`, `docs/WP6C_ADAPTIVE_UX_MODEL.md` (WP7 amendment notes).

**Evidence**
- `owner-review/WP7/REPORT.md`, `TESTDOX.txt`, `PWA_BROWSER_QA.md`, `ROUTE_MATRIX_mariadb.rows`.
- `owner-review/WP7/browser/*.json`.
- `owner-review/WP7/visual/V01–V12.png` + `pwa_visual.json`.

**Files beyond the plan's expected list (all within the authorized scope):**
- `ui/ui/admin/nuxr/pwa-config.tpl`: the client's URL and strings, kept out of the footer.
- `dev/pwa-lab/make_icons.py`: the recorded icon recipe.
- `dev/pwa-lab/lab.sh`, `README.md`, `pwa_visual.py`: lab tooling.
- `ui/ui/admin/error.tpl`: an asset version query only.

**Not created, as the plan requires:** `pwa/start`, a PHP offline route, a runtime cache, `minClient`. The optional `notificationclick` handler was also not shipped (§9).

## 3. Owner decisions as implemented

- **OD-W7-1 = B, interim icons.**
  - Deterministic rendering of the WP6C application mark: the initial "P" (from the shipped default `CompanyName` "PHPNuxBill"), white on `#3442c9`.
  - Rounded square, radius 0.22, for 192 and 512. Full bleed for 180 (the platform applies its own mask) and for maskable 512 (glyph at 0.40, inside the 80% safe zone).
  - Recipe: `dev/pwa-lab/make_icons.py` (Pillow 10.2.0, DejaVu Sans Bold, 4× supersampling + LANCZOS). No AI, no upscaling. Rerunning it reproduces byte-identical files.
  - The initial is not a brand decision. A deployment may regenerate with `--initial`, e.g. "ش".

  | File | Size | sha256 |
  |---|---|---|
  | `icon-180.png` | 180×180 | `d70839df62a65bf9c6f1f962e6db55e70731b2739788b88f2d90a1f7f0e1ee3d` |
  | `icon-192.png` | 192×192 | `4225a18c2fc0b4e5c161141e27dec6bf80e46007a709942d992167dc76311ad2` |
  | `icon-512.png` | 512×512 | `16d3def13538c463b349dc70c0f4c73be2d35d8ce665de8e674a2a6bf833b52f` |
  | `icon-maskable-512.png` | 512×512 | `6ceadda4008b7ef623f559742e2a75170bd74325f696ddc2eabf01faae916994` |
- **OD-W7-2: only `pwa/manifest` + `pwa/ping`.**
  - Both are public GET with no capability and are not in `API_ALLOWED`.
  - Any other `pwa/*` returns 404 with no body; POST returns 405.
  - `start_url` is `./?_route=admin`. The offline page is static `nuxr-offline.html`.
  - **Refinement:** the manifest `id` is `<path>/?_route=admin`, not the plan's literal `"nuxr-staff"`. This keeps two installations on one origin distinct apps. It is still per installation, never per user.
- **OD-W7-3 = A:** logout and session-expiry routing are unchanged.
- **OD-W7-4 = A:** `dev/pwa-lab/` Python Playwright harness, test-only.
- **Amendments A–O:** see `docs/WP7_PWA_MODEL.md` §2–§8.
  - Precache only (offline document + 4 icons), zero business cache.
  - Navigations network-only; 303 `reason=unconfirmed` for a failed non-GET navigation.
  - Cookie-less heartbeat with throwaway-session disposal; the stated cadence.
  - Gate on every send path (OFFLINE / RECONNECTING only).
  - User-initiated install; user-chosen, deferred, single-reload update.
  - No client router; branding replaceability.

## 4. Focused validation (per slice, while building)

| Slice | Focused result |
|---|---|
| 1–4 | `wp7-unit` + `wp7-integration` green. RouteMap / SourceGuard / SecurityRoutes / AuthSession / Csrf: 76 OK. CSRF / Auth / ShellNavigation / WP6 guards / LegacyAdaptive: 40 OK. `wp6a-unit` / `wp6c-unit` OK. Lab T00–T13b PASS. |
| 5 | MySQL `wp7-unit,wp7-integration`: 19 tests / 232 assertions OK. Lab T14i PASS. |
| 6 | MySQL `wp7-unit,wp6a-unit,wp6c-unit`: 27 / 243 OK. MySQL `wp6a-integration`: 45 / 1436 OK. Lab T14–T16 PASS (root + `/billing/`). |
| 7 | MySQL `wp7-unit` + WP6 units: 40 tests. The first pass had 1 failure: my own wrong count threshold in the new Arabic-coverage guard, corrected to the real 14 strings. Then OK. MariaDB `wp7-unit,wp7-integration`: 24 / 283 OK. Lab T17–T20 PASS. |

## 5. Candidate validation (once, on `10408df9`)

The run went from 2026-10-05T17:18:51Z to 18:40:20Z, in the isolated `nuxbill-reseller-test` project only.

| Item | Result |
|---|---|
| MariaDB 10.11 full suite `--testdox` | **OK (927 tests, 66382 assertions)**: 0 failures, 0 errors, 0 PHPUnit warnings / deprecations / risky / skipped (`TESTDOX.txt`) |
| MySQL 8.0 full suite `--testdox` | **OK (927 tests, 66381 assertions)**: 0 failures, 0 errors, 0 warnings |
| Variant registry | reproducible on both engines (V1 / V2 / V3 core fingerprints `3ca25964…`, `6c2f04ea…`, `e1a66aa3…`) |
| Route matrix (once, both engines) | **1727 requests / 1354 denials / 373 allowed** (290 × 200 + 83 × 302). **Zero-row delta** against the accepted WP6C rows; MariaDB and MySQL rows byte-identical. |
| PHP 8.2 lint | 499 / 499 tracked non-vendor PHP files pass |
| Pack manifest | `docs/claude-development-pack/MANIFEST.sha256`: 42 / 42 OK |
| Migration / install boundary | 0 changed files under `system/reseller/migrations`, `install/`, `system/updates.json`. Migrations are still R0001–R0007. 0 schema statements in added lines. |
| Secret sweep (WP7 diff) | 0 secrets. 2 hits are test regexes for the CSRF-token pattern (no values). The ephemeral DB password was never written to the repository or the evidence. |
| WP7 browser suite (root + `/billing/`) | 24 / 25 PASS + 1 anomaly (T18), reported separately (`PWA_BROWSER_QA.md`); the isolated rerun PASS |
| Secure-context proof | T00: `isSecureContext === true` at `localhost:18099/` and `localhost:18098/billing/`; every check re-asserts it |
| Root / subdirectory | manifest `id` per installation; worker scope `/` and `/billing/`; a page outside `/billing/` is not controlled; scope-hashed caches `8acf0404` / `82c75d71`; offline, update and parity checks pass at both |
| Cache-leak result | T04 / T05: exactly 5 precache entries, no `_route=` or authenticated content. T13: after Admin → Agent → Sales, no previous identity / customer / finance / token / ACT- value in CacheStorage, local / session storage or IndexedDB. T13b: prune stays in its own namespace. |
| Visual / a11y (12 curated captures) | no overflow, no WP7 phone target < 44 px, no bottom-nav collision, axe serious / critical 0, no page error, no external request. One console line is the lab-only `user.default.jpg` 404 (known WP6C lab gap, not WP7). T20: 10 surface audits, axe 0. |
| AVAIA (observation only) | snapshot before the run and checks after the PHPUnit / route run and after teardown: **all 24 protected containers unchanged** (status, health, restart count, start time) |

## 6. Hard-stop conditions: none hit

- No schema or migration change.
- No new capability.
- No DataScope, Finance-writer or Activation-writer change.
- No API authority expansion; `pwa` is not in `API_ALLOWED`.
- No authenticated or runtime business cache; precache list only.
- No Background Sync, mutation queue or offline replay.
- No customer-portal business-logic change: the portal does not load the client, and the shared CSRF shim's hook is inert without the gate (T10b, `PwaCsrfGateTest`).
- No auth / login / logout / session-routing change. `login.tpl` only gains head assets and the client.
- The worker scope is the natural installation root.
- Zero route-matrix delta.
- No TLS, AVAIA, `main`, deployment or WP8 work.

## 7. Infrastructure / test flakes encountered (exact)

- **PHPUnit:** none in the candidate run. Neither the CliOnlyTest healthcheck race nor the AppServer stale `php -S` signature appeared.
- **WP7 lab T18 (and once T17 during development):**
  - **Signature:** in the context with service workers blocked, `window.nuxrCsrfToken` read as `undefined` on one page. Status, final route, title, h1, forms and alerts were identical.
  - **Server side:** the lab server log has no 5xx and no fatal error.
  - **Reproduction:** not reproduced in instrumented reproductions (about 100 visits) or in the plain isolated rerun (PASS).
  - **Classification:** an unexplained test-measurement timing anomaly in the new lab tooling, not a product failure. Full evidence is in `PWA_BROWSER_QA.md`. The suite was **not** altered to hide it.

## 8. Pre-existing defect found (not fixed, owner decision required)

**WP2 CSRF shim vs Pace.**
- **Cause:** the staff pages load Pace before the CSRF shim. Pace replaces `window.XMLHttpRequest`, so the shim's `XMLHttpRequest.prototype` patch never reaches the instances legacy jQuery creates.
- **Observed effect in the lab:** legacy jQuery XHR POSTs go out **without** `X-CSRF-Token` and the server refuses them (403). One example is the `autoload/server` request on the Admin `plan/recharge` page.
- **Scope:** this predates WP7. Fixing it changes WP2 CSRF-injection behaviour, which is outside the WP7 authorization.
- **WP7 is not affected:** its gate wraps the constructor per instance (`GatedXHR`), so it is independent of this defect.

## 9. Residuals

1. **HTTPS is a deployment prerequisite.** Worker and install exist only in a secure context. On plain HTTP the client offers nothing, and the app works as a normal web page. WP7 changes no production TLS.
2. **iOS installation is manual** (Share → Add to Home Screen) through the manual sheet; there is no `beforeinstallprompt` there.
3. **Logout inside the standalone app** lands on the legacy customer login page. Routing is unchanged per OD-W7-3 = A.
4. **The offline document** is static and bilingual (English + Arabic); it does not follow the user's language setting.
5. **`short_name`** is a plain 12-character prefix of `CompanyName` and may cut a word, e.g. "شبكة النور ل".
6. **Chromium CDP does not emulate `display-mode`.** The standalone CSS rule is verified in the live CSSOM and by the guard; no standalone capture was possible.
7. **Interim icons** (OD-W7-1). A permanent brand is a future owner decision.
8. **The optional `notificationclick`** is not shipped: no notification can exist without permission or subscription. It is documented as an extension point together with `minClient`.
9. **Safe-area insets** are checked at CSS level only; headless Chromium cannot emulate device insets.
10. **The T18 lab measurement anomaly** (§7).
11. **The pre-existing Pace / CSRF-shim XHR defect** (§8).
12. **The lab's `user.default.jpg`** is untracked upstream, giving a lab-only 404 on legacy customer pages.

## 10. Confirmations

- `feat/reseller-system-v1` (`8becd6b9`), `wp6c/mobile-adaptive-ux` (`757d8de9`) and `main` (`500d3a2d`) are untouched, locally and on origin.
- Only `wp7/staff-pwa` is pushed.
- No merge, PR, tag or deployment.
- No WP8 work.
- The lab and the test stack are torn down. AVAIA was observed only and is unchanged.

## Fix round 1 addendum

The owner's fix round 1 (FR1-1 … FR1-6) is documented in `FIX_ROUND_1.md`. The sections above are unchanged as the original candidate record.
