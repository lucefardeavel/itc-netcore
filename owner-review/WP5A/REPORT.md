# WP5A — Activation Core — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED** (waiting for owner code review after fix round 1). Final state: `STOPPED_WAITING_OWNER_REVIEW`.

Detailed model: `docs/WP5A_ACTIVATION_MODEL.md`. Test evidence: `owner-review/WP5A/TESTDOX.txt`. Plan: approved by the owner ("Approve all A1–A8").

**Owner fix round 1.** The reviewed candidate `608260d940c042ff6e585f9694ad2a4581415168` exposed three issues:
- an unenrolled Agent could reach the create form;
- the global plan catalog was exposed to Agent / Sales;
- reconcile could release reserved funds while a modified, usable service remained live.

They were fixed under the owner decisions "WP5A FIX REQUIRED" (§N).

**Final owner code review.** Candidate `ca1b12c70d16fa75935d11cd266bb9342c5c323b` accepted the eligibility, the scoped catalog and the reconciliation redesign. It found one blocker: rollback re-keyed an unknown prior password and still reported ROLLED_BACK. This is fixed under the decision "FINAL OWNER CODE REVIEW — WP5A FIX REQUIRED" (§O). This report describes the fixed candidate.

## A. Baseline ancestry / accepted prior HEAD / current HEAD / branch / worktree

| Item | Value |
|---|---|
| Upstream ancestry | `d3e0596287322944ee21fcc14dc1798158463140` |
| Accepted prior WP | WP4 **ACCEPTED_WITH_NOTES** at `58e3e45898f813454e9232a9e535ad36b32740e0` (owner decision 2026-10-02) |
| Execution base | `feat/reseller-system-v1` = `0c080a7aa80f032ea1d6a9497fcd9b6cdf8680bc`: `58e3e458` plus the owner-metadata commit "docs: record WP4 owner acceptance and decisions" (local, not pushed) |
| Branch | `wp5a/activation-core`, from `0c080a7a` (nothing reset or rewritten) |
| Reviewed candidate that exposed the issues | `608260d940c042ff6e585f9694ad2a4581415168` (code `c0302b61`) |
| Reviewed candidate where the rollback blocker was found | `ca1b12c70d16fa75935d11cd266bb9342c5c323b` (code `c00eb868`) |
| Implementation HEAD validated (exact-rollback fix) | `06ee827560aecab7ca30febcf974d62ff2afe8d2` |
| Candidate HEAD | the evidence commit `docs(wp5a): exact rollback — model, report and testdox` (SHA in the final response) |
| Worktree | clean after the evidence commit (`tests/vendor/` ignored) |
| Push / PR / merge / deploy | only `wp5a/activation-core` is pushed, after validation (owner-authorized, normal, non-force); no PR, merge or deploy |

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
| **Fix round 1: actor eligibility** | `Capability::PLACEMENT_REQUIRED` = [`activation.create`], enforced in the central `PermissionService`; the A5 scope letters are unchanged. `ActivationService::eligible()` / `assertEligible()` run before any data access. An unenrolled or inconsistent Agent / Sales gets 403 on the form, the preview and the POST. |
| **Fix round 1: plan catalog** | `ActivationService::catalog()` returns only plans saleable for the actor's authoritative reseller identity (id, name, type): the Agent itself, or the owner of a customer in the Sales scope. Admin keeps the administrative list. |
| **Final review: exact rollback** | Every value the restore needs is resolved before any RADIUS write. A prior password is restored only when it equals `customer.password` / `customer.pppoe_password` exactly; otherwise `PRIOR_SECRET_UNRESTORABLE` (ROLLBACK_PENDING, RADIUS untouched, funds held). The reservation is released only after the complete subscriber projection equals the captured prior one. |
| **Fix round 1: reconciliation** | Split by the durable history. VERIFIED reached → financial retry only. Never verified → the canonical `RadiusState` projection is compared with the captured target and prior state; anything else is `EXTERNAL_STATE_AMBIGUOUS`, with the funds held. Rollback restores the captured prior state and releases only after verification. |

