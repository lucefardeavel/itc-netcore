# WP5A — Activation Core — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED** (waiting for owner review). Final state: `STOPPED_WAITING_OWNER_REVIEW`.

Detailed model: `docs/WP5A_ACTIVATION_MODEL.md`. Test evidence: `owner-review/WP5A/TESTDOX.txt`. Plan: approved by the owner ("Approve all A1–A8").

## A. Baseline ancestry / accepted prior HEAD / current HEAD / branch / worktree

| Item | Value |
|---|---|
| Upstream ancestry | `d3e0596287322944ee21fcc14dc1798158463140` |
| Accepted prior WP | WP4 **ACCEPTED_WITH_NOTES** at `58e3e45898f813454e9232a9e535ad36b32740e0` (owner decision 2026-10-02) |
| Execution base | `feat/reseller-system-v1` = `0c080a7aa80f032ea1d6a9497fcd9b6cdf8680bc`: `58e3e458` plus the owner-metadata commit "docs: record WP4 owner acceptance and decisions" (local, not pushed) |
| Branch | `wp5a/activation-core`, from `0c080a7a` (nothing reset or rewritten) |
| Implementation HEAD validated | `c0302b619ee99b9e34df7a66cede1409deb85efc` |
| Candidate HEAD | the evidence commit `docs(wp5a): activation model, owner-review report and testdox` (SHA in the final response) |
| Worktree | clean after the evidence commit (`tests/vendor/` ignored) |
| Push / PR / merge / deploy | none |

## B. Scope implemented (plan `WP5A_PLAN.md` + owner decisions A1–A8; bindings: WP3B §9, WP4 §10)

| Required | Done |
|---|---|
| Persistent activation state machine | `ActivationState`: 15 states, 24 approved edges, four terminal states (A3). Every change is a guarded `UPDATE … WHERE status = <from>` plus one append-only event. |
| Activation for reseller-owned customers: NEW / RENEW / CHANGE_PLAN / REACTIVATE | `ActivationService::create`. The server derives the action (A2), and the form's expected action must match. |
| C14 service period, C18 time zone | `ServicePeriod`: legacy parity per unit, renewal from the current expiry, frozen in the VALIDATED event. |
| WP3B §9 contract | Phase 1 runs in one tree-locked transaction (revalidate → resolve → single sealed INSERT → reserve). Phase 2 provisions outside any transaction. In phase 3, `commitInTx` is the first locking work of the COMPLETED transaction. |
| WP4 §10 contract | The activation provisions through `RadiusPolicyBuilder` / `RadiusProvisioner` with its own expiry. `ProvisionResult` is stored in `provisioning_result`. UNKNOWN → RECONCILIATION_REQUIRED, never retried blindly. No direct RADIUS writes. |
| Idempotency, duplicate submit, reload | `staff:{user}:{nonce}` key with a request fingerprint. Replay returns the same activation. In-flight guard: one open activation per customer. |
| Never free service | A provisioned activation whose settlement fails keeps its reservation ACTIVE (RECONCILIATION_REQUIRED, `SETTLEMENT_<kind>`) until it is settled exactly once. |
| Reconciliation (A4) | `ReconciliationService` (reconcile / rollback / cancel / stale report), the Admin view buttons, and the CLI `system/reseller/reconcile.php`. No automatic timeout release. |
| Authorization (A5) | `activation.create` / `activation.read` matrix. The owner Agent's wallet always pays; an ancestor never spends a descendant's wallet. Only RADIUS Hotspot / PPPoE plans are eligible. |
| Compatibility bridge (A6) | At COMPLETED: the `tbl_user_recharges` row and one `tbl_transactions` row (`INV-<ref>`, retail). No `Package`, hooks or messages. |
| Minimal staff routes (A7) | `activation/create`, `create-post`, `view`, `reconcile`, `rollback`. The bare `activation` route and unknown actions are the read action (view). The pinned `RouteMap` is changed and re-pinned. |
| Refunds (A8) | Deferred to WP5B. Rollback of a COMPLETED activation is refused. |
| Activation vs plan-delete race (WP3B binding) | Real worker race test (§G). |

**Forbidden scope respected:**
- no schema change (no R0007);
- no legacy entrypoint closure (cron auto-renew, customer order, payment callbacks, vouchers, API: WP5B);
- no reseller UX (WP6A) and no reconciliation dashboards (WP6B);
- `ServiceGate` unchanged; no `Package::rechargeUser`;
- no direct ledger, wallet, pricing or RADIUS writes (source guards);
- no production NAS.

