# WP8 A4 — `R-WP8-CARRY-03` Preflight & Implementation Plan

| Item | Value |
|---|---|
| Date | 2026-10-13 |
| Stage | **A4 = R-WP8-CARRY-03** (Architecture Lock roadmap A4; backlog AUD-P0-05; gap GAP-05; risk R-009) |
| Round | **Planning and reading only.** No code, schema or configuration change; nothing on production; no commit, push, merge or deployment; A5, S7 and S8 not started; A3 not reopened |
| Status | `A4_PLAN_READY_FOR_OWNER_APPROVAL` |

## 1. Baseline (verified)

| Check | Result |
|---|---|
| Branch | `wp8/integration-poc` |
| Local HEAD | `2628c632123b65578ce29908aa33f27d7ccaae93` |
| `origin/wp8/integration-poc` (after `git fetch`) | `2628c632…` (identical) |
| Working tree | clean, except the owner's local A5 plan `owner-review/WP8/WP8_A5_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md`: **untracked, not touched** (sha256 `96f1aca7408ab278…`, unchanged) |

**Documents read:**
- Architecture Lock (local branch `docs/owner-architecture-lock-2026-10-09` @ `8b0ee336`):
  - `IMPLEMENTATION_ROADMAP.md` §A4;
  - `IMPROVEMENT_BACKLOG.md` AUD-P0-05;
  - `GAP_ANALYSIS.md` GAP-05;
  - `RISK_REGISTER.md` R-009;
  - `DOCUMENTATION_SYNC_REPORT.md` conflict C-05.
- `owner-review/WP8/OWNER_DECISIONS.md`:
  - OBS-W8-8 → CARRY-03, OD-W8-16 third round;
  - scope note C-09: `Reseller/Ui/Subscriber.php`.
- `owner-review/WP8/PLAN.md`.
- `owner-review/WP4/OWNER_DECISIONS.md` (residual 4).
- `docs/WP4_RADIUS_MODEL.md` §4 / §12.
- `evidence/od-w8-16-checkrad-gate/GATE_RESULTS.md` (OBS-W8-8).

**Code read:**

| Area | Files |
|---|---|
| Reseller code | `Reseller/Radius/AccountingService.php`, `Reseller/Ui/Subscriber.php`, `Reseller/Radius/RestCounter.php`, `Reseller/Radius/RadiusPolicyBuilder.php`, `Reseller/Activation/{ReconciliationService, ActivationOperatorService}.php` |
| Device and controllers | `system/devices/Radius.php`, `system/controllers/customers.php:430` + `ui/ui/admin/customers/view.tpl:164-178` |
| Entry points | `init.php:150,160`, `radius.php:207-212`, `system/cron.php:150` |
| Schema | `install/radius.sql` (`radacct`) |
| FreeRADIUS | `install/freeradius/{sites-available/phpnuxbill, mods-available/nuxr_counters, nuxr_*admission*.sql}` |
| Stock accounting queries | `queries.conf` from the pinned FreeRADIUS 3.2.8 image (read with `--network none`) |
| Lab and harness | `dev/wp8/compose.wp8.yml`, `dev/wp8/lab.sh` |
| Tests | `tests/Integration/Wp8/{CheckradGateTest, FailureDrillsTest}.php` |

**The task as recorded:** "FreeRADIUS writes radacct times with `FROM_UNIXTIME()` in the database session time zone; `AccountingService::staleSessions()` builds its cutoff from the PHP application clock; with different time zones a live session may be reported stale or a stale one missed." (OWNER_DECISIONS, R-WP8-CARRY-03.)

The Lock's required direction: "one tested accounting time contract, preferably UTC across FreeRADIUS SQL session / application interpretation".

## 2. Root-cause analysis

### 2.1 Time source per layer

