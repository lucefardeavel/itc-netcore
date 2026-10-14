# WP8 A5 — `R-WP8-CARRY-01` Implementation and Security Acceptance Report

| Item | Value |
|---|---|
| Date | 2026-10-13 |
| Owner decisions | `A5_PLAN_APPROVED — IMPLEMENTATION_AUTHORIZED` (plan §0–§10 + addendum §11; C4 cancelled; T7 not part of A5); then `A5_IMPLEMENTATION_REVIEWED — FINAL_SAFETY_FIX_AUTHORIZED` (2026-10-14): **D-A5-1** mandatory fix of L-A5-1, **D-A5-2** approved test-only correction, **L-A5-2** documented production blocker (§10) |
| Baseline | `wp8/integration-poc@8ed4cb3da4e125ad017393d3bb70a1d5ab0120a3` (= origin, unchanged) |
| Round | Development only. No commit, push, merge or deployment; no production; no schema, finance, cron, RADIUS or protected-file change |
| Verdict | **`A5_READY_FOR_FINAL_OWNER_ACCEPTANCE`** (§10.5) |

## 1. Changes and why

**Product code (7 lines for C1–C3, plus 20 lines for D-A5-1 in `plan.php` / `customers.php`, §10; line endings kept: `plan.php` LF, `customers.php` and `home.php` CRLF):**

| # | File / place | Change | Why |
|---|---|---|---|
| C1 | `system/controllers/plan.php` `case 'delete'` (`:437`) | `ServiceGate::guardCustomer($d['customer_id'], 'planDelete', 'plan/list')` after the recharge row is found, **before** `run_hook('delete_customer_active_plan')` | P1 deleted a reseller customer's recharge and suspended RADIUS outside reversal |
| C2 | `system/controllers/customers.php` `case 'deactivate'` (`:277`) | `ServiceGate::guardCustomer($id_customer, 'customerDeactivate', 'customers/view/' . (int) $id_customer)` after the CSRF check, **before** the recharge lookup | P2 ended service outside reversal |
| C3 | `system/controllers/home.php` deactivate (`:202-203`) | `guardCustomer($user['id'], 'portalDeactivate', 'home')` **and** `guardCustomer($bill['customer_id'], 'portalDeactivate', 'home')` after the row is found, **before** the device | P3 self-deactivation. The row is matched by **username**, so both owners are checked (T1b) |

Each insertion carries a short comment. `ServiceGate.php` and `RouteMap.php` are **unchanged** (C4 cancelled): `tests/Unit/Wp3a/SourceGuardTest.php` pins both by SHA-256, and that test still passes.

**Documentation:** `docs/WP5B_LEGACY_CLOSURE_MODEL.md` §13 addendum (rule, paths, refusal behaviour, unchanged paths, tests, open items).

**Tests (new):**
- `tests/Unit/Wp8/LegacyRemovalSourceTest.php` (T9);
- `tests/Http/LegacyRemovalGateTest.php` (T1, T1b, T1c, T2, T3, T4, T5, T6, T6b, T8).

**Test-only fix outside the A5 files (owner decision D-A5-2, 2026-10-14: APPROVED as a test exception inside the A5 package):** `tests/Integration/Wp4/AccountingTimeContractTest.php` (A4, committed in `8ed4cb3d`), +3 / −2 lines.
- **What failed:** the full harness run 2 failed on **MySQL**, 1 error in `testTheStaleQueryKeepsTheAccessPathOfThePreviousQuery`: `1062 Duplicate entry … for key 'radacct.acctuniqueid'`.
- **Cause:** the performance fixture built unique session keys as `MD5(CONCAT(radacctid, '-', RAND()))` over 15 self-doubling inserts, and MySQL's RAND() can repeat a value for the same row. It is a rare flake: it passed in every earlier A4 run, in A5 run 1 and on MariaDB in the same harness.
- **Fix:** the keys are now deterministic, `MD5(CONCAT(radacctid, '-', <prefix>-<step>))`. Only the fixture changed: no assertion and no product code. The committed version is kept in the backup (`AccountingTimeContractTest.php.a4-committed`).
- **Verification:** `post/focused-run5-a4-perf-fixture-fix.out`, 3 repetitions per engine, all exit 0 (11 tests / 666 assertions each).
- **Status:** approved by D-A5-2. The original failure is kept (`harness/harness-run2-FAILED-a4-perf-fixture-flake.out`), no assertion or A4 product code changed, and the final harness (§10.4) re-runs it on both engines.

**Deviation from plan §9 (recorded):** T2 (reversibility) is inside the HTTP class instead of a separate `tests/Integration/Wp5b/LegacyRemovalReversibilityTest.php`. It uses the same real activation fixture, and the coverage is unchanged.

