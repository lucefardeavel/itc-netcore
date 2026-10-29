# About this guide

This guide helps support staff and operators handle the most common incidents on an ITC NetCore V1.0 instance. Each incident follows the same structure:

**Symptom → Likely causes → Safe verification → Authorized resolution → Escalation**

"Safe verification" steps are read-only. "Authorized resolution" names who may act and through which supported path. Sources: `docs/OPERATOR_RECOVERY_RUNBOOK.md`, `install/freeradius/README.md`, `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md`, `docs/BACKUP_RESTORE_RUNBOOK.md`, `install/backup/README.md`, `install/fresh/README.md`, `system/reseller/health.php`, the FreeRADIUS site `install/freeradius/sites-available/phpnuxbill`, and the WP models.

> [!WARNING]
> Release Candidate, not yet production-accepted. Network behaviour was validated on RouterOS CHR 7.24.5 with FreeRADIUS 3.2.8 in an isolated lab only.

> [!FINANCE-WARNING]
> In every incident: never edit the ledger, wallets, reservations, activations, `radacct`, quota rows or audit tables by SQL; never release a held reservation or an ACTIVE admission claim to "unblock" a customer; never retry an UNKNOWN network outcome blindly.

**Roles used below:** *Support* (front-line staff), *Admin* (application Admin / SuperAdmin), *Operator* (shell access to the instance), *Network admin* (NAS and network), *DBA* (database host), *Financial operator* (authorized to decide on held funds). Escalation paths: `change_management_escalation`.

**Where to read RADIUS outcomes:** the subscriber receives a `Reply-Message`; the reason detail is in the FreeRADIUS log (`reject:` reasons) and in the staff UI Logs > Radius. Staff errors in the web UI show a reference id; the detail is in the server log (`[nuxr] ref=…`).

# 1. Incorrect login

**Symptom.** A staff user or a subscriber cannot log in to the web UI or the portal; or a subscriber's PPPoE / Hotspot login is rejected with a password error.

**Likely causes.**
- Wrong username or password (the most common cause).
- Staff account disabled (an inactive staff user cannot log in), or the reseller is frozen because an ancestor is disabled (login works, changes are refused).
- The installer's default `admin` password still in use: every route redirects to the password change.
- Subscriber portal: the account is **Banned** (shown only after the correct password is entered).
- Subscriber network login: credentials changed but the network account not re-provisioned (see §8).

**Safe verification.**
- Admin: Settings > Administrator Users — status of the staff account; Reseller Tree — status of its ancestors.
- Admin: customer view — status, username, PPPoE username.
- Logs > Radius for the subscriber's recent rejects.

**Authorized resolution.**
- Staff password reset: SuperAdmin / Admin for any lower account; an Agent for its strict descendants only.
- Subscriber password: Admin edits the customer (for a reseller-owned customer the change re-provisions the network account); the subscriber can also change it in the portal or use "forgot password".
- Re-enable a disabled account only if the disablement was not deliberate (an account disabled by an Admin cannot be re-enabled by an Agent).

**Escalation.** Repeated failures for many accounts may be a password-guessing attempt: the application has no built-in login throttling, so escalate to the network / security contact to apply rate limiting at the HTTPS front (the `install/fresh/` nginx example has a login-POST rate limit that answers `429`; TA-10 checks it on the target).

# 2. Insufficient wallet credit

**Symptom.** In the activation preview, Confirm stays disabled; a forced submission is refused; the reseller reports "not enough credit".

**Likely causes.**
- Available credit (`balance + debt limit − reserved`) is below the plan's wholesale price.
- Credit is blocked by **held reservations** of stuck activations.
- A reseller up the chain that earns a commission has no wallet (`COMMISSION_RECIPIENT_NO_WALLET`) or no headroom (`COMMISSION_RECIPIENT_CAPACITY`).
- The reseller or an ancestor is frozen (`WALLET_OWNER_FROZEN`).

**Safe verification.**
- Finance > Wallets > (wallet): balance, debt limit, reserved, available.
- Operations Center: stale or needs-attention activations of that reseller; health `reservations`.

**Authorized resolution.**
- Parent Agent: Finance > *Send to child reseller* (review → confirm).
- Admin: provider funding or a debt limit (review → confirm, reason required).
- Held reservations: resolve the holding activation per §4 (resume or cancel); never release by SQL.

**Escalation.** Financial operator for funding policy or a disputed balance; ITC if `finance-check` is not CONSISTENT.

