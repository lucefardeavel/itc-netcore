# KEEP_ADOPT_REPLACE_MATRIX

## Reference State

- Product evidence: `lucefardeavel/phpnuxbill-reseller@47a3a02cb0d469d324393515186f2d1497641b31`
- SAS4 reference: `lucefardeavel/sas4-reference-intelligence@76a4f7ae83f5fc50eaf0c7c9c440c35450cdba65`
- Status: **owner-locked architecture review (2026-10-09)**; no implementation authorization or WP8/WP9/WP10 acceptance.
- Reference snapshot: First-Principles Review 2 — 2026-10-07.

## K00 — Governing Rule

**SAS4 is non-authoritative.** A feature is never kept, adopted, merged, replaced or ignored solely because of the SAS4 comparison. Decisions require independent requirement, standards, repository evidence and failure/concurrency analysis.

## Decision Matrix

| ID | Design element | Decision | First-principles treatment |
|---|---|---|---|
| K01 | FreeRADIUS 3.2+ as AAA protocol edge | **KEEP OUR DESIGN** | Current proven edge. |
| K02 | SQL-backed managed RADIUS policy | **KEEP OUR DESIGN** | Current proven model. |
| K03 | Mandatory Message-Authenticator | **KEEP OUR DESIGN** | Release security invariant. |
| K04 | PAP/CHAP for current PPPoE/Hotspot scope | **KEEP OUR DESIGN** | Keep for current supported scope. |
| K05 | Per-request `sas_auth` executable | **SAS4 LEGACY — IGNORE** | No measured need; adds process/failure surface. |
| K06 | `RadiusRepository` as single policy-row writer | **KEEP OUR DESIGN** | Preserve single writer. |
| K07 | SUCCESS / FAILED / UNKNOWN external result | **KEEP OUR DESIGN** | Reuse wherever uncertainty matters. |
| K08 | FreeRADIUS-owned `radacct` accounting history | **KEEP OUR DESIGN** | Application remains read-only. Corrected wording: rows are mutable by legitimate RADIUS accounting updates; do not call the table immutable. |
| K09 | `rad_acct` as non-authoritative compatibility counter | **KEEP OUR DESIGN** | Keep only while required; never promote to accounting/session truth. |
| K10 | Delete/rewrite `radacct` to reset quota or hide stale sessions | **SAS4 LEGACY — IGNORE** | Destructive repair loses evidence and couples accounting to policy. |
| K11 | Session admission from open `radacct` rows alone | **REDESIGN** | Add explicit Session Authority/liveness evidence. |
| K12 | Stock checkrad as safety authority | **SAS4 LEGACY — IGNORE** | Our real gate proved unsafe fail-open behavior. |
| K13 | Option G fail-closed tri-state semantics | **KEEP OUR DESIGN** | Behavioral contract for Session Authority v1. |
| K14 | Option G lab-script packaging as permanent architecture | **REDESIGN** | Productionize behind a bounded interface; technology can evolve later. |
| K15 | Hotspot Port-Limit as defense-in-depth | **KEEP OUR DESIGN** | Keep only for verified RouterOS Hotspot behavior. |
| K16 | PPPoE Port-Limit as authoritative limit | **SAS4 LEGACY — IGNORE** | Real RouterOS 7.24.5 gate disproved it. |
| K17 | Age-only stale-window Simultaneous-Use | **REDESIGN** | Age triggers verification; it does not prove offline. |
| K18 | Live NAS evidence for conflicting-session decisions | **REDESIGN** | Independently justified by WP8 lost-Stop evidence, not adopted from SAS4. |
| K19 | Native RFC5176 CoA/Disconnect client | **KEEP OUR DESIGN** | Current hardened network primitive. |
| K20 | Durable detector -> queue -> actuator path | **NOT APPLICABLE** | Candidate only if an approved use case requires actions to survive process/request failure and retry asynchronously. |
| K21 | Multiple overlapping disconnect actors | **SAS4 LEGACY — IGNORE** | If async enforcement is added, one authority/idempotency contract is required. |
| K22 | Rate change by CoA where proven | **KEEP OUR DESIGN** | Capability-gated by real NAS evidence. |
| K23 | IP/pool change via Disconnect + re-auth | **KEEP OUR DESIGN** | Current RouterOS evidence supports this. |
| K24 | Current period time/data quota counters | **KEEP OUR DESIGN** | Existing product capability. |
| K25 | Daily/total multidimensional FUP expansion | **NOT APPLICABLE** | Not an approved product requirement yet. |
| K26 | Explicit quota/daily next-policy transitions | **NOT APPLICABLE** | Evaluate only if advanced FUP is approved. |
| K27 | Raw vs billable/free-zone usage separation | **NOT APPLICABLE** | Add only for a concrete commercial requirement. |
| K28 | Multiple independent billable-usage writers | **REDESIGN** | If additional telemetry is added, define one billing authority and explicit reconciliation. |
| K29 | Current ActivationService saga | **KEEP OUR DESIGN** | Preserve. |
| K30 | Completed-activation reversal saga | **KEEP OUR DESIGN** | Preserve. |
| K31 | Reseller ledger | **KEEP OUR DESIGN** | Financial truth. |
| K32 | Reservation + settlement model | **KEEP OUR DESIGN** | Preserve. |
| K33 | SAS4 journals as replacement finance model | **SAS4 LEGACY — IGNORE** | No independent reason to replace stronger current finance. |
| K34 | Closure-table reseller hierarchy | **KEEP OUR DESIGN** | Preserve for current reseller scope. |
| K35 | Central capability/data-scope authorization | **KEEP OUR DESIGN** | Preserve as authority. |
| K36 | SAS4 web-centric ACL authority | **SAS4 LEGACY — IGNORE** | Would weaken current centralized authority. |
| K37 | Reseller hierarchy treated as tenant isolation | **NOT APPLICABLE** | It is intra-ISP role/data scope only; owner chose isolated instances for independent ISPs. |
| K38 | NAS capability model | **REDESIGN** | Minimal vendor/service/version capabilities are independently justified by WP8 differences. Do not clone SAS4's broad registry. |
| K39 | Broad plaintext management/RADIUS/SNMP secret access | **SAS4 LEGACY — IGNORE** | Define least-privilege secret boundary; storage mechanism remains design-specific. |
| K40 | Write-only NAS secret UI | **KEEP OUR DESIGN** | Keep presentation/control behavior. |
| K41 | RouterOS >=7.15 hardening floor | **KEEP OUR DESIGN** | Current accepted product constraint. |
| K42 | RouterOS 7.24.5 observed capability evidence | **KEEP OUR DESIGN** | Current verified behavioral baseline, not universal future truth. |
| K43 | RouterOS management API exposed publicly | **SAS4 LEGACY — IGNORE** | Management plane remains private/restricted. |
| K44 | Private-only unencrypted RouterOS API path | **REDESIGN** | Accept only as bounded current/POC condition; prefer encrypted management path for production where used. |
| K45 | Database-backed work queue | **NOT APPLICABLE** | Not inherently wrong. Choose DB queue/outbox/broker only after durability/load requirements are explicit. |
| K46 | Transactional outbox | **NOT APPLICABLE** | Candidate reliability pattern, not mandatory architecture today. |
| K47 | External broker immediately | **NOT APPLICABLE** | Requires measured operational need. |
| K48 | Fast online index such as Redis | **NOT APPLICABLE** | Requires measured lookup/scale need; never accounting authority. |
| K49 | Single-host deployment | **NOT APPLICABLE** | Not automatically wrong. Deployment topology follows availability/SLO requirements. |
| K50 | Modular monolith for business core | **KEEP OUR DESIGN** | Current default. |
| K51 | Full microservices rewrite | **NOT APPLICABLE** | No evidence justifies it. |
| K52 | Existing `tbl_customers.id` as internal customer identity | **KEEP OUR DESIGN** | Already provides stable internal identity for current domain. |
| K53 | Mutable RADIUS username as cross-domain identity | **REDESIGN** | Map exact RADIUS identities/sessions back to existing customer id; do not invent a new subscriber id unless a separate requirement proves necessary. |
| K54 | Request correlation + append-only audit | **KEEP OUR DESIGN** | Preserve. |
| K55 | Minimum AAA/session/NAS operational metrics | **REDESIGN** | Required to operate fail-closed session controls safely. Full tracing/SLO stack remains conditional. |
| K56 | Standing vendor reverse tunnel with static/shared credentials | **SAS4 LEGACY — IGNORE** | Independent security risk. Controlled, opt-in support access is a different design question. |
| K57 | Shared shipped root key | **SAS4 LEGACY — IGNORE** | Independent critical security risk. |
| K58 | Root daemons / blanket sudo by default | **SAS4 LEGACY — IGNORE** | Least privilege. |
| K59 | Parameterized SQL in new domain | **KEEP OUR DESIGN** | Preserve. |
| K60 | Real FKs/constraints where current domain relies on them | **KEEP OUR DESIGN** | Preserve current integrity model. |
| K61 | No-FK architecture as a general rule | **NOT APPLICABLE** | Not universally wrong, but no reason exists to weaken our current integrity model. |
| K62 | Shared-DB `tenant_id` isolation | **NOT APPLICABLE** | Owner selected Option A isolated instance per ISP; not a V1 requirement. |
| K63 | Production DB HA/read-scale topology | **NOT APPLICABLE** | Backup/restore is required; HA topology depends on availability target and deployment scale. |
| K64 | Global reseller-tree lock | **KEEP OUR DESIGN** | Keep until measured contention proves a problem. |
| K65 | Finer-grained tree concurrency | **NOT APPLICABLE** | Optimization only after evidence. |
| K66 | Concurrent-first-login admission gate | **REDESIGN** | Must be tested explicitly: two same-subscriber Access-Requests before first Accounting-Start. Add a bounded reservation/lock only if the gate proves a race. |

