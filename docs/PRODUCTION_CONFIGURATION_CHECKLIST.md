# Production configuration checklist (one ISP instance, Option A)

This checklist is for an operator deploying one isolated instance. Tick every line **on the target** before go-live and keep the evidence: the command output and who checked it, never a secret.
- `php system/reseller/preflight.php` automates the lines marked **[P]**. It must exit 0.
- `php system/reseller/health.php --finance` automates the lines marked **[H]**.

## A. Platform

| # | Item | How to verify |
|---|---|---|
| A1 | PHP 8.2 (the tested runtime) with `pdo_mysql`, `curl`, `gd`, `mbstring`, `zip` | `php -v`, `php -m` |
| A2 | MariaDB 10.11 or MySQL 8.0 (the tested engines) **[P engine]** | preflight `engine` |
| A3 | Global transaction isolation REPEATABLE-READ (W8-18 deployment contract) **[P isolation]** | preflight `isolation` |
| A4 | Database global `time_zone = '+00:00'`; every application and FreeRADIUS SQL session is UTC (P-A4-1) **[P time]** | preflight `time.sql_session_zone` |
| A5 | NTP on the application, database, FreeRADIUS and NAS hosts; skew under 5 s (P-A4-2) **[P time]** | preflight `time.clock_skew`, `chronyc tracking` |
| A6 | MariaDB: `check_constraint_checks = ON` globally (A2-R hardening L4) **[P radius_checks]** | preflight `radius_checks` |
| A7 | The database is not reachable from the internet; only the application and FreeRADIUS hosts can connect | firewall rules |
| A8 | Disk space and the backup target are sized (see §F) | — |

## B. Web server and TLS

| # | Item | How to verify |
|---|---|---|
| B1 | HTTPS only, with a valid certificate; HTTP redirects to HTTPS; HSTS set by the web server | `curl -I http://…` / `https://…` |
| B2 | `APP_URL` resolves to the HTTPS address **[P stage]** | preflight `stage` |
| B3 | The document root serves the application only. These are not reachable from the web: `config.php`, `system/` (except the routed entry), `install/`, `dev/`, `tests/`, `docs/`, `owner-review/`, `.git/`, and any `*.sql` / `*.sh` (`.htaccess_firewall` is the Apache example) | `curl -I https://…/config.php` → 403 / 404 |
| B4 | `install/` is removed after installation. It also refuses to run once `config.php` and `pages/` exist (WP2 SEC-19, WP9 INST-1) | `curl https://…/install/` → 403 |
| B5 | The web updater stays disabled (`update.php` answers 403, WP2 SEC-03) | `curl -I https://…/update.php` |
| B6 | Responses carry `X-Frame-Options: SAMEORIGIN`, `Content-Security-Policy: frame-ancestors 'self'` and `X-Content-Type-Options: nosniff`; the session cookie is `HttpOnly; SameSite=Lax; Secure` (WP9 SESS-1 / HDR-1) | `curl -I https://…/?_route=login` |
| B7 | `ui/compiled/`, `system/uploads/` and `pages/` are writable by the PHP user only; the code tree is read-only for it | `ls -l` |
| B8 | `display_errors = Off` and errors logged to a file outside the document root **[P stage]** | preflight `stage` |

## C. Application secrets (`config.php`, mode 0640, owner root:php-user)

| # | Item | How to verify |
|---|---|---|
| C1 | `$app_secret`: 32+ random characters, unique to this instance (NEW-7) **[P keys]** | preflight `keys` |
| C2 | `$api_secret`: 32+ random characters, different from every other secret (or the API stays disabled) **[P keys]** | preflight `keys` |
| C3 | `$db_pass`: the least-privilege application account (§D), not root | preflight `grants` |
| C4 | `$legacy_devices_enabled` is absent or `false`; `$radius_rest_secret` is absent (release profile: FreeRADIUS SQL only) **[P profile]** | preflight `profile` |
| C5 | `$_app_stage = 'Live'` **[P stage]** | preflight `stage` |
| C6 | No secret appears on a command line, in shell history, in tickets or in logs. The rotation procedures are in `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` | review |
| C7 | The installer-seeded `admin` password has been changed. The application forces this: SEC-18 lock | first login |

## D. Database accounts (least privilege)

| # | Item | How to verify |
|---|---|---|
| D1 | Application account created from `php system/reseller/grants.php --app=<user>`. It has:<br>• no global privileges;<br>• append-only (SELECT, INSERT) access on the ledger, audit and activation-event tables;<br>• no DELETE on the finance tables. **[P grants]** | preflight `grants` |
| D2 | FreeRADIUS account created from `grants.php --radius=<user>`. It has:<br>• SELECT on the policy tables;<br>• INSERT / UPDATE on `radacct` and the admission tables;<br>• nothing on the application tables. | `SHOW GRANTS FOR …` |
| D3 | The migration account (DDL) is a separate administrative account. It is used only by `system/reseller/migrate.php` in a maintenance window, and its password is not kept in `config.php`. | — |
| D4 | No application or FreeRADIUS account can read another instance's database (Option A isolation, ISO-01) | `SHOW GRANTS` |

## E. Schema and data

