# Pre-WP8 Hotfix — R-WP7-CARRY-01: Legacy Pace / XHR CSRF Header Compatibility — PLAN (planning only)

**Status:** `PACE_XHR_CSRF_PLAN_READY_WAITING_OWNER_REVIEW`. No runtime code has been changed. This is not WP8.

**Carry-forward:** `R-WP7-CARRY-01` (OPEN, pre-existing, not a WP7 regression; `owner-review/WP7/OWNER_DECISIONS.md`).

## 1. Baseline verification

| Ref | Expected | Origin (verified 2026-10-06) |
|---|---|---|
| `feat/reseller-system-v1` | `7e3a557c89d3d3f85a404b2ec82255e7c3b2af45` | match |
| `wp7/staff-pwa` | `8e7f0e1c024e6c319b5088896e001555d6c4f079` | match (frozen) |
| `wp6c/mobile-adaptive-ux` | `757d8de969e98787a270facb956a4c543fec7eaa` | match (frozen) |
| `main` | `500d3a2d15003efeed6e0aad639c13d08101c3b1` | match |
| `hotfix/pace-xhr-csrf` | did not exist | created from `7e3a557c` for this plan only |

The working tree was clean. All recon ran in the isolated `nuxbill-reseller-test` project: the PWA lab at `localhost:18099` (root) and `localhost:18098/billing/`. It was torn down afterwards, and AVAIA was unchanged (24 / 24 protected containers).

## 2. Exact pre-fix reproduction

The real staff stack ran in Chromium: jQuery 1.11.1, Pace 1.0.2, the WP7 client and the WP2 CSRF shim, as Admin `ad`. The flow was `plan/recharge` → check the recharge-type radio `#Hot` → `custom.js` runs `$.ajax({type:'POST', url: appUrl + '/?_route=autoload/server'})`.

| Installation | Service worker | Method | URL | `X-Requested-With` | `X-CSRF-Token` | Status |
|---|---|---|---|---|---|---|
| root `/` | active | POST | `?_route=autoload/server` | `XMLHttpRequest` | **ABSENT** | **403** (central `CsrfPolicy::enforceWeb` page) |
| root `/` | blocked | POST | same | `XMLHttpRequest` | **ABSENT** | **403** |
| `/billing/` | active | POST | same | `XMLHttpRequest` | **ABSENT** | **403** |
| `/billing/` | blocked | POST | same | `XMLHttpRequest` | **ABSENT** | **403** |

**Transport probes on the same staff pages.** The requests were fulfilled locally by the test, so they never reached the server.

| Probe | Root, `/billing/`, worker active and blocked |
|---|---|
| jQuery POST | `X-CSRF-Token` ABSENT |
| native `new XMLHttpRequest()` POST | ABSENT |
| `fetch` POST | **present** (the shim's fetch wrapper works) |
| jQuery GET | ABSENT (the shim intends the header on every same-origin XHR) |

**How the instance was created** (introspected on the page):
- `window.XMLHttpRequest` is WP7 `GatedXHR`, which calls `new Prev()`. `Prev` is the Pace function `function(b){var c;return c=new P(b),a(c),c}`, which returns a **native** instance.
- The instance's prototype is the native `XMLHttpRequest.prototype`, but `XMLHttpRequest.prototype` (the constructor's `.prototype`) is a **different** object.
- `x instanceof XMLHttpRequest` is `false`.
- `open` and `send` are own properties (WP7 per-instance; Pace patches own `open`).
- The native prototype's `open` is **unpatched**.
- No page errors.

**Predates WP7, proven twice:**
1. The order is the same since WP2 (`1c66ec5b`: `pace.min.js` at line 14, `nuxr-csrf.tpl` at line 193) and in WP6C (`757d8de9`).
2. Dynamic: with `nuxr-pwa.js` blanked at the network layer (test-side route, i.e. the pre-WP7 stack), `window.XMLHttpRequest` is the bare Pace function, and `autoload/server` is still POSTed without the token → **403**.

**Customer portal (no Pace):** jQuery POST and native XHR POST both carry the token. The shim's prototype patch works there.

## 3. Root cause

Pace 1.0.2 (`ui/ui/scripts/pace.min.js`, vendor) does this unconditionally at load. `y().on("request", …)` runs at module top level, so `paceOptions.ajax = false` does **not** prevent it:

```
P = window.XMLHttpRequest                                    // native constructor, captured privately
window.XMLHttpRequest = function (b) { var c = new P(b); a(c); return c }  // returns a NATIVE instance
w(window.XMLHttpRequest, P)                                  // copies statics as getters; the function keeps its own empty .prototype
```

The WP2 shim, which loads later in the staff footer, does this:

```
var xOpen = XMLHttpRequest.prototype.open, xSend = XMLHttpRequest.prototype.send;   // Pace's dummy prototype: undefined
XMLHttpRequest.prototype.open = function (...) { ... xOpen.apply(...) }             // patched onto the dummy object
XMLHttpRequest.prototype.send = function (...) { ... setRequestHeader('X-CSRF-Token') ... }
```

Native instances never inherit from Pace's dummy prototype, so the header code never runs. The token-less POST is then correctly refused by the central server policy.

The defect is in the client-side transport only. The server, RouteGuard and `CsrfPolicy` behave as designed and must stay as they are.

A side observation, pre-existing and **not** in scope: Pace's `w()` getter loop shares one closure variable. Static constants on the staff constructor are therefore unreliable (`XMLHttpRequest.DONE` reads `null` on staff pages and `4` on the customer portal). No application code uses `XMLHttpRequest.DONE` or `instanceof XMLHttpRequest`.

## 4. XHR / AJAX inventory (non-vendor, complete search)

The search covered: `new XMLHttpRequest`, `$.ajax`, `$.post`, `$.get`, `$.getJSON`, ajaxForm / ajaxSubmit, `api-get-text`, `XMLHttpRequest.DONE`, `instanceof XMLHttpRequest`, `XMLHttpRequest.prototype`, `window.XMLHttpRequest`, `fetch(`, `.load(`, axios, `ajaxSetup` and `ajaxPrefilter` across templates, scripts, controllers (`xheader` / `xfooter` strings), widgets and plugins (`system/plugin` has none).

| # | Caller | Surface | Method | Class | Needs CSRF | Pre-fix state |
|---|---|---|---|---|---|---|
| 1 | `custom.js` `autoload/server` ×3 (Hotspot / PPPoE / VPN branches, recharge form) | staff | POST | same-origin read via POST | **yes** (central policy) | **broken (403)** |
| 2 | `custom.js` `autoload/plan` ×3 | staff | POST | same-origin read via POST | **yes** | **broken (403)** |
| 3 | `message/bulk.tpl` `message/send_bulk_ajax` (jQuery) | staff | POST | same-origin **mutation** (`C::MESSAGE_BULK`) | **yes** | **broken (403)** |
| 4 | `coupons/list.tpl` `coupons/delete` (native XHR) | staff | POST | same-origin **mutation** (`C::SYSTEM_COUPONS`) | **yes** | **broken (403)** |
| 5 | `voucher/list.tpl` `plan/voucher-delete-many` (native XHR) | staff | POST | same-origin **mutation** (`C::VOUCHER_DELETE`) | **yes** | **broken (403)** |
| 6 | `custom.js` / `pppoe/add.tpl` `autoload/pool`; `custom.js` `pppoe_ip_used`, `pppoe_username_used`; `plan/deposit.tpl` `autoload/balance` | staff | GET | lookup | no | works |
| 7 | footer `api-get-text` (`autoload/bw_name`, `customer_is_active`) | staff | GET | lookup / polling | no | works |
| 8 | select2 `autoload/customer_select2` (`plan.php`, `message.php` `xfooter`) | staff | GET | search / autocomplete | no | works |
| 9 | `reports/list.tpl` `reports/ajax/*` (`$.getJSON`) | staff | GET | report data | no | works |
| 10 | `dashboard.tpl`, `community.tpl` `./version.json`, plus `raw.githubusercontent.com/.../version.json` | staff | GET | same-origin + **cross-origin** | no; cross-origin must NOT get it | works |
| 11 | `nicEdit.js` image upload to `api.imgur.com` | staff (editor pages) | POST | **cross-origin upload** (`upload.onprogress`) | must NOT get it | unaffected |
| 12 | `customers/list.tpl` `message/send_bulk_selected` (`fetch`) | staff | POST | same-origin mutation | yes | works (fetch wrapper) |
| 13 | `nuxr-shell.js` search and activation poll (`fetch`) | staff | GET | search / polling | no | works |
| 14 | WP7 heartbeat (`heartbeatFetch`, pre-shim) | staff | GET | identity-free ping | must NOT get it | works (WP7-accepted) |
| 15 | customer footer `api-get-text` (`autoload_user/*`); widget `autoload_user/isLogin` | customer | GET | lookup / polling | no | works |
| 16 | `custom.js` / `vendors.js` `pages/*.html` | public / customer | GET | static | no | works |

