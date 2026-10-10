# WP8 A2-R — Final Acceptance Closure Report

Date: 2026-10-09. Branch `wp8/integration-poc` at `cd09a5893fa10b4650cc2d410cc618c200900687`. Lab only. Nothing was
committed, pushed, merged or deployed. Neither A3 nor the H-1 fix was started.

**Verdict: `A2_R_BLOCKED_WITH_EVIDENCE`** (§13).

Summary:
- The security result holds: no double PPPoE session in any run, and no fail-open.
- **AV-1** cannot be closed inside the approved A2-R scope. It needs an owner decision.
- This round found **AV-4**. The 2000 ms late-claim budget also covers A1's own SNMP verification time. When that time
  is slow, a legitimate relogin after a lost Stop is refused (fail-closed). This is the cause of a new intermittent
  Drill 7 failure.
- CoA, Option G gate I and RealAaa are closed with evidence.

---

## 1. Current Git and backup verification

| Item | Value |
|---|---|
| HEAD | `cd09a5893fa10b4650cc2d410cc618c200900687` (= `origin/wp8/integration-poc`) |
| `origin/feat/reseller-system-v1` | `5bdc57459a64dd53e32e5abb2ef6efa3815e387a` (unchanged) |
| `origin/main` | `500d3a2d15003efeed6e0aad639c13d08101c3b1` (unchanged) |
| Working tree | uncommitted A2-R work only (§11). No `reset`, `stash` or `clean` was used. |
| Backup | `/srv/phpnuxbill/wp8-a2r-closure-backup-20261009T210332Z` (mode 0700, outside the worktree) |

The backup holds the tracked diff, the tracked files, every untracked file (including both evidence directories) and
the lab evidence from `$NUXR_WP8_STATE`. `SHA256SUMS` was verified (`sha256sum -c`, all OK):

```
9d9c47be6eb3e8a61e61d6abf9585ed13fb8689a570a2dda8e8ab42bddd6688a  tracked.diff
5798a0697ee71462f3fac10a3cc6f091e2596f43e226c74f4ff709c45a8f08fb  status.txt
ceaa9cfea3fe20889f8d32facb8f3be42cf3dd46e58ca462d90c66227317f212  HEAD.txt
d9970de4f8929a292dc7494e79f6ad347fff4b343d0f94a633be44c9a584cd1c  untracked.tar
d3f01807bf934a7b8b831d54c409ecb84a466ce80b3c0df540af11efa02ec446  tracked-files.tar
(lab-evidence.tar appended and verified)
```

The prototype backup from the implementation round (`wp8-a2r-prototype-backup-20261009T172236Z`) is untouched. A1,
production and AVAIA were not touched.

## 2. AV-1 analysis and outcome

### 2.1 Reconstructed timeline (real CHR 7.24.5, `/radius timeout=3s`, FreeRADIUS threaded)

The failing Drill 6 run kept no detailed record: the test stops before it writes evidence, and the FreeRADIUS logs
went with the lab containers. The drill was therefore reproduced with instrumentation (new lab case
`a2r_gate.py run av1_stall_timeline`):
- a pcap on the lab bridge (kernel arrival time at the FreeRADIUS host);
- `docker logs -t` (FreeRADIUS processing time);
- `nuxr_admission.created_at`;
- the client `pppd` log and the RouterOS log.

Two runs gave the same result. Times are in ms from the first Access-Request on the wire (second run):

