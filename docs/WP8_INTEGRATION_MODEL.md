# WP8 — Integration model: PHPNuxBill reseller ⇄ FreeRADIUS ⇄ MikroTik RouterOS

| Item | Value |
|---|---|
| Status | **S8 documentation candidate (2026-10-14).** Final development finalization (2026-10-15, owner `S8_REGATE_ACCEPTED — FINAL_DEVELOPMENT_FINALIZATION_AUTHORIZED`): **`WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS`**, **`PRODUCTION_NOT_AUTHORIZED`** (§14). The ledger row stays **PENDING** until the owner records it (REPORT §12). **This document is no production authorization** |
| Code baseline | `wp8/integration-poc@b96747b5f453a74f731624e01d5d5b4e8c53c43c` |
| Decision record | `owner-review/WP8/OWNER_DECISIONS.md` (all stage acceptances), `owner-review/WP8/H2B_OWNER_ACCEPTANCE_RECORD.md` (H-2B) |
| Final report | `owner-review/WP8/REPORT.md`: evidence coverage, blocker register, acceptance assessment |
| Related contracts | `docs/WP4_RADIUS_MODEL.md` (RADIUS attributes, results, accounting time; §13 has the WP8 real-NAS notes), `docs/WP5A_ACTIVATION_MODEL.md`, `docs/WP5B_LEGACY_CLOSURE_MODEL.md`, `install/freeradius/README.md` (operator configuration) |
| Architecture direction | Owner Architecture Lock 2026-10-09 (commit `8b0ee336`). It exists on a local branch only and is **not on `wp8/integration-poc`** (REPORT §6, C-17). Conformance of the final WP8 state: §14 and REPORT §12.2 |

## 0. Evidence labels

| Label | Meaning |
|---|---|
| **[CHR]** | Proven by a documented test on the disposable RouterOS **CHR 7.24.5** lab (QEMU TCG, isolated `10.88.0.0/24`) with **FreeRADIUS 3.2.8** (`read_clients` from `nas`). The evidence path is given. **This is the only label meaning "verified on a real NAS",** and it covers that exact NAS / version / runtime only (lock §8 "PROVEN"). |
| **[CHR-FG]** | Observed only in the CHR **feasibility gate** on RouterOS **7.12.1 / 7.20.3** (`CHR_FEASIBILITY.md`, `evidence/chr-feasibility/`). **Not re-tested on 7.24.5.** |
| **[LAB-FR]** | Proven against the **real lab FreeRADIUS 3.2.8** (SQL client from `nas`) by a **crafted test client** (`nuxr_wp8_app` / `nuxr_wp8_maprobe`). **No RouterOS is involved**, so this is not a NAS result. |
| **[HARNESS]** | Proven by the dual-engine PHPUnit harness: MariaDB 10.11 / MySQL 8.0, PHP 8.2, FreeRADIUS 3.2.8 test containers, and a **stub** NAS for CoA. **Not** a NAS test. |
| **[CONTRACT]** | A deployment or operator requirement or a design rule. It is not a test result. |
| **NOT_VERIFIED** | No sufficient evidence was found. |

**Scope of the lab.**
- Hardware and software versions: RouterOS 7.24.5 only (W8-A2), FreeRADIUS 3.2.8 only, the Linux pppd / curl clients in network namespaces, and one or two CHRs.
- **Not tested:** other RouterOS versions or models, physical radios, IPv6, L2TP / PPTP / SSTP / OVPN, and real subscriber load.

## 1. Components and roles

| Component | Role | Authority |
|---|---|---|
| **PHPNuxBill reseller application** (`system/`) | Commercial and financial domain:<br>• reseller tree (Admin → Agent → sub-agents) and Sales;<br>• customers, plans and pricing;<br>• wallet / ledger / reservations;<br>• activation and reversal state machines;<br>• legacy compatibility paths | Writes the RADIUS **policy** tables only, through `RadiusRepository` / `RadiusProvisioner`. **Never writes `radacct`.** |
| **FreeRADIUS 3.2.8** (`install/freeradius/`, virtual server `phpnuxbill`) | AAA for every NAS:<br>• authorize / authenticate (PAP / CHAP);<br>• expiration and quotas;<br>• admission control (Simultaneous-Use, PPPoE session guard, A2-R reservation, H1-A spelling check, H-2B slots);<br>• accounting into `radacct` | The **only writer of `radacct`** and of the admission tables (`nuxr_admission*`, `nuxr_hs_admission*`). |
| **RADIUS database** (MariaDB / MySQL; `install/radius.sql`, unchanged; plus the additive admission SQL) | `radcheck` / `radreply` / `radusergroup` / `radgroupreply`, `nas`, `radacct`, the admission tables | `radacct` is the authoritative usage and session history. |
| **MikroTik RouterOS NAS** | PPPoE server and Hotspot. It enforces the RADIUS replies:<br>• rate;<br>• address / pool;<br>• Session-Timeout;<br>• Hotspot `Port-Limit`.<br>It also sends accounting and accepts Disconnect / CoA. | Enforcement point only, never a subscriber source of truth (pack `07_MIKROTIK_CONTRACT.md`). |
| **Legacy device drivers** (`system/devices/Mikrotik{Hotspot,Pppoe,Vpn}.php`, `Radius.php`, `RadiusRest.php`) | Compatibility for **unassigned legacy customers** and for the permitted `LegacyReprovisionService` path | Default deny for reseller-owned customers (§9). |

