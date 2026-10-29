# About these templates

> [!WARNING]
> **EDITABLE TEMPLATES — NO VALUES FILLED IN.** Every field below is intentionally blank. Nothing in this document is an offer, a commitment, a price, a service level, a warranty or a contract term of ITC or of any ISP. The documents become binding only when the parties complete them, review them with their own legal counsel, and sign them.

> [!NOTE]
> ITC NetCore V1.0 is a Release Candidate, not yet production-accepted; the legal review LIC-1 (licensing, naming, data protection) is pending with counsel. Any agreement must take its outcome into account. The upstream PHPNuxBill community, donation and support channels belong to the original project, not to ITC.

How to use:
- Replace every `________` and tick every `☐` deliberately; delete rows that do not apply.
- Keep the template version and a change history with each completed copy.
- Do not copy the **provisional** engineering design targets (RTO 4 h / RPO 15 min) into an SLA as if they were approved values: they are not owner-approved SLAs.

| Template | Section |
|---|---|
| T1 Support agreement | 1 |
| T2 Service level agreement (SLA) | 2 |
| T3 Warranty statement | 3 |
| T4 Customer acceptance certificate | 4 |
| T5 Installation verification checklist | 5 |
| T6 Training completion checklist | 6 |

<!-- pagebreak -->

# 1. T1 — Support agreement (TEMPLATE)

| Field | Entry |
|---|---|
| Agreement number | ________ |
| Supplier (legal name, address, registration) | ________ |
| Customer / ISP (legal name, address, registration) | ________ |
| Product and version | ________ |
| Release SHA covered | ________ |
| Instance(s) covered (name, host, location) | ________ |
| Effective date / term / renewal | ________ / ________ / ________ |

**1.1 Scope of support**

| Item | Included? | Notes |
|---|---|---|
| Product defect investigation | ☐ yes ☐ no | ________ |
| Release upgrades | ☐ yes ☐ no | ________ |
| Security fixes | ☐ yes ☐ no | ________ |
| Operational assistance (runbooks) | ☐ yes ☐ no | ________ |
| Installation services | ☐ yes ☐ no | ________ |
| NAS / network configuration assistance | ☐ yes ☐ no | ________ |
| Database administration | ☐ yes ☐ no | ________ |
| Data migration from another system | ☐ yes ☐ no | ________ |
| Custom development / plugins | ☐ yes ☐ no | ________ |
| Training | ☐ yes ☐ no | ________ |

**1.2 Exclusions:** ________

**1.3 Responsibilities of the Customer** (for example: named operators, privileged access, backups, NAS administration, monitoring): ________

**1.4 Contacts**

| Role | Name | Phone | Email | Hours |
|---|---|---|---|---|
| Supplier support desk | ________ | ________ | ________ | ________ |
| Supplier escalation | ________ | ________ | ________ | ________ |
| Customer ISP operator | ________ | ________ | ________ | ________ |
| Customer network administrator | ________ | ________ | ________ | ________ |
| Customer DBA | ________ | ________ | ________ | ________ |
| Customer authorized financial operator | ________ | ________ | ________ | ________ |
| Customer security contact | ________ | ________ | ________ | ________ |

**1.5 Access arrangements** (remote access method, approval per session, logging): ________

**1.6 Data protection and confidentiality** (personal data, backups, secrets, retention, sub-processors): ________

**1.7 Fees, invoicing and payment terms:** ________

**1.8 Liability, termination, governing law, dispute resolution:** ________

**1.9 Signatures**

| Party | Name | Title | Date | Signature |
|---|---|---|---|---|
| Supplier | ________ | ________ | ________ | ________ |
| Customer | ________ | ________ | ________ | ________ |

<!-- pagebreak -->

# 2. T2 — Service level agreement (TEMPLATE)

**2.1 Service hours:** ________ (time zone: ________)

**2.2 Severity definitions and targets**

