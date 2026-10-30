# ITC NetCore V1.0 — operations documentation: sources and contradictions

**Date:** 2026-10-29 · **Status:** Release Candidate — not yet production-accepted · **Source tree:** `SRC` = `\\wsl.localhost\Ubuntu-24.04\srv\itcnc\src\final`, branch `release/itc-netcore-v1-final-prep`, HEAD `e02d784b` (documentation-only commit on top of the lab-accepted candidate `a6b02eba`). SRC was read only; nothing in it was changed.

**Prefixes:** `SRC:` product tree · `LAB:` `C:\Users\user\Desktop\ITC-NetCore-Lab\` · `G:` `LAB:reports/handover/guides/` (drafts at `6b2e5baa`, used only after re-verification against SRC) · `CONS` = `LAB:reports/OWNER_DECISION_CONSOLIDATION_20261029.md` · `FRR` = `LAB:reports/FINAL_RELEASE_REPORT_20261029.md` · `RBAC` = `LAB:reports/RBAC_MATRIX.md` (static audit at `c9ea0be0`).

**Directive inputs applied (owner / requester instructions, 2026-10-29):** first installation = fresh install with an empty database, migration a separate future path; RTO 4 h / RPO 15 min = provisional engineering design targets, not owner-approved SLAs; HELD financial reservations never released automatically (audited resume / cancel only); A2-R = "MITIGATED — EFFECT UNVERIFIED"; no direct database edits for financial repair.

## 1. Deliverables

| # | Folder | Edition(s) | Audience |
|---|---|---|---|
| 1 | `admin_quickstart` | EN, AR (`admin_quickstart_ar`) | ISP administrators |
| 2 | `operator_daily_checklist` | EN, AR (`operator_daily_checklist_ar`) | Instance operators |
| 3 | `reseller_onboarding` | EN, AR (`reseller_onboarding_ar`) | Admins, resellers |
| 4 | `subscriber_onboarding` | EN, AR (`subscriber_onboarding_ar`) | Admins, resellers, Sales, support |
| 5 | `backup_restore_guide` | EN | Operators, DBAs |
| 6 | `disaster_recovery_guide` | EN | Operators, DBAs, network admins, owner |
| 7 | `financial_reconciliation_guide` | EN | Authorized financial operators, Admins |
| 8 | `monitoring_alerting_guide` | EN | Operators, monitoring teams |
| 9 | `incident_troubleshooting_guide` | EN | Support, operators |
| 10 | `upgrade_rollback_guide` | EN | Operators, DBAs |
| 11 | `security_hardening_checklist` | EN | Operators, security, network admins |
| 12 | `known_limitations` | EN, AR (`known_limitations_ar`) | All stakeholders |
| 13 | `change_management_escalation` | EN | ITC, ISP management, all operational roles |
| 14 | `templates_support_agreement` | EN (blank templates) | ITC and ISP contract owners |

Each folder holds `meta.json` and `content.md`. Arabic editions translate the English content one to one (technical terms in Latin script); their source mapping is the same as the English edition's.

## 2. Section-to-source map

### 2.1 `admin_quickstart` (and `_ar`)

| Section | Sources |
|---|---|
| About / status | `SRC:README.md` (Status); `FRR` verdict, §6, §7, §13; directive (fresh install) |
| 1 Prerequisites | `SRC:README.md` Requirements; `SRC:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` §A; `SRC:docs/TIME_CONTRACT.md` §1; `SRC:install/freeradius/README.md` "PPPoE session guard / Install" (3.2.8 only) |
| 2 Installation sequence | `SRC:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §1, §2 steps 1–13; `SRC:system/reseller/preflight.php` header (`--setup`, exit 4); `SRC:system/reseller/grants.php` header |
| 3 First login | `SRC:admin/index.php` (redirect); `SRC:docs/WP2_PERMISSION_MODEL.md` §15 step 4; runbook §2 step 11 |
| 4 Initial configuration | `G:SUPERADMIN_GUIDE.md` §3 re-verified: `SRC:system/controllers/settings.php` (`$nuxrSuperAdminOnly` incl. `radius_enable`), `SRC:docs/BRAND_IDENTITY.md` §2 (`CompanyName`), `SRC:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2, `SRC:docs/WP5A_ACTIVATION_MODEL.md` §7, `SRC:docs/WP3B_PRICING_MODEL.md` §2, `SRC:docs/WP2_PERMISSION_MODEL.md` §6 |
| 5 Roles | `SRC:system/autoload/Reseller/Security/RoleCapabilityMap.php` |
| 6 Scheduled jobs | Checklist §I; `SRC:install/freeradius/README.md` "Readiness check" |
| 7 Go-live gate | Checklist §K; `CONS` §6 (B-8 `CompanyName`) |
| 8 Never do | `SRC:docs/OPERATOR_RECOVERY_RUNBOOK.md` §8; `SRC:docs/WP2_PERMISSION_MODEL.md` §12; `ReleaseProfile` (via preflight `profile`) |

### 2.2 `operator_daily_checklist` (and `_ar`)

| Section | Sources |
|---|---|
| 1.1 Platform health | `SRC:system/reseller/health.php` (checks and thresholds) |
| 1.2 Held money and queues | `SRC:docs/OPERATOR_RECOVERY_RUNBOOK.md` §0–§5; `SRC:system/reseller/reconcile.php` usage; `SRC:install/freeradius/nuxr_hs_admission_report.sql` |
| 1.3 Backups | `SRC:docs/BACKUP_RESTORE_RUNBOOK.md` §1, §2 |
| 1.4 NAS, guard, clocks | `SRC:install/freeradius/README.md` "Readiness check"; `SRC:docs/TIME_CONTRACT.md` §1, §5; recovery runbook §4c |
| 1.5 Logs | `SRC:docs/WP2_PERMISSION_MODEL.md` §10 (`[nuxr] ref=`); `CONS` §3 C4; recovery runbook §7 |
| 2 Weekly | `SRC:system/reseller/preflight.php`; `SRC:system/reseller/plugins.php`; checklist A8, F3; `reconcile.php expired-autorenew` |
| 3 Finance incident | Recovery runbook §0 ("finance-check exit 4 is a security incident") |
| 4–5 Monthly / quarterly / yearly | Backup runbook §4; NAS runbook §3; README "Rotation"; items marked "Recommended practice" are ITC recommendations |

### 2.3 `reseller_onboarding` (and `_ar`)

| Section | Sources |
|---|---|
| 1 Concepts | `SRC:docs/WP2_PERMISSION_MODEL.md` §3, §5; `SRC:docs/WP3A_FINANCE_MODEL.md` §4, §5 |
| 2 Who may do what | `RoleCapabilityMap.php`; `SRC:docs/WP2_PERMISSION_MODEL.md` §4 (staff target rules); `RBAC` §3 (re-verified against SRC map) |
| 3–5 Onboarding steps | `G:RESELLER_GUIDE.md` §6–§8 and `G:SUPERADMIN_GUIDE.md` §3–§4 re-verified: `SRC:docs/WP6B_FINANCE_UX_MODEL.md` §3; `SRC:docs/WP3A_FINANCE_MODEL.md` §4–§5; `SRC:docs/WP3B_PRICING_MODEL.md` §2–§3, §8 (`COMMISSION_RECIPIENT_NO_WALLET`) |
| 6 Pricing rules | `SRC:docs/WP3B_PRICING_MODEL.md` §2, §3, §5, §7 |
| 7 Limits | `SRC:docs/WP5A_ACTIVATION_MODEL.md` §7; `RoleCapabilityMap.php`; `G:KNOWN_LIMITATIONS.md` §3 re-verified |
| 8 Verification | `SRC:system/reseller/hierarchy.php`; `SRC:docs/WP2_PERMISSION_MODEL.md` §16 |
| 9 Off-boarding | `SRC:docs/WP3A_FINANCE_MODEL.md` §6 (frozen owner rules); `SRC:docs/WP2_PERMISSION_MODEL.md` §6 (customer transfer) |

### 2.4 `subscriber_onboarding` (and `_ar`)

| Section | Sources |
|---|---|
| 1 Two kinds | `SRC:docs/WP5B_LEGACY_CLOSURE_MODEL.md` §2; `SRC:system/lan/english.json` (ServiceGate message, verified) |
| 2 Creating | `SRC:ui/ui/admin/customers/add.tpl` (fields); `SRC:docs/WP2_PERMISSION_MODEL.md` §6; `CONS` §1.1 W8-14 (`W9SharedRadiusKeyTest`); preflight `shared_key` |
| 3 Activation | `SRC:docs/WP5A_ACTIVATION_MODEL.md` §2, §4, §5, §7; `SRC:docs/OPERATOR_RECOVERY_RUNBOOK.md` §1; `G:RESELLER_GUIDE.md` §5 re-verified |
| 4 Direct subscribers | `G:STAFF_OPERATIONS_GUIDE.md` §2.2 re-verified against `RoleCapabilityMap.php` (`service.*`) |
| 5 Quota plans | `SRC:install/freeradius/README.md` "V1 data and time quota"; `SRC:system/autoload/Reseller/Radius/QuotaPolicy.php` (MB / GB, Mins / Hrs); `SRC:system/reseller/quota.php` |
| 6 First connection | NAS runbook §2 step 6; `G:MIKROTIK_FREERADIUS_ONBOARDING.md` §4 (missing pool) |
| 7 Portal | `G:CUSTOMER_PORTAL_GUIDE.md` §1–§4 re-verified: `SRC:system/controllers/login.php` (Banned only), `SRC:system/paymentgateway/` (empty) |
| 8 Vouchers | `SRC:ui/ui/admin/voucher/add.tpl` (fields); `RoleCapabilityMap.php` (`voucher.*`); recovery runbook §2 |

### 2.5 `backup_restore_guide`

| Section | Sources |
|---|---|
| 1–3, 5, 7 | `SRC:docs/BACKUP_RESTORE_RUNBOOK.md` §1–§4; checklist §F, I5; `SRC:install/freeradius/README.md` "Credential file" (part of the backup) |
| 4 Retention / off-host | Backup runbook §2 ("agreed retention, at least one off-site copy"); checklist F3; tooling marked **to be delivered** (no script in SRC) |
| 6 Binlog / PITR | `CONS` §5 Option 2; search of SRC (`docs/`, `system/reseller/`, `install/`, `dev/wp10/`) found no binlog / PITR procedure → **to be delivered** |
| Web download warning | `SRC:system/controllers/settings.php` `case 'dbbackup'`; `SRC:docs/WP2_PERMISSION_MODEL.md` §12 |
| RTO / RPO note | Directive; `CONS` §5 |

### 2.6 `disaster_recovery_guide`

| Section | Sources |
|---|---|
| 2 Options and targets | `CONS` §5 (options table, REL-06); directive (provisional 4 h / 15 min); `SRC:CLAUDE.md` (no DB / RADIUS HA in V1) |
| 3 DB loss | Backup runbook §3; `SRC:install/freeradius/README.md` (fail closed, router limits); `quota.php` exit 3 |
| 4 App host loss | Deployment runbook §1; checklist H2 (CoA source); NAS runbook §4 (`$app_secret`) |
| 5 FreeRADIUS loss | `SRC:install/freeradius/README.md` (Rules, guard, AV-1); checklist §G; `SRC:docs/STAGING_DEPLOYMENT_PACKAGE.md` S7 |
| 6 NAS loss | NAS runbook §2, §3, §5; README "PPPoE session guard" (lost Stop, fail closed) |
| 7 Bad release | Deployment runbook §4 |

### 2.7 `financial_reconciliation_guide`

| Section | Sources |
|---|---|
| 1 Concepts | `SRC:docs/WP3A_FINANCE_MODEL.md` §2, §4, §5, §10 |
| 2 Funding / adjustments | `SRC:docs/WP6B_FINANCE_UX_MODEL.md` §3; WP3A §5–§7 |
| 3 Debits / settlement | `SRC:docs/WP5A_ACTIVATION_MODEL.md` §2 (outcome mapping); `SRC:docs/WP3B_PRICING_MODEL.md` §7–§8 |
| 4 HELD funds | Recovery runbook §1, §2, §5; `SRC:system/reseller/reconcile.php` usage; WP3A §5 ("no expiry primitive"); `CONS` §2 / `FRR` §4 (no automatic release); directive |
| 5 Activation reversal | `SRC:docs/WP5B_LEGACY_CLOSURE_MODEL.md` §5; WP3A WP5B amendment |
| 6 Routine and exports | Recovery runbook §0, §6; `SRC:docs/WP6B_FINANCE_UX_MODEL.md` §5; health `reservations` |
| 7–9 Incident, audit, never do | Recovery runbook §0, §8; WP3A §9; checklist D1 |

### 2.8 `monitoring_alerting_guide`

| Section | Sources |
|---|---|
| 1–2 Sources and thresholds | `SRC:system/reseller/health.php` (code and header); `SRC:system/reseller/preflight.php`; recovery runbook §0 |
| 3 Alerting policy | Checklist §J; README "Readiness check"; `CONS` §3 C4; `SRC:docs/STAGING_DEPLOYMENT_PACKAGE.md` §6 (70 %); README "Known limitation" (AV-1); TIME_CONTRACT §5 |
| 4 Example integration | README example cron line (guard); health example is a generic illustration |
| 5–6 Preflight and gaps | `preflight.php` header; TIME_CONTRACT §5; recovery runbook §4c; `FRR` §5 (capacity) |

### 2.9 `incident_troubleshooting_guide`

| Incident | Sources |
|---|---|
| 1 Incorrect login | `RBAC` §1 modifiers (re-verified), F-7 (verified: no throttling code in `SRC:system/controllers/admin.php` / `login.php`); `SRC:system/controllers/login.php` (Banned after password); `SRC:docs/V1_TARGET_ACCEPTANCE.md` TA-10 |
| 2 Insufficient credit | WP3A §5–§6; WP3B §8; WP5A §7 |
| 3 Expired subscription | `SRC:install/freeradius/sites-available/phpnuxbill` (`expiration`); `SRC:system/devices/Radius.php` `remove_customer`; `SRC:system/cron.php`; recovery runbook §7 |
| 4 Activation pending | Recovery runbook §1; `SRC:docs/WP6B_FINANCE_UX_MODEL.md` §7 |
| 5 NAS unreachable | README "Disconnect / CoA" (port 1700 vs 3799, `COA_NO_RESPONSE`); NAS runbook §2 |
| 6 RADIUS reject | Site file Reply-Messages ("Quota unavailable", "Data quota exhausted or unavailable", "Session verification failed"); README (PAP / CHAP only, dictionary install); recovery runbook §4b; preflight `mac` |
| 7 SNMP verification failed | README "PPPoE session guard", "Readiness check" (reasons) |
| 8 Cannot reconnect | Recovery runbook §3, §4, §4a; README "PPPoE admission reservation" (TD-2, fresh hold); `G:MIKROTIK_FREERADIUS_ONBOARDING.md` §4 (pool) |
| 9 Quota disagreement | README "V1 data and time quota"; `QuotaPolicy.php`; `quota.php`; TIME_CONTRACT §2–§3 |
| 10 Backup / restore failure | Backup runbook §2–§3 |

### 2.10 Others

| Document | Sources |
|---|---|
| `upgrade_rollback_guide` | Deployment runbook §1, §3, §4, §5; `SRC:system/reseller/README.md` (exit codes, states, guarantees); `FRR` §11 (lab switch timings); `SRC:docs/OBS1_SURVEY_RUNBOOK.md` (migration path excluded) |
| `security_hardening_checklist` | Checklist §A7, §B–§H; NAS runbook §1–§4; README (Rules, Credential file, NAS contract, Message-Authenticator, rlm_rest); `SRC:docs/STAGING_DEPLOYMENT_PACKAGE.md` §3, §5; TA-10; `SRC:qrcode/index.php` (public, verified); `SRC:system/controllers/customers.php` (`customer.impersonate` audit, verified); `settings.php` (`$nuxrWriteOnly` per `G:SUPERADMIN_GUIDE.md` §1) |
| `known_limitations` (and `_ar`) | `FRR` verdict, §4–§7, §13; `CONS` header (lead update), §1.1, §2, §3; `SRC:CLAUDE.md`; `SRC:README.md`; `SRC:docs/TIME_CONTRACT.md` §5; README (quota, guard, AV-1, CoA); `QuotaPolicy.php`; `G:KNOWN_LIMITATIONS.md` re-verified item by item (see §3); `SRC:system/controllers/customers.php` and `RadiusPolicyBuilder.php` (status not used by RADIUS policy, verified) |
| `change_management_escalation` | Deployment runbook header (owner authorization); `SRC:CLAUDE.md` (D038: no production action without owner authorization); recovery runbook; checklist §K; `CONS` §3, §5 (owner decisions); severity mapped to health levels. Response times deliberately not defined |
| `templates_support_agreement` | No product source for values (by design). Module list from this documentation set; T5 rows from checklist §A–§K and deployment runbook §2; T6 module 6 from `CONS` §3 C5 |

## 3. Contradictions found and their resolution

| # | Contradiction | Paths | Resolution in these documents |
|---|---|---|---|
| 1 | **Deployment type.** The records say no statement exists on fresh install vs migration (OBS-1 open); the directive states the owner decision: fresh install, empty database | `CONS` §4 (":226 no record"); `FRR` §4, §13 row 5 | Directive applied: fresh install. Migration is described only as a separate future path (upgrade guide scope note; quickstart). OBS-1 survey, P-A4-3 and D-2 are N/A for the first instance per `CONS` §4 table. The owner's written statement in `CONS` §4 remains to be signed |
| 2 | **RTO / RPO.** The consolidation says no owner value exists and values must never be invented; the directive supplies 4 h / 15 min | `CONS` §5; `FRR` §13 row 6 | Both honoured: the values appear only as **PROVISIONAL engineering design targets, not owner-approved SLAs**, with the owner fill-in left blank (DR guide §2, SLA template T2). Added: RPO 15 min is not achievable with Option 1 (the only option covered by SRC) |
| 3 | **O-DL policy record conflict.** D-30 says "residual accepted"; every later record lists O-DL as open | `LAB:LAB_DECISIONS.md` :39–:43; `CONS` §2; `FRR` §4 | Documents describe the implemented and lab-verified behaviour (bounded retry 2, no jitter, HELD never auto-released), which also matches the directive; the owner confirmation is listed as open in `known_limitations` |
| 4 | **Legacy web backup.** The backup runbook says the upstream JSON backup / restore "stays disabled"; the code still serves the JSON table download to SuperAdmin (only the restore is refused) | `SRC:docs/BACKUP_RESTORE_RUNBOOK.md` (scope bullets); `SRC:system/controllers/settings.php` `case 'dbbackup'`; `RoleCapabilityMap.php` (`system.backup`) | Documented as existing but **not a supported backup** (unencrypted, schema-less, not restorable on a reseller installation); restore refused per `SRC:docs/WP2_PERMISSION_MODEL.md` §12. Recommend aligning the runbook text |
| 5 | **Drill tooling not shipped.** The backup runbook's automated drill uses `dev/wp10/drill.sh`; the deployment runbook excludes `dev/` from the release artifact | `SRC:docs/BACKUP_RESTORE_RUNBOOK.md` §4; `SRC:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §1 | Backup guide §7: lab automation runs from the source tree; on a target the same steps are manual |
| 6 | **"Audit export" does not exist as a tool.** Two runbooks tell the operator to reconcile from "the audit export"; no export route or CLI for `tbl_reseller_audit_events` was found (RouteMap has finance CSV exports and a payment-gateway audit only) | `SRC:docs/BACKUP_RESTORE_RUNBOOK.md` §3 step 4; `SRC:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §4; `SRC:system/autoload/Reseller/Security/RouteMap.php` | Documents say "audit records" and point to the finance exports and `reconcile.php show`. Flagged as a gap for ITC (an audit export, or a documented read-only extraction, should be delivered with the release) |
| 7 | **Clock tolerance.** Checklist A5 groups the NAS with the hosts under "skew under 5 s"; the time contract sets the NAS limit at 10 s hard (2 s typical) and 5 s for the hosts | `SRC:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` A5 vs H8; `SRC:docs/TIME_CONTRACT.md` §1 (declares itself canonical) | TIME_CONTRACT applied: hosts ≤ 5 s; NAS ≤ 10 s hard, keep < 2 s; alert from 5 s ahead |
| 8 | **Staging network matrix omits SNMP.** The matrix lists no FreeRADIUS → NAS UDP 161 flow, which the session guard requires | `SRC:docs/STAGING_DEPLOYMENT_PACKAGE.md` §3; `SRC:install/freeradius/README.md` "NAS contract" | Security checklist N4 includes UDP 161 restricted to the FreeRADIUS address |
| 9 | **Draft onboarding guide out of date.** It says the NAS runbook omits type `mikrotik_snmp` / the credential file, and that the upgrade runbook omits the `nasreload` collation step; both are present in SRC | `G:MIKROTIK_FREERADIUS_ONBOARDING.md` §1.4, §8; `SRC:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2 step 3; `SRC:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §3 step 5 | SRC wins; the draft statements were not reused |
| 10 | **Two-NAS test.** Draft limitations say "not tested (ENV-2NAS BLOCKED)"; the consolidation's lead update records a PASS on a local CHR2 on both engines | `G:KNOWN_LIMITATIONS.md` §2.5; `FRR` §5; `CONS` header | Recorded as a local-lab pass that does **not** replace staging case X-1 |
| 11 | **Items listed as limitations / "to confirm" in the draft are engineering-closed.** R-15 (bandwidth after confirmation), AV-3, W8-14, R-4 | `G:KNOWN_LIMITATIONS.md` §3, §4; `CONS` §1.1 (class b; tests present in `SRC:tests/Integration/Wp9/`: `W9BandwidthTermsTest`, `W9CronRenewalRaceTest`, `W9RecoveryToolsTest`, `W9SharedRadiusKeyTest`) | Listed as "engineering-closed, awaiting owner signature", not as functional limitations |
| 12 | **RBAC audit findings fixed in SRC.** F-1 (`settings/miscellaneous` without allowlist), F-12 (markup in translations), F-15 (impersonation not audited) are flagged at `c9ea0be0`; SRC has an allowlist (`$nuxrMiscAllowed`), a markup refusal and an `AuditLog::record('customer.impersonate', …)` | `RBAC` §5; `SRC:system/controllers/settings.php` (:1140, :1060–1075); `SRC:system/controllers/customers.php` (:385–387) | SRC wins; not listed as open. Still present in SRC and documented: F-6 (portal login refuses only Banned), F-7 (no login throttling), F-10 (public QR generator) |
| 13 | **Report role scope.** The illustrated product guide describes Report as "viewing reports and lists"; the code grants no data access | `G:STAFF_OPERATIONS_GUIDE.md` §4 (citing `dev/guide/gen.py`); `RoleCapabilityMap.php` (OD-4) | Code wins: Report = login, empty dashboard, own profile |
| 14 | **VPN plans.** Pricing model says Hotspot, PPPoE **and VPN** plans are eligible for reseller commerce; the activation model accepts only RADIUS Hotspot / PPPoE | `SRC:docs/WP3B_PRICING_MODEL.md` §2; `SRC:docs/WP5A_ACTIVATION_MODEL.md` §7 (D028) | Effective behaviour documented: reseller activation excludes VPN (pricing a VPN plan does not make it activatable) |
| 15 | **CoA source rule evidence.** The NAS runbook states as fact that RouterOS ignores CoA from non-`/radius` sources; the FreeRADIUS README says this was observed on 7.12.1 and not re-tested on 7.24.5 | `SRC:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2 step 4; `SRC:install/freeradius/README.md` "Disconnect / CoA" | Rule kept regardless of version (as the README instructs); documented as a configuration requirement |
| 16 | **Licence statements.** LICENSE is GPLv2, the README says "or later", `composer.json` says MIT | `SRC:LICENSE`; `SRC:README.md`; `FRR` §7 | Not resolved here (LIC-1, counsel). Documents state LIC-1 pending; templates leave licence terms blank |
| 17 | **Candidate SHA.** The final report and consolidation name `a6b02eba`; SRC HEAD is `e02d784b` (documentation-only); the requested metadata names `e02d784b` | `FRR` §2; `CONS` header; `git log` of SRC | `meta.json` uses "e02d784b (final-prep; updated at release)"; content names `a6b02eba` as the lab-accepted candidate |
| 18 | **`CompanyName` missing from the go-live checklist** although the brand rules require it before go-live | `SRC:docs/BRAND_IDENTITY.md` §2; `CONS` §6 (B-8) | Added as a go-live step in the quickstart and installation template (proposal B-8); the SRC checklist itself is unchanged |