## C. Files changed (`git diff --name-status 0c080a7a..c0302b61`, 36 files)

**Runtime, added** (all `NUXR_RUNTIME`-guarded):
- `system/autoload/Reseller/Activation/`: `ActivationState`, `ActivationService`, `ActivationStore`, `ServicePeriod`, `LegacyBridge`, `ReconciliationService`, `ActivationActor`, `ActivationFaults`;
- `system/controllers/activation.php`;
- `ui/ui/admin/activation/create.tpl` and `view.tpl`;
- `system/reseller/reconcile.php` (CLI only).

**Runtime, modified:**
- `Security/Capability.php`: `RESERVED = []`; `activation.create` is mutating; both capabilities are schema-dependent.
- `Security/RoleCapabilityMap.php`: the A5 matrix.
- `Security/RouteMap.php` (**pinned, owner-approved A7**): the `activation` entry only. Not API-allowed.
- `Domain/DomainError.php`: 9 activation reasons.
- `Domain/SchemaReadiness.php`: `tbl_reseller_activation_events` is required.

**Tests, added:**
- `tests/Unit/Wp5a/*` (3 classes);
- `tests/Integration/Wp5a/*` (6 classes);
- `tests/Support/ActivationTestCase.php`.

**Test infrastructure, modified:**
- `tests/Support/finance_worker.php`: worker operations `activate` and `plan_delete`; the Radius / Activation autoload.
- `tests/bootstrap.php`: Activation autoload and the support list.
- `tests/phpunit.xml`: suites `wp5a-unit` and `wp5a-integration`.

**Accepted tests changed (owner-approved, A1 / A5 / A7):**

| Test | Change |
|---|---|
| `tests/Unit/Wp3a/SourceGuardTest.php` | The `Activation` directory is an allowed Finance caller. New `RouteMap` pin `21f46e16…e770`, with a comment. |
| `tests/Unit/Wp3b/PricingSourceGuardTest.php` | The activation-table allowlist gains `ActivationStore` and `ReconciliationService`. `Activation` is allowed to use the pricing classes. The plan-reference writers are `ActivationStore` and `PricingService`. |
| `tests/Unit/Wp3a/FinanceCapabilityTest.php`<br>`tests/Unit/Wp3b/PricingCapabilityTest.php`<br>`tests/Unit/Wp2/PermissionModelTest.php` | `RESERVED` is now empty. |
| `tests/Integration/Wp3a/FinanceSchemaReadinessTest.php` | The required table list includes the events table. |

**Docs:** `docs/WP5A_ACTIVATION_MODEL.md`, this report, `TESTDOX.txt`.

**Not changed:**
- R0001–R0006, the runner, `ServiceGate`, `Tx`, `Balance`, `Package.php`;
- every Finance / Pricing / Radius class (WP5A only calls them);
- `install/radius.sql`, the FreeRADIUS configuration, `dev/test`;
- the spec pack and MANIFEST.

Commits (author `ALI HENDI <ali.hahuba3@gmail.com>`, `Co-Authored-By` trailer):

| Commit | Content |
|---|---|
| `3fc8952c` wp5a: activation capabilities, readiness and domain errors (A5) | security / domain wiring |
| `5536eb11` wp5a: persistent activation state machine and reconciliation (A1–A4, A6, A8) | `Reseller\Activation`, CLI |
| `d9b94624` wp5a: minimal staff activation routes (A7) | controller, templates, RouteMap |
| `839e26df` wp5a: activation tests and owner-approved guard updates | tests |
| `bc17d0c4` wp5a: classify every settlement failure (WP3B F-6) and cover the remaining failure points | fix + 3 tests (§L) |
| `c0302b61` wp5a: the bare activation route is the read action | RouteMap `''`/`'*'` → view, controller default, re-pin, HTTP check (§L) |
| evidence commit | model doc, this report, `TESTDOX.txt` |

## D. Important design notes

- **One sealed INSERT, under the tree lock.**
  - The activation row and its five pricing snapshot columns are inserted once, in the transaction that resolves the price and reserves the funds.
  - A price change or plan delete racing an activation sees either the old or the new state, never a mix (race tests).
- **Provision outside, settle inside.**
  - RADIUS is never called inside a DB transaction.
  - PROVISIONING is persisted before the call, so a crash leaves an observable in-flight state that reconciliation can resolve by reading RADIUS back.
