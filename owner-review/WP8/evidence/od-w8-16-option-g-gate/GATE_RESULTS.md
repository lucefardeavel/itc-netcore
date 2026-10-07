# OD-W8-16 Option G — fail-closed PPPoE session guard gate on RouterOS CHR 7.24.5 — results (2026-10-07)

**Result: GATE PASSED** (every mandatory case, clean run on a freshly created lab; `lab-evidence.jsonl` = that run,
redacted). Candidate under test: `dev/wp8/freeradius/option-g/` (temporary copy - `install/freeradius` unchanged by
the gate): `nuxr_pppoe_session_guard` (POSIX sh), named exec module, post-auth policy. Same pinned FreeRADIUS 3.2.8
image, real RouterOS CHR 7.24.5, independent PPPoE / Hotspot clients, NAS active table sampled every 0.5 s.
`Simultaneous-Use` and `session { sql }` unchanged; `delete_stale_sessions = no`. Stock checkrad **exactly as shipped**
(sha256 `be009360…5738` asserted by every shipped-mode test; it cannot run SNMP in the pinned image and reports "not
logged in", i.e. the stock layer lets every open-row duplicate through - the guard is the forced backstop in every
case). A lab-only "functional stock checkrad" mode (Option F configuration) shows the stock layer rejecting first.

## 1. PPPoE / Hotspot discriminator (owner §6) — captured Access-Requests

| Attribute | PPPoE (RouterOS 7.24.5) | Hotspot (RouterOS 7.24.5) |
|---|---|---|
| Service-Type | **Framed-User** | **Login-User** |
| Framed-Protocol | **PPP** | absent |
| NAS-Port-Type | **Ethernet** | **Wireless-802.11** (although ether4 is wired) |
| NAS-Port-Id | `ether3` (server interface) | `ether4` |
| Calling-Station-Id | client MAC | client MAC |
| other | Called-Station-Id = PPPoE service name | Framed-IP-Address, Mikrotik-Host-IP, WISPr-Logoff-URL, Called-Station-Id = hotspot server |

Rule: **PPPoE ⇔ Service-Type == Framed-User AND Framed-Protocol == PPP AND NAS-Port-Type == Ethernet.** Hotspot
never matches (Login-User, no Framed-Protocol). Other RouterOS PPP services (L2TP / PPTP / SSTP / OVPN, NAS-Port-Type
Virtual) are not PPPoE and are not covered by this guard (interface prefix differs; out of the WP8 PPPoE/Hotspot scope).

## 2. Mechanism (FreeRADIUS 3.2.8, verified)

- `post-auth` runs only for a request that authenticated AND passed the stock `Simultaneous-Use` (a stock reject runs
  only `Post-Auth-Type REJECT`, guard not reached - `functional.gateB`).
- `rlm_exec` 3.2.8: exit 0 -> ok, 1 -> reject, 2 -> fail, timeout -> fail. The policy overrides every module action to
  "continue" and maps explicitly: **accept only if the module returned ok AND `control:Tmp-String-7 == "SAFE"`**;
  everything else -> `attr_filter.access_reject` + `Reply-Message "Session verification failed"` + `reject`. (On this
  path 3.2.8 does not run `Post-Auth-Type REJECT`; without the explicit filter the Reject carried
  Mikrotik-Rate-Limit / Framed-Pool / Session-Timeout - found and fixed in the gate; every Reject now carries only
  Reply-Message, asserted.)
- The program line is split into argv BEFORE expansion and `execve()`d - no shell. argv = `%{hex:&User-Name}`
  `%{control:Simultaneous-Use}` `NAS-list` (validated by an unlang regex). No environment (no `input_pairs`), output to
  the control list. The SNMP community is never in argv/env/logs: the guard writes it into a private per-run net-snmp
  config (`SNMPCONFPATH`, mode 0600 dir, removed on exit).
