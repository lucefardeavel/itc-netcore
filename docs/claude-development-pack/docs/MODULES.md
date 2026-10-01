# Module Map

## Existing foundation modules to preserve/reuse selectively
- Customers
- Plans/Bandwidth/Pools
- Routers/NAS data
- Vouchers core for approved legacy compatibility only
- Notifications
- Invoice rendering / transaction compatibility
- Customer portal where compatible and safely adapted
- FreeRADIUS tables/integration
- MikroTik device handlers as legacy/compatibility assets
- Smarty/theme/plugin infrastructure as presentation helpers

## New authoritative domain modules
### Identity/Context
ActorContext

### Reseller Organization
ResellerTreeService
PermissionService
DataScope

### Customer/Subscription
CustomerService
SubscriptionService
Sales assignment handling

### Finance
WalletService
Reservation handling
LedgerService
PricingService
Commission/Settlement responsibility
Provider funding/withdrawal operations

### Provisioning
ProvisioningAdapter
ProvisionResult
RadiusRepository
RadiusPolicyBuilder
RadiusProvisioner
CoAClient
MikrotikNasService

### Accounting
AccountingService
Stale-session reconciliation if needed

### Orchestration
ActivationService
ReconciliationService
Compatibility bridge/gates for legacy service-granting entrypoints

### Presentation
Adaptive app shell using existing server-rendered stack by default
Subscriber 360
Activation flow
Wallet/Reseller/Pricing views
Reports/Exports

### PWA
Manifest
Service worker
Connectivity state
Safe per-user cache strategy
Install/update/deep-link/notification plumbing
