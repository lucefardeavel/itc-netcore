# Status of this guide

> [!WARNING]
> **This path is NOT accepted and has NOT been executed for ITC NetCore V1.0.** By owner decision of 2026-10-29, the first V1.0 installation is a **fresh install on a new, empty database** (see the *Fresh Installation Manual*). Migration of an existing legacy database is a separate **future** path. Nothing in this guide may be run against production data, a production database copy or a production router without its own, separate, written owner authorization. This guide does not grant it.

This guide exists so that a later migration project starts from the right prerequisites. It collects, from the product's authoritative documents, every gate that a migration must pass, the procedure the deployment runbook prescribes, and the open decisions that only the owner can take. Where this guide and a product document differ, the product document wins (`TECH_DOCS_SOURCES.md` lists the sources).

| Item | State |
|---|---|
| Owner decision | First V1.0 instance: fresh install (D-61, 2026-10-29). Migration: separate gated path |
| Procedure source | `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md` §3 (upgrade) and §4 (rollback) |
| Executed on real data | **Never.** No survey, no conversion and no upgrade has touched real data |
| Executed in the lab | Upgrade / rollback / restore drills D1–D3 on **synthetic fixture databases** only, both engines |
| Target acceptance | TA-08 "on the §3 upgrade path" is required and `NOT_RUN` |

## What "migration" means here

An existing legacy installation is upgraded **in place**: the ITC NetCore code is deployed next to the old code, and the reseller-domain schema is added to the existing database by the project migration runner (`system/reseller/migrate.php`). The reseller migrations are additive: legacy tables keep their data, except for the columns that the migration contract adds to `tbl_customers`. There is no export / import tool and no data transformation step.

# Prerequisite gates

Every gate below must be closed, with evidence, before the upgrade window is scheduled. For the fresh-install path all of the data-specific gates are *not applicable*; for a migration **none of them is optional**.

| # | Gate | Status |
|---|---|---|
| M-1 | Separate written owner authorization for the migration (database, isolated survey host, report readers, window, rollback authority) | Open |
| M-2 | The existing core schema is a registered variant | To be checked on a copy |
| M-3 | OBS-1 identity survey on an isolated copy, owner decision sheet, rehearsal | Open (procedure ready, never run on real data) |
| M-4 | P-A4-3 historic `radacct` time-zone survey | Open; **no executable procedure ships** |
| M-5 | D-2 ownership backfill decision | Open (owner) |
| M-6 | Collation conversion of existing RADIUS tables (B-L09) | Procedure ready |
| M-7 | MAC-H1A Hotspot MAC-login impact | Procedure ready (read-only queries) |
| M-8 | Hotspot admission prerequisites and rollout order | Procedure ready |
| M-9 | Legacy device plans (MikroTik API, rlm_rest) moved to the FreeRADIUS device | Open |
| M-10 | NAS contract for every existing NAS (type `mikrotik_snmp`, SNMP credentials, timeout, CoA port, NTP) | Procedure ready (companion RADIUS manual) |
| M-11 | Plugin inventory reviewed and approved | Open |
| M-12 | Verified pre-upgrade backup and a tested restore | Procedure ready |
| M-13 | TA-08 on the §3 upgrade path on the approved staging copy | `NOT_RUN` |

## M-2 Core schema variant

The migration runner compares the exact definitions of `tbl_users`, `tbl_customers` and `tbl_plans` (columns, types, nullability, defaults, indexes, foreign keys, engine, collation) with the variants registered in `system/reseller/Migration/variants.php`:

- `V1_FRESH_INSTALL`
- `V2_FRESH_INSTALL_UPDATED`
- `V3_LONGLIVED_2023_UPDATED`

An unknown or incompatible core schema gives **NOT_READY (exit 2) before any change**. There is **no runtime override**. Supporting another variant needs a reviewed source change and tests. Run `php system/reseller/migrate.php status` and `preflight` against a **restored copy** first, never against production.

## M-3 OBS-1 identity survey

RADIUS identifies a subscriber by one key (Hotspot user name, PPPoE user name, or voucher code). The application now refuses to *write* an identity that another holder owns, but rows that existed before the upgrade can hold the same canonical key (trimmed, case- and accent-insensitive under `utf8mb4_general_ci`). Such a key authenticates one RADIUS identity for two commercial owners, and the preflight check `identity.collisions` **FAILs** while any exists.

The survey runs **only on an isolated, authorized copy** (`docs/OBS1_SURVEY_RUNBOOK.md`):

