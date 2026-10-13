# WP8 A3 — Old RADIUS Identity / Session Survives Rename (`R-WP8-CARRY-02`): Preflight & Implementation Plan (rev. 3)

**Status: `A3_IMPLEMENTATION_BLOCKED — DESIGN_REVISION_REQUIRED` (owner, 2026-10-12).**
- rev. 3 records the two safety stops, the baseline test review and the rename data-integrity risk (§R).
- Production code is at the baseline. No commit, push, merge or deploy.
- Implementation moves to an authorized team after the design update is approved: `WP8_A3_HANDOFF.md`.
- A4, A5 and V-1 are not started (A5 = `R-WP8-CARRY-01`, approved in principle: `WP8_A5_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md`).
- §1–§12 (rev. 2) stay valid for the old-session part, except where §R adds conditions. §R comes first.

## R0. Implementation attempt and the two safety stops (2026-10-12)

| Step | Result | Evidence |
|---|---|---|
| Backup of the 7 touched files | done, SHA256SUMS 7/7 OK (re-checked after the stop) | `/srv/phpnuxbill/wp8-a3-prefix-backup-20261012T130010Z` |
| Baseline checks | HEAD = origin = `32a162d6`; manifest 42/42; protected FreeRADIUS hashes recorded | session record |
| New tests written first | `tests/Unit/Wp5b/RenameOldSessionUnitTest.php`, `tests/Integration/Wp5b/RenameOldSessionTest.php` (untracked) | files |
| New tests run on the unchanged baseline (MariaDB) | unit **exit 2**; integration **exit 2** (§R1) | `evidence/a3/baseline-new-tests-fail.out` |
| Production change 1 (`CoAClient::disconnectSession`) | applied; then **restored from the backup** after the second stop | `git diff -- system/` empty |
| Production change 2 (`Radius::change_username`) | **not applied**: the assistant's response was stopped by a safety classifier, **twice**, at this step | — |
| Harness | taken down (0 containers); the temporary credential file deleted | — |

**Owner decision:**
- No retry on the same path, and no way around the classifier.
- Implementation moves to independent review and development by an authorized team.

## R1. Baseline test review (expected failures vs test-construction issues)

**Unit test** (`RenameOldSessionUnitTest`, 13 tests): **exit 2**, 10 errors and 3 failures.
- The 10 errors are calls to the not-yet-existing classification methods.
- The 3 failures are source guards that do not find the new code.
- **All expected.** None comes from the test setup.

**Integration test** (`RenameOldSessionTest`, 20 tests, MariaDB): **exit 2**, 17 failures, 3 errors, 19 warnings.

| Group | Tests | Classification |
|---|---|---|
| 17 failures | the I1–I16 cases | **Expected** (missing feature). Status `DONE` while `old_session` is `null`; no `rename.old_session` events; no `PENDING` marker; the legacy rename sends no Disconnect; the lock probe never runs. The fixtures worked (activation, NAS stub, the equivalent-rename write hook fired) |
| 19 warnings | "Undefined array key old_session" | consequence of the missing field |
| error: `testPppoeUsernameEmptyToSetAndSetToEmptyTargetTheFormerPppoeKey` | `TypeError: array_map … null` | **Test-construction weakness**: with no confirmation event it reads `end([])` and errors instead of failing with a message. The outcome is still expected; the test must fail cleanly (handoff item H-T1) |
| errors: `testUsernameAndPppoeTogether…`, `testTheSameOldKeyIsDisconnectedOnceAcrossPlans` | `DomainError REPROVISION_UNCONFIRMED` | **Not test setup: a pre-existing product defect** (§R2 case C), reproduced on the baseline |

**Version note:**
- After that baseline run, the two tests in the last row were adapted (13:14:33Z; the run ended 13:13:59Z). They now accept that outcome and assert that the old-session result is still recorded.
- The adapted version has **not** been run on the baseline.
- Current SHA-256:
  - `RenameOldSessionTest.php` `b66921f6…5805`;
  - `RenameOldSessionUnitTest.php` `015d18f7…284f`.

## R. Rename data-integrity risk (owner observation, confirmed by code reading)

`RadiusRepository::renameUser` (`system/autoload/Reseller/Radius/RadiusRepository.php:170-183`) works per table
(`radcheck`, `radreply`, `radusergroup`). Each `username` column is `utf8mb4_general_ci` (`install/radius.sql:52, 90, 99`).
For each table it:
1. `DELETE … WHERE username = <to>`;
2. `UPDATE … SET username = <to> WHERE username = <from>`.

Both statements compare with the collation, not by bytes.

