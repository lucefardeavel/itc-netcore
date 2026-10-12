# WP8 H-2B — Owner Acceptance Record

**Status: `H2B_LAB_ACCEPTED_PRODUCTION_BLOCKED`**

This records that the owner accepted the H-2B **lab results**. It does **not** authorize production, deployment or the
closure of WP8.

| Item | Value |
|---|---|
| Owner decision | 2026-10-12 ("H-2B Acceptance & A2-R Risk Disposition", then "Option 1 Approved") |
| Source report | `evidence/h2b-impl/H2B_IMPLEMENTATION_REPORT.md` (verdict there: `H2B_IMPLEMENTATION_READY_FOR_OWNER_ACCEPTANCE`) |
| Evidence folder | `evidence/h2b-impl/` (unchanged by this record) |
| Baseline | `wp8/integration-poc` @ `1537775555b3a940e32c8103df4c98bb0549ddf1` = origin. The H-2B change is **uncommitted** |
| Basis | The existing report, evidence and owner decisions only. No test was re-run and no new security analysis was made for this record |

## 1. Scope accepted (lab results)

H-2B = R-H2-B: Hotspot `shared_users` enforced across every NAS of one ISP. Implemented under the owner authorization of
2026-10-11 ("AUTHORIZED FOR DEVELOPMENT & LAB IMPLEMENTATION ONLY").

**What was implemented:**
- **Slot table** `nuxr_hs_admission`, plus the audit table `nuxr_hs_admission_audit`.
  - One row per (canonical subscriber, slot); the canonical subscriber is the exact `radcheck.username` bytes (H1-A identity).
  - Slots run from 1 to Simultaneous-Use.
  - CHECK constraints refuse an empty or malformed token or claim identity.
  - Additive, re-runnable migration `install/freeradius/nuxr_hs_admission.sql`.
  - Read-only readiness check `nuxr_hs_admission_check.sql`.
  - Read-only monitoring report `nuxr_hs_admission_report.sql`.
- **Claim v2:**
  - A non-locking pre-check that echoes its key and limit, then one conditional `UPDATE … ORDER BY slot LIMIT 1`.
  - Correlated by Class `nuxh1` + a 128-bit token.
- **Strict PENDING:**
  - A PENDING slot is never released by time, by SNMP or by another request.
  - The only self-releases are by the creating request, before any reply: `late_claim` and `claim_failed`.
  - **No executable manual release exists.**
- **Hotspot slot verifier** `nuxr_hotspot_slot_verifier`:
  - Read-only SNMP check of the MIKROTIK-MIB Hotspot active table (user + MAC) after a health probe.
  - Acts on ACTIVE slots only. It never acts on PENDING and does not use checkrad.
  - Any error or ambiguity refuses the login and keeps the slot. Each release is audited first.
- **FreeRADIUS site** `install/freeradius/sites-available/phpnuxbill`:
  - H-2B block placed after H1-A, plus the `nuxh1` accounting block.
  - The stock Simultaneous-Use check is bypassed for Hotspot requests only.
  - Port-Limit is kept as a local layer.
  - Fail-closed when a table or component is missing.
- **Docs:** README section "Hotspot admission slots (WP8 H-2B)" and the `docs/WP4_RADIUS_MODEL.md` addendum.
  The recovery runbook is a design only.

**Not changed:**
- A2-R and H1-A policy blocks, Option G, PPPoE and VPN behaviour.
- `install/radius.sql` and `system/` (0 diff lines). Manifest 42/42.

## 2. Test evidence (actual results and exit codes)

### 2.1 Real NAS lab: FreeRADIUS 3.2.8 (threaded), RouterOS CHR 7.24.5 ×2 (CHR1, CHR2)

Final site, run 2 (2026-10-11 13:43–14:56Z); run 1 gave identical gate results.

| Gate | Result (report §7) |
|---|---|
| `gc1` limit 1, cross-NAS both directions, 10× simultaneous | 12/12 ENFORCED, 0 exceeded |
| `gc2` limit 2, 4 clients on 2 NAS | 6/6 ENFORCED |
| `gc3` limit 3, 5 clients on 2 NAS | 5/5 ENFORCED |
| Synthetic burst, 16 simultaneous, limits 1/2/3/5 × 3 rounds | exactly `limit` admitted each round; slots = Accepts; all ended by their Stops |
| Concurrent decrease 3→2, 16 simultaneous × 5 rounds | never above the new limit |
| PENDING stall (RouterOS timeout 3 s and 10 s), verifier-never-PENDING, lost accounting, SNMP/NAS faults incl. CHR2 reboot, component failures, retransmission, timing | pass in run 2 |

**Evidence counts:**
- `lab-evidence-h2b.jsonl`: 67 records, of which 53 carry `"pass": true` and 1 `"pass": false`.
  - The failure is `H2B.IMPL.synthetic_burst`, run 1, 12:29Z: the empty-expansion finding. It was fixed and kept with a NOTE record (report §9.3).

