# Business Rules v1.1

## Reseller hierarchy
- Agent is the reseller role reused from PHPNuxBill.
- Agent may have Agent children recursively.
- Sales may exist under an authorized reseller but is not a reseller-wallet owner.
- Self-parenting and cycles are forbidden.
- Default reseller-to-reseller finance transfer is parent -> direct child.
- Moving a reseller subtree is an Admin/SuperAdmin operation and must be audited.
- Historical ledger/activation records never change ownership because a subtree is moved later.
- If a reseller or required ancestor is inactive/frozen, descendant business mutations are blocked by default; read-only admin visibility remains.

## Customer ownership and Sales assignment
- `manager_id` (or approved equivalent) is current commercial owner.
- `assigned_sales_id` (or approved equivalent) is optional operational assignment.
- `created_by` remains creator/operator audit data.
- Sales-created customers belong commercially to Sales' parent reseller and are assigned to that Sales user by default.
- Sales sees only explicitly assigned customers in V1, not every customer owned by the parent reseller.
- Customer transfer between managers is explicit/audited.
- A manager transfer clears invalid Sales assignment unless a valid Sales user under the new owner is explicitly assigned.
- Historical activations remain associated with their original manager/pricing snapshot.
- Future operations after transfer use the new commercial owner.
- Existing customers with no commercial owner remain Admin/SuperAdmin-only until explicitly assigned; `created_by` is not a migration shortcut.

## Customer balance vs manager wallet
These are distinct accounting domains.
- Customer balance = end-user deposit/payment credit.
- Manager wallet = reseller wholesale operational credit.
They must never share the same field or service.

## Retail vs wholesale price
These are distinct price domains.
- Legacy PHPNuxBill `tbl_plans.price` is used by customer/order/auto-renew paths and must remain a legacy/default retail price unless an owner-approved migration says otherwise.
- System/provider wholesale base cost must be stored explicitly in the new reseller domain.
- Each reseller has an effective wholesale cost for a plan.
- Admin sets top-level reseller wholesale cost; a parent reseller may set the direct child's wholesale cost.
- A reseller does not set its own wholesale cost.
- If no reseller-specific wholesale override exists, effective cost inherits the nearest authorized ancestor override, otherwise the system wholesale base cost.
- A child wholesale cost cannot be lower than the parent's effective wholesale cost in V1.
- Ancestor/global plan disablement blocks descendants from using the plan; a descendant cannot re-enable a plan prohibited above it.
- A reseller may have an optional retail/customer price override according to UI policy. If absent, the approved legacy/default retail price applies.
- An explicitly configured reseller retail price below its own effective wholesale cost is rejected in V1 unless owner later approves a loss-leader exception policy.
- Browser-sent prices are never authoritative.
- Renewal uses prices resolved at renewal time and then snapshots them.
- Historical snapshots never change after later price edits.

## Wholesale billing invariant
Every successful new subscription period for a reseller-owned customer is a reseller-billable event unless an explicit owner-approved exemption exists.
This includes any adapted path that grants or extends service:
- manual activation
- manual renewal
- approved auto-renewal
- customer self-service purchase/balance flow
- payment-gateway completion
- API/service call
- voucher redemption if it grants reseller-owned service

Customer retail payment does not replace reseller wholesale charging. It is a separate retail payment path.

## Legacy service-entrypoint safety
During migration, a legacy route may continue unchanged for unassigned/admin-owned legacy customers if safe. For a reseller-owned customer, a service-granting route must either use ActivationService/orchestrator or fail closed. No hidden bypass is allowed through cron, API, plugin, payment callback, balance purchase, voucher or legacy `Package::rechargeUser()` caller.

## Auto-renewal
Auto-renew must not bypass reseller wallet/pricing/commission rules. It creates the same authoritative activation/billing event as a manual renewal, with actor marked as system/cron and owner manager preserved.

For a reseller-owned customer using customer balance:
- retail/customer balance sufficiency and reseller wholesale-credit sufficiency are both required
- the two finance domains must be committed idempotently around the same authoritative service operation
- if the current legacy path cannot do this safely, auto-renew for that reseller-owned customer must fail closed until adapted

