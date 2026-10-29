# About this guide

This guide explains what ITC NetCore V1.0 exposes for monitoring, what to alert on and at which thresholds. Every threshold below is implemented in `system/reseller/health.php` or `system/reseller/preflight.php`, or documented in `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` §I–§J, `docs/OPERATOR_RECOVERY_RUNBOOK.md` §0, `docs/TIME_CONTRACT.md` §5 and `install/freeradius/README.md`. Nothing here adds a new threshold to the product.

> [!WARNING]
> Release Candidate, not yet production-accepted. **No monitoring product is integrated with V1.0.** Alerting relies on the exit codes and JSON of the CLI checks, run from cron or the ISP's own monitoring agent. Alert firing and clearing on a target (A2-R condition C4, TA-07) has not been tested.

# 1. Signal sources

| Source | Command | Schedule | Output |
|---|---|---|---|
| Health (operational) | `php system/reseller/health.php --json` | Every 5 minutes (checklist I3) | JSON; exit 0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN |
| Health (finance) | `php system/reseller/health.php --json --finance` | Hourly (checklist I4; expensive) | As above, plus the `finance` check |
| Guard readiness | `sudo -u freerad ${confdir}/nuxr/nuxr_guard_selfcheck` | Every few minutes, after every NAS or credential change, at FreeRADIUS start | `guard_selfcheck=OK` / `ERROR`; exit 0 only when every NAS is OK |
| Release preflight | `php system/reseller/preflight.php [--json]` | Before go-live, after every change, weekly | PASS / WARN / FAIL per check; exit 0, or 4 when any check FAILs |
| Review queues | `php system/reseller/reconcile.php drift`, `finance-check`, `stale`, `admission-pending`, `voucher-review`, `balance-held` | Daily (operator) | No line when healthy; `drift` / `finance-check` exit 4 on findings |
| Quota report | `php system/reseller/quota.php report` | On `quota_enforcer` WARNING | Over-quota open sessions |

Properties of `health.php`: CLI only, read-only, plugins not loaded; every metric is a count or an age; no record content and no secret is printed. Each WARNING / CRITICAL line names the command that lists the records.

JSON shape:

```json
{"status":"WARNING","checks":[{"check":"reservations","level":1,"status":"WARNING","value":5400,"detail":"oldest ACTIVE reservation 5400s"}]}
```

# 2. Health checks and thresholds

| Check | What it measures | WARNING | CRITICAL | Runbook action |
|---|---|---|---|---|
| `db` | Database reachable, `REPEATABLE-READ`, UTC session, database ↔ PHP clock skew | Skew > 5 s | Wrong isolation, non-UTC session, or skew > 30 s | Fix the database configuration or NTP |
| `schema` | Reseller schema ready, including CHECK expressions and enforcement | — | Not ready | Stop selling; escalate |
| `activations_stale` | Non-terminal activations not updated | > 5 min | > 30 min | `reconcile.php stale`; recovery runbook §1 |
| `reconciliation` | Activations in `RECONCILIATION_REQUIRED` (funds held) | ≥ 1 | — | Recovery runbook §1 |
| `reservations` | Age of the oldest ACTIVE wallet reservation (never expires on its own) | > 1 h | > 24 h | Find and resolve the holding activation |
| `admission_pending` | PPPoE claims / Hotspot slots PENDING > 10 min (locked-out subscribers) | ≥ 1 | — | `reconcile.php admission-pending`; `nuxr_hs_admission_report.sql`; recovery runbook §3 |
| `vouchers_review` | Vouchers left `CLAIMED` | ≥ 1 | — | `reconcile.php voucher-review`; runbook §2 |
| `balance_held` | Legacy balance operations HELD | ≥ 1 | — | `reconcile.php balance-held`; runbook §5 |
| `accounting` | Newest `radacct` update while sessions are open | > 15 min | — | FreeRADIUS / NAS accounting problem |
| `nas_clock` | Open sessions stamped > 5 s in the future (a NAS clock ahead) | ≥ 1 | — | Recovery runbook §4c |
| `coa_failures` | Activations whose latest session-end in the last hour is not confirmed | ≥ 1 | — | Drift `SESSION_POLICY_STALE`; runbook §4a |
| `quota_enforcer` | Quota enforcer heartbeat while quota subscribers are online; over-quota open sessions | Over-quota session still open | Quota subscribers online and no enforcer pass for > 3 min | Restart the enforcer cron; `quota.php report`; runbook §4b |
| `finance` (`--finance`) | Whole-database finance integrity | — | Any integrity issue | **Stop selling**; `reconcile.php finance-check`; security incident |

