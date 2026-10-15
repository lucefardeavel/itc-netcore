# WP8 — Final Integration Report (S8: final integration evidence and documentation)

| Item | Value |
|---|---|
| Owner decision | `S7_COMMITTED_PUSHED_VERIFIED — S8_DOCUMENTATION_AUTHORIZED` (2026-10-14) |
| Canonical baseline | `wp8/integration-poc@b96747b5f453a74f731624e01d5d5b4e8c53c43c` (= origin); worktree clean before S8 |
| Plan basis | `PLAN.md` §27 (S8 = this report + `docs/WP8_INTEGRATION_MODEL.md` + WP4 real-NAS notes); owner decision D1 (A1 → A5 → S7 → S8); Architecture Lock 2026-10-09 (§6, C-17); `OWNER_DECISIONS.md` |
| S8 nature | Documentation and evidence audit only. **No product code, schema, finance, test or lab change; no test run; no CHR; no production; no commit or push** |
| Ledger | `docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md`: **WP8 = PENDING** (owner-controlled; not changed) |
| **Final state** | **`S8_READY_FOR_OWNER_ACCEPTANCE`** (§9). WP8 overall: **Development implementation complete with notes; integration verified with gaps; not operationally ready; not production ready** (§7) |
| Final evidence re-gate (2026-10-14, added) | Owner `S8_DOCUMENTATION_REVIEWED — FINAL_EVIDENCE_REGATE_AUTHORIZED`. Result **`WP8_FINAL_REGATE_READY_FOR_OWNER_REVIEW`**: §10 and `WP8_S8_FINAL_EVIDENCE_REGATE.md`. **WP8 stays PENDING** |
| Warning remediation (2026-10-14, added) | Owner `D-S8-3_TEST_ONLY_FIX_APPROVED — WP8_ACCEPTANCE_PENDING`. Result **`WP8_READY_FOR_FINAL_DEVELOPMENT_ACCEPTANCE`** (§11). WP8 stays PENDING |
| Final development finalization (2026-10-15, added) | Owner `S8_REGATE_ACCEPTED — FINAL_DEVELOPMENT_FINALIZATION_AUTHORIZED`. D-S8-4 … D-S8-7 closed as authorized; pre-commit audit passed; one S8 commit to `wp8/integration-poc` (§12). State **`WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS`**, **`PRODUCTION_NOT_AUTHORIZED`** |

Paths below are relative to `owner-review/WP8/` unless they start with `docs/`, `tests/`, `install/`, `system/` or `dev/`. Every number below is quoted from an evidence or decision file. **Incomplete, skipped, aborted and interrupted runs are never counted as passes.** `NOT_VERIFIED` means no sufficient evidence was found.

## A. Baseline, branch and worktree

| | |
|---|---|
| Upstream ancestry | `d3e05962…` (ledger) |
| Accepted prior WP | WP7 `8e7f0e1c`, then the Pace / XHR hotfix `6d4f3f96`. WP8 branched from `feat/reseller-system-v1@5bdc5745` |
| WP8 branch | `wp8/integration-poc` = origin = `b96747b5` (23 WP8 commits, `5de6d8e6` … `b96747b5`) |
| Protected branches (unchanged) | `feat` `5bdc5745`, `main` `500d3a2d`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `hotfix` `6d4f3f96` |
| S8 worktree changes | new `owner-review/WP8/REPORT.md`, new `docs/WP8_INTEGRATION_MODEL.md`, additive `docs/WP4_RADIUS_MODEL.md` (+43 lines, 0 removed) |

## B. Scope

**WP8 mission** (pack prompt `docs/claude-development-pack/prompts/WP8_INTEGRATION_POC.md`): validate end to end with real FreeRADIUS and a MikroTik test NAS.

**How it was executed:** on a disposable RouterOS **CHR 7.24.5** under QEMU TCG in an isolated local lab, instead of an external NAS over WireGuard (owner amendment W8-A1, `CHR_FEASIBILITY.md` **PASS_LOCAL_CHR**; W8-A2 sets 7.24.5).

**S8 scope** (owner §1–§8):
- audit the S1…S7 evidence (including A1–A5, A2-R, H-1/H1-A, H-2B and the test-infrastructure items);
- the integration model;
- the WP4 verified notes;
- the coverage matrix and the unified blocker register;
- consistency;
- the acceptance assessment;
- the decisions needed.

## 1. Executive summary

- **Built and proven on the lab NAS (RouterOS 7.24.5 + FreeRADIUS 3.2.8):**
  - PPPoE and Hotspot AAA with Message-Authenticator enforcement;
  - accounting;
  - Disconnect / CoA semantics;
  - the full reseller A → B → C → Sales financial flow with conservation;
  - NEW / RENEW / CHANGE_PLAN / EXPIRY / REACTIVATE;
  - ten failure drills;
  - fail-closed PPPoE admission (session guard + atomic reservation) and Hotspot admission (exact identity + cross-NAS slots);
  - rename integrity;
  - the accounting time contract;
  - the legacy-path closure, including the S7 driver-authority fix and the pre-write recharge refusal.
- **Every stage has an owner record** (§2).
  - Accepted stages were accepted for **development only**; none is production-accepted, and WP8 itself is PENDING.
  - A2's security gate **failed** (`RACE_PROVEN`; remediated by A2-R).
  - Options E and F were **rejected**.
  - "Option G gate: PASSED" is an executor record.
- **Evidence quality:**
  - S1–S6 and the OD-W8-16 gates recorded **summary counts only**: no raw runner output and no exit codes (§3).
  - Later rounds re-ran most real-NAS classes with raw output.
  - The full lab suite `wp8-integration` has **never exited 0**: the pre-existing `system/autoload/Message.php:13-15` warnings under `failOnWarning`.
  - The `wp8-security-gates` suite was last run on 2026-10-10, before H-2B.
- **Production is blocked** by the open items in §5. The most important are:
  - OBS-1 / L-A5-2 (identity collisions at creation);
  - the A2-R SQL-exhaustion risk (unverified, owner-classified production blocker);
  - Strict-Mode `PENDING` recovery and AV-1;
  - the P-A4 time preflights;
  - the H-2 follow-ups;
  - R-HF-CARRY-01;
  - Phase B plugin authority;
  - the Architecture-Lock release gates (Q1–Q4, P1–P3, N6–N7, REL-01–05, ISO-01) and lock risk R-021 (telemetry).

## 2. Evidence coverage matrix, per stage

Legend for "Result":
- counts are **as recorded** in the evidence;
- **raw** = runner output with an exit code exists in the evidence;
- **summary** = only a results markdown file states it.

