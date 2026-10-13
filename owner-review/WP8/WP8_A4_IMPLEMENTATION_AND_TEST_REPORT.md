# WP8 A4 — `R-WP8-CARRY-03` Implementation and Test Report

| Item | Value |
|---|---|
| Date | 2026-10-13 |
| Owner decision | `A4_PLAN_APPROVED_WITH_AMENDMENTS — DEVELOPMENT_IMPLEMENTATION_AUTHORIZED` (OD-A4-1..6 approved with conditions) |
| Baseline | `wp8/integration-poc@2628c632123b65578ce29908aa33f27d7ccaae93` (= origin, unchanged) |
| Round | Development only. No commit, push, merge or deployment; no production query or setting; A5 / S7 / S8 not started |
| Verdict | **`READY_FOR_OWNER_ACCEPTANCE`** (§10) |

## 1. Root cause, with evidence

FreeRADIUS writes `radacct.acctstarttime / acctupdatetime / acctstoptime` as `FROM_UNIXTIME(epoch)`. The value is a wall clock in the **writer's SQL session zone**, stored in a `DATETIME` that keeps no zone. Before A4 the application interpreted these values with the **PHP zone**. Three defects followed.

| ID | Defect (before A4) | Evidence |
|---|---|---|
| D-1 | `AccountingService::staleSessions()` formatted its cutoff in the zone of `$now` and compared the text with the `DATETIME` | **Lab (OD-W8-16, before A4):** app +03 vs DB UTC, the app clock listed `[18, 19]` (old **and** live). **Harness pre-fix (`evidence/a4/pre/pre-fix-run1.out`):** a 60 s old row in an `America/New_York` session is reported stale when `$now` carries another zone (`…60 s old across the spring gap is fresh`, failed). The old query also **silently dropped** open rows with a future time or with no time at all (the matrix failed already at DB `+00:00` / PHP `UTC`: `future-3h`, `future-tol+1`, `no-times` missing) |
| D-2 | `Subscriber::session()` computed age as `time() - strtotime(<DB text>)`, off by Δ = app offset − DB offset | **Real HTTP application, harness pre-fix (both engines):** the admin page of a session updated **seconds earlier** shows **"Status unknown"**. Harness app zone = `Asia/Jakarta` (`tbl_appconfig`), DB = UTC. The old assertion `assertStringContainsString('Online')` hid this, because "Online since" also contains "Online". **Contract test pre-fix:** app `Asia/Baghdad` → `stale` instead of `online` |
| D-3 | "Online since" / "Last seen" printed the raw DB-local text, without a zone label | contract test pre-fix: `'2026-10-13 18:02:00'` instead of the application-zone instant with its offset |

**Decision impact (unchanged from the plan, re-verified):**
- These values drive display and reporting only.
- No admission, activation, Disconnect, quota, finance or reversal path reads them.
- A3's capture and confirmation use only `radacctid` / NAS / session id (independent review, §8).

## 2. The final write / read time contract

The proofs below were required by the owner before implementation (§1 of the decision). Each one is an executed test.

