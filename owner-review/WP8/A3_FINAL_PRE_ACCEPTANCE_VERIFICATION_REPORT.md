# WP8 A3 — Final Pre-Acceptance Verification Report

| Item | Value |
|---|---|
| Date | 2026-10-13 |
| Branch / HEAD | `wp8/integration-poc` @ `32a162d6` (= origin). A3 is uncommitted in the working tree. No commit, push, merge or deployment |
| Subject | `WP8_A3_IMPLEMENTATION_ACCEPTANCE_CANDIDATE.md` and its 7 production changes |
| Evidence | `owner-review/WP8/evidence/a3/verify/` (new) and `owner-review/WP8/evidence/a3/` (earlier) |
| Rules followed | No re-planning, no re-implementation. **No product or test file was changed during this verification**: tree hashes are identical at the start, after the harness and at the end (`tree-before.sha256` = `tree-after.sha256` = `tree-now.sha256`, 1823 files). Every probe ran from outside the repository, mounted read-only |

## Verdict: **BLOCKED_WITH_EVIDENCE**

A3 meets its core aim, proven on MariaDB, MySQL and the real CHR:
- exact-byte rename;
- refusal of ambiguous renames before any write;
- rename once per run;
- the old session ended per captured row, after the lock;
- `old_session` kept separate from the status.

It is still **not ready for acceptance as is**, because three acceptance criteria of the approved plan are not met. Each one has a reproducible proof:

| # | Criterion not met | Cause | Introduced by A3? |
|---|---|---|---|
| B-1 | §R5.4 "the `REPROVISION_UNCONFIRMED` drift is unaffected" | F1: the new outcome `REFUSED_AMBIGUOUS_IDENTITY` is written as a `reprovision.%` event and hides an earlier unresolved UNCONFIRMED | **Yes** (new trigger of an older mechanism) |
| B-2 | Handoff §3 / plan §R4 / §R5.1: "multi-plan shared key: every plan verified, R1 `DONE`" | F2: two Radius plans on the **same** key cannot verify; ADMIN_SYNC and the rename both end `REPROVISION_UNCONFIRMED` | **No**: identical before and after A3 |
| B-3 | §R5.2 "no other subscriber touched" | I1: no uniqueness check on the PPPoE username, and an exact target is replaced. A rename can replace another customer's policy | **No**: identical before and after A3 |

Plus six LOW defects in A3's own code (F3–F8). Each has a small proposed fix (§6). Nothing was fixed: the fixes need your approval (§7).

## 1. Full harness: reproducible, files frozen

- **Command:** `dev/test/run-tests.sh` (unchanged; `failOnWarning` unchanged).
- **Run:** 2026-10-13 12:58:52Z → 14:04:40Z.
- **Output:** `verify/harness-both-engines-rerun.out`.
- **Before the run:** all 6 new test files were complete. The last write was `RealLifecycleTest.php` at 11:56:38Z, more than an hour earlier. A sha256 snapshot of 1823 files (`tests/ system/ dev/ install/ ui/`) was taken. After the run: `TREE_UNCHANGED`.

| | MariaDB 10.11 | MySQL 8.0 |
|---|---|---|
| Tests collected | 1144 | 1144 |
| Tests (PHPUnit summary) | **1059** | **1059** |
| Assertions | **68221** | **68221** |
| Incomplete | **85** | **85** |
| Skipped | **0** | **0** |
| Failures / Errors / Warnings | 0 / 0 / 0 | 0 / 0 / 0 |
| PHPUnit exit code | **0** | **0** |

- **Per-engine exit codes:** `run-tests.sh` sets 1 if either engine's PHPUnit fails. The script's exit is 0, so both engine runs exited 0.
- **Variant registry:** "matches freshly built fixtures".
- **Final exit:** `exit=0`.
- **Reading the counts:** "Tests" in the PHPUnit summary does not include incompletes: 1059 + 85 = 1144 collected.
- **The 85 incompletes:** the lab-gated WP8 tests (75 in `wp8-integration`, including the 6 new A3 lab tests) plus 10 earlier ones. They are **not counted as passes**.
- **The first run's 79 vs 85:** caused by the test file being written while MariaDB was already collecting. The frozen run gives 85 on both engines, so that difference is closed.

