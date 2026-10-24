---
name: wp8-planning-state
description: "WP8 state: plan 5de6d8e6 approved with amendment W8-A1; CHR feasibility gate PASS_LOCAL_CHR (RouterOS CHR 7.20.3 under QEMU TCG, isolated lab 10.88.x) committed on wp8/integration-poc; OD-W8-1 dropped; OD-W8-10..12 open; full WP8 not started; waiting owner"
metadata:
  type: project
---

**WP8 planning (2026-10-06).** Owner authorized WP8 as PLANNING + RECONNAISSANCE ONLY. Result:
- Branch `wp8/integration-poc` created from canonical feat `5bdc5745`; one docs-only commit `5de6d8e6` (`owner-review/WP8/PLAN.md`, 318 lines) pushed. `feat`, `wp7` (8e7f0e1c), `wp6c` (757d8de9), hotfix (6d4f3f96), `main` (500d3a2d) unchanged.
- **Verdict: NOT_READY_REAL_NAS_REQUIRED.** FreeRADIUS 3.2.8 exists as a disposable container in `dev/test/compose.yml` (no published ports). No MikroTik reachable: no WireGuard (`wg` tools absent, module present but unloaded), no private route, no CHR image; dev host is a KVM guest without nested virt (no `/dev/kvm`), so a local CHR is impossible. `ufw` allows only 22/tcp.
- Key findings: `CoAClient` uses `nas.ports` (upstream CoA-port convention) else 3799; RouterOS default `/radius incoming` is `accept=no port=1700`; README already says set router to 3799; UI label is just "Ports". NAS match is exact `radacct.nasipaddress = nas.nasname`. PEAR2 RouterOS 1.0.0b6 is plaintext-only (API-SSL would need a runtime change). Accepted activation path never calls MikroTik drivers; only `LegacyReprovisionService` does; static `Mikrotik` class is dead code.
- Owner decisions OD-W8-1 (provide staging NAS + WireGuard path, BLOCKING) … OD-W8-9 (classify OBS-W8-1: ungated `remove_customer` paths for reseller-owned customers at plan.php:443, customers.php:286, home.php:206). Observations OBS-W8-1..5 recorded, not fixed.

**CHR feasibility gate (2026-10-06, owner amendment W8-A1).** Result **PASS_LOCAL_CHR**, committed additively on `wp8/integration-poc` (`CHR_FEASIBILITY.md`, `OWNER_DECISIONS.md`, `evidence/chr-feasibility/`).
- Lab (uncommitted, `/srv/phpnuxbill/wp8lab/`): CHR 7.20.3 image (sha256 231babe7…), `rosapi.py`, `configure_chr.py`, `compose.wp8.yml` (adds docker network `wp8lab` 10.88.0.0/24, bridge `wp8lab0`, freeradius 10.88.0.10, php82 10.88.0.20), `cleanup.sh`, `redact.py`, `coa_probe.php`, `api_probe.php`, `.secrets`/`.chrpw` (never print). QEMU: `-accel tcg -cpu max -smp 1 -m 1024`, ether1 slirp restrict=on hostfwd 127.0.0.1:18728, ether2 tap→wp8lab0, ether3 tap→wp8br1 (PPPoE, netns wp8c1), ether4 tap→wp8br2 (Hotspot, netns wp8c2). Boot→API ≈ 52 s.
- Packages installed on the host: qemu-system-x86 8.2.2, qemu-utils, ppp, pppoe (+51 deps). No wireguard-tools.
- Key findings: RouterOS 7.12.1 sends no Message-Authenticator → FreeRADIUS harness `require_message_authenticator = yes` drops it silently; relaxing it was DENIED by the auto-mode classifier (security weaken) — do not retry; use NAS firmware ≥ 7.15 (OD-W8-10 A). RouterOS answers CoA only from a `/radius` server address → run app CoA from the FreeRADIUS netns (`docker run --network container:...freeradius...`) (OD-W8-11). RouterOS NAKs unknown sessions with Error-Cause 406. `pkill -f` patterns must be self-excluding (`[q]emu...`) or they kill the invoking shell.
- Locked: OD-W8-2..9 as in OWNER_DECISIONS.md; R-WP8-CARRY-01 OPEN/MEDIUM must close before production. OD-W8-1 no longer required.
- Third-party activity on `avaia-wp2-pg-*` containers (restarts 09:20–09:29Z and 10:14Z) is not ours; report, never touch.

**Implementation GO + S1 STOP (2026-10-06).** Owner accepted the gate (ad314341) and authorised S1–S8 with W8-A2 (canonical CHR 7.24.5, img sha256 e4caf173…, zip 16f07222…). In S1, verifying OD-W8-10 proved FreeRADIUS 3.2.8 SQL-loaded clients (read_clients=yes) accept Access-Requests WITHOUT Message-Authenticator even with global security.require_message_authenticator=yes; only a virtual-server policy `if (!&Message-Authenticator) { do_not_respond }` enforces it. Per owner rule WP8 STOPPED; OD-W8-13 raised (recommend A = site policy). Docs-only commit ab6c9dfa pushed (S1_STOP_MESSAGE_AUTHENTICATOR.md, evidence/s1-ma-policy). 7.24.5 smoke passed fully. Lab scripts now include fr-start.sh (modes via NUXR_WP8_GLOBAL_MA / NUXR_WP8_SITE_MA_POLICY) and ma_probe.py. Fixture API for S4+: extend ActivationTestCase, nuxr_radius via ORM 'radius', FinanceSeed::staff(..,'Sales'), pricedChain, activate(). AVAIA protected set at S0 = 24 containers (avaia-wp2-pg-* churn is concurrent/unattributed).

**OD-W8-13 GO → S1–S3 done, STOPPED on AVAIA (2026-10-06).** OD-W8-13 A implemented (site authorize MA check), OD-W8-10 closed. Commits: S1 c7811e50 (dev/wp8 lab, tests Wp8, WP4 guards; WP4 71/71 both engines), S2 b4347279 (RealAaaTest 7/7), S3 cc29b505 (CoaDisconnectTest 6/6 + STOP record). Lab: NUXR_WP8_STATE=/srv/phpnuxbill/wp8state, `dev/wp8/lab.sh up|test|down`; WP4 harness run-tests.sh must not run while the lab is up (same compose project).
- Findings: RouterOS 7.24.5 NAKs every unmatched CoA/Disconnect with 406 (never 503) → OD-W8-14 raised (recommend keep mapping); CoA-ACK for Framed-Pool/IP is ignored (address change only via disconnect+reauth); lost Stop not resent (stale radacct); Accounting-On unanswered when no open sessions (OBS-W8-7); nonexistent pool → 0.0.0.0 link.
- STOP: S3 slice-end guard: avaia-wp2-pg-dev/-full/-rehearsal removed during S3 window (unattributed; WP8 touched nothing AVAIA). STOP_AVAIA_S3.md. S4–S8 not started.
- docker events history is unreliable on this host (healthcheck exec flood) — always diff full `docker ps -a` name+CreatedAt lists per slice.

**Final resume directive → S4–S6 done, STOPPED after S6 (2026-10-06).** Owner: S1/S2 ACCEPTED, S3 ACCEPTED_WITH_NOTES, OD-W8-14 closed (406 stays FAILED), AVAIA stop cleared and AVAIA out of scope. Commits: S4 67b79099 (A→B→C business flow, 17 = 5 + 12), S5 cb29ac4f (lifecycle; OD-W8-15: username change leaves old-identity session up → 2 concurrent sessions), S6 729dbf93 (drills 9/9). Drill 7: lost accounting Stop is not resent by RouterOS → stale radacct → Simultaneous-Use rejects re-login indefinitely (checkrad "type other not checking"); only a RouterOS restart (Accounting-On) unlocks → stop condition 9, OD-W8-16 raised (recommend staleness-bounded simul_count_query in FreeRADIUS config). S7/S8 not started. Tests: Wp8ActivationLabCase base (lab-gated, ORM radius → nuxr_radius, plan AUTO_INCREMENT offset), activation.before_commit hook used to observe the real NAS pre-settlement.