A check that cannot run reports UNKNOWN (exit 3) with an error reference in the server log; treat UNKNOWN like CRITICAL for paging.

<!-- pagebreak -->

# 3. Alerting policy (checklist §J)

**Page the operator on any CRITICAL, and on any WARNING that lasts more than 30 minutes.**

| Group | Checks | Meaning for the business |
|---|---|---|
| Stop selling and investigate | `db`, `schema`, `finance` (CRITICAL) | The instance cannot be trusted to bill correctly |
| Held money | `activations_stale`, `reconciliation`, `reservations` | Reseller credit is blocked; nothing is released automatically |
| Locked-out subscribers | `admission_pending` | Subscribers refused at login until an audited release |
| Operator review queue | `vouchers_review`, `balance_held` | Revenue items waiting for a decision |
| Network / RADIUS | `accounting`, `coa_failures`, `nas_clock`, `quota_enforcer` | Accounting stopped, a session kept an old policy, a NAS clock ahead, or quota not enforced |

Additional alerts from the product documentation:

| Signal | Rule | Source |
|---|---|---|
| Guard self-check | Alert on any non-zero exit of `nuxr_guard_selfcheck`; each ERROR line names a NAS whose PPPoE subscribers cannot re-login after a lost Stop | `install/freeradius/README.md` "Readiness check" (TD-1b) |
| FreeRADIUS SQL pool | Alert on the log pattern `No connections available` | A2-R condition C4 |
| Database connections | Alert on `Threads_connected` against `max_connections` (the staging package stops a test above 70 %) | A2-R condition C4; `docs/STAGING_DEPLOYMENT_PACKAGE.md` §6 |
| FreeRADIUS liveness and host stalls | Monitor the FreeRADIUS process and VM / host stalls (a stall > ~9 s leaves PENDING claims) | `install/freeradius/README.md` "Known limitation" (AV-1) |
| NAS clock behind | Not visible to `health.php`; monitor NTP on each NAS | `docs/TIME_CONTRACT.md` §5 |
| Preflight | Any FAIL after a change blocks reopening | Deployment runbook §3 step 6 |

> [!NOTE]
> The A2-R residual risk ("MITIGATED — EFFECT UNVERIFIED") makes the C4 alerts part of the V1 launch conditions recorded for the owner. Their firing and clearing on the target is still to be evidenced.

# 4. Example integration (cron, generic)

The product provides the commands and exit codes only. A minimal example that sends a syslog alert from cron (adapt to the ISP's monitoring agent; paths are examples):

```bash
*/5 * * * * cd /srv/itcnc/app/current && php system/reseller/health.php --json > /var/lib/itcnc/health.json; rc=$?; [ $rc -eq 0 ] || logger -p daemon.warning -t itcnc-health "health exit $rc"
```

The guard self-check example from the FreeRADIUS README (root crontab):

```bash
*/5 * * * * sudo -u freerad /etc/freeradius/3.0/nuxr/nuxr_guard_selfcheck >/var/log/nuxr-guard-selfcheck.log 2>&1 || logger -p auth.crit -t nuxr-guard "PPPoE session guard cannot verify a NAS (see /var/log/nuxr-guard-selfcheck.log)"
```

# 5. Preflight checks that matter for monitoring

| Check | Rule |
|---|---|
| `time.sql_session_zone`, `time.clock_skew` | SQL session UTC; database / PHP skew < 5 s |
| `time.nas_clock` | WARN above 5 s ahead, FAIL above 10 s (the session-end cut-off allowance); FAIL "could not run" when the RADIUS accounting table is unavailable |
| `grants` | The runtime account has no global privileges and no UPDATE / DELETE on the ledger and audit tables |
| `nas.verification` | Every NAS is of type `mikrotik_snmp`; no NAS at all FAILs in release mode |
| `quota.plans` | No enabled Limited plan with an invalid quota |
| `plugins` | With `--plugins-approved`: no unknown, modified or missing plugin file |
| `radius_checks`, `schema` | CHECK constraints present and enforced (MariaDB `check_constraint_checks = ON`) |

# 6. What is not monitored by the product

- A NAS clock that is **behind** (only NTP monitoring on the NAS sees it).
- Sessions that changed plan while a NAS clock was more than 10 s ahead (RAD-3a residual): not reported automatically; found by the manual procedure of recovery runbook §4c.
- Capacity on the target hardware (TA-09 NOT_RUN).
- Certificate expiry, disk space, backup success: the ISP's monitoring must cover them. For backups, alert on every non-zero exit code of the `install/backup/` tools (for example 5 = off-host copy failed, 7 = checksum mismatch, 11 = binlog chain gap) and on the age of the newest backup set and of the newest archived binlog (`backup_restore_guide`).
