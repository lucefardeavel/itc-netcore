# O-DL: deadlock refusal of concurrent activations on one wallet - study and classification

Owner direction (2026-10-18): do **not** implement automatic cancellation of financially HELD operations; preserve the fail-safe behaviour and the locked finance / ledger / retry contracts;
reproduce and compare old and new candidates under identical conditions, on both engines; record frequency, retry outcome, reservation state, user-facing result and operator recovery time; confirm
no double charge, unintended grant, lost reservation, orphaned operation or indefinite HELD state; evaluate minimal safe improvements inside the approved contracts; **never release a reservation
merely because a request returned a deadlock error.** Nothing in this study changes product code: the improvement variants ran only in scratch worktrees.

## 1. Method (identical for every candidate)

| Item | Value |
|---|---|
| Workload | `dev/wp10/DeadlockStudyTest.php`: waves of **12 concurrent worker processes**, each a NEW activation of a different customer by the **same manager (one wallet)**; 25 waves = 300 operations per process run; 6 - 18 runs per candidate and engine |
| Candidates | **old** = the previous frozen candidate `e34906d6`; **new** = the final candidate (product tree of `9da2015e`, worktree at `be6f65e1`); each in a *fresh* worktree (an earlier comparison showed that the location of a worktree changes timing, so both sides use the same kind of fresh worktree) |
| Databases | the same disposable containers for every run: MariaDB 10.11.19 and MySQL 8.0.46, `REPEATABLE-READ`, `innodb_lock_wait_timeout` 50, `innodb_deadlock_detect` ON; `innodb_print_all_deadlocks` ON (disposable server) |
| Order | interleaved (old, new, old, new ...) to cancel time-of-day noise |
| Measured | operations, refusals and their reason, InnoDB deadlocks in total (absorbed by the bounded retries or not), the state a refusal leaves, the customer's own retry, operator recovery (stale floor + action time), final accounting |
| Pass criteria of every run | after the operator recovery: `COMPLETED` activations = `COMMITTED` reservations = `ACTIVATION_DEBIT` entries; no ACTIVE reservation; no non-terminal activation; ledger / wallets reconcile; no customer served twice |

## 2. Result: old versus new, both engines

| candidate | engine | runs | operations | InnoDB deadlocks | refusals (CONCURRENCY_RETRY) | refusal rate / op | runs with a refusal | failed runs |
|---|---|---|---|---|---|---|---|---|
| new | mariadb | 6 | 1800 | 174 | 5 | 0.0028 | 5 | 0 |
| new | mysql | 6 | 1800 | 0 | 0 | 0.0000 | 0 | 0 |
| old | mariadb | 6 | 1800 | 146 | 4 | 0.0022 | 4 | 0 |
| old | mysql | 6 | 1800 | 1 | 0 | 0.0000 | 0 | 0 |

### What the refusals left behind, the customer retry and the operator recovery

| candidate | engine | held activations | states | reservations | service rows | ledger entries | customer retry | reasons / user message | resume ms (n, avg, max) | cancel ms (n, avg, max) | final: completed = committed = debits, active reservations, non-terminal, integrity |
|---|---|---|---|---|---|---|---|---|---|---|---|
| new | mariadb | 5 (of 5 refusals) | {'FUNDS_RESERVED': 5} | {"['ACTIVE']": 5} | 0 | 0 | {'REFUSED:ACTIVATION_IN_PROGRESS': 5} | {('CONCURRENCY_RETRY', 'The financial operation could not complete because of concurrent activity; please try again.'): 5} | 5, 100, 172 | 0, 0, 0 | OK (6 runs) |
| new | mysql | 0 (of 0 refusals) | {} | {} | 0 | 0 | {} | {} | 0, 0, 0 | 0, 0, 0 | OK (6 runs) |
| old | mariadb | 4 (of 4 refusals) | {'FUNDS_RESERVED': 4} | {"['ACTIVE']": 4} | 0 | 0 | {'REFUSED:ACTIVATION_IN_PROGRESS': 4} | {('CONCURRENCY_RETRY', 'The financial operation could not complete because of concurrent activity; please try again.'): 4} | 4, 97, 144 | 0, 0, 0 | OK (6 runs) |
| old | mysql | 0 (of 0 refusals) | {} | {} | 0 | 0 | {} | {} | 0, 0, 0 | 0, 0, 0 | OK (6 runs) |