| Case | What happens | Status |
|---|---|---|
| **A. Equivalent rename** (case / accent only, e.g. `abc` → `ABC`) | step 1 matches `abc` and **deletes the subscriber's own rows**; step 2 has nothing left to move. The policy exists again only if the following `add_customer` re-provisions it. If that step fails or is skipped, the subscriber is left without a RADIUS policy (fail closed, availability) | code-certain; **not yet reproduced by a test** |
| **B. Another key equivalent to the source or target** (legacy S-1 data, orphan rows) | step 2 also **moves the other key's rows** into the renamed subscriber's key (the other subscriber loses its policy; foreign rows can remain under the renamed key). Step 1 also **deletes** rows of any key equivalent to the target | code reading; **not yet reproduced**. The edit validation (`USERNAME_TAKEN`) covers `tbl_customers`, not orphan RADIUS rows |
| **C. Several Radius plans sharing one key** | the rename is repeated per active recharge. The second call's step 1 deletes the rows the first plan just wrote under the new key | **reproduced on the baseline**: R1 (`IDENTITY_EDIT`) ends `REPROVISION_UNCONFIRMED` (2 tests, §R1). R2 (legacy edit, `customers.php:876-901`) has the same loop and **no verification**, so the loss can be silent. The exact rows lost are not yet characterized |

**Severity (proposed): MEDIUM, production blocker together with CARRY-02.** It is policy-integrity and availability:
- case C is proven;
- case B affects another subscriber, but only with pre-existing equivalent-key data.

### R2. Minimum conditions for a safe rename (design, no code)

| ID | Condition |
|---|---|
| F-1 | Rename source and target are matched by **exact bytes** in all three tables; no row of a collation-equivalent other key is read, moved or deleted |
| F-2 | An **equivalent rename** never deletes the source rows: it is an in-place byte update of the same rows |
| F-3 | **Before any write:** if another exact key that is collation-equal to the source or the target exists in RADIUS, refuse with nothing applied. This maps to the accepted `FAILED` → `FAILED_REVERTED` (decision OD-A3R-9) |
| F-4 | **Once per run:** each `from → to` is applied once per re-provision run. Further plans on the same key are only re-provisioned. This applies to R1 (`LegacyReprovisionService` loop) **and** R2 (`customers.php` loop) |
| F-5 | **Verification:** after the rename the exact source has no rows; the target holds the moved rows plus the re-provisioned policy of **every** active plan; rows of every other key are unchanged |
| F-6 | The old-session design of rev. 2 (§4) is applied on top. Its ambiguity check uses exact identities and runs **before** the rename (code reading: a check after the rename would see nothing, because of case B) |
| F-7 | No change to `install/radius.sql` (collations), FreeRADIUS configuration, A1 / A2-R / H1-A / H-2B / Option G, schema, finance or accounting history |

### R3. Files to review for the rename fix (not modified)

| File | Why |
|---|---|
| `system/autoload/Reseller/Radius/RadiusRepository.php` | `renameUser` (F-1, F-2). Its other `WHERE username = ?` statements (read, snapshot, suspend, remove, write; `:46-339`) use the same collation and must be reviewed for case B. A change to them is a separate decision |
| `system/autoload/Reseller/Radius/RadiusProvisioner.php` | the `rename` verification reads the source by collation (F-5) |
| `system/autoload/Reseller/Domain/LegacyReprovisionService.php` | the per-plan loop repeats the rename (F-4); the old-session work (rev. 2) |
| `system/controllers/customers.php` (`:876-901`) | the R2 loop repeats the rename with no verification (F-4). **This file was not in the rev. 2 file list** |
| `system/devices/Radius.php` | `change_username` (F-3, F-6) |
| `system/autoload/Reseller/Radius/CoAClient.php` | `disconnectSession` (approved OD-A3R-8) |
| `system/autoload/Reseller/Activation/DriftReport.php` | `OLD_SESSION_UNCONFIRMED` (approved OD-A3R-5) |
| `system/autoload/Reseller/Radius/RadiusPolicyBuilder.php` | `key()`: which key each plan type uses (review only) |
| tests: `RadiusRepositoryTest`, `LegacyReprovisionTest`, `RadiusDeviceLifecycleTest`, `RealLifecycleTest` (CHR) | regression and the expectation flip (after CHR proof only) |

### R4. Impact on subscribers and plans

| Who | Impact today | After the fix |
|---|---|---|
| Single-plan subscriber, ordinary rename | none from rename integrity (only CARRY-02, the old session) | unchanged + old session handled |
| Case-only rename | own rows deleted, then re-provisioned; a gap, and no policy if re-provisioning fails | in-place update; no gap |
| Subscriber with 2+ Radius plans whose rename touches one key (e.g. Hotspot on the username, PPPoE on its own key) | R1: `REPROVISION_UNCONFIRMED` (operator must sync); R2: possibly silent policy loss | rename once; all plans re-provisioned and verified |
| Subscriber with 2+ Radius plans on the **same** RADIUS key *(corrected 2026-10-13, owner D-2)* | ADMIN_SYNC and any rename end `REPROVISION_UNCONFIRMED` (one `radusergroup` row per key; the earlier plan cannot verify) | **unchanged by A3** (proven identical before and after): a documented pre-existing operational limit that needs separate treatment before this configuration is production-ready. `REPROVISION_UNCONFIRMED` is never a success |
| Another subscriber with an equivalent key | its rows can be moved or deleted | untouched; the rename is refused (F-3) |
| Existing data | no migration is needed. Equivalent-key data and shared-key multi-plan customers need a **read-only production impact query** (separate authorization, OD-A3R-12) |

