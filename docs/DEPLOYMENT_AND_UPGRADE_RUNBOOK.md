# Deployment and upgrade runbook (WP10)

Scope: one isolated instance per ISP (Option A). Every step names its verification, and a failed verification stops the run.
- Configuration requirements: `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md`.
- Backups: `docs/BACKUP_RESTORE_RUNBOOK.md`.
- Recovery of business states: `docs/OPERATOR_RECOVERY_RUNBOOK.md`.

**Production deployment, production data migration and production secret changes need the owner's explicit release authorization.** This runbook does not grant it.

## 1. Release artifact

1. Build from the owner-accepted commit only, and record the commit SHA in the release notes.
2. Exclude from the artifact:
   - `.git/`, `dev/`, `tests/`, `owner-review/`, `docs/claude-development-pack/`, `CLAUDE.md` (development rules);
   - `system/vendor/yosiazwan/php-facedetection/lena512color.jpg`.
3. Include `LICENSE` and `docs/THIRD_PARTY_LICENSES.md`.
4. Publish the artifact's SHA-256 next to it, and verify it on the target before unpacking.

## 2. New installation

1. Prepare the platform: checklist §A, §B.
2. Create the database (utf8mb4) and the **migration** account (administrative, DDL).
3. Run the web installer once over HTTPS, steps 1–5. It writes `config.php` and seeds the legacy schema.
4. Remove the web installer from the document root: `install/*.php`, `install/css/`, `install/img/`, and `install/*.sql` once step 7 has loaded `install/radius.sql`. It already refuses to run once installed. **Keep `install/freeradius/`**: read-only for the PHP user and blocked by the web server (checklist B3). Preflight `radius_checks` reads the expected admission CHECK definitions from it, so removing all of `install/` weakens that check (a tampered constraint is no longer detected).
5. Edit `config.php`:
   - set `$app_secret` and `$api_secret` (checklist §C);
   - do **not** set `$legacy_devices_enabled`;
   - set `$_app_stage = 'Live'`.
6. Apply the reseller schema with the migration account:

   ```bash
   php system/reseller/migrate.php preflight --config=config.php --db-user=<migration user> --db-password-env=NUXR_MIG_PW
   php system/reseller/migrate.php migrate   --config=config.php --db-user=<migration user> --db-password-env=NUXR_MIG_PW
   php system/reseller/migrate.php verify    --config=config.php --db-user=<migration user> --db-password-env=NUXR_MIG_PW
   ```

   Each command must exit 0. The password comes from an environment variable, never from the command line.
7. RADIUS database:
   - load `install/radius.sql`, `install/freeradius/nuxr_admission.sql` and `install/freeradius/nuxr_hs_admission.sql`;
   - run `nuxr_admission_check.sql` and `nuxr_hs_admission_check.sql`; both must report READY.
8. Least-privilege accounts:
   - `php system/reseller/grants.php --app="'app'@'host'" --radius="'radius'@'host'"`;
   - review the output, create the accounts, and put the application account into `config.php` (checklist §D).
9. FreeRADIUS: checklist §G.
10. NAS: `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2.
11. Log in as `admin`. The application forces the default password change.
12. Run `php system/reseller/preflight.php` (the release preflight). It must exit 0; with no NAS it FAILs `nas.verification` NOT_CONFIGURED (PF-0). Before step 10, `preflight.php --setup` may be used to check the rest of the installation: it reports a missing NAS as WARN NOT_CONFIGURED and is never the release gate. Then run `php system/reseller/health.php --finance`. It must exit 0.
13. Install the scheduled jobs: checklist §I. `cron.php` and `cron_reminder.php` run from `system/` (`cd <app>/system && php cron.php`); `php system/cron.php` from the application root does nothing. The quota enforcer's `--every` is at least 5.

## 3. Upgrade of an existing legacy installation

The reseller migrations are additive. Legacy tables keep their data, except for the columns that the migration contract adds to `tbl_customers`. The drill evidence for this is in `owner-review/WP10/evidence/drill/`.

1. Announce a maintenance window.
2. Stop the scheduled jobs (`cron.php`) and put the web server into maintenance (503) for staff and customers.
3. **Take a verified backup** (`docs/BACKUP_RESTORE_RUNBOOK.md` §2) and record its SHA-256. This backup is the only rollback path once new-domain data exists.
4. Deploy the new code next to the old one, then switch the document root.
5. Run `migrate.php preflight`, then `migrate`, then `verify` (§2 step 6).
   - A second `migrate` must report nothing to do.
   - Exit codes:
     - **2** NOT_READY: nothing was changed. Fix the cause and retry.
     - **3** FAILED: only `rollback` may run. See §4.
   - In the RADIUS database, apply the additive FreeRADIUS objects of §2 step 7 that the old installation lacks (`nuxr_admission.sql`, `nuxr_hs_admission.sql`) and `install/freeradius/nuxr_nasreload_collation.sql`.
     - All three are idempotent. The collation script fixes `nasreload` tables created before B-L09; without it, a MySQL 8 default-collation database fails every login with ERROR 1267.
     - `nuxr_admission_check.sql`, `nuxr_hs_admission_check.sql` and `nuxr_nasreload_collation_check.sql` must all print READY.
6. Run `preflight.php`. Resolve every FAIL before reopening, in particular:
   - `identity` (OBS-1 duplicates; owner-decided resolution);
   - `grants`;
   - `time`.
7. Reopen in this order: the web server, then the scheduled jobs. Watch `health.php` for 30 minutes.

## 4. Rollback

| Situation | Action |
|---|---|
| `migrate` failed or was interrupted, and no new-domain data exists | `php system/reseller/migrate.php rollback --all` (or `--to=<id>`). It drops only the objects the migrations created, in reverse order. The core fingerprint must equal the pre-migration one. |
| New-domain data exists (any reseller row, or `tbl_customers.manager_id` set) | Schema rollback is **refused** (exit 2, data guard), by design. Recover by restoring the pre-upgrade backup (`docs/BACKUP_RESTORE_RUNBOOK.md` §3) and redeploying the previous code. Every operation since the backup is lost and must be reconciled from the audit export. |
| Code-only problem after a successful migration | Redeploy the previous release of this fork with the same schema (the migrations are part of the release history). Never redeploy legacy over a migrated database. |

## 5. Post-deployment verification
- one staff login per role;
- one activation on a test customer (and its reversal, if the policy allows);
- one PPPoE and one Hotspot login on a test identity, its accounting, and one Disconnect;
- `health.php --finance` exits 0.