new-mariadb: 174 logged deadlock(s); statement pairs: {'INSERT INTO tbl_reseller_activation_events x UPDATE tbl_reseller_activations': 161, 'INSERT INTO tbl_reseller_activation_events x INSERT INTO tbl_reseller_activations': 6, 'UPDATE tbl_reseller_activations': 5, 'INSERT INTO tbl_reseller_activations x UPDATE tbl_reseller_activations': 2}

new-mysql: 0 logged deadlock(s); statement pairs: {}

old-mariadb: 146 logged deadlock(s); statement pairs: {'INSERT INTO tbl_reseller_activation_events x UPDATE tbl_reseller_activations': 137, 'INSERT INTO tbl_reseller_activation_events x INSERT INTO tbl_reseller_activations': 7, 'INSERT INTO tbl_reseller_activations x UPDATE tbl_reseller_activations': 1, 'UPDATE tbl_reseller_activations': 1}

old-mysql: 0 logged deadlock(s); statement pairs: {}


Totals (1,800 operations per candidate and engine, 6 interleaved runs each):

| | MariaDB old | MariaDB new | MySQL old | MySQL new |
|---|---|---|---|---|
| refusals | 4 (0.22 %) | 5 (0.28 %) | 0 | 0 |
| InnoDB deadlocks | 146 | 174 | 1 | 0 |
| failed runs | 0 | 0 | 0 | 0 |

(An earlier, unstructured comparison gave the same picture: the previous candidate also refused an operation in a fresh worktree, and ran clean in 8 of 8 runs in another worktree - location and timing change the odds, not the mechanism.)

## 3. Classification

**It is neither a regression nor test variability: it is an existing contention limit of the accepted code on MariaDB.**
- Old and new behave the same (refusal rate 0.22 % versus 0.28 % per operation, about 0.08 - 0.10 deadlocks per operation, all absorbed by the bounded retries except the rare triple).
- It is reproducible and specific to **MariaDB**: MySQL 8.0 showed 0 / 1 deadlocks in 3,600 operations.
- Mechanism (the server logged about 1,660 deadlock dumps over all structured runs): about 92 % are `INSERT INTO tbl_reseller_activation_events` versus `UPDATE tbl_reseller_activations` (the status transition) of concurrent activations of the same wallet owner - InnoDB gap / insert-intention locks on the `(status, updated_at)` index and the foreign-key shared lock on the owner's `tbl_users` row. The accepted code retries a deadlock victim as a whole unit twice (`FinanceTx::MAX_DEADLOCK_RETRIES = 2`, pinned by the accepted WP3A test); a third consecutive deadlock surfaces as the refusal `CONCURRENCY_RETRY`.
- The earlier passes of the load test were luck, not evidence of absence.

## 4. What a refusal leaves behind (verified in every refusal, both candidates, 18 refusals in the structured runs)