**OD-W8-16 round (2026-10-07).** Owner: S4 ACCEPTED, S5 ACCEPTED_WITH_NOTE (R-WP8-CARRY-02 = old session survives username reprovision, OPEN, must close before prod), S6 ACCEPTED_WITH_BLOCKER. OD-W8-16 = Option E (freshness 180s/interim 60s + NAS Port-Limit) conditional on a real-NAS gate → gate FAILED for PPPoE (RouterOS 7.24.5 ignores RADIUS Port-Limit on PPPoE; Hotspot enforces 1 and 2). Not implemented. Observation: /ppp profile only-one=yes kills the PPPoE newcomer within ~1-2 s (one transient double entry in 3 runs). Commit 5th: s6r-gate pushed. Waiting owner.

**How to apply:** Do not resume WP8 until the owner decides after the failed PPPoE Port-Limit gate (e.g. only-one=yes variant). Commit trailer: Co-Authored-By: Claude Opus 5.5. `R-HF-CARRY-01` stays OPEN and out of scope. Never expose RouterOS API publicly.

Related: [[hotfix-pace-xhr-csrf-state]], [[wp7-planning-state]], [[phpnuxbill-dev-env]], [[shared-host-avaia-boundary]].

**OD-W8-16 second round (2026-10-07).** Owner: Option E REJECTED; `only-one=yes` observation only (ignored with RADIUS per MikroTik docs); Option F (stock checkrad `mikrotik_snmp` + `delete_stale_sessions=no`, Hotspot-only Port-Limit conditional) gate run → **Gate D FAILED (fail-open)**: snmpwalk missing → checkrad exit 0; checkrad exit 2 → `session = fail` → process_checksimul treats non-OK as under limit → Access-Accept → 2 real PPPoE sessions. A/B/C/E/Hotspot passed. Not implemented. Commit `65b96dd3` pushed. Lab tooling: `dev/wp8/checkrad_gate.{py,sh}` (modes on/fault/nas-type/off; restarts FR → run between PHPUnit runs), community in `$STATE/snmp.env`. Stock image checkrad has empty `$snmpwalk` (autoconf). New OBS-W8-8: radacct times in DB TZ (UTC) vs app clock in `staleSessions()`. Waiting owner; end token WP8_OD_W8_16_CHECKRAD_GATE_FAILED_WAITING_OWNER.

**OD-W8-16 third round (2026-10-07).** Option G (fail-closed PPPoE post-auth SNMP guard) gate PASSED in the lab, commit `47a3a02c` pushed. Partial permanent integration (5 files under install/freeradius + dev/test/freeradius/sql) was started and is UNCOMMITTED in /srv/phpnuxbill/dev. It is not verified. Lab containers were left running.

**Owner Architecture Lock (2026-10-09).** See [[architecture-owner-lock-2026-10-09]]. Its roadmap requires A1 productionize G → A2 concurrent first-login gate (NOT RUN yet) → A3–A5 close CARRY-02/03/01 → A6 S7/S8. That conflicts with the 2026-10-07 directive "continue S7→S8, do not fix carry-forwards" (conflict C-01). Do not resume WP8 until the owner decides C-01/C-02/C-03.

**A1 executed (2026-10-09). Status: A1_PASS_PENDING_OWNER_ACCEPTANCE. Uncommitted** on `wp8/integration-poc@47a3a02c` (25 modified + 8 new files).
- Backup: `/srv/phpnuxbill/wp8-a1-backup-20261009T034740Z`.
- Derived image: `nuxbill-reseller-test-freeradius:3.2.8-snmp`, built from `dev/test/freeradius/Dockerfile`.
- Lab credentials: `$STATE/radius-secrets/nas_snmp`.
- Gotchas:
  - `option_g_gate.sh` takes no mode argument; it runs the whole gate.
  - The WP8 suite needs the CHR NAS type `mikrotik_snmp` for the production contract.
  - `lastReply` is now request-local.
- Next: wait for owner acceptance, then commit and push to wp8 only. Do NOT start A2.

**A1 ACCEPTED WITH NOTES and pushed (2026-10-09).**
- Commit `609dc4db` on `wp8/integration-poc` (parent 47a3a02c); local and remote match.
- feat 5bdc5745 and main 500d3a2d unchanged.
- Notes NOTE-01..03 are in A1_RESULTS.md §6. Open release items: legacy Hotspot subscribers without Port-Limit, NAS type mikrotik_snmp, FreeRADIUS 3.2.8 only.
- Next stage: A2 (concurrent first-login gate). It needs an owner plan approval first; do not start it automatically.


**A2 executed (2026-10-09). Verdict: A2_RACE_PROVEN. Uncommitted; awaiting owner review.**
- Base: wp8 @ 609dc4db. New files: `ConcurrentFirstLoginGateTest`, `pppoe_race` agent op, `accessDecisions` helper, `evidence/a2-concurrent-first-login/`.
- B0: two logins 11.6 and 40 ms apart were both accepted (`n=0`) and stayed up as two live sessions.
- C: with the Accounting-Start lost, logins 5 s and 30 s apart gave the same double session.
- E1: fail-closed held, enforced by Option G.
- E2: single session, but only incidentally (CHR answers SNMP serially).
- Cross-NAS (D) was not run, because the race was proven first (D-A2-1).
- Proposed remediation (needs a separate owner-approved plan): R1 atomic per-username admission reservation, plus R2 accounting-time duplicate eviction.
- Lab gotchas:
  - PPPoE discovery adds up to ~3 s, so classify by the pcap request gap, not the launch offset.
  - Join FreeRADIUS decisions to the trial's own pcap on RADIUS Id + port.
- Do not start A3.

**A2 committed and pushed; A2-R decision review (2026-10-09).** A2 evidence was accepted and pushed as `cd09a589`. The A2 gate's verdict is FAILED (RACE_PROVEN), and remediation (A2-R) is NOT approved for execution. A first full A2-R plan was cut off by the safety classifier; do not re-issue it. The owner then asked for a short engineering decision document instead (10 sections, verdict token). Recommendation delivered:
- Strict mode: a PENDING or UNKNOWN reservation is never released on time alone.
- A per-attempt Class correlation token echoed in accounting, which needs empirical gate G-CLASS.
- A reservation claimed only in post-auth, after the password has been verified.
- The schema goes in a separate upgrade file; `install/radius.sql` stays untouched.

Waiting for owner decisions OD-A2R-1..9. Nothing has been implemented.

**A2-R feasibility gates (2026-10-09, LAB ONLY). Verdict: A2_R_FEASIBILITY_PASS_PENDING_OWNER_REVIEW. Uncommitted.**
- Uncommitted files: `dev/wp8/freeradius/start.sh` (markers `wp8-a2r` and `wp8-a2r-threaded`), `dev/wp8/a2r_gate.py`, `dev/wp8/a2r_sql_gate.py`, `dev/wp8/freeradius/a2r/`, and the report in `owner-review/WP8/evidence/a2r-feasibility/`.
- Results:
  - RouterOS 7.24.5 echoes `Class` unchanged in Start, Interim and Stop.
  - A lost Start is never re-sent; the first Interim confirms the session.
  - A lost Accept means 3 exact retransmissions, 3 s apart, then "radius timeout". The claim is left PENDING forever.
  - The Acct-Session-Id high nibble counts boots (810 → 811 → 812) on the same disk.
  - The race re-run (B0, C, E3) gave 19/19 SINGLE_SESSION.
- NEW finding S-1: accepted A1 allows 2 sessions under case or accent spellings, because the guard compares the name byte-exactly.
- Lab gotchas:
  - `freeradius -X` is single-threaded; use the threaded marker for concurrency tests.
  - The rlm_sql xlat returns empty both on an SQL error and when 0 rows are affected.
  - SQL pool max is 8.
- Do not start implementation or A3 without the owner.

