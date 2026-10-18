# V1 target-environment acceptance procedure and evidence template

Scope: everything the isolated lab (`dev/wp8`, RouterOS CHR 7.24.5 + FreeRADIUS 3.2.8 + MariaDB / MySQL) **cannot** prove for a real
installation. Each test below is a procedure for the operator on the **approved staging copy of the target** (same RouterOS version /
model, same FreeRADIUS and database hosts, synthetic subscribers only). Nothing here may be run against a router that carries
customer traffic, and nothing here is evidence until the table in section 3 is filled from a real run.

**Status rule:** every row starts as `NOT_RUN`. A row becomes `PASS` / `FAIL` only with the attached evidence file named in the table.
The isolated CHR lab is not proof for an untested production router or server (owner directive).

Related: `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` (configuration), `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md`,
`docs/BACKUP_RESTORE_RUNBOOK.md`, `docs/OPERATOR_RECOVERY_RUNBOOK.md`, `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md`,
`owner-review/WP10/N1_PPPOE_OVERSHOOT.md`, `owner-review/WP10/N2_PERIOD_OWNERSHIP.md`.

## 1. Preconditions (stop if any is false)

| # | Precondition |
|---|---|
| P1 | Written owner authorization naming the staging NAS, the staging database and the time window. |
| P2 | The NAS carries no customer traffic; the RADIUS secret of the staging NAS differs from every production NAS. |
| P3 | Backup of the staging database taken and a restore tested (`docs/BACKUP_RESTORE_RUNBOOK.md`). |
| P4 | Only synthetic identities (`tgt_*`) are created; none exists on a production NAS. |
| P5 | Secrets are passed through the environment / files with mode 0600, never on a command line, never in the evidence. |

## 2. Procedures

Run in this order. Capture the command output to `owner-review/WP10/target/evidence/<id>.txt` (redact secrets first).

### TA-01 Preflight and health (deployment gate)
```bash
php system/reseller/preflight.php          # must exit 0
php system/reseller/health.php --json --finance
```
Pass: preflight exit 0 (UTC + clock skew, least-privilege grants, CHECK enforcement, release profile); health exit 0, or only WARNINGs
that are explained in the evidence.

### TA-02 Clock discipline (N2 requirement)
Requirements: the application, database, FreeRADIUS and NAS hosts run NTP in **UTC**; maximum permitted drift **5 s** between
application / database / FreeRADIUS (preflight `time.clock_skew`) and **60 s** for a NAS (operations); the health check `nas_clock`
warns above 120 s ahead. Quota *attribution* does not depend on the NAS clock (the period owns the accounting rows that arrived after it
started), but accounting display, freshness and the pre-V1.1 fallback rule do.
1. On every host: `chronyc tracking` (or `timedatectl`) - offset and `System clock synchronized: yes`.
2. On the NAS: `/system ntp client print`, `/system clock print`; compare with `date -u` on the application host.
3. Log in one synthetic PPPoE user and compare `radacct.acctstarttime` with the arrival time of the Accounting-Start in the
   FreeRADIUS log (the NAS stamp may be 1-2 s early; more than 60 s is a FAIL).
Pass: all offsets within limits; evidence attached.

### TA-03 NAS contract (RouterOS version / model of the target)
Checklist items H1 - H7 on the staging NAS: `/radius print detail`, `/radius incoming print` (`accept=yes`, port = `nas.ports` or 3799),
`/ppp aaa print` (accounting + interim on, no fixed interim override), no local `/ppp secret` shadowing, one Disconnect from the
application (`docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md`). Record the RouterOS version and board.

### TA-04 Quota capability probe on the target NAS
For a PPPoE GB plan and a Hotspot GB plan (synthetic subscriber, small quota, e.g. 20 MB):
1. After login `/ppp active print detail` shows `limit-bytes-in` / `limit-bytes-out` = the per-session share
   (PPPoE: per direction; Hotspot: `limit-bytes-total`).
2. `Acct-Interim-Interval` from the reply is the interval seen in `radacct.acctupdatetime` spacing.
3. A Disconnect-Request is ACKed and followed by an Accounting-Stop within 6 s (`php system/reseller/quota.php report`).
The lab results for 7.24.5 are in `owner-review/WP10/evidence/quota/`; a different version or model must repeat them.

