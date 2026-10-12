# WP8 H-2 R-H2-B — Architecture & Feasibility Decision Report

Date: 2026-10-11. Study and lab feasibility only. Baseline `wp8/integration-poc@1537775555b3a940e32c8103df4c98bb0549ddf1`.

- **Lab:** two RouterOS CHR 7.24.5 (Hotspot on both), FreeRADIUS 3.2.8 threaded with the production NAS contract
  (`option_g_gate.py on shipped`).
- **Prototype:** a lab-only site variant generated at run time, with prototype tables in the disposable lab database
  only.
- **Not done:** no production policy change, no `install/` or `system/` change, no production query, no commit / push.

**Verdict: `H2_B_FEASIBLE_READY_FOR_DESIGN_APPROVAL`.**

All five gates hold on the real NAS and both database engines:
- **A:** the verification method;
- **B:** the correlation;
- **C:** atomic capacity (after one design correction found by the gate itself);
- **D:** recovery;
- **E:** latency.

The remaining work is design and implementation, not feasibility.

## 1. Proposed architecture

**Global Hotspot admission slots in the RADIUS database of the ISP instance.**

- **Table and identity.** A new table `nuxr_hs_admission` holds one row per (canonical subscriber, slot number).
  - The subscriber key is the exact `radcheck.username` bytes (the H1-A identity).
  - A subscriber has `shared_users` slots (from `control:Simultaneous-Use`, which `RadiusPolicyBuilder` already writes).
- **Admission (FreeRADIUS `post-auth`, Hotspot branch only, directly after the H1-A exact-spelling check):**
  - claim **one** free slot atomically;
  - send `Class = "nuxh1" + 128-bit token` in the Access-Accept;
  - no free slot → refused (fail closed).
- **Accounting.**
  - Start / Interim-Update carrying the token → `ACTIVE`; Stop → `ENDED`.
  - A transition happens only when token + NAS-IP-Address + Acct-Session-Id + Calling-Station-Id all match, never by
    username alone.
- **Recovery: a NAS-side verifier**, run only when a claim finds no free slot (proposed as an exec module like the A1
  guard).
  - For each occupied slot it reads the Hotspot active table of **that slot's NAS** over SNMP (health probe first).
  - It releases a slot only on **proof** that (user, MAC) has no session there.
  - A `PENDING` slot is checked only once it is older than the establishment horizon.
  - An unreachable NAS releases nothing.
  - Every non-Stop release is audited.
- **Isolation.**
  - One ISP = one RADIUS database (the deployment model since WP4), so slots and NAS of different ISPs cannot mix.
  - Any NAS of the ISP may admit the subscriber (no NAS binding, roaming kept).

## 2. Atomic reservation (claim v2, proven in Gate C)

Per Hotspot login, after authentication and H1-A:
1. **Slots exist:** `INSERT IGNORE` of slots `1..shared_users` (state `ENDED`), then a `SELECT` must return
   `s=<shared_users>`.
   - rlm_sql returns an empty value for a write that affects no rows and for an error, so the result is verified by a
     read.
2. **Retransmission re-use:** a `PENDING` slot with the same NAS, Acct-Session-Id, Calling-Station-Id **and Request
   Authenticator** is re-used with the same token (`attach_count + 1`). No second slot is taken.
3. **Pre-check (non-locking):** `SELECT IF(COUNT(non-ENDED) < shared_users, 'free', 'full')`. It is needed only after a
   limit decrease (slots above the new limit still in use).
4. **The claim:** `UPDATE … SET state='PENDING', token=…, attempt identity … WHERE username=<key> AND
   slot <= shared_users AND state='ENDED' ORDER BY slot LIMIT 1`.
   - It is one statement; InnoDB's row lock serializes concurrent claims from any NAS.
   - The number of non-ENDED slots can never exceed `shared_users`.
   - 1 row affected = admitted; anything else = refused.
5. **Late-claim guard** (the A2-R 5000 ms budget, unchanged), before and after the claim. A late creator releases its
   own claim (`attach_count = 0`).

**Claim v1 was rejected by the gate (kept as evidence).** It put the in-use count as a subquery inside the `UPDATE`.
- MySQL 8.0.46 deadlocked 347 of 500 concurrent claims: shared locks taken by the subquery, then the exclusive lock of
  the update.