**A2-R implemented and gated (2026-10-09). Verdict: A2_R_PASS_PENDING_OWNER_ACCEPTANCE. Uncommitted; NOT production-ready.**
- Implementation (in install/freeradius): `nuxr_admission.sql`, `_check.sql`, `_release.sql`, the site post-auth and accounting, and a README contract. `system/` and `radius.sql` are untouched.
- Report: `owner-review/WP8/evidence/a2r-implementation/A2R_IMPLEMENTATION_REPORT.md`.
- Backup: `/srv/phpnuxbill/wp8-a2r-prototype-backup-20261009T172236Z`.
- Test results:
  - Harness: 274/5788 on both engines.
  - Lab: 67 race trials with 0 doubles; cross-NAS (CHR2) 13/13.
  - S-1 closed for PPPoE.
- Open blockers:
  - H-1: Hotspot spelling bypass (proven).
  - AV-1: a FreeRADIUS stall leaves orphan PENDING claims (drill 6 test fails).
  - AV-2: SQL pool sizing.
- Gotchas:
  - Never run `dev/test/run-tests.sh` while the lab is up: same compose project, and its teardown kills the lab containers.
  - QEMU gives every VM the same default MACs; CHR2 needs explicit MACs.
  - `%{Packet-Authentication-Vector}` works only directly, not inside `%{hex:}`.
  - Read the age as `%C` before `%c`.

**A2-R final acceptance closure round (2026-10-09). Verdict: A2_R_BLOCKED_WITH_EVIDENCE. Uncommitted.** Report: `owner-review/WP8/evidence/a2r-closure/A2R_FINAL_CLOSURE_REPORT.md`. Backup: `/srv/phpnuxbill/wp8-a2r-closure-backup-20261009T210332Z`.
- **AV-1 not closable inside A2-R.** Reproduced on the real CHR (lab case `a2r_gate.py run av1_stall_timeline`): a 10.8 s kernel queue, but the policy saw an age of 22 ms. FreeRADIUS 3.2.8 timestamps a packet at socket read (`process.c` `request_receive`) and has no SO_TIMESTAMP; a RouterOS Access-Request has no timestamp. The owner must choose D1 (accept with ops controls and change the Drill 6 expectation), D2 (separate auto-release design) or D3 (supervisor).
- **AV-4 (new).** The 2000 ms late budget is below A1's worst case (stock checkrad plus a guard of up to 3 s). Drill 7G failed in 1 of 4 runs (request took 4348 ms: reject:late). Recommendation AV4-B: a budget of about 5000 ms plus re-validation. Needs an owner decision.
- **Closed:** CoA (age-order fix); Option G gate I (91/91 assertions unchanged; synthetic Stop); RealAaa was a test defect from S2 `b4347279`, not A1 (API session id upper case vs RADIUS lower case), now fixed with strtolower on both sides.
- **AV-2:** pool.max 32 (= max_servers) gave 0 pool errors; pool.max 8 gave 166. Lab marker `a2r_gate.py pool N|off`.
- **H-1 scope proposed:** H1-A, an exact-spelling rule for Hotspot in post-auth. Do not start without owner approval.

**A2-R owner final decisions round (2026-10-10). Verdict: A2_R_READY_FOR_OWNER_ACCEPTANCE_WITH_NOTES. Uncommitted; awaiting final owner approval.** Owner decided D-AV1 = D1 (AV-1 accepted as a documented Strict Mode limitation; still an OPEN production risk) and D-AV4 = AV4-B (5000 ms budget). The late budget is now 5000 ms in the site; the real-NAS boundary measured 3.5/4.5 s admitted and 5.5/6.5 s refused. Drill 1&6 was rewritten to verify the Strict Mode contract: residual PENDING claim → refused → audited release → served.
- **Results:** WP8 suite 69 tests / 1203 assertions, 0 failures, 13 skipped, exit 1 caused only by 3 pre-existing Message.php warnings under failOnWarning (the A1 baseline shows the same). Security gates 8 / 194, exit 0, 48 race trials with 0 doubles. Harness 274 / 5791 OK on both engines.
- **Report:** `owner-review/WP8/evidence/a2r-final/A2R_OWNER_FINAL_ACCEPTANCE_CANDIDATE.md`. Backup: `/srv/phpnuxbill/wp8-a2r-final-backup-20261010T063516Z`.
- **Next:** wait for the owner's final acceptance before any commit. H-1 must be closed before production and before the WP8 closure; do not start H-1 or A3 automatically.

**A2-R ACCEPTED_WITH_NOTES and pushed (2026-10-10).** Commit `5ea597d2b74d0f0b89c5bc7ef1f6eef172c3ced2` (parent cd09a589, 42 files) is on origin/wp8/integration-poc. feat (5bdc5745) and main (500d3a2d) are unchanged. The OWNER_DECISIONS.md A2-R record was appended. Next is the H-1 plan only, READ-ONLY. Do not implement H-1 or start A3 without new approval.

**H-1 H1-B feasibility (2026-10-10, lab only). Verdict: H1_B_FEASIBILITY_FAILED.** RouterOS 7.24.5 Hotspot ignores a canonical User-Name in the Access-Accept: the active session, the accounting and the Port-Limit count all keep the typed spelling (variants gave 2 sessions at Port-Limit 1, 3 at Port-Limit 2).
- **Lab-tooling lesson:** in post-auth, `%{SQL-User-Name}` is empty unless rlm_sql is primed first with `%{sql:SELECT '1'}`. The first H1-B run was invalid because of this; a correction was recorded.
- **Facts found:** MAC login sends the MAC in upper case; a cookie re-login sends the typed spelling through RADIUS.
- **H1-A fallback plan proposed** (refusal identical to a wrong-password refusal). New candidates: H-2 (no global shared_users across NAS) and V-1 (VPN). Neither is verified.
- **State:** uncommitted lab tooling (`dev/wp8/h1_gate.py`, the start.sh marker `wp8-h1-site`) and the evidence folder `owner-review/WP8/evidence/h1-feasibility/`. Waiting for the owner to approve H1-A.

**H-1 H1-A implementation (2026-10-10, lab only, uncommitted). Verdict: H1_A_SECURITY_PASS_WITH_OPEN_FINDINGS.**
- **What changed:** post-auth now has a Hotspot block (Login-User + Wireless-802.11) placed after the PPPoE branch. It primes SQL-User-Name, then a sentinel query returns the count of byte-distinct stored spellings plus the stored hex, and that hex must equal `%{hex:&User-Name}`. Every reject runs attr_filter with no Reply-Message, so it looks like a wrong password.
- **Results:** harness 283 / 5902 on both engines; security gates 10 / 216, exit 0, 48 race trials with 0 doubles. WP8 suite 69 / 1199 with 1 failure (RealAaa, caused by AV-5), exit 1.
- **AV-5 (open):** the A2-R age mixes `%C` (gettimeofday) and `%c` (coarse time(), which can lag up to about 4.6 ms after a second boundary). Negative ages cause false rejects, and when the true age is 1 s or more it under-estimates by 1000 ms. Proposed fix: one `"%C %c"` expansion plus a +1000 correction when `%C` < 20000. Proven by the av5probe lab case; NOT applied, needs owner approval.
- **MAC-H1A (open):** with `mac-as-username`, a lower-case stored MAC worked before H1-A and is refused by it. With `mac-as-username-and-password` it is refused in both. This corrects the H1-B report.
- **Lab tooling:** dev/wp8/h1_gate.py site modes project / pre / h1b / fault / av5 / av5probe. Evidence: owner-review/WP8/evidence/h1a/. Backup: /srv/phpnuxbill/wp8-h1a-baseline-backup-20261010T094908Z.

