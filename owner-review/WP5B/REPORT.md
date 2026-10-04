# WP5B — Legacy Service Entrypoint Closure — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED** — fix round 2 applied ("OWNER FINAL REVIEW — WP5B FIX REQUIRED ROUND 2": blockers 1–5 closed, blocker 5B open on `5d76a09b`), waiting for the owner's final acceptance. Final state: `WP5B_FIX_ROUND_2_COMPLETE_PUSHED_WAITING_OWNER_FINAL_ACCEPTANCE`.

Model: `docs/WP5B_LEGACY_CLOSURE_MODEL.md`. Test evidence: `owner-review/WP5B/TESTDOX.txt`; fix round 1 defect reproduction on the reviewed candidate: `owner-review/WP5B/FIX_ROUND_1_REPRODUCTION.txt`; fix round 2 reproduction on `5d76a09b`: `owner-review/WP5B/FIX_ROUND_2_REPRODUCTION.txt`. Plan: `WP5B_PLAN.md` revision 2, accepted by "OWNER FINAL DECISION — WP5B PLAN ACCEPTED / IMPLEMENTATION GO" (with the `Balance.php` one-row correction and the one-transaction E13 cleanup), plus "OWNER DECISION — R0007 RUNNER EXTENSION APPROVED" after the stop on condition E.

## A. Ancestry / branch / heads

| Item | Value |
|---|---|
| Base | `feat/reseller-system-v1` = `427799f53a570846659796b13e0fbad82175eb6e` (WP3B + WP4 + WP5A integrated, WP5A acceptance recorded) |
| Branch | `wp5b/legacy-entrypoint-closure` from `427799f5` (nothing reset, no squash) |
| Reviewed candidate (fix round 1) | `bb15ffc8d1ff9234c4bcebed5dbf2a8bb9405747`, evidence `0d9d6eb7471bdbbffbf779583262ca27cee37abe` |
| Fix round 1 implementation / evidence | `5d76a09be4f03e99b21efeff67bc8166824a437f` / `152b43cc` (owner final review: blockers 1–5 closed, blocker 5B open) |
| Implementation HEAD (validated, fix round 2) | `cdaebba22e163e2fc5e68b9ce111e34b3f3f3538` |
| Evidence HEAD | the evidence commit `docs(wp5b): fix round 2 — report and testdox` (SHA in the final response) |
| Push | only `wp5b/legacy-entrypoint-closure`, normal, non-force. No PR, merge, tag or deploy; `main` untouched. |

## B. Commits (author `ALI HENDI <ali.hahuba3@gmail.com>`, `Co-Authored-By` trailer)

| Commit | Content |
|---|---|
| `975413d3` | WP5B slice 1: R0007 activation reversal states, strict redefines primitive, readiness |
| `3f422e67` | WP5B slice 2: reversal context, ActivationReversalService, ledger reversal |
| `bb55d3f0` | WP5B slice 3: tree serialization, bounded legacy re-provision, device default deny |
| `08a670f0` | WP5B slice 4: single atomic voucher redemption, E13 per-username lock, CLAIMED protection |
| `96202960` | WP5B slice 5: payment gateway hardening (unassigned customers, OD-2) |
| `5d7c92f2` | WP5B slice 6: LegacyCustomerBalanceService (unassigned customers only) |
| `f261c7f8` | WP5B slice 7: Balance.php atomic relative updates (owner-approved pin change) |
| `59f3fa90` | WP5B slice 8: cron hardening |
| `a160c5be` | WP5B slice 9: controller and route closure |
| `1a443943` | WP5B slice 10: drift report and operator commands |
| `b3298acd` | WP5B slice 11: focused race tests and source guards |
| `bb15ffc8` | WP5B slice 10 fix: keep the reconcile.php CLI guard first |
| `0d9d6eb7` | docs(wp5b): model, owner-review report and testdox (first candidate evidence) |
| `3a0a7210` | fix round 1, blocker 1: serialize HELD balance operations with the operator resolution |
| `dadaa11a` | fix round 1, blocker 2: no DB transaction open across the reversal RADIUS read |
| `e8d5d51a` | fix round 1, blocker 3: re-provision tracks external progress per step |
| `0bb83fad` | fix round 1, blocker 4: MikroTik rename bug and the expiry path vs the grant guard |
| `5d76a09b` | fix round 1, blocker 5: coupons are server-authoritative at buy time |
| `152b43cc` | docs(wp5b): fix round 1 — model, report and testdox |
| `cdaebba2` | fix round 2, blocker 5B: buy-time coupon price basis = the gateway preview's legacy rule (current `Invoice` override, else plan price) |