## 2. Independent read-only code review

- **Reviewer:** a separate review agent, without the implementer's context. It wrote and ran nothing.
- **Scope:** the 7 production files, against plan rev. 3, the addendum (OD-A3R-1 … 14) and the handoff.
- **Full text:** `verify/INDEPENDENT_CODE_REVIEW.md`.
- **Reviewer verdict:** DEFECTS_FOUND. No CRITICAL or HIGH; 2 MEDIUM; 6 LOW; INFO items.
- **Verification of the findings:** I checked each one myself, either with a probe on both engines or by matching the code against the plan text.
- **Probes:** `verify/*ProbeTest.php`, `verify/run-probes.sh`, outputs `verify/probes-run1/2/3.out`.
  - Run 1: F1 failed because of a fixture error in my probe (an admin context cannot do PASSWORD_CHANGE → FORBIDDEN). It is recorded in the file, and corrected in run 2.
  - **Baseline comparison:** the same probes ran on the baseline (`HEAD` product files from the scope2 backup, bind-mounted read-only over `/repo`; hashes matched HEAD 7/7).

| Focus | Result |
|---|---|
| Rename data integrity | **Sound.** Exact bytes in DELETE/UPDATE; in-place equivalent rename; ambiguity checked inside the transaction (`FOR UPDATE`, rollback) and before any write in R1 and R2; verification by exact bytes. Exception: **F3** (a false refusal; fails closed, no data harm) |
| Other subscribers' policies | Keys that are equal only under the collation are protected. **I1 remains: a target with the same exact bytes is replaced (pre-existing, B-3)** |
| Multiple plans | Case C (rename repeated per plan) is fixed in R1 and R2. Exceptions: **F2** (same key, pre-existing, B-2), **F4**, **F5** |
| No new or untargeted session cut | Sound: the old name's rows after the rename, or the snapshot for an equivalent rename; holder check after the identity write; one Disconnect per row to its NAS. Exception: **F5** (same session targeted twice; a duplicate send, not a new session) |
| Transactions, lock, audit, drift | Sound: no network wait inside the lock or a transaction; static state reset on every path; `rename.old_session` outside `reprovision.%`; PENDING. Exceptions: **F1**, **F4** |
| Partial-failure outcomes | Sound: rename applied then failed → UNCONFIRMED with `old_session` recorded; FAILED_REVERTED only when nothing was applied; the refusal is never FAILED_REVERTED; `old_session` never changes the status; exceptions rethrown unchanged |

## 3. Verified findings

