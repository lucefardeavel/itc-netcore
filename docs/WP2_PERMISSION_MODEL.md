# WP2 — Permission Model, Hierarchy and Ownership Boundary

Status: implemented on branch `wp2/hierarchy-permissions` (base `0b16f60`), waiting for owner review.
Scope: authorization, data scoping, reseller hierarchy, customer ownership, CSRF, API authentication,
error handling and the OD-11 service gate. **No finance, pricing, activation, RADIUS or MikroTik logic.**
Binding inputs: the accepted WP2 plan with owner decisions OD-1 … OD-16 (`owner-review/WP2/REPORT.md` §A).

The tables in §4 and §13 are generated from code (`RoleCapabilityMap`, `RouteMap`) and verified by the
`wp2-unit` suite (`RouteMapTest` compares the map with every action found in `system/controllers/*.php`).

## 1. Components

All runtime classes live under `system/autoload/Reseller/` and are loaded by the existing autoloader.
Each file starts with `if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }`, so none of
them is executable on its own (`NUXR_RUNTIME` is defined only by `init.php` and the test bootstrap).

| Class | Responsibility |
|---|---|
| `Security\ActorContext` | Immutable principal built once per request: STAFF / CUSTOMER / SYSTEM / ANONYMOUS / DENIED, role, status, enrolment, ancestors, frozen, manager, channel, request id |
| `Security\Capability`, `Security\RoleCapabilityMap` | Code-defined capability catalogue and the reviewed role → capability → scope map (no DB editor) |
| `Security\PermissionService` | `can` / `require` / `scopeOf` applying frozen, enrolment, consistency and schema-readiness rules |
| `Security\DataScope` | Parenthesized, parameter-bound `where_raw` predicates for customers, derived rows, vouchers and staff |
| `Security\RouteMap`, `Security\RouteGuard` | Central route authorization for web and API; unmapped = 404 for every role; POST-only routes return 405 on GET |
| `Security\CsrfPolicy` | One stable token per session, enforced centrally for every browser POST |
| `Security\ApiToken`, `Security\ApiGuard` | `a2` actor-bound API tokens, dedicated secret, API allowlist, response sanitizer |
| `Security\PluginRegistry` | `plugin/{fn}` dispatch limited to registered + authorized menu functions (OD-9) |
| `Security\PathGuard` | Language, page-name and voucher-template-name canonicalization (NEW-1, NEW-4) |
| `Security\RestoreGuard` | C24 / OD-5 fail-closed legacy restore guard |
| `Security\ErrorReporter` | Generic error + reference id to clients; sanitized server-side log; sanitized Telegram summary |
| `Domain\SchemaReadiness` | Registry/checksum/column/lock check; not ready ⇒ schema-dependent work fails closed for everyone |
| `Domain\ServiceGate` | OD-11 temporary gate: no legacy service grant for customers with `manager_id IS NOT NULL` |
| `Domain\ResellerTreeService` | Closure-table hierarchy: place, move, verify; global tree lock; strict transactions |
| `Domain\StaffService`, `Domain\CustomerService` | Staff lifecycle (SEC-04) and customer ownership / Sales assignment |
| `Domain\Tx`, `Domain\DomainError` | Strict-mode transactions, tree lock, UTC time; typed refusal codes |
| `Audit\AuditLog` | Append-only writer for `tbl_reseller_audit_events` (R0006) with a redactor |

CLI-only tool: `system/reseller/hierarchy.php` (inspect / validate / plan; `apply` refused).

## 2. Request pipeline

Web (`index.php` → `system/boot.php`):

1. `init.php` defines `NUXR_RUNTIME`, forces `display_errors=0`, `log_errors=1`, `zend.exception_ignore_args=1`,
   allowlists the language (NEW-1); the actor context is ANONYMOUS until resolved, so plugins loaded by
   `init.php` never see a privileged context.
2. `ActorContext` is resolved from the staff session/cookie (status re-read from the DB on every request)
   or the customer session.
3. `PluginRegistry::capture()` snapshots the registered plugin menus.
4. `CsrfPolicy::enforceWeb()` — every non-API, non-callback POST needs a valid token.
5. `RouteGuard::authorize()` — the `(controller, action[#variant], method)` entry decides; unmapped ⇒ 404
   for every role; a POST-only entry requested with GET/HEAD ⇒ 405; capability rules go through
   `PermissionService`.
6. `$_can` capability flags are assigned to Smarty (cosmetic menu hiding only).
7. The controller runs; object ids are resolved through `DataScope` (capability + scope in one query;
   out-of-scope and nonexistent ids give the same response).
8. Any uncaught `Throwable` ⇒ HTTP 500, generic message and reference id (`ErrorReporter`).

API (`system/api.php`): cookies and session are discarded first; identity comes only from an `a2` token;
then the same `RouteGuard` with `RouteMap::API_ALLOWED` (deny by default); every response is sanitized.

## 3. Principals and scopes

