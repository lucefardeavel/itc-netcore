# ITC NetCore V1.0 technical manuals: sources and contradiction register

Prepared 2026-10-29 for the five customer-facing manuals in this folder. Every instruction in the manuals is taken from the files listed here. The product tree was read only; nothing in it was changed.

**Path prefixes**

| Prefix | Location |
|---|---|
| `S:` | Product tree, final candidate: `\\wsl.localhost\Ubuntu-24.04\srv\itcnc\src\final\` at commit `e02d784bbfd33e3ff1912bed502d4b80b68b975f` ("docs(staging): close staging-package gaps …") |
| `L:` | Lab: `C:\Users\user\Desktop\ITC-NetCore-Lab\` |

**Documents written**

| Folder | Language | Title |
|---|---|---|
| `install_guide_en/` | en | ITC NetCore V1.0 — Fresh Installation Manual |
| `install_guide_ar/` | ar | ITC NetCore V1.0 — دليل التثبيت الجديد |
| `radius_mikrotik_guide_en/` | en | ITC NetCore V1.0 — MikroTik and FreeRADIUS Integration Manual |
| `radius_mikrotik_guide_ar/` | ar | ITC NetCore V1.0 — دليل تكامل MikroTik وFreeRADIUS |
| `migration_guide_en/` | en | Migration from an Existing PHPNuxBill Database (Future Path) |

Each folder holds `meta.json` and `content.md`; the four install / RADIUS folders also hold `topology.svg` (English or Arabic labels). `meta.json` carries the requested `sha` key and, with the same value, `source_sha`, which the renderer in `L:deliverables\docpipe\lib\common.py` requires.

**Verification-level rule used.** A procedure is "Lab-verified" only when a lab script under `L:scripts\` executed it and left evidence under `L:evidence\` (or, where stated, when the automated harness covered it). The lab-install chain is `L:scripts\p1_01_mariadb.sh`, `p1_03_php_nginx.sh`, `p1_05_freeradius.sh`, `d01_stage.sh`, `d02_installer.mjs`, `d03_postinstall.sh`, `d04_migrate_grants.sh`, `d05_grants_admin.sh`, `d06_redeploy.sh`, `d07_cron.sh`, `r01_freeradius_install.sh`, `fr_tz_sync.sh`, `chr_20_configure_nas.sh` + `chr_20_radius_nas.rsc.tpl`, `b01_backup_restore_drill.sh`, `e2e_01_firstlogin.mjs`, `e2e_02_settings.mjs`, `e2e_03`…`e2e_08`, and `d14_deploy_release.sh` (redeploy, rollback rehearsal, preflight / health). Everything else is "Documented (not yet executed on target)". No procedure was executed on a target; every TA row in `S:docs/V1_TARGET_ACCEPTANCE.md` is `NOT_RUN`.

## 1. Section-to-source map

### 1.1 Fresh Installation Manual (`install_guide_en`, `install_guide_ar`)

| Section | Sources |
|---|---|
| About, release status, scope | `S:README.md`; `S:CLAUDE.md`; `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` (authorization sentence); `S:docs/V1_TARGET_ACCEPTANCE.md` §1, §3; `S:docs/BRAND_IDENTITY.md` §1, §4; owner decision D-61 in `L:LAB_DECISIONS.md` and `L:OWNER_ACTION_REQUIRED.md` (2026-10-29 section) |
| Verification levels (lab description) | `L:scripts\p1_01_mariadb.sh`, `p1_03_php_nginx.sh`, `p1_02_mysql80.ps1`, `p1_05_freeradius.sh`, `r01_freeradius_install.sh`; `L:evidence\deploy\envcheck_after-release_20261029T101501Z.txt` |
| Quick-start checklist | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2 steps 1–13; `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` §A–§K |
| Topology | `S:docs/STAGING_DEPLOYMENT_PACKAGE.md` §1, §3; `S:docs/WP8_INTEGRATION_MODEL.md` §2; `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2 step 4; `S:owner-review/WP10/TARGET_AUTHORIZATION_REQUEST.md` §2; `S:install/step4.php` (the RADIUS option writes `$radius_*` = the main database) |
| Operating system | `L:reports\STAGING_AUTHORIZATION_PACKAGE_V1.md` R-OS, R-NTP; `S:install/freeradius/README.md` (snmp pin) |
| Sizing | `S:docs/STAGING_DEPLOYMENT_PACKAGE.md` §2; `S:docs/V1_TARGET_ACCEPTANCE.md` TA-09; `L:reports\STAGING_AUTHORIZATION_PACKAGE_V1.md` rule 2 |
| PHP 8.2 and extensions | `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` A1; `S:README.md`; `L:scripts\p1_03_php_nginx.sh`; `S:install/step2.php`; `S:Dockerfile`; `S:composer.json`; `S:system/vendor/` |
| Database engine | `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` A2–A7; `S:system/reseller/README.md` (minimums); `L:scripts\p1_01_mariadb.sh`; `S:install/freeradius/README.md` (B-L09 row); `L:OWNER_ACTION_REQUIRED.md` §0b |
| FreeRADIUS | `S:install/freeradius/README.md`; `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` G1–G5; `L:scripts\r01_freeradius_install.sh` (L1–L4) |
| Time and NTP | `S:docs/TIME_CONTRACT.md` §1, §5, §6; `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` A5, H8; `L:scripts\env_chrony_ptp.sh` (lab clock fix, D-40) |
| Firewall and ports | `S:docs/STAGING_DEPLOYMENT_PACKAGE.md` §3; `S:owner-review/WP10/TARGET_AUTHORIZATION_REQUEST.md` §2; `S:install/freeradius/README.md` "NAS contract" (UDP 161) |
| DNS and HTTPS | `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` B1, B2, B9; `S:config.sample.php` |
| Backup storage | `S:docs/BACKUP_RESTORE_RUNBOOK.md` intro, §1; `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` F1–F3 |
| Security prerequisites | `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` C6, D1–D4, E7; `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §1; `S:docs/STAGING_DEPLOYMENT_PACKAGE.md` §5; `S:docs/V1_TARGET_ACCEPTANCE.md` P1–P5 |
| Obtain and verify the artifact | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §1; `S:docs/STAGING_DEPLOYMENT_PACKAGE.md` S1; `L:scripts\d01_stage.sh` |
| Deploy the code, ownership | `L:scripts\d01_stage.sh`, `d06_redeploy.sh`; `S:install/step4.php`, `S:install/step5.php`; `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` B7, C; `S:init.php` (`$CACHE_PATH`, `$UPLOAD_PATH`, `$PAGES_PATH`); `S:system/boot.php` (Smarty compile / cache dirs); `S:system/cron.php` (lock file) |
| nginx and PHP-FPM | `L:scripts\p1_03_php_nginx.sh`, `d01_stage.sh`; `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` B1–B9; `S:.htaccess_firewall`; `S:index.php` (headers); `S:update.php`; `S:ui/ui/admin/plan/invoice.tpl`, `S:ui/ui/customer/invoice-customer.tpl` (`system/uploads/paid.png`) |
| Create the new, empty database | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2 step 2; `S:docs/BACKUP_RESTORE_RUNBOOK.md` §3 step 1 (CREATE DATABASE form); `S:install/phpnuxbill.sql`, `S:install/radius.sql` (`DROP TABLE IF EXISTS`); `S:install/step4.php` (no emptiness check); `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` C3, D3; `L:scripts\d01_stage.sh`, `d03_postinstall.sh` → `d04_migrate_grants.sh` (host-part finding) |
| Run the web installer | `S:install/index.php`, `step2.php`, `step3.php`, `step4.php`, `step5.php`; `L:scripts\d02_installer.mjs`; `L:evidence\deploy\d02_installer.txt` |
| Remove the installer | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2 step 4; checklist B4; `L:scripts\d03_postinstall.sh` |
| Review config.php | `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` C1–C7, B9; `S:config.sample.php`; `S:install/step4.php`; `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §4; `S:system/autoload/Reseller/Domain/ReleaseProfile.php` |
| Base schema | `S:docs/BRAND_IDENTITY.md` §4; `S:system/reseller/README.md` (variants) |
| Migrations | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2 step 6; `S:system/reseller/README.md`; `S:system/reseller/migrate.php`; `S:system/reseller/migrations/R0001…R0007`; `L:evidence\deploy\d04_migrate_grants.txt` |
| RADIUS admission tables | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2 step 7; `S:install/freeradius/README.md` file table; `L:scripts\d03_postinstall.sh` |
| Least-privilege accounts | `S:system/reseller/grants.php`; `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2 step 8; checklist D1–D4; `S:docs/BACKUP_RESTORE_RUNBOOK.md` §2 (backup account); `L:scripts\d03…d05`; `L:evidence\deploy\d05_grants_admin.txt` |
| First login | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2 step 11; checklist C7; `S:install/phpnuxbill.sql` (seeded `admin` row, SHA-1 of `admin`); `S:system/autoload/Admin.php` (`hasDefaultCredential`, SEC-18); `L:scripts\e2e_01_firstlogin.mjs` |
| Basic settings | `S:docs/BRAND_IDENTITY.md` §2; `S:install/phpnuxbill.sql` (seeded `CompanyName`, `timezone`, `currency_code`); `S:init.php` (`radius_enable` gate); `S:system/controllers/settings.php` (SuperAdmin-only key); `L:DEFECT_REGISTER.md` ACC-1; `L:scripts\e2e_02_settings.mjs` |
| Verify HTTPS and the web boundary | `S:docs/V1_TARGET_ACCEPTANCE.md` TA-10 (§2A); checklist B1–B6 |
| Setup preflight and health | `S:system/reseller/preflight.php` (header), `S:system/reseller/health.php` (header); runbook §2 step 12; `L:LAB_DECISIONS.md` D-48 (PF-0) |
| Services: scheduled jobs | checklist §I; `L:scripts\d07_cron.sh`; `S:system/cron.php` |
| RADIUS and accounting, quota enforcer | `S:install/freeradius/README.md` "Rules", "V1 data and time quota"; `S:system/reseller/quota.php` |
| Session monitoring | `S:install/freeradius/README.md` "Readiness check", "Hotspot admission slots"; `S:docs/OPERATOR_RECOVERY_RUNBOOK.md` §0 |
| Finance health | `S:docs/OPERATOR_RECOVERY_RUNBOOK.md` §0, §8 |
| Backups | `S:docs/BACKUP_RESTORE_RUNBOOK.md` §1–§4; `L:scripts\b01_backup_restore_drill.sh` |
| Alerting | checklist §J; `S:system/reseller/health.php` |
| Log rotation | none (gap, see contradiction X-31) |
| Supervision | `S:install/freeradius/README.md` "Known limitation"; `L:scripts\p1_03_php_nginx.sh`, `p1_01_mariadb.sh`, `d07_cron.sh` |
| Release preflight | runbook §2 step 12; `S:docs/V1_TARGET_ACCEPTANCE.md` §2A TA-01; `S:dev/staging/ta_eval.py` (10 required checks) |
| Verification checklist | runbook §5; `S:docs/V1_TARGET_ACCEPTANCE.md`; `S:docs/WP8_INTEGRATION_MODEL.md` §3, §5; `L:scripts\e2e_03…e2e_08`; `L:reports\handover\guides\MIKROTIK_FREERADIUS_ONBOARDING.md` §4 (pool on NAS) |
| Go-live sign-off | checklist §K |
| Troubleshooting appendix | `L:evidence\deploy\d03_postinstall.txt` (host-part, grants-before-migrations, ACC-1 symptoms, CLI warnings); `S:system/reseller/README.md` (exit codes, rollback); `S:docs/OPERATOR_RECOVERY_RUNBOOK.md` §4b, §4c; `S:install/freeradius/README.md`; `L:scripts\r01_freeradius_install.sh` (L4) |
| Exit codes appendix | headers of `migrate.php`, `preflight.php`, `health.php`, `quota.php`, `grants.php`; `S:docs/OPERATOR_RECOVERY_RUNBOOK.md` (reconcile); `S:install/freeradius/bin/nuxr_guard_selfcheck` |

### 1.2 MikroTik and FreeRADIUS Integration Manual (`radius_mikrotik_guide_en`, `radius_mikrotik_guide_ar`)

| Section | Sources |
|---|---|
| About, labels | `S:docs/V1_TARGET_ACCEPTANCE.md`; `S:docs/WP8_INTEGRATION_MODEL.md` §0; `L:reports\handover\guides\MIKROTIK_FREERADIUS_ONBOARDING.md` (evidence level) |
| Architecture and topology, `topology.svg` | `S:docs/WP8_INTEGRATION_MODEL.md` §1, §2; `S:docs/STAGING_DEPLOYMENT_PACKAGE.md` §1, §3; `S:owner-review/WP10/TARGET_AUTHORIZATION_REQUEST.md` §2; `L:reports\STAGING_AUTHORIZATION_PACKAGE_V1.md` §1; colours from `S:docs/BRAND_IDENTITY.md` §3 and `S:ui/ui/styles/nuxr-brand.css` (`--nc-navy #193768`, `--nc-core-blue #245d99`, `--nc-interactive #3275b5`, `--nc-teal #117c88`, `--nx-bg #f5f8fc`, `--nx-text #18283e`, `--nx-surface-2 #eaf2f9`, `--nc-solid-danger #b42318`) |
| RouterOS requirements | `S:install/freeradius/README.md` "Message-Authenticator", "NAS contract", "Hotspot admission slots"; `S:docs/WP8_INTEGRATION_MODEL.md` §3, §12; checklist H8; `L:reports\STAGING_AUTHORIZATION_PACKAGE_V1.md` §3 (licence) |
| FreeRADIUS installation | `S:install/freeradius/README.md` (file table, Rules, PPPoE session guard Install, NAS contract); `L:scripts\p1_05_freeradius.sh`, `r01_freeradius_install.sh` |
| SQL integration | `S:install/freeradius/mods-available/sql.example`; `S:system/reseller/grants.php`; `S:install/freeradius/README.md` steps 2 and 5, "Capacity sizing"; `S:owner-review/WP10/TARGET_AUTHORIZATION_REQUEST.md` §3 (forbidden reproduction) |
| Time synchronisation | `S:docs/TIME_CONTRACT.md`; `S:install/freeradius/README.md` "Time zone", "Period attribution"; `L:scripts\r01_freeradius_install.sh` (systemd drop-in), `fr_tz_sync.sh` |
| NAS registration | `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2; `S:install/freeradius/README.md` "NAS clients", "Credential file"; `S:ui/ui/admin/radius/nas-add.tpl`; `S:system/autoload/Reseller/Ui/Navigation.php`; `L:scripts\r01_freeradius_install.sh` (nas row, nas_snmp) |
| Secret handling and rotation | `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §1, §3, §4; `S:install/freeradius/README.md` "Rotation" |
| RouterOS configuration | `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2 step 4; `S:dev/staging/staging_nas.rsc.tpl`; `S:install/freeradius/README.md` "NAS contract", "Disconnect / CoA", "Hotspot canonical spelling"; checklist H1–H8; `S:docs/WP8_INTEGRATION_MODEL.md` §3, §10; lab reference block from `L:scripts\chr_20_radius_nas.rsc.tpl` |
| Second NAS, removal | `L:reports\STAGING_AUTHORIZATION_PACKAGE_V1.md` §3 (X-1); `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §5 |
| Accounting | `S:docs/WP8_INTEGRATION_MODEL.md` §5; `S:install/freeradius/README.md` "Accounting integrity", "PPPoE admission reservation"; `S:docs/TIME_CONTRACT.md` §3; `S:system/autoload/Reseller/Radius/RadiusPolicyBuilder.php`, `QuotaPolicy.php` (interim) |
| Disconnect and CoA | `S:install/freeradius/README.md` "Disconnect / CoA", "Renewal"; `S:docs/WP8_INTEGRATION_MODEL.md` §2, §6, §11; `S:docs/OPERATOR_RECOVERY_RUNBOOK.md` §4a |
| Firewall boundaries | `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2; `S:dev/staging/staging_nas.rsc.tpl`; checklist A7, H2 |
| Admission policy | `S:install/freeradius/README.md` (PPPoE session guard, Hotspot canonical spelling, PPPoE admission reservation, Hotspot admission slots, Message-Authenticator); `S:docs/WP8_INTEGRATION_MODEL.md` §4; `S:docs/WP4_RADIUS_MODEL.md` §2 |
| Quotas | `S:install/freeradius/README.md` "V1 data and time quota"; `S:system/reseller/quota.php`; `S:system/autoload/Reseller/Radius/QuotaPolicy.php`; `S:owner-review/WP10/N1_G_COMMERCIAL_QUOTA_CONTRACT.md`; `S:docs/V1_TARGET_ACCEPTANCE.md` TA-04, TA-05, TA-09 |
| Tables | `S:docs/WP4_RADIUS_MODEL.md` §2, §4; `S:install/radius.sql`; `S:install/freeradius/README.md` |
| Guard scripts and readiness | `S:install/freeradius/bin/nuxr_guard_selfcheck` (header); `S:install/freeradius/README.md` "Readiness check", "Rotation", Hotspot "Install / upgrade" step 5 |
| File ownership | `S:install/freeradius/README.md`; `L:scripts\r01_freeradius_install.sh`; checklist C, G4 |
| Logs and service management | `S:install/freeradius/README.md` "Known limitation", "Operator recovery"; `L:scripts\r01_freeradius_install.sh`, `d10_envcheck.sh` |
| Recovery | `S:docs/OPERATOR_RECOVERY_RUNBOOK.md` §3, §4a, §4b, §4c; `S:install/freeradius/README.md` "Operator recovery", "A subscriber locked out by an ACTIVE reservation", Hotspot "Recovery runbook"; `S:tests/Integration/Wp9/W9RecoveryToolsTest.php` (harness coverage of `admission-release`) |

### 1.3 Migration guide (`migration_guide_en`)

| Section | Sources |
|---|---|
| Status | `L:LAB_DECISIONS.md` D-61; `L:OWNER_ACTION_REQUIRED.md` (2026-10-29); `L:reports\OWNER_DECISION_CONSOLIDATION_20261029.md` §4; `L:reports\GATE_REGISTER_DRAFT_20261028.md` rows 242–243 |
| What migration means | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §3 intro; `S:system/reseller/README.md` |
| M-2 variant | `S:system/reseller/README.md` "Core-schema contract"; `S:system/reseller/Migration/variants.php` |
| M-3 OBS-1 | `S:docs/OBS1_SURVEY_RUNBOOK.md`; `S:owner-review/WP10/OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md`; checklist E4 |
| M-4 P-A4-3 | checklist E6; `S:docs/WP8_INTEGRATION_MODEL.md` §12 item 8; `S:owner-review/WP8/OWNER_DECISIONS.md` (P-A4-3); `S:owner-review/WP8/WP8_A4_IMPLEMENTATION_AND_TEST_REPORT.md` (+10800 s characterisation); `S:owner-review/WP9/WP8_WP9_UNIFIED_CLOSURE_AND_RELEASE_ROADMAP.md` W8-12 |
| M-5 D-2 | `L:OWNER_ACTION_REQUIRED.md` §1 "D-2 consequence"; `L:reports\OWNER_DECISION_CONSOLIDATION_20261029.md` §4; `L:reports\handover\guides\KNOWN_LIMITATIONS.md` |
| M-6 collation | `S:install/freeradius/README.md` file table (B-L09); `S:install/freeradius/nuxr_nasreload_collation*.sql`; `L:OWNER_ACTION_REQUIRED.md` §0b |
| M-7 MAC-H1A | `S:install/freeradius/README.md` "Hotspot canonical spelling"; checklist E5 |
| M-8 Hotspot | `S:install/freeradius/README.md` "Hotspot admission slots" (Prerequisites, Install); `S:docs/WP8_INTEGRATION_MODEL.md` §12 item 8 |
| M-9 legacy devices | `S:system/autoload/Reseller/Domain/ReleaseProfile.php`; `S:config.sample.php`; checklist C4 |
| M-10…M-12 | companion RADIUS manual sources; checklist E7; `S:docs/BACKUP_RESTORE_RUNBOOK.md` |
| Procedure, rollback, never | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §3, §4; `S:docs/BACKUP_RESTORE_RUNBOOK.md` §4 (D1–D3); `S:owner-review/WP10/evidence/drill/`; `S:install/update.php`, `S:update.php` |
| Owner decisions | `L:reports\OWNER_DECISION_CONSOLIDATION_20261029.md` §4 "What the owner must state" |

## 2. Contradictions and gaps found, and how they were resolved

Rule applied: the stricter or the newer authoritative statement wins; where the code and a document disagree about behaviour, the code is taken as the behaviour and the document gap is recorded. Each row names the winner.

| # | Topic | Source A | Source B | Resolution (winner) |
|---|---|---|---|---|
| X-01 | How to run `cron.php` | `S:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` I1 and the header of `S:system/cron.php`: `php system/cron.php` | `S:system/cron.php` code: `include "../init.php"` (resolved against the working directory); `L:scripts\d07_cron.sh` runs `cd system && php cron.php` | **Code behaviour + lab-verified form** (`cd <app-root>/system && php cron.php`). The checklist / header wording is noted in the manual |
| X-02 | `Acct-Interim-Interval` value and scope | `S:docs/WP4_RADIUS_MODEL.md` §2: "V1: 60 with a data quota"; `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2: sent "in every Access-Accept" | `S:system/autoload/Reseller/Radius/QuotaPolicy.php` `INTERIM_SECONDS = 30`; `RadiusPolicyBuilder.php` writes it only for data-quota plans; `S:install/freeradius/README.md`: default 30 s | **Code** (30 s, data-quota plans only). Gap recorded: the interval of a non-quota Hotspot session with `radius-interim-update=received` is not documented; manuals ask to measure it in TA-03 |
| X-03 | Where `nuxr_quota_interim_seconds` is set | `S:install/freeradius/README.md`: "in `config.php`" | `QuotaPolicy::interim()` reads `$GLOBALS['config']`, which `S:init.php` fills from `tbl_appconfig` after loading `config.php` | Unresolved in the sources. Manuals give **no procedure to change it** and keep the default 30 s (owner's provisional N1; final value from TA-05 / TA-09) |
| X-04 | Hotspot interim on the NAS | `L:scripts\chr_20_radius_nas.rsc.tpl` and `S:docs/WP8_INTEGRATION_MODEL.md` §3, §12: `radius-interim-update=1m` | `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2, `S:dev/staging/staging_nas.rsc.tpl` §5, checklist H7: `received` | **Newer V1 quota rule** (`received`). The lab block is shown with the difference called out |
| X-05 | CoA from a separate application address | `S:docs/WP8_INTEGRATION_MODEL.md` §2: application co-located with FreeRADIUS, "no SNAT, and no second generic `/radius` entry" | `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2, checklist H2, `S:dev/staging/staging_nas.rsc.tpl` §2: a second `/radius` entry for the application address is allowed | **Stricter** (co-location recommended, lab-verified); the second entry is documented as an unverified alternative |
| X-06 | Wrong-source CoA on 7.24.5 | `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` and checklist H2 state it as fact ("ignores any other source") | `S:install/freeradius/README.md` and `S:docs/WP8_INTEGRATION_MODEL.md` §2: proven on 7.12.1 only, NOT re-tested on 7.24.5 | Rule kept as **binding** (stricter); its verification status is stated |
| X-07 | NAS type and credential file in the NAS document | `L:reports\handover\guides\MIKROTIK_FREERADIUS_ONBOARDING.md` §8 and `L:reports\handover\HANDOVER_INDEX_V1.md` §6 item 2: the NAS document omits `mikrotik_snmp` and `nas_snmp` | `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2 step 3 (final tree) now includes both | **Newer** final tree; the handover gap is closed |
| X-08 | Upgrade runbook and the collation step | `L:reports\handover\guides\MIKROTIK_FREERADIUS_ONBOARDING.md` §1 step 4: runbook §3 lacks it (at `6b2e5baa`) | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §3 step 5 (final) includes `nuxr_nasreload_collation*.sql` | **Newer** final tree |
| X-09 | `nas_snmp` file mode | `S:install/freeradius/README.md` "Credential file": "Mode 0600"; header of `S:install/freeradius/bin/nuxr_guard_selfcheck`: "must be 0600" | Same script's code accepts `600 | 400`; README "Readiness check", NAS document §2, checklist G4: "0600 (or 0400)" | 0600 recommended, 0400 accepted (**code**) |
| X-10 | FreeRADIUS version | `S:install/freeradius/README.md`, `S:docs/WP8_INTEGRATION_MODEL.md` §12: validated on 3.2.8 only | `S:owner-review/WP10/TARGET_AUTHORIZATION_REQUEST.md` R5: "3.2.x"; the lab's native host ran Ubuntu 3.2.5 (`L:scripts\r01_freeradius_install.sh`) with deviations L1–L4 | **Stricter**: 3.2.8 validated; any other build needs the Option G / X-4 gate; lab 3.2.5 results are labelled with their deviations |
| X-11 | Stock `date` module | `S:install/freeradius/README.md`: "enabled by default" | `L:scripts\r01_freeradius_install.sh` L4: not enabled by the Ubuntu 3.2.5 package | Manuals instruct to **ensure** the link exists (lab-verified) |
| X-12 | Stock `checkrad` | `S:install/freeradius/README.md` file table: no replacement step | Same README "NAS contract": validated only as the SNMP-less checkrad of the official image; a distro checkrad with SNMP can wait 10 s; lab L2 used an SNMP-less copy | Lab deviation documented as such; **gate mandatory** on the target build |
| X-13 | PHP version | `S:install/step2.php`: "at least PHP 7.2"; `S:Dockerfile`: `php:7.4-apache` | `S:README.md`, checklist A1, TAR R3: PHP 8.2 | **Stricter** (PHP 8.2); the Dockerfile is not a V1 deployment path (also `L:reports\STAGING_AUTHORIZATION_PACKAGE_V1.md` C-11) |
| X-14 | Writable paths | Checklist B7: `ui/compiled/`, `system/uploads/`, `pages/` | `S:init.php` (`$CACHE_PATH = system/cache`, used by `S:system/cron.php` for its lock file); `S:system/boot.php` (`ui/cache/` as Smarty cache dir) | **Code**: `system/cache/` and `ui/cache/` added to the writable list |
| X-15 | Ownership of the code tree | Checklist B7: code tree read-only for the PHP user | `L:scripts\d01_stage.sh`, `d06_redeploy.sh`: `chown -R www-data` on the whole release | **Stricter** (B7); the lab practice is labelled as a lab deviation |
| X-16 | Blocking `system/` on the web | Checklist B3: `system/` not reachable "except the routed entry" | Templates load `system/uploads/paid.png` (`S:ui/ui/admin/plan/invoice.tpl`, `S:ui/ui/customer/invoice-customer.tpl`) | Requirement kept, with the explicit exception for files the UI uses; no production nginx block is given because none ships |
| X-17 | `install/` removal vs later use of `install/freeradius/` | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2: step 4 removes `install/`, step 7 then loads `install/freeradius/*.sql` | — (ordering issue inside one document) | Manuals keep a verified artifact copy `<artifact-dir>` outside the document root (the lab read the files from its source tree) |
| X-18 | Manifest check in staging S1 | `S:docs/STAGING_DEPLOYMENT_PACKAGE.md` S1: `sha256sum -c docs/claude-development-pack/MANIFEST.sha256` | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §1: `docs/claude-development-pack/` is excluded from the artifact | Manual states the check runs **on a source checkout only** |
| X-19 | Identity-survey tool paths | Checklist E4 and the `identity.collisions` message in `S:system/reseller/preflight.php`: `dev/wp9/identity_survey.sql`; `S:docs/OBS1_SURVEY_RUNBOOK.md` step 6: `dev/wp10/obs1_sheet.py` | `dev/` is excluded from the artifact (runbook §1); the shipped, byte-identical copy is `S:system/reseller/identity_survey.sql` (OBS-1 runbook "Read-only guarantees") | Migration guide uses the **shipped** path and states that `obs1_sheet.py` exists only in the source tree |
| X-20 | P-A4-3 survey procedure | Checklist E6: survey "`docs/OPERATOR_RECOVERY_RUNBOOK.md`" | `S:docs/OPERATOR_RECOVERY_RUNBOOK.md` contains no such procedure; roadmap W8-12 `OPEN_OPERATIONAL` | Recorded as an **open gap** (migration gate M-4); no procedure invented |
| X-21 | Time zone of FreeRADIUS | `S:docs/TIME_CONTRACT.md` §1: application, database, FreeRADIUS "UTC zone" | `S:install/freeradius/README.md` "Time zone": run FreeRADIUS with the application zone (e.g. `TZ=Asia/Baghdad`) | Reconciled by checklist G3 (newer, more specific): hosts and SQL sessions UTC; the FreeRADIUS **process** `TZ` = application zone |
| X-22 | `nas_clock` thresholds | `L:OWNER_ACTION_REQUIRED.md` §1 (2026-10-25): health warns only beyond 120 s; N2 proposal: 60 s NAS limit | `S:docs/TIME_CONTRACT.md` §1, §5, `S:system/reseller/health.php`, `preflight.php`: WARN > 5 s ahead, FAIL > 10 s | **Newer** (code + time contract) |
| X-23 | Quota scope and attribution | `S:docs/WP8_INTEGRATION_MODEL.md` §3: quotas "Hotspot Limited plans only", NAS behaviour NOT_VERIFIED; `S:docs/WP4_RADIUS_MODEL.md` §4: counters by `acctstarttime >= Nuxr-Period-Start` | `S:install/freeradius/README.md` "V1 data and time quota" (PPPoE and Hotspot, CHR-measured); `S:docs/TIME_CONTRACT.md` (canonical, 2026-10-18): ownership by arrival order (`Nuxr-Period-Acct-Id`), time rule only as fallback | **Newer** (README V1 quota + time contract) |
| X-24 | `--db-host` for the migration runner | `S:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2 step 6: no `--db-host` | `L:evidence\deploy\d03_postinstall.txt`: exit 2 with `--db-host=127.0.0.1` for a `'…'@'localhost'` account; `d04_migrate_grants.sh` passed with `--db-host=localhost` | Manual adds `--db-host` matching the account host (**lab-verified**); troubleshooting row added |
| X-25 | RADIUS must be enabled before the preflight | Runbook §2 does not mention the `radius_enable` setting | `L:DEFECT_REGISTER.md` ACC-1: preflight FAILs ("check could not run") until RADIUS is enabled; `S:init.php` opens the RADIUS connection only when it is | Manual adds the step (lab-verified by `e2e_02_settings.mjs`) |
| X-26 | NAS menu path | `S:docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2: "Admin → RADIUS → NAS → Add"; README: "RADIUS > NAS" | `S:system/autoload/Reseller/Ui/Navigation.php`: **Network > Radius NAS** (visible only with RADIUS enabled) | **Code** |
| X-27 | UI field "SNMP community" | `S:ui/ui/admin/radius/nas-add.tpl` offers an "SNMP community" field (column `nas.community`) | `S:install/freeradius/README.md` "Credential file": that column is not read by the guard; do not store the guard community there | Manuals tell the operator to **leave it empty** |
| X-28 | Staging flow list and UDP 161 | `S:docs/STAGING_DEPLOYMENT_PACKAGE.md` §3 network matrix (still at `e02d784b`): no UDP 161 | `S:owner-review/WP10/TARGET_AUTHORIZATION_REQUEST.md` §2 (corrected at `e02d784b`), README "NAS contract", NAS document §2: FreeRADIUS → NAS UDP 161 | **Stricter / newer** (UDP 161 required); `L:reports\STAGING_AUTHORIZATION_PACKAGE_V1.md` C-1 |
| X-29 | Separate application and RADIUS databases | `S:docs/BACKUP_RESTORE_RUNBOOK.md`: "they can be separate" | `S:system/reseller/grants.php` handles one database (`DATABASE()`); `S:install/step4.php` sets `$radius_*` = main database; the lab used one database | Manuals document the **single-database layout only** (the executed one); a separate RADIUS database is recorded as undocumented |
| X-30 | Monitoring account for the Hotspot report | `S:install/freeradius/README.md`: the report reads the audit "with the monitoring account" | `S:system/reseller/grants.php` generates no monitoring account | Gap; manuals tell the operator to create a read-only account with `SELECT` on the two tables |
| X-31 | Log rotation | No source specifies it | Lab writes `/var/log/itcnc/*` (`L:scripts\d07_cron.sh`); README cron example writes `/var/log/nuxr-guard-selfcheck.log` | Recorded as a **gap**; no configuration invented |
| X-32 | Self-check on the lab's native host | `S:install/freeradius/README.md`: install `nuxr_guard_selfcheck` next to the guard | `L:scripts\r01_freeradius_install.sh` did not install it (the self-check ran in the harness / CHR suites) | **README** followed; verification level stated as harness / CHR suites |
| X-33 | Configuration check command | README: `freeradius -C` | `S:docs/STAGING_DEPLOYMENT_PACKAGE.md` S7: `radiusd -C`; lab: `freeradius -XC` | Lab-verified `freeradius -XC` used (Ubuntu binary name), `-C` mentioned |
| X-34 | Empty-database protection | Runbook §2 step 2 only says "create the database" | `S:install/phpnuxbill.sql` and `S:install/radius.sql` use `DROP TABLE IF EXISTS`; `S:install/step4.php` imports them without checking; `L:scripts\d01_stage.sh` uses `DROP DATABASE IF EXISTS` (lab only) | Manuals add the **owner's stricter requirement**: prove the database name is unused and the new database empty; never overwrite an unknown database; the lab `DROP DATABASE` is flagged as lab-only |
| X-35 | Installer account | Runbook §2 step 2 names only the migration account; checklist C3 / D3 forbid an administrative account or the migration password in `config.php` | `L:scripts\p1_01_mariadb.sh`, `d02_installer.mjs`: a separate installer account with all privileges on the instance database, replaced later | Lab-verified approach adopted (temporary installer account, replaced at the grants step) |
| X-36 | Default administrator password | Runbook §2 step 11 and checklist C7 do not state it | `S:install/phpnuxbill.sql` seeds SHA-1 of `admin`; `S:system/autoload/Admin.php` `hasDefaultCredential`; `L:scripts\e2e_01_firstlogin.mjs` ("admin/admin") | Stated from code and lab evidence |
| X-37 | Product version string | `S:README.md`, `S:docs/BRAND_IDENTITY.md`: V1.0 | `S:version.json`: `2025.3.20` (upstream version field) | V1.0 is the product version; `version.json` is an internal upstream identifier (BRAND_IDENTITY §4 naming rule) |
| X-38 | HTTPS in the lab | Runbook §2 step 3: run the installer over HTTPS; checklist B1 | Lab ran plain HTTP on loopback (`L:scripts\p1_03_php_nginx.sh`; preflight `stage.https` WARN in `L:evidence\deploy\d05_grants_admin.txt`) | Every HTTPS step labelled **documented (not yet executed on target)**; TA-10 |
| X-39 | `grants.php` timing | Runbook order (migrations → RADIUS tables → grants) | `L:evidence\deploy\d03_postinstall.txt`: grants generated while the migrations had failed left the reseller tables ungranted (health UNKNOWN) | Runbook order kept; troubleshooting row added (lab finding) |

## 3. Items deliberately not invented

- No production nginx server block (none ships; requirements B1–B9 listed instead).
- No certificate authority or tool, no firewall tool syntax for Linux hosts, no log-rotation file.
- No command to change `nuxr_quota_interim_seconds` (X-03).
- No P-A4-3 survey SQL (X-20), no D-2 backfill tool, no OBS-1 merge tool (R3), no Hotspot `PENDING` release.
- No RouterOS command to disable the API services (the sources only require them closed; verification via `/ip service print`, which the lab used).

## Addendum 2026-10-29: reconciliation with the final-prep work

The installation manual (`install_guide_en`, `install_guide_ar`) was rewritten around the fresh-install package; the section names and quick-start rows of §1.1 above refer to the earlier draft. New sources, in addition to those above:

| Topic | Source |
|---|---|
| Application installation (artifact verify, web server examples, env file, dry run, fresh install, post-install verify, deviations), labels "Lab-verified (disposable instance, MariaDB 10.11 + MySQL 8.0)", findings G-1 … G-9 | `LAB:reports/FRESH_INSTALL_PROOF_20261029.md`; `LAB:deliverables/repo_add/install/fresh/README.md` and the package files (staged as `install/fresh/`) |
| Backups in the installation manual | `LAB:reports/BACKUP_PITR_DRILL_20261029.md`; `LAB:deliverables/repo_add/install/backup/README.md` (staged as `install/backup/`) |
| Static IP rule, two-NAS lab result (integration manual) | `LAB:reports/R-HF-CARRY-01_FIX.md` (Round 2); `LAB:reports/review/R-HF-CARRY-01_ROUND2_REVIEW.md` (N-1); `LAB:reports/CHR2_BUILD_AND_TWO_NAS_RESULT.md` |

Gaps of §3 now covered by the package: a production nginx server block, a log-rotation file and a cron file are provided as examples in `install/fresh/` (lab-verified on a disposable instance). The change log of this reconciliation is `RECONCILIATION_20261029.md`.

## Addendum 2026-10-29 (2): independent documentation review

Corrections from `reports/review/DOCS_INDEPENDENT_REVIEW_20261029.md` (list: `REVIEW_CORRECTIONS_20261029.md`). Additional sources:

| Topic | Source |
|---|---|
| Preflight `db.connect` (G-4), `radius_checks` reference files (G-1), `time.clock_skew` thresholds, `identity.non_canonical`, `shared_key` | `SRC:system/reseller/preflight.php` (`bd1e03cc`, `33624129`) |
| Static-IP special classes and refusal text | `SRC:system/autoload/Reseller/Domain/CustomerIdentity.php`, `SRC:system/autoload/Reseller/Domain/DomainError.php` (`b2f7837b`); `SRC:system/lan/arabic.json` (`9b21ebfe`); `LAB:reports/ROUND3_FIXES_20261029.md` |
| Post-install verify: 27 sensitive paths (403 / 404), `/admin/` redirect, repeated header WARN | `SRC:install/fresh/nuxr-post-install-verify.sh` |
| Installer phases (env-file check before phase 1, `pages/` in phase 6), `--installer=remove` | `SRC:install/fresh/nuxr-fresh-install.sh` |
| `quota.php --every` lower bound only | `SRC:system/reseller/quota.php` |
| `nuxr_admission_audit` writer | `SRC:system/reseller/grants.php`; `SRC:system/autoload/Reseller/Radius/AdmissionRecovery.php` |
| Runbook and checklist corrections (cron form, keep `install/freeradius/`) | `SRC:docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md`, `SRC:docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` (`c550d6b8`) |
