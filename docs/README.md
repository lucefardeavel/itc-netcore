# ITC NetCore documentation

**ISP Management & Billing Platform** · <span dir="rtl" lang="ar">منصة إدارة وفوترة مزوّدي خدمة الإنترنت</span> ·
V1.0.1 Release Candidate 3 ([`v1.0.1-rc.3`](https://github.com/lucefardeavel/itc-netcore/releases/tag/v1.0.1-rc.3)) · developed and maintained by ITC

This is the index of the documentation in this repository. Paths are relative to `docs/`. Entries marked
**added in V1.0** are new folders delivered with the V1.0 release; everything else already exists in the tree.

> [!IMPORTANT]
> ITC NetCore V1.0.1 is a **Release Candidate, not production-accepted**. Lab verdict `LAB_ACCEPTED_WITH_LIMITATIONS`;
> release state `RELEASE_BLOCKED_WITH_EVIDENCE`; target staging not run; LIC-1 `BLOCKED — COUNSEL REQUIRED`.
> Back to the [project README](../README.md#release-status).

<div dir="rtl" lang="ar">

**بالعربية:** هذا فهرس وثائق <bdi>ITC NetCore V1.0</bdi>. الأدلة الموجّهة للعملاء والمشغّلين (التثبيت، و<bdi>MikroTik</bdi> و<bdi>FreeRADIUS</bdi>، والتشغيل، والتدريب، والملف التعريفي للمنتج) موجودة في المجلد `docs/guides/` (القسم 1 أدناه) وتصدر بالعربية والإنجليزية حيثما توفّرت النسختان. أما الوثائق الهندسية والنماذج المرجعية أدناه فبالإنجليزية.

</div>

## 1. Guides (added in V1.0)

| Folder | What it holds | Status |
|---|---|---|
| [guides/install/](guides/install/) | Fresh installation manual (Arabic, English): preparing hosts, installing on a new, empty database with the `install/fresh/` package, verification. Also the separate **future** migration guide (`migration_guide_en`, not accepted for V1's first installation) | added in V1.0 |
| [guides/radius-mikrotik/](guides/radius-mikrotik/) | MikroTik RouterOS and FreeRADIUS integration: NAS onboarding, RADIUS, CoA / Disconnect, SNMP session guard | added in V1.0 |
| [guides/operations/](guides/operations/) | Operating the instance: administrator quick start, reseller and subscriber onboarding, daily checks, backup and restore, disaster recovery, monitoring, incidents, upgrade and rollback, financial reconciliation, security hardening, change management, agreement templates, supported capabilities and known limitations | added in V1.0 |
| [guides/training/](guides/training/) | Illustrated training manual for every role (Arabic primary, English), with exercises, competency checklist and assessment | added in V1.0 |
| [guides/SOURCES/](guides/SOURCES/) | Source registers: which runbook / source file each guide section is based on, and contradictions resolved | added in V1.0 |
| [../dev/docpipe/](../dev/docpipe/) | Document build pipeline (Markdown to branded PDF / DOCX, Arabic RTL and English); source repository only (not in the release artifact) | added in V1.0 |
| [guides/product-profile/](guides/product-profile/) | Customer-facing product profile and one-page summary, Arabic and English | added in V1.0 |
| [../install/fresh/](../install/fresh/) | Fresh-install package: artifact verification, gated command-line installer, post-install verification, nginx / PHP-FPM / logrotate / cron examples | added in V1.0 |
| [../install/backup/](../install/backup/) | Backup, binlog archive (point-in-time recovery chain), restore, restore verification and database fingerprint tools | added in V1.0 |

## 2. Install and deploy

| Document | Purpose |
|---|---|
| [DEPLOYMENT_AND_UPGRADE_RUNBOOK.md](DEPLOYMENT_AND_UPGRADE_RUNBOOK.md) | Deployment and upgrade runbook |
| [../install/fresh/README.md](../install/fresh/README.md) | Fresh-install package: order of use, safety contract, exit codes, differences from the runbook |
| [PRODUCTION_CONFIGURATION_CHECKLIST.md](PRODUCTION_CONFIGURATION_CHECKLIST.md) | Production configuration checklist for one ISP instance |
| [../install/freeradius/README.md](../install/freeradius/README.md) | FreeRADIUS configuration, session guard, readiness checks |
| [NAS_ONBOARDING_AND_SECRET_ROTATION.md](NAS_ONBOARDING_AND_SECRET_ROTATION.md) | Adding a MikroTik NAS and rotating RADIUS / SNMP secrets |
| [TIME_CONTRACT.md](TIME_CONTRACT.md) | Time, NTP and billing-period ownership rules |
| [../system/reseller/README.md](../system/reseller/README.md) | Migration runner and recognised schema variants |
| [../config.sample.php](../config.sample.php) | Sample configuration file |

## 3. Operate and recover

| Document | Purpose |
|---|---|
| [BACKUP_RESTORE_RUNBOOK.md](BACKUP_RESTORE_RUNBOOK.md) | Backup and restore procedures and drills |
| [../install/backup/README.md](../install/backup/README.md) | Backup and point-in-time recovery tooling: installation, server settings, restore, exit codes |
| [OPERATOR_RECOVERY_RUNBOOK.md](OPERATOR_RECOVERY_RUNBOOK.md) | Recovering held activations and reservations, audited releases |
| [OBS1_SURVEY_RUNBOOK.md](OBS1_SURVEY_RUNBOOK.md) | Read-only survey of an existing database (migration path only) |

## 4. Target staging and acceptance

| Document | Purpose |
|---|---|
| [STAGING_DEPLOYMENT_PACKAGE.md](STAGING_DEPLOYMENT_PACKAGE.md) | Isolated target staging: topology, hosts, network matrix |
| [V1_TARGET_ACCEPTANCE.md](V1_TARGET_ACCEPTANCE.md) | Target acceptance procedure TA-01 … TA-10 and evidence template (not run yet) |

## 5. Product and domain models

| Document | Topic |
|---|---|
| [WP1_SCHEMA_CONTRACT.md](WP1_SCHEMA_CONTRACT.md) | Reseller-domain database schema |
| [WP2_PERMISSION_MODEL.md](WP2_PERMISSION_MODEL.md) | Roles, hierarchy, ownership and data scope |
| [WP3A_FINANCE_MODEL.md](WP3A_FINANCE_MODEL.md) | Wallets, ledger, funding, transfers, reservations |
| [WP3B_PRICING_MODEL.md](WP3B_PRICING_MODEL.md) | Pricing chain, commissions, provider settlement |
| [WP4_RADIUS_MODEL.md](WP4_RADIUS_MODEL.md) | FreeRADIUS policy, accounting, Disconnect / CoA |
| [WP5A_ACTIVATION_MODEL.md](WP5A_ACTIVATION_MODEL.md) | Activation state machine, retry, reconciliation |
| [WP5B_LEGACY_CLOSURE_MODEL.md](WP5B_LEGACY_CLOSURE_MODEL.md) | Closure of legacy service entry points |
| [WP6A_UX_MODEL.md](WP6A_UX_MODEL.md) | Adaptive shell and subscriber UX |
| [WP6B_FINANCE_UX_MODEL.md](WP6B_FINANCE_UX_MODEL.md) | Finance, reseller, pricing, reports and operations UX |
| [WP6C_ADAPTIVE_UX_MODEL.md](WP6C_ADAPTIVE_UX_MODEL.md) | One system, two UX modes (desktop and phone) |
| [WP7_PWA_MODEL.md](WP7_PWA_MODEL.md) | Staff PWA and safe offline behaviour |
| [WP8_INTEGRATION_MODEL.md](WP8_INTEGRATION_MODEL.md) | Application ⇄ FreeRADIUS ⇄ MikroTik RouterOS integration |
| [../owner-review/WP10/N1_G_COMMERCIAL_QUOTA_CONTRACT.md](../owner-review/WP10/N1_G_COMMERCIAL_QUOTA_CONTRACT.md) | Actual vs billable usage, quota accuracy; source repository only (not in the release artifact) |

## 6. Architecture and decisions

| Document | Purpose |
|---|---|
| [architecture/owner-lock-2026-10-09/OWNER_ARCHITECTURE_LOCK.md](architecture/owner-lock-2026-10-09/OWNER_ARCHITECTURE_LOCK.md) | Owner architecture decisions (verbatim, SHA-256 manifest) |
| [architecture/owner-lock-2026-10-09/ARCHITECTURE_DECISIONS.md](architecture/owner-lock-2026-10-09/ARCHITECTURE_DECISIONS.md) | Decisions AD-00 … AD-24 |
| [architecture/owner-lock-2026-10-09/TARGET_ARCHITECTURE.md](architecture/owner-lock-2026-10-09/TARGET_ARCHITECTURE.md) | Target architecture |
| [architecture/DEVELOPMENT_STATUS_REPORT.md](architecture/DEVELOPMENT_STATUS_REPORT.md) | Dated status audit (2026-10-09) |
| [architecture/RAD3A_OPTION_D_PROPOSAL.md](architecture/RAD3A_OPTION_D_PROPOSAL.md) | Proposal only, not implemented |
| [media/architecture.svg](media/architecture.svg) | High-level architecture diagram |

## 7. Release, status and legal

| Document | Purpose |
|---|---|
| [release/RELEASE_NOTES_V1.0.1.md](release/RELEASE_NOTES_V1.0.1.md) | V1.0.1 release notes and upgrade steps (maintenance release candidate; latest pre-release `v1.0.1-rc.3`) |
| [release/RELEASE_NOTES_V1.0.md](release/RELEASE_NOTES_V1.0.md) | V1.0 release notes (Release Candidate) |
| [release/CHANGELOG_V1.0_entry.md](release/CHANGELOG_V1.0_entry.md) | V1.0 changelog entry |
| [../CHANGELOG.md](../CHANGELOG.md) | History of the code base |
| [../owner-review/CURRENT_STATE_AND_OPEN_NOTES.md](../owner-review/CURRENT_STATE_AND_OPEN_NOTES.md) | Current state, open items, where each record is; source repository only (not in the release artifact) |
| [claude-development-pack/review/ACCEPTANCE_LEDGER.md](claude-development-pack/review/ACCEPTANCE_LEDGER.md) | Owner-controlled acceptance ledger; source repository only (not in the release artifact) |
| [../owner-review/WP10/LEGAL_REVIEW_PACKAGE.md](../owner-review/WP10/LEGAL_REVIEW_PACKAGE.md) | Legal review package (LIC-1, `BLOCKED — COUNSEL REQUIRED`); source repository only (not in the release artifact) |
| [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md) | Third-party components and licences |
| [../NOTICE](../NOTICE), [../LICENSE](../LICENSE) | Notice and licence text |

## 8. Brand and credits

| Document | Purpose |
|---|---|
| [BRAND_IDENTITY.md](BRAND_IDENTITY.md) | Product name, tagline, visual identity, credits that must stay |
| [../ui/ui/images/brand/ASSETS.txt](../ui/ui/images/brand/ASSETS.txt) | Approved brand assets and their SHA-256 hashes |

## 9. Development

| Document | Purpose |
|---|---|
| [../tests/README.md](../tests/README.md) | Test suite (development only); source repository only (not in the release artifact) |
| [../.github/workflows/lab-unit.yml](../.github/workflows/lab-unit.yml) | Unit-suite workflow for lab branches |
| [claude-development-pack/START_HERE.md](claude-development-pack/START_HERE.md) | Development rules and specification (manifest-pinned); source repository only (not in the release artifact) |

Support terms are defined per customer agreement.
