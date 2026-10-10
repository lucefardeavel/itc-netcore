# WP8 A2-R — Implementation & Security Acceptance Report (2026-10-09)

This work is **uncommitted**: no commit, push, merge or deployment was made. It runs in the dev branch and the isolated WP8
lab only, and awaits owner acceptance.

**Verdict: `A2_R_PASS_PENDING_OWNER_ACCEPTANCE`**
- Every required security and implementation gate passed.
- No double PPPoE session appeared in any trial.
- This is **NOT production-ready**. The open blockers are listed in §14.

## 1. Baseline and protected backup

- **Baseline.** `wp8/integration-poc` @ `cd09a5893fa10b4650cc2d410cc618c200900687`, equal to origin before and after. `feat/reseller-system-v1` `5bdc5745…` and `main` `500d3a2d…` are unchanged. No reset, stash or clean was used.
- **Backup** (taken before any change): `/srv/phpnuxbill/wp8-a2r-prototype-backup-20261009T172236Z`, mode 0700.
  - 10 files, each SHA-256 verified (`SHA256SUMS`): the feasibility prototype site and lab schema, `a2r_gate.py`, `a2r_sql_gate.py`, the `start.sh` change and diff, the feasibility report and evidence, and the state evidence.
  - The two prototype files were removed from the working tree only after their SHA-256 matched the backup.
  - The feasibility evidence (`owner-review/WP8/evidence/a2r-feasibility/`) is kept unchanged.

## 2. Files changed

**Production configuration (`install/`):**

| File | Change |
|---|---|
| `install/freeradius/nuxr_admission.sql` | NEW. Additive, idempotent upgrade: `nuxr_admission` and `nuxr_admission_audit`. |
| `install/freeradius/nuxr_admission_check.sql` | NEW. Read-only readiness check; prints `nuxr_admission=READY`. |
| `install/freeradius/nuxr_admission_release.sql` | NEW. Operator recovery of ONE `PENDING` claim, with an audit row. |
| `install/freeradius/sites-available/phpnuxbill` | PPPoE post-auth (A1 extended) and accounting transitions. |
| `install/freeradius/README.md` | New section "PPPoE admission reservation": install / upgrade / rollback contract, NAS contract, pool sizing, recovery, "Not covered". |

**Not changed:** `install/radius.sql`, `system/`, the RouteMap / capability / DataScope / finance code, Hotspot, and the guard program and module.

**Documentation:** `docs/WP4_RADIUS_MODEL.md` (A2-R addendum).

**Tests:**
- New:
  - `tests/Unit/Wp4/AdmissionSourceTest.php`
  - `tests/Integration/Wp4/AdmissionReservationContractTest.php` (dual engine)
  - `tests/Integration/Wp8/UsernameEquivalenceGateTest.php` (S-1 regression gate, real NAS)
  - `tests/Support/RadiusWire.php` (byte-level RADIUS client; RouterOS identity incl. NAS-Port-Id)
- Changed:
  - `tests/Unit/Wp4/SessionGuardSourceTest.php`: A1 lines kept in order; counts updated for the approved extension.
  - `tests/Integration/Wp4/SessionGuardContractTest.php`: schema load; RouterOS PPPoE identity on synthetic requests; assertions unchanged.
  - `tests/Integration/Wp8/OptionGGateTest.php`: identity on synthetic requests; gate I ends its synthetic Accept with a Stop.
  - `tests/bootstrap.php` and `tests/phpunit.xml`: the S-1 gate joins the `wp8-security-gates` suite.

**Lab tooling (`dev/wp8/`):**
- `lab.sh`: loads and checks the schema; `up-chr2` with explicit MACs.
- `configure_chr.py`: CHR2 role.
- `client_agent.py`: client `c6`.
- `freeradius/start.sh`: two marker-gated lab blocks, `wp8-a2r-fault` and `wp8-a2r-threaded`.
- `a2r_gate.py` and `a2r_sql_gate.py`.

## 3. Schema and upgrade contract

