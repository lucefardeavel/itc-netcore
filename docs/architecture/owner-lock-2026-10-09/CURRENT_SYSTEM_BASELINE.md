# CURRENT_SYSTEM_BASELINE

## Audit Metadata

- Audit: **SaaS RADIUS vs SAS4 Reference Intelligence - Comparative Architecture Audit**
- Date: **2026-10-07**
- Mode: **planning and architecture only**
- Code changes: **none**
- GitHub mutations: **none**
- Product repository: `lucefardeavel/phpnuxbill-reseller` (PRIVATE)
- Evidence branch: `wp8/integration-poc`
- Evidence HEAD: `47a3a02cb0d469d324393515186f2d1497641b31`
- Accepted integration branch before WP8: `feat/reseller-system-v1`
- Accepted integration HEAD: `5bdc57459a64dd53e32e5abb2ef6efa3815e387a`
- Product `main`: `500d3a2d15003efeed6e0aad639c13d08101c3b1`
- SAS4 frozen reference: `lucefardeavel/sas4-reference-intelligence@76a4f7ae83f5fc50eaf0c7c9c440c35450cdba65`
- Change gate: **no implementation before owner review and decision lock**.
- Revision: **First-Principles Review 2 — 2026-10-07**.

### Evidence states used in this audit

- **ACCEPTED**: present in an owner-accepted WP or hotfix.
- **WP8 PROVEN CANDIDATE**: demonstrated against real FreeRADIUS 3.2.8 and RouterOS CHR 7.24.5, but not yet permanent production configuration.
- **TARGET / PROPOSED**: architecture proposed by this audit only.

### First-Principles Review Rule

SAS4 is a non-authoritative clean-room reference. A SAS4 feature does not become a requirement, gap, or target merely because SAS4 implements it. Decisions are grounded first in owner/product requirements, standards/security/data-integrity principles, the current repository, and empirical NAS/FreeRADIUS evidence.

Independent anchors used in the revised review include RFC 2866, RFC 5176, FreeRADIUS 3.2 Simultaneous-Use guidance, MikroTik RouterOS RADIUS documentation, and our WP8 real-CHR results.

## 1. Executive Baseline

The current product is an evolved **modular monolith** built around PHPNuxBill, with separately authored reseller/domain layers for authorization, finance, pricing, activation orchestration, RADIUS provisioning, audit and adaptive staff UX.

The accepted architecture through WP7 is substantial. WP8 has proven real FreeRADIUS/MikroTik behavior in an isolated CHR lab. However, WP8 is still **PENDING**, and its newest session-safety mechanism, Option G, is still a lab-proven candidate under `dev/wp8/`, not permanent `install/freeradius/` configuration.

The correct starting point for future architecture is therefore **evolution, not rewrite**.

## 2. Repository and Acceptance State

| Item | Current state |
|---|---|
| Repository | `lucefardeavel/phpnuxbill-reseller` |
| Visibility | PRIVATE |
| `main` | `500d3a2d15003efeed6e0aad639c13d08101c3b1` |
| Accepted `feat/reseller-system-v1` | `5bdc57459a64dd53e32e5abb2ef6efa3815e387a` |
| Active `wp8/integration-poc` | `47a3a02cb0d469d324393515186f2d1497641b31` |
| WP8 delta | 12 commits ahead of accepted `feat`, 0 behind |
| WP0 | ACCEPTED_WITH_NOTES |
| WP1 | ACCEPTED_WITH_NOTES |
| WP2 | ACCEPTED_WITH_NOTES |
| WP3A | ACCEPTED_WITH_NOTES |
| WP3B | ACCEPTED |
| WP4 | ACCEPTED_WITH_NOTES |
| WP5A | ACCEPTED_WITH_NOTES |
| WP5B | ACCEPTED_WITH_NOTES |
| WP6A | ACCEPTED_WITH_NOTE |
| WP6B | ACCEPTED_WITH_NOTES |
| WP6C | ACCEPTED_WITH_NOTES |
| WP7 | ACCEPTED_WITH_NOTES |
| Pre-WP8 Pace/XHR CSRF hotfix | ACCEPTED_WITH_NOTES |
| WP8 | PENDING |
| WP9 | PENDING |
| WP10 | PENDING |

