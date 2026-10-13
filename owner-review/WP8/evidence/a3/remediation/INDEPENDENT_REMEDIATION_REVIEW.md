# WP8 A3 remediation: independent read-only review (2026-10-13) and disposition

## Provenance

- **Reviewer:** a separate review agent, without the implementer's context. Read-only: it wrote nothing and ran nothing.
- **Reviewed delta:** the first remediation, `remediation-product-first.diff` (pre-remediation → intermediate).
- **Inputs:**
  - the earlier review: `../verify/INDEPENDENT_CODE_REVIEW.md`;
  - the final pre-acceptance verification report;
  - the plan;
  - the new tests.
- **Reviewer verdict:** DEFECTS_FOUND. No CRITICAL or HIGH; 1 MEDIUM; 3 LOW; INFO items.
  - FIX-1, 3, 5, 6 and 7 are correct and minimal.
  - FIX-2 and FIX-4 fix their findings but introduced R-1 and R-2.
  - D-3 closes the race under REPEATABLE READ; R-3 is a residual under READ COMMITTED, and R-4 is an R2 bypass through loose comparison.
  - Nothing outside the approved scope.

## Findings and disposition

Every MEDIUM and LOW finding was fixed inside the approved FIX/D-3 scope, in the same 7 files. Each fix has a regression test that **fails on the reviewed intermediate code** (`intermediate-review-tests.out`: exit 1, 1, 1) and passes on the final code on both engines (`focused-both-engines-run3.out`).

| ID | Sev | Finding (summary) | Disposition | Regression test |
|---|---|---|---|---|
| R-1 | MEDIUM | `renameCheck` deduped the pairs by `from`, so the target of a second pair with the same old key went unchecked, although the plan's re-provisioning writes it (`applyUser` uses the collation) | **Fixed.** The pre-check runs over **every** pair in run order; the device still renames each old key once (`LegacyReprovisionService.php:246-283`) | `RenameRemediationTest::testR1TheTargetOfAPairRenamedOnlyOnceIsStillChecked` (exact orphan → IDENTITY_TAKEN; equivalent orphan → AMBIGUOUS_IDENTITY; nothing written) |
| R-2 | LOW | The FIX-2 exemption covered the keys of **later** pairs, so the pre-check passed what the write transaction then refused (R2: after the save) | **Fixed.** Cumulative exemption: pair *i* exempts only the sources and targets of pairs 0..i-1, exactly what its write transaction will see. F3 chains still pass | `testR2AKeyOfALaterPairIsNotExemptForAnEarlierPair` (clean pre-write refusal `REFUSED_AMBIGUOUS_IDENTITY`; nothing written) |
| R-3 | LOW (isolation-dependent) | The in-transaction re-check `exactRows($to)` is a non-locking read; under READ COMMITTED, a row committed between it and the `DELETE` would be deleted | **Fixed.** When the caller does not own the target, a `DELETE` that removes any row aborts the rename (`RADIUS_IDENTITY_TAKEN`, rolled back) under any isolation (`RadiusRepository.php:192-197`). Deployment default is REPEATABLE READ (no override in the code) | unit source guard `testTheTargetCheckIsInsideTheRenameTransaction` (`!$replaceTarget && $deleted > 0`). A true concurrent READ COMMITTED interleaving is not reproduced (documented) |
| R-4 | LOW | R2 `$pppoeDiff` used the legacy loose `!=` while the PPPoE username is always saved: a numeric-looking change (`4200` → `42e2`) skipped every check | **Fixed.** Strict string comparison (`customers.php:713-715`) | `RadiusLegacyRenameTest::testANumericLookingPppoeUsernameIsComparedStrictly` (refused; both customers and RADIUS unchanged) |
| R-5 | INFO | R2 has no per-row reuse: a chained edit could send a session two Disconnects | **Fixed** (owner FIX-4 asks for no duplicate Disconnect per radacct session): one Disconnect per radacctid inside one rename scope (`LegacyReprovisionService.php:84, 302, 358-367`). In R2 the case-only part of a chain is in any case refused by the upstream username check (collation) | `testR5OneDisconnectPerSessionInsideALegacyRenameScope` (stub receives 1) |
| R-6 | INFO | FIX-3 also stops `change_username(a, a)` on MikroTik devices, which used to force a reconnect | **Recorded.** This was anticipated by the approved FIX-3 text. A no-op rename is not a rename | — |
| R-7 | INFO | `CHECK_UNAVAILABLE` also covers application-DB errors, but the message said "RADIUS database" | **Fixed:** "The identity check could not be completed (database unavailable); nothing was changed." | (F7 test asserts the reason code) |
| R-8 | INFO | The R2 pre-check and device loop had no ORDER BY | **Fixed:** both use `order_by_asc('id')` (R1 already uses `ORDER BY id`) | covered by the R2 tests |
| R-9 | INFO (pre-existing) | Plan-order effects when a chained pair moves another plan's freshly written rows (the same family as B-2) | **Not changed:** documented with D-2/B-2 | — |

## Residual limits outside the rename path's control (reviewer list, kept as residuals)

1. **No uniqueness in the database.** There is no DB unique constraint on `tbl_customers.username` / `pppoe_username` (a schema change is out of scope). Creation paths check only username against username, and R2 shares no lock with R1. A colliding customer's own activation or sync (`applyUser`, not a rename) can still overwrite a policy.
2. **Customers who already share a key.** The `from` side of a rename moves the shared rows; only the other customer's sessions are protected (holder → AMBIGUOUS).
3. **Writes after the rename commits,** by a writer that holds the key through such a collision.
4. **Several application databases on one RADIUS database:** `nameTakenByOther` cannot see the other application's customers. Non-own targets stay protected by the in-transaction check.
5. **R2 in-transaction refusal lands after `$c->save()`** (I2 pattern). It is reachable only through a concurrent write between the pre-check and the device step.
6. **Two Radius plans on the same key / plan-order effects** (B-2, R-9), and stale own rows under a former username (F5-keys).
7. **`add_customer` outside renames** (e.g. customer creation) writes onto any orphan rows of a fresh key.
