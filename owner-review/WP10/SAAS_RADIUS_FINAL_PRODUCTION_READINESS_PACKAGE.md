# SAAS_RADIUS_FINAL_PRODUCTION_READINESS_PACKAGE

**Owner authorization:** `WP8_WP9_WP10_AUTONOMOUS_EXECUTION_AUTHORIZED` (2026-10-16).
**Plan executed:** `owner-review/WP9/WP8_WP9_UNIFIED_CLOSURE_AND_RELEASE_ROADMAP.md`, with the W9-0 decisions in `owner-review/WP9/AUTONOMOUS_EXECUTION_PLAN.md`.
**Handoff ledger:** `owner-review/WP9/AUTONOMOUS_PROGRESS.md`.
**Date:** 2026-10-16.

Scope of the execution:
- development, disposable test databases and the isolated CHR lab only;
- no production deployment, migration or secret, no live router;
- no push, merge or force push;
- the SQL-exhaustion reproduction was not run, simulated or retried;
- AVAIA was not touched.

This package does **not** record any owner acceptance. Every ledger row stays as the owner left it.

---

## 1. SHA and state

| Item | Value |
|---|---|
| Branch | `wp9/red-team-rc`. Local only, **not pushed**. |
| Base | `7e46758114545dd4a6d2b0179e9d5d7ceb60e643` (U0 accepted; unified roadmap) |
| Final tested tree | **`46624cd8`**: full harness on both engines, CHR and browser suites. Later commits are documentation / evidence only. |
| Protected refs (unchanged) | `feat/reseller-system-v1` = wp8 = `704031c6`; `main` = `500d3a2d` |
| Commits this round | 37 since the base (code / test, docs, evidence). Listed in §3; the commit bodies hold the per-item evidence. |
| Engines | MariaDB 10.11.19, MySQL 8.0.46, PHP 8.2.34, FreeRADIUS 3.2.8 (pinned image), RouterOS CHR 7.24.5 (lab) |
| Acceptance protocol | The "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" text was **not located** (repository, Drive, Basic Memory). Per D-S8-9 the audit used: the DoD, the WP9 PLAN §16 gates, the WP10 prompt and the Architecture Lock release gates. No textual conformity to the protocol is claimed. |
| Ledger | WP9 and WP10 rows unchanged. WP9 is **READY_FOR_OWNER_ACCEPTANCE**, not accepted. |

---

## 2. Matrix: every open item of the unified roadmap after this round

Status vocabulary:
- **CLOSED_WITH_EVIDENCE:** code / test or document, test-first where a defect existed, both engines.
- **CLOSED_BY_CONTRACT:** the feature is disabled in the release profile and a test proves it is unreachable.
- **DEPLOYMENT_GATE:** checked on the target by the preflight; the release cannot start while it FAILs.
- **OWNER_DECISION:** non-delegable.

### 2.1 Security blockers (roadmap §4.1)

