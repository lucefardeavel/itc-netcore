# WP8 S7 — Direct MikroTik Driver Classification & Fail-Closed Contract Tests: Implementation and Test Report

| Item | Value |
|---|---|
| Date | 2026-10-14 |
| Owner decision | `S7_PLAN_APPROVED_WITH_AMENDMENTS — IMPLEMENTATION_AUTHORIZED`: OD-S7-1 = A, OD-S7-2 = A, AM-S7-1 … AM-S7-4 |
| Baseline | `wp8/integration-poc@f07917e9f976d2ec4cbd0b32fcb875a72be8d979` (= origin, unchanged) |
| Round 1 | Tests, lab tooling and docs only. **No product code change**; no schema, finance or accounting history change; no production or real MikroTik; no commit, push, merge or deployment; S8 not started |
| Round 1 verdict | `S7_BLOCKED_WITH_EVIDENCE`: a real authority bypass was proven during the approved fail-closed tests (§6, **S7-F1**); S7 stopped, the lab was cleaned and stopped, and the bypass was presented for a decision (§10). §1 … §11 below are the round-1 record, unchanged except for forward references |
| Round 2 (owner decision) | `S7_F1_TARGETED_REMEDIATION_APPROVED — S7_REMAINS_BLOCKED` (option C): one limited change in `LegacyReprovisionService.php`, test-first, real CHR regression; no `Package.php` / `ServiceGate` / schema change; no commit, push, merge, deployment or S8 |
| Round 2 verdict | `S7_BLOCKED_WITH_EVIDENCE` (§12 … §15). **S7-F1 is fixed and closed in every tested form** (direct PPPoE / VPN grants, sync, the legacy recharge funnel, database-equal variants, the RADIUS-masking case, and the legacy Radius device on both engines), and no proven authority bypass remains in the tested scope. S7 stays blocked by the **legacy recharge funnel's residue** (owner §4): after the refused device grant, the funnel still saves an active recharge row and an invoice for the unassigned customer (§12.5). Per the owner instruction this is documented and stopped for a separate authorization, so the §5 continuation (the focused CHR regression and the frozen full harness) was not started |
| Round 3 (owner decision) | `OD-S7-FC-1 = APPROVED`, `S7_FC_PREWRITE_REFUSAL_APPROVED — IMPLEMENTATION_AUTHORIZED` (option F-C). It allows one limited change in `Package::rechargeUser()` and, only for that file, a SHA-pin update in `tests/Unit/Wp3a/SourceGuardTest.php`. Then S7 is completed. No schema, driver, finance-service or production change; no commit, push, merge, deployment or S8 |
| Round 3 verdict | `S7_READY_FOR_OWNER_ACCEPTANCE` (§16 … §19) |
| **Owner acceptance** | **`S7_ACCEPTED_WITH_NOTES — DEVELOPMENT ONLY`** (2026-10-14), recorded in `OWNER_DECISIONS.md`. Not production acceptance; OBS-1 stays a production blocker |

## 1. Result in one table

| Area | Result |
|---|---|
| T-S1 … T-S4 (source classification, both engines) | **PASS**, exit 0 on MariaDB and MySQL (5 tests / 34 assertions each) |
| DC-X0 / DC-H / DC-P / DC-V1 / DC-X (real CHR) | **PASS**, final run 8 tests / 200 assertions, exit 0 |
| Local vs RADIUS separation | **PROVEN**: local Hotspot and PPPoE logins without any RADIUS request; a RADIUS subscriber still authenticates through RADIUS with exactly one new accounting row of its own |
| FC-1, FC-3 / FC-8, FC-4, FC-5, FC-6, FC-7 | **PASS**, with real positive controls |
| **FC-2 (b) and (c)** | **FAIL = real bypass (S7-F1)**: an unassigned customer whose PPPoE username equals a reseller-owned customer's key overwrote that customer's real router secret, through the driver and through the legacy recharge funnel |
| Cleanup | Every S7 object, the temporary API user, its group and the router row were removed and verified. The RouterOS inventory equals its pre-test state for all 6 class runs. The lab is down with nothing left |
| Not run because of the stop | Step 9 (focused lab regression `RealAaaTest` / `RouterOsClosureTest` / …) and step 12 (full harness). The **SEP** test re-proved the RADIUS NAS function on the CHR. No tracked file changed (`git diff HEAD` is empty) |

## 2. Final driver classification matrix

Unchanged from the approved plan §4, now **confirmed** by T-S1…T-S4 (source) and DC / FC (real router).

| Method (H / P / V) | Grant guard | Class | Real-router result |
|---|---|---|---|
| `add_customer` (32 / 30 / 23) | `assertDeviceAllowed($customer)`: **by id and by the row's `username` only** | B + C | **Contract:** DC-H2, DC-P2, DC-V1 pass. **Guard:** refused by id (FC-1) and by an equal `username` (FC-2a). **Not refused** when the row's `pppoe_username` is an owned key (FC-2b, **S7-F1**) |
| `sync_customer` (56 / 93 / 73) | same | B + C | DC pass; FC-1 refused |
| `change_username` (100 / 126 / 105) | `assertDeviceAllowed(null, $from, $to)` | B + C | DC-H3 (the old name is gone: real-router WP5B 4A), DC-P2, DC-V1 pass; FC-1 refused |
| `remove_customer` (83 / 100 / 80) | none (expiry path; every controller caller is gated) | C + P4 | DC-H5 / DC-P3 (removal and `plan_expired`) pass; FC-6 (A5 gates stop it); FC-7 (cron expiry allowed) |
| `online_customer` / `connect_customer` / `disconnect_customer` | n/a | C | DC-H4 (real Hotspot client) and DC-P3 pass |
| `add/update/remove_plan`, `add/update/remove_pool` | n/a (admin) | C | DC-H1 and DC-P1 pass |
| `getClient` / `info` | n/a | internal + routers.php | DC-X1 / DC-X2 (OBS-W8-4) |
| unused helpers; static `system/autoload/Mikrotik.php` | — | **D** | T-S4: no caller |
| **Class A** (activation → driver) | — | **empty** | T-S1 |

## 3. Final call-site and authority matrix

The plan §3 (22 groups, **47 call sites**) is confirmed exactly by **T-S3**. The test pins the full inventory of `)->method(` device calls in `system/` and `radius.php`. Each grant or removal call has its declared gate inside its own branch: the A5 gates, `planEditPost`, `planExtend`, `customerSync`, `homeSync`, `homeExtend`, `accountsChangePassword`, `loginVoucherOnly`, `loginVoucherActivation`, `assertDeletable`, and the core funnel `ServiceGate::isBlocked*` in `Package::rechargeUser`.

**Declared without a gate, by design:**
- cron expiry (P4);
- the Hotspot connect / disconnect helpers in `home.php` (OBS-W8-3);
- `online_customer` reads;
- the admin plan, pool and router helpers.

**Class B** (`LegacyReprovisionService::devices()`): the permit is set before the driver is built and cleared in `finally` (asserted in source). It was also exercised on the real router (FC-3 / FC-8).

**Mutation checks** (all detected):
- P1, P2, portal-sync, core-funnel and voucher-only gates removed;
- an unclassified call site added;
- the default-deny guard removed from each driver;
- a Class A breach;
- a static `Mikrotik` caller.

## 4. Tests and exit codes (real; incomplete and skipped are never counted as passed)

### 4.1 Source (harness containers; `evidence/s7/source/source-run1.out`)

