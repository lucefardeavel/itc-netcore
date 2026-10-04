# WP5B Owner Review Decision and Owner Decisions

Recorded 2026-10-04 from the owner's explicit decisions in the implementation session:
- "OWNER VERDICT — WP5B ACCEPTED / CANONICAL INTEGRATION GO": status **ACCEPTED_WITH_NOTES**, accepted implementation `cdaebba2`, accepted evidence / branch HEAD `cfef0129`; fast-forward `feat/reseller-system-v1`, record the acceptance, push only the feature branch; WP6 not started.

This file records that verdict and the WP5B owner decisions given during planning and review. The scope and the notes below are the owner's verdict; their technical detail is in the accepted WP5B documentation (`docs/WP5B_LEGACY_CLOSURE_MODEL.md`, `owner-review/WP5B/REPORT.md`).

## WP5B acceptance
- Owner status: **ACCEPTED_WITH_NOTES**.
- Accepted implementation: `cdaebba22e163e2fc5e68b9ce111e34b3f3f3538`.
- Accepted evidence / branch HEAD: `cfef0129928005e398304ca30021925a6bbace1a`. Branch `wp5b/legacy-entrypoint-closure`, on origin, unchanged.
- Base: `427799f53a570846659796b13e0fbad82175eb6e` (WP3B + WP4 + WP5A integrated, WP5A acceptance recorded).
- `feat/reseller-system-v1` is fast-forwarded (`--ff-only`) to `cfef0129`, plus this metadata commit.
- Schema: R0007 only (`b7edce53…`). R0001–R0006 are byte-identical. `Package.php`, `Tx.php`, `ServiceGate.php` and `install/radius.sql` are unchanged since `427799f5`.

## Owner decisions applied in WP5B
| Decision | Content |
|---|---|
| Plan revision 2 accepted ("OWNER FINAL DECISION — WP5B PLAN ACCEPTED / IMPLEMENTATION GO") | Implementation of `WP5B_PLAN.md` revision 2, which applied OD-1 … OD-15 of the plan review. |
| OD-1 / OD-2 / OD-3 | No reseller retail money, online payment or auto-renew in V1. Those paths fail closed for reseller-owned customers. |
| OD-6 | Admin / SuperAdmin web recharge of a reseller-owned customer continues in `activation/create` (ActivationService); `zero` / `balance` and the API are refused. |
| OD-9 | Voucher + username never touches an existing customer (new customer only). |
| OD-10 | Bounded `LegacyReprovisionService` (re-provision only, never a grant) plus device default deny. |
| OD-11 / OD-12 | `activation.reverse`: Admin / SuperAdmin G only. A financial refusal leaves the activation COMPLETED with nothing written. |
| OD-14 | R0007 extends only the three R0003 state ENUMs with `REVERSAL_PENDING` / `REVERSED`. |
| OD-15 | Pinned `RouteMap` changed for `activation/reverse` and re-pinned. |
| `Balance.php` correction | Pinned `Balance.php` changed to atomic relative updates. One id is resolved by username (`ORDER BY id LIMIT 1`), then updated by id; no multi-row update. Re-pinned. |
| E13 correction | The provisional-customer cleanup runs in one transaction. |
| "R0007 RUNNER EXTENSION APPROVED" | Strict `redefines` primitive in the pinned `MigrationDefinition.php` / `Runner.php` (re-pinned), with the listed migration-test contract changes and dedicated tests. |

## Owner code-review fix rounds
| Round | Reviewed | Blockers | Fix commits |
|---|---|---|---|
| Fix round 1 ("OWNER CODE REVIEW — WP5B FIX REQUIRED ROUND 1") | `bb15ffc8` (evidence `0d9d6eb7`) | 1 HELD balance resolution race; 2 RADIUS read inside the reversal transaction; 3 re-provision partial external success; 4 MikroTik Hotspot rename and the expiry path vs the grant guard; 5 coupon authority at buy | `3a0a7210`, `dadaa11a`, `e8d5d51a`, `0bb83fad`, `5d76a09b`; evidence `152b43cc` |
| Fix round 2 ("OWNER FINAL REVIEW — WP5B FIX REQUIRED ROUND 2") | `5d76a09b` (blockers 1–5 closed) | 5B: the buy-time coupon price basis must be the gateway preview's legacy rule | `cdaebba2`; evidence `cfef0129` |