| # | Blocker | Status | Evidence |
|---|---|---|---|
| S-1 | A2-R SQL-exhaustion admission risk (W8-2 / N-13 / N-17) | **REMEDIATED** (defensive layers L1–L5); CHR security gates 10 / 216 exit 0 on the hardened site (§6). The roadmap allows the owner's reclassification from `UNVERIFIED_SECURITY_RISK` "only by explicit decision". **OWNER_DECISION** | `b4223961`:<br>• CHECK constraints refuse an emptied claim;<br>• echo validation (reject:echo);<br>• SAFE-verified takeover;<br>• check script + preflight;<br>• README corrected.<br>Real FreeRADIUS harness on both engines; `W9AdmissionHardeningTest` 11/35 (pre-fix 9 failures + 1 error). The exhaustion reproduction was **not** run. |
| S-2 | OBS-1 existing data and external writers (W8-1b / 1c) | **Application writers:** CLOSED (U0).<br>**External writers:** CLOSED_WITH_EVIDENCE (least-privilege grants, plugin inventory).<br>**Existing duplicate data:** **DEPLOYMENT_GATE**; the preflight `identity` check FAILs on any multi-holder key. The resolution method for real data is an **OWNER_DECISION**. | `58f5eadf` (`grants.php`, preflight, `W9PreflightGrantsTest` 1/32 both engines); `a6aa0011` (`plugins.php`) |
| S-3 | Phase B authority (W8-21, N-01, N-02) | CLOSED_WITH_EVIDENCE | `a6aa0011`: `W9PhaseBAuthorityTest` 5/14 (pre-fix 5/5 failed), both engines; release profile `cf19e04e` |
| S-4 | R-HF-CARRY-01 / N-12 | CLOSED_WITH_EVIDENCE | `7e4919c8`: `W9AutoloadExposureTest` 3/19 (pre-fix 2/3 failed) |
| S-5 | WP1-CHECK / N-08 (ledger-binding; no risk acceptance) | CLOSED_WITH_EVIDENCE: expression + enforcement verified; readiness, runner, preflight | `2ecfdc2f`, `58f5eadf`: `W9CheckConstraintIntegrityTest` 10/37–39 (pre-fix 6/10 failed), `W9CheckExpressionTest` 13/346. Drill D2: CHECKs survive backup / restore. |
| S-6 | NEW-7 (+ N-03, NEW-6, O-T0-3) | CLOSED_WITH_EVIDENCE | `2dea76d7`: dedicated `$app_secret` with purpose subkeys. Pre-fix, forged `db_pass` cookie / link / staff / invoice tokens were ACCEPTED; after: refused. |
| S-7 | NEW-8 Smarty | CLOSED_WITH_EVIDENCE | `f8bf19fc`: sandboxed page templates. Pre-fix, a page template ran `id` and leaked a staff hash. |
| S-8 | N6 secrets at rest / NAS secret plaintext | CLOSED_WITH_EVIDENCE for redaction (`95ae997f`) and the documented boundary (`docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §1). `nas.secret` stays recoverable **by necessity** (RADIUS signs with it). The AD-18 boundary decision is an **OWNER_DECISION**. | `W9SecretRedactionTest` 1/4 (pre-fix leaked to the log) |

### 2.2 Operational / contract blockers (roadmap §4.2)

| # | Blocker | Status | Evidence |
|---|---|---|---|
| O-1 | Operator recovery (W9-OPS, W8-4, AV-1) | CLOSED_WITH_EVIDENCE (tools + runbook + monitoring). AV-1 itself is FreeRADIUS behaviour: the WP8 options D1 / D2 / D3 are an **OWNER_DECISION**. The tooling implements D1's operational controls. | `558acddc` (`reconcile.php` finance-check, voucher-review / resolve, admission-pending / release); `29d374ea` (`health.php` `admission_pending` alert) |
| O-2 | Time contract P-A4-1…3 | P-A4-1 / 2: DEPLOYMENT_GATE (preflight `time`: UTC session, skew). P-A4-3 (historic radacct): operator survey (checklist E6). The canonical contract (lock C-05) is an **OWNER_DECISION**. | `58f5eadf`, `29d374ea` |
| O-3 | Capacity / load (AV-2, THRU / PERF / IDX, D-U0-R3) | Bounded load PASS on both engines (§10). **Production sizing on target hardware NOT_TESTED** (needs the staging environment). | `29d374ea` |
| O-4 | Restore drill, backup reconciliation, alerting | CLOSED_WITH_EVIDENCE (drill D1–D3 both engines, §11; health / alerting) | `29d374ea` |
| O-5 | REPEATABLE READ, H-2 rollout order, MAC-H1A | DEPLOYMENT_GATE (preflight `isolation`, `mac`); rollout order in the runbook | `58f5eadf` |
| O-6 | R-4 cron vs RENEW | CLOSED_WITH_EVIDENCE | `39c7aed5`: pre-fix paid COMPLETED + RADIUS suspended (both engines); after 2/34 |
| O-7 | CHR regression of the WP8 gates on the final tree | CLOSED_WITH_EVIDENCE: security gates 10 / 216; integration 87 pass / 13 mode skips / 1 parser failure, fixed and re-run 15 / 15 | §6 |
| O-8 | N-07a, N-11, N-17, AV-3, TAMPER / LPDB | CLOSED_WITH_EVIDENCE | `6af1e8e8`, `0da0a4ed`, `b4223961`, `2c7f0b4d`, `58f5eadf` |
| O-9 | Legacy MikroTik-API drivers, VPN (V-1), rlm_rest (W8-15), same-key plans (W8-14), MAC auth | **Legacy drivers / VPN / rlm_rest:** CLOSED_BY_CONTRACT (release profile, `cf19e04e`; `W9ReleaseProfileTest`).<br>**W8-14:** CLOSED_WITH_EVIDENCE (`ca168b7d`, configuration forbidden).<br>**MAC auth:** DEPLOYMENT_GATE (preflight `mac`). | — |

### 2.3 WP8 register rows not covered above

| Row | Status |
|---|---|
| W8-6 AV-3 | CLOSED (`2c7f0b4d`) |
| W8-7 H-2 follow-ups | runbook / checklist §G–H; sizing NOT_TESTED on target |
| W8-9 V-1 VPN | CLOSED_BY_CONTRACT (MikrotikVpn disabled) |
| W8-13 L-A4-1…5 | documented residuals (P3) |
| W8-16 L-1/2/3/5 | documented residuals; multi-owner renames fail closed (U0) |
| W8-17 R2 legacy rename audit | CLOSED_WITH_EVIDENCE (`13182b37`, `W9LegacyRenameAuditTest`, pre-fix 2/3 failed) |
| W8-18 READ COMMITTED | DEPLOYMENT_GATE (preflight `isolation`) |
| W8-22 / 23 / 26 / 27 (legacy drivers) | CLOSED_BY_CONTRACT (release profile) |
| W8-24 OBS-S7-4 | CLOSED_WITH_EVIDENCE (`7ab1e52f`; held at the base) |
| W8-25 L-S7-FC-1 | runbook (`docs/OPERATOR_RECOVERY_RUNBOOK.md` §2 / §6) |
| W8-29 lock gates | **Q1–Q4:** Q2 time and Q3 / Q4 evidenced; **Q1 and Q2-data open (owner semantics, §4 D3)**; characterised on the FreeRADIUS harness.<br>**P1:** CLOSED (`c0d7f8ab`).<br>**P2:** OWNER_DECISION (product).<br>**P3:** CLOSED (`27a3a81f`).<br>**N6 / N7:** CLOSED (`95ae997f`).<br>**REL-01…05:** drill / health / runbooks (§11–12); REL-04 capacity NOT_TESTED on target.<br>**ISO-01:** grants per instance (checklist D4); not tested across two real instances. |
| W8-30 hygiene | D-S8-6 comments already corrected at the base (`704031c6`); OBS-W8-6 P3 |
| W8-31 minor | P3 residuals, unchanged |

### 2.4 WP9-originated rows

| ID | Status |
|---|---|
| N-05 / P1, N-15 / P2–P4 | CLOSED (`614bcae0`, option B) |
| N-15 / P5 | CLOSED (Sales sees only current-owner activations) |
| N-06 | CLOSED (`43007e35`) |
| N-09 reservations never expire | CLOSED for detection (`health.php` reservations WARN > 1 h, CRIT > 24 h); no automatic expiry, by design |
| N-10 API assigns | CLOSED (`d75a07a5` red-team row) |
| N-16 checrad fail-open | INFO (Option G is the defence) |
| R-FC-1 voucher codes in financial fields | **OWNER_DECISION** (masking or design change) |
| R-FC-2 / R-FC-3 | LOW, documented (pinned `Package.php` record; grandfathered only) |
| R-FC-4a | runbook (voucher review) |
| HARNESS flakes | P3; none occurred in the final run (§5) |

### 2.5 Findings of the final audit (this round)

| ID | Finding | Status |
|---|---|---|
| SC-1 | CDN scripts without Subresource Integrity (Chart.js on every staff page; two unpinned versions) | CLOSED_WITH_EVIDENCE (`9a6c12e5`; 30 → 0) |
| SEC-17 | staff passwords unsalted SHA-1 (open since WP0 GAP_ANALYSIS); loose `==` comparison let two "0e" SHA-1 passwords log in for each other | CLOSED_WITH_EVIDENCE (`ad3b6bf3`; reproduced pre-fix on both engines) |
| INST-1 | `install/step5.php` overwrote the operator's pages / deleted the templates after installation (unauthenticated) | CLOSED_WITH_EVIDENCE (`b0184aaa`) |
| SESS-1 / HDR-1 | session cookie readable by scripts (no HttpOnly / SameSite); no anti-framing / nosniff headers | CLOSED_WITH_EVIDENCE (`cf1bdfec`) |
| **XSS-1** | **Stored script injection across privilege levels.** Smarty has no auto-escaping (GAP_ANALYSIS §14; O8-C deferred), and legacy templates printed lower-privilege text raw. An Agent-created customer / Sales account (full name, address, city, district, **username**), or a customer's own profile, rendered as live markup on **SuperAdmin / Admin** pages: customer edit, staff view / edit, system log; the Agent's own staff list. **Production blocker** (privilege escalation). | CLOSED_WITH_EVIDENCE: 249 free-text outputs `|escape`d in 80 templates (names, addresses, contact fields, usernames, descriptions, plan / pool / router / bandwidth names, widget titles); system log description escaped before `nl2br`. `W9StoredXssCrawlTest` crawls every GET page of the route map as SuperAdmin, Admin and Agent: pre-fix **13** unescaped renderings on both engines, post-fix 0. Global `escape_html` (O8-C) stays out of scope: double escaping of the existing `|escape` uses. |
| GUARD-1 | 8 accepted source guards broken by WP9 tool code (full run 1) | CLOSED (`1dc37552`; fixed in code, no guard changed) |
| R-JQ-1 | jQuery 1.11.1 bundled (known CVEs: CVE-2015-9251, CVE-2019-11358, CVE-2020-11022 / 11023). They are exploitable only when the application passes untrusted HTML / JSON to jQuery DOM or extend helpers, and server-side escaping is the primary control. No such path was found in this audit, but a full proof would need an upgrade and a UI regression. | **OPEN, LOW / MEDIUM**: upgrade plan, **OWNER_DECISION** (visual-regression scope) |
| LIC-1 | GPL version ("2 only" vs "any"), Smarty LGPL-3.0 with a GPL-2.0 work, the delivery model | **LEGAL REVIEW** (`docs/THIRD_PARTY_LICENSES.md`) |

---

## 3. Closed findings (commits, oldest first)

Each code commit body records:
- its pre-fix evidence (the test run on the base tree `7e467581` through the worktree `/srv/phpnuxbill/wp9-prefix`, which reproduces the defect);
- its post-fix runs on both engines;
- every protected-file re-pin.

| Commit | Subject |
|---|---|
| `2ecfdc2f` | wp9(wp1-check): verify CHECK constraints by normalized expression and enforcement |
| `af752926` | wp9(wp1-check): keep the guard-test console evidence |
| `2dea76d7` | wp9(new-7): dedicated application key with purpose subkeys; no signature from db_pass |
| `f8bf19fc` | wp9(new-8): render editable page templates in a sandboxed Smarty instance |
| `6af1e8e8` | wp9(n-07a): quote and neutralise every cell of the legacy CSV exports |
| `0da0a4ed` | wp9(n-11): stored secrets are write-only in the admin UI; post-auth passwords never rendered |
| `7e4919c8` | wp9(r-hf-carry-01): no router / plan / bandwidth metadata for tree-scoped staff in legacy autoload |
| `4bad1ca3` | wp9(new-7): SecurityRoutesTest positive control uses the AppKey invoice signature |
| `a6aa0011` | wp9(phase-b): guard the public RADIUS grant, stored expired plan only, plugin errors reported, REST authenticates before plugins |
| `39c7aed5` | wp9(r-4): per-customer service lock between cron expiry, activation and re-provision |
| `7c285f90` | docs(wp9): autonomous progress ledger checkpoint |
| `614bcae0` | wp9(policies): owner policy matrix P1-P5, conservative option B |
| `43007e35` | wp9(n-06): operator CLI never acts on a live activation |
| `b4223961` | wp9(a2r): defensive hardening of the PPPoE admission SQL path (no exhaustion reproduction) |
| `aeeb400d` | docs(wp9): progress ledger checkpoint |
| `2c7f0b4d` | wp9(av-3): admission release audit records the database-attested account |
| `cf19e04e` | wp9(release-profile): FreeRADIUS-only release profile; legacy MikroTik-API and rlm_rest devices disabled by default |
| `558acddc` | wp9(ops): operator recovery tools and runbook (no deletion, audited, exactly once) |
| `58f5eadf` | wp9(lpdb+preflight): least-privilege grants, release preflight, CHECK verification that works under them |
| `99074d5e` | docs(wp9): progress ledger checkpoint |
| `27a3a81f` | wp9(p3): seal and verify the confirmed bandwidth terms (lock gate P3 / WP6A R-15) |
| `c0d7f8ab` | wp9(p1): report and end a plan change's session that kept the previous policy (lock gate P1) |
| `ca168b7d` | wp9(w8-14): forbid a Hotspot and a PPPoE service of one customer on one RADIUS key |
| `d75a07a5` | wp9(red-team): HTTP / API red-team batch; array request parameters no longer end requests with 500 |
| `7ab1e52f` | wp9(red-team): no-shell scan of all runtime code; OBS-S7-4 key-variant re-test |
| `95ae997f` | wp9(n6-n7): database-stored secrets redacted from logs; NAS onboarding and secret rotation runbook |
| `dc4b0176` | docs: sync owner architecture lock 2026-10-09 and audit development status |
| `e918be9d` | docs(d-s8-4): refresh dated status lines of the synced architecture lock (WP8 merged; WP9 branch) |
| `9a6c12e5` | wp9(sc-1): pin third-party scripts and stylesheets with Subresource Integrity |
| `1dc37552` | wp9(guards): restore the accepted source-guard boundaries broken by the WP9 tools |
| `ad3b6bf3` | wp9(sec-17): salted adaptive staff password hashes; strict legacy comparison |
| `b0184aaa` | wp9(inst-1): the last installer step does nothing on an installed application |
| `cf1bdfec` | wp9(sess-1, hdr-1): HttpOnly / SameSite session cookie, strict session ids, anti-framing headers |
| `13182b37` | wp9(w8-17): audit and confirm the old-name sessions of a legacy Admin rename |
| `29d374ea` | wp10(prep): health telemetry, backup / restore / rollback drill, bounded load test, runbooks, licenses |
| `b5b441d4` | wp9(xss-1): escape lower-privilege free text in the legacy templates (stored XSS across roles) |
| `c298b21d` | wp10(ui): final UI / browser audit; customer page language and zoom |


---

## 4. Risks (open, residual, accepted-by-design)

### 4.1 Open: they need an owner decision (non-delegable; nothing was risk-accepted on the owner's behalf)

| # | Item | Why it is open | What closes it |
|---|---|---|---|
| D1 | **WP9 acceptance** (ledger) | WP10 is gated on "WP9 owner-accepted with zero blockers" | the owner's acceptance record |
| D2 | **S-1 A2-R reclassification** | remediated and regression-tested; the reproduction was never run (forbidden). The roadmap reserves the reclassification for the owner | the owner's decision on the evidence (§2.1, §6) |
| D3 | **Lock gates Q1 / Q2 (quota correctness, "mandatory")**:<br>• Q1: a session that crosses a renewal is attributed entirely to the previous period;<br>• Q2: a data quota is enforced only at the next authentication (no in-session limit). Time quotas are enforced in-session. | product semantics, linked to P2 (RENEW Session-Timeout) and the FUP scope ("advanced FUP not approved"). Characterised on the real FreeRADIUS harness (`W9QuotaGateCharacterisationTest`). | the owner chooses the contract. Options include:<br>• accept the current semantics as the V1 contract;<br>• end the running session at renewal / at the data quota (CoA, P2);<br>• send an in-session data limit where the NAS type supports it.<br>Then implementation + CHR proof. |
| D4 | **AV-1** (FreeRADIUS stall → orphan PENDING claim) | WP8 options D1 / D2 / D3 | the owner's choice. The tooling and monitoring for D1 exist (`admission-pending` / `admission-release`, `health.php`). |
| D5 | **Time contract (lock C-05)** | the preflight already enforces the UTC SQL session and the clock skew; the canonical contract text is the owner's | the owner's decision |
| D6 | **AD-18 secret boundary (S-8)** | `nas.secret` must stay recoverable; the boundary is documented | the owner's decision |
| D7 | **OBS-1 existing data**: the resolution method for real duplicate identities | the preflight FAILs on any multi-holder key; resolving one needs a business decision about who keeps the key | the owner's method + an operator run on an approved copy |
| D8 | R-FC-1 voucher codes in financial fields; P2 RENEW Session-Timeout (product) | product choices | the owner's choice |
| D9 | R-JQ-1 jQuery 1.11.1 upgrade; customer-portal RTL / contrast (OD-3 scope) | UI scope / visual identity | the owner's scope decision |
| D10 | **LIC-1 legal review** (GPL version, Smarty LGPL-3.0, delivery model) | legal, not technical | counsel |

### 4.2 Not testable in this environment (WP10, needs a target / staging environment and real equipment)
- deployment on the target infrastructure:
  - TLS, web server, NTP;
  - the least-privilege accounts created on the target;
  - FreeRADIUS on the target with its SQL pool sizing;
- production capacity / sizing (AV-2 / REL-04) on target hardware;
- NAS onboarding against the ISP's real routers (forbidden here; the CHR lab is the substitute);
- ISO-01 across two real instances;
- the OBS-1 survey and the P-A4-3 `radacct` survey on real data (production data: not touched).

### 4.3 Accepted-by-design and documented residuals (LOW / INFO)
- L-A4-1…5;
- L-1 / L-2 / L-3 / L-5 rename windows;
- L-S7-FC-1 "billed, sync manually" (runbook);
- R-FC-2 / R-FC-3;
- R-FC-4a (runbook);
- N-16 (Option G is the defence);
- OBS-W8-6 PEAR2 deprecation;
- W8-31 minor items;
- the run-dependent assertion counts of the harness (R-FC-8 / 9);
- the Report role holds no report capability in the accepted WP2 model (an observation on naming vs scope).


---

## 5. MariaDB / MySQL results

| Run | Tree | MariaDB 10.11.19 | MySQL 8.0.46 | Notes |
|---|---|---|---|---|
| Full run 1 | `95ae997f` | 1476 tests, **8 failures** (accepted source guards) | not completed (stopped) | kept: `evidence/w9e/full-run1-…`; fixed in code (`1dc37552`) |
| Full run 2 | `29d374ea` | stopped at 26 % (0 failures by then) | — | superseded by XSS-1; kept: `full-run2-…` |
| **Full run 3 (final)** | **`46624cd8`** | **1493 tests, 75 946 assertions, 0 failures, 104 incomplete** | **1493 tests, 75 949 assertions, 0 failures, 104 incomplete** | `evidence/w9e/full-run3-46624cd8-20261017T000711Z.log`; run-tests teardown clean |

- **Incomplete:** the 104 per engine are the real-NAS classes, which mark themselves incomplete without the CHR lab. They were executed for real on CHR (§6).
- **Assertion counts:** the run-dependent difference (3 assertions) is the known R-FC-8 / 9 race-winner variance.
- **Schema variants:** fingerprints are identical across engines: V1 `3ca25964…`, V2 `6c2f04ea…`, V3 `e1a66aa3…`.
- **Focused test-first runs** of every fix are in the commit bodies and `owner-review/WP9/evidence/{w9f,w9sc,t1a,a1…}`.
- **WP10 lab tools on both engines:** drill PASS, load PASS (§10, §11).


---

## 6. CHR results

Lab:
- RouterOS CHR 7.24.5 ×2 under QEMU (isolated `10.88.x` networks, independent PPPoE / Hotspot client namespaces);
- FreeRADIUS 3.2.8 with the **shipped, A2-R-hardened site** (`h2b_gate.py site project`);
- production-contract sequence: Option G shipped, CHR2, H-2 setup, A2-R CHR2 setup, threaded FreeRADIUS.

Never run: `a2r_sql_gate.py`, `a2r_gate.py pool` / `fault`. The lab was torn down afterwards: 0 VMs, 0 netns, 0 links.
Evidence: `owner-review/WP9/evidence/w9e2/chr-20261016T221608Z/` and `chr-optiong-20261016T234732Z/`.

| Suite | Result |
|---|---|
| `wp8-security-gates` (A2 concurrent first login A / B0 / Bscan / C / E1–E3, username equivalence PPPoE + Hotspot, shared users) | **10 / 10, 216 assertions, exit 0** (33 min of race trials) |
| `wp8-integration` | 101 tests / 1847 assertions:<br>• **87 pass**;<br>• 13 skipped: the stock-checkrad / SNMP-mode and "functional"-mode gates that need a different lab mode than the production contract (same 13 as the S8 re-gate);<br>• **1 failure kept**: `OptionGGateTest` gate A |
| Failure analysis | The hardened probe first carries its echo (`n=0;;u=…;k=…`) and is normalised back to `n=0;` before the decision (site lines 333–348). The test's private trace parser took the **first** assignment; the shared `Wp8LabHelpers` (used by the passing security gates) takes the last. The parser now reads the last `n=` probe value; the assertion `n=0;` is unchanged. **The decision was correct:** Access-Accept, no verification. |
| `OptionGGateTest` re-run | **17 tests / 426 assertions, 15 pass, 2 skipped (functional mode), exit 0** |

The WP8 "104 incomplete" tests of the harness are exactly these lab suites (they are marked incomplete without the lab). They were **executed for real** here: 10 security gates + 101 integration tests = 111, which also includes the classes added since.

Covered on real RouterOS by these suites:
- PPPoE / Hotspot AAA, accounting Start / Interim / Stop;
- CoA / Disconnect (ACK / NAK / silence);
- business flows A → B → C, renew, change plan, expiry, re-provision;
- the failure drills (FreeRADIUS down, DB down, uncertain commit, NAS unreachable, CoA port blocked, auth timeout, accounting interruption, API unavailable, lost response, recovery);
- driver contracts, fail-closed legacy paths, Port-Limit, rename / old session (A3), accounting time (A4).

**Lock gates on CHR / FreeRADIUS:**
- Q3 (accounting interruption) and Q4 (no destructive reset) are evidenced.
- Q1 / Q2-data are characterised as open (§4 D3).
- The A2-R race gates pass.


---

## 7. Security / red team

### 7.1 Red-team matrix (PLAN §12) and stages

| Stage | Execution | Result |
|---|---|---|
| T0 static | earlier round (owner-accepted) | PASS |
| T1-A harness characterisation | earlier round (OBS-1 V1 found and fixed by U0) | closed |
| **T1-B / T2** harness + HTTP / API, rows #1–#13, #16–#19, #21, #23–#25 | this round, both engines. Rows #1, #2, #4, #5, #6, #8, #9, N-10, #13, R-FC-1 are in `W9RedTeamHttpTest` (10 / 132). The other rows are in their dedicated tests: #3 / #5 P2–P4 `W9OwnerPolicyTest`; #7 / #8 `Wp3a` concurrency + `StagingLoadTest`; #10 `W9CsvExportInjectionTest`; #11 LPDB `W9PreflightGrantsTest`; #12 `W9ReconcileCliGuardTest`; #14 `W9NoShellSourceTest`; #16 `W9KeySeparationTest`, `W9SessionCookieHardeningTest`; #17 `W9CheckConstraintIntegrityTest`; #18 `W9CronRenewalRaceTest`; #19 `W9AutoloadExposureTest`; #21 AV-3; #23 `W9SecretRenderingTest`, `W9SecretRedactionTest`; #24 the existing SEC regression suites in the full harness; **XSS-1** `W9StoredXssCrawlTest` (new axis, §2.5). | all PASS post-fix; every defect reproduced pre-fix on both engines (commit bodies) |
| **T3** PWA browser, row #15 | this round, PWA lab: `pwa_history.py` (back / forward after logout, online / offline, next user) + WP7 suite T13 / T17 | PASS (§9) |
| **T4** CHR, lab parts of rows #13 / #14 / #20 / #22 + A2-R regression gates | this round, CHR 7.24.5 lab, production-contract sequence | PASS (§6) |
| WP9-C OBS-S7-4 re-test | `W9KeyVariantDeviceTest` | holds (already at the base) |

### 7.2 Security fixes of this round (all test-first, both engines)

| ID | Defect reproduced before the fix | Fix |
|---|---|---|
| WP1-CHECK | a same-name weakened CHECK passed verification | expression + enforcement verification |
| NEW-7 / N-03 / NEW-6 / O-T0-3 | forged cookie / link / staff / invoice tokens from `db_pass` were accepted | dedicated `$app_secret` and purpose subkeys |
| NEW-8 | a page template ran `id` and leaked a staff hash | sandboxed Smarty |
| N-07a | CSV formula / quote injection | RFC 4180 + neutralisation |
| N-11 | stored secrets rendered in admin forms | write-only fields, masked logs |
| R-HF-CARRY-01 | router / plan metadata exposed to Agent / Sales | scope-checked autoload |
| Phase B | public RADIUS grant on owned customers; caller-supplied `plan_expired` | guards |
| R-4 | cron expiry vs renewal produced a paid COMPLETED with suspended RADIUS | per-customer service lock |
| P1–P5 | replay after authority loss; staff-policy gaps | option B policies |
| N-06 | CLI reconciled a live activation | 300 s floor, resume stale only |
| A2-R | emptied SQL expansions could create claims / skip verification | CHECKs + echo + SAFE flag |
| P3 / R-15 | changed bandwidth terms applied after confirmation | sealed terms |
| W8-14 | Hotspot + PPPoE on one RADIUS key | refused |
| N6 | NAS secret in an exception reached the log | DB-secret redaction |
| SC-1 | CDN scripts without integrity | SRI |
| **SEC-17** | two different "0e" SHA-1 passwords logged in for each other; unsalted SHA-1 | bcrypt, strict compare, upgrade on login |
| **INST-1** | unauthenticated `install/step5.php` overwrote pages after install | post-install guard |
| **SESS-1 / HDR-1** | script-readable session cookie; frameable staff pages | HttpOnly / SameSite / strict mode; X-Frame-Options / CSP frame-ancestors / nosniff |
| **XSS-1** | Agent / customer text executed as markup on SuperAdmin / Admin pages (13 renderings) | 249 outputs escaped |
| W8-17 | legacy rename without old-session audit / warning | confirm + audit |

### 7.3 Things deliberately **not** done
- The SQL-exhaustion reproduction, pool pressure or SQL fault injection: never run, simulated or retried. The A2-R closure rests on defensive design, DB-level CHECK tests, functional tests on the real FreeRADIUS harness and the CHR security gates in normal operation.
- No production, live router, push or merge. No risk acceptance on the owner's behalf.


---

## 8. Finance integrity

- **Read-only integrity report** (`SettlementReadModel::integrity`, CLI `reconcile.php finance-check`, `health.php --finance`). It checks:
  - ledger continuity per wallet;
  - wallet = last balance;
  - available >= 0;
  - transfer legs and reversal links;
  - reservation <-> debit;
  - WP3B settlement against the sealed snapshot (conservation: leaf debit = commissions + provider settlement);
  - activation timelines, reservations and legacy records.

  It detects tampering (`W9RecoveryToolsTest`: a ledger entry changed while keeping `ck_ledger_math`, and a wallet changed outside the ledger) and reports CONSISTENT after restore.
- **Database guarantee (LPDB):** with the least-privilege application account, the database refuses UPDATE / DELETE on the ledger, audit and activation-event tables, and DELETE on wallets / transfers / reservations / activations. The application still works under it (`W9PreflightGrantsTest`).
- **Concurrency:** 96 activations on one wallet, 96 distributed, and same-customer RENEW stampedes. Every activation is terminal, there are no reservations left, no double charge (completed = committed reservations), and finance integrity is clean after every scenario (§10).
- **Races closed this round:** R-4 (cron vs renewal), P1 replay, N-06 live reconcile.
- **No financial history, RADIUS accounting or audit record was deleted** by any change, tool or test of this round. The recovery tools mark / compensate; they never delete.


---

## 9. Visual / UI

Evidence: `owner-review/WP10/evidence/ui/` (JSON / text; the 130 screenshots are kept outside Git).

| Check | Result |
|---|---|
| Staff pages: 6 roles × main pages × 3 layouts (desktop English 1366×800, phone Arabic 390×844, tablet Arabic 820×1180), 130 loads | no horizontal overflow; Arabic `dir=rtl`; axe wcag2a/aa serious + critical **0**; no console / page errors except the expected 403s of denial cases |
| Role denials | A → settings/app and A1 → finance/overview refused (403). Report (`rp`) is refused reports and customers: in the accepted WP2 model Report holds only dashboard / staff-read / self capabilities. **Observation for the owner** (naming vs scope). |
| WP7 PWA suite T00–T24 | **32 / 32 PASS** on the final templates (secure context, manifest, worker, offline gate, cross-user hygiene, updates, RTL, axe, CSRF / XHR transport) |
| Row #15 history after logout | PASS (§7) |
| Customer login (portal out of WP6C scope, OD-3) | Fixed this round: `lang` and pinch-zoom. Open, low: the theme's default button / link contrast (visual identity, no change); no RTL layout of the portal in Arabic (**product-scope OWNER_DECISION**) |
| CDN assets | all pinned with SRI; the only external origin loaded is cdn.jsdelivr.net (Chart.js) |


---

## 10. Staging load

Evidence: `owner-review/WP10/evidence/load/`; tool `dev/wp10/load.sh` + `StagingLoadTest`.

Bounded by design:
- at most 12 concurrent workers, each with its own DB connection;
- connection high-water mark: 15 of 151;
- never a pool or connection exhaustion test.

| Scenario | MariaDB 10.11 | MySQL 8.0 |
|---|---|---|
| L1: 96 NEW activations, distributed over two managers | 96 COMPLETED, 9.5 ops/s, wave p50 1.15 s | 96 COMPLETED, 10.2 ops/s, wave p50 1.24 s |
| L2: 96 NEW activations on one wallet (contention) | 96 COMPLETED, 12.4 ops/s, wave p50 0.94 s | 96 COMPLETED, 9.0 ops/s, wave p50 1.32 s |
| L3: 6 concurrent RENEW of one customer × 8 waves | 8 COMPLETED + 40 `ACTIVATION_IN_PROGRESS`, no double charge | same |
| After every scenario | integrity clean, every activation terminal, no ACTIVE reservation | same |

Interpretation:
- **Correctness under bounded concurrency is proven.**
- The throughput figures include a PHP process start per operation (harness), so they are a **lower bound, not a capacity number**.
- **Production sizing (AV-2 / REL-04) needs the target hardware and a staging environment: NOT_TESTED.** The production FreeRADIUS SQL pool sizing guidance is in `install/freeradius/README.md`.


---

## 11. Backup / restore / rollback

Evidence: `owner-review/WP10/evidence/drill/`; tool `dev/wp10/drill.sh` + `BackupRestoreDrillTest`. The dumps were made with the engine's own client inside the DB container (password never on a command line) and stayed outside Git.

| Drill | MariaDB 10.11 (mariadb-dump 10.11) | MySQL 8.0 (mysqldump 8.0.46) |
|---|---|---|
| **D1 upgrade:**<br>1. legacy V2 database with data;<br>2. backup B0;<br>3. preflight / migrate / verify (exit 0);<br>4. a second migrate changes nothing;<br>5. legacy data unchanged;<br>6. with new-domain data, `rollback --all` is refused (exit 2) and changes nothing. | PASS | PASS |
| **D2 backup / restore:**<br>• the source holds 7 activations + 1 in flight, ledger, wallets, reservations, audit, RADIUS rows, admission claim and accounting (46 tables, 357 rows);<br>• backup B1;<br>• restore into a fresh database;<br>• **every table identical**: definition including CHECK constraints, row count, SHA-256 of all rows;<br>• `migrate verify` 0; health incl. finance all OK;<br>• a new activation COMPLETED on the restored copy; integrity clean. | PASS | PASS (MySQL prints a restored column's `COLLATE` with a redundant `CHARACTER SET`; it is the same definition, normalised in the comparison) |
| **D3 rollback by restore:**<br>• B0 restored is identical to the pre-upgrade database;<br>• the upgrade re-runs (migrate / verify 0). | PASS | PASS |

**Rollback policy** (`docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §4):
- schema rollback only without new-domain data;
- otherwise restore the pre-upgrade backup and redeploy the previous code;
- never redeploy upstream PHPNuxBill over a migrated database.


