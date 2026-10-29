# About this guide

This quick-start takes an ISP administrator from an empty server to a first working ITC NetCore V1.0 instance. It condenses the authoritative runbooks shipped with the product and points to them for every detail. When this guide and a runbook differ, the runbook in the product tree wins.

> [!WARNING]
> ITC NetCore V1.0 is a **Release Candidate**. The release state is `RELEASE_BLOCKED_WITH_EVIDENCE` and the lab verdict is `LAB_ACCEPTED_WITH_LIMITATIONS`. The target acceptance tests TA-01 … TA-10 have not been run, the legal review LIC-1 is pending with counsel, and several owner decisions are open. Production deployment needs the owner's explicit release authorization; this guide does not grant it.

> [!NOTE]
> **Owner decision:** the first installation is a **fresh install with an empty database**. Migration of an existing PHPNuxBill database is a separate, future path (deployment runbook §3 and the OBS-1 survey runbook). It is not covered here.

| Item | Value |
|---|---|
| Deployment model | One isolated instance per ISP (Option A). No shared database, no multi-tenancy |
| Authoritative runbooks | `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md`, `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md`, `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md`, `install/freeradius/README.md` |
| Installation and backup packages | `install/fresh/` (fresh-install package, `install/fresh/README.md`) and `install/backup/` (backup and point-in-time recovery tooling, `install/backup/README.md`) |
| Full procedure | `install_guide_en` (Fresh Installation Manual) |
| Roles that administer | SuperAdmin (everything) and Admin (everything except code, backup and restore surfaces) |

# 1. Prerequisites

| # | Requirement | How to check |
|---|---|---|
| 1 | PHP 8.2 with `pdo_mysql`, `curl`, `gd`, `mbstring`, `zip` | `php -v`, `php -m` |
| 2 | MariaDB 10.11 or MySQL 8.0 (the tested engines) | preflight `engine` |
| 3 | Global isolation `REPEATABLE-READ`; global `time_zone = '+00:00'` | preflight `isolation`, `time.sql_session_zone` |
| 4 | MariaDB only: `check_constraint_checks = ON` globally | preflight `radius_checks` |
| 5 | NTP on the application, database and FreeRADIUS hosts (skew under 5 s) and on every NAS (10 s hard limit, keep it under 2 s) | `chronyc tracking`; preflight `time` |
| 6 | FreeRADIUS 3.2.8 (the only version validated with the PPPoE session guard) | `freeradius -v` |
| 7 | MikroTik RouterOS as the NAS (validated in an isolated CHR 7.24.5 lab only) | TA-03 / TA-04 on the target |
| 8 | HTTPS front with a valid certificate; the database not reachable from the internet | checklist §A7, §B |

# 2. Installation sequence (fresh install)