| Step | Action |
|---|---|
| 1 | Consistent, encrypted backup of the application and RADIUS databases (backup runbook §2); record time, size, SHA-256, release commit |
| 2 | Move the encrypted dumps to an isolated host (no route to production, no RADIUS service, no NAS access, no outbound mail / SMS) |
| 3 | Restore into **new, empty** databases there |
| 4 | Verify the copy: `php system/reseller/migrate.php verify --db-name=<copy> …` exits 0; finance-table row counts equal the backup record |
| 5 | With a scratch `config.php` and the copy's own read-only account: `php system/reseller/identity_report.php --json > report.json` (read-only session, SELECT only). The shipped survey SQL is `system/reseller/identity_survey.sql` |
| 6 | Produce the decision sheet (`python3 dev/wp10/obs1_sheet.py report.json sheet.csv`; this tool is in the source tree, not in the release artifact) |
| 7 | The **owner** fills the sheet: who keeps each key, the option, the new name for the other holder, approver, date |
| 8 | Rehearse the remediation on the copy through the supported flows; re-run the survey: `collisions` must be 0 and preflight `identity.collisions` PASS |
| 9 | Production execution needs a **separate** authorization with its own backup, window and rollback |
| 10 | Destroy the copy, the dumps and the report on the isolated host; keep the sheet (mode 0600) with the owner |

Remediation options (the owner chooses per class; the engineering side never chooses):

| Option | Mechanism | Reversible |
|---|---|---|
| R1 Rename one holder | Supported customer rename (old session ended and confirmed, audited) | Yes (rename back) |
| R2 Retire the unused holder | Supported deactivate flow; accounting and financial history kept | Partly |
| R3 Merge two customer records | **No supported tool exists**; would be new development and a financial-history decision | No |
| R4 Keep the key with the reseller-owned holder, rename the legacy holder | A rule executed as R1 | As R1 |
| R5 Leave as is | **Not available**: the deployment gate fails | — |

> [!SECURITY-WARNING]
> The survey report contains customer names and ids. Keep it on the isolated host, mode 0600, outside Git; never attach it to a ticket or chat.

## M-4 P-A4-3 historic radacct time-zone survey

The V1 time contract requires every SQL session to be UTC and reads accounting times as epoch seconds. Accounting history that an existing installation wrote under **another database time zone** shifts historic reads (characterised in the WP8 time-contract tests by a +10800 s shift). The checklist item E6 requires a survey of historic `radacct` times for non-UTC writers before relying on period counters.

> [!WARNING]
> No executable survey procedure for P-A4-3 ships with the release. Checklist E6 refers to the operator recovery runbook, which contains no such procedure; the item is recorded as `OPEN_OPERATIONAL`. Before a migration, a read-only survey and a compatibility plan must be designed, reviewed and authorized. Never rewrite `radacct` to "fix" times.

## M-5 D-2 ownership backfill decision

Legacy history rows that carry no owner (`user_id = 0`) are no longer shown to the customer in the portal after the fail-closed fix; staff still see them, and nothing is deleted. The lab's answer "no ownership backfill" was taken under a general delegation and must be **ratified or replaced by the owner**:

- ratify "no backfill" (customers upgraded from ITC NetCore do not see their own pre-upgrade history in the portal); or
- authorize a supervised backfill of `user_id` for rows whose ownership can be proven. **No tool for this exists**; it would be new, reviewed work.

## M-6 Collation conversion (B-L09)

A RADIUS `nasreload` table created before the fix may carry a collation other than that of `radacct` (the MySQL 8 default is `utf8mb4_0900_ai_ci`). The stock simultaneous-use query then fails on **every** login with error 1267.

```bash
mariadb --defaults-extra-file=<0600 admin client file> <radius database> < <artifact-dir>/install/freeradius/nuxr_nasreload_collation_check.sql
# not READY:
mariadb --defaults-extra-file=<0600 admin client file> <radius database> < <artifact-dir>/install/freeradius/nuxr_nasreload_collation.sql
```

The conversion is idempotent, converts only `nasreload` and keeps every row; the check must then print `nasreload_collation=READY`. The identity report's section [1] lists the collation of every identity column as deployed; a column that is not `utf8mb4_general_ci`-compatible changes what "equal" means for M-3.

## M-7 MAC-H1A impact

The V1 Hotspot rule admits only the exact stored spelling of a user name. RouterOS sends MAC user names in upper case with colons. A Hotspot that uses `mac-auth-mode=mac-as-username` with a MAC stored in **lower case** worked before and **stops working** after the upgrade (measured on RouterOS 7.24.5). Nothing changes stored user names automatically. Before enabling: list candidates with the preflight `mac` check and the read-only impact queries, then correct the stored MAC (or the NAS mode).

## M-8 Hotspot admission prerequisites

- Every Hotspot subscriber needs `Simultaneous-Use` in `radcheck`; find exceptions (read-only):

  ```sql
  SELECT DISTINCT username FROM radcheck WHERE attribute = 'Cleartext-Password'
     AND username NOT IN (SELECT username FROM radcheck WHERE attribute = 'Simultaneous-Use');
  ```

- Every Hotspot NAS must send a unique `NAS-IP-Address` equal to its `nas.nasname`.
- Sessions that are live when the admission slots are enabled hold no slot; enable outside peak hours or after a Hotspot session reset.
- Hotspot subscribers provisioned before `Port-Limit` was written as a reply attribute need a `Port-Limit` rollout (integration model WP8 §12) before the NAS enforces the limit for them; plan it with the re-provisioning tools of the application (customer Sync), never by editing `radreply` by hand.