**Only Pace replaces `XMLHttpRequest`.** No other library does: jQuery 1.11.1 and 2.x resolve `window.XMLHttpRequest` at call time, and bootstrap, adminlte, select2, sweetalert2 and vendors.js do not touch it.

**No caller sets `X-CSRF-Token` itself.**

**Impact:** five staff same-origin XHR / jQuery POST paths (rows 1–5) are refused today. Three of them are real legacy mutations: bulk message, coupon bulk delete and voucher bulk delete. **A fix restores these existing, capability-gated functions.** The server still authorizes each one: RouteGuard and its capabilities, then CSRF.

## 5. Script-order map

| Page family | Order (relevant) | `window.XMLHttpRequest` at shim time | Shim XHR patch reaches instances? |
|---|---|---|---|
| Staff shell (`admin/footer.tpl`) | jQuery 1.11.1 → bootstrap → adminlte → select2 → **Pace** (replaces XHR) → summernote (some routes) → custom.js → nuxr-shell.js → pwa-config + **nuxr-pwa.js** (captures the native fetch for the heartbeat; wraps fetch for the network hint; `GatedXHR` wraps the Pace constructor; jQuery prefilter) → `$xfooter` (page scripts, select2 AJAX config, Leaflet) → inline legacy (`api-get-text`) → **nuxr-csrf.tpl** | `GatedXHR` (prototype = Pace dummy) | **no (defect)** |
| Staff login (`admin/admin/login.tpl`) | pwa-config + **nuxr-pwa.js** (`GatedXHR` wraps the **native** constructor; no Pace, no jQuery) → **nuxr-csrf.tpl** | `GatedXHR` (prototype = native) | yes |
| Staff print pages (`plan/invoice-print`, `print/by-date`, `print/by-period`, `print/voucher`) | jQuery (+ bootstrap) → **nuxr-csrf.tpl** | native | yes (no XHR callers) |
| Customer portal (`customer/footer.tpl`) | jQuery → bootstrap → adminlte → select2 → custom.js → `$xfooter` → inline → **nuxr-csrf.tpl** | native | yes |
| Customer login / public (`footer-public.tpl`, `login-custom-moon`, `reg-login-custom-moon`) | vendors.js (jQuery 2.x bundle) / sweetalert2 → **nuxr-csrf.tpl** | native | yes |

**Pace appears only in the staff shell footer.** The defect is therefore confined to the staff shell, and it affects every page that uses it.

## 6. Current wrapper-chain trace (staff shell, canonical)