---

## 12. Runbooks

| Runbook | Covers |
|---|---|
| `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` | release artifact, new installation, upgrade of an existing PHPNuxBill, rollback, post-deployment checks |
| `docs/BACKUP_RESTORE_RUNBOOK.md` | what a backup contains (secret material), taking / encrypting backups, restore into a new database, verification, reconciliation of the gap, the quarterly drill |
| `docs/OPERATOR_RECOVERY_RUNBOOK.md` | daily detective checks, continuous monitoring (`health.php`), stuck activations, CLAIMED vouchers, PENDING admission claims (AV-1 / W8-4), REPROVISION / OLD_SESSION unconfirmed (incl. legacy renames), stale policy sessions (P1), HELD balance operations, cron, what is never done |
| `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` | secret boundary (N6), NAS onboarding (N7), NAS secret rotation, application secret rotation, NAS removal |
| `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` | platform, TLS / web server, secrets, least-privilege accounts, schema / data gates, backups, FreeRADIUS, RouterOS contract, scheduled jobs, monitoring / alerting, sign-off |
| `install/freeradius/README.md` | FreeRADIUS site / modules, admission reservation, session guard, time zone, NAS clients |
| `docs/THIRD_PARTY_LICENSES.md` | license metadata and the legal-review items |


---

## 13. Production configuration checklist