| # | Layer | Time source | UTC or local? | Evidence |
|---|---|---|---|---|
| L1 | NAS (RouterOS) | `Event-Timestamp` (epoch), only if the NAS sends it | epoch (zone-free), **NAS clock** | stock `queries.conf:64`. RouterOS sends no `Event-Timestamp` in **Access**-Requests (A2-R evidence); for **Accounting**-Requests it is **unverified** (H-4) |
| L2 | FreeRADIUS | `%l` = request receive time (epoch), the fallback for `Event-Timestamp` | epoch, **FreeRADIUS host clock** | `queries.conf:64` `event_timestamp_epoch = "%{%{integer:Event-Timestamp}:-%l}"` |
| L3 | rlm_sql (writer) | `FROM_UNIXTIME(epoch)` for Start, Interim and Stop (`acctstarttime`, `acctupdatetime`, `acctstoptime`) | **local time of the SQL session's `time_zone`**. The stock module sets no session zone, so the DB server default applies (`SYSTEM` → the DB host or container zone) | `queries.conf:69, 360-361, 383-384, 409-410, 467-468, 484, 514-515, 578, 603-605`. No `time_zone` / `init_query` in `install/freeradius/mods-available/sql.example` or `dev/test/freeradius/sql` |
| L4 | Durations written by FreeRADIUS | `acctsessiontime = epoch − UNIX_TIMESTAMP(acctstarttime)` on Stop; `acctinterval = epoch − UNIX_TIMESTAMP(acctupdatetime_old)` on Interim | a round trip through the **same session zone** | `queries.conf:303-305, 483-486` |
| L5 | Storage | `radacct.acctstarttime / acctupdatetime / acctstoptime` are **`DATETIME`** | wall-clock text **without zone information** | `install/radius.sql:10-12` |
| L6 | FreeRADIUS process zone | `TZ` of the FreeRADIUS container (the lab sets `TZ: ${NUXR_WP8_TZ}` = Asia/Baghdad) | affects **`Expiration` parsing only** (WP4 residual 4), **not** radacct | `dev/wp8/compose.wp8.yml:35`; `docs/WP4_RADIUS_MODEL.md` §12.4 |
| L7 | Application (PHP) | `date_default_timezone_set($config['timezone'])`; `time()`, `new DateTimeImmutable('now')` | the application zone (lab: Asia/Baghdad) | `init.php:150`; `compose.wp8.yml:64` |
| L8 | Application RADIUS connection | `ORM 'radius'` with `SET NAMES utf8` only | the DB server default session zone (no override) | `init.php:160` |
| L9 | Lab DB containers | MariaDB / MySQL without `TZ` | `SYSTEM` = UTC | `compose.wp8.yml`, `dev/test/compose.yml` (no TZ). Proven in the lab (§2.3) |

### 2.2 The faulty comparisons

**D-1 — `AccountingService::staleSessions(\DateTimeImmutable $now, int $s)` (`AccountingService.php:93-97`)**
- The cutoff is `$now->modify(-$s)->format('Y-m-d H:i:s')`, formatted in **`$now`'s zone** (the application zone), and compared as a string with `COALESCE(acctupdatetime, acctstarttime)` (**DB-session local**).
- When the two zones differ by Δ = app offset − DB offset:

| Case | Effect |
|---|---|
| Δ > 0 (app ahead, e.g. Baghdad +3 vs UTC) | the cutoff is Δ too late, so **live sessions are reported stale** |
| Δ < 0 (app behind) | stale sessions are reported only Δ late, or **never** within the threshold |

**D-2 — `Reseller\Ui\Subscriber::session()` (`Subscriber.php:95-100`)**
- The code computes `$age = time() - strtotime($seen)`. `strtotime()` reads the DB-local string **as the application zone**, so the age is off by exactly Δ.
- `state = $age > 1800 ? 'stale' : 'online'`:

| Case | Effect |
|---|---|
| Δ ≥ +30 min | **every** open session shows "Status unknown" |
| Δ < 0 | a session that stopped updating shows "Online" for up to \|Δ\| + 30 min longer |

**D-3 — Display (`Subscriber.php:103` → `view.tpl:174-175`)**
- "Online since" and "Last seen" print the raw DB-local strings with no zone label. In the admin view they appear Δ off from the application clock.

### 2.3 Proven vs. to be tested

