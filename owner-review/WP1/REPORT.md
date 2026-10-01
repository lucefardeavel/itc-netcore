# Owner Review — WP1 Database Foundation

Final state: `STOPPED_WAITING_OWNER_REVIEW`
WP status: `IMPLEMENTED_NOT_ACCEPTED`. This report does not self-accept; acceptance is recorded only by the owner in `docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md`.

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
| Starting HEAD | `e609af824a387b5812d88b3a30fb66dd1111713c` |
| Branch | `wp1/database-foundation`, created from `e609af8`, no upstream tracking to `feat/reseller-system-v1` |
| Ancestry proven before implementation | `merge-base --is-ancestor c2582368 HEAD` ✅; `… e609af8 HEAD` ✅; `… d3e0596 HEAD` ✅; `git log c2582368..e609af8` = `56867de`, `e609af8` only |
| Ending HEAD | the single WP1 commit on `wp1/database-foundation`. A commit cannot contain its own hash; it is stated in the final response and equals `git rev-parse wp1/database-foundation`. |
| Spec manifest | `sha256sum --strict -c MANIFEST.sha256` → 42/42 OK (ledger excluded by design) |
| Worktree | clean at start; clean after commit |
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
  - per-manager wholesale, retail and availability overrides.
- **R0003 activations:** the 4 actions, 15 frozen states, idempotency, snapshots and legacy references, plus an append-only state-transition timeline.
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
- deploy, merge, PR, or WP2.

## C. Files changed (relative to `e609af8`)
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

**Legacy files untouched:**
- `git diff --name-only e609af8 -- . ':!system/reseller' ':!tests' ':!dev' ':!docs/WP1_SCHEMA_CONTRACT.md' ':!owner-review/WP1' ':!.gitignore'` → **empty**.
- Application tree vs `d3e0596` → **0 files** differ.
- Not touched: `system/updates.json`, `install/*`, `update.php`, the `Dockerfile`, composer files under `system/`, any controller, template, device or RADIUS/MikroTik code.

## D. Important design notes
- **Migration IDs and checksums** (sha256 of file):

| ID | Steps up/down | Checksum |
|---|---|---|
| `R0001_manager_tree` | 1 / 1 | `a0b52f7fb4bd5f5e317c701d85df6db7d2a95ace04ec1b1112873191e514a5a7` |
| `R0002_pricing_foundation` | 2 / 2 | `4f95b48d931bd3ee237ee43b060e2c3d4d8bae923c939c0b0796c668a2ac85c2` |
| `R0003_activation_foundation` | 2 / 2 | `b79e0e0eb01c0c091228b49a42fdaa3ca813d904cfa2c1875adee9d0373e45bc` |
| `R0004_wallet_finance_foundation` | 4 / 4 | `1005721df99db4b8370f225d874b4d99031a1c538f0e000c7b8ace37c080e796` |
| `R0005_customer_ownership` | 1 / 4 | `b69135eab8b70f3bed2355946d959be356c3d48897df9db6cb41c6c7e45b3854` |

  `variants.php` checksum: `4d9f7f8a38c81d29164a474aaa36a0d29ef10bc7c12beecdc37f02b391022202`.

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
- **Activation events** are a full transition timeline. `from_state` may be NULL only for the initial event into CREATED; the provisioning result, error fields, actor and JSON metadata are attached per event. All 225 (from, to) pairs plus the initial event are representable (tested).
- **Test hooks.** Failure and crash injection are inert unless `NUXR_MIGRATION_TESTING=1`. They can only cause failures.

## E. Migrations / data impact
- **Production:** no data impact; nothing was applied anywhere except disposable test containers.
- **Shape of the change on a migrated database:**
  - 9 new empty domain tables + 2 runner registry tables;
  - `tbl_customers` gains 2 NULL columns, 2 indexes and 2 FKs.
- **Data:** no existing row is modified (tests T16/T17 compare data hashes).
- **Rollback:** `rollback --all` restores the exact pre-migration core fingerprint and every legacy table; the registry history is kept.

## F. Test commands and exact results
**Environment**
- PHP 8.2.34 (`php:8.2-cli` + `pdo_mysql`; `dev/test/Dockerfile`);
- MariaDB 10.11.19 and MySQL 8.0.46 containers (tmpfs, random root password, torn down afterwards);
- PHPUnit 11.5.56 (dev-only).

**Command:** `dev/test/run-tests.sh` (final clean run).

| Engine | Tests | Assertions | Result |
|---|---|---|---|
| MariaDB 10.11.19 | 69 | 1401 | **OK** |
| MySQL 8.0.46 | 69 | 1401 | **OK** |
| Variant registry reproducibility (`build-variant-registry.php --check`, both engines) | — | — | **matches freshly built fixtures** |

**Per class (per engine):**
- unit: `GrantsTest` 7, `MigrationDefinitionsTest` 8;
- integration: `PreflightTest` 18, `ForwardMigrationTest` 8, `RollbackTest` 6, `PartialFailureTest` 7, `DriftAndRegistryTest` 6, `ConstraintTest` 6, `CliOnlyTest` 2;
- regression: `AppRegressionTest` 1.

