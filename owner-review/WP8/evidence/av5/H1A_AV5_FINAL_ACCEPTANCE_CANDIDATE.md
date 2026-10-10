# WP8 H1-A + AV-5 — Final Acceptance Candidate

Date: 2026-10-10. Development and lab only: RouterOS CHR 7.24.5 as a real NAS, FreeRADIUS 3.2.8 threaded, a
production-contract NAS row, and the harness on MariaDB 10.11 and MySQL 8.0.

What was not done:
- no commit, push, merge or production deployment;
- H-2, V-1 and A3 were not started;
- no subscriber or voucher data was changed;
- the production impact queries were not run.

**Verdict: `H1_A_READY_FOR_OWNER_ACCEPTANCE_WITH_NOTES`.**

Summary:
- **AV-5 is fixed with a one-clock age.**
  - Both A2-R age blocks now compute `%{expr:(%{time_since:us} - %l * 1000000 - %M) / 1000}`.
  - The request receive time (`%l`/`%M`) and the current time (`%{time_since:us}`) each come from one `gettimeofday()`.
  - There is no `+1000 ms` window, so no correction can push a sound request over the budget.
  - Fail-closed checks, Strict Mode and the 5000 ms budget are unchanged. AV-1 is **not** resolved by this fix.
- **H1-A is unchanged** and was re-verified on the real NAS after the AV-5 fix:
  - no subscriber exceeded `shared_users`;
  - there is no MAC exception;
  - the MAC impact is documented in the README.
- **Results:** no failure caused by the candidate:
  - **AV-5 boundary gate:** 240 evaluations; 0 negative, 0 outside the one-clock bracket, 0 wrong decisions. The
    pre-fix formula on the same lab: 9 false refusals and 1 silent underestimate.
  - **A2-R real-NAS 5000 ms boundary:** 3.5 / 4.5 s admitted and consumed by the NAS; 5.5 / 6.5 s refused and released.
  - **RealAaa:** 3/3 OK (exit 0).
  - **Security gates:** 10 tests / 216 assertions, exit 0; 48 race trials, 0 double sessions.
  - **Full WP8 suite:** 69 tests / 1203 assertions, **0 failures, 0 errors**. **Exit 1**, caused only by the 3
    pre-existing `Message.php` warnings under `failOnWarning` (identical to the accepted A2-R baseline).
- **The record is not all green; every failure is kept and reported:**
  - **One lab-case run failed** (`slow_guard_lost_stop`, 15:21Z): the A1 guard hit its 3 s timeout under the TCG lab,
    before any age computation. It passed on 4 re-runs.
  - **The full dual-engine harness exited 1 in both runs**, from a **pre-existing test-harness defect**
    (`tests/Support/AppServer.php`: orphaned `php -S` workers serve 404 on a reused random port, proven by a probe):
    4 `ErrorHandlingTest` issues on MariaDB (run 1) and 5 `FinanceCorrectionTest` errors on MySQL (run 2). Each passes
    in isolation on both engines.
  - **5 warnings** in harness run 1 came from **my own new test** (`HotspotEquivalenceGateTest::tearDown` called the lab
    agent with the lab down). They were fixed and are gone in run 2.

Evidence is in `owner-review/WP8/evidence/av5/`, listed in §12. Pcaps are in `$NUXR_WP8_STATE/pcap` and are not committed.

## 1. Baseline and backups

| Item | Value |
|---|---|
| Branch / base | `wp8/integration-poc` @ `5ea597d2b74d0f0b89c5bc7ef1f6eef172c3ced2` (= origin), plus the uncommitted H1-A changes |
| `feat` / `main` | `5bdc5745` / `500d3a2d` (unchanged) |
| Backup before this round | `/srv/phpnuxbill/wp8-av5-prefix-backup-20261010T143836Z` (0700): HEAD, status, the tracked diff and files, the untracked files, and the lab evidence. `SHA256SUMS` was verified when it was made and again at the end. |
| Earlier backups | `wp8-h1a-baseline-backup-20261010T094908Z`, `wp8-a2r-final-backup-20261010T063516Z`, the closure and prototype backups (untouched) |

