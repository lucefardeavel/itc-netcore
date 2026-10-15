# WP8 S8 — Final Evidence Re-Gate

| Item | Value |
|---|---|
| Owner decision | `S8_DOCUMENTATION_REVIEWED — FINAL_EVIDENCE_REGATE_AUTHORIZED` (2026-10-14) |
| Canonical baseline | `wp8/integration-poc@b96747b5f453a74f731624e01d5d5b4e8c53c43c` (= origin) |
| Run window | 2026-10-14, lab up 14:43Z → down 16:25Z |
| Scope | Evidence only. **No product, schema, finance, test, PHPUnit-configuration or architecture change**; no production; no real MikroTik; no commit or push; WP9 / WP10 not started |
| Evidence | `evidence/s8-regate/` (raw PHPUnit outputs with exit codes, lab console, lab evidence jsonl, RouterOS inventories, tree fingerprints, warning diagnosis) |
| **Final state** | **`WP8_FINAL_REGATE_READY_FOR_OWNER_REVIEW`** (§9). This is **not** a WP8 closure: WP8 stays PENDING until the owner decides (D-S8-7) |
| Warning remediation (added) | Owner `D-S8-3_TEST_ONLY_FIX_APPROVED — WP8_ACCEPTANCE_PENDING`. Result **`WP8_READY_FOR_FINAL_DEVELOPMENT_ACCEPTANCE`**: full `wp8-integration` 101 / 1849, 0 warnings, **exit 0** (§10) |

Paths are relative to `owner-review/WP8/` unless they start with `tests/`, `system/`, `install/`, `dev/` or `docs/`.

## 1. Owner decisions applied in this round

| Decision | Applied as |
|---|---|
| **D-S8-1 = A** | OD-W8-16 (the original lost-Stop lockout) is **closed for development only**, by A1 (Option G) and A2-R. **AV-1, the A2-R SQL-exhaustion risk and every production requirement stay open.** This round re-proved the guard path: `FailureDrillsTest::testDrill7GuardLost…` passed. The record is kept here and in `REPORT.md` §10; `OWNER_DECISIONS.md` is not changed in this round. |
| **D-S8-2 = A** | Executed: §3 |
| **D-S8-3** | `Message.php` not changed. Diagnosis and a proposed fix (not applied): §5 |
| **D-S8-4** | Read-only comparison and a docs-only reconciliation plan: §7. Nothing merged or copied |
| **D-S8-5 / D-S8-6** | Documentation notes only. File changes deferred until after this round (the tested tree did not change, §6) |
| **D-S8-7** | Deferred. Neither `OWNER_DECISIONS.md` nor `ACCEPTANCE_LEDGER.md` was changed |
| **D-S8-8** | The proposed phase assignment in `REPORT.md` §5 is kept. No WP9 / WP10 work |
| **D-S8-9** | No claim of applying the "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" text (not found in any available source) |

## 2. Preflight (read-only) and scenario safety

| Check | Result |
|---|---|
| HEAD / origin / worktree | `b96747b5` = origin. The worktree differs only by the uncommitted S8 documents (`REPORT.md`, `docs/WP8_INTEGRATION_MODEL.md`, `docs/WP4_RADIUS_MODEL.md`) |
| Product code vs the S7 commit | `git diff HEAD` over `system install tests dev ui` is **empty**. The code-diff hash is the empty-input SHA-256 (`evidence/s8-regate/tree-fingerprint-before.txt`) |
| Earlier lab processes | `lab.sh status`: links 0, netns 0, qemu 0, agent 0, containers 0, networks 0. No `:18728` / `:18729` listener |
| CHR1 / CHR2 / FreeRADIUS | CHR 7.24.5 ×2 (`10.88.0.2`, `10.88.0.3`), FreeRADIUS 3.2.8, readiness `nuxr_admission=READY`, site `project`, 0 config errors (`evidence/s8-regate/lab-console.out`) |
| **Scenario safety** | Re-read every test in scope.<br>• **`wp8-security-gates`:** A2 race gates A / B0 / Bscan / C / E1–E3 (SNMP-fault cases, not SQL), S-1 and H1 spelling gates.<br>• **`wp8-integration`:** uses only lab-agent operations (logins, races, captures, NAS link, a FreeRADIUS pause for the AV-1 stall contract).<br>**No PHPUnit test in scope runs** the A2-R SQL tooling: `dev/wp8/a2r_sql_gate.py`, `a2r_gate.py pool …` (AV-2 pool sizing) or `a2r_gate.py fault on`.<br>**None of these was run. The halted A2-R SQL-exhaustion reproduction was not run under any name.** |