**Forbidden scope respected:**
- no schema change (no R0007);
- no legacy entrypoint closure (cron auto-renew, customer order, payment callbacks, vouchers, API: WP5B);
- no reseller UX (WP6A) and no reconciliation dashboards (WP6B);
- `ServiceGate` unchanged; no `Package::rechargeUser`;
- no direct ledger, wallet, pricing or RADIUS writes (source guards);
- no production NAS.

## C. Files changed (`git diff --name-status 0c080a7a..c00eb868`, 41 code / test files plus docs)

**Runtime, added** (all `NUXR_RUNTIME`-guarded):
- `system/autoload/Reseller/Activation/`: `ActivationState`, `ActivationService`, `ActivationStore`, `ServicePeriod`, `LegacyBridge`, `ReconciliationService`, `ActivationActor`, `ActivationFaults`, `RadiusState` (fix round 1);
- `system/controllers/activation.php`;
- `ui/ui/admin/activation/create.tpl` and `view.tpl`;
- `system/reseller/reconcile.php` (CLI only).

**Runtime, modified:**
- `Security/Capability.php`: `RESERVED = []`; `activation.create` is mutating; both capabilities are schema-dependent.
- `Security/RoleCapabilityMap.php`: the A5 matrix.
- `Security/RouteMap.php` (**pinned, owner-approved A7**): the `activation` entry only. Not API-allowed.
- `Domain/DomainError.php`: 9 activation reasons, plus `PRIOR_STATE_NOT_CAPTURED` and `PRIOR_SECRET_UNRESTORABLE` (final review).
- `Domain/SchemaReadiness.php`: `tbl_reseller_activation_events` is required.
- **Fix round 1:**
  - `Security/Capability.php`: `PLACEMENT_REQUIRED`.
  - `Security/PermissionService.php`: an Agent / Sales principal that is not validly placed has no scope for a `PLACEMENT_REQUIRED` capability.
  - `Radius/RadiusRepository.php` (accepted WP4 code; additive only): `snapshotUser`, `snapshotGroup`, `restoreUser`, the private `syncExact` and `orderGroups`. Existing methods are unchanged.
  - `Radius/RadiusProvisioner.php` (additive): `restore()`, verified by read-back.

**Tests, added:**
- `tests/Unit/Wp5a/*` (4 classes; fix round 1 adds `RadiusStateTest`);
- `tests/Integration/Wp5a/*` (8 classes; fix round 1 adds `ActivationCatalogTest` and `ActivationReconciliationTest`);
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
- every Finance / Pricing class (WP5A only calls them); the Radius classes only gain the additive methods above;
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
| `608260d9` docs(wp5a): activation model, owner-review report and testdox | **the reviewed candidate** |
| `39ba63be` wp5a: activation actor eligibility, saleable plan catalog, reconciliation by durable history | fix round 1, runtime |
| `c00eb868` wp5a: tests for eligibility, catalog and reconciliation scenarios A-I | fix round 1, tests |
| `ca1b12c7` docs(wp5a): owner fix round 1 — model, report and testdox | **the reviewed candidate of the final review** |
| `7e65cb6c` wp5a: exact rollback fails closed on an unrestorable prior secret | final review, runtime |
| `06ee8275` wp5a: exact rollback tests (customer / PPPoE password, unknown secret, no password, repeats, ambiguous) | final review, tests |
| evidence commit | model doc, this report, `TESTDOX.txt` |

## D. Important design notes

- **One sealed INSERT, under the tree lock.**
  - The activation row and its five pricing snapshot columns are inserted once, in the transaction that resolves the price and reserves the funds.
  - A price change or plan delete racing an activation sees either the old or the new state, never a mix (race tests).
- **Provision outside, settle inside.**
  - RADIUS is never called inside a DB transaction.
  - PROVISIONING is persisted before the call, so a crash leaves an observable in-flight state that reconciliation can resolve by reading RADIUS back.
