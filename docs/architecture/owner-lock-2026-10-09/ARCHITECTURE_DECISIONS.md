# ARCHITECTURE_DECISIONS

## Status

> **Canonical Owner Architecture Lock — 2026-10-09.** Owner approved the ten architecture axes in the review conversation. Approval is of decisions and boundaries, **not** code completion, WP8/WP9/WP10 acceptance, production readiness, or authorization to change code/deploy. Reference SAS4 is non-authoritative.

## Ten Owner-Locked Axes

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

**OWNER-APPROVED ARCHITECTURE ENVELOPE — 2026-10-09.** Ten reviewed axes are locked below. Unimplemented gates, release blockers and conditional product features are **not** owner-accepted implementations. This document does not authorize code changes, production deployment, or silent WP8/WP9/WP10 scope changes.

**SAS4 is a non-authoritative clean-room reference.** Decision justification must come from our own product requirements, evidence and safe engineering constraints.

## AD-00: Reference Is Non-Authoritative

**Decision:** SAS4 can supply observations, test ideas and candidate concepts, but never normative justification by itself.

Every architecture proposal must be justified independently by product requirement, standards/security/data-integrity principles, current repository evidence, empirical integration evidence where relevant, and failure/concurrency analysis.

**Verdict:** **KEEP OUR DESIGN** as the starting authority; SAS4 remains reference-only.

## AD-01: Evolve, Do Not Rewrite

Keep the current modular monolith and extract only proven runtime pressure points.

**Verdict:** **KEEP OUR DESIGN**

## AD-02: FreeRADIUS Remains the AAA Protocol Edge

FreeRADIUS owns packet handling, authentication protocol, SQL policy, accounting ingestion and RADIUS protocol modules for the current architecture.

Do not add a per-request external policy process without a measured/required reason.

**Verdict:** **KEEP OUR DESIGN**

## AD-03: `radacct` Is FreeRADIUS-Owned Accounting History, Not an Immutable Event Log

Correction to Revision 1: `radacct` rows are legitimately updated by FreeRADIUS as Start/Interim/Stop information arrives. The table therefore must not be described as literally immutable.

The binding rule is:

- application/business code does not destructively edit or delete `radacct` to repair sessions or reset quota;
- FreeRADIUS remains the accounting writer;
- session current-state is derived separately;
- if a future requirement needs a truly append-only raw packet/event journal, add a separate journal rather than pretending `radacct` already is one.

**Verdict:** **KEEP OUR DESIGN**, wording corrected.

## AD-04: Introduce Logical Session Authority

**Owner-approved direction in this review.**

A logical Session Authority decides whether a conflicting login is safe from:

1. accounting evidence;
2. live NAS evidence where required;
3. verifier health/freshness;
4. vendor/service capability;
5. explicit uncertainty.

Core verdicts:

- `PROVEN_SAFE`
- `PROVEN_LIVE`
- `UNKNOWN`

Only `PROVEN_SAFE` permits a conflicting candidate login to proceed.

Session Authority is a contract, not a mandate for a separate microservice. V1 may live close to FreeRADIUS.

**Verdict:** **REDESIGN**

## AD-05: Preserve Option G Safety Semantics, Not Necessarily Its Packaging

**Owner-approved direction in this review.**

Option G provides the current strongest real-NAS evidence for the V1 contract:

- strict input handling;
- bounded deadline;
- dependency failure -> UNKNOWN/reject;
- first login without conflict avoids NAS verification;
- multi-NAS fail-closed;
- no fake Accounting-Stop or destructive accounting repair;
- explicit PPPoE discriminator.

The current lab script is not automatically the permanent architecture.

**Verdict:** **KEEP OUR DESIGN** for behavior; implementation packaging remains subject to review.

## AD-06: Stale Means Unverified, Not Offline

**Owner-approved direction in this review.**

Age/freshness is a trigger for verification, not proof of session death.

Current-state terminology should distinguish at least:

- LIVE / PROVEN_LIVE
- GONE / PROVEN_SAFE
- STALE_UNVERIFIED
- UNKNOWN

The accounting record remains historical evidence; current liveness is derived separately.

**Verdict:** **REDESIGN**

## AD-07: Simultaneous-Use Is Vendor/Service Aware and Requires a Concurrency Gate

The product must not assume one attribute works identically across RouterOS services.

Verified facts:

- Hotspot Port-Limit works as defense-in-depth in the tested environment;
- PPPoE Port-Limit is not an authoritative control in the tested environment;
- stock checkrad failure semantics are unsafe for our required fail-closed policy.

Additional mandatory design test before production acceptance:

> Two same-subscriber PPPoE Access-Requests arrive concurrently while no open `radacct` row exists yet and before the first Accounting-Start.

