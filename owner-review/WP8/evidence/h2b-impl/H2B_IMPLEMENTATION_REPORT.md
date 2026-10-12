# WP8 H-2B — Implementation & Security Acceptance Candidate

**Verdict: `H2B_IMPLEMENTATION_READY_FOR_OWNER_ACCEPTANCE`** - with the documented Strict Mode availability limitations (§11) and open production blockers (§12).

| Item | Value |
|---|---|
| Date | 2026-10-11 |
| Baseline | `wp8/integration-poc@1537775555b3a940e32c8103df4c98bb0549ddf1` = origin; uncommitted |
| Lab | FreeRADIUS 3.2.8 (threaded); RouterOS CHR 7.24.5 ×2 (CHR1 10.88.0.2, CHR2 10.88.0.3); MariaDB 10.11.19; MySQL 8.0.46 for the SQL stress |
| Backup before the change | `/srv/phpnuxbill/wp8-h2b-impl-prefix-backup-20261011T112613Z` (33 files, SHA256SUMS verified) |
| Evidence | this folder: `lab-evidence-h2b.jsonl` (67), `lab-regression-h1a.jsonl` (30), `lab-regression-a2r.jsonl` (55), `lab-phpunit-*`, `lab-console.out`, `frlog-excerpt-empty-expansion.txt`, `harness-*.out` |
| Restrictions | No commit, push, merge or deploy. No production change or data. No V-1, no A3. `install/radius.sql` and `system/` unchanged. **No executable PENDING release.** |

**Prior evidence kept unchanged:**
- `../h2/`, `../h2b/` and `../h2b-pending/`.
- Every failure from this round is kept and none is counted as a pass (§9.4).

---

## 1. Modified files

**New, product (`install/freeradius/`):**

| File | Purpose |
|---|---|
| `nuxr_hs_admission.sql` | Migration, additive and idempotent. Tables `nuxr_hs_admission` and `nuxr_hs_admission_audit`. |
| `nuxr_hs_admission_check.sql` | Read-only readiness check. Prints `nuxr_hs_admission=READY`. |
| `nuxr_hs_admission_report.sql` | Read-only monitoring report. `ALERT pending_over_1_minute`, `ALERT orphan_accounting`, INFO lines. |
| `bin/nuxr_hotspot_slot_verifier` | Read-only SNMP Hotspot session verifier (POSIX sh, 264 lines). |
| `mods-available/nuxr_hotspot_slot_verifier` | Named exec module for the verifier. |

**Changed, product:**

| File | Change |
|---|---|
| `sites-available/phpnuxbill` | +342 / −4 lines; see the breakdown below. |
| `README.md` | Install table, new section "Hotspot admission slots (WP8 H-2B)", corrected "Hotspot" and "Not covered" paragraphs. |
| `docs/WP4_RADIUS_MODEL.md` | H-2B addendum. |

What changed in `sites-available/phpnuxbill`:
- **Post-auth:** a Hotspot block placed **after** the H1-A `exact` tag.
- **Accounting:** a `nuxh1` block.
- **`session {}`:** the stock check is skipped for Hotspot only.
- **Header:** a comment.
- **Untouched:** the A2-R and H1-A blocks are byte-identical. The only removed lines are 2 header-comment lines and `session { # …; sql }`, which is re-added under `else`.

**Tests:**

| File | Change |
|---|---|
| `tests/Unit/Wp4/HotspotAdmissionSourceTest.php` | New. 9 tests, source guards, mutation-checked. |
| `tests/Integration/Wp4/HotspotAdmissionContractTest.php` | New. 12 tests on the real harness FreeRADIUS, both engines. |
| `tests/Support/RadiusWire.php` | `hotspotAccess()`, `hotspotAccounting()`, `hotspotToken()`. |
| `HotspotSpellingContractTest`, `AdmissionReservationContractTest`, `SessionGuardContractTest` | Load the new schema. Send RouterOS-shaped Hotspot requests with Acct-Session-Id and Calling-Station-Id. Expect the `nuxh1` Class. |
| `AdmissionSourceTest`, `HotspotSpellingSourceTest`, `SessionGuardSourceTest` | Whole-section counts scoped to the block each test protects. Same guarantees; H-2B counted separately. |
| `Wp8/PortLimitGateTest` | The Hotspot Port-Limit gate now keeps the limit at 99 instead of deleting it, because a Hotspot login without a limit is now refused. |
| `Wp8/RealAaaTest` | The second Hotspot device is now refused by FreeRADIUS. Before H-2B it was refused by the RouterOS Port-Limit. |
| `Wp8/OptionGGateTest` | `guard_ran` now identifies the PPPoE guard by name. The Hotspot verifier is recorded separately. |

