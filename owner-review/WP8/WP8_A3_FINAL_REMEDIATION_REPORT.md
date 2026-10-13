# WP8 A3 — Final Remediation Report

| Item | Value |
|---|---|
| Date | 2026-10-13 |
| Owner decision | `A3_REMEDIATION_APPROVED — FINAL_VERIFICATION_REQUIRED` (D-1 approved, D-2 approved with documented residual, D-3 mandatory security fix) |
| Branch / HEAD | `wp8/integration-poc` @ `32a162d6` (= origin). Everything is uncommitted in the working tree. No commit, push, merge or deployment |
| Backup before remediation | `/srv/phpnuxbill/wp8-a3-remediation-backup-20261013T145704Z` (15 files, sha256 verified) |
| Evidence | `owner-review/WP8/evidence/a3/remediation/` |

## Verdict: **READY_FOR_OWNER_ACCEPTANCE**

- Every approved fix (FIX-1 … FIX-7, D-3) is implemented inside the 7 A3 product files, with regression tests.
- A second independent review of the first remediation found 1 MEDIUM and 3 LOW issues in my own fixes. All were fixed inside the same approved scope, each with a test that **fails on the reviewed version** and passes now.
- Final runs:
  - focused suites on MariaDB and MySQL: 28/28 exit 0;
  - frozen full harness: exit 0 on both engines, tree unchanged;
  - CHR (`RenameOldSessionLabTest` + `RealLifecycleTest`): 9/9 exit 0, on the same tree.
- Security, secret, manifest, diff and protected-branch checks are clean.
- One **pre-existing out-of-scope observation** is reported for your decision and was not implemented: name collisions at customer **creation** (§8, OBS-1).

## 1. Each fix and what actually changed

Line numbers refer to the final files.

| Fix | Finding | Change |
|---|---|---|
| FIX-1 | F1: a refusal hid an earlier unresolved `REPROVISION_UNCONFIRMED` | `DriftReport.php:72-75`: an outcome starting with `REFUSED_` (a refusal changes nothing) never supersedes the latest `reprovision.%` outcome. The older masking by a later `FAILED_REVERTED` stays as it was (pre-existing, documented) |
| FIX-2 | F3: false "ambiguous" refusal for chained pairs of one edit | `LegacyReprovisionService::renameCheck` (`:246-283`) checks every pair in run order. Pair *i* treats as the customer's own only the sources and targets of pairs 0..i-1, i.e. exactly what its write transaction will see (`RadiusRepository::equivalentKeys(..., $alsoOwn)`, `:210-212`). A genuinely foreign equivalent key is still refused |
| FIX-3 | F4: a no-op pair gave an UNCONFIRMED old session and drift | `renamePairs` (`:230`) drops pairs with `from === to` (exact bytes). The plan is still re-provisioned (`add_customer` in R1 and R2) |
| FIX-4 | F5: duplicate Disconnect for one session | (a) R1 `devices()` (`:752`) and R2 (`customers.php:913-916`) rename each **old key once**. After the first rename the old exact key holds nothing, so a second target is a no-op rename; its target is still pre-checked (R-1). (b) `confirmOldSessions` reuses the first result per radacctid (`:472-481`). (c) Inside one legacy rename scope, one Disconnect per radacctid (`:84, 302, 358-367`) |
| FIX-5 | F6: UI states | `oldSessionNoticeType()` (`:435`): `UNCONFIRMED` and `NO_ACCOUNTING_EVIDENCE` give `'w'` (warning), otherwise `'s'`. Used at `customers.php:936` |
| FIX-6 | F8: silent R2 non-success | `disconnectCaptured` (`:347-351`) logs `ErrorReporter::log('radius.old_session', 'capture=<STATE>')` when nothing can be targeted (AMBIGUOUS / CAPTURE_FAILED / NO_ACCOUNTING_EVIDENCE). The line holds the state only: no name, no secret |
| FIX-7 | F7: unreadable database reported as ambiguity | `renameCheck` returns `CHECK_UNAVAILABLE` on any read error: audit `REFUSED_CHECK_UNAVAILABLE`, code `RENAME_CHECK_UNAVAILABLE`, message "The identity check could not be completed (database unavailable); nothing was changed." Still fail-closed |
| D-3 | I1: a rename (e.g. a PPPoE username) could replace another subscriber's policy | See §4 |
| Test seam | — | `ActivationFaults::hit('reprovision.before_devices')` in `run()` (`:133`): a no-op unless a test sets a hook, like the existing `reprovision.old_session_*` points. Used only by the R1 race test |