| Severity | Definition (example triggers, see change-management procedure §6) | Response target | Update interval | Resolution / workaround target |
|---|---|---|---|---|
| S1 — critical | ________ | ________ | ________ | ________ |
| S2 — major | ________ | ________ | ________ | ________ |
| S3 — minor | ________ | ________ | ________ | ________ |
| S4 — request | ________ | ________ | ________ | ________ |

**2.3 Recovery objectives (owner-approved values only)**

| Field | Entry |
|---|---|
| Recovery option (1 daily backups / 2 + binlog PITR / 3 + replica) | ________ |
| RTO | ________ |
| RPO | ________ |
| Backup retention / off-site location | ________ / ________ |
| Restore drill cadence | ________ |
| Owner approval reference | ________ |

**2.4 Availability measurement** (what is measured, how, exclusions, maintenance windows): ________

**2.5 Reporting** (frequency, content): ________

**2.6 Service credits / remedies:** ________

**2.7 Customer dependencies** (for example: NTP, NAS configuration per the NAS contract, monitoring of health checks, timely operator decisions on held items): ________

**2.8 Signatures:** Supplier ________ · Customer ________ · Date ________

<!-- pagebreak -->

# 3. T3 — Warranty statement (TEMPLATE)

| Field | Entry |
|---|---|
| Product / version / release SHA | ________ |
| Warranty period start / end | ________ / ________ |
| What is warranted | ________ |
| Conditions (supported platform, unmodified code, no unreviewed plugins, configuration per the production checklist, …) | ________ |
| Exclusions (third-party components, NAS hardware, customer modifications, …) | ________ |
| Remedy (correction, workaround, replacement, …) | ________ |
| How to make a claim | ________ |
| Licence terms that apply (subject to the LIC-1 legal review) | ________ |
| Limitation of liability | ________ |
| Signatures | Supplier ________ · Customer ________ · Date ________ |

<!-- pagebreak -->

# 4. T4 — Customer acceptance certificate (TEMPLATE)

| Field | Entry |
|---|---|
| Certificate number | ________ |
| Customer / ISP | ________ |
| Instance | ________ |
| Product / version / release SHA | ________ |
| Installation verification checklist (T5) reference | ________ |
| Target acceptance results (TA-01 … TA-10) reference | ________ |
| Training completion (T6) reference | ________ |

**Acceptance criteria**

| # | Criterion | Result | Evidence reference |
|---|---|---|---|
| 1 | ________ | ☐ pass ☐ fail | ________ |
| 2 | ________ | ☐ pass ☐ fail | ________ |
| 3 | ________ | ☐ pass ☐ fail | ________ |
| 4 | ________ | ☐ pass ☐ fail | ________ |

**Open items / accepted limitations**

| # | Item | Accepted by | Due date |
|---|---|---|---|
| 1 | ________ | ________ | ________ |
| 2 | ________ | ________ | ________ |

**Decision:** ☐ Accepted ☐ Accepted with the open items above ☐ Not accepted

| Party | Name | Title | Date | Signature |
|---|---|---|---|---|
| Customer | ________ | ________ | ________ | ________ |
| Supplier | ________ | ________ | ________ | ________ |

<!-- pagebreak -->

# 5. T5 — Installation verification checklist (TEMPLATE)

| Field | Entry |
|---|---|
| Instance | ________ |
| Release SHA / artifact SHA-256 verified | ________ / ☐ |
| Installed by / date | ________ / ________ |
| Verified by / date | ________ / ________ |

