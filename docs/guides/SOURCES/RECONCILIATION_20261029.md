# Documentation reconciliation with the final-prep work, 2026-10-29

**Scope:** `deliverables/docs/` and `deliverables/repo_add/` (README, docs index, release notes, changelog entry) only. Nothing in the product worktree, in `deliverables/docpipe/` or in the staged packages `repo_add/install/backup/` and `repo_add/install/fresh/` was changed.

**Inputs (nothing beyond these was cited):**

| Fact | Source |
|---|---|
| Backup and PITR tooling, lab drill on both engines; RTO 4 h / RPO 15 min stay provisional; option 2 implemented, option 3 not | `reports/BACKUP_PITR_DRILL_20261029.md`; `repo_add/install/backup/README.md` |
| Fresh-install package proven on a disposable instance on both engines; findings G-1 … G-9; deviations | `reports/FRESH_INSTALL_PROOF_20261029.md`; `repo_add/install/fresh/README.md` |
| R-HF-CARRY-01 rounds 1–2 (`0be31b63`, `228f01c9`, `e8260df7`), round 3 in progress, status OPEN (*superseded:* round 3 committed in `b2f7837b` and `9b21ebfe`; status still OPEN, see §5) | `reports/R-HF-CARRY-01_FIX.md`; `reports/review/R-HF-CARRY-01_SECURITY_REVIEW.md`; `reports/review/R-HF-CARRY-01_ROUND2_REVIEW.md` |
| UI / i18n fixes (`012a9e91`) | `reports/UI_I18N_FIXES_20261029.md` |
| Two-NAS test on a local CHR2 (lab only; X-1 still required); UX-2P closed in the lab | `reports/CHR2_BUILD_AND_TWO_NAS_RESULT.md`; task brief (UX-2P) |
| First installation = fresh install, empty database | Owner decision |
| Callout syntax and required `meta.json` fields | `docpipe/README.md`; `docpipe/lib/mdparse.py` (`CALLOUT_KINDS`); `docpipe/lib/common.py` (`REQUIRED_META`) |

**Canonical R-HF-CARRY-01 wording** (used in `known_limitations`, `known_limitations_ar`, the security checklist, the incident guide, the release notes and the changelog): **OPEN, owner decision**. Fixed on the final-prep branch, with lab evidence on both engines: the `autoload` PPPoE existence oracles (`0be31b63`), the `customers/add-post` existence oracle for scoped staff (`228f01c9`), and static-IP validation and uniqueness on save for every role (`e8260df7`). A third round (reserved and special IPv4 ranges) is in progress (*superseded:* implemented in `b2f7837b`, Arabic refusal details in `9b21ebfe`; see §5). Still open: **low** unauthenticated enumeration (forgot-password / forgot-username, self-registration) and **accepted-level** residuals.

**Behaviour change, stated wherever operators meet it:** a static IP must be a valid, non-reserved IPv4 address and unique across all customers. Agents and Sales see one generic refusal when an identity is taken. The rejection of reserved ranges is marked as part of the round in progress (*superseded:* now stated as in force since `b2f7837b`; see §5).

## 1. Pipeline conformance (all `deliverables/docs/*/content.md` and `meta.json`)

| Change | Detail |
|---|---|
| Callouts converted to the docpipe syntax | 179 single-line `> **Note:** …`, `> **Warning:** …`, `> **Security:** …` and `> **Finance:** …` callouts became two-line `> [!NOTE]`, `> [!WARNING]`, `> [!SECURITY-WARNING]` and `> [!FINANCE-WARNING]` blocks, with the default (localised) title. New callouts were written in the same syntax. After the change, `mdparse.parse` sees 237 callouts in the 30 documents, no plain quotations, and no leftover old-style callouts |
| `meta.json` (all 30 documents) | `sha` and `source_sha` set to `"final-prep (release SHA recorded at build)"`. `source_sha` was added where it was missing (21 documents); without it the pipeline refuses to build (`REQUIRED_META`). `version` changed from `1.0 RC` to `V1.0 RC` (brand rule in `docpipe/README.md`). Every document now passes `common.load_doc` |

## 2. Content changes, per file

