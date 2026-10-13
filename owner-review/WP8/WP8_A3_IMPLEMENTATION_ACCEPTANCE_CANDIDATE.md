# WP8 A3 — IMPLEMENTATION & SECURITY ACCEPTANCE CANDIDATE

`R-WP8-CARRY-02` (the old-identity session after a rename, plus the rename-integrity sub-defect OD-A3R-13).

| Item | Value |
|---|---|
| Branch / HEAD | `wp8/integration-poc` @ `32a162d6` (H-2B). Nothing is committed or pushed, and the working tree is not committed |
| Plan | `WP8_A3_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md` rev. 3 plus addendum; decisions OD-A3R-1 … 14 unchanged |
| Backups | `/srv/phpnuxbill/wp8-a3-prefix-backup-20261012T130010Z` (7/7), `/srv/phpnuxbill/wp8-a3-scope2-backup-20261013T101117Z` (10/10) |
| Evidence | `owner-review/WP8/evidence/a3/` |
| Status | **CANDIDATE — awaiting owner review.** No commit, push or merge |

## 1. What was implemented

### 1.1 Rename integrity (OD-A3R-9 / 11 / 13; F-1 … F-5)
- `RadiusRepository::renameUser` works on **exact bytes**: `DELETE` of the target and `UPDATE` of the source both add `CAST(username AS BINARY) = CAST(? AS BINARY)`.
  - An equivalent rename (case or accent only) updates the subscriber's own rows in place. Before, it deleted them (case A).
- Another key that the collation treats as equal to the source or the target refuses the whole rename. The check runs inside the transaction (`FOR UPDATE`), before any write: `FAILED RADIUS_IDENTITY_AMBIGUOUS`, rolled back (case B). `RadiusProvisioner::run` returns that refusal as a normal result.
- The rename is verified by exact bytes (`exactRows($from) === 0`), so the equivalent rename verifies correctly.
- **R1** (reseller-owned, `LegacyReprovisionService` IDENTITY_EDIT):
  - an ambiguous rename is refused **before any write**: audit `REFUSED_AMBIGUOUS_IDENTITY`, `DomainError RENAME_AMBIGUOUS_IDENTITY`;
  - no `FAILED_REVERTED` is used, because nothing was reverted.
- **R2** (unassigned legacy, `customers.php`, OD-A3R-1): the same pre-check runs before the customer row is saved, and the error is shown in the form.
- **Case C:** the shared RADIUS database renames each `from→to` key **once per run**. A second Radius plan on the same key is only re-provisioned. This applies to R1 and R2, and both use the shared `renamePairs()`.

### 1.2 Old-identity session (OD-A3R-5 / 6 / 7 / 8)
- `Radius::change_username`:
  - **before the rename:** the equivalence check against the `radacct.username` collation; for an equivalent rename, a snapshot of the open rows; the holder check (another customer still holding the old name);
  - **after the rename:** the capture, which is the old identity's open rows (equivalent rename: only the snapshot rows).
- Inside `LegacyReprovisionService::run()` the capture is **handed over**, and the Disconnects are sent **after `TreeSerialization::hold` returns**, outside any transaction. On the legacy R2 path they are sent at once (no tree lock there).
- `CoAClient::disconnectSession($row)`: one Disconnect-Request per captured row, to that row's NAS. It uses the existing 1 + 2 identical sends and never a new request.
- Confirmation: the ACKed rows are polled for their Accounting-Stop **by radacctid**, bounded at **6 s in total**.
- Session states:

| Result | State |
|---|---|
| ACK and Stop seen | CONFIRMED |
| NAK 503 | CONFIRMED (that session only) |
| NAK 406, no answer, unknown NAS, exception, or ACK without Stop | UNCONFIRMED, with the reason |

- Per-identity states:

| Condition | State |
|---|---|
| No open row | NO_ACCOUNTING_EVIDENCE |
| `AMBIGUOUS` holder, `CAPTURE_FAILED`, or a rename attempted without a capture | UNCONFIRMED |

- The run value is the **worst** identity state. It is returned as `old_session` and **never changes the re-provision status**.
- Audit: its own event `rename.old_session`, kept outside `reprovision.%` so the existing `REPROVISION_UNCONFIRMED` drift is unaffected. The main `reprovision.*` audit carries `old_session: PENDING` (or `NOT_APPLICABLE`).
- `DriftReport` adds the kind `OLD_SESSION_UNCONFIRMED`:
  - `state UNCONFIRMED` (the confirmation said so);
  - `state CONFIRMATION_MISSING` (PENDING announced, confirmation never written).