If this gate proves both can pass, introduce the smallest bounded per-subscriber admission reservation/lock that closes the race. Do not pre-commit to a lock before the gate proves it is needed.

**Verdict:** **REDESIGN**

## AD-08: Uncertainty Never Creates Security-Sensitive Access or Financial Value

**Consequence-based failure policy:** auth/session admission with unknown safety or authorization proof rejects new unsafe grants; financial/provisioning external result uncertain remains `UNKNOWN` / `RECONCILIATION_REQUIRED` with funds held as required; Disconnect/CoA response uncertain is never falsely marked applied; missing Interim/Stop does **not** automatically trigger mass disconnect; observability and notifications may degrade without falsifying core results. `fail-closed` is **not** shorthand for disconnecting every established session.

Uncertainty cannot create unauthorized access, privileges, ownership, money or irreversible settlement. Availability impacts must be observable and managed.

**Verdict:** **KEEP OUR DESIGN**

## AD-09: Keep Current CoA/Disconnect Primitive and Capability Semantics

Keep the current native RFC5176 client and current result classification.

- use CoA only for attributes proven effective on the target NAS/service;
- unsupported live semantic changes require Disconnect + re-auth where appropriate;
- an ACK is not sufficient evidence for behavior the target NAS does not actually support.

**Verdict:** **KEEP OUR DESIGN**

## AD-10: Durable Async Enforcement Is Conditional, Not Yet Mandatory

A single durable worker/outbox architecture is useful only if an approved workflow requires network work to survive process/request failure and be retried asynchronously.

Current synchronous/best-effort actions with explicit UNKNOWN are not replaced merely because SAS4 has polling workers.

If durable async enforcement becomes a requirement, there must be one logical action authority, idempotency, replay safety and explicit terminal/UNKNOWN handling.

**Verdict:** **NOT APPLICABLE** until a concrete requirement is approved.

## AD-11: Keep Current Quota Scope; Advanced FUP Requires Product Approval

Keep the existing period time/data quota scope (currently evidenced for Hotspot Limited) and do **not** claim general PPPoE quotas without implementation/evidence. Before production closure, run **Q1** cross-period long-running session accounting, **Q2** mid-session quota exhaustion/enforcement, **Q3** delayed/missing Interim/Stop semantics, **Q4** no double counting or destructive `radacct` resets.

Daily/total upload/download/uptime, free-zone accounting, carry-over, throttle graphs and similar SAS4 features are **candidate product features**, not architecture obligations.

**Verdict:** **KEEP OUR DESIGN** for current scope; advanced FUP is **NOT APPLICABLE** until owner requirements are defined.

## AD-12: Runtime Policy State Is Conditional

**Approved commercial transition design** remains the current `ActivationService` flow: `NEW`, `RENEW`, `CHANGE_PLAN`, `REACTIVATE`, with pricing snapshot, funds reservation, verified RADIUS policy and settlement/reconciliation. Before production, verify (P1) change-plan session re-enforcement after uncertain Disconnect; (P2) renewal while an old Session-Timeout is in force; (P3) preview/confirmation/provisioning bandwidth-term consistency (known R-15).

Do not create a second *automatic FUP next-profile* state machine just because SAS4 has next-profile pointers. If advanced FUP is later approved, transient network policy must be separate from the paid activation state.

**Verdict:** **NOT APPLICABLE** until the feature exists.

## AD-13: Keep Current Financial Ledger as Truth

No SAS4 finance model replaces ledger/reservation/settlement/reversal.

**Verdict:** **KEEP OUR DESIGN**

## AD-14: Use Existing Customer ID as Internal Identity; Separate Mutable RADIUS Identity

Correction to Revision 1: the current domain already has `tbl_customers.id` and uses customer IDs through ownership, audit and activation flows. A brand-new `subscriber_id` is not justified by current evidence.

Target rule:

- existing customer ID remains the internal customer identity;
- exact RADIUS identity used by a session is captured/mapped separately;
- username/PPPoE username changes must not erase the relationship to old active sessions;
- introduce a new global/UUID subscriber identifier only if a separate tenancy/integration requirement proves that `tbl_customers.id` is insufficient.

**Verdict:** **KEEP OUR DESIGN** for internal identity, with a **REDESIGN** of RADIUS edge-identity mapping.

## AD-15: True Multi-Tenancy Is a Product Decision, Not an Assumed Gap

**Owner decision: Option A — one separately isolated runtime instance per independent ISP**, based on a shared, versioned source repository. Isolate application runtime, Core and RADIUS databases, secrets, NAS registration/AAA routes, session state, backups/restore, configuration and operational access per ISP. Separate physical hosts are optional if logical isolation on a shared host is proven. Existing reseller hierarchy remains *inside* each ISP instance.

