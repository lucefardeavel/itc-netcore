# Owner Review — WP2 Hierarchy + Permission Core

Final state: `STOPPED_WAITING_OWNER_REVIEW`
WP status: `IMPLEMENTED_NOT_ACCEPTED`. This report does not self-accept; acceptance is recorded only by the
owner in `docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md` (not edited by WP2).

Binding inputs: the WP2 plan accepted with amendments, owner decisions OD-1 … OD-16, the schema-readiness
rule, the schema-scope rule and the standing prohibitions (owner decision message of 2026-10-01).

**Supporting documents**
- [`docs/WP2_PERMISSION_MODEL.md`](../../docs/WP2_PERMISSION_MODEL.md): components, request pipeline, scopes,
  the capability matrix and route inventory generated from code, compatibility notes, deployment order,
  `$api_secret` setup and the onboarding runbook.
- [`ROUTE_MATRIX_EVIDENCE.txt`](ROUTE_MATRIX_EVIDENCE.txt): every request of the generated role × route matrix
  (role, method, route, rule, expected, actual status).
- [`TESTDOX.txt`](TESTDOX.txt): `--testdox` output of the complete matrix on both engines.

## A. Baseline ancestry / accepted prior HEAD / current HEAD / branch / worktree
| Item | Value |
|---|---|
| Upstream baseline (ancestry) | `d3e0596287322944ee21fcc14dc1798158463140` |
| Accepted WP0 | `c2582368d0111046e39c353ca70b782d8fbb78c4` (ACCEPTED_WITH_NOTES) |
| Accepted WP1 | `1f36bfa17383efa903011a8316b5b4bf14e6bdc8` (ACCEPTED_WITH_NOTES) |
| Execution base (owner metadata commit) | `0b16f60dc6c8f28571675bce141203d382b68ee4` = `origin/feat/reseller-system-v1` |
| Branch | `wp2/hierarchy-permissions`, created from `0b16f60`, no upstream tracking to `feat/reseller-system-v1` |
| Ancestry proven | `merge-base --is-ancestor` of `0b16f60`, `1f36bfa`, `c2582368` against HEAD: all ✅ |
| Commits since `0b16f60` | `aa2f71d` R0006 audit table · `8cf402a` central authorization, CSRF, API hardening, OD-11 gate · `055f24e` test suites + follow-up fixes · `39f04b2` MySQL 8.0 portability of two retrofitted queries + docs · `4fbacff` restore original CRLF line endings (no content change) · `2eb190d` API case for unmapped routes · `4c53418` doc precision (no code) · final commit: this report and its evidence files |
| Ending HEAD | the final report commit on `wp2/hierarchy-permissions`. A commit cannot contain its own hash; it is stated in the final response and equals `git rev-parse wp2/hierarchy-permissions`. |
| Spec manifest | `sha256sum --strict -c MANIFEST.sha256` → 42/42 OK; the pack is unchanged (`git diff 0b16f60 -- docs/claude-development-pack` is empty) |
| Worktree | clean at the start; clean after the final commit |
| `main` / `feat/reseller-system-v1` | untouched (`500d3a2` / `0b16f60`) |

## B. Scope implemented
All items of the amended plan (§A2 overrides §1–§21), in the planned order:

1. **R0006 `tbl_reseller_audit_events`** (OD-1, option A): append-only audit with actor/target identity
   snapshots, before/after JSON (named `JSON_VALID` checks), request id, channel, IP, UTC `DATETIME(6)`; no FKs
   (approved exception); `AuditLog` writer with a redactor and a 64 KB limit. No update/delete code path.
2. **SchemaReadiness** (owner rule): registry present; R0001–R0006 `APPLIED` with matching file checksums; no
   blocking or unknown registry rows; required tables/columns present; runner lock free. Not ready ⇒ every
   schema-dependent capability and every tree scope is refused for every role incl. SuperAdmin; the service
   gate refuses everything; login/profile/logout and a SuperAdmin/Admin banner remain.
3. **ErrorReporter** (OD-14): no role receives traces, SQL, paths or secrets (web 500 + generic message +
   reference; API `success:false` + ref); sanitized `error_log`; Telegram receives `System error ref=… class=…
   route=…` only (boot, Package, Invoice, radius, message, cron); `display_errors` forced off at runtime;
   web-reachable cron output reduced to a status line.
4. **ServiceGate** (OD-11): core guards in `Package::rechargeUser/rechargeBalance/rechargeCustomBalance` and
   `Balance::transfer` (return `false` before any write/hook/device call) plus controller gates for every
   WP0-discovered service-granting path (table in G.1). Agent/Sales hold no `service.*`/`customer.sync`.
