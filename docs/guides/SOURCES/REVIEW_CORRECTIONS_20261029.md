# Corrections from the independent documentation review, 2026-10-29

**Input:** `reports/review/DOCS_INDEPENDENT_REVIEW_20261029.md` (tree `e23e7540`, verdict ACCEPT WITH CORRECTIONS: 0 Blocker, 5 Major, 18 Minor).

**Scope:** staging copies only: `deliverables/docs/<doc>/content.md` (maps to `docs/guides/<area>/<doc>/`), the source registers `deliverables/docs/*.md` (map to `docs/guides/SOURCES/`), and `deliverables/repo_add/` (`README.md`, `docs/README.md`, `docs/release/*`, `install/fresh/README.md`, and the help text of `install/fresh/nuxr-fresh-install.sh`). The product worktree was not changed. No test, docker, browser or build was run.

**Facts checked in the source (read-only, `e23e7540`):**

- `system/reseller/preflight.php`:
  - l.70-94: `db.connect` FAIL, exit 4 (`bd1e03cc`).
  - l.130: `time.clock_skew` PASS up to 5 s, WARN up to 30 s, FAIL above 30 s.
  - l.164-181: `radius_checks` FAIL when `install/freeradius/*.sql` is missing or unreadable (`33624129`).
  - l.220: `identity.non_canonical`; l.222-228: `shared_key`.
- `install/fresh/nuxr-fresh-install.sh`:
  - the env-file checks run before phase 1;
  - `pages/` is created in phase 6;
  - `--installer=remove` deletes `install/` in phase 11, so the phase-12 preflight fails with exit 4.
- `install/fresh/nuxr-post-install-verify.sh`: 27 sensitive paths, 403 or 404 accepted; `/admin/` must answer 30x; a repeated header is a WARN.
- `DomainError.php`: the `STATIC_IP_INVALID` and `STAFF_PASSWORD_DIRECT_CHILD_ONLY` texts.
- `arabic.json`: the matching Arabic texts, and «التجديد التلقائي غير متاح (سجلات)».
- `StaffService.php:170-176`: password reset for direct children only.
- `system/cron_reminder.php:10`: `include "../init.php"`.
- `system/cron.php:2-4` header and checklist I1 / I2: the `cd <app>/system` form.
- Runbook §2 step 4: keep `install/freeradius/` (`c550d6b8`).
- `quota.php:43`: `--every` has a lower bound of 5 and no upper bound.
- `grants.php`, `AdmissionRecovery.php:77`: `nuxr_admission_audit` is written only by the operator release.
- `variants.php`: the core schema is recognised by its structure, not by the hash of the file.
- `settings.php:992`: the web restore is refused.

## Major findings

| Finding | Correction | Files changed |
|---|---|---|
| **M1** G-1 fixed; `--installer=remove` is not usable | `radius_checks` FAILs without the reference files (`33624129`). `--installer=remove` makes the installer stop at phase 12 (exit 4). Keep the default `--installer=strip`. The troubleshooting row "PASS although missing" is replaced by the FAIL row with the restore action | `install_guide_en`, `install_guide_ar` (warning, phase 11 row, deviations, preflight table, troubleshooting); `admin_quickstart`, `admin_quickstart_ar`; `known_limitations`, `known_limitations_ar` (§1 row, §4 row); `security_hardening_checklist` (W4); `incident_troubleshooting_guide` (§12 exit 4); `repo_add/docs/release/RELEASE_NOTES_V1.0.md` (fixes list, installation notes); `repo_add/docs/release/CHANGELOG_V1.0_entry.md` (Fixed); `repo_add/install/fresh/README.md` ("Differences" row); `repo_add/install/fresh/nuxr-fresh-install.sh` (`--installer=remove` help text: 2 comment lines, same line count, no logic change) |
| **M2** G-4 fixed | `FAIL db.connect` (cause and MySQL error number), exit 4 (`bd1e03cc`). Appendix B: preflight exits 0 / 4 only | `install_guide_en`, `install_guide_ar` (troubleshooting row, Appendix B, new `db.connect` row in the preflight table); `incident_troubleshooting_guide` (§12); release notes; changelog |
| **M3** R-HF-CARRY-01 round 3 committed | Special IPv4 classes are refused on every save path, and a kept padded IP is not re-judged (`b2f7837b`). Arabic refusal details: `9b21ebfe`. R-HF-CARRY-01 stays **OPEN**. Residuals R3-1 and R3-2 are stated. The release notes list `0be31b63`, `228f01c9`, `e8260df7`, `012a9e91`, `b2f7837b`, `9b21ebfe`, `bd1e03cc`, `c550d6b8`, `33624129` and the docs commit `e23e7540` | `radius_mikrotik_guide_en`, `radius_mikrotik_guide_ar` (static-IP rule); `known_limitations`, `known_limitations_ar` (About, §1, §5.4, §7); `incident_troubleshooting_guide` (§11); `security_hardening_checklist` (C6); release notes (tree row, fixes list, behaviour changes, verification table); changelog (Changed, Fixed, Known limitations); `repo_add/README.md` (final-prep evidence line) |
| **M4** `cron_reminder.php` line does nothing | The line is now `cd system && php cron_reminder.php`, run from `system/`. A note points to the installed `/etc/cron.d/itc-netcore` | `admin_quickstart`, `admin_quickstart_ar` (§6); release notes (installation notes) |
| **M5** Agent password reset | Profile edit, disable and reactivate apply to everyone below you; reactivation excludes an account an administrator disabled. Password reset applies to **direct** children only. The refusal text is quoted | `training_manual_en`, `training_manual_ar` (§3.7) |