**Production topology [CONTRACT]** (lock §3, Option A):
- **One isolated instance per ISP:** application, Core + RADIUS data, NAS identity, keys and secrets, admin access, logs and backup.
- **NAS links:** each NAS reaches only its own instance's FreeRADIUS, over a private path. Public RADIUS is never used (OD-W8-2).
- **ISO-01** (cross-ISP isolation) is **NOT_VERIFIED** in WP8.

**Lab topology [CHR].**
- **Isolated network:**
  - CHR1 `10.88.0.2`;
  - optional CHR2 `10.88.0.3`;
  - FreeRADIUS `10.88.0.10`;
  - the PHP runner shares the FreeRADIUS network namespace (OD-W8-11);
  - netns clients `wp8c1 …`.
- **No path off the lab:** no WireGuard, no NAT and no public port.
- **Change from the plan:** this replaces the planned WireGuard design (`PLAN.md` §4, superseded by W8-A1; see REPORT §6).
- **Tooling:** `dev/wp8/` (OD-W8-12).

## 2. Network paths and NAS identity

| Path | Rule | Evidence |
|---|---|---|
| NAS → FreeRADIUS, UDP 1812 / 1813 | `/radius src-address` = `nas.nasname` = the NAS-IP-Address seen in `radacct.nasipaddress` (OD-W8-4) | **[CHR]** `nasipaddress 10.88.0.2` = `nasname`; `nas_id 1` matched on every Disconnect (`evidence/chr-feasibility/coa.txt`, `evidence/s2|s3/lab-evidence.jsonl`).<br>A NAS behind NAT, or `src-address=0.0.0.0` with several addresses: **NOT_VERIFIED**. NAT is excluded by design. |
| FreeRADIUS client list | `read_clients = yes`, `client_table = nas` (OD-W8-7). `nas.secret` serves both RADIUS and CoA, and is plaintext at rest (WP4 Q10) | **[CHR]** `evidence/s1/freeradius-startup.txt` |
| Application → NAS, Disconnect / CoA (UDP) | **The source IP must be a configured RouterOS `/radius` server address** (OD-W8-11). The production rule: the application runs co-located with FreeRADIUS on the NAS-visible address. No SNAT, and no second generic `/radius` entry. | **[CHR]** From `10.88.0.10` (a `/radius` server) → answered on 7.24.5 (S3).<br>**[CHR-FG]** From `10.88.0.20` (not a `/radius` server) → silently ignored on **7.12.1**: `UNKNOWN COA_NO_RESPONSE` after 3 sends (6.0 s) (`evidence/chr-feasibility/coa.txt` probe 2). **The wrong-source case is NOT_VERIFIED on 7.24.5**; the rule stays binding as an owner contract (OD-W8-11). |
| CoA port | `nas.ports` = the CoA / Disconnect destination port; empty means **3799**. RouterOS `/radius incoming accept=yes port=3799` (OD-W8-3, Option A, no UI change). | **[CHR]** port 3799 (`evidence/s1-ma-policy/chr-7.24.5-smoke.txt`). The RouterOS factory default port **1700** is from the vendor documentation only and was never exercised: **NOT_VERIFIED**. |
| RouterOS API, TCP 8728 | Only inside the isolated lab or a private path. No public API. API-SSL deferred (OD-W8-5). Used only by the legacy drivers and lab tooling, never by the reseller activation path. | **[CHR]** S7 DC. OBS-W8-4: no TLS and no driver timeout (60 s to fail). |
| FreeRADIUS → NAS, SNMP (read-only) | Used by the PPPoE session guard (A1) and the H-2B slot verifier:<br>• RouterOS SNMP read-only, filtered to the FreeRADIUS address;<br>• per-instance credential file (`${confdir}/nuxr/nas_snmp`, 0600);<br>• NAS row type `mikrotik_snmp`. | **[CHR]** `evidence/od-w8-16-option-g-gate/`, `evidence/a1-permanent-option-g/`, `evidence/h2b-impl/` |

## 3. Authentication (AAA)