If reseller credit is insufficient or ancestor/business policy blocks renewal, auto-renew fails safely and records a clear reason. It must not silently provide service for free.

## Payment-gateway/customer-order paths
A successful retail payment for a reseller-owned customer does not authorize free wholesale service. Payment completion that grants service must join the same authoritative activation identity/orchestration or remain blocked until adapted. Callback replay must not double-provision or double-charge.

## Voucher boundary
V1 does not enable new reseller voucher-generation/wholesale-commerce behavior by default because prepaid issuance settlement requires its own explicit policy.
- existing/admin voucher compatibility may remain
- customer voucher redemption that grants service to a reseller-owned customer must not bypass wholesale activation rules
- reseller voucher inventory/generation UI stays disabled unless owner approves a separate settlement addendum
- if legacy code already exposes reseller voucher generation, WP0 must identify it and WP5/WP6 must gate it safely rather than leave a bypass

## Commission / margin conservation
The financial engine must be conservative. For a leaf manager wholesale charge `L`:

`sum(all intermediary reseller margin credits) + provider/system settlement = L`

No activation may create or destroy wallet value accidentally.

For a chain with provider base cost 10, R1 cost 12, R2 cost 15, R3 cost 17 and R3 performing the activation:
- R3 wholesale debit = 17
- R2 margin credit = 17 - 15 = 2
- R1 margin credit = 15 - 12 = 3
- provider/system settlement = 12

The provider/system settlement is **not** credited to a normal reseller wallet by default. It is recorded as provider revenue/settlement in the immutable activation financial record or equivalent auditable record. The implementation may separately analyze base cost 10 vs provider/root margin 2, but the externally auditable settlement must conserve the original 17 debit.

## Wallet funding
Admin/SuperAdmin may issue/revoke reseller operational credit through audited funding/withdrawal operations with operator, reason and reference. This does not require a fake root wallet.

A reseller parent-to-child top-up/transfer is a balance transfer, not service revenue. It debits parent wallet and credits child wallet atomically. Later activation settlement/commission is a separate event.

Funding, withdrawal and debt-limit changes must not invalidate active reservations or create negative available credit. V1 blocks a reduction/withdrawal that would make available credit negative unless an explicit owner-approved override/reconciliation process is added.

## Debt limit
Available wholesale credit:
`wallet balance + debt limit - active reservations`

The manager may not create a reservation that would move available credit below zero.

## Reservation
- one active wholesale reservation per authoritative activation operation
- reserve before uncertain external provisioning
- commit exactly once on successful authoritative completion
- release on confirmed failure/cancellation
- UNKNOWN must remain reserved or follow a documented safe timeout/reconciliation policy; it must not blindly release if service may already be active
- verified provisioning followed by a transient finance-commit failure must remain recoverable/idempotent and may not become free service

## Activation idempotency
Repeated click, HTTP retry, page reload, mobile reconnect, duplicate callback, or duplicate background request with the same idempotency identity returns/reconciles the same operation. It does not create a second service period, debit, retail charge or commission.

## Renew
Same plan renewal extends according to approved PHPNuxBill expiry semantics verified during WP0. It creates a new billing event and current-time wholesale/retail snapshot.

## Change plan
V1 has no proration unless owner later changes the contract. New plan is charged according to V1 change-plan semantics and the behavior is shown before confirmation.

## Disconnect / suspend / delete
- Disconnect ends current network session only.
- Suspend blocks service but preserves customer/history.
- Reseller permanent delete is forbidden in V1.
- Admin destructive operations must protect accounting/financial history and prefer disable/archive over destructive delete.

## Failure semantics
- CONFIRMED FAILED: safe compensation/release may execute.
- UNKNOWN: reconciliation required; no blind retry and no premature refund that could create free active service.
- VERIFIED PROVISIONING + FINANCE COMMIT FAILURE: reconciliation must complete idempotent financial settlement or execute an explicitly safe compensating rollback; do not silently complete for free.
- COMPLETED: retail finance where applicable, wholesale finance and provisioning must each be exactly-once from the business perspective.