5. **Unauthenticated surfaces**: `plugin/{fn}` registry (OD-9), `search_user` auth + scope, web updater
   disabled (OD-2), installer re-entry guard + `$api_secret` generation in the `step4` config template
   (SEC-19 minimal), NEW-1, NEW-4, NEW-5.
6. **ActorContext + session hardening** (SEC-10): per-request Active check, session fingerprint and cookie MAC
   bound to the password hash, `session_regenerate_id` on login, full logout, default-credential lock
   (SEC-18 minimal), Inactive login refused with the bad-password message.
7. **RouteMap / PermissionService / RouteGuard**: every controller action mapped (completeness test against
   `system/controllers/*.php`); unmapped ⇒ 404 for every role; POST-only routes ⇒ 405 on GET.
8. **CsrfPolicy** (OD-8): central, independent of `csrf_enabled`; stable per-session token rotated on
   login/logout; footer shim; ~44 GET-mutation links converted to POST; public login/register/forgot/voucher
   flows work with their page token.
9. **API** (OD-3, OD-15, SEC-21): cookies/session discarded; `a2` actor-bound tokens bound to the password
   hash; dedicated `$api_secret` (≥ 32 chars, ≠ `db_pass`) or fail closed; `api_key`, `a.`, `c.`, `u.`
   refused; API allowlist; recursive response sanitizer. **No `WP2_API_REVOCATION` stop was needed.**
10. **Staff management** (SEC-04, OD-12, OD-13): server-side target-role table, no self type/status/parent
    change, `root` never from forms, tree-safe role change, scoped view/edit, delete reference pre-check,
    last-SuperAdmin protection, audited lifecycle.
11. **Raw SQL** (SEC-12): transaction retention delete removed; logs `keep`/`q` bound; customer list `orderby`
    allowlist; raw `OR` fragments parenthesized; NEW-2 escape.
12. **DataScope**: parenthesized, bound predicates (T subtree, A assigned, none); derived rows by customer id;
    vouchers by closure; staff by closure; identical response for out-of-scope and nonexistent ids.
13. **ResellerTreeService** + `reseller` controller/UI (tree, place, move, status) with the global tree lock.
14. **CustomerService**: create ownership rules, assign/clear Sales, transfer (S/A only, OD-7), explicit legacy
    owner assignment (OD-6, all-or-nothing, ≤ 500), delete reference pre-check.
15. **Retrofits**: customers, search_user, autoload, plan (scoped reads, invoices, voucher read scope, C8
    generation closure), message, maps, dashboard (Report/Agent/Sales: scope-safe empty), coupons (OD-10),
    widgets (`html_php*` SuperAdmin only), pages, settings, reports, logs, customfield, community.
16. **RestoreGuard** (C24, OD-5) and backup validation.
17. **`system/reseller/hierarchy.php`** (CLI only): inspect / validate / plan; `apply` refused (exit 5).

**Not implemented (forbidden or later WPs):** no wallet, pricing, ActivationService, RADIUS or MikroTik logic;
no finance; no hierarchy import/apply executed; no `tbl_users.root` import; no `created_by` reinterpretation;
no automatic customer assignment; no broad UI redesign; WP3A not started.

## C. Files changed (relative to `0b16f60`)
164 files including the three `owner-review/WP2` files: 62 added, 101 modified, 1 deleted (`ui/ui/admin/rollback.tpl`).

