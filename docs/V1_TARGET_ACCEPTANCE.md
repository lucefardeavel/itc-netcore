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
application / database / FreeRADIUS (preflight `time.clock_skew`) and **10 s** for a NAS (hard limit of the session-end cut-off, B-L08; 2 s typical with NTP); the health check `nas_clock`
warns above 5 s ahead. Quota *attribution* does not depend on the NAS clock (the period owns the accounting rows that arrived after it
started), but accounting display, freshness and the pre-V1.1 fallback rule do.
1. On every host: `chronyc tracking` (or `timedatectl`) - offset and `System clock synchronized: yes`.
2. On the NAS: `/system ntp client print`, `/system clock print`; compare with `date -u` on the application host.
3. Log in one synthetic PPPoE user and compare `radacct.acctstarttime` with the arrival time of the Accounting-Start in the
   FreeRADIUS log (the NAS stamp may be 1-2 s early; more than 10 s is a FAIL).
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

## 2A. Execution sheets with explicit pass / fail criteria

How to read: run in order; every command's output goes to `owner-review/WP10/target/evidence/<TA id>.txt` (secrets removed first); a criterion that is not met is a **FAIL**; a test that was
not run is **NOT_RUN**; one FAIL stops the sequence unless the sheet says otherwise. "Eval" = `python3 dev/staging/ta_eval.py ...` (reads the evidence file, prints PASS / FAIL with the reason).
Environment: `docs/STAGING_DEPLOYMENT_PACKAGE.md` steps S1 - S12 done.

### TA-01 Preflight and health
- Run: `php system/reseller/preflight.php > TA-01-preflight.txt; echo "preflight=$?" >> TA-01-preflight.txt`; `php system/reseller/health.php --json --finance > TA-01-health.json`.
- PASS: preflight exit 0 (every check PASS; WARN only with a written explanation: `stage.https` WARN is not acceptable on the target); health status OK or WARNING with each WARNING explained; the eight required checks present (`keys.app_secret`, `isolation`, `time.sql_session_zone`, `time.clock_skew`, `schema`, `radius_checks`, `identity.collisions`, `grants`).
- FAIL: any FAIL line; health CRITICAL / UNKNOWN. Eval: `ta_eval.py TA-01 --preflight TA-01-preflight.txt --health TA-01-health.json`.

### TA-02 Clock discipline
- Run on the application, database, FreeRADIUS hosts and every staging NAS: `chronyc tracking` (or `timedatectl`), and on the NAS `/system ntp client print`, `/system clock print`; compare with `date -u` taken at the same moment; write `<host> <offset seconds> <core|nas>` lines to `TA-02-offsets.txt`. Then log in one synthetic PPPoE user and compare `radacct.acctstarttime` with the arrival time of the Accounting-Start in the FreeRADIUS log.
- PASS: `System clock synchronized: yes` and NTP client enabled on every host and NAS; core offsets (application / database / FreeRADIUS) <= 5 s; NAS offset <= 10 s; the Accounting-Start stamp within 10 s of its arrival.
- FAIL: any offset above its limit, NTP disabled / unsynchronised, fewer than the 3 core hosts + 1 NAS measured. Eval: `ta_eval.py TA-02 --offsets TA-02-offsets.txt`.

### TA-03 NAS contract
- Run: the commands of `dev/staging/staging_nas.rsc.tpl` section 0 (prior state) and 1 - 6; then `/radius print detail`, `/radius incoming print`, `/ppp aaa print`, `/ppp secret print where name~"tgt_"`, `/ip hotspot user print where name~"tgt_"`; one Disconnect from the application (`php system/reseller/reconcile.php session-end <ACT-reference>` of a RENEW / CHANGE_PLAN activation, or the quota enforcer on an over-quota session) for one synthetic session.
- PASS: `nasname` = `/radius src-address` = the `NAS-IP-Address` seen in `radacct.nasipaddress`; `/radius incoming` `accept=yes` and the port equals `nas.ports` (or 3799); `require-message-auth` yes-for-request-resp; PPP AAA accounting on with interim; **no** local `/ppp secret` or hotspot user named `tgt_*`; the Disconnect returns ACK and the Accounting-Stop arrives within 6 s.
- FAIL: any mismatch above; a local user shadowing a RADIUS identity; Disconnect NAK / no answer. Record RouterOS version and board in the evidence.

### TA-04 Quota capability probe (PPPoE and Hotspot GB plans, 20 MB quota, synthetic subscriber)
- Run: dial, then `/ppp active print detail` (PPPoE) / `/ip hotspot active print detail` (Hotspot); read `radacct.acctupdatetime` spacing for 3 intervals; send one Disconnect; send a second Disconnect for the vanished session.
- PASS: PPPoE `limit-bytes-in` = `limit-bytes-out` = the remaining quota (20000000); Hotspot `limit-bytes-total` = the remaining quota; update spacing equals `nuxr_quota_interim_seconds` (30 s) +/- 5 s; Disconnect ACK then Stop within 6 s; the second Disconnect gets NAK 406 (never 503, never an ACK).
- FAIL: a limit missing or different; interim spacing not following the RADIUS value; Disconnect not ACKed. A different RouterOS version from the lab (7.24.5) must also record whether the lab findings hold (PPPoE ignores `Mikrotik-Total-Limit`; byte-limit / interim CoA are ACKed and not applied).

