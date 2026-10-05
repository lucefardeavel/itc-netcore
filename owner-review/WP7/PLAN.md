# WP7 — Staff PWA: implementation plan (locked)

**Status:** WP7 PLAN ACCEPTED WITH DECISIONS ("WP7 OWNER PLAN REVIEW IS COMPLETE", 2026-10-05).

**Implementation is not authorized yet:** it waits for the owner's implementation GO.

This file is the canonical WP7 plan. It merges two sources:
- the planning reconnaissance (the draft "WP7_PLAN_DRAFT_READY_WAITING_OWNER_REVIEW");
- the owner's final decisions OD-W7-1 … OD-W7-4 and amendments A–O.

Where the draft and the owner amendments differ, **the amendments win**. Draft items the owner removed, such as `pwa/start`, the PHP `pwa/offline` route, the runtime static cache and `minClient`, are not part of WP7.

| Item | Value |
|---|---|
| Base (canonical) | `feat/reseller-system-v1` = `8becd6b9f5ec2eb81099baa5f5247e9ddf98267c` (WP6C accepted) |
| Branch | `wp7/staff-pwa`, from `8becd6b9`; this plan is its first commit. Additive commits only. |
| Untouched | `main` = `500d3a2d15003efeed6e0aad639c13d08101c3b1`; `wp6c/mobile-adaptive-ux` = `757d8de969e98787a270facb956a4c543fec7eaa` (frozen) |
| Stale input corrected | `prompts/WP7_PWA.md` names WP6B as the execution base. The binding base is `8becd6b9`. |
| Goal | Installable, secure, staff-side PWA behaviour **without changing business authority**. WP6A / WP6C solved the adaptive UX and are not redesigned. |

---

## 1. Owner decisions

| ID | Decision |
|---|---|
| **OD-W7-1** | **Option B: interim deterministic PWA icon.** It is derived from the accepted WP6C application mark: the company / application first initial on the WP6C primary `#3442c9`, a simple square / rounded square, with maskable-safe artwork inside the central safe zone.<br>• Sizes: **180×180, 192×192, 512×512, 512×512 maskable**, generated deterministically during implementation.<br>• No AI redraw, no reinterpretation of a corporate logo, no upscaling of the 128×128 stock icon, no wordmark distortion.<br>• **Interim product identity, not a locked brand.** Files are isolated under `ui/ui/images/pwa/` so a later brand package replaces them without architectural change.<br>• The manifest `name` still comes from `CompanyName`. |
| **OD-W7-2** | **Approved with amendments.** A new public controller **`pwa`** with **only** `pwa/manifest` and `pwa/ping`:<br>• GET only, no capability;<br>• no authenticated business data, no mutation;<br>• **not** in `RouteMap::API_ALLOWED`.<br>The RouteMap change and its **hash re-pin are owner-authorized**.<br>Also authorized:<br>• the narrow mutation-gate hook in `ui/ui/nuxr-csrf.tpl`;<br>• the staff header / footer / login integration;<br>• the declarative critical-flow markers;<br>• Arabic strings;<br>• WP7 guards and tests.<br>**Removed:** `pwa/start`. `start_url` is the existing staff login `./?_route=admin`; `admin.php` redirects an authenticated staff member to the dashboard.<br>**Removed:** the PHP offline route. A static `nuxr-offline.html` is used instead. |
| **OD-W7-3** | **Option A.** Existing logout and session-expiry routing is **not** changed in WP7. If it lands on the customer login, that is a documented non-blocking residual. The manifest launch uses the staff `admin` route. |
| **OD-W7-4** | **Option A.** The PWA browser / security QA harness is committed under **`dev/pwa-lab/`**:<br>• test-only, Python Playwright;<br>• no production runtime dependency, no frontend build system, no Node in production;<br>• secure-context assertions are mandatory. |
| Spec 11 deviation | "Cached read-only last-known data", "open previously loaded customer summary" and local drafts are **not** implemented. This is an owner-directed stricter policy (directive P2 / amendment B), recorded for acceptance. |

## 2. Owner architecture amendments (binding)

### A. Scope
- Staff PWA only. The customer portal stays outside WP7 (WP6C OD-3).
- The root-scoped worker may *see* customer, public and auth requests, because they share one application root. They stay **network-only** and never enter PWA storage.