| Topic | Contract | Evidence |
|---|---|---|
| Message-Authenticator | The `authorize` section starts with `if (!&Message-Authenticator) { do_not_respond }` (OD-W8-13). FreeRADIUS 3.2.8 does **not** apply the global `require_message_authenticator = yes` to SQL-loaded clients.<br>NAS firmware floor: **RouterOS ≥ 7.15**. 7.12.1 sends no Message-Authenticator and is silently dropped. | **[LAB-FR]:**<br>• the global `yes` is not applied to SQL clients (probe modes A / B / C, `evidence/s1-ma-policy/`);<br>• MA-1 missing → no reply; MA-2 wrong HMAC → dropped; MA-3 valid → Accept / Reject; accounting without MA is answered (`FreeRadiusSqlClientTest`, crafted client, `evidence/s1/`).<br>**[CHR]:** RouterOS 7.24.5 sends Message-Authenticator in every Access-Request; its accounting carries none and is answered (`RouterOsClosureTest`, `evidence/s1/lab-evidence.jsonl`).<br>**[CHR-FG]:** 7.12.1 sends none and is dropped; 7.20.3 sends it (`evidence/chr-feasibility/radius-packets-summary.txt`).<br>**The firmware floor ≥ 7.15 is an owner contract (OD-W8-10 / 13), not a test result.** |
| PPPoE | CHAP through `/ppp aaa use-radius=yes accounting=yes interim-update=1m` | **[CHR]** `RealAaaTest` (S2; raw re-runs: `evidence/av5/phpunit-summaries.txt` RealAaa ×3 OK 7 / 79, rc=0; `evidence/s7/fc/focused-router-regression-run2.out` OK, exit 0) |
| Hotspot | Server profile `use-radius`, accounting, interim 1 m, `login-by=http-chap`. The NAT pool is not shared with the DHCP pool (`address-pool=none`; S2 lab correction). | **[CHR]** http-chap (S2), http-pap (feasibility gate), cookie and MAC modes (H1-A, `evidence/h1a/`) |
| RADIUS key | PPPoE: `pppoe_username` when set, else `username`. Hotspot / VPN: `username` (WP4 Q7). | **[HARNESS]** WP4; **[CHR]** S2 / S7 |
| Attribute effects on the NAS | **Mikrotik-Rate-Limit `rx/tx`:** rx = client **upload**, tx = client **download**. Dynamic queue `256000/512000`; measured 253 kbps up and 505 kbps down.<br>**Framed-Pool** → address from the named pool.<br>**Framed-IP-Address** → exact static address.<br>**Never both.**<br>**A pool missing on the NAS** → Access-Accept but no IPv4 (RADIUS cannot detect it). | **[CHR]** `evidence/s2/lab-evidence.jsonl` lines 1, 2, 4 |
| Expiration | Written as `Expiration` in the application time zone. The FreeRADIUS process `TZ` must equal the application zone (WP4 residual 4). RouterOS gets `Session-Timeout` capped at the expiry, sends Stop `Session-Timeout`, and re-authentication is rejected. | **[CHR]** S2 (Session-Timeout 66 s for +70 s), S5 EXPIRY (Stop at 118 s, then Access-Reject) |
| Hotspot `Port-Limit` | Hotspot only: `max(1, shared_users)` (A1). **RouterOS enforces it per NAS on Hotspot but ignores it on PPPoE.** PPPoE never relies on it. | **[CHR]** `evidence/od-w8-16-gate/GATE_RESULTS.md`; A1 |
| Quotas (`Max-All-Session`, `Max-Data`, period counters) | Hotspot "Limited" plans only, per billing period (WP4 §4) | **[LAB-FR] only:** S1 ran crafted packets from a test client (`FreeRadiusSqlClientTest`) against the lab FreeRADIUS, plus the WP4 harness. **Quota behaviour on a NAS is NOT_VERIFIED** (lock gates Q1–Q4 open). |
| Usernames outside `safe_characters` | Stock rlm_sql escapes them (`=XX`) while PHPNuxBill stores the raw name: such a subscriber cannot authenticate (fails safe). Whitespace names are refused by `filter_username`. | **[CHR]** OBS-W8-9 (option G gate, hostile usernames) |

## 4. Admission control (who may hold a session)

The layers run in this order. Each one fails **closed**: an error refuses the login and never grants it.

