# V1 engineering decision record (continuous execution phase)

Scope: decisions an engineer may take inside the accepted contracts (owner `CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED`, 2026-10-17). Non-delegable decisions are **not** recorded as taken here; they are listed under "Deferred to the owner" with options and a recommendation.

## Taken (engineering)

| ID | Date | Decision | Why | Alternatives rejected |
|---|---|---|---|---|
| E-N2-1 | 2026-10-17 | A billing period owns the accounting rows that **arrived after it started** (`radacct.radacctid` > `Nuxr-Period-Acct-Id` = the table's highest id at the period start). The time boundary (period start − 10 s) stays as the fallback for rows without an id. | exact for every row, independent of the NAS clock; removes the under-count (clock lag) and the over-count (session opened in the 10 s before a renewal) of the time rule; one new check attribute, no schema change | receive-time stamping in FreeRADIUS (`event_timestamp_epoch = %l`): changes the accepted accounting time contract → owner decision, not taken |
| E-N2-2 | 2026-10-17 | The id is **paired with its period**: same time boundary → keep the stored id (or its absence); new boundary → take the table's current end. A period provisioned before this change keeps the time rule until it ends. | a re-provision (password, rename, re-sync) must never reset the usage; no in-place migration of existing periods | computing a fresh id on every provision (would reset usage on a password change) |
| E-N2-3 | 2026-10-17 | An activation records the id in its captured target (before the first write); `provision()` writes that target and verification compares with it. A RADIUS-database error while resolving is a retryable RADIUS failure (activation does not start). | accounting rows arriving between capture and provision must not cause `VERIFY_MISMATCH` | re-reading `MAX(radacctid)` in each step |
| E-N2-4 | 2026-10-17 | Readers trust an id only when it is well-formed and not beyond the table's end; a malformed id fails closed at login. | an accounting table restored from an older backup must not make every new row look "old" (quota bypass) | trusting the stored value blindly |

## Deferred to the owner (collected for the final package)

| ID | Item | Options | Recommendation |
|---|---|---|---|
| N1 | PPPoE total-quota overshoot semantics | filled by Q2 | filled by Q2 |
| N2 | Period-attribution method and NAS clock requirement | filled by Q1 | filled by Q1 |
| D1, D2, D4 – D10 | as in `SAAS_RADIUS_V1_GB_QUOTA_AND_RENEWAL_FINAL_ACCEPTANCE_PACKAGE.md` §15 | | |