| Stage (commit) | What was executed | NAS / services / engines | Scenarios | Result | Evidence | Fixed | Left open | Not verified | Owner status |
|---|---|---|---|---|---|---|---|---|---|
| **CHR feasibility** (`ad314341`) | Boot a CHR under QEMU TCG; PPPoE, Hotspot, accounting, CoA, PEAR2 API | CHR 7.12.1 → 7.20.3; FreeRADIUS 3.2.8 with **static** `clients.conf`; netns clients | 7.12.1: 6 Access-Requests, 0 replies (no Message-Authenticator). 7.20.3: Accept. CoA: 406 from `10.88.0.10`; UNKNOWN from `.20`; Hotspot / PPPoE Disconnect ACK | No PHPUnit; manual probes (summary + probe files) | `CHR_FEASIBILITY.md`, `evidence/chr-feasibility/` | firmware moved to 7.20.3 | OD-W8-10 / 11 / 12, OBS-W8-6, R-WP8-CARRY-01 opened | `read_clients` path; rate direction; Hotspot http-chap | **ACCEPTED** (`PASS_LOCAL_CHR`), `OWNER_DECISIONS.md:73-74` |
| **S1 stop: MA policy** (`ab6c9dfa`) | OD-W8-10 verification with SQL clients, 3 modes; 7.24.5 smoke | FreeRADIUS 3.2.8; CHR 7.24.5 | Global `yes` **not applied** to SQL clients (mode B Accept); site policy (mode C) drops requests without MA | Probe files (summary) | `S1_STOP_MESSAGE_AUTHENTICATOR.md`, `evidence/s1-ma-policy/` | — | OD-W8-13 | per-client SQL policy in newer FreeRADIUS | Option A approved (`:95`) |
| **S1** (`c7811e50`) | `authorize` MA enforcement; lab tooling; SQL-client tests | CHR 7.24.5; FreeRADIUS `read_clients`; WP4 suites on MariaDB / MySQL | MA-1/2/3, accounting without MA, attributes, expiration, quotas (crafted test client) | **summary:** `FreeRadiusSqlClientTest` 8/8, `RouterOsClosureTest` 1/1, WP4 71/71 per engine; exit codes **NOT_VERIFIED** | `evidence/s1/S1_RESULTS.md`, `lab-evidence.jsonl` | OD-W8-13 (OD-W8-10 closed) | OBS-W8-7 (Accounting-On unanswered; ambiguous vs closure jsonl) | quotas and attributes **on RouterOS** (crafted packets only); `REPORT.md` cited at `OWNER_DECISIONS.md:105` never existed (C-15) | **S1 ACCEPTED** (`:125`) |
| **S2** (`b4347279`) | Real AAA and accounting | CHR 7.24.5; netns pppd CHAP / Hotspot http-chap | PPPoE pool / static / rate / Simultaneous-Use / negatives / expiry; Hotspot login / rate / accounting / limit / expiry | **summary:** `RealAaaTest` 7/7 (78); exit **NOT_VERIFIED**. **Later raw re-runs:** RealAaa ×3 OK 7 / 79 rc=0 (`evidence/av5/phpunit-summaries.txt`); S7 focused regression OK, exit 0 | `evidence/s2/` | lab: Hotspot `address-pool=none`, `nodefaultroute` | missing pool → Accept without IPv4 (no ID) | quotas on NAS; Hotspot `shared_users` > 1 (S2); burst; WISPr; IPv6 | **S2 ACCEPTED** (`:125`) |
| **S3** (`cc29b505`) | Real Disconnect / CoA with the unchanged `CoAClient` | CHR 7.24.5; runner in the FreeRADIUS netns | PPPoE / Hotspot Disconnect; rate CoA; IP / pool CoA; silence; 406 | **summary:** `CoaDisconnectTest` 6/6 (70); exit **NOT_VERIFIED**; the first of two runs is not recorded | `evidence/s3/`, `STOP_AVAIA_S3.md` | — (mapping unchanged) | OD-W8-14 → closed, Option A; lost Stop not resent (→ OD-W8-16) | download rate after CoA; how "no response" was induced; RouterOS default port 1700 | **S3 ACCEPTED_WITH_NOTES** (`:125`); AVAIA stop cleared (`:126-127`) |
| **S4** (`67b79099`) | A → B → C → Sales NEW activation on the real NAS | CHR 7.24.5; FreeRADIUS; PPPoE client; disposable app DB (engine **NOT_VERIFIED**) | Funding, transfers, pricing chain, Sales scope / redaction (service level), service before settlement, conservation, replay | **summary:** `ResellerBusinessFlowTest` 1/1 (69); exit **NOT_VERIFIED**. Re-run inside A3's full lab run (raw, §3) | `evidence/s4/` | — | — | Hotspot / VPN flows; HTTP / API redaction; concurrent finance | **S4 = ACCEPTED** (`:153`) |
| **S5** (`cb29ac4f`) | Lifecycle through `ActivationService` and `LegacyReprovisionService` | as S4 | RENEW (no disconnect, old Session-Timeout), CHANGE_PLAN rate / pool (Disconnect + re-auth), EXPIRY, REACTIVATE, password / username / uncertain commit | **summary:** `RealLifecycleTest` 3/3 ("84 + 34" assertions: **ambiguous**); exit NOT_VERIFIED. **Later raw:** A3 CHR 9/269 exit 0; S7 focused 18/418 exit 0 | `evidence/s5/` | — | OD-W8-15 → R-WP8-CARRY-02 (closed by A3) | Hotspot lifecycle; live-rate CoA in the activation path; reversal on NAS | **S5 = ACCEPTED_WITH_NOTE** (`:153`) |
| **S6** (`729dbf93`) | 10 failure drills (9 methods) | CHR 7.24.5; FreeRADIUS; `docker pause`, firewall, `RadiusFaults` | Drills 1–10 (§3 of the model) | **summary:** `FailureDrillsTest` 9/9 (277); exit NOT_VERIFIED. **Drill 7 passed by asserting the lockout** (a characterisation, not a functional pass). Re-run inside A3's full lab run (raw) | `evidence/s6/`, `STOP_S6_LOST_STOP.md` | — | OD-W8-16 (lost-Stop lockout), OBS-W8-4 / 6 | Hotspot drills; CoA UNKNOWN reconciliation (drill 10 plan) | **S6 = ACCEPTED_WITH_BLOCKER** (OD-W8-16) (`:153-154`); formal closure **never recorded** (C-19) |
| **Gate E: Port-Limit** (`8e7d5567`) | Port-Limit instead of Simultaneous-Use (lab) | CHR 7.24.5 | PPPoE / Hotspot limits, accounting lost, `only-one` | PPPoE **FAILED** (2 live sessions with Port-Limit 1); Hotspot PASSED. PHPUnit counts **NOT_VERIFIED** (the tests assert the observed failure) | `evidence/od-w8-16-gate/` | — | — | — | Option E **REJECTED** (`:172`) |
| **Gate F: checkrad** (`65b96dd3`) | Stock `mikrotik_snmp` checkrad | CHR 7.24.5; net-snmp (lab only) | Gates A–E, Hotspot | A, B, C, E PASS; **Gate D fails open** (2 live sessions). Counts NOT_VERIFIED | `evidence/od-w8-16-checkrad-gate/` | — | OBS-W8-8 → R-WP8-CARRY-03 (closed by A4) | — | Option F **REJECTED** (`:188`) |
| **Gate G: session guard** (`47a3a02c`) | Fail-closed post-auth guard (candidate) | CHR 7.24.5; SNMP read-only | A–I, hostile usernames, Hotspot | All PASS (`GATE_RESULTS.md:55-76`); counts NOT_VERIFIED | `evidence/od-w8-16-option-g-gate/` | 2 candidate defects; 2 lab incidents disclosed | OBS-W8-9, suffix false LIVE, other PPP services | concurrent first login (→ A2) | "Option G gate: PASSED" (executor record, `:194`) |
| **A1** (`609dc4db`) | Permanent Option G guard + Hotspot-only Port-Limit | CHR 7.24.5; FreeRADIUS 3.2.8 + net-snmp; MariaDB / MySQL | gates A–I, drill 7 baseline + guard, clean recreation | **raw:** T4 shipped gate OK 15 / 426; regression 258 / 5470 exit 0; `wp8-integration` 69 / 1190, 0 failures, 13 skipped, 3 warnings + 1 deprecation, **exit 1** | `evidence/a1-permanent-option-g/A1_RESULTS.md` | first-run test-side failures (4) | NOTE-01 warnings, NOTE-02, NOTE-03 release items | A2 race; other FreeRADIUS versions | **A1 = ACCEPTED WITH NOTES** (`:230`) |
| **A2** (`cd09a589`) | Concurrent first-login race gate | CHR 7.24.5 (A1 config) | B0, Bscan, C, E1–E3 | **RACE_PROVEN** (two live sessions 11.6 / 40 ms apart); regression 54 / 2016, lab 6 / 117; gate exit NOT_VERIFIED | `evidence/a2-concurrent-first-login/` | — | race blocker | cross-NAS (D-A2-1) | `A2_EVIDENCE_ACCEPTED_RACE_PROVEN_BLOCKER_OPEN` (`:255-257`) |
| **A2-R** (`5ea597d2`) | Atomic PPPoE admission reservation | CHR 7.24.5 (+ CHR2); `timeout=3s`; FreeRADIUS threaded; MariaDB / MySQL | A2 gates, S-1, cross-NAS, AV-1 / AV-4, faults | **raw:** `wp8-security-gates` OK 8 / 194 rc=0 (48 trials, 0 doubles); cross-NAS 13 / 13; harness 274 / 5791 rc=0; `wp8-integration` 69 / 1203 **rc=1** (warnings); closure round 2 failures **kept** | `evidence/a2r-*` | S-1 (HIGH), AV-4, request-age read order | AV-1, AV-2, AV-3, H-1 (→ H1-A). Later: **SQL exhaustion `UNVERIFIED_SECURITY_RISK`**, classified 2026-10-12 in the H-2B round (H2BR `:152`; reproduction halted, never retried) | real SQL-exhaustion effect (owner rule) | `A2_R_ACCEPTED_WITH_NOTES` (`:296-298`) |
| **H-1 / H1-A + AV-5** (`fcf54a7a`) | Exact-byte Hotspot identity; age timing | CHR 7.24.5 Hotspot (chap / pap / cookie / MAC) | spellings, concurrency, lost accounting, vouchers, AV-5 boundary | **raw:** RealAaa ×3 7 / 79 rc=0; security gates 10 / 216 rc=0; `wp8-integration` 69 / 1203 **rc=1**; full harness **exit 1 in both runs** (AppServer defect, later fixed); 1 A2-R lab failure kept | `evidence/h1-feasibility/`, `evidence/h1a/`, `evidence/av5/` | H-1, AV-5 | MAC-H1A, AV-1, H-2, V-1 | impact queries (prepared, not run); V-1 | `H1_A_AV5_ACCEPTED_WITH_NOTES` (`:365-366`) |
| **AppServer** (`51e9915d`) | `php -S` lifecycle repair | harness, both engines | 6 lifecycle tests | **raw:** full harness run 1 **rc=1** (TI-02), run 2 986 / 67263 rc=0 per engine (79 incomplete) | `evidence/appserver/` | orphan worker 404 | TI-02, N-1, N-2 | — | ACCEPTED WITH NOTES (`:418`) |
| **TI-02** (`15377755`) | StubNas logging race | harness | race probe | **raw:** 6 / 7000 → 0 / 7000; full harness ×2 994 / 67305, 79 incomplete, rc=0 | `evidence/ti02/` | TI-02 | port check is not an atomic guarantee | non-stub `SO_REUSEADDR` binder | `TI02_ACCEPTED_WITH_NOTES` (`:449-450`) |
| **H-2 / H-2B** (`32a162d6`) | Cross-NAS Hotspot admission slots | CHR1 + CHR2 7.24.5; FreeRADIUS threaded; MariaDB 10.11.19 / MySQL 8.0.46 | gc1–gc3, burst, limit decrease, PENDING stall, faults, SQL stress | Before: 28 / 29 trials over the limit. After: gc1 12/12, gc2 6/6, gc3 5/5. **raw:** harness 1015 / 67746 exit 0 (79 incomplete). `wp8-integration`: first run **1 failure, exit 1** (`evidence/h2b-impl/lab-phpunit-wp8-integration.out`, **kept**); verify re-run 69 / 1203, 0 failures, **exit 1** (`evidence/h2b-verify/`). Lab regression A2-R 3 false (lab latency) and H1-A 3 false (Strict PENDING effect), **kept** | `evidence/h2*/`, `H2B_OWNER_ACCEPTANCE_RECORD.md` | empty-expansion defect; M1, M3, L1–L8 | §4 requirements, PENDING recovery, rollout order | PHPUnit security gates not re-run in this round | `H2B_LAB_ACCEPTED_PRODUCTION_BLOCKED`, then `H2B_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY` (H2BR `:3`, `:185`) |
| **A3** (`2628c632`) | Rename integrity, old-session lifecycle (R-WP8-CARRY-02) | CHR1 + CHR2; MariaDB / MySQL | C1–C6, case C, D-3 | **raw:** frozen harness 1167 / 1082 / 68517 / 85 incomplete, exit 0; CHR 9 / 269 exit 0; full lab `wp8-integration` 75 / 1346, 13 skipped, 3 warnings, **exit 1**; earlier blocked round and RenameOldSession run 1 (5/6) **kept** | `evidence/a3/`, A3 reports | FIX-1…7, D-3, R-1…R-8 | OBS-1, same-key ADMIN_SYNC (B-2), L-1…L-5, R2 audit / UI, READ COMMITTED, F1-context, F5-keys, R-6 / R-9 | real READ COMMITTED interleaving; other creation paths for OBS-1 | `A3_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY` (`:484`) |
| **A4** (`8ed4cb3d`) | Accounting time contract (R-WP8-CARRY-03) | MariaDB 10.11.19, MySQL 8.0.46; CHR 7.24.5 | freshness, outage, recovery, OBS-W8-8 reversal, Event-Timestamp | **raw:** frozen harness 1185 / 1099 / 69266 / 86 incomplete, exit 0; CHR 1/30, gate B 1/20, focused 10/299 (exit printed by the invoking shell) | `evidence/a4/`, A4 report | D-1…D-3 | P-A4-1…3, L-A4-1…5 | production zone; Hotspot / other NAS Event-Timestamp | `A4_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY` (`:529`) |
| **A5** (`f07917e9`) | Legacy removal guard (R-WP8-CARRY-01) | harness; stub NAS (no CHR, owner-justified) | P1–P3, D-A5-1, positive control T1c | **raw:** source 3/39, HTTP 13/215 exit 0 both engines; frozen harness 1201 / 1115 / 69521 / 86 incomplete, exit 0; pre-fix failures and harness run 2 flake **kept** | `evidence/a5/`, A5 report | C1–C3, D-A5-1, D-A5-2 | L-A5-2 (→ OBS-1), R-4, R-6 / Phase B | real-NAS refusal (later covered by S7 FC-6 on CHR) | `A5_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY` (`:576`) |
| **S7** (`b96747b5`) | Driver classification, CHR contracts, fail-closed; **S7-F1** guard fix; **S7-FC** pre-write refusal | CHR 7.24.5 (+ CHR2 for regression); temporary least-privilege API user; both engines | T-S1…T-S4; DC; FC-1…FC-8 incl. FC-2c / 2d / 3b; 24-case funnel matrix | **raw:** frozen full harness 1129 / 69661 / 104 incomplete, 0 failures, exit 0 per engine; CHR FC 10/177, DC 8/200, focused router regression 18/418, all exit 0; every failed and diagnostic run **kept** (incl. focused run 1 without CHR2) | `WP8_S7_IMPLEMENTATION_AND_TEST_REPORT.md`, `evidence/s7/` | S7-F1, OBS-S7-3 (ownership-collision case) | L-S7-FC-1, OBS-S7-1 / 2 / 4, OBS-1 | — | `S7_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY` (`:633`) |