### R5. Independent acceptance criteria

1. **RADIUS policy integrity:** after every rename case (single plan, multi-plan on distinct keys, PPPoE transitions, equivalent rename; *corrected 2026-10-13, owner D-2: two Radius plans on the same key are the pre-existing residual above, not covered*),
   the policy of every active plan is present and verified under the new key, and nothing remains under the old exact
   key.
2. **No other subscriber touched:** the rows of every other key are byte-identical before and after (checksum), and no
   Disconnect is sent for another subscriber's session.
3. **No session of the new name disconnected** by the old-session step (non-equivalent rename: by name; equivalent
   rename: by the pre-rename snapshot).
4. **Correct audit:**
   - the main re-provision audit carries the `PENDING` marker whenever a key was renamed;
   - `rename.old_session` holds `CONFIRMED` / `UNCONFIRMED` / `NO_ACCOUNTING_EVIDENCE` with per-session detail;
   - a missing or `UNCONFIRMED` confirmation appears in drift as `OLD_SESSION_UNCONFIRMED`;
   - the `REPROVISION_UNCONFIRMED` drift is unaffected;
   - no secret appears.
5. **No tree lock and no transaction during the confirmation wait** (≤ 6 s in total), and no Disconnect inside the lock.
6. The accepted rules are unchanged: `DONE` / `FAILED_REVERTED` / `REPROVISION_UNCONFIRMED`, no automatic rollback after
   an applied rename, no blind retry.
7. Unit + integration on MariaDB and MySQL, real CHR (PPPoE, Hotspot, equivalent rename, lost Start, CoA blocked),
   full harness. Skipped and incomplete tests are never counted as passes.

### R6. New decisions requested

| ID | Question | Recommendation |
|---|---|---|
| **OD-A3R-9** | Rename when another exact key equivalent to the source or target exists | Refuse before any write (FAILED → FAILED_REVERTED, distinct code) |
| **OD-A3R-10** | Extend the A3 file scope: `RadiusRepository.php`, `RadiusProvisioner.php`, `customers.php` (R2 loop), in addition to the four rev. 2 files | Yes; the rename integrity fix is a precondition of the old-session fix |
| **OD-A3R-11** | Case-only (equivalent) renames | Allow, as an in-place byte update (F-2) |
| **OD-A3R-12** | Read-only production impact query: equivalent RADIUS keys; customers with 2+ active Radius plans on one key | Separate authorization before production |
| **OD-A3R-13** | Classify the rename integrity risk (§R) | New carry-forward, MEDIUM, production blocker with CARRY-02 |
| **OD-A3R-14** | The authorized team may adjust the prepared tests where an API name or event name differs, without weakening any assertion; H-T1 (clean failure) fixed | Yes, with each change listed in their report |

| Item | Value |
|---|---|
| Date | 2026-10-12 (rev. 1, rev. 2, rev. 3) |
| Branch / baseline | `wp8/integration-poc` @ `32a162d6e91012ca2638b13332b129ece29331c7` = origin |
| Stage mapping (owner-approved) | A3 = `R-WP8-CARRY-02`, A4 = `R-WP8-CARRY-03`, A5 = `R-WP8-CARRY-01`. Reference: Architecture Lock `IMPLEMENTATION_ROADMAP.md` (commit `8b0ee336`, manifest 10/10 OK), "### A3. Close `R-WP8-CARRY-02`" |
| Subject | `R-WP8-CARRY-02` (from OD-W8-15): OPEN / PRE-EXISTING / MEDIUM / must close before production |
| Lock requirement | GAP-06 (P0): "exact old session identity must be addressed during rename/reprovision, with uncertainty surfaced". AUD-P1-04 / GAP-12 (customer id → edge identities) are P1 and not part of A3 |
| Method | Read-only review of code, existing tests and evidence. No test was run for this plan |

## 1. Proven finding (unchanged from rev. 1)

From `evidence/s5/S5_RESULTS.md` (real CHR, 2026-10-06) and the lab test
`tests/Integration/Wp8/RealLifecycleTest.php::testLegacyReprovisionPasswordAndUsernameOnTheRealNas` (`:173-215`):
- An Admin `IDENTITY_EDIT` username change ends `DONE`. The new identity works; the old identity has no policy.
- **The old identity's live session survives** (asserted: `assertCount(1, $liveOld, 'observed: …still up after the rename')`).
- A second session under the new name was observed at the same time.
- The test still passed on this baseline (H-2B verification run, 2026-10-12), so the behaviour is current.

## 2. Root cause (unchanged from rev. 1)

- `Radius::change_username` (`system/devices/Radius.php:85-90`) only moves the radcheck / radreply / radusergroup rows
  (`RadiusRepository::renameUser`, `:170-183`).
- The later `add_customer` → `Radius::provision` (`:219-231`) disconnects the **new** key only.
- `CoAClient::disconnect($username)` (`CoAClient.php:44-47`) targets the open `radacct` rows **of that name**, and the
  live session is recorded under the **old** name.