| File | Why |
|---|---|
| `known_limitations/content.md` | About: verification basis now names the final-prep commits and the 2026-10-29 reports. §1: new row for the final-prep fixes (targeted dual-engine runs; browser matrix and CHR suites not re-run). §2: two-NAS result made precise (passed on both engines on CHR2, X-1 still required); new rows for the backup / PITR drill and the fresh-install proof. §4: "PITR to be delivered" replaced (option 2 implemented and drilled; off-host target, archive-age monitoring, interval choice, TA-08 open; option 3 not implemented); new rows for the paths not drilled and for the CLI fresh install plus `install/freeradius/` (G-1); RTO / RPO still provisional. §5.4: R-HF-CARRY-01 rewritten to the canonical wording. §7: UX-2P closed in the lab; login rate limit in the nginx example; new rows for the static-IP rule and the generic refusal |
| `known_limitations_ar/content.md` | The same changes in Arabic |
| `backup_restore_guide/content.md` | Rewritten around `install/backup/`: the tooling replaces the "[To be delivered with the release]" items. Covers installation, what a set contains, retention and the off-host hook (real target **[Still open]**), restore with its safety checks and copy verification, PITR (server settings, archiving, `--stop-datetime` / `--stop-position` / `--pitr-to-end`, the unsafe-statement refusal, the engine difference), drills including the weekly restore test, lab results with their caveats, the exit codes and the troubleshooting pointers. Key-custody callout added. RTO / RPO stay provisional; TA-08 NOT_RUN |
| `disaster_recovery_guide/content.md` | Option 2 marked as implemented and lab-drilled; indicative RPO / RTO drivers use the lab figures with caveats; the 15-minute RPO needs a ≤ 15 min interval **and** a monitored off-host copy. Owner fill-in gets an archive-interval / off-host row. Database-loss scenario uses `nuxr-restore.sh`; new warning on logical errors (never replay binlogs by hand). Host-loss scenario: artifact verify, `config.php` from the encrypted `secrets.tar`, restore onto a fresh server not drilled, cron and backup timers re-installed. After-recovery checklist: new full backup |
| `install_guide_en/content.md` | Rewritten so the `install/fresh/` package is THE procedure: artifact verify, web-server examples, env file, dry run, `nuxr-fresh-install.sh`, then post-install verify. Steps covered by the proof are labelled **Lab-verified (disposable instance, MariaDB 10.11 + MySQL 8.0)**; target items stay **Documented (not yet executed on target)**. Quick-start checklist rebuilt (18 rows, with a verification-level column). Web-installer procedure and manual `CREATE DATABASE` / `CREATE USER` steps removed; "Deviations from the product runbook" added (G-7, G-1, G-2, ACC-1 / G-3, `--db-host`, pinned `APP_URL`). G-1 warning about `install/freeradius/`; G-2 cron form; G-4 preflight fatal error (fix in progress); G-5 `--every` ≥ 5; logrotate and cron examples; backups through `install/backup/`; troubleshooting and exit-code appendices updated; `e02d784b` replaced by "release SHA recorded at build" |
| `install_guide_ar/content.md` | The same rewrite in Arabic, label «مُتحقَّق منه مخبريًا (نسخة مؤقتة قابلة للإزالة، MariaDB 10.11 وMySQL 8.0)» |
| `radius_mikrotik_guide_en/content.md` | Verified first: the PR #7 / SNMP items are already present (UDP 161 flow from FreeRADIUS only, `/snmp` community and firewall rules, guard self-check, `nas.verification` and `time.nas_clock` in the release preflight). Added: the static-IP rule (valid IPv4, unique across customers; reserved ranges in round 3; never assign special addresses such as `255.255.255.255`; keep static addresses out of pools and away from NAS / gateway addresses), and a lab-only note on the CHR2 two-NAS pass (X-1 stays open). Cross-reference to the installation manual's renamed grants section |
| `radius_mikrotik_guide_ar/content.md` | The same additions in Arabic |
| `security_hardening_checklist/content.md` | Warning notes which rows the post-install verify checked in the lab. W3: nginx example and 28 sensitive paths (*corrected:* 27 paths, 403 or 404; see §5). W4: web installer stripped, `install/freeradius/` kept read-only and web-blocked (G-1). W9: login-POST rate limit in the example. New rows: S9 (env file 0600, deleted after install), D8 (backup / restore accounts), C6 (R-HF-CARRY-01 OPEN; self-registration off unless needed, as recommended practice), C7 (review of refused-create log lines), B6 (key custody), B7 (0600 option files, encrypted off-host transport); B1 / B2 name the tooling |
| `admin_quickstart/content.md` | §2 installation sequence rewritten around the package, with a lab-verified note for steps 1–6 and warnings on G-1 and on the web installer. Header table points to `install/fresh/`, `install/backup/` and the full manual. Settings step 3: RADIUS already stored by the installer. §6 scheduled jobs: `cd system && php cron.php` (G-2), backup / binlog-archive / restore-test jobs, `--every` ≥ 5 |
| `admin_quickstart_ar/content.md` | The same changes in Arabic |
| `incident_troubleshooting_guide/content.md` | Sources list the two package READMEs. §1: rate limit in the nginx example. §3: the silent no-op of `cron.php` from the root (G-2). §10 rewritten around the backup-tool exit codes (no preflight on a restored copy). New §11 (customer creation refused: identity or static IP; generic refusal for scoped staff; log lines for probing) and §12 (fresh installation refused or failed: exits 2 / 3 / 4 / 5, G-4) |
| `training_manual_en/content.md` | Label fixes of `012a9e91`: "FreeRADIUS" settings section, "Add Vouchers" button, translated default plan titles (the "Radius Plan" remark removed); note that the screenshots predate the fixes. Add-subscriber step: static-IP rule; common mistakes now describe the generic refusal for Agents / Sales and the invalid-IP refusal (text from `STATIC_IP_INVALID`), replacing the outdated quoted message. §9.10 backup failure aligned with the tooling |
| `training_manual_ar/content.md` | The note on duplicate Arabic labels (formerly listed as a product defect) replaced by the distinct labels «شحن العميل بقسيمة», «إعادة شحن اشتراك العميل», «شحن الحساب بقسيمة», «القسائم», «الكوبونات»; «إضافة قسائم» button; portal menu item «قسيمة» instead of the English "Voucher" (three places, including exercise 7); "FreeRADIUS" section; translated default plan title «خطة Radius»; staff service table uses the new labels; static-IP rule and generic refusal; §9.10 backup failure aligned with the tooling; note that the screenshots predate the fixes |
| `subscriber_onboarding/content.md` | PPPoE field row: static-IP rule. Collision note: the static IP is included, the generic refusal for Agents / Sales, specific reason for Admins |
| `subscriber_onboarding_ar/content.md` | The same, plus the new Arabic labels next to the English menu names Recharge Customer and Refill Customer |
| `operator_daily_checklist/content.md` | Backup checks D17–D19 use the tooling's results and records; new D20 (binlog archive age, chain gap); W3 includes archived binlogs; new W3a (weekly restore test) |
| `operator_daily_checklist_ar/content.md` | The same in Arabic |
| `monitoring_alerting_guide/content.md` | §6: alert on the backup-tool exit codes and on the age of the newest set and archived binlog |
| `upgrade_rollback_guide/content.md` | Pre-upgrade backup step names `nuxr-backup.sh` |
| `TECH_DOCS_SOURCES.md` | Addendum: new sources of the rewritten installation manual and the integration-manual additions; §3 gaps now covered by package examples |
| `OPS_DOCS_SOURCES.md` | Addendum: §5 "tooling to be delivered" superseded by `install/backup/`; new sources listed |
| `TRAINING_VERIFICATION.md` | Addendum: the §4 label issues and the portal header defect are fixed by `012a9e91`; the manuals now quote the corrected labels; the screenshots still predate the fix |
| `RECONCILIATION_20261029.md` | This change log (new) |