### 2.1 Coverage of the WP8 requirements (pack prompt, contracts, lock gates)

| Requirement | Coverage | Evidence |
|---|---|---|
| Environment topology documented | **Done:** local isolated lab | `dev/wp8/README.md`, `CHR_FEASIBILITY.md`, model §1 |
| Private management path / WireGuard "where available" | **N/A by decision:** the local lab has no external path (W8-A1, OD-W8-2). A WireGuard path is **NOT_VERIFIED** | `OWNER_DECISIONS.md:9-19` |
| PPPoE test | **CHR** | S2, S5, A1, A2-R, S7 |
| Hotspot test | **CHR** | S2, H1-A, H-2B |
| Dynamic pool / static IPv4 | **CHR** | S2 |
| Online session / accounting | **CHR** | S2, A4 |
| CoA / Disconnect | **CHR** | S3, S6 |
| A → B → C chain; Admin funding; parent → child transfer | **CHR** (PPPoE customer only) | S4 |
| Wallet / commission / provider settlement conservation | **CHR** | S4, S5 |
| Sales-assigned customer operation | **CHR** (service level). HTTP / API redaction **not re-verified in WP8** | S4 |
| Expiry / renew / change | **CHR** | S5 |
| Adapted auto-renew / customer-balance / payment path "where enabled" | **Reseller scope:** auto-renew closed by design (WP5B E11 `AUTO_RENEW_UNAVAILABLE`).<br>**Balance-purchase refund on refusal:** code-read only (S7-FC §16.1).<br>**Payment gateways:** **NOT_VERIFIED in WP8** | `docs/WP5B_LEGACY_CLOSURE_MODEL.md:27` |
| Disabled / unadapted legacy paths fail closed for reseller-owned customers | **CHR + harness** | A5, S7 FC-1…FC-8, S7-FC |
| Network-loss and recovery drills | **CHR** | S6 (10 drills) |
| Exact evidence; `owner-review/WP8/REPORT.md` | **Done:** this file | `evidence/` |
| RADIUS contract verification (`06_RADIUS_CONTRACT.md`): auth, rate, Simultaneous-Use, pool, static, expiration, CoA, history retained, stale handling | **CHR** for all items **except quotas** (harness / crafted packets only) | model §3–§6 |
| MikroTik contract (`07_MIKROTIK_CONTRACT.md`): device methods contract-tested or classified; Hotspot `change_username` suspicion | **CHR:** S7 DC / T-S1…T-S4. The rename is verified (fixed in WP5B round 1; S7 DC-H3 old name gone) | S7 report §2, §5 |
| PLAN §7 D1–D8 table ("CONFIRMED / CONTRADICTED") | Produced here, in §6.1 (never produced before, C-16) | §6.1 |
| Lock release gates (§7.3) | Mapped in §7.3 | — |

## 3. Test-result ledger: what is raw, what is summary, what is open

| Item | Status |
|---|---|
| S1–S6 and the OD-W8-16 gates | **Summary counts only.** No raw PHPUnit output or exit code is stored for these stages, so their exit codes are **NOT_VERIFIED**. |
| Re-runs with raw output that cover the same real-NAS classes | `RealAaaTest`, `RouterOsClosureTest`, `RealLifecycleTest`, `AccountingTimeLabTest`, `RenameOldSessionLabTest`: S7 focused regression, **OK 18 / 418, exit 0** (`evidence/s7/fc/focused-router-regression-run2.out`).<br>`FailureDrillsTest`, `CoaDisconnectTest`, `ResellerBusinessFlowTest`: last raw run inside A3's full lab run, 0 failures, overall **exit 1** because of warnings (`evidence/a3/lab-phpunit-wp8-integration.out:121,123`). **That run was on the pre-remediation A3 tree** (`WP8_A3_IMPLEMENTATION_ACCEPTANCE_CANDIDATE.md:99`). These three classes have **no raw run on the final A3 / A4 / A5 / S7 code** (D-S8-2).<br>S7 classes: exit 0. |
| Full lab suite `wp8-integration` | **Never exit 0:** A1 (69 / 1190), A2-R (69 / 1203), AV-5 (69 / 1203), H-2B verify re-run (69 / 1203; its first run had 1 failure, kept) and A3 (75 / 1346, pre-remediation tree) all have 0 failures, 13 skipped, 3 warnings and 1 deprecation, **exit 1**.<br>**Cause:** the pre-existing `system/autoload/Message.php:13-15` `$root_path` warnings under `failOnWarning="true"`; **not resolved**.<br>**The 13 skipped** are mode-gated lab tests: not passes. |
| `wp8-security-gates` (ConcurrentFirstLogin, UsernameEquivalence, HotspotEquivalence) | Last runs: A2-R 8 / 194 rc=0; AV-5 10 / 216 rc=0 (**2026-10-10**). **Not re-run** after H-2B (site `+342` lines), A3, A4, A5 or S7.<br>By code review the H-2B change leaves the PPPoE session check and accounting path unchanged (Hotspot-only branch, `nuxh1` tokens only), and the H-2B round re-ran the A2-R lab cases. So the current pass state is **NOT_VERIFIED** (decision D-S8-2).<br>In the full harness these tests are **incomplete** (lab down), never passes. |
| Full dual-engine harness | Last frozen run (S7): **1129 / 69661 / 104 incomplete per engine, exit 0**, tree fingerprints identical before and after. Earlier non-clean full runs (AV-5 exit 1 ×2, AppServer run 1 exit 1, A5 run 2 flake) are kept in their evidence. |
| Deprecation source | Two different sources are named: the PEAR2 `Message::getIterator / count` return type (A1) and `PEAR2/Autoload.php:206` `is_file(null)` (H-2B verify). Not reconciled (C-24). |

## 4. Integration model and WP4 notes (deliverables 2 and 3)

- **`docs/WP8_INTEGRATION_MODEL.md` (new).** It covers:
  - components, topology and network paths, NAS identity;
  - AAA;
  - the admission layers;
  - accounting;
  - CoA and the session lifecycle;
  - commercial lifecycle and finance;
  - roles;
  - the legacy closure;
  - identity ownership and the device guard;
  - local vs RADIUS separation;
  - failure / UNKNOWN;
  - the pre-production configuration checklist;
  - the uncovered areas.

  Every behavioural statement carries **[CHR]** (a documented CHR test), **[HARNESS]**, **[CONTRACT]** or **NOT_VERIFIED**.
- **`docs/WP4_RADIUS_MODEL.md` (additive only, +43 / −0).** New §13 "WP8 real-NAS verified notes", plus dated notes at §6, §9 and residual 6. The original text is kept: no contract changed and history is not rewritten.

## 5. Unified production blocker register (deliverable 5)

Notes on the columns:
- **"SaaS?"**: YES = blocks running the RADIUS reseller SaaS in production; **Legacy** = blocks only use of a legacy MikroTik-API driver / legacy path; **Cond.** = blocks only a specific configuration or feature.
- **Severity source:** the owner's classification where recorded, else the Architecture Lock register (`8b0ee336` `RISK_REGISTER.md`), else "assessed" (my assessment, for owner review).
- **Phases:** "WP8-F" = a separately authorized WP8 follow-up round; WP9 = red-team / release candidate; WP10 = release, runbook and restore.
- **Closure:** nothing is closed here; closure needs evidence **and** an owner decision.

