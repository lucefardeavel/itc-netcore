# V1 continuous autonomous execution — checkpoint / handoff

Owner instruction `CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED` (2026-10-17). Resume rule: read this file, then `DECISIONS.md`, then the last rows of `owner-review/WP9/AUTONOMOUS_PROGRESS.md`. Never redo a DONE row without new evidence. Update this file at every milestone.

## Checkpoint

| Field | Value |
|---|---|
| Branch | `wp9/red-team-rc` (local only, nothing pushed) |
| Baseline verified | HEAD `c867949432107d2582b1cd76d989b2c080311884` (clean, no processes, no containers, manifest OK) |
| Protected refs (verified) | `feat/reseller-system-v1` = `wp8/integration-poc` = `704031c6`, `main` = `500d3a2d`, hotfix `6d4f3f96`, wp7 `8e7f0e1c` |
| Last frozen candidate (previous round) | `32cdc92d` (MariaDB 1521/76375, MySQL 1521/76381, 0 failures) |
| Current objective | see "Work queue" |

## Work queue (state)

| # | Task | State | Evidence |
|---|---|---|---|
| Q1 | **N2** exact period ownership | DONE: code + tests both engines + docs + health `nas_clock` (`0ec84def`); real NAS: `V1QuotaLabTest` 9 tests / 220 assertions OK incl. `testRenewalIsExactEvenWhenTheOldSessionStartedSecondsBefore` | N2_PERIOD_OWNERSHIP.md, evidence/quota/n2/{regress,chr} |
| Q2 | **N1** PPPoE overshoot | DONE (engineering): levers `ae99c89e`; CHR matrix `V1OvershootLabTest` 10 tests / 219 assertions OK (9 matrix + early-cross + CoA-fault retry); CoA cannot change interim or byte limits (probe); analysis `N1_PPPOE_OVERSHOOT.md`; DECISIONS N1 row filled. Owner decides the customer-facing semantics (final package) | N1_PPPOE_OVERSHOOT.md, evidence/quota/n1/chr |
| Q3 | Re-verify every WP8 / WP9 / WP10 roadmap item against the actual code (closure matrix) | TODO | |
| Q4 | Target-environment preparation: acceptance procedures, evidence templates, preflight additions (nothing marked PASS without a real target run) | DONE: docs/V1_TARGET_ACCEPTANCE.md (TA-01..10, all NOT_RUN) | |
| Q5 | Frozen-candidate full verification (both engines, CHR, UI/PWA, drill, load, guards, secrets) | TODO | |
| Q6 | `SAAS_RADIUS_V1_FINAL_OWNER_RELEASE_DECISION_PACKAGE` (14 sections) | TODO | |

## Open failures
None known at this checkpoint.

## Next exact action
Q5: with the CHR lab still up run the CHR gates + wp8-integration + V1 lab suites (`scratchpad/chr_gates_final.sh`, edit `EV=`), then `chr_down.sh`; then the frozen-candidate full dual-engine harness with `--testdox` in the snapshot worktree; UI audit + PWA suite; drill; load; guards. Then Q3 (`closure_check.py --testdox`) and Q6.

## Lab hygiene (learned)
- The lab agent runs ONE operation at a time: a `Wp8Lab::ros()` issued while a traffic job runs is queued and executes AFTER it (a queued `/radius/incoming accept=no` silently broke later CoA). After any aborted CHR test run `/radius/incoming/print` and restore `accept=yes port=3799`.

## Blockers / dependencies
None. Non-delegable decisions are collected for the final package: N1, N2 acceptance, D1, D2, D4 – D10.

## Operating notes (so a resumed session loses nothing)
- Focused stack: `dev/wp9/t1a_run.sh up|down`; FreeRADIUS harness containers re-read `install/freeradius` on `scratchpad/fr_up.sh`; focused tests: `WP9_EVID_DIR=<dir> dev/wp9/regress.sh "mariadb mysql" <paths>`.
- CHR lab and the focused stack share the compose project: one at a time. Lab: `chr_up.sh` (production-contract sequence) / `chr_down.sh`; tests `dev/wp8/lab.sh test ... <path>`.
- Commit with `scratchpad/tools/commit.sh MSGFILE files...` (lint + secret sweep). Trailer from the latest system reminder.
- Never run `a2r_sql_gate.py`, `a2r_gate.py pool|fault`, `run-tests.sh` while the lab is up; no push / merge; AVAIA is out of scope.
