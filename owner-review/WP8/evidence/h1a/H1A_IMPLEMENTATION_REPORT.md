# WP8 H-1 — H1-A Implementation & Security Acceptance Report

Date: 2026-10-10. Development and lab only (RouterOS CHR 7.24.5 real NAS, FreeRADIUS 3.2.8 threaded, production-contract
NAS row; harness on MariaDB 10.11 and MySQL 8.0).
- No commit, push, merge or deploy.
- Not started: an AV-5 hotfix, H-2, V-1, A3.

**Verdict: `H1_A_SECURITY_PASS_WITH_OPEN_FINDINGS`.**

Summary:
- **H1-A closes H-1 on the tested NAS.** In no H1-A run did a subscriber hold more live Hotspot sessions than
  `shared_users`. The stored spelling logs in normally, every SQL fault is fail-closed, and PPPoE / A2-R are unaffected.
- **This is not a final acceptance.** Two findings stay open:
  - **AV-5:** a pre-existing A2-R clock defect. It caused a real WP8 suite failure (RealAaa), so the suite is NOT green.
  - **MAC-H1A:** H1-A introduces a new refusal for lower-case MAC usernames in RouterOS `mac-as-username` mode.

Evidence is in `owner-review/WP8/evidence/h1a/`:
- `lab-evidence.jsonl` (33 records, including 3 correction records);
- `lab-a2r-regression.jsonl`, `lab-phpunit-evidence.jsonl`, `phpunit-summaries.txt`, `lab-gate-console.out`;
- `av5-realaaa-request278.txt`, `PRODUCTION_IMPACT_READONLY.sql`, `H2_V1_TEST_PROPOSAL.md`.

Pcaps are in `$NUXR_WP8_STATE/pcap` (not committed).

## 1. Baseline and backup

| Item | Value |
|---|---|
| Baseline | `wp8/integration-poc@5ea597d2b74d0f0b89c5bc7ef1f6eef172c3ced2` (= origin) |
| `feat` | `5bdc5745` (unchanged) |
| `main` | `500d3a2d` (unchanged) |
| Backup | `/srv/phpnuxbill/wp8-h1a-baseline-backup-20261010T094908Z` (0700; the H1-B tooling and evidence, including the invalid / corrected records; `SHA256SUMS` re-verified at the end: OK) |

No `reset`, `stash` or `clean` was used.

## 2. Root cause and exact fix

**Root cause (H-1).**
- `radcheck.username` is `utf8mb4_general_ci`, so every case or accent spelling authenticates.
- RouterOS keys the Hotspot session, its accounting and `Port-Limit` by the **typed** spelling, and it ignores a
  `User-Name` in the Access-Accept (H1-B gate, accepted).

**Fix (H1-A).** One block at the end of `post-auth` in `install/freeradius/sites-available/phpnuxbill`, after the whole
PPPoE branch, entered only when `Service-Type == Login-User && NAS-Port-Type == Wireless-802.11`:
1. **Prime the username:** `%{sql:SELECT '1'}` makes rlm_sql create the stock `SQL-User-Name` (the FreeRADIUS 3.2.8
   behaviour proven in the H1-B gate). If it is missing or the sentinel is not `1`, reject (`no-key`).
2. **Read the stored identity:** one read-only query returns `c=<count of byte-distinct stored spellings>;<LOWER(HEX(stored
   bytes))>` for the lookup key. The sentinel must match exactly `^c=1;[0-9a-f]+$`. Otherwise reject (`identity`): an
   SQL error, an empty or malformed result, 0 spellings, or **2+ byte-distinct stored spellings (ambiguous identity:
   no account is picked, nothing is merged)**.
3. **Compare the bytes:** `%{hex:&User-Name}` (the raw request User-Name bytes) must equal the stored hex. This is a
   byte comparison; no SQL collation is used for equality. Otherwise reject (`spelling`).
4. **Reject like a wrong password:** each reject runs `attr_filter.access_reject` with **no Reply-Message**. The reason
   exists only in `Tmp-String-0`, visible in the debug log; no password or secret is logged.

