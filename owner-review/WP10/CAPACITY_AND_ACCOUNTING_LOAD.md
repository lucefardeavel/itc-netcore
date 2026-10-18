# Capacity and accounting load of N1 = A + G (interim 30 s, enforcer `--every=5`)

Owner instruction 2026-10-18: do not assume that 2,000 online quota subscribers are proven because the load model lists them; fix the final settings from
TA-05 / TA-09; if the capacity tests show the setting unsafe, improve the mechanism inside the approved contract or record a specific release blocker.

**Scope of this file: isolated lab only** (a 12-vCPU shared development host, FreeRADIUS 3.2.8 pinned image, MariaDB 10.11.19 and MySQL 8.0.46, throwaway
databases, synthetic subscribers). It shows behaviour and the *relative* cost of the settings. It is **not** TA-09: the target hardware, database sizing
and FreeRADIUS pool are not these, and **TA-05 / TA-09 remain `NOT_RUN`**.

## 1. What was measured

`dev/wp10/acct_load.sh <engine>` (`dev/wp10/AcctLoadTest.php`): S synthetic online quota subscribers (quota rows + open sessions created through real
Accounting-Start requests), then Interim-Updates at S / I per second for 40 s through the real FreeRADIUS accounting path into the database, with the
quota enforcer running **as its own process** (`acct_enforcer_worker.php`, a pass every 5 s, no NAS), as in production. Bounded by design: at most 8 requests
in flight against a pool of 32, a hard ceiling of 260 requests/s, accounting traffic only (no authentication, no admission SQL), stop at more than 5 % loss.
It is not the forbidden pool-exhaustion test: `a2r_sql_gate.py` / `a2r_gate.py pool|fault` were never run; FreeRADIUS logged no pool or SQL error
(`freeradius-errors.txt` in every run is empty); `Aborted_connects` stayed 0.

## 2. Finding: the enforcer scan did not scale (fixed)

The frozen-candidate enforcer made one database query per online quota subscriber. Measured pass time (the enforcer is expected to finish well inside `--every=5`):


| engine | online quota subscribers | interim s | interim updates / s (target / achieved) | lost | latency ms p50 / p95 / p99 / max | enforcer pass s avg / max (every 5 s) | DB questions / s | row-lock waits | max used connections |
|---|---|---|---|---|---|---|---|---|---|
| mariadb | 500 | 60 | 8.3 / 8.4 | 0 | 6.7 / 53.9 / 119.5 / 252.8 | 0.58 / 0.748 | 119.6 | 0 | 17 |
| mariadb | 500 | 30 | 16.7 / 16.7 | 0 | 5.2 / 47.4 / 93.8 / 160.7 | 0.875 / 2.324 | 123.4 | 0 | 17 |
| mariadb | 2000 | 60 | 33.3 / 33.4 | 0 | 4 / 21.2 / 50.6 / 118.9 | 1.987 / 4.273 | 371.2 | 0 | 17 |
| mariadb | 2000 | 30 | 66.7 / 66.7 | 0 | 3.8 / 18.1 / 67.4 / 437.1 | 1.547 / 1.965 | 455.9 | 0 | 17 |
| mariadb | 5000 | 60 | 83.3 / 83.3 | 0 | 4.3 / 26.1 / 87 / 473.1 | 5.419 / 7.85 | 672.4 | 0 | 17 |
| mariadb | 5000 | 30 | 166.7 / 166.5 | 0 | 5 / 25.9 / 50.1 / 186.3 | 4.982 / 5.286 | 839.2 | 0 | 17 |
| mysql | 500 | 60 | 8.3 / 8.4 | 0 | 6.8 / 26.3 / 44.2 / 97.9 | 0.561 / 1.053 | 118.7 | 0 | 17 |
| mysql | 500 | 30 | 16.7 / 16.7 | 0 | 6.5 / 20.7 / 38.5 / 59.4 | 0.556 / 0.774 | 135.1 | 0 | 17 |
| mysql | 2000 | 60 | 33.3 / 33.4 | 0 | 7.7 / 50.7 / 109.4 / 219.4 | 2.052 / 3.101 | 370.1 | 0 | 17 |
| mysql | 2000 | 30 | 66.7 / 66.7 | 0 | 6.8 / 39.8 / 147.9 / 278.2 | 1.935 / 2.887 | 436.7 | 0 | 17 |
| mysql | 5000 | 60 | 83.3 / 82.5 | 0 | 8.1 / 60 / 135.9 / 408.5 | 4.801 / 5.476 | 673.1 | 0 | 17 |
| mysql | 5000 | 30 | 166.7 / 166.6 | 0 | 16.1 / 110.5 / 260.5 / 865.6 | 5.152 / 9.384 | 838.4 | 0 | 17 |