Callout and `meta.json` changes only (§1): `change_management_escalation`, `financial_reconciliation_guide`, `migration_guide_en`, `one_pager_en`, `one_pager_ar`, `product_profile_en`, `product_profile_ar`, `reseller_onboarding`, `reseller_onboarding_ar`, `templates_support_agreement`. No stale statement about the new facts was found in them. `product_profile_*` already names the backup and PITR tooling. `templates_support_agreement` already offers the three recovery options.

## 3. `deliverables/repo_add/`

| File | Why |
|---|---|
| `README.md` | Arabic overview: first install with `install/fresh/`. Verified capabilities: two-NAS result (lab only, X-1 required); the fresh-install, PITR and R-HF-CARRY-01 / UI final-prep evidence. Quick start rewritten around the package (no web installer). Security table gets a fresh-installation row; login rate limit in the nginx example; R-HF-CARRY-01 open. Release status: open owner decisions named |
| `docs/README.md` | Index: `install/fresh/` folder and README, `install/backup/README.md`; install-guide description names the package |
| `docs/release/RELEASE_NOTES_V1.0.md` | Release-prep tree names the final-prep commits; release SHA recorded at build. Operations: backup tooling (option 2, option 3 not) and the fresh-install package. Fixes: R-HF-CARRY-01 in the canonical wording (status OPEN, three fixes, round 3 in progress, residuals); UI and Arabic label fixes. New *Behaviour changes operators should know*. Verification table: CHR2 two-NAS, targeted runs, fresh-install proof, PITR drill. Installation notes: package, `install/freeradius/`, cron form, backups. Known limitations and gates: recovery option, R-HF-CARRY-01 residuals, owner acknowledgement of the static-IP behaviour changes |
| `docs/release/CHANGELOG_V1.0_entry.md` | Added: both packages. Changed: static-IP rule, generic refusal, distinct Arabic labels, CLI installer. Fixed: the three R-HF-CARRY-01 fixes and the two UI / i18n fixes. Known limitations: R-HF-CARRY-01 stays open |

