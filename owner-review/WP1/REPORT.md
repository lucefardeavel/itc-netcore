# Owner Review — WP1 Database Foundation

Final state: `STOPPED_WAITING_OWNER_REVIEW`
WP status: `IMPLEMENTED_NOT_ACCEPTED`. This report does not self-accept; acceptance is recorded only by the owner in `docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md`.

**Revision 2: owner review correction pass.** The owner reviewed candidate `a2c0ade` as `FIX_REQUIRED`. This revision applies exactly the three requested fixes in one normal follow-up commit (no rewrite, no force push):
1. **Manager plan availability:** `tbl_manager_plan_prices.is_enabled` becomes an optional, audited override (R0002 amended in place).
2. **Customer actor identity:** `actor_customer_id` on activations and activation events (R0003 amended in place).
3. **Report accuracy:** the relationship to the upstream baseline is stated precisely (section C).

R0002 and R0003 were amended rather than superseded by an R0006, as instructed: WP1 is not accepted and these migrations were never applied outside disposable test databases. The runner, the other migrations and every other frozen decision are unchanged.

**Supporting documents**
- [`docs/WP1_SCHEMA_CONTRACT.md`](../../docs/WP1_SCHEMA_CONTRACT.md): column-level contract, generated from the migrations.
- [`system/reseller/README.md`](../../system/reseller/README.md): runner operator contract.
- [`tests/README.md`](../../tests/README.md): test suites and fixtures.

## A. Baseline ancestry / accepted prior HEAD / current HEAD / branch / worktree
| Item | Value |
|---|---|
| Upstream baseline (ancestry) | `d3e0596287322944ee21fcc14dc1798158463140` |
| Accepted prior-WP HEAD (WP0, ACCEPTED_WITH_NOTES) | `c2582368d0111046e39c353ca70b782d8fbb78c4` |
| Execution base (owner metadata descendant) | `e609af824a387b5812d88b3a30fb66dd1111713c` |
| Starting HEAD (initial WP1 pass) | `e609af824a387b5812d88b3a30fb66dd1111713c` |
| Previous WP1 candidate (owner review: `FIX_REQUIRED`) | `a2c0adeab351ccee3af17f4205e0d3c7a3deae61` |
| Starting HEAD (correction pass) | `a2c0adeab351ccee3af17f4205e0d3c7a3deae61` |
| Branch | `wp1/database-foundation`, created from `e609af8`, no upstream tracking to `feat/reseller-system-v1` |
| Ancestry proven before implementation | `merge-base --is-ancestor c2582368 HEAD` ✅; `… e609af8 HEAD` ✅; `… d3e0596 HEAD` ✅; `git log c2582368..e609af8` = `56867de`, `e609af8` only |
| Commits on the branch since `e609af8` | 2: `a2c0ade` (WP1 implementation) and the correction commit `fix(wp1): complete pricing audit and activation actor identity` |
| Ending HEAD | the correction commit on `wp1/database-foundation`. A commit cannot contain its own hash; it is stated in the final response and equals `git rev-parse wp1/database-foundation`. |
| Spec manifest | `sha256sum --strict -c MANIFEST.sha256` → 42/42 OK (ledger excluded by design) |
| Worktree | clean at the start of each pass; clean after each commit |
| `main` / `feat/reseller-system-v1` | untouched (`500d3a2` / `e609af8`) |

## B. Scope implemented
**Strict project migration runner** (C13, C28), `system/reseller/migrate.php`:
- CLI-only.
- Commands: `status`, `preflight`, `migrate`, `verify`, `rollback --to=<id>|--all`.
- Preflight is read-only:
  - server minimum MariaDB 10.11 / MySQL 8.0.16;
  - privileges;
  - registry contract;
  - core-schema contract matched against the registered variants;
  - collisions;
  - partial states.
- Every DDL step is journaled before and after execution; any error stops the run.
- Postflight contract verification.
- Durable registry (`tbl_project_migrations`) plus an append-only journal (`tbl_project_migration_journal`).
- States: APPLYING / APPLIED / FAILED_PARTIAL / ROLLBACK_REQUIRED / ROLLING_BACK / ROLLBACK_FAILED / ROLLED_BACK.
- Rollback:
  - removes only registry-proven objects;
  - refuses on drift or when data exists;
  - must restore the recognised pre-migration core fingerprint.
- Per-database `GET_LOCK`.

