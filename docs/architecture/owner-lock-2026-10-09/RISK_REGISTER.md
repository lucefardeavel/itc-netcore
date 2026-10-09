# RISK_REGISTER

## Status

Revision: **First-Principles Review 2 — 2026-10-07**.

Risks are classified as **PROVEN/CURRENT** or **CONDITIONAL**. SAS4 comparison alone is not evidence of risk.

## Scale

- **CRITICAL:** can break access/security/financial isolation or make release unsafe.
- **HIGH:** serious outage/security/correctness impact.
- **MEDIUM:** bounded operational/security debt.
- **LOW:** optimization/documentation concern.

## Current / Evidence-Backed Risks

| ID | Risk | Severity | Likelihood | Evidence | Required response |
|---|---|---:|---:|---|---|
| R-000 | Reference-driven overengineering or copying a wrong SAS4 design | HIGH | MEDIUM | owner review correction | AD-00: every adoption requires independent requirement/evidence |
| R-001 | WP8 session-safety solution not yet permanent config | CRITICAL | HIGH | Option G under `dev/wp8/`; WP8 PENDING | owner-lock, productionize, rerun real-NAS gates, continue S7/S8 |
| R-002 | Lost Accounting-Stop causes indefinite PPPoE lockout | HIGH | MEDIUM | S6 reproduction | Session Authority / fail-closed live verification |
| R-003 | Stock checkrad can fail open | CRITICAL | MEDIUM | Option F Gate D | never use as admission safety authority |
| R-004 | PPPoE Port-Limit cannot be assumed authoritative | HIGH | HIGH if assumed | Option E real gate | capability-specific policy |
| R-005 | Fail-closed verifier outage can deny legitimate reconnects | HIGH | MEDIUM | intentional Option G behavior | health/latency metrics, bounded timeout, redundancy where justified; never silently fail open |
| R-006 | RouterOS RADIUS timeout can be too short for verifier path | HIGH | MEDIUM | WP8 timing evidence | binding supported config + deployment check |
| R-007 | Concurrent first-login race may admit two sessions before Accounting-Start | HIGH | UNKNOWN | protocol/accounting ordering concern; not yet gated | explicit concurrent real-NAS gate before production-safe claim |
| R-008 | Old username session survives rename | HIGH | MEDIUM | `R-WP8-CARRY-02` | exact old-session handling during reprovision |
| R-009 | Stale-session timezone mismatch | HIGH | MEDIUM | `R-WP8-CARRY-03` | canonical tested time contract |
| R-010 | Legacy direct deactivation/removal bypasses reversal | HIGH | MEDIUM | `R-WP8-CARRY-01` | authoritative path closure |
| R-011 | Legacy metadata authorization exposure | MEDIUM | MEDIUM | `R-HF-CARRY-01` | WP9/release closure |
| R-012 | RADIUS/NAS verifier secrets have broad/plaintext exposure risk | HIGH | HIGH | current `nas.secret`; future SNMP credential | least-privilege secret boundary, redaction, rotation plan |
| R-013 | RouterOS management API 8728 is plaintext if used | MEDIUM | MEDIUM | current private-only path | keep restricted; prefer encrypted management transport for production use |
| R-014 | Mutable RADIUS identity can desynchronize from live sessions | HIGH | MEDIUM | rename defect | bind exact session identity to existing customer id |
| R-015 | Plugin/direct legacy code may bypass new authority | HIGH | MEDIUM | accepted WP5B/WP9 residual | source/runtime red-team and default-deny writer boundaries |
| R-016 | RouterOS ACK can be misread as semantic application | HIGH | MEDIUM | pool/IP ACK-but-ignore evidence | capability rules + postcondition/re-auth strategy |
| R-017 | `radacct` and `rad_acct` can be confused | MEDIUM | MEDIUM | two stores | explicit naming/docs/metrics; never treat `rad_acct` as accounting truth |
| R-018 | Historical open accounting row can be confused with current online truth | MEDIUM | HIGH | lost-Stop behavior | separate current Session Authority read model |
| R-019 | UNKNOWN may be collapsed into SUCCESS/FAILED by future code | CRITICAL | MEDIUM | cross-domain integration risk | preserve tri-state contracts/tests |
| R-020 | `radacct` growth can create operational pressure over time | MEDIUM | MEDIUM/HIGH | retained accounting history | measure growth; archive/partition only when needed, never as quota reset |
| R-021 | Insufficient runtime telemetry hides fail-closed dependency failures | HIGH | HIGH | current observability scope | minimum AAA/session/NAS metrics before production rollout |