Primary evidence: `docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md`.

## 3. Runtime Shape

### Application plane

The reseller/domain architecture is organized under:

- `system/autoload/Reseller/Security/`
- `system/autoload/Reseller/Domain/`
- `system/autoload/Reseller/Finance/`
- `system/autoload/Reseller/Activation/`
- `system/autoload/Reseller/Radius/`
- `system/autoload/Reseller/Audit/`
- `system/autoload/Reseller/Backoffice/`
- `system/autoload/Reseller/Ui/`

The system is still deployed as a PHP application, but these boundaries are real enough to support selective extraction later.

### New-domain schema

Current project migrations are R0001-R0007:

1. `R0001_manager_tree`
2. `R0002_pricing_foundation`
3. `R0003_activation_foundation`
4. `R0004_wallet_finance_foundation`
5. `R0005_customer_ownership`
6. `R0006_reseller_audit`
7. `R0007_activation_reversal_states`

No current migration introduces a tenant root, `tenant_id`, an outbox, a broker queue or a dedicated session store.

The new domain uses real constraints/FKs, strict migration contracts, schema readiness, UTC service-written timestamps and transactional consistency. This is materially stronger than SAS4's app-enforced no-FK schema.

## 4. Authentication Baseline

### ACCEPTED

Current project RADIUS design uses:

- FreeRADIUS 3.2.8 in validated test/integration environments
- SQL-backed policy
- PAP and CHAP for current service scope
- expiration/logintime
- project quota counters
- Simultaneous-Use
- managed `radcheck`, `radreply`, `radusergroup`, `radgroupreply`
- `RadiusRepository` as the single new-domain policy writer
- prepared SQL, transactions, idempotent sync and read-back verification
- `RadiusProvisioner` result taxonomy: SUCCESS / FAILED / UNKNOWN

Core policy attributes include:

- `Cleartext-Password`
- `Simultaneous-Use`
- `Expiration`
- `Max-All-Session`
- `Max-Data`
- `Nuxr-Period-Start`
- `Mikrotik-Rate-Limit`
- `Framed-IP-Address` or `Framed-Pool`
- `WISPr-Session-Terminate-Time`
- `plan_<id>` group membership

PPPoE uses `pppoe_username` where present. Hotspot/VPN use username.

### WP8 hardening

WP8 proved that SQL-loaded NAS clients do not automatically inherit the intended Message-Authenticator requirement in the pinned runtime. The project virtual server therefore enforces Message-Authenticator first in `authorize`.

- Missing Message-Authenticator: no response.
- Wrong Message-Authenticator: dropped during packet verification.
- Valid request proceeds to auth policy.

This is a strong product-specific design. There is no reason to adopt SAS4's fork-per-auth `sas_auth` model.

## 5. Human Authorization and RBAC

The current product has a centralized authority stack:

- `ActorContext`
- `Capability`
- `RoleCapabilityMap`
- `PermissionService`
- `DataScope`
- `RouteMap`
- `RouteGuard`
- `CsrfPolicy`
- `ApiGuard`
- `ApiToken`
- `PluginRegistry`
- `RestoreGuard`

Important behavior:

- deny by default
- unmapped route -> 404
- method mismatch -> 405
- schema-dependent capabilities fail closed
- inconsistent/frozen actors lose mutation authority
- un-enrolled tree roles have empty tree scope
- backend data scope is independent of UI visibility
- API auth is actor-bound and fails closed without a dedicated secret

This is stronger than SAS4's web-centric ACL model.

## 6. Reseller Hierarchy

The product uses `tbl_manager_tree` as a closure table with:

- ancestor/subtree queries
- atomic placement and subtree moves
- actor revalidation under transaction
- compatibility with legacy `root`
- audit
- Agent/Sales structural rules
- frozen-ancestor handling
- global tree serialization for hierarchy-dependent mutations

This is **reseller hierarchy**, not true tenant isolation.

