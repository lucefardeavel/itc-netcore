# WP8 — Real FreeRADIUS + MikroTik Integration POC — PLAN (planning + reconnaissance, 2026-10-06)

Status: **PLANNING ONLY**. Readiness verdict: **NOT_READY_REAL_NAS_REQUIRED** (§33). No implementation, staging configuration or router change was made.

## 1. Baseline verification (read-only, 2026-10-06)

| Ref | Expected | Observed on origin | Result |
|---|---|---|---|
| `feat/reseller-system-v1` | `5bdc57459a64dd53e32e5abb2ef6efa3815e387a` | same | OK |
| `wp7/staff-pwa` | `8e7f0e1c024e6c319b5088896e001555d6c4f079` | same | OK |
| `wp6c/mobile-adaptive-ux` | `757d8de969e98787a270facb956a4c543fec7eaa` | same | OK |
| `hotfix/pace-xhr-csrf` | `6d4f3f962482b6e125429da7ae459ae8dfd4214c` | same | OK |
| `main` | `500d3a2d15003efeed6e0aad639c13d08101c3b1` | same | OK |
| `wp8/integration-poc` | must not exist yet | ABSENT | OK |

Local checkout was `hotfix/pace-xhr-csrf`, clean. `owner-review/WP8/`, `docs/WP8*` and any `wp8` branch: ABSENT. The only prior WP8 material is the pack prompt `docs/claude-development-pack/prompts/WP8_INTEGRATION_POC.md`. The acceptance ledger lists WP0–WP7 accepted and the Pace/XHR CSRF hotfix accepted; WP8/WP9/WP10 PENDING; `R-HF-CARRY-01` OPEN (untouched by WP8).

## 2. Environment readiness

Development host: a KVM guest (Linux 6.8, 12 vCPU, 47 GiB RAM, 93 GiB free disk), one public IPv4 (`eth0`), `ufw` active with only `22/tcp` allowed inbound, default INPUT DROP. Shared with AVAIA production (never touched; `avaia-guard.sh` snapshot/check applies to every WP8 run).

| Component | State | Readiness |
|---|---|---|
| FreeRADIUS (disposable) | `freeradius/freeradius-server:3.2.8` (digest-pinned) is already defined in `dev/test/compose.yml` (`freeradius-mariadb`, `freeradius-mysql`) running the project configuration `install/freeradius/*` via `dev/test/freeradius/start.sh`; no published ports; reachable only inside the `nuxbill-reseller-test` network | **READY as a container**; **NOT reachable by any external NAS** (no published UDP 1812/1813, firewall closed) |
| MikroTik test NAS | none reachable: no private route, no WireGuard tunnel (`wg` tools absent, no `/etc/wireguard`, kernel module `wireguard.ko` present but unloaded), no SSH alias, no recorded staging router in any project file, no RouterOS CHR image; host has no `/dev/kvm` and no `vmx/svm` CPU flags (nested virtualization unavailable), so a local CHR VM is impossible | **NOT READY** |
| Management path | none exists | NOT READY |
| Diagnostic tools on host | `tcpdump`, `nc`, `ssh`, `python3` present; `radclient`/`radsniff`/`radtest`/`nmap`/`wg` absent | partial |
| PHP test runner | `nuxbill-reseller-test-php82` image; dual-engine harness `dev/test/run-tests.sh` | READY |
| PWA lab | `dev/pwa-lab/lab.sh` (starts `mariadb` + `freeradius-mariadb`, publishes 127.0.0.1:18099/18098 only) | READY (not needed unless WP8 touches UI) |

Containers of the compose project are currently down; nothing was started.

## 3. Real NAS inventory

**No real NAS is available.** Every item below is therefore `UNKNOWN — NO NAS`; the table is the exact record to be filled when the owner provides one. Secret values will never be recorded (only "set"/"not set").

| Item | Value |
|---|---|
| RouterOS version | UNKNOWN — NO NAS |
| Architecture / model | UNKNOWN — NO NAS |
| Management address / path | none |
| Connection type (direct / private / WireGuard) | none |
| API 8728 / API-SSL 8729 availability | UNKNOWN |
| RADIUS path (NAS → FreeRADIUS) | none |
| Authentication port / accounting port | n/a (RouterOS default 1812/1813) |
| `/radius incoming` accept / port / vrf | UNKNOWN (RouterOS default `accept=no`, `port=1700`, `vrf=main`) |
| PPPoE test interface/server | none |
| Hotspot test interface/server | none |
| Test IP pools | none |
| Disposable / staging confirmation | **not confirmed** |

## 4. Topology (target design, ASCII)

