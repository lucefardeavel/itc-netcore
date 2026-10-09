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