**Core-schema contract and variant registry** (Q3 modified, C27):
- `tbl_users`, `tbl_customers` and `tbl_plans` must match one explicitly verified variant (V1/V2/V3, MariaDB and MySQL).
- Extra unrelated tables are allowed and reported. No runtime override.

**Schema foundation**, migrations R0001–R0005:
- **R0001 hierarchy:** closure table only, empty, no `root` import (C20).
- **R0002 pricing:**
  - explicit provider wholesale base cost (`tbl_reseller_plan_base_costs`) with actor and timestamp audit (Q4);
  - per-manager wholesale, retail and availability overrides, each optional (NULL = inherit) and each with its own operator + UTC time;
  - **availability (revision 2):** `is_enabled` `NULL` = inherit, `0` = explicitly disabled, `1` = explicitly enabled (still subject to ancestor/global restrictions in WP3B). An explicit value requires `availability_set_by` (FK `tbl_users`, RESTRICT) and `availability_set_at`; `NULL` requires both to be `NULL`. No row = no manager-specific configuration.
- **R0003 activations:** the 4 actions, 15 frozen states, idempotency, snapshots and legacy references, plus an append-only state-transition timeline.
  - **Actor identity (revision 2):** `actor_customer_id INT NULL` (signed, FK `tbl_customers.id`, RESTRICT) on both tables. `STAFF` requires `actor_user_id`, `CUSTOMER` requires `actor_customer_id`, `SYSTEM`/`CRON` may carry neither, `API` has no identity rule yet (WP2). The initiating customer is independent of the target `customer_id`.
- **R0004 finance:** wallets, transfers/funding, the ledger and reservations.
- **R0005 customers:** `tbl_customers.manager_id` / `assigned_sales_id`, nullable INT UNSIGNED, **no backfill** (C19).

**Dev/test infrastructure** (approved):
- PHP 8.2 test container and compose file for disposable MariaDB 10.11 / MySQL 8.0;
- dev-only PHPUnit 11 in `tests/composer.json`;
- `tests/vendor/` added to `.gitignore` (Q2).

**Documentation:** schema contract, runner README, tests README, and this report.

**Not implemented (forbidden in WP1):**
- services of any kind: hierarchy, permission, wallet, pricing, ActivationService;
- RADIUS, MikroTik, UI or PWA changes;
- changes to the production `Dockerfile`;
- legacy schema normalisation or price conversion;
- voucher schema;
- triggers (Q5);
- application-code filtering of the API null fields (Q6);
- any restore behaviour change (C24 → WP2);
- pricing business logic (availability inheritance, ancestor disable: WP3B);
- deploy, merge, PR, or WP2.

## C. Files changed (relative to `e609af8`)
**Correction pass (`a2c0ade` → correction commit), 6 files, all modifications:**
- `system/reseller/migrations/R0002_pricing_foundation.php`: availability override + audit columns, index, FK and checks; contract updated.
- `system/reseller/migrations/R0003_activation_foundation.php`: `actor_customer_id`, indexes, FKs and checks on both tables; contract updated.
- `tests/Integration/ConstraintTest.php`: second customer fixture; 3 new tests.
- `tests/README.md`: ConstraintTest description.
- `docs/WP1_SCHEMA_CONTRACT.md`: decision rows, invariant rows, regenerated column-level contract with new checksums.
- `owner-review/WP1/REPORT.md`: this revision.

No runner class, no other migration, no fixture, no dev tool and no generated `variants.php` changed (the core-schema registry covers only `tbl_users` / `tbl_customers` / `tbl_plans`, which R0002/R0003 do not alter).

