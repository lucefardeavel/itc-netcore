# WP8 H2-B — PENDING Safety Gate & Final Design Decision

**Verdict: `H2B_DESIGN_SAFE_READY_FOR_IMPLEMENTATION`** — with one design change.

**What failed.** The feasibility prototype's recovery rule failed the gate. That rule automatically released a `PENDING` slot once a 15 s horizon had passed and SNMP showed no session. On the real lab it let two sessions run under `shared_users = 1`. The prototype rule is therefore **rejected**.

**What replaces it.** The final design uses **Strict Mode**, the rule already accepted for PPPoE in A2-R:
- A `PENDING` slot is never released by time or by SNMP.
- It leaves `PENDING` only in four ways: its own accounting, its own late-claim self-release before any reply, or an audited operator release. That operator release is a separate decision.

Strict Mode held the limit in every scenario. Its availability cost is set out in section 6.

| Item | Value |
|---|---|
| Date | 2026-10-11 |
| Baseline | `wp8/integration-poc@1537775555b3a940e32c8103df4c98bb0549ddf1` (HEAD = origin; nothing committed) |
| Lab | FreeRADIUS 3.2.8 (threaded); RouterOS CHR 7.24.5 ×2 (CHR1 10.88.0.2, CHR2 10.88.0.3); MariaDB 10.11 |
| Tooling | `dev/wp8/h2b_gate.py`, lab only. New site modes `hsres2` (final candidate) and `hsres2_hold` (+ lab hold points) |
| Evidence | `lab-evidence.jsonl` (records of this gate), `lab-regression.jsonl`, `lab-console.out`, `PROTOTYPE_site_hsres2.diff`, `hsres_to_hsres2.diff`, `LAB_ONLY_hold_points.diff` |

Previous gates A–E are **not** re-run or re-analysed. Their evidence (`../h2b/`) and the H-2 evidence (`../h2/`) are kept unchanged. A pre-change backup is at `/srv/phpnuxbill/wp8-h2b-pending-prefix-backup-20261011T102103Z` (SHA256SUMS verified).

---

## 1. The question

The scenario, on real FreeRADIUS 3.2.8:
1. A Hotspot login claims a slot (`PENDING`).
2. FreeRADIUS stalls after the claim, before the Access-Accept.
3. The recovery horizon passes.
4. SNMP shows no session.
5. A login from another NAS takes the slot.
6. The stalled request resumes.

**Can its Access-Accept still create a second session?**

## 2. How the stall was produced

The `hsres2_hold` site adds two **lab-only** hold points. Each is a SQL `SLEEP` of exactly one attempt, keyed by its Calling-Station-Id in the lab table `nuxr_h2b_lab_hold`. The FreeRADIUS thread really blocks, while every other request is still served.
- `post_claim`: after the claim UPDATE, **before** the last age check (AV-5 expression, 5000 ms).
- `pre_reply`: **after** the last age check and after the Class is set. This is the last statement before FreeRADIUS sends the Access-Accept, i.e. the window that no in-policy check can cover.

The hold points exist only in `LAB_ONLY_hold_points.diff`. They are not part of the candidate.

## 3. Results

### 3.1 P0 — where is the NAS deadline, and is a late Access-Accept discarded?

- **Setup:** CHR1 `/radius timeout=3s` (the A1 contract value). One Hotspot login; the Access-Accept is held at `pre_reply` for H seconds.
- **Measurement:** times are on the wire, relative to the first Access-Request.

| Hold H | Access-Request transmissions (ms) | Access-Accept on the wire | Login page | NAS session | Accounting | Slot |
|---|---|---|---|---|---|---|
| 2.0 s | 0 | 2048 | logged in | 1 | Start | ACTIVE |
| 6.5 s | 0, 2998, 6003 | 6519 | logged in | 1 | Start | ACTIVE |
| 8.0 s | 0, 3010, 6018 | 8017 | logged in | 1 | Start | ACTIVE |
| 8.7 s | 0, 3028, 6036 | 8718 | logged in | 1 | Start | ACTIVE |
| 9.4 s | 0, 2999, 6003 | **9421** | "RADIUS server is not responding" at 9.1 s | **0** | none | **PENDING (stuck)** |
| 11.0 s | 0, 3019, 6090 | **11021** | same, at 9.2 s | **0** | none | **PENDING (stuck)** |
| 14.0 s | 0, 3039, 6043 | **14034** | same, at 9.2 s | **0** | none | **PENDING (stuck)** |