- `customers.php`: an UNCONFIRMED `old_session` on the R1 edit is shown as a warning, with a one-line note.

## 2. Test results (real exit codes; incompletes are never counted as passes)

| Run | MariaDB 10.11 | MySQL 8.0 | Evidence |
|---|---|---|---|
| A3 suites (Unit 13/43, RadiusRenameIntegrity 5/17, RenameIntegrity 4/67, RadiusLegacyRename 2/23, RenameOldSession 20/325) | all OK, exit 0 | all OK, exit 0 | `a3-tests-both-engines.out` |
| Full harness `dev/test/run-tests.sh` | OK, Tests 1059, Assertions 68221, Incomplete 79, **0 failures/errors** | OK, Tests 1059, Assertions 68221, Incomplete 85, **0 failures/errors** | `harness-both-engines.out` (exit 0) |
| `wp8-integration` without the lab (incomplete listing) | 75 incomplete (69 baseline + 6 new lab tests) | the same 75 | `wp8-suite-incomplete-listing.out` (exit 0) |
| Variant registry reproducibility | matches | matches | in the harness output |

- **Why 79 vs 85:** the harness started at 10:39:06Z, and the last write of the new lab test file was 10:41:42Z. MariaDB collected its tests before the file existed (1138 collected), MySQL after (1144). Every non-incomplete result is identical on both engines. The suite-only listing gives the same 75 on both.
- **Superseded by the frozen rerun (2026-10-13, 12:58–14:04Z, `evidence/a3/verify/harness-both-engines-rerun.out`).** Both engines collected 1144 tests: Tests 1059, Assertions 68221, Incomplete 85, Skipped 0, Failures/Errors 0, exit 0. The tree hashes before and after the run are identical (`tree-before.sha256` = `tree-after.sha256`, 1823 files).
- **Baseline proof (before the fix, from the scope2 backup, bind-mounted read-only):**
  - the new tests fail on the baseline: Unit exit 2, Integration exit 2, repository exit 1 (3 failures), service exit 2, legacy exit 1;
  - case C is proven: R1 ends `REPROVISION_UNCONFIRMED`, and R2 silently loses the policy under the new key.
  - Evidence: `baseline-new-tests-fail.out`, `baseline-rename-integrity-fail.out`.

### 2.1 Real CHR (RouterOS 7.24.5; CHR1 10.88.0.2, CHR2 10.88.0.3; FreeRADIUS 3.2.8; Option G shipped; site `project`)

`RenameOldSessionLabTest` — **run 2: OK, 6 tests, 141 assertions, exit 0** (`lab-rename-old-session-run2.out`, `lab-evidence-a3.jsonl`).

| Case | Result on the NAS |
|---|---|
| C1 PPPoE rename | `DONE` / `CONFIRMED`; one Disconnect, ACK plus Stop. `run()` took 0.72 s (0.94 s in run 1); the old session was gone 1.32 s from the run start (2.30 s). The new name logs in; never two live sessions |
| C2 Hotspot `shared_users=2` on two NAS | `CONFIRMED`; one Disconnect per session, **each to its own NAS** (10.88.0.2 and 10.88.0.3), both ACK plus Stop; no Hotspot session of the old name left |
| C3 PPPoE key set, then cleared | both `CONFIRMED`; the former key's session ended each time |
| C4 case-only (equivalent) rename | `CONFIRMED`, `equivalent: true`; the pre-rename session ended; policy kept under the new bytes; a new session under the new spelling starts **and survives 8 s** (never targeted) |
| C5 `/radius incoming accept=no` | `DONE` / `UNCONFIRMED`, session state `COA_NO_RESPONSE`; the old session survives (no retry, no revert); drift `OLD_SESSION_UNCONFIRMED state UNCONFIRMED` |
| C6 Accounting-Start lost (udp 1813 dropped on the NAS) | `DONE` / `NO_ACCOUNTING_EVIDENCE`, never CONFIRMED; the live session remains (residual L-2) |

- **Run 1** had 5/6 passing (`lab-rename-old-session-run1.out`, kept). C2 failed with `c7: RADIUS server is not responding`.
  - The cause was the lab setup: `a2r_gate.py chr2 setup` had not been run, so FreeRADIUS did not load CHR2 as a client. This was **not a product defect**.
  - The step was run (logged in `lab-console.out`) and the class was re-run unchanged.