**Corrections after the second independent review** (`INDEPENDENT_REMEDIATION_REVIEW.md`; delta in `review-fixes-product.diff`, +35/−11):

| ID | Sev | Correction |
|---|---|---|
| R-1 | MEDIUM | The pre-check had copied the per-`from` dedupe, so the target of a second pair with the same old key went unchecked although it is written. Now every pair is checked |
| R-2 | LOW | The exemption was cumulative over **all** pairs. Now only earlier pairs count, so there is no pass in the pre-check followed by a refusal in the write transaction |
| R-3 | LOW | Under READ COMMITTED, a target row committed between the re-check and the `DELETE` could be deleted. Now, for a caller that does not own the target, a `DELETE` that removes anything aborts and rolls back (`RadiusRepository.php:192-197`) |
| R-4 | LOW | The R2 PPPoE change was detected with the legacy loose `!=`, so `4200` → `42e2` skipped every check while the value was saved. Now a strict string comparison is used (`customers.php:713-715`) |
| R-5 / R-7 / R-8 | INFO | One Disconnect per session in a legacy scope; message wording; `ORDER BY id` in both R2 queries |

**Size:** the final product delta against the pre-remediation code is +190/−46 lines over 6 files (`CoAClient.php` unchanged) (`remediation-product-final.diff`). The A3 total against `HEAD`: `git diff --stat`.

## 2. Regression tests (no earlier assertion weakened)

**New tests:**

| File | New tests |
|---|---|
| `tests/Integration/Wp5b/RenameRemediationTest.php` (new) | 15: F1, F3 (×2), F4, F5, F7, F8, D-3 (×5), R-1, R-2, R-5 |
| `tests/Unit/Wp5b/RenameOldSessionUnitTest.php` | +4: F6 notice type, F4 pairs, D-3/R-3 transaction-order guard, F1 drift guard |
| `tests/Integration/Wp4/RadiusLegacyRenameTest.php` (R2, HTTP) | +3: D-3 PPPoE collision, D-3 foreign/orphan policy, R-4 strict comparison |
| `tests/Integration/Wp4/RadiusRenameIntegrityTest.php` | +1: D-3 repository refusal without ownership |

**Detection proof** (original failing runs kept):
- Against the **pre-remediation** code (backup mounted read-only), the 19 first-round tests fail or error (`pre-remediation-new-tests-run2.out`: exit 2, 2, 1, 1).
- Against the **reviewed intermediate** code (rebuilt from `remediation-product-first.diff`), the 5 review tests fail (`intermediate-review-tests.out`: exit 1, 1, 1).

**Debug records kept:**
- `pre-remediation-new-tests-run1.out`: my test helper `name()` clashed with PHPUnit's final `TestCase::name()`; renamed `userOf()`.
- `focused-both-engines-run1.out`: a real bug in my first fix; the rename closure did not capture `$replaceTarget`, so every existing target was refused. Also an F3 fixture that put two Radius plans on one key (B-2). Both fixed before run 2.
- `harness-ABORTED-for-review-fixes.out`: a frozen harness stopped at 31% because the review fixes changed the tree. Stopped by PID, containers removed.

**Adjustments to the new tests:** only the F3 fixture changed, and no earlier assertion. It now uses a single PPPoE plan, because two Radius plans on the same key cannot verify even in ADMIN_SYNC (the B-2 residual, D-2).

## 3. Results with real exit codes