| Layer | Applies to | Mechanism | Proven | Known limits |
|---|---|---|---|---|
| Stock `Simultaneous-Use` (`session { sql }`) | PPPoE and other non-Hotspot requests | Counts open `radacct` rows. The stock checkrad **fails open** (Option F, rejected). | **[CHR]** second PPPoE session rejected (S2) | Alone it gives a lost-Stop lockout (S6 drill 7) and can fail open (Gate D) |
| **PPPoE session guard** (A1, OD-W8-16 Option G) | PPPoE (`Framed-User` + `PPP` + `Ethernet`) | `post-auth` exec guard. It asks every NAS by read-only SNMP whether a session of that name is live. Tri-state: **SAFE** → Accept; **LIVE / ERROR** → Reject. `delete_stale_sessions = no`. | **[CHR]** gates A–I; a lost Stop with SAFE gives an Accept without any repair (`FailureDrillsTest::testDrill7GuardLost…`); hostile usernames (`evidence/od-w8-16-option-g-gate/`, `evidence/a1-permanent-option-g/`) | **Contract:** `/radius timeout ≥ 3s` and every NAS of type `mikrotik_snmp`. Other PPP services (L2TP / PPTP / SSTP / OVPN) are not covered. The `bob` / `bob-1` suffix gives a false LIVE, never a false SAFE. |
| **A2-R admission reservation** | PPPoE | One atomic row per canonical subscriber (`nuxr_admission.sql`), claimed as the last step before the Access-Accept, with a per-attempt `Class` token `nuxr1`. **Strict Mode:** a `PENDING` claim never expires by time or SNMP. Late-claim budget 5000 ms. | **[CHR]** 48 race trials with 0 double sessions; cross-NAS 13 / 13; S-1 spellings; AV-4 (3.5 / 4.5 s admitted, 5.5 / 6.5 s refused) (`evidence/a2r-*`) | **AV-1:** a whole-process FreeRADIUS stall longer than ~9.3 s leaves an orphan `PENDING`, and the subscriber is refused until an audited operator release (no executable recovery yet). AV-2 sizing; AV-3 operator identity. **A2-R SQL exhaustion: `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`** (reproduction halted by the owner; never retried). |
| **H1-A exact Hotspot identity** | Hotspot (`Login-User` + `Wireless-802.11`) | Admits only when the raw `User-Name` bytes equal the one stored `radcheck.username`; equivalent spellings are refused | **[CHR]** no session above `shared_users` (`evidence/h1a/`, `evidence/av5/`) | **MAC-H1A:** a lower-case stored MAC with `mac-as-username` is now refused |
| **H-2B Hotspot admission slots** | Hotspot, across **all** NAS of the ISP | Slots `1..Simultaneous-Use` (`nuxr_hs_admission.sql`), Class token `nuxh1`, Strict PENDING, release of an `ACTIVE` slot only on SNMP proof. The stock Simultaneous-Use check is bypassed for Hotspot only. | **[CHR]** CHR1 + CHR2: gc1 12 / 12, gc2 6 / 6, gc3 5 / 5 enforced; before the change 28 / 29 multi-NAS trials exceeded the limit (`evidence/h2/`, `evidence/h2b-impl/`) | **H-2 follow-ups:** PENDING recovery, impact queries, sizing, per-NAS contract, monitoring, rollout order (live sessions hold no slot at enable time) |
| VPN | VPN plans | Stock path only | **V-1 (VPN username equivalence) NOT_VERIFIED** | — |

**Timing contract [CONTRACT].**
- RouterOS `/radius timeout=3s`: 3 transmissions, abandoned after about 9.3 s.
- The 5000 ms late-claim budget covers the guard path only under this timeout.
- The guard's own time in the lab: median 188–354 ms, maximum about 1.1 s.

## 5. Accounting

| Topic | Contract / observation | Evidence |
|---|---|---|
| Authority | FreeRADIUS writes `radacct`; PHPNuxBill reads it only (`AccountingService`). No lifecycle path deletes or rewrites it. One row per session. | **[HARNESS]** WP4 C17 guards. **[CHR]** the S6 drill 7 history was kept (`NAS-Reboot` close, no delete); S7 FC tests: RADIUS tables byte-identical after driver refusals; A3 C1–C6 |
| Records | Start / Interim (1 m) / Stop. `acctsessionid` = the NAS session id without `0x`. PPPoE `nasportid ether3` / `Ethernet`; Hotspot `Wireless-802.11`. Terminate causes `User-Request`, `NAS-Request` (PPPoE Disconnect), `Admin-Reset` (Hotspot Disconnect), `Session-Timeout`, `NAS-Reboot`. | **[CHR]** `evidence/s2`, `evidence/s3` |
| Event-Timestamp | Present in Start / Interim / Stop (the NAS clock), absent in Accounting-On (the FreeRADIUS clock `%l`) | **[CHR]** PPPoE only (A4). Hotspot and other NAS models: **NOT_VERIFIED** (P-A4-2) |
| Accounting-On | RouterOS sends it before its first record. The stock query closes that NAS's open rows (`NAS-Reboot`). OBS-W8-7: one Accounting-On got no response (one timeout); a later closure record reports 0 unanswered, so this is ambiguous. | **[CHR]** S1, S6 |
| Lost Stop | **RouterOS does not resend a lost Stop.** The row stays open. Admission is then decided by the session guard (PPPoE) or the slots (Hotspot), never by row age. | **[CHR]** S3, S6 drill 7, A1 |
| Time contract (A4) | Read as epochs (`UNIX_TIMESTAMP`). **The SQL session zone must be the same (UTC) for writer and reader.** NTP everywhere. Stale = report only; `CLOCK_TOLERANCE_SECONDS = 300`. | **[HARNESS]** both engines; **[CHR]** `AccountingTimeLabTest` (A4; S7 focused regression OK). Production: **P-A4-1…3** |
| `rad_acct` (rlm_rest mode) | Local counter state, not history (WP4 Q9). The rlm_rest mode is **out of WP8 scope** (L-4). | **[HARNESS]** only |

