# Reseller project test suite (development only)

Never deployed: `tests/` and `dev/` must be excluded from production deployments, because the web root is the repository root. Every PHP file here also refuses non-CLI execution.

## Running
```
composer install --working-dir=tests --prefer-source   # dev-only PHPUnit 11 into tests/vendor (gitignored)
dev/test/run-tests.sh                                  # PHP 8.2 container × {MariaDB 10.11, MySQL 8.0}
dev/test/run-tests.sh --filter PartialFailureTest      # extra args go to PHPUnit
```

`run-tests.sh` does the following:
1. Starts disposable `mariadb:10.11` and `mysql:8.0` containers (tmpfs, no published ports, random root password).
2. Builds the `php:8.2-cli` + `pdo_mysql` image from `dev/test/Dockerfile`.
3. Runs the suites once per engine (`WP1_DB_HOST=mariadb|mysql`).
4. Checks that `system/reseller/Migration/variants.php` equals what the fixtures produce (`dev/tools/build-variant-registry.php --check`).

The production `Dockerfile` is not used or changed.

## Fixtures (WP0-verified baseline variants)
| Variant | Source |
|---|---|
| `V1_FRESH_INSTALL` | `install/phpnuxbill.sql` (current installer) |
| `V2_FRESH_INSTALL_UPDATED` | V1 + `system/updates.json` applied with legacy `update.php` semantics |
| `V3_LONGLIVED_2023_UPDATED` | `fixtures/schema/phpnuxbill.2023-08-09.sql` + `system/updates.json` |

**About `fixtures/schema/phpnuxbill.2023-08-09.sql`.** It is a byte copy of `git show 56cea36a:install/phpnuxbill.sql` (2023-08-09, the commit that introduced `system/updates.json`), sha256 `cd60da943fcdebb7b6419875f8b0f78cdefe96abdf31b7f1953a7e2220d78a14`. It is upstream PHPNuxBill material, kept unmodified, so tests do not depend on deep git history.

**About `Support/FixtureBuilder::applyLegacyUpdates()`.** It deliberately reproduces the legacy updater's error swallowing, and it does so **only** to build realistic fixtures. The project runner never ignores an error.

## Suites
| Suite | Class | Covers |
|---|---|---|
| unit | `GrantsTest` | privilege parsing for the preflight (global, db-level, escaped/wildcard names, roles not assumed) |
| unit | `MigrationDefinitionsTest` | ordered ids; only R0005 touches a legacy table and runs last; one DDL statement per step, no DML; RESTRICT-only FKs; unsigned user refs; DECIMAL(18,2) money; UTC DATETIME(6); no voucher schema; reverse-order down steps |
| integration | `PreflightTest` | each variant recognised with zero mutation; extra unrelated tables (plugin + FreeRADIUS) pass and are reported; incompatible required-core changes fail before mutation; empty DB; collisions; missing privilege |
| integration | `ForwardMigrationTest` | forward per variant; contracts; empty new tables; NULL ownership with no assignment; legacy customer/user/plan data byte-identical; legacy price columns unchanged; no voucher schema; durable registry + complete journal; second run executes zero DDL (server `Com_*` counters) and writes nothing; FK signedness/rules from the catalog |
| integration | `RollbackTest` | full rollback per variant restores the pre-migration core fingerprint and every legacy table; history retained; forward after rollback; `--to` partial rollback; data guard |
| integration | `PartialFailureTest` | injected step failure per variant → FAILED_PARTIAL, durable step journal, migrate blocked, status/verify report, rollback restores the recognised core, forward again; crash → APPLYING → ROLLBACK_REQUIRED; atomic customer ALTER failure; failed rollback resumed; postflight negative control |
| integration | `DriftAndRegistryTest` | manual change to a project table; edited applied migration file; tampered registry; unknown registry id; lock; variant registry reproducibility |
| integration | `ConstraintTest` | unique / CHECK / FK behaviour of every new table; every frozen activation state transition representable |
| integration | `CliOnlyTest` | HTTP requests to the runner, migrations, registry, tests and dev tools return 404 with no output and no DB connection; every new PHP file carries the guard |
| regression | `AppRegressionTest` | legacy flows (customer create, Dummy-device recharge, user/plan delete, cron, HTTP boot) identical on an unmigrated vs a migrated V2 database |