**Findings:**
- **Deadline:** the RouterOS 7.24.5 Hotspot gives up between 8.7 s and 9.4 s, i.e. 3 transmissions × 3 s. Every retransmission has the same Id and authenticator.
- **Late replies are dropped:** every late Access-Accept was really sent. Its Response Authenticator verifies with the secret, so the NAS would accept those bytes. The NAS still discarded each one: no session and no accounting.
- **Cost of a late reply:** the claim is left `PENDING` with nothing to confirm or end it.

### 3.2 P4 — does FreeRADIUS stop a late reply itself?

**No.** The test held a synthetic claim at `pre_reply` for 35 s, longer than `max_request_time = 30`. FreeRADIUS 3.2.8 still sent the Access-Accept, at 35,020 ms, and logged nothing beyond `Sent Access-Accept`.

**Consequence:** nothing on the server side bounds how late an Accept can leave FreeRADIUS. The only bound is the **NAS's own RADIUS window**, which is a NAS configuration value that FreeRADIUS can neither see nor enforce.

### 3.3 P1 — the owner's six-step scenario on the real NAS

Each run follows the same steps:
- A CHR1 login (c2) claims slot 1 under `shared_users = 1` and is held 22 s.
- At 1 s, the verifier ran on the still-young claim.
- At about 17 s, SNMP read CHR1 (`ok`, no session of the user in all seven runs) and the verifier ran under the rule shown.
- At about 20 s, CHR2 client c7 logged in.
- At 22 s, c2 resumed.
- Live sessions were then sampled for 8 s.

| Run | Rule | Hold point | CHR1 timeout (NAS window) | Verifier at ~17 s | CHR2 login | Stalled CHR1 request | Max live | Result |
|---|---|---|---|---|---|---|---|---|
| a | prototype (horizon 15 s + SNMP) | pre_reply | 3s (~9 s) | **released** | admitted | Accept at 22,028 ms **discarded** by CHR1 | 1 | held — **only because 9 s < 15 s** |
| **b** | prototype (horizon 15 s + SNMP) | pre_reply | **10s (~30 s)** | **released** | admitted | Accept at 22,014 ms **taken by CHR1 → session** | **2** | **EXCEEDED** |
| c | **Strict** | pre_reply | 10s | keep (`pending-strict`) | **refused** | Accept at 22,013 ms taken → session, slot ACTIVE via its Start | 1 | held |
| d | **Strict** | pre_reply | 3s | keep | **refused** | Accept at 22,017 ms discarded; slot stays **PENDING** | 0 → **1 after operator release** | held; availability cost (§6) |
| e | prototype | post_claim | 3s | released | admitted | resumes; last age check fails → `reject:late-claim`; self-release is token-conditional → **CHR2's claim untouched** | 1 | held |
| f | **Strict** | post_claim | 3s | keep | refused | `reject:late-claim`; its own claim → `ENDED late_claim` (slot free again) | 0 | held |
| g | prototype | post_claim | 10s | released | admitted | `reject:late-claim` even though CHR1 was still waiting; CHR2's claim untouched | 1 | held |

**Run b in detail:**
- **Wire:** CHR1 Access-Requests at 339 / 10,345 / 20,358 ms. CHR2 Access-Request at 20,494 ms, Accept at 20,528 ms, Start (Class token `b1ca0811…`). CHR1 Access-Accept at 22,353 ms, then a CHR1 Start (Class token `5c463e9e…`) at 22,399 ms.
- **Live:** one session on CHR1 and one on CHR2.
- **Detection:** the candidate's orphan-accounting check recorded the CHR1 Start as `orphan_accounting / Start`, because its token holds no slot. Detection works, but it cannot prevent the session.

