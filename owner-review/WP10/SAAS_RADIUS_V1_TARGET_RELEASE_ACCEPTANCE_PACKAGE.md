# SAAS_RADIUS_V1_TARGET_RELEASE_ACCEPTANCE_PACKAGE

**Owner authorization in force:** `V1_OWNER_DECISIONS_APPROVED - AUTONOMOUS_CLOSURE_AUTHORIZED` (2026-10-18), on top of `CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED`,
`QUOTA_V1_GB_SUPPORT_APPROVED`, `RENEWAL_OPTION_B_APPROVED`.
**Date:** 2026-10-18 (UTC). **Previous package:** `SAAS_RADIUS_V1_FINAL_OWNER_RELEASE_DECISION_PACKAGE.md` (`RELEASE_BLOCKED_WITH_EVIDENCE`).
**Handoff:** `HANDOFF_V1_CONTINUOUS.md`. **Engineering decisions:** `DECISIONS.md` (E-N1-3, E-N1-4 added).

Scope of the execution: development, disposable MariaDB / MySQL, the isolated RouterOS CHR lab and synthetic data only; no production deployment or migration,
no real NAS or customer data, no live secret, no push / merge / force push, no unapproved risk acceptance; the SQL-exhaustion reproduction
(`a2r_sql_gate.py`, `a2r_gate.py pool|fault`) was never run, simulated or approximated (the capacity tool is paced, bounded, accounting-only).

---

> **Addendum (closure round, same day):** O-DL was studied under the owner's direction (no automatic cancellation, locked contracts untouched): `O_DL_CLASSIFICATION.md` (existing MariaDB contention limit, not a regression, fail-safe verified on both engines; no code change). The target staging package, the consolidated authorization request, the TA execution sheets with pass / fail criteria, the OBS-1 runbook, the A2-R owner evidence and the finished legal package were added; the overall status is unchanged: `RELEASE_BLOCKED_WITH_EVIDENCE`. Closing summary: `SAAS_RADIUS_V1_FINAL_OPERATIONAL_CLOSURE.md`.

## Final status

# **`RELEASE_BLOCKED_WITH_EVIDENCE`**

Every engineering task the owner authorized is done and verified on the final candidate. The release stays blocked for the reasons below, which are outside this session's authority:

| Mandatory condition still open | Why it cannot be closed here | Exact next step |
|---|---|---|
| **TA-01 .. TA-10 on the approved target** - all `NOT_RUN` (`docs/V1_TARGET_ACCEPTANCE.md`); the owner said they must be run before the time contract (TA-02, TA-06) and the quota settings (TA-05, TA-09) are production-ready | no target environment, staging NAS or hosts were named; no real NAS or production data may be touched without explicit approval | answer `TARGET_AUTHORIZATION_REQUEST.md` (one request: devices, resources, window, safeguards); the operator then runs TA-01 .. TA-10 |
| **D7 OBS-1** - the survey on real legacy data and the choice of who keeps each colliding key | owner decision on an isolated, authorized copy; the engineer must not choose or change real subscriber data | authorize the survey; run `identity_report.php` on the isolated copy; the owner fills the decision sheet (`OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md`) |
| **D10 LIC-1 legal review** | needs a qualified reviewer; the package for counsel is prepared (`LEGAL_REVIEW_PACKAGE.md`), 11 questions, 4 missing licence files, 1 clearance | counsel answers Q1 - Q11; then the artifact checklist items are closed |
| **D2 A2-R** - classification and production conditions C1 - C7 | the reproduction is forbidden; the owner must adopt the classification and decide C7 | adopt `A2R_RESIDUAL_RISK_CLASSIFICATION.md` (or amend); verify C1 - C6 on the target |
| **Production deployment authorization** | none was given | separate, explicit |

