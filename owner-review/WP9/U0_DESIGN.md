# WP9 — U0 Shared Identity Lock: Architecture Audit and Design (2026-10-15)

| Item | Value |
|---|---|
| Owner decision | `U0_SHARED_IDENTITY_LOCK_DESIGN_AUTHORIZED — IMPLEMENTATION_BLOCKED` (OD-W9-A1-2) |
| Basis | PLAN §7.1 (I-1…I-5), §7.3 (AM-W9-1 lock order L-1…L-4, rules R1–R4, P1–P5 sequences), §7.7 (AM-W9-3 voucher namespace), REPORT §T1-A, §A1 |
| Code base read | `wp9/red-team-rc` @ `0f181323ad12220dcd6e584f98d3a6652ee2d824` (A1 fix committed) |
| Method | read-only code reading. No test, container, database, RADIUS, CHR or product change in this study |
| Status | **design only; U0 NOT implemented**. V1 `MITIGATED_NOT_CLOSED`; OBS-1 and the race stay **PRODUCTION BLOCKERS** |

The approved AM-W9-1 design is **kept**: the global order L-1 tree → L-2 voucher username → L-3 identity → L-4 `BEGIN`, named locks only outside a transaction, commit before releasing L-3, and the P1–P5 sequences of PLAN §7.3. This document adds only what the audit found missing (§2) and the smallest contract that closes races A, B and C (§4).

---

## 1. Identity writer / authority matrix

Legend:
- **Acquirer:** makes a name *become* an identity. Each acquirer must hold L-3 and run `assertAvailable`.
- **Releaser:** makes a name stop being an identity.
- **Key writer:** writes RADIUS or device rows under an identity it already holds.

### 1.1 Identity acquirers (must take L-3)
| # | Writer (entry points) | Who can call it | Names acquired | Today: check / lock | Required under U0 | Effect of a refusal |
|---|---|---|---|---|---|---|
| **P1** | `CustomerService::create` (`customers.php:602` add-post; also the API and PWA through the same route) | SA / Admin (G), Agent (T), Sales (A) | `username`, `pppoe_username` | `username = ? FOR UPDATE` only, not cross-field (`CustomerService.php:65`). Order: L-1 → `BEGIN` (`Tx::withTreeLock`) | L-1 → L-3 → `BEGIN` → `assertAvailable(both names)` → INSERT → COMMIT (as in §7.3) | `USERNAME_TAKEN` / `IDENTITY_TAKEN`; transaction rolled back; no row, no audit `customer.create` |
| **P2** | `register.php` post (public) | unauthenticated, behind optional OTP | `username` (`alphanumeric '+_.@-'`) | `where('username')` only (`:84`), ORM autocommit insert (`:89-96`), no lock | L-3 → `assertAvailable` → `$d->save()` → release. The photo update afterwards stays outside the lock | "Account already exists" (same message as today); no row |
| **P3** | `VoucherRedemptionService::redeemForNewUsername` (`login.php:221`) | public, holding a valid voucher | new `username` | L-2 (`nuxr_user_*`, `strtolower` only) → check `username = ? OR pppoe_username = ?` → `claim()` → `Tx::run(INSERT)`. The grant runs after L-2 is released | L-2 → L-3 → `assertAvailable` → `claim()` → `Tx::run(INSERT)` → release L-3 → release L-2 → grant (unchanged) | `USERNAME_TAKEN`; the voucher is never claimed (it stays `0`) |
| **P4** | `customers.php` edit-post, unassigned customer (`:662-951`) | Admin / staff with customer edit | changed `username` / `pppoe_username` | column-equality checks (`:716-722`), `nameTakenByOther` (`:775`), `renameCheck` (`:779`; RADIUS DB), `$c->save()` (`:857`, ORM autocommit). RADIUS / device renames run **after** the save (`:905-941`) with no lock | early checks unchanged (they give the messages); then **only** L-3 → `assertAvailable(changed names, self excluded)` → `$c->save()` → release. Device steps stay after the release | message, no save; nothing is written, so nothing needs reverting |
| **P5** | `LegacyReprovisionService::run` IDENTITY_EDIT (`customers.php:758`), reseller-owned customer | staff inside the tree scope | changed names | inside L-1 (`TreeSerialization::hold`): `validate()` (column equality), `renameCheck()` (RADIUS), `Tx::run(write)`. Device steps run under L-1 | inside L-1: `validate` / `renameCheck` as today, then L-3 → `Tx::run(assertAvailable + write)` → release L-3 → device steps (§7.3) | `USERNAME_TAKEN` / `PPPOE_USERNAME_TAKEN` / `IDENTITY_TAKEN`; refusal audit as today |
| **P5r** | P5 **revert** (`LegacyReprovisionService.php:151`; old names written back after a confirmed device failure) | (inside P5) | the old names | `Tx::run(write(new→old))` with no identity check | L-3 → `assertAvailable(old names, self excluded)` → `Tx::run(write)`. If an old name was taken in the meantime: no revert, `REPROVISION_UNCONFIRMED` (§7.3) | stays `REPROVISION_UNCONFIRMED` for an operator, never a blind revert |
| **P6** *(new in this audit)* | **voucher-only redemption** `VoucherRedemptionService::redeem(code, voucherOnly)`. Public login `login.php:135` (E12) and rlm_rest authorize `radius.php:163` (E15) | public / RADIUS client | the **code** as a RADIUS identity | A1 check (`identityInUse`) **outside any lock**, then `claim()`, then the grant. **This is the A1 race** | L-3 → `assertAvailable(code)` → `claim()` (`0 → CLAIMED`, autocommit) → release L-3 → grant **outside** the lock (unchanged) | `REFUSED` (A1 behaviour); the voucher stays `0`; nothing is written |

