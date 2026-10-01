# WP3A — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED.** WP3A is implemented on `wp3a/wallet-ledger` from `3e6345500724394132654a34ea6323051ceb1c02`, following the owner's decisions P1–P7 and the plan amendments. It is waiting for owner review.

| Item | Value |
|---|---|
| Branch | `wp3a/wallet-ledger` (created from `3e634550`; not reset to an earlier HEAD) |
| Starting HEAD | `3e6345500724394132654a34ea6323051ceb1c02` |
| Code under test | `32690c37` (all code and tests; the following commit adds only this report, `TESTDOX.txt` and `docs/WP3A_FINANCE_MODEL.md`) |
| Ending HEAD | the report commit on top of `32690c37` (given in the final response) |
| Ancestry | `3e634550` → `1c66ec5b` (WP2) → `1f36bfa` (WP1), all verified ancestors |
| Schema | **no change, no R0007, no triggers**; R0001–R0006 byte-identical |
| `feat/reseller-system-v1` / `main` | unchanged: `3e634550` / `500d3a2d15003efeed6e0aad639c13d08101c3b1` |
| Production | not touched; nothing deployed |
| WP3B | not started |

Detailed model: `docs/WP3A_FINANCE_MODEL.md`. Test evidence: `owner-review/WP3A/TESTDOX.txt`.

## A. Commits
| Commit | Content |
|---|---|
| `81e02e0f` feat(wp3a): activate the finance capability matrix and add the Money model | `Capability`, `RoleCapabilityMap`, `DomainError` codes, `Finance/Money.php`, generated finance rows of `docs/WP2_PERMISSION_MODEL.md` |
| `12824c02` feat(wp3a): wallet, ledger, transfer and reservation services | `system/autoload/Reseller/Finance/*` |
| `32690c37` test(wp3a): unit, integration, concurrency and failure-injection suites | `tests/Unit/Wp3a`, `tests/Integration/Wp3a`, `tests/Support/Finance*`, `finance_worker.php`, bootstrap, `phpunit.xml` |
| report commit — docs(wp3a): finance model and owner-review report | `docs/WP3A_FINANCE_MODEL.md`, `owner-review/WP3A/{REPORT.md,TESTDOX.txt}` |

All commits are authored by `ALI HENDI <ali.hahuba3@gmail.com>` and carry the agreed trailers.

## B. Exact changed files (`git diff --name-status 3e634550..HEAD`)
**Modified (accepted code / test harness / doc):**
- `system/autoload/Reseller/Security/Capability.php`: adds `wallet.debt_limit`, `wallet.adjust`, `wallet.reverse`. `RESERVED` is now pricing and activation only. Five finance capabilities are `MUTATING`; all seven are `SCHEMA_DEPENDENT`.
- `system/autoload/Reseller/Security/RoleCapabilityMap.php`: the finance matrix (section D).
- `system/autoload/Reseller/Domain/DomainError.php`: 24 finance reason codes.
- `docs/WP2_PERMISSION_MODEL.md`: regenerated finance rows.
- `tests/bootstrap.php`: `Finance` autoload and the support classes.
- `tests/phpunit.xml`: suites `wp3a-unit` and `wp3a-integration`.

**Added runtime (`system/autoload/Reseller/Finance/`, 15 files, all NUXR_RUNTIME-guarded):**
- `Money`, `FinanceActor`, `FinanceInput`, `FinanceTx`, `FinanceFaults`;
- `WalletOwner`, `LockedWallet`, `PostedEntry`;
- `WalletService`, `WalletView`;
- `LedgerService`;
- `TransferService`, `TransferResult`;
- `ReservationService`, `ReservationResult`.

**Added tests (21 files):**
- `tests/Unit/Wp3a/`: `MoneyTest`, `FinanceCapabilityTest`, `FinanceActorTest`, `SourceGuardTest`.
- `tests/Integration/Wp3a/`: `WalletTest`, `FundingTest`, `AdjustmentTest`, `TransferTest`, `ReservationTest`, `ReversalTest`, `LedgerTest`, `MoneyBoundaryTest`, `FailureInjectionTest`, `FinanceTxTest`, `ConcurrencyTest`, `AppSurfaceTest`.
- `tests/Support/`: `FinanceTestCase`, `FinanceSeed`, `FinanceInvariants`, `FinanceWorkers`, `finance_worker.php`.