`READY_FOR_PRODUCTION_OWNER_APPROVAL` becomes available only when all ten TA rows are `PASS` with evidence files **and** the items above are decided.
Three different things stay separate: **engineering completion** (done, section 3), **formal acceptance** (D1 recorded as development acceptance with notes; WP9 / WP10 ledger rows
stay `PENDING`) and **production launch** (not authorized).

---

## 1. Owner Decision Record

Full record with the implementation map: `V1_OWNER_DECISION_RECORD.md`. Summary:

| Decision (2026-10-18) | Content | Where it lands | State |
|---|---|---|---|
| **N1** | PPPoE GB quota: **Option A + G**. A = native per-direction NAS limits + accounting enforcer. G = overshoot caused by detection / Disconnect delay is not charged and not carried into a renewal / new period; raw accounting kept whole; actual and billable usage separate; no debt or wallet movement; ledger history unchanged; boundary and finance cases tested; never sold as a hard byte-exact cap. Provisional `nuxr_quota_interim_seconds` = 30, `quota.php enforce --loop=55 --every=5`, final by TA-05 / TA-09 | section 4, 10; `QuotaUsage` | **Implemented; lab capacity verified; target TA-05 / TA-09 NOT_RUN** |
| **N2** | period ownership by accounting arrival order; UTC + NTP mandatory; verify clocks, boundaries, delayed accounting, Interim / Stop ordering, reconnect identity, duplicates / late arrival; TA-02 and TA-06 before the contract is production-ready | section 5; `docs/TIME_CONTRACT.md` | **Documented and lab-verified; TA-02 / TA-06 NOT_RUN** |
| D1 | WP9 accepted **for development with notes**, not production; recorded separately; register row `PENDING` | ledger note + record | **Recorded** (row unchanged) |
| D2 | A2-R: defensive treatment done; reproduction forbidden; not closed absolutely; classify and give production conditions | `A2R_RESIDUAL_RISK_CLASSIFICATION.md` | **Prepared; owner adopts; C1 - C7 on target** |
| D4 | AV-1: monitoring, alerting, audited manual recovery; **no automatic release** | runbook section 3; `V1OwnerDecisionGuardsTest` | **Verified by test** |
| D5 | canonical time contract | `docs/TIME_CONTRACT.md` | **Written; target tests NOT_RUN** |
| D6 | NAS secret boundary as documented; real NAS secrets untouched | `NAS_ONBOARDING_AND_SECRET_ROTATION.md` | **Unchanged; no secret touched** |
| D7 | OBS-1: survey only on an isolated authorized copy; report, options, backup / rollback; no winner chosen | `OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md`; `identity_report.php` | **Prepared and tested on synthetic data; real-data survey NOT authorized yet** |
| D8 | keep original voucher codes in financial records; mask where not needed; keep Option B | `Format::method / holder / maskCode`, templates | **Done, tested** |
| D9 | customer-UI RTL / contrast within the visual identity; assess jQuery; minimal change only if proven | `JQUERY_ASSESSMENT.md`; customer login clean in 3 layouts | **Done; jQuery upgrade not required for launch** |
| D10 | licence files, obligations, package for counsel; legal review not declared finished | `LEGAL_REVIEW_PACKAGE.md`; `LicenseInventoryTest` | **Prepared; review OPEN (counsel)** |

---

## 2. Final Git SHAs