### 1.2 Releasers (no L-3 needed; releasing a name cannot create a collision)
| Writer | Note for U0 |
|---|---|
| P4 / P5 old names: the customer row stops holding the old name at commit | The **old RADIUS rows stay until the device rename runs** (P4 `:925-932`, after the save with no lock; P5 under L-1 only). This is the transition gap G3 (§2) |
| `customers.php` delete (`:499`), `plan.php` / `home.php` / `customers.php` deactivate (`remove_customer`) | They remove or deactivate RADIUS / device rows of a key. With colliding (grandfathered) identities they hit the other holder — pre-existing; the read-only survey (PLAN §7.4) covers it |
| `cron.php:66-83` expiry, also for voucher-only recharges (`$c = $u`, key = the code) | Same: it deactivates the key of the voucher identity |
| voucher release `CLAIMED → 0` (`grant()` on `rechargeUser === false`, P3 removal) | Releases the reservation of the code; no lock needed |

### 1.3 Key writers (write only under an identity they hold; no L-3)
| Writer | Note |
|---|---|
| `Package::rechargeUser` for customer id > 0: admin recharge (unassigned only), customer purchase, gateway, customer-path voucher (E2 / E7) | Writes the customer's own RADIUS key; safe once I-1 holds. **Pinned**, not changed |
| `ActivationService` (owned customers, L-1) | Same; the reverse-direction risk exists only with colliding identities (deferred characterisation) |
| P6 grant (`Package::rechargeUser(0, …, 'Voucher', code)`) | Writes the key the voucher reserved in its L-3 section (claim = reservation) |
| `plan.php` voucher-post (**P7**, A1) | Creates **unused** codes, which are not identities (§4.4). It keeps the A1 best-effort regeneration and takes no lock |

### 1.4 Not coverable by application locks (residual, recorded)
- Plugins (`register_hook('add_customer'|'edit_customer'|'recharge_user', …)`), direct DB writes and operators. The A1 race test's barrier is exactly such a direct write. Only U3 / U4 (schema / triggers) cover these; separate decision, PLAN §7.5.
- MikroTik router namespaces (hotspot users / PPP secrets) for non-RADIUS plans: L-3 serialises the application identity, but stale router objects are not read under it.

---

## 2. Gaps of the approved design found by this audit

