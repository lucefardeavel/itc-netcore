# WP7 — Owner Fix Round 1: Addendum

**Scope:** the owner review "WP7 OWNER REVIEW — FIX ROUND 1" of `95bf8bee`, fixes FR1-1 … FR1-6.

**Base:** `95bf8bee`. All commits are additive on `wp7/staff-pwa`: no amend, squash or rebase.

**Prior evidence is kept unchanged:**
- `REPORT.md`, `PWA_BROWSER_QA.md`, `TESTDOX.txt` and `visual/`.
- `browser/pwa_suite.json`: the candidate run, including the T18 anomaly.
- `browser/pwa_suite_T18_isolated_rerun.json` and `browser/T17_development_observation.json`.

**New evidence:** `browser/pwa_suite_fix_round_1.json`.

## Commits

| Commit | Kind | Content |
|---|---|---|
| `c48bb461` | runtime | FR1-1 heartbeat uses the pre-shim fetch; FR1-2 network-error hint; FR1-5 manifest anonymous-session disposal |
| `bce221b9` | runtime | FR1-1 follow-up: the heartbeat sends no `Referer` (found by the new header test) |
| `8b374fb6` | tests | guards, integration test, lab checks T12h / T12n / T10c, FR1-3 readiness |
| this commit | evidence | this addendum, the clean browser run, model-doc update, R-WP7-CARRY-01 |

## Changed files (`95bf8bee..`)

- **Runtime:**
  - `ui/ui/scripts/nuxr-pwa.js`
  - `system/controllers/pwa.php`
- **Tests:**
  - `tests/Unit/Wp7/Wp7GuardTest.php`
  - `tests/Integration/Wp7/PwaRoutesTest.php`
  - `dev/pwa-lab/pwa_suite.py`
- **Docs / evidence:**
  - `docs/WP7_PWA_MODEL.md`
  - `owner-review/WP7/FIX_ROUND_1.md`, `REPORT.md` (appended pointer), `PWA_BROWSER_QA.md` (appended pointer)
  - `owner-review/WP7/browser/pwa_suite_fix_round_1.json`

**Not touched:**
- RouteMap, `API_ALLOWED`, `SourceGuardTest`, migrations, install, schema;
- the CSRF shim `nuxr-csrf.tpl`, Pace, CSS and templates;
- any business, finance, activation, DataScope or permission code.

## FR1-1 — The heartbeat carries no identity

**Change.**
- The first statement of `nuxr-pwa.js` captures `heartbeatFetch = window.fetch.bind(window)`. The client loads before the CSRF shim on every staff page (footer and login), so this is the browser's own fetch.
- `probe()` is the only caller of `heartbeatFetch`. It sends `credentials:'omit'`, `referrerPolicy:'no-referrer'`, `cache:'no-store'` and `redirect:'error'`.
- Ordinary application requests still go through the shim.

**Follow-up.** The new header test found that the heartbeat still sent `Referer`, which is the current staff page URL and can name a customer (`customers/view/<id>`) or an activation (`ACT-…`). `bce221b9` removes it.

**Browser proof: T12h** (root and `/billing/`, an authenticated Agent page, the real worker active, actual request headers observed at the network layer).
- The heartbeat headers are exactly: `accept, accept-encoding, cache-control, connection, host, pragma, sec-ch-ua, sec-ch-ua-mobile, sec-ch-ua-platform, sec-fetch-dest, sec-fetch-mode, sec-fetch-site, user-agent`.
- **No** `Cookie`, `Authorization`, `X-CSRF-Token` or `Referer`.
- Neither the CSRF token value nor any cookie value appears in any header or the URL, and neither does the username or the password.
- The URL is exactly `<base>?_route=pwa/ping`.
- The response is exactly `{"app":"nuxr","ok":true}`, with no `Set-Cookie`.
- **Control:** an ordinary same-origin `fetch` from the same page **does** carry the page's `X-CSRF-Token`, so the shim is intact.

