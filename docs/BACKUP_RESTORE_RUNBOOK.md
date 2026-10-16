# Backup and restore runbook (WP10, REL-01 / REL-02)

Scope: one ISP instance (Option A): the application database and the RADIUS database. They are often the same database; they can be separate.
- The legacy JSON "backup / restore" of the upstream web UI stays disabled (WP2). It cannot restore the reseller domain.
- Use only the procedure below.

**A backup is secret material.** It contains:
- the NAS shared secrets (`nas.secret`);
- customer passwords (needed for RADIUS CHAP, stored recoverably);
- staff password hashes;
- personal data;
- every financial record.

Encrypt it at rest and in transit, restrict it to the instance operators, and never attach it to a ticket or chat.

## 1. What a backup must contain
- every table of the application database: legacy `tbl_*`, reseller `tbl_manager_*` / `tbl_reseller_*`, the migration registry `tbl_project_migrations*`;
- every table of the RADIUS database: `radcheck`, `radreply`, `radgroup*`, `radusergroup`, `radacct`, `nas`, `nuxr_admission*`, `nuxr_hs_admission*`;
- CHECK constraints, generated columns, indexes and foreign keys. A logical dump with `SHOW CREATE TABLE` definitions keeps them all.

`config.php` is backed up **separately**, and encrypted, because it holds `$app_secret`:
- without the same `$app_secret`, a restored instance still works;
- but every session, remember cookie, login link and public invoice link issued before becomes invalid.

## 2. Taking a backup (nightly, and immediately before every upgrade)

MariaDB 10.11:

```bash
MYSQL_PWD="$(cat /root/.secrets/backup.pw)" mariadb-dump --user=backup --single-transaction --routines --triggers --events \
  --hex-blob --default-character-set=utf8mb4 <database> | gzip | age -r <operator-public-key> > <database>-$(date -u +%Y%m%dT%H%M%SZ).sql.gz.age
```

MySQL 8.0: the same with `mysqldump` and `--set-gtid-purged=OFF`.

Rules:
- `--single-transaction` gives a consistent snapshot of InnoDB tables without locking the application.
  - Do not run DDL (migrations) during a backup.
- The password comes from a protected file through `MYSQL_PWD` (or a `--defaults-extra-file` of mode 0600), never on the command line.
- The `backup` account needs:
  - SELECT, SHOW VIEW, TRIGGER, EVENT and LOCK TABLES on the instance's databases;
  - PROCESS for the dump header (MySQL 8.0).
- Record for each backup:
  - the UTC time;
  - the size and SHA-256 of the encrypted file;
  - the release commit running at that time.
- Keep the agreed retention, plus at least one off-site copy.

## 3. Restoring

1. Restore into a **new, empty** database. Never over the live one.

   ```bash
   mariadb -e "CREATE DATABASE <new> CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
   age -d -i <operator-key> <file>.sql.gz.age | gunzip | MYSQL_PWD=... mariadb --user=<admin> --default-character-set=utf8mb4 <new>
   ```

2. Verify the restored database before using it:
   - `php system/reseller/migrate.php verify --db-name=<new> …` exits 0. This checks the contracts, CHECK expressions and file checksums.
   - With a `config.php` pointing at `<new>`:
     - `php system/reseller/health.php --finance` reports `db`, `schema` and `finance` OK;
     - `php system/reseller/preflight.php` passes.
   - Row counts of the finance tables match the source backup record (the ledger, wallets, transfers, reservations and activations).
3. Switch over:
   - stop the scheduled jobs and the web server;
   - point `config.php` (and FreeRADIUS) at `<new>`, or rename the databases;
   - restart, then watch `health.php`.
4. Reconcile the gap between the backup time and the incident:
   - activations, transfers and voucher redemptions after the backup are missing;
   - use the audit export, the payment provider's records and `radacct` on the NAS side;
   - the operator recovery runbook lists the states to review.
5. Never delete or rewrite `radacct`, the ledger or the audit tables to "make the numbers fit". Record a correcting operation through the application instead.

## 4. Drill (before go-live, then quarterly)

The drill is automated for the disposable test databases: `dev/wp10/drill.sh <mariadb|mysql>`, using `dev/wp10/BackupRestoreDrillTest.php`. The same steps apply manually on a staging copy.
- **D1, upgrade:**
  1. pre-upgrade backup;
  2. `migrate`, then `verify`;
  3. a second `migrate` changes nothing;
  4. with new-domain data, schema `rollback` is refused (exit 2) and changes nothing.
- **D2, backup and restore of a data-bearing instance:**
  1. every table is identical after the restore: definition (including CHECK constraints), row count, and SHA-256 of all rows;
  2. `verify`, `health` and finance integrity pass on the copy;
  3. a new activation completes on the restored copy.
- **D3, rollback by restore:**
  1. the pre-upgrade backup restores the legacy database exactly;
  2. the upgrade can be re-run on it.

Record each drill with its date, engine, file size and SHA-256, and the result. Never keep the dump in a repository.
