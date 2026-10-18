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