The full checklist is `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` (sections A–K).
- Lines marked **[P]** are enforced by `php system/reseller/preflight.php`, which must exit 0 on the target. It checks: keys, stage / HTTPS, engine, REPEATABLE READ, UTC + clock skew, schema incl. CHECKs, admission CHECKs incl. MariaDB `check_constraint_checks`, the OBS-1 identity survey, the release profile, plugins, least-privilege grants, and MAC-auth impact.
- Lines marked **[H]** are monitored by `php system/reseller/health.php`.

Release-blocking items per instance:
- preflight FAIL of any kind (in particular `identity`: OBS-1 duplicates on an upgraded database);
- no successful restore drill on the target backup chain;
- the NAS identity contract (`nasname` = `src-address` = `NAS-IP-Address`) unverified for any NAS.


---

## 14. Release decision

## **Decision: `RELEASE_BLOCKED_WITH_EVIDENCE`**

**Closed with evidence in this round:**
- every engineering, security, operational and test item inside the authorization:
  - all P0 security blockers' code work;
  - every P1 operational blocker that does not need the target environment;
  - five new findings from the final audit, among them **XSS-1**, a privilege-escalation path;
- both engines;
- the CHR security gates;
- the browser suites;
- the drills and the bounded load test.

**The system is not declared production-ready**, because these mandatory items are still open. None of them can be closed by the agent within the authorization:
1. **Lock gates Q1 / Q2-data (mandatory, lock axis 6):**
   - a cross-period session is attributed to the previous period;
   - a data quota is enforced only at the next authentication.

   This is characterised on the real FreeRADIUS harness. The contract is a product decision (§4 D3); after it, implementation + CHR proof are needed.