| Suite | MariaDB | MySQL |
|---|---|---|
| `tests/Unit/Wp8/DriverClassificationSourceTest.php` (T-S1…T-S4) | 5 / 34, **exit 0** | 5 / 34, **exit 0** |
| `LegacyRemovalSourceTest` (A5, unchanged) | 3 / 39, exit 0 | 3 / 39, exit 0 |
| `Wp5bSourceGuardTest` | 9 / 570, exit 0 | 9 / 570, exit 0 |
| `Wp3a/SourceGuardTest` (SHA pins) | 10 / 37057, exit 0 | 10 / 37057, exit 0 |
| `Wp5b/MikrotikDeviceTest` (fake-client driver tests) | 3 / 13, exit 0 | 3 / 13, exit 0 |

**Before that run:** a local run showed one failure, a miscount of the classified sites in my own test (53 instead of 47). The inventory itself matched; corrected.

### 4.2 Real lab: RouterOS CHR 7.24.5 (`evidence/s7/lab/`)

| Run | Result | Cause / action |
|---|---|---|
| `DC-run1.out` | 8 tests, **2 failures**, exit 1 | **Test fixture:** RouterOS rejects my customer e-mail on the reserved `.invalid` TLD (`failure: invalid email address`). The driver **ignored the error** and reported success, which is a real observation (**OBS-S7-1**, now pinned as DC-X3). The fixture now uses `example.com` |
| `DC-run2.out` | **8 / 200, exit 0** | — |
| `FC-run1.out` | 7 tests, 2 errors + 3 failures, exit 2 | **Test bugs:** (1) refusals in CLI context write a log line, not an audit row (by design), so the counters now check the `op=` log lines; (2) the PPPoE fixture of the owned customer used the router's `default` profile (no address); (3) the app-runtime helper used a global `$t` that `Package::rechargeUser` overwrites. All fixed in the tests |
| `FC-run2-diagnose.out` | the 2 errors diagnosed with the application's error log | the helper bug of (3) |
| **`FC-run3.out`** | 7 tests / 94 assertions, **1 failure, exit 1**: **FC-2 (b) = S7-F1 (a real bypass)**; all other FC tests pass | **stopped here** (owner rule) |
| `DC-run3-final-helper.out` | **8 / 200, exit 0** | re-run of the contract tests on the final shared helper (consistency only, nothing new) |

All failed runs are kept.

## 5. DC results (real router; local identities only)

| ID | Proven |
|---|---|
| **DC-X0 / AM-S7-2** | **Group policy:** exactly `read,write,api` (all others negated); user address `10.88.0.10/32`. **Through the application's PEAR2 client with the temporary credential:** read on 6 paths, add and remove OK. **Least privilege:** `/user/add` is refused (`!trap`). The password exists only in process memory and the disposable `tbl_routers` row, and the row is deleted (verified) |
| DC-H1 | Hotspot user profile add (`1M/2M`, shared 1) → update (`1M/4M`, shared 2) → remove |
| DC-H2 / H3 / H5 | **User:** add, then sync (idempotent, one user), then rename (old name gone). **Expiry:** with `plan_expired` the restricted profile is applied; without it the user is removed |
| **DC-H4** | A real Hotspot client (`c2`) logs in with a **local** user (`LOGGED_IN`, address `10.88.2.199`), with **0 FreeRADIUS log lines** for that name. `online_customer` gives the real active id; `disconnect_customer` removes it; `connect_customer` logs the host in again; removal cleans up. RADIUS checksums are unchanged |
| DC-P1 / P2 | **Pool:** add, then update (range changed), then remove. **PPP profile:** `10.88.250.1` / pool / `1M/2M`, then update (`3M/2M`), then remove. **Secret:** named after the PPPoE username, with a static `remote-address`; sync unsets the address; rename leaves the old name gone |
| **DC-P3** | A real PPPoE client (`c1`) dials with a **local** secret (address `10.88.250.118` from the local pool), with 0 RADIUS log lines. `online_customer` true. **Expiry:** `plan_expired` gives the restricted profile and drops the session. **Removal:** deletes the secret |
| **SEP / AM-S7-1** | A RADIUS subscriber `nuxr_wp8_s7rad_*` (no local secret) dials **through RADIUS** (FreeRADIUS log present, address `10.88.10.147` from the RADIUS pool). Exactly **one** new `radacct` row, its own (the expected positive-control accounting). Older accounting rows and the policy tables are unchanged |
| **DC-V1 / AM-S7-3** | **Test addresses:** `10.88.251.10`, public `10.88.251.250`, ports `41000-41010`; verified unused before. **Full NAT inventory:** 16 rules, 15 dynamic Hotspot rules plus 1 static. **Read-only probe:** `?to-addresses=` (what an empty `pppoe_ip` would make `rmNat()` select) matched **no** rule on this router. **Contract:** `add_customer` creates the secret (`service=any`, static address) and exactly 3 dst-nat rules (Winbox / Api / Web). **Pre-removal guard:** the removal can reach the test rules only. **After removal:** the secret and its 3 rules are gone and the **full NAT table is identical** to before |
| DC-X1 | API unreachable (lab firewall drop from the runner): `SocketException: Error connecting to RouterOS` after **60 081 ms** (no explicit driver timeout, OBS-W8-4); no partial object |
| DC-X2 | Router address without `:port`: PHP warning `MikrotikPppoe.php:352 Undefined array key 1`; the connection then uses the default port (OBS-W8-4) |
| **DC-X3 (OBS-S7-1)** | A grant RouterOS rejects (an e-mail it refuses) is reported as **success** by the driver while **nothing is created**: the drivers never check `!trap` replies |

**Driver tests never touch RADIUS:** every driver test asserts byte-identical RADIUS checksums for `radcheck`, `radreply`, `radusergroup`, `radgroupcheck`, `radgroupreply`, `radacct` and `nas`. No local name overlaps a RADIUS identity (checked).

## 6. FC results (reseller-owned customer with legacy MikroTik plans and real local router objects)

| ID | Result | Evidence |
|---|---|---|
| FC-1 | **PASS** | 9 direct grants (add / sync / rename × H / P / V) → `RESELLER_OWNED_REFUSED`, 9 refusal log lines (`op=deviceGrant`; CLI context logs, it does not audit). The owned customer's router objects, the inventory and RADIUS are unchanged. **Positive control:** the same grants for an unassigned customer create the objects |
| FC-2 (a) | **PASS** | an unassigned row with the **same username** → refused on H and P; owned objects unchanged |
| **FC-2 (b)** | **FAIL: S7-F1** | An unassigned customer (own username) whose `pppoe_username` = the owned customer's key. `MikrotikPppoe::add_customer` was **allowed** (`ok: true`) and **overwrote the owned customer's real PPPoE secret**: the password changed and the comment is now the unassigned customer's |
| **FC-2 (c)** | **FAIL: S7-F1 via a legacy funnel** | `Package::rechargeUser` (the funnel of every legacy grant caller) for that unassigned customer **succeeded** (invoice `INV-6`) and **overwrote** the owned secret the same way |
| FC-3 / FC-8 (AM-S7-4) | **PASS** | **Permitted path on the real router:** `LegacyReprovisionService` `ADMIN_SYNC` = `DONE`, `PASSWORD_CHANGE` = `DONE`, and the new password reached the router. **After success:** a direct driver call is `RESELLER_OWNED_REFUSED`, so the permit is gone. **With the API unavailable:** `PASSWORD_CHANGE` gives `REPROVISION_UNCONFIRMED` after ~60 s (OBS-W8-5), the router is unchanged, and a direct call is again `RESELLER_OWNED_REFUSED` (the permit is gone after failure too) |
| FC-4 | **PASS** | `Package::rechargeUser` for the owned customer gives `false` (`op=rechargeUser` logged), with no recharge, no transaction and no router change. **Positive control:** the unassigned recharge reaches the router |
| FC-5 | **PASS** | voucher-only code = owned username → `REFUSED`, router unchanged. **Positive control:** a fresh code → `GRANTED`, and the Hotspot user exists on the router |
| FC-6 | **PASS** | A5 P1 / P2 / P3 (HTTP) with a MikroTik plan and the owned customer's **live local PPPoE session**: each 302, 3 refusals audited, the application data byte-identical, the secret unchanged, **the session survived** |
| FC-7 | **PASS** | real `system/cron.php` (exit 0) expires the owned customer's PPPoE plan (`status=off`) and removes the router secret. This is the canonical end (P4), not a bypass |