| ID | Sev | Where | Defect | Evidence (both engines) | A3? |
|---|---|---|---|---|---|
| **F1** | MEDIUM | `LegacyReprovisionService.php:100-102`, `DriftReport.php:69-78` | The refusal writes `reprovision.identity_edit` with outcome `REFUSED_AMBIGUOUS_IDENTITY`. The drift takes the **latest** `reprovision.%` outcome, so an earlier unresolved `REPROVISION_UNCONFIRMED` vanishes from the drift report | probes-run2: `drift_before` contains `REPROVISION_UNCONFIRMED`, `drift_after` does not | **yes** (see note) |
| **F2** | MEDIUM | `LegacyReprovisionService.php:670-687` (unchanged) | Two Radius plans on the same key cannot verify: ADMIN_SYNC and IDENTITY_EDIT → `REPROVISION_UNCONFIRMED` (`verified:false`, device SUCCESS) | probes-run1 `SameKeyAdminSyncProbeTest`: **identical on A3 and the baseline** | **no** |
| **I1** | HIGH impact / pre-existing | `RadiusRepository.php:186`, `LegacyReprovisionService.php:549-558`, `customers.php:713-723` | Renaming a PPPoE username to another customer's exact username replaces that customer's policy (7 → 5 rows; its password replaced; rename `DONE`) | probes-run3 `I1-exact-target`: **identical on A3 and the baseline** | **no** |
| **F3** | LOW | `LegacyReprovisionService.php:219-241`; `customers.php:759-762` | Chained pairs (a case-only username change plus a new PPPoE username in one edit) are refused as "ambiguous" when no other identity exists. Nothing is written | probes-run2 F3: refused together; the two changes in two edits → DONE, DONE | yes |
| **F4** | LOW | `LegacyReprovisionService.php:645`; `Radius.php:105-107` | A no-op pair (from = to; the PPPoE username equals the username, then cleared or set) → `old_session` UNCONFIRMED (`rename_not_confirmed`), drift `OLD_SESSION_UNCONFIRMED`, UI error message, although nothing was renamed | probes-run2 F4 | yes |
| **F5** | LOW | `LegacyReprovisionService.php:366-371` | Dedup is per (from, to), not per `from` (plan §4.2: "each `from` once"). The same session is targeted twice: the second send waits about 6 s and gives `UNCONFIRMED` although the session was ended | probes-run2 F5: the same radacctid twice (COA_ACK, then COA_NO_RESPONSE), 6.2 s | yes |
| **F6** | LOW | `customers.php:916-918` | Plan §4.4: UNCONFIRMED and NO_ACCOUNTING_EVIDENCE are shown as warnings. Actual: NO_ACCOUNTING_EVIDENCE as success `'s'`, UNCONFIRMED as error `'e'` | code + plan text; `'w'` is supported (`ui/ui/admin/header.tpl:129`) | yes |
| **F7** | LOW | `LegacyReprovisionService.php:237-240` | When the RADIUS DB is unreadable, the refusal (fail closed, correct) uses the "equivalent identity" message and triggers F1 | code reading | yes |
| **F8** | LOW | `LegacyReprovisionService.php:266-268` | R2: an AMBIGUOUS capture sends nothing and **logs nothing**. Plan §4.5: "each non-success is logged" | code + plan text | yes |

- **Note on F1:** the masking mechanism is older than A3. The baseline probe shows that a later `FAILED_REVERTED` also hides an earlier UNCONFIRMED (`F1-context`, identical on the baseline and A3). A3 adds a **new** outcome that triggers it, which contradicts §R5.4 as written.
- **Pre-existing observation, outside A3:** a customer whose username equals its PPPoE username changes both in one edit. A stale policy then stays under the new username next to the new PPPoE key (5 + 5 rows; `F5-keys`, identical on the baseline).
- **INFO items:** I2–I8 are in `verify/INDEPENDENT_CODE_REVIEW.md`. I4 is a documentation mismatch only: the plan text says `reprovision.old_session`; the code and handoff use `rename.old_session`, and the code is right.

## 4. Rename defect fixed vs ADMIN_SYNC behaviour still open (item 3)

| | Rename repeated per plan (case C as proven on the baseline) | Two Radius plans on the **same** RADIUS key |
|---|---|---|
| Configuration | Hotspot on the username plus PPPoE on its own PPPoE username. The username pair was applied for both plans, and the second rename deleted what the first plan had just written | Hotspot and PPPoE both on the username (no PPPoE username) |
| Before A3 | R1 `REPROVISION_UNCONFIRMED`; R2 **silent policy loss** (`evidence/a3/baseline-rename-integrity-fail.out`) | ADMIN_SYNC `REPROVISION_UNCONFIRMED`; rename `REPROVISION_UNCONFIRMED` |
| After A3 | R1 `DONE`, equal to a fresh ADMIN_SYNC; R2 equal to a fresh legacy sync, no loss (`RenameIntegrityTest`, `RadiusLegacyRenameTest`, both engines) | **Unchanged:** ADMIN_SYNC and rename still `REPROVISION_UNCONFIRMED` (`verified:false`) |
| Cause | Repeated rename (fixed: once per run, exact bytes) | Verification model: one `radusergroup` row per key, group `plan_<id>`. The last plan written overwrites the first, so the first plan's verification fails |
| Status | **CLOSED** by A3, with evidence | **OPEN, not closed by A3**. No claim of closure. It contradicts the handoff/plan wording (B-2) |

## 5. R2 path: no audit, drift or UI warning (item 4)

**What the approved plan says** (plan rev. 3 §4.5):

> "The legacy controller ignores the return value: no Stop wait and no new audit; each non-success is logged through `ErrorReporter` (as `disconnectCustomer` logs today). The legacy flow is otherwise unchanged and never throws because of CoA."

