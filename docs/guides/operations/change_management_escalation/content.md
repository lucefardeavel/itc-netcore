# About this procedure

This procedure defines how changes to an ITC NetCore V1.0 instance are requested, approved, executed and recorded, how incidents are escalated, and who is responsible for what. It is derived from the runbooks' authorization rules (`docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` header, `docs/OPERATOR_RECOVERY_RUNBOOK.md`, `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` §K) and the project rule that no push, merge, tag, deploy or production action happens without explicit owner authorization.

> [!WARNING]
> Release Candidate, not yet production-accepted. **No support hours, response times, contacts or service levels are defined by this document.** They are set only in a signed support agreement (see the `templates_support_agreement` templates, which are blank).

# 1. Parties

| Party | Role |
|---|---|
| **ITC** | Develops and maintains ITC NetCore; builds release artifacts from the owner-accepted commit; publishes SHA-256 and release notes; third-level investigation of product defects |
| **ISP operator** | Runs the instance day to day: application administration, health and review queues, operator recovery decisions within its authority, scheduled jobs |
| **Network administrator** | NAS (RouterOS), FreeRADIUS host networking, firewalls, NTP, SNMP, CoA paths |
| **DBA** | Database hosts, accounts and grants, backups, restores, drills |
| **Authorized financial operator** | Decides on held funds and financial corrections (funding policy, adjustments, reversals, held reservations, legacy balance operations) |
| **Owner** | Release authorization, risk acceptance (for example A2-R C7), RTO / RPO values, acceptance ledger |

The ISP names the persons for each role in the support agreement. One person may hold several roles; the financial-operator role should not be held by the same person who approves their own corrections (**Recommended practice**).

# 2. Access levels

| Level | Means | Used for |
|---|---|---|
| Application — SuperAdmin | Web UI | Security settings (`radius_enable`, single session, session timeout, proxy), static pages, plugins, payment gateways |
| Application — Admin | Web UI | Customers, services, vouchers, pricing, reseller tree, finance corrections (review, then confirm), Operations Center actions |
| Application — Agent / Sales | Web UI | Own subtree / assigned customers only |
| Privileged — instance shell | SSH to the application host as the PHP user | `health.php`, `preflight.php`, `reconcile.php`, `quota.php`, `plugins.php`, `hierarchy.php`, cron |
| Privileged — FreeRADIUS host | SSH; `sudo -u freerad` | Site and module files, guard credential file, `nuxr_guard_selfcheck`, restarts |
| Privileged — database | Separate administrative accounts | Migration (DDL) account for `migrate.php`; backup account; account creation from `grants.php` output; per-operator accounts for admission-claim release |
| Privileged — NAS | RouterOS administration | `/radius`, `/radius incoming`, firewall, SNMP, NTP, sessions |

> [!SECURITY-WARNING]
> Privileged credentials are personal (no shared logins), never placed on command lines, in tickets or in chat. The application runtime account is least-privilege and cannot rewrite the ledger.

# 3. Responsibility matrix

R = responsible (does it) · A = accountable (approves) · C = consulted · I = informed. "App" = through the application; "Priv" = needs privileged access.

| Task | Access | ITC | ISP operator | Network admin | DBA | Financial operator | Owner |
|---|---|---|---|---|---|---|---|
| Build release artifact, publish SHA-256 and notes | — | R | I | I | I | — | A |
| Production deployment / upgrade | Priv | C | R | C | R | I | **A** |
| Schema migration (`migrate.php`) | Priv (DDL account) | C | R | — | R | — | A |
| Rollback decision | Priv | C | R | C | R | I | A |
| Nightly backups, off-site copy, retention | Priv | — | I | — | R/A | — | I |
| Restore / restore drill | Priv | C | C | — | R/A | I | I |
| Least-privilege accounts (`grants.php`) | Priv | C | R | — | R/A | — | — |
| FreeRADIUS configuration and restarts | Priv | C | R | R | — | — | — |
| NAS onboarding and removal | App + Priv | C | R | R/A | — | — | — |
| NAS secret / SNMP community rotation | App + Priv | — | R | R/A | — | — | I |
| `config.php` secret rotation | Priv | C | R/A | — | — | — | I |
| NTP on hosts and NAS | Priv | — | C | R/A | C | — | — |
| Health monitoring and alert routing | Priv | C | R/A | C | C | I | — |
| Release preflight after changes | Priv | C | R/A | C | C | — | — |
| Plans, bandwidth, vouchers | App | — | R/A | C | — | C | — |
| Base cost, resale switch, reseller pricing | App | — | R | — | — | A | — |
| Reseller onboarding and off-boarding | App | — | R | — | — | A | — |
| Provider funding, withdrawal, debt limit | App | — | R | — | — | **A** | — |
| Accounting adjustment, transfer reversal, activation reversal | App | — | R | — | — | **A** | — |
| Held activation: resume / cancel / reconcile / rollback | App or Priv | C | R | C | — | **A** | — |
| Legacy balance HELD: `balance-resolve` | Priv | — | R | — | — | **A** | — |
| Voucher review: `voucher-resolve` | Priv | — | R | — | — | A | — |
| PPPoE admission release (with NAS-side proof) | Priv | C | R | **C (provides proof)** | C | — | — |
| Plugin / payment-gateway approval | App (SuperAdmin) | C | R | — | — | C | **A** |
| Staff accounts and roles | App | — | R/A | — | — | I | — |
| Finance integrity incident (`finance-check` exit 4) | Priv | R | R | — | R (preserve evidence) | C | **I/A** |
| Security incident (suspected compromise) | Priv | C | R | R | R | I | A |
| Risk acceptance (A2-R C7), RTO / RPO values | — | C | C | — | — | — | **A** |
| Legal review (LIC-1) | — | C | — | — | — | — | A (with counsel) |