### B. Strict no-business-data cache invariant
WP7 V1 stores **zero** authenticated business responses. Never persisted:
- staff pages and customer pages;
- Subscriber 360, customer search;
- finance, wallets, ledger, transfers, pricing;
- permissions / capabilities;
- activation and reconciliation;
- exports and reports;
- auth responses, CSRF, API responses;
- uploaded customer / staff images;
- server-rendered business fragments.

There is no last-known-data feature and no offline drafts.

### C. Precache only
- The worker cache holds **only**:
  - `nuxr-offline.html`;
  - the approved PWA icons;
  - any truly essential tiny static PWA-only resource, if implementation proves one is needed. It must be justified in the report.
- There is **no runtime cache**: no cache-first or stale-while-revalidate for application CSS, JS, fonts or images. Those stay ordinary network / browser resources.
- WP7 caching is for safe offline PWA behaviour, not performance.
- A guard fails if any application route or asset starts being runtime-cached.

### D. Registration and scope
- The worker is the static file **`APP_URL/nuxr-sw.js`** (repository root), registered at its **natural default scope**. Scope is not widened manually, and no `Service-Worker-Allowed` header is used.
- A worker at `/billing/nuxr-sw.js` controls `/billing/` only.
- Root and **subdirectory** installations are both required. The subdirectory lab test is mandatory.

### E. Navigation policy
- All dynamic navigations are network-only and never cached.
- A successful GET navigation returns the network response, and nothing is put in CacheStorage.
- A GET navigation that fails on the network gets `nuxr-offline.html`.
- A non-GET navigation that fails is not queued or replayed. It is redirected (303) to the safe GET offline document with **`reason=unconfirmed`**, so a reload cannot resend the POST. The text:

> The request could not be confirmed. It may or may not have reached the server. Reconnect and check the authoritative record before repeating the action.

It must never claim "nothing changed".

### F. Subresource policy
- Ordinary dynamic subresources are **not intercepted**: `_route=` data, search, autoload, the activation poll, ping, manifest, exports and downloads, uploads, editable pages, API, payment callbacks, the customer portal, external CDN and maps.
- Cross-origin requests are untouched.

### G. Connectivity heartbeat (`pwa/ping`)
- Public, GET only, `Cache-Control: no-store`, exact content type and marker, no user or business information.
- The client fetch uses **`credentials: 'omit'`**, so the staff session timeout is never extended.
- **Throwaway session cleanup:** `index.php` always starts a session. `pwa/ping` destroys that anonymous session when the request carried no session cookie, so heartbeat traffic does not accumulate session files. It never destroys a session a cookie identified.

### H. No `minClient`
- No client / server version negotiation in WP7.
- `docs/WP7_PWA_MODEL.md` documents a future extension point only: a later incompatible release may add a minimum-compatible-client protocol if actually required.

### I. Mutation gate
- While the confirmed state is **OFFLINE or RECONNECTING**, block same-origin non-GET/HEAD requests from the staff UI. **SLOW/UNSTABLE** submits normally, with the existing pending / double-submit safeguards.
- The gate is UX and safety only, never authorization. RouteGuard, CSRF, DataScope, Finance, Activation and the domain services remain authoritative.
- **CSRF shim hook (authorized):**
  - It may only **stop** a request.
  - It must not generate or alter a token, bypass token injection, replay a request, or send one later.
  - If `window.nuxrMutationGate` is absent, as on the customer portal, behaviour is unchanged.

### J. Install UX
- No prompt after login. A user-triggered "Install app" item sits in the staff account menu and More sheet.
- `beforeinstallprompt` is used only when supported; elsewhere the item shows generic manual instructions.
- No user-agent or device sniffing.
- Standalone mode is never required for any business operation.

### K. Update UX
- No automatic `skipWaiting()`: a new worker waits.
- Show "Update available"; the user must choose to update. Defer while in a critical flow (§9).
- When safe:
  1. the user presses Update;
  2. `SKIP_WAITING` is posted to the waiting worker;
  3. the page waits for `controllerchange`;
  4. it reloads **once**.
- Other tabs are never force-reloaded.

### L. Deep links
- No client router. Server routes stay canonical, and the worker never reinterprets authorization.
- Online deep links reach the server normally, so the RouteGuard / DataScope 403/404 shell applies.
- Offline deep links get only the static offline document.

