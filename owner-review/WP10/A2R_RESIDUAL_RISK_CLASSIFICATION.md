# A2-R (PPPoE admission under SQL exhaustion) - residual-risk classification and production conditions (D2)

Owner decision 2026-10-18: the defensive treatment was implemented and tested inside the allowed scope; the SQL-exhaustion reproduction stays
**forbidden** and the effect stays **not verified experimentally**; the risk is **not closed absolutely** and no unproven production risk is
accepted on the owner's behalf. This document classifies what remains, lists the defensive evidence and states the conditions that close the
production gate **without** the forbidden test. It is a classification for the owner to adopt or amend; it changes no register row.

`a2r_sql_gate.py`, `a2r_gate.py pool` and `a2r_gate.py fault` were never run, simulated or retried in any round (including this one).

## 1. The risk, as established (no exploitability claim in either direction)

| Fact | Source |
|---|---|
| In the H-2B lab (FreeRADIUS 3.2.8) a `%{sql:}` statement ran with its inner expansions **emptied** while rlm_sql logged "No connections available and at max connection limit" | `owner-review/WP8/evidence/h2b-impl/`, `owner-review/WP9/PLAN.md` section 6.2 |
| Hypothesised failure paths F1 (claim keyed on an emptied name), F2 (ACTIVE takeover with the verification skipped), F3 (stored empty token / identity) | PLAN section 6.3 - **hypotheses, never reproduced** |
| No path was found where an Access-Accept is sent without a claim; every Accept passes `claim=1` | PLAN section 6.3 |
| Unknown: whether FreeRADIUS 3.2.8 empties the A2-R expansions in practice; whether `pool.max >= max_servers` prevents it; frequency; whether an outside party can induce it | PLAN section 6.4 - **not studied by experiment and will not be** |

## 2. Classification

| Class | Residual item | Severity | Likelihood | Status |
|---|---|---|---|---|
| **R-A2R-1** | An emptied expansion produces a *silent* wrong admission (F1 / F2) | High (admission bypass / duplicate session) | Unknown, requires SQL pool starvation | **Mitigated by design, effect unproven.** Layers L1 - L4 turn silent corruption into a refusal (below). Not closed |
| **R-A2R-2** | Starvation costs **availability**: refusals and an orphan `PENDING` claim (the AV-1 class) | Medium (subscribers cannot dial until released) | Plausible under overload | **Accepted-by-design with an audited manual recovery** (D4); no automatic release; monitored |
| **R-A2R-3** | A **different** statement path not covered by the layers is affected the same way (the mechanism is per-expansion on a pool connection) | Medium | Unknown | **Open, unverifiable here.** Reduced by pool sizing and the load limits; closed only by the production conditions below |
| **R-A2R-4** | The defensive layers depend on engine behaviour: CHECK enforcement (`NOT ENFORCED`, `check_constraint_checks`), strict `sql_mode` | Medium | Low (a deployment mistake) | **Deployment gate**: preflight FAILs when a CHECK is missing or not enforced |
| **R-A2R-5** | README promises more than is proven (N-17) | Low | - | **Closed**: the claim "sizing removes exhaustion / never Accept" was removed; "necessary, not sufficient" is stated |
| **R-A2R-6** | The CHR / harness behaviour differs from the production database host (CPU, memory, `max_connections`, thread pool) | Medium | Certain to differ | **Open, target-only** (TA-09) |

## 3. Defensive evidence (all inside the allowed scope; all dual-engine unless noted)