| ID | Gap | Evidence | Consequence for U0 |
|---|---|---|---|
| **G1** | The voucher-only redemption (P6) is not in the AM-W9-1 writer list; it acquires the code as a RADIUS identity with no shared lock | REPORT §A1.6 RACE_REMAINS (both engines) | P6 joins L-3 (race A) |
| **G2** | `assertAvailable` (PLAN §7.3) checks customers only; vouchers stay outside I-1 under AM-W9-3 | V2 TEST-PROVEN (§T1-A.4) | Extend the check to **live voucher identities** (race B) — needs **D-U0-1** |
| **G3** | **Rename-away transition:** after P4 / P5 commit a rename X→Y, X is free in `tbl_customers` but the RADIUS rows of X still exist until the device rename runs (P4: after the save, unlocked; P5: after L-3 release). Sequence: an acquirer (P6 voucher X, or P1 / P2 X followed by activation) writes X rows → the pending rename then moves those rows to Y | code reading (`customers.php:857` save, then `:925-932` rename; `LegacyReprovisionService.php:122-131`); **not tested** | Under L-3, acquirers must also refuse while the canonical key still has RADIUS rows they do not own (§4.2 c). That is a bounded RADIUS-DB read inside L-3, which amends R3 → **D-U0-2** |
| **G4** | The §7.3 `assertAvailable` sketch uses column equality; T1-A proved that misses stored leading whitespace / TAB | REPORT "OBS-1 design check", §T1-A.6 | Use the A1 canonical comparison (`customerIdentityCollisions`), moved into `CustomerIdentity` |
| **G5** | Two voucher-only redemptions of different vouchers whose codes are equal under the collation (race C; overlaps V4) | static; V4 deferred | Covered by G2's voucher check (another voucher's `CLAIMED` / used code); tested only as a U0 concurrency case (**D-U0-6**) |
| **G6** | The A1 race test injects the concurrent customer by raw SQL in a plugin hook, i.e. a non-application writer | `A1VoucherIdentityGuardTest::RACE_PLUGIN` | After U0 it still shows RACE_REMAINS by design (§1.4). U0 needs barrier tests with **real** writers; the raw-SQL case is kept as the documented residual |
| **G7** | Wp5bSourceGuardTest pins the P4 identity-write shape (`if (!$resellerOwned) { if ($userDiff) { $c->username = …`) and "only the service changes a voucher status" | `tests/Unit/Wp5b/Wp5bSourceGuardTest.php:63-77, 125-131` | Wrap only `$c->save()`, keep the assignment block verbatim; the claim stays in `VoucherRedemptionService`. No guard change expected (if one is needed: stop and ask) |
| **G8** | Extending P2 (public, unauthenticated) to voucher identities turns registration into a yes / no probe for live voucher-only identities, whose RADIUS password **is** the code | static reasoning | Trade-off between closing V2 through P2 and adding a guessing channel → **D-U0-4** |

---

## 3. Canonical identity rule (unchanged from A1, applied to every acquirer)

- **Canonical key:** `trim()` as PHP does it (space, TAB, LF, CR, NUL, VT) — exactly `RadiusPolicyBuilder::key()`.
- **Comparison:** `CONVERT(x USING utf8mb4) COLLATE utf8mb4_general_ci`. That is case- and accent-insensitive, and matches the `radcheck.username` collation. Values travel as hex, so no connection-charset effect.
- **`username`:**
  - always a name;
  - new values are already restricted by the writers (`alphanumeric` + length);
  - stored legacy values may carry edge whitespace and are canonicalised in the comparison.
- **`pppoe_username`:**
  - a name only when its canonical form is not `''` (I-3);
  - new values are `trim()`med;
  - I-5 rules (≤ 32 / key rules, refused, never truncated).
- **Same customer:** `username` and `pppoe_username` may be equal (I-2). On a rename the customer's own row is excluded (I-4 self-exclusion; this also fixes the P4 false refusal of a case-only self-rename).
- **Voucher-only identity:** the canonical code.
- **Grandfathering (I-4):** existing duplicates are not rejected on edits of other fields; only acquired names are checked.
- **Bounds:** at most 1000 candidate rows per statement (the A1 bound, an **open availability limit**, not a solution). An empty canonical name, any error, or more candidates than the bound → `CHECK_UNAVAILABLE` → refusal (fail closed).

