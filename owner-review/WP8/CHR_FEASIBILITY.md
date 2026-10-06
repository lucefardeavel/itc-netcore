# WP8 — CHR feasibility gate (owner amendment W8-A1) — 2026-10-06

**Classification: `PASS_LOCAL_CHR`.** A genuine MikroTik RouterOS CHR (7.20.3, license level *free*) boots and operates
reliably under QEMU software emulation (TCG, no `/dev/kvm`) on the development host, inside a fully isolated local lab:
independent Linux-namespace clients authenticated over PPPoE and Hotspot through the disposable FreeRADIUS, accounting
rows were written, and the application's own `CoAClient` disconnected both sessions (ACK) over the isolated network.
It qualifies as **`REAL_ROUTEROS_CHR_TEST_NAS`**: every facility exercised (`/radius`, `/radius incoming`, `/ppp aaa`,
PPPoE server, Hotspot, API, firewall, pools, active-session tables) is RouterOS itself, not an emulation of its commands.

No application runtime, schema, `main`, production or AVAIA object was modified. Full WP8 (S1–S8) has **not** started.
Evidence: `evidence/chr-feasibility/` (all secrets redacted; CHR images, pcaps and lab secrets are not committed).

## 1. Baseline

`wp8/integration-poc` = `5de6d8e6049f0482294102ddb7fe0bab493c0b74` (parent `5bdc5745…`, canonical `feat`); `wp7`
`8e7f0e1c`, `wp6c` `757d8de9`, `hotfix` `6d4f3f96`, `main` `500d3a2d` unchanged before and after the gate.

## 2. Host feasibility (read-only first)

| Item | Finding |
|---|---|
| OS / kernel | Ubuntu 24.04.5 LTS, Linux 6.8.0-142-generic; host is itself a KVM guest (`systemd-detect-virt: kvm`) |
| Hardware acceleration | `/dev/kvm` absent; 0 `vmx/svm` flags → TCG only |
| QEMU | not installed before the gate; installed `qemu-system-x86` **8.2.2** (Debian 1:8.2.2+ds-0ubuntu1.18); binary accelerators `tcg kvm`; used `-accel tcg` |
| Privileges / namespaces | root (`=ep`); `ip netns add/exec/del` probe OK; `/dev/net/tun` present (builtin); `br_netfilter` not loaded |
| Resources | 12 vCPU, 47 GiB RAM (40 GiB available), 93 GiB free disk; host load ~3.9 before, ~5.9 during the gate |
| CPU model for CHR | `-cpu max` under TCG; RouterOS reports `cpu = QEMU`, `architecture-name = x86_64`, `board-name = CHR` |

Packages installed (host dev/test tools, not application dependencies): `qemu-system-x86`, `qemu-utils`, `ppp`, `pppoe`
plus 51 dependencies — exact list `evidence/chr-feasibility/packages-installed.txt` (55 entries). `wireguard-tools` was
**not** installed (OD-W8-6). Before installation: host networking recorded (`host-network-before-after.txt`), dpkg list
recorded. The first AVAIA guard snapshot attempt before the install failed on a script usage error (no file argument →
0 lines); it was retaken immediately after the install (27 protected containers) and the container start times prove
no protected container restarted during the install (newest start 09:29:27Z, install 10:03Z).

## 3. CHR images (downloaded from MikroTik; not committed; `/srv/phpnuxbill/wp8lab/img`)

| | 7.12.1 | **7.20.3 (used for the result)** |
|---|---|---|
| Source | `https://download.mikrotik.com/routeros/7.12.1/chr-7.12.1.img.zip` (what `upgrade.mikrotik.com/routeros/NEWEST7.stable` reported — stale) | `https://download.mikrotik.com/routeros/7.20.3/chr-7.20.3.img.zip` (newest present on the download server; 7.15–7.20.3 all probed) |
| SHA-256 zip | `3c6c42b86cefabf5ba2a5aacc701e6b1d96709533b1a23cf050f4c0844abd69e` (40 868 739 B) | `feade269f247fb76aea41e7ae9379698d33eccfcf2d9435868ef5ef9b0fa13ea` (43 485 600 B) |
| SHA-256 raw img | `36d550f3e49f9125c57aad84baac7ccfd096f15b5f8b7c9ca001be8fa9b146f1` | `231babe722ace2a397c8a230ea1a5fb15b159797e06196cc66ba982d7fea1548` |
| Type / size | raw disk image, DOS/MBR, 2 partitions, 134 217 728 B | same layout, 134 217 728 B; package `routeros 7.20.3` built 2025-10-28 12:45:18 |

