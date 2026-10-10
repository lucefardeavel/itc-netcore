# WP8 A2-R — Feasibility Gate Report (lab only, 2026-10-09)

**Status:** this report is UNCOMMITTED and awaits owner review. Nothing was implemented in `install/`, `system/`, production
or AVAIA. The gates ran on a disposable prototype in the isolated WP8 lab only.

**Verdict:** `A2_R_FEASIBILITY_PASS_PENDING_OWNER_REVIEW` (all decisive gates passed; limitations in §8–§9; new pre-existing finding S-1)

Evidence labels used in this report:
- **[V-REAL]**: observed on the real RouterOS CHR 7.24.5.
- **[V-LAB]**: observed on lab FreeRADIUS 3.2.8 and the lab databases.
- **[A]**: an assumption.
- **[GATE]**: still needs an empirical gate.

## 1. Baseline

| Item | Value |
|---|---|
| Branch / commit | `wp8/integration-poc` @ `cd09a5893fa10b4650cc2d410cc618c200900687` (local = origin, before and after) |
| Protected refs | `feat/reseller-system-v1` `5bdc5745…`, `main` `500d3a2d…`; both unchanged |
| NAS | RouterOS CHR 7.24.5, QEMU TCG, fresh working disk, PPPoE server with `authentication=chap`, `interim-update=1m`, `/radius timeout=3000ms` |
| FreeRADIUS | 3.2.8, derived image `…:3.2.8-snmp`, production-contract A1 (`nas.type mikrotik_snmp`, guard credentials) via `option_g_gate.py on shipped` |
| Databases | MariaDB 10.11.19 (lab `nuxr_radius` plus throwaway `a2r_sqlgate`) and MySQL 8.0.46 (throwaway `a2r_sqlgate`), both from the `nuxbill-reseller-test` project |

**Lab-only artifacts**, all uncommitted:
- `dev/wp8/freeradius/a2r/phpnuxbill.a2r`: the prototype site. It is the project site with a changed `accounting` section and a changed PPPoE `post-auth` block.
- `dev/wp8/freeradius/a2r/nuxr_admission.lab.sql`: the prototype table.
- `dev/wp8/freeradius/start.sh`: two marker-gated blocks were added, `wp8-a2r` (use the prototype site) and `wp8-a2r-threaded` (run with the production thread pool).
- `dev/wp8/a2r_gate.py`: synthetic RADIUS sent byte-exact from inside the FreeRADIUS network namespace, plus the real-NAS cases.
- `dev/wp8/a2r_sql_gate.py`: SQL-level tests on both database engines.

**Important lab fact [V-LAB]:** the lab normally runs `freeradius -X`, which implies `-s`, i.e. a single thread. Concurrent requests can therefore never race inside FreeRADIUS. All concurrency cases were run with the production thread pool (`freeradius -fxx -l stdout`, marker `wp8-a2r-threaded`). The first G-RETX and G-LIFECYCLE synthetic records (16:27Z) were taken in `-X` mode. They are kept and are superseded by the threaded run (16:29–16:30Z); see §12.

## 2. Tests performed

All evidence is in `lab-evidence.jsonl` (redacted copy of `$NUXR_WP8_STATE/evidence/a2r.jsonl`).

