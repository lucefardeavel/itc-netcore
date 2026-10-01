# WP0 Gap Analysis

Work Package: **WP0 — Repository Reconnaissance & Baseline Verification**
Status: `IMPLEMENTED_NOT_ACCEPTED` (waiting for owner review)
Date: 2026-10-01
Baseline: application tree identical to upstream `d3e0596`; evidence in [`BASELINE_VERIFICATION.md`](BASELINE_VERIFICATION.md)

This document compares the actual baseline with the owner-approved specification (`docs/claude-development-pack/`). It covers the role and scope model, every service-granting entrypoint, finance behaviour, RADIUS/MikroTik behaviour, credential handling, pre-existing security findings, UI/PWA readiness, and the spec↔repository conflicts that need owner decisions.

**Rules applied**
- Nothing here was fixed (owner decision C9).
- Security findings are described at "what and where" level and contain no exploit payloads.
- Labels: `STATIC` = code evidence; `DYNAMIC` = reproduced in the WP0 sandbox (BASELINE_VERIFICATION §3).

---

## 1. Request architecture (as-is)
**Web dispatch**
- `index.php` → `system/boot.php`.
- `_route` (or `REQUEST_URI`) is split on `/`; `$routes[0]` selects `system/controllers/{handler}.php` (`boot.php:70-129`).
- Actions are `switch ($routes[1])` cases inside each controller.

**API**
- `system/api.php` sets `$isApi=true`.
- It includes the **same** controller files (`api.php:139-142`).
- Token types:
  - `api_key` equality (`api.php:84-85`);
  - `a.`/`c.` tokens signed with `sha1(uid.time.$api_secret)`, where `$api_secret` defaults to `$db_pass` (`init.php:376-377`).
- CSRF is always bypassed for the API (`Csrf.php:25`).

**Authentication helpers**
- `_admin()` (staff) checks only that a staff id resolves (`init.php:225`, `Admin::getID`).
- `_auth()` (customer) is at `init.php:212`.
- Neither checks `tbl_users.status`. The word `status` never appears in `Admin.php` or `admin.php`.

**Web-server firewall**
- `.htaccess_firewall` would restrict PHP entrypoints to `index.php`, `update.php` and `radius.php`.
- It is **not active by default**: the file is not named `.htaccess`. Every PHP file is web-reachable unless the operator renames or configures it, including `system/cron.php`, `system/api.php` and `install/*`.

**Plugins**
- `init.php:101-109` includes every `system/plugin/*.php` and ignores any error.
- `run_hook` returns after the first handler and ignores return values (`Hookers.php:48-58`), so **a hook cannot veto or gate an operation**.

## 2. Role / action matrix (staff)
Legend: S=SuperAdmin, A=Admin, Ag=Agent, Sa=Sales, R=Report. "all" = every staff role. Most controllers call only `_admin()` at the top and gate per `case`, so an action without a gate is reachable by **all** staff roles. Unless marked, the gate is checked at the cited line.

| Controller | Action | Allowed | Evidence |
|---|---|---|---|
| customers | csv, csv-prepaid | S,A | `customers.php:25`, `:86` |
| customers | add (form), recharge | S,A,Ag,Sa | `:155`, `:164` |
| customers | deactivate, login (impersonate), edit (form), delete | S,A | `:239`, `:308`, `:378`, `:418` |
| customers | **list, view/viewu, add-post, edit-post, sync** | **all** | `:829`, `:324`, `:460`, `:589`, `:274` (no gate) |
| plan | sync, delete, edit-post, remove-voucher, voucher-delete(-many) | S,A | `plan.php:38`, `:342`, `:367`, `:552`, `:925`, `:837` |
| plan | recharge(+confirm,+post), add-voucher, voucher-post, refill(+post), deposit(+post) | S,A,Ag,Sa | `:73`, `:91`, `:175`, `:537`, `:712`, `:938/949`, `:973/987` |
| plan | edit (form) | S,A,Ag | `:312` |
| plan | **view, print, voucher list, print-voucher, voucher-view, extend, billing list** | **all** | `:259`, `:290`, `:434`, `:569`, `:869`, `:1036`, `:1088` |
| settings | users, users-add, users-edit, users-post | S,A,Ag | `settings.php:474`, `:546`, `:588`, `:666` |
| settings | users-delete | S,A | `:645` |
| settings | **users-view, users-edit-post, change-password** | **all** | `:555`, `:739`, `:889/896` |
| settings | app/localisation/maintenance/db backup | S,A; two actions S only | `:56`, `:189`, `:339`, `:940`, `:991`, `:1014` |
| coupons | all | S,A,Sa (Agent excluded) | `coupons.php:19` |
| message | send, send_bulk | S,A,Ag,Sa | `message.php:21` |
| message | **send_bulk_selected** | **all** | `:359` |
| reports, export, dashboard, maps, autoload (AJAX), paymentgateway | **whole controller** | **all** | `reports.php:8`, `export.php:8`, `maps.php:8`, `autoload.php:12`, `paymentgateway.php:8` |
| routers, radius (NAS), pool, odp, bandwidth, services (plans), widgets, pages, pluginmanager, logs | whole controller | S,A | `routers.php:17`, `radius.php:15`, `services.php:14`, `widgets.php:13`, `pluginmanager.php:18`, `logs.php:15`, … |
| **search_user** | query | **unauthenticated** (no `_admin`/`_auth`) | `search_user.php:1-20` |
| **plugin** | `plugin/{function}` | **unauthenticated**; calls any existing zero-argument global function | `plugin.php:7-8` |
| **callback** | `callback/{gateway}` | unauthenticated (gateway plugin must verify) | `callback.php:12-17` |
| update.php (root) | download/extract/migrate | **any staff session** (`$_SESSION['aid']` non-empty) | `update.php:29`; `update_url` taken from request `:20-23` |

