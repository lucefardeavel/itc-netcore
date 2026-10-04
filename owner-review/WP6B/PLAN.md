# WP6B — Finance / Reseller / Reports UX — FINAL PLAN (revision 2, PLANNING ONLY)

**Status:** final plan; waiting for the owner's implementation GO. No code, schema, route, capability, test, commit or push was made.

**Revision 2** incorporates "WP6B OWNER REVIEW — PLAN ACCEPTED WITH AMENDMENTS / OWNER DECISIONS" (OD-1 … OD-9 decided, plus the slice-0 correction, read-model ownership, bounded pricing reads, Operations categories and the tree phone defect). Where this text differs from revision 1, revision 2 wins. Revision 1 is superseded in full.

**Reconnaissance (revision 1, re-verified for revision 2):**
- read-only code and document review;
- three parallel read-only inventories;
- a disposable visual lab on the accepted HEAD, isolated `nuxbill-reseller-test` only, torn down afterwards. AVAIA was not touched.

---

## 1. Canonical baseline and prerequisites

| Item | Value |
|---|---|
| Execution base | `feat/reseller-system-v1` @ `81d492432ca6ae6e5f8ff67793542540001287df` |
| Accepted | WP0–WP6A. WP6A `ACCEPTED_WITH_NOTE`: implementation `f71e239f`, evidence `ed014525`. R-15 is not reopened. |
| `main` | `500d3a2d`, untouched |
| Branch (after GO) | `wp6b/finance-reseller-reports-ux` from `81d49243` |
| Stack | PHP 8.2, Smarty 4, AdminLTE 2 / Bootstrap 3 / jQuery 1.11 plus the WP6A shell. No SPA, no PWA. |
| Tools | Nothing new to install. The lab, host Playwright and axe-core 4.10.2 are reused. |

**Frozen decisions touched:** D009–D015, D020–D025, D027, D029, D032, D034. Also binding:
- WP2 OD-4;
- WP3A P2 / P3 / P7 and its state and wallet-creation policies;
- WP3B F-1 … F-8;
- WP5A A4 / A5;
- WP5B OD-11 / OD-12;
- WP6A OD-A … OD-L.

**Prompt:** `prompts/WP6B_FINANCE_RESELLER_UX.md`. It excludes PWA, domain changes for visual convenience, SPA and reseller voucher generation.

---

## 2. Current-state / backend inventory (verified)

### 2.1 Framing facts

1. **No finance or pricing web or API surface exists, and accepted tests pin this for WP3A / WP3B:**
   - `SourceGuardTest::testNoEntrypointReachesTheFinanceServices`: only `Finance/` and `Activation/` may reference finance; route-name bans; `API_ALLOWED` bans.
   - `PricingSourceGuardTest::testNoEntrypointOrRoute`: the same bans for pricing.
   - `AppSurfaceTest::testNoFinanceRoutesOrApi` (P7).
   - The RouteMap byte pin.

   WP6B amends these intentionally and narrowly (OD-1).
2. **`API_ALLOWED['reseller'] = '*'`.** Every reseller action, including the unknown-action alias to `tree`, is API-reachable.
3. **WP6A guard:** `Ui\*` helpers must not query `tbl_manager_*`.
4. **Activation-table allow-list (WP3B guard).** Only these files may reference `tbl_reseller_activations`:
   - `ActivationStore`
   - `ReconciliationService`
   - `SchemaReadiness`
   - `LedgerService`
   - `ReservationService`
5. **Public-API pins:** only the `LedgerService` public API is reflection-pinned among the finance services (plus the `ReservationService` / `FinanceActor` shapes). The finance files are not byte-pinned.
6. **Route-matrix URL helper:** it maps action bases `list`, `view`, `search`, `dashboard`, … to the bare controller URL. New WP6B actions therefore avoid those names so that every new row is explicit (`reseller/detail`, not `reseller/view`).

### 2.2 Operation inventory

The R1 inventory is unchanged. Key contracts:

| Operation | Contract |
|---|---|
| `WalletService::view/listScoped` | `wallet.read` G / T. Synthesized wallet when absent (`exists = false`). `listScoped`: 50 per page, no filters or total. |
| `LedgerService::entries/entry` | `ledger.read` G / T. 100 per page, **oldest first, no filters**. |
| `TransferService::fund/withdraw` | G. Mandatory reason 3–255; reference `^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$`, unique, replay on identical payload, otherwise `IDEMPOTENCY_CONFLICT`. Optional `external_reference`. |
| `TransferService::adjustCredit/adjustDebit` | G. Same reference / reason rules. Never creates a wallet (`WALLET_NOT_FOUND`). |
| `TransferService::transferToChild` | G / Agent C. Source = self for an Agent; direct child only. |
| `TransferService::reverse` | G. COMPLETED, non-reversal transfers only. Mapping: FUNDING↔WITHDRAWAL, ADJ_CREDIT↔ADJ_DEBIT, P2C swapped. No wallet creation. Floor applies to the debit leg. |
| `WalletService::setDebtLimit` | G. Absolute; same value = no-op; mandatory reason; a positive limit creates a wallet; `DEBT_LIMIT_EXPOSURE` on unsafe decrease. |
| `PricingService::set*` | Absolute; optional reason; same value = no-op. |
| `PricingService::quote` | One manager × plan, under the tree lock. |
| `PricingService::validateTree` | G; every enrolled Agent for one plan, under one **unbounded** tree lock. |
| `PricingReadModel::project` | G full / T redacted (chain starts at the viewer; upstream = `UPSTREAM`; no base cost, provider settlement or ancestors). |
| `ReconciliationService::reconcile` | **Only** RECONCILIATION_REQUIRED. In-flight rows (PROVISIONING … FINANCIAL_COMMITTING) are first moved to RECONCILIATION_REQUIRED, and only when stale (≥ 300 s). Everything else → `ACTIVATION_STATE_CONFLICT`. |
| `ReconciliationService::rollback` | RECONCILIATION_REQUIRED, VERIFIED, ROLLBACK_PENDING. One-way. |
| `ReconciliationService::cancel` | FUNDS_RESERVED, RETRY_PENDING. CLI only today. |
| `ActivationService::execute` | Resumes FUNDS_RESERVED, RETRY_PENDING, VERIFIED. CLI `resume` only today. |
| `ReconciliationService::stale` | Global and **unbounded**. |
| `ActivationReversalService::reverse` | G; reason. Web route exists. |
| `LegacyCustomerBalanceService::resolve` | **No actor and no capability** (CLI). |
| `DriftReport::collect/autoRenewUnavailable` | Global. RADIUS reads per owned customer. |

Sales and Report hold no finance or pricing capability. A frozen Agent keeps its reads and loses every mutation.

---

## 3. Gaps classified (revision 2)

| Class | Items |
|---|---|
| **A — presentation only** | Tree restyle (phone drill-down); place / move / status; links to staff creation; WP6A activation-action correction (§13.1); legacy report relabel |
| **B — read model / route wrapper** | Filtered ledger, transfer history, wallet aggregates, settlement / commission projections, bounded pricing matrix, wallet operation previews (`Reseller\Finance`); bounded stale page, operator reads (`Reseller\Activation`); reseller tree / detail counts (`Reseller\Domain`); Operations lists |
| **C — mutation wrapper** | Fund, withdraw, transfer, debt limit, adjustments, transfer reversal (accepted Finance services); pricing setters; activation cancel / resume (new authorized `Reseller\Activation` wrapper over the accepted primitives) |
| **D — owner decision, NOT implemented in WP6B** | Web balance-HELD resolution; CLAIMED voucher resolution; PRIOR_SECRET_UNRESTORABLE / PRIOR_STATE_NOT_CAPTURED recovery; a "resolved" marker for auto-renew obligations; provider wallet / ledger; any finance / pricing / operations **API** |

---

