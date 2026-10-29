# About this guide

This guide explains how to back up and restore one ITC NetCore V1.0 instance, including point-in-time recovery (PITR). It separates clearly:

- **The product runbook** — `docs/BACKUP_RESTORE_RUNBOOK.md` (encrypted logical dumps, restore into a new database, verification, drills) and the related checklist rows (`docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` §F, I5);
- **The backup tooling** — `install/backup/` (`nuxr-backup.sh`, `nuxr-binlog-archive.sh`, `nuxr-restore.sh`, `nuxr-backup-verify.sh`, `nuxr-db-fingerprint.sh`, their README and configuration examples). It automates the runbook and adds the binary-log PITR chain. It ships with the release as `install/backup/` and was **lab-drilled on MariaDB 10.11 and MySQL 8.0** on 2026-10-29;
- **What is still open** — items that neither the runbook nor the tooling provides, marked **[Still open]**.

> [!WARNING]
> Release Candidate, not yet production-accepted. The backup and PITR tooling was drilled in the lab on a small synthetic database (71 MiB, about 124 000 rows) in disposable containers on a 16 GB workstation. The figures show that the procedure works and give an order of magnitude; they are not a measurement of any production instance. The target backup chain has **not** been tested (TA-08 NOT_RUN).

> [!NOTE]
> **RTO 4 h and RPO 15 min are PROVISIONAL engineering design targets, not owner-approved SLAs.** No owner value exists in any record, and the tooling guarantees neither. An RPO of 15 minutes is **not achievable** with the nightly dumps alone (their recovery point is up to about 24 hours). It needs recovery option 2 (daily backups plus binlog PITR, §6), which the tooling now implements. A binlog archive every 5 minutes is compatible with the provisional 15-minute design target **only if every archive run ships off-host and is monitored**; that remains to be shown on the target system.

# 1. A backup is secret material

A backup contains the NAS shared secrets, customer passwords (stored recoverably because RADIUS CHAP needs them), staff password hashes, personal data and every financial record.

> [!SECURITY-WARNING]
> Encrypt every backup at rest and in transit. Restrict it to the instance operators. Never attach a backup to a ticket, an email or a chat. Never keep a dump in a source repository.

> [!SECURITY-WARNING]
> **The encryption key.** Keep a sealed copy of the key file (`ENC_KEY_FILE`, or the `age` identity) **off the host**, for example with two people and an envelope, or in a vault. Without it no backup can be restored, and it must never sit in the backup store. With `ENCRYPTION=age` the database host keeps only the public recipient, so a stolen host cannot decrypt its own backups; only the restore host needs the identity. The encrypted manifest cannot be authenticated against someone who holds the public recipient, so with `age` keep the recipients file private and the backup store write-protected, or use openssl / gpg symmetric keys. The tools read the key from a 0600 file and never from the command line.

# 2. What must be backed up

| Item | Contents | How |
|---|---|---|
| Application database | Every table: legacy `tbl_*`, reseller `tbl_manager_*` / `tbl_reseller_*`, migration registry `tbl_project_migrations*` | `nuxr-backup.sh` (§3) |
| RADIUS database (often the same database) | `radcheck`, `radreply`, `radgroup*`, `radusergroup`, `radacct`, `nas`, `nuxr_admission*`, `nuxr_hs_admission*` | `nuxr-backup.sh` dumps it too when it is separate |
| Schema objects | CHECK constraints, generated columns, indexes, foreign keys, routines, triggers, events | Kept by the logical dump |
| Changes since the last full backup | Row-format binary logs | `nuxr-binlog-archive.sh` (§6) |
| `config.php` | `$app_secret`, `$api_secret`, database credentials | Encrypted `secrets.tar` in every set (`SECRET_FILES`). Without the same `$app_secret` a restored instance works, but every session, remember cookie, login link and public invoice link issued before becomes invalid |
| FreeRADIUS configuration | Site, modules, `${confdir}/nuxr/nas_snmp` (guard SNMP communities) | Part of the instance's FreeRADIUS configuration and its backup (`install/freeradius/README.md`); add it to `SECRET_FILES` if it lives on the same host. Never copy it between instances |
| Release identity | The release running at backup time | Recorded in each set's `backup.info` (release directory and version) |

