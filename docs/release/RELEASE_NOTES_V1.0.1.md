# ITC NetCore V1.0.1: release notes (release candidate)

Developed and maintained by **ITC – Integrated Technology Company** · www.itc-iq.net

V1.0.1 is a maintenance release built on V1.0 (`v1.0.0-rc.1`, frozen code `0a8b9d58`), on the branch
`maint/v1.0.1-itc-only`. It changes identity, wording and usability, adds thermal voucher printing and
username-and-password hotspot cards. The finance, activation, pricing and reseller logic is unchanged.

## What changed

### Identity: ITC only
- The interface, the subscriber portal, the README and the delivered guides name ITC only. No donation, community or
  original-project link remains.
- `LICENSE`, `NOTICE` and the copyright headers in the source files stay (GNU GPL v2).

### Interface
- **Notifications** are a small toast at the top centre, in Arabic. The **sign-in page** is centred.
- **No countdown pages** after signing in or out, or after a failed sign-in.
- **Show / hide button** on every password field.
- **Password change** names the real reason for each refusal. New passwords are 8 to 64 characters.
- **Simple menu:** 11 groups organised around daily work. Technical pages are under *Advanced*.
- **One word per thing:** المشترك, الموزّع, الراوترات, السرعات.
- Tables, page titles, devices, widgets and logs are in Arabic.
- **Messaging:** a direct "Connect WhatsApp and SMS" link opens the WhatsApp settings, with a plain guide.
- **Data consistency check:** the former "drift check" has a one-line explanation and Arabic finding names.
- **Plugin page:** no external plugin catalogue is fetched or shown. The GitHub settings are removed.
- Maps and the transactions report no longer stop with a script error when there is no data.

### Vouchers
- **80 mm thermal cards** are the default; the A4 sheet stays.
  - A **hotspot card** prints the company, plan, validity, price, the code, a QR code and short instructions.
  - A **recharge card** for a PPPoE subscriber prints the same, with instructions for Voucher Activation in the portal.
  - Recharge cards are for subscribers managed directly by the company. A reseller's subscribers are renewed by
    their reseller.
- **Username-and-password hotspot cards (new):** choose "User name and a separate password" when generating hotspot
  cards. The code is the user name. A separate password of 4 to 12 digits is printed on the card.
  - The portal asks for that password before it activates the card.
  - RADIUS receives the card password.
  - One-code cards work as before.

### Iraq defaults on a fresh install
A fresh install now starts with these settings:
- currency: Iraqi dinar (د.ع, shown as 25,000);
- time zone: Asia/Baghdad;
- language: Arabic;
- dates: numeric (d/m/Y);
- country code: 964;
- receipt note: in Arabic.

Only the stock values of the base schema are replaced. An existing installation keeps its settings.
FreeRADIUS must run with the same time zone (`TZ=Asia/Baghdad`).

## Upgrading a V1.0 installation

1. Take a backup and run a restore check (`install/backup/`).
2. Deploy the V1.0.1 code (verified artifact) as for any release.
3. Run the project migrations with the migration account:

   ```
   php system/reseller/migrate.php preflight --config=config.php --db-user=<migration user> --db-password-env=<VAR>
   php system/reseller/migrate.php migrate   --config=config.php --db-user=<migration user> --db-password-env=<VAR>
   php system/reseller/migrate.php verify    --config=config.php --db-user=<migration user> --db-password-env=<VAR>
   ```

   R0008 creates `tbl_voucher_logins`. Until it is applied, the application reports the reseller schema as not ready.
4. Give the application account its rights on the new table. Review the output of
   `php system/reseller/grants.php --app="'<app user>'@'<host>'"` (run as an administrator), or apply:

   ```
   GRANT SELECT, INSERT, UPDATE, DELETE ON `<database>`.`tbl_voucher_logins` TO '<app user>'@'<host>';
   ```

5. **Hotspot login page (MikroTik).** If you use username-and-password cards, the login page sends:
   - the user name as `voucher_only`;
   - the password as `voucher_password`;
   - both to `/?_route=login/activation`.

   One-code cards need only `voucher_only`, as before.
6. Run the release preflight (`preflight.php` without `--setup`). Sign in and check the dashboard, voucher printing
   and one card activation.

## Verification

- Unit suite: 195 tests, 0 failures. It includes a new test that compiles every interface template.
- Browser review of 66 pages on desktop and phone on a fresh local instance. The changed pages were checked again
  after the changes.
- On the local instance:
  - the migrations ran: R0008 applied and verified against its contract;
  - cards were generated and printed (thermal and A4);
  - card activation was refused without the password and with a wrong one, and accepted with the right one;
  - RADIUS received the card password for a username-and-password card, and the code for a one-code card.
- The Docker suites (MariaDB 10.11 and MySQL 8.0) and a real-router (CHR) window are listed in the pre-release notes
  with their result.

## Status

Release candidate; not production-accepted. `v1.0.0-rc.1` and the frozen V1.0 branch are unchanged.