| Scope | Meaning | Customer predicate |
|---|---|---|
| G | global | none |
| T | own subtree incl. self (closure) | `(tbl_customers.manager_id IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ?))` |
| D | strict descendants (depth > 0) | staff only |
| C | direct children (depth = 1) | staff creation only (Agent creates its own children) |
| A | customers explicitly assigned to self (C21) | `(tbl_customers.assigned_sales_id = ? AND tbl_customers.manager_id = ?)` (second value = the Sales user's manager) |
| S | self only | — |
| none | not enrolled / not ready / Report | `(1 = 0)` |

Derived rows (recharges, transactions, payment-gateway rows) are scoped through the customer **id**
(`customer_id` / `user_id IN (SELECT id FROM tbl_customers WHERE <predicate>)`), never by username;
rows with id 0 are visible with G scope only. Vouchers: T ⇒ `generated_by` in subtree; S ⇒ `generated_by = self`.

Principal state rules (`PermissionService`):

- **Frozen** (own status or any ancestor not Active): no `MUTATING` capability; reads remain.
- **Inactive / deleted** staff: unauthenticated on the next request (web session cleared, API refused).
- **Not enrolled** Agent/Sales: tree scopes (T/D/C/A) resolve to nothing — they see no data until an
  Admin places them in the hierarchy.
- **Inconsistent** (enrolled account whose closure parent differs from the legacy `root` field, an Agent
  under a non-Agent, or a Sales user without an Agent parent): no tree scopes.
- **Schema not ready**: every `SCHEMA_DEPENDENT` capability and every tree scope is refused for every
  role including SuperAdmin; the service gate refuses every service grant (it cannot evaluate ownership).
- **Default credential** (installer-seeded `admin` hash): every route redirects to change-password.

## 4. Role → capability → scope matrix (generated)

| Capability | SuperAdmin | Admin | Agent | Sales | Report | Mutating | Schema-dependent |
|---|---|---|---|---|---|---|---|
| `customer.read` | G | G | T | A | — |  |  |
| `customer.create` | G | G | T | A | — | yes | yes |
| `customer.sync` | G | G | — | — | — | yes |  |
| `customer.update` | G | G | — | — | — | yes |  |
| `customer.deactivate` | G | G | — | — | — | yes |  |
| `customer.delete` | G | G | — | — | — | yes | yes |
| `customer.impersonate` | G | G | — | — | — | yes |  |
| `customer.export` | G | G | — | — | — |  |  |
| `customer.assign_sales` | G | G | T | — | — | yes | yes |
| `customer.transfer_owner` | G | G | — | — | — | yes | yes |
| `customer.assign_legacy_owner` | G | G | — | — | — | yes | yes |
| `service.recharge` | G | G | — | — | — | yes |  |
| `service.recharge_free` | G | G | — | — | — | yes |  |
| `service.refill_voucher` | G | G | — | — | — | yes |  |
| `service.deposit` | G | G | — | — | — | yes |  |
| `service.extend` | G | G | — | — | — | yes |  |
| `service.plan_edit` | G | G | — | — | — | yes |  |
| `service.sync_all` | G | G | — | — | — | yes |  |
| `invoice.read` | G | G | T | A | — |  |  |
| `invoice.send` | G | G | T | A | — | yes |  |
| `voucher.generate` | G | G | — | — | — | yes |  |
| `voucher.read` | G | G | T | S | — |  |  |
| `voucher.delete` | G | G | — | — | — | yes |  |
| `message.send` | G | G | T | A | — | yes |  |
| `message.send_selected` | G | G | T | A | — | yes |  |
| `message.bulk` | G | G | — | — | — | yes |  |
| `report.read` | G | G | — | — | — |  |  |
| `dashboard.view` | G | G | S | S | S |  |  |
| `staff.read` | G | G | T | S | S |  |  |
| `staff.create` | G | G | C | — | — | yes | yes |
| `staff.update_profile` | G | G | D | — | — | yes | yes |
| `self.update_profile` | S | S | S | S | S |  |  |
| `self.change_password` | S | S | S | S | S |  |  |
| `staff.change_role` | G | G | — | — | — | yes | yes |
| `staff.disable` | G | G | D | — | — | yes | yes |
| `staff.delete` | G | G | — | — | — | yes | yes |
| `reseller.place` | G | G | — | — | — | yes | yes |
| `reseller.move` | G | G | — | — | — | yes | yes |
| `reseller.tree_read` | G | G | T | — | — |  | yes |
| `system.admin` | G | G | — | — | — | yes |  |
| `system.code` | G | — | — | — | — | yes |  |
| `system.coupons` | G | G | — | — | — | yes |  |
| `system.backup` | G | — | — | — | — | yes |  |
| `system.restore` | G | — | — | — | — | yes |  |
| `system.update` | — | — | — | — | — | yes |  |
| `api.access` | S | S | S | S | S |  |  |
| `wallet.read` (reserved) | — | — | — | — | — |  |  |
| `ledger.read` (reserved) | — | — | — | — | — |  |  |
| `wallet.fund_withdraw` (reserved) | — | — | — | — | — |  |  |
| `wallet.transfer` (reserved) | — | — | — | — | — |  |  |
| `pricing.base_cost` (reserved) | — | — | — | — | — |  |  |
| `pricing.child_wholesale` (reserved) | — | — | — | — | — |  |  |
| `pricing.own_retail` (reserved) | — | — | — | — | — |  |  |
| `activation.create` (reserved) | — | — | — | — | — |  |  |
| `activation.read` (reserved) | — | — | — | — | — |  |  |

Reserved capabilities are defined now and never granted until the owning work package enables them.

**Staff target rules** (`StaffService`, on top of the matrix):

| Actor | May create | May manage (profile/password reset, disable/reactivate, role among) | Delete |
|---|---|---|---|
| SuperAdmin | any role (Agent/Sales under an enrolled, Active Agent or top-level Agent) | any role; never self status/role; the last active SuperAdmin is protected | unreferenced, never-enrolled accounts |
| Admin (OD-12) | Report, Agent, Sales | Report, Agent, Sales (never SuperAdmin/Admin) | Report accounts only |
| Agent | Agent or Sales as a **direct child of itself** (parent from the form ignored) | strict descendants only (OD-13) | — |
| Sales, Report | — | own profile/password only | — |

- Nobody changes their own type, status or parent. `root` is never taken from a form; parents change only
  through `ResellerTreeService` (`reseller/place-post`, `reseller/move-post`), which also keeps `root` equal
  to the closure parent.
- Role changes are **tree-safe**: refused when the account is enrolled, is any customer's `manager_id` or
  `assigned_sales_id`, or is anyone's `root` (disable and create a new account instead).
- A referenced/enrolled account is never deleted (disable instead); every reference is found by a
  schema-driven foreign-key scan before any side effect.

## 5. Hierarchy (ResellerTreeService)

- Closure table `tbl_manager_tree` (WP1 R0001). Agents may have Agent or Sales children; Sales are leaves.
- Every mutation: `GET_LOCK('nuxr_tree_<sha1(db)[0:20]>')` **before** `BEGIN`, strict `sql_mode`, row locks
  in ascending id order, actor revalidation, validation (self-parent, cycle, parent role/status/enrolment),
  closure rewrite, `root` update, audit row, commit. A partial closure is never committed.
- Moving a Sales user (or a subtree) clears Sales assignments whose customer owner no longer matches the
  Sales user's manager, in the same transaction, with an audit event per customer. Ownership never moves
  implicitly.
- `ResellerTreeService::verify()` reports missing self rows, depth gaps, orphans and closure ↔ `root`
  mismatches (used by tests, the CLI tool and `inspect`).

## 6. Customer ownership (CustomerService)

| Actor | `manager_id` on create | `assigned_sales_id` on create |
|---|---|---|
| Sales (enrolled, not frozen) | the Sales user's manager | self |
| Agent (enrolled, not frozen) | self | optional; must be an Active, enrolled direct Sales child of the Agent |
| SuperAdmin / Admin | optional: NULL (legacy-unassigned, default) or an enrolled Active Agent | optional; only with an owner and only a Sales child of that owner |
| Report / non-enrolled / frozen | refused | refused |

- Posted `manager_id` / `assigned_sales_id` are ignored for Agent/Sales.
- `reseller/customer-assign-sales` / `customer-clear-sales`: SuperAdmin/Admin (G) or Agent inside its subtree (OD-7).
- `reseller/customer-transfer`: SuperAdmin/Admin only; a Sales user not under the new owner is cleared unless
  a valid replacement is supplied; an invalid replacement is rejected.
- `reseller/customer-assign-owner`: SuperAdmin/Admin, explicit selection, only `manager_id IS NULL` rows,
  all-or-nothing, at most 500 per request (OD-6). **Never derived from `created_by`; no automatic assignment.**
- Customer delete (Admin): reference pre-check before any device or row side effect; no swallowed FK errors.

## 7. OD-11 service gate (temporary, until WP5B)

For every customer with `manager_id IS NOT NULL`, every legacy operation that can create, extend,
re-provision or otherwise grant service fails closed for **every role**: recharge (incl. `using=zero`),
refill / voucher activation, deposit, extend, plan edit, sync (single and bulk), customer portal order
pay/send/gateway/buy, self-extend, balance transfer, portal password change (re-provisions the device),
public voucher+username login, cron auto-renewal, and customer edits that change username / PPPoE
identity / password / status → Active.

- Core guards (`Package::rechargeUser`, `rechargeBalance`, `rechargeCustomBalance`, `Balance::transfer`)
  return `false` before any write, hook or device call, for every caller (web, API, cron, RADIUS REST,
  payment plugins).
- Controller gates return the friendly message *"… temporarily unavailable for reseller-managed customers
  (pending WP5B)"* before the path's hooks; refusals are audited as `service.refused` and logged.
- Agent and Sales hold **no** `service.*` / `customer.sync` capability in WP2, so they are refused even
  for customers correctly inside their own scope.
- Unassigned legacy customers keep the legacy behaviour for SuperAdmin/Admin.

## 8. CSRF (OD-8)

- One stable token per session (`$_SESSION['nuxr_csrf']`, 64 hex), rotated on login and logout; the
  `csrf_enabled` setting is ignored.
- Every non-API, non-callback POST needs the token in the body (`csrf_token`) or the `X-CSRF-Token` header.
- Former GET mutations are POST-only (`data-method="post"` links, `window.nuxrPost(url)`); a GET returns 405.
- `ui/ui/nuxr-csrf.tpl` (included by the admin, customer and public footers and the standalone
  login/register/print templates) adds the hidden field to every POST form and the header to jQuery/fetch.
- Public login, register (all variants), forgot and voucher activation work with the token rendered in
  their page; a missing or foreign token is refused with no side effect.
- Custom templates outside `ui/ui` that render their own POST forms without the shared footer must add
  `<input type="hidden" name="csrf_token" value="{$csrf_token}">`.

## 9. API (OD-3, OD-15, SEC-21)

- Token format `a2.{uid}.{iat}.{mac}` with
  `mac = HMAC-SHA256("nuxr-api-staff|v2|{uid}|{iat}|" . tbl_users.password, $api_secret)`.
  The MAC covers the stored password hash, so **any password change revokes every earlier token** (no
  schema change needed). Age ≤ 90 days, at most 60 s clock skew, the user must exist and be Active.
- `$api_secret` must be set in `config.php`, at least 32 characters and different from `$db_pass`;
  otherwise **every API request fails closed** ("API disabled"). There is no fallback to `db_pass`.
- The global `api_key` and the legacy `a.` / `c.` / `u.` token formats are refused.
- Obtain a token: `POST system/api.php?r=admin/post` with `username`, `password` → `result.token`; send it as
  `Authorization: Bearer <token>` (or `token=` parameter).
- Same RouteGuard / PermissionService / DataScope as the web, plus the `API_ALLOWED` allowlist
  (no impersonation, backup/restore, plugin/code surfaces, updater, callbacks or customer portal).
- Responses are sanitized recursively (keys matching `pass|secret|login_token|salt|api_key|csrf|otp|signature|cookie`,
  `_c`, `config` removed; `_admin` / `_user` reduced to identity fields).

## 10. Errors (OD-14)

No role, SuperAdmin included, receives stack traces, SQL errors, filesystem paths or secrets. Web and API
return a generic message with a reference id (HTTP 500); the server log line `[nuxr] ref=…` carries the
sanitized detail; Telegram (if configured) receives only `System error ref=… class=… route=…`. Web-reachable
cron output is reduced to a status line (CLI output unchanged).

## 11. Audit (OD-1, R0006)

`tbl_reseller_audit_events` is append-only (no update/delete code path; excluded from log retention and
from the legacy restore). Every row carries event type, actor and target ids, actor/target identity
snapshots (JSON), before/after (JSON) where applicable, request id, channel (WEB/API/CLI), IP and a UTC
`DATETIME(6)`. The redactor removes secret-like keys and known secret values; payloads over 64 KB are
rejected. Events: `staff.create|update|role_change|disable|reactivate|delete`, `tree.place|move`,
`customer.create|assign_sales|clear_sales|transfer_owner|assign_legacy_owner|delete`, `restore.refused`,
`service.refused`.

## 12. Restore guard (C24, OD-5)

`settings/dbrestore` refuses before reading the upload when the migration registry holds any row other
than `ROLLED_BACK`, any other project table exists (`tbl_manager_*`, `tbl_reseller_*`, `tbl_project_*`),
the customer ownership columns (`manager_id`, `assigned_sales_id`) exist, or the guard itself errors.
A refusal is audited (`restore.refused`) when the audit table is available and logged server-side. On a pure legacy install the restore remains (SuperAdmin + CSRF), with table names validated
against `SHOW TABLES`, project tables refused and no `SET GLOBAL FOREIGN_KEY_CHECKS`.

## 13. Route inventory (generated from RouteMap)

Rule `public` / `customer` / `staff` / `plugin` / `deny` or a capability; "Roles (scope)" lists who holds
that capability (SA SuperAdmin, A Admin, Ag Agent, Sa Sales, R Report). "API" = reachable with an `a2` token.
Object-level scoping inside a permitted route is applied by `DataScope` in the controller.

| Controller | Action (variant) | Method | Rule | Roles (scope) | API |
|---|---|---|---|---|---|
| admin | login-display | GET/POST | `public` | public | — |
| admin | post | POST | `public` | public | yes |
| login | login-display | GET/POST | `public` | public | — |
| login | post | POST | `public` | public | — |
| login | activation | POST | `public` | public | — |
| register | register-display | GET/POST | `public` | public | — |
| register | post | POST | `public` | public | — |
| forgot | forgot | GET/POST | `public` | public | — |
| logout | logout | GET/POST | `public` | public | — |
| callback | notify | GET/POST | `public` | public | — |
| plugin | dispatch | GET/POST | `plugin` | plugin | — |
| home | dashboard | GET/POST | `customer` | customer | — |
| home | dashboard#renewal | POST | `customer` | customer | — |
| home | dashboard#sync | POST | `customer` | customer | — |
| home | dashboard#extend | POST | `customer` | customer | — |
| home | dashboard#deactivate | POST | `customer` | customer | — |
| accounts | 404 | GET/POST | `customer` | customer | — |
| accounts | change-password | GET/POST | `customer` | customer | — |
| accounts | change-password-post | POST | `customer` | customer | — |
| accounts | profile | GET/POST | `customer` | customer | — |
| accounts | edit-profile-post | POST | `customer` | customer | — |
| accounts | phone-update | GET/POST | `customer` | customer | — |
| accounts | phone-update-otp | POST | `customer` | customer | — |
| accounts | phone-update-post | POST | `customer` | customer | — |
| accounts | email-update | GET/POST | `customer` | customer | — |
| accounts | email-update-otp | POST | `customer` | customer | — |
| accounts | email-update-post | POST | `customer` | customer | — |
| accounts | language-update-post | POST | `customer` | customer | — |
| autoload_user | none | GET/POST | `customer` | customer | — |
| autoload_user | isLogin | GET/POST | `customer` | customer | — |
| autoload_user | bw_name | GET/POST | `customer` | customer | — |
| autoload_user | inbox_unread | GET/POST | `customer` | customer | — |
| autoload_user | inbox | GET/POST | `customer` | customer | — |
| autoload_user | language | GET/POST | `customer` | customer | — |
| mail | list | GET/POST | `customer` | customer | — |
| mail | view | GET/POST | `customer` | customer | — |
| mail | delete | POST | `customer` | customer | — |
| order | voucher | GET/POST | `customer` | customer | — |
| order | history | GET/POST | `customer` | customer | — |
| order | balance | GET/POST | `customer` | customer | — |
| order | package | GET/POST | `customer` | customer | — |
| order | unpaid | GET/POST | `customer` | customer | — |
| order | view | GET/POST | `customer` | customer | — |
| order | pay | GET/POST | `customer` | customer | — |
| order | send | GET/POST | `customer` | customer | — |
| order | gateway | GET/POST | `customer` | customer | — |
| order | buy | GET/POST | `customer` | customer | — |
| page | view | GET/POST | `customer` | customer | — |
| voucher | 404 | GET/POST | `customer` | customer | — |
| voucher | activation | GET/POST | `customer` | customer | — |
| voucher | activation-post | POST | `customer` | customer | — |
| voucher | list-activated | GET/POST | `customer` | customer | — |
| voucher | invoice | GET/POST | `customer` | customer | — |
| dashboard | view | GET/POST | `dashboard.view` | SA:G A:G Ag:S Sa:S R:S | yes |
| dashboard | view#refresh | POST | `system.admin` | SA:G A:G | yes |
| search_user | search | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| customers | list | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| customers | csv | POST | `customer.export` | SA:G A:G | — |
| customers | csv-prepaid | POST | `customer.export` | SA:G A:G | — |
| customers | add | GET | `customer.create` | SA:G A:G Ag:T Sa:A | — |
| customers | add-post | POST | `customer.create` | SA:G A:G Ag:T Sa:A | yes |
| customers | view | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| customers | viewu | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| customers | edit | GET | `customer.update` | SA:G A:G | — |
| customers | edit#deletePhoto | POST | `customer.update` | SA:G A:G | — |
| customers | edit-post | POST | `customer.update` | SA:G A:G | yes |
| customers | sync | POST | `customer.sync` | SA:G A:G | yes |
| customers | recharge | GET | `service.recharge` | SA:G A:G | yes |
| customers | deactivate | POST | `customer.deactivate` | SA:G A:G | yes |
| customers | delete | POST | `customer.delete` | SA:G A:G | yes |
| customers | login | POST | `customer.impersonate` | SA:G A:G | — |
| plan | list | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| plan | sync | POST | `service.sync_all` | SA:G A:G | yes |
| plan | recharge | GET | `service.recharge` | SA:G A:G | yes |
| plan | recharge-confirm | POST | `service.recharge` | SA:G A:G | yes |
| plan | recharge-post | POST | `service.recharge` | SA:G A:G | yes |
| plan | view | GET | `invoice.read` | SA:G A:G Ag:T Sa:A | yes |
| plan | view#send | POST | `invoice.send` | SA:G A:G Ag:T Sa:A | yes |
| plan | print | GET/POST | `invoice.read` | SA:G A:G Ag:T Sa:A | yes |
| plan | edit | GET | `service.plan_edit` | SA:G A:G | yes |
| plan | edit-post | POST | `service.plan_edit` | SA:G A:G | yes |
| plan | delete | POST | `service.plan_edit` | SA:G A:G | yes |
| plan | voucher | GET/POST | `voucher.read` | SA:G A:G Ag:T Sa:S | yes |
| plan | voucher-view | GET | `voucher.read` | SA:G A:G Ag:T Sa:S | yes |
| plan | print-voucher | GET/POST | `voucher.read` | SA:G A:G Ag:T Sa:S | yes |
| plan | add-voucher | GET | `voucher.generate` | SA:G A:G | yes |
| plan | voucher-post | POST | `voucher.generate` | SA:G A:G | yes |
| plan | remove-voucher | POST | `voucher.delete` | SA:G A:G | yes |
| plan | voucher-delete | POST | `voucher.delete` | SA:G A:G | yes |
| plan | voucher-delete-many | POST | `voucher.delete` | SA:G A:G | yes |
| plan | refill | GET | `service.refill_voucher` | SA:G A:G | yes |
| plan | refill-post | POST | `service.refill_voucher` | SA:G A:G | yes |
| plan | deposit | GET | `service.deposit` | SA:G A:G | yes |
| plan | deposit-post | POST | `service.deposit` | SA:G A:G | yes |
| plan | extend | POST | `service.extend` | SA:G A:G | yes |
| message | send | GET | `message.send` | SA:G A:G Ag:T Sa:A | — |
| message | send-post | POST | `message.send` | SA:G A:G Ag:T Sa:A | yes |
| message | send_bulk | GET | `message.bulk` | SA:G A:G | — |
| message | send_bulk_ajax | POST | `message.bulk` | SA:G A:G | yes |
| message | send_bulk_selected | POST | `message.send_selected` | SA:G A:G Ag:T Sa:A | yes |
| reports | daily-report | GET/POST | `report.read` | SA:G A:G | yes |
| reports | by-date | GET/POST | `report.read` | SA:G A:G | yes |
| reports | activation | GET/POST | `report.read` | SA:G A:G | yes |
| reports | by-period | GET/POST | `report.read` | SA:G A:G | yes |
| reports | period-view | GET/POST | `report.read` | SA:G A:G | yes |
| reports | ajax | GET/POST | `report.read` | SA:G A:G | yes |
| export | 404 | GET/POST | `report.read` | SA:G A:G | — |
| export | print-by-date | GET/POST | `report.read` | SA:G A:G | — |
| export | pdf-by-date | GET/POST | `report.read` | SA:G A:G | — |
| export | print-by-period | GET/POST | `report.read` | SA:G A:G | — |
| export | pdf-by-period | GET/POST | `report.read` | SA:G A:G | — |
| maps | customer | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| maps | routers | GET/POST | `system.admin` | SA:G A:G | yes |
| maps | odp | GET/POST | `system.admin` | SA:G A:G | yes |
| autoload | 404 | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| autoload | pool | GET/POST | `system.admin` | SA:G A:G | yes |
| autoload | bw_name | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| autoload | balance | GET/POST | `service.recharge` | SA:G A:G | yes |
| autoload | server | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| autoload | plan | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| autoload | pppoe_ip_used | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| autoload | pppoe_username_used | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| autoload | customer_is_active | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| autoload | plan_is_active | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| autoload | customer_select2 | GET/POST | `customer.read` | SA:G A:G Ag:T Sa:A | yes |
| settings | 404 | GET/POST | `system.admin` | SA:G A:G | — |
| settings | docs | GET/POST | `system.admin` | SA:G A:G | — |
| settings | devices | GET/POST | `system.admin` | SA:G A:G | — |
| settings | app | GET | `system.admin` | SA:G A:G | — |
| settings | app#test | POST | `system.admin` | SA:G A:G | — |
| settings | app-post | POST | `system.admin` | SA:G A:G | — |
| settings | localisation | GET | `system.admin` | SA:G A:G | — |
| settings | localisation-post | POST | `system.admin` | SA:G A:G | — |
| settings | users | GET/POST | `staff.read` | SA:G A:G Ag:T Sa:S R:S | yes |
| settings | users-add | GET | `staff.create` | SA:G A:G Ag:C | — |
| settings | users-post | POST | `staff.create` | SA:G A:G Ag:C | yes |
| settings | users-view | GET | `staff` | any active staff | yes |
| settings | users-edit | GET | `staff` | any active staff | — |
| settings | users-edit#deletePhoto | POST | `staff` | any active staff | — |
| settings | users-edit-post | POST | `staff` | any active staff | yes |
| settings | users-delete | POST | `staff.delete` | SA:G A:G | yes |
| settings | change-password | GET | `self.change_password` | SA:S A:S Ag:S Sa:S R:S | — |
| settings | change-password-post | POST | `self.change_password` | SA:S A:S Ag:S Sa:S R:S | yes |
| settings | notifications | GET | `system.admin` | SA:G A:G | — |
| settings | notifications-post | POST | `system.admin` | SA:G A:G | — |
| settings | dbstatus | GET | `system.admin` | SA:G A:G | — |
| settings | dbbackup | POST | `system.backup` | SA:G | — |
| settings | dbrestore | POST | `system.restore` | SA:G | — |
| settings | language | GET | `system.admin` | SA:G A:G | — |
| settings | lang-post | POST | `system.admin` | SA:G A:G | — |
| settings | maintenance | GET/POST | `system.admin` | SA:G A:G | — |
| settings | miscellaneous | GET/POST | `system.admin` | SA:G A:G | — |
| reseller | tree | GET | `reseller.tree_read` | SA:G A:G Ag:T | yes |
| reseller | place-post | POST | `reseller.place` | SA:G A:G | yes |
| reseller | move-post | POST | `reseller.move` | SA:G A:G | yes |
| reseller | status-post | POST | `staff.disable` | SA:G A:G Ag:D | yes |
| reseller | customer-assign-sales | POST | `customer.assign_sales` | SA:G A:G Ag:T | yes |
| reseller | customer-clear-sales | POST | `customer.assign_sales` | SA:G A:G Ag:T | yes |
| reseller | customer-transfer | POST | `customer.transfer_owner` | SA:G A:G | yes |
| reseller | customer-assign-owner | POST | `customer.assign_legacy_owner` | SA:G A:G | yes |
| bandwidth | list | GET/POST | `system.admin` | SA:G A:G | yes |
| bandwidth | add | GET | `system.admin` | SA:G A:G | yes |
| bandwidth | add-post | POST | `system.admin` | SA:G A:G | yes |
| bandwidth | edit | GET | `system.admin` | SA:G A:G | yes |
| bandwidth | edit-post | POST | `system.admin` | SA:G A:G | yes |
| bandwidth | delete | POST | `system.admin` | SA:G A:G | yes |
| routers | list | GET/POST | `system.admin` | SA:G A:G | yes |
| routers | add | GET | `system.admin` | SA:G A:G | yes |
| routers | add-post | POST | `system.admin` | SA:G A:G | yes |
| routers | edit | GET | `system.admin` | SA:G A:G | yes |
| routers | edit-post | POST | `system.admin` | SA:G A:G | yes |
| routers | delete | POST | `system.admin` | SA:G A:G | yes |
| odp | list | GET/POST | `system.admin` | SA:G A:G | yes |
| odp | add | GET | `system.admin` | SA:G A:G | yes |
| odp | add-post | POST | `system.admin` | SA:G A:G | yes |
| odp | edit | GET | `system.admin` | SA:G A:G | yes |
| odp | edit-post | POST | `system.admin` | SA:G A:G | yes |
| odp | delete | POST | `system.admin` | SA:G A:G | yes |
| radius | nas-list | GET/POST | `system.admin` | SA:G A:G | yes |
| radius | nas-add | GET | `system.admin` | SA:G A:G | yes |
| radius | nas-add-post | POST | `system.admin` | SA:G A:G | yes |
| radius | nas-edit | GET | `system.admin` | SA:G A:G | yes |
| radius | nas-edit-post | POST | `system.admin` | SA:G A:G | yes |
| radius | nas-delete | POST | `system.admin` | SA:G A:G | yes |
| pool | list | GET/POST | `system.admin` | SA:G A:G | yes |
| pool | add | GET | `system.admin` | SA:G A:G | yes |
| pool | add-post | POST | `system.admin` | SA:G A:G | yes |
| pool | edit | GET | `system.admin` | SA:G A:G | yes |
| pool | edit-post | POST | `system.admin` | SA:G A:G | yes |
| pool | delete | POST | `system.admin` | SA:G A:G | yes |
| pool | sync | POST | `system.admin` | SA:G A:G | yes |
| pool | port | GET/POST | `system.admin` | SA:G A:G | yes |
| pool | add-port | GET | `system.admin` | SA:G A:G | yes |
| pool | add-port-post | POST | `system.admin` | SA:G A:G | yes |
| pool | edit-port | GET | `system.admin` | SA:G A:G | yes |
| pool | edit-port-post | POST | `system.admin` | SA:G A:G | yes |
| pool | delete-port | POST | `system.admin` | SA:G A:G | yes |
| services | hotspot | GET/POST | `system.admin` | SA:G A:G | yes |
| services | add | GET | `system.admin` | SA:G A:G | yes |
| services | add-post | POST | `system.admin` | SA:G A:G | yes |
| services | edit | GET | `system.admin` | SA:G A:G | yes |
| services | edit-post | POST | `system.admin` | SA:G A:G | yes |
| services | delete | POST | `system.admin` | SA:G A:G | yes |
| services | pppoe | GET/POST | `system.admin` | SA:G A:G | yes |
| services | pppoe-add | GET | `system.admin` | SA:G A:G | yes |
| services | pppoe-add-post | POST | `system.admin` | SA:G A:G | yes |
| services | pppoe-edit | GET | `system.admin` | SA:G A:G | yes |
| services | edit-pppoe-post | POST | `system.admin` | SA:G A:G | yes |
| services | pppoe-delete | POST | `system.admin` | SA:G A:G | yes |
| services | vpn | GET/POST | `system.admin` | SA:G A:G | yes |
| services | vpn-add | GET | `system.admin` | SA:G A:G | yes |
| services | vpn-add-post | POST | `system.admin` | SA:G A:G | yes |
| services | vpn-edit | GET | `system.admin` | SA:G A:G | yes |
| services | edit-vpn-post | POST | `system.admin` | SA:G A:G | yes |
| services | vpn-delete | POST | `system.admin` | SA:G A:G | yes |
| services | balance | GET/POST | `system.admin` | SA:G A:G | yes |
| services | balance-add | GET | `system.admin` | SA:G A:G | yes |
| services | balance-add-post | POST | `system.admin` | SA:G A:G | yes |
| services | balance-edit | GET | `system.admin` | SA:G A:G | yes |
| services | balance-edit-post | POST | `system.admin` | SA:G A:G | yes |
| services | balance-delete | POST | `system.admin` | SA:G A:G | yes |
| services | sync | POST | `system.admin` | SA:G A:G | yes |
| logs | list | GET/POST | `system.admin` | SA:G A:G | yes |
| logs | list-csv | GET/POST | `system.admin` | SA:G A:G | — |
| logs | radius | GET/POST | `system.admin` | SA:G A:G | yes |
| logs | radius-csv | GET/POST | `system.admin` | SA:G A:G | — |
| logs | message | GET/POST | `system.admin` | SA:G A:G | yes |
| logs | message-csv | GET/POST | `system.admin` | SA:G A:G | — |
| customfield | list | GET | `system.admin` | SA:G A:G | — |
| customfield | save | POST | `system.admin` | SA:G A:G | — |
| community | view | GET | `system.admin` | SA:G A:G | — |
| coupons | list | GET/POST | `system.coupons` | SA:G A:G | yes |
| coupons | add | GET | `system.coupons` | SA:G A:G | yes |
| coupons | add-post | POST | `system.coupons` | SA:G A:G | yes |
| coupons | edit | GET | `system.coupons` | SA:G A:G | yes |
| coupons | edit-post | POST | `system.coupons` | SA:G A:G | yes |
| coupons | delete | POST | `system.coupons` | SA:G A:G | yes |
| coupons | status | POST | `system.coupons` | SA:G A:G | yes |
| paymentgateway | list | GET/POST | `system.admin` | SA:G A:G | — |
| paymentgateway | audit | GET/POST | `system.admin` | SA:G A:G | — |
| paymentgateway | auditview | GET/POST | `system.admin` | SA:G A:G | — |
| paymentgateway | delete | POST | `system.code` | SA:G | — |
| paymentgateway | gateway | GET/POST | `system.code` | SA:G | — |
| pluginmanager | list | GET | `system.admin` | SA:G A:G | — |
| pluginmanager | refresh | POST | `system.admin` | SA:G A:G | — |
| pluginmanager | dlinstall | POST | `system.code` | SA:G | — |
| pluginmanager | install | POST | `system.code` | SA:G | — |
| pluginmanager | delete | POST | `system.code` | SA:G | — |
| widgets | list | GET/POST | `system.admin` | SA:G A:G | — |
| widgets | add | GET/POST | `system.admin` | SA:G A:G | — |
| widgets | edit | GET/POST | `system.admin` | SA:G A:G | — |
| widgets | delete | POST | `system.admin` | SA:G A:G | — |
| widgets | pos | POST | `system.admin` | SA:G A:G | — |
| pages | page | GET | `system.admin` | SA:G A:G | — |
| pages | page-post | POST | `system.admin` | SA:G A:G | — |
| pages | page-reset | POST | `system.admin` | SA:G A:G | — |
| default | resolve | GET/POST | `public` | public | — |

_257 route entries; default actions: '' and unknown actions resolve to each controller's real default branch (see RouteMap::ROUTES); anything unmapped is denied for every role._

## 14. Compatibility impact

- Existing Agents and Sales see **no customers, vouchers or sub-users** until an Admin places them in the
  hierarchy and assigns customers (onboarding runbook below).
- Report has login, an empty dashboard, own profile/password and logout only (OD-4).
- Agents/Sales lose deposit, extend, free recharge, customer edit/export, reports/exports, bulk messaging,
  voucher generation; Sales lose coupons (OD-10). Admins lose SuperAdmin/Admin management, code surfaces
  and backup/restore (OD-12).
- Reseller-owned customers lose every legacy service operation until WP5B (OD-11), in the admin UI, the API,
  cron and the customer portal. Pending gateway transactions for them need manual resolution.
- The web updater is gone (OD-2). Unregistered or anonymous plugin endpoints are refused (OD-9).
- Mutation links need JavaScript; bookmarked GET-mutation URLs return 405.
- A disabled account is cut off on its next request; a password change logs out other web sessions and
  revokes API tokens; existing login cookies become invalid once at deploy.
- The API is disabled until `$api_secret` is configured; `api_key` and old token integrations must move to `a2`.
- Error pages show only a reference id; details are in the server error log.

## 15. Deployment order and configuration

1. Back up the database (operator procedure; not part of WP2).
2. Apply R0001–R0006 with the project runner: `php system/reseller/migrate.php preflight`, then
   `php system/reseller/migrate.php migrate`, then `php system/reseller/migrate.php verify`.
3. Add a dedicated API signing secret to `config.php` (only if the API is used):
   `$api_secret = '<64 hex characters from: php -r "echo bin2hex(random_bytes(32));">';`
   It must differ from `$db_pass`. Rotating it revokes every API token.
4. Deploy the WP2 code. Without step 2, schema readiness fails closed for every role (login, profile and
   logout still work and SuperAdmin/Admin see a "domain not ready" banner).
5. Change the installer default `admin` password if still in use (enforced at login).

## 16. Onboarding runbook (explicit, OD-6)

1. Review the legacy hierarchy (read-only):
   `php system/reseller/hierarchy.php validate` and `php system/reseller/hierarchy.php plan --json`.
   The tool never writes; `apply` is refused (C20). The legacy `root` field is shown, never applied.
2. As SuperAdmin/Admin open **Reseller Tree** and place each existing Agent (top level or under an Agent)
   and each Sales user (under its Agent) with `place`.
3. Assign legacy customers explicitly: customer view → *Assign owner* (or bulk assign on unassigned
   customers), optionally with a Sales user of that owner. Nothing is derived from `created_by`.
4. Verify with `php system/reseller/hierarchy.php inspect` (expect `tree_drift: []`).
