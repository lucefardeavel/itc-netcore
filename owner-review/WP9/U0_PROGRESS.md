# WP9 U0 — progress ledger and handoff (owner: `WP9_U0_FULL_IMPLEMENTATION_AND_TESTING_AUTHORIZED`, D-U0-1…8 approved)

Resume rule: read this file, `U0_DESIGN.md` and `REPORT.md` §U0; `git log --oneline wp9/red-team-rc`; continue at the first phase not marked DONE. Runner: `dev/wp9/t1a_run.sh up | test <mariadb|mysql> <file> | down` (env `WP9_EVID_DIR`, `WP9_ISOLATION=READ-COMMITTED`, `WP9_FILTER=<regex>`). Never `run-tests.sh` while the CHR lab is up; no SQL exhaustion / pool / connection-fault work; no push / merge / deploy.

| Phase | State | Local commit | Evidence | Notes |
|---|---|---|---|---|
| Baseline | DONE | `85a5d4ef` (docs: U0 design + V1 interim record) on top of `0f181323` (A1 fix) | — | product fingerprint `6ff47e19…` |
| U0-0 pre-fix characterisation | DONE | see git log (`test(wp9-u0): …U0-0`) | `evidence/u0/pre/` | race matrix 22/22 races reproduced on MariaDB + MySQL, RR and RC (23 tests incl. isolation proof); identity matrix 86 tests, 47 defects per engine, 18 cases already refused by existing guards / A1, 1 control fail = known P4 case-only false refusal. Kept defective runs: `run1-judge-one-side` (G2/G3 judged one side only), `rr-run2-noisolationproof`, `rc-run1-noisolationproof`, `matrix-run1-login-noise` (staff login inside the snapshot window) |
| U0-1 shared lock primitive | DONE | see git log (`wp9(u0-1): …`) | `evidence/u0/u0-1/` | `CustomerIdentity` (hold / held / assertAvailable / conflicts); lock tests 5/5 both engines (transaction boundary, mutual exclusion, nested, exception + rollback, exit / fatal release). Timeout / inverted order / parallel / bounds run after wiring (U0-5) |
| U0-2 P6 voucher-only redemption + P7 generation | DONE | see git log (`wp9(u0-2): …`) | `evidence/u0/u0-2/` | P6: candidate check, `assertAvailable` and claim inside L-3 (claim = durable reservation), grant outside; a voucher whose own row is CLAIMED stays `NOT_VALID` (WP5B contract; run 1 returned `REFUSED` and failed `VoucherRedemptionTest`, kept in `run1-own-claim-refused`). P7 uses `CustomerIdentity::conflicts` (no lock). A1 replay fixture now renames all three policy tables (radcheck-only rename left radreply / radusergroup rows that correctly keep the key unavailable under D-U0-2). Both engines: A1 16/78, T1aVoucherV1 1/3, WP5B VoucherRedemption 10/66, race C1/C2/G1/G2 6/26, matrix P6 16/42 — all exit 0. The A1 raw-SQL race characterisation still records `RACE_REMAINS` (plugin / direct-DB writer, not coverable — documented residual) |
| U0-3 P1 / P2 / P3 | DONE | see git log (`wp9(u0-3): …`) | `evidence/u0/u0-3/` | P1 `TreeSerialization::hold` → `CustomerIdentity::hold` → `Tx::run` (cross-field canonical check in the inserting transaction; I-5 PPPoE ≤ 32, no control characters); P2 check + save under L-3, refusal through the same generic form message as an existing account; P3 L-2 → L-3 (check, claim, insert), grant outside. DomainError `IDENTITY_TAKEN` / `IDENTITY_CHECK_UNAVAILABLE`. Both engines: race A1–A3, B1–B3, D1–D3 + isolation 10/37 (P6 waited on L-3 in every B case, then REFUSED); matrix P1/P2/P3 + generic message 44/117; CustomerOwnership 9/75, Audit 2/391, Csrf 9/75, WP5B VoucherRedemption 10/66, WP6A CreateFlow 3/53, Hierarchy 7/72 — all exit 0 |
| U0-4 P4 / P5 / P5r | DONE | see git log (`wp9(u0-4): …`) | `evidence/u0/u0-4/` | P4: early checks exclude the customer itself (I-4, fixes the case-only false refusal); I-5 length / control-character rule; only `$c->save()` of an acquiring edit runs under L-3 (own RADIUS keys excluded), device steps after. P5: acquired names checked and written under L-3 inside the tree lock (L-1 → L-3 → BEGIN), I-5 in `validate()`; P5r: the revert re-checks the old names under L-3 and never writes onto a name taken meanwhile (REPROVISION_UNCONFIRMED). Helpers `CustomerIdentity::acquired()` / `keysOf()`. Both engines: race A4/A5/B4/B5/D4/D5/G1–G3 9/44; matrix P4/P5 + case-only + I-2 + grandfathered + P5r 30/97; ServiceGate 10/264, RadiusLegacyRename 5/48, LegacyEntrypointClosure 6/62, LegacyReprovision 14/260, RenameIntegrity 4/67, RenameOldSession 20/325, RenameRemediation 15/242, LegacyAdaptive 5/266, DeviceGuardKeyCollision 4/67, RechargePreWriteRefusal 3/28 — all exit 0 |
| U0-5 closure matrix, bounds, perf | DONE (harness: next row) | see git log (`wp9(u0-5): …`) | `evidence/u0/u0-5/`, `evidence/u0/related/` | Both engines exit 0: U0RaceTest 23/96 RR and 23/96 RC (22 races closed); U0IdentityTest 86/244; U0LockTest 9/46 (timeouts: RADIUS ≈3 s / web ≈10 s, no write, single retry = one grant; inverted order resolved: MySQL deadlock error / MariaDB timeout; parallel mixed acquirers; statement bound interrupts; 1001 edge-whitespace rows → fail closed); U0PerfTest observation; A1 16/78; T1aVoucherV1 1/3; T1aLegacyPaths 6/35; T1aIdentity 7/66 (writer defects gone; 5 remaining DEFECT lines are engine / data facts: collation leading space, SQL TRIM vs PHP trim, old survey misses TAB → corrected survey `dev/wp9/identity_survey.sql` + U0SurveyTest 1/6); Unit/Wp9 28/280 incl. U0SourceGuardTest; U0V4Test 7/37; U0ReverseDirectionTest 2/29 (creation / rename of a collider refused; GRANDFATHERED collider activation overwrites the other key = KNOWN_RESIDUAL_REPRODUCED → decision). Kept: `perf-run1-timer-clobbered` (test snippet used `$t`, a Package::rechargeUser global) |
| EOL incident | FIXED | `d64cb359` | `evidence/u0/harness/run1-aborted-lf-endings/` | final diff audit found that the U0-3 / U0-4 edit tool had normalised `customers.php` (1093 CRLF lines) and `register.php` (286) to LF; restored by a separate commit (history not rewritten); the first harness run (started on the LF bytes) was stopped by its process-group id after ≈3 min and its partial log kept; the harness then ran on the final bytes |
| Full harness | DONE | final docs commit | `evidence/u0/harness/` | `run-tests.sh` all suites at `d64cb359`: both engines 1 316 tests / 70 589 assertions, 0 failures / errors / warnings, 104 incomplete = WP8 real-NAS lab tests (itemised), variant registry reproducible, exit 0; fingerprint `d3136afb…` unchanged before / after; lint 534 files OK |
| Related WP9 work (V4, reverse direction, survey) | DONE | (in the U0-5 commit) | `evidence/u0/related/` | see U0-5 row |
| Final audit / report | DONE | final docs commit | `REPORT.md` §U0 | result `WP9_U0_END_TO_END_FINAL_ACCEPTANCE_CANDIDATE`; integrity §U0.11; blockers §U0.12 |

