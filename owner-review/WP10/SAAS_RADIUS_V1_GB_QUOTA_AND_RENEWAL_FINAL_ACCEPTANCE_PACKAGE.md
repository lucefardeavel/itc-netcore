# SAAS_RADIUS_V1_GB_QUOTA_AND_RENEWAL_FINAL_ACCEPTANCE_PACKAGE

**Owner authorization:** `QUOTA_V1_GB_SUPPORT_APPROVED`, `RENEWAL_OPTION_B_APPROVED`, `AUTONOMOUS_RELEASE_COMPLETION_AUTHORIZED` (2026-10-17).
**Plan:** `owner-review/WP10/V1_QUOTA_RENEWAL_PLAN.md`. **Previous package:** `SAAS_RADIUS_FINAL_PRODUCTION_READINESS_PACKAGE.md` (`RELEASE_BLOCKED_WITH_EVIDENCE`, blocker D3).
**Date:** 2026-10-17.

Scope of the execution:
- development, disposable MariaDB / MySQL databases, the isolated RouterOS CHR lab and synthetic data only;
- no production deployment, migration, secret or customer data; no real router; AVAIA not touched;
- no push, merge or force push (protected refs verified unchanged, §1);
- the SQL-exhaustion reproduction (`a2r_sql_gate.py`, `a2r_gate.py pool` / `fault`) was not run, simulated or retried;
- no schema, ledger, pricing or finance code was changed (§10).

This package records **no owner acceptance**. Ledger rows are as the owner left them.

---

## 1. Exact Git SHAs

| Item | Value |
|---|---|
| Repository / branch | `lucefardeavel/phpnuxbill-reseller`, `wp9/red-team-rc`. **Local only, not pushed.** |
| Base (verified at the start) | `e45899dd456d6df5ca63a016e9664aaebf45c1ed`, product tree `46624cd8`, worktree clean |
| Final product code | last product commit `9a869280` (preflight check). Later commits are tests / docs / evidence only. |
| Final harness tree | `32cdc92d` (full dual-engine run on exactly this commit, §6) |
| Protected refs (verified with `git ls-remote`, unchanged) | `feat/reseller-system-v1` = `wp8/integration-poc` = `704031c6…`; `main` = `500d3a2d…`; `hotfix/pace-xhr-csrf` = `6d4f3f96…`; `wp7/staff-pwa` = `8e7f0e1c…` |
| Engines | MariaDB 10.11.19, MySQL 8.0.46, PHP 8.2.34, FreeRADIUS 3.2.8 (pinned image), RouterOS CHR 7.24.5 (2 VMs) |

Commits of this round (oldest first; each commit body holds its own evidence):

| SHA | Content |
|---|---|
| `4fe1b190` | plan, measured NAS capabilities (`quota_gate.py`) |
| `2279a787` | **Q2**: V1 data quota inside the session, Hotspot + PPPoE (builder, FreeRADIUS site, plan forms) |
| `9cca32dd` | **Q2**: accounting enforcer, `SessionTermination`, `quota.php`, health check |
| `02860410` | **Q1 / Option B**: RENEW ends and confirms the previous-period session; drift; legacy device |
| `e3d1b693` | operator docs, regression evidence |
| `e4162092` | **fix**: NAS-clock allowance in the period attribution (found on the real NAS, §5) |
| `8b6f5baa` | **fix**: one Disconnect per session in a rename operation (A3 regression found on the real NAS, §5) |
| `37c1aead`, `3bf52486` | real-NAS V1 suite, measured cap bounds, deterministic renewal tests, Hotspot renewal |
| `9a869280` | preflight `quota.plans`; voucher GB tests |
| `a4ca655c`, `32cdc92d` | drill / load / UI and final real-NAS evidence |

Product code changed (`system/`, `install/`, `ui/`): 21 files, +867 / −68. Not changed: anything under `Reseller/Finance`, pricing, the migrations or `install/*.sql`.

---

## 2. Owner Decision Record

