# WP3B — Reseller pricing / commission / provider settlement

Status: **IMPLEMENTED_NOT_ACCEPTED**. WP3B builds on the accepted WP3A finance layer and the accepted schema
(R0001–R0006; no R0007, no trigger, no migration change). It has:
- no network provisioning, RADIUS or MikroTik;
- no ActivationService / activation state machine (WP5A);
- no legacy entrypoint closure (WP5B);
- no reseller voucher commerce;
- no pricing route, API or UI.

## 1. Components

| Class | Role |
|---|---|
| `Finance/PricingService` | The only writer of `tbl_reseller_plan_base_costs` and `tbl_manager_plan_prices`; authoritative resolution (`resolveInTx`), role-filtered `quote`, administrative `validateTree` |
| `Finance/PricingSnapshot` + `PricingLink` | The canonical, immutable internal pricing snapshot of one saleable (manager, plan) resolution |
| `Finance/LegacyRetailPrice` | Strict reader of the legacy default retail price `tbl_plans.price` |
| `Finance/PricingReadModel` | Role-filtered presentation (future-facing security contract for WP6B) |
| `Finance/PricingResult` | Outcome of one configuration mutation (changed / before / after / warnings) |
| `Finance/LedgerService::postActivationSettlement` | The single activation financial posting primitive (replaces `postActivationDebit`) |
| `Finance/SettlementFailure`, `PostedSettlement` | Typed settlement failure; settlement ledger result |
| `Domain/PlanGuard` | Safe legacy plan deletion (WP1 binding input) |

Accepted code changed (minimal):
- `Capability` / `RoleCapabilityMap`: the pricing matrix (§4).
- `DomainError`: the pricing / settlement reason codes.
- `SchemaReadiness`: the pricing tables are required.
- `LedgerService` / `ReservationService`: the settlement extension (§8).
- `system/controllers/services.php`: the four plan delete paths run their hook, device removal and delete through `PlanGuard::deleteSerialized` (§11).

Byte-identical and still pinned:
- R0001–R0006 and the migration runner;
- `ServiceGate`, `RouteMap`, `Tx`;
- `Balance.php`, `Package.php`.

## 2. Price domains (D009 / D010 / D011 / D023)
- **Provider base cost** (`tbl_reseller_plan_base_costs.base_cost`). This is the wholesale floor.
  - A plan without a base row is not resellable (`PLAN_NOT_RESELLABLE`).
  - `is_resale_enabled = 0` closes it for everyone (`PLAN_RESALE_DISABLED`).
  - A base row is created with resale disabled. Base rows are never deleted.
  - Base 0 is valid configuration.
- **Effective wholesale cost** of a reseller = its own override, else the nearest ancestor override, else the base cost.
  - For every parent P and child C: `E(C) ≥ E(P)`, and the top level is ≥ the base cost.
  - Admin/SuperAdmin set any reseller's override.
  - An Agent sets only its direct children's overrides, never its own (`SELF_PRICING_FORBIDDEN`).
  - Clearing an override returns to inheritance.
- **Retail** (customer-facing) is separate.
  - It is the reseller's explicit retail override, else the legacy default `tbl_plans.price` parsed strictly.
  - `+1000`, `0100`, spaces, separators, exponents, more than 16 integer digits and non-ASCII digits are refused as `LEGACY_RETAIL_INVALID`; values are never normalised.
  - `tbl_plans.price` is never written.
  - The legacy per-customer `Invoice` attribute, bills, tax and coupons are not part of reseller retail (WP5B).
- **Availability:**
  - no base row, or resale disabled → closed for everyone;
  - an explicit `0` anywhere on the path → closed for that subtree (`PLAN_DISABLED_BY_SELF` / `PLAN_DISABLED_BY_ANCESTOR`);
  - an explicit `1` never overrides an ancestor or global `0`;
  - `NULL` inherits.
- **Owner policies:**
  - Only `tbl_plans.type = 'Balance'` is excluded from reseller commerce (`BALANCE_PLAN_NOT_RESELLABLE`, F-4). These plans mint customer balance through `Package::rechargeBalance`. Hotspot, PPPOE and VPN plans are eligible.
  - A leaf wholesale of 0 is valid configuration but not activatable in V1 (`ZERO_WHOLESALE_NOT_SUPPORTED`, F-3). There is no zero-reservation / zero-ledger path; zero-cost activation is deferred policy.

## 3. Configuration mutations