## Final hardening round (2026-10-16, `WP9_U0_IMPLEMENTED — FINAL_HARDENING_AUTHORIZED — PRODUCTION_BLOCKED`)

| Unit | State | Commit | Evidence | Notes |
|---|---|---|---|---|
| Baseline audit | DONE | — | — | `aebba8bf` verified; claims re-read against code |
| fh-1 survey | DONE | `081a23e5` | `evidence/fh/survey*` | 1/12 both engines; run 1 = test hex case defect (kept) |
| fh-2 multi-owner write refusal | DONE | `0067bb22` | `evidence/fh/guard*`, `deadlock-*`, `parallel-diag`, `focused-regression-run1-stopped-deadlock-found` | guard 11/45, reverse 4/60 both engines; WP8 precedence fix; WP8 A3 rename test expectation changed (D-FH-1); 1213 deadlock found (5/20 runs per engine) and fixed at the legacy device (0/20) |
| fh-3 O-U0-1 + N-07 | DONE | `9e4d8156` | `evidence/fh/o-u0-1*`, `n07*` | phantom GRANTED reproduced (control char, bypass, deadlock) → CLAIMED_REVIEW; log/audit no code / hash |
| R2 / R3 reviews | DONE (read-only) | final docs commit | `U0_HARDENING_REVIEWS.md` | both OPEN |
| Full validation | DONE | final docs commit | `evidence/fh/final/` | lint 537 OK; RC race 23/96 both engines; full harness 1334 / 70703 per engine, 0 failures, 104 lab-incomplete, registry OK, exit 0; fingerprint `f10011ec…` unchanged |
| Final report | DONE | final docs commit | `REPORT.md` §FH | `WP9_U0_FINAL_HARDENING_READY_FOR_OWNER_REVIEW` |

## Final closure round (2026-10-16, `WP9_U0_HARDENING_CONDITIONALLY_ACCEPTED — TARGETED_FINAL_CLOSURE_AUTHORIZED`)