| Layer | What it does | Evidence |
|---|---|---|
| L1 schema CHECKs | an emptied token / name / claim field is refused by the database (`ck_nuxr_admission_token`, `ck_nuxr_admission_claim`) | `W9AdmissionHardeningTest` 11 tests (pre-fix: 9 failures + 1 error); `W9CheckConstraintIntegrityTest` 10, `W9CheckExpressionTest` 13 |
| L2 echo validation | S3a / S3b read from one parameter row and echo it; Unlang compares; mismatch -> `Access-Reject` (`reject:echo`) | `AdmissionSourceTest`, `SessionGuardSourceTest` (source pins), CHR security gates |
| L3 SAFE takeover | an ACTIVE takeover is allowed only if the same request's guard returned ok + SAFE | same |
| L4 readiness | `nuxr_admission_check.sql` + preflight `radius_checks`: both constraints exist **and are enforced**, otherwise NOT_READY | `W9AdmissionHardeningTest`, preflight; TA-01 |
| L5 docs | pool sizing is "necessary, not sufficient"; empty-expansion paragraph; upgrade steps | `install/freeradius/README.md` |
| Real-NAS behaviour of the guards | concurrent first login (A2), username equivalence PPPoE + Hotspot, shared users, Option G, Port-Limit | CHR security gates **10 tests / 216 assertions** on the candidate; `wp8-integration` 121 / 2323 |
| Recovery | `reconcile.php admission-pending` / `admission-release` (operator, reason, NAS-side evidence, audited, age and open-session checks); health `admission_pending` | `W9RecoveryToolsTest`; `V1OwnerDecisionGuardsTest` (no automatic release) |
| Capacity discipline | bounded paced loads only; pool never exhausted by any tool here | `StagingLoadTest`, `AcctLoadTest` (in-flight <= 8 against a pool of 32) |

## 4. What the lab cannot show (the honest gap)

- It is **not shown** that the layers hold under real pool starvation, because creating starvation is the forbidden test. The layers are
  verified for the *consequence* they target (an emptied value is refused / not trusted) by forcing the emptied value directly, not by
  causing the starvation that might produce it.
- Hence the status is *mitigated, effect unverified*; it is never to be called *closed*.

## 5. Production conditions that close the gate without the forbidden test

All must hold on the **approved target**, each with evidence in `docs/V1_TARGET_ACCEPTANCE.md`:

| # | Condition | How it is evidenced |
|---|---|---|
| C1 | Preflight exits 0: CHECK constraints present **and enforced** (both `nuxr_admission` constraints), strict `sql_mode`, REPEATABLE READ, UTC session | TA-01 output |
| C2 | FreeRADIUS runs **threaded** under the service manager (never `-X`), `pool.max >= max_servers` and `max_connections >= sum(pool.max) + application`, sized with margin for the planned peak (incl. the accounting rate of N1: `owner-review/WP10/CAPACITY_AND_ACCOUNTING_LOAD.md`) | TA-09 sizing record |
| C3 | A bounded, paced capacity test on the target (never to the limit) shows headroom: pool in use stays below 50 % at the planned peak, no `No connections available` in the FreeRADIUS log | TA-09 (`dev/wp10/load.sh`, `dev/wp10/acct_load.sh` shape) |
| C4 | Alerting on: FreeRADIUS log pattern `No connections available`, `admission_pending` health WARNING / CRITICAL, `quota_enforcer`, database `Threads_connected` vs `max_connections` | TA-07 failure-injection record (alert fired and cleared) |
| C5 | Operators trained on `OPERATOR_RECOVERY_RUNBOOK.md` section 3; no automatic release exists (guard test) | runbook sign-off |
| C6 | Rollout order and the stage-wise release of PPPoE admission as in the H-2 runbook; the first stage on a NAS with a small subscriber group | deployment record |
| C7 | The owner records, in the register, a classification of **R-A2R-1 / R-A2R-3**: either "accepted for launch with C1 - C6" or "launch blocked until a staged pilot shows no starvation event over N days" | the owner's decision (not made here) |

A pilot with real traffic (C7, second form) is a production activity and needs its own authorization; this package does not authorize it.

## 6. Recommendation (non-binding)

Adopt **mitigated - effect unverified** as the permanent classification; accept R-A2R-2 as an availability cost with the audited manual
recovery; make C1 - C6 go-live gates; decide C7 with the capacity numbers of the target in hand.