| ID | Claim | Status | Evidence / required test |
|---|---|---|---|
| P-1 | radacct times are DB-session local (`FROM_UNIXTIME`) | **PROVEN** | stock queries (§2.1 L3) and lab: `db_now` = UTC while the app is `+03` |
| P-2 | `staleSessions()` with the application clock lists live sessions as stale when Δ > 0 | **PROVEN (lab)** | OD-W8-16 gate B evidence `obs_w8_8_app_clock`: `app_now 2026-10-07 12:35:13 +03`, `db_now 09:35:13`. With the app clock it listed **[18, 19]** (stale **and** live); with the DB clock only **[18]** (`CheckradGateTest.php:222-241`) |
| P-3 | `Subscriber::session()` age and state are off by Δ | **PROVEN by code reading** (`strtotime` uses the PHP default zone). Not yet shown by a test | test T-I3 |
| P-4 | The displayed "Online since" / "Last seen" are DB-local | **PROVEN by code reading** | test T-I4 |
| P-5 | `staleSessions()` has **no production caller**; only lab tests call it | **PROVEN** (`grep` of the whole repository, `tests/` excluded) | — |
| P-6 | The quota counters (`nuxr_counters`) are consistent across zones | **PROVEN by reading**: `acctstarttime >= FROM_UNIXTIME(Nuxr-Period-Start)`, where `Nuxr-Period-Start` is an epoch (`RadiusPolicyBuilder.php:60`) and both conversions happen in FreeRADIUS's own SQL session. Exception: H-2 | — |
| P-7 | A2-R, H-2B and Option G are zone-independent | **PROVEN by reading**: `nuxr_*admission*` use `UTC_TIMESTAMP(6)` only; the age guards use `%l` / `%M` (FreeRADIUS clock); Option G decides by real NAS state, not row age | — |
| H-1 | A DB session zone **with DST** makes the wall-clock values ambiguous in the fall-back hour (`FROM_UNIXTIME` drops the offset). `UNIX_TIMESTAMP` of such a value can be 1 h off, which affects `acctsessiontime` / `acctinterval` (L4), freshness (D-1 / D-2) and quota period edges (P-6) | **HYPOTHESIS** (from documented MySQL / MariaDB semantics) | T-I6 with a named zone (`Europe/Berlin`) |
| H-2 | Quota edges under H-1 | HYPOTHESIS | T-I6 |
| H-3 | Clock skew between the PHP host, the FreeRADIUS host and the DB host shifts the age (independent of zone) | HYPOTHESIS / operational | documented requirement (NTP) |
| H-4 | RouterOS sends `Event-Timestamp` in Accounting-Requests (then the times come from the **NAS clock**) | **UNVERIFIED** | T-L4 (capture, informational) |
| H-5 | The application's RADIUS connection has the same session zone as FreeRADIUS's writer connection (both use the server default) | **true in the current code** (neither sets `time_zone`). It would break if an operator configured a zone on only one side | contract (§5) + source guard T-U4 |

### 2.4 Effect on decisions

- **Today, display and report only:**
  - D-1 has no production caller;
  - D-2 / D-3 drive the admin customer page only ("Online" / "Status unknown", "Online since", "Last seen").
  - No admission, activation, Disconnect, quota, finance or reversal decision reads these values. Admission is A2-R / H-2B with `UTC_TIMESTAMP` and Option G with the real NAS state; quota is the FreeRADIUS session; finance and activation use UTC application tables (`ActivationOperatorService.php:151` uses `UTC` explicitly).
- **Why it still blocks production (owner classification kept: MEDIUM, Lock R-009 HIGH):**
  - an operator acting on a wrong "Status unknown" / "Online" may disconnect or ignore a subscriber;
  - `staleSessions()` is the documented report for WP5A reconciliation and later S7 / S8 consumers (`WP4_RADIUS_MODEL.md` §4), so any future consumer would inherit the error;
  - H-1 can distort FreeRADIUS-computed durations in DST zones.

### 2.5 Rejected readings

| Reading | Why it is wrong |
|---|---|
| "Setting FreeRADIUS `TZ` fixes it" | No. FreeRADIUS's process zone does not affect `FROM_UNIXTIME` (DB session); it only serves `Expiration` (L6) |
| "Only `staleSessions()` is affected" | No: D-2 and D-3 in `Subscriber.php` (scope note C-09) |
| "The rlm_rest reaper is affected" | `RestCounter::record()` writes `dateAdded` with PHP `date()` (`radius.php:212`), and `reapStale()` compares with PHP `date()` (`cron.php:150`): the same clock on both sides, so it is **consistent**. It is rlm_rest (RadiusRest / L-4, outside the A4 scope). Residual: PHP-zone DST / zone change; see OD-A4-4 |

## 3. Matrix of affected paths