**Unchanged:**
- the request, the password, the `authorize` / `authenticate` / `session` sections, Message-Authenticator enforcement;
- the accounting SQL, Port-Limit generation, `Simultaneous-Use`;
- the PPPoE A1 / Option G / A2-R branch;
- VPN, voucher generation, `install/radius.sql`, `system/`.

No table and no service were added.

## 3. Changed files (round diff against `5ea597d2`, all uncommitted)

| File | Change |
|---|---|
| `install/freeradius/sites-available/phpnuxbill` | +60 lines: the Hotspot canonical-spelling block and the header note |
| `install/freeradius/README.md` | "Hotspot canonical spelling" section (problem, mechanism, compatibility, rollback); "Not covered" now cites H-2 / V-1 |
| `tests/Unit/Wp4/HotspotSpellingSourceTest.php` (new) | source contract: Hotspot-only, after PPPoE, priming, byte comparison, ambiguity, 3 rejects with no Reply-Message, read-only, authorize untouched |
| `tests/Unit/Wp4/SessionGuardSourceTest.php` | reject / `attr_filter` counts +3 (the H1-A branches); the PPPoE `Reply-Message` count is unchanged (14) |
| `tests/Unit/Wp4/AdmissionSourceTest.php` | the "no SQL after Class" check is bounded to the PPPoE branch, plus an assertion that the Hotspot block follows it (verification level kept) |
| `tests/Integration/Wp4/HotspotSpellingContractTest.php` (new) | harness, real FreeRADIUS, both engines (5 tests) |
| `tests/Integration/Wp8/HotspotEquivalenceGateTest.php` (new) + `tests/phpunit.xml` | real-CHR H-1 regression gate in `wp8-security-gates` (excluded from `wp8-integration`, like S-1) |
| `dev/wp8/h1_gate.py` (new, lab only) + `dev/wp8/freeradius/start.sh` (marker `wp8-h1-site`) | site modes `project` / `pre` / `h1b` / `fault` / `av5` / `av5probe`; the H-1, MAC and AV-5 cases |

## 4. Username identity verification

| Source | Before H1-A (site `pre`, typed `NUXR_…`, stored `nuxr_…`) | H1-A |
|---|---|---|
| RADIUS reply | Access-Accept | **Access-Reject** (`reject:spelling`) |
| `/ip hotspot active` | `NUXR_H1_ID_CAFE_16C2` | none |
| Accounting Start / Interim / Stop | under `NUXR_…` | none |
| The exact spelling afterwards, same client | — | LOGGED_IN, active user = the stored bytes |

Harness (both engines): each equivalent spelling (UPPER, first letter upper, `café`, `cafeß`) **authenticates**, proven
by the same spelling being admitted as PPPoE (S-1). Each is refused for Hotspot under CHAP and PAP. The exact spelling
gets the unchanged policy (Port-Limit, rate, Session-Timeout), with no `User-Name`, no `Class` and no reservation.

## 5. Duplicate canonical identity handling

Two byte-distinct stored accounts that collate equal (`nuxr_…_dup_…` and `NUXR_…_DUP_…`, the same password, Port-Limit
1), made on purpose:

| Site | Results | Live sessions |
|---|---|---|
| `pre` | LOGGED_IN, LOGGED_IN | **2** |
| H1-A | REFUSED, REFUSED | 0 |

The harness gives the same result on both engines. The fault site's `count` marker (forced `c=2`) is also refused. No
account is chosen and nothing is merged. The A2-R collision pre-check (production) stays the detection tool (read-only
query 3).

## 6. Real Hotspot test matrix (CHR 7.24.5, http-chap unless noted; `pre` = 5ea597d2 site, H1-A = project site)

