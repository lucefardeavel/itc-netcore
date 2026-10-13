# WP8 — Owner decisions (addendum to `owner-review/WP8/PLAN.md`)

Recorded from the owner review "WP8 OWNER REVIEW — PLAN APPROVED WITH AMENDMENT" (2026-10-06). The plan commit
`5de6d8e6049f0482294102ddb7fe0bab493c0b74` (parent `5bdc5745`) was independently verified and is
**APPROVED WITH OWNER AMENDMENT W8-A1** (local CHR feasibility first). This file is docs-only; it changes no runtime.

## Amendment W8-A1 — local CHR feasibility gate

The absence of `/dev/kvm` and nested `vmx/svm` proves only that hardware acceleration is unavailable, not that a
RouterOS CHR cannot boot under QEMU software emulation (TCG). A tightly isolated feasibility probe was required before
`NOT_READY_REAL_NAS_REQUIRED` may be accepted as final. Result: see `CHR_FEASIBILITY.md` (**PASS_LOCAL_CHR**).
Consequence: **OD-W8-1 (owner-provided physical MikroTik + WireGuard) is no longer required**; the preferred WP8 POC
topology is fully local and isolated. Full WP8 (slices S1–S8) does not start until the owner reviews the evidence.

## Locked decisions

| ID | Decision | Status |
|---|---|---|
| **OD-W8-2** | No public RADIUS. Local CHR → fully isolated local networking. External NAS (fallback only) → WireGuard/private tunnel only. | APPROVED |
| **OD-W8-3** | **Option A**: keep the existing `nas.ports` / 3799 model; configure `/radius incoming accept=yes port=3799` for the POC; document "Ports = CoA / Disconnect destination port; empty means 3799; RouterOS factory/default incoming port is 1700". **No UI change during WP8** (UI clarification may be a separate later refinement). | APPROVED |
| **OD-W8-4** | NAS identity must satisfy `radacct.nasipaddress == nas.nasname`. External NAS: `nas.nasname = RouterOS /radius src-address = tunnel address`. Local CHR: the deterministic CHR RADIUS source IP on the isolated lab network (`10.88.0.2`). If real evidence contradicts this, STOP. | APPROVED (gate evidence: satisfied, `nas_id = 1` matched on both disconnects) |
| **OD-W8-5** | **Option A**: RouterOS API TCP 8728 only inside the isolated local lab or over the private WireGuard path. No public API. API-SSL change deferred, not part of WP8. | APPROVED |
| **OD-W8-6** | AMENDED: for local CHR, QEMU and test-client packages are authorized (installed set in `evidence/chr-feasibility/packages-installed.txt`). `wireguard-tools` only if local CHR fails and the external-NAS fallback is selected (not installed). `radclient` / FreeRADIUS utilities stay container/test scoped. | APPROVED |
| **OD-W8-7** | **Option A**: production-style `read_clients=yes` from the `nas` table during the WP8 POC. | APPROVED (not yet exercised in the gate, which used the harness `clients.conf`; see OD-W8-10) |
| **OD-W8-8** | AMENDED: preferred clients are independent Linux namespace/container clients for PPPoE and Hotspot in the local CHR lab (gate: namespaces `wp8c1`, `wp8c2`). External physical clients are not required. For an external NAS, same-router loopback is acceptable only as explicitly weaker fallback evidence. | APPROVED |
| **OD-W8-9** | **Option A with stronger classification**: new carry-forward **R-WP8-CARRY-01** (below). Not fixed in the gate; legacy removal routes are never used as canonical WP8 lifecycle evidence. | APPROVED |

## R-WP8-CARRY-01 — Legacy reseller-owned direct removal/deactivation bypasses activation/reversal authority

Status: **OPEN — PRE-EXISTING — MEDIUM — NOT CAUSED BY WP8**. **MUST BE CLOSED BEFORE PRODUCTION DEPLOYMENT.**

Evidence (reconnaissance 2026-10-06, `PLAN.md` §23a OBS-W8-1):
- Admin legacy plan delete directly calls the device `remove_customer` (`system/controllers/plan.php:443`, no ServiceGate);
- Admin customer deactivate directly calls the device `remove_customer` and marks the recharge off (`system/controllers/customers.php:286`);
- customer-portal deactivate directly calls the device `remove_customer` and marks the recharge off (`system/controllers/home.php:206`);
- these paths can bypass `ActivationReversalService` for reseller-owned customers.

It does not block the integration POC: it removes service rather than granting unauthorized service, and it is not on
the canonical WP8 lifecycle path. `R-HF-CARRY-01` remains untouched and OPEN.

## New decisions raised by the feasibility gate (owner answer requested)

**OD-W8-10 — FreeRADIUS Message-Authenticator policy vs. NAS firmware (blocks S1 configuration choice, not the gate).**
- Problem: the harness FreeRADIUS `clients.conf` sets `require_message_authenticator = yes`. RouterOS **7.12.1** sends no
  `Message-Authenticator` in Access-Request, so FreeRADIUS 3.2.8 silently discarded every request (6 Access-Requests,
  0 replies, `radius timeout` on PPPoE and "RADIUS server is not responding" on Hotspot). RouterOS **7.20.3** sends it
  and authenticates. Evidence: `evidence/chr-feasibility/radius-packets-summary.txt`.
- Options: (A) keep `require_message_authenticator = yes` and require NAS firmware ≥ 7.15 (BlastRADIUS-capable) —
  **recommended**; WP8 runs on CHR 7.20.3; document the firmware floor as an operational rule. (B) set
  `require_message_authenticator = auto` for the NAS client (FreeRADIUS recommended mixed-client value) — a hardening
  relaxation; a lab-only variant was prepared but **not applied** (`lab-clients.conf.PREPARED-NOT-APPLIED.txt`) because
  the owner had not authorized weakening the setting. (C) `no` — rejected.
