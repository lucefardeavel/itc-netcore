# WP0 Baseline Verification

Work Package: **WP0 — Repository Reconnaissance & Baseline Verification**
Status: `IMPLEMENTED_NOT_ACCEPTED` (waiting for owner review)
Date: 2026-10-01
Companion documents: [`GAP_ANALYSIS.md`](GAP_ANALYSIS.md), [`../owner-review/WP0/REPORT.md`](../owner-review/WP0/REPORT.md)

This document records what the pinned baseline is and how it actually behaves. It classifies the 18 reference findings from `docs/claude-development-pack/docs/17_REFERENCE_FINDINGS.md`. Nothing in the application was changed.

**Evidence labels**
- `STATIC`: established by reading code at the cited `file:line`.
- `DYNAMIC`: reproduced in the disposable WP0 sandbox (§3) by running the application's own code.

Security-sensitive findings were classified from static evidence only. No exploit payloads or attacker-style requests were run (owner rule: "minimum safe proof").

---

## 1. Repository identity and ancestry

| Item | Verified value |
|---|---|
| Repository | `lucefardeavel/phpnuxbill-reseller` (private), remote `origin` |
| Upstream baseline (pack) | `d3e0596287322944ee21fcc14dc1798158463140` ("Merge pull request #452 from ctranggono/master", 2026-09-29) |
| Starting HEAD | `500d3a2d15003efeed6e0aad639c13d08101c3b1` = `d3e0596` + one docs-only commit (spec pack v1.1) |
| `origin/feat/reseller-system-v1` | `500d3a2…` (also `origin/main`) |
| WP0 branch | `wp0/reconnaissance`, created from `origin/feat/reseller-system-v1` at `500d3a2`; upstream tracking removed to prevent an accidental push to the feature branch |
| `git merge-base --is-ancestor d3e0596 HEAD` | true |
| App tree vs baseline | `git diff --name-only d3e0596 HEAD -- . ':!docs/claude-development-pack'` → **0 files**. The application tree is byte-identical to the upstream baseline. |
| Diff baseline → 500d3a2 | 44 files, +2291 lines, all under `docs/claude-development-pack/` |
| Spec pack integrity | `sha256sum -c MANIFEST.sha256` → 43/43 OK |
| Worktree at start | clean |
| Clone depth | Initially shallow (99 commits). Deepened from `origin` (local `.git` only) to 2349 commits for licensing history. |

**Execution-base note (conflict C1, GAP_ANALYSIS §15).** The WP0 prompt says to "checkout/pin exact baseline commit d3e0596". WP0 ran on `500d3a2` as the owner instructed. Because git commit ids are content-addressed, `d3e0596` being the parent proves the baseline tree. The diff above proves the application files are unchanged.

`hotspotbilling/phpnuxbill` itself was **not** consulted, because it is outside this session's repository scope.

---

## 2. Runtime and dependencies

### 2.1 PHP runtime (owner decision C5: target PHP 8.2)
| Source | Statement |
|---|---|
| `Dockerfile:2` | `FROM php:7.4-apache` (EOL runtime) |
| `README.md` "System Requirements" | "Minimum PHP Version 8.2" |
| `system/composer.lock`, mpdf/mpdf 8.1.6 | `php: ^5.6 \|\| ^7.0 \|\| ~8.0.0 \|\| ~8.1.0 \|\| ~8.2.0` (**excludes 8.3+**) |
| `docker-compose.example.yml` | MySQL 8.0 |
| Sandbox | PHP **8.2.34** (official `php:8.2-cli` image + `pdo_mysql`, `mysqli`); host PHP 8.3.6 used for comparison/lint only |

**Results**

