# V1 continuous autonomous execution — checkpoint / handoff

Owner instruction `CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED` (2026-10-17). Resume rule: read this file, then `DECISIONS.md`, then the last rows of `owner-review/WP9/AUTONOMOUS_PROGRESS.md`. Never redo a DONE row without new evidence. Update this file at every milestone.

## Checkpoint

| Field | Value |
|---|---|
| Branch | `wp9/red-team-rc` (local only, nothing pushed) |
| Baseline verified | HEAD `c867949432107d2582b1cd76d989b2c080311884` (clean, no processes, no containers, manifest OK) |
| Protected refs (verified) | `feat/reseller-system-v1` = `wp8/integration-poc` = `704031c6`, `main` = `500d3a2d`, hotfix `6d4f3f96`, wp7 `8e7f0e1c` |
| Last frozen candidate (previous round) | `32cdc92d` (MariaDB 1521/76375, MySQL 1521/76381, 0 failures) |
| Current objective | queue Q1 - Q6 complete; final package written; waiting for the owner decisions and the target evidence |

## Work queue (state)

| # | Task | State | Evidence |
|---|---|---|---|
| Q1 | **N2** exact period ownership | DONE: code + tests both engines + docs + health `nas_clock` (`0ec84def`); real NAS: `V1QuotaLabTest` 9 tests / 220 assertions OK incl. `testRenewalIsExactEvenWhenTheOldSessionStartedSecondsBefore` | N2_PERIOD_OWNERSHIP.md, evidence/quota/n2/{regress,chr} |
| Q2 | **N1** PPPoE overshoot | DONE (engineering): levers `ae99c89e`; CHR matrix `V1OvershootLabTest` 10 tests / 219 assertions OK (9 matrix + early-cross + CoA-fault retry); CoA cannot change interim or byte limits (probe); analysis `N1_PPPOE_OVERSHOOT.md`; DECISIONS N1 row filled. Owner decides the customer-facing semantics (final package) | N1_PPPOE_OVERSHOOT.md, evidence/quota/n1/chr |
| Q3 | Re-verify every WP8 / WP9 / WP10 roadmap item against the actual code (closure matrix) | DONE: `closure_check.py --testdox` 36 items exit 0 (`fe2c4424`) | evidence/release-candidate/closure-matrix-17da628a.md |
| Q4 | Target-environment preparation: acceptance procedures, evidence templates, preflight additions (nothing marked PASS without a real target run) | DONE: docs/V1_TARGET_ACCEPTANCE.md (TA-01..10, all NOT_RUN) | |
| Q5 | Frozen-candidate full verification (both engines, CHR, UI/PWA, drill, load, guards, secrets) | DONE on frozen product candidate `e34906d6`: CHR 10/216 + 121/2323; MariaDB 1534/76558, MySQL 1534/76560, 0 failures; UI 136 loads, PWA 32/32; drill 128; load PASS; manifest 42/42; lint 1386; sweep 0 hits | evidence/release-candidate/ (README.md) |
| Q6 | `SAAS_RADIUS_V1_FINAL_OWNER_RELEASE_DECISION_PACKAGE` (14 sections) | DONE: status `RELEASE_BLOCKED_WITH_EVIDENCE` (TA-01..10 NOT_RUN, owner decisions open) | owner-review/WP10/SAAS_RADIUS_V1_FINAL_OWNER_RELEASE_DECISION_PACKAGE.md |

## Open failures
None known at this checkpoint.

## Next exact action
Nothing is left that can be done inside the authorized scope. Waiting for the owner: answer section 13 of the final package, authorize a staging target (P1 - P5 of `docs/V1_TARGET_ACCEPTANCE.md`) and run TA-01 .. TA-10. If product code changes after the freeze (`e34906d6`), rerun the affected gates and the final suites (full harness in `/srv/phpnuxbill/wp9-snap`, CHR gates, UI / PWA, drill, load, closure check).

## Lab hygiene (learned)
- The lab agent runs ONE operation at a time: a `Wp8Lab::ros()` issued while a traffic job runs is queued and executes AFTER it (a queued `/radius/incoming accept=no` silently broke later CoA). After any aborted CHR test run `/radius/incoming/print` and restore `accept=yes port=3799`.

## Blockers / dependencies
None. Non-delegable decisions are collected for the final package: N1, N2 acceptance, D1, D2, D4 – D10.

## Operating notes (so a resumed session loses nothing)
- Focused stack: `dev/wp9/t1a_run.sh up|down`; FreeRADIUS harness containers re-read `install/freeradius` on `scratchpad/fr_up.sh`; focused tests: `WP9_EVID_DIR=<dir> dev/wp9/regress.sh "mariadb mysql" <paths>`.
- CHR lab and the focused stack share the compose project: one at a time. Lab: `chr_up.sh` (production-contract sequence) / `chr_down.sh`; tests `dev/wp8/lab.sh test ... <path>`.
- Commit with `scratchpad/tools/commit.sh MSGFILE files...` (lint + secret sweep). Trailer from the latest system reminder.
- Never run `a2r_sql_gate.py`, `a2r_gate.py pool|fault`, `run-tests.sh` while the lab is up; no push / merge; AVAIA is out of scope.