## 4. Information architecture (OD-3 decided)

Destinations appear only where the role has a capability.

| Destination | SA / Admin | Agent | Sales | Report |
|---|---|---|---|---|
| Finance (wallets, ledger, transfers, money operations, finance reports) | ✔ | ✔ own + subtree | — | — |
| Resellers (tree, detail, Sales) | ✔ | ✔ subtree | — | — |
| Pricing | ✔ | ✔ | — | — |
| Operations Center | ✔ | — | — | — |
| Legacy reports (relabelled "Transactions (legacy)") | ✔ | — | — | — |

**Phone primary navigation (OD-3):**

| Role | Primary navigation |
|---|---|
| Agent | Home / Customers / **+** / **Wallet** / More. Wallet = `finance/overview`, its own wallet. Activations stay in More, on the dashboard and in subscriber flows. |
| Sales | WP6A unchanged: Home / Customers / New / Activations / More |
| Admin / SA | WP6A unchanged: Home / Customers / New / Activations / More. Finance / Resellers / Pricing / Operations go in More and the sidebar. |
| Report | unchanged: Home / More |

- **Fallback:** an Agent whose `wallet.read` resolves to NONE (unenrolled or inconsistent) keeps the WP6A navigation. There are no empty destinations.
- **Quick Action (+):**
  - Agent: "Send to child reseller", only when `wallet.transfer` is allowed and at least one active direct child exists.
  - Admin: "Fund reseller".
  - No voucher items.
- **Dashboard:**
  - Agent: "Available credit" tile with Send / Ledger shortcuts.
  - Admin: counters for wallets with no available credit, activations holding reserved credit, and Operations attention.
  - The WP6A dashboard tests that pinned "no wallet / balance words" are updated (§20).

---

## 5. Role-by-role visibility / action matrix

✔ = allowed; — = not rendered **and** refused server-side; (s) = within scope.

| Item | SA / Admin | Agent | Sales / Report |
|---|---|---|---|
| Wallet list / detail (balance, debt line, reserved, available, exists) | ✔ all Agents | ✔ own + descendants (T) | — |
| Ledger + export | ✔ | ✔ (s), including in-scope reasons (OD-4) | — |
| Counterparty / operator identity in ledger and transfers | ✔ | identities **inside** the subtree only; outside → role label ("Parent reseller", "Provider / administrator") | — |
| Transfer history + export | ✔ | ✔ where from or to is in its subtree | — |
| Provider funding / withdrawal | ✔ | — | — |
| Parent → direct-child transfer | ✔ (any valid parent → direct child) | ✔ own wallet → own direct child | — |
| Debt limit / credit adjustment / debit adjustment / transfer reversal (OD-2) | ✔ | — | — |
| Reseller tree / detail | ✔ | ✔ subtree | — |
| Place / move | ✔ | — | — |
| Disable / reactivate | ✔ | ✔ strict descendants | — |
| Pricing: base cost, resale | ✔ | — | — |
| Pricing: child wholesale / child availability | ✔ any | ✔ direct children, never self | — |
| Pricing: retail | ✔ any | ✔ self | — |
| Pricing view | ✔ full projection | exactly `PricingReadModel` T (OD-4) | — |
| Provider base cost, provider settlement, upstream margins | ✔ | — never | — |
| Commissions / sales / performance + export | ✔ | ✔ (s), from ledger only | — |
| Operations Center | ✔ | — | — |
| Activation reconcile / rollback / reverse / cancel / resume | ✔ (each its own capability) | — | — |

**Report role (OD-5):** no new capability, no finance, pricing or activation read, no finance export. The legacy `report.read` grants nothing in the finance domain.

---

## 6. Routes and RouteMap delta (revision 2)

General rules:
- All routes are **web only**. None of `finance`, `pricing` or `operations` is added to `API_ALLOWED`.
- **`API_ALLOWED['reseller']`** changes from `'*'` to the explicit list of the current actions: `['tree', 'place-post', 'move-post', 'status-post', 'customer-assign-sales', 'customer-clear-sales', 'customer-transfer', 'customer-assign-owner']`. This is done in slice 0, **before** any new reseller action exists. API behaviour for these is unchanged. `reseller/detail` is never API-reachable.

  At implementation, verify that the API check runs on the resolved action, so that the unknown-action alias keeps its current API result. This is pinned by `ApiTest:134` and `AppSurfaceTest`.
- CSRF on every POST. A `*-review` POST renders a confirmation and **writes nothing**.
- Exports are `&format=csv` on the **same GET route**, so the same rule and scope apply.

### `finance` (new)

| Action | Method | Rule |
|---|---|---|
| `overview` (and the `''` / `'*'` aliases) | GET | wallet.read |
| `wallets` | GET (+csv) | wallet.read |
| `wallet/{managerId}` | GET | wallet.read |
| `ledger/{managerId}` | GET (+csv) | ledger.read |
| `entry/{id}` | GET | ledger.read |
| `transfers` | GET (+csv) | ledger.read |
| `transfer/{id}` | GET | ledger.read |
| `funding/{managerId}` | GET | wallet.fund_withdraw |
| `funding-review`, `funding-post` | POST | wallet.fund_withdraw |
| `send` | GET | wallet.transfer |
| `send-review`, `send-post` | POST | wallet.transfer |
| `debt-limit/{managerId}` | GET | wallet.debt_limit |
| `debt-limit-review`, `debt-limit-post` | POST | wallet.debt_limit |
| `adjust/{managerId}` | GET | wallet.adjust |
| `adjust-review`, `adjust-post` | POST | wallet.adjust |
| `reverse/{transferId}` | GET | wallet.reverse |
| `reverse-review`, `reverse-post` | POST | wallet.reverse |
| `sales` | GET (+csv) | ledger.read |
| `commissions` | GET (+csv) | ledger.read |
| `settlement` | GET (+csv) | ledger.read; the Finance read model requires G, others get `NOT_FOUND` |
| `performance` | GET (+csv) | wallet.read |

### `pricing` (new)

| Action | Method | Rule |
|---|---|---|
| `plans` (+ aliases) | GET (+csv) | pricing.read |
| `plan/{planId}` | GET | pricing.read (paged resellers) |
| `catalog/{managerId}` | GET | pricing.read (paged plans) |
| `base-cost-post`, `resale-post` | POST | pricing.base_cost |
| `wholesale-post` | POST | pricing.child_wholesale |
| `retail-post` | POST | pricing.own_retail |
| `availability-post` | POST | pricing.child_availability |

### `operations` (new; OD-8: `system.admin`)

| Action | Method | Rule |
|---|---|---|
| `overview` (+ aliases) | GET | system.admin |
| `activations` | GET | system.admin |
| `drift` | GET (`run=1` on demand) | system.admin |
| `held` | GET | system.admin |
| `renewals` | GET | system.admin |

Action buttons on these pages post to the **existing** activation routes or to the two new ones below. Read access to the Center grants no mutation (OD-8).

### `activation` (existing)

New actions, OD-7:

| Action | Method | Rule |
|---|---|---|
| `cancel/{ref}` | POST | activation.create; the Activation wrapper requires G |
| `resume/{ref}` | POST | activation.create; the Activation wrapper requires G |

Existing reconcile / rollback / reverse are unchanged.

### `reseller` (existing)

New action: `detail/{id}` GET `reseller.tree_read` (scoped by `DataScope::findStaff`, T). The tree is restyled and its POST actions are unchanged.

### Route matrix

- **Expectation:** all **1477** accepted rows unchanged.
- **New rows:**
  - 7 per new GET (25 GETs → 175);
  - the denied-role rows of each new POST (17 POSTs: finance 10, pricing 5, activation 2; the allowed POSTs are skipped by the harness): about 55–75.
