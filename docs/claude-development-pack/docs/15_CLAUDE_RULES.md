# Claude / Coding Agent Rules

These rules are mandatory.

## Before editing
1. Read the supplied specification docs in `START_HERE.md` order.
2. Inspect the exact repository and relevant files.
3. WP0 starts at the pinned upstream baseline. WP1+ starts from the owner-accepted prior-WP HEAD. Do not reset later work to baseline.
4. Report actual findings, not assumptions.
5. Identify any conflict between spec and code.
6. Do not silently reinterpret an owner decision.
7. Satisfy `docs/DEFINITION_OF_READY.md` or return `NOT_READY`.

## Scope
- Work only on the current WP.
- Do not implement future WPs “while here”.
- Do not redesign frozen architecture for taste/preferences.
- If a spec requirement is technically impossible or unsafe, stop that part, document evidence, and propose the smallest decision change.

## Repository hygiene
- start from a clean worktree or clearly explain unrelated pre-existing changes
- state branch/worktree/HEAD before changes
- state the accepted prior-WP base SHA for WP1+
- prefer a WP-specific branch/worktree unless owner explicitly instructs another workflow
- no unrelated formatting/refactors
- preserve all upstream copyright/license notices
- upstream license metadata is currently inconsistent; do not remove or relicense notices based on one metadata file
- do not push, merge, tag, deploy or touch production unless explicitly instructed

## Implementation
- use existing project conventions when they do not violate the spec
- default frontend strategy is existing server-rendered/Smarty stack + progressive enhancement; do not introduce a new SPA framework without owner approval
- centralize security/finance logic
- no copy-pasted ad-hoc permission filters
- no browser-trusted money/state
- do not repurpose `tbl_plans.price` as wholesale cost
- no direct reseller wallet mutations outside finance service
- no destructive `radacct` reset for lifecycle operations
- no blind retry of UNKNOWN network outcome
- no legacy service-granting bypass for reseller-owned customers

## Tests
Run relevant automated/static/integration tests. Add tests when the repo lacks them. A command that was not run must never be reported as passed.

## Durable owner-review artifact
For every WP, create `owner-review/WP<N>/REPORT.md` (or an owner-approved equivalent path) containing the evidence packet below. Do not include secrets. This artifact is part of the WP deliverable and must match the final chat response.

## Evidence packet after each WP
Return exactly:
A. BASELINE ANCESTRY / ACCEPTED PRIOR HEAD / CURRENT HEAD / branch / worktree
B. SCOPE IMPLEMENTED
C. FILES CHANGED
D. IMPORTANT DESIGN NOTES
E. MIGRATIONS / DATA IMPACT
F. TEST COMMANDS + EXACT RESULTS
G. SECURITY / FAILURE CASES TESTED
H. GIT DIFF SUMMARY
I. KNOWN RISKS / TODOs
J. PRODUCTION STATUS
K. PUSH STATUS
L. OWNER-REVIEW ARTIFACT PATH
M. FINAL STATE: `STOPPED_WAITING_OWNER_REVIEW`

## Forbidden wording
Do not say “done” if tests or acceptance criteria are incomplete. Use precise states such as `IMPLEMENTED_NOT_ACCEPTED`, `BLOCKED`, `NOT_READY`, or `WAITING_OWNER_REVIEW`.
