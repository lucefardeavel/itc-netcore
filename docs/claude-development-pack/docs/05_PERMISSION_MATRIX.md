# Permission and Data-Scope Matrix

## SuperAdmin/Admin
May manage the complete platform subject to normal safety/audit rules. Provider funding/withdrawal and destructive/override actions must be explicit and audited.

## Reseller / Agent
May see and manage only authorized subtree data.
Typical capabilities:
- own and descendant resellers
- Sales under authorized subtree
- customers owned by authorized subtree
- assign/unassign Sales only within authorized owner scope
- approved activation/renew/change operations
- direct-child wholesale pricing according to parent-child rules
- own retail price according to policy
- own wallet and permitted direct-child funding/transfers
- scoped ledger/activations/reports/accounting views

Must not:
- see sibling/unrelated trees
- access system secrets/RADIUS low-level credentials
- mutate root/base plan technical definition unless separately authorized
- set its own wholesale cost
- delete financial history
- use legacy service-granting routes to bypass wholesale settlement

## Sales
Operational role, not reseller-wallet owner.
Typical capabilities:
- customers explicitly assigned to that Sales account
- create customer under parent reseller; new customer is commercially owned by parent and assigned to creator Sales by default
- activate/renew only if parent policy permits
- view own activity

Must not:
- see all parent-reseller customers merely because they share the same manager
- create resellers
- manage wholesale/retail pricing
- transfer wallet balance
- view unrelated subtree finance

When a customer changes owner manager, Sales assignment is cleared unless an explicitly valid Sales user under the new owner is assigned as part of the audited transfer.

## Report / Viewer legacy role
New reseller-domain routes deny this role by default unless an explicit read-only capability mapping is approved. WP0 must document existing legacy Report behavior. Do not accidentally grant global reseller-domain visibility merely to preserve a legacy menu.

## Unassigned legacy customers
Customers with null/unassigned commercial owner are Admin/SuperAdmin-only in the new reseller domain until explicit audited assignment/import. Do not infer access from `created_by`.

## Voucher boundary
Reseller voucher generation/commerce is not enabled by default in V1. If any legacy reseller voucher view remains exposed, it must obey DataScope and must not enable uncharged service. Customer voucher redemption that grants reseller-owned service must not bypass activation/wholesale rules.

## Object-level enforcement
Server side checks apply to:
- customer view/edit/sync/recharge/suspend/delete attempts
- reseller view/edit/move
- Sales assignment
- activation
- wallet/ledger/funding/transfer
- pricing
- voucher paths if exposed
- accounting/session
- report
- CSV/export
- API/service endpoints and callbacks

UI visibility is not authorization.

## Tree invariants
- no self-parent
- no cycles
- parent type rules enforced
- subtree moves admin-only unless later approved
- historical finance remains tied to original actors/managers

## Direct-child transfer rule
Default V1 reseller transfer policy: reseller balance transfer only to a direct child reseller. Admin/SuperAdmin provider funding is a separate audited operation, not a reseller-tree transfer.