- **`nuxr_admission`.** One row per canonical subscriber.
  - `username` is `varchar(64) utf8mb4_bin`, the PRIMARY KEY: the exact `radcheck.username` the lookup matched.
  - `state` is `PENDING / ACTIVE / ENDED`.
  - `token` is `char(32) ascii_bin`, UNIQUE.
  - The attempt identity: `nas`, `acct_session_id`, `calling_station_id`, `nas_port_id`, `request_authenticator`, `session_user`.
  - Also: `attach_count`, UTC `DATETIME(6)` timestamps, `end_reason`, `prev_token`, `prev_state`.
- **`nuxr_admission_audit`.** Append-only: operator, reason, evidence and outcome.
- **Contract (per ISP):**
  1. Back up.
  2. Apply `nuxr_admission.sql`.
  3. Run `nuxr_admission_check.sql`; it must print `READY`.
  4. Install the site and restart.
  5. Size `pool.max` for the peak load.
  6. NAS contract: `Class` passthrough, Interim enabled, `/radius timeout=3s`.
- **Rollback.** Reinstall the previous site; the tables may stay.
- **Fail closed when not ready.** A missing table means every PPPoE login is refused, with only `Reply-Message` in the reply (verified on both engines and in the lab).

## 4. State lifecycle and identity

- **Order.** Authentication, then Simultaneous-Use, then the identity checks, the canonical key, the reservation read, the Strict refusals and the A1 SNMP verification (radacct NAS plus the NAS of an ACTIVE claim), then the late guard, the **claim (last)**, the post-claim late guard, and finally `Class` = "nuxr1" + 128-bit token.
- **Claim.**
  - From no row: a plain INSERT.
  - From `ENDED` or `ACTIVE`: an UPDATE conditioned on state and previous token. `ACTIVE` is reached only after SNMP proved its session gone.
  - Attach: the SAME attempt only. Identity = NAS-IP + Acct-Session-Id + Calling-Station-Id + NAS-Port-Id + spelling + **Request Authenticator**.
  - On failure: one re-read, then attach or refuse.
- **Accounting.** Token + NAS-IP + Acct-Session-Id + Calling-Station-Id + state must all match.
  - Start or Interim gives ACTIVE; Stop gives ENDED.
  - Accounting-On changes nothing.
  - ENDED is never revived.
- **Release paths.**
  - A token-matched Stop.
  - A1 SNMP SAFE for an ACTIVE claim.
  - The creator's late claim, only when `attach_count = 0`.
  - The operator procedure, for PENDING only.
  - There is **no release by time, SNMP-on-PENDING, sysUpTime or Session-ID generation.**
- **Sentinels.** rlm_sql 3.2.8 expands both an SQL error and "no row affected" to an empty string. Every value is therefore matched exactly (`k=…`, `r=…`, `n=…`, `m=0`, `nuxr-admission-claim=1`, a 32-hex token, a numeric age).
- **Late guard.** The request age (current minus receipt time) must be ≤ 2000 ms, checked before and after the claim. The current time is read microseconds first, so a second boundary can only overestimate the age (fixed during this stage, see §12).

## 5. Test matrix

**Harness** (real FreeRADIUS 3.2.8 against MariaDB 10.11.19 AND **MySQL 8.0.46**; FreeRADIUS→MySQL path included):

| Suite | MariaDB | MySQL |
|---|---|---|
| Unit source contracts (`AdmissionSourceTest`, `SessionGuardSourceTest`) | 10 tests / 205 assertions OK | (engine independent) |
| `AdmissionReservationContractTest` + `SessionGuardContractTest` | 18 / 295 OK | 18 / 295 OK |
| WP4 + WP5A + WP5B full regression (final site) | 274 / 5788 OK (rc 0) | 274 / 5788 OK (rc 0) |

**SQL level** (`a2r_sql_gate.py`, both engines, production schema): 30 × 20-session races for each of plain INSERT, conditional take-over, and attach versus release.