**Added docs:**
- `docs/WP3A_FINANCE_MODEL.md`;
- `owner-review/WP3A/REPORT.md`;
- `owner-review/WP3A/TESTDOX.txt`.

**Not changed (byte-identical, pinned by `SourceGuardTest`):**
- R0001–R0006;
- the migration runner;
- `ServiceGate`, `RouteMap`, `Tx`, `SchemaReadiness`;
- `Balance.php`, `Package.php`;
- every controller and template;
- the spec pack and `MANIFEST.sha256`.

Totals: 42 files changed, 4854 insertions, 9 deletions in the code commits, plus the report commit.

## C. Database: R0001–R0006 byte-identical, no R0007
- `git diff 3e634550..HEAD -- system/reseller/migrations system/autoload/Reseller/Domain/ServiceGate.php docs/claude-development-pack` is empty.
- `system/reseller/migrations/` holds exactly R0001–R0006. The R0006 sha256 is `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd` (unchanged).
- `SourceGuardTest::testAcceptedFilesAreByteIdentical` pins the sha256 of R0001–R0006, ServiceGate, RouteMap, Tx, SchemaReadiness, Balance and Package to their `3e634550` content. It also asserts no R0007 and no `CREATE TRIGGER`.
- P1: immutability is enforced in the application (section L). No triggers were added.
- No schema change was needed. Nothing indicated one, so there was no stop-and-ask.

## D. Capability matrix (P2/P3)
| Capability | SuperAdmin | Admin | Agent | Sales | Report |
|---|---|---|---|---|---|
| `wallet.read` | G | G | T (own + subtree) | — | — |
| `ledger.read` | G | G | T (own + subtree) | — | — |
| `wallet.fund_withdraw` | G | G | — | — | — |
| `wallet.debt_limit` | G | G | — | — | — |
| `wallet.adjust` | G | G | — | — | — |
| `wallet.reverse` | G | G | — | — | — |
| `wallet.transfer` | G (direct-child rule) | G (direct-child rule) | C (own wallet → depth-1 child) | — | — |

Enforcement uses the accepted WP2 `PermissionService`, `DataScope::staffPredicate` and `revalidateActor`; no parallel authorization was added.

**Evidence (`FinanceCapabilityTest`):**
- the exact 7 × 5 matrix;
- frozen / inconsistent / non-enrolled / inactive / schema-not-ready cases fail closed for mutations;
- frozen Agents keep reads;
- pricing and activation are never granted;
- non-staff principals hold nothing.

## E. Money representation and boundary evidence
- **Representation:** integer minor units.
- **Storage range:** one `DECIMAL(18,2)` column, |v| ≤ 9,999,999,999,999,999.99. Enforced by `toStorage()` on every persisted value, before SQL.
- **Calculation range:** checked signed int64 for intermediates; every `plus`/`minus` is overflow-checked.
- **Inputs:** strings only; no float, BCMath or GMP.
- **Balance writes:** compare-and-set (`WHERE balance = CAST(? AS DECIMAL(18,2))`).

**Unit evidence (`MoneyTest`):**
- the 0.01 boundary;
- 9999999999999999.99 exact, and +0.01 is not storable;
- MAX + MAX = 19999999999999999.98 as a valid intermediate;
- the int64 limit and `MONEY_OVERFLOW`;
- 36 malformed inputs (NaN, INF, exponent, sign, spaces, newline, leading zeros, separators, 3 decimals, 17 digits, Arabic/full-width digits, float/int/null/bool/array);
- 10 × 0.10 = 1.00 and 0.1 + 0.2 = 0.30 exactly;
- strict column and aggregate parsing.

