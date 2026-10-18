# SAAS_RADIUS_V1_FINAL_OWNER_RELEASE_DECISION_PACKAGE

**Owner authorization in force:** `CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED` (2026-10-17), on top of `QUOTA_V1_GB_SUPPORT_APPROVED`, `RENEWAL_OPTION_B_APPROVED`, `AUTONOMOUS_RELEASE_COMPLETION_AUTHORIZED`.
**Date:** 2026-10-18 (UTC). **Handoff / checkpoint:** `owner-review/WP10/HANDOFF_V1_CONTINUOUS.md`. **Engineering decisions taken:** `owner-review/WP10/DECISIONS.md`.

Scope of the execution (unchanged boundaries):
- development, disposable MariaDB / MySQL databases, the isolated RouterOS CHR lab and synthetic data only;
- no production deployment, no production data, no live router, no secret rotation, AVAIA not touched;
- nothing pushed, merged or force-pushed (protected refs verified, section 1);
- `a2r_sql_gate.py`, `a2r_gate.py pool` and `fault` (the SQL-exhaustion reproduction) were never run, simulated or retried;
- no owner acceptance, risk acceptance or ledger row was recorded on the owner's behalf.

---

## Final status

# **`RELEASE_BLOCKED_WITH_EVIDENCE`**

Three different things are kept apart on purpose:

| Question | Answer |
|---|---|
| Is the engineering work in the authorized scope complete and verified on the frozen candidate? | **Yes.** Every gate that can be run here passed with no failure (sections 3 - 11). No engineering blocker is known. |
| Has the owner formally accepted WP9 / WP10, the remaining risk decisions and the commercial semantics? | **No.** Ten decisions are open and non-delegable (section 13). |
| Can the product be launched in production? | **No.** The ten target-environment procedures (TA-01 .. TA-10, section 12) are all `NOT_RUN`, legal review LIC-1 is open, and no production deployment was authorized. |

The status becomes `READY_FOR_PRODUCTION_OWNER_APPROVAL` only when section 13 is answered **and** `docs/V1_TARGET_ACCEPTANCE.md` is `PASS` on the approved target with its evidence files. A failing gate on a changed tree, or an owner rejection of N1 (a hard PPPoE total would be a design change), would also change it.

---

## 1. Exact final Git state

| Item | Value |
|---|---|
| Repository / branch | `lucefardeavel/phpnuxbill-reseller`, `wp9/red-team-rc`. **Local only, not pushed.** |
| Base of the V1 work | `e45899dd456d6df5ca63a016e9664aaebf45c1ed` (WP9 final), V1 GB + Option B at `c8679494` |
| **Frozen product candidate** | **`e34906d6`**. Every commit after it is tests / docs / evidence only: `git diff e34906d6 HEAD -- system install ui index.php` is empty. |
| Final HEAD when this package was written | the commit that adds this file (parent `94d1d3d1`); the evidence commits `17da628a`, `fcb6d8bb`, `fe2c4424`, `94d1d3d1` precede it |
| Protected refs (`git ls-remote origin`, unchanged) | `feat/reseller-system-v1` = `wp8/integration-poc` = `704031c6`; `main` = `500d3a2d`; `hotfix/pace-xhr-csrf` = `6d4f3f96`; `wp7/staff-pwa` = `8e7f0e1c` |
| Engines | MariaDB 10.11.19, MySQL 8.0.46, PHP 8.2.34, FreeRADIUS 3.2.8 (pinned image), RouterOS CHR 7.24.5 (2 VMs) |
| Working tree | clean (no untracked file after the final commit) |

Commits of the continuous phase, oldest first:

| SHA | Content |
|---|---|
| `0ec84def` | **N2**: exact billing-period ownership by accounting-row arrival order; health check `nas_clock` |
| `ae99c89e` | **N1 levers**: configurable data-quota interim, outcome-dependent enforcer retry, one termination call per pass, silent NAS asked once |
| `d59fb61f` | N1 measured on the CHR (10 cases), N1 analysis, target acceptance procedure, N2 real-NAS evidence |
| `e34906d6` | closure checker item for the N1 lab class (**frozen product candidate**) |
| `17da628a` | CHR gates on the candidate |
| `fcb6d8bb` | full dual-engine harness on the candidate |
| `fe2c4424` | closure matrix on the candidate; checker fix (see section 2) |
| `94d1d3d1` | UI / PWA / drill / load evidence and the evidence index |