**Whole WP1 (both commits):**
| Path | Change |
|---|---|
| `.gitignore` | +3 lines: blank separator, comment, `tests/vendor/` (Q2) |
| `system/reseller/migrate.php` | new: CLI entry |
| `system/reseller/Migration/{Outcome,Db,SchemaInspector,Grants,MigrationDefinition,MigrationSet,VariantRegistry,Registry,Runner}.php` | new: runner |
| `system/reseller/Migration/variants.php` | new: generated core-schema contract (V1–V3 × MariaDB/MySQL) |
| `system/reseller/migrations/R0001…R0005_*.php` | new: 5 migrations |
| `system/reseller/README.md` | new |
| `dev/test/{Dockerfile,compose.yml,run-tests.sh}` | new: dev/test only |
| `dev/tools/build-variant-registry.php` | new: dev-only registry generator / checker |
| `tests/{composer.json,composer.lock,phpunit.xml,bootstrap.php,README.md}` | new |
| `tests/Support/{FixtureBuilder,TestDatabase,RunnerProcess,IntegrationTestCase}.php` | new |
| `tests/Unit/{GrantsTest,MigrationDefinitionsTest}.php` | new |
| `tests/Integration/{PreflightTest,ForwardMigrationTest,RollbackTest,PartialFailureTest,DriftAndRegistryTest,ConstraintTest,CliOnlyTest}.php` | new |
| `tests/Regression/AppRegressionTest.php` | new |
| `tests/fixtures/schema/phpnuxbill.2023-08-09.sql` | new: unmodified copy of `56cea36a:install/phpnuxbill.sql` (V3 fixture) |
| `docs/WP1_SCHEMA_CONTRACT.md` | new |
| `owner-review/WP1/REPORT.md` | new (this file) |

**Relationship to the upstream baseline (corrected in revision 2).** The repository tree is **not** identical to upstream `d3e0596`: WP1 adds new files, including PHP code inside the web-served `system/` directory. Precisely:
- **Legacy PHPNuxBill application files are unchanged.** Relative to `d3e0596` (spec pack excluded), every change is a new file except `.gitignore` (+3 lines). Excluding the project-owned paths (`system/reseller/`, `tests/`, `dev/`, the WP0/WP1 docs, `owner-review/`, `.gitignore`), `git diff --name-only d3e0596 HEAD` is **empty**.
- **WP1 adds a new project-owned CLI migration/schema subsystem under `system/reseller/`** (17 files: `migrate.php`, 9 runner classes, the generated `variants.php`, 5 migrations, `README.md`), plus dev/test tooling under `dev/` and `tests/` (24 files) and documentation. These are new files; every PHP file among them refuses non-CLI execution.
- **No existing controller, device, template, RADIUS or MikroTik application behaviour file was modified.** No file under `system/` outside `system/reseller/` changed; `system/updates.json`, `install/*`, `update.php`, the `Dockerfile` and the composer files under `system/` are untouched.
- The revision-1 wording "Application tree vs `d3e0596` → 0 files differ" was computed with the WP1 paths excluded and overstated the result. It is withdrawn.

## D. Important design notes
- **Migration IDs and checksums** (sha256 of file):

| ID | Steps up/down | Checksum |
|---|---|---|
| `R0001_manager_tree` | 1 / 1 | `a0b52f7fb4bd5f5e317c701d85df6db7d2a95ace04ec1b1112873191e514a5a7` |
| `R0002_pricing_foundation` | 2 / 2 | `dc674b5f892ad00d9f518a1d97a4348be4b30dff91e0cfb192df6badfb6dcac6` (revision 2; was `4f95b48d…85c2`) |
| `R0003_activation_foundation` | 2 / 2 | `60e63156bdc90e9772dd479f69c9507fdc648f98dbbe301ab060ff59fe3f9acd` (revision 2; was `b79e0e0e…45bc`) |
| `R0004_wallet_finance_foundation` | 4 / 4 | `1005721df99db4b8370f225d874b4d99031a1c538f0e000c7b8ace37c080e796` |
| `R0005_customer_ownership` | 1 / 4 | `b69135eab8b70f3bed2355946d959be356c3d48897df9db6cb41c6c7e45b3854` |

  `variants.php` checksum: `4d9f7f8a38c81d29164a474aaa36a0d29ef10bc7c12beecdc37f02b391022202` (unchanged in revision 2).

- **Supported schema variants** (core fingerprints identical on MariaDB 10.11.19 and MySQL 8.0.46):

| Variant | Fingerprint |
|---|---|
| `V1_FRESH_INSTALL` | `3ca25964…6b3` |
| `V2_FRESH_INSTALL_UPDATED` | `6c2f04ea…1a9` |
| `V3_LONGLIVED_2023_UPDATED` | `e1a66aa3…afc` |

  Anything else gives NOT_READY.