| Check | PHP 8.2.34 | PHP 8.3.6 |
|---|---|---|
| `php -l` on all 200 tracked non-vendor PHP files | 200 pass, 0 fail | 200 pass, 0 fail |
| `composer check-platform-reqs --lock -d system` | `php` **success**. `ext-gd` reported missing only because the sandbox image could not build gd offline (Debian mirrors blocked). The repo Dockerfile installs gd. | `php` **failed**: mpdf 8.1.6 requires < 8.3 |
| `composer validate` (root) | n/a | valid |
| Boot / page smoke (`admin`, `login`, `register`, `login&do=forgot`) | HTTP 200 for all; no PHP errors logged | not run as server |
| Business code paths executed (§3) | recharge, RADIUS provisioning, cron auto-renew all ran without PHP errors | — |

**Conclusions**
- PHP 8.2 is compatible with the locked dependency set.
- PHP 8.3 is **not** within mpdf 8.1.6's declared constraint. Moving to 8.3 later would need a dependency upgrade, which is out of scope for WP0.
- The repo `Dockerfile` (PHP 7.4) contradicts the 8.2 target and needs a WP1+ owner-approved change.

**Other runtime observations**
- `system/cron.php:3` does `include "../init.php"`, so cron must run with cwd = `system/`. Running from the web root fails (observed).
- The default timezone is `Asia/Jakarta` (install SQL appconfig) while MySQL was UTC. Cron logged `PHP Time 14:08` vs `MYSQL Time 07:08`. Expiry dates are computed in PHP time.
- Version metadata is inconsistent:
  - `version.json` = `2025.3.20`;
  - the newest `CHANGELOG.md` entry = `2024.10.23`;
  - the newest `system/updates.json` key = `2025.11.17`.

### 2.2 Dependencies (no changes made)
| Component | Version | License (declared) | Location |
|---|---|---|---|
| smarty/smarty | 4.5.3 (pinned `=4.5.3`) | LGPL-3.0 | `system/vendor/smarty` |
| mpdf/mpdf | 8.1.6 | GPL-2.0-only (no LICENSE file in vendor dir) | `system/vendor/mpdf` |
| setasign/fpdi | 2.3.7 | MIT | vendor |
| myclabs/deep-copy | 1.11.1 | MIT | vendor |
| psr/log, psr/http-message, paragonie/random_compat, mpdf/psr-log-aware-trait | — | MIT | vendor |
| yosiazwan/php-facedetection | 0.1.0 | GPL-2.0 | vendor |
| PEAR2 Net_RouterOS | 1.0.0b6 | LGPL-2.1 | `system/autoload/PEAR2` |
| PHPMailer | — | LGPL | `system/autoload/mail` |
| Idiorm ORM | — | BSD | `system/orm.php` |
| Parsedown | — | refers to an unshipped LICENSE file | `system/autoload/Parsedown.php` |
| phpqrcode | — | LGPL-3.0-or-later (`qrcode/LICENSE`, `qrcode/qrlib.php:15-16`) | `qrcode/` |
| UI: Bootstrap 3.4.1, jQuery 1.11.1, AdminLTE 2.x, SweetAlert2, Select2, Summernote, Font Awesome 4.3, Chart.js (CDN) | — | MIT / OFL | `ui/ui/scripts`, `ui/ui/styles` |

**Tests and CI:** there are none. No phpunit config, no tests, no `.github/workflows` (only `FUNDING.yml` and issue templates), and no linters or static analysis.

---

## 3. Disposable WP0 sandbox (not committed)
Everything below existed only inside this ephemeral container (scratchpad). No external MikroTik, production RADIUS, production database, real credentials or external network target was used.

| Component | How | Notes |
|---|---|---|
| Docker daemon | started inside the container | used only to run the official `php:8.2-cli` image |
| PHP 8.2.34 | `php:8.2-cli` + `docker-php-ext-install pdo_mysql mysqli` | gd/zip not built (Debian mirrors blocked); does not affect tested paths |
| MariaDB 10.11.14 | Ubuntu apt package, bound to 127.0.0.1 | random throwaway credentials; default engine InnoDB, strict SQL mode |
| FreeRADIUS 3.2.5 | Ubuntu apt package; `sql` module pointed at the throwaway DB; IPv6 listeners replaced by 127.0.0.1-only listeners (container has no IPv6) | `radtest` against 127.0.0.1 only |
| App copy | `git archive HEAD` extracted to the scratchpad; sandbox-only `config.php` there | the repository working tree was never used as a web root |
| `radclient` | replaced, inside the PHP container only, by a stub that **logs** argv/stdin and sends nothing | no CoA/Disconnect packet was emitted |
| Router `r1` / NAS | `127.0.0.1:9` / `127.0.0.1` (nothing listening) | proves failure handling without touching any device |
| Seed | SuperAdmin, Admin, Report, Agent A (+Sales A1, child Agent B), Agent C (+Sales C1), Inactive Agent; 4 customers; Dummy/Radius/Hotspot plans; 3 fake `radacct` rows; 1 voucher | random sandbox passwords |

