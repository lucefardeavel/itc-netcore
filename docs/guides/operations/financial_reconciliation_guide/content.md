# About this guide

This guide is for the **authorized financial operator** and the Admins of one ITC NetCore V1.0 instance. It explains the wallet and ledger model, how money moves (funding, adjustments, activation debits, commissions, settlement, reversals), how held funds arise and are resolved, and which tools reconcile the books. Sources: `docs/WP3A_FINANCE_MODEL.md`, `docs/WP3B_PRICING_MODEL.md`, `docs/WP5A_ACTIVATION_MODEL.md`, `docs/WP5B_LEGACY_CLOSURE_MODEL.md`, `docs/WP6B_FINANCE_UX_MODEL.md`, `docs/OPERATOR_RECOVERY_RUNBOOK.md`.

> [!WARNING]
> Release Candidate, not yet production-accepted. The finance behaviour is verified by the lab test harness on MariaDB 10.11 and MySQL 8.0; target acceptance has not been run.

> [!FINANCE-WARNING]
> **Direct database edits are prohibited for financial repair.** Never run INSERT, UPDATE or DELETE against the ledger, wallets, transfers, reservations, activations, audit tables or `radacct`. Every correction is a new, audited operation through the application or `reconcile.php`. The least-privilege application account cannot rewrite the ledger by design.

# 1. Concepts

| Concept | Definition |
|---|---|
| Money | Integer minor units (1 = 0.01), stored as `DECIMAL(18,2)`. No floating point. Inputs with signs, separators, exponents or more than 2 decimals are refused |
| Wallet | Prepaid credit of one Agent (reseller). Fields: balance, debt limit. Only an enrolled, consistent Agent can own one; no wallet is created by upgrade or backfill |
| Debt limit | Credit the provider extends to a reseller (Admin only). It counts as real available credit |
| Reservation | Funds held for one activation. States: `ACTIVE` (held), `COMMITTED` (charged), `RELEASED` (freed, no value moved) |
| Available credit | `balance + debt limit − SUM(ACTIVE reservations)`, always computed from locked state |
| Ledger | Double-entry, **insert-only** record of every value movement. No update, delete or edit path exists. A correction is a compensating entry |
| Audit trail | Who did what and why (`tbl_reseller_audit_events`); the ledger is the financial truth |

**Ledger entry kinds**