# 3. Expired subscription

**Symptom.** The subscriber cannot connect after the end date; the portal shows the plan as expired; the reseller says "it renewed automatically before".

**Likely causes.**
- The billing period ended; FreeRADIUS rejects after the end date and the cron expires the service (or moves it to the plan's "expired plan" profile if one is configured).
- Reseller-owned subscribers have **no auto-renew in V1**.
- The cron skipped the customer because a renewal was in flight (`SKIPPED (service busy…)`) — normal unless repeated.

**Safe verification.**
- Customer view: plan, expiry date, last activation.
- `php system/reseller/reconcile.php expired-autorenew` (reseller-owned customers that expired without renewal).
- Cron output for the customer.
- If **no** customer expires or renews any more: check that the scheduled job runs `cd <app-root>/system && php cron.php`. Started from the application root, `cron.php` cannot find `../init.php`, does nothing and still exits 0 (only a PHP warning in the cron log).

**Authorized resolution.**
- Reseller-owned: the owner reseller (or its Sales user, or an Admin) renews through Activate / Renew (REACTIVATE or RENEW); the owner's wallet is debited.
- Direct subscriber: portal recharge or voucher, or Admin recharge / refill / extend.

**Escalation.** If the same customer is skipped on many consecutive cron runs, operator runs `reconcile.php stale` (§4).

<!-- pagebreak -->

# 4. Activation pending or RECONCILIATION_REQUIRED

**Symptom.** An activation stays "in progress"; the customer has no service or the old one; a new attempt is refused with `ACTIVATION_IN_PROGRESS`; the reseller saw `CONCURRENCY_RETRY` ("please try again"); health `activations_stale`, `reconciliation` or `reservations` alerts.

**Likely causes.**
- The process stopped before or between RADIUS attempts (`FUNDS_RESERVED`, `RETRY_PENDING`).
- A deadlock refusal under heavy simultaneous activity on one wallet (MariaDB): `FUNDS_RESERVED` with an ACTIVE reservation, nothing charged or granted.
- The RADIUS outcome is unknown or the read-back mismatched, or the settlement failed after delivery (`RECONCILIATION_REQUIRED`).
- Delivered but not yet settled (`VERIFIED`, stale).

**Safe verification.**
- Operations Center > Needs attention / stale; open the activation page (timeline and allowed actions).
- `php system/reseller/reconcile.php show <ACT-...>` and `reconcile.php stale --seconds=300`.
- For `RECONCILIATION_REQUIRED`: compare the customer's RADIUS rows (customer view) with the activation target.

**Authorized resolution** (Admin or Operator; Agents and Sales cannot):

| State | Action |
|---|---|
| `FUNDS_RESERVED` / `RETRY_PENDING`, stale ≥ 5 min | Resume (continue) or Cancel (release, CANCELLED) |
| `VERIFIED` (stale) | Resume (settles once) |
| `RECONCILIATION_REQUIRED` | Reconcile; on `EXTERNAL_STATE_AMBIGUOUS` (exit 4) inspect RADIUS, then Rollback or leave held |
| `ROLLBACK_PENDING` | Rollback again; funds stay held until the restore is verified |
| `REVERSAL_PENDING` | Reverse again (idempotent) |

> [!FINANCE-WARNING]
> Funds are released only by the operator's audited decision. A fresh (non-stale) activation is refused by the tools; wait for the 300 s stale floor.

**Escalation.** Financial operator for any ambiguous outcome; ITC if the same activation stays ambiguous after RADIUS inspection.

# 5. NAS unreachable

**Symptom.** New logins on one NAS time out; health `accounting` WARNING; `coa_failures`; activations annotated `COA_NO_RESPONSE`; `nuxr_guard_selfcheck` reports `health` for that NAS.

**Likely causes.**
- NAS down, rebooting or isolated; routing or firewall change between NAS, FreeRADIUS and the application.
- CoA port mismatch: RouterOS documents 1700 as its factory default while the application sends to 3799 (or `nas.ports`).
- `/radius incoming accept=no`, or CoA sent from an address that is not a `/radius` entry on the NAS.
- SNMP (UDP 161) blocked or the community changed.

**Safe verification.**
- On the NAS (network admin): `/radius print detail`, `/radius incoming print`, the UDP 3799 and UDP 161 firewall rules, `/system resource print` (uptime).
- On the FreeRADIUS host: `sudo -u freerad ${confdir}/nuxr/nuxr_guard_selfcheck <NAS_IP>`.
- `php system/reseller/preflight.php` (`nas.verification`, `time.nas_clock`).

**Authorized resolution.** The network admin restores reachability and the NAS contract (`docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2). After recovery, re-send pending session ends with `php system/reseller/reconcile.php session-end <ACT>` where drift `SESSION_POLICY_STALE` lists them. A `COA_NO_RESPONSE` is never retried automatically and never rolls back provisioning.

**Escalation.** Network admin first; ITC if the NAS contract is correct and CoA still fails.

# 6. RADIUS reject

**Symptom.** The subscriber's login is rejected; the NAS log shows Access-Reject.

| Reply-Message / log reason | Likely cause | Safe verification | Authorized resolution |
|---|---|---|---|
| (password error, no specific message) | Wrong password; unsupported method (only PAP and CHAP are supported; MS-CHAPv2 is refused) | Customer view; NAS PPP profile authentication methods | Correct the credentials or the NAS authentication method |
| Rejected after the end date | Expired period (§3) | Customer view expiry | Renew |
| "Data quota exhausted or unavailable" | Period quota used, or the RADIUS database query failed (fail closed) | `quota.php usage <USERNAME>`; health `db` | Renew / change plan; if the database failed, fix the database |
| "Quota unavailable" | A `Max-Data` / `Max-All-Session` row in `radcheck` is malformed or duplicated (written outside the application) | Customer view RADIUS rows | **Re-save the customer's plan through the application**; never by hand |
| "Session verification failed" | PPPoE session guard could not prove the old session gone (§7), a PENDING admission claim (§8), the 10 s fresh-session hold (`reject:active-fresh`), a claim that arrived too late, or a SQL failure on the admission path | FreeRADIUS log `reject:` reason; `reconcile.php admission-pending`; `nuxr_guard_selfcheck` | Per §7 / §8 |
| MAC-auth refused | The RADIUS username is stored as a lower-case MAC address; MAC authentication is refused for such names by design (MAC-H1A) | preflight `mac` lists the count | Review the listed identities with the Admin (checklist E5); any change is made through the application |
| Rejected after a dictionary / site change | An unknown attribute in `radcheck` (dictionary, counters and site not installed together) | FreeRADIUS start log | Install `dictionary.phpnuxbill`, `nuxr_counters` and the site together; restart |

**Escalation.** Operator → ITC with the FreeRADIUS log excerpt (never the secrets).

# 7. SNMP verification failed

**Symptom.** PPPoE subscribers on one NAS are rejected with "Session verification failed" when they reconnect after a lost Accounting-Stop; `nuxr_guard_selfcheck` prints `guard_selfcheck=ERROR`.

**Likely causes** (self-check reasons): `no-credential-file`, `credential-file-mode` (must be 0600 or 0400, owner `freerad`), `credentials` (no entry for that NAS or invalid characters), `snmpget-missing` / `snmpbulkwalk-missing` (net-snmp tools not installed), `health` (wrong community **or** SNMP disabled, filtered or unreachable — they look the same), `walk*` (unexpected answer). Also: NAS type not `mikrotik_snmp` (the NAS is then never verified).

**Safe verification.**
- `sudo -u freerad ${confdir}/nuxr/nuxr_guard_selfcheck <NAS_IP>` (read-only; never prints a community).
- RouterOS: `/snmp print`, `/snmp community print` (read-only community restricted to the FreeRADIUS address), the UDP 161 rule.
- `preflight.php` `nas.verification`.

**Authorized resolution.** Fix the credential file (`${confdir}/nuxr/nas_snmp`, one `NAS_IP COMMUNITY` line per NAS) or the NAS SNMP configuration; repeat until `guard_selfcheck=OK`. No FreeRADIUS restart is needed for the credential file. The next login of an affected subscriber is then admitted (after the 10 s fresh-session hold). **No database change is needed or allowed.**

**Escalation.** Network admin (NAS SNMP) and Operator (FreeRADIUS host).

<!-- pagebreak -->

# 8. Customer cannot reconnect

**Symptom.** A subscriber who was online cannot log in again (often after a power cut, a NAS reboot, a plan change or a password change).

**Likely causes and checks.**

| Cause | Safe verification | Authorized resolution |
|---|---|---|
| Lost Accounting-Stop: an open `radacct` row; the guard must verify the NAS | `nuxr_guard_selfcheck`; customer sessions view | If the guard is OK, ask the subscriber to reconnect after a few seconds; otherwise §7 |
| Fresh-session hold: within 10 s of a session Start every take-over is refused | FreeRADIUS log `reject:active-fresh` | Ends by itself; reconnect after a few seconds |
| PENDING admission claim (a FreeRADIUS stall or an Accept the NAS never used) | `php system/reseller/reconcile.php admission-pending` (key, token, NAS, age, `releasable`) | Operator collects **NAS-side proof** that the attempt cannot become a session (NAS rebooted after `created_at`, or the NAS log shows the attempt failed) and runs `reconcile.php admission-release <key_hex> <token> --operator=<name> --reason="…" --evidence="…"`. An empty active list alone is not proof. ACTIVE claims are never released by hand |
| ACTIVE reservation after a lost Stop or NAS restart | Status query in the FreeRADIUS README; guard self-check | Not an operator release case: the guard takes it over on the next verified login. Fix the guard if needed (§7) |
| Password or identity changed, network not confirmed (`REPROVISION_UNCONFIRMED`) | `reconcile.php drift` | Admin runs **Sync** once from the customer view |
| Old session still running with the old plan (`SESSION_POLICY_STALE`) | `reconcile.php drift` | `reconcile.php session-end <ACT>` or disconnect on the NAS |
| Plan's IP pool missing on the NAS (Accept without IPv4) | Plan settings vs `/ip pool print` | Network admin creates the pool |
| Hotspot slot PENDING | `nuxr_hs_admission_report.sql`; health `admission_pending` | FreeRADIUS README "Hotspot admission slots" |

**Escalation.** Operator → ITC with the activation reference or admission key (never the token in a public channel).

# 9. Quota or usage disagreement

**Symptom.** The subscriber or reseller says the used data / time does not match the router, a speed test or expectations; a session continued slightly past the quota; usage "reset" or "did not reset".

**Likely causes.**
- The quota is **bounded, not byte-exact**: the router cuts on its own timer (Hotspot measured 0.3–4.4 % past the session share in the lab); PPPoE upload + download together are closed by the enforcer within its pass (interim default 30 s, enforcer pass every 5 s).
- Overshoot is **not charged and not carried over**; actual and billable usage are separate numbers.
- A renewal, plan change or reactivation starts a new period from zero; a password change, rename or re-sync keeps the period.
- Period ownership is by **arrival order** of accounting rows, not by the NAS clock.
- Units: data quotas are decimal (1 GB = 10^9 bytes, 1 MB = 10^6 bytes), download + upload combined.
- During an enforcer, FreeRADIUS, database or NAS-link outage only the router's limits apply (PPPoE worst case: twice the remaining quota).

**Safe verification.**
- `php system/reseller/quota.php usage <USERNAME>` — actual, billable and overshoot for the current period.
- `php system/reseller/quota.php report` — over-quota open sessions.
- health `quota_enforcer`; customer view (period start, plan).

**Authorized resolution.** Explain the quota contract (bounded, not byte-exact; overshoot free of charge). If the enforcer was down, restart it (`quota.php enforce --loop=55 --every=5` every minute). A goodwill credit is an Admin / financial-operator decision through an audited adjustment. **Never edit `radacct` or quota rows.**

**Escalation.** ITC with the `quota.php usage` output if the numbers contradict the contract.

# 10. Backup or restore failure

**Symptom.** The nightly backup is missing or fails; a backup tool exits non-zero; the archived binlogs are getting old; a restore fails or the restored copy does not verify.

**Likely causes** (exit codes of the `install/backup/` tools).

| Exit | Meaning | Typical cause |
|---|---|---|
| 3 / 8 | Dump or load failed | The backup account lacks a privilege (`backup.my.cnf.example` lists the grants per engine); DDL (a migration) ran during the dump; disk full |
| 5 | Set complete locally, off-host copy failed | Off-host target or transport down. The set is kept locally; the RPO is at risk until the copy succeeds |
| 6 | Refused by a safety rule | Restore target not empty; a set from the other engine; wrong server; the replay range contains `CREATE` / `DROP` / `ALTER DATABASE` or a statement naming the source database |
| 7 | Checksum or manifest mismatch | The set was altered or corrupted after it was written. Treat as a possible security incident |
| 9 / 11 | Binlog chain incomplete, archive failed or chain gap | A binlog expired before it was archived, or the archive job was down longer than the binlog expiry |
| 10 | Verification of the restored copy failed | `migrate.php verify` drift (the copy does not match the release's migration contract), or `health.php --finance` not OK on the copy |
| 75 | Another run holds the lock | Two runs overlapped; retry later |
| 78 | Configuration or prerequisite error | Configuration or option file not mode 0600; wrong `server_id`; the engine's own client tools missing (for example no `mysqlbinlog` on a MySQL host) |

Also: the decryption key is not available on the restore host; wrong character set (`utf8mb4` required).

**Safe verification.**
- The tool's `key=value` result lines and its log (`LOG_FILE`); `backup.info` and `MANIFEST.sha256` of the set; the age of the newest set and of the newest archived binlog.
- `nuxr-binlog-archive.sh --check-only` (server settings report, read-only).
- `nuxr-restore.sh ... --decode-only <file>` to review what a replay would apply, without applying it.
- On a restored copy the restore tool itself runs the fingerprint, the finance row counts, `migrate.php verify` and `health.php --finance`. `preflight.php` is not run on a copy (it checks the live NAS / FreeRADIUS environment).

**Authorized resolution.** DBA fixes privileges, schedule, storage or the off-host transport and takes a new full backup immediately (after a chain gap a new full backup is required). A restore is always repeated into a **new, empty** database; the live database is never overwritten (`--i-understand-overwrite` only on an owner-approved decision). Never replay binlogs by hand and never override an unsafe-statement refusal without reviewing the decoded SQL. Never "fix" a copy's numbers by SQL.

**Escalation.** DBA, then ITC if `verify` or `finance` fails on a correctly restored copy, or on any exit 7; owner and ISP management if no valid backup or off-host copy exists (RPO at risk).

# 11. Customer creation or edit refused (identity or static IP)

**Symptom.** Adding a customer is refused. An Agent or Sales user sees **one generic refusal** that some identity is already taken. An Admin sees a specific reason, for example "This static IP address is already assigned to another customer." or "The static IP must be a valid IPv4 address (leave it empty for none)."

**Likely causes.**
- The username or the PPPoE username already belongs to another customer **anywhere in the instance**: the RADIUS identity namespace is global, including other resellers' trees.
- The static IP is already assigned to another customer (it must be unique across all customers, for every role).
- The static IP is not a valid IPv4 address, or is a reserved / special address (the rejection of reserved ranges is part of the third R-HF-CARRY-01 round, in progress on the final-prep branch).
- The identity check could not run (for example the RADIUS database is unreachable): the refusal says to try again; nothing was changed.

**Safe verification.**
- Admin: search the customers for the username, PPPoE username or IP (Admins get named answers).
- Agents and Sales are deliberately **not** told which identity collides or who holds it.

**Authorized resolution.** Choose another username, PPPoE username or static IP and save again. A conflict with an existing customer is resolved by an Admin. Do not ask an Admin to reveal another reseller's customer data to an Agent. An existing (legacy) value that is not changed is not re-checked on edit.

**Escalation.** Many refused creates by one Agent or Sales user, or through one API token, may be probing: the refusals are logged with the actor id in the PHP error log (`customer.create_identity_refused`, `identity.static_ip_taken`). Escalate to the security contact. R-HF-CARRY-01 remains **OPEN** for the owner (`known_limitations` §5.4).

# 12. Fresh installation refused or failed

**Symptom.** `nuxr-fresh-install.sh` or `nuxr-post-install-verify.sh` exits non-zero.

| Exit | Meaning | Authorized resolution |
|---|---|---|
| 2 | Prerequisites missing (nothing changed) | Install what the message names (PHP 8.2 and extensions, PHP-FPM, database client, engine settings A3–A6, administrator privileges) |
| 3 | Refused (nothing changed): database not empty or unknown, wrong collation, an account already exists, env file not 0600, a `config.php` already present, or an interrupted base-schema load | **Never** clean, drop or reuse the database to get past the refusal. Choose a new database name (or fix the env file) and run again. After an interrupted base-schema load, start again on a new, empty database |
| 4 | A step failed (schema, migration, grants, preflight, health) | Fix the cause and re-run: the installer resumes after the base schema. If the runtime account cannot connect, `preflight.php` currently dies with a PHP fatal error (exit 255) instead of a FAIL line (fix in progress); check the account, its host part and its password |
| 5 | Usage | Correct the command |

The post-install verify exits 1 when a check fails: read its FAIL lines (web boundary, files, tools or cron). **Escalation:** ITC with the installer log from the state directory (`/var/lib/itc-netcore/fresh-install/`), never the env file.