Pass time grew linearly: **4.8 - 5.4 s on average, up to 9.4 s, at 5,000 online quota subscribers** - longer than `--every=5`, so the effective detection interval
`P` was a pass time, not 5 s, and the database ran 840 queries/s for 167 Interim-Updates/s. (An earlier measurement that ran the pass inside the load generator's
loop showed acknowledgement latencies of 5 - 8 s; that was the generator stalling during its own pass, not FreeRADIUS, and was discarded:
`baseline-per-subscriber-enforcer-INLINE-PASS-latency-invalid`.)

`QuotaEnforcer::overQuota()` is now **set-based** (one grouped query per quota kind and ownership rule; same rules as `QuotaUsage`). Equivalence with the previous
implementation is proven by a differential test on randomised data (`V1QuotaEnforcerEquivalenceTest`: 6 seeds x 160 subscribers with malformed, duplicate,
impossible and missing boundary rows, trailing-newline values and 64-bit sums; the test failed twice first and found two real differences, both fixed; the failing runs are kept).

## 3. Result with the set-based enforcer


| engine | online quota subscribers | interim s | interim updates / s (target / achieved) | lost | latency ms p50 / p95 / p99 / max | enforcer pass s avg / max (every 5 s) | DB questions / s | row-lock waits | max used connections |
|---|---|---|---|---|---|---|---|---|---|
| mariadb | 500 | 60 | 8.3 / 8.4 | 0 | 10.3 / 65.6 / 117.2 / 168.3 | 0.091 / 0.328 | 19.8 | 0 | 17 |
| mariadb | 500 | 30 | 16.7 / 16.7 | 0 | 6.4 / 41.2 / 95.2 / 216.1 | 0.054 / 0.093 | 36.6 | 0 | 17 |
| mariadb | 500 | 15 | 33.3 / 33.4 | 0 | 4.8 / 17.3 / 32.9 / 93 | 0.032 / 0.038 | 70 | 0 | 17 |
| mariadb | 2000 | 60 | 33.3 / 33.3 | 0 | 6.9 / 31.3 / 58.1 / 183.7 | 0.164 / 0.206 | 72.6 | 0 | 17 |
| mariadb | 2000 | 30 | 66.7 / 66.7 | 0 | 4.3 / 15.9 / 42.5 / 112.7 | 0.14 / 0.189 | 139.4 | 0 | 17 |
| mariadb | 2000 | 15 | 133.3 / 133.3 | 0 | 4.3 / 18.2 / 52.5 / 157.1 | 0.185 / 0.302 | 273 | 0 | 17 |
| mariadb | 5000 | 60 | 83.3 / 83.3 | 0 | 4.6 / 30.8 / 105.5 / 402.3 | 0.354 / 0.523 | 178.2 | 0 | 17 |
| mariadb | 5000 | 30 | 166.7 / 166.7 | 0 | 6.8 / 51.9 / 109.6 / 381.7 | 0.481 / 1.037 | 345.7 | 0 | 17 |
| mariadb | 7500 | 60 | 125 / 125 | 0 | 4.7 / 26.3 / 73.2 / 248.6 | 0.425 / 0.79 | 265.9 | 0 | 17 |
| mariadb | 7500 | 30 | 250 / 250 | 0 | 4.5 / 29.4 / 71.3 / 291.3 | 0.404 / 0.564 | 515.9 | 0 | 17 |
| mysql | 500 | 60 | 8.3 / 8.4 | 0 | 7.2 / 93.6 / 361.9 / 606.9 | 0.13 / 0.585 | 19 | 0 | 17 |
| mysql | 500 | 30 | 16.7 / 16.7 | 0 | 16.4 / 79.8 / 138.4 / 217.8 | 0.049 / 0.078 | 35.6 | 0 | 17 |
| mysql | 500 | 15 | 33.3 / 33.4 | 0 | 9.2 / 98.9 / 327.6 / 586.4 | 0.062 / 0.105 | 69 | 0 | 17 |
| mysql | 2000 | 60 | 33.3 / 33.4 | 0 | 7.5 / 58.2 / 172 / 291.9 | 0.141 / 0.328 | 71.7 | 0 | 17 |
| mysql | 2000 | 30 | 66.7 / 66.7 | 0 | 7.9 / 71.3 / 166 / 399.9 | 0.125 / 0.23 | 138.4 | 0 | 17 |
| mysql | 2000 | 15 | 133.3 / 133.3 | 0 | 7.1 / 54.6 / 139.1 / 539.1 | 0.124 / 0.233 | 271.7 | 0 | 17 |
| mysql | 5000 | 60 | 83.3 / 83.3 | 0 | 9.3 / 74.7 / 166 / 308.2 | 0.304 / 0.56 | 176.9 | 0 | 17 |
| mysql | 5000 | 30 | 166.7 / 166.7 | 0 | 7 / 58 / 141.3 / 518.8 | 0.283 / 0.431 | 343.5 | 0 | 17 |
| mysql | 7500 | 60 | 125 / 125 | 0 | 5.6 / 78 / 200.8 / 464.3 | 0.41 / 0.738 | 264.7 | 0 | 17 |
| mysql | 7500 | 30 | 250 / 250 | 0 | 6.3 / 45.1 / 95.9 / 226.2 | 0.378 / 0.945 | 514.9 | 0 | 17 |