## 3. Agent / Sales / Report behaviour (as-is)
**Agent**
- Can create sub-users.
- `user_type` is taken from POST with **no whitelist** (`settings.php:679→710`). The same applies on edit (`:861-862`).
  - So an Agent can create Agent, Sales, Report, **Admin or SuperAdmin** users.
  - `root` is forced to the creating Agent (`:718-720`).
- Can see itself and direct children only (`settings.php:479-521`). There is no deeper hierarchy.
- Can recharge, deposit, refill and generate vouchers for **any** customer (no ownership check).
- Can see **all** customers, billing, reports and exports.

**Sales**
- Same customer reach as Agent: everything.
- Can generate vouchers and recharge any customer.
- The voucher list has **no** Sales filter (`plan.php:479-503` handles only S/A and Agent), so Sales sees all voucher codes.
- Can manage coupons (Agent cannot).

**Report**
- Is in no allow-list.
- Still reaches every ungated action in §2: the customer list and view, **add-post/edit-post/sync**, `plan/extend`, billing/reports/export, maps, AJAX lookups, `message/send_bulk_selected`, payment-gateway configuration and the `reports.php` delete branch.
- **It is not read-only.**

**Spec gap.**
- 05_PERMISSION_MATRIX requires:
  - Agent: subtree-only;
  - Sales: assigned customers only;
  - Report: denied on new-domain routes.
- None of this exists today, and the *legacy* routes themselves are unscoped (conflict C7).

## 4. Customer ownership and data scoping
- `tbl_customers.created_by` (signed INT) is set once (`customers.php:514`) and is **never read as a filter** anywhere in the codebase.
- There is no commercial-owner or Sales-assignment field.
- `tbl_users.root` is the only relationship. It holds a single parent and is used only for user lists and voucher lists.

## 5. IDOR / unscoped object access (STATIC)
| Path | Lookup | Reached by | Data / effect |
|---|---|---|---|
| `customers` view/viewu | `find_one($id)` `customers.php:330` | all staff | full customer record, plans, balance |
| `customers` edit-post | by posted id | all staff | modify any customer |
| `customers` sync | by id | all staff | re-provision any customer (RADIUS: purges `radacct`) |
| `customers` recharge form | `customers.php:173/177` | S,A,Ag,Sa | any customer |
| `customers` list / csv | unscoped `:846-909`, csv S/A | all staff | all customers |
| `plan` recharge / recharge-post | `plan.php:78`, `User::_info($id)` `:107` | S,A,Ag,Sa | grant service to any customer |
| `plan` extend | `find_one($id)` `plan.php:1043` | all staff | extend any active plan by URL-supplied days |
| `plan` deposit-post | posted customer | S,A,Ag,Sa | add arbitrary balance to any customer |
| `plan` view/print invoice | `plan.php:261` | all staff | any invoice |
| `plan` billing list | unscoped `:1112-1126` | all staff | all active plans |
| `message` send/send_bulk_selected | `message.php:46/70/381` | all staff | message any customer |
| `maps` | `maps.php:26-29` | all staff | name, phone, email, coordinates, balance of all customers |
| `autoload` balance / customer_select2 / plan_is_active / pppoe_*_used | `autoload.php:36,51,64,129,170-173` | all staff | balance lookup and enumeration |
| `reports`, `export` | global queries | all staff | all transactions |
| `search_user` | `search_user.php:6` | **unauthenticated** | username + id enumeration |
| voucher list (Sales) | no filter `plan.php:479-503` | Sales, Report | all voucher codes |

All of these must go through WP2 DataScope (D020). For reseller-owned customers, the service-granting ones must also go through the WP5 orchestrator or fail closed.

## 6. Voucher exposure (D027 input)
**Reseller voucher generation EXISTS today.**
- `plan/voucher-post` (`plan.php:711-830`) allows S, A, **Agent, Sales**.
- It has no cost, no CSRF check and no plan/router ownership validation.
- It writes `generated_by` (`:784`) and the global `voucher_prefix` appconfig (`:742-752`).

**Code quality**
- Codes come from `md5(time().rand())` (`plan.php:766`) or `rand()` (`init.php:304`). Neither is a CSPRNG.
- There is no unique index on `tbl_voucher.code`.

**Redemption.** Seven paths grant service (§7: E7, E12, E13, E14, E15; refill E2; RADIUS authenticate E16). Every redemption marks the voucher used *after* `rechargeUser`, with no lock, so concurrent redemption of one code is a race.

## 7. Service-granting and money-moving entrypoint inventory
"Reseller-owned reach" = whether the path can grant or extend service or move money for a customer who will be reseller-owned under the new domain. Today **every row is reachable for any customer**, because no ownership model exists.

