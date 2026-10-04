# WP5B — Legacy Service Entrypoint Closure — Model

Plan: `WP5B_PLAN.md` revision 2 (owner-accepted, "OWNER FINAL DECISION — WP5B PLAN ACCEPTED / IMPLEMENTATION GO"), with the owner corrections of that decision and the later approval "R0007 RUNNER EXTENSION APPROVED". Evidence: `owner-review/WP5B/REPORT.md`, `owner-review/WP5B/TESTDOX.txt`.

## 1. What WP5B guarantees

Every legacy path that can create, extend, re-provision or otherwise grant service, or move customer balance, is either:

- **closed** for a reseller-owned customer (`manager_id IS NOT NULL`): refused before any write, or continued in the authoritative WP5A activation (E1 web);
- **bounded** for a reseller-owned customer (re-provision only, E19 / E20 / E21): `LegacyReprovisionService`, which never grants service;
- **hardened** for an unassigned (legacy) customer: the legacy behaviour and amounts are kept, but every money movement is exactly-once, locked and replay-safe, and every voucher redemption is atomic.

A COMPLETED activation can now be **reversed** (Admin / SuperAdmin) through a dedicated saga.

## 2. Classification (implemented)

| ID | Path | Reseller-owned | Unassigned |
|---|---|---|---|
| E1 | admin recharge (`plan/recharge-confirm`, `plan/recharge-post`, `customers/recharge`) | web: nothing written, redirect to `activation/create`; `zero` / `balance` / API refused | kept; `zero` needs `service.recharge_free`; `balance` → `LegacyCustomerBalanceService` (`balance:admin:{adminId}:{nonce}`) |
| E2 | staff voucher refill | refused | `VoucherRedemptionService` (STAFF source) |
| E3 / E4 / E5 | deposit / extend / plan edit | refused (WP2 gate) | kept, SA / A; audited (`service.deposit` / `service.extend` / `service.plan_edit`) |
| E6 | voucher generation | — | CSPRNG codes, no duplicates |
| E7 | portal voucher | refused | `VoucherRedemptionService` (CUSTOMER source) |
| E8 | balance purchase (`order/pay`, `order/buy` with gateway `balance`) | refused | `LegacyCustomerBalanceService` (`balance:buy:{customerId}:{nonce}`), POST + CSRF |
| E9 | gift (`order/send`) | refused (sender or target) | `LegacyCustomerBalanceService` (`balance:gift:{senderId}:{nonce}`) |
| E10 | online payment | refused (WP2 gate) | `PaymentGatewayResolver`, no posted discount, conditional transitions |
| E11 | cron auto-renew | no renewal: expiry enforced, one `AUTO_RENEW_UNAVAILABLE` record per obligation, no activation / wallet / balance debit | `LegacyCustomerBalanceService` (`balance:cron:{rechargeId}:{expiration}T{time}`), CLI only |
| E12 / E15 | public voucher-only / RADIUS authorize | refused (username collision) | `VoucherRedemptionService` (PUBLIC / RADIUS source) |
| E13 | voucher + username (login) | refused | new customer only, per-username lock; existing customers never touched |
| E18 | balance transfer (`home`) | refused | one atomic locked transfer (`balance:transfer:{senderId}:{nonce}`) |
| E19 / E20 / E21 | sync (single, bulk, portal), password change, identity edit | `LegacyReprovisionService` | kept |
| E22 | API | every grant refused; re-provision where already API-reachable (`customers/sync`, `customers/edit-post`) | kept |
| E23 | plugins / direct calls | core guards (unchanged) + device default deny; detected by `reconcile.php drift` | — |
| new | `activation/reverse` | Admin / SuperAdmin (`activation.reverse` G), web only | n/a |

## 3. R0007 and the `redefines` runner primitive

`R0007_activation_reversal_states` appends `REVERSAL_PENDING`, `REVERSED` to the three R0003 state ENUMs only (`tbl_reseller_activations.status`, `tbl_reseller_activation_events.from_state` / `to_state`). `contract.tables = []`, `contract.table_parts = []`.

The owner-approved runner primitive `redefines` (pinned `MigrationDefinition.php` / `Runner.php`, re-pinned):

- each entry: valid table / column identifiers, non-empty and distinct canonical `from` / `to`, exactly these three keys plus `data_guard`, which must be one read-only `SELECT` (no DDL / DML / locking read / comment / second statement); malformed definitions fail closed (USAGE);
- a redefining migration creates no table and no table part;
- set-level check before any command: only a column of a project table created by an earlier migration, `from` = its current contract definition, never a legacy / core table;
- preflight: a column already in the `to` definition while the migration is not applied is a collision; any third definition fails closed;
- post-check: the column must equal `to`; a historical contract is evaluated through the redefinitions of later APPLIED migrations (no false R0003 drift); the R0003 file stays byte-identical;
- rollback: refused while the data guard finds a row with a new value; restores exactly the R0003 definitions; the contract fingerprint includes the redefinitions (deterministic).

## 4. State machine