| Area | Files |
|---|---|
| New runtime (`system/autoload/Reseller/`) | `Security/` ActorContext, ApiGuard, ApiToken, Capability, CsrfPolicy, DataScope, ErrorReporter, PathGuard, PermissionService, PluginRegistry, RestoreGuard, RoleCapabilityMap, RouteGuard, RouteMap · `Domain/` CustomerService, DomainError, ResellerTreeService, SchemaReadiness, ServiceGate, StaffService, Tx · `Audit/AuditLog` |
| New migration / CLI | `system/reseller/migrations/R0006_reseller_audit.php`, `system/reseller/hierarchy.php` |
| Entry points | `init.php`, `system/boot.php`, `system/api.php`, `update.php` (static 403), `radius.php` (sanitized errors only), `system/cron.php`, `system/cron_reminder.php`, `install/{index,step2,step3,step4,step5,update}.php` |
| Legacy autoload | `Admin.php`, `Csrf.php`, `Package.php` (guards + sanitized Telegram only), `Balance.php` (transfer guard only), `Invoice.php` (sanitized errors only) |
| Controllers (27) | accounts, admin, autoload, autoload_user, community, coupons, customers, customfield, dashboard, home, login, logout, logs, maps, message, order, page, pages, plan, plugin, register, reports, **reseller (new)**, search_user, settings, voucher, widgets |
| Widgets | `system/widgets/html_php.php`, `html_php_card.php`, `customer/html_php.php` (error output only) |
| Templates (50) | new `admin/403.tpl`, `admin/reseller/tree.tpl`, `nuxr-csrf.tpl`; removed `admin/rollback.tpl`; staff add/edit/list, customers add/edit/list/view, header/footer/error, `data-method="post"` conversions in list templates, print templates, customer footer/error/login/register |
| Docs | `docs/WP2_PERMISSION_MODEL.md`; `owner-review/WP2/{REPORT.md, ROUTE_MATRIX_EVIDENCE.txt, TESTDOX.txt}` |
| Tests | `tests/Http/*` (19 classes), `tests/Unit/Wp2/*` (3), `tests/Integration/Wp2/HierarchyCliTest.php`, `tests/Support/{AppServer,HttpClient,HttpResponse,Wp2Seed,Wp2HttpTestCase,DbProbe,RouteInventory}.php`, `tests/phpunit.xml`, `tests/bootstrap.php`; WP1 test updates listed in E |

Not modified: R0001–R0005, the migration runner (`system/reseller/Migration/*`), `variants.php`,
`system/updates.json`, `install/*.sql`, the production `Dockerfile`, device classes, the spec pack, the ledger.
Controllers without code changes (routers, services, bandwidth, pool, odp, radius, paymentgateway,
pluginmanager, export, …) are gated centrally by RouteGuard; their GET mutations became POST in templates.

## D. Important design notes
- **One authorization path for web and API.** `RouteGuard` runs before every controller include in
  `boot.php` and `api.php`; object ids are then resolved through `DataScope` (capability + scope in one query).
  Hooks and plugins are never consulted for authorization.
- **Fail closed everywhere:** unmapped route ⇒ 404 for every role; context build failure ⇒ DENIED;
  schema not ready ⇒ schema-dependent work refused for every role; missing/short/`db_pass`-equal API
  secret ⇒ API disabled; restore guard error ⇒ refusal.
- **API revocation without schema change (OD-15):** the `a2` MAC covers `tbl_users.password`; any staff
  password write (change-password, users-edit-post, reset) invalidates every earlier token. The hash is
  never revealed. The web cookie and session fingerprint are bound the same way.
- **OD-11 gate placement:** core guards return `false` (every caller tests truthiness; an exception or
  redirect would break cron/RADIUS) and are the backstop for plugins, cron, RADIUS REST and payment code;
  controller gates give the friendly message before the path's hooks and before any write (E13: before the
  customer password overwrite; E10: before payment creation).
- **Tree concurrency:** `GET_LOCK` before `BEGIN` serializes tree writers; verified by a two-process
  cross-move test (exactly one succeeds, the other gets `CYCLE`, closure verifies clean, 6 rounds).
- **Out-of-scope = nonexistent:** same status/body for both (no existence oracle), verified per route.
- **Spec names kept, namespaced** (`Reseller\Security\…`, `Reseller\Domain\…`), so the immutable pack and
  `DECISIONS.md` need no edit.
- **Two legacy MySQL 8.0 defects fixed in retrofitted routes** (found by the matrix; pre-existing at
  `0b16f60`): `plan/print-voucher` compared a TIMESTAMP with `'0'` and `reports/period-view` compared a DATE
  with `''` — both MySQL 8.0 strict-mode error 1525. Replaced by equivalent portable predicates (same results
  as the legacy code on MariaDB); tests in `RouteMatrixTest` and `SecurityRoutesTest::testRawSqlParametersAreInert`.
- **Line endings:** fifteen legacy files are CRLF; commit `4fbacff` restores CRLF after earlier edits had
  normalized them, so `git diff 0b16f60` shows only real changes (`--ignore-cr-at-eol` is identical).