**Assessment:**
- **"No new audit"** is stated explicitly, so the documented limit is confirmed.
- **No drift entry** follows from it, because the drift kind reads only the audit event (§4.4). This is implied, not stated.
- **No UI warning** is implied by "the legacy flow is otherwise unchanged". It is not stated in so many words.
- The explicit sentence "R2 has no `old_session` audit, drift entry or UI note" appeared first in the acceptance candidate (residual R-a).
- **One deviation inside this limit: F8.** An AMBIGUOUS capture in R2 is not even logged, although §4.5 says every non-success is logged.

**Operational impact** (unassigned legacy customers only; reseller-owned customers use R1 with full audit and drift):
- **Better than before:** R2 now sends a Disconnect per captured row. The baseline sent none, so the old session always survived.
- **When the Disconnect fails or cannot be targeted** (CoA blocked, NAS silent, lost Accounting-Start, AMBIGUOUS holder):
  - the old session stays up until it ends by itself;
  - the operator still sees "User Updated Successfully";
  - nothing appears in the drift report;
  - the only trace is an `ErrorReporter` line (not even that for AMBIGUOUS, F8).
- **What happens while it stays up:**
  - the old name no longer has a RADIUS policy, so it cannot authenticate again;
  - but the customer can hold a parallel session under the new name (the CARRY-02 symptom) until the old session ends.
- **No extension was implemented:** adding an audit, drift entry or UI note for R2 would be a scope change and needs your decision. Only the F8 log line falls inside the approved plan text.

## 6. Smallest proposed fixes (not applied)

| Fix | For | Change | Size |
|---|---|---|---|
| FIX-1 | F1 (B-1) | `DriftReport.php:70-73`: ignore `REFUSED_AMBIGUOUS_IDENTITY` outcomes when taking the latest `reprovision.%` outcome (one condition). Alternative: write the refusal under its own event type (would also need the event name changed in 2 tests) | 1 line + regression test |
| FIX-2 | F3 | `ambiguousRename`: treat every source and target of this edit's pairs as "own" (pass them to `equivalentKeys` as an optional list). The in-transaction check stays per pair | ~6 lines |
| FIX-3 | F4 | `renamePairs`: drop pairs where from === to (MikroTik devices only lose a rename to the same name) | 2 lines |
| FIX-4 | F5 | `confirmOldSessions`: dedupe targets by `radacctid` across identities (first one wins). R2 duplicates remain (LOW, documented) | ~4 lines |
| FIX-5 | F6 | `customers.php:918`: `'w'` for UNCONFIRMED and NO_ACCOUNTING_EVIDENCE | 1 line |
| FIX-6 | F8 | `disconnectCaptured`: `ErrorReporter::log('radius.old_session', 'capture=' . state)` when state ≠ TARGETED (no secret) | 2 lines |
| FIX-7 (optional) | F7 | A separate reason/message for "RADIUS unreadable" (fail closed unchanged) | ~4 lines |

- **Files touched:** all fixes stay inside the 7 already-modified files. No schema, policy or FreeRADIUS change.
- **Tests after the fixes:**
  - the probes become regression tests (they now assert the defect, so they must be inverted);
  - A3 suites on both engines;
  - full harness;
  - **a short CHR re-run** of `RenameOldSessionLabTest` + `RealLifecycleTest`, because product files change.

## 7. Owner decisions needed

| ID | Question | Options | Recommendation |
|---|---|---|---|
| D-1 | Approve FIX-1 … FIX-6 (FIX-7 optional) inside A3? | A: approve; B: approve FIX-1 only and record the LOW items as residuals | **A**: small, inside the existing files, and they close plan deviations |
| D-2 | B-2: two Radius plans on the same key | A: accept as a documented pre-existing residual (correct the handoff/plan wording "every plan verified, R1 DONE" to the distinct-key case) and track it separately; B: extend A3 to make verification multi-plan aware (larger design change) | **A**: unchanged behaviour, already visible as `REPROVISION_UNCONFIRMED` (fails safe, not silent) |
| D-3 | B-3 / I1: a PPPoE username rename can replace another customer's policy | A: add the minimal validation (refuse a PPPoE username already used by another customer as username or PPPoE username, in R1 `validate()` and R2, where the upstream check is commented out at `customers.php:713-723`), before any write; B: record as a pre-existing carry-forward that blocks production | **A**: same rename-integrity family (OD-A3R-13), and it violates §R5.2 with real harm to another subscriber. It is a validation change and needs your approval |