Every round was additive (no amend, squash or rebase). Each defect was reproduced on the reviewed candidate before its fix: round 1 gave 12 failures + 1 error, and round 2 gave 3 failures.

## Accepted scope
- Closure of reseller-owned legacy service-grant bypasses.
- Authorized Admin / SuperAdmin web recharge through ActivationService.
- Reseller balance / gateway / voucher / auto-renew service grants fail closed.
- Bounded `LegacyReprovisionService`.
- Shared tree serialization (`TreeSerialization`).
- Device default deny.
- Completed-activation reversal (`ActivationReversalService`).
- R0007 `REVERSAL_PENDING` / `REVERSED`.
- Exact reversal context (captured at COMPLETED).
- Original-ledger financial reversal.
- Atomic voucher CLAIMED lifecycle.
- E13 username serialization.
- Payment gateway resolver hardening (`PaymentGatewayResolver`).
- POST / CSRF payment mutations.
- Server-authoritative coupon validation (`CouponAuthority`).
- The CURRENT customer `Invoice` override as the authoritative coupon price basis at BUY (else the plan price).
- `LegacyCustomerBalanceService` for unassigned customers only.
- HELD operation lifecycle and operation serialization.
- `Balance.php` relative atomic balance updates.
- Cron financial work CLI-only.
- Operator reconciliation commands (`system/reseller/reconcile.php`).
- Closure of SEC-08, SEC-13, SEC-14, SEC-15, SEC-16 and SEC-23.

## Accepted test evidence (canonical run at `cdaebba2`)
- 778 tests per engine: MariaDB 50 026 assertions, MySQL 50 026 assertions. Stack: MariaDB 10.11.19, MySQL 8.0.46, PHP 8.2.34, FreeRADIUS 3.2.8 test containers. The registry matches.
- Testdox 1556 / 0.
- Route matrix 1470 / 1183 / 287, identical on both engines. All 1442 accepted rows and the 23 WP5A rows are unchanged; 5 `activation/reverse` denials were added.
- Lint 439 / 439; manifest 42 / 42; secret sweep clean.
- Details: `owner-review/WP5B/REPORT.md`, `owner-review/WP5B/TESTDOX.txt`, `owner-review/WP5B/FIX_ROUND_1_REPRODUCTION.txt`, `owner-review/WP5B/FIX_ROUND_2_REPRODUCTION.txt`.

## Accepted notes (not blockers)
1. Installed plugins with direct DB access can bypass application policy. WP5B detects drift but does not make arbitrary PHP plugins a security boundary. Carried to WP9 / WP10.
2. Reseller self-purchase, provider gateway commerce and reseller auto-renew remain disabled in V1.
3. Pre-WP5B COMPLETED activations lacking reversal context return `REVERSAL_CONTEXT_NOT_CAPTURED`.
4. `REVERSAL_PENDING` may require operator intervention if RADIUS restoration succeeded but the financial reversal later refuses.
5. Legacy invoices, voucher codes and usernames still have no universal DB uniqueness guarantees.
6. Gateway callback authentication remains the external payment plugin's contract.
7. Legacy re-provision may hold the global tree advisory lock across a device call. Throughput optimization is carried to WP9.
8. HELD balance operations, CLAIMED vouchers and `REPROVISION_UNCONFIRMED` require operator review. Dashboards belong to later UI / reporting work.
9. The legacy cron amount behaviour is preserved, pending its separate owner decision.
10. Real NAS / MikroTik validation is not claimed by WP5B. It remains part of the later integration / real-device validation work.

Accepted notes are not release acceptance. WP6 has not started and needs a separate owner go.
