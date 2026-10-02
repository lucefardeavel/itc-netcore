# Owner Acceptance Ledger

This ledger is **owner-controlled**. Claude/Coding Agent must never mark a WP `ACCEPTED` or `ACCEPTED_WITH_NOTES` unless the owner explicitly states that decision and authorizes recording it.

Upstream baseline: `d3e0596287322944ee21fcc14dc1798158463140`

| WP | Owner status | Accepted HEAD | Notes |
|---|---|---|---|
| WP0 | ACCEPTED_WITH_NOTES | `c2582368d0111046e39c353ca70b782d8fbb78c4` | Owner decision 2026-10-01. No rework required. Non-blocking notes (must stay visible in later relevant WPs): PDF/gd runtime path not exercised; authenticated UI screens reviewed statically, not end-to-end; external payment-gateway/plugin repositories outside WP0 scope. Accepted implementation HEAD remains `c2582368` although an owner-metadata commit follows it. Decisions: `owner-review/WP0/OWNER_DECISIONS.md`. |
| WP1 | ACCEPTED_WITH_NOTES | `1f36bfa17383efa903011a8316b5b4bf14e6bdc8` | Owner decision 2026-10-01. No rework required. Accepted implementation HEAD remains `1f36bfa` although an owner-metadata commit follows it. Non-blocking note: the migration verifier compares CHECK constraints by name, not normalized expression text; this must be closed by WP9 (Red Team / Release Candidate) before WP9 may be owner-accepted and must not be silently risk-accepted for release. Tested database policy: MariaDB 10.11.x, MySQL 8.0.x, PHP 8.2; other engine generations remain subject to later staging/release verification. Decisions and binding WP2/WP3A/WP3B/WP5A/WP8/WP9/WP10 inputs: `owner-review/WP1/OWNER_DECISIONS.md`. |
| WP2 | ACCEPTED_WITH_NOTES | `1c66ec5b8d0b107d32cfce0ec8185c241a8bc27e` | Owner decision 2026-10-01. No rework required. Accepted implementation HEAD remains `1c66ec5b` although an owner-metadata commit follows it. Non-blocking notes: the temporary ServiceGate intentionally blocks legacy service granting for reseller-owned customers until WP5B (WP5B must also prove no plugin/payment path bypasses activation and settlement, R1/R9); WP9 must close or explicitly resolve CHECK constraint verification by name, NEW-7 (`db_pass` as HMAC key material incl. staff session fingerprint and remember-cookie MAC), NEW-8 (Smarty pages without a sandbox) and plugin/direct-device bypass red-team tests before WP9 acceptance; WP10 owns the reseller-aware backup/restore runbook (legacy JSON restore stays disabled on reseller installations), key separation, `api_secret` deployment and migration ordering. Accepted residuals are not release acceptance. Tested database policy: MariaDB 10.11.x, MySQL 8.0.x, PHP 8.2 (207 tests / 9315 assertions per engine). Decisions and binding WP3A/WP3B/WP5B/WP9/WP10 inputs: `owner-review/WP2/OWNER_DECISIONS.md`. |
| WP3A | ACCEPTED_WITH_NOTES | `c329145ac3bce900648b6a6159a50097a7fc9b29` | Owner decision 2026-10-01. No rework required. Accepted implementation HEAD remains `c329145a` although an owner-metadata commit follows it. No R0007; R0001–R0006 byte-identical (R0006 `f1a76119…7abd`); ledger immutability is application-enforced, no triggers. SchemaReadiness requires the WP3A finance runtime tables (EXPECTED stays R0001–R0006). Non-blocking notes: application-level ledger immutability (WP9 tamper re-test, WP10 least-privilege runtime DB account); SEC-01 remains until WP4; hierarchy-dependent finance mutations use global tree serialization; reservation primitives are internal and await WP5A caller authorization and stale-reservation reconciliation; SuperAdmin code surfaces remain for WP9; no finance UI and no finance mutation API yet. Accepted residuals are not release acceptance. Tested database policy: MariaDB 10.11.x, MySQL 8.0.x, PHP 8.2 (371 tests / 24904 assertions per engine; route matrix 1442/1164/278). Decisions and binding WP3B/WP4/WP5A/WP5B/WP9/WP10 inputs: `owner-review/WP3A/OWNER_DECISIONS.md`. |
| WP3B | ACCEPTED | `460714def5c4d9814fc80b18acf400a3b79a9029` | Owner decision 2026-10-02 ("Accept WP3B, plan WP4"). Last implementation change `9978311a` (plan-delete TOCTOU fix); later commits are evidence only. No R0007; R0001–R0006 byte-identical. 470 tests / 43219 assertions per engine (MariaDB 10.11.x, MySQL 8.0.x, PHP 8.2); route matrix 1442/1164/278. Decisions, carried-forward WP4/WP5A inputs and residuals: `owner-review/WP3B/OWNER_DECISIONS.md`. |
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
