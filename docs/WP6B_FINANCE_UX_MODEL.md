# WP6B — Finance, Reseller, Pricing, Reports and Operations UX: model and contracts

Status: implemented on `wp6b/finance-reseller-reports-ux` (base `feat/reseller-system-v1` = `81d49243`), waiting for the
owner's code review. Plan: `WP6B_PLAN.md` revision 2 (recorded in commit `5d3b6040`), accepted by "WP6B OWNER REVIEW —
FINAL PLAN ACCEPTED / IMPLEMENTATION GO" (OD-1 … OD-9). Evidence: `owner-review/WP6B/`.

WP6B is the web surface over the accepted finance, pricing, hierarchy and activation primitives. It adds **no schema
change (no R0008), no capability change, no new money rule** and no framework (server-rendered Smarty on the WP6A shell).
Every money mutation is the accepted `TransferService` / `PricingService` / activation primitive, re-authorized by
that service; the UI only hides what the backend would refuse anyway.

## 1. Architecture boundary (OD-1)

```
controllers / templates  ->  Reseller\Backoffice (gateway)  ->  Reseller\Finance  /  Reseller\Activation  /  Reseller\Domain
```

| Layer | May | Must never |
|---|---|---|
| Controllers `finance`, `pricing`, `operations`, `activation`, `reseller` | read request input, call `Reseller\Backoffice` / accepted Activation & Domain services, assign template data, redirect | reference `Reseller\Finance` or `Reseller\Radius`, touch `tbl_*`, call `ActivationStore` / `ReservationService` / `LedgerService`, post balance / wholesale values to a service |
| `Reseller\Backoffice\*` (WalletDesk, PricingDesk, SettlementDesk, ResellerDesk, OperationsDesk, ExportStream, ConfirmToken) | call Finance / Activation / Domain services and read models; compose review data; stream exports | SQL, `tbl_*`, `Tx::` / ORM / `FinanceTx`, Money arithmetic, ledger posting, reservations, `LockedWallet`, `resolveInTx`, request superglobals, floats, network |
| `Reseller\Finance\{LedgerReadModel, SettlementReadModel, WalletPreview}` | SELECT-only read models / informational previews (finance-domain reads live in Finance) | any write |
| `Reseller\Ui\*` | presentation helpers | the `Reseller\Finance` namespace, activation / manager tables, writes |
| Templates | escaped output of server values | naming finance services (guards scan templates as text), computing money |

Proven by `Tests/Unit/Wp6b/Wp6bBoundaryTest` (Backoffice purity, thin controllers, SELECT-only read models, one tree lock
per bounded pricing page, exact reseller API list) and the narrowly amended WP3A / WP3B guards.

## 2. Routes (RouteMap, pinned and re-pinned; web only — none in `API_ALLOWED`)

| Controller | Actions (rule) |
|---|---|
| `finance` | `overview`, `wallets`, `wallet`, `performance` (wallet.read); `ledger`, `entry`, `transfers`, `transfer`, `sales`, `commissions`, `settlement` (ledger.read; settlement needs G inside the read model); `funding`, `funding-review`, `funding-post` (wallet.fund_withdraw); `send`, `send-review`, `send-post` (wallet.transfer); `debt-limit*` (wallet.debt_limit); `adjust*` (wallet.adjust); `reverse*` (wallet.reverse) |
| `pricing` | `plans`, `plan`, `catalog` (pricing.read); `base-cost-post`, `resale-post` (pricing.base_cost); `wholesale-post` (pricing.child_wholesale); `retail-post` (pricing.own_retail); `availability-post` (pricing.child_availability) |
| `operations` | `overview`, `activations`, `drift`, `held`, `renewals` (system.admin) |
| `activation` | + `cancel`, `resume` (POST, activation.create; G enforced by `ActivationOperatorService`) |
| `reseller` | + `detail` (reseller.tree_read) |