---

## 4. Shared identity lock contract (U0)

### 4.1 Primitive: `Reseller\Domain\CustomerIdentity` (new, unpinned file)
- **`hold(callable $fn, int $timeout = 10)`:**
  - `GET_LOCK('nuxr_identity_' . sha1(DATABASE()))` — the same per-database naming as `TreeSerialization::name`;
  - **throws `LogicException` if a transaction is open** (R2 enforced in code);
  - `LOCK_TIMEOUT` (`DomainError`) when the lock is not obtained: nothing is written;
  - `RELEASE_LOCK` in `finally`;
  - re-entrant per session.
- **`assertAvailable(\PDO $pdo, array $names, ?int $selfCustomerId = null, ?int $selfVoucherId = null)`:**
  - callable only while `IS_USED_LOCK(name) = CONNECTION_ID()`, else `LogicException`;
  - throws `DomainError` `IDENTITY_TAKEN` / `CHECK_UNAVAILABLE`, carrying an internal reason that is never shown to public callers.
- **Lock release on abnormal exit:** the `finally` block; and on process death the connection closes. Connections are **not** persistent (`init.php:91-94`, no `ATTR_PERSISTENT`), so MariaDB / MySQL free named locks at disconnect.

### 4.2 What `assertAvailable` checks, for each canonical name *n*
- **(a) Customers:** *n* equals the canonical `username` or `pppoe_username` of any customer other than `$selfCustomerId`. This is the A1 statements, moved.
- **(b) Live voucher identities (D-U0-1, D-U0-3):**
  - **Vouchers:** any `tbl_voucher` row, other than `$selfVoucherId`, whose canonical code equals *n* and whose status is `CLAIMED` (in flight or `CLAIMED_REVIEW`), or `1` with `user = '0'` (a granted voucher-only identity);
  - **Recharge rows:** any `tbl_user_recharges` row with `customer_id = 0` and canonical `username` = *n* (voucher-only service rows, including pre-fork data).
  - **Unused codes (`0`) are NOT identities.** That avoids a code-enumeration oracle; a later collision with an unused code is refused at redemption by (a).
- **(c) RADIUS presence (D-U0-2):** any `radcheck` / `radreply` / `radusergroup` row whose `username` equals *n* under the collation (indexed lookups on the `radius` connection), except the acquirer's own current keys (renames).
  - **Closes G3:** a key stays unavailable until its old holder's rows are renamed or removed;
  - **Legacy rows:** RADIUS rows with edge whitespace are not scanned per request; the read-only survey covers them (PLAN §7.4, extended to the RADIUS tables);
  - **On error** → `CHECK_UNAVAILABLE`.

### 4.3 Per-writer sequences (lock order L-1 → L-2 → L-3 → L-4; R1, R2 and R4 unchanged; **R3 amended** per D-U0-2: under L-3 only bounded, read-only, single-statement RADIUS-DB lookups are allowed — never a RADIUS write, device call or network call)
| Writer | Sequence |
|---|---|
| P1 | `TreeSerialization::hold` → `CustomerIdentity::hold` → `Tx::run` [`revalidateActor`, owner / sales checks, `assertAvailable(u, p)`, INSERT, fields, audit] → COMMIT → release L-3 → release L-1 |
| P2 | `CustomerIdentity::hold` [`assertAvailable(u)`, `$d->save()`] → release; photo update / OTP cleanup after |
| P3 | `withUsernameLock` (L-2) → `CustomerIdentity::hold` [`assertAvailable(u)`, `claim()`, `Tx::run(INSERT)`] → release L-3 → release L-2 → `grant()` (unchanged). The provisional-row removal path only deletes, so it needs no L-3 |
| P4 | early checks (messages) → `CustomerIdentity::hold` [`assertAvailable(changed, self)`, `$c->save()`] → release → custom fields → device steps (unchanged) |
| P5 / P5r | as §1.1 row P5, plus the revert under L-3 with `assertAvailable(old, self)` |
| P6 | `reseller()` check (unchanged, V3) → `CustomerIdentity::hold($fn, E15 ? 3 : 10)` [`assertAvailable(code, selfVoucher = the claimed id)`; `claim()`] → release → `grant()` outside the lock (unchanged: `GRANTED` / `NOT_GRANTED` release / `CLAIMED_REVIEW`). The A1 pre-claim check folds into this section |
| P7 | unchanged A1 regeneration (no lock); a collision created later is refused at P6 by (a) |