- **Settlement first, then legacy rows, then COMPLETED, atomically.**
- **Captured RADIUS states (fix round 1).** Before any RADIUS write, the PROVISIONING event stores two canonical projections (`RadiusState`):
  - the **prior** state (first attempt);
  - the exact **target**.

  Each covers every managed check / reply row and every group-membership row of the provisioned key, plus the managed rows of the plan group. The password is stored only as a salted digest. If the prior state cannot be read, provisioning does not start. Verification compares the whole projection with the target.
- **Failure states:**

| Outcome | State | Reservation |
|---|---|---|
| Confirmed RADIUS failure | FAILED | released |
| RADIUS UNKNOWN, read-back mismatch, settlement failure, stale in-flight | RECONCILIATION_REQUIRED | kept ACTIVE |

- **Reconciliation is explicit, split by durable history:**

  | Situation | Mode | Outcome |
  |---|---|---|
  | VERIFIED evidence exists | FINANCIAL | Retry the exact settlement; RADIUS is not consulted. |
  | Never verified | EXTERNAL | Current == target → settle once. Current == prior → NOT_APPLIED, released. Anything else → `EXTERNAL_STATE_AMBIGUOUS`: nothing changes, funds held. |

  - **Rollback** restores the captured prior subscriber rows exactly. It is automatic only when every prior secret can be reconstructed exactly from the customer's own passwords; otherwise it stays ROLLBACK_PENDING with `PRIOR_SECRET_UNRESTORABLE` before any write. The reservation is released only when the complete subscriber projection equals the captured prior one.
  - There is no timeout release, in-flight activations younger than 300 s are not raced, and a RADIUS read error changes nothing.
- **Eligibility in two layers.**
  - The central `PermissionService` makes the RouteGuard answer 403 before the controller runs.
  - `ActivationService` repeats the check before any data access.
  - The accepted `RouteMatrixTest` derives its expectations from `PermissionService`, so it stays correct without modification.
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

Final canonical run on `06ee8275` (exact-rollback fix): PHP 8.2.34, PHPUnit 11.5.56, FreeRADIUS 3.2.8 test containers.

| Command | MariaDB 10.11.19 | MySQL 8.0.46 | Registry | Exit |
|---|---|---|---|---|
| `dev/test/run-tests.sh` (2026-10-03 09:47–10:26 UTC, 38m41s) | **OK 618 / 46587** | 615 OK + 3 errors in `Wp3b\PlanDeleteGuardTest`, all `no CSRF token on admin: 404 … /?_route=admin was not found`: the known `AppServer` port-reuse harness flake (§L) | matches | 1 |
| `phpunit` MySQL plain leg, clean rerun of the same suite (11:12–11:38, 25m42s) | — | **OK 618 / 46587** | — | 0 |
| `PlanDeleteGuardTest` in isolation, MySQL, 3 runs | — | OK 3 / 55, three times | — | — |
| `dev/test/run-tests.sh --testdox` (10:26–11:04, 38m30s) | **OK 618 / 46587** | **OK 618 / 46587** | matches | 0 |
| `RouteMatrixTest` with `WP2_EVIDENCE=1` (11:04–11:09) | OK 3 / 3096 | OK 3 / 3096 | — | 0 |

**Totals.** Each engine runs **618 tests / 46587 assertions**.
- 618 = 539 (accepted WP4 baseline) + 79 WP5A tests.
- The exact-rollback fix adds 5 test methods. The 6 owner-required cases are covered; case 5 (repeated rollback) is asserted inside cases 3 and 6.

**Testdox** (`TESTDOX.txt`): 1236 passed / 0 failed (618 × 2).
- All 1078 accepted WP4 lines are present.
- Every line of the `ca1b12c7` candidate is still present.

**Route / security matrix: 1465 requests, 1178 denials (all 403), 287 allowed.** Unchanged by the exact-rollback fix.
- The rows are byte-identical on both engines.
- Compared with the accepted 1442 / 1164 / 278, **every accepted row is unchanged (0 removed or altered)**, and exactly **23 rows are added**, all for `activation`:

```
A    GET  activation          activation.read   ALLOW 302      A    GET  activation/create  activation.create ALLOW 200
A1   GET  activation          activation.read   ALLOW 302      A1   GET  activation/create  activation.create ALLOW 200
ad   GET  activation          activation.read   ALLOW 302      ad   GET  activation/create  activation.create ALLOW 200
sa   GET  activation          activation.read   ALLOW 302      sa   GET  activation/create  activation.create ALLOW 200
F    GET  activation          activation.read   ALLOW 302      F    GET  activation/create  activation.create DENY  403
E    GET  activation          activation.read   DENY  403      E    GET  activation/create  activation.create DENY  403
rp   GET  activation          activation.read   DENY  403      rp   GET  activation/create  activation.create DENY  403
E    POST activation/create-post / reconcile / rollback   activation.create DENY 403   (3 rows)
F    POST activation/create-post / reconcile / rollback   activation.create DENY 403   (3 rows)
rp   POST activation/create-post / reconcile / rollback   activation.create DENY 403   (3 rows)
```

- Against the reviewed candidate (1462 / 1174 / 288):
  - `E GET activation/create` changed from ALLOW 200 to **DENY 403**;
  - E's three POST rows are new: allowed POSTs are skipped by the matrix, and E's are now denied;
  - so +3 requests, +4 denials, −1 allowed.

**Other checks:**
- **PHP 8.2.34 lint:** 409 tracked non-vendor PHP files (+24 for WP5A): 409 pass, 0 fail.
- **Source guards** (WP3A, WP3B, WP4, WP5A): pass on both engines in the final run.
- **Manifest:** 42/42 OK.
- **Migrations:** R0001–R0006 only. `system/reseller/migrations/` and `install/radius.sql` are unchanged against `0c080a7a`. R0006 is `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`.
- The only guard edits in WP5A are the A1 / A7 ones listed in §C.
- **Secret sweep** over `0c080a7a..HEAD`: no private-key, cloud, VCS, chat, payment or JWT pattern. The secret-shaped literals are:
  - the attribute-name constant `'Cleartext-Password'`;
  - the per-test random stub NAS secret;
  - the unit-test fixture password `pw-secret-1`;
  - the status word `'restored'`.

  Runner secrets are generated at runtime.

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
- rollback restores the captured prior state exactly: a previous service with its own password, or the empty state of a NEW customer. An unrestorable prior secret is refused before any write (§O);
- cancel before provisioning; resume.

**Fix round 1 tests.** Every case below runs on both engines.

| Area | Tests |
|---|---|
| Actor eligibility (`ActivationCatalogTest`, `ActivationCapabilityTest`, `ActivationHttpTest`) | Unenrolled Agent: catalog / preview (existing and missing customer alike) / create → FORBIDDEN with nothing written; over HTTP, bare `activation` 403, `activation/create` 403 with and without parameters (no plan catalog, no form), POST `create-post` 403 with nothing written. A frozen-ancestor Agent and an unplaced Sales → FORBIDDEN. In the unit matrix, enrolled=false / inconsistent / managerId=null each mean no `activation.create` for Agent and Sales; the A5 scope letters are unchanged; other self-scoped capabilities of an unplaced Agent are unchanged; Admin / SuperAdmin are unchanged. |
| Plan catalog | **Agent:** only plans saleable for itself. Absent: resale disabled, no base cost, disabled by an ancestor, retail below wholesale, malformed legacy retail, zero wholesale, invalid wholesale chain (a real `moveSubtree`), plan disabled, MikroTik device, VPN type, Balance plan. Rows are exactly id / name / type. A plan outside the catalog is not previewed (NOT_FOUND, no reason). **Sales:** no customer → no plans; its customer → the owner's saleable plans only; another reseller's customer, a customer assigned to it but owned elsewhere, a missing id and an injection string → the same NOT_FOUND. The approved Sales flow completes on the owner wallet. **Admin / SuperAdmin:** the administrative list, unchanged, and the reason on preview. **HTTP:** the Agent sees `wp5a_http` but not `wp5a_unpriced` and no economics words; Sales sees plans only for its assigned customer; Admin sees both. |
| Reconciliation A–I (`ActivationReconciliationTest`) | **A/B:** VERIFIED → settlement failure → foreign expiry or extra group → reconcile → COMPLETED, COMMITTED, one debit and one commission, RADIUS untouched, `mode: FINANCIAL`. **A/I:** a settlement that keeps failing → RR, ACTIVE, zero ledger rows, twice, then COMPLETED once. **C:** UNKNOWN commit + exact target → VERIFIED → COMPLETED once; a second reconcile is refused with no double debit. **D:** crash before the RADIUS call (NEW, and RENEW over a real previous service) + exact prior → FAILED NOT_APPLIED, RELEASED, zero ledger rows. **E/F:** UNKNOWN + foreign expiry or extra group → `EXTERNAL_STATE_AMBIGUOUS`, RR, ACTIVE, zero ledger rows, no legacy rows, RADIUS byte-identical, the differing component named without values; repeated reconcile → no new event. **G:** ambiguous → rollback → prior restored (including group memberships), RELEASED exactly once; a second rollback or reconcile is refused. **H:** a rollback whose commit is lost or whose write is rejected → ROLLBACK_PENDING with funds held, then a repeated rollback completes. **Capture:** durable, salted password digest, no customer password anywhere in the activation history. **Unreadable RADIUS:** provisioning never starts (FUNDS_RESERVED, `RADIUS_READ_FAILED`), resumable. |
| Projection (`RadiusStateTest`) | The target equals the provisioner contract; the password never appears and the digest is salted per activation; equality survives the JSON round trip and is independent of order; a password, expiry or extra-group difference is detected; a malformed projection never matches; diagnostics name components only. |