| Run | MariaDB 10.11 | MySQL 8.0 | Evidence |
|---|---|---|---|
| Focused, final code: 14 affected suites (A3 suites + LegacyReprovision, RadiusRepository, RadiusDeviceLifecycle, DriftReport, Wp5bSourceGuard, MikrotikDevice, PluginBypass, LegacyEntrypointClosure) | 14/14 OK, exit 0, no warnings | 14/14 OK, exit 0, no warnings | `focused-both-engines-run3.out` |
| Full harness, frozen (`tree-before` = `tree-after`, 1824 files), 15:53–17:05Z | 1167 collected; **Tests 1082, Assertions 68517, Incomplete 85, Skipped 0, Failures/Errors/Warnings 0, exit 0** | the same: **1082 / 68517 / 85 / 0 / 0, exit 0** | `harness-both-engines.out` (`exit=0`, `TREE_UNCHANGED`; variant registry matches) |
| CHR lab focused: `--testsuite wp8-integration --filter 'RenameOldSessionLabTest\|RealLifecycleTest'`, 17:09–17:14Z | **OK, 9 tests, 269 assertions, exit 0** | — | `lab-chr-focused.out`, `lab-evidence-chr.jsonl` |

- **Harness growth:** 1059 → 1082 tests, which equals the 23 new tests.
- **Incompletes:** the 85 are lab-gated tests. They are **not counted as passes**.
- **CHR tree:** the CHR ran on the tree whose hash equals the harness tree (`tree-final.sha256` = `tree-before.sha256`).
- **PHP warnings:** the 3 pre-existing `Message.php:13-15` warnings did **not occur in this focused CHR run**. They appeared in the full `wp8-integration` runs (H-2B and the A3 candidate, exit 1). `failOnWarning` is unchanged, nothing is hidden, and the full lab suite was not re-run, as the directive allows.

**CHR evidence after the remediation:**

| Case | Result |
|---|---|
| C1 PPPoE | `DONE`/`CONFIRMED`; `run()` took 1.24 s, and the old session was gone 1.84 s from the run start; the new name logs in |
| C2 Hotspot `shared_users=2` | one Disconnect per session to its own NAS (10.88.0.2 and 10.88.0.3), both ACK plus Stop |
| C3 PPPoE key set, then cleared | `CONFIRMED` / `CONFIRMED` |
| C4 case-only rename | `CONFIRMED`; the new-spelling session survives |
| C5 CoA not accepted | `UNCONFIRMED` + drift `OLD_SESSION_UNCONFIRMED` |
| C6 lost Accounting-Start | `NO_ACCOUNTING_EVIDENCE` (residual L-2) |
| `RealLifecycleTest` S5 | `old_session: CONFIRMED` |

## 4. Proof that other subscribers are safe (D-3)

**Design:**
- **Before any write, R1 and R2:**
  - a PPPoE username held by another customer as username or PPPoE username (collation, so exact and equivalent spellings; the customer itself excluded by id) is refused with `PPPOE_USERNAME_TAKEN` (R1 `validate()` `:665-669`; R2 `customers.php:761-764`);
  - `renameCheck` refuses `AMBIGUOUS_IDENTITY` (an equivalent foreign key) and `IDENTITY_TAKEN`. The second applies when the exact target already holds RADIUS rows and is **not** one of the customer's own old keys, or a key this edit renames into first. Orphan policies count as well.
- **In the write transaction itself** (race backstop, `RadiusRepository::renameUser`):
  - after the locking read (`FOR UPDATE` on both names), an existing exact target is refused unless the caller owns it;
  - a `DELETE` that removes a target row of a non-owner aborts the transaction under any isolation level.
- **Who owns a target:** ownership comes from the customer's **rename scope** (`renameScope` / `targetReplaceable` / `renameApplied`).
  - It is opened in R1 `devices()` and around the R2 device loop, and closed in `finally` on both paths.
  - Outside a scope, an existing target is never replaced.
- **The legitimate case:** the customer's own old key, e.g. stale own rows under its old PPPoE username, may still be replaced (`testD3TheCustomersOwnOldKeyMayStillBeReplaced`).