**Lab tooling:**
- `dev/test/freeradius/start.sh` and `dev/wp8/freeradius/start.sh` install the verifier and the module, as the README describes.
- `dev/wp8/lab.sh` loads and checks the new schema.
- `dev/wp8/h1_gate.py` takes the post-auth Hotspot block from the last occurrence, and its feasibility modes that no longer apply are tolerated.
- `dev/wp8/h2b_gate.py`: new `hold` overlay and implementation cases.

---

## 2. Database model

**`nuxr_hs_admission`: one row per (canonical subscriber, slot).**
- **Primary key:** `(username utf8mb4_bin, slot)`.
- **Indexes:** `UNIQUE(token)`, `KEY(nas, state)`.
- **Canonical subscriber:** the exact `radcheck.username` bytes. H1-A guarantees the request used exactly those bytes.
- **States:** `PENDING` (claimed, Accept sent or uncertain), `ACTIVE` (accounting with the token arrived), `ENDED`.
- **Identity of a claim:** token (128-bit, Class `nuxh1`), NAS, Acct-Session-Id, Calling-Station-Id, Request Authenticator, `session_user`, `attach_count`.
- **Timestamps:** UTC `datetime(6)`.

**CHECK constraints (lab finding §9.3).**
- `ck_nuxr_hs_admission_token`: the token is always 32 lower-case hex digits, and `username <> ''`.
- `ck_nuxr_hs_admission_claim`: every non-ENDED slot carries a dotted IPv4 NAS, a non-empty Acct-Session-Id and Calling-Station-Id, a 32-hex authenticator and a `session_user`.
- Enforced by MariaDB ≥ 10.2 and MySQL ≥ 8.0.16. Verified on both engines.

**`nuxr_hs_admission_audit`: append-only, written by FreeRADIUS.**
- `verified_release`: written before every automatic ACTIVE release, with the verifier's evidence and the requesting claim.
- `orphan_accounting`: a Start or Interim-Update whose token holds no live slot (an alarm).

**Migration.**
- Only `CREATE TABLE IF NOT EXISTS`. No ALTER, DROP or DELETE.
- Re-running it is harmless and keeps data (harness test on both engines, lab DB twice).
- `install/radius.sql` is unchanged.
- **Rollback:** the previous site file, plus removing the module from `mods-enabled`, then restart. The tables may stay. The README has the install and rollback order.
- **Grants:** `SELECT, INSERT, UPDATE` on `nuxr_hs_admission`; `SELECT, INSERT` on the audit table; no DELETE.

## 3. Claim v2

The claim is the one accepted in the feasibility gate (no COUNT subquery inside the UPDATE), with two hardenings from this round.

**The steps, in order:**
1. Identity check: attempt identity and a strict MAC (review L6); `Simultaneous-Use` present and 1..99 (review L1).
2. Age ≤ 5000 ms (AV-5 clock).
3. Slots: count first. Only if some are missing, `INSERT IGNORE` them in slot order, then count again. The **count**, never the INSERT result, must equal the limit (review L2 and L8).
4. Token: `randstr`, 32 hex.
5. **Retransmission:** the same NAS, Acct-Session-Id, MAC and Request Authenticator attach to the PENDING claim (`attach_count + 1`).
6. **Pre-check (non-locking):** fewer than `limit` slots in use **and no live slot above the limit**.
   - Key and limit are read from **one parameter row** and echoed (`free/<keyhex>/<limit>`).
   - Unlang compares the echo exactly (lab finding §9.3).
7. **Claim:** `UPDATE … SET state='PENDING', token=… WHERE username=K AND slot<=L AND state='ENDED' ORDER BY slot LIMIT 1`. The result must be exactly `hs-claim=1`.
8. Only if no slot was free: the verifier path (§5). Then steps 6–7 once more.
9. **Late-claim guard:** if the age is now > 5000 ms, the creator ends its own claim (own token, `PENDING`, `attach_count=0`), then reject.
10. `Class := 0x6e75786831 + token`. No SQL after this.

