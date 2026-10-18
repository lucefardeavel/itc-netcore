# SaaS RADIUS V1 - Final Operational Closure

Date: 2026-10-18 (UTC). Authorization: the existing autonomous execution authorization and the owner's O-DL direction. Nothing was pushed, merged or deployed; no production data, live NAS or secret
was touched; the SQL-exhaustion reproduction was never run or approximated.

## 1. Final candidate

| Item | Value |
|---|---|
| Product candidate | **`9da2015e`** (the full dual-engine harness, CHR gates, UI / PWA, drill, load and capacity ran on exactly this product tree) |
| Repository HEAD | the commit that adds this file; `git diff 9da2015e HEAD -- system install ui index.php` is **empty**: this round changed only documents, tooling and evidence, so the long suites were not repeated (focused N1 + G set re-run, section 2.5) |
| Branch | `wp9/red-team-rc`, local, nothing pushed; protected refs unchanged (`feat/reseller-system-v1` = `wp8/integration-poc` = `704031c6`, `main` = `500d3a2d`, `hotfix/pace-xhr-csrf` = `6d4f3f96`, `wp7/staff-pwa` = `8e7f0e1c`) |
| Status | **`RELEASE_BLOCKED_WITH_EVIDENCE`** - only target evidence, owner decisions on real data and counsel remain; not `READY_FOR_PRODUCTION_OWNER_APPROVAL` |

## 2. O-DL classification and evidence

Full study: `O_DL_CLASSIFICATION.md`; evidence `evidence/odl/`; tools `dev/wp10/DeadlockStudyTest.php`, `deadlock_study.sh`, `deadlock_report.py`.

| Question of the owner | Answer |
|---|---|
| Regression, existing limit or test variability? | **Existing contention limit of the accepted code, specific to MariaDB.** Same workload (12 concurrent NEW activations on one wallet, 300 operations per run), same database configuration and isolation level, interleaved runs in fresh worktrees: previous candidate `e34906d6` **4 refusals / 1,800 operations (0.22 %)**, final candidate **5 / 1,800 (0.28 %)**; deadlocks 146 vs 174; MySQL 8.0: **0 refusals, 0 - 1 deadlocks** in 3,600 operations |
| Frequency | about 1 operation in 400 on MariaDB, only under a 12-way stampede on one wallet; about 1 of every 4 runs of a 96-operation load sees one |
| Retry outcome | the bounded whole-unit retry (2) absorbs almost all deadlocks (about 10 per 100 operations on MariaDB); a third consecutive deadlock surfaces as `CONCURRENCY_RETRY` |
| Reservation state | every refusal: activation `FUNDS_RESERVED`, **exactly one ACTIVE reservation** (funds held), never released by the refusal, never a second one |
| User-facing result | "The financial operation could not complete because of concurrent activity; please try again." The customer's retry is refused (`ACTIVATION_IN_PROGRESS`): no double reservation, no double charge (the wording "try again" is misleading for this case - noted, not changed) |
| Operator recovery | listed by `reconcile.php stale` after the 300 s stale floor; alerts: `activations_stale` WARNING 5 min / CRITICAL 30 min, reservations WARNING 1 h / CRITICAL 24 h; **resume** 109 ms on average (191 ms max, 18 resumes: exactly one settlement, service granted) or **cancel** (release, nothing charged or granted) |
| No double charge / unintended grant / lost reservation / orphan / indefinite HELD | **confirmed in every structured run on both engines** (completed = committed reservations = ACTIVATION_DEBIT, no ACTIVE reservation, no non-terminal activation, integrity empty, no customer served twice). The HELD state is bounded by the operator action and the alerts, **not by time** - by design |
| Minimal safe improvements | evaluated, **none implemented**: (A) jittered backoff between the existing retries, same bound and semantics - refusals **8 / 5,400 -> 0 / 5,400** in the lab; (B) more retries - 1 / 1,800 but changes the pinned bound. Both touch the locked WP3A retry contract: **separate authorization needed**. Automatic cancel: not done (owner direction) |
| Engineering classification for the owner | **accepted production residual** (fail-safe, rare, MariaDB-specific, monitored, recoverable), optional improvement A on request |

## 2.5 N1 + G and the enforcer (verified)