## E. Migrations / data impact
| Item | Value |
|---|---|
| New migration | `R0006_reseller_audit` (only new WP2 migration; strict runner; rollback = DROP while empty) |
| R0006 file sha256 | `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd` |
| R0001–R0005 | byte-identical to accepted WP1: `git diff 1f36bfa -- system/reseller/migrations/R000[1-5]*` is empty |
| R0001–R0005 sha256 | `a0b52f7f…14a5a7`, `38bceb1f…c771`, `60e63156…9acd`, `7f3bc5bc…ffa7`, `b69135ea…3854` (unchanged) |
| Runner | unchanged (`git diff 1f36bfa -- system/reseller/Migration` is empty) |
| Data migration / backfill / import | **none**. No hierarchy import or apply was executed anywhere; no customer was assigned automatically; `root` and `created_by` are not read as ownership sources. |
| WP1 test updates (approved with OD-1) | `MigrationDefinitionsTest`: "R0005 runs last" → "R0005 is the only legacy-table change; later migrations only `CREATE TABLE`"; `ForwardMigrationTest`: 6 registry rows; `RollbackTest`: R0006 `ROLLED_BACK`; `IntegrationTestCase::projectTables()` + audit table; `CliOnlyTest`: + `hierarchy.php`; `AppRegressionTest`: WP2 behaviour change (no recharge while the schema is not ready), pages copied into the app copy, harness reads stdout only |
| Deployment order | run `migrate.php` (R0001–R0006) **before** deploying WP2 code; set `$api_secret` if the API is used (docs §15) |

## F. Test commands and exact results
Environment: `dev/test/run-tests.sh` — PHP 8.2.34 container, disposable MariaDB 10.11.19 and MySQL 8.0.46
containers (tmpfs), PHPUnit 11.5.56. Run on the final code (`2eb190d`; the later commits change documentation only).

```
dev/test/run-tests.sh            # both engines, all suites, then variant-registry reproducibility
dev/test/run-tests.sh --testdox  # same, testdox output (owner-review/WP2/TESTDOX.txt)
```

| Suite | Tests | Content |
|---|---|---|
| unit (WP1) | 15 | migration definitions, grants |
| integration (WP1 + CliOnly) | 58 | runner, preflight, forward, rollback, partial failure, drift, constraints, CLI-only |
| regression | 1 | legacy app flows before/after migration |
| wp2-unit | 21 | permission model, route map completeness, guards/sanitizers |
| wp2-integration | 7 | hierarchy CLI dry-run tool |
| wp2-http | 92 | HTTP suites against a served disposable app copy |
| **Total** | **194** | |

| Engine | Plain run (code `2eb190d`) | `--testdox` run (code `2eb190d` + docs-only `4c53418`) |
|---|---|---|
| MariaDB 10.11.19 | **OK (194 tests, 9089 assertions)** | **OK (194 tests, 9089 assertions)** |
| MySQL 8.0.46 | **OK (194 tests, 9089 assertions)** | **OK (194 tests, 9089 assertions)** |
| Variant registry reproducibility (V1/V2/V3 on both engines) | matches freshly built fixtures | matches freshly built fixtures |
| `run-tests.sh` exit code | 0 | 0 |

No skipped, risky, incomplete or warning tests (`failOnRisky`/`failOnWarning` are on). `TESTDOX.txt` lists
all 194 test names per engine (388 ✔, 0 ✘).

Earlier full runs during this final step were not green and were fixed before the runs above: the regression
harness parsed stdout+stderr together (both engines; fixed to read stdout only), and two allowed SuperAdmin/Admin
pages returned 500 on MySQL 8.0 only (`plan/print-voucher`, `reports/period-view`; legacy strict-mode
defects fixed in `39f04b2`, see D).

Lint: `php -l` on all 109 changed/new PHP files — PHP 8.2.34: 0 errors; PHP 8.3.6 (observation): 0 errors.

Secret sweep of `git diff 0b16f60`: the test database password value occurs 0 times; no private keys or
provider tokens; the only 40-hex literal is the public installer default `sha1('admin')` used by the
default-credential lock; test passwords are disposable fixture values (`pw-<user>`).

## G. Security / failure cases tested
All cases are raw HTTP requests with real sessions or real API tokens (UI hiding never counts), on both
engines. "No mutation" = `DbProbe` CHECKSUM TABLE of every table (activity tables asserted separately).