> [!FINANCE-WARNING]
> No role is authorized to repair financial data by direct database edits. The DBA's role in a finance incident is to preserve evidence, never to change rows.

<!-- pagebreak -->

# 4. Change classes

| Class | Examples | Approval | Window |
|---|---|---|---|
| **Standard** (pre-approved, low risk, documented procedure) | Add a plan, generate vouchers, onboard a reseller, add a Sales user, resolve a review-queue item per the runbook | ISP operator (and financial operator where money is involved) | Business hours |
| **Normal** | New NAS, NAS secret rotation, FreeRADIUS configuration change, plugin installation, cron change, `config.php` change | Change record approved by the accountable party in §3 | Agreed maintenance window |
| **Major** | Release upgrade, schema migration, restore-based rollback, database host move | Owner's explicit authorization + change record | Announced maintenance window |
| **Emergency** | Restore after data loss, rollback of a failing release, containment of a security incident | Fastest available accountable party; recorded retrospectively within one business day (**Recommended practice**) | Immediately |

# 5. Change record

| Field | Entry |
|---|---|
| Change ID / date | |
| Class | ☐ Standard ☐ Normal ☐ Major ☐ Emergency |
| Requested by / role | |
| Description and reason | |
| Instance and components affected | |
| Release SHA (if any) | |
| Pre-change backup: file and SHA-256 | |
| Runbook section followed | |
| Verification (commands and expected results) | |
| Rollback plan and rollback decision owner | |
| Approved by / date | |
| Executed by / date / result | |
| Post-change `preflight.php` / `health.php --finance` exit codes | |

# 6. Incident severity and escalation

Severity follows the product's own signals. Response and resolution times are **not** defined here; they belong to the support agreement.

| Severity | Typical trigger | First responder | Escalate to |
|---|---|---|---|
| S1 — critical | Health `db`, `schema` or `finance` CRITICAL; `finance-check` exit 4; database or FreeRADIUS down; suspected compromise | ISP operator | DBA / network admin at once; ITC; owner informed |
| S2 — major | Many subscribers cannot log in (guard ERROR, `admission_pending`, NAS unreachable); `quota_enforcer` CRITICAL; stale activations > 30 min | ISP operator | Network admin; financial operator for held funds; ITC if unresolved |
| S3 — minor | Single subscriber or reseller affected; a WARNING lasting > 30 minutes; review-queue items | Support / ISP operator | ISP operator / Admin |
| S4 — request | Questions, configuration help, documentation | Support | ISP operator |

**Escalation path:** Support (L1), then ISP operator / Admin (L2), then ITC engineering (L3). Network, database and financial decisions escalate in parallel to the respective accountable party (§3).

**Information to include when escalating to ITC:** instance name; release SHA; the exact health / preflight / reconcile output (secrets removed); error reference ids (`[nuxr] ref=…`); activation references; times in UTC; what was already done. **Never include** passwords, NAS secrets, SNMP communities, `config.php`, backups, voucher codes or customer personal data beyond what the agreement allows.

# 7. Post-incident review

For every S1 / S2 incident: timeline (UTC), root cause, what the product signals showed, actions taken (with runbook references), data reconciliation performed, secret rotation if exposure was possible, follow-up changes, and documentation updates.
