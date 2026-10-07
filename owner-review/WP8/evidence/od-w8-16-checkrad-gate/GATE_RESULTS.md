# OD-W8-16 Option F — checkrad gate on RouterOS CHR 7.24.5 — results (2026-10-07)

**Result: GATE FAILED (Gate D — verification failure fails OPEN).** Option F was **not implemented**: no change to
`install/freeradius/**`, no `RadiusPolicyBuilder` change, no Hotspot Port-Limit in the runtime policy.

Lab only (`dev/wp8/checkrad_gate.py`, `dev/wp8/checkrad_gate.sh`, `tests/Integration/Wp8/CheckradGateTest.php`):
same pinned FreeRADIUS 3.2.8 image (`sha256:af6fd34a…`), same real RouterOS CHR 7.24.5, independent Linux PPPoE /
Hotspot clients, NAS active table sampled every 0.5 s while the competing client tried. `lab-evidence.jsonl` holds the
final complete run of every mode (`OD16F.*`, redacted); the exploratory runs before it had identical outcomes.

## Stock mechanism (FreeRADIUS 3.2.8, exact)

| Item | Value |
|---|---|
| checkrad | `/usr/sbin/checkrad` (Perl), sha256 `be0093605a2a032942631067fefe74ab1725f4de3b4460cc7dbfe48957cf5738`; identical to upstream `src/main/checkrad.in` (tag `release_3_2_8`) except autoconf substitutions |
| MikroTik methods | `mikrotik_snmp` (Evren Yurtesen, v1.0) — used; `mikrotik` = Telnet — **not used**, RouterOS Telnet not enabled |
| `mikrotik_snmp` | forces SNMP **v1**; community from `naspasswd` (line `<nas-ip> SNMP <community>`; a login other than `SNMP` -> exit 2; no entry -> falls back to `public`); walks **`1.3.6.1.4.1.14988.1.1.14.1.1.2` (MIKROTIK-MIB `mtxrInterfaceStatsName`)** and returns 1 if any line matches `/<.*-USER>/` (the dynamic `<pppoe-USER>` interface), else **0**; the walk is run with backticks, its exit status and stderr are ignored |
| program paths | the image was built without net-snmp, so autoconf left `$snmpget = ""` / `$snmpwalk = ""`; the lab copy sets exactly these two build-time paths (`/usr/bin/snmpget`, `/usr/bin/snmpwalk`) — `diff` = 4 lines, no logic change |
| SNMP tooling | Ubuntu 22.04 package `snmp` 5.9.1+dfsg-1ubuntu2.9 (+ `libsnmp40`, `libsnmp-base`, `libpci3`, `libsensors5`, `libwrap0`, `pci.ids`) installed in the disposable lab container only; relevant file `/usr/bin/snmpwalk` |
| invocation | `rad_check_ts()` execs `checkrad <nas.type> <radacct.nasipaddress> <radacct.nasportid> <username> <acctsessionid>`, e.g. `checkrad mikrotik_snmp 10.88.0.2 ether3 nuxr_wp8_cr_… 81000000`; waits at most 10 s (`rad_waitpid`, then "timeout" = 2) |
| FreeRADIUS side | `session { sql }` -> `simul_count_query` (stock); if count >= Simultaneous-Use -> `simul_verify_query` (stock) -> per open row `rad_check_ts`: 0 = stale (zapped only if `delete_stale_sessions = yes`), 1 = live (counted), anything else -> `Failed to check the terminal server` -> `RLM_MODULE_FAIL`; `process_checksimul()` returns "not over the limit" for **any non-OK** session result |
| `delete_stale_sessions` | set to **`no`** for the gate; proven: an admitted login leaves the stale row byte-for-byte unchanged (Gate B) |
| NAS community | FreeRADIUS never reads `nas.community` (client query: `id, nasname, shortname, type, secret, server`); the lab `naspasswd` (0600 `freerad`) is derived from the `nas` rows with `type = mikrotik_snmp` |
| RouterOS SNMP (lab) | `/snmp enabled=yes`; default `public` community **disabled**; one random test-only read-only community (`write-access=no`, `addresses=10.88.0.10/32`); input filter drops UDP/161 from anything but 10.88.0.10; isolated `wp8lab0` only; community kept in `$NUXR_WP8_STATE/snmp.env`, redacted from all evidence |

## Gate results