### 2.2 `RealLifecycleTest` flip (OD-A3R-4, after the CHR proof above)
`testLegacyReprovisionPasswordAndUsernameOnTheRealNas` now expects:
- the rename ends `DONE` / `CONFIRMED`;
- the old-identity session is gone;
- the new name logs in, with `[old, new] = [0, 1]` live sessions.

Before, it expected the OD-W8-15 observation "old session still up; a parallel session possible". The rest of the test is unchanged.

Full lab suite `wp8-integration`: **see §2.3**.

### 2.3 Lab `wp8-integration` (whole suite, real CHR)
`dev/wp8/lab.sh test --testsuite wp8-integration` (12:00–12:42Z, 41:58), with the lab set up as: up, Option G shipped, up-chr2, h2 setup, a2r chr2 setup, site project. Evidence: `lab-phpunit-wp8-integration.out`, `lab-phpunit-evidence-all.jsonl`.

> **Correction (2026-10-13, final pre-acceptance verification).** The first version of this table used the historical H-2B run `evidence/h2b-impl` (2026-10-11), which still had 1 failure. That failure was fixed within H-2B. The **latest verified H-2B result** is `evidence/h2b-verify/lab-phpunit-wp8-integration.out` (2026-10-12), with 0 failures. A3 is now compared against that run.

| | H-2B historical (`h2b-impl`, 2026-10-11) | **H-2B latest verified (`h2b-verify`, 2026-10-12)** | A3 candidate (2026-10-13) |
|---|---|---|---|
| Tests / assertions | 69 / 1203 | 69 / 1203 | **75 / 1346** (+6 lab tests, +143 assertions from A3) |
| Failures / errors | 1 (`OptionGGateTest::testShippedHotspotShared2`) / 0 | **0 / 0** | **0 / 0** (A3 fixed no H-2B failure; there was none left to fix) |
| Skipped | 13 | 13 | 13: the same 13 tests (lists compared, identical) |
| Warnings | 3 | 3: `Message.php:13-15` "Undefined variable $root_path" | 3, the same, from the same test (`RealLifecycleTest::testLegacyReprovisionPasswordAndUsernameOnTheRealNas`) |
| Deprecations | 1 | 1: `PEAR2/Autoload.php:206` `is_file(null)` (`FailureDrillsTest::testDrill8RouterOsApiUnavailable`) | 1, same count. **The source was not displayed** (run without `--display-deprecations`), so "the same deprecation" is not proven |
| **PHPUnit exit code** | 1 | **1** | **1** |

- **The exit code is 1, and it is reported as is.** Its only cause is `failOnWarning="true"` in `tests/phpunit.xml` together with the 3 pre-existing PHP warnings above, which also appear in both H-2B runs. The run had no failure and no error.
- The flipped lifecycle assertion passed on the CHR. Its evidence is `S5.reprovision`: `old_session: CONFIRMED`, `old_identity_live_session_after_rename: []`, and `nas_old 0 / nas_new 1`.
- An earlier launch at 11:57Z was **aborted on purpose**. `lab.sh test` adds `--testsuite wp8-integration` only when called with no arguments, so passing the display flags started the whole matrix inside the lab runner. The task and its runner container were stopped, and the lab state was re-verified before the rerun. The partial output is kept in `lab-phpunit-ABORTED-wrong-suite.out`.

## 3. Lock check (Lock Safety condition)

- **No network wait inside the tree lock from A3.**
  - The Disconnects and the 6 s Stop poll run in `confirmOldSessions()`, called from `run()` **after** `TreeSerialization::hold()` returns (also on the UNCONFIRMED path, where the closure threw).
  - Inside the lock, A3 adds only database reads: the equivalence and holder checks, and the capture.
- **Source guards** (`RenameOldSessionUnitTest`):
  - in `run()`, `TreeSerialization::hold(` comes before `self::confirmOldSessions(`;
  - the closure contains neither the confirmation nor a disconnect;
  - `confirmOldSessions` contains no `Tx::run(` and no `TreeSerialization::`;
  - `change_username` order: before, `->rename(`, after, defer, then the legacy send; no `disconnectCustomer(` and no `throw`.
- **Concurrency test** (`testDisconnectsRunWithoutTheTreeLockOrATransactionAndKeepTheCapturedIdentity`):
  - at the moment of sending, a **second connection takes the tree lock with `GET_LOCK(name, 0)` = 1** (free), and `inTransaction() = false`;
  - between capture and send, a new session of the **new** name and a new session of the **old** name are inserted;
  - exactly 1 Disconnect is sent, to the captured row only, and the result is CONFIRMED.
