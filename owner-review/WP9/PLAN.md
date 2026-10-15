# WP9 — Security Preflight & Red-Team Plan (revision 3)

| Item | Value |
|---|---|
| Owner decisions | **Rev 1:** `WP9_PREFLIGHT_AND_PLANNING_AUTHORIZED` (2026-10-15).<br>**Rev 2:** `WP9_PREFLIGHT_REVIEWED — AMENDMENTS_REQUIRED` (2026-10-15). OD-W9-1…11 are decided (§0), and the owner asked for amendments to A2-R, OBS-1, the plugin boundary, the findings, the owner policies and the cross-review record.<br>**Rev 3:** `WP9_PLAN_REV2_ACCEPTED_WITH_AMENDMENTS`, `WP9_T0_STATIC_TESTING_AUTHORIZED`, `PRODUCTION_NOT_AUTHORIZED` (2026-10-15). Mandatory corrections AM-W9-1 (identity-lock ordering), AM-W9-2 (test criterion), AM-W9-3 (voucher namespace); decision status D-W9-R1…R13 (§18) |
| Canonical baseline | `feat/reseller-system-v1@704031c61296f4975f1d4e51531973d67e5f0a9b` |
| Method | Ali Design System for Software Development. Stage: **PLAN (revised)**. Nothing is executed, tested or fixed |
| Nature of this file | Plan only. **It contains no test results.** `owner-review/WP9/REPORT.md` is not started. Every finding is a **static code-reading result**: none is a proven exploit, and none is proven safe (evidence levels §4.1) |
| Revision log | **Rev 3:** §7.3 lock ordering redesigned (AM-W9-1); "no DB delta" replaced by the AM-W9-2 criterion everywhere; new §7.7 voucher namespace (AM-W9-3); §18 decision status; T0 execution mode (§13).<br>**Rev 1:** first plan, plus 14 cross-review corrections (log §17).<br>**Rev 2:**<br>• owner decisions recorded (§0);<br>• A2-R split into observations, hypotheses, exploitability, design and missing evidence (§6);<br>• OBS-1 invariant and separate uniqueness options (§7);<br>• plugin trust boundary (§8);<br>• NEW-7 / NEW-8 option studies (§9);<br>• owner-policy option matrix (§10);<br>• full N-01…N-17 table (§4);<br>• test plan staged under OD-W9-3 (§13);<br>• list of files and contracts that may change later (§15) |
| Final state | Plan rev 3 for the T0 round; T0 results only in `REPORT.md` (T0 section) and `evidence/t0/` |
| Status note (2026-10-15, after the rounds; history above unchanged) | **T0 was executed** (8/8 static checks PASS, 2 NOT TESTED; owner `WP9_T0_STATIC_ACCEPTED_WITH_NOT_TESTED`). **T0+** (PHP pure-unit / source-guard, owner `WP9_T0_PHP_COMPLETION_AUTHORIZED`) and **T1-A** (OBS-1 / voucher characterisation, owner `WP9_T1A_CHARACTERIZATION_AUTHORIZED_CONDITIONALLY`) were executed; T1-A **stopped at an owner stop trigger** (V1). Results only in `REPORT.md` §T0+ / §T1-A. The plan text below is the approved rev 3 and is not rewritten |

---

## 0. Owner decisions recorded (2026-10-15) and what still needs approval

| ID | Owner decision | Effect on this plan |
|---|---|---|
| **OD-W9-1** | **A:** local branch `wp9/red-team-rc`, created from the canonical baseline only, **no push** | Created 2026-10-15 at `704031c6`, with no upstream (§1). The WP9 files stay untracked until a commit is authorized |
| **OD-W9-2** | **Design only:** study CHECK constraints and SQL echo validation, and their MariaDB / MySQL impact. **No schema change before approval** | §6.5 is a design study, not an implementation plan |
| **OD-W9-3** | **Static / inert only.** No SQL-exhaustion reproduction, no pressure, no fault injection, and no simulation that leads to the halted experiment. Unit tests that do not connect to services are allowed, **in the test plan only** for now | A2-R tests are restricted to static and unit tests (§6.6). Rev-1 A-T2 is deferred to a separate decision; A-T3 is **withdrawn** |
| **OD-W9-4** | **Proposed application-level identity protection**, with research into concurrency and historical data. **No implementation now** | §7 |
| **OD-W9-5** | **Test-first, then owner-approved bounded remediation waves** | §11 |
| **OD-W9-6** | **OWNER_POLICY_PENDING:** an options matrix with conservative recommendations; no permission change before a decision | §10 |
| **OD-W9-7 / 8** | **SECURITY_REMEDIATION_REQUIRED_BEFORE_PRODUCTION:** study the options and compatibility first | §9 |
| **OD-W9-9** | **Plugin / public-method trust-boundary study**, separating unauthorized entrypoints, arbitrary code execution and direct DB authority | §8 |
| **OD-W9-10** | **CHR TEST PLANNING APPROVED ONLY** | CHR rows are planned and never run in this stage (§13) |
| **OD-W9-11** | **WP9 = bounded security concurrency; WP10 = capacity, load and long-duration testing** | §2, §5 |

**U0 status note (2026-10-15, cumulative; rev 3 text below unchanged):** owner decisions OD-W9-A1-1…4 (V1 interim acceptance, U0 design study, N-07 voucher-code logging, deferrals), then `WP9_U0_FULL_IMPLEMENTATION_AND_TESTING_AUTHORIZED` with D-U0-1…8 APPROVED. U0-0…U0-5 executed test-first on `wp9/red-team-rc` (local commits only); results, residual blockers and new decisions in `REPORT.md` §U0 and `U0_PROGRESS.md`. `PRODUCTION_NOT_AUTHORIZED`; OBS-1 remains a production blocker until the owner rules on the §U0 evidence.

**Rev 3 owner decisions (2026-10-15):** plan rev 2 accepted with amendments AM-W9-1…3; **T0 static testing authorized** (A2-R statement inventory; A2-R sentinel / rejection decision table; SourceGuard-oriented analysis; no-shell and ledger-writer scans; CSV formula / escaping pure-unit cases; NEW-7 key-use inventory; Smarty static-call / configuration-exposure inventory) under strict conditions: no database connection, no Docker container, no FreeRADIUS process, no MikroTik / CHR, no HTTP / PWA lab, no SQL exhaustion / pressure / fault / simulation, no product-code change, no schema / migration / SourceGuard re-pin. One local commit of `PLAN.md` + `WP9_HANDOFF.md` authorized after a diff audit and a secret sweep; no push.

**Still needing owner approval (§18):**
- execution of any test stage (T0–T4) — *status 2026-10-15: T0, T0+ and T1-A (stopped) executed after their approvals; T1-B…T4 not approved*;
- every remediation wave;
- the A2-R hardening scope (OD-W9-2 implementation), and the rev-1 A-T2 DB-level constraint test;
- the OBS-1 implementation, and any uniqueness option U1–U4;
- the five policy choices of OD-W9-6;
- the NEW-7 and NEW-8 options;
- the plugin trust policy;
- CHR execution;
- any SourceGuard re-pin;
- any commit or push.

---

## 1. Verified baseline (read-only, 2026-10-15)

| Item | Observed | Result |
|---|---|---|
| `origin/feat/reseller-system-v1` | `704031c61296f4975f1d4e51531973d67e5f0a9b` | = canonical baseline |
| `origin/wp8/integration-poc` | `704031c6` | same commit (WP8 merged by fast-forward) |
| `origin/main`, `wp7/staff-pwa`, `wp6c/mobile-adaptive-ux`, `hotfix/pace-xhr-csrf` | `500d3a2d`, `8e7f0e1c`, `757d8de9`, `6d4f3f96` | unchanged |
| Ancestry | descends from the previous feat `5bdc5745`; upstream ancestry `hotspotbilling/phpnuxbill@d3e05962` | OK |
| Remote tags / remote WP9 branches | none / none | OK |
| **Local branch (OD-W9-1)** | `wp9/red-team-rc` created at `704031c6` with `git switch -c` from a clean worktree. It has **no upstream**. The untracked WP9 files were kept (SHA-256 identical before and after; a backup copy is in the session scratchpad) | OK |
| Worktree | clean except `owner-review/WP9/` (untracked) | OK |
| Ledger | WP0–WP8 owner-accepted: WP3B `ACCEPTED`, WP6A `ACCEPTED_WITH_NOTE`, all others `ACCEPTED_WITH_NOTES` (WP8 at `44ea45d4`). WP9 / WP10 PENDING | DoR: previous WP accepted |
| Architecture Lock | local branch `docs/owner-architecture-lock-2026-10-09` @ `8b0ee336`, read with `git show` only | read-only |
| Last full dual-engine harness | S7 on `b96747b5`: 1129 tests / 69661 assertions / 104 incomplete per engine, exit 0 (WP8 `OWNER_DECISIONS.md:683`). Since then only docs, comments and the accepted drill 8 test fix changed | reference |
| Lab | no QEMU process and no lab containers (S8 teardown) | clean |

**Sources read, in the mandatory order:**
- **Spec pack docs:** `START_HERE.md`, docs 00–18, `DECISIONS.md`, `MODULES.md`, `DEFINITION_OF_READY.md`, `DEFINITION_OF_DONE.md`, `15_CLAUDE_RULES.md`, `16_HANDOFF_PROTOCOL.md`, `17_REFERENCE_FINDINGS.md`.
- **Review, audit and prompts:** `review/OWNER_REVIEW_CHECKLIST.md`, `review/ACCEPTANCE_LEDGER.md`, `PACK_AUDIT.md`, `prompts/WP9_RED_TEAM_RELEASE_CANDIDATE.md`, `prompts/WP10_RELEASE_HANDOFF.md`.
- **WP8 records and models:** WP8 `REPORT.md` / `OWNER_DECISIONS.md` / `H2B_OWNER_ACCEPTANCE_RECORD.md`, `docs/WP8_INTEGRATION_MODEL.md`, `docs/GAP_ANALYSIS.md` §13.
- **Architecture Lock:** `OWNER_ARCHITECTURE_LOCK.md` / `IMPLEMENTATION_ROADMAP.md` / `RISK_REGISTER.md`.
- **Earlier decision records:** all WP0–WP7 and hotfix records (WP9 / WP10 items).

The code at HEAD is the execution reference.

**Spec vs code notes:**
- The pack prompt names `hotspotbilling/phpnuxbill`. Execution is on the fork `lucefardeavel/phpnuxbill-reseller`, as for WP0–WP8 (owner decision).
- The ledger WP8 row says "feat not changed or fast-forwarded". That was true when written and is stale after the owner's merge. WP9 does not edit the ledger.

---

## 2. Scope and non-goals

**In scope:**
- every attack area of the WP9 prompt (21 areas + REPORT) and of `14_WORK_PACKAGES.md` WP9;
- the priority blockers: A2-R SQL exhaustion (study and design) and OBS-1;
- the WP9-bound inherited items (§5);
- regression tests that the closed SEC-01…25 items stay closed;
- **bounded security concurrency:** real parallel workers and HTTP stampedes on disposable databases (OD-W9-11);
- `REPORT.md`, written only after tests run.

**Non-goals:**
- **WP10 operations:**
  - backup / restore drill, runbooks, monitoring / alerting build-out, deployment, RTO/RPO;
  - ISO-01 drill, deployment of least-privilege grants, PENDING-recovery runbook;
  - **capacity, load and long-duration testing** (OD-W9-11).
- new features;
- reopening S1–S7 / A1–A5 without a new proven defect;
- production, real MikroTik, merge, tag, push or deploy;
- **the halted A2-R SQL-exhaustion reproduction, under any name, method or simulation (OD-W9-3);**
- any permission change before the OD-W9-6 decisions.

---

## 3. Architecture Lock constraints

1. **WP9 covers the lock's Phase B and Phase E inputs without expanding them:**
   - R-HF-CARRY-01;
   - plugin / direct-device authority red team;
   - verifier secret boundary;
   - least-privilege DB access, **tested and reported** only;
   - RouterOS management-transport review;
   - fail-open / fail-closed review;
   - concurrent-first-login and old-identity rename regressions;
   - secret / redaction review;
   - accounting time tests;
   - CoA / Disconnect failure matrix;
   - carry-forward closure.