| Case | `pre` (bypass baseline) | H1-A |
|---|---|---|
| UPPER login (identity) | admitted, typed name active | refused, nothing active |
| Exact login | admitted | admitted |
| HTTP-PAP: UPPER, then exact (limit 1) | 2 live | REFUSED, LOGGED_IN → 1 live |
| Cookie (`http-chap,cookie`): UPPER | login + cookie under UPPER; cookie re-login via RADIUS (UPPER) accepted | no login, no cookie |
| Cookie: exact | — | login, cookie under the exact name; cookie re-login via RADIUS accepted, active `login-by=cookie` |
| Logout / reconnect: UPPER → exact → UPPER | 3 logins, active name = typed each time | REFUSED, LOGGED_IN, REFUSED |
| Wrong password / unknown user / equivalent spelling | wrong pw and unknown: Reject; the variant **Accept** | all three: Access-Reject, **no reply attributes**, ~1001 ms (`reject_delay`), page text "invalid username or password" |
| Rate, quota, expiry, accounting (exact; quota Max-All-Session 3600 + Max-Data) | Mikrotik-Rate-Limit `256k/512k`, Session-Timeout `3600` (quota-capped), Port-Limit 2, WISPr end; Start + Stop under the exact name | **identical** attributes (the WISPr value is time-based) and identical accounting |
| SQL faults (synthetic, site `fault`): priming error / identity-query error / malformed result / two spellings | — | all Access-Reject with no attributes (`no-key` / `identity` / `identity` / `identity`); controls: exact → Accept, variant → Reject |

## 7. `shared_users` = 1 / 2 evidence

| Case | Limit | `pre` | H1-A |
|---|---|---|---|
| exact, exact | 1 | LOGGED_IN, REFUSED (1 live) | LOGGED_IN, REFUSED (1) |
| exact, UPPER | 1 | **2 live** | LOGGED_IN, REFUSED (1) |
| UPPER, exact | 1 | **2 live** | REFUSED, LOGGED_IN (1) |
| UPPER, accent | 1 | **2 live** | REFUSED, REFUSED (0) |
| mixed-case voucher typed UPPER, then lower | 1 | **2 live** | REFUSED, REFUSED (0) |
| voucher exact, then UPPER | 1 | — | LOGGED_IN, REFUSED (1) |
| exact ×3 | 2 | 2 live, 3rd refused | 2 live, 3rd refused |
| exact, UPPER, accent | 2 | **3 live** | LOGGED_IN, REFUSED, REFUSED (1) |
| Lost Accounting-Start: UPPER, then exact | 1 | **2 live** | REFUSED, LOGGED_IN (1) |
| Lost Accounting-Start: exact, UPPER, exact (3 clients) | 1 | **2 live** | LOGGED_IN, REFUSED, REFUSED (1) |
| Lost Accounting-Stop: exact logs out (Stop dropped), then exact re-logs in | 1 | admitted, 1 live | admitted, 1 live (the stale open `radacct` row is kept, pre-existing) |

**PHPUnit H-1 gate** (`HotspotEquivalenceGateTest`, production policy via `RadiusPolicyBuilder`):
- limit 1, trials exact→UPPER, UPPER→exact, exact→accent: max live 1, only the stored spelling ever live;
- limit 2, exact / UPPER / exact: 2 live, variant refused.

## 8. Concurrent session evidence (logins launched together from independent Hotspot clients)

| Case | Trials | `pre` live per trial | H1-A live per trial |
|---|---|---|---|
| limit 1, UPPER + exact (2 clients) | 5 | 2, 2, 2, 2, 2 | **1, 1, 1, 1, 1** |
| limit 1, same spelling (2 clients) | 5 | 1, 1, 1, 1, 1 | 1, 1, 1, 1, 1 |
| limit 1, UPPER + exact + accent (3 clients) | 3 | 3, 3, 3 | **1, 1, 1** |
| limit 2, exact + UPPER + accent (3 clients) | 3 | 3, 3, 3 | **1, 1, 1** |

No H1-A run ever exceeded `shared_users`, so there is **no SECURITY FAIL**. Note that RouterOS' own same-spelling check
held in all 10 same-spelling trials; this is limited evidence.

## 9. Voucher / Cookie / MAC compatibility

**Controlled MAC comparison** (`H1.mac_compare`, the same client MAC `6E:59:58:28:E8:37`, site `pre` vs H1-A, both
RouterOS MAC modes):