`N1_G_VERIFICATION.md`: every condition of Option G is mapped to a test (raw accounting kept, actual vs billable separate, no debt / wallet / ledger movement, not carried into a renewal, ledger and settlement unchanged, boundary and financial cases), on both engines and on the real NAS;
final enforcer performance: pass 0.05 s at 500, 0.13 s at 2,000, 0.3 - 0.5 s at 5,000 and 0.4 s at 7,500 online quota subscribers (the frozen-candidate scan took about 5 s at 5,000), zero lost accounting requests, p99 < 170 ms. Fresh confirmation on this HEAD: 8 focused classes OK on MariaDB and MySQL.
Lab figures only; the target is TA-09.

## 3. Remaining technical risks

| # | Risk | State |
|---|---|---|
| 1 | Target behaviour unverified: RouterOS version / model specifics (limits, CoA, interim), NAS NTP, real access network, TLS / proxy, target hardware sizing | **TA-01 .. TA-10 NOT_RUN** |
| 2 | N1 weak cases: a small quota on a fast line is cut by the NAS cap with an overshoot up to the whole quota; enforcer / FreeRADIUS / database outage allows up to 2 x the remaining quota on PPPoE (stated separately in the contract) | accepted by the owner's N1 + G decision; monitored |
| 3 | O-DL deadlock refusal on MariaDB | section 2 |
| 4 | A2-R: effect of SQL exhaustion on the admission statements unverified (forbidden reproduction) | mitigated by L1 - L5, evidence in `A2R_OWNER_DECISION_EVIDENCE.md`; owner adoption + conditions C1 - C7 |
| 5 | OBS-1 existing duplicate identities on real data | tooling, runbook, sheet ready; needs the isolated authorized copy and the owner's choices |
| 6 | jQuery 1.11.1 remains (no exploitable path found; sinks fixed and pinned) | accepted, post-launch hardening plan |
| 7 | Time contract is lab-verified, not production-ready until TA-02 / TA-06 | open |

## 4. Exact staging hardware, NAS, database and network access needed

Full consolidated request with fill-in fields: `TARGET_AUTHORIZATION_REQUEST.md`; how it is built: `docs/STAGING_DEPLOYMENT_PACKAGE.md`.

| Resource | Requirement |
|---|---|
| Staging NAS | the **same RouterOS version and board / model as production**, no customer traffic, its own staging RADIUS secret; operator write access for `/radius`, `/radius incoming`, `/ppp aaa`, PPPoE + Hotspot servers on the test interface, `/system ntp client` |
| Test segment + client | an isolated interface / VLAN with no subscribers; a synthetic client (second router / CHR / Linux pppoe client) that can dial PPPoE and Hotspot and generate mixed traffic at a known rate |
| Application host | 2 vCPU, 4 GB, SSD, PHP 8.2 (pdo_mysql, curl, gd, mbstring, zip), HTTPS front with a valid certificate (the production proxy type), cron |
| Database host | 4 vCPU, 8 GB, disk-backed SSD, MariaDB 10.11 **or** MySQL 8.0 (the production engine), a separate instance, REPEATABLE-READ, UTC, `max_connections` >= 151; least-privilege accounts from `grants.php` |
| FreeRADIUS host | 4 vCPU, 4 GB (may share the application host), FreeRADIUS 3.2.x threaded, DB over 3306, NAS over UDP 1812 / 1813 without NAT |
| Load generator | any PHP 8.2 host that can send UDP 1813 to FreeRADIUS |
| Network | operator -> app 443; app + FreeRADIUS -> DB 3306; NAS -> FreeRADIUS UDP 1812 / 1813; app -> NAS UDP 3799 (or `nas.ports`); generator -> FreeRADIUS UDP 1813; NTP UDP 123; SSH. The RouterOS API stays closed |
| People / time | a named operator, a window in UTC (about 2 working days), a rollback contact |

## 5. Required authorization boundaries

Granted in this round (unchanged): local development, tests, synthetic databases, the isolated CHR lab, documentation, local commits.
**To be granted by the owner (not assumed):** deploy the named candidate on the **staging** hosts only; configure the staging NAS (`nuxr_tgt_*` objects and the listed settings, prior values captured and restored); create synthetic `tgt_*` data and send synthetic accounting; run TA-01 .. TA-10 incl. failure injection on staging services, the restore drill, the bounded capacity test and non-destructive security checks; keep the redacted evidence in the repository.
**Never covered by this request:** production deployment or migration, any production database / router / secret / customer record, push or merge of a protected branch, the OBS-1 survey on real data (separate request), the SQL-exhaustion reproduction, automatic cancellation of held operations, a change to the locked retry / finance contracts (variant A needs its own authorization).

## 6. TA-01 .. TA-10 readiness