**H1-A + AV-5 final closure round (2026-10-10, uncommitted). Verdict: H1_A_READY_FOR_OWNER_ACCEPTANCE_WITH_NOTES.** Report: `owner-review/WP8/evidence/av5/H1A_AV5_FINAL_ACCEPTANCE_CANDIDATE.md`. Backup: `/srv/phpnuxbill/wp8-av5-prefix-backup-20261010T143836Z`.
- **AV-5 fix:** both age blocks now use `%{expr:(%{time_since:us} - %l * 1000000 - %M) / 1000}`. `time_since` comes from the stock rlm_date module (one gettimeofday call); without `mods-enabled/date` FreeRADIUS refuses to start. The owner REJECTED the "+1000 ms in the first 20 ms" proposal.
- **Evidence:** a 50k-request micro-study (new formula 0 bad, old 28 negatives); `h1_gate.py` modes `av5hold` / `av5hold_pre` with case `av5_boundary`; real-NAS 3.5/4.5 s admitted, 5.5/6.5 s refused.
- **Results:** WP8 suite 69/1203 with 0 failures but exit 1 (Message.php warnings). Security gates 10/216, exit 0. Full harness matrix is 980 tests per engine and exits 1 due to a pre-existing AppServer defect: orphaned `php -S` workers serve 404 on a reused random port (proven with a probe; a different HTTP test class hits it each run).
- **Fixed:** my `HotspotEquivalenceGateTest` `tearDown` must return when the lab is down. `a2r_gate.fr_requests` age parser now accepts both formulas.
- **Lab gotcha:** `slow_guard_lost_stop` at 2.5 s has only 70–250 ms of margin to the 3 s guard timeout under TCG; it failed once.
- **Next:** wait for owner acceptance. No commit, push, H-2, V-1 or A3.

**H1-A + AV-5 ACCEPTED_WITH_NOTES and pushed (2026-10-10). Status: H1_A_AV5_ACCEPTED_PUSHED_VERIFIED.**
- **Commit:** `fcf54a7a763d185079b0e1b92654069851f9c757` on origin/wp8/integration-poc (parent 5ea597d2, 40 files). The owner record is appended to OWNER_DECISIONS.md.
- **Protected branches unchanged:** feat 5bdc5745, main 500d3a2d, hotfix 6d4f3f96, wp7 8e7f0e1c, wp6c 757d8de9.
- **Pre-commit backup:** /srv/phpnuxbill/wp8-h1a-av5-precommit-backup-20261010T193610Z.
- **Reports (outside the repo):** /root/Downloads/WP8_H1A_AV5_GITHUB_FINALIZATION_REPORT.md, and /root/Downloads/WP8_NEXT_INVESTIGATION_PLANS.md with plans A (AppServer setsid / process-group kill), B (H-2 needs an owner contract decision first: per-NAS H2-C1 vs per-ISP H2-C2; then a CHR2 Hotspot test) and C (V-1 L2TP test with CHR2 as the client).
- **Next:** nothing started. Do not start A3, H-2, V-1 or the AppServer fix without owner approval.

**AppServer test-infrastructure repair (2026-10-10, uncommitted). Verdict: APPSERVER_FIX_READY_FOR_OWNER_ACCEPTANCE.** Report: `owner-review/WP8/evidence/appserver/APPSERVER_REPAIR_REPORT.md`. Backup: `/srv/phpnuxbill/wp8-appserver-prefix-backup-20261010T195044Z`.
- **Fix:** `php -S` (4 workers) now starts under `setsid`, so it has its own process group. `stop()` signals `-pgid` only after checking /proc that the processes' group and session match, does a final sweep, and `proc_close`. Busy ports are skipped. The server counts as ready only when 1 master + 4 workers are alive and it answers a random sentinel. 6 lifecycle tests in `tests/Http/AppServerLifecycleTest.php`.
- **Results:** full harness run 2 exit 0 on both engines (986 / 67263). Run 1 exit 1 from a separate, pre-existing StubNas race (CoAClientTest; reproduced 5 of 600). Proposed StubNas fix: atomic log write and wait for the stub to exit. Not fixed.
- **Gotchas:**
  - The test image has no pcntl and no ps; use numeric signals.
  - `php -S` command lines contain `-d` options before `-S`.
- **Next:** wait for owner acceptance, then commit. H-2 (CHR1+CHR2) comes next, then V-1. Do not start them without go.

**AppServer ACCEPTED_WITH_NOTES and pushed (2026-10-11). Status: APPSERVER_FIX_ACCEPTED_PUSHED_VERIFIED.**
- **Commit:** `51e9915d388c2306f58d3ac6d830e70bc05fc8ab` on origin/wp8/integration-poc (parent fcf54a7a, 14 files). The owner record is in OWNER_DECISIONS.md.
- **TI-02 (StubNas logging race):** OPEN; documented in `evidence/appserver/TI-02_STUBNAS_LOGGING_RACE.md`.
- **StubNas plan:** `/root/Downloads/WP8_APPSERVER_FINALIZATION_AND_STUBNAS_PLAN.md`. Verdict STUBNAS_PLAN_READY_FOR_OWNER_APPROVAL. The plan:
  - stub publishes its log atomically (tmp file + rename);
  - `received()` waits for the stub process to exit, and throws instead of returning `[]`;
  - constructor retries or throws on a bind failure;
  - new `StubNasReliabilityTest`; probe run 1000 times before and after the fix.
- **Next:** nothing started. Do not implement StubNas, H-2, V-1 or A3 without go.

**TI-02 StubNas repair (2026-10-11, uncommitted). Verdict: STUBNAS_READY_FOR_OWNER_ACCEPTANCE.** Report: `owner-review/WP8/evidence/ti02/TI02_STUBNAS_REPAIR_REPORT.md`. Backup: `/srv/phpnuxbill/wp8-stubnas-prefix-backup-20261011T053050Z`.
- **Fix:**
  - `stub_nas.php` publishes its log atomically (tmp file + rename). It checks port exclusivity via /proc/net/udp, because PHP's SO_REUSEADDR makes UDP port collisions silent and ext/sockets is not in the image.
  - `StubNas` retries other ports on bind/port_in_use. `received()` waits for the stub to exit, validates the JSON, and throws on timeout or malformed log. `release()` acts only on its own proc handle. New `StubNasReliabilityTest` (8 tests).
- **Results:** race probe before 6/7000, after 0/7000. 20 focused repetitions OK (29/305). Full harness ×2 exit 0 (994 / 67305 per engine). Two development focused runs failed; they are kept as evidence.
- **Next:** wait for owner acceptance, then commit. H-2, then V-1. Do not start them without go.

**TI-02 ACCEPTED_WITH_NOTES and pushed (2026-10-11). Status: TI02_ACCEPTED_PUSHED_VERIFIED.**
- **Commit:** `1537775555b3a940e32c8103df4c98bb0549ddf1` on origin/wp8/integration-poc (parent 51e9915d, 17 files). The owner record closes TI-02 as TI02_ACCEPTED_WITH_NOTES.
- **Port-check note:** /proc/net/udp is post-bind detection, NOT atomic. It excludes stub-vs-stub collisions but not a later non-stub SO_REUSEADDR binder. A report addendum corrects "never a shared port".
- **Report:** /root/Downloads/WP8_TI02_GITHUB_FINALIZATION_REPORT.md.
- **Next:** H-2 (CHR1+CHR2, an owner contract decision first), then V-1. Both need new approval, as does A3.

**H-2 cross-NAS Hotspot feasibility (2026-10-11, lab only, uncommitted). Verdict: H2_GAP_CONFIRMED.** Report: `owner-review/WP8/evidence/h2/H2_CROSS_NAS_FEASIBILITY_REPORT.md`. Owner criterion: shared_users is a GLOBAL limit across all NAS.
- **Cause:** RouterOS Port-Limit applies per NAS. The FreeRADIUS Simultaneous-Use check calls stock checkrad mikrotik_snmp, which looks for `<..-user>` interfaces only (PPPoE), so a live Hotspot session is never seen ("not logged in", exit 0) and the login is Accepted.
- **Results, shipped mode:** 28 of 29 multi-NAS trials exceeded (shared_users=1 gave 2 sessions, shared_users=2 gave up to 4).
- **Results, other modes:**
  - Functional SNMP still exceeds.
  - SNMP blocked gives a fail-open Accept that arrives after the NAS timeout; correction record kept.
  - NAS type `other` is enforced sequentially, but concurrent and lost-Start logins still exceed, and a lost Stop locks the user out.
- **Remediation proposals (not implemented):** R-H2-A NAS binding (simplest), R-H2-B Hotspot reservation extending A2-R, R-H2-C verification guard (insufficient alone).
- **Lab tooling:** `dev/wp8/h2_gate.py` and lab.sh cleanup entries for wp8c7/wp8c8. CHR2 Hotspot on ether3 at 10.88.12.1.
- **Backup:** /srv/phpnuxbill/wp8-h2-prefix-backup-20261011T081453Z.
- **Next:** wait for the owner's remediation decision. Do not start V-1 or A3.

