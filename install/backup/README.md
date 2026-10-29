# install/backup: backup and point-in-time recovery tooling (ITC NetCore V1.0)

These tools automate `docs/BACKUP_RESTORE_RUNBOOK.md` §2–§4 for one instance (Option A). They also add the binlog
point-in-time recovery (PITR) chain that the runbook does not yet cover. They work with MariaDB 10.11 and MySQL 8.0.

> **RTO / RPO.** The lab figures of 4 h RTO and 15 min RPO are **provisional engineering design targets**. They are not
> owner-approved service levels, and this tooling guarantees neither. What a deployment achieves depends on the
> archive interval, the database size, the hardware, the off-host transport and the people on call. Measure it with
> `nuxr-backup-verify.sh` and a drill on the target hardware (§9).

| Tool | Purpose |
|---|---|
| `nuxr-backup.sh` | Consistent (`--single-transaction`), compressed, encrypted logical dump of the application DB (plus the RADIUS DB when it is separate), with the binlog coordinates of the snapshot. Also an encrypted copy of `config.php` and the other secret files, a sha256 manifest, an optional off-host copy, and retention pruning |
| `nuxr-binlog-archive.sh` | Archives closed binary logs: flush, fetch, size check, sha256, compress, encrypt, off-host copy. Run every few minutes |
| `nuxr-restore.sh` | Restores a set into a **new** database: checksum, decrypt, load. Optionally replays the archived binlogs up to `--stop-datetime` / `--stop-position`, or to the end of the archive. Then the runbook checks on the copy: fingerprint, finance row counts, `migrate.php verify`, `health.php --finance` |
| `nuxr-backup-verify.sh` | Periodic restore test into a scratch DB (plain, or with the whole PITR chain). Records the result and the row counts, then drops the scratch DB |
| `nuxr-db-fingerprint.sh` | Fingerprint of a database (per table: rows, sha256 of all rows, sha256 of the definition). Compares a copy with its source |
| `nuxr-backup-lib.sh` | Shared functions (sourced) |
| `backup.conf.example`, `backup.my.cnf.example`, `binlog.cnf.example`, `systemd/`, `cron.example` | Configuration, grants, server settings, schedules |

## 1. Prerequisites

- bash 4.4+, coreutils (`sha256sum`, `stat`, `mktemp`), `tar`, `gzip` (or `zstd`), `openssl` (or `age` / `gpg`), and `flock` (util-linux; without it the tools fall back to a `mkdir` lock).
- **The engine's own client tools.** Both the dump tool and the binlog tool must belong to the engine:
  - MariaDB: `mariadb`, `mariadb-dump`, `mariadb-binlog`;
  - MySQL 8.0: `mysql`, `mysqldump`, `mysqlbinlog`.

  A MariaDB `mysqldump` / `mysqlbinlog` against MySQL 8.0, or the reverse, is refused. Use `CLIENT_BIN_DIR` when the engine's tools are not first in `PATH`. Note that the official `mysql:8.0` container image ships no `mysqlbinlog`: install the MySQL client tools on the host that runs the tools.
- For verifying a restored copy: PHP CLI with `pdo_mysql`, and the ITC NetCore release tree (`APP_DIR`).
- Server settings: §3. Accounts and grants: `backup.my.cnf.example`.

## 2. Installation

```bash
install -d -m 0755 /opt/itcnc/install && cp -r install/backup /opt/itcnc/install/backup
install -d -m 0700 /etc/itcnc /etc/itcnc/backup /var/backups/itcnc /var/log/itcnc
install -m 0600 install/backup/backup.conf.example /etc/itcnc/backup.conf          # then edit
install -m 0600 /dev/null /etc/itcnc/backup/backup.my.cnf                          # fill in from backup.my.cnf.example
install -m 0600 /dev/null /etc/itcnc/backup/restore.my.cnf
( umask 077; openssl rand -base64 48 > /etc/itcnc/backup/backup.key )             # ENCRYPTION=openssl
/opt/itcnc/install/backup/nuxr-binlog-archive.sh --check-only                      # server settings report
/opt/itcnc/install/backup/nuxr-backup.sh && /opt/itcnc/install/backup/nuxr-backup-verify.sh
```

Then install the timers (`systemd/*.service`, `*.timer`: `systemctl enable --now nuxr-backup.timer nuxr-binlog-archive.timer nuxr-backup-verify.timer`) or `cron.example`. Monitor the exit codes (§8) and the age of the newest set and of the newest archived binlog.