## 7. S7-F1: the proven authority bypass (stopped, not fixed)

> **Round 2:** fixed and regression-proven (§12). The "not tested" RADIUS-masking hypothesis below was **proven** before the fix (FC-2d, §12.3), and the harness showed that the same gap also reached the legacy **Radius** device: an owned subscriber's RADIUS policy was overwritten on both engines (§12.2).

**Root cause:**
- **The guard's name list.** `LegacyReprovisionService::assertDeviceAllowed()` (`system/autoload/Reseller/Domain/LegacyReprovisionService.php:191-203`) builds the names to check from the customer row's **`username` only**, plus explicit rename names (`:195`). `deviceOwned()` compares those names with owned customers' `username` **and** `pppoe_username`, but the row's own `pppoe_username` is never in the list.
- **The drivers' name choice.** The PPPoE and VPN drivers name the router secret after **`pppoe_username`** when it is set (`MikrotikPppoe.php:60-64, 381-385`; `MikrotikVpn.php` same), and find an existing secret by it (`getIdByCustomer`).

**Reachability (read-only analysis):**
- **No collision check:** customer creation (`customers.php` add-post → `CustomerService::create`) checks only `username` against `username`. It never checks `pppoe_username` against other customers' keys: the **OBS-1** family, already a production blocker.
- **Edit is blocked:** the R2 identity edit refuses a PPPoE collision since A3. Creation, or pre-existing data, is enough.
- **Any grant then fires it:** every legacy grant for such an unassigned customer reaches the driver: Admin recharge, portal purchase, voucher, sync, extend, cron auto-renew.

**Impact proven in the lab:**
- **Who and what:** an unassigned (non-reseller) customer's ordinary legacy grant **modified a reseller-owned customer's router credentials and comment**.
- **What it bypasses:** the reseller activation / re-provision authority (OD-11 / WP5B default deny).
- **Not a privilege escalation:** it requires an Admin-created (or legacy) colliding identity.

**Not tested (hypothesis, stopped):** if the owned customer is a **RADIUS** subscriber (the normal reseller path), the same grant would **create** a local secret with the subscriber's key. RouterOS checks local users first (§5 of the plan), so that local secret would **mask the subscriber's RADIUS authority**.

**Severity (my assessment):** HIGH for production, development-only for now. It is the same family as **L-A5-2 / OBS-1**, but proven at the MikroTik driver level.

## 8. Observations OBS-W8-2 … OBS-W8-5 and new ones (no fix in S7)

| ID | Current assessment |
|---|---|
| OBS-W8-2 | Still present (`plan.php:81`, `customers.php:338`, `home.php:97`). Harmless in practice: DC-H2 / DC-P2 prove that `add_customer` is idempotent on the real router |
| OBS-W8-3 | Still present (`home.php:236 / 239`); the Hotspot implementation works (DC-H4); PPPoE and VPN lack the methods (a fatal error if reached) |
| OBS-W8-4 | **Confirmed on the real router:** no driver timeout (60 s to fail, DC-X1); the missing `:port` warning (DC-X2); plain API inside the lab (OD-W8-5) |
| OBS-W8-5 | **Confirmed:** a driver exception gives `REPROVISION_UNCONFIRMED` (no revert, no retry); the permit is cleared (FC-3) |
| **OBS-S7-1 (new)** | The drivers ignore RouterOS `!trap` replies: a rejected grant is reported as success while nothing was created (DC-X3). This affects reliability and evidence, not authority |
| **OBS-S7-2 (new)** | **Stale comments:** `MikrotikVpn::change_username` leaves the NAT comments with the old name (3 rules in DC-V1). **Selection by address:** `rmNat()` selects rules by `to-addresses` = the customer's current `pppoe_ip`, not by its own comment, so a rule of another purpose with the same target would be removed. With an empty `pppoe_ip` the query matched nothing on RouterOS 7.24.5 (read-only probe) |

## 9. Inventory, cleanup, security

| Check | Result |
|---|---|
| RouterOS inventory (11 paths) before / after each class run | **identical** in all 6 class runs (`S7.class-cleanup.*`): the API user, its group and the router row are removed, `inventory_unchanged = true` |
| Post-run `dev/wp8/s7_cleanup.py delete` (read-before-delete) | `listed: []`, `remaining: []`; users = `admin`; groups = built-ins only; no S7 firewall rule |
| Lab stop | `lab.sh down` exit 0; no container, QEMU process, netns, `wp8*` link or `127.0.0.1:18728` listener left |
| Secret sweep (lab secret values, private-key markers, the temporary API password format) over the S7 tests, tool, plan and all `evidence/s7/` | **0 hits**. One `"password"` key in the `FC-3` evidence is the **status** of the permitted PASSWORD_CHANGE run (`DONE`), not a password. The API password never reached evidence (checked before every write) |
| Manifest | **42 / 42** |
| Tracked files | `git diff HEAD` **empty**: no product, schema, finance or protected file changed |
| Diff check | clean; new files are LF without trailing whitespace |
| A1–A5 | untouched; their source guards and pins pass (§4.1) |

## 10. Decision needed (S7-F1)

| Option | Change | Note |
|---|---|---|
| **A (recommended)** | **Minimal guard fix:** `assertDeviceAllowed()` also checks the row's own `pppoe_username` (one line at `LegacyReprovisionService.php:195`), so any unassigned row carrying an owned key is refused before the driver | small, inside the existing WP5B default-deny; FC-2 (b) / (c) become the regression tests; needs a product-code authorization (S7 forbids product changes) |
| B | Creation-time uniqueness of usernames / PPPoE keys across customers | the OBS-1 / L-A5-2 work (production blocker); larger; schema or uniqueness decisions |
| C | A + B | A now, B before production |
| D | Accept S7 with S7-F1 recorded as a new production blocker | not recommended: a proven authority bypass |

**My recommendation:** **C**. A as a separately authorized, test-first S7 remediation round, then the steps S7 did not finish (the focused lab regression and the full harness). B stays a production blocker under OBS-1.

## 11. Git state and files

| | |
|---|---|
| HEAD / origin (round 1) | `f07917e9…` / `f07917e9…` (unchanged); nothing staged or committed. Round 2 state: §15 |
| New (untracked) | `tests/Unit/Wp8/DriverClassificationSourceTest.php`, `tests/Support/Wp8DriverLabCase.php`, `tests/Integration/Wp8/DriverContractLabTest.php`, `tests/Integration/Wp8/DriverFailClosedLabTest.php`, `dev/wp8/s7_cleanup.py`, `owner-review/WP8/WP8_S7_PREFLIGHT_AND_CONTRACT_TEST_PLAN.md`, this report, `owner-review/WP8/evidence/s7/` |
| Modified (tracked) | none |