| K67 | Per-ISP isolated deployment (Option A) | **KEEP OUR DESIGN** | **OWNER APPROVED:** separate application/Core DB/RADIUS DB/NAS/secrets/backup domains for every independent ISP; shared versioned code allowed. |
| K68 | Verified backup/restore and recovery | **REDESIGN** | REL-01–REL-03 are V1 release requirements, not HA architecture. |
| K69 | Real load/capacity/observability gates | **REDESIGN** | REL-04/05 required before declaring capacity; no invented numbers or premature cluster. |
| K70 | Current commercial plan transitions | **KEEP OUR DESIGN** | Keep NEW/RENEW/CHANGE_PLAN/REACTIVATE; pass P1–P3. |
| K71 | Quota accounting correctness gates | **REDESIGN** | Keep limited current feature scope; pass Q1–Q4; advanced FUP not approved. |

## Summary

### Mandatory / evidence-backed direction

- preserve current business/RBAC/finance/activation core;
- finish Session Authority and stale-session safety;
- preserve fail-closed uncertainty semantics;
- add minimal NAS capability knowledge required by proven vendor/service differences;
- add minimum operational metrics for the new fail-closed path;
- close existing production carry-forwards.

### Conditional, not yet approved architecture

- advanced FUP;
- runtime policy transition engine;
- outbox/worker/broker;
- Redis/session cache;
- shared-DB multi-tenancy (explicitly not selected; Option A is APPROVED);
- DB HA/read replicas beyond backup/restore;
- finer-grained hierarchy concurrency.

SAS4 may continue to inform tests and questions, but none of these conditional items become architecture merely because SAS4 has an analogue.