**The key.** Keep a sealed copy of `ENC_KEY_FILE` (or of the age identity) **off the host**, for example two people with an envelope or a vault. Without it no backup can be restored, and it must never sit in the backup store. With `ENCRYPTION=age`, the database host keeps only the public recipient, so a stolen host cannot decrypt its own backups. Only the restore host needs the identity.

## 3. Server settings (PITR)

See `binlog.cnf.example`.
- Required: `log_bin` on, `binlog_format=ROW`, a unique `server_id`, `sync_binlog=1`, `innodb_flush_log_at_trx_commit=1`.
- Set `binlog_expire_logs_seconds` (MariaDB < 10.6: `expire_logs_days`) **much longer** than the archive interval plus the longest outage of the archive job you will tolerate. Once a binlog expires before it is archived, the chain has a gap: the archiver reports `CHAIN GAP` (exit 11), and a new full backup is needed.
- MariaDB has binary logging OFF by default. MySQL 8.0 has it ON.
- `nuxr-binlog-archive.sh --check-only` prints these settings and warns on each deviation.

## 4. What a set contains

`$BACKUP_DIR/full/<UTC yyyymmddThhmmssZ>/` (written as `.partial` and renamed when complete):

| File | Content |
|---|---|
| `<db>.sql.gz.enc` | The dump: `--single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --no-tablespaces`, plus binlog coordinates: MariaDB `--master-data=2`; MySQL `--source-data=2 --set-gtid-purged=OFF` |
| `secrets.tar.gz.enc` | The files in `SECRET_FILES` (`config.php`: `$app_secret`, `$api_secret`, DB credentials). Restore it by hand: `openssl enc -d -aes-256-cbc -pbkdf2 -iter <openssl_iter> -pass file:<key> -in secrets.tar.gz.enc \| tar -tzvf -` |
| `backup.info` | UTC times, engine and versions, `server_id`, the dump tool, encryption parameters, and per DB: file, size, sha256, seconds, binlog file and position. Also the release directory and version, and the GTID state at the end |
| `MANIFEST.sha256`, `MANIFEST.sha256.enc` | sha256 of every file. The encrypted copy makes a silently edited plaintext manifest detectable |
| `VERIFIED`, `VERIFIED.<db>.rows` | Written by `nuxr-backup-verify.sh`: the result, and the per-table row counts of the restored snapshot |

Binlogs: `$BACKUP_DIR/binlog/<ARCHIVE_TAG>/<name>.gz.enc`, `<name>.sha256` and `<name>.info` (size, raw sha256, first and last event UTC). The `.info` file is written last, so it marks a binlog as archived.

**Consistency.** `--single-transaction` gives one InnoDB snapshot per database without locking the application.
- On MySQL, `--source-data` takes a brief global read lock at the start of the dump. It waits for running statements, so avoid long transactions at backup time.
- Non-InnoDB tables are not covered by the snapshot; the tool warns about them.
- Do not run migrations (DDL) during a backup.
- With a separate RADIUS DB, the two dumps are taken one after the other, so they are two snapshots a few seconds apart. Each has its own coordinates, so a PITR replay brings both to the same point in time.

## 5. Restoring (runbook §3)

```bash
# 1. plain restore of the newest set into a NEW database, with verification
nuxr-restore.sh --set latest --target-db itcnc_restored            [--target-radius-db radius_restored]
# 2. point in time: the set taken before the incident + binlogs, replaying events BEFORE this UTC time
nuxr-restore.sh --set 20261029T021500Z --target-db itcnc_restored --stop-datetime '2026-10-29 10:41:00'
# 3. exactly up to (excluding) one event, e.g. a destructive statement
nuxr-restore.sh --set 20261029T021500Z --target-db itcnc_restored --stop-position itcnc-bin.000123:4711
# 4. host lost: everything that reached the archive
nuxr-restore.sh --set latest --target-db itcnc_restored --pitr-to-end --binlog-dir /mnt/offhost/binlog/server-101
# inspect what would be replayed, without applying it
nuxr-restore.sh ... --decode-only /root/replay-review          # writes /root/replay-review.<target>.sql (0600)
```

To find the position of a destructive event: decrypt the binlog that holds it, run `mysqlbinlog --no-defaults --base64-output=DECODE-ROWS -v <file> | less`, and take the `# at <pos>` line just before the statement.