| Item | Value |
|---|---|
| Repository / branch | `lucefardeavel/phpnuxbill-reseller`, `wp9/red-team-rc`. **Local only, nothing pushed.** |
| Base of this round | `aba467c4` (previous final package) |
| **Final candidate** | **`9da2015e`**: the full dual-engine harness, the CHR gates and the UI / PWA suites ran on exactly this product tree. Later commits (this package) are documents / evidence only |
| Product commits of this round | `32f0bfd8` Option G + new-period fix; `4f0481d6` D8 masking, D9 sink fixes, D4 guards; `f0a883bf` set-based enforcer; `a655d8c5` default interim 30 s; `fb279e03` customer AA colours |
| Test / evidence commits | `9da2015e` CHR evidence and a lab-test precondition fix; the capacity, UI, drill and load evidence is inside the commits above and below |
| Protected refs (unchanged, `git ls-remote origin`) | `feat/reseller-system-v1` = `wp8/integration-poc` = `704031c6`; `main` = `500d3a2d`; `hotfix/pace-xhr-csrf` = `6d4f3f96`; `wp7/staff-pwa` = `8e7f0e1c` |
| Engines | MariaDB 10.11.19, MySQL 8.0.46, PHP 8.2.34, FreeRADIUS 3.2.8 (pinned image), RouterOS CHR 7.24.5 (2 VMs) |
| Product diff of the round (`system/`, `install/`, `ui/`) | 27 files, +490 / -68 (`git diff aba467c4 9da2015e`); empty diff after `fb279e03`; empty diff for finance / migration / schema paths ; not touched: ledger, wallets, pricing, settlement, schema, migrations, `install/*.sql` |

---

## 3. Implementation and Verification Results

| Area | Delivered | Verification |
|---|---|---|
| **Option G** | `Reseller\Radius\QuotaUsage` (actual / billable = min(actual, quota) / overshoot, 64-bit exact in the database); Subscriber 360 card "Quota this period"; `quota.php usage USERNAME` | `V1QuotaUsageTest` 7 tests; `Subscriber360Test`; CHR: `V1OvershootLabTest` asserts it on the real NAS |
| **Defect fixed: activation in the same second as the previous period** | the old boundary was kept, so the previous period's overshoot rows were carried into a renewal; an activation now always opens a new period (`PeriodBoundary::newPeriod`) | failing first: `V1QuotaUsageTest` failed in 4 of 4 runs on MySQL and 1 of 4 on MariaDB (plus its first run) before the fix, because it depends on the second boundary (evidence `optg/prefix-loop`); 8 / 8 OK after |
| **Defect fixed: stored XSS in the bulk-message history** | customer names / phones / gateway text went unescaped into a DataTables table (a Sales user could run script in an Agent's / Admin's browser) | browser negative control: old template `executed: 1, injected images: 5`; fixed `None, 0`; PWA T24 now proves it on every run |
| **Defect fixed: remote version data into HTML sinks** | upstream `version.json` fed `.html()` and a SweetAlert title | text + restricted token; `ClientSideSinkInventoryTest` pins every remaining sink |
| **Capacity finding fixed** | the enforcer scan was O(subscribers): 5 - 9 s per pass at 5000 online quota subscribers (longer than `--every=5`); now set-based | section 10; `V1QuotaEnforcerEquivalenceTest` (differential, both engines; failed twice first, found two real differences) |
| N2 matrix | 4 new tests: same-second activation, out-of-order Interim / Stop / Start, reconnect + retransmitted Stop, delayed Start | `V1PeriodOwnershipTest` 11 tests, both engines |
| D8 | `Format::method / holder / maskCode`; reports, print views, receipts, customer activation list | `W9RedTeamHttpTest` D8 test, `VoucherMaskTest` |
| D9 customer UI | `dir=rtl` + the RTL layer, AA-contrast buttons / links in the same hues (theme `!important` rules overridden) | UI audit: customer login clean in desktop, phone, tablet; screenshots inspected |
| D4 / D6 guards | admission release only from the operator CLI; no billing code reads usage; no secret option on a CLI | `V1OwnerDecisionGuardsTest` |
| D7 | `identity_report.php` (read-only, SELECT only, no winner) | `V1IdentityReportTest` |
| D10 | licence inventory cannot drift from `composer.lock` | `LicenseInventoryTest` |
| Interim default | 30 s (E-N1-3) | `V1QuotaPolicyTest`, `V1QuotaFreeRadiusTest`, `V1VoucherQuotaTest`, CHR |
| Failures kept as evidence | authoring errors and real findings are preserved: `equiv1`, `equiv2`, `d8/run1`, `d8/run2`, the superseded capacity runs, the CHR run with the timing precondition, `d9/run1-fatal-name-clash`, the failing MariaDB load run (`final-v2/load/...FAILED...`) and the repeat runs (`load-repeat*`, `load-contract-hunt*`) | `owner-review/WP10/evidence/` |