### 3.1 Dynamic checks performed
| ID | What was run | Result |
|---|---|---|
| F1 | `Package::rechargeUser(104,'radius',2,…)` (Admin recharge of a RADIUS-plan customer) via a CLI harness that bootstraps `init.php` | **All 3 `radacct` rows for the user deleted** (2 closed + 1 open). `radcheck` got Cleartext-Password, Simultaneous-Use, Port-Limit, Mikrotik-Wireless-Comment, Max-All-Session, Expiration, WISPr-Session-Terminate-Time. `radreply` got Framed-Pool (empty), Framed-IP-Address `0.0.0.0`, Framed-IP-Netmask. `radusergroup` = `plan_2`. Recharge `on` + 1 transaction. Disconnect stub invoked once. |
| F2 | `rechargeUser(101,'r1',3,…)` with the Hotspot plan on unreachable router `r1` | Router connection failed, yet the recharge was saved `status=on` and transaction `INV-2` written; no error surfaced to the caller |
| F3 | `change_username()` on MikrotikHotspot vs MikrotikPppoe, with a stub RouterOS client | Hotspot sent only `/ip/hotspot/user/print` (**no `set`**). PPPoE sent `print`, `set` and active-session removal. |
| F4 | `php cron.php` (cwd `system/`) with an expired auto-renew customer (`created_by` = Agent C) on the Dummy plan | Auto-renew succeeded: customer balance 5000 → 4000 (`tbl_plans.price`=1000). New period counted from today (2026-10-01→2026-10-31). Transaction `INV-3` method `Customer - Balance`, `admin_id=0`. No reseller-domain involvement exists. |
| R1–R3 | `radtest` (PAP) on stock FreeRADIUS 3.2.5 for the F1-provisioned user, wrong password, unprovisioned user | **All Access-Reject.** For the provisioned user, the server logged `sql: ERROR: Failed to create the pair: Unknown name "Max-All-Session"` → "Error getting check attributes". |
| R4–R5 | Same after defining `Max-All-Session` in the sandbox's local dictionary | Correct password → **Access-Accept** (Framed-IP-Address 0.0.0.0, Framed-IP-Netmask, Session-Timeout from the `expiration` module). Wrong password → Access-Reject. |
| U1 | Playwright/Chromium screenshots of `admin` and `login` at 1440/834/390 px | No horizontal overflow; `<html>` has no `dir`/`lang` on these pages. Authenticated screens assessed statically only. |
| S1 | Schema variants (§4) built and compared | see §4 |

---

## 4. Schema and migration verification

### 4.1 Schema-changing mechanisms found
| Mechanism | Location | Behaviour |
|---|---|---|
| Fresh install | `install/step4.php:89-96` | Executes `install/phpnuxbill.sql`. Executes `install/radius.sql` **only** if the "radius" option is ticked, and then into the **same database** (`$radius_*` = `$db_*`, `step4.php:46-49`). Writes `config.php` from POSTed values. Does **not** create `updates.done.json`. |
| Web updater | `update.php:106-133` | Applies every `system/updates.json` version not listed in `system/cache/updates.done.json` (gitignored cache file). **`catch (PDOException) { //ignore }` (`update.php:125-127`)**. Each version is marked done even when its statements failed. No transaction. |
| Legacy one-shot updater | `install/update.php:26-99` | Old manual DDL script (single try; aborts on first failure) |
| Runtime destructive SQL | `logs.php:120,139,159`, `reports.php:264`, `settings.php:1022-1057` (SuperAdmin restore/TRUNCATE) | not schema-changing, but raw SQL |
| FreeRADIUS server schema | not in repo; FreeRADIUS writes `radacct` through `rlm_sql` | compared against the official 3.2.5 schema below |