| mode / stored username | `pre` | H1-A | Reason |
|---|---|---|---|
| `mac-as-username-and-password` / `6e:59:…` (lower) | Reject | Reject | `chap: Password comparison failed` in both (password = the MAC as sent, upper case): **pre-existing** |
| `mac-as-username-and-password` / `6E:59:…` (UPPER) | Accept | Accept | `nuxr-hotspot=exact` |
| **`mac-as-username` / `6e:59:…` (lower)** | **Accept** (active `6E:…`) | **Reject** | **`nuxr-hotspot=reject:spelling`: a NEW refusal caused by H1-A** |
| `mac-as-username` / `6E:59:…` (UPPER) | Accept | Accept | exact |

**Request attributes** sent by RouterOS for MAC login (pcap):
- `User-Name` = `Calling-Station-Id` = the MAC in UPPER case with colons;
- `Service-Type` Login-User, `NAS-Port-Type` Wireless-802.11, CHAP-Challenge / CHAP-Password, NAS-Port-Id `ether4`,
  Called-Station-Id `nuxr_wp8_hs`, Mikrotik VSAs.

So it is a Hotspot request and H1-A applies. **Correction:** the H1-B report's statement that "MAC login is unchanged"
holds only for `mac-as-username-and-password`. The correction record is `CORRECTION.H1B_report.mac_statement`; the old
report text is kept.

**Effect on existing users:**
- Only subscribers whose username is a MAC stored with lower-case letters, on a NAS using `mac-as-username`, lose
  access.
- PHPNuxBill has no code that generates MAC usernames; operators would have typed them (`:` is allowed in usernames).
- Their number is unknown until the read-only query 4 is run.
- No MAC exception was added and no account generator was changed.

**Other users:**
- **Mixed-case vouchers:** must be typed exactly as issued (`rand` format); `up` / `low` in their case; `numbers`
  unaffected. Old vouchers are not changed or re-issued.
- **Existing usernames:** only the stored spelling logs in on the Hotspot; PPPoE is unchanged.
- **Stored Hotspot cookies:** a cookie created under a non-stored spelling is refused at re-login (RouterOS sends the
  typed spelling through RADIUS). Exact-spelling cookies keep working (verified).
- **Saved credentials:** a browser- or device-saved username in another case is refused until it is corrected.

## 10. Failure and negative tests

- **SQL errors at both H1-A queries, a malformed result, an ambiguous count:** Access-Reject (lab fault site, real
  FreeRADIUS).
- **Missing `SQL-User-Name` path:** covered by the `no-key` branch, through the priming SQL error.
- **Wrong password, unknown user, variant:** indistinguishable (§6). The harness asserts the same code and attributes
  and `reject_delay` ≥ 900 ms on both engines.
- **No fail-open found:** every negative path ended in Access-Reject or no session.

## 11. Regression results

| Run | Result | Exit code |
|---|---|---|
| Harness WP4 + WP5A + WP5B, MariaDB | **OK (283 tests, 5902 assertions)** | 0 |
| Harness, MySQL 8.0 | **OK (283 tests, 5902 assertions)** | 0 (same run) |
| Lab A2-R cases (collision pre-check, Class basic, lost-Stop takeover, real replay, S-1 real spellings, 5000 ms budget) | 6/6 pass | 0 |
| `wp8-security-gates` (A2 ×7, S-1, H-1 ×2), full rerun 11:59–12:37Z | **OK (10 tests, 216 assertions)**; A2: 48 race trials, 0 double sessions | **0** |
| `wp8-security-gates`, first run 11:09Z | **INTERRUPTED** (the agent session ended at ~11:43Z after 8 dots, no summary) — **not counted** | — |
| **`wp8-integration`** (full, 10:27–11:09Z) | **Tests 69, assertions 1199, 1 FAILURE, 0 errors, 13 skipped, 3 warnings, 1 deprecation** | **1** |
| `RealAaaTest` focused rerun (extra data only) | OK (7 tests, 79 assertions) | 0 |