- **Partial DDL.** MySQL/MariaDB DDL is non-transactional, so each step is exactly one atomic DDL statement and the legacy-table change (R0005) is a single atomic ALTER executed last. Completed steps are durably journaled; an interrupted run is detected under the lock and recorded as ROLLBACK_REQUIRED.
- **JSON portability.** `LONGTEXT` + named `CHECK (JSON_VALID(...))` avoids the MariaDB-alias versus MySQL-native JSON drift. CHECK constraints are compared by name.
- **Foreign keys.** All are RESTRICT/RESTRICT; there is no cascade or SET NULL. There are no FKs to volatile legacy rows (transactions, recharges, payment-gateway rows).
- **Plan availability** is an optional override with its own audit: `ck_mpp_enabled_bool` (`NULL`, `0` or `1` only) and `ck_mpp_availability_audit` (`NULL` ⇔ both audit fields `NULL`; `0`/`1` ⇔ both set). Both checks only use `IS [NOT] NULL` / `IN`, so they never evaluate to UNKNOWN.
- **Actor identity:** `ck_act_staff_actor` / `ck_evt_staff_actor` (STAFF ⇒ `actor_user_id`) and `ck_act_customer_actor` / `ck_evt_customer_actor` (CUSTOMER ⇒ `actor_customer_id`). The actor customer is a separate column from the target `customer_id`, with its own RESTRICT FK.
- **Activation events** are a full transition timeline. `from_state` may be NULL only for the initial event into CREATED; the provisioning result, error fields, actor and JSON metadata are attached per event. All 225 (from, to) pairs plus the initial event are representable (tested).
- **Test hooks.** Failure and crash injection are inert unless `NUXR_MIGRATION_TESTING=1`. They can only cause failures.

## E. Migrations / data impact
- **Production:** no data impact; nothing was applied anywhere except disposable test containers.
- **Shape of the change on a migrated database:**
  - 9 new empty domain tables + 2 runner registry tables (revision 2 adds columns to 3 of the new tables only);
  - `tbl_customers` gains 2 NULL columns, 2 indexes and 2 FKs.
- **Data:** no existing row is modified (tests T16/T17 compare data hashes).
- **Rollback:** `rollback --all` restores the exact pre-migration core fingerprint and every legacy table; the registry history is kept.

## F. Test commands and exact results
**Environment**
- PHP 8.2.34 (`php:8.2-cli` + `pdo_mysql`; `dev/test/Dockerfile`);
- MariaDB 10.11.19 and MySQL 8.0.46 containers (tmpfs, random root password, torn down afterwards);
- PHPUnit 11.5.56 (dev-only).

**Command:** `dev/test/run-tests.sh`, the complete matrix (not only the new tests), re-run after the revision-2 fixes. It was run twice end to end (plain and `--testdox`) with identical results, fresh disposable containers each time.

| Engine | Tests | Assertions | Result |
|---|---|---|---|
| MariaDB 10.11.19 | 72 | 1482 | **OK** |
| MySQL 8.0.46 | 72 | 1482 | **OK** |
| Variant registry reproducibility (`build-variant-registry.php --check`, both engines) | — | — | **matches freshly built fixtures** |

Revision 1 (`a2c0ade`) for comparison: 69 tests / 1401 assertions per engine, all OK.

**Per class (per engine):**
- unit: `GrantsTest` 7, `MigrationDefinitionsTest` 8;
- integration: `PreflightTest` 18, `ForwardMigrationTest` 8, `RollbackTest` 6, `PartialFailureTest` 7, `DriftAndRegistryTest` 6, `ConstraintTest` 9 (6 + 3 new in revision 2), `CliOnlyTest` 2;
- regression: `AppRegressionTest` 1.

Total: 15 unit + 56 integration + 1 regression = **72**.

**Other checks**

| Check | Result |
|---|---|
| `php -l` on the 32 WP1 PHP files (after revision 2), PHP 8.2.34 | 32 pass, 0 fail |
| `php -l` on the 32 WP1 PHP files (after revision 2), PHP 8.3.6 | 32 pass, 0 fail |
| Manual smoke (revision 1, before the suite): preflight / migrate / migrate / status / verify / rollback --all / migrate on V1 | exit 0 for each step on both engines |
| Negative control (revision 2): the 3 new tests against the revision-1 R0002/R0003 | fail on both engines (unknown column), as expected |
| Legacy-file diff vs `e609af8` | empty (apart from `.gitignore` +3) |

