# FreeRADIUS configuration for PHPNuxBill (WP4)

Stock FreeRADIUS cannot serve PHPNuxBill subscribers. PHPNuxBill writes the check items `Max-All-Session`
(time quota) and `Max-Data` (data quota), which are unknown to a stock server, and FreeRADIUS then rejects
every subscriber whose `radcheck` rows contain them. This directory holds the project-owned configuration that
PHPNuxBill requires and tests (owner decision C16, option a).

It is tested with FreeRADIUS **3.2.8** (`freeradius/freeradius-server:3.2.8`) on MariaDB 10.11 and MySQL 8.0. See
`tests/Integration/Wp4/FreeRadiusContractTest.php` and the test setup in `dev/test/freeradius/`.

| File | Install as | Purpose |
|---|---|---|
| `dictionary.phpnuxbill` | `/etc/freeradius/dictionary.phpnuxbill`, plus the line `$INCLUDE dictionary.phpnuxbill` in `/etc/freeradius/dictionary` | defines the internal attributes `Nuxr-Period-Start` and `Nuxr-Quota-Share` (V1 data quota; never sent to a NAS) |
| `mods-available/nuxr_counters` | `mods-enabled/nuxr_counters` | `Max-All-Session` / `Max-Data` counters for the **current billing period** |
| `mods-available/sql.example` | adapt into `mods-available/sql`, then link it in `mods-enabled/` | SQL module on the PHPNuxBill RADIUS database (`read_groups = yes`) |
| `sites-available/phpnuxbill` | `sites-enabled/phpnuxbill`, replacing `default` | authorize / authenticate (PAP, CHAP) / accounting / session / post-auth (PPPoE session guard) |
| (stock) `mods-available/date` | keep `mods-enabled/date` (FreeRADIUS default) | provides `%{time_since:}`, the clock of the PPPoE late-claim guard |
| `bin/nuxr_pppoe_session_guard` | `${confdir}/nuxr/nuxr_pppoe_session_guard`, mode 0755, owner root | fail-closed PPPoE live-session verifier (see "PPPoE session guard") |
| `mods-available/nuxr_pppoe_session_guard` | `mods-enabled/nuxr_pppoe_session_guard` | the named exec module that runs the verifier from post-auth |
| `nuxr_admission.sql` | run once in the RADIUS database (additive, idempotent) **before** enabling the site | PPPoE admission reservation tables (see "PPPoE admission reservation") |
| `nuxr_admission_check.sql` | run in the RADIUS database | read-only readiness check: prints `nuxr_admission=READY` |
| `nuxr_admission_release.sql` | run by the operator only, per the recovery procedure | releases ONE unresolved PENDING claim, with an audit record |
| `nuxr_hs_admission.sql` | run once in the RADIUS database (additive, idempotent) **before** enabling the site | Hotspot admission slot tables (see "Hotspot admission slots") |
| `nuxr_hs_admission_check.sql` | run in the RADIUS database | read-only readiness check: prints `nuxr_hs_admission=READY` |
| `nuxr_hs_admission_report.sql` | run by monitoring (every minute) | read-only report: `ALERT` lines for stuck PENDING slots (> 1 minute) and orphan accounting |
| `bin/nuxr_hotspot_slot_verifier` | `${confdir}/nuxr/nuxr_hotspot_slot_verifier`, mode 0755, owner root | fail-closed Hotspot session verifier (read-only SNMP), ACTIVE slots only |
| `mods-available/nuxr_hotspot_slot_verifier` | `mods-enabled/nuxr_hotspot_slot_verifier` | the named exec module that runs the verifier from the Hotspot post-auth |

## Rules

**Accounting history is never deleted.**
- FreeRADIUS writes `radacct`; PHPNuxBill only reads it.
- Before WP4, PHPNuxBill deleted the subscriber's `radacct` rows on every activation, renewal, sync, password or username change, and expiry. That deletion was what reset the quotas.
- Since WP4, quotas reset by period instead: PHPNuxBill writes `Nuxr-Period-Start` (the start of the billing period, a UNIX timestamp), and the `nuxr_*` counters sum only the sessions started since then.

**Stale sessions are never zapped.** `sql` must keep `delete_stale_sessions = no`. With `yes`, a checkrad "not logged in"
answer makes FreeRADIUS write a fabricated Accounting-Stop into `radacct`. PPPoE admission against a stale row is decided
by the PPPoE session guard instead (it asks the NAS; the row is left exactly as it is).

**Counters.** Do not enable the stock `noresetcounter` together with `nuxr_timecounter`, because both define `Max-All-Session`.

**EAP.** The PHPNuxBill server does not define an EAP section. Disable `mods-enabled/eap`, or keep EAP in your own virtual server.

**Time zone.**
- `Expiration` is written in the application time zone (`Settings > Time zone`), in the format `d M Y H:i:s`, and FreeRADIUS interprets it in its own local time.
- Run FreeRADIUS with the same time zone as the application, for example `TZ=Asia/Baghdad`.
- `WISPr-Session-Terminate-Time` carries an explicit offset.

**Post-auth logging.** `radpostauth` is not enabled here, because the stock query stores the cleartext password.

**NAS clients.**
- `read_clients = yes` (with `client_table = "nas"`) loads the NAS list that PHPNuxBill manages (RADIUS > NAS). This is the
  production arrangement validated in WP8: the `nas` table is the single NAS source for FreeRADIUS (authentication and
  accounting clients) and for PHPNuxBill (CoA / Disconnect target). Restart or reload FreeRADIUS after changing a NAS.
- The NAS secret is stored in the `nas` table in plaintext, because FreeRADIUS needs to read it.
- PHPNuxBill never shows the stored secret again after it is saved.
- `nas.nasname` must be the IP address that the NAS sends as `NAS-IP-Address` and uses as the source of its RADIUS
  packets (RouterOS: `/radius src-address`). PHPNuxBill finds the NAS of a session by `radacct.nasipaddress == nas.nasname`.

## PPPoE session guard (WP8 OD-W8-16 Option G)

**Problem.** RouterOS does not resend a lost PPPoE Accounting-Stop. The `radacct` row then stays open although the session
is gone, and `Simultaneous-Use` refuses every new login of that subscriber. Row age is not proof that a session ended, and
the stock checkrad turns SNMP failures into "not logged in" (it fails open), so neither can decide.