No `reset`, `stash` or `clean` was used. Earlier evidence, including failed, invalid and corrected records, is kept unchanged.

## 2. AV-5: cause (re-verified on the FreeRADIUS 3.2.8 source and binary)

The source files are from the `release_3_2_8` tag (sha256 values in `freeradius-3.2.8-source-excerpts.txt`).

`src/main/xlat.c`:
- **`%l` / `%M`** are `request->packet->timestamp`: **one** `gettimeofday()` taken in `request_receive`
  (`process.c`). The request side is consistent.
- **`%C`** is a new `gettimeofday()` read, microseconds only.
- **`%c`** is `time(NULL)`. On Linux this is the kernel's coarse clock: it advances only on a tick, so for a few
  milliseconds after a second boundary it still shows the previous second.

The pre-fix formula `(%C - %M) / 1000 + (%c - %l) * 1000` therefore joined microseconds from the precise clock with
seconds from the coarse clock. In the lag window the age came out **1000 ms too small**:
- negative when the request was younger than 1 s → `reject:late` (the RealAaa failure of the H1-A round, age -990);
- positive but 1000 ms low when the request was older (a silent underestimate; demonstrated in §4.2).

## 3. The fix, and why it is the simplest safe one

**Why the "+1000 ms in the first 20 ms" proposal was not adopted (owner's concern confirmed).** That correction added
1000 ms whenever `%C` < 20000 µs, **whether or not** the coarse clock lagged:
- In that window without a lag (the deterministic probe case `window_nolag`: true age 5 ms, computed 1006 ms), the
  age is overestimated by 1000 ms.
- A sound request with a true age of 4.0–5.0 s evaluated in the first 20 ms of a second (about 2 % of such requests)
  would be refused as late.
- That is a false refusal on exactly the slow-verification path AV-4 exists to protect.

**What FreeRADIUS 3.2.8 offers.** The stock `rlm_date` module registers `%{time_since:BASE [n]}`
(`mods-available/date`, "time in microseconds since the epoch"). Its code (`rlm_date.c`, `xlat_time_since`) calls
**one** `gettimeofday()` and returns `tv_sec * 1000000 + tv_usec`. It is the same clock as `%l`/`%M`, read once.

**Change: two lines (plus comments) in `install/freeradius/sites-available/phpnuxbill`.** Both age blocks (A2-R step 5
before the claim, step 7 after it) become:

```
update request {
	&Tmp-String-6 := "%{expr:(%{time_since:us} - %l * 1000000 - %M) / 1000}"
}
if ((&Tmp-String-6 !~ /^[0-9]{1,9}$/) || (<integer>&Tmp-String-6 > 5000)) {   # unchanged
```

**Unchanged:**
- the check itself: negative, malformed or > 5000 ms → reject;
- the reject tags `reject:late` / `reject:late-claim`, the creator-only release, `attach_count = 0`;
- Strict Mode (a `PENDING` / `ACTIVE` claim is never released on time alone);
- the 5000 ms budget, H1-A, the Option G guard, the accounting SQL, Message-Authenticator;
- `install/radius.sql` and `system/`.

**Properties:**
- Both sides of the subtraction come from `gettimeofday()`, so there is no mixed-clock error in either direction.
- Integer division truncates. The age is the floor of the true age at evaluation (never above it), at most 1 ms below.
- If the wall clock is stepped (NTP), a backward step can only give a negative age (refused) and a forward step a
  larger one (refused when over 5000 ms). Both are the same fail-closed direction as before.

**Deployment dependency, and it fails closed.** `mods-enabled/date` is enabled by default in FreeRADIUS 3.2.8 (it is in
the stock image's `mods-enabled`; the harness and the lab use it unchanged). Without it FreeRADIUS **refuses to start**:
`freeradius -XC` exits 1 with `%{time_since:us} ^ Unknown module` (`date-module-config-check.txt`). It never runs with a
missing clock. The README install table and the late-claim section now state this.

**AV-1 is not solved by this fix.** FreeRADIUS still timestamps a packet when it reads it from the socket. A stall of
the whole process (the packet waiting in the kernel) is invisible to any formula. AV-1 stays the accepted, documented
Strict Mode limitation and an **open production risk** (owner decision D-AV1 = D1).

## 4. Boundary timing evidence

### 4.1 Micro-study on the real FreeRADIUS 3.2.8 binary (50,000 requests)

Setup:
- Image `freeradius/freeradius-server:3.2.8`, network none, a minimal virtual server (`micro-study-site.conf`).
- For each request:
  - the new and the old formula, each bracketed by a `%{time_since:us}` read just **before** and just **after** it;
  - the true age at the moment of the evaluation must lie inside that bracket.
- 250 sequential batches of 200 requests (radclient).

| | Rows | Malformed / negative | Outside the before/after bracket |
|---|---|---|---|
| **New formula** | 50,000 | **0** | **0** |
| Old formula | 50,000 | **28** (all ≈ −1000 ms, coarse lag) | 33: 28 that are 900–1001 ms too low, and 5 that are ≤ 1 ms high (truncation of a negative `%C − %M`) |

- 157 evaluations fell in the first 5 ms after a second boundary.
- The raw rows are in `micro-study-rows.txt.gz`; the analysis script is `micro-study-analyze.py`.
- A first, preliminary micro-study without the "before" read (50,000 rows: 24 old negatives, 0 new negatives, 10
  stall-affected reference reads) is kept in the lab state and superseded by this bracketed run.

### 4.2 Lab: the project site path, aimed at second boundaries (`h1_gate.py` case `av5_boundary`)

**Two lab-only site variants** (generated at run time, never written to `install/`):
- `av5hold` is the fixed project site plus instrumentation, active only for synthetic requests with
  `NAS-Identifier = nuxr-av5-*`:
  - a SQL hold of N ms before age block A (pre-claim) or block B (post-claim);
  - a `%{time_since:us}` read just before and just after each age block;
  - the coarse `%c` read right after, which shows whether `time()` lagged at that moment.
- `av5hold_pre` is the same site with the **pre-fix** formula, for contrast.

**Request series.** Synthetic PPPoE Access-Requests were sent from the FreeRADIUS network namespace, timed so that the
age block is evaluated at a planned offset from a second boundary:
- **sweep:** no hold, offsets −3 .. +6 ms, 91 requests;
- **edge:** holds of 4900 / 4980 / 5020 / 5100 ms before block A or B, evaluated 0.3 / 1.0 / 2.5 ms after a boundary
  (24 requests);
- **retx:** the identical packet re-sent at +3 s and +6 s, as RouterOS does, with holds 4500-A, 5500-A and 5500-B.

| Site | Attempts | Age evaluations | In first 5 ms after a boundary | `time()` lagging at that moment | Malformed / negative | Outside bracket | Below the hold | Wrong decision |
|---|---|---|---|---|---|---|---|---|
| **`av5hold` (fixed)** | 124 | 240 | 26 | 4 | **0** | **0** | **0** | **0** → **pass** |
| `av5hold_pre` (pre-fix) | 124 | 235 | 44 | 10 | **9** (−987 … −993 ms: false `reject:late` / `reject:late-claim`) | 12 | 10 | 0 (the decisions followed the wrong ages) → **fail** |

- **Silent underestimate demonstrated with the pre-fix formula.** Hold 4900 before A, block B evaluated 1.5 ms after a
  boundary:
  - the age was computed as **3911 ms**;
  - the true age, bracketed, was **4911 ms**.
  - The same lag at a true age of 5000–6000 ms would have been accepted past the budget.

**5000 ms edge with the fixed formula.** Format: hold → computed age(s) → reply.

| Hold | Block | Δ (ms) | Computed age(s) | Reply | Tag |
|---|---|---|---|---|---|
| 4900 | A | 0.3 / 1.0 / 2.5 | A 4917, 4914, 4948 | Accept ×3 | — |
| 4980 | A | 0.3 | A 4994 | Accept | — |
| 4980 | A | 1.0 | A 4991, B 5020 | Reject | `reject:late-claim` (released) |
| 4980 | A | 2.5 | A 5064 | Reject | `reject:late` |
| 5020 | A | all three | A 5028–5038 | Reject | `reject:late` |
| 5100 | A | all three | A 5109–5119 | Reject | `reject:late` |
| 4900 | B | all three | B 4917–4977 | Accept | — |
| 4980 | B | 0.3 | B 5008 | Reject | `reject:late-claim` |
| 4980 | B | 1.0 / 2.5 | B 4994 / 4991 | Accept | — |
| 5020 | B | all three | B 5035–5230 | Reject | `reject:late-claim`, claim `ENDED late_claim` |
| 5100 | B | all three | B 5113–5165 | Reject | `reject:late-claim`, claim `ENDED late_claim` |

- Every decision equals "computed age > 5000 ms".
- Every computed age lies inside its one-clock bracket.
- Every computed age is at least the hold.

**Retransmissions with the fixed formula** (the identical packet sent 3 times: 0 / +3 / +6 s):
- **Hold 4500-A:** Accept. Ages A 4516 and B 4519, one evaluation per block. The +6 s copy got the cached, identical
  Accept; the claim was ended by the Stop.
- **Hold 5500-A:** Reject `reject:late`. One evaluation (5567), no claim; identical replies.
- **Hold 5500-B:** Reject `reject:late-claim`. Ages 7 / 5509; the claim was released by its creator (`ENDED late_claim`).
- The in-flight duplicate was ignored by FreeRADIUS. No retransmission caused a second computation or a second claim.

### 4.3 Real NAS (CHR 7.24.5, real PPPoE client, RouterOS `/radius timeout=3s`)

The claim statement was held by a row lock inside FreeRADIUS. Ages are those the policy computed (blocks A, B).

| Case | Wire Access-Requests (ms) | Policy ages (ms) | Reply on the wire | NAS / claim |
|---|---|---|---|---|
| `late_budget_real` 3.5 s | 0, 3003.8 | 22 / **3502** | Accept at 3504.7 | session UP, NAS live 1, claim ACTIVE |
| `late_budget_real` 4.5 s | 0, 2997.2 | 14 / **4502** | Accept at 4503.7 | session UP, NAS live 1, claim ACTIVE |
| `late_budget_real` 5.5 s | 0, 3020.8 | 6 / **5501** | Reject at 5503.6 | FAILED, NAS live 0, claim `ENDED late_claim` |
| `late_claim_real` 6.5 s | 0, 3005.6, 6024.9 | 7 / **6511** | Reject at 6516.8 | FAILED, claim `ENDED late_claim`; the next login is admitted |
| `nas_deadline` 4.0 s / 7.0 s | 0, ~3000, ~6000 (same Id and authenticator) | — | 4.0 s: session UP; 7.0 s: refused | ACTIVE / PENDING (Strict Mode) |

The ages match the wire clock to within about 2 ms, through RouterOS's retransmissions at ~3 s and ~6 s.

## 5. A2-R regression on the fixed site (lab, real CHR + synthetic)

| Case(s) | Result |
|---|---|
| S-1 collision pre-check, G-CLASS basic / lost Start / Stop-without-Start / accounting retransmission / sequential tokens | pass |
| lost-Stop takeover, real replay (`G-RETX.real_replay`), `nas_deadline`, S-1 real spellings | pass |
| `late_budget_real` (5000 ms), `late_claim_real` | pass (run twice; the second run, with the age parser fixed, records the ages above) |
| `fr_restart_pending`, `verifier_faults` | pass |
| Fault site: `retx_synthetic`, `lifecycle_synthetic`, `sql_faults`, `sql_concurrency` (pool), `s1_synthetic` | pass |
| **`slow_guard_lost_stop`, 15:21Z** | **FAIL, kept** (see below) |
| `slow_guard_lost_stop` re-runs 15:27Z, 15:28Z, 15:28Z, 15:31Z | pass ×4 |

**The `slow_guard_lost_stop` failure is real for that run, and it is not caused by AV-5.**
- From the FreeRADIUS trace of request (60): the A1 guard ran 3.05 s (a 2.5 s lab sleep wrapper plus SNMP to the CHR,
  which runs under TCG emulation). That reached its 3 s module timeout ("Failed to read from child output"), so
  `guard = fail` gave an Access-Reject.
- This happened **before** either age block (no age was computed).
- In the re-runs the guard took 2.82–2.93 s. The lab case has only about 70–250 ms of margin to the guard timeout.
- A note record (`NOTE.G-LATE.slow_guard_lost_stop.1521Z`) is appended next to the failing record. The failing record
  is unchanged.

**Tooling correction.** `a2r_gate.fr_requests()` recognised only the pre-fix formula text. As a result the
`freeradius_age_ms` lists of the 15:14–15:29Z records are empty (their verdicts do not use them). The parser now
accepts both formulas, and the timing cases were re-run with ages (`NOTE.a2r_gate.age_parser`).

## 6. H1-A on the real NAS after the AV-5 fix (project site; no redesign, no MAC exception)

| Case | Result |
|---|---|
| `identity` | UPPER variant refused (nothing active, no accounting); the exact spelling logs in |
| `shared1` (limit 1) | exact+exact, exact+UPPER, UPPER+exact, UPPER+accent, voucher UPPER/lower, voucher exact+UPPER: **max live 1** |
| `shared2` (limit 2) | exact ×3 → 2 live, the third refused; three spellings → 1 live |
| `concurrent` | limit 1, two / same / three spellings: live 1 in every trial (5, 5, 3); limit 2, three spellings: 1 |
| `lost_accounting` | Start lost: live 1; Stop lost: relogin OK, live 1 |
| `reconnect`, `http_pap`, `cookie` | variant refused, exact OK; the cookie re-login sends the stored spelling → Accept, `login-by=cookie` |
| `negatives` | wrong password / unknown user / equivalent spelling: same page text "invalid username or password", Access-Reject, no attributes, 1001–1003 ms |
| `duplicate_identity` | both spellings refused, live 0 |
| `policy_unchanged` | rate VSA, Session-Timeout 3600 (quota), Port-Limit 2, WISPr, Start/Stop accounting unchanged |
| `synthetic_faults` (fault site) | SQL error at priming / at the identity query, unexpected result, two spellings: all Access-Reject without attributes |

**No Hotspot session ever exceeded `shared_users`.**

## 7. MAC impact (documented; no exception added, no data changed)

Same MAC, real CHR, the actual Access-Request. The request carries `User-Name = Calling-Station-Id =
"6E:59:58:28:E8:37"` (upper case with colons), CHAP, `Service-Type` Login-User, `NAS-Port-Type` 19.

| `mac-auth-mode` | Stored MAC | Before H1-A (5ea597d2, previous round) | With H1-A (this round, unchanged) |
|---|---|---|---|
| `mac-as-username-and-password` | lower case | Reject (CHAP password mismatch) | Reject (same reason) |
| `mac-as-username-and-password` | upper case | Accept | Accept (`nuxr-hotspot=exact`) |
| **`mac-as-username`** | **lower case** | **Accept** | **Reject (`reject:spelling`) — STOPS WORKING** |
| `mac-as-username` | upper case | Accept | Accept |

`install/freeradius/README.md` (section "Hotspot canonical spelling") now says this in bold:
- a Hotspot using `mac-as-username` with a MAC stored in lower case worked before and stops working with H1-A;
- the stored MAC must be in RouterOS form;
- there is no exception, and nothing rewrites stored usernames;
- query 4 of the impact SQL finds these subscribers.

## 8. Production impact queries (prepared for review only; NOT run on production)

`owner-review/WP8/evidence/h1a/PRODUCTION_IMPACT_READONLY.sql` (unchanged from the H1-A round, SELECT only):
1. Hotspot sessions in the last 90 days under a non-stored spelling;
2. the affected-subscriber count and its share;
3. ambiguous stored identities;
4. MAC-shaped usernames stored with lower-case letters;
5. Hotspot vouchers by letter case (application database).

For AV-5 the operator check is not SQL: confirm `mods-enabled/date` and that `freeradius -C` passes.

## 9. Full test results (actual counts and exit codes)

| Run | Result | Exit code |
|---|---|---|
| Source-contract unit tests (Admission, SessionGuard, HotspotSpelling), lab runner | OK (14 tests, 266 assertions) | 0 |
| AV-5 micro-study, FreeRADIUS 3.2.8 binary | 50,000 rows: new 0 bad / old 28 negative (§4.1) | — |
| `h1_gate.py run av5_boundary`, site `av5hold` (fixed) | **pass** (124 attempts, 240 evaluations) | 0 |
| `h1_gate.py run av5_boundary`, site `av5hold_pre` (pre-fix, contrast) | fail as expected (9 negative, 1 underestimate) | 0 |
| A2-R lab cases (19, project + fault site), 15:14–15:25Z | 18 pass, **1 FAIL** (`slow_guard_lost_stop`, guard timeout; §5) | 0 |
| `slow_guard_lost_stop` re-runs | 4/4 pass | 0 |
| `late_budget_real`, `late_claim_real`, `nas_deadline` re-run with ages | 3/3 pass | 0 |
| H1-A Hotspot / MAC lab cases (13 project + `synthetic_faults` on the fault site) | all pass (§6, §7) | 0 |
| `RealAaaTest` ×3 (16:03Z onwards) | OK, OK, OK (7 tests, 79 assertions each) | 0, 0, 0 |
| `wp8-security-gates` (A2 ×7, S-1, H-1 ×2), 16:03–16:39Z | **OK (10 tests, 216 assertions)**; A2: 48 race trials → 45 single, 3 no session, **0 double** | **0** |
| **`wp8-integration`** (full), 16:39–17:25Z | **Tests 69, assertions 1203, 0 failures, 0 errors**, 13 skipped, 3 warnings (`Message.php:13-15`, pre-existing), 1 deprecation | **1** (`failOnWarning`) |
| Harness run 1, full matrix, MariaDB (17:28Z) | Tests 980, assertions 67073, **3 errors + 1 failure** (`Http\ErrorHandlingTest`, HTTP 404), **5 warnings** (my test, fixed), 79 incomplete | **1** (whole run) |
| Harness run 1, MySQL | Tests 980, assertions 67198, 0 errors / failures, 5 warnings (same), 79 incomplete | (same run) |
| Harness focused `ErrorHandlingTest` + `HotspotEquivalenceGateTest`, both engines | OK (4 tests, 126 assertions; 2 incomplete = lab tests), no warnings | 0 |
| Harness run 2, full matrix, MariaDB (18:34Z) | **Tests 980, assertions 67197, 0 errors / failures / warnings**, 79 incomplete | **1** (whole run) |
| Harness run 2, MySQL | Tests 980, assertions 67096, **5 errors** (`Wp6b\FinanceCorrectionTest`, HTTP 404), 79 incomplete | (same run) |
| Harness focused `FinanceCorrectionTest`, both engines | OK (5 tests, 102 assertions) on each | 0 |

Notes on the harness:
- This round ran the **full default matrix** (980 tests per engine). The H1-A round's 283 / 5902 was the WP4 + WP5A +
  WP5B subset.
- The 79 incomplete tests are the lab-only tests, marked incomplete when the lab is down (by design).
- **The cause of the HTTP 404 errors is proven.** `AppServer` picks a random port in 20000–29999, runs `php -S` with
  `PHP_CLI_SERVER_WORKERS=4`, and `stop()` terminates only the parent.
- The probe in the test image (`harness-appserver-orphan-probe.*`): after stop and deletion of the docroot, the port
  still accepts and answers `HTTP/1.1 404 Not Found`. A later test class that draws a used port talks to that orphan;
  `start()` returns as soon as the port accepts.
- `tests/Support/` is identical to `5ea597d2` (`git diff` is empty), so the defect is pre-existing and independent of
  this candidate. It is not fixed here (out of scope).

## 10. Changed files (all uncommitted; diff against `5ea597d2`)

| File | Change in this round | Status |
|---|---|---|
| `install/freeradius/sites-available/phpnuxbill` | AV-5: both age expressions → `%{time_since:us}` (2 lines plus the comment); the H1-A block unchanged | modified |
| `install/freeradius/README.md` | `date` module row in the install table; one-clock age paragraph; the MAC `mac-as-username` warning made explicit | modified |
| `tests/Unit/Wp4/AdmissionSourceTest.php` | asserts the new expression twice, no other age computation, no `%c` / `%C` in the site code | modified |
| `dev/wp8/h1_gate.py` | lab: site modes `av5hold` / `av5hold_pre`, case `av5_boundary`; the rejected `av5` / `av5probe` modes are rebuilt on the pre-fix formula so their evidence stays reproducible | new (lab) |
| `dev/wp8/a2r_gate.py` | lab: the age log parser accepts both formulas | modified (lab) |
| `tests/Integration/Wp8/HotspotEquivalenceGateTest.php` | `tearDown` returns when the lab is down (it caused 5 harness warnings; no change with the lab up) | new in H1-A, fixed now |
| `owner-review/WP8/evidence/av5/` | this report and its evidence | new |
| From the H1-A round, unchanged | `tests/Unit/Wp4/{HotspotSpellingSourceTest,SessionGuardSourceTest}.php`, `tests/Integration/Wp4/HotspotSpellingContractTest.php`, `tests/phpunit.xml`, `dev/wp8/freeradius/start.sh`, `owner-review/WP8/evidence/{h1a,h1-feasibility}/` | — |

Not touched: `install/radius.sql`, `system/`, the 42 manifest-pinned pack files, VPN and voucher generation, the
accounting SQL, Port-Limit generation, Message-Authenticator, Strict Mode, and the 5000 ms budget.

## 11. Remaining blockers and notes

Nothing found blocks H1-A + AV-5 as a candidate. These notes stay open:
1. **AV-1 (open production risk, accepted for development only):** a whole-process stall is invisible to the age check;
   the result is orphan `PENDING` claims (refusals, never a second session). This fix does not change it.
2. **H-2 (cross-NAS Hotspot `shared_users`) and V-1 (VPN equivalence):** unverified. The test proposal is in
   `evidence/h1a/H2_V1_TEST_PROPOSAL.md`. Not started; both need assessment before the final WP8 closure and production.
3. **MAC-H1A:** lower-case stored MACs stop working with `mac-as-username`.
   - Documented in the README; there is no exception.
   - The production count is unknown until the read-only queries run (separate authorization).
   - How to treat the affected subscribers is an owner decision; no data was changed.
4. **Production impact queries:** prepared, not run.
5. **`date` module:** FreeRADIUS must keep `mods-enabled/date` (default). Without it the server refuses to start
   (fail-closed), so the operator must check this at deployment.
6. **WP8 suite exit code 1:** 0 failures, 0 errors. The exit 1 comes only from the 3 pre-existing `Message.php`
   warnings (`failOnWarning`), identical to the accepted A2-R baseline. It is not "green".
7. **Harness exit code 1 in both full runs:** the pre-existing `AppServer` orphan-server defect (proven). It hit a
   different HTTP test class each run; those classes pass in isolation on both engines. A separate test-infrastructure
   fix (for example: kill the worker process group, or refuse a port that already accepts before `start()`) is
   recommended and needs approval.
8. **Lab margin of `slow_guard_lost_stop`:** 1 failed run of 5. The A1 guard (2.5 s wrapper plus SNMP) reached its 3 s
   module timeout before the age check. It is availability-only and fail-closed, the same class as the documented
   AV-4 risk: a slow SNMP path near the guard timeout refuses a legitimate reconnect.
9. **Nothing is committed.** Commit / push only after owner acceptance (secret sweep repeated then).

## 12. Evidence index (`owner-review/WP8/evidence/av5/`)

| File | Content |
|---|---|
| `H1A_AV5_FINAL_ACCEPTANCE_CANDIDATE.md` | this report |
| `freeradius-3.2.8-source-excerpts.txt` | `rlm_date.c` (`time_since`), `xlat.c` (`%c`, `%C`, `%l`, `%M`), `process.c` (`request_receive`), release_3_2_8, with sha256 |
| `date-module-config-check.txt` | `freeradius -XC` with / without `mods-enabled/date` (exit 0 / exit 1 "Unknown module") |
| `micro-study-site.conf`, `micro-study-analyze.py`, `micro-study-summary.json`, `micro-study-rows.txt.gz`, `micro-study-freeradius-version.txt` | the 50,000-request bracketed micro-study |
| `lab-h1-av5-evidence.jsonl` | 16 records: `AV5.boundary` (fixed, pre-fix) and the H1-A / MAC cases of this round |
| `lab-a2r-regression.jsonl` | 29 records: the A2-R cases of this round, including the failed `slow_guard_lost_stop` and 2 NOTE records |
| `lab-phpunit-evidence.jsonl` | 139 records written by the lab PHPUnit runs (RealAaa, security gates, WP8 suite) |
| `lab-gate-console.out` | console output of every lab run |
| `phpunit-summaries.txt`, `harness-summaries.txt` | PHPUnit summaries and exit codes (lab and harness) |
| `harness-appserver-orphan-probe.php` / `.out` | the probe that proves the orphan-server mechanism |

Unchanged earlier evidence: `evidence/h1a/` (H1-A round, including `PRODUCTION_IMPACT_READONLY.sql`,
`H2_V1_TEST_PROPOSAL.md`, `av5-realaaa-request278.txt`) and `evidence/h1-feasibility/`. The raw micro-study rows (also
the preliminary run) are in `$NUXR_WP8_STATE/evidence/av5-micro/`.

**Final QA:**
- Secret sweep of every modified and untracked file (39, including all evidence): 4 lab secret values, **0 hits**; no secret-like
  patterns.
- Manifest **42/42 OK**. `install/radius.sql` and `system/` are unchanged against `5ea597d2`.
- The lab is down: links, netns, qemu, agent, containers and networks all 0.
- Backups: `SHA256SUMS` OK for this round's backup and the H1-A / A2-R-final backups.
- Git: `HEAD` = `origin/wp8/integration-poc` = `5ea597d2`. Remote `feat` = `5bdc5745`, `main` = `500d3a2d` (unchanged).
  No commit, push, merge, reset, stash or clean.

## 13. Final verdict

**`H1_A_READY_FOR_OWNER_ACCEPTANCE_WITH_NOTES`**

- **AV-5** is fixed with the simplest mechanism FreeRADIUS 3.2.8 offers: one `gettimeofday()` on each side of the
  subtraction, and no correction window.
  - Proven on the source, the binary (50,000 requests), the second-boundary lab gate (fixed vs pre-fix), the 5000 ms
    edges, retransmissions and the real NAS.
  - Fail-closed, Strict Mode and the 5000 ms budget are unchanged. AV-1 stays open.
- **H1-A** is unchanged and re-verified on the real NAS: no Hotspot session above `shared_users`. The MAC impact is
  documented; there is no exception and no data change.
- The open notes in §11 (AV-1, H-2, V-1, MAC decision, production queries, `date` dependency, the pre-existing WP8 /
  harness exit codes, lab margin) are for the owner's decision.
- Stopped before any commit, push, merge or deployment.