`API_ALLOWED['reseller']` changed from `'*'` to the explicit pre-existing list, so `reseller/detail` is not API-reachable.
Unknown reseller actions still alias to `tree` (accepted behaviour); the tree carries no money.

## 3. Money operations: review -> execute (OD-2)

1. Form (GET) -> **review** (POST, no write): the server normalises the amount (Arabic-Indic digits mapped losslessly
   before the strict Money grammar), validates reason / external reference, computes informational before / after
   figures (`WalletPreview`) with a "likely refused" hint, generates the operation reference
   `WEB-<OP>-<Ymd>-<16 hex>`, and signs the reviewed fields with `ConfirmToken` (HMAC-SHA256 under a per-session key
   that is never rendered).
2. **Execute** (POST) recomputes the token from the posted values; any difference fails closed with `REVIEW_EXPIRED`
   before a service call. Withdrawal, debit adjustment and transfer reversal also require a typed confirmation
   (`CONFIRMATION_MISMATCH`). The accepted service then validates, authorizes and posts; a double submit replays the
   same idempotent operation ("Already recorded").

   Fix round 1, the debt line: its risk direction depends on mutable state, so the review binds the current limit it was classified against.
   - Execute re-reads the limit. A changed limit refuses the stale review (`REVIEW_EXPIRED`, nothing written).
   - The typed confirmation follows the server-derived direction against the current limit, never a browser or signed flag.
   - Fix round 2: the write itself is `WalletService::setDebtLimitIfCurrent`. The writer compares the reviewed current limit with the limit of the row it has locked `FOR UPDATE`, inside its tree-locked transaction, before writing anything. A limit that changes between the pre-read and the writer's lock is therefore refused too (`REVIEW_EXPIRED`, nothing written).
   - The other operations carry an explicit, signed operation chosen by the user.

Administrative corrections (Admin / SuperAdmin only, an "Accounting corrections" zone, never "edit balance"): debt line
(current limit, balance, reserved, available, projected available), Credit / Debit Adjustment (never opens a wallet),
and reversal of an existing reversible transfer only (`TransferService::reversalPreview`, the compensation it would post).

## 4. Visibility (OD-4 / OD-5)

- Wallets / ledger / transfers: wallet.read / ledger.read scope (G Admin, T Agent own + subtree). Parties outside the
  viewer's subtree are shown as role labels ("Upstream reseller", "Provider (administrator)", "Staff"), never names / ids.
  In-scope ledger reasons are shown (not newly redacted).
- Pricing: exactly the `PricingReadModel` projection (no duplicated redaction). An Agent never sees the provider base
  cost, provider settlement or upstream identities / costs / margins.
- Settlement card / reports: Admin sees debit, commissions, provider settlement and the conservation check; an ancestor
  sees the descendant's debit and its own commission; the seller sees its own debit only. The provider settlement report
  is Admin-only.
- Report and Sales roles get nothing new (no wallet, ledger, pricing or finance export).

## 5. Exports (OD-6)

Same capability, scope and query as the screen; streamed CSV (UTF-8 BOM, `;`, formula-safe cells); at most 20,000 rows
(`EXPORT_TOO_LARGE`) and a date range of at most 366 days for time series (`EXPORT_RANGE_REQUIRED`); one audit row
`export.finance` with metadata only (type, filters, row count — never the rows).

Fix round 1: one explicit primitive, `Reseller\Finance\ExportCap`.
- The authoritative scoped total is known before the first CSV byte. Above 20,000 rows the export is refused (zero bytes, no export audit); exactly 20,000 is allowed. Exports are never truncated.
- The SQL cursors (wallets, ledger, transfers, sales / commissions, settlement, performance) count and stream inside one read-only REPEATABLE READ snapshot (`ExportCap::snapshot`), so the streamed set is exactly the counted set.
- The pricing catalog is pre-refused on the total of its first bounded, tree-locked page and streams exactly that many pages (25 rows each, one tree lock per page). 25 divides the cap, so the stream cannot exceed it.
- `ExportCapGuardTest` keeps every producer on this contract.