**How it works.** The stock `Simultaneous-Use` check (`session { sql }`) is unchanged and runs first. Then `post-auth` runs
the guard for a **PPPoE** Access-Request only, recognised as `Service-Type = Framed-User` AND `Framed-Protocol = PPP`
AND `NAS-Port-Type = Ethernet` (RouterOS 7.24.5; Hotspot sends `Login-User` and never matches):
1. It reads the open `radacct` rows of the subscriber, using the same SQL key as FreeRADIUS itself (`SQL-User-Name`).
2. No open row: the login proceeds and **no NAS is asked**.
3. Open rows: it asks every NAS named by those rows, over read-only SNMP (health probe `sysUpTime.0` first, then the
   MIKROTIK-MIB interface-name table), whether `<pppoe-USERNAME>` (or `<pppoe-USERNAME-N>`) is live right now.
4. Result `SAFE` (every NAS answered, below the limit): Access-Accept. `LIVE` (still connected): Access-Reject.
   `ERROR` (anything unproven: missing credentials, SNMP unreachable or wrong community, timeout, missing tool, malformed
   answer, unknown or malformed NAS address): **Access-Reject**. With several NAS, any LIVE or any ERROR rejects.
5. Every guard reject carries only `Reply-Message = "Session verification failed"` (no policy attributes).

The guard never deletes or updates `radacct`, never fabricates a Stop and never reboots or asks the NAS to do anything.
The stale row stays as it is (operators see it as "stale" until the NAS sends something for it).

**Install** (`${confdir}` is `/etc/freeradius/3.0` on Ubuntu packages, `/etc/freeradius` in the official image):
- `bin/nuxr_pppoe_session_guard` as `${confdir}/nuxr/nuxr_pppoe_session_guard` (0755, root); the directory 0755.
- `mods-available/nuxr_pppoe_session_guard` into `mods-enabled/`. The site file already calls it from `post-auth`.
- The net-snmp command-line tools (`snmpget`, `snmpbulkwalk`): Ubuntu/Debian package `snmp`. The project test and lab
  image pins `snmp 5.9.1+dfsg-1ubuntu2.9` on Ubuntu 22.04 (`dev/test/freeradius/Dockerfile`). Without the tools every
  PPPoE login that meets an open row is rejected.
- Validated with FreeRADIUS **3.2.8** only. Another FreeRADIUS version must pass the WP8 Option G gate again
  (`tests/Integration/Wp8/OptionGGateTest.php`) before it is used.

**Credential file** `${confdir}/nuxr/nas_snmp` - the only place the guard reads SNMP communities from:
- One line per NAS: `NAS_IP COMMUNITY` (the NAS address as in `nas.nasname`, dotted IPv4). Community characters:
  `A-Z a-z 0-9 . _ -` only (anything else is ERROR).
- Mode **0600**, owned by the FreeRADIUS user (`freerad`). Never world-readable, never in Git, never on a command line
  (the guard writes it into a private per-run net-snmp configuration that is deleted when it exits).
- It is part of that instance's FreeRADIUS configuration and its backup (one isolated instance per ISP: every ISP has its
  own file; never copy it between instances). Keep it on persistent storage - a container must mount it, not create it.
- It is maintained by the operator. The `nas.community` column (RADIUS > NAS > Community in the admin UI) is **not**
  read by the guard or by FreeRADIUS; the admin UI shows that field in the NAS list, so do not store the guard community
  there.
- A NAS without an entry cannot be verified: a PPPoE login that meets an open row on it is rejected.

**Rotation** (no FreeRADIUS restart; the guard reads the file on every run):
1. RouterOS: add the new community (read-only, restricted to the FreeRADIUS address), keep the old one.
2. Write the new file next to the old one (`umask 077`, same directory, owner `freerad`), then `mv` it over `nas_snmp`.
3. Check: `sudo -u freerad ${confdir}/nuxr/nuxr_pppoe_session_guard 70726f6265 1 NAS_IP` must print
   `Tmp-String-7 = "SAFE"` (`70726f6265` is the hex of a username that is not online). `ERROR` means stop and fix.
4. RouterOS: remove the old community.
A mistake during rotation produces ERROR, i.e. rejected PPPoE logins for subscribers with an open row - never an Accept.

**NAS contract** (MikroTik RouterOS; checked by the WP8 lab):
- PHPNuxBill NAS type (RADIUS > NAS > Type) **`mikrotik_snmp`**. With another type (default `other`) the stock checkrad
  trusts every open row as live: after a lost Stop the subscriber stays locked out (fail-safe, but not resolved).
- SNMP read-only on the private management path: `/snmp set enabled=yes`;
  `/snmp community set [find name=public] disabled=yes`;
  `/snmp community add name=COMMUNITY addresses=FREERADIUS_IP/32 read-access=yes write-access=no`;
  firewall: accept UDP 161 only from the FreeRADIUS address. The community must differ from the RADIUS secret.
- **`/radius set [find] timeout=3s`** (or more). The RouterOS default is 1100 ms, shorter than the guard path. Measured on
  RouterOS 7.24.5 with this configuration (WP8 A1): guard median 188 ms, max 1084 ms (NAS SNMP unreachable); Access-Accept
  after a verification 235 ms; Access-Reject 1.0-1.2 s, which includes the stock `reject_delay` of 1 s. The guard's hard
  deadline (1.8 s) bounds the worst case at about 2 s. A too short timeout fails closed (the login is refused), it never
  lets a duplicate in.
- The stock `checkrad` runs before the guard (`session { sql }`). It was validated **as shipped in the official 3.2.8 image**,
  where it cannot run SNMP and answers at once. A checkrad build that does run SNMP (for example a distribution package
  with net-snmp program paths) waits up to 10 s for a NAS that does not answer - longer than the RouterOS timeout, so such
  logins are refused (fail closed, availability only). Re-run the WP8 Option G gate before using such a build.

**Hotspot.** The guard never runs for Hotspot. PHPNuxBill writes `Port-Limit` = `Simultaneous-Use` (`max(1, shared_users)`)
as a **reply** attribute for Hotspot plans, and RouterOS enforces it at the NAS, also when an accounting Stop was lost.
PPPoE gets no `Port-Limit` (RouterOS ignores it for PPPoE). RouterOS counts `Port-Limit` per **spelling** it received,
so the Hotspot canonical-spelling check below admits only the exact stored spelling, and per **NAS**, so the global
limit across NAS is enforced by the Hotspot admission slots (below); `Port-Limit` stays as an additional local limit.

## Hotspot canonical spelling (WP8 H-1, H1-A)

**Problem (H-1).** `radcheck.username` is `utf8mb4_general_ci`, so a Hotspot login authenticates under every case or
accent spelling of the stored name (`ALI`, `Ali`, `àli` for `ali`). RouterOS keys the Hotspot session, its accounting
and its `Port-Limit` by the spelling it received, and it ignores a `User-Name` returned in the Access-Accept (WP8 H1-B
gate, RouterOS 7.24.5), so each equivalent spelling got its own `Port-Limit`: two live sessions under `shared_users = 1`.