Note on honesty of the record: one new guard test's first run (`d4/run1`, an expectation error in a brand-new test) was deleted before it was committed; everything else is kept.

---

## 4. N1 + G Commercial Quota Contract

Full text: `N1_G_COMMERCIAL_QUOTA_CONTRACT.md`. In short:

- **Sold as:** a quota per service period, enforced by the network equipment while the session runs and checked continuously from accounting - **not a hard, byte-exact cap**. Allowed wording: *"Data quota is enforced by the network equipment and checked continuously; usage can exceed the quota by a few seconds of line speed."* Never "exactly X GB", never a percentage.
- **Normal operation** (enforcer, FreeRADIUS, database and CoA path working): overshoot <= `min(R, r x (I + P + c))` - with the shipped settings about **36 s of line rate** (I = 30 s, P = 5 s, c <= 1 s), about 40 s with CoA retransmissions. Example: a 100 / 100 Mbit/s PPPoE line with both directions saturated: about 0.9 GB (about 1.0 GB with retransmissions).
- **Failure operation** (enforcer stopped, FreeRADIUS / database down, NAS unreachable by CoA): on PPPoE up to **the whole remaining quota again (total use up to 2 R)**; Hotspot keeps its native total limit. A CoA outage extends the overshoot by the outage plus the 15 s retry (measured: 38 s after crossing with a 40 s block). A quota crossed before the first interim on a small quota is cut by the NAS cap with an overshoot of up to the whole quota. These are reported separately from normal operation and not described as smaller than they are.
- **Option G rules (all tested):** raw accounting kept whole; actual vs billable usage are separate numbers; the overshoot creates no debt, no wallet or ledger movement; it is not carried into a renewal or any new period; a same-second activation still opens a new period; no finance class reads usage; ledger history and settlement unchanged.
- **Settings:** `nuxr_quota_interim_seconds` = 30 (now the shipped default), `quota.php enforce --loop=55 --every=5`. Confirmed in the lab (section 10); **final confirmation is TA-05 / TA-09 on the target (NOT_RUN)**.

Real-NAS evidence (RouterOS 7.24.5 CHR, mixed upload + download PPPoE, lab line rate about 1 Mbit/s per direction): `V1OvershootLabTest` 10 tests passed in the final run, including the Option G assertions on the real NAS (billable = quota, overshoot reported, no ledger / wallet change); matrix in `evidence/quota/n1/chr/overshoot-table.md` and `evidence/final-v2/chr/`.

---

## 5. N2 Time Contract

Canonical text: `docs/TIME_CONTRACT.md`. UTC and NTP are mandatory (app / database / FreeRADIUS drift <= 5 s, NAS <= 60 s; health warns at 120 s ahead); period ownership is by arrival order (`Nuxr-Period-Acct-Id`), independent of the NAS clock; an activation always opens a new period.

| Owner's verification item | Result |
|---|---|
| App / database / FreeRADIUS clock discipline | preflight `time.sql_session_zone` (UTC), `time.clock_skew` (<= 5 s PASS, <= 30 s WARN): `AccountingTimeContractTest`, `W9PreflightGrantsTest` family; lab: 0 s skew |
| RouterOS clock discipline | CHR lab (`V1ClockLabTest`): offset **-1 s**; **the CHR NTP client is disabled (status stopped)** - the lab proves nothing about a production router's NTP: TA-02 |
| Renewal period boundaries | `V1PeriodOwnershipTest` (skew -300 .. +300 s, re-provision vs renewal, same-second activation); CHR `testRenewalIsExactEvenWhenTheOldSessionStartedSecondsBefore` |
| Delayed accounting | `testADelayedStartAfterTheRenewalBelongsToTheNewPeriod` (counted in the new period, conservative, seconds of traffic) |
| Interim / Stop ordering | `testOutOfOrderInterimStopAndStartKeepOneRowAndNeverLowerTheUsage` |
| Reconnect and session identity | `testReconnectsAddTheirUsageAndARetransmittedStopAddsNothing` |
| Duplication and late arrival | `testLateInterimAndDuplicateStartNeverMoveARow` |