**Integration evidence (`MoneyBoundaryTest`, both engines):**
- balance MAX + debt MAX gives available 19999999999999999.98;
- two MAX reservations give SUM 19999999999999999.98 (beyond one column) with available 0.00, and a further 0.01 is refused;
- a cut of the debt limit by 0.01 is refused;
- settling both reaches exactly −MAX;
- funding and credit adjustment at MAX + 0.01 → `STORAGE_RANGE_EXCEEDED` before SQL;
- a 17-digit amount → `INVALID_AMOUNT`;
- reversal credit overflow is atomic;
- destination overflow is atomic (`TransferTest`).

## F. Wallet-creation policy evidence
A wallet is created only by provider funding, a positive debt limit, or a valid transfer destination.

| Test | Proves |
|---|---|
| `WalletTest::testAbsentWalletIsSynthesizedWithoutAnyWrite` | a read writes nothing |
| `testFirstFundingCreatesTheWalletWithInitialZeroState` | funding creates the wallet from zero |
| `testDebtLimitZeroOnAbsentWalletIsANoOp` | no wallet, no audit |
| `testPositiveDebtLimitCreatesTheWalletWithoutLedgerEntry` | wallet created, no ledger entry |
| `TransferTest::testDirectChildTransferIsBalancedAndAudited` | the destination is created |
| `AdjustmentTest::testAdjustmentsNeverCreateAWallet` | `WALLET_NOT_FOUND` for both credit and debit, and no row |
| `FundingTest::testInsufficientCreditOnAbsentWalletWritesNothing` | debits never create a wallet |
| `ReversalTest::testReversalNeverCreatesAWallet` | reversals never create a wallet |

`SourceGuardTest`: `INSERT INTO tbl_manager_wallets` exists only in `LockedWallet`. There is no backfill.

## G. Funding / withdrawal / adjustment evidence
**Funding** (`FundingTest`):
- one `PROVIDER_FUNDING` row (from NULL — no fake root wallet);
- one `FUNDING` credit;
- one `finance.fund` audit row with actor, target, reference, reason, before/after and no secrets.

**Withdrawal:**
- the exact floor (90.00 passes, 90.01 is refused) counts ACTIVE reservations and the debt line (P5: AVAILABLE ≥ 0);
- drawing into the debt line works.

**Idempotency:**
- a replay writes nothing;
- each of 9 payload variations → `IDEMPOTENCY_CONFLICT` (amount, target, reason, external reference present/absent, operator, operation, adjustment, reference case).

**Refusals:**
- invalid / frozen / inactive / non-Agent / non-enrolled targets;
- Agent, Sales and Report denied;
- an inactive Admin is denied;
- a refused reference stays reusable.

**Adjustments** (`AdjustmentTest`):
- the credit/debit ledger shape;
- the debit floor;
- blocked for frozen owners (P4).

## H. Transfer evidence (`TransferTest`)
**Success case:**
- the direct-child transfer is balanced: `TRANSFER_OUT` and `TRANSFER_IN` have the same amount and the same transfer id, with counterparties set;
- the destination wallet is created;
- the audit row has source and destination snapshots.

**Refused:**

| Refused case | Reason |
|---|---|
| sibling, ancestor, unrelated tree, grandchild (Agent) | `NOT_FOUND` |
| self | `SELF_TRANSFER` |
| Sales child | `WALLET_OWNER_INVALID` |
| forged source | `NOT_FOUND` |
| Admin non-direct pairs | `NOT_DIRECT_CHILD` |
| customer ids as target | refused |
| Sales / Report / non-enrolled actor | `FORBIDDEN` |
| inactive or frozen source/target | refused (including a stale context revalidated in-transaction → `ACTOR_FROZEN`) |

**Further evidence:**
- the available-credit boundary includes reservations and the debt line;
- a refused transfer does not create the destination wallet;
- an Admin transfer records the Admin as operator (P2);
- idempotent replay, and conflicts per field.

## I. Reservation evidence (`ReservationTest`)
- reserve/insufficient boundary;
- reservation history:
  - ACTIVE → replay; different amount or manager → `RESERVATION_CONFLICT` / `ACTIVATION_MISMATCH`;
  - COMMITTED → returned as COMMITTED with no new exposure;
  - RELEASED → `RESERVATION_RESOLVED`;
  - EXPIRED (fixture) → `RESOLVED`;
  - never a second exposure row;