- The MikroTik devices already remove the old name's active session (`MikrotikPppoe.php:126-144`,
  `MikrotikHotspot.php:120`).

**Paths:**
- **R1:** reseller-owned customer → `LegacyReprovisionService::run(IDENTITY_EDIT)` (`customers.php:742-748`).
- **R2:** unassigned legacy customer → direct device calls (`customers.php:876-901`).

## 3. Facts that the corrected design relies on (code, verified)

| # | Fact | Evidence |
|---|---|---|
| F1 | `radacct.username` is `utf8mb4_general_ci` (`install/radius.sql:21`), so `openSessions('ali')` (`AccountingService.php:31-34`, `WHERE username = ?`) also returns rows recorded as `ALI`, `Ali`, `àli` … | schema |
| F2 | `radacct.username` holds the spelling the **NAS sent** (stock `SQL-User-Name`), not the stored key. PPPoE accepts any collation-equal spelling (radcheck `utf8mb4_general_ci`); Hotspot only the exact stored bytes (H1-A) | WP8 A1 / H1-A evidence |
| F3 | `tbl_customers.username` / `pppoe_username` are `utf8mb4_general_ci`. The edit validation refuses a NEW name equal (collation) to another customer's `username` or `pppoe_username` (`LegacyReprovisionService::validate`, `USERNAME_TAKEN`; `customers.php:706-709`). Legacy data may still hold two collation-equal RADIUS keys (S-1 condition) | code |
| F4 | `IDENTITY_EDIT` compares old / new with `!==`, so a **case-only or accent-only rename** (old and new collation-equal) is a real rename | `LegacyReprovisionService.php:330` |
| F5 | `CoAClient` per session: ACK → `SUCCESS OK`; Disconnect-NAK Error-Cause 503 → `SUCCESS SESSION_NOT_FOUND`; other NAK → `FAILED`; no answer after 1 + 2 identical sends → `UNKNOWN COA_NO_RESPONSE`; NAS not configured → `FAILED NAS_UNKNOWN` | `CoAClient.php:15-20, 61-79, 146-147`; `CoaDisconnectTest::testNoResponseGivesUnknownAfterIdenticalRetransmissionsAndNoSuccess` |
| F6 | `Radius::disconnectCustomer()` returns `[]` both when there is no open row and when CoA throws (logged only) | `Radius.php:198-216` |
| F7 | `run()` holds the **tree lock** (`GET_LOCK`, advisory, no transaction; timeout 10 s) around validation, the identity write, the device steps and the audit. Outcomes: `DONE`, `FAILED_REVERTED` (confirmed nothing applied), `REPROVISION_UNCONFIRMED` (any UNKNOWN, unverified, or billing changed; no revert, no retry, audit, `reconcile.php drift` → `REPROVISION_UNCONFIRMED`). The audit already carries `old_name_may_remain` on UNCONFIRMED | `LegacyReprovisionService.php:62-115`; `TreeSerialization.php:29-48`; `DriftReport.php:24, 72-75` |
| F8 | The device step closure discards return values; a thrown failure after an applied step is promoted to UNKNOWN and stops the remaining steps | `LegacyReprovisionService.php:301-315` |

## 4. Corrected design

### 4.1 Separation of outcomes (amendment 1)

The re-provision **status** keeps its accepted meaning:
- `DONE`: the identity was updated and the RADIUS policy is provisioned and verified.
- `FAILED_REVERTED` and `REPROVISION_UNCONFIRMED`: as today (F7).
- **`DONE` never states anything about the old identity's sessions.**

A separate field **`old_session`** carries the old-identity session state of the run:

| `old_session` | Meaning (and only this) |
|---|---|
| `NOT_APPLICABLE` | no RADIUS identity key changed in this run (e.g. `PASSWORD_CHANGE`, or no Radius-device plan) |
| `CONFIRMED` | every targeted old-identity session has **NAS-side evidence of its end**: (a) Disconnect-ACK **and** its `radacct` row closed by an Accounting-Stop within the bound `B`; or (b) Disconnect-NAK 503, the NAS reports no such session |
| `UNCONFIRMED` | at least one targeted session lacks that evidence: ACK but no Stop within `B`, another NAK, `COA_NO_RESPONSE`, `NAS_UNKNOWN`, an exception, or the identity is **ambiguous** (§4.2: nothing is sent) |
| `NO_ACCOUNTING_EVIDENCE` | no open `radacct` row exists for the old identity. **Not a proof that it is offline** (lost or late Accounting-Start; amendment 1, F6): nothing could be targeted |

- When several keys change in one run (username and PPPoE username), each key gets its own result.
- The run value is the **worst** of them: `UNCONFIRMED` > `NO_ACCOUNTING_EVIDENCE` > `CONFIRMED` > `NOT_APPLICABLE`.

**Rules (accepted model):**
- **No rollback:** the identity change stays applied whatever `old_session` is.
- **No blind retry:** CoA's own 1 + 2 identical sends only; a later disconnect needs a new operator action.
- **No status promotion:** `old_session` never turns `DONE` into `REPROVISION_UNCONFIRMED`, and never the reverse.
- **A disconnect problem never throws from the rename step.** Throwing would promote the run to UNKNOWN and skip
  `add_customer` (F8). Only a failed rename throws, as today.