### G.1 OD-11 service gate (E-paths from `docs/GAP_ANALYSIS.md`)
| Path | Gate | Test (`ServiceGateTest::` unless noted) |
|---|---|---|
| E1 recharge-post incl. `using=zero` | `plan.php` guardCustomer + core | `testAgentAndSalesCannotGrantServiceToTheirOwnInScopeCustomer`, `…ThroughTheApi`, `testSuperAdminAndAdminAreRefusedOnResellerOwnedCustomers` |
| E2 refill-post | `plan.php` + core | same three |
| E3 deposit-post | `plan.php` + core `rechargeBalance` | same three; `testCoreGuardsRefuseDirectCallsForResellerOwnedCustomers` |
| E4 extend | `plan.php` (POST-only) | same three |
| E5 plan edit-post | `plan.php` | same three |
| E6 voucher generation | SuperAdmin/Admin only (C8) | `SecurityRoutesTest::testAgentAndSalesCannotGenerateVouchers` |
| E7 voucher activation | `voucher.php` + core | `testCustomerPortalRefusesServiceForResellerOwnedCustomer` |
| E8 order pay | `order.php` | same |
| E9 order send (target-based) | `order.php` (sender and target) | same |
| E10 order gateway / buy / check | `order.php` before payment creation | same |
| E11 cron auto-renew | `cron.php` skip + log, loop continues | `testCronSkipsResellerOwnedAutoRenewalAndRenewsUnassigned` |
| E12 public voucher-only activation | core guard (voucher-mode username collision) + E14 gate | `testPublicVoucherActivationRefusedAndPasswordUnchanged`, `testCoreGuards…` |
| E13 voucher + username activation | `login.php` before customer creation and before the password overwrite | `testPublicVoucherActivationRefusedAndPasswordUnchanged` (password unchanged) |
| E14 voucher-only re-add | `login.php` username collision | same |
| E15 RADIUS REST authorize | core guard (voucher-mode collision); E15b/E16 are WP4 | `testCoreGuards…` (`rechargeUserVoucherCollision`) |
| E17 customer self-extend | `home.php` | `testCustomerPortalRefuses…` |
| E18 balance transfer | `home.php` + core `Balance::transfer` (both sides) | `testCustomerPortalRefuses…`, `testCoreGuards…` |
| E19a plan/sync (bulk) | reseller-owned skipped + audited; refused while not ready | `testBulkPlanSyncSkipsResellerOwnedCustomers` |
| E19b customers/sync | `customers.php` | the three role tests |
| E19c portal sync | `home.php` | `testCustomerPortalRefuses…` |
| E20 portal password change | `accounts.php` | `testCustomerPortalRefuses…` |
| E21 customer edit-post (username, PPPoE identity, password, re-activation) | `customers.php` | `testCustomerEditRefusesReprovisioningChangesForResellerOwned` (profile edits still allowed, no device call) |
| E22 API | same controllers + allowlist | `testAgentAndSalesCannotGrantServiceThroughTheApi`, `AuditTest` |
| Schema not ready | every service operation refused incl. unassigned | `SchemaNotMigratedTest`, `SchemaDriftTest`, `AppRegressionTest` |
| Regression: unassigned customers | SuperAdmin/Admin recharge, refill, sync still work | `testUnassignedCustomersKeepAdminServiceCompatibility` |

Every refusal: no change in `tbl_user_recharges`, `tbl_transactions`, `tbl_customers.balance`, `tbl_voucher`
(whole-DB probe), empty device call log, `service.refused` audit row (web/API).