**TA-02 and TA-06 are `NOT_RUN`**: until they pass on the target the time contract is documented and lab-verified, **not production-ready**.

---

## 6. WP8 / WP9 / WP10 Closure

`dev/wp10/closure_check.py --testdox <full-run log>` re-verifies every roadmap item against the tree (control present, cited test class present, ran on both engines without failure): ****44 items, exit 0**** (`evidence/final-v2/closure-matrix.md`). Classes: CLOSED, CONTRACT, DEPLOY (checked by the preflight on the target), LAB (real-NAS proof, run on the CHR).
New items this round: V1-G, V1-CAP, D7, D8, D9, D10, N2-M, LAB-CLOCK. The WP8 register (31 rows), WP9 findings and WP10 requirements keep the status of `SAAS_RADIUS_FINAL_PRODUCTION_READINESS_PACKAGE.md` section 2; the open items there are exactly the ones in section 14 of this package. **D1:** WP9 is accepted for development with notes; the ledger rows `WP9` and `WP10` remain `PENDING` (owner-controlled; WP9 still carries production blockers).

---

## 7. Full Regression Results

Final candidate `9da2015e`, snapshot worktree, `dev/test/run-tests.sh --do-not-cache-result --testdox`, both engines (log `evidence/final-v2/full-run-9da2015e.log`):

| Gate | Result |
|---|---|
| MariaDB 10.11.19 | **OK: 1561 tests, 76805 assertions, 0 failures / errors / warnings**, PHPUnit deprecations 1, 125 incomplete (the real-NAS lab classes, run on the CHR: not counted as passes) |
| MySQL 8.0.46 | **OK: 1561 tests, 76807 assertions, 0 failures / errors / warnings**, PHPUnit deprecations 1, 125 incomplete |
| Variant registry / fingerprints | identical on both engines and identical to the previous rounds (V1 `3ca25964...`, V2 `6c2f04ea...`, V3 `e1a66aa3...`): no schema change |
| CHR security gates | **OK 10 tests / 216 assertions** |
| CHR `wp8-integration` | 122 tests / 2383 assertions, 13 mode skips, **1 failure in the full run** (a lab-test timing precondition, kept) -> test corrected, rerun of the V1 lab classes **OK 10 tests / 224 assertions**; the other classes of that run passed on the same product tree |
| GB quota and renewal | `V1Quota*`, `V1Renewal*`, `V1Period*`, `V1Voucher*`, `V1PlanQuotaForm`, CHR `V1QuotaLab`, `V1OvershootLab` |
| UI audit | 136 page loads, 3 layouts, 6 staff roles + customer login: the only 8 flags are the expected 403 denials; customer login clean |
| PWA | **32 / 32 PASS** (T24 extended with the bulk-history XSS proof); history after logout: no leak |
| Backup / restore / rollback drill | **OK on both engines, 1 test / 128 assertions each** (`evidence/final-v2/drill/`) |
| Bounded load | **PASS on both engines** (4 final runs, `evidence/final-v2/load-final/`): L1 96 / 96, L2 96 / 96, L3 8 completed + 40 refused `ACTIVATION_IN_PROGRESS`; peak 15 of 151 connections. **Disclosed finding:** the first MariaDB run on this candidate failed its strict L2 assertion (95 completed + 1 clean `CONCURRENCY_RETRY` refusal); investigation and resolution in section 14, item 6 |
| Manifest `docs/claude-development-pack/MANIFEST.sha256` | **42 / 42 OK** |
| PHP lint | **1398 tracked PHP files, 0 errors** |
| Secret sweep (7 known secret values + key markers, every tracked and untracked file) | **0 hits** (11 batches, all tracked and untracked files) |