- With OD-W8-7 (`read_clients=yes`), the per-client value comes from FreeRADIUS SQL client defaults, not from the
  `nas` table (it has no such column): WP8 S1 must verify the effective value and record it.
- Blast radius: FreeRADIUS configuration only. Blocks implementation: no (A needs no change).

**OD-W8-11 — CoA source address must be a RouterOS `/radius` entry (binding operational rule candidate).**
- Evidence: a Disconnect-Request from the FreeRADIUS-co-located address (`10.88.0.10`, the only `/radius` server on
  CHR) is answered (NAK 406 for an unknown session; ACK for live sessions); the identical request from the php82
  container's own address (`10.88.0.20`) is silently ignored → `UNKNOWN COA_NO_RESPONSE` after 3 identical sends.
- Options: (A) the application host shares the FreeRADIUS address (production co-location; lab: app probes run in
  the FreeRADIUS network namespace) — **recommended for WP8**; (B) add the application host as an additional
  `/radius` entry on the NAS (then RouterOS may also send auth/acct to it; needs `service=` scoping). Document in WP8.
- Blocks implementation: no.

**OD-W8-12 — Permanent `dev/wp8/` tooling (proposal, nothing committed yet).** The uncommitted lab files proven in
the gate (`compose.wp8.yml` override, `rosapi.py`, `configure_chr.py`, `cleanup.sh`, `redact.py`, `coa_probe.php`,
`api_probe.php`, `pppd.opts` template) are proposed as generic test tooling under `dev/wp8/` in slice S1, with the CHR
image, secrets and pcaps excluded by `.gitignore`. Owner approval requested before committing them.

## Implementation GO (2026-10-06) — amendments recorded

Owner message "WP8 — FULL REAL FREERADIUS + MIKROTIK INTEGRATION POC — OWNER IMPLEMENTATION GO": feasibility
`ad314341` ACCEPTED (`PASS_LOCAL_CHR`); S1–S8 authorised. Recorded amendments:
- **W8-A2**: canonical RouterOS for all WP8 evidence = CHR **7.24.5** (stable, released 2026-10-01); no silent fallback.
- **OD-W8-10 APPROVED WITH AMENDMENT**: keep `require_message_authenticator = yes`; never `auto`/`no`; RouterOS ≥ 7.15;
  verify the EFFECTIVE policy for SQL-loaded `nas` clients; STOP if it cannot be preserved without a configuration change.
- **OD-W8-11 APPROVED WITH AMENDMENT**: binding invariant "CoA source IP must be a configured RouterOS RADIUS-server
  address"; WP8 uses Option A with the ACTUAL application execution context sharing the FreeRADIUS NAS-visible address
  (no SNAT, no second generic `/radius` server).
- **OD-W8-12 APPROVED**: commit the generic lab tooling under `dev/wp8/` with ignore rules for images, disks, secrets,
  pcaps, serial logs and credentials.
- AVAIA rule: valid snapshot before / check after every substantial slice; STOP on any protected change.
- 406 investigation in S3; no mapping change without owner review. R-WP8-CARRY-01 and R-HF-CARRY-01 unchanged.

## OD-W8-13 — Message-Authenticator enforcement for SQL-loaded NAS clients (NEW, BLOCKING S1)

Raised by the OD-W8-10 verification; full evidence and options in `S1_STOP_MESSAGE_AUTHENTICATOR.md`.
FreeRADIUS 3.2.8 accepts Access-Requests without Message-Authenticator from `read_clients = yes` clients even with the
global `security.require_message_authenticator = yes`. Recommended: **Option A** — enforce in the project virtual server
(`if (!&Message-Authenticator) { do_not_respond }` in `authorize`), proven in the lab. Status: **WAITING OWNER**.

## OD-W8-13 decision and S1 closure (2026-10-06)

Owner message "WP8 — OWNER DECISION OD-W8-13": **Option A approved** — project-level enforcement in
`install/freeradius/sites-available/phpnuxbill`, inside `authorize {}` only, with amendment **W8-A3** (prove MA-1 /
MA-2 / MA-3 and the accounting negative control on a SQL-loaded NAS client).

| ID | Status |
|---|---|
| **OD-W8-13** | **APPROVED — IMPLEMENTED — VERIFIED.** `if (!&Message-Authenticator) { do_not_respond }` is the first statement of `authorize`; nothing in `preacct` / `accounting` / `authenticate` / `session` / `post-auth`. |
| **OD-W8-10** | **CLOSED / SATISFIED BY OD-W8-13.** Hardened requirement preserved; no `auto` relaxation; SQL-loaded NAS path protected; RouterOS firmware floor >= 7.15 documented (site file + `install/freeradius/README.md`). |
| **W8-A3** | MA-1 missing -> no response (policy, before every module); MA-2 wrong HMAC -> "Dropping packet without response ... invalid Message-Authenticator", never reaches authorize, for PAP and CHAP, also as a client's first packet; MA-3 valid -> Accept / Reject; Accounting-Request without Message-Authenticator answered and Start / Interim / Stop written (contract client and real RouterOS 7.24.5). |

S1 verdict: **PASS** (evidence `evidence/s1/`, report `REPORT.md`). Per the owner instruction, S2 to S8 continue
without a further approval unless a stop condition occurs.

## Raised during S2 / S3 (2026-10-06)

**OD-W8-14 — RouterOS Error-Cause 406 (non-blocking, owner review).** RouterOS 7.24.5 never returns 503; it NAKs every
Disconnect / CoA that matches no session with Error-Cause 406 - stale radacct, unknown user, wrong session id, and also
a request carrying no identifying attribute. Options: (A) keep the accepted mapping (406 -> FAILED COA_NAK) and
document that a vanished session shows as `FAILED COA_NAK (406)` in the best-effort annotation - **recommended**,
no code change, no state-machine impact; (B) map 406 to SUCCESS SESSION_NOT_FOUND only when the request carried a
User-Name AND Acct-Session-Id (code change in `CoAClient`, separate approval). Evidence `evidence/s3/`.