| # | ID | Item | Severity | Impact | SaaS? | Proposed phase | Source / status |
|---|---|---|---|---|---|---|---|
| 1 | **OBS-1 / L-A5-2** | Customer creation allows identity collisions (`username` vs `pppoe_username`, database-equal spellings). After S7 a collider is refused without any write, but it cannot be served, and two unassigned colliders still overwrite each other. | **PRODUCTION BLOCKER** (owner, `:512-514`, `:587`, `:620`); HIGH | Mis-provisioning, denial of service, RADIUS key takeover between legacy customers | **YES** | WP8-F or WP9: creation-time check (smallest fix proposed in the A3 remediation report §8) plus a DB-uniqueness decision (schema, change control) | open |
| 2 | **A2-R SQL exhaustion** | The empty-expansion behaviour could affect A2-R PPPoE statements | **`UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`** (owner, H2BR `:152`) | Possible admission bypass under SQL failure (unproven) | **YES** | WP9: the hardening plan (study approved). **The reproduction is halted and must not be retried** | open |
| 3 | **AV-1** | A FreeRADIUS stall longer than ~9.3 s leaves an orphan `PENDING`; the subscriber is refused until an audited release | Open production release risk (owner, `:313-320`); lock R-005 HIGH | Availability (fail-closed) | **YES** | WP10 (runbook + monitoring), with #4 | open |
| 4 | **PENDING recovery** (A2-R / H-2B) | No executable, safety-gated release procedure | Needs a separate owner decision (H2BR `:133`, `:168`) | Operators cannot recover locked subscribers safely | **YES** | WP8-F or WP10 (own safety gate) | open |
| 5 | **AV-2** | Capacity sizing (`pool.max`, `max_servers`, DB connections); lab evidence only | Open (owner); lock REL-04 | SQL exhaustion under load | **YES** | WP9 / WP10 load measurement | open |
| 6 | **AV-3** | Operator identity in the release audit is free text | Open (owner) | Weak accountability for manual releases | **YES** | WP9 | open |
| 7 | **H-2 follow-ups** | Read-only impact queries; NAS contract per Hotspot NAS; SQL sizing; monitoring; rollout order (live sessions hold no slot at enable time) | Production blocked (H2BR §4) | Over-limit or refused Hotspot sessions at rollout | **YES** (Hotspot) | WP10 preflight / rollout plan | open |
| 8 | **MAC-H1A** | `mac-as-username` with a lower-case stored MAC is refused since H1-A | Open production note (owner, `:387-391`) | Refused MAC-auth subscribers | Cond. (MAC auth) | WP10 preflight (impact queries prepared, not run) | open |
| 9 | **V-1** | VPN username equivalence is not proven (stock fail-open path) | Not proven (owner, `:392`) | Possible VPN double session | Cond. (VPN service) | WP9 or a feature decision | open |
| 10 | **P-A4-1** | The effective SQL **session** zone must be UTC on writer and reader | **Production blocker** (owner, `:557-559`) | Wrong freshness / quota edges | **YES** | WP10 preflight | open |
| 11 | **P-A4-2** | NTP on every NAS and host; Hotspot and other NAS `Event-Timestamp` not captured | **Production blocker** (owner, `:560-562`) | Clock skew → wrong ages | **YES** | WP10 preflight (+ Hotspot capture) | open |
| 12 | **P-A4-3** | Existing `radacct` history written under another zone needs a survey and plan | **Production blocker** (owner, `:563-564`) | Shifted historic reads | **YES** (existing installations) | WP10 | open |
| 13 | L-A4-1…5 | DST zone, zone mismatch, NAS clock, wording, display threshold | MEDIUM-if-violated / INFO (A4 report) | Covered by #10–#12 | — | WP10 | open (documented) |
| 14 | **Same-key ADMIN_SYNC** (B-2 / D-2, R-9) | Two Radius plans on one RADIUS key → ADMIN_SYNC / rename `REPROVISION_UNCONFIRMED` | Needs treatment before production (owner, `:515-517`) | Re-provision unconfirmable for such configurations | Cond. (shared key) | WP9 | open |
| 15 | **RadiusRest / L-4** | The rlm_rest mode is outside WP8 | Open before production (owner, `:518`) | Unverified mode | Cond. (rlm_rest) | Feature decision / WP9 | open |
| 16 | L-1, L-2, L-3, L-5 | Rename overlap window; lost Start → `NO_ACCOUNTING_EVIDENCE`; snapshot rule; old-name slots under Strict Mode | Documented residuals (A3) | Bounded windows / operator review | — (runbook) | WP10 runbook | open (accepted residuals) |
| 17 | **R2 audit / UI** (R-a) | The unassigned legacy rename path has no `old_session` audit or warning UI | LOW (assessed) | Operator visibility | Legacy | WP9 | open |
| 18 | **READ COMMITTED interleaving** | Not every interleaving is proven (DELETE-count guard; deployment default REPEATABLE READ) | Conditional (owner, `:520-521`) | Possible race under READ COMMITTED | Cond. → **[CONTRACT]** keep REPEATABLE READ | WP10 deployment check | open |
| 19 | **R-4 cron / renewal race** | Cron expiry vs a RENEW in flight | **Not proven** (A5 report `:206`) | Possible wrong expiry state | **YES** (reseller renewals) | WP9 concurrency gate | open |
| 20 | **R-HF-CARRY-01** | Legacy autoload router / plan metadata authorization exposure | MEDIUM (owner); lock R-011 MEDIUM | Information exposure | **YES** | WP9 | open |
| 21 | **Phase B: plugin / direct-device / direct-DB authority** (A5 R-6, WP2 / WP5B notes) | Code running inside the application can bypass the new authority | Lock R-015 HIGH | Authority bypass | **YES** (if plugins are installed) | WP9 red team | open |
| 22 | **OBS-S7-1** | Drivers ignore RouterOS `!trap` (a rejected grant reports success) | MEDIUM (S7 §13) | False success on legacy devices | **Legacy** | Before enabling legacy MikroTik-API devices / WP9 | open |
| 23 | **OBS-S7-2** | VPN `rmNat()` removes by address; renames leave stale NAT comments | MEDIUM (S7 §13) | May delete an unrelated NAT rule | **Legacy** (VPN driver) | same as #22 | open |
| 24 | **OBS-S7-4** | Whitespace-normalised keys outside the guard's DB comparison (not reachable through product input) | LOW (S7 §13) | Phase-B-only bypass path | — | WP9 (Phase B) | open |
| 25 | **L-S7-FC-1** | An ordinary device failure after the authority check keeps "billed, sync manually" | MEDIUM operations (S7 §18) | Unassigned customer billed without service until synced | **Legacy** | WP10 runbook | open (accepted limitation) |
| 26 | **OBS-W8-4** | RouterOS API: no TLS, no driver timeout (60 s), missing `:port` warning | MEDIUM (S7 §13); lock R-013 MEDIUM | Credentials in clear outside a private path; hangs | **Legacy** + management | **[CONTRACT]** private path only (OD-W8-5); API-SSL in WP9 | open |
| 27 | OBS-W8-2 / OBS-W8-3 / OBS-W8-5 | `method_exists` on a path (always `add_customer`); portal connect / disconnect missing on PPPoE / VPN (fatal); exception → UNCONFIRMED | LOW / LOW / LOW (accepted design) | Legacy reliability | **Legacy** | WP9 | open |
| 28 | **OD-W8-16 / S6 blocker status** | A1 and A2-R are accepted, but no formal closure of OD-W8-16 or "S6 = ACCEPTED_WITH_BLOCKER" is recorded (`:232`, `:270`) | Record gap | Ambiguous blocker state | — | Owner decision **D-S8-1** | open |
| 29 | **Lock gates without evidence** | **Q1–Q4** quotas on a NAS (O-R01–O-R03).<br>**P1** CHANGE_PLAN after Disconnect UNKNOWN (O-R04; S6 drill 4 observed, no mitigation).<br>**P2** RENEW keeps the old Session-Timeout (O-R05; observed S5).<br>**P3** WP6A R-15 (O-R06).<br>**N6 / N7** secrets and NAS onboarding / rotation (NAS secret plaintext, WP4 Q10; R-012 HIGH).<br>**REL-01–05** restore / reconciliation / capacity / alerting.<br>**ISO-01** per-ISP isolation.<br>Plus lock **risk** R-021 (telemetry; a risk-register item, not a §5 gate) | Lock §5: mandatory release gates, PENDING | Production readiness | **YES** | WP9 / WP10 (per lock §7) | pending |
| 30 | Test-evidence hygiene | `Message.php` warnings (lab suite exit 1); `wp8-security-gates` not re-run since 2026-10-10; stale "EXPECTED TO FAIL" comments in `tests/phpunit.xml:111,116-118` and in the `ConcurrentFirstLoginGateTest` docblock; OBS-W8-6 PEAR2 deprecation | Evidence quality, not product behaviour | WP8 acceptance confidence | — | **D-S8-2, D-S8-3, D-S8-6** | open |
| 31 | Minor | OBS-W8-9 (escaped usernames fail safe); a missing NAS pool gives Accept without IPv4 (S2); OBS-W8-7 (Accounting-On unanswered, ambiguous); A3 F1-context, F5-keys, R-6; A5 refusal text "(pending WP5B)"; TI-02 port note; N-1 / N-2 (test infrastructure) | LOW / INFO | — | — | WP9 / WP10 / test hygiene | open |

**Inherited, not WP8:** WP1 / WP2 / WP3A / WP5B notes bound to WP9 / WP10 (CHECK constraints by name, NEW-7, NEW-8, least-privilege runtime DB account, backup / restore runbook) stay as recorded in the acceptance ledger.

**Closed for development during WP8**, with owner records; these are **not production closures**:
- R-WP8-CARRY-01 (A5), -02 (A3), -03 (A4);
- OD-W8-10, -13, -14;
- S-1, H-1, AV-4, AV-5;
- TI-02;
- S7-F1;
- OBS-S7-3 (ownership-collision case only).

## 6. Documentation consistency (deliverable 4, owner §5)

The history is kept. No older file was edited by S8 except the additive WP4 notes. Newer owner records prevail (`OWNER_DECISIONS.md:1` is an addendum to `PLAN.md`).

