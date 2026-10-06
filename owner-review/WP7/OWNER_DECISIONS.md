# WP7 Owner Review Decision and Owner Decisions

Recorded 2026-10-06 from the owner's explicit decisions. The verdict was "WP7 OWNER ACCEPTANCE": status **ACCEPTED_WITH_NOTES**. The accepted, frozen WP7 implementation / evidence state is `wp7/staff-pwa` = `8e7f0e1c024e6c319b5088896e001555d6c4f079`.

The owner instructed:
- documentation / integration closeout only;
- the acceptance commit has `8e7f0e1c` as its direct parent and changes only acceptance / owner-review documentation;
- push only `feat/reseller-system-v1`;
- keep `wp7/staff-pwa` permanently frozen at `8e7f0e1c`;
- do not move `wp6c/mobile-adaptive-ux` or `main`;
- no deployment, no production tag, no full-suite rerun after this docs-only commit;
- do not fix R-WP7-CARRY-01 here, and do not begin WP8.

Technical detail is in `docs/WP7_PWA_MODEL.md`, `owner-review/WP7/REPORT.md` (candidate), `owner-review/WP7/FIX_ROUND_1.md` (fix round 1) and `owner-review/WP7/PWA_BROWSER_QA.md`.

## WP7 acceptance

| Item | Value |
|---|---|
| Owner status | **ACCEPTED_WITH_NOTES** |
| Accepted implementation / evidence state | `8e7f0e1c024e6c319b5088896e001555d6c4f079`. Branch `wp7/staff-pwa` stays frozen there. |
| Runtime commits | Candidate: slices `a120b426` … `bd92412e`, docs `10408df921f0ad8c95eb8d9da0db9d5e166133cd` (the validated candidate SHA). Fix round 1: `c48bb461ec3c9d904d675fecacfea490cd365d40`, `bce221b9eade4318ffe4ccb8be296b96a21c2097`. |
| Test / evidence commits | `a98fba5c` (lab visual tool), `95bf8bee` (candidate evidence), `8b374fb6` (fix-round-1 tests), `8e7f0e1c` (fix-round-1 evidence) |
| Plan | `245e46bfb7a82e3a0877f387f67f563d16a6de98` (`owner-review/WP7/PLAN.md`, owner-locked) |
| Base | `8becd6b9f5ec2eb81099baa5f5247e9ddf98267c` (WP6C acceptance recorded) |
| `feat/reseller-system-v1` | fast-forwarded to `8e7f0e1c`, plus this docs-only acceptance metadata commit. No squash, rebase, cherry-pick or rewrite. |
| `wp6c/mobile-adaptive-ux` | `757d8de969e98787a270facb956a4c543fec7eaa`, frozen, unchanged |
| `main` | `500d3a2d15003efeed6e0aad639c13d08101c3b1`, unchanged |
| Contracts | No schema, migration or `install/*` change. No new capability. DataScope, Finance writers, Activation writers and API authority unchanged; `pwa` is not in `API_ALLOWED`. The only RouteMap change is the owner-authorized `pwa` entry (re-pinned in `SourceGuardTest`). |

## Owner decisions applied in WP7

From "WP7 OWNER PLAN REVIEW — PLAN ACCEPTED WITH DECISIONS" and "WP7 OWNER IMPLEMENTATION GO"; the full binding text is in `owner-review/WP7/PLAN.md`.

| Decision | Content |
|---|---|
| OD-W7-1 = B | Interim deterministic icons: first initial on `#3442c9`, rounded square, maskable-safe; 180 / 192 / 512 / maskable 512. No AI, no upscaling, explicitly interim. Recipe and hashes are recorded in `REPORT.md` §3. |
| OD-W7-2 | Public `pwa` controller with ONLY `pwa/manifest` and `pwa/ping`: GET, public, no capability, not in `API_ALLOWED`; any other `pwa/*` is a 404. RouteMap re-pin and the narrow `nuxr-csrf.tpl` gate hook authorized. No `pwa/start` (`start_url` = `./?_route=admin`); no PHP offline route (static `nuxr-offline.html`). |
| OD-W7-3 = A | Logout and session-expiry routing unchanged. |
| OD-W7-4 = A | `dev/pwa-lab/` Python Playwright harness, test-only. |
| Amendments A–O | Staff PWA only; zero business cache, precache only (offline document + icons); static worker at its natural scope, root and subdirectory mandatory; network-only navigations, failed GET → offline document, failed non-GET → `reason=unconfirmed` ("may or may not have reached the server"); dynamic subresources not intercepted; cookie-less no-store heartbeat with the stated cadence, paused while hidden; no `minClient`; mutation gate for OFFLINE / RECONNECTING only, on every path; user-initiated install, no user-agent sniffing; user-chosen update with critical-flow deferral, `SKIP_WAITING` → `controllerchange` → one reload, other tabs never forced; no client router; notification-ready only; secure-context lab; branding replaceability. |
| Refinement (as built, accepted) | Manifest `id` = `<install path>/?_route=admin` instead of the plan's literal `"nuxr-staff"`, so two installations on one origin stay distinct apps (still per installation, never per user). The optional `notificationclick` handler was not shipped. |