| # | Owner condition | Proof (test → result, MariaDB 10.11.19 and MySQL 8.0.46) |
|---|---|---|
| 1 | The FreeRADIUS SQL session that writes and the PHP SQL session that reads use the same time reference | `testTheFreeRadiusWriterAndTheApplicationReaderShareOneTimeReference` sends **real** Accounting Start / Interim / Stop to the harness FreeRADIUS 3.2.8 (stock queries) with `Event-Timestamp = E`. **The text FreeRADIUS stored for E equals the text the application session gives for `FROM_UNIXTIME(E)`.** `UNIX_TIMESTAMP` gives back E, E+60, E+120 exactly. FreeRADIUS's own `acctinterval` = 60. Without `Event-Timestamp` (`%l`), the stored epoch equals PHP `time()` (diff 0). Zones: global `SYSTEM`, session `SYSTEM`, system `UTC` (`evidence/a4/post/contract-*.jsonl`, case `writer-reader`) |
| 2 | `FROM_UNIXTIME` / `UNIX_TIMESTAMP` convert as expected on both engines | `testFromUnixtimeAndUnixTimestampConvertExactlyInEveryNonDstZone`: zones `+00:00, +03:00, -05:00, +05:45, UTC, Asia/Baghdad` × 8 instants (incl. DST-edge instants). `FROM_UNIXTIME` equals PHP's wall clock in that zone; the round trip is lossless. DST zones: §6 |
| 3 | A different PHP zone does not change the result | `testStaleSessionsIsTheSameForEveryDatabaseZoneAndApplicationZone` (DB `+00:00/+03:00/-05:00` × PHP `UTC/Asia/Baghdad/America/New_York` × zone of `$now` `UTC/+03:00/-05:00/Asia/Baghdad`). `testSubscriberSessionStateAndDisplayAreTheSameInstantInEveryZoneCombination`. `testChangingTheApplicationZoneBetweenWriteAndReadChangesNothing` (5 PHP zones incl. `+05:45`). §5.1 |
| 4 | A change of SQL session zone between write and read must not pass as a correct case without proof | `testAZoneMismatchShiftsEveryEpochExactlyAndVoidsTheFreshnessGuarantee`: the **real** writer (UTC) read with session `+03:00 / -05:00 / -00:15 / +00:15` gives every epoch shifted by **exactly** the zone difference. `testMixedHistoryWrittenBeforeAndAfterADatabaseZoneChangeIsReadNeverRewritten`: rows from before a DB zone change read 3 h off and are never rewritten. **The mismatch voids the freshness guarantee** (§7, L-A4-2). It is therefore forbidden by the contract, never treated as a correct state |

**Contract (documented in `docs/WP4_RADIUS_MODEL.md` §4.1):**
1. **Writer:** FreeRADIUS `FROM_UNIXTIME(Event-Timestamp or %l)` in its session zone. **Proven on the CHR:** RouterOS 7.24.5 **sends `Event-Timestamp`** in Start, Interim-Update and Stop, but not in Accounting-On. The radacct times are therefore the **NAS clock** (T-L4, §4.3).
2. **Reader:** the application reads epochs only, `UNIX_TIMESTAMP(col) AS acctstart_epoch / acctupdate_epoch / acctstop_epoch`. Every comparison and display uses the epochs. The PHP zone affects only how an instant is printed.
3. **Same session zone on both sides.** Neither FreeRADIUS nor `init.php` sets `time_zone` (source-guarded), so both use the RADIUS database server zone. Never override it on one side.
4. **Production: the RADIUS database zone is UTC** (owner decision), checked by a later operational preflight. No production setting changed now.
5. **Clocks:** NTP on the PHP, FreeRADIUS, DB **and NAS** hosts. A last update more than 300 s in the future is a contract break (never fresh).
6. **Unchanged contracts:**
   - the application zone and FreeRADIUS `Expiration` (residual 4 / OD-A4-3);
   - RadiusRest (OD-A4-4);
   - A2-R / H-2B (`UTC_TIMESTAMP`).

## 3. Changes made

