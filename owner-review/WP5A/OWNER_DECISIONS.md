# WP5A Owner Review Decision and Owner Decisions

Recorded 2026-10-03 from the owner's explicit decisions in the implementation session:
- "OWNER VERDICT — WP5A ACCEPTED": status **ACCEPTED_WITH_NOTES**, accepted HEAD `8e3c6ec9`;
- "OWNER INTEGRATION GO — WP3B + WP4 + WP5A": fast-forward `feat/reseller-system-v1`, record the acceptance, push only the feature branch.

This file records those decisions and the WP5A owner decisions given during planning and review. The scope, notes and residuals below are carried forward from the accepted WP5A documentation (`docs/WP5A_ACTIVATION_MODEL.md`, `owner-review/WP5A/REPORT.md`); they are not new owner statements.

## WP5A acceptance
- Owner status: **ACCEPTED_WITH_NOTES**.
- Accepted implementation / evidence HEAD: `8e3c6ec975a5698d2ae74b089f8ddaf54460e8cf`. Branch `wp5a/activation-core`, on origin, unchanged.
- Base: `0c080a7aa80f032ea1d6a9497fcd9b6cdf8680bc` (WP4 accepted at `58e3e458` plus its acceptance record).
- Reviewed candidates that exposed issues, all fixed before acceptance:
  - `608260d9`: the unenrolled-Agent create form, the global plan catalog, and reconcile releasing funds on any RADIUS mismatch;
  - `ca1b12c7`: rollback re-keying an unknown prior password.
- `feat/reseller-system-v1` is fast-forwarded (`--ff-only`) to `8e3c6ec9`, plus this metadata commit.
- No R0007. R0001–R0006 are byte-identical (R0006 `f1a76119…7abd`). `install/radius.sql` is unchanged.

## Owner decisions applied in WP5A
| # | Decision |
|---|---|
| A1 | New `Reseller\Activation` namespace. The accepted WP3A / WP3B source-guard allowlists are extended to it. |
| A2 | The server derives NEW / RENEW / REACTIVATE / CHANGE_PLAN from the current RADIUS recharge (same plan id). The C14 period is frozen at validation; legacy `extend_expiry` is ignored. |
| A3 | Transition graph of 15 states and 24 edges; terminal states COMPLETED / FAILED / ROLLED_BACK / CANCELLED. |
| A4 | Reconciliation by Admin / SuperAdmin and the CLI `system/reseller/reconcile.php`. Stale activations are reported only; no timeout release. |
| A5 | `activation.create`: SuperAdmin / Admin G, Agent S (own customers), Sales A. `activation.read`: G / T / A. The owner Agent's wallet always pays; an ancestor never spends a descendant's wallet. Only RADIUS Hotspot / PPPoE plans. |
| A6 | At COMPLETED: the `tbl_user_recharges` row and one `tbl_transactions` row (`INV-<ref>`, retail price). No Package, hooks or messages. |
| A7 | Minimal staff routes `activation/create`, `create-post`, `view`, `reconcile`, `rollback`. Pinned `RouteMap` changed and re-pinned; the bare route is the read action. |
| A8 | Refund / reversal of COMPLETED activations deferred to WP5B. |
| Fix round 1, decision 1 (option B) | `activation.create` requires a validly placed reseller identity (`Capability::PLACEMENT_REQUIRED`, enforced in the central `PermissionService` and again in `ActivationService`). The A5 scope letters are unchanged. |
| Fix round 1, decision 2 | The create form lists only plans saleable for the actor's authoritative reseller identity (Agent: itself; Sales: the owner of a customer in its scope; Admin: the administrative list). No economics are exposed. |
| Fix round 1, decisions 3–6 | Reconciliation is split by durable history: VERIFIED reached → financial retry only; never verified → exact target / exact captured prior / `EXTERNAL_STATE_AMBIGUOUS` with the funds held. Rollback is an explicit operator decision; UI / CLI never present ambiguity as "not applied". |
| Fix round 1, decision 9 | No schema change: the existing event history and its JSON metadata distinguish the cases and hold the captured states. |
| Final review | Exact rollback only. A prior password is restored only when it equals `customer.password` / `customer.pppoe_password` exactly; otherwise `PRIOR_SECRET_UNRESTORABLE` before any write (ROLLBACK_PENDING, RADIUS untouched, funds held). The reservation is released only when the complete subscriber projection equals the captured prior one. |

