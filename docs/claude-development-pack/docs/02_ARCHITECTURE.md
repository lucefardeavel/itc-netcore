# Architecture Contract

## Logical architecture

```text
Adaptive Web/PWA UI
        |
ActorContext + PermissionService + DataScope
        |
+-------------------- Domain --------------------+
| Customer / Subscription | Reseller Tree        |
| Pricing / Commission    | Wallet / Ledger      |
| Activation / Reconcile  | Reporting Scope      |
+--------------------------+-----------------------+
        |
Provisioning abstraction
        |
+-------------------+------------------+
| RadiusProvisioner | MikrotikNasService|
+-------------------+------------------+
        |
FreeRADIUS ----> MikroTik NAS
```

## Layer rules
### Compatibility layer
May call selected PHPNuxBill legacy functions. Must isolate legacy side effects and never bypass new permissions/finance/state invariants. For reseller-owned customers, a legacy entrypoint that grants service must be adapted to the orchestrator or fail closed.

### Domain layer
Contains authoritative business logic. Critical permission/finance logic must fail closed if unavailable.

### Provisioning layer
All network mutations return a structured result with `SUCCESS`, `FAILED`, or `UNKNOWN`.

### UI layer
Never contains authoritative pricing, ownership or finance logic.

## Frontend integration default
V1 defaults to the smallest compatible approach: reuse PHPNuxBill's server-rendered/Smarty stack, custom templates/components and progressive JavaScript enhancement where practical. Do **not** introduce React/Vue/another SPA framework or a second frontend application without an explicit owner decision supported by WP0 evidence.

## Core class targets
- `ActorContext`
- `ResellerTreeService`
- `PermissionService`
- `DataScope`
- `CustomerService`
- `SubscriptionService`
- `PricingService`
- `CommissionService` if separation is useful
- `WalletService`
- `LedgerService`
- `ActivationService`
- `ReconciliationService`
- `ProvisioningAdapter`
- `ProvisionResult`
- `RadiusRepository`
- `RadiusPolicyBuilder`
- `RadiusProvisioner`
- `AccountingService`
- `CoAClient`
- `MikrotikNasService`

Names may be adjusted only when repository conventions justify it; semantic responsibilities must remain intact and the change must be documented in `docs/DECISIONS.md`.