| ms | Event |
|---|---|
| −254 | FreeRADIUS frozen (`docker pause`: the whole process stops; the kernel keeps queueing UDP) |
| 0 | Access-Request id 20, Acct-Session-Id `81000005`, authenticator A |
| 3045 | same packet retransmitted (same id and authenticator) |
| 6268 | same packet retransmitted (3rd and last transmission) |
| ≈9300 | RouterOS gives up: NAS log `user … authentication failed - radius timeout`; client gets `LCP TermReq "… radius timeout"` |
| ≤10498 | client `pppd` has exited (attempt over) |
| 10498 | resume command issued |
| **10808** | FreeRADIUS reads the original packet from the socket. **The policy computes age = 22 ms** (pre-claim) / 24 ms (post-claim). The real wait was 10.8 s. |
| 10808 | the 2 queued retransmissions are read and logged `Ignoring duplicate packet … due to unfinished request` |
| 10823 | claim INSERT: `PENDING`, Acct-Session-Id `81000005`, request authenticator = A |
| 10824 | Access-Accept id 20 on the wire. The NAS has no pending request with that id: the Accept is discarded and no accounting follows |
| next login | `nuxr-admission=reject:pending-other` (claim still `PENDING` for `81000005`) |

First run: the same sequence, with the Accept at 13846 ms and a kernel queue of 13.8 s. Its automatic flag in the
record is wrong (§10.4).

**Control (same session, stall inside the NAS deadline):** FreeRADIUS paused until 2.4 s after the first
Access-Request. Read at 2920 ms, the policy saw an age of 57 ms, and the Accept went out at 2968 ms (before the first
retransmission). Result: session UP, claim `ACTIVE`. A stall alone is harmless; the Accept fails only when the stall
outlasts the NAS retry window (about 9 s with `timeout=3s`).

Evidence: `a2r-closure/lab-evidence.jsonl` (`AV-1.stall_timeline` ×2 plus the correction record), pcaps
`a2r-av1-short.pcap` / `a2r-av1-long.pcap` in `$NUXR_WP8_STATE/pcap` (not committed).

### 2.2 Why FreeRADIUS 3.2.8 cannot see the delay

1. **Source of the timestamp.** `src/main/process.c` (`request_receive`, release_3_2_8) sets
   `packet->timestamp = gettimeofday()` when the server reads the packet from the socket. `%l` / `%M` (`xlat.c`) are that
   timestamp. Time spent in the kernel receive queue before the read is invisible.
2. **No kernel timestamps.** There is no `SO_TIMESTAMP` / `SIOCGSTAMP` in `udpfromto.c`, `radius.c`, `listen.c` or
   `process.c`, so kernel receive timestamps are never requested.
3. **No NAS timestamp.** RouterOS 7.24.5 PPPoE Access-Requests carry no `Event-Timestamp` (attributes captured: Service-Type,
   Framed-Protocol, NAS-Port, NAS-Port-Type, User-Name, Calling-/Called-Station-Id, NAS-Port-Id, Acct-Session-Id,
   CHAP-Challenge/-Password, NAS-Identifier, NAS-IP-Address, Message-Authenticator). Only accounting carries
   `Event-Timestamp` / `Acct-Delay-Time`.
4. **The duplicate signal is not usable.** The duplicates FreeRADIUS logs on resume are visible only in its log, not to
   the policy.

**When it happens.** Kernel queueing of more than milliseconds happens only when the whole FreeRADIUS process or its
event loop is stalled: a paused container or VM, SIGSTOP, a debugger, swap or CPU starvation of the host, or `-X` / `-s`
single-thread mode. Waiting *inside* FreeRADIUS (thread or SQL saturation) is visible and is rejected by the late
guard (gate `late_claim_real`; the 13.2 s case of the feasibility round).

### 2.3 Can a late claim or late Accept be prevented safely inside A2-R? — No

| Candidate | Result |
|---|---|
| Measure kernel queue age in the policy | impossible: no timestamp source (§2.2) |
| NAS-side time in the request | RouterOS sends none |
| Tighter late budget | does not help: the visible age is ~20 ms whatever the stall length |
| Release `PENDING` after time | forbidden (Strict Mode); not done |
| Release `PENDING` on SNMP SAFE | forbidden by the owner; not done |
| Supervisor restart on stall (drops the kernel queue) | new infrastructure, cannot see VM-level pauses; out of scope |
| Smaller socket buffer | bounds bytes, not time; unsafe; not done |