**How it works.** At the end of `post-auth`, only for Hotspot requests (`Service-Type = Login-User` and
`NAS-Port-Type = Wireless-802.11`; RouterOS sends these for http-chap, http-pap, cookie and MAC login), after a
successful authentication: the bytes of the request `User-Name` must be identical to the bytes of the one stored
`radcheck.username` the lookup matched. Otherwise - another spelling, two byte-distinct stored spellings of one
subscriber (ambiguous identity), any SQL error or unexpected result - Access-Reject, exactly like a wrong password (no
Reply-Message, `reject_delay`; the Hotspot page shows its normal "invalid username or password" text). Nothing is
written, the request and the password check are not changed, PPPoE / VPN requests never enter it.

**Compatibility.** The stored spelling is the only one that works on the Hotspot:
- users must type the username in its stored case (vouchers: the code exactly as issued - `rand` codes are mixed case);
- a saved browser password or a Hotspot cookie under another spelling stops working until the user logs in once with
  the stored spelling;
- MAC login: RouterOS sends the MAC in UPPER case with colons (`AA:BB:CC:00:11:22`); store MAC usernames in that form.
  **A Hotspot that uses `mac-auth-mode=mac-as-username` with a MAC stored in lower case worked before this rule and
  STOPS WORKING with it** (WP8 H1-A, measured on RouterOS 7.24.5: the same MAC, accepted before, refused after). With
  `mac-as-username-and-password` a lower-case MAC was already refused before (the CHAP password is the upper-case MAC).
  There is no MAC exception, and nothing changes stored usernames: find such subscribers with the read-only impact
  queries and correct their stored MAC (or the NAS mode) before enabling the rule;
- PPPoE is unchanged (equivalent spellings share one admission reservation, see below).
Measure the impact before enabling it (read-only queries in the WP8 H-1 report).

**Rollback:** reinstall the previous site file and restart FreeRADIUS (H-1 returns).

## PPPoE admission reservation (WP8 A2-R)

**Problem.** The guard above decides from open `radacct` rows, and a session only gets a row when its Accounting-Start
arrives (25-160 ms after the Access-Accept on RouterOS 7.24.5, or never when the Start is lost - RouterOS does not
resend it). Two PPPoE logins of the same subscriber arriving in that window were both accepted and both stayed up
(WP8 A2, `RACE_PROVEN`). Different spellings of one username (case, accents) that `radcheck` (`utf8mb4_general_ci`)
treats as the same subscriber could also hold two sessions, because the guard matches the spelling it is given (S-1).

**How it works** (PPPoE only; Hotspot is unchanged). After authentication, `Simultaneous-Use` and the guard, the last
step before the Access-Accept is an atomic **claim** in the table `nuxr_admission`, one row per **canonical subscriber**
(the exact `radcheck.username` the lookup matched, so every equivalent spelling maps to the same row):
1. No row, or an `ENDED` row: exactly one request can create it (PRIMARY KEY, plain `INSERT`) or take it over (`UPDATE`
   conditioned on the state and the previous token). It becomes `PENDING` with a new random 128-bit **token**, and the
   Access-Accept carries `Class = "nuxr1" + token`. Every other request is refused.
2. A `PENDING` row of **another** attempt: the login is refused - also when the old attempt is old, and also when SNMP
   shows no session (it may still complete). Nothing releases it automatically (Strict Mode): see "Operator recovery".
3. The **same** attempt (an exact retransmission of the claiming Access-Request: same NAS-IP-Address, Acct-Session-Id,
   Calling-Station-Id, NAS-Port-Id, spelling and Request Authenticator - for example after a FreeRADIUS restart) re-uses
   the claim and its token.