The commits are reviewable units in plan order; the canonical evidence (§F) is for the whole implementation HEAD. Note: `Wp5bSourceGuardTest` was committed with slice 4 already containing the `Balance.php` guard that only becomes true with slice 7, so slices 4–6 are not individually green on that one guard.

## C2. Fix round 2 (owner final review of `5d76a09b`)

Blockers 1–5 closed by the owner. **Blocker 5B:** the buy-time coupon re-validation used the raw plan price, while the gateway preview (legacy rule, `order.php` gateway action) uses the customer's `Invoice` attribute when set (it overrides the plan price; `User::getAttribute` without an id resolves the logged-in customer). A percent coupon, a minimum order or a maximum discount could therefore be judged on a different price at preview and at buy.

**Fix** (one additive commit `cdaebba2`, `system/controllers/order.php` only): before `CouponAuthority::buy`, the coupon price basis is read again from the server — the buying customer's **current** `Invoice` attribute when non-empty, else `$plan['price']` — and passed as the price to validate and derive the discount from. Nothing else changed: the retail price formula, tax, bills (`add_cost`), the `Invoice` semantics and the payment-row amount composition (plan price + bills + tax − discount) are unchanged; no schema. No stop condition: the `Invoice` override is applied as the legacy preview applies it (not restricted to Period plans), and bills / tax do not join the coupon basis.

| Required test | `PaymentHardeningTest` |
|---|---|
| 1 percent coupon, `Invoice` lower than the plan | `testPercentCouponUsesTheInvoiceOverrideAsItsBasis` (Invoice 500, 10 % → discount 50, payment 950) |
| 2 `Invoice` higher than the plan + minimum order | `testMinimumOrderIsCheckedAgainstTheInvoiceOverride` (Invoice 2000, min order 1500: valid at preview and buy, one use, payment 900) |
| 3 `Invoice` changed after preview | `testBuyReadsTheCurrentInvoiceOverride` (500 at preview, 800 at buy → discount 80, payment 920) |
| 4 no `Invoice` | `testWithoutAnOverrideThePercentCouponUsesThePlanPrice` (payment 900) |
| 5 stale / concurrent | the fix round 1 tests (disabled / expired / exhausted between preview and buy; concurrent last use) unchanged and green |

Reproduction (`FIX_ROUND_2_REPRODUCTION.txt`): the new tests against the `5d76a09b` runtime (MySQL) → **3 failures** (900 vs 950; coupon refused although the current override meets the minimum order; 900 vs 920); the no-`Invoice` case and all earlier coupon tests passed. After the fix: focused `PaymentHardeningTest|LegacyEntrypointClosureTest|Wp5bSourceGuardTest` OK 28 / 621 on both engines; wp5b / wp5a / wp4 integration + wp2-http OK 293 on both engines; then the canonical run (§F). No accepted test changed.

## C1. Fix round 1 (owner code review of `bb15ffc8`)

Additive commits on top of the reviewed candidate (no amend, no squash). Every blocker got tests first; the new tests were run against the reviewed candidate's runtime (`git archive bb15ffc8` + the new tests, MySQL): **12 failures + 1 error reproduce the defects** (refund / completed resolution completing during a live dispatch; finalizer reporting a fabricated state; `REPROVISION_FAILED` + DB revert after a partial external apply, twice; Hotspot rename not applied; the expiry path refused with `RESELLER_OWNED_REFUSED`; the private primitive missing; disabled / expired / exhausted coupons still discounted; no RADIUS-read instrumentation; `CouponAuthority` absent). The tests that must pass on both (abandoned HELD resolution, first-operation FAILED revert, a valid coupon) passed on the candidate too.

