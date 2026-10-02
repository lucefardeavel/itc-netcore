# WP3B — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED**. Two owner fix rounds have been applied:
- round 1: deterministic concurrency assertion count (§L.1);
- round 2: plan-delete TOCTOU race fixed (§L.2).
- WP3B is implemented on `wp3b/pricing-settlement`, branched from the accepted integration base `d4ba451437c3abf1b6349494b40b93257eaca553` (WP3A accepted at `c329145a`).
- It follows the owner-approved final plan (Revision 2) and the owner decisions OD-3, OD-4, OD-5, F-1 … F-8.
- It is waiting for owner review.

| Item | Value |
|---|---|
| Branch | `wp3b/pricing-settlement` (created from `d4ba4514`; nothing reset, rebased or rewritten) |
| Base | `d4ba451437c3abf1b6349494b40b93257eaca553` = `origin/feat/reseller-system-v1` |
| Ancestry | `d4ba4514` → `c329145a` (WP3A) → `1c66ec5b` (WP2) → `1f36bfa` (WP1) |
| Original candidate HEAD | `2c543a63969ec8e4bd1aa541d8249ac219743ba5` (docs commit). Owner review: **WP3B FIX REQUIRED** (non-deterministic assertion totals) |
| Fix commit, round 1 | `e7d2e7a3b5ce2d074d3819fd32ec48180a27a217` `test(wp3b): deterministic assertion count for the near-maximum race`: test-only, one file (§L.1) |
| Candidate after fix round 1 | `ae636d1497ef1e32dcc8354eb9b01aba0c911a36` (evidence commit, pushed). Owner code review: **WP3B FIX REQUIRED**: plan-delete TOCTOU race (§L.2) |
| Fix commit, round 2 | `9978311a1ebaa202e96c0bc06a51672645e03ee7` `fix(wp3b): serialize legacy plan delete against plan-reference writers` (§L.2) |
| Candidate HEAD after fix round 2 | the evidence commit `docs(wp3b): plan-delete race fix evidence` on top of `9978311a` (SHA given in the final response = `origin/wp3b/pricing-settlement`) |
| Schema | **no change**: no R0007, no trigger, R0001–R0006 byte-identical (R0006 `f1a76119…7abd`) |
| `feat/reseller-system-v1` / `main` | unchanged: `d4ba4514` / `500d3a2d15003efeed6e0aad639c13d08101c3b1` |
| Push | `wp3b/pricing-settlement` only, after each fix round (owner-authorised, normal non-force push) |
| PR / merge / tag / deploy | none |
| Production | not touched |
| WP4 / WP5A / WP5B | not started |

Detailed model: `docs/WP3B_PRICING_MODEL.md`. Test evidence: `owner-review/WP3B/TESTDOX.txt`.

## A. Commits

| Commit | Content |
|---|---|
| `b2b147f7` feat(wp3b): activate the pricing capabilities, codes and readiness | `Capability`, `RoleCapabilityMap`, `DomainError`, `SchemaReadiness`; two accepted assertions written for WP3B updated |
| `954e86cc` feat(wp3b): pricing service, immutable snapshot and legacy retail parser | `PricingService`, `PricingSnapshot`, `PricingLink`, `PricingResult`, `PricingReadModel`, `LegacyRetailPrice` |
| `6e5f2361` feat(wp3b): atomic activation settlement with intermediary commissions | `LedgerService::postActivationSettlement` (replaces `postActivationDebit`), `ReservationService` reserve / commit, `SettlementFailure`, `PostedSettlement`; owner-approved API-pin updates; invariants / worker / barrier |
| `9045a0cd` feat(wp3b): refuse deleting plans referenced by reseller data | `Domain/PlanGuard`, `system/controllers/services.php` (four delete paths, CRLF kept) |
| `09954cac` test(wp3b): pricing, settlement, concurrency, failure-injection suites | `tests/Unit/Wp3b` (7), `tests/Integration/Wp3b` (10), support, suites |
| `2c543a63` docs(wp3b): pricing model and owner-review report | `docs/WP3B_PRICING_MODEL.md`, WP2 permission-model rows, WP3A finance-model amendment note, this report, `TESTDOX.txt` (original candidate HEAD) |
| `e7d2e7a3` test(wp3b): deterministic assertion count for the near-maximum race | owner fix round 1: `tests/Integration/Wp3b/PricingConcurrencyTest.php` only (§L.1) |
| `ae636d14` docs(wp3b): fix-round evidence for deterministic concurrency totals | this report and `TESTDOX.txt` (fix round 1; pushed; reviewed candidate of round 2) |
| `9978311a` fix(wp3b): serialize legacy plan delete against plan-reference writers | owner fix round 2: `Domain/PlanGuard.php`, `system/controllers/services.php` (four delete paths), `PlanDeleteRaceTest`, `finance_worker.php` pause barrier, `PricingSourceGuardTest`, `docs/WP3B_PRICING_MODEL.md` (§L.2) |
| evidence commit | this report and `TESTDOX.txt` updated with the fix-round 2 evidence |

All commits are authored by `ALI HENDI <ali.hahuba3@gmail.com>` with the `Co-Authored-By` trailer. Only `wp3b/pricing-settlement` is pushed (after each fix round); nothing is merged.

## B. Changed files (`git diff --name-status d4ba4514..HEAD`)

**Runtime, modified:**
- `Reseller/Security/Capability.php`
- `Reseller/Security/RoleCapabilityMap.php`
- `Reseller/Domain/DomainError.php`
- `Reseller/Domain/SchemaReadiness.php`
- `Reseller/Finance/LedgerService.php`
- `Reseller/Finance/ReservationService.php`
- `system/controllers/services.php`

**Runtime, added:**
- `Reseller/Finance/{PricingService, PricingSnapshot, PricingLink, PricingResult, PricingReadModel, LegacyRetailPrice, SettlementFailure, PostedSettlement}.php`
- `Reseller/Domain/PlanGuard.php`

Every new file carries the `NUXR_RUNTIME` guard.