| Gate | Case | Where | Result |
|---|---|---|---|
| G-ID baseline | Same subscriber, two spellings (`UPPER` on c1, `lower` on c3), **project site = accepted A1** | real CHR | **2 live sessions** (security finding, §8) |
| G-CLASS | basic: Accept → Start → Interim → Stop | real CHR | PASS |
| G-CLASS | lost Start, second attempt, Interim confirms | real CHR | PASS |
| G-CLASS | Stop without Start | real CHR | PASS |
| G-CLASS | accounting retransmission (FreeRADIUS paused at Stop) | real CHR | PASS |
| G-CLASS | sequential sessions: distinct tokens, takeover of ENDED claim | real CHR | PASS |
| G-LIFECYCLE | lost Stop: ACTIVE claim taken over only after A1 SNMP SAFE | real CHR | PASS |
| G-RETX | Access-Accept lost (NAS input drops FreeRADIUS replies) | real CHR | evidence (§5) |
| G-RETX | two NAS reboots; Accounting-On; Acct-Session-Id after reboot; orphan claim | real CHR | evidence (§5) |
| G-RETX | exact retransmission (in flight / cached / 8 s later / after FreeRADIUS restart), repeated auth, different attempts, missing/malformed identity | FreeRADIUS, threaded | PASS |
| G-LIFECYCLE | claim-before-Accept, delayed Start, Stop-before-Start, old accounting after replacement, other-NAS token, Accounting-On, creator failure without/with attacher | FreeRADIUS, threaded | PASS |
| G-SQL | 10 × 12 concurrent different attempts; 12 concurrent copies of one attempt; missing table; connection killed; DB paused; UTC | FreeRADIUS, threaded, MariaDB | PASS (with findings) |
| G-SQL | 30 × 20-session races on INSERT / conditional takeover / attach-vs-release; sentinel; INSERT IGNORE; UTC; FOUND_ROWS | MariaDB 10.11 **and** MySQL 8.0 | PASS |
| G-ID | spelling equivalence and claim-key collapse (8 bases × up to 14 spellings) | FreeRADIUS + both engines | PASS |
| G-ID | real NAS, two spellings, **prototype** | real CHR | single session (`a2r-reject=active-respell`) |
| A2 race rerun | `ConcurrentFirstLoginGateTest` B0 / C / E3 against the prototype | real CHR | **19/19 SINGLE_SESSION** (B0 ×10, C 5 s ×3 + 30 s ×3, E3 ×3); PHPUnit OK ×3 |

## 3. G-CLASS verdict: **PASS [V-REAL]**

What was observed on the CHR:
- **Echo of `Class`.** The Access-Accept `Class` (21 bytes: `nuxr1` followed by 16 random bytes) was returned **byte-identical** in Accounting-Start, Interim-Update and Stop (`G-CLASS.basic`).
- **Lost Start.** The NAS output to port 1813 was dropped for about 12 s.
  - The claim stayed PENDING.
  - A second, different attempt was refused (`Access-Reject`, `pending-other`), and only one NAS session existed.
  - **RouterOS did NOT re-send the lost Start** after the path came back.
  - The first Interim (about 60 s after session start) carried the same `Class` and moved the claim to ACTIVE, 47 s after the path was restored. The Stop then ended it.
- **Stop without Start.** The Start was lost, the session ended before any Interim, and the Stop alone, carrying the same `Class`, ended the PENDING claim (`end_reason=acct_stop`). The stock query inserted one closed `radacct` row.
- **Accounting retransmission.** The Stop was sent while FreeRADIUS was paused.
  - RouterOS retransmitted it after 3 s with the **same Id and the same Request Authenticator** (an exact retransmission), unchanged `Class`, and `Acct-Delay-Time` still 0.
  - The claim ended exactly once, and `radacct` holds 1 row, closed.
- **Sequential sessions.** Two sessions gave distinct tokens, distinct Acct-Session-Ids and distinct CHAP-Challenges. The second claim took over the ENDED first one by a conditional update (`prev_token` = the first token).

The NAS keeps the value through every PPP phase observed: authentication, IPCP, Interim and termination.

Not covered [GATE]:
- Hotspot (out of A2-R scope).
- A `Class` longer than 21 bytes.
- RouterOS versions other than 7.24.5.
- `Class` after a CoA.

## 4. G-ID verdict: **PASS for the prototype key; PRE-EXISTING SECURITY GAP in accepted A1 [V-REAL]**

