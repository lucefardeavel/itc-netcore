# WP5A — Activation Core: model and contracts

- **Base:** `feat/reseller-system-v1` at `0c080a7a` (WP4 accepted at `58e3e458` plus the owner metadata commit).
- **Scope:** the persistent, authoritative activation state machine for the **new / manual reseller flow** (STAFF_WEB): NEW, RENEW, CHANGE_PLAN and REACTIVATE for reseller-owned customers.
- **Not in WP5A:** legacy entrypoint closure (cron auto-renew, customer order and balance, payment callbacks, API, vouchers, plugins) is WP5B. The activation UX is WP6A; reconciliation views are WP6B.
- **No schema change:** no R0007; R0001–R0006 are byte-identical.
- **Owner fix round 1** (after review of candidate `608260d9`): actor eligibility and the per-context plan catalog (§7), and reconciliation split by durable history with a captured prior state (§6). See `owner-review/WP5A/REPORT.md` §L.
- **ServiceGate is unchanged.** Legacy service granting for reseller-owned customers stays blocked; WP5A never calls `Package::rechargeUser`.

## 1. Components (`system/autoload/Reseller/Activation/`, namespace `Reseller\Activation`)

| Class | Responsibility |
|---|---|
| `ActivationState` | The 15 states and **the only transition table** (R0003 stores the states but does not enforce the transitions). |
| `ActivationService` | `create()`: open, provision, verify and commit, or replay. `execute()`: continue from a safe state. `status()`, `preview()`. |
| `ActivationStore` | The single sealed INSERT, guarded transitions, append-only events, the frozen period. |
| `ServicePeriod` | C14 expiry rules with the legacy validity-unit arithmetic. |
| `LegacyBridge` | The `tbl_user_recharges` and `tbl_transactions` rows written at COMPLETED. |
| `ReconciliationService` | reconcile (split by durable history, §6), rollback to the captured prior state, cancel, and a stale-activation report. |
| `RadiusState` | The canonical projection of the RADIUS rows an activation's provisioning owns or changes; password stored only as a salted digest (§6). |
| `ActivationActor` | Staff or system actor, mapped onto the finance actor. |
| `ActivationFaults` | Test-only failure points. |

**Entry points**
- Minimal staff routes: `activation/create` (GET form and preview), `activation/create-post`, `activation/view/<ref>`, `activation/reconcile/<ref>`, `activation/rollback/<ref>` (`system/controllers/activation.php`, two plain templates).
- The CLI tool `system/reseller/reconcile.php` (SYSTEM actor).
- The bare `activation` route and unknown actions are the read action (`activation.read`). The guard and the controller agree, so a principal holding only `activation.read` never reaches the create form.
- No API route.

## 2. Flow

| Phase | Transaction | What happens |
|---|---|---|
| 1. Open | **one `FinanceTx::run(…, true)`** (tree lock; WP3B §9) | idempotency probe (replay) → `revalidateActor` → customer scope and eligibility → in-flight guard → server-derived action and C14 period → `resolveInTx(owner, plan)` → **the single sealed INSERT** (CREATED) → VALIDATED (period frozen in the event metadata) → `reserveInTx` → FUNDS_RESERVED |
| 2. Provision | none around the RADIUS call; each step is its own short transaction | The prior RADIUS state (first attempt) and the exact target are captured into the PROVISIONING event **before** any write; if the prior state cannot be read, provisioning does not start (FUNDS_RESERVED, `RADIUS_READ_FAILED`; resume or cancel). Then plan group and user policy (`RadiusProvisioner`) → PROVISIONED → VERIFYING → the whole projection must equal the captured target → VERIFIED. CHANGE_PLAN and REACTIVATE also end the current sessions (CoA, best effort; an ANNOTATION event records it). |
| 3. Commit | FINANCIAL_COMMITTING, then **one `FinanceTx::run(…, false)`** | `commitInTx` first → `LegacyBridge` rows → COMPLETED with a FINANCIAL event and the `legacy_*` columns, all atomic |