**Tests, added:**
- `tests/Unit/Wp3b/`: `SettlementMathTest`, `PricingSnapshotTest`, `LegacyRetailPriceTest`, `PricingCapabilityTest`, `PricingReadModelTest`, `SettlementFailureTest`, `PricingSourceGuardTest`.
- `tests/Integration/Wp3b/`: `BaseCostTest`, `WholesaleTest`, `RetailTest`, `AvailabilityTest`, `ResolutionSnapshotTest`, `SettlementTest`, `PricingFailureInjectionTest`, `PricingSchemaReadinessTest`, `PricingConcurrencyTest`, `PlanDeleteGuardTest` (HTTP).
- `tests/Support/{PricingTestCase, SnapshotFactory}.php`.
- Fix round 2 (`9978311a`):
  - runtime: `Reseller/Domain/PlanGuard.php` (`deleteRefusal` replaced by `deleteSerialized`) and `system/controllers/services.php` (the four delete paths, CRLF kept);
  - tests: new `tests/Integration/Wp3b/PlanDeleteRaceTest.php` (4 tests, HTTP); `tests/Support/finance_worker.php` (optional pause barrier); `tests/Unit/Wp3b/PricingSourceGuardTest.php` (serialized-delete structure, plan-reference writer contract; 7 → 8 tests);
  - docs: `docs/WP3B_PRICING_MODEL.md` §9 / §11 / §12.

  No migration, no FK, no pricing / settlement / ledger code, no harness file changed. `PlanDeleteGuardTest` (sequential) is unchanged.
- Fix round 1 (`e7d2e7a3`) changed **only** `tests/Integration/Wp3b/PricingConcurrencyTest.php` (+108 / −28): 19 → 21 tests. No runtime, support, harness, migration or doc file changed in that commit.

**Tests, modified:**
- Suites (`phpunit.xml`: `wp3b-unit`, `wp3b-integration`; Wp3b excluded from `unit` / `integration`) and `bootstrap.php` (support list).
- `FinanceInvariants`: commission legs and conservation.
- `finance_worker.php`: pricing / settlement operations and typed failure.
- `FinanceWorkers`: the barrier also recognises shared row locks (`LOCK IN SHARE MODE`).

**Accepted WP3A tests changed** (owner-approved, narrowest change):

| Test | Change | Why |
|---|---|---|
| `FinanceCapabilityTest::testPricingAndActivationStayReserved` | RESERVED = activation only | pricing activated (written for WP3B) |
| `FinanceSchemaReadinessTest::testRequiredTableListIsExact` | +2 pricing tables; the "no pricing table" negative assertion removed | pricing tables required (written for WP3B) |
| `LedgerTest::testNoGenericOrMutatingLedgerApi` | public list names `postActivationSettlement`; mutator regex `set` → `set(?!tlement)` | F-2 dedicated primitive (owner-approved) |
| `SourceGuardTest::testNoGenericLedgerWriter` | `post*` list names `postActivationSettlement` | F-2 (owner-approved) |

No other accepted test assertion was changed.

**Docs:**
- New: `docs/WP3B_PRICING_MODEL.md`, `owner-review/WP3B/{REPORT.md, TESTDOX.txt}`.
- Updated: `docs/WP2_PERMISSION_MODEL.md` (pricing rows), `docs/WP3A_FINANCE_MODEL.md` (operations row + WP3B amendment section).

**Not changed** (byte-identical; pinned by the WP3A `SourceGuardTest`):
- R0001–R0006 and the migration runner;
- `ServiceGate`, `RouteMap`, `Tx`;
- `Balance.php`, `Package.php`;
- the spec pack and `MANIFEST.sha256`;
- the acceptance ledger;
- every other controller and template.

## C. Architecture
- **Pricing (`PricingService`).** It is the only writer of the two pricing tables. Every mutation runs:
  1. capability check;
  2. strict input;
  3. WP2 tree lock;
  4. BEGIN (strict);
  5. actor revalidated;
  6. base row `FOR UPDATE`;
  7. the plan's override rows `FOR UPDATE` (ascending);
  8. validation;
  9. write;
  10. R0006 audit;
  11. COMMIT.

  Identical values are no-ops. A cleared all-NULL row is deleted.
- **Resolution (`resolveInTx`).** Tree lock asserted; WP5A / `reserveInTx` only. It returns the sealed, canonical `PricingSnapshot` or throws the first saleability reason (deterministic order, model §5). `quote` (role-filtered) and `validateTree` (Admin diagnostics) are tree-locked reads.
- **Settlement.** `LedgerService::postActivationSettlement` is the single activation financial posting primitive:
  - the leaf `ACTIVATION_DEBIT` `res:{reservation}:commit`;
  - every positive intermediary `COMMISSION_CREDIT` `com:{activation}:m{manager}`, re-derived from the locked reservation and the activation's sealed snapshot;
  - all legs validated before the first write, through one private INSERT.

  `postActivationDebit` no longer exists. Provider settlement P stays on the activation and is never a wallet credit; there is no provider / root wallet.
- **Reserve** checks every deterministic precondition before provisioning (model §8). **Commit** takes the canonical lock order, re-checks headroom and the leaf floor, posts atomically, and on failure raises a typed `SettlementFailure`.
- **Plan delete.** `PlanGuard` refuses referenced plans before any hook, device call or delete.

## D. Capability matrix

| Capability | SuperAdmin | Admin | Agent | Sales | Report |
|---|---|---|---|---|---|
| `pricing.read` | G | G | T | — | — |
| `pricing.base_cost` | G | G | — | — | — |
| `pricing.child_wholesale` | G | G | C (direct children, never self) | — | — |
| `pricing.own_retail` | G (OD-4) | G (OD-4) | S | — | — |
| `pricing.child_availability` | G (OD-3) | G (OD-3) | C | — | — |

