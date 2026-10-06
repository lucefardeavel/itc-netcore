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
| `sites-available/phpnuxbill` | `sites-enabled/phpnuxbill`, replacing `default` | authorize / authenticate (PAP, CHAP) / accounting / session |

## Rules

**Accounting history is never deleted.**
- FreeRADIUS writes `radacct`; PHPNuxBill only reads it.
- Before WP4, PHPNuxBill deleted the subscriber's `radacct` rows on every activation, renewal, sync, password or username change, and expiry. That deletion was what reset the quotas.
- Since WP4, quotas reset by period instead: PHPNuxBill writes `Nuxr-Period-Start` (the start of the billing period, a UNIX timestamp), and the `nuxr_*` counters sum only the sessions started since then.

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