**H-2 R-H2-B architecture feasibility (2026-10-11, lab only, uncommitted). Verdict: H2_B_FEASIBLE_READY_FOR_DESIGN_APPROVAL.** Report: `owner-review/WP8/evidence/h2b/H2B_ARCHITECTURE_FEASIBILITY_REPORT.md`. Owner chose R-H2-B (rejected R-H2-A, R-H2-C and NAS type `other` as general solutions).
- **Model:** table `nuxr_hs_admission` with PK (canonical username, slot); slots 1..shared_users.
  - The claim is a plain `UPDATE ... WHERE slot<=limit AND state='ENDED' ORDER BY slot LIMIT 1`, preceded by a non-locking pre-check.
  - Claim v1 put a COUNT subquery inside the UPDATE and deadlocked on MySQL 8 (347 of 500); kept as evidence.
  - Class "nuxh1"+token; accounting moves the slot to ACTIVE/ENDED.
  - Recovery uses an SNMP verifier on MIKROTIK-MIB Hotspot table 1.3.6.1.4.1.14988.1.1.5.1.1 (col 3 user, 6 MAC), with a health probe first.
- **Gates:** A, B, C (23/23 on the NAS; SQL on both engines with 0 errors), D and E all pass.
- **Gotcha:** rlm_sql returns "" for a write that affected 0 rows; verify such writes with a SELECT.
- **Lab tooling:** `dev/wp8/h2b_gate.py` (site modes project/hsres, schema). Backup: /srv/phpnuxbill/wp8-h2b-prefix-backup-20261011T090159Z.
- **Next:** wait for owner design approval. No implementation, V-1 or A3.

**H2-B PENDING Safety Gate (2026-10-11, lab only, uncommitted). Verdict: H2B_DESIGN_SAFE_READY_FOR_IMPLEMENTATION (with design change).** Report: `owner-review/WP8/evidence/h2b-pending/H2B_PENDING_SAFETY_GATE_REPORT.md`.
- Prototype rule (auto-release PENDING after 15 s horizon + SNMP) REJECTED: run b over-admitted (live 2) when CHR1 /radius timeout=10s (NAS window ~30 s > horizon). FreeRADIUS 3.2.8 still sends a reply after max_request_time (35 s test), so only the NAS window bounds a late Accept.
- Adopted Strict Mode (same as A2-R PPPoE): PENDING never released by time/SNMP; leaves only via own accounting, own late-claim self-release, or audited operator release (separate decision). ACTIVE may be released by the SNMP verifier.
- RouterOS 7.24.5 Hotspot NAS deadline with timeout 3s: between 8.7 s and 9.4 s; later Accepts are discarded (no session, slot stuck PENDING).
- Candidate site `hsres2` = hsres + orphan-accounting audit + stock session{} sql skipped for Hotspot (functional checkrad stalled 10 s -> late reject of legit recovery). Lab hold points live only in `hsres2_hold`.
- Backup: /srv/phpnuxbill/wp8-h2b-pending-prefix-backup-20261011T102103Z.
- **Next:** owner decision on implementation + separate decision on the operator release procedure. No V-1/A3.

**H-2B IMPLEMENTATION (2026-10-11, uncommitted on wp8 @ 15377755). Verdict: H2B_IMPLEMENTATION_READY_FOR_OWNER_ACCEPTANCE.** Report: `owner-review/WP8/evidence/h2b-impl/H2B_IMPLEMENTATION_REPORT.md`.
- **New:** `install/freeradius/nuxr_hs_admission{,_check,_report}.sql`, `bin/` + `mods-available/nuxr_hotspot_slot_verifier`. Site: Hotspot block after H1-A, nuxh1 accounting, session{} skips stock check for Hotspot only.
- **Rules:** Strict PENDING. Verifier is ACTIVE-only (user+MAC, MikroTik + RouterOS 7 health probe). Claim v2 pre-check also requires no live slot above the limit (review M1). Creator `claim_failed` release (M3). No executable release.
- **Lab finding:** FR 3.2.8 rlm_sql turns a %{..} expansion into "" on pool exhaustion. Fixed with CHECK constraints + an echoed pre-check. A2-R shares the exposure (owner finding, not changed).
- **Results:** harness 1015 tests/engine OK (79 incomplete = baseline); lab final-site run 2: all H-2B gates pass.
- **Kept failures (NOTE records):** H1.lost_accounting (Strict Mode), G-RETX.synthetic (docker logs 5.8 s > cleanup_delay).
- **Lab-tooling gotchas:**
  - `pgrep -f`/`pkill` patterns match their own waiting shell. Use output-file markers.
  - The day-long `-fxx` FR log makes `docker logs` slow; recreate the FR container.
- Backup: /srv/phpnuxbill/wp8-h2b-impl-prefix-backup-20261011T112613Z.

**H-2B owner status 2026-10-12: `H2B_LAB_ACCEPTED_PRODUCTION_BLOCKED`** (record `owner-review/WP8/H2B_OWNER_ACCEPTANCE_RECORD.md`, approved with notes). A2-R SQL exhaustion = `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`; reproduction halted by the owner after repeated safety-classifier stops (never retry or work around it); A2-R hardening plan approved for study only, not started.
- Verification gaps closed (`owner-review/WP8/evidence/h2b-verify/`): FR `-XC` OK, exit 0 on the harness (mariadb, mysql) and the lab; the installed files are identical to the repo. Lab PHPUnit wp8-integration: 69 tests, 0 failures, 13 skipped, 3 pre-existing `system/` warnings + 1 deprecation. Exit is 1 only because phpunit.xml sets failOnWarning="true". Lab down and clean.
- Next: owner decision on committing H-2B. Still nothing committed. V-1 and A3 not started.
- Gotcha: a `docker exec … sh -c '…'` + `python3 -c` one-liner tripped a built-in rm-safety check. Use direct `docker exec` and a scratchpad script instead.

**H-2B COMMITTED 2026-10-12:** owner decision `H2B_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY`. Commit `32a162d6e91012ca2638b13332b129ece29331c7` (parent 15377755; 57 files) pushed to origin `wp8/integration-poc`. main 500d3a2d, feat 5bdc5745, hotfix 6d4f3f96, wp7 8e7f0e1c and wp6c 757d8de9 unchanged. The record's §8 addendum holds the decision.
- `git diff --cached --check` found trailing whitespace only in verbatim evidence captures (same precedent as the H1-A/AV-5 commits); product, test and doc files were clean.
- Still open, none started: A2-R SQL hardening (UNVERIFIED blocker), PENDING recovery, AV-1/2/3, MAC-H1A, V-1, A3/A4/A5, S7/S8, production carry-forwards. WP8 open.

**Stage mapping fixed 2026-10-12 (owner): keep the Architecture Lock order.** `IMPLEMENTATION_ROADMAP.md` Phase A (local branch `docs/owner-architecture-lock-2026-10-09` @ 8b0ee336, manifest 10/10 OK): A3 = CARRY-02 (old session survives rename), A4 = CARRY-03 (accounting TZ), A5 = CARRY-01 (legacy removal bypass).
- `owner-review/WP8/WP8_A5_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md`: CARRY-01. Owner approved in principle: gate P1 plan delete, P2 admin deactivate and P3 portal deactivate with ServiceGate::guardCustomer, for reseller-owned customers only; no emergency stop; no UI hiding; R-4 (cron vs RENEW) is a separate check. Ownership check: manager_id can never return to NULL, so there is no gap. Not executed.
- `owner-review/WP8/WP8_A3_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md`: CARRY-02 plan awaiting approval. Fix: Radius::change_username disconnects the OLD key after the rename and returns the CoA results; LegacyReprovisionService audits old_session CONFIRMED/UNCONFIRMED. Open decisions OD-A3R-1..4.
- Both plan files are untracked; no code changed.
- A3 plan rev. 2 (2026-10-12) after the owner amendments:
  - separate `old_session` field CONFIRMED / UNCONFIRMED / NO_ACCOUNTING_EVIDENCE / NOT_APPLICABLE, never changing status;
  - CONFIRMED needs ACK + Stop within B, or NAK 503;
  - per-row targeting; an equivalent (case-only) rename uses the pre-rename snapshot; an ambiguous equal key → nothing sent;
  - Stop wait outside the tree lock, with a new audit event `reprovision.old_session`;
  - files: Radius.php, CoAClient.php (+disconnectSession), LegacyReprovisionService.php, optional DriftReport.php;
  - RadiusRest documented out of scope;
  - pending decisions OD-A3R-5..8.

