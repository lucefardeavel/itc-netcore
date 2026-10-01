# WP3A — Reseller wallet / ledger / funding / transfer / reservations

Status: **IMPLEMENTED_NOT_ACCEPTED**. The owner accepted the WP3A plan with amendments. WP3A builds on the accepted R0004/R0006 schema, with no schema change and no R0007.

WP3A is the authoritative reseller financial writer layer. It has:
- no pricing (WP3B);
- no ActivationService (WP5A);
- no entrypoint adaptation (WP5B);
- no finance UI, web route or API (owner decision P7).

## 1. Components (`system/autoload/Reseller/Finance/`, every file NUXR_RUNTIME-guarded)

| Class | Role |
|---|---|
| `Money` | Exact money in integer minor units. Strings only at the boundaries; no float, BCMath or GMP |
| `FinanceActor` | Explicit ledger actor: `staff()`, `customer()`, `system()`, `cron()`. Never inferred from SAPI or channel; never `API` |
| `FinanceInput` | Strict reason, reference, external reference and id validation |
| `WalletOwner` | Wallet-owner rules (valid owner for business mutations; identifiable counterparty for reversals) |
| `LockedWallet` | A wallet row read `FOR UPDATE` in the current transaction. The only wallet creator |
| `WalletService` | Scoped reads, available credit, debt limit (audited configuration change, no ledger entry) |
| `LedgerService` | The only writer of `tbl_manager_ledger` and of `tbl_manager_wallets.balance`. Append-only |
| `TransferService` | Provider funding/withdrawal, adjustments, parent→direct-child transfer, reversal. The only writer of `tbl_manager_transfers` |
| `ReservationService` | Internal reserve / commit / release primitives for WP5A. The only writer of `tbl_manager_wallet_reservations` |
| `FinanceTx` | Envelope over the accepted WP2 `Tx::run` / `Tx::withTreeLock`; bounded deadlock retry |
| `FinanceFaults` | Test-only failure-injection hook (never assigned at runtime) |
| `WalletView`, `TransferResult`, `ReservationResult`, `PostedEntry` | Result value objects |

Accepted code changed (minimal):
- `Capability` and `RoleCapabilityMap`: the finance matrix (§3);
- `DomainError`: finance reason codes;
- `SchemaReadiness`: intentionally changed in fix round 1 to require the finance runtime tables (§1a);
- the generated finance rows of `docs/WP2_PERMISSION_MODEL.md`.

Byte-identical (pinned by `SourceGuardTest`):
- R0001–R0006;
- the accepted WP1 migration runner (`system/reseller/migrate.php`, `system/reseller/Migration/*`);
- `ServiceGate`, `RouteMap`, `Tx`;
- `Balance.php`, `Package.php`.

`SchemaReadiness` is deliberately not pinned.

## 1a. Schema readiness (fix round 1)

A registry row saying R0003/R0004 is APPLIED is not proof that the finance runtime tables still exist. `SchemaReadiness` therefore checks every table a runtime authority depends on:

| Table | Why it is required |
|---|---|
| `tbl_manager_tree` | hierarchy (WP2) |
| `tbl_reseller_audit_events` | audit (WP2) |
| `tbl_customers` | customer ownership (WP2) |
| `tbl_reseller_activations` | activation rows used by `ReservationService` |
| `tbl_manager_wallets` | wallet authority |
| `tbl_manager_transfers` | transfer / idempotency authority |
| `tbl_manager_ledger` | financial source of truth |
| `tbl_manager_wallet_reservations` | exposure authority |

- `EXPECTED` stays R0001–R0006.
- Pricing tables are not required (WP3B extends the list when pricing becomes runtime-authoritative).