- It was safe (never above the limit) but not available.
- MariaDB 10.11 had 0 errors.

## 3. Live-session verification (Gate A)

**Methods compared**, from the FreeRADIUS network namespace against both CHRs:

| Method | How | Credential |
|---|---|---|
| **SNMP** | MIKROTIK-MIB **Hotspot active table** `1.3.6.1.4.1.14988.1.1.5.1.1`: col 3 user, col 5 IP, col 6 MAC, col 7 uptime; the row index = the API `.id` | the existing A1 read-only community; private 0600 net-snmp config, never argv |
| **API** | `/ip/hotspot/active/print` | a RouterOS login (admin in the lab; production would need a new read-only API user) |
| **REST** | `GET /rest/ip/hotspot/active` | the same RouterOS login; 0600 curl config file |

Note: the stock checkrad walks the *interface* table, which never contains Hotspot sessions. That is the root of H-2.

**Results:**

| Scenario | SNMP | API | REST |
|---|---|---|---|
| Live session on CHR1 and on CHR2 (user + MAC) | ✔ | ✔ | ✔ |
| Two sessions under one name (told apart by MAC) | ✔ | ✔ | ✔ |
| Ended session → absent | ✔ | ✔ | ✔ |
| Accounting-Start lost → still seen live | ✔ | ✔ | ✔ |
| Accounting-Stop lost → seen absent | ✔ | ✔ | ✔ |
| Management unreachable → **ERROR**, never "absent" | ✔ | ✔ | ✔ |

**Recommended: SNMP.**
- No new credential type: it reuses the A1 contract's community and NAS type.
- It is read-only.
- Inside the container one verification (health probe + table walk) took **116–215 ms** per NAS (Gate E, TCG lab).

The Hotspot `Acct-Session-Id` is **not** exposed by any method. Matching is by (NAS, user, MAC), which identifies a
Hotspot session on one NAS: a RouterOS Hotspot host is a MAC.

## 4. Gate results

| Gate | Result | Evidence |
|---|---|---|
| **A** verification | **PASS** for all three methods, all six scenarios (§3) | `H2B.gate_a` |
| **B** correlation | **PASS** for http-chap, http-pap, cookie re-login and MAC login (mac-as-username) — see below | `H2B.gate_b` |
| **C** capacity on the NAS (claim v2) | **23 / 23 ENFORCED**, 0 exceeded, 0 availability failures — see below | `H2B.gc1/gc2/gc3` |
| **C** capacity SQL (v2), both engines | MariaDB 10.11.19 and MySQL 8.0.46: 25 sessions released at the same instant, limits 1/2/3/5 × 5 rounds — see below | `H2B.gate_c_sql` |
| **C** retransmission | an exact copy after FreeRADIUS forgot the first (7 s) → same claim, same Class (`attach_count` 1); a new attempt of the same client → refused (limit 1); Stop → ENDED | `H2B.gate_c_retx` |
| **D** recovery | §5 / §6: every scenario held the limit (max live 1 at limit 1) | `H2B.gate_d`, `H2B.gate_d_nas_reboot` |
| **E** performance | Access-Request → Access-Accept on the wire: **project site 5 / 11 / 16 ms** vs **prototype 12 / 16 / 19 ms** (min / median / max, 10 logins each); verifier SNMP 116–215 ms per NAS | `H2B.gate_e` ×2 |

**Gate B detail.** For every method:
- the Access-Accept `Class` comes back **unchanged** in Start, Interim-Update and Stop;
- the accounting `Acct-Session-Id` equals the claiming Access-Request's;
- the slot goes `ACTIVE` → `ENDED (acct_stop)`;
- a cookie re-login is a new RADIUS login with its own claim and Class.

**Gate C detail (NAS):**
- `shared_users=1`, CHR1↔CHR2: sequential ×2 and concurrent ×10 → exactly 1 admitted, max live 1;
- `shared_users=2`, 4 clients on 2 NAS: sequential + concurrent ×5 → exactly 2 admitted;
- `shared_users=3`, 5 clients on 2 NAS: concurrent ×5 → exactly 3 admitted;
- every trial's slots returned to `ENDED`.

**Gate C detail (SQL v2):**
- never above the limit; **exactly the limit admitted every round; 0 errors**;
- a limit decrease (3 in use, limit 1) is refused.
- v1: MySQL deadlocks, see §2.