| File | Change | Lines (+/−) |
|---|---|---|
| `system/autoload/Reseller/Radius/AccountingService.php` | **F-2:** additive `*_epoch` columns on every row read. **F-1:** `staleSessions()` uses an epoch cutoff `$now->getTimestamp() - max(60, s)`, with SQL `COALESCE(UNIX_TIMESTAMP(COALESCE(acctupdatetime, acctstarttime)), 0) NOT BETWEEN cutoff AND now+300`; a missing, invalid or future time is reported, never treated as fresh. `CLOCK_TOLERANCE_SECONDS = 300`, `epoch()` normaliser. `rows()` binds ints as `PARAM_INT`. Signature, existing keys, ordering and the 60 s floor are unchanged | +36 / −6 |
| `system/autoload/Reseller/Ui/Subscriber.php` | **F-3:** state from `sessionState(open, seenEpoch, time())`. The 1800 s edge is unchanged; NULL, invalid or future → `stale` ("Status unknown"). Times via `displayTime()` = application zone + offset (`2026-10-13 21:57:13 UTC+03:00`). Additive `started_epoch / last_update_epoch / stopped_epoch`. Field names unchanged; the template is untouched | +30 / −8 |
| `docs/WP4_RADIUS_MODEL.md` | **F-4:** §4.1 accounting time contract, residual 4 clarified, residual 10 added | +31 / −1 |
| `tests/Integration/Wp8/CheckradGateTest.php` | **F-5 (OD-A4-5, after the lab proof):** gate B gives `staleSessions()` the **application** clock instead of the DB-clock workaround. It asserts the zones differ (discriminating), then `[$old]` exactly. **Strengthened:** the assertion now applies to the real production input | +9 / −7 |
| `tests/Integration/Wp6a/Subscriber360Test.php` | **Strengthened:** "not Status unknown" for a fresh session (Admin and Agent); the displayed "Online since" / "Last seen" equal the accounting instant in the application zone; the fixture writes the DB session wall clock (`NOW()`), as FreeRADIUS does | +12 / −2 |
| `tests/Unit/Wp4/AccountingTimeUnitTest.php` (new) | T-U1..4: ±1 s boundaries, tolerance, epoch normalisation, DST display (Berlin / New York spring and autumn, Baghdad, UTC, Kathmandu), source guards | 160 |
| `tests/Integration/Wp4/AccountingTimeContractTest.php` (new) | T-I1..7 + the owner's four contract proofs, outage, mismatch, mixed history, DST, performance | 581 |
| `tests/Integration/Wp8/AccountingTimeLabTest.php` (new) | T-L1, T-L2, T-L4 on the CHR | 176 |

**Not changed** (`git diff 2628c632` is empty for these):
- `install/` (incl. `radius.sql`, `install/freeradius/*`), `init.php`, `radius.php`, `system/cron.php`;
- `system/devices`, `system/controllers`, `ui/`;
- `Reseller/{Domain,Activation,Finance}`, `CoAClient`, `RadiusRepository`, `RestCounter`;
- `dev/`, `tests/phpunit.xml`.

No schema change, no radacct write, no FreeRADIUS configuration change, and nothing in A1 / A2-R / H1-A / H-2B / A3 / Option G / Expiration / RadiusRest.

**Deviations from the plan (recorded):**
- the unit test lives in `tests/Unit/Wp4/` (the accounting model's suite), not `Unit/Wp8/`, so `phpunit.xml` needs no change;
- plan RK-7 said radacct has only the primary key. That was wrong: `install/radius.sql:118-131` has `KEY acctstoptime` and others. §9 uses the real indexes;
- the plan's T-U3 (cutoff independent of `$now`'s zone) is covered in the integration matrix with a real DB.

## 4. Test results (real exit codes; incomplete and skipped are never counted as passed)

### 4.1 Tests first: the new tests on the unfixed code (`evidence/a4/pre/pre-fix-run1.out`)

| Suite | MariaDB | MySQL |
|---|---|---|
| `AccountingTimeUnitTest` | 6 tests, 5 errors + 1 failure, **exit 2** | same, **exit 2** |
| `AccountingTimeContractTest` | 11 tests: 6 failures, 2 errors (1 = missing `epoch()`, 1 = **a bug in my test**, an unread `ANALYZE` result, fixed), 1 warning, **exit 2** | same, **exit 2** |
| `Subscriber360Test` (strengthened) | 1 failure: "Status unknown" for a fresh session (**D-2 in the real HTTP app**), **exit 1** | same, **exit 1** |

### 4.2 Focused runs on the fixed code (`evidence/a4/post/`)