Total: 15 unit + 53 integration + 1 regression = **69**.

**Other checks**

| Check | Result |
|---|---|
| `php -l` on the 32 new PHP files, PHP 8.2.34 | 32 pass, 0 fail |
| `php -l` on the 32 new PHP files, PHP 8.3.6 | 32 pass, 0 fail |
| Manual smoke (before the suite): preflight / migrate / migrate / status / verify / rollback --all / migrate on V1 | exit 0 for each step on both engines |
| Legacy-file diff vs `e609af8` | empty (apart from `.gitignore` +3) |

## G. Security / failure cases tested
**Forward / rollback (per variant, both engines)**
- **Forward (V1, V2, V3):** contracts exact; new tables empty.
- **Second run:** zero DDL, proven by the server `Com_create_table`, `Com_alter_table`, `Com_drop_table`, `Com_create_index`, `Com_drop_index` and `Com_rename_table` counters; no journal rows written.
- **Rollback (V1, V2, V3):** the pre-migration core fingerprint is restored exactly, and every legacy table is identical to its pre-migration snapshot. Forward after rollback succeeds.

**Partial-failure recovery (C28; owner additions 3–4)**
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

**Constraints:**
- unique idempotency and reference;
- the CHANGE_PLAN previous-plan check;
- JSON validity;
- amount and non-negative checks;
- the STAFF actor check;
- closure uniqueness and the depth/self check;
- one base cost per plan, with resale disabled by default;
- the manager/plan uniqueness and audit checks;
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
- **One commit** on `wp1/database-foundation`.
- **44 files:** 43 new files (including this report) plus `.gitignore` (+3 lines); 0 deletions; no legacy application file modified.
- **Content:** the runner (2,086 lines PHP: `migrate.php` + 9 classes), the 5 migrations (789 lines), the generated `variants.php` (642 lines), tests (1,868 lines PHP), the dev tooling, the V3 fixture SQL copy (643 lines) and docs.

## I. Known risks / TODOs (unresolved; no owner decision implied)
1. **Real installs outside V1–V3 are refused (C27, by design).** Deployments made with other installer generations or partial `update.php` runs (e.g. 2017-era utf8mb3 installs, hand-repaired broken-installer installs) need a reviewed registry addition with fixture evidence before WP8 staging.
2. **Newer engines are untested.** MariaDB 11.x and MySQL 8.4/9.x meet the approved *minimum* rule but are not tested. Their core contract must still match, so the likely failure mode is NOT_READY. A maximum-version policy is an open owner choice.
3. **C24 legacy restore (assigned to WP2).** The restore can TRUNCATE new-domain tables with FK checks off (session and GLOBAL). Unchanged in WP1.
4. **Legacy delete paths**, once referencing rows exist, will hit RESTRICT:
   - user delete → uncaught error (**WP2**);
   - customer delete → unprovisions first, then swallows the error (**WP2/WP5B**);
   - plan delete → removes from the router/RADIUS first (**WP3B**).
5. **Strict session mode for writers.** New-domain writers must use a strict `sql_mode`; otherwise invalid ENUM values become `''`. CHECK and FK constraints are enforced regardless. This is a WP3A/WP5A requirement.
6. **Production ALTER.** `ALTER TABLE tbl_customers` with FKs may rebuild the table on MySQL 8 in production. It needs a backup, preflight and a maintenance window (runbook WP8/WP10).
7. **Rollback is a pre-data safety net.** Once new-domain data exists, rollback refuses and restore from backup is the path (WP10).
8. **Recovery from a defective shipped migration.** If a shipped migration contract is wrong and its apply fails, its recorded checksum ties recovery to the same file version. A corrected file is reported as drift and needs manual review. This is caught by the test suite before release.
9. **Deployment hygiene.** `tests/` and `dev/` must be excluded from production deployments (web root = repository root). CLI guards mitigate this (WP10 runbook item).
10. **PHPUnit fetching.** The dev-only PHPUnit is fetched by `composer --prefer-source` from GitHub; `tests/composer.lock` pins versions.
11. **WP0 notes remain open:** PDF/gd runtime path not exercised; authenticated UI reviewed statically only; external payment-gateway/plugin repositories outside scope.
12. **API exposure (Q6/C25).** API customer payloads now expose `manager_id: null` / `assigned_sales_id: null` (accepted; WP2 owns API authorization).

## J. Production status
Untouched. No production or staging database, RADIUS, MikroTik or external target was contacted. All databases were disposable containers, torn down after the final run.

## K. Push status
Normal push of `wp1/database-foundation` to `origin` (owner-authorised). No force, no PR, no merge into `feat/reseller-system-v1` or `main`, no deploy. The result is stated in the final response.

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

Overall: **`IMPLEMENTED_NOT_ACCEPTED` — waiting for owner review.** WP2 has not been started.