**Conclusions:**
1. **The owner's hazard is real.** A stall *after the last age check* can deliver an Access-Accept after the slot was released, re-assigned and verified empty by SNMP.
   - Whether the NAS still takes that Accept depends only on the NAS RADIUS window (3 × `/radius timeout` on RouterOS).
   - With the prototype horizon (15 s), any NAS whose window is longer than the horizon can over-admit (run b: timeout 10s, window ~30 s). On RouterOS that means a `/radius timeout` above about 5 s. FreeRADIUS does not stop it (P4).
2. **A stall before the last age check is safe under both rules** (runs e, f, g).
   - The AV-5 age check refuses the resumed request.
   - Its self-release is conditional on its own token, `state = 'PENDING'` and `attach_count = 0`, so it can never end another claim.
3. **Strict Mode held the limit in every run.** It never releases a `PENDING`, so the slot cannot be re-assigned while an Accept may still be in flight (runs c, d, f).

### 3.4 P2 — concurrency during a stall

- **Setup:** Strict Mode, `shared_users = 2`. c2's claim is held 12 s at `pre_reply` (beyond the 9 s window). During the hold, four clients log in at the same instant: c4 and c5 on CHR1, c7 and c8 on CHR2.
- **Result:** exactly **one** was admitted (c7 → slot 2). The others got `reject:full` / `reject:full-precheck`.
- **Verifier:** `keep:pending-strict` for slot 1 and `keep:live` for slot 2.
- **Outcome:** c2's late Accept was discarded. Max live 1. Final slots: PENDING, ACTIVE. **Pass.**

### 3.5 P3 — retransmissions during a stall

- **Setup:** a synthetic NAS sends the same packet at 0, 3,074, 6,123 and 9,638 ms (same source, same bytes) while FreeRADIUS holds the claim 8 s.
- **Replies:** one Access-Accept at 8,030 ms. The copy at 9,638 ms got the **cached identical reply** (9,897 ms). The copies sent during processing got nothing.
- **Slots:** one claim (`attach_count 0`). A new attempt (new authenticator) was refused. **Pass.**
- **Real NAS:** the wire traces show the same pattern (Access-Request ×3 with one Id and authenticator; one reply).

## 4. Safe-recovery decision

**Rejected rule:** "release a `PENDING` slot when it is older than a horizon and SNMP shows no session."
- It is safe only if the horizon exceeds the RADIUS window of the claiming NAS.
- That window is NAS configuration, invisible to FreeRADIUS, and not bounded by FreeRADIUS either (P4).
- The gate reproduced the over-admission (run b).

**Adopted: Strict Mode for Hotspot slots**, identical in principle to the accepted A2-R PPPoE rule ("A PENDING reservation of another attempt is NEVER overridden — not by time, not by SNMP").

| Transition | Allowed by | Evidence |
|---|---|---|
| `PENDING → ACTIVE` | Accounting Start / Interim-Update with the slot's token, NAS, Acct-Session-Id, MAC | Gate B, F1 |
| `PENDING/ACTIVE → ENDED` | Accounting-Stop with the same four values | Gate B, F1 |
| `PENDING → ENDED` | the creating request itself when the last age check fails (`late_claim`, token- and `attach_count`-conditional), **before** any reply | P1 e/f/g |
| `ACTIVE → ENDED` | the verifier, on SNMP proof (health probe OK and the NAS Hotspot table has no session of user + MAC); any error = keep | F3, Gate D, F4 |
| `PENDING → ENDED` | **only** the audited operator procedure (a separate owner decision), naming the claim by its full token | P1 d (lab model) |
| anything else | never: no release by time, by SNMP alone, by FreeRADIUS restart or by NAS reboot detection | — |