| Gate | Observed on RouterOS 7.24.5 (FreeRADIUS debug + checkrad trace + NAS table) | Result |
|---|---|---|
| **A** healthy live PPPoE, Simultaneous-Use 1, Interim working | open row + Interim; C2 same username -> `SELECT COUNT(*)` = 1 -> `simul_verify_query` -> `checkrad mikrotik_snmp … exit=1` (0.14-0.35 s) -> **Access-Reject** "You are already logged in - access denied"; NAS max **1**; C2 never got an address | **PASS** |
| **B** lost Stop, session gone | UDP/1813 dropped, C1 disconnected, `/ppp active` empty, Stop lost, accounting restored, row still OPEN; re-login -> verify -> `checkrad … exit=0` -> **Access-Accept** (no reboot, no 180 s wait, no delete, no fabricated Stop); radacct = stale historical row + new genuine row; **old row byte-for-byte identical (every column) after the admission and after the stale check**; `AccountingService::staleSessions()` reports the old row and not the live one (database clock, see OBS-W8-8) | **PASS** |
| **C** accounting lost, session live | UDP/1813 dropped 190 s (row age >= 190 s at the attempt), C1 live; C2 -> `checkrad … exit=1` -> **Access-Reject**; NAS max **1** | **PASS** |
| **D1** RouterOS SNMP unreachable (input drop UDP/161) | `checkrad … exit=0 ms≈10200-10250` (snmpwalk timeout = "not logged in") -> FreeRADIUS **Access-Accept** for the duplicate, sent ~10.3 s after the first Access-Request; RouterOS (timeout 3 s, 3 tries) had already logged `authentication failed - radius timeout`, so no second session **only by timing** | **FAIL (decision = admit)** |
| **D2** SNMP client unavailable inside FreeRADIUS | `sh: 1: /usr/bin/snmpwalk: Permission denied` -> `checkrad … exit=0 ms≈20-26` -> **Access-Accept** -> **2 genuine live PPPoE sessions** (`<pppoe-USER>` + `<pppoe-USER-1>`, both with addresses) for Simultaneous-Use 1 | **FAIL** |
| **D3** checkrad reports an error (exit 2) | `checkrad … exit=2` -> `sql: ERROR: Failed to check the terminal server for user '…'` -> `} # session = fail` -> **Access-Accept** -> **2 genuine live PPPoE sessions** | **FAIL** |
| **E** control, `nas.type = other` | lost-Stop scenario: `checkrad: No NAS type, or type "other" not checking` (checkrad never runs) -> **Access-Reject**, lockout reproduced | PASS (control) |
| Hotspot `shared_users 1` (Simultaneous-Use 1 + Port-Limit 1) | 2nd device: verify -> `checkrad … exit=0` (Hotspot sessions have no `<…-USER>` interface) -> FreeRADIUS Access-Accept -> **RouterOS refuses** ("no more sessions are allowed for user"); max 1 | PASS (RouterOS layer) |
| Hotspot `shared_users 2` (Simultaneous-Use 2 + Port-Limit 2) | 1st, 2nd allowed; 3rd: `checkrad exit=0` x2 -> Accept -> **RouterOS refuses**; max 2 | PASS (RouterOS layer) |
| Hotspot 1, accounting lost 190 s | 2nd device: `exit=0` -> Accept -> **RouterOS refuses**; max 1 | PASS (RouterOS layer) |

## Why Option F fails

1. **checkrad layer** — the stock `mikrotik_snmp` method cannot tell "the NAS answered and the user is absent" from
   "the NAS did not answer": an empty walk (timeout, wrong / missing community, SNMP disabled or filtered, SNMP client
   missing) returns **0 = not logged in**, so the genuine open row is treated as stale.
2. **FreeRADIUS layer** — even an explicit checkrad error (exit 2, or the 10 s `rad_waitpid` timeout) makes the session
   section return `fail`, which `process_checksimul()` treats as "not over the limit": **Access-Accept**.
3. Both are stock behaviour of 3.2.8; neither has a fail-closed setting. Making it fail closed would need a project-owned
   verifier and / or unlang policy (owner instruction: do not patch around without review).

Additional facts: Hotspot rows are never seen as live by `mikrotik_snmp` (interface-based), so with `mikrotik_snmp`
the SQL Simultaneous-Use layer admits every Hotspot duplicate and RouterOS Port-Limit is the only Hotspot enforcer.
A blocked SNMP path costs every login of a subscriber with an open row ~10 s of a FreeRADIUS worker (RouterOS
retransmits, then times out). Source review (not lab-executed): the username is interpolated unescaped into the Perl
regex (`.`/`+` widen the match; an unbalanced `(` makes Perl die -> exit 255 -> treated as error -> admit).

## Production mapping (recorded as asked; NOT deployable as is)

`nas.type = mikrotik_snmp` per MikroTik NAS; `naspasswd` rendered from the `nas` rows (`<nasname> SNMP <community>`,
0600, owner of the FreeRADIUS user) and FreeRADIUS restarted whenever a NAS row changes (clients and types are read at
start); net-snmp `snmp` in the FreeRADIUS host / image and checkrad's two program paths set; RouterOS SNMP read-only,
`public` disabled, community restricted to the FreeRADIUS source, management network / VLAN / WireGuard-equivalent
only; community != RADIUS secret; SNMPv1 (forced by the stock method) = cleartext community -> never over untrusted
networks. **Not compliant today because of Gate D.**

## OBS-W8-8 (new observation, pre-existing, not fixed)

FreeRADIUS stores radacct times with `FROM_UNIXTIME()` in the **database session time zone** (lab MariaDB: UTC), while
`AccountingService::staleSessions($now, …)` compares them with the **application clock** (Asia/Baghdad, UTC+3). In the
lab, `staleSessions(new DateTimeImmutable('now'), 120)` therefore listed the live row as stale as well (Gate B evidence:
`stale_reported_with_app_clock` = old + live); with the database clock it lists only the stale row. Any deployment where
the MySQL/MariaDB time zone differs from `$config['timezone']` is affected (same family as WP4 residual 4,
`Expiration` parsing). The S6 drill-7 stale listing was correct for its row but not discriminating for this reason.
