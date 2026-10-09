# IMPLEMENTATION_ROADMAP

## Status

**Owner-approved architecture roadmap, not implementation authorization — 2026-10-09.** No code, branch, deployment or production actions are authorized by this document. Scope is subject to WP-by-WP acceptance.

Baseline comparison snapshot: First-Principles Review 2 — 2026-10-07.

This roadmap does not rename or replace existing WP8, WP9 or WP10.

## Gate 0 — Owner Architecture Lock

**COMPLETE as an owner architecture discussion — 2026-10-09.** The ten axes are locked in `OWNER_ARCHITECTURE_LOCK.md` and `ARCHITECTURE_DECISIONS.md`. This is **not** evidence that any technical release gate passed.

Keep out of V1 unless separately approved: advanced FUP, automated profile engine, shared-DB tenancy/tenant_id, broker, Redis, microservices, or an HA cluster. Owner explicitly chose **Option A isolated deployment per ISP**, not shared tenancy.

## Phase A — Finish Existing WP8 Safely

### A1. Productionize OD-W8-16 / Session Authority v1

Required proof:

- permanent reviewed FreeRADIUS/project integration;
- same Option G fail-closed behavior;
- no fail-open regression;
- secrets not exposed to logs/argv;
- bounded verifier deadline;
- RouterOS supported timeout verified;
- real PPPoE/Hotspot regressions;
- application does not destructively edit `radacct` to decide liveness.

### A2. Concurrent First-Login Gate

Run a real concurrency test where two Access-Requests for the same PPPoE identity race before Accounting-Start.

- If already safe, record evidence and add regression.
- If unsafe, design and gate the smallest bounded admission reservation/lock.

### A3. Close `R-WP8-CARRY-02`

Old RADIUS identity/session survives rename.

### A4. Close `R-WP8-CARRY-03`

Accounting timezone mismatch.

### A5. Close `R-WP8-CARRY-01` before production

Direct service removal/deactivation bypass of activation/reversal authority is a documented production blocker, not a deferrable launch issue.

### A6. Continue S7/S8

Only after the blocking session-admission issue and required gates are closed.

### A7. Owner WP8 acceptance

WP8 remains PENDING until the existing project acceptance process advances it.

## Phase B — Pre-Release Security / Authority Closure

Align with existing WP9/WP10 without silently expanding them:

- `R-HF-CARRY-01`;
- plugin/direct-device authority red-team;
- selected verifier secret boundary;
- least-privilege production DB/runtime access where feasible;
- RouterOS management transport review;
- accounting timezone/freshness verification;
- backup/restore;
- dependency startup/health rules;
- release-focused session observability.

## Phase C — Session Authority Contract Finalization

Document and test:

- accounting evidence vs current liveness;
- PROVEN_SAFE / PROVEN_LIVE / UNKNOWN;
- stale-unverified semantics;
- existing customer id + exact RADIUS identity mapping;
- NAS/service/version capability input;
- verifier health/deadline;
- multi-NAS behavior;
- hostile identity handling;
- operator diagnostics without credentials.

This may remain in the same codebase/process architecture. No service extraction is required.

## Phase D — Minimum Operational Telemetry

Before production rollout of new fail-closed dependencies, expose:

- session-verifier latency and result;
- NAS verifier health/failure;
- auth reject reason family;
- accounting freshness/timezone anomaly;
- CoA/Disconnect outcomes;
- correlation IDs.

Do not turn this into a tracing-platform project unless operations require it.

## Phase E — Existing WP9 Red-Team / Release Candidate

Recommended inputs:

- fail-open/fail-closed session review;
- concurrent-first-login regression;
- old-identity rename regression;
- secret/redaction review;
- plugin/direct-authority bypass tests;
- accounting time/freshness tests;
- CoA/Disconnect failure matrix;
- current carry-forward closure.

## Phase F — Existing WP10 Deployment / Operations Closure

Recommended inputs:

- DB/runtime grants;
- backup/restore;
- migration/deployment order;
- RouterOS firmware/service capability contract;
- RADIUS timeout/dependency settings;
- verifier secret provisioning/rotation;
- minimum telemetry/alerting;
- rollback/runbook.

## Conditional Program Gate 1 — Advanced FUP

No implementation unless owner first defines the desired product behavior.

If approved, perform a fresh design review from first principles. SAS4 features are examples only.

## Conditional Program Gate 2 — Durable Async Enforcement

Trigger only if required network work must survive request/process failure and retry later.

Evaluate simplest viable design before choosing outbox/worker/broker.

## Deployment Decision — Isolated ISP Instances (Option A, APPROVED)

Each ISP receives an independently isolated application deployment with separately scoped Core/RADIUS databases, runtime/secrets, NAS/AAA routing, backups, restore procedures and administrative control. A common source repository/release process is allowed. Multiple isolated instances may share physical infrastructure *only after* cross-instance isolation and recovery checks. **No `tenant_id` redesign**. Shared-DB multi-tenancy is a separate future product proposal, not a current program gate.

## Conditional Program Gate 4 — Scale / HA

Trigger from actual requirements/measurements:

- availability target;
- auth/accounting load;
- DB latency/utilization;
- tree-lock contention;
- verifier latency;
- reporting load.

Only then evaluate multiple FreeRADIUS nodes, DB HA/read replicas, Redis, broker, finer locks or service extraction.

## Release Split

### V1 / current release safety

- finish WP8 safely;
- prove simultaneous-use concurrency behavior;
- close current production carry-forwards;
- WP9 security/authority review;
- WP10 deployment/runbook;
- minimum operational telemetry.

### Future product capabilities, individually approved

- advanced FUP;
- runtime policy transitions;
- durable asynchronous enforcement;
- separately proposed shared-DB multi-tenancy **only if the owner reopens Option A by explicit change control**.

### Future scale capabilities, evidence-driven

- DB HA/read scale;
- multiple RADIUS nodes;
- Redis/session cache;
- broker;
- service extraction;
- lock refinement.

This roadmap intentionally prevents a reference comparison from turning into architecture scope explosion.


## Mandatory Release Verification Gates Added by Owner Lock (2026-10-09)

- **Quota Q1–Q4:** crossing billing boundaries, mid-session quota exhaustion, missing/delayed accounting, no double counts/destructive resets. Scope limited to currently supported plan/service combinations; do not claim PPPoE quota support without proof.
- **Commercial transitions P1–P3:** old-policy live session after uncertain Disconnect; RENEW with old Session-Timeout; bandwidth terms fixed from accepted preview to provisioning (R-15).
- **NAS N1–N7:** NAS/source identity and NAT; Message-Authenticator SQL clients; firmware/service capabilities; CoA source/port/results; Option G failures/deadlines; secret boundary; NAS onboarding/rotation.
- **Reliability REL-01–05:** isolated restore of Core+RADIUS+secrets/config, financial/session external reconciliation, dependency fault/recovery, load/capacity evidence, monitoring/alerting. **REL-06** HA/failover only upon an explicit availability requirement.
- **Per-ISP releases:** isolation checks for data, credentials, NAS route, backups, admin access and restore; owner-set RTO/RPO *before* deployment, never invented in documentation.
- Do not duplicate/rewrite WP9 or WP10. Integrate these as input acceptance checks and evidence attachments, preserving their owner review and NO DEPLOY defaults.
