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
- `read_clients = yes` loads the NAS list that PHPNuxBill manages (RADIUS > NAS).
- The NAS secret is stored in the `nas` table in plaintext, because FreeRADIUS needs to read it.
- PHPNuxBill never shows the stored secret again after it is saved.

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
