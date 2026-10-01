# WP1 Owner Review Decision and Owner Decisions

Recorded: 2026-10-01, from the owner's explicit review decision. Decision text below is recorded as given by the owner.

## WP1 acceptance
WP1 Database Foundation is accepted with notes.

- Owner status: **ACCEPTED_WITH_NOTES**
- Accepted implementation HEAD: `1f36bfa17383efa903011a8316b5b4bf14e6bdc8`
- No WP1 rework is required.
- The accepted implementation HEAD remains `1f36bfa` even though an owner-metadata commit follows it.

### Accepted WP1 implementation
The following WP1 implementation is accepted:
- strict project-owned migration runner
- V1/V2/V3 core-schema recognition
- MariaDB 10.11 / MySQL 8.0 tested migration matrix
- durable migration registry and step journal
- partial-DDL recovery states
- fail-closed unknown-schema behavior
- rollback before new-domain data exists
- manager closure-table foundation
- explicit provider wholesale base-cost storage
- manager wholesale/retail/availability override schema
- activation and activation-event foundation
- wallet, ledger, transfer and reservation foundation
- nullable customer manager_id and assigned_sales_id
- no customer ownership backfill
- no tbl_users.root import
- no legacy price conversion
- customer actor identity on activations/events
- customer actor identity on ledger rows
- current-override pricing provenance semantics
- DECIMAL(18,2) for new-domain money
- UTC DATETIME(6) semantics
- RESTRICT foreign keys
- CLI-only migration tooling
- dev-only PHP 8.2 test infrastructure

## Owner note — CHECK constraint drift
The current migration verifier compares CHECK constraints by constraint name rather than normalized expression text.

This is accepted as a non-blocking WP1 limitation.

Reason:
- shipped migration definitions are checksum-protected
- constraint behavior is exercised on MariaDB 10.11 and MySQL 8.0
- the residual gap concerns a later manual database alteration that changes a CHECK expression while retaining the same name

This limitation must be closed by WP9 Red Team / Release Candidate work before WP9 may be owner-accepted.

WP9 must determine and implement a safe cross-engine verification method for CHECK expressions, or an equivalent integrity mechanism that detects same-name semantic CHECK drift without producing unreliable MariaDB/MySQL false positives.

Do not silently risk-accept this for release.

## WP2 ownership items
The following already-identified items remain binding input to WP2:
- C24 legacy database restore protection
- centralized authorization and DataScope
- Agent / Sales / Report permission correction
- Sales sees only explicitly assigned customers
- unowned legacy customers remain Admin/SuperAdmin-only
- API authorization/scoping
- privilege-escalation paths
- Agent/Sales voucher generation closure
- legacy user-delete behavior with new RESTRICT references
- customer ownership / Sales assignment services
- audited hierarchy import/rebuild tooling
- no automatic tbl_users.root import without explicit owner authorization

## WP3A input
The following remain binding input to WP3A:
- wallet and ledger services
- transaction and row-locking rules
- strict SQL mode for financial writers
- ledger immutability enforcement
- reservation concurrency
- debt-limit / available-credit enforcement
- funding / transfer atomicity
- customer-ledger actor semantics at service level

## WP3B input
The following remain binding input to WP3B:
- pricing inheritance
- parent-controlled child wholesale pricing
- retail >= effective wholesale
- ancestor/global availability restrictions
- rows with all manager-plan overrides NULL must behave exactly like no override row
- legacy VARCHAR price parsing/validation
- safe plan-delete behavior with new RESTRICT references

## WP5A input
The activation schema is only the foundation.

WP5A still owns:
- ActivationService
- valid state-transition enforcement
- event immutability
- idempotent activation orchestration
- strict SQL mode for activation writers
- failure/reconciliation semantics

## WP8 / staging input
Real installations outside registered V1/V2/V3 schema variants remain NOT_READY until explicitly analyzed and added through reviewed fixtures/tests.

Do not weaken this fail-closed rule at runtime.

## WP10 input
The following remain deployment/runbook requirements:
- production backup before migration
- maintenance-window requirements for tbl_customers ALTER
- restore-from-backup path after new-domain data exists
- exclusion of tests/ and dev/ from production deployments

## Tested database policy
WP1 test evidence is accepted for:
- MariaDB 10.11.x
- MySQL 8.0.x
- PHP 8.2 target runtime

Support for other engine generations remains subject to later staging/release verification.

## Recording scope
- This metadata step does not change the WP1 runner, any migration or any application code.
- `docs/claude-development-pack/MANIFEST.sha256` is not modified; the Acceptance Ledger is intentionally excluded from the immutable specification manifest.
- WP2 has not started.