| # | Older statement | Newer evidence / decision | Handling |
|---|---|---|---|
| C-01 | `PLAN.md:3,318` verdict `NOT_READY_REAL_NAS_REQUIRED`; `:282` stop condition 1 met | W8-A1 `PASS_LOCAL_CHR` (`OWNER_DECISIONS.md:9-13`), Implementation GO (`:73-75`) | **Superseded**; recorded here |
| C-02 | `PLAN.md:25,290` "a local CHR VM is impossible" | The fact (no `/dev/kvm`) holds; the CHR boots under QEMU TCG (`CHR_FEASIBILITY.md`) | Conclusion superseded |
| C-03 | `PLAN.md` §3: no real NAS; inventory UNKNOWN | CHR 7.24.5 (W8-A2) | Superseded |
| C-04 | `PLAN.md` §4–§5: WireGuard topology; CoA through Docker NAT | Local isolated lab; OD-W8-11: no SNAT; OD-W8-1 no longer required | Superseded; NAT forbidden |
| C-05 | OD-W8-1 / 6 / 8 as planned | Amended (`OWNER_DECISIONS.md:12,23,25`) | Superseded |
| C-06 | `PLAN.md:111` `delete_stale_sessions = yes` | A1: `no` | Superseded |
| C-07 | `PLAN.md:114` timeout 1100 ms | `timeout=3s` required (`install/freeradius/README.md`) | Superseded |
| C-08 | `PLAN.md:167` SQL `Simultaneous-Use` alone | Option G guard + A2-R + H1-A + H-2B; Port-Limit Hotspot-only | Superseded |
| C-09 | `PLAN.md:184` DC-3 "NAK 503 → SESSION_NOT_FOUND"; WP4 §6 the same mapping | RouterOS 7.24.5 never sends 503; it sends **406 → FAILED COA_NAK** (OD-W8-14, Option A) | WP4 annotated (S8 note §6, §13); code unchanged |
| C-10 | `PLAN.md` §24 #1: FreeRADIUS down → "FAILED before any write" | S6 drill 1: COMPLETED (provisioning writes to the DB); no service until it returns | Recorded (model §11) |
| C-11 | `PLAN.md` §24 #10: "reconciliation resolves UNKNOWN" | CoA UNKNOWN stays an annotation; no CoA reconciliation exists | Recorded (model §11) |
| C-12 | `PLAN.md` §22: `add_customer` "guarded" | S7-F1: bypassable via `pppoe_username`, fixed (`b96747b5`) | Superseded |
| C-13 | `PLAN.md` §27: S7 = tests only; §32: "no `system/` runtime change" | D1 allows stage changes (`:216`); S7 made two owner-authorized runtime fixes | Superseded |
| C-14 | `PLAN.md` §27 / §32: compose profile `wp8`, gate variable `NUXR_WP8_REAL_NAS` | `dev/wp8/compose.wp8.yml`; `NUXR_WP8_LAB=1` + `/wp8ctl/req` | Naming drift; behaviour as planned (incomplete when absent) |
| C-15 | `OWNER_DECISIONS.md:105` "report `REPORT.md`" for S1 | That file never existed; S1's report is `evidence/s1/S1_RESULTS.md`. This file (S8) is the WP8 report, not the S1 report | Not edited (history); noted |
| C-16 | `PLAN.md:90` D1–D8 to be recorded as CONFIRMED / CONTRADICTED | Never produced | **Produced in §6.1** |
| C-17 | Architecture Lock (`8b0ee336`, local branch `docs/owner-architecture-lock-2026-10-09`, **not pushed, not an ancestor of wp8**). Lock §6 "WP8 PENDING. Option G only proved in disposable lab; S7/S8 pending"; its status report has WP8 at `47a3a02c`; RISK_REGISTER R-008 / 009 / 010 HIGH vs WP8 records MEDIUM (lock C-04 unreconciled) | A1 permanent Option G accepted; A2 / A2-R ran; CARRY-01 / 02 / 03 closed for development; S7 accepted | **D-S8-4** (bring the lock onto the WP8 / feat line and reconcile its status and severities) |
| C-18 | `tests/phpunit.xml:111,116-118`, `ConcurrentFirstLoginGateTest` docblock: "EXPECTED TO FAIL until A2-R" | A2-R re-gate passed 8 / 194 and 10 / 216 rc=0 | Not edited (test files outside S8 scope); **D-S8-6** |
| C-19 | "S6 = ACCEPTED_WITH_BLOCKER (OD-W8-16)" | A1 (Option G) and A2-R accepted; no formal closure token | **D-S8-1** |
| C-20 | OD-W8-3 / OD-W8-11 operator documentation | "RouterOS default incoming port 1700" and "CoA source = a `/radius` address" appear only in `dev/wp8/README.md` and the decision record, not in `install/freeradius/README.md` | Now in the WP4 model (§6, §13) and the integration model; operator README **D-S8-5** |
| C-21 | H-2B acceptance | Recorded only in `H2B_OWNER_ACCEPTANCE_RECORD.md`, not in `OWNER_DECISIONS.md`. Later records refer to "H-2 follow-ups" | Consistent in content; cross-reference noted here |
| C-22 | WP4 §9 "No real NAS"; residual 6 | WP8 CHR evidence | Annotated (dated notes), text kept |
| C-23 | `S5_RESULTS.md:3` "3/3 (84 + 34 assertions)" | Two numbers for three tests | Ambiguous; later raw re-runs exist (§3) |
| C-24 | Deprecation source (A1 vs H-2B verify) | Two different sources named | Not reconciled; affects only the explanation of exit 1 |
| C-25 | `dev/wp8/lab.sh up` | It does not start CHR2; the cross-NAS classes need `up-chr2` + h2 / a2r / h2b setup (S7 focused run 1 failed on that) | Tooling note (S7 §17) |
| C-26 | Owner reference "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" | **No such document** exists in the repository, the pack, the WP8 records or the lock | §7 applies the pack's DoD / checklist / evidence packet and the lock's §5 / §8 definitions; **D-S8-9** |

### 6.1 PLAN §7 RouterOS documentation facts vs the CHR (C-16)

| # | Documented | Result on CHR 7.24.5 | Evidence |
|---|---|---|---|
| D1 | `/radius incoming` default `accept=no`, `port=1700` | **Configured** `accept=yes port=3799` and working. The **defaults themselves were not exercised: NOT_VERIFIED** | `evidence/s1-ma-policy/chr-7.24.5-smoke.txt` |
| D2 | Disconnect and CoA supported (RFC 3576) | **CONFIRMED** | S3 |
| D3 | CoA may change the rate; IP / pool need a disconnect | **CONFIRMED.** The IP / pool CoA is **ACKed but not applied**: the ACK is a false success signal (lock R-016) | S3 |
| D4 | rx = client upload, tx = client download | **CONFIRMED** | S2 |
| D5 | NAS-IP-Address = the router IP; `src-address` default `0.0.0.0` | **CONFIRMED** with `src-address` set. The default `0.0.0.0` was not tested: **NOT_VERIFIED** | S1–S3 |
| D6 | RADIUS is consulted only if no local user matches | **CONFIRMED** | S7 SEP, FC-2d |
| D7 | services / ports / timeout 1100 ms / `require-message-auth` default | FreeRADIUS listens on 1812 / 1813 (`evidence/s1/freeradius-startup.txt`). `require-message-auth=yes-for-request-resp` was **configured** by the lab (`/radius/add`, `evidence/s1-ma-policy/chr-7.24.5-smoke.txt`), so the **default is NOT_VERIFIED**. **The 1100 ms default timeout is insufficient** for the guard path; the contract is `3s` | S1, A1, A2-R |
| D8 | PPP AAA and Hotspot profile RADIUS settings | **CONFIRMED** (as configured by the lab) | S2 |
| D9 (PLAN §8, `PLAN.md:116,149`; not part of §7) | A profile `remote-address` would mask RADIUS pools | **NOT_VERIFIED** (not tested) | — |

## 7. Final acceptance assessment (deliverable 6)

**Basis.**
- The pack's Definition of Done, the owner review checklist and the evidence packet (`docs/claude-development-pack/docs/DEFINITION_OF_DONE.md`, `review/OWNER_REVIEW_CHECKLIST.md`, `docs/15_CLAUDE_RULES.md`).
- The Architecture Lock's definitions (§8: APPROVED / PROVEN / PENDING / CONDITIONAL) and release gates (§5).
- The owner-named "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" was **not found** (C-26), so it is applied **only through these available artifacts**, not from its text.
- **A WP is not closed until the owner marks it; this assessment does not self-accept.**

### 7.1 Four levels

