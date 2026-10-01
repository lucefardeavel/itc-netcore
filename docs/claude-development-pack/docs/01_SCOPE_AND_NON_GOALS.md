# Scope and Non-Goals

## V1 in scope
- PHPNuxBill baseline compatibility.
- Hierarchical Agent/Reseller tree.
- Sales under reseller with explicit customer assignment semantics.
- Customer commercial ownership.
- Wallet + debt limit + reservation + immutable journal.
- Admin/SuperAdmin audited reseller funding/withdrawal.
- Balance transfer parent to direct child under policy.
- Explicit wholesale base cost and reseller price inheritance/validation.
- Multi-level commissions based on wholesale price differences.
- Separate retail/customer price behavior preserved from legacy/customer commerce.
- PPPoE and Hotspot.
- FreeRADIUS primary AAA.
- RADIUS CoA/Disconnect through a hardened client.
- Accounting/session views without destructive history deletion.
- New/Renew/Change Plan/Reactivate orchestration.
- Suspend/Disconnect as non-financial subscription/network actions.
- Adapt/gate legacy service-granting entrypoints for reseller-owned customers.
- Scoped reports and exports.
- Responsive adaptive admin/reseller UI.
- PWA shell/install/update/read-only cached experience.

## Deferred
- Full CRM/helpdesk.
- Inventory/OLT management.
- Full IPv6 policy engine.
- Advanced FUP policy designer.
- Complex tax engine.
- Multi-currency.
- Native mobile applications.
- Complex multi-person approval workflows.
- AI features.
- Offline activation/renewal/financial mutation.
- Reseller voucher-generation/wholesale settlement model unless separately owner-approved.
- Full granular custom permission editor; V1 uses centralized role/capability mapping unless the actual repository proves an existing compatible mechanism worth reusing.

## Explicitly forbidden shortcuts
- Cloning/circumventing SAS4 licensing.
- Treating `tbl_customers.balance` as reseller wallet.
- Direct use of legacy `Balance::*` for reseller finance.
- Direct reseller-controller calls to legacy `Package::rechargeUser()` without approved bridge/orchestrator.
- Direct unscoped `find_one($id)` for reseller-accessible objects.
- Repurposing legacy `tbl_plans.price` as wholesale/base cost.
- Allowing a legacy API/cron/payment/voucher/plugin path to grant reseller-owned service without wholesale settlement/orchestration.