- **Total:** ≈ **+230 … +250** rows, identical on both engines. Exact numbers come from the candidate.
- Sales `A1` and Report `rp` are denied on every new finance / pricing / operations route.
- Controllers must answer a bare GET without an id with a non-500 (`NOT_FOUND` page), as `activation/view` does.

**RouteMap is re-pinned** under OD-1.

---

## 7. Read-model ownership and services (revision 2)

**Boundary (OD-1):**

```
Controllers / templates → Reseller\Backoffice (gateway) → Reseller\Finance / Reseller\Activation / Reseller\Domain (authority)
```

### `Reseller\Finance` — owns finance tables and finance reads (additive)

| Unit | Kind | Content |
|---|---|---|
| `Finance/LedgerReadModel.php` (new) | read, SELECT-only | `wallets(a, filters, page)` (usernames, status, frozen, exists, available via `WalletView`, count, aggregates); `ledger(a, managerId, {from,to,type,direction}, page)`, newest first, counted, plus a bounded export cursor (keyset chunks); `transfers(a, filters, page)` plus `transfer(a, id)` with legs and reversal link; identity masking (usernames only for ids inside the viewer's predicate, role labels otherwise); scope via `PermissionService::scopeOf` plus `DataScope::staffPredicate`, exactly as the existing services do |
| `Finance/SettlementReadModel.php` (new) | read, SELECT-only | Per-activation settlement: leaf debit, `COMMISSION_CREDIT` legs, reversal legs (with `reverses_entry_id` → the original entries), provider settlement (column), and the conservation check. All from the immutable ledger, activation columns and `PricingSnapshot::fromActivationRow`. Aggregates for sales, commissions, settlement and performance. G gets the full projection. An Agent gets **only ledger-derived amounts within T**: own debits, own and descendant commissions, descendant debits. Never the snapshot, provider settlement, base cost or upstream amounts. Also resolves activation references for ledger rows in scope. **Needs the activation-table allow-list amendment** (SELECT-only, asserted). |
| `Finance/WalletPreview.php` (new) | read | Informational before / after for fund, withdraw, transfer (source and destination), debt limit (projected available), adjustments (`WALLET_NOT_FOUND` when absent: no creation) and reversal. Uses `WalletView` / `Money` arithmetic **inside Finance**, takes no lock, and returns "would be refused: <code>" hints. Never authority: the services re-check atomically. |
| `TransferService::reversalPreview(a, transferId)` (additive read) | read | The original transfer, its reversibility (status, already reversed), and the compensating type from the **existing private mapping**, so the mapping is not duplicated |
| `PricingService::matrixForPlan(a, planId, page)` / `catalogForManager(a, managerId, page)` / `baseCosts(a, page)` (additive reads, OD-9) | read | **Bounded pages** (at most 25 plans × 1 reseller, or 1 plan × at most 50 resellers). **One tree-locked evaluation per page** through the existing private `evaluate()`. Every row is projected by `PricingReadModel::project`. No N× locks; never an unbounded catalog under one lock. `validateTree` is not used by the WP6B UI. |

### `Reseller\Activation` — operational reads and wrappers

| Unit | Kind | Content |
|---|---|---|
| `Activation/ActivationOperatorService.php` (new, OD-7) | wrapper | `cancel(ActorContext, ref)`, `resume(ActorContext, ref)`, `stalePage(ActorContext, page)`. Requires `activation.create` scope G (reusing `ReconciliationService::staffActor`). Resolves the reference authoritatively, enforces the accepted state preconditions, calls the accepted primitive unchanged (`ReconciliationService::cancel` / `ActivationService::execute`), and records an operator audit row (`activation.cancel` is already written by `cancel`; `activation.resume` is new attribution only). No new transition. |
| `ActivationStore` + bounded stale page | read | Bounded counterpart of `stale()` (same definition, `STALE_SECONDS`) |
| `ActivationReadService`, `DriftReport` | read | Reused; display of activation customer / plan for settlement rows comes through `ActivationReadService` (scoped by `activation.read`) |

### `Reseller\Domain`

`Domain/ResellerReadService.php` (new, read): scoped tree page, detail, children, Sales list (scoped `salesOptions`), direct and subtree subscriber counts, and the status / frozen / enrolled / inconsistent flags. It may read `tbl_manager_tree` (Domain is allowed; `Ui\*` stays untouched).

### `Reseller\Backoffice` — gateway, no domain

| Class | Coordinates |
|---|---|
| `WalletDesk` | LedgerReadModel, WalletService reads, WalletPreview, TransferService / WalletService mutations |
| `PricingDesk` | PricingService reads, setters, PricingReadModel output |
| `SettlementDesk` | SettlementReadModel plus `ActivationReadService` display |
| `OperationsDesk` | `ActivationOperatorService`, `ActivationReadService`, `DriftReport`, `LegacyCustomerBalanceService::held()` (read) |
| `ExportStream` | Streams rows from the Finance cursors: CSV, BOM, formula neutralisation, caps, export audit |
| `ConfirmToken` | Signs the reviewed parameters (§16.5) |
| `Messages` | DomainError code → fixed text |

Backoffice **may not**:
- run SQL of any kind;
- write `tbl_manager_*`, pricing or activation tables;
- do `Money` arithmetic or balance calculations;
- reconstruct pricing rules;
- post ledger rows;
- call internal finance primitives (`LedgerService::post*`, `ReservationService`, `LockedWallet`, `FinanceTx`, `resolveInTx`).

All of this is enforced by the boundary guard (§16 / §20).

`Ui\*` helpers remain presentation-only (the WP6A guard is unchanged).

---

## 8. Finance flows

### 8.1 Wallet home / detail

- **Header:** identity, status (Active / Inactive / Frozen by an upstream reseller / not enrolled), and parent shown as "Parent reseller" (name only when inside the viewer's scope).
- **Money block:**
  - **Balance** (may be negative = using the debt line)
  - **Debt line**
  - **Reserved for activations in progress** (linked)
  - **Available credit** (prominent)

  All values come from `WalletView`.
- **`exists = false`:** a "No wallet yet" state explaining the three accepted creation events. It is never presented as an established zero.
- **Read failure:** "Unavailable — try again", never `0.00`.
- **Last 10 movements**, plus actions only where the accepted service would authorize them. A frozen actor sees a banner and no actions.

### 8.2 Ledger

- Server-side pages of 50, newest first, with a total.
- Filters: date range (required for export, at most 366 days), entry type, direction.
- Columns: time (UTC, with the app time zone shown), type, direction (icon + sign + word), amount, balance before → after, reference (transfer / activation / reversed entry), actor (masked per §5), reason.
- Entry detail links to the transfer (with its reversal state), the activation or the original entry.
- Wording: "Ledger entries are permanent; corrections are new compensating entries." There is no edit or delete anywhere.

### 8.3 Provider funding / withdrawal (Admin)

1. **Form.** Operation choice "Provider funding (adds credit)" vs "Provider withdrawal (removes credit)", visually and verbally distinct. Amount, mandatory reason, optional external reference.
2. **Review** (no write). Shows:
   - `WalletPreview` before / after;
   - the server-normalised amount;
   - the server-generated reference;
   - the note "the server re-checks when you confirm".

   Withdrawal requires typing the target username.
3. **Execute.** The accepted `TransferService::fund` / `withdraw` is called with the **reviewed, token-verified** parameters (§16.5). A replay shows "Recorded once (replayed)". A refusal writes nothing and maps to fixed text.

### 8.4 Parent → direct-child transfer

- **Agent:** the source is fixed to itself. The destination picker lists its direct child Agents only; inactive or frozen children are shown disabled with the reason.
- **Admin:** picks a parent, then one of its direct children (the backend `NOT_DIRECT_CHILD` stays authoritative).
- **Review:** From (available now → after) and To (balance now → after) from `WalletPreview`; text "Balance transfer, not a sale".
- **Execute:** `transferToChild`.

### 8.5 Administrative corrections (OD-2; Admin; a separate "Accounting corrections" area)

Each correction has its own visual treatment and the label "administrative accounting operation". There is no "edit balance" wording anywhere.

**Debt limit**
- Review shows: current debt limit, balance, active reserved exposure, current available credit, proposed debt limit, projected available credit (`WalletPreview`).
- Mandatory reason.
- Typed confirmation on a decrease.
- Same value = "No change".
- A positive limit on a missing wallet is labelled "opens the wallet" (accepted creation rule).

**Credit adjustment / Debit adjustment**
- Two explicit operations.
- Amount, mandatory reason, generated reference, review.
- Typed confirmation for a debit.
- A missing wallet produces the review message "No wallet: adjustments never open a wallet" and the execute button is disabled; the backend `WALLET_NOT_FOUND` stays authoritative.

**Transfer reversal**
- Started only from an existing transfer's detail page, when `TransferService::reversalPreview` says it is reversible.
- Shows the original reference, type, counterparties, amount and date, the compensating type, and the per-wallet effect.
- Mandatory reason; typed confirmation of the original reference; generated reference.
- No generic ledger reversal exists in the UI.

---

## 9. Reseller / tree / detail flows (tree phone defect accepted)

- **Desktop:** dense indented table with:
  - status;
  - direct and subtree subscriber counts;
  - Sales count;
  - available credit (where `wallet.read`);
  - actions (Disable / Reactivate per StaffPolicy; Admin Move / Place).

  The "not enrolled" and "consistency findings" panels are kept (and so are their pinned cells where feasible).
- **Tablet:** split view (tree | detail).
- **Phone:** drill-down **cards**: the current node card, children as cards, tap to drill down, breadcrumb back.
  - **No horizontal scrolling solution.**
  - Verified at 390 px and 360 px: no page overflow, and **the bottom navigation fully visible** (the WP6A overflow pushed "More" off-screen).
- **Translation:** the untranslated tree labels ("Role", "Customers owned", "Active", …) are translated in the Arabic pass.
- **Detail** (`reseller/detail/{id}`):
  - identity, status, parent (masked above the viewer), children;
  - Sales accounts with assigned-customer counts, linking to `customers/list` with a new scoped `sales` filter;
  - subscriber counts;
  - wallet card;
  - pricing card (bounded catalog page; "N saleable / M not saleable" for the first page with "see all");
  - recent activations;
  - actions (add Agent / Sales via the existing `settings/users-add`, disable / reactivate, move, fund / send, pricing, ledger).
- **No new hierarchy semantics.**

---

## 10. Pricing flows (OD-4, OD-9)

**Vocabulary** (fixed in both languages):
- provider base cost (G);
- effective wholesale with source *own setting / inherited / upstream / provider base (G)*;
- child wholesale (set by you);
- retail with source *your retail / default retail (plan price)*;
- margin;
- availability (*Available / Disabled by you / Disabled upstream / Resale off (G)*);
- provider settlement (G);
- commission.

The word "price" is never used alone. The legacy `tbl_plans.price` is shown only as "Default retail (plan price)".

**Admin:**
- Plans overview (paged `baseCosts`): base cost, resale on / off, default retail.
- Set base cost / resale, with warnings rendered (resellers that became unsaleable).
- Plan page: a paged reseller grid (`matrixForPlan`, 50 per page, one lock per page) with effective wholesale + source, retail + source, availability, saleable / reasons.
- Edit any reseller's child wholesale / retail / availability.

**Agent:**
- Catalog (`catalogForManager` for itself, 25 plans per page): own effective wholesale (source `UPSTREAM` when inherited from above), own retail (set / clear), margin vs retail, availability, reasons worded neutrally.
- Children pricing: per direct child (paged), the child's wholesale (floor shown = own effective wholesale) and availability, plus the margin from that child.
- All values come from `PricingReadModel` output. **No redaction logic in Backoffice or templates.**
- "Set my own wholesale" never exists.

**Mutations:** absolute values, one-step dialog showing old → new, optional reason, fixed messages for:
- `WHOLESALE_BELOW_PARENT`
- `WHOLESALE_DESCENDANT_CONFLICT`
- `RETAIL_BELOW_WHOLESALE`
- `RETAIL_CLEAR_INVALID`
- `PRICING_TARGET_FROZEN`
- `SELF_PRICING_FORBIDDEN`

---

## 11. Commission / provider-settlement presentation

**Source:** immutable ledger legs and activation columns / sealed snapshots (`SettlementReadModel`). **Never recomputed from today's pricing.**

**Admin settlement card (per activation):**
- leaf debit;
- commissions per recipient;
- provider settlement;
- conservation line ("debit 17.00 = commissions 5.00 + provider settlement 12.00 ✔"), computed in Finance; a mismatch is shown as "Inconsistent — contact support", never hidden;
- reversal / clawback legs, each linked to the **original entries** (`reverses_entry_id`), with time and reason.

**Agent:**
- its own sales debits;
- commissions it received;
- descendants' commissions and debits inside T, from the ledger only;
- never provider settlement, base cost, upstream identities or upstream margins;
- the WP6A activation view still shows wholesale only to G or the paying Agent.

**Aggregates** (sales, commissions, settlement (G), performance, reversals, net) by period, reseller and plan, using integer-minor sums inside Finance.

---

## 12. Reports / exports (OD-5, OD-6)

**Reports:**

| Report | Admin | Agent |
|---|---|---|
| Wallet balances / available credit | ✔ | ✔ (T) |
| Ledger movements | ✔ | ✔ (T) |
| Funding / withdrawals (type filter) | ✔ | own-wallet rows only, as a ledger view |
| Transfers | ✔ | ✔ (T) |
| Activation sales | ✔ | ✔ (T) |
| Commissions | ✔ | ✔ (T) |
| Provider settlement | ✔ | — |
| Reversals | ✔ | ✔ (T) |
| Reseller performance | ✔ | ✔ (T) |
| Pricing / saleability | ✔ | ✔ (T, via `PricingReadModel`) |

**Export contract:**
- **Same route, capability, DataScope and query / filter model** as the screen. One Finance cursor serves both.
- Never "global query → filter afterwards".
- Time series require a date range of at most 366 days.
- Hard cap of **20,000 rows**: above it, the request is refused with "narrow the filter" (no truncated file).
- **Streaming** with keyset chunks (no unbounded memory) and formula neutralisation.
- **Audit `export.finance`:** actor, export type, filters / range, scope / target, row count. **No exported contents.**
- `report.read` grants no finance export.
- The Report role gets nothing new. Legacy reports stay Admin-global and relabelled.

---

## 13. Operations Center (OD-7, OD-8)

### 13.1 Slice-0 correction — dead activation actions (a WP6A UI defect found in WP6B reconnaissance)

The accepted WP6A `activation/view.tpl` offers **Reconcile** for RECONCILIATION_REQUIRED, VERIFIED and ROLLBACK_PENDING. `ReconciliationService::reconcile()` accepts only RECONCILIATION_REQUIRED (in-flight rows are moved there only when stale).

| State | Reconcile | Rollback (one-way) | Other |
|---|---|---|---|
| RECONCILIATION_REQUIRED | ✔ keep | ✔ | — |
| VERIFIED | **✘ remove** (always `ACTIVATION_STATE_CONFLICT`) | ✔ (accepted) | Resume / settle (OD-7 wrapper) |
| ROLLBACK_PENDING | **✘ remove** (always refused) | ✔ retry (accepted) | — |
| stale in-flight | only in the Operations Center, only when listed by the accepted stale rule (≥ 300 s) | — | — |

**Focused regression test:** render `activation/view` as Admin for an activation in **each** of the 17 states and assert that the rendered action set equals the accepted-primitive matrix. Then POST each rendered action's precondition check: Reconcile on VERIFIED and ROLLBACK_PENDING is refused by the backend, which proves those buttons must not be rendered. A general assertion ("no rendered activation action is always rejected") covers reconcile, rollback, reverse, cancel and resume.

The rollback dialog gains the one-way warning: "after a rollback attempt this activation can never be settled; funds stay reserved until the restore is verified".

This is recorded in the WP6B report as a narrow WP6A defect fix; reconciliation semantics are not broadened.

### 13.2 Center

Admin / SuperAdmin only (`system.admin`). Explicit categories, never a binary failed / ok:

| Category | Items | Rendered actions (exact accepted operations only) |
|---|---|---|
| **Needs attention** | RECONCILIATION_REQUIRED; stale in-flight (bounded `stalePage`); stuck FUNDS_RESERVED / RETRY_PENDING / VERIFIED (stale) | Reconcile (RECONCILIATION_REQUIRED; stale in-flight); Rollback (RECONCILIATION_REQUIRED / VERIFIED); **Cancel** (FUNDS_RESERVED / RETRY_PENDING, wrapper); **Resume / Settle** (FUNDS_RESERVED / RETRY_PENDING / VERIFIED, wrapper) |
| **Waiting / held** | ROLLBACK_PENDING (retryable codes); REVERSAL_PENDING; `BALANCE_HELD` | Retry rollback; Resume reversal (reason); HELD → **CLI / operator action required** |
| **Failed / uncertain** | `EXTERNAL_STATE_AMBIGUOUS` (still uncertain; funds reserved); `REVERSAL_FINANCIAL_REFUSED` (hint: available credit may need funding first, linked to Finance) | Only the accepted retry of that state; never "mark failed" or "mark done" |
| **Informational / CLI required** | PRIOR_SECRET_UNRESTORABLE / PRIOR_STATE_NOT_CAPTURED; VOUCHER_CLAIMED (voucher id, never the code); AUTO_RENEW_UNAVAILABLE ("Renew now" link to `activation/create`, never marked resolved); REPROVISION_UNCONFIRMED (link to the 360 Sync = accepted ADMIN_SYNC); drift findings (PERIOD_NOT_BACKED, RADIUS_DRIFT, PAYMENT_FOR_RESELLER_OWNED; `DriftReport::collect` on demand, RADIUS part opt-in); persisted CREATED / VALIDATED anomalies | No buttons except the links named. "CLI / operator action required" with the non-secret identifier and, where one exists, the exact CLI command (e.g. `balance-resolve <id> completed\|refund`). |
| **Resolved / history** | Recent terminal outcomes of attention items (last 7 days, from `ActivationReadService` groups) | none |

**Wrapper preconditions (OD-7):**
- **Cancel:** the accepted `cancel()` states.
- **Resume:** FUNDS_RESERVED / RETRY_PENDING / VERIFIED, the states `execute()` actually advances.
- **Both** are rendered and accepted only for activations the accepted stale rule lists (≥ `STALE_SECONDS`, untouched). This prevents an operator racing a live request. It is an application precondition mirroring the accepted stale definition, not a new transition. The owner may relax it at implementation review.

**General rules:**
- An unknown or ambiguous outcome is never labelled failed or successful.
- No automatic or polling-driven mutation.
- Event metadata is never rendered.
- Every button posts to its own route with its own capability.
- Viewing the Center authorizes nothing.

---

## 14. Desktop / tablet / phone UX

- **Desktop:**
  - dense server-paged `DataTable`s with filters and a CSV button;
  - LTR tabular money;
  - two-column review pages.
- **Tablet:** split views (tree | detail, wallets | wallet, plan | grid).
- **Phone:**
  - cards (wallets, ledger rows, transfers, operations items);
  - filters in a bottom sheet;
  - sticky primary action;
  - review as a full-screen sheet with the money hierarchy;
  - drill-down tree;
  - 44 px targets;
  - **no page-level horizontal scroll at 390 px / 360 px**, with the bottom navigation fully visible.
- **Components:** MoneyDisplay (domain colour + label), MoneyInput, ConfirmSheet, DangerDialog (typed), EmptyState, ErrorState ("unavailable"), StatusBadge (icon + word).
- **No new library.** Chart.js only on report pages if charts are added at all.

---

## 15. Arabic / RTL / accessibility

- All new strings in `system/lan/arabic.json`, with fixed money vocabulary:
  - تكلفة الجملة (wholesale)
  - سعر التجزئة (retail)
  - التكلفة الأساسية للمزوّد (provider base cost)
  - عمولة (commission)
  - تسوية المزوّد (provider settlement)
  - الرصيد المتاح (available credit)
  - حد الدين (debt line)
  - محجوز (reserved)
  - تسوية محاسبية إدارية (administrative accounting correction)

  The Arabic copy needs the owner's language review.
- Amounts, references, usernames, dates and IDs are LTR (`<bdi dir="ltr">`) with Latin digits. Signs are attached. The direction word is always present.
- Untranslated legacy tree labels are fixed.
- axe 0 serious / critical on the curated set; labels; focus; never colour alone; labelled dialogs; reduced motion.

---

## 16. Security and money-safety invariants

1. **Authorization chain:**
   1. RouteMap rule;
   2. controller (thin);
   3. Backoffice;
   4. accepted service (capability, scope, `revalidateActor`).

   Hidden UI is never authorization.
2. **DataScope everywhere:** all Finance / Domain reads use `scopeOf` + `staffPredicate` / `customerPredicate`. Out-of-scope and nonexistent give an identical `NOT_FOUND`. Exports use the identical predicate (one cursor).
3. **No API:**
   - none of `finance`, `pricing`, `operations` or `activation/{cancel,resume}` in `API_ALLOWED`;
   - `reseller` explicit list;
   - the API half of P7 stays fail-closed (tested per route).
4. **CSRF** on every POST.
5. **Browser input is never authority.**
   - **Amounts** are typed by the operator and parsed only by the accepted `Money` grammar inside the services. Backoffice performs only a lossless digit mapping (Arabic-Indic / Persian digits and the Arabic decimal separator → ASCII); commas are still refused; no rounding.
   - **Balances, previews, wholesale, commission and settlement** are always read on the server.
   - **References** are server-generated: `WEB-<OP>-<yyyymmdd>-<16 hex>`.
   - **Review → execute binding:** the review step issues a **confirm token** = HMAC-SHA256 under a per-session key (`$_SESSION['nuxr_backoffice_key']`, 64 hex, never rendered) over {op, target ids, normalised amount, reason hash, reference, external reference}. Execute recomputes it from the posted values, and a mismatch fails closed **before any service call** ("This confirmation is no longer valid; review again"). A tampered hidden field or a forged reference cannot reach a service with unreviewed values.
   - A controller guard forbids posting `balance|available|wholesale|retail_effective|commission|settlement|provider` fields.
6. **Duplicate submit / replay:**
   - pending-submit protection (WP6A);
   - the same reference gives a service replay (`replayed = true`, shown as "Recorded once");
   - a changed payload with the same reference gives `IDEMPOTENCY_CONFLICT`, nothing written;
   - absolute setters (debt limit, pricing) are naturally idempotent (same value = no-op).
7. **Integer-minor `Money`; no float** in any WP6B file. Arithmetic only in Finance.
8. **Immutable ledger:** no edit or delete path; corrections only through accepted compensating primitives.
9. **Locks / transactions unchanged:**
   - only accepted services open finance transactions;
   - reads take no lock except the bounded pricing pages (one tree lock per page);
   - no network inside finance transactions (the activation wrappers call accepted primitives that already keep RADIUS I/O outside).
10. **No secrets / raw errors:**
    - fixed messages and an `ErrorReporter` reference;
    - no event metadata, RADIUS digests, voucher codes or session keys in HTML or CSV;
    - CSV formula neutralisation.
11. **Escaping:** every new template is in the WP6A / WP6B escaping guard.

---

## 17. Test strategy

**Cadence:**
1. focused tests while building;
2. **one** canonical full validation at the first complete candidate (testdox both engines + registry, route matrix once, lint);
3. focused owner-review fixes;
4. at most **one** final full validation if those fixes change runtime code.

New suites `wp6b-unit` / `wp6b-integration`:

| Area | Tests |
|---|---|
| Boundary guards | Backoffice has no SQL / `tbl_` / Money arithmetic / internal primitives; controllers and templates have no Finance / Radius / tables; Finance read models are SELECT-only; the activation-table allow-list is exact; no API exposure of any WP6B route |
| Capability / scope | Route × role matrix (sa, ad, A, B, A1, rp, E, F; frozen Agent: reads yes, mutations no) |
| Cross-tenant / IDOR | Forged ids on every read, mutation and export (sibling, ancestor, Sales, nonexistent) → identical `NOT_FOUND`, all-table checksums unchanged |
| Upstream disclosure | Agent HTML **and** CSV never contain base cost, provider settlement, ancestor identities / margins (seeded unique sentinel values, asserted absent); pricing pages equal the `PricingReadModel` output |
| Mutation path | Each money operation ends in the accepted service (fault hook / ledger evidence); the review writes nothing; the confirm token rejects tampered amount / target / reference / reason; posted balance fields are ignored |
| Replay | Double POST → one transfer (`replayed`); changed payload → `IDEMPOTENCY_CONFLICT`, nothing written; refusal → same reference reusable |
| Corrections | Adjust on a missing wallet → refused, no wallet created; debt-limit decrease under exposure refused; reversal only for reversible transfers; typed confirmations |
| Dead actions | §13.1 state matrix; the Operations Center renders only the accepted operations |
| Uncertain states | Ambiguous / unknown wording; never "failed"; no metadata |
| Exports | Screen rows ≡ CSV rows (set equality) per role and filter; caps refuse; date range required; formula neutralisation; audit row without contents |
| Navigation | Agent primary = Home / Customers / + / Wallet / More; Sales / Admin / Report unchanged; every rendered link opens; no empty destination |
| Performance | Pricing pages take exactly one tree lock (lock-acquisition counter in the test); bounded page sizes; no N+1 (query-count assertion on list pages) |
| Arabic / RTL | `dir="rtl"`; LTR tokens; no untranslated new keys; tree labels translated |
| Route matrix | All 1477 accepted rows byte-identical; only new rows added |

---

## 18. Visual QA

One curated pass at the candidate: about **21 screenshots** plus 3 tablet probes. Each capture records axe, page overflow at 390 / 360 px, bottom-navigation visibility, external requests and console errors.

| # | Screen |
|---|---|
| 1 | Admin wallets (desktop) |
| 2 | Agent wallet home (phone, Arabic) |
| 3 | No-wallet state |
| 4 | Ledger + filters (desktop) |
| 5 | Ledger cards (phone, Arabic) |
| 6 | Funding review |
| 7 | Withdrawal typed confirmation (phone) |
| 8 | Agent transfer review (phone, Arabic) |
| 9 | Replayed result |
| 10 | Debt-limit review (projected available) |
| 11 | Debit adjustment typed confirmation |
| 12 | Transfer reversal review |
| 13 | Tree drill-down (phone 390 and 360, Arabic; bottom navigation visible) |
| 14 | Reseller detail (desktop) |
| 15 | Tree split (tablet) |
| 16 | Admin plan grid (paged) |
| 17 | Agent catalog + child wholesale dialog (phone, Arabic) |
| 18 | Settlement card Admin vs Agent (redacted) |
| 19 | Commissions + CSV |
| 20 | Operations Center categories (desktop) |
| 21 | Activation view, VERIFIED (no Reconcile; Rollback with one-way warning) |
| 22 | Sales More sheet (no finance) |

---

## 19. Implementation slices (reviewable commits, no squash)

| Slice | Content |
|---|---|
| **0** | `API_ALLOWED['reseller']` explicit list (first). Boundary guard amendments (§20). RouteMap re-pin. AppSurfaceTest P7 rewrite. **Dead-action fix** (VERIFIED and ROLLBACK_PENDING) + one-way rollback warning + the §13.1 regression test. |
| 1 | `LedgerReadModel`, `WalletPreview`, `TransferService::reversalPreview`; `WalletDesk` reads; `finance` GET pages; `ExportStream` |
| 2 | Funding / withdrawal and transfer (review / confirm token / execute) |
| 3 | Accounting corrections (debt limit, credit / debit adjustment, transfer reversal) |
| 4 | `ResellerReadService`; tree restyle (desktop / tablet / phone drill-down, translations); `reseller/detail`; `customers/list` Sales filter |
| 5 | Bounded `PricingService` reads (OD-9); `PricingDesk`; `pricing` pages and setters |
| 6 | `SettlementReadModel` (allow-list amendment); settlement card; finance reports and exports |
| 7 | `ActivationOperatorService` (cancel / resume / stale page); `OperationsDesk`; `operations` pages |
| 8 | Navigation (Agent Wallet, groups, quick actions), dashboard tiles, Arabic, WP6B guards complete, docs |
| — | Candidate full validation, curated visual QA, evidence |

---

## 20. Files expected to change

**New:**
- `Reseller/Backoffice/{WalletDesk,PricingDesk,SettlementDesk,OperationsDesk,ExportStream,ConfirmToken,Messages}.php`
- `Reseller/Finance/{LedgerReadModel,SettlementReadModel,WalletPreview}.php`
- `Reseller/Activation/ActivationOperatorService.php`
- `Reseller/Domain/ResellerReadService.php`
- `system/controllers/{finance,pricing,operations}.php`
- `ui/ui/admin/{finance,pricing,operations}/*.tpl`
- `ui/ui/admin/reseller/detail.tpl`
- `ui/ui/admin/nuxr/{money-input,ledger-rows,settlement-card,confirm-sheet,…}.tpl`
- `tests/Unit/Wp6b/*`, `tests/Integration/Wp6b/*`
- `docs/WP6B_FINANCE_UX_MODEL.md`
- `owner-review/WP6B/*`

**Modified (additive or presentation):**
- `Finance/TransferService.php` (+`reversalPreview`, read)
- `Finance/PricingService.php` (+ bounded reads)
- `Activation/ActivationStore.php` (+ bounded stale page, reads)
- `Security/RouteMap.php` (**pinned**)
- `Ui/{Navigation,Dashboard,Format}.php`
- `system/controllers/{reseller,activation,customers,dashboard}.php`
- `ui/ui/admin/{reseller/tree,activation/view,dashboard}.tpl`
- `ui/ui/styles/nuxr-shell.css`, `ui/ui/scripts/nuxr-shell.js`
- `system/lan/arabic.json`
- `tests/phpunit.xml`
- amendment notes in the `docs/WP2|WP3A|WP3B|WP5A|WP6A` model docs

**Accepted tests changed (authorized by OD-1; exact diffs listed in the report):**

| Test | Change |
|---|---|
| `Unit/Wp3a/SourceGuardTest` | `testNoEntrypointReachesTheFinanceServices`: allowed callers = `Finance/`, `Activation/`, **`Backoffice/`** (exact); controllers / templates never. Route-name ban → exact allow-list of the `finance` controller actions; `API_ALLOWED` ban kept. RouteMap pin re-set. |
| `Unit/Wp3b/PricingSourceGuardTest` | `testNoEntrypointOrRoute`: pricing callers + `Backoffice/`; `pric*` names / `pricing.*` rules only on the exact `pricing` actions; API clean. `testActivationSnapshotsAreWriteOnce`: + `Finance/SettlementReadModel.php` (SELECT-only asserted). |
| `Integration/Wp3a/AppSurfaceTest::testNoFinanceRoutesOrApi` | API: every WP6B route + the old unmapped names + reseller aliases → `success: false`, no write. Web: unmapped names denied; reseller aliases still at most the tree; new routes per matrix; forged / refused POSTs write nothing. |
| `Http/HierarchyTest` | Tree markup strings, only if the restyle cannot keep them |
| `Integration/Wp6a/ShellNavigationTest` | Agent primary labels; the dashboard "wallet" assertion becomes role-aware |
| `Integration/Wp6a/DashboardTest` | Finance words allowed in finance tiles only |
| `Unit/Wp2/RouteMapTest` | Controller aliases for `finance` / `pricing` / `operations` |
| `Unit/Wp6a/Wp6aSourceGuardTest` | Template list extended, if WP6B templates join its escaping guard |

## 21. Files that must remain untouched / pinned

- `Package.php`, `Balance.php`, `Domain/Tx.php`, `Domain/ServiceGate.php`
- the migration runner and `Migration/*`, R0001–R0007, `install/radius.sql`
- `Radius/RadiusPolicyBuilder.php` (R-15)
- `Finance/{LedgerService,WalletService,ReservationService,LockedWallet,Money,FinanceTx,FinanceInput,PricingReadModel,PricingSnapshot,WalletOwner}.php`
- `Activation/{ActivationService,ReconciliationService,ActivationReversalService,ActivationReadService,DriftReport}.php` (behaviour; reused)
- `Domain/{LegacyCustomerBalanceService,VoucherRedemptionService,LegacyReprovisionService,AutoRenewPolicy,ResellerTreeService,StaffService,CustomerService}.php`
- `Security/{Capability,RoleCapabilityMap,PermissionService,DataScope}.php` (no capability change: OD-5 A, OD-8 A)
- the spec pack / manifest; `main`

**Pinned file requiring explicit authorized change:** only `Security/RouteMap.php` (OD-1), re-pinned in `SourceGuardTest`.

## 22. Schema impact

**NONE.** Everything reads accepted persisted facts using existing indexes:
- ledger `(manager_id, created_at)`, `activation_id`;
- transfers `(from / to, created_at)`, `status`;
- activations by manager / status.

If an item turns out not to be implementable correctly from the accepted facts, that item **stops** and returns as an Owner Decision. No migration for UI convenience; R-15 not reopened.

## 23. Risks / residuals (revision 2)

| ID | Risk |
|---|---|
| R1 | The finance caller surface widens by `Backoffice/`. Mitigated: Backoffice has no SQL, no arithmetic, an allow-listed call set and thin controllers, all guard-proven. |
| R2 | Bounded pricing pages still take the global tree lock (one per page). Pages are small; contention with activations is measured in WP9. |
| R3 | Aggregates scan large ledger / activation sets: date range required, caps, indexes → WP9 load test |
| R4 | `PricingReadModel` `reasons` reveal upstream causes indirectly (accepted contract; not altered) |
| R5 | Agents see descendants' wholesale and debits through the accepted `ledger.read` / `pricing.read` T scopes (consistent with OD-4) |
| R6 | The cancel / resume stale precondition is an application guard mirroring `STALE_SECONDS` (owner may relax) |
| R7 | CLI / information-only items stay operator work: balance HELD, CLAIMED, PRIOR_SECRET, drift kinds, auto-renew "resolved" |
| R8 | On-demand drift with RADIUS reads is slow on large bases |
| R9 | The Arabic finance copy needs owner language review |
| R10 | Emulated devices only for visual QA |
| R11 | Legacy `settings/users*` pages stay legacy-styled (linked) |
| R12 | Legacy reports stay Admin-global (legacy retail transactions) |
| R13 | The confirm token makes a review older than its session invalid (re-review needed after logout or session expiry) |
| R14 | The WP6A residuals continue; R-15 is not reopened |

## 24. Stop conditions

STOP and return to the owner if:
- schema / migration is needed;
- accepted finance / pricing / settlement / activation semantics would change;
- a new mutation or capability is needed (web HELD / CLAIMED / PRIOR_SECRET resolution, auto-renew "resolved", Report mapping);
- an SPA is needed;
- a pinned file other than RouteMap must change;
- Backoffice would need SQL or arithmetic;
- a finance / pricing / operations API is needed;
- reconcile / rollback / cancel / resume preconditions would have to be broadened.

**Rollback:** the branch is additive. Reverting restores the WP6A surface; there are no data changes.

## 25. Definition of Ready — **READY**

| Item | Status |
|---|---|
| WP6A accepted; base `81d49243` verified | ✔ |
| Scope / forbidden scope written | ✔ |
| Frozen decisions identified | ✔ |
| Acceptance tests defined (§17–18) | ✔ |
| Environment (isolated project, lab) | ✔ |
| All owner decisions answered (OD-1 … OD-9) | ✔ |
| No blocking decision open | ✔ |
| Push / deploy default NO | ✔ |
| Worktree clean | ✔ |
| Rollback / data impact known (none) | ✔ |

**Verdict:** **READY.** Implementation starts only on the explicit owner GO.

## 26. Definition of Done

- Slices 0–8 implemented as approved; nothing knowingly omitted; no WP7 / PWA, voucher commerce, finance API or capability change.
- Focused tests per slice.
- One candidate full validation green: both engines, registry, route matrix with **all 1477 accepted rows byte-identical** and only new rows, lint.
- Curated visual QA: axe 0 serious / critical; no overflow at 390 / 360 px; bottom navigation visible; no external requests beyond the documented residuals.
- Accepted-test changes exactly the §20 list.
- Manifest 42/42; R0001–R0007 unchanged; only RouteMap re-pinned; secret sweep clean; AVAIA untouched.
- Docs: `docs/WP6B_FINANCE_UX_MODEL.md`, amendment notes, `owner-review/WP6B/{REPORT.md,TESTDOX.txt,VISUAL_QA.md,visual/}`. The slice-0 dead-action fix is recorded as a WP6A UI defect found in WP6B reconnaissance.
- Push only `wp6b/finance-reseller-reports-ux` after the candidate. Final state: `STOPPED_WAITING_OWNER_REVIEW`.

---

## 27. Adversarial review (revision 2, against the owner's 12 points)

| # | Attack | Where it could fail | Defence in this plan | Residual |
|---|---|---|---|---|
| 1 | **Cross-tenant finance disclosure** | New list queries forget the predicate; a detail id from another branch; the CSV path diverges from the screen | Every Finance / Domain read applies `scopeOf` + `staffPredicate`, the same mechanism as the accepted `WalletService` / `LedgerService`; out-of-scope = nonexistent `NOT_FOUND`; one cursor for screen and CSV; IDOR tests for every route with sibling / ancestor / Sales ids; transfers visible only if from **or** to is in the predicate, and the outside party is masked | None known |
| 2 | **Agent sees provider / upstream data** | Pricing redaction re-implemented wrongly; ledger counterparty / operator above the viewer; the settlement snapshot leaked; reasons | Pricing **only** via `PricingReadModel::project` (no second rule); settlement for an Agent from the ledger only, never the snapshot or provider settlement; identity masking for ids outside the predicate; sentinel-value tests over HTML and CSV | `reasons` codes (accepted contract) |
| 3 | **Finance mutations bypass domain services** | Backoffice writes tables or computes balances; a new "read model" does an UPDATE | Backoffice has no SQL, no `tbl_`, no Money arithmetic and no internal primitives (guard); read models are SELECT-only (guard); the WP3A writer guards stay untouched (`LedgerService` sole writer, etc.); mutation tests assert the accepted-service path | None |
| 4 | **API reaches new WP6B routes** | `'reseller' => '*'` exposes `reseller/detail`; a new controller is added to the API list by mistake | Slice 0 makes `reseller` explicit **before** `detail` exists; no WP6B controller in `API_ALLOWED`; guard asserts the API list excludes finance / pricing / operations; AppSurfaceTest calls every WP6B route through the API → `success: false` | Verify the API check uses the resolved action (pinned by ApiTest / AppSurfaceTest) |
| 5 | **Browser-controlled amounts / references** | A hidden field edited between review and execute; a forged reference colliding with another transfer | Amounts parsed only by `Money` in the services; server-generated references; **HMAC confirm token** over op, target, amount, reason, reference (session key) checked before any service call; a forged reference can at worst cause `IDEMPOTENCY_CONFLICT` (operator / payload compared by the service) | None |
| 6 | **Duplicate submit / replay** | Double click, reload, back / forward | Pending-submit JS; service replay on the same reference (`replayed`); absolute setters are no-ops on repeat; tests for double POST and changed payload | None |
| 7 | **Dead UI actions** | WP6A Reconcile on VERIFIED / ROLLBACK_PENDING; Operations buttons for states without a primitive; Cancel / Resume shown on non-eligible states | Slice-0 fix + the 17-state action matrix test; Center renders buttons only from an explicit state → accepted-operation table; informational items have no buttons | None |
| 8 | **Stale / ambiguous states shown wrongly** | Ambiguity shown as failed; an uncertain refund; a flash "success" after a reconcile that stayed RECONCILIATION_REQUIRED | Explicit categories; results keyed off the returned status, not the flash; `EXTERNAL_STATE_AMBIGUOUS` = "still uncertain, funds reserved"; HELD explains the unknown grant; no automatic actions | None |
| 9 | **Export scope leakage** | Export built on a global query; huge exports exhaust memory; the audit leaks data | Same route / capability / predicate / cursor; caps refuse above 20k; date range required; streaming keyset; audit has metadata only; set-equality tests | None |
| 10 | **Mobile RTL overflow** | Wide tables / tree on phones; bottom navigation pushed off | Cards and drill-down instead of tables (no horizontal-scroll "fix"); curated checks at 390 / 360 px for page overflow **and** bottom-navigation visibility; tree labels translated | Emulated devices only |
| 11 | **N+1 / global-lock performance** | `quote()` per plan (N tree locks); `validateTree` unbounded under one lock; per-row username lookups | Bounded pricing pages with **one** lock per page; `validateTree` not used by the UI; joins for usernames / counts inside the read models; lock-count and query-count tests | Lock contention under load → WP9 |
| 12 | **Accepted route-matrix rows change** | `API_ALLOWED` narrowing; new reseller action named `view` colliding with the helper; alias behaviour | Web matrix rows do not depend on `API_ALLOWED`; new actions avoid helper base names (`detail`, `overview`, …); test asserts the 1477 accepted rows byte-identical and only additions | None |

**Additional self-check:** the review figures (`WalletPreview`) could be mistaken for authority. Labelled "informational; the server re-checks", computed in Finance (not Backoffice), and the services re-check atomically.

No contradiction remains with the owner decisions, the accepted contracts or the frozen decisions.

---

## 28. Owner decisions recorded (2026-10-04)

**OD-1 — APPROVED (Option A with a strict boundary amendment).**
- WP6B intentionally introduces the finance / pricing / operations **web** surface.
- `Reseller\Backoffice\*` is a gateway only:
  - **may:** orchestrate accepted services and compose page models;
  - **may not:** write finance or pricing tables, calculate balances, reconstruct pricing rules, post ledger rows, bypass `WalletService` / `LedgerService` / `TransferService` / `PricingService`, or act as an SQL escape hatch.
- Finance-table ownership stays in `Reseller\Finance`: new finance reads are additive read models there; activation wrappers go in `Reseller\Activation`.
- The guards are amended to **prove** controllers / templates → Backoffice → Finance / Activation, not simply weakened.
- No WP6B controller in `API_ALLOWED`; the API half of P7 stays fail-closed.
- `API_ALLOWED['reseller']` becomes an explicit list of the current actions **before** any new reseller action.

**Slice-0 correction — RECORDED.** Two dead Reconcile states (VERIFIED, ROLLBACK_PENDING) are removed:
- RECONCILIATION_REQUIRED keeps Reconcile;
- Rollback stays for VERIFIED / ROLLBACK_PENDING;
- a regression test proves no always-rejected action is rendered;
- stale in-flight Reconcile appears only when the accepted stale rule is satisfied;
- no semantic broadening;
- recorded as a narrow WP6A UI defect found in WP6B reconnaissance.

**OD-2 — APPROVED.** Debt limit, credit / debit adjustments and transfer reversal, Admin / SuperAdmin only, as visibly administrative accounting operations:
- **Debt-limit review** shows the current limit, balance, reserved exposure, current available, the proposed limit and the projected available.
- **Adjustments** are explicit "Credit Adjustment" / "Debit Adjustment" (never "edit balance"), with amount, reason, generated reference and review; a missing wallet is never created.
- **Reversal** only of an existing reversible transfer; no generic ledger reversal.

**OD-3 — APPROVED (role-specific).**
- Agent: Home / Customers / + / Wallet / More (replaces Activations in the bottom navigation).
- Sales: WP6A unchanged.
- Admin / SA: WP6A unchanged; finance destinations in More / sidebar / desktop.
- No empty destinations.

**OD-4 — APPROVED.**
- Pricing visibility follows `PricingReadModel` exactly (T: own + subtree; chain starts at the viewer; base cost, provider settlement and ancestor identities / costs / margins hidden; upstream = `UPSTREAM`). No duplicated redaction.
- Ledger: in-scope reasons are not newly redacted; general secret / error redaction still applies.
- Reporting: no upstream identities, margins, provider data or other branches.

**OD-5 — APPROVED (A).** Report role gets no new reseller-domain visibility (no `wallet.read`, `ledger.read`, `pricing.read`, `activation.read` or finance exports). Agent reports only where the Agent already holds the underlying capability.

**OD-6 — APPROVED.**
- Exports use the same capability, DataScope and server query / filter model as the screen.
- Server-side; date-range bounded for time series; row-bounded; memory-bounded.
- Audited with actor, type, filters / range, scope / target and row count, without contents.
- `report.read` grants no finance export.

**OD-7 — APPROVED (narrow wrapper).**
- Web actions, Admin / SuperAdmin only, for: reconcile, rollback, completed-activation reversal / resume reversal, pre-provision cancel, and resume for the states `ActivationService::execute` supports.
- Cancel / resume through a new `Reseller\Activation` wrapper that takes the ActorContext, enforces G activation authority, resolves the reference and calls the accepted primitive (no new transition).
- CLI / information only: HELD resolution, CLAIMED resolution, PRIOR_SECRET_UNRESTORABLE recovery, and anything without an accepted primitive.

**OD-8 — APPROVED.** Operations Center gated by `system.admin`, Admin / SuperAdmin only, no new capability. Viewing implies no mutation; each button maps to its own authorized operation.

**OD-9 — APPROVED (bounded batching).**
- Additive bounded read APIs in `PricingService` / `Reseller\Finance`.
- One consistent tree-locked evaluation per bounded page or target set.
- No N× lock acquisition and no unbounded catalog under one lock.
- Results go through `PricingReadModel`.

**Also recorded:**
- **Read-model ownership rule.** Finance reads → `Reseller\Finance`; activation operational reads → `Reseller\Activation` (or the accepted readers); Backoffice coordinates; `Ui\*` stays presentation-only.
- **Operations Center categories.** Needs attention / Waiting-held / Failed-uncertain / Informational-CLI required / Resolved-history.
- **Tree phone defect accepted for the WP6B fix.** Drill-down cards rather than horizontal scroll; bottom navigation fully visible at ≤ 390 px; labels translated.
- **Schema.** None expected; stop and return if one is required. R-15 not reopened.
- **Testing cadence confirmed:** focused → one candidate full → focused fixes → at most one final full; curated visual QA only.

---

WP6B_FINAL_PLAN_READY_WAITING_IMPLEMENTATION_GO