- **Settlement first, then legacy rows, then COMPLETED, atomically.**
  - The legacy recharge row is untouched until COMPLETED, so it remains the "previous state" for rollback.
- **Failure states:**

| Outcome | State | Reservation |
|---|---|---|
| Confirmed RADIUS failure | FAILED | released |
| RADIUS UNKNOWN, read-back mismatch, settlement failure, stale in-flight | RECONCILIATION_REQUIRED | kept ACTIVE |

- **Reconciliation is explicit:**
  - no timeout release;
  - in-flight activations younger than 300 s are not raced;
  - a RADIUS read error changes nothing.
- **The controller is thin.** No finance or RADIUS access; the browser supplies no money or period (source guard).

## E. Migrations / data impact

- No migration, no DDL, no R0007. R0001–R0006 are byte-identical (R0006 `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`).
- New runtime rows:
  - in the existing R0003 tables `tbl_reseller_activations` and `tbl_reseller_activation_events`;
  - finance rows through the WP3A/WP3B services;
  - at COMPLETED, legacy `tbl_user_recharges` / `tbl_transactions` rows.
- `SchemaReadiness` now also requires `tbl_reseller_activation_events`. If it is missing, both activation capabilities are refused for every role.
- **Rollback:** code only (revert the branch). Activation rows are history, and finance rows follow the WP3A immutability.

## F. Test commands + exact results

Canonical runs on `c0302b61`: PHP 8.2.34, PHPUnit 11.5.56, FreeRADIUS 3.2.8 test containers.

| Command | MariaDB 10.11.19 | MySQL 8.0.46 | Registry | Exit |
|---|---|---|---|---|
| `dev/test/run-tests.sh` (2026-10-03 00:23–01:15 UTC, 51m32s) | **OK 594 / 45903** | **OK 594 / 45903** | matches | 0 |
| `dev/test/run-tests.sh --testdox` (01:15–01:49, 34m25s) | **OK 594 / 45903** | **OK 594 / 45903** | matches | 0 |
| `RouteMatrixTest` with `WP2_EVIDENCE=1` (01:49–01:55) | OK 3 / 3090 | OK 3 / 3090 | — | 0 |

**Totals.** Each engine runs **594 tests / 45903 assertions**.
- 594 = 539 (accepted WP4 baseline) + 55 WP5A tests (3 unit classes, 6 integration classes).
- The accepted suites gain assertions only from the source guards and the route matrix covering the new files and routes.

**Testdox** (`TESTDOX.txt`): 1188 passed / 0 failed. All 1078 accepted WP4 testdox lines are present unchanged, plus 110 new lines (55 × 2).

**Route / security matrix: 1462 requests, 1174 denials (all 403), 288 allowed.**
- The rows are byte-identical on both engines.
- Compared with the accepted 1442 / 1164 / 278, every accepted row is unchanged. There are exactly **20 added rows**, all for `activation`:
  - **Bare `activation`** (`activation.read`): sa / ad / A / A1 / F → 302 (no reference: redirect); E (non-enrolled Agent) and rp → 403.
  - **`activation/create`** (`activation.create`): sa / ad / A / A1 / E → 200; F (frozen) and rp → 403.
  - **POST `create-post` / `reconcile` / `rollback`:** F and rp → 403.
- E holds the self-scoped `activation.create` under the accepted C20 rule: only tree scopes need enrollment, the same as `pricing.own_retail`. E therefore sees the form, but every activation by E is refused inside the service before anything is written: E owns no reseller customers, and its wallet is not a valid owner.

**Other checks:**
- **PHP 8.2.34 lint:** 405 tracked non-vendor PHP files (+20 for WP5A): 405 pass, 0 fail.
- **Manifest:** 42/42 OK.
- **Migrations:** R0001–R0006 only. `system/reseller/migrations/` and `install/radius.sql` are unchanged against `0c080a7a`. R0006 is `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`.
- **Secret sweep** over `0c080a7a..HEAD`: no private-key, cloud, VCS, chat, payment or JWT pattern. The only secret-shaped literal is the per-test random stub NAS secret (`wp5a-coa-<random>`). The test-runner secrets are generated at runtime and are not in the repository.

## G. Security / failure cases tested