2. **Non-delegable owner acceptances / decisions:**
   - WP9 acceptance (D1);
   - A2-R reclassification (D2);
   - AV-1 (D4);
   - the time contract (D5);
   - the AD-18 boundary (D6);
   - the OBS-1 resolution method (D7).
3. **WP10 target-environment work** that is forbidden or impossible here (§4.2):
   - deployment on the target with TLS / NTP / least-privilege accounts;
   - capacity on target hardware;
   - real NAS onboarding;
   - the OBS-1 / P-A4-3 surveys on real data.
4. **Legal review** (D10) before distribution.

**What unblocks the release, in order:**
1. The owner's WP9 review, with the decision below.
2. Implementation + CHR proof of the chosen Q1 / Q2 contract.
3. WP10 on a staging copy of the target:
   - deploy per `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md`;
   - preflight exit 0;
   - restore drill on the target backup chain;
   - capacity run;
   - NAS onboarding per NAS.
4. The owner's release decision in the ledger.

### The one decision requested now

**WP9 acceptance, together with the quota contract (D3) that it requires.** Options for D3:
- **A:** accept the current semantics as the V1 contract and document them for operators and customers:
  - time quotas end the session (Session-Timeout);
  - data quotas are checked at the next login;
  - a session crossing a renewal counts in the period it started.
- **B:** end the running session at renewal (CoA Disconnect from the activation), so every period starts from a fresh session. This closes Q1 and depends on P2.
- **C:** B, plus an in-session data limit where the NAS supports it (RouterOS Hotspot `Mikrotik-Total-Limit`; PPPoE has no equivalent: CoA on interim accounting). This is closer to the "advanced FUP" the lock has not approved.

**Recommendation: A for V1** (no new mechanism, the lock's "KEEP current scope"), with B as a follow-up once P2 is decided. The other D-items (§4.1) can be decided in the same review; the evidence for each is referenced there.