## Conditional Risks — Activate Only If Feature Is Approved

| ID | Conditional feature | Potential risk | Required gate before activation |
|---|---|---|---|
| C-R01 | Advanced FUP | misbilling / stale policy under accounting loss | product rules, usage authority, accounting-freshness tests |
| C-R02 | Free/excluded traffic | classifier can under/over-bill | auditable/versioned rules and test corpus |
| C-R03 | Durable outbox/worker | duplicate network actions / retry storms | idempotency, command state machine, crash/replay tests |
| C-R04 | External broker | operational complexity / split-brain work ownership | measured volume/isolation need |
| C-R05 | Redis/session cache | cache drift treated as truth | derived-only contract, rebuild/TTL tests |
| C-R06 | Future shared-DB multi-tenancy (NOT SELECTED) | cross-tenant data/NAS/finance leakage | explicit tenant design + negative isolation matrix |
| C-R07 | New subscriber UUID | migration complexity with no benefit | prove current customer id insufficient first |
| C-R08 | DB HA/read replicas | failover/consistency complexity | availability SLO and deployment plan |
| C-R09 | Fine-grained tree locks | correctness regression | measured global-lock contention + concurrency proof |
| C-R10 | Full service decomposition | distributed consistency failure | measured need and bounded extraction scope |

## Release-Critical Cluster

Before production acceptance, close or explicitly owner-risk-accept:

- R-001 through R-012 as applicable to the selected WP8 solution;
- R-014 through R-016;
- R-019;
- R-021;
- current project carry-forwards.

R-007 specifically requires an evidence gate even though the defect is not yet proven.

## Closure Rule

Documentation does not close a risk. Closure requires code/config where applicable, tests, integration evidence for NAS behavior, and owner acceptance.


## Owner-Locked Product/Release Gate Risks — 2026-10-09

| ID | Risk | Evidence status | Required closure |
|---|---|---|---|
| O-R01 | Continued session across quota period boundary may escape period usage calculation | Existing period-start SQL behavior identified; full real-NAS quota gate pending | Q1 and Q4 evidence; correct only the proven accounting logic |
| O-R02 | Subscriber can overshoot data quota while connected or enforcement may be delayed | Current tests show new authentication rejection, not universal mid-session enforcement | Q2 real behavior on supported services; policy decision if strict cutoff needed |
| O-R03 | Missing Interim/Stop creates unreliable quota freshness | WP8 missing accounting evidence | Q3 failure/degraded semantics without mass disconnect |
| O-R04 | CHANGE_PLAN Disconnect UNKNOWN leaves old session policy running | WP8 S6 Drill 4 observed | P1 behavior/evidence + targeted mitigation |
| O-R05 | RENEW keeps previous Session-Timeout until re-auth | WP8 S5 observed | P2 product behavior and test |
| O-R06 | Bandwidth live edit between accepted preview and provisioning changes terms | Accepted WP6A R-15 | P3 freeze/bind/verify release gate |
| O-R07 | Independent ISP instance shares credentials/DB/NAS/backup accidentally on common host | Selected deployment model, not a reproduced breach | ISO-01 isolation and restore validation |
| O-R08 | No verified disaster restore/RTO/RPO or measured capacity | WP10 work not yet owner accepted; load results not established | REL-01–REL-05; owner-set per-instance RTO/RPO |

**Status note:** architecture approval does not downgrade existing WP8/WP9 blockers. No automatic SLA or HA-cluster claim is authorized.