| Aspect | Observed |
|---|---|
| Result for the caller | refusal `CONCURRENCY_RETRY`: "The financial operation could not complete because of concurrent activity; please try again." |
| Activation state | `FUNDS_RESERVED` (timeline CREATED > VALIDATED > FUNDS_RESERVED); never half-granted |
| Reservation | exactly one, `ACTIVE` (funds **held**); never a second; never released by the refusal |
| Service | **none** (0 RADIUS rows); **no** ledger entry (nothing charged) |
| The customer's own retry | refused `ACTIVATION_IN_PROGRESS` (the held activation blocks a second one for that customer): no double reservation, no double charge. The message "please try again" is therefore misleading for this case (a UX note, section 7) |
| Visible to operators | `reconcile.php stale` lists it after 300 s; health `activations_stale` WARNING after 5 min, CRITICAL after 30 min; held reservations WARNING after 1 h, CRITICAL after 24 h (`W10HealthCheckTest`) |
| Operator recovery | after the 300 s stale floor: **resume** completes it (measured 109 ms on average, 191 ms maximum, 18 resumes: exactly one settlement, the service granted, no second charge) or **cancel** (release, CANCELLED; nothing charged or granted: proven in `StagingLoadTest` and the load runs) |
| Final accounting of every run | completed = committed reservations = ACTIVATION_DEBIT; no ACTIVE reservation; no non-terminal activation; integrity empty - **OK in all structured runs on both engines** |

Therefore: no double charge, no unintended service grant, no lost reservation, no orphaned operation. The HELD state is **bounded by the operator action and by alerts, not by time**: it never expires by itself (by design: a reservation is never released because of an error) - an operator who ignores the alerts leaves funds held, visibly.

## 5. Improvements evaluated (inside the approved architecture; none implemented)

All on MariaDB, interleaved with the baseline, in scratch worktrees (`be6f65e1` plus one change in `FinanceTx.php`):

| candidate | engine | runs | operations | InnoDB deadlocks | refusals (CONCURRENCY_RETRY) | refusal rate / op | runs with a refusal | failed runs |
|---|---|---|---|---|---|---|---|---|
| bo | mariadb | 18 | 5400 | 554 | 0 | 0.0000 | 0 | 0 |
| new | mariadb | 18 | 5400 | 564 | 8 | 0.0015 | 8 | 0 |
| r4 | mariadb | 6 | 1800 | 222 | 1 | 0.0006 | 1 | 0 |

### What the refusals left behind, the customer retry and the operator recovery

| candidate | engine | held activations | states | reservations | service rows | ledger entries | customer retry | reasons / user message | resume ms (n, avg, max) | cancel ms (n, avg, max) | final: completed = committed = debits, active reservations, non-terminal, integrity |
|---|---|---|---|---|---|---|---|---|---|---|---|
| bo | mariadb | 0 (of 0 refusals) | {} | {} | 0 | 0 | {} | {} | 0, 0, 0 | 0, 0, 0 | OK (18 runs) |
| new | mariadb | 8 (of 8 refusals) | {'FUNDS_RESERVED': 8} | {"['ACTIVE']": 8} | 0 | 0 | {'REFUSED:ACTIVATION_IN_PROGRESS': 8} | {('CONCURRENCY_RETRY', 'The financial operation could not complete because of concurrent activity; please try again.'): 8} | 8, 121, 191 | 0, 0, 0 | OK (18 runs) |
| r4 | mariadb | 1 (of 1 refusals) | {'FUNDS_RESERVED': 1} | {"['ACTIVE']": 1} | 0 | 0 | {'REFUSED:ACTIVATION_IN_PROGRESS': 1} | {('CONCURRENCY_RETRY', 'The financial operation could not complete because of concurrent activity; please try again.'): 1} | 1, 107, 107 | 0, 0, 0 | OK (6 runs) |

bo-mariadb: 554 logged deadlock(s); statement pairs: {'INSERT INTO tbl_reseller_activation_events x UPDATE tbl_reseller_activations': 515, 'INSERT INTO tbl_reseller_activation_events x INSERT INTO tbl_reseller_activations': 34, 'UPDATE tbl_reseller_activations': 3, 'INSERT INTO radgroupreply': 1, 'INSERT INTO tbl_reseller_activations x UPDATE tbl_reseller_activations': 1}