- All five are `SCHEMA_DEPENDENT`; the four mutating ones are `MUTATING`.
- `RESERVED` = `activation.create`, `activation.read`.
- Evidence: `PricingCapabilityTest` covers the exact 5 × 5 matrix, frozen / inconsistent / non-enrolled / inactive / not-ready cases, and that non-staff principals hold nothing.

## E. Pricing rules (evidence)

| Rule | Tests |
|---|---|
| Missing base row → not resellable; resale disabled → everyone unavailable; base created disabled; base 0 valid; MAX valid; malformed input refused before SQL; Admin/SuperAdmin only; no-ops not audited | `BaseCostTest` |
| Balance plans not resellable (config + resolution); Hotspot / PPPOE / VPN eligible | `BaseCostTest::testBalancePlansAreNotResellable` |
| Zero leaf not activatable; base 0 with leaf > 0 valid (owner example 0 → 5 → 8: debit 8, R1 3, P 5) | `BaseCostTest::testZeroBase…`, `SettlementTest`, `SettlementMathTest` |
| Admin sets top level; parent sets direct child; equal allowed; below parent refused; self / sibling / grandchild / unrelated / forged ids refused; Sales / non-enrolled / Admin targets invalid | `WholesaleTest` |
| Inheritance: nearest ancestor override, base fallback, clear → inherit, all-NULL row deleted, deep chains | `WholesaleTest::testInheritance…` |
| OD-5: introduced descendant conflict rejected (never rewritten); bottom-up fix; pre-existing violation from a real `moveSubtree` fails closed and never blocks corrections | `WholesaleTest`, `BaseCostTest::testUpstreamBaseRaise…` |
| Frozen target: Agent refused, Admin allowed; frozen actor refused | `WholesaleTest::testDeepChainAndFrozenPolicy`, `AvailabilityTest::testAuthority` |
| Retail: explicit / clear to legacy default; below own wholesale refused; equal allowed; OD-4 authority; legacy `+1000` / `0100` / `1,000` / empty never normalised (fails closed); invalid clear refused; legacy below wholesale fails closed; `tbl_plans` unchanged | `RetailTest`, `LegacyRetailPriceTest` |
| F-5: upstream raise allowed, stored retail kept, descendant unsaleable + warning + audit + `validateTree`, historical snapshot unchanged, reservation of the stale snapshot refused, correction restores saleability | `RetailTest::testUpstreamRaise…` |
| Browser money never authoritative: forged amount `ACTIVATION_MISMATCH`, forged retail / settlement column `PRICING_SNAPSHOT_INVALID` | `RetailTest::testBrowserMoney…` |
| Availability: ancestor 0 blocks the subtree; explicit 1 cannot override; NULL inherits; global disable wins; authority (no self, direct children, Admin any) | `AvailabilityTest` |

## F. Settlement rules (evidence)

| Rule | Tests |
|---|---|
| Exact math for one / two / three levels, inherited middle, equal adjacent, base 0, cents, deep chains, maximum value, 30-level no-drift | `SettlementMathTest` (12 worked chains + zero leaf + 30-level drift test) |
| Spec chain 10 → 12 → 15 → 17 settles: R3 −17, R2 +2, R1 +3, P 12; keys, counterparties, actor; no provider wallet; replay writes nothing | `SettlementTest::testSpecChain…` |
| Every chain shape through reserve + commit; no zero-value entry | `SettlementTest::testOneTwoLevel…` |
| F-1: a positive recipient without a wallet → `COMMISSION_RECIPIENT_NO_WALLET` before provisioning, nothing written, no wallet created; after an approved creation path the sale works; a zero-margin ancestor needs no wallet | `SettlementTest::testCommissionRecipientMustAlreadyHaveAWallet` |
| F-6: capacity refused at reserve; state changes after reserve; commit → `SettlementFailure` CAPACITY with nothing written and the reservation ACTIVE; administrative fix → exactly one settlement | `SettlementTest::testRecipientCapacity…` |
| Frozen recipient still credited; a move after reserve keeps the snapshotted chain; staff / customer actors on every leg | `SettlementTest` |
| Stale / partial / zero / legacy (no-snapshot) activations | `SettlementTest::testStalePartialZeroAndNullSnapshotActivations` |
| Tampering after reserve → `SettlementFailure` INTEGRITY, nothing written | `SettlementTest::testCustomerActorAndTamperedSnapshotAtCommit` |
| Canonical snapshot bytes, completeness, every listed tamper / partial / non-canonical case refused, invariants on seal, plan snapshot without scripts | `PricingSnapshotTest` |
| Historical snapshot byte-identical after base, ancestor, retail and availability changes and a hierarchy move; T3 resolves the new values | `ResolutionSnapshotTest::testHistoricalSnapshot…` |
| Typed failure classification; message preserved and sanitized | `SettlementFailureTest` |
| `FinanceInvariants` after every test: commission set = snapshot positive margins; Σ + P = debit = `wholesale_cost`; no commission without a committed reservation or snapshot | all WP3A + WP3B finance tests |

## G. Schema readiness
- `REQUIRED_TABLES` += `tbl_reseller_plan_base_costs`, `tbl_manager_plan_prices`. `EXPECTED` stays R0001–R0006.
- `PricingSchemaReadinessTest` takes each pricing table offline in turn:
  - the status is exactly `table <t> missing`;
  - pricing, finance and tree capabilities are closed for SuperAdmin / Admin / Agents (no bypass);
  - 12 pricing / settlement / finance calls are refused with `SCHEMA_NOT_READY`;
  - no fault point is reached and nothing is written.

  After the table is restored, readiness returns and real operations (including a settlement) succeed.

## H. Source guards (`PricingSourceGuardTest`, 7 tests; the WP3A guards stay green)
- **Pricing tables:** only `PricingService` writes them. The only DELETE is the exact all-NULL row statement. There are no base-row deletes, upserts, ORM access, TRUNCATE / ALTER / DROP or LOAD DATA.
- **Activation snapshots are write-once:**
  - only `SchemaReadiness`, `LedgerService` and `ReservationService` reference `tbl_reseller_activations`;
  - no UPDATE names a snapshot column;
  - an activation INSERT must seal all five columns;
  - no delete, upsert or ORM access.