## 7. Financial Baseline

Finance is one of the strongest current domains.

`tbl_manager_ledger` is the financial source of truth. `LedgerService` is the single application writer for ledger/balance changes.

Key properties:

- append-only application API
- compensating reversals
- deterministic entry keys
- idempotency conflict detection
- reservations before settlement
- exact money semantics
- `FOR UPDATE` locking
- explicit lock ordering
- bounded deadlock retry
- no external network call inside finance transactions
- audit records actor/reason without copying secrets or ledger contents

Pricing separates provider base cost, reseller wholesale, customer retail, availability inheritance, pricing provenance and activation snapshots.

Do not replace this with SAS4's billing structure.

## 8. Activation and Lifecycle

The activation domain is a persistent saga/state machine.

Accepted behaviors include:

- server-derived NEW / RENEW / CHANGE_PLAN / REACTIVATE
- sealed pricing/service-period decisions
- idempotency key and request fingerprint
- one in-flight activation per customer
- reservation before network provisioning
- network provisioning outside DB transactions
- SUCCESS / FAILED / UNKNOWN external classification
- RECONCILIATION_REQUIRED on uncertainty
- exact-state reconciliation
- rollback only with provable prior state
- completed activation reversal with financial compensation
- closure/bounding of legacy service-grant paths for reseller-owned customers

This should remain the orchestration core.

## 9. Accounting

`radacct` is the current authoritative FreeRADIUS accounting history/projection for this product.

`AccountingService` is read-only and exposes:

- open sessions
- history
- usage totals
- stale candidates
- last session
- open-session lookup by IP

Important correction from the first audit revision: `radacct` is **not literally immutable**. FreeRADIUS legitimately updates accounting rows as Interim/Stop information arrives. The binding rule is that application/business code does not destructively delete or rewrite `radacct` to repair session admission or reset quota.

The separate application table `rad_acct` is legacy/rlm_rest per-session counter state and is explicitly non-authoritative.

Current quota counters sum retained history only within `Nuxr-Period-Start`, so renewal starts a new quota period without destroying history.

## 10. Sessions and Simultaneous-Use

### Accepted behavior before WP8

- `session { sql }` uses open `radacct` rows.
- Simultaneous-Use: PPPoE = 1; Hotspot/VPN = `shared_users`.
- stale rows are report-only.
- no accepted generic stale-row cleanup mutates accounting history.

### Proven S6 defect

If Accounting-Stop is lost while the session disappears from RouterOS:

- the `radacct` row remains open
- Simultaneous-Use still counts it
- subscriber can be locked out indefinitely
- RouterOS did not resend the lost Stop in the reproduced case
- NAS restart/Accounting-On is too disruptive as normal recovery

### Rejected Option E

Freshness-bounded Simultaneous-Use + NAS Port-Limit was rejected because RouterOS 7.24.5 enforces Port-Limit for Hotspot but ignores it for PPPoE.

### Rejected Option F

Stock FreeRADIUS `checkrad` with `mikrotik_snmp` was rejected because verification failures can fail open and admit a duplicate PPPoE session.

### WP8 PROVEN CANDIDATE: Option G

Current HEAD proves a project-owned fail-closed PPPoE post-auth verifier.

Behavioral contract:

- PPPoE only
- no open row -> no NAS query
- open rows -> verify all involved NAS devices
- SAFE only when every required NAS is healthy and live count is below the limit
- LIVE -> reject
- ERROR -> reject
- timeout/tool missing/wrong credential/unreachable/malformed input -> reject
- lost Stop + actual session gone -> allow without editing stale history
- accounting lost >190s + actual session live -> reject duplicate
- multi-NAS mixed states remain fail-closed
- hostile usernames are handled byte-exactly without shell interpretation

Important operational constraint: RouterOS RADIUS timeout must be >=3 seconds for this verification path. Default 1100 ms is too tight for the observed failure path.

Candidate files:

- `dev/wp8/freeradius/option-g/nuxr_pppoe_session_guard`
- `dev/wp8/freeradius/option-g/post-auth.unlang`