**Lab configuration used:** the production contract. These are the five A4 / S7 setup steps, plus FreeRADIUS threaded, all rc=0:

| Step | Command |
|---|---|
| 1 | `lab.sh up` |
| 2 | `option_g_gate.py on shipped` |
| 3 | `lab.sh up-chr2` |
| 4 | `h2_gate.py setup` |
| 5 | `a2r_gate.py chr2 setup` |
| 6 | `h2b_gate.py site project` |
| 7 | `a2r_gate.py threaded on` (`freeradius -fxx`, threaded) |

**Why threaded.**
- `install/freeradius/README.md` requires "run FreeRADIUS threaded … never `-X` in production".
- The security gates' accepted baseline ran threaded (A2-R final, AV-5, H-2B).

**Recorded discrepancy:** the A3, A4 and S7 lab runs used non-threaded `-X` (their consoles show `"threaded": false`). This round re-ran their classes **threaded** on the final code.

## 3. Results

Each suite ran separately, with its raw PHPUnit output kept. Exit codes are those printed by the runner. Each row cites its `evidence/s8-regate/` output file. Counts are PHPUnit's own; skipped and incomplete tests are **not passes**.

| # | Suite | Tests | Assertions | Failures / errors | Warnings | Deprecations | Skipped | Incomplete | Exit | Duration | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `wp8-security-gates` (full suite) | 10 | 216 | 0 / 0 | 0 | 0 | 0 | 0 | **0** | 34:31 | `01-wp8-security-gates.out` |
| 2 | `FailureDrillsTest` | 10 | 283 | 0 / 0 | 0 | 1 | 1 | 0 | **0** | 3:25 | `02-FailureDrillsTest.out` |
| 3 | `CoaDisconnectTest` | 6 | 70 | 0 / 0 | 0 | 0 | 0 | 0 | **0** | 1:07 | `03-CoaDisconnectTest.out` |
| 4 | `ResellerBusinessFlowTest` | 1 | 69 | 0 / 0 | 0 | 0 | 0 | 0 | **0** | 0:05 | `04-ResellerBusinessFlowTest.out` |
| 5 | `wp8-integration` (full suite, includes 2–4 again) | 101 | 1848 | 0 / 0 | **3** | 1 | 13 | 0 | **1** | 56:02 | `05-wp8-integration.out` |

**Notes on the results.**
- **Suite 2:**
  - **The skip** is the S6 drill-7 *baseline* for NAS type `other`, which cannot run in the production-contract lab. Its production variant `testDrill7GuardLost…` ran and passed.
  - **The deprecation** comes from `FailureDrillsTest::testDrill8` (`system/autoload/PEAR2/Autoload.php:206`, `is_file(null)`).
  - PHPUnit printed "OK, but there were issues!"; exit 0.
- **Suite 5: NOT a PASS.**
  - **Exit 1** with 0 failures and 0 errors. The exit code comes from the 3 `system/autoload/Message.php:13-15` warnings ("Undefined variable `$root_path`", triggered by `RealLifecycleTest::testLegacyReprovisionPasswordAndUsernameOnTheRealNas`) under `failOnWarning="true"`. Cause: §5.
  - **The deprecation** is the same as in suite 2.
  - **The 13 skips** are the mode-gated lab tests: `CheckradGateTest` ×10 (Option F gate mode), `OptionGGateTest` functional ×2, and the drill-7 baseline ×1 (`05-wp8-integration.out:51-88`).
  - **Size:** 101 tests, against 69 in A1 / A2-R / H-2B and 75 in A3. The increase is A4's `AccountingTimeLabTest` plus the S7 classes `DriverContractLabTest`, `DriverFailClosedLabTest`, `DeviceGuardKeyCollisionTest` and `RechargePreWriteRefusalTest`.