Every mutation runs in this order:
1. capability check (WP2 `PermissionService`);
2. strict input (money strings only, the WP3A grammar; ids; optional reason 3–255 characters);
3. WP2 tree lock, then BEGIN (strict `sql_mode`);
4. actor revalidated;
5. base row `FOR UPDATE`;
6. the plan's override rows `FOR UPDATE` (ascending manager id);
7. authoritative validation;
8. write, then R0006 audit;
9. COMMIT.

Identical values are no-ops: nothing is written or audited. A row whose last override is cleared is deleted, because an all-NULL row must behave exactly like no row (WP1 binding input).

| Rule | Behaviour |
|---|---|
| Child below parent effective cost (or base at the top level) | `WHOLESALE_BELOW_PARENT`; equal is allowed |
| Upstream wholesale / base raise that would put an explicit descendant override below its new parent price | **rejected** (`WHOLESALE_DESCENDANT_CONFLICT` / `BASE_COST_DESCENDANT_CONFLICT`, OD-5). Only *newly introduced* violations count; violations created earlier by a WP2 hierarchy move fail closed at resolution (`PRICING_CHAIN_INVALID`) and never block unrelated or corrective changes. Admin can always fix bottom-up. Descendant prices are never rewritten. |
| Upstream raise that puts a descendant's retail below its wholesale | **allowed** (F-5). Stored retail is not rewritten and there is no downstream veto. The descendant becomes not saleable (`RETAIL_BELOW_WHOLESALE`) until corrected; affected ids are returned as warnings and audited (count + up to 50 ids). |
| Retail set below own wholesale | `RETAIL_BELOW_WHOLESALE` |
| Retail clear when the legacy default is invalid or below wholesale | `RETAIL_CLEAR_INVALID` |
| Target not an enrolled, consistent Agent | `PRICING_TARGET_INVALID` |
| Inactive / frozen target | Agent actor: `PRICING_TARGET_FROZEN`; Admin: allowed (configuration correction; resolution stays closed) |
| Frozen / inactive actor | `FORBIDDEN` (`PermissionService`) or `ACTOR_FROZEN` / `NOT_ENROLLED` (WP2 revalidation) |

## 4. Capability matrix (OD-3, OD-4)

| Capability | SuperAdmin | Admin | Agent | Sales | Report | Mutating | Schema-dependent |
|---|---|---|---|---|---|---|---|
| `pricing.read` | G | G | T | — | — |  | yes |
| `pricing.base_cost` | G | G | — | — | — | yes | yes |
| `pricing.child_wholesale` | G | G | C | — | — | yes | yes |
| `pricing.own_retail` | G | G | S | — | — | yes | yes |
| `pricing.child_availability` | G | G | C | — | — | yes | yes |

`RESERVED` now holds `activation.create` and `activation.read` only. `validateTree` requires scope G.

## 5. Resolution and saleability
`PricingService::resolveInTx(\PDO, manager, plan)`:
- is the internal API for WP5A and `reserveInTx`;
- has no capability of its own;
- asserts the caller's transaction and the tree lock.

The first reason, in this deterministic order, is thrown:
1. `NOT_FOUND`
2. `BALANCE_PLAN_NOT_RESELLABLE`
3. `PLAN_NOT_RESELLABLE`
4. `PLAN_RESALE_DISABLED`
5. leaf not a valid, Active reseller (`WALLET_OWNER_INVALID` / `WALLET_OWNER_FROZEN`)
6. `PLAN_DISABLED_BY_SELF` / `PLAN_DISABLED_BY_ANCESTOR`
7. `PRICING_CHAIN_INVALID`
8. `ZERO_WHOLESALE_NOT_SUPPORTED`
9. `LEGACY_RETAIL_INVALID`
10. `RETAIL_BELOW_WHOLESALE`

`quote` and `validateTree` (tree-locked reads) report every reason. `validateTree` also lists positive-margin commission recipients that have no wallet.