- **One settlement authority:**
  - `ACTIVATION_DEBIT` / `COMMISSION_CREDIT` appear only in `LedgerService`;
  - `postActivationDebit` is gone;
  - `postActivationSettlement(pdo, reservationId, actor, leaf, ...recipients)` is called only by `ReservationService`.
- **No money writes from pricing:** pricing files never write wallets / ledger / transfers / reservations / `tbl_plans` / `tbl_customers` and never call a finance writer. No reseller code writes `tbl_plans`. `PlanGuard` is SELECT-only, apart from its `FOR UPDATE` plan-row lock.
- **No entrypoint:** no controller / API / plugin / cron / template references pricing or settlement classes, and no pricing route or `API_ALLOWED` key exists.
- **Plan delete (since fix round 2):** in all four delete paths, the hook, the device call and the delete exist only inside the `PlanGuard::deleteSerialized` closure, and no unserialized pre-check remains. `PlanGuard` takes the tree lock, then the plan-row lock, then runs the check, then the effects. CRLF is preserved.
- **Plan-reference writers:** the only runtime INSERT into a table referencing `tbl_plans` is in `PricingService`, and every `PricingService` transaction holds the tree lock. WP5A's ActivationService must join under the same contract.
- **Accepted WP3A guards** pass over the new Finance files: no float / `round` / `number_format`, no `tbl_customers` / `Balance::` / `Package::`, no request input, no network, `NUXR_RUNTIME`, pinned files byte-identical, no R0007, no triggers.

## I. Audit
- Events:
  - `pricing.base_cost_set`
  - `pricing.resale_enable` / `pricing.resale_disable`
  - `pricing.wholesale_set` / `pricing.wholesale_clear`
  - `pricing.retail_set` / `pricing.retail_clear`
  - `pricing.availability_set` / `pricing.availability_clear`
  - `plan.delete_refused`
- Each row records the actor, target (reseller + plan identity), before / after of the changed override only, the reason, and the UTC time from R0006. Unsaleable-descendant warnings carry a count plus up to 50 ids.
- No snapshots, no secrets; payload keys avoid the redactor's secret words.
- Tests: `BaseCostTest`, `WholesaleTest`, `RetailTest`, `AvailabilityTest`, `PlanDeleteGuardTest`.

## J. Concurrency (`PricingConcurrencyTest`, real worker processes, deterministic barriers, 8 rounds per case)
- **Settlement with a commission against each writer on the recipient:** transfer from the recipient, provider funding, withdrawal, debt limit, credit adjustment, debit adjustment. Both commit, with exact balances.
- **Settlement against a reversal, a second settlement sharing the recipient, and a reserve by the same leaf:** all four commit, with exact balances.
- **FK identity row:** the leaf agent's own tree-locked transfer races the STAFF-operator settlement of its activation (`LOCK IN SHARE MODE` identity order). Both commit; no deadlock.
- **Near-maximum recipient balance:** exactly one of the commission and a funding fits. Settlement-first → funding `STORAGE_RANGE_EXCEEDED`. Funding-first → `SettlementFailure` CAPACITY with no partial settlement, then an administrative fix leads to one settlement.
  - Since fix round 1, every round builds one outcome record (successes, refusal reasons, commissions, reservation status, leaf balance), asserts it equals the expected record of the winner, then drives both orders to the same final state (exactly one settlement). The assertion count is identical whichever worker wins.
  - Both orders are also run deterministically, without a race: `testNearMaximumSettlementFirst` and `testNearMaximumFundingFirst`.
  - The retail-versus-raise race asserts one saleability record, independent of the order.
- **Pricing mutation races:**
  - parent child-set vs ancestor raise: exactly one commits and the wholesale tree is never invalid;
  - own retail vs upstream raise: never saleable below wholesale;
  - availability vs ancestor disable.
- **Reserve vs price change / hierarchy move:** the reserve commits before the change, or is `PRICING_SNAPSHOT_STALE`.
- **Every round:** no worker error, no `CONCURRENCY_RETRY` / `LOCK_TIMEOUT` / TRANSIENT, and whole-database invariants hold.
- **Plan delete vs pricing reference (fix round 2, `PlanDeleteRaceTest`):** the real HTTP delete races a real `setBaseCost` worker. There are two deterministic orders, a lock-timeout case and an 8-round free race. Only "delete first: pricing waits and finds no plan" or "pricing first: delete refused before hook and device" can occur; a device/database split fails the test (§L.2).
- **Accepted WP3A `ConcurrencyTest`:** stays green. Its barrier now also counts shared identity locks, because the settlement takes them first.

## K. Failure injection (`PricingFailureInjectionTest`, 15 points)
- **Points:**
  - `pricing.after_write` / `pricing.after_audit` × base, resale, wholesale, retail, availability;
  - `commit.after_debit_before_commissions`;
  - `commit.after_commissions`;
  - `commit.after_ledger_before_status`;
  - `commit.after_status`;
  - `reserve.after_insert` with a sealed snapshot.
- **For each point:** the point was reached, the checksums of every table are unchanged, invariants hold, the transaction is closed, `sql_mode` is restored and the tree lock released. Settlement failures are typed. The retry succeeds once, and the next call is a no-op / replay with no effect.
- **Accepted WP3A `FailureInjectionTest`:** green, with its commit points unchanged.

## L. Test matrix (PHP 8.2.34, PHPUnit 11.5.56, `dev/test/run-tests.sh`)

Original candidate evidence (code `09954cac`, candidate HEAD `2c543a63`). It is kept unchanged as historical evidence; §L.1 supersedes its totals.

| Run | MariaDB 10.11.19 | MySQL 8.0.46 | Registry |
|---|---|---|---|
| Accepted WP3A baseline (for reference) | 371 / 24904 | 371 / 24904 | matches |
| WP3B plain run 1 (36m54s) | **OK 463 / 43108** | 462 OK + 1 known environment race (see below) | matches |
| WP3B `--testdox` (32m29s) | **OK 463 / 43108** | **OK 463 / 43108** | matches |
| WP3B plain re-run (30m25s) | **OK 463 / 43108** | **OK 463 / 43102** | matches |