Any refusal in phase 1 writes nothing: scope, eligibility, the WP3B pricing reasons, insufficient credit, recipient wallet or capacity, stale snapshot.

**Outcome mapping (WP4 §10; D017; 18_BUSINESS_RULES)**

| Situation | State | Reservation | Money |
|---|---|---|---|
| RADIUS FAILED, not retryable | FAILED | RELEASED (SYSTEM, with a note) | nothing charged; RADIUS unchanged (rolled back) |
| RADIUS FAILED, retryable (1205 / 1213 / 2002 / 2006 / 2013) | RETRY_PENDING → identical retry, at most 2 | ACTIVE | — |
| Retries exhausted | FAILED (`PROVISION_RETRIES_EXHAUSTED`) | RELEASED | nothing |
| RADIUS UNKNOWN, read-back mismatch, or a crash in flight | RECONCILIATION_REQUIRED | **ACTIVE** | nothing yet; never retried blindly |
| Verified, then the settlement fails (TRANSIENT after the FinanceTx retries, CAPACITY, INTEGRITY, UNKNOWN) | RECONCILIATION_REQUIRED (`SETTLEMENT_<kind>`) | **ACTIVE**: the service is provisioned but the funds are reserved, so it is **never free** | settled exactly once by reconciliation (`res:{id}:commit`) |
| Success | COMPLETED | COMMITTED | one debit, the snapshot's commissions, the provider settlement on the activation |

## 3. State machine (owner decision A3)

- **Nominal:** CREATED → VALIDATED → FUNDS_RESERVED → PROVISIONING → PROVISIONED → VERIFYING → VERIFIED → FINANCIAL_COMMITTING → COMPLETED.
- **Exceptional edges:**
  - FUNDS_RESERVED or PROVISIONING → RETRY_PENDING → PROVISIONING;
  - PROVISIONING or RETRY_PENDING → FAILED;
  - PROVISIONING / PROVISIONED / VERIFYING / FINANCIAL_COMMITTING → RECONCILIATION_REQUIRED;
  - RECONCILIATION_REQUIRED → VERIFIED | FAILED | ROLLBACK_PENDING;
  - VERIFIED → ROLLBACK_PENDING → ROLLED_BACK;
  - FUNDS_RESERVED or RETRY_PENDING → CANCELLED.
- **Terminal:** COMPLETED, FAILED, ROLLED_BACK, CANCELLED.
- **24 edges** in total, tested over the full 15×15 matrix.
- **Money-safety rules** (all tested):
  - only FINANCIAL_COMMITTING reaches COMPLETED;
  - once provisioning may have happened, the activation can only be reconciled, never failed or cancelled without a read-back.
- **Mechanics:**
  - every change is `UPDATE … WHERE status = <expected>`, plus one event with the next sequence number, under the activation row lock;
  - a concurrent duplicate step loses cleanly (`ACTIVATION_STATE_CONFLICT`);
  - events are append-only (source guard).

## 4. Action and service period (owner decisions C14 and A2)

**Action**, derived by the server from the customer's current recharge row for `routers='radius'` and the plan type:

| Current recharge | Action |
|---|---|
| none | NEW |
| same **plan id**, still active | RENEW |
| same plan id, expired or `off` | REACTIVATE |
| another plan | CHANGE_PLAN (`previous_plan_id` set) |

The form sends the action the operator saw. A mismatch is refused (`ACTION_MISMATCH`).