### TA-05 GB quota lifecycle with measured overshoot (N1)
Run the procedure in `owner-review/WP10/N1_PPPOE_OVERSHOOT.md` section "Reproducing on a target": a PPPoE subscriber with mixed traffic at
the staging line rate, `nuxr_quota_interim_seconds` = 30 and enforcer `--every=5` (the owner's provisional N1 = A + G settings; the final
values are fixed by this test and TA-09). Record per case: quota, rate, `t_cross`, `t_stop`, overshoot, termination cause.
Pass: (a) overshoot within the bound `min(R, r x (I + P + c))` for the configured values; the enforcer ends the session with `CONFIRMED`;
(b) **Option G**: `php system/reseller/quota.php usage <user>` shows `actual` > quota and `billable` = quota; the wallet / ledger rows are
unchanged by the overshoot (`php system/reseller/health.php --finance` OK); a renewal afterwards starts with `actual = 0`;
(c) normal-operation and failure-operation figures are reported **separately** (enforcer stopped / CoA blocked: overshoot up to the whole quota on PPPoE;
see `owner-review/WP10/N1_G_COMMERCIAL_QUOTA_CONTRACT.md` section 2.2).

### TA-06 Renewal (Option B) on the target
Synthetic subscriber with an open session; renew; verify (a) the previous session is ended and `CONFIRMED`, (b) the next session starts
under the new policy with the new period quota and only post-renewal usage counted, (c) no second settlement (ledger conservation
`php system/reseller/health.php --finance`), (d) accounting rows are retained. Repeat with an immediate re-dial (inside 10 s of the renewal).

### TA-07 Failure injection on staging
Stop the quota enforcer for 2 minutes with an over-quota session online: `quota_enforcer` is CRITICAL, resumes cleanly. Block the CoA
port for one pass: `COA_NO_RESPONSE`, retry after 15 s, no claim of termination. Stop FreeRADIUS accounting for one interval: the
session is not charged twice after recovery.

### TA-08 Deployment, backup and restore on the target
`docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` upgrade + rollback on a copy; `docs/BACKUP_RESTORE_RUNBOOK.md` restore drill on the target backup
chain; `dev/wp10/drill.sh` is the lab reference.

### TA-09 Capacity on target hardware
`dev/wp10/load.sh` shape (bounded, paced; never exhaust the SQL pool). Size the FreeRADIUS SQL pool per `install/freeradius/README.md`.
For N1 also measure the accounting write rate at the chosen interim with `dev/wp10/acct_load.sh` shape: `online quota subscribers / interim`
Interim-Updates per second through the real FreeRADIUS + database, together with the enforcer pass (`--every=5`).
Pass (all, at the planned peak of simultaneously online quota subscribers): no lost accounting request; p99 latency below 250 ms; enforcer
pass time below the `--every` interval; no `No connections available` in the FreeRADIUS log; FreeRADIUS SQL pool in use below 50 %;
database `Threads_connected` far below `max_connections`. The lab numbers (`owner-review/WP10/CAPACITY_AND_ACCOUNTING_LOAD.md`) are not
a promise for the target hardware.

### TA-10 Security on the target
TLS, headers, session cookies, rate limits behind the real reverse proxy; least-privilege database accounts (checklist D); the
preflight grants check; no installer reachable after install.

## 3. Evidence table (fill from real runs only)

| ID | Test | Target (version / model / host) | Date (UTC) | Operator | Result | Evidence file |
|---|---|---|---|---|---|---|
| TA-01 | Preflight + health | | | | NOT_RUN | |
| TA-02 | Clock discipline | | | | NOT_RUN | |
| TA-03 | NAS contract | | | | NOT_RUN | |
| TA-04 | Quota capability probe | | | | NOT_RUN | |
| TA-05 | GB quota overshoot (N1) | | | | NOT_RUN | |
| TA-06 | Renewal Option B | | | | NOT_RUN | |
| TA-07 | Failure injection | | | | NOT_RUN | |
| TA-08 | Deploy / backup / restore | | | | NOT_RUN | |
| TA-09 | Capacity | | | | NOT_RUN | |
| TA-10 | Security on target | | | | NOT_RUN | |

Sign-off: `READY_FOR_PRODUCTION_OWNER_APPROVAL` requires every row `PASS` with its evidence file; a single `FAIL` or `NOT_RUN` keeps
the release at `RELEASE_BLOCKED_WITH_EVIDENCE`.