| Path | Reads | Decision type | Affected? | A4 action |
|---|---|---|---|---|
| `AccountingService::staleSessions()` | `acctupdatetime` / `acctstarttime` vs the PHP instant | report (no production caller) | **YES (D-1)** | fix |
| `Subscriber::session()` → `customers/view` | age + state + 3 timestamps | admin display | **YES (D-2, D-3)** | fix |
| `AccountingService::openSessions / history / lastSession / sessionCount / usage / openSessionUsernamesByIp` | rows / counts / sums, no time comparison | data | no (raw strings returned; only D-3 displays them) | epoch fields added (additive) |
| A3 old-session capture and confirmation (`Radius::change_username`, `confirmOldSessions`) | `openSessions` rows by `radacctid` | security (Disconnect targeting) | **no**: no time logic | none (must stay green) |
| `CoAClient` | rows → NAS id / session id | Disconnect / CoA | no | none |
| `nuxr_counters` (FreeRADIUS) | `acctstarttime` vs `FROM_UNIXTIME(period epoch)` in the same session | quota (security) | no (consistent), except H-2 under DST | test only (T-I6); no change |
| `nuxr_admission` / `nuxr_hs_admission` (A2-R / H-2B), H1-A age guard, Option G | `UTC_TIMESTAMP`, `%l` / `%M`, NAS state | admission (security) | **no** | regression only |
| `Expiration` (radcheck, app zone text) / `WISPr-Session-Terminate-Time` (has offset `P`) / `Session-Timeout` (seconds) | policy, not accounting | access end | `Expiration` depends on FreeRADIUS `TZ` = app zone (WP4 residual 4, Lock C-05) | **unchanged**; OD-A4-3 |
| rlm_rest `rad_acct` (`RestCounter`, `cron.php:150`) | PHP `date()` both sides | rlm_rest counters | consistent; DST / zone change edge | out of scope (OD-A4-4) |
| Activation / reconciliation staleness (`ReconciliationService`, `ActivationOperatorService`) | application tables, UTC | finance / activation | no | none |

## 4. Risks and severity

| ID | Risk | Severity | Note |
|---|---|---|---|
| RK-1 | A wrong freshness report or admin state leads an operator to a wrong action | MEDIUM (owner), HIGH (Lock R-009) | the reason for the production block |
| RK-2 | A future consumer of `staleSessions()` (WP5A / S7 / S8) inherits the error | HIGH if left open | closed by the fix + contract |
| RK-3 | DST ambiguity in a DST database zone (H-1 / H-2) | MEDIUM (if confirmed) | removed only by a UTC (or non-DST) database zone: a deployment requirement (OD-A4-2), not code |
| RK-4 | Mixed session zones between writer and reader (H-5) | MEDIUM | contract + guard; never set `time_zone` on only one side |
| RK-5 | Clock skew between hosts (H-3) | LOW–MEDIUM | NTP requirement (documented) |
| RK-6 | Regression of A3 / A2-R / H-2B / Option G | LOW | no change on those paths; full regression |
| RK-7 | Fix-induced: `UNIX_TIMESTAMP()` on a column disables an index for `staleSessions` | LOW | report path, already filtered by `acctstoptime IS NULL`. Stock `radacct` here has the primary key only (`install/radius.sql:116-117`) |

## 5. Smallest proposed fix

**Principle (the accounting time contract, proposed for owner approval):**
- radacct `DATETIME`s are wall-clock values in the RADIUS database's **session zone**. That zone is the server default, because neither FreeRADIUS nor the application sets one.
- The application **never interprets them as PHP-local strings**. It converts them to **epoch seconds in SQL** (`UNIX_TIMESTAMP(col)`), on a connection with the same session zone, and does every comparison and display from epochs. PHP formats epochs in the application zone, so DST is handled by PHP.
- **Recommended deployment zone:** UTC (OD-A4-2). It removes H-1.

**No** change to the following:
- the schema;
- radacct rows or history;
- FreeRADIUS configuration or queries (`install/freeradius/*`);
- `init.php`;
- A1 / A2-R / H1-A / H-2B / A3 / Option G;
- finance, activation or reversal.

