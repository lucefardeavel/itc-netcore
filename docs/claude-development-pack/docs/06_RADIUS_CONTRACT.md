# FreeRADIUS Contract

## Authority
FreeRADIUS is the primary AAA authority for V1 subscriber service.

## Functional separation
### RadiusRepository
CRUD for relevant RADIUS tables/attributes only. No business pricing/wallet decisions.

### RadiusPolicyBuilder
Builds the exact RADIUS policy required from customer + plan + subscription state.

Potential attributes include:
- Cleartext-Password
- Simultaneous-Use
- Port-Limit where justified
- Mikrotik-Rate-Limit
- Framed-Pool
- Framed-IP-Address
- Expiration
- WISPr-Session-Terminate-Time
- Max-Data
- Max-All-Session

Only apply attributes appropriate to the service/plan.

### RadiusProvisioner
Implements provisioning lifecycle through the repository/policy builder.

### AccountingService
Read/query session and usage history. Must not destroy historical accounting during standard subscriber lifecycle operations.

### CoAClient
Hardened disconnect/CoA execution. Avoid building unsafe shell command strings from user-controlled values.

## Accounting invariant
`radacct` is historical evidence. Renew/change-plan should update policy and disconnect/CoA as required, allowing Stop/New Session behavior. Do not delete accounting history to force a reset.

## Legacy finding to verify/fix
Pinned upstream `Radius::customerAddPlan()` was observed to call disconnect and then delete accounting records for the username. Claude must re-verify this in the pinned repo and remove/replace the destructive behavior as part of the RADIUS-hardening WP, not earlier.

## Verification requirements
- authentication works for PPPoE and Hotspot
- rate limit applied
- simultaneous-use policy applied
- dynamic pool works
- static IPv4 works
- expiration works
- CoA/disconnect observable
- accounting history retained
- open/stale session handling defined
