# Database Requirements

This document specifies semantics, not ready-to-run SQL. Claude must inspect the exact current repository schema and produce the migration from the owner-accepted prior-WP HEAD.

## Required schema additions
### Customer ownership and Sales assignment
Add nullable references for:
- commercial owner manager
- optional assigned Sales user

Do not guess/backfill either field for existing customers without an explicit owner-approved migration policy. Existing null-owner customers remain Admin/SuperAdmin-only in the new reseller domain.

### Reseller tree
A closure representation is preferred:
- ancestor manager
- descendant manager
- depth
- unique ancestor/descendant pair
- indexes supporting ancestor and descendant traversal

### Manager wallets
One wallet per reseller/manager wallet owner.
- decimal money type suitable for IQD and future decimals
- balance
- debt limit
- timestamps/status only if justified

Admin/SuperAdmin provider funding does not require a fake root reseller wallet.

### Reservations
- stable unique relation to a single authoritative business operation
- manager
- amount
- ACTIVE / COMMITTED / RELEASED / EXPIRED (or equivalent)
- resolution timestamps

An activation may not have two active wholesale reservations.

### Ledger
- wallet owner/manager where applicable
- explicit event/business type and direction semantics
- positive amount
- before/after balance snapshots for wallet-affecting entries
- stable unique entry/idempotency key
- activation/business-operation reference when applicable
- counterparty manager when applicable
- reversal relation
- operator
- note/reason/timestamps

Ledger must be treated as immutable by application code. Provider/system settlement may be stored in the activation financial record or an equivalent immutable settlement record rather than pretending it is a normal reseller-wallet credit.

### Wholesale base cost
Provide explicit new-domain storage for a system/provider wholesale base cost per plan used in reseller commerce. **Do not repurpose legacy `tbl_plans.price`** without a separate owner-approved decision after WP0 evidence.

### Manager plan prices
Unique manager/plan configuration as needed for:
- manager effective wholesale-cost override
- optional retail/customer price override
- enabled/availability override if retained
- audit timestamps/operator

Semantics must support inheritance and parent-set child cost rules from `18_BUSINESS_RULES.md`.

### Transfers / funding operations
Support auditable:
- parent -> direct-child reseller transfer
- Admin/SuperAdmin provider funding/withdrawal/adjustment
- amount
- operation status
- unique reference/idempotency
- operator/reason/timestamps/reversal semantics

A provider funding operation is not a fake transfer from a root wallet.

### Activations
- stable reference
- unique idempotency key
- action
- manager and actor
- customer and plan
- current status
- wholesale cost snapshot
- retail/customer price snapshot where relevant
- provider/system settlement amount or auditable equivalent
- plan snapshot JSON
- wholesale pricing-chain snapshot JSON
- legacy PHPNuxBill transaction/recharge/invoice references when created
- error code/message
- timestamps

### Activation events
Append-only event timeline per activation.

## Legacy entrypoint compatibility
WP0 must inventory every service-granting path. WP1 must not add behavior, but schema must not prevent WP5 from binding auto-renew, customer-balance purchase, payment gateway completion, API/plugin entrypoints, and voucher redemption to the same authoritative activation identity/idempotency model.

## Voucher schema
Do not add speculative reseller voucher-commerce tables in WP1. Reseller voucher generation/wholesale settlement is deferred unless the owner approves a separate voucher settlement model. If WP0 proves a minimal owner field is required merely to prevent data leakage in an already-exposed reseller voucher screen, surface that as a decision before migration.

## Constraint requirements
- unique idempotency on activation requests
- no duplicate active financial reservation for one activation/business operation
- unique manager-plan configuration
- unique closure path pair
- valid manager/Sales assignment references matching actual signedness/types
- indexes for manager/customer, assigned Sales/customer, activation status/date, ledger manager/date, descendant/ancestor queries

## Migration behavior
- staging/disposable clone first
- strict failure behavior
- no silent swallowing of failed DDL
- explicit rollback plan
- post-migration verification
- legacy behavior unchanged at the database-foundation WP

## Forbidden in database-foundation WP
- UI changes
- RADIUS changes
- RouterOS changes
- wallet business activation
- guessed ownership/Sales-assignment migration
- repurposing legacy retail-price columns
- production deployment