| Decision (2026-10-17) | Content | Implemented as |
|---|---|---|
| `QUOTA_V1_GB_SUPPORT_APPROVED` (D3 / Q1 / Q2) | V1 supports time, speed, GB and composite plans; the data quota is controlled **inside** the session, not only at the next login; native NAS enforcement preferred; accounting + Disconnect allowed with computed and tested bounds, never described as an exact hard cap | §3, §8 |
| `RENEWAL_OPTION_B_APPROVED` (P2) | on renewal the old session is terminated and the outcome verified before the new session / policy is relied on | §3, §9 |
| `AUTONOMOUS_RELEASE_COMPLETION_AUTHORIZED` | the cycle ANALYSE → … → RELEASE READINESS without per-step approval, within the listed authorizations and prohibitions | this package |

Not decided by this round (unchanged, §15): D1, D2, D4 – D10. They were not treated as approved. A2-R stayed defensive only (no SQL-exhaustion reproduction, no self-reclassification); OBS-1 stayed unresolved (no survey on real data, no identity ownership decision); NAS secrets were not changed.

---

## 3. Q1 / Q2 functional contract

| Plan type | Enforcement |
|---|---|
| **Time** (`Max-All-Session`) | `Session-Timeout` = the remaining period time, native on Hotspot and PPPoE. Concurrent Hotspot sessions are reconciled by the enforcer. |
| **Speed** (`Mikrotik-Rate-Limit`) | unchanged |
| **GB** (`Max-Data`, 1 GB = 10⁹ bytes, upload + download, per billing period) | at every login FreeRADIUS replies the session's share of the remaining quota: `floor((Max-Data − period usage) / Simultaneous-Use)` as `Mikrotik-Total-Limit`, `Recv-Limit`, `Xmit-Limit` (+ Gigawords) and `Acct-Interim-Interval = 60`. Hotspot: the NAS cuts the session at the share. PPPoE: the NAS cuts each direction at the share. The accounting enforcer (`quota.php enforce --loop=55`, pass every 15 s) ends sessions whose period usage reached the quota (PPPoE upload + download together, concurrent Hotspot sessions): Disconnect + confirmed Stop, audited. |
| **Composite** (duration + speed + GB) | all of the above together; the duration is the billing validity / `Expiration` |

Fail closed: a share of 0 (RouterOS reads 0 as unlimited), an exhausted quota, a malformed or duplicated quota row, an emptied or failed SQL expansion all **reject** the login; a Limited plan with a zero / negative / non-numeric limit cannot be saved and is never provisioned (`POLICY_QUOTA_INVALID`).

**Period attribution (Q1).** A session counts in the period in which it **started**. The stored boundary `Nuxr-Period-Start` is the period start minus 10 s (`QuotaPolicy::PERIOD_SKEW_SECONDS`): the NAS stamps session starts 1.1 – 1.7 s early (§5). Cost: a session that started up to 10 s before a renewal is also counted in the new period (the renewal ends it). Requirement: NTP on every NAS (checklist H8). Accounting history is never deleted.

**Option B renewal (Q1).** After the renewal is provisioned and verified, every open session that started before that moment gets **one** Disconnect and its end is **CONFIRMED** only by NAS-side evidence (Disconnect-ACK **and** its Accounting-Stop, or NAK 503). A Disconnect-ACK alone is not proof; RouterOS answers NAK 406 for a vanished session, which stays unconfirmed. An unconfirmed end never undoes the renewal (it settles exactly once); it is reported as drift `SESSION_POLICY_STALE` and ended by the operator command. The old session cannot exceed its own grant. Applies to activation RENEW / CHANGE_PLAN / REACTIVATE and to the legacy recharge / voucher renewal. Inside a rename operation a session the device already ended and confirmed is not sent a second Disconnect (exact radacctid + NAS + Acct-Session-Id).

---

## 4. PPPoE and Hotspot capability matrix (RouterOS CHR 7.24.5 + FreeRADIUS 3.2.8, measured)