17 states, 26 edges (WP5A 24 + `COMPLETED → REVERSAL_PENDING → REVERSED`). `TERMINAL` (not in flight) = COMPLETED, FAILED, ROLLED_BACK, CANCELLED, REVERSED; `FINAL` (no outgoing edge) = FAILED, ROLLED_BACK, CANCELLED, REVERSED. REVERSAL_PENDING is in flight: an activation open and a re-provision of the customer refuse. The WP5A ROLLBACK_PENDING / ROLLED_BACK meaning is unchanged.

Invariants (test support): REVERSAL_PENDING / REVERSED keep the COMMITTED reservation; a REVERSED activation has every original ledger leg reversed exactly once and exactly one `REV-<reference>` transaction; no other activation has either.

## 5. Completed-activation reversal (`ActivationReversalService`)

Context captured at COMPLETED (version 1, `LegacyBridge::record` → COMPLETED event metadata `reversal_context`): the recharge row before (`exists` explicit) and after the write, the transaction id and invoice; the RADIUS prior / target projections come from the PROVISIONING events (WP5A). Pre-WP5B activations: `REVERSAL_CONTEXT_NOT_CAPTURED`.

| Phase | What happens | Failure |
|---|---|---|
| 0 safety proof (read only) | context present; no later service-affecting or in-flight activation; recharge row = `legacy.post`; RADIUS = captured target; prior restorable (`RestorePlan`) | refusal + one audit row; nothing else written |
| 1 financial preflight (tree lock, writes nothing) | identity rows S → activation → wallets ascending (no creation) → reservation rows; original legs from the immutable ledger rows, cross-checked with the sealed snapshot; clawback floor `available − m ≥ 0`; storage range | `REVERSAL_FINANCIAL_REFUSED` |
| 2a start (`TreeSerialization::hold`, the same tree lock as `ActivationService::open`, lock-only) | 1. short transaction: database checks; 2. **no transaction**: RADIUS = captured target; 3. short transaction: activation re-locked, database checks repeated, COMPLETED → REVERSAL_PENDING (reason, legs summary). No application DB transaction or row lock is open at any RADIUS read / restore (fix round 1). | `REVERSAL_EXTERNAL_STATE_CHANGED` / `ACTIVATION_STATE_CONFLICT`, nothing written |
| 2b RADIUS compensation (no transaction) | exact restore of the captured prior subscriber rows, read back; the verified restore is the durable evidence (PROVISIONING_RESULT on REVERSAL_PENDING) | target still there → `REVERSAL_RADIUS_NOT_RESTORED` (repeat); anything else → `REVERSAL_EXTERNAL_STATE_CHANGED` (held) |
| 3 commit (tree lock, all-or-nothing) | `LedgerService::postActivationReversal` (leaf REVERSAL credit, one clawback per commission, keys `rev:{activation}:e{entry}`, unique `reverses_entry_id`), recharge row restored to PRE (or switched off), `REV-<reference>` transaction (negative retail), REVERSAL_PENDING → REVERSED, audit | rolled back; REVERSAL_PENDING kept with the evidence and a note (R-4) |

Resume: REVERSAL_PENDING with evidence → phase 3 only (no second RADIUS write); without → phase 2b; REVERSED → `ACTIVATION_STATE_CONFLICT`. No automatic customer refund (V1 staff activations collect no retail; the event records `retail: not_collected_by_system`).

## 6. Bounded re-provision (`TreeSerialization`, `LegacyReprovisionService`)

`TreeSerialization::hold` takes the same named lock as `Tx::withTreeLock` / `FinanceTx::run(…, true)` without a transaction (name equality and mutual blocking are tested; `Tx.php` unchanged). `LegacyReprovisionService::run` performs ADMIN_SYNC / ADMIN_BULK_SYNC_ROW (SA / A), PORTAL_SYNC / PASSWORD_CHANGE (the customer itself), IDENTITY_EDIT (SA / A) for a reseller-owned customer under that lock: database authorization, no activation in flight, billing snapshot, the approved mutation only (`username`, `password`, `pppoe_username`, `pppoe_password`, `pppoe_ip`; the recharge rows follow a username change as in the legacy controller; never status / plan / expiry), the device call outside any transaction with a private per-call permit, billing unchanged, RADIUS verified for Radius-device plans.

| Outcome | Result |
|---|---|
| SUCCESS + verified + billing unchanged | DONE, audit `reprovision.<op>` |
| FAILED (confirmed) **before any external step applied** | the DB mutation is compensated conditionally; `REPROVISION_FAILED` |
| FAILED after an earlier step (another target, or `change_username` before `add_customer`) applied | promoted to UNKNOWN (fix round 1): next row |
| UNKNOWN / mismatch / billing changed | new values kept, no retry, `REPROVISION_UNCONFIRMED` (audit without secrets, drift report) |

External progress is tracked per target and per service-affecting step; the first non-success stops every further step.