There is **no schema-version table**. Migration state lives only in a gitignored cache file.

### 4.2 Variants built in the sandbox
| DB | Content | Tables / columns |
|---|---|---|
| `nb_fresh` | current `install/phpnuxbill.sql` only (fresh install) | 21 / 231 |
| `nb_upgraded` | fresh install + first `update.php` run (all 60 versions, 91 statements) | 21 / 232 |
| `nb_legacy` | `install/phpnuxbill.sql` as of `56cea36a` (2023-08-09, when `updates.json` was introduced) + all current updates (a long-lived upgraded install) | 22 / 238 |
| `radius_app` | `install/radius.sql` | 9 / 72 |
| `radius_fr` | official FreeRADIUS 3.2.5 `mods-config/sql/main/mysql/schema.sql` | 9 / 70 |

All tables are InnoDB. `tbl_odps` is `latin1_swedish_ci`; all other app tables are `utf8mb4_general_ci`.

### 4.3 Migration failures silently ignored by `update.php`
- **Fresh install + update run:** 35 statements succeeded and **56 failed** (47 duplicate column, 2 table exists, 1 duplicate PK, 4 duplicate seed rows, 1 unknown table, **1 missing table**). The updater ignores all of them.
- **Long-lived install:** 87 succeeded, 4 failed.
- **`2023.10.24`, `ALTER TABLE nas ADD routers …`:** fails with *table doesn't exist* whenever the RADIUS tables are not in the main database. That covers installs without the radius option, or with a separate RADIUS DB. The updater only ever touches the main DB, so `nas.routers` then never exists.

### 4.4 Drift findings (no drift resolved in WP0)
**Fresh install ≠ upgraded install.** A fresh install that never runs `update.php` lacks:
- `tbl_plans.allow_purchase`;
- the `VPN` enum value in `tbl_plans.type`, `tbl_transactions.type` and `tbl_customers.service_type`.

**Upgraded vs long-lived install.** The long-lived install differs in several ways:
- It keeps the orphan table `tbl_message`.
- Integer display widths differ (int(10) vs int(11)). The type is the same and both are signed.
- `tbl_payment_gateway.payment_method/payment_channel` is varchar(64) vs varchar(32).
- Several default values differ: `tbl_customers.balance` and `tbl_user_recharges.method` have no default.
- `rad_acct.acctsessiontime` is bigint(12).
- Under strict SQL mode, columns with no default can make INSERTs that rely on defaults fail on old installs.

**Reference-type signedness (matters for WP1 foreign keys).**
- `tbl_users.id` is **INT UNSIGNED**.
- Every column that references a user is **signed INT**: `tbl_users.root`, `tbl_customers.created_by`, `tbl_voucher.generated_by`, `tbl_transactions.admin_id`, `tbl_user_recharges.admin_id`.
- `tbl_customers.id`, `tbl_plans.id` and `tbl_voucher.id` are signed INT.
- Only one foreign key exists anywhere (`tbl_customers_fields`, legacy install only).

**Money columns.**
- `tbl_plans.price`, `tbl_plans.price_old`, `tbl_transactions.price` and `tbl_payment_gateway.price` are **VARCHAR(40)**.
- Only `tbl_customers.balance` is DECIMAL(15,2).

**Constraints.** There is no unique index on voucher code, customer username or transaction invoice. Only PKs exist, plus one index on `tbl_customers_fields.customer_id`.

**App RADIUS schema vs FreeRADIUS.** `install/radius.sql` = the official FreeRADIUS 3.2.5 schema plus two app columns:
- `nas.routers` varchar(32);
- `radgroupreply.plan_id` int.

All indexes are identical.

