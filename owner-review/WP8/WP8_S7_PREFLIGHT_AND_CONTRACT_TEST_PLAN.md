# WP8 S7 — Direct MikroTik Driver Classification & Fail-Closed Contract Tests: Preflight and Plan

| Item | Value |
|---|---|
| Date | 2026-10-14 |
| Stage | **S7** (Architecture Lock order D1: A1 → A2 → A3 → A4 → A5 → **S7** → S8; D5 "S7 not before A1, A2 and A3–A5" is satisfied) |
| Baseline | `wp8/integration-poc@f07917e9f976d2ec4cbd0b32fcb875a72be8d979` |
| Round | **Preflight and planning only.** No product code, schema, finance, accounting history, CHR or RouterOS change; no lab run; nothing on production; no commit, push, merge or deployment; S8 not started |
| Status | `S7_PLAN_READY_FOR_OWNER_APPROVAL` |

## 1. Baseline (verified, read-only)

| Check | Result |
|---|---|
| Branch / HEAD / origin | `wp8/integration-poc`; HEAD = `origin/wp8/integration-poc` = `f07917e9…` (A5 commit, parent `8ed4cb3d`) |
| Worktree | clean (`git status --porcelain` empty) |
| Inputs read | `PLAN.md` §22, §23, §23a, §27; `OWNER_DECISIONS.md` (OD-W8-2…9, R-WP8-CARRY-01 record, D1–D5, A3 / A4 / A5 records); Architecture Lock `IMPLEMENTATION_ROADMAP.md` (A6 "Continue S7/S8"; Phase B "plugin / direct-device authority red-team") |
| Code read | `system/devices/{MikrotikHotspot,MikrotikPppoe,MikrotikVpn}.php`; every device call site in `system/` (§3); `LegacyReprovisionService` (permit, `assertDeviceAllowed`, `devices()`); `ServiceGate`; `ActivationService`; `Package::rechargeUser`; `cron.php`; the static `system/autoload/Mikrotik.php` |
| Lab facts | **API reach:** the CHR API is restricted to `10.88.0.0/24` and the QEMU management forward (`configure_chr.py:60`); the lab runner shares the FreeRADIUS address `10.88.0.10`, so the application's PEAR2 drivers can reach `10.88.0.2:8728`. **Credential:** the runner has `NUXR_WP8_CHR_PASSWORD` in its environment (`compose.wp8.yml:63`). **AAA:** `/ppp aaa use-radius=yes`, and the Hotspot profile `nuxr_wp8_hs_prof` has `use-radius=yes`, so **local users are checked first, RADIUS only when no local user exists** (§5) |

**Use of the original plan:** `PLAN.md` §22 / §23 / §23a / §27 remain valid and are used as the base. This document does not rewrite them. It updates the references changed by A1–A5 (§2), fixes the scope of the real-router tests (§6–§7) and adds acceptance, cleanup and decisions.

## 2. What changed since `PLAN.md` (2026-10-06)

| Item in `PLAN.md` | Now |
|---|---|
| §22 "Class A is therefore empty" | **Still true.** `ActivationService` requires `plan.device === 'Radius'` (`ActivationService.php:97, 154, 234`) and reaches the network only through RADIUS rows and `CoAClient` |
| §22 line references | **Driver methods are unchanged:** H `add_customer:32` / `sync_customer:56` / `remove_customer:83` / `change_username:100`; P `30 / 93 / 100 / 126`; V `23 / 73 / 80 / 105`. **Controller call sites moved** after A3 / A5 edits (current lines in §3) |
| §22 Class B | Unchanged. `LegacyReprovisionService::devices()` builds the drivers (`:744`) with a private per-call permit (`:707`, cleared `:781`) |
| §23a **OBS-W8-1** (ungated `remove_customer` paths `plan.php:443`, `customers.php:286`, `home.php:206`) | **Closed for development by A5 (`f07917e9`, R-WP8-CARRY-01).** Now `plan.php:437` (+ D-A5-1 `:440` / `:447`), `customers.php:277` (+ `:280` / `:290`) and `home.php:202-203`. Voucher-only rows (`customer_id = 0`) can no longer be deleted through P1. The driver `remove_customer()` itself stays unguarded by design: it is the expiry path (P4) and the A5-gated controllers' device step |
| §23a OBS-W8-2 | **Still present**, moved to `plan.php:81`, `customers.php:338`, `home.php:97` (§8) |
| §23a OBS-W8-3 | **Still present**, moved to `home.php:236 / 239` (§8) |
| §23a OBS-W8-4 | **Still present** (§8) |
| §23a OBS-W8-5 | **Still present** (`LegacyReprovisionService.php:714-730`, §8) |
| A3 / A4 | **A3:** the RADIUS rename path (`Radius::change_username`) is not a MikroTik driver; only the R2 loop's call site moved (`customers.php:930`). **A4:** no driver impact |
| CHR | canonical CHR 7.24.5 lab (amendment W8-A2), not the 7.20.3 named in §22 / §27 |