- **Display flags:** suite 5 ran with `--display-warnings --display-deprecations --display-skipped --display-incomplete`. These are display options only; `tests/phpunit.xml` is unchanged.

## 4. Behaviour re-proved on the final code

All of this is **[CHR]**, on RouterOS 7.24.5, threaded FreeRADIUS 3.2.8, production contract. Source: `evidence/s8-regate/lab-evidence.jsonl` (161 records).

| Area | Result in this round | Matches earlier evidence |
|---|---|---|
| A2 / A2-R race gates | **48 trials: 45 SINGLE_SESSION, 3 NO_SESSION (fail-closed), 0 double sessions.** S-1 (case, accent) and the H1 spelling gates passed | Yes (A2-R final, AV-5) |
| S6 drills 1–10 | D1 + D6 COMPLETED; D2 FAILED `RADIUS_DB_ERROR`; D3 `RECONCILIATION_REQUIRED` → one settlement; D4 / D5 / D9 COMPLETED with `UNKNOWN COA_NO_RESPONSE`; D8 PEAR2 `SocketException` after 8 s, activation unaffected; D10 clean recovery (0 firewall rules, `accept=yes port=3799`, auth ok, CoA ACK); D7 guard: lost Stop → re-login accepted without repair | Yes (S6 summary, A1, A2-R) |
| S3 Disconnect / CoA | PPPoE and Hotspot Disconnect `SUCCESS COA_ACK`; rate CoA `256000/512000` → `128000/1000000`; Framed-Pool / Framed-IP CoA ACKed (not applied); silence `UNKNOWN COA_NO_RESPONSE` (~6.0 s); no matching session → `FAILED COA_NAK 406` | Yes (S3 summary) |
| S4 business flow | conservation `17.00 = 5.00 + 12.00`; idempotent replay; Sales out of scope → `NOT_FOUND` | Yes (S4 summary) |
| A3 rename (C1–C6, CHR1 + CHR2), A4 time contract, S7 DC / FC, Option G gates, Port-Limit observations | passed inside suite 5 | Yes |

**This round closes the evidence-currency gaps of `REPORT.md` §3 / §7.1, items 1, 3 and 4.**
- **Raw output now exists for:** the S1–S6 classes, the OD-W8-16 gates, and `FailureDrillsTest` / `CoaDisconnectTest` / `ResellerBusinessFlowTest` on the final code.
- **The security gates** have been re-run after H-2B, A3–A5 and S7.

**Not closed by this round:** item 2 (the full lab suite exits 1 on the warnings) and item 5 (CoA wrong-source on 7.24.5, excluded by the owner).

## 5. `Message.php` warnings: diagnosis (D-S8-3; nothing changed)

**Mechanism** (reproduced deterministically in an isolated container, `--network none`, repository read-only; `evidence/s8-regate/message-warning-diagnosis.out`):
1. `system/autoload/Message.php:13-15` runs top-level `require $root_path . 'system/autoload/mail/…'`. It therefore needs `$root_path` in the scope that **includes** the file.
2. In production, `init.php:44` registers `_autoloader` (which declares `global $root_path`) **before** `init.php:80` requires `PEAR2/Autoload.php`. PEAR2 appends its loader (`APPEND = 0`, so not prepended).
   - So `Message` is always loaded by `_autoloader`. **Case 4** (exact init order) loads `Message` with **no warning**.
3. In the PHPUnit process there is no `_autoloader`. `FailureDrillsTest::testDrill8` (`tests/Integration/Wp8/FailureDrillsTest.php:477`) requires `PEAR2/Autoload.php`, which registers `PEAR2\Autoload::load` with `system/autoload` as a search path.
   - A later test in the same process that touches `Message` (in suite order, `RealLifecycleTest::testLegacyReprovision…`) has it loaded **inside `PEAR2\Autoload::load`'s function scope** (`PEAR2/Autoload.php:279-281`), where `$root_path` does not exist. That gives 3 warnings.
   - The relative `require` still succeeds (cwd `/repo`), so behaviour is unaffected. **Case 3** reproduces exactly the 3 warnings and the `Autoload.php:206` deprecation.
