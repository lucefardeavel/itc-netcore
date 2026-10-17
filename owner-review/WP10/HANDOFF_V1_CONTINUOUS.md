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
| Q1 | **N2** exact period ownership (server-side arrival order, `radacctid`) instead of NAS-clock time; clock-skew / NTP-drift / delayed Start / late Interim / cross-period / reconnect / duplicate matrix; NTP + max drift in preflight and health | IN PROGRESS: implemented (PeriodBoundary, builder, 3 callers, counters, share SQL, enforcer, dictionary), V1PeriodOwnershipTest 7/113 green both engines (failing-first in evidence/quota/n2/prefix). TODO: fix V1QuotaEnforcerTest full-chain setup (insert the old session BEFORE the activation), finish MySQL regression (evidence/quota/n2/regress), health `nas_clock`, CHR verification, docs (README, checklist H8, WP4 model, plan) | owner-review/WP10/N2_PERIOD_OWNERSHIP.md |
| Q2 | **N1** PPPoE overshoot: measure on the CHR (interim, rate, quota, fault), model, reduce safely (interim configuration, adaptive interim if RouterOS supports it, pass interval), state the exact guarantee; options + recommendation for the owner | TODO | |
| Q3 | Re-verify every WP8 / WP9 / WP10 roadmap item against the actual code (closure matrix) | TODO | |
| Q4 | Target-environment preparation: acceptance procedures, evidence templates, preflight additions (nothing marked PASS without a real target run) | TODO | |
| Q5 | Frozen-candidate full verification (both engines, CHR, UI/PWA, drill, load, guards, secrets) | TODO | |
| Q6 | `SAAS_RADIUS_V1_FINAL_OWNER_RELEASE_DECISION_PACKAGE` (14 sections) | TODO | |

## Open failures
None known at this checkpoint.

## Next exact action
Wait for the N2 regression (`scratchpad/regress_n2.log`, evidence/quota/n2/regress) to finish; do NOT edit system/ or tests/ while it runs. Then fix the V1QuotaEnforcerTest setup, rerun Wp10 on both engines, then Q1 remaining items.

## Blockers / dependencies
None. Non-delegable decisions are collected for the final package: N1, N2 acceptance, D1, D2, D4 – D10.

## Operating notes (so a resumed session loses nothing)
- Focused stack: `dev/wp9/t1a_run.sh up|down`; FreeRADIUS harness containers re-read `install/freeradius` on `scratchpad/fr_up.sh`; focused tests: `WP9_EVID_DIR=<dir> dev/wp9/regress.sh "mariadb mysql" <paths>`.
- CHR lab and the focused stack share the compose project: one at a time. Lab: `chr_up.sh` (production-contract sequence) / `chr_down.sh`; tests `dev/wp8/lab.sh test ... <path>`.
- Commit with `scratchpad/tools/commit.sh MSGFILE files...` (lint + secret sweep). Trailer from the latest system reminder.
- Never run `a2r_sql_gate.py`, `a2r_gate.py pool|fault`, `run-tests.sh` while the lab is up; no push / merge; AVAIA is out of scope.
