# WP8 A1 — Permanent Option G integration — execution results (2026-10-09)

Owner approval: "WP8 A1 — OWNER EXECUTION APPROVAL" (2026-10-09), decisions D1–D5 and the additional Hotspot
`Port-Limit` approval. Base: `wp8/integration-poc@47a3a02c`. **Not committed, not pushed** (owner rule: commit only after
acceptance). Lab: RouterOS CHR **7.24.5**, FreeRADIUS **3.2.8** (pinned digest + net-snmp), MariaDB 10.11; harness:
MariaDB 10.11 and MySQL 8.0, PHP 8.2. `lab-evidence.jsonl` = every lab record of this execution, redacted
(`dev/wp8/redact.py`; secret sweep: none of the lab secret values present).

## 1. What became permanent

| Property (gate of 2026-10-07) | Permanent form |
|---|---|
| Guard program | `install/freeradius/bin/nuxr_pppoe_session_guard` (0755); code identical to the gate copy except the credential path (`${0%/*}/nas_snmp`), pinned by `tests/Unit/Wp4/SessionGuardSourceTest.php` |
| Named exec module | `install/freeradius/mods-available/nuxr_pppoe_session_guard` (`${confdir}/nuxr/...`, no shell, no environment, `output_pairs = control`, timeout 3) |
| Post-auth policy | part of `install/freeradius/sites-available/phpnuxbill`; code byte-identical to the gate copy, first in `post-auth`, never in `session` / `Post-Auth-Type REJECT` |
| `delete_stale_sessions = no` | `install/freeradius/mods-available/sql.example`, `dev/test/freeradius/sql` |
| net-snmp | part of the FreeRADIUS image (`dev/test/freeradius/Dockerfile`: pinned image + `snmp 5.9.1+dfsg-1ubuntu2.9`); no installation at run time; stock checkrad byte-identical (`be009360…`) |
| SNMP credentials | one file per instance `${confdir}/nuxr/nas_snmp` (0600 freerad); lab: persistent `$NUXR_WP8_STATE/radius-secrets/nas_snmp` (dir 0700, file 0600) mounted read-only and installed on every start; `nas.community` untouched (not read by the guard; the admin UI renders it - README) |
| Hotspot `Port-Limit` | `RadiusPolicyBuilder`: reply attribute, Hotspot only, `max(1, shared_users)`; PPPoE / VPN none |
| Wiring | `dev/test/freeradius/start.sh`, `dev/wp8/freeradius/start.sh` install from `install/`; the lab `$INCLUDE` is gone (no double block); fail-fast checks; the gate copy `dev/wp8/freeradius/option-g/` is kept as the reference and used by no start script |

## 2. Results (all runs on the permanent configuration)

| Run | Scope | Result |
|---|---|---|
| T1 unit | `SessionGuardSourceTest`, `RadiusPolicyBuilderTest`, `RadiusStateTest`, `RadiusSourceGuardTest` | OK 25 tests / 296 assertions |
| T3 harness focused | `SessionGuardContractTest` (7), `FreeRadiusContractTest`, `HotspotPortLimitReconciliationTest` (3) | MariaDB OK 18 / 235; MySQL OK 18 / 235 |
| T3 harness regression | wp4-unit, wp4-integration, wp5a-unit, wp5a-integration, wp5b-unit, wp5b-integration | MariaDB OK 258 / 5470; MySQL OK 258 / 5470; variant registry reproducible; runner exit 0 |
| T4 gate shipped | `OptionGGateTest` discriminator + 14 shipped (A–I, hostile usernames, Hotspot 1 / 2 / accounting lost) | OK 15 / 426 |
| T4 gate functional | lab-only functional stock checkrad (stock rejects first; SNMP loss: stock fails open, guard rejects) | OK 2 / 39 |
| T4 WP8 suite (production contract: CHR NAS type `mikrotik_snmp` + credentials) | `wp8-integration`, 69 tests | 69 tests, 1190 assertions, **0 failures, 0 errors**, 13 documented skips (mode-gated: 10 `CheckradGateTest` = rejected Option F gate, 2 functional, 1 drill 7 baseline), 3 PHP warnings + 1 PHP deprecation. **PHPUnit process exit code 1** (`tests/phpunit.xml` `failOnWarning="true"`): caused only by the pre-existing warnings below (NOTE-01) |
| T4 drill 7 baseline (NAS type `other`, as in S6) | `FailureDrillsTest::testDrill7LostAccountingStopAndSimultaneousUse` | OK 1 / 36 - the S6 behaviour is unchanged for a NAS that is not configured per the contract (fail-safe lockout, never an Accept) |
| T4 drill 7 with the guard | `FailureDrillsTest::testDrill7GuardLostStopReloginIsAcceptedWithoutAnyRepair` | PASS (in the suite run): real re-login accepted, stale row byte-for-byte unchanged, history 2 rows, no reboot / Accounting-On / wait / deletion |
| T5 clean recreation | FreeRADIUS container recreated (`--force-recreate`), DB and RouterOS untouched, gate tooling NOT re-run | guard, module, credentials (0600 freerad, 2 entries), net-snmp all present; Gates B, C, E + Hotspot shared-1 OK 4 / 94 |