If any of these tables is missing, the reseller schema is **not ready**:
- PermissionService refuses all seven finance capabilities for every role, SuperAdmin and Admin included.
- Every finance read and mutation is refused with `SCHEMA_NOT_READY` at `ResellerTreeService::authorize` / `requireReady`, before any finance SQL runs. It never fails later with a "table not found" error.
- Under the accepted WP2 readiness rule, the other schema-dependent reseller operations also fail closed.
- Restoring the table restores readiness.

## 2. Money model (owner correction)

Money is an immutable count of integer minor units (1 = 0.01). There are two separate ranges:

- **Storage range:** one `DECIMAL(18,2)` column, |value| ≤ 9,999,999,999,999,999.99.
  - `Money::toStorage()` enforces it for every persisted value: wallet balance, debt limit, transfer/ledger/reservation amount, `balance_before`, `balance_after`.
  - An out-of-range result is refused with `STORAGE_RANGE_EXCEEDED` before any SQL. A refused funding, credit or transfer is fully atomic.
- **Calculation range:** checked signed int64 (symmetric ±PHP_INT_MAX).
  - Intermediates are valid even when they exceed one column, e.g. `balance + debt_limit` (up to 2 × MAX), `SUM(ACTIVE reservations)` and available credit.
  - Every `plus`/`minus` is overflow-checked (`MONEY_OVERFLOW`); PHP integers never overflow.

Parsers (all anchored with `/D`, strings only):

| Parser | Accepts |
|---|---|
| `parsePositive` / `parseNonNegative` | `^(0\|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$` |
| `fromDb` | exactly what a `DECIMAL(18,2)` column returns |
| `fromDbAggregate` | a `SUM()` result of any length; checked into int64 |

Rejected inputs:
- NaN and Infinity;
- exponent forms;
- signs and spaces;
- leading zeros;
- separators;
- more than 2 decimals;
- more than 16 integer digits;
- non-ASCII digits;
- floats, ints, null and arrays.

Balances are written as absolute values computed from locked rows. The write is a compare-and-set: `SET balance = ? WHERE balance = CAST(? AS DECIMAL(18,2))`.

## 3. Capability / scope matrix (owner decisions P2, P3)

| Capability | SuperAdmin | Admin | Agent | Sales | Report | Mutating |
|---|---|---|---|---|---|---|
| `wallet.read` | G | G | T (own + subtree) | — | — | |
| `ledger.read` | G | G | T (own + subtree) | — | — | |
| `wallet.fund_withdraw` | G | G | — | — | — | yes |
| `wallet.debt_limit` | G | G | — | — | — | yes |
| `wallet.adjust` | G | G | — | — | — | yes |
| `wallet.reverse` | G | G | — | — | — | yes |
| `wallet.transfer` | G | G | C (own wallet → depth-1 child) | — | — | yes |

All seven are schema-dependent. Enforcement is the accepted WP2 machinery:
- `PermissionService` (frozen, inactive, inconsistent or non-enrolled principals lose mutating and tree scopes);
- `DataScope::staffPredicate` (scope);
- `ResellerTreeService::revalidateActor` (in-transaction).

Pricing and activation capabilities stay reserved. Reservation primitives have no capability: they are internal APIs for WP5A.

## 4. Wallet lifecycle (owner amendment)

- **Who may own a wallet:** only an enrolled, consistent (closure parent = `root`, parent is an Agent) Agent. SuperAdmin, Admin, Sales and Report never own one.
- **No backfill.** No wallet is created by upgrade or backfill. A wallet appears only on the first approved wallet-creating write:
  - provider funding;
  - positive debt-limit establishment;
  - receipt of a valid parent-child transfer.
- `setDebtLimit(0)` on an absent wallet is a no-op: no wallet, no audit.
- `adjustCredit` / `adjustDebit` never create a wallet (`WALLET_NOT_FOUND`).
- Withdrawal, transfer source and reservation on an absent wallet fail with `INSUFFICIENT_AVAILABLE_CREDIT`.
- Reversal never creates a wallet.
- Reads of an absent wallet return a synthesized zero view (`exists = false`) and write nothing.