## G. Security / failure cases tested
**Forward / rollback (per variant, both engines; re-verified on the revision-2 schema)**
- **Forward (V1, V2, V3):** contracts exact; new tables empty.
- **Second run:** zero DDL, proven by the server `Com_create_table`, `Com_alter_table`, `Com_drop_table`, `Com_create_index`, `Com_drop_index` and `Com_rename_table` counters; no journal rows written.
- **Rollback (V1, V2, V3):** the pre-migration core fingerprint is restored exactly, and every legacy table is identical to its pre-migration snapshot. Forward after rollback succeeds.

**Partial-failure recovery (C28; owner additions 3–4; re-verified on the revision-2 schema)**
- **Injected failure at R0004 step 3 (V1, V2, V3):**
  - exit 3, `FAILED_PARTIAL`, `steps_completed=2`;
  - journal shows steps 1–2 COMPLETED and step 3 FAILED;
  - R0005 never starts;
  - a second `migrate` exits 2 with no change;
  - `status` and `verify` exit 2 and report "FAILED_PARTIAL after 2/4 steps";
  - `rollback --all` restores the prior core fingerprint exactly, skipping the never-created objects as absent;
  - `migrate` afterwards succeeds.
- **Crash after the R0002 step 1 DDL:** state APPLYING, journal STARTED without COMPLETED → `status` exits 2 → `migrate` records ROLLBACK_REQUIRED and exits 2 → `rollback` recovers → `migrate` succeeds.
- **Failure of the single customer ALTER (V3):** `tbl_customers` unchanged → `rollback --to=R0004` → `migrate` succeeds.
- **Rollback step failure:** ROLLBACK_FAILED → `migrate` and `verify` exit 2 → resumed `rollback` restores the fingerprint.
- **Postflight negative control:** a wrong contract gives FAILED_PARTIAL and a journaled POSTFLIGHT_FAILED, is never marked APPLIED, and blocks further runs.

**Preflight (owner additions 1–2)**
- **Extra unrelated tables** (a plugin table + the FreeRADIUS schema): preflight passes, they are reported and untouched, and migration succeeds.
- **Incompatible required-core changes** each give exit 2 with **zero mutation** and no registry created:
  - signed `tbl_users.id`;
  - MyISAM `tbl_customers`;
  - utf8mb3 `tbl_customers`;
  - an unknown column on `tbl_customers`;
  - `tbl_plans.price` type changed;
  - `tbl_users.root` dropped;
  - `tbl_plans.id` BIGINT;
  - an extra index on `tbl_customers`;
  - `tbl_plans` missing.
- **Also refused before mutation:**
  - an empty database;
  - collisions (an unregistered `tbl_manager_ledger`, a pre-existing `manager_id`, an FK name already in use);
  - a missing REFERENCES privilege;
  - a concurrent runner (lock).

**Drift**
- A manual change to a project table gives exit 4 on `verify`, `migrate` and `rollback`; the object is not dropped.
- An edited applied migration file gives exit 4.
- A tampered registry, or an unknown registry id, gives exit 2.

**Owner review fixes (revision 2; 3 new tests in `ConstraintTest`, both engines)**
- `testManagerPlanAvailabilityOverrideIsOptionalAndAudited`:
  1. a pricing-only manager/plan row stores `is_enabled = NULL` with no availability actor or time (default), and explicit `NULL` needs no audit;
  2. `NULL` together with `availability_set_by` and/or `availability_set_at` is rejected;
  3. `is_enabled = 0` without the actor, without the time, or without both is rejected; with both it is stored;
  4. `is_enabled = 1`: the same three rejections, and stored with both;
  5. invalid values `2`, `-1`, `127` and `'yes'` are rejected on insert, and `2` on update;
  6. an unknown `availability_set_by` is rejected by the FK;
  7. returning to inherit requires clearing all three fields together; a user recorded as availability actor cannot be deleted (RESTRICT).
- `testCustomerActorIdentityOnActivations`:
  1. customer **A** initiates an activation whose target is customer **B**; both identities persist separately (`actor_customer_id = A`, `customer_id = B`, A ≠ B), and a customer may also act for themself;
  2. `CUSTOMER` without `actor_customer_id` is rejected, including when only a staff `actor_user_id` is given;
  3. an unknown `actor_customer_id` is rejected by the FK;
  4. `STAFF` without `actor_user_id` is still rejected, including when only a customer id is given;
  5. `SYSTEM`, `CRON` and `API` with neither identity are accepted;
  6. a customer referenced **only** as an initiator cannot be deleted (RESTRICT via `fk_act_actor_customer`), and the identity of a CUSTOMER row cannot be removed.