## 6. Disconnect / CoA and the session lifecycle

| Situation | Result in the application (`CoAClient`; result mapping unchanged since WP4, A3 added `disconnectSession()` for one captured session) | Evidence |
|---|---|---|
| Live session, Disconnect or CoA accepted | `SUCCESS COA_ACK`. One packet, no retransmission. | **[CHR]** S3 |
| No matching session (stale row, unknown user, wrong id, or no identifying attribute) | RouterOS 7.24.5 answers **NAK Error-Cause 406, never 503**. That maps to `FAILED COA_NAK (406)`; this is the accepted mapping (OD-W8-14 = Option A). On MikroTik a vanished session therefore shows as `FAILED COA_NAK (406)`, not `SESSION_NOT_FOUND`. | **[CHR]** S3 (`evidence/s3/lab-evidence.jsonl` line 6) |
| No answer (incoming CoA disabled or blocked, NAS down, answer lost; wrong source address) | `UNKNOWN COA_NO_RESPONSE` after 3 identical packets about 2 s apart. Never retryable, never upgraded to SUCCESS, and never a rollback of provisioning. | **[CHR]** S3, S6 drills 4, 5, 9; the wrong-source case is **[CHR-FG]** only (7.12.1) |
| Rate change | A CoA with `Mikrotik-Rate-Limit` is applied **live** (queue `256000/512000` → `128000/1000000` in the same session) | **[CHR]** S3 |
| Address / pool change | **A CoA-ACK proves nothing:** RouterOS ACKs and ignores `Framed-Pool` / `Framed-IP-Address`. Binding rule: **policy → Disconnect → re-authentication.** | **[CHR]** S3; OWNER_DECISIONS "binding S3 rules" |

**Commercial lifecycle as seen on the NAS** (S5, `RealLifecycleTest`, PPPoE):

| Action | Network effect | Evidence |
|---|---|---|
| NEW | Policy written, then the session authenticates. **The service starts before settlement** (at `FINANCIAL_COMMITTING` the session is up, the reservation ACTIVE, and no ledger leg exists yet). | **[CHR]** S4 |
| RENEW | `Expiration` rewritten, **no disconnect**. The live session keeps its auth-time Session-Timeout until it re-authenticates (lock gate P2, O-R05). | **[CHR]** S5 |
| CHANGE_PLAN | Policy update → Disconnect → re-authentication with the new rate / pool. If the Disconnect is UNKNOWN, the **old session keeps the old policy** (S6 drill 4; lock gate P1, O-R04). | **[CHR]** S5, S6 |
| EXPIRY | NAS Session-Timeout → Stop → the next authentication is rejected. The canonical cron expiry is allowed for reseller-owned customers. | **[CHR]** S5, S7 FC-7 |
| REACTIVATE | Like NEW, with settlement | **[CHR]** S5 |
| Reversal (WP5B `ActivationReversalService`) | Removes or suspends the service through the canonical path | **[HARNESS]** WP5B only. **A reversal on the CHR is NOT_VERIFIED in WP8.** |
| Rename / re-provision (A3) | Exact-byte rename. The old identity's sessions are captured and disconnected after the tree lock, with a ≤ 6 s Stop poll and an `old_session` audit. An ambiguous or taken target is refused before any write. | **[CHR]** A3 C1–C6 on CHR1 + CHR2 (`evidence/a3/`); S7 focused regression |

## 7. Commercial and financial integration

**Order [HARNESS] + [CHR]:**
1. Reserve funds.
2. Provision RADIUS outside any DB transaction.
3. Settle.

For the outcomes:
- **UNKNOWN provisioning** → `RECONCILIATION_REQUIRED` with funds held; never a blind retry.
- **FAILED** → the reservation is released and no ledger leg is written.

S6 drills 2–3 on the CHR:
- `RADIUS_DB_ERROR` → FAILED / RELEASED;
- `radius.commit_lost` → `RECONCILIATION_REQUIRED`;
- reconcile → exactly one settlement; a second reconcile → `ACTIVATION_STATE_CONFLICT`.