| ID | Entrypoint | Trigger / actor | Gate today | Calls | Money effect | Replay / idempotency | Bypass candidate | WP5B proposal |
|---|---|---|---|---|---|---|---|---|
| E1 | `plan.php:175-271` recharge-post | Staff S,A,Ag,Sa (web+API) | role list; no scope; no CSRF | `rechargeUser` `:242` | `using=balance` → `Balance::min` after recharge (`:244`); `using=zero` → free (`:237-241`) | none | **YES** | route to ActivationService for reseller-owned; forbid `zero` outside admin policy |
| E2 | `plan.php:948-971` refill-post (voucher) | Staff S,A,Ag,Sa | role list; any generator's voucher | `rechargeUser` `:958` | voucher value | voucher status only, no lock | **YES** | fail closed for reseller-owned until voucher settlement approved |
| E3 | `plan.php:987-1030` deposit-post | Staff S,A,Ag,Sa | role list; no scope | `rechargeBalance` `:1008,1021` → `Balance::plus` | credits customer balance, any amount | none | **YES** (customer-balance minting) | admin-only + audit; reseller retail-balance policy decision |
| E4 | `plan.php:1036-1087` extend | **All staff** incl. Report | none | device `add_customer` `:1064` | none recorded (free extension) | none | **YES** | fail closed / admin-only audited action |
| E5 | `plan.php:366-432` edit-post | S,A | role | device `add_customer` `:419` | sets arbitrary expiry/plan | none | admin override | keep admin-only; audit; exclude from reseller UI |
| E6 | `plan.php:711-830` voucher-post | S,A,Ag,Sa | role | creates vouchers | **free prepaid inventory** | none | **YES** | disable for Agent/Sales (D027) |
| E7 | `voucher.php:59-76` activation-post | Customer (web+API) | `_auth` | `rechargeUser` `:64` | voucher value | voucher status | **YES** | orchestrator or fail closed for reseller-owned |
| E8 | `order.php:203-261` pay (balance) | Customer | `enable_balance`, balance ≥ total | `rechargeUser` `:246` then `Balance::min` `:248` | customer balance debit | none | **YES** | orchestrator (retail + wholesale) or fail closed |
| E9 | `order.php:262-393` send (gift) | Customer | `enable_balance` | `rechargeUser` `:331` (target) then `Balance::min` `:334` (sender) | sender balance debit | none | **YES** | same as E8 |
| E10 | `order.php:539-704` buy + `order.php:155-182` view/check/cancel + `callback.php:12-17` | Customer / gateway callback | `_auth`; callback unauthenticated | external `{gw}_create_transaction/_get_status/_payment_notification`, which call `rechargeUser` **inside plugin code** | retail payment | `$trx` global guard `Package.php:30-33` only | **YES** | gate at core: refuse reseller-owned orders until orchestrated |
| E11 | `cron.php:101-125` auto-renew | Cron (CLI; also web-reachable if firewall inactive) | `enable_balance` + `auto_renewal` | `rechargeUser` `:109` then `Balance::min` `:110` | customer balance debit at `tbl_plans.price` | none | **YES** (DYNAMIC F4) | orchestrator or fail closed for reseller-owned |
| E12 | `login.php:121-170` voucher-only activation | **Public** | CSRF checked `:73` | `rechargeUser(0,…)` `:126` + device `:144` | voucher value | voucher status | **YES** | fail closed for reseller-scoped vouchers |
| E13 | `login.php:175-306` voucher + username activation | **Public** | **no CSRF**; no ownership proof | overwrites the customer password with the voucher code (`:203`), then `rechargeUser` `:206`, `connect_customer` `:221` | voucher value | voucher status | **YES** (+ SEC-16) | redesign / fail closed |
| E14 | `login.php:78-119` re-add active voucher user | Public | voucher exists, status not off | device `add_customer` `:99` | none | n/a | re-provision only | gate with E12 |
| E15 | `radius.php:237-260` rlm_rest authorize | **FreeRADIUS REST (unauthenticated HTTP)** | voucher unused, `routers='radius'` | `rechargeUser(0,'radius',…)` `:242` | voucher value | voucher status | **YES** | WP4/WP5B: authenticated NAS channel + orchestrator |
| E16 | `radius.php:110-123` authenticate | same | voucher **exists** (used or not) | 204 accept | none | n/a | auth-level | WP4 |
| E17 | `home.php:151-210` customer self-extend | Customer | config `extend_expired`, monthly cache file | device `add_customer` `:185` | none (free) | file-based | **YES** | fail closed for reseller-owned |
| E18 | `home.php:19-82` balance transfer | Customer | `enable_balance` + `allow_balance_transfer` | `Balance::transfer` `:39` | moves customer balance; **no positive-amount check** (`:29-35`) | none | retail-domain only | keep out of wallet domain; add validation (SEC-15) |
| E19 | Sync re-provision: `plan.php:37-71` (S,A), `customers.php:274-305` (**all staff**), `home.php:101-131` (customer) | various | see cell | device `add_customer` | none | n/a | re-provision existing period | scope + RADIUS-safe provisioning (WP4) |
| E20 | `accounts.php:40-60` customer password change | Customer | `_auth` | device `add_customer` `:54` | none | n/a | re-provision (RADIUS: purges `radacct`) | WP4 |
| E21 | `customers.php:589-829` edit-post username change | **All staff** | none | `change_username` `:799` + `add_customer` `:813` | none | n/a | re-provision | scope (WP2) |
| E22 | `system/api.php` | API token holder | token | every row above via controller include | as rows | as rows | **YES** (multiplies E1–E21) | DataScope + orchestrator apply equally to API |
| E23 | Plugin / hook callers | installed plugins (external) | plugin load swallows errors; hooks can't veto | arbitrary, incl. `Package::*` | arbitrary | n/a | **YES** (unknown code) | core-level gates; plugins never authoritative (D019) |
| E24 | `update.php` (root) | any staff session | `$_SESSION['aid']` only | replaces code and runs SQL | n/a | n/a | n/a (SEC-03) | out of service flow; hardening |