**Binding operational rules proven on the real NAS (for the WP8 model):** CoA-ACK is not proof of an address / pool
change (RouterOS ACKs and ignores `Framed-Pool` / `Framed-IP-Address`): address and pool changes always use
policy -> Disconnect -> re-authentication. Rate changes by CoA (`Mikrotik-Rate-Limit`) are applied live.

**STOP (AVAIA)**: see `STOP_AVAIA_S3.md`.

## Final resume directive (2026-10-06) — additive record

Owner verdicts: **S1 ACCEPTED** (`c7811e50`), **S2 ACCEPTED** (`b4347279`), **S3 ACCEPTED_WITH_NOTES** (`cc29b505`).
**OD-W8-14 = CLOSED / OPTION A ACCEPTED** (406 stays FAILED / COA_NAK; `CoAClient` unchanged). The earlier AVAIA stop
(`STOP_AVAIA_S3.md`) is **CLEARED BY OWNER SCOPE CORRECTION**; **AVAIA is out of scope for WP8** (no monitoring, no
snapshots, no reporting). Binding S3 rules adopted: live rate change by CoA; address / pool change only by policy ->
Disconnect -> re-authentication (a CoA-ACK proves nothing for them); one radacct row per session; CoA timeout = UNKNOWN.

| Slice | Result |
|---|---|
| S4 (`67b79099`) | PASS — A -> B -> C -> Sales -> customer; conservation 17.00 = 5.00 + 12.00 from the original ledger rows; Sales scope and redaction held (`evidence/s4/`) |
| S5 (`cb29ac4f`) | PASS — RENEW, CHANGE_PLAN rate / pool, EXPIRY, REACTIVATE, LegacyReprovisionService password / username / uncertain commit on the real NAS (`evidence/s5/`) |
| S6 (this commit) | drills 1-6 and 8-10 PASS; **drill 7 -> STOP CONDITION 9** (`STOP_S6_LOST_STOP.md`) |
| S7, S8 | not started |

**OD-W8-15 (raised in S5, non-blocking, owner decision).** A username change through `LegacyReprovisionService`
(IDENTITY_EDIT, Admin only) re-provisions and disconnects only the NEW RADIUS key; the live session of the OLD identity
survives, and a second session under the new name is possible meanwhile (two concurrent sessions beyond
Simultaneous-Use 1 until the old session ends). Options: (A) also disconnect the old key in the Radius device rename
path (runtime change, separate approval) - recommended; (B) document an operator step (disconnect before renaming).

**OD-W8-16 (raised in S6, BLOCKING).** Lost accounting Stop -> indefinite Simultaneous-Use lockout without a safe
per-subscriber recovery. Options A-D and recommendation (A, staleness-bounded Simultaneous-Use in the FreeRADIUS
project configuration) in `STOP_S6_LOST_STOP.md`.

Carry-forwards unchanged: **R-WP8-CARRY-01** OPEN / PRE-EXISTING / MEDIUM (must close before production);
**R-HF-CARRY-01** OPEN / PRE-EXISTING / MEDIUM.

## OD-W8-16 decision round (2026-10-07) — additive record

Owner verdicts: **S4 = ACCEPTED**; **S5 = ACCEPTED_WITH_NOTE** (note = R-WP8-CARRY-02); **S6 = ACCEPTED_WITH_BLOCKER**
(blocker = OD-W8-16).

**R-WP8-CARRY-02 — OLD RADIUS SESSION SURVIVES USERNAME REPROVISION** (from OD-W8-15). Status: **OPEN — PRE-EXISTING —
MEDIUM — NOT CAUSED BY WP8 — MUST BE CLOSED BEFORE PRODUCTION DEPLOYMENT.** An Admin IDENTITY_EDIT username change can
leave the OLD username's active RouterOS session alive while the NEW username authenticates (two simultaneous sessions
beyond the intended limit; evidence `evidence/s5/`). Privileged action only; no unpaid service, no cross-tenant access;
does not block the POC. Not fixed in WP8. To be resolved before production together with R-WP8-CARRY-01 and
R-HF-CARRY-01.

**OD-W8-16 = OPTION E** (freshness-aware Simultaneous-Use + NAS-side Port-Limit), implementation conditional on a
real-NAS Port-Limit gate for PPPoE AND Hotspot. Status: **IMPLEMENTATION CANDIDATE -> GATE FAILED FOR PPPoE ->
NOT IMPLEMENTED.** RouterOS 7.24.5 enforces Port-Limit on Hotspot (1 and 2) but its PPPoE server ignores it (two live
PPPoE sessions with Port-Limit 1 in the Access-Accept). Details, packet evidence and a lab observation of
`/ppp profile only-one=yes` (which does hold PPPoE to one live session) in `STOP_S6_LOST_STOP.md` (owner resolution
section). WP8 stays stopped; S6 remains ACCEPTED_WITH_BLOCKER; S7 / S8 not started.

## OD-W8-16 second resolution round (2026-10-07) — additive record

Owner: **Option E = REJECTED** (formal). **`/ppp profile only-one=yes` = observation only, not a solution**
(MikroTik: ignored with RADIUS authentication). **Option F** (SQL Simultaneous-Use + stock checkrad `mikrotik_snmp`
real-NAS verification, `delete_stale_sessions = no`, Hotspot-only Port-Limit conditional) investigated by a lab gate:
Gates A, B, C, E and the Hotspot cases pass; **Gate D fails open** (verification failure admits a second real PPPoE
session) -> **OPTION F GATE FAILED -> NOT IMPLEMENTED.** Details `STOP_S6_LOST_STOP.md` (second resolution section),
`evidence/od-w8-16-checkrad-gate/`.