## 5. Operations

| Operation | Ledger | Rule |
|---|---|---|
| `fund` | `FUNDING` credit `trf:{id}:credit` | valid owner; transfer row `PROVIDER_FUNDING` NULL→M (no fake root wallet) |
| `withdraw` | `WITHDRAWAL` debit `trf:{id}:debit` | valid owner; existing wallet; AVAILABLE after ≥ 0 (P5: the debt line is real credit) |
| `adjustCredit` / `adjustDebit` | `ADJUSTMENT` credit/debit | valid owner; existing wallet; debit obeys the floor |
| `transferToChild` | `TRANSFER_OUT` `trf:{id}:out` + `TRANSFER_IN` `trf:{id}:in` | closure depth-1 child; both owners valid; source floor; destination storage range |
| `setDebtLimit` | none (audited config change) | valid owner; decrease refused if `balance + new − ACTIVE < 0` |
| `reverse` | `REVERSAL` `rev:{id}:debit/credit` with `reverses_entry_id` | Admin/SuperAdmin; at most once; original counterparties; current floor on debit legs |
| `reserve` | none | activation row; `wholesale_cost` (if set) = amount; valid owner; AVAILABLE − amount ≥ 0 |
| `commit` | `ACTIVATION_DEBIT` `res:{id}:commit` | exactly once; amount = reserved amount |
| `release` | none | ACTIVE → RELEASED; no value moves |

**Shared rules:**
- `available = balance + debt_limit − SUM(ACTIVE reservations)`, always from locked state.
- Reason is mandatory (3–255 characters). Reference is `^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$`.
- The operation row is written as `COMPLETED` together with its ledger entries and audit row.
- A refusal persists nothing, and the refused reference stays usable.

**Ledger keys** are derived inside `LedgerService` from authoritative operation ids. No caller supplies a key, amount, balance or actor id.

**Reservation history for the same activation:**

| Existing | Same manager + amount | Different manager or amount |
|---|---|---|
| ACTIVE | returned (replay) | `RESERVATION_CONFLICT` |
| COMMITTED | returned as COMMITTED (no new exposure) | `RESERVATION_CONFLICT` |
| RELEASED / EXPIRED | `RESERVATION_RESOLVED` (no re-reservation, P6) | `RESERVATION_CONFLICT` |

**Commit:**
- Repeating a commit returns the same ledger entry and never rewrites the recorded actor.
- RELEASED or EXPIRED → `RESERVATION_NOT_ACTIVE`.

**Release:**
- A replay with the same resolver and note is idempotent.
- A different resolver or note → `RESERVATION_CONFLICT`.
- COMMITTED → `RESERVATION_ALREADY_COMMITTED`. EXPIRED → `RESERVATION_NOT_ACTIVE`.
- Customers never release.

**Expiry:** there is no expiry primitive, timer or cron, and `expires_at` stays NULL. UNKNOWN outcomes belong to WP5A reconciliation.

**Customer actor:** `actor_customer_id` may differ from the activation's target customer, and both are preserved. A CUSTOMER ledger entry always references an activation.

## 6. Inactive / frozen resellers (P4, modified)

"Invalid" means: not an Agent, not enrolled, inconsistent, not Active, or with a non-Active ancestor.

| Operation | Invalid owner |
|---|---|
| funding, withdrawal, adjustments, debt limit (any direction), new transfer (source or destination), reservation | refused (`WALLET_OWNER_INVALID` / `WALLET_OWNER_FROZEN`), Admin included |
| commit / release | allowed: settlement of exposure that already exists |
| `reverse` (Admin/SuperAdmin) | allowed. The counterparty only has to be identifiable as an Agent with an existing wallet; frozen/inactive does not block the correction; debit legs obey the current floor; audit is mandatory. Not generalized to Agent operations |
| reads | allowed within scope (a frozen Agent keeps `wallet.read` / `ledger.read`) |