```
            dev host 167.86.x.x (KVM guest, ufw: 22/tcp only)          owner-provided staging MikroTik
 +------------------------------------------------------------+        +---------------------------------+
 | compose project nuxbill-reseller-test (bridge network)      |        | RouterOS (version TBD)          |
 |  php82 runner / app (ActivationService, CoAClient, API)    |  WG    |  /radius  service=ppp,hotspot    |
 |  freeradius-wp8 (3.2.8, install/freeradius, read_clients)  |<=====>|   address=<wg dev ip>           |
 |  mariadb 10.11 (+ mysql 8.0 only if DB behaviour touched)  |  tun   |   src-address=<wg nas ip>        |
 |                                                            |        |  /radius incoming accept=yes      |
 |  host: wg0 <wg dev ip>/30   (OD-W8-1)                       |        |   port=3799 (or Ports=1700)       |
 +------------------------------------------------------------+        |  /interface pppoe-server (test)   |
      UDP 1812/1813 NAS->FreeRADIUS (over tunnel only)                 |  /ip hotspot (test profile)       |
      UDP CoA  app->NAS <wg nas ip>:<nas.ports|3799>                   |  /ip pool nuxr_wp8_*              |
      TCP 8728 app->NAS API (over tunnel only, OD-W8-5)                |  test PPPoE/Hotspot clients       |
                                                                        +---------------------------------+
```

Alternative without a tunnel (public-IP RADIUS with `ufw` source restriction) is **not recommended**: NAT changes NAS-IP-Address (§11) and the RouterOS API must never be public (§12).

## 5. Network path

- NAS → FreeRADIUS: UDP 1812 (auth), UDP 1813 (acct), from the NAS `src-address` to the FreeRADIUS address. Today FreeRADIUS has no published port; WP8 needs a dedicated compose service (`freeradius-wp8`) that publishes 1812/1813 UDP **bound to the tunnel address only** (never `0.0.0.0`).
- App → NAS CoA/Disconnect: UDP to `nas.nasname:<nas.ports|3799>` from the php container (source = host tunnel address via Docker NAT masquerade; the NAS must accept CoA from that source).
- App → NAS API: TCP 8728 (plain, see §12) over the tunnel only.
- Everything crosses `ufw`: allow rules limited to the WireGuard UDP listener (source = owner NAS public IP) and to traffic on `wg0`.

## 6. Security boundaries

- AVAIA containers, networks, volumes, the Docker daemon: untouched. `scratchpad/avaia-guard.sh snapshot` before and `check` after every WP8 session.
- Only the `nuxbill-reseller-test` compose project is used; the WP8 services join it (new profile `wp8`).
- No RouterOS API or RADIUS port is exposed on the public interface. Ports bind to the tunnel address only.
- Router credentials, RADIUS secret, WireGuard keys live only in `scratchpad/.devpw`-style untracked files / environment; never in the repository, logs, evidence or reports.
- Staging NAS must be confirmed disposable by the owner (§3). If it carries any customer traffic, STOP (stop condition 2/13).
- Test identities are prefixed `nuxr_wp8_` (§10) and never collide with real subscribers.

## 7. RouterOS version and capabilities (to verify on the real NAS)

Documentation facts (help.mikrotik.com, RADIUS page, fetched 2026-10-06), each to be **verified on the real router** and recorded in REPORT.md as `CONFIRMED` / `CONTRADICTED`:

| # | Documented | Verification command (read-only) |
|---|---|---|
| D1 | `/radius incoming`: `accept` default **no**; `port` default **1700**; `vrf` default main | `/radius incoming print` |
| D2 | Disconnect and CoA (RFC 3576) supported; POD not supported | real Disconnect-Request from `CoAClient` (§18) |
| D3 | CoA may change Mikrotik-Rate-Limit, Mikrotik-Group, Recv/Xmit-Limit, Ascend rates (if no Rate-Limit), Mark-Id, Filter-Id, Advertise-*, Session-Timeout, Idle-Timeout, Port-Limit; **IP address, pool, routes cannot be changed — user must be disconnected first** | CoA rate-limit test (§18) + attempted Framed-Pool CoA expected to be NAK/ignored |
| D4 | `Mikrotik-Rate-Limit` = `rx-rate[/tx-rate] …`, direction **from the router's point of view: rx = client upload, tx = client download** | `/ppp active print detail`, `/queue simple print` for the dynamic queue, plus a client speed observation |
| D5 | NAS-IP-Address = "IP address of the router itself"; `src-address` default 0.0.0.0 | compare `radacct.nasipaddress` with `/radius print detail` src-address |
| D6 | RADIUS consulted **only if no matching local user record exists** → local users/secrets can mask RADIUS | `/ppp secret print`, `/ip hotspot user print` must contain no `nuxr_wp8_*` identity |
| D7 | `service=ppp|login|hotspot|wireless|dhcp|ipsec|dot1x`; auth 1812, acct 1813, timeout 1100 ms, `require-message-auth=yes-for-request-resp` default | `/radius print detail` |
| D8 | PPP: `/ppp aaa use-radius=yes accounting=yes interim-update=…`; Hotspot profile `use-radius=yes`, `radius-accounting=yes`, `radius-interim-update=…` | `/ppp aaa print`, `/ip hotspot profile print detail` |

PEAR2 client compatibility: vendored `PEAR2\Net\RouterOS` 1.0.0b6 (`system/autoload/PEAR2/Net/RouterOS/Client.php`) implements both the ≥6.43 and <6.43 login — compatible with RouterOS 6.43+ and 7.x in principle; **to confirm on the real version** with a read-only `/system/resource/print`.

## 8. RADIUS configuration (staging)

