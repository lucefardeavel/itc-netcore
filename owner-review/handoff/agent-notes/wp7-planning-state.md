---
name: wp7-planning-state
description: "WP7 ACCEPTED_WITH_NOTES 2026-10-06; wp7/staff-pwa frozen 8e7f0e1c; origin feat = 7e3a557c (acceptance recorded, canonical baseline); main 500d3a2d untouched; next = pre-WP8 Pace/XHR CSRF hotfix (R-WP7-CARRY-01)"
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-05T19:01:28.847Z
---

**Accepted 2026-10-06: ACCEPTED_WITH_NOTES.**
- `wp7/staff-pwa` is frozen at `8e7f0e1c`.
- `feat` was fast-forwarded to `8e7f0e1c`, then the docs-only acceptance commit `7e3a557c` (parent `8e7f0e1c`) was pushed: ledger row (`git add -f`), `owner-review/WP7/OWNER_DECISIONS.md`, and a REPORT provenance note.
- `wp6c` stays `757d8de9` and `main` stays `500d3a2d`.

**Next (owner direction).** A separately reviewed hotfix for R-WP7-CARRY-01 (legacy Pace/XHR CSRF header; OPEN, pre-existing) must be done BEFORE WP8. Do not start it without the owner's GO.

**Fix round 1 (2026-10-06).** Pushed: origin = `8e7f0e1c` (evidence commit).
- Runtime: `c48bb461` and `bce221b9`. Tests: `8b374fb6`.
- FR1-1: the heartbeat uses a `heartbeatFetch` captured before the CSRF shim, plus `no-referrer`.
- FR1-2: a network-error hint calls `probeNow` (1 s throttle).
- FR1-3: `page_ready` waits for the CSRF token in T17/T18.
- FR1-4: new T10c customer parity.
- FR1-5: a manifest/ping anonymous-session closure.
- FR1-6: R-WP7-CARRY-01 (Pace/XHR CSRF) is documented, not fixed.
- New lab checks: T12h, T12n, T10c. The complete browser suite is 28/28.
- Full DB suites and the route matrix were not rerun, per the owner's instruction.
- Waiting for owner review; end marker `WP7_FIX_ROUND_1_READY_WAITING_OWNER_REVIEW`.

**Candidate (2026-10-05).** `wp7/staff-pwa` is pushed: origin = `95bf8bee` (evidence commit).
- Plan: `245e46bf`, parent `8becd6b9`.
- Slices 0–7: `a120b426` … `bd92412e`, then docs `10408df9`. `10408df9` is the validated candidate SHA.
- Lab visual tool: `a98fba5c`.
- Evidence: `owner-review/WP7/{REPORT.md, PWA_BROWSER_QA.md, TESTDOX.txt, browser/, visual/}`.
- Status: waiting for the owner's code review. Do not merge into feat, do not start WP8, never self-accept.

**One candidate run (results).**
- 927 tests per engine, both OK; variant registry OK.
- Route matrix 1727/1354/373, zero delta.
- Lint 499/499; manifest 42/42.
- Browser suite T00–T20 24/25. The T18 anomaly (`nuxrCsrfToken` undefined in the no-SW context; server clean; isolated rerun PASS) was reported, not hidden.
- 12 visual captures (V01–V12) clean except the lab-only `user.default.jpg` 404.
- AVAIA unchanged.

**Owner-decision items raised.** A pre-existing WP2 defect: Pace loads before the CSRF shim, so legacy jQuery XHR POSTs lack `X-CSRF-Token` and get 403 (e.g. `autoload/server` on `plan/recharge`). Not fixed.

**Decisions as built.**
- Manifest `id` = `<path>/?_route=admin` (a refinement of "nuxr-staff").
- Optional `notificationclick` not shipped.
- Chromium CDP cannot emulate `display-mode`, so the standalone rule is verified in the CSSOM.

**Lab usage.**
- Commands: `dev/pwa-lab/lab.sh up|sync|down` (needs the devloop `.devpw` env); `pwa_suite.py --axe <axe.min.js>`; `pwa_visual.py`.
- axe 4.10.2 lives at `scratchpad/wp6a/axe.min.js`.
- Update tests rewrite VERSION in the lab copy via `docker exec` into the lab container only.
- Run the candidate validation only after `lab.sh down` and `devloop down`, because `run-tests.sh` owns the same compose project.

Related: [[wp6c-planning-state]], [[phpnuxbill-dev-env]], [[shared-host-avaia-boundary]].
