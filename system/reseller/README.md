# Project migration runner (reseller domain)

Owner decisions C13 (strict project migrations) and C28 (partial-failure journaling).
Introduced in WP1. **CLI only.**

This runner is the **only** authoritative mechanism for reseller-domain schema changes. The legacy `system/updates.json` / `update.php` path is not used for them, because that path swallows SQL errors and marks versions as done regardless.

```
php system/reseller/migrate.php <command> [options]
```

## Commands
| Command | Mutates | Purpose |
|---|---|---|
| `status` | no | registry state of every migration, recognised variant, extra tables, blocking states |
| `preflight` | no | full read-only readiness check for `migrate` (server version, privileges, core contract, collisions, registry) |
| `migrate` | yes | preflight → apply pending migrations in order → postflight contract per migration |
| `verify` | no | re-check every APPLIED migration's contract, the core contract and file checksums (drift detection) |
| `rollback --to=<id>` / `rollback --all` | yes | defined recovery / removal flow in strict reverse order |

## Options
- `--config=<path>`: the legacy `config.php` to read `$db_host`, `$db_name`, `$db_user` and `$db_pass` from. Defaults to `<repo>/config.php`.
  - The runner uses the same DSN shape as the application (`mysql:host=$db_host;dbname=$db_name`), so it always targets the application's database.
- `--db-host=`, `--db-name=`, `--db-user=`, `--db-password-env=<VAR>`: an explicit connection, used by the test suite. The password is only ever read from an environment variable.
- `--json`: machine-readable report.

## Exit codes
| Code | Name | Meaning |
|---|---|---|
| 0 | OK | success, or nothing to do |
| 2 | NOT_READY | refused before any mutation. Causes: unknown or incompatible core schema, unsupported server, missing privileges, collision, lock held, a partial state that requires `rollback`, or the rollback data guard |
| 3 | FAILED | a step or postflight failed; the partial state is recorded and blocks everything except `rollback` |
| 4 | DRIFT | an applied object or migration file no longer matches its contract or checksum |
| 5 | USAGE | invalid command, option or configuration |

## Supported databases (owner decision Q1)
- **Minimums:** MariaDB **10.11+** and MySQL **8.0.16+**, enforced by preflight.
- **Tested:** MariaDB 10.11 and MySQL 8.0.x (`dev/test/run-tests.sh`).
- **Newer major versions** meet the minimum rule but are untested; their core contract still has to match a registered variant.

## Core-schema contract (owner decisions Q3 / C27)
- **Contract scope:** the exact canonical definitions of the legacy tables WP1 depends on: `tbl_users`, `tbl_customers` and `tbl_plans`. That means columns, types, signedness, nullability, defaults, indexes, foreign keys, engine and collation.
- **Registered variants:** the contract must equal one registered, explicitly verified variant in `Migration/variants.php`, which is generated from the WP0 fixtures:
  - `V1_FRESH_INSTALL`;
  - `V2_FRESH_INSTALL_UPDATED`;
  - `V3_LONGLIVED_2023_UPDATED`.
- **Unknown or incompatible core schema:** an unknown core table shape, column, index or type gives NOT_READY before any mutation. **There is no runtime override.** Supporting another variant requires a reviewed source change and tests (`dev/tools/build-variant-registry.php`).
- **Other tables:** tables outside the core set (plugins, FreeRADIUS, deployment extensions) are reported as `extra tables`. They do not block migration and are never touched.

## State model and journal (C28)
**Registry tables.** These are runner-owned. They are never created by a migration file and never deleted by the runner.
- `tbl_project_migrations`: current state per migration.
- `tbl_project_migration_journal`: append-only history of every run, step start and completion, state change and postflight result.

| State | Meaning |
|---|---|
| `APPLYING` | forward run in progress (or died: converted to `ROLLBACK_REQUIRED` by the next locked run) |
| `APPLIED` | every step + postflight succeeded |
| `FAILED_PARTIAL` | a step or the postflight failed; completed steps are known from `steps_completed` and the journal |
| `ROLLBACK_REQUIRED` | an interrupted forward run was detected |
| `ROLLING_BACK` | rollback in progress (or died: converted to `ROLLBACK_FAILED`) |
| `ROLLBACK_FAILED` | a rollback step failed; running `rollback` again resumes (objects already removed are skipped) |
| `ROLLED_BACK` | all objects of the migration removed and verified absent |

**Blocking states:** any state other than `APPLIED` or `ROLLED_BACK`. While one exists:
- ordinary `migrate` fails closed (exit 2);
- no later migration runs;
- `status` and `verify` report it (exit 2);
- only `rollback` may continue.

## Guarantees
- **Preflight is read-only** and runs before any mutation, including before the registry is bootstrapped.
- **Every DDL step is journaled** before and after execution. Any error stops the run: there is no catch-and-continue anywhere.
- **APPLIED only after full success.** A migration is APPLIED only after all its steps succeed and its postflight contract check passes. The postflight also confirms the legacy core still matches the recognised variant.
- **Second run.** A second `migrate` on an up-to-date database executes **zero** DDL and writes nothing.
- **Concurrency.** `GET_LOCK` on a per-database name prevents concurrent runners.
- **Session settings.** The runner's session uses strict `sql_mode`, `time_zone='+00:00'` and `foreign_key_checks=1`.
- **Rollback safety:**
  - it drops only objects the migration created;
  - it refuses (exit 4) if those objects drifted from their contract;
  - it refuses (exit 2) if any new-domain table holds rows or any `tbl_customers.manager_id` / `assigned_sales_id` is non-NULL;
  - after a full rollback the core fingerprint must equal the fingerprint recorded before the first migration.

## Operations (staging/production — later WPs; never applied by WP1)
1. Take a verified database backup immediately before `migrate`.
2. Run `preflight`. Only proceed on exit 0.
3. Run `migrate` in a maintenance window. `ALTER TABLE tbl_customers` with foreign keys may rebuild the table on MySQL 8.
4. Run `verify`.
5. Once new-domain data exists, schema rollback is refused. Recovery is restore from backup, per the WP10 runbook.

## Test hooks (inert unless enabled)
`NUXR_MIGRATION_TESTING=1` together with either of:
- `NUXR_MIGRATION_TEST_FAIL=<UP|DOWN>:<id>:<step>`;
- `NUXR_MIGRATION_TEST_CRASH=<UP|DOWN>:<id>:<step>`.

These make a specific step fail, or kill the process after it, so the test suite can prove partial-failure handling. They can only cause failures; they cannot skip or weaken any check. Never set them outside the test environment.
