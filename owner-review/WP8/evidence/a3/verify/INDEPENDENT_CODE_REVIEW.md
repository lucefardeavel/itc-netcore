# WP8 A3: independent read-only code review (2026-10-13)

## Provenance

- **Reviewer:** a separate review agent. It did not share the implementer's context, wrote nothing and ran nothing (read-only).
- **Scope:** the 7 production files, reviewed against plan rev. 3 + addendum (OD-A3R-1 … 14) and the handoff.
- **Treatment of this record:** the reviewer's findings are below as delivered. The implementer then verified each finding independently (column "Verified"). That verification is in `A3_FINAL_PRE_ACCEPTANCE_VERIFICATION_REPORT.md` §3.
- **Reviewer verdict:** DEFECTS_FOUND. No CRITICAL or HIGH; 2 MEDIUM; 6 LOW; INFO items.

## Findings (as reported) and verification

| ID | Sev (reviewer) | file:line | Finding | Verified by |
|---|---|---|---|---|
| F1 | MEDIUM | LegacyReprovisionService.php:100-102; DriftReport.php:70-77 | The ambiguity refusal writes `reprovision.identity_edit` with outcome `REFUSED_AMBIGUOUS_IDENTITY`. The `REPROVISION_UNCONFIRMED` drift uses the latest `reprovision.%` outcome, so the refusal hides an earlier unresolved UNCONFIRMED. This contradicts plan §R5.4 | probe F1 (both engines): drift lists `REPROVISION_UNCONFIRMED` before the refusal, not after. Context probe: a later FAILED_REVERTED hides it too, on the baseline (pre-existing mechanism) |
| F2 | MEDIUM (acceptance gap; pre-existing behaviour) | LegacyReprovisionService.php:670-687 (unchanged); RadiusRepository.php:45-51 | Two Radius plans on the SAME key still cannot both verify (one `radusergroup` row, group `plan_<id>`). R1 and ADMIN_SYNC end `REPROVISION_UNCONFIRMED`. Plan §R4 and handoff §3 ("every plan verified, R1 DONE") are not met for this configuration | probe SameKeyAdminSync: ADMIN_SYNC and IDENTITY_EDIT end UNCONFIRMED (`verified:false`, device SUCCESS) on A3 **and** on the baseline, on both engines |
| F3 | LOW | LegacyReprovisionService.php:219-241; customers.php:759-762 | The pre-check tests each pair against the current state. With chained pairs (case-only username change and a new PPPoE username in one edit), the first pair's own rows look foreign → false `RENAME_AMBIGUOUS_IDENTITY` (R1 and R2) | probe F3 (both engines): refused together; the same two changes in two edits → DONE, DONE |
| F4 | LOW | LegacyReprovisionService.php:645; Radius.php:105-107 | A no-op pair (from === to; PPPoE username equal to the username, then cleared or set) is recorded as an attempt with no capture → `old_session` UNCONFIRMED + drift; the UI shows an error-type message | probe F4 (both engines): DONE / UNCONFIRMED, reason `rename_not_confirmed`, drift `OLD_SESSION_UNCONFIRMED:UNCONFIRMED` |
| F5 | LOW | LegacyReprovisionService.php:639-642, 366-368; Radius.php:108-111 | Dedup is per (from,to), not per from (plan §4.2: "each `from` once"). Pairs [a→b], [a→c] target the same session twice | probe F5 (both engines): the same radacctid targeted twice (COA_ACK, then COA_NO_RESPONSE); the run takes 6.2 s; `old_session` UNCONFIRMED although the session was ended |
| F6 | LOW | customers.php:916-918 | Plan §4.4: UNCONFIRMED and NO_ACCOUNTING_EVIDENCE are shown as warnings. The code shows NO_ACCOUNTING_EVIDENCE as success `'s'` and UNCONFIRMED as error `'e'` | code + plan text (§4.4); `'w'` is supported (`ui/ui/admin/header.tpl:129`) |
| F7 | LOW | LegacyReprovisionService.php:237-240 | An unreadable RADIUS DB fails closed with the "equivalent identity" message and the `REFUSED_AMBIGUOUS_IDENTITY` audit (and triggers F1) | code reading |
| F8 | LOW | Radius.php:108-111 → LegacyReprovisionService.php:266-268 | R2: an AMBIGUOUS capture is silent (no log). Plan §4.5: "each non-success is logged through `ErrorReporter`" | code + plan text |
| I1 | INFO (pre-existing) | RadiusRepository.php:186; LegacyReprovisionService.php:549-558; customers.php:713-723 | Exact `to` bytes are replaced on purpose, and PPPoE-username uniqueness is not validated (R1 and R2). A rename can therefore replace another subscriber's exact-key policy. Plan §R5.2 relies on validation that does not exist for the PPPoE username | probe I1 (both engines): another customer's key 7 → 5 rows, password replaced, rename `DONE`, **identical on the baseline** |
| I2 | INFO | customers.php:836 vs :904 | R2: a race refusal inside the transaction throws after `$c->save()` (same pattern as every R2 device failure) | code reading |
| I3 | INFO | DriftReport.php:81-95 | An UNCONFIRMED `old_session` is cleared only by a later `rename.old_session` of the customer; there is no acknowledgement path | code reading |
| I4 | INFO | plan §4.3 line 244, §4.4 line 256 | The plan text says `reprovision.old_session`; the handoff and code use `rename.old_session`. The code is right (the plan's name would break the `REPROVISION_UNCONFIRMED` drift) | plan text |
| I5 | INFO | LegacyReprovisionService.php:445-456 | `web()` is not extended. No IDENTITY_EDIT goes through it; on the R1 UNCONFIRMED path the UI shows only the DomainError | code reading |
| I6 | INFO | LegacyReprovisionService.php:264-286 | `disconnectCaptured` has no demo-stage guard (R2 skips devices only for lowercase `'demo'`) | code reading |
| I7 | INFO | AuditLog.php:18, 85-87 | An audit payload over 64 KB is swallowed; drift then shows `CONFIRMATION_MISSING` (fails safe) | code reading |
| I8 | INFO (uncertain) | RadiusPolicyBuilder::key trims, `renamePairs` does not | A legacy untrimmed name would be refused (fail closed) | not verified |

## Reviewer conclusions per focus area (summary)

| Area | Conclusion |
|---|---|
| a) Rename integrity | Exact-byte DELETE/UPDATE with the index predicate kept; case-only rename is an in-place update; the ambiguity check runs inside the transaction (FOR UPDATE) and rolls back through `ProvisionFailure`; pre-write refusal in R1 and R2; verification by `exactRows`; portable to MariaDB 10.11 and MySQL 8.0. Exception: F3 |
| b) Other subscribers | Collation-equivalent keys are protected. Exact-key replacement stays (I1, pre-existing) |
| c) Multiple plans | Case C (repeated rename) is fixed in R1 and R2; MikroTik devices still get `change_username` per plan; `renamePairs` matches the replaced code. Exceptions: F2, F4, F5 |
| d) No new-session disconnect | Non-equivalent: open rows of the old name after the rename. Equivalent: snapshot rows only. The holder check runs after the identity write in R1 and R2. One Disconnect per row, to its NAS; polling by radacctid. Exception: F5 duplicates |
| e) Lock, transactions, audit, drift | Disconnects and the ≤ 6 s poll run after `hold` returns; only DB reads inside the lock; static state reset in the closure and in `finally`; `rename.old_session` is outside `reprovision.%`; `PENDING` on DONE and UNCONFIRMED. Exceptions: F1, F4 |
| f) Partial-failure outcomes | Applied then failed → UNCONFIRMED with `old_session` recorded; FAILED_REVERTED only when nothing was applied; the refusal is never FAILED_REVERTED; `old_session` never changes the status; exceptions are rethrown as-is |
| g) Other | No PHP 8.2 warning found. F6, F8, I5, I6 |

## Q1 and Q2

**Q1.** Case C (the same key renamed twice across plans) is fixed: R1 at :636-648, R2 at customers.php:897-903. Two Radius plans on the SAME key: nothing changes; ADMIN_SYNC and IDENTITY_EDIT still end `REPROVISION_UNCONFIRMED` (F2).

**Q2.** Partly. Plan §4.5 states "no Stop wait and no new audit; each non-success is logged through `ErrorReporter`" and "the legacy flow is otherwise unchanged". The absence of an R2 drift entry and UI warning is implied, not stated. The explicit statement is only in the acceptance candidate (residual R-a).