**Gate E budget.** The 5000 ms late-claim budget and RouterOS `/radius timeout=3s` are unchanged. The claim adds about
5 ms; the verifier runs only when the slots are full, at about 0.2 s per occupied NAS (the A1-style deadline bounds an
unreachable NAS).

## 5. Lost accounting

| Case | Behaviour (measured) | Security | Availability |
|---|---|---|---|
| Access-Accept lost (CHR1 replies dropped) | slot `PENDING`; a CHR2 login is **refused**; the verifier keeps a young PENDING, then, after the horizon and with SNMP proof of no session, releases it → the CHR2 login succeeds | held (1 session max) | the subscriber waits for the horizon (15 s in the lab) plus a verification |
| Accounting-Start lost | the session is live, the slot `PENDING`; the CHR2 login is **refused**; the next **Interim-Update carries the Class → `ACTIVE`** | held | a correct refusal |
| Accounting-Stop lost | the slot stays `ACTIVE`; CHR2 refused; the verifier with SNMP **blocked** → `keep:verify-error` (still refused); with SNMP reachable → released → the CHR2 login succeeds | held | stale until a successful verification |
| Late accounting | same as a lost Start until the packet or the Interim arrives; the slot is never freed by time | held | as above |

## 6. Concurrency and recovery

- **Concurrency:** two NAS and up to five clients launched together, and 25 database sessions at the same instant
  (§4): never above the limit.
- **Retransmission:** re-uses the claim by attempt identity (§4).
- **Transaction interruption / DB outage:** the database was paused during a login → **refused**; no slot leaked; the
  next login succeeded after unpause. A claim that commits after the Accept deadline is released by its creator (late
  guard).
- **FreeRADIUS restart:** slots persist (database), and a second NAS login is still refused while the first session
  is `ACTIVE`.
- **NAS reboot (graceful `/system/reboot`):** RouterOS sent **Accounting-Stop `Acct-Terminate-Cause = Admin-Reboot`
  with the Class**, so the slot ended by accounting. An ungraceful loss (power, crash) sends no Stop, which is the
  lost-Stop case (verifier).
- **PENDING of unknown fate:** never released by time alone. It needs the horizon **and** a successful SNMP reading
  without the (user, MAC). If the NAS is unreachable it stays refused (fail closed, availability), and the operator
  release with audit (as in A2-R) is the fallback.

## 7. Risks

**Security** (the model is fail-closed; none of these opens a second session):
1. **Verifier false "absent".** A release needs a successful health probe plus a successful table walk without the
   (user, MAC).
   - A NAS that hides a live session from SNMP would cause over-admission.
   - Requirement: the verifier must be pinned to the MIB table proven here, and re-proven per RouterOS major version.
2. **Several sessions of the same user with the same MAC on one NAS.** RouterOS keys a Hotspot host by MAC, so this is
   a single session. A stale slot of that MAC is kept while a newer session of it is live (conservative).
3. **MAC randomisation / NAT in front of the NAS:** the verifier keys by MAC as RouterOS does. An ambiguous match keeps
   the slot (conservative).
