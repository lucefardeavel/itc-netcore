# WP8_WP9_UNIFIED_CLOSURE_AND_RELEASE_ROADMAP

**Date:** 2026-10-16.

**Owner decision:** `WP9_U0_DEVELOPMENT_ACCEPTED_WITH_NOTES`. This round is documentation, audit and planning only: no product code changed, no test was run, nothing was pushed or merged.

**Branch:** `wp9/red-team-rc` (local only).

**Verified HEAD before this round:** `30ceaa09ad34cf4b6ba052d9d06dd54b3b72e0b1` (clean tree). The WP9 base is `704031c6` (the WP8 metadata closure, equal to remote `feat` and `wp8`).

**Status:** `PRODUCTION_NOT_AUTHORIZED`. **WP9 is not accepted, WP8 stays at its recorded development acceptance with blockers, and WP10 has not started.**

Classification keys used throughout:

| Key | Meaning |
|---|---|
| **CLOSED_WITH_EVIDENCE** | closed by test or record evidence, within the scope stated |
| **DEV_ACCEPTED_WITH_NOTES** | owner development acceptance; notes and production items remain |
| **OPEN_SECURITY** | still open: a security issue |
| **OPEN_OPERATIONAL** | still open: an operational, runbook or deployment item |
| **NOT_TESTED** | no evidence yet |
| **SUPERSEDED** | superseded, with evidence |
| **OWNER_DECISION** | requires an owner decision |

Evidence levels:
- **E1:** code read.
- **E4:** test-proven.
- **R:** owner record.

Phase labels (from the Architecture Lock roadmap and WP9 `PLAN.md` §11):
- WP9-A … WP9-E: the WP9 waves.
- WP10: release, operations and restore.
- OPS: operator procedure.
- ARCH: architecture / schema approval.
- CHR: isolated RouterOS CHR lab.

---

## 1. U0 Acceptance Record