- **The WP8 suite is NOT green.** The failure is
  `RealAaaTest::testPppoeSimultaneousUseAllowsOneSession` (line 105): the first PPPoE login was refused with
  `nuxr-admission=reject:late` and age **-990 ms** (AV-5, below).
- It is a real failure. The focused rerun passing does not change that; it shows the defect is intermittent.
- The 13 skips are the same lab-mode-exclusive tests as before (Checkrad gate ×10, Option G functional ×2, Drill 7
  baseline for NAS type `other` ×1).
- The 3 warnings (`Message.php:13-15`) and 1 deprecation are pre-existing (identical in the A1-round run).

### AV-5 — A2-R request-age clock consistency (independent finding, NOT H1-A)

**What happened.**
- The request (278) was received at 11:00:22.990.
- The pre-claim age was computed at 11:00:23.001021 (≈1.0 ms after a second boundary) as `-990`.
- The result: Access-Reject `reject:late` for a fresh, legitimate PPPoE login. It is fail-closed (no unsafe Accept),
  but it costs availability. The trace (no password lines) is `av5-realaaa-request278.txt`.

**Cause (verified).** The accepted formula is `%{expr:(%C - %M) / 1000 + (%c - %l) * 1000}`:
- `%C` = `gettimeofday()` microseconds and `%c` = `time(NULL)` are **two separate clock reads** (FreeRADIUS 3.2.8
  `xlat.c`).
- `time()` is the kernel's coarse clock: it can still show the previous second right after a boundary, even when
  `gettimeofday()` was read first.

Measured on this host (same kernel as the containers) over 30 s of paired reads:
- the coarse clock lagged in **29 of 30 seconds**, for up to **4628 µs** after the boundary (one tick);
- that is **0.054 %** of reads.

**Frequency.**
- This lab run: **1 negative of 929** age computations (both PPPoE checks); it hit RealAaa.
- Every login has 2 age checks, so about 0.05–0.1 % of PPPoE logins can be falsely refused. Each such user is refused
  once and re-dials.
- **Second, silent direction:** when the evaluated request is ≥ 1 s old, the same lag makes the age **under-estimate by
  1000 ms** (`raw = true − 1000`, not negative). A 5.0–6.0 s old request could pass the 5000 ms check in that window.
  The NAS still consumes such an Accept (abandons at ~9.3 s), so there is no orphan, but the check is not exact.

**Proposed minimal fix (NOT applied to the project site; it needs your approval).** In both age blocks, replace the one
expression with:

```
update request {
	&Tmp-String-6 := "%C %c"
}
if (&Tmp-String-6 =~ /^([0-9]{1,6}) ([0-9]{9,11})$/) {
	update request {
		&Tmp-String-6 := "%{expr:(%{1} - %M) / 1000 + (%{2} - %l) * 1000 + (999999 - %{1}) / 980000 * 1000}"
	}
}
```