| Mechanism | PPPoE | Hotspot |
|---|---|---|
| `Mikrotik-Total-Limit(-Gigawords)` in Access-Accept | **ignored** (limits 0; 2.5 MB passed a 2 MB budget) | **enforced**, Gigawords combined (2³² + 5 verified) |
| `Mikrotik-Recv-Limit` / `Xmit-Limit(-Gigawords)` | **enforced per direction** | supported |
| `Session-Timeout` | enforced | enforced |
| `Acct-Interim-Interval` | honoured (10 s → 5 updates in 45 s) | honoured |
| CoA byte limit mid-session | ACKed, **not applied** | NAKed |
| CoA `Session-Timeout` mid-session | ACKed, applied | — |
| Disconnect-Request | ACK + Stop (`NAS-Request`) | ACK + Stop |
| Disconnect of a vanished session | **NAK 406** (never 503; measured) | NAK 406 (the WP8 NAS matrix: any request without a matching session) |
| Total-quota cap | per direction hard; **upload + download together: accounting enforcer** | hard per session (not byte-exact); concurrent sessions: shares + enforcer |

**Verdict on the owner's PPPoE rule.** PPPoE *can* be capped in-session: each direction natively (hard, not byte-exact) and the total by the accounting enforcer with a computed and tested bound. It is not a login-only fallback and it is not described as an exact cap. No release blocker arises from it; the bound is a product acceptance item (N1, §15). Options are listed there.

---

## 5. Before / After evidence

Every defect was reproduced by a failing test first (kept under `evidence/quota/…/prefix`), then fixed, then re-run green on both engines and, where it concerns the NAS, on the CHR.

| # | Finding | Found by | Before → after |
|---|---|---|---|
| 1 | Data quota enforced only at the next login; PPPoE had no quota | owner decision / `W9QuotaGateCharacterisationTest` (superseded) | login-only → in-session share + enforcer (`2279a787`, `9cca32dd`) |
| 2 | RENEW left the old session running with the old policy | CHR S5 (`RealLifecycleTest`) | no disconnect → one Disconnect, Stop-confirmed (`02860410`) |
| 3 | A quota row `Max-Data = 1e9` was **accepted** (Access-Accept) | real FreeRADIUS test | fail-open → rejected "Quota unavailable" (`2279a787`) |
| 4 | A GB-only Hotspot plan form returned HTTP 500 (strict SQL on an empty limit) | form test | 500 → saved with 0 for the unused limit |
| 5 | PPPoE form stored a missing unit as Hrs / GB while the policy reads Mins / MB (60× / 1000× mismatch) | code review, `V1PlanQuotaFormTest` | mismatch → stored as read |
| 6 | Legacy renewal sent two Disconnects per session | review, `V1LegacyRenewalTest` | 2 → 1, confirmed |
| 7 | **The first session after a renewal escaped the new quota.** A time-zone mismatch was suspected and **refuted** (all sessions UTC, MariaDB and MySQL). Measured cause: the NAS stamps Accounting-Start 1.1 – 1.74 s before the host receives it (4 captures), so a login within ~2 s of the period start — the PPPoE redial after the Option B Disconnect — was booked to the previous period | CHR mixed-traffic test (enforcer did not act) | 3 tests failing on both engines → green; boundary = start − 10 s (`e4162092`) |
| 8 | **A3 rename regression** (case-only rename: CONFIRMED → UNCONFIRMED): the device re-provision now ends the session with a Stop-confirmed termination, so A3's own later Disconnect got NAK 406. The baseline had passed only by racing the PPPoE teardown | full real-NAS `wp8-integration` | harness tests failing on both engines (`COA_NO_RESPONSE`) → green; CHR A3 suite 6 / 141 (`8b6f5baa`). NAK is **not** treated as success: the "CoA not accepted" case is still UNCONFIRMED |
| 9 | Existing `Limited` plans with a zero limit (saved before V1) would fail closed at sale | review | preflight `quota.plans` WARN (`9a869280`) |
| 10 | My own lab tests: an invented 1 % cap tolerance (the NAS overshoots 0.02 – 10 % of a small share) and renewal tests that passed only by timing | CHR runs | replaced by "limit + 1 s of measured line rate" and explicit session ageing (`37c1aead`, `3bf52486`); the docs no longer say "exact cap" |