**Period**
- RENEW extends from the current expiry. NEW, REACTIVATE and CHANGE_PLAN start now. There is no proration.
- **Legacy arithmetic:** Months, Days, Hrs, Mins and Period (the "Expired Date" day, else the postpaid plan day, else 20) are reproduced unit for unit. Tests check parity against the legacy formulas.
- **One deliberate correction:** a Period renewal clamps the day to the month (the legacy code could write `YYYY-02-31`).
- **`extend_expiry`:** the legacy setting does not apply.
- **Time zone and freezing:** the period is a wall clock in the application time zone (as the legacy rows and the RADIUS Expiration use; C18). The UTC equivalents are recorded too. It is frozen in the VALIDATED event, so retries and reconciliation never recompute it.

## 5. Idempotency and concurrency

- **Key:** `staff:{userId}:{nonce}`. The nonce (16–64 `[A-Za-z0-9_-]`) is issued by the form.
- **Same key, same request** (customer, plan, action, actor): the same activation is returned (`replayed`), and if it is in a safe state it continues from there. A replay never repeats a step.
- **Same key, different request:** `IDEMPOTENCY_CONFLICT`.
- **In-flight guard** (inside the tree lock): a customer with a non-terminal activation gets no new one (`ACTIVATION_IN_PROGRESS`). Two devices therefore never produce two periods.
- **Wallet:** all opens are serialized by the tree lock, and the reservation re-checks available credit, so there is no overspend.
- **Proven with real worker processes:**
  - a double submit with the same key gives one activation;
  - two devices with different keys give one period;
  - three concurrent sales with room for one give one sale and no overspend;
  - an activation racing a price change gets the old or the new sealed price, and the debit always equals it;
  - an activation racing a plan delete stays serialized: a priced plan is never deleted, and an unpriced plan's activation finds no plan.

## 6. Reconciliation (owner decision A4; owner fix round 1)

**Who:** Admin / SuperAdmin staff (`activation.create` scope G) on `activation/view`, or the CLI (SYSTEM).

**Captured states.** `RadiusState` is derived from the WP4 provisioner contract. It projects exactly the rows the provisioning owns or changes:
- for the provisioned key: every managed check and reply attribute row `[attribute, op, value]` (`RadiusPolicy::MANAGED_*`), and every group-membership row `[group, priority]`;
- the managed reply rows of the plan group `plan_<id>`, which `applyPlan` writes.

Unmanaged rows and accounting history are excluded. The provisioner never touches them.

The PROVISIONING event metadata stores two projections:
- `prior`: the state before the **first** attempt;
- `target`: the exact state the **latest** attempt applies.

The password value never appears. It is replaced by `sha256(reference ‖ value)`. Neither the view page nor the CLI renders event metadata.

**`reconcile` is split by durable history:**

| History | Mode | Decision | Result |
|---|---|---|---|
| the timeline reached VERIFIED (delivery proven; the settlement failed afterwards) | **FINANCIAL** | the current RADIUS state is **not** consulted; a later foreign edit never turns a verified delivery into NOT_APPLIED | the exact idempotent settlement is retried → COMPLETED, or back to RECONCILIATION_REQUIRED with the reservation ACTIVE and no partial ledger result |
| never verified (UNKNOWN commit, read-back mismatch, stale in-flight) | **EXTERNAL** | current projection **==** target | VERIFIED → settled exactly once |
| | | current **==** captured prior state | `NOT_APPLIED`: reservation released → FAILED |
| | | anything else (or nothing captured) | **`EXTERNAL_STATE_AMBIGUOUS`**: stays RECONCILIATION_REQUIRED, reservation ACTIVE, no debit, no commission, no legacy rows, **no RADIUS write**, no release. The event names the differing components (e.g. `check:Expiration`, `groups`), never values. A repeated reconcile with the same result records nothing new. |

In-flight states are reconciled only when stale (default 300 s), so a running request is never raced. A RADIUS read error changes nothing.

**Other commands**