**Round 1 verdict: `S7_BLOCKED_WITH_EVIDENCE`.**
- **Proven and passing:** the classification, the real-router contracts, the local / RADIUS separation and 6 of the 7 fail-closed areas.
- **Blocked by S7-F1:** an unassigned identity carrying a reseller customer's PPPoE key bypasses the driver's default deny and overwrites the reseller customer's router credentials.
- **Waiting:** the owner's decision (§10). No commit.

---

## 12. S7-F1 Targeted Remediation & Regression Evidence (round 2)

Owner decision: `S7_F1_TARGETED_REMEDIATION_APPROVED — S7_REMAINS_BLOCKED` (option C). Baseline `wp8/integration-poc@f07917e9…` (= origin, unchanged). Evidence: `evidence/s7/f1/`. Backup of every file touched before the round: `/srv/phpnuxbill/wp8-s7f1-backup-20261014T093719Z/`.

### 12.1 The fix (the only product change)

`system/autoload/Reseller/Domain/LegacyReprovisionService.php`, `assertDeviceAllowed()` (`:197`): the names the guard judges now include the row's own **`pppoe_username`** next to its `username` and the explicit rename names. `git diff HEAD --stat`: this file only, 3 insertions / 1 deletion (one code line plus a two-line docblock note).

| Owner condition (§2) | How it holds |
|---|---|
| No change to the function's signature or authority | Same signature, same callers, same `deviceOwned()` query, same refusal (`ServiceGate::refused('deviceGrant', …)` + `RESELLER_OWNED_REFUSED`, non-retryable) |
| The private permit is kept | `self::$permit` is untouched: still private, set and cleared only in `devices()` (`Wp5bSourceGuardTest::testDeviceGrantsAreDefaultDeny`, T-S1 pins pass). Inside `run()` the permit already lists the old and new `pppoe_username`, so the owned customer's own key passes (FC-3b, harness test 4) |
| Non-colliding legacy customers keep their behaviour | Harness test 3 and the FC-2 positive control: an unassigned customer with its own PPPoE username is provisioned under it; an empty `pppoe_username` is not an identity. DC 8 / 200 pass unchanged |
| Fail-closed and SourceGuard not weakened | The guard only gains a name: it can refuse more, never less. Every source guard passes (§12.6) |
| No RouterOS secret of a reseller customer can be replaced | FC-2 / FC-2c / FC-2d: secret, live session and RADIUS policy unchanged in every case |
| No driver change | None needed: every regression passes with the guard change alone. `system/devices/*` unchanged |
| No `Package.php` / `ServiceGate` / schema change | Unchanged (`git diff HEAD --stat` on them is empty) |

### 12.2 Test-first proof: the regression failed before the fix, for this exact cause

| Run | Before the fix (`f07917e9` code) | After the fix |
|---|---|---|
| Harness `DeviceGuardKeyCollisionTest` (new, in-process, legacy **Radius** device), MariaDB and MySQL | `harness-prefix.out` + `harness-prefix-run2.out`: **4 tests, 2 failures on each engine**, exactly the S7-F1 cases. The guard, `Radius::add_customer` and `sync_customer` returned `ALLOWED` for all four variants, and **the owned subscriber's RADIUS policy was overwritten** (`owned_policy_unchanged = false`, `tables_unchanged = false`). The two control tests passed | `harness-postfix-focused.out`: pass on both engines (§12.6) |
| CHR `DriverFailClosedLabTest` FC-2 / FC-2c / FC-2d / FC-3b | `FC-prefix.out`: **4 tests, 3 failures** (FC-2, FC-2c, FC-2d), each on the bypass itself; FC-3b passed (permitted path) | `FC-postfix-run1.out`: **10 tests / 174 assertions, exit 0** (the whole FC class) |