Assertion / guard changes (none relaxed to turn a result green; each follows the owner decision or a measurement, with the old and new values):

| File | Old → new | Reason |
|---|---|---|
| `tests/Unit/Wp9/U0SourceGuardTest.php` | `0eea411a` → `ea181c54` | runtime lock inventory gains `nuxr_quota_`; new assertions that `quota.php` holds only its own lock and nothing nests under it |
| `tests/Unit/Wp4/RadiusPolicyBuilderTest.php` | `7648aaa8` → `70e38317` | "quotas are Hotspot-only" → V1 contract (PPPoE time quota); `Nuxr-Period-Start` = start − 10 s (positive controls unchanged: unknown start stays `0`) |
| `tests/Integration/Wp9/W9QuotaGateCharacterisationTest.php` | removed | characterised the pre-decision semantics; superseded by `V1QuotaFreeRadiusTest` |
| `Wp5a/ActivationFailureTest`, `Wp8/FailureDrillsTest` | `8dcafefd` → `052b226d`, `cbb8a6c9` → `0d4e5338` | the annotation keeps the `disconnect` list and **adds** per-session `sessions` and `confirmed`; unconfirmed stays unconfirmed |
| `Wp8/RealLifecycleTest` | `871ff3c5` → `89f67ab1` | "RENEW does not disconnect" → the Option B contract |

---

## 6. Full MariaDB / MySQL results

| Run | Tree | MariaDB 10.11.19 | MySQL 8.0.46 |
|---|---|---|---|
| Final full harness | `32cdc92d` | **1521 tests, 76375 assertions, 0 failures, 0 errors**, 112 incomplete | **1521 tests, 76381 assertions, 0 failures, 0 errors**, 112 incomplete |
| (log) | | `evidence/quota/final/full-run-32cdc92d-20261017T154707Z.log` | run-tests teardown clean (0 containers left) |
| Earlier full harness (superseded) | `37c1aead` | 1518 tests, 76354 assertions, 0 failures, 111 incomplete | 1518 tests, 76356 assertions, 0 failures, 111 incomplete |

- **Incomplete:** the real-NAS lab classes (104 before this round + the new V1 lab classes). They mark themselves incomplete without the lab and were **executed for real** on the CHR (§7). Incomplete is not counted as passed.
- Assertion difference between engines: the known race-winner variance (R-FC-8 / 9).
- Variant registry reproducibility: fingerprints identical on both engines and identical to the previous round (V1 `3ca25964…`, V2 `6c2f04ea…`, V3 `e1a66aa3…`): no schema change.
- Focused runs (both engines, in commit bodies and `evidence/quota/…`): `V1QuotaPolicyTest` 5, `V1QuotaFreeRadiusTest` 7 (real FreeRADIUS), `V1QuotaEnforcerTest` 5, `V1QuotaOperationsTest` 3 (CLI, audit, heartbeat, lock, health, preflight), `V1RenewalOptionBTest` 3, `V1LegacyRenewalTest`, `V1PlanQuotaFormTest` 2, `V1VoucherQuotaTest` 2, `RenameOldSessionTest` 22 / 357, plus the Unit, Wp4, Wp5a/b, Wp6a/b/c, Wp7, Wp9, Wp10 and Http suites.

---

## 7. Real CHR results (RouterOS 7.24.5 ×2, shipped A2-R-hardened FreeRADIUS site, production-contract sequence)

| Suite (final tree) | Result |
|---|---|
| `wp8-security-gates` (A2 concurrent first login, username equivalence PPPoE + Hotspot, shared users) | **10 tests / 216 assertions, exit 0** |
| `wp8-integration` | **109 tests / 2055 assertions, 0 failures**, 13 skipped (the stock-checkrad / SNMP-mode gates that need another lab mode than the production contract; identical in every round; not counted as passed) |
| `V1QuotaLabTest` (new) | 8 tests, all pass (§8) |
| `RenameOldSessionLabTest` (A3) | 6 / 141 pass |