| Item | Record |
|---|---|
| Decision | `WP9_U0_DEVELOPMENT_ACCEPTED_WITH_NOTES`, owner, 2026-10-16, after the independent review of `WP9_U0_INDEPENDENT_OWNER_REVIEW_PACK_v3` (code, tests, patches) |
| Accepted HEAD | `30ceaa09ad34cf4b6ba052d9d06dd54b3b72e0b1` (`wp9/red-team-rc`, local). The last product change is `ec9d695f`; `30ceaa09` is documents and evidence |
| Scope | the shared identity lock (U0) and its closure rounds: A1, U0-0…U0-5, final hardening (FH), final closure (FC), R-FC-4, R-FC-4E. 24 commits `c9469f9e`…`30ceaa09` (after the plan commit `143c4cd1`); product +864 / −47 in 14 files; one owner-approved pin exception (`Package.php`, N-07, `d4567f66…` → `9759fca7…`) |
| Last full harness | `ec9d695f`: 1 368 tests per engine, 0 failures / errors / warnings, exit 0. Assertions: MariaDB 70 900, MySQL 70 897 (a run-dependent count, not an engine difference). 104 incomplete (WP8 real-NAS lab; not passes). Variant registry matches. Manifest 42/42; pins 23/23 |
| Closed within the demonstrated **application** scope (CLOSED_WITH_EVIDENCE, E4, both engines) | **V1** (voucher-only code over a customer identity); **V2** (customer created over a voucher identity); **V4** (security effect: one grant per RADIUS identity); 22 identity races (RR + RC); **N-14** (creation paths without canonical uniqueness); multi-owner policy writes refused (D-U0-R1 targeted protection); **O-U0-1** / grant confirmation (no GRANTED without this grant's verified write); open-transaction ownership guard; **N-07b** voucher credential exposure in logs / audit / notifications / login gate; **R-FC-4** (reviewed / unredeemed voucher served from its recharge row); **R-FC-4E** (canonical-identity guard); **D-FH-1** (fail-closed rename precedence, approved) |
| **Not** closed by U0 | **OBS-1 overall** (existing duplicate data, external writers: plugins, other DB accounts, direct repository use); **CHR / real-NAS tests remain incomplete** (104); **A2-R** stays `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`; **operational recovery** (CLAIMED_REVIEW, PENDING, REVERSAL_PENDING, REPROVISION_UNCONFIRMED) has no tool; U0 residuals R-FC-1…3, R-FC-4a…d, D-U0-R2, D-U0-R3 |
| Ledger | `ACCEPTANCE_LEDGER.md`: the WP9 row stays **PENDING** and gains a dated note recording the U0 sub-acceptance. No WP is marked accepted |
| Production | **Not authorized** |

---

## 2. WP8 S8 Decision Reconciliation (D-S8-1 … D-S8-9)

Sources: `owner-review/WP8/WP8_S8_FINAL_EVIDENCE_REGATE.md`, `owner-review/WP8/REPORT.md` §10–§13, `owner-review/WP8/OWNER_DECISIONS.md`, and the WP9 records.

| Decision | WP8 outcome | What WP9 did afterwards | Status now |
|---|---|---|---|
| **D-S8-1:** close OD-W8-16 for development only | closed 2026-10-14 for development; AV-1, the A2-R SQL-exhaustion risk and production requirements stay in the WP8 register | nothing (correctly; production items stay open) | **DEV_ACCEPTED_WITH_NOTES** (development); production parts stay in §3 rows W8-2, W8-3, W8-4 |
| **D-S8-2:** final lab re-gate | done: `wp8-security-gates` 10/216 exit 0; full `wp8-integration` 101/1849 exit 0 | not re-run on the WP9 tree (no CHR) | **CLOSED_WITH_EVIDENCE** for `44ea45d4`. **NOT_TESTED** on the WP9 tree: WP9 changed `system/devices/Radius.php` and `radius.php`, so a CHR regression is due before WP9 acceptance (§5, plan item W9-E2) |
| **D-S8-3:** `Message.php` warnings, test-only fix | done (S8 §11); `Message.php` unchanged | `Message.php` still unchanged; `Package.php` notifications changed under N-07 | **CLOSED_WITH_EVIDENCE** |
| **D-S8-4:** Architecture Lock conformance | documented (WP8 `REPORT.md` §12.2). Not done: landing the lock (`8b0ee336`, local, not an ancestor), the dated addendum, the C-04 severity reconciliation | nothing | **OWNER_DECISION**: land / merge the lock documents; C-04 severity |
| **D-S8-5:** operator README (CoA source rule, port 1700) | done (`install/freeradius/README.md`) | nothing | **CLOSED_WITH_EVIDENCE** (documentation). CoA wrong-source on RouterOS 7.24.5 is **NOT_TESTED** (W8-X1) |
| **D-S8-6:** stale "EXPECTED TO FAIL" comments | the test docblock was corrected; `tests/phpunit.xml:111,116-118` not edited (needs explicit approval) | not edited | **OWNER_DECISION**: a comment-only `phpunit.xml` change (proposed text in WP8 `REPORT.md` §12.1) |
| **D-S8-7:** WP8 formal acceptance | development acceptance with blockers; ledger row WP8 `ACCEPTED_WITH_NOTES` at `44ea45d4` | WP9 is based on it | **DEV_ACCEPTED_WITH_NOTES**; production not authorized |
| **D-S8-8:** keep the WP8 §5 phase assignment | kept | this document re-classifies each row (§3); no phase is treated as a closure | **CLOSED_WITH_EVIDENCE** (process); superseded for status by §3 here |
| **D-S8-9:** no claim of the "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" | not applied (text unavailable) | still unavailable; never claimed in WP9 | **OWNER_DECISION**: provide the text if it is to be applied at WP9 / WP10 acceptance |

---

## 3. Unified Open Findings Matrix

Priority:
- **P0:** production security blocker.
- **P1:** production blocker (operational or contract).
- **P2:** should close before launch.
- **P3:** low / record.

"Needs" lists the kind of work still required:
- **Code:** a product change;
- **Test:** harness evidence;
- **CHR:** isolated RouterOS lab;
- **ARCH:** architecture / schema approval;
- **OPS:** operator procedure;
- **WP10:** release / operations work package;
- **DEC:** owner decision.

### 3.1 The WP8 production blocker register (all 31 rows) reconciled with WP9

| # | ID / item | WP9 effect (evidence) | Status | Needs | Pri | Depends on |
|---|---|---|---|---|---|---|
| W8-1a | **OBS-1 / L-A5-2: application writers** | U0 + FH + FC + R-FC-4/4E. Every application identity acquirer is serialized and canonical; multi-owner writes are refused; voucher service decided on the canonical identity (E4, both engines, RR + RC) | **CLOSED_WITH_EVIDENCE** (application scope) | — | — | — |
| W8-1b | **OBS-1: existing duplicate data** | a read-only survey exists (`dev/wp9/identity_survey.sql`, tested on synthetic data only). Multi-owner keys are refused at write time, so their owners can lose service until resolved | **OPEN_SECURITY** | OPS (survey on an approved copy + resolution plan), DEC, ARCH (U3 / U4 uniqueness, R0008) | **P0** | an approved data copy; DEC on the resolution method |
| W8-1c | **OBS-1: external writers** (plugins, other DB accounts, direct `RadiusRepository`) | D-U0-R2 read-only review (`U0_HARDENING_REVIEWS.md`): bypass confirmed by design; detection and refusal at write time partly mitigate it | **OPEN_SECURITY** | DEC (OD-W9-9 plugin boundary), ARCH (U3 / U4 DB enforcement), WP10 (least-privilege DB accounts) | **P0** | OD-W9-9 |
| W8-2 | **A2-R SQL exhaustion** (N-13) | T0 static characterisation only; the hardening design is in PLAN §6.5; reproduction forbidden and never attempted | **OPEN_SECURITY** (`UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`) | DEC (OD-W9-2 implementation), Code (FreeRADIUS site / SQL), CHR re-gate | **P0** | OD-W9-2; CHR approval |
| W8-3 | **AV-1:** FreeRADIUS stall leaves an orphan PENDING | none | **OPEN_OPERATIONAL** | WP10 (runbook, monitoring) | P1 | W8-4 |
| W8-4 | **PENDING recovery:** no safety-gated release procedure | none | **OPEN_OPERATIONAL** | DEC, Code or OPS (gated release tool), WP10 | P1 | DEC |
| W8-5 | **AV-2:** capacity sizing | none | **NOT_TESTED** | WP10 (load), Test | P1 | staging environment |
| W8-6 | **AV-3:** release audit operator identity is free text | none | **OPEN_SECURITY** | Code (WP9-D), Test | P2 | — |
| W8-7 | **H-2 follow-ups** (impact queries, NAS contract, sizing, rollout order) | none | **OPEN_OPERATIONAL** | WP10 preflight / rollout | P1 | W8-5 |
| W8-8 | **MAC-H1A:** lower-case MAC refused | none | **OPEN_OPERATIONAL** | WP10 preflight (impact queries) | P2 (MAC-auth only) | — |
| W8-9 | **V-1:** VPN username equivalence | not proven | **NOT_TESTED** | CHR, DEC (VPN feature) | P2 (VPN only) | CHR approval |
| W8-10 | **P-A4-1:** SQL session zone UTC | none | **OPEN_OPERATIONAL** | WP10 preflight | P1 | — |
| W8-11 | **P-A4-2:** NTP; Hotspot `Event-Timestamp` | none | **OPEN_OPERATIONAL** | WP10 preflight, CHR (Hotspot capture) | P1 | — |
| W8-12 | **P-A4-3:** historic `radacct` zone survey | none | **OPEN_OPERATIONAL** | WP10, OPS | P1 (existing installations) | — |
| W8-13 | L-A4-1…5 | documented | **OPEN_OPERATIONAL** (documented residuals) | WP10 | P3 | W8-10 … W8-12 |
| W8-14 | **Same-key ADMIN_SYNC** (two Radius plans on one key) | U0 does not address it (one owner, not multi-owner) | **OPEN_OPERATIONAL** (integrity / availability) | Code (WP9-B) or DEC (forbid the configuration), Test | P1 (if configured) | DEC |
| W8-15 | **RadiusRest / L-4** (rlm_rest mode) | WP9 hardened `radius.php` E15 / E16 for voucher state (R-FC-4 / 4E, E4 harness incl. the WP4 REST contract) | **NOT_TESTED** with a real FreeRADIUS `rlm_rest` deployment | DEC (support the mode or not), CHR / staging test | P2 (rlm_rest only) | DEC |
| W8-16 | L-1, L-2, L-3, L-5 (rename windows, lost Start, snapshot, Strict Mode slots) | D-FH-1 makes multi-owner renames fail closed (E4) | **OPEN_OPERATIONAL** (accepted residuals) | WP10 runbook | P3 | — |
| W8-17 | **R2 audit / UI:** unassigned legacy rename has no `old_session` audit | none | **OPEN_OPERATIONAL** | Code (WP9-B), LOW | P3 | — |
| W8-18 | **READ COMMITTED interleaving** (A2-R DELETE-count guard) | U0 races proven under RC (E4) but **not** the A2-R admission statements | **OPEN_OPERATIONAL** | WP10 deployment check ([CONTRACT] keep REPEATABLE READ) | P1 | — |
| W8-19 | **R-4 / N-04:** cron vs RENEW race | none (PLAN matrix #18 planned) | **NOT_TESTED** | Test (WP9-B), then Code if confirmed | **P1** | — |
| W8-20 | **R-HF-CARRY-01 / N-12:** autoload router / plan metadata exposure | none | **OPEN_SECURITY** (MEDIUM) | Test (matrix #19), Code (WP9-B) | **P0** | — |
| W8-21 | **Phase B: plugin / direct-device / direct-DB authority** (N-01, N-02) | D-U0-R2 review; the shared device guard now also refuses multi-owner keys and voucher-only rows not granted; `Radius::customerAddPlan` gets the ownership check but still **no** reseller-owned refusal (N-01) | **OPEN_SECURITY** | DEC (OD-W9-9), Code (WP9-B guards), Test, CHR (device paths) | **P0** | OD-W9-9 |
| W8-22 | **OBS-S7-1:** drivers ignore RouterOS `!trap` | none | **OPEN_SECURITY** (legacy MikroTik-API only) | Code, CHR | P1 (before enabling legacy MikroTik devices) | CHR approval |
| W8-23 | **OBS-S7-2:** VPN `rmNat()` by address | none | **OPEN_SECURITY** (legacy VPN) | Code, CHR | P1 (legacy VPN) | CHR approval |
| W8-24 | **OBS-S7-4:** whitespace-normalised keys outside the device-guard comparison | U0 canonical checks run after the WP8 guard (ownership, voucher state), but the WP8 reseller-owned comparison itself is unchanged | **NOT_TESTED** (re-test after U0) | Test (WP9-C) | P2 | — |
| W8-25 | **L-S7-FC-1:** "billed, sync manually" after a device failure | voucher-only grants now go to CLAIMED_REVIEW instead of GRANTED (O-U0-1); customer path unchanged | **OPEN_OPERATIONAL** | WP10 runbook, OPS | P2 | recovery tooling (W9-OPS) |
| W8-26 | **OBS-W8-4:** RouterOS API without TLS / timeout | none | **OPEN_SECURITY** | DEC (API-SSL, WP9) or [CONTRACT] private path only | P1 (management path) | DEC |
| W8-27 | OBS-W8-2 / -3 / -5 (legacy driver reliability) | none | **OPEN_OPERATIONAL** (LOW) | Code (optional) | P3 | — |
| W8-28 | OD-W8-16 record gap | D-S8-1 recorded | **CLOSED_WITH_EVIDENCE** (R) | — | — | — |
| W8-29 | **Lock gates:**<br>• Q1–Q4 quotas; P1 CHANGE_PLAN after UNKNOWN Disconnect; P2 RENEW Session-Timeout; P3 R-15 bandwidth terms;<br>• N6 secrets; N7 NAS onboarding / rotation;<br>• REL-01…05; ISO-01 | none of them advanced by U0 | **Q1–Q4:** NOT_TESTED (CHR).<br>**P1:** NOT_TESTED + OWNER_DECISION.<br>**P2 / P3:** OWNER_DECISION (product).<br>**N6 / N7:** OPEN_SECURITY.<br>**REL-01…05:** OPEN_OPERATIONAL (WP10; REL-03 lab-proven, REL-02 one path).<br>**ISO-01:** NOT_TESTED | CHR, DEC, Code, WP10 | **P0** (N6), P1 (others) | see §6 |
| W8-30 | Test-evidence hygiene | D-S8-2 / -3 closed; the stale `phpunit.xml` comments stay; OBS-W8-6 PEAR2 deprecation | **D-S8-2 / -3:** CLOSED_WITH_EVIDENCE.<br>**Comments:** OWNER_DECISION.<br>**OBS-W8-6:** OPEN_OPERATIONAL (P3) | DEC | P3 | — |
| W8-31 | Minor items (OBS-W8-9, missing NAS pool, OBS-W8-7, A3 F1 / F5 / R-6, A5 text, TI-02, N-1 / N-2) | none | **OPEN_OPERATIONAL** (LOW / INFO) | WP9 / WP10 hygiene | P3 | — |

### 3.2 WP9-originated findings (PLAN §4 N-01…N-17, T0 observations, inherited WP0–WP7 bindings)

| ID | Item | Status | Needs | Pri | Notes |
|---|---|---|---|---|---|
| N-01 | public device-grant methods without guard | **OPEN_SECURITY** (partly mitigated: ownership and voucher checks) | Code (WP9-B), Test, CHR | P0 | with W8-21 |
| N-02 | plugins load before every gate; errors swallowed; `run_hook` first-only (R2-3, E4) | **OPEN_SECURITY** | DEC (OD-W9-9) | P0 | with W8-21 |
| N-03 | customer login cookie (loose compare, future time, `?uid=`, no flags) | **OPEN_SECURITY** | Code (with NEW-7), Test | **P0** | matrix #16 |
| N-04 | = W8-19 R-4 | NOT_TESTED | Test → Code | P1 | — |
| N-05 / P1 | replay without re-authorization | **OWNER_DECISION** (OD-W9-6 P1) | DEC → Code, Test | P2 | — |
| N-06 | CLI reconcile `--stale-seconds=0` / `resume` | **OPEN_OPERATIONAL** | Code (WP9-B) or OPS | P2 | operator safety |
| **N-07a** | **legacy CSV exports without escaping; `csvCell` misses leading whitespace** (O-T0-4; T0+ 4 DEFECT_REPRODUCED, E4) | **OPEN_SECURITY** | Code (WP9-D), Test | P1 | **not** addressed by U0 |
| **N-07b** | voucher credential in logs / audit / notifications / login gate | **CLOSED_WITH_EVIDENCE** (application scope; residuals R-FC-1…3) | DEC (R-FC-1 masking) | — | U0 |
| N-08 / **WP1-CHECK** | CHECK constraints compared by name; readiness ignores constraints | **OPEN_SECURITY** — the ledger forbids risk-accepting it; it must close before WP9 acceptance | Code (pinned SchemaInspector / Runner → re-pin approval), Test | **P0** (ledger-binding) | DEC on the re-pin |
| N-09 | reservations never expire / no alert | **OPEN_OPERATIONAL** | WP10 alerting | P2 | R-021 |
| N-10 | API returns all `$ui` assigns | **NOT_TESTED** | Test (WP9-C) | P2 | — |
| N-11 + O-T0-1 | secrets rendered in UI (router password, settings `smtp_pass`, `tawkto`, `github_token`), radpostauth passwords; `secretValues` gaps | **OPEN_SECURITY** | Code (WP9-D), Test | P1 | — |
| N-12 | = W8-20 | OPEN_SECURITY | — | P0 | — |
| N-13 | = W8-2 A2-R | OPEN_SECURITY | — | P0 | — |
| N-14 | creation paths without uniqueness | **CLOSED_WITH_EVIDENCE** (U0 P1 / P2 / P3, E4) | — | — | — |
| N-15 / P2–P4 | Agent re-enable / password reset / Sales re-assignment | **OWNER_DECISION** (OD-W9-6) | DEC → Code, Test | P2 | — |
| N-15 / P5 | activation history after an ownership transfer | **OWNER_DECISION** | DEC | P3 | — |
| N-16 | stock checkrad fails open | record only (INFO) | — | P3 | Option G is the defence |
| N-17 | README claims pool sizing removes exhaustion / "never Accept" | **OPEN_SECURITY** (unproven safety claim shipped to operators) | Documentation fix (approval) | P1 | with A2-R |
| NEW-6 | payment-link token ~59 days | **OPEN_SECURITY** | Code (with NEW-7) | P2 | — |
| **NEW-7** (+ O-T0-3 OTP files) | `db_pass` as HMAC / key material | **OPEN_SECURITY** (ledger: close before WP9 acceptance; OD-W9-7: remediate before production) | DEC (option), Code, Test; WP10 KEY-SEP | **P0** | — |
| **NEW-8** | Smarty without a security policy | **OPEN_SECURITY** (same binding) | DEC (O8-A scoped policy), Code, Test | **P0** | — |
| O-T0-2 | installer seeds the RADIUS DB connection with the app credentials | **OPEN_OPERATIONAL** | WP10 KEY-SEP / LPDB | P2 | — |
| TAMPER / LPDB | ledger / audit immutability is application-only; DB grants unverified | **NOT_TESTED** (privilege evidence) | Test (WP9-D), WP10 grants | P1 | — |
| WP2-PERM, IDEMP / FCAP / SQLBYP / ACT-RT, RAD-RT, SEC-01…25 regression, PWA (#15) | red-team matrix rows | **NOT_TESTED** as red-team (baselines pass in the harness) | Test (WP9-C) | P1 | — |
| THRU / PERF / IDX | stress, index scans (D-U0-R3 observational figures only) | **NOT_TESTED** | WP10 load | P1 | — |
| HARNESS | `CliOnlyTest` counter flake; AppServer port reuse; run-dependent assertion counts (R-FC-8 / 9) | **OPEN_OPERATIONAL** (test infrastructure) | DEC (test-only fixes) | P3 | — |

### 3.3 U0 residuals carried forward

| ID | Item | Status | Needs | Pri |
|---|---|---|---|---|
| D-U0-R2 | external writers bypass application controls | = W8-1c / W8-21 | — | P0 |
| D-U0-R3 | global identity lock, unindexed scans, 1 000-row bounds, 2 s budgets; fail-closed refusals of legitimate legacy names | **OPEN_OPERATIONAL** | WP10 load; ARCH (indexes / per-key locks) | P1 |
| W9-OPS | **operator recovery:** CLAIMED_REVIEW (invoice + recharge left), REVERSAL_PENDING, REPROVISION_UNCONFIRMED, HELD, PENDING (W8-4), OLD_SESSION_UNCONFIRMED; no tool | **OPEN_OPERATIONAL** | DEC (tool or runbook), Code (if tool), Test | **P1** |
| R-FC-1 | voucher codes in financial / compatibility fields (SuperAdmin / Admin and API) | **OWNER_DECISION** (masking or design change) | DEC | P2 |
| R-FC-2 / R-FC-3 | pinned `Package.php` gate log; `deviceGrant` record (grandfathered only) | **OPEN_SECURITY** (LOW) | DEC (pin exception) | P3 |
| R-FC-4a | `rlm_sql` serves rows of an uncertain commit during review | **OPEN_OPERATIONAL** | OPS (W9-OPS) | P2 |
| R-FC-4b…d, R-FC-5…7 | operator edits; E16 contract; legacy deleted-voucher recharges; customer-path vouchers; plugin in-transaction refusal; count → write window | documented / accepted | — | P3 |

---

## 4. Security and Operational Blockers

A launch needs **every row below** closed with evidence, or explicitly risk-accepted by the owner where the ledger allows it. WP1-CHECK may **not** be risk-accepted.

### 4.1 Security blockers (P0)

| # | Blocker | Why it blocks | Closure route |
|---|---|---|---|
| S-1 | **A2-R** SQL-exhaustion admission risk (W8-2 / N-13) | possible admission bypass under SQL failure; unverified | OD-W9-2 → hardening (PLAN §6.5) → CHR re-gate; owner reclassification only by explicit decision |
| S-2 | **OBS-1 existing data + external writers** (W8-1b / 1c) | identity takeover / denial of service from data U0 cannot see | survey on an approved copy → operator resolution → U3 / U4 decision → least-privilege accounts |
| S-3 | **Phase B authority** (W8-21, N-01, N-02) | code inside the app can bypass authority | OD-W9-9 → guards (WP9-B) → red team (WP9-C) → CHR for device paths |
| S-4 | **R-HF-CARRY-01 / N-12** | information exposure to Agent / Sales | characterise (matrix #19) → fix (WP9-B) |
| S-5 | **WP1-CHECK / N-08** | ledger-binding; constraint drift undetected | re-pin approval → fix → test (WP9-D) |
| S-6 | **NEW-7** (+ N-03, NEW-6, O-T0-3) | one database password signs cookies / OTP / links | option decision → key separation (WP9-D) → WP10 key provisioning |
| S-7 | **NEW-8** | template injection surface | O8-A scoped Smarty policy (WP9-D) |
| S-8 | **N6 secrets / NAS secret plaintext** (lock gate) | NAS and verifier secrets at rest | AD-18 boundary decision → WP10 |

### 4.2 Production blockers: operational / contract (P1)

| # | Blocker |
|---|---|
| O-1 | operator recovery tooling or runbook (W9-OPS, W8-4, AV-1) |
| O-2 | accounting time contract: P-A4-1…3 (UTC session zone, NTP, historic `radacct`) |
| O-3 | capacity (AV-2 / REL-04), load (THRU / PERF / IDX), D-U0-R3 |
| O-4 | restore drill and runbook (REL-01), backup reconciliation (REL-02), alerting (REL-05, N-09) |
| O-5 | deployment contract: keep REPEATABLE READ (W8-18); H-2 rollout order; MAC-H1A preflight |
| O-6 | R-4 cron / RENEW race (W8-19): test, then fix if confirmed |
| O-7 | CHR regression of the WP8 security gates on the final WP9 tree (WP9 changed `Radius.php` / `radius.php`; D-S8-2 evidence is for `44ea45d4`) |
| O-8 | N-07a CSV injection, N-11 secrets in UI, N-17 README claim, AV-3, TAMPER / LPDB evidence |
| O-9 | conditional on the deployed feature set: legacy MikroTik-API drivers (OBS-S7-1 / -2, OBS-W8-4), VPN (V-1), rlm_rest (W8-15), same-key plans (W8-14), MAC auth (W8-8) — either fixed and tested, or the feature disabled by contract |

---

## 5. Remaining WP9 Execution Plan

Each wave keeps the WP9 method: plan → owner approval → test-first → minimal fix → focused → full harness → review. Already closed work is **not** repeated (§1, rows marked CLOSED_WITH_EVIDENCE).

| Step | Content | Gate / approval needed | Depends on | Est. effort |
|---|---|---|---|---|
| **W9-0** | **Owner decision batch:**<br>• OD-W9-2 (A2-R implementation);<br>• OD-W9-6 (P1–P5 policies);<br>• OD-W9-7 / OD-W9-8 options (NEW-7 / NEW-8);<br>• OD-W9-9 (plugin boundary);<br>• OD-W9-10 (CHR execution);<br>• re-pin approval for WP1-CHECK (SchemaInspector / Runner);<br>• D-S8-4 (lock landing, C-04);<br>• D-S8-6 (`phpunit.xml` comment);<br>• W8-14 / W8-15 / V-1 / legacy-driver feature scope;<br>• R-FC-1;<br>• W9-OPS tool vs runbook;<br>• D-S8-9 protocol text | owner | — | decision only |
| **W9-A2R** | A2-R hardening per OD-W9-2 (PLAN §6.5): CHECK / echo validation of A2-R statements, fail-closed on empty expansion. **No** exhaustion reproduction; static and functional tests only | OD-W9-2 | W9-0 | medium |
| **W9-B** | **Authority and races:**<br>• N-01 / N-02 guards per OD-W9-9 (`customerAddPlan` reseller-owned refusal, MikroTik public helpers);<br>• R-4 characterisation (matrix #18), then the fix;<br>• N-05 / N-15 per policies;<br>• N-06 CLI guard;<br>• R-HF-CARRY-01 / N-12;<br>• W8-14 same-key decision;<br>• R2 audit (W8-17);<br>• legacy driver decisions (OBS-S7-1 / -2) | per-defect approvals | W9-0 | large |
| **W9-C** | **Red-team matrix** (PLAN §12) rows not yet executed: #1–#13, #15, #16, #19, #21, #23, #24 (WP2-PERM, IDEMP, ACT-RT, RAD-RT, SEC-01…25 regression, PWA cross-user), OBS-S7-4 re-test, N-10 | test-stage approvals | W9-B for re-tests of fixed items | large |
| **W9-D** | **Integrity and secrets:**<br>• WP1-CHECK + N-08 (re-pin);<br>• NEW-7 (+ N-03, NEW-6, O-T0-3);<br>• NEW-8;<br>• TAMPER / LPDB privilege evidence;<br>• AV-3;<br>• N-07a CSV;<br>• N-11 + O-T0-1;<br>• N-17 README | option decisions; re-pin approval | W9-0 | large |
| **W9-OPS** | operator recovery: a safety-gated tool or a runbook for CLAIMED_REVIEW (incl. R-FC-4a), PENDING (W8-4 / AV-1), REVERSAL_PENDING, REPROVISION_UNCONFIRMED, HELD, OLD_SESSION_UNCONFIRMED; audited, idempotent, no automatic cleanup | owner decision (tool vs runbook; if tool: code approval) | W9-0 | medium |
| **W9-E** | **Close-out:**<br>• W9-E1: full dual-engine harness on the frozen final tree;<br>• W9-E2: CHR regression (`wp8-security-gates`, `wp8-integration` real-NAS classes) on the final tree — WP9 changed device / REST code;<br>• secret sweep; manifest; pins;<br>• `REPORT.md` evidence packet;<br>• WP10 hand-over list;<br>• owner WP9 acceptance with **zero blockers in WP9 scope** (WP10 prerequisite) | OD-W9-10 (CHR execution) | W9-A2R, W9-B, W9-C, W9-D, W9-OPS | medium |

**Not in WP9:** capacity / load / long-duration (OD-W9-11 → WP10), deployment, production data cleanup.

---

## 6. WP10 Readiness Requirements

**Prerequisite** (`docs/claude-development-pack/prompts/WP10_RELEASE_HANDOFF.md`): **WP9 owner-accepted with zero blockers.** The execution base is the accepted WP9 HEAD.

| Area | Requirement | Inputs from WP8 / WP9 |
|---|---|---|
| Restore | a staging backup and an **actual restore drill**; backup / restore runbook (reseller-aware; legacy JSON restore stays disabled) | REL-01, REL-02, WP2 note |
| Release | version / commit inventory and ancestry; install / upgrade guide; migration ordering; rollback plan and RC rollback verification | MIG-ORDER, ENGINES (MariaDB 10.11 / MySQL 8.0 / PHP 8.2) |
| Secrets / keys | environment and secrets guide; key separation (NEW-7 outcome); `api_secret` deployment; NAS / verifier secret provisioning and rotation (N6 / N7, AD-18); `$radius_rest_secret` | S-6, S-8, O-T0-2 |
| DB access | least-privilege runtime and FreeRADIUS accounts (LPDB): no UPDATE / DELETE on ledger / audit; FreeRADIUS read-only on policy tables | TAMPER, W8-1c, D-U0-R2 |
| Preflight | SQL session zone UTC, NTP, historic `radacct` survey (P-A4-1…3); MAC-H1A impact queries; REPEATABLE READ check; PRE-WP4-DATA; **OBS-1 identity survey + resolution** | O-2, O-5, S-2 |
| Operations | runbooks: AV-1, PENDING, H-2 rollout, L-1/2/3/5, L-S7-FC-1, OPS-HELD, STALE-SCHED, cron packaging, W9-OPS recovery | O-1 |
| Telemetry | minimum telemetry (lock Phase D / AD-21): verifier latency / result, NAS verifier health, reject-reason families, accounting freshness / zone anomalies, CoA outcomes, correlation ids; alerting (REL-05, N-09, R-021) | O-4 |
| Capacity | load, capacity and long-duration tests (AV-2 / REL-04, THRU / PERF / IDX, D-U0-R3) on staging | O-3 |
| Network contract | RouterOS firmware / service capability contract; RADIUS timeout / dependency settings; CoA source / port rule (D-S8-5); private management path or API-SSL (OBS-W8-4) | W8-26, W8-X1 |
| Legal | license metadata / notices report; distribution obligations only as far as verified | WP0 note |
| Docs | operator / admin guide; known limitations (all OPEN rows accepted by the owner); owner acceptance checklist; `owner-review/WP10/REPORT.md` | §3 |

---

## 7. Critical Path to Commercial Launch

```
[1] W9-0 owner decision batch
      │
      ├──► [2a] W9-A2R (A2-R hardening) ─────────────┐
      ├──► [2b] W9-B (authority, R-4, R-HF, policies) ┤
      ├──► [2c] W9-D (WP1-CHECK, NEW-7, NEW-8, …)     ├──► [3] W9-C red team (incl. re-tests of [2]) ──┐
      └──► [2d] W9-OPS (recovery tool / runbook) ─────┘                                                │
                                                                                                        ▼
                                              [4] W9-E: full harness + CHR regression on the final tree
                                                                                                        │
                                                                                                        ▼
                                              [5] Owner: WP9 ACCEPTED (zero WP9-scope blockers) ── WP10 prerequisite
                                                                                                        │
          ┌─────────────────────────────────────────────────────────────────────────────────────────────┤
          ▼                                   ▼                                    ▼                     ▼
 [6a] restore drill + runbooks   [6b] LPDB grants + key / secret        [6c] staging capacity / load   [6d] OBS-1 survey on an
      (REL-01 / 02, recovery)         provisioning (NEW-7, N6 / N7)          + telemetry / alerting         approved copy + operator
                                                                             (AV-2, REL-04 / 05)            resolution (+ U3 / U4 if approved)
          └───────────────┬───────────────────┴────────────────────┬───────────────┘                     │
                          ▼                                         ▼                                     │
              [7] preflight on the target (P-A4, REPEATABLE READ, MAC-H1A, H-2 rollout order, network contract) ◄┘
                          │
                          ▼
              [8] WP10 owner acceptance (release candidate + handover)
                          │
                          ▼
              [9] staged pilot on a real (non-lab) NAS with explicit owner deployment authorization
                          │
                          ▼
              [10] Commercial launch (owner production authorization)
```

The longest dependency chain is **[1] → [2b] / [2c] → [3] → [4] → [5] → [6c] → [7] → [8] → [9] → [10]**.
- The red team [3] waits on the fixes it must re-test.
- WP10 cannot start before [5].
- Staging load [6c] needs the final code.

Items that can run **in parallel without code**, once the owner approves them:
- the OBS-1 survey on an approved copy, as early as now ([6d] read-only part);
- D-S8-4 lock landing;
- D-S8-6;
- the WP10 legal / notices report;
- runbook drafting for items that are already stable (AV-1, L-1/2/3/5, P-A4 preflight queries).

Never on the critical path:
- re-running accepted U0 tests whose conditions have not changed;
- re-opening U0 without new evidence of a real defect;
- any SQL-exhaustion reproduction.

---

## 8. Records changed by this round

- `docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md`: the WP9 row stays `PENDING`; a dated note records the U0 sub-acceptance. This file is not in the pack manifest (manifest stays 42/42).
- `owner-review/WP9/REPORT.md`: §U0-ACC acceptance record.
- `owner-review/WP9/PLAN.md`: §18 decision row and status note.
- `owner-review/WP9/U0_PROGRESS.md`: acceptance line.
- This file.

No product code, test, schema, pin or WP8 record was changed. No push, merge or deployment.

`STOPPED_WAITING_OWNER_REVIEW`