This is **not yet permanent `install/freeradius` configuration**.

## 11. CoA / Disconnect

The project uses a native RFC5176 client rather than shelling to `radclient`.

Properties:

- one Disconnect per open accounting session
- NAS matched from the session NAS address
- User-Name, Acct-Session-Id, Framed-IP-Address and NAS-IP-Address included
- Message-Authenticator
- strict response authenticator/identifier checks
- identical retransmission on timeout
- ACK -> SUCCESS
- NAK -> FAILED
- no valid response -> UNKNOWN
- secret does not enter process argv/logs

WP8 real RouterOS evidence:

- `Mikrotik-Rate-Limit` CoA works
- Framed-Pool / Framed-IP may be ACKed but ignored
- IP/pool change must therefore use policy update + Disconnect + re-authentication
- missing-session response 406 remains FAILED/COA_NAK by owner decision
- CoA source IP must be a RouterOS-configured RADIUS server address

## 12. FUP and Quota

Current product supports:

- period-scoped time quota
- period-scoped combined data quota
- expiry
- RouterOS rate policy
- history-preserving quota periods

Not yet a generalized subsystem:

- daily upload/download/combined/uptime caps
- independent total upload/download caps
- free/excluded usage
- quota-driven next-plan transitions
- daily-limit next-plan transitions
- centralized mid-session breach detector
- monthly/carry-over policy model

## 12a. Internal Customer Identity vs RADIUS Identity

The current domain already uses `tbl_customers.id` as the internal customer identity in ownership, activation and audit flows. The rename defect does **not** prove that a new global `subscriber_id` is required. The actual architectural need is to preserve/map the exact RADIUS identity used by each relevant session back to the existing customer id across username/PPPoE-username changes. A new UUID-style subscriber identifier remains conditional on future tenancy/federation requirements.

## 13. NAS and MikroTik

Strengths:

- SQL-loaded NAS clients
- exact session/NAS identity rules
- native CoA
- real RouterOS CHR 7.24.5 evidence
- RouterOS >=7.15 hardening floor
- private/isolated management paths in WP8

Debt:

- RADIUS shared secret remains plaintext in `nas`
- Option G needs an SNMP community credential
- RouterOS API 8728 is plain TCP, accepted only on private tunnel/lab paths
- NAS capabilities are not yet modeled as an explicit vendor/version capability set

## 14. Reliability

Existing strengths:

- strict schema readiness
- transactional/idempotent RADIUS writes
- read-back verification
- UNKNOWN external result classification
- activation reconciliation
- compensating reversals
- financial locking/concurrency testing
- WP8 failure drills

Current evidence-backed reliability work:

- productionize logical Session Authority;
- close stale-session and simultaneous-use failure modes;
- define verifier dependency health/deadline behavior;
- add minimum operational visibility.

Potential future mechanisms such as a durable queue/outbox, active DB HA, or a fast session cache are **conditional architecture choices**, not present-day defects without a durability/SLO/load requirement.

## 15. Security

### Strong controls

- parameterized SQL in new domain
- central deny-by-default authorization
- CSRF enforcement
- actor-bound API tokens
- fail-closed readiness
- request correlation IDs
- sanitized logs/operator notifications
- append-only redacted audit
- native CoA packet construction
- mandatory Message-Authenticator
- no vendor backdoor/tunnel architecture

### Open debt

- plaintext NAS/RADIUS secret at rest
- future SNMP secret handling
- API-token max age is long relative to modern access/refresh patterns
- private-only but non-TLS RouterOS management path
- legacy/plugin residuals reserved for WP9/WP10
- mutable username/pppoe_username still important across systems

## 16. Scalability

The product has useful internal seams. There is currently no evidence that it must become a distributed control plane before release. Scale changes should be measurement-driven.

Current characteristics to measure rather than automatically redesign:

- shared relational DB is central;
- global tree lock serializes hierarchy-dependent writes;
- some network work is synchronous/operator reconciled;
- no separate event backbone/session cache/read replica exists;
- these are not defects without durability/load/SLO evidence.

The proven near-term operational gap is minimum AAA/session/NAS telemetry for the new fail-closed session path.

