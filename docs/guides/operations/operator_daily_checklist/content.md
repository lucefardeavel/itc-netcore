# About this checklist

This is the recurring check routine for the operator of **one** ITC NetCore V1.0 instance. Every command is read-only unless the row says otherwise. Every check comes from `docs/OPERATOR_RECOVERY_RUNBOOK.md` §0, `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` §F, §I, §J, `docs/TIME_CONTRACT.md` and `install/freeradius/README.md`. When a check is not healthy, follow the runbook section named in the "If not healthy" column; this checklist never authorizes a change by itself.

> [!WARNING]
> Release Candidate, not yet production-accepted. The thresholds below are the ones implemented in `system/reseller/health.php` and documented in the runbooks. They have been verified in the lab only; target acceptance (TA-01 … TA-10) has not been run.

**Conventions**
- All commands run from the application root as the PHP user unless stated.
- Credentials never go on a command line. The CLI tools read `config.php`.
- Record each run in the operations log: date, operator, result. Never record a secret, a voucher code or customer personal data in the log.
- Rows marked **Recommended practice** are ITC operating recommendations, not product requirements.

# 1. Daily checks

## 1.1 Platform health

| # | Check | Command / place | Healthy result | If not healthy |
|---|---|---|---|---|
| D1 | Overall health | `php system/reseller/health.php` (also runs every 5 min from monitoring) | Exit 0, every line `OK` | Each WARNING / CRITICAL line names the command that lists the records. See `monitoring_alerting_guide` |
| D2 | Finance integrity | `php system/reseller/health.php --finance` (also hourly from monitoring) | `finance` OK, `consistent` | CRITICAL: **stop selling**, run `reconcile.php finance-check`, see §3 below |
| D3 | Database contract and clock skew | health `db` line | `isolation=REPEATABLE-READ session_utc_offset=0s`, clock skew ≤ 5 s | WARNING above 5 s, CRITICAL above 30 s: check NTP on the database and application hosts |
| D4 | Schema readiness | health `schema` | `ready` | CRITICAL: escalate to ITC; do not run migrations outside a maintenance window |
| D5 | Accounting freshness | health `accounting` | OK | WARNING: open sessions but no accounting update for over 15 minutes: FreeRADIUS / NAS problem |
| D6 | Quota enforcer | health `quota_enforcer` | OK | CRITICAL: the `quota.php enforce` cron is not running while quota subscribers are online; restart it. WARNING: `php system/reseller/quota.php report` |

## 1.2 Held money and the review queues

These are the states the application deliberately leaves to an operator. **Nothing in this queue is ever released automatically.**

| # | Check | Command | Healthy result | If not healthy |
|---|---|---|---|---|
| D7 | Stale activations | `php system/reseller/reconcile.php stale --seconds=300`; health `activations_stale` | No line. Health WARN at 5 min, CRIT at 30 min | Recovery runbook §1 (resume or cancel once stale) |
| D8 | Activations needing reconciliation | Operations Center > Needs attention; health `reconciliation` | 0 | Recovery runbook §1. Funds stay reserved until an operator decides |
| D9 | Oldest ACTIVE reservation | health `reservations` | OK. WARN above 1 h, CRIT above 24 h | Find the activation that holds it (D7 / D8) and resolve it through the runbook. Reservations never expire on their own (N-09) |
| D10 | Drift report | `php system/reseller/reconcile.php drift` | Exit 0, no line | Recovery runbook §4, §4a, §6 per finding kind |
| D11 | Finance check | `php system/reseller/reconcile.php finance-check` | `{"finance_check":"CONSISTENT"}`, exit 0 | **Exit 4 is a security incident** (§3 below) |
| D12 | PPPoE admission claims PENDING | `php system/reseller/reconcile.php admission-pending`; health `admission_pending` (over 10 min) | No line | Recovery runbook §3: release only with NAS-side proof |
| D13 | Hotspot slots PENDING | health `admission_pending` (Hotspot part); `install/freeradius/nuxr_hs_admission_report.sql` | 0 | `install/freeradius/README.md` "Hotspot admission slots" |
| D14 | Vouchers under review | `php system/reseller/reconcile.php voucher-review`; health `vouchers_review` | No line | Recovery runbook §2 |
| D15 | Legacy balance operations HELD | `php system/reseller/reconcile.php balance-held`; health `balance_held` | No line | Recovery runbook §5 |
| D16 | Unconfirmed Disconnect / CoA | health `coa_failures` | 0 | Drift `SESSION_POLICY_STALE`; recovery runbook §4a |

> [!FINANCE-WARNING]
> A held reservation means the reseller's credit is blocked but nothing has been charged or granted. It is resolved only by an audited operator decision (resume, cancel, reconcile or rollback) through the Operations Center or `reconcile.php`. Never edit wallets, reservations or the ledger by SQL.

## 1.3 Backups

| # | Check | Where | Healthy result |
|---|---|---|---|
| D17 | Last night's backup set exists | `install/backup/nuxr-backup.sh` result (timer / log); newest set under the backup directory | Exit 0 (5 = set kept locally but the off-host copy failed); one complete set per night (no `.partial`), UTC timestamp, one encrypted dump per database |
| D18 | Backup record complete | the set's `backup.info` and `MANIFEST.sha256` | UTC times, sizes, SHA-256 and release recorded |
| D19 | `config.php` backup | the set's encrypted `secrets.tar` | Present in every set |
| D20 | Binlog archive current (point-in-time recovery) | `nuxr-binlog-archive.sh` result; age of the newest archived binlog, local and off-host | Exit 0; newest archive younger than the archive interval plus one run; no `CHAIN GAP` (exit 11: take a new full backup) |