### G.2 Security closures
| ID | Fix | Tests |
|---|---|---|
| SEC-02 `plugin/{fn}` | registered + authorized menu functions only | `SecurityRoutesTest::testPluginDispatchRequiresRegisteredAuthorizedFunction` |
| SEC-03 updater | `update.php` static 403; links removed; rollback denied | `SecurityRoutesTest::testUpdaterIsDisabled` |
| SEC-04 `user_type` escalation | StaffService target table, self-protection, tree-safe role change | `StaffManagementTest::*` (7) |
| SEC-05 `search_user` | staff + `customer.read` + scope, bounded LIKE | `SecurityRoutesTest::testSearchUserRequiresStaffAndScope`, `ScopeIsolationTest::testCustomerListsSearchAndPickersAreScoped` |
| SEC-06 IDOR | DataScope on every object/list; no existence oracle | `ScopeIsolationTest::*` (5), `RouteMatrixTest` |
| SEC-07 ungated mutations / Report | RouteGuard + capability map | `RouteMatrixTest::testRoleRouteMatrix`, `testMutatingRoutesRefuseGet`, `testUnmappedRoutesAreDeniedForEveryone` |
| SEC-09 CSRF | central CsrfPolicy, POST-only mutations, public flows | `CsrfTest::*` (9) |
| SEC-10 Inactive staff | per-request status, session/cookie hardening | `AuthSessionTest::*` (7), `ApiTest::testDisabledUserTokenIsRefused` |
| SEC-12 raw SQL delete | branch removed, bound params, allowlists | `SecurityRoutesTest::testRawSqlParametersAreInert` |
| SEC-21 API | a2 tokens, secret, allowlist, sanitizer, no ambient cookies | `ApiTest::*` (5), `ApiNoSecretTest`, `ApiShortSecretTest`, `ApiSecretEqualsDbPassTest`, `CsrfTest::testBrowserCookieGivesNoAuthorityThroughTheApi` |
| SEC-18 (minimal) default credential | change-password lock | `AuthSessionTest::testDefaultCredentialMustBeChangedFirst` |
| SEC-19 (minimal) installer | 403 once `config.php` exists | `SecurityRoutesTest::testInstallerIsDisabledOnceConfigured` |
| C8 / D027 voucher generation | SuperAdmin/Admin only | `SecurityRoutesTest::testAgentAndSalesCannotGenerateVouchers` |
| C24 restore | RestoreGuard before reading the upload | `RestoreGuardTest::*` (2), `RestoreLegacyInstallTest` |
| NEW-1 language path | allowlist against `system/lan/*.json` | `SecurityRoutesTest::testLanguageCookieTraversalIsIgnored`, `GuardsTest::testPathGuardRejectsTraversal` |
| NEW-2 reflected XSS | `{$content|escape}` | `SecurityRoutesTest::testPrintContentIsEscaped` |
| NEW-3 traces | ErrorReporter | `ErrorHandlingTest::*` (4) |
| NEW-4 `template_name` / page traversal | regex + allowlist + realpath containment | `SecurityRoutesTest::testPagesTraversalIsRejected`, `GuardsTest::testPathGuardRejectsTraversal` |
| NEW-5 empty-id invoice signature | positive integer ids, `hash_equals`, 404 | `SecurityRoutesTest::testPublicInvoiceSignature` |
| CLI-only WP2 files | runtime classes 404 over HTTP | `SecurityRoutesTest::testWp2FilesRefuseDirectHttpExecution`, `CliOnlyTest` |

### G.3 API evidence (OD-3 / OD-15)
- No `db_pass` fallback: `init.php` no longer assigns `$api_secret`; `ApiToken::secret()` requires ≥ 32 chars
  and ≠ `db_pass`. `ApiNoSecretTest`, `ApiShortSecretTest`, `ApiSecretEqualsDbPassTest`: login and every route
  refused ("API disabled"), no token issued.
- Password-change revocation: `ApiTest::testPasswordChangeRevokesEarlierTokens` — a token issued before
  the user's own change (`settings/change-password-post`) and a token issued before an Admin reset
  (`settings/users-edit-post`) are refused afterwards; a token from a new login with the new password works. Web: `AuthSessionTest::testPasswordChangeInvalidatesOtherSessionsAndCookies`.
- Retired formats and tampering: `ApiTest::testInvalidTokensAreRefused` — legacy `a.` (signed with
  `db_pass`), `c.`, `u.Array`, the global `api_key`, tampered uid / iat / mac, expired (> 90 days), future
  `iat` (+1 h), `iat` = 0, a token signed with a wrong secret, a token signed with `db_pass`, garbage.
- Scope and allowlist: `ApiTest::testApiInheritsTheActorScopeAndAllowlist`; sanitizer:
  `ApiTest::testValidTokenWorksAndResponsesAreSanitized`, `GuardsTest::testApiSanitizerRemovesCredentialsAndConfig`.

### G.4 Error-handling evidence (OD-14)
`ErrorHandlingTest`: a forced `PDOException` whose message contains SQL text, a path and the real `db_pass`,
and a `TypeError`, for SuperAdmin, Admin, Agent, Sales and Report (web); a customer-side exception (customer
portal); a hook throwing a `PDOException` inside an API-reachable controller (API). Each returns HTTP 500
(API: `success:false`) with the generic message and a reference id; the body contains no `SQLSTATE`, stack
trace, `.php:<line>`, `/home/`, app directory, `PDOException`/`TypeError` text, database name or password
(`HttpResponse::leaksInternals()` plus explicit checks); the server log holds the sanitized record under the
same reference; the Telegram probe receives only lines matching `System error ref=… class=… route=…`.
Anonymous users cannot reach the failing staff endpoint at all (404). `display_errors=1` in the test
`config.php` is overridden on staff and public pages.