## 3. Call-site and authority matrix (current code)

Drivers are instantiated dynamically (`new $p['device']`), so a call site can reach any driver of the plan's device. "Gate" is the authority check that runs **before** the call. The grant guard inside the drivers is `LegacyReprovisionService::assertDeviceAllowed`: it refuses a reseller-owned customer by **id or by username / PPPoE username** (`deviceOwned`), unless the service's private permit is set.

| # | Call site (current) | Method(s) | Actor / route | Gate before the call | Class |
|---|---|---|---|---|---|
| 1 | `LegacyReprovisionService.php:744` (`devices()`) | `add_customer`, `sync_customer`, `change_username` | the re-provision service (Admin identity edit / password, portal password / sync) | tree lock + ownership + per-call **permit** (`:707`) | **B** |
| 2 | `Package.php:264, 375` (`rechargeUser`) | `add_customer` | all legacy grant callers (recharge, voucher, order, cron renewal, extend) | `ServiceGate` at `:32` (id, or username for voucher mode) + driver guard | C |
| 3 | `plan.php:82 / 84` (bulk sync) | `sync_customer` / `add_customer` | Admin `plan/sync` | reseller-owned → `LegacyReprovisionService::run` (`:56-60`); else `isBlockedCustomerId` skip (`:68`) + driver guard | C (B for owned) |
| 4 | `plan.php:455` (P1 delete) | `remove_customer` | Admin | A5 `planDelete` `:437`; D-A5-1 `:440` / `:447` | C |
| 5 | `plan.php:516 / 526 / 537` (plan edit) | `remove_customer` / `add_customer` / `sync_customer` | Admin | `planEditPost` `:489` (+ driver guard on grants) | C |
| 6 | `plan.php:1188` (extend) | `add_customer` | Admin | `planExtend` `:1163` + driver guard | C |
| 7 | `customers.php:298` (P2 deactivate) | `remove_customer` | Admin | A5 `customerDeactivate` `:277`; D-A5-1 `:280` / `:290` | C |
| 8 | `customers.php:339 / 341` (sync) | `sync_customer` / `add_customer` | Admin | owned → service (`:322-323`); `customerSync` `:325` + driver guard | C (B for owned) |
| 9 | `customers.php:527` (P6 delete) | `remove_customer` | Admin | `CustomerService::assertDeletable` `:510` | C |
| 10 | `customers.php:930 / 932` (R2 identity edit, unassigned) | `change_username` / `add_customer` | Admin edit-post | owned → service (`:749-758`); `isBlockedCustomerId` `:752`; `renameCheck` `:779` (A3) + driver guard | C |
| 11 | `home.php:98 / 100` (portal sync) | `sync_customer` / `add_customer` | customer | owned → service (`:82-83`); `homeSync` `:85` + driver guard | C (B for owned) |
| 12 | `home.php:179` (portal extend) | `add_customer` | customer | `homeExtend` `:144` + driver guard | C |
| 13 | `home.php:209` (P3 self-deactivation) | `remove_customer` | customer | A5 `portalDeactivate` `:202-203` | C |
| 14 | `home.php:236 / 239` | `connect_customer` / `disconnect_customer` | customer (Hotspot login helper) | none (session action, no grant); **OBS-W8-3** | C |
| 15 | `accounts.php:74` (password change) | `add_customer` | customer | owned → service (`:34-42`); `accountsChangePassword` `:51` + driver guard | C (B for owned) |
| 16 | `login.php:107, 152` (voucher-only login) | `add_customer` (voucher array, `customer_id 0`) | public | `guardUsername` `loginVoucherOnly` `:85`; redemption via `VoucherRedemptionService` + driver guard (by username) | C |
| 17 | `login.php:200` (voucher activation) | `connect_customer` | public | `guardUsername` `loginVoucherActivation` `:187` | C |
| 18 | `cron.php:83` (expiry) | `remove_customer` | CLI | none needed: lifecycle end at the saved expiry (canonical). Reseller auto-renew refused `:107-110` | P4 (canonical) |
| 19 | `autoload.php:128, 161`; `autoload_user.php:27` | `online_customer` | staff / customer pages | read only | C (read) |
| 20 | `services.php` (plan definitions) | `add_plan` / `update_plan` / `remove_plan` | Admin `system.admin` | RouteMap; `PlanGuard` on delete | C (profiles only) |
| 21 | `pool.php:63, 76, 107, 142, 201` | `add_pool` / `update_pool` / `remove_pool` (PPPoE driver) | Admin | RouteMap | C (pools only) |
| 22 | `routers.php:86, 154` | `getClient` ("test connection") | Admin | RouteMap | C |