- **Totals per engine (original candidate):** 463 tests = the accepted 371 + 92 WP3B tests (34 unit + 58 integration). Assertions rose from 24904 to 43108. The new tests are expected; there is no regression.
- **Assertion totals vary by race order (original candidate; owner review: FIX REQUIRED; fixed in §L.1).** The new near-maximum concurrency case asserts 4 things on its fund-first branch and 2 on its commit-first branch (8 rounds). Totals such as 43102 vs 43108 therefore come from which worker wins, not from a lost test; the test count is always 463 and every test passes.
- **Testdox:** 926 ✔ / 0 ✘ (both engines). All 742 accepted testdox lines are present unchanged, plus 184 new lines. See `TESTDOX.txt`.
- **Known environment race:** `CliOnlyTest::testNewPhpFilesRefuseHttpExecution` failed once in plain run 1 on MySQL (`Connections` 201 → 202). It is the same race recorded in the dev-host baseline (there on MariaDB):
  - The test compares the server-global `Connections` counter, and the compose healthcheck connects on its own: measured on the idle test MySQL, 4 connections in 12 s (`mysqladmin ping` every 3 s); on MariaDB, 2 per healthcheck.
  - The isolated test passed 20/20 on each engine.
  - WP3B changed no file the test probes except one line of `tests/bootstrap.php` (support-class list), which keeps its CLI guard.
  - Classification: ENVIRONMENT / test-harness timing, not a product regression. Not patched (a test change would need owner approval).
### L.2 Owner fix round 2: plan-delete TOCTOU race (fix commit `9978311a`)

**Reviewed candidate:** `ae636d1497ef1e32dcc8354eb9b01aba0c911a36` (pushed). Owner code review: **WP3B FIX REQUIRED**. The pricing / settlement implementation was accepted so far.

**Defect (product, confirmed).**
- The four legacy delete paths called `PlanGuard::deleteRefusal()` (an unlocked reference check), then `run_hook`, the router / RADIUS `remove_plan`, and `$d->delete()`.
- A concurrent `PricingService::setBaseCost` (tree-locked, but the delete was not) could commit a `tbl_reseller_plan_base_costs` reference in that gap. The device plan was then removed, FK RESTRICT refused the row delete, and the database plan and its pricing reference stayed. The controller's `PDOException` catch prevented a 500, not the split.

**Reproduced before the fix.** The new `PlanDeleteRaceTest` was run against the reviewed `PlanGuard.php` and `services.php` (`ae636d14`), with only the new test and its worker barrier added. All 4 tests fail on both engines:
- delete-first, pricing-first and the free race show the **device removed with the database plan kept** (the split);
- the lock-timeout case shows the delete ignoring the busy tree lock;
- on MariaDB, one free-race round also left the pricing writer with a raw FK 1452 error.

**Fix (`9978311a`).** `PlanGuard::deleteSerialized(int $planId, callable $delete)` replaces `deleteRefusal`. The four delete paths pass their unchanged legacy delete as the closure (hook, `remove_plan` in the legacy order, `$d->delete()`); CRLF is kept. The unit runs in this order:
1. **Existing reseller tree lock** (`Tx::withTreeLock`, `GET_LOCK` 10 s), taken **before** the final check and released in `finally` after COMMIT or ROLLBACK. Every pricing writer (`FinanceTx::run(…, true)`) holds this same lock while it creates a reference, so no pricing reference can enter the gap. No new or competing lock was introduced.
2. **Plan row `FOR UPDATE`** inside the transaction, before the check. This is a database-level backstop for any writer outside the tree lock. Such a writer's FK check needs a shared lock on the plan row, so its reference either committed before the check (and is seen) or waits and then fails on the missing plan. It cannot appear between the decision and the delete.
3. **Final reference check** (schema-driven, every FK to `tbl_plans.id`). If referenced → refused with no hook, no device call and no database change; audit `plan.delete_refused`; clear message.
4. The request's own `sql_mode` is restored for the legacy code. Then the hook, the device removal and the row delete run, followed by COMMIT and lock release.

**Failure / timeout policy:**
- tree lock not obtained in 10 s → "The hierarchy is busy; please try again.", nothing done;
- error before the effects → fail closed (refusal, nothing done);
- FK 1451 during the effects (no longer reachable by in-application writers) → REFERENCED and database rollback;
- any other error during the effects → rethrown unchanged (legacy behaviour).

**Unchanged:** no FK weakened, no reference or history deleted, no device compensation, no pricing economics change, no migration (R0001–R0006 byte-identical, no R0007).

**WP5A contract** (model §9.4 / §11, report §O): activation rows, and any future `tbl_plans` reference, must be inserted inside the tree-locked transaction that resolves the plan. A source guard pins that the only runtime plan-reference writer today is `PricingService` and that every one of its transactions holds the tree lock.

**Tests (fix round 2):**
- `PlanDeleteRaceTest` (HTTP, real `services/delete` vs a real `setBaseCost` worker, both engines). Every case records one outcome (HTTP status, plan in DB, hook ran, device removal, base-cost row, pricing result, refusal audits) and asserts "never device removed with the plan kept":
  - `testDeleteWinsSerialization`: the delete is held inside its hook. The pricing writer must block on the tree lock (asserted), then gets `NOT_FOUND`. Plan deleted, hook and device removal ran, no base-cost row.
  - `testPricingWinsSerialization`: the pricing writer is paused after inserting the reference, holding the lock. The delete must block (asserted), then is refused before the hook and the device call. Plan kept, base-cost row kept, one refusal audit.
  - `testLockTimeoutRefusesBeforeAnyEffect`: the tree lock is held past 10 s, so the delete is refused with the retry message, with no hook, no device call and no change.
  - `testConcurrentDeleteAndPricingNeverSplit`: 8 free-running rounds. Every round must equal one of the two coherent outcome records.
