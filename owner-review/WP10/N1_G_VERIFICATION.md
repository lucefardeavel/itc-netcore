# N1 = A + G: verification index (semantics and enforcer performance)

For the owner's instruction "verify N1 + G quota semantics and the final enforcer performance evidence". Final candidate: product tree of `9da2015e` (unchanged since; HEAD adds documents / tooling /
evidence). Contract text: `N1_G_COMMERCIAL_QUOTA_CONTRACT.md`. Test counts are from the final full-run log (`evidence/final-v2/full-run-9da2015e.log`, both engines) and the CHR run.

## 1. Option A (mechanism) - what is verified and where

| Statement | Evidence |
|---|---|
| a data quota limits the session natively (Hotspot total; PPPoE per direction) and the login fails closed on malformed / duplicate / unreadable quota rows | `V1QuotaPolicyTest` 7, `V1QuotaFreeRadiusTest` 7 (real FreeRADIUS), `V1PlanQuotaFormTest` 2, `V1VoucherQuotaTest` 2 - both engines |
| the enforcer ends an over-quota session with a confirmed Disconnect, never claims an end it did not observe, retries a no-answer after 15 s and a NAK after 60 s | `V1QuotaEnforcerTest` 8, `V1QuotaOperationsTest` 4; CHR `V1OvershootLabTest` (incl. the CoA-closed fault: confirmed 38 s after crossing) |
| normal-operation overshoot <= `min(R, r x (I + P + c))` with I = 30 s (shipped default), P = 5 s | CHR matrix `evidence/quota/n1/chr/overshoot-table.md` and `evidence/final-v2/chr/` (every case inside the bound; model in `N1_PPPOE_OVERSHOOT.md`) |
| failure-operation overshoot (enforcer / CoA down) up to the whole remaining quota again on PPPoE, reported separately | contract section 2.2; CHR early-cross case (overshoot = the whole quota, cut by the NAS cap) |
| the period owns only rows that arrived after it started (NAS-clock independent) and an activation always opens a new period | `V1PeriodOwnershipTest` 11 (skew +-300 s, same-second activation, ordering, reconnect, delayed Start), CHR `V1QuotaLabTest` exact-renewal case |

## 2. Option G (commercial rule) - each condition of the owner's decision

| Owner condition | Verified by |
|---|---|
| keep the **raw accounting** whole, never deleted or edited | every enforcer / usage test asserts `radacct` unchanged: `V1QuotaUsageTest` (read-only checksum test), `V1QuotaEnforcerTest`; `QuotaUsage` and the set-based `overQuota` only SELECT |
| **actual** network usage and **billable** usage are separate | `QuotaUsage::current` (`actual`, `billable = min(actual, quota)`, `overshoot`); Subscriber 360 card; `quota.php usage`; CHR asserts it on the real NAS (`V1OvershootLabTest`: billable = quota, overshoot = used - quota) |
| **no extra debt or wallet movement** from an overshoot | `V1QuotaUsageTest::testAnOvershootCreatesNoDebtAndARenewalSettlesExactlyOnce` (wallet rows and ledger count identical, `SettlementReadModel::integrity()` empty); CHR: the same assertions around a real over-quota session |
| the overshoot is **not carried** into a renewal / new period | same test (renewal starts with `actual = 0`); `testThePreviousPeriodsRowsAreNotPartOfTheCurrentOne`; defect found and fixed: a same-second activation kept the old boundary (failing first, kept as evidence) |
| **ledger history and financial settlement unchanged** | no finance / pricing / schema / migration file in the product diff (`git diff aba467c4 9da2015e -- system/autoload/Reseller/Finance system/reseller/migrations install/*.sql` is empty); `V1OwnerDecisionGuardsTest::testBillingCodeNeverReadsUsageOrTheOvershoot` (no finance or activation class reads usage) |
| every boundary and financial case tested | exactly at the quota, one over, 64-bit values, previous-period rows, malformed rows (`V1QuotaUsageTest` 7); renewal after an overshoot settles once (`V1RenewalOptionBTest` 3, `V1QuotaUsageTest`) |
| never advertised as a hard byte-exact cap | contract section 1 (allowed / forbidden wording); README text; no percentage anywhere |

## 3. Enforcer performance (final evidence)

`CAPACITY_AND_ACCOUNTING_LOAD.md`: with the enforcer as its own process and the real FreeRADIUS accounting path, both engines, interim 30 s:

| Online quota subscribers | Interim-Updates / s | Frozen-candidate enforcer pass (avg / max) | **Set-based pass (avg / max)** | Lost requests | p99 latency |
|---|---|---|---|---|---|
| 500 | 17 | 0.56 - 0.88 s / 2.3 s | 0.05 s / 0.09 s | 0 | < 140 ms |
| 2,000 | 67 | 1.5 - 1.9 s / 2.9 s | 0.13 - 0.14 s / 0.23 s | 0 | < 170 ms |
| 5,000 | 167 | 5.0 - 5.2 s / 9.4 s (longer than `--every=5`) | 0.28 - 0.48 s / 1.04 s | 0 | < 145 ms |
| 7,500 | 250 | not measured | 0.38 - 0.40 s / 0.95 s | 0 | < 100 ms |

Equivalence of the new scan with the previous one is proven by the differential test `V1QuotaEnforcerEquivalenceTest` (6 seeds x 160 random subscribers, both engines; it failed twice first and found two real differences, kept).
These are lab figures (shared 12-vCPU host, tmpfs database): the **target** sizing is TA-09, whose criteria and evaluator are ready (`docs/V1_TARGET_ACCEPTANCE.md` TA-09, `dev/staging/ta_eval.py`).

## 4. Fresh confirmation on the current HEAD (closure round, 2026-10-18)

No product file differs from `9da2015e` (`git diff 9da2015e HEAD -- system install ui index.php` is empty). Focused set re-run on both engines, real FreeRADIUS harness where it applies
(`evidence/final-v2/fresh-n1g/`): `V1QuotaUsageTest` 7 / 51, `V1QuotaEnforcerEquivalenceTest` 1 / 10, `V1QuotaEnforcerTest` 8 / 64, `V1QuotaOperationsTest` 4 / 62, `V1PeriodOwnershipTest` 11 / 145,
`V1RenewalOptionBTest` 3 / 53, `V1OwnerDecisionGuardsTest` 3 / 14, `V1QuotaPolicyTest` 7 / 77 - **all OK on MariaDB and MySQL**. The long suites were not repeated: no product code changed.