---

## 8. CHR and FreeRADIUS Evidence

RouterOS CHR 7.24.5 (2 VMs), FreeRADIUS 3.2.8 with the shipped site, production-contract bring-up (`chr_up.sh`); lab torn down afterwards (`LAB_DOWN`: 0 VMs / netns / links / containers).
Evidence: `evidence/final-v2/chr/` (`chr-gates.log`, `security-gates.out`, `wp8-integration-run1-1-failure-timing-precondition.out`, `v1-lab-rerun.out`, `lab-evidence-full.jsonl`).

- Security gates (A2 concurrent first login, username equivalence PPPoE + Hotspot, shared users): 10 / 216.
- Integration: Option G, Port-Limit, equivalence, A2-R race gates, V1 quota, N1 overshoot (11 evidence rows), N2 exact renewal, A3 rename, failure drills, driver contracts.
- Limitations (the CHR is **not** proof for the target): RouterOS 7.24.5 on CHR only; lab line rate about 1 Mbit/s per direction (bigger plans are extrapolated by the model); no WAN loss; no real access network; **the CHR NTP client is disabled**; the CoA limits / interim findings may differ on other RouterOS versions (TA-04).

---

## 9. Staging / TA-01 .. TA-10 Results

The isolated lab was prepared and used as the staging stand-in (synthetic data only). **No target environment exists in this session, so no TA row is `PASS`.**

| ID | Test | Target result | Lab stand-in (not the target) |
|---|---|---|---|
| TA-01 | preflight + health | **NOT_RUN** | `evidence/final-v2/ta-lab/`: preflight on the lab app copy FAILs exactly where it should (lab account holds `ALL PRIVILEGES`, no admission tables) and passes the rest |
| TA-02 | clock discipline | **NOT_RUN** | CHR offset -1 s; CHR NTP **disabled** |
| TA-03 | NAS contract | **NOT_RUN** | CHR production-contract bring-up and integration run |
| TA-04 | quota capability probe | **NOT_RUN** | measured on CHR 7.24.5: PPPoE ignores Total-Limit, per-direction limits enforced, CoA does not change byte limits or interim, Disconnect confirmed |
| TA-05 | GB overshoot (N1) | **NOT_RUN** | CHR matrix, Option G assertions |
| TA-06 | renewal Option B | **NOT_RUN** | CHR `V1QuotaLabTest` (renewal, immediate redial, exact ownership) |
| TA-07 | failure injection | **NOT_RUN** | CHR faults (CoA closed 40 s, NAS link, FreeRADIUS down, enforcer stopped) |
| TA-08 | deploy / backup / restore | **NOT_RUN** | drill on both engines |
| TA-09 | capacity | **NOT_RUN** | section 10 |
| TA-10 | security on target | **NOT_RUN** | harness security suites; TLS / proxy behaviour untested |

Preconditions P1 - P5 are **not satisfied** (no authorization, no NAS, no staging copy named). The single authorization request is `TARGET_AUTHORIZATION_REQUEST.md`; it asks for the staging NAS (same RouterOS version / model), the access segment and synthetic client, the staging hosts, NTP, least-privilege accounts, an operator, a window and a backup, and lists the safeguards.

---

## 10. Capacity and Accounting Load

Full document: `CAPACITY_AND_ACCOUNTING_LOAD.md`. Lab only (12 vCPU shared host, throwaway databases); **not TA-09**.