- `PricingSourceGuardTest`: the serialized structure of all four paths and the plan-reference writer contract (8 tests).
- `PlanDeleteGuardTest` (sequential): unchanged and green.

**Repeated runs of `PlanDeleteRaceTest`** (after the fix):

| Engine | Runs | Result every run | Free-race winners per run (pricing-first / delete-first) |
|---|---|---|---|
| MariaDB 10.11.19 | 5 | **OK 4 / 29** | 7/1, 8/0, 6/2, 6/2, 8/0 |
| MySQL 8.0.46 | 5 | **OK 4 / 29** | 6/2, 7/1, 7/1, 8/0, 3/5 |

Both orders are also exercised deterministically in every run. The WP3B suites (`wp3b-unit` + `wp3b-integration`) gave **OK 99 / 6161** on both engines.

**Full canonical validation on `9978311a`** (PHP 8.2.34, PHPUnit 11.5.56):

| Run (UTC) | MariaDB 10.11.19 | MySQL 8.0.46 | Registry | Exit |
|---|---|---|---|---|
| `run-tests.sh` (08:46–09:16, 29m46s) | 467 OK + 3 errors in `Http\RouteMatrixTest` (the `AppServer` port-reuse harness flake, below): 470 / 40172 | **OK 470 / 43219** | matches | 1 |
| `run-tests.sh --testdox` (09:16–09:50, 34m17s) | **OK 470 / 43219** | **OK 470 / 43219** | matches | 0 |
| `run-tests.sh` re-run (09:50–, stopped by the owner) | **OK 470 / 43219** | stopped at about 74 %, every test so far passing | — | stopped |

- **Final totals per engine: 470 tests / 43219 assertions**, identical on both engines in every complete leg.
  - 470 = 465 + 4 `PlanDeleteRaceTest` + 1 new source-guard test; 99 WP3B tests.
- **Testdox:** 940 ✔ / 0 ✘ (the 930 previous lines unchanged, plus the 5 new tests on each engine). See `TESTDOX.txt`.
- **Plain-run MariaDB `RouteMatrixTest` errors:** all 3 failed at login with the php -S `404 … /?_route=admin was not found` page. That is the same pre-existing WP2 `AppServer` orphaned-worker port reuse classified in §L.1 and §N; it is unrelated to plan deletion. 40172 + 3047 (the class) = 43219. The class passed on both engines in the testdox run and on MariaDB in the re-run.
- The re-run of the plain suite was stopped at the owner's instruction ("leave the heavy tests"). Its MySQL leg is therefore incomplete and is not counted.
- **Route / security matrix:** `RouteMatrixTest` (3 tests, 3047 assertions) passed on both engines in the testdox run. The `WP2_EVIDENCE=1` row comparison (a separate heavy run) was **not repeated** in this round at the owner's instruction. Fix round 2 changes no route, `RouteMap` or capability; the last row comparison (on `e7d2e7a3`, §L.1) was byte-identical to the WP2 evidence.
- **PHP 8.2.34 lint on `9978311a`:** 355 tracked non-vendor PHP files (+1 `PlanDeleteRaceTest`), 355 pass, 0 fail.
- **Manifest:** 42/42 OK.
- **Migrations:** R0001–R0006 only; `system/reseller/` is unchanged against `d4ba4514`; R0006 `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`.
- **Source guards:** `PricingSourceGuardTest` (8) and the accepted WP3A `SourceGuardTest` are green in every complete leg.
- **Secret sweep** over `ae636d14..HEAD`: clean; no credential-like content.
- **Shared host** (08:46–10:22 UTC, 496 host samples, 82 AVAIA samples):
  - host load1 max 10.29, mean 5.18; CPU max 41 %, mean 20 %; MemAvailable min 41648 MiB; swap 0;
  - test peaks: MySQL 193 % / 762 MiB, PHP runner 118 % / 550 MiB, MariaDB 139 % / 468 MiB;
  - the 18 AVAIA containers recorded before were present and unchanged throughout (`avaia-v3-minio-init` stayed `Exited (0)` as before). A new AVAIA container, `avaia-v3-prod-publish-worker`, appeared during the window. That is owner-driven AVAIA activity, not PHPNuxBill;
  - no `nuxbill` container, network or volume left behind; no global prune, no daemon change, no AVAIA access.

### L.1 Owner fix round 1: deterministic assertion count (fix commit `e7d2e7a3`)

**Finding (owner review of `2c543a63`).** Per-engine assertion totals were not stable (43102 vs 43108 at 463 tests), so the candidate did not report one reproducible total.

**Root cause (test only).** In `PricingConcurrencyTest::testNearMaximumRecipientBalanceRace`, each of the 8 rounds asserted differently depending on which approved outcome won:
- settlement-first: 2 branch assertions;
- funding-first: 4 branch assertions, plus one hidden assertion: the `ctx('ad')` helper used by the administrative fix-up calls `assertCount` internally, and it ran only on that branch.

Each funding-first round therefore added 3 assertions (`FinanceTestCase::ctx` makes exactly one `assertCount`). The observed 43108 − 43102 = 6 matches two extra funding-first rounds. No product code was involved. A first fix candidate still varied (4239–4243 for the class) because of the hidden `ctx()` assertion. It was never committed; the committed fix builds the context in every round.

**Fix (`e7d2e7a3`, one file: `tests/Integration/Wp3b/PricingConcurrencyTest.php`; no runtime, support, harness, migration or accepted-test change).**
- Every race round builds one outcome record: successes, refusal reasons, commissions, reservation status, leaf balance.
- It asserts that record equals the expected record of the winner (`NEAR_MAX_OUTCOMES`: settlement-first or funding-first).
- It then drives both orders to the same final state (`COMMITTED`, one 3.00 commission, leaf 85.00, exactly one settlement). The assertions are identical whatever the winner.
- New deterministic tests run each order without a race: `testNearMaximumSettlementFirst` and `testNearMaximumFundingFirst`. This adds 2 tests per engine.
- The retail-versus-raise race asserts one saleability record (`RETAIL_BELOW_WHOLESALE` or none) instead of branch-dependent checks.
- `WP3B_EVIDENCE=1` (optional, off in `run-tests.sh`) prints the winner distribution to STDERR. It makes no assertion.
- No product defect was found; the race still yields only the two approved outcomes.