| Blocker | Fix | Tests |
|---|---|---|
| 1 HELD balance resolution race | operation-level advisory lock on the operation key: taken before the HELD row, held through the HELD commit, the grant and the classification (no DB transaction across the grant); `balance-resolve` takes the same lock (≤ 10 s, then `BALANCE_OPERATION_BUSY`), re-reads; checked affected rows, persisted state reported when another transition won | real workers with a barrier inside the dispatch: refund / completed vs live dispatch (operator waits, one final state, never service + refund); abandoned HELD resolvable after its lock is gone; finalizer losing its update reports the persisted state |
| 2 RADIUS read inside the reversal transaction | `start` = `TreeSerialization::hold`: short DB check transaction → RADIUS read with no transaction → short transaction re-lock + re-check + COMPLETED → REVERSAL_PENDING | instrumentation: `inTransaction() === false` on the application and RADIUS connections at every reversal RADIUS read (phase 0, start, 2b) and restore; reversal races green |
| 3 re-provision partial external success | per-target / per-step progress; a confirmed FAILED after any applied step is promoted to UNKNOWN → `REPROVISION_UNCONFIRMED` (no revert, no retry); first non-success stops further steps | target 1 applied / target 2 FAILED; rename applied / add FAILED; first operation FAILED still reverted; UNKNOWN unchanged |
| 4 MikroTik | 4A Hotspot rename uses the found `$id`; 4B private `provisionCustomer()` in Hotspot / PPPoE / VPN, public `add_customer` guard → primitive, `remove_customer` expiry → primitive directly (no bypass flag) | fake RouterOS client in the app: the existing Hotspot user renamed (same id, old name gone); expiry of a reseller-owned customer applies the expired plan on all three devices while the public grant stays refused |
| 5 coupon authority | `CouponAuthority`: preview consumes nothing and keeps only the code; buy re-fetches `FOR UPDATE`, re-validates, re-derives, consumes one use conditionally in the payment-row transaction | valid coupon consumed once at buy; disabled / expired / exhausted between preview and buy → no stale discount, no use consumed; concurrent last use → at most one |

No stop condition: no schema change, `Package.php` / `Tx.php` / `ServiceGate.php` / R0001–R0006 unchanged, no accepted test changed in this round (only WP5B tests added / extended).

## C. Stop condition E and the owner decision

Slice 1 stopped (condition E): the strict runner could not express R0007 (the R0003 contract would drift; `table_parts` treats an existing column as a collision) and two accepted WP1 tests stated contracts R0007 changes. The owner approved the `redefines` primitive in the pinned `MigrationDefinition.php` / `Runner.php`, their re-pins, and the listed migration-test contract changes. Implemented exactly as approved (model §3), with the dedicated tests (`R0007ReversalStatesMigrationTest`, `MigrationRedefinesTest`). Slice 1 was green on both engines before its commit (182 tests / 28 539 assertions each).

No other stop condition was hit:
- A `Package.php`: unchanged. B schema: only R0007. C `Tx.php`: unchanged. D R0001–R0006: byte-identical (hashes in §F).
- E accepted tests changed only within the approved / planned list (§E). F `tbl_payment_gateway` sufficed (status 5 HELD, key in `gateway_trx_id`, serialized by the customer row lock).
- G no reseller retail, payment or auto-renew commerce was enabled.

## D. Files (`git diff --name-status 427799f5..bb15ffc8`: 89 files; 89 files changed, 5425 insertions(+), 447 deletions(-))

**Fix round 2** (`git diff 5d76a09b..cdaebba2`: 2 files changed, 68 insertions(+), 1 deletion(-)):
- `system/controllers/order.php` (modified)
- `tests/Integration/Wp5b/PaymentHardeningTest.php` (modified)

**Fix round 1** (`git diff bb15ffc8..5d76a09b -- system tests`: 15 files changed, 826 insertions(+), 185 deletions(-)):
- `system/autoload/Reseller/Activation/ActivationFaults.php` (modified)
- `system/autoload/Reseller/Activation/ActivationReversalService.php` (modified)
- `system/autoload/Reseller/Domain/CouponAuthority.php` (added)
- `system/autoload/Reseller/Domain/DomainError.php` (modified)
- `system/autoload/Reseller/Domain/LegacyCustomerBalanceService.php` (modified)
- `system/autoload/Reseller/Domain/LegacyReprovisionService.php` (modified)
- `system/controllers/order.php` (modified)
- `system/devices/MikrotikHotspot.php` (modified)
- `system/devices/MikrotikPppoe.php` (modified)
- `system/devices/MikrotikVpn.php` (modified)
- `tests/Integration/Wp5b/ActivationReversalTest.php` (modified)
- `tests/Integration/Wp5b/LegacyCustomerBalanceTest.php` (modified)
- `tests/Integration/Wp5b/LegacyReprovisionTest.php` (modified)
- `tests/Integration/Wp5b/MikrotikDeviceTest.php` (added)
- `tests/Integration/Wp5b/PaymentHardeningTest.php` (modified)