**A3 BLOCKED 2026-10-12 (owner): `A3_IMPLEMENTATION_BLOCKED — DESIGN_REVISION_REQUIRED`.**
- What happened: a safety classifier stopped the production edit of Radius::change_username twice.
- Owner rule: no retry and no workaround; implementation moves to an authorized team.
- Production code is at baseline: the CoAClient change was restored from /srv/phpnuxbill/wp8-a3-prefix-backup-20261012T130010Z.
- Kept, untracked: the two new A3 tests, evidence/a3 (baseline exit 2/2), plan rev. 3 and WP8_A3_HANDOFF.md.
- New risk §R: RadiusRepository::renameUser runs DELETE target then UPDATE source with utf8mb4_general_ci.
  - Case A, equivalent rename, self-delete: code-certain.
  - Case B, another equivalent key moved or deleted: code reading only.
  - Case C, multi-plan shared key, second rename deletes the first plan's rows: reproduced (R1 REPROVISION_UNCONFIRMED; R2 customers.php may be silent).
- Pending decisions: OD-A3R-9..14.
- **A3 planning closed 2026-10-12: `A3_PLAN_APPROVED — IMPLEMENTATION_BLOCKED`.**
  - OD-A3R-9: an ambiguous rename is refused before any write, never labelled FAILED_REVERTED.
  - OD-A3R-10: the extended file review is approved.
  - OD-A3R-11: an equivalent rename is allowed only with integrity guarantees.
  - OD-A3R-12: deferred.
  - OD-A3R-13: MEDIUM blocker as a sub-defect of CARRY-02, not a new carry-forward.
  - OD-A3R-14: test fixes allowed without weakening assertions, re-run before relying on them.
  - Implementation goes to the authorized team. Do not ask the owner to re-approve A3.

**A3 implementation resumed by the owner (2026-10-13). Verdict: WP8 A3 — IMPLEMENTATION & SECURITY ACCEPTANCE CANDIDATE. Uncommitted** on wp8 @ 32a162d6. Workflow: I implement and test, ChatGPT plans and reviews, the owner approves. Permission mode is Manual.
- **Report:** `owner-review/WP8/WP8_A3_IMPLEMENTATION_ACCEPTANCE_CANDIDATE.md`. Evidence: `evidence/a3/`.
- **Harness, both engines:** exit 0, 1059 / 68221 per engine.
  - Incomplete counts were 79 on MariaDB vs 85 on MySQL. The cause was a lab test file written after the MariaDB phase had collected its tests.
- **CHR `RenameOldSessionLabTest`:** C1–C6 6/6 OK.
- **RealLifecycleTest:** flipped to old_session CONFIRMED and [old, new] = [0, 1].
- **Lab `wp8-integration`:** 75 tests, 0 failures, 13 skipped. Exit 1 only from failOnWarning: the 3 pre-existing Message.php `$root_path` warnings, the same as the H-2B baseline.
- **Lab gotchas:**
  - `lab.sh test` adds `--testsuite wp8-integration` only when called with NO arguments. Always pass `--testsuite wp8-integration` explicitly.
  - C2, and anything on CHR2, needs `a2r_gate.py chr2 setup` after `up-chr2` and `h2_gate.py setup`. Otherwise CHR2 is not a FreeRADIUS client and logins fail with "RADIUS server is not responding".
- **Next:** wait for the owner/ChatGPT review. Commit only after acceptance. Then A4.

**A3 final pre-acceptance verification (2026-10-13). Verdict: BLOCKED_WITH_EVIDENCE.** Report: `owner-review/WP8/A3_FINAL_PRE_ACCEPTANCE_VERIFICATION_REPORT.md`. Evidence: `evidence/a3/verify/`. No code changed during the verification; hash snapshots at start and end match.
- **Frozen harness:** both engines 1144 collected = 1059 Tests + 85 Incomplete, 68221 assertions, 0 skipped, exit 0.
- **Blockers:**
  - B-1 = F1, introduced by A3: the `REFUSED_AMBIGUOUS_IDENTITY` audit is a `reprovision.%` event and hides an earlier REPROVISION_UNCONFIRMED in the drift (plan §R5.4).
  - B-2 = F2, pre-existing: two Radius plans on the SAME key → ADMIN_SYNC and rename UNCONFIRMED, identical on the baseline.
  - B-3 = I1, pre-existing: PPPoE username not unique, so a rename replaces another customer's exact-key policy (§R5.2).
- **LOW A3 defects:** F3 chained-pair false refusal, F4 no-op pair UNCONFIRMED, F5 double Disconnect per same `from`, F6 UI 'w' (warning) not used, F7 misleading message, F8 R2 AMBIGUOUS not logged.
- **Proposed fixes (not applied):** FIX-1..7.
- **Owner decisions:** D-1 apply fixes, D-2 same-key, D-3 PPPoE uniqueness.
- **Probe technique:** probes live in the scratchpad and are mounted read-only at /probe. Use `$root='/repo'`. The baseline is the scope2 backup files bind-mounted read-only over /repo.

**A3 final remediation (2026-10-13). Verdict: READY_FOR_OWNER_ACCEPTANCE. Uncommitted** on wp8 @ 32a162d6.
- **Report:** `owner-review/WP8/WP8_A3_FINAL_REMEDIATION_REPORT.md`. Evidence: `evidence/a3/remediation/`. Backup before remediation: `/srv/phpnuxbill/wp8-a3-remediation-backup-20261013T145704Z`.
- **Owner decisions:** D-1 FIX-1..7 approved; D-2 same-key multi-plan is a documented residual (plan and handoff wording corrected); D-3 PPPoE / foreign-policy protection is mandatory.
- **Implementation:**
  - `renameCheck` pre-check returns OK, AMBIGUOUS_IDENTITY, IDENTITY_TAKEN or CHECK_UNAVAILABLE; refusals are audited as `REFUSED_*`, which drift ignores.
  - `nameTakenByOther` checks PPPoE usernames in R1 and R2.
  - The rename scope (`renameScope` / `targetReplaceable` / `renameApplied`) feeds `RadiusRepository::renameUser(replaceTarget)`: the in-transaction check plus a DELETE-count guard.
  - Each old key is renamed once; one Disconnect per radacctid.
  - Test seam `reprovision.before_devices`.
- **Second independent review:** findings R-1..R-8 fixed, each with a test that fails on the intermediate version.
- **Results:** focused suites 28/28 exit 0. Frozen harness 1167 = 1082 + 85 incomplete, 68517 assertions, exit 0. CHR RenameOldSessionLabTest + RealLifecycleTest 9/9, exit 0.
- **OBS-1 (out of scope, not implemented):** customers.php add-post does not check PPPoE username collisions.

**A3 ACCEPTED_WITH_NOTES and pushed (2026-10-13). Status: WP8 A3 — COMMITTED & VERIFIED.**
- **Commit:** `2628c632123b65578ce29908aa33f27d7ccaae93` on origin/wp8/integration-poc (parent 32a162d6, 65 files, +21251/−54). Remote equals local.
- **Unchanged:** feat 5bdc5745, main 500d3a2d, hotfix 6d4f3f96, wp7 8e7f0e1c, wp6c 757d8de9.
- **Acceptance record:** appended to OWNER_DECISIONS.md.
- **Left out of the commit:** `owner-review/WP8/WP8_A5_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md` (not A3). It is the only file left untracked.
- **Open notes:** OBS-1 (creation-path collisions, production blocker), same-key multi-plan, RadiusRest/L-4, L-1..L-5, R2 audit/UI, READ COMMITTED proof, Message.php warnings.
- **Next:** A4 only after the owner approves the transition. Do not start A4/A5/V-1.