**OBS-W8-8 (new, pre-existing, not fixed, owner classification).** radacct times are written in the database session
time zone (`FROM_UNIXTIME`), `AccountingService::staleSessions()` compares them with the application clock; with a DB
time zone different from the application time zone, live sessions are reported stale (or stale ones missed).

Carry-forwards unchanged: **R-WP8-CARRY-01**, **R-WP8-CARRY-02** (OPEN / PRE-EXISTING / MEDIUM / MUST CLOSE BEFORE
PRODUCTION), **R-HF-CARRY-01**. OD-W8-16 OPEN; S6 ACCEPTED_WITH_BLOCKER; S7 / S8 not started.

## OD-W8-16 third resolution round (2026-10-07) — additive record

Owner: **Option F = REJECTED** (stock Simultaneous-Use verification path fails open: rlm_sql checksimul FAIL ->
`process_checksimul()` allows; stock MikroTik checkrad maps SNMP failures to "not logged in"). Option E stays
REJECTED; `/ppp profile only-one=yes` stays observation only. **Option G** selected for investigation: existing
Simultaneous-Use + project-owned FAIL-CLOSED PPPoE post-auth session guard (real-NAS SNMP verification, tri-state
SAFE / LIVE / ERROR, only SAFE accepts) + Hotspot Port-Limit; gate first, permanent change only if every gate passes.

