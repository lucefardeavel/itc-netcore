# OWNER_ARCHITECTURE_LOCK — SaaS RADIUS / PHPNuxBill

**Status:** OWNER-APPROVED ARCHITECTURE DIRECTION — 2026-10-09  
**Product source of evidence:** `lucefardeavel/phpnuxbill-reseller@47a3a02cb0d469d324393515186f2d1497641b31` (WP8 laboratory branch); accepted feature branch independently remains `feat/reseller-system-v1@5bdc57459a64dd53e32e5abb2ef6efa3815e387a`.  
**External clean-room reference (NON-AUTHORITATIVE):** `lucefardeavel/sas4-reference-intelligence@76a4f7ae83f5fc50eaf0c7c9c440c35450cdba65`.  
**Scope:** architecture baseline lock and release prerequisites. **No code push, merge, deployment or production authorization.**

## 1. Binding principle

Reference observation ≠ correctness proof ≠ owner requirement ≠ implementation approval. Independent current-code evidence, contracts, security boundaries, failure/concurrency analysis and NAS lab tests take precedence. No SAS4 proprietary code, binary, license circumvention, or vendor deployment dependencies.

## 2. Ten owner-approved axes

| # | Reviewed axis | Owner-approved direction | Implementation status |
|---|---|---|---|
| 1 | Sessions / Session Authority | REDESIGN logical contract, Option G behavior | WP8 lab PASS; production integration PENDING |
| 2 | Stale Sessions | REDESIGN: stale ≠ offline; require verified liveness | PENDING production closure |
| 3 | Simultaneous-Use | REDESIGN; protocol-aware; test simultaneous first login | PENDING real concurrency gate |
| 4 | Failure Semantics | Consequence-based UNKNOWN, never unsafe grant | Approved policy; verify by workflow |
| 5 | CoA / Disconnect | KEEP existing RFC5176 client; semantic capability gates | Implemented; carry-forwards remain |
| 6 | FUP / Quota | KEEP current scope, mandatory correctness gates Q1–Q4 | Gates PENDING; advanced FUP NOT APPROVED |
| 7 | Profiles / Plan Transitions | KEEP NEW/RENEW/CHANGE_PLAN/REACTIVATE | 3 lifecycle correctness gates PENDING |
| 8 | NAS / MikroTik | KEEP integration + minimal capability + secret hardening | NAS gates N1–N7 PENDING as a set |
| 9 | ISP Isolation | **Option A: separate isolated instance per ISP** | Design APPROVED, deployment verification PENDING |
| 10 | Reliability / Scale / HA | KEEP modular monolith; required restore, recovery, monitoring, load tests; HA conditional | REL-01–REL-05 PENDING, REL-06 CONDITIONAL |

## 3. Selected ISP deployment model (Option A, locked)

- One **isolated instance per ISP**: application, instance-owned Core/RADIUS data, RADIUS/NAS identity/routing, keys/secrets, admin access, logging segregation, backup and restore. Common **source repository**, common reviewed release artifacts and automation are allowed.
- Multiple instances may share physical host(s), but not unisolated credentials, write access, network authority, customer data or backup material. Test isolation and recovery, do not assume Docker or a hostname alone provides it.
- Keep existing reseller closure tree/RBAC/Finance *inside* each ISP. `tbl_customers.id` remains the internal key **inside that instance**. External composite identity, if needed for centralized operations, must include an instance/ISP context; do not change the legacy schema by default.
- No shared-DB `tenant_id`, shared multi-tenant row-security migration or universal subscriber UUID mandated for V1. Revisit only via explicit change control.

## 4. Product scope (not expanded by this lock)

- Existing PPPoE/Hotspot features remain supported **only to the extent proven**. Existing period quota counters are Hotspot Limited; do not claim a universal PPPoE quota feature.
- Keep `NEW`, `RENEW`, `CHANGE_PLAN`, `REACTIVATE` commercial activation and financial settlement, no new automatic FUP next-profile business engine.
- No obligatory async durable queue/outbox/worker, Kafka/RabbitMQ, Redis, DB HA, multiple FreeRADIUS nodes, Kubernetes, microservices or cross-ISP centralized control plane.

## 5. Mandatory release gates (PENDING unless fresh evidence closes them)

| Gate | Binding acceptance proof |
|---|---|
| Session | WP8 Option G fail-closed integration (lab results are not production acceptance), lost Stop, unknown verifier/NAS, concurrency (two first simultaneous logins prior to Accounting-Start), renamed old identity |
| Q1–Q4 | cross-period long session, quota limit mid-session, delayed/lost Accounting, single-source/no destructive counter reset |
| P1–P3 | CHANGE_PLAN current-session after Disconnect UNKNOWN, RENEW old Session-Timeout, R-15 bandwidth terms after quote |
| N1–N7 | NAS identity/NAT, SQL Message-Authenticator, per-protocol RouterOS behavior, CoA source/port and semantic results, Option G faults, secrets, onboarding/rotation |
| REL-01–05 | actual isolated restore Core+RADIUS+configuration/secrets, ledger/reservation/external reconciliation, failure drills, workload/load capacity measurements, alerting |
| ISO-01 | no cross-ISP read/write/AAA/secret/backup/admin crossover, even for multiple instances on one host |
| REL-06 | **conditional:** failover drill when owner approves per-ISP HA requirement; no HA claim before it |

## 6. Existing work-package authority and blockers

- WP0–WP7: accepted with recorded notes. Do not rewrite frozen scope.
- WP8: **PENDING**. Option G only proved in disposable lab candidate, `dev/wp8/`; S7/S8 and formal WP8 acceptance pending. Close carry-forwards `R-WP8-CARRY-01` direct deactivation/removal bypass, `R-WP8-CARRY-02` live old username session, `R-WP8-CARRY-03` accounting time-zone, `R-HF-CARRY-01` legacy metadata exposure; do not silently waive for production.
- WP9: **PENDING**, owner red-team/security gate; include plugin/direct DB/device bypass, privilege scope, concurrency, secret boundaries and added correctness gates.
- WP10: **PENDING**, release handoff, tested staging restore and rollback, per-instance runbook, RTO/RPO owner settings, secure deployment and no automatic production action.
- No revision of this planning package grants permission to change GitHub branches, merge, deploy, or weaken existing authority.

## 7. Architecture-to-execution order (not an autonomous execution instruction)

1. Review/finalize WP8 implementation plan based on the locked decisions. Productionize only via separately authorized WP8 code changes and evidence; close blocking failures before S7/S8.
2. Complete mandatory targeted correctness gates and security/authority carry-forwards. No speculative replacement of working modules.
3. Advance WP9 red-team/release candidate only after accepted WP8 evidence.
4. Advance WP10 release/handover and actual staging restore/rollback only after accepted WP9. Release acceptance and deployment authorization remain separate.
5. Extend features (advanced FUP, HA, async workers, central fleet control) only after independent owner approval and measured/explicit requirements.

## 8. Acceptance definitions and anti-drift

- **APPROVED:** architectural principle and scope, not a passing test.
- **PROVEN:** linked test/artifact for the exact NAS/version/runtime combination.
- **PENDING:** implementation, stress/failover, acceptance or operational readiness not yet demonstrated.
- **CONDITIONAL:** not in V1 mandatory scope; requires explicit new decision.
- Use `ARCHITECTURE_DECISIONS.md` for detailed AD-00..AD-24, `GAP_ANALYSIS.md` and `RISK_REGISTER.md` for unclosed findings, and `IMPLEMENTATION_ROADMAP.md` for bounded sequence. Changes to a locked decision require named amendment with reason, impact and owner review.
