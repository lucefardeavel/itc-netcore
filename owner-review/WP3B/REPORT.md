# WP3B — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED.**
- WP3B is implemented on `wp3b/pricing-settlement`, branched from the accepted integration base `d4ba451437c3abf1b6349494b40b93257eaca553` (WP3A accepted at `c329145a`).
- It follows the owner-approved final plan (Revision 2) and the owner decisions OD-3, OD-4, OD-5, F-1 … F-8.
- It is waiting for owner review.

| Item | Value |
|---|---|
| Branch | `wp3b/pricing-settlement` (created from `d4ba4514`; nothing reset, rebased or rewritten) |
| Base | `d4ba451437c3abf1b6349494b40b93257eaca553` = `origin/feat/reseller-system-v1` |
| Ancestry | `d4ba4514` → `c329145a` (WP3A) → `1c66ec5b` (WP2) → `1f36bfa` (WP1) |
| Candidate HEAD | the docs commit `docs(wp3b): pricing model and owner-review report` (SHA given in the final response) |
| Schema | **no change**: no R0007, no trigger, R0001–R0006 byte-identical (R0006 `f1a76119…7abd`) |
| `feat/reseller-system-v1` / `main` | unchanged: `d4ba4514` / `500d3a2d15003efeed6e0aad639c13d08101c3b1` |
| Push / PR / merge / deploy | none |
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
| docs commit | `docs/WP3B_PRICING_MODEL.md`, WP2 permission-model rows, WP3A finance-model amendment note, this report, `TESTDOX.txt` |

All commits are authored by `ALI HENDI <ali.hahuba3@gmail.com>` with the `Co-Authored-By` trailer. Every commit is local only.

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
- **No money writes from pricing:** pricing files never write wallets / ledger / transfers / reservations / `tbl_plans` / `tbl_customers` and never call a finance writer. No reseller code writes `tbl_plans`. `PlanGuard` is SELECT-only.
- **No entrypoint:** no controller / API / plugin / cron / template references pricing or settlement classes, and no pricing route or `API_ALLOWED` key exists.
- **Plan delete:** the four delete paths are guarded before the hook, the device call and the delete; the FK race is caught; CRLF is preserved.
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
- **Near-maximum recipient balance:** exactly one of the commission and a funding fits. Commit-first → funding `STORAGE_RANGE_EXCEEDED`. Funding-first → `SettlementFailure` CAPACITY with no partial settlement, then an administrative fix leads to one settlement.
- **Pricing mutation races:**
  - parent child-set vs ancestor raise: exactly one commits and the wholesale tree is never invalid;
  - own retail vs upstream raise: never saleable below wholesale;
  - availability vs ancestor disable.
- **Reserve vs price change / hierarchy move:** the reserve commits before the change, or is `PRICING_SNAPSHOT_STALE`.
- **Every round:** no worker error, no `CONCURRENCY_RETRY` / `LOCK_TIMEOUT` / TRANSIENT, and whole-database invariants hold.
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

| Run | MariaDB 10.11.19 | MySQL 8.0.46 | Registry |
|---|---|---|---|
| Accepted WP3A baseline (for reference) | 371 / 24904 | 371 / 24904 | matches |
| WP3B plain run 1 (36m54s) | **OK 463 / 43108** | 462 OK + 1 known environment race (see below) | matches |
| WP3B `--testdox` (32m29s) | **OK 463 / 43108** | **OK 463 / 43108** | matches |
| WP3B plain re-run (30m25s) | **OK 463 / 43108** | **OK 463 / 43102** | matches |

- **Totals per engine:** 463 tests = the accepted 371 + 92 WP3B tests (34 unit + 58 integration). Assertions rose from 24904 to 43108. The new tests are expected; there is no regression.
- **Assertion totals vary by race order.** The new near-maximum concurrency case asserts 4 things on its fund-first branch and 2 on its commit-first branch (8 rounds). Totals such as 43102 vs 43108 therefore come from which worker wins, not from a lost test; the test count is always 463 and every test passes.
- **Testdox:** 926 ✔ / 0 ✘ (both engines). All 742 accepted testdox lines are present unchanged, plus 184 new lines. See `TESTDOX.txt`.
- **Known environment race:** `CliOnlyTest::testNewPhpFilesRefuseHttpExecution` failed once in plain run 1 on MySQL (`Connections` 201 → 202). It is the same race recorded in the dev-host baseline (there on MariaDB):
  - The test compares the server-global `Connections` counter, and the compose healthcheck connects on its own: measured on the idle test MySQL, 4 connections in 12 s (`mysqladmin ping` every 3 s); on MariaDB, 2 per healthcheck.
  - The isolated test passed 20/20 on each engine.
  - WP3B changed no file the test probes except one line of `tests/bootstrap.php` (support-class list), which keeps its CLI guard.
  - Classification: ENVIRONMENT / test-harness timing, not a product regression. Not patched (a test change would need owner approval).
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

## O. Binding input for WP5A
See `docs/WP3B_PRICING_MODEL.md` §9.

**Sequence:**
1. One tree-locked transaction: `resolveInTx`, then a single activation INSERT with `activationColumns()`, then `reserveInTx`.
2. Provisioning outside any transaction.
3. `commitInTx` as the first locking work of the state-transition transaction. On `SettlementFailure` (or `SettlementFailure::classify`) → retry / RECONCILIATION_REQUIRED.

**WP5A must never:**
- write ledger, wallet or pricing tables;
- update snapshot columns;
- create an activation without a snapshot.

## P. Git, push and environment status
- Working tree: clean after the docs commit (`tests/vendor/` is ignored).
- Push: **none**. PR / merge / tag / deploy: **none**.
- `feat/reseller-system-v1` = `d4ba4514`, `main` = `500d3a2d…` (unchanged).
- Production: not touched. WP4 / WP5A / WP5B: not started.

WP3B remains **IMPLEMENTED_NOT_ACCEPTED** pending owner review. STOPPED_WAITING_OWNER_REVIEW.