### 4.2 Exact targeting (amendment 2)

For each renamed RADIUS key `from → to` (the `change_username` call):

1. **Equivalence test** (radius DB, same collation as `radacct.username`): is `from` collation-equal to `to`?
2. **Snapshot before the rename:** `S0` = `openSessions(from)` (radacctid, NAS, Acct-Session-Id, spelling, start).
3. **Rename** (unchanged; a failure throws as today, and nothing is sent).
4. **Ambiguity check after the rename.**
   - Look for any radcheck key collation-equal to `from` whose exact bytes differ from `to`.
   - If one exists, another subscriber shares an equivalent name (legacy S-1 data).
   - Then **no Disconnect is sent** and the result is `UNCONFIRMED`, reason `ambiguous_identity`.
5. **Targets:**
   - **Non-equivalent rename** (`from` ≠ `to` by collation): `T` = `openSessions(from)` after the rename. These rows
     cannot belong to the new name (collation equality is transitive). The old key has no policy any more, so they are
     all old-identity sessions, including a late Start of an attempt accepted before the rename.
   - **Equivalent rename** (case / accent only): `T` = the rows of `S0` that are still open. A session that started
     after the snapshot, under any spelling, is **never** targeted. This is the only way to tell the new name's sessions
     apart, because `radacct` cannot (F1, F2).
6. **Disconnect each row of `T` individually.** The Disconnect goes to its own NAS, with its `User-Name` (the recorded
   spelling), `Acct-Session-Id`, `Framed-IP-Address` and `NAS-IP-Address`, as `CoAClient` already builds them. This
   covers several sessions on several NAS. Each row gets its own `ProvisionResult`.
7. **Return** a structured result: per row the radacctid, NAS id, CoA status / code and the ambiguity flag. Values are
   never secrets; the Acct-Session-Id is kept in the audit (it is already in `radacct`).

**Deduplication:**
- A run calls `change_username` once per (plan, key pair); a customer with a Hotspot and a PPPoE plan can rename the
  same `from` twice (the second rename moves 0 rows).
- The run disconnects each `from` **once**; a later call for the same `from` reuses the first result.

### 4.3 Confirmation without holding the tree lock

- The Stop check (step "ACK → Stop within `B`") runs **after** `TreeSerialization::hold` returns.
- It runs without the tree lock and without a DB transaction. It polls `openSessions(from)` for the ACKed radacctids
  (each ≤ 0.5 s, bound `B`; proposed `B` = 6 s, OD-A3R-6).
- It then writes **one additional audit event `rename.old_session`**: classification, per-key and per-session
  detail, codes and NAS ids only.
- The main `reprovision` audit (outcome `DONE` / `UNCONFIRMED` / `FAILED_REVERTED`) is unchanged and is written inside
  the lock as today.
- `run()` returns `['status' => …, 'routers' => …, 'reference' => …, 'old_session' => …]`.

### 4.4 Operator message and drift

- `LegacyReprovisionService::web()` shows the status message as today, plus one sentence per `old_session` value.
  Example for `NO_ACCOUNTING_EVIDENCE`: "No session of the old name is recorded; this does not prove it is offline."
  `UNCONFIRMED` and `NO_ACCOUNTING_EVIDENCE` are shown as warnings, not as success.
- **Drift (OD-A3R-5):** `reconcile.php drift` reports a new kind `OLD_SESSION_UNCONFIRMED` for a customer whose
  latest `rename.old_session` is `UNCONFIRMED` and not superseded. This keeps the uncertainty visible after the
  request, like `REPROVISION_UNCONFIRMED`.

### 4.5 Unassigned legacy customers (R2, amendment 4)

- The device change (§4.2) applies to every `Radius` rename, so legacy renames also end the old sessions, as the
  MikroTik devices already do (owner: included).
- The legacy controller ignores the return value: no Stop wait and no new audit; each non-success is logged through
  `ErrorReporter` (as `disconnectCustomer` logs today).
- The legacy flow is otherwise unchanged and never throws because of CoA.

### 4.6 RadiusRest (amendment 4: out of scope, documented)

- **Actual use:**
  - a legacy device a plan can select (`system/devices/RadiusRest.php`);
  - the REST endpoint is **off** unless `config.php` sets `$radius_rest_secret` (`install/freeradius/README.md` "rlm_rest mode");
  - reseller activations can never use it (`ActivationService` requires `device = 'Radius'`, `:97, :234`).
- **Gap left open:**
  - its `change_username` is a no-op (only the device guard), so an rlm_rest rename never ends the old session;
  - its `remove_customer` and `disconnect_customer` send no disconnect at all;
  - this concerns unassigned legacy customers on RadiusRest plans only, and is pre-existing.
- **Effect:** CARRY-02 stays open for rlm_rest mode. It is recorded in the A3 report as a residual and is not counted as
  closed.

## 5. Coverage matrix (amendment 3)