**Why "no live slot above the limit" (review M1).**
- After a decrease, slots above the new limit are outside the row-lock bound.
- Two concurrent logins could otherwise both pass the pre-check and claim two free slots, leaving 3 live under limit 2.
- With the extra condition, only requests that read the **same** limit compete, and they are bounded by the slot rows.
- Residual window: a request already in flight with the **old** higher limit at the moment of the decrease (documented).

**A claim result that is not the exact sentinel (review M3).**
- Example: a claim that committed but whose result was lost.
- The final refusal runs the creator release (`claim_failed`, own token, `PENDING`, `attach_count=0`).
- It is safe because no Access-Accept with that token was ever sent.

## 4. Strict PENDING safety

**The only transitions a slot can make:**

| From → to | Allowed only by |
|---|---|
| ENDED → PENDING | Claim v2 |
| PENDING → ACTIVE | Accounting Start or Interim with the same token, NAS, session and MAC |
| PENDING/ACTIVE → ENDED | Accounting Stop with the same four values |
| PENDING → ENDED | The creating request itself, **before any reply**: `late_claim` or `claim_failed` (own token, `PENDING`, `attach_count=0`) |
| ACTIVE → ENDED | The verifier on SNMP proof (§5): audit row first, then the release, both conditional on token and state ACTIVE |
| Anything else | Never: no time, no SNMP for PENDING, no other request, no operator tool |

**Proof:**
- **Source guards (`HotspotAdmissionSourceTest`):**
  - exactly three `SET state='ENDED'` in post-auth, each conditioned as above;
  - no `INTERVAL`, `TIMESTAMPDIFF` or `created_at <` anywhere in the slot policy;
  - verifier candidates `state='ACTIVE'` only.
- **Mutation check:** six mutations of the site make the guards fail:
  - verifier releasing PENDING;
  - PENDING as a verifier candidate;
  - time-based take-over;
  - bypass for every request;
  - pre-check without the above-limit condition;
  - `claim_failed` without the token, state and attach conditions.
- **Harness** (`testStrictModeAnOldPendingSlotIsNeverReleased`): a PENDING slot back-dated by **one day** still refuses logins from two NAS, and no audit row appears.

**Lab, real NAS (`hold` overlay; final-site run 2, 14:13–14:20Z). CHR1 holds a claim for 22 s; CHR2 logs in at 17 s.**

| Stall point | CHR1 `/radius timeout` | CHR2 login | Stalled CHR1 request | Max live | Slot after |
|---|---|---|---|---|---|
| After the last age check | 3s (NAS window ~9 s) | refused | Accept on the wire at 22,078 ms, **discarded** by CHR1 | 0 | PENDING; at 66 s still PENDING, CHR2 still refused, report `ALERT pending_over_1_minute` |
| After the last age check | 10s (~30 s) | refused | Accept at 22,016 ms **taken** → session | 1 | ACTIVE (its Start) |
| Before the last age check | 3s | refused | Access-Reject at 22,022 ms (`late-claim`) | 0 | ENDED `late_claim` |
| Before the last age check | 10s | refused | Access-Reject at 22,023 ms | 0 | ENDED `late_claim` |

**More stall evidence:**
- **Verifier never touches a PENDING slot** (limit 2, CHR1 timeout 10s):
  - Slot 1 is a stale ACTIVE (its Stop lost); slot 2 is a held PENDING.
  - The CHR2 login is admitted: the verifier proved only slot 1 gone (1 audit row).
  - The held request's late Accept is then taken. Live = 2 = limit.
- **P0, NAS deadline:** Accepts held 2/6.5/8/8.7 s are taken; 9.4/11/14 s are discarded, with valid authenticators and no accounting.
- **P2, concurrency during a hold:** 1 of 4 admitted, max live 1.
- **P3, retransmission during a hold:** one claim; FreeRADIUS discards the in-flight duplicates, and a copy after the reply gets the cached identical reply.

## 5. SNMP verification (`nuxr_hotspot_slot_verifier`)

**When it runs:**
- Only when a Hotspot login finds no free slot.
- Only for the subscriber's **ACTIVE** slots: at most 12, least recent accounting first.
- Arguments: `USERNAME_HEX` and `token/nas/mac` triples. No secret is ever on the command line.