**Explicit candidate bypass list (reseller-owned customers):**
- E1, E2, E3, E4, E6, E7, E8, E9, E10, E11, E12, E13, E15, E17, E22, E23.
- E5 is an admin override and needs an audited policy.
- E14, E16, E19, E20 and E21 re-provision without a new period, but must not be reachable cross-scope.

**Destructive / history-affecting paths**
- **`radacct` purge.** Every Radius-device `add_customer`/`sync_customer`, and `remove_customer` on plan_expired, runs `Radius.php:371-372`. That makes E1, E2, E4, E5, E7, E8, E9, E11, E12, E13, E14, E15 (when device=Radius), E17, E19, E20 and E21 all purge accounting history (DYNAMIC F1).
- **`tbl_transactions` delete.** `reports.php:262-266` (all staff) builds raw SQL from POST. It currently references a non-existent column, so the statement errors.
- **Retention deletes.** `logs.php:120,139,159` delete logs, radpostauth and message logs (S,A).
- **Restore.** `settings.php:1014-1057` (SuperAdmin) can TRUNCATE tables.
- **Customer delete.** `customers.php:418+` (S,A).

## 8. Finance behaviour (as-is)
**`Package::rechargeUser`** (`Package.php:21-476`)
- **Repeat-payment guard:** only a global `$trx` with status 2 (`:30-33`).
- **Disabled plan:** non-admin callers are redirected with `r2()`, which **exits**. Under cron this would abort the whole cron run (`:75-82`, `init.php` `r2`).
- **Expiry semantics:** same-plan extension requires an equal plan **name** (`namebp == name_plan`), `status=='on'` and `extend_expiry=='yes'` (`:198`). Otherwise `$isChangePlan` (`:225`) and the expiry is counted from now, losing any remaining time.
  - The settings UI shows "yes" when the key is unset (`miscellaneous.tpl:167` tests `!='no'`), but the code requires `=='yes'`.
  - Cron sets status `off` before auto-renew, so auto-renew always counts from now (DYNAMIC F4).
- **Device failure:** swallowed; the DB still records the plan active (DYNAMIC F2).
- **Writes:** `tbl_user_recharges`, `tbl_transactions` (price = plan price + bills, no tax; invoice id from `_raid()` = max id + 1, race-prone, with no unique index), invoice attributes, bills, then `recharge_user_finish` hook and notification.

**`Balance`.** Covered in BASELINE_VERIFICATION §5 #4.

**Concurrency.** There are no transactions or row locks in application PHP (grep for `beginTransaction|FOR UPDATE|LOCK TABLES|rollBack` = 0). Balance checks are check-then-act. Voucher redemption is unlocked.

**Spec gap.** There are no wallet, ledger, reservation, debt-limit, commission, wholesale-cost, pricing-snapshot, activation-state or idempotency structures (expected; WP1/WP3/WP5).

## 9. `tbl_plans.price` usage map (D009 evidence)
- **Column:** `tbl_plans.price` VARCHAR(40); `price_old` VARCHAR(40), the struck-through "old price" display.
- **Read sites:** 85 in PHP, by file: `Package.php` 28, `order.php` 25, `plan.php` 13, `services.php` 8 (writes), `cron.php` 8, `export.php` 4, `customers.php` 3, `Message.php` 3, `home.php` 2, `cron_reminder.php` 2. Plus 29 templates.

| Use | Where | Classification |
|---|---|---|
| Customer charge from balance | `order.php:239-248` (pay), `:296-334` (send), `cron.php:105-110` (auto-renew) | **retail / customer price** |
| Gateway charge amount | `order.php:646-690` (plus tax, minus posted `discount`) | **retail** |
| Balance top-up amount | `Package.php:487,504`, `plan.php:1007` | customer balance deposit value |
| Revenue record | `Package.php:296-301,414` → `tbl_transactions.price` | retail revenue (no tax) |
| Admin recharge confirmation | `plan.php:112-132,217-222`, `customers.php:181-204` | display of retail |
| Notifications / reminders | `cron.php:89`, `cron_reminder.php:45-50`, `Message.php:209-233` | retail |
| Catalogues | customer `orderPlan.tpl`, `selectGateway.tpl`, admin plan lists | retail |

**Conclusion.** `tbl_plans.price` is exclusively a customer/retail price. D009/D010 are consistent with the code. Wholesale cost needs new DECIMAL storage (WP1).

## 10. FreeRADIUS integration
**Provisioning (`system/devices/Radius.php`, SQL mode)**
- `radcheck`: Cleartext-Password, Simultaneous-Use, Port-Limit, Mikrotik-Wireless-Comment, Max-All-Session, Max-Data, Expiration, WISPr-Session-Terminate-Time.
- `radreply`: Framed-Pool, Framed-IP-Address (`0.0.0.0` when no static IP), Framed-IP-Netmask.
- `radusergroup`: `plan_<id>`.
- `radgroupreply`: Ascend-Data-Rate, Ascend-Xmit-Rate (both built from the download rate, `:169-170`) and Mikrotik-Rate-Limit, written by `add_plan`/`update_plan` when an admin saves a plan.
- Per-user `Mikrotik-Rate-Limit` is written by `addBandwidth` (`:327`) and immediately deleted for non-"Hotspot Limited" plans (`:364`).
- **Not written:** `radgroupcheck`, `nasreload`.
- `pppoe_username` is ignored as the RADIUS key.

