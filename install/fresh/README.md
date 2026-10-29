# install/fresh: fresh-install package for ITC NetCore V1.0

Owner decision 2026-10-29: the first operational installation is a **fresh install on a new, empty database**.
This directory holds the scripts and configuration examples for that path. It replaces the interactive web installer
(`install/index.php` … `step5.php`) with one command-line run. It also performs the post-installer steps of
`docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §2 and the matching lines of `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md`.

It does **not** grant a production release. A production deployment still needs the owner's explicit release authorization (runbook, first paragraph).

## Files

| File | Purpose |
|---|---|
| `nuxr-artifact-verify.sh` | Verifies a release tarball before it is unpacked: it checks the SHA-256 against `SHA256SUMS`, the archive safety rules, `RELEASE_SHA` against the expected commit, and the runbook §1 content rules. Then it optionally unpacks the tarball |
| `nuxr-fresh-install.sh` | Fresh install: checks the prerequisites, proves the database is empty, creates the accounts, loads the base schema, writes `config.php`, runs the migrations, loads the admission tables, applies the grants, sets permissions, removes the installer, and runs the setup preflight and health check |
| `nuxr-post-install-verify.sh` | Non-destructive verification of the web boundary (TA-10 items), the files, the tools and the scheduled jobs |
| `templates/config.php.tmpl` | Template from which `config.php` is rendered |
| `templates/install.env.example` | Operator parameter and secret file (mode 0600). It documents every key |
| `lib/render-config.php` | Renders the template. Values come from the environment only (CLI only, refuses web execution) |
| `nginx/itc-netcore.conf.example` | Production nginx site: HTTPS only, HSTS, the B6 headers, blocked paths, a PHP entry-point allowlist and a login rate limit |
| `php-fpm/pool.conf.example` | PHP-FPM pool: unix socket, `display_errors` off, error log outside the document root, UTC |
| `logrotate/itc-netcore.example` | Log rotation for `/var/log/itcnc` (the product ships none) |
| `cron/itc-netcore.cron.example` | Checklist §I jobs with the documented cadence. `cron.php` runs from `system/` |

No systemd unit is shipped: the runbook uses only the distribution's own services (nginx, php8.2-fpm, the database engine, cron, FreeRADIUS).

## Order of use

All commands run as root on the application host. Keep the verified artifact copy (`<artifact-dir>`) **outside** the document root, and run the scripts from `<artifact-dir>/install/fresh/`. The installer refuses to run from inside the document root.

1. **Release build** (release engineering, from the owner-accepted commit `<sha>`):

   ```bash
   NAME=itc-netcore-v1.0-${SHA:0:12}
   git archive --format=tar --prefix="$NAME/" --add-virtual-file="$NAME/RELEASE_SHA:$SHA"$'\n' "$SHA" -- . \
     ':(exclude)dev' ':(exclude)tests' ':(exclude)owner-review' ':(exclude)docs/claude-development-pack' \
     ':(exclude)system/vendor/yosiazwan/php-facedetection/lena512color.jpg' | gzip -n -9 > "$NAME.tar.gz"
   sha256sum "$NAME.tar.gz" > SHA256SUMS
   ```

   The build is reproducible: the same commit gives the same SHA-256.

2. **Verify, then unpack** twice: once as the kept copy and once as the release that becomes the document root.

   ```bash
   nuxr-artifact-verify.sh --artifact=$NAME.tar.gz --expected-sha=<sha from the release notes> --extract-to=/srv/itcnc/artifact/$NAME
   cd /srv/itcnc/artifact/$NAME/install/fresh
   ./nuxr-artifact-verify.sh --artifact=/path/$NAME.tar.gz --expected-sha=<sha> --extract-to=/srv/itcnc/app/releases/<sha8>-<UTC>
   ln -sfn /srv/itcnc/app/releases/<sha8>-<UTC> /srv/itcnc/app/current
   ```

3. **Platform** (checklist §A, §B):
   - PHP 8.2 with `pdo_mysql`, `curl`, `gd`, `mbstring` and `zip`, plus PHP-FPM;
   - nginx;
   - `mariadb-client` or `mysql-client`;
   - MariaDB 10.11 or MySQL 8.0 with global `REPEATABLE-READ`, `time_zone = '+00:00'` and (MariaDB) `check_constraint_checks = ON`.

   Install `php-fpm/pool.conf.example` and `nginx/itc-netcore.conf.example` (adapt the host name, the certificate and the paths), then run `nginx -t` and reload.

4. **Parameters and secrets**:

   ```bash
   ./nuxr-fresh-install.sh --generate-secrets=/root/itc-netcore-install.env
   ```

   This writes a new 0600 file with three generated passwords. Fill in the empty lines (see `templates/install.env.example`). No secret is ever printed.

5. **Dry run** (read-only):

   ```bash
   ./nuxr-fresh-install.sh --app-root=/srv/itcnc/app/current --env-file=/root/itc-netcore-install.env --create-db --install-cron --dry-run
   ```

6. **Install** with the same command without `--dry-run`. Exit 0 means installed, preflight `--setup` passed and `health.php --finance` passed.

7. **Verify**:

   ```bash
   ./nuxr-post-install-verify.sh --app-root=/srv/itcnc/app/current --base-url=https://<host>
   ```

8. **Next steps** (printed by the installer as well):
   - sign in as `admin` / `admin`; the application forces the password change (SEC-18);
   - set `CompanyName`, the time zone and the currency;
   - install `logrotate/itc-netcore.example`;
   - configure FreeRADIUS (checklist §G) and every NAS (`docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md`), then run the **release** preflight (`preflight.php` without `--setup`; `nuxr-post-install-verify.sh --release`);
   - set up backups and run a restore drill;
   - move the env-file secrets into the secret store and delete the file.

## Safety contract of `nuxr-fresh-install.sh`

- **Database gate.** The target database must either:
  - not exist, and `--create-db` is given; it is then created as `utf8mb4` / `utf8mb4_general_ci`; or
  - exist with **zero** tables, views, routines, events and triggers and the collation `utf8mb4_general_ci`.

  The emptiness proof requires an administrator with global `SELECT`, so that every object is visible. Anything else is **refused (exit 3) before any change**. The emptiness is checked again immediately before the base schema is loaded, because `install/phpnuxbill.sql` and `install/radius.sql` begin with `DROP TABLE IF EXISTS`.
- **Accounts.** An account that already exists is refused: it may hold privileges on other databases (ISO-01). An existing `config.php` without installer state is refused.
- **Secrets.**
  - Passwords come only from the 0600 env file. The file is parsed, never sourced.
  - Database clients read their credentials from 0600 option files in a private temporary directory, which is removed on exit.
  - `migrate.php` receives its password through `--db-password-env`.
  - `config.php` gets a new 64-hex `$app_secret` and `$api_secret` from `random_bytes`.
  - Nothing secret appears on a command line, on the screen or in the log.
- **Idempotent and resumable.** A state file (`/var/lib/itc-netcore/fresh-install/<host>__<db>.state`, 0700 directory, with a lock) records each phase.
  - A re-run after success changes nothing in the database. It re-applies permissions, re-checks the installer removal and re-runs the checks.
  - A re-run after a failure resumes after the base schema.
  - A failure **during** the base schema load is never resumed: start again on a new empty database.
- **Exit codes:**

  | Code | Meaning |
  |---|---|
  | 0 | Installed and verified |
  | 1 | Internal error |
  | 2 | Prerequisites missing (nothing changed) |
  | 3 | Refused (nothing changed) |
  | 4 | A step failed (schema, migration, grants, preflight, health) |
  | 5 | Usage |

## Differences from the runbook text, and why

| Runbook / web installer | This package | Reason |
|---|---|---|
| Web installer steps 1–5 in a browser | One CLI run doing the same work: write `config.php`, import `phpnuxbill.sql` + `radius.sql` (the RADIUS option), copy `pages_template/` to `pages/` | Reproducible, no password typed into a web form, and the emptiness gate can run before the import |
| Installer account writes the schema, later replaced (§2 steps 2–3, 8) | The migration (DDL) account loads the base schema. `config.php` holds the runtime account from the start | One administrative account fewer. The migration password is never in `config.php` (D3) |
| `migrate.php` without `--db-host` | `--db-host` equal to `NUXR_DB_HOST` | The account host part must match the connection (register X-24) |
| RADIUS enabled later in the UI | `tbl_appconfig.radius_enable = 1` is stored by default (`--no-enable-radius` to skip) | Defect ACC-1. It is the same value the SuperAdmin "Enable Radius: Yes" setting stores |
| Remove the web installer (§2 step 4, which since `c550d6b8` keeps `install/freeradius/`) | Default `--installer=strip`: the web installer is deleted, and `install/freeradius/` stays read-only and is denied by nginx. Not a deviation any more: the runbook now says the same. `--installer=remove` deletes everything and is **not usable**: the setup preflight then FAILs and the run stops in phase 12 with exit 4 | `preflight.php` `radius_checks` reads the expected CHECK expressions from `install/freeradius/*.sql` in the document root and FAILs when they are missing or unreadable (G-1, fixed in `33624129`) |
| `APP_URL` derived from the request | `define('APP_URL', '<https URL>')` pinned | Checklist B9. It also removes the CLI warnings |
