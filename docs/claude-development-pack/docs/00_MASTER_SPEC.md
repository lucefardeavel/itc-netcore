# MASTER SPEC v1.1

Status: `DESIGN_FREEZE_APPROVED_REVIEWED`

## Vision
Create an ISP subscriber and reseller management platform that feels operationally close to proven SAS Radius workflows while remaining free/open-source and maintainable. Reuse PHPNuxBill where it is already useful; do not rebuild AAA, plans, customers, vouchers, routers, invoices, and notifications without a reason.

## System authorities
- PHPNuxBill: compatibility/foundation and selected legacy capabilities.
- New Reseller Domain: authority for reseller hierarchy, permission scope, wholesale pricing, wallet, ledger, commissions, transfers, and activation orchestration.
- FreeRADIUS: primary subscriber AAA authority.
- FreeRADIUS `radacct`: authoritative historical accounting record for RADIUS sessions; never destructive on normal renew/change-plan flows.
- MikroTik: NAS and operational network integration, not a second independent subscriber source of truth.
- Wallet Ledger: reseller financial source of truth.
- Activation State Machine: provisioning/business-operation source of truth.
- DataScope: mandatory server-side authorization boundary.

## Core roles
- SuperAdmin
- Admin
- Agent = Reseller
- Sales
- Report/Viewer legacy role, deny-by-default on new reseller-domain routes unless explicitly mapped
- Customer

## Core V1 capabilities
1. Multi-level reseller hierarchy.
2. Customer commercial ownership separate from creator audit identity.
3. Optional Sales assignment separate from ownership/creator identity.
4. Reseller wallet, reservations, debt limit, immutable ledger.
5. Explicit wholesale base cost plus per-reseller wholesale cost/pricing chain and upward commission/margin settlement.
6. Retail/customer price remains a separate concern from wholesale reseller cost.
7. PPPoE and Hotspot via FreeRADIUS-first provisioning.
8. Static IPv4 and dynamic pool support.
9. Safe activation, renewal, plan change, reactivation.
10. Idempotency and reconciliation for uncertain network outcomes.
11. Scoped reports, exports and accounting views.
12. Legacy service-granting paths cannot bypass reseller finance/orchestration for reseller-owned customers.
13. Desktop, tablet, mobile browser and installed PWA adaptive UX.
14. Arabic-first RTL with technical identifiers displayed correctly LTR.

## Legacy pricing compatibility
The pinned PHPNuxBill code uses `tbl_plans.price` in customer/order/auto-renew flows as a legacy customer-facing price. **Do not repurpose `tbl_plans.price` as reseller wholesale/base cost.** Wholesale base cost must have explicit new-domain storage derived during WP1 after WP0 confirms the schema and usage.

## Existing-customer ownership rule
Existing customers with no new commercial owner remain `UNASSIGNED` for the reseller domain and are Admin/SuperAdmin-only until explicitly assigned through an audited action/import policy. Do not infer ownership from `created_by`.

## Legacy entrypoint rule
Any path that creates or extends service for a reseller-owned customer must either:
1. enter the authoritative activation/orchestration path, or
2. be blocked for that scope until safely adapted.

This includes manual recharge, customer-balance purchase, auto-renew cron, payment-gateway completion, voucher redemption where it grants service, API endpoints, and plugin/hook paths discovered during WP0.

## Voucher V1 boundary
Existing/admin voucher functionality may remain for compatibility, but reseller voucher commerce/generation must not create uncharged wholesale service. Reseller voucher generation/sale is disabled by default in V1 until an owner-approved settlement model is added. Voucher redemption for a reseller-owned customer may not bypass wholesale activation rules.

## Non-negotiable invariants
- Never trust browser-supplied money, price, commission, ownership, state, or authorization.
- Never directly mutate reseller balance without a ledger-backed service.
- Never update/delete ledger history; corrections are compensating entries.
- Never delete RADIUS accounting history during renewal or plan change.
- Never let an Agent/Sales access an object outside authorized scope by changing an ID/URL/POST body.
- Never treat UNKNOWN provisioning outcome as FAILED.
- Never blindly retry a network mutation after an uncertain outcome.
- Never make offline PWA data authoritative for a financial/network mutation.
- Never expose RouterOS API publicly as a normal deployment design.
- Never reset a later WP back to the upstream baseline; build on the owner-accepted prior WP HEAD.

## Design philosophy
Minimal invasive core patches, explicit domain services, documentation-first execution, strict work-package boundaries, evidence before acceptance.