- wholesale snapshot match;
- frozen owner refused for reserve;
- `reserveInTx` without the tree lock → `LOCK_REQUIRED`;
- commit exactly once:
  - `ACTIVATION_DEBIT` `res:{id}:commit` of the reserved amount;
  - a replay by another caller returns the same entry and never rewrites the actor;
  - commit after release → `RESERVATION_NOT_ACTIVE`;
- CUSTOMER actor preserved and different from the activation target; CRON explicit; no `API` actor anywhere;
- release:
  - no value moves, no fake ledger entry;
  - same resolver + note → replay; other note or resolver → `RESERVATION_CONFLICT`;
  - COMMITTED → `RESERVATION_ALREADY_COMMITTED`; EXPIRED → `RESERVATION_NOT_ACTIVE`;
  - customer → `INVALID_ACTOR`;
- commit and release allowed for a frozen owner;
- no blind expiry: a 2020-dated ACTIVE reservation stays ACTIVE, and there is no expire method.

## J. Reversal evidence (`ReversalTest`)
- **Reversal types:** funding, withdrawal, both adjustments and transfer reversals use mirrored rows with `reverses_transfer_id` and `REVERSAL` entries with `reverses_entry_id`. The original rows are byte-identical afterwards, and the original moves to REVERSED.
- **Historical transfer:** a reversal after `moveSubtree` unwinds the original counterparties and is not reinterpreted as a child→parent transfer.
- **Ordering** (owner clarification):
  - same reference and payload → replay with no effect;
  - new reference → `ALREADY_REVERSED`;
  - same reference with a different reason, operator or original → `IDEMPOTENCY_CONFLICT`;
  - reversal of a reversal → `NOT_REVERSIBLE`.
- **Floor:** an unaffordable debit side is refused with nothing written; a smaller correction then succeeds.
- **P4:** reversals of a frozen/inactive historical counterparty succeed while normal business operations stay blocked. Agents, Sales, Report and the parent itself cannot reverse.
- **No wallet creation.**

## K. Concurrency evidence (`ConcurrencyTest`, both engines)
**Method:**
- real PHP worker processes, each with its own DB connection;
- a deterministic barrier: the test holds the contended wallet row or the WP2 tree lock until every worker is blocked on the database, then releases it;
- 8 rounds per case on fresh resellers;
- no worker may end with an unexpected error (deadlock or timeout);
- whole-database invariants are checked after every round.

| Case | Valid outcome only |
|---|---|
| double spend of the last credit (2 × 60 of 100) | 1 success, 1 `INSUFFICIENT_AVAILABLE_CREDIT` |
| two transfers from one parent (2 × 70 of 100) | 1 success; the losing destination wallet is never created |
| reservation vs transfer / vs withdrawal | 1 success |
| reservation vs debt-limit reduction | either order, never both |
| 3 tree-locked transfers + 1 non-tree-locked commit on the same wallets | all 4 succeed, exact balances, no deadlock |
| commit vs duplicate commit | 1 ledger entry, 1 replay |
| commit vs release | exactly one terminal state |
| two reserves for the same activation (same / different amount) | one row; replay / `RESERVATION_CONFLICT` |
| same funding reference twice | one transfer, one replay |
| double reversal (different / same reference) | `ALREADY_REVERSED` / replay |
| subtree move vs transfer (tree-lock barrier) | serial only: the transfer commits before the move, or is refused with `NOT_DIRECT_CHILD` |
| free-running stampede, 6 × 30 against 100 (3 rounds) | exactly 3 succeed |

Deadlock policy (`FinanceTxTest`), tested with an injected 1213:
- the whole unit is retried;
- between attempts there is no transaction, the session sql_mode is restored, the tree lock is released (then re-acquired), and no rows or locks remain;
- the strict mode is in force inside every attempt;
- retries are bounded (2), then `CONCURRENCY_RETRY`;
- 1205 → `LOCK_TIMEOUT` without retry; other errors are not retried;
- `FinanceTx::treeLockName` equals the WP2 lock.

The lock order (section 8 of the model doc) means real deadlocks between finance operations are not expected, and none occurred in any race.