| Run | Scope | Result |
|---|---|---|
| run 1 (`focused-run1.out`) | A4 tests + 16 regression suites × 2 engines (38 runs) | 36 × exit 0. **`Wp6aSourceGuardTest` exit 1 on both engines:** my new comment used the word "update", which the UI read-only guard forbids. Fixed by rewording the comment; **the guard was not changed** |
| run 2 (`focused-run2-ABORTED-test-edit.out`) | — | **Aborted** during container start-up (stopped by process-group ID; containers removed by the script's own trap) to add a DST check. Kept |
| **run 3** (`focused-run3.out`) | 38 runs | **38 / 38 exit 0** |
| **run 4** (`focused-run4-a4-tests-after-review.out`) | the 3 A4 test files after the review corrections (§8) × 2 engines | **6 / 6 exit 0**: unit 6 tests / 79 assertions; contract 11 / 666; Subscriber360 4 / 137 (each engine) |

**Run 3 regression suites (each exit 0 on both engines):**
- **Unit:** `RadiusSourceGuardTest`, `Wp6aSourceGuardTest`, `RenameOldSessionUnitTest`.
- **Wp4:** `RadiusRepositoryTest`, `FreeRadiusContractTest` (counters), `CoAClientTest`, `RadiusDeviceLifecycleTest`, `RadiusAdminFlowTest`, `SessionGuardContractTest` (Option G), `AdmissionReservationContractTest` (A2-R), `HotspotAdmissionContractTest` (H-2B).
- **Wp5b (A3):** `RenameOldSessionTest`, `RenameRemediationTest`, `RenameIntegrityTest`, `LegacyReprovisionTest`, `DriftReportTest`.

### 4.3 Real lab: RouterOS CHR 7.24.5, app `Asia/Baghdad` (+03), DB UTC (`evidence/a4/lab/`)

| Run | Result |
|---|---|
| `T-L-run1.out`: `AccountingTimeLabTest` (lab default) | **1 test, 30 assertions, exit 0** |
| `F-5-gateB-run1.out`: `CheckradGateTest::testSnmpGateB` in checkrad gate mode (`checkrad_gate.py on` → test → `off`, all rc 0) | **1 test, 20 assertions, exit 0** |
| `lab-chr-focused.out`: production-contract configuration (`option_g_gate.py on shipped`, CHR2, `h2_gate` / `a2r_gate chr2` / `h2b_gate site project`): `AccountingTimeLabTest` + `RenameOldSessionLabTest` (A3 C1..C6) + `RealLifecycleTest` (S5) | **10 tests, 299 assertions, exit 0** |
| Lab teardown (`lab-console.out`) | `h2_gate teardown` rc 0, `lab.sh down` rc 0; no container, link or netns left |

**Lab facts (`lab-evidence-chr.jsonl`, `T-L4-event-timestamp.txt`):**

**T-L1 / T-L2: live session.**
- **Freshness:** with the application clock the live session is **not stale**, and Subscriber shows **online**.
- **"Online since":** `2026-10-13 21:48:18 UTC+03:00`, the DB text was `18:48:18`, and the real start from the RouterOS uptime matches within 1 s.

**Accounting outage.**
- **Cut:** UDP/1813 was dropped on the NAS. After 124 s without an Interim the session is reported stale, while still up on the NAS.
- **Display:** the admin view still says online. This is correct below its 1800 s threshold.
- **Recovery:** the Interims resume and the session is fresh again.
- **Stop:** the session shows offline, with the Stop instant correct.
- **History:** the retained history is byte-identical.

**F-5 / OBS-W8-8 reversed.**
- **Gate B:** app clock `21:55:22 +03`, DB `18:55:22`. The application clock reports **`[2]` (the historical row only)**. Before A4 it reported `[18, 19]` (historical + live).
- **Old row:** unchanged by the admission and by the stale check.

**T-L4: Event-Timestamp.**
- **Present** in Start / Interim / Stop, absent in Accounting-On.
- `Event-Timestamp` = capture second − 1 s.
- The Interims dropped during the cut were never resent.
- CHR clock: `gmt-offset +00:00`, `dst-active false`.

### 4.4 Full harness, frozen tree, both engines (`evidence/a4/harness/`)

Run: `dev/test/run-tests.sh` → `harness-both-engines.out`, **exit 0**.

| Engine | Collected | Tests | Assertions | Incomplete | Skipped | Failures / errors / warnings |
|---|---|---|---|---|---|---|
| MariaDB 10.11.19 | 1185 | 1099 | 69266 | 86 | 0 | 0 / 0 / 0 |
| MySQL 8.0.46 | 1185 | 1099 | 69266 | 86 | 0 | 0 / 0 / 0 |

- **Counts vs A3:** A3 had 1167 collected / 1082 tests / 68517 assertions / 85 incomplete. The difference is +18 collected: 6 unit tests, 11 contract tests and 1 lab test.
- **Incomplete:** 86 = 85 + `AccountingTimeLabTest`. The lab tests are **incomplete, never passed**, when the CHR is absent; they ran for real in §4.3.
- **"OK, but there were issues!"** refers to those incomplete tests. No PHP warning, deprecation, risky or skipped test appears in the output.
- **`failOnWarning`** is unchanged.
- **Variant registry reproducibility:** matches on both engines.
- **Frozen tree:** `tree-before.sha256` = `tree-after.sha256` over 2788 tracked and untracked files (the harness output and this report excluded): **TREE_UNCHANGED**. No harness container was left afterwards.

### 4.5 Security, manifest and protected files

| Check | Result |
|---|---|
| Manifest `docs/claude-development-pack/MANIFEST.sha256` | **42 / 42 OK** |
| Protected / out-of-scope paths (`git diff 2628c632 -- install/ init.php system/devices system/controllers Reseller/{Domain,Activation,Finance} CoAClient RadiusRepository RestCounter radius.php system/cron.php dev/ ui/ tests/phpunit.xml`) | **empty** |
| Secret sweep, A4 product, test and doc files and all of `evidence/a4/` | **0 hits.** Values: lab `secrets.env`, `snmp.env`, the non-address tokens of `radius-secrets/*`; plus private-key markers. **First pass flagged a false positive:** the IP `10.88.0.10`, the first field of the `nas_snmp` credential file, was tokenised as a value. Excluded as an address; the community itself is redacted in the console log |
| pcap | stays in `$NUXR_WP8_STATE/pcap` (not in the repository); only a decoded, attribute-level summary is in evidence |
| Local A5 plan | untracked, unchanged: sha256 `96f1aca7408ab278…` |
| Git | HEAD = origin = `2628c632`; nothing staged, committed or pushed |

## 5. Correctness when the PHP zone differs

### 5.1 `staleSessions()`: identical reports for every combination

For each DB session zone (`+00:00`, `+03:00`, `-05:00`), each PHP zone (`UTC`, `Asia/Baghdad`, `America/New_York`) and each zone of `$now`, the threshold-120 report is the same set:
- `cut-1` (121 s);
- `old`, `noupd-old`;
- `future-tol+1` (+301 s), `future-3h`;
- `no-times`, `zero` (`0000-00-00`), `pre1970`.

Never reported:
- `live` (30 s), `cut` (exactly 120 s), `cut+1`, `floor`;
- `noupd-live`, `future-tol` (+300 s), `closed`.

The 60 s floor holds (`floor+1` = 61 s reported, `floor` = 60 s not). `radacct` is byte-identical after every report.

### 5.2 `Subscriber::session()`: same instant for every combination

| Row | State |
|---|---|
| fresh / fresh-without-Interim | online |
| 3600 s old | stale |
| stopped | offline |
| +3600 s future | stale |
| no times / zero date | stale (no time displayed) |

Every displayed time equals the true instant, formatted in the PHP zone with its offset. Re-parsing the label gives back the epoch. Five PHP zones show five different wall clocks for **one** instant.

## 6. DST and historical dates

**Named-zone tables:** present (MariaDB 1793 / MySQL 1795 names). The DST tests **ran** on both engines and nothing was skipped (OD-A4-6).

**`testDstTransitionsAreCharacterisedAndUtcHasNoAmbiguity`** (both engines, identical numbers):

| Zone | Repeated autumn hour | `UNIX_TIMESTAMP` back | Freshness misclassified | `acctinterval` true 3600 / true 60 | Counter edge: session before the period counted |
|---|---|---|---|---|---|
| Europe/Berlin | `02:30:00` for both `00:30Z` and `01:30Z` | both → the **first** (summer) instant | **1** (a 60 s old row read 3660 s old) | 3600 / **3660** | **1** |
| America/New_York | `01:30:00` for both | both → the first | **1** | 3600 / **3660** | **1** |
| UTC, `+00:00` | distinct | exact | 0 | 3600 / 60 | 0 |

- **Spring:** `FROM_UNIXTIME` never writes a time in the gap, so values written by FreeRADIUS round-trip exactly in both DST zones. A 60 s old row across the gap is fresh.
- **Display:** PHP prints the offset, so the repeated hour is unambiguous on screen (`02:30:00 UTC+02:00` vs `02:30:00 UTC+01:00`).
- **Limits (H-1 / H-2 PROVEN):** in a DST database zone the repeated hour is ambiguous in storage. That ambiguity cannot be removed in the application. **UTC removes it**, and UTC is the contract.

**Historical / mixed rows:**
- **Rows written under a former DB zone:** they read shifted by the difference (+10800 s in the test), and they are **never corrected or rewritten** (checksum).
- **Invalid values:** zero and pre-1970 dates read as invalid. Such a row is never online; it is reported, and no time is shown.

## 7. Performance

| | MariaDB | MySQL |
|---|---|---|
| radacct rows / open rows (test table) | 33 003 / 408 | 33 003 / 408 |
| Old plan | `ref`, key `acctstoptime`, rows 408 | `ref`, key `acctstoptime`, rows 408 |
| New plan (the exact SQL the product sent, captured) | **identical** | **identical** |
| Median of 7, old → new | 16.17 → 16.26 ms | 2.27 → 2.92 ms |

- **Plan:** no full scan, and the access path is unchanged. Asserted by `testTheStaleQueryKeepsTheAccessPathOfThePreviousQuery`.
- **Old query:** it was already non-sargable on the time columns (`COALESCE`). The time filter stays a residual predicate on the open rows.
- **Other reads:** `UNIX_TIMESTAMP` in the select list of `openSessions` / `lastSession` / `history` adds three scalar conversions per returned row (indexed by `username`).

## 8. Independent read-only review and disposition

**Reviewer:** a separate agent, read-only, given the plan, the diff, the tests and every consumer. **Verdict: DEFECTS_FOUND.**
- No defect in the product code under the approved contract.
- No protected file touched.
- The tests discriminate.

| # | Sev | Finding | Disposition |
|---|---|---|---|
| 1 | MEDIUM | The doc / test claim "a writer zone ahead always reads as future" was false: once accounting goes silent for about Δ, a mismatched row reads as fresh | **Fixed (wording + new characterising assertions).** The doc now says a mismatch **voids** the freshness guarantee. The test was renamed `…ShiftsEveryEpochExactlyAndVoidsTheFreshnessGuarantee` and **proves** the window: writer +03, reader UTC, silent 3 h 01 min → not reported, shown online (L-A4-2) |
| 2 | LOW | `CheckradGateTest` gate B is red until F-5 | **Done** after the lab proof; passes in gate mode (§4.3) |
| 3 | LOW | The Subscriber360 fixture used the PHP CLI zone | **Fixed:** the fixture uses the DB session `NOW()` |
| 4 | INFO | "Status unknown" text ("not updated recently") is also used for a future or invalid time; an invalid non-NULL stop time falls back to "Last seen" | Recorded (L-A4-4). The state is fail-safe; no template change in A4 |
| 5 | INFO | Plan deviations (unit test folder; RK-7 index sentence) | Recorded in §3 |
| 6 | INFO | Evidence was still incomplete at review time | Completed (§4) |

## 9. Files and Git state

| | |
|---|---|
| HEAD / origin | `2628c632…` / `2628c632…` (unchanged) |
| Modified (tracked) | `docs/WP4_RADIUS_MODEL.md`, `system/autoload/Reseller/Radius/AccountingService.php`, `system/autoload/Reseller/Ui/Subscriber.php`, `tests/Integration/Wp6a/Subscriber360Test.php`, `tests/Integration/Wp8/CheckradGateTest.php` |
| New (untracked) | `tests/Unit/Wp4/AccountingTimeUnitTest.php`, `tests/Integration/Wp4/AccountingTimeContractTest.php`, `tests/Integration/Wp8/AccountingTimeLabTest.php`, `owner-review/WP8/evidence/a4/`, this report, the A4 plan |
| Untouched (untracked) | `owner-review/WP8/WP8_A5_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md` |
| Pre-change backup | `/srv/phpnuxbill/wp8-a4-backup-20261013T180436Z` (6 files + `SHA256SUMS`) |
| Rollback | restore the two product files, the doc and the two modified tests from the backup and delete the three new tests. There is no data, schema or configuration state to undo |

## 10. Remaining risks and verdict

| ID | Residual | Severity | Handling |
|---|---|---|---|
| L-A4-1 | **DST database zone:** the repeated autumn hour is ambiguous in storage (freshness, `acctinterval`, accounting-on `acctsessiontime`, quota period edge), proven on both engines | MEDIUM if not UTC | **UTC production contract** (owner). Operational preflight in a later stage; no production change now |
| L-A4-2 | **Writer / reader session-zone mismatch, or a DB zone change while sessions are open:** every epoch is off by Δ and the freshness guarantee is void (a silent session can show online for a window). Rows written before a change stay shifted | MEDIUM if violated | Contract: same zone, never one-sided `time_zone`. Source-guarded for the code; deployment preflight later |
| L-A4-3 | **NAS clock:** RouterOS sends `Event-Timestamp`, so radacct times are the **NAS clock**. Skew shifts age; more than 300 s in the future → unknown | LOW–MEDIUM | NTP on the NAS (and every host) is a deployment requirement |
| L-A4-4 | The "Status unknown" text reads "not updated recently" also for a future or invalid time | INFO | Fail-safe state; wording follow-up optional |
| L-A4-5 | Display threshold 1800 s vs report threshold: during an accounting outage the admin view says online for up to 30 min while `staleSessions(…, 120)` already reports it (unchanged WP6A threshold) | INFO | Existing behaviour, documented |
| — | `Expiration` (FreeRADIUS process zone = application zone), RadiusRest reaper, OBS-1, earlier WP8 residuals | — | Unchanged, outside A4 |

**Verdict: `READY_FOR_OWNER_ACCEPTANCE`.**
- **Defects:** D-1, D-2 and D-3 are fixed and proven on both engines, in the real HTTP application and on the real CHR.
- **Contract:** the write / read time contract is proven, the four owner conditions included. DST and the zone mismatch are characterised and documented as deployment requirements.
- **Regressions:** none in A1 / A2-R / H1-A / H-2B / A3 / Option G.
- **Checks:** the full harness is green on both engines with the tree frozen; manifest, secret and protected-file checks are clean.
- **Not done:** nothing is committed or pushed. The commit waits for the owner's acceptance.

## 11. Addendum (2026-10-13): owner acceptance and pre-commit audit

**Owner decision:** `A4_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY`. One commit and one push to `wp8/integration-poc` are authorized after this audit.

### 11.1 Time contract points (owner §1): proven vs. not proven

| Point | Status | Evidence / where documented |
|---|---|---|
| The accounting SQL connections use one UTC contract (FreeRADIUS writer and PHP reader) | **Proven in development only** (harness, both engines; WP8 lab). The code does not force a zone: both sides use the server default, and a one-sided override is forbidden | §2. `WP4_RADIUS_MODEL.md` §4.1 |
| The **session** zone is checked, not only the global zone | **Application connection: verified directly.** Harness and lab show `@@global` = `@@session` = `SYSTEM`, `@@system_time_zone` = `UTC`, lab session offset 0. **FreeRADIUS writer: verified by round trip**, because its session variables cannot be read without a configuration change. Its stored text for a known epoch equals the reader's `FROM_UNIXTIME`, and the lab stored a real 18:48:18 UTC start as `18:48:18`. **Production: not verified** (no access, by rule) → P-A4-1 | §4.1 of the model doc; `evidence/a4/post/contract-*.jsonl` (`writer-reader`); `evidence/a4/lab/T-L-evidence.jsonl` (`env`, `live`) |
| The FreeRADIUS process zone for `Expiration` stays equal to the application zone and separate from SQL UTC | **Proven (lab):** FreeRADIUS `TZ=Asia/Baghdad` (`dev/wp8/compose.wp8.yml`) while its SQL session writes UTC. A4 did not touch `Expiration` | model doc §4.1 "Not the FreeRADIUS process zone", residual 4 |
| NTP is required on every NAS and server | **Documented as a requirement (P-A4-2).** Lab: CHR clock 1 s from the host, DB clock = PHP clock | model doc §4.1 "Clocks" |
| Event-Timestamp absent in some messages | **Proven:** RouterOS 7.24.5 PPPoE sends it in Start / Interim / Stop, **not in Accounting-On**, where FreeRADIUS uses `%l` for the close time and duration. **Not captured:** Hotspot accounting and other NAS models or firmware | `evidence/a4/lab/T-L4-event-timestamp.txt`; model doc §4.1 "Writer" |
| History stored under another zone needs a preflight and a separate compatibility / migration plan before production | **Documented (P-A4-3).** The shift is characterised by test (+10800 s); rows are never changed in place | model doc §4.1 "Operational preflight"; `OWNER_DECISIONS.md` A4 record |
| No production setting change, no change to old radacct rows | **Confirmed:** no production access; radacct checksums unchanged in every read test; the lab history is byte-identical | §4, §6 |

No new functional defect was found in this audit, and no code was changed after the final tests.

### 11.2 Pre-commit audit (owner §2)

| # | Check | Result |
|---|---|---|
| 1 | Branch / HEAD / origin | `wp8/integration-poc`, HEAD = origin = `2628c632…` |
| 2 | Changed / new files and diff | **Code and tests:** 2 product files, 2 modified tests, 3 new tests. **Documentation:** the model doc, `OWNER_DECISIONS.md` (append only), the A4 plan, this report, `evidence/a4/` |
| 3 | Files vs. the final test fingerprint | Every tracked and untracked file is byte-identical to `evidence/a4/harness/tree-after.sha256` (2788 files), which equals `tree-before`. **Changed after the fingerprint:** only documentation (this addendum, `docs/WP4_RADIUS_MODEL.md` §4.1 clarifications, `OWNER_DECISIONS.md`). No test reads these files, so the long runs were not repeated |
| 4 | Scope limited to A4 | `git diff 2628c632` is empty for `install/`, `init.php`, `radius.php`, `system/cron.php`, `system/devices`, `system/controllers`, `ui/`, `Reseller/{Domain,Activation,Finance}`, `CoAClient`, `RadiusRepository`, `RestCounter`, `dev/`, `tests/phpunit.xml` |
| 5 | MariaDB / MySQL / CHR numbers and exit codes | As in §4 (re-read from the original outputs) |
| 6 | Report and evidence | Reviewed. The aborted run 2 and the failed run 1 are kept |
| 7 | PHP-zone independence and DST limits | §5, §6 |
| 8 | Protected files, manifest, secrets, diff | See §11.3 |
| 9 | Local A5 plan | Untracked, sha256 `96f1aca7408ab278…`, not in the commit |
| 10 | `OWNER_DECISIONS.md` | A4 record appended; the 525 historical lines are byte-identical |

### 11.3 Final checks before the commit

| Check | Result |
|---|---|
| Manifest `docs/claude-development-pack/MANIFEST.sha256` | 42 / 42 OK |
| Protected / out-of-scope paths vs `2628c632` | empty diff (incl. `docs/claude-development-pack`) |
| `git diff --check`; CR / trailing whitespace in the new files | clean |
| Secret scan (lab secret values, non-address tokens of the RADIUS credential files, private-key and env-assignment patterns) over the A4 code, tests, docs, report, plan, `OWNER_DECISIONS.md` and `evidence/a4/` | **0 hits** |
| Evidence hygiene | 23 files, 868 KB; no pcap, image, disk or `.env` file (the pcap stays in `$NUXR_WP8_STATE`) |
| Original exit codes re-read | **run 3:** 38 × `exit=0`. **run 4:** 6 × `exit=0`. **Harness:** `run-tests exit=0`, `Tests: 1099, Assertions: 69266, Incomplete: 86.` per engine. **Gate B:** `gate_on_exit=0`, `phpunit_exit=0`, `gate_off_exit=0`. **Lab console:** every step `rc=0`. **`T-L-run1.out` and `lab-chr-focused.out`:** show `OK (1 test, 30 assertions)` / `OK (10 tests, 299 assertions)`; their exit codes (0) were printed by the invoking shell, not inside the files |
