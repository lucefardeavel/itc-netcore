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