> [!WARNING]
> The legacy web table download on the database status page (`settings/dbstatus` › `settings/dbbackup`, SuperAdmin only) produces an **unencrypted JSON file of selected tables**. It is not a supported backup: it does not carry the schema, and the web restore is refused on a reseller installation. Do not use it as a backup.

# 3. Taking a backup

**When:** nightly (checklist F1, I5), **immediately before every upgrade**, and continuously for the binary logs (§6).

## 3.1 Installation of the tooling

Prerequisites: bash 4.4+, coreutils, `tar`, `gzip` (or `zstd`), `openssl` (or `age` / `gpg`), `flock`, and **the engine's own client tools** (MariaDB: `mariadb`, `mariadb-dump`, `mariadb-binlog`; MySQL 8.0: `mysql`, `mysqldump`, `mysqlbinlog`). A MariaDB dump or binlog tool against MySQL 8.0, or the reverse, is refused. The official `mysql:8.0` container image ships no `mysqlbinlog`: install the MySQL client tools on the host that runs the tools. Verifying a restored copy also needs PHP CLI with `pdo_mysql` and the release tree.

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

Then install the schedules: the systemd units in `systemd/` (backup nightly, binlog archive every 5 minutes, restore test weekly: `systemctl enable --now nuxr-backup.timer nuxr-binlog-archive.timer nuxr-backup-verify.timer`) or `cron.example`. The accounts and their grants per engine are in `backup.my.cnf.example`. Configuration and option files must be mode 0600; a group-writable configuration or a 0644 option file is refused (exit 78).

## 3.2 What `nuxr-backup.sh` does

- Dumps the application database (and the RADIUS database when it is separate) with `--single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --no-tablespaces`, plus the binlog coordinates of the snapshot (MariaDB `--master-data=2`; MySQL `--source-data=2 --set-gtid-purged=OFF`). The dump stream must end with `-- Dump completed`.
- Compresses (gzip or zstd) and encrypts (openssl AES-256-CBC with PBKDF2, or `age` / `gpg`).
- Writes, per set under `$BACKUP_DIR/full/<UTC yyyymmddThhmmssZ>/`: the encrypted dump, the encrypted `secrets.tar` (`SECRET_FILES`), `backup.info` (UTC times, engine and versions, `server_id`, the dump tool, encryption parameters, per database file, size, sha256 and binlog position, release directory and version), `MANIFEST.sha256` and an encrypted copy of the manifest (mandatory at restore). The set is written as `.partial` and renamed only when complete.
- Runs the off-host hook and the retention pruning (§4), holds a lock (a concurrent run exits 75), logs, and returns a distinct exit code per failure (§9).

Rules:
- `--single-transaction` gives one consistent InnoDB snapshot per database without locking the application. On MySQL, `--source-data` takes a brief global read lock at the start of the dump: avoid long transactions at backup time.
- Non-InnoDB tables are not covered by the snapshot; the tool warns about them. Do not run migrations (DDL) during a backup.
- With a separate RADIUS database the two dumps are two snapshots a few seconds apart; each carries its own coordinates, so a PITR replay brings both to the same point in time.
- No password, key or row content is ever printed; results are `key=value` lines.

**Backup record.** `backup.info` and the manifest are the machine record. Keep the operator record below for the sets you hand over, restore or drill.

| UTC time | Database | Set | Size | SHA-256 | Release | Operator |
|---|---|---|---|---|---|---|
| | | | | | | |

# 4. Retention and off-host storage