**Final review tests** (`ActivationReconciliationTest`, both engines):

| # | Case | Result |
|---|---|---|
| 1 | Prior password = `customer.password` (RENEW over a real previous service) | Exact password restored, previous rows identical, complete projection == captured prior, RELEASED, ROLLED_BACK, zero ledger rows. |
| 2 | Prior password = `customer.pppoe_password` (PPPoE) | Exact PPPoE password restored, projection == prior, RELEASED. |
| 3 | Prior password set directly in RADIUS (foreign / unknown) | `PRIOR_SECRET_UNRESTORABLE`; RADIUS row-for-row identical, ids included; ROLLBACK_PENDING; reservation ACTIVE; zero ledger rows; balances unchanged; no legacy finalization; the operator message says the prior authentication state cannot be restored automatically. |
| 4 | Prior state has a managed row but no password | Exactly that row restored, no password invented, RELEASED, `password: none`. |
| 5 | Rollback repeated three times after the refusal | The same refusal each time: no RADIUS write, no release, no new event after the first. |
| 6 | Ambiguous external state + unknown prior password | Refused with `PRIOR_SECRET_UNRESTORABLE`; the RADIUS password stays the activation's, never a random value. |

The reconciliation tests A–I stay green.

**Invariants checked after every test:**
- `FinanceInvariants` (ledger / wallet / reservation conservation);
- timeline and reservation-state consistency of every activation;
- the `tbl_customers` checksum.

## H. Git diff summary

`0c080a7a..c00eb868`: 41 code / test files, runtime and templates +2062, tests +2112. Fix round 1 alone (`608260d9..c00eb868`): 19 files, +1261 / −119.

## I. Known risks / TODOs

See `docs/WP5A_ACTIVATION_MODEL.md` §10:
1. **RENEW display window.** RADIUS has the new expiry before the legacy row changes at COMPLETED.
2. **Provisioned but unsettled activations** stay active, with funds reserved, until an operator reconciles them. The tools are the CLI and the Admin view; the dashboards are WP6B.
3. **No refund or reversal** of COMPLETED activations (A8 → WP5B).
4. **Global tree-lock serialization** of activation opens (throughput: WP9).
5. **Reseller retail in legacy revenue reports.**
6. **Disconnect scope.** Disconnect on CHANGE_PLAN / REACTIVATE is best effort; RENEW does not disconnect. No real NAS was tested (WP8).
7. **Pre-WP4 PPPoE rows** keyed by `username` remain.
8. **Plan group rows are not rolled back.** They are shared plan configuration. Reconcile still requires them to match exactly before it settles or releases, so a foreign plan edit leaves the activation ambiguous, with the funds held.
9. **Unrestorable prior secret.** When the captured prior password is not exactly one of the customer's own current passwords (set directly in RADIUS, a suspension value, or a password changed since), rollback is refused: ROLLBACK_PENDING with `PRIOR_SECRET_UNRESTORABLE` and the funds held.
   - The digest is only a verifier and cannot reconstruct the value.
   - There is no automatic exit until an operator acts, or until a future owner-approved secure recovery mechanism is designed (no encryption or schema change in WP5A).