Product code changed in the continuous phase (`system/`, `install/`, `ui/`): 16 files, +264 / -53 (`git diff c8679494 e34906d6`). Not touched: ledger, wallets, pricing, settlement, schema, migrations, `install/*.sql`, RouteMap, capabilities.

---

## 2. WP8 / WP9 / WP10 closure matrix

The roadmap items were re-verified **against the actual tree** by `dev/wp10/closure_check.py --testdox <full-run log>`: for each item the control must exist in the file (pattern match), the cited test class must exist, and with the harness log it must have run on both engines without failure. Result on the candidate: **36 items, exit 0** (`evidence/release-candidate/closure-matrix-17da628a.md`).

| Class | Items | Meaning |
|---|---|---|
| CLOSED | 27 | control present, tests ran and passed on both engines |
| CONTRACT | 1 | disabled by the release profile and proven unreachable (legacy drivers, VPN, rlm_rest, same-key plans) |
| DEPLOY | 4 | deployment gate checked by the preflight on the target (OBS-1 existing data, time contract, capacity tool, REPEATABLE READ / MAC) |
| LAB | 4 | real-NAS proof, run on the CHR (V1 quota, N1 overshoot matrix, A3 rename, Option G / Port-Limit / equivalence / A2-R race gates) |

Reconciliation with the WP8 production-blocker register (31 rows), the WP9 findings and the WP10 requirements (full tables: `SAAS_RADIUS_FINAL_PRODUCTION_READINESS_PACKAGE.md` section 2, status vocabulary unchanged):

| Group | State |
|---|---|
| WP8 W8-1a, 3, 4, 6, 14, 17, 19, 20, 21, 24, 28, 30 (application / authority / recovery / race / metadata exposure) | CLOSED_WITH_EVIDENCE |
| WP8 W8-9, 15, 22, 23, 26, 27 (VPN, rlm_rest, legacy MikroTik-API drivers) | CLOSED_BY_CONTRACT (release profile) |
| WP8 W8-1b (existing duplicate identities), 5, 7, 8, 10, 11, 12, 18 | DEPLOYMENT_GATE or target test (preflight FAILs; tool exists; not run on real data) |
| WP8 W8-2 A2-R | REMEDIATED by defensive layers; CHR security gates 10 / 216 on the candidate; the reproduction was never run. Reclassification = owner decision D2 |
| WP8 W8-13, 16, 25, 31 | documented residuals / runbook (P3) |
| WP8 W8-29 lock gates | Q1 - Q4 (quota) closed by V1 (section 5); P1, P3, N6, N7 closed; P2 settled by Option B; REL-04 and ISO-01 need the target |
| WP9 (N-01 .. N-17, S-1 .. S-8, SEC-17, INST-1, SESS-1, XSS-1, SC-1, P1 .. P5) | CLOSED_WITH_EVIDENCE except the owner decisions in section 13 |
| WP10 (restore drill, health / alerting, runbooks, configuration checklist, load tool) | CLOSED_WITH_EVIDENCE in the lab; target execution is section 12 |

Honest note on the checker: the first closure run on this log reported two classes missing. Both were tool defects, fixed in `fe2c4424` without relaxing a check: the testdox parser ignored `Tests\Unit\...` class headers (it had been attributing those tests to the preceding class: `CsrfTest` shows 9 tests per engine now instead of 35), and one cited class is a CHR lab class under `tests/Integration/Wp8` that does not run in the harness. A lab class is never reported as a pass: it is listed as "lab class (not in the harness run)" and its result is the CHR run in section 6.

---

## 3. Completed engineering work (this phase and the V1 base)