### 2.2 Regression on the same lab

| Suite | Evidence | Result |
|---|---|---|
| A2-R PPPoE | `lab-regression-a2r.jsonl` (55 records: 49 `pass: true`, 3 `pass: false`, 1 NOTE) | All 3 failures are `G-RETX.synthetic` (14:48:59Z, 14:57:50Z, 14:58:39Z). Cause: lab log latency (`docker logs` 5.81 s > `cleanup_delay` 5 s), see the NOTE record. It passed twice after the lab FreeRADIUS log was reset. Kept as failures, not counted as passes |
| H1-A | `lab-regression-h1a.jsonl` (30 records: 24 `h1a_pass: true`, 3 `h1a_pass: false`, 1 NOTE) | All 3 failures are `H1.lost_accounting`: the expected Strict PENDING availability effect (§3). The H1-A property itself held. Kept as failures |
| H-1 discovery case | `HOTSPOT.username_variants` | `bypass_proven = false` |
| Lab PHPUnit `wp8-integration` | `lab-phpunit-wp8-integration.out` | **exit=1**: 69 tests, 1203 assertions, 1 failure, 3 warnings, 1 deprecation, 13 skipped. The failure was the test heuristic `OptionGGateTest` `guard_ran`; the test was fixed. The 3 warnings are pre-existing in `system/autoload/Message.php` |
| Option G Hotspot tests, re-run after that fix | `lab-phpunit-evidence.jsonl` records `OD16G.hotspot.*` (15:46–15:50Z) | 3/3 OK per report §9.4. **The PHPUnit exit code of this re-run was not saved to a file** |
| VPN | `vpn_untouched` | stock Simultaneous-Use check unchanged; no slot, no `nuxh1` Class |

### 2.3 MariaDB / MySQL

**SQL stress `gate_c_sql`** (25 concurrent sessions × limits 1/2/3/5 × 5 rounds):
- MariaDB 10.11.19: never above the limit, 0 errors.
- MySQL 8.0.46: never above the limit, 0 errors.

**Full harness** `dev/test/run-tests.sh` (`harness-both-engines.out`), **exit=0**:

| Engine | Result line |
|---|---|
| MariaDB 10.11.19 | `OK, but there were issues!` — `Tests: 1015, Assertions: 67746, Incomplete: 79.` |
| MySQL 8.0.46 | `OK, but there were issues!` — `Tests: 1015, Assertions: 67746, Incomplete: 79.` |
| Variant registry reproducibility | `variant registry matches freshly built fixtures` |

**The 79 incomplete tests are NOT passes.** They are the lab-gated WP8 tests, which do not run without the real-NAS lab.
They are the same 79 as the TI-02 baseline, and exit code 0 does not change their status.

### 2.4 Quality gates (report §14)

- FreeRADIUS configuration check (`-XC`): **not recorded** in the report or the evidence folder. It must not be cited
  as evidence until it is re-run and kept.
- Independent code review: 0 critical/high. M1, M3 and L1–L8 fixed; M2 documented as a production prerequisite.
- Secret sweep: 0 hits.
- Manifest 42/42.
- Backups verified (SHA256SUMS):
  - `/srv/phpnuxbill/wp8-h2b-impl-prefix-backup-20261011T112613Z` (33 files);
  - `/srv/phpnuxbill/wp8-h2b-pending-prefix-backup-20261011T102103Z` (13 files).
- Protected refs unchanged. Lab down and clean.

## 3. Strict PENDING: availability impact (accepted as a limitation, not resolved)

Strict PENDING prevents any automatic release. That is the safety property: an Accept stalled inside FreeRADIUS can
still be taken by the NAS later. **It carries an availability risk.** A PENDING slot that never receives its token's
accounting blocks that slot; with `shared_users = 1` the subscriber is refused on every NAS. There is no executable
release.

**Known causes (report §11):**
- every reply lost, or arriving after the NAS window (~9 s);
- a FreeRADIUS stall longer than ~9 s;
- the NAS failing between the Accept and the Start;
- accounting lost for the whole session (`H1.lost_accounting`);
- a lost claim result under SQL pool exhaustion.

**Other effects:**
- After a lost Stop, the next login needs the slot's NAS to answer SNMP.
- A limit decrease refuses new logins while a slot above the new limit is live.
- A refusal on the verifier path takes about 1–1.8 s.
- **Sessions already live when H-2B is enabled hold no slot and are not counted until they end.**

## 4. Open operational and production requirements

1. **PENDING recovery procedure:** a separate owner decision with its own safety gate. Nothing executable exists. The
   runbook (README) is a design only, and allows a read-only report and the `pending_over_1_minute` alert.
2. **Read-only production impact queries** (separate authorization):
   - Hotspot subscribers without `Simultaneous-Use`;
   - Hotspot NAS rows and their SNMP reachability and credentials;
   - duplicated `NAS-IP-Address` (review M2);
   - non-MAC `Calling-Station-Id` formats.