10. **Activations without a captured state.** These are activations that reached provisioning before fix round 1. They are always ambiguous when never verified, and rollback refuses them with the funds held. They exist only in development databases.
11. **Catalog under the tree lock.** The catalog resolves each candidate plan under the global tree lock: correct but serialized (WP9).
12. **Ambiguous activations hold reseller credit until an operator acts.** There is no automatic path out, by design; WP6B adds the views.

**Carried forward:**
- WP0 notes;
- WP4 notes (FreeRADIUS time zone, secrets at rest);
- the harness flakes: the `CliOnlyTest` healthcheck race and `AppServer` port reuse.

## J. Production status

Not touched. No real NAS, RADIUS server or MikroTik was contacted. Everything ran in the isolated `nuxbill-reseller-test` compose project on loopback.

## K. Push status

- **Pushed:** only `wp5a/activation-core`, to origin, with a normal non-force push of this evidence commit (owner-authorized after a successful validation). The pushed SHA and the local == origin check are in the final response.
- **Not pushed:** `feat/reseller-system-v1` (holds the local WP4 metadata commit `0c080a7a`), `main` and `wp4/radius-hardening`.
- **Origin state:** `origin/feat/reseller-system-v1` = `d4ba4514` and `origin/main` = `500d3a2d`, both unchanged.
- No PR, merge, tag or deploy.

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
3. **`c0302b61`.** Every leg is clean: 594 / 45903 per engine, route matrix 1462 / 1174 / 288. This is the commit the owner reviewed as candidate `608260d9`, with evidence.
4. **Owner review of `608260d9`.** Three product issues were found (§N). They are fixed in `39ba63be` and `c00eb868`. Focused runs on both engines were clean, and the new tests were proven to fail on the previous runtime (§N).
5. **`c00eb868`.** Every leg is clean: 613 / 46426 per engine; route matrix 1465 / 1178 / 287. This is the commit the owner reviewed as candidate `ca1b12c7`, with evidence.
6. **Final owner code review of `ca1b12c7`.** One blocker: the rollback re-keyed an unknown prior password (§O). It is fixed in `7e65cb6c` and `06ee8275`. The focused rollback and reconciliation tests (28) are clean on both engines, and the new tests were proven to fail on the previous runtime (§O).
7. **`06ee8275`.** Final canonical run:
   - MariaDB is clean in both the plain and the testdox runs; the MySQL testdox run is clean; the route matrix, lint and registry are clean.
   - The MySQL **plain** leg had 3 errors, all in the accepted WP3B `PlanDeleteGuardTest`, all `RuntimeException: no CSRF token on admin: 404 … The requested resource /?_route=admin was not found`.
   - This is the known `AppServer` port-reuse harness flake (classified in the WP3B report §L.1 and seen in WP4 on `AppSurfaceTest`): a stopped `php -S` server's orphaned worker answers on a reused random port. The class does not involve activation code.
   - The class then passed 3/3 in isolation on MySQL, and a clean rerun of the complete MySQL plain leg passed (618 / 46587, exit 0).
   - The harness is not patched; that needs owner approval.

### Shared host (AVAIA)
The final canonical run took place 09:47–11:09 UTC, with 427 host samples and 71 AVAIA samples. The MySQL rerun followed at 11:12–11:38.