| Area | Delivered |
|---|---|
| V1 plans | time, speed, GB and composite plans for Hotspot and PPPoE; plan forms; voucher GB path; fail closed on malformed or duplicate quota rows (login rejected) |
| In-session quota | native NAS limit for the remaining quota at login (Hotspot total, PPPoE per direction) + accounting enforcer `quota.php enforce` + confirmed termination (`SessionTermination`) + health `quota_enforcer` |
| Renewal Option B | the previous-period session is ended and the outcome confirmed before the new period is relied on; exactly one settlement; accounting rows retained; unconfirmed end visible as drift until the Stop arrives |
| **N2** | a billing period owns the accounting rows that **arrived after it started** (`Nuxr-Period-Acct-Id` = highest `radacctid` at the period start); the time rule (start - 10 s) is only a fallback for rows without an id; a malformed or out-of-range id fails closed; no schema change; health `nas_clock` |
| **N1 levers** | `nuxr_quota_interim_seconds` (10 - 300, default 60); enforcer retry 15 s after no answer / no Stop, 60 s after a NAK; one termination call per pass; a silent NAS is asked once and never claimed ended |
| Measurement | N1 matrix on the real NAS (section 5), CoA capability probes, analysis `N1_PPPOE_OVERSHOOT.md`, model tool `n1_overshoot_model.py` |
| Target readiness | `docs/V1_TARGET_ACCEPTANCE.md` (TA-01 .. TA-10 with an evidence table, all `NOT_RUN`); `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` I6 now `quota.php enforce --loop=55 --every=5`; `install/freeradius/README.md` capability table |
| Tooling | `closure_check.py` (36 items), `n1_report.py`, drill / load / UI / PWA tools unchanged |

---

## 4. Full security results

| Check | Result (candidate `e34906d6`) |
|---|---|
| CHR security gates (A2 concurrent first login, username equivalence PPPoE + Hotspot, shared users) | **OK, 10 tests / 216 assertions** |
| Option G, Port-Limit, equivalence and identity guards, A2-R race gates on the real NAS | inside `wp8-integration` (below): 0 failures |
| WP9 red-team and security suites (route / role / scope matrix, CSRF, session cookie, stored-XSS crawl over the whole route map, SRI, secret redaction and rendering, key separation, Smarty sandbox, installer post-install protection, autoload exposure, CSV injection, staff password storage, ledger CHECK constraints, grants, plugin inventory) | inside the full harness: **0 failures on both engines** |
| PWA history after logout (red-team row 15) | no leak, online and offline, then the next user |
| Secret sweep (7 known secret values + key markers) over **every tracked and untracked file** | **0 hits**; no secret on a command line or in evidence; no image, capture or customer data committed |
| PHP lint | 1386 tracked PHP files, 0 errors |
| Manifest `docs/claude-development-pack/MANIFEST.sha256` | 42 / 42 OK |
| SourceGuard pins | the Source Guard classes (Wp3a, Wp3b, Wp4, Wp5a, Wp9/U0) ran in the harness: 0 failures; no pin was changed in this phase |
| New CLI `quota.php` | CLI-only (404 through the web server, tested); runtime lock inventory guard covers `nuxr_quota_` |

Not claimed: a penetration test of a production deployment (TA-10), TLS / reverse-proxy behaviour on the target, jQuery 1.11.1 upgrade (R-JQ-1, owner scope D9).

---

## 5. GB quota and renewal guarantees

### 5.1 What the system guarantees

| Guarantee | Basis |
|---|---|
| A subscriber whose data or time quota for the period is exhausted cannot **start** a new session (fail closed: also for malformed, duplicate or unreadable quota rows) | FreeRADIUS counters + `Nuxr-Period-*`; harness both engines |
| A running session carries a native NAS limit for the **remaining** quota at login (Hotspot: total, enforced by the NAS; PPPoE: each direction) | CHR |
| A running session that passes the quota is ended by the accounting enforcer; the end is reported **only** when confirmed (Disconnect-ACK + Accounting-Stop, or NAK 503); ACK without Stop, NAK 406, silence, unknown NAS are reported as observed, never as ended | `V1QuotaEnforcerTest`, CHR |
| Usage never decreases and is never double counted (stale interim, duplicate Start / Stop, 64-bit Gigawords, wrapped 32-bit counters, several NAS summed) | harness |
| After a renewal the new period counts only usage that **arrived after** the renewal, independent of the router clock (N2); the previous session is ended and confirmed first (Option B); one settlement only | `V1PeriodOwnershipTest`, `V1RenewalOptionBTest`, CHR `V1QuotaLabTest` |
| No history is deleted: `radacct` is read-only to the enforcer, ledger / audit rows are never rewritten | every test asserts the rows unchanged |

### 5.2 The exact PPPoE guarantee (N1) - never "byte-exact"

RouterOS 7.24.5 **cannot** enforce a hard total for PPPoE, and a running session cannot be re-limited: measured on the CHR, `Mikrotik-Total-Limit` is ignored for PPPoE; per-direction `Recv/Xmit-Limit` is enforced; a CoA that changes the byte limit **or the interim interval** is ACKed and **not applied**. The only mid-session tool is Disconnect.