### G.5 Schema-readiness evidence
- `SchemaNotMigratedTest` (unmigrated database, no registry): SuperAdmin and Admin see the "schema is not
  ready" banner; their recharge of an unassigned customer is refused (the gate cannot evaluate ownership);
  customer create, staff create and the reseller tree return 403; legacy customer list and router
  administration stay available. The legacy Agent gets the dashboard and its change-password page; customers,
  plans, messaging and the staff directory return 403. No DB change, no device call; the core guard returns
  `false` for a direct `Package::rechargeUser`. Own profile edit, own password change and logout still work.
- `SchemaDriftTest`, one test per condition (`FAILED_PARTIAL` registry row, checksum drift, missing R0006 row +
  unknown registry id, missing audit table, runner lock held): SuperAdmin recharge of an unassigned customer
  refused, SuperAdmin customer create 403, Agent customer list 403, Agent dashboard 200, banner shown, no DB
  change, no device call; readiness returns once the condition is removed.
- Unit, every role × every schema-dependent capability: `PermissionModelTest::testSchemaNotReadyFailsClosedForEveryRole`
  (own password and dashboard kept; pure legacy administration kept for Admin).

### G.6 Hierarchy, ownership, audit, import tool
- `HierarchyTest` (7): seed tree consistent; explicit placement of legacy accounts (S/A only); move rules
  (cycle, self-parent, Sales parent, inactive parent, Agent cannot move); subtree move keeps the closure
  consistent and clears mismatched Sales assignments (audited); Agent disables strict descendants only
  (OD-13); frozen descendant is read-only; concurrent cross-moves cannot create a cycle.
- `CustomerOwnershipTest` (9): Sales/Agent/Admin create rules with forged fields ignored; Report and
  non-enrolled refused; Sales assignment inside scope (OD-7); transfer S/A only, clears foreign Sales,
  validates replacements; explicit legacy owner assignment only (OD-6); delete reference pre-check, audited.
- `AuditTest` (2): every audited operation carries snapshots, request id, channel (WEB and API), UTC
  microsecond timestamp; no row contains any seeded password, credential hash, token, `api_secret` or
  `db_pass`, nor a secret-like key; snapshots survive deletion and renaming; no runtime code updates,
  deletes or truncates the audit table (only the R0006 rollback `DROP`).
- `HierarchyCliTest` (7): inspect/validate/plan/apply cause zero mutation (whole-DB checksums incl. audit
  and logs, `Com_insert/update/delete/replace/truncate…` deltas 0); every finding reported with the full
  cycle path `1030 -> 1031 -> 1032 -> 1030`; invalid subtrees excluded, parents first; identical
  `plan_sha256` on rerun and with reversed insertion order; enrolled nodes never re-placed; `apply` exit 5.
  **The tool was run only against disposable test databases. No import/apply was executed.**

### G.7 Direct URL / forged POST / IDOR matrix
`RouteMatrixTest::testRoleRouteMatrix` generates every (route × role) request from `RouteMap` for seven
principals (SuperAdmin, Admin, Agent, Sales, Report, non-enrolled Agent, frozen Agent): 1 440 requests — 1 162 denials (all 403) and 278 allowed reads (243 × 200, 35 × 302); the table is identical on MariaDB and MySQL.
Every denied combination returns 403 with no DB change; every allowed read returns no 401/403/405/500;
no response leaks internals. Full table: `ROUTE_MATRIX_EVIDENCE.txt`. `testMutatingRoutesRefuseGet`: every
POST-only route returns 405 on GET for an authorized SuperAdmin with no DB change.
`testUnmappedRoutesAreDeniedForEveryone`: an unmapped controller present on disk is 404 for SuperAdmin,
Admin and Agent (GET and POST), refused through the API with a SuperAdmin token, and never runs.
`ScopeIsolationTest`: out-of-scope ids (sibling, unrelated tree, NULL owner) give the same response as
nonexistent ids on the customer object routes; lists, search, pickers, invoices, recharges, vouchers,
messaging and the staff directory are scoped. Through the API: `ApiTest::testApiInheritsTheActorScopeAndAllowlist`.

## H. Git diff summary
`git diff --shortstat 0b16f60` excluding `owner-review/`: 161 files changed, 9 920 insertions(+), 1 299 deletions(−);
with the report and its two evidence files: 164 files, 12 303 insertions(+), 1 299 deletions(−).
Largest code changes: `settings.php` (+190/−244), `Admin.php` (+152/−82), `customers.php` (+79/−57),
`boot.php` (+63/−30), `pages.php` (+48/−26); the rest is new code under `system/autoload/Reseller/`
and tests.