No change was made to the policy. Strict Mode is unchanged. **AV-1 is NOT closed.**

### 2.4 What does work (verified)

- **Fail-closed.** No second session in either run, and no Accept reached the NAS in time.
- **Operator recovery** with NAS-side proof (the NAS log line `authentication failed - radius timeout` for that user at
  the claim's time, plus `/ppp active` empty):
  - `nuxr_admission_release.sql` printed `RELEASED`;
  - audit row: `release_pending / RELEASED / wp8-lab-operator / reason / evidence`;
  - the next login was admitted and the new claim became `ACTIVE`.
- **Detection.** A read-only monitoring query for orphan candidates was added to the README: `PENDING`, no accounting,
  older than 60 s. A row is a candidate for the NAS-side check, never proof.

### 2.5 Owner decision required (AV-1)

| Option | What | Security | Availability | Scope |
|---|---|---|---|---|
| **D1 (recommended for A2-R)** | Accept AV-1 as a documented consequence of Strict Mode, with operational controls: FreeRADIUS threaded under the service manager (never `-X` / paused); host / VM stall monitoring; alert on the orphan query; release via the runbook with NAS-log proof. Drill 6's step "service after FreeRADIUS returned" becomes "refused until the operator release after a stall longer than the NAS retry window" — changed only on your instruction. | unchanged (no auto-release) | lock-out until the operator acts (MTTR = operations) | docs + test expectation |
| D2 | Separate design: automatic release of an aged, never-accounted `PENDING` claim on positive NAS-side proof from the same NAS (for example the NAS log or an API query for that Acct-Session-Id). Needs its own feasibility gate. | weakens Strict Mode; risk of a false "gone" | self-healing | new stage |
| D3 | Supervisor that restarts FreeRADIUS on a detected stall (kernel queue dropped) | unchanged | partial (cannot see VM pauses) | new infrastructure |

## 3. WP8 regression final matrix

Full suite `wp8-integration` on the production-contract lab (CHR NAS row `mikrotik_snmp` plus guard credentials),
FreeRADIUS threaded, 21:29:58Z–22:18:09Z.

| Test | Previous round | This round | Classification |
|---|---|---|---|
| `CoaDisconnectTest` (6) | 1 fail (CoA PPPoE) | **6/6 pass** | fixed (age-computation order), §4 |
| `OptionGGateTest` (17) | 1 fail (gate I) | **15 pass, 2 skipped** (functional mode) | test-fixture adaptation verified, §4 |
| `RealAaaTest` (7) | 1 fail | **7/7 pass** | test defect fixed, §6 |
| `FailureDrillsTest::testDrill1and6…` | fail | **fail** | AV-1, §5 |
| `FailureDrillsTest::testDrill7GuardLostStopRelogin…` | pass | **fail** (1 of 4 runs) | **AV-4 (new)**, below |
| all other WP8 tests | pass | pass | — |
| skipped | 13 | 13 | gate modes that exclude the production contract (§9) |

**AV-4 (new) — the late-claim budget includes A1's verification time.**

Failing request `(49)` (relogin after a lost Stop; excerpt `a2r-closure/av4-request49-timing.txt`):

| Time | Step |
|---|---|
| 21:35:00.698 | received |
| +0.05 s | stock `Simultaneous-Use` check starts (stale radacct row → stock checkrad); it takes 1.69 s |
| +1.74 s | A1 guard starts (SNMP); it takes 2.58 s (`ms=2434` inside the guard) |
| +4.33 s | the policy's age is `4348` → `nuxr-admission=reject:late` → Access-Reject |

The result is fail-closed and never a second session. Three focused reruns passed, with relogin paths of 1605 ms,
669 ms and 745 ms. One run in four failed in this session; the previous round passed. The margin is thin: 1605 ms
against a 2000 ms budget.

Root cause: the 2000 ms budget is shorter than the worst case of the A1 layer it follows. The guard alone has a 1.8 s
SNMP deadline and a 3 s module timeout, and stock checkrad runs before it. This is an A2-R parameter inconsistency
(mine), not an A1 change. **Not changed in this round** (no redesign; it trades against AV-1, see §12).

## 4. CoA and Option G evidence

**CoA.**
- **Root cause (previous round):** the age expression read seconds before microseconds, so on a second boundary the age
  went negative. That failed the sentinel and refused a valid login. 2 of 454 requests were hit.
- **Fix:** `%C` (µs) is read before `%c`. `AdmissionSourceTest` asserts the order.
- **This round:** all 6 CoA tests pass. `S3.disconnect-pppoe` shows `COA_ACK`, exactly one request, and the radacct Stop
  written (radacctid 19, `81000008`). Disconnect-Hotspot, CoA rate, address change, no-response and error-cause tests
  also pass.
- `reject:late` appeared exactly once in the whole run (AV-4, §3). No negative or malformed age appeared in any request.

**Option G gate I.** Diff against HEAD (`tests/Integration/Wp8/OptionGGateTest.php`):
- **Assertions:** 91 in HEAD and 91 now, and no assertion line was added, removed or changed.
- **`pppoeAccess()`:** now sends the RouterOS PPPoE attempt identity (Acct-Session-Id, Calling-Station-Id, NAS-Port-Id),
  which every real RouterOS request carries. The method is PAP, as before.
- **`endSyntheticAttempt()`:** after I4, the only synthetic *Accept* in gate I, the synthetic NAS sends the
  Accounting-Stop with the returned `Class`. This is exactly what a real NAS sends when that session ends. Without it,
  I5 (a real login of the same subscriber) is refused `pending-other`. That refusal would be the correct Strict Mode
  answer if the synthetic session were still live, so the Stop states the fixture's intent ("session over") and
  weakens nothing.
- **What I1–I3 still check:** the A1 decisions (LIVE on B or A, ERROR on B) still produce `Access-Reject` with the same
  assertions, and no claim is created for them.
- **This round:** gate I passes (`OD16G.gateI.multiple-nas`, `guard_detail 10.88.0.2=LIVE:1;10.88.0.30=SAFE` for I1),
  as do all other Option G gates A–I, the hostile-username gate and the three Hotspot gates.

## 5. Drill 6 verdict

- **What failed:** `FailureDrillsTest.php:130` "service after FreeRADIUS returned". The first login after the stall is
  refused `pending-other` because of the orphan claim (§2.1). Reproduced in this suite run (request `(30)`, 21:32:35Z).
- **Classification:** an **intended Strict Mode consequence that is an availability regression against A1**. A1
  restored service immediately; A2-R locks the subscriber out until the operator release. It is not an implementation
  defect, it can never yield a second session, and it cannot be fixed inside A2-R (§2.3).
- **Expectation not modified:** the test still expects the A1 behaviour and fails. It can change only on your decision
  D1 (§2.5), or the code can change under D2 / D3.

## 6. RealAaa root cause and result

- **Failed assertion:** `RealAaaTest.php:70`, message "session id, NAS identity (== nas.nasname), framed address, port".
- **Actual data (previous round):**

  | Field | Value |
  |---|---|
  | expected (RouterOS API `session-id` without `0x`) | `810000E1` |
  | actual (`strtolower(radacct.acctsessionid)`) | `810000e1` |
  | other fields (NAS IP, framed address, port type, port) | identical |

- **Root cause:** a test helper defect. The RouterOS API prints the session id as upper-case hex and RADIUS carries it
  lower-case. The test lowercased only one side, so it passed only while the counter had no hex letter (for example
  `81000014`, `81000060`).
- **Not a behaviour change:** evidence recorded on **2026-10-06, before A1 and A2-R existed**
  (`pre-s6-final-174408.jsonl`, `S6.D4.nas-unavailable`) already shows API `0x8100001F` against RADIUS `8100001f`.
  The comparison involves no username, so canonical username plays no part, and A2-R writes nothing to radacct.
- **Origin:** commit `b4347279` (S2). *Correction to the implementation report, which named the A1 commit `609dc4db`.*
- **Fix (test only):** `strtolower()` on both sides. It compares the same number, so the verification is unchanged.
- **Result:** passes this round on `0x8100003A`, a value with a hex letter, which is exactly the formerly failing
  condition.

## 7. H-1 independent remediation scope

**"H-1 — HIGH — VERIFIED HOTSPOT USERNAME-EQUIVALENCE BYPASS"** (classification adopted).

**Evidence kept** (`a2r-implementation/lab-evidence.jsonl`, `HOTSPOT.username_variants`, 18:08:50Z). Port-Limit = 1,
`Simultaneous-Use` = 1:

| Trial | First login | Second login | Live sessions |
|---|---|---|---|
| same spelling (control) | in | REFUSED | **1** |
| upper vs lower case | in | in | **2** (`nuxr_a2r_hs_cafe_3994` and `NUXR_A2R_HS_CAFE_3994`) |
| accent vs plain | in | in | **2** (`…cafe_1ae5` and `…café_1ae5`) |

Hotspot was not modified in A2-R.

**Root cause.**
1. `radcheck` (utf8mb4_general_ci) authenticates every equivalent spelling.
2. RouterOS keys Hotspot active users and `Port-Limit` by the spelling it received.
3. The stock `Simultaneous-Use` layer does not save it: its checkrad verification on the production NAS type fails
   open in FreeRADIUS 3.2.8 (the A1 finding).

**Proposed minimal fix (H1-A, recommended): exact-spelling rule for Hotspot.**
- **Rule:** in `post-auth`, Hotspot branch only (Service-Type = Login-User, NAS-Port-Type = Wireless-802.11, as captured
  from RouterOS 7.24.5): when the request's spelling (`SQL-User-Name` bytes) is not byte-identical to the canonical
  `radcheck.username` (the same `k=` key A2-R uses for PPPoE), send Access-Reject with `attr_filter.access_reject` and
  `Reply-Message "Session verification failed"`.
- **Effect:** only one spelling can ever hold Hotspot sessions, so the NAS's per-spelling `Port-Limit` becomes
  per-subscriber.
- **Properties:** stateless, so it is independent of accounting loss and of races. No schema change, no
  `install/radius.sql` change, no `system/` change. About 15 lines in the site, plus README and tests.
- **Cost:** a Hotspot user who types a different case or accent than stored is refused. Measure the impact first with
  a read-only production query of radacct Hotspot rows whose username bytes differ from the radcheck bytes.

**Alternatives.**
- **H1-B:** reply `User-Name := <canonical>` in the Hotspot Accept. Less visible to users, but only works if RouterOS
  renames the active user. Unverified; needs its own lab gate first.
- **H1-C:** a Hotspot reservation counter like A2-R. Heavier; rejected as not minimal.
- **H1-D:** binary collation on `radcheck`. A schema change with wide impact; rejected.

**Required gates for the H-1 stage.**
1. Equivalent spellings (case, accent, mixed) refused, while the stored spelling still logs in.
2. `shared_users` = 1: control refused by Port-Limit, variant refused by the rule.
3. `shared_users` = 2: two stored-spelling sessions allowed and a third refused; a variant is refused even when below
   the limit.
4. Accounting lost (Start dropped at the NAS): the variant is still refused.
5. Two spellings logging in concurrently: only the stored spelling is admitted.
6. Regressions: OD16 Hotspot Port-Limit gates (1, 2, accounting-lost), Option G Hotspot gates, S-1, A2 race gates, the
   harness on both engines, and the collision pre-check.
7. Production pre-check: collisions plus the spelling-mismatch impact query.

Fix before Production Release. Timing is for you to set before A3.

## 8. AV-2 and AV-3 operational status

**AV-2 — SQL pool.**

| Setting | Value |
|---|---|
| Lab module (`dev/test/freeradius/sql`) | `pool { start 0, min 0, max 8, spare 1, retry_delay 1, idle_timeout 30 }` |
| Shipped example (`install/freeradius/mods-available/sql.example`) | `max 32, spare 3, retry_delay 30, idle_timeout 60` |
| FreeRADIUS image thread pool | `start_servers 5, max_servers 32, max_spare_servers 10` |

40 concurrent attempts of one subscriber, 3 rounds, measured as (admitted, refused, no reply):

| `pool.max` | Rounds | Pool-exhausted log lines |
|---|---|---|
| 8 (implementation round) | `[1,39,0] [0,40,0] [0,40,0]` | 166 |
| **32** (= `max_servers`, this round, lab marker `a2r_gate.py pool 32`) | `[1,39,0] [1,39,0] [1,39,0]` | **0** |

Never more than one admitted per round. Proposed sizing (README step 5 updated):
1. Per instance, `pool.max` >= `thread pool { max_servers }`. One worker holds at most one connection at a time, so pool
   exhaustion cannot happen inside the instance.
2. The DB `max_connections` must cover Σ(pool.max of every ISP instance on it) + the application.
3. Size `max_servers` to the ISP peak, measured per ISP: the reconnect storm after a reboot of the largest NAS, divided
   by the per-auth latency (6–300 ms measured in the lab, plus 0.7–4.3 s for the A1 path with stale rows).

A bigger pool **does not add capacity**. Load beyond the threads waits inside FreeRADIUS and is refused by the late
guard. That costs availability and never yields a second session. A production load measurement per ISP is still
open.

**AV-3 — uncertain claims (Accept lost on the network).**
- Recovery depends only on NAS-side proof of invalidation: a NAS reboot after `created_at` (gate
  `orphan_reboot_runbook`) or the NAS log `radius timeout` line (this round, §2.4).
- The README states that "no session in SNMP / `/ppp active`" alone is **not** proof.
- There is no automatic release by time, SNMP SAFE, `sysUpTime` or Acct-Session-Id. A NAS reboot did not release the
  claim automatically.
- **Audit:** every decision, released or refused, writes a row with `at`, `action`, `outcome`, `username`, `token`,
  `operator` (mandatory, 2–64 chars), `reason` (≥ 10) and `evidence` (≥ 10).
- **Refusals:** a wrong or newer token, a short reason, a second release, and any `ACTIVE` claim are refused (harness
  contract test, both engines).
- **Limit:** `operator` is the name the operator declares. The executing DB account is not recorded. Recommendation: one
  DB account per operator with the server's audit or general log, or a `db_account` column (a schema change; not done).

## 9. Tests and exit codes

| Run | Result | Exit code |
|---|---|---|
| WP8 `wp8-integration` (full, real CHR) | Tests 69, assertions 1175, **2 failures** (Drill 1&6 = AV-1, Drill 7G = AV-4), **13 skipped**, 3 warnings, 1 deprecation | **1** |
| Drill 7G focused rerun ×3 | OK (1 test, 29 assertions) each | 0, 0, 0 (they do not erase the failure) |
| S-1 gate (`wp8-security-gates`, `UsernameEquivalenceGateTest`) | OK (1 test, 10 assertions) | 0 |
| AV-1 lab case `av1_stall_timeline` (×2) | AV-1 reproduced both times; control OK; recovery OK | 0 |
| AV-2 lab case `sql_concurrency` (pool 32) | pass | 0 |
| Harness WP4 + WP5A + WP5B, MariaDB | OK (274 tests, 5788 assertions) | 0 |
| Harness WP4 + WP5A + WP5B, MySQL 8.0 | OK (274 tests, 5788 assertions) | 0 (same run) |

**Skipped (13), not counted as passes.** All belong to lab modes that exclude the production contract:
- `CheckradGateTest` ×10: Option F checkrad gate mode, an alternative the owner rejected;
- `OptionGGateTest` functional mode ×2 (diagnostic mode);
- `FailureDrillsTest::testDrill7LostAccountingStopAndSimultaneousUse` ×1: the S6 baseline for a NAS row of type
  `other`. Its production-contract variant is Drill 7G.

The previous round skipped the same 13.

**Warnings and deprecation are pre-existing.** The 3 warnings are `system/autoload/Message.php:13-15`
"Undefined variable `$root_path`" (upstream file, last changed `c63545d3`, 2025-03-17, untouched), triggered by
`RealLifecycleTest::testLegacyReprovisionPasswordAndUsernameOnTheRealNas`. The previous round had the same 3 warnings
and 1 deprecation. **No new regression** beyond AV-4.

A2 race gates (B0, C, E3, A, Bscan, E1, E2) were **not re-run**. The policy is byte-identical to the implementation
round (§11), whose evidence stands: 67 trials, 0 double sessions.

## 10. Security evidence and cleanup

1. **SNMP test community.**
   - The value exposed in the console in the implementation round was rotated then (files deleted).
   - This round's lab generated a new one. A direct comparison against every `wp8…` candidate in the session
     transcripts gives *reused: False*. Only booleans were printed.
   - After lab down, this round's `snmp.env` and `radius-secrets/nas_snmp` were deleted as well.
   - The value is in no file of the worktree or the evidence.
2. **Secret sweep.** 33 changed or new files, checked against the 3 lab secrets (`secrets.env`), the disclosed community
   and private-key or community patterns: **0 hits**. The AV-4 log excerpt was filtered to drop password lines (0
   matches).
3. **Lab cleanup.** `lab.sh down` reports: qemu 0, netns 0, links 0, agent 0, containers 0, networks 0. CHR1 and CHR2
   working disks were removed; only the pinned original image (`chr-7.24.5.img`, `.zip`) remains. No `pppd.opts` or
   `.pid` files remain.
   - `$NUXR_WP8_STATE/secrets.env` (mode 600, since 2026-10-06, outside Git) is the lab's persistent secret file and
     was kept.
4. **Invalid and corrected records kept, never counted as passes.**
   - The implementation round's invalid cross-NAS run (19:46Z / 19:47Z, MAC collision) and its corrections are kept.
   - This round: the first `AV-1.stall_timeline` record (timeline correct, flag `long_stall_reproduces_av1 =
     false` wrong) is kept unchanged. A `CORRECTION.AV-1.stall_timeline` record explains it: the Accept was compared
     with the moment the `docker unpause` *command returned*, not the resume. A corrected rerun follows.
5. **Manifest:** `docs/claude-development-pack/MANIFEST.sha256`, **42/42 OK**, and no file in that tree changed.
6. **`install/radius.sql` and `system/`:** 0 changed lines, not in `git status`.
7. **Fail-open:** the PPPoE policy and the 3 SQL files are byte-identical to the implementation round. The source
   contracts (14 rejects, 15 `attr_filter`, 1 `INSERT`, sentinels, age read order) pass in the harness run. Every
   failure in this round's runs was a refusal (`reject:late`, `pending-other`), never an Accept.
8. **Out-of-scope changes:** none. Touched this round: one WP8 test, the README, and lab tooling (§11).

## 11. Files changed

Changed **in this closure round** (compared with the backup):

| File | Change |
|---|---|
| `tests/Integration/Wp8/RealAaaTest.php` | test defect fix (§6), 1 assertion line plus comment |
| `install/freeradius/README.md` | pool sizing rule (step 5); orphan-candidate monitoring query (operator recovery) |
| `dev/wp8/a2r_gate.py` | lab case `av1_stall_timeline`; `pool N|off` command |
| `dev/wp8/freeradius/start.sh` | lab-only marker `wp8-a2r-pool` (SQL pool max for the AV-2 measurement) |
| `owner-review/WP8/evidence/a2r-closure/*` | this report and the evidence (new) |

Unchanged since the implementation round: `install/freeradius/sites-available/phpnuxbill`, `nuxr_admission.sql`,
`nuxr_admission_check.sql`, `nuxr_admission_release.sql`, `docs/WP4_RADIUS_MODEL.md`, and all other A2-R tests and tools.

Full uncommitted A2-R set: 13 modified files and 21 new files, `a2r-closure/` included (see `git status`).

## 12. Remaining blockers

| # | Item | Severity | Blocks A2-R acceptance | Blocks production | Needed |
|---|---|---|---|---|---|
| 1 | **AV-1** FreeRADIUS stall → orphan `PENDING` → lock-out until the operator acts; Drill 6 fails | availability | **yes** (owner decision) | yes | decision D1 / D2 / D3 (§2.5) |
| 2 | **AV-4** late budget 2000 ms < A1 verification worst case → lost-Stop relogin refused (1 of 4 runs here) | availability | **yes** (owner decision) | yes | decision AV4-A or AV4-B (below) |
| 3 | **H-1** Hotspot spelling bypass | HIGH security | no (separate stage, per owner) | **yes** | H-1 stage (§7) |
| 4 | AV-2 production pool and thread sizing per ISP | availability | no | yes | per-ISP peak measurement |
| 5 | AV-3 uncertain claims by design; operator identity = declared name | operations | no | runbook adoption | optional `db_account` audit |
| 6 | R-WP8-CARRY-01/02/03, R-HF-CARRY-01 | carry-forward | no | as before | unchanged |

**AV-4 options.**
- **AV4-A:** keep 2000 ms. Require the whole A1 path to finish within about 1.5 s in production; measure stock checkrad
  plus guard on the largest real NAS, whose interface table grows with the number of sessions. Nothing changes in
  code.
- **AV4-B:** raise the budget to about 5000 ms, below the NAS abandonment at about 9 s with `timeout=3s`, then
  re-validate the late-claim gates (harness
  `testCreatorReleasesOnlyAnUnusedLateClaimAndAMissingTableFailsClosed`, lab `late_claim_real`, whose hold times must
  be retuned, and `nas_deadline`), Drill 7G and the CoA tests.
  - Trade-off: an orphan then needs about 4 s of invisible kernel queueing instead of about 7 s (AV-1 exposure grows
    a little).
  - Recommended: AV4-B. A guard that can legitimately take 3 s should not run under a 2 s budget.

## 13. Final recommendation

- **Security:** the PPPoE admission reservation does what A2-R set out to do. There is no double session in any run
  (67 race trials, 13 cross-NAS trials, S-1 real and synthetic), every fault path is fail-closed, and nothing touches
  radacct.
- **Closed in this round:** CoA, Option G gate I and RealAaa, with evidence.
- **Not closed:**
  - **AV-1** needs your decision; it cannot be fixed inside A2-R without weakening Strict Mode.
  - **AV-4** is a new, intermittent availability regression from an A2-R parameter.
  - **Drill 6 and Drill 7G** therefore still fail, and their expectations were not modified.

Recommended path to `A2_R_READY_FOR_OWNER_ACCEPTANCE`:
1. Decide **D1** for AV-1, which authorizes the Drill 6 expectation change and the operational controls.
2. Authorize **AV4-B** (late budget plus targeted re-validation), or adopt AV4-A with a production latency requirement.
3. Keep H-1 as the next separate stage before Production Release.

**`A2_R_BLOCKED_WITH_EVIDENCE`**

No commit, push, merge or deploy. A3 and the H-1 fix were not started.