`Simultaneous-Use`, Option G, A2-R and the RADIUS identity guard are unaffected: the security gates (A2 concurrent first login, username equivalence PPPoE + Hotspot, shared users) are the same 10 / 216 as every previous round, and the Option G and Port-Limit gate classes pass inside `wp8-integration` (96 pass + 13 skipped = 109). The lab was torn down afterwards (0 VMs, 0 namespaces, 0 links). `a2r_sql_gate.py`, `a2r_gate.py pool` and `fault` were never run.

---

## 8. In-session quota enforcement evidence (CHR, final tree; `evidence/quota/chr-final/v1-chr-evidence-final.jsonl`)

| Case | Quota / share | Ended at | Overshoot | Mechanism |
|---|---|---|---|---|
| Hotspot, 2 shared users, session 1 | share 2 000 000 | 2 001 516 | +1 516 B (0.08 %) | NAS, hard cap |
| Hotspot, session 2 (share of what is left) | share 999 242 | 1 098 932 | +99 690 B (≈ 0.6 s of line rate) | NAS |
| Hotspot, both sessions | quota 4 000 000 | 3 100 448 total | none (≤ quota) | shares |
| Hotspot, single user, exhausted | 3 000 000 | 3 000 732 | +732 B; **next login Access-Reject** | NAS + counter |
| PPPoE download only | 3 000 000 per direction | 3 116 560 | +116 560 B (3.9 %); **next login Access-Reject** | NAS per direction |
| **PPPoE upload + download** | quota 9 000 000 | 12 128 056 | **+3 128 056 B**; first interim over quota at 60.1 s, enforcer Disconnect-ACK + Stop **CONFIRMED** at 60.7 s | **accounting enforcer** |
| Time plan 60 s | 60 s | `Session-Timeout` 60, session time 61 s; **next login Access-Reject** | — | NAS |
| RENEW during a live PPPoE session | old session used 1 615 552 B | ACK + Stop **CONFIRMED**; new session limit 10 000 000 (old usage not mixed); 1 debit | — | Option B |
| CHANGE_PLAN (10 → 20 MB) | — | CONFIRMED; next session limit 20 000 000; 2 debits for 2 paid actions | — | Option B |
| RENEW during a live Hotspot session | old used 1 542 000 B | CONFIRMED, gone from the NAS; new limit 6 000 000; 1 debit | — | Option B |
| FreeRADIUS frozen mid-session | 3 000 000 | cut by the NAS at 3 005 536; Stop recorded after unfreeze | — | NAS limit holds |
| NAS link down during enforcement | limit 1 000 (synthetic) | enforcer result **`UNKNOWN:COA_NO_RESPONSE`, not confirmed**, session still up (nothing fabricated); after restore **CONFIRMED** | — | enforcer |

**Computed and tested bounds (not an exact hard cap):**
- Hotspot and PPPoE per-direction: share + the NAS's own cut delay; asserted ≤ share + 1 s of the measured line rate; observed ≤ 0.9 s.
- PPPoE upload + download: overshoot ≤ `min(R, rate_total × (60 s interim + 15 s pass + 6 s Stop wait))`, `R` = the quota remaining at login. The measured case (200 KB/s, crossing at ≈ 45 s, enforcer at 60.7 s) gives 3.13 MB against a computed 16 MB / cap 9 MB. With the enforcer down the per-direction caps still hold: worst case `2R` in total.
- Examples (computed): a 100 Mbps plan (12.5 MB/s each way) has a bound of ≈ 2 GB: 4 % of a 50 GB quota but up to the full cap (1× the quota) of a 1 GB quota.

---

## 9. Accounting and renewal correctness