- Open-row key = the stock `SQL-User-Name` (created by a trivial `%{sql:SELECT '1'}`), escaped by the sql xlat. Two
  traps were caught by the gate and fixed: in post-auth a nested `%{SQL-User-Name}` was empty (query matched nothing ->
  "no open row" -> no verification), and a nested `%{User-Name}` differs from the stock key for `"` and `\` (stock
  key backslash-escapes them). Both would have skipped verification; the hostile-username gate now proves the key.
- Guard per NAS (parallel, hard deadline 1.8 s): credentials from the protected file -> **health probe `sysUpTime.0`
  first** (any non-zero exit / timeout / malformed output = ERROR) -> walk `1.3.6.1.4.1.14988.1.1.14.1.1.2`
  (MIKROTIK-MIB `mtxrInterfaceStatsName`, v2c bulk, `-Ox` hex, 1 s timeout, 0 retries) -> every line must parse,
  empty table = ERROR -> byte-exact hex comparison with `<pppoe-USER>` or `<pppoe-USER-N>` (digits only). Aggregate:
  live sessions >= Simultaneous-Use -> LIVE; else any ERROR -> ERROR; else SAFE.

## 3. Gate matrix (clean run)

| Gate | Observed | Guard (in-guard ms) | Reply on the wire (request -> reply) | Result |
|---|---|---|---|---|
| A first login, no open row | `n=0;` -> no NAS query, no checkrad | not run | Access-Accept, 106 ms | PASS |
| B healthy duplicate, forced backstop | stock checkrad exit 0 (as shipped) -> guard | **LIVE** (354) | Access-Reject (Reply-Message only), 1002 ms*; NAS max **1** | PASS |
| B normal path (functional stock checkrad) | stock checkrad exit 1 -> stock Access-Reject before post-auth | not run | Access-Reject, 1001 ms*; max 1 | PASS |
| C lost Stop, session gone | stale row OPEN; stock exit 0 -> guard | **SAFE** (308) | Access-Accept, 433 ms; old row **byte-for-byte unchanged**, new row added; no reboot / wait / delete / fake Stop | PASS |
| D accounting lost > 190 s, session live | row age > 190 s | **LIVE** (250) | Access-Reject, 1001 ms*; max 1 | PASS |
| E SNMP unreachable (RouterOS drop udp/161) | | **ERROR:health** (1088) | Access-Reject, 1139 ms*; max 1 | PASS |
| F `snmpget` not executable | | **ERROR:health** (379) | Access-Reject, 1043 ms* | PASS |
| F `snmpbulkwalk` not executable | | **ERROR:walk** (70) | Access-Reject, 1001 ms* | PASS |
| G wrong (lab-only) community | | **ERROR:health** (1102) | Access-Reject, 1162 ms* | PASS |
| H credential entry missing for the NAS | | **ERROR:credentials** (72) | Access-Reject, 1001 ms* | PASS |
| H open row on an UNKNOWN NAS (10.88.0.99) | stock: unknown NAS is trusted as live | not reached | Access-Reject | PASS |
| H verifier input (15 cases) | malformed / leading-zero / IPv6 NAS, empty list, unknown NAS, mixed unknown, bad / non-numeric limit, argc, empty / non-hex / odd-hex / control-char username, `;id` in the list, 17 NAS | all **exit 2, ERROR** | — | PASS |
| I1 NAS B SAFE + NAS A LIVE (real C1) | | LIVE `10.88.0.2=LIVE:1;10.88.0.30=SAFE` | Access-Reject; max 1 | PASS |
| I2 NAS B LIVE + NAS A SAFE | | LIVE | Access-Reject | PASS |
| I3 NAS B ERROR + NAS A SAFE | | ERROR `…10.88.0.30=ERROR:walk-format` | Access-Reject | PASS |
| I4 / I5 all SAFE (RADIUS / real PPPoE login) | | SAFE | Access-Accept; session up | PASS |
| hostile usernames (11 reach the guard) | `'` `"` `` ` `` `;` `$(id)` `${IFS}` `\` `%{User-Password}` `*` leading `-` `&&|>` | LIVE when `<pppoe-NAME>` exists on the NAS -> reject; SAFE when only near-miss names (`NAMEx`, `NAME-1a`) exist -> accept | no injection canary created | PASS |
| hostile with whitespace (3) | refused by the stock `filter_username` before any module | not reached | Access-Reject | PASS |
| Hotspot shared 1 (Simultaneous-Use 1 + Port-Limit 1) | 2nd device: FreeRADIUS Accept, **RouterOS refuses**; guard never runs for Hotspot | — | max 1 | PASS |
| Hotspot shared 2 | 1st, 2nd allowed; 3rd refused by RouterOS | — | max 2 | PASS |
| Hotspot shared 1, accounting lost 190 s | 2nd device refused by RouterOS | — | max 1 | PASS |
| SNMP unreachable with functional stock checkrad (observation) | stock checkrad fails open after ~10 s, then guard ERROR | ERROR (1104) | Access-Reject after **11.3 s** (RouterOS had timed out) | fail-closed, but slow -> production keeps the stock checkrad as shipped (see contract) |

\* Access-Reject timings include the stock FreeRADIUS `reject_delay = 1` s. In-guard time: median 354 ms, max 1104 ms
(TCG-emulated CHR); first login without open rows adds no NAS query.

## 4. Latency vs RouterOS

RouterOS 7.24.5 default `/radius timeout` = **1100 ms** (read from the CHR); the lab uses 3000 ms. Verified answers:
Accept <= 433 ms, Reject <= 1162 ms. Contract: **`/radius timeout` >= 3 s** on every MikroTik NAS using PPPoE with
Simultaneous-Use (with the 1.1 s default an ERROR/LIVE reject - or a slow SAFE verification - can arrive after the NAS
gave up: still never an admission, but a failed login instead of a clean reject).

## 5. Lab incidents during the gate (disclosed; results of affected runs discarded)

1. A second lab agent left running by an agent restart split requests (capture start/stop in different processes) -> run 1
   errors; stale agent stopped, single agent verified.
2. In the disposable FreeRADIUS container the lab start script wrote the "functional checkrad" copy THROUGH the shipped-
   mode symlink, truncating that container's `/usr/sbin/checkrad` -> runs 1-2 discarded, start script fixed (remove the
   link first), stock checkrad hash now asserted in every test, lab recreated, clean run 3 = the evidence. The pinned
   image is unaffected (containers are recreated from it).

## 6. Findings recorded (not fixed)

- **OBS-W8-9** (pre-existing): stock rlm_sql escapes usernames outside `safe_characters` as `=XX` (and `"`/`\` are
  backslash-escaped first). PHPNuxBill writes radcheck/radreply with the RAW username, so a subscriber whose username
  contains such characters can never authenticate (fails safe). Usernames with whitespace are refused by the stock
  `filter_username`.
- Suffix ambiguity (by design, fail-closed): RouterOS names a second session of `bob` `<pppoe-bob-1>`, which is also
  the first interface of a user literally named `bob-1`; the guard counts it for both (a false LIVE -> reject, never
  a false SAFE).
