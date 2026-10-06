# WP8 real-NAS lab (development/test only)

Reproducible lab for the WP8 integration POC: a **genuine RouterOS CHR 7.24.5** (QEMU software emulation, free
license) as the NAS, the project **FreeRADIUS 3.2.8** with **production-style NAS clients from the `nas` table**, the
**application test runner**, **MariaDB**, and **independent Linux PPPoE and Hotspot clients**, all on isolated private
networks. Never used for production; nothing here is a runtime dependency of PHPNuxBill.

## Topology

```
host (no uplink bridge, no firewall change)       CHR 7.24.5 (QEMU TCG, 1 vCPU, 1 GiB)
127.0.0.1:18728 --QEMU user-mode, restrict=on--->  ether1  management API only
docker bridge wp8lab0 10.88.0.0/24 <--wp8tap1-->   ether2  10.88.0.2   /radius src-address, CoA listener :3799, API :8728
  freeradius-wp8  10.88.0.10  FreeRADIUS, read_clients = yes (nas: 10.88.0.2 CHR, 10.88.0.10 app)
  php82-wp8       shares the freeradius-wp8 network namespace -> application CoA leaves from 10.88.0.10
  netns wp8srv    10.88.0.40  traffic peer (rate-limit direction)
linux bridge wp8br1 (no IP) <--wp8tap2-->          ether3  PPPoE server "nuxr_wp8" (CHAP), pools nuxr_wp8_pool_dyn/_dyn2
  netns wp8c1, wp8c3          independent pppd clients
linux bridge wp8br2 (no IP) <--wp8tap3-->          ether4  10.88.2.1/24 Hotspot "nuxr_wp8_hs" (http-chap) + DHCP
  netns wp8c2, wp8c4          independent Hotspot clients (udhcpc, curl)
```

Deterministic addresses: 10.88.0.0/24 (lab), 10.88.10.0/24 (PPPoE), 10.88.2.0/24 (Hotspot). `lab.sh up` refuses to start
when 10.88.0.0/16, a lab link, namespace, QEMU or 127.0.0.1:18728 is already in use.

## Invariants proven here (owner decisions)

- **OD-W8-13 / OD-W8-10**: Access-Request without Message-Authenticator -> no response (virtual-server policy);
  wrong Message-Authenticator -> discarded on receipt; RouterOS >= 7.15 required; accounting not affected.
- **OD-W8-11**: CoA source = an address configured as RouterOS `/radius` server. The runner shares the FreeRADIUS
  network namespace, so the unchanged application code sends CoA from 10.88.0.10. No SNAT, no second `/radius`.
- **OD-W8-4**: `radacct.nasipaddress == nas.nasname == /radius src-address` (10.88.0.2).
- **OD-W8-3 A**: `nas.ports = 3799` and `/radius incoming accept=yes port=3799` (RouterOS default is 1700).

## Usage (root, on the lab host)

```bash
export NUXR_WP8_STATE=/var/lib/nuxr-wp8      # outside Git: secrets, CHR image + working disk, pcaps, logs
dev/wp8/lab.sh fetch-image                   # https://download.mikrotik.com/routeros/7.24.5/chr-7.24.5.img.zip
dev/wp8/lab.sh up                            # ~2 min (CHR boot under TCG)
dev/wp8/lab.sh test                          # PHPUnit suite wp8-integration in the runner
dev/wp8/lab.sh down                          # idempotent cleanup: QEMU, TAPs, bridges, veths, namespaces, containers
```

Pinned image: zip SHA-256 `16f07222a3213352c9c4fbea4f4dec6a8ffb78fdc0fa5e398c23103ed357c6eb`, raw image SHA-256
`e4caf173aceb497433c5fc285bffee09c1e6761dfe0e658a2b178970249fc2d2`. `lab.sh` refuses any other image.

Host packages (dev/test tools only): `qemu-system-x86`, `qemu-utils`, `ppp`, `pppoe`, plus `tcpdump`, `curl`, `busybox`.

## Files

| File | Purpose |
|---|---|
| `lab.sh` | up / down / status / test / php / fetch-image |
| `compose.wp8.yml` | lab network, `freeradius-wp8`, `php82-wp8` on top of `dev/test/compose.yml` (project `nuxbill-reseller-test`) |
| `freeradius/start.sh` | project configuration + `read_clients = yes`; debug log for evidence |
| `configure_chr.py` | deterministic CHR configuration over the API |
| `rosapi.py` | minimal RouterOS API client - **lab tooling only**; application compatibility uses the vendored PEAR2 client |
| `client_agent.py` | host-side, whitelisted operations for the tests (pppd / Hotspot clients, CHR management, captures) |
| `pppd-client.opts.tpl` | PPPoE client options template |
| `redact.py` | evidence redaction (all lab secrets, password attributes) |
| `coa_probe.php`, `api_probe.php` | diagnostics with the application's `CoAClient` and the vendored PEAR2 RouterOS client |

Never commit images, working disks, secrets, pcaps or serial logs (see `.gitignore`); evidence is redacted with
`redact.py` before it is copied under `owner-review/WP8/evidence/`.