**Per NAS, in parallel:**
1. **Health probe:** `sysUpTime`, `sysObjectID` (must be MikroTik `.1.3.6.1.4.1.14988.1`) and `mtxrLicVersion` (must be `7.x`).
2. **Two walks** of the MIKROTIK-MIB Hotspot active table: column 3 (user) and column 6 (MAC).
   - The two columns must carry the same session indexes; otherwise ERROR.
   - The comparison is on hex strings.
   - "No Such Object" counts as an empty table only on a validated agent. RouterOS gives the same answer for an empty table and for a missing table, which was measured.

**Decision:**
- **GONE** (exit 0, plus the token): the first slot whose NAS answered and shows no session with the same user bytes **and** the same MAC.
- **LIVE** (exit 1): every candidate's session is still there.
- **ERROR** (exit 2): anything else.
- FreeRADIUS releases only on `ok`, GONE and a 32-hex token. Each one is audited.
- Timing: wall-clock deadline 1.5 s; module timeout 3 s (review L4).

**Credentials:**
- The existing 0600 `nas_snmp` file of the A1 guard is read once and written to a private per-run `snmp.conf`.
- It is never logged and never on argv.
- `checkrad` is not used.

**Lab results (final site, run 2): a CHR1 session whose Stop was lost, then CHR2 tries to log in.**

| Case | CHR2 login | Slot | Access-Request → reply |
|---|---|---|---|
| SNMP disabled on CHR1 | refused | kept ACTIVE | 1188 ms |
| Wrong community | refused | kept | 1109 ms |
| No credential entry | refused | kept | 1002 ms |
| Credential file missing | refused | kept | 1002 ms |
| Verifier program missing | refused | kept | 1001 ms |
| All restored | **admitted** (proven gone, audited) | — | 560 ms |
| CHR2 rebooted, its reboot Stop lost: CHR1 login **while CHR2 is down** | refused | kept | 1800 ms |
| CHR1 login **after CHR2 is back** without the session | **admitted** (audited) | — | 991 ms |

- **Refusal timing:** refusals include the stock `reject_delay` of 1 s.
- **Lost-accounting cases:**
  - Lost Start: PENDING, CHR2 refused, then the Interim with the Class confirms the session.
  - Lost Interim: the slot stays ACTIVE, and CHR2 is refused after 2.1 s (verifier: LIVE).
  - Lost Stop: CHR2 is admitted after 0.64 s.

## 6. Accounting correlation

**Rules:**
- Class `0x6e75786831` + 32 hex. PPPoE uses `nuxr1`; neither regex matches the other.
- Start or Interim: PENDING/ACTIVE → ACTIVE. Stop: → ENDED.
- Every transition requires the same token, NAS-IP-Address, Acct-Session-Id **and** Calling-Station-Id.
- An unmatched Start or Interim writes `orphan_accounting`.

**Proven (F1 synthetic plus the harness contract, both engines):**
- A Stop before the Start ends the slot; the late Start and the Interim do **not** revive it.
- A duplicate Start counts once, and so does a duplicate Stop.
- A **new claim reusing the same Acct-Session-Id and MAC** is not ended by the older claims' Stop or Interim. Nor is it ended by its own Class arriving from another NAS-IP, or by a Stop without Class. Only its own Stop ends it.

**Gate B, real NAS:**
- The Class is echoed unchanged in Start, Interim and Stop for http-chap, http-pap, cookie re-login and MAC login.
- The slot goes ACTIVE, then ENDED by `acct_stop`.

## 7. Concurrent sessions (real CHR1 + CHR2; final-site runs 1 and 2)

| Gate | Result (run 2; run 1 identical) |
|---|---|
| `gc1`, limit 1: CHR1→CHR2, CHR2→CHR1, 10× simultaneous | 12/12 ENFORCED, 0 exceeded, 0 availability failures |
| `gc2`, limit 2: 4 clients on 2 NAS, sequential + 5× simultaneous | 6/6 ENFORCED |
| `gc3`, limit 3: 5 clients on 2 NAS, 5× simultaneous | 5/5 ENFORCED |
| Synthetic burst, 16 simultaneous requests, limits 1/2/3/5, 3 rounds each | always exactly `limit` admitted; slots = Accepts; all ended by their Stops |
| Concurrent decrease 3→2 with slot 3 live, 16 simultaneous requests, 5 rounds | 0 admitted, live ≤ 1; admitted after slot 3's Stop |
| F2 limit change on real NAS (2→1→2) | decrease refuses new logins until in use < limit; slot above limit ends by its Stop; increase admits |
| H1-A `concurrent` (equivalent spellings, limit 1/2) | live ≤ limit in every trial |

