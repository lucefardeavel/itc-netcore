# OBS-1 read-only survey runbook (D7)

Scope: survey the **existing** customer / voucher / RADIUS identities for canonical-key collisions (two holders of one RADIUS key) **on an isolated, authorized copy of the data only**.
**Read-only**: nothing in this runbook changes a subscriber, a record on the production database or a router. The engineering side does not decide which holder keeps a key; that is the owner's
decision (sheet in step 6). Background and remediation options: `owner-review/WP10/OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md`.

**Authorization required first** (separate from the target-staging request): written permission naming (1) the production database that is backed up, (2) the isolated host the copy is restored on,
(3) who may read the report (it contains customer names and ids). Without it, stop.

| Step | Action | Command / rule | Evidence |
|---|---|---|---|
| 1 | Consistent backup of the application and RADIUS databases (operator, production side; read-only access) | `docs/BACKUP_RESTORE_RUNBOOK.md` section 2: `--single-transaction`, password from a 0600 file, dump encrypted with `age`; record time, size, SHA-256, release commit | backup record |
| 2 | Move the encrypted dumps to the **isolated** host (no route to production, no RADIUS service, no NAS access, no outbound mail / SMS) | `age -d` only on the isolated host | transfer note |
| 3 | Restore into **new, empty** databases there | `docs/BACKUP_RESTORE_RUNBOOK.md` section 3 step 1 | restore log |
| 4 | Verify the copy | `php system/reseller/migrate.php verify --db-name=<copy> ...` exits 0; row counts of the finance tables equal the backup record | verify output |
| 5 | Point a **scratch** `config.php` at the copy (copy's own read-only account; never the production account) and run the survey | `php system/reseller/identity_report.php --json > report.json` (the session is read-only, SELECT only; it refuses anything else); the same result from `dev/wp9/identity_survey.sql` | `report.json` (0600, outside Git) |
| 6 | Produce the decision sheet | `python3 dev/wp10/obs1_sheet.py report.json sheet.csv` (one row per colliding key, masked key, holders, kinds; the owner columns are **empty**) | `sheet.csv` (0600) |
| 7 | The owner fills the sheet: who keeps each key, the option (R1 rename / R2 retire / R3 merge / R4 rule), the new name for the other holder, approver, date | owner | completed sheet |
| 8 | Rehearse the chosen remediation **on the copy** through the supported flows (rename = the A3 customer rename, retire = the deactivate flow) and re-run steps 5 - 6: `collisions` must be 0 and `preflight.php` `identity.collisions` PASS | operator | second report |
| 9 | Production execution: a **separate** authorization with its own backup, window and rollback (`OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md` section 5) | owner | explicit, separate |
| 10 | Destroy the copy, the dumps and the report on the isolated host when the sheet is complete (keep the sheet, 0600, with the owner) | operator | destruction note |

## Read-only guarantees (how to check them)

- `identity_report.php` opens its session with `SET SESSION TRANSACTION READ ONLY` and executes only statements that start with `SELECT` (guard test `V1IdentityReportTest`).
- The survey SQL shipped in `system/reseller/identity_survey.sql` is byte-identical to the one the survey tests use (`dev/wp9/identity_survey.sql`, same test).
- Before and after step 5, compare a checksum of the customer, voucher, radcheck and ledger tables on the copy (`CHECKSUM TABLE`): equal.

## What the report contains

Sections: [0] session check (must be `OK`), [1] column collations as deployed, **[2] collisions** (canonical keys held by more than one identity), [3] names whose canonical form differs from the stored bytes,
[4] names with an interior control character, [5] RADIUS policy keys without exactly one owner. Meaning and options: the package above. A statement that failed is reported, never hidden
(`statements_failed` in the summary).

## What is not done here

No change of any record, no choice of a winner, no merge tool, no production connection, no deletion of accounting or financial history.