## 1.4 NAS, guard and clocks

| # | Check | Command | Healthy result | If not healthy |
|---|---|---|---|---|
| D20 | PPPoE session guard readiness | `sudo -u freerad ${confdir}/nuxr/nuxr_guard_selfcheck` (on the FreeRADIUS host; also from cron with an alert) | `guard_selfcheck=OK`, one `status=OK` line per NAS | `incident_troubleshooting_guide` "SNMP verification failed" |
| D21 | NAS clock ahead | health `nas_clock` | 0 | Recovery runbook §4c |
| D22 | Host clocks | `chronyc tracking` (or `timedatectl`) on the application, database and FreeRADIUS hosts | Synchronised, UTC, within 5 s of each other | Repair NTP. Never set a clock by hand without NTP |
| D23 | NAS NTP (spot check) | RouterOS `/system ntp client print`, `/system clock print` against `date -u` | NTP enabled and synchronised; offset within 10 s (typically under 2 s) | Recovery runbook §4c |

> [!NOTE]
> health `nas_clock` sees only a NAS clock that is **ahead**, and only shortly after each Start / Interim. A clock that is behind is caught only by NTP monitoring on the NAS.

## 1.5 Logs

| # | Log | What to look for |
|---|---|---|
| D24 | PHP / application error log | `[nuxr] ref=…` entries. A staff user who reports an error gives the reference id; match it here |
| D25 | Logs > ITC NetCore, Logs > Radius (staff UI) | Unexpected administrative actions, repeated failures |
| D26 | FreeRADIUS log | `No connections available` (SQL pool exhaustion, A2-R condition C4), repeated `reject:` reasons, `reject:active-unverified` |
| D27 | Cron output | `SKIPPED (service busy…)` for the same customer on many consecutive runs (then run D7); `RENEWED meanwhile` is normal |
| D28 | Guard self-check log | Example location from the README: `/var/log/nuxr-guard-selfcheck.log` |

<!-- pagebreak -->

# 2. Weekly checks

| # | Check | Command / action | Healthy result |
|---|---|---|---|
| W1 | Release preflight (read-only) | `php system/reseller/preflight.php` | Exit 0; no FAIL. Watch `time.nas_clock`, `grants`, `nas.verification`, `quota.plans`, `plugins` |
| W2 | Plugin inventory | `php system/reseller/plugins.php --expect=<approved inventory>` (or `preflight.php --plugins-approved=<file>`) | No unknown, modified or missing file |
| W3 | Off-site backup copy | Backup target | At least one off-site copy of the week's backups and archived binlogs, within the agreed retention |
| W3a | Weekly restore test | `install/backup/nuxr-backup-verify.sh` result (`VERIFIED` record of the newest set) | Exit 0; the scratch database was dropped afterwards |
| W4 | Disk space | Database, backup target, log partitions | Within the sizing agreed at installation (checklist A8) |
| W5 | Expired without auto-renew | `php system/reseller/reconcile.php expired-autorenew` | Informational: reseller-owned customers that expired (V1 has no auto-renew for them) |
| W6 | Staff accounts | Settings > Administrator Users | No unknown account; leavers disabled (Agents are disabled, never deleted) |
| W7 | Operations Center history | Operations > Operations Center, last 7 days' outcomes | Every operator decision has a reason |
| W8 | Review queue ages | D7–D16 trend | No item older than one week. **Recommended practice** |

# 3. If `finance-check` exits 4 or health `finance` is CRITICAL

1. Stop selling: inform the Admins and resellers that activations are paused.
2. **Do not "fix" the numbers.** Exit 4 means a write happened outside the finance services (tampering, a plugin or a bug).
3. Preserve a database snapshot (a backup per the backup runbook) and the logs.
4. Escalate to ITC and the ISP's security contact (`change_management_escalation`).

# 4. Monthly checks

| # | Check | Action |
|---|---|---|
| M1 | Backup readability | Decrypt one recent backup on an isolated host and list its contents. **Recommended practice** (the runbook requires the quarterly restore drill) |
| M2 | Secret hygiene | Confirm no secret appears in shell history, tickets or logs (checklist C6) |
| M3 | NAS inventory | Every NAS row is type `mikrotik_snmp`, has a guard credential entry, and still matches its RouterOS `/radius src-address` |
| M4 | Rotation log | NAS secret rotations recorded (date, NAS, operator, never the secret) |
| M5 | Review open limitations | Re-read `known_limitations` for changes in the release notes |

# 5. Quarterly and yearly

| # | When | Check |
|---|---|---|
| Q1 | Quarterly (and before go-live) | Restore drill on a scratch database (backup runbook §4), recorded with date, engine, file size, SHA-256 and result |
| Y1 | At least yearly, and immediately after staff turnover, a suspected leak or a backup exposure | NAS secret rotation per NAS, in a maintenance window (NAS runbook §3) |
| Y2 | Yearly | SNMP community rotation for the guard (`install/freeradius/README.md` "Rotation"). **Recommended practice** |

# 6. Sign-off

| Date | Operator | Daily D1–D28 | Weekly W1–W8 | Exceptions and runbook section used |
|---|---|---|---|---|
| | | ☐ | ☐ | |
| | | ☐ | ☐ | |
| | | ☐ | ☐ | |
