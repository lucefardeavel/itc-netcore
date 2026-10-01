# Security Model

## Fail closed
If permission/scoping services cannot initialize, reseller mutation routes do not fall back to unrestricted legacy behavior.

## Never trust client values
Re-resolve server-side:
- plan wholesale cost
- manager effective cost
- retail price if authoritative
- commission chain
- wallet balance/available credit
- manager ownership
- Sales assignment
- customer ownership
- activation state
- completion status

## IDOR prevention
Every object access combines role/capability + scope check. Guessing an ID must never reveal another reseller's data.

## Legacy bypass prevention
For a reseller-owned customer, every path that can create/extend service must use the authoritative orchestration path or be blocked until adapted. WP0 inventories these paths; WP5 closes them. Include web routes, cron/auto-renew, customer balance/order flows, payment callbacks, API endpoints, voucher redemption, and plugin/hook callers discovered in the repository.

## Finance controls
- transaction/row-lock protection around wallet commits, funding and transfers
- reservation before uncertain external provisioning
- unique idempotency keys
- immutable ledger semantics
- no direct balance writes outside wallet service
- provider/system settlement cannot masquerade as a normal reseller-wallet credit

## Network controls
- private RouterOS API path
- restricted RADIUS/CoA
- secrets protected at rest as reasonably achievable within architecture, and never presented to reseller UI
- command injection prevention in CoA execution

## CSRF/session/auth
Preserve and improve baseline CSRF controls for state-changing web routes. Re-evaluate role/manager status during meaningful mutations. Disabled reseller must lose mutation capability promptly.

## Exports/reports
Same DataScope as UI endpoints.

## PWA/cache
No cached balance/price/permission/state can authorize or determine a mutation.
- do not cache authentication or mutation responses as authoritative content
- namespace safe read-only caches by authenticated identity/version where technically needed
- clear user-specific caches on logout/account switch and when access becomes invalid
- prevent cached data from one user being shown to another user on a shared device
- label last-known/offline data as stale/read-only

## Logging
Do not expose stack traces, DB errors or secrets to ordinary users. Use reference IDs for user-visible errors and detailed server/audit logging. Logs and owner-review artifacts must not contain raw credentials/secrets.