3. **SQL sizing per ISP:** `pool.max ≥ thread max_servers`, plus a database `max_connections` that covers every pool and
   the application. These are necessary conditions, **not proof** that every SQL failure is avoided (see §5).
4. **NAS contract for every Hotspot NAS:**
   - RouterOS 7, with read-only SNMP from FreeRADIUS and a `nas_snmp` entry;
   - Hotspot accounting and interim on, `/radius timeout=3s`;
   - a unique `NAS-IP-Address` equal to `nas.nasname`.
5. **Monitoring** of `nuxr_hs_admission_report.sql`.
6. **Rollout order and timing**, because live sessions are not counted at enable time.
7. **Commit of the H-2B change:** only after a separate owner instruction (owner workflow).

## 5. Separate issue: A2-R SQL Exhaustion (not part of this acceptance)

- **Classification (owner, 2026-10-12): `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`.**
  - The risk is not proven in practice.
  - The risk is not considered safe merely because it could not be reproduced.
- Origin: the empty-expansion behaviour seen in the H-2B lab (report §9.3), which A2-R PPPoE statements could share.
  H-2B has its own defences: CHECK constraints and the echoed pre-check.
- The focused reproduction was **halted by the owner** after repeated safety-classifier stops. It must not be retried
  or worked around.
- Preventive hardening of A2-R is approved for **study only**; no code change has been made. The change plan is a
  separate document for a later round and **is not prepared in this round**.
- This issue does not change the H-2B lab results above. It blocks production independently.

## 6. Remaining decisions

| Item | Status |
|---|---|
| H-2B production deployment | blocked (§4, §5) |
| PENDING recovery (H-2B Hotspot; A2-R runbook scope unchanged) | needs a separate owner decision |
| A2-R SQL hardening plan | approved for study; not started in this round |
| V-1 | not started |
| A3 | not started |
| WP8 closure | not granted |

## 7. Official status

**`H2B_LAB_ACCEPTED_PRODUCTION_BLOCKED`**

- Lab results: accepted.
- Production: blocked.
- WP8: open.
- Nothing committed, pushed, merged or deployed.

---

## 8. Addendum 2026-10-12 — Final owner decision: `H2B_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY`

This section is added. §1–§7 above are kept as written; they recorded the status at that time
(`H2B_LAB_ACCEPTED_PRODUCTION_BLOCKED`).

**Decision.** The owner accepts the H-2B implementation and its lab results, and authorizes one development commit on
`wp8/integration-poc` from the baseline `1537775555b3a940e32c8103df4c98bb0549ddf1`.
- **Not authorized:** production deployment, merge, or the closure of WP8.
- **Production stays blocked** by §4 and §5.

**Verification gaps closed** (`evidence/h2b-verify/H2B_VERIFICATION_GAPS.md`; the original evidence in `evidence/h2b-impl/`
is unchanged):

| Check | Actual result | Exit |
|---|---|---|
| FreeRADIUS 3.2.8 `-XC`, harness `freeradius-mariadb` | `Configuration appears to be OK` | 0 |
| FreeRADIUS 3.2.8 `-XC`, harness `freeradius-mysql` | `Configuration appears to be OK` | 0 |
| FreeRADIUS 3.2.8 `-XC`, WP8 lab (CHR1 + CHR2, Option G `shipped`) | `Configuration appears to be OK` | 0 |
| Installed files vs repository (site, slot verifier, session guard) | byte-identical in all three containers | — |
| WP8 PHPUnit `wp8-integration` re-run after the Option G test fix | 69 tests, 1203 assertions, **0 failures, 0 errors**, 3 warnings, 1 deprecation, 13 skipped | **1** |

**About these results:**
- **Exit code 1 is the actual result.** `tests/phpunit.xml` sets `failOnWarning="true"`.
  - The 3 warnings come from `system/autoload/Message.php` and are pre-existing (also in the A1, A2-R, AV-5, H1-A and H-2B evidence).
  - The deprecation (`system/autoload/PEAR2/Autoload.php`) is pre-existing too.
  - `system/` is unchanged.
- **Skipped and incomplete tests are not passes.** The 13 skipped need other lab modes. The full harness (§2.3) keeps its
  79 incomplete per engine, with exit 0.
- **Failure records stay as they were:** every failure and its correction (§2, and `evidence/h2b-impl/` §9.4) is kept
  unchanged.

**Notes carried with the acceptance:**
- **Strict PENDING** stays as implemented, with no automatic release. Its availability limits (§3) remain.
- **Still open, none started in this round:**
  - the production requirements (§4);
  - the A2-R SQL Exhaustion hardening (§5, `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`);
  - PENDING Recovery;
  - AV-1, AV-2, AV-3;
  - MAC-H1A production compatibility;
  - V-1, A3, A4, A5, S7, S8;
  - every production carry-forward not yet closed.

**Status: `H2B_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY`.** WP8 stays open.