## L. Failure-injection evidence (`FailureInjectionTest`, 23 points, both engines)
| Operation | Fault points |
|---|---|
| fund | `fund.after_transfer_row`, `fund.after_ledger`, `fund.after_audit` |
| withdraw | `withdraw.after_transfer_row`, `withdraw.after_ledger`, `withdraw.after_audit` |
| adjust | `adjust.after_ledger`, `adjust.after_audit` |
| transfer | `transfer.after_wallet_locks`, `transfer.after_transfer_row`, `transfer.after_first_leg`, `transfer.after_second_leg`, `transfer.after_audit` |
| debt limit | `debt_limit.after_update`, `debt_limit.after_audit` |
| reverse | `reverse.after_reversal_row`, `reverse.after_first_leg`, `reverse.after_status_update`, `reverse.after_audit` |
| reserve | `reserve.after_insert` |
| commit | `commit.after_ledger_before_status`, `commit.after_status` |
| release | `release.after_status` |

For every point:
- the injection point was reached;
- the checksum of every table (finance, audit, customers, users) is identical to before, including a lazily created wallet;
- the invariants hold: no half-transfer, no orphan ledger row, no debit without ledger, no completed operation without all entries, no status without its entry;
- the transaction is closed, the session sql_mode restored and the tree lock released;
- retrying the same command succeeds exactly once, and the next call replays with no effect.

## M. Ledger continuity and immutability evidence
- **Invariants:** `FinanceInvariants::check` runs after **every** WP3A test and every concurrency round. It checks:
  - per-wallet continuity from 0.00;
  - balance = last `balance_after`;
  - AVAILABLE ≥ 0;
  - each operation's exact leg set;
  - 1:1 reversal links;
  - COMMITTED ↔ exactly one `ACTIVATION_DEBIT`;
  - no PENDING/FAILED rows;
  - key formats.
- **`LedgerTest`:**
  - continuity across every operation type, with exact `balance_after` sequences;
  - tamper detection;
  - derived unique keys; a duplicate leg → 1062; a mismatched leg is refused;
  - the public API is exactly `postTransferLeg`, `postActivationDebit`, `entries`, `entry`, `continuity`, with no amount/key/balance/actor parameters and no update/delete/edit method;
  - a forged `LockedWallet` → `LEDGER_STATE_MISMATCH` (compare-and-set), nothing written;
  - scoped reads (subtree yes; sibling, ancestor or unrelated `NOT_FOUND`; Sales/Report/non-enrolled `FORBIDDEN`; frozen keeps reads);
  - correction only by compensation;
  - UTC microsecond timestamps.

## N. Source-guard evidence (`SourceGuardTest`, 10 tests)
Over every runtime PHP/TPL file (`system`, `ui`, `install`, `index.php`, `init.php`, `radius.php`, `update.php`; vendor and accepted migration definitions excluded):
- **Ledger:** no UPDATE/DELETE/REPLACE/TRUNCATE/ALTER/DROP anywhere; `INSERT` only in `LedgerService` (once, in a private method).
- **Wallets:** `INSERT` only in `LockedWallet`; balance `UPDATE` only in `LedgerService`; debt-limit `UPDATE` only in `WalletService`; no other write.
- **Reservations and transfers:** writes only in `ReservationService` / `TransferService`. The only transfer `UPDATE` is `status = 'REVERSED' WHERE id = ? AND status = 'COMPLETED'`. No DELETE.
- **ORM:** no `for_table('tbl_manager_…')` or activation ORM access.
- **Finance code** contains:
  - no `Balance::`, `Package::`, `tbl_customers`, `User::` or `Transaction::`;
  - no request superglobals or `_post/_get/_req`;
  - no float casts, float literals, `round` or BCMath/GMP;
  - no `'API'` literal;
  - no network calls.
- **Entrypoints:** no controller, API, plugin, widget, cron or template references the finance services. RouteMap and `API_ALLOWED` have no finance key.
- **Fault hook:** `FinanceFaults::$hook` is never assigned at runtime.
- **No generic ledger writer.**
- **Pinned files:** R0001–R0006, ServiceGate and other accepted files are byte-identical; no R0007; no triggers.