## 8. Corrections to the H-2B comparison (item 5)

- **The error:** the candidate report compared A3 with the **historical** H-2B run `evidence/h2b-impl` (2026-10-11: 69 tests, **1 failure**). My earlier summary also said "the H-2B baseline failure now passes". That was misleading: the failure was already fixed inside H-2B.
- **The latest verified H-2B result** is `evidence/h2b-verify/lab-phpunit-wp8-integration.out` (2026-10-12): 69 tests / 1203 assertions, **0 failures**, 13 skipped, 3 warnings, 1 deprecation, exit 1.
- **The corrected comparison,** now in `WP8_A3_IMPLEMENTATION_ACCEPTANCE_CANDIDATE.md` §2.3:
  - A3 has 75 / 1346 (+6 tests, +143 assertions), 0 failures and 0 errors;
  - **the same 13 skipped tests** (lists compared, identical);
  - the same 3 warnings, from the same test;
  - 1 deprecation: the same count, but **its source was not shown** in the A3 run (no `--display-deprecations`), so "same deprecation" is **not proven**;
  - exit code 1.
- The candidate also points to the frozen harness rerun (§1 here).

## 9. PHP warnings and exit codes (item 6)

- `tests/phpunit.xml` is unchanged (`failOnWarning="true"`; tree hashes identical).
- The 3 pre-existing warnings (`system/autoload/Message.php:13-15` "Undefined variable $root_path") are kept and reported. The real exit code of the lab suite stays **1**.
- The harness exit code **0** is real, with no warnings on either engine.
- Incompletes and skipped tests are never counted as passes.

## 10. CHR evidence

- **Not re-run:** no product or test file affecting the lab changed (tree hashes identical).
- **Still valid within its limits:**
  - `RenameOldSessionLabTest` 6/6 (C1–C6);
  - the `RealLifecycleTest` flip;
  - `wp8-integration` 75 tests, 0 failures, exit 1 from the warnings only.
- **The limits:** C1–C6 do not cover F3, F4 or F5 (edge configurations), and the CHR runs predate any fix from §6.

## 11. Safety and state

| Check | Result |
|---|---|
| Product and test files | unchanged during this verification (1823 hashes identical at start, after the harness, and at the end) |
| Probes | outside the repository, mounted read-only; copies kept in `evidence/a3/verify/` |
| Containers | harness and probe containers removed (`nuxbill-reseller-test`: 0); the CHR lab stays down |
| Secret sweep | 0 hits over all changed and untracked files, including `verify/` (§12) |
| Git | HEAD = origin `wp8/integration-poc` `32a162d6`; `main` 500d3a2d, `feat` 5bdc5745, `hotfix` 6d4f3f96, `wp7` 8e7f0e1c, `wp6c` 757d8de9 unchanged. No commit, push, merge or deployment |
| AVAIA, production | untouched |

## 12. Files of this verification

- `owner-review/WP8/A3_FINAL_PRE_ACCEPTANCE_VERIFICATION_REPORT.md` (this report)
- `owner-review/WP8/evidence/a3/verify/`:
  - `harness-both-engines-rerun.out`;
  - `tree-before.sha256`, `tree-after.sha256`, `tree-now.sha256`;
  - `INDEPENDENT_CODE_REVIEW.md`;
  - `SameKeyAdminSyncProbeTest.php`, `ReviewFindingsProbeTest.php`, `BaselineComparableProbeTest.php`, `run-probes.sh` (last version);
  - `probes-run1.out`, `probes-run2.out`, `probes-run3.out`.
- `owner-review/WP8/WP8_A3_IMPLEMENTATION_ACCEPTANCE_CANDIDATE.md`: H-2B comparison corrected; frozen rerun referenced.

**Stopped for owner review.**