With R the quota remaining at login, r the subscriber's combined line rate, I the interim interval, P the enforcer pass interval, c the Disconnect time (<= 1 s normal, about 5 s with CoA retransmissions):

* overshoot <= **min( R , r x (I + P + c) )** while the enforcer and the CoA path work;
* overshoot <= **R** (total use <= 2 R) when the enforcer, the NAS link, FreeRADIUS or the database are down, because the native per-direction caps still hold;
* the NAS itself cuts 0.02 - 4 % past a per-direction limit;
* Hotspot: native total limit, overshoot is the NAS cut delay only (0.3 - 4.4 % measured, 0.2 - 0.7 s of line rate).

Measured on the real NAS (mixed upload + download PPPoE, lab rate about 1 Mbit/s per direction, enforcer `--every=5`; `evidence/quota/n1/chr/overshoot-table.md`; `V1OvershootLabTest` 10 tests / 219 assertions in its own run, and inside the 121 / 2323 run below):

| interim I | detection to confirmed Stop | note |
|---|---|---|
| 60 s | 20.8 - 21.3 s after crossing (bound 71 s) | |
| 30 s | 21.2 - 21.7 s (bound 41 s) | |
| 15 s | 5.3 - 5.4 s (bound 26 s) | |
| 10 s | 10.6 - 11.4 s (bound 21 s) | |
| 60 s, quota crossed before the first interim | cut by the NAS native cap, overshoot = the whole quota (100 %) | the weak case: quota small versus rate x interim |
| 15 s, CoA closed for 40 s | confirmed 38 s after crossing; retry <= 15 s after the path returned | no claim of termination while unconfirmed |

Percentages in the lab table are large because the lab quotas are 4 - 12 MB; the overshoot is **a property of rate x latency, not of the quota size**. Model for I = 30 s, P = 15 s with CoA retransmissions: a 25 / 25 Mbit/s plan can overshoot by at most about 312 MB (5.8 % of 5 GB, 1.4 % of 20 GB); 100 / 100 Mbit/s about 1.25 GB (23 % of 5 GB, 5.8 % of 20 GB, 1.1 % of 100 GB).

### 5.3 Accounting cost of a shorter interim (Interim-Update writes per second)

| online quota subscribers | I = 60 s | 30 s | 15 s | 10 s |
|---|---|---|---|---|
| 500 | 8 | 17 | 33 | 50 |
| 2 000 | 33 | 67 | 133 | 200 |
| 5 000 | 83 | 167 | 333 | 500 |

### 5.4 Wording the product may use

Allowed: *"Data quota is enforced by the network equipment and checked continuously; usage can exceed the quota by a few seconds of line speed."* Not allowed: "exactly X GB", any percentage, any claim that PPPoE is a hard cap. A figure, if the owner needs one, is stated per plan as seconds of line rate (I + P + c).

---

## 6. CHR evidence and limitations

| Suite (candidate `e34906d6`) | Result |
|---|---|
| `wp8-security-gates` | **OK, 10 tests / 216 assertions** (`evidence/release-candidate/chr/security-gates.out`) |
| `wp8-integration` (Option G, Port-Limit, equivalence, A2-R race gates, `V1QuotaLabTest`, `V1OvershootLabTest`, `RenameOldSessionLabTest`, N2 renewal) | **OK, 121 tests / 2323 assertions**, 13 mode skips (the stock-checkrad / SNMP-mode gates that need a different lab mode than the production contract; identical in every round; not counted as passed) (`chr/wp8-integration.out`, rows in `chr/lab-evidence-full.jsonl`) |
| Lab torn down afterwards | 0 VMs, 0 namespaces (`chr_down.sh`: `LAB_DOWN`) |

Limitations (the CHR is **not** proof for the target):
- RouterOS **7.24.5 on CHR only**; another version, board or a hardware router must repeat the capability probes (TA-04). The "CoA limits / interim not applied" findings may differ on other versions.
- Lab line rate is about 1 Mbit/s per direction; larger plans are extrapolated by the model (seconds carry over, bytes scale with the rate).
- One crossing phase per N1 case: the worst case is the model bound, not a measurement of every phase.
- Disconnect time c was <= 1 s in every run; a lossy WAN path to the NAS is not covered.
- No real PPPoE access network, no real subscriber CPE, no NAS carrying customer traffic.

---

## 7. MariaDB / MySQL acceptance

