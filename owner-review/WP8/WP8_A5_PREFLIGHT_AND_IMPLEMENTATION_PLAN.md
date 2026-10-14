# WP8 A5 — Legacy Removal / Deactivation Authority (`R-WP8-CARRY-01`): Preflight & Implementation Plan

**Status: PLAN — technical approach APPROVED IN PRINCIPLE by the owner (2026-10-12); execution NOT authorized.**
- A5 is scheduled after A3 and A4 (Architecture Lock order). No code, test or configuration was changed.
- No commit, push, merge or deploy.

| Item | Value |
|---|---|
| Date | 2026-10-12 (first written as "A3"; reclassified the same day, see §0) |
| Branch / baseline | `wp8/integration-poc` @ `32a162d6e91012ca2638b13332b129ece29331c7` = origin |
| Subject | `R-WP8-CARRY-01` — legacy direct removal / deactivation of reseller-owned service bypasses activation / reversal authority (OPEN / PRE-EXISTING / MEDIUM / must close before production) |
| Method | Read-only review of the repository, `owner-review/WP8/OWNER_DECISIONS.md` and the Architecture Lock of 2026-10-09. No test was run for this plan |

## 0. Stage label (OD-A3-0, resolved)

**Owner decision (2026-10-12).** Keep the original Architecture Lock order and do not renumber. Where the official
document confirms the mapping, this study is the **A5** plan.