FreeRADIUS side (`freeradius-wp8` service, project config from `install/freeradius/`):
- virtual server `phpnuxbill` (PAP/CHAP, sql, expiration, `nuxr_timecounter`/`nuxr_datacounter`, accounting → sql); `dictionary.phpnuxbill` (`Nuxr-Period-Start`).
- **`read_clients = yes`, `client_table = nas`** (production path; the harness uses `read_clients = no` + `clients.conf 0.0.0.0/0`). Consequence: the `nas` row must exist before FreeRADIUS starts (reload on change); `nas.secret` plaintext as accepted WP4.
- `TZ` of the FreeRADIUS container = application time zone (WP4 residual 4; `Expiration` is parsed in server local time).
- accounting: `acct_unique`, `delete_stale_sessions = yes` (unchanged), `radpostauth` disabled (unchanged).

MikroTik RADIUS client (read-only inspection first, then the minimal staging configuration in the implementation plan):
`/radius add service=ppp,hotspot address=<wg dev ip> secret=<set> src-address=<wg nas ip> authentication-port=1812 accounting-port=1813 timeout=1100ms` (possibly `timeout` raised to 3000ms for the tunnel), `/radius monitor` and `print stats` captured sanitized (requests, accepts, rejects, resends, timeouts, bad-replies).

PPP AAA: `use-radius=yes accounting=yes interim-update=1m` (test value) — PPPoE server on the test interface with a profile that has **no local address/pool** forcing (so Framed-Pool/Framed-IP-Address from RADIUS are authoritative; `remote-address` on the profile would mask RADIUS — recorded as D9 to verify).
Hotspot: test server profile `use-radius=yes radius-accounting=yes radius-interim-update=1m`, login method the staging profile actually uses (expected `http-chap`/`http-pap`; MAC/cookie login disabled for the test to avoid cached logins), recorded as Q13.

## 9. `/radius incoming` configuration

Required: `accept=yes`, `port=` **consistent with the application's destination** (§10), `vrf=main`; firewall input rule on the NAS allowing UDP to that port **only from the tunnel source** of the dev host. Current state: UNKNOWN — NO NAS.

## 10. CoA port investigation (WP4 carry-forward, first-class)

Facts established read-only:
1. Accepted code `system/autoload/Reseller/Radius/CoAClient.php:87-92`: `$port = (int)($nas['ports'] ?? 0); $port = $port >= 1 && $port <= 65535 ? $port : 3799;` destination host = `nas.nasname` (IP literal required).
2. Schema `install/radius.sql:8`: `` `ports` int(5) DEFAULT NULL `` — the stock FreeRADIUS `nas` table column, whose historic meaning is "number of ports on the NAS" (informational, never read by the server for CoA).
3. Upstream PHPNuxBill repurposed it: legacy `Radius::disconnectCustomer` ran `radclient -x <nasname>:<ports> disconnect` (`docs/GAP_ANALYSIS.md:229`), i.e. **upstream convention = CoA/Disconnect port**. WP4 kept that convention and added the 3799 fallback.
4. UI (`ui/ui/admin/radius/nas-add.tpl:34-36`, `nas-edit.tpl:35-38`): label literally **"Ports"**, placeholder `null`, not translated; list column "Port". Nothing says CoA. Controller `system/controllers/radius.php:157-168` docblock says "address, CoA port and secret"; validation 1–65535, empty → NULL.
5. Values in accepted tests: `'3799'` (`RadiusAdminFlowTest`), random stub port (`CoAClientTest`, `ActivationFailureTest`). Project operator docs `install/freeradius/README.md:44-46`: "port is `nas.ports` when set, otherwise 3799; MikroTik: `/radius incoming set accept=yes port=3799`".
6. RouterOS default incoming port is **1700** (D1). So an operator who leaves "Ports" empty **and** leaves RouterOS at default gets CoA to 3799 while the NAS listens on 1700 → `UNKNOWN COA_NO_RESPONSE` on every disconnect. The accepted README rule (set the router to 3799) closes the gap by configuration; the UI label does not communicate it.

Assessment: the field is **suitable** as the CoA destination port (upstream convention, validated 1–65535, read by exactly one consumer), the 3799 fallback is the RFC 5176 registered port and correct for a MikroTik configured per the README, and **no schema change is required**. The deficiencies are documentation/UI clarity (label "Ports", placeholder `null`) and the absence of a real-NAS proof. → **OD-W8-3** (non-blocking for implementation; blocks the router configuration choice in the POC).

## 11. NAS identity mapping

Accepted code: `CoAClient.php:63-66` → `RadiusRepository::nasByAddress()` (`RadiusRepository.php:237-239`): `SELECT * FROM nas WHERE nasname = ? ORDER BY id`, with `?` = `radacct.nasipaddress` (exact string match; CIDR/hostname `nasname` → `NAS_UNKNOWN`, hostname found → `NAS_ADDRESS_INVALID`). `radacct.nasipaddress` is written by FreeRADIUS from the **NAS-IP-Address attribute**, not from the packet source. RouterOS sends NAS-IP-Address = "router's own IP" (D5; which interface address is chosen when `src-address=0.0.0.0` must be observed).