| Check | MariaDB | MySQL |
|---|---|---|
| Plain INSERT race: exactly one winner (+19 × 1062) | yes | yes |
| Conditional take-over race: exactly one winner | yes | yes |
| Attach vs release: never released after an attach | yes (release won 0, attach won 30) | yes (release won 2, attach won 28) |
| Identity: equivalence classes and one key per subscriber | identical | identical |
| INSERT IGNORE | truncates silently | truncates silently |
| UTC | independent of the session time zone | independent of the session time zone |
| FOUND_ROWS | documented | documented |

**Real NAS lab** (CHR 7.24.5, FreeRADIUS threaded = production model, production-contract A1). The lab gates (`lab-evidence.jsonl`) all ended PASS; superseded first passes are listed in §12.

| Gate | Result |
|---|---|
| G-CLASS basic | `Class` of 21 bytes returned unchanged in Start, Interim and Stop. |
| G-CLASS lost Start | Second attempt refused; the Interim confirms 46.6 s after the path is restored. |
| G-CLASS Stop without Start | Claim ends. |
| G-CLASS accounting retransmission | Exact Stop retransmit; the claim ends once. |
| G-CLASS sequential sessions | Distinct tokens; take-over of the ENDED row. |
| Lost Stop (A1 semantics) | ACTIVE claim taken over only after SNMP SAFE. |
| G-RETX: real RouterOS request replayed byte-exact | Attaches with the same token. Same identity with a new authenticator: refused. |
| G-RETX synthetic | In-flight duplicate dropped; cached reply identical at 1 s and 8 s; other port and FreeRADIUS restart re-use the claim; 5 kinds of other attempt refused; 6 malformed identities refused; MS-CHAPv2 refused; PAP and CHAP admitted. |
| G-LIFECYCLE synthetic | Claim before Accept; 7 invalid or foreign `Class` cases with no effect; delayed Start; Accounting-On; Stop then late Start; old accounting after replacement; creator late with no attacher (released); **creator late after an ACCEPTED attacher: claim kept PENDING, attach_count 1.** |
| G-SQL concurrency | 10 × 12 concurrent attempts: exactly 1 Accept per round. 12 copies of one attempt: 1 token. Pool pressure: see §10. |
| G-SQL faults | Missing table: reject with only Reply-Message. Killed connection: reconnects, consistent. DB paused: reject, claim unchanged. UTC. |
| DB interruption on the real NAS | DB paused across the request: reject at 4.3–7.4 s, no claim, next login OK. |
| Verifier faults | Live session baseline: LIVE reject. SNMP unreachable: ERROR reject. Guard not executable: reject. Claim untouched, 1 session. |
| FreeRADIUS restart with PENDING | The claim survives; second attempt refused; the Interim confirms with the same token. |
| Late claim on the real NAS | Claim held 6 s by a row lock: released (`late_claim`), refused, next login OK. |
| NAS deadline | Accept delivered at the 3rd transmission (~6 s): session UP. All 3 unanswered: abandoned and the claim stays PENDING. |
| Recovery runbook | Lost Accept leaves PENDING; refused before and **after a NAS reboot**; operator release (reboot evidence) RELEASED + audit row; login OK. |

## 6. RouterOS CHR evidence (7.24.5)

- **`Class`.** Returned unchanged in every accounting packet.
- **Lost Start.** Never re-sent.
- **Lost Access-Accept.** The Access-Request is sent 3 times (0, 3.0, 6.0 s) with the same Identifier, Request Authenticator, Acct-Session-Id and CHAP-Challenge. It then fails with "authentication failed - radius timeout". The RouterOS log names the user and a PPPoE tag, not the Acct-Session-Id.
- **Accounting retransmission.** Exact (same Identifier and authenticator); `Acct-Delay-Time` stays 0.
- **Acct-Session-Id.** Lowercase hex in RADIUS; the API shows `0x…` in upper case.
- **Hotspot.** `Port-Limit` is enforced per spelling (§9).

## 7. Cross-NAS evidence (real CHR + real CHR2)