7.12.1 was booted first; it produced the Message-Authenticator finding (§7) and was then replaced by 7.20.3.

## 4. First boot — software emulation

QEMU: `-machine pc,accel=tcg -cpu max -smp 1 -m 1024`, raw virtio disk (working copy), 4 × `virtio-net-pci`
(ether1 = QEMU user-mode net `restrict=on` with only `hostfwd 127.0.0.1:18728→8728` for management; ether2/3/4 = TAPs),
`-display none`, serial console to a file, QEMU monitor on a unix socket. No bridge to `eth0`, no public interface.

| Measure | 7.12.1 | 7.20.3 |
|---|---|---|
| Boot → first successful API login (from QEMU start) | **79.7 s** | **52.3 s** |
| Serial console | `MikroTik 7.12.1 (stable) / MikroTik Login:` | `MikroTik 7.20.3 (stable) / MikroTik Login:` |
| Host CPU of the QEMU process | ~100–120 % of one core while booting; 17–34 % idle; 39–60 % during tests | same pattern (89 % at boot end, 39–43 % during tests) |
| Host RSS | 318–369 MB | 325–386 MB |
| Guest | total-memory 1 006 632 960 B, free 916 MB, cpu-load 6–13 % idle | total-memory 1 073 741 824 B, free 830 MB, cpu-load 35 % during tests |
| License | `level = free` | `level = free` |
| API responsiveness | login 87–335 ms; `/system/resource/print` 19–282 ms (avg 68 ms over 10) | login 104–155 ms; reads 12–51 ms |
| Stability | no crash, no reboot, no API error in ~16 min | none in ~7 min |

TCG is slow but deterministic and fully usable for functional AAA/CoA/accounting testing (one of three ICMP echoes was
lost in the first second of the PPPoE session; everything else answered).

## 5. Isolated network lab (recorded topology; cleanup script prepared first)

```
 host                                          CHR (QEMU/TCG)                       client namespaces
 127.0.0.1:18728 ─hostfwd─▶ slirp 10.0.2.0/24 ─ ether1  (management only, restrict=on)
 docker bridge wp8lab0 10.88.0.1/24 ◀─wp8tap1─ ether2 10.88.0.2/24   ◀── RADIUS src-address
   ├─ freeradius-mariadb 10.88.0.10 (compose project nuxbill-reseller-test, lab network "wp8lab")
   └─ php82 10.88.0.20 (compose run) / probes in the FreeRADIUS netns (source 10.88.0.10)
 linux bridge wp8br1 (no IP) ◀─wp8tap2─ ether3  PPPoE server "nuxr_wp8", profile nuxr_wp8_pppoe, pool 10.88.10.100-199
   └─ veth ─ netns wp8c1 (pppoe-discovery / pppd, RADIUS CHAP)
 linux bridge wp8br2 (no IP) ◀─wp8tap3─ ether4 10.88.2.1/24  Hotspot "nuxr_wp8_hs", DHCP, pool 10.88.2.100-199
   └─ veth ─ netns wp8c2 (udhcpc, curl)
```
Deterministic WP8-only addresses (10.88.0/10/2.0/24); no production, AVAIA (172.x) or customer subnet; the lab compose
override (`lab-compose-override.yml`) only adds the `wp8lab` network to the existing `nuxbill-reseller-test` project.

## 6. Genuine NAS qualification

