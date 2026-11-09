# WP7 — Staff PWA Model

WP7 makes the **staff application** an installable Progressive Web App with safe connectivity behaviour. It is presentation, connectivity and safe-offline behaviour only. It adds:
- no schema or migration change;
- no capability, DataScope, Finance, Activation, pricing or API-authority change;
- no business caching, no mutation queue, no offline replay and no Background Sync.

The server (RouteGuard, CSRF, DataScope, Finance, Activation, sessions) stays authoritative for everything. Source of truth: the owner-locked plan `owner-review/WP7/PLAN.md` (`245e46bf`), decisions OD-W7-1..4 and amendments A–O.

## 1. Components

| Piece | File | Role |
|---|---|---|
| Manifest + heartbeat | `system/controllers/pwa.php`, `system/autoload/Reseller/Ui/Pwa.php` | `pwa/manifest` and `pwa/ping` only. Both are public GET with no capability and are **not** in `RouteMap::API_ALLOWED`. Any other `pwa/*` returns 404 with no body; POST returns 405. |
| Service worker | `nuxr-sw.js` (application root, natural scope) | Precaches the offline document and the icons. Navigations are network-only. Nothing else is touched. |
| Offline document | `nuxr-offline.html` (static) | A generic English + Arabic page (`lang` / `dir` per block) with Retry and the `reason=unconfirmed` text. It has no PHP, data, token or external asset. |
| Client | `ui/ui/scripts/nuxr-pwa.js` + `admin/nuxr/pwa-config.tpl` | Registration, connectivity state machine, status UI, mutation gate, install UX, update UX and prune. |
| CSRF hook | `ui/ui/nuxr-csrf.tpl` | `allowed(kind, method, url)` before every script-driven send. It can only **stop** a request; token injection is unchanged. |
| Icons (interim, OD-W7-1) | `ui/ui/images/pwa/icon-{180,192,512,maskable-512}.png`, recipe `dev/pwa-lab/make_icons.py` | Deterministic first-initial icons, explicitly interim (§8). |

The staff header, footer and login page include the client, the manifest link, `theme-color` and the apple-touch-icon. The customer portal and public pages include **none** of them, so `window.nuxrMutationGate` is absent there and their behaviour is unchanged.

## 2. Invariants

- **B — no business cache.**
  - CacheStorage holds exactly the literal precache list in one cache, `nuxr-pwa:<scope-hash>:<VERSION>`. It is fetched with `credentials:'omit'`.
  - There is no runtime cache, IndexedDB, push subscription or notification permission.
  - Authenticated HTML, `_route=` responses, API, exports and uploads are never stored.
- **C — server authority.**
  - Navigations always go to the network.
  - A failed GET navigation gets the offline document (503).
  - A failed non-GET navigation is redirected (303) to `nuxr-offline.html?reason=unconfirmed`. That page says the request "may or may not have reached the server" and never claims that nothing changed. A reload cannot resend it.
  - Nothing is queued or replayed.
- **Mutation gate.**
  - While the heartbeat confirms OFFLINE or RECONNECTING, a same-origin non-GET request from the staff UI is stopped before it is sent, on every path: form submit, `requestSubmit`, `HTMLFormElement.submit`, XHR (per instance, because Pace replaces the constructor), jQuery, `fetch`, `nuxrPost` and `data-method`. The user sees a "Not sent" notice.
  - SLOW submits normally. The gate is UX only; the server still decides.
- **Heartbeat.**
  - `pwa/ping` is anonymous and identity-free (fix round 1, FR1-1). It is sent with the browser's own `fetch`, captured by `nuxr-pwa.js` before the WP2 CSRF shim wraps `window.fetch`, so it carries no `X-CSRF-Token`. It also sends no cookies (`credentials:'omit'`) and no `Referer` (`referrerPolicy:'no-referrer'`), uses `no-store`, and needs an exact JSON marker, so a captive portal page counts as a failure. Only the heartbeat uses the captured fetch; every ordinary request still goes through the shim.
  - A cookie-less ping or manifest request leaves no session file and no `Set-Cookie` behind (one controller-local helper, FR1-5). A cookie-identified session is never touched, and a ping never extends a staff session.
  - **Network-error hint (FR1-2).** A genuine network failure of an ordinary same-origin request (a `fetch` TypeError rejection or an XHR `error` event) triggers an immediate heartbeat, at most once per second. It is only a hint: the state changes solely from the heartbeat result, and the failed request is not retried, queued or replayed. The caller receives the same rejection or error. The heartbeat never passes through these wrappers, so there is no loop.
  - Cadence: ONLINE about 60 s, SLOW about 10 s, RECONNECTING about 2 s, OFFLINE 5 s growing to about 30 s. It pauses while the page is hidden.
- **Scope.**
  - The worker sits at the installation root (`/` or a subdirectory such as `/billing/`). It ignores requests outside its scope.
  - Prune touches only its own `nuxr-pwa:<scope-hash>:` namespace, never another application on the same origin.
- **Cross-user hygiene.**
  - The logout navigation prunes the namespace.
  - The staff login page clears only this client's `nuxr-*` session keys.
  - Nothing user-specific is ever stored.
- **No authority in the client.** No price, permission, scope or business state is decided or stored. The manifest is identical for every visitor, with no per-user value.

## 3. Install UX (amendment J)

- "Install app" appears in the account menu and the More sheet. It is hidden by default and offered only in a secure context.
- It is hidden in the installed app by CSS (`@media (display-mode: standalone)`) and after `appinstalled`.
- `beforeinstallprompt` is deferred and shown once, only from the user's click. Otherwise a generic manual-instructions sheet opens.
- There is no automatic prompt, no user-agent branching and no JS `matchMedia`.