## Round 2 (owner `V1_OWNER_DECISIONS_APPROVED` / `AUTONOMOUS_CLOSURE_AUTHORIZED`, 2026-10-18)

Decision record: `V1_OWNER_DECISION_RECORD.md`. Queue and state:

| # | Task | State |
|---|---|---|
| R1 | N1 = A + G: `QuotaUsage` (actual / billable / overshoot), Subscriber 360 card, `quota.php usage`, defect fix "an activation always opens a NEW period" (`32f0bfd8`) | DONE |
| R2 | N2 matrix tests (same-second activation, ordering, reconnect, delayed Start), `docs/TIME_CONTRACT.md` | DONE |
| R3 | D8 voucher masking, D9 sink fixes (version.json, bulk history) + customer RTL / AA contrast, D4 guards (`4f0481d6`, `fb279e03`) | DONE |
| R4 | Capacity: accounting load tool, set-based enforcer (5 - 9 s pass at 5000 subscribers -> < 1 s at 7500), default interim 30 s (`f0a883bf`, `a655d8c5`) | DONE |
| R5 | D2 / D7 / D10 / authorization-request documents; OBS-1 report tool; licence guard | DONE |
| R6 | Final gates on candidate `9da2015e`: CHR, full dual-engine harness, drill, load, manifest / lint / sweep / closure | DONE (evidence/final-v2/) |
| R7 | `SAAS_RADIUS_V1_TARGET_RELEASE_ACCEPTANCE_PACKAGE.md` (15 sections) | DONE: `RELEASE_BLOCKED_WITH_EVIDENCE` |

Nothing runnable is left. Waiting for: answer to `TARGET_AUTHORIZATION_REQUEST.md`, OBS-1 real-data authorization, A2-R adoption (C7), counsel (LIC-1), owner decision O-DL (deadlock-refusal handling), deployment authorization.

Lab hygiene (learned again): never `pkill -f <script name>` (it matches the calling shell: exit 144); stop containers by exact name; the CHR lab and the focused stack
share the compose project - `t1a_run.sh down` before `chr_up.sh`; `t1a.env` is regenerated by `t1a_run.sh up` and removed by `down`.

## Round 3 - final operational closure (owner direction on O-DL, 2026-10-18)

| # | Task | State |
|---|---|---|
| C1 | O-DL study (old vs new, both engines, interleaved, fresh worktrees; variants in scratch worktrees; no code change) | DONE: `O_DL_CLASSIFICATION.md`, `evidence/odl/` |
| C2 | N1 + G verification index and fresh focused run on HEAD | DONE: `N1_G_VERIFICATION.md` |
| C3 | Staging package, consolidated authorization request, TA execution sheets, evaluators, accounting generator (validated in the lab) | DONE |
| C4 | OBS-1 runbook + decision-sheet tool; A2-R owner evidence; legal package finished (not approved) | DONE |
| C5 | Closing summary | DONE: `SAAS_RADIUS_V1_FINAL_OPERATIONAL_CLOSURE.md` |

No product code changed after `9da2015e`. Waiting for the owner: the staging authorization request, O-DL record, A2-R option, OBS-1 authorization, counsel, deployment authorization.

## Round 4 - ITC NetCore product identity (owner decision, 2026-10-18)

Owner decision: `PRODUCT_NAME_APPROVED = ITC NetCore`, `BRAND = ITC`, `TAGLINE = ISP Management & Billing Platform`, `VERSION = V1.0`.

| # | Task | State |
|---|---|---|
| B1 | Single identity source `Reseller\Ui\Brand` (`$_brand` in every template); operator vs product identities; fallback for an unconfigured / stock CompanyName | DONE |
| B2 | Staff shell, sign-in, footer, phone More sheet, About, dashboard notice, customer portal, error pages, installer, device texts, settings help, PWA manifest + interim "ITC" icons (`wp7.2`) | DONE |
| B3 | Arabic entries + `<bdi>` isolation; README, NOTICE, docs/BRAND_IDENTITY.md, upstream README kept verbatim; legal package facts + Q12 - Q14 | DONE |
| B4 | Tests: BrandIdentityTest (first run failed, kept), unit 150, PWA 32/32, brand visual both phases CLEAN (first runs: findings, kept), full dual-engine harness | see `owner-review/BRANDING/ITC_NETCORE_BRANDING_REPORT.md` |

The product candidate moved from `9da2015e` to the branding commit (presentation only; no finance / RADIUS / activation / schema file changed). TA-01 .. TA-10 must use the new SHA.
Release state unchanged: `RELEASE_BLOCKED_WITH_EVIDENCE`. Open: owner B-1 .. B-8 (logo, colour, upstream boxes, banner, file names, default artwork, GitHub text, CompanyName) and counsel Q1 (new fact), Q12 - Q14.
- Round 4b (2026-10-18): product-guide fixes (decimal quota units, 486 Arabic entries + `ArabicCatalogueTest`, translated titles / labels / notices). The candidate moved again, to the round-4b commit. The guide is published as a private Artifact (outside the repository). Its demo data was made in the isolated lab through the application's own screens (`scratchpad/manual/demo_prep.sh`, `demo_flows.py`).