## 17. Multi-Tenancy

Current model: **single installation + hierarchical reseller ownership**.

This is a description, not automatically a defect. True multi-tenancy becomes a requirement only if the owner confirms independent operators must share one isolated deployment. The current model does not provide true tenant isolation because there is no:

- tenant root
- tenant partition key
- tenant-owned NAS/secrets boundary
- tenant-aware RADIUS namespace/routing
- tenant-aware uniqueness contract
- tenant-scoped finance/audit/session architecture

## 18. Observability

Existing:

- request correlation ID
- sanitized error logs
- Telegram summary
- append-only audit
- activation event timeline
- financial ledger
- extensive test/evidence artifacts

Missing as a production platform:

- structured log pipeline
- metrics
- tracing
- AAA latency/reason dashboards
- accounting freshness metrics
- session-verifier verdict metrics
- CoA outcome metrics
- queue/DLQ metrics
- tenant/reseller SLOs
- session drift and NAS-health alerts

## 19. Current Carry-Forwards

| ID | State | Production impact |
|---|---|---|
| `R-WP8-CARRY-01` | direct removal/deactivation bypass | Must close before production |
| `R-WP8-CARRY-02` | old RADIUS session survives username reprovision | Must close before production |
| `R-WP8-CARRY-03` | stale-session timezone mismatch | Must close before production |
| `R-HF-CARRY-01` | legacy autoload router / plan metadata authorization exposure | Release closure required |
| `OD-W8-16` | Option G gate passed, permanent architecture not integrated | WP8 remains pending |
| WP8 S7/S8 | not started | WP8 incomplete |

## 20. Maturity Summary

| Domain | Maturity |
|---|---|
| Human RBAC / data scoping | Strong |
| Finance / ledger | Strong |
| Pricing | Strong |
| Activation saga / reconciliation | Strong |
| RADIUS provisioning | Strong |
| Real MikroTik integration evidence | Strong for PPPoE/Hotspot scope |
| Accounting history integrity | Strong |
| Session truth / stale recovery | In transition, WP8 candidate |
| Simultaneous-use | In transition |
| FUP / advanced quota policy | Basic-to-moderate |
| NAS capability abstraction | Moderate |
| Secrets management | Needs hardening |
| Reliability / async enforcement | Core correctness strong; async durability conditional |
| Scalability | Measure before redesign |
| True multi-tenancy | Not present; requirement not yet locked |
| Production observability | Needs redesign |

## 21. Baseline Conclusion

Keep the accepted business core. Concentrate future architecture on:

1. session authority and reconciliation;
2. simultaneous-use enforcement, including the concurrent-first-login gate;
3. NAS capability/secret handling required by the selected session solution;
4. mapping mutable RADIUS identities/sessions to the existing internal customer id;
5. minimum operational observability for the fail-closed path;
6. current release carry-forward closure.

Advanced FUP, durable async enforcement, true tenancy, caches, brokers and HA/scale-out remain separate conditional programs until owner requirements or measurements justify them.


## 22. Post-Baseline Owner Architecture Lock (2026-10-09)

**This is an owner decision addendum, not evidence that runtime code changed since the snapshot above.** The ten reviewed architectural axes are approved in `OWNER_ARCHITECTURE_LOCK.md` and `ARCHITECTURE_DECISIONS.md`.

- Product deployment choice: **Option A, isolated ISP instances with one common versioned codebase**, not shared database `tenant_id` multi-tenancy. Existing reseller hierarchy is intra-instance only.
- Keep current commercial activation/finance and FreeRADIUS protocol edge. Add verified Session Authority/Option G behavior, minimum NAS capability and secret/operational boundaries as bounded release work.
- Correctness gates remain open: concurrent-first-login, Q1–Q4 quotas, P1–P3 transitions, N1–N7 NAS, REL-01–REL-05 recovery and measured capacity. HA REL-06 is conditional.
- Do not overstate baseline: WP8 remains pending acceptance, WP9/WP10 are not accepted, and the owner lock **does not** assert a production-ready build.