### TA-05 GB quota overshoot (N1 = A + G)
- Run: PPPoE subscriber, quota Q (for example 60 MB) at the staging line rate; mixed upload + download traffic; settings `nuxr_quota_interim_seconds` = 30, `quota.php enforce --loop=55 --every=5` running. Record: `t_cross = Q / r`, `t_stop = acctsessiontime`, `overshoot = octets - Q`, `acctterminatecause`. Repeat at two rates. Then the **failure operation**: stop the enforcer, repeat once.
- PASS (normal operation): `overshoot <= min(Q, r x (30 + 5 + 1) s)` (r measured as octets / duration), the enforcer's Disconnect is `CONFIRMED`; (**Option G**) `php system/reseller/quota.php usage <user>` shows `actual` > Q and `billable` = Q, the wallet and ledger rows are unchanged by the overshoot (`health.php --finance` OK), and after a renewal `actual` = 0; (failure operation) the session ends at the NAS per-direction cap with `overshoot <= 1.05 x Q`, reported separately from the normal figure.
- FAIL: overshoot above the bound; billable above Q; any ledger / wallet movement caused by the overshoot; the overshoot carried into the renewal.

### TA-06 Renewal (Option B) and the time contract
- Run: synthetic subscriber with an open session; renew; then dial again at once (inside 10 s of the renewal); then repeat with the accounting path paused for one interval (delayed accounting).
- PASS: the previous session is ended and `CONFIRMED`; the next session starts with the full new-period share and counts only post-renewal usage (the redial inside 10 s is counted, the old session's traffic is not); exactly one settlement (`health.php --finance` OK; one `ACTIVATION_DEBIT` for the renewal); accounting rows retained; a Start delivered after the renewal is counted in the new period only.
- FAIL: old usage mixed into the new period, a missing or second settlement, a session left running with the old policy.

### TA-07 Failure injection (staging only)
- Run: (a) stop the quota enforcer for 2 minutes with an over-quota session online; (b) block the CoA port on the NAS for one enforcer pass; (c) pause FreeRADIUS accounting for one interval.
- PASS: (a) `health.php` `quota_enforcer` CRITICAL within 2 minutes, cleared after the restart, the session ended after the restart; (b) `COA_NO_RESPONSE` reported, retried about 15 s later, never reported as ended, `SESSION_POLICY_STALE` / `activations_stale` visible until the Stop arrives; (c) after recovery the session is not charged twice (usage never decreases; one row).
- FAIL: an alert that does not fire or does not clear, a fabricated "ended", a double count.

### TA-08 Deployment, backup, restore, rollback
- Run: upgrade on a copy (`docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` section 3), rollback (section 4), restore drill from the target's own backup chain (`docs/BACKUP_RESTORE_RUNBOOK.md` section 4).
- PASS: restored databases pass `migrate.php verify`, preflight and `health.php --finance`; row counts and the ledger / wallet reconciliation equal the pre-backup values; CHECK constraints survive; the previous artifact runs after rollback.
- FAIL: any difference in the reconciliation, a missing constraint, a failed verify.

### TA-09 Capacity
- Run: seed `dev/staging/seed_quota_rows.sql` (planned number of online quota subscribers S); `NUXR_STAGING_RADIUS_SECRET=... php dev/staging/acct_gen.php --host=<freeradius> --subs=S --interim=30 --seconds=60 --out=TA-09-load.jsonl` at S and at 1.5 x S; meanwhile `time php system/reseller/quota.php report` every 5 s (the enforcer scan cost) and `SHOW GLOBAL STATUS` deltas; FreeRADIUS log excerpt of the window.
- PASS: no lost accounting request; p99 latency < 250 ms; the enforcer pass < `--every` (5 s); no `No connections available` in the FreeRADIUS log; FreeRADIUS SQL pool in use < 50 %; `Threads_connected` < 70 % of `max_connections`; `Aborted_connects` unchanged.
- FAIL: any of the above; the first window above 5 % loss stops the test. If it fails at the planned S, the lever is `nuxr_quota_interim_seconds` (a larger value lowers the load and raises the normal-operation overshoot bound by the same factor): re-decide N1 before launch. Eval: `ta_eval.py TA-09 --load TA-09-load.jsonl --pass-seconds <measured> --freeradius-log <excerpt>`.
- Never to the limit: the pool exhaustion reproduction (A2-R) is forbidden here as everywhere; the generator is paced and bounded.

### TA-10 Security on the target
- Run: `curl -sI https://<host>/` and a login response capture; `curl -sI http://<host>/` (redirect); `curl -sI https://<host>/update.php`, `/install/`, `/config.php`, `/system/`; the rate-limit behaviour of the front for the login route (a few failed logins only); `SHOW GRANTS` of the application and FreeRADIUS accounts (and the preflight `grants` check of TA-01).
- PASS: HTTPS only with HSTS; `X-Content-Type-Options: nosniff` and anti-framing; session cookie `HttpOnly; Secure; SameSite`; HTTP redirects to HTTPS; `update.php` 403; `install/`, `config.php`, `system/` not served; the application / FreeRADIUS accounts hold no global privileges; installer unreachable.
- FAIL: any item missing. Eval (headers): `ta_eval.py TA-10 --headers TA-10-headers.txt`.

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