- Tool: `dev/wp10/acct_load.sh` (paced, <= 8 requests in flight against a pool of 32, accounting only, enforcer as its own process). Not the forbidden test; no pool or SQL error in any FreeRADIUS log; no refused connection.
- **Finding:** the frozen-candidate enforcer scan took 4.8 - 5.4 s (max 9.4 s) per pass at 5,000 online quota subscribers on both engines, longer than `--every=5`; fixed (set-based, proven equivalent).
- **After the fix**, both engines: zero lost requests, p99 < 400 ms (max 607 ms), enforcer pass 0.03 - 0.5 s average (max 1.04 s) up to **7,500** online quota subscribers at 250 Interim-Updates/s (interim 30 s); database load halved.
- The earlier model figure "2,000 subscribers" is **not** a limit of the mechanism; it is also **not** a promise for the target hardware. The shipped default stays 30 s; if TA-09 shows a target cannot carry the planned peak, the lever is the interim (it doubles the normal-operation overshoot bound when doubled).

---

## 11. Security and Finance Integrity

- Security: CHR gates 10 / 216; the WP9 red-team, XSS crawl, CSRF, session, secret-redaction, autoload-exposure, grants, key-separation suites inside the full harness; two new real XSS / injection paths found and fixed this round (bulk-message history, remote version data); voucher codes masked; the jQuery assessment (no exploitable path remains; upgrade not required for launch; plan documented).
- Finance: no finance, wallet, pricing, settlement, schema or migration file changed. An overshoot creates no ledger or wallet movement (`V1QuotaUsageTest`, CHR); RENEW settles exactly once; `SettlementReadModel::integrity()` empty after every case; concurrency suites (`W9CronRenewalRaceTest`, `U0RaceTest`, `ActivationReversalRaceTest`, bounded load) pass on both engines; no history deleted.

---

## 12. Backup/Restore and Recovery

- Drill on both engines on the final candidate: **OK on both engines, 1 test / 128 assertions each** (`evidence/final-v2/drill/`) (dump, restore, CHECK constraints survive, migration rollback). No schema change this round.
- Recovery: `reconcile.php` (finance-check, voucher-review / resolve, admission-pending / release), `quota.php session-end / usage / report`, drift report, health (`quota_enforcer`, `nas_clock`, `admission_pending`); no automatic release of a PENDING claim (guard test, D4). OBS-1 backup / rollback plan: `OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md` section 5.
- Not run: restore from the target's own backup chain, application-code rollback rehearsal (TA-08).

---

## 13. Legal Review Status

**LIC-1: OPEN - `COUNSEL`.** Package: `LEGAL_REVIEW_PACKAGE.md` (facts, 11 questions, release-artifact checklist) and `docs/THIRD_PARTY_LICENSES.md`, kept honest by `LicenseInventoryTest`. Facts counsel must see: no version statement in the GPL-2.0 `LICENSE` (Q1) combined with LGPL-3.0 Smarty (Q2); two GPL-2.0 Composer libraries; four Composer packages ship no licence file (to be obtained from upstream); a third-party photograph inside a vendored package; delivery model A (operated) vs B (delivered) decides the obligations. The legal review is **not** declared finished.

---

## 14. Remaining Risks and Decisions