4. An `ACTIVE` row (its session was proven by accounting): taken over only after the guard verified its NAS over SNMP and
   found the session gone (the guard's lost-Stop rule). Under another spelling it cannot be verified: refused.
5. Accounting with the token from the admitting NAS (token, NAS-IP-Address, Acct-Session-Id and Calling-Station-Id must
   all match): Start / Interim-Update -> `ACTIVE`, Stop -> `ENDED`. A lost Start is confirmed by the first Interim-Update.
   Old, duplicated or foreign packets change nothing; `ENDED` is never revived; Accounting-On changes no reservation.
6. **Late-claim guard:** a request that has been in FreeRADIUS longer than **5000 ms** gets no claim and no Accept; a claim
   that completed late is released by its creator (unless a retransmission of the same attempt already re-used it).
   RouterOS 7.24.5 with `/radius timeout=3s` sends an Access-Request 3 times (0 / ~3 / ~6 s, identical id and
   authenticator) and gives up after about 9.3 s; an Accept for the original request that arrives before that is
   used. 5000 ms (owner decision D-AV4, 2026-10-10; was 2000 ms) covers the A1 verification path - stock
   `Simultaneous-Use` check plus the guard up to its 3 s module timeout; with 2000 ms a legitimate reconnect after a
   lost Stop was refused when SNMP was slow - and leaves about 4 s to the NAS deadline. Measured on the WP8 lab NAS
   (claim delayed inside FreeRADIUS): 3.5 s and 4.5 s -> Accept used by the NAS, session up; 5.5 s and 6.5 s ->
   refused, claim released. The guard does not cover delays FreeRADIUS cannot see (a packet waiting in the kernel
   before FreeRADIUS reads it - see "Known limitation" - or an Accept lost on the network): those leave a `PENDING`
   claim (refused logins, never a second session). Keep `/radius timeout=3s`: a shorter NAS timeout (RouterOS default
   1100 ms gives up after about 3.3 s) is NOT covered by this budget.
   The age is measured on ONE clock: the request receive time (`%l` / `%M`) and the current time
   `%{time_since:us}` both come from `gettimeofday()`. `%{time_since:}` is provided by the stock `date` module
   (`mods-enabled/date`, enabled by default): keep it enabled - without it FreeRADIUS refuses to start ("Unknown
   module"). Before WP8 AV-5 (2026-10-10) the current time mixed `%C` (`gettimeofday()` microseconds) with `%c`
   (`time()`, a coarse clock that can still show the previous second for a few milliseconds after a second boundary):
   such a request got an age about 1000 ms too small, negative when the request was younger than one second (refused
   as malformed).
7. Fail closed: a missing table, an SQL error, an unexpected SQL result, a missing or malformed attempt identity
   (Acct-Session-Id, Calling-Station-Id, NAS-Port-Id), a guard failure: Access-Reject with only
   `Reply-Message = "Session verification failed"`. `radacct` is never written by this policy; nothing is deleted.

**Authentication methods.** PAP and CHAP (the methods of this virtual server) were tested. Any other method (for example
MS-CHAPv2) is refused by the virtual server. A `Class` attribute from `radreply` / `radgroupreply` is replaced on PPPoE
Access-Accepts.

**Not covered here.** Hotspot is not part of this PPPoE reservation: it has its own admission slots ("Hotspot admission
slots" below; WP8 H-2B). The VPN behaviour is open (WP8 V-1, unverified).

**Known limitation - FreeRADIUS stall (WP8 AV-1; accepted by the owner on 2026-10-10 as a documented Strict Mode
availability limitation for development; an OPEN PRODUCTION AVAILABILITY RISK).** FreeRADIUS 3.2.8 timestamps a
request when it reads it from the socket. If the whole process stalls (paused container or VM, SIGSTOP, debugger, host
swap or CPU starvation, `-X` / `-s` single-thread mode under load) for longer than the NAS retry window (about 9 s),
the NAS gives up while its packets wait in the kernel queue; after the stall FreeRADIUS reads them with an age of a few
milliseconds, claims and sends an Accept the NAS ignores. The claim stays `PENDING` and the subscriber is refused until
the operator recovery below. Never a second session; no time-based expiry and no SNMP-based unlock of `PENDING`.
Operations: run FreeRADIUS threaded under the service manager (never `-X` in production), monitor host / VM stalls and
FreeRADIUS liveness, alert on the orphan-candidate query (below), release only with NAS-side proof.

**Install / upgrade** (per ISP instance, in this order):
1. Back up the RADIUS database.
2. Apply `nuxr_admission.sql` to the RADIUS database (the same database as `radcheck` / `radacct`), with the account that
   owns the schema. It only creates `nuxr_admission` and `nuxr_admission_audit` (`CREATE TABLE IF NOT EXISTS`); it does
   not touch `install/radius.sql` tables. The FreeRADIUS SQL account needs `SELECT, INSERT, UPDATE` on `nuxr_admission`
   (no `DELETE`); the audit table is written only by the operator procedure.
3. Run `nuxr_admission_check.sql`: it must print `nuxr_admission=READY`. Anything else: stop.
4. Install the site file and restart FreeRADIUS.
5. SQL pool: `pool.max` must cover the peak of concurrent authentications plus accounting; `pool.max` >=
   `thread pool { max_servers }` (32 by default) is **necessary, not sufficient**. WP9 correction (N-17): an earlier
   version of this step stated that this sizing *removes* pool exhaustion and that exhaustion yields Access-Reject,
   *never* Accept. Neither statement is proven. rlm_sql 3.2.8 escapes every `%{...}` inside a `%{sql:...}` statement on
   its own pool connection, so one statement can need more than one connection, and the WP8 H-2B lab recorded a
   statement that ran with its inner expansions EMPTIED under exhaustion. The WP9 A2-R hardening therefore makes an
   emptied expansion fail closed instead of relying on sizing: the CHECK constraints of `nuxr_admission` refuse an
   emptied key / token / attempt identity, the two open-session statements echo their inputs from one parameter row
   (a mismatch rejects: `reject:echo`), and an ACTIVE reservation is taken over only after this request's own SAFE NAS
   verification (`reject:active-unverified`). An exhausted pool can still cost availability (refusals, an orphan
   PENDING claim when a release or Stop statement is emptied - see "Operator recovery"). The SQL-exhaustion scenario
   itself was halted in WP8 and is not reproduced; its exploitability stays unverified (WP8 lab sizing evidence: 40
   concurrent attempts, `pool.max` 8 -> 166 pool errors and 2 of 3 rounds admitted nobody; `pool.max` 32 -> no pool
   error observed, 1 admitted per round). The database `max_connections` must cover the `pool.max` of every instance
   on it plus the application. Load beyond the threads waits inside FreeRADIUS and is refused by the 5000 ms late-claim
   guard. See "Capacity sizing".

   **WP9 CHECK upgrade of an existing `nuxr_admission` table.** Re-running `nuxr_admission.sql` adds the two CHECK
   constraints once (guarded). The database validates the existing rows first; run this read-only pre-scan before the
   upgrade - it must return no row:

   ```sql
   SELECT HEX(username), state, token, nas, updated_at FROM nuxr_admission
    WHERE NOT (token REGEXP '^[0-9a-f]{32}$' AND username <> '' AND session_user <> '')
       OR NOT (nas REGEXP '^[0-9]{1,3}([.][0-9]{1,3}){3}$' AND acct_session_id <> '' AND calling_station_id <> ''
               AND nas_port_id <> '' AND request_authenticator REGEXP '^[0-9a-f]{32}$');
   ```

   A row it returns was written with an emptied value: record it, then resolve it with the operator recovery procedure
   (NAS-side proof first); it is never deleted automatically. `nuxr_admission_check.sql` reports `check_constraints`
   until both constraints exist; the application preflight (`php system/reseller/preflight.php`) also verifies their
   exact expressions and that they are enforced (MySQL `ENFORCED = YES`; MariaDB global and session
   `check_constraint_checks = 1`).
6. NAS contract additions: RouterOS returns `Class` unchanged in Start / Interim-Update / Stop (verified on 7.24.5);
   keep PPP accounting and Interim-Update enabled (`/ppp aaa set accounting=yes interim-update=...`); `/radius timeout=3s`.

**Capacity sizing** (per ISP instance; the WP8 lab numbers are evidence, not a setting to copy):
1. *Load.* Peak concurrent Access-Requests = the reconnect storm after the largest NAS reboots or loses its uplink
   (its PPPoE subscribers re-dial within seconds), plus Accounting (Start / Interim-Update / Stop: every session sends
   one Interim per `interim-update` interval). Measure on the real NAS: sessions per NAS, re-dial spread, interim
   interval.
2. *Per-request cost.* A PPPoE Access-Request runs about 10 SQL statements (authorize, Simultaneous-Use, the guard
   read, the reservation read and claim); a first login took 6-300 ms in the lab; a login with an open radacct row also
   runs stock checkrad and the A1 SNMP guard (0.7-4.3 s in the lab, guard bounded by its 3 s module timeout).
3. *Threads.* `thread pool { max_servers }` >= peak concurrent requests that must be in progress at once; throughput is
   about `max_servers / per-request time`. Requests beyond it queue inside FreeRADIUS (`max_queue_size`), and those that
   wait longer than the 5000 ms budget are refused - availability, never an unsafe Accept.
4. *SQL pool.* `pool.max` >= `max_servers` (necessary, not sufficient: step 5 above). `pool.max` 8 under 40
   concurrent requests exhausted the pool in the lab (166 errors, 2 of 3 rounds admitted nobody); `pool.max` 32 =
   `max_servers` 32: no exhaustion observed in that run (not a proof that none can occur).
5. *Database.* `max_connections` >= Σ `pool.max` of every FreeRADIUS instance on that server + the application pool +
   operator / backup sessions + margin; the database CPU / IO must sustain the per-request statements at the peak rate.
6. *Limits and failure behaviour.* Open-file and process limits of the service cover `max_servers` + sockets. Every
   Access-Accept of the PPPoE path follows a successful claim (`claim=1`); with the WP9 A2-R hardening an emptied SQL
   expansion makes the claim, the open-session read or the echo fail and the request is refused. What exhaustion does
   in practice was not measured (the experiment is halted); treat it as an availability risk to monitor.
   Re-measure after every change of NAS size, interim interval or hardware.

**Rollback:** reinstall the previous site file and restart FreeRADIUS (the A1 behaviour returns). The tables may stay
(nothing reads them then) or be dropped after a backup; they hold admission state only, no billing or accounting data.

**Operator recovery** of an unresolved claim (`PENDING`, refused logins). An Access-Accept already sent cannot be
cancelled from the RADIUS side, so a claim may be released only on **NAS-side proof that its attempt can no longer
become a session**, for example: the NAS was rebooted after the claim's `created_at`, or the NAS log shows
`authentication failed - radius timeout` for that user at that time. "No session in SNMP / `/ppp active`" alone is
NOT proof. Status of a subscriber:

    SELECT username, state, token, nas, acct_session_id, calling_station_id, created_at, attach_count,
           TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS age_s
    FROM nuxr_admission WHERE username = (SELECT username FROM radcheck WHERE username = 'LOGIN'
                                          ORDER BY CAST(username AS BINARY) LIMIT 1);

Claims that may be orphans (an Accept the NAS never acted on: no accounting at all, older than the NAS retry window).
Read-only, for monitoring; a row is a candidate for the NAS-side check above, never a proof:

    SELECT username, nas, acct_session_id, calling_station_id, created_at,
           TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS age_s
    FROM nuxr_admission WHERE state = 'PENDING' AND last_acct_at IS NULL
      AND created_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND ORDER BY created_at;

**Operator identity.** `@nuxr_operator` is free text typed by the operator: it records who *claims* to have acted, it
does not prove it. Before production (simplest mechanism, no UI, no new permission system): give every operator their
own database account (no shared login) with only `SELECT` on `nuxr_admission`, `radcheck` and `nuxr_admission_audit`,
`UPDATE` on `nuxr_admission` and `INSERT` on `nuxr_admission_audit`, and record `CURRENT_USER()` in the audit row (one
additional audit column; not implemented in A2-R) - the database then attests the account that ran the release.

Release: set `@nuxr_username`, `@nuxr_token`, `@nuxr_operator`, `@nuxr_reason`, `@nuxr_evidence` and run
`nuxr_admission_release.sql` (see its header). It releases only that `PENDING` claim with that token and records every
decision in `nuxr_admission_audit`. There is no automatic release by time, SNMP, `sysUpTime` or Acct-Session-Id.

## Hotspot admission slots (WP8 H-2B)

**Problem (WP8 H-2, `H2_GAP_CONFIRMED`).** `shared_users` is the limit of the subscriber across **every** NAS of the ISP.
RouterOS enforces `Port-Limit` per NAS only, and the stock `Simultaneous-Use` check cannot see Hotspot sessions (checkrad
looks for PPPoE interfaces), so the same Hotspot subscriber was admitted once on **each** NAS (28 of 29 cross-NAS trials
exceeded the limit in the WP8 lab).

**How it works** (Hotspot only: `Service-Type = Login-User` AND `NAS-Port-Type = Wireless-802.11`; PPPoE, VPN and every
other request are unchanged). At the end of `post-auth`, after authentication and the canonical-spelling check:
1. **Slots.** One row per (canonical subscriber, slot) in `nuxr_hs_admission`, slots `1..Simultaneous-Use`. The canonical
   subscriber is the exact stored `radcheck.username` (H1-A guarantees the request used exactly those bytes). Missing slots
   are created on demand (counted first; `INSERT IGNORE` only when some are missing) and counted again.
2. **Claim (v2).** A non-locking pre-check that fewer than `limit` slots are in use and that no slot above the limit
   is live, then ONE conditional `UPDATE ... WHERE slot <= limit AND state = 'ENDED' ORDER BY slot LIMIT 1`. InnoDB
   serialises concurrent claims from every NAS on the slot rows, so the number of slots in use never exceeds the limit
   (MariaDB 10.11 and MySQL 8.0: 25 concurrent sessions, limits 1/2/3/5, 0 deadlocks). The slot becomes `PENDING` with a new random 128-bit token, and the
   Access-Accept carries `Class = "nuxh1" + token` (PPPoE uses `"nuxr1"`; any `Class` from `radreply` is replaced).
3. **Retransmission.** An exact retransmission of the claiming Access-Request (same NAS-IP-Address, Acct-Session-Id,
   Calling-Station-Id and Request Authenticator) re-uses its `PENDING` claim and token.
4. **Accounting.** Only packets with the slot's token, NAS-IP-Address, Acct-Session-Id **and** Calling-Station-Id change
   it: Start / Interim-Update -> `ACTIVE` (a lost Start is confirmed by the next Interim-Update), Stop -> `ENDED`. Old,
   duplicated, re-ordered or foreign packets change nothing; `ENDED` is never revived; a slot taken by a newer claim is
   never touched by an older claim's packets (same Acct-Session-Id or MAC included). A Start / Interim-Update whose
   token holds no live slot writes an `orphan_accounting` audit row - a session the slots do not count (alarm).
5. **No free slot.** The **Hotspot slot verifier** checks the subscriber's `ACTIVE` slots - never a `PENDING` one - on
   their NAS over read-only SNMP (wall-clock deadline 1.5 s, module timeout 3 s): health probe (`sysUpTime`,
   `sysObjectID` = MikroTik, RouterOS major version 7), then the
   MIKROTIK-MIB Hotspot active table (columns user and MAC). The first slot whose session (same username bytes AND same
   MAC) is proven absent is ended (audit row first, then the release, both conditional on its token and state `ACTIVE`),
   and the claim runs once more. NAS unreachable, wrong or missing community, timeout, unexpected answer, missing program:
   nothing is released, the login is refused. The verifier never uses `checkrad`.
6. **STRICT MODE.** A `PENDING` slot is **never** released by time, by SNMP or by another request. An Access-Accept that
   stalled inside FreeRADIUS after its last check can still be taken by the NAS until the NAS gives up (RouterOS: about
   3 x `/radius timeout`), and FreeRADIUS 3.2.8 still sends such a reply after `max_request_time` - so "old and not in
   SNMP" proves nothing (WP8 H2-B PENDING gate: a time + SNMP release admitted two sessions under `shared_users = 1`
   with `/radius timeout=10s`). A `PENDING` slot ends only by its own accounting, or by the late-claim guard below.
7. **Late-claim guard** (same one-clock age as A2-R, 5000 ms): no claim for an older request; a claim that completed
   late gets no Access-Accept and is released by its creator (only its own token, only while `PENDING` and not re-used by
   a retransmission) before any reply exists. The same creator release runs when a claim result is not the exact
   success sentinel (a claim that committed but whose result was lost), so no slot is held by a token never sent.
8. **Fail closed.** A missing table, an SQL error or unexpected SQL result, a missing or malformed attempt identity
   (Acct-Session-Id; Calling-Station-Id must be a MAC `AA:BB:CC:DD:EE:FF` or `AA-BB-...`), a missing or malformed
   `Simultaneous-Use` (1..99): Access-Reject with only
   `Reply-Message = "Session verification failed"`. `radacct` is never written by this policy; nothing is deleted.
9. **`session { }`.** The stock radacct `Simultaneous-Use` check is **not run for Hotspot** (the slots are the limit; the
   stock check cannot see Hotspot sessions, and a checkrad build that runs SNMP held a request 10 s for an unreachable
   NAS, after which the late-claim guard refused a legitimate login). It is unchanged for every other request.

**Limit changes** (`shared_users` edited while sessions are live). A decrease does not end live sessions (no CoA): new
Hotspot logins are refused while fewer than the new limit are free OR any slot above the new limit is still live (it
ends by its own Stop, or the verifier proves its session gone); only then do the slot row locks alone bound concurrent
logins again. A login that was already in flight with the old, higher limit at the moment of the decrease can still
take one slot above the new limit (a window of one request duration). An increase takes effect at the next login
(missing slots are created).

**Availability limitation (Strict Mode, accepted 2026-10-11).** A `PENDING` slot whose Access-Accept never produced
accounting - every reply lost or later than the NAS window, a FreeRADIUS stall of more than ~9 s after its last check, a
NAS failure between the Accept and the Start, or accounting lost for the whole session - blocks that slot until resolved.
With `shared_users = 1` the subscriber is refused on every NAS. There is **no executable manual release** (owner decision
2026-10-11): monitor with `nuxr_hs_admission_report.sql` (alert `pending_over_1_minute`) and follow the runbook below.

**Prerequisites** (per ISP instance):
- The NAS identity of a slot is the `NAS-IP-Address` of the claiming request, as for the PPPoE reservation: it must be the
  NAS's own address, equal to its RADIUS source address and to `nas.nasname` (see "NAS clients"). Two NAS that send the
  same `NAS-IP-Address` (NAT, copied configuration) would be verified at one address: a session on the other could be
  taken for gone. Check that every Hotspot NAS sends a unique `NAS-IP-Address` before enabling.
- Every Hotspot NAS answers read-only SNMP v2c from FreeRADIUS, with an entry in `${confdir}/nuxr/nas_snmp` - the same
  0600 credential file as the PPPoE session guard ("PPPoE session guard" above: format, rotation, never on a command
  line). A NAS without it cannot be verified: after a lost Stop on it, the subscriber's next login is refused until a
  Stop or the NAS proves the session gone.
- RouterOS 7 (validated: 7.24.5): Hotspot accounting on (`radius-accounting=yes`, `radius-interim-update` set), `Class`
  returned unchanged in Start / Interim-Update / Stop (verified for http-chap, http-pap, cookie and MAC login),
  `/radius timeout=3s`. A RouterOS major version other than 7 is refused by the verifier until it has passed the WP8 H-2B
  gates again (the Hotspot active table and its "No Such Object" answer for an empty table must be re-validated).
- Every Hotspot subscriber has `Simultaneous-Use` in `radcheck` (RadiusPolicyBuilder always writes `max(1, shared_users)`);
  a Hotspot login without it is refused. Find exceptions before enabling (read-only):
  `SELECT DISTINCT username FROM radcheck WHERE attribute = 'Cleartext-Password' AND username NOT IN (SELECT username FROM radcheck WHERE attribute = 'Simultaneous-Use');`

**Install / upgrade** (per ISP instance, in this order; never on production without the owner's release decision):
1. Back up the RADIUS database.
2. Apply `nuxr_hs_admission.sql` (additive, idempotent: `CREATE TABLE IF NOT EXISTS` only). The FreeRADIUS SQL account
   needs `SELECT, INSERT, UPDATE` on `nuxr_hs_admission` and `SELECT, INSERT` on `nuxr_hs_admission_audit` (no `DELETE`).
3. Run `nuxr_hs_admission_check.sql`: it must print `nuxr_hs_admission=READY`. Anything else: stop.
4. Install `bin/nuxr_hotspot_slot_verifier` (0755, root) and `mods-available/nuxr_hotspot_slot_verifier` (into
   `mods-enabled/`), then the site file, then restart FreeRADIUS (`freeradius -C` first). Without the module the site
   does not load.
5. Watch `nuxr_hs_admission_report.sql` and the first logins (Access-Accept with `Class` starting `0x6e75786831`).
Sessions that are already live when the site is enabled hold no slot; they are not counted until they end (the limit
applies to new logins from then on). Enable outside peak hours or after a Hotspot session reset.

**Rollback:** reinstall the previous site file (and remove the module from `mods-enabled/`), restart FreeRADIUS (H-2
returns: the limit is per NAS again). The tables may stay (nothing reads them then) or be dropped after a backup; they
hold admission state only, no billing or accounting data.

**Recovery runbook (design only - NO executable release; owner decision 2026-10-11).**
- *Detect.* `nuxr_hs_admission_report.sql` every minute; `ALERT pending_over_1_minute` names the subscriber, slot, NAS,
  Acct-Session-Id, MAC and age; `ALERT orphan_accounting` means a live session holds no slot (investigate at once).
- *Investigate (read-only).* On the slot's NAS: `/ip hotspot active print where user=...`, `/log print where
  message~"..."` (an `authentication failed - radius timeout` for that user at `created_at` shows the NAS gave up),
  `/system resource print` (uptime: a reboot after `created_at`), and `/radius print` (the NAS window = 3 x timeout).
- *Proof that the old request can never become a session* - all of: (a) the NAS rebooted after `created_at`, or the NAS
  window (3 x its configured `/radius timeout`) plus a margin has passed since `created_at` AND the NAS log shows the
  attempt failed; (b) no Hotspot session of the user with that MAC on that NAS; (c) no Accept for that request can still
  be in flight inside FreeRADIUS (FreeRADIUS restarted since, or the request is older than any possible stall).
- *Release.* Not available. A future release procedure needs its own owner decision and safety gate (it must name the
  claim by its full token, end it only if it is still `PENDING` with that token, record the operator account and the
  evidence, and handle a resumed old request). Until then the slot stays blocked: record the case (subscriber, slot,
  NAS, age, evidence collected above) and escalate it to the owner; never edit `nuxr_hs_admission` by hand.

## Message-Authenticator (required on every Access-Request)

The `phpnuxbill` virtual server drops, without any response, every **Access-Request** that carries no
`Message-Authenticator` (BlastRADIUS hardening; owner decisions OD-W8-10 / OD-W8-13):

```
authorize {
    if (!&Message-Authenticator) {
        do_not_respond
    }
    ...
```

- Why in the virtual server: NAS clients loaded from SQL (`read_clients = yes`) do **not** inherit
  `require_message_authenticator = yes` in FreeRADIUS 3.2.8 - neither from `clients.conf` nor from the global `security`
  section (proven in the WP8 lab: a SQL client's request without the attribute was accepted with the global setting at
  `yes`). The check is the first statement of `authorize`, so it applies to every client however it is defined.
- A request with a **wrong** `Message-Authenticator` never reaches `authorize`: FreeRADIUS verifies the HMAC on receipt
  and discards the packet without a response.
- **Authentication only.** `preacct` / `accounting` are not subject to the check: NAS **Accounting-Request** packets do not
  carry `Message-Authenticator` (RouterOS does not send it) and are authenticated by their Request Authenticator.
- NAS firmware floor: **MikroTik RouterOS 7.15** or later sends `Message-Authenticator` in Access-Request; WP8 validated
  **RouterOS 7.24.5** (CHR). Older RouterOS (for example 7.12.1) cannot authenticate subscribers against this server.
- Do not relax this with `require_message_authenticator = auto` or `no`.

## V1 data and time quota (owner QUOTA_V1_GB_SUPPORT_APPROVED, 2026-10-17)

Limited plans (Hotspot **and** PPPoE) carry a time quota (`Max-All-Session`), a data quota (`Max-Data`, decimal: 1 GB =
10^9 bytes, download + upload) or both, per billing period (`Nuxr-Period-Start`). A session's usage belongs to the period
in which the session **started**; accounting history is never deleted. Capabilities measured on RouterOS CHR 7.24.5 with
FreeRADIUS 3.2.8 (`dev/wp8/quota_gate.py`; `owner-review/WP10/V1_QUOTA_RENEWAL_PLAN.md` §1):

| Mechanism | PPPoE | Hotspot |
|---|---|---|
| `Mikrotik-Total-Limit(-Gigawords)` in Access-Accept | ignored | enforced (hard cap per session) |
| `Mikrotik-Recv-Limit` / `Xmit-Limit(-Gigawords)` in Access-Accept | enforced **per direction** | supported |
| `Session-Timeout` in Access-Accept | enforced | enforced |
| `Acct-Interim-Interval` in Access-Accept | honoured | honoured |
| CoA changing a byte limit mid-session | ACKed, **not applied** | NAKed |
| CoA changing `Acct-Interim-Interval` | ACKed, **not applied** | not tested |

**At every authentication** (`authorize`, after the period counters), when `Max-Data` is set, FreeRADIUS replies the
session's share of the remaining quota, `share = floor((Max-Data - period usage) / Simultaneous-Use)`, as
`Mikrotik-Total-Limit`, `Mikrotik-Recv-Limit` and `Mikrotik-Xmit-Limit` (each with its Gigawords part), and PHPNuxBill
adds `Acct-Interim-Interval` (default 60 s; the setting `nuxr_quota_interim_seconds` in `config.php`, 10 - 300, shortens it for sessions that authenticate afterwards, at the price of one accounting UPDATE per online quota subscriber per interval). The time quota becomes `Session-Timeout` (the remaining time; the counter module).
- Hotspot: the session is cut by the router at its share: a hard cap per session, but **not byte-exact** (the NAS cuts on its own
  timer; measured on CHR 7.24.5 the recorded usage ended 0.3 % - 4.4 % past the share at ~1 Mbps, i.e. 0.2 - 0.7 s of line rate).
- PPPoE: each direction is cut at the share (= the whole remaining quota; PPPoE has Simultaneous-Use 1). Traffic in
  one direction only is capped by the NAS (same overshoot as Hotspot); upload + download together can pass the quota and is closed by the enforcer.
- **Fail closed.** A share of 0 is never sent (RouterOS reads 0 as "no limit"); an exhausted quota, a malformed quota
  row or a failed / emptied SQL expansion rejects the login ("Data quota exhausted or unavailable"). Every login also
  checks that the `Max-Data` / `Max-All-Session` rows in `radcheck` are exactly what FreeRADIUS loaded (one row each,
  digits only, equal to the control value): a malformed row such as `1e9` (the integer parser would read another
  number) or a row that was not loaded rejects ("Quota unavailable"). A Limited plan with
  a zero, negative, non-numeric or unknown-unit limit cannot be saved and is never provisioned (`POLICY_QUOTA_INVALID`).

**Accounting enforcer** (`php system/reseller/quota.php enforce --loop=55`, cron every minute, as the PHP user). Every
15 s it ends the open sessions of subscribers whose current-period usage reached `Max-Data` or `Max-All-Session`
(PPPoE upload + download; several concurrent Hotspot sessions): Disconnect-Request to the session's NAS and a confirmed
end (the Accounting-Stop seen within 6 s, or NAK 503). One audit record `quota.enforced` per subscriber and pass; a
session whose Disconnect got no answer / no Stop is retried after 15 s, one that was answered NAK after 60 s (one termination call per pass for all subscribers; a NAS that stayed silent is asked once per call); one enforcer at a time (database lock); accounting is only read.
`quota.php report` lists over-quota open sessions (read-only).
- **This is not a byte-exact cap.** Overshoot past the quota is bounded by
  `min(share at authentication, rate x (interim + pass + Disconnect <= 6 s))`, measured on CHR (`owner-review/WP10/N1_PPPOE_OVERSHOOT.md`:
  the model, the measured matrix, the cost of a shorter interval and the options). Never advertise it as byte-exact.
- If the enforcer, FreeRADIUS, the database or the NAS link is down, the router's own limits still hold
  (PPPoE worst case: the share in each direction, i.e. 2 x the remaining quota).
- `health.php` check `quota_enforcer`: CRITICAL when quota subscribers are online and the enforcer has not completed a
  pass for 3 minutes; WARNING while an over-quota session is still open.

**Period attribution (exact, independent of the NAS clock).** A session counts against the billing period in which its
accounting row **arrived**: a period that starts now owns every `radacct` row whose `radacctid` is larger than the table's
highest id at that moment (`Nuxr-Period-Acct-Id`, written by PHPNuxBill with the quota). The NAS clock plays no part. Measured on
RouterOS CHR 7.24.5, the NAS stamps Accounting-Start **1.1 - 1.7 s earlier** than the host receives it, and a PPPoE client redials
right after the renewal Disconnect, so a comparison of the NAS stamp with the period start booked the first new session to the
previous period (its traffic escaped the new quota); a 10 s allowance fixed that but also counted a session opened just *before* a
renewal in the new period. Ownership by arrival order has neither error (`owner-review/WP10/N2_PERIOD_OWNERSHIP.md`).
- The id is paired with its period: a re-provision of the same period (password, rename, re-sync) keeps it, so usage is never
  reset; a renewal / plan change / reactivation takes the table's current end. A period provisioned before this change has no id
  and keeps the **time rule** (`Nuxr-Period-Start` = the period start minus 10 s, `QuotaPolicy::PERIOD_SKEW_SECONDS`) until it ends.
- A late Interim, a duplicate Start or a retransmitted Stop updates its own row: a row never moves between periods.
- The counters, the FreeRADIUS share and the enforcer trust the id only when it is well-formed and not beyond the table's end (an
  accounting table restored from an older backup falls back to the time rule); a malformed id rejects the login.
- A login accepted just before the renewal whose Start arrives just after it is counted in the new period (it can only add usage).
- **Install:** the dictionary now defines `Nuxr-Period-Acct-Id`: install `dictionary.phpnuxbill`, `mods-available/nuxr_counters`
  and `sites-available/phpnuxbill` together, and restart FreeRADIUS, **before** the first quota plan is provisioned (an unknown
  attribute in `radcheck` makes FreeRADIUS reject the subscriber).
- Clocks: attribution no longer needs the NAS clock, but accounting times (display, freshness) and the time-rule fallback do.
  Every host must be UTC and NTP-synchronised (preflight `time`: SQL session zone UTC, database-vs-PHP skew < 5 s); every NAS
  should run NTP (health `nas_clock` reports interim stamps in the future by more than 120 s; operations tolerate 60 s of drift).
- The database session zone is not involved: every connection is UTC (WP8 A4); a zone mismatch was investigated and refuted.

**Accounting integrity.** An Interim-Update that is stale (after its session's Stop, or with smaller counters than
already stored: re-ordered or late retransmission) is acknowledged but not written, so usage never decreases. A
duplicate Start / Stop is handled by the stock queries (`acctuniqueid`). Counters are 64-bit (Gigawords combined).

**Renewal (owner RENEWAL_OPTION_B_APPROVED).** A RENEW, CHANGE_PLAN or REACTIVATE activation ends the open sessions
that started **before** the new period; a legacy recharge / voucher renewal (the Radius device: a new policy takes
effect at the next session) ends every open session of the subscriber. One Disconnect per session, and the end is
confirmed. A Disconnect-ACK
alone is not proof; RouterOS 7.24.5 answers a Disconnect for a session it no longer has with **NAK 406** (not 503),
which stays unconfirmed. An unconfirmed end never undoes the renewal (it settles exactly once); it is reported as
drift `SESSION_POLICY_STALE` until the session's Stop arrives (`docs/OPERATOR_RECOVERY_RUNBOOK.md` §4a). The old
session can never use more than its own grant (its Session-Timeout and per-session byte limits).

## Disconnect / CoA

PHPNuxBill sends RFC 5176 Disconnect-Request and CoA-Request packets itself (`Reseller\Radius\CoAClient`); `radclient` is not used and no shell is involved.
- It sends to the NAS address in `nas.nasname` (it must be an IP address).
- The port is `nas.ports` when it is set (the legacy convention), otherwise 3799.
- MikroTik: `/radius incoming set accept=yes port=3799`.

**Port and source on MikroTik (WP8 notes, 2026-10-14).** Validated on RouterOS CHR **7.24.5** in the WP8 lab unless a
line says otherwise:
- **Port.** RADIUS > NAS "Ports" (`nas.ports`) is the CoA / Disconnect **destination** port; empty means 3799. Set the NAS
  to the same port explicitly: WP8 ran `nas.ports = 3799` with `/radius incoming set accept=yes port=3799`.
  - MikroTik documents **1700** as the factory default of `/radius incoming port`. WP8 never ran with 1700. Do not rely on
    the default: with an empty "Ports" and an unchanged router, PHPNuxBill sends to 3799 while the router listens on 1700.
  - A port mismatch, `accept=no` or a firewall drop all look the same to PHPNuxBill: no answer → `UNKNOWN
    COA_NO_RESPONSE` after 3 identical packets about 2 s apart. It is never retried as a new request and never rolls
    back provisioning.
- **Source address (binding rule).** Send CoA / Disconnect **from an address that is configured as a RouterOS `/radius`
  server** on that NAS. In WP8 the application shares the FreeRADIUS address that the NAS uses as its `/radius` server
  (co-located; no SNAT, no second generic `/radius` entry).
  - Proven on 7.24.5: requests from that address are answered.
  - Requests from an address that is **not** a `/radius` server were silently ignored on RouterOS **7.12.1** (WP8
    feasibility gate). This case was **not re-tested on 7.24.5**; keep the rule regardless of version.
- **Answers seen on 7.24.5.** ACK for a live session → `SUCCESS`. A request that matches no session → **NAK
  Error-Cause 406** (never 503) → `FAILED COA_NAK`. A `Mikrotik-Rate-Limit` CoA is applied live. A CoA that changes
  `Framed-Pool` / `Framed-IP-Address` is ACKed but **ignored**: change the policy, then Disconnect so the subscriber
  re-authenticates.
- Allow UDP to the incoming port on the NAS only from the PHPNuxBill / FreeRADIUS address.

Details and evidence: `docs/WP8_INTEGRATION_MODEL.md` §2 and §6, `docs/WP4_RADIUS_MODEL.md` §13.

## rlm_rest mode (`radius.php`)

The REST endpoint is **off** unless `config.php` defines it:

```php
$radius_rest_secret = '...at least 32 random characters...';
$radius_rest_allow  = '127.0.0.1, ::1';   // optional: IPs / CIDRs of the FreeRADIUS servers calling radius.php
```

Configure the FreeRADIUS `rest` module with `auth = 'basic'`, any user name, and the same secret as the password. Alternatively, send the header `X-Nuxr-Radius-Secret`.