**Stock FreeRADIUS compatibility.** Stock FreeRADIUS 3.2.5 **rejects** app-provisioned users, because `Max-All-Session` (a `radcheck` item set to the seconds until expiry, `:378`) is not in the stock dictionary.
- Defined only via `sqlcounter`'s `check_name` once that module is enabled. With it defined, authentication succeeds (DYNAMIC R1/R4).
- **So the production FreeRADIUS configuration is an unstated external dependency** (conflict C16).

**Accounting.** `radacct` vs `rad_acct`: see BASELINE_VERIFICATION §4.4 and §5 #17. There is no session-history UI, and `online_customer` is an empty stub for both RADIUS devices.

**CoA / Disconnect**
- `Radius::disconnectCustomer` (`:484-507`) finds one open session on the **default** connection (`:493`, wrong connection) and runs `@shell_exec("echo '…' | radclient -x <nas>:<port> disconnect '<secret>'")` (`:503`).
- Username, framed IP, NAS name and secret are interpolated **without escaping** (SEC-11).
- Only Disconnect-Request exists; there is no CoA-Request.

**rlm_rest endpoint (`radius.php`)**
- No authentication.
- Request values are interpolated into SQL.
- It grants service for vouchers (E15, E16).
- It writes local `rad_acct` with additive octets and never stores Interim-Update.

## 11. MikroTik integration
**Devices** (`system/devices/`): MikrotikHotspot, MikrotikPppoe, MikrotikVpn, Radius, RadiusRest, Dummy. Selection is via `Package::getDevice()`.

**Known defects**
- Hotspot `change_username` never renames (DYNAMIC F3).
- `sync_customer` is never used: callers test `method_exists($dvc,…)` where `$dvc` is a **file path** (`customers.php:292`, `plan.php:54`, `home.php:112`).
- Dummy `change_username` has the wrong signature.
- The demo-mode check is case-inconsistent (`'Demo'` vs `'demo'`).

**Failure handling.** The PEAR2 RouterOS client does not throw on `!trap`, and callers never check responses. There is no explicit timeout (the `default_socket_timeout` default applies). Errors are swallowed in `rechargeUser` (F2).

**Credentials.** Router credentials are plaintext in `tbl_routers` and rendered back into the edit form. Routers are resolved by **name**.

**Spec alignment.** 07_MIKROTIK_CONTRACT (API private, FreeRADIUS-first, no split-brain) is compatible with retaining these drivers as legacy/fallback. Each retained method needs a contract test (the F3 stub pattern).

## 12. Credential and secret handling inventory (no values recorded)
| Secret | Storage | Exposure |
|---|---|---|
| DB credentials | `config.php` (gitignored) | also the default `api_secret` and the HMAC key for staff/customer cookies (`Admin.php:50-51`, `User.php`) and the public invoice signature (`plan.php:283`, `voucher.php:16`) |
| Staff passwords | `tbl_users.password`, **unsalted SHA-1** (`Password.php:11-13`) | — |
| Default SuperAdmin | `install/phpnuxbill.sql` seeds `admin` with a well-known default password | no forced change found |
| Customer passwords | **plaintext** `tbl_customers.password` / `pppoe_password` | needed for Cleartext-Password/CHAP; emailed on creation; overwritten by voucher code in E13 |
| Router API credentials | plaintext `tbl_routers.password` | rendered into `routers/edit.tpl` |
| RADIUS NAS secrets | plaintext `nas.secret` | rendered into `radius/nas-edit.tpl`; passed on a shell command line (SEC-11) |
| API key | `tbl_appconfig.api_key` | rendered in `settings/app.tpl`; equality check grants admin context |
| Gateway / SMS / WA / Telegram tokens | `tbl_appconfig` | admin settings pages |
| Errors | `boot.php:141-168` | uncaught exceptions sent to Telegram and rendered with trace on `admin/error.tpl`; `html_php` widgets echo traces |

## 13. Pre-existing security findings register (owner decision C9: verify, document, do not fix)
Severity uses impact × reachability.
- **Trust boundaries:** Internet→App (unauthenticated), Customer→App, LowStaff→Admin (Agent/Sales/Report reaching admin powers), Staff→Server, NAS↔App.
- **Verification:** all items are **STATIC** (code evidence); none were exploited, per the owner rule.
- **Columns:** "Prod" = must be closed or explicitly risk-accepted before any production use. "Blocks" = later WP that cannot be accepted while it remains open.

