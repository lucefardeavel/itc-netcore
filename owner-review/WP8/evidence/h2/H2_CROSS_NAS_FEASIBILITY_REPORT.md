# WP8 H-2 Cross-NAS Hotspot — Feasibility & Security Evidence Report

Date: 2026-10-11. Lab feasibility only. Baseline `wp8/integration-poc@1537775555b3a940e32c8103df4c98bb0549ddf1`.
- **Lab:** two RouterOS CHR 7.24.5 under QEMU; FreeRADIUS 3.2.8 threaded with the PROJECT site; the production NAS
  contract `option_g_gate.py on shipped`.
- **Not done:** no product or policy change, no fix, no commit / push.

**Owner criterion (2026-10-11):** `shared_users` is the TOTAL number of concurrent sessions of one subscriber across
ALL NAS of the ISP.

**Verdict: `H2_GAP_CONFIRMED`.**

With the production NAS contract:
- one Hotspot subscriber holds **`shared_users` sessions on each NAS**;
- `shared_users = 1` gave **2** live sessions on two NAS in **every** trial (sequential, reversed, concurrent);
- `shared_users = 2` gave **3 and 4** live sessions;
- the cross-NAS check exists, but its live-session verification can never see a Hotspot session.

## 1. Contract found in the code and documents vs the owner criterion

| Source | What it says | Difference from the criterion |
|---|---|---|
| `system/autoload/Reseller/Radius/RadiusPolicyBuilder.php` | Hotspot: `Simultaneous-Use := shared_users` (radcheck) and `Port-Limit := shared_users` (radreply) | It intends a limit, but does not say per NAS or global |
| `docs/WP4_RADIUS_MODEL.md` | `Simultaneous-Use` Hotspot = `shared_users`; `Port-Limit` Hotspot only, "RouterOS enforces it at the NAS" | `Port-Limit` is by definition per NAS |
| `install/freeradius/README.md` (Hotspot, "Not covered") | "`Port-Limit` is enforced per NAS by RouterOS: whether `shared_users` holds across several NAS of one ISP is open (WP8 H-2, unverified)" | Explicitly open |
| FreeRADIUS `session { sql }` (stock) | counts open `radacct` rows of the user on ALL NAS; when the count reaches `Simultaneous-Use`, it asks `checkrad` per row whether the session is still live | The only cross-NAS layer; its verification decides |
| stock `checkrad` `mikrotik_snmp` (FreeRADIUS 3.2.8) | walks `mtxrInterfaceStatsName` for an interface named `<…-USER>` (PPPoE dynamic interfaces); a Hotspot session has no such interface | **It can never see a live Hotspot session**: "not logged in" |
| Production NAS contract (README, A1) | NAS type `mikrotik_snmp`; `delete_stale_sessions = no`; the stock checkrad as shipped (no SNMP program paths) | As shipped it cannot even run SNMP, and answers at once |

**Conclusion:** the code expresses one limit, but the effective contract today is **per NAS** (RouterOS `Port-Limit`),
with an FreeRADIUS cross-NAS check that fails open for Hotspot. That differs from the owner criterion.

## 2. Lab set-up and pre-checks

**CHR1** (as before): Hotspot `nuxr_wp8_hs` on ether4, 10.88.2.0/24, clients `c2` / `c4` / `c5`.

**CHR2** (added by `dev/wp8/h2_gate.py setup`, lab only):
- Hotspot `nuxr_wp8_hs` on ether3, 10.88.12.0/24, next to its PPPoE server;
- **the same profile as CHR1** (http-chap only, use-radius, radius-accounting, interim 1 m; local default
  shared-users 10, so RADIUS governs);
- RouterOS RADIUS `service=ppp,hotspot`;
- two new client namespaces `wp8c7` / `wp8c8` with explicit MACs `02:48:32:00:00:07` / `:08`;
- the NAS row from `a2r_gate.py chr2 setup` (nas 4, `mikrotik_snmp`, SNMP community from 10.88.0.10 only).

**`H2.precheck` (shipped mode): pass.**
- both NAS rows are `mikrotik_snmp`, and both FreeRADIUS clients are loaded;
- both routers have `service=ppp,hotspot` and the same Hotspot profile;
- the 5 client MACs are unique; the CHR NIC MACs are disjoint;
- 0 live sessions, 0 open `nuxr_h2_*` rows; site = project.

The functional-mode precheck shows `no_open_rows=false`: the 2 open rows are the deliberate stale rows of the
`H2.lost_stop` users. Every case uses a new subscriber; this is recorded in a correction / note record.

Each trial:
- logs out every client, clears the active and host tables of both NAS, and re-runs DHCP;
- logs in through the real Hotspot login page (http-chap);
- samples `/ip/hotspot/active` on both NAS every 0.5 s for 8–10 s;
- reads `radacct` and the FreeRADIUS debug decisions plus the checkrad trace (type, NAS, user, session, exit, ms).

## 3. Results (`lab-evidence.jsonl`, 22 records)

**Production contract (`shipped`: NAS type `mikrotik_snmp`, stock checkrad as shipped):**