**Application-local vs FreeRADIUS accounting.**
- Local `rad_acct` (app DB, default connection) has 15 columns: id, acctsessionid, username, realm, nasid, nasipaddress, nasportid, nasporttype, framedipaddress, acctsessiontime, acctinputoctets, acctoutputoctets, acctstatustype, macaddr, dateAdded.
  - It has no start/stop/update times and no unique session id.
  - It is upserted per username+MAC+NAS (`radius.php:276-281`). It is a **counter-state table, not a session history**.
- FreeRADIUS `radacct` ('radius' connection) has 29 columns, with `acctuniqueid` UNIQUE, start/update/stop times and terminate cause. It is the **session history**.

**Not drift.** An exploration pass claimed fresh installs lack `tbl_customers_meta`. That is **NOT_REPRODUCED**: the table is created in `2023.8.9` and deliberately dropped in `2024.2.20.1`.

---

## 5. Reference findings (17_REFERENCE_FINDINGS.md)
| # | Finding | Classification | Evidence | Method |
|---|---|---|---|---|
| 1 | Roles SuperAdmin/Admin/Report/Agent/Sales; `tbl_users.root` for sub-accounts | **CONFIRMED** | `install/phpnuxbill.sql` `tbl_users.user_type enum('SuperAdmin','Admin','Report','Agent','Sales')`, `root int` "for sub account"; sandbox schema identical | STATIC + schema |
| 2 | Agent handling around immediate children/Sales, not an arbitrary tree | **CONFIRMED** | User list filters `root = self` (`settings.php:479-521`); voucher scope = self + direct children (`plan.php:480-503`). An Agent **can** create Agents (`settings.php:718-720` forces `root = creator`), but no traversal beyond one level exists. | STATIC |
| 3 | Customer list/view need stronger centralized scope | **CONFIRMED (stronger)** | `created_by` is written once (`customers.php:514`) and **never used as a filter** anywhere. Customer list/view/edit, plan recharge, billing list, maps, AJAX helpers, reports and export have no Agent/Sales scoping at all. See GAP_ANALYSIS §5. | STATIC |
| 4 | Legacy `Balance` = customer-balance helpers, unsuitable as reseller wallet | **CONFIRMED** | `Balance.php`: `plus/min/transfer/plusByPhone/minByPhone` only mutate `tbl_customers.balance`. `min()` always returns true and can go negative (`:30-36`). `transfer()` is non-atomic (`:20-28`). No ledger, lock or transaction. F4 shows the balance debited by cron. | STATIC + DYNAMIC (F4) |
| 5 | `Package::rechargeUser()` continues recording after provisioning failure | **CONFIRMED** | Device call wrapped in `try { … } catch (Throwable)` that only notifies Telegram (`Package.php:229-257`, `345-373`). A missing device file creates an Exception that is never thrown (`:236`, `:352`). The DB write still follows. F2: unreachable router → recharge `on` + transaction. | STATIC + DYNAMIC (F2) |
| 6 | RADIUS plan update disconnects, then deletes accounting rows | **CONFIRMED (broader)** | `Radius.php:371-372` (`disconnectCustomer()` then `radacct … delete_many()`) inside `customerAddPlan`. Reached from `add_customer`, `sync_customer`, `remove_customer` (plan_expired). So **any recharge, sync, plan edit, extend, voucher activation or customer password change** (`accounts.php:54`) on a RADIUS plan purges the user's whole `radacct` history. F1: 3 → 0 rows. | STATIC + DYNAMIC (F1) |
| 7 | Plugin loading ignores plugin errors | **CONFIRMED** | `init.php:101-109`: `try { include $filename; } catch (Throwable) {/*ignore*/} catch (Exception) {/*ignore*/}` | STATIC |
| 8 | Plugin menus and custom UI/template paths exist | **CONFIRMED** | `register_menu` (`Hookers.php:23-36`); template dirs `ui_custom` → theme → `ui/ui` plus `pg` and `plugin` dirs (`boot.php:22-53`); menu build `boot.php:100-127` | STATIC |
| 9 | `Package::getDevice()` selects Radius / MikroTik PPPoE / Hotspot | **CONFIRMED** | `Package.php:728-753`. The plan `device` column wins (any `system/devices/*.php`, incl. Dummy, MikrotikVpn, RadiusRest). Legacy fallback `is_radius`→Radius, `PPPOE`→MikrotikPppoe, else MikrotikHotspot, written back to the plan. | STATIC |
| 10 | Hotspot username-change variable bug | **CONFIRMED** | `MikrotikHotspot.php:94-96`: `$id` fetched, `if (!empty($cid))` tested, so the rename never runs. F3 proves no `set` is sent (PPPoE correct). | STATIC + DYNAMIC (F3) |
| 11 | Updater catches PDO migration exceptions and continues | **CONFIRMED** | `update.php:123-127`. Sandbox: 56/91 statements failed silently on a fresh install, including the `nas.routers` migration that never applies when RADIUS tables live elsewhere (§4.3). | STATIC + DYNAMIC (S1) |
| 12 | Dockerfile PHP 7.4 vs README PHP 8.2 | **CONFIRMED** | `Dockerfile:2` vs README. In addition, mpdf 8.1.6 excludes PHP ≥ 8.3 (§2.1). | STATIC + composer check |
| 13 | `tbl_plans.price` used by customer order/plan/cron as retail price | **CONFIRMED** | 85 PHP read sites in 10 files, plus 29 templates (GAP_ANALYSIS §9). Used for customer charging (`order.php`, `cron.php:105-110`, `Package::rechargeBalance`), `tbl_transactions.price`, notifications and admin/customer display. **Never** a wholesale concept. The column is VARCHAR(40). F4 charged 1000 from it. | STATIC + DYNAMIC (F4) |
| 14 | Cron auto-renew calls `rechargeUser()` then `Balance::min()` | **CONFIRMED** | `cron.php:101-125`, specifically `:109` `rechargeUser(...,'Customer','Balance')` then `:110` `Balance::min`. F4 reproduced it for an Agent-created customer. | STATIC + DYNAMIC (F4) |
| 15 | Customer order/balance and voucher redemption call `rechargeUser()`; payment/API/plugin callers to be inventoried | **CONFIRMED** | `order.php:246,331`; `voucher.php:64`; `login.php:126,206`; `plan.php:958`; `radius.php:242`. Gateway callers live in **external** plugins (`callback.php:12-17`, `order.php:175-176,558-559,702`). API reaches every controller (`system/api.php:139-142`). Full inventory: GAP_ANALYSIS §7. | STATIC |
| 16 | `tbl_voucher.generated_by` exists as operator identity, not a commercial model | **CONFIRMED** | `tbl_voucher.generated_by int` (signed); set at `plan.php:784`. Used only for one-level Agent list scoping (`plan.php:480-503`, `650-662`, `873-884`). No price, cost or settlement fields. | STATIC + schema |
| 17 | Local `rad_acct` vs FreeRADIUS `radacct` | **CONFIRMED** | `radacct` = FreeRADIUS session history on the `'radius'` connection (`Radius.php:215-217`). `rad_acct` = local counter state written by the rlm_rest endpoint `radius.php:266-334` on the default connection, read at `radius.php:360-368,418-425`, zeroed by `RadiusRest.php:35-40`, reaped by `cron.php:140-152`. `Radius.php:493` reads `radacct` on the **default** connection (inconsistent). Authoritative history = FreeRADIUS `radacct` (SQL mode); REST mode keeps no session history. | STATIC + schema |
| 18 | License metadata conflict | **CONFIRMED** | §6 | STATIC + history |