Harness contract (both engines): first PPPoE login without an open row accepted without NAS query; open row on an
unverifiable NAS (credential entry, unreachable) and on a NAS without credentials rejected with `Reply-Message` only,
inside 2.5 s, stale row byte-for-byte unchanged; multi-NAS with any unverifiable NAS rejected; NAS type `other` stays
with the stock layer; Hotspot never reaches the guard and carries `Port-Limit`; 8 hostile usernames matched on the stock
SQL key (near-miss names accepted); the random test community never appears in the FreeRADIUS log; no guard temp dir left.

Hotspot `Port-Limit` and reconciliation (`HotspotPortLimitReconciliationTest`, both engines): a new Hotspot activation
provisions and verifies `Port-Limit` (radreply, never radcheck), PPPoE gets none; an activation captured before A1
still reconciles to TARGET while RADIUS is unchanged; only after an A1 re-provision does a pre-A1 target differ, exactly
by `reply:Port-Limit` -> EXTERNAL_STATE_AMBIGUOUS, funds held, nothing written (the accepted safe outcome).

## 3. Latency (captured shipped run, RouterOS `/radius timeout = 3000ms`)

| Case | Guard (in-program) | On the wire |
|---|---|---|
| A first login (no open row) | not run | 8 ms (request -> reply as measured by the test) |
| C lost Stop, session gone -> SAFE | 182 ms | Access-Accept 235 ms |
| B / D live -> LIVE | 188–256 ms | Access-Reject 1001–1002 ms (stock `reject_delay` 1 s) |
| E SNMP unreachable -> ERROR | 1084 ms | Access-Reject 1174 ms |
| G wrong community -> ERROR | 1075 ms | Access-Reject 1125 ms |
| F / H tool or credentials missing | 75–85 ms | Access-Reject 1001–1002 ms |
| I multi-NAS | 181–199 ms | 1001 ms |
| lab-only functional checkrad, SNMP unreachable | 1058 ms | 11136 ms (the stock checkrad's own 10 s wait, Option F configuration - not the shipped/contract configuration; documented in the README) |

Guard median 188 ms, max 1084 ms; hard deadline 1.8 s. Every contract-configuration reply < 1.2 s, well inside 3 s.

## 4. Errors found and fixed during A1 (disclosed)

1. `start.sh` fail-fast checks: `! grep` and `a && b && c` are not enforced by `set -e` - rewritten as explicit checks
   before any run.
2. Unit test count of `$comm` uses in the guard was wrong in the new test itself (2 uses + 1 read) - test corrected.
3. First WP8 suite run: 4 failures, all test-side, none in the product:
   - `lastReply()` (shared lab helper) read only the last 200 log lines and tracked request numbers across FreeRADIUS
     restarts; with the permanent guard a PPPoE request logs many more post-auth lines, and the gate switches restart
     FreeRADIUS -> wrong / no request found (drill 7G, lifecycle Session-Timeout). Fixed: request-local, restart-safe
     parser and a larger window (`client_agent.fr_log` `limit`, `Wp8Lab::frLog`).
   - `FreeRadiusSqlClientTest` counted "client added" lines over all FreeRADIUS starts of the container - now once per
     start.
   - `RealAaaTest` Hotspot limit asserted the FreeRADIUS layer; under the contract (`mikrotik_snmp`) FreeRADIUS accepts
     and RouterOS refuses via `Port-Limit` (the Option G gate observation) - assertion made NAS-type aware (`other`
     keeps the original assertion).
   Re-run: 0 failures.
4. Two lab operator slips, no effect on results: `option_g_gate.sh` was started believing it took a mode argument (it
   runs the whole gate) and a 600 s shell timeout killed the wrapper while PHPUnit continued; later a too broad
   `--filter` (PortLimit / RouterOsClosure) was cut by a timeout the same way. In both cases the PHPUnit process was
   left to finish (never interrupted mid-test) and the runs were repeated with captured output; only captured runs are
   reported above. Their evidence records are part of `lab-evidence.jsonl` (marked by time stamps, not counted).
5. T5 first attempt skipped its 4 tests: the lab evidence marker (`wp8-option-g`, trace wrapper only) lives inside the
   container and is lost on recreation by design; it was recreated (marker only, no credentials / guard / RouterOS /
   database step) and the 4 tests passed.

## 5. Not changed / out of scope (recorded)

`CheckradGateTest` (rejected Option F) and `PortLimitGateTest` (rejected Option E) files unchanged and not part of the
A1 result. A2 (concurrent first login), R-WP8-CARRY-01/02/03, C-09, R-HF-CARRY-01, S7, S8 untouched. No schema,
migration, RouteMap, capability, finance or activation change. `nas.community` data untouched.

## 6. Owner acceptance notes (A1 ACCEPTED WITH NOTES, 2026-10-09)

**NOTE-01 - PHPUnit warnings (non-blocking, pre-existing, not fixed in A1).**
- The WP8 suite result: 69 tests, 1190 assertions, zero failures and errors, 13 documented skips. The process exit is
  **non-zero (1)** because PHPUnit fails on warnings.
- The 3 warnings are `system/autoload/Message.php:13-15` "Undefined variable $root_path". They are triggered by
  `RealLifecycleTest::testLegacyReprovisionPasswordAndUsernameOnTheRealNas` (legacy reprovision path). The file is
  upstream code: its last change is upstream commit `c63545d3`, and it is unchanged by A1.
- The 1 deprecation is the PHP 8.2 return-type deprecation of the vendored `PEAR2\Net\RouterOS\Message::getIterator()`
  and `::count()`. It was already recorded in S1 (`evidence/s1/api-probe-pear2-7.24.5.json`, lines 88-89, at
  `47a3a02c`) and reproduces on any load of the PEAR2 client.
- `system/autoload/PEAR2/**`, `system/autoload/Message.php`, `system/devices/**` and `LegacyReprovisionService` are
  identical to `47a3a02c`. The only `system/` file A1 changes is `RadiusPolicyBuilder.php`.
- No new warning comes from A1.

**NOTE-02 - clean recreation.**
- The permanent guard does not depend on the lab marker. `dev/wp8/freeradius/start.sh` installs the guard program, the
  module and the credential file unconditionally on every start. The marker `$R/wp8-option-g` only switches the
  diagnostic checkrad trace wrapper used by the lab tests' evidence.
- Proof:
  - The T5 state right after recreation, before the marker was restored: guard 0755, module, credentials 0600 freerad
    with 2 entries, net-snmp present.
  - The WP4 harness (`SessionGuardContractTest`, both engines), which has no marker at all and exercises the guard's
    fail-closed decisions.
- **Manual step** to re-run the `OptionGGateTest` lab tests after recreating the FreeRADIUS container: restore the
  marker (`docker exec nuxbill-reseller-test-freeradius-wp8-1 touch /etc/freeradius/wp8-option-g` followed by a
  FreeRADIUS restart), or run `dev/wp8/option_g_gate.py on shipped`.
- `option_g_gate.py on` is lab gate tooling. It also configures RouterOS SNMP and the lab NAS rows; production performs
  those as the operator steps documented in `install/freeradius/README.md`. The lab test procedure is therefore not
  fully automatic after a recreation.

**NOTE-03 - A1 is not a deployment authorization. These release items stay OPEN:**
- FreeRADIUS **3.2.8** is the only version validated. Any other version needs the Option G gate again.
- Every MikroTik NAS must have PHPNuxBill NAS type `mikrotik_snmp`. The UI default `other` keeps the fail-safe lockout
  after a lost Stop.
- The SNMP credential must be read-only, restricted to the FreeRADIUS address, different from the RADIUS secret,
  isolated per ISP instance (own credential file, own backup), with documented provisioning and rotation.
- RouterOS `/radius timeout` must be 3 s or more on every NAS.
- **Hotspot subscribers provisioned before A1 have no `Port-Limit`** until their next provisioning (activation,
  renewal or reprovision). A1 performed no mass re-provisioning and no migration. A rollout plan for existing Hotspot
  subscribers is a separate owner decision.
- Stock `checkrad` limits: a build that can run SNMP may wait up to 10 s for an unresponsive NAS. That refuses the
  login (fail closed, availability only); re-gate any such build.
- The concurrent first-login gate (A2) is not run.
