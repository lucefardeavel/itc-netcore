# WP2 Owner Review Decision and Owner Decisions

Recorded: 2026-10-01, from the owner's explicit review decision. Decision text below is recorded as given by the owner.

## WP2 acceptance
WP2 Hierarchy / Permissions / DataScope / Security Boundary is accepted with notes.

- Owner status: **ACCEPTED_WITH_NOTES**
- Accepted implementation HEAD: `1c66ec5b8d0b107d32cfce0ec8185c241a8bc27e`
- Accepted branch: `wp2/hierarchy-permissions`
- No WP2 rework is required.
- The accepted implementation HEAD remains `1c66ec5b` even though an owner-metadata commit follows it.

### Accepted WP2 scope
The following WP2 implementation is accepted:
- ActorContext
- PermissionService
- code-defined capability matrix
- DataScope
- RouteMap / RouteGuard
- fail-closed unmapped-route behavior
- reseller hierarchy services
- tbl_users.root compatibility synchronization
- customer manager ownership
- Sales assignment
- explicit legacy-customer owner assignment
- explicit hierarchy placement
- hierarchy dry-run/validation CLI
- no automatic root import
- no automatic customer assignment
- R0006 reseller audit trail
- staff lifecycle hardening
- Agent permanent-delete prohibition
- Agent role permanence in V1
- disable/reactivate reseller lifecycle
- central CSRF policy
- API actor-bound authentication
- API password-change token revocation
- no api_secret fallback to db_pass
- API DataScope enforcement
- Report deny-by-default reseller posture
- updater disablement
- installer re-entry guard
- legacy restore fail-closed guard
- plugin dispatch authorization
- voucher generation restriction
- error/trace sanitization
- NEW-1 language path closure
- NEW-2 reflected XSS closure
- NEW-3 error disclosure closure
- NEW-4 Pages/template traversal closure
- NEW-5 public invoice empty-id/signature closure
- Pages read = system.admin
- Pages write/reset = system.code
- temporary legacy service-granting gate for reseller-owned customers

## Database acceptance
- `R0006_reseller_audit` is accepted.
- R0001–R0005 remain the accepted WP1 definitions.
- R0006 checksum: `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`
- No R0007 exists.
- No hierarchy import, customer ownership backfill or production data migration was executed.

## Binding input — WP3A
WP3A owns the authoritative financial writer layer.

It must consume the WP2 ActorContext / PermissionService / DataScope boundaries and must not create parallel authorization logic.

WP3A remains responsible for:
- WalletService
- LedgerService
- immutable financial ledger behavior
- reservations
- available-credit calculations
- debt-limit enforcement
- funding
- parent/child transfers
- row locking
- strict SQL mode
- transaction atomicity
- financial concurrency
- reversal/adjustment semantics
- ledger customer actor semantics at service level

WP3A must not weaken WP2 scope enforcement.

## Binding input — WP3B
WP3B remains responsible for:
- pricing inheritance
- provider base cost
- child wholesale pricing
- retail override
- availability override semantics
- minimum-margin validation
- legacy VARCHAR price parsing
- safe pricing resolution

WP2's authorization boundary must be reused.

## Binding input — WP5B
The temporary WP2 ServiceGate is **accepted**.

Legacy service granting for reseller-owned customers remains intentionally blocked until WP5B.

WP5B must replace the temporary refusal with authoritative activation + financial settlement.

WP5B must also revisit R1 / R9: plugins or payment integrations that can directly write `tbl_user_recharges` or call device code outside normal core flows.

Hooks/plugins are not a security boundary.

WP5B must prove that no plugin/payment path can grant reseller-owned service without authoritative activation and financial settlement.

## Binding input — WP9
WP9 Red Team must close or explicitly resolve before WP9 owner acceptance:

1. CHECK constraints currently verified by name, not normalized semantic expression.
2. NEW-7: `db_pass` is still HMAC key material for:
   - staff session fingerprint
   - staff remember-cookie MAC
   - customer cookies
   - payment-link signatures
   - invoice signatures

   WP9/WP10 must establish proper key separation.
3. NEW-8: Smarty page templates execute without a security sandbox. Only SuperAdmin can currently write/reset them, which is accepted for WP2, but WP9 must evaluate sandboxing or equivalent release hardening.
4. Plugin/top-level execution and direct-device/write bypasses must be red-team tested.
5. Re-test all WP2 permission boundaries:
   - direct URL
   - forged POST
   - API
   - CSRF
   - DataScope
   - Pages system.code
   - Agent lifecycle
   - restore guard
   - service gate

## Binding input — WP10
WP10 owns:
- real reseller-aware database backup/restore runbook
- production restore procedure
- key separation operational configuration where still required
- `api_secret` deployment configuration
- production migration ordering
- R0001–R0006 backup/rollback procedure
- deployment exclusion of tests/dev artifacts

The legacy JSON restore remains disabled on reseller installations until WP10.

## Known accepted residuals
These do not block WP2 acceptance:
- customer/service operations for reseller-owned customers remain intentionally blocked pending WP5B
- no per-token API revocation except password-change or api_secret rotation
- stable per-session CSRF token rotated on login/logout
- mutation links require JavaScript
- pure legacy Admin/SuperAdmin compatibility remains where explicitly allowed
- Report remains intentionally restricted
- Sales deletion rules remain as accepted; Sales is not a reseller identity
- opening a missing allowlisted Page may materialize the shipped template
- legacy `Lang::T()` may write/translate static missing strings on non-English installations

These notes must not be reinterpreted as release acceptance.

## Test evidence accepted
Final WP2 matrix (PHP 8.2):

| Engine | Tests | Assertions | Result |
|---|---|---|---|
| MariaDB 10.11.19 | 207 | 9315 | PASS |
| MySQL 8.0.46 | 207 | 9315 | PASS |

Plain and `--testdox` runs both PASS.

Route matrix: 1442 requests, 1164 denials, 278 allowed reads.

WP2 accepted branch: `wp2/hierarchy-permissions`
Accepted HEAD: `1c66ec5b8d0b107d32cfce0ec8185c241a8bc27e`

## Recording scope
- `feat/reseller-system-v1` was fast-forwarded (`--ff-only`) from `0b16f60` to the accepted WP2 HEAD `1c66ec5b`; this owner-metadata commit follows it.
- This record changes only `docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md` (WP2 row) and this file. No application code, migration, test, WP2 report/evidence or `MANIFEST.sha256` was changed; the ledger is excluded from the manifest by design.
- `main` is not updated, no PR is created, the WP2 branch is kept, nothing is deployed.
- WP3A has not been started.