2. **No architecture change:** modular monolith; Option A per-ISP isolation; no `tenant_id`; no Redis, broker, queue, HA or service extraction.
3. **Release gates as recorded** (ledger WP8 row; WP8 REPORT §5 #29, §7.3):
   - PENDING: Q1–Q4, P1–P3, N6–N7, REL-01 / 04 / 05, ISO-01;
   - CONDITIONAL: REL-06;
   - partly proven: N1, N3, REL-02;
   - proven in the lab only: N2, N4, N5, REL-03.
   - WP9 never closes a gate by documentation.
4. **PROVEN** applies only to the exact NAS / version / runtime tested. Lab ≠ production.
5. **The lock folder is not edited.** Lock reconciliation (D-S8-4) stays separate.

---

## 4. Findings register N-01 … N-17

### 4.1 Evidence levels and columns

**Evidence levels:**
- **E1 CODE-CONFIRMED:** the code shape at the cited lines was read at HEAD by me and, independently, by the cross-review. It states what the code does, **not** that an attack works.
- **E2 RECORD-CONFIRMED:** stated in an owner / WP record; not re-proven in WP9.
- **E3 HYPOTHESIS:** an inference about runtime behaviour or exploitability, drawn from code reading.
- **E4 TEST-PROVEN:** **none yet** in WP9.

**Severity** is my assessment (a proposal) unless marked "(recorded)".

**Production impact:**
- **Blocker:** an existing owner-classified production blocker row.
- **Must fix before production:** owner decision OD-W9-7/8.
- **Assessed:** my proposal for owner review.

### 4.2 Complete table

| ID | Finding | Source path(s) | Evidence level | Exploitability today | Severity | Production impact | Register link | Phase |
|---|---|---|---|---|---|---|---|---|
| **N-01** | **Public device-grant methods have no guard:**<br>• `Radius::customerAddPlan()` is public, has no `assertDeviceAllowed`, and `''` means no end time;<br>• `remove_customer()` provisions whatever `plan_expired` the caller supplies;<br>• MikroTik public helpers (`addPpoeUser`, `addHotspotUser`, `setHotspotUser*`, `addVpnUser`) are unguarded, but need an already-connected RouterOS client | `system/devices/Radius.php:72-84, 278-283`; `system/devices/MikrotikPppoe.php:370`; `MikrotikHotspot.php:291, 359, 376`; `MikrotikVpn.php:335` | E1 (no core caller of `customerAddPlan` in `system/` or root PHP); exploitation E3 | needs either in-process code (a SuperAdmin-installed plugin), **or** an Admin who sets a full plan as another plan's `plan_expired` and lets cron expire an owned customer. **No unprivileged HTTP entrypoint found** | **MEDIUM** (privileged / trusted-code precondition; settlement bypass for owned customers) | Blocker via #21 (Phase B), not separately | #21, lock R-015 | WP9-B |
| **N-02** | **Plugins run before every gate:** plugin files are included inside `init.php` on every entrypoint, before `RouteGuard`, `ApiGuard` and `RestGate`. Their errors are swallowed silently, and `run_hook` returns after the first match | `init.php:110-119`; `radius.php:22-24`; `system/autoload/Hookers.php:48-57`; `system/autoload/Reseller/Security/PluginRegistry.php` | E1 | plugin install = SuperAdmin (`SYSTEM_CODE`, `RouteMap.php:298-301`). The code shape matters only if an installed plugin is malicious or faulty | MEDIUM-HIGH | Blocker via #21 | #21, R-015 | WP9-B (§8) |
| **N-03** | **Customer login cookie:**<br>• format `uid = id.time.sha1(id.time.db_pass)`, compared with loose `==`;<br>• a **future** time stays valid until t+30 days;<br>• the token is also accepted from `?uid=`, and magic links are valid ~59 days;<br>• the cookie has no HttpOnly / Secure / SameSite flags and is not bound to the password | `system/autoload/User.php:11-27, 169-195`; `system/boot.php:96-98`; links in `system/autoload/Message.php`, `system/controllers/message.php`, `system/autoload/Invoice.php` | E1 (code shape). Forgery after a `db_pass` leak: E3. `0e` loose-compare path: E3 (theoretical, impractical online) | requires knowledge of `db_pass` (from a backup, config or container leak), or a leaked URL token (logs, referrer) | **HIGH** (conditional on that precondition) | **Must fix before production** (OD-W9-7, NEW-7) | NEW-7, NEW-6, KEY-SEP | WP9-D (§9.1) |
| **N-04** | **Cron vs renewal race:** cron selects expired rows without a lock, decides on the stale `$ds` expiry, re-reads, calls `remove_customer`, then saves `status='off'`. It shares no lock with `ActivationService` RENEW | `system/cron.php:48-99`; `system/autoload/Reseller/Activation/ActivationService.php:283-312, 414-445` | E1 (no shared lock); race outcome E3 | timing: RENEW and expiry naturally coincide at the period end, because renewals happen near expiry | **HIGH** (a paid renewal could end without service, or with the recharge `off`) | **Blocker** (row #19, "not proven") | #19 R-4 | WP9-B |
| **N-05** | **Replay without re-authorization:** a same-actor replay of an idempotency key returns at `ActivationService::open` before `revalidateActor` / `authorizeCustomer`. `execute()` then drives a `FUNDS_RESERVED` / `RETRY_PENDING` / `VERIFIED` activation forward.<br>A **frozen** actor is refused earlier by `assertEligible` (`:60`, `:121-137`; frozen is re-derived each request), except in the window between context build and `open()` | `ActivationService.php:56-76, 121-137, 214-223, 283-312` | E1 | needs the same actor, customer, plan and action; funds were reserved at authorization time | MEDIUM (policy) | Assessed: owner policy (OD-W9-6 P1) | ACT-RT | WP9-B |
| **N-06** | **CLI reconcile can act on live activations:** `reconcile --stale-seconds=N` accepts 0, which bypasses the 300 s in-flight guard. CLI `resume` has no stale check | `system/reseller/reconcile.php:47-54, 77-86`; `system/autoload/Reseller/Activation/ReconciliationService.php:63-70` | E1; free-service outcome E3 | server shell access only (operator) | LOW-MEDIUM (operator safety) | Assessed: not blocking; runbook item (WP10) | ACT-RT | WP9-B |
| **N-07** | **Legacy customer exports are not escaped:**<br>• `customers/csv` and `csv-prepaid` build rows with `echo '"' . implode('","', $row)`. There is no escaping at all, so both quote / field injection and formula injection are possible;<br>• the list export uses `fputcsv` without formula neutralisation;<br>• the WP6B `Format::csvCell` misses a leading space / newline before `=` | `system/controllers/customers.php:26-83, 87-153, 1052-1068`; `system/autoload/Reseller/Ui/Format.php:117-122` | E1 (no escaping); execution in a spreadsheet E3 (depends on the viewer's spreadsheet and its warnings) | needs an attacker-controlled `fullname` / `address` (Agent or Sales on create / edit; customer profile edit) that reaches an Admin export (`CUSTOMER_EXPORT` = global scope, `RoleCapabilityMap.php:26`) | **LOW-MEDIUM** (client-side; quote injection also breaks export integrity) | Assessed: fix before production recommended, not a blocker. **2026-10-15 addition (OD-W9-A1-3):** voucher codes in clear in the server log (`VoucherRedemptionService::record()` `target=` for PUBLIC / RADIUS voucher-only redemptions; unused-redeemable or live-credential codes) — LOW-MEDIUM where server logs are readable beyond host operators, LOW otherwise; REPORT §A1.9 | — | WP9-D |
| **N-08** | **Constraint integrity is not checked:**<br>• the migration verifier compares CHECKs by name (WP1-CHECK);<br>• runtime `SchemaReadiness` checks no constraints at all;<br>• `NOT ENFORCED` (MySQL) and session `check_constraint_checks=0` (MariaDB) are not detected | `system/reseller/Migration/SchemaInspector.php:26-27, 235-245` (SHA-pinned); `system/reseller/Migration/Runner.php:652-657` (pinned); `system/autoload/Reseller/Domain/SchemaReadiness.php:60-120` | E1; loss of the invariant after tampering E3 | needs DDL privilege, or in-process code on the app connection (session variable) | MEDIUM | **Ledger condition: WP1-CHECK must be closed before WP9 acceptance** | WP1-CHECK, TAMPER | WP9-D |
| **N-09** | **Reservations never expire, and nothing alerts** (`stale()` is report-only) | `system/autoload/Reseller/Finance/ReservationService.php:31-32`; `ReconciliationService.php:229` | E1 (by design) | stranded UNKNOWN reservations hold credit until an Admin acts | MEDIUM (availability) | Assessed: WP10 alerting | R-021 | shared WP9 test / WP10 |
| **N-10** | **The API returns every controller `$ui` assign** after only a key-name sanitizer. A redaction done only in a template would leak | `system/api.php:111-112`; sanitizer `init.php:286-297` | E1 (shape); any actual leak E3 | needs an API token with an allowed route | MEDIUM if a leak exists | decided by test | — | WP9-C |
| **N-11** | **Secrets visible in UI and missed in logs:**<br>• the router API password is rendered in clear (`value="{$d['password']}"`, SYSTEM_ADMIN);<br>• RADIUS post-auth passwords are viewable;<br>• `ErrorReporter::secretValues` omits `$radius_rest_secret`, NAS secrets and router passwords | `ui/ui/admin/routers/edit.tpl:48-49`; `ui/ui/admin/logs/radius.tpl:56, 68`; `system/autoload/Reseller/Security/ErrorReporter.php:101-119` | E1 | Admin screens / logs | LOW-MEDIUM | Assessed: input to gate N6 | R-012 | WP9-D |
| **N-12** | **`autoload/customer_is_active/<own>/<anyPlanId>`:** when `check_customer_online = yes` and the plan's device has `online_customer`, the server opens a RouterOS API connection to that plan's router. `bw_name` / `plan_is_active` echo names unescaped | `system/controllers/autoload.php:34-37, 114-181` (precondition `:115`, `:151`) | E1; use as an oracle E3 | Agent / Sales with `CUSTOMER_READ`; depends on configuration | LOW-MEDIUM | with Blocker row #20 | #20 R-HF-CARRY-01, R-011 | WP9-B |
| **N-13** | **A2-R has no CHECK constraints and no echo validation.** Three hypothesized empty-expansion paths F1–F3 (§6) | `install/freeradius/sites-available/phpnuxbill` post-auth `:209-489`, accounting `:96-101`; `install/freeradius/nuxr_admission.sql:26-47` | E1 (defences absent; statement shapes). F1–F3 **E3**. Exploitability **unverified** | requires FreeRADIUS SQL pool starvation (the halted scenario, never retried) | part of #2 (recorded: `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`) | **Blocker** (#2, unchanged) | #2, R-001 / R-007 | WP9-A (§6) |
| **N-14** | **Creation paths do not enforce identity uniqueness:**<br>• `CustomerService::create` checks `username = ?` only and inserts `pppoe_username` unchecked (Admin / Agent / Sales, web and API);<br>• public `register.php` does check-then-insert, on username only, with no lock | `system/autoload/Reseller/Domain/CustomerService.php:58-76`; `system/controllers/register.php:83-96` | E1. Cross-tree overwrite on activation: E2 (A3 report §6) / E3 (no dedicated test) | Agent / Sales choose names at creation; public registration, unless disabled | part of #1 (recorded HIGH) | **Blocker** (#1, unchanged) | #1, R-014 | WP9-A (§7) |
| **N-15** | **Policy points (no defect asserted):**<br>• an Agent can re-enable a descendant that an Admin disabled;<br>• an Agent can reset a descendant's password;<br>• an Agent can re-assign the Sales of a child Agent's customer;<br>• activation history visibility after an ownership transfer | `system/autoload/Reseller/Domain/StaffService.php:198-229`; `system/autoload/Reseller/Security/RoleCapabilityMap.php`; `CustomerService.php:95-118`; `ActivationService.php:544-562` | E1 | authority boundaries by design | owner policy | OD-W9-6 | — | §10 |
| **N-16** | **Stock Simultaneous-Use verification (checkrad) fails open.** That much is documented. That `session { sql }` also fails open **on SQL error** rests only on a cached upstream source | site comment `:174-175`; `docs/WP8_INTEGRATION_MODEL.md` §4 | checkrad part E2; SQL-error part E3 (secondary source) | — (A2-R / Option G are the defences) | INFO | not blocking | — | record only |
| **N-17** | **Unproven safety claim in the shipped README.** It states, as fact, that each worker holds at most one connection, so `pool.max >= max_servers` "removes pool exhaustion", and that exhaustion yields Reject, "never Accept". The H-2B lab mechanism (each `%{…}` escaped on its own connection) contradicts that premise | `install/freeradius/README.md:235-238, 262-263`; `owner-review/WP8/evidence/h2b-impl/H2B_IMPLEMENTATION_REPORT.md` §9.3 | E1 (the statement exists); its truth E3 / unverified | operators rely on it for sizing | MEDIUM (it documents a blocker risk as solved) | part of #2 | #2 | WP9-A (doc correction) |

### 4.3 Review of the six owner-named classifications

| Finding | Code-confirmed (E1) | Not proven (E3) | Classification after review |
|---|---|---|---|
| Cookie / NEW-7 (N-03) | loose `==`; a future time accepted; `?uid=`; no cookie flags; `db_pass` used as the key; not bound to the password | offline forgery (needs `db_pass`); the `0e` magic-hash path (impractical) | HIGH, conditional; **must fix before production** |
| Public Radius grant (N-01) | public, unguarded, no core caller; `plan_expired` trusted | that a plugin or an Admin configuration would actually be used this way | **lowered from HIGH to MEDIUM** (privileged / trusted-code precondition); stays inside Blocker #21 |
| Legacy CSV (N-07) | no escaping in two exports; no formula neutralisation in three | formula execution in the viewer's spreadsheet | LOW-MEDIUM; quote injection also breaks export integrity |
| Cron / renewal race (N-04) | no shared lock; decision on a stale value | the interleaving and its outcome | HIGH; Blocker #19 unchanged |
| CHECK validation (N-08) | by-name comparison; readiness ignores constraints | real tampering scenarios | MEDIUM; ledger condition for WP9 acceptance |
| NEW-8 Smarty (§9.2) | no security policy; no autoescape; `$_c` (the whole config) assigned to every template; page reset fetches GitHub `master` | code execution through a template write (needs SuperAdmin, a plugin, or the remote source) | MEDIUM (privileged precondition, plus a supply-chain path); **must fix before production** |

**Low items kept as test cases only:**
- `ConfirmToken` has no in-session expiry and treats null as `''`;
- lax `intval` in `assignLegacyOwner` (Admin only);
- `deviceOwned` returns "not owned" on error 1054. This is intentional: if the column is absent, no owned customers exist;
- `ServiceGate::isBlockedCustomerId` returns "not blocked" for id ≤ 0 (username paths use `isBlockedUsername`);
- `nas.ports` is reused as the CoA port;
- CoA replies without Message-Authenticator are accepted (allowed by the RFC);
- the PWA sends no `Clear-Site-Data` and no explicit `Cache-Control`.

---

## 5. Consolidated inherited risk register

Statuses are copied from the records. **This plan closes nothing.**

### 5.1 WP8 production blocker register — all 31 rows **open** (`owner-review/WP8/REPORT.md` §5)

| # | ID | Severity (recorded) | WP9 treatment | Phase |
|---|---|---|---|---|
| 1 | OBS-1 / L-A5-2 | PRODUCTION BLOCKER, HIGH | §7 | WP9-A |
| 2 | A2-R SQL exhaustion | UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER | §6; reproduction never retried | WP9-A |
| 3 | AV-1 | open production release risk; lock R-005 HIGH | test-only shape check | WP10 (visible) |
| 4 | PENDING recovery | separate owner decision | — | WP10 / owner |
| 5 | AV-2 | open; REL-04 | sizing contract recorded; measurement in WP10 (OD-W9-11) | shared |
| 6 | AV-3 | open | test + design | WP9-D |
| 7 | H-2 follow-ups | production blocked | — | WP10 |
| 8 | MAC-H1A | open production note | — | WP10 |
| 9 | V-1 | not proven | harness test or feature decision | WP9-C / owner |
| 10–12 | P-A4-1…3 | production blockers | — | WP10 |
| 13 | L-A4-1…5 | documented | — | WP10 |
| 14 | Same-key ADMIN_SYNC | needs treatment before production | red-team case | WP9-C |
| 15 | RadiusRest / L-4 | open before production | RAD-RT, including the N-02 ordering | WP9-C |
| 16 | L-1/2/3/5 | accepted residuals | — | WP10 |
| 17 | R2 audit / UI | LOW | test | WP9-C |
| 18 | READ COMMITTED | conditional | — | WP10 |
| 19 | R-4 cron / RENEW race | not proven | N-04 | WP9-B |
| 20 | R-HF-CARRY-01 | MEDIUM; R-011 | N-12 content scope | WP9-B |
| 21 | Phase B plugin / direct-device / direct-DB | lock R-015 HIGH | §8, N-01, N-02 | WP9-B |
| 22 | OBS-S7-1 | MEDIUM | legacy-device decision | WP9-B |
| 23 | OBS-S7-2 | MEDIUM | as #22 | WP9-B |
| 24 | OBS-S7-4 | LOW | Phase B test | WP9-B |
| 25 | L-S7-FC-1 | MEDIUM operations | — | WP10 |
| 26 | OBS-W8-4 | MEDIUM; R-013 | transport review | WP9-B |
| 27 | OBS-W8-2/3/5 | LOW | tests | WP9-C |
| 28 | OD-W8-16 / S6 record | record gap (D-S8-1 = A recorded) | — | — |
| 29 | Lock gates without evidence | PENDING | evidence where WP9 produces it | shared |
| 30 | Test-evidence hygiene | partly addressed | PEAR2 deprecation LOW | — |
| 31 | Minor | LOW / INFO | tests where relevant | WP9-C / WP10 |

### 5.2 Inherited WP0–WP7 / hotfix items bound to WP9

| ID | Item | Recorded binding | WP9 treatment |
|---|---|---|---|
| WP1-CHECK | CHECK constraints compared by name | ledger WP1: **"must be closed by WP9 … before WP9 may be owner-accepted and must not be silently risk-accepted"** | WP9-D, with N-08 |
| NEW-7 | `db_pass` used as HMAC key material | ledger WP2: close or explicitly resolve before WP9 acceptance. Owner OD-W9-7: remediation required before production | §9.1 |
| NEW-8 | Smarty pages without a sandbox | as NEW-7; OD-W9-8 | §9.2 |
| NEW-6 | payment-link token valid ~59 days | registered | with NEW-7 |
| WP2-PERM | re-test every WP2 boundary | binding WP9 | WP9-C |
| PLUGIN / GW-CB | plugin bypass; gateway callback authenticity is the plugin's contract | must be done before WP9 acceptance | §8 |
| TAMPER | immutability is application-level only | WP9 tamper re-test | WP9-D |
| IDEMP / FCAP / SQLBYP / ACT-RT | finance idempotency, capabilities, direct SQL, activation routes / CLI | binding WP9 | WP9-C (+ N-05, N-06) |
| RAD-RT | rlm_rest endpoint and CoA injection | binding WP9 | WP9-C |
| THRU / PERF / IDX | throughput, stress, index scans | the WP6B note bound stress to WP9. **OD-W9-11 splits it:** bounded security concurrency in WP9; capacity, load and long-duration in WP10 | WP9-C (concurrency) / WP10 (load) |
| WP6B R-9; WP6A R-13; R-15 / P3 | settlement route; API/CLI preview binding; live bandwidth | notes | WP9-C tests / characterisation |
| SEC-01…25 | `docs/GAP_ANALYSIS.md` §13 | closed items must stay closed; SEC-22 is "WP9 review" | WP9-C regression; SEC-22 decision |
| UNBOUND-SEC | WP2 R6, R11, R17; CDN / EOL libraries; no DB uniqueness for invoices / voucher codes | recorded | WP9 review + recommendation |
| HARNESS | AppServer / CliOnlyTest flakes; WP6C MySQL flake | test infrastructure | recorded; fixed only with approval |

### 5.3 Shared between WP9 and WP10
- AV-2 / REL-04;
- SECRETS-AT-REST (R-012, gates N6 / N7);
- KEY-SEP;
- C22 (SEC-18/19/20);
- LPDB: WP9 records whether the app DB user can UPDATE / DELETE ledger and audit rows; WP10 deploys the grants;
- CoA wrong-source on 7.24.5 (W8-X1);
- untested NAS areas (W8-X2);
- Q1–Q4 / P1;
- R-018;
- N-09 alerting;
- N-06 runbook.

### 5.4 WP10 only
- **Operations and runbooks:** AV-1 runbook / monitoring, PENDING recovery, H-2 rollout, OPS-HELD, STALE-SCHED, CRON packaging, L-1/2/3/5, L-S7-FC-1.
- **Preflight and deployment checks:** MAC-H1A, P-A4 / L-A4, READ COMMITTED check, PRE-WP4-DATA.
- **Release and deployment items:** BACKUP / REL-01, MIG-ORDER, API-SECRET, DEPLOY-EXCL, ENGINES, FR-DEPLOY, SEC-20, HTTPS-PWA.
- **Lock risks and gates:** R-005, R-006, R-009, R-020, R-021, O-R07, O-R08, ISO-01, REL-05, Phase D telemetry.
- **Capacity, load and long-duration testing** (OD-W9-11).

### 5.5 Lock RISK_REGISTER cross-reference
- R-001 (#28 / #2), R-002 (#16), R-003 (#9), R-004 (closed-dev), R-005 (#3), R-006 (closed-dev), R-007 (#2).
- R-008 (closed-dev; #14 / #16 / #17), R-009 (#10–#12), R-010 (closed-dev; L-A5-2 in #1), R-011 (#20), R-012, R-013 (#26), R-014 (#1 / #14).
- R-015 (#21, N-01, N-02), R-016 (#22), R-017 (#15), R-018, R-019, R-020, R-021.
- The C-04 severity reconciliation stays an owner item (D-S8-4).

---

## 6. A2-R SQL exhaustion — read-only study (WP9-A)

**The official classification is unchanged: `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`.** Nothing in this section records an SQL admission bypass as proven. The halted reproduction is **never** re-run.

### 6.1 Confirmed code observations (E1)
1. A2-R PPPoE statements run as `%{sql:…}` xlat expansions inside `update` blocks, in post-auth and accounting (site `:209-489`, `:96-101`).
2. In `install/freeradius/nuxr_admission.sql:26-47`, `nuxr_admission` has `NOT NULL` columns, `PRIMARY KEY (username)`, `UNIQUE (token)` and **no CHECK constraint**. The H-2B table (`nuxr_hs_admission.sql:60-62`) has two.
3. There is no echo validation. S3a / S3b (`:310-311`) read with `'%{SQL-User-Name}'` and `UNHEX('%{Tmp-String-2}')` and return only a count and a NAS list.
4. At `:323-325`, a result of exactly `n=0;` skips the NAS verification (session guard) branch.
5. `:228` validates the attempt identity (NAS address, session id, calling station, port, authenticator). It runs before every statement except S0 (`:209-211`), and it does not cover SQL-User-Name.
6. Guarded read / claim statements compare exact sentinels and reject "" (e.g. `:211`, `:242`, `:261-284`, `:313`, `:441`, `:446`, `:458`). The late release L (`:478`) and the accounting updates (`:96`, `:101`) are **unchecked**.
7. The shipped README (`:235-238`, `:262-263`) asserts that pool sizing removes exhaustion and that exhaustion yields "never Accept" (N-17).
8. Pool defaults in `install/freeradius/mods-available/sql.example`: `max=32`, `start=0`, `min=0`, `retry_delay=30`.

### 6.2 Recorded mechanism (E2)
- **H-2B lab** (`evidence/h2b-impl/H2B_IMPLEMENTATION_REPORT.md` §9.3, `frlog-excerpt-empty-expansion.txt`): on FreeRADIUS 3.2.8, a `%{sql:}` statement ran with its inner expansions emptied (`token=''`, `nas=''`) while rlm_sql logged "No connections available and at max connection limit".
- **Owner record:** A2-R statements "could share" this behaviour (H2B record §5).
- **Cached upstream rlm_sql source** (secondary; version unrecorded) is consistent: each inner `%{…}` is escaped on its own pool connection, while module calls escape on the handle they already hold.

### 6.3 Hypothesized failure paths F1–F3 (E3: not tested, occurrence unknown)
- **F1 — claim keyed on an emptied name.**
  - If `Tmp-String-2` were emptied in C1 (`:416`), the INSERT could store `UNHEX('')` as the key and return `claim=1`, giving an Access-Accept.
  - The real key would then have no row, so a concurrent attempt could also claim.
  - Whether `UNHEX('')` yields '' or NULL is uncertain. NULL would be refused by NOT NULL in strict mode.
- **F2 — ACTIVE takeover with verification skipped.**
  - If both S3a expansions were emptied while S2 read ACTIVE, the result `n=0;` would skip the guard, and C2 (`:425`) could take over a possibly live session.
  - Single-expansion variant: open radacct rows that have no reservation.
- **F3 — stored '' token / identity.**
  - Usually this is an orphan PENDING: fail-closed, but it costs availability.
  - A later emptied release / Stop could match `token=''`. A cross-row effect would also need colliding identity values.
- **No path was found where an Accept is sent without any claim:** every Accept passes `claim=1`. F1–F3 concern defective claims or a skipped verification.

### 6.4 Unverified exploitability (what is NOT known)
- Whether FreeRADIUS 3.2.8 empties **A2-R** expansions in practice. The H-2B log is about H-2B statements.
- Whether `pool.max ≥ max_servers` prevents it. The README claims yes; the per-expansion mechanism suggests no. Pool spawn failures with `retry_delay=30` may also yield no handle (reasoned, uncertain).
- Frequency, timing, and whether an attacker could induce starvation from outside (e.g. through authentication load). This is **not studied by experiment, and will not be**.
- The NAS-side outcome of F1 / F2.

**No exploitability claim is made in either direction.**

### 6.5 Defensive design (OD-W9-2: design only, not approved for implementation)
The design is layered, because **CHECK constraints alone are not sufficient**.

| Layer | Design | Addresses | Does **not** address |
|---|---|---|---|
| L1 schema CHECKs | `ck_nuxr_admission_token CHECK (token REGEXP '^[0-9a-f]{32}$' AND username <> '')` and `ck_nuxr_admission_claim CHECK (nas REGEXP IPv4 AND acct_session_id <> '' AND calling_station_id <> '' AND nas_port_id <> '' AND request_authenticator REGEXP '^[0-9a-f]{32}$' AND session_user <> '')`. Added in CREATE TABLE, and through an idempotent, `information_schema`-guarded ALTER after a read-only pre-scan | F1, F3 (an emptied write errors → "" → Reject) | F2 (a SELECT with emptied inputs returns a well-formed `n=0;`); unchecked L / A-* (orphan PENDING remains) |
| L2 echo validation | S3a / S3b read from **one parameter row** (`FROM (SELECT '%{SQL-User-Name}' u, UNHEX('%{Tmp-String-2}') k) p`) and echo `LOWER(HEX(p.u))` and `HEX(p.k)` from that same row. Unlang compares them with `%{hex:&SQL-User-Name}` (not SQL) and with the key; a mismatch → Reject. The echo must come from the same expansion as the WHERE clause | F2 and its single-expansion variant | a value emptied consistently in both (impossible by construction: one expansion feeds both) |
| L3 SAFE flag | an ACTIVE takeover (C2) is allowed only if this request's guard returned `ok` + SAFE | F2, defence in depth | — |
| L4 readiness | `nuxr_admission_check.sql` verifies that both constraints exist and are enforced; otherwise NOT_READY | an engine silently not enforcing | — |
| L5 docs | README: remove "removes pool exhaustion" / "never Accept" as unproven; state "sizing is necessary, not sufficient"; add an empty-expansion paragraph and upgrade steps | N-17 | — |
| (optional) | key echo in S2 / R | a misread `none` before the INSERT | — |

**MariaDB 10.11 / MySQL 8.0 impact (study items, not verified by test):**
- Both engines enforce CHECK (MySQL ≥ 8.0.16, MariaDB ≥ 10.2.1). The `REGEXP` syntax works on both: H-2B uses the same form and passed the dual-engine harness (`evidence/h2b-impl/harness-both-engines.out`).
- `ADD CONSTRAINT` validates existing rows on both, so a violating row makes the ALTER fail. That needs the pre-scan and an operator decision; there is no automatic data change.
- Error codes differ (expected MySQL 3819 and MariaDB 4025; to be confirmed).
- MySQL `NOT ENFORCED` and MariaDB `check_constraint_checks` can disable enforcement. So L4 must read `information_schema` per engine: `CHECK_CONSTRAINTS`, plus MySQL `TABLE_CONSTRAINTS.ENFORCED`.
- Strict `sql_mode` becomes a contract item, because it governs `UNHEX('')`, NULL and truncation behaviour.

**Contract and test impact, if later approved:**
- It contradicts the header of `nuxr_admission.sql` ("never alters").
- It contradicts `tests/Unit/Wp4/AdmissionSourceTest.php:126-131` (no ALTER, exactly two CREATE TABLE) and `:100` (the S3b line).
- It rewrites lines pinned by `tests/Unit/Wp4/SessionGuardSourceTest.php` (D-A2R-4 "extended, not rewritten"; it also counts reject branches) and by its reference `dev/wp8/freeradius/option-g/post-auth.unlang`.
- All of these need explicit re-pin approval.

**Residual after a full design, if implemented:**
- Silent corruption would become refusals.
- An emptied L / A-Stop could still leave an orphan PENDING (AV-1 class, with no executable recovery). This is an availability cost the owner must accept or address.

### 6.6 Evidence still missing, and the test plan allowed now
| Evidence | Can WP9 obtain it under OD-W9-3? |
|---|---|
| Static proof that every `%{sql:}` on the PPPoE path is either an echoed SELECT or a write on a CHECK-protected table (after hardening) | **yes:** T0 source-guard unit test (no services) |
| Pure-unit proof of the unlang decision table (sentinel strings, regexes), by parsing the site text | **yes:** T0 |
| DB-level proof that the CHECKs reject '' values on both engines | **not now:** rev-1 A-T2 needs a database service; deferred to a separate owner decision |
| FreeRADIUS behaviour with a literal '' in a test-only copy of the site | **withdrawn** (rev-1 A-T3): it could be read as a simulation of the halted scenario |
| Whether A2-R expansions are emptied in practice; frequency; refusal under real exhaustion; the NAS outcome | **never:** requires the halted experiment |

**Forbidden in every WP9 activity:**
- `dev/wp8/a2r_sql_gate.py`;
- `a2r_gate.py pool …`;
- `a2r_gate.py fault on`;
- any change of `pool.max`;
- any connection holding, `max_connections` manipulation, burst or load against FreeRADIUS SQL;
- any proxy or tool that drops or delays SQL connections;
- any shared or production database.

---

## 7. OBS-1 / L-A5-2 — creation-time identity protection (WP9-A; OD-W9-4: proposal only)

### 7.1 Proposed final invariant (for owner approval)

**Definitions:**
- `canon(x)` = `TRIM(x)`, compared under the column collation `utf8mb4_general_ci`. That is the same equality FreeRADIUS uses on `radcheck.username`, which is also `varchar(64) utf8mb4_general_ci` (`install/radius.sql:52`).
- For a customer *c*: `names(c) = { canon(c.username) } ∪ { canon(c.pppoe_username) if ≠ '' }`.

**The invariant:**
> **I-1.** For any two distinct customers *a ≠ b*: `names(a) ∩ names(b) = ∅`.
>
> **I-2.** Within one customer, `username` and `pppoe_username` may be equal. That is legitimate.
>
> **I-3.** An empty `pppoe_username` is never a name, so many customers may have it.
>
> **I-4.** Enforcement applies on **create** and on **a change of `username` or `pppoe_username`**. Only the changed name is checked, and the customer's own row is excluded. Edits of other fields never re-check identity, so pre-existing duplicates are **grandfathered**, not rejected.
>
> **I-5.** A new or changed `pppoe_username` must fit the RADIUS key rules already enforced by `RadiusPolicyBuilder::key()` (≤ 64 bytes, no control characters) and the column (≤ 32). Otherwise it is refused, never truncated.

**Why the DB collation and not binary:** RADIUS authorization matches under `utf8mb4_general_ci`. Two names that differ only by case, a Latin accent or trailing spaces therefore already resolve to the same `radcheck` rows. A binary rule would allow exactly the collisions S7-F1 had to refuse.

### 7.2 Research points
| Point | Finding (E1 unless marked) | Consequence for the invariant |
|---|---|---|
| Collation and equivalence | Both columns are `utf8mb4_general_ci` on fresh installs (`install/phpnuxbill.sql:28, 31`; schema variants). That collation is case- and Latin-accent-insensitive and PAD SPACE: trailing spaces are ignored, leading spaces are significant (general engine facts, E3 until tested on both engines). MySQL 8's default `utf8mb4_0900_ai_ci` (NO PAD) applies only to columns without an explicit collation, so **old upgraded installs may differ** | the check must use the column's own comparison (a query, not PHP); the survey must record the actual collation per installation |
| Empty / optional PPPoE | default `''`; `key()` falls back to the username; existing guards already skip '' (`LegacyReprovisionService.php:196`, `customers.php:774`) | I-3; never pass '' as a parameter |
| Existing duplicates | no unique or secondary index on either column (`install/phpnuxbill.sql:343-344`; R0005 adds only manager / sales indexes); duplicates may exist on upgraded installs | I-4 grandfathering; read-only survey (§7.4); no automatic data change |
| Locking / concurrency | Only P1 is serialized (tree lock).<br>• P2: no lock;<br>• P3: a per-name lock, in its own path only;<br>• P4: no lock;<br>• P5: tree lock, with the check outside the write transaction.<br>`FOR UPDATE` on a non-indexed column scans the table, and gap locks fail under READ COMMITTED | one dedicated global identity lock taken by every writer (§7.3) |
| Legacy / RADIUS compatibility | `key()` trims with PHP `trim`, which is broader than SQL `TRIM`. OBS-S7-4 whitespace keys exist. Voucher-only identities (`tbl_user_recharges.username`, `radcheck` rows for voucher codes) share the RADIUS namespace but are not customers | the invariant covers customers; the voucher namespace stays outside unless the owner widens it (decision) |
| No accidental rejection | grandfathering (I-4); self-exclusion on rename, which fixes today's false refusal of a case-only self-rename at `customers.php:716-722`; no new charset rule for existing values; only *new* or *changed* names are checked | previously legitimate customers stay editable and servable as today |

### 7.3 Proposed application-level protection and lock ordering (AM-W9-1; design only, U0 not implemented)

**Correction of rev 2.** Rev 2 said the identity lock would be "innermost: after any tree / user lock, before `BEGIN`". For P1 that is not achievable inside the current code shape: `Tx::withTreeLock()` takes the tree lock and then calls `Tx::run()`, which executes `BEGIN` **before** the callback runs (`system/autoload/Reseller/Domain/Tx.php:51-65`, `:22-41`). A `GET_LOCK` taken inside that callback is taken inside an open transaction. Taking it *before* `withTreeLock` instead would invert the order used by P5 (tree → identity) and could deadlock. Rev 3 replaces the rev-2 text with the design below.

**Existing lock primitives (E1):**
| Primitive | Behaviour | Source |
|---|---|---|
| `Tx::run($fn)` | strict `sql_mode`, `beginTransaction()`, `$fn`, commit / rollback | `Tx.php:22-41` (SHA-pinned; **not changed**) |
| `Tx::withTreeLock($fn)` | `GET_LOCK('nuxr_tree_…')`, then `Tx::run($fn)`; release in `finally` | `Tx.php:51-65` (pinned) |
| `TreeSerialization::hold($fn)` | the **same** tree lock without a transaction; refuses if a transaction is open; re-entrant per session | `system/autoload/Reseller/Domain/TreeSerialization.php:29-48` |
| `VoucherRedemptionService::withUsernameLock()` | `GET_LOCK('nuxr_user_'.sha1(db|lower(name)))`, no transaction; inner steps use their own `Tx::run` | `VoucherRedemptionService.php:171-184` |

**Proposed primitive (not implemented):** `CustomerIdentity::hold(callable $fn, int $timeout = 10)` — `GET_LOCK('nuxr_identity_'.sha1(db))`; **throws `LogicException` if a transaction is already open** (same guard as `TreeSerialization::hold`); fail-closed `LOCK_TIMEOUT`; release in `finally`. `CustomerIdentity::assertAvailable()` (unchanged from rev 2) runs only inside `hold()`.

**Global lock order (all writers):**
> **L-1 tree lock** (`nuxr_tree_*`) → **L-2 voucher username lock** (`nuxr_user_*`) → **L-3 identity lock** (`nuxr_identity_*`) → **L-4 `BEGIN` / InnoDB row locks**.
>
> Rules: (R1) a lock is only acquired when no lock of a **later** level is held; (R2) the named locks L-1…L-3 are only ever requested **outside** a transaction; (R3) the identity lock is never held across a device / network call or a RADIUS-database call; (R4) every identity write commits before L-3 is released.

**Per writer (P1–P5):**
| Writer | Today | Proposed sequence | Notes |
|---|---|---|---|
| **P1** `CustomerService::create` | `Tx::withTreeLock(cb)` → GET_LOCK tree → BEGIN → cb (`:57`) | `TreeSerialization::hold(fn => CustomerIdentity::hold(fn => Tx::run(cb)))` → tree → identity → BEGIN → cb (`revalidateActor`, owner / sales checks, **`assertAvailable`**, INSERT) → COMMIT → release identity → release tree | identical tree-lock semantics to `withTreeLock` (same lock name, taken before the transaction); **`Tx.php` unchanged**; no test pins `CustomerService` to `withTreeLock` (grep of `tests/`) |
| **P2** `register.php` | check (`:84`), ORM insert (`:89-96`), no lock | `CustomerIdentity::hold(fn => [assertAvailable, ORM create/save])` (autocommit statements) | no tree lock needed; each autocommit statement reads the latest committed data |
| **P3** `VoucherRedemptionService::redeemForNewUsername` | `withUsernameLock` → check → `claim()` (own Tx) → `Tx::run(INSERT)`; the grant runs after the lock is released | `withUsernameLock(fn => CustomerIdentity::hold(fn => [assertAvailable, claim, Tx::run(INSERT)]))`; grant unchanged, outside both locks | order L-2 → L-3; the WP5B redemption contract (claim before grant; release only on proven non-start) unchanged; the provisional-row removal path deletes and needs no identity lock |
| **P4** `customers.php` edit-post, unassigned | checks `:716-783`, `$c->save()` `:857` (ORM autocommit), device calls `:905-932` | keep the early checks for messages; wrap **only** the re-check + `$c->save()` in `CustomerIdentity::hold()`; device calls stay after release | no lock during device calls; no transaction open at `:857` (legacy ORM autocommit — the guard in `hold()` enforces it) |
| **P5** `LegacyReprovisionService::run` (IDENTITY_EDIT) | `TreeSerialization::hold` → `validate()` (autocommit reads) → `renameCheck()` (RADIUS DB) → `Tx::run(write)` → device steps under the tree lock (`:104-131`) | inside the existing `hold`: `validate()` and `renameCheck()` as today (early refusal), then `CustomerIdentity::hold(fn => [assertAvailable(new names, self excluded), Tx::run(write)])`, release identity, then device steps (unchanged) | order L-1 → L-3 → L-4; RADIUS reads and device calls stay outside L-3; **the revert path** (`:151`, writes the old identity back after a device failure) must also run inside `CustomerIdentity::hold` with an `assertAvailable(old names)` re-check; if an old name was taken in between, no revert: the result stays `REPROVISION_UNCONFIRMED` for an operator (fail closed, no blind revert) |

**Deadlock argument.** (1) Named-lock waits: every path acquires named locks in the order L-1 → L-2 → L-3 and never requests a lower level while holding a higher one, so no cycle exists among named locks. P1 / P5 take L-1 → L-3; P3 takes L-2 → L-3; P2 / P4 take L-3 only; activation, finance and pricing take L-1 only. (2) Named-lock vs row-lock waits: R2 guarantees no session waits for a named lock while holding InnoDB row locks, so no mixed cycle can form (MariaDB does not detect user-lock deadlocks, so this ordering is required, not optional). (3) Re-entrancy: `GET_LOCK` is re-entrant per session on MySQL ≥ 5.7 and MariaDB ≥ 10.0.2; nested `hold()` of the same lock is harmless (E3 until tested on both engines). (4) Timeouts fail closed (`LOCK_TIMEOUT`) and write nothing.

**Snapshot correctness.** Transactional writers (P1, P3 insert, P5 write) start `BEGIN` **after** L-3 is held, and InnoDB creates the read view at the first consistent read inside the transaction, i.e. after the lock; every other identity writer commits before releasing L-3 (R4). Therefore `assertAvailable` sees every identity change committed by any other lock holder. Autocommit writers (P2, P4) read the latest committed data per statement. No `FOR UPDATE` / gap lock on the non-indexed name columns is needed (that would scan and lock the whole table and is unsafe under READ COMMITTED).

**Contracts preserved.** WP2 tree-lock-before-transaction semantics (P1) unchanged; WP5B voucher redemption and re-provision contracts unchanged; `Tx.php`, `RouteMap.php`, `ServiceGate.php`, `Package.php` (all SHA-pinned) unchanged; no lock held during device calls beyond the existing WP5B tree-lock design of P5.

**Not covered by application code:** direct DB writes by plugins or operators (§8); only DB constraints or triggers could cover them (U3 / U4).

**Verification needed before any implementation (planned, not run):** T1 tests on both engines — concurrent P1×P2, P1×P3, P2×P3, P4×P1, P5×P1 with real parallel processes and barrier points between lock acquisition and commit; an external holder of L-3 → `LOCK_TIMEOUT` with no row; a nested-lock test (P1 inside an outer tree hold); `hold()` called inside an open transaction → `LogicException`; READ COMMITTED and REPEATABLE READ sessions.

### 7.4 Read-only historical survey (only on an owner-approved copy; never on production in WP9)
```sql
SELECT COLUMN_NAME, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_customers' AND COLUMN_NAME IN ('username','pppoe_username');
SELECT k, COUNT(DISTINCT id) n, GROUP_CONCAT(DISTINCT id) ids FROM (
  SELECT id, TRIM(username) k FROM tbl_customers
  UNION ALL SELECT id, TRIM(pppoe_username) FROM tbl_customers WHERE TRIM(pppoe_username) <> ''
) x GROUP BY k HAVING n > 1;                                  -- I-1 violations (cross-field, DB-equal)
SELECT id FROM tbl_customers WHERE username <> TRIM(username) OR pppoe_username <> TRIM(pppoe_username);  -- whitespace keys
```

### 7.5 Migration / uniqueness options (each a separate owner decision; none proposed for implementation now)
| Option | What | Covers | Cost / risk |
|---|---|---|---|
| **U0** | application-level only (§7.3) | I-1 for all application writers | no schema change; plugins / direct DB not covered |
| **U1** | `UNIQUE (username)` | username vs username | new R0008, plus variants, registry and inspector (all pinned), so re-pins; **fails on legacy duplicates**; needs the survey and an operator plan per duplicate |
| **U2** | generated `pppoe_key = NULLIF(TRIM(pppoe_username), '')` + UNIQUE | pppoe vs pppoe | as U1; generated-column support differs in detail between MariaDB and MySQL (to verify) |
| **U3** | identity table `tbl_customer_identity(name varchar(64) general_ci UNIQUE, customer_id, kind)`, maintained in the same transaction as every writer | full cross-field I-1 for application writers | the largest change; plugins writing `tbl_customers` directly still bypass it, unless U4 |
| **U4** | `BEFORE INSERT / UPDATE` triggers that enforce I-1 | also direct DB writes | the project has deliberately used **no triggers** so far (WP3A); needs the TRIGGER privilege at migration; behaviour on both engines to verify |

**For every option U1–U4:** run the read-only survey first; never modify legacy rows automatically; rollback = drop the added constraint, table or trigger.

### 7.6 Tests (planned; execution needs approval)
- **Helper matrix:** I-1…I-5, with positive controls including grandfathered duplicates and a case-only self-rename.
- **Per path:** P1–P5 and the API. A refusal causes no unauthorized identity, business, RADIUS or device state change; the expected refusal audit is allowed (AM-W9-2).
- **Concurrency:** pairs P1×P2, P1×P3, P2×P3, P4×P1 with real parallel processes, under REPEATABLE READ and under READ COMMITTED. An external lock hold → `LOCK_TIMEOUT`.
- **Reverse direction:** characterise, in a disposable DB and before any fix, an owned colliding customer activated over another tree's key.
- **Over-long pppoe:** refused, never truncated, in strict and non-strict session modes.
- **Survey query:** run on seeded duplicates, on both engines.

### 7.7 Voucher namespace (AM-W9-3; study only)

**Owner decision:** keeping vouchers outside the customer rule I-1 is accepted **for this study only**. Voucher-only and RADIUS identity conflicts are studied separately, and **OBS-1 is not closed for production while any collidable RADIUS identity remains**.

**Facts (E1):**
- A voucher-only redemption grants service with `Package::rechargeUser(0, router, plan, 'Voucher', code)` (`VoucherRedemptionService.php:142`); the RADIUS identity is the **voucher code** itself, and the billing row is found by `tbl_user_recharges.username` (`system/devices/Radius.php:325-340`). Entry points: `login.php:135` (public) and `radius.php:163` (rlm_rest, behind `RestGate`).
- The gate for voucher-only grants is `ServiceGate::isBlockedUsername(code)` (`ServiceGate.php:48-66`): refused when the code equals (cross-field, collation-equal) the `username` or `pppoe_username` of a **reseller-owned** customer.
- `radcheck.username` is `varchar(64) utf8mb4_general_ci` (`install/radius.sql:52`), the same equality as the customer columns.

**Collision classes (E3 unless marked):**
| Class | Description | Today |
|---|---|---|
| V1 | voucher code = an **unassigned** customer's username / pppoe | not refused by the gate (only owned customers are checked); a voucher-only grant would write RADIUS rows under the same key as that customer |
| V2 | a new customer name (P1–P5) = an existing voucher-only identity (an active voucher code with RADIUS rows / an `on` recharge row keyed by username) | not checked by any creation path |
| V3 | voucher code = an owned customer's name | refused before any write (E1, gate) |
| V4 | two voucher codes equal under the collation | depends on voucher-code generation / uniqueness (SEC-23 closure in WP5B; no DB uniqueness for voucher codes per the WP5B ledger note) — to verify |

**Options for a later decision (none proposed for implementation now):** (a) extend I-1 to "customer names ∩ live voucher-only identities = ∅" (check active voucher-only recharges and, optionally, unused voucher codes) in `assertAvailable` and in the voucher-only grant; (b) prefix or namespace voucher-only RADIUS identities (changes the login contract for voucher users — large); (c) refuse voucher-only redemption when the code equals **any** customer name (extends the gate from owned to all customers). Planned characterisation (T1, needs approval): V1 and V2 on disposable databases, both engines, proving the RADIUS-row overwrite or its absence.

**Effect on OBS-1:** even after U0, OBS-1 stays open for production until V1 / V2 are resolved by decision and evidence.

---

## 8. Plugin and public-method trust boundary (OD-W9-9)

**There is no sandbox.** Plugins are PHP files included into the application process. Once installed, they run with the application's full authority. Nothing below claims otherwise.

| Boundary | What it is | Preventable in application code? | Existing controls | Proposal (for approval) |
|---|---|---|---|---|
| **B1 Unauthorized entrypoints** (reachable over HTTP without the right authority) | routes, `plugin/{fn}` dispatch, `radius.php` REST, `system/api.php`, `callback.php`, cron | **yes** | RouteGuard / RouteMap / API_ALLOWED; PluginRegistry gates `plugin/{fn}`; RestGate; cron is CLI-only; CSRF | red-team every entrypoint (§12). Note: the plugin's own top-level code runs before RestGate / ApiGuard / RouteGuard (N-02); that belongs to B2, not to entrypoints |
| **B1′ Public grant methods** (the in-process API that legacy or plugin callers use) | `Radius::customerAddPlan`, `remove_customer` with a caller-supplied `plan_expired`, MikroTik `add*/set*` helpers | **partly.** Guarding them prevents bypass by accident or by legacy convention: a well-behaved plugin that uses the documented API is stopped | default deny on `add_customer` / `sync_customer` / `change_username` only | guard `customerAddPlan` with `assertDeviceAllowed`; validate `plan_expired` against the DB plan in `remove_customer`. Guarding the MikroTik helpers adds little (they need a connected client), so it is optional. **This does not stop a malicious plugin**, which can write RADIUS rows directly |
| **B2 Arbitrary code execution** | installed plugins (SuperAdmin `SYSTEM_CODE`, `RouteMap.php:298-301`); `html_php` widgets (SYSTEM_CODE for code widgets, `widgets.php:16-18`); Smarty page templates (SYSTEM_CODE; §9.2); page reset from GitHub `master` (`pages.php:48, 51`) | **no.** In-process PHP cannot be sandboxed | install and code surfaces are SuperAdmin-only; the updater is closed (SEC-03) | **a trust policy:**<br>• only reviewed plugins;<br>• a recorded inventory (file name + SHA-256), checked at startup or by an operator report;<br>• log plugin load errors instead of swallowing them;<br>• document "plugins are fully trusted code";<br>• optionally, stop loading plugins on `radius.php` before RestGate (a decision: some installs may rely on hooks there) |
| **B3 Direct DB authority** | plugin or operator code that uses the application's DB connection | **no** in application code. **Only** DB least privilege (and triggers) can limit it | drift report (PERIOD_NOT_BACKED, RADIUS_DRIFT; MikroTik state is not compared); static scans of core code | WP9 records the app user's actual privileges as WP10 LPDB evidence (expected: UPDATE / DELETE on ledger and audit allowed). The least-privilege split (runtime vs migration; no UPDATE / DELETE on ledger / audit) is WP10. U4 triggers only by decision |

---

## 9. NEW-7 and NEW-8 option studies (OD-W9-7 / 8: remediation required before production)

### 9.1 NEW-7 — `db_pass` as key material (with N-03)
**Current uses (E1):**
- staff session fingerprint and remember-cookie MAC (`system/autoload/Admin.php:223-233`, verified at `:65-69`);
- customer cookie `sha1(uid.time.db_pass)` (`User.php:11-27, 169-195`);
- voucher invoice signature `md5(id.db_pass)` (`voucher.php:18`, `plan.php:364`, `Message.php:337`);
- OTP file names `sha1(user.db_pass)` (`accounts.php`, `forgot.php`, `register.php`).

The API secret already refuses `== db_pass` (`ApiToken.php`).

| Option | Content | Closes | Compatibility impact |
|---|---|---|---|
| **O7-A (recommended)** | • a dedicated application secret: a config key generated at install / upgrade, never `db_pass`;<br>• purpose-separated subkeys (`hash_hmac` with labels) for the staff fingerprint, staff cookie, customer cookie, links, invoice signatures and OTP names;<br>• customer cookie = HMAC over uid, issue time, expiry and the password hash, checked with `hash_equals`; no future issue time; max 30 days;<br>• cookie flags HttpOnly + SameSite=Lax (+ Secure under HTTPS);<br>• purpose-bound, short-lived link tokens;<br>• no session token in `?uid=` | NEW-7, N-03, NEW-6 | • a one-time logout of all staff (fingerprint) and all customers;<br>• previously sent magic / payment links stop working;<br>• a config migration step;<br>• WP10 provisions and rotates the key |
| O7-B (minimal) | keep the key; fix the comparison (`hash_equals`), bound the expiry, bind to the password, add cookie flags | N-03 partly | fewer logouts; **NEW-7 is not closed** (the key coupling remains) |
| O7-C | risk acceptance | — | excluded by OD-W9-7 |

### 9.2 NEW-8 — Smarty without a security policy
**Facts (E1):**
- Smarty 4.5.3. `system/boot.php:24-45` sets the template dirs (custom / theme / default / payment-gateway / plugin).
- There is no `enableSecurity` and no `escape_html`.
- `$ui->assign('_c', $config)` exposes the whole configuration to every template.
- **185 templates use static class calls**: `Lang::T` 3531 uses, `Text::url` 602, `Lang::moneyFormat` 97, `NuxrUi::*`, and more.
- Page templates (`pages/*.html`) are written by `pages/page-post` (SYSTEM_CODE).
- The page reset fetches `raw.githubusercontent.com/.../master/pages_template/*.html` (`system/controllers/pages.php:48, 51`).

| Option | Content | Compatibility impact |
|---|---|---|
| **O8-A (recommended)** | apply a Smarty security policy **only when rendering page templates** (a separate Smarty instance or a policy switch):<br>• no static classes except an allowlist (`Lang`, `Text`);<br>• no PHP-function modifiers beyond an allowlist;<br>• no `{$_c}` in that render scope (allowlisted keys only);<br>• replace the GitHub page reset with templates bundled in the release | core and theme templates unchanged; existing custom pages that use other static calls or `$_c` keys must be adjusted (inventory first) |
| O8-B | a global security policy, with a static-class and modifier allowlist | high regression risk across the 185 templates, plus plugin and gateway templates |
| O8-C | `escape_html` by default (XSS hardening) | very broad (double escaping); a separate wave; not needed to close NEW-8 |

---

## 10. Owner policy matrix (OD-W9-6 = OWNER_POLICY_PENDING)

**No policy is adopted here.** "Current" is the behaviour in the code today (E1). The recommendation is the conservative choice, offered for decision only.

| # | Situation | Current behaviour (code) | Option A | Option B | Option C | Conservative recommendation | Tests that follow the decision |
|---|---|---|---|---|---|---|---|
| P1 | Replay of an in-flight activation after the actor lost authority | a same-actor replay continues a reserved operation. Frozen actors are refused by `assertEligible`. A lost customer (unassigned / moved) is not re-checked (N-05) | keep current ("an authorized, reserved operation may complete") | re-check `authorizeCustomer` (and the actor) on replay. If authority was lost: no progression; return the status; the operation stays for an Admin to reconcile / cancel; no automatic release | refuse and auto-cancel / release | **B** (no progression, and no automatic release that could create free service) | replay after unassignment / move; positive control while still assigned |
| P2 | Agent re-enables a descendant that an Admin disabled | allowed (STAFF_DISABLE scope D, `StaffService.php:198-229`; there is no "disabled by" rule) | keep | record who disabled; lower roles cannot re-enable an Admin-disabled account | only an Admin re-enables any disabled descendant | **B** | Admin-disable → Agent re-enable refused; Agent-disable → Agent re-enable allowed |
| P3 | Password reset of descendants by an Agent | allowed for descendants (STAFF_UPDATE_PROFILE scope D), audited | keep (audited) | direct children only; audited; existing sessions end (the fingerprint binds the password hash) | Admin only; the Agent may request one | **B** | grandchild reset refused; direct child allowed; audit row |
| P4 | Sales re-assignment on a child Agent's customer | any ancestor Agent with T scope may re-assign to a Sales of the customer's manager (`CustomerService.php:95-118`) | keep | only the customer's direct owner Agent (and Admin) | owner + Admin, plus the Sales user's consent | **B** | ancestor re-assign refused; owner allowed |
| P5 | Activation history after an ownership transfer | **Agent:** T scope uses the activation's historical `manager_id`, so the old owner keeps the history and the new owner Agent does not see pre-transfer activations.<br>**Sales:** A scope uses the customer's **current** scope, so the new owner's Sales sees pre-transfer activations.<br>(`ActivationService.php:544-562`) | keep | Sales sees only activations whose `manager_id` is the customer's current owner; the old owner keeps its own history | history follows the customer: the current owner sees all; the old owner loses it | **B** (financial records stay with the payer; no exposure across owners) | after a transfer, reads by the old owner, the new owner and the new Sales; wholesale masking unchanged |

---

## 11. Work plan WP9-A … WP9-E (OD-W9-5: test-first, owner-approved bounded waves)

Each wave runs plan → approval → execution → testing → review → acceptance. Characterisation tests run **before** any fix, and their results are kept. A confirmed defect triggers the stop rule (§16.4).

| Wave | Content | Gate |
|---|---|---|
| **WP9-A** | **A2-R:** T0 static tests. Implementation only after an explicit OD-W9-2 implementation decision.<br>**OBS-1:** invariant decision → characterisation tests → U0 helper wave after approval. The uniqueness option is decided separately | OD-W9-2 (implementation), OD-W9-4 (implementation), test-stage approvals |
| **WP9-B** | • N-01 / N-02 (§8 decisions);<br>• N-04 R-4: concurrency characterisation, then the fix;<br>• N-05 / N-15 according to the policy decisions;<br>• N-06 CLI guard;<br>• #20 / N-12;<br>• #22 / #23 / #26 legacy driver decisions | §10 decisions; per-defect approvals |
| **WP9-C** | • the red-team matrix (§12) on the disposable harness, over HTTP and in the PWA lab;<br>• SEC-01…25 regression;<br>• WP2-PERM;<br>• IDEMP / ACT-RT / RAD-RT;<br>• bounded security concurrency | test-stage approvals |
| **WP9-D** | • WP1-CHECK + N-08 (pinned files → re-pin approval);<br>• NEW-7 (§9.1);<br>• NEW-8 (§9.2);<br>• TAMPER and LPDB evidence;<br>• AV-3;<br>• N-07;<br>• N-11 | option decisions; per-defect approvals |
| **WP9-E** | • full dual-engine harness on the final tree (frozen fingerprints);<br>• CHR gates only if RADIUS / site / device code changed **and** CHR execution is approved;<br>• secret sweep; manifest;<br>• `REPORT.md`;<br>• WP10 hand-over list | final review |

---

## 12. Red-team attack and test matrix (planned; not executed)

**Legend:** Env H = dual-engine harness; HTTP = harness AppServer; B = PWA browser lab; L = isolated CHR lab (**planning only**, OD-W9-10).

**Rule for every negative case (AM-W9-2):** it has a positive control, and a refusal must cause **NO UNAUTHORIZED BUSINESS / FINANCE / IDENTITY / SERVICE STATE CHANGE**. Expected security refusal records that are part of the contract (refusal audit rows, `ServiceGate::refused` / `op=deviceGrant` records, error-reporter log lines) are allowed. The test must prove that money (wallets, ledger, reservations, transfers), subscriptions (activations, events, recharges, invoices / transactions), subscriber identity (`tbl_customers` identity fields, ownership, assignment) and service / device state (radcheck / radreply / radusergroup, router objects) did not change. Checked by before / after snapshots of those tables (values, not only counts) plus an allow-list of the expected refusal records.

| # | Axis | Existing control | Existing tests (examples) | New red-team cases | Env |
|---|---|---|---|---|---|
| 1 | Cross-reseller IDOR | RouteGuard, PermissionService, DataScope (no existence oracle); ActivationService::status; ResellerReadService; LedgerReadModel | `Http/ScopeIsolationTest`, `Wp6a/ActivationReadModelTest`, `Wp6b/ResellerWorkspaceTest`, `Wp6b/FinanceReadTest`, `Wp6c/TargetParityTest`, `Http/RouteMatrixTest` | • every object route × role with foreign / nonexistent / own ids: same status and body hash for foreign and nonexistent ids;<br>• `reseller/tree?node=<foreign>`;<br>• a transfer entry shows only the viewer's leg | HTTP |
| 2 | Unassigned legacy customers | DataScope never matches NULL; `CUSTOMER_NOT_RESELLER_OWNED`; ServiceGate | ScopeIsolationTest matrix; `Wp6a/*`; `CustomerOwnershipTest` | • Agent / Sales with `cNULL` on activation, message, assign-sales, invoices, API, `autoload/*`;<br>• legacy-username oracle on `pppoe_username_used` / `customer_is_active` | HTTP |
| 3 | Sales assignment bypass | DataScope A; `assertSalesOf`; a move clears assignments | `CustomerOwnershipTest`, `HierarchyTest`, `Wp5a/ActivationAuthorizationTest` | • A1 on cA2 / cA0;<br>• N-05 replay after unassignment (P1);<br>• ancestor re-assign (P4) | H, HTTP |
| 4 | Forged POST ids | `nuxrId`; server-derived owner / payer / parent; `ConfirmToken` HMAC | `StaffManagementTest`, `CustomerOwnershipTest`, `Wp3b/WholesaleTest`, `Wp6b/FinanceMutationTest`, `SecurityRoutesTest::testRawSqlParametersAreInert` | • id fuzz on every mutating route: foreign, 0, −1, `1e3`, `01`, arrays, 19+ digits, wrong role type;<br>• extra money / owner fields on `activation/create-post`;<br>• swapped `from` / `to` → REVIEW_EXPIRED;<br>• `customer_ids[]=1abc` | HTTP |
| 5 | Role / capability escalation; disabled / frozen | RoleCapabilityMap; `canMutate`; `revalidateActor`; StaffService | `PermissionModelTest`, `RouteMatrixTest`, `StaffManagementTest`, `HierarchyTest`, `OperationsCenterTest` | • Report / Sales on every finance / pricing / operations / activation-operator / settings route;<br>• an Agent edits an Admin or a sibling, or sets `user_type=Admin`;<br>• ACTOR_FROZEN race between form and POST;<br>• P2 / P3 after the decisions | H, HTTP |
| 6 | Forged retail / wholesale / base price; own wholesale | server snapshot; `SELF_PRICING_FORBIDDEN`; `WHOLESALE_BELOW_PARENT`; ancestor disable; Money grammar | `Wp3b/*`, `ActivationPreviewTest` | • forged price fields are ignored;<br>• wholesale-post on self / parent / sibling / grandchild / Sales;<br>• below-parent; ancestor-disabled re-enable;<br>• money fuzz;<br>• a legacy price below wholesale → refused, not under-charged;<br>• the NULL-snapshot reservation branch | H, HTTP |
| 7 | Wallet double-spend; provider funding / withdrawal abuse; transfers | available = balance + debt − reservations; ordered `FOR UPDATE`; unique keys; depth-1 transfers; funding / debt in global scope only | `Wp3a/ConcurrencyTest`, `TransferTest`, `FundingTest`, `WalletTest`, `DebtLimitReviewedWriterTest` | • **bounded** HTTP stampede: parallel `finance/send-post` and `activation/create-post` from two sessions;<br>• the 0.00 / 0.01 boundary;<br>• forbidden targets;<br>• Agent / Sales on funding / withdrawal / debt / adjust;<br>• cross-session token replay;<br>• withdrawal vs reserve race | H (FinanceWorkers), HTTP |
| 8 | Replay / idempotency / concurrent activation | per-user nonce namespace; `uq_act_idempotency`; `inFlight`; ACTION_MISMATCH | `Wp5a/ActivationRaceTest`, `ActivationAuthorizationTest`, `Wp6a` replay, `ActivationHttpTest` | • two browsers, two nonces, simultaneous → one period and one debit;<br>• cross-user nonce reuse;<br>• back-button resubmit;<br>• same nonce, different plan → IDEMPOTENCY_CONFLICT | H, HTTP |
| 9 | Hierarchy move / cycle | Admin-only move / place under the tree lock; SELF_PARENT; CYCLE; valid parent | `HierarchyTest`, `HierarchyCliTest`, `ConcurrencyTest::testSubtreeMoveVersusTransfer`, `WholesaleTest::testPreExistingViolationFromAMove` | • Agent / Sales move / place (web + API);<br>• invalid parents;<br>• concurrent cross moves;<br>• a move during a reserved activation → settlement by the snapshot chain, with conservation | H, HTTP |
| 10 | CSV / report / API leakage | `ExportStream` + `csvCell`; ExportCap; label masking; API allowlist | `FinanceReadTest`, `SettlementReportTest`, `ExportCapTest`, `PricingUxTest`, `ApiTest` | • N-07: quote / field and formula injection in `customers/csv`, `csv-prepaid` and the list export;<br>• `csvCell` unit cases (`" =1"`, `"\n=1"`, `"-12.5"`);<br>• N-10: per-route API JSON key diff for Agent / Sales / Report tokens;<br>• WP6B R-9;<br>• token-in-URL logging | HTTP |
| 11 | Ledger mutation / reservation leakage | append-only LedgerService; REVERSAL only; no triggers | `Unit/Wp3a/SourceGuardTest`, `LedgerTest`, `ReservationTest`, `ConcurrencyTest` | • static scan including plugins / CLI / pages;<br>• record the app user's UPDATE / DELETE privilege on ledger and audit (LPDB evidence);<br>• tamper detector;<br>• leak scan after every scenario;<br>• double cancel / rollback → one release;<br>• plugin-hook ledger write;<br>• N-09 visibility | H |
| 12 | UNKNOWN outcomes / unsafe retry / verified provisioning + finance failure | state machine; bounded retries; reconcile A / B; AMBIGUOUS hold | `Wp5a/ActivationFailureTest`, `ActivationReconciliationTest`, `Wp5b/ActivationReversal*`, `Wp8/FailureDrillsTest` | • a RADIUS write held (StubNas) > 300 s + reconcile → never released while the write may still land;<br>• N-06 CLI paths;<br>• a non-PDO throw after the RADIUS commit;<br>• settlement failure + double reconcile → one debit;<br>• parallel `reverse` ×2;<br>• untested codes: PRICING_SNAPSHOT_STALE, REVERSAL_RADIUS_NOT_RESTORED, RESERVATION_ALREADY_COMMITTED, ACTOR_FROZEN | H (StubNas) |
| 13 | Legacy recharge / cron / customer-order / payment callback / voucher / API / plugin / direct-device bypass | ServiceGate; Package funnel and pre-write refusal; device default deny; cron CLI-only; PaymentGatewayResolver; VoucherRedemptionService | `ServiceGateTest`, `Wp5b/*`, `Wp8/RechargePreWriteRefusalTest`, `DriverFailClosedLabTest` | • N-01: `customerAddPlan($owned, $plan, '')`; `remove_customer` with a caller-supplied `plan_expired` (4 drivers); Admin `plan_expired` + cron on an owned customer;<br>• `rechargeUser("5abc" / 0 / −1)`;<br>• customer-order (`order/buy`, balance purchase, gateway order) for an owned customer → refused before any write;<br>• a duplicate callback → one result;<br>• a barrier between the gate check and an assignment change;<br>• N-02 fixture plugins: a marker before RestGate, a throwing plugin is logged, two `cronjob` hooks;<br>• drift of a plugin-extended expiry | H; L planning only |
| 14 | CoA command injection | native UDP `CoAClient`; `RadiusPacket` length checks; NAS by exact IP | `RadiusSourceGuardTest::testNoShellInAnyRadiusPath`, `Wp4/CoAClientTest`, `RadiusPacketTest`, `Wp8/CoaDisconnectTest` | • hostile usernames (`a;id`, `$(id)`, 300 bytes, NUL) → binary only, no process;<br>• forged replies → ignored → UNKNOWN;<br>• extend the no-shell scan to all of `system/`, with a whitelist of the known constant calls;<br>• SEC-22 `html_php` decision;<br>• CoA wrong-source on 7.24.5 (L, planning only) | H; L planning |
| 15 | PWA stale authority / cross-user cache | literal precache list; network-only navigations; no runtime cache; prune on logout; offline non-GET never replayed | `Unit/Wp7/*`, `Integration/Wp7/*`, browser suite 32/32 | • user A → logout → user B, offline + back / forward: no A content (CacheStorage, HTTP cache, bfcache);<br>• role downgrade while open → 403;<br>• offline POST, then reconnect → no replay;<br>• customer-portal navigation;<br>• `Set-Cookie` / `Cache-Control` headers | B |
| 16 | Session / CSRF / secrets | CsrfPolicy; staff HMAC fingerprint / cookie | `Http/CsrfTest`, `AuthSessionTest`, `ApiSecret*Test`, `PagesBoundaryTest` | • N-03: forged `uid` (cookie and `?uid=`) with a known test `db_pass`, a future time, a magic link after a password change, cookie flags;<br>• NEW-7 inventory;<br>• NEW-8: a page with a static call / `{$_c}` rendered to a customer (documents the boundary);<br>• N-11 redaction | HTTP |
| 17 | CHECK-constraint equivalence | by-name verifier; readiness ignores constraints | `ConstraintTest`, `DriftAndRegistryTest` | N-08 on a disposable DB: a same-name weakened CHECK, MySQL `NOT ENFORCED`, MariaDB session `check_constraint_checks=0` → verify / readiness must report it (characterise first) | H |
| 18 | R-4 cron / renewal race | cron `flock` only | none | `ActivationFaults` barrier + real `php system/cron.php`, in both orders. Secure result: never a paid COMPLETED with suspended RADIUS or the recharge `off` | H |
| 19 | R-HF-CARRY-01 | `CUSTOMER_READ`; `autoload '*'` in API_ALLOWED | route-level only | Agent / Sales over web and API: `autoload/server`, `plan`, `bw_name`, `customer_is_active/<own>/<foreignRouterPlan>` (with `check_customer_online` on / off) | HTTP |
| 20 | OBS-S7-1 / OBS-S7-2 / OBS-W8-4 | — | `Wp8/DriverContractLabTest`, `DriverFailClosedLabTest`, `DriverClassificationSourceTest` | • `!trap` on remove + cron;<br>• NAT with a shared `pppoe_ip`;<br>• black-holed router timing;<br>• transport review | **L planning only** |
| 21 | AV-3 | free-text `@nuxr_operator`; audit table without append-only enforcement | A2-R drills | • release as the FreeRADIUS account under another name (characterise);<br>• UPDATE / DELETE on `nuxr_admission_audit` by the app and FreeRADIUS users (LPDB evidence);<br>• wrong token → REFUSED row | H |
| 22 | Ambiguous reply / verified provisioning + finance failure / recovery | see #12 | see #12 | covered in #12; CHR drill regression only if RADIUS code changes **and** CHR execution is approved | H; L planning |
| 23 | Secret leakage in UI / logs / review artifacts | NAS secret never rendered; per-commit sweep | `testNasSecretIsNeverRendered` | • router password in `routers/edit.tpl`;<br>• radpostauth passwords (Admin);<br>• API sanitizer;<br>• sweep of `owner-review/**` | HTTP |
| 24 | Closed SEC-01…25 regression | WP2 / WP4 / WP5B closures | per-WP tests | one regression case per closed SEC item; SEC-22 decision | HTTP |
| 25 | A2-R / OBS-1 | §6, §7 | WP4 admission suites; `DeviceGuardKeyCollisionTest`, `VoucherRedemptionTest` | §6.6 T0 only (A2-R); §7.6 (OBS-1) | T0; H |

**Completeness:** every area below maps to at least one row:
- the WP9 prompt (21 attack areas + REPORT);
- `14_WORK_PACKAGES.md` WP9 (12 areas);
- the owner's list (§3 of the rev-1 authorization);
- the lock's Phase B / E inputs.

---

## 13. Staged test plan

| Stage | Content | Services touched | Status |
|---|---|---|---|
| **T0 static / inert** | **Execution mode (rev 3):** the host has **no PHP runtime** (PHP 8.2 exists only in the `nuxbill-reseller-test-php82` Docker image), and Docker is not allowed in T0. T0 therefore runs as **Python 3 standard-library static checks** (`python3 -I -B`, no network, no DB, no container) over the repository text. Checks that need to **execute PHP** (the CSV `Format::csvCell` pure-unit cases, PHPUnit source guards) are reported **NOT TESTED** with an alternative for approval. The PHPUnit bootstrap (`tests/bootstrap.php`) only loads class files behind a CLI guard; database connections are opened in test setup (`tests/Support/TestDatabase.php:21-24`), not at bootstrap.<br>Contents: source-guard and pure-unit tests:<br>• A2-R statement inventory and sentinel table;<br>• no-shell scan of `system/`;<br>• ledger-writer scan, including plugins / CLI / pages;<br>• `csvCell` unit cases;<br>• NEW-7 key-use inventory;<br>• Smarty static-call inventory | **none** (no DB, no FreeRADIUS, no HTTP server) | **authorized** (rev 3, D-W9-R1) |
| **T1 harness characterisation** | rows #1–#13, #16–#19, #21, #23–#25 on disposable MariaDB 10.11 and MySQL 8.0; bounded security concurrency with real parallel workers | disposable harness containers (`nuxbill-reseller-test` only) | needs approval |
| **T2 HTTP / API** | AppServer-based cases of the same rows | disposable harness | needs approval |
| **T3 PWA browser** | row #15 | disposable PWA lab (127.0.0.1) | needs approval |
| **T4 CHR** | the lab parts of rows #13 / #14 / #20 / #22; A2-R regression gates only if RADIUS code changes; optional CoA wrong-source on 7.24.5 | isolated CHR 7.24.5 lab | **planning only** (OD-W9-10) |
| **Excluded** | • anything that reproduces, pressures, injects faults into or simulates FreeRADIUS SQL pool exhaustion;<br>• capacity, load and long-duration tests (WP10, OD-W9-11) | — | not planned |

---

## 14. Environment and tooling (reuse first)

| Need | Existing tool | Note |
|---|---|---|
| Harness | `dev/test/run-tests.sh` (PHP 8.2; disposable MariaDB 10.11, MySQL 8.0, two FreeRADIUS 3.2.8 containers); `tests/phpunit.xml` | new suites `wp9-unit`, `wp9-integration`; never while the CHR lab is up |
| Helpers | `tests/Support`: `Wp2Seed`, `Wp2HttpTestCase`, `HttpClient`, `FinanceSeed`, `FinanceWorkers`, `FinanceInvariants`, `ActivationTestCase`, `StubNas`, `RouteInventory` | no new framework |
| Fault hooks | `ActivationFaults`, `RadiusFaults` | never SQL pool / connection faults |
| PWA | `dev/pwa-lab/` (Python Playwright, 127.0.0.1) | as in WP7 |
| CHR | `dev/wp8/lab.sh`, production-contract sequence | planning only |
| Integrity | secret sweep (values never printed); `MANIFEST.sha256` 42/42; tree fingerprints | before any commit |

No new dependency, framework, service or agent type.

---

## 15. Files and system contracts that may change later (none approved; none modified)

| Area | Files (future, per wave) | Contracts / docs / pinned tests affected |
|---|---|---|
| A2-R (OD-W9-2 implementation) | `install/freeradius/nuxr_admission.sql`, `nuxr_admission_check.sql`, `sites-available/phpnuxbill` (post-auth S3a / S3b, C2), `README.md` | • docs: `docs/WP4_RADIUS_MODEL.md`, `docs/WP8_INTEGRATION_MODEL.md` §4;<br>• re-pin: `tests/Unit/Wp4/AdmissionSourceTest.php`, `SessionGuardSourceTest.php`, and the reference `dev/wp8/freeradius/option-g/post-auth.unlang`;<br>• also affected: `Integration/Wp4/AdmissionReservationContractTest.php` |
| OBS-1 (OD-W9-4 implementation) | new `system/autoload/Reseller/Domain/CustomerIdentity.php`; `CustomerService.php`; `system/controllers/customers.php`; `register.php`; `VoucherRedemptionService.php`; `LegacyReprovisionService.php` | • doc: `docs/WP8_INTEGRATION_MODEL.md` §9;<br>• keep green: `Wp5bSourceGuardTest`, `DriverClassificationSourceTest`, `RenameOldSessionUnitTest`, `LegacyRemovalSourceTest`;<br>• U1–U4 would add R0008 plus pinned migration-framework files |
| N-01 / N-02 | `system/devices/Radius.php` (+ optionally the MikroTik drivers); the `init.php` plugin loop (logging); possibly the `radius.php` order | extend `Wp5b/PluginBypassTest`; `docs/WP5B_LEGACY_CLOSURE_MODEL.md` |
| N-03 / NEW-7 | `system/autoload/User.php`, `Admin.php`, `system/boot.php`, `Message.php`, `Invoice.php`, `system/controllers/message.php`, `voucher.php`, `plan.php`, `accounts.php`, `forgot.php`, `register.php`; the config template | `Http/AuthSessionTest`; WP10 key provisioning |
| NEW-8 | `system/boot.php` (Smarty), `system/controllers/pages.php`, bundled page templates | `PagesBoundaryTest` |
| N-04 R-4 | `system/cron.php` (lock / re-check) and possibly the RENEW commit path (`LegacyBridge`) | `CronHardeningTest` |
| N-05 / P1–P5 | `ActivationService.php`, `StaffService.php`, `CustomerService.php` (per decision) | scope tests |
| N-06 | `system/reseller/reconcile.php` | — |
| N-07 | `system/controllers/customers.php` (exports), `system/autoload/Reseller/Ui/Format.php` | — |
| N-08 / WP1-CHECK | `system/reseller/Migration/SchemaInspector.php`, `Runner.php` (**SHA-pinned**, so `tests/Unit/Wp3a/SourceGuardTest.php` must be re-pinned), `system/autoload/Reseller/Domain/SchemaReadiness.php` | WP1 migration contract |
| N-11 | `system/autoload/Reseller/Security/ErrorReporter.php`, `ui/ui/admin/routers/edit.tpl`, `ui/ui/admin/logs/radius.tpl` | — |
| N-12 / R-HF-CARRY-01 | `system/controllers/autoload.php` | `RouteMap.php` is SHA-pinned and is **not** planned to change |
| AV-3 | `install/freeradius/nuxr_admission_release.sql` (design for a DB-attested identity) | README runbook; WP10 accounts |

---

## 16. Quality gates, acceptance, safety and stop criteria

### 16.1 Gates
- **Test-first:** characterisation runs before any fix.
- **No silent pass:** incomplete, skipped and aborted runs are never counted.
- **Both engines,** with counts reported per engine.
- **Negative cases:** each has a positive control and must cause no unauthorized business / finance / identity / service state change (AM-W9-2); expected refusal records are allowed and listed.
- **Frozen tree:** fingerprints taken around every validation run.
- **Integrity:**
  - manifest 42/42;
  - SourceGuard pins unchanged unless a named decision says otherwise;
  - secret sweep 0 values;
  - protected branches unchanged.
- **Lab:** inventory and teardown, if the lab is ever authorized.

### 16.2 WP9 acceptance criteria (proposal)
- Every §12 row has pass / fail evidence, or an owner-recorded reason it was not run.
- Zero open BLOCKER in the WP9 scope.
- **Ledger conditions:**
  - **WP1-CHECK closed** (the ledger forbids risk-accepting it);
  - NEW-7 and NEW-8 remediated per OD-W9-7 / 8 (required before production);
  - the plugin / direct-device red team done.
- A2-R: the classification changes only by an explicit owner decision.
- OBS-1: the invariant is enforced on every application writer and tested; the uniqueness option is decided.
- **Regression:**
  - full dual-engine harness green on the final tree;
  - CHR gates green if RADIUS / site / device code changed and CHR execution was approved.
- **Close-out:** `REPORT.md` evidence packet A–M; WP10 hand-over list; `PRODUCTION_NOT_AUTHORIZED` kept.

### 16.3 Safety, cleanup, rollback
- **Environment:** disposable databases only (`nuxbill-reseller-test`). No shared DB, production, AVAIA, `docker prune` or name-based kills.
- **Secrets:** never on command lines or in evidence.
- **Commits:** each fix is a separate commit on `wp9/red-team-rc` after its approval, and reverts cleanly.
- **Backups:** a full-tree backup before each implementation wave.
- **Schema options:** always with a read-only pre-scan and a drop-constraint rollback.
- **Evidence:** failed and aborted runs are kept.

### 16.4 Stop criteria for security tests
Stop the wave, keep the evidence, report, and wait for the owner when any of these happens:
1. a test proves a cross-tenant read or write, free service, a double debit or double period, a ledger mutation path, or a privilege escalation;
2. a refusal causes an unauthorized business, finance, identity, service or device state change (expected refusal records excepted, AM-W9-2);
3. a result contradicts an accepted WP0–WP8 claim;
4. an action would need any of:
   - the halted SQL-exhaustion technique, or any pressure, fault or simulation of it;
   - a shared or production system;
   - a real MikroTik;
5. a fix would need a schema, SourceGuard-pin, permission-policy or architecture change that no approval named;
6. a secret appears in any output or artifact;
7. a disposable environment cannot be cleaned back to its baseline.

---

## 17. Cross-review correction log (rev 1)

An independent read-only fact-check of rev 1 produced 14 corrections, plus two safety-wording items. All were applied in rev 1 and are carried into rev 2. No finding was withdrawn.

| # | Claim in the first draft | Correction (evidence) | Effect on the plan |
|---|---|---|---|
| 1 | Ledger: WP0–WP8 all `ACCEPTED_WITH_NOTES` | WP3B is `ACCEPTED` and WP6A `ACCEPTED_WITH_NOTE` (ledger `:13`, `:17`); the WP8 row text is stale after the merge | §1 accuracy; stale-text note added; no ledger edit |
| 2 | Release gates "Q1–Q4, P1–P3, N1–N7, REL-01–05, ISO-01 PENDING" | recorded PENDING: Q1–Q4, P1–P3, N6–N7, REL-01 / 04 / 05, ISO-01; REL-06 CONDITIONAL; the others partly or lab-proven | §3 no longer overstates the pending gates |
| 3 | N-05: a frozen actor's replay completes | `assertEligible` refuses frozen actors before `open()` (`ActivationService.php:60, 121-137`) | N-05 narrowed to "lost customer"; policy P1 reframed |
| 4 | N-07: one legacy export | `csv` and `csv-prepaid` (`customers.php:26-153`) have no escaping at all | N-07 widened (quote / field injection); matrix #10 cases added |
| 5 | N-12: always opens a RouterOS connection | only when `check_customer_online = yes` (`autoload.php:115, 151`) | precondition stated; severity unchanged (LOW-MEDIUM) |
| 6 | N-16: fail-open on SQL error is documented | the docs describe checkrad failing open; the SQL-error part rests on a secondary source | evidence level split (E2 / E3) |
| 7 | §6: identity validated "before any SQL" (`:228`) | S0 (`:209-211`) runs first, and SQL-User-Name is not covered | precise wording in §6.1 |
| 8 | §6: "every statement that fails is rejected" | L (`:478`) and accounting (`:96`, `:101`) are unchecked | no blanket fail-closed claim; the orphan-PENDING residual is stated |
| 9 | §6.5 pins: only SessionGuardSourceTest | `AdmissionSourceTest.php:126-131` (no ALTER) and `:100` (S3b) also pin these files | OD-W9-2 cost and the §15 re-pin list completed |
| 10 | README A2-R text "does not mention empty expansion" | the README asserts that pool sizing "removes pool exhaustion" and "never Accept" (`:235-238`, `:262-263`) | new finding **N-17**; README correction added to the design (L5) |
| 11 | WP1-CHECK: "closed or explicitly resolved by the owner" | ledger WP1: must be closed before WP9 acceptance, and not risk-accepted | acceptance criterion made strict (§16.2) |
| 12 | Load / concurrency stress moved to WP10 by default | the WP6B ledger note binds stress to WP9 | presented as an explicit decision (became OD-W9-11, now decided) |
| 13 | WP9 prompt has "22 areas"; customer-order had no case | 21 attack areas + REPORT; the customer-order case was missing | count corrected; customer-order cases added to row #13 |
| 14 | N-01: the MikroTik helpers are equally dangerous | they need an already-connected RouterOS client | nuance added; `customerAddPlan` / `plan_expired` identified as the meaningful paths; N-01 later re-classified MEDIUM (rev 2, §4.3) |
| S1 | "classification stays UNVERIFIED unless the owner decides otherwise" | could be read as leaving room for a re-run | "the reproduction itself is never re-run" added (§6) |
| S2 | rev-1 A-T3 (a literal '' in a test-only site copy) | it simulates the *effect* of exhaustion | routed to an owner decision in rev 1; **withdrawn** in rev 2 under OD-W9-3 |

---

## 18. Decision status (rev 3)

| ID | Decision | Status after the rev-3 owner decision |
|---|---|---|
| D-W9-R1 | Execute T0 (static / inert) | **APPROVED** with strict conditions (§0); execution mode §13. *Executed 2026-10-15; accepted as `WP9_T0_STATIC_ACCEPTED_WITH_NOT_TESTED`; T0+ PHP completion and T1-A authorized afterwards (REPORT.md)* |
| D-W9-R2 | T1–T3 on the disposable harness / PWA lab | pending (after T0 review). *2026-10-15: only T1-A (OBS-1 / voucher characterisation) was authorized; it stopped at V1 (REPORT.md §T1-A)* |
| D-W9-R3 | A2-R implementation scope | pending (design only, OD-W9-2) |
| D-W9-R4 | Rev-1 A-T2 DB-level CHECK test | pending (deferred) |
| D-W9-R5 | OBS-1 invariant I-1…I-5 + U0; U1–U4 separately | pending; lock design corrected (AM-W9-1, §7.3); U0 **not** implemented. **2026-10-15 status note:** U0 design accepted with D-U0-1…8 and implemented on `wp9/red-team-rc` (`WP9_U0_FULL_IMPLEMENTATION_AND_TESTING_AUTHORIZED`; `U0_DESIGN.md` §9, `REPORT.md` §U0); U1–U4 still separate decisions |
| D-W9-R6 | Voucher namespace | **accepted for the study only** (AM-W9-3); separate V1–V4 study (§7.7); OBS-1 not closable for production while collidable RADIUS identities remain |
| D-W9-R7 | Policies P1–P5 | pending (OWNER_POLICY_PENDING); no permission change |
| D-W9-R8 | NEW-7 option | pending (remediation required before production) |
| D-W9-R9 | NEW-8 option | pending (remediation required before production) |
| D-W9-R10 | Plugin trust policy and public-method guards | pending |
| D-W9-R11 | SourceGuard re-pins | pending; none in T0 |
| D-W9-R12 | CHR execution | pending (planning only) |
| D-W9-R13 | Commit of the plan files | **APPROVED:** one local commit of `PLAN.md` + `WP9_HANDOFF.md` after a diff audit and a secret sweep; no push; T0 evidence and `REPORT.md` are not part of that commit |

## 19. Readiness verdict

Definition of Ready:
- previous WP accepted ✔;
- ancestry known ✔;
- HEAD = canonical baseline, on the local branch `wp9/red-team-rc` ✔;
- scope and forbidden scope written ✔ (§2);
- frozen decisions D001–D040 unchanged ✔;
- acceptance tests defined ✔ (§12, §13, §16);
- environment exists ✔ (harness, PWA lab; CHR planning only);
- owner decisions OD-W9-1…11 recorded ✔; execution decisions listed (§18);
- rollback / data impact stated ✔;
- push / deploy = NO ✔;
- worktree clean apart from the untracked WP9 files ✔.

**No product code, database, RADIUS configuration or RouterOS device was modified in this stage.**

Rev 2 verdict: `WP9_PLAN_REVISED_READY_FOR_OWNER_APPROVAL` (accepted with amendments). Rev 3 status: amendments AM-W9-1…3 applied; T0 authorized; `PRODUCTION_NOT_AUTHORIZED`.