**Why `ACTIVE` may be released on SNMP proof:**
- The slot's token came back in accounting, so the NAS has already consumed the Access-Accept and created the session.
- One Access-Request yields at most one session, and a duplicate reply is ignored by the NAS.
- So once SNMP shows that session gone, no late Accept of that claim can re-create it.

**Proposed operator procedure (separate decision).** It mirrors `nuxr_admission_release.sql`:
- It names the claim by username, slot and **full token**, and ends it only if that claim still holds the slot.
- It writes an append-only audit row whether or not it releases.

The operator must record NAS-side proof that the claim's request can no longer be accepted, either of:
- (a) the NAS rebooted after `created_at` (the A2-R runbook proof); or
- (b) the elapsed time exceeds that NAS's configured RADIUS window, read from the NAS (`/radius print`: timeout × 3), with SNMP showing no session.

**Defence in depth (in the candidate).** A Start or Interim-Update whose `nuxh1` token holds no live slot writes an `orphan_accounting` audit row. Run b proved it fires. In implementation it should raise an alarm.

## 5. Final design checks

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | Atomic claim under concurrency | One conditional UPDATE per claim. Gate C (23/23 on NAS; 25 concurrent sessions × limits 1/2/3/5 on MariaDB + MySQL, 0 errors). P2: 1 of 4 admitted next to a held claim. F5 bursts: 4 concurrent on two NAS, all within the limit | `../h2b/`, P2, F5 |
| 2 | `shared_users` change with live sessions | Limit 2 → 1 with two live sessions: a new login is **refused** (`full-precheck`). The slot above the limit still ends by its own Stop. With one session left a new login is still refused; with none it is admitted. Limit 1 → 2: admitted again (live 2) | F2 |
| 2a | Existing sessions after a decrease | **Not cut** (no CoA in this design). They count until they end, so new admissions wait. A forced disconnect on decrease would be a separate product decision | F2 |
| 3 | Duplicate / reordered accounting | Stop before Start → ENDED, and the late Start / Interim do **not** revive it (orphan audit rows). Duplicate Start → ACTIVE once. Duplicate Stop → ENDED once | F1 |
| 4 | An old Stop cannot end a new claim | New claim (new token) on the same slot, **same Acct-Session-Id and MAC**: stale Stop / Interim of the two older claims, a Stop with the right Class but another NAS-IP, and a Stop without Class all leave it ACTIVE. Only its own Stop ends it | F1 |
| 5 | Re-use of Acct-Session-Id / MAC / SNMP index | Accounting matches **token AND NAS AND Acct-Session-Id AND MAC**; the token is per claim (128 bits, UNIQUE), so re-used ids cannot cross claims. The verifier never uses the SNMP index; it matches user + MAC on the slot's NAS. A re-used MAC on the same NAS can only make it **keep** a slot (conservative) | F1, Gate A |
| 6 | SNMP missing or failing | SNMP disabled on CHR1, wrong community, management path dropped: all `keep:verify-error` (health probe first). A `PENDING` is never released by SNMP | F3, Gate A/D |
| 7 | The old Simultaneous-Use check blocking a legitimate recovery | **Found and fixed in the candidate** (see below) | F4 |
| 8 | Real time bounds, not only the median | Access-Request → reply on the wire, `hsres2`: 30 sequential logins min / p50 / p95 / **max** = 8 / 13 / 48 / **94 ms**; 24 requests in concurrent bursts 6 / 13 / 24 / **24 ms** | F5, Gate E, P0 |
| 9 | PPPoE, H1-A, VPN unchanged | The candidate diff adds lines only inside the Hotspot post-auth branch (after the H1-A `exact` tag), the `nuxh1`-Class accounting block, and a Hotspot-only condition in `session {}`. The only line removed is `sql` in `session {}`, re-added under `else`. Regression results in §7 | `PROTOTYPE_site_hsres2.diff`, §7 |

