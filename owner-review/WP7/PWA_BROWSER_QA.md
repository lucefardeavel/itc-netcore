# WP7 — PWA Browser / Security QA and Curated Visual QA

## Run conditions

- **Lab:** the secure-context lab `dev/pwa-lab/` (OD-W7-4), container `nuxbill-reseller-test-pwalab` in the isolated `nuxbill-reseller-test` compose project, ports on 127.0.0.1 only:
  - root installation: `http://localhost:18099/`
  - subdirectory installation: `http://localhost:18098/billing/`
- **Code:** the lab copy was built from the candidate implementation SHA `10408df9`. The later commit `a98fba5c` adds test tooling only.
- **Browser:** Python Playwright, Chromium (headless).
- **Accessibility tool:** axe-core 4.10.2 (`wcag2a` + `wcag2aa`), a local copy that is not vendored.
- **Secure context:** every check first asserts `window.isSecureContext === true` and `'serviceWorker' in navigator`. A check that cannot prove a secure context fails; none is ever skipped.

Raw results:
- `browser/pwa_suite.json`: the single full run.
- `browser/pwa_suite_T18_isolated_rerun.json`: the isolated T18 rerun.
- `visual/pwa_visual.json`: the visual QA.

## Browser suite: one full run, 25 checks, 24 PASS + 1 anomaly (T18, below)

| # | Check | Result |
|---|---|---|
| T00 | Secure context at the root and `/billing/`; staff login on both; `APP_URL` correct for the subdirectory | PASS |
| T01 | Valid user-neutral manifest at both installations: `id` `/?_route=admin` and `/billing/?_route=admin`, 3 icons, `start_url` `./?_route=admin` | PASS |
| T02 | CDP installability inspection (persistent profile): no installability errors | PASS |
| T03 | Worker installs, activates and controls at both installations with its natural scope; a page outside `/billing/` is not controlled | PASS |
| T04 | CacheStorage = exactly the 5 precache entries in one scope-hashed cache per installation (`nuxr-pwa:8acf0404:wp7.1`, `nuxr-pwa:82c75d71:wp7.1`) | PASS |
| T05 | After a full staff session: no `_route=` or authenticated response in CacheStorage; no identity, customer, finance or token text | PASS |
| T06 | Offline launch (`start_url`) shows only the static offline document (503) | PASS |
| T07 | Offline deep links (customer / activation / finance, subdirectory) show only the generic document | PASS |
| T07b | Failed POST navigation → 303 → `nuxr-offline.html?reason=unconfirmed`; the reload sent no POST | PASS |
| T08 | OFFLINE: a POST form through the WP6C confirmation sheet (`requestSubmit`) is stopped: 0 POSTs, notice shown, form not left pending | PASS |
| T09 | OFFLINE: `a[data-method=post]`, `nuxrPost` and native `form.submit()`: 0 POSTs | PASS |
| T10 | OFFLINE: `fetch`, `XMLHttpRequest` and jQuery POST: 0 POSTs (server log). The caller sees the failure. | PASS |
| T10b | Customer portal / public pages: no gate defined, CSRF shim intact | PASS |
| T11 | Reconnect OFFLINE → RECONNECTING → ONLINE through the heartbeat; POST blocked while reconnecting | PASS |
| T12 | SLOW (3 s latency): chip shown; POST still allowed | PASS |
| T13 | Cross-user hygiene, Admin → logout → Agent → logout → Sales: local / session / IndexedDB empty; no previous identity, customer, finance, token or ACT- reference | PASS |
| T13b | Prune keeps another application's cache on the origin and removes only this installation's stale version | PASS |
| T14i | Install: nothing automatic after login; manual sheet when no prompt; a deferred `beforeinstallprompt` is used once, on click; the standalone rule is verified in the live CSSOM (see the notes) | PASS |
| T14 | Update waiting at both installations: offered, no self-activation for 4 s, offered again on the next page | PASS |
| T15 | Update deferred for the activation confirmation, finance review, dirty POST form, `dialog[open]` and `form[data-nuxr-submitted]`; offered again on a non-critical page | PASS |
| T16 | Explicit update: exactly one reload of this tab; old cache pruned; the other tab is not reloaded and shows "updated in another tab" | PASS |
| T17 | Deep-link authorization parity with / without the worker: 24 deep links (Agent, Sales, Report roles) identical, 9 of them denials | PASS |
| T18 | Core online parity, worker vs `service_workers='block'`, 10 pages + a reviewed POST at both installations | **ANOMALY** in the full run (below); PASS on the isolated rerun |
| T19 | Arabic RTL: offline / reconnecting / slow chip, banner, "Not sent", install item + sheet, update bar + deferred, offline documents (`lang` / `dir` per block) | PASS |
| T20 | axe serious + critical = 0 on 10 WP7 surface audits (phone Arabic + desktop English); no phone target < 44 px; no horizontal overflow | PASS |

### T18 anomaly (reported separately, not hidden)

