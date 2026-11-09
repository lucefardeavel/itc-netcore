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
- **Get started:** after a fresh install the administrator dashboard lists the steps that make the system ready
  (company name and phone, logo, router or RADIUS device, plans, optional reseller prices). Each step opens its page
  and is ticked when done; the card disappears once the required steps are done.
- **Durations in words** with the Arabic number agreement: "شهر واحد", "شهران", "3 أشهر", "12 شهراً", "ساعة واحدة".
- **Settings explained:** one plain-language line under each technical field (SMTP, Telegram, proxy, separators,
  phone country code, reminders and others). Card code formats are named in Arabic.
- **Reseller and sales screens** (reviewed as a reseller and as a sales rep, on desktop and phone):
  - a subscriber without a service shows "No service yet" and Activate (the box was empty);
  - the sales rep is "مندوب المبيعات" everywhere and the finance menu item is "Sales report";
  - the pricing page explains the provider base cost and reseller selling;
  - clearer page titles; an empty vouchers table says so.
- **Subscriber portal:** "Internet package" (باقة الإنترنت) and "Refresh connection" instead of "Radius plan" and "Sync"; Sign in is the main
  button; the show-password button stays inside the field; the unused Order Voucher page (a server error) opens the
  voucher activation.
- **No request to other sites** from the bulk-message, coupons, plan-editor and invoice pages: their libraries are
  served locally (each copy checked against its pinned SRI hash). The coupons table and the plan script editors no
  longer stop with a script error.
- The version shown is **V1.0.1**.

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
- **Hotspot sign-in page (new):** Settings > General > Voucher has a button that downloads the router's
  `login.html` with this system's address.
  - The router opens the ITC NetCore card page. The subscriber enters the card code (and the card password when the
    card has one), or a subscriber account. The system activates the card and signs the device in on that router
    (CHAP when the router offers it).
  - The page sends sign-ins only to a private or CGNAT address, or to a hotspot name listed in the setting
    "Hotspot router names".
  - Card activation refusals return to the sign-in page with a notice (no countdown page).

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

5. **Hotspot login page (MikroTik).**
   - Download `login.html` from Settings > General > Voucher ("Download the router login page").
   - Put it in the router's hotspot folder (Files > `hotspot/login.html`).
   - Add this system's address to the hotspot walled garden, for example
     `/ip hotspot walled-garden add dst-host=billing.example.iq`.
   - Allow `http-chap` (recommended) or `http-pap` in the hotspot server profile.
   - If the hotspot uses a DNS name (for example `login.itc`), add it to "Hotspot router names".
6. Run the release preflight (`preflight.php` without `--setup`). Sign in and check the dashboard, voucher printing
   and one card activation.

## Verification

- Unit suite: 204 tests, 0 failures. It includes a test that compiles every interface template.
- Browser review of 66 pages on desktop and phone on a fresh local instance. The changed pages were checked again
  after the changes.
- On the local instance:
  - the migrations ran: R0008 applied and verified against its contract;
  - cards were generated and printed (thermal and A4);
  - card activation was refused without the password and with a wrong one, and accepted with the right one;
  - RADIUS received the card password for a username-and-password card, and the code for a one-code card;
  - the hotspot sign-in page activated both card kinds and sent the sign-in to a simulated router (CHAP and PAP);
  - as a reseller: provider funding, a new subscriber and an activation from the wallet (20,000 debited, service on).
- Docker suites (MariaDB 10.11 and MySQL 8.0), 1,729 tests on each engine, run at `75440d68`:
  - 3 failures found and fixed in `2d7e7c9a` (two portal heading assertions, one voucher guard); the affected tests
    (276) and every source guard and template test (99) were run again on both engines: 0 failures;
  - 2 tests fail only on the Windows test host and are not code failures: `GuardSelfcheckSourceTest` and
    `SessionGuardSourceTest` read the file mode, which NTFS reports as 0777 instead of the committed 0755;
  - the variant registry matched freshly built fixtures.
- Not yet run: a real-router (MikroTik CHR) window and a real 80 mm printer.

## Status

Release candidate; not production-accepted. `v1.0.0-rc.1` and the frozen V1.0 branch are unchanged.
