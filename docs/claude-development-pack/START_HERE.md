# Claude Development Pack v1.1

Status: `REVIEWED_OWNER_SPECIFICATION_PACK_READY`
Purpose: hand this package to Claude/Coding Agent for implementation, one Work Package at a time, with owner review between packages.

## Absolute rule
This package is a **specification and review contract**, not an implementation drop.
Claude must inspect the real repository before editing and must not treat any earlier draft SQL/code from prior planning as authoritative implementation.

## Upstream baseline
- Repository: `hotspotbilling/phpnuxbill`
- Upstream baseline commit: `d3e0596287322944ee21fcc14dc1798158463140`
- Baseline date: 2026-09-29
- This baseline is project ancestry, not the execution HEAD for every later WP.
- WP0 starts from the exact upstream baseline.
- WP1+ must start from the **owner-accepted HEAD of the previous WP**, and must never reset back to the upstream baseline.

## License metadata warning
The pinned upstream repository contains conflicting license metadata: README states GPL v2 or later, the `LICENSE` file contains GPLv2 license text, and `composer.json` declares MIT. Preserve all upstream notices. WP0 must classify the effective licensing evidence and report the conflict. Do not assume permission to relicense or remove notices.

## Product goal
Build a free/open-source MikroTik + FreeRADIUS ISP subscriber/reseller platform using PHPNuxBill as the compatibility/foundation layer, while adding a SAS4-style business domain for hierarchy, pricing, wallet, commission, debt limit, activation integrity, accounting integrity, and modern adaptive PWA UX.

## Mandatory read order
1. `docs/00_MASTER_SPEC.md`
2. `docs/01_SCOPE_AND_NON_GOALS.md`
3. `docs/DECISIONS.md`
4. `docs/02_ARCHITECTURE.md`
5. `docs/03_DOMAIN_MODEL.md`
6. `docs/18_BUSINESS_RULES.md`
7. `docs/04_DATABASE_REQUIREMENTS.md`
8. `docs/05_PERMISSION_MATRIX.md`
9. `docs/06_RADIUS_CONTRACT.md`
10. `docs/07_MIKROTIK_CONTRACT.md`
11. `docs/08_SECURITY_MODEL.md`
12. `docs/09_UX_INFORMATION_ARCHITECTURE.md`
13. `docs/10_UX_FLOWS.md`
14. `docs/11_PWA_REQUIREMENTS.md`
15. `docs/12_DESIGN_SYSTEM.md`
16. `docs/MODULES.md`
17. `docs/13_TEST_ACCEPTANCE_MATRIX.md`
18. `docs/14_WORK_PACKAGES.md`
19. `docs/15_CLAUDE_RULES.md`
20. `docs/DEFINITION_OF_READY.md`
21. `docs/DEFINITION_OF_DONE.md`
22. `docs/17_REFERENCE_FINDINGS.md`
23. `docs/16_HANDOFF_PROTOCOL.md`
24. `review/OWNER_REVIEW_CHECKLIST.md`
25. `review/ACCEPTANCE_LEDGER.md`
26. `PACK_AUDIT.md`

## Execution rule
Send **only one prompt from `prompts/` at a time**. Do not send the whole project as a single implementation request.

## Owner gate
Every WP ends with `STOPPED_WAITING_OWNER_REVIEW`. No automatic next WP, no push, no deployment unless the owner explicitly approves.

## Integrity manifest
- `MANIFEST.sha256` verifies the immutable owner-approved specification pack (`sha256sum -c MANIFEST.sha256` from this directory).
- `review/ACCEPTANCE_LEDGER.md` is deliberately excluded because it is mutable owner-controlled project state.
- Changes to `review/ACCEPTANCE_LEDGER.md` are valid only when explicitly authorized by the owner.
- A manifest verification must not treat an authorized ledger change as specification corruption.