All ten are **prepared** and **NOT_RUN**; none is `PASS`. Execution sheets with numeric pass / fail criteria, stop conditions and evidence names: `docs/V1_TARGET_ACCEPTANCE.md` section 2A.

| ID | Prepared | Mechanical evaluator | Blocked by |
|---|---|---|---|
| TA-01 preflight + health | commands, 8 required checks | `dev/staging/ta_eval.py TA-01` (self-tested; correctly FAILs the lab copy) | application / database / FreeRADIUS hosts |
| TA-02 clock discipline | limits core <= 5 s, NAS <= 60 s | `ta_eval.py TA-02` | hosts + NAS + NTP |
| TA-03 NAS contract | RouterOS template `dev/staging/staging_nas.rsc.tpl` | criteria | staging NAS |
| TA-04 capability probe | numeric criteria, lab reference | criteria | staging NAS + client |
| TA-05 overshoot (N1 = A + G) | bound formula, Option G checks, failure step | criteria | NAS + client + line rate |
| TA-06 renewal / time contract | steps incl. immediate re-dial, delayed accounting | criteria | NAS + client |
| TA-07 failure injection | steps, expected alerts | criteria | NAS + FreeRADIUS |
| TA-08 deploy / restore / rollback | runbooks, criteria | criteria | hosts |
| TA-09 capacity | `dev/staging/acct_gen.php`, `seed_quota_rows.sql` | `ta_eval.py TA-09` | DB + FreeRADIUS + the planned peak |
| TA-10 security | commands, criteria | `ta_eval.py TA-10` | the real HTTPS front |

## 7. Remaining legal and owner decisions

| Item | State | Needed |
|---|---|---|
| Staging authorization (P1 - P5, resources, window) | open | answer `TARGET_AUTHORIZATION_REQUEST.md` |
| O-DL classification | evidence complete | the owner records "accepted production residual" (or asks for variant A: separate authorization) |
| A2-R classification and C7 | evidence complete (`A2R_OWNER_DECISION_EVIDENCE.md`) | owner: option A / B / C |
| OBS-1 on real data | tooling + runbook + sheet ready | separate authorization for an isolated copy; the owner's decision sheet |
| Legal review (LIC-1) | package finished, **not approved** (`LEGAL_REVIEW_PACKAGE.md`: 11 questions, answer sheet, the four missing licence files and where they come from, `notices_bundle.py`) | counsel; the maintainers add the missing licence files |
| WP9 / WP10 ledger rows | `PENDING` (D1 recorded as a separate development-acceptance note) | owner-controlled |
| Production deployment authorization | not given | separate, explicit |

## 8. Clear next action to production approval

1. **Owner:** answer `TARGET_AUTHORIZATION_REQUEST.md` (resources R1 - R10, the five boundaries, the window) and name the candidate SHA to deploy (`9da2015e` unless code changes).
2. **Operator:** build the staging per `docs/STAGING_DEPLOYMENT_PACKAGE.md` and run TA-01 .. TA-10 per section 2A; the engineering side evaluates the evidence and fills the table. Any FAIL is fixed (with tests first) and the affected gates re-run.
3. **In parallel:** the owner decides O-DL (record the residual) and A2-R (option A / B / C); counsel works through the legal package; the OBS-1 real-data survey is authorized separately and its sheet completed.
4. **Then:** with all ten TA rows `PASS` with evidence files and sections 7's items decided, the status moves to `READY_FOR_PRODUCTION_OWNER_APPROVAL`; production deployment remains a separate authorization.

## 9. What this round added (all inside the authorization)

Documents: `O_DL_CLASSIFICATION.md`, `N1_G_VERIFICATION.md`, `A2R_OWNER_DECISION_EVIDENCE.md`, the consolidated `TARGET_AUTHORIZATION_REQUEST.md`, `docs/STAGING_DEPLOYMENT_PACKAGE.md`, `docs/OBS1_SURVEY_RUNBOOK.md`, TA execution sheets, the completed `LEGAL_REVIEW_PACKAGE.md`.
Tooling: `dev/wp10/DeadlockStudyTest.php` / `deadlock_study.sh` / `deadlock_report.py`, `a2r_evidence.py`, `obs1_sheet.py`, `notices_bundle.py`; `dev/staging/ta_eval.py`, `acct_gen.php`, `seed_quota_rows.sql`, `staging_nas.rsc.tpl`.
Evidence: `evidence/odl/`, `evidence/final-v2/` (fresh N1 + G run, A2-R matrix). No product code changed.