Implementation found no case where withdrawal, adjustment or debt-limit changes on a frozen wallet are an unavoidable accounting correction. Corrections of historical operations are covered by `reverse()`.

## 7. Idempotency and reversal ordering

**Canonical payload** compared on a replay of the same reference:
- `operation_type`, `from_manager_id`, `to_manager_id`, `amount`;
- `reason` (trimmed), `external_reference`, `operator_id`, `reverses_transfer_id`;
- the exact reference spelling.

Same payload → the existing operation is returned with `replayed = true`, with no effect and no audit. Any difference → `IDEMPOTENCY_CONFLICT`.

**`reverse(original, newReference)`:**
1. The new reference is checked first. If it exists with the same reversal payload, the existing reversal is returned (a lost-response replay). Otherwise → `IDEMPOTENCY_CONFLICT`.
2. Only a new reference inspects the original:
   - missing → `NOT_FOUND`;
   - is itself a reversal → `NOT_REVERSIBLE`;
   - already reversed → `ALREADY_REVERSED`.
3. The original's ledger rows never change. The original operation row moves COMPLETED → REVERSED.

## 8. Locking, transactions, concurrency

Global lock order:
1. The accepted WP2 tree lock (`GET_LOCK` before BEGIN), for hierarchy-dependent operations: fund, withdraw, adjust, debt limit, transfer, reverse, reserve.
2. BEGIN, with strict `sql_mode` restored afterwards.
3. The actor row (`revalidateActor`).
4. The idempotency reference probe `FOR UPDATE`.
5. The activation row.
6. Wallet rows `FOR UPDATE` in ascending `manager_id`.
7. The locking `SUM` of ACTIVE reservations.
8. The reservation row or the original transfer row.
9. Writes.
10. COMMIT, then release the lock.

Commit and release do not take the tree lock, so settlement stays possible for a frozen reseller.

Every change to balance, debt limit or ACTIVE exposure first holds the wallet row lock, and every exposure read is a locking read. Two operations can therefore never pass the availability check on the same state.

Deadlocks:
- Deadlock 1213 is retried at most 2 times, around the whole DB-only unit. Between attempts the transaction is rolled back, the sql_mode restored and the tree lock released, then re-acquired. After the last attempt → `CONCURRENCY_RETRY`.
- Lock-wait timeout 1205 → `LOCK_TIMEOUT`, not retried.
- WP3A has no network call and holds no transaction across one.

## 9. Audit (R0006)

| Event | When |
|---|---|
| `finance.fund`, `finance.withdraw`, `finance.adjust_credit`, `finance.adjust_debit`, `finance.transfer`, `finance.reverse` | every successful command |
| `finance.debt_limit` | an actual change |
| `finance.reservation_release` | a staff-resolved release |

- **Content:** actor and target snapshots, reference, operation id, amount, ledger entry ids, balance and available before/after, and the reason.
- **Never included:** secrets, credentials or ledger copies.
- **Not audited:** replays, refusals, reserve and commit. The reservation and ledger rows are the record; WP5A activation events cover orchestration.

The ledger is the financial truth; the audit trail records who did what and why.

## 10. Ledger immutability (P1) and follow-ups

Immutability is enforced in the application:
- `LedgerService` is the only writer and is insert-only;
- there is no update, delete or edit method;
- corrections are compensating reversals;
- R0004 `ck_ledger_math`, unique entry and reversal keys, and RESTRICT foreign keys;
- static source guards (`tests/Unit/Wp3a/SourceGuardTest.php`).

There are no triggers and no R0007.

Binding follow-ups:
- **WP4:** close SEC-01 (`radius.php` SQL injection) before any release or production acceptance.
- **WP9:** re-test ledger tamper surfaces.
- **WP10:** a least-privilege production runtime DB account, with no UPDATE/DELETE on immutable ledger/audit history where operationally feasible.