| Level | Assessment | Reasoning |
|---|---|---|
| **Development Implementation Complete** | **COMPLETE WITH NOTES, for the owner-approved D1 sequence** (A1 → A2 / A2-R → A3 → A4 → A5 → S7 → S8). S8 is complete pending owner acceptance | Every stage has an owner development acceptance (§2). Items deliberately not implemented by owner decision stay open: V-1, PENDING recovery, A2-R SQL hardening (study only), OBS-1 fix, Phase B |
| **Integration Tests Verified** | **VERIFIED WITH GAPS**, for RouterOS CHR 7.24.5 + FreeRADIUS 3.2.8 + MariaDB 10.11 / MySQL 8.0 + PHP 8.2 only | **PROVEN on the lab NAS:** model §2–§11.<br>**Gaps:**<br>1. S1–S6 / gates have summary counts only (covered by later raw re-runs for the main classes, §3).<br>2. The full lab suite has never exited 0 (warnings).<br>3. The security gates were not re-run since 2026-10-10 (H-2B, A3–A5, S7 after it).<br>4. `FailureDrillsTest`, `CoaDisconnectTest` and `ResellerBusinessFlowTest` have no raw run on the final code (their last raw run was on the pre-remediation A3 tree).<br>5. The CoA wrong-source rule was observed on 7.12.1 only (feasibility gate).<br>6. Not on a NAS: quotas, reversal, Hotspot business flows, VPN identity, payment gateways, HTTP / API redaction, IPv6, other PPP services and other RouterOS versions |
| **Operational Readiness** | **NOT READY** | Missing:<br>• an executable PENDING recovery;<br>• a runbook for UNKNOWN / UNCONFIRMED / RECONCILIATION_REQUIRED / "sync manually";<br>• monitoring and telemetry (R-021);<br>• SQL sizing (AV-2);<br>• NAS onboarding / secret rotation (N7);<br>• the P-A4 time preflights;<br>• the H-2B rollout plan;<br>• the impact queries (not run);<br>• backup / restore (REL-01) |
| **Production Readiness** | **NOT READY** | Owner-classified production blockers stay open (§5: #1, #2, #10–#12, the H-2B §4 requirements and others). The lock release gates are PENDING (#29). WP9 (red team) and WP10 (release / restore) have not started. **Lab success is not production readiness** |

### 7.2 Owner review checklist (RADIUS / network, security, legacy) — WP8 answers

| Check | Answer |
|---|---|
| No accounting history deletion? | **Yes:** no lifecycle path deletes `radacct` (WP4 guards; CHR drills; S7 RADIUS checksums) |
| `radacct` vs `rad_acct` distinguished? | **Yes:** model §5 (the rlm_rest mode is out of scope, L-4) |
| UNKNOWN handled without blind retry? | **Yes:** CHR S3 / S6; `REPROVISION_UNCONFIRMED`, `RECONCILIATION_REQUIRED`, Strict PENDING |
| No split-brain local / RADIUS subscriber? | **Yes for the reseller path** (Class A empty; local users mask RADIUS → contract rule; S7-F1 closed the collider path). The legacy drivers remain for unassigned customers |
| Unassigned legacy customers fail closed for the reseller domain? / any entrypoint granting reseller-owned service without settlement? | **No entrypoint found** among WP8-tested paths (A5, S7 FC-1…FC-8, S7-FC); plugins are Phase B |
| No new secret exposure? | S7 / A-stage secret sweeps 0 hits. The NAS secret stays plaintext at rest by design (Q10) |

### 7.3 Architecture Lock release gates (§5) vs WP8 evidence

| Gate | WP8 evidence | Status |
|---|---|---|
| Session (Option G fail-closed, lost Stop, unknown verifier / NAS, concurrent first logins, renamed old identity) | A1, Gate G, A2 / A2-R (48 trials, 0 doubles; cross-NAS 13 / 13), A3 | **PROVEN in the lab** (lock: "lab results are not production acceptance"); AV-1 and the SQL-exhaustion risk remain |
| Q1–Q4 quotas | Harness / crafted packets only | **PENDING** |
| P1 CHANGE_PLAN after Disconnect UNKNOWN | Observed (S6 drill 4); no mitigation | **PENDING** |
| P2 RENEW old Session-Timeout | Observed (S5) | **PENDING** (product decision) |
| P3 R-15 bandwidth terms | Not in WP8 | **PENDING** |
| N1 NAS identity / NAT | Identity PROVEN; NAT excluded, not tested | Partly proven |
| N2 SQL Message-Authenticator | PROVEN (S1) | Proven (lab) |
| N3 per-protocol RouterOS behaviour | PPPoE / Hotspot PROVEN; VPN API-level only (S7) | Partly proven |
| N4 CoA source / port / semantics | PROVEN (feasibility, S3, S6) | Proven (lab) |
| N5 Option G faults | PROVEN (Gate G, A1) | Proven (lab) |
| N6 secrets | SNMP credentials 0600, secret sweeps; the NAS secret plaintext (Q10) | **PENDING** |
| N7 onboarding / rotation | — | **PENDING** |
| REL-01 restore | — | **PENDING** (WP10) |
| REL-02 ledger / reservation / external reconciliation | S6 drill 3 (RADIUS commit lost → one settlement) | Partly (one path) |
| REL-03 failure drills | S6, A2-R drills | Proven (lab) |
| REL-04 capacity | Lab pool observations only (AV-2) | **PENDING** |
| REL-05 alerting | Report SQL only | **PENDING** |
| ISO-01 | — | **PENDING** |
| REL-06 HA | Conditional, not requested | CONDITIONAL |

## 8. New decisions requested (deliverable 7, only new ones)

| ID | Decision | Options and recommendation |
|---|---|---|
| **D-S8-1** | Formal status of **OD-W8-16** and "S6 = ACCEPTED_WITH_BLOCKER" | (A) close for development: the blocker is solved by A1 Option G + A2-R, both accepted; production items stay in §5 — **recommended**. (B) keep it open |
| **D-S8-2** | A consolidated final lab **re-gate on `b96747b5`** before formal WP8 acceptance: `wp8-security-gates` + the full `wp8-integration`, in the production-contract configuration with CHR2, frozen tree, evidence only | (A) authorize it as a bounded evidence round (no code change) — **recommended**, to close the evidence-currency gaps of §3 / §7.1 (security gates since 2026-10-10; the drill, CoA and business-flow classes on the final code). Optionally add a CoA wrong-source probe on 7.24.5 (an existing lab capability; it needs owner approval because it extends the test set). (B) accept WP8 on the existing evidence with the gap recorded |
| **D-S8-3** | The pre-existing `Message.php:13-15` warnings make every full lab run exit 1 | (A) a separate, owner-authorized minimal product fix (pinned and runtime files: change control) so the lab suite can exit 0. (B) accept "0 failures, exit 1 (warnings)" as the recorded WP8 lab result |
| **D-S8-4** | The Architecture Lock is not on the WP8 / feat line; its WP8 status and severity classifications are stale (C-17) | Bring the lock docs onto the line through a docs-only change and reconcile lock §6 / the status report / C-04 severities. Owner choice of branch and timing |
| **D-S8-5** | Operator README gap (C-20) | Docs-only addition to `install/freeradius/README.md`: the CoA source rule (OD-W8-11) and the RouterOS default port 1700 note (OD-W8-3) |
| **D-S8-6** | Stale "EXPECTED TO FAIL" comments (C-18) | Comment-only change in `tests/phpunit.xml` and the `ConcurrentFirstLoginGateTest` docblock, recording the A2-R re-gate, with no assertion change |
| **D-S8-7** | WP8 formal acceptance and the ledger record | Owner decision; WP8 remains PENDING until then |
| **D-S8-8** | Phase assignment of the §5 register (especially #1 OBS-1, #2 SQL hardening, #4 PENDING recovery, #19 R-4) | Proposed phases are given in §5; owner to confirm WP8-F vs WP9 / WP10 |
| **D-S8-9** | The "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" text | Provide it if a stricter checklist must be applied; §7 can then be re-run against it (docs only) |

## 9. Evidence packet (pack `15_CLAUDE_RULES.md`)

| | |
|---|---|
| **C. Files changed (S8)** | New `owner-review/WP8/REPORT.md`; new `docs/WP8_INTEGRATION_MODEL.md`; modified `docs/WP4_RADIUS_MODEL.md` (+43 / −0). No other file |
| **D. Design notes** | Evidence labels [CHR] / [HARNESS] / [CONTRACT] / NOT_VERIFIED; no behaviour is called "verified on a real NAS" without a CHR test |
| **E. Migrations / data impact** | None |
| **F. Test commands and results** | **No test was run in S8** (owner §6: no long harness without a code difference). The tree equals the tested and committed `b96747b5` (S7 fingerprint, verified at the S7 commit). Read-only audit commands only (`git`, `grep`, `sha256sum`) |
| **G. Security / failure cases** | Audited, not re-run (§2, §5, model §11) |
| **H. Diff summary** | Documentation only |
| **I. Known risks** | §5 (31 register rows) and §6 |
| **J. Production status** | Untouched. **Not production ready** (§7) |
| **K. Push status** | Nothing committed or pushed (owner §7) |
| **L. Owner-review artifact** | `owner-review/WP8/REPORT.md` (this file) |
| **M. Final state** | **`S8_READY_FOR_OWNER_ACCEPTANCE`**, `STOPPED_WAITING_OWNER_REVIEW` |

The S8 deliverables are complete and audited against the files and commits. The WP8 evidence is coherent, with the gaps stated in §3. No new test was required to write these documents. Formal WP8 acceptance, the re-gate (D-S8-2) and every production question remain owner decisions.

**`S8_READY_FOR_OWNER_ACCEPTANCE`**

---

## 10. Final evidence re-gate (2026-10-14, added after the S8 owner review)

Owner decision: `S8_DOCUMENTATION_REVIEWED — FINAL_EVIDENCE_REGATE_AUTHORIZED`.
- **Full report:** `WP8_S8_FINAL_EVIDENCE_REGATE.md`. **Evidence:** `evidence/s8-regate/`.
- **Nothing above is rewritten.** Where this section updates an earlier statement, the earlier statement stays as the record at its time.

**Decisions recorded by the owner (applied in this round):**
- **D-S8-1 = A:** OD-W8-16 is closed for development only (A1 + A2-R). AV-1, the A2-R SQL-exhaustion risk and the production requirements stay open.
- **D-S8-2 = A:** this re-gate.
- **D-S8-3:** no `Message.php` change; diagnosed only.
- **D-S8-4:** comparison and plan only.
- **D-S8-5 / D-S8-6:** deferred documentation notes.
- **D-S8-7:** WP8 acceptance and the ledger are deferred.
- **D-S8-8:** the phases of §5 are kept.
- **D-S8-9:** no claim of applying the protocol text.

**How the round ran.**
- **Lab configuration:** the production contract on the committed final code (tree fingerprints identical before and after). That is the A4 / S7 setup plus **FreeRADIUS threaded**, per `install/freeradius/README.md`.
- **Mode note:** the earlier A3 / A4 / S7 lab runs were non-threaded `-X`.
- **SQL-exhaustion tooling not run:** no A2-R SQL tooling (`a2r_sql_gate.py`, `a2r_gate.py pool|fault`) was run, and the halted SQL-exhaustion reproduction was not run under any name.

| # | Suite ([CHR], RouterOS 7.24.5 ×2, FreeRADIUS 3.2.8 threaded) | Tests | Assertions | Failures / errors | Warnings | Deprecations | Skipped | Incomplete | Exit | Evidence (`evidence/s8-regate/`) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `wp8-security-gates` | 10 | 216 | 0 / 0 | 0 | 0 | 0 | 0 | **0** | `01-wp8-security-gates.out` |
| 2 | `FailureDrillsTest` | 10 | 283 | 0 / 0 | 0 | 1 | 1 | 0 | **0** | `02-FailureDrillsTest.out` |
| 3 | `CoaDisconnectTest` | 6 | 70 | 0 / 0 | 0 | 0 | 0 | 0 | **0** | `03-CoaDisconnectTest.out` |
| 4 | `ResellerBusinessFlowTest` | 1 | 69 | 0 / 0 | 0 | 0 | 0 | 0 | **0** | `04-ResellerBusinessFlowTest.out` |
| 5 | `wp8-integration` (full) | 101 | 1848 | 0 / 0 | 3 | 1 | 13 | 0 | **1 (not a PASS)** | `05-wp8-integration.out` |

**Updates to earlier sections, dated 2026-10-14.**
- **§3 raw-vs-summary:** the S1–S6 classes, the OD-W8-16 gate classes, and `FailureDrillsTest` / `CoaDisconnectTest` / `ResellerBusinessFlowTest` now have **raw output on the final code** (suite 5; suites 2–4).
- **§3 security gates:** **re-run after H-2B / A3–A5 / S7: 10 / 216, exit 0.** 48 race trials: 45 single, 3 no-session (fail-closed), **0 double sessions**.
- **§3 full lab suite:** **still exit 1.** 0 failures; exit 1 **only** because of the 3 `Message.php:13-15` warnings.
- **Diagnosis of those warnings** (reproduced in isolation): a **test-process artefact**.
  - `FailureDrillsTest::testDrill8` registers the PEAR2 autoloader (`:477`).
  - A later in-process test then loads `Message` inside `PEAR2\Autoload::load`'s scope, which has no `$root_path`.
  - The production init order (`init.php:44` before `:80`) loads `Message` with no warning.
  - Proposed test-only fix **T-1** (unregister the PEAR2 autoloader after drill 8), **not applied**.
- **§3 deprecation source (C-24):** resolved. It is `PEAR2/Autoload.php:206` `is_file(null)` (from `testDrill8` in the tests; also emitted in production at `init.php:80` on PHP 8.2; log noise, LOW, refines OBS-W8-6).
- **§5 #28 (OD-W8-16 / S6 status):** owner decision D-S8-1 = A recorded above. Production items stay open.
- **§5 #30 (test hygiene):** `wp8-security-gates` is now current. The `Message.php` exit 1, the stale comments (D-S8-6) and the PEAR2 deprecation remain.
- **§7.1 gaps 1, 3 and 4:** closed by this round.
  - **Gap 2 remains:** the full lab suite exits 1.
  - **Gap 5 remains:** CoA wrong-source on 7.24.5, excluded by the owner; still **[CHR-FG]** only.
  - **Gap 6 (untested areas):** unchanged.
- **§6 C-17 (Architecture Lock):**
  - 0 textual conflicts against wp8 (`git merge-tree`);
  - the lock folder manifest is 10 / 10 OK;
  - docs-only reconciliation plan in `WP8_S8_FINAL_EVIDENCE_REGATE.md` §7 (land it on its own base, add a dated status addendum, a WP4 sub-note at WP8 merge time, and a C-04 severity decision). Nothing was merged or copied.

**Integrity:**
- manifest 42 / 42;
- protected and product files unchanged;
- secret sweep 0 hits for the real credentials (the drill fixture passwords `D*-pass` are literals of the committed tests, as in earlier committed evidence);
- RouterOS inventory before / after identical except the Hotspot DHCP-pool usage counters;
- no S7 objects;
- full teardown (0 links / netns / qemu / agent / containers / networks).

**Acceptance assessment (§7.1) after the re-gate:**

| Level | Assessment |
|---|---|
| Development Implementation | Complete with notes |
| Integration Tests | **Verified with gaps**, now narrowed to: full lab suite exit 1 (test-process warnings); CoA wrong-source on 7.24.5; the untested areas of §7.1 item 6 |
| Operational Readiness | **Not ready** (unchanged) |
| Production Readiness | **Not ready** (unchanged) |

**State: `WP8_FINAL_REGATE_READY_FOR_OWNER_REVIEW`.** WP8 is not closed; `OWNER_DECISIONS.md` and `ACCEPTANCE_LEDGER.md` are unchanged.

---

## 11. Test-only warning remediation (2026-10-14, added)

Owner decision: `D-S8-3_TEST_ONLY_FIX_APPROVED — WP8_ACCEPTANCE_PENDING`. Details: `WP8_S8_FINAL_EVIDENCE_REGATE.md` §10. Evidence: `evidence/s8-warning-fix/`.

**What stays as it was:** §10's results are kept as recorded, including suite 5 **exit 1** with 3 warnings on 2026-10-14 16:23Z.

**The true cause of the 3 `Message.php` warnings** (proven in isolation and on the CHR):
1. `FailureDrillsTest::testDrill8` required `system/autoload/PEAR2/Autoload.php` in the PHPUnit process.
2. That registered `PEAR2\Autoload::load` (with its internal `registered` state) for the rest of the run.
3. `ErrorReporter::report()` in a later test (`RealLifecycleTest::testLegacyReprovision…`) calls `class_exists('Message', true)`. That loaded the global `Message` class **inside PEAR2's loader scope**, where `$root_path` does not exist, so `Message.php:13-15` warned.
4. The production init order (`init.php:44` `_autoloader` before `:80`) loads `Message` without a warning.

**What was fixed:** `tests/Integration/Wp8/FailureDrillsTest.php` only (+18 / −2):
- drill 8 now loads the PEAR2 client classes through a loader scoped to the drill (PEAR2 namespace only, the application's own path rule), removed in `finally` together with the PHP settings;
- `PEAR2\Autoload` is never loaded, so its internal state cannot desynchronise;
- a new assertion checks that the SPL autoloader stack is identical before and after;
- the original assertions and the scenario are unchanged.

`Message.php`, `PEAR2/Autoload.php`, `init.php`, `tests/phpunit.xml` and `tests/bootstrap.php` are unchanged.

| Run ([CHR], RouterOS 7.24.5 ×2, FreeRADIUS 3.2.8 threaded, production contract) | Tests | Assertions | Failures / errors | Warnings | Deprecations | Skipped | Incomplete | Exit | Evidence |
|---|---|---|---|---|---|---|---|---|---|
| Drill 8 standalone | 1 | 24 | 0 / 0 | 0 | 0 | 0 | 0 | **0** | `01-drill8-standalone.out` |
| Drill 8, then `RealLifecycleTest` (one process, `--debug`) | 4 | — (debug output; no summary line) | 0 / 0 | 0 events | 0 events | 0 | 0 | **0** | `02-drill8-then-RealLifecycleTest.out` |
| **Full `wp8-integration`** | **101** | **1849** | **0 / 0** | **0** | **0** | 13 (mode-gated, not passes) | 0 | **0** | `03-wp8-integration-full.out` |

**Updates, dated 2026-10-14** (the earlier text of each section is kept):
- **§3 "Full lab suite … never exit 0":** superseded for the current tree. The full lab suite now exits **0** (101 / 1849, 0 warnings, 0 deprecations, 13 skipped).
- **§3 deprecation:** the test-side deprecation is gone because `PEAR2/Autoload.php` is no longer executed in the PHPUnit process. The production-init deprecation (`init.php:80`) remains (log noise, LOW).
- **§7.1 gap 2:** closed. **Gap 5** (CoA wrong-source, **[CHR-FG]** RouterOS 7.12.1 only, not tested on 7.24.5) and **gap 6** (untested areas) remain.

**Security gates:** not re-run (owner instruction). Product code and security tests are unchanged since §10 suite 1 (10 / 216, exit 0).

**Integrity:**
- tree after the run identical to the fixed tree;
- manifest 42 / 42;
- protected / product / configuration files unchanged;
- secret sweep: 0 secret values (only the env-var name `NUXR_WP8_CHR_PASSWORD` in the test, as in `b96747b5`);
- RouterOS configuration identical before and after (runtime counters and status text only);
- full teardown (all zero).

**§5 production blocker register: unchanged** (owner instruction). Its row #30 (test hygiene) is now partly addressed in development by this round, and is not edited.

**Acceptance assessment (§7.1) after this round:**

| Level | Assessment |
|---|---|
| Development Implementation | Complete with notes |
| Integration Tests | **Verified** for RouterOS CHR 7.24.5 + FreeRADIUS 3.2.8 threaded + MariaDB 10.11 / MySQL 8.0 + PHP 8.2 in the tested scope; full lab suite exit 0. Remaining gaps: CoA wrong-source on 7.24.5 ([CHR-FG] only) and the untested areas of §7.1 item 6 |
| Operational Readiness | **Not ready** (unchanged) |
| Production Readiness | **Not ready** (unchanged) |

**State: `WP8_READY_FOR_FINAL_DEVELOPMENT_ACCEPTANCE`.** Nothing committed or pushed; `OWNER_DECISIONS.md` and `ACCEPTANCE_LEDGER.md` unchanged; WP8 not closed.

---

## 12. Final development finalization (2026-10-15, added)

Owner decision: **`S8_REGATE_ACCEPTED — FINAL_DEVELOPMENT_FINALIZATION_AUTHORIZED`** on the canonical baseline
`wp8/integration-poc@b96747b5f453a74f731624e01d5d5b4e8c53c43c`. It authorizes completing and committing the S8 package
for development. **It is not a production approval.** Sections §1–§11 stay as recorded; this section adds to them.

**Accepted by the owner (recorded, not re-run):**
- the test-only drill 8 fix in `tests/Integration/Wp8/FailureDrillsTest.php` (scoped PEAR2 loader, with the autoloader
  stack, `error_reporting` and `default_socket_timeout` restored);
- full `wp8-integration`: 101 tests / 1849 assertions, exit 0, 0 errors / failures / warnings / deprecations. The 13
  mode-gated skips are not counted as passes. No product code or `failOnWarning` change.

**History kept:** the exit 1 run with 3 warnings (§10 suite 5, `evidence/s8-regate/05-wp8-integration.out`) and every
earlier failed or aborted run stay in the evidence unchanged.

### 12.1 D-S8 decisions: sources and closure

Sources: §8 (decision text), §10 (owner answers of 2026-10-14) and this round's decision.

| ID | Original decision (§8 / §10) | Closure in this round |
|---|---|---|
| D-S8-1 | A: close OD-W8-16 for development only | Closed 2026-10-14 (§10). Production items stay in §5 |
| D-S8-2 | A: final lab re-gate | Done (§10) |
| D-S8-3 | Diagnose `Message.php`, then the test-only fix | Done (§11); accepted by the owner in this round |
| **D-S8-4** | Lock comparison and plan (§10) → **now: document the final state's conformance with the Architecture Lock** | **Done, documentation only (§12.2).** The lock is not merged or copied. The reconciliation steps of `WP8_S8_FINAL_EVIDENCE_REGATE.md` §7 (landing the lock, a dated addendum on the lock line, the C-04 severity decision) were not executed; they remain owner decisions on branch and timing |
| **D-S8-5** | Operator README: CoA source rule (OD-W8-11) and the 1700 note (OD-W8-3) | **Done:** `install/freeradius/README.md` "Disconnect / CoA". It states only proven behaviour: port 3799 = `nas.ports` on 7.24.5; 1700 is the **vendor-documented** default, never exercised; the source rule is binding; the wrong-source case was observed **on 7.12.1 only** and was **not re-tested on 7.24.5**; NAK 406, live rate CoA, pool / IP CoA ignored |
| **D-S8-6** | Correct the stale "EXPECTED TO FAIL" comments only where the evidence makes them wrong | **Partly done:** the `ConcurrentFirstLoginGateTest` docblock now records the history and the passing re-gate (10 / 216, exit 0). The change is comment-only: `php -w` (code without comments) has the same SHA-256 before and after. **`tests/phpunit.xml` was not edited** (`:111`, `:116-118` are still stale), because audit item 5 requires `phpunit.xml` to stay exactly as it is. A comment-only change there needs the owner's explicit approval (proposed text: "A2 gate: passed after A2-R; own suite below; failures are never waived") |
| **D-S8-7** | WP8 formal acceptance and the ledger | **Final re-gate condition: met** (§10 suite 1, §11 full suite). Recorded in `OWNER_DECISIONS.md` (S8 record) as a **development acceptance with blockers**, separate from production readiness. **The ledger row stays `PENDING`.** Its structure needs an accepted HEAD, and every earlier ledger entry was written by a docs-only acceptance commit on `feat` at integration time. Integration into `feat` is not authorized, and this commit cannot name its own SHA. Recording it is left to the owner |
| D-S8-8 | Keep the §5 phases | Unchanged |
| D-S8-9 | No claim of applying the "MASTER" protocol text | Unchanged: the protocol was not applied from its text |

### 12.2 Architecture Lock conformance of the final WP8 state (D-S8-4)

- **Lock source, read only:** `docs/architecture/owner-lock-2026-10-09/OWNER_ARCHITECTURE_LOCK.md` at `8b0ee336`.
  - It is on the local branch `docs/owner-architecture-lock-2026-10-09`; the manifest is 10 / 10.
  - It is **not** on `wp8/integration-poc` and was not merged or copied.
- **Status words** are the lock's §8 definitions: APPROVED / PROVEN (exact NAS / version / runtime) / PENDING / CONDITIONAL.
- **"PROVEN (lab)"** means RouterOS CHR 7.24.5 + FreeRADIUS 3.2.8 + MariaDB 10.11 / MySQL 8.0 + PHP 8.2 only.

| Lock axis (§2) | Locked direction | Final WP8 state | Conformance |
|---|---|---|---|
| 1 Sessions / Session Authority | Option G behaviour | A1 permanent Option G, fail-closed (A1, Gate G); A2-R atomic reservation; Strict PENDING | **Conforms.** PROVEN (lab). Production PENDING: AV-1, A2-R SQL-exhaustion risk (unverified, reproduction halted) |
| 2 Stale sessions | stale ≠ offline; verified liveness | Admission by the session guard / slots, never by `radacct` row age (lost Stop not resent); A4 stale = report only | **Conforms** (lab). Production closure PENDING (P-A4-1…3) |
| 3 Simultaneous-Use | protocol-aware; simultaneous-first-login gate | PPPoE: 48 race trials, 0 doubles; cross-NAS 13 / 13. Hotspot: H1-A + H-2B slots. VPN: V-1 not proven | **Conforms for PPPoE / Hotspot** (lab); VPN PENDING |
| 4 Failure semantics | consequence-based UNKNOWN, never an unsafe grant | `UNKNOWN COA_NO_RESPONSE`, `RECONCILIATION_REQUIRED`, `REPROVISION_UNCONFIRMED`, Strict PENDING; S6 drills | **Conforms** (lab) |
| 5 CoA / Disconnect | KEEP the RFC 5176 client | `CoAClient` kept: same result mapping; A3 added `disconnectSession()` for one captured session. 406 / ACK / silence proven on 7.24.5 | **Conforms.** The wrong-source case is **[CHR-FG] 7.12.1 only** |
| 6 FUP / Quota | KEEP scope; Q1–Q4 gates; no advanced FUP | No FUP engine added; quotas harness / crafted packets only | **Conforms** (no expansion). Q1–Q4 PENDING |
| 7 Profiles / plan transitions | KEEP NEW / RENEW / CHANGE_PLAN / REACTIVATE | Kept; S5 lifecycle on the CHR | **Conforms.** P1–P3 PENDING |
| 8 NAS / MikroTik | KEEP integration + minimal capability + secret hardening | Integration kept; S7: Class A empty, default deny on every grant method, pre-write recharge refusal | **Conforms.** N6 / N7 PENDING (NAS secret plaintext, onboarding / rotation); OBS-S7-1 / -2 / OBS-W8-4 gate the legacy API device |
| 9 ISP isolation | **Option A**: one isolated instance per ISP, no shared-DB `tenant_id` | No `tenant_id`, no multi-tenant migration, no cross-instance component. The only new tables are FreeRADIUS-side admission tables (`nuxr_admission*`, `nuxr_hs_admission*`) inside one instance's RADIUS database | **Conforms** (design). ISO-01 not tested: PENDING |
| 10 Reliability / scale / HA | KEEP the modular monolith; restore, recovery, monitoring, load; HA conditional | No Redis, broker, queue / outbox, HA or extra FreeRADIUS nodes in product code (`dev/wp8/` is lab tooling) | **Conforms.** REL-03 PROVEN (lab drills); REL-02 partly; REL-01 / 04 / 05 PENDING; REL-06 CONDITIONAL |

| Lock section | Final WP8 state |
|---|---|
| §3 Option A deployment model | Unchanged by WP8 (see axis 9) |
| §4 Product scope not expanded | No new feature engine; PPPoE / Hotspot claimed only as proven (§13 of the integration model lists what is not covered) |
| §5 Release gates | As in §7.3. Every gate WP8 did not prove stays **PENDING**; none is closed by this report |
| §6 Carry-forwards | R-WP8-CARRY-01 (A5), -02 (A3), -03 (A4): **closed for development only**, not waived for production. **R-HF-CARRY-01: OPEN** (§5 #20) |
| §6 WP8 status | Lock text "WP8 PENDING; S7 / S8 pending" is now stale (C-17): S7 and S8 are development-accepted. The lock documents are not edited (manifest-pinned); updating their status line is the deferred reconciliation step |
| §7 Execution order | Steps 1–2 (WP8 and its carry-forwards) are done for development. Step 3 (WP9) has not started and needs the owner's go |

### 12.3 Files of the S8 commit

| File | Change |
|---|---|
| `owner-review/WP8/REPORT.md` | new (this report) |
| `owner-review/WP8/WP8_S8_FINAL_EVIDENCE_REGATE.md` | new (re-gate, warning fix, finalization) |
| `owner-review/WP8/OWNER_DECISIONS.md` | additive S8 record |
| `owner-review/WP8/evidence/s8-regate/` (13 files), `evidence/s8-warning-fix/` (14 files), `evidence/s8-final/` | new evidence |
| `docs/WP8_INTEGRATION_MODEL.md` | new |
| `docs/WP4_RADIUS_MODEL.md` | additive dated notes |
| `install/freeradius/README.md` | additive operator notes (D-S8-5) |
| `tests/Integration/Wp8/FailureDrillsTest.php` | the accepted test-only fix, byte-identical to the version that passed the full lab suite |
| `tests/Integration/Wp8/ConcurrentFirstLoginGateTest.php` | docblock comment only (D-S8-6) |

**Not changed:** `system/`, `init.php`, `ui/`, `install/` apart from the README, schema and migrations, `tests/phpunit.xml`,
`tests/bootstrap.php`, the ledger, the lock branch, and every protected branch.

### 12.4 Final pre-commit audit

Raw output: `evidence/s8-final/precommit-audit.out`.

| # | Check | Result |
|---|---|---|
| 1 | HEAD and origin | Both `b96747b5`; only the expected S8 files changed |
| 2 | Inventory | All modified, new and untracked files listed (§12.3); no unexpected path |
| 3 | Test autoloader = the tested version | `git diff` of `FailureDrillsTest.php` is byte-identical to `evidence/s8-warning-fix/FailureDrillsTest.diff`. The tree fingerprint before the docs edits equals the after-run fingerprint |
| 4 | No product code change | `git diff HEAD` over `system/`, `init.php`, `ui/` and `install/` (README excluded) is empty |
| 5 | `phpunit.xml` / `failOnWarning` | `tests/phpunit.xml` byte-identical to HEAD (`failOnWarning="true"` unchanged) |
| 6 | Accepted results | `03-wp8-integration-full.out`: `Tests: 101, Assertions: 1849, Skipped: 13`, exit 0, no warning or deprecation line. The 13 skips are mode-gated, not passes |
| 7 | SourceGuard, manifest, protected files, secret sweep | Unit source guards (`SourceGuardTest`, `RadiusSourceGuardTest`, `DriverClassificationSourceTest`): 50 tests, OK, run in a read-only container. Manifest 42 / 42. `Package.php` matches its pin (`d4567f66…2b85`); `Message.php`, `PEAR2/Autoload.php` and `init.php` are unchanged. Secret sweep over all 35 changed or new files: 7 credential values, 0 hits; an all-token variant also found 0 hits; no PCAP, key or disk image |
| 8 | Lab clean | No QEMU process, lab container, network, netns, `:18728` / `:18729` listener or `__pycache__` |
| 9 | Claims | The documents claim no production readiness, no SQL-exhaustion result and no 7.24.5 wrong-source result |
| 10 | Blocker register | §5 has all 31 rows, unchanged |

**No full lab re-run:** after the tested tree, only documentation and one non-executable docblock changed. That is the
owner's condition for not re-running the hour-long suite.

### 12.5 Final state

| Level | Assessment |
|---|---|
| Development | **`WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS`** (owner decision of this round) |
| Integration tests | Verified for the tested lab scope (§11); gaps: CoA wrong-source on 7.24.5, the untested areas of §7.1 item 6 |
| Operational readiness | **Not ready** |
| Production | **`PRODUCTION_NOT_AUTHORIZED`**. The §5 blockers are all open; the lock release gates are PENDING; WP9 and WP10 have not started |


---

## 13. Post-acceptance metadata closure (2026-10-15, added)

Owner verdict **`WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS`** / **`PRODUCTION_NOT_AUTHORIZED`** on the accepted
implementation SHA `44ea45d48fa5212617cb879958b50c6baa32635b`. This section updates two items of §12.1; that text stays as
recorded.

- **D-S8-7:** the ledger row is now recorded on `wp8/integration-poc` as `ACCEPTED_WITH_NOTES` at `44ea45d4` (development
  only). §5 (31 rows), WP9, WP10 and the lock release gates stay open. The ledger on `feat` is not changed.
- **D-S8-6:** the stale `tests/phpunit.xml` comments (`:111`, `:116-118`) are corrected, comment-only, with the A2 failure
  history kept. Execution settings are unchanged: the canonical XML without comments is identical, and the PHPUnit listing
  is identical (`evidence/s8-metadata/`).
- No code, test logic, suite, `failOnWarning` or production-blocker change, so no lab run.