| Command | Effect |
|---|---|
| `rollback` | An explicit operator decision, from RECONCILIATION_REQUIRED (ambiguous included) or VERIFIED. The captured prior subscriber rows are restored exactly (`RadiusProvisioner::restore`) and verified by read-back; **only then** is the reservation released → ROLLED_BACK. An uncertain or failed restore stays ROLLBACK_PENDING with the reservation held, and can be repeated. Password handling is described below. |
| `cancel` | FUNDS_RESERVED or RETRY_PENDING (nothing provisioned) → released → CANCELLED. |
| `resume` | Continues from FUNDS_RESERVED, RETRY_PENDING or VERIFIED. |
| `stale` | A report only. |

**Rollback details:**
- **Password:** the captured password is a digest. It is restored when it equals one of the customer's own passwords; otherwise the access is re-keyed to an unguessable value, and the event records `password: restored|rekeyed`.
- **Plan group rows** are plan configuration shared by every subscriber of the plan, so rollback does not revert them.

**Operator surfaces:**
- The UI and CLI never present an ambiguous state as "not applied".
- The view page states that the current state differs from both known states, that no funds were released, that the reservation remains held, and that operator review or rollback is required.
- The CLI prints the same text and exits with code 4.

- **Nothing is automatic.** There is no timeout release (WP3A); scheduling the report is a deployment matter (WP10).
- **Stale RADIUS sessions** (`AccountingService::staleSessions`) are not acted on in WP5A.

## 7. Permissions (owner decision A5)

| Capability | SuperAdmin / Admin | Agent | Sales | Report |
|---|---|---|---|---|
| `activation.create` (mutating, schema-dependent) | G | S: customers it owns (`manager_id` = self) | A: customers assigned to it, of its parent | — |
| `activation.read` (schema-dependent) | G | T: activations of its subtree (historical owner) | A | — |

- **Actor eligibility (owner fix round 1).** `activation.create` acts for a reseller identity (`Capability::PLACEMENT_REQUIRED`). An Agent or Sales principal must be validly placed: enrolled, consistent and attached to its reseller, whatever its scope letter.
  - The A5 scope letters are unchanged.
  - The central `PermissionService` refuses an unplaced principal, so the RouteGuard answers 403 before the controller runs, for the form, the preview and the POST.
  - `ActivationService::eligible()` / `assertEligible()` repeat the check (Active, enrolled, consistent, not frozen; Agent = its own identity; Sales placed under an Agent) before any data access.
  - `open()` revalidates the actor in the database inside the transaction.
  - Admin / SuperAdmin keep the approved global behaviour.
- **Plan catalog (owner fix round 1).** `ActivationService::catalog()` returns id, name and type only: no price, cost, chain, commission or settlement data.
  - **Agent:** the plans saleable for its own reseller identity.
  - **Sales:** the plans saleable for the authoritative owner (read from the customer row) of a customer in its scope; no customer means no plans.
  - **Admin / SuperAdmin:** the administrative list of enabled RADIUS Hotspot / PPPoE plans (unchanged).
  - Saleability is `PricingService::resolveInTx` under the tree lock, so every WP3B fail-closed reason excludes a plan. The SQL filter also excludes:
    - non-RADIUS devices and unsupported types (VPN, Balance);
    - disabled plans.
  - A plan outside the actor's catalog is not previewed. Agent / Sales get NOT_FOUND, without the reason; Admin gets the reason. A Sales request naming a customer outside its scope gets the same NOT_FOUND as a missing customer.
- **Wallet:** the wallet debited and the pricing leaf are always the customer's owner Agent, whoever initiates. An **ancestor never spends a descendant's wallet**.
- **Customers:** only reseller-owned (`manager_id` set) and Active. Unassigned legacy customers keep the legacy flow (Admin gets `CUSTOMER_NOT_RESELLER_OWNED`; others get NOT_FOUND).
- **Plans:** only the `Radius` device and the Hotspot / PPPOE types (D028). Other plans get `PLAN_DEVICE_NOT_SUPPORTED`; disabled plans get `PLAN_DISABLED`.
- **Fail-closed principals:**
  - a frozen principal loses `activation.create`;
  - schema not ready means both capabilities are refused for every role (`tbl_reseller_activation_events` is now a required table);
  - a reseller frozen **after** provisioning still settles (WP3A).