| Area | Tests (both engines) |
|---|---|
| State machine | Full 15×15 matrix: exactly 24 edges, terminal states, nominal path. Only FINANCIAL_COMMITTING reaches COMPLETED. Provisioned states can never FAIL or be CANCELLED. |
| Period (C14 / C18) | Months / Days / Hrs / Mins / Period, fresh and renewal, parity with the legacy `Package::rechargeUser` expressions, application time zone. Period day clamped (the legacy code wrote `2031-02-31`). Invalid validity refused. |
| Lifecycle | NEW / RENEW / REACTIVATE / CHANGE_PLAN end to end: sealed snapshot, reservation, RADIUS policy, exact debit and commission, legacy rows, event sequence. Server-derived action, and a mismatch is refused. |
| Failure injection | See the breakdown below this table. |
| Authorization | Owner / Sales / Admin pay from the owner's wallet. IDOR, forged ids, ancestor Agent, other tree, unassigned Sales, Report → refused, nothing written. Ineligible customer or plan (unowned legacy customer, banned customer, MikroTik device, VPN type, disabled, unpriced, insufficient credit) → refused. Malformed nonce or action → refused. A frozen reseller cannot start an activation, but its settlement completes. Schema not ready → refused before any write. Read scope: G / T / A. Reconciliation is Admin-only. |
| Idempotency and races (real worker processes) | Double submit with one key → one activation. Two devices → one period. Three concurrent sales with room for one → no overspend. Activation vs price change → the old or the new sealed price, and the debit always equals it (both orders observed). Activation vs plan delete → serialized. A replay never doubles a period, debit or commission. |
| HTTP | Staff flow → 302 to the view. Duplicate POST and reloads → same activation, nothing written. No CSRF token, a non-owner Agent, unassigned Sales → nothing written. Report → 403. GET on mutations → 405. Rollback of COMPLETED refused. CLI `show` / `stale` / `rollback` exit codes. The CLI is unreachable over the web (404). |
| FreeRADIUS 3.2.8 contract | A completed activation authenticates (PAP) with Session-Timeout up to the expiry and the plan rate. A wrong password is rejected. A renewal stacks 30 days more. |
| Source guards | One sealed INSERT, only in `open()` under the tree lock. Snapshot / identity columns never updatable. Events append-only. No `Package` / hooks / messages / direct finance / plan / customer / RADIUS writes. No request input. No float money. The fault hook is test-only. The CLI is CLI-only. Thin controller. |

**Failure injection.** The faults are injected at every activation boundary (`ActivationFaults`), the RADIUS boundary (`RadiusFaults`) and a stub NAS:
- confirmed RADIUS failure → FAILED, released, RADIUS unchanged;
- retryable failure → identical retry; retries bounded (1 + 2);
- lost commit → RECONCILIATION_REQUIRED with the funds kept, then reconciled → COMPLETED once;
- crash before or after the RADIUS call → reconciliation reaches FAILED or COMPLETED, respectively;
- settlement UNKNOWN / TRANSIENT / INTEGRITY / CAPACITY → RECONCILIATION_REQUIRED with funds reserved, then settled once (CAPACITY only after the Admin makes room);
- one deadlock in the settlement → retried, completes once;
- read-back mismatch → funds kept, settled once RADIUS confirms;
- silent NAS on CHANGE_PLAN → completes, and the CoA outcome is recorded;
- rollback restores the previous expiry, or suspends a new service;
- cancel before provisioning; resume.

**Invariants checked after every test:**
- `FinanceInvariants` (ledger / wallet / reservation conservation);
- timeline and reservation-state consistency of every activation;
- the `tbl_customers` checksum.

## H. Git diff summary

`0c080a7a..c0302b61`: 36 files, +3032 / −14 (runtime and templates +1507, tests +1525).

## I. Known risks / TODOs

See `docs/WP5A_ACTIVATION_MODEL.md` §10:
1. **RENEW display window.** RADIUS has the new expiry before the legacy row changes at COMPLETED.
2. **Provisioned but unsettled activations** stay active, with funds reserved, until an operator reconciles them. The tools are the CLI and the Admin view; the dashboards are WP6B.
3. **No refund or reversal** of COMPLETED activations (A8 → WP5B).
4. **Global tree-lock serialization** of activation opens (throughput: WP9).
5. **Reseller retail in legacy revenue reports.**
6. **Disconnect scope.** Disconnect on CHANGE_PLAN / REACTIVATE is best effort; RENEW does not disconnect. No real NAS was tested (WP8).
7. **Pre-WP4 PPPoE rows** keyed by `username` remain.
8. **`reconcile` reads Expiration + group only.** A foreign RADIUS write between provisioning and the read-back makes `reconcile` release the funds and leave the foreign state. In that case the operator should use `rollback`.

