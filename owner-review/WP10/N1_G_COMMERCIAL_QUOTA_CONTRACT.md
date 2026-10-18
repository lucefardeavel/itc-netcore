# N1 + G commercial quota contract (PPPoE and Hotspot data / time quota)

Owner decision 2026-10-18: **Option A + Option G**. This is the contract the product may state to customers and the rules the code enforces.
Technical analysis and measurements: `N1_PPPOE_OVERSHOOT.md`. Capacity and accounting cost: `CAPACITY_AND_ACCOUNTING_LOAD.md`.

## 1. What is sold

A plan with a data quota gives the subscriber **a quota of data per service period** (download + upload together) and/or a quota of
session time. The quota is enforced **by the network equipment while the session is running** and **checked continuously from accounting**.
It is **not** a hard, byte-exact cap.

Allowed customer wording: *"Data quota is enforced by the network equipment and checked continuously; usage can exceed the quota by a few
seconds of line speed."* Not allowed: "exactly X GB", any percentage, "hard cap", "no more than". The wording applies to PPPoE and Hotspot;
Hotspot's native total limit is tighter but is still not described as byte-exact.

## 2. Option A - the mechanism (unchanged from N1)

| Layer | What it does | Bound |
|---|---|---|
| Login | the remaining quota decides admission (fail closed on malformed / duplicate / unreadable quota rows) | none: no session starts over quota |
| NAS native limit | Hotspot: total; PPPoE: each direction, set at login to the remaining quota | the NAS cuts 0.02 - 4 % past a limit |
| Accounting enforcer (`quota.php enforce`) | ends a session whose period usage reached the quota (Disconnect + confirmed Accounting-Stop) | detection latency <= I + P + c |

Symbols: R remaining quota at login, r the subscriber's combined line rate, I the interim interval (`nuxr_quota_interim_seconds`, provisional 30 s),
P the enforcer pass interval (`--every`, provisional 5 s), c the Disconnect time (<= 1 s normal, about 5 s with CoA retransmissions).

### 2.1 Normal operation (enforcer, FreeRADIUS, the database and the CoA path working)

**Overshoot <= min( R , r x (I + P + c) )** - at most the data the subscriber's line can move in `I + P + c` seconds. With the provisional
settings that is about 36 s of line rate (more with CoA retransmissions).

### 2.2 Failure operation (stated separately, and not smaller than it is)

| Failure | Bound on the overshoot | How it is visible |
|---|---|---|
| Enforcer not running, FreeRADIUS down, accounting not arriving, database down, NAS unreachable by CoA | **up to the whole remaining quota again on PPPoE (total use up to 2 R)**: only the per-direction native caps hold; Hotspot: native total limit holds, overshoot stays the NAS cut delay | health `quota_enforcer` CRITICAL while quota subscribers are online; drift / `quota.php report` |
| CoA path blocked, enforcer working | detection happens, the Disconnect is not confirmed: retried after 15 s; overshoot grows with the outage (measured: 38 s after crossing with a 40 s block) | `COA_NO_RESPONSE`, `SESSION_POLICY_STALE` until the Stop arrives |
| Session already over the NAS cap before the first interim | the NAS native cap ends it: overshoot = up to the whole quota on a PPPoE line whose per-direction cap is the quota (a small quota on a fast line) | accounting Stop, cause "NAS-Request" |

Operators must treat the second table as the real worst case when sizing plans: *quota small compared with `line rate x (I + P + c)` is the
weak case* (a 5 GB plan on a 100 / 100 Mbit/s line, both directions saturated, can overshoot by about 0.9 GB in normal operation at the
provisional settings: 25 MB/s x (30 + 5 + 1) s; about 1.0 GB with CoA retransmissions. The model tables in `N1_PPPOE_OVERSHOOT.md` section 7 use
P = 15 s, which is why they show 1.15 - 1.25 GB).

## 3. Option G - the commercial rule

> Usage above the quota that is caused by detection delay or Disconnect delay is **not charged** and is **not carried into the next period**.

Rules enforced by the code and tests:

| # | Rule | Where / test |
|---|---|---|
| G1 | Raw accounting (`radacct`) is kept whole and never edited or deleted by the quota code | every enforcer / usage test asserts the rows unchanged (`V1QuotaEnforcerTest`, `V1QuotaUsageTest`) |
| G2 | **Actual network usage** and **billable usage** are different numbers: `actual` = recorded octets / seconds of the period; `billable = min(actual, quota)`; `overshoot = max(actual - quota, 0)` (information only) | `Reseller\Radius\QuotaUsage`; Subscriber 360 card "Quota this period"; `quota.php usage USERNAME` |
| G3 | The overshoot creates **no debt**, no extra wallet movement, no ledger entry, no settlement | `V1QuotaUsageTest::testAnOvershootCreatesNoDebtAndARenewalSettlesExactlyOnce` (ledger rows, wallets, `SettlementReadModel::integrity()`) |
| G4 | The overshoot is **not carried into a renewal or any new period**: a period owns only the accounting rows that arrived after it started (`Nuxr-Period-Acct-Id`) | `V1PeriodOwnershipTest`; `V1QuotaUsageTest::testThePreviousPeriodsRowsAreNotPartOfTheCurrentOne` |
| G5 | An activation always opens a **new** period, even inside the same second as the previous one (defect found and fixed 2026-10-18: before, a renewal in the same second kept the old boundary and the overshoot rows were carried over) | `PeriodBoundary::newPeriod`; `V1QuotaUsageTest` (failing first, MySQL 4/4 runs, MariaDB 2/4 runs) |
| G6 | No finance, wallet, pricing or activation class reads usage or overshoot | `V1OwnerDecisionGuardsTest::testBillingCodeNeverReadsUsageOrTheOvershoot` |
| G7 | Ledger history and financial settlement are unchanged by this decision | no finance / pricing / schema file in the diff |

Boundary cases covered: exactly at the quota (billable = actual, no overshoot), one octet over, 64-bit values (5,000,000,000,007 octets),
a period whose previous rows are all over quota, a session open across a renewal (Option B ends it first), a late Interim after a Stop, a
delayed Start after a renewal (counted in the new period by arrival order: conservative, at most the traffic of seconds), duplicates and
retransmissions, reconnects, several sessions of one subscriber.

## 4. What is **not** decided or promised

- No customer-facing percentage and no "tolerance" figure. If a figure is ever needed it is stated per plan as seconds of line rate (`I + P + c`).
- No rebate or refund for under-use caused by a premature cut (a session cut by the NAS native cap below the quota is a limitation of the
  per-direction cap, documented in `N1_PPPOE_OVERSHOOT.md`).
- Time quotas follow the same rule (billable time is `min(actual, quota)`); an overshoot of seconds is not charged and not carried.

## 5. Settings (provisional, to be fixed by the capacity and target results)

| Setting | Provisional | Notes |
|---|---|---|
| `nuxr_quota_interim_seconds` | 30 | shipped default is 60 until the capacity result below confirms 30; the setting is read when a session is admitted, a running session keeps its interval |
| `quota.php enforce --loop=55 --every=5` | 5 s | cron every minute; one enforcer at a time |

The capacity measurements decide whether 30 s / 5 s is safe at the planned number of simultaneously online quota subscribers
(`CAPACITY_AND_ACCOUNTING_LOAD.md`). The measured lab limit is **not** a promise for the production hardware (TA-09).