new-mariadb: 564 logged deadlock(s); statement pairs: {'INSERT INTO tbl_reseller_activation_events x UPDATE tbl_reseller_activations': 518, 'INSERT INTO tbl_reseller_activation_events x INSERT INTO tbl_reseller_activations': 41, 'UPDATE tbl_reseller_activations': 3, 'INSERT INTO tbl_reseller_activations x UPDATE tbl_reseller_activations': 2}

r4-mariadb: 222 logged deadlock(s); statement pairs: {'INSERT INTO tbl_reseller_activation_events x UPDATE tbl_reseller_activations': 194, 'INSERT INTO tbl_reseller_activation_events x INSERT INTO tbl_reseller_activations': 20, 'UPDATE tbl_reseller_activations': 6, 'INSERT INTO radcheck': 1, 'INSERT INTO tbl_reseller_activations x UPDATE tbl_reseller_activations': 1}


| Variant | Change | Refusals / operations | Effect |
|---|---|---|---|
| baseline | none | 8 / 5,400 (0.15 %) | - |
| **A. jittered backoff** | `usleep(random_int(2000, 20000) * (attempt + 1))` before each existing retry; **same retry bound (2), same whole-unit retry, same semantics** | **0 / 5,400** | deadlocks unchanged (554 vs 564): the retries now succeed; refusals vanished in the lab (probability of 0 of 5,400 at the baseline rate is below 0.1 %) |
| B. more retries | `MAX_DEADLOCK_RETRIES = 4` | 1 / 1,800 | lower, but it changes the pinned retry bound (`FinanceTxTest` asserts 2) |
| C. automatic safe cancel of stale held-only activations | **not implemented and not evaluated beyond reasoning**: the owner directed against it | - | would release funds on a timer; contradicts "never release because of an error" |

**What A would change:** a timing line inside `FinanceTx::run` (a WP3A-accepted file). It does not alter the retry count, the unit of retry, the lock order, any ledger / reservation / settlement semantics, or the schema. It is still inside the *locked retry contract* area, so it is **not implemented here**; the exact diff and measurements are in `evidence/odl/variants/`. If the owner authorizes it separately, the proportional gates are: `FinanceTxTest` and the finance suites on both engines, `StagingLoadTest`, `DeadlockStudyTest`, then the final suites.

## 6. Recommendation

1. **Classify O-DL as an accepted production residual** (engineering view): fail-safe verified on both engines, existing limit not a regression, MariaDB-specific, rare (about 1 operation in 400 only under a 12-way stampede on one wallet; ordinary activation traffic is far below that), monitored (`activations_stale` WARN 5 min / CRIT 30 min) and recoverable by the documented operator procedure (runbook section 1).
2. Make TA-09 measure it on the target engine (the study tool needs Docker; without it, run the load shape by hand and count `CONCURRENCY_RETRY` outcomes).
3. **Optional improvement, needs separate authorization:** variant A (jittered backoff, same bound). Expected effect from the lab: refusals to about zero.
4. Operator guidance: a `CONCURRENCY_RETRY` that is followed by `ACTIVATION_IN_PROGRESS` on the customer's retry means "a held activation exists": resume or cancel it (runbook section 1); do not create another.

## 7. Notes

- UX: the refusal text "please try again" is inaccurate when the activation is already held (the retry is refused). A wording change is low risk but part of the message catalogue; not changed.
- This is the only deadlock family observed: about 92 % `events` / `activations`, the rest `activations` / `activations` and `events` / `activations` inserts of the same pattern.
- Raw server deadlock dumps (they contain row images of the synthetic users) are kept outside Git (`/srv/phpnuxbill/dlstudy-*`); the repository holds the counters, the per-run outcomes and the summaries.

Evidence: `evidence/odl/oldnew/`, `evidence/odl/variants/`, `evidence/final-v2/load*/`, `evidence/final-v2/ab-deadlock-investigation/`; tools `dev/wp10/DeadlockStudyTest.php`, `deadlock_study.sh`, `deadlock_report.py`.
