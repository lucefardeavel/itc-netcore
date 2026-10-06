# Pre-WP8 Hotfix — R-WP7-CARRY-01 Legacy Pace / XHR CSRF Header Compatibility — Candidate Report

**Status:** `PACE_XHR_CSRF_CANDIDATE_READY_WAITING_OWNER_REVIEW`. This is not self-accepted. This is not WP8.

**R-WP7-CARRY-01 status:** FIX CANDIDATE — WAITING OWNER ACCEPTANCE. It is not closed until the owner accepts.

> **Acceptance and provenance note** (added on `feat` in the owner-acceptance metadata commit; the frozen branch `hotfix/pace-xhr-csrf` = `6d4f3f96` is unchanged).
> - The owner verdict of 2026-10-06 is **ACCEPTED_WITH_NOTES**, with accepted implementation / evidence `6d4f3f962482b6e125429da7ae459ae8dfd4214c`. See `OWNER_DECISIONS.md`.
> - **R-WP7-CARRY-01 is CLOSED — ACCEPTED HOTFIX.** The candidate-stage wording above and below is kept as the original record.
> - OBS-HF-1 is now recorded as **R-HF-CARRY-01 — Legacy autoload router / plan metadata authorization exposure: OPEN — PRE-EXISTING — NOT CAUSED BY HOTFIX, MEDIUM**, for a later owner decision only.

## 1. Identification

| Item | Value |
|---|---|
| Branch | `hotfix/pace-xhr-csrf` |
| Locked plan | `ae4eaeac27c042e70ffac02c866d369b49f27c4f` (parent = canonical `7e3a557c89d3d3f85a404b2ec82255e7c3b2af45`) |
| Runtime commit | `349b864a` — central shim fix (`ui/ui/nuxr-csrf.tpl` only) |
| Test commit | `b57876f5` — guard suite, `phpunit.xml` suite, lab checks T21–T24 (validated candidate SHA for the browser suite) |
| Evidence commit | the commit that adds this file |
| Owner decisions | OD-HF-1 (WP2 semantics: every same-origin XHR), OD-HF-2 (Option D), amendment **HF-A1** (no `setRequestHeader` wrapper), OD-HF-3 (focused validation), OD-HF-4 (restored functions acknowledged) |

**Pre-flight:** the branch was at `ae4eaeac` and the worktree was clean. Origin refs matched: `feat` `7e3a557c`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `main` `500d3a2d`.

## 2. Changed files (`ae4eaeac..`)

| Group | Files |
|---|---|
| Runtime | `ui/ui/nuxr-csrf.tpl` (XHR section and comment only) |
| Tests | `tests/Unit/HotfixPaceXhrCsrf/PaceXhrCsrfGuardTest.php` (new); `tests/phpunit.xml` (suite `hotfix-pace-unit`, excluded from the generic `unit` suite); `dev/pwa-lab/pwa_suite.py` (T21–T24) |
| Docs | `docs/WP2_PERMISSION_MODEL.md` (CSRF §8: the XHR target); `docs/WP7_PWA_MODEL.md` (R-WP7-CARRY-01 marked FIX CANDIDATE only) |
| Evidence | `owner-review/HOTFIX_PACE_XHR_CSRF/REPORT.md`, `evidence/*.json` |

**Not touched:**
- Pace and jQuery (sha256-pinned) and `vendors.js`;
- `nuxr-pwa.js` / `nuxr-sw.js`, every other template, `custom.js`;
- RouteMap / `API_ALLOWED`, `CsrfPolicy` / `Csrf.php`, controllers;
- schema / migrations / install;
- capabilities, DataScope, Finance, Activation, wallet / pricing, RADIUS / MikroTik;
- PWA caching. There is no new dependency.

## 3. The fix (Option D, HF-A1)

The shim's XHR section now does this:
1. **It resolves the real instance prototype.** It starts from `var XP = XMLHttpRequest.prototype;` and creates a disposable `probeXhr = new XMLHttpRequest()`, which is never opened or sent and has nothing else done with it. If `Object.getPrototypeOf(probeXhr)` has `open` and `send` functions, that prototype becomes `XP`. Any exception keeps the old target.
2. **It patches only `XP.open` and `XP.send`, once per document.** The guard is a non-enumerable `__nuxrCsrfXhr = true` marker on `XP`, set with `Object.defineProperty`, with a plain-assignment fallback. The marker holds no token, identity or session data.
3. **The existing semantic lines are byte-identical:**
   - `this._nuxrSame = sameOrigin(u); this._nuxrMethod = m; this._nuxrUrl = u;`
   - `if (this._nuxrSame) { try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) { } }`
   - `allowed('xhr', this._nuxrMethod, this._nuxrUrl)`, with its synthetic "not sent" error.
4. **Nothing else is replaced.** `window.XMLHttpRequest` is never reassigned and `setRequestHeader` is not wrapped (HF-A1). Forms, `form.submit`, fetch, `nuxrPost`, `a[data-method=post]`, the WP7 gate, the heartbeat and Pace are unchanged.

