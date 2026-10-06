# WP8 S1 — STOP: hardened Message-Authenticator policy is not effective for SQL-loaded NAS clients (2026-10-06)

Status: **WP8 STOPPED IN S1 — OWNER DECISION REQUIRED (OD-W8-13)**. Raised under the owner's OD-W8-10 instruction:
"If production-style SQL clients cannot preserve the hardened policy without an architectural/configuration change:
STOP and report. Do NOT weaken the policy." Nothing was weakened. No S1 tooling, test, runtime, schema, harness or
`install/freeradius` file was changed or committed; this commit adds documentation and sanitized evidence only.

## Finding

With the production-style client source required by OD-W8-7 (`read_clients = yes`, `client_table = nas`), FreeRADIUS
**3.2.8** (`freeradius/freeradius-server:3.2.8`, the pinned project image) does **not** apply
`require_message_authenticator = yes` to clients loaded from the `nas` table:

| Lab mode (fresh server each time) | Global `security.require_message_authenticator` | Access-Request **without** Message-Authenticator from SQL client `nuxr_wp8_maprobe` | with Message-Authenticator |
|---|---|---|---|
| A — stock image config | `auto` | **Access-Accept** (BlastRADIUS warning only) | Access-Accept |
| B — global hardening (lab only) | `yes` (debug confirms `require_message_authenticator = "yes"` in `security {}`) | **Access-Accept** — debug: `YOU MUST SET "require_message_authenticator = true" … for client nuxr_wp8_maprobe` | Access-Accept |
| C — virtual-server policy (lab evaluation of an option) | `auto` | **dropped (no reply)** — `if (!&Message-Authenticator) { do_not_respond }` | Access-Accept |

Why: the SQL client query (`mods-config/sql/main/mysql/queries.conf`) returns only `id, nasname, shortname, type,
secret, server`; there is no per-client policy column, and — empirically (mode B) — SQL-loaded clients do not inherit
the global `security` value either. The static harness client in `dev/test/freeradius/clients.conf` *does* enforce
`yes` (proven in the feasibility gate: RouterOS 7.12.1 requests were dropped), which is why the accepted WP4 harness
never exposed this. The `nas` table therefore silently downgrades the hardened policy to "accept without
Message-Authenticator" for every NAS managed through RADIUS > NAS.

Evidence: `evidence/s1-ma-policy/` — FreeRADIUS `-X` excerpts for modes A/B/C (startup configuration with the
effective `security` block, `rlm_sql … Client "nuxr_wp8_…" (sql) added`, BlastRADIUS warnings, request handling),
`probe-results.txt` (crafted PAP requests with/without Message-Authenticator plus a per-packet capture summary), the
lab-only mode-C policy text.

## RouterOS 7.24.5 (amendment W8-A2) — compatibility smoke PASSED (no regression vs 7.20.3)

Image `https://download.mikrotik.com/routeros/7.24.5/chr-7.24.5.img.zip`, zip SHA-256
`16f07222a3213352c9c4fbea4f4dec6a8ffb78fdc0fa5e398c23103ed357c6eb` (45 293 979 B), raw image SHA-256
`e4caf173aceb497433c5fc285bffee09c1e6761dfe0e658a2b178970249fc2d2` (134 217 728 B, MBR raw disk), package
`routeros 7.24.5` built 2026-09-29 14:21:27, license level `free`; image untracked under `/srv/phpnuxbill/wp8lab/img`.

| Capability | 7.24.5 result |
|---|---|
| boot (QEMU 8.2.2 TCG, 1 vCPU, 1 GiB) → API login | 67.3 s |
| `/radius` (`require-message-auth = yes-for-request-resp`), `/radius incoming accept=yes port=3799` | OK |
| PPPoE (namespace client): discovery, CHAP via RADIUS, `radius: true`, 10.88.10.199 from `Framed`-less pool profile, dynamic queue `512000/1000000` from `Mikrotik-Rate-Limit 512k/1M` | OK (4.1 s) |
| Hotspot (namespace client): DHCP, 302 interception, `http-pap` via RADIUS "logged in", `radius: true` | OK |
| Accounting Start + Interim (1 min) for both; Stop `NAS-Request` after disconnect | OK |
| application `CoAClient::disconnect()` (source 10.88.0.10 = configured `/radius` server) | `SUCCESS COA_ACK`, `nas_id = 1` (`radacct.nasipaddress == nas.nasname`), session gone |
| Message-Authenticator in RouterOS Access-Request | **present in 2/2**; Access-Accept carries it too |

So the NAS side is ready; the blocker is purely the FreeRADIUS SQL-client policy.

## OD-W8-13 — how to keep the hardened policy with production-style SQL NAS clients (BLOCKS S1)

| Option | What changes | Effect | Blast radius |
|---|---|---|---|
| **A (recommended)** — enforce in the project virtual server | `install/freeradius/sites-available/phpnuxbill` `authorize { if (!&Message-Authenticator) { do_not_respond } … }` (mode C, proven in the lab); WP4 `FreeRadiusContractTest` + README updated; harness static client keeps `yes` | every client — static or SQL — must send Message-Authenticator on Access-Request; NAS firmware floor ≥ 7.15 (RouterOS 7.24.5 complies) | project FreeRADIUS deployment config (not PHP runtime, no schema); changes the accepted WP4 configuration artefact → owner approval needed |
| B — static `clients.conf` per NAS instead of `read_clients = yes` | revoke OD-W8-7; NAS list maintained in two places (app `nas` table for CoA + FreeRADIUS file for auth) | per-client `yes` works (proven in the gate) | operational split-brain between RADIUS > NAS UI and FreeRADIUS; not recommended |
| C — accept `auto`/unenforced for SQL clients | none | weakens the hardened policy | **rejected** by OD-W8-10 |
| D — newer FreeRADIUS with per-client SQL policy | image upgrade / custom build | unverified; would change the pinned WP4 image | larger; not recommended for WP8 |

Global `security.require_message_authenticator = yes` alone is **not** an option: mode B proves it has no effect on
SQL-loaded clients in 3.2.8.

## Other S1-window facts

- Lab topology, address-space refusal check (10.88.0.0/16 free), cleanup (0 links, 0 namespaces, 0 QEMU, 0 nuxbill
  containers/networks/volumes) and host network (eth0, default route, 2 ufw rules, iptables INPUT unchanged): OK.
- AVAIA guard: valid S0 snapshot 11:06Z (24 protected containers) → check after the slice: **all 24 unchanged**.
  Concurrent/unattributed shared-host activity, recorded without attribution: 27 protected containers at the end of
  the feasibility gate vs 24 at S0 (`avaia-wp2-pg-base`, `-dev`, `-rehearsal` disappeared while no WP8 activity was
  running); `avaia-wp2-pg-dev` was created and started ~11:14:45Z during this window (not part of the S0 protected set).
  No WP8 command addressed any AVAIA object.
- Separate finding for the record: with `read_clients = yes`, `CoAClient` and FreeRADIUS both read `nas`; the
  `nas.secret` therefore keeps serving both auth (FreeRADIUS) and CoA (application) — consistent with WP4.

## What happens after the decision

On OD-W8-13 A: implement S1 with the virtual-server enforcement in the committed lab and project config (focused WP4
contract test + the lab proof re-run on 7.24.5), then continue S2–S8 as authorised. On B: implement S1 with generated
static clients. WP8 does not resume until the owner answers.