### M. Notification-ready only
- No permission request, no push-subscription tables, no push-registration API, no campaigns.
- At most a `notificationclick` handler with no backend state. It accepts only a same-origin destination matching a strict route pattern, and otherwise opens `./?_route=admin`. No arbitrary URL from notification data.

### N. Secure-context QA
- `http://IP:8099` is not valid service-worker evidence.
- A test-only localhost mapping (`http://localhost:18099`) is used, with an assertion of `window.isSecureContext === true` before any PWA result is accepted.
- A subdirectory installation is also tested.
- No production TLS, no AVAIA.

### O. Branding replaceability (architectural invariant)
- PWA identity assets are replaceable presentation assets.
- Changing the system or commercial name, visual identity, logo, app icon or primary colour must not require changes to service-worker authority, routing, cache safety, permissions or business services.
- The interim WP7 icon is deliberately replaceable: assets live in `ui/ui/images/pwa/`, referenced from one place (the `Pwa` manifest builder plus the header link), with no branding in worker logic beyond the precache file list.

## 3. Reconnaissance facts the plan relies on (verified at `8becd6b9`)

1. No service worker, manifest or PWA code exists. The only client storage is `localStorage['mode']` (theme). `navigator.onLine` is unused.
2. `APP_URL` = protocol + Host + `dirname(SCRIPT_NAME)`, which supports subdirectories. Routes are `?_route=` or, with `url_canonical=yes`, pretty paths. Boot always honours `?_route=`.
3. **Staff and the customer portal share one application root** (`''` dispatches to dashboard, home or login), and staff routes have no path prefix.
4. **Login** is `admin/post` (CSRF, session regeneration, an HttpOnly SameSite=Lax `aid` cookie). **Logout** is a GET to `logout`: it clears the token and cookies, destroys and regenerates the session, rotates CSRF, sends `no-store`, then shows `admin/alert.tpl` → meta refresh to `login` (the customer login).
5. **`Admin::getID` extends `aid_expiration` on every authenticated request.** An expired session renders `_alert` HTML.
6. Authenticated HTML is `no-store` (PHP default `nocache` limiter). Legacy CSV exports send `Pragma: public` + `must-revalidate`, which is cacheable. There is no CSP.
7. `RouteMap.php` is **hash-pinned** in `tests/Unit/Wp3a/SourceGuardTest.php`. `RouteMatrixTest` **skips `public` rules** when building the matrix.
8. **Branding:**
   - the largest square asset is `ui/ui/images/logo.png`, 128×128 (the stock PHPNuxBill mark);
   - `phpnuxbill_transparan.png` is a 1508×624 wordmark;
   - the favicons are 30×30 and 48×48.

   None is a suitable icon source, hence OD-W7-1.
9. Playwright 1.63 is available with Chromium 153, which supports CDP `Page.getInstallabilityErrors`. Ports 8443, 8310/8311, 5433/5434 and 9000–9001 belong to AVAIA, and `127.0.0.1:18099` is free.
10. **Mutation paths** are inventoried in §8. `nuxr-csrf.tpl` submits `data-method` links and `nuxrPost` through the captured native `form.submit`, which fires no submit event, hence the shim hook.

## 4. Threat model

| Threat | Control |
|---|---|
| Cross-user leakage on a shared device | No business, user or auth response is ever stored (B, C). Prune on logout and on the staff login page. Authenticated pages stay `no-store`. Browser leak test (T13). |
| Stale or forged client state used as authority | No cached business data at all. The gate is UX only; the server is authoritative. No business rule reads `display-mode`. |
| Offline replay or duplicate mutation | No queue, Background Sync or replay. A failed non-GET navigation becomes a 303 to the GET offline document, so a reload cannot resend the POST. |
| Response lost after the server received a POST | The `reason=unconfirmed` wording (E): "may or may not have reached the server; check before repeating". This follows the "no blind retry of UNKNOWN" rule. |
| Captive portal (ISP hotspot) faking "online" | The ping is valid only with `200` + the exact JSON content type + the exact marker. Portal HTML counts as failure. |
| Heartbeat extending idle sessions | `credentials:'omit'`; throwaway-session disposal (G). |
| Worker interfering with customer / co-hosted apps | Natural scope (`APP_URL/`). Only navigations and the precache list are intercepted. Cache names are namespaced by scope. Customer traffic is network-only. |
| Malicious notification payload | Same-origin plus a strict route pattern; fallback to `./?_route=admin` (M). |
| Stuck or broken worker update | `register(..., {updateViaCache:'none'})`. A versioned precache. User-controlled activation. A static worker script, so there is no PHP session or `_alert` involvement in update fetches. |
| Manifest identity leak | Built only from public config. It is fetched without credentials (no `crossorigin` attribute), so it is byte-identical for every user. |