| Metric | Value |
|---|---|
| Host load1 | max 8.37, mean 5.07 |
| CPU | max 46 %, mean 21 % |
| MemAvailable | min 39355 MiB |
| Swap used | flat 577–582 MiB |

**Containers:**
- Every AVAIA service was `Up` in every sample, except `avaia-v3-minio-init` and the owner one-offs `gracious_chaplygin` / `hopeful_lederberg` (`Exited (0)` throughout, present before the run).
- **Observed and not touched:** the owner's tooling started new `avaia-v3-prod-api:4a86d886…` one-off containers during the run. `tender_liskov` was seen once as `Created` at 10:41 and was gone later. `elegant_swartz` was created at 11:07:42 and was `Up (unhealthy)` afterwards.
- These have no compose project and are not related to this work. They are reported for the owner's information only.

**Isolation:**
- Only the `nuxbill-reseller-test` compose project and one-shot `nuxbill-reseller-test-*` containers were used.
- No global prune, no daemon change, no AVAIA access.
- Nothing was left behind.

### Harness
- The known `AppServer` port-reuse flake occurred once, in the final run's MySQL plain leg (item 7 above); the isolated reruns and the full leg rerun were clean. The other known flake, the `CliOnlyTest` healthcheck race, did not occur. A harness fix (terminate the server's process group, or check the server identity at start) remains an owner decision.
- The harness is unchanged, apart from the WP5A test-support additions listed in §C.

## N. Owner fix round 1 (decisions "WP5A FIX REQUIRED")

**Findings on the reviewed candidate `608260d9`**, reproduced on that code in this session:

1. **Unenrolled-Agent create form.**
   - An Active Agent that is not enrolled (seed E) got `GET activation/create` → **200**.
   - The form listed the global catalog of enabled RADIUS Hotspot / PPPoE plans (id, name, type).
   - Its preview and POST were refused inside the service ("Not found." / "not enrolled"), with nothing written.
   - Cause: under the accepted C20 rule only tree scopes require enrollment, and the Agent's `activation.create` is S.
2. **Plan catalog exposure.** Every holder of `activation.create` (enrolled Agents, Sales) saw the global enabled-RADIUS plan list, including plans it cannot sell.
3. **Reconciliation ambiguity.**
   - `reconcile` compared only the radcheck `Expiration` and the group list. Any difference meant `NOT_APPLIED`: reservation released, FAILED (terminal; rollback refused afterwards).
   - Reproduced on `608260d9` for both a verified-then-unsettled activation and a never-verified one, with the expiry moved to 2035 or an extra group added. The result was always FAILED / RELEASED, nothing charged, with the password, the plan group and the (foreign) expiry still live in RADIUS.

**Corrections (`39ba63be`, tests `c00eb868`):**

| Owner decision | Implementation |
|---|---|
| 1 (option B) | `Capability::PLACEMENT_REQUIRED` + `PermissionService` (central; the RouteGuard answers 403 before the controller) + `ActivationService::eligible/assertEligible` (before any data, in catalog / preview / create) + `revalidateActor` in `open()` (database, inside the transaction). The A5 scope letters are unchanged. |
| 2 | `ActivationService::catalog()` (described in §B and model §7). No economics. The Sales authority is the customer row's owner; nothing is taken from the request. |
| 3–4 | Reconciliation split by `ActivationStore::wasVerified` (the durable VERIFIED event). External mode uses `RadiusState` against the captured target / prior. Ambiguous → `EXTERNAL_STATE_AMBIGUOUS` (`ActivationStore::note`, same-state event, error columns). |
| 5 | Rollback restores the captured prior subscriber rows exactly (`RadiusRepository::restoreUser` via `RadiusProvisioner::restore`, verified by read-back); release only after SUCCESS; uncertain → ROLLBACK_PENDING, funds held. |
| 6 | The view page and the CLI explain the ambiguity (CLI exit 4). Nothing is presented as "not applied"; no values or passwords appear in diagnostics. |
| 9 | **No schema change.** The existing append-only event history already distinguishes "VERIFIED reached" from "never verified", and the existing `metadata` JSON column of the PROVISIONING event holds the captured projections. `WP5A_RECONCILIATION_SCHEMA_DECISION_REQUIRED` was **not** needed. |