Device default deny: `add_customer`, `sync_customer`, `change_username` of `Radius`, `RadiusRest`, `MikrotikHotspot`, `MikrotikPppoe`, `MikrotikVpn` refuse a reseller-owned customer (`RESELLER_OWNED_REFUSED`) unless called inside `run()` for that customer. In the three MikroTik devices the provisioning is a private `provisionCustomer()`: the public `add_customer()` is guard → primitive, and the allowed `remove_customer()` expiry path (`plan_expired`) uses the primitive directly (fix round 1). `MikrotikHotspot::change_username` really renames now (the pre-WP5B code tested an undefined variable). Ownership is read from `manager_id`; an unmigrated schema without the column has no reseller-owned customer (the WP4 device behaviour is unchanged there).

## 7. Vouchers (`VoucherRedemptionService`)

One redemption path; reseller-owned targets refused before the voucher is touched. Claim `'0' → 'CLAIMED'` (single UPDATE on one row), grant through the legacy `Package::rechargeUser`, finalize `'1'` on an invoice; back to `'0'` only on its exact `false` return (pre-write refusal); anything else stays `CLAIMED` (never redeemable again, drift report). Sources: STAFF / CUSTOMER (real context, audit `voucher.redeem`), PUBLIC / RADIUS (log line with a code hash, never the code). E13: `GET_LOCK('nuxr_user_' . sha1(DATABASE() . '|' . lower(username)), 10)`, existing customer (username or PPPoE username) refused untouched, provisional customer created once, the lock released before the grant; proven not started → delete the provisional row and release the voucher in ONE transaction under the username lock (owner correction). CLAIMED vouchers are never deleted (single and bulk delete conditional on status). E6 codes from `random_bytes` / `random_int`.

## 8. Payments (`PaymentGatewayResolver`)

Gateway name `^[A-Za-z0-9_]{1,64}$`, configured in `payment_gateway`, `realpath` inside the gateway directory; `Balance` never resolvable. The posted `discount` is ignored. Coupons (`CouponAuthority`, fix round 1): the gateway page only previews (no use consumed; the session keeps the coupon code, never an amount); at buy the coupon is re-fetched `FOR UPDATE`, re-validated (active, date window, not for Balance plans, minimum order, usage limit), the discount derived again — on the same price basis as the preview's legacy rule, read again at buy (fix round 2): the customer's current `Invoice` attribute when set, else the plan price; the payment-row amount composition (plan price + bills + tax − discount) is unchanged — and one use consumed by a conditional UPDATE in the same transaction that writes the payment row (`COUPON_INVALID` writes nothing). Cancel / gateway switch only `WHERE status = 1`; the plugin status check runs only while unpaid. `order/pay`, `order/buy`, `order/view#check`, `order/view#cancel` are POST + CSRF. Gateway callback authentication remains the plugin's contract (R-9).

## 9. Legacy customer balance (`LegacyCustomerBalanceService`, `Balance.php`)

Unassigned customers only; strict minor units (DECIMAL(15,2)), no float arithmetic, no `Balance::*`, no `Reseller\Finance`. Purchases run under an operation-level advisory lock on the operation key (fix round 1), taken before the HELD row exists and held through the HELD commit, the external grant and the final classification (no DB transaction across the grant); `balance-resolve` takes the same lock (waits ≤ 10 s or fails closed `BALANCE_OPERATION_BUSY`), re-reads and only then changes a HELD row; a crashed dispatcher releases it with its connection. Every conditional finalization checks its affected row and otherwise reports the persisted state. Tx1 locks the payer (and target) rows ascending, looks the key up (replay), validates, debits, inserts the journal row HELD (5) in `tbl_payment_gateway` (gateway / payment_method `Balance`, key in `gateway_trx_id`, request JSON); the grant runs outside any transaction; an invoice → COMPLETED (2) once; exact `false` → REFUNDED (4) once with the credit back; anything else stays HELD (operator `reconcile.php balance-resolve`, exactly once, audited). Transfer: one transaction, both rows locked, legacy minimum, no negative balance, replay by key.

`Balance.php` (owner-approved pin change): `plus` / `min` are single relative UPDATEs by id; `plusByPhone` / `minByPhone` resolve exactly one id (`… WHERE username = ? ORDER BY id LIMIT 1`) and update by id (`minByPhone` with `balance >= ?` in the same statement). Signatures and return values unchanged. A legacy credit can no longer overwrite a hardened locked debit.

## 10. Cron

`system/cron.php` refuses any non-CLI SAPI first (empty 404, before `init.php`); the `<Files cron.php>` allow is removed from `system/.htaccess` (`api.php`, `cron_reminder.php` unchanged). Deployment: replace any URL cron with `php system/cron.php` (WP10 runbook).

## 11. Operator tools (`system/reseller/reconcile.php`, CLI only)

`drift [--no-radius]` (exit 4 on findings: PERIOD_NOT_BACKED, RADIUS_DRIFT, PAYMENT_FOR_RESELLER_OWNED, VOUCHER_CLAIMED, REPROVISION_UNCONFIRMED, BALANCE_HELD), `expired-autorenew`, `balance-held`, `balance-resolve <id> <completed|refund>`. The reversal itself is web / Admin only (no CLI reversal).

## 12. Residual risks

R-1 … R-14 of the plan (§13), with R-11 closed by the approved `Balance.php` change. See the report §I.