## 5. No-business-cache invariant (precise)

**The only entries ever present in `CacheStorage`** are those of the current precache list:
- `nuxr-offline.html`;
- `ui/ui/images/pwa/*` icons;
- any explicitly justified tiny PWA-only static file.

They live in **one** cache named `nuxr-pwa:<scopeHash>:<VERSION>`.

Consequences:
- No `cache.put` occurs outside the install / precache step.
- IndexedDB is never used.
- localStorage / sessionStorage hold only:
  - `mode` (theme, existing);
  - an anonymous install-dismissal flag;
  - transient `nuxr-*` UI state with no identity, token or business value.

The guards (§13) and browser tests T4, T5 and T13 enforce this.

## 6. Service-worker fetch table (`/nuxr-sw.js`)

| Request | Handling |
|---|---|
| Cross-origin (CDN, maps, tiles) | Not intercepted |
| Same-origin, outside the worker's natural scope | Not intercepted |
| **Navigation, GET** | `respondWith`: navigation-preload response, else `fetch(request)`. **Never stored.** On a network failure → cached `nuxr-offline.html` (status 503). |
| **Navigation, non-GET** (form POST) | `respondWith(fetch(request))`, a single attempt and never stored. On a network failure → `Response.redirect('<scope>nuxr-offline.html?reason=unconfirmed', 303)`. |
| Precache list URL (GET) | Served from the precache, falling back to the network |
| Every other subresource: `_route=` data, search, autoload, poll, `pwa/ping`, `pwa/manifest`, uploads, `pages/`, `system/api.php`, exports, application CSS / JS / fonts / images, customer portal | **Not intercepted** (browser network, never stored) |
| Non-GET subresource (fetch / XHR POST) | Not intercepted; gated in the page (§8) |

Lifecycle and messages:
- **`install`:** precache the list. **No `skipWaiting`.**
- **`activate`:** delete only `nuxr-pwa:<scopeHash>:*` caches other than the current one; enable navigation preload; `clients.claim()`.
- **`message`:**
  - `SKIP_WAITING`, accepted only as a user-initiated message from a controlled client;
  - `PRUNE`, which removes any non-current `nuxr-pwa:<scopeHash>:*` cache and any entry not on the precache list.
- **Logout:** a navigation to the logout route (query or pretty form) triggers `waitUntil(prune)` after the network response.
- **`notificationclick`** (optional, M): strict same-origin route validation; fallback to `./?_route=admin`.

`VERSION` is a single constant, equal to the WP7 asset version used in `header.tpl`; a guard checks this.

## 7. Connectivity state machine (`nuxr-pwa.js`)

**Probe:** `GET <scope>?_route=pwa/ping` with `{credentials:'omit', cache:'no-store'}` and a 4 s timeout.
- **Valid** only with HTTP 200, the exact JSON content type, and the exact marker body (for example `{"app":"nuxr","ok":true}`).
- A network error or timeout is a **failure**.
- A non-valid 200, such as captive-portal HTML, is a **failure**.
- **5xx** means the server is reachable but degraded → SLOW/UNSTABLE.

**Hints** that trigger an immediate probe: `navigator.onLine`, `online` / `offline` events, `visibilitychange` → visible, and a same-origin network error seen by the gate wrappers.

| From | Event | To |
|---|---|---|
| ONLINE | valid, RTT ≥ 2500 ms; or 1 failure (re-probe in 2 s) | SLOW/UNSTABLE |
| any | 2 consecutive failures; or (`navigator.onLine === false` and 1 failure) | OFFLINE |
| SLOW/UNSTABLE | 2 consecutive valid probes, RTT < 2500 ms | ONLINE |
| OFFLINE | 1 valid probe | RECONNECTING |
| RECONNECTING | 2 consecutive valid probes (about 2 s apart) | ONLINE (SLOW/UNSTABLE if RTT ≥ 2500 ms) |
| RECONNECTING | failure | OFFLINE |