## 6. Bounded pricing reads (OD-9)

`PricingService::catalogForManager` / `matrixForPlan` / `baseCosts` / `relation`: 25 plans or 50 managers per page, one
tree lock per page, every row projected through `PricingReadModel`.

## 7. Activation operations and the Operations Center (OD-7 / OD-8, slice-0 correction)

`ActivationOperatorService` decides the operator actions from one table that mirrors the accepted primitives' state
preconditions (drift-guarded by `ActivationActionMatrixTest` over all 17 states):

| Operation | Primitive | States |
|---|---|---|
| reconcile | `ReconciliationService::reconcile` | RECONCILIATION_REQUIRED; an in-flight state once stale |
| rollback | `ReconciliationService::rollback` | RECONCILIATION_REQUIRED, VERIFIED, ROLLBACK_PENDING (one-way warning, typed reference) |
| reverse | `ActivationReversalService::reverse` | COMPLETED, REVERSAL_PENDING |
| cancel | `ReconciliationService::cancel` | FUNDS_RESERVED, RETRY_PENDING — stale only |
| resume | `ActivationService::execute` | FUNDS_RESERVED, RETRY_PENDING, VERIFIED — stale only (audited `activation.resume`) |

Stale = untouched for `ReconciliationService::STALE_SECONDS` (300 s); a fresh row is refused with `ACTIVATION_IN_PROGRESS`
and nothing is written. Slice 0 removed WP6A's dead Reconcile button for VERIFIED / ROLLBACK_PENDING (a WP6A UI defect
found in WP6B reconnaissance; the backend always refused it).

Operations Center (system.admin, read-only): Needs attention, stale activations (bounded page), Waiting / held legacy
balance operations (CLI decision `php system/reseller/reconcile.php balance-resolve <id> completed|refund`), auto-renew
not available (informational), drift on demand (RADIUS comparison opt-in), resolved history. Buttons only link to the
activation page, where each accepted action has its own confirmation; informational items carry no buttons; ambiguous
outcomes stay "still uncertain"; identifiers only (no metadata, secrets or voucher codes).

## 8. Navigation and dashboard (OD-3)

- Groups: Finance (Wallet overview, Wallets, Ledger, Transfers), Resellers (Reseller Tree, moved from Settings),
  Pricing (Plans and prices), Operations (Operations Center, Admin), Reports (Sales, Commissions, Provider settlement
  (Admin), Reseller performance, "Transactions (legacy)", "Activation History (legacy)"). Every item is listed only when
  its RouteMap rule allows the actor.
- Phone primary: Agent with wallet.read = Home / Customers / New (+) / **Wallet** / More; Sales / Admin / Report unchanged;
  an unenrolled Agent keeps the WP6A navigation.
- Quick actions: "Send to child reseller" (Agent with wallet.transfer C and an Active direct child), "Fund reseller"
  (Admin -> wallet list).
- Dashboard tiles (`WalletDesk::dashboard`): Agent available credit (or "No wallet yet") with Send / Ledger / Wallet;
  Admin wallets with no available credit, wallets using the debt line, credit reserved for activations in progress,
  stale activations.

## 9. Language

All WP6B strings have Arabic catalog entries (drafted copy for owner review); `Wp6bTemplateGuardTest` fails on any WP6B
string without one. Money and references render as LTR tokens inside RTL text.

## 10. Accepted tests changed (authorized by the plan §20)

`Unit/Wp3a/SourceGuardTest` (Backoffice callers, finance action allow-list, RouteMap re-pin), `Unit/Wp3b/PricingSourceGuardTest`
(pricing routes allow-list, read-only display files, SettlementReadModel in the activation allow-list),
`Integration/Wp3a/AppSurfaceTest` (P7 rewritten for the WP6B surface), `Integration/Wp6a/ShellNavigationTest` (Agent
primary Wallet), `Integration/Wp6a/DashboardTest` (finance words only in the finance tiles).
