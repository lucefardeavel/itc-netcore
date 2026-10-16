# WP9 U0 final hardening — read-only reviews D-U0-R2 (plugin / direct-DB boundary) and D-U0-R3 (availability)

Owner decision: `WP9_U0_IMPLEMENTED — FINAL_HARDENING_AUTHORIZED — PRODUCTION_BLOCKED` (2026-10-16). Both reviews are
**read-only**: no schema, plugin architecture, index or WP10 work was done. Evidence levels: E1 code read, E4 test-proven.

## 1. D-U0-R2 — plugins, hooks and direct database writers

**Statement of the limit:** the shared identity lock and the ownership check are **application** controls. They protect
writers that go through the application code. They do **not** protect against code or accounts that write the database
directly. Nothing below claims otherwise.

| # | Path | Can it bypass the identity lock / ownership check? | Evidence | Severity |
|---|---|---|---|---|
| R2-1 | **Plugin files** `system/plugin/*.php` are `include`d by `init.php:111-119` on every request (web, API, CLI exec, cron), before RouteGuard / ApiGuard / RestGate (N-02); their errors are swallowed | **Yes** — a plugin runs with the application's DB connection (`ORM::get_db()`, raw PDO) and can INSERT / UPDATE `tbl_customers`, `tbl_voucher`, `radcheck` / `radreply` / `radusergroup` without `CustomerIdentity::hold()` | E1; **E4**: A1 race plugin (raw radcheck write during the grant), U0VoucherServiceTest bypass plugin (raw customer insert during the grant) | **HIGH** (if any untrusted / unreviewed plugin is installed) |
| R2-2 | **Hooks inside identity / RADIUS flows**: `recharge_user` (inside `Package::rechargeUser`, after the pre-write guard, before the device step), `add_customer` / `edit_customer` (before P1 / P4 checks), `register_user` (after the P2 insert), `create_voucher`, `delete_customer`, `delete_customer_active_plan`, `customer_activate_voucher`, `cronjob` | **Yes, for direct DB writes made from the hook.** Mitigated after this round: a duplicate created by a hook during a grant is **detected by the ownership check** before the RADIUS write, the write is refused and the voucher becomes `CLAIMED_REVIEW` (no phantom `GRANTED`) — E4 both engines | E1, E4 | MEDIUM (residual: the duplicate row itself remains; the survey finds it) |
| R2-3 | `run_hook()` (`system/autoload/Hookers.php:48-58`) runs **only the first** registered function of a hook and returns its result | not a bypass; a correctness / trust issue: a later plugin on the same hook silently never runs, an earlier one silently shadows it | E1, **E4** (found by U0VoucherServiceTest run 2) | LOW (upstream behaviour) — recorded |
| R2-4 | **Plugin installation**: `pluginmanager/dlinstall` downloads from the public PHPNuxBill plugin repository and `install` unpacks an uploaded ZIP into `system/plugin` | gate exists: `C::SYSTEM_CODE` (SuperAdmin-only code capability, RouteMap); after installation see R2-1 | E1 | HIGH impact, high privilege required |
| R2-5 | **Plugins calling application services** (`CustomerService::create`, `VoucherRedemptionService::*`, `LegacyReprovisionService::run`, device `add_customer` / `sync_customer` / `customerAddPlan` / `remove_customer`) | **No** — these entry points take the lock / run the ownership check themselves | E1 (+ the U0 / hardening suites exercise them) | — |
| R2-6 | **Plugins using `RadiusRepository` / `RadiusProvisioner` directly** (public classes) | **Yes** — the ownership check sits in the callers (Radius device, ActivationService), not in the repository | E1 | MEDIUM (requires code-level intent) |
| R2-7 | **Other database accounts** — operators, external tools, a FreeRADIUS SQL account with write grants (A2-R / AV-3 / LPDB) | **Yes** — any account with write privilege on these tables | E1 | HIGH until least-privilege accounts exist (WP10 LPDB) |

**Closure plan (needs separate owner decisions; not done here):**
1. **Plugin boundary policy** (OD-W9-9): an allow-list of reviewed plugins, `SYSTEM_CODE` holders only, no plugin install on production instances without review; consider disabling `dlinstall` on reseller installations.
2. **Database-level enforcement** for identities written outside the application: U3 (identity table with a UNIQUE canonical key, maintained in the writers' transactions) or U4 (triggers) — schema decisions (R0008), out of this round's authority.
3. **Least-privilege DB accounts** (WP10): the FreeRADIUS account read-only on policy tables; operator accounts audited.
4. **Detection meanwhile:** run `dev/wp9/identity_survey.sql` (read-only) on an approved copy before go-live and periodically; every multi-owner key is also refused at write time now (§2 of REPORT §FH).

**Status: D-U0-R2 OPEN** (production blocker as part of OBS-1 while plugins or other DB writers can exist).

## 2. D-U0-R3 — availability and performance (observational; WP10 owns load evidence)

| Item | Current state (after this round) | Risk | Owner |
|---|---|---|---|
| Global identity lock | one per database; held only for checks + one claim / insert / update; acquisitions wait 10 s (web) / 3 s (RADIUS voucher authorization); the new ownership check waits **3 s** on every path (it guards RADIUS writes) | serialisation of all acquisitions and now of every RADIUS policy write's ownership count; under contention → fail closed (refusal, nothing written) | WP10 load test; per-key locks (LK) only if metrics show contention (Architecture Lock TARGET §236) |
| Unindexed identity scans | `tbl_customers.username` / `pppoe_username`, `tbl_voucher.code`, `tbl_user_recharges.username` have no index; each acquisition scans them (2–4 statements), each ownership count scans them once more | linear growth: observed ≈12 → 56 ms (MariaDB) / 15 → 94 ms (MySQL) per check from 1 000 to 50 000 customers (U0PerfTest, single host, not a load test) | any index = schema decision |
| 1 000-candidate bound | more than 1 000 rows with edge whitespace (or matching a name) → "cannot check" → every acquisition AND every RADIUS write of that installation refused | **a legitimate installation with ≥ 1 001 legacy names with leading / trailing whitespace would stop provisioning** — run the survey (statement 3) first and clean the names (operator decision) | operator plan before go-live |
| Per-statement time budget | 2 s per lookup (MySQL `MAX_EXECUTION_TIME`, MariaDB `max_statement_time`) | a slow database refuses rather than waits (fail closed) | WP10 |
| Legitimate customers refused by fail-closed rules | (a) a name equal to a used voucher-only code; (b) a key with leftover RADIUS rows (e.g. a deleted customer's suspended rows); (c) **NEW:** grandfathered multi-owner keys — their legacy recharge, reseller activation, deactivation / expiry, rename and `customerAddPlan` are all refused until an operator resolves the duplicate; (d) the pre-write device guard judges BOTH names of a row, so a duplicate PPPoE name also blocks a Hotspot recharge of that customer (conservative) | availability for affected legacy customers; **expiry of a multi-owner key is refused** (the expired subscriber keeps the shared key's current policy until resolved) — deliberate: suspending would lock out the other owner | survey + operator resolution before go-live |

**Status: D-U0-R3 OPEN** for WP10 (no schema, index or load work in this round).
