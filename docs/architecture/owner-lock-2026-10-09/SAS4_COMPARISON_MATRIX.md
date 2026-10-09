# SAS4_COMPARISON_MATRIX

## Reference State

- Current product evidence: `lucefardeavel/phpnuxbill-reseller@47a3a02cb0d469d324393515186f2d1497641b31`
- Accepted product base: `feat/reseller-system-v1@5bdc57459a64dd53e32e5abb2ef6efa3815e387a`
- SAS4 reference: `lucefardeavel/sas4-reference-intelligence@76a4f7ae83f5fc50eaf0c7c9c440c35450cdba65`
- Clean-room only. No vendor code, protected logic, licensing mechanism or secret is copied.
- Revision: **First-Principles Review 2 — 2026-10-07**.

## Governing Rule — Reference Is Non-Authoritative

SAS4 is an **evidence source and idea inventory**, not a correctness oracle and not a target architecture.

No SAS4 behavior is adopted merely because SAS4 implements it. A proposal must survive, in order:

1. current product/owner requirements and accepted invariants;
2. protocol/security/data-integrity first principles and public standards;
3. actual current-repository behavior;
4. empirical FreeRADIUS/NAS integration evidence where relevant;
5. failure, concurrency and rollback analysis.

SAS4 may reveal a useful question or candidate concept. It cannot, by itself, establish the answer.

Independent technical anchors used in this review include RADIUS Accounting (RFC 2866), Dynamic Authorization (RFC 5176), FreeRADIUS 3.2 Simultaneous-Use guidance, MikroTik RouterOS RADIUS documentation, and the project's own WP8 real-CHR evidence.

## Axis-by-Axis Matrix

| Axis | Current SaaS RADIUS | SAS4 observation | Decision | Independent rationale |
|---|---|---|---|---|
| Authentication | FreeRADIUS 3.2.8 SQL path; PAP/CHAP; mandatory Message-Authenticator; deterministic provisioned attributes | FreeRADIUS 3.0.16 plus native `sas_auth` post-auth executable | **KEEP OUR DESIGN** | Current path is already tested, simpler, and avoids per-request process execution. SAS4 adds no independently proven benefit here. |
| Authorization | Central PermissionService, capabilities, DataScope, RouteGuard, fail-closed readiness | ACL groups + reseller hierarchy, with broader app/daemon authority | **KEEP OUR DESIGN** | Current centralized server-side authority is a stronger explicit invariant. |
| Accounting | FreeRADIUS-owned `radacct` accounting projection/history; application read-only | `radacct` plus extra traffic/accounting daemons | **KEEP OUR DESIGN** | Keep one authoritative RADIUS accounting store for current product semantics. Important correction: `radacct` rows are not immutable; FreeRADIUS legitimately updates them on Interim/Stop. Application code must not destructively rewrite them to repair sessions or reset quota. |
| Sessions | Open `radacct` + Simultaneous-Use; lost-Stop defect; Option G gate passed | Open rows plus pollers/live traffic/track | **REDESIGN** | Our own WP8 evidence proves open accounting rows alone are insufficient. Session Authority is justified by our defect and failure tests, not by SAS4. |
| Stale sessions | Report-only accepted behavior; Option G distinguishes live/gone without deleting history | Auxiliary pollers; no confirmed hard authority | **REDESIGN** | Age/freshness creates suspicion, not truth. Current-state liveness must be derived from explicit evidence. |
| Simultaneous-use | SQL count; Hotspot Port-Limit verified; PPPoE Port-Limit ineffective; stock checkrad can fail open | SQL count plus extra double-login controls | **REDESIGN** | Must be vendor/service aware and must also prove the concurrent-first-login race where two Access-Requests arrive before Accounting-Start. |
| Fail-open / fail-closed | New domain and Option G favor fail-closed on uncertainty | Mixed/partly unknown failure behavior | **KEEP OUR DESIGN** | Security/money uncertainty must not create access/value. Availability consequences must be monitored, not converted silently into fail-open. |
| CoA / Disconnect | Native RFC5176 client, authenticated result handling, real RouterOS evidence | Separate detector/queue/actuator concepts | **KEEP OUR DESIGN** | Keep current client and semantics. A durable queue/worker is a separate future reliability decision, not something to adopt simply because SAS4 has polling workers. |
| FUP / Quota | Period time/data quotas and expiry are implemented | Rich daily/total dimensions, transitions, carry-over/free-traffic features | **KEEP OUR DESIGN** | Keep current proven quota scope. Advanced FUP is **not yet an established product requirement**; evaluate independently if owner wants it. |
| Profiles / Plan transitions | Strong plan/pricing/activation lifecycle | Daily/quota next-profile pointers | **KEEP OUR DESIGN** | Do not add a runtime transition graph until a concrete FUP/service-policy requirement exists. If required, design independently from the business activation state machine. |
| NAS integration | SQL NAS registry, native CoA, real RouterOS evidence, exact NAS identity rules | Broader multi-channel registry | **REDESIGN** | WP8 itself proves capabilities differ by RouterOS service/version. Add only the minimal capability model required by evidence; do not clone SAS4's broad channel registry. |
| MikroTik | RouterOS 7.24.5 real evidence for AAA/accounting/CoA/session behavior | Broad older API/SSH/SNMP/HTTP integrations | **KEEP OUR DESIGN** | Current behavior is newer and empirically verified. Add channels only for a concrete requirement. |
| Resellers / RBAC | Closure tree + centralized capabilities/scopes + audit | Parent hierarchy + ACL/allow-lists | **KEEP OUR DESIGN** | Current model is already stronger and better isolated. |
| Billing | Ledger, reservations, pricing snapshots, idempotent settlement/reversal | Journals/invoices/receipts/balances | **KEEP OUR DESIGN** | Current financial correctness is a core strength. |
| Reliability | Strong transaction/UNKNOWN/reconciliation semantics; some network actions remain synchronous/best-effort | DB-polling workers and single-DB coupling | **REDESIGN** | Close proven release blockers first. Durable queues, HA and worker extraction are conditional on required durability/SLOs, not automatic architecture. |
| Security | Central authz, Message-Authenticator, parameterized new-domain SQL, audit/redaction; NAS secret debt remains | Several independently unsafe legacy patterns | **KEEP OUR DESIGN** | Retain our security baseline and harden remaining secrets/transport. Unsafe SAS4 patterns are rejected because of independent security reasons, not because they are SAS4. |
| Scalability | Modular monolith + FreeRADIUS + DB; no measured evidence yet requiring broker/cache/microservices | Single-host appliance + polling daemons | **KEEP OUR DESIGN** | Optimize only after load/lock/latency evidence. Premature distribution would add failure modes without proven need. |
| Multi-tenancy | Reseller hierarchy; no tenant root | Reseller/sites hierarchy, also not strong tenant isolation | **NOT APPLICABLE** | True multi-operator tenancy is a product-scope decision not yet locked by this audit. If owner confirms it, a separate end-to-end design is required. |
| Observability | Correlation IDs, sanitized logs, audit/events; limited runtime metrics | Operational tables/pollers | **REDESIGN** | Minimum production telemetry for AAA/session/NAS failures is justified by WP8 operations. Full distributed tracing/SLO machinery is conditional on deployment complexity. |

