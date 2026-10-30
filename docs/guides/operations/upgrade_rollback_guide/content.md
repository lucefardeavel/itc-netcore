# About this guide

This guide describes how to deploy a new ITC NetCore release onto an existing instance and how to roll it back, following `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §1, §3, §4, §5, the backup runbook and the migration runner documentation (`system/reseller/README.md`).

> [!WARNING]
> Release Candidate, not yet production-accepted. **Production deployment, production data migration and production secret changes need the owner's explicit release authorization.** This guide does not grant it. TA-08 (deployment, backup, restore, rollback on the target) has not been run.

> [!NOTE]
> **Scope.** The first V1.0 installation is a fresh install with an empty database (owner decision; see `admin_quickstart`). This guide covers later release upgrades of that instance. Migrating an existing PHPNuxBill database into ITC NetCore is a **separate future path**: it additionally needs the OBS-1 identity survey on an isolated copy, the owner's decision sheet, the `radacct` time-zone survey and separate authorizations (`docs/OBS1_SURVEY_RUNBOOK.md`). Do not use this guide for it.

# 1. Release artifact

1. Built from the owner-accepted commit only; the commit SHA is recorded in the release notes.
2. Excluded from the artifact: `.git/`, `dev/`, `tests/`, `owner-review/`, `docs/claude-development-pack/`, and the vendored test image `system/vendor/yosiazwan/php-facedetection/lena512color.jpg`.
3. Included: `LICENSE` and `docs/THIRD_PARTY_LICENSES.md`.
4. The artifact's SHA-256 is published next to it. **Verify it on the target before unpacking.**

# 2. Before the window

| # | Item |
|---|---|
| P1 | Approved change record (`change_management_escalation`), with the release SHA and the rollback decision owner |
| P2 | Release notes read: new migrations, new FreeRADIUS files, new cron jobs, new checklist rows |
| P3 | Last restore drill is recent (quarterly) and passed |
| P4 | Maintenance window announced to staff, resellers and subscribers |
| P5 | Migration account available (separate DDL account; password from an environment variable, never from `config.php` or the command line) |

# 3. Upgrade procedure

| Step | Action | Verification |
|---|---|---|
| 1 | Stop the scheduled jobs (`cron.php`, `quota.php`, health senders) and put the web server into maintenance (503) for staff and customers | No new activity in the logs |
| 2 | **Take a verified backup** of the application and RADIUS databases (backup runbook §2; `install/backup/nuxr-backup.sh`, which also writes the encrypted `config.php` copy, the manifest and the release in `backup.info`) and record its SHA-256. Back up `config.php` and the FreeRADIUS configuration | Backup record complete. **This backup is the only rollback path once new-domain data exists** |
| 3 | Deploy the new code **next to** the old one (a new release directory), then switch the document root | The old release directory is kept untouched |
| 4 | `php system/reseller/migrate.php preflight`, then `migrate`, then `verify` (same options as the installation) | Each exits 0; a second `migrate` reports nothing to do |
| 5 | Apply any additive FreeRADIUS objects named in the release notes and run their check scripts (for example `nuxr_admission_check.sql`, `nuxr_hs_admission_check.sql`, `nuxr_nasreload_collation_check.sql`) | Every check prints READY |
| 6 | Install updated FreeRADIUS site / module / dictionary files **together** if the release changes them; restart FreeRADIUS | `nuxr_guard_selfcheck` prints `guard_selfcheck=OK` |
| 7 | `php system/reseller/preflight.php` | Exit 0. Resolve every FAIL before reopening, in particular `identity`, `grants` and `time` |
| 8 | Reopen in this order: the web server, then the scheduled jobs | — |
| 9 | Watch `health.php` for 30 minutes | Exit 0 |
| 10 | Post-deployment verification (§5) | All pass |

**Migration runner exit codes**

| Code | Name | Meaning | What to do |
|---|---|---|---|
| 0 | OK | Success or nothing to do | Continue |
| 2 | NOT_READY | Refused before any change (unknown core schema, privileges, lock held, a partial state that requires rollback, or the rollback data guard) | Fix the cause and retry; nothing was changed |
| 3 | FAILED | A step or postflight failed; the partial state is recorded and blocks everything except `rollback` | Go to §4 |
| 4 | DRIFT | An applied object or migration file no longer matches its contract or checksum | Stop; escalate to ITC |
| 5 | USAGE | Invalid command, option or configuration | Correct the command |

> [!WARNING]
> Never run DDL during a backup, never run two migration runners at once (the runner takes a database lock), and never set the `NUXR_MIGRATION_TEST_*` hooks outside the test environment.

<!-- pagebreak -->

# 4. Rollback

Decide with the rollback decision owner named in the change record.

| Situation | Action | Consequence |
|---|---|---|
| `migrate` failed or was interrupted, and **no new-domain data** exists | `php system/reseller/migrate.php rollback --all` (or `--to=<id>`); then redeploy the previous code | Drops only objects the migrations created, in reverse order. The core fingerprint must equal the pre-migration one |
| **New-domain data exists** (any reseller row, or `tbl_customers.manager_id` set) | Schema rollback is **refused** (exit 2, data guard) by design. Restore the pre-upgrade backup into a new database (backup runbook §3), switch to it, and redeploy the previous code | Every operation since the backup is lost and must be reconciled from audit records, payment-provider records and NAS-side accounting |
| Code-only problem after a successful migration | Switch the document root back to the previous ITC NetCore release directory (same schema) | No data loss. **Never** redeploy upstream PHPNuxBill over a migrated database |
| A rollback step failed (`ROLLBACK_FAILED`) | Run `rollback` again; it resumes and skips objects already removed | Escalate to ITC if it fails again |
| FreeRADIUS change only | Reinstall the previous site / module files and restart FreeRADIUS | Admission tables may stay; they hold admission state only |

After any rollback: `preflight.php` exit 0, `health.php --finance` exit 0, and the §5 checks.

> [!FINANCE-WARNING]
> A restore-based rollback brings back activations that were in flight at backup time with ACTIVE reservations. They are resolved by the operator procedures; nothing is released automatically, and the ledger is never edited to match.

# 5. Post-deployment verification

- [ ] One staff login per role (SuperAdmin, Admin, Agent, Sales, Report)
- [ ] One activation on a test customer, and its reversal if the ISP's policy allows
- [ ] One PPPoE and one Hotspot login on a test identity, its accounting, and one Disconnect
- [ ] `health.php --finance` exit 0
- [ ] `preflight.php` exit 0
- [ ] `nuxr_guard_selfcheck` OK
- [ ] Release SHA, backup SHA-256 and results recorded in the change record

# 6. Lab evidence (not target evidence)

In the lab the release switch and its reverse each took about 0.05 s, with admin, login, brand assets and health checked after each step; the pre-deploy backup drill passed (`reports/FINAL_RELEASE_REPORT_20261029.md` §11). These are lab-scale results only.