| Case | Trials | Result | Max live (CHR1 + CHR2) |
|---|---|---|---|
| Control: same NAS, limit 1 (CHR1 c2→c4; CHR2 c7→c8) | 2 | **ENFORCED** by RouterOS `Port-Limit` | 1 |
| `shared_users=1` CHR1 → CHR2 (Start confirmed in radacct) | 3 | **EXCEEDED 3 / 3** | 1 + 1 = 2 |
| `shared_users=1` CHR2 → CHR1 | 3 | **EXCEEDED 3 / 3** | 2 |
| `shared_users=1` concurrent | 10 | **EXCEEDED 10 / 10** | 2 |
| `shared_users=2`: 1+1 then +1 on either NAS; 2 on CHR1 then +1 / +2 on CHR2 | 4 | **EXCEEDED 4 / 4** | 3, 3, 3, 4 |
| `shared_users=2` concurrent 2+2 | 5 | **EXCEEDED 5 / 5** | 4 |
| Accounting-Start lost (CHR1, then CHR2 direction) | 2 | **EXCEEDED 2 / 2** | 2 |
| Late accounting (CHR1 Start held 20 s, CHR2 login in the window) | 1 | **EXCEEDED**; the held Start was **never** resent after release, so the CHR1 session never appeared in radacct; a third login on CHR2 was refused by CHR2's own `Port-Limit` | 2 |
| Lost Stop (CHR1 session gone, row stale), then CHR2 | 1 | admitted, stale row ignored (correct for the subscriber) | 1 |

**SNMP / verification variants:**

| Mode | Case | Trials | Result |
|---|---|---|---|
| `functional` (checkrad with SNMP program paths + naspasswd, working SNMP) | CHR1→CHR2, CHR2→CHR1 | 6 | **EXCEEDED 6 / 6**: real SNMP still finds no `<…-user>` interface for Hotspot (checkrad exit 0, 0.5 s) |
| `functional`, SNMP to CHR1 **dropped** | CHR1→CHR2 | 2 | auto-labelled ENFORCED, **corrected (see §4)**: FreeRADIUS **fail-open Accept** after 10 s; the NAS had already timed out ("RADIUS server is not responding") → availability failure, not enforcement |
| `functional` | lost Stop | 1 | admitted, stale row ignored |
| NAS type `other` (FreeRADIUS trusts every open row, no NAS query) | CHR1→CHR2, CHR2→CHR1 | 6 | **ENFORCED 6 / 6** (the second NAS refused) |
| NAS type `other` | concurrent | 10 | **EXCEEDED 10 / 10** (both requests before any Accounting-Start) |
| NAS type `other` | Accounting-Start lost (both directions) | 2 | **EXCEEDED 2 / 2** |
| NAS type `other` | lost Stop | 1 | **AVAILABILITY_FAILURE**: locked out by the stale row (no session anywhere) |

**Totals:**
- Production contract: **28 EXCEEDED** of 29 multi-NAS trials (3 + 3 + 10 + 9 + 2 + 1). The one that did not exceed is the lost-Stop case
  (single session, correctly admitted). The same-NAS controls were enforced.
- Variants: no configuration of the existing layers enforced the global limit in every case.

## 4. FreeRADIUS decisions, checkrad, radacct (examples from the evidence)

**`shipped`, `H2.su1seq` trial 0** (`shared_users = 1`):
- CHR1 login: request 13 → `Simultaneous-Use := 1`, `session = ok`, `nuxr-hotspot=exact`, Access-Accept. Its
  Accounting-Start is in radacct (`10.88.0.2`, open).
- CHR2 login: request 15 → `session { }`: 1 open row ≥ limit 1, so checkrad is run for the CHR1 row:
  `[wp8-checkrad] type=mikrotik_snmp nas=10.88.0.2 port=ether4 user=… exit=0 ms=30` ("not logged in") →
  `session = ok` → **Access-Accept**.
- radacct afterwards: two open rows (`10.88.0.2` and `10.88.0.3`). `/ip/hotspot/active`: one on each NAS.

**`functional`, SNMP dropped, `H2.snmpblk`:**
- `sql: ERROR: Failed to check the terminal server` → `session = fail` → **Sent Access-Accept** (fail-open).
- checkrad ran 10,042 ms; RouterOS had abandoned the request.
- The record `CORRECTION.H2.snmpblk.classification` keeps the original label and states the correct one.

**NAS type `other`:**
- No NAS query: an open row counts as live. That enforces the limit sequentially, but not before the first
  Accounting-Start (concurrent, lost Start) and it **locks the subscriber out** after a lost Stop.

## 5. Classification against the global limit

| Outcome | Where |
|---|---|
| **Proven exceedance** | Production contract: sequential (both directions), concurrent, `shared_users=2` (up to 2 × limit), lost Start, late accounting. Also functional SNMP. Also NAS type `other` for concurrent and lost Start. |
| **Safe enforcement** | Same NAS only (RouterOS `Port-Limit`); NAS type `other` sequentially |
| **Availability failure** | NAS type `other` after a lost Stop (lock-out); SNMP-blocked verification (refused only because the fail-open Accept came after the NAS timeout) |
| **Inconclusive** | none in the matrix. Not tested: more than two NAS, NAS in different RADIUS instances, Hotspot login methods other than http-chap (H1-A covers them on one NAS). |