- **Attribution:** by session start with the 10 s allowance; verified on the real FreeRADIUS (session stamped 2 s before the boundary counted, session from an hour earlier not), on the full chain (activation → boundary → enforcer) and on the CHR.
- **Usage never decreases:** a stale Interim-Update (after the Stop, or with smaller counters) is acknowledged but not written; duplicate Start / Stop produce one row; 64-bit counters (Gigawords) and a wrapped 32-bit counter are summed correctly; usage of several NAS is summed.
- **No old / new mixing:** after RENEW and CHANGE_PLAN the new session's limit is the full new-period share (CHR).
- **Confirmation:** CONFIRMED needs ACK + Stop or NAK 503; ACK without Stop, NAK 406, silence, unknown NAS and a missing NAS address are each reported as observed, never as ended.
- **Idempotency:** the enforcer re-sends a Disconnect to a session at most every 60 s; one enforcer at a time (database lock); repeating the operator `session-end` is harmless; an unconfirmed end is visible in the drift report and the health check until the Stop arrives.
- **History:** `radacct` is only read by the enforcer, the termination and the confirmation; every test asserts the rows are unchanged.

---

## 10. Financial integrity and concurrency

- No change to the ledger, wallets, pricing, settlement or schema (the diff touches none of them).
- A session-end outcome never changes or repeats a settlement, recharge or grant: RENEW settles exactly once (CHR: 1 debit per paid action; harness: `V1RenewalOptionBTest` ledger delta equals the NEW activation's); `SettlementReadModel::integrity()` empty after every case.
- Full harness (both engines) includes the finance integrity, concurrent renewal (`W9CronRenewalRaceTest`, `ActivationReversalRaceTest`, `U0RaceTest`) and voucher lifecycle suites: 0 failures.
- Bounded load, both engines: distributed 96 / 96 and one wallet 96 / 96 completed; one customer 8 completed + 40 `ACTIVATION_IN_PROGRESS` refusals (no double settlement); peak 15 of 151 connections.

---

## 11. Failure / recovery scenarios

| Scenario | Result |
|---|---|
| CoA timeout / NAS silent | `UNKNOWN COA_NO_RESPONSE` after 1 + 2 identical sends, never a new request; not confirmed; drift + health until the Stop arrives |
| Disconnect-NAK (406) / NAK 503 | 406 unconfirmed; 503 confirmed ("no such session") |
| Accounting delay / lost interim | stale interim not written; a missing interim delays the usage only; the native limit still holds |
| Lost Stop | the row stays open; the enforcer re-sends every 60 s, the NAS answers 406, the health WARNING stays; runbook 4a / 4b (the row is never edited) |
| FreeRADIUS down / frozen | CHR: the NAS limit of the running session holds; new logins fail closed |
| NAS link down | CHR: enforcer UNKNOWN, nothing fabricated, CONFIRMED after the path returns |
| Enforcer not running | health `quota_enforcer` CRITICAL while quota subscribers are online; native limits still apply |
| Malformed / duplicate quota rows | login rejected "Quota unavailable" |
| Invalid plan quota at sale | voucher / activation held for the operator (`CLAIMED_REVIEW`), nothing written to RADIUS |
| Renewal while the old session is uncertain | renewal completes, one settlement; the session is reported `SESSION_POLICY_STALE` and ended by the operator command |

---

## 12. Backup / restore and rollback

WP10 drill on both engines against the final tree (1 test / 128 assertions each, exit 0): dump, restore, CHECK constraints survive, migration rollback. No schema change in this round, so the migration path is unchanged. Rollback of this feature = redeploy the previous commit and the previous `install/freeradius` files: there is no data migration, and the rows V1 writes are ordinary RADIUS attributes (`Max-Data`, `Max-All-Session`, `Nuxr-Period-Start`, the standard `Acct-Interim-Interval`). A separate rollback rehearsal of the application code was **not** run; this rests on the absence of a schema change and on the drill covering the database restore.

---

## 13. Security regression

- CHR security gates 10 / 216 (§7); the Wp9 red-team suites, the stored-XSS crawl (includes the new PPPoE fields), CSRF / session / secret-redaction / autoload-exposure suites are inside the full harness: 0 failures.
- New CLI `quota.php` is CLI-only (404 through the web server, tested); the new classes refuse direct execution; runtime lock inventory guard updated (§5).
- UI audit, 136 page loads (desktop English, phone and tablet Arabic RTL, six staff roles + customer login): no overflow, axe wcag2a/aa serious + critical 0, correct `dir`; the new `services/add` and `services/pppoe-add` forms are clean in all three layouts. Flagged rows are the documented set (expected 403 denials; customer login contrast / RTL = D9). PWA suite T00 – T24: **32 / 32 PASS**.
- Secret sweep before every commit (7 known secret values + key markers): 0 hits; no image, capture or customer data committed; no secret on a command line or in evidence.

---

## 14. Production readiness checklist

| Item | State |
|---|---|
| V1 GB + time + speed + composite plans, in-session enforcement, Option B | **DONE and verified** (dev, both engines, real CHR) |
| Regression: full dual-engine harness, CHR gates, finance, voucher, security, PWA / UI, drill, load, source guards, manifest, secret sweep, lint | **DONE** (§6 – §13) |
| Operator docs: README "V1 data and time quota", checklist I6 (cron), H7, H8, E8, runbook 4a / 4b | **DONE** |
| Cron `quota.php enforce --loop=55` + health `quota_enforcer` on the target | **TARGET** |
| NTP on every real NAS (offset < 5 s) | **TARGET** |
| Real NAS (not CHR) acceptance of `Recv/Xmit-Limit`, `Acct-Interim-Interval`, Disconnect (RouterOS versions / models other than 7.24.5 are unverified) | **TARGET** |
| Deployment on the target (TLS, least-privilege accounts, FreeRADIUS SQL pool sizing), capacity on target hardware, ISO-01 across two real instances, OBS-1 / P-A4-3 surveys on real data | **TARGET** (not testable here) |
| Legal review (LIC-1) | **COUNSEL** (not claimed) |

---

## 15. Remaining non-delegable decisions (one request)

| # | Decision | Recommendation |
|---|---|---|
| **N1** | Accept the PPPoE upload + download overshoot bound (§8) and the 60 s interim. Options: (a) accept as is; (b) 30 s / 15 s interim (bound 51 s / 36 s, 2× / 4× accounting load); (c) split the direction limits (R/2 each) for a hard total, which would cap a download-only subscriber at half the quota: **not recommended**; (d) GB plans on Hotspot only in V1 | (a), revisit with real traffic profiles; (b) is a one-line constant |
| **N2** | Accept the 10 s NAS-clock attribution allowance and make NTP on every NAS a go-live condition (checklist H8) | accept |
| N3 (cosmetic) | An exhausted data quota is refused with FreeRADIUS's built-in text "Your maximum never usage time has been reached" (the stock counter message cannot be changed here) | no action unless wanted |
| D1 | WP9 acceptance record | owner |
| D2 | S-1 A2-R reclassification (remediated; reproduction never run) | owner |
| D4 | AV-1 FreeRADIUS stall → orphan PENDING claim (documented strict-mode limitation, D-AV1) | owner |
| D5 | Time contract C-05 (canonical text; N2 adds the NAS clock) | owner |
| D6 | AD-18 secret boundary (`nas.secret` stays recoverable) | owner |
| D7 | OBS-1 resolution method for real duplicate identities | owner + an operator run on an approved copy |
| D8 | R-FC-1 voucher codes in financial fields (the P2 part is closed by Option B) | owner |
| D9 | R-JQ-1 jQuery upgrade; customer-portal RTL / contrast | owner scope |
| D10 | LIC-1 legal review | counsel |

---

## 16. Exact final release status

# **`READY_FOR_PRODUCTION_OWNER_APPROVAL`**

Meaning, precisely:
- **Inside the authorized scope** (development, disposable databases, the CHR lab, synthetic data) every gate of the owner's list passes and no technical blocker remains: GB / time / speed / composite plans, in-session enforcement with computed and tested bounds, Option B with confirmed termination, fail-closed behaviour, no double settlement, no history deleted, the lock gates Q1 – Q4 evidenced.
- This is **not** a "Production Ready" declaration. The target-environment tests (§14), the owner decisions N1, N2, D1, D2, D4 – D10 (§15) and the legal review remain. Until they are done the product must not be deployed to production.
- What would change this status: a failing gate on the final tree, or an owner rejection of N1 (a tighter PPPoE total cap would need a design change, not a configuration).