`chr-7.20.3-facilities.json`: 24 RouterOS menus answered (`/system/resource|license|identity|package`, `/interface`,
`/ip/address`, `/radius`, `/radius/incoming`, `/ppp/aaa|profile|active|secret`, `/interface/pppoe-server/server`,
`/ip/pool`, `/ip/dhcp-server`, `/ip/hotspot|profile|active|user`, `/ip/firewall/filter|nat`, `/ip/service`, `/user`,
`/queue/simple`). CHR 7.20.3 has no static default firewall (only the hotspot placeholder rule; the 11/15 filter/NAT
rules are the Hotspot's dynamic rules); the only local Hotspot user is `default-trial`, no `/ppp secret` exists, so no
local record can mask RADIUS for the `nuxr_wp8_*` identities (RouterOS documentation D6 holds).

Lab configuration applied over the API (`configure_chr.py`): lab admin password, identity `nuxr_wp8_chr`, addresses,
pools, PPPoE profile/server (`pap,chap`), `/ppp aaa use-radius=yes accounting=yes interim-update=1m`,
`/radius add address=10.88.0.10 service=ppp,hotspot src-address=10.88.0.2 timeout=3000ms`,
`/radius incoming set accept=yes port=3799` (OD-W8-3 A), DHCP + Hotspot profile (`http-chap,http-pap`,
`use-radius=yes radius-accounting=yes`), `/ip service api address=10.88.0.0/24,10.0.2.0/24`.
7.20.3 reports `require-message-auth = yes-for-request-resp`.

## 7. RADIUS reachability (UDP 1812/1813 across the isolated topology)

- **Network path: proven** for both firmware versions (packets from `10.88.0.2` arrive at `10.88.0.10`; ICMP both ways).
- **7.12.1:** 6 Access-Requests and 7 Accounting-Requests delivered, **0 replies** — FreeRADIUS logged nothing. The
  capture shows the Access-Requests carry **no `Message-Authenticator`**, and the harness `clients.conf` requires it
  (`require_message_authenticator = yes`), so FreeRADIUS 3.2.8 silently discards them (BlastRADIUS behaviour). PPPoE
  ended with `radius timeout`, Hotspot with "RADIUS server is not responding". The harness file was **not** changed;
  a lab-only `auto` variant was prepared and deliberately **not applied** (owner decision OD-W8-10).
- **7.20.3:** Access-Requests carry `Message-Authenticator`; **2 Access-Accepts**, Accounting Start/Interim/Stop rows
  written for both sessions; `/radius monitor`: `accepts=5 rejects=0 bad-replies=0 last-request-rtt=160ms`.

## 8. PPPoE client feasibility (independent namespace `wp8c1`)

`pppoe-discovery` on the client veth: PADO from `Access-Concentrator: nuxr_wp8_chr`, `Service-Name: nuxr_wp8`.
`pppd` (plugin `pppoe.so`): CHAP challenge from `nuxr_wp8_chr`, **`CHAP authentication succeeded: Welcome.`**, local
`10.88.10.199`, remote `10.88.10.1`, **4.2 s** from pppd start to IPCP up. CHR `/ppp/active`: `nuxr_wp8_gate_radius`,
service `pppoe`, `radius: true`; dynamic queue `<pppoe-nuxr_wp8_gate_radius>` `max-limit = 512000/1000000` — the
`radreply` `Mikrotik-Rate-Limit := 512k/1M` was applied by the NAS. `radacct`: `nasporttype = Ethernet`,
`nasipaddress = 10.88.0.2`, `framedipaddress = 10.88.10.199`, Start + Interim, Stop `NAS-Request` after the CoA
disconnect (70 s, 550/686 octets).

## 9. Hotspot client feasibility (independent namespace `wp8c2`)

`udhcpc`: lease `10.88.2.199` from `10.88.2.1`. HTTP to an arbitrary address → **`302 Found`,
`Location: http://10.88.2.1/login?dst=…`**; login page `200` with `username/password/dst` fields; `http-pap` POST with
the gate identity → page text **"logged in"**, `/status` `200` showing the client address; CHR `/ip/hotspot/active`:
`nuxr_wp8_gate_hs`, `login-by: http-pap`, `radius: true`. `radacct`: `nasporttype = Wireless-802.11` (RouterOS Hotspot
convention), `framedipaddress = 10.88.2.198`, Stop `Admin-Reset` after the CoA disconnect; the client was intercepted
again (302) once the session was gone.

## 10. CoA / API feasibility from the PHP/app test context

All CoA packets were built and sent by the application's **`Reseller\Radius\CoAClient`** (unchanged), run in the
project `php82` image (`coa_probe.php`, lab tooling):

| Probe | Source | Result |
|---|---|---|
| `send()` Disconnect-Request, unknown session (7.12.1 and 7.20.3) | `10.88.0.10` (FreeRADIUS netns = the `/radius` server CHR knows) | authenticated **Disconnect-NAK, `Error-Cause 406`** → `FAILED COA_NAK` (accepted mapping) in 0.6 s |
| same request | `10.88.0.20` (php82's own lab address, not a `/radius` entry) | **ignored by RouterOS** → `UNKNOWN COA_NO_RESPONSE` after 3 identical sends / 6.0 s (no blind retry as a new request) |
| `disconnect('nuxr_wp8_gate_hs')` — nas lookup `radacct.nasipaddress == nas.nasname`, port `nas.ports = 3799` | `10.88.0.10` | **`SUCCESS COA_ACK`, `nas_id = 1`** in 0.24 s; hotspot active table empty; radacct Stop `Admin-Reset` |
| `disconnect('nuxr_wp8_gate_radius')` (PPPoE) | `10.88.0.10` | **`SUCCESS COA_ACK`, `nas_id = 1`** in 0.09 s; `/ppp/active` empty; pppd "Modem hangup / Connection terminated"; radacct Stop `NAS-Request` |

OD-W8-4 held on real packets: `radacct.nasipaddress` (`10.88.0.2`) == `nas.nasname`, exact match found the row.
RouterOS only answers CoA from a configured `/radius` server address (→ OD-W8-11).

RouterOS API TCP 8728 from the `php82` context (`10.88.0.20`) with the application's vendored `PEAR2\Net\RouterOS`
client (plain API, as in `system/autoload/Mikrotik.php`): login 87–155 ms, `/system/resource|license/print`,
`/radius/incoming/print`, `/ppp/aaa/print` 12–51 ms each, on both firmware versions. The library emits 14 PHP 8.2
`Deprecated` notices (observation OBS-W8-6, pre-existing).

## 11. Free license

`level = free` on both images; no paid license, trial or MikroTik account was used. The 1 Mbps per-interface limit is
irrelevant to the functional tests (auth, address assignment, accounting, CoA, disconnect, rate-limit application).

## 12. Public interface, firewall, AVAIA, cleanup

- `eth0` address, default route, the two `ufw` rules (22/tcp v4/v6), iptables INPUT and FORWARD chains and the docker
  network list are **identical** before and after the gate (`host-network-before-after.txt`). No port was published.
- Cleanup (`cleanup.sh`, prepared before testing): QEMU stopped, TAPs/bridges/veths/namespaces deleted, lab compose
  project down with volumes, `wp8lab` network removed — **0 wp8 links / 0 netns / 0 QEMU / 0 nuxbill containers,
  networks or volumes remain** (`cleanup.log`). The 7.20.3 lab image, secrets and pcaps stay only under the untracked
  `/srv/phpnuxbill/wp8lab/`.
- **AVAIA guard:** post-gate check reports **one VIOLATION: `avaia-wp2-pg-base` restarted at 10:14:08Z** (26 other
  protected containers unchanged). This was **not caused by the gate**: no WP8 command addressed any AVAIA object (the
  complete list of the gate's Docker actions is in `avaia-events-window.txt`); the container has restart policy `no`
  and exit code 0, i.e. it was explicitly recreated by another operator — the same third-party `avaia-wp2-pg-*`
  activity already observed at 09:20–09:29Z before this gate began (and during the hotfix work, OBS-HF-3). Reported for
  the owner's awareness; nothing was done about it.

## 13. Findings for WP8 proper

1. **FreeRADIUS Message-Authenticator policy vs NAS firmware** — OD-W8-10 (recommend keeping the hardened setting and
   fixing the NAS firmware floor at ≥ 7.15; WP8 runs 7.20.3).
2. **CoA source must be a `/radius` server address on the NAS** — OD-W8-11 (co-locate the application with FreeRADIUS
   or add the app host as a `/radius` entry); the WP8 lab runs app-side CoA from the FreeRADIUS network namespace.
3. `nas.ports = 3799` + `/radius incoming port=3799` works as accepted (OD-W8-3 A); RouterOS NAKs unknown sessions with
   Error-Cause **406**, not 503 — the accepted `SESSION_NOT_FOUND` mapping (503) will not trigger on RouterOS 7.20.3;
   record in WP8 S3 (no code change proposed here).
4. RouterOS Hotspot reports `NAS-Port-Type = Wireless-802.11` for a wired Hotspot client; PPPoE reports `Ethernet`.
5. `upgrade.mikrotik.com NEWEST7.stable` returned 7.12.1 while the download server holds 7.20.3 — pin the image by
   SHA-256, never by channel lookup.
6. OBS-W8-6: PEAR2 1.0.0b6 emits PHP 8.2 deprecation notices (pre-existing; not WP8 scope).

## 14. Proposed permanent tooling (OD-W8-12, not committed)

`dev/wp8/`: `compose.wp8.yml` (lab network override), `rosapi.py` (minimal RouterOS API client), `configure_chr.py`,
`cleanup.sh`, `redact.py`, `coa_probe.php`, `api_probe.php`, a `pppd.opts` template, a `README.md` with the QEMU command
and the image SHA-256 pins; `.gitignore` entries for images, secrets and pcaps. S1 will also need the `freeradius-wp8`
service (`read_clients=yes`, `TZ`) decided in the plan.

## 15. Verdict

`PASS_LOCAL_CHR`. OD-W8-1 (owner-provided NAS + WireGuard) is no longer required; the WP8 POC topology becomes fully
local and isolated. Full WP8 waits for the owner's review of this evidence and answers to OD-W8-10/11/12.