**Reference that settles the mapping:**
- **File:** `docs/architecture/owner-lock-2026-10-09/IMPLEMENTATION_ROADMAP.md`. It is on the local branch
  `docs/owner-architecture-lock-2026-10-09`, commit `8b0ee33672c7bbb06c75a56336ed229a8f9328a8` ("docs: sync owner
  architecture lock 2026-10-09 …"). That branch is not merged into `wp8/integration-poc`.
- **Integrity:** `SaaS_RADIUS_vs_SAS4_AUDIT_MANIFEST.sha256` of that folder was re-checked on 2026-10-12 from the
  commit: **10/10 OK**, `IMPLEMENTATION_ROADMAP.md` = `f98edf4392d76b821dc7749e5eaf760f749a2b151f162e29ff83d39d32e531bb`.
- **Text (Phase A, verbatim):**
  - "### A3. Close `R-WP8-CARRY-02`" — "Old RADIUS identity/session survives rename."
  - "### A4. Close `R-WP8-CARRY-03`" — "Accounting timezone mismatch."
  - "### A5. Close `R-WP8-CARRY-01` before production" — "Direct service removal/deactivation bypass of activation/reversal
    authority is a documented production blocker, not a deferrable launch issue."
- **Consistent with:**
  - `OWNER_DECISIONS.md` D1 (2026-10-09): "A1 → A2 → A3 → A4 → A5 → S7 → S8";
  - the lock backlog: AUD-P0-03 = CARRY-02, AUD-P0-04 = CARRY-01, AUD-P0-05 = CARRY-03.

**Result:** **A3 = CARRY-02, A4 = CARRY-03, A5 = CARRY-01.** The analysis below is unchanged; only the label changed.

## 1. Baseline: removal and deactivation paths today

`remove_customer()` is called from exactly six places (`grep`, `system/`). Reseller-owned = `tbl_customers.manager_id IS NOT NULL`.

| # | Path (actor) | Code | Authority checks today | Effect |
|---|---|---|---|---|
| P1 | Admin "delete active plan" `plan/delete/<recharge id>` (SuperAdmin / Admin; RouteMap `POST` + `service.plan_edit`, CSRF) | `system/controllers/plan.php:429-452` | role + RouteMap only. **No `ServiceGate`, no ownership check** | hook `delete_customer_active_plan`; device `remove_customer` (Radius: suspend + CoA disconnect, or the plan's restricted "expired plan"); **`tbl_user_recharges` row deleted** |
| P2 | Admin "deactivate" `customers/deactivate/<customer>/<plan>` (SuperAdmin / Admin; `customer.deactivate`, CSRF) | `system/controllers/customers.php:266-298` | role + RouteMap + CSRF. **No `ServiceGate`** | device `remove_customer`; recharge `status='off'`, expiry = now; log + Telegram |
| P3 | Customer portal "deactivate" `home&deactivate=<recharge id>` (the logged-in customer; RouteMap `dashboard#deactivate` `POST`, CSRF; GET refused 405 per `tests/Http/CsrfTest.php:68`) | `system/controllers/home.php:198-221` | customer login + own username + CSRF. **No `ServiceGate`** | same as P2, by the customer |
| P4 | Cron expiry (CLI only) | `system/cron.php:72-99` | none needed: lifecycle end at the saved expiry | device `remove_customer`; recharge `status='off'`. Reseller-owned auto-renew is already refused (`:107`, `AutoRenewPolicy`) |
| P5 | Admin plan edit with a plan change `plan/edit-post` | `system/controllers/plan.php:454-504` | **`ServiceGate::guardCustomer(..., 'planEditPost')` at `:477`, before any effect** | old plan removed, new plan added (unassigned customers only) |
| P6 | Admin customer delete `customers/delete/<id>` | `system/controllers/customers.php:487-528` | `CustomerService::assertDeletable` **before** any device effect. A customer referenced by a project table is refused `REFERENCED`. `tbl_reseller_activations.customer_id`, its events and the ledger `actor_customer_id` are FKs `ON DELETE RESTRICT` (R0003 / R0004) | every recharge removed on the device, then the customer, its recharges and fields deleted in one audited transaction |

**Not removal paths, or already guarded (checked, not in scope):**
- Plan definition delete (`services.php`) runs under `PlanGuard::deleteSerialized` (tree lock; `REFERENCED` refused).
- NAS / router / pool / bandwidth deletes do not remove a subscriber.
- `home.php` connect / disconnect (`:233/236`) acts on the session only (OBS-W8-3, unchanged).
- The API (`system/api.php`) includes the same controllers, so P1, P2, P5 and P6 behave the same through it.
- Plugins and hooks are Phase B ("plugin / direct-device authority red-team").

## 2. Which paths bypass reseller-ownership authority

- **The canonical removal of a reseller activation** is `ActivationReversalService::reverse`:
  - Admin / SuperAdmin with `activation.reverse` scope G, and a reason;
  - a safety proof, then the RADIUS restore, then the financial compensation and the legacy recharge restore;
  - audited.
- **The other canonical end** is expiry (P4).

| Path | Bypass? | Basis |
|---|---|---|
| **P1** | **YES** | No ownership gate; it deletes the recharge row that the activation's reversal context points to |
| **P2** | **YES** | No ownership gate; it changes the recharge row and RADIUS outside the activation / reversal state machine |
| **P3** | **YES** | The reseller's customer can end the reseller's service outside the reversal saga |
| P4 | No (expiry is canonical) | See race risk R-4 (§7) |
| P5 | No | Gated at `:477` |
| P6 | No for customers with activation history | Refused `REFERENCED` before any effect. A reseller-owned customer **without** activations can be deleted by Admin; this stays unchanged (owner decision 5) |

P1–P3 are exactly the three paths named in `R-WP8-CARRY-01`. No further path was found.

## 3. Impact on RADIUS, RouterOS, accounting and finance

The impact is derived from the code and from existing accepted tests. No end-to-end run of P1–P3 against a reseller
activation was made.

| Area | Effect of P1 / P2 / P3 on a reseller-owned customer with a COMPLETED activation | Evidence |
|---|---|---|
| RADIUS | **Radius** device `remove_customer`: `RadiusProvisioner::suspend` plus CoA disconnect, or the restricted "expired plan" | `system/devices/Radius.php:71-82, 171-175` |
| RouterOS | Live session ended by CoA (Radius device). Activations are always `device = Radius` | `MikrotikPppoe.php:100-123`; `ActivationService` requires Radius |
| Accounting | `radacct` is never written or deleted by these paths | `RadiusRepositoryTest::testSuspendAndRemoveNeverTouchAccounting` |
| Legacy recharge | P1 deletes the row; P2 and P3 set `status='off'` and the expiry to now | controllers above |
| **Reversal** | The activation stays **COMPLETED** but is **no longer reversible**: the safety proof refuses `REVERSAL_EXTERNAL_STATE_CHANGED` (`legacy_recharge`, `radius:*`) | `ActivationReversalService.php:124-170`; `LegacyBridge::unchangedSince`; tests `testLegacyRechargeDriftRefusesWithoutAnyWrite`, `testExternalRadiusEditRefusesWithoutAnyWrite` |
| Finance | No ledger, wallet or reservation write. The paid wholesale period is cut and **no compensation path remains** | `ActivationReversalService` header; P1–P3 code |
| Next activation | P2 / P3 → `REACTIVATE` (starts now); P1 → `NEW`. The remaining paid period is lost | `ActivationService::deriveAction` (`:525-535`) |
| Detection | `reconcile.php drift` reports `RADIUS_DRIFT`, after the fact only | `DriftReport.php:18-48` |
| Audit | Legacy log line (and Telegram for P2 / P3) only; **no `AuditLog` event, no reason, no activation reference** | controllers above |

**Severity: MEDIUM (unchanged).** These paths never grant unpaid service, and an outsider cannot reach them.

## 4. Owner decisions (2026-10-12) — recorded

| # | Decision |
|---|---|
| 1 | **Refuse P1, P2 and P3 for reseller-owned customers before any hook, device call or database mutation** |
| 2 | **No emergency-stop exception outside the reversal** for now |
| 3 | **Refuse self-deactivation (P3) for reseller-owned customers only**, not for all customers |
| 4 | **Unassigned legacy customers keep their current behaviour unchanged** |
| 5 | **No change to the deletion rights of a customer without activations** (P6) |
| 6 | Hiding UI buttons is not part of the core security fix; study only if needed |
| 7 | **R-4** (cron vs RENEW) stays a separate check; no fix without proof and approval |

The earlier questions OD-A3-1 … OD-A3-5 are answered by decisions 2, 3, 6, 5 and 7.

## 5. Approved minimal change (to execute in A5 only)

| Change | Place | Detail |
|---|---|---|
| C1 | `system/controllers/plan.php` `case 'delete'` | after the recharge row is found, **before** `run_hook('delete_customer_active_plan')`: `ServiceGate::guardCustomer($d['customer_id'], 'planDelete', 'plan/list')` |
| C2 | `system/controllers/customers.php` `case 'deactivate'` | after the CSRF check, **before** the recharge lookup: `ServiceGate::guardCustomer($id_customer, 'customerDeactivate', 'customers/view/' . (int) $id_customer)` |
| C3 | `system/controllers/home.php` deactivate branch | after `$bill` is found, **before** the device call: guard the logged-in customer `$user['id']` **and** the row's `customer_id`, operation `portalDeactivate`, redirect `home` |
| C4 | `system/autoload/Reseller/Domain/ServiceGate.php` | docblock only (also covers legacy removal of reseller-owned service). No logic change |
| C5 | `docs/WP5B_LEGACY_CLOSURE_MODEL.md` addendum + `OWNER_DECISIONS.md` additive record | the rule and the allowed P4 / P5 / P6 behaviour |

- `ServiceGate` fails closed when the schema is not ready, as every OD-11 gate does.
- **Not part of A5:** auto-reversal, an operator suspension path, UI changes, and any change to P4 / P5 / P6, plugins,
  RADIUS, schema or the finance services.

## 6. Additional check: `ServiceGate` / `manager_id` with prior or in-flight activations and ownership changes

**Question (owner):** can a customer with earlier or in-flight reseller activations reach P1–P3 because its ownership
changed? Method: code and existing tests only.

| Fact | Evidence |
|---|---|
| An activation is only ever opened for a reseller-owned customer; an unassigned one is refused `CUSTOMER_NOT_RESELLER_OWNED` | `ActivationService::authorizeCustomer` (`ActivationService.php:585-600`) |
| Owner transfer always writes a validated, non-null owner (`assertOwner`) under the tree lock, audited | `CustomerService::transferOwner` (`CustomerService.php:124-147`); `tests/Http/CustomerOwnershipTest.php::testOwnerTransferIsAdminOnlyAndClearsForeignSales` |
| Legacy owner assignment only goes `NULL → owner`, never the reverse (`NOT_UNASSIGNED`) | `CustomerService::assignLegacyOwner` (`:155-190`); `testExplicitLegacyOwnerAssignmentOnly` |
| `manager_id` is written only by `CustomerService::create`, `transferOwner` and `assignLegacyOwner`; the legacy customer edit does not write it | `grep "manager_id" system/` (controllers: only `customers.php:596`, the create call) |
| The owner cannot disappear under the customer: `fk_customers_manager … ON DELETE RESTRICT`; staff delete refuses a referenced user | `R0005_customer_ownership.php:30`; `StaffService` delete (`REFERENCED`) |
| A customer with activation rows cannot be deleted (FK `RESTRICT`) | `R0003_activation_foundation.php:80`; `testCustomerReferencedByResellerDomainIsNotDeleted` |
| `ServiceGate::isBlockedCustomerId` blocks on **any** non-null owner, read fresh, and fails closed when the schema is not ready or on error | `ServiceGate.php:27-46` |

**Conclusion: no gap found.** Every customer that has (or had) a reseller activation keeps a non-null `manager_id`
through any transfer, so C1–C3 refuse it whichever reseller owns it now. An in-flight activation does not change
this; a transfer only changes which non-null owner is recorded.

**Limits of this check:**
- A direct database edit that sets `manager_id = NULL` on such a customer would bypass every OD-11 gate, not only C1–C3.
  That is outside the application's authority model. `reconcile.php drift` would **not** show it either, because
  `PERIOD_NOT_BACKED` / `RADIUS_DRIFT` cover reseller-owned customers only. Recorded as a limit, not as a finding.
- Rolling back the schema (R0005 down) makes the schema not ready, and the gate then refuses all.
- **Observation, not examined further, not in scope:** `transferOwner` does not check for an in-flight activation of the
  customer. This concerns which wallet pays, not the removal gate. No change is proposed without evidence and a
  separate decision.

## 7. Risks

| ID | Risk | Status | Mitigation |
|---|---|---|---|
| R-1 | Operators lose the quick "deactivate" for reseller customers | by design (decision 2) | Reversal or expiry; documented |
| R-2 | Schema-not-ready installs refuse P1–P3 for all customers | consequence of the fail-closed gate | same as every OD-11 gate |
| R-3 | Ownership changes between the gate check and the removal | no gap (§6); a transfer keeps a non-null owner | — |
| **R-4** | Cron expiry vs a RENEW in flight | **NOT PROVEN**; separate check (decision 7) | T7 only; no fix without proof and approval |
| R-5 | `tests/Unit/Wp5b/Wp5bSourceGuardTest.php` reads `plan.php`, `customers.php`, `home.php` | to verify at implementation | scoped updates only if affected, reported |
| R-6 | Plugins or hooks calling devices directly | out of scope (Phase B) | — |

## 8. Tests (at A5 execution; both engines)

| ID | Test | Expectation |
|---|---|---|
| T1 | Http, P1 / P2 / P3 on a reseller-owned customer with a COMPLETED activation | refused. **Unchanged:** RADIUS rows, recharge row, transactions, ledger, wallets, activation. One `service.refused` audit; no hook side effect |
| T2 | After T1: `ActivationReversalService::reverse` | succeeds (reversibility preserved) |
| T3 | Same routes, **unassigned** customer | today's behaviour exactly |
| T4 | Schema not ready | refused, nothing changed |
| T5 | API path (admin token) for P1 / P2 | refused identically |
| T6 | Role and negative checks | RouteMap matrix unchanged |
| T6b | Ownership change (§6): reseller-owned customer with a COMPLETED activation, `transferOwner` to another reseller, then P1–P3 | still refused |
| T7 | R-4 concurrency (fault hook between RENEW provisioning and COMPLETED, then the cron expiry step) | establishes whether the race is real; reported only |
| T8 | Partial failure | refusal before any effect; unassigned failure behaviour unchanged |
| T9 | Unit source guard, mutation-checked | every `remove_customer(` in `system/controllers` is preceded by `ServiceGate::guardCustomer` or `CustomerService::assertDeletable`, or is cron expiry |
| T10 | Regression | full harness (MariaDB + MySQL), RouteMatrix, CSRF, WP5B reversal / drift suites |

## 9. Files, implementation and rollback

**Files:**
- `system/controllers/plan.php`, `customers.php`, `home.php`: C1–C3, a few lines each.
- `ServiceGate.php`: docblock.
- New tests: `tests/Http/LegacyRemovalGateTest.php`, `tests/Integration/Wp5b/LegacyRemovalReversibilityTest.php`,
  `tests/Unit/Wp8/LegacyRemovalSourceTest.php`.
- Docs: C5.
- Evidence: `owner-review/WP8/evidence/a5/`.

**Not touched:** `install/radius.sql`, `install/freeradius/*`, A2-R / H1-A / H-2B / Option G, schema, finance and
activation services, cron, the development pack.

**Order:**
1. Backup.
2. T9 and T1 written first; they must fail on the baseline.
3. C1–C4.
4. Tests and full harness.
5. Docs and report.
6. Stop for review.

**Rollback:** revert one commit. No migration and no configuration change.

## 10. Unchanged open items

- A2-R SQL Exhaustion: `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`, no re-test.
- PENDING Recovery.
- AV-1, AV-2, AV-3; MAC-H1A.
- V-1.
- `R-WP8-CARRY-02` (A3), `R-WP8-CARRY-03` (A4), `R-HF-CARRY-01`.
- S7, S8.

WP8 stays open.

## 11. Addendum (2026-10-13): final implementation readiness, re-checked on `8ed4cb3d`

**Scope:** additive only; §0–§10 above are unchanged. The re-check was read-only, on the new baseline
`wp8/integration-poc@8ed4cb3da4e125ad017393d3bb70a1d5ab0120a3` (= origin, after A3 `2628c632` and A4 `8ed4cb3d`).
Nothing was run, changed or committed.

### 11.1 What still holds (verified against the current code)

| Item | Result |
|---|---|
| `remove_customer(` call sites | still exactly six: `plan.php:443` (P1), `customers.php:286` (P2), `home.php:206` (P3), `cron.php:83` (P4), `plan.php:504` (P5), `customers.php:515` (P6) |
| P1 / P2 / P3 code | **unchanged since the plan baseline** (`git diff 32a162d6..8ed4cb3d` for `plan.php` and `home.php` is empty). **`customers.php`:** A3 changed only edit-post (hunks from line 710), not deactivate (`:266`) or delete (`:487`). **P1–P3:** still no `ServiceGate`, no ownership check, and an effect order of hook → device → row |
| P5 / P6 gates | `plan.php:477` `guardCustomer(..., 'planEditPost')` and `customers.php:498` `assertDeletable`, both before any effect |
| RouteMap | P1 `plan/delete` POST `service.plan_edit`; P2 `customers/deactivate` POST `customer.deactivate`; P3 `dashboard#deactivate` POST customer (`RouteMap.php:50, 88, 102`) |
| `ServiceGate` API | `guardCustomer(id, operation, redirect)`. **Ownership:** read fresh, and the gate fails closed when the schema is not ready or the read fails. **On refusal:** it records `service.refused` (audit + log), then web redirect / API `success:false` |
| Impact (§3) | **A3:** changed `Radius.php` only for the rename path. `remove_customer` / `customerDeactivate` behaviour is unchanged (only the line numbers moved). **A4:** no time logic in P1–P3. §3 stands |
| Ownership (§6) | `CustomerService::transferOwner` / `assignLegacyOwner` / `assertDeletable` and `ActivationService::authorizeCustomer` (`CUSTOMER_NOT_RESELLER_OWNED`) are unchanged. The §6 conclusion stands |
| Owner decisions §4 (1–7) | unchanged; not re-opened |

### 11.2 Proven differences (and the resulting plan amendments)

| ID | Finding | Amendment |
|---|---|---|
| A5-D1 | **C4 conflicts with an accepted guard.** `tests/Unit/Wp3a/SourceGuardTest.php` pins `system/autoload/Reseller/Domain/ServiceGate.php` (and `RouteMap.php`) byte-for-byte by SHA-256 (`PINNED`). A docblock edit would fail that guard, and updating the pin would weaken it | **Drop C4.** `ServiceGate.php` and `RouteMap.php` are **not touched**. The rule is documented in C5 (`docs/WP5B_LEGACY_CLOSURE_MODEL.md` addendum + `OWNER_DECISIONS.md`) and in short comments at C1–C3. No functional change |
| A5-D2 | **No existing test covers P1–P3 for unassigned customers.** The only references are `CsrfTest:68` (P3 GET → 405) and `Subscriber360Test:53` (no deactivate link for Agent) | T3 is written **first** and must pass on the **baseline**: a characterisation of today's unassigned behaviour. It must stay identical after C1–C3 |
| A5-D3 | **T7 (cron vs RENEW probe) is cron work.** The current owner direction is "do not expand to Cron"; decision 7 already keeps R-4 a separate check | **T7 is removed from A5** and R-4 stays a separate open item. No cron test or change in A5 |
| A5-D4 | Stale references | baseline `32a162d6` → `8ed4cb3d`. `ActivationReversalService` external-state refusal is now at `:117`, `:258-283` (was "124-170"). `Radius.php` `customerDeactivate` is now at `:263-267` (was "171-175"). Behaviour unchanged |
| A5-I1 (INFO) | The refusal text is the pre-existing generic `ServiceGate::MESSAGE` ("…temporarily unavailable for reseller-managed customers (pending WP5B)"). It is shown to staff and, for P3, to the customer | **Unchanged:** the file is pinned (A5-D1), and the text is not a security property. Wording follow-up only if the owner asks |

### 11.3 Final change set

| # | Change |
|---|---|
| C1 | `plan.php` `case 'delete'`: after `$d` is found, **before** `run_hook('delete_customer_active_plan')`: `ServiceGate::guardCustomer($d['customer_id'], 'planDelete', 'plan/list')` |
| C2 | `customers.php` `case 'deactivate'`: after the CSRF check, **before** the recharge lookup: `ServiceGate::guardCustomer($id_customer, 'customerDeactivate', 'customers/view/' . (int) $id_customer)` |
| C3 | `home.php` deactivate: after `$bill` is found, **before** the device call: `guardCustomer($user['id'], 'portalDeactivate', 'home')` **and** `guardCustomer($bill['customer_id'], 'portalDeactivate', 'home')`. The row is matched by username, so both ids are checked |
| C5 | `docs/WP5B_LEGACY_CLOSURE_MODEL.md` addendum; `OWNER_DECISIONS.md` additive record (at acceptance) |

**Not touched:**
- `ServiceGate.php`, `RouteMap.php`, `cron.php`;
- schema, finance and activation services;
- `install/`, FreeRADIUS, A1 / A2-R / H1-A / H-2B / A3 / A4 / Option G;
- plugins and UI.

### 11.4 Tests (both engines; written first, the T1 / T9 set must fail on the baseline, T3 must pass on it)

| ID | Proves |
|---|---|
| T1 | **Setup:** P1, P2, P3 (web) against a reseller-owned customer with a COMPLETED activation. **Expected:** refused, and one `service.refused` audit per attempt (operation name). **Nothing changes:** byte-identical checksums of `tbl_user_recharges`, `tbl_transactions`, ledger / wallet / reservation / activation / event tables, `radcheck` / `radreply` / `radusergroup` / `radacct`. No device call (probe) and no hook effect. **Service unchanged:** the RADIUS policy is still active (not suspended) and no CoA is sent |
| T1b | P3 when the logged-in customer is unassigned but the matched recharge row's `customer_id` is reseller-owned (the same username): refused |
| T2 | After T1, `ActivationReversalService::reverse` succeeds (reversibility kept) |
| T3 | **Before and after:** P1 / P2 / P3 on an **unassigned** customer, compared with the baseline: device called, recharge deleted or `off`, log line; no `service.refused` |
| T4 | **Schema not ready:** P1–P3 refused for every customer, nothing changed (the existing fail-closed rule; R-2) |
| T5 | API (admin token) P1 / P2: same refusal (`success:false`), nothing changed |
| T6 | RouteMap / role matrix and CSRF suites unchanged |
| T6b | Ownership transfer to another reseller, then P1–P3: still refused |
| T8 | **Partial failure:** the guard's DB read fails (fault) → refused before any effect |
| T9 | **Source guard, mutation-checked:** every `remove_customer(` under `system/controllers` is preceded in its branch by `ServiceGate::guardCustomer` or `CustomerService::assertDeletable`; in P1 the guard comes before `run_hook` |
| T10 | **Regression:** full harness on MariaDB and MySQL, frozen tree; Wp3a `SourceGuardTest` pins still pass; WP5B reversal / drift suites; manifest, secret and protected-file checks |

**CHR:** not required, because the gate refuses before any device or RADIUS effect and T1 proves no device call. On request, one lab run can show the live PPPoE session surviving a refused P2.

### 11.5 Acceptance criteria

1. **Reseller-owned customers:** P1, P2 and P3 are refused before any hook, device call, database write or financial effect. The data and the service are byte-identical (T1, T1b, T5, T6b, T8).
2. **Reversal:** the activation stays reversible (T2).
3. **Unassigned customers:** behaviour identical to the baseline (T3).
4. **Fail-closed:** a schema that is not ready refuses (T4).
5. **Other gates:** no other gate, pinned file or accepted behaviour changes. The full harness is green on both engines with real exit codes, and incomplete or skipped tests are not counted.
6. **Evidence:** the new tests fail on the baseline (failure evidence kept), and the diff is limited to §11.3.

### 11.6 Rollback

- **Before commit:** restore `plan.php`, `customers.php` (CRLF preserved) and `home.php` from the pre-change backup, and delete the new tests and the doc addendum.
- **After commit:** revert the one A5 commit.
- No migration, data, configuration or RADIUS state to undo; the gate only refuses.

### 11.7 Owner decisions

**None new.** Decisions 1–7 (§4) cover the change. The amendments A5-D1 … D4 apply them to the current code without changing scope.

**Readiness:** `READY_FOR_IMPLEMENTATION_AUTHORIZATION`.