- `testCustomerActorIdentityOnActivationEvents`: `CUSTOMER` event without `actor_customer_id` rejected; unknown `actor_customer_id` rejected by the FK; `STAFF` event without `actor_user_id` rejected; a `CUSTOMER` event records initiator A on an activation targeting B, next to `SYSTEM` and `STAFF` events.
- **Negative control:** the three new tests were also run against the revision-1 migrations (`a2c0ade` versions of R0002/R0003). They fail on both engines (`Unknown column 'availability_set_by'` / `'actor_customer_id'`), so they detect the defects the owner identified.

**Constraints:**
- unique idempotency and reference;
- the CHANGE_PLAN previous-plan check;
- JSON validity;
- amount and non-negative checks;
- the STAFF and CUSTOMER actor checks (activations and events);
- closure uniqueness and the depth/self check;
- one base cost per plan, with resale disabled by default;
- the manager/plan uniqueness and audit checks, including the optional audited availability override;
- the wallet floor and debt limit;
- transfer shape, single reversal and unique reference;
- ledger arithmetic, positive amounts, the unique entry key, single reversal and the wallet-required FK;
- one ACTIVE reservation per activation (history allowed), resolution and commit-link checks;
- RESTRICT on user, plan, customer and transfer deletes when referenced.

**Activation timeline (owner addition 5):** the initial event plus all 15×15 (from, to) pairs are inserted; NULL `from_state` into a non-CREATED state is rejected; provisioning SUCCESS/FAILED/UNKNOWN is attached to transitions.

**CLI-only (owner addition 6)**
- The repository root was served over HTTP without any firewall. All of these return **404 with an empty body**, and the server's `Connections` counter is unchanged, proving no database contact:
  - the runner;
  - two migrations;
  - three runner classes;
  - the variant registry;
  - the test bootstrap and support files;
  - a test class;
  - the dev tool.
- All 32 new PHP files carry the guard. A control request to `/version.json` returns 200.

**Application regression (V2, before vs after migration, same results)**
- A legacy customer insert gives a NULL owner and Sales assignment.
- A Dummy-device `rechargeUser` returns `INV-…`, status `on`, 1 transaction.
- Legacy user delete and plan delete succeed.
- `cron.php` exits 0 and finishes.
- `admin`, `login` and `register` pages return HTTP 200 HTML with no fatal error.
- Results are identical apart from the owner-accepted presence of the two NULL fields (Q6/C25).

## H. Git diff summary
- **Two commits** on `wp1/database-foundation`: `a2c0ade` and the correction commit. No history rewrite.
- **Correction commit:** 6 files modified (listed in C); 0 added, 0 deleted.
- **Whole WP1 vs `e609af8`:** 44 files: 43 new files (including this report) plus `.gitignore` (+3 lines); 0 deletions; no legacy application file modified.
- **Content:** the runner (2,086 lines PHP: `migrate.php` + 9 classes), the 5 migrations (789 lines), the generated `variants.php` (642 lines), tests (1,868 lines PHP), the dev tooling, the V3 fixture SQL copy (643 lines) and docs.

## I. Known risks / TODOs (unresolved; no owner decision implied)
1. **Real installs outside V1–V3 are refused (C27, by design).** Deployments made with other installer generations or partial `update.php` runs (e.g. 2017-era utf8mb3 installs, hand-repaired broken-installer installs) need a reviewed registry addition with fixture evidence before WP8 staging.
2. **Newer engines are untested.** MariaDB 11.x and MySQL 8.4/9.x meet the approved *minimum* rule but are not tested. Their core contract must still match, so the likely failure mode is NOT_READY. A maximum-version policy is an open owner choice.
3. **C24 legacy restore (assigned to WP2).** The restore can TRUNCATE new-domain tables with FK checks off (session and GLOBAL). Unchanged in WP1.
4. **Legacy delete paths**, once referencing rows exist, will hit RESTRICT:
   - user delete → uncaught error (**WP2**); this now also covers users recorded as availability actors;
   - customer delete → unprovisions first, then swallows the error (**WP2/WP5B**); this now also covers customers recorded only as the initiating actor of another customer's activation;
   - plan delete → removes from the router/RADIUS first (**WP3B**).