**Through the first candidate:**

**Runtime, added:** `Activation/{ActivationReversalService, RestorePlan, DriftReport}`, `Domain/{TreeSerialization, LegacyReprovisionService, VoucherRedemptionService, RedemptionSource, RedemptionTarget, PaymentGatewayResolver, LegacyCustomerBalanceService, AutoRenewPolicy, ServiceAudit}`, `Finance/PostedReversal`, `system/reseller/migrations/R0007_activation_reversal_states.php`.

**Runtime, modified:**
- Activation: `ActivationState` (17 / 26), `ActivationStore` (reversal / drift queries), `LegacyBridge` (reversal context, `reverse()`), `ActivationService` (COMPLETED metadata), `ReconciliationService` (uses `RestorePlan`, behaviour unchanged), `ActivationFaults` (reversal points).
- Finance: `LedgerService` (`activationReversalLegs`, `assertActivationReversible`, `postActivationReversal`).
- Security: `Capability`, `RoleCapabilityMap` (`activation.reverse`), **`RouteMap` (pinned, OD-15, re-pinned)**.
- Domain: `DomainError` (reasons), `SchemaReadiness` (R0007).
- Migration runner (**pinned, owner-approved, re-pinned**): `MigrationDefinition.php`, `Runner.php`.
- **`Balance.php` (pinned, owner-approved, re-pinned)**.
- Controllers: `accounts`, `activation`, `callback`, `customers`, `home`, `login`, `order`, `plan`, `voucher`; entrypoints `radius.php`, `system/cron.php`, `system/.htaccess`, `init.php` (CSPRNG voucher digits); `system/reseller/reconcile.php`.
- Devices: `Radius`, `RadiusRest`, `MikrotikHotspot`, `MikrotikPppoe`, `MikrotikVpn` (default deny, first statement of each grant method).
- Templates: activation view (reverse form), voucher list (CLAIMED), order view / unpaid widget (POST check / cancel), payment history (HELD 5), purchase / gift / transfer / admin recharge forms (request nonce).

**Not changed:** `Package.php`, `Tx.php`, `ServiceGate.php`, R0001–R0006, `install/radius.sql`, the FreeRADIUS configuration, `dev/test`, the spec pack and its MANIFEST.

## E. Accepted tests changed (all within the approved / planned lists)

| Test | Change | Authority |
|---|---|---|
| `Unit/MigrationDefinitionsTest` | ids R0001–R0007; "after R0005 only CREATE TABLE" except R0007, which may only ALTER … MODIFY the three R0003 state columns via `redefines` (no table, no table part, no DML, no legacy table) | R0007 decision |
| `Integration/ForwardMigrationTest` | T3 through later APPLIED redefinitions + R0003 bytes pinned separately; registry 7 | R0007 decision |
| `Integration/RollbackTest` | partial rollback statuses include R0007 | R0007 decision |
| `Unit/Wp3a/SourceGuardTest` | pins: Runner, MigrationDefinition, R0007, RouteMap, Balance; migration list R0001–R0007; `post*` list + `postActivationReversal` | R0007 decision, OD-15, §14 approval, plan §9 |
| `Integration/Wp3a/LedgerTest` | public API list (+ `activationReversalLegs`, `assertActivationReversible`, `postActivationReversal`) | plan §9 |
| `Integration/Wp3a/FinanceSchemaReadinessTest`, `Integration/Wp3b/PricingSchemaReadinessTest` | `EXPECTED` R0001–R0007 | plan §9 |
| `Unit/Wp5a/ActivationStateAndPeriodTest` | 17 states, 26 edges, FINAL / TERMINAL | plan §9 |
| `Unit/Wp5a/ActivationCapabilityTest` | `activation.reverse` row | plan §9 |
| `Http/ServiceGateTest` | E1 web → activation redirect; E19 / E20 / E21 through `LegacyReprovisionService`; cron AUTO_RENEW_UNAVAILABLE; every refusal row unchanged | plan §9 |
| test support | `ActivationTestCase` (reversal invariants, `customersChanged`), `FinanceInvariants` (activation reversals), `finance_worker.php` (race ops), `AppServer::execStart` (PHP diagnostics to the probe log, as `exec()`), `phpunit.xml` (wp5b suites) | plan §9 |