Full harness on the frozen candidate (`dev/test/run-tests.sh --do-not-cache-result --testdox`, snapshot worktree at `17da628a`, log `evidence/release-candidate/full-run-17da628a.log`):

| Engine | Result |
|---|---|
| MariaDB 10.11.19 | **OK: 1534 tests, 76558 assertions, 0 failures / errors / warnings**, PHPUnit deprecations 1, **124 incomplete** |
| MySQL 8.0.46 | **OK: 1534 tests, 76560 assertions, 0 failures / errors / warnings**, PHPUnit deprecations 1, **124 incomplete** |
| Variant registry reproducibility | fingerprints identical on both engines and identical to the previous round (V1 `3ca25964...`, V2 `6c2f04ea...`, V3 `e1a66aa3...`): no schema change |

The 124 incomplete are the real-NAS lab classes: they self-mark incomplete without the lab and are **not** counted as passes; they are the CHR runs of section 6. The assertion counts differ by 2 between engines as in every previous round (run-dependent counts, R-FC-8 / 9).

---

## 8. Finance and recovery evidence

- No change to ledger, wallets, pricing, settlement, schema or migrations in this phase.
- A session-end outcome never changes or repeats a settlement, recharge or grant. RENEW settles exactly once (`V1RenewalOptionBTest`: ledger delta equals the activation's; CHR: one debit per paid action). `SettlementReadModel::integrity()` is empty after every case.
- Concurrency (full harness, both engines): `W9CronRenewalRaceTest`, `ActivationReversalRaceTest`, `U0RaceTest`, concurrent renewal, voucher lifecycle, finance integrity - 0 failures.
- Bounded load (both engines, 12 per wave): distributed 96 / 96 completed, one wallet 96 / 96 completed, one customer 8 completed + 40 refused `ACTIVATION_IN_PROGRESS` (no double settlement); peak 15 of 151 connections (`evidence/release-candidate/load/`).
- Recovery: operator tools `reconcile.php` (finance-check, voucher-review / resolve, admission-pending / release), `quota.php session-end`, drift report, health (`quota_enforcer`, `nas_clock`, `admission_pending`, reservations); runbooks `docs/OPERATOR_RECOVERY_RUNBOOK.md` (4a / 4b lost Stop, held, CLAIMED_REVIEW).

| Failure injected (CHR / harness) | Result |
|---|---|
| CoA timeout / NAS silent | `UNKNOWN COA_NO_RESPONSE` after 1 + 2 identical sends; never claimed ended; retry 15 s; drift + health until the Stop arrives |
| Disconnect NAK 406 / 503 | 406 unconfirmed, 503 confirmed ("no such session") |
| Lost Stop / lost interim | row stays open, enforcer re-sends, health WARNING; native limit still holds; the row is never edited |
| FreeRADIUS down | running session keeps its NAS limit; new logins fail closed |
| NAS link down | enforcer UNKNOWN, nothing fabricated, CONFIRMED after the path returns |
| Enforcer stopped | health `quota_enforcer` CRITICAL while quota subscribers are online; native limits still apply |
| Invalid quota at sale | voucher / activation held (`CLAIMED_REVIEW`), nothing written to RADIUS |

---

## 9. UI / PWA acceptance

| Check | Result |
|---|---|
| UI audit (`dev/wp10/ui_audit.py`): desktop English 1366x800, phone Arabic RTL 390x844, tablet Arabic RTL 820x1180; roles SuperAdmin, Admin, Agent A, Agent B, Sales A1, Report + customer login | **136 page loads**; staff pages: no horizontal overflow, Arabic pages `dir=rtl`, axe wcag2a/aa serious + critical = 0; the new `services/add` and `services/pppoe-add` forms are clean in all three layouts |
| Flagged rows (11, identical to the previous round) | expected 403 denials (Agent `settings/app`, Sales `finance/overview`, Report role without customer / report capability) in three layouts; **customer login page**: theme button / link colour contrast and no RTL layout in Arabic (portal is outside the WP6C scope, decision D9) |
| PWA suite T00 - T24 (secure context, manifest, worker, offline gate, cross-user hygiene, updates, RTL, axe, CSRF / XHR transport) | **32 / 32 PASS** |
| History after logout | no leak (positive control present) |

Evidence: `evidence/release-candidate/ui/`. Screenshots were kept outside Git (no images in the repository). Not covered: real mobile devices and real browsers' installation prompts on the production origin (TA-10).

---

## 10. Backup / restore / rollback

- WP10 drill on both engines against the candidate: dump, restore, CHECK constraints survive, migration rollback - **1 test / 128 assertions each, exit 0** (`evidence/release-candidate/drill/`). Dumps were made with the engine's own client inside the DB container (password never on a command line) and stayed outside Git.
- No schema change in this phase, so the migration path is unchanged.
- Rollback of the V1 quota feature = redeploy the previous commit and the previous `install/freeradius` files; the rows V1 writes are ordinary RADIUS attributes (`Max-Data`, `Max-All-Session`, `Nuxr-Period-Start`, `Nuxr-Period-Acct-Id`, `Acct-Interim-Interval`); a period provisioned before N2 keeps the time rule until it ends (E-N2-2).
- Runbooks: `docs/BACKUP_RESTORE_RUNBOOK.md`, `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md`.
- **Not run:** an application-code rollback rehearsal and a restore from the target's own backup chain (TA-08).

---

## 11. Staging and capacity evidence

- Bounded load: section 8 (both engines, PASS). The tool is paced and bounded; the SQL pool exhaustion test was never run (forbidden).
- Accounting write rate at the chosen interim: section 5.3 (model); the FreeRADIUS SQL pool sizing guidance is in `install/freeradius/README.md`.
- **There is no staging environment in this lab.** The numbers above are from a 12 vCPU shared development host with a throwaway database; they prove behaviour and bounded correctness under concurrency, **not** production capacity (AV-2 / REL-04: TA-09).

---

## 12. Target-environment items not yet executed

Every row is `NOT_RUN` in `docs/V1_TARGET_ACCEPTANCE.md` (procedures, pass criteria and the evidence table). None may be marked `PASS` from lab evidence.

| ID | Test | Why it cannot be run here |
|---|---|---|
| TA-01 | Preflight + health on the target (UTC, skew, grants, CHECK enforcement, release profile) | needs the target database / hosts |
| TA-02 | Clock discipline: NTP + UTC everywhere; drift <= 5 s app / db / FreeRADIUS, <= 60 s NAS | needs the real hosts and NAS |
| TA-03 | NAS contract on the target RouterOS version / model (`/radius incoming`, `/ppp aaa`, no local `/ppp secret` shadowing) | needs the staging NAS |
| TA-04 | Quota capability probe (limits, interim, Disconnect) on the target NAS | RouterOS version / board dependent |
| TA-05 | GB overshoot (N1) at the target line rate, chosen interim and `--every` | needs target rates |
| TA-06 | Renewal Option B on the target, incl. re-dial inside 10 s | needs the target |
| TA-07 | Failure injection on staging (enforcer stop, CoA block, accounting stop) | needs the target |
| TA-08 | Deploy / upgrade / rollback and restore from the target backup chain | needs the target |
| TA-09 | Capacity on target hardware (incl. interim write rate against the FreeRADIUS SQL pool) | needs target hardware |
| TA-10 | Security on the target (TLS, headers, cookies, rate limits behind the real proxy, least-privilege accounts, installer unreachable) | needs the target |

Also target-only: OBS-1 identity survey and P-A4-3 historic `radacct` zone survey on **real** data; ISO-01 across two real instances; NAS onboarding against the ISP's routers; legal review LIC-1.

Preconditions the operator must hold first (`docs/V1_TARGET_ACCEPTANCE.md` P1 - P5): written owner authorization naming the staging NAS, database and window; no customer traffic on that NAS; a different RADIUS secret from production; backup and tested restore; synthetic identities only; secrets only in the environment / 0600 files.

---

## 13. Consolidated non-delegable decisions (one request)

### 13.1 Product / commercial

| # | Decision | Options | Recommendation |
|---|---|---|---|
| **N1** | PPPoE data-quota overshoot semantics (section 5.2, `N1_PPPOE_OVERSHOOT.md`). No percentage and no customer wording was approved by the engineering side | **A** keep native per-direction caps + accounting enforcer (bound `min(R, r x (I+P+c))`, never byte-exact); **B** directional split for a native hard total at 5 - 60 forced reconnects per period; **F** strict quotas on Hotspot only; **G** the overshoot is not charged and not carried (commercial policy; combines with A) | **A** with `nuxr_quota_interim_seconds` = 30 and `--every=5` for up to about 2 000 simultaneously online quota subscribers (67 Interim-Updates/s; keep 60 above that unless TA-09 shows headroom); wording "a few seconds of line speed"; B only if a hard PPPoE total is required |
| **N2** | Accept the implemented period ownership (arrival order) and make clock discipline a go-live gate: UTC + NTP on every host, drift <= 5 s app / db / FreeRADIUS, <= 60 s NAS (health warns > 120 s ahead) | accept / require more | accept; TA-02 as a go-live gate |
| N3 | An exhausted data quota is refused with FreeRADIUS's stock text ("Your maximum never usage time has been reached") | leave / change the policy text | cosmetic, no action unless wanted |
| D8 | R-FC-1 voucher codes appear in financial fields (masking or design change); P2 RENEW Session-Timeout product choice (settled by Option B) | mask / leave | owner |

### 13.2 Risk and acceptance

| # | Decision | Recommendation |
|---|---|---|
| **D1** | Formal **WP9 acceptance** (and then WP10): the ledger rows are as the owner left them, never marked ACCEPTED here | owner, on this evidence |
| **D2** | Reclassify S-1 A2-R from `UNVERIFIED_SECURITY_RISK` after the defensive layers L1 - L5; the exhaustion reproduction was never run and stays forbidden | owner |
| D4 | AV-1: FreeRADIUS stall leaves an orphan PENDING claim (WP8 options D1 / D2 / D3; D1's tooling and monitoring exist) | owner |
| D5 | Time contract C-05: canonical text (the preflight already enforces UTC and skew) | owner |
| D6 | AD-18 secret boundary: `nas.secret` stays recoverable by necessity (RADIUS signs with it); redaction and the documented boundary are done | owner |
| D7 | OBS-1: resolution method for real duplicate identities (the preflight FAILs on any multi-holder key; engineering must not decide who keeps a customer's key) | owner + an operator run on an approved copy |
| D9 | R-JQ-1 jQuery 1.11.1 upgrade; customer-portal RTL / contrast | owner scope |
| D10 | LIC-1 legal review (GPL version, Smarty LGPL-3.0, delivery model); `docs/THIRD_PARTY_LICENSES.md` | counsel |

### 13.3 Authorizations that do not exist yet

- Production deployment of any kind; use of a real staging NAS and database (TA-01 .. TA-10); changes to live routers or live secrets; changes to real customer records; push or merge of `wp9/red-team-rc` into a protected branch.

---

## 14. Remaining blockers and exact next actions

There is **no open engineering defect**. The blockers are decisions and target evidence:

| # | Blocker | Owner | Exact next action |
|---|---|---|---|
| 1 | Section 13 decisions (N1, N2, D1, D2, D4 - D10) | owner / counsel | answer section 13 in one record; record WP9 / WP10 acceptance in the ledger |
| 2 | Target preconditions P1 - P5 | owner | provide the written authorization naming the staging NAS, database and window |
| 3 | TA-01 .. TA-10 | operator on the approved staging copy | run the procedures in `docs/V1_TARGET_ACCEPTANCE.md`, fill the evidence table with files; any `FAIL` or `NOT_RUN` keeps the status at `RELEASE_BLOCKED_WITH_EVIDENCE` |
| 4 | OBS-1 / P-A4-3 surveys on real data | operator | run `dev/wp9/identity_survey.sql` and the `radacct` zone survey on an approved copy; owner chooses the resolution method (D7) |
| 5 | LIC-1 | counsel | legal review |
| 6 | Integration of the branch | owner | decide when and how `wp9/red-team-rc` is pushed / merged (nothing was pushed) |

If section 13 is answered with the recommendations, the engineering change is one constant (`nuxr_quota_interim_seconds` = 30) plus the cron line `quota.php enforce --loop=55 --every=5`. Any other answer (notably B for N1) is a design change and needs a new plan; if product code changes after this freeze, the affected gates and the final suites must be rerun.

Operating notes for whoever continues: `owner-review/WP10/HANDOFF_V1_CONTINUOUS.md` (checkpoint, lab hygiene), `owner-review/WP10/DECISIONS.md` (engineering decisions E-N2-1 .. 4, E-N1-1 / 2), `owner-review/WP9/AUTONOMOUS_PROGRESS.md` (ledger of rows). The CHR lab and the focused stack are down; the compose project `nuxbill-reseller-test` has no running container. Google Drive and Basic Memory were **not** updated by this package (nothing was sent to an external service).
