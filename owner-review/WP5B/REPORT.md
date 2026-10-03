# WP5B — Legacy Service Entrypoint Closure — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED** (waiting for owner code review). Final state: `WP5B_IMPLEMENTATION_COMPLETE_PUSHED_WAITING_OWNER_CODE_REVIEW`.

Model: `docs/WP5B_LEGACY_CLOSURE_MODEL.md`. Test evidence: `owner-review/WP5B/TESTDOX.txt`. Plan: `WP5B_PLAN.md` revision 2, accepted by "OWNER FINAL DECISION — WP5B PLAN ACCEPTED / IMPLEMENTATION GO" (with the `Balance.php` one-row correction and the one-transaction E13 cleanup), plus "OWNER DECISION — R0007 RUNNER EXTENSION APPROVED" after the stop on condition E.

## A. Ancestry / branch / heads

| Item | Value |
|---|---|
| Base | `feat/reseller-system-v1` = `427799f53a570846659796b13e0fbad82175eb6e` (WP3B + WP4 + WP5A integrated, WP5A acceptance recorded) |
| Branch | `wp5b/legacy-entrypoint-closure` from `427799f5` (nothing reset, no squash) |
| Implementation HEAD (validated) | `bb15ffc8d1ff9234c4bcebed5dbf2a8bb9405747` |
| Evidence HEAD | the evidence commit `docs(wp5b): model, owner-review report and testdox` (SHA in the final response) |
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

The commits are reviewable units in plan order; the canonical evidence (§F) is for the whole implementation HEAD. Note: `Wp5bSourceGuardTest` was committed with slice 4 already containing the `Balance.php` guard that only becomes true with slice 7, so slices 4–6 are not individually green on that one guard.

## C. Stop condition E and the owner decision

Slice 1 stopped (condition E): the strict runner could not express R0007 (the R0003 contract would drift; `table_parts` treats an existing column as a collision) and two accepted WP1 tests stated contracts R0007 changes. The owner approved the `redefines` primitive in the pinned `MigrationDefinition.php` / `Runner.php`, their re-pins, and the listed migration-test contract changes. Implemented exactly as approved (model §3), with the dedicated tests (`R0007ReversalStatesMigrationTest`, `MigrationRedefinesTest`). Slice 1 was green on both engines before its commit (182 tests / 28 539 assertions each).

No other stop condition was hit:
- A `Package.php`: unchanged. B schema: only R0007. C `Tx.php`: unchanged. D R0001–R0006: byte-identical (hashes in §F).
- E accepted tests changed only within the approved / planned list (§E). F `tbl_payment_gateway` sufficed (status 5 HELD, key in `gateway_trx_id`, serialized by the customer row lock).
- G no reseller retail, payment or auto-renew commerce was enabled.

## D. Files (`git diff --name-status 427799f5..bb15ffc8`: 89 files; 89 files changed, 5425 insertions(+), 447 deletions(-))

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

## F. Validation (bb15ffc8)

Canonical runs on the dev host, isolated compose project `nuxbill-reseller-test` only (PHP 8.2.34, MariaDB 10.11.19, MySQL 8.0.46, FreeRADIUS 3.2.8 test containers), started 2026-10-03T18:55Z, finished 22:15Z.

| Check | Result |
|---|---|
| `dev/test/run-tests.sh` (plain) | MariaDB **OK (758 tests, 49 886 assertions)**; MySQL **OK (758 tests, 49 886 assertions)**; exit 0 (79 m 57 s) |
| Variant registry reproducibility | matches freshly built fixtures |
| `dev/test/run-tests.sh --testdox` | MariaDB OK 758 / MySQL OK 758; **1516 ✔, 0 ✘** (`TESTDOX.txt`); exit 0 |
| R0007 apply / inspect / rollback | `R0007ReversalStatesMigrationTest` (clean apply + verify on V1 / V2 / V3, no false R0003 drift, collision, unknown definition, interrupted apply after the first ALTER, failed second ALTER, exact R0003 restore, rollback refused for each new state in `status` / `from_state` / `to_state`, deterministic checksum / fingerprint, non-project targets refused) and `MigrationRedefinesTest` (malformed entries, mutating / non-SELECT guards) |
| WP4 FreeRADIUS contract, WP5A regression, WP3A / WP3B guards | included in the 758 per engine, all green |
| Real-worker concurrency | reversal vs open, reversal vs re-provision, open vs ADMIN_SYNC, open vs PASSWORD_CHANGE, concurrent voucher redemption of one code, concurrent E13 usernames, concurrent same-key purchases, concurrent transfer drain, hardened debit vs `Balance::plus` / `plusByPhone` |
| Route matrix (`RouteMatrixTest`, WP2_EVIDENCE) | 1470 requests, 1183 denials, 287 allowed; identical on both engines (§G) |
| PHP 8.2 lint | 437 / 437 tracked non-vendor PHP files |
| Manifest | `MANIFEST.sha256` 42 / 42 OK |
| R0001–R0006 | byte-identical: `a0b52f7f…a5a7`, `38bceb1f…b771`, `60e63156…acd`, `7f3bc5bc…dffa7`, `b69135ea…3854`, `f1a76119…7abd` |
| R0007 | `b7edce53accd75a5af7bce6cbf66a9bae02b90f8f67211000a5fb08328ef00f6` |
| Untouched | `Package.php`, `Tx.php`, `ServiceGate.php`, `install/radius.sql`: no diff since `427799f5` |
| Secret sweep | added lines and evidence files: no credential, token or secret value |
| Shared host | AVAIA container set identical before / after (observe only); max load 18.8, min MemAvailable 40 200 MiB; no global prune, no daemon change; test containers removed |

Harness notes (not product failures):
- An earlier canonical attempt was stopped by me: it showed one real defect (the WP5B usage block had pushed the `reconcile.php` CLI guard below the 25 lines `CliOnlyTest` inspects), fixed in `bb15ffc8` before the run above, plus the known `CliOnlyTest` healthcheck race (`Connections` 30 vs 29).
- During slice validation one MySQL `ActivationHttpTest` batch hit the known AppServer port-reuse flake (404 from another built-in server on `/`); isolated reruns 2 / 2 clean. Neither flake occurred in the final canonical run.

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