## Accepted scope (from the final report §B)
- **Persistent activation state machine** for reseller-owned customers: NEW / RENEW / CHANGE_PLAN / REACTIVATE.
- **WP3B §9 contract:** one tree-locked open (revalidate → resolve → single sealed INSERT → reserve).
- **WP4 §10 contract:** provisioning outside any transaction.
- **Settlement:** `commitInTx` first in the COMPLETED transaction; exactly once.
- **Never free service:** a provisioned but unsettled activation keeps its reservation ACTIVE.
- **Idempotency and in-flight guard;** the CoA disconnect is best effort.
- **Captured prior / target RADIUS projection,** with the password stored only as a digest.
- **Reconciliation, rollback and cancel,** with stale reports.
- **Actor eligibility and the scoped catalog.**
- **Minimal staff routes and the CLI.**

## Accepted test evidence
- 618 tests / 46587 assertions per engine (MariaDB 10.11.19, MySQL 8.0.46, PHP 8.2.34, FreeRADIUS 3.2.8 test containers); the registry matches.
- The MySQL plain leg followed one `AppServer` port-reuse harness flake (`Wp3b\PlanDeleteGuardTest`) with clean isolated reruns (3/3) and a clean full-leg rerun.
- Testdox 1236/0.
- Route matrix 1465/1178/287: all 1442 accepted rows unchanged, 23 activation rows added.
- Lint 409/409; manifest 42/42; secret sweep clean.
- Details: `owner-review/WP5A/REPORT.md`.

## Notes (accepted residuals, from the WP5A report §I / model §10)
1. RENEW display window: RADIUS has the new expiry before the legacy recharge row changes at COMPLETED.
2. Provisioned but unsettled activations stay active with the funds reserved until an operator reconciles them (CLI / Admin view; dashboards WP6B).
3. No refund or reversal of COMPLETED activations (A8 → WP5B).
4. Global tree-lock serialization of activation opens and of the catalog resolution (throughput: WP9).
5. Reseller retail appears in legacy revenue reports (`tbl_transactions.price`).
6. Disconnect on CHANGE_PLAN / REACTIVATE is best effort; RENEW does not disconnect; no real NAS tested (WP8).
7. Pre-WP4 PPPoE rows keyed by `username` remain (WP4 note 1).
8. Plan group rows are not rolled back (shared plan configuration). Reconcile requires them to match exactly, so a foreign plan edit leaves the activation ambiguous with the funds held.
9. Unrestorable prior secret: ROLLBACK_PENDING + `PRIOR_SECRET_UNRESTORABLE` with the funds held. There is no automatic exit until an operator acts or a future owner-approved secure recovery mechanism exists (no encryption or schema change in WP5A).
10. Activations that reached provisioning before fix round 1 have no captured state: always ambiguous when never verified, and rollback-refused with the funds held. They exist in development databases only.
11. Ambiguous activations hold reseller credit until an operator acts (by design; views in WP6B).
12. Test harness: the `AppServer` port-reuse flake (seen again in the final run) and the `CliOnlyTest` healthcheck race. A harness fix needs owner approval.

## Binding inputs carried forward
- **WP5B:** close the legacy entrypoints through the activation core:
  - cron auto-renew, customer order and balance, payment callbacks, API, vouchers and plugins;
  - prove that no plugin or payment path bypasses activation and settlement (WP2 R1/R9);
  - refund / reversal of COMPLETED activations (A8);
  - legacy customer-charge composition (WP3B R4).
- **WP6A / WP6B:** the activation UX, the reconciliation and ambiguity dashboards, and operator tooling for ROLLBACK_PENDING / `PRIOR_SECRET_UNRESTORABLE`.
- **WP8:** real NAS / MikroTik verification of provisioning, disconnect and expiry.
- **WP9:**
  - tree-lock throughput;
  - red-team of the activation routes, the reconciliation CLI and the eligibility rule;
  - a secure prior-secret recovery design, if the owner wants one.
- **WP10:** scheduling of the stale-activation report; least-privilege database accounts.