## 4. Update UX (amendment K, plan §9)

- A new worker **waits**: the worker never skips waiting by itself.
- The page offers "Update". Nothing is offered on the first installation.
- If the user presses Update during a **critical flow**, nothing is activated and the bar shows "Finish or leave the current task first". A critical flow is any of:
  - `form[data-nuxr-submitted]`;
  - `[data-nuxr-poll]`;
  - `dialog[open]`;
  - `[data-nuxr-critical]` (the activation confirmation and the finance review);
  - a POST form the user changed.
- When safe: `SKIP_WAITING` is sent to the waiting worker, then the page waits for `controllerchange`, then reloads **this tab once**.
- Other tabs are never reloaded. They show "updated in another tab — reload when convenient".

## 5. Deep links and parity

- There is no client router. Server routes are canonical, and the worker never reinterprets authorization.
- Lab evidence:
  - T17: deep links give the same status, final route and denial with and without the worker;
  - T18: core pages and a reviewed POST are identical with the worker active and with `service_workers='block'`, at the root and in a subdirectory.
- `start_url` is the existing staff login (`./?_route=admin`). An authenticated staff member is redirected to the dashboard, an unauthenticated one sees the login.
- The manifest `id` is the installation's start path (`<path>/?_route=admin`), so two installations on one origin stay distinct apps.
- Logout and session-expiry routing are unchanged (OD-W7-3 = A).

## 6. Prerequisites and environment

- **HTTPS is a deployment prerequisite.** Service workers and installation exist only in a secure context. On plain HTTP the client offers nothing, and the application keeps working as a normal web page. WP7 changes no production TLS.
- The secure-context lab `dev/pwa-lab/` is test tooling only (OD-W7-4). It runs on `localhost:18099` (root) and `localhost:18098/billing/` (subdirectory).

## 7. Future extension points (not implemented)

- **Minimum compatible client (`minClient`)** — not implemented (amendment H). A later incompatible release may add a version handshake if it is actually required. Today the single `VERSION` (worker = client asset query = `Pwa::VERSION`, guard-tested) and the user-chosen update are enough.
- **Push / notifications** — notification-ready only (amendment M).
  - No permission is requested and no subscription is made.
  - The optional `notificationclick` handler is **not** shipped, because no notification can be shown without `showNotification`.
  - A future push work package would add a strict same-origin route-pattern `notificationclick` handler, with a fallback to `./?_route=admin` and no arbitrary URL taken from notification data.

## 8. Branding replaceability (amendment O)

- The interim icons are presentation files under `ui/ui/images/pwa/`. They are referenced from one place: the `Pwa` manifest builder plus the header and login `apple-touch-icon` link.
- The worker knows them only as precache file names.
- Replacing them (same names and sizes) needs no change to the worker logic, routing, cache safety, permissions or business services. Bump `VERSION` so installed clients refresh the precache.
- The name comes from `CompanyName` (fallback "ITC NetCore" when the stock placeholder is stored). `short_name` is its first 12 characters.

## 9. Guards and tests

- `tests/Unit/Wp7/Wp7GuardTest.php` checks:
  - the routes and controller, the pure builder, and the precache-only worker (one `cache.put`, no forbidden APIs);
  - a single version, the static offline document, and feature detection only;
  - that the CSRF hook can only stop a request, and the gate rule;
  - user-initiated install, and the user-chosen, deferred, single-reload update;
  - Arabic coverage and namespace-only prune.
- `tests/Unit/Wp7/PwaManifestTest.php` covers the builder.
- `tests/Integration/Wp7/PwaRoutesTest.php` and `PwaCsrfGateTest.php` cover HTTP: the manifest, ping, route surface, API refusal, the gate order, and unchanged token injection and server-side CSRF.
- `dev/pwa-lab/pwa_suite.py` runs browser checks T00–T20 at both installations. Fix round 1 added:
  - T12h: the heartbeat's request headers;
  - T12n: the network-error hint;
  - T10c: public and customer parity with the root worker installed.

## 10. Residuals

- HTTPS is required (above).
- iOS installation is manual (Share → Add to Home Screen). There is no `beforeinstallprompt` there, so the manual sheet applies.
- Logging out inside the standalone app lands on the legacy customer login page. Routing is unchanged by owner decision OD-W7-3 = A.
- The offline document is bilingual and static (English + Arabic). It does not follow the user's language setting.
- `short_name` can cut a word (it is a plain 12-character prefix).
- Chromium's CDP does not emulate `display-mode`. The standalone CSS rule is verified in the live CSSOM and by the guard instead.
- The interim icons are deliberately interim (OD-W7-1).
- **R-WP7-CARRY-01 — Legacy Pace / XHR CSRF header compatibility.** This predates WP7 and is carried forward; it is not fixed in WP7. The staff pages load Pace before the WP2 CSRF shim. Pace replaces `window.XMLHttpRequest`, so the shim's `XMLHttpRequest.prototype` patch never reaches the instances legacy jQuery creates. Their POSTs go out without `X-CSRF-Token` and are refused (403); one known example is `autoload/server` on the Admin `plan/recharge` page. The WP7 offline gate is unaffected because it wraps each instance created through the current constructor (`GatedXHR`) rather than the prototype. Recommendation: a dedicated pre-WP8 security / compatibility hotfix (owner decision). **Status: CLOSED — ACCEPTED HOTFIX** (owner acceptance 2026-10-06; accepted implementation / evidence `6d4f3f962482b6e125429da7ae459ae8dfd4214c`, runtime `349b864a`; `owner-review/HOTFIX_PACE_XHR_CSRF/OWNER_DECISIONS.md`). The shim now patches the prototype that real XHR instances inherit, so Pace-created instances receive the header.