**Carried forward:**
- WP0 notes;
- WP4 notes (FreeRADIUS time zone, secrets at rest);
- the harness flakes: the `CliOnlyTest` healthcheck race and `AppServer` port reuse.

## J. Production status

Not touched. No real NAS, RADIUS server or MikroTik was contacted. Everything ran in the isolated `nuxbill-reseller-test` compose project on loopback.

## K. Push status

None.
- `feat/reseller-system-v1` holds the local WP4 metadata commit `0c080a7a` (not pushed).
- `origin/feat/reseller-system-v1` = `d4ba4514`, `origin/main` = `500d3a2d` (both unchanged).

## L. Validation notes

### Validation history (all on this branch)
1. **`839e26df`.** I stopped the first canonical run myself after about 10 minutes, not because of a failure. The planned failure-injection matrix was missing four cases: settlement TRANSIENT, settlement INTEGRITY, read-back mismatch, and a silent NAS.
   - Adding those tests exposed a **defect**: `commitStep` recorded the raw `DomainError` reason for a settlement that ended in an exhausted deadlock retry (`CONCURRENCY_RETRY`) instead of the documented `SETTLEMENT_TRANSIENT`. An INTEGRITY fault (a `LogicException`) was recorded as `SETTLEMENT_UNKNOWN`.
   - The fix is commit `bc17d0c4`, which uses the WP3B `SettlementFailure::classify`. The new test fails on the old code (`CONCURRENCY_RETRY` was reported).
   - In every case the reservation stayed ACTIVE and no money moved; only the recorded kind was wrong.
2. **`bc17d0c4`.** Full plain run: 594 / 44418 on each engine, with **1 failure on both engines**: the accepted `Http\RouteMatrixTest::testRoleRouteMatrix` → `E GET activation (activation.read) must be denied: 200`. The registry matched.
   - Cause: my RouteMap entry sent the bare `activation` route to `create`. Every accepted controller sends its bare route to its read action, and the accepted matrix test maps the read key to the bare route.
   - For the non-enrolled Agent E, who holds the self-scoped `activation.create` but no tree-scoped `activation.read`, the guard therefore allowed the create form under what the matrix treats as the read route.
   - The service still refused any activation by E. This was not a money or authorization escape, but the guard and the matrix disagreed.
   - The fix is commit `c0302b61`: RouteMap `''` / `'*'` → `view`, and the controller default is the view, so the guarded rule and the executed action always agree. The RouteMap pin was updated (A7), and HTTP assertions were added for a frozen read-only Agent and for E.
   - **The accepted `RouteMatrixTest` was not changed.**
3. **`c0302b61`.** Every leg is clean (§F).

### Shared host (AVAIA)
The canonical run took place 00:23–01:55 UTC, with 464 host samples and 77 AVAIA samples.

| Metric | Value |
|---|---|
| Host load1 | max 8.19, mean 4.96 |
| CPU | max 31 %, mean 18 % |
| MemAvailable | min 41191 MiB |
| Swap used | flat 623–631 MiB for the whole run |

Swap was already about 560–630 MiB before the run started and did not grow during it; WP4 reported up to 126 MiB.

**Containers:**
- The 21 non-nuxbill containers are the same set before and after the run.
- Every AVAIA service was `Up` in every sample, except three one-shot containers that are `Exited (0)` throughout and were present before the run:
  - `avaia-v3-minio-init`;
  - `gracious_chaplygin` and `hopeful_lederberg`, two `avaia-v3-prod-api` one-off containers created 2026-10-02 08:29 UTC by the owner's tooling, not by this work.

**Isolation:**
- Only the `nuxbill-reseller-test` compose project and one-shot `nuxbill-reseller-test-*` containers were used.
- No global prune, no daemon change, no AVAIA access.
- Nothing was left behind: no `nuxbill` container, network or volume.

### Harness
- Neither known flake occurred in the final run: the `CliOnlyTest` healthcheck race and the `AppServer` port reuse.
- The harness is unchanged, apart from the WP5A test-support additions listed in §C.

## M. Final state

`STOPPED_WAITING_OWNER_REVIEW`