- **Cadence:** ONLINE ~60 s; SLOW/UNSTABLE ~10 s; RECONNECTING ~2 s; OFFLINE starts at 5 s and backs off to a maximum of ~30 s. Normal polling pauses while the page is hidden.
- **Initial state:** ONLINE, because the page was just served by the network.
- **UI:**
  - a top-bar status chip (`role="status"`), hidden while ONLINE;
  - an OFFLINE / RECONNECTING banner: "You're offline — changes can't be sent; information on screen may not be current";
  - `body[data-nuxr-net]` for styling.

## 8. Offline mutation coverage matrix

**Rule:** same-origin and method ∉ {GET, HEAD} and state ∈ {OFFLINE, RECONNECTING} → blocked. Every blocked request shows an assertive "Not sent — you're offline" message.

| Path (staff UI) | Real callers at `8becd6b9` | Enforcement |
|---|---|---|
| POST form submit, `requestSubmit` | all legacy / WP6 forms; the WP6C confirmation sheet (`requestSubmit`) | `nuxr-pwa.js` capture-phase `submit` listener: `preventDefault` + `stopImmediatePropagation`, before the WP6A pending guard |
| Native `form.submit()` | `plugin-manager` upload; script-created forms | Shim hook in the patched `HTMLFormElement.prototype.submit` |
| `a[data-method="post"]` | 34 links in 20 staff templates | Shim hook before `nativeSubmit` in the delegated click handler |
| `window.nuxrPost` | `plan/active` (extend), `settings/app` (4 gateway tests), `coupons/list`, `customers/edit` and `admin/edit` (deletePhoto) | Shim hook before `nativeSubmit` |
| `fetch` POST | `customers/list` → `message/send_bulk_selected` | Shim `fetch` wrapper hook → reject with `TypeError` (not sent) |
| XHR / jQuery POST | `coupons/delete`, `plan/voucher-delete-many`, `message/send_bulk_ajax`, `custom.js` `autoload/server` / `autoload/plan` (read-only POSTs, also blocked) | Shim XHR `send` hook → `abort()` |
| Failed POST navigation (backstop) | any | Worker 303 → `nuxr-offline.html?reason=unconfirmed` |

The CSRF shim change is one guarded line per interception point:

```js
if (window.nuxrMutationGate && !window.nuxrMutationGate(kind, method, url, form)) { /* block */ }
```

- Token injection code is unchanged and still runs where it ran before.
- The customer portal never defines the gate.
- A test (§12) proves staff and customer token injection is unchanged.

## 9. Update critical-flow rules

A page is in a **critical flow** if any of these hold. All are presentation state, never business authority.
- `form[data-nuxr-submitted]` (pending submit, WP6A);
- `[data-nuxr-poll]` (activation in flight, WP6A);
- `dialog[open]` (confirmation, typed-confirmation, editor and ask sheets);
- `[data-nuxr-critical]`, the new declarative markers on:
  - the activation confirmation (`#nuxr-confirm-form`, `activation/create.tpl`);
  - the finance review / confirm form (`finance/review.tpl`);
- a POST form the user has changed since the page loaded (dirty tracking, where appropriate).

Behaviour:
- Update pressed during a critical flow → "Finish or leave the current task first"; nothing is activated, and the bar is offered again when the flow ends or on the next page.
- When safe → the 4-step sequence in K.
- Other tabs only show "Updated — reload when convenient".

## 10. Install UX
- An "Install app" item in the staff account menu (`header.tpl`) and the More sheet (`footer.tpl`).
- **`beforeinstallprompt`:** when it fires, it is stored with `preventDefault`, and `prompt()` is called only from the item.
- **Fallback:** a small sheet with generic manual instructions (browser menu → Install app / Add to Home Screen), with no user-agent branching.
- **Hidden in standalone mode** by CSS `@media (display-mode: standalone)`, and after `appinstalled`. There is no JS `matchMedia`, so the WP6C guard is respected.
- No prompt after login and no automatic sheet.

## 11. Manifest and identity

- `pwa/manifest` → `application/manifest+json`, `Cache-Control: no-cache`, JSON via `json_encode`, built by `Reseller\Ui\Pwa` from public config only:

| Field | Value |
|---|---|
| `id` | `"nuxr-staff"` (stable) |
| `name` | `CompanyName` |
| `short_name` | `CompanyName` truncated to 12 characters, multibyte-safe |
| `start_url` | **`"./?_route=admin"`** |
| `scope` | `"./"` |
| `display` | `"standalone"` |
| `lang` / `dir` | configured language |
| `theme_color` | `#ffffff` (WP6C top bar) |
| `background_color` | `#f3f5fa` |
| `icons` | 192, 512, 512 `maskable` |

- Linked as `<link rel="manifest" href="{$app_url}/?_route=pwa/manifest">` on **staff pages and the staff login page only**, with no `crossorigin` attribute, so it is fetched without credentials and identical for everyone.
- The head also gets `theme-color` meta (light / dark variants) and an `apple-touch-icon` (180).
- **No per-user identifiers anywhere.**
- Icons are generated deterministically per OD-W7-1, with the exact generation recipe recorded in the report.

## 12. Exact expected files

**New runtime:**
- `nuxr-sw.js` (repository root: static worker)
- `nuxr-offline.html` (repository root: static, self-contained generic offline document with inline CSS)
  - no PHP, DB, CSRF or external assets;
  - Retry / Reconnect;
  - `reason=unconfirmed` text;
  - English plus Arabic text, with `dir` per block.
- `system/controllers/pwa.php` (`manifest`, `ping`; the `NUXR_RUNTIME` guard; unknown actions → 404 through a public, no-data default)
- `system/autoload/Reseller/Ui/Pwa.php` (pure manifest builder, version constant, notification route patterns)
- `ui/ui/scripts/nuxr-pwa.js` (registration, state machine, status UI, mutation gate, install, update, prune)
- `ui/ui/images/pwa/icon-180.png`, `icon-192.png`, `icon-512.png`, `icon-maskable-512.png` (interim)

**Modified accepted runtime:**
- `system/autoload/Reseller/Security/RouteMap.php` (`pwa` entry: `manifest` / `ping` public GET; **not** in `API_ALLOWED`)
- `ui/ui/nuxr-csrf.tpl` (gate hook)
- `ui/ui/admin/header.tpl` (manifest link, theme-color, apple-touch-icon, status chip, install item)
- `ui/ui/admin/footer.tpl` (`nuxr-pwa.js`, strings, More-sheet item, update bar)
- `ui/ui/admin/admin/login.tpl` (manifest link, registration, prune request)
- `ui/ui/styles/nuxr-shell.css` (status chip, banners, sheets, standalone rules)
- `ui/ui/admin/activation/create.tpl` and `ui/ui/admin/finance/review.tpl` (`data-nuxr-critical`)
- `system/lan/arabic.json`

**Tests:**
- `tests/Unit/Wp7/Wp7GuardTest.php`
- `tests/Unit/Wp7/PwaManifestTest.php`
- `tests/Integration/Wp7/PwaRoutesTest.php`
- `tests/Integration/Wp7/PwaCsrfGateTest.php`
- `tests/phpunit.xml`: `wp7-unit` / `wp7-integration` suites **and** `Unit/Wp7`, `Integration/Wp7` excluded from the generic suites (WP6C fix-round-1 lesson)
- `tests/Unit/Wp3a/SourceGuardTest.php`: the RouteMap pin updated (owner-authorized)
- `dev/pwa-lab/*` (Python Playwright harness, test-only)

**Docs and evidence:**
- `docs/WP7_PWA_MODEL.md`: the model, invariants B / C / O, and the future `minClient` and push extension points
- a WP6A / WP6C model amendment note
- `owner-review/WP7/{PLAN.md, REPORT.md, TESTDOX.txt, PWA_BROWSER_QA.md, visual/}`

Files from the draft that the simpler architecture no longer needs are **not** created: `pwa/start`, a PHP offline route or template, a runtime static cache, and `minClient`.

## 13. Source-guard / pinned-file authorization

- **RouteMap re-pin:** owner-authorized (OD-W7-2). The new pin is recorded in the report.
- **New WP7 guards** (`Wp7GuardTest`):
  - the worker has no `sync` / `periodicsync`, `indexedDB`, `skipWaiting` outside the user message handler, `requestPermission` / `pushManager` or external URL;
  - `cache.put` / `cache.add(All)` appears only in the precache step, over the literal precache list (**fails if any route or asset is runtime-cached**);
  - `nuxr-pwa.js` has no UA / device sniffing, no `matchMedia`, the ping uses `credentials:'omit'`, and no identity or token is written to storage;
  - `VERSION` equals the asset version;
  - the offline document has no `<script src>` / `<link href>` to external or PHP resources;
  - `pwa` is absent from `API_ALLOWED`;
  - the escaping guard applies to the new template snippets.