Plan: configure `/radius src-address=<wg nas ip>`, `nas.nasname=<wg nas ip>`; verify (a) `radacct.nasipaddress`, (b) packet source seen by FreeRADIUS (`tcpdump -i wg0 udp port 1813` on the host), (c) `nas.nasname`, (d) the FreeRADIUS `read_clients` match (client lookup is by **source IP**, so `nasname` must also equal the source). If (a) ≠ (c) in the real topology → STOP (stop condition 9), present options (e.g. documenting the `src-address` rule vs. a mapping change — the latter would be an authority change requiring an owner decision). No NAT path is planned precisely because NAT breaks (a)=(c).

## 12. API / API-SSL / WireGuard assessment

- Vendored client: `PEAR2\Net\RouterOS\Client::__construct($host, $user, $pass, $port = 8728, $persist, $timeout, $crypto = CRYPTO_OFF, …)`. All four app call sites (`system/autoload/Mikrotik.php:24`, `MikrotikPppoe.php:352`, `MikrotikHotspot.php:270`, `MikrotikVpn.php:316`) pass only host/user/pass/port → **API-SSL is not reachable without a runtime change**. → OD-W8-5: plain API 8728 **over the WireGuard tunnel only** (private) is the recommended POC transport; API-SSL would be a runtime architectural change deferred (WP9 hardening), not silently implemented.
- WireGuard: kernel module available (`wireguard.ko`, v1.0.0, not loaded); `wireguard-tools` not installed; the host is shared with AVAIA → installing `wireguard-tools`, adding `wg0` and two `ufw` rules is a host network change → **OD-W8-1** (blocking).
- `/ip service api address=` must be restricted to the tunnel subnet; `/ip firewall filter` on the NAS must accept 8728 and the CoA port only from the dev tunnel address; `/ip service` www/winbox untouched (owner's router).

## 13. PPPoE topology

Test PPPoE server on an isolated router interface (bridge/VLAN with no customers) with a dedicated profile `nuxr_wp8_pppoe` (no `remote-address`/`local-address` pool override that would mask RADIUS, `use-radius` via `/ppp aaa`). PPPoE test clients: either a second RouterOS/Linux `pppoe-client` on that segment or a RouterOS `/interface pppoe-client` on a VLAN loop of the same router (loopback PPPoE is a valid NAS-side session for RADIUS evidence; the owner should state what client is available). Identities: `nuxr_wp8_pppoe_dyn`, `nuxr_wp8_pppoe_static`, `nuxr_wp8_pppoe_simu`.

## 14. Hotspot topology

Test Hotspot server `nuxr_wp8_hs` on an isolated interface/VLAN with pool `nuxr_wp8_hs_pool`, server profile with `use-radius=yes`, accounting on, login by `http-chap` (or the method the staging profile uses — recorded), `mac-cookie`/`trial` off. Client: a device or a RouterOS/Linux client on the segment performing the login (owner to confirm). Identities: `nuxr_wp8_hs_1`, `nuxr_wp8_hs_2`.

## 15. IP pools

- `nuxr_wp8_pool_dyn` (PPPoE dynamic; RADIUS `Framed-Pool` names it; must exist on the router).
- `nuxr_wp8_pool_hs` (Hotspot).
- Static: `nuxr_wp8_pppoe_static` gets `Framed-IP-Address` = an address outside both pools.
- Negative: a plan whose pool does **not** exist on the router → expected PPP failure (recorded behaviour).

## 16. RADIUS attribute expectations (accepted WP4 model)

| Attribute | Written by | Expectation on the real NAS |
|---|---|---|
| `Cleartext-Password` (radcheck) | `RadiusPolicyBuilder` | PAP/CHAP Access-Accept for the right password; Reject otherwise |
| `Simultaneous-Use` `:=` 1 (PPPoE) / max(1, shared_users) (Hotspot) | radcheck | second PPPoE session → Access-Reject (`session` sql check against open radacct); Hotspot allows up to shared_users |
| `Expiration` `:=` `d M Y H:i:s` (app TZ) | radcheck | Access-Reject after expiry; `Session-Timeout` capped before it |
| `Mikrotik-Rate-Limit` `:=` `up/down[ burst]` (radreply; group `plan_<id>` also carries Ascend-Data-Rate/Xmit-Rate) | radreply/radgroupreply | router dynamic queue shows rx(upload)=up, tx(download)=down — **verify the direction** (D4, Q9) |
| `Framed-IP-Address` (static PPPoE) / `Framed-Pool` (dynamic) — never both | radreply | §15 |
| `WISPr-Session-Terminate-Time` (Hotspot) | radreply | session ends at expiry |
| `Max-All-Session` / `Max-Data` + `Nuxr-Period-Start` | radcheck / counters | quota enforcement per billing period (observed via Session-Timeout / reject) |

## 17. Accounting expectations

Start (nasipaddress, acctsessionid, framedipaddress, username, calledstationid), Interim (acctupdatetime, octets growing), Stop (acctstoptime, acctterminatecause, final octets). Historical rows **preserved** across activation, renewal, change-plan, re-provision, expiry (WP4 rule "FreeRADIUS writes radacct; PHPNuxBill only reads it") — proven by `COUNT(*)`/`MAX(radacctid)` monotonic before/after each lifecycle action plus the existing source guard that no runtime deletes radacct.

## 18. Real Disconnect / CoA matrix (application `CoAClient` is the primary instrument)

| Case | Steps | Evidence |
|---|---|---|
| DC-1 PPPoE disconnect | open session → `CoAClient::disconnect` via `ActivationService` CHANGE_PLAN path or a dev harness calling the same class | Disconnect-ACK, `/ppp active` empty for the user, radacct Stop with terminate cause, exactly 1 request sent (no blind retry as new request — identifier constant across ≤3 retransmissions) |
| DC-2 Hotspot disconnect | same for a Hotspot session | `/ip hotspot active` empty, Stop row |
| DC-3 NAK | wrong-port/unknown-session → Disconnect-NAK 503 → `SUCCESS SESSION_NOT_FOUND` per accepted mapping | result metadata |
| COA-1 rate-limit | active session; `changeAuthorization` with new `Mikrotik-Rate-Limit` | CoA-ACK; `/queue simple print` dynamic queue reflects new rate; throughput observation |
| COA-2 IP/pool change | **not expected via CoA** (D3): update policy → disconnect → re-auth → new IP/pool | radacct new session with new framedipaddress; documented as binding operational rule if confirmed |
| COA-3 silence | CoA port blocked on the NAS firewall → `UNKNOWN COA_NO_RESPONSE` after 1+2 identical sends, no provisioning rollback | tcpdump count = 3 identical packets |

Cross-checks only: `radclient` (inside the FreeRADIUS container if present in the image; else `freeradius-utils` installed in a throwaway container, never on the host), `tcpdump` on `wg0`, RouterOS `/log print where message~"nuxr_wp8"`.

## 19. Reseller A → B → C finance scenario (real network)

Seed through accepted services only (no SQL writes): Admin funds Agent A (`WalletService` funding); A → B → C transfers; base cost + wholesale chain + retail (`PricingService`); Sales user under C; customer `nuxr_wp8_cust_1` assigned to Sales; `ActivationService::activate` NEW for a PPPoE plan → real Access-Accept and session → settlement commit (debit L, commissions, provider settlement P). Conservation proof from ledger rows: `L = Σ commissions + P`, wallet deltas = ledger legs, no extra rows. Then RENEW, CHANGE_PLAN (new rate via CoA or disconnect/re-auth), expiry. Evidence: ledger/wallet/reservation/activation/event rows + radacct + router session data.

## 20. Sales scenario

Sales (under C) performs the allowed customer operation (activation of its assigned customer via the accepted route) → network service provisioned; Sales attempts out-of-scope customer → 403/NOT_FOUND; Sales pages/API expose no wholesale/exact wallet (reuse WP6B redaction assertions); C's wallet is the one debited. No new capability.

## 21. Lifecycle tests

NEW (real success) → RENEW (expiry/policy change; existing session continues or is cut per accepted model; radacct retained) → CHANGE_PLAN (rate/pool; refresh mechanism = CoA for rate, disconnect/re-auth for IP/pool) → EXPIRY (short window on a staging identity; Access-Reject after expiry; existing session ended by Session-Timeout/WISPr) → REACTIVATE → username/password re-provision via `LegacyReprovisionService` (exact user change, no duplicate authority, no blind revert after partial external apply).

## 22. Direct MikroTik driver classification

Fact: **the accepted reseller activation path never calls a MikroTik driver.** `ActivationService` requires `plan.device === 'Radius'` (`ActivationService.php:97,154,234`) and touches the network only through RADIUS rows and `CoAClient`. Class A is therefore empty. Drivers are created dynamically (`new $p['device']`), so one caller reaches all three.

| Method (Hotspot `MikrotikHotspot.php` / PPPoE `MikrotikPppoe.php` / VPN `MikrotikVpn.php`) | Class | Callers | WP8 treatment |
|---|---|---|---|
| `add_customer` (H:32, P:30, V:23) — guarded by `assertDeviceAllowed` | **B** (`LegacyReprovisionService.php:342,344` password change / identity edit) + **C** (`Package.php:264,375`, `plan.php:84,514,1176`, `customers.php:329,901`, `home.php:100,179`, `accounts.php:74`, `login.php:107,152`, all behind ServiceGate/`owned()`) | real-router contract test on `nuxr_wp8_local_*` identity (B path), plus fail-closed re-check for a reseller-owned customer (§23) |
| `sync_customer` (H:56, P:93, V:73) — guarded | **B** (`LegacyReprovisionService.php:346`) ; legacy callers' `method_exists($dvc,…)` branch is dead (OBS-W8-2) | contract test (B) |
| `change_username` (H:100, P:126, V:105) — guarded | **B** (`:331-339`) + **C** (`customers.php:887-898`) | contract test (B), incl. the 07_MIKROTIK_CONTRACT "wrong variable" suspicion on Hotspot `change_username` |
| `remove_customer` (H:83, P:100, V:80) — **not guarded** | **C** (`cron.php:83` expiry for all customers incl. reseller-owned per WP5B model; `plan.php:443,504`, `customers.php:286,515`, `home.php:206`) | contract test for the expiry path on a local identity; see OBS-W8-1 |
| `online_customer` (read) | C (`autoload.php:128,161`, `autoload_user.php:27`) | read-only contract test |
| `connect_customer` / `disconnect_customer` (Hotspot only) | C (`login.php:200`, `home.php:233,236`) | legacy-only rationale documented (not in FreeRADIUS-first path); OBS-W8-3 |
| `add_plan` / `update_plan` / `remove_plan` | C admin-only (`services.php` under `system.admin`) | contract test on profile `nuxr_wp8_local_profile` |
| `add_pool` / `update_pool` / `remove_pool` (PPPoE; VPN variants unused) | C admin-only (`pool.php:63,76,107,142,201`) | contract test on `nuxr_wp8_local_pool` |
| `getClient` | internal + `routers.php:86,154` ("test connection") | used by every contract test; observes transport (plain 8728, no timeout → OBS-W8-4) |
| `description`, `info`, `getIdByCustomer`, internal helpers | internal | none |
| `setHotspotUser`, `setHotspotUserPackage`, `getIpHotspotUser` (×3), `add/removeIpFromAddressList`, VPN pool methods, **entire static `Mikrotik` class** (`system/autoload/Mikrotik.php`, only self-call at :101) | **D** unused / dead | documented as unused; no test; no removal in WP8 |

Rule: local-user contract tests use identities/profiles **never** present in RADIUS (`nuxr_wp8_local_*`), because RouterOS consults RADIUS only when no local record matches (D6) — a shared identity would mask/split authority.

## 23. Fail-closed legacy tests (reseller-owned customer)

Guards: `Reseller\Domain\ServiceGate` (`ServiceGate.php:27-95`, fails closed on schema-not-ready/lookup error) and `LegacyReprovisionService::assertDeviceAllowed` (`:117-129`, `RESELLER_OWNED_REFUSED`) at the first line of `add_customer`/`sync_customer`/`change_username` in all five drivers. Staging re-checks for a reseller-owned `nuxr_wp8_cust_*` (expected: refusal, no radcheck/radreply change, no router user, no ledger movement, audit `ServiceGate::refused`):

| Path | Enforcement point | Existing tests reused |
|---|---|---|
| direct legacy device grant | driver guard (H:35/59/103, P:33/96/129, V:26/76/108) | `Wp5b/PluginBypassTest`, `MikrotikDeviceTest` (fake client → WP8 adds the real-router variant) |
| `Package::rechargeUser` all callers | `Package.php:32` | `PluginBypassTest`, `LegacyEntrypointClosureTest` |
| reseller balance purchase / gift / transfer | `order.php:220,321-322,556`, `home.php:25-26`, `Balance.php:26` | `Http/ServiceGateTest` |
| provider gateway retail | `order.php:182,407` | `Http/ServiceGateTest` |
| auto-renew | `cron.php:107-110` → `AutoRenewPolicy::unavailable` | `CronHardeningTest` |
| voucher grant | `voucher.php:64`, `login.php:85,187`, `VoucherRedemptionService.php:117` | `VoucherRedemptionTest` |
| admin recharge / deposit / extend / plan edit | redirects to `activation/create`; `plan.php:1098,1151,477`, `home.php:144` | `LegacyEntrypointClosureTest` |

Reseller wallet money is never blurred with legacy customer balance (`rechargeBalance`/`rechargeCustomBalance` blocked at `Package.php:477,540`). Unassigned legacy customers may get separate compatibility smoke tests on `nuxr_wp8_legacy_*` identities (owner §19 of the directive); no disabled path is enabled.

## 23a. Observations recorded during reconnaissance (not fixed, not in WP8 scope unless the owner says so)

- **OBS-W8-1** — `remove_customer` paths are not guarded for reseller-owned customers: `plan.php:443` (admin deletes an active plan, no ServiceGate), `customers.php:286` (deactivate), `home.php:206` (customer-portal deactivate). These are removals, not grants, so WP5B's closure did not cover them; the portal path lets a reseller-owned customer tear down its own RADIUS/router user outside the activation/reversal state machine. Severity LOW–MEDIUM; pre-existing; owner to classify (candidate carry-forward).
- **OBS-W8-2** — `method_exists($dvc, 'sync_customer')` receives a file path at `plan.php:81`, `customers.php:326`, `home.php:97`, so legacy syncs always fall through to `add_customer`. Pre-existing upstream behaviour.
- **OBS-W8-3** — `home.php:233/236` can call `connect_customer`/`disconnect_customer` on PPPoE/VPN drivers that do not define them. Pre-existing.
- **OBS-W8-4** — RouterOS client: no TLS, no explicit timeout (falls back to `default_socket_timeout`, only `autoload.php:127,160` sets 5 s); `'Demo'` vs `'demo'` stage check differs between Hotspot and the other drivers; missing `:port` raises an "Undefined array key 1" warning on PHP 8.2. Relevant to the API-unavailable drill (§24 #8) and OD-W8-5.
- **OBS-W8-5** — `LegacyReprovisionService` classifies any driver exception (incl. connection refused on the first step) as UNKNOWN → `REPROVISION_UNCONFIRMED`, never a clean FAILED, because drivers throw PEAR2 exceptions rather than `ProvisionFailure` (`:300-314`). Accepted behaviour (no blind revert); WP8 only records the real-router manifestation.

## 24. Network-loss drills

| # | Drill | Mechanism (staging only) | Expected accepted semantics |
|---|---|---|---|
| 1 | FreeRADIUS unreachable before provisioning | `docker compose stop freeradius-wp8` (test project only) | activation FAILED before any write (radius write fails) |
| 2 | RADIUS DB unreachable before COMMIT | `RadiusFaults::$hook` `radius.after_write` / test DB stop | FAILED, no partial rows |
| 3 | Uncertain commit | `radius.commit_lost` | UNKNOWN → RECONCILIATION_REQUIRED, no retry |
| 4 | NAS unreachable for Disconnect | NAS firewall drop from dev source | `UNKNOWN COA_NO_RESPONSE`, provisioning kept |
| 5 | CoA port blocked | router `/radius incoming` port mismatch | same as 4 |
| 6 | RADIUS auth timeout | FreeRADIUS paused (`docker pause` test container) | router `timeout`, client reject; no app state change |
| 7 | Accounting interruption & recovery | pause acct during a session, resume | interim resumes; Stop recorded; no deletion |
| 8 | Management API unavailable | NAS firewall drop 8728 | driver contract tests report failure, no blind retry |
| 9 | Disconnect sent, response lost | NAS firewall drop **outbound** CoA replies | UNKNOWN, 3 identical packets |
| 10 | Path restored | re-enable tunnel/rules | normal operation resumes; reconciliation resolves UNKNOWN |

Invariants checked after each: no double settlement, no lost reservation, no fabricated success, radacct count non-decreasing.

## 25. Cleanup / rollback plan (prepared before execution)

Router: delete every `nuxr_wp8_*` object (pools, profiles, secrets, hotspot users, server, radius entry, firewall rules) via a scripted read-then-delete; `/radius incoming` restored to the owner's prior values (captured first). App DB: disposable `wp1_*`/staging databases dropped. Host: `wg0` down and `ufw` rules removed after WP8 unless the owner keeps the path. Evidence: redacted copies kept under `owner-review/WP8/evidence/`.

## 26. Evidence / redaction plan

Helper `dev/wp8/redact.py` masks `secret=…`, `password=…`, `private-key`, API credentials, cookies, and any value equal to a known secret from `scratchpad/.devpw`-style sources before anything is stored. Evidence set: `/radius print detail` (sanitized), `/radius monitor`, `/radius incoming print`, `/ppp active`, `/ip hotspot active`, filtered logs, FreeRADIUS `-X` excerpts for test identities, `radacct`/`radcheck`/`radreply`/group rows, activation/event rows, wallet/reservation/ledger rows, API readback, CoA result metadata, timestamps, drill chronology. A secret sweep (`grep` of the known secrets' sha256 via helper) runs before every commit.

## 27. Focused implementation slices (only after READY)

S1 staging stack: compose profile `wp8` (`freeradius-wp8` with tunnel-bound ports, `read_clients=yes`, `TZ`), `dev/wp8/` scripts (redaction, RouterOS read-only inventory via PEAR2, CoA probe calling `CoAClient`, evidence capture). S2 real-NAS smoke: PPPoE + Hotspot auth/accounting (§13–§17). S3 Disconnect/CoA matrix (§18). S4 A→B→C finance + Sales (§19–§20). S5 lifecycle (§21). S6 drills (§24). S7 driver classification + contract tests (§22) and fail-closed re-checks (§23). S8 `owner-review/WP8/REPORT.md` + `docs/WP8_INTEGRATION_MODEL.md` + WP4 model "verified on real NAS" notes. Focused tests per slice; `tests/Integration/Wp8/*` real-NAS tests gated by `NUXR_WP8_REAL_NAS=1` and **marked incomplete (never passed) when the NAS is absent**.

## 28. Candidate validation strategy

One complete relevant validation: wp4/wp5a/wp5b/wp8 suites on MariaDB (dual-engine only if DB behaviour is touched — not expected); real-NAS evidence set (§26) mandatory; no route matrix (RouteMap unchanged), no migration validation (no schema), no visual sweep (no UI change unless OD-W8-3 chooses a label change → then the two NAS templates get a focused `pwa_visual` check only).

## 29. Stop conditions

The owner's 20 stop conditions are adopted verbatim. **Condition 1 (no real disposable/staging MikroTik) is currently met** → this plan stops at readiness. Conditions 11/12 would be met by any proposal to expose 8728 publicly; condition 9 by a NAT topology.

## 30. Risks / residuals

R1 shared host: WireGuard + `ufw` changes touch the AVAIA host network stack (mitigation: tunnel-bound ports, guard snapshots, owner decision). R2 FreeRADIUS `read_clients` reads `nas` at start only (reload required). R3 CoA port mis-configuration silently yields UNKNOWN (OD-W8-3). R4 `Expiration` TZ coupling. R5 Hotspot MAC-cookie/trial could mask RADIUS. R6 local PPP secrets mask RADIUS (D6). R7 PEAR2 1.0.0b6 vs RouterOS 7 untested. R8 loopback PPPoE client on the same router is weaker evidence than an independent client. R9 `R-HF-CARRY-01` stays OPEN and out of scope.

## 31. Owner decisions required

**OD-W8-1 — Provide a real staging MikroTik and the private path (BLOCKING).** Problem: no NAS reachable; host cannot run CHR (no nested virt). Options: (A) owner-provided physical MikroTik/CHR at owner premises with a **WireGuard** peer to the dev host (install `wireguard-tools`, `wg0`, 2 `ufw` rules on the shared host); (B) CHR on another VPS/VM with KVM that the owner provides, same tunnel; (C) no NAS → WP8 stays NOT_READY. Recommended: A (or B). Blast radius: host network (shared with AVAIA) — tunnel only, no public services. Blocks implementation: **yes**.

**OD-W8-2 — Where the NAS reaches FreeRADIUS.** (A) tunnel-only (recommended); (B) public IP 1812/1813 with `ufw` source restriction — NOT recommended (NAT breaks NAS identity; RADIUS over internet). Blocks: yes (part of OD-W8-1 design).

**OD-W8-3 — CoA port convention.** Evidence §10. Options: (A) keep model; configure the staging router `/radius incoming port=3799` per README; in WP8 docs only, record the rule "Ports = CoA/Disconnect port; empty → 3799; RouterOS default is 1700" (no code); (B) same + a UI text change of the label/placeholder in `nas-add.tpl`/`nas-edit.tpl` to "CoA / Disconnect port (empty = 3799)" — a tiny runtime change, needs focused test + visual check; (C) change fallback to 1700 — rejected (breaks the accepted README convention and RFC port); (D) dedicated column — rejected (schema). Recommended: A for the POC, B as a separate owner-approved follow-up. Blast radius: none (A) / 2 templates (B). Blocks implementation: no.

**OD-W8-4 — NAS identity rule.** Adopt the operational rule "`nas.nasname` = RouterOS `/radius src-address` = tunnel IP" as binding; if the real NAS contradicts (nasipaddress ≠ nasname), STOP for a separate decision. Blocks: no (verification outcome).

**OD-W8-5 — RouterOS API transport.** (A) plain API 8728 over the tunnel only (recommended; no runtime change); (B) API-SSL 8729 — requires passing `$crypto` in 4 call sites + certificate handling → runtime architectural change, defer to WP9. Blocks: no.

**OD-W8-6 — Tool installation on the shared host.** `wireguard-tools` (needed for A/B in OD-W8-1); `freeradius-utils`/`radclient` only inside throwaway containers (recommended), never on the host; `tcpdump` already present. Blocks: tied to OD-W8-1.

**OD-W8-7 — Staging FreeRADIUS mode.** (A) `read_clients=yes` from `nas` table (production path, recommended); (B) `clients.conf` as the harness. Blocks: no.

**OD-W8-8 — Test client for PPPoE/Hotspot.** Owner to state which client exists (independent device vs. RouterOS pppoe-client loop). Blocks: yes for §13–§14 evidence quality.

**OD-W8-9 — Classification of OBS-W8-1** (ungated `remove_customer` paths for reseller-owned customers, §23a). Options: (A) record as a new carry-forward `R-WP8-CARRY-01` OPEN / pre-existing, out of WP8 scope (recommended; same handling as `R-HF-CARRY-01`); (B) treat as stop condition 15 and authorize a separate hotfix. Blast radius: none in WP8. Blocks implementation: no.

## 32. Exact expected file scope (implementation phase; nothing in this turn except PLAN.md)

- `owner-review/WP8/{PLAN.md, REPORT.md, OWNER_DECISIONS.md, evidence/*}` (redacted)
- `dev/test/compose.yml` (+ profile `wp8` service `freeradius-wp8`), `dev/wp8/{redact.py, ros_inventory.php, coa_probe.php, capture.sh, cleanup.rsc.tpl, README.md}`
- `tests/Integration/Wp8/*`, `tests/phpunit.xml` (+ `wp8-integration`)
- `docs/WP8_INTEGRATION_MODEL.md`; `docs/WP4_RADIUS_MODEL.md` (real-NAS verification notes only); `install/freeradius/README.md` (CoA port note, if OD-W8-3 A)
- **No** `system/` runtime change expected; **no** schema/migration; **no** RouteMap/capability/DataScope/wallet/ledger/pricing/activation/reversal/PWA/CSRF change. Only OD-W8-3 option B would touch `ui/ui/admin/radius/nas-add.tpl`, `nas-edit.tpl`.

## 33. Final readiness verdict

**NOT_READY_REAL_NAS_REQUIRED.** FreeRADIUS is ready as a disposable container; the dev host has no reachable MikroTik, no private/WireGuard path and cannot host a CHR. WP8 implementation cannot start until OD-W8-1 (and OD-W8-2/-6/-8 that depend on it) are decided and a disposable staging NAS is reachable over a private path. All other owner decisions (OD-W8-3/-4/-5/-7) are recorded for decision at the same time so implementation can start immediately once the NAS exists.