No finding was classified `CHANGED_UPSTREAM` or `NOT_REPRODUCED`. Additional findings beyond these 18 are in GAP_ANALYSIS §13 (security register) and §15 (conflicts).

---

## 6. License metadata (finding 18, D039) — classification without legal conclusion
| Evidence | Detail |
|---|---|
| `README.md` "License" | "GNU General Public License version 2 or later" — present since the first upload `979475b3` (2017-03-11, "PHPMixBill v5.0 - First Upload": "GNU General Public License version 2 or later; see LICENSE.txt") |
| `LICENSE` | GPLv2 text, unchanged since `979475b3` (sha256 `db296f2f…c73`). Identical to `/usr/share/common-licenses/GPL-2` except unfilled GitHub-template placeholders (`{description}`, `{year} {fullname}`, `{signature of Ty Coon}`) in the "How to Apply" appendix. The appendix's "or (at your option) any later version" (`LICENSE:297-299`) is template text, not a filled-in project grant. |
| `composer.json:7` | `"license": "MIT"`, added in `fc6a5a26` (2023-03-06, "add new install") by the same upstream author, with `"type": "template"` |
| Per-file headers | Attribution only ("PHP Mikrotik Billing … by https://t.me/ibnux"); no license statement |
| Bundled third-party | mpdf **GPL-2.0-only**; php-facedetection GPL-2.0; Smarty **LGPL-3.0**; phpqrcode **LGPL-3.0-or-later**; PEAR2 RouterOS LGPL-2.1; PHPMailer LGPL; Idiorm BSD; UI libraries MIT/OFL |

