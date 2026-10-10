# FreeRADIUS configuration for PHPNuxBill (WP4)

Stock FreeRADIUS cannot serve PHPNuxBill subscribers. PHPNuxBill writes the check items `Max-All-Session`
(time quota) and `Max-Data` (data quota), which are unknown to a stock server, and FreeRADIUS then rejects
every subscriber whose `radcheck` rows contain them. This directory holds the project-owned configuration that
PHPNuxBill requires and tests (owner decision C16, option a).

It is tested with FreeRADIUS **3.2.8** (`freeradius/freeradius-server:3.2.8`) on MariaDB 10.11 and MySQL 8.0. See
`tests/Integration/Wp4/FreeRadiusContractTest.php` and the test setup in `dev/test/freeradius/`.

| File | Install as | Purpose |
|---|---|---|
| `dictionary.phpnuxbill` | `/etc/freeradius/dictionary.phpnuxbill`, plus the line `$INCLUDE dictionary.phpnuxbill` in `/etc/freeradius/dictionary` | defines the internal attribute `Nuxr-Period-Start` |
| `mods-available/nuxr_counters` | `mods-enabled/nuxr_counters` | `Max-All-Session` / `Max-Data` counters for the **current billing period** |
| `mods-available/sql.example` | adapt into `mods-available/sql`, then link it in `mods-enabled/` | SQL module on the PHPNuxBill RADIUS database (`read_groups = yes`) |
| `sites-available/phpnuxbill` | `sites-enabled/phpnuxbill`, replacing `default` | authorize / authenticate (PAP, CHAP) / accounting / session / post-auth (PPPoE session guard) |
| `bin/nuxr_pppoe_session_guard` | `${confdir}/nuxr/nuxr_pppoe_session_guard`, mode 0755, owner root | fail-closed PPPoE live-session verifier (see "PPPoE session guard") |
| `mods-available/nuxr_pppoe_session_guard` | `mods-enabled/nuxr_pppoe_session_guard` | the named exec module that runs the verifier from post-auth |
| `nuxr_admission.sql` | run once in the RADIUS database (additive, idempotent) **before** enabling the site | PPPoE admission reservation tables (see "PPPoE admission reservation") |
| `nuxr_admission_check.sql` | run in the RADIUS database | read-only readiness check: prints `nuxr_admission=READY` |
| `nuxr_admission_release.sql` | run by the operator only, per the recovery procedure | releases ONE unresolved PENDING claim, with an audit record |

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
PPPoE gets no `Port-Limit` (RouterOS ignores it for PPPoE).

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
7. Fail closed: a missing table, an SQL error, an unexpected SQL result, a missing or malformed attempt identity
   (Acct-Session-Id, Calling-Station-Id, NAS-Port-Id), a guard failure: Access-Reject with only
   `Reply-Message = "Session verification failed"`. `radacct` is never written by this policy; nothing is deleted.

**Authentication methods.** PAP and CHAP (the methods of this virtual server) were tested. Any other method (for example
MS-CHAPv2) is refused by the virtual server. A `Class` attribute from `radreply` / `radgroupreply` is replaced on PPPoE
Access-Accepts.

**Not covered.** Hotspot is unchanged and NOT covered: RouterOS enforces `Port-Limit` per spelling, so two equivalent
spellings of one Hotspot subscriber can hold two sessions (WP8 finding H-1, HIGH, open; must be closed before
production).

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
5. SQL pool: `pool.max` must cover the peak of concurrent authentications plus accounting. When the pool is exhausted
   FreeRADIUS answers Access-Reject (`No connections available`), never Accept. Each FreeRADIUS worker thread holds at
   most one connection at a time, so `pool.max` >= `thread pool { max_servers }` (32 by default) removes pool exhaustion
   inside one instance (WP8 lab: 40 concurrent attempts, `pool.max` 8 -> 166 pool errors and 2 of 3 rounds admitted
   nobody; `pool.max` 32 -> no pool error, 1 admitted per round). The database `max_connections` must then cover the
   `pool.max` of every instance on it plus the application. This does not add capacity: load beyond the threads waits
   inside FreeRADIUS and is refused by the 5000 ms late-claim guard (availability, never a second session). See
   "Capacity sizing".
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
4. *SQL pool.* `pool.max` >= `max_servers` (one connection per busy thread; step 5 above). `pool.max` 8 under 40
   concurrent requests exhausted the pool in the lab (166 errors, 2 of 3 rounds admitted nobody); `pool.max` 32 =
   `max_servers` 32: no exhaustion observed.
5. *Database.* `max_connections` >= Σ `pool.max` of every FreeRADIUS instance on that server + the application pool +
   operator / backup sessions + margin; the database CPU / IO must sustain the per-request statements at the peak rate.
6. *Limits and failure behaviour.* Open-file and process limits of the service cover `max_servers` + sockets; on any
   exhaustion (threads, SQL pool, database) the answer is Access-Reject or no answer - never an Accept without a claim.
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

## Disconnect / CoA

PHPNuxBill sends RFC 5176 Disconnect-Request and CoA-Request packets itself (`Reseller\Radius\CoAClient`); `radclient` is not used and no shell is involved.
- It sends to the NAS address in `nas.nasname` (it must be an IP address).
- The port is `nas.ports` when it is set (the legacy convention), otherwise 3799.
- MikroTik: `/radius incoming set accept=yes port=3799`.

## rlm_rest mode (`radius.php`)

The REST endpoint is **off** unless `config.php` defines it:

```php
$radius_rest_secret = '...at least 32 random characters...';
$radius_rest_allow  = '127.0.0.1, ::1';   // optional: IPs / CIDRs of the FreeRADIUS servers calling radius.php
```

Configure the FreeRADIUS `rest` module with `auth = 'basic'`, any user name, and the same secret as the password. Alternatively, send the header `X-Nuxr-Radius-Secret`.