**Construction** of `$.ajax({type:'POST', url:'?_route=autoload/server'})`:
1. jQuery calls `options.xhr()` → `new window.XMLHttpRequest()`, i.e. `GatedXHR`.
2. `GatedXHR` calls `new Prev()` (Pace) → `new P()`, giving a **native instance `x`**. Pace sets own `x.open = paceOpen(x.open_native)`.
3. `GatedXHR` captures `open = x.open` (Pace's) and `send = x.send` (native via the prototype). It sets own `x.open` and `x.send`, adds an `error` listener (the network hint) and **returns `x`**, the native object.

**Online send:**
1. jQuery sets `onreadystatechange` (jQuery 1.11 observes readystatechange only).
2. `x.open(...)` runs: `GatedXHR.open` records the method / URL, then Pace's `open` (tracks GET), then the **native** open.
3. jQuery calls `setRequestHeader` for `X-Requested-With`, `Content-Type` and `Accept`.
4. `x.send(body)` runs `GatedXHR.send`: `nuxrMutationGate('xhr', …)` is true (online), so it calls the **native** send.
5. **The CSRF header is never set**, because the shim's functions live on Pace's dummy prototype. The server returns 403, `readystatechange` reaches 4, and jQuery's error path runs (`status` 403).

**Offline send (WP7 OFFLINE):**
1. The jQuery prefilter calls `jqXHR.abort('offline')` before the transport, so no XHR is created.
2. A native caller hits `GatedXHR.send`, whose gate returns false. It sets `__nuxrBlocked`, schedules a synthetic `error` + `loadend`, and does **not** call the native send. The network hint skips blocked instances.

**Where the CSRF header should be added:** on the native instance, between `open` and the native `send`, for same-origin URLs only, exactly once.

## 7. Option comparison

All options were checked against the evidence. A and D were **feasibility-tested** by injecting their logic test-side into the live canonical staff page, with the worker active and blocked:
- `autoload/server` → **200** with exactly one correct token;
- jQuery POST, native POST and jQuery GET carry the token; cross-origin carries none;
- callbacks and events unchanged (`readystatechange` 2 / 4, `load`, `loadend`; jQuery success);
- offline, **0** requests sent (the WP7 gate is intact);
- no page errors.

| Option | Mechanism | Pace | WP7 | Customer portal | jQuery / events / upload / abort / timeout / responseType | Statics / `instanceof` | Duplicate-header risk | Blast radius | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| **A** Per-instance CSRF constructor wrapper in the shim (`window.XMLHttpRequest = CsrfXHR` over the current constructor) | one more wrapper layer | compatible (feasibility PASS) | compatible; `GatedXHR` stays inner | **changes the constructor** on native pages (identity, statics copied from a buggy Pace getter on staff), or needs a staff-only branch | unchanged (own `open` / `send` only) | needs constant copying; `instanceof` unchanged only if `.prototype` is reused | the existing prototype patch still applies on native pages, so a second `setRequestHeader` gives `token, token` unless the prototype path is removed | 4th global constructor replacement | viable, but more moving parts |
| **B** Script-order change (shim XHR part before Pace) | Pace would then wrap an already-patched native prototype | works in principle | **conflicts**: the WP7 heartbeat must capture the native fetch **before** the shim's fetch wrapper, and WP7 wraps the current XHR constructor after Pace; the shim would have to be split across the header / footer of several templates | needs the same split for customer templates | unchanged | unchanged | low | **largest**: shared order on 10 templates, WP7-accepted ordering guards | **not recommended** |
| **C** Pace-specific integration (`paceOptions` / vendor edit) | stop Pace replacing XHR, or adapt it | `ajax:false` does **not** stop the replacement (unconditional at load), so only a vendor modification works | n/a | n/a | n/a | n/a | n/a | vendor fork, upgrade-fragile; restoring the native constructor would drop Pace AJAX progress | **rejected** (vendor change strongly disfavored) |
| **D (recommended)** Patch the prototype **real instances inherit**: `XP = Object.getPrototypeOf(new XMLHttpRequest())`, with the shim's existing `open` / `send` logic | Pace's own `open` calls the patched `XP.open`; `GatedXHR`'s captured `send` is `XP.send` | compatible (feasibility PASS) | compatible: `GatedXHR` still gates first, the network hint is unchanged, the heartbeat is fetch-based and untouched | **identical** on native pages (`XP === XMLHttpRequest.prototype`, the same object the shim patches today) | unchanged; only the same two prototype methods already patched on the customer portal in production; upload, abort, timeout and responseType untouched | no constructor replacement, so statics and `instanceof` are exactly as today | one patch site, so one `setRequestHeader` per send; plus an explicit guard (§8) | **one file**, central, about 10 lines | **recommended** |

## 8. Recommended architecture (Option D, central, shim-only)

The change is inside the XHR section of `ui/ui/nuxr-csrf.tpl`. The fetch, form, `submit`, `nuxrPost` and `data-method` paths are untouched.

1. **Resolve the transport prototype from a real instance.** `var XP = Object.getPrototypeOf(new XMLHttpRequest())`, guarded by `try` and by `typeof XP.open === 'function' && typeof XP.send === 'function'`. On failure it falls back to `XMLHttpRequest.prototype`, which is today's behaviour.
   - Creating one unopened instance is inert: Pace tracks only on `open`, and WP7 only adds an `error` listener.
   - On native-constructor pages, `XP` is the same object as `XMLHttpRequest.prototype`.
2. **Patch `XP.open` / `XP.send` exactly once.** An idempotency marker on `XP` (`__nuxrCsrf`) guards against the shim being included twice. The existing lines stay **byte-for-byte**, including the WP7 gate call and its synthetic error:
   - `this._nuxrSame = sameOrigin(u)` in `open`;
   - `if (this._nuxrSame) { try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) { } }` in `send`.

   The token injection line asserted by the accepted `PwaCsrfGateTest::INJECTION` is unchanged.
3. **No duplicate header.** `XP.setRequestHeader` is wrapped only to note when a caller itself sets `X-CSRF-Token` (case-insensitive). In that case `_nuxrSame` is cleared, so the shim does not add a second value. No caller does this today, so this is defensive only.
4. **Unchanged:**
   - the same-origin rule: the header goes on every same-origin XHR, any method, which is the WP2 design and matches the fetch wrapper (OD-HF-1);
   - cross-origin never gets it;
   - the gate is consulted the same way.
5. **Order is unchanged:** Pace → WP7 client → page scripts → shim. No template, Pace or WP7 runtime change.

**Resulting staff chain:**
- **Online:** `GatedXHR.send` → gate (allowed) → `XP.send` (shim: header, then the gate again, a no-op) → native send.
- **Offline:** `GatedXHR.send` blocks first: synthetic error, nothing sent. This is unchanged WP7 behaviour.
- **Customer portal:** identical to today.

## 9. Exact expected files (implementation turn, after owner GO)

| File | Change |
|---|---|
| `ui/ui/nuxr-csrf.tpl` | XHR section only: real-instance prototype resolution, once-only patch, duplicate guard (§8). Comment updated (R-WP7-CARRY-01). |
| `tests/Unit/HotfixPaceXhrCsrf/PaceXhrCsrfGuardTest.php` (new) | Source guards. See §12 G1–G6. |
| `tests/phpunit.xml` | new suite `hotfix-pace-unit`; the folder excluded from the generic unit suite (WP6C fix-round-1 lesson) |
| `dev/pwa-lab/pwa_suite.py` | new lab checks (T21–T23, §12). Test tooling only. |
| `docs/WP2_PERMISSION_MODEL.md` | the CSRF-shim paragraph: XHR interception targets the prototype real instances inherit (Pace note) |
| `docs/WP7_PWA_MODEL.md` | R-WP7-CARRY-01: status → closed by the hotfix (after acceptance) |
| `owner-review/HOTFIX_PACE_XHR_CSRF/{REPORT.md, browser evidence}` | evidence |

**Not touched:**
- Pace (vendor) and jQuery;
- `nuxr-pwa.js`, `nuxr-sw.js`, all templates other than the shim, `custom.js`;
- RouteMap / `API_ALLOWED`, `CsrfPolicy` / `Csrf.php`, controllers;
- schema / migrations / install, capabilities, DataScope;
- Finance / Activation / wallet / pricing, RADIUS / MikroTik;
- PWA caching.

There is no new dependency.

## 10. Security invariants (each one tested in §12)

1. Same-origin XHR and fetch mutations carry the correct session token: exactly one value, equal to `window.nuxrCsrfToken`.
2. Cross-origin XHR and fetch never carry `X-CSRF-Token`: `api.imgur.com` uploads, the GitHub `version.json` request and synthetic cross-origin probes.
3. The WP7 heartbeat carries no Cookie, Authorization, `X-CSRF-Token` or Referer. It is fetch-based and pre-shim, so this is unchanged.
4. The WP7 offline gate keeps working for fetch, XHR, jQuery, forms, `form.submit`, `nuxrPost` and `a[data-method=post]`, with 0 business requests sent.
5. Customer-portal CSRF protection is unchanged.
6. The server's CSRF enforcement is unchanged and stays mandatory. A token-less POST is still 403 (`CsrfTest` / `PwaCsrfGateTest`).
7. No retry, queue, replay or deferred mutation. The fix only adds a header at send time.
8. The token is only ever read from the server-rendered `window.nuxrCsrfToken`. It is never generated, altered or stored (existing `Wp7GuardTest` guard: one `token =` assignment).

## 11. Compatibility invariants

The following are unchanged:
- jQuery success / error / complete;
- XHR `readystatechange`, `load`, `error`, `abort`, `timeout` and `upload` events;
- `responseType`, `status`, `response` and `responseText`.

These are covered because the fix only adds a header inside the existing `open` / `send` wrappers, the same code that runs on the customer portal today.

Also covered:
- Pace request tracking: Pace's own `open` remains in the chain.
- The WP7 network-error hint and the WP7 synthetic offline error.
- Browser mode and installed-PWA mode: same DOM and scripts.
- Root and `/billing/`.
- Statics and `instanceof`: no constructor replacement.

## 12. Focused regression matrix

**Source guards (PHPUnit unit, new `PaceXhrCsrfGuardTest`):**

| ID | Guard |
|---|---|
| G1 | The shim derives the XHR patch target from a real instance (`Object.getPrototypeOf(new XMLHttpRequest())`) with the documented fallback, and never assigns `window.XMLHttpRequest`. |
| G2 | One idempotent patch of `open`, `send` and `setRequestHeader`; the token line is byte-identical; same-origin only. |
| G3 | The duplicate guard: a caller-set `X-CSRF-Token` suppresses the shim's own header. |
| G4 | Unchanged vendor and accepted files: `pace.min.js` and `jquery.min.js` sha256 pinned; the footer, login and customer script order unchanged. |
| G5 | No new `retry`, `queue`, `replay`, `localStorage` or `sessionStorage` in the shim. The existing WP7 guards stay green. |
| G6 | The inventory regression: no template or script sets `X-CSRF-Token` manually. |

**Browser checks (lab; root and `/billing/`; worker active and blocked):**

| ID | Check | Pass criterion |
|---|---|---|
| 1 | Pre-fix reproduction, recorded in this plan | `autoload/server` 403 without token (done above) |
| 2 | **T21** `plan/recharge` → `autoload/server` and `autoload/plan` (real server) | **200**, server list HTML in `#server`, exactly one correct `X-CSRF-Token`, one request each |
| 3 | Online jQuery POST (`type:'POST'`) | one request, header present and correct, success callback |
| 4 | Native XHR POST | header present, events `rs2,rs4,load,loadend`, `status` as served |
| 5 | fetch POST | header present (unchanged) |
| 6 | Cross-origin XHR POST and GET (`https://cross.example.invalid`, CORS fulfilled) and a nicEdit-style upload with `upload.onprogress` | **no** `X-CSRF-Token`; upload progress events fire |
| 7 | GET XHR (jQuery and native) | same-origin header present (WP2 semantics, OD-HF-1); response unchanged |
| 8 | **T22** customer portal: jQuery POST and native POST (fulfilled locally), real `api-get-text` GETs | header present exactly once (unchanged), no page errors |
| 9 | WP7 OFFLINE: XHR, jQuery, fetch POST | **0** business requests; jQuery `.fail('offline')`; native synthetic `error` + `loadend` (T10 rerun) |
| 10 | WP7 SLOW (3 s latency) | jQuery POST sent normally, with the header (T12 rerun + header check) |
| 11 | Genuine XHR network failure (`ctx.route` abort) | the caller sees `error` once, one immediate heartbeat, request not retried, header present on the failed request (T12n rerun + header) |
| 12 | Heartbeat identity | T12h rerun: no Cookie / Authorization / CSRF / Referer |
| 13 | Worker active vs blocked parity | 2–11 in both modes (T18 / T10c rerun) |
| 14 | Root and `/billing/` | 2, 3, 6, 9 at both |
| 15 | Pace operational | Pace's own `open` is in the chain (`ownOpen` source), `Pace.running` toggles on a tracked GET, no page or console errors on `plan/recharge`, `message/bulk`, `coupons/list`, `voucher/list`, `reports/list` |
| 16 | **T23** no duplicate header | header value equals the token exactly (no comma-joined duplicate) on 3, 4 and 7; a caller-set token yields one value |
| 17 | Legacy journeys (selected) | coupon bulk delete and voucher bulk delete on lab fixtures succeed via XHR (200 JSON `success`); bulk message with `test=on` returns JSON (no send); Admin only |

**HTTP (PHPUnit, focused, both engines):** `CsrfTest`, `AuthSessionTest`, `SecurityRoutesTest`, `wp7-unit`, `wp7-integration` (`PwaCsrfGateTest` asserts the unchanged injection lines and that the server CSRF is unchanged).

## 13. Candidate validation proposal

- **During implementation:**
  - G1–G6 and the WP7 guards;
  - focused HTTP CSRF / Auth / Security and `wp7-*` on both engines;
  - the focused browser checks T21–T23 plus the T10, T10b, T10c, T12, T12h, T12n, T18 reruns.
- **Candidate:**
  - the same focused PHP on MariaDB and MySQL;
  - the **complete WP7 browser suite once** (all checks + T21–T23);
  - selected legacy AJAX journeys (§12 #17);
  - lint of changed files only (no PHP runtime change expected);
  - secret sweep of the diff;
  - pack manifest.
- **Full 927-test suites: argued not required.** The expected diff has **no PHP runtime change**: one template's client-side JS, plus tests and docs. PHPUnit exercises the server side, which does not change. Owner decision OD-HF-3 can request it anyway.
- **Route matrix: not rerun** (RouteMap unchanged).
- **Visual QA: not repeated** (no CSS or markup change).

## 14. Stop conditions

STOP and report to the owner if any of these happen:
- the fix needs a change to Pace (vendor), jQuery, `nuxr-pwa.js` / `nuxr-sw.js` (accepted WP7 runtime), a template other than the shim, or script order;
- server CSRF enforcement would need relaxing, or any token-less POST would need allowing;
- schema, migration, RouteMap, capability, DataScope, Finance, Activation, wallet / pricing, RADIUS / MikroTik, API authority, PWA caching or a new dependency appears necessary;
- any WP7 browser check regresses (heartbeat identity, offline gate, network hint, parity);
- cross-origin requests receive the token;
- a duplicate token value appears;
- the customer portal behaviour changes;
- the restored legacy POSTs reveal a server-side authorization defect (report it; do not fix it in the hotfix).

## 15. Risks / residuals

- **R1 — restored functions (intended).** Bulk message (`send_bulk_ajax`), coupon bulk delete and voucher bulk delete become usable again from the staff UI. They were effectively broken by this defect. They stay gated server-side by RouteGuard capabilities (`MESSAGE_BULK`, `SYSTEM_COUPONS`, `VOUCHER_DELETE`) and CSRF. The owner should be aware that these legacy functions come back.
- **R2 — instances created before the shim** (none today; all callers run after DOM ready) keep `send` / `open` references captured before the patch. This is unchanged and documented.
- **R3 — the header on same-origin GET XHRs.** This is the WP2 design and is already the case on the customer portal and for fetch. Staff GETs will carry it too after the fix (OD-HF-1).
- **R4 — Pace statics** (`XMLHttpRequest.DONE` null on staff) are pre-existing, unused and out of scope.
- **R5 — WP7 accepted behaviour** (observation): a jQuery caller using `error:` **options** is not called back when the WP7 prefilter aborts offline (jQuery 1.11 attaches option callbacks after prefilters). `.fail()` on the returned jqXHR is. The legacy callers above use `success:` only. This is unchanged by the hotfix.
- **R6 — real devices / production HTTPS:** evidence is from the headless Chromium lab, as for WP7.

## 16. Owner decisions required

| ID | Decision | Recommendation |
|---|---|---|
| **OD-HF-1** | The header scope for XHR: keep the WP2 design (every same-origin XHR, any method, as fetch and the customer portal do today), or restrict to unsafe methods (POST / PUT / PATCH / DELETE) everywhere | **Keep the WP2 design**: a single consistent rule, no customer-portal behaviour change, no new branch |
| **OD-HF-2** | Approve Option D (shim-only, prototype resolved from a real instance, plus the duplicate guard) | **Approve D** |
| **OD-HF-3** | Validation depth: focused PHP + complete WP7 browser suite + legacy journeys, **without** the full 927-test suites, because there is no PHP runtime change | **Focused**, unless the final diff unexpectedly touches PHP |
| **OD-HF-4** | Acknowledge R1: the restored legacy XHR functions (bulk message, coupon / voucher bulk delete) become usable again under their existing capabilities | Acknowledge |

## 17. Final recommendation

- **Reproduction succeeded.** It was reproduced on canonical `7e3a557c`, root and `/billing/`, with the worker active and blocked, and in a simulated pre-WP7 stack. The root cause is that Pace's constructor replacement leaves the WP2 shim patching a prototype no instance inherits.
- **Fix with Option D.** It is one central change inside `ui/ui/nuxr-csrf.tpl` that patches the prototype real instances actually inherit, once, with a duplicate-header guard. It needs no vendor, template-order, WP7-runtime, server-CSRF or PHP change. It was feasibility-proven in the live staff stack, and it leaves the customer portal byte-for-byte identical in behaviour.
- **Validate focused** (§13). Implementation waits for the owner's GO and the answers to OD-HF-1 … OD-HF-4.