5. **Strict session mode for writers.** New-domain writers must use a strict `sql_mode`; otherwise invalid ENUM values become `''`. CHECK and FK constraints are enforced regardless. This is a WP3A/WP5A requirement.
6. **Production ALTER.** `ALTER TABLE tbl_customers` with FKs may rebuild the table on MySQL 8 in production. It needs a backup, preflight and a maintenance window (runbook WP8/WP10).
7. **Rollback is a pre-data safety net.** Once new-domain data exists, rollback refuses and restore from backup is the path (WP10).
8. **Recovery from a defective shipped migration.** If a shipped migration contract is wrong and its apply fails, its recorded checksum ties recovery to the same file version. A corrected file is reported as drift and needs manual review. This is caught by the test suite before release.
9. **Deployment hygiene.** `tests/` and `dev/` must be excluded from production deployments (web root = repository root). CLI guards mitigate this (WP10 runbook item).
10. **PHPUnit fetching.** The dev-only PHPUnit is fetched by `composer --prefer-source` from GitHub; `tests/composer.lock` pins versions.
11. **WP0 notes remain open:** PDF/gd runtime path not exercised; authenticated UI reviewed statically only; external payment-gateway/plugin repositories outside scope.
12. **API exposure (Q6/C25).** API customer payloads now expose `manager_id: null` / `assigned_sales_id: null` (accepted; WP2 owns API authorization).
13. **Ledger customer identity (observation; not changed, outside the requested fix scope).** `tbl_manager_ledger.actor_type` (R0004) also allows `CUSTOMER` but has no customer-identity column. For activation-linked entries the initiator is reachable through `activation_id → actor_customer_id`; a non-activation ledger entry with a `CUSTOMER` actor would carry no identity. Owner decision: leave as is, or add the same column/check to R0004 before acceptance.
14. **Audit-rule asymmetry (observation; not changed).** The availability override now requires its audit fields to be `NULL` when the value is `NULL`. The existing wholesale/retail checks (`ck_mpp_wholesale_audit`, `ck_mpp_retail_audit`) only require the audit when a value is set, so a cleared wholesale/retail override may keep its last actor/time. Owner decision whether to align them.
15. **Row with no overrides.** A manager/plan row with all three overrides `NULL` is allowed and is semantically the same as no row. WP3B must treat both identically.

## J. Production status
Untouched. No production or staging database, RADIUS, MikroTik or external target was contacted. All databases were disposable containers, torn down after the final run.

## K. Push status
Normal pushes of `wp1/database-foundation` to `origin` (owner-authorised): `a2c0ade` (revision 1), then the correction commit as a fast-forward. No force, no PR, no merge into `feat/reseller-system-v1` or `main`, no deploy. The result is stated in the final response.

## L. Owner-review artifact path
`owner-review/WP1/REPORT.md` (this file)

## M. Final state
`STOPPED_WAITING_OWNER_REVIEW`

## Definition of Done assessment (WP1)
| DoD item | Status | Evidence |
|---|---|---|
| Requested scope implemented, nothing knowingly omitted | Met | B |
| No unauthorized adjacent WP work | Met | B (not implemented), C |
| Relevant automated tests added/run, exact results | Met | F |
| Negative/security/failure cases | Met | G |
| Migrations / data impact documented | Met | D, E, schema contract |
| Changed files and diff summary | Met | C, H |
| Docs for decisions in scope | Met | schema contract §1, runner README |
| Durable owner-review artifact | Met | L |
| No secrets committed | Met (sweep in the final response; test passwords are random environment values only) | — |
| No unexplained dirty worktree | Met | A |
| Known risks listed | Met | I |
| Production untouched | Met | J |
| Owner amendments: C28 states/journal, Q3 core contract + extra-table tests, CLI-only, activation timeline, JSON portability, test additions 1–6 | Met | B, D, G |
| Owner review fix 1: optional audited availability override (NULL / 0 / 1, FK + CHECKs), R0002 amended in place | Met | B, D, G |
| Owner review fix 2: customer actor identity on activations and events, initiator ≠ target, R0003 amended in place | Met | B, D, G |
| Owner review fix 3: accurate baseline wording | Met | C |
| Complete matrix re-run after the fixes (MariaDB 10.11, MySQL 8.0, PHP 8.2) | Met | F |

Overall: **`IMPLEMENTED_NOT_ACCEPTED` — waiting for owner review.** WP2 has not been started.
