# GAP_ANALYSIS

## Status

Revision: **Owner Architecture Lock — 2026-10-09** (original evidence based on First-Principles Review 2, 2026-10-07).

This document now separates **proven gaps** from **conditional product/scale questions**. SAS4 observations do not create a gap by themselves.

## Governing Rule

A missing SAS4 feature is not automatically a deficiency in SaaS RADIUS.

A real gap requires at least one of:

- an explicit owner/product requirement;
- a current accepted invariant that is violated;
- a reproduced defect/failure;
- a security/reliability requirement supported independently;
- measured scale/operability evidence.

## P0 — Proven Production-Safety Gaps

### GAP-01: Session Admission Can Be Wrong When Accounting-Stop Is Lost

**Evidence:** WP8 S6 real-CHR reproduction.

**Current effect:** open `radacct` row can outlive the real RouterOS session and lock out a new PPPoE login.

**Required direction:** Session Authority / fail-closed real-state verification.

**Priority:** P0.

### GAP-02: Option G Safety Behavior Is Proven but Not Productionized

The strongest current PPPoE session-safety behavior is still a WP8 candidate under `dev/wp8/`.

**Required direction:** owner-approved production integration, bounded interface, repeated real-NAS gate, no fail-open regression.

**Priority:** P0.

### GAP-03: Simultaneous-Use Is Service/Version Specific

Proven:

- Hotspot Port-Limit worked in the tested RouterOS environment;
- PPPoE Port-Limit did not provide the required control;
- stock checkrad verification failure can fail open.

**Required direction:** minimal NAS capability knowledge for the supported service/version matrix.

**Priority:** P0/P1.

### GAP-04: Concurrent First-Login Race Is Not Yet Proven Closed

FreeRADIUS Simultaneous-Use depends on accounting knowledge. Two same-subscriber Access-Requests can theoretically arrive before the first Accounting-Start exists.

**Required evidence gate:** two real concurrent PPPoE Access-Requests, zero initial open rows, first Accounting-Start deliberately delayed/controlled.

If both are admitted, add the smallest bounded admission reservation/serialization mechanism. If the gate proves the race is already closed by another layer, do not add complexity.

**Priority:** P0 before declaring simultaneous-use production-safe.

### GAP-05: Stale-Session Timezone Mismatch

`AccountingService::staleSessions()` can compare application time against `radacct` values written in another DB-session timezone.

**Required direction:** one tested accounting time contract, preferably UTC across FreeRADIUS SQL session/application interpretation.

**Priority:** P0.

### GAP-06: Old RADIUS Identity Can Stay Online After Username Reprovision

Maps to `R-WP8-CARRY-02`.

**Required direction:** exact old session identity must be addressed during rename/reprovision, with uncertainty surfaced.

**Priority:** P0.

### GAP-07: Direct Legacy Service Removal/Deactivation Can Bypass Reversal Authority

Maps to `R-WP8-CARRY-01`.

**Required direction:** close/bound the bypass before production.

**Priority:** P0.

### GAP-08: Legacy Metadata Authorization Exposure

Maps to `R-HF-CARRY-01`.

**Priority:** release closure required.

### GAP-09: Session-Verifier Secret/Dependency Boundary Is Not Production-Defined

Option G requires live NAS verification and therefore runtime credentials/dependencies.

**Required direction:** least-privilege secret access, no secret in log/argv, deployment health checks, bounded timeout.

**Priority:** P0/P1.

## P1 — Proven Architectural/Operational Work

### GAP-10: Session Current-State Authority Is Not a First-Class Contract Yet

Current accounting and current liveness are too easy to conflate.

**Required direction:** explicit current-state result such as PROVEN_SAFE / PROVEN_LIVE / UNKNOWN, separate from historical accounting state.

### GAP-11: `radacct` Terminology Was Too Strong

Correction: `radacct` is not immutable. FreeRADIUS updates rows as accounting progresses.

The required invariant is that **business/application code must not destructively rewrite accounting to repair sessions or reset quota**.

If a truly append-only raw event journal is ever required, it must be a separate structure.

### GAP-12: RADIUS Edge Identity Is Too Tightly Coupled to Mutable Username

The product already has `tbl_customers.id` as internal customer identity. The real gap is not “no immutable subscriber ID.”

The gap is that exact RADIUS identities and live sessions need a reliable mapping back to the existing customer id across rename/reprovision.