## M-9 Legacy device plans

The release profile serves subscribers through FreeRADIUS only. The legacy MikroTik-API drivers (Hotspot, PPPoE, VPN) and the rlm_rest device are **disabled** unless `$legacy_devices_enabled = true` is set in `config.php`, which is a documented, **unsupported** configuration with open findings. An existing installation whose plans use those devices must move them to the Radius device (and the NAS to the FreeRADIUS contract) before go-live; no automatic conversion exists.

## M-10 Existing NAS

Each existing NAS must meet the full NAS contract of the companion RADIUS manual: RouterOS 7.15 or later, NAS type `mikrotik_snmp`, an entry in the guard credential file, `/radius timeout=3s` with `require-message-auth`, the CoA port set explicitly, NTP within 10 s, no local users shadowing subscribers, and the RouterOS API closed.

## M-11 Plugins

Plugins are fully trusted code. Inventory them (`php system/reseller/plugins.php`) and verify the approved list with `preflight.php --plugins-approved=<inventory file>`; only reviewed plugins may stay installed.

## M-12 Verified backup

Take the pre-upgrade backup with the backup runbook §2 procedure and record its SHA-256. Once new-domain data exists it is **the only rollback path**. Prove the restore on a scratch database before the window. The legacy JSON "backup / restore" of the web interface is disabled and cannot restore the reseller domain.

# Upgrade procedure (runbook §3, never executed on real data)

**Verification level:** Documented (not yet executed on target). The same sequence passed the D1 drill on synthetic fixture databases in the lab (MariaDB and MySQL 8.0).

1. Announce a maintenance window.
2. Stop the scheduled jobs and put the web server into maintenance (HTTP 503) for staff and customers.
3. Take a **verified backup** and record its SHA-256 (M-12).
4. Deploy the new code next to the old one, then switch the document root.
5. Run the migration runner with the migration (DDL) account; the password comes from an environment variable, never from the command line:

   ```bash
   php system/reseller/migrate.php preflight --config=config.php --db-user=<migration user> --db-password-env=NUXR_MIG_PW
   php system/reseller/migrate.php migrate   --config=config.php --db-user=<migration user> --db-password-env=NUXR_MIG_PW
   php system/reseller/migrate.php verify    --config=config.php --db-user=<migration user> --db-password-env=NUXR_MIG_PW
   ```

   - A second `migrate` must report nothing to do.
   - Exit **2** NOT_READY: nothing was changed; fix the cause and retry. Exit **3** FAILED: only `rollback` may run.
   - In the RADIUS database apply the additive objects the old installation lacks: `nuxr_admission.sql`, `nuxr_hs_admission.sql` and `nuxr_nasreload_collation.sql` (all idempotent). `nuxr_admission_check.sql`, `nuxr_hs_admission_check.sql` and `nuxr_nasreload_collation_check.sql` must all print READY.
6. Generate and apply the least-privilege grants (`grants.php`), install the FreeRADIUS configuration, and run `php system/reseller/preflight.php`. Resolve **every** FAIL before reopening, in particular `identity` (OBS-1), `grants` and `time`.
7. Reopen in this order: the web server, then the scheduled jobs. Watch `php system/reseller/health.php` for 30 minutes.

# Rollback

| Situation | Action |
|---|---|
| `migrate` failed or was interrupted, and no new-domain data exists | `php system/reseller/migrate.php rollback --all` (or `--to=<id>`): drops only the objects the migrations created, in reverse order; the core fingerprint must equal the pre-migration one |
| New-domain data exists (any reseller row, or `tbl_customers.manager_id` set) | Schema rollback is **refused** (exit 2, data guard), by design. Restore the pre-upgrade backup (backup runbook §3) and redeploy the previous code. Every operation since the backup is lost and must be reconciled from the audit export |
| Code-only problem after a successful migration | Redeploy the previous release of this fork with the same schema |
| OBS-1 remediation row | Per row: rename back from the audit record (R1); whole change: restore the pre-change dump |

> [!WARNING]
> **Never redeploy legacy over a migrated database.** Never use the legacy updater (`update.php` answers 403; `install/update.php` refuses once installed): schema changes go only through `system/reseller/migrate.php`.

# What is never done

- Deleting or rewriting rows of `radacct`, the ledger, transfers, transactions or any audit table, before, during or after the migration.
- Choosing which customer keeps a colliding key (owner only).
- Running the survey, a conversion or the upgrade on production data without the separate authorization.
- Releasing funds, admission claims or reservations to make a migrated subscriber log in.

# Decisions the owner must record before a migration

| # | Decision |
|---|---|
| 1 | Which instance is migrated, and which database (`______`) |
| 2 | Whether the same path applies to every ISP instance |
| 3 | The OBS-1 survey authorization: production database, isolated host, report readers |
| 4 | D-2: ratify "no backfill" or authorize a supervised backfill |
| 5 | The P-A4-3 survey and compatibility plan (design, review, authorization) |
| 6 | The window, the rollback authority and the TA-08 evidence on the upgrade path |