| Case | Rename call(s) | Targeting | Expected `old_session` |
|---|---|---|---|
| username old → new (Hotspot, or PPPoE without PPPoE username) | `old → new` | non-equivalent: all open rows of `old` | CONFIRMED / UNCONFIRMED / NO_ACCOUNTING_EVIDENCE per evidence |
| PPPoE username old → new | `oldP → newP` | non-equivalent | same |
| PPPoE username empty → set | `username → newP` (`:333-334`) | rows of the username key (the PPPoE key so far) | same |
| PPPoE username set → empty | `oldP → username` (`:335-336`) | rows of `oldP` | same |
| username and PPPoE username in one edit | two calls; per-key result, run = worst | per key | worst of the keys |
| Hotspot + PPPoE plans, same `from` | dedup: one disconnect per `from` | — | one result per key |
| case / accent-only rename (equivalent) | `old → New` | `S0` rows still open only; later sessions never targeted | per evidence |
| other subscriber holds a collation-equal key | any | **none sent** | UNCONFIRMED (`ambiguous_identity`) |
| several sessions on several NAS | any | one Disconnect per row to its NAS | worst per row |
| lost Accounting-Start (live session, no row) | any | nothing to target | **NO_ACCOUNTING_EVIDENCE** (residual L-2) |
| CoA not answered / NAS not configured / CoA port blocked | any | sent | UNCONFIRMED |
| ACK but no Accounting-Stop within `B` | any | sent | UNCONFIRMED (`ack_without_stop`) |
| stale open row (lost Stop), NAS says 503 | any | sent | CONFIRMED for that row (NAS-side absence) |

## 6. Exact files

| File | Change | Why |
|---|---|---|
| `system/devices/Radius.php` | `change_username`: equivalence test, snapshot `S0`, rename (unchanged), ambiguity check, per-row disconnect, structured return. Never throws for CoA | the defect is here; covers R1 and R2 |
| `system/autoload/Reseller/Radius/CoAClient.php` | **additional file**: one public method `disconnectSession(array $row): ProvisionResult` wrapping the existing private `toSessionNas(…, DISCONNECT_REQUEST, [])` | exact per-row targeting (§4.2 step 6). Today `disconnect($username)` re-reads the rows by name, so the equivalent-rename snapshot could not be honoured. No change to the packet, retry or mapping logic |
| `system/autoload/Reseller/Domain/LegacyReprovisionService.php` | keep the rename step results; dedup per `from`; after `hold`: Stop confirmation (no lock, no transaction), classification, `rename.old_session` audit, `old_session` in the return and in `web()` | amendment 1 |
| `system/autoload/Reseller/Activation/DriftReport.php` | `OLD_SESSION_UNCONFIRMED` kind (only if OD-A3R-5 = yes) | uncertainty stays visible |
| `docs/WP5B_LEGACY_CLOSURE_MODEL.md`, `install/freeradius/README.md` (Disconnect / CoA note), `owner-review/WP8/OWNER_DECISIONS.md` | additive | rule, states, limits |

**No change to:**
- `AccountingService` (`openSessions` is reused);
- the radacct / radcheck schema;
- accounting history (nothing is written to `radacct`);
- finance, the activation services;
- A2-R, H1-A, H-2B, Option G, `install/radius.sql`, `install/freeradius/*` site and modules;
- the MikroTik devices, `RadiusRest`, the development pack.

## 7. Tests

### 7.1 Unit (no network)

| ID | Test |
|---|---|
| U1 | Classification table: every combination of per-row results → `CONFIRMED` / `UNCONFIRMED` / `NO_ACCOUNTING_EVIDENCE`; worst-of aggregation across keys |
| U2 | `ProvisionResult` mapping of ACK, NAK 503, NAK other, no response, NAS unknown, exception |
| U3 | Source guard (mutation-checked): in `Radius::change_username` the snapshot comes before `rename`, the disconnect after it, the targets are rows of `$from` only, and there is no `throw` on the CoA path. In `LegacyReprovisionService` the Stop wait is outside `TreeSerialization::hold` |
| U4 | `old_session` never changes `status` (DONE stays DONE; UNCONFIRMED stays UNCONFIRMED) |

### 7.2 Integration (harness FreeRADIUS + database, both engines; `StubNas` for CoA)