**Conservation [CHR]**, from the original ledger rows:
- S4: `L = Σ commissions + P` → 17.00 = (3.00 + 2.00) + 12.00. Wallets equal their ledger sums; an idempotent replay is byte-identical.
- S5 CHANGE_PLAN (no proration): 30.00 = 6.00 + 24.00.
- S5 RENEW and REACTIVATE: 17 = 5 + 12.

**Roles [CHR]** (service level, S4):
- Admin funds the root Agent; parent → child transfers (A 700 / B 200 / C 100).
- Sales acts only on assigned customers. Out of scope → `NOT_FOUND` with no side effect. Sales sees no wholesale price or wallet values.
- **HTTP / API-level redaction is not re-verified in WP8.**
- **Out of WP8 scope:** Hotspot / VPN business flows, concurrent finance, and the payment-gateway paths (NOT_VERIFIED in WP8).
- Reseller auto-renew is closed by design (WP5B E11 `AUTO_RENEW_UNAVAILABLE`).

## 8. Legacy paths for reseller-owned customers

| Path | Protection | Evidence |
|---|---|---|
| Legacy grant funnel `Package::rechargeUser` (web, API, cron, voucher, balance) | WP2 `ServiceGate` at the top (owned customer, owned voucher username). **S7-FC:** for a non-voucher grant, the device default deny runs **before the recharge cycle**: no hook, recharge row, transaction / invoice, device call or notification, and `false` is returned. The three callers' existing (unchanged) code treats `false` as "not granted": admin error, exactly-once refund, voucher release. That handling is **code-read only** (S7 report §16.1), not exercised by a WP8 test. | **[CHR]** FC-4, FC-2c (the funnel itself returns `false` and writes nothing); **[HARNESS]** `RechargePreWriteRefusalTest` (24 cases, both engines) |
| Legacy removal (`plan.php` delete, `customers.php` deactivate, portal deactivate) | A5 `ServiceGate::guardCustomer` before any hook, device call or write. D-A5-1: no fallback to the session customer. | **[HARNESS]** `LegacyRemovalGateTest`; **[CHR]** FC-6: refused, and the live local session survives |
| Direct driver grants (add / sync / rename) | `assertDeviceAllowed` first in every grant method of the five drivers (§9) | **[CHR]** FC-1, FC-2 |
| Plugins, hooks, direct DB or device writes | **Not covered** (Phase B / WP9, lock R-015) | NOT_VERIFIED |

## 9. Identity ownership and the device guard

- **Ownership:** `tbl_customers.manager_id` set = reseller-owned. Unassigned (NULL) = legacy customer.
- **Device default deny** (`LegacyReprovisionService::assertDeviceAllowed`, the first statement of `add_customer` / `sync_customer` / `change_username` in `Radius`, `RadiusRest`, `MikrotikHotspot`, `MikrotikPppoe` and `MikrotikVpn`):
  - **Names judged:** the row's `username` **and its `pppoe_username`** (S7-F1), plus the explicit rename names.
  - **The check:** each name is compared with the `username` **or** `pppoe_username` of every owned customer, by database comparison. So case, trailing space and accent variants (utf8mb4_general_ci) are the same name.
  - **On a match:** `RESELLER_OWNED_REFUSED`, non-retryable, recorded once (`op=deviceGrant`).
  - **On a lookup error:** fails closed.
- **The only permitted bypass:** the private per-call **permit** of `LegacyReprovisionService::run()`. It covers only that customer id and the old and new names of the run, and ends after both success and failure. **[CHR]** FC-3, FC-3b; **[HARNESS]** WP5B and `DeviceGuardKeyCollisionTest`.
- **What S7-F1 closed:** an unassigned row carrying an owned key as its PPPoE username could do the following; all of these are now refused.
  - **[CHR]** (exact key, upper case, trailing space): overwrite the owned customer's router secret, disconnect its live session, or mask an owned RADIUS subscriber with a local secret.
  - **[HARNESS]** (both engines, in-process `DeviceGuardKeyCollisionTest`): on the legacy Radius device, overwrite its RADIUS policy; the accent variant.