## 6. Internal snapshot (`pricing_chain_snapshot`, canonical JSON v1)
- **Fixed key order:** `v, plan_id, leaf_manager_id, resolved_at, provider{base_cost, resale_enabled, updated_by, updated_at}, chain[{manager_id, distance, effective_wholesale, source(OVERRIDE|INHERITED|BASE), source_manager_id, set_by, set_at, availability, margin}], retail{effective, source(OVERRIDE|LEGACY_DEFAULT), legacy_raw, set_by, set_at}, availability{effective, decided_by}, settlement{leaf_debit, commissions[{manager_id, amount}], provider_settlement, provider_margin_over_base}`.
- **Encoding:** money is always a `"d.dd"` string, ids are ints, timestamps are UTC microseconds. Flags are `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE`; there is no whitespace.
- **`plan_snapshot`:** selected `tbl_plans` columns as raw text. `on_login` / `on_logout` scripts and `price_old` are excluded.
- **Write-once:** WP5A writes the five activation columns (`wholesale_cost` = L, `retail_price`, `provider_settlement_amount` = P, `plan_snapshot`, `pricing_chain_snapshot`) in its single activation INSERT. No runtime `UPDATE`, upsert, delete or ORM path exists (source guard).
- **Decoding:** `fromActivationRow` requires byte-identical canonical JSON, re-verifies every invariant and cross-checks the columns. Any failure is `PRICING_SNAPSHOT_INVALID`.
- **Immutability:** historical snapshots survive later price, availability and hierarchy changes byte-identically.
- **Visibility:** role visibility is separate (`PricingReadModel`). Agents never see the base cost, the resale flag, ancestors or the provider settlement. The internal snapshot is never weakened.

## 7. Settlement math (Money, integer minor units, no float)

For a chain top-level → leaf with effective costs `c_1 … c_k`:

| Quantity | Formula |
|---|---|
| Leaf debit | `L = c_k` (> 0) |
| Provider/system settlement | `P = c_1` (not a wallet credit) |
| Margin of intermediary i | `m_i = c_{i+1} − c_i ≥ 0` |
| Conservation | `P + Σ m_i = L` exactly |

- The leaf never earns a margin.
- Only positive margins become `COMMISSION_CREDIT` entries; zero margins stay visible in the snapshot.
- `provider_margin_over_base = c_1 − base` is visible in the snapshot only.
- Settlement follows the snapshotted chain, not a later hierarchy move.

| Chain (base → top → … → leaf) | Leaf debit | Commissions | P |
|---|---|---|---|
| 10 → 12 | 12 | — | 12 |
| 10 → 12 → 15 | 15 | R1 3 | 12 |
| 10 → 12 → 15 → 17 | 17 | R2 2, R1 3 | 12 |
| 10 → 12 → (inherit 12) → 17 | 17 | R2 5 | 12 |
| 0 → 5 → 8 | 8 | R1 3 | 5 |
| 0 → (inherit 0) → 5 | 5 | R1 5 | 0 |

## 8. Settlement integration (owner decisions F-1, F-2, F-6, F-7)

**`reserveInTx`** (tree lock held, before provisioning) checks every deterministic precondition:
- **Activation snapshot:**
  - a sealed snapshot must be valid and equal a fresh `resolveInTx` (`PRICING_SNAPSHOT_STALE`), and the plan must be saleable;
  - a partial snapshot is `PRICING_SNAPSHOT_INVALID`;
  - `wholesale_cost` 0 is `ZERO_WHOLESALE_NOT_SUPPORTED`.
- **Wallets:**
  - the leaf and every positive-margin recipient are locked in ascending order **without creation**;
  - a missing recipient wallet is `COMMISSION_RECIPIENT_NO_WALLET` (WP3B never creates wallets; the WP3A closed policy is unchanged; zero-margin ancestors need no wallet);
  - a recipient without headroom is `COMMISSION_RECIPIENT_CAPACITY`;
  - then the leaf available-credit check.

**`commitInTx`** (no tree lock, after provisioning) follows the canonical lock order:
1. FK identity rows (`tbl_users` leaf + staff operator) `LOCK IN SHARE MODE`, ascending;
2. activation `FOR UPDATE`;
3. one ascending `lockMany` of the leaf and recipients (no creation);
4. reservation `FOR UPDATE`;
5. replay if COMMITTED;
6. `postActivationSettlement`;
7. status COMMITTED.

State can change between reserve and commit, so storage headroom and the leaf floor are re-checked under the final locks before the first write. Any failure rolls the whole settlement back:
- no partial debit, no partial commission, no lost commission, no silent free completion;
- the reservation stays ACTIVE.

**`SettlementFailure`** is thrown for a failure of the settlement unit:
- `kind` is `TRANSIENT`, `CAPACITY`, `INTEGRITY` or `UNKNOWN`;
- it carries the reason, reservation and activation ids, `effects = NONE`, the original message (sanitized) and the chained cause.

Error handling around it:
- Precondition refusals (`NOT_FOUND`, `RESERVATION_NOT_ACTIVE`, `SCHEMA_NOT_READY`) stay `DomainError`.
- Inside `commitInTx`, deadlock 1213 and lock wait 1205 are never wrapped, so the accepted `FinanceTx` whole-unit retry still works.
- `commit()` classifies the final outcome.

