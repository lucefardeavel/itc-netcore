# WP8 A2 — Concurrent First-Login Race Gate — results (2026-10-09)

> **Owner review (2026-10-09):** evidence ACCEPTED - SECURITY GATE FAILED - `A2_EVIDENCE_ACCEPTED_RACE_PROVEN_BLOCKER_OPEN`.
> The gate test runs only in the PHPUnit suite `wp8-security-gates` (excluded from `wp8-integration`) and fails by design
> until the A2-R remediation is implemented and re-gated. No production authorization.

**Verdict: `A2_RACE_PROVEN`.** Two live PPPoE sessions of the same username were proven concurrently on the real
RouterOS CHR 7.24.5, with the permanent A1 configuration (`wp8/integration-poc@609dc4db`, production contract: CHR NAS
type `mikrotik_snmp` with guard credentials). This is an investigation only: no fix was applied, and nothing in `install/`,
`system/`, the schema or `radacct` was changed (owner D-A2-4). Cross-NAS (D) was not run: per owner D-A2-1 a proven same-NAS
race stops the escalation, and no CHR2 was created.

`lab-evidence.jsonl` holds every A2 record of this run, redacted (secret sweep: no lab secret, community or test password
present). It includes the invalid first-pass records listed in §6.

## 1. Environment and baseline

- **Code:** `wp8/integration-poc@609dc4db` (A1, accepted). Worktree clean before A2.
- **Lab:** fresh `dev/wp8/lab.sh up`, then `option_g_gate.py on shipped`:
  - NAS row 1 = CHR 10.88.0.2, type `mikrotik_snmp`;
  - guard credentials persistent (0600);
  - `delete_stale_sessions = no`;
  - permanent post-auth guard, with no lab policy.
- **RouterOS** (read only, recorded, not changed — owner D-A2-3):
  - PPP profiles `default` and `nuxr_wp8_pppoe`: `only-one=default`;
  - PPPoE server: `one-session-per-host=false`, `max-sessions=unlimited`;
  - `/ppp aaa`: `use-radius=yes`, `interim-update=1m`;
  - `/radius timeout=3s`.
- **Clients:** two independent Linux pppd clients in their own namespaces (`wp8c1` MAC `CA:80:7E:17:26:BB`, `wp8c3` MAC
  `3E:FC:F1:E0:E7:14`), both on the PPPoE segment. Every trial uses a fresh username with `Simultaneous-Use = 1`,
  provisioned through the normal `RadiusPolicyBuilder` path.

## 2. Method

**Lab agent operation `pppoe_race`.** Inside one agent call:
- Both pppd option files are prepared first.
- Two launch threads meet at a `threading.Barrier`, then busy-wait to a common `monotonic_ns` release point (+ offset).
- The measured launch skew for offset 0 was 0.19–1.1 ms.

**Independent observers, running in parallel:**
- the NAS `/ppp/active` table, read for the user over its own API connection every 150 ms (mean API call 16–74 ms);
- each client's ppp address, polled every 50 ms;
- ICMP from each client namespace to 10.88.10.1 over its own `pppN` link, every 0.2 s.

**Joined after each trial:**
- a RADIUS pcap on `wp8lab0`;
- the FreeRADIUS per-request decisions, request-local and joined to the trial's own pcap on RADIUS Id + source port;
- `radacct`;
- the RouterOS log.

**Clocks:**
- The agent events and the tcpdump pcap use the same host `CLOCK_REALTIME`, so no conversion is needed. The agent has ns
  resolution, the pcap µs (kept exactly as `t_us`).
- RouterOS clock readings (1 s resolution) are bracketed by host time, for the RouterOS log only (1 s resolution).
  `radacct` times have 1 s resolution and are used only as supporting evidence.

**Classification (owner D-A2-2).**
- `RACE_PROVEN_*` requires all four of:
  - two Access-Accepts;
  - two distinct live NAS sessions (session-id and caller MAC) in the same sample;
  - both clients up at the same time;
  - ICMP replies on both links within 0.5 s of each other.
- Fewer than two Accepts is decisive from the RADIUS decisions alone (the NAS cannot open a RADIUS session without an
  Accept).
- Two Accepts with poor coverage give `INCONCLUSIVE`.
- Evidence is recorded before any assertion, and every trial ends with both clients down and the NAS table empty.

## 3. Results

The gap is the measured time between the two Access-Requests in the pcap (PPPoE discovery adds up to about 3 s
variance after the launch).