| Unit | State | Commit | Evidence | Notes |
|---|---|---|---|---|
| Baseline audit | DONE | — | — | `94bfd7b9` verified (clean, refs unchanged); claims re-read against code |
| fc-1 D-FH-1 | DONE | `fa0b4483` | `evidence/fc/post` | approved; WP8 test proves no policy write / disconnect / session change; 20/329 both engines |
| fc-2 open-transaction guard | DONE | `64f617be` | `evidence/fc/pre-run2`, `pre-rc`, `post`, `post-rc` | S1 (RR stale view) and S2 (uncommitted owner, RR + RC) reproduced → refused; 4/24 both engines × both isolations |
| fc-3 grant confirmation | DONE | `fc6190a6` | `evidence/fc/pre`, `pre-rc`, `post`, `post-rc` | C1–C3 false GRANTED, C4 unconfirmed GRANTED reproduced → CLAIMED_REVIEW with reason; 7/41 both engines × both isolations |
| fc-4 N-07 | DONE | `21858d4c` | `evidence/fc/pre-run2`, `pre-login`, `post`, `post-login` | Package.php limited exception + re-pin (`d4567f66…` → `9759fca7…`); login.php gate record; 1/11 + 2/10 both engines |
| Full validation | DONE | docs commit | `evidence/fc/final`, `full-run1-stopped-scope-extended`, `clionly-classification` | first run stopped (scope extended to login.php); run 1: MySQL clean, MariaDB 1 known `CliOnlyTest` counter flake (10/10 isolated passes); **run 2 (final, `21858d4c`): 1351 tests per engine, 0 failures / errors / warnings, 104 lab-incomplete, registry OK, exit 0**; fingerprint `4e62cacd…` unchanged; lint 581 OK; manifest 42/42; pins 23/23 (Package re-pinned only) |
| Final report | DONE | docs commit | `REPORT.md` §FC | `WP9_U0_FINAL_OWNER_ACCEPTANCE_PACKAGE` — `PRODUCTION_NOT_AUTHORIZED` |

## R-FC-4 round (2026-10-16, `WP9_U0_INDEPENDENT_ACCEPTANCE_ON_HOLD — R_FC_4_VERIFICATION_AUTHORIZED`)

| Unit | State | Commit | Evidence | Notes |
|---|---|---|---|---|
| Investigation | DONE | — | REPORT §RFC4.1 | 4 serving entry points without a voucher state check (E12, device guard, E16, E15) |
| Test-first | DONE | — | `evidence/rfc4/pre*` | defect confirmed both engines (reviewed / CLAIMED / unused+orphan served; RADIUS policy written); kept: name clash, short REST secret |
| Fix | DONE | `9b96b4e3` | `evidence/rfc4/post*`, `race-rc` | `voucherOnlyServiceRefusal()` at the shared device guard, login E12, radius.php E15 / E16; 4/22 both engines; static guard 11/300 |
| Full harness | DONE | docs commit | `evidence/rfc4/final` | 1356 / 70846 per engine, 0 failures, 104 lab-incomplete, registry OK, exit 0; fingerprint `6bd6403b…` unchanged |
| Review pack v2 | DONE | — | `/root/Downloads/WP9_U0_INDEPENDENT_OWNER_REVIEW_PACK_v2` (outside the repository) | v1 kept unchanged |

## R-FC-4E round (2026-10-16, `WP9_U0_CORRECTION_REQUIRED — R_FC_4_EQUIVALENCE_VERIFICATION_AUTHORIZED`)

| Unit | State | Commit | Evidence | Notes |
|---|---|---|---|---|
| Matching-semantics audit | DONE | — | REPORT §RFC4E.1 | E12 / device / rlm_sql resolve by collation; the R-FC-4 guard by bytes |
| Test-first | DONE | — | `evidence/rfc4e/pre`, `pre-run1-run-name-clash` | defect confirmed both engines (variants served with RADIUS writes; E15 authorized next to an equivalent CLAIMED; empty identity allowed; no bound) |
| Fix | DONE | `ec9d695f` | `evidence/rfc4e/post`, `regression`, `regression-rc` | canonical `voucherIdentity()` + rebuilt decision; 11/50 + R-FC-4 4/22 both engines; static guard |
| Full harness | DONE | docs commit | `evidence/rfc4e/final` | 1368 per engine, 0 failures, 104 lab-incomplete, registry OK, exit 0; MySQL 3 assertions fewer (run-dependent, see §RFC4E.6) |
| Review pack v3 | DONE | — | `/root/Downloads/WP9_U0_INDEPENDENT_OWNER_REVIEW_PACK_v3` | v1 / v2 unchanged |

## Acceptance (2026-10-16)

**`WP9_U0_DEVELOPMENT_ACCEPTED_WITH_NOTES`** at `30ceaa09` (owner, after the review of pack v3). U0 is closed as a development unit. Do not re-open it without new evidence of a real defect. The remaining work is listed in `WP8_WP9_UNIFIED_CLOSURE_AND_RELEASE_ROADMAP.md` §5.