| # | Change | File | Size |
|---|---|---|---|
| F-1 | `staleSessions()`: the cutoff becomes an **epoch**, `$now->getTimestamp() - max(60, $s)` (an instant, so the zone of `$now` no longer matters). SQL: `… AND UNIX_TIMESTAMP(COALESCE(acctupdatetime, acctstarttime)) < ?`. Signature unchanged | `AccountingService.php` | ~3 lines |
| F-2 | Additive epoch fields on every row read: `UNIX_TIMESTAMP(acctstarttime) AS acctstart_epoch, UNIX_TIMESTAMP(acctupdatetime) AS acctupdate_epoch, UNIX_TIMESTAMP(acctstoptime) AS acctstop_epoch` (existing keys unchanged) | `AccountingService.php` (`COLUMNS`) | 1 line |
| F-3 | `Subscriber::session()`: age = `time() - (acctupdate_epoch ?: acctstart_epoch)`. `started` / `last_update` / `stopped` are formatted from the epochs with `date('Y-m-d H:i:s', …)` in the application zone (template unchanged). A small pure helper for the state, so it can be unit-tested | `Reseller/Ui/Subscriber.php` | ~10 lines |
| F-4 | The accounting time contract and requirements (session zone; no `time_zone` override on one side only; UTC recommended; NTP; `Expiration` still requires FreeRADIUS `TZ` = app zone) | `docs/WP4_RADIUS_MODEL.md` §4 and §12 (not a protected file) | doc |
| F-5 | Test change after the proof (as OD-A3R-4): `CheckradGateTest` gate B stops feeding `staleSessions()` the "DB clock" workaround. After F-1, `new DateTimeImmutable(<DB NOW() string>)` would be a wrong instant. It uses the real now and asserts the discriminating result: the old row only, with the **application** clock | `tests/Integration/Wp8/CheckradGateTest.php:222-241` | ~5 lines |

**Alternatives considered (not recommended):**

| Alternative | Why not |
|---|---|
| (B) Force a UTC session in FreeRADIUS (init query) | edits the protected FreeRADIUS configuration; mixes zones in the **existing history** (old rows in the server zone, new rows UTC); breaks the counter period edges across the switch |
| (C) `SET time_zone` on the application's RADIUS connection | correct only if it equals the writer's zone; it would itself create RK-4 |
| (D) Convert in PHP using `@@session.time_zone` | `SYSTEM` has no zone name; fragile |
| (E) Add a UTC column / generated column | schema change, not needed |

## 6. Files

| File | Change |
|---|---|
| `system/autoload/Reseller/Radius/AccountingService.php` | F-1, F-2 |
| `system/autoload/Reseller/Ui/Subscriber.php` | F-3 |
| `docs/WP4_RADIUS_MODEL.md` | F-4 |
| `tests/Unit/Wp8/AccountingTimeUnitTest.php` (new) | T-U* |
| `tests/Integration/Wp4/AccountingTimeContractTest.php` (new) | T-I* |
| `tests/Integration/Wp8/CheckradGateTest.php` | F-5 (only after the lab proof) |
| `tests/Integration/Wp8/AccountingTimeLabTest.php` (new, lab-gated) | T-L* |
| `owner-review/WP8/…` | plan, results report, evidence |

Not touched: `install/`, `init.php`, FreeRADIUS configuration, `RestCounter` / `radius.php` / `cron.php`, A3 code, schema.

## 7. Test plan

All tests run on MariaDB 10.11 **and** MySQL 8.0. Incomplete or skipped tests are never counted as passes. Failed runs are kept as evidence.

**Unit (pure):**

| Test | Asserts |
|---|---|
| T-U1 | `Subscriber` state helper: online / stale at `threshold − 1`, `threshold`, `threshold + 1`; no update → start time used; stopped → offline |
| T-U2 | Epoch formatting in the application zone across a DST change (`Europe/Berlin` spring and autumn, `America/New_York`) and a non-DST zone (`Asia/Baghdad`) |
| T-U3 | `staleSessions` cutoff is the same instant for the same moment expressed in different zones (`UTC`, `+03:00`, `-05:00`) |
| T-U4 | Source guards: no `strtotime(` / `date(` parsing of radacct strings in `Subscriber::session`; `staleSessions` compares epochs; no `time_zone` set on the RADIUS connection (`init.php`) |

**Integration (harness DB, rows written as FreeRADIUS writes them: `FROM_UNIXTIME(epoch)` in a session with `SET time_zone = X`, read with the same session zone):**

