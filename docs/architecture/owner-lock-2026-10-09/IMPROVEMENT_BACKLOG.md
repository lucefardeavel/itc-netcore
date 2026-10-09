# IMPROVEMENT_BACKLOG

## Status

Architecture/planning only. Revision: **First-Principles Review 2 — 2026-10-07**.

The backlog is split into **mandatory evidence-backed work** and **conditional discovery**. SAS4 features do not enter implementation backlog automatically.

## P0 — Existing Production-Safety Closure

### AUD-P0-01 — Productionize Session Authority v1

Goal: turn the Option G safety contract into owner-accepted production behavior.

Acceptance intent:

- only proven-safe conflicting login may proceed;
- dependency failure never means offline;
- first login without conflict avoids live-NAS verification;
- multi-NAS fail-closed;
- no fake Stop/destructive accounting repair;
- secret-safe bounded verifier path;
- supported RouterOS timeout contract;
- repeated real PPPoE/Hotspot regression.

### AUD-P0-02 — Concurrent First-Login Gate

Run two same-subscriber PPPoE Access-Requests concurrently with no open accounting row and delayed/controlled first Accounting-Start.

Outcome:

- if one is safely rejected by existing layers, document and freeze evidence;
- if both are admitted, design the smallest short-lived per-customer/RADIUS-identity admission reservation and re-gate.

### AUD-P0-03 — Close Old-Identity Live Session

Maps to `R-WP8-CARRY-02`.

### AUD-P0-04 — Close Direct Deactivation/Removal Bypass

Maps to `R-WP8-CARRY-01`.

### AUD-P0-05 — Canonical Accounting Time

Maps to `R-WP8-CARRY-03`.

### AUD-P0-06 — Close Legacy Metadata Authorization Exposure

Maps to `R-HF-CARRY-01`.

## P1 — Minimum Session/NAS Production Foundation

### AUD-P1-01 — Session Authority Contract

Define:

- accounting evidence vs current liveness;
- PROVEN_SAFE / PROVEN_LIVE / UNKNOWN;
- exact RADIUS identity/session snapshot;
- customer-id mapping;
- NAS/service capability input;
- verifier health/deadline;
- stale semantics;
- operator read model.

### AUD-P1-02 — Minimal NAS Capability Model

Only fields needed by proven behavior, for example:

- vendor/service/firmware scope;
- session-limit mechanism;
- liveness verifier;
- CoA rate support;
- Disconnect support;
- timeout/transport requirement.

Do not pre-build unused SAS4-style channels.

### AUD-P1-03 — Secret Boundary for RADIUS/NAS Verification

Design least-privilege access and rotation without preselecting a secrets product.

### AUD-P1-04 — RADIUS Identity Mapping

Use existing `tbl_customers.id` as internal identity. Preserve the exact username/PPPoE username used by each relevant session and make rename/reprovision able to target the old identity safely.

### AUD-P1-05 — Minimum AAA/Session Observability

Add only the telemetry required to operate the fail-closed path safely.

### AUD-P1-06 — RouterOS Management Transport Review

For any production management channel actually needed, prefer encrypted transport and keep management plane private.

## P1/P2 — Existing Release Hardening

- plugin/direct-device authority red-team;
- least-privilege production DB grants where feasible;
- backup/restore verification;
- accounting retention/growth measurement;
- dependency startup/health rules;
- WP9/WP10 release gates.

## CONDITIONAL-01 — Advanced FUP Discovery

**Do not implement until owner approves a business requirement.**

Discovery questions:

- which dimensions matter: daily/total, upload/download/combined, uptime?
- throttle, cutoff, grace, or plan transition?
- reset period/timezone?
- what is billable vs free/excluded traffic?
- what freshness/error behavior is acceptable during accounting loss?

Only after answers exist should a FUP architecture be proposed.

## CONDITIONAL-02 — Durable Async Enforcement

Trigger only if a required network action must survive request/process failure and retry later.

Evaluate simplest valid option in order:

1. existing synchronous action with explicit UNKNOWN;
2. DB-backed durable command/outbox;
3. separate worker;
4. external broker only if workload/isolation justifies it.

## NOT SELECTED — Shared-DB Multi-Tenancy (Option A Supersedes)

**Decision already made:** Option A, separate isolated instance per ISP. Do not open a V1 shared-DB tenant program. This heading now preserves an **explicitly unselected alternative** only if the owner later changes the product model through formal change control.

## CONDITIONAL-04 — Scale / HA

First collect:

- auth/accounting throughput;
- DB utilization/latency;
- tree-lock wait/hold time;
- session-verifier latency;
- reporting pressure;
- availability/SLO target.

Then decide whether any of these are required:

- DB HA/read replica;
- multiple FreeRADIUS nodes;
- Redis session index;
- reporting replica;
- external broker;
- finer-grained tree locking;
- service extraction.

## Ordering Principle

1. close proven release defects;
2. prove simultaneous-use concurrency safety;
3. productionize the minimum Session Authority/NAS capability/telemetry needed;
4. finish WP8/WP9/WP10 acceptance gates;
5. only then activate optional FUP, tenancy, async, or scale programs through separate owner decisions.


## Owner Architecture Lock Work Items — 2026-10-09

- **ISO-01 (REQUIRED for deployment):** one isolated instance per independent ISP, independently scoped Core/RADIUS DB access, AAA/NAS configuration, secrets, administrative control, backups and restoration. Do not add tenant_id; validate no cross-instance leakage even on a shared host.
- **Q1–Q4 (REQUIRED if current quota offering is shipped):** crossing period boundaries; quota limit while session remains live; delayed/missing Interim/Stop; no double-count/reset deletion. Evidence must identify supported Hotspot/PPPoE modes.
- **P1–P3 (REQUIRED):** change-plan old-session enforcement including Disconnect UNKNOWN; RENEW old Session-Timeout behavior; R-15 bandwidth snapshot mismatch before provisioning.
- **N1–N7 (REQUIRED):** identity/NAT, SQL client Message-Authenticator, RouterOS capability by protocol, CoA results and source port, Option G fault behavior, secrets/least privilege, NAS onboarding/rotation.
- **REL-01–05 (REQUIRED):** actual isolated restore, finance/external reconciliation, dependency fault/recovery, load/capacity testing, minimal monitoring and alerts.
- **REL-06 (CONDITIONAL):** HA failover only for a separately approved per-ISP uptime/RTO/RPO requirement.
- Prioritize P0 WP8 carry-forwards ahead of optional capacity scaling; do not treat approval of a gate as evidence the test passed.
