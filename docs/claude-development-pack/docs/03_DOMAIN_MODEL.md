# Domain Model

## Manager hierarchy
Existing `tbl_users.root` remains for compatibility. The implementation shall support recursive Agent -> Agent relationships plus Sales children, with cycle prevention.

Recommended query model: closure table representing `(ancestor_id, descendant_id, depth)`.

## Customer ownership and assignment
- `created_by`: immutable/legacy audit identity of creator/operator.
- new commercial owner field: `manager_id` or equivalent approved name.
- new optional Sales assignment field: `assigned_sales_id` or equivalent approved name.
- ownership changes must be explicit and audited.
- Sales assignment is mutable but must point to a Sales account authorized under the customer owner manager.
- no silent reinterpretation of `created_by` as mutable ownership or assignment.
- existing customers with no commercial owner are Admin/SuperAdmin-only in the new reseller domain until explicitly assigned.

## Wallet
Manager wallet is separate from customer deposit balance.

Concepts:
- balance
- debt limit
- active reservations
- available = balance + debt_limit - active_reservations

Top-level reseller funding/withdrawal is an audited provider/admin operation and does not require inventing a fake root reseller wallet.

## Ledger
Append-only financial history. Corrections use reversal/compensating entries.

Required semantic event categories must cover at least:
- reseller funding / withdrawal
- activation wholesale debit
- commission/margin credit
- transfer in / transfer out
- refund/reversal
- explicit admin adjustment

Implementation may use a type+direction model instead of these exact enum names, but the business meaning and auditability must remain explicit.

## Pricing
Wholesale and retail are separate domains.
- Legacy `tbl_plans.price` remains a customer/retail compatibility field unless WP0 proves otherwise; it is not wholesale base cost.
- Every plan used in reseller commerce has an explicit system wholesale/base cost in new-domain storage.
- Every reseller has an effective wholesale cost: nearest authorized reseller override, otherwise inherited parent/system base cost.
- Parent/Admin sets a child's wholesale cost; a reseller does not set its own cost.
- Child effective wholesale cost may not be lower than parent effective wholesale cost in V1.
- optional reseller retail price is customer-facing and separate from wholesale settlement; if absent, legacy/default retail price may be inherited according to the approved implementation.
- V1 blocks an explicitly configured retail price below that reseller's effective wholesale cost unless owner later approves an exception policy.
- Activation stores immutable plan and pricing-chain snapshots.

## Commission/system settlement
For a leaf wholesale debit `L`, intermediary reseller margin credits plus provider/system settlement must equal `L` exactly.

Only intermediary reseller margins are credited to reseller wallets by default. The remaining provider/system settlement is **not** automatically credited to a normal reseller wallet; it must remain auditable as provider revenue/settlement through the activation financial record or equivalent immutable record.

## Activation
Financial actions:
- NEW
- RENEW
- CHANGE_PLAN
- REACTIVATE

Non-financial actions:
- SUSPEND
- DISCONNECT current session

## Activation state machine
Nominal path:
`CREATED -> VALIDATED -> FUNDS_RESERVED -> PROVISIONING -> PROVISIONED -> VERIFYING -> VERIFIED -> FINANCIAL_COMMITTING -> COMPLETED`

Exceptional states:
- FAILED
- RETRY_PENDING
- RECONCILIATION_REQUIRED
- ROLLBACK_PENDING
- ROLLED_BACK
- CANCELLED

Every state transition is persisted as an event.

If provisioning has been verified but financial commit cannot complete due to a transient DB/system failure, the operation must remain recoverable/idempotent with reservation preserved as appropriate. It must not silently become free service.

## Provision result
Every network mutation returns:
- status: SUCCESS | FAILED | UNKNOWN
- message
- external/nas identifier where available
- retryable boolean/classification
- metadata

UNKNOWN is not a clean failure and must route to reconciliation.