**Not changed:**
- the earlier gates: P4 cron, P5 plan edit, P6 customer delete;
- `ServiceGate`, `RouteMap`, `SchemaReadiness`;
- devices, RADIUS, FreeRADIUS, schema, finance, activation and reversal services;
- UI, `tests/phpunit.xml`, A1–A4 code.

`git diff 8ed4cb3d` is empty for `install/`, `init.php`, `radius.php`, `system/cron.php`, `system/devices`, `system/autoload`, `ui/`, `dev/`, `tests/phpunit.xml` and `docs/claude-development-pack`.

## 2. How the refusal works (verified in code and by the independent review)

- **What happens:** `guardCustomer` reads `manager_id` fresh, then calls `refused()`, which never throws. That writes the `service.refused` audit (schema ready) plus a sanitized log line. Then `r2()` ends the request: `exit` on the web, `showResult()` → `die()` in the API.
- **Nothing after the gate runs** for a refused customer. CSRF returns true under `$isApi`, so the API reaches the gate before any effect too.
- **Schema not ready:** the gate refuses every customer (owner-accepted R-2). Only the log line is written (no audit) and the not-ready message is shown.
- **Ownership read fails:** the gate refuses (fail closed).

## 3. Tests before and after the fix (test-first)

### 3.1 Before the fix (the defect, proven)

| Run | Code | Result (both engines) |
|---|---|---|
| `evidence/a5/pre/pre-fix-run1.out` (first test revision) | working tree before C1–C3 | **T9:** 3 tests, 3 failures, exit 1. **HTTP:** 10 tests, 8 failures, exit 1 (only T3 and T8 pass) |
| `evidence/a5/post/focused-run4-final-tests-baseline-vs-fixed.out`, "BASELINE" part (**final test revision**) | the 3 baseline controllers from the verified backup, mounted read-only over the fixed tree | **T9:** 3 tests, 3 failures, exit 1. **HTTP:** 11 tests, 105 assertions, **8 failures**, exit 1. Failing: T1-P1, T1-P2, T1-P3, T1b, T2, T4, T5, T6b. Passing: T1c (positive control), T3 (legacy characterisation), T8 (shared gate) |

**What the baseline did** (pre-fix failures):
- **P1 / P2 / P3 on a reseller customer with a COMPLETED activation:** `radcheck` changed (RADIUS suspended) and `tbl_user_recharges` was deleted or set `off`.
- **API P1:** `{"success":true,"message":"Data Deleted Successfully"}`.
- **Reversal afterwards:** stayed `COMPLETED`, because reversal had become impossible.
- **Schema not ready:** the recharge still changed.

### 3.2 After the fix

| Run | Result |
|---|---|
| `post/focused-run1.out`: A5 tests + 19 regression suites × 2 engines (42 runs) | 40 × exit 0. **`LegacyRemovalGateTest` exit 1 on both engines:** a **bug in my test**. The Agent and Sales sessions were opened after the snapshot, so their `last_login` write was seen. The sessions are now opened first; the product code was not changed. Kept |
| `post/focused-run2-a5-tests.out` | A5 tests, both engines: exit 0 (3/30, 10/149) |
| **Independent review** (§7) → test-evidence fixes | the full harness then running was **stopped** (process group; leftover runner container and network removed by exact name) → `harness/harness-ABORTED-review-test-fixes.out`, kept |
| `post/focused-run3-…out` | final revision: fixed-code T9 **exit 1**, because of a PHP escaping bug in **my** mutation regex (`\$b` in a double-quoted string). Fixed; kept |
| **`post/focused-run4-final-tests-baseline-vs-fixed.out`**, "FIXED" part | **MariaDB:** T9 3 tests / 27 assertions **exit 0**; HTTP 11 tests / 167 assertions **exit 0**. **MySQL:** same, **exit 0** |

**Regression suites (run 1; the product code is identical in the final tree), exit 0 on both engines:**

| Suite | Tests / assertions |
|---|---|
| `Wp3a/SourceGuardTest` (SHA pins incl. ServiceGate / RouteMap) | 10 / 37057 |
| `Wp5bSourceGuardTest` | 9 / 570 |
| `AccountingTimeUnitTest` (A4) | 6 / 79 |
| `Http/ServiceGateTest` | 10 / 264 |
| `Http/CsrfTest` | 9 / 75 |
| `Http/RouteMatrixTest` | 3 / 3638 |
| `Http/SecurityRoutesTest` | 11 / 203 |
| `Http/SchemaNotMigratedTest` | 2 / 29 |
| `Http/ApiTest` | 5 / 177 |
| `Http/CustomerOwnershipTest` | 9 / 75 |
| `Wp5b/ActivationReversalTest` | 14 / 305 |
| `Wp5b/LegacyEntrypointClosureTest` | 6 / 62 |
| `Wp5b/DriftReportTest` | 1 / 19 |
| `Wp5b/PluginBypassTest` | 1 / 20 |
| `Wp6a/Subscriber360Test` | 4 / 137 |
| `Wp4/RadiusAdminFlowTest` | 3 / 24 |
| `Wp5b/RenameRemediationTest` (A3) | 15 / 242 |
| `Wp5b/RenameOldSessionTest` (A3) | 20 / 325 |
| `Wp4/AccountingTimeContractTest` (A4) | 11 / 666 |