`PricingSourceGuardTest` and `ActivationSourceGuardTest` needed no change (the reversal / drift queries live in `ActivationStore`).

## F. Validation (fix round 2, `cdaebba2`)

The `5d76a09b` validation is NOT reused (the payment-critical code changed). One new canonical run on the dev host at `cdaebba22e163e2fc5e68b9ce111e34b3f3f3538`, isolated compose project `nuxbill-reseller-test` only (PHP 8.2.34, MariaDB 10.11.19, MySQL 8.0.46, FreeRADIUS 3.2.8 test containers), 2026-10-04T09:41Z – 11:56Z.

| Check | Result |
|---|---|
| `dev/test/run-tests.sh` (plain) | MariaDB **OK (778 tests, 50 026 assertions)**; MySQL **OK (778 tests, 50 026 assertions)**; exit 0 (65 m 32 s) |
| Variant registry reproducibility | matches freshly built fixtures |
| `dev/test/run-tests.sh --testdox` | MariaDB OK 778 (50 023 assertions) / MySQL OK 778 (50 026); **1556 ✔, 0 ✘** (`TESTDOX.txt`); exit 0 (63 m 28 s). (Race tests take one of two valid branches, hence the small assertion difference.) |
| Migration / R0007 | `R0007ReversalStatesMigrationTest`, `MigrationRedefinesTest` and the WP1 migration suites green on both engines |
| WP4 FreeRADIUS contract, WP5A regression, WP3A / WP3B guards, WP5B source guards | included, all green |
| Real-worker concurrency | all WP5B races incl. the fix round 1 races (balance dispatch vs resolve, coupon last use) |
| Route matrix | 1470 / 1183 / 287, identical on both engines; 0 rows removed vs the accepted 1442, 28 added (23 WP5A + 5 `activation/reverse`); unchanged from fix round 1 (§G) |
| PHP 8.2 lint | 439 / 439 |
| Manifest | 42 / 42 |
| R0001–R0006 | byte-identical (`a0b52f7f…`, `38bceb1f…`, `60e63156…`, `7f3bc5bc…`, `b69135ea…`, `f1a76119…`); R0007 `b7edce53…` |
| Untouched since `427799f5` | `Package.php`, `Tx.php`, `ServiceGate.php`, `install/radius.sql` |
| Secret sweep | `git diff 152b43cc..cdaebba2` clean |
| Shared host | observe-only sampler; max load 14.3, min MemAvailable 38 626 MiB; the AVAIA container set (23 names, Up / Exited states) is identical before and after; no AVAIA container was touched by this session (`avaia-v3-prod-api` and `avaia-v3-prod-web` were recreated at 11:13Z / 11:37Z by AVAIA's own deployment during the run, both healthy); test containers removed |

### Fix round 1 validation (`5d76a09b`, history)

One canonical run on the dev host, isolated compose project `nuxbill-reseller-test` only (PHP 8.2.34, MariaDB 10.11.19, MySQL 8.0.46, FreeRADIUS 3.2.8 test containers), 2026-10-04T05:02Z – 07:22Z. Focused suites before it: wp5b / wp5a / wp4 integration + wp2-http, 293 / 293 on both engines.

| Check | Result |
|---|---|
| `dev/test/run-tests.sh` (plain) | MariaDB **OK (774 tests, 50 015 assertions)**; MySQL **OK (774 tests, 50 016 assertions)**; exit 0 (63 m 14 s). (One race test takes one of two valid branches, hence ±1 assertion.) |
| Variant registry reproducibility | matches freshly built fixtures |
| `dev/test/run-tests.sh --testdox` | MariaDB OK 774 / MySQL OK 774; **1548 ✔, 0 ✘** (`TESTDOX.txt` of `152b43cc`, superseded); exit 0 |
| Migration / R0007 | `R0007ReversalStatesMigrationTest`, `MigrationRedefinesTest` and the WP1 migration suites green on both engines |
| WP4 FreeRADIUS contract, WP5A regression, WP3A / WP3B guards, WP5B source guards | included, all green |
| Real-worker concurrency | the round-1 races (balance dispatch vs resolve, coupon last use) plus all earlier WP5B races |
| Route matrix | 1470 / 1183 / 287, identical on both engines; unchanged from the first candidate (§G) |
| PHP 8.2 lint | 439 / 439 |
| Manifest | 42 / 42 |
| R0001–R0006 | byte-identical (`a0b52f7f…`, `38bceb1f…`, `60e63156…`, `7f3bc5bc…`, `b69135ea…`, `f1a76119…`); R0007 `b7edce53…` |
| Untouched since `427799f5` | `Package.php`, `Tx.php`, `ServiceGate.php`, `install/radius.sql` |
| Secret sweep | clean |
| Shared host | observe-only sampler; max load 10.2, min MemAvailable 38 569 MiB; no AVAIA container removed or changed by this session (two new `avaia-v3-prod-motion-card-worker-*` containers were created by AVAIA's own deployment at 06:58Z / 07:11Z during the run); test containers removed |

First candidate (`bb15ffc8`) evidence, for history: 758 / 758 per engine, testdox 1516 / 0, lint 437 / 437.

## G. Route matrix delta

Against the WP5A accepted matrix (1465 requests / 1178 denials / 287 allowed): all 1442 pre-WP5A rows and the 23 WP5A activation rows are unchanged; **+5 rows**, all denials: `POST activation/reverse` → 403 for Agent `A`, Sales `A1`, unenrolled Agent `E`, Agent `F` and Report `rp` (SuperAdmin / Admin reach the service; covered by `LegacyEntrypointClosureTest`). Totals 1470 / 1183 / 287. The order POST-only changes are customer-portal routes (outside the staff matrix) and are tested in `LegacyEntrypointClosureTest` (GET → 405).

## H. SEC findings closed

| Finding | Closure |
|---|---|
| SEC-13 gateway include without allowlist | `PaymentGatewayResolver` (order buy / check, callback) |
| SEC-14 posted discount reduces the gateway charge | ignored; only the server-validated coupon |
| SEC-15 negative balance transfer | strict positive minor-unit amount, locked atomic transfer |
| SEC-16 voucher + username overwrites an existing customer | existing customers refused untouched (OD-9) |
| SEC-23 check-then-set voucher redemption | atomic claim, single path |
| SEC-08 customer-balance minting / lost updates | hardened journal paths + `Balance.php` relative updates (R-11 closed) |
| cron web exposure | CLI-only first statement, `.htaccess` allow removed |
| order routes accept GET without CSRF; cancel could overwrite a paid row | POST-only + CSRF; conditional `status = 1` transitions |

## I. Residual risks

| ID | Risk |
|---|---|
| R-1 | Installed plugins with direct DB access can bypass application policy: detected (`reconcile.php drift`), not prevented (WP9 / WP10). |
| R-2 | No reseller self-purchase, online payment or auto-renew in V1 (OD-1 … OD-3). |
| R-3 | Pre-WP5B COMPLETED activations: `REVERSAL_CONTEXT_NOT_CAPTURED` (development databases only). |
| R-4 | REVERSAL_PENDING with RADIUS restored but the financial commit refused: no service and money settled until an operator acts. |
| R-5 | No DB uniqueness on legacy invoices, voucher codes or usernames (application level). |
| R-6 | Negative `REV-` rows appear in legacy revenue reports. |
| R-7 | Reversal only while the period is current and untouched. |
| R-8 | CLAIMED vouchers need operator review (drift report). |
| R-9 | Gateway callback authentication is the plugin's contract. |
| R-10 | A re-provision holds the global tree lock across a device call (opens wait ≤ 10 s, LOCK_TIMEOUT). |
| R-11 | **Closed** by the approved `Balance.php` change. |
| R-12 | `tbl_payment_gateway` key lookups are full scans under the customer lock (no index; WP9). |
| R-13 | HELD balance operations and `REPROVISION_UNCONFIRMED` customers need operator review (CLI; dashboards WP6B). |
| R-14 | The legacy cron amount is kept unchanged (owner decision pending if it should change). |
| new | E1 "balance" / E8 / E9 / E11 / E18 now refuse an Inactive payer or target (plan §7a.2 eligibility) where the legacy code did not check; `enable_balance != yes` refuses E1 "balance" (the legacy code debited anyway). |
| harness | The known AppServer port-reuse flake and the CliOnlyTest healthcheck race (§F). |

## J. Deployment notes

- Replace any URL-based cron with `php system/cron.php` in a crontab (WP10 runbook).
- Apply R0007 with `php system/reseller/migrate.php migrate` (preflight first); rollback is refused while any activation uses the new states.
- Operators: `php system/reseller/reconcile.php drift | balance-held | balance-resolve <id> <completed|refund> | expired-autorenew`.
