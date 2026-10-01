# Owner Review Checklist

Use after every Claude WP response.

## Repository chain
- Is upstream baseline ancestry correct?
- For WP1+, did Claude start from the owner-accepted prior-WP HEAD rather than resetting to baseline?
- Exact current HEAD/branch/worktree supplied?
- Durable `owner-review/WP<N>/REPORT.md` (or approved equivalent) present and consistent with response?

## Scope
- Did Claude modify only the requested WP?
- Any hidden extra feature/refactor?
- Any architecture decision changed without approval?
- Any new SPA/framework introduced without owner decision?

## Evidence
- Files changed listed?
- Test commands and exact results supplied?
- Diff summary supplied?
- Known risks honestly stated?

## Security
- Scope/permission enforcement server-side?
- Browser values re-resolved server-side?
- Cross-reseller test present where relevant?
- Unassigned legacy customers fail closed for reseller domain?
- Sales assignment enforced separately from ownership?
- No new secret exposure?

## Finance
- No direct wallet update outside service?
- Legacy `tbl_plans.price` left as retail/default compatibility rather than wholesale?
- Wholesale base cost explicit?
- Admin funding/withdrawal audited?
- Idempotency/concurrency evidence?
- Ledger immutable semantics maintained?
- Provider settlement not accidentally credited as reseller wallet value?

## Legacy entrypoints
- Any route/cron/API/payment/voucher/plugin path that can grant reseller-owned service without orchestrator/wholesale settlement?
- If an entrypoint is not adapted, is it blocked for reseller-owned scope?

## RADIUS/network
- No accounting history deletion?
- FreeRADIUS `radacct` vs local helper accounting table clearly distinguished?
- UNKNOWN outcome handled without blind retry?
- No split-brain local/RADIUS subscriber behavior introduced?

## UX/PWA
- Role-specific navigation?
- Mobile not just squeezed desktop?
- Offline is read-only for critical operations?
- RTL/technical LTR behavior checked?
- User-specific PWA cache isolation/clearing tested?

## Repository / licensing
- No push/deploy unless owner asked?
- Clean or explained worktree state?
- Upstream notices preserved?
- License metadata conflict not silently “resolved” without evidence/owner decision?

## Decision
`ACCEPTED / ACCEPTED_WITH_NOTES / FIX_REQUIRED / REJECTED / DECISION_REQUIRED`