4. **Case 1** (a closure autoloader without `$root_path`) also warns, and **case 2** (an init-style global) does not. Together they confirm the scope dependency.

**Conclusions.**

| Point | Finding |
|---|---|
| **Test failures vs warnings** | 0 failures and 0 errors in every suite. The 3 warnings are the sole cause of exit 1 in suite 5. |
| **Production impact of the warnings** | **None found:** the production autoload order never loads `Message` through PEAR2 (case 4). It is a **test-process artefact** that depends on suite order. This explains the A2-R note that `RealLifecycleTest` alone shows no warning. |
| **Production-relevant by-product (pre-existing, not fixed, LOW)** | `init.php:80` → `PEAR2/Autoload.php:206` emits `E_DEPRECATED` (`is_file(null)`, from `addMap(null)`) on PHP 8.2 at every application init. This is log noise; it is the same deprecation counted in suites 2 and 5, and it refines OBS-W8-6. |
| **The discrepancy C-24** (two deprecation sources named in earlier records) | Resolved: in this round the deprecation is `PEAR2/Autoload.php:206` from `testDrill8`. |

**Proposed limited fixes (NOT applied; each needs a separate owner authorization):**

| Option | Change | Runtime change? | Note |
|---|---|---|---|
| **T-1 (recommended)** | `tests/Integration/Wp8/FailureDrillsTest.php`, `testDrill8`: after using the PEAR2 client, `spl_autoload_unregister('PEAR2\Autoload::load')` in a `finally`. The suite then behaves like the isolated run (no foreign autoloader leaks into later tests) | No (test only) | 2–3 lines; removes the order-dependent warnings; no assertion change |
| T-2 | `tests/bootstrap.php`: a test autoloader that mirrors `init.php` (`global $root_path`, registered first) | No (test only) | Broader effect on every in-process test; not recommended |
| R-1 | `system/autoload/Message.php`: `require __DIR__ . '/mail/…'` | **Yes** (upstream file) | Not needed: the production path does not warn |
| R-2 (separate) | The PEAR2 `is_file(null)` deprecation in production init | Yes (vendored library or `init.php`) | Log-noise only; a candidate for WP9 hygiene |

## 6. Evidence integrity

| Check | Result |
|---|---|
| Tree fingerprint before / after | **Identical** (`evidence/s8-regate/tree-fingerprint-{before,after}.txt`): HEAD, the code-diff hash and the whole-tree hashes of `system/`, `install/`, `tests/`, `dev/`, `ui/` and `init.php`. The tested tree is the committed `b96747b5` code |
| Manifest | **42 / 42** (`docs/claude-development-pack/MANIFEST.sha256`) |
| Protected / product files | `git diff HEAD` over `system install tests dev ui init.php`: **empty**. No PHPUnit configuration change |
| Secret sweep | **0 hits** for the real credentials (lab admin, DB root, RADIUS secrets, SNMP community and NAS SNMP credentials; 8 values) and private-key / API-password patterns, over all 13 `evidence/s8-regate/` files and the 3 S8 documents.<br>A first, over-broad pass that also tokenised a pcap binary produced one false positive (a 12-letter lowercase English word); it is disclosed here and is not a credential.<br>The lab evidence contains the **fixed test-fixture passwords** of the drill identities (`D1-pass` … `D9-pass`, hard-coded in the committed test source). The same values are already in committed evidence (A2-R, AV-5, H1-A, A3); they are not credentials. |
| RouterOS inventory, before / after (CHR1 + CHR2; users, groups, PPP secrets / profiles / active, Hotspot, pools, NAT, filter, RADIUS, incoming, SNMP attributes without the community name, DHCP server, addresses) | Identical except the dynamic usage counters of the Hotspot DHCP pool `nuxr_wp8_pool_hs` (`used` 2 / 1: client leases) (`inventory-{before,after}.json`). **No** leftover user, group, secret, NAT or filter object. The S7 object listing is `listed: []` (`s7-objects-after.out`) |
| Teardown | `a2r_gate.py threaded off`, `h2_gate.py teardown`, `lab.sh down`: all rc=0. `lab.sh status`: links 0, netns 0, qemu 0, agent 0, containers 0, networks 0. No `qemu-system` process, no lab container or network, no `dev/wp8/__pycache__`. Both CHRs (and their temporary disks) are destroyed with the lab |