**What changes where:**
- **Staff shell:** `XP` is the native `XMLHttpRequest.prototype` that Pace's instances inherit. The chain becomes WP7 `GatedXHR` (gate) → Pace `open` → shim (`open` / header) → native.
- **Native-constructor pages** (customer portal, public, staff login, print): `XP` is the same object as before, so behaviour is unchanged.

## 4. Results

### Pre-fix provenance (kept)

Recorded in the locked plan §2. Raw data: `evidence/prefix_reproduction.json` and `evidence/prefix_pre_wp7_simulation.json`.
- `autoload/server` returned 403 without `X-CSRF-Token` at root and `/billing/`, with the worker active and blocked, and in the simulated pre-WP7 stack.

### Focused PHP (both engines)

| Suite | MariaDB | MySQL |
|---|---|---|
| `hotfix-pace-unit` + `wp7-unit` + `wp7-integration` | OK (33 tests, 377 assertions) | OK (33 tests, 377 assertions) |
| `CsrfTest` + `AuthSessionTest` + `SecurityRoutesTest` | OK (27 tests, 330 assertions) | OK (27 tests, 330 assertions) |

- `PwaCsrfGateTest` (in `wp7-integration`) re-asserts the byte-identical token injection lines and the unchanged server CSRF (token-less POST → 403).
- The hotfix guard suite (7 tests) covers:
  - real-instance prototype resolution;
  - no `window.XMLHttpRequest` reassignment;
  - only `open` / `send` patched;
  - no `setRequestHeader` wrapper;
  - the token line central, same-origin guarded and before the gate;
  - no runtime file other than the shim sets `X-CSRF-Token`;
  - Pace / jQuery / `vendors.js` byte-identical;
  - script order unchanged;
  - no retry / queue / replay / storage.

### Browser (lab: root `localhost:18099/` and `/billing/` `localhost:18098/billing/`, secure context)

**Complete WP7 + hotfix suite, run exactly once:** **32 / 32 PASS** (T00–T20, the WP7 fix-round checks, T21–T24). Evidence: `evidence/pwa_suite_hotfix_candidate.json`, at `b57876f5`.

| Owner item | Check | Result |
|---|---|---|
| B. Recharge `autoload/server` | T21 | root + `/billing/` × worker active + blocked: **200**, one request, exactly one `X-CSRF-Token` equal to `window.nuxrCsrfToken`, router options rendered |
| B. Recharge `autoload/plan` | T21 | same four combinations: **200**, one request, exact single token, the Hotspot plan list rendered |
| C. jQuery POST | T22 | one request, exact single token, `success` callback with the response |
| D. Native XHR POST / GET | T22 | exact single token; events `rs1,rs2,rs3,rs4,load,loadend`; `status` 200 and `responseText` unchanged |
| Upload | T22 | a real un-intercepted same-origin upload (256 KiB Blob): `up-loadstart,up-progress,up-load,up-loadend,load,loadend`, exact single token. Intercepted probes emit only `up-loadstart` in Chromium, so they are checked for that and for completion. |
| E. fetch POST | T22 | exact single token, normal response (unchanged) |
| F. Cross-origin XHR GET / POST (with upload) | T22 | **no** `X-CSRF-Token`; completed normally |
| G. Same-origin XHR GET | T22 | header present, exactly one value (OD-HF-1), response unchanged |
| H. Customer portal | T22 | native constructor, `instanceof` intact, no Pace, no gate; same-origin jQuery / native XHR carry one exact token and cross-origin none, identical to the pre-fix reproduction |
| I. WP7 OFFLINE | T08, T09, T10 | 0 business POSTs for requestSubmit, `data-method`, `nuxrPost`, `form.submit`, fetch, XHR and jQuery; gate behaviour unchanged |
| J. WP7 SLOW | T12, T23 | chip shown; jQuery POST sent normally, one request, exact token, `success` |
| K. Network-error hint | T12n, T23 | genuine XHR network error: the caller's `error` fires once (`status` 0, `readyState` 4); immediate heartbeat (0.03 s); request sent **once**, carrying the exact token; no replay |
| L. Heartbeat identity | T12h | root + `/billing/`: no Cookie, Authorization, `X-CSRF-Token` or Referer; marker exact; ordinary fetch keeps the token |
| M. Worker parity | T18, T10c, T17, T21, T22 | worker active vs blocked equivalent, root and `/billing/` |
| N. Pace | T21, T22 | `window.Pace` present, `.pace` element present, the constructor still Pace-wrapped (via WP7), the marker non-enumerable; no page errors on `plan/recharge`, `dashboard` or the probe pages |
| O. Restored legacy operations | T24 | see below |

**Restored legacy operations (T24; isolated lab fixtures only; Admin):**