- **What remains (production blocker OBS-1 / L-A5-2):** customer **creation** still allows colliding identities. A collider is now refused with no write, but it cannot be served, and two colliding **unassigned** customers can still overwrite each other (upstream behaviour). Creation-time uniqueness needs a separate decision.
- **WP9 U0 (2026-10-15, development only, branch `wp9/red-team-rc`; added note):** every application writer that **acquires** a RADIUS-relevant name now does so under one shared identity lock (`CustomerIdentity`, a per-database named lock taken outside any transaction, after the tree / voucher-username locks): customer creation (CustomerService, public registration, voucher with a new username), legacy and reseller-owned identity edits (incl. the re-provision revert) and the voucher-only redemption, whose claim is the durable reservation of the code (the grant stays outside the lock). A name is refused when its canonical RADIUS form (PHP `trim()` + `utf8mb4_general_ci`) equals another customer's username / PPPoE username, a live voucher identity, or a RADIUS key that still holds policy rows. Not covered: plugins / direct DB writes and pre-existing (grandfathered) duplicates (read-only survey + operator plan, or a uniqueness decision U1–U4). OBS-1 stays a **production blocker** until the owner accepts this evidence; details: `owner-review/WP9/U0_DESIGN.md`, `REPORT.md` §U0.
- **WP9 final hardening (2026-10-16, development only; added note):** a subscriber's RADIUS login policy is no longer written, suspended, removed or renamed while its canonical key has more than one logical owner (grandfathered duplicates); the reseller activation of such a key ends FAILED with the reservation released, legacy grants are refused before any write, and the other owner keeps its policy and session. This supersedes the WP8 A3 behaviour of renaming a key another customer also holds (now refused; owner confirmation pending). Existing duplicates must be resolved by an operator plan (`dev/wp9/identity_survey.sql`, read-only) before production; details `owner-review/WP9/REPORT.md` §FH.
- **OBS-S7-4** (Phase B): whitespace-normalised keys (`RadiusPolicyBuilder::key()` trims) are outside the database comparison. Product inputs cannot store them.

## 10. Local MikroTik users vs RADIUS subscribers

- **Masking:** RouterOS consults RADIUS **only when no local user matches**. A local `/ppp secret` or `/ip hotspot user` with a subscriber's name therefore masks that subscriber's RADIUS identity.
- **Proof [CHR]:**
  - S7 SEP: local logins never reach FreeRADIUS, and a RADIUS subscriber gets exactly one new accounting row of its own.
  - FC-2d, before S7-F1: a local secret created for a collider broke the owned subscriber's RADIUS login, and FreeRADIUS was never asked.
- **Rule [CONTRACT]:**
  - A NAS that serves RADIUS subscribers must hold **no local user with a RADIUS subscriber's name**.
  - The reseller activation path never creates local users. Class A is empty: `ActivationService` requires `device = 'Radius'` (S7 T-S1).
  - Legacy MikroTik-API plans are for unassigned legacy customers only.
- **Legacy driver findings (they gate legacy-driver use, not the RADIUS reseller path):**
  - OBS-S7-1: `!trap` replies are ignored, so a rejected grant is reported as success.
  - OBS-S7-2: VPN NAT removal is by address, and renames leave stale comments.
  - OBS-W8-2 … 5.

## 11. Failure, recovery and UNKNOWN

| Failure | Outcome | Evidence |
|---|---|---|
| FreeRADIUS paused before provisioning | The activation still COMPLETES (the policy is written to the RADIUS DB) but no service is given until FreeRADIUS returns. **Different from the plan's expectation** (`PLAN.md` §24 #1). | **[CHR]** S6 drill 1 |
| RADIUS DB error before COMMIT | FAILED, reservation RELEASED, no ledger. A transient 2006 error → retry → completed once. | **[CHR]** S6 drill 2 |
| COMMIT acknowledgement lost | `RECONCILIATION_REQUIRED`, funds held; reconcile → one settlement | **[CHR]** S6 drill 3 |
| NAS unreachable / CoA blocked / answer lost | `UNKNOWN COA_NO_RESPONSE`; the old session may continue; the activation is still COMPLETED | **[CHR]** S6 drills 4, 5, 9 |
| RADIUS auth timeout | No session; application state byte-identical | **[CHR]** S6 drill 6 |
| Lost accounting Stop | PPPoE: the session guard decides by SNMP (SAFE → Accept). Type `other` NAS: lockout until Accounting-On (the S6 baseline, kept as characterisation). | **[CHR]** S6 drill 7, A1 |
| RouterOS API down (legacy drivers) | PEAR2 exception after the socket timeout (8–60 s). The activation path is unaffected. `LegacyReprovisionService` → `REPROVISION_UNCONFIRMED`, with no revert and no retry (OBS-W8-5). | **[CHR]** S6 drill 8, S7 DC-X1, FC-3 |
| FreeRADIUS whole-process stall longer than ~9.3 s | Orphan A2-R / H-2B `PENDING`: the subscriber is refused until an audited operator release (AV-1, Strict Mode) | **[CHR]** A2-R Drill 1&6. **No executable recovery yet.** |
| Same RADIUS key on two Radius plans | ADMIN_SYNC / rename → `REPROVISION_UNCONFIRMED` (pre-existing) | **[HARNESS]** A3 |
| Ordinary legacy device failure after the authority check | Billed, "sync manually" (WP4 order, **L-S7-FC-1**) | **[HARNESS]** S7-FC test 2 |

Tri-state rule: **UNKNOWN is never collapsed into SUCCESS or FAILED** (lock R-019). Reconciliation of **CoA** UNKNOWNs is not implemented; they stay annotations (S6 drill 10).

