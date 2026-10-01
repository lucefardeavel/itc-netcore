# Handoff and Review Protocol

## From owner/spec chat to Claude
Send:
1. this package or the relevant specification subset
2. one WP prompt only
3. repository access/path details if needed
4. for WP1+, the owner-accepted prior-WP HEAD/review artifact or a repository state that clearly contains it; `review/ACCEPTANCE_LEDGER.md` is the persistent owner-controlled record when maintained
5. explicit instruction about push/deploy (default NO)

## Execution-base rule
- WP0 execution base = pinned upstream baseline.
- WP1+ execution base = owner-accepted previous WP HEAD.
- The pinned upstream baseline remains ancestry/reference only after WP0.
- If the repository current HEAD does not match/descend from the accepted prior-WP state, return `NOT_READY` and do not guess/reset.

## From Claude back to owner
Claude returns the Evidence Packet defined in `15_CLAUDE_RULES.md` and creates the durable owner-review artifact under `owner-review/WP<N>/REPORT.md` (or owner-approved equivalent), plus any requested patch/archive.

## Owner review outcomes
- ACCEPTED: WP closed; its HEAD becomes the required base for the next WP.
- ACCEPTED_WITH_NOTES: closed with documented non-blocking notes; its HEAD becomes the required base.
- FIX_REQUIRED: same WP continues; no next WP.
- REJECTED: revert/rework as instructed.
- DECISION_REQUIRED: specification conflict requires owner decision before continuing.

## Change control
Any frozen change must state:
- old decision
- proposed new decision
- reason/evidence
- affected WPs/docs/tests
- migration/backward-compatibility impact
Owner approval required before implementation.

## Previous planning artifacts
Earlier generated WP0/WP1 code/SQL artifacts are planning references only unless the owner explicitly re-authorizes a specific file after Claude verifies it against the repository. Do not copy them blindly into production code.

## Acceptance ledger
When the owner explicitly accepts a WP, the acceptance decision and exact HEAD should be recorded in `review/ACCEPTANCE_LEDGER.md` (or an owner-approved repository equivalent). Claude must not self-accept a WP.