| Kind | Created by |
|---|---|
| `FUNDING` | Provider funding of a wallet (Admin) |
| `WITHDRAWAL` | Provider withdrawal from a wallet (Admin) |
| `ADJUSTMENT` (credit / debit) | Accounting correction (Admin) |
| `TRANSFER_OUT` / `TRANSFER_IN` | Transfer from a parent to its direct child (Agent or Admin) |
| `ACTIVATION_DEBIT` | Settlement of a completed activation (the selling reseller's wholesale) |
| `COMMISSION_CREDIT` | Positive margin of each intermediary reseller in the chain |
| `REVERSAL` | Compensation of a transfer, or of a completed activation (leaf credit and commission clawbacks) |

# 2. Funding, withdrawals and adjustments

All are **review, then confirm** in Finance (Admin / SuperAdmin):

1. Fill the form: amount, reason (3–255 characters), external reference where applicable.
2. **Review** (no write): the server normalises the amount, shows informational before / after figures, generates the operation reference `WEB-<OP>-<date>-<hex>` and signs the reviewed fields.
3. **Confirm**: the server recomputes the signature (any change gives `REVIEW_EXPIRED`, and nothing is written). Withdrawal, debit adjustment and transfer reversal also require a **typed confirmation**. A double submit replays the same operation ("Already recorded").

| Operation | Rule |
|---|---|
| Funding | Valid, Active owner; may create the wallet |
| Withdrawal | Existing wallet; available credit after ≥ 0 |
| Adjustment credit / debit | Existing wallet (never creates one); a debit obeys the floor |
| Debt limit | A decrease is refused if `balance + new limit − reserved < 0`; the review binds the current limit (a changed limit refuses the stale review) |
| Transfer to child | Direct child only; source floor; both owners valid |
| Transfer reversal | Admin / SuperAdmin; at most once; a new compensating operation, the original stays and is marked REVERSED |

> [!FINANCE-WARNING]
> For a disabled (frozen) reseller, funding, withdrawal, adjustments, debt-limit changes and new transfers are refused for everyone, Admin included. Settlement of existing exposure (commit / release) and Admin reversals remain possible.

# 3. Activation debits and settlement

| Phase | What happens to money |
|---|---|
| Open | The price chain is resolved and sealed; the wholesale amount is **reserved** on the owner Agent's wallet (`FUNDS_RESERVED`) |
| Provision | No money moves while RADIUS is provisioned and verified |
| Commit | One transaction: `ACTIVATION_DEBIT` of the leaf wholesale, one `COMMISSION_CREDIT` per positive margin, provider settlement recorded on the activation, reservation `COMMITTED`, activation `COMPLETED` |

Settlement math (integer minor units): leaf debit `L = c_k`; provider settlement `P = c_1` (top-level reseller's wholesale, not a wallet credit); intermediary margin `m_i = c_{i+1} − c_i ≥ 0`; conservation `P + Σ m_i = L` exactly.

Failure outcomes:

| Situation | Activation state | Reservation | Money |
|---|---|---|---|
| RADIUS failed, not retryable | `FAILED` | `RELEASED` | Nothing charged |
| Retryable failure | `RETRY_PENDING` (at most 2 identical retries) | `ACTIVE` | — |
| Retries exhausted | `FAILED` | `RELEASED` | Nothing charged |
| RADIUS outcome unknown, read-back mismatch, crash in flight | `RECONCILIATION_REQUIRED` | **`ACTIVE`** | Nothing yet; never retried blindly |
| Delivered but settlement failed | `RECONCILIATION_REQUIRED` | **`ACTIVE`** | Service is provisioned but **never free**: settled exactly once by reconciliation |

<!-- pagebreak -->

# 4. HELD funds: what they are and how they are resolved

> [!FINANCE-WARNING]
> **HELD reservations are never released automatically** — not by time, not by a cron, not by a deadlock error. They are resolved only by an audited operator **resume** or **cancel** (or reconcile / rollback, according to state). There is no expiry primitive, timer or cron for reservations (N-09).

## 4.1 Activation holds

| State | How it arises | Authorized resolution |
|---|---|---|
| `FUNDS_RESERVED` / `RETRY_PENDING`, stale ≥ 300 s | Stopped before or between RADIUS attempts; also left by a `CONCURRENCY_RETRY` refusal under heavy simultaneous activity on one wallet (MariaDB, rare) | **Resume** (continues) or **Cancel** (releases the reservation, `CANCELLED`). A live activation is refused |
| `VERIFIED` (stale) | Delivered, settlement not yet run | **Resume** (runs the settlement once) |
| `RECONCILIATION_REQUIRED` | Provisioning or settlement outcome unknown | **Reconcile**: delivery verified: settle once; RADIUS = target: settle; RADIUS = prior: `FAILED` and released; anything else: `EXTERNAL_STATE_AMBIGUOUS` (exit 4), inspect RADIUS, then roll back or leave held |
| `ROLLBACK_PENDING` | Restore of the prior RADIUS state not confirmed | **Rollback** again; funds stay held until the restore is verified |
| `REVERSAL_PENDING` | Reversal of a completed activation started, not finished | **Reverse** again (idempotent continuation) |

Where: Operations > Operations Center (Admin), then the activation page, which shows the allowed actions with their own confirmation. CLI equivalents (operator shell):

```bash
php system/reseller/reconcile.php show <ACT-...>        # state and event timeline (read-only)
php system/reseller/reconcile.php resume <ACT-...>
php system/reseller/reconcile.php cancel <ACT-...>
php system/reseller/reconcile.php reconcile <ACT-...>
php system/reseller/reconcile.php rollback <ACT-...>
```

Exit codes: 0 OK · 2 refused (nothing changed) · 3 failed (reference in the server log) · 4 external state ambiguous (funds held) · 5 usage.

## 4.2 Legacy balance operations HELD

Interrupted balance operations of direct (non-reseller) customers.

1. List: `php system/reseller/reconcile.php balance-held`.
2. Prove from the gateway and RADIUS whether the service was granted.
3. Decide once: `php system/reseller/reconcile.php balance-resolve <journal id> completed` (granted) or `refund` (credit back, exactly once).

## 4.3 Vouchers under review

A voucher left `CLAIMED` holds no wallet money but is a revenue item under review: `reconcile.php voucher-review`, then `voucher-resolve <id> confirm|deny|release --operator=<name> --reason="<why>"` per the recovery runbook §2. A `deny` keeps the invoice; any refund happens outside the application.

# 5. Reversing a completed activation

Admin only, reason required (`activation/reverse`). The reversal restores the prior RADIUS state, then posts in one all-or-nothing transaction: a `REVERSAL` credit of the original debit to the selling reseller and one `REVERSAL` clawback per original commission. It is refused if a clawback would leave a recipient's available credit below zero. The reservation stays `COMMITTED`; the provider settlement is reversed in the activation record. V1 makes **no automatic customer refund** (staff activations collect no retail through the system).

# 6. Reconciliation routine

| Frequency | Check | Tool | Expected |
|---|---|---|---|
| Hourly (monitoring) | Finance integrity | `health.php --json --finance` | `finance` OK |
| Daily | Finance and activation integrity | `reconcile.php finance-check` | `{"finance_check":"CONSISTENT"}` |
| Daily | Drift | `reconcile.php drift` | No line (`PERIOD_NOT_BACKED`, `RADIUS_DRIFT`, `PAYMENT_FOR_RESELLER_OWNED`, … are actions, see runbook §6) |
| Daily | Held queue | Operations Center; `reconcile.php stale`; health `reservations` (WARN > 1 h, CRIT > 24 h) | Empty, or each item owned by a named operator |
| Monthly | Reseller statements | Finance > Ledger, Transfers; Reports > Sales, Commissions, Provider settlement (Admin), Reseller performance | Agree with the ISP's external payment records |
| After a restore | Gap since the recovery point | Audit records, payment-provider records, NAS-side `radacct` | Missing operations re-entered through the application with a reason |

**Exports.** Finance screens export CSV with the same scope as the screen (UTF-8 BOM, `;` separator, formula-safe cells), at most 20,000 rows and 366 days per time series; larger exports are refused, never truncated. Each export writes one `export.finance` audit row (metadata only).

`finance-check` covers: ledger continuity, wallet = ledger, available ≥ 0, transfer legs, reversal links, reservation ↔ debit, settlement against the sealed snapshot, activation timelines and reservations.

# 7. Integrity incident

> [!SECURITY-WARNING]
> `reconcile.php finance-check` exit 4, or health `finance` CRITICAL, means a write happened outside the finance services (tampering, a plugin or a bug). Stop selling, **do not correct the numbers**, preserve a database snapshot and the logs, and escalate (`change_management_escalation`).

# 8. Audit

| Event | When |
|---|---|
| `finance.fund`, `finance.withdraw`, `finance.adjust_credit`, `finance.adjust_debit`, `finance.transfer`, `finance.reverse` | Every successful command |
| `finance.debt_limit` | An actual change |
| `finance.reservation_release` | A staff-resolved release |
| `activation.resume` and the activation event timeline | Every operator action on an activation (events are append-only) |
| `export.finance` | Every finance export |

Audit content: actor and target, reference, operation id, amount, ledger entry ids, balance and available before / after, reason. Never secrets. Replays and refusals are not audited (they change nothing).

# 9. Never do

- Delete or edit rows of `tbl_manager_ledger`, `tbl_manager_transfers`, `tbl_transactions`, `radacct` or any audit table.
- Run SQL against the ledger, wallets or reservations — including "just to release a stuck reservation".
- Release a held reservation without the runbook decision for its state.
- Retry an UNKNOWN network outcome blindly.
- Install an unreviewed plugin or payment gateway (code inside the application can bypass the finance services).