## 4. Not changed, and open points found

- **Not changed (out of scope):** the package READMEs and scripts in `repo_add/install/backup/` and `repo_add/install/fresh/`, `docpipe/`, the product worktree, the screenshots.
- **Arrow characters will fail the docpipe font check.** `docpipe/README.md` says `→` / `←` are not in the brand font subsets, and font substitution FAILs the check. About 140 remain in the existing text: 89 in `training_manual_ar` (menu paths), 7–9 each in `radius_mikrotik_guide_en`, `financial_reconciliation_guide` and `incident_troubleshooting_guide`, and a few elsewhere. No new arrow was added. Recommended: replace the menu-path arrows with `›` before the build; flows such as "NAS → FreeRADIUS" need case-by-case wording.
- **Screenshots predate `012a9e91`.** Some images still show the old labels or the old portal header. Both manuals now say so, and the 11 product-mode portal images stay unused. Recapture them on the release build. *Resolved later on 2026-10-29:* recaptured on `33624129` into `deliverables/screenshots_final/`; all document images replaced by id and the manuals' note removed (`TRAINING_VERIFICATION.md` §7).
- **Wording to confirm at build:** the reserved-range rejection (R-HF-CARRY-01 round 3) is described as in progress; G-1 and G-4 are described as product fixes pending or in progress. Update these lines when the commits land. *Done on 2026-10-29* after the independent documentation review (§5). Also stamp the release SHA into `meta.json` and into the release notes and changelog.
- **Historical SHAs kept on purpose:** `e02d784b` stays where it describes the documentation baseline or the lab-acceptance record (`known_limitations*` §1 and About), and `228f01c9` where it names the commit the fresh-install proof used.
- **Install-guide renumbering:** the quick-start checklist now has 18 rows. The row numbers cited in `FRESH_INSTALL_PROOF_20261029.md` §8 refer to the earlier 21-row draft.
- **The docpipe build was not run** for this reconciliation. Only the parser (`mdparse.parse`) and `common.load_doc` were exercised, on all 30 documents.

## 5. Corrections after the independent documentation review (2026-10-29)

The independent review `reports/review/DOCS_INDEPENDENT_REVIEW_20261029.md` (tree `e23e7540`) found 5 Major and 18 Minor issues. All were corrected in the staging copies; the per-finding file list is `REVIEW_CORRECTIONS_20261029.md`. Facts now stated, each checked in the source:

- G-1 fixed in `33624129`: preflight `radius_checks` FAILs when `install/freeradius/*.sql` is missing or unreadable. `--installer=remove` would make the installer fail at phase 12 (exit 4); the documents say to keep the default `--installer=strip`.
- G-4 fixed in `bd1e03cc`: `FAIL db.connect`, exit 4 (no PHP fatal error).
- R-HF-CARRY-01 round 3 committed in `b2f7837b` (special IPv4 classes refused; a kept padded IP is not re-judged) and `9b21ebfe` (Arabic refusal details). R-HF-CARRY-01 stays **OPEN** for the owner decision; residuals R3-1 and R3-2 are stated.
- `c550d6b8`: runbook §2 step 4 keeps `install/freeradius/`; checklist I1 / I2 run `cron.php` and `cron_reminder.php` from `system/`. The install guide no longer lists these two points as deviations.
- The fresh-install proof ran on `228f01c9`; the documents now say it will be re-run on the final release commit (it has not been re-run).
- The arrow characters of every `content.md` were replaced (menu paths by `›`, flows reworded), as recommended in §4.