### 3.3 Full harness, frozen tree, both engines (`evidence/a5/harness/`)

| Run | Result |
|---|---|
| run 1 (`harness-ABORTED-review-test-fixes.out`) | stopped at about 50 % of MariaDB to apply the review's test-evidence fixes (process group; leftover runner container and network removed by exact name). Kept |
| run 2 (`harness-run2-FAILED-a4-perf-fixture-flake.out`) | **exit 1.** **MariaDB:** 1113 tests / 69461 assertions / 86 incomplete, OK. **MySQL:** 1113 / 69456, **1 error** = the A4 performance-fixture flake (§1). Kept |
| **run 3 (`harness-both-engines.out`), final tree** | **`run-tests exit=0`** |

| Engine | Collected | Tests | Assertions | Incomplete | Skipped | Failures / errors / warnings |
|---|---|---|---|---|---|---|
| MariaDB 10.11 | 1199 | 1113 | 69461 | 86 | 0 | 0 / 0 / 0 |
| MySQL 8.0 | 1199 | 1113 | 69461 | 86 | 0 | 0 / 0 / 0 |

- **Counts vs A4:** A4 had 1185 collected / 1099 tests / 69266 assertions. The difference is +14 tests: 3 source-guard tests and 11 HTTP tests.
- **Incomplete:** the 86 incomplete tests are the lab-only (CHR) tests, never counted as passed.
- **Warnings:** no warning, deprecation, risky or skipped test in the output.
- **Variant registry:** matches on both engines.
- **Frozen tree:** `tree-before.sha256` = `tree-after.sha256` over 2800 files (this report and the harness output excluded): **TREE_UNCHANGED**. No harness container was left afterwards.

## 4. Subscriber, service and financial data integrity on refusal

**T1 (P1, P2, P3 separately), T1b, T5 (API), T6b (after an ownership transfer).** Each case uses a reseller-owned customer with a **real COMPLETED activation**: the staff activation flow, a Radius plan, real `radcheck` / `radreply` / `radusergroup` rows and the finance rows. After each refusal:
- **Every table is byte-identical** (`DbProbe` over the whole application database, which includes the RADIUS tables). That covers:
  - `tbl_user_recharges`, `tbl_transactions`, `tbl_customers`;
  - wallets, ledger, reservations, transfers;
  - activations and activation events;
  - `radcheck`, `radreply`, `radusergroup`, `radacct`, `nas`.

  Excluded by design: the append-only `tbl_reseller_audit_events` and the legacy `tbl_logs`.
- **Exactly one `service.refused` audit** per refusal, with `operation` = `planDelete` / `customerDeactivate` / `portalDeactivate` and `target_customer_id` = the protected customer. For T1b that is the reseller owner of the matched row, not the logged-in customer.
- **The activation stays `COMPLETED`.** T2: after P1, P2 and P3 were all refused, `activation/reverse` gives **`REVERSED`**, so reversibility is kept.
- **P3 stays POST-only.** A GET is `405` (route policy), with nothing changed. The recharge id is still read from the query, as RouteMap `dashboard#deactivate` expects.
- **Agent and Sales:** still `403` on P1 / P2 (T6, route policy unchanged; RouteMatrix and CSRF suites green).

## 5. Proof that no hook, device call or Disconnect runs on refusal

| Effect | Instrument | Result on refusal | Proof the instrument works (positive control) |
|---|---|---|---|
| Hook `delete_customer_active_plan` (P1) | test plugin in the disposable app copy, logging every call | `[]` | **T3 (legacy P1):** `['delete_customer_active_plan']` |
| Radius device: RADIUS suspend | `radcheck` / `radreply` / `radusergroup` byte-identical | unchanged | **T1c:** the device removal suspends (`radcheck` changes) |
| Radius device: CoA / Disconnect | an open `radacct` session of the customer on a **silent stub NAS** (`nas.nasname` = the session's `nasipaddress` = 127.0.0.1) | **no packet received** (`[]` after the stub's window) | **T1c:** running `Radius::remove_customer` for the same wiring delivers a **Disconnect-Request (code 40)** to the stub |
| Probe device (legacy plans) | harness Probe device log | `[]` (T1b, T4) | **T3:** `remove_customer` recorded for P1 / P2 / P3 |
| Notification (Telegram, P2 / P3) | harness `send_telegram` probe | `[]` | **T3:** 1 notification each for P2 / P3 |