- **Other subscribers are never targeted:**
  - another subscriber with an equivalent name: nothing is sent (`testAnotherSubscriberWithAnEquivalentNameMeansNothingIsSent`);
  - another customer holding the old name: AMBIGUOUS, nothing is sent (`testAnotherCustomerHoldingTheOldNameMeansNothingIsSent`);
  - equivalent rename: only the snapshot rows are targeted (`testEquivalentRenameTargetsOnlyTheSessionsOpenBeforeTheRename`);
  - on the CHR, C4: the new-spelling session survives.
- **Pre-existing, unchanged:** the accepted WP4 policy refresh (`add_customer` → `disconnectCustomer(new key)`) still runs inside the lock. It was not touched by A3 and is reported as is.

## 4. Partial-failure cases (Failure Audit condition)

| Case | Re-provision status | `old_session` | Proof |
|---|---|---|---|
| Rename refused/failed before any change (FAILED, reverted) | `FAILED_REVERTED` | `NOT_APPLICABLE`, nothing sent | `testRenameFailedIsRevertedAndNothingIsSent` |
| Rename applied, then `add_customer` failed | `REPROVISION_UNCONFIRMED` (no revert, no retry: unchanged) | **recorded** (`rename.old_session`, Disconnect sent after the lock) | `testRenameAppliedThenAddFailedStillRecordsTheOldSession` |
| Rename outcome unknown (attempt without a capture) | `REPROVISION_UNCONFIRMED` | `UNCONFIRMED`, reason `rename_not_confirmed` | `testUnconfirmedRenameIsAnUnconfirmedOldSession` |
| Confirmation event never written (crash after the main audit) | unchanged | drift `OLD_SESSION_UNCONFIRMED state CONFIRMATION_MISSING` (PENDING marker) | `testAMissingConfirmationEventIsReportedByTheDriftReport` |
| ACK without Stop within 6 s | `DONE` | `UNCONFIRMED` (`ack_without_stop`) | `testAckWithoutStopIsUnconfirmedAfterTheBoundAndStatusStaysDone` |
| NAK 503 / NAK 406 | `DONE` | CONFIRMED / UNCONFIRMED | `testNak503IsConfirmedForThatSessionAndNak406IsNot` |
| No answer / unknown NAS | `DONE` | `UNCONFIRMED`, no retry, no revert | `testNoAnswerAndUnknownNasAreUnconfirmedWithoutRetryOrRevert`; CHR C5 |
| No open accounting row | `DONE` | `NO_ACCOUNTING_EVIDENCE`, nothing sent | `testNoOpenRowIsNoAccountingEvidenceAndNothingIsSent`; CHR C6 |
| Ambiguous rename (R1 / R2) | refused before any write | — | `RenameIntegrityTest`, `RadiusLegacyRenameTest`, `RadiusRenameIntegrityTest` |

## 5. Modified files

**Product (uncommitted):**

| File | +/− |
|---|---|
| `system/autoload/Reseller/Domain/LegacyReprovisionService.php` | +326 / −16 |
| `system/devices/Radius.php` | +89 |
| `system/autoload/Reseller/Radius/RadiusRepository.php` | +41 / −2 |
| `system/controllers/customers.php` | +22 / −15 (file is CRLF; added lines keep CRLF, so `git diff --check` reports CR only, with no real trailing spaces) |
| `system/autoload/Reseller/Activation/DriftReport.php` | +19 |
| `system/autoload/Reseller/Radius/CoAClient.php` | +11 |
| `system/autoload/Reseller/Radius/RadiusProvisioner.php` | +5 / −4 |

**Lab tooling:** `dev/wp8/client_agent.py` (+9 / −5): Hotspot clients c7/c8 on the CHR2 segment, with a per-client gateway.

**Tests:**
- New:
  - `tests/Unit/Wp5b/RenameOldSessionUnitTest.php`
  - `tests/Integration/Wp5b/RenameOldSessionTest.php`
  - `tests/Integration/Wp4/RadiusRenameIntegrityTest.php`
  - `tests/Integration/Wp5b/RenameIntegrityTest.php`
  - `tests/Integration/Wp4/RadiusLegacyRenameTest.php`
  - `tests/Integration/Wp8/RenameOldSessionLabTest.php`