**Check 7 in detail:**
- **What happened:** lost Stop → radacct row stays open; the verifier releases the ACTIVE slot on SNMP proof; then SNMP to CHR1 fails.
  - The stock `session { sql }` Simultaneous-Use check runs `checkrad` (functional variant) for the stale row and waits 10,058 ms.
  - The slot policy's first age check then refuses with `reject:late`, and the legitimate CHR2 login fails ("RADIUS server is not responding").
- **Fix (`hsres2`):** that stock check is not run for Hotspot. Hotspot = Service-Type Login-User AND NAS-Port-Type Wireless-802.11, as in H1-A. The slots are the Hotspot limit, and `checkrad` cannot see Hotspot sessions at all (H-2 root cause).
- **Result with the fix:** the same login is accepted in 15 ms.
- **Shipped `checkrad`:** with the stock image (shipped mode) the check fails immediately (`sh: Illegal option -r`, exit 0 in 52 ms), so it is a no-op for Hotspot.
- **PPPoE is unaffected:** its `sql` session check is unchanged.

**Check 8 — the time budget:**
- **NAS side:** RouterOS retransmits after 3 s and gives up at ~9 s. The AV-5 guard refuses anything older than 5000 ms at the last check.
- **Verifier:** on the full path the SNMP verifier costs 116–215 ms per NAS in-container, or about 1 s when a NAS does not answer (`-t1 -r0`).
- **Deadline needed:** the implementation needs a hard total deadline (proposed 2 s) so that a full-path verification can never push a request past the 5000 ms guard. Run out of time = keep = refusal.

## 6. Availability impact of Strict Mode

**When a slot stays `PENDING` until an operator releases it.** Only when the claim's Access-Accept never produced a session the NAS reports with the token:
- every reply to the claiming request lost, or later than the NAS window: three transmissions, and FreeRADIUS re-sends its cached reply to each retransmission (P3);
- or a FreeRADIUS stall of more than ~9 s **after** the last age check (P0, P1 d);
- or the NAS failing after accepting but before any accounting;
- or accounting lost for the whole session (Start, every Interim and the Stop).

**How often:** in the lab the claim-to-reply time is ≤ 94 ms against a 9 s window, so this needs an extreme stall or total reply loss.

**Effect on the subscriber:** one slot is lost until the operator acts. With `shared_users = 1` the subscriber is refused on **every** NAS (P1 d: refused, then admitted after the audited release).

**What softens it, all safe:**
- the late-claim self-release covers FreeRADIUS slowness detected before the reply (P1 e/f/g);
- an Interim promotes a lost Start (Gate D);
- a Stop ends a PENDING (F1);
- a read-only alert on `PENDING` older than ~60 s;
- the audited operator procedure.

**Trade:** this is the same as the accepted A2-R PPPoE behaviour.

## 7. Regression with the candidate site (`hsres2`) active

- **Setup:** site `hsres2` with stock `checkrad` in shipped mode, Option G on, FreeRADIUS threaded.
- **Labels:** `h1_gate.py` records the site as `other` because it does not know the new variant. `h2b_gate.py site_mode()` confirmed `hsres2` and 0 config errors before the run.
- **Records:** `lab-regression.jsonl`.

| Suite | Case | Result |
|---|---|---|
| H1-A (Hotspot, real CHR1) | `identity` | pass. UPPER refused (no session, no accounting); exact spelling logged in |
| | `shared1` | pass. Every pair: live ≤ 1 |
| | `shared2` | pass. exact ×3 → 2 live, the third refused; three spellings → 1 |
| | `concurrent` | pass. Limit 1: live 1 in all 13 trials; limit 2 (three spellings): 1 |
| | `negatives` | pass. Wrong password, unknown user and equivalent spelling all get the same refusal, Reject ~1 s, no attributes |
| | `duplicate_identity` | pass. Refused, live 0 |
| A2-R (PPPoE, real CHR1) | `G-CLASS.basic` | pass |
| | `G-CLASS.lost_start` | pass |
| | `G-CLASS.stop_without_start` | pass |
| | `G-LIFECYCLE.lost_stop_takeover` | pass |
| | `G-RETX.real_replay` | pass |
| | `G-LATE.late_claim_real` | pass |
| | `G-LATE.nas_deadline` | pass |