4. **ISP isolation** relies on one RADIUS database per ISP. A shared database would need the ISP in the key (a design
   item, not today's model).

**Availability:**
1. A lost Accept, Stop or Start delays the next admission (horizon + verification), or keeps refusing while the NAS
   management path is down.
2. SNMP must be reachable from FreeRADIUS on every Hotspot NAS (already part of the A1 contract).
3. Under heavy same-user contention, refusals are expected by design (exactly the limit is admitted).

**Operational:** a slot table that grows with subscribers × `shared_users` (small rows, never deleted, re-used) and an
audit table.

## 8. Implementation cost and complexity

About the size of A2-R (medium–high):
- **FreeRADIUS site:** about 90 lines (Hotspot claim) + 25 (accounting).
- **Additive schema:** 2 tables (`PROPOSED_nuxr_hs_admission.sql`, plus a readiness check and an operator release
  script).
- **Verifier exec program:** a shell script like the A1 guard, about 150 lines, SNMP only, with a deadline.
- **README / operator runbook.**
- **Tests:** harness contract tests on both engines (SQL concurrency, retransmission, accounting transitions,
  fail-closed paths) and real-NAS gates (two CHRs: Gates C and D as automated tests).
- **Unchanged:** PPPoE / A2-R, VPN, H1-A semantics, `install/radius.sql`, `system/`, PHP code.

## 9. Simplest implementation plan (proposal; each step needs approval)

1. **Schema:** `install/freeradius/nuxr_hs_admission.sql` (additive, idempotent), plus a readiness check.
2. **Site:** the claim v2 block after H1-A; the Class "nuxh1" accounting transitions; the same fail-closed reject as
   H1-A (no reason disclosed) or a dedicated message (decision).
3. **Verifier:** an SNMP Hotspot-table exec module, called only when the slots are full.
   - It releases a slot only on proof; a young `PENDING` is kept; any error means no release.
   - It retries the claim once.
4. **Operator release procedure** for unprovable `PENDING` / `ACTIVE` slots (audit table).
5. **Tests:** harness (both engines) plus the real-NAS gates from this study (`dev/wp8/h2b_gate.py`) promoted to PHPUnit
   lab tests.
6. **Docs:** README Hotspot section, NAS contract (SNMP on every Hotspot NAS), runbook.

## 10. Acceptance conditions before production

1. **Never above `shared_users`:** in automated real-NAS tests across ≥ 2 NAS (sequential, both directions,
   concurrent ≥ 10 trials, `shared_users` 1 / 2 / 3, ≥ 3 clients), and in a SQL concurrency test on **both** engines
   with 0 errors.
2. **Class correlation:** proven for every Hotspot login method in use (http-chap, http-pap, cookie, MAC) on the
   production RouterOS version(s).
3. **Verifier:**
   - proven per production RouterOS version: live, ended, lost Start, lost Stop, unreachable = error, two sessions per
     name;
   - it never releases on error or on time alone;
   - a deadline within the Accept budget.
4. **Recovery drills:** lost Accept, Start and Stop, FreeRADIUS restart, DB outage, NAS reboot (graceful and
   ungraceful).
5. **Unchanged:** H1-A, A2-R and PPPoE regression green; the full harness on both engines; the WP8 security gates
   extended with an H-2 gate.
6. **Read-only production impact queries (separate authorization):** Hotspot subscribers using more than one NAS, the
   `shared_users` distribution, SNMP reachability of every Hotspot NAS.
7. **Operator runbook** and owner acceptance; AV-1 stays an accepted open risk (it is not changed by R-H2-B).

## 11. Evidence and status

**Evidence** (`owner-review/WP8/evidence/h2b/`):
- `lab-evidence.jsonl`: 23 records, including the failed first retransmission test, claim v1, the reboot tooling
  error and three NOTE records;
- `lab-console.out`;
- `PROPOSED_nuxr_hs_admission.sql` (proposal; applied only to the lab DB and a throwaway MySQL);
- `PROTOTYPE_site_hsres.diff` (the lab-only prototype against the project site).

**Kept failures (not counted as passes):**
- prototype iteration 1: rlm_sql's empty result for a no-op `INSERT IGNORE` refused the retransmission;
- claim v1: MySQL deadlocks;
- the first reboot run: a tooling `TimeoutError`.

**Exit codes:**
- all tool runs 0, apart from the crashed reboot case, which is recorded as `ERROR` in the evidence;
- `lab.sh down` reports everything 0.

**Lab tooling** (uncommitted):
- `dev/wp8/h2b_gate.py` (new; lab only);
- `dev/wp8/h2_gate.py` and `dev/wp8/lab.sh` (from H-2).

**Observation:** the existing lab helper `checkrad_gate.sql()` (since A1) passes the lab DB password as a client
argument inside the disposable container. The new H2B stress code uses `MYSQL_PWD` instead. This is a lab-tooling
hygiene item for a later cleanup.

**QA and Git:**
- `install/` and `system/` unchanged (0 diff lines); manifest 42/42; secret sweep 0 hits;
- backups `wp8-h2b-prefix-backup-20261011T090159Z` and `wp8-h2-prefix-backup-20261011T081453Z` verified;
- the lab is down and clean;
- `HEAD` = `origin` = `15377755`. No commit, push, merge or deploy. V-1 and A3 are not started.

**Verdict: `H2_B_FEASIBLE_READY_FOR_DESIGN_APPROVAL`.** Stopped for review.