## Owner code-review fix round

| Round | Reviewed | Findings | Fix | Outcome |
|---|---|---|---|---|
| Fix round 1 ("WP7 OWNER REVIEW — FIX ROUND 1") | `95bf8bee` | FR1-1 heartbeat could carry the staff CSRF token through the shim-wrapped fetch; FR1-2 locked-plan same-origin network-error hint missing; FR1-3 T18 readiness measurement; FR1-4 customer / public parity under the installed root worker unproven; FR1-5 anonymous manifest sessions; FR1-6 pre-existing Pace / CSRF issue to be documented, not fixed | runtime `c48bb461`, `bce221b9` (no `Referer`, found by the new header test); tests `8b374fb6`; evidence `8e7f0e1c` | **Accepted** in this verdict |

**Closed by the owner:**
- FR1-1: the heartbeat uses a browser fetch captured before the CSRF shim; it sends no Cookie, no Authorization, no X-CSRF-Token and no Referer; ordinary application fetch still receives the CSRF header.
- FR1-2: same-origin network failures (fetch rejection, XHR error) trigger an immediate heartbeat hint without changing state directly; no business request is retried, queued or replayed.
- FR1-3: T17 / T18 readiness hardened (bounded wait for the 64-hex token where the server HTML renders the footer CSRF include) without weakening parity.
- FR1-4: root worker + customer / public online parity proven (T10c).
- FR1-5: cookie-less manifest and ping requests leave no anonymous session file and no Set-Cookie; cookie-identified staff sessions are preserved.

## Accepted validation

**Original candidate complete validation (on `10408df9`):**
- MariaDB full suite: 927 tests / 66,382 assertions PASS; MySQL full suite: 927 tests / 66,381 assertions PASS; zero PHPUnit warnings.
- Variant registry reproducible on both engines.
- Route matrix 1727 / 1354 / 373, zero-row delta, identical between engines.
- PHP 8.2 lint 499 / 499; pack manifest 42 / 42.
- Migration / schema / install boundary unchanged; secret sweep clean.

**Fix round 1 focused validation:**
- `wp7-unit` + `wp7-integration` green on MariaDB and MySQL (26 tests / 330 assertions each).
- `CsrfTest`, `AuthSessionTest`, `SecurityRoutesTest` green on both engines (27 tests each).
- WP6 / WP7 source guards and RouteMap / SourceGuard green.
- Complete replacement WP7 browser suite: **28 / 28 PASS** at the root and `/billing/` secure-context installations, including T17, T18, T12h (heartbeat identity), T12n (network-error hint) and T10c (root-worker customer / public parity). Evidence: `browser/pwa_suite_fix_round_1.json`; the earlier T18 anomaly files are kept as prior evidence.

No additional full suite was required for this docs-only acceptance integration.

## Accepted residual / carry-forward

**R-WP7-CARRY-01 — Legacy Pace / XHR CSRF header compatibility.**
**Status: OPEN — PRE-EXISTING — NOT A WP7 REGRESSION.**
- Known example: a legacy jQuery XHR POST such as `autoload/server` on `plan/recharge` can be refused (403), because Pace replaces `window.XMLHttpRequest` before the WP2 CSRF prototype patch can reach the created native instance.
- Not fixed in WP7 or in this acceptance commit.
- **Next action (owner direction):** a dedicated, separately reviewed compatibility / security hotfix **before WP8 begins**.

**Other accepted WP7 residual notes:**
- HTTPS is required for production PWA functionality.
- iOS installation is manual (Share → Add to Home Screen).
- Logout / session-expiry routing unchanged per OD-W7-3.
- The offline document is static and bilingual (English + Arabic).
- `short_name` is a simple 12-character prefix of `CompanyName`.
- The icons are interim (OD-W7-1).
- Standalone / device / safe-area limitations of headless evidence (no `display-mode` emulation; safe areas checked at CSS level).
- Legacy lab missing-avatar 404 (`user.default.jpg`, untracked upstream).

## Not done (by owner instruction)

No deployment, no production tag, no WP8 work, no Pace / CSRF hotfix, and no test-suite rerun after this docs-only commit.