The independent review found that the first test revision wrote the stub session with NAS address `192.0.2.1`. Its "no Disconnect" check was therefore vacuous. **Fixed:** the session now uses the stub's address, and T1c proves the check would fail if a Disconnect were sent.

## 6. Unassigned legacy customers: unchanged (T3, characterised on the baseline)

T3 passed on the **baseline** and passes after the fix (same assertions):
- **P1:** `302 → plan/list`, hook runs, Probe `remove_customer`, recharge **deleted**.
- **P2:** `302 → customers/view/<id>`, Probe `remove_customer`, recharge `off`, one Telegram.
- **P3:** `302 → home`, Probe `remove_customer`, recharge `off`, one Telegram.
- No `service.refused` for unassigned customers.

**The only behaviour change for unassigned customers:** when the reseller schema is **not ready**, P1–P3 are now refused for every customer (T4). This is owner-accepted risk R-2, the rule of every OD-11 gate. T4 asserts:
- `302` to the expected route;
- the gate's log line `op=planDelete / customerDeactivate / portalDeactivate`;
- no audit row while not ready;
- nothing changed.

## 7. Independent read-only review and disposition

**Reviewer:** a separate agent, read-only, given the plan, decisions, diff, tests, support classes and evidence.

**Verdict: DEFECTS_FOUND.** No CRITICAL or HIGH. **The product code is correct for the approved scope**:
- gate before every effect;
- `r2()` always ends the request (web `exit`, API `die`);
- consistent id handling (`(int)` vs SQL coercion);
- unassigned behaviour unchanged;
- line endings kept.

| # | Sev | Finding | Disposition |
|---|---|---|---|
| R-1 | MEDIUM | The "no Disconnect" assertion could not fail: the stub session used NAS `192.0.2.1`, not the stub's `127.0.0.1`. "No device call" via the Probe log is empty by construction for a Radius plan | **Fixed in the test:** the session uses the stub's address. **Added T1c** (positive control: Disconnect-Request received when the removal really runs). The docblock and doc state exactly which instrument proves which effect (§5) |
| R-2 | LOW → **closed by D-A5-1 (§10)** | **Pre-existing:** `User::_info($id)` falls back to the session customer (`$_SESSION['uid']`, set by Admin "login as customer") when the id is falsy. A recharge row with `customer_id = 0` (or the route `customers/deactivate/0/<plan>` matching such a row) passes the gate (id ≤ 0 is "not reseller-owned") and removes the **impersonated** customer's service | **Not changed** (outside the approved C1–C3 text). It needs an orphan row with `customer_id = 0` (column `int NOT NULL`; legacy or corrupt data only) **and** an Admin who impersonated a reseller customer in the same browser session; the API is not affected. Recorded as **L-A5-1**, with a decision proposed in §9 |
| R-3 | LOW | T8 was described as an HTTP test | **Fixed:** the docblock and doc say it is an app-runtime check of the shared gate; the placement is proven by T9 |
| R-4 | LOW | T4 accepted 302 or 403 without proving it was the A5 gate | **Fixed:** exact `302` + route + the gate's `op=` log line + no audit while not ready |
| R-5 | LOW | The P3 mutation check did not run a detector; no "late" P2 mutation | **Fixed:** the placement checks are a function over source text. Seven mutations (P1 removed or after the hook, P2 removed or after the lookup, P3 own / row / both removed) are each run through **both** checkers and detected |
| I-1 | INFO | Not-ready refusals write a log line, not an audit | Documented (§2; doc §13) |
| I-2 | INFO | T3 does not assert the legacy `_log` line (`tbl_logs` excluded by DbProbe) | Accepted; T3 asserts the effects that matter (hook, device, row, notification) |
| I-3 | INFO | **RADIUS-key collision:** the gate is per customer id, while the Radius device removes by RADIUS key (`pppoe_username` for PPPoE). An unassigned customer whose PPPoE key equals a reseller customer's key could suspend that key through P1–P3 | Outside A5, **the same family as OBS-1** (customer-creation collisions, production blocker). Recorded as **L-A5-2** |
| I-4 | INFO | `home` POST with `renewal=` writes `auto_renewal` before the branch | Pre-existing and not a removal effect; unchanged |
| I-5 | INFO | The pre-fix evidence used the first test revision | **Addressed:** the final revision was re-run on the baseline controllers (§3.1, run 4) |

## 8. Source guard, secret sweep, manifest, protected files