**What failed.** In the full run, at the root installation, `customers/list` in the context **without** a service worker returned identical status, final route, title, h1, forms and alerts, but `window.nuxrCsrfToken` was `undefined` when the page facts were read. This is the only differing field. The same signature appeared once during development, in T17 (`browser/T17_development_observation.json`, `customers/view` in the no-worker context).

**What the evidence shows**
- **The server side is clean.** The lab server log for the run has no 5xx and no PHP fatal error. All 39 `customers/list` requests completed with normal statuses, so the response was not truncated.
- **The token comes from the footer.** It is printed by the footer CSRF include. Its absence while the header and content fields are present means the page facts were read from a document that had not yet run its footer script. That points to the test's read timing (the Playwright `networkidle` / evaluate race), not to the HTML.
- **No extra navigation was observed.** An instrumented reproduction recorded every main-frame navigation: 3 × T18 (about 60 visits, both contexts) plus 4 rounds of separate contexts (40 visits). No extra navigation and no missing token occurred.
- **Reruns pass.** The plain isolated rerun of T18 passed at both installations, and 3 earlier T17 reruns passed.
- **No WP7 worker is involved.** The context without a worker has service workers blocked, so the WP7 worker is not on this path. The only WP7 code there is the client, which never touches `nuxrCsrfToken`.

**Classification.** Intermittent test-measurement anomaly in the new WP7 lab tooling: unexplained, not reproduced, not a product failure on the evidence above. **The suite was not changed to hide it.** For owner review.

**Found and fixed during development: a real client bug.** Under `service_workers='block'`, Playwright resolves `register()` without a registration object, and the update hook then threw. `bd92412e` hardened `nuxr-pwa.js` against it.

## Curated visual / accessibility QA (12 captures, `visual/`)

The WP6C 37-shot sweep was **not** repeated. Each capture was probed for:
- horizontal overflow at the viewport width;
- WP7 controls under 44 px on a phone (WP6A desktop controls are 40 px by design);
- collision of the WP7 fixed surfaces with the bottom navigation;
- axe serious / critical violations;
- console and page errors (the expected offline network failures are counted separately);
- requests to any origin other than the lab.

| Capture | Surface | Overflow | < 44 px | Bottom-nav collision | axe S/C | Console / page errors | External |
|---|---|---|---|---|---|---|---|
| V01 | SLOW chip (phone, Arabic) | no | 0 | none | 0 | 0 | 0 |
| V02 | OFFLINE chip + banner (phone, Arabic) | no | 0 | none | 0 | 0 (offline network lines only) | 0 |
| V03 | Mutation blocked: "Not sent" above the bottom nav (phone, Arabic) | no | 0 | none | 0 | 0 (offline network lines only) | 0 |
| V04 | RECONNECTING chip (phone, Arabic) | no | 0 | none | 0 | 0 | 0 |
| V05 | Manual-install sheet (phone, Arabic) | no | 0 | none | 0 | 0 | 0 |
| V06 | Update available (phone, Arabic) | no | 0 | none | 0 | 0 | 0 |
| V07 | Offline deep link → generic offline document, full page (phone; English + Arabic blocks) | no | 0 | none | 0 | 0 (offline network + the document's 503) | 0 |
| V08 | Real failed POST navigation → `reason=unconfirmed`, full page (phone) | no | 0 | none | 0 | 1: **lab-only** 404 for `system/uploads/user.default.jpg.thumb.jpg` on the legacy customers/edit page before going offline. The file is untracked upstream (known WP6C lab gap); not WP7. | 0 |
| V09 | OFFLINE chip + banner + "Not sent" (desktop, English) | no | 0 | n/a | 0 | 0 (offline network lines only) | 0 |
| V10 | Install item in the account menu (desktop, English) | no | 0 | n/a | 0 | 0 | 0 |
| V11 | Update deferred during the activation confirmation (desktop, English) | no | 0 | n/a | 0 | 0 | 0 |
| V12 | Another tab after the update: told, not reloaded (desktop, English) | no | 0 | n/a | 0 | 0 | 0 |

**Safe areas.** The fixed "Not sent" notice is positioned at `bottom: calc(var(--nx-bottom-h) + var(--nx-safe-bottom) + 12px)` on a phone, using the WP6C safe-area tokens. Headless Chromium cannot emulate device insets, so this is a CSS-level check.

**Standalone mode (install item hidden).** Not capturable: Chromium's CDP does not emulate `display-mode` (the `prefers-color-scheme` control works). The `@media (display-mode: standalone)` rule hiding `[data-nuxr-install]` is verified in the live CSSOM (T14i) and by `Wp7GuardTest`.

## Fix round 1 run

The complete WP7 browser suite was rerun once after fix round 1: **28 / 28 PASS**. This includes T17, T18 and the new T12h, T12n and T10c. Evidence: `browser/pwa_suite_fix_round_1.json`; details in `FIX_ROUND_1.md`. The T18 anomaly record above and its files are kept as prior evidence.