## Minor findings

| Finding | Correction | Files changed |
|---|---|---|
| **m1** | The checklist, the runbook and the `cron.php` header now agree with the package (`c550d6b8`). The two "deviation" rows (install/ removal, cron form) are removed and replaced by one sentence. The cron warning no longer says the checklist gives the wrong form | `install_guide_en`, `install_guide_ar`; `repo_add/install/fresh/README.md` ("Differences" row). **Not changed:** the comment in `repo_add/install/fresh/cron/itc-netcore.cron.example:12`, because that file is outside the allowed scope; it is left for the repository sync |
| **m2** | `install/fresh/` is part of the release commit. The note adds that the proof on `228f01c9` predates that commit | `install_guide_en`, `install_guide_ar` |
| **m3** | 27 sensitive paths, 403 / 404 | `install_guide_en`, `install_guide_ar` (Web row, verification level); `security_hardening_checklist` (W3); `RECONCILIATION_20261029.md` (§2 row annotated). **Not changed:** `reports/FRESH_INSTALL_PROOF_20261029.md` (out of scope) |
| **m4** | `/admin/` redirects (30x) to `?_route=admin/`, which answers 200. A repeated header is a WARN | `install_guide_en`, `install_guide_ar` |
| **m5** | The env-file checks run before phase 1. Phase 6 creates `pages/` and removes `pages_template/`. Phase 2 and phase 4 rows corrected | `install_guide_en`, `install_guide_ar` |
| **m6** | The schema is recognised by comparing it with registered variants (`V1_FRESH_INSTALL`), not by the file hash | `install_guide_en`, `install_guide_ar` |
| **m7** | `time.clock_skew`: PASS up to 5 s, WARN 6-30 s, FAIL above 30 s. `time.nas_clock` thresholds added | `install_guide_en`, `install_guide_ar` (preflight table, troubleshooting) |
| **m8** | "check could not run" is caused by the `$radius_*` settings or the RADIUS tables / grants, not by `radius_enable` (G-3) | `install_guide_en`, `install_guide_ar`; `repo_add/install/fresh/nuxr-fresh-install.sh` (`--no-enable-radius` help text, same line count) |
| **m9** | The current `STATIC_IP_INVALID` text is quoted (EN). The AR edition quotes the `arabic.json` text | `training_manual_en`, `training_manual_ar`; `incident_troubleshooting_guide` (§11 Admin example) |
| **m10** | «التجديد التلقائي غير متاح (سجلات)», as in `arabic.json:1356` | `training_manual_ar` (2 places) |
| **m11** | Restart FreeRADIUS after a NAS change; do not rely on a reload | `training_manual_en`, `training_manual_ar` (§8.2 note) |
| **m12** | The README describes the illustrated training manual (AR primary, EN) and points to `docs/guides/operations/` for the quick start and onboarding guides | `repo_add/README.md` |
| **m13** | SuperAdmin: web backup download only. Restore is at database level (`install/backup/nuxr-restore.sh`); the web restore is refused on reseller installations | `repo_add/README.md` (roles table); `product_profile_en`, `product_profile_ar` |
| **m14** | Links into `owner-review/`, `tests/`, `docs/claude-development-pack/` and `dev/` are marked "source repository only (not in the release artifact)" | `repo_add/README.md` (3 links); `repo_add/docs/README.md` (6 links, plus the `dev/docpipe/` row reworded the same way) |
| **m15** | `--every`: at least 5 (design range 5-15; below 5 exits 5) | `radius_mikrotik_guide_en`, `radius_mikrotik_guide_ar` |
| **m16** | `nuxr_admission`: written by FreeRADIUS and the operator release. `nuxr_admission_audit`: written by the operator release only. The table row is split | `radius_mikrotik_guide_en`, `radius_mikrotik_guide_ar` |
| **m17** | The DR guide now prefers to keep or co-locate the CoA source address. The second-`/radius`-entry variant is marked not lab-verified | `disaster_recovery_guide` (§4 step 5; English only, no AR edition exists) |
| **m18** | Preflight table: `db.connect`, `identity.collisions` / `identity.non_canonical`, `shared_key` | `install_guide_en`, `install_guide_ar` |

