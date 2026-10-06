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