Before the fix, the CHR evidence (`s7f1-evidence.jsonl`, 09:45–09:47) showed:
- **FC-2:** the `pppoe_username` collider and its upper-case and trailing-space variants were `ALLOWED` on both PPPoE and VPN, for both `add_customer` and `sync_customer`.
  - The owned secret was overwritten.
  - **Its live session was disconnected** (the driver's `$isChangePlan` branch removes the active session of `pppoe_username`).
  - Variant secrets were created.
- **FC-2c:** the recharge funnel overwrote the owned secret.
- **FC-2d:** a local secret was created that **masks** the owned RADIUS subscriber. Its RADIUS login then failed, and FreeRADIUS was never asked.

### 12.3 The required regression cases on the real, isolated CHR (after the fix)

| # (owner §3) | Case | Test | Result |
|---|---|---|---|
| 1 | Direct PPPoE grant collision | FC-2 `b_pppoe_username`, `MikrotikPppoe::add_customer` | `RESELLER_OWNED_REFUSED` |
| 2 | Direct VPN grant collision | FC-2 `b_pppoe_username`, `MikrotikVpn::add_customer` | `RESELLER_OWNED_REFUSED` |
| 3 | `sync_customer` on a colliding identity | FC-2, PPPoE and VPN `sync_customer`; FC-2d | `RESELLER_OWNED_REFUSED` |
| 4 | `Package::rechargeUser` collision | FC-2c | The device grant inside the funnel was refused (1 `op=deviceGrant`) and the owned customer is untouched. The funnel's own records for the unassigned customer remain (§12.5) |
| 5 | Database-equal spellings | **CHR:** FC-2 upper case and trailing space. **Harness (both engines):** exact, upper case, trailing space, accent; as the PPPoE username against an owned username **and** against an owned PPPoE username | All `RESELLER_OWNED_REFUSED`; no variant secret on the router; owned RADIUS policy unchanged |
| 6 | A legitimate, non-colliding legacy customer is allowed | FC-2 positive control; harness test 3; DC 8 / 200 | Provisioned under its own PPPoE username (router and RADIUS) |
| 7 | The permitted LegacyReprovisionService works on the owned identity | FC-3 (username identity), **FC-3b (PPPoE identity)**, harness test 4 (`ADMIN_SYNC`, `PASSWORD_CHANGE`, `IDENTITY_EDIT` of the PPPoE username) | `DONE`. The password reached the real secret under the owned key; one secret, the same name |
| 8 | The permit ends after success and after failure | FC-3 and FC-3b (API dropped during the run → `REPROVISION_UNCONFIRMED` after ~60 s); harness test 4 | A direct call after each run is `RESELLER_OWNED_REFUSED` |

### 12.4 Data integrity of the other (reseller-owned) customer when refused (owner §4)

| Requirement | Evidence (after the fix) |
|---|---|
| Password not changed | FC-2 / FC-2c: `secret_unchanged = true`. The secret's `password`, `name`, `comment`, `profile`, `remote-address`, `service` and `disabled` are compared in memory and never recorded |
| Name, comment and profile not changed | same comparison; the Hotspot user is also unchanged (`hotspot_unchanged = true`) |
| No local secret masking a RADIUS subscriber | FC-2d: `local_secret_created = false`. The owned subscriber logged in with its **RADIUS** password and was seen by FreeRADIUS (`radius_login = true`, `seen_by_freeradius = true`) |
| No disconnect of the other customer | FC-2 (with `$isChangePlan` set) and FC-2c: `session_survived = true` (the same `.id` and address) |
| RADIUS policy not changed | FC-2 / FC-2c: all RADIUS tables byte-identical. FC-2d: `radcheck` / `radreply` / `radusergroup` identical. Harness: the owned policy rows and the whole policy tables identical, on both engines |
| Refusal recorded safely | FC-2: 17 `op=deviceGrant` log lines for 17 refused calls. FC-2c: 1 line. FC-2d: 2 lines. `log_has_no_secret = true` (neither the owned password nor the colliders' passwords appear). In CLI the refusal is a sanitized log line, and on web / API it is also audited (round 1, §6) |
| Other router objects | FC-2: `others_unchanged = true`. Every class run: `inventory_unchanged = true`, with the API user, group and router row removed |

### 12.5 Legacy recharge funnel: the remaining financial / operational effect (owner §4, stopped here)

Characterised on the CHR (FC-2c, after the fix). `Package::rechargeUser()` for the **unassigned** colliding customer:

| Item | Before | After | Note |
|---|---|---|---|
| `tbl_user_recharges` of the unassigned customer | 0 | **1, `status = 'on'`** | saved **before** the device call (`Package.php:366`; renew branch `:254`) |
| `tbl_transactions` of the unassigned customer | 0 | **1** (`INV-6`, plan `default`, price `10`, method `Recharge - S7`) | written **after** the device call (`:393` / `:283`) |
| Return value | — | the invoice (`INV-…`) | the caller reports success |
| Customer balance | 0.00 | 0.00 | this gateway (`Recharge`) debits nothing; a balance or paid gateway would carry its own payment |
| Device | — | refused (`op=deviceGrant`) | the exception is caught (`Package.php:379` / `:268`), reported (`package-activation`), and a "sync manually" operator notification is attempted |
| Router | — | no secret for the unassigned customer | the customer has an active plan and an invoice, but **no service** |
| Owned customer | — | records, secret, session and RADIUS unchanged | — |
| Reseller finance (wallets, ledger, reservations) | — | untouched | the funnel never touches reseller finance; the owned customer is refused at its first line (FC-4) |

**Assessment:**
- **Authority:** closed. The refusal protects the reseller customer.
- **Not fail-closed as a whole:** the legacy funnel follows its upstream "device failed → sync manually" contract. That is the same behaviour as for any device failure, such as a router being down.
- **Effect:** an unassigned customer whose key collides with a reseller customer's can be recharged and invoiced for a service it cannot receive. This touches only that unassigned customer's legacy records, never the reseller customer or reseller finance.
- **Not changed:** per owner §4, `Package.php` was not modified. A separate decision is requested (§14).

### 12.6 Focused harness after the fix (lab down), both engines

| Run | MariaDB 10.11 | MySQL 8.0 |
|---|---|---|
| `harness-postfix-focused.out`: `--testsuite unit,wp4-integration,wp5b-integration,wp8-integration` | **OK**: 307 tests, 4355 assertions, 0 failures, 0 errors | **OK**: 307 tests, 4355 assertions, 0 failures, 0 errors |
| `harness-postfix-sourceguards.out`: `--testsuite wp3a-unit,wp5b-unit` (the `unit` suite excludes these two directories) | **OK**: 91 tests, 38041 assertions | **OK**: 91 tests, 38041 assertions |

**Incomplete tests:** 94 per engine in the first run. They are the real-NAS lab tests, which mark themselves incomplete while the lab is down, and they are **not counted as passed**. The lab classes of this round ran on the CHR instead (§12.2, §12.3).

**Exit codes:** both runs exit 0, and the variant registry check reports "matches freshly built fixtures" in both.

**What these suites cover:**
- the new `DeviceGuardKeyCollisionTest`;
- the WP5B guard and permit tests (`LegacyReprovisionTest`, `PluginBypassTest`, `MikrotikDeviceTest`, `RenameIntegrityTest` and the other `Rename*` tests), whose owned customers have PPPoE usernames;
- the legacy Radius device lifecycle (`wp4-integration`);
- the source guards: `Wp5bSourceGuardTest` (default deny, private permit), the Wp3a SHA pins (`ServiceGate.php` / `RouteMap.php`), and `DriverClassificationSourceTest` T-S1…T-S4 plus `LegacyRemovalSourceTest` (in `unit`).

**Not re-run here (§5 continuation, stopped):** the full matrix with the tree frozen.

**Line endings:** the product file has 0 CR, the new and changed test files are LF without trailing whitespace, and `git diff --check` is clean.

### 12.7 Test-side corrections in this round (no product effect)

- **`Wp8DriverLabCase::localCustomer()` fixture bug:**
  - **Cause:** a trailing `//` comment written in round 1 swallowed `service_type`, `account_type`, `coordinates` and `status`.
  - **Why the tests still passed:** they ran on the schema defaults (`status` defaults to `Active`).
  - **Fix:** the fields are now set explicitly.
- **FC-2 rewritten:**
  - FC-2 now covers (a), (b), the variants, a live session and the VPN driver.
  - New: FC-2c (the funnel plus the residue characterisation) and FC-2d (RADIUS masking).
  - FC-3 is factored into a helper shared with the new FC-3b.
- **New test:** `tests/Integration/Wp8/DeviceGuardKeyCollisionTest.php` (in the harness, `wp8-integration` suite).
- **Kept as evidence:** `harness-prefix.out` (round-2 run 1), which stopped at the first assertion; run 2 merges the policy check into the same assertion to show the RADIUS overwrite. Nothing was retried to hide a result.

## 13. Open observations: severity and production impact (owner §6, none fixed in S7-F1)

| ID | What | Severity | Production readiness impact |
|---|---|---|---|
| **OBS-1 / L-A5-2** | Customer creation does not reject identities that collide across `username` / `pppoe_username`, including database-equal spellings (`CustomerService::create`, `customers.php` add) | **HIGH** | **Production blocker (unchanged).** After S7-F1 a collision can no longer hurt a reseller customer through a legacy device, but colliders still get refused grants with legacy records (§12.5), and colliding unassigned customers can still overwrite each other's device and RADIUS identities (upstream behaviour, outside reseller authority) |
| **OBS-S7-3 (new, §12.5)** | Legacy recharge funnel: the recharge row and invoice persist after a refused or failed device grant | **MEDIUM** (finance / operations of unassigned customers; no reseller finance) | Blocks S7 acceptance per owner §4. For production it is coupled to OBS-1: removing collisions removes the refusal case, while device failures keep the upstream "sync manually" path |
| **OBS-S7-4 (new, analysis only, not tested)** | The guard matches names with database comparison (case, accent, trailing space). `RadiusPolicyBuilder::key()` additionally **trims** the key (leading or other whitespace), so a row stored with `" key"` would be a different name for the guard but the same RADIUS key. **Not reachable through the product's input paths:** the admin add / edit filter the username (`alphanumeric`) and trim `pppoe_username`, and no other product writer stores them. Only a direct DB or plugin write could create it | **LOW** (Phase B, plugin / data authority) | Not a blocker. Candidate for Phase B, or for OBS-1 normalisation at creation |
| **OBS-S7-1** | Drivers ignore RouterOS `!trap`: a rejected grant is reported as success (DC-X3) | **MEDIUM** (reliability, truthful status; not authority) | Gates the legacy **MikroTik-API device** use in production. Not the RADIUS reseller path |
| **OBS-S7-2** | VPN `rmNat()` removes NAT rules by `to-addresses` (another rule with the same target would be deleted); rename leaves stale NAT comments | **MEDIUM** (could delete an unrelated NAT rule on a shared router) | Gates the legacy **VPN** device on shared production routers. Not the RADIUS reseller path |
| **OBS-W8-4** | RouterOS API: no TLS, no driver timeout (60 s hang, DC-X1), missing `:port` warning (DC-X2) | **MEDIUM** (credentials in clear outside a private path; operator-facing hangs) | Production requires the API on a private path (OD-W8-5) or API-SSL (WP9). A blocker for any API exposure beyond a private path |
| **OBS-W8-3** | Portal connect / disconnect calls methods PPPoE / VPN lack (fatal error if reached) | **LOW** | Not a blocker; reliability fix recommended |
| **OBS-W8-2** | `method_exists()` on a file path makes legacy syncs always `add_customer` | **LOW** (idempotent on the real router, DC-H2 / DC-P2) | Not a blocker |
| **OBS-W8-5** | A driver exception gives `REPROVISION_UNCONFIRMED` (no revert, no retry) | **LOW** (accepted design; confirmed on CHR, FC-3 / FC-3b) | Not a blocker |

## 14. Decision needed (OBS-S7-3, the legacy funnel residue)

| Option | Change | Note |
|---|---|---|
| **F-A (recommended)** | Pre-flight in `Package::rechargeUser()`: for a non-voucher grant, run the device guard (`LegacyReprovisionService::assertDeviceAllowed($c)`) **before** any recharge, transaction or customer write. A refusal returns `false` and is recorded (`ServiceGate::refused('rechargeUser', …)`), exactly like the existing WP2 gate at the top of the function | Small, test-first: FC-2c becomes the regression (no recharge row, no invoice, refusal recorded). Needs authorization for `Package.php`. Other device failures (router down) keep the upstream "sync manually" path |
| F-B | Accept the residue as upstream behaviour; record OBS-S7-3 under OBS-1 (creation-time uniqueness removes the colliding case) | No code; S7 could then be accepted with the note. The residue stays possible until OBS-1 is done |
| F-C | F-A now, plus OBS-1 creation-time uniqueness before production | — |

**My recommendation:** **F-C**:
1. **F-A now**, as a separately authorized, test-first round.
2. **Then complete S7:**
   - the focused CHR regression (`RealAaaTest`, `RouterOsClosureTest`, `AccountingTimeLabTest`, `RenameOldSessionLabTest`);
   - the full harness on MariaDB and MySQL with the tree frozen;
   - SourceGuard, manifest and secret sweep;
   - RouterOS inventory before and after;
   - cleanup and CHR stop.
3. **Keep** OBS-1 as a production blocker.

## 15. Round 2 state, files and verdict

| | |
|---|---|
| HEAD / origin | `f07917e9…` / `f07917e9…` (unchanged); nothing staged or committed; no push, merge or deployment; S8 not started |
| Modified (tracked) | `system/autoload/Reseller/Domain/LegacyReprovisionService.php` only (the authorized S7-F1 fix) |
| New (untracked) | round-1 files (§11), plus `tests/Integration/Wp8/DeviceGuardKeyCollisionTest.php` and `owner-review/WP8/evidence/s7/f1/` |
| Changed untracked (round-1 files) | `tests/Integration/Wp8/DriverFailClosedLabTest.php` (FC-2 / FC-2c / FC-2d / FC-3b), `tests/Support/Wp8DriverLabCase.php` (fixture fix), this report |
| Protected | `install/radius.sql`, `install/freeradius/*`, `ServiceGate.php`, `RouteMap.php`, `Package.php`, `system/devices/*`, controllers, migrations, schema, finance and accounting history: unchanged. A1 … A5 and Option G not touched or re-run |
| Manifest | **42 / 42** |
| Secret sweep | **0 hits** over 30 files. Checked: the lab secret values (6), private-key markers and the temporary API password format, across the S7 tests, tool, plan, this report, all of `evidence/s7/` and the changed product file. The only `"password"` keys in the evidence are the status `DONE` of the permitted run; no fixture password appears in the evidence |
| RouterOS inventory | identical before and after each of the 3 class runs of this round (FC pre-fix, FC post-fix, DC post-fix): `inventory_unchanged = true`; API user, group and router row removed and verified |
| Lab | `lab.sh down` exit 0: links 0, netns 0, qemu 0, agent 0, containers 0, networks 0 |
| Not run (stopped per owner §4) | the deferred focused CHR regression and the frozen full harness (owner §5) |

**Round 2 verdict: `S7_BLOCKED_WITH_EVIDENCE`.**
- **S7-F1 is fixed:** the authorized one-line guard change, proven test-first on the real CHR and on both engines.
- **No proven authority bypass remains** in the tested scope.
- **S7 stays blocked** by the legacy recharge funnel's residue (§12.5, OBS-S7-3). Per owner §4 it is documented and stopped, waiting for the decision in §14. No commit.

---

## 16. S7-FC Pre-Write Authority Refusal Remediation (round 3)

Owner decision: `OD-S7-FC-1 = APPROVED` / `S7_FC_PREWRITE_REFUSAL_APPROVED — IMPLEMENTATION_AUTHORIZED`. Evidence: `evidence/s7/fc/`. Backup taken before the round: `/srv/phpnuxbill/wp8-s7fc-backup-20261014T104956Z/`. (The unchanged originals of `Package.php` and the Wp3a guard were also copied into the round-2 backup folder.)

### 16.1 The change (`system/autoload/Package.php`, `rechargeUser()`; 13 lines added, none removed)

```php
if (!$isVoucher) {
    try {
        \Reseller\Domain\LegacyReprovisionService::assertDeviceAllowed($c);
    } catch (\Reseller\Radius\ProvisionFailure $e) {
        return false;
    }
}
```

**Where:**
- After the customer row (`$c`) and the plan (`$p`) are loaded, and after the `balance` / `Custom Balance` returns. Those top-ups never call a device and are unchanged.
- Before everything in the recharge cycle: the existing-recharge lookup, `run_hook("recharge_user")`, the recharge UPDATE (`$b->save()`) or INSERT (`$d->save()`), the device call, the `tbl_transactions` invoice, `recharge_user_finish` and `Message::sendTelegram` / `sendInvoice`.
- Only reads happen before this point: the bills and attributes lookups. `_alert` and the disabled-plan redirect are unchanged legacy behaviour and write nothing.

**What it uses:**
- The approved guard, unchanged. Since S7-F1 it checks the row's `username` **and** `pppoe_username` with `deviceOwned()`, the same query as before.
- No parallel ownership logic and no new query.

**Refusal record:** the guard itself records it (`ServiceGate::refused('deviceGrant', …)`): a sanitized log line in CLI, plus the audit row on web / API. That is exactly **one** record per refused call; the funnel adds none.

**Return contract:** `false`, the same value the existing WP2 gate at the top of the function returns for an owned customer. All three remaining callers already treat `false` as "not granted", so no fake success is possible:

| Caller | Handling of `false` (unchanged code) |
|---|---|
| `plan.php` `recharge-post` (admin, non-balance gateway) | `$nuxrGranted = (bool) …` → `r2(…, 'e', "Failed to recharge account")`; no invoice page, no log line |
| `LegacyCustomerBalanceService::purchase()` (balance purchase / gift / admin balance) | `$result === false` → the exactly-once **refund** of the HELD journal row (`REFUNDED`) |
| `VoucherRedemptionService::grant()` | `$result === false` → voucher released back to unused, status `NOT_GRANTED` |

No controller, return contract or finance service was changed.

**Not covered by the pre-check (deliberately):**
- **Voucher-only grants** (`Voucher`, `id_customer = 0`). The WP2 gate above already judges them with the same username / PPPoE-username lookup (`ServiceGate::isBlockedUsername`). Behaviour and tests are unchanged (§16.4, items 9 and 12).
- **Ordinary device failures after the authority check.** Examples are an unreachable API or a device error unrelated to ownership. They keep the WP4 order: the recharge is saved, the device fails, the error is reported and "sync manually" is sent. This is recorded as limitation **L-S7-FC-1** (§18).

### 16.2 Protected-file exception (`Package.php` SHA pin)

| | |
|---|---|
| Pin file | `tests/Unit/Wp3a/SourceGuardTest.php`: **only** the `system/autoload/Package.php` entry changed. Every other pin is byte-identical, the guard is not removed, disabled or bypassed, and the comment records the reason and the previous value |
| Previous SHA-256 | `e4225dd06c4e4b5e555f141f8319f9a2575c4d7461f96ff30dc52b35f1f36346` |
| New SHA-256 | `d4567f663193e16b6db7397ff94ed37c268274ebc5f6ce8a645af3c79fca2b85` (after reviewing the 13-line diff above) |
| Reason | owner decision OD-S7-FC-1, a limited exception for the pre-write refusal only |
| Other `Package.php` guards | WP4 `RadiusSourceGuardTest::testPackageCallsTheDeviceAfterSavingTheRecharge` (the save-before-device order) is **unchanged and passing**. S7 T-S3 (the call-site inventory and the WP2 gate before both device calls) is **unchanged and passing** |
| New pin | `DriverClassificationSourceTest::testTheRechargeFunnelRefusesBeforeAnyWrite`: the non-voucher `assertDeviceAllowed($c)` with catch → `return false` appears **exactly once**, after the balance returns and before the hook, both saves, the transaction, the device call and the messages. It is **mutation-checked** (`testThePreWriteCheckerDetectsMutations`): removed, refusal swallowed, moved after the hook, and scope widened to vouchers are each detected |

### 16.3 Test-first: the regression failed before the fix, for this exact cause

| Run | Before the fix (round-2 code) | After the fix |
|---|---|---|
| Harness `RechargePreWriteRefusalTest` (new; full application runtime, RADIUS enabled; MikroTik plans point at a closed local port), MariaDB and MySQL | `harness-prefix-run3.out`: the collision matrix fails on both engines. **24 of 24** colliding recharges returned an `INV-…`, wrote `tbl_user_recharges` and `tbl_transactions`, ran both recharge hooks, and sent the "sync manually" and success notifications. In the **renewal** cases the existing expiry `2031-01-01 10:00` was **moved** to the new period. The allowed and voucher / owned control tests passed | pass on both engines (§17) |
| CHR `DriverFailClosedLabTest::FC-2c` (PPPoE new, PPPoE renewal, VPN new; owned session live) | `FC-2c-prefix.out`: each call returned `INV-6` / `INV-7` / `INV-8`, ran both hooks, and wrote `tbl_user_recharges` and `tbl_transactions` | `FC-postfix-run1.out`: pass (§16.4) |

**Pre-fix runs that were not clean, kept as evidence (all test-side, none affecting the product):**
- `harness-prefix-run1-fatal.out`: my helper name `customer()` clashed with the base class.
- `harness-prefix-run2.out`: one allowed-path expectation was wrong. For a custom device name such as `Probe`, upstream `rechargeUser` keeps no recharge row (`Validator::containsKeyword`). The recharge-row and renewal assertions moved to the Radius plan. The legacy renewal sets the expiry by its own rule, so the test asserts "row rewritten" rather than an extension.

### 16.4 Owner test list (§4) after the fix

| # | Requirement | Where | Result |
|---|---|---|---|
| 1 | A legacy customer whose PPPoE username collides with a reseller customer's identity | harness A (12 cases), CHR FC-2c | refused before the cycle |
| 2 | Collision with the customer **username** and with the **PPPoE username** | harness A / B (PPPoE username = owned username; = owned PPPoE username) and C (username = owned username; = owned PPPoE username in upper case) | refused |
| 3 | Case, trailing space, collation | harness B: upper case, trailing space, accent (utf8mb4_general_ci), on MariaDB and MySQL | refused |
| 4 | MikroTik PPPoE, VPN and Radius plans (and Hotspot) | harness: `Radius` PPPoE, `MikrotikPppoe`, `MikrotikVpn`, `MikrotikHotspot`, plus an unguarded custom device (`Probe`), so the pre-check does not depend on the device's own guard. CHR: PPPoE and VPN | refused |
| 5 | An existing recharge (update) and a new recharge (insert) | harness A: no / active / expired previous recharge; CHR FC-2c: new and renewal | refused; rows unchanged |
| 6 | No new invoice or transaction | every refused case: `tbl_transactions` unchanged (whole-database checksum) | **0** |
| 7 | No recharge reactivated and no expiry changed | harness A `on` / `off`; CHR `pppoe_renewal`: the unassigned customer's recharge rows (plan, dates, expiry, status, method) are identical | unchanged |
| 8 | No RouterOS / RADIUS change and no disconnect | harness: no device call (the `Probe` device log is empty) and the RADIUS tables are inside the unchanged whole-database checksum. CHR: owned secret, Hotspot user, live session and RADIUS checksums are identical; no "sync manually" report (the device never ran) | unchanged |
| 9 | No recharge hook, no success notification | `recharge_user` / `recharge_user_finish` were observed by named probe hooks and both were empty; the Telegram / SMS probes were empty | none |
| 10 | One safe refusal record | harness: exactly 1 `op=deviceGrant` per call, and none of the owned or colliding passwords in the log. CHR: 1 per call, `log_has_no_secret = true` | 1 |
| 11 | A non-colliding legacy recharge works as before | harness: `Probe` (hooks, device call, invoice, notification) and `Radius` PPPoE (row saved, device provisions its own PPPoE key, renewal updates the same row). CHR: FC-1 and FC-4 positive controls, FC-2 positive control, DC 8 / 200 | unchanged |
| 12 | Voucher-only, Hotspot and other unaffected paths | harness: a fresh voucher-only code is granted; a voucher code equal to an owned key and the owned customer itself are refused by the **existing** WP2 gate (`op=rechargeUser`, no `op=deviceGrant`, no data change). CHR: FC-5 voucher-only, the DC Hotspot contracts | unchanged |
| 13 | An ordinary device failure after the authority check is unchanged | harness: a non-colliding `MikrotikPppoe` recharge against a closed port → `INV-…`, recharge row and transaction kept, hooks ran, "sync manually" sent, 0 `op=deviceGrant` | unchanged (L-S7-FC-1) |
| 14 | The permitted LegacyReprovisionService works and the permit ends after success and failure | CHR FC-3 (username identity) and FC-3b (PPPoE identity), including the API-drop failure; harness `DeviceGuardKeyCollisionTest` test 4 | `DONE`; `RESELLER_OWNED_REFUSED` after each run |

**Required collision outcome:** no service change, no financial mutation, no invoice, and no unauthorized RouterOS / RADIUS change. This is **proven on both engines and on the CHR**. The only record is the authorized refusal (a log line in CLI; on web / API, the `tbl_reseller_audit_events` row, which is the only table excluded from the data comparison besides `tbl_logs`).

**CHR evidence, FC-2c after the fix** (`s7fc-lab-evidence.jsonl`, 11:02:06), the same for each of the three calls `pppoe_new`, `pppoe_renewal` and `vpn_new`:
- `value = false`, `hooks = []`;
- `unassigned_records_unchanged = true`, `data_unchanged = []`;
- `refusals_logged = 1`, `device_failure_reported = 0`, `log_has_no_secret = true`;
- integrity: secret, Hotspot user, live session and RADIUS all unchanged.

### 16.5 WP4 contract (docs)

`docs/WP4_RADIUS_MODEL.md` §3 gains a "WP8 S7-FC clarification", and decision row Q5 is annotated:
- a confirmed ownership refusal is judged **before any billing write**;
- the save-before-device order for allowed grants is **unchanged**;
- later device failures are **unchanged** ("sync manually").

## 17. S7 completion (owner §6)

| Step | Evidence | Result |
|---|---|---|
| FC on the CHR (affected tests and the whole class) | `FC-postfix-run1.out` | **10 tests / 177 assertions, exit 0**. The class cleanup reports `inventory_unchanged = true` and the API user, group and router row removed |
| DC regression on the CHR | `DC-postfix-run1.out` | **8 tests / 200 assertions, exit 0**; class cleanup `inventory_unchanged = true` |
| Focused router regression (deferred since round 1) | `focused-router-regression-run1-no-chr2.out` (kept) | 15 tests, **1 failure**: `RenameOldSessionLabTest` C2 needs CHR2 (`c7 lease`), which plain `lab.sh up` does not start. Lab precondition, not a regression |
| | `lab-console.out` + `focused-router-regression-run2.out` | Same production-contract configuration as A4 (`option_g_gate on shipped`, `up-chr2`, `h2_gate setup`, `a2r_gate chr2 setup`, `h2b_gate site project`, all rc 0; site `project`, admission `READY`, 0 config errors). `RealAaaTest`, `RouterOsClosureTest`, `AccountingTimeLabTest`, `RenameOldSessionLabTest` and `RealLifecycleTest`: **18 tests / 418 assertions, exit 0** |
| RouterOS inventory | class cleanups (3 class runs this round) + `cleanup-list.out` | identical before and after each S7 class run; read-only post-run listing `listed: []` |
| Lab stop | `lab-console.out` | `h2_gate teardown` rc 0, `lab.sh down` rc 0: links 0, netns 0, qemu 0, agent 0, containers 0, networks 0; no `__pycache__` |
| Full harness, MariaDB and MySQL, tree frozen | `harness-full.out`, `tree-fingerprint-before.txt` / `-after.txt` | **MariaDB 10.11: OK, 1129 tests / 69 661 assertions; MySQL 8.0: OK, 1129 tests / 69 661 assertions; 0 failures, 0 errors, exit 0; variant registry "matches freshly built fixtures".** 104 incomplete per engine are the real-NAS lab tests (lab down), which are **not counted as passed**; they ran on the CHR above. Against A5 (1115 / 86 incomplete) there are +14 new tests (the S7 source pins and the two S7 harness regressions) and +18 incomplete (the S7 lab classes, DC 8 + FC 10). **Tree frozen:** `tree-fingerprint-before.txt` and `-after.txt` are identical (HEAD, `git diff HEAD` SHA-256, every modified and untracked file's SHA-256, and the whole `tests/` + `system/` + `dev/` tree hash) |
| SourceGuard | inside the full harness: Wp3a pins (with the one authorized `Package.php` value), Wp4 order guard, Wp5b default deny, S7 T-S1…T-S4, the new pre-write pin | **pass on both engines** (inside the 1129). The Wp3a pins changed only the authorized `Package.php` value (`git diff`: 2 lines removed, 4 added, all in that entry and its comment) |
| Manifest | `docs/claude-development-pack/MANIFEST.sha256` | **42 / 42** |
| Secret sweep | lab secret values, private-key markers, the API password format, over the S7 tests, tools, plan, report, all of `evidence/s7/` and the changed product, doc and guard files | **0 hits** over 49 files (6 lab secret values, private-key markers, the API password format). No fixture password (`Zz-…`, `Pw-…`, `Own-ppp-…`, `Free-…`, `Rad-…`, `Dev-…`) in any evidence file. The only `"password"` keys in the lab evidence are the status `DONE` of the permitted runs |
| A1 … A5 | not re-run from scratch; their tests are part of the full harness and the focused router regression | — |

## 18. Observations and limitations after round 3 (none fixed here)

| ID | Status after round 3 | Severity / production impact |
|---|---|---|
| **S7-F1** | **CLOSED (development)**: the guard checks `pppoe_username`; regression-proven (§12) | — |
| **OBS-S7-3** (funnel residue after a refused grant) | **CLOSED for the ownership-collision case**: refused before any write (§16) | — |
| **L-S7-FC-1 (new, documented limitation)** | An ordinary device failure after the authority check keeps the WP4 / upstream "billed, sync manually" result: recharge row and invoice kept, no service until synced (§16.4 #13). Unchanged by owner instruction | MEDIUM (operations of unassigned customers); production runbook item, not an authority issue |
| **OBS-1 / L-A5-2** | open: creation still allows colliding identities. After S7-F1 and S7-FC a collider is refused with **no** write, but it cannot be served either | **HIGH, production blocker (unchanged)** |
| **OBS-S7-4** | open (Phase B, analysis only): whitespace keys trimmed by `RadiusPolicyBuilder::key()` are outside the database comparison; not reachable through product input | LOW |
| **OBS-S7-1** | open: drivers ignore RouterOS `!trap` | MEDIUM; gates legacy MikroTik-API device use in production |
| **OBS-S7-2** | open: VPN `rmNat()` by address; stale NAT comments | MEDIUM; gates the legacy VPN device on shared routers |
| **OBS-W8-2 … OBS-W8-5** | open, unchanged (§13) | LOW / LOW / MEDIUM / LOW as classified in §13 |
| Other production blockers | P-A4-1 … P-A4-3, OBS-1 / L-A5-2: unchanged and recorded in `OWNER_DECISIONS.md` | — |

## 19. Round 3 state, files and verdict

| | |
|---|---|
| HEAD / origin | `f07917e9…` / `f07917e9…` (unchanged); nothing staged or committed; no push, merge or deployment; S8 not started |
| Modified (tracked) | `system/autoload/Reseller/Domain/LegacyReprovisionService.php` (S7-F1); `system/autoload/Package.php` (S7-FC); `tests/Unit/Wp3a/SourceGuardTest.php` (the `Package.php` pin only); `docs/WP4_RADIUS_MODEL.md` (S7-FC clarification) |
| New (untracked) | round-1 / round-2 files, plus `tests/Integration/Wp8/RechargePreWriteRefusalTest.php` and `owner-review/WP8/evidence/s7/fc/` |
| Changed untracked (earlier S7 files) | `tests/Integration/Wp8/DriverFailClosedLabTest.php` (FC-2c is now the S7-FC regression); `tests/Unit/Wp8/DriverClassificationSourceTest.php` (pre-write pin and its mutation check); this report |
| Not changed | schema, migrations, drivers (`system/devices/*`), `ServiceGate.php`, `RouteMap.php`, controllers, finance services, `install/radius.sql`, `install/freeradius/*`, accounting history, production |

**Verdict: `S7_READY_FOR_OWNER_ACCEPTANCE`.**

- **The authorized S7 criteria pass:**
  - classification T-S1 … T-S4 plus the new pre-write pin;
  - DC on the real CHR;
  - FC-1 … FC-8, including FC-2 / FC-2c / FC-2d / FC-3b;
  - local vs RADIUS separation;
  - the deferred focused router regression;
  - the frozen full harness on MariaDB and MySQL;
  - SourceGuard, manifest 42/42, secret sweep, inventory, cleanup and lab stop.
- **No proven authority bypass remains** in the tested scope (S7-F1 closed).
- **No unaccepted financial effect remains** in the tested collision scenarios: no recharge, invoice, hook, notification, device, RouterOS or RADIUS effect (OBS-S7-3 closed for this case).
- **Remaining items are documented and outside S7-FC by owner instruction:**
  - L-S7-FC-1: ordinary device failures keep "sync manually";
  - OBS-1 / L-A5-2 (production blocker);
  - OBS-S7-1, OBS-S7-2, OBS-S7-4, OBS-W8-2 … OBS-W8-5;
  - P-A4-1 … 3.

Stopped for owner review. No commit, push, merge or deployment; S8 not started.