| Check | Result |
|---|---|
| T9 `LegacyRemovalSourceTest` | **Generic check:** every `->remove_customer(` in `system/controllers` (5 calls: P1, P2, P3, P5, P6) has an ownership gate earlier in its branch, before any hook, device, row write, log or notification. **Exact placement:** the three A5 gates are where the plan says. **Mutations:** all 7 detected |
| SHA-pinned files (`Wp3a/SourceGuardTest`) | `ServiceGate.php`, `RouteMap.php`, `Tx.php`, `Balance.php`, `Package.php`, the migration runner: **unchanged**; the test passes (10 / 37057) |
| Manifest `docs/claude-development-pack/MANIFEST.sha256` | **42 / 42 OK** |
| Protected / out-of-scope paths | `git diff 8ed4cb3d` empty (§1) |
| `git diff --check` | The only hits are the CR of the CRLF lines in `customers.php` / `home.php` (the files' own convention; the accepted A3 commit shows 42 such hits in `customers.php`). **Clean** with `core.whitespace=cr-at-eol`; the new files are LF without trailing whitespace |
| Secret sweep (lab secret values, non-address tokens, private-key markers) over the changed and new files, the doc, the plan and `evidence/a5/` | **0 hits** |
| A3 / A4 unchanged | A3 `RenameRemediationTest` / `RenameOldSessionTest` and A4 `AccountingTimeContractTest` / `AccountingTimeUnitTest` green on both engines; no A3 / A4 file changed |
| CHR | Not run (owner: optional). The refusal happens before any device or RADIUS call, as proven by T1 / T1c / T5 / T6b; no technical reason found that requires it |

## 9. Remaining risks and limits; verdict

| ID | Item | Severity | Status |
|---|---|---|---|
| L-A5-1 | `User::_info` falsy-id fallback to the impersonated session customer in P1 / P2 (review R-2; pre-existing) | LOW | **CLOSED by D-A5-1 (§10)** |
| L-A5-2 | **RADIUS-key collision:** an unassigned customer sharing a PPPoE / RADIUS key with a reseller customer could suspend that key (review I-3) | **PRODUCTION BLOCKER** (owner, 2026-10-14) | Kept under **OBS-1**. Not treated in A5: no customer-creation or database-uniqueness change in this round |
| R-2 | Schema not ready → P1–P3 refused for all customers | by design | Owner-accepted |
| R-4 | Cron expiry vs a RENEW in flight | not proven | Separate check (decision 7); T7 not in A5 |
| R-6 | Plugins / hooks calling devices directly | — | Phase B |
| I | The refusal text still reads "(pending WP5B)" | INFO | Pinned file; wording only |
| — | Earlier open items | — | Unchanged: OBS-1, same-key ADMIN_SYNC, RadiusRest / L-4, L-1…L-5, R2 audit / UI, the READ COMMITTED interleaving, the PHP warnings, P-A4-1…3, R-HF-CARRY-01, H-2 follow-ups, V-1, S7 / S8 |

**Git state:**
- HEAD = origin = `8ed4cb3d`; nothing staged or committed.
- **Modified:** `system/controllers/{plan,customers,home}.php`, `docs/WP5B_LEGACY_CLOSURE_MODEL.md`, and (test fixture only, §1) `tests/Integration/Wp4/AccountingTimeContractTest.php`.
- **New (untracked):**
  - `tests/Unit/Wp8/LegacyRemovalSourceTest.php`, `tests/Http/LegacyRemovalGateTest.php`;
  - `owner-review/WP8/evidence/a5/`;
  - this report;
  - the A5 plan (original 209 lines unchanged, plus §11).
- **Backup:** `/srv/phpnuxbill/wp8-a5-backup-20261013T210147Z` (`SHA256SUMS` verified).
- **Rollback:** restore the three controllers (CRLF kept) and the doc from the backup, and delete the two new tests. No data, schema or configuration to undo.

**Verdict of the first round: `READY_FOR_OWNER_ACCEPTANCE`.** It was superseded by the final safety round, §10.5.
- **P1, P2 and P3:** refused for reseller-owned customers before any hook, device call, Disconnect, database, RADIUS or financial effect. This is proven on both engines over real HTTP, with positive controls.
- **Reversibility:** kept.
- **Unassigned legacy customers:** unchanged.
- **Pinned files:** `ServiceGate.php` and `RouteMap.php` untouched.
- **Full harness:** green on both engines with the tree frozen.
- **Checks:** source guard, manifest and secret checks clean.
- **Open:** L-A5-1 (OD-A5-1, optional) and L-A5-2 (with OBS-1) are recorded and do not block development acceptance.
- **Not done:** no commit or push; that waits for the owner's acceptance.


## 10. Final safety round (owner decision 2026-10-14: `A5_IMPLEMENTATION_REVIEWED — FINAL_SAFETY_FIX_AUTHORIZED`)

### 10.1 D-A5-1: L-A5-1 closed (mandatory)

**Root cause:** `User::_info($id)` (`system/autoload/User.php:214-216`) replaces a falsy `$id` with `User::getID()`. That is the session `uid` (or the signed `uid` cookie), which the Admin "login as customer" sets. ServiceGate treats an id ≤ 0 as "not reseller-owned", so the gate passed. P1 and P2 then removed the **impersonated** customer's service.

**Legitimate historical use of `customer_id = 0` (checked before choosing the refusal):**
- **Where it comes from:** legacy **voucher-only** subscribers. `Package::rechargeUser(0, …, 'Voucher', code)` creates `tbl_user_recharges` with `customer_id = 0` and `username` = the voucher code, still reachable through `login.php:135` (`RedemptionTarget::voucherOnly`).
- **Cron expiry** handles these rows correctly: with no customer row, the recharge row itself is the device target (`system/cron.php:66-70`). Unchanged.
- **P1 never handled them correctly.** With an impersonation session it removed the impersonated customer. Without one, `User::_info(0)` finds no customer, so the device receives `false` and does not act on the voucher identity.
- **Choice:** a fail-closed refusal (unverifiable owner) loses no correct legacy behaviour. Voucher-only rows end at expiry. Valid ids (T3) are unchanged.

**Change** (inside P1 / P2 only; `ServiceGate.php`, `RouteMap.php` and protected files untouched):

| Path | Before any hook / lookup / device / write | Right before the device call |
|---|---|---|
| P1 `plan.php` (`:439-443`, after the ownership gate, **before `run_hook`**) | refuse unless `(int) $d['customer_id'] > 0` and that customer exists | refuse unless `User::_info()` returned exactly that customer (`(int) $c['id'] === (int) $d['customer_id']`) |
| P2 `customers.php` (`:279-283`, after the gate, **before the recharge lookup**) | refuse unless the route id is canonical (`ctype_digit`), positive and existing | refuse unless the resolved customer **and** the recharge row's `customer_id` are that id |

- **Refusal:** `ServiceGate::refused('planDeleteIdentity' | 'customerDeactivateIdentity', id)` (audit + log line), redirect with the existing message `Account Not Found`, and nothing else.
- **Size:** +10 lines per file; line endings kept (`plan.php` LF, `customers.php` CRLF); `git diff --check` clean with `cr-at-eol`.
- **Second check:** it fires only if the customer disappears between the two checks (a concurrent delete). That is fail-closed defence in depth for owner requirement 3.
- **Verified backup** of the two controllers before this change: `…/wp8-a5-backup-20261013T210147Z/pre-d-a5-1/` (`SHA256SUMS`).

### 10.2 Tests (test-first), both engines

**New in `tests/Http/LegacyRemovalGateTest.php`, both with the Admin's browser session impersonating a reseller-owned customer X** (a real COMPLETED activation, a Radius plan and an open session on the silent stub NAS):

- **`testP1NeverFallsBackToTheImpersonatedCustomer`:** P1 on a voucher-only row (`customer_id = 0`) on the Radius plan. **Expected:**
  - `302 → plan/list`;
  - every table byte-identical, X's `radcheck` unchanged;
  - no Disconnect at the stub; no hook, device call or notification;
  - one `service.refused` (`planDeleteIdentity`);
  - X's activation `COMPLETED`; the voucher row kept.
- **`testP2NeverFallsBackToTheImpersonatedCustomer`:** P2 with customer id `0`, `abc` and `0x1`. **Expected:**
  - data checked after **each** request;
  - never a redirect to X;
  - X's RADIUS unchanged, no Disconnect, no device call or notification;
  - three `customerDeactivateIdentity` refusals.

**T9 (`LegacyRemovalSourceTest`) extended:**
- **Exact placement:** the P1 id check comes before the hook, the P2 id check before the lookup, and both identity checks between `User::_info(` and `->remove_customer(`.
- **Mutations:** 4 new (each check removed). The two "late gate" mutations were adapted to the added lines; they are still detected.

| Run | Code | Result |
|---|---|---|
| `evidence/a5/d-a5-1/pre-fix-run1-first-test-revision.out` | before D-A5-1 | both engines: T9 2 failures; HTTP 13 tests, **2 failures**. **P1:** `radcheck` + `tbl_user_recharges` changed (X suspended, the row deleted). **P2:** stopped at `abc` with an HTTP **500**. Kept |
| `d-a5-1/pre-fix-run2.out` (P2 checks the data after each id) | before D-A5-1 | both engines: T9 exit 1; HTTP 13 / 191, **2 failures**. **P2 with id `0`:** `radcheck` changed, so the impersonated X was suspended. **L-A5-1 proven for P1 and P2** |
| `d-a5-1/post-fix-focused-run1.out` (A5 + 19 regression suites, 42 runs) | after D-A5-1 | 40 × exit 0; HTTP 13 / 215 OK. **`LegacyRemovalSourceTest` exit 1 on both engines:** a **bug in my test**, because the two "late gate" mutation regexes assumed the gate was directly followed by the hook / lookup. Fixed in the test (flexible gap); product code unchanged. Kept |
| **`d-a5-1/before-vs-after-final-tests.out`** (final tests; "before" = the pre-D-A5-1 controllers mounted read-only from the verified backup) | before / after | **Before D-A5-1:** T9 exit 1 (2 failures); HTTP 13 / 191, **exactly the 2 L1 tests fail**, the other 11 pass. **After D-A5-1:** **MariaDB** T9 3 / 39 exit 0, HTTP 13 / 215 exit 0; **MySQL** same, exit 0 |

**Regression after D-A5-1** (`post-fix-focused-run1.out`), exit 0 on both engines, same tests and assertion counts as §3.2:
- **A5 baseline:** `LegacyRemovalGateTest` 13 / 215.
- **Pins:** `Wp3a/SourceGuardTest` 10 / 37057.
- **WP5B:** `Wp5bSourceGuardTest`.
- **Security / HTTP:** `ServiceGateTest`, `CsrfTest`, `RouteMatrixTest`, `SecurityRoutesTest`, `SchemaNotMigratedTest`, `ApiTest`, `CustomerOwnershipTest`.
- **Reversal and legacy closure:** `ActivationReversalTest`, `LegacyEntrypointClosureTest`, `DriftReportTest`, `PluginBypassTest`.
- **UI / admin flow:** `Subscriber360Test`, `RadiusAdminFlowTest`.
- **A3:** `RenameRemediationTest`, `RenameOldSessionTest`.
- **A4:** `AccountingTimeContractTest`, `AccountingTimeUnitTest`.

### 10.3 Owner checks (D-A5-1 §1–7 and Final Verification)

| Owner requirement | Evidence |
|---|---|
| A test that fails on the current code with `customer_id = 0` + an Admin impersonating a reseller customer | §10.2 "before" runs (P1 and P2) |
| An invalid or missing id never falls back to the session customer | P1 id ≤ 0 / missing customer refused; P2 `0`, `abc`, `0x1` refused (non-canonical, ≤ 0 or missing); the T9 placement checks |
| The DB owner of the plan = the customer actually passed to the device | identity re-check before the device (P1 `$c` = row customer; P2 `$c` = route customer = row `customer_id`); T9 |
| Checked before any hook / device call / write | P1 id check **before `run_hook`**, P2 before the recharge lookup; the HTTP tests prove no hook, device, Disconnect or write |
| Fail-closed on mismatch or unverifiable identity | refusals in every case above |
| `ServiceGate.php`, `RouteMap.php` and protected files unchanged | SHA pins pass; protected diff empty (§10.4) |
| Correct legacy behaviour kept; historical id use checked | §10.1 (voucher-only analysis); T3 (valid unassigned ids) unchanged |
| The impersonated customer's service unchanged | X's `radcheck`, recharge and activation byte-identical; no Disconnect at the stub |
| CHR | not run: no new device-reaching path (the change only refuses earlier); isolation tests and the positive control T1c are sufficient |

### 10.4 Final full harness and checks

Run 4 (`evidence/a5/harness/harness-both-engines.out`) on the final tree; run 3 (the tree before D-A5-1) is kept as `harness-run3-before-D-A5-1.out`. **`run-tests exit=0`**.

| Engine | Collected | Tests | Assertions | Incomplete | Skipped | Failures / errors / warnings |
|---|---|---|---|---|---|---|
| MariaDB 10.11 | 1201 | 1115 | 69521 | 86 | 0 | 0 / 0 / 0 |
| MySQL 8.0 | 1201 | 1115 | 69521 | 86 | 0 | 0 / 0 / 0 |

- **Counts vs run 3:** run 3 had 1113 tests; the difference is the 2 L1 tests.
- **Incomplete:** the 86 incomplete tests are the lab-only tests, never counted as passed.
- **No warnings:** no warning, deprecation, risky or skipped test.
- **Variant registry:** matches on both engines.
- **D-A5-2:** the A4 fixture passed on both engines in this run too.

| Check | Result |
|---|---|
| Frozen tree | `tree-before.sha256` = `tree-after.sha256`, 2804 files: **TREE_UNCHANGED**; no harness container left |
| Branches | HEAD = origin = `8ed4cb3d`. Every remote ref (main, feat, hotfix, wp0–wp8) is identical to the post-A4 listing; nothing staged, committed or pushed |
| Pinned / protected files | `git diff HEAD` empty for `system/autoload` (incl. `ServiceGate.php`, `RouteMap.php`), `install/`, `init.php`, `radius.php`, `system/cron.php`, `system/devices`, `ui/`, `dev/`, `tests/phpunit.xml`, `docs/claude-development-pack`; the `Wp3a` SHA pins pass |
| Manifest | **42 / 42** |
| Diff | `git -c core.whitespace=cr-at-eol diff --check` clean. Tracked changes: `plan.php` +12, `customers.php` +12, `home.php` +3, the WP5B doc +52, the A4 test fixture +3 / −2 (D-A5-2) |
| Secret sweep | 32 files (changed / new code, tests, docs, plan, this report, `evidence/a5/`): **0 hits** |
| A5 plan | the original 209 lines are byte-identical; §11 is appended (untracked) |
| Kept failure evidence | `pre/pre-fix-run1.out`, `post/focused-run1.out`, `post/focused-run3-…`, `harness/harness-ABORTED-review-test-fixes.out`, `harness/harness-run2-FAILED-a4-perf-fixture-flake.out`, `d-a5-1/pre-fix-run1-first-test-revision.out`, `d-a5-1/pre-fix-run2.out`, `d-a5-1/post-fix-focused-run1.out` |

### 10.5 Verdict

**`A5_READY_FOR_FINAL_OWNER_ACCEPTANCE`**
- **L-A5-1:** closed by D-A5-1. P1 and P2 never fall back to the session customer, and the owner of the plan in the database is exactly the customer passed to the device, checked before any hook, lookup, device call or write and again before the device. Fail-closed, proven to fail on the previous code and to pass after it, on both engines.
- **D-A5-2:** the test-only correction is applied, documented and stable on both engines.
- **L-A5-2:** remains a **production blocker** under OBS-1 (not treated here).
- **Not done:** no commit, push, merge or deployment; waiting for the owner's final review.

## 11. Addendum (2026-10-14): owner acceptance and pre-commit audit

**Owner decision:** `A5_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY`. **R-WP8-CARRY-01: CLOSED FOR DEVELOPMENT** (not production ready). One commit and one push to `wp8/integration-poc` are authorized.

**Accepted limits (A5 is not extended):**
- Deleting a voucher-only active plan (`customer_id = 0`) through P1 is now **refused, fail closed**. The existing **cron expiry path is unchanged** and still ends these rows. Any safe manual cancellation of a voucher-only plan needs a **separate study**.
- **L-A5-2** (RADIUS-key collision) and **OBS-1** remain open **production blockers**. The earlier production blockers (P-A4-1…3 and the rest listed in `OWNER_DECISIONS.md`) are **not** closed by A5.
- **Incomplete (lab-only) tests are not passes.**
- **D-A5-2** is an approved **test-only correction** of an A4 test fixture, not a change of A4 code.

**Session recovery and pre-commit audit (read-only, before any write):**

| # | Check | Result |
|---|---|---|
| 1 | Branch / HEAD / origin | `wp8/integration-poc`; HEAD = origin = `8ed4cb3d`. Nothing staged, no lock, no running test, git or container process |
| 2 | Inventory | **Modified:** `system/controllers/{plan,customers,home}.php`, `docs/WP5B_LEGACY_CLOSURE_MODEL.md`, `tests/Integration/Wp4/AccountingTimeContractTest.php` (D-A5-2). **New:** `tests/Unit/Wp8/LegacyRemovalSourceTest.php`, `tests/Http/LegacyRemovalGateTest.php`, the A5 plan, this report, `evidence/a5/` (20 files, 2.0 MB). **Appended after the fingerprint:** `OWNER_DECISIONS.md` (A5 record) and this addendum (docs only; no test reads them) |
| 3 | Code and tests vs the final fingerprint | the whole tree is byte-identical to `evidence/a5/harness/tree-after.sha256` (2804 files) |
| 4 | D-A5-1 placement | **P1:** gate `:437` → id check `:440` → `run_hook` `:444` → identity `:447` → device `:455` → delete `:460`. **P2:** CSRF `:273` → gate `:277` → id check `:280` → lookup `:284` → identity `:290` → device `:298` → save `:306`. **P3:** gates `:202-203` → device `:209` |
| 5 | `ServiceGate.php`, `RouteMap.php`, protected files | unchanged (`git diff HEAD` empty for `system/autoload`, `install/`, `init.php`, `radius.php`, `system/cron.php`, `system/devices`, `ui/`, `dev/`, `tests/phpunit.xml`, `docs/claude-development-pack`) |
| 6 | Full harness | `harness-both-engines.out`: 1115 tests / 69521 assertions / 86 incomplete per engine, `run-tests exit=0` |
| 7 | A5 tests | `d-a5-1/before-vs-after-final-tests.out`: source guard **3 / 39** and HTTP **13 / 215**, exit 0 on both engines |
| 8 | Secret sweep / manifest / diff | 0 hits; **42 / 42**; `diff --check` clean (`cr-at-eol`, the CRLF files' convention) |
| 9 | Evidence hygiene | logs and fingerprint lists only. **No** pcap, image, disk, `.env`, key, lock or temp file; no credential assignment; the stub-NAS test secret is a fixed test literal, not a credential |
| 10 | `OWNER_DECISIONS.md` | A5 record appended; the 572 historical lines are byte-identical |