### 4.4 Why this closes A, B and C (interleavings)
- **A — voucher grant vs customer creation / identity edit:**
  - **P6 first:** its `CLAIMED` row commits before L-3 is released, so a later P1–P5 sees (b) and refuses during the whole grant and after it (`1`, `user='0'`, plus the recharge row).
  - **Customer first:** its commit precedes the release, so P6 sees (a) and refuses.
  - **Rename-away:** a customer renaming X→Y still has X rows until the device rename, so P6 sees (c) and refuses (G3).
- **B — customer acquisition vs an existing or in-flight voucher identity:** (b) covers `CLAIMED`, granted voucher-only codes and `customer_id = 0` recharges; (c) covers the RADIUS rows of the key.
- **C — two grants or two writers:**
  - **Same voucher:** the existing atomic claim decides.
  - **Collation-equal codes of two vouchers:** the second P6 sees the first's `CLAIMED` row in (b).
  - **Two customer writers:** serialised by L-3, and each reads committed data. P1 / P3 / P5 start `BEGIN` after L-3, so the snapshot is taken after the lock. P2 / P4 / P6 are autocommit statements.
- **Not closed:** writers that bypass the application (§1.4); grandfathered duplicates and stale legacy RADIUS rows, which need the survey and an operator plan.

### 4.5 Timeout, deadlock, rollback
- **Timeout:**
  - L-3 `GET_LOCK` waits up to 10 s on web paths, and up to 3 s on E15 (D-U0-5; it must stay below the RADIUS client timeout);
  - expiry → `LOCK_TIMEOUT`, nothing written; a voucher stays `0`;
  - E15 answers *activation failed* and the client may retry.
- **Deadlock between named locks:**
  - every path acquires in the order L-1 → L-2 → L-3 and never requests a lower level while holding a higher one;
  - P6 takes L-3 only;
  - MariaDB has no user-lock deadlock detection, so the order is mandatory;
  - MySQL's `ER_USER_LOCK_DEADLOCK` is caught as a failure (fail closed).
- **Deadlock between named locks and row locks:** R2 is enforced by `hold()`, so no session waits for L-3 while holding InnoDB row locks.
- **P1's `FOR UPDATE` table scan** stays (minimal change). It runs inside L-3, so no identity writer waits on it while holding a row lock.
- **Rollback:**
  - transactional writers roll back in `Tx::run` before L-3 is released;
  - autocommit writers (P2 / P4 / P6) write exactly one identity statement inside L-3. For P6 that is the claim; a grant failure afterwards follows the unchanged WP5B release / review rules.
- **Partial states:** no partial grant, and no partial customer creation.
- **Release after success or failure:** `finally`, plus disconnect at process end.

---

## 5. Option comparison (only where a decision is needed)

| Topic | Options | Recommendation |
|---|---|---|
| Lock granularity | **LG** one global L-3 (approved AM-W9-1)<br>**LK** one lock per canonical key (`WEIGHT_STRING(… COLLATE utf8mb4_general_ci)`), several locks taken in sorted order | **LG** for U0: approved, simplest, and the proof is trivial. LK only if WP10 shows contention; its weight semantics are E3 on both engines |
| Closing race A | **O1** reservation by the claim inside L-3 + (b)<br>**O2** hold L-3 across the grant (breaks R3: device / MikroTik / RADIUS writes under a global lock; RADIUS-authorize latency)<br>**O3** U3 identity table / **O4** U4 triggers (schema; separate decisions) | **O1** |
| Rename transition (G3) | **Ra** RADIUS presence check (c) inside L-3 (amends R3 to allow bounded reads)<br>**Rb** hold L-3 across the RADIUS rename (P4 has no lock today; P5 mixes MikroTik steps)<br>**Rc** accept and document the residual | **Ra** |
| Voucher-identity lifetime (b) | **T1** permanent for any granted voucher-only code / `customer_id = 0` recharge<br>**T2** only while a recharge is `on` and RADIUS rows exist | **T1** (codes are random; legacy username-based lookups would otherwise mix histories) |
| P2 and voucher identities (G8) | **Q1** check (b) / (c) in P2 with the existing generic message<br>**Q2** check customers only in P2; V2 stays open for public registration | **Q1**, plus a rate-limit review of `register` (WP9-D / WP10; existence not verified in this study) |