**A4 (R-WP8-CARRY-03) plan ready (2026-10-13). Status: A4_PLAN_READY_FOR_OWNER_APPROVAL.** Planning only. Baseline is wp8 @ 2628c632. The plan is `owner-review/WP8/WP8_A4_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md` (untracked). The A5 plan is untouched (sha256 96f1aca7…).
- **Root cause:**
  - radacct DATETIMEs are written as FROM_UNIXTIME in the DB session time zone (server default).
  - `staleSessions` compares them with a cutoff formatted in the PHP zone (D-1). It has no production caller; lab proof [18,19] vs [18].
  - `Subscriber::session` uses strtotime in the PHP zone (D-2 wrong online/stale state; D-3 displayed times off).
  - Counters, A2-R/H-2B (UTC_TIMESTAMP), Option G and A3 are not affected. The rlm_rest reaper is consistent on both sides and out of scope.
- **Proposed fix (smallest):**
  - epoch via `UNIX_TIMESTAMP` in SQL, so comparisons and display work on epochs;
  - no schema, FreeRADIUS or `init.php` change;
  - CheckradGateTest workaround flipped after the lab proof.
- **Owner decisions:** OD-A4-1..6 (approach; UTC DB zone as release requirement; Expiration/C-05 kept separate; rlm_rest out; test flip; DST test hard-requires tz tables).

**A4 implemented (2026-10-13); ACCEPTED_WITH_NOTES and committed 8ed4cb3d (pushed to wp8 only; production blockers P-A4-1..3 in OWNER_DECISIONS).** Owner `A4_PLAN_APPROVED_WITH_AMENDMENTS`. Fix: AccountingService additive `*_epoch` (UNIX_TIMESTAMP) cols, staleSessions epoch cutoff `NOT BETWEEN cutoff AND now+300` (NULL/invalid/future reported), Subscriber sessionState/displayTime (app zone + `UTC+hh:mm`). F-5 CheckradGateTest gate B uses app clock. Tests: Unit/Wp4/AccountingTimeUnitTest, Integration/Wp4/AccountingTimeContractTest, Integration/Wp8/AccountingTimeLabTest; Subscriber360Test strengthened. Harness 1185/1099/69266/86 incomplete both engines exit 0; CHR 10/10. Facts: RouterOS 7.24.5 sends Event-Timestamp in Start/Interim/Stop (NAS clock); both engines resolve DST repeated hour to the first instant. Report `owner-review/WP8/WP8_A4_IMPLEMENTATION_AND_TEST_REPORT.md` = READY_FOR_OWNER_ACCEPTANCE; backup /srv/phpnuxbill/wp8-a4-backup-20261013T180436Z. Wp6aSourceGuard forbids the word "update" in Reseller/Ui comments. Next: wait for the formal transition; do not start A5/S7/S8.

**A5 readiness (2026-10-13).** Baseline 8ed4cb3d. Existing local A5 plan (CARRY-01: P1 plan.php delete, P2 customers.php deactivate, P3 home.php deactivate) re-checked; addendum §11 appended (original 209 lines untouched; file still untracked). Amendments: drop C4 (ServiceGate.php and RouteMap.php are SHA-pinned by tests/Unit/Wp3a/SourceGuardTest.php), T3 baseline characterisation first, T7 (cron) removed per "no cron", stale line refs. No new owner decisions; READY_FOR_IMPLEMENTATION_AUTHORIZATION, waiting owner.

**A5 implemented (2026-10-13, uncommitted).** Owner `A5_PLAN_APPROVED — IMPLEMENTATION_AUTHORIZED`. C1-C3: ServiceGate::guardCustomer in plan.php delete (planDelete, before hook), customers.php deactivate (customerDeactivate, after CSRF), home.php deactivate (portalDeactivate, $user['id'] AND $bill['customer_id']). C4 dropped (ServiceGate/RouteMap SHA-pinned). Tests: tests/Unit/Wp8/LegacyRemovalSourceTest.php, tests/Http/LegacyRemovalGateTest.php (stub NAS session must use nasipaddress=127.0.0.1; T1c positive control). Disclosed test-only fix of A4 perf fixture (RAND() dup key flake on MySQL). Harness 1199/1113/69461/86 both engines exit 0. Report WP8_A5_IMPLEMENTATION_AND_SECURITY_ACCEPTANCE_REPORT.md READY_FOR_OWNER_ACCEPTANCE; backup /srv/phpnuxbill/wp8-a5-backup-20261013T210147Z. Residuals L-A5-1 (User::_info id-0 session fallback, OD-A5-1 optional), L-A5-2 (RADIUS-key collision, with OBS-1). Waiting owner acceptance.
**A5 final safety round (2026-10-14).** D-A5-1 fixed L-A5-1: P1 refuses customer_id<=0/missing before the hook, P2 refuses non-canonical/<=0/missing route id before lookup, both re-check User::_info() target == owner before device (ops planDeleteIdentity/customerDeactivateIdentity). Voucher-only rows (customer_id 0, Package voucher mode) are legit; P1 now refuses them (cron expiry handles them). D-A5-2 A4 fixture fix approved. L-A5-2 = production blocker under OBS-1. Final harness 1201/1115/69521/86 both engines exit 0. Verdict A5_READY_FOR_FINAL_OWNER_ACCEPTANCE; nothing committed.
**A5 committed (2026-10-14).** A5_ACCEPTED_WITH_NOTES, commit f07917e9 pushed to wp8 only (30 files); R-WP8-CARRY-01 CLOSED FOR DEVELOPMENT. Open prod blockers: L-A5-2/OBS-1, P-A4-1..3, others. Next: wait for formal transition; do not start S7/S8.

**S7 preflight (2026-10-14).** Baseline f07917e9. Plan `owner-review/WP8/WP8_S7_PREFLIGHT_AND_CONTRACT_TEST_PLAN.md` (untracked): call-site matrix (22 sites), classification (A empty, B = LegacyReprovisionService permit, C legacy, D unused + static Mikrotik class), RADIUS-vs-local masking rules (prefix nuxr_wp8_local_), DC-* CHR contracts, FC-1..8 fail-closed, T-S1..4 source guard. OBS-W8-1 closed by A5; OBS-W8-2..5 still present (new lines). Decisions OD-S7-1 (per-run lab API user, rec A), OD-S7-2 (VPN API-level, rec A). Waiting owner approval; no code/lab yet.

**S7 executed (2026-10-14) → S7_BLOCKED_WITH_EVIDENCE.** Tests only (untracked): Unit/Wp8/DriverClassificationSourceTest (T-S1..4, 47 pinned call sites), Support/Wp8DriverLabCase (temp API user nuxr_wp8_s7api, app-runtime driver calls; use unique global names — legacy code overwrites $t), Integration/Wp8/DriverContractLabTest (8/200 pass), DriverFailClosedLabTest; dev/wp8/s7_cleanup.py. **S7-F1 real bypass:** assertDeviceAllowed names only row username, not row pppoe_username → unassigned customer with pppoe_username = owned key overwrites owned PPPoE secret (driver + Package::rechargeUser). Recommended C: 1-line guard fix (LegacyReprovisionService.php:195) + OBS-1 uniqueness. OBS-S7-1 drivers ignore !trap; OBS-S7-2 VPN rmNat by IP / stale NAT comments. RouterOS rejects .invalid email TLD. Full harness & focused lab regression not run (stopped). Lab down, clean. Waiting owner.