## 8. MariaDB / MySQL outcomes

**SQL stress (`gate_c_sql`) on the shipped schema, CHECK constraints included.**
- Setup: 25 concurrent sessions per round × limits 1/2/3/5 × 5 rounds per engine.
- **MariaDB 10.11.19:** never above the limit; exactly `limit` admitted; **0 errors**; limit decrease refused.
- **MySQL 8.0.46:** same result, **0 errors** (no deadlocks).

**Harness contract tests:** MariaDB and MySQL; see §9.2.

## 9. Full regression matrix

### 9.1 Lab: real NAS, final site (run 2, 13:43–14:56Z; run 1 12:27–13:34Z before the last hardening)

| Suite | Cases | Result |
|---|---|---|
| H-2B implementation | `vpn_untouched`, `f1_accounting`, `gate_c_retx`, `synthetic_burst`, `decrease_concurrent`, `f2_limit_change`, `lost_accounting`, `snmp_and_nas` (incl. CHR2 reboot), `component_failures` (table missing, audit table missing, DB paused), `gc1`/`gc2`/`gc3`, `gate_b`, `f5_timing`, `p0_deadline`, `pending_stall`, `verifier_never_pending`, `p3_retransmission`, `p2_concurrent`, `gate_c_sql` | **all pass** in run 2 |
| H1-A | `identity`, `shared1`, `shared2`, `concurrent`, `http_pap`, `cookie`, `negatives`, `duplicate_identity`: pass. `reconnect` and `mac_login`: same results as before H-2B (variants refused; lower-case MAC refused as documented in H1-A). `lost_accounting`: see §9.4 | H1-A property held everywhere |
| A2-R PPPoE | 20 real-NAS cases including `orphan_reboot_runbook`, `cross_nas`, `late_budget_real`, `slow_guard_lost_stop` + 5 fault-site cases | 24 pass. `G-RETX.synthetic` failed for a tooling reason (§9.4); passed twice after the lab log was reset |
| `HOTSPOT.username_variants` (the original H-1 discovery case) | `bypass_proven = false` (it was `true` on 2026-10-09, before H1-A) | H-1 closed |
| Lab PHPUnit `wp8-integration` (Option G, Port-Limit, RealAaa, CoA, lifecycle, H-1 gates…) | 69 tests, 1 failure (§9.4), 13 skipped (other lab modes), 3 pre-existing `system/` warnings | Option G Hotspot tests re-run after the fix: 3/3 OK |
| VPN | `vpn_untouched`: a VPN-shaped request (Framed-User, PPP, Virtual) still meets the stock Simultaneous-Use check ("You are already logged in"); no slot, no `nuxh1` Class. Also covered by harness `testTheStockSimultaneousUseCheckStillRunsForEveryNonHotspotRequest` | unchanged |

### 9.2 Harness (MariaDB + MySQL)

`dev/test/run-tests.sh` (lab down, `harness-both-engines.out`), **exit code 0**:

| Engine | Tests | Assertions | Result |
|---|---|---|---|
| MariaDB 10.11 | 1015 | 67,746 | OK; 79 incomplete |
| MySQL 8.0 | 1015 | 67,746 | OK; 79 incomplete |
| Variant registry reproducibility | — | — | pass |

**What the counts mean:**
- **The 79 incomplete** are the lab-gated WP8 tests. They are marked incomplete without the lab, the same 79 as the TI-02 baseline.
- **Test count:** 1015 = the TI-02 baseline 994 + the 21 new H-2B tests (9 source guards + 12 contract tests).

**Also run in isolation (no network):**
- `wp4-unit`: 47 tests, 721 assertions, OK.
- Mutation checks: 6/6 caught.

### 9.3 Lab finding fixed during this round: FreeRADIUS empty expansions

**What happened.**
- In final-site run 1, `synthetic_burst` (limit 1, round 2) left a slot in use after the admitted claim's own Stop.
- Trace (`frlog-excerpt-empty-expansion.txt`): request 61 generated token `5c91326f…`.
- While rlm_sql logged `No connections available and at max connection limit`, its claim UPDATE ran with **`token = ''` and `nas = ''`**. The Access-Accept still carried the real token, so its accounting could never match.

**Cause.**
- FreeRADIUS 3.2.8 rlm_sql escapes each `%{…}` inside a `%{sql:…}` statement on its own pool connection.
- When none is free, that expansion becomes an **empty string**, and the statement still runs.
- Conditions: lab pool max 8, 32 threads, 16 simultaneous requests. Frequency: 1 of 441 Hotspot claims.
- The limit was never exceeded. But a later release whose token also expanded empty could have matched `token ''`.