**Target:** customer id + exact edge identity/session snapshot.

### GAP-13: Minimum Session/NAS Operational Telemetry Is Missing

Fail-closed controls need operational visibility.

Minimum required metrics/logging:

- Session Authority verdict counts/latency;
- verifier dependency failures;
- NAS reachability/verifier health;
- accounting freshness/timezone anomalies;
- CoA/Disconnect outcomes;
- auth reject reason families.

This does **not** automatically require a full distributed tracing platform.

### GAP-14: RouterOS Management Transport Is Bounded but Not Ideal

Private-only plain TCP management can be acceptable as a constrained POC/internal path, but production should prefer encrypted management transport where that channel is actually needed.

### GAP-15: Plugin/Legacy Authority Surfaces Need Release Red-Team Closure

Existing WP5B/WP9 residual remains independent of SAS4.

## Conditional Questions — Not Current Gaps

### CQ-01: Advanced FUP

Daily/total upload/download/uptime, free zones, carry-over and runtime throttling are **not yet owner-approved product requirements**.

Status: **NOT APPLICABLE until requirement is defined**.

### CQ-02: Runtime Policy Transition State Machine

Only needed if advanced FUP/suspension policy is approved.

Status: **NOT APPLICABLE**.

### CQ-03: Durable Outbox / Enforcement Worker

Useful if a required external action must survive request/process failure and retry asynchronously.

Current absence alone is not a defect.

Status: **NOT APPLICABLE pending durability requirement**.

### CQ-04: Shared-Database Multi-Tenancy (NOT SELECTED; Option A APPROVED)

Existing reseller hierarchy isolates roles/data *inside* one ISP instance. Owner has now selected **Option A: isolated instance per independent ISP**; absence of `tenant_id` is not a gap. Shared-DB multi-tenancy remains **NOT SELECTED**, available only through an explicit future architecture change.

Status: **NOT APPLICABLE — Option A selected and locked 2026-10-09**.

### CQ-05: Database HA / Read Scale

Backup/restore and recovery are required operationally. Active HA/read replicas depend on availability target and measured scale.

Status: **NOT APPLICABLE until SLO/deployment requirements are defined**.

### CQ-06: Redis / Fast Session Index

Status: **NOT APPLICABLE until measured lookup/load need exists**.

### CQ-07: External Broker

Status: **NOT APPLICABLE until outbox/worker volume or isolation requirements justify it**.

### CQ-08: Global Tree-Lock Optimization

Current lock is a correctness mechanism.

Status: **NOT APPLICABLE until lock wait/hold metrics show material contention**.

### CQ-09: New Global Subscriber UUID

Current product already uses customer IDs internally. A new subscriber identifier is not justified today.

Status: **NOT APPLICABLE unless future tenancy/federation/integration requirements prove `tbl_customers.id` insufficient**.

## Areas Where Current Design Remains the Stronger Baseline

- centralized RBAC/data scoping;
- reseller finance/ledger/reservations;
- pricing resolution;
- activation/reconciliation/reversal;
- explicit SUCCESS/FAILED/UNKNOWN classification;
- prepared/parameterized new-domain SQL;
- current RouterOS 7.24.5 evidence;
- schema/readiness discipline.

## Architectural Principle

Close proven defects first. Add optional architecture only after the requirement or measurement exists.

SAS4 may suggest tests and candidate features, but it does not define what our product is missing.


## Owner-Locked Correctness / Release Gates (2026-10-09)

The following are **unclosed verification obligations**, not license to add a new feature engine:

- Q1–Q4: quota sessions spanning period reset, exhaustion while online, delayed/missing accounting, no double-count/destructive reset.
- P1–P3: active session after change-plan/Disconnect UNKNOWN, renewal with existing Session-Timeout, bandwidth snapshot consistency (R-15).
- N1–N7: NAS identity/transport, SQL Message-Authenticator, firmware/service capabilities, CoA outcome, Option G error handling, secrets, onboarding/rotation.
- REL-01–REL-05: restore, financial/network reconciliation, dependency failure drills, representative load/capacity measurement, monitoring/alerting. HA failover REL-06 is **conditional**.
- Selected V1 isolation is **one independent instance per ISP**. Missing cross-instance isolation tests are a deployment validation issue; missing shared-tenant IDs are **not** a gap.

Approval of the architecture **does not close** GAP-01–GAP-15, release carry-forwards or WP8/WP9/WP10.