- **CHR2.** 10.88.0.3, own PPPoE segment and client `c6`, NAS type `mikrotik_snmp`, guard credentials.
- **Race.** 13 trials: 10 concurrent, 3 with the first NAS's Start lost and the second NAS 5 s later. **13/13 single session**, with a NAS maximum of 1 across both NAS. The winner was on either NAS.
- **Live on CHR2, login on CHR1.** The guard asks CHR2: **LIVE**, refused.
- **Lost Stop on CHR2, login on CHR1.** CHR2 SNMP **SAFE**, take-over (prev_state ACTIVE), admitted.
- **Lab defect found and fixed.** QEMU gives every VM the same default NIC MACs, so CHR1 and CHR2 collided on the lab bridge (`ether2` = 52:54:00:12:34:57). The first cross-NAS run (19:46Z, in which c6 never won) is **invalid** and kept. `lab.sh up-chr2` now sets explicit MACs, and the gate was re-run (19:54Z).

## 8. S-1 PPPoE closure evidence

- **Pre-check.** 157 subscribers in the lab database; no distinct accounts collide under the lookup collation. The harness check also passed on both engines.
- **Real NAS.** Two clients, two spellings (`UPPER` / `lower`, `café` / `cafe`): the second is refused (`active-respell`); 1 live session.
- **PHPUnit regression gate** (`UsernameEquivalenceGateTest`): OK ×2 runs, including after the final fix.
- **Equivalence (FreeRADIUS on MariaDB, plus SQL on both engines).**
  - Case, `ı→i`, `á/à→a`, `é/É/E→e` and `ß→s` (not `ss`) all map to **one** key.
  - While a claim is PENDING, 0 equivalent spellings are admitted.
  - Names containing `'`, `"`, `\` or a space never authenticate (rlm_sql `=XX` escaping; pre-existing).

## 9. Hotspot assessment (no Hotspot change)

**BLOCKER H-1 (proven, HIGH, out of A2-R scope):**
- With `Port-Limit` 1, two equivalent spellings of one Hotspot subscriber (`UPPER` / `lower`, and `café` / `cafe`) held **2 live Hotspot sessions**.
- The same-spelling control was enforced (1 session).
- Hotspot was not changed. This needs its own owner-approved scope.

## 10. Availability and recovery risks

- **AV-1 (BLOCKER for production) — FreeRADIUS stall.**
  - FreeRADIUS was paused (WP8 drill 6). The Access-Request waited in the socket buffer, then was processed after the pause with an age of 17 ms, which the late guard cannot see.
  - The policy claimed it and sent an Accept that the NAS had already abandoned, which left a PENDING claim. The subscriber's next login was refused (`FailureDrillsTest::testDrill1and6…` now fails, see §11).
  - Recovery: the operator runbook.
  - Unresolved: no FreeRADIUS 3.2.8 mechanism exposes the kernel arrival time.
- **AV-2 — SQL pool pressure.** 40 concurrent attempts with the lab `pool.max = 8` produced 166 "No connections available" errors, and **2 of 3 rounds admitted nobody**.
  - Never more than 1 Accept, so this is availability, not security.
  - Production `sql.example` has `max = 32`; it must be sized (README).
- **AV-3 — uncertain claims by design (Strict).** One lost Access-Accept (all 3 transmissions) leaves a PENDING claim.
  - Proven: the claim survives NAS reboots, with no automatic release.
  - Recovery: the audited runbook, on NAS-side proof only.
- **Late guard (2000 ms) limits.** Measured age p99 was 3.0 s only during injected stalls; normal requests take 6–300 ms. The guard covers delays FreeRADIUS can see. It does not cover kernel queueing (AV-1) or an Accept lost on the network (AV-3).

## 11. Regressions

- **Harness WP4 / WP5A / WP5B:** final run with the final site on both engines; see §5.
- **WP8 lab suite** (`wp8-integration`, 69 tests): 4 failures in the first run.
  1. `CoaDisconnectTest`: **caused by an A2-R bug** (negative request age). Fixed; re-run OK.
  2. `OptionGGateTest::GateI`: a synthetic Accept left an uncertain claim. The test now sends the synthetic Stop. Re-run OK; A1 assertions unchanged.
  3. `FailureDrillsTest::testDrill1and6`: **AV-1**. Expected under Strict Mode; not modified; open.
  4. `RealAaaTest::testPppoeDynamic…`: **pre-existing test defect** (A1 commit `609dc4db`). It lowercases the radacct value but not the API session-id. It surfaces once the lab Acct-Session-Id counter reaches hex letters (`0x…C2`); it is not caused by A2-R. Not modified.
  - The 3 PHP warnings (`Message.php $root_path`) and 1 deprecation are pre-existing (A1 NOTE-01).
- **A2 security gate suite.** `ConcurrentFirstLoginGateTest`, all 7 scenarios, OK. After the final fix, B0, C and E3 were re-run, OK.
  - Lab total: **67 race trials** — B0 20, Bscan 21, C 12, E2 5, E3 6 SINGLE_SESSION; E1 3 NO_SESSION (fail closed) — **0 double sessions.**

## 12. Secret and cleanup checks; disclosed defects and superseded records

- **Secret sweep.** 27 changed or new files plus the evidence: 0 hits for lab secrets, private keys, test passwords or community-shaped values.
- **Manifest.** `docs/claude-development-pack/MANIFEST.sha256`: 42 files verified, unchanged.
- **Disclosure.** During a diagnostic, the test-only lab SNMP community was printed to the operator console twice. It is in no file or evidence. It was rotated by deleting `snmp.env` and the guard credential file; the next lab run generates a new one.
- **Lab cleanup.** Fully down (0 links, netns, QEMU, agent, containers, networks), including CHR2 and the MySQL container. The working disks are deleted. AVAIA, production and the other services were not touched.
- **Implementation defect found by the gates and fixed.** Request-age read order. Of 454 requests, 2 got a negative age: one refused login (CoA test) and one released claim in a race trial (refused). Both failed closed. Revalidated after the fix: G-CLASS basic, late claim, DB interruption, synthetic retransmission / lifecycle / SQL faults, cross-NAS, runbook, B0 / C / E3, S-1.
  - Gates run only before the fix: lost Start, Stop without Start, accounting retransmission, sequential sessions, lost Stop, real replay, NAS deadline, S-1 real, Hotspot, FreeRADIUS restart, verifier faults, SQL concurrency, S-1 synthetic. Their outcomes do not depend on the late guard; the fix can only remove false late rejections.
- **Superseded records** (kept unchanged in `lab-evidence.jsonl`):
  - NAS deadline 18:07Z: delay measured from capture start, not from the first request.
  - DB interruption 18:11Z: the pause landed after FreeRADIUS had already answered.
  - Lifecycle 18:13Z: the attacher carried the fault marker.
  - SQL faults 18:15Z: the kill target was not found in time.
  - Cross-NAS 19:46Z / 19:47Z: MAC collision.

## 13. Git status

- `wp8/integration-poc` = `cd09a589` (local = origin). Nothing committed, pushed or merged.
- Modified: 12 files (the diff stat in §2).
- New: `install/freeradius/nuxr_admission{,_check,_release}.sql`, 4 test files, 2 lab tools, `owner-review/WP8/evidence/a2r-feasibility/`, and `owner-review/WP8/evidence/a2r-implementation/`.

## 14. Remaining production release blockers

1. **H-1** Hotspot username-equivalence bypass (proven, separate scope).
2. **AV-1** FreeRADIUS stall → abandoned attempts become PENDING claims → refused until operator recovery. Needs an owner decision: accept with operations monitoring and runbook, or a separate design.
3. **AV-2** SQL pool sizing per ISP (contract item; production load test not done).
4. **AV-3** uncertain claims by design (Strict). The runbook is proven; the frequency in production is unmeasured.
5. `RealAaaTest` pre-existing case defect (test only).
6. Carried, unchanged: R-WP8-CARRY-01 / 02 / 03, R-HF-CARRY-01, and the A1 notes (NAS type `mikrotik_snmp`, FreeRADIUS 3.2.8 only, legacy Hotspot `Port-Limit`).
7. Not production evidence: FreeRADIUS in the lab is a single container with the derived image, on CHR under TCG (latencies are not production figures).

`A2_R_PASS_PENDING_OWNER_ACCEPTANCE`
