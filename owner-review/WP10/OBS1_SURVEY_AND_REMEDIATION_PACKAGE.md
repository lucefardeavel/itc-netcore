# OBS-1 - existing duplicate identities: survey procedure, remediation options, backup / rollback plan (D7)

Owner decision 2026-10-18: survey the legacy data **only on an isolated, authorized copy**; prepare the collision report, the remediation
options and a backup / rollback plan; **do not choose which customer keeps a key** and do not change real subscriber data.
This package prepares everything except those two things. Nothing here has been run on real data.

## 1. The problem in one paragraph

RADIUS identifies a subscriber by one key (the Hotspot username, the PPPoE username, or a voucher code). The application now refuses to *write* an
identity that another holder already owns (U0, serialized and canonical), but **rows that already existed before** can hold the same canonical key
(trim + case/accent-insensitive comparison under `utf8mb4_general_ci`): two customers, a customer's username equal to another's PPPoE username, a live
voucher equal to a customer, owned versus unassigned. Such a key authenticates one RADIUS identity for two commercial owners. The preflight check
`identity.collisions` **FAILs** while any exists; resolving each one is a business decision (whose account keeps the key), so it is the owner's.

## 2. Survey procedure (read only)

| Step | Action | Who | Authorization needed |
|---|---|---|---|
| 1 | Take a consistent backup of the application and RADIUS databases (`mysqldump --single-transaction`, both databases) on the production host with the operator's own procedure (`docs/BACKUP_RESTORE_RUNBOOK.md`) | operator | written authorization naming the backup and the isolated target |
| 2 | Restore into an **isolated** database server with no route to production, no RADIUS service, no NAS access | operator | same |
| 3 | `php system/reseller/identity_report.php --json > report.json` against the isolated copy (read-only session, SELECT only; the tool refuses anything else). Equivalent: `dev/wp9/identity_survey.sql` | operator | - |
| 4 | Fill the decision sheet (section 4) from `report.json`; the engineer can prepare it but the **"keeps the key" column is the owner's** | owner | - |
| 5 | Rehearse the chosen remediation **on the isolated copy** (section 3), re-run the report: `collisions` must be 0 | operator | - |
| 6 | Production execution: a separate authorization with its own backup, window and rollback (section 5) | owner | explicit, separate |

The report contains customer names and ids: keep it on the isolated host, mode 0600, outside Git.

### 2.1 What the report contains (`identity_report.php`)

| Section | Meaning |
|---|---|
| [0] session check | must be `OK` (an SQL mode that changes backslash handling would falsify the survey: the tool shows `ABORT_*`) |
| [1] collations | the collation of every identity column as deployed (a column that is not `utf8mb4_general_ci`-compatible changes what "equal" means) |
| **[2] collisions** | every canonical key held by more than one identity: customers (both name fields), owned versus unassigned, live vouchers, voucher-only recharges |
| [3] non-canonical names | stored bytes differ from the canonical form (edge whitespace / control characters) |
| [4] interior control characters | never a valid RADIUS key |
| [5] RADIUS keys without exactly one owner | orphaned / suspended policy rows and keys claimed twice |

## 3. Remediation options (the owner chooses per class; none is chosen here)

| Option | What changes | Supported mechanism | Reversible | Risk |
|---|---|---|---|---|
| **R1 Rename one holder** | the losing holder gets a new, unique name; its RADIUS rows move to the new key; its running session is ended (Option B / A3 old-session handling) | the application's customer rename (`LegacyReprovisionService`, A3): exact user change, old session ended and confirmed, audited | yes: rename back (the old name is in the audit record) | customer must re-dial and learn the new login |
| **R2 Retire the unused holder** | an identity with no active service and no recent accounting is deactivated; its RADIUS rows are removed through the application's deprovision | supported deactivate flow; accounting and financial history are **kept** | partly: re-activation creates a new period | wrong "unused" judgement cuts a customer |
| **R3 Merge two customer records** | one record absorbs the other | **no supported tool exists**; would be new development and a financial-history decision | no | highest: touches balances, orders, ledger links |
| **R4 Keep the key with the reseller-owned holder, rename the legacy (unassigned) holder** | a *rule*, executed as R1 for every collision of that class | as R1 | as R1 | the rule must be justified commercially |
| **R5 Leave as is** | nothing | - | - | **not available**: the deployment gate FAILs; shipping with a known multi-holder key is refused |

Whichever option applies, the engineer's tooling never edits `radacct`, `tbl_transactions`, the ledger, wallets or audit rows.

## 4. Decision sheet (one row per collision; filled from the report)

| key (masked on paper) | holders | owner of each (reseller / unassigned) | active service (plan, expiry) | last accounting (UTC) | balance | **owner's choice: who keeps the key** | option (R1 - R4) | new name for the other | approved by / date |
|---|---|---|---|---|---|---|---|---|---|
| | | | | | | *(owner)* | | | |

The sheet is the only input the remediation needs; it is **not** completed by the engineering side.

## 5. Backup / rollback plan (for the production execution, once authorized separately)

| Phase | Action | Evidence |
|---|---|---|
| Before | consistent backup of both databases; SHA-256 of the dumps; restore rehearsal of that exact dump on the isolated host (same engine and version); the report re-run on the restore gives the same collisions | dump sizes + checksums, drill log |
| Freeze | maintenance window; stop new activations and renames (or run when idle); `quota.php`, `reconcile.php stale` empty | `reconcile.php stale`, `health.php --json` |
| Change | execute the sheet row by row through the supported flow (R1 / R2 / R4); one row at a time; the audit record per row is kept | audit ids per row |
| Verify | `identity_report.php` -> `collisions = 0`; preflight exit 0; `health.php --finance` OK; one test login per renamed subscriber on the staging NAS first | outputs |
| Rollback trigger | any verification failure, any unexpected customer impact | - |
| Rollback | per row: rename back (R1) from the audit record; or whole-change: restore the pre-change dump into the production databases with the runbook procedure, then replay nothing automatically | restore log + `finance-check` |

`dev/wp10/drill.sh` is the lab reference for the dump / restore / verify shape (128 assertions on both engines).

## 6. Test evidence

`tests/Integration/Wp10/V1IdentityReportTest.php` (report finds a synthetic collision, changes nothing, states facts only, shipped survey identical to the tested one);
`U0SurveyTest` (the survey on every class of duplicate); `U0IdentityTest`, `U0RaceTest` (write-time refusal).