**Option G gate: PASSED** (clean run, `evidence/od-w8-16-option-g-gate/GATE_RESULTS.md`): discriminator, A-I,
hostile usernames, Hotspot 1 / 2 / accounting lost, functional stock-checkrad variant. Two candidate defects were found
BY the gate and fixed before the passing run (SQL key in post-auth; policy attributes in the guard's Access-Reject);
two lab-harness incidents are disclosed there (duplicate agent; the disposable container's stock checkrad overwritten
through a symlink - affected runs discarded, lab recreated).

**R-WP8-CARRY-03 — ACCOUNTINGSERVICE STALE-SESSION TIME-ZONE MISMATCH** (from OBS-W8-8). Status: **OPEN —
PRE-EXISTING — MEDIUM — NOT CAUSED BY WP8 — MUST CLOSE BEFORE PRODUCTION DEPLOYMENT.** FreeRADIUS writes radacct
times with `FROM_UNIXTIME()` in the database session time zone; `AccountingService::staleSessions()` builds its cutoff
from the PHP application clock; with different time zones a live session may be reported stale or a stale one missed.
Not fixed. `staleSessions()` is not an authority for session admission (Option G uses real NAS state, not row age).

**OBS-W8-9 (new, pre-existing, not fixed):** stock rlm_sql escapes usernames outside `safe_characters` (`=XX`; `"` and
`\` backslash-escaped first) while PHPNuxBill stores the raw username -> such subscribers cannot authenticate (fails
safe); whitespace usernames are refused by the stock `filter_username`.

Carry-forwards: **R-WP8-CARRY-01**, **R-WP8-CARRY-02**, **R-WP8-CARRY-03**, **R-HF-CARRY-01** — all OPEN, production
blockers unless separately closed; none fixed in WP8.

## Owner decisions D1–D5 and A1 execution (2026-10-09) — additive record

Owner approved (2026-10-09, after the Architecture Lock of the same date and the WP8 preflight):
- **D1 order:** A1 → A2 → A3 → A4 → A5 → S7 → S8 → WP8 acceptance; `system/` changes allowed per stage after that stage's plan is approved.
- **D2 packaging:** Option A, complete Option G in the existing FreeRADIUS structure.
- **D3:** protect the five uncommitted files first.
- **D4 SNMP / secrets:** the simplest stable, rebuildable mechanism, isolated per ISP, with documented source, provisioning and rotation.
- **D5:** S7 not before A1, A2 and A3–A5.
- **Additional approval:** the Hotspot-only `Port-Limit` in `RadiusPolicyBuilder` (PPPoE must not rely on it).

**A1 — permanent Option G integration: executed, not committed (pending owner acceptance).**
- Results: `evidence/a1-permanent-option-g/A1_RESULTS.md`.
- Protective backup before any edit: `/srv/phpnuxbill/wp8-a1-backup-20261009T034740Z` (outside Git; 5/5 SHA-256 verified).
- **Superseded wording:** `STOP_S6_LOST_STOP.md` ("Permanent implementation follows in the next commit (owner §30)") — the permanent implementation was made in A1 instead (preflight conflict C-10).
- OD-W8-16 stays OPEN until the owner accepts A1. S6 stays ACCEPTED_WITH_BLOCKER, and S7 / S8 have not started.
- Carry-forwards are unchanged: **R-WP8-CARRY-01**, **R-WP8-CARRY-02**, **R-WP8-CARRY-03** (scope note C-09: `Reseller/Ui/Subscriber.php` has the same time-zone comparison) and **R-HF-CARRY-01** are all OPEN.

**Owner verdict (2026-10-09): A1 = ACCEPTED WITH NOTES.**
- The notes are NOTE-01 (pre-existing PHPUnit warnings and deprecation, non-blocking), NOTE-02 (clean recreation and the lab marker) and NOTE-03 (A1 is no deployment authorization; release items stay open). They are recorded in `evidence/a1-permanent-option-g/A1_RESULTS.md` §6.
- The permanent Option G integration is accepted. The owner did not state a formal change of the OD-W8-16 / S6 blocker status, so none is recorded here.
- A2 has not started.

## A2 — Concurrent first-login race gate (2026-10-09) — additive record

**Owner approval.** The A2 plan was approved with amendments, with these decisions:
- **D-A2-1:** Cross-NAS testing only if no same-NAS race is found.
- **D-A2-2:** any proven live overlap is `TRANSIENT_DOUBLE — SECURITY GATE FAILED`.
- **D-A2-3:** RouterOS `only-one` is recorded only, never changed and never treated as a fix.
- **D-A2-4:** no fix without a separate owner-approved plan.

**Result: `A2_RACE_PROVEN`**, not committed and pending owner review. Details in `evidence/a2-concurrent-first-login/A2_RESULTS.md`.
- **Same NAS (CHR 7.24.5, permanent A1 configuration):** two live PPPoE sessions of one username were proven:
  - two logins 11.6 ms and 40 ms apart: both `n=0`, both accepted without verification, two distinct NAS sessions with traffic on both links until cleanup;
  - with the Accounting-Start lost, the same result for logins 5 s and 30 s apart.
- **Fail-closed checks:**
  - stale row + SNMP down: both concurrent logins rejected, by Option G (ERROR);
  - stale row + healthy SNMP (5 trials): single session only, but by timing, not by design (T5 not proven, not excluded).
- **Not done:** Cross-NAS (D), because the race was proven on one NAS (D-A2-1).
- **No functional change:** `install/`, `system/`, schema, `radacct` and the A1 policy are all untouched.
- **Remediation options:** R1 (atomic per-username admission reservation) with R2 (accounting-time duplicate eviction) as a backstop, both proposed only. They need a separate owner-approved plan.
- **Open:** OD-W8-16 carry-forwards are unchanged. A3 has not started.

**Owner verdict on A2 (2026-10-09):**
- **Test findings ACCEPTED** as engineering evidence.
- **SECURITY GATE FAILED — RACE_PROVEN.** Status: `A2_EVIDENCE_ACCEPTED_RACE_PROVEN_BLOCKER_OPEN`.
- **What A2 found:**
  - A2 was executed.
  - The race is proven:
    - B0: two logins 11.6 ms / 40 ms apart;
    - C: Accounting-Start lost, second login 5 s / 30 s later;
    - E3: accounting lost + SNMP down.
  - E1: fail-closed, enforced by Option G.
  - E2: single session in 5/5 trials, but only incidental to timing.
- **The blocker stays open until a separately approved remediation is implemented and re-gated:**
  - no functional remediation has been made;
  - the A2-R plan is pending owner approval.
- **No production authorization.**
- OD-W8-16 and WP8 are NOT closed by this verdict. A1 is unchanged.
- The gate test `tests/Integration/Wp8/ConcurrentFirstLoginGateTest.php` fails by design.
  - It runs only in the PHPUnit suite `wp8-security-gates`, which is excluded from `wp8-integration`.
  - Its assertions are unchanged.
- Invalid first-pass trials (tooling defects) are listed in `A2_RESULTS.md` §6 and are not used in the conclusion.

## A2-R — Atomic PPPoE admission reservation (2026-10-09 / 2026-10-10) — additive record

**How A2-R got here.** Each step was owner-approved:
1. Engineering decision review (Strict Mode, an atomic reservation in the per-ISP RADIUS DB, per-attempt `Class`, no
   automatic expiry of `PENDING`).
2. Feasibility gates G-CLASS / G-ID / G-RETX / G-SQL / G-LIFECYCLE: `A2_R_FEASIBILITY_PASS_PENDING_OWNER_REVIEW`.
3. Implementation, including "S-1 — HIGH — VERIFIED PPPoE USERNAME-EQUIVALENCE BYPASS", fixed with a canonical
   reservation key: `A2_R_PASS_PENDING_OWNER_ACCEPTANCE`.
4. Closure round: `A2_R_BLOCKED_WITH_EVIDENCE`, which found AV-1 and AV-4.
5. Owner decisions (2026-10-10):
   - **D-AV1 = D1:** AV-1 accepted as a documented Strict Mode availability limitation.
   - **D-AV4 = AV4-B:** a conditional 5000 ms late-claim budget.
6. Final candidate: `A2_R_READY_FOR_OWNER_ACCEPTANCE_WITH_NOTES`.

Reports:
- `evidence/a2r-feasibility/A2R_FEASIBILITY_REPORT.md`
- `evidence/a2r-implementation/A2R_IMPLEMENTATION_REPORT.md`
- `evidence/a2r-closure/A2R_FINAL_CLOSURE_REPORT.md`
- `evidence/a2r-final/A2R_OWNER_FINAL_ACCEPTANCE_CANDIDATE.md`

**Owner verdict (2026-10-10): A2-R ACCEPTED WITH NOTES — DEVELOPMENT ONLY.** Official status:
`A2_R_ACCEPTED_WITH_NOTES`. This permits committing A2-R to `wp8/integration-poc`. It does **not** authorize a production
deployment or the closure of WP8, S7 or S8.

- **A2-R ACCEPTED WITH NOTES.** Security gates passed under the tested configuration (RouterOS CHR 7.24.5,
  `/radius timeout=3s`, FreeRADIUS 3.2.8 threaded, MariaDB 10.11 / MySQL 8.0):
  - A2 race gates: 48 trials (B0, Bscan, C, E1, E2, E3), 0 double PPPoE sessions;
  - S-1 gate passed;
  - cross-NAS (2026-10-09): 13/13 single session;
  - `ConcurrentFirstLoginGateTest` (suite `wp8-security-gates`), which failed by design under A2, now passes:
    8 tests, 194 assertions, exit 0.
- **AV-4: Closed within the validated 5000 ms budget.**
  - Late-claim budget raised from 2000 to 5000 ms. It covers the A1 verification path (stock `Simultaneous-Use` check
    plus the guard, up to its 3 s module timeout).
  - Measured on the real NAS: a claim delayed 3.5 / 4.5 s → Accept used by the NAS; 5.5 / 6.5 s → refused, claim
    released.
  - A slow guard (2.5 s) on a reconnect after a lost Stop is admitted; a guard past its timeout is refused.
- **AV-1: Accepted as a documented Strict Mode availability limitation for development only. Remains an OPEN
  production release risk.**
  - A whole-process FreeRADIUS stall longer than the NAS retry window (~9.3 s) leaves an orphan `PENDING` claim, and the
    subscriber is refused until the audited operator release on NAS-side proof.
  - No automatic expiry, no SNMP-based unlock, never a second session.
  - The 5000 ms budget does not cover this case: the time spent in the kernel queue is invisible to FreeRADIUS.
  - Drill 1&6 now verifies this contract. The earlier failures are kept in `evidence/a2r-implementation` and
    `evidence/a2r-closure`.
- **AV-2: Production capacity sizing remains open.**
  - The method is documented in `install/freeradius/README.md` "Capacity sizing".
  - Lab evidence only: `pool.max` 8 → exhaustion under 40 simultaneous requests; `pool.max` 32 with `max_servers` 32 →
    none observed.
- **AV-3: Operator identity verification remains open.**
  - `@nuxr_operator` is free text.
  - Proposal, not implemented: a per-operator DB account plus `CURRENT_USER()` in the audit row.
- **H-1: HIGH security blocker, verified in Hotspot and not yet remediated.**
  - "H-1 — HIGH — VERIFIED HOTSPOT USERNAME-EQUIVALENCE BYPASS": Port-Limit=1 → two live Hotspot sessions under two
    equivalent spellings.
  - Must be closed before production and before the final WP8 closure.
  - H1-A is a candidate only.
- **The FreeRADIUS / NAS timeout contract remains binding.**
  - RouterOS `/radius timeout=3s` (3 transmissions, abandoned after ~9.3 s).
  - A shorter NAS timeout is not covered by the 5000 ms budget.
- **WP8 PHPUnit (`wp8-integration`, 2026-10-10):** 69 tests, 1203 assertions, zero failures and zero errors, 13 skips
  (lab modes that exclude the production contract), three pre-existing warnings (`system/autoload/Message.php:13-15`
  under `failOnWarning="true"`), one pre-existing deprecation. **Actual exit code 1.**
  - The warnings and the deprecation are NOT resolved. They are the same as in the A1-round run before A2-R.
- **Harness** WP4 + WP5A + WP5B: 274 tests / 5791 assertions OK on MariaDB and MySQL, exit code 0.
- **Not changed:** `install/radius.sql` and `system/`. Hotspot is functionally unchanged. A1 historical evidence is not
  rewritten.
- **Invalid and corrected records** are documented in the reports and are not counted as passes:
  - the cross-NAS run at 19:46Z (MAC collision);
  - feasibility correction records;
  - `CORRECTION.AV-1.stall_timeline`.
- **Still open:** R-WP8-CARRY-01/02/03 and R-HF-CARRY-01. S7, S8 and WP8 are NOT complete. A3 is not started; H-1 is
  planning only.

## H-1 H1-A + AV-5 — Hotspot username identity and RADIUS age timing (2026-10-10) — additive record

**How it got here.** Each step was owner-approved:
1. H-1 plan, then the H1-B feasibility gate. The verdict was `H1_B_FEASIBILITY_FAILED`: RouterOS 7.24.5 ignores a
   canonical `User-Name` in the Hotspot Access-Accept.
2. H1-A implementation authorization (development and lab only). Verdict:
   `H1_A_SECURITY_PASS_WITH_OPEN_FINDINGS` (AV-5 open, MAC-H1A open).
3. H1-A + AV-5 final closure authorization: AV-5 corrected **without** the rejected "+1000 ms in the first 20 ms"
   proposal. Verdict: `H1_A_READY_FOR_OWNER_ACCEPTANCE_WITH_NOTES`.

Reports:
- `evidence/h1-feasibility/H1B_FEASIBILITY_REPORT.md`
- `evidence/h1a/H1A_IMPLEMENTATION_REPORT.md`
- `evidence/av5/H1A_AV5_FINAL_ACCEPTANCE_CANDIDATE.md` (the basis of this acceptance)

**Owner decision (2026-10-10): H1-A ACCEPTED WITH NOTES (development only); AV-5 FIX ACCEPTED (verified).
Status: `H1_A_AV5_ACCEPTED_WITH_NOTES`.** Commit and push to `wp8/integration-poc` only. No merge, no production
deployment.

- **H1-A closes the Hotspot spelling bypass (H-1) in the tested CHR cases.**
  - A Hotspot login (`Service-Type` Login-User + `NAS-Port-Type` Wireless-802.11) is admitted only when the raw
    `User-Name` bytes equal the one stored `radcheck.username`.
  - Equivalent spellings, an ambiguous stored identity, and any SQL error or unexpected result are refused exactly like
    a wrong password.
  - The request, the password check and the policy attributes are unchanged. H1-B is NOT used as policy.
  - Real NAS: no Hotspot session above `shared_users` in any H1-A run (limits 1 and 2, concurrent, lost accounting,
    PAP, cookie, vouchers).
- **AV-5 is closed.** Both A2-R age blocks compute `%{expr:(%{time_since:us} - %l * 1000000 - %M) / 1000}`, one
  `gettimeofday()` on each side (stock rlm_date; FreeRADIUS refuses to start without `mods-enabled/date`).
  - Second-boundary evidence:
    - 50,000-request micro-study: new formula 0 bad, old formula 28 negative ages;
    - lab boundary gate: fixed formula 240 evaluations, 0 errors; pre-fix formula 9 false refusals and 1 underestimate
      of 1000 ms;
    - 5000 ms edges and retransmissions;
    - real NAS: 3.5 / 4.5 s admitted, 5.5 / 6.5 s refused.
- **Unchanged:** the 5000 ms budget, Strict Mode, fail-closed, `install/radius.sql`, `system/`.
- **AV-1 remains an OPEN production availability risk.** This fix does not address a whole-process stall.
- **MAC-H1A remains an OPEN production note.**
  - `mac-as-username` with a MAC stored in lower case worked before H1-A and is refused with it.
  - RouterOS sends upper case with colons; with `mac-as-username-and-password` a lower-case MAC was already refused.
  - No MAC exception, no data change; documented in `install/freeradius/README.md`.
  - The read-only impact queries (`evidence/h1a/PRODUCTION_IMPACT_READONLY.sql`) are prepared and NOT run.
- **H-2 (cross-NAS Hotspot `shared_users`) and V-1 (VPN username equivalence) are NOT proven.** They must be assessed
  later; the plans are separate and nothing is started.
- **Test infrastructure defect (independent):** `tests/Support/AppServer.php` leaves `php -S` workers running after
  `stop()`. A test class that draws a reused random port talks to the orphan and gets HTTP 404 (proven by a probe).
  Not fixed.
- **Test results with actual exit codes (not rewritten):**
  - RealAaa ×3: OK (7 tests / 79 assertions each), exit 0.
  - `wp8-security-gates`: 10 tests / 216 assertions, exit 0 (48 race trials, 0 double sessions).
  - `wp8-integration`: 69 tests / 1203 assertions, 0 failures, 0 errors, 13 skipped, 3 warnings, 1 deprecation.
    **Exit 1**, caused by the three pre-existing `system/autoload/Message.php:13-15` warnings under `failOnWarning`.
    These warnings are **NOT resolved**.
  - **Full dual-engine harness: not clean, exit 1 in both runs.** No exit 0 is recorded for it.
    - Run 1 (980 tests / engine): MariaDB had 3 errors and 1 failure in `Http\ErrorHandlingTest` (AppServer defect),
      plus 5 warnings from `HotspotEquivalenceGateTest::tearDown` with the lab down (fixed in this commit). MySQL had
      0 errors / failures.
    - Run 2: MariaDB clean (67197 assertions, 0 issues except 79 lab-only incomplete). MySQL had 5 errors in
      `Wp6b\FinanceCorrectionTest` (AppServer defect).
    - The affected classes pass in isolation on both engines (exit 0).
  - A2-R lab cases: 18/19 pass in the main run. One `slow_guard_lost_stop` run FAILED: the A1 guard reached its 3 s
    timeout under the TCG lab before any age check. It passed on 4 re-runs. The failure is kept as evidence and not
    counted as a pass.
- **Still open:** AV-1, AV-2, AV-3, MAC-H1A, H-2, V-1, the AppServer defect, the WP8 `Message.php` warnings,
  R-WP8-CARRY-01/02/03, R-HF-CARRY-01. S7, S8 and WP8 are NOT complete. A3 is not started.

## AppServer test-infrastructure repair (2026-10-10 / 2026-10-11) — additive record

**Owner decision (2026-10-11): APPSERVER FIX ACCEPTED WITH NOTES — development only.** Basis:
`evidence/appserver/APPSERVER_REPAIR_REPORT.md`. Commit and push to `wp8/integration-poc` only. No merge, no
deployment.

- **Accepted:**
  - `php -S` keeps its 4 workers and runs in its own process group / session (`setsid`).
  - `stop()` signals only that group after a `/proc` ownership check (group AND session), then sweeps and reaps.
  - A busy port is never reused.
  - The server is ready only when 1 master + 4 workers are alive and it answers a per-start random sentinel from this
    copy.
  - `stop()` and `destroy()` are idempotent.
  - 6 lifecycle and concurrency tests (`tests/Http/AppServerLifecycleTest.php`).
- **Results (unchanged from the report):**
  - focused run 1 had **1 failure** (the first version did not wait for all 4 workers); runs 2–4 OK (6 / 64), exit 0;
  - `wp2-http` 110 / 6192 OK;
  - full harness run 1: MariaDB 986 / 67263 OK; **MySQL 1 failure + 2 warnings (TI-02), exit 1** — kept, not a pass;
  - full harness run 2: **986 / 67263 on both engines, 0 failures / errors / warnings, exit 0**;
  - no AppServer (HTTP 404) failure in either full run;
  - at most 5 live `php -S` processes and 1 docroot at any time.
- **TI-02 — StubNas logging race: OPEN (test infrastructure, not a production CoA defect).** The ACK arrives, but the
  stub's request record can be read or killed before it is complete. Reproduced 5 / 600 (and 2 / 300), 0 bind
  failures. Recorded in `evidence/appserver/TI-02_STUBNAS_LOGGING_RACE.md`. Fix: plan only, needs approval.
- **Kept cleanup notes (no scope extension):**
  - N-1: a SIGKILLed PHPUnit cannot stop its server group (bounded by the harness container removal);
  - N-2: a failed start in `Wp2HttpTestCase::setUpBeforeClass()` leaves the app copy and the test database.
- **Unchanged:** `install/`, `system/`, A2-R, H1-A, AV-5, production code.
- **Still open:** AV-1, AV-2, AV-3, MAC-H1A, H-2, V-1, TI-02, the WP8 `Message.php` warnings, R-WP8-CARRY-01/02/03,
  R-HF-CARRY-01. H-2, V-1 and A3 are not started.

## TI-02 — StubNas logging race repair (2026-10-11) — additive record

**Owner decision (2026-10-11): TI-02 StubNas ACCEPTED WITH NOTES — development only. TI-02 is CLOSED with status
`TI02_ACCEPTED_WITH_NOTES`.** Basis: `evidence/ti02/TI02_STUBNAS_REPAIR_REPORT.md`. The race finding
`evidence/appserver/TI-02_STUBNAS_LOGGING_RACE.md` is kept unchanged. Commit and push to `wp8/integration-poc` only;
no merge, no deployment.

- **Accepted:**
  - `stub_nas.php` publishes its log atomically (temporary file + `rename()`, same directory). The answer-then-record
    order is unchanged.
  - Out-of-range port → `bind`; post-bind `/proc/net/udp(6)` count ≠ 1 → `port_in_use` / `port_not_bound`.
  - `StubNas` retries a bounded set of ports (5 random, or the explicit list), each with a bounded 10 s ready wait,
    after cleaning the failed attempt, and throws when all fail.
  - `received()` waits (bounded) for the stub to exit on its own, validates the complete JSON list, throws on timeout,
    error or malformed log, and is idempotent.
  - Only the instance's own child is stopped, through its `proc_open` handle. The temporary, ready and log files are
    removed.
- **Results (unchanged from the report):**
  - race probe: before 6 / 7,000 lost after ACK, after 0 / 7,000 (0 exceptions);
  - 20 focused repetitions OK (29 tests / 305 assertions per engine, exit 0);
  - full harness ×2: 994 tests / 67305 assertions per engine, 0 failures / errors / warnings, 79 incomplete (CHR lab
    absent), **exit 0 and 0**;
  - two development focused runs failed (an undetected UDP collision, then an out-of-range port). Both are kept as
    evidence.
- **Unchanged:** `CoAClientTest`, `ActivationFailureTest` and every protocol assertion (ACK, NAK 502 / 503, silence
  with 3 identical packets, forged reply); production `CoAClient`; `install/`, `system/`; FreeRADIUS policies.
- **Remaining note (port check):** the `/proc/net/udp(6)` check is post-bind detection, **not an atomic exclusivity
  guarantee**.
  - It excludes collisions between stubs (each binds before counting).
  - It does not detect a non-stub process that binds the same port with `SO_REUSEADDR` after the check.
  - It requires `/proc/net/udp`; without it the stub refuses to start.
  - Clarified in the report addendum; §10.2 "never a shared port" is too strong.
- **Still open:** N-1 / N-2 cleanup notes, AV-1, AV-2, AV-3, MAC-H1A, H-2, V-1, the WP8 `Message.php` warnings,
  R-WP8-CARRY-01/02/03, R-HF-CARRY-01. H-2, V-1 and A3 are not started.

## A3 — R-WP8-CARRY-02 old-identity session and RADIUS rename integrity (2026-10-12 / 2026-10-13) — additive record

**Owner decision (2026-10-13): `A3_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY`.** GitHub finalization is authorized on
`wp8/integration-poc` only, from baseline `32a162d6e91012ca2638b13332b129ece29331c7`. No merge to `feat` or `main`,
no force push, no deployment. Basis: `WP8_A3_FINAL_REMEDIATION_REPORT.md`, after:
- `WP8_A3_IMPLEMENTATION_ACCEPTANCE_CANDIDATE.md`;
- `A3_FINAL_PRE_ACCEPTANCE_VERIFICATION_REPORT.md` (`BLOCKED_WITH_EVIDENCE`, leading to the owner decision
  `A3_REMEDIATION_APPROVED — FINAL_VERIFICATION_REQUIRED`: D-1 approved, D-2 approved with documented residual, D-3
  mandatory security fix).

Plan: `WP8_A3_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md` rev. 3 + OD-A3R-1 … 14; handoff `WP8_A3_HANDOFF.md`. Both have
dated 2026-10-13 corrections: D-2 wording, and the event name `rename.old_session`.

- **Accepted:**
  - FIX-1 … FIX-7.
  - D-3 within the limits of its proof (§4 of the remediation report): PPPoE-username collisions and foreign / orphan
    RADIUS policies under the new name are refused before any write in R1 and R2, and the write transaction re-checks
    them (`replaceTarget`, `DELETE`-count guard).
  - The final MariaDB, MySQL and CHR tests.
  - The second independent review and its fixes R-1 … R-8.
  - Lock safety, failure audit and drift, within the scope of the tests.
  - **Case C (a rename repeated across several plans) is closed.**
- **Results:**
  - focused suites: 14 per engine, 28 runs, exit 0;
  - frozen full harness: 1167 collected / Tests 1082 / Assertions 68517 / Incomplete 85 / Skipped 0 per engine, exit 0,
    tree unchanged;
  - CHR `RenameOldSessionLabTest` + `RealLifecycleTest`: 9 tests / 269 assertions, exit 0, on the same tree.
  - Incomplete and skipped tests are not counted as passes.
- **R-WP8-CARRY-02:** closed for development by A3, with the notes below. It is not a production release.
- **Open notes (not closed by this acceptance):**
  - **OBS-1 — production blocker, independent of the development acceptance of A3.** Customer creation
    (`customers.php` add-post) does not fully check PPPoE-username / username collisions between customers. Not fixed
    in this round; no schema change.
  - ADMIN_SYNC (and any rename) with two Radius plans on the **same** RADIUS key ends `REPROVISION_UNCONFIRMED`.
    Pre-existing, unchanged by A3, it needs separate treatment before production. `REPROVISION_UNCONFIRMED` is never a
    success.
  - RadiusRest / L-4 (open before production); L-1 … L-5.
  - R2 (unassigned legacy path) has no `old_session` audit and no warning UI; non-success is logged only.
  - Not every real interleaving inside the write transaction under READ COMMITTED is proven (covered by the
    `DELETE`-count guard; the deployment default is REPEATABLE READ).
  - The pre-existing PHP warnings (`system/autoload/Message.php:13-15`, `$root_path`) are **not closed**.
    `failOnWarning` is unchanged.
  - Still open from earlier records: R-WP8-CARRY-01 / 03, R-HF-CARRY-01, H-2 follow-ups, V-1. A4, A5 and V-1 are not
    started.