| ID | Finding | Evidence | Boundary | Severity | Earliest WP | Prod | Blocks |
|---|---|---|---|---|---|---|---|
| SEC-01 | Unauthenticated rlm_rest endpoint; request values interpolated into SQL; grants service from vouchers | `radius.php:19-21,45-334` | Internet/NAS→App | **Critical** | WP4 | yes | WP4, WP5B |
| SEC-02 | Unauthenticated `plugin/{fn}` calls any zero-argument global function | `plugin.php:7-8` | Internet→App | **High** | WP2 | yes | WP2 |
| SEC-03 | Web updater usable by any staff role; update source URL taken from the request; replaces code | `update.php:20-29,52-98` | LowStaff→Server | **Critical** | WP2 | yes | WP2 |
| SEC-04 | Privilege escalation: `user_type` stored from POST without whitelist (create and edit) | `settings.php:679,710,861-862` | LowStaff→Admin | **Critical** | WP2 | yes | WP2 |
| SEC-05 | Unauthenticated customer search/enumeration | `search_user.php:1-20` | Internet→App | Medium | WP2 | yes | WP2 |
| SEC-06 | No object-level scoping on customers, plans, billing, maps, AJAX, reports, export (§5) | §5 | LowStaff→Admin data | **High** | WP2 (+WP5B for service grants) | yes | WP2 |
| SEC-07 | Ungated mutating actions reachable by all staff incl. Report (§2) | §2 | LowStaff→Admin | **High** | WP2 | yes | WP2 |
| SEC-08 | Staff can grant free service/value: `using=zero`, arbitrary deposit, free vouchers, extend | E1, E3, E4, E6 | LowStaff→Finance | **High** | WP5B (gate), WP2 (roles) | yes | WP5B |
| SEC-09 | CSRF off by default (`csrf_enabled` absent on fresh install) and missing in many controllers; bypassed for API | `Csrf.php:25-40`; §2 | Internet→Staff session | **High** | WP2 | yes | WP2 |
| SEC-10 | Disabled (Inactive) staff keep login and sessions | `admin.php:23-38`, `Admin.php` (no `status`) | LowStaff | **High** | WP2 | yes | WP2 |
| SEC-11 | CoA disconnect via `shell_exec` with unescaped interpolation (username, IP, NAS name, secret) | `Radius.php:503` | Staff→Server | **High** | WP4 | yes | WP4 |
| SEC-12 | Raw SQL built from POST in the transaction-delete branch; reachable by all staff | `reports.php:262-266` | LowStaff→DB | **High** | WP2 | yes | WP2 |
| SEC-13 | Payment gateway filename taken from POST and `include`d without allowlist | `order.php:540,558` | Customer→Server | **High** | WP5B | yes | WP5B |
| SEC-14 | Client-supplied `discount` reduces the gateway charge | `order.php:541,662-690` | Customer→Finance | **High** | WP5B | yes | WP5B |
| SEC-15 | Customer balance transfer lacks a positive-amount check; `Balance::min` never refuses | `home.php:29-39`, `Balance.php:30-36` | Customer→Finance | Medium (config-dependent) | WP5B | yes if enabled | WP5B |
| SEC-16 | Voucher + username activation overwrites an existing customer's password without ownership proof or CSRF | `login.php:175-210` | Internet→Customer account | **High** | WP5B | yes | WP5B |
| SEC-17 | Weak credential storage: unsalted SHA-1 staff hashes; plaintext router/NAS secrets rendered in forms; DB password reused as HMAC/API secret | §12 | multiple | Medium–High | WP2 (staff auth), WP4 (NAS) | yes | — |
| SEC-18 | Default SuperAdmin with a well-known password seeded by the installer | `install/phpnuxbill.sql:425-446` | Internet→Admin | **High** (deployment) | WP10 runbook / WP2 | yes | — |
| SEC-19 | Installer stays reachable after install (`install/` not removed by the installer; step4 rewrites `config.php` from POST with no "already installed" guard) | `install/step4.php:9-13,86-90`, `step5.php:17,69` | Internet→Server | **Critical** (deployment) | WP2 / WP10 runbook | yes | — |
| SEC-20 | `.htaccess_firewall` inactive by default, so every PHP file is web-reachable (cron, api, installer) | file name | Internet→App | Medium | WP10 runbook / WP8 | yes | — |
| SEC-21 | API: every controller reachable with tokens; CSRF bypass; `api_key` equality path | `system/api.php:76-142` | Internet→App | Medium | WP2 | yes | WP2 |
| SEC-22 | Admin `html_php` widgets `eval` stored content and echo traces (Admin-trusted feature) | `system/widgets/html_php.php:12` (+2) | Admin→Server | Low (by design) | WP9 review | decide | — |
| SEC-23 | Non-CSPRNG voucher codes; no unique index; unlocked redemption race | `plan.php:766`, `init.php:304` | Internet→Finance | Medium | WP5B | yes | WP5B |
| SEC-24 | No DB transactions/locks around money or service (check-then-act) | §8 | Concurrency | **High** (finance) | WP3A/WP5A | yes | WP3A |
| SEC-25 | `Lang::T` makes outbound translation calls and writes language files at runtime | `Lang.php:37,223-243` | App→External | Low | WP6A | no | — |

**Must block production:** every Critical and High item above (independent of the reseller features).

**Must be in scope before the named WP can be accepted:**
- WP2: SEC-02, 03, 04, 05, 06, 07, 09, 10, 12, 21.
- WP4: SEC-01, 11.
- WP5B: SEC-08, 13, 14, 15, 16, 23.
- WP3A: SEC-24.

Expanding those WPs' scope is an owner decision (conflict C9b).

## 14. UI, RTL, responsive and PWA assessment
**Stack**
- Smarty 4.5.3 server rendering with **no auto-escaping** (`escape_html` never set).
- Template resolution `ui_custom` → theme → `ui/ui` (`boot.php:22-53`), plus plugin/gateway template dirs.
- AdminLTE 2.x on Bootstrap 3.4.1 and jQuery 1.11.1; Chart.js from a CDN.
- No asset build tooling.