**Classification: CONFLICT CONFIRMED. Unresolved.**
- The oldest and most consistent signals are the README and LICENSE (GPL-2.0, README adding "or later"). The MIT declaration is a later package-metadata field.
- A protective reading treats distribution under GPL-2.0 terms. Bundled GPL-2.0-only code (mpdf) also points that way.
- Whether README's "or later" applies to the project as a whole is not established by the LICENSE file.
- Bundling LGPL-3.0 components (Smarty, phpqrcode) alongside GPL-2.0-only code (mpdf) raises a known compatibility question. It is **flagged for owner/legal review, not resolved**.

**WP0 actions on licensing:** no notice was changed, removed or relicensed.

---

## 7. Proposed WP1+ development/test environment (documented only, not built in-repo)
- **Runtime:** PHP 8.2 (owner decision C5), using an official `php:8.2-apache` image with gd, zip, pdo_mysql, mysqli.
  - Replacing the PHP 7.4 `Dockerfile` needs an explicit owner-approved change.
- **Database:** MariaDB 10.11 or MySQL 8.0, InnoDB, strict SQL mode, `utf8mb4`.
  - A **reproducible base schema** = `install/phpnuxbill.sql` + `install/radius.sql` + all `system/updates.json` applied in a **strict** runner that fails on error. Expected-failure classes must be explicitly whitelisted.
- **RADIUS:** FreeRADIUS 3.2.x with `rlm_sql` (mysql) and the dictionary/sqlcounter configuration the app's attributes require.
  - Stock config rejects app-provisioned users (R1).
  - CoA tests use a stub or loopback NAS.
- **Tests:** PHPUnit as a **dev-only** dependency in a separate composer manifest, so the runtime vendor tree is untouched. Plus a seed script with role and tree fixtures.
  - This is a dependency change, proposed for WP1 with owner approval.
- **No real MikroTik** until WP8. Device contract tests use stub RouterOS clients (the F3 pattern).
- **CI:** a minimal lint + unit-test workflow can be proposed in WP1. None exists today.

## 8. Limits of this verification
- **Security findings:** classified from code evidence, not exploitation (owner rule). Exploitability details are deliberately omitted from committed documents.
- **Payment gateway and plugin implementations:** these live outside the repository (`.gitignore`: `system/paymentgateway/**`, `system/plugin/*`). Only the core contracts were inventoried.
- **Authenticated UI screens:** reviewed statically; only public login pages were rendered.
- **gd/zip on PHP 8.2:** not exercised (sandbox build limitation). PDF and invoice rendering on 8.2 is unverified.
- **RADIUS rate-limit delivery:** depends on `radgroupreply` written when an admin saves a plan through the UI (`services.php` → `Radius::add_plan`). The sandbox plan was inserted by SQL, so rate-limit delivery was **not** observed (UNVERIFIED).
- **Real CoA/Disconnect:** no packet was sent and none will be until WP8.