**Source guard (`Wp7GuardTest::testTheHeartbeatBypassesTheCsrfShimAndNothingElseDoes`):**
- the capture is the first statement;
- exactly one `(cfg.ping,` call site, and it is `heartbeatFetch(cfg.ping, …)` with `no-referrer`;
- no `fetch(cfg.ping` through the global (CSRF-wrapped) fetch;
- `nuxr-pwa.js` precedes `nuxr-csrf.tpl` in `footer.tpl` and `login.tpl`.

## FR1-2 — A same-origin network error triggers an immediate heartbeat

**Change.**
- `nuxr-pwa.js` wraps `window.fetch` before the shim does, so the chain is shim (adds the CSRF header) → hint wrapper → browser fetch.
- A `TypeError` rejection, i.e. a network failure, calls `networkHint(url)`. An `AbortError` or an HTTP status does not. The wrapper then rethrows the **same** error.
- Each XHR instance gets an `error` listener, which never fires for an HTTP status. Our own "not sent" synthetic error is excluded.
- `networkHint` checks same-origin, then calls `probeNow()` at most once per second.
- It never sets the state, never re-sends, never queues and never replays.
- The heartbeat uses `heartbeatFetch`, which does not pass through the wrapper, so there is no recursion.

**Browser proof: T12n** (an authenticated page, real worker, `navigator.onLine === true`, state ONLINE, after the load probe; the next scheduled ping is about 60 s away).
- **fetch POST** to a same-origin URL aborted at the network level:
  - the caller got `rejected TypeError`;
  - exactly **one** immediate ping, 0.57 s after the failure;
  - the ping succeeded, so the state **stayed online** with no state event;
  - the failed request was seen **once** (not retried) and it carried `X-CSRF-Token` (shim preserved).
- **XHR POST** aborted at the network level, with the heartbeat also failing:
  - the caller's `onerror` fired exactly **once** (`status` 0, `readyState` 4);
  - at that moment the state was still `online`;
  - an immediate ping followed 0.25 s later;
  - the **first** state change (`slow`) came after that ping, i.e. from the ping result, never set directly to offline;
  - the request was seen **once**;
  - the state then recovered to `online` from later pings once the heartbeat was allowed again.
- **States:** `['slow', 'online']`, all from pings.

## FR1-3 — T18 (and T17) stabilised without weakening

`visit()` now calls `page_ready()`.
- When the server HTML contains the footer CSRF include (`window.nuxrCsrfToken =`), the check waits, with a bounded 10 s timeout, for `typeof window.nuxrCsrfToken === 'string'` matching `^[0-9a-f]{64}$` before reading `PAGE_FACTS`.
- If the token never initialises, the wait raises and the check **fails**.
- The token comparison is unchanged. Nothing is ignored, retried or downgraded to a warning.
- Pages without the include (e.g. the bodyless `pwa/*` 404) are compared as before, including the token field.

**Results:**
- T17: 24 deep links identical (9 denials).
- T18: 10 pages + the funding-review POST identical at the root and `/billing/`.
- Both PASS in the focused run and in the complete run.

## FR1-4 — Customer / public parity with the root worker installed

**T10c**, phone viewport. For each mode, worker vs `service_workers='block'`:
1. Sign in as staff (Agent A); in worker mode the root worker is installed and controlling.
2. Log out, keeping the worker.
3. Visit the public `login`, `register` and `forgot` pages.
4. Sign in as the lab customer `mohammed.h`.
5. Visit `home`, then the harmless GET `accounts/profile`.
6. Send a safely isolated POST, `accounts/change-password-post`, with a wrong current password. The server refuses it and nothing changes.

**Results:**

| Step | Result (identical in both modes) |
|---|---|
| staff logout landing | 200 `logout` |
| public login / register / forgot | 200 each |
| customer login result | 200 `login/post` (legacy interstitial) |
| customer home | 200 `home`, signed in |
| customer profile | 200 `accounts/profile` |
| customer POST | 200 `accounts/change-password` (refused) |

- Every step: status, final route, title, h1, forms, CSRF token presence, `dir` and alerts are identical with and without the worker.
- With the worker, the pages **were** controlled by the root worker.
- `window.nuxrMutationGate` is undefined on every step, with no `nuxr-pwa.js` and no manifest link.
- CacheStorage still holds exactly the 5 precache entries, with no customer or public HTML or data.
- There are no `nuxr*` keys in local or session storage and no IndexedDB, in both modes.
- **Parity holds.** No customer-portal code was changed.

