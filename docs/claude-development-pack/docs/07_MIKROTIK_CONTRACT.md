# MikroTik Contract

## Role
MikroTik is the NAS and network enforcement endpoint. The new platform should avoid maintaining a parallel local subscriber source of truth when FreeRADIUS is used.

## Normal V1 path
`Subscriber Domain -> FreeRADIUS -> MikroTik NAS`

## MikroTik API responsibilities
- NAS/router health
- diagnostics
- active-session lookup
- disconnect fallback/verification
- RADIUS client/NAS synchronization where needed
- operational information

## Deployment security
- RouterOS API must not be publicly exposed by default.
- Prefer WireGuard/private management path.
- Restrict RADIUS/CoA to trusted NAS/server sources.
- Secrets never exposed in reseller UI.

## Legacy drivers
Existing `MikrotikPppoe`, `MikrotikHotspot`, and related device classes may be retained for compatibility/fallback, but the new reseller activation flow must not create a split-brain subscriber state by mixing local and RADIUS provisioning casually.

## Baseline defects
Known candidate bug from planning review: Hotspot `change_username()` appeared to test a variable different from the fetched ID variable. Claude must verify before modifying and record exact evidence.

## Contract testing
Each retained device method used by the new system needs a contract test or an explicit rationale for being legacy-only.