- **Authentication equivalence [V-LAB, FreeRADIUS on MariaDB].**
  - `radcheck.username` is `utf8mb4_general_ci` (`install/radius.sql`, explicit on every column).
  - Spellings accepted as the SAME subscriber:
    - case variants;
    - `ı`→`i`;
    - `á`/`à`→`a`;
    - `é`/`É`/`E`→`e`;
    - `ß`→`s` (but NOT `ss`).
  - Spellings rejected:
    - a leading or trailing space (`filter_username`);
    - a decomposed `é`;
    - a full-width `＿`.
  - Names containing `'`, `"`, `\` or a space never authenticate. rlm_sql's escape turns them into `=27`, `=22`, `=5C`, `=20`, so the `radcheck` lookup finds nothing. This is pre-existing.
- **Both engines [V-LAB, SQL level].** MariaDB 10.11 and MySQL 8.0 return **identical** equivalence classes with a FreeRADIUS-style (coercible) utf8mb4 literal, because the column collation decides. MySQL's default `collation_connection` is `utf8mb4_0900_ai_ci`. An *implicit* literal with that collation, such as `CONVERT(... USING utf8mb4)`, makes the comparison FAIL with an error. The first engine run counted that error as a match; it was invalid for this item and is corrected (§12).
- **Claim key.** The key is the canonical `radcheck.username` bytes matched by the lookup, `ORDER BY CAST(username AS BINARY) LIMIT 1`, stored as `utf8mb4_bin`.
  - Every accepted spelling of every base mapped to **exactly one** key (`G-ID.auth_equivalence`, both engines).
  - While the base claim was PENDING, every other accepted spelling was refused, with 0 variants accepted (`G-ID.matrix`).
- **Accepted A1 gap (no prototype) [V-REAL].** The production-contract project site admitted `NUXR_A2R_RCV_…` on c1 and `nuxr_a2r_rcv_…` on c3, giving 2 live PPPoE sessions (`0x81000000`, `0x81000001`). The causes:
  - The stock `radacct` count is case-insensitive (`n=1`).
  - The Option G guard compares the interface name **byte-exactly** with the requesting spelling, so it returned SAFE.
  - The stock checkrad layer is fail-open (OD-W8-16 Gate D).

  With the prototype the second login was refused (`active-respell`). See §8 S-1.

## 5. G-RETX verdict: **PASS for the identity rules; refined identity recommended [V-REAL + V-LAB]**

**RouterOS 7.24.5 behaviour [V-REAL]:**
- **Lost Access-Accept.** RouterOS sends the Access-Request **3 times, 3 s apart**, with the same Id, source port, Request Authenticator, Acct-Session-Id and CHAP-Challenge (exact retransmissions). It then fails PPP authentication ("authentication failed - radius timeout") and terminates the PPPoE session. No second Access-Request with a new authenticator was ever sent for the same PPP session.
- **FreeRADIUS deduplication.** FreeRADIUS answered both retransmissions from its reply cache ("Sending duplicate reply"). The claim was not touched (`attach_count=0`).
- **Acct-Session-Id after a reboot with the SAME working disk.** The values were `81000000…` → `81100000` → `81200000`: the second hex digit is a per-boot generation. The values are therefore **not reused** across these reboots. The earlier observation "restarts at 81000000" holds only for a FRESH disk (reinstall or re-image).
  - [GATE] It is unknown what happens after 16 reboots (wrap) or on a reinstall in production.
- **Accounting-On** came after each reboot and was retransmitted, as before (OBS-W8-7). It did not touch the reservation.

**Mechanism [V-LAB, threaded]:**
- **(a) Exact retransmission while the original is still running:** dropped ("Ignoring duplicate packet … unfinished request").
- **(b, c) Exact retransmission 1 s and 8 s later:** cached reply, identical bytes, claim untouched.
- **(c2) The same bytes after a FreeRADIUS restart** (cache gone): re-processed, **attached** to the claim, and returned the same token.
- **(d) Same identity with a new authenticator** (CHAP, and PAP): attached, same token.
- **(e) A different attempt while PENDING is always refused.** Variants tested:
  - a new Acct-Session-Id;
  - the same Acct-Session-Id from another MAC;
  - the same Acct-Session-Id and MAC on another port;
  - the same Acct-Session-Id, MAC and port on another NAS.
- **(f) Missing or malformed identity is refused:**
  - no Acct-Session-Id;
  - no Calling-Station-Id;
  - no NAS-Port-Id;
  - an Acct-Session-Id with quote or SQL characters;
  - a non-MAC Calling-Station-Id;
  - a 40-character Acct-Session-Id.

**Recommendation.** Add the CHAP-Challenge (or, for every method, the Request Authenticator, `Packet-Authentication-Vector` [GATE]) to the attempt identity.
- The evidence shows that every legitimate re-use is an EXACT retransmission, which keeps both values.
- Adding them prevents a new attempt from attaching to an old PENDING claim after an Acct-Session-Id reuse (fresh disk).
- The cost: case (d) would be refused. RouterOS was never seen producing it.

## 6. G-SQL verdict: **PASS on both engines; FreeRADIUS→MySQL path not run [V-LAB]**

**Engine level** (30 rounds × 20 parallel sessions, released together; MariaDB 10.11.19 and MySQL 8.0.46, both STRICT):
- **Plain INSERT on the PRIMARY KEY:** exactly 1 row count of 1 and 19 × `ERROR 1062` in every round.
- **Conditional takeover** (`state = 'ENDED' AND token = old`): exactly 1 winner per round.
- **Attach versus the creator's conditional release** (`attach_count = 0`): never both.
  - MariaDB: release won 2 rounds, attach won 28.
  - MySQL: release won 3 rounds, attach won 27.
  - Every final state was consistent.
- **INSERT IGNORE:** returns 0 rows on a duplicate (a warning only). On an over-long key it inserts a **silently truncated** row (MySQL: 1 row, 64 characters), where a plain INSERT fails with `ERROR 1406`. → **plain INSERT only.**
- **UTC:** `UTC_TIMESTAMP(6)` into `DATETIME(6)` is independent of the session `time_zone` (+03:00 tested), while `NOW(6)` is shifted by 10800 s.
- **FOUND_ROWS:** an UPDATE that matches a row but changes nothing returns 0 without the flag and 1 with `CLIENT_FOUND_ROWS`. Every prototype statement changes a column (token, state, counter or time), so the result is the same either way.

**FreeRADIUS path** (MariaDB, threaded):
- **10 rounds × 12 concurrent different attempts:** exactly 1 Access-Accept per round (1 / 11 / 0 every round).
- **rlm_sql 3.2.8 xlat:** an SQL **error** (1146, 1062) and **"affected no rows"** both expand to an **empty string**; neither aborts the update section. Every expected value is therefore checked against an exact sentinel (`k=…`, `r=…`, `a2r-claim=1`), and anything else rejects.
- **Missing table:** Access-Reject with only `Reply-Message`.
- **Connection killed** while the request held it (hook): the next statement reconnected; claim and Accept were consistent.
- **Database paused 12 s between authentication and the claim:** the claim succeeded after the pause and the **Access-Accept left after 13.2 s**. A real NAS gives up after about 9 s, so this would leave an uncertain PENDING claim (§9).
- **12 concurrent copies of ONE attempt:** 8–9 accepted with one token. The rest were refused, partly because **the SQL pool (`max = 8`) was exhausted** ("No connections available and at max connection limit" → condition evaluation error → reject). That is fail-closed, and a sizing item (§9).
- **UTC:** `created_at` matched the host UTC clock while FreeRADIUS ran with TZ=Asia/Baghdad (+0300).

Not run [GATE]: FreeRADIUS → MySQL 8.0 (`rlm_sql_mysql` connection charset and affected rows). The lab FreeRADIUS uses MariaDB; the dual-engine harness (`freeradius-mysql`) covers it at implementation time.

## 7. G-LIFECYCLE verdict: **PASS; recovery of an uncertain claim needs confirmed NAS-side invalidation [V-LAB + V-REAL]**

- **Claim before Accept:** the claim is the last step before the Access-Accept, and the log order (`a2r-claim=1` before `Sent Access-Accept`) was verified.
- **Failure after the claim, creator only, nobody attached:** released by a conditional update to `ENDED / pre_accept_failure`.
- **Creator failure after another request of the SAME attempt attached and was accepted (threaded):**
  - The attacher received Access-Accept with the claim token.
  - The creator's release statement (`AND attach_count = 0`) matched 0 rows, and the creator was rejected.
  - The **claim stayed PENDING (attach_count 1)**: the mandatory safety condition holds.
  - At SQL level, 60 races on two engines showed the same.
- **Concurrent requests of the same attempt:** a single token. The losers re-read once and may only attach to the SAME attempt; otherwise they are rejected (`claim-lost`).
- **Delayed Start:** a different attempt is refused while PENDING; the late Start with the token gives ACTIVE.
- **Stop before Start, then a late Start of the same token:** the claim stays ENDED (never revived).
- **Old accounting (Start / Interim / Stop with the previous token) after replacement:** the new claim is unchanged.
- **Token from another NAS-IP-Address:** no effect. **Accounting-On:** no effect.
- **Lost Stop [V-REAL]:** the ACTIVE claim was taken over only after the A1 SNMP verification of its NAS returned SAFE. This is the accepted A1 semantics, applied only to a claim already proven ACTIVE.
- **A2 race scenarios against the prototype [V-REAL]** (threaded FreeRADIUS, production-contract A1): 19 of 19 trials were SINGLE_SESSION, with exactly one Access-Accept per trial and NAS max = 1.
  - In A2 these scenarios gave RACE_PROVEN_PERSISTENT_DOUBLE.
  - Refusal reasons: `pending-other` (claim PENDING, no open row) or A1 `LIVE` (the Start had already confirmed the claim, `n=2`).

**Recovery of PENDING / UNKNOWN:**
- **[V-REAL]** One lost Access-Accept left a claim PENDING forever. The next attempt was refused, and the claim survived **two NAS reboots**, because no automatic reboot release is implemented (Strict).
- Clearing it because SNMP shows no session is NOT reliable. Recovery requires **confirmed invalidation of that attempt on the NAS**. The candidates found, none of them implemented:
  - **R1, NAS reboot after the claim.** No attempt survives a reboot. The proof can come from Accounting-On together with the Acct-Session-Id boot generation (`811…` → `812…`) [GATE: format, wrap, reinstall], or from the operator's reboot record.
  - **R2, NAS-side failure record of that attempt.** RouterOS logs `<002b>: user … authentication failed - radius timeout` (topics `pppoe,ppp,error`) [V-REAL, `G-LIFECYCLE.recovery_evidence_nas_log`]. The line names the user and a PPPoE tag, **not** the Acct-Session-Id (`81200029`) and not the MAC. Binding it to the claim therefore needs correlation by time and user, and log retention is limited: [GATE].
  - **R3, operator isolation.** No RADIUS-side action can cancel an Access-Accept already delivered. An operator release without R1 or R2 is a documented risk acceptance, not a proof.

## 8. Security limitations

- **S-1 (NEW, HIGH, pre-existing in accepted A1):** a subscriber can hold 2 live PPPoE sessions by using two case or accent spellings of the same username [V-REAL]. A2-R closes it for PPPoE through the canonical key and the `respell` / `m>0` rejects. The **Hotspot equivalent (NAS Port-Limit is per spelling) is untested [GATE]**. Classification and remedy are owner decisions; nothing was fixed.
- **S-2:** an uncertain claim (Accept lost, late Accept, FreeRADIUS crash after the claim) cannot be resolved by evidence available to FreeRADIUS (§7).
- **S-3:** the Acct-Session-Id is NAS-assigned but **is** reused after a re-image. Without the CHAP-Challenge or authenticator in the identity, a post-reimage attempt could attach to an old PENDING claim of the same MAC and port. That is harmless because the old attempt is dead, but it is not "prevented".
- **S-4:** the mechanism trusts `Class` only from the NAS that holds the claim, and only with a 128-bit random token. A NAS that strips or rewrites `Class` leaves claims PENDING (fail-safe for security, lockout for availability).
- **S-5:** no automatic disconnect (O4 deferred). A double session created by a path not covered here would persist.

## 9. Availability implications (Strict Mode)

- **Lockout after one lost Accept [V-REAL]:** until confirmed invalidation or operator action. The frequency depends on how often NAS↔RADIUS Access-Accepts are lost. It is not measured in production [GATE].
- **Late Accepts [V-LAB]:** a FreeRADIUS or database stall longer than the NAS retry window (about 9 s) produces a claim for an attempt the NAS has already abandoned. Mitigation candidate [GATE]: a **late-claim guard** that refuses (does not claim) when the request is older than about 2 s at claim time, using FreeRADIUS's request timestamp. It does not cover packets queued in the kernel before FreeRADIUS reads them.
- **Burst load [V-LAB]:** SQL pool exhaustion rejects logins (fail-closed). The production pool size must be at least the expected concurrent PPPoE authentications plus accounting.
- **NAS reboot:** claims stay PENDING or ACTIVE. ACTIVE recovers through A1 SNMP SAFE; PENDING needs R1.
- **Common cases unaffected [V-REAL]:** normal login and logout, lost Start (confirmed by the first Interim), lost Stop (A1 takeover), and accounting retransmission.

## 10. Recommended final implementation architecture

This is the prototype as validated, plus the evidence-driven refinements:
1. **One table per ISP RADIUS database**, created by a separate, additive upgrade file. The key is the canonical `radcheck.username` (`utf8mb4_bin`). No `radacct` change and no DELETE.
2. **Post-auth (PPPoE only), claim LAST:**
   1. identity validation;
   2. canonical key;
   3. read the reservation (sentinel);
   4. Strict refusals (`pending-other`, `active-respell`, open rows under another spelling);
   5. A1 verification of `radacct` NAS plus the ACTIVE claim's NAS;
   6. claim with a plain INSERT, a conditional takeover of ENDED or ACTIVE (ACTIVE only after SNMP SAFE), or an attach of the SAME attempt;
   7. one re-read on failure;
   8. `Class` = `nuxr1` + 128-bit token.
3. **Attempt identity** = NAS-IP-Address + Acct-Session-Id + Calling-Station-Id + NAS-Port-Id + spelling + **CHAP-Challenge or Request Authenticator** (refinement, §5).
4. **Accounting:** conditional on token + NAS + state. Start or Interim gives ACTIVE; Stop gives ENDED. Accounting-On does nothing.
5. **Every SQL value is checked against an exact sentinel**, because empty means error or no rows. Any SQL error rejects.
6. **Release paths only:**
   - token-matched Stop;
   - A1 SNMP SAFE for an ACTIVE claim;
   - the creator's pre-Accept failure with `attach_count = 0`;
   - the owner-approved recovery R1 / R2 / R3.
7. **Late-claim guard** [GATE], and **SQL pool sizing** in the deployment contract.
8. **Readiness:** per-request fail-closed (proven: missing table → reject), plus a deploy-time check that the table and columns exist.

## 11. Exact proposed schema and files (FOR OWNER APPROVAL ONLY — not applied)

**Schema:** table `nuxr_admission`, InnoDB, `utf8mb4_bin`, in the existing per-ISP RADIUS database. The lab prototype is `dev/wp8/freeradius/a2r/nuxr_admission.lab.sql`.

| Column | Type | Purpose |
|---|---|---|
| `username` | varchar(64) utf8mb4_bin, **PRIMARY KEY** | canonical `radcheck.username` |
| `state` | enum('PENDING','ACTIVE','ENDED') | lifecycle |
| `token` | char(32) ascii_bin, **UNIQUE** | `Class` token |
| `nas` | varchar(15) utf8mb4_general_ci | NAS-IP-Address (same collation as `radacct.nasipaddress` for the UNION) |
| `acct_session_id`, `calling_station_id`, `nas_port_id`, `session_user` | utf8mb4_bin | attempt identity / spelling |
| `chap_challenge` (or `request_authenticator`) | ascii_bin | identity refinement (§5) |
| `attach_count` | int unsigned | attach versus release safety |
| `created_at`, `updated_at`, `confirmed_at`, `last_acct_at`, `ended_at` | datetime(6) UTC | evidence |
| `end_reason`, `prev_token`, `prev_state` | ascii | audit of transitions |

**Files (proposed):**
- `install/freeradius/nuxr_admission.sql` (NEW): idempotent upgrade, `CREATE TABLE IF NOT EXISTS`. `install/radius.sql` is **unchanged**.
- `install/freeradius/sites-available/phpnuxbill`: post-auth and accounting as in §10. This is the **A1 policy change** (D-A2R-4).
- `install/freeradius/README.md`: deployment contract.
  - Apply the upgrade.
  - Readiness check.
  - NAS contract: `Class` passthrough, Interim enabled, `/radius timeout` and retries.
  - SQL pool size.
  - Recovery procedure.
- `install/freeradius/bin/nuxr_admission_check` (NEW, optional): deploy-time readiness check.
- `dev/test/freeradius/*`, `dev/wp8/*`: lab and harness wiring.
- Tests:
  - `tests/Integration/Wp4/AdmissionReservationContractTest.php`: dual-engine harness, which covers FreeRADIUS→MySQL 8.0;
  - the existing `ConcurrentFirstLoginGateTest`, which turns green;
  - a new real-NAS gate for `Class`, lifecycle and retransmission, derived from `a2r_gate.py`;
  - an A1 regression.
- `docs/WP4_RADIUS_MODEL.md` and `owner-review/WP8/*`: documentation.
- **No** `system/` change, no RouteMap, capability, DataScope or finance change, no new service.

## 12. Cleanup and Git status

- **Prototype off:** marker removed, table dropped, project site restored (`site_is: project`).
- **Lab down:** 0 links, 0 netns, no QEMU, no agent, 0 containers and 0 networks of `nuxbill-reseller-test`, including the MySQL container started for G-SQL. The working disk was deleted; secrets stay only in `$NUXR_WP8_STATE`.
- **Evidence (redacted, 0 secret hits, no test passwords):**
  - `lab-evidence.jsonl`: 22 records;
  - `race-rerun-evidence.jsonl`: 21 records, the A2 race re-run against the prototype.
- **Git:** `wp8/integration-poc` = `cd09a589` = origin; `feat` `5bdc5745` and `main` `500d3a2d` unchanged. Nothing committed or pushed.
- **Working tree (uncommitted, lab and evidence only):**
  - `M dev/wp8/freeradius/start.sh` (two marker-gated lab blocks);
  - `?? dev/wp8/a2r_gate.py`, `?? dev/wp8/a2r_sql_gate.py`, `?? dev/wp8/freeradius/a2r/`;
  - `?? owner-review/WP8/evidence/a2r-feasibility/`.
- `install/`, `system/` and `install/radius.sql` are untouched. Production and AVAIA were not touched.

**Records disclosed and kept** (originals never edited; each has a correction or a superseding record):
- `G-RETX.synthetic` and `G-LIFECYCLE.synthetic` at 16:27Z (`pass=false`). These were taken in `-X` single-thread mode.
  - In (c), the expectation was wrong: FreeRADIUS still replays a cached reply 8 s later.
  - In (3/4), the creator and attacher could not interleave under `-X`.
  - Both are superseded by the threaded run at 16:29–16:30Z (`pass=true`).
- `G-RETX.reboot_asid_reuse` at 16:26Z. Its `asid_recurred` and `old_claim_reused` fields compared the unchanged orphan row with itself. The correction record is at 16:27Z; the real values are 81100000 versus 81200000, so false and false.
- `G-SQL.engines` at 16:45Z. Item 7 (an error counted as a match) and its version / sql_mode fields are invalid. The correction record is at 16:46Z, and the rerun is at 16:55Z.

**Documentation accuracy (owner item 7).** The double session was **proven** in A2 by **B0, C and E3** only.
- A (single-login baseline) measured the Accept→Start window.
- Bscan was SINGLE_SESSION ×7.
- Neither A nor Bscan is evidence of a double connection.

The decision review of 2026-10-09 wrongly listed "A, B0, Bscan" under F1. All future references use B0 / C / E3. The A2 evidence itself is unchanged.