**S7-F1 remediation round 2 (2026-10-14) → still S7_BLOCKED_WITH_EVIDENCE.** Owner `S7_F1_TARGETED_REMEDIATION_APPROVED — S7_REMAINS_BLOCKED` (option C). Fix (uncommitted, only tracked change): LegacyReprovisionService::assertDeviceAllowed :197 adds row pppoe_username to names. Test-first proven: pre-fix harness DeviceGuardKeyCollisionTest failed (legacy Radius device overwrote owned RADIUS policy, both engines); pre-fix CHR FC-2/2c/2d failed (secret overwritten, live session disconnected, RADIUS-masking local secret proven). Post-fix: FC 10/174, DC 8/200, focused harness 307/4355 + wp3a/wp5b unit 91/38041 both engines exit 0. Blocker left: OBS-S7-3 — Package::rechargeUser saves recharge row (:366/:254) before device and invoice after (:393/:283) even when the grant is refused; owner §4 said document + stop, don't touch Package.php. Proposed F-C (pre-flight guard in rechargeUser before writes + OBS-1). Not run: focused CHR regression, frozen full harness. Also fixed test fixture bug in Wp8DriverLabCase::localCustomer (comment swallowed fields). Note: `unit` suite excludes Unit/Wp3a & Wp5b — run wp3a-unit,wp5b-unit separately. Backup /srv/phpnuxbill/wp8-s7f1-backup-20261014T093719Z. Evidence evidence/s7/f1/. Waiting owner.

**S7-FC round 3 (2026-10-14) → S7_READY_FOR_OWNER_ACCEPTANCE (uncommitted).** Owner OD-S7-FC-1 approved F-C. Package::rechargeUser: non-voucher `assertDeviceAllowed($c)` pre-check after balance returns, before run_hook/recharge/tx/device; catch ProvisionFailure → return false (callers: plan.php "Failed to recharge", LegacyCustomerBalanceService refund, VoucherRedemptionService release). Package.php SHA pin e4225dd0… → d4567f66… (Wp3a SourceGuardTest, only that entry). WP4 doc §3 clarification. Tests: Integration/Wp8/RechargePreWriteRefusalTest (24-case matrix, RadiusHttpTestCase, Probe device, named hook probes), FC-2c now regression, DriverClassificationSourceTest preWrite pin + mutations. Results: FC 10/177, DC 8/200, focused router 18/418 (needs production-contract config: option_g on shipped, up-chr2, h2 setup, a2r chr2 setup, h2b site project — plain `up` lacks CHR2 → c7 lease fail), full harness 1129/69661/104 incomplete both engines exit 0, fingerprints identical. L-S7-FC-1: ordinary device failures keep "sync manually". Tracked changes: LegacyReprovisionService, Package, Wp3a SourceGuardTest, WP4 doc. Backup /srv/phpnuxbill/wp8-s7fc-backup-20261014T104956Z. Waiting owner acceptance; no commit; no S8.

**S7 committed (2026-10-14).** S7_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY. Commit b96747b5f453a74f731624e01d5d5b4e8c53c43c (parent f07917e9, 51 files) pushed fast-forward to wp8/integration-poc only; protected refs unchanged (feat 5bdc5745, main 500d3a2d, wp7 8e7f0e1c, wp6c 757d8de9, hotfix 6d4f3f96). S7-F1 closed (dev); OBS-S7-3 closed for ownership collision only; OBS-1 prod blocker; L-S7-FC-1 limitation. Next: wait for owner's S8 transition order; do not start S8.

**S8 (2026-10-14) → S8_READY_FOR_OWNER_ACCEPTANCE (uncommitted, docs only).** Owner `S7_COMMITTED_PUSHED_VERIFIED — S8_DOCUMENTATION_AUTHORIZED`, baseline b96747b5. Created owner-review/WP8/REPORT.md (coverage matrix, 31-row blocker register, C-01..C-26 consistency, D1–D9 table, 4-level assessment, D-S8-1..9) and docs/WP8_INTEGRATION_MODEL.md (labels [CHR]/[CHR-FG]/[LAB-FR]/[HARNESS]/[CONTRACT]); WP4 model +43/−0 (§13 real-NAS notes). Key facts: S1–S6 summary-only (no raw exit codes); wp8-integration lab never exit 0 (Message.php warnings); security gates last run 2026-10-10; drills/CoA/business-flow raw only on pre-remediation A3 tree; CoA wrong-source tested only on 7.12.1; Architecture Lock 8b0ee336 not on wp8; "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" not found in repo. No commit before owner acceptance.

**S8 final evidence re-gate (2026-10-14) → WP8_FINAL_REGATE_READY_FOR_OWNER_REVIEW (uncommitted).** Owner D-S8-1=A (OD-W8-16 dev-closed), D-S8-2=A. Lab = A4/S7 setup + `a2r_gate.py threaded on` (README: never -X in prod; A3/A4/S7 ran -X). Results: security-gates 10/216 exit 0 (48 trials, 0 doubles); FailureDrills 10/283 exit 0 (1 skip, 1 deprecation); CoaDisconnect 6/70; BusinessFlow 1/69; wp8-integration 101/1848, 0 fail, 3 warnings, 13 skip, exit 1. Message.php warnings = test-process artefact: FailureDrillsTest drill 8 requires PEAR2/Autoload.php → later Message loaded in PEAR2 scope; production init order (init.php:44 before :80) no warning; proposed test-only fix T-1 (unregister PEAR2 autoloader), not applied. PEAR2 Autoload.php:206 is_file(null) deprecation also in production init (log noise). Lock 8b0ee336: 0 textual conflicts vs wp8, manifest 10/10; reconciliation plan in WP8_S8_FINAL_EVIDENCE_REGATE.md §7. Never run a2r_sql_gate.py / a2r_gate.py pool|fault. Evidence owner-review/WP8/evidence/s8-regate/. WP8 PENDING; no commit.

**D-S8-3 test-only warning fix (2026-10-14) → WP8_READY_FOR_FINAL_DEVELOPMENT_ACCEPTANCE (uncommitted).** FailureDrillsTest drill 8: replaced `require_once PEAR2/Autoload.php` with a drill-scoped PEAR2\-only closure loader registered in try / unregistered in finally (never loads PEAR2\Autoload → no $registered desync) + assertSame(spl_autoload_functions before/after). Full wp8-integration (threaded prod contract): 101 tests / 1849 assertions, 0 warnings, 0 deprecations, 13 skipped, exit 0. Drill 8 standalone 1/24 exit 0; drill8+RealLifecycle 4 passed exit 0. Evidence owner-review/WP8/evidence/s8-warning-fix/. Uncommitted changes now: tests/Integration/Wp8/FailureDrillsTest.php, docs/WP4_RADIUS_MODEL.md, new REPORT.md, WP8_INTEGRATION_MODEL.md, WP8_S8_FINAL_EVIDENCE_REGATE.md, evidence/s8-regate, evidence/s8-warning-fix. Next: owner final WP8 dev acceptance + commit authorization.

**S8 FINALIZED (2026-10-15): commit 44ea45d48fa5212617cb879958b50c6baa32635b pushed to wp8/integration-poc only (fast-forward from b96747b5).** Owner `S8_REGATE_ACCEPTED — FINAL_DEVELOPMENT_FINALIZATION_AUTHORIZED` → `WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS`, `PRODUCTION_NOT_AUTHORIZED`. Commit: REPORT.md (§12 finalization, lock conformance §12.2), WP8_S8_FINAL_EVIDENCE_REGATE.md (§11), WP8_INTEGRATION_MODEL.md (§14), WP4 notes, install/freeradius/README.md CoA port/source notes (D-S8-5), ConcurrentFirstLoginGateTest docblock (D-S8-6, php -w identical), FailureDrillsTest fix, OWNER_DECISIONS S8 record, evidence s8-regate/s8-warning-fix/s8-final. tests/phpunit.xml comments :111/:116-118 still stale (left unchanged by audit rule; needs owner OK). Ledger WP8 row still PENDING (needs owner record; past entries were feat acceptance commits). Lock reconciliation steps (land lock branch, addendum, C-04) still owner decisions. Next: owner decides ledger/integration into feat and WP9 go; do not start WP9.

**WP8 metadata closed (2026-10-15): commit 704031c61296f4975f1d4e51531973d67e5f0a9b on wp8 (pushed, parent 44ea45d4).** Owner verdict WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS / PRODUCTION_NOT_AUTHORIZED; ledger WP8 row = ACCEPTED_WITH_NOTES @ 44ea45d4 on wp8 branch only (feat ledger not updated — later integration decision). phpunit.xml A2 comments corrected (c14n-no-comments + PHPUnit listing identical). Ledger file is under git-ignored docs/claude-development-pack: needs `git add -f` on the exact path. Next: owner decides feat integration and WP9; do not start WP9/WP10.