- **Existing guards stay green and unedited:** `Wp6aSourceGuardTest`, `Wp6cGuardTest` (`nuxr-shell.js` is unchanged), the route completeness check, `CliOnly`, `SecurityRoutesTest`, `AuthSessionTest`, `CsrfTest`.
- **Boundary (guard-asserted, empty diff):** schema / migrations / `install/`; Finance, Activation, Security (except the authorized RouteMap entry), Domain and Backoffice; capabilities; DataScope; `system/api.php` authority.

## 14. Route-matrix expected delta

`RouteMatrixTest` skips `public` rules (`tests/Http/RouteMatrixTest.php`, the role matrix loop). The `pwa/manifest` and `pwa/ping` entries are `public` GET, so the **expected delta is zero rows**:
- the matrix stays **1727 requests / 1354 denials / 373 allowed**;
- the rows are byte-identical to the WP6B / WP6C baseline on both engines.

Any other delta is a STOP-and-report item.

## 15. Secure-context lab (`dev/pwa-lab/`)

- The disposable UX lab container is published as **`127.0.0.1:18099`**, and Playwright uses **`http://localhost:18099`**.
- Every PWA test first asserts `window.isSecureContext === true` and `'serviceWorker' in navigator`, otherwise it fails.
- **Subdirectory installation (mandatory):** a second instance with the application under `/billing/` (docroot symlink). It checks the natural worker scope, the manifest, the offline fallback, cache naming, and that `/` outside `/billing/` is not controlled.
- **Offline:** `context.set_offline(True)` and a real origin outage (`docker pause` of the **lab** container only).
- **Slow connections:** a delayed `page.route` on the ping.
- **Only** the `nuxbill-reseller-test` compose project and its lab are used: no AVAIA ports or containers, no production TLS.

## 16. Test plan

**PHP tests (both engines):**
1. RouteMap: `pwa/manifest` and `pwa/ping` are public GET only; POST → 405; an unknown `pwa/*` action → 404 with no data; not in `API_ALLOWED`; `system/api.php` refuses them.
2. Manifest:
   - valid JSON with the required fields and `start_url` `./?_route=admin`;
   - `CompanyName` containing quotes, `</script>` or Unicode encodes correctly;
   - **byte-identical with and without a staff cookie**;
   - no per-user value.
3. Ping:
   - `no-store`, the exact content type and marker, no extra data;
   - a cookie-less request leaves no session file behind;
   - a request carrying a session cookie does not have its session destroyed;
   - the client guard asserts `credentials:'omit'`.
4. CSRF hook: staff and customer token injection unchanged; the gate is absent on customer pages.
5. Arabic catalog coverage for the new strings.
6. Unchanged and green: `AuthSessionTest` (login / logout behaviour), `CsrfTest`, `SecurityRoutesTest`, the WP6A / WP6C guards, route completeness.

**Browser suite (secure lab):**

| # | Check |
|---|---|
| T1 | Valid manifest |
| T2 | CDP installability inspection |
| T3 | Worker install / activate / control (root **and** `/billing/`) |
| T4 | CacheStorage = exactly the precache list |
| T5 | **No `_route=` or authenticated HTML anywhere in CacheStorage** after a full staff session |
| T6 | Offline launch |
| T7 | Offline deep link |
| T8 | POST form refused offline |
| T9 | `data-method` refused |
| T10 | fetch / XHR / jQuery POST refused, with a server request log proving nothing arrived |
| T11 | Reconnect OFFLINE → RECONNECTING → ONLINE |
| T12 | SLOW/UNSTABLE |
| T13 | Cross-user leakage: Admin → logout → Agent → Sales. CacheStorage, IndexedDB, local- and sessionStorage hold no previous username, customer identifier, finance value, permission, CSRF token, auth / session token or `ACT-` reference. |
| T14 | Update waiting |
| T15 | Update deferred during the activation confirm and the finance review |
| T16 | Explicit safe update + single reload; other tabs not reloaded |
| T17 | Deep-link authorization parity (same 403/404 shell with and without the worker) |
| T18 | Core online parity: worker active vs `service_workers='block'` |
| T19 | Arabic RTL status / install / update / offline surfaces |
| T20 | axe serious / critical = 0 on the new surfaces |
| — | Failed POST navigation → `reason=unconfirmed` page; reload does not resend |