The installation uses the **`install/fresh/` package**: verify the artifact, run `nuxr-fresh-install.sh`, then run `nuxr-post-install-verify.sh`. The package replaces the web installer and performs the post-installer steps of `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2. Every step has a verification; a failed verification stops the run. The full procedure is in `install_guide_en`.

> [!NOTE]
> **Lab-verified (disposable instance, MariaDB 10.11 + MySQL 8.0):** steps 1–6 below ran end to end on a disposable Docker instance on both engines: install exit 0, an identical database after a second run, post-install verify 67 PASS / 0 WARN / 0 FAIL, and 8 / 8 refusal cases per engine. Steps 7–12 are **documented (not yet executed on target)**; the target acceptance tests TA-01 … TA-10 have not been run.

1. **Verify the artifact.** `nuxr-artifact-verify.sh --artifact=<tarball> --expected-sha=<release SHA> --extract-to=<dir>` checks the SHA-256 against `SHA256SUMS`, the archive safety rules, `RELEASE_SHA` and the content rules, then unpacks into a new, empty directory. Unpack it twice: once as the kept copy `<artifact-dir>` (outside the document root; run the package from `<artifact-dir>/install/fresh/`) and once as the release that becomes the document root (`current` symbolic link).
2. **Platform.** Checklist §A and §B: PHP 8.2 with the extensions, PHP-FPM, nginx, the database client, and the engine settings of section 1. Install `php-fpm/pool.conf.example` and `nginx/itc-netcore.conf.example` from the package (HTTPS only, HSTS, the security headers, blocked paths, a login rate limit), then `nginx -t`.
3. **Parameters and secrets.** `nuxr-fresh-install.sh --generate-secrets=<new env file>` writes a 0600 file with generated passwords; fill in the empty lines. No secret is ever printed.
4. **Dry run.** `nuxr-fresh-install.sh --app-root=<document root> --env-file=<env file> --create-db --install-cron --dry-run` is read-only and must end with `dry_run=PASS`.
5. **Fresh install.** The same command without `--dry-run`. It proves the database is **empty** (or creates it with `--create-db`) and refuses anything else with exit 3 before any change. It refuses accounts that already exist, then creates the accounts, loads the base schema, writes `config.php` (new `$app_secret` / `$api_secret`, stage `Live`, a fixed `https://` `APP_URL`, the least-privilege runtime account, mode 0640), runs `migrate.php` (`status`, `preflight` with variant `V1_FRESH_INSTALL`, `migrate`, `verify`), loads the admission tables (three READY checks), stores `radius_enable`, generates, reviews and applies the grants, sets ownership and modes, removes the web installer, installs the cron file, and runs `preflight.php --setup` and `health.php --finance`. Exit 0 means installed and verified. A second run changes nothing.
6. **Post-install verification.** `nuxr-post-install-verify.sh --app-root=<document root> --base-url=https://<host>` checks the web boundary (redirect, HSTS, headers, cookie flags, blocked paths, `update.php` 403), files and modes, the tools and the cron file. It must exit 0.
7. **First login and password change** (section 3).
8. **FreeRADIUS.** Checklist §G and `install/freeradius/README.md`: shipped site and modules, `delete_stale_sessions = no`, `radpostauth` off, `read_clients = yes`, FreeRADIUS `TZ` = the application time zone, threaded (never `-X`), the PPPoE session guard and its credential file. The RADIUS SQL files are in `<artifact-dir>/install/freeradius/`.
9. **NAS onboarding.** `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2 (section 4 below).
10. **Release preflight and health.** `php system/reseller/preflight.php` (without `--setup`) must exit 0 (it exits 4 when any check FAILs); `nuxr-post-install-verify.sh --release` runs the same gate. Then `php system/reseller/health.php --finance` must exit 0.
11. **Backups.** Install `install/backup/` (nightly backup, binlog archive every 5 minutes, weekly restore test) and run a restore drill (`backup_restore_guide`).
12. **Clean up.** Move the env-file secrets into the secret store and delete the file.

> [!WARNING]
> **Keep `install/freeradius/` in the document root, read-only and blocked by the web server** (the package default `--installer=strip` does this, and the nginx example denies `install/`). Preflight `radius_checks` reads its expected CHECK expressions from it; if it is removed, a tampered admission CHECK constraint passes the preflight (finding G-1, product fix pending). Do not use `--installer=remove` until that fix is released.

> [!WARNING]
> **Never use the web installer** (`install/index.php` … `step5.php`) for V1: it does not check that the database is empty, and its SQL files begin each table with `DROP TABLE IF EXISTS`. Pointed at a database that holds data, it destroys it.

> [!SECURITY-WARNING]
> Never put a password or secret on a command line, in shell history, in a ticket, in chat or in a log. Use protected files (mode 0600) or environment variables.

<!-- pagebreak -->

# 3. First login

1. Open the staff login (`/admin` redirects to `?_route=admin/`) and log in as `admin`.
2. While the installer's default password is in use, every staff route redirects to the password change. Change it.
3. If the reseller schema is not migrated, schema-dependent work fails closed for every role and SuperAdmin / Admin see a "domain not ready" banner. Go back to step 5 of section 2 (re-run the installer: it resumes after the base schema).

# 4. Initial configuration, in order

| Step | Where (menu) | What to set | Who |
|---|---|---|---|
| 1 | Settings > General Settings | `CompanyName` (shown in titles, the customer portal, invoices and messages), logo, footer, contact data. Set it before go-live | SuperAdmin / Admin |
| 2 | Settings > Localisation | Time zone, date format, currency. FreeRADIUS must run with the same time zone | SuperAdmin / Admin |
| 3 | Settings > General Settings | Check that RADIUS is enabled (`radius_enable`; the fresh installer stores it unless run with `--no-enable-radius`). The Radius NAS and Radius log menus appear only afterwards | **SuperAdmin only** |
| 4 | Network > Radius NAS > Add | One entry per NAS: name, address (= `nasname`), CoA port `3799`, a unique 32+ character secret, **Type `mikrotik_snmp`**. Then the RouterOS side, FreeRADIUS restart and verification (NAS runbook §2) | SuperAdmin / Admin + network administrator |
| 5 | Internet Plan > Bandwidth, then Hotspot / PPPOE | Bandwidth profiles, then plans. Reseller activations accept only plans on the **Radius** device of type Hotspot or PPPOE. Any IP pool a plan names must exist on the NAS | SuperAdmin / Admin |
| 6 | Pricing > Plans and prices | Provider base cost and resale switch per plan (a new base row starts with resale disabled); every change asks for a reason and is audited | SuperAdmin / Admin |
| 7 | Settings > Administrator Users > Add | Agents (resellers) and Sales accounts; they are placed in the reseller tree in the same transaction | SuperAdmin / Admin |
| 8 | Finance > Wallets > (wallet) > Fund | Provider funding of a top-level Agent: amount, reason, external reference; review, then confirm | SuperAdmin / Admin |
| 9 | Settings > Widgets, User Notification, Static Pages | Dashboard widgets, reminder templates, customer pages (only SuperAdmin can save static pages) | SuperAdmin |

> [!NOTE]
> A NAS of any type other than `mikrotik_snmp` makes the release preflight FAIL `nas.verification`, and its PPPoE subscribers stay locked out after a lost Accounting-Stop.

Detailed procedures: `reseller_onboarding` and `subscriber_onboarding` in this documentation set.

# 5. Roles at a glance

| Role | Scope | Main work |
|---|---|---|
| SuperAdmin | Global | Everything, plus static pages, plugins, payment gateways, web backup download, security settings |
| Admin | Global | Customers, services, vouchers, finance corrections, pricing, reseller tree, Operations Center |
| Agent (reseller / sub-reseller) | Own subtree | Own customers, activations from its own wallet, transfers to direct children, child pricing, own retail |
| Sales | Assigned customers | Create and activate assigned customers on the parent Agent's wallet |
| Report | None | Login, empty dashboard and own profile only in V1 |

# 6. Scheduled jobs (as the PHP user)

| Job | Schedule |
|---|---|
| `cd system && php cron.php` (expiry, renewals). Run it **from `system/`**: from the root it does nothing and still exits 0 | every 5 minutes |
| `php system/cron_reminder.php` | daily |
| `php system/reseller/health.php --json` → monitoring | every 5 minutes |
| `php system/reseller/health.php --json --finance` → monitoring | hourly |
| `install/backup/nuxr-backup.sh` (encrypted database backup, backup runbook §2) | nightly, and before every upgrade |
| `install/backup/nuxr-binlog-archive.sh` (binlog archive for point-in-time recovery) | every 5 minutes |
| `install/backup/nuxr-backup-verify.sh` (restore test into a scratch database) | weekly |
| `php system/reseller/quota.php enforce --loop=55 --every=5` (`--every` below 5 is refused) | every minute, required as soon as one Limited (quota) plan is sold |
| `sudo -u freerad ${confdir}/nuxr/nuxr_guard_selfcheck` (root crontab, alert on non-zero exit) | every few minutes |

# 7. Go-live gate

Tick each line on the target and keep the evidence (command output and who checked it, never a secret):

- [ ] Release-mode `preflight.php` exit 0 (evidence attached)
- [ ] `health.php --finance` exit 0 (evidence attached)
- [ ] Restore drill done on a scratch database (checklist F2)
- [ ] NAS onboarding verification for every NAS: test login, accounting Start, one Disconnect (checklist H1–H3)
- [ ] `nuxr_guard_selfcheck` prints `guard_selfcheck=OK`
- [ ] `CompanyName` set
- [ ] The owner's release decision recorded in the acceptance ledger

> [!WARNING]
> These checks are necessary but not sufficient. The release also depends on TA-01 … TA-10 on an approved target, LIC-1 and the open owner decisions (see `known_limitations`).

# 8. Never do

- Never enable `$legacy_devices_enabled` (legacy MikroTik-API drivers and the rlm_rest device are disabled in the release profile).
- Never install an unreviewed plugin: plugin code runs with full trust and can bypass the reseller authority.
- Never use the web database restore (it is refused on a reseller installation) and never edit the ledger, wallets, reservations or `radacct` by SQL.
- Never release an ACTIVE admission claim by hand, and never release a held reservation to "unblock" something.
- Never run FreeRADIUS with `-X` in production.

# 9. Where next

| Task | Document |
|---|---|
| Daily, weekly and monthly checks | `operator_daily_checklist` |
| Resellers and wallets | `reseller_onboarding`, `financial_reconciliation_guide` |
| Subscribers, plans, vouchers, portal | `subscriber_onboarding` |
| Backups and recovery | `backup_restore_guide`, `disaster_recovery_guide` |
| Alerts and incidents | `monitoring_alerting_guide`, `incident_troubleshooting_guide` |
| Hardening | `security_hardening_checklist` |
| What V1.0 does not do | `known_limitations` |