| Requirement | Source | Status |
|---|---|---|
| Keep the **agreed** retention | Backup runbook §2; checklist F3 | `RETENTION_DAYS` plus `RETENTION_MIN_KEEP` (the newest sets are always kept). Archived binlogs older than the PITR start of the oldest kept set are pruned too, so every kept set has a complete chain. `nuxr-backup.sh --prune-only` prunes without backing up. The period itself is agreed with the ISP; the product sets no value |
| At least one off-site copy | Backup runbook §2; checklist F3 | Hook provided: `OFFHOST_CMD` runs after each set and each archived binlog. A failed copy keeps the item locally, leaves a marker for it in `$BACKUP_DIR/.offhost/` and exits 5; the next run retries every marked item automatically and keeps exiting 5 while such a backlog remains. **No destination is built in. [Still open: the real off-host target, its transport and its own retention, agreed with the ISP]** |
| Scheduled backup | Checklist I5 | Provided: systemd timers or `cron.example` |
| Monitoring of the backups | Checklist I5 | Alert on every non-zero exit code (§9), including **any** exit 5 (an off-host copy is still missing), and on the age of the newest set and of the newest archived binlog. **[Still open: wiring into the ISP's monitoring]** |

> [!WARNING]
> For binary logs, the off-host copy is what protects the RPO when the host is lost: a local archive dies with the host. The off-host command must keep the files private and use an encrypted transport.

<!-- pagebreak -->

# 5. Restoring

`nuxr-restore.sh` restores a set into a **new** database and verifies it. It never restores over the live database.

```bash
# plain restore of the newest set into a NEW database, with verification
nuxr-restore.sh --set latest --target-db itcnc_restored            [--target-radius-db radius_restored]
```

1. **Safety checks before loading.** The tool checks the manifest and its encrypted copy (mandatory: a set without the encrypted manifest is refused), every checksum before decrypting, the `server_id` (`RESTORE_EXPECT_SERVER_ID`), the engine, and that the target is empty. A non-empty target is refused (exit 6); `--i-understand-overwrite <db>` overrides it after a 10-second grace period. A set from the other engine is refused (exit 6). The load runs without writing the target server's binary log.
2. **Verification of the copy (runbook §3).** The tool runs, on the copy:
   - the per-table fingerprint (rows, sha256 of all rows in primary-key order, sha256 of the definition);
   - the finance row counts (ledger, wallets, transfers, reservations, activations);
   - `migrate.php verify` (exit 0);
   - `health.php --json --finance` from a throwaway copy of the application tree whose `config.php` includes the real one and re-points the database settings at the copy (the password reaches PHP through the environment only). `db`, `schema` and `finance` must be OK; other checks may legitimately warn on an old copy.

   `preflight.php` is not run on a copy: it checks the live NAS / FreeRADIUS environment.
3. **Switch over (manual).** Stop the scheduled jobs and the web server; point `config.php` (and FreeRADIUS) at the new database, or rename the databases; restart; watch `health.php`.
4. **Reconcile the gap** between the recovery point and the incident. Without PITR, activations, transfers and voucher redemptions made after the backup are missing. Use the audit records, the payment provider's records and `radacct` on the NAS side; the operator recovery runbook lists the states to review.
5. **Never** delete or rewrite `radacct`, the ledger or the audit tables to "make the numbers fit". Record a correcting operation through the application (`financial_reconciliation_guide`).

> [!FINANCE-WARNING]
> After a restore, activations that were in flight at the recovery point come back in their recorded state (for example `FUNDS_RESERVED` or `RECONCILIATION_REQUIRED`) with their reservations ACTIVE. They are resolved by the normal operator procedures; they are never released automatically.

# 6. Binary log and point-in-time recovery (recovery option 2)

PITR is now implemented by `nuxr-binlog-archive.sh` and `nuxr-restore.sh`. **Still open:** the PITR section of the product runbook `docs/BACKUP_RESTORE_RUNBOOK.md` (the tooling README holds the draft), a real off-host target, monitoring of the archive age, and the owner's choice of archive interval.

## 6.1 Server settings

See `binlog.cnf.example`. Required: `log_bin` on, `binlog_format=ROW`, a unique `server_id`, `sync_binlog=1`, `innodb_flush_log_at_trx_commit=1`. Set the binlog expiry (`binlog_expire_logs_seconds`) **much longer** than the archive interval plus the longest archive outage you will tolerate: a binlog that expires before it is archived leaves a gap (`CHAIN GAP`, exit 11), and a new full backup is needed. MariaDB has binary logging off by default; MySQL 8.0 has it on. `nuxr-binlog-archive.sh --check-only` prints the settings and warns on each deviation.

## 6.2 Archiving

`nuxr-binlog-archive.sh` (every 5 minutes by the shipped timer) flushes the binary logs, fetches each closed binlog, checks its size, records its sha256 and its first and last event time, compresses and encrypts it, writes an `.info` file last (which marks it as archived), detects chain gaps and calls the off-host hook.

## 6.3 Restoring to a point in time

```bash
# the set taken before the incident + binlogs, replaying events BEFORE this UTC time
nuxr-restore.sh --set 20261029T021500Z --target-db itcnc_restored --stop-datetime '2026-10-29 10:41:00'
# exactly up to (excluding) one event, e.g. a destructive statement
nuxr-restore.sh --set 20261029T021500Z --target-db itcnc_restored --stop-position itcnc-bin.000123:4711
# host lost: everything that reached the archive
nuxr-restore.sh --set latest --target-db itcnc_restored --pitr-to-end --binlog-dir /mnt/offhost/binlog/server-101
# inspect what would be replayed, without applying it
nuxr-restore.sh ... --decode-only /root/replay-review
```

- `--stop-datetime` is UTC with one-second resolution; `--stop-position` is the precise tool. To find a position, decrypt the binlog that holds the event, run `mysqlbinlog --no-defaults --base64-output=DECODE-ROWS -v <file>`, and take the `# at <pos>` line just before the statement.
- Before replaying, the tool checks that the chain is contiguous and verifies each binlog's encrypted and raw checksums. It then decodes the range and **scans** it: `CREATE` / `DROP` / `ALTER DATABASE`, and statements that still name the source database, are **refused** (exit 6), because they would act on the **live** database. Table DDL in the range is reported. `--allow-unsafe-binlog-statements` overrides the refusal, only after review of the decoded SQL.
- After the replay, the same verification as §5 step 2 runs on the copy, then the manual switch-over and the reconciliation of whatever lies after the recovery point.

> [!WARNING]
> **A careless replay can drop the live database.** A `DROP DATABASE` issued from a session whose default database is the application database stays in the replay even after the database name is rewritten, because only the `use` line is rewritten. Replayed on the production server it would drop the live database. The tool's pre-apply scan refuses it (drilled: exit 6). Never replay binlogs by hand.

> [!NOTE]
> **Engine difference (lab finding).** With `--rewrite-db`, MySQL's `mysqlbinlog` applies `--database` to the rewritten name, MariaDB's `mariadb-binlog` to the original name. The tool passes the right name for each engine and warns when a replay range holds no row event. `--set-gtid-purged=OFF` is compatible with this chain, which uses file / position coordinates; `gtid_mode=ON` was not drilled.

# 7. Drills

| Drill | What it proves | When |
|---|---|---|
| D1 upgrade | Pre-upgrade backup; `migrate` then `verify`; a second `migrate` changes nothing; with new-domain data, schema `rollback` is refused (exit 2) | Before go-live, then quarterly |
| D2 backup and restore | Every table identical after restore (definition including CHECK constraints, row count, SHA-256 of all rows); `verify`, `health` and finance integrity pass on the copy; a new activation completes on the copy | Before go-live, then quarterly |
| D3 rollback by restore | The pre-upgrade backup restores the previous database exactly; the upgrade can be re-run on it | Before go-live, then quarterly |
| Periodic restore test | `nuxr-backup-verify.sh` restores the newest set (plain, or `--with-binlogs` for the whole PITR chain) into a scratch database `nuxr_verify_<ts>`, writes the `VERIFIED` record and drops only the scratch databases it created | Weekly (shipped timer) |
| PITR drill | Restore plus replay to a chosen point (`--stop-datetime` / `--stop-position`) and to the end of an archive (`--pitr-to-end`); the copy is compared with a fingerprint taken at the chosen point | Before go-live, then at the restore-drill cadence |

The lab automation in the source tree (`dev/wp10/drill.sh <mariadb|mysql>`, `dev/wp10/BackupRestoreDrillTest.php`) runs against disposable test databases; `dev/` is not part of the release artifact. On a staging copy the steps are run with the tools above.

**Drill record**

| Date | Engine | Drill | Set / range | Duration | Result | Operator |
|---|---|---|---|---|---|---|
| | | | | | | |

<!-- pagebreak -->

# 8. Lab drill results (2026-10-29)

Measured runs with 240 s of synthetic writes and a binlog archive every 60 s.

| | MariaDB 10.11.19 | MySQL 8.0.46 |
|---|---|---|
| Source (synthetic) | 46 tables, 122 833 rows, 71 MiB | 46 tables, 122 833 rows, 71 MiB |
| Full backup, wall time | 2.07 s | 2.41 s |
| Encrypted dump size | 5.8 MiB | 5.8 MiB |
| Periodic restore test | 12.45 s, exit 0 | 26.14 s, exit 0 |
| **A. Dropped database, server alive: restore + replay to the chosen time** | copy **identical** to the state at that time, 0 rows lost, tool run 15.8 s | **identical**, 0 rows lost, 30.8 s |
| **E. Same, to the position just before the DROP event** | **identical**, 15.2 s | **identical**, 30.6 s |
| **B. Host lost: only the archive as it was** | loss window 51.4 s, tool run 13.4 s | 51.4 s, 25.1 s |
| Verification on every copy | `migrate verify` exit 0; health `--finance` all checks OK; finance row counts equal | same |
| Replay range containing the DROP DATABASE; restore into a non-empty database | both refused, exit 6 | both refused, exit 6 |

A regression run of the final tooling (90 s of writes, archive every 30 s) passed on both engines, with a host-loss window of about 21 s. The negative tests returned the expected codes: concurrent backup 75, tampered dump 7, wrong `server_id` 78, group-writable configuration 78, cross-engine restore 6, option file mode 0644 78. A secret scan of the evidence found none of the drill secrets.

**How to read these figures.**
- The tool time does **not** include detection, the decision, stopping the jobs, the switch-over, watching `health.php` and the manual reconciliation of the gap; in a real incident those dominate.
- The load ran at about 11 MiB/s (MariaDB) and 7.7 MiB/s (MySQL). The replay is bound by the transaction count, not by the bytes. Larger databases and longer replay windows take proportionally longer; more frequent full backups or faster storage shorten the replay. Measure on the target (TA-08).
- **Not drilled:** a separate RADIUS database; `age`, `gpg` and `zstd`; `BINLOG_MODE=local`; `gtid_mode=ON`; restore onto a fresh server; the `--i-understand-overwrite` path (only its refusal); the systemd units and cron (syntax only); a real remote off-host copy (simulated by a local directory). The replay window held customer and plan writes only: activations, transfers and ledger entries across a PITR boundary were not exercised.

# 9. Exit codes of the backup tools

| Code | Meaning |
|---|---|
| 0 | OK |
| 1 | Unexpected failure (or a required secret file missing) |
| 3 | Dump failed |
| 4 | Compression or encryption failed |
| 5 | Set complete locally, off-host copy failed or still pending (retried by the next run; repeats while a backlog remains) |
| 6 | Refused by a safety rule |
| 7 | Checksum or manifest mismatch |
| 8 | Load failed |
| 9 | Binlog chain incomplete, or replay failed |
| 10 | Verification of the restored copy failed |
| 11 | Binlog archiving failed, or chain gap |
| 64 | Usage |
| 75 | Another run holds the lock (retry later) |
| 78 | Configuration or prerequisite error |

# 10. Troubleshooting a backup or restore

See `incident_troubleshooting_guide`, "Backup / restore failure". Typical causes: the backup account lacks a privilege; DDL ran during the dump; the encryption key is not available on the restore host; the engine's own client tools are missing (exit 78); a binlog expired before it was archived (exit 11: take a new full backup); `migrate.php verify` reports drift on a copy restored from another release.