**Repeated runs of the affected suite** (`--filter PricingConcurrencyTest`, 21 tests, 8 race rounds each, `WP3B_EVIDENCE=1`):

| Engine | Runs | Result every run | Race winners per run (settlement-first / funding-first) |
|---|---|---|---|
| MariaDB 10.11.19 | 5 | **OK 21 / 4247** | 6/2, 6/2, 5/3, 7/1, 6/2 |
| MySQL 8.0.46 | 5 | **OK 21 / 4247** | 4/4, 7/1, 7/1, 2/6, 4/4 |

Both outcomes occurred in every run, and the assertion total did not move.

**Full canonical validation on `e7d2e7a3`** (PHP 8.2.34, PHPUnit 11.5.56):

| Run (UTC) | MariaDB 10.11.19 | MySQL 8.0.46 | Registry | Exit |
|---|---|---|---|---|
| `run-tests.sh` (05:52–06:34, 42m10s) | **OK 465 / 43159** | **OK 465 / 43159** | matches | 0 |
| `run-tests.sh --testdox` run 1 (06:34–07:18, 44m00s) | **OK 465 / 43159** | 458 OK + 7 errors in `Http\StaffManagementTest` (harness port reuse, below): 465 / 43087 | matches | 1 |
| `run-tests.sh --testdox` run 2 (07:30–08:07, 37m09s) | **OK 465 / 43159** | **OK 465 / 43159** | matches | 0 |

- **Final stable totals per engine: 465 tests / 43159 assertions** on both engines, in every clean run.
  - 465 = 371 accepted + 94 WP3B (34 unit + 60 integration); the original candidate's 92 WP3B tests plus the 2 deterministic ordering tests.
- **Testdox (run 2):** 930 ✔ / 0 ✘. These are the 926 lines of the original candidate, all unchanged, plus `Near maximum settlement first` and `Near maximum funding first` on each engine. See `TESTDOX.txt`.
- An earlier plain run on `e7d2e7a3` was cut off by the operator's tool time limit before it finished. It is not counted, and nothing in it failed.

**Testdox run 1, MySQL: `StaffManagementTest` (7 errors). Classification: ENVIRONMENT / test-harness, pre-existing WP2 harness flake, not a product or WP3B regression.**
- Every test failed at setup login with `no CSRF token on admin: 404 … The requested resource /?_route=admin was not found`. That is the built-in `php -S` "file not found" page; a docroot that holds the app never serves it for `/`. The run lost exactly the class's 72 assertions (43159 − 43087).
- **Cause, reproduced with the unmodified harness:**
  - `tests/Support/AppServer.php` (WP2, unchanged by WP3B) starts `php -S` with `PHP_CLI_SERVER_WORKERS=4` on a random port in 20000–29999. `stop()` uses `proc_terminate`, which stops only the master.
  - The forked workers keep the port and answer with that 404 page after `destroy()` removes the docroot.
  - `start()` accepts any listener (`fsockopen`). So a later class that draws the same port talks to the orphan.
  - A scratch script using the real `AppServer` showed:
    - live: 200 app page;
    - after `destroy()`: the port is still accepting and answers `404 … not found`;
    - a second server on that port: `start()` succeeds and the request gets the same 404.
  - Each engine leg starts 23 HTTP test classes, so the chance of a port collision is roughly 2.5% per leg.
- **Rerun of the class:** MySQL 5/5 and MariaDB 2/2 `OK (7 tests, 72 assertions)`. Run 2 above is fully clean.
- Not patched: this is accepted WP2 harness code outside this fix's scope. It is listed in §N for an owner-approved harness fix (stop the server's process group or verify the server's identity at start).
- **Route / security matrix on `e7d2e7a3`:** 1442 requests, 1164 denials (all 403), 278 allowed (243 × 200, 35 × 302). The rows are byte-identical on MariaDB and MySQL and to the accepted WP2 evidence rows.
- **PHP 8.2.34 lint on `e7d2e7a3`:** 354 tracked non-vendor PHP files, 354 pass, 0 fail. The file list is the same as the original candidate's.
- **Manifest:** 42/42 OK.
- **Migrations:** R0001–R0006 only; `system/reseller/` is unchanged against `d4ba4514`; R0006 `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`.
- **Source guards:** `PricingSourceGuardTest` and the accepted WP3A `SourceGuardTest` are green in every run above.
- **Secret sweep** over `2c543a63..HEAD`: no new credential-like content. The fix changes test logic and this evidence only.
- **`CliOnlyTest` connection-counter race:** it did not occur in this round. It stays classified as an environment timing flake and the test is not weakened.

### L.0 Original candidate: route matrix, lint, manifest, migrations, secrets (still valid)
- **Route / security matrix** (`RouteMatrixTest`, `WP2_EVIDENCE=1`): **1442 requests, 1164 denials, 278 allowed**. Every denial is a 403. The rows are identical on MariaDB and MySQL and identical to `owner-review/WP2/ROUTE_MATRIX_EVIDENCE.txt`, so WP3B adds no route and changes no decision.
- **PHP lint:** 354 tracked non-vendor PHP files (incl. the 43 WP3B changed / added files) are clean on PHP 8.2.34 (authoritative) and PHP 8.3.35 (observation).
- **Manifest:** `sha256sum --strict -c MANIFEST.sha256` gives 42/42 OK.
- **Migrations:** R0001–R0006 only, byte-identical (R0006 `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`). No R0007, no trigger.
- **Secret sweep** over the full `d4ba4514..HEAD` diff (incl. docs) found:
  - no private-key, AWS, GitHub, Slack, Stripe-live, Google, `sk-`, Telegram-bot or JWT pattern;
  - no credential-like literal assignment.

  The only credential-shaped text is the deliberately fake `password=hunter2 … token: abc` in `SettlementFailureTest`, which proves redaction. Test database passwords are random runtime values only.