| # | Item | Class | What closes it |
|---|---|---|---|
| 1 | TA-01 .. TA-10 (incl. TA-02 / TA-06 for the time contract and TA-05 / TA-09 for the quota settings) | **blocker** | the authorization request, then the operator's runs |
| 2 | D7 OBS-1 on real data and the choice of key holder | **blocker, owner** | authorized isolated copy; the owner's decision sheet |
| 3 | D10 / LIC-1 legal review | **blocker, counsel** | counsel's answers; missing licence files from upstream |
| 4 | D2 A2-R: classification adoption, C1 - C6 on the target, C7 decision | **blocker, owner** | `A2R_RESIDUAL_RISK_CLASSIFICATION.md` |
| 5 | Production deployment authorization | owner | separate |
| 6 | **O-DL: deadlock refusal under a single-wallet stampede.** Studied in the closure round (`O_DL_CLASSIFICATION.md`): old and new candidates behave the same (refusals 4 / 1,800 versus 5 / 1,800 operations on MariaDB, 0 on MySQL in 3,600), so it is an **existing MariaDB contention limit, not a regression and not test variability**. Fail-safe verified on both engines: every refusal leaves the activation `FUNDS_RESERVED` with one ACTIVE reservation, no service, no ledger entry; the customer's retry is refused (`ACTIVATION_IN_PROGRESS`); alerts after 5 / 30 min (`activations_stale`); operator resume 109 ms on average (one settlement) or cancel; final accounting OK in every run. No automatic cancellation (owner direction); the locked retry bound (2) is untouched; a jittered-backoff variant (same bound) took refusals from 8 / 5,400 to 0 / 5,400 in the lab and is documented for a **separate authorization** | engineering view: accepted production residual; the owner records it | the owner's classification; TA-09 counts it on the target engine |
| 7 | N1 weak case: small quota on a fast line (overshoot up to the whole quota on PPPoE when the NAS cap fires first) | accepted by the owner's N1 decision, stated in the contract | plan design guidance (quota >> line rate x 36 s) |
| 8 | Enforcer / FreeRADIUS / database outage => up to 2 R on PPPoE | stated separately, monitored (`quota_enforcer` CRITICAL) | TA-07, alerting |
| 9 | RouterOS version / model dependence of the CoA and limit findings | target | TA-04 |
| 10 | NAS NTP (the CHR lab has it disabled) | target | TA-02 |
| 11 | jQuery 1.11.1 stays; legacy client libraries | accepted (assessment), post-launch hardening plan | `JQUERY_ASSESSMENT.md` section 6 |
| 12 | Residuals documented earlier (L-A4, L-1 .. L-5, R-FC-2 / 3, OBS-W8-6, W8-31) | accepted-by-design | unchanged |

---

## 15. Production Approval Checklist

| # | Item | State |
|---|---|---|
| 1 | V1 plans, in-session quota, Option B, Option G, N2 | **DONE and verified (lab, both engines, CHR)** |
| 2 | Final candidate gates: full harness, CHR, GB quota + renewal, finance, recovery, drill, security, UI, PWA, SourceGuard / manifest / lint, secret sweep, bounded load, accounting capacity | **DONE on `9da2015e`** (section 7) |
| 3 | Owner decisions N1, N2, D1, D2 (adoption), D4 - D10 recorded and implemented / prepared | **DONE** (D1 as dev acceptance; the register rows stay `PENDING`) |
| 4 | TA-01 .. TA-10 on the approved target, evidence files attached | **NOT_RUN** |
| 5 | P1 - P5 preconditions (authorization, staging NAS, backup, synthetic data, secrets handling) | **OPEN** |
| 6 | OBS-1 survey on an isolated authorized copy and remediation | **OPEN** (owner) |
| 7 | A2-R production conditions C1 - C6 verified on the target, C7 decided | **OPEN** |
| 8 | Legal review (LIC-1) | **OPEN** (counsel) |
| 9 | Cron `quota.php enforce --loop=55 --every=5` + health monitoring on the target | TARGET |
| 10 | NTP on every host and NAS | TARGET |
| 11 | Deployment, TLS, least-privilege accounts, FreeRADIUS pool sizing on the target | TARGET |
| 12 | WP9 / WP10 ledger rows | owner-controlled, `PENDING` |
| 13 | Production deployment authorization | **NOT GIVEN** |

Meaning of the status: inside the authorized scope there is no known engineering defect and every runnable gate passed; the release is **not** production-ready because mandatory target evidence, owner decisions on real data and counsel's review do not exist yet. Nothing was pushed; Google Drive and Basic Memory were not updated.