| Scenario | Trials (valid) | Request gap | FreeRADIUS decisions | NAS / clients | Verdict |
|---|---|---|---|---|---|
| A baseline (single login) | 5 | – | `n=0`, guard not run, Accept | 1 session | Accept→Accounting-Start **25.0–159.5 ms** (pcap); right after the Start the guard sees `LIVE:1` |
| **B0 concurrent same NAS** | 2 (stopped after 2 proofs) | **11.6 ms, 40.0 ms** | both `n=0;`, guard not run, **both Access-Accept** | 2 distinct sessions (`0x81000005/06`, `0x81000007/08`) in 89/111 and 99/122 samples, until cleanup; both clients up together 17.1 / 17.2 s; 80 / 82 ICMP pairs | **RACE_PROVEN_PERSISTENT_DOUBLE** ×2 |
| B-scan staggered (rerun, fixed tooling) | 7 | 504 ms – 3.1 s | 1st `n=0` Accept; 2nd `n=1`, guard **LIVE**, Reject | 1 session | SINGLE_SESSION ×7 |
| **C Accounting-Start lost** (rerun) | 2 | **5.0 s, 30.0 s** | both `n=0;`, guard not run, both Accept | 2 sessions in 127/158 and 288/447 samples; clients together 24.4 / 49.4 s; 119 / 245 ICMP pairs | **RACE_PROVEN_PERSISTENT_DOUBLE** ×2 |
| E1 stale row + SNMP down (rerun) | 1 | 15.2 ms | both `n=1`, guard ran, **ERROR:health**, both Reject; not the stock layer (`stock_session_reject=false`) | 0 sessions; stale row byte-for-byte unchanged | NO_SESSION (fail closed by **Option G**) |
| E2 stale row + SNMP healthy | 5 | 3.9 / 12.2 / 37.8 ms (concurrent), 2.98 / 2.99 s | 1st guard **SAFE** Accept; 2nd guard **LIVE** Reject | 1 session | SINGLE_SESSION ×5 — protection **incidental** (§4) |
| **E3 accounting lost + SNMP down** | 1 | 3.04 s | both `n=0;`, **guard not invoked**, both Accept | 2 sessions in 103/123 samples; together 17.9 s; 88 ICMP pairs; `radacct` empty | **RACE_PROVEN_PERSISTENT_DOUBLE** |

- The RouterOS log of every race trial shows both `<pppoe-USER>` and `<pppoe-USER-1>` "authenticated" / "connected".
- No mechanism ended either duplicate: they stayed up until the test's cleanup.
- After the accounting path came back (C), a `radacct` row appeared within 75 s (the check stops at the first row), and
  the duplicate session was still neither detected nor evicted.

## 4. Root cause

- PPPoE admission is decided only from open `radacct` rows: the stock `Simultaneous-Use` (`session { sql }`) and the A1
  guard trigger (`n=…`).
- A session's row exists only after its Accounting-Start has been received and written. On this lab that is 25–160 ms
  after the Access-Accept; if the Start is lost or delayed, it is not written at all.
- Admission is **not serialized per username**, and **nothing reserves the admitted session** before its accounting exists.
- **Consequence:** every Access-Request processed before the first session's Start is written sees `n=0` and is accepted
  without verification. The resulting duplicate is never evicted afterwards.
- **Proven windows:**
  - T1/T2 — concurrent (11.6–40 ms) or before the Start;
  - T4 — the whole time the Start is missing (5 s and 30 s proven; unbounded until a later Interim / Start creates a row).
- **T5 (both requests see a stale row, the guard runs twice):** not reproduced in 5 trials.
  - In the 3 truly concurrent trials the second guard was consistently slower (501 / 527 / 1165 ms against 189 / 248 /
    532 ms). The CHR SNMP agent appears to answer the two guards one after the other, so the second walk saw the first
    session already up.
  - That protection is a timing property of this NAS, not a guarantee. On a NAS that answers SNMP in parallel, two SAFE
    results are possible. T5 is **not proven and not excluded**.
- **Not a defect of A1's fail-closed contract:** with an open row and an unverifiable NAS (E1), both logins are refused by
  Option G. The gap is that A1, by design, does not verify when `n=0` (first login), and the A2 race lives exactly there.
- **Scope of the conclusion:** proven on CHR 7.24.5 under QEMU TCG with these settings. Real hardware has a shorter
  Accept→Start latency, which shrinks the T1/T2 window but does not close it; T4 does not depend on speed.

## 5. Remediation options (proposal only, NOT implemented — owner D-A2-4)