| Proof (both engines) | Asserted |
|---|---|
| `testD3APppoeUsernameHeldByAnotherCustomerIsRefusedBeforeAnyWrite` (R1): the other's username, its UPPER-case spelling, and its PPPoE username | `PPPOE_USERNAME_TAKEN`. Both customers' rows (incl. passwords), the checksum of **every** radcheck/radreply/radusergroup/radacct row, and the other's exact policy are identical. Its open session is still open, and no Disconnect was attempted |
| `testD3AForeignOrOrphanPolicyUnderTheNewNameIsRefusedBeforeAnyWrite` (R1) | `RENAME_IDENTITY_TAKEN` / audit `REFUSED_IDENTITY_TAKEN`; everything byte-identical |
| `RadiusLegacyRenameTest` D-3 ×2 + R-4 (R2 over HTTP) | refused before the save; both customers and the full RADIUS checksum unchanged |
| **Race R1** `testD3RaceBetweenThePreCheckAndTheWriteIsClosedR1` | Another connection inserts a foreign policy under the new name **after** the pre-check and the identity write, just before the device step. Result: the write transaction refuses → `FAILED_REVERTED`; the foreign policy is byte-identical; the renamed customer's policy and identity are restored |
| **Race R2** `testD3RaceBetweenThePreCheckAndTheWriteIsClosedR2Device` | The pre-check returns OK, then a foreign row appears, then the device renames in the scope: `ProvisionFailure RADIUS_IDENTITY_TAKEN`; the foreign row is identical, nothing moved |
| Repository `testAnExistingExactTargetIsRefusedWhenTheCallerDoesNotOwnIt` | Rolled back, every row identical; a free target is still renamed |

**Limits of the race proof:** the race tests insert the foreign row **committed before** the write transaction starts. A true interleaving inside the transaction under READ COMMITTED is not reproduced. It is covered by the `DELETE`-count guard (unit source guard). The deployment default is REPEATABLE READ (no override in the code).

## 5. Lock Safety and Failure Audit (unchanged rules, re-verified)

- **Lock Safety:** the Disconnects and the ≤ 6 s Stop poll still run after `TreeSerialization::hold` returns, outside any transaction. The new pre-checks and the target check are DB reads or run inside the RADIUS write transaction; there is no network wait.
  - Source guards (`RenameOldSessionUnitTest`) still pass.
  - The concurrency test `testDisconnectsRunWithoutTheTreeLockOrATransactionAndKeepTheCapturedIdentity` still passes: the lock is free, there is no transaction, and only the captured row is targeted.
- **Failure Audit:** `RenameOldSessionTest` (20/20, both engines) still proves:
  - rename applied, then a failure → `REPROVISION_UNCONFIRMED` with `old_session` recorded;
  - FAILED_REVERTED only when nothing was applied;
  - CONFIRMATION_MISSING drift.
- **New:** every refusal is audited `REFUSED_*` and never supersedes an earlier unresolved outcome (F1). An in-transaction refusal yields the accepted `FAILED_REVERTED` with a real revert. No rollback after an applied rename and no blind retry.

## 6. Pre-existing limits that remain open (not changed by A3)

| ID | Limit |
|---|---|
| B-2 (D-2) | Two Radius plans on the **same** RADIUS key: ADMIN_SYNC and renames end `REPROVISION_UNCONFIRMED` (identical before and after A3). This is an operational limit that needs separate treatment before the configuration is production-ready. `REPROVISION_UNCONFIRMED` is never a success. The plan (§R4, §R5.1) and the handoff (§3) wording is corrected, with dated correction notes |
| R-9 | Plan-order effects when a chained pair moves another plan's freshly written rows (the B-2 family) |
| L-1 … L-5 | Overlap window; lost Accounting-Start (`NO_ACCOUNTING_EVIDENCE`, CHR C6); snapshot rule; RadiusRest (open before production); A2-R/H-2B slots |
| R2 | No `old_session` audit, drift or UI note on the legacy path (plan §4.5 "no new audit"). Non-success is now logged (FIX-6) |
| Residual name collisions | No DB unique constraint on customer names (a schema change is out of scope). An in-transaction refusal on R2 lands after `$c->save()` (only through a concurrent write). Several application databases on one RADIUS database. A colliding customer's own activation or sync (not a rename) can overwrite a policy. See OBS-1 |
| F1 context | A later `FAILED_REVERTED` still supersedes an earlier UNCONFIRMED in the drift report (pre-existing) |
| F5-keys | Stale own rows remain under a former username when username = PPPoE username and both change |
| R-6 | A no-op PPPoE change no longer forces a MikroTik reconnect (anticipated by FIX-3) |

## 7. Documentation corrections (D-2)

