# WP3A Owner Review Decision and Owner Decisions

Recorded 2026-10-01 from the owner's explicit review decision. The decision text below is recorded as the owner gave it.

## WP3A acceptance
The WP3A Wallet / Ledger / Funding / Transfer foundation is accepted with notes.

- Owner status: **ACCEPTED_WITH_NOTES**
- Accepted implementation HEAD: `c329145ac3bce900648b6a6159a50097a7fc9b29`
- Accepted branch: `wp3a/wallet-ledger`
- No WP3A rework is required.
- The accepted implementation HEAD remains `c329145a` even though an owner-metadata commit follows it.
- WP3B is not started in the recording session.

### Accepted WP3A scope
The following implementation is accepted:
- exact integer-minor-unit Money model
- separate storage and calculation ranges
- no float money arithmetic
- WalletService
- LedgerService
- TransferService
- ReservationService
- FinanceActor
- FinanceTx
- FinanceInput
- FinanceFaults test instrumentation
- provider funding
- provider withdrawal
- explicit credit/debit adjustments
- debt-limit administration
- direct parent → direct-child reseller transfer
- compensating reversals
- reservation reserve/commit/release primitives
- deterministic ledger keys
- financial idempotency
- strict transaction envelope
- deterministic wallet lock ordering
- concurrency protection
- failure atomicity
- source guards
- customer-balance separation
- R0006 operator audit usage
- finance capability matrix
- finance schema-readiness fail-closed behavior

## Database decision
- No R0007.
- R0001–R0006 remain byte-identical.
- R0006 checksum remains `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`.
- Ledger immutability in WP3A is application-enforced.
- No triggers were added.

## Money model
- Authoritative money uses integer minor units.
- Persisted DECIMAL(18,2) values must fit one storage column.
- Intermediate calculations may exceed one DECIMAL(18,2) field range but must stay inside checked signed-int64 arithmetic.
- No float, BCMath or GMP dependency is introduced.

The following must remain true:
- funding overflow fails before SQL
- transfer destination overflow is atomic
- reversal overflow is atomic
- SUM(active reservations) may exceed one column's storage range
- available-credit calculations must remain exact

## Wallet creation policy
- No wallet is created by migration, upgrade or backfill.
- A wallet may first appear only through:
  - provider funding
  - establishment of a positive debt limit
  - receipt of a valid parent-child transfer
- `setDebtLimit(0)` on an absent wallet is a no-op.
- Adjustments do not create wallets.
- Reversals do not create wallets.
- Only valid enrolled Agent identities may own wallets.
- Sales and Report never own wallets.

## Financial floor
Available credit is authoritative:

```
available = balance + debt_limit - ACTIVE reservations
```

- Provider withdrawal, debit adjustment, transfer debit and reservation creation must not make available credit negative.
- The debt line is real operational credit.
- A negative wallet balance is therefore permitted within `debt_limit`.

## Capability model
Accepted finance capabilities:
- `wallet.read`
- `ledger.read`
- `wallet.fund_withdraw`
- `wallet.transfer`
- `wallet.debt_limit`
- `wallet.adjust`
- `wallet.reverse`

Scopes:

**SuperAdmin / Admin**
- `wallet.read` = G
- `ledger.read` = G
- fund/withdraw = G
- debt limit = G
- adjustment = G
- reversal = G
- transfer = G, but still only for a structurally valid parent → direct child

**Agent**
- `wallet.read` = T
- `ledger.read` = T
- `wallet.transfer` = C
- the source must be self
- the target must be a direct child Agent

**Sales:** no reseller finance capability.

**Report:** no reseller finance capability.

WP3A adds no finance web mutation route and no finance API mutation route. Any later finance API mutation requires explicit owner approval.

## Reseller state policy
- Normal business finance mutation remains blocked when the reseller or a required ancestor is invalid, inactive or frozen.
- Administrative historical reversal is the approved exception.

`reverse()`:
- is Admin/SuperAdmin only
- may operate on inactive/frozen historical counterparties
- uses the original recorded counterparties
- does not create wallets
- must still satisfy the current available-credit floor for every debit leg
- remains audited
- does not become a general business-mutation exception

## Reservation policy
WP3A owns financial primitives only.

| Primitive | Effect |
|---|---|
| reserve | creates financial exposure |
| commit | converts ACTIVE exposure into exactly one ACTIVATION_DEBIT |
| release | removes confirmed-unused exposure without moving the wallet balance |

- No automatic expiry.
- No blind timeout release.
- No re-reservation after RELEASED/EXPIRED in WP3A.
- WP5A owns retry/reconciliation semantics.
- Commit and release remain possible when the reseller later becomes frozen, because existing financial exposure still has to reach a coherent terminal state.

