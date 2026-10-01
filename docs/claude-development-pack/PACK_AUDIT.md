# Pack Audit v1.1

Status: `INTERNAL_CONSISTENCY_REVIEW_PASSED_PENDING_CLAUDE_WP0_REPO_VERIFICATION`

This pack supersedes v1 after a full consistency audit.

## Corrections made from v1
1. Mandatory read order now includes Business Rules, Frozen Decisions, Modules, Definition of Ready, Definition of Done and Reference Findings.
2. Later WPs explicitly build on the owner-accepted prior-WP HEAD; the upstream baseline is ancestry/reference only after WP0.
3. Upstream license metadata conflict is explicitly documented instead of asserting one license interpretation.
4. Legacy `tbl_plans.price` is protected as customer/retail compatibility data and may not be silently reused as wholesale/base cost.
5. Sales assignment is modeled separately from customer ownership and creator audit identity.
6. Existing unassigned customers are Admin/SuperAdmin-only until audited assignment; no `created_by` inference.
7. Admin/SuperAdmin provider funding/withdrawal is defined separately from reseller parent-child transfers.
8. Provider/system settlement is defined as non-reseller-wallet settlement; margin conservation is explicit.
9. Wholesale cost inheritance and parent-set child pricing semantics are explicit.
10. Legacy service-granting entrypoints (cron, customer balance, payment, voucher, API/plugin) are inventoried in WP0 and must be adapted or fail closed for reseller-owned customers.
11. Reseller voucher-generation commerce is deferred by default until a separate settlement model is owner-approved; voucher paths cannot bypass wholesale rules.
12. PWA cache isolation/clearing rules were added.
13. Default frontend implementation strategy is server-rendered/Smarty + progressive enhancement; no new SPA without owner decision.
14. Staging backup/restore is an executed release test, not documentation only.
15. Every WP must produce a durable owner-review artifact.
16. Oversized implementation stages were split into WP3A/WP3B, WP5A/WP5B and WP6A/WP6B to reduce agent scope and hallucination risk.
17. Added an owner-controlled acceptance ledger so accepted HEAD ancestry survives chat/session changes.
18. Canonical methodology file names now exist: `DECISIONS.md`, `MODULES.md`, `DEFINITION_OF_READY.md`, `DEFINITION_OF_DONE.md`.

## What this audit does not claim
This pack does not prove implementation correctness. WP0 must still verify every repository-dependent finding against the exact pinned baseline before code changes begin.