**Canonical lock order** for every WP3A / WP3B finance path:
1. tree lock (hierarchy-dependent writers only);
2. BEGIN;
3. identity rows (actor `FOR UPDATE`, or FK rows shared);
4. pricing configuration rows (pricing mutations only, never together with wallets);
5. idempotency / reference probe;
6. activation row (+ activation-scoped reservation probe);
7. wallet rows (one ascending lock);
8. manager-scoped reservation rows / reservation row / original transfer row;
9. writes;
10. audit;
11. COMMIT.

## 9. WP5A handoff contract
1. **One tree-locked transaction:**
   1. `revalidateActor`;
   2. `snap = PricingService::resolveInTx(pdo, manager, plan)`;
   3. INSERT the activation with `snap->activationColumns()` (the only activation INSERT; it names all five columns);
   4. `ReservationService::reserveInTx(pdo, activation, manager, L)`;
   5. WP5A state / event rows.
2. **Provisioning** happens outside any transaction.
3. **`ReservationService::commitInTx(pdo, reservation, actor)`** is the first locking work of WP5A's state-transition transaction. Debit, commissions and reservation status commit atomically with WP5A's FINANCIAL event and COMPLETED transition. On a `SettlementFailure` (or `SettlementFailure::classify`), WP5A owns retry or RECONCILIATION_REQUIRED.
4. **Plan-reference serialization (shared contract with safe plan deletion, §11):** every activation INSERT, which creates the `plan_id` / `previous_plan_id` references, must happen inside the same tree-locked transaction that resolves the plan (step 1). `PlanGuard::deleteSerialized` holds that lock from its final reference check through the hook, the device removal and the delete commit, so an activation can never reference a plan whose delete has already been decided. WP5A must also add a deterministic race test, activation creation vs plan delete, mirroring `PlanDeleteRaceTest`.
5. **WP5A must never:**
   - create a `tbl_plans` reference (activation, pricing or any new table) outside the reseller tree lock;
   - write ledger, wallet or pricing tables;
   - update the snapshot columns;
   - create an activation without a snapshot;
   - use `quote()` output as authority.

   It owns refund / reversal of a settled activation and UNKNOWN reconciliation.

## 10. Audit (R0006, same transaction)
- **Events:**
  - `pricing.base_cost_set`
  - `pricing.resale_enable` / `pricing.resale_disable`
  - `pricing.wholesale_set` / `pricing.wholesale_clear`
  - `pricing.retail_set` / `pricing.retail_clear`
  - `pricing.availability_set` / `pricing.availability_clear`
  - `plan.delete_refused`
- **Targets:** `plan` targets carry `{plan_id, name_plan, type}`. `pricing` targets carry `target_user_id` = the reseller and an identity snapshot plus the plan identity.
- **Payload:** before / after contain only the changed override `{value, set_by, set_at}`, the optional reason and any unsaleable-descendant warnings. Snapshots are never copied.
- **Not audited:** reads, resolution and settlement (the ledger is the financial truth).

## 11. Safe plan deletion (WP1 binding input; serialized since owner code review fix round 2)
- The four legacy delete paths (`delete`, `pppoe-delete`, `balance-delete`, `vpn-delete`) pass their whole legacy delete to `PlanGuard::deleteSerialized(planId, closure)`: the hook, the router / RADIUS removal and `$d->delete()`, unchanged and in the legacy order. No hook, device call or delete exists outside that closure.
- **One unit, in this order:**
  1. `Tx::withTreeLock`: the reseller tree lock (`GET_LOCK`, 10 s) is taken **before** the final check and held until the delete has committed or rolled back. It is the existing lock: every pricing writer (`PricingService`, all `FinanceTx::run(…, true)`) holds it while it creates a base-cost or override reference, and WP5A must hold it for activation references (§9). No new lock exists.
  2. BEGIN. `SELECT id FROM tbl_plans WHERE id = ? FOR UPDATE`, the plan row lock, taken before the check. This is a database-level backstop for any writer that does not hold the tree lock. Its foreign-key check needs a shared lock on the plan row, so the writer's reference either committed before this point (and is seen) or waits for the unit and then fails because the plan is missing (1452). The pricing writers never reach that point, because the tree lock serializes them first.
  3. Final reference check (schema-driven, every FK to `tbl_plans.id`). If referenced → refused: no hook, no device call, no database change, a `plan.delete_refused` audit, a clear message.
  4. The legacy session `sql_mode` is restored for the legacy code. Then the hook, the device removal and the row delete run.
  5. COMMIT, then the tree lock is released (`finally`).
