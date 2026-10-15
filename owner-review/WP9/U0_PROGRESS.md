# WP9 U0 — progress ledger and handoff (owner: `WP9_U0_FULL_IMPLEMENTATION_AND_TESTING_AUTHORIZED`, D-U0-1…8 approved)

Resume rule: read this file, `U0_DESIGN.md` and `REPORT.md` §U0; `git log --oneline wp9/red-team-rc`; continue at the first phase not marked DONE. Runner: `dev/wp9/t1a_run.sh up | test <mariadb|mysql> <file> | down` (env `WP9_EVID_DIR`, `WP9_ISOLATION=READ-COMMITTED`, `WP9_FILTER=<regex>`). Never `run-tests.sh` while the CHR lab is up; no SQL exhaustion / pool / connection-fault work; no push / merge / deploy.

| Phase | State | Local commit | Evidence | Notes |
|---|---|---|---|---|
| Baseline | DONE | `85a5d4ef` (docs: U0 design + V1 interim record) on top of `0f181323` (A1 fix) | — | product fingerprint `6ff47e19…` |
| U0-0 pre-fix characterisation | DONE | see git log (`test(wp9-u0): …U0-0`) | `evidence/u0/pre/` | race matrix 22/22 races reproduced on MariaDB + MySQL, RR and RC (23 tests incl. isolation proof); identity matrix 86 tests, 47 defects per engine, 18 cases already refused by existing guards / A1, 1 control fail = known P4 case-only false refusal. Kept defective runs: `run1-judge-one-side` (G2/G3 judged one side only), `rr-run2-noisolationproof`, `rc-run1-noisolationproof`, `matrix-run1-login-noise` (staff login inside the snapshot window) |
| U0-1 shared lock primitive | DONE | see git log (`wp9(u0-1): …`) | `evidence/u0/u0-1/` | `CustomerIdentity` (hold / held / assertAvailable / conflicts); lock tests 5/5 both engines (transaction boundary, mutual exclusion, nested, exception + rollback, exit / fatal release). Timeout / inverted order / parallel / bounds run after wiring (U0-5) |
| U0-2 P6 voucher-only redemption + P7 generation | TODO | | | |
| U0-3 P1 / P2 / P3 | TODO | | | |
| U0-4 P4 / P5 / P5r | TODO | | | |
| U0-5 closure matrix, bounds, perf, regression, harness | TODO | | | |
| Related WP9 work (V4, reverse direction) | TODO | | | |
| Final audit / report | TODO | | | |