Do **not** add a shared-DB `tenant_id` scheme or describe reseller ancestry as tenant isolation. A future shared deployment is a distinct product decision and requires its own architecture/security review.

**Verdict:** **APPROVED — ISOLATED INSTANCE PER ISP (OPTION A)**.

## AD-16: If Multi-Tenancy Is Approved, It Must Be End-to-End

For the selected Option A, **shared-database multi-tenancy is out of V1 scope**. The future-only security constraint remains: any separately approved shared-tenant design must isolate identity, customers, plans, reseller operations, NAS/RADIUS routes, finance, audits, APIs, exports and backups end-to-end.

**Verdict:** **NOT APPLICABLE — NOT A V1 DESIGN PATH**.

## AD-17: Add the Minimum NAS Capability Model Proven Necessary by WP8

WP8 independently proves that behavior differs by service/version, for example Hotspot vs PPPoE Port-Limit and CoA attribute effectiveness.

Therefore add only the capability knowledge needed to make safe decisions, for example:

- vendor/service/firmware scope;
- session-limit mechanism;
- liveness-verification method;
- CoA/Disconnect capabilities;
- required timeout/transport assumptions.

Do not clone SAS4's broad registry just because it exists.

**Verdict:** **REDESIGN**

## AD-18: Define a Least-Privilege Secret Boundary, Do Not Preselect the Storage Product

Current RADIUS/SNMP/management secrets must be readable only by runtime actors that require them and must not appear in logs/audit/argv.

The architecture decision is the **boundary and access model**, not automatically “external secrets manager” or “encrypt every DB column.” Storage/materialization must be chosen against the actual deployment model, key location and FreeRADIUS compatibility.

**Verdict:** **REDESIGN**

## AD-19: Transactional Outbox Is a Candidate Pattern, Not a Mandate

Use it if a future approved workflow requires atomic “state committed + durable work scheduled.” Do not add it preemptively.

**Verdict:** **NOT APPLICABLE** today.

## AD-20: Online Session Cache Is a Performance Option

Redis or equivalent is not part of the mandatory target. Add only if measured session lookup/load requirements justify it. It can never become sole accounting/session authority.

**Verdict:** **NOT APPLICABLE** today.

## AD-21: Minimum Operational Observability Is Required

The fail-closed session path must expose enough telemetry to operate safely:

- auth/session-verifier result and latency;
- NAS verifier health;
- accounting freshness/timezone anomalies;
- CoA/Disconnect result categories;
- relevant error counts without credentials.

Full distributed tracing/SLO infrastructure is deployment-dependent and not mandated by this decision.

**Verdict:** **REDESIGN**

## AD-22: Preserve SUCCESS / FAILED / UNKNOWN

Do not collapse timeout/uncertainty into success or confirmed failure.

**Verdict:** **KEEP OUR DESIGN**

## AD-23: Keep Global Tree Serialization Until Measured Pressure Exists

Instrument wait/hold time before weakening a proven correctness mechanism.

**Verdict:** **KEEP OUR DESIGN**

## AD-24: Reject Unsafe Reference Patterns Only on Independent Grounds

Examples currently rejected on independent security/reliability grounds include shared shipped root keys, blanket root/sudo, arbitrary remote-command channels, unsafe standing vendor tunnels, and overlapping non-idempotent enforcement actors.

Other SAS4 patterns are not automatically “wrong” merely because they are old or different. For example, a single-host deployment or DB-backed queue can be valid in some workload/deployment contexts.

**Verdict:** **SAS4 LEGACY — IGNORE** only where the specific pattern independently violates our requirements.

## Owner Decision State — 2026-10-09 (Canonical)

- **All ten reviewed architecture axes: APPROVED as design direction.** AD-00 is binding; AD-01 to AD-24 are interpreted under the mapping above and the owner-selected Option A.
- **Implementation != approval:** all WP8 carry-forwards, WP8 S7/S8 and acceptance, WP9 security, WP10 restore/deployment gates remain pending until separately evidenced and accepted.
- **Conditional modules remain NOT APPROVED to build:** advanced/daily FUP, automatic next-profile engine, durable queue/outbox/broker, Redis/session cache, DB/RADIUS HA cluster, microservices, per-table tenant IDs, finer hierarchy lock restructuring. These require individually justified new owner decisions.
- **High-priority acceptance gates:** Q1–Q4 quota; P1–P3 plan; N1–N7 NAS; REL-01–REL-05 recovery/scale; concurrent-first-login gate; productionized Option G/Session Authority. REL-06 HA failover only if HA is subsequently required.
- **No authority transfer from SAS4:** it remains an evidence-limited, non-normative reference; no vendor software, licensing bypass, or code transplantation.
- See `OWNER_ARCHITECTURE_LOCK.md` as the current concise owner-facing register and execution boundary.