## FR1-5 — Anonymous manifest sessions cleaned up

`pwa.php` has one controller-local closure, `$nuxrDisposeAnonymousSession`, used by both `manifest` and `ping`. When no session cookie identified the request and a session is active, it clears and destroys the throwaway session and removes `Set-Cookie`. It never touches a cookie-identified session.

`PwaRoutesTest::testManifestLeavesNoAnonymousSessionAndNeverTouchesACookieSession`:
- 5 cookie-less manifest requests produce no `Set-Cookie`, leave the session-file count unchanged, and return identical bytes.
- Admin / Agent / Sales (`ad`, `A`, `A1`) manifest requests with their session cookie:
  - bytes identical to the anonymous manifest;
  - no `Set-Cookie`;
  - session-file count unchanged;
  - the session survives (dashboard 200 inside the shell).

The existing ping and user-neutral manifest tests still pass.

## FR1-6 — R-WP7-CARRY-01 (not fixed; carried forward)

**R-WP7-CARRY-01 — Legacy Pace / XHR CSRF header compatibility.**

| Item | Detail |
|---|---|
| Affected (known example) | legacy jQuery XHR POSTs on staff pages, e.g. `autoload/server` on the Admin `plan/recharge` page. They go out without `X-CSRF-Token` and the server refuses them (403). |
| Why it predates WP7 | Pace (WP6A shell asset order) loads before the WP2 CSRF shim. Pace replaces `window.XMLHttpRequest`, so the shim's `XMLHttpRequest.prototype` patch never reaches the native instances jQuery creates. WP7 changed neither Pace, the asset order, nor the shim's token injection. |
| Why WP7's offline gate still works | `nuxr-pwa.js` loads after Pace and wraps the **current** constructor, gating each instance (`GatedXHR`) rather than the prototype. T10 proves 0 POSTs offline for XHR and jQuery. |
| Recommendation | A dedicated, separately reviewed pre-WP8 security / compatibility hotfix to make WP2 header injection reach Pace-created instances. Owner decision. |

The same entry is in `docs/WP7_PWA_MODEL.md` §10.

## Validation (focused, as the owner instructed)

**PHP, both engines:**

| Suite | MariaDB | MySQL |
|---|---|---|
| `wp7-unit` + `wp7-integration` | OK (26 tests, 330 assertions) | OK (26 tests, 330 assertions) |
| `CsrfTest` (9) + `AuthSessionTest` (7) + `SecurityRoutesTest` (11) | OK (27 tests) | OK (27 tests) |

Also on MySQL (the RouteMap and source guards are untouched; run as a confirmation):
- `wp6a-unit,wp6b-unit,wp6c-unit,wp7-unit`: OK (41 tests, 481 assertions);
- `RouteMapTest` + `SourceGuardTest`: OK (49 tests).

**Browser:**
- Focused: T12h, T12n, T10c, then T10, T10b, T11, T12, T17, T18. All PASS.
- **Complete WP7 browser suite, run once at runtime `bce221b9`** (lab synced to it), both installations, secure context: **28 / 28 PASS**. Evidence: `browser/pwa_suite_fix_round_1.json`.

**Not rerun, per the owner's instruction:**
- Full DB suites: the changes are limited to client JS and the controller-local session cleanup. No business, security-authority, RouteMap or schema change.
- Route matrix: RouteMap is unchanged.
- The 12 visual captures: no CSS or template change.

**Confirmations:**
- No route-matrix, schema, migration, install or authority change (0 files under `Security/`, migrations, `install/`, `updates.json`, `tests/Unit/Wp3a/`).
- AVAIA: all 24 protected containers unchanged (observation only).
- Lab and test stack torn down.
- `feat` = `8becd6b9`, `wp6c` = `757d8de9`, `main` = `500d3a2d`, all untouched.
- Only `wp7/staff-pwa` pushed. No deployment, no WP8.