- **Zero lost accounting requests** in every window, both engines; p99 latency below 400 ms in every window (maximum 607 ms); no row-lock wait; no refused connection.
- The enforcer pass stays **flat**: 0.03 - 0.5 s average and at most 1.04 s up to **7,500** online quota subscribers (250 Interim-Updates/s at 30 s) - against a 5 s interval.
- Database load fell by about 2.5x at 5,000 subscribers (346 vs 839 queries/s at I = 30 s).
- Peak container CPU over a whole run (includes the Start phase): FreeRADIUS 63 - 215 % of one core, database 76 - 291 % of one core, at the largest windows.
  A target must be sized for the planned peak, not for these figures.

## 4. Reading the numbers for the N1 settings

| Setting | Cost at 2,000 online quota subscribers | At 5,000 | At 7,500 |
|---|---|---|---|
| I = 60 s | 33 updates/s | 83 | 125 |
| **I = 30 s (the shipped default)** | **67 updates/s** | **167** | **250** |
| I = 15 s | 133 updates/s | 333 (above this generator's ceiling) | - |

Verdict for the **lab**: the provisional N1 settings (`nuxr_quota_interim_seconds` = 30, `quota.php enforce --loop=55 --every=5`) are safe up to 7,500 simultaneously
online quota subscribers with the set-based enforcer; the 2,000-subscriber figure of the earlier model is therefore *not* a limit of the mechanism. The shipped
default is now **30 s** (E-N1-3). Whether the **target** database and FreeRADIUS hold the planned number is what TA-09 must show (pass criteria in `docs/V1_TARGET_ACCEPTANCE.md`);
if a target cannot, the lever is `nuxr_quota_interim_seconds` (a higher value halves the load per doubling and raises the normal-operation overshoot bound by the same amount;
see `N1_G_COMMERCIAL_QUOTA_CONTRACT.md` section 2).

## 5. Limits of this measurement

- Interim-Updates only; the Access-Request admission path (which uses more SQL per request and is the subject of the A2-R discussion) was deliberately not loaded.
- One generator process, UDP on the loopback of the container network; no WAN loss, no NAS retransmissions, no real accounting skew.
- Windows of 40 s; no long-duration soak (a soak on the target belongs to TA-09).
- The database is a throwaway container on tmpfs; a disk-backed production database has different write latency.

Evidence: `owner-review/WP10/evidence/capacity/` (`baseline-per-subscriber-enforcer/`, `set-based-enforcer/`, the kept failed runs, `equiv*`). Report generator: `dev/wp10/capacity_report.py`.