## 7. Architecture Lock comparison and reconciliation plan (D-S8-4; read-only)

**What exists.**
- Commit `8b0ee336` on the local branch `docs/owner-architecture-lock-2026-10-09` (worktree `/srv/phpnuxbill/docs-sync`, clean). Its parent is `5bdc5745` (= `origin/feat`). It is **not pushed** and is not an ancestor of wp8.
- 17 files, all docs:
  - `CLAUDE.md`;
  - `docs/architecture/DEVELOPMENT_STATUS_REPORT.md` and `DOCUMENTATION_SYNC_REPORT.md`;
  - the verbatim lock folder `docs/architecture/owner-lock-2026-10-09/` (10 documents + SHA-256 manifest: **10 / 10 OK** on the branch);
  - dated notes appended to `docs/GAP_ANALYSIS.md` and `docs/WP4_RADIUS_MODEL.md`.

**Overlap with wp8** (`git merge-tree`, no ref or worktree change):
- **Textual:** 0 conflicts. `docs/WP4_RADIUS_MODEL.md` was changed on both sides and merges cleanly. Everything else is new on the lock side.
- **Semantic staleness** relative to wp8 (stale statements, not errors at the time they were written):
  - the lock's WP4 note says Option G is "not productionized" and the time contract is "an open owner decision" (now A1 and A4 on wp8);
  - lock §6 / the status report: "WP8 PENDING; Option G only in the disposable lab; S7/S8 pending", WP8 at `47a3a02c`, "4 carry-forwards OPEN";
  - `DOCUMENTATION_SYNC_REPORT` conflicts C-01 / C-05 / C-08 are resolved on wp8 (D1; A4; A1);
  - C-04 severity (RISK_REGISTER R-008 / 009 / 010 HIGH vs the WP8 records' MEDIUM) is not reconciled.

**Reconciliation plan (docs only, for owner approval; nothing executed):**
1. **Keep the lock folder verbatim** (manifest-pinned; `CLAUDE.md` says never edit it). All updates go into **new dated files**, never into the lock documents.
2. **Land the lock on its own base first:** push the branch, or open a docs-only PR into `feat` from `5bdc5745`. It is correct for `feat`, where WP8 is not merged. Owner decision: branch and timing.
3. **Add a dated status addendum** (e.g. `docs/architecture/WP8_STATUS_ADDENDUM_<date>.md`) that records the WP8 outcomes, with evidence links:
   - A1 / A2-R / H1-A / H-2B / A3 / A4 / A5 / S7 / S8;
   - the carry-forwards closed for development;
   - the open production blockers (`REPORT.md` §5);
   - the D-S8-1 decision.
   It supersedes the status lines of `DEVELOPMENT_STATUS_REPORT.md` without editing them.
4. **When WP8 is merged into `feat`** (a separate owner decision): add a dated sub-note under the lock's WP4 note stating that A1 (Option G permanent) and A4 (UTC session contract, §4.1) supersede its "not productionized / open decision" lines. Expect no textual conflict.
5. **Record an owner decision** reconciling the C-04 severity classification: lock HIGH vs WP8 MEDIUM for R-WP8-CARRY-01 / 02 / 03, now closed for development.

## 8. Gaps and notes that remain

- **CoA wrong-source on RouterOS 7.24.5:** not tested (excluded by the owner for this round). It stays **[CHR-FG]** (7.12.1 only).
- **The full lab suite still exits 1** on the test-process warnings (§5). It is **not a PASS** until a fix such as T-1 is authorized.
- **Earlier A3, A4 and S7 lab runs were non-threaded** (§2). They are now re-run threaded, inside suite 5.
- **D-S8-5 / D-S8-6:** documentation changes deferred (`install/freeradius/README.md`; the stale `tests/phpunit.xml` / `ConcurrentFirstLoginGateTest` comments). The security-gate suite that those comments call "expected to fail" **passed** here (exit 0).
- **Unchanged:** every production blocker and lock gate in `REPORT.md` §5 / §7.3. AV-1 and the A2-R SQL-exhaustion risk remain open.

## 9. Final state

- **Suites passed:** all five ran on the committed final code in the production-contract lab with zero failures and zero errors. Suites 1–4 exit 0.
- **Suite 5 is not a PASS:** it exits 1 solely because of the 3 pre-existing, test-process-only `Message.php` warnings. They are diagnosed and reproduced, and a test-only fix is proposed and not applied.
- **No new security or functional defect** appeared.
- **Integrity:** the tree was unchanged, the integrity checks passed, and the lab is fully torn down.

**`WP8_FINAL_REGATE_READY_FOR_OWNER_REVIEW`**. This is not WP8 acceptance; it is stopped for owner review.

---

## 10. WP8_S8_FINAL_WARNING_REMEDIATION_RESULT (2026-10-14, added)

Owner decision: `D-S8-3_TEST_ONLY_FIX_APPROVED — WP8_ACCEPTANCE_PENDING`. Baseline `b96747b5`. Evidence: `evidence/s8-warning-fix/`. Backup taken before the edit: `/srv/phpnuxbill/wp8-s8w-backup-20261014T164624Z/`.

**Nothing above is changed:**
- §3 suite 5 (`wp8-integration`, **exit 1**, 3 warnings) stays the recorded result of that run;
- §5's diagnosis stands;
- this section adds the remediation round.

### 10.1 What was changed

| File | Change |
|---|---|
| `tests/Integration/Wp8/FailureDrillsTest.php`, `testDrill8RouterOsApiUnavailable` **only** (+18 / −2; `evidence/s8-warning-fix/FailureDrillsTest.diff`) | **Loader:** the line `require_once …/system/autoload/PEAR2/Autoload.php` is replaced by a **drill-scoped loader**: a static closure that loads only `PEAR2\…` classes from `system/autoload/` (the application's own namespace-to-path rule in `init.php` `_autoloader`).<br>**Lifecycle:** it is registered inside the `try` and **unregistered in the `finally`**, together with the restoration of `default_socket_timeout` and `error_reporting`. The settings are now restored even if loading throws; before, the `require` sat outside the `try`.<br>**New assertion:** `assertSame($autoloadBefore, spl_autoload_functions())` (the SPL autoloader stack is identical before and after the drill).<br>**Evidence:** the record gains `autoload_stack_unchanged`.<br>**Unchanged:** the scenario (API blocked on the CHR, the application's PEAR2 client without a timeout argument), its original assertions, the activation check and the invariants. |

**Not changed:** `system/autoload/Message.php`, `system/autoload/PEAR2/Autoload.php`, `init.php`, any product, schema or finance file, `tests/phpunit.xml` (`failOnWarning` and the other settings) and `tests/bootstrap.php`. `git diff HEAD` over `system install dev ui init.php tests/phpunit.xml tests/bootstrap.php` is empty.

### 10.2 Why this design (lifecycle safety)

- **Why not unregister PEAR2's own loader:** `PEAR2\Autoload` keeps a protected static `$registered`. After `require`, it registered `PEAR2\Autoload::load` and set `$registered = true` for the rest of the PHPUnit process. A blind `spl_autoload_unregister('PEAR2\Autoload::load')` would leave `$registered = true` while the loader is gone, so a later `initialize()` would never re-register (the desynchronisation the owner warned about).
- **What the fix does instead:** it **never loads `PEAR2\Autoload`**. There is no internal state to desynchronise, and the SPL stack is restored exactly.
- **Isolation probe** (`evidence/s8-warning-fix/autoload-lifecycle-probe.{php,out}`; container, `--network none`, repository read-only):

  | | Old pattern | Scoped loader |
  |---|---|---|
  | Stack after the drill | `["Closure","PEAR2\\Autoload::load"]` (leaked) | identical to before |
  | `PEAR2\Autoload` | loaded, `registered = true` | never loaded |
  | Later `class_exists('Message', true)` | 3 warnings, true | false, no warning |
  | PEAR2 client classes loadable during the drill | yes | yes |

- **Behaviour after the drill:** `Message` is no longer loadable in the PHPUnit process. `Reseller\Security\ErrorReporter::report()` (`:79`) then takes its **designed** fallback (`class_exists('Message', true)` false → no Telegram call). This is exactly the behaviour of an isolated run (the A2-R note), and no test asserted the Telegram call.
- **No later in-process PEAR2 user:** the only other PEAR2 use in the tests (`DriverContractLabTest:50`) runs inside the application runtime (AppServer exec, a separate process through `init.php`). Production loading is unaffected (§5 case 4).

### 10.3 Test-first and focused runs

[CHR]: RouterOS 7.24.5 ×2, FreeRADIUS 3.2.8 **threaded**, the production-contract configuration as in §2 (`evidence/s8-warning-fix/lab-console.out`, all rc=0).

| Step | Run | Result | Evidence |
|---|---|---|---|
| Before the fix | The existing evidence of §3 suite 5 | 3 × `Message.php:13-15` "Undefined variable `$root_path`", triggered by `RealLifecycleTest::testLegacyReprovisionPasswordAndUsernameOnTheRealNas`; 1 deprecation `PEAR2/Autoload.php:206` from `testDrill8`; **exit 1** | `evidence/s8-regate/05-wp8-integration.out:94-131` |
| 1 | Drill 8 standalone | **OK (1 test, 24 assertions), exit 0.** Same scenario: `PEAR2\Net\RouterOS\SocketException: Error connecting to RouterOS` after 8 s, activation `COMPLETED`, `autoload_stack_unchanged: true`. **No deprecation:** `PEAR2/Autoload.php` is no longer executed in-process | `01-drill8-standalone.out` |
| 2 | Drill 8, then the whole `RealLifecycleTest` (the former warning trigger), one process, suite order, `--debug` | 4 tests "Test Passed", including `testLegacyReprovisionPasswordAndUsernameOnTheRealNas`. **No "Test Triggered … Warning / Deprecation" event**; `PHPUnit Finished (Shell Exit Code: 0)` | `02-drill8-then-RealLifecycleTest.out` |
| 3 | Autoloader state | Asserted in the test: stack identical before and after (it passed in steps 1, 2 and 4). Isolation probe: §10.2 | test + probe |

### 10.4 Final full lab suite

| Suite | Tests | Assertions | Failures / errors | Warnings | Deprecations | Skipped | Incomplete | Exit | Duration | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|
| `wp8-integration` (full, `failOnWarning` unchanged, no test excluded) | 101 | **1849** | **0 / 0** | **0** | **0** | 13 | 0 | **0** | 54:57 | `evidence/s8-warning-fix/03-wp8-integration-full.out` |

**About this result.**
- **"OK, but some tests were skipped!"** The 13 skipped tests are the same mode-gated lab tests as in §3: `CheckradGateTest` ×10, `OptionGGateTest` functional ×2, and the drill-7 baseline ×1. **They are not passes.**
- **Assertions:** 1849 = 1848 (§3 suite 5) + 1 (the new autoloader-state assertion). Every other test is unchanged.
- **No security or authority regression:** the S7 DC / FC classes, the A3 rename, the A4 time contract, the Option G gates, drills 1–10, CoA / Disconnect and the business flow all passed in this run (`evidence/s8-warning-fix/lab-evidence.jsonl`, 91 records).
- **Security gates not re-run** (owner instruction): product code and security tests are unchanged since §3 suite 1 (10 / 216, exit 0, same baseline).
- **SQL tooling:** no A2-R SQL tooling was run, and no SQL-exhaustion reproduction.

### 10.5 Integrity and cleanup

| Check | Result |
|---|---|
| Tree fingerprint | Before the fix = the §6 re-gate tree. After the fix, only `tests/` changed (the one authorized file). The tree after the run is **identical** to the tree after the fix (`tree-fingerprint-{before-fix,after-fix,after-run}.txt`) |
| Manifest | **42 / 42** |
| Protected / product / configuration files | Unchanged (§10.1) |
| Secret sweep (real credential values: lab admin, DB root, RADIUS secrets, SNMP community and NAS SNMP credentials; private-key and API-password patterns) over all `evidence/s8-warning-fix/` files and the changed test | **0 secret values.** The only match is the environment-variable **name** `NUXR_WP8_CHR_PASSWORD` read by `getenv()` in the test, the same as in the committed `b96747b5`. No PCAP, credential file, `.env` or disk image is among the evidence |
| RouterOS inventory, before / after | Configuration identical. Differences only in the runtime fields: Hotspot DHCP-pool usage counters (client leases), and CHR2 `/radius` `status` (a transient "Connection refused" text from the CHR2 bring-up before the run, empty after). The S7 object listing is `listed: []` |
| Teardown | `a2r_gate.py threaded off`, `h2_gate.py teardown`, `lab.sh down`: rc=0. `lab.sh status`: links 0, netns 0, qemu 0, agent 0, containers 0, networks 0. No `qemu-system` process, no lab container or network, no `:18728` / `:18729` listener, no `dev/wp8/__pycache__` |

### 10.6 Remaining notes

- **Production log noise** (unchanged; not part of this authorization): `init.php:80` still triggers the pre-existing `PEAR2/Autoload.php:206` `is_file(null)` deprecation at every production init on PHP 8.2 (§5, R-2).
- **The stale "EXPECTED TO FAIL" comments** (D-S8-6) and the operator README notes (D-S8-5) are still deferred documentation notes.
- **CoA wrong-source** remains **[CHR-FG]** (RouterOS 7.12.1) only, **not tested on 7.24.5**.
- **The production blocker register** (`REPORT.md` §5) is unchanged.
- **WP8 acceptance** and the ledger are not changed. Git: nothing committed or pushed.

**State: `WP8_READY_FOR_FINAL_DEVELOPMENT_ACCEPTANCE`.** The full WP8 lab suite now exits 0 with 0 failures, errors, warnings and deprecations; the 13 mode-gated skips are recorded and are not passes. This is not a WP8 closure; it is stopped for the owner's decision.

---

## 11. Final development finalization (2026-10-15, added)

Owner decision: **`S8_REGATE_ACCEPTED — FINAL_DEVELOPMENT_FINALIZATION_AUTHORIZED`** on
`wp8/integration-poc@b96747b5f453a74f731624e01d5d5b4e8c53c43c`. This is a development finalization, **not a production
approval**. §1–§10 stay as recorded. In particular, §3 suite 5 (exit 1, 3 warnings) stays the historical result of the
tree before the fix. Full text: `REPORT.md` §12.

- **Accepted:** the §10 test-only fix and the full `wp8-integration` result: 101 / 1849, exit 0, 0 errors, failures,
  warnings and deprecations. The 13 mode-gated skips are not passes.
- **D-S8-4:** Architecture Lock conformance documented in `REPORT.md` §12.2 (ten axes, Option A, §3–§7). Nothing was merged
  or copied. The reconciliation steps of §7 above remain owner decisions.
- **D-S8-5:** operator notes added to `install/freeradius/README.md` ("Disconnect / CoA"):
  - port 3799 = `nas.ports`, set explicitly on the NAS;
  - 1700 is the vendor-documented default, not exercised;
  - the source rule is binding;
  - wrong-source was observed on **RouterOS 7.12.1 only** ([CHR-FG]) and was **not re-tested on 7.24.5**.
- **D-S8-6:** the `ConcurrentFirstLoginGateTest` docblock is corrected, comment-only (`php -w` SHA-256 identical before and
  after). `tests/phpunit.xml` is unchanged by audit rule, so its comments at `:111` and `:116-118` stay stale until the owner
  approves a comment-only edit.
- **D-S8-7:** the final re-gate condition is met.
  - Recorded in `OWNER_DECISIONS.md` as `WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS` / `PRODUCTION_NOT_AUTHORIZED`.
  - The ledger row stays `PENDING` (`REPORT.md` §12.1).
- **No lab run in this round.** Only documentation and one non-executable docblock changed after the tested tree. The CHR,
  the SQL-exhaustion tooling and production were not used.
- **Pre-commit audit:** `evidence/s8-final/precommit-audit.out` (`REPORT.md` §12.4).

**State: `WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS` — `PRODUCTION_NOT_AUTHORIZED`.**