| # | Check (from the production configuration checklist) | Result | Evidence reference | Initials |
|---|---|---|---|---|
| 1 | Platform: PHP 8.2 and extensions; supported database engine | ☐ | ________ | ____ |
| 2 | Database isolation REPEATABLE-READ; UTC; MariaDB CHECK enforcement | ☐ | ________ | ____ |
| 3 | NTP on all hosts and every NAS | ☐ | ________ | ____ |
| 4 | HTTPS, redirects, headers, protected paths, `install/` removed, updater 403 | ☐ | ________ | ____ |
| 5 | `config.php` secrets set, mode 0640, stage `Live`, legacy devices off | ☐ | ________ | ____ |
| 6 | `migrate.php preflight` / `migrate` / `verify` exit 0 | ☐ | ________ | ____ |
| 7 | RADIUS SQL loaded; admission checks READY | ☐ | ________ | ____ |
| 8 | Least-privilege application and FreeRADIUS accounts; separate migration account | ☐ | ________ | ____ |
| 9 | FreeRADIUS configuration, guard installed, `guard_selfcheck=OK` | ☐ | ________ | ____ |
| 10 | Each NAS onboarded (type `mikrotik_snmp`): test login, accounting, Disconnect | ☐ | ________ | ____ |
| 11 | Default `admin` password changed; `CompanyName` set | ☐ | ________ | ____ |
| 12 | Release `preflight.php` exit 0 | ☐ | ________ | ____ |
| 13 | `health.php --finance` exit 0 | ☐ | ________ | ____ |
| 14 | Scheduled jobs installed | ☐ | ________ | ____ |
| 15 | First backup taken and restore drill done | ☐ | ________ | ____ |
| 16 | Monitoring receives health exit codes / JSON | ☐ | ________ | ____ |
| 17 | ________ | ☐ | ________ | ____ |

Remarks: ________

<!-- pagebreak -->

# 6. T6 — Training completion checklist (TEMPLATE)

| Field | Entry |
|---|---|
| Trainee name / role | ________ / ________ |
| Trainer name / organisation | ________ / ________ |
| Instance / environment used for training | ________ |
| Dates | ________ |

| # | Module | Reference document | Completed | Trainer initials | Trainee initials |
|---|---|---|---|---|---|
| 1 | Product overview, roles and scopes | `admin_quickstart`, `known_limitations` | ☐ | ____ | ____ |
| 2 | Daily / weekly / monthly checks | `operator_daily_checklist` | ☐ | ____ | ____ |
| 3 | Reseller onboarding, funding, pricing | `reseller_onboarding` | ☐ | ____ | ____ |
| 4 | Subscriber onboarding, activation, vouchers, portal | `subscriber_onboarding` | ☐ | ____ | ____ |
| 5 | Held activations and reservations (resume / cancel / reconcile / rollback) | `financial_reconciliation_guide`; recovery runbook §1 | ☐ | ____ | ____ |
| 6 | PPPoE admission claims: release only with NAS-side proof; no automatic release | Recovery runbook §3 | ☐ | ____ | ____ |
| 7 | Vouchers under review; legacy balance HELD | Recovery runbook §2, §5 | ☐ | ____ | ____ |
| 8 | Monitoring and alerting | `monitoring_alerting_guide` | ☐ | ____ | ____ |
| 9 | Incident handling | `incident_troubleshooting_guide` | ☐ | ____ | ____ |
| 10 | Backup, restore, drills | `backup_restore_guide` | ☐ | ____ | ____ |
| 11 | Disaster recovery scenarios | `disaster_recovery_guide` | ☐ | ____ | ____ |
| 12 | Upgrade and rollback | `upgrade_rollback_guide` | ☐ | ____ | ____ |
| 13 | Security hardening and secret rotation | `security_hardening_checklist` | ☐ | ____ | ____ |
| 14 | Change management and escalation | `change_management_escalation` | ☐ | ____ | ____ |
| 15 | Prohibition of direct database edits for financial repair | `financial_reconciliation_guide` §9 | ☐ | ____ | ____ |
| 16 | ________ | ________ | ☐ | ____ | ____ |

Assessment method / result: ________

| Party | Name | Date | Signature |
|---|---|---|---|
| Trainee | ________ | ________ | ________ |
| Trainer | ________ | ________ | ________ |
| ISP manager | ________ | ________ | ________ |