| Option | Idea | Atomicity | Crash / FreeRADIUS restart | TTL / lost Start | Cross-NAS (same ISP) | Fail-closed / availability | Notes |
|---|---|---|---|---|---|---|---|
| **R1 Admission reservation in the FreeRADIUS PPPoE post-auth path** (single FreeRADIUS per ISP, Option A) | Before Accept, atomically create a per-username reservation and reject if one already exists and is fresh; it ends when the session's Start creates the row, or at TTL | Required: e.g. an atomic create in a local directory or a DB unique key — a plain check-then-insert (`rlm_cache`) is **not** atomic | A file-based lock must survive or expire cleanly across restarts (age-based expiry needed); a DB key survives restarts | TTL must cover Accept→Start (measured ≤160 ms here, seconds on loaded NAS); after TTL, a lost-Start window (T4) reopens unless combined with R2 | Covered, because every NAS of the ISP uses the same FreeRADIUS | Wrongly held reservation = short lockout (≤TTL) of a legitimate reconnect; never an Accept on error | Smallest change that closes T1/T2/T5. Needs owner approval of the mechanism (file vs DB table = schema change) |
| **R2 Accounting-time duplicate eviction** | On every Accounting-Start / Interim: if another open row of the same username is LIVE on any NAS, Disconnect the newer session via the existing CoA client | n/a (post-hoc) | stateless | Covers lost-Start duplicates once any accounting arrives | Covered | Leaves a transient double until the Start / Interim (≤ interim period) — a transient double is still a gate failure (D-A2-2), so this is defense-in-depth only | Uses existing primitives; needs a policy for which session survives |
| R3 Verify on every first login (`n=0`) too | Always run the SNMP check | No: two concurrent checks both see nothing yet | – | – | – | Adds NAS dependency to every login; does not close T1 | Not recommended |
| R4 RouterOS `only-one` / `one-session-per-host` | NAS-side limit | – | – | – | Per NAS only | MikroTik documents `only-one` as ignored with RADIUS; `one-session-per-host` limits per MAC, not username | Rejected as a fix (owner D-A2-3) |

**Recommendation for a separate remediation plan:** R1 (atomic per-username admission reservation) as the closing control,
with R2 as a backstop for lost / delayed accounting. The mechanism choice (local atomic file vs DB unique key) needs an owner
decision. A DB key is a schema change. Validate the fix with this gate plus Cross-NAS (D) on a real CHR2.

## 6. Tooling defects found and corrected during A2 (disclosed; affected records kept and marked)

1. **`pppoe_race` deleted both pppd option files 1.5 s after the release.** A second client launched later than that
   exited with pppd code 2 and never sent a request.
   - **Invalid first-pass records:** `A2.Bscan.2000.0`, `A2.Bscan.3000.0`, `A2.Bscan.5000.0` (11:37–11:38),
     `A2.C.5000.0` (11:39) and `A2.C.30000.0` (11:40). Their "SINGLE_SESSION" verdicts are **void**.
   - **Fix:** each launch thread removes its own file 1 s after its own pppd started. B-scan and C were re-run (11:43–11:49).
2. **The classifier marked a trial INCONCLUSIVE on a NAS sampling gap even when only one Accept existed** (first-pass
   `A2.Bscan.100.0`, 11:35). Fixed: fewer than two Accepts is decisive from RADIUS.
3. **The trial decisions included the setup login of the same user** (the session that created the stale row). The
   first-pass `A2.E1.0` (11:41, recorded SINGLE_SESSION) and `A2.E2.0` (11:41, recorded RADIUS_DOUBLE_ACCEPT_NAS_HELD) are
   **misclassified**: their raw decisions show the race requests were ERROR/ERROR Reject (E1) and SAFE Accept / LIVE
   Reject (E2).
   - **Fix:** decisions are joined with the trial's own pcap. E1 and E2 were re-run (11:50–11:53).
4. **The guard verdict was not parsed** when it shared a log line with the "program executed" marker (first-pass records
   show `guard_verdict: null`). Fixed: independent parsing.

## 7. Regression

- Unit `wp4-unit` + `wp5a-unit`: 54 / 2016 OK.
- Lab, after A2: `OptionGGateTest` A, B, C, E, Hotspot shared-1 + `RealAaaTest::testPppoeSimultaneousUseAllowsOneSession`:
  OK 6 / 117.
- No change to `install/`, `system/`, the guard, the post-auth policy, `radacct`, Hotspot `Port-Limit` or Simultaneous-Use.

## 8. Cleanup

After every trial both clients went down and the NAS table was empty. After A2:
- no `nuxr_wp8_a2` firewall rule and no PPP session remained on the CHR;
- no pppd process and no option (password) file remained;
- `option_g_gate.py off`, then `lab.sh down`: links 0, netns 0, qemu 0, agent 0, containers 0, networks 0;
- lab credential file removed.

No CHR2 was created. AVAIA, the host firewall and the shared networks were untouched.