## 8. Compatibility bridge (owner decision A6)

**At COMPLETED, in the settlement transaction**

| Row | What is written |
|---|---|
| `tbl_user_recharges` | The customer's row for routers `radius` and the type is updated, or one is inserted. Plan, period start and expiry, `status='on'`, `method='Reseller - <ref>'`. |
| `tbl_transactions` | One row: `invoice='INV-<ref>'` (unique, no `_raid()`), price = the sealed retail snapshot, a note. |
| activation `legacy_*` columns | The ids of both rows. |

- **Not done:** no `Package::*`, no hooks, no notifications, no bills or tax (WP5B).
- **Consistency:** the legacy UI, cron expiry, device sync and rlm_rest then see the same expiry as RADIUS.

## 9. Owner decisions applied

| # | Decision |
|---|---|
| A1 | New `Reseller\Activation` namespace. The accepted guards allow exactly it as a finance / pricing caller, activation-table user and plan-reference writer (`tests/Unit/Wp3a/SourceGuardTest.php`, `tests/Unit/Wp3b/PricingSourceGuardTest.php`). |
| A2 | Server-derived action (same plan = same plan id); C14 period frozen at validation; `extend_expiry` ignored. |
| A3 | Transition graph as in §3. |
| A4 | Reconciliation by Admin and the CLI; stale activations reported only; no timeout release. |
| A5 | Capability matrix as in §7. |
| A6 | Legacy bridge as in §8. |
| A7 | Minimal staff routes; the pinned `RouteMap` changed and its pin updated. |
| A8 | Refund / reversal of COMPLETED activations is deferred to WP5B. |

## 10. Residuals

1. **RENEW display window.** RENEW provisions the new expiry before the recharge row changes at COMPLETED. In that window the legacy UI shows the old expiry.
2. **Provisioned, uncommitted service.** A provisioned service whose settlement failed stays active, with funds reserved, until an operator reconciles it (CLI / Admin view). WP6B adds operational views.
3. **No refunds.** There is no refund or reversal of a COMPLETED activation (A8 → WP5B), and Admin rollback is refused for COMPLETED activations.
4. **Tree-lock serialization.** Activation opens are serialized globally by the tree lock (WP3A residual; throughput is for WP9).
5. **Retail in legacy reports.** `tbl_transactions.price` holds the reseller retail, so legacy revenue reports count reseller sales at retail.
6. **Disconnect scope.** CHANGE_PLAN and REACTIVATE disconnect existing sessions (best effort); RENEW does not, so an open session keeps its old Session-Timeout until re-authentication. There is no real-NAS verification (WP8).
7. **Pre-WP4 PPPoE rows.** RADIUS rows keyed by `username` for PPPoE customers (WP4 residual 1) are not cleaned by WP5A.
8. **Plan group rows are not rolled back.** Rollback restores the subscriber's rows exactly. The plan group's rows are shared plan configuration and stay as the plan defines them.
   - Reconcile still requires them to match exactly, for both the target and the prior state, before it settles or releases. A foreign plan edit between provisioning and reconcile therefore leaves the activation ambiguous, with the funds held.
9. **Password re-keying.** When the captured prior password is not one of the customer's own current passwords (for example, an earlier suspension), rollback re-keys the access to an unguessable value instead of re-enabling an unprovable one.
10. **Activations without a captured state.** Activations that reached provisioning before fix round 1 have no captured state. A never-verified one of these is always ambiguous, and rollback refuses `PRIOR_STATE_NOT_CAPTURED` with the funds held. None exists outside development databases.
11. **Catalog under the tree lock.** The catalog resolves each candidate plan under the global tree lock. This is correct but serialized; WP9 measures it.