## 17. Visual / accessibility QA

A curated WP7-only set of about 12 captures (the WP6C 37-capture sweep is **not** repeated):
- the status chip SLOW / OFFLINE / RECONNECTING on a phone (Arabic) and on desktop;
- `nuxr-offline.html` on a phone in Arabic and English, including `reason=unconfirmed`;
- the install item and the manual-instructions sheet;
- the update bar and the deferred message;
- the "Not sent" message;
- the standalone emulation (install item hidden).

Probes cover overflow at the configured width, touch targets, overlap with the bottom navigation, safe areas, axe, console errors and external requests.

## 18. Work slices

| Slice | Content | Focused tests |
|---|---|---|
| 0 | This plan (done in this commit). In the GO turn: `wp7` phpunit suites + excludes, the `dev/pwa-lab` secure-lab skeleton | lab self-check (`isSecureContext`) |
| 1 | `pwa` controller (`manifest`, `ping`, ping session disposal), `Reseller\Ui\Pwa`, RouteMap entry + re-pin | PHP 1–3, guards |
| 2 | Interim icons (OD-W7-1), `nuxr-offline.html`, `nuxr-sw.js` (precache, navigation policy, PRG, prune, versioning), registration in header / login | T1, T3–T7, subdirectory |
| 3 | Connectivity state machine, status UI, mutation gate + CSRF hook | PHP 4, T8–T12 |
| 4 | Logout / account-switch prune | T13 |
| 5 | Install UX | T1, T2, T19 |
| 6 | Update UX + `data-nuxr-critical` markers | T14–T16 |
| 7 | Deep links / parity, optional `notificationclick`, Arabic strings, a11y, `docs/WP7_PWA_MODEL.md` | T17–T20, PHP 5 |

**Cadence:** focused PHP tests and the relevant lab tests per slice. Expensive browser and visual suites are not repeated.

## 19. Candidate validation

ONE complete candidate validation, on the candidate SHA:
- MariaDB + MySQL full suites, `--testdox`: **zero failures, errors and PHPUnit warnings; every process exits 0**;
- variant registry;
- **route matrix once** (expected delta zero, §14);
- PHP 8.2 lint;
- manifest 42/42;
- migration / install and boundary diffs empty;
- secret sweep;
- one curated PWA browser suite (T1–T20, root + subdirectory) and the ~12 captures;
- AVAIA snapshot comparison (observation only).

Then: owner review → focused fixes → at most one final complete run, only if materially justified and owner-permitted.

Evidence goes in `owner-review/WP7/`. Push **only** `wp7/staff-pwa`; `feat` and `main` stay unchanged. No deploy.

## 20. Residuals known at planning
- **HTTPS prerequisite:** service workers need a secure context. Plain-HTTP deployments get no worker, install or offline page; the browser application works unchanged.
- iOS / Safari has no `beforeinstallprompt` (manual install), and worker storage eviction is possible. Device tests are emulated only.
- **Standalone logout / session expiry** lands on the customer login (existing behaviour, OD-W7-3 = keep).
- The gate is client-side UX; the server is unchanged and authoritative.
- Headless CDP installability may differ from desktop Chrome; the fallback is `Page.getAppManifest` validation.
- The theme preference persists across users on a shared device (non-sensitive, existing).
- The icon is interim (OD-W7-1).

## 21. STOP conditions (implementation)

Stop the affected item and return an owner decision if implementation reveals any need for:

1. a schema or migration change;
2. a new capability;
3. any DataScope change;
4. any Finance or Activation writer change;
5. any API authority expansion (including adding `pwa` to `API_ALLOWED`);
6. caching **any** authenticated business data, or any runtime cache beyond the precache list;
7. Background Sync, a business mutation queue, or any offline replay;
8. altering customer-portal business logic;
9. finding that the natural root / subdirectory worker scope cannot be made safe;
10. a route-matrix delta other than the expected zero rows;
11. changing login / logout / session-expiry routing;
12. any action on `main`, any deploy, or WP8 work.

Plan status: locked, waiting for the owner's implementation GO.