## Candidate Ideas Surfaced by SAS4 — Not Yet Architecture Decisions

These are questions worth considering, not approved adoptions:

1. Advanced daily/total FUP dimensions.
2. Runtime policy transitions after quota/daily limits.
3. Free/excluded traffic accounting.
4. Durable detector-to-actuator work queue.
5. Broader NAS management-channel inventory.
6. Multi-source telemetry enrichment.
7. IP-pool lifecycle automation beyond current requirements.

Each requires a product requirement plus independent design/test evidence before it can move into the target architecture.

## Independently Rejected SAS4 Patterns

The following are rejected because they conflict with security/reliability principles or our accepted invariants, not merely because SAS4 uses them:

| Pattern | Decision |
|---|---|
| Fork a native policy binary per Access-Request when no measured need exists | **SAS4 LEGACY — IGNORE** |
| Overlapping disconnect actors without single ownership/idempotency | **SAS4 LEGACY — IGNORE** |
| Shared shipped root private key | **SAS4 LEGACY — IGNORE** |
| Standing vendor reverse tunnel with shared/static credentials | **SAS4 LEGACY — IGNORE** |
| Arbitrary vendor remote-command channel | **SAS4 LEGACY — IGNORE** |
| Blanket sudo/root privilege for application services | **SAS4 LEGACY — IGNORE** |
| String-concatenated SQL for untrusted/runtime identifiers | **SAS4 LEGACY — IGNORE** |
| Plaintext secret sprawl with broad application access | **SAS4 LEGACY — IGNORE** |
| EOL platform stack as a new deployment baseline | **SAS4 LEGACY — IGNORE** |

Other SAS4 choices, such as DB-backed queues, single-host deployment, or no-FK domains, are **not automatically wrong in every context**. They are simply not accepted as our design without independent justification.

## Comparative Conclusion

After the first-principles review, SAS4 remains valuable primarily as a **question generator and behavioral reference**. It no longer supplies normative justification for FUP, queues, tenancy, caches, HA, or service extraction.

The mandatory architecture direction is driven by our own proven evidence:

- preserve the accepted business/finance/RBAC core;
- finish safe session admission and stale-session handling;
- close current WP8/release carry-forwards;
- add only the minimum NAS capability and operational telemetry needed to operate those mechanisms safely;
- place optional product/scale features behind explicit owner decision gates.


## Owner Decision Addendum — 2026-10-09

Ten architecture axes were owner-approved **independently of SAS4**. Its role remains a non-authoritative clean-room inventory. The selected deployment is **one isolated PHPNuxBill / FreeRADIUS instance per independent ISP (Option A)**, with existing reseller hierarchy inside each instance. Shared-DB tenant_id multi-tenancy is NOT selected. FUP next-profile transitions, distributed broker/Redis, HA clustering, per-request vendor policy services and any unsafe vendor patterns are not adopted by this comparison.

The comparison remains historically useful but is subordinate to `OWNER_ARCHITECTURE_LOCK.md`. No vendor code has been transferred into the target architecture by these documents.
