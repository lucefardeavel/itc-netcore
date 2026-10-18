# A2-R owner risk-decision evidence (D2)

For the owner's decision on the classification in `A2R_RESIDUAL_RISK_CLASSIFICATION.md`. **Nothing here runs or approximates the forbidden SQL-exhaustion
reproduction** (`a2r_sql_gate.py`, `a2r_gate.py pool|fault` were never run in any round); this file only collects what exists and states what it does not show.
Generated from the final-candidate logs by `dev/wp10/a2r_evidence.py` (reads logs, runs nothing).

## 1. The decision being asked

Adopt the classification **"mitigated - effect unverified"** for A2-R (PPPoE admission under SQL exhaustion), with the production conditions C1 - C6 as go-live
gates and a decision on C7. The owner has already stated: the defensive treatment is implemented and tested in the allowed scope; the effect is not verified
experimentally; the risk is not closed absolutely; no unproven production risk is accepted on the owner's behalf.

| Option | Meaning | Consequence |
|---|---|---|
| **A. Adopt, launch with C1 - C6** | the residual (R-A2R-1 / R-A2R-3) is accepted for launch under the gates; capacity headroom and alerting are the control | smallest delay; the owner owns the residual risk |
| **B. Adopt, launch only after a staged pilot (C7, second form)** | a pilot with real traffic on a small subscriber group shows no starvation event over an owner-chosen period | needs its own production-adjacent authorization; longest path |
| **C. Do not adopt** | A2-R stays an open P0 | release stays blocked; a different mitigation or the forbidden test would be needed (neither is authorized) |

The engineering recommendation is **A with C1 - C6 verified on the target** (see the classification, section 6); this is a recommendation, not a decision.

## 2. Defensive evidence on the final candidate (both engines)

| Layer | Defence | Test class | MariaDB | MySQL |
|---|---|---|---|---|
| L1 | schema CHECKs: an emptied claim / token / name is refused by the database | W9AdmissionHardeningTest | 11 pass | 11 pass |
| L1 | schema CHECKs: an emptied claim / token / name is refused by the database | W9CheckConstraintIntegrityTest | 10 pass | 10 pass |
| L1 | schema CHECKs: an emptied claim / token / name is refused by the database | W9CheckExpressionTest | 13 pass | 13 pass |
| L2/L3 | echo validation and SAFE-verified takeover (source pins of the FreeRADIUS site) | AdmissionSourceTest | 5 pass | 5 pass |
| L2/L3 | echo validation and SAFE-verified takeover (source pins of the FreeRADIUS site) | SessionGuardSourceTest | 5 pass | 5 pass |
| L4 | readiness: CHECKs exist and are enforced (preflight), least-privilege grants | W9PreflightGrantsTest | 1 pass | 1 pass |
| REC | operator recovery of an orphan PENDING claim: audited, manual, never automatic | W9RecoveryToolsTest | 3 pass | 3 pass |
| REC | operator recovery of an orphan PENDING claim: audited, manual, never automatic | W9ReconcileCliGuardTest | 2 pass | 2 pass |
| REC | operator recovery of an orphan PENDING claim: audited, manual, never automatic | V1OwnerDecisionGuardsTest | 3 pass | 3 pass |
| ID | identity guards the admission relies on | U0IdentityTest | 86 pass | 86 pass |
| ID | identity guards the admission relies on | U0RaceTest | 23 pass | 23 pass |
| ID | identity guards the admission relies on | U0OwnershipGuardTest | 11 pass | 11 pass |
| HEALTH | monitoring and alerting | W10HealthCheckTest | 2 pass | 2 pass |
| CHR | real NAS: concurrent first login, username equivalence PPPoE + Hotspot, shared users | wp8-security-gates | OK 10 tests / 216 assertions | (CHR) |

Also on the final candidate: the CHR (RouterOS 7.24.5) security gates cover the same admission path on a real NAS (row `CHR` above); Option G, Port-Limit and
equivalence gates run inside `wp8-integration` (122 tests, 2383 assertions, 13 mode skips).

## 3. What this evidence does **not** show

- It does not show that the layers hold under **real pool starvation**: starvation is exactly the forbidden test. The layers are verified for the *consequence*
  they target (an emptied value is refused / not trusted) by forcing the emptied value directly.
- It does not show how often starvation occurs on the target, or whether an outside party can induce it.
- Capacity numbers are lab numbers: `CAPACITY_AND_ACCOUNTING_LOAD.md` (accounting path only, in-flight 8 against a pool of 32; no admission SQL was loaded).

## 4. Production conditions and the evidence each needs on the target

| # | Condition | Evidence to attach (TA row) | State |
|---|---|---|---|
| C1 | preflight exit 0 incl. CHECKs present **and enforced**, strict `sql_mode`, REPEATABLE READ, UTC session | `preflight.php` output (TA-01) | NOT_RUN |
| C2 | FreeRADIUS threaded, `pool.max >= max_servers`, `max_connections >= sum(pool.max) + application`, margin for the planned peak | configuration extract + sizing record (TA-09) | NOT_RUN |
| C3 | bounded paced capacity test: pool in use < 50 % at the planned peak; no `No connections available` in the FreeRADIUS log | `acct_load.sh` / `load.sh` evidence (TA-09) | NOT_RUN |
| C4 | alerts on the log pattern, `admission_pending`, `quota_enforcer`, `activations_stale`, `Threads_connected` vs `max_connections`; fired and cleared once | failure-injection record (TA-07) | NOT_RUN |
| C5 | operators trained on runbook section 3; no automatic release (guard test) | runbook sign-off; `V1OwnerDecisionGuardsTest` (green) | test green, sign-off open |
| C6 | staged rollout order (H-2 runbook), first stage on a small group | deployment record | NOT_RUN |
| C7 | the owner records the classification of R-A2R-1 / R-A2R-3 and chooses option A or B above | the owner's record | **owner** |

## 5. Owner decision record (to be completed by the owner)

| Field | Entry |
|---|---|
| Decision | A / B / C |
| C7 form (if B) | pilot group, duration, success criterion |
| Date / approver | |
| Register row to update | (owner-controlled; not edited by engineering) |