## Other instructions applied

| Item | Files changed |
|---|---|
| Statement that the fresh-install proof ran on `228f01c9` and will be re-run on the final release commit (the documents do not claim a re-run) | `install_guide_en`, `install_guide_ar` (label definition, lab-proof note); `admin_quickstart`, `admin_quickstart_ar` (lab note); `known_limitations`, `known_limitations_ar` (§2 row); release notes (verification table); `repo_add/README.md` |
| Arrow characters U+2192 / U+2190 removed from every `deliverables/docs/*/content.md` (141 before, 0 after). Menu paths now use `›`, which covers the 90 in `training_manual_ar`. Flow arrows were reworded ("review, then confirm", "NAS to FreeRADIUS", "result `SUCCESS`") or replaced by `›` for step sequences | `admin_quickstart`, `admin_quickstart_ar`, `change_management_escalation`, `disaster_recovery_guide`, `financial_reconciliation_guide`, `incident_troubleshooting_guide`, `known_limitations`, `radius_mikrotik_guide_en`, `radius_mikrotik_guide_ar`, `reseller_onboarding`, `reseller_onboarding_ar`, `security_hardening_checklist`, `training_manual_en`, `training_manual_ar` |
| Source registers | `RECONCILIATION_20261029.md` (superseded markers on the round-3, G-1 / G-4 and 28-path lines, plus a new §5); `TECH_DOCS_SOURCES.md` (addendum with the new source references) |

## Checks run (read-only)

- Every changed `content.md` loads with `docpipe/lib/common.load_doc` and parses with `mdparse.parse`.
- Block-count changes compared with the pre-edit copies:
  - `admin_quickstart` and `admin_quickstart_ar`: +1 callout each (the cron note);
  - `install_guide_en` and `install_guide_ar`: +1 paragraph each (the "no longer deviations" sentence);
  - every other changed document: no change.
- `bash -n` passes on `nuxr-fresh-install.sh`. Its diff against the pre-edit copy touches only comment lines 22-25, so the `usage()` range `2,49p` is unchanged.
- The docpipe PDF build was **not** run.

## Not changed / remaining

- **Arrows outside `content.md` (not part of the PDF build):** they stay in `repo_add/README.md` (4), `repo_add/docs/release/RELEASE_NOTES_V1.0.md` (3: two on l.33, one on l.38), `OPS_DOCS_SOURCES.md` (2), `RECONCILIATION_20261029.md` (3, older text), `TECH_DOCS_SOURCES.md` (7) and `TRAINING_VERIFICATION.md` (6). Replace them too if any of these files is rendered with the brand fonts.
- **`nuxr-fresh-install.sh` l.500:** the runtime `say` message for `--installer=remove` still says "radius_checks can no longer compare". It is a log message, not help text, so it was left unchanged under the no-logic-change rule.
- **R-HF-CARRY-01:** stays OPEN for the owner decision. No document closes it.