| Operation | Result |
|---|---|
| Coupon bulk delete | 2 coupons created through `coupons/add`, deleted through the real `coupons/delete` XHR: 1 request, **200** `{"status":"success"}`, exact single token, fixtures gone |
| Voucher bulk delete | 2 vouchers generated through `plan/add-voucher` (whose own `autoload/server` and `autoload/plan` now work), deleted through `plan/voucher-delete-many`: 1 request, **200** `success`, `skipped` 0, exact single token, fixtures gone |
| Bulk message | **TEST mode** (`test=on` verified in every request body): 3 batch XHRs, **200** `success`, every entry `Test Mode`, nothing delivered |
| No privilege expansion | Agent `A` and Sales `A1` sending XHR POSTs (which now carry the token) to `coupons/delete`, `plan/voucher-delete-many` and `message/send_bulk_ajax`: **403** each (RouteGuard, capabilities `SYSTEM_COUPONS`, `VOUCHER_DELETE`, `MESSAGE_BULK` = SuperAdmin / Admin only) |

**Page errors on the legacy pages:** 4, all `$j(...).DataTable is not a function` on `coupons/list`. This is upstream legacy code (2024-12, `ui/ui/coupons.tpl` → `admin/coupons/list.tpl`) that calls DataTables without loading it; it is unrelated to XHR and pre-existing. The check fails on any other page error, and there were 0.

### Other checks
- **Lint:** `PaceXhrCsrfGuardTest.php` passes PHP 8.2 lint. The runtime JS (the shim's literal block) passes `node --check`.
- **Secret sweep** of `7e3a557c..HEAD`: clean.
- **Pack manifest:** 42 / 42 OK.
- **Not run, per OD-HF-3:** the full 927-test suites (no PHP runtime or server-authority change), `RouteMatrixTest` (RouteMap unchanged) and the full visual QA (no CSS or markup change).

## 5. Confirmations

- **No duplicate `X-CSRF-Token`.** Every same-origin XHR, upload, fetch and legacy request carried exactly one value equal to `window.nuxrCsrfToken`; a comma-joined value would fail.
- **No manual runtime CSRF-header caller.**
  - The guard scans every runtime `php` / `tpl` / `js` / `html` file outside `tests/`, `docs/`, `owner-review/`, `dev/` and vendor. Only `ui/ui/nuxr-csrf.tpl` sets `X-CSRF-Token`.
  - Mentions elsewhere are server reads (`CsrfPolicy`, `Csrf.php`) and a comment in `nuxr-pwa.js`.
  - No actual runtime caller sets it manually, so HF-A1's stop rule was not triggered.
- **No route-matrix, schema, migration or server-authority change.** RouteMap, `CsrfPolicy`, controllers and migrations are untouched. Server-side CSRF is still mandatory.
- **Canonical refs untouched:** `feat` `7e3a557c`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `main` `500d3a2d`. Only `hotfix/pace-xhr-csrf` is pushed.
- No deployment, no production tag, no WP8.

## 6. Observations reported separately (not fixed; owner classification)

- **OBS-HF-1 — `autoload/server` and `autoload/plan` reachability for Agent and Sales (pre-existing; unchanged by the hotfix).**
  - The accepted WP2 RouteMap maps both to `CUSTOMER_READ`, which Agent (T) and Sales (A) hold.
  - An Agent or Sales session that POSTs to them with the session token receives **200**: the global list of enabled routers (`autoload/server`) and the per-type plan list without DataScope filtering (`autoload/plan`). This was demonstrated through `fetch`, which carried the token before the hotfix too. They cannot open `plan/recharge` (403), and Report gets 403 on both. Evidence: `evidence/obs_autoload_reachability.json`.
  - The hotfix does not change who can reach these endpoints: before the fix, `fetch` and forms already carried the token. Only the Admin-only recharge UI was broken.
  - **Recorded for the owner to decide** whether router names and legacy plan lists are acceptable for Agent / Sales. It was not changed (RouteMap and authority are out of hotfix scope).
- **OBS-HF-2 — legacy DataTables page errors** on `coupons/list` (above). Pre-existing upstream code; not in scope.
- **OBS-HF-3 — AVAIA (observation only).** During the browser run, `avaia-wp2-pg-base` (no compose label) showed a new start time: 08:00:23Z → 08:53:54Z, `RestartCount` 0, i.e. a stop / start or recreate rather than a crash restart. A new `avaia-wp2-pg-rehearsal` container appeared.
  - This session touched only the `nuxbill-reseller-test` project; this is AVAIA-side activity on the shared host.
  - All other protected containers were unchanged in status, health and restart count.

## 7. Residuals

- XHR instances constructed **before** the shim runs keep their earlier `open` / `send` references. There are none today: all callers run after DOM ready or on user action. This is unchanged from the plan (R2).
- Staff same-origin GET XHRs now carry the token, as fetch and the customer portal already did (OD-HF-1, R3).
- Pace's static constants (`XMLHttpRequest.DONE` null on staff) are pre-existing and unused (R4).
- jQuery `error:` option callbacks are not invoked when the WP7 prefilter aborts offline; `.fail()` is. This is accepted WP7 behaviour (R5).
- The evidence comes from the headless Chromium lab (R6).