How it works:
- **One expansion, read order fixed:** `%C` is read before `%c`.
- **Window correction:** `(999999 − C) / 980000` is 1 exactly when `%C` < 20000 µs (the first 20 ms of a second, which
  covers the measured ≤ 4.6 ms lag and a 100 Hz kernel's 10 ms). It then adds +1000 ms.
- **Result:** the computed age is **never below the true age**, never negative from the lag, and at most +1000 ms high
  in that window (fail-closed direction).
- **Unparsable input:** `Tmp-String-6` stays non-numeric, so the unchanged check rejects it.

**FreeRADIUS 3.2.8 compatibility proof:**
- **Deterministic probe (`AV5.probe`, site `av5probe`, real FreeRADIUS 3.2.8 unlang regex + rlm_expr):** all 7 cases
  matched the C-integer expected values and the decision:
  - lag (`C`=400 µs, `c`=`l`) → **6** (the current formula gives −994);
  - window without lag → 1006;
  - normal → 505;
  - late (5400) → Reject;
  - window edge 19999 µs → 5019 Reject; 20000 µs → 4020 Accept;
  - malformed → Reject.
- **Real traffic (site `av5`):** 161 synthetic PPPoE logins swept across second boundaries, 161 accepted, 0 negative
  ages. The current formula gave the same counts in that sweep, which did not hit the lag window. The per-request timing
  metrics of that sweep are invalid (`CORRECTION.AV5.age_study_timing`).

**Re-validation needed if approved:** unit source test (new formula), harness late-claim test, lab `late_budget_real`,
`late_claim_real`, `db_interruption_real`, AV-1 timeline, and the WP8 suite.

## 12. Production compatibility impact (READ-ONLY, not run)

`PRODUCTION_IMPACT_READONLY.sql` contains only SELECT statements, per ISP. It was syntax-checked on the lab databases
(MariaDB): queries 1–4 on the lab RADIUS database, and query 5 on a temporary scratch database (dropped). It covers:
1. Hotspot sessions in the last 90 days under a non-stored spelling, per subscriber.
2. Affected subscribers versus all Hotspot subscribers seen.
3. Ambiguous identities (byte-distinct, collation-equal stored names).
4. MAC-shaped usernames stored with lower-case letters (the MAC-H1A population).
5. Hotspot vouchers by code case (`mixed` / `upper` / `lower` / `no_letters`).

Running it on production requires your separate authorization.

## 13. H-2 and V-1 status

Both are **unverified**, recorded separately, and not part of H1-A (no changes for them):
- **H-2:** a possible cross-NAS Hotspot `shared_users` enforcement gap.
- **V-1:** a possible VPN username-equivalence bypass.

The separate test proposal is in `H2_V1_TEST_PROPOSAL.md`:
- H-2 needs a CHR2 Hotspot server and client;
- V-1 uses CHR2 as the L2TP client, with no host packages.

## 14. Cleanup and Git status

**Lab.** `lab.sh down` reports qemu 0, netns 0, links 0, agent 0, containers 0, networks 0.
- Before shutdown, the site was `project` and the Hotspot profile `login-by=http-chap`.
- No cookie jar, site file, `pppd.opts` or work images remain.
- This round's SNMP credential files were deleted. CHR2 was not used.

**Secrets.** Sweep of 21 changed or new files against the 3 lab secrets, the disclosed community and key / community
patterns: **0 hits**. No random test password appears in the evidence. The disposable test voucher code (also its
password) appears as a username; that lab identity was deleted.

**Integrity.** Manifest **42/42**. `install/radius.sql` and `system/`: 0 lines changed.

**Git.**

| Ref | Value |
|---|---|
| HEAD | `5ea597d2` (= origin) |
| `feat` | `5bdc5745` (unchanged) |
| `main` | `500d3a2d` (unchanged) |
| Uncommitted | 6 modified, 15 new (H1-B feasibility evidence and tooling, H1-A code, tests, evidence) |

Everything is inside the approved scope except two lab-only items:
- the `av5` / `av5probe` site modes in `dev/wp8/h1_gate.py`;
- the AV-5 study cases.

These are investigation tooling, as requested; the project A2-R policy is unchanged.

## 15. Final verdict

H1-A passes every H-1 security gate on the real NAS:
- equivalent spellings never opened an extra session, including concurrently and with accounting lost;
- the stored spelling works;
- `shared_users` 1 / 2 behave as expected;
- refusals look like a wrong password;
- no fail-open on SQL errors;
- PPPoE / A2-R / S-1 / Option G regressions pass.

It is **not** a final acceptance, because:
1. **AV-5** (A2-R age clock, an availability finding independent of H1-A) left a real failure in the WP8 suite (exit
   code 1, 1 failure). The fix is proposed and proven compatible, but not applied.
2. **MAC-H1A:** H1-A newly refuses lower-case MAC usernames under `mac-as-username`. This needs your decision; the
   options are to accept it with a migration to upper-case MAC names after the read-only query, or another approved
   approach. No exception was added.

**`H1_A_SECURITY_PASS_WITH_OPEN_FINDINGS`**

No commit or push. No AV-5 hotfix, H-2, V-1 or A3 started.