| ID | Test | Expectation |
|---|---|---|
| I1 | username old → new, one row, StubNas ACK, then an Accounting-Stop sent through FreeRADIUS | exactly one Disconnect, `User-Name` = recorded spelling; `CONFIRMED` |
| I2 | ACK, no Stop | `UNCONFIRMED` (`ack_without_stop`) after `B`; status `DONE` |
| I3 | NAK 503 | `CONFIRMED` |
| I4 | NAK other / no response / NAS not in `nas` | `UNCONFIRMED`; no retry beyond 1 + 2 identical sends; no revert |
| I5 | no open row | no Disconnect; `NO_ACCOUNTING_EVIDENCE` |
| I6 | two rows on two NAS (one ACK + Stop, one silent) | two Disconnects to their own NAS; `UNCONFIRMED`; per-row detail in the audit |
| I7 | PPPoE username old → new, empty → set, set → empty, username + PPPoE username together | the right `from` per call; per-key results; worst-of |
| I8 | Hotspot + PPPoE plans with the same `from` | one Disconnect set per `from` |
| I9 | **equivalent rename** `nuxr_x` → `NUXR_X`: one row before the rename (recorded `nuxr_x`), one row started after it (`NUXR_X`) | only the first is targeted |
| I10 | **non-equivalent rename with recorded spelling variants**: rows `OLDNAME` / `oldname` for key `oldname` | both targeted; no row of the new name targeted |
| I11 | **ambiguous**: another radcheck key collation-equal to `from` exists | nothing sent; `UNCONFIRMED` (`ambiguous_identity`) |
| I12 | rename step fails (FAILED, nothing applied) | no Disconnect; `FAILED_REVERTED` as today |
| I13 | rename ok, `add_customer` fails | `REPROVISION_UNCONFIRMED` (unchanged) and `old_session` recorded |
| I14 | legacy unassigned rename (R2) | old rows disconnected; the legacy flow is unchanged otherwise; no exception when CoA fails |
| I15 | during the Stop wait | no tree lock held (`IS_USED_LOCK`), no open transaction; an activation of another customer is not blocked |
| I16 | `PASSWORD_CHANGE` | `NOT_APPLICABLE`; behaviour unchanged (the existing new-key disconnect) |
| I17 | regression | `LegacyReprovisionTest`, CoA tests, RouteMatrix, A2-R / H1-A / H-2B / Option G suites and file hashes unchanged; full harness, both engines |

### 7.3 Real CHR (lab; RouterOS 7.24.5, CHR1 + CHR2)

| ID | Test | Expectation |
|---|---|---|
| C1 | PPPoE live under the old username → IDENTITY_EDIT | old session ends on the NAS (`/ppp active`), Stop recorded, `CONFIRMED`; the new name logs in; never two live sessions after the Disconnect; overlap window measured (L-1) |
| C2 | Hotspot (`shared_users` 2), old-name sessions on CHR1 **and** CHR2 | both end; `CONFIRMED` |
| C3 | PPPoE username empty → set and set → empty | old-key session ends |
| C4 | case-only rename with a live session | the pre-rename session ends; a session started after the rename (new spelling) survives |
| C5 | CoA blocked on the NAS firewall | `UNCONFIRMED`; the session survives and is visible to the operator / drift |
| C6 | Accounting-Start blocked for the old session | `NO_ACCOUNTING_EVIDENCE`; the live session is observed on the NAS (proves the residual) |
| C7 | only after C1–C6 pass: `RealLifecycleTest` expectation flipped to "the old-identity session is ended" (amendment 4) | whole `wp8-integration` suite re-run |

No A2-R SQL-exhaustion or PENDING-recovery test is part of this plan.

## 8. Partial failure

| Failure point | State left | Outcome |
|---|---|---|
| Identity write fails | nothing changed | as today (error) |
| Rename fails (confirmed nothing) | identity reverted (`FAILED_REVERTED`) | no Disconnect sent |
| Rename unknown (lost commit) | UNKNOWN → `REPROVISION_UNCONFIRMED` | no Disconnect (rename not confirmed); `old_session = UNCONFIRMED` (`rename_unconfirmed`) |
| Rename ok, ambiguity found | rename kept | nothing sent; `UNCONFIRMED` |
| Rename ok, some Disconnects fail | rename kept; some old sessions may live | `UNCONFIRMED`, per-row detail; no retry, no revert |
| Rename ok, `add_customer` fails | `REPROVISION_UNCONFIRMED` as today | `old_session` from the rename step recorded |
| Crash after Disconnect, before the `old_session` audit | sessions may be ended; no `old_session` record | the main audit (inside the lock) already shows the run. A missing `old_session` event reads as "not confirmed" (drift kind `OLD_SESSION_UNCONFIRMED` treats a missing record as unconfirmed for runs that changed a key) |
| Stop wait times out | — | `UNCONFIRMED` (`ack_without_stop`) |

## 9. Acceptance, stop and rollback conditions

**Acceptance (all required):**
- U1–U4 and I1–I17 pass on MariaDB and MySQL.
- C1–C6 pass on the real CHR.
- Full harness exit 0 (incomplete tests counted separately, never as passes).
- A2-R / H1-A / H-2B / Option G files byte-identical.
- Secret sweep 0. Manifest 42/42.

**Stop immediately, no commit, report with evidence:**
- any test or CHR run where a **new-name** session (non-equivalent rename) or **another subscriber's** session is
  disconnected;
- `CONFIRMED` while the old session is still live on the NAS;
- a `status` change caused by `old_session`;
- the tree lock or a transaction held during the Stop wait;
- any change needed in A2-R / H1-A / H-2B / Option G, the schema, finance or accounting history;
- a secret in a log or the audit.

**Rollback:**
- Revert the single commit. There is no migration and no configuration change.
- The `rename.old_session` audit rows are append-only and harmless.
- Disconnects already sent cannot be undone; they only end sessions of an identity that no longer has a policy.