**RTL**
- None. No `dir` attribute anywhere; the customer header hard-codes `lang="en"`.
- `system/lan/arabic.json` exists (717 keys vs 457 English) and the language is chosen via session → cookie → user attribute → appconfig (`init.php:155-176`).
- Technical tokens (IP, MAC, username) have no LTR isolation.

**Responsive**
- A viewport meta tag exists on all full pages; four templates disable zoom (`maximum-scale=1` / `user-scalable=no`).
- No mobile-specific layouts. Public login pages show no horizontal overflow at 390/834/1440 px (DYNAMIC U1).
- Wide admin tables are not converted to cards.

**PWA**
- No manifest, service worker, `theme-color` or touch icons.
- Feasibility constraints:
  - query-string routing (`?_route=`), which works for deep links;
  - a service worker must be served from the web root to control `/` (root-level file or a route returning JS with the `Service-Worker-Allowed` header);
  - cookie sessions (`aid`/`uid`) with 7/30-day lifetimes;
  - HTTPS is required in production;
  - there is no per-user cache key today, so D031 isolation must be designed in.

**Recommendation (smallest D034-compliant path; no implementation in WP0)**
1. Keep Smarty. Add a new adaptive layout (`ui/ui_custom` or a dedicated theme directory) with an RTL stylesheet (Bootstrap 3 RTL overrides) and `dir`/`lang` from the active language. Wrap technical tokens with `<bdi dir="ltr">`.
2. Progressive JavaScript only (vanilla, or the existing jQuery). No SPA (D034).
3. Add a root-served `manifest.webmanifest` and `sw.js`:
   - app-shell cache only;
   - a network-only policy for every `?_route=` mutation and for authentication;
   - per-user cache names derived from a server-issued, non-secret cache key;
   - caches cleared on `logout`.
4. Enable Smarty auto-escaping for new templates only, to avoid regressions in legacy templates.

## 15. Spec ↔ repository conflicts (unresolved unless marked)
| # | Conflict | Evidence | Impact | Options | Recommendation |
|---|---|---|---|---|---|
| C1 | WP0 prompt: "checkout exact baseline d3e0596 on hotspotbilling/phpnuxbill"; the execution base was `500d3a2` on the fork | BASELINE §1 | none on code | (a) accept 500d3a2 as BASELINE_EQUIVALENT; (b) re-run on detached d3e0596 | (a) — app tree proven identical |
| C2 | Branch naming | — | — | — | **Owner-resolved**: `wp0/reconnaissance` |
| C3 | Push / PR policy | — | — | — | **Owner-resolved**: push, no PR |
| C4 | `.gitignore` ignores `docs/**` except top-level `docs/*.md`; the spec pack itself is force-added | `.gitignore:53-55` | later WPs adding docs in subfolders | (a) keep top-level docs; (b) add a narrow `.gitignore` negation in a later WP | **Owner-resolved for WP0** (top-level). For later WPs, (b) needs owner approval. |
| C5 | Runtime PHP | BASELINE §2.1 | Dockerfile still 7.4 | — | **Owner-resolved: PHP 8.2.** Dockerfile change is WP1+ with approval |
| C6 | Payment gateways and plugins are external, so the inventory is contract-level only | `.gitignore` | unknown plugin code can grant service | (a) gate at core call sites; (b) audit specific installed plugins per deployment | (a) in WP5B, plus (b) as a deployment checklist item in WP8/WP10 |
| C7 | Finding 3 understated: there is **no** scoping at all, and Report can mutate | §3–§5 | WP2 must scope legacy routes too, not only new-domain routes | (a) WP2 scopes all legacy customer/plan/report routes; (b) WP2 hides legacy routes from Agent/Sales and builds new scoped screens; (c) both | (c): fail closed on legacy routes for Agent/Sales/Report, and provide new scoped routes |
| C8 | D027 says reseller voucher generation is deferred; it already exists for Agent/Sales, free | §6 | uncharged prepaid service today | (a) disable Agent/Sales generation in WP2 (role gate); (b) wait for WP5B/WP6; (c) keep but scope by `generated_by` | (a) earliest: a pure permission change, consistent with D027. No voucher schema in WP1. |
| C9 | Pre-existing security defects | §13 | production blockers | — | **Owner-resolved for WP0**: verify and document only. **C9b (open):** confirm the proposed WP mapping in §13, or create a dedicated hardening WP before WP2. |
| C10 | Service-granting paths not named in the spec: E4, E5, E13–E17, E19–E21 | §7 | WP5B scope | include all of §7 in WP5B | include |
| C11 | Hooks cannot veto (`run_hook` ignores return values) | `Hookers.php:48-58` | WP5B cannot gate via hooks | core call-site gates | core gates (consistent with D019) |
| C12 | `tbl_plans.price` (and transaction/payment prices) are VARCHAR | BASELINE §4.4 | arithmetic and format risk | new DECIMAL wholesale storage; leave legacy untouched | new DECIMAL storage in WP1 (D009); parse legacy in WP3B |
| C13 | No schema-version tracking; error-swallowing updater; fresh ≠ upgraded schemas | BASELINE §4 | WP1 migration may hit an unknown base | (a) WP1 targets "install SQL + all updates.json" and asserts preconditions per column; (b) WP1 first normalizes drift | (a); drift resolution stays out of WP1 unless the owner approves |
| C14 | Renewal expiry semantics: name-based match, `extend_expiry` UI/code default mismatch, remaining time lost on change, cron always from now | §8 | 18_BUSINESS_RULES "Renew … approved PHPNuxBill semantics" | (a) adopt as-is; (b) define V1 semantics in ActivationService (extend from current expiry for same plan id; change-plan from now) | (b), with owner sign-off on exact rules |
| C15 | No DB transactions or locks | §8 | WP3A prerequisite | InnoDB is present; row locks are feasible | WP3A uses transactions + `SELECT … FOR UPDATE` |
| C16 | Stock FreeRADIUS rejects app-provisioned users (`Max-All-Session` unknown); app depends on undocumented server configuration | R1/R4 | WP4/WP8 cannot assume stock config | (a) WP4 documents and tests the required FreeRADIUS config (sqlcounter/dictionary); (b) stop writing `Max-All-Session` and rely on Expiration/Session-Timeout | decide in WP4; (a) minimum |
| C17 | `radacct` purge also triggered by sync, password change, username change, edit/extend, voucher paths (not only plan update) | §7 | WP4 scope | all `add_customer`-based paths must stop purging | WP4 replaces `customerAddPlan` purge globally |
| C18 | PHP timezone (default Asia/Jakarta) vs DB time (UTC) differ; expiry is computed in PHP | F4 | expiry/reporting skew | single configured timezone per deployment | WP8 runbook + WP5A tests |
| C19 | `tbl_users.id` INT UNSIGNED while all user references are signed INT; no FKs exist | BASELINE §4.4 | WP1 FK design | (a) new FK columns INT UNSIGNED with real FKs; (b) match the legacy signed refs without FKs | (a) for new tables; legacy columns untouched |
| C20 | Existing `root` relationships (Agent→Sales and Agent→Agent) vs the new closure tree | §3 | WP1/WP2 migration | (a) seed the closure from `root` via an audited import; (b) start empty, admin assigns | (a) only via an explicit owner-approved import (D004/D007 spirit) |
| C21 | Existing Sales currently see all customers; the spec limits them to assigned customers | §3 | behaviour change for current deployments | accept the change | accept (spec intent) |
| C22 | Installer artefacts and default credentials | SEC-18/19/20 | deployment safety | WP10 runbook items | include in WP8/WP10 |
| C23 | `tbl_odps` is latin1 while all other tables are utf8mb4 | BASELINE §4.2 | Arabic text in ODP names | convert in a later WP | out of scope for WP1 unless the owner wants it |

