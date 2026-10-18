# Isolated target staging - deployment package

Purpose: everything an operator needs to stand up the **approved, isolated staging copy of the target** and run `docs/V1_TARGET_ACCEPTANCE.md` (TA-01 .. TA-10)
on it. **Nothing in this package has been executed on a target**: no host, router, database or address has been named or assumed. It is prepared from the lab
(RouterOS CHR 7.24.5, FreeRADIUS 3.2.8, MariaDB 10.11 / MySQL 8.0, PHP 8.2) and from the runbooks; where a number comes from the lab it says so.

Preconditions: written authorization (`owner-review/WP10/TARGET_AUTHORIZATION_REQUEST.md` answered), P1 - P5 of `docs/V1_TARGET_ACCEPTANCE.md`, **synthetic data only**
(`tgt_*`), no production database, secret or customer record, no NAS carrying customer traffic.

## 1. Topology

```
 operator ──443──> [ application host ]──3306──> [ database host ]<──3306── [ FreeRADIUS host ]
                       │   (PHP, web, cron: quota.php, cron.php, health.php)                │
                       │ UDP CoA / Disconnect (nas.ports or 3799)            UDP 1812 / 1813 ▲
                       ▼                                                                     │
                 [ staging NAS: same RouterOS version + model as production ]───────────────┘
                       │ isolated interface / VLAN (no customers)
                       ▼
                 [ synthetic client: second router / CHR / Linux pppoe client ]   [ load generator host (may be the application host) ] ──UDP 1813──> FreeRADIUS
 NTP source reachable by all of the above (UDP 123)
```

The application and the FreeRADIUS host may share a machine on a small staging; the **database must be a separate engine instance from production** (and from every other instance: Option A isolation).

## 2. Hosts and representative sizing

| Host | Staging minimum (to be representative) | Basis |
|---|---|---|
| Application | 2 vCPU, 4 GB RAM, SSD | lab: the PHP workers of the load tests were light |
| Database (one engine: MariaDB 10.11 or MySQL 8.0 as in production) | 4 vCPU, 8 GB RAM, **disk-backed SSD** storage, `innodb_buffer_pool_size` >= 1 GB, `max_connections` >= 151 | lab (tmpfs database, 12 shared vCPU): up to about 3 cores of database CPU at 250 Interim-Updates/s |
| FreeRADIUS | 4 vCPU, 4 GB RAM, `thread pool max_servers` 32, `sql pool max` 32 (shipped) | lab: up to about 2 cores at 250 Interim-Updates/s |
| Staging NAS | the production model and RouterOS version (capability findings are version / model specific) | TA-04 |
| Synthetic client | any device able to dial PPPoE and Hotspot on the isolated segment | TA-05 / TA-06 |

These are **lab-derived minima for a representative staging**, not production sizing: production sizing is the output of TA-09 for the planned number of simultaneously online quota
subscribers (`owner-review/WP10/CAPACITY_AND_ACCOUNTING_LOAD.md`: zero loss up to 7,500 in the lab with the shipped settings).

## 3. Network matrix (staging)

| From | To | Port | Purpose |
|---|---|---|---|
| operator | application | TCP 443 | web UI, TA-10 |
| application, FreeRADIUS | database | TCP 3306 | SQL (only these two hosts) |
| NAS | FreeRADIUS | UDP 1812 / 1813 | authentication / accounting (no NAT: NAS-IP-Address = `nas.nasname` = `/radius src-address`) |
| application | NAS | UDP `nas.ports` (default 3799) | Disconnect / CoA (quota enforcer, renewal) |
| load generator | FreeRADIUS | UDP 1813 | TA-09 accounting load (synthetic `tgt_*`, NAS-IP a documentation address) |
| every host | NTP | UDP 123 | TA-02 |
| operator | hosts | TCP 22 | administration |
| (none) | NAS API 8728 / 8729 | - | **not used** by the release profile (legacy drivers disabled); do not open it |

The database is not reachable from anywhere else; no RADIUS or API port is exposed to the internet.

## 4. Deployment steps

Each step cites the checklist item it satisfies (`docs/PRODUCTION_CONFIGURATION_CHECKLIST.md`). Capture the output of every step as evidence (secrets redacted first).