**Mechanism:**
- `Port-Limit` is local to each RouterOS NAS.
- The only global layer, FreeRADIUS `Simultaneous-Use`, works only from accounting rows and verifies them with a
  checkrad that cannot see Hotspot sessions (it fails open). With `other` instead, it trusts stale rows (lock-out)
  and cannot cover the window before the Accounting-Start.

## 6. Impact

**Subscriber / sharing.** One `shared_users = 1` account can be used concurrently on every NAS of the ISP. With N
Hotspot NAS, up to `N × shared_users` sessions are possible (lab: 2 × 2 = 4). Account sharing across sites becomes
possible; vouchers sold as single-device are not single-device across sites.

**ISP:**
- revenue leakage (one plan serves several users);
- capacity planning per subscriber is wrong;
- each session gets the full `Mikrotik-Rate-Limit`, so a subscriber's bandwidth is N × the plan.

**Quotas.** Time and data quotas (`Max-All-Session`, `Max-Data`) sum radacct across NAS, so they still bound total
usage. They do not see sessions whose Start was lost, and they do not bound concurrency.

**Not affected:**
- PPPoE (A2-R reservation plus the Option G guard, already accepted);
- the same-NAS Hotspot limit (`Port-Limit`), and H1-A spelling equivalence;
- no data exposure; FreeRADIUS still authenticates the correct password only.

## 7. Simplest remediation options (proposals only, NOT implemented)

1. **R-H2-A — NAS binding for Hotspot subscribers (simplest).** Admit a Hotspot login only on the subscriber's own
   NAS / site (the plan's existing `routers` field, or a per-subscriber NAS list, checked in post-auth against
   `NAS-IP-Address`). `Port-Limit` on that one NAS then *is* the global limit, including concurrent logins and lost
   accounting.
   - Cost: no roaming between sites.
   - Needs: a product decision and the data model of "which NAS".
2. **R-H2-B — Hotspot admission reservation (extend A2-R).** An atomic per-subscriber slot table in the RADIUS DB
   with `shared_users` slots, claimed in post-auth before the Accept and confirmed / ended by accounting (`Class`
   token), with Strict Mode like PPPoE.
   - Closes sequential, concurrent and lost-Start cases across all NAS.
   - A lost Stop needs a verified release: a Hotspot verifier (RouterOS API `/ip/hotspot/active` or an SNMP OID that
     really lists Hotspot users, to be proven), or the operator release procedure.
   - Most complete; largest change.
3. **R-H2-C — Hotspot verification guard (like Option G).** Before a Hotspot Accept, ask the NAS of each open row
   whether the session is live, fail-closed (replacing the blind checkrad). It fixes the sequential case.
   - Does not cover concurrent logins or lost Start (no row yet), so it is insufficient alone.
4. **Not recommended alone:** NAS type `other` (fixes sequential only; concurrent and lost Start still exceed;
   lost-Stop lock-out).

**Recommended for a decision:** R-H2-A if subscribers belong to one site, otherwise R-H2-B (with a verifier proven
first in a separate gate).

## 8. Evidence, exit codes, cleanup

| Item | Value |
|---|---|
| Evidence | `owner-review/WP8/evidence/h2/lab-evidence.jsonl` (22 records, including the correction record); `lab-console.out` (lab-up, setup, every run's console). No pcaps or secrets. |
| Exit codes (all 0) | `h2_gate.py setup`; `run precheck control_same_nas su1_sequential`; `run su1_reverse su1_concurrent su2_split lost_start late_accounting`; `run lost_stop` + functional switch + functional runs; NAS-type-`other` runs |
| Exit codes, mode switches | all 0: `lab.sh up`, `option_g_gate.py on shipped` / `on functional`, `a2r_gate.py threaded on`, `lab.sh up-chr2`, `a2r_gate.py chr2 setup`, `lab.sh down` |

The verdict comes from the recorded classifications, not from an exit code.

**Lab tooling (uncommitted, lab only):**
- `dev/wp8/h2_gate.py` (new);
- `dev/wp8/lab.sh`: `wp8c7` / `wp8c8` and their veths added to the cleanup lists so `down` removes them.

No change to `install/`, `system/`, H1-A, A2-R or AV-5 (0 diff lines). Production data was not touched.

**Cleanup:**
- `lab.sh down`: links 0, netns 0, qemu 0, agent 0, containers 0, networks 0; the CHR working disks removed.
- Backup `/srv/phpnuxbill/wp8-h2-prefix-backup-20261011T081453Z` (SHA OK).
- Secret sweep 0 hits; manifest 42/42.

**Git:** `HEAD` = `origin` = `15377755`. No commit, push, merge or deploy. V-1 and A3 are not started.

**Verdict: `H2_GAP_CONFIRMED`.** Stopped for the owner's decision on the remediation option.