## 16. Module gap → work-package map (MODULES.md)
| Spec module | Baseline today | Gap | WP |
|---|---|---|---|
| ActorContext / PermissionService / DataScope | role checks scattered per `case`; no scope | build + retrofit legacy routes (C7) | WP2 |
| ResellerTreeService | `tbl_users.root` one level | closure table + cycle prevention | WP1 schema, WP2 service |
| Customer ownership / Sales assignment | `created_by` only (unused) | new owner/assignment fields; unassigned = admin-only | WP1, WP2 |
| WalletService / LedgerService / reservations | none (customer `Balance` only) | everything | WP1, WP3A |
| PricingService / commission / settlement | `tbl_plans.price` retail only | wholesale storage + chain | WP1, WP3B |
| ActivationService / ReconciliationService | `Package::rechargeUser` (non-atomic, swallows failures) | state machine, idempotency | WP1, WP5A |
| Legacy entrypoint gates | none | §7 rows | WP5B |
| RadiusRepository / PolicyBuilder / Provisioner / AccountingService | `Radius.php` monolith; purges `radacct` | separation, no purge, contract tests | WP4 |
| CoAClient | `shell_exec radclient` unescaped | hardened client | WP4 |
| MikrotikNasService | legacy device classes | contract tests; diagnostics only | WP4/WP8 |
| Adaptive UI / RTL | AdminLTE LTR | new layout, RTL, Subscriber 360 | WP6A/6B |
| PWA | none | manifest, SW, cache hygiene | WP7 |
| Tests / CI | none | PHPUnit + fixtures + lint CI | WP1 onward |

## 17. WP1 prerequisites and blockers
Nothing **blocks WP0 completion**. WP1 cannot start until the owner:
1. **Accepts WP0** and records the accepted HEAD in `review/ACCEPTANCE_LEDGER.md`.
2. **Decides C13:** the WP1 base schema definition and a strict migration runner (not `update.php`).
3. **Decides C19:** FK and signedness policy for new reference columns (`tbl_users.id` is INT UNSIGNED).
4. **Approves names and types:**
   - commercial-owner and Sales-assignment column names (`manager_id` / `assigned_sales_id` or equivalents);
   - the DECIMAL precision for money (IQD), e.g. DECIMAL(15,2) to match `tbl_customers.balance`, or higher.
5. **Approves the test environment change:** PHPUnit as a dev-only dependency, plus PHP 8.2 Docker/compose files. This is a dependency change, forbidden without approval.
6. **Decides C20:** whether an initial closure-tree import from `tbl_users.root` is wanted, as a separate audited operation, not in the WP1 migration.
7. **Confirms D027 and C8:** no voucher schema in WP1 (recommended; `generated_by` suffices for interim scoping).

Decisions C7, C9b, C14, C16 and C17 affect WP2, WP4 and WP5. They do not block WP1's schema-only scope.
