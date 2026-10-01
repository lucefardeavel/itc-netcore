# Owner Acceptance Ledger

This ledger is **owner-controlled**. Claude/Coding Agent must never mark a WP `ACCEPTED` or `ACCEPTED_WITH_NOTES` unless the owner explicitly states that decision and authorizes recording it.

Upstream baseline: `d3e0596287322944ee21fcc14dc1798158463140`

| WP | Owner status | Accepted HEAD | Notes |
|---|---|---|---|
| WP0 | ACCEPTED_WITH_NOTES | `c2582368d0111046e39c353ca70b782d8fbb78c4` | Owner decision 2026-10-01. No rework required. Non-blocking notes (must stay visible in later relevant WPs): PDF/gd runtime path not exercised; authenticated UI screens reviewed statically, not end-to-end; external payment-gateway/plugin repositories outside WP0 scope. Accepted implementation HEAD remains `c2582368` although an owner-metadata commit follows it. Decisions: `owner-review/WP0/OWNER_DECISIONS.md`. |
| WP1 | ACCEPTED_WITH_NOTES | `1f36bfa17383efa903011a8316b5b4bf14e6bdc8` | Owner decision 2026-10-01. No rework required. Accepted implementation HEAD remains `1f36bfa` although an owner-metadata commit follows it. Non-blocking note: the migration verifier compares CHECK constraints by name, not normalized expression text; this must be closed by WP9 (Red Team / Release Candidate) before WP9 may be owner-accepted and must not be silently risk-accepted for release. Tested database policy: MariaDB 10.11.x, MySQL 8.0.x, PHP 8.2; other engine generations remain subject to later staging/release verification. Decisions and binding WP2/WP3A/WP3B/WP5A/WP8/WP9/WP10 inputs: `owner-review/WP1/OWNER_DECISIONS.md`. |
| WP2 | PENDING |  |  |
| WP3A | PENDING |  |  |
| WP3B | PENDING |  |  |
| WP4 | PENDING |  |  |
| WP5A | PENDING |  |  |
| WP5B | PENDING |  |  |
| WP6A | PENDING |  |  |
| WP6B | PENDING |  |  |
| WP7 | PENDING |  |  |
| WP8 | PENDING |  |  |
| WP9 | PENDING |  |  |
| WP10 | PENDING |  |  |

## Rules
- The accepted HEAD of the previous WP is the execution base of the next WP.
- A chat response from Claude saying it is complete does not change this ledger.
- `FIX_REQUIRED`, `REJECTED` or `DECISION_REQUIRED` does not advance the base HEAD.
- If this ledger conflicts with an explicit newer owner instruction, stop and ask the owner to reconcile the record before implementation.