## Finance actor policy
- Finance actor identity is explicit.
- CLI does not imply CRON.
- API does not imply `actor_type=API`.
- An authenticated API staff principal remains STAFF.
- An authenticated API customer remains CUSTOMER.
- SYSTEM and CRON are explicit internal identities.

## Binding input — WP3B
WP3B must reuse the accepted WP3A financial foundation.

WP3B owns:
- PricingService
- provider base cost
- manager wholesale inheritance
- child wholesale overrides
- retail overrides
- availability inheritance/restrictions
- minimum-margin validation
- legacy VARCHAR retail-price parsing/validation
- immutable activation pricing snapshots
- provider/system settlement math
- intermediary margin/commission math

WP3B must NOT:
- directly mutate wallet balance
- directly INSERT ledger rows
- bypass WalletService / LedgerService / TransferService / ReservationService
- reuse `tbl_customers.balance` for reseller finance
- use browser-supplied money as authoritative
- repurpose `tbl_plans.price` as reseller wholesale cost

Further binding rules:
- The accepted WP3A source guards and financial invariants remain binding.
- Pricing and settlement must fit the existing DECIMAL(18,2) / Money model.
- If WP3B needs any schema change beyond the accepted R0001–R0006: STOP and request owner review before creating it.

## Binding input — WP4
- SEC-01 in `radius.php` remains binding.
- WP4 must close the SQL injection/write vector before release or production acceptance.
- WP4 must not introduce direct reseller-finance writes.

## Binding input — WP5A
WP5A must authorize callers of the internal reservation primitives.

WP5A owns:
- ActivationService
- activation state transitions
- reconciliation
- UNKNOWN handling
- reservation orchestration
- exactly-once financial commit orchestration

Further binding rules:
- WP5A must not blindly release stale reservations.
- WP5A must consume WP3A services rather than write finance tables directly.

## Binding input — WP5B
- The WP2 ServiceGate remains binding.
- Reseller-owned legacy service-granting routes remain blocked until WP5B connects them to the authoritative activation and financial flow.
- WP5B must not bypass WP3A finance services.

## Binding input — WP9
WP9 must red-team:
- ledger immutability/tamper surfaces
- global finance serialization throughput
- idempotency replay/conflict behavior
- finance capability boundaries
- direct SQL/write bypasses
- plugin/direct-device bypasses
- CHECK semantic drift
- WP2 residual security issues

## Binding input — WP10
WP10 must design the production runtime database account using least privilege.

Where operationally feasible:
- the runtime account must not have UPDATE/DELETE authority on immutable ledger history
- the runtime account must not have UPDATE/DELETE authority on immutable audit history
- migration privileges must be separated from ordinary runtime privileges

WP10 also owns the production backup/restore and migration runbooks.

## Schema readiness acceptance
SchemaReadiness now requires:
- `tbl_manager_tree`
- `tbl_reseller_audit_events`
- `tbl_customers`
- `tbl_reseller_activations`
- `tbl_manager_wallets`
- `tbl_manager_transfers`
- `tbl_manager_ledger`
- `tbl_manager_wallet_reservations`

Further rules:
- `EXPECTED` remains exactly R0001–R0006.
- Pricing tables are not yet runtime-readiness requirements.
- WP3B may extend readiness when pricing becomes authoritative.

## Known accepted residuals
These do not block WP3A acceptance:
- ledger immutability is application-level in WP3A
- SEC-01 remains until WP4
- hierarchy-dependent finance mutations use global tree serialization
- reservation primitives are internal and await WP5A caller authorization
- stale ACTIVE reservation reconciliation awaits WP5A
- SuperAdmin code surfaces remain for WP9
- no finance UI exists yet
- no finance mutation API exists yet

These notes are NOT release acceptance.

## Test evidence accepted
Final WP3A matrix (PHP 8.2):

| Engine | Tests | Assertions | Result |
|---|---|---|---|
| MariaDB 10.11.19 | 371 | 24904 | PASS |
| MySQL 8.0.46 | 371 | 24904 | PASS |

- Plain and `--testdox` both pass. Testdox: 742 passed, 0 failed.
- Route/security matrix: 1442 requests, 1164 denials, 278 allowed.
- No production deployment occurred.

WP3A accepted branch: `wp3a/wallet-ledger`. Accepted HEAD: `c329145ac3bce900648b6a6159a50097a7fc9b29`.

## Recording scope
- `feat/reseller-system-v1` was fast-forwarded (`--ff-only`) from `3e634550` to the accepted WP3A HEAD `c329145a`, and this owner-metadata commit follows it.
- This record changes only `docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md` (WP3A row) and this file.
- No application code, finance code, migration, test, WP3A report/evidence or `MANIFEST.sha256` was changed. The ledger is excluded from the manifest by design.
- `main` is not updated, no PR is created, the `wp3a/wallet-ledger` branch is kept, and nothing is deployed.
- WP3B has not been started.