**Safety rules.** The tool:
- refuses a target that exists and is not empty (`--i-understand-overwrite <db>` overrides it, after a 10 s grace period);
- refuses a set from the other engine;
- refuses a server whose `server_id` is not `RESTORE_EXPECT_SERVER_ID`;
- verifies every checksum before decrypting;
- decodes the replay first and scans it. `CREATE` / `DROP` / `ALTER DATABASE`, and statements that still name the source database, are refused, because they would act on the **live** database. Table DDL in the range is reported. `--allow-unsafe-binlog-statements` overrides the refusal, after review;
- never writes the target server's binlog (`RESTORE_SKIP_BINLOG=1`).

**Engine difference** (found in the lab drill). With `--rewrite-db`, MySQL's `mysqlbinlog` applies `--database` to the rewritten name, but MariaDB's `mariadb-binlog` applies it to the original name. The tool passes the right name for each engine, and it warns when a replay range holds no row event.

**After the restore**, the switch-over stays manual (runbook §3 step 3):
1. stop the jobs and the web server;
2. re-point `config.php` and FreeRADIUS, or rename the databases;
3. restart and watch `health.php`.

Then reconcile the gap (runbook §3 step 4). Never rewrite the ledger, the audit tables or `radacct` to make the numbers fit.

**Verification of the copy.**
- `migrate.php verify` runs against the copy with the restore account.
- `health.php --json --finance` runs from a throwaway copy of `APP_DIR`. Its `config.php` includes the real one and re-points `$db_*` / `$radius_*` at the copy. The password reaches PHP through the process environment only.
- `VERIFY_HEALTH_REQUIRED` (default `db schema finance`) must be OK. The other health checks are reported; on an old copy they can legitimately warn, for example on accounting freshness.
- `preflight.php` is not run: it checks the live NAS / FreeRADIUS environment, not a scratch copy.

## 6. Off-host copy

`OFFHOST_CMD` runs from `$BACKUP_DIR` with the relative paths of the new files as `"$@"`; see the placeholders in `backup.conf.example`. No destination is built in. The command must keep the files private and use an encrypted transport. A failed copy keeps the set locally and exits 5. For binlogs, the off-host copy is what protects the RPO when the host is lost: the local archive dies with the host.

## 7. Retention

After each backup, sets older than `RETENTION_DAYS` are removed, but the newest `RETENTION_MIN_KEEP` sets are always kept. Archived binlogs older than the PITR start of the oldest kept set are removed too. So every kept set has a complete chain to the newest archived binlog. `nuxr-backup.sh --prune-only` prunes without backing up. The off-host store needs its own retention.

## 8. Exit codes

| Code | Meaning |
|---|---|
| 0 | OK |
| 1 | Unexpected failure (or a required secret file missing) |
| 3 | Dump failed |
| 4 | Compression or encryption failed |
| 5 | Set complete locally, off-host copy failed |
| 6 | Refused by a safety rule |
| 7 | Checksum or manifest mismatch |
| 8 | Load failed |
| 9 | Binlog chain incomplete, or replay failed |
| 10 | Verification of the restored copy failed |
| 11 | Binlog archiving failed, or chain gap |
| 64 | Usage |
| 75 | Another run holds the lock (retry later) |
| 78 | Configuration or prerequisite error |

Every tool prints `key=value` result lines on stdout and logs to stderr and `LOG_FILE`. No password, key or row content is ever printed.

## 9. RPO / RTO: what the tooling does and does not determine

| Owner option (consolidation §5) | Tools used | What bounds the data loss | What bounds the recovery time |
|---|---|---|---|
| 1: daily logical backups | `nuxr-backup.sh`, `nuxr-backup-verify.sh` | The backup interval (about 24 h) | Load time of the dump, plus verification, switch-over and reconciliation |
| 2: plus binlog PITR | + `nuxr-binlog-archive.sh`, `nuxr-restore.sh --stop-*` / `--pitr-to-end` | A logical error with the server alive: about 0, because the replay can stop just before the bad event. Host lost: the binlog archive and off-host interval, plus the transfer time | Load plus replay of the binlogs since the last full set (more frequent full sets shorten it), plus the same checks |
| 3: replica plus option 2 | Not covered by this tooling (REL-06; HA is out of V1 scope unless approved) | Replication lag | Promotion and re-pointing |

Lab measurements: `reports/BACKUP_PITR_DRILL_20261029.md` in the lab workspace. They come from a small synthetic database on a 16 GB workstation, so they are not a guarantee for any deployment.
