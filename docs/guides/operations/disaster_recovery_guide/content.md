# About this guide

This guide describes how to recover one ITC NetCore V1.0 instance from the loss of a component or from a bad release. It builds on `docs/BACKUP_RESTORE_RUNBOOK.md`, the backup and PITR tooling `install/backup/` (see `backup_restore_guide`), `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §4, `docs/OPERATOR_RECOVERY_RUNBOOK.md`, `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` and `install/freeradius/README.md`, and on the recovery options of the owner-decision consolidation (2026-10-29, §5).

> [!WARNING]
> Release Candidate, not yet production-accepted. No disaster-recovery exercise has been run on a target environment (TA-07 failure injection and TA-08 backup / restore are NOT_RUN). Lab results (restore drill on both engines; the 2026-10-29 backup and PITR drill on a 71 MiB synthetic database on both engines; release switch in about 0.05 s each way) are lab-scale only.

> [!NOTE]
> **RTO 4 h / RPO 15 min are PROVISIONAL engineering design targets only, not owner-approved SLAs.** They are not a commitment by ITC or by the ISP. The owner must choose a recovery option and fill in the values (§2); TA-08 is then measured against them.

> [!NOTE]
> V1.0 has **no database or RADIUS high availability**. One isolated instance per ISP (Option A); DB / RADIUS HA is outside the V1 scope, and no HA claim may be made before an owner-approved HA requirement and a failover drill (lock REL-06).

# 1. Principles

1. **Detect, collect evidence, then decide.** Never run a recovery procedure "to see what happens".
2. **Restore into new, empty databases** — never over the live one.
3. **Nothing is released automatically.** Held reservations, PENDING admission claims and CLAIMED vouchers wait for an audited operator decision after recovery.
4. **Never rewrite history.** No deletion or edit of the ledger, wallets, reservations, `radacct` or audit tables. Corrections are new, audited operations.
5. **Fail closed is expected.** While the database or FreeRADIUS is unavailable, new logins are refused rather than admitted unverified.
6. Production changes during recovery follow the emergency change path of `change_management_escalation`.

# 2. Recovery options and the provisional targets

The three options come from the owner-decision consolidation §5. Option 1 is covered by the runbooks in the release tree. **Option 2 is now implemented** by the `install/backup/` tooling and was lab-drilled on MariaDB 10.11 and MySQL 8.0. Option 3 is not implemented.

| | Option 1: daily logical backups | Option 2: Option 1 + binlog point-in-time recovery | Option 3: Option 2 + replica with manual failover |
|---|---|---|---|
| Architecture | Nightly encrypted dumps of the application and RADIUS databases, plus one before every upgrade; `config.php` separately | Plus row-format binary logs shipped off-host, encrypted, at a fixed interval | Plus an asynchronous replica on a separate host; runbook-driven promotion; application and FreeRADIUS re-pointed |
| Indicative RPO | Up to the backup interval (about 24 h) | Logical error with the server alive: about 0 (the replay stops just before the bad event; lab: 0 rows lost). Host lost: about the archive and off-host interval plus the transfer time (lab: 51.4 s at a 60 s interval, about 21 s at 30 s) | About the replication lag while healthy (backups still needed against logical errors, which a replica copies) |
| RTO driver | Restore, `verify`, health, preflight, manual reconciliation of the gap | Restore, replay of the binlogs since the last full set, then the same checks (lab tool run 13–31 s at 71 MiB; detection, decision, switch-over and reconciliation not included) | Promotion, re-pointing, the checks |
| Covered | **Yes** (backup runbook §2–§4; checklist F1–F3, I5; automated by `nuxr-backup.sh` and `nuxr-backup-verify.sh`) | **Tooling yes** (`nuxr-binlog-archive.sh`, `nuxr-restore.sh`; lab-drilled). **Still open:** the PITR section of the product runbook, a real off-host target, monitoring of the archive age, the owner's interval | **No** — needs an owner HA requirement under REL-06 first |
| Meets the provisional RPO 15 min | No | Only with an archive interval ≤ 15 min (the shipped timer runs every 5 min) **and** an off-host copy on every run that is monitored; to be shown on the target | Yes while the replica is healthy |
| Meets the provisional RTO 4 h | Not measured on a target | Not measured on a target | Not measured on a target |

**Owner fill-in (one copy per instance)**

| Field | Entry |
|---|---|
| Instance | |
| Option | ☐ 1 · ☐ 2 · ☐ 3 |
| Binlog archive interval and off-host target (Option 2) | |
| RTO (maximum time to restored service) | |
| RPO (maximum data loss) | |
| Retention / off-site location | |
| Drill cadence | |
| HA requirement (REL-06) | ☐ none for V1 · ☐ yes: |
| Owner name / date / signature | |

<!-- pagebreak -->

# 3. Scenario: database loss (corruption, deletion, disk failure)

| Phase | Action |
|---|---|
| Impact | New logins fail closed (FreeRADIUS cannot read policy or write admission claims); the staff UI and portal fail; `quota.php` exits 3; health reports `db` CRITICAL or UNKNOWN. Sessions already up stay on the NAS under the router's own limits |
| Contain | Stop the scheduled jobs (`cron.php`, `quota.php`, health) and put the web server into maintenance (503). Preserve whatever remains of the damaged database and its logs |
| Restore | `nuxr-restore.sh`: the latest verified set into a **new, empty** database (backup runbook §3 step 1). With Option 2: also replay the archived binlogs — `--stop-datetime` / `--stop-position` just before a destructive event when the server is alive, or `--pitr-to-end --binlog-dir <off-host archive>` when the host is lost |
| Verify | The restore tool checks the copy: fingerprint, finance-table row counts, `migrate.php verify` exit 0, `health.php --finance` `db`, `schema`, `finance` OK. After the switch, `preflight.php` must pass on the live configuration |
| Switch | Point `config.php` and FreeRADIUS at the new database; restart FreeRADIUS; reopen the web server, then the scheduled jobs; watch `health.php` for 30 minutes |
| Reconcile | Everything after the recovery point is missing (activations, transfers, voucher redemptions); with PITR the gap is only what never reached the archive. Rebuild the list from audit records, payment-provider records and NAS-side `radacct`; re-enter the business operations through the application with a reason. Then run `reconcile.php drift`, `finance-check`, `stale`, `admission-pending`, `voucher-review`, `balance-held` |

> [!WARNING]
> **Logical error (bad statement, dropped table or database) while the server is alive.** Do not replay binlogs by hand. A `DROP DATABASE` issued from a session whose default database is the application database stays in a manual replay and would drop the live database. `nuxr-restore.sh` decodes and scans the range first and refuses such statements (exit 6). Find the position of the bad event, restore with `--stop-position` into a new database, compare, then switch.

> [!FINANCE-WARNING]
> Activations that were in flight at the recovery point return with ACTIVE reservations. Resolve each by the recovery runbook §1. A reseller's credit that "looks wrong" after a restore is corrected only by an audited adjustment or reversal, never by SQL.

# 4. Scenario: application host loss

1. Provision a new host per checklist §A–§B (PHP 8.2, HTTPS, document-root protections).
2. Deploy the **same release artifact**, verified with `install/fresh/nuxr-artifact-verify.sh` (SHA-256, `RELEASE_SHA`, content rules).
3. Restore `config.php` from the encrypted `secrets.tar` of the latest backup set. Keeping the same `$app_secret` keeps sessions and links valid; a new value invalidates them (users log in again). A restore onto a freshly built server was **not** drilled in the lab; rehearse it on staging.
4. Re-install the scheduled jobs (checklist §I; `install/fresh/cron/itc-netcore.cron.example`, with `cron.php` run from `system/`) and the backup timers (`install/backup/systemd/`).
5. **CoA source address:** RouterOS accepts a Disconnect / CoA only from the address of one of its `/radius` entries. If the new host has a different address, update every NAS (second `/radius` entry for the new address with the same secret, and the UDP 3799 accept rule) — checklist H2.
6. Run `preflight.php` (exit 0) and `health.php --finance` (exit 0); test one staff login, one Disconnect, one activation on a test customer.

# 5. Scenario: FreeRADIUS loss

| Phase | Action |
|---|---|
| Impact | No authentication or accounting: new logins time out on the NAS; accounting freshness alarms (`accounting`); sessions already up continue on the NAS |
| Rebuild | FreeRADIUS 3.2.8 from `install/freeradius/` (site, modules, dictionary, guard, Hotspot slot verifier) plus the backed-up instance configuration, including `${confdir}/nuxr/nas_snmp` (mode 0600, owner `freerad`) and the net-snmp tools |
| Rules | `read_clients = yes`, `client_table = "nas"`; `delete_stale_sessions = no`; `radpostauth` off; process `TZ` = the application time zone; threaded under the service manager, **never `-X`**; SQL pool sized per the README |
| Address | Keep the same IP. If it changes, every NAS `/radius` entry must change, and the guard's SNMP community `addresses` and the UDP 161 rule on each NAS |
| Verify | `sudo -u freerad ${confdir}/nuxr/nuxr_guard_selfcheck` → `guard_selfcheck=OK`; one test login, accounting Start, one Disconnect; `preflight.php` |
| After a stall | A FreeRADIUS stall longer than the NAS retry window (about 9 s) can leave PPPoE admission claims PENDING (AV-1). List them with `reconcile.php admission-pending`; release only with NAS-side proof (recovery runbook §3) |

# 6. Scenario: NAS loss or replacement

1. Treat a lost or stolen NAS as a secret exposure: generate a **new** RADIUS secret (32+ random characters) and a new SNMP community for the replacement.
2. Onboard the replacement per the NAS runbook §2: same `nasname` address if possible (it must equal `/radius src-address` and the `NAS-IP-Address`; no NAT), type `mikrotik_snmp`, CoA port, `/radius incoming`, the firewall rules, PPP AAA accounting with interim updates, Hotspot `radius-interim-update=received`, NTP from two internal servers, IP pools named by the plans.
3. Update the guard credential file entry, then `nuxr_guard_selfcheck` must print OK for it. Restart FreeRADIUS after the NAS row change.
4. Verify: test login, accounting Start, one Disconnect; `preflight.php` `nas.verification` PASS.
5. **Open accounting rows of the lost NAS stay open** (lost Accounting-Stops are never fabricated). A subscriber's next PPPoE login is verified by the guard on that NAS address; it is admitted once the guard proves the old session gone. If the NAS address can no longer answer SNMP, those logins are refused (fail closed). Escalate to ITC; never close `radacct` rows or reservations by hand.
6. A NAS removed permanently follows NAS runbook §5 (its accounting history stays in `radacct`).

# 7. Scenario: bad release

From `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §4:

| Situation | Action |
|---|---|
| `migrate` failed or was interrupted, and no new-domain data exists | `php system/reseller/migrate.php rollback --all` (or `--to=<id>`). It drops only the objects the migrations created; the core fingerprint must equal the pre-migration one |
| New-domain data exists (any reseller row, or `tbl_customers.manager_id` set) | Schema rollback is **refused** (exit 2) by design. Restore the pre-upgrade backup and redeploy the previous code. Every operation since the backup is lost and must be reconciled |
| Code-only problem after a successful migration | Redeploy the previous release of ITC NetCore with the same schema. **Never** redeploy upstream PHPNuxBill over a migrated database |

Details: `upgrade_rollback_guide`.

# 8. After any recovery

- [ ] `preflight.php` exit 0 and `health.php --finance` exit 0
- [ ] Recovery-runbook daily checks run; every queue item has an owner
- [ ] Backup timers running again; a new full backup set taken and verified (`nuxr-backup.sh`, then `nuxr-backup-verify.sh`)
- [ ] Gap reconciliation recorded (what was re-entered, by whom, with which evidence)
- [ ] Secrets rotated where exposure is possible (NAS secret, SNMP community, `$app_secret`, database passwords)
- [ ] Incident record and post-incident review (`change_management_escalation`)