- **`WP8_A3_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md`:**
  - §R4 now separates "multi-plan rename touching one key" from "two Radius plans on the same key" (a pre-existing residual, unchanged by A3);
  - §R5.1 is qualified;
  - the event name is corrected from `reprovision.old_session` to `rename.old_session` in 4 places (review I4);
  - a dated "Corrections" section is appended.
- **`WP8_A3_HANDOFF.md` §3:** the shared-key criterion is corrected the same way.
- **`WP8_A3_IMPLEMENTATION_ACCEPTANCE_CANDIDATE.md`:** H-2B comparison corrected (earlier round).

## 8. New observation outside the approved scope (stopped there, not implemented)

**OBS-1:** customer **creation** does not check name collisions for the PPPoE username.
- **Evidence (code reading):**
  - `customers.php` `add-post` (`:533-592`) checks only `tbl_customers.username = $username` (`:573`), not against other customers' PPPoE usernames;
  - the new `pppoe_username` is saved unchecked (`:592`).
- **Consequence:** a new customer can take a name that another customer uses as a RADIUS key. The new customer's activation or sync (`applyUser`, not a rename) can then overwrite that policy.
- **Scope:** pre-existing, outside the rename paths (R1/R2) and therefore outside D-3. Other creation paths (registration, import, API) were not examined.
- **Proposed smallest fix (needs your approval):** the same `nameTakenByOther` check on `add-post` for the username (against PPPoE usernames) and for the PPPoE username. A full guarantee needs a DB uniqueness design, which is a schema decision.

## 9. Security and state checks

| Check | Result |
|---|---|
| Secret sweep (lab secrets.env + snmp.env values, private-key markers) | 64 changed or untracked files, **0 hits** |
| Protected files (`install/radius.sql`, `install/freeradius/*`, 9 hashes) | unchanged; no diff under `install/`, `docs/claude-development-pack/`, `tests/phpunit.xml` |
| Manifest | 42/42 |
| `git diff --check` | clean. `customers.php` keeps CRLF on all 1081 lines and has 0 real trailing spaces |
| A2-R, H1-A, H-2B, Option G, schema, finance, accounting history | untouched |
| Production / AVAIA | untouched; only the `nuxbill-reseller-test` project was used |
| Lab | down (links/netns/qemu/agent/containers/networks all 0); harness containers removed |
| Remote refs | `main` 500d3a2d, `feat` 5bdc5745, `hotfix` 6d4f3f96, `wp7` 8e7f0e1c, `wp6c` 757d8de9, `wp8` 32a162d6, all unchanged |

## 10. Modified files and Git status

**Product (7, uncommitted):**
- `system/autoload/Reseller/Domain/LegacyReprovisionService.php`
- `system/autoload/Reseller/Radius/RadiusRepository.php`
- `system/autoload/Reseller/Radius/RadiusProvisioner.php`
- `system/autoload/Reseller/Radius/CoAClient.php` (A3 only; unchanged by the remediation)
- `system/devices/Radius.php`
- `system/autoload/Reseller/Activation/DriftReport.php`
- `system/controllers/customers.php`

**Lab tooling:** `dev/wp8/client_agent.py` (A3, unchanged by the remediation).

**Tests:**
- Modified tracked: `tests/Integration/Wp8/RealLifecycleTest.php`.
- Untracked (new):
  - `tests/Unit/Wp5b/RenameOldSessionUnitTest.php`
  - `tests/Integration/Wp5b/{RenameOldSessionTest, RenameIntegrityTest, RenameRemediationTest}.php`
  - `tests/Integration/Wp4/{RadiusRenameIntegrityTest, RadiusLegacyRenameTest}.php`
  - `tests/Integration/Wp8/RenameOldSessionLabTest.php`

**Documents (untracked):**
- this report;
- `A3_FINAL_PRE_ACCEPTANCE_VERIFICATION_REPORT.md`;
- `WP8_A3_IMPLEMENTATION_ACCEPTANCE_CANDIDATE.md`;
- the plan and handoff (corrected);
- `WP8_A5_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md`;
- `evidence/a3/` (`verify/`, `remediation/`).

**Git:** HEAD = origin `wp8/integration-poc` = `32a162d6`. No commit, push or merge.

**Stopped for owner review.** A4, A5 and V-1 are not started.