**Proof on the previous runtime.** The new tests were run on MySQL with `system/` and `ui/` checked out from `608260d9`; the worktree was restored afterwards.

| Test | Previous runtime |
|---|---|
| `ActivationHttpTest::testEligibilityAndCatalogOverHttp` | fails: `unenrolled Agent: no create form [] — Failed asserting that 200 is identical to 403` |
| `ActivationCatalogTest::testUnplacedAgentIsRefusedBeforeAnyData` | fails: the central guard granted `activation.create` |
| `ActivationReconciliationTest::testVerifiedDeliveryIsReconciledFinanciallyDespiteForeignEdits` | fails: expected `COMPLETED`, actual `FAILED` |
| Never-verified probe (temporary, not committed) | Previous runtime: `[expiry] … after: FAILED NOT_APPLIED reservation=RELEASED … RADIUS password=present Expiration=31 Dec 2035 23:59:59` and `[group] … FAILED NOT_APPLIED reservation=RELEASED … password=present`. New runtime: both → `RECONCILIATION_REQUIRED EXTERNAL_STATE_AMBIGUOUS reservation=ACTIVE`. |

On the fixed runtime all four pass (§F).

## O. Final owner code review (decision "FINAL OWNER CODE REVIEW — WP5A FIX REQUIRED")

**Finding on the reviewed candidate `ca1b12c7`.** `restorePrior()` resolved the prior `Cleartext-Password` from its digest against `customer.password` / `customer.pppoe_password`. When neither matched, it wrote a new random password and verified that new state. It then released the reservation and reported ROLLED_BACK. That is not an exact restoration: a previously working subscriber could be disabled while the activation reported "rolled back, nothing charged".

**Correction (`7e65cb6c`):**

| Owner requirement | Implementation |
|---|---|
| Validate before writing | `ReconciliationService::restorePlan()` resolves every row to write, every prior password included, **before** `RadiusProvisioner::restore()` and before any state change other than the refusal record. |
| Unknown prior secret | `PRIOR_SECRET_UNRESTORABLE` (a new `DomainError` reason with an operator message). RADIUS is not modified, the reservation is not released, the activation is (or stays) ROLLBACK_PENDING, and there is no ledger movement or legacy finalization. The refusal is recorded once (event plus error columns). A repeated rollback re-checks, writes nothing and records nothing new. A missing capture is handled the same way (`PRIOR_STATE_NOT_CAPTURED`). |
| Known password | The exact plaintext of `customer.password` or `customer.pppoe_password`, matched with `hash_equals` against the digest. After the restore RADIUS is read back. Release and ROLLED_BACK happen **only** if the complete subscriber projection (`RadiusState::sameSubscriber`) equals the captured prior projection; otherwise `ROLLBACK_VERIFY_MISMATCH`, ROLLBACK_PENDING, funds held. |
| No prior password | The exact absence is restored; nothing is invented. |
| Digest | It remains a verifier only and is documented as insufficient by itself for exact rollback. No encryption, new secret, schema change or migration. |
| Operator surface | The view page shows that the exact prior authentication state cannot be restored automatically, that RADIUS was not changed and that the reservation remains held. The CLI prints `refused: PRIOR_SECRET_UNRESTORABLE …` (exit 2). |

**Proof on the previous runtime.** The new tests were run on MySQL with `system/` and `ui/` from `ca1b12c7`; the worktree was restored afterwards:
- `testUnknownPriorPasswordIsRefusedBeforeAnyWrite` → `expected PRIOR_SECRET_UNRESTORABLE: attempt 1`: the old rollback succeeded.
- `testAmbiguousStateWithUnknownPriorPasswordStaysUntouched` → the same.

Both pass on the fixed runtime (§F).

## M. Final state

`STOPPED_WAITING_OWNER_REVIEW`: `WP5A_EXACT_ROLLBACK_FIXED_WAITING_OWNER_FINAL_REVIEW`. WP5B has not started.