## 12. Required configuration before any production use [CONTRACT]

These are deployment requirements. The full open-item list is in REPORT §5.

1. **NAS firmware and versions:**
   - RouterOS ≥ 7.15 (Message-Authenticator);
   - validated **only on 7.24.5**;
   - FreeRADIUS validated **only on 3.2.8**.
2. **RADIUS client settings on each NAS:**
   - `/radius timeout=3s` and `require-message-auth=yes-for-request-resp`;
   - `/radius incoming accept=yes port=<nas.ports | 3799>`;
   - a unique `src-address` equal to `nas.nasname` (no NAT).
3. **CoA and SNMP:**
   - CoA from the FreeRADIUS-co-located application address only;
   - SNMP read-only from FreeRADIUS, with a credential entry per NAS;
   - NAS type `mikrotik_snmp` for every PPPoE / Hotspot NAS.
4. **Accounting on the NAS:**
   - PPP AAA `use-radius`, accounting, interim 1 m;
   - the Hotspot profile with `use-radius`, accounting, interim, `http-chap`, and a NAT pool not shared with DHCP.
5. **No local users** that collide with RADIUS subscriber names.
6. **FreeRADIUS installation:**
   - the project site with Message-Authenticator enforcement;
   - `delete_stale_sessions = no`;
   - the session guard, `nuxr_admission`, `nuxr_hs_admission` and the readiness checks;
   - SQL `pool.max ≥ max_servers`, and DB `max_connections` sized for that (sizing is lab-only, AV-2).
7. **Time:**
   - RADIUS DB SQL **session** zone UTC on both the writer and the reader;
   - NTP on every NAS and host;
   - FreeRADIUS process `TZ` = the application zone (P-A4-1, P-A4-2, residual 4).
8. **Before enabling:**
   - the existing `radacct` history survey (P-A4-3);
   - the Hotspot rollout order (H-2B: live sessions hold no slot);
   - a Port-Limit rollout for Hotspot subscribers provisioned before A1;
   - the read-only production impact queries (MAC-H1A, H-2B §4).
9. **Database:** `REPEATABLE READ`, the deployment default; READ COMMITTED interleavings are not fully proven.
10. **Operations:**
    - an operator runbook for `PENDING` releases (none executable yet), `REPROVISION_UNCONFIRMED`, `RECONCILIATION_REQUIRED` and "sync manually";
    - monitoring of `nuxr_*_report.sql` and of the guard / verifier health (lock R-021).

## 13. Not covered by WP8

- IPv6;
- L2TP / PPTP / SSTP / OVPN;
- RADIUS-backed VPN identity (V-1);
- quotas on a real NAS (Q1–Q4);
- the rlm_rest mode (L-4);
- other RouterOS versions or physical hardware;
- load and capacity (REL-04);
- backup / restore (REL-01);
- multi-ISP isolation (ISO-01);
- HA (REL-06);
- plugin / direct-DB authority (Phase B / WP9);
- production data.

## 14. Final development state and Architecture Lock conformance (2026-10-15, added)

Owner decision `S8_REGATE_ACCEPTED — FINAL_DEVELOPMENT_FINALIZATION_AUTHORIZED` on
`wp8/integration-poc@b96747b5f453a74f731624e01d5d5b4e8c53c43c`. **This is a development acceptance, not production
readiness.** State: **`WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS`**, **`PRODUCTION_NOT_AUTHORIZED`**.

- **Final re-gate condition: met.** Production-contract lab (RouterOS CHR 7.24.5 ×2, FreeRADIUS 3.2.8 threaded):
  `wp8-security-gates` 10 / 216, exit 0 (2026-10-14); full `wp8-integration` 101 / 1849, 0 failures, errors, warnings and
  deprecations, 13 mode-gated skips (not passes), exit 0 after the test-only drill 8 autoloader fix (REPORT §10–§11).
- **Operator README:** `install/freeradius/README.md` "Disconnect / CoA" now carries the CoA port and source notes (D-S8-5),
  with the 1700 default marked as vendor documentation and the wrong-source case as RouterOS 7.12.1 only.
- **Architecture Lock (D-S8-4), documented only:** the lock is not merged or copied. The final WP8 state conforms to the ten
  axes and to Option A as far as WP8 evidence reaches. The axis-by-axis table and the release-gate status are in REPORT
  §12.2 and §7.3; every lock release gate that WP8 did not prove stays **PENDING**.
- **Unchanged:** the production blocker register (REPORT §5, 31 rows), the required configuration (§12) and the areas not
  covered (§13). CoA wrong-source stays **[CHR-FG]** (7.12.1) only. The A2-R SQL-exhaustion risk stays an unverified
  production blocker; its reproduction is halted and was not run.