**Fix.**
- **CHECK constraints (§2):** an empty token or identity can never be stored or matched; the write fails and the login is refused.
- **Echoed pre-check (§3):** an emptied key or limit can never read as "free".

**Re-test.**
- **Pool 8:** 3× burst + decrease case. 661 pool-exhaustion events. 1 claim refused by `ck_nuxr_hs_admission_claim`, 1 echo mismatch caught, **0 empty tokens stored**, every round passed.
- **Pool 32**, following the README rule `pool.max ≥ max_servers`: 0 exhaustion events, 0 constraint failures.

**Finding for the owner (not changed here).** The accepted **A2-R PPPoE** statements rely on the same FreeRADIUS escaping. Their correct operation depends on the documented pool sizing, `pool.max ≥ thread max_servers` (README A2-R step 5). Hardening A2-R the same way would need a separate decision.

### 9.4 Failures and warnings (all kept in the evidence)

| Record | Cause | Status |
|---|---|---|
| `H2B.IMPL.synthetic_burst` 12:29:17Z (run 1) | Empty-expansion finding §9.3 | Fixed; passes in run 2 and in 3 re-runs; NOTE record |
| `H1.lost_accounting` h1a_pass=false (runs 1 and 2) | The case drops **all** accounting while the exact spelling logs in and out, so the slot stays PENDING (Strict Mode). The later exact logins are refused (live 0), where the pre-H-2B expectation was an admission. H1-A itself held (every variant refused). | Documented Strict Mode availability limitation (§11); NOTE record |
| `G-RETX.synthetic` (A2-R PPPoE) 14:48:59Z + 2 re-runs | The case calls `docker logs --since` between the reply and its "immediate" copy. On the day-long `-fxx` lab log this took **5.81 s > cleanup_delay 5 s**, so the copy was re-processed instead of answered from the cache. It correctly re-used the same claim and token. | Lab tooling. After recreating the lab FreeRADIUS container (fresh log), it passed twice (15:01:45Z, 15:02:17Z); NOTE record |
| Lab PHPUnit `OptionGGateTest::testShippedHotspotShared2` | The test's `guard_ran` heuristic counted any `Program returned code` line as the PPPoE guard. The c5 refusal ran the Hotspot slot verifier, which answered LIVE (2 live = limit). | Test fixed to match the PPPoE guard by name; the 3 Option G Hotspot tests re-run: OK |
| 3 PHP warnings `system/autoload/Message.php` (`RealLifecycleTest`) | Pre-existing (already in the A1 and H1-A evidence); `system/` unchanged | Not related |
| Code review (independent, read-only): 0 critical/high | M1, M3, L1–L8 fixed; M2 documented as a prerequisite (§12) | — |
| Pre-review regression run (10:55–11:20Z) | Stopped when the review findings changed the site; partial output kept (`lab-console.out`) | Superseded by runs 1 and 2 |
| `h2b_gate.py` syntax slip (a comment inside an expression) | Lab tool; `gate_c_sql` printed nothing once | Fixed; re-run passed |

## 10. Performance and timeouts

**Login latency (FreeRADIUS lab, Access-Request → Access-Accept on the wire, final site run 2):**

| Measurement | Result |
|---|---|
| 30 sequential logins | min 9 / p50 17 / p95 91 / **max 159 ms** |
| 24 requests in concurrent bursts | 9 / 15 / 34 / **34 ms** |
| Synthetic 16-request bursts | max 1044 ms, which includes the 1 s `reject_delay` of the refused ones |

**Verifier path (§5):**
- 560–991 ms to an Accept, including SNMP to a TCG-emulated CHR.
- Refusals with an unreachable or broken NAS: 1.0–1.8 s.
- Program deadline 1.5 s; module timeout 3 s.

**Budgets (all well inside):**
- The AV-5 late-claim budget is 5000 ms.
- The NAS window is ~9 s with `/radius timeout=3s`.

**Earlier measurement (feasibility gate):** the in-container SNMP cost was 116–215 ms per NAS.

## 11. Availability limitations (Strict Mode, accepted)