- Modified tracked test: `tests/Integration/Wp8/RealLifecycleTest.php` (§2.2).

**Test adjustments under OD-A3R-14 (none weakens an assertion):**
1. `RenameOldSessionTest`:
   - the radcheck-ambiguity case now expects the OD-A3R-9 refusal: nothing written, no confirmation;
   - H-T1: `assertCount` before `end()`;
   - the dedupe fixture uses a distinct PPPoE key (the real case C);
   - the stub log field is `auth`, plus an id+auth identical-retransmission check;
   - the new-name row is on an unconfigured NAS, so the pre-existing WP4 new-key disconnect does not consume the stub.
2. `RadiusLegacyRenameTest`: the helper was renamed to `legacyCustomer` (it clashed with `Wp2HttpTestCase::customer()`).
3. The `RenameIntegrityTest` / `RadiusLegacyRenameTest` fixtures use distinct keys per plan. Two plans on the SAME key cannot verify even on ADMIN_SYNC; that is a pre-existing property, not A3.

**Documents (untracked):** this report, the plan, the handoff, and `evidence/a3/*`.

## 6. Security / QA checks

| Check | Result |
|---|---|
| Protected files (`install/radius.sql`, `install/freeradius/*`, 9 hashes) | unchanged (`sha256sum -c` OK); `git diff -- install/ docs/claude-development-pack/` empty |
| Development pack manifest | 42/42 OK |
| Secret sweep (lab secrets.env and snmp.env values plus private-key markers, over every changed and untracked file) | 0 hits (re-run at the end: see §8) |
| `git diff --check` | clean except the CRLF note above |
| Schema, finance, accounting history, A2-R / H1-A / H-2B / Option G policy | untouched |
| AVAIA, production | untouched; only the `nuxbill-reseller-test` project was used |
| Code review of the diff | no defect found. The holder check in R2 runs after `$c->save()` (line 836 vs the device loop at 897), so the customer is never its own holder. `Lang::T` with the new message does not write language files (WP6A) |

## 7. Residual risks

| ID | Residual |
|---|---|
| L-1 | Overlap window between the rename and the Disconnect. Measured on the CHR: the old session was gone 1.3–2.3 s after `run()` started. A silent NAS is bounded by the CoA resends (≈ 6 s per session) plus the 6 s Stop bound, outside the lock |
| L-2 | A live old session without any `radacct` row (lost Start) cannot be targeted: `NO_ACCOUNTING_EVIDENCE`, never CONFIRMED (CHR C6) |
| L-3 | Equivalent rename: a session that starts between the snapshot and the rename is not targeted (snapshot rule) |
| L-4 | `RadiusRest` (rlm_rest) mode is out of scope and stays an open limit before production (OD-A3R-3) |
| L-5 | Old-name A2-R reservations and H-2B slots end by their own Stop, or stay per Strict Mode; A3 never changes them |
| R-a | R2 (unassigned legacy) sends the Disconnects but has no `old_session` audit, drift entry or UI note (the R1 service path has all three) |
| R-b | Several targets on a silent NAS are sent one after another (≈ 6 s each). There is no lock, but the admin request takes longer |
| R-c | Pre-existing, not A3: two Radius plans on the SAME key make even ADMIN_SYNC end `REPROVISION_UNCONFIRMED`; the WP4 new-key policy-refresh disconnect remains inside the lock |
| R-d | The `reconcile.php` docblock does not list the new drift kind (documentation only) |

## 8. Final state

- **Lab:** `h2_gate.py teardown` and `lab.sh down`, both rc 0. `lab.sh status`: links 0, netns 0, qemu 0, agent 0, containers 0, networks 0. The generated `dev/wp8/__pycache__` was removed.
- **Final secret sweep:** 31 changed or untracked files, 0 hits. Protected hashes OK; manifest 42/42 OK.
- **Git:**
  - local HEAD = origin `wp8/integration-poc` = `32a162d6`;
  - origin `main` 500d3a2d, `feat` 5bdc5745, `hotfix` 6d4f3f96, `wp7` 8e7f0e1c, `wp6c` 757d8de9 are unchanged;
  - working tree: 9 modified tracked files (§5) plus the untracked tests and documents.
- **Next steps, only after owner acceptance:** commit and push to `wp8/integration-poc` per the stage workflow, then A4.

**Stop for owner review.** No commit, push or merge. A4 / A5 / V-1 are not started.