**Resume point after approval:**
1. Backup (SHA256SUMS).
2. U3 and I1 written first; they must fail on the baseline.
3. C1–C4 code.
4. Unit and harness tests.
5. Lab C1–C6.
6. C7.
7. Full harness.
8. Report.
9. Stop for review.

## 10. Residual limits (documented, not solved by A3)

| ID | Limit |
|---|---|
| L-1 | Overlap window between the rename and the Disconnect (ms to ≈ 3 s with CoA resends); measured in C1 |
| L-2 | A live old session without any `radacct` row (lost Start) cannot be targeted: `NO_ACCOUNTING_EVIDENCE`, never `CONFIRMED` |
| L-3 | Equivalent rename: a session that started between the snapshot and the rename is not targeted (snapshot rule) |
| L-4 | `RadiusRest` (rlm_rest) mode: gap left open (§4.6) |
| L-5 | Old-name A2-R reservations / H-2B slots end by their own Stop, or stay per Strict Mode; never changed by A3 |

## 11. Owner decisions

| ID | Item | Status |
|---|---|---|
| OD-A3R-1 | Radius fix includes unassigned legacy customers, with compatibility regression | **APPROVED** (owner, 2026-10-12) |
| OD-A3R-2 | rev. 1 proposal "DONE + UNCONFIRMED" | **REPLACED** by amendment 1 → §4.1 (separate `old_session`, three states, no status promotion) |
| OD-A3R-3 | RadiusRest out of A3, documented | **APPROVED**; documented in §4.6 / L-4 |
| OD-A3R-4 | flip the `RealLifecycleTest` expectation | **APPROVED only after real-CHR proof** (C7) |
| **OD-A3R-5** | Add the drift kind `OLD_SESSION_UNCONFIRMED` (`DriftReport.php`, one more file) | **requested**. Recommendation: yes |
| **OD-A3R-6** | Stop-confirmation bound `B` | **requested**. Recommendation: 6 s, outside the tree lock |
| **OD-A3R-7** | NAK 503 (NAS: no such session) counts as `CONFIRMED` for that row | **requested**. Recommendation: yes (NAS-side evidence; accepted CoA mapping) |
| **OD-A3R-8** | `CoAClient::disconnectSession` (one public method in an additional file) | **requested**. Recommendation: yes (needed for the equivalent-rename snapshot) |

## 12. Unchanged open items

- A2-R SQL Exhaustion: `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`, no re-test.
- PENDING Recovery.
- AV-1, AV-2, AV-3; MAC-H1A.
- V-1.
- A4 (`R-WP8-CARRY-03`), A5 (`R-WP8-CARRY-01`, approved in principle), `R-HF-CARRY-01`.
- S7, S8.

WP8 stays open.

---

## Owner decisions — planning closure (2026-10-12, additive)

**Official status: `A3_PLAN_APPROVED — IMPLEMENTATION_BLOCKED`.** Plan rev. 3 and the handoff are approved; the study and
planning stage is complete. Code implementation of A3 stays stopped in this session. Any independent implementation is
subject to the authorized engineering team's review and the usual acceptance procedure. WP8 stays open.

| ID | Decision |
|---|---|
| OD-A3R-9 | **APPROVED.** An ambiguous rename is refused **before any write**. Do **not** report it as `FAILED_REVERTED` when no revert actually happened (use a distinct refusal outcome) |
| OD-A3R-10 | **APPROVED.** The extended file review of plan §R3 (`RadiusRepository.php`, `RadiusProvisioner.php`, `customers.php`, plus the rev. 2 files) |
| OD-A3R-11 | **APPROVED WITH CONDITIONS.** An equivalent (case / accent-only) rename is allowed only with guaranteed data and identity integrity (F-1, F-2, F-5) |
| OD-A3R-12 | **DEFERRED.** No production read without separate authorization |
| OD-A3R-13 | **APPROVED WITH AMENDMENT.** MEDIUM / PRODUCTION BLOCKER, recorded as a **sub-defect of `R-WP8-CARRY-02`**, not as a new carry-forward |
| OD-A3R-14 | **APPROVED.** Fix H-T1 and the API / event names without weakening any assertion; **re-run the tests before their results are relied on** |

**Unchanged:**
- the tests, the evidence and their SHA-256;
- no retry of the edits stopped by the safety classifier, in any form;
- no change to production code or tests in this session;
- no commit, push, merge or deploy;
- A4, A5 and V-1 not started.


## Corrections (2026-10-13, owner decision D-2 / review I4)

- §R4 and §R5.1: "multi-plan" means several Radius plans whose rename touches one key (case C). Two Radius plans on the **same** RADIUS key are a pre-existing limit, unchanged by A3: ADMIN_SYNC and renames end `REPROVISION_UNCONFIRMED`. That limit needs separate treatment before the configuration is production-ready.
- §4.3, §4.4 and §6: the audit event of the old-session confirmation is `rename.old_session`, as implemented and in the handoff. It was written `reprovision.old_session` in 4 places. A `reprovision.%` name would break the `REPROVISION_UNCONFIRMED` drift.