**A PENDING slot that never receives its token's accounting blocks that slot.** With `shared_users = 1` the subscriber is refused on every NAS, and there is no executable release. It happens when:
- every reply is lost, or arrives later than the NAS window (~9 s);
- FreeRADIUS stalls for more than ~9 s after its last check;
- the NAS fails between the Accept and the Start;
- accounting is lost for the whole session (e.g. H1 `lost_accounting`);
- under SQL pool exhaustion, a claim whose result expansion was lost. The `claim_failed` release covers the commonly seen case.

**Other availability effects:**
- After a lost Stop, the next login needs the slot's NAS to answer SNMP. NAS down, wrong community, or a non-RouterOS-7 agent: refused until a Stop arrives or the NAS proves the session gone.
- A limit decrease refuses new logins while a slot above the new limit is live.
- Every refusal on the verifier path costs ~1–1.8 s, bounded.
- **SQL pool exhaustion turns into refusals.** Production needs `pool.max ≥ max_servers`.
- Sessions already live when H-2B is enabled hold no slot, so they are not counted until they end.

## 12. Open production blockers

1. **Your acceptance** of this implementation and its availability limitations.
2. **The PENDING recovery procedure:** a separate decision and its own safety gate. Nothing executable exists.
3. **Read-only production impact queries** (separate authorization):
   - Hotspot subscribers without `Simultaneous-Use` (they would be refused);
   - Hotspot NAS rows and their SNMP reachability and credentials;
   - duplicated `NAS-IP-Address` across NAS (review M2: the slot's NAS identity is the attribute, as for A2-R);
   - non-MAC `Calling-Station-Id` formats.
4. **Production FreeRADIUS sizing:** `pool.max ≥ max_servers`, and the database `max_connections` (§9.3).
5. **NAS contract per Hotspot NAS:**
   - RouterOS 7, SNMP read-only from FreeRADIUS, a `nas_snmp` entry;
   - Hotspot accounting and interim on, `/radius timeout=3s`;
   - a unique `NAS-IP-Address` equal to `nas.nasname`.
6. **Monitoring** of `nuxr_hs_admission_report.sql` (alert on `pending_over_1_minute` and `orphan_accounting`).
7. **Owner decision on the A2-R empty-expansion exposure** (§9.3).
8. **Rollout order and timing:** live sessions are not counted at enable time.

## 13. Recovery runbook design (no executable release)

The runbook is in the README section "Hotspot admission slots" under "Recovery runbook". It has four parts:
- **Detect:** the report every minute.
- **Investigate, read-only:** on the slot's NAS, the active list, the log (`radius timeout` for the user at `created_at`), uptime (reboot after `created_at`), and `/radius print` (window = 3 × timeout).
- **Proof required before any future release:** the request can never become a session. That means a NAS reboot, or the NAS window plus margin passed with a NAS log of the failure. No session for that user and MAC. No possible in-flight Accept inside FreeRADIUS (a FreeRADIUS restart since, or an age beyond any stall).
- **Release: not available.** A future procedure needs its own decision. It must name the claim by its full token, act only if the slot is still PENDING with that token, record the operator account and the evidence, and handle a resumed old request. Until then: record and escalate, never edit the table by hand.

## 14. Git status and backup evidence

**Repository:**
- Branch `wp8/integration-poc`, **HEAD = origin = `1537775555b3a940e32c8103df4c98bb0549ddf1`**. Nothing committed or pushed.
- Protected refs unchanged: `origin/main` = `500d3a2d…`, `origin/feat/reseller-system-v1` = `5bdc5745…`.
- No reset, no stash (stash list empty), no clean.

**Working tree:**
- 17 modified files and 13 untracked paths, listed in §1.
- Untracked lab tooling and evidence: `dev/wp8/h2_gate.py`, `dev/wp8/h2b_gate.py`, and `owner-review/WP8/evidence/{h2,h2b,h2b-pending,h2b-impl}`.

**Protected areas:**
- 0 diff lines in `install/radius.sql` and `system/`.
- `docs/claude-development-pack` manifest: **42/42**.

**Backups, SHA256SUMS verified:**
- `/srv/phpnuxbill/wp8-h2b-impl-prefix-backup-20261011T112613Z` (33 files).
- `/srv/phpnuxbill/wp8-h2b-pending-prefix-backup-20261011T102103Z` (13 files).

**Secrets and cleanup:**
- Secret sweep: 0 hits over 106 changed, evidence and tool files.
- Lab down and clean: 0 containers, 0 netns, 0 QEMU. The harness containers are removed.