---

## 6. Phased implementation and test plan (test-first; every phase needs its own authorization)

**Fixtures and environment, for every phase:**
- disposable databases through `dev/wp9/t1a_run.sh` (`mariadb` 10.11 + `mysql` 8.0, tmpfs, throwaway credentials);
- the harness app copy and synthetic `w9u0…` names;
- RADIUS tables only: no FreeRADIUS process, CHR or MikroTik;
- no SQL exhaustion, pool pressure or connection-fault injection;
- real parallel processes through `AppServer::execStart` / `execFinish`;
- barriers through a test-only seam in `CustomerIdentity`, a no-op unless a test hook is set, like `ActivationFaults::hit`;
- an external `GET_LOCK` holder on L-3 for the timeout tests (D-U0-7).

| Phase | Content | Tests (both engines; RR, plus RC where marked) | Stop points |
|---|---|---|---|
| **U0-0** pre-fix characterisation | Re-prove the races with **real** writers before any code change | P6×P1, P6×P2, P6×P4, P6×P5 (barrier inside the voucher grant); rename-away P4 X→Y × P6 X (G3); P1×P2, P1×P3, P2×P3, P4×P1, P5×P1 (barrier before commit); P6×P6 collation-equal codes (G5, D-U0-6) | STOP on any **new** impactful defect beyond the expected races (e.g. a finance write, an invoice, or a mutation of a third identity) |
| **U0-1** primitive | `CustomerIdentity::hold` / `assertAvailable` (a)(b)(c) | `hold()` inside a transaction → `LogicException`; timeout → `LOCK_TIMEOUT`, no row; re-entrant / nested under L-1 and L-2; release on exception and on process exit; canonical matrix (case, accent, leading / trailing whitespace, TAB, cross-field, self-exclusion, grandfathered duplicates); bound overflow and check error → fail closed | STOP if `GET_LOCK` re-entrancy or release differs between engines |
| **U0-2** P6 | wire voucher-only redemption | A1 suite unchanged and green; race A with real P1 / P2 / P4 / P5 → refused on one side, never both granted; replay / `CLAIMED_REVIEW` / `NOT_GRANTED` release unchanged; no invoice or RADIUS mutation on refusal; E15 timeout path | STOP if a WP5B voucher contract test changes |
| **U0-3** P1, P2, P3 | wire creators | creation vs redemption (both orders); creation vs a live voucher identity (V2 → refused); P3 unchanged contract; legacy positive controls (distinct names created; I-2 same-customer equal names) | STOP if any WP2 tree-lock test changes |
| **U0-4** P4, P5, P5r | wire renames | rename vs redemption; rename-away × acquisition (G3); case-only self-rename now allowed; P5 revert under L-3 and the no-revert path → `REPROVISION_UNCONFIRMED`; device steps outside L-3 | STOP if a Wp5bSourceGuard / WP8 A3 rename guard needs changing |
| **U0-5** closure evidence | full matrix after the fix | every U0-0 race closed on both engines (RR and RC); the A1 raw-SQL barrier still reports the documented residual (§1.4); regression harness (the A1 suite set + `wp2-integration`, `wp3a-integration`, `wp5a`); SourceGuard 23 pins; manifest 42/42; L-3 wait / hold timings observed (single process; **no load test**, WP10) | STOP if any refusal leaves a business / finance / identity / RADIUS delta (AM-W9-2) |

How the owner's list maps onto the phases:

| Owner's test | Phases |
|---|---|
| Creation vs redemption | U0-0, U0-2, U0-3 |
| Rename vs redemption | U0-0, U0-4 |
| Multiple redemptions | U0-0, U0-2 |
| Cross-field collisions | U0-1, U0-3 |
| Unicode / case / accent / whitespace | U0-1 |
| Timeout / deadlock / rollback | U0-1, U0-4 |
| Voucher replay / claim state | U0-2 |
| No invoice / no unintended RADIUS mutation | every phase |
| Legacy positive controls | U0-2 … U0-4 |
| Performance and operating limits | U0-5, observational only |

---

## 7. Impact assessment

| Area | Impact |
|---|---|
| Protected files | **none changed**: `Tx.php`, `ServiceGate.php`, `Package.php`, `RouteMap.php`, `Balance.php`, migrations / framework (all 23 pins), Architecture Lock folder. No re-pin |
| Files to change (implementation phase, not now) | new `system/autoload/Reseller/Domain/CustomerIdentity.php`; `CustomerService.php` (P1); `system/controllers/register.php` (P2); `VoucherRedemptionService.php` (P3, P6; `customerIdentityCollisions` moves into `CustomerIdentity`); `system/controllers/customers.php` edit-post (P4, save only); `LegacyReprovisionService.php` (P5 / P5r); `system/controllers/plan.php` (P7 call site only); new tests under `tests/Integration/Wp9/` |
| Schema / migrations | **none** (U0 is application-level; U1–U4 remain separate decisions) |
| Finance | no new writes. Refusals happen before the claim / insert, so there is no invoice, transaction or wallet effect. Grant / settlement paths are unchanged |
| RADIUS | no new RADIUS writes. (c) adds bounded indexed reads on the `radius` connection inside L-3 (D-U0-2) |
| Availability (no figures; unmeasured) | All identity acquisitions are serialised by one global lock. Each L-3 section scans the unindexed `tbl_customers` (2 statements), `tbl_voucher` and `tbl_user_recharges` (`customer_id = 0`) and does 3 indexed RADIUS reads. Voucher-only RADIUS authorize (E15) now waits on that lock. The 1000-candidate bound stays an open limit. Throughput / latency belong to WP10 |
| Behaviour changes for legitimate users | case-only self-rename becomes possible (P4 fix); a name equal to a used voucher-only code or to a key with leftover RADIUS rows is refused (fail closed; operator clean-up); public registration refuses such names with the existing generic message (D-U0-4) |
| Source guards | `Wp5bSourceGuardTest` shapes kept (G7); no SourceGuard pin touched |

---

## 8. New owner decisions required (only these)

| ID | Decision | Recommendation |
|---|---|---|
| **D-U0-1** | Widen I-1 (AM-W9-3) so that live **voucher-only identities** are part of the shared identity namespace checked by every acquirer (§4.2 b) | Yes. Without it, races A and B cannot be closed |
| **D-U0-2** | Amend rule **R3** so that bounded, read-only, single-statement RADIUS-DB lookups (c) are allowed inside L-3, to close the rename-away gap G3 | Yes (option Ra) |
| **D-U0-3** | Lifetime of a voucher-only identity reservation | T1 (permanent) |
| **D-U0-4** | Public registration (P2) also refuses names held by voucher identities / RADIUS rows (with the generic message), or not | Q1 (refuse), plus a later registration rate-limit review |
| **D-U0-5** | L-3 timeouts: 10 s for web paths, 3 s for E15 RADIUS authorize | as proposed |
| **D-U0-6** | Allow the collation-equal two-voucher concurrency case (overlaps the deferred V4) inside the U0 test phases | Yes, as a U0 concurrency case only; the V4 characterisation stays deferred |
| **D-U0-7** | Allow, in U0 tests, a second DB session that holds L-3 (`GET_LOCK`) to prove `LOCK_TIMEOUT`. It is a named-lock holder, not SQL exhaustion, pool pressure or a connection fault | Yes |
| **D-U0-8** | Authorize phase **U0-0** (pre-fix characterisation with real writers), then U0-1…U0-5 one at a time | Owner's call |

**`WP9_U0_DESIGN_READY_FOR_OWNER_REVIEW`** — U0 not implemented — `PRODUCTION_NOT_AUTHORIZED`.