## O. Customer-balance separation evidence
- Every WP3A integration test asserts the `CHECKSUM TABLE tbl_customers` (legacy customer balance included) is unchanged across the test.
- `AppSurfaceTest` checks the customer balance after an application-context funding.
- The source guards forbid `Balance::*` and `tbl_customers` in finance code.
- `Balance.php` and `Package.php` are byte-identical.
- The WP2 ServiceGate is byte-identical, and `ServiceGateTest` is still green. Reseller-owned service granting stays blocked until WP5B.

## P. Test matrix (PHP 8.2.34, PHPUnit 11.5.56, `dev/test/run-tests.sh`)
| Engine | Tests | Assertions | Plain | `--testdox` |
|---|---|---|---|---|
| MariaDB 10.11.19 | 365 | 24201 | OK | OK |
| MySQL 8.0.46 | 365 | 24201 | OK | OK |

- **Coverage:** the 365 tests are the accepted WP1 + WP2 suite (207 tests) plus WP3A (158 tests: `wp3a-unit` 65 tests / 12401 assertions, `wp3a-integration` 93 tests / 2431 assertions, identical on both engines). The accepted WP2 tests now report more assertions because their loops over `Capability::all()` cover the new capabilities.
- **Testdox:** 730 ✔ / 0 ✘ (both engines). See `TESTDOX.txt`.
- **Registry reproducibility:** `variant registry matches freshly built fixtures` in both runs (V1/V2/V3 fingerprints unchanged).
- **Route / security matrix:** `RouteMatrixTest` with `WP2_EVIDENCE=1` gives 1442 requests, 1164 denials, 278 allowed. The rows are identical on MariaDB and MySQL and identical to the accepted `owner-review/WP2/ROUTE_MATRIX_EVIDENCE.txt`, so WP3A adds no route and changes no decision.
- **No finance route:** `AppSurfaceTest` checks unmapped finance routes for sa/ad/A (web GET/POST and API) and reseller-alias attempts. All are denied or at most the read-only tree page, and no finance, customer or tree table changed.
- **PHP lint:** the 40 changed PHP files are clean on PHP 8.2.34 (test image) and PHP 8.3.6.
- **Secret sweep:** run over the full `3e634550..HEAD` diff and the docs. It found no DB password, API secret, private key, token pattern or password literal (the only passwords are the disposable test-fixture conventions).
- **Manifest:** `sha256sum --strict -c MANIFEST.sha256` gives 42/42 OK.

## Q. Unresolved risks / follow-ups
1. **SEC-01** (`radius.php` SQL injection) could write any table, finance included. WP4 must close it before release or production acceptance (binding).
2. **Application-level immutability (P1):** a privileged DB user can still UPDATE/DELETE history. WP9 re-tests tamper surfaces; WP10 designs a least-privilege runtime DB account (binding).
3. **Global serialization:** hierarchy-dependent finance mutations share the WP2 tree lock (short transactions). WP9 should measure throughput; commit/release do not take it.
4. **Internal reservation primitives** (reserve/commit/release) have no capability of their own. WP5A must authorize their callers.
5. **Stale ACTIVE reservations** are not resolved until WP5A reconciliation (no blind expiry, by decision).
6. **SuperAdmin code surfaces** (plugins / Smarty pages) remain (WP9). dbbackup exports finance tables (SuperAdmin, read-only).
7. **P4 exception check:** implementation found no unavoidable accounting-correction case requiring withdrawal, adjustment or debt-limit changes on frozen wallets; `reverse()` covers historical corrections. Nothing was broadened.

## R. Git, push and environment status
- **Working tree:** clean after the report commit.
- **Push:** `git push -u origin wp3a/wallet-ledger` (normal, no force). No PR, merge or deploy.
- **Unchanged branches:** `feat/reseller-system-v1` = `3e634550` (local = origin); `main` = `500d3a2d…`.
- **Production:** not touched.
- **WP3B:** not started.

WP3A remains **IMPLEMENTED_NOT_ACCEPTED** pending owner review.