## 4. Draft claims not reused (unverified against SRC)

- NEW-7 / NEW-8 ledger closure status (`G:KNOWN_LIMITATIONS.md` §5): not re-verified; not included.
- Exact visible menu set per role and default customer widgets (`G:RESELLER_GUIDE.md` §3, `G:CUSTOMER_PORTAL_GUIDE.md` §6): not re-captured; documents name only verified menus.
- H-2 Hotspot rollout follow-ups and MAC-H1A disposition (`G:KNOWN_LIMITATIONS.md` §4 rows 7–8): only the preflight `mac` behaviour is documented.

## 5. New tooling to be delivered with the release (not in SRC)

| Item | Referenced in |
|---|---|
| Scheduled backup wrapper (runs the runbook dump, writes the backup record, alerts on failure) | `backup_restore_guide` §4 |
| Off-host encrypted transfer with checksum verification | `backup_restore_guide` §4 |
| Retention pruning | `backup_restore_guide` §4 |
| Binary-log / point-in-time recovery procedure, shipping and drill | `backup_restore_guide` §6; `disaster_recovery_guide` §2 |
| Audit-record export (or documented read-only extraction) for gap reconciliation | Contradiction 6 |

## 6. Addendum 2026-10-29: reconciliation with the final-prep work

§5 is superseded: the scheduled backup, off-host hook, retention pruning and binlog point-in-time recovery are now provided by `install/backup/` (staged in `LAB:deliverables/repo_add/install/backup/`, lab-drilled: `LAB:reports/BACKUP_PITR_DRILL_20261029.md`). Still open from §5: a real off-host target, archive-age monitoring, the PITR section of the product runbook, and the audit-record export.

New sources used by the reconciliation: `LAB:reports/FRESH_INSTALL_PROOF_20261029.md` (fresh install), `LAB:reports/R-HF-CARRY-01_FIX.md` and `LAB:reports/review/` (R-HF-CARRY-01), `LAB:reports/UI_I18N_FIXES_20261029.md` (labels), `LAB:reports/CHR2_BUILD_AND_TWO_NAS_RESULT.md` (two-NAS lab test). The per-file change log is `RECONCILIATION_20261029.md`.