- **Failure / timeout policy:**
  - tree lock not obtained within 10 s → `LOCK_TIMEOUT` ("The hierarchy is busy; please try again."), nothing done;
  - any error before the effects → fail closed (REFERENCED refusal, nothing done);
  - a foreign-key refusal during the effects (1451; impossible for in-application writers now) → REFERENCED message and the database part rolled back;
  - any other error during the effects is rethrown unchanged, as in the legacy code.
- **Lock hold time:** the tree lock is held during the device call. A tree-locked operation, such as a pricing or finance write, that waits longer than its 10 s timeout fails closed with `LOCK_TIMEOUT` and can be retried. It never becomes inconsistent.
- **Not compensated:** device state is never recreated after the fact. The serialization makes the reference race impossible instead.
- **Residual:** an infrastructure failure, such as a lost database connection, between a successful device removal and the commit can still leave the device ahead of the database. This is the legacy ordering (device first) and has nothing to do with references.
- Unreferenced plans and unmigrated installations (no reseller tables, no FKs) are deleted as before.

## 12. Source guards (`tests/Unit/Wp3b/PricingSourceGuardTest.php`; the WP3A guards stay in force)
- Only `PricingService` writes the pricing tables. Its only DELETE is the all-NULL override row. There are no upserts, ORM access, TRUNCATE / ALTER / DROP / LOAD DATA, and base rows are never deleted.
- Activation snapshots are write-once:
  - only approved files reference `tbl_reseller_activations`;
  - no UPDATE names a snapshot column;
  - any activation INSERT must seal all five columns;
  - no delete, upsert or ORM access.
- `ACTIVATION_DEBIT` / `COMMISSION_CREDIT` appear only in `LedgerService`, and `postActivationDebit` no longer exists. `postActivationSettlement` takes only ids, an actor and locked wallets, and only `ReservationService` calls it.
- Pricing code writes no money, legacy price or customer table and calls no finance writer. `PlanGuard` is SELECT-only, apart from its `FOR UPDATE` row lock.
- No controller / API / plugin / cron / template reaches pricing or settlement, and no route or `API_ALLOWED` key is about pricing.
- The four delete paths run the hook, device call and delete only inside `PlanGuard::deleteSerialized`, which takes the tree lock, then the plan row lock, then the check, then the effects; no unserialized pre-check remains; CRLF is preserved.
- Plan-reference writers: the only runtime INSERT into a plan-referencing table is in `PricingService`, and every `PricingService` transaction holds the tree lock. WP5A's ActivationService must join under the same contract.

## 13. Known residuals
- **Recipient overflow at commit:** a recipient balance can exceed the money range between reserve and commit. This is the only post-delivery capacity refusal; it is atomic and typed, and is resolved through WP5A reconciliation and an administrative fix.
- **Hierarchy moves** (WP2 `moveSubtree`, unchanged) can create wholesale-chain violations. They fail closed and are listed by `validateTree`.
- **Global tree-lock serialisation** covers pricing mutations, resolution and quotes. WP9 should measure throughput.
- **Legacy customer-charge composition** (per-customer `Invoice` attribute, bills, float tax, coupons including the unvalidated client `discount`) is outside reseller retail and belongs to WP5B. `Package.php:69` can zero a legacy price; the affected legacy-default resellers then fail closed.
- **Snapshot and configuration immutability** is application-level. WP9 must red-team the tamper surfaces, and WP10 must provide least-privilege database accounts.
- **Refund / reversal** of a settled activation (debit plus commissions) belongs to WP5A/WP5B.
- **Ledger visibility after a move:** old ancestors keep historical commission rows whose counterparty may now be outside their scope. This is accepted as historical truth.

## WP6B amendment (owner decisions OD-4, OD-9)

- Bounded pricing reads for the pricing pages: `PricingService::catalogForManager` / `matrixForPlan` / `baseCosts` /
  `relation` (25 plans / 50 managers per page, one tree lock per page, rows projected through `PricingReadModel`).
  `PricingSourceGuardTest` admits the exact `pricing` / finance-report routes and the read-only display files
  (`LedgerReadModel`, `SettlementReadModel`, `Ui/Format`); `Finance/SettlementReadModel` (SELECT-only) joins the
  activation-table allow-list. No pricing rule changed.