## I. Known risks / TODOs (unresolved; no owner decision implied)
| ID | Risk |
|---|---|
| R1 | Plugins still run top-level code at `init.php` load before any guard; hooks cannot veto (C6/C11). Mitigated only by the core gates. |
| R2 | Legacy service granting stays disabled (not redesigned) for reseller-owned customers until WP5B; pending pre-WP2 gateway transactions for them need manual resolution. |
| R3 | Scope correctness on legacy routes relies on RouteMap + per-controller DataScope calls; mitigated by the completeness test and the generated matrix. |
| R4 | `tbl_transactions` rows with `user_id = 0` are visible to SuperAdmin/Admin only. |
| R5 | No per-token API revocation without a password change; rotating `$api_secret` revokes all tokens. |
| R6 | The CSRF token is stable for the session lifetime (rotated on login/logout). |
| R7 | The admin JSON restore is unavailable on reseller installations until the WP10 DB-level runbook (OD-5). |
| R8 | WP1 note: CHECK constraints are verified by name, not expression (also applies to R0006); WP9. |
| R9 | Payment/other plugins that write `tbl_user_recharges` or call devices directly bypass the core gates (C6). |
| R10 | Registered, not fixed: NEW-6 payment-link token lifetime ~59 days; NEW-7 `db_pass` as HMAC key for customer cookies, payment links and invoice signatures; NEW-8 Smarty pages without a security policy. |
| R11 | Legacy GET routes not converted: customer-portal `home?mikrotik=login/logout` (session toggle on the device, no grant); the RADIUS post-auth log export includes the legacy-logged password column (SuperAdmin/Admin only). |
| R12 | `export/print-by-period` and `export/pdf-by-period` share the `reports/period-view` empty-date MySQL 8.0 defect when called without dates (SuperAdmin/Admin only; not exercised: the test image lacks the PDF/GD extensions). |
| R13 | `settings/app` shows the installation path in the cron instructions (deliberate configuration display for SuperAdmin/Admin). |
| R14 | Mutation links need JavaScript; operator custom templates outside `ui/ui` that render their own POST forms must add `{$csrf_token}`. |

## J. Production status
Untouched. No production or staging database, RADIUS, MikroTik, Telegram or external target was contacted.
All databases were disposable containers (tmpfs); the app copies used a probe device and a probe plugin
that record instead of sending.

## K. Push status
Normal push of `wp2/hierarchy-permissions` to `origin` (`git push -u origin wp2/hierarchy-permissions`), the
only push target approved by OD-16. No force push, no PR, no merge into `feat/reseller-system-v1` or `main`,
no deploy. The result (remote ref and hash) is stated in the final response.

## L. Owner-review artifact path
`owner-review/WP2/REPORT.md` (this file), with `ROUTE_MATRIX_EVIDENCE.txt` and `TESTDOX.txt`.

## M. Final state
`STOPPED_WAITING_OWNER_REVIEW`

## Definition of Done assessment (WP2)
| DoD item | Status | Evidence |
|---|---|---|
| Requested scope implemented, nothing knowingly omitted | Met | B |
| No unauthorized adjacent WP work (finance, pricing, activation, RADIUS, MikroTik, import, UI redesign) | Met | B, C |
| Relevant automated tests added/run on both engines, exact results | Met | F |
| Negative/security/failure cases | Met | G |
| Migrations / data impact documented; R0001–R0005 byte-identical | Met | E |
| Changed files and diff summary | Met | C, H |
| Docs for decisions in scope | Met | `docs/WP2_PERMISSION_MODEL.md` |
| Durable owner-review artifact | Met | L |
| No secrets committed | Met | F (sweep) |
| No unexplained dirty worktree | Met | A |
| Known risks listed | Met | I |
| Production untouched | Met | J |
| OD-1 audit content (snapshots, request id, channel, UTC, no secrets) | Met | G.6 |
| OD-3 / OD-15 no `db_pass` fallback, fail closed, password-change revocation | Met | G.3 |
| OD-11 every service path fails closed for reseller-owned, Agent/Sales cannot grant in scope | Met | G.1 |
| OD-14 no traces/SQL/paths/secrets for any role; Telegram summary only | Met | G.4 |
| Schema readiness fails closed for every role | Met | G.5 |
| Import tool dry-run only; no import/apply executed | Met | G.6 |
| Push only `origin/wp2/hierarchy-permissions`; no force, PR, merge or deploy | Met | K |

Overall: **`IMPLEMENTED_NOT_ACCEPTED` — waiting for owner review.** WP3A has not been started.