| # | Step | Checklist | Evidence |
|---|---|---|---|
| S1 | Verify the artifact: `git rev-parse HEAD` equals the candidate SHA named in the acceptance package; `sha256sum -c docs/claude-development-pack/MANIFEST.sha256` (42 / 42); `python3 dev/wp10/notices_bundle.py` run for the notices | - | SHA, manifest output |
| S2 | OS baseline: UTC, NTP client (`chronyc tracking`), firewall per section 3 | A5, A7 | `chronyc tracking` per host |
| S3 | Install the database engine; `transaction_isolation = REPEATABLE-READ`, global `time_zone = '+00:00'`, MariaDB `check_constraint_checks = ON` | A2 - A4, A6 | `SHOW VARIABLES` extract |
| S4 | Create the accounts: `php system/reseller/grants.php --app=<user>` and `--radius=<user>` (least privilege); a separate DDL account for migrations; passwords from files mode 0600 | D1 - D4 | `SHOW GRANTS` (no password) |
| S5 | Back up (an empty database is fine), then `php system/reseller/migrate.php preflight`, `migrate`, `verify` (all exit 0) | E1, E2 | exit codes |
| S6 | Application config (`config.php`, mode 0640): `$app_secret`, `$api_secret` (32+ random characters each), least-privilege `$db_pass`, `$_app_stage = 'Live'`, no `$legacy_devices_enabled`, no `$radius_rest_secret`; HTTPS front with the headers of B1 - B8 | B, C | `curl -I` outputs |
| S7 | FreeRADIUS: the shipped site and modules from `install/freeradius/`, `read_clients = yes` / `client_table = nas`, admission tables (`nuxr_admission.sql`, `nuxr_hs_admission.sql`), FreeRADIUS TZ = the application zone, credentials files 0600, threaded (never `-X`), pool per `install/freeradius/README.md` | E3, G1 - G5 | `radiusd -C` result, pool settings |
| S8 | Insert the staging NAS row (`nasname` = the NAS source address, secret = the staging secret, `ports` = the CoA port) through the application; restart FreeRADIUS | G2, H1 | `nas` row (secret masked) |
| S9 | Configure the staging NAS from `dev/staging/staging_nas.rsc.tpl` (section 0 first: capture the prior values) | H1 - H8 | `/radius print detail` etc. (secret removed) |
| S10 | Cron (as the PHP user): `cron.php` every 5 min, `health.php --json` every 5 min, `health.php --json --finance` hourly, `quota.php enforce --loop=55 --every=5` every minute | I1 - I6 | crontab |
| S11 | Take a backup and test the restore on a scratch database | F1, F2 | restore log |
| S12 | Create the synthetic reseller chain and plans through the application (admin UI): one reseller chain with funded wallets, one Hotspot GB plan and one PPPoE GB plan with a **small quota** (for example 20 MB) and a time plan; synthetic customers `tgt_*`. For TA-09 seed quota rows with `dev/staging/seed_quota_rows.sql` | - | list of created objects |
| S13 | Run TA-01 .. TA-10 in order (`docs/V1_TARGET_ACCEPTANCE.md` section 2A); evaluate the mechanical ones with `dev/staging/ta_eval.py` | - | `owner-review/WP10/target/evidence/<id>.txt` |
| S14 | Teardown: remove the `tgt_*` users, plans, pools and the test NAS entries; restore the NAS values captured in S9; destroy the staging NAS secret; keep the evidence | - | restore confirmation |

## 4A. Tooling validated in the lab (not on a target)

`dev/staging/acct_gen.php` was run against the lab FreeRADIUS (120 synthetic Starts + 40 Interim-Updates: all acknowledged, the rows written, then removed) and its output passes
`dev/staging/ta_eval.py TA-09`; `ta_eval.py selftest` covers the four evaluators (TA-01, TA-02, TA-09, TA-10) with passing and failing inputs; `ta_eval.py TA-01` correctly FAILs the lab preflight output
(`evidence/final-v2/ta-lab/`). `staging_nas.rsc.tpl` is a printed template: it was never executed anywhere.

## 5. Secrets handling

Passwords and the RADIUS / NAS secrets are generated for the staging, stored in files mode 0600 (owner: the service user) or injected through the environment; they never appear on a
command line, in shell history, in tickets, logs or evidence (the evidence is redacted before it is stored; `scratchpad`-style sweeps compare the evidence with the secret values). The staging NAS
secret differs from every production NAS and is rotated or destroyed at teardown.

## 6. Stop conditions (any of them ends the session at once)

- any sign of customer traffic on the staging NAS, or a production identity, address or secret in a command or file;
- more than 5 % lost accounting requests in a load window, or any `No connections available` in the FreeRADIUS log;
- a database `Threads_connected` above 70 % of `max_connections`;
- a `FAIL` that makes the next test unsafe (for example the preflight): stop, record, do not continue.

## 7. Rollback

Application / FreeRADIUS: redeploy the previous artifact and `install/freeradius/` files (no schema change in V1). Database: restore the backup of S11 (`docs/BACKUP_RESTORE_RUNBOOK.md`).
NAS: the captured values of S9 section 0 (`dev/staging/staging_nas.rsc.tpl` section 9). Nothing in staging affects production.