| # | Item | How to verify |
|---|---|---|
| E1 | `migrate.php preflight`, then `migrate`, then `verify`; all exit 0 (taken right after a verified backup, §F) | exit codes |
| E2 | Reseller schema ready, including every CHECK expression and its enforcement (WP1-CHECK) **[P schema][H schema]** | preflight `schema` |
| E3 | FreeRADIUS admission tables installed and their CHECK constraints exact (`nuxr_admission.sql`, `nuxr_hs_admission.sql`) **[P radius_checks]** | preflight `radius_checks`, `nuxr_admission_check.sql` |
| E4 | OBS-1 identity survey: no canonical identity with more than one holder (`dev/wp9/identity_survey.sql`, also preflight `identity`). Resolve every FAIL before go-live; the procedure is an owner decision. **[P identity]** | preflight `identity` |
| E5 | MAC-auth impact: review the RADIUS usernames that look like lower-case MAC addresses (MAC-H1A) **[P mac]** | preflight `mac` |
| E6 | Existing installations: survey historic `radacct` times for non-UTC writers (P-A4-3) before relying on period counters | `docs/OPERATOR_RECOVERY_RUNBOOK.md` |
| E7 | Plugins: only reviewed plugins are installed; the inventory matches the approved list **[P plugins]** | `php system/reseller/plugins.php`, `preflight --plugins-approved=…` |

## F. Backup and restore

| # | Item | How to verify |
|---|---|---|
| F1 | Nightly logical backup of the application and RADIUS databases (`mariadb-dump` / `mysqldump --single-transaction --routines --triggers --events --hex-blob`). Encrypted at rest; access limited to the instance operators. **The backups contain the NAS secrets and customer data.** | backup log |
| F2 | Restore drill done on a scratch database at least once before go-live, then quarterly (`docs/BACKUP_RESTORE_RUNBOOK.md`) | drill record |
| F3 | Retention and off-site copy agreed with the ISP | — |

## G. FreeRADIUS

| # | Item | How to verify |
|---|---|---|
| G1 | Site `install/freeradius/sites-available/phpnuxbill` and `mods-available/*` as shipped; `delete_stale_sessions = no`; `radpostauth` disabled; EAP disabled or isolated | config diff |
| G2 | `read_clients = yes`, `client_table = "nas"`; FreeRADIUS restarted after every NAS change | — |
| G3 | FreeRADIUS process `TZ` = the application time zone (`Expiration` parsing, WP4 decision 4). Its SQL sessions are UTC (A4). | `/proc/<pid>/environ`, preflight |
| G4 | PPPoE session guard and Hotspot slot verifier installed. Credential files mode 0600, owner `freerad`. Communities never in argv. | `ls -l`, `ps` |
| G5 | SQL pool sized per `install/freeradius/README.md`. The admission hardening (A2-R) refuses a claim written with an emptied key; pool sizing is not relied on for safety. | — |

## H. NAS (RouterOS) contract

| # | Item | How to verify |
|---|---|---|
| H1 | One `nas` row per NAS. `nasname` = `/radius src-address` = `NAS-IP-Address` of its accounting; no NAT between NAS and FreeRADIUS. | test login + radacct |
| H2 | `/radius incoming accept=yes port=3799` (or `nas.ports` set to the NAS's port); CoA allowed only from the application host | one Disconnect from the application |
| H3 | `require-message-auth=yes-for-request-resp`; RADIUS timeout per WP8 (3 s) | `/radius print detail` |
| H4 | PPP AAA accounting with interim updates; no local `/ppp secret` or hotspot users that shadow RADIUS identities | `/ppp secret print` |
| H5 | The RouterOS API is not used in the release profile. If it is ever enabled, it runs only over a private management path (OBS-W8-4). | — |
| H6 | Per-NAS secret: unique, 32+ characters, rotated as documented | rotation log |

## I. Scheduled jobs (as the PHP user)

| # | Job | Schedule |
|---|---|---|
| I1 | `php system/cron.php` (expiry, renewals) | every 5 minutes |
| I2 | `php system/cron_reminder.php` | daily |
| I3 | `php system/reseller/health.php --json` → monitoring (exit 1 WARNING, 2 CRITICAL, 3 UNKNOWN) | every 5 minutes |
| I4 | `php system/reseller/health.php --json --finance` | hourly |
| I5 | Backup (F1) | nightly |

## J. Monitoring and alerting **[H]**

Page the operator on CRITICAL, and on any WARNING that lasts more than 30 minutes:
- `db`, `schema`, `finance`: CRITICAL means stop selling and investigate (`docs/OPERATOR_RECOVERY_RUNBOOK.md`).
- `activations_stale`, `reconciliation`, `reservations` (N-09): held money; follow the runbook.
- `admission_pending`: locked-out PPPoE / Hotspot subscribers (runbook §PENDING).
- `vouchers_review`, `balance_held`: operator review queue.
- `accounting`: accounting stopped while sessions are open (FreeRADIUS / NAS problem).
- `coa_failures`: Disconnect / CoA not confirmed (session policy may be stale; drift `SESSION_POLICY_STALE`).

## K. Go-live sign-off

- [ ] preflight exit 0 (evidence attached)
- [ ] health exit 0 (evidence attached)
- [ ] restore drill record (F2)
- [ ] NAS onboarding verification per NAS (H1–H3)
- [ ] the owner's release decision recorded in the acceptance ledger