**Not run:**
- H1 `lost_accounting` was deliberately not run. Under the slots a relogin after a lost Stop is decided by the verifier (F3, F4, Gate D), not by the radacct path that case asserts.
- VPN has no RADIUS path in the lab. It is unchanged by construction: no line outside the Hotspot / `nuxh1` regions changes, and PPPoE accounting carries `nuxr1`, which the `nuxh1` regex cannot match.

## 8. Implementation plan (built on the tested candidate)

**Files:**
1. `install/freeradius/nuxr_hs_admission.sql` (new, additive, idempotent): `nuxr_hs_admission` + `nuxr_hs_admission_audit` exactly as `../h2b/PROPOSED_nuxr_hs_admission.sql`. Not `install/radius.sql`.
2. `install/freeradius/sites-available/phpnuxbill`, three additions, with A2-R and H1-A text unchanged:
   - **Post-auth Hotspot branch,** after the H1-A `exact` tag, the `hsres2` block:
     - limit and identity checks; age check;
     - slots created with `INSERT IGNORE` and verified with a SELECT;
     - retransmission re-use; non-locking pre-check; claim v2;
     - last age check with token-conditional self-release;
     - `Class = "nuxh1" + token`;
     - **plus** the verifier call on the full path, ACTIVE only.
   - **Accounting:** the `nuxh1` transitions, plus the orphan audit.
   - **`session {}`:** stock check not run for Hotspot.
3. `install/freeradius/bin/nuxr_hotspot_slot_verifier` + `mods-available/nuxr_hotspot_slot_verifier`:
   - exec, read-only SNMP, health probe first, 2 s total deadline;
   - releases ACTIVE slots only, conditional on token + state, with an audit row; any error = keep;
   - credentials from a 0600 file, never argv.
4. `install/freeradius/nuxr_hs_admission_release.sql`: operator procedure (**inactive until its separate approval**).
5. `install/freeradius/README.md`:
   - Strict Mode; the NAS window rule for the operator proof; SNMP prerequisite per Hotspot NAS;
   - RouterOS re-verification per major version; `date` module unchanged.

**Tests:**
1. **Unit source tests** (`tests/Unit/Wp4/HotspotAdmissionSourceTest.php`):
   - the block's order: identity → age → claim → age → Class;
   - every write verified by a SELECT or sentinel;
   - no PENDING release outside the creator / accounting / operator;
   - `session {}` Hotspot-only bypass; PPPoE and H1-A text byte-identical.
2. **Lab gates** (`dev/wp8/h2b_gate.py` against the **project** site, not a variant; hold points via a lab-only overlay):
   - P0, P1 (Strict runs c, d, f), P2, P3;
   - F1–F5; Gates B, C (NAS + SQL both engines), D.
3. **Regression:** A2-R and H1-A lab cases, plus the full PHPUnit harness on MariaDB and MySQL.

**Order:** schema → site block → verifier → source tests → lab gates → README → operator procedure (held for its decision).

## 9. Restrictions honoured

- **No changes to:** `install/` (including `install/radius.sql`), `system/`, A2-R policy, H1-A policy, production settings or data. 0 lines diff in `install/` and `system/`.
- **Lab only:** the prototype exists only as run-time site variants inside the lab container (marker `wp8-h1-site`, as before). The hold table is lab-only.
- **CHR1 `/radius timeout`** was set to 10s only inside runs b, c and g, and restored to 3s in `finally` (verified 3s afterwards).
- **Git and scope:** no commit, push, merge or deploy. No V-1 or A3.
- **Evidence kept:**
  - every failure is recorded and none was counted as a pass: run b EXCEEDED; the stuck PENDING at H ≥ 9.4 s;
  - the first F4 runs (shipped `checkrad`, which did not exercise the failure) are recorded, with a NOTE.