No other call site exists in `system/` or `radius.php` (exhaustive `grep` of `)->{method}(` and `new MikrotikX()`).

**Not in S7 (Phase B):** plugins and hooks (`system/plugin/*`), which may call devices directly. The Lock's "plugin / direct-device authority red-team" belongs to Phase B / WP9.

## 4. Driver classification matrix (updated `PLAN.md` §22)

| Method (H / P / V line) | Grant guard in the driver | Class | S7 treatment |
|---|---|---|---|
| `add_customer` (H:32, P:30, V:23) | **yes**, `assertDeviceAllowed($customer)` | **B** (#1) + **C** (#2, 3, 5, 6, 8, 10, 11, 12, 15, 16) | real-CHR contract on local identities (DC); refusal for owned (FC) |
| `sync_customer` (H:56, P:93, V:73) | **yes** | B + C (#3, 5, 8, 11); legacy callers reach it only through OBS-W8-2 (dead `method_exists` branch → `add_customer`) | DC; FC |
| `change_username` (H:100, P:126, V:105) | **yes**, `assertDeviceAllowed(null, $from, $to)` (both names) | B + C (#10) | DC (incl. the WP5B "wrong variable" fix on a real router); FC |
| `remove_customer` (H:83, P:100, V:80) | **no** (by design: the expiry path and the A5-gated controller step) | C (#4, 5, 7, 9, 13) + P4 (#18) | DC (expiry semantics incl. `plan_expired`); FC proves the A5 gates stop it before the driver |
| `online_customer` | n/a (read) | C (#19) | DC (read only) |
| `connect_customer` / `disconnect_customer` (Hotspot only; also Radius, RadiusRest, Dummy) | n/a | C (#14, 17) | DC (Hotspot, real client c2); OBS-W8-3 recorded for PPPoE / VPN |
| `add_plan` / `update_plan` / `remove_plan` | n/a | C (#20) admin-only | DC on profile `nuxr_wp8_local_prof*` |
| `add_pool` / `update_pool` / `remove_pool` (PPPoE; VPN variants unused) | n/a | C (#21) admin-only | DC on pool `nuxr_wp8_local_pool*` |
| `getClient` / `info` | n/a | internal + #22 | used by every DC test; transport and timeout observed (OBS-W8-4) |
| `setHotspotUser`, `setHotspotUserPackage`, `getIpHotspotUser` (×3), `add/removeIpFromAddressList`, VPN `addNat` / `rmNat` / `checkPort` / `checkIpAddr`, internal `add*User` / `remove*User` / `remove*Active` | internal or unused | **D** for the unused helpers (no caller outside the driver); internal helpers are covered through the public methods | no direct test; listed |
| **static class `system/autoload/Mikrotik.php`** | — | **D** (no caller outside the file) | listed; no removal in S7 |
| **Class A** (accepted activation → driver) | — | **empty** | source guard keeps it empty (T-S1) |

## 5. Separation of RADIUS users and local MikroTik users

**Fact (`PLAN.md` §7 D6; lab configuration):** RouterOS consults RADIUS only when **no local user** with that name exists, for PPP (`/ppp secret`) and Hotspot (`/ip hotspot user`). A local user with a RADIUS subscriber's name would **mask RADIUS authority**: no admission checks, no Option G / A2-R / H-2B, no accounting policy.

**Rules for S7 (to be enforced by the tests):**
1. **Naming:** every local object uses the prefix `nuxr_wp8_local_` (users, profiles, pools, comments). No local object may carry a name that exists in `radcheck` / `radusergroup` (checked before and after every test).
2. **Before / after inventory:** `/ppp secret`, `/ip hotspot user`, `/ppp profile`, `/ip hotspot user profile`, `/ip pool`, `/ppp active`, `/ip hotspot active` and `/ip firewall nat`, filtered to non-`nuxr_wp8_local_*` objects, must be identical. This covers every RADIUS subscriber and the lab's own objects.
3. **RADIUS untouched:** checksums of `radcheck`, `radreply`, `radusergroup` and `radacct` are byte-identical before and after each driver test. Drivers never touch RADIUS tables.
4. **Masking proof (real client):**
   - **Local:** a local PPPoE secret `nuxr_wp8_local_ppp_*` logs in through client `c1` with **no** Access-Request for that name in the FreeRADIUS log.
   - **RADIUS:** a RADIUS user still authenticates through RADIUS in the same run.
   - **Hotspot:** the same with client `c2` and a local Hotspot user.
5. **Leftovers:** a leftover local object with a RADIUS-like name is a **stop condition** (§10).

## 6. CHR contract tests (scope; executed only after approval)

**Target:** the isolated lab CHR 7.24.5 (`10.88.0.2`; CHR2 not needed). Real RouterOS API through the **application's** drivers and vendored PEAR2 client, from the lab runner, against **local identities only**.

**Test file:** `tests/Integration/Wp8/DriverContractLabTest.php`, suite `wp8-integration`. It is incomplete when the lab is down, never a pass.

| ID | Driver | Contract (each asserts the real RouterOS object state, then cleans up) |
|---|---|---|
| DC-H1 | Hotspot | `add_plan` creates user profile `nuxr_wp8_local_prof_h` with the plan rate; `update_plan` changes it; `remove_plan` removes it |
| DC-H2 | Hotspot | `add_customer` on an **unassigned** local customer creates `/ip hotspot user nuxr_wp8_local_hs_*` (password, profile); `sync_customer` updates it, idempotent |
| DC-H3 | Hotspot | `change_username` renames the existing user; the old name is gone (real-router variant of WP5B 4A) |
| DC-H4 | Hotspot | client `c2` logs in with the local user (no RADIUS request). `online_customer` reports it; `connect_customer` / `disconnect_customer` act on the real active entry |
| DC-H5 | Hotspot | `remove_customer` ends the active session and removes the user; with `plan_expired` it applies the restricted profile instead (expiry semantics) |
| DC-P1 | PPPoE | `add_plan` / `update_plan` / `remove_plan` on PPP profile `nuxr_wp8_local_prof_p`; `add_pool` / `update_pool` / `remove_pool` on `nuxr_wp8_local_pool` |
| DC-P2 | PPPoE | `add_customer` creates `/ppp secret nuxr_wp8_local_ppp_*`, including a static `remote-address`; `sync_customer` updates it; `change_username` renames it |
| DC-P3 | PPPoE | client `c1` dials with the local secret (no RADIUS request); `online_customer` true; `remove_customer` drops the active session and the secret, or applies `plan_expired` |
| DC-V1 | VPN | API-level only (OD-S7-2): `add_customer` creates the secret (service, profile) and its NAT rule(s) by comment; `change_username`; `remove_customer` removes both; no VPN client session |
| DC-X1 | all | `getClient` transport: plain API 8728 (OD-W8-5). **API unavailable** (lab firewall drops 8728 from the runner): the call fails, with the time to failure measured (OBS-W8-4: no explicit timeout), and no partial object is left |
| DC-X2 | all | `info()` with a router address **without** `:port`: the PHP 8.2 `Undefined array key 1` warning is recorded (OBS-W8-4); observation only |

**Credential (OD-S7-1, recommended A):**
- **The user:** each run creates a dedicated lab API user `nuxr_wp8_s7api`, in a group limited to `api,read,write`, with address `10.88.0.10/32` and a random password generated in memory. Teardown removes it.
- **How the drivers get it:** through a disposable `tbl_routers` row in the test's own application database.
- **Never stored or shown:** the password is never logged or written to evidence (redaction plus a secret sweep). The lab admin password is not placed in any database row.

## 7. Fail-closed scenarios (reseller-owned customers with **MikroTik-device** plans)

**Test file:** `tests/Integration/Wp8/DriverFailClosedLabTest.php` (lab).

**The fixture:** a reseller-owned customer created through the accepted services, with a legacy MikroTik plan (Hotspot / PPPoE) and an existing local router object that was created through the permitted path.

**Expected for every refusal:**
- the RouterOS inventory (§5.2) is unchanged;
- the RADIUS and application data are byte-identical;
- one `service.refused` audit is recorded;
- no partial router object is created.

**The positive control:** each FC case runs the same call for an **unassigned** local customer and shows the router object being created or changed. This makes "unchanged" discriminating.

| ID | Scenario | Expected |
|---|---|---|
| FC-1 | Direct driver grant: `add_customer`, `sync_customer`, `change_username` on H / P / V for a reseller-owned customer (by id) | `ProvisionFailure RESELLER_OWNED_REFUSED`; audit `deviceGrant`; no router write |
| FC-2 | **Username collision:** an unassigned row whose `username` / `pppoe_username` equals a reseller-owned customer's name (the driver guard's name check); `change_username` with an owned `$to` | refused by name; no router write. This is related to L-A5-2 / OBS-1 but tests only the **driver** guard; no fix of customer creation |
| FC-3 | **The permitted path works (Class B):** `LegacyReprovisionService::run` (password change and identity edit) for the same reseller-owned customer | **succeeds** on the real router (secret / user updated, name renamed), audited. The permit ends with the call: a direct driver call right after it is refused again |
| FC-4 | `Package::rechargeUser` (the funnel of every legacy grant caller, #2) for the reseller-owned customer with a MikroTik plan | `false` + `service.refused rechargeUser`; no router write, no recharge, transaction or balance change |
| FC-5 | Voucher-only grant path (#16) with a voucher code equal to a reseller-owned username | refused (`loginVoucherOnly` / redemption) before the driver; no router write |
| FC-6 | **Removal:** A5 P1 / P2 / P3 for the reseller-owned customer with a MikroTik plan (real router; one run per path; device-independent gates already proven in the harness) | refused before the driver; the local router object (secret / Hotspot user and active session) unchanged |
| FC-7 | **Canonical expiry (P4)** of the reseller-owned customer | allowed: the router applies `plan_expired` or removes the object exactly as DC-H5 / DC-P3. Expiry is not a bypass |
| FC-8 | **Bypass attempt through the permit:** a driver grant called from inside a hook or after a failed `run()` | refused: the permit is private, set and cleared only inside `run()` (WP5B source guard plus a real call) |

**Harness side (both engines, no lab):** `tests/Unit/Wp8/DriverClassificationSourceTest.php`.

| ID | Check |
|---|---|
| T-S1 | Class A stays empty (no device instantiation in `Reseller/Activation`) |
| T-S2 | Every `add_customer` / `sync_customer` / `change_username` in the three MikroTik drivers starts with `assertDeviceAllowed` |
| T-S3 | The §3 call-site list is pinned: a new call site fails the test until it is classified. Each listed call site has its gate earlier in the same branch (the A5 checker approach) |
| T-S4 | The static `Mikrotik` class has no caller |

All T-S checks are mutation-checked, like A5's T9.

## 8. Observations OBS-W8-2 … OBS-W8-5 (recorded; no fix in S7)

| ID | Current state | S7 action |
|---|---|---|
| OBS-W8-2 | `method_exists($dvc, 'sync_customer')` receives the **file path**, so legacy syncs always fall to `add_customer` (`plan.php:81`, `customers.php:338`, `home.php:97`). Grants stay guarded by `assertDeviceAllowed` | DC-H2 / DC-P2 prove `add_customer` is idempotent on a real router (so the dead branch is harmless) |
| OBS-W8-3 | `home.php:236 / 239` call `connect_customer` / `disconnect_customer` on any device; PPPoE and VPN drivers do not define them, which gives a fatal error | recorded; DC-H4 covers the Hotspot implementation; no fix |
| OBS-W8-4 | **Transport:** plain API, no TLS (OD-W8-5). **Timeout:** no explicit timeout (only `autoload.php` sets 5 s). **Stage check:** Hotspot compares `'Demo'`, PPPoE / VPN `'demo'`. **Address:** a missing `:port` gives a PHP warning | DC-X1 / DC-X2 measure and record; no fix (Phase B "RouterOS management transport review") |
| OBS-W8-5 | `LegacyReprovisionService` classifies any non-`ProvisionFailure` driver exception, and a failure after a first applied step, as UNKNOWN → `REPROVISION_UNCONFIRMED` (`:714-730`) | **FC-3b:** API unavailable during `run()` gives `REPROVISION_UNCONFIRMED` (no revert, no retry, drift reported); recorded as the accepted behaviour |

**INFO (outside S7, seen in passing):** `login.php:133` builds `whereRaw("BINARY code = '$voucher'")` from `Text::alphanumeric(..., "-_.,")`, which strips quotes, so it is not injectable as written. It belongs to the Phase B review.

## 9. Acceptance criteria and evidence

1. **Classification:** §3 and §4 are confirmed by T-S1…T-S4 on both engines (exit 0), with mutation checks.
2. **Contracts:** DC-* pass on the real CHR. Each asserts the real RouterOS state (API read-back), and the inventory outside `nuxr_wp8_local_*` is unchanged.
3. **Separation:** the masking proof of §5.4 holds (local login without a RADIUS request; a RADIUS user still via RADIUS); there are zero name overlaps with `radcheck`; and the RADIUS checksums are unchanged.
4. **Fail-closed:** FC-1…FC-8 behave as specified, each with its positive control. No router write and no data change on any refusal; the audit is present.
5. **Regression:**
   - the full harness on MariaDB and MySQL with the tree frozen, exit 0;
   - the focused lab regression `RealAaaTest`, `RouterOsClosureTest`, `AccountingTimeLabTest` and `RenameOldSessionLabTest` (only to show the CHR is still a clean RADIUS NAS after S7), exit 0.

   A1–A5 are not re-tested from scratch.
6. **Evidence** (`owner-review/WP8/evidence/s7/`):
   - the RouterOS read-backs, redacted (no passwords or secrets);
   - the before / after inventories;
   - the FreeRADIUS log excerpts for the masking proof;
   - the DC-X1 timings;
   - the run logs with real exit codes.

   Failed and aborted runs are kept. The S7 API password never leaves the runner process: the tests check every evidence record against it before writing. The secret sweep (lab secrets, private-key markers) shows 0 hits; manifest 42/42; protected-file and diff checks pass.
7. **Report:** `WP8_S7_IMPLEMENTATION_AND_TEST_REPORT.md`, verdict `READY_FOR_OWNER_ACCEPTANCE` or `BLOCKED_WITH_EVIDENCE`.

**Expected file scope (implementation):**
- **New tests:** `tests/Unit/Wp8/DriverClassificationSourceTest.php`, `tests/Integration/Wp8/DriverContractLabTest.php`, `tests/Integration/Wp8/DriverFailClosedLabTest.php`; possibly a small test-support helper (`tests/Support/Wp8DriverLab.php`).
- **Lab tool:** `dev/wp8/s7_cleanup.py` (read-then-delete of `nuxr_wp8_local_*` objects and the S7 API user).
- **Docs and evidence:** the WP8 docs, a model note in `docs/`, evidence and the report.
- **Product code: none planned.** If a test proves a real authority bypass in a driver path, S7 **stops** and presents it (no silent fix).

## 10. Risks and stop conditions

| ID | Risk | Mitigation / stop |
|---|---|---|
| R-S7-1 | A local object masks a RADIUS subscriber | prefix rule, `radcheck` overlap check before and after, inventory diff; **stop** on any overlap or leftover |
| R-S7-2 | Driver tests leave objects behind after a failure | `tearDown` deletion by exact name plus `s7_cleanup.py` (read-then-delete, prefix only); `lab.sh down` destroys the disposable CHR disk anyway |
| R-S7-3 | Credential exposure | dedicated random-password API user (OD-S7-1); the agent's redaction; secret sweep; never in evidence |
| R-S7-4 | API unavailability hangs a test (no driver timeout, OBS-W8-4) | DC-X1 bounded by the test (process timeout); measured, not fixed |
| R-S7-5 | VPN depth limited to API level | OD-S7-2; the VPN client session stays untested (residual) |
| R-S7-6 | Plugins and hooks calling devices directly | out of S7 (Phase B / WP9) |
| R-S7-7 | Driver-level `remove_customer` is unguarded | by design (expiry); every controller caller is gated (A5, P5, P6); T-S3 pins this. A future new caller fails T-S3 |
| R-S7-8 | L-A5-2 / OBS-1 identity collisions | FC-2 tests only the driver guard; the root cause stays a production blocker |
| **Stop** | **Conditions:** a real authority bypass; a RADIUS subscriber changed or masked; a lab object left with a non-prefixed name; any production or real-MikroTik access; a secret in evidence | **Action:** stop and report; no fix without approval |

## 11. Rollback and cleanup

**Per test:** delete the created objects by exact name (`nuxr_wp8_local_*`, the S7 API user and its group, NAT rules by comment), then re-read the inventory.

**Per run:**
1. `dev/wp8/s7_cleanup.py` lists and then deletes the prefixed objects only, and verifies none remain.
2. The inventory diff against the run's start is empty.
3. `dev/wp8/lab.sh down` (the CHR working disk is disposable, rebuilt from the verified image at the next `up`).

**Repository:** S7 adds tests, a lab script and docs only. Rollback = revert the S7 commit; there is no data, schema, configuration or production state.

**Real MikroTik / production:** never contacted.

## 12. New owner decisions (only those needed)

| ID | Question | Options | Recommendation |
|---|---|---|---|
| **OD-S7-1** | API credential the application drivers use against the lab CHR | **A:** a per-run lab API user `nuxr_wp8_s7api` (least privilege `api,read,write`, address `10.88.0.10/32`, random in-memory password), removed after the run. **B:** the lab admin password from the runner environment, written into the disposable `tbl_routers` row | **A** |
| **OD-S7-2** | Depth of the `MikrotikVpn` contract | **A:** API-level on the real CHR: objects, NAT rules and removal are verified; no VPN client session. **B:** add a VPN client (new client tooling, larger lab change) | **A** (VPN session is a residual) |

**Already settled, not re-asked:**
- OD-W8-5 (plain API inside the lab);
- OD-W8-9 / A5 (removal paths);
- D1 / D5 (order);
- plugins = Phase B;
- no product change in S7 (stop on a proven bypass).

**Next step after approval:**
1. T-S* first (harness).
2. The lab: `lab.sh up` → DC-* → FC-* → the focused lab regression → cleanup → `lab.sh down`.
3. The full harness on both engines with the tree frozen; security checks.
4. The report.
5. Stop for review. No commit before acceptance.