| Test | Asserts |
|---|---|
| T-I1 | Matrix DB zone X ∈ {`+00:00`, `+03:00`, `-05:00`} × PHP zone ∈ {`UTC`, `Asia/Baghdad`, `America/New_York`}: a live row (updated 30 s ago) is **never** stale; a row 1 h old **is** stale; threshold edges ±1 s; the 60 s floor; `acctupdatetime` NULL → start time |
| T-I2 | **Accounting interruption:** Start, then no Interim → stale after the threshold; Interim resumes → fresh; Stop → excluded. History rows are never modified (checksum before/after) |
| T-I3 | `Subscriber::session()` in the same matrix: `online` for a fresh row, `stale` for an old row, independent of Δ (proves P-3 fixed) |
| T-I4 | Displayed times equal the true event instant in the application zone (proves P-4 fixed) |
| T-I5 | **Zone change:** change the application zone between write and read (`date_default_timezone_set`); results are invariant |
| T-I6 | **DST (characterization):** DB session zone `Europe/Berlin` (named zone; tz tables present in both official images; if they are missing the test **fails**, it is not skipped). Rows written inside the spring gap and the autumn repeated hour. Records the documented ambiguity (H-1 / H-2, incl. FreeRADIUS-style `acctsessiontime`), and proves that a UTC DB zone has none. It is the basis for OD-A4-2 |
| T-I7 | Regression: A3 suites (`RenameOldSessionTest`, `RenameRemediationTest` …), `RadiusRepositoryTest`, `FreeRadiusContractTest` (counters), `DriftReportTest` |

**Real lab (CHR, after Unit / Integration pass):**

| Test | Asserts |
|---|---|
| T-L1 | App zone Asia/Baghdad, DB UTC (the current lab): a live PPPoE session is not stale with the application clock; after accounting is blocked (udp 1813) past the threshold it is stale. `CheckradGateTest` gate B flipped (F-5) |
| T-L2 | `Subscriber::session()` for a live CHR session shows `online` and the correct local times |
| T-L3 | Regression: `FailureDrillsTest` drill 7 listing, `RealLifecycleTest`, `RenameOldSessionLabTest`, the Option G / A2-R / H-2B classes in `wp8-integration` |
| T-L4 | Informational: does RouterOS 7.24.5 send `Event-Timestamp` in Accounting-Requests? (FreeRADIUS debug log of one Start / Interim / Stop) |

**Then:** the full harness on both engines with the tree frozen; secret, manifest, diff and protected-branch checks.

## 8. Acceptance criteria and rollback

**Acceptance criteria:**
1. The same accounting moment gives the same freshness result and the same displayed instant for every combination of DB session zone and application zone in T-I1 / T-I3 / T-I4 / T-I5.
2. P-2 reversed in the real lab: the application clock reports only genuinely stale rows (T-L1).
3. No radacct row, schema, FreeRADIUS configuration or history is changed: radacct checksums before/after; `git diff` limited to §6.
4. A1, A2-R, H1-A, H-2B, A3 and Option G regression green on both engines and in the lab.
5. The DST behaviour is characterized (T-I6), and the contract is documented with the owner's decision on the deployment zone.
6. Real exit codes are reported; incomplete and skipped tests are not counted.

**Rollback:** revert the two product files (`AccountingService.php`, `Subscriber.php`) and the tests. There is no data, schema or configuration state to undo, and history is never written. A pre-change backup will be taken as in A3.

## 9. Decisions requiring the owner

| ID | Decision | Options | Recommendation |
|---|---|---|---|
| OD-A4-1 | Fix approach | A: epoch conversion in SQL (`UNIX_TIMESTAMP`) + epoch-based comparison and display (§5), no schema / config / FreeRADIUS change. B: force a UTC session in FreeRADIUS (rejected, §5) | **A** |
| OD-A4-2 | Canonical deployment zone of the RADIUS database (Lock: "preferably UTC") | A: document UTC as a **production requirement** (release gate; no code enforcement). B: A + a read-only runtime warning in an existing operator report when the session zone is not UTC / observes DST. C: no requirement | **A** (B as an optional follow-up) |
| OD-A4-3 | Lock conflict C-05 (`Expiration` parsed in FreeRADIUS local time, WP4 decision 4) | A: keep it as a **separate policy contract** (FreeRADIUS `TZ` = application zone), documented next to the accounting contract; A4 does not change it. B: extend A4 to `Expiration` (larger: FreeRADIUS / policy change) | **A** |
| OD-A4-4 | rlm_rest `rad_acct` reaper (consistent PHP clock on both sides; DST / zone-change edge) | A: out of A4 (RadiusRest / L-4), recorded. B: include | **A** |
| OD-A4-5 | Test change F-5 (`CheckradGateTest` gate B stops using the DB-clock workaround) | allowed after the lab proof, without weakening any assertion (as OD-A3R-4 / OD-A3R-14) | **approve** |
| OD-A4-6 | T-I6 hard-requires named time zones in the test DB containers; it fails, not skips, when they are missing | approve | **approve** |

**Next step after approval:** implementation and tests in the usual order (backup → code → focused tests on both engines → full harness frozen → CHR → security checks → report). No commit before acceptance.