## M. Shared host (AVAIA)
- PHPNuxBill Docker work used only the `nuxbill-reseller-test` compose project plus one-shot `nuxbill-reseller-test-*` lint / composer containers. There was no global prune, no daemon change and no AVAIA access.
- A passive sampler logged host load, CPU, memory, swap, Docker stats and AVAIA container state during the three full runs (23:26–01:14 UTC, 557 samples):
- host load1 max 6.37, mean 3.98 on 12 vCPU;
- host CPU max 27 %, mean 16 %;
- MemAvailable min 42927 MiB; swap 0;
- test container peaks: MySQL 199 % / 767 MiB, PHP runner 105 % / 513 MiB, MariaDB 111 % / 459 MiB;
- disk free 166641 → 166596 MiB (tmpfs databases);
- the same 18 AVAIA containers before and after, no unhealthy or restarting state logged;
- no `nuxbill` container, network or volume left behind.

**Fix round 1** (05:52–08:08 UTC, 689 host samples, 140 AVAIA samples; repeat runs, plain, two testdox runs, route matrix and lint):
- host load1 max 9.82, mean 5.26;
- CPU max 43 %, mean 18 %;
- MemAvailable min 34978 MiB; swap 0;
- test container peaks: MySQL 233 % / 766 MiB, PHP runner 173 % / 856 MiB, MariaDB 165 % / 460 MiB;
- AVAIA: the same 18 containers in every sample and after the round, all `Up`, except the one-shot `avaia-v3-minio-init` (`Exited (0) 11 days ago`, the same as before);
- no `nuxbill` container, network or volume left behind;
- no global prune, no daemon change, no AVAIA access.

## N. Known residuals (not release acceptance)
1. **Recipient overflow at commit:** a recipient balance can reach the money-range limit between reserve and commit. This is the only post-provisioning capacity refusal; it is atomic and typed (`SettlementFailure` CAPACITY). WP5A reconciliation and an administrative fix resolve it.
2. **Hierarchy moves** (WP2 `moveSubtree`, unchanged) can create wholesale-chain violations. They fail closed (`PRICING_CHAIN_INVALID`) and are listed by `validateTree`.
3. **Global tree-lock serialisation** covers pricing mutations, resolution and quotes. WP9 should measure throughput.
4. **Legacy customer-charge composition** (per-customer `Invoice` attribute, bills, float tax, coupons including the unvalidated client `discount` in `order.php`) is not part of reseller retail; WP5B owns it. `Package.php:69` can zero a legacy price (unchanged, pinned); legacy-default resellers then fail closed.
5. **Immutability is application-level** for snapshots and configuration. WP9 must red-team the tamper surfaces; WP10 must deliver least-privilege database accounts.
6. **Refund / reversal** of a settled activation (debit plus commissions) is not designed here (WP5A / WP5B).
7. **Old ancestors after a move** keep historical commission rows whose counterparty may be outside their current scope (historical truth).
8. **Zero-cost activation** is deferred policy (F-3). **Balance-plan resale** is excluded (F-4).
9. **`CliOnlyTest` connection-counter race:** a pre-existing test-harness timing race (§L), unchanged by WP3B.
10. **Plan delete holds the tree lock during the device call** (fix round 2). A slow router / RADIUS removal delays tree-locked operations. Any that wait longer than 10 s fail closed with `LOCK_TIMEOUT` and can be retried; none becomes inconsistent.
11. **Device ahead of the database after an infrastructure failure:** a lost database connection or server crash between a successful device removal and the commit can still leave the device ahead of the database. This is the legacy device-first ordering; it is not a reference race (those are now impossible) and is not compensated (owner rule).
12. **`AppServer` orphaned `php -S` workers** (WP2 harness, §L.1): a stopped app server's workers keep its port, and a later HTTP test class that draws the same random port fails at login with a 404. This is rare (about 2.5% per engine leg) and environment / harness only. The fix (kill the process group, or verify server identity at start) needs owner approval, because it changes accepted harness code.

## O. Binding input for WP5A
See `docs/WP3B_PRICING_MODEL.md` §9.

**Sequence:**
1. One tree-locked transaction: `resolveInTx`, then a single activation INSERT with `activationColumns()`, then `reserveInTx`.
2. Provisioning outside any transaction.
3. `commitInTx` as the first locking work of the state-transition transaction. On `SettlementFailure` (or `SettlementFailure::classify`) → retry / RECONCILIATION_REQUIRED.

**Plan-reference serialization (fix round 2):** WP5A must insert activations, which create the `plan_id` / `previous_plan_id` references, only inside the tree-locked transaction that resolves the plan. Plan deletion holds that same lock from its final check through the device removal and the delete commit (model §9.4, §11). WP5A must add a deterministic race test, activation vs plan delete, mirroring `PlanDeleteRaceTest`.

**WP5A must never:**
- create a `tbl_plans` reference outside the reseller tree lock;
- write ledger, wallet or pricing tables;
- update snapshot columns;
- create an activation without a snapshot.

## P. Git, push and environment status
- Working tree: clean after the evidence commit (`tests/vendor/` is ignored).
- Push: **only `wp3b/pricing-settlement`** to `origin`, normal non-force push, authorised by the owner for each fix round. Local HEAD = `origin/wp3b/pricing-settlement` (SHA in the final response). PR / merge / tag / deploy: **none**.
- `feat/reseller-system-v1` = `d4ba4514`, `main` = `500d3a2d…` (unchanged).
- Production: not touched. WP4 / WP5A / WP5B: not started.

WP3B remains **IMPLEMENTED_NOT_ACCEPTED** pending owner review. STOPPED_WAITING_OWNER_REVIEW.
