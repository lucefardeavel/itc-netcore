# N1 - PPPoE data-quota overshoot: measurement, levers and the exact guarantee

Status: engineering analysis complete; **the customer-facing quota semantics and any accepted overshoot percentage remain an owner
decision (N1)**. Nothing here approves or advertises an overshoot figure.

## 1. Problem statement

The previous round reported a 9 MB quota that ended at 12.13 MB, with ~60.7 s to a confirmed termination. The owner did not accept that
automatically and asked for: the real enforcement capabilities, safe ways to reduce the overshoot, quantification across rates / quotas /
loads / faults, the operational cost, and the exact guarantee that is achievable - never an approximate cap sold as byte-exact.

## 2. What RouterOS 7.24.5 can and cannot enforce (measured on the CHR, FreeRADIUS 3.2.8)

| Mechanism | PPPoE | Hotspot | Evidence |
|---|---|---|---|
| `Mikrotik-Total-Limit(-Gigawords)` in Access-Accept | **ignored** | enforced | `dev/wp8/quota_gate.py`, `evidence/quota/` |
| `Mikrotik-Recv-Limit` / `Xmit-Limit` in Access-Accept | enforced **per direction** (limit-bytes-in / -out) | supported | same |
| CoA that changes a byte limit | ACKed, **not applied** | NAKed | same |
| CoA that changes `Acct-Interim-Interval` | ACKed, **not applied** (new finding) | not tested | `evidence/quota/n1/chr/coa-interim-probe.jsonl` |
| CoA `Session-Timeout` | applied | applied | earlier rounds |
| Disconnect-Request | ACKed, session ends, Stop within seconds | same | all lab runs |
| Disconnect of a session the NAS no longer has | NAK 406 | - | earlier rounds |

Consequences:
1. **A hard total-byte cap for PPPoE does not exist natively.** RouterOS caps *each direction* separately at the value it is given.
   With the whole remaining quota R as both caps, upload + download together can use up to **2R** before the NAS acts.
2. The interim interval cannot be shortened or adapted for a running session; it is fixed by the Access-Accept at login. An "adaptive
   interim near the end of the quota" is therefore **not possible** without a reconnect.
3. The byte limits cannot be tightened by CoA as the quota is consumed. The only mid-session tool is Disconnect.
4. The NAS itself cuts 0.02 - 4 % past a limit (it acts on its own timer), per direction.

## 3. The mechanism and its exact guarantee

PPPoE data quota = native per-direction caps (hard, per direction, fail-closed at login) **plus** the accounting enforcer
(`quota.php enforce`) which reads `radacct`, and ends a session that has passed the quota with a Disconnect confirmed by Disconnect-ACK +
Accounting-Stop (or NAK 503).

With R the quota remaining at login, r the combined line rate of the subscriber (bytes/s), I the interim interval, P the enforcer pass
interval and c the Disconnect time (<= 1 s normal, ~5 s with CoA retransmissions):

* detection latency L <= I + P + c (the quota is only visible in an Interim-Update; the enforcer polls every P);
* **overshoot <= min( R, r x L )** while the enforcer and the CoA path work (R = the native per-direction caps cutting first);
* **overshoot <= R** (i.e. total use <= 2 R) when the enforcer, the NAS link, FreeRADIUS or the database are down, because the native
  per-direction caps still hold;
* the quota is **never** advertised as byte-exact. The statement the system can honestly make is *"the cap is enforced per direction
  by the router and in total by an accounting check that ends the session within about I + P seconds of the quota being passed; usage
  can exceed the plan quota by at most the data transferred in that time, and in the worst case (enforcer unavailable) by the quota
  again"*.

Hotspot is different: `Mikrotik-Total-Limit` is enforced natively, so the bound is the NAS cut delay (0.3 - 4.4 % measured, 0.2 - 0.7 s of
line rate).

## 4. Levers implemented (commit `ae99c89e`, all covered by tests on both engines)

| Lever | Effect | Cost |
|---|---|---|
| `nuxr_quota_interim_seconds` (10 - 300, default 30 since 2026-10-18; it was 60 when this analysis was written): the `Acct-Interim-Interval` of a data-quota session | lowers I | one Interim-Update per online quota subscriber per interval (section 6) |
| Enforcer retry by outcome: a Disconnect with no answer / no Stop is retried after **15 s**, a NAK after 60 s | under a CoA fault the cut comes ~15 s after the path is back instead of up to 60 s | none |
| One termination call per pass for every over-quota subscriber | the Stop wait (<= 6 s) is shared, not added per subscriber | none |
| A NAS that stays silent is asked once per call (`COA_NAS_SKIPPED`, not claimed ended) | a NAS outage cannot stretch a pass to minutes | none |
| Pass interval P (`quota.php enforce --every=N`, cron `--loop=55`) | lowers P; lab used P = 5 s | a cheap indexed read per pass |

A change of the interim applies to sessions that authenticate afterwards; a running session keeps its interval.

## 5. Measured on the real NAS (CHR 7.24.5 + FreeRADIUS, mixed upload + download PPPoE, enforcer active)

`V1OvershootLabTest` (10 tests, 219 assertions, all passing): 900 kbit/s and 400 kbit/s **per direction** (the lab path carries ~1 - 1.3 Mbit/s
per direction, so absolute bytes scale with the plan rate, seconds do not). The quota is crossed ~40 s after login, i.e. between the
30 s and 60 s interim records. Raw rows: `evidence/quota/n1/chr/overshoot-rows.jsonl`, table `overshoot-table.md`.

| interim I | pass P | rate / direction | quota | cut by | latency (cross to Stop) | model bound I+P+6 | overshoot |
|---|---|---|---|---|---|---|---|
| 60 | 5 | 400 kbit/s | 4 MB | enforcer, Disconnect CONFIRMED | 20.8 s | 71 s | 2.07 MB (52 %) |
| 60 | 5 | 900 kbit/s | 9 MB | enforcer | 21.3 s | 71 s | 4.70 MB (52 %) |
| 30 | 5 | 400 kbit/s | 4 MB | enforcer | 21.7 s | 41 s | 2.10 MB (52 %) |
| 30 | 5 | 900 kbit/s | 9 MB | enforcer | 21.2 s | 41 s | 4.67 MB (52 %) |
| 15 | 5 | 400 kbit/s | 4 MB | enforcer | 5.4 s | 26 s | 0.53 MB (13 %) |
| 15 | 5 | 900 kbit/s | 9 MB | enforcer | 5.3 s | 26 s | 1.17 MB (13 %) |
| 10 | 5 | 400 kbit/s | 4 MB | enforcer | 10.6 s | 21 s | 1.05 MB (26 %) |
| 10 | 5 | 900 kbit/s | 9 MB | enforcer | 11.4 s | 21 s | 2.46 MB (27 %) |
| 30 | 15 | 900 kbit/s | 9 MB | enforcer (slow pass) | 20.8 s | 51 s | 4.65 MB (52 %) |
| 60 | 5 | 900 kbit/s | 6 MB (crossed at 27 s, **before** the first interim) | **NAS native per-direction cap** | 27 s | - | 6.02 MB (**100 %**: the 2R worst case) |
| 15 | 5 | 900 kbit/s | 12 MB, **CoA path closed for 40 s** | enforcer, retry after 15 s: attempts at 60.2 s (no answer), 75.2 s (no answer), 90.2 s CONFIRMED (10 s after the path reopened) | - | - | 8.6 MB (72 %) |

Reading the table:
* Every measured latency is inside the model bound; no case exceeded R.
* The cut happens at the **next interim record after the crossing**, so with one crossing phase the I = 60 and I = 30 rows coincide (both
  see the record at 60 s). The benefit of a short interval is the *worst case* (crossing just after a record) and the average (I/2),
  not every single run; the 15 s rows show the effect when the crossing falls shortly before a record.
* A quota that is **small relative to the rate x interval** is cut by the NAS caps first (row 10): then the overshoot is the full quota.
  That is the situation a short interval avoids, and the reason the guarantee below is stated in seconds of line rate, not in percent.
* A closed CoA path delays the cut by the path outage plus at most one 15 s retry; the native caps remain the backstop.

## 6. Cost of a shorter interval (accounting write rate; `n1_overshoot_model.py load`)

| online quota subscribers | I = 60 s | 30 s | 15 s | 10 s |
|---|---|---|---|---|
| 500 | 8 /s | 17 /s | 33 /s | 50 /s |
| 2 000 | 33 /s | 67 /s | 133 /s | 200 /s |
| 5 000 | 83 /s | 167 /s | 333 /s | 500 /s |
| 20 000 | 333 /s | 667 /s | 1 333 /s | 2 000 /s |

Each Interim-Update is one `radacct` UPDATE through the FreeRADIUS SQL pool, so the interval must be sized against the target
database and pool (checklist TA-09). Only subscribers on data-quota plans use the short interval; other plans keep their own value.

## 7. Worst-case overshoot by plan (model, both directions at the full plan rate for the whole latency)

`min(R, r x (I + P + c))`, I = 30 s, P = 15 s -> 50 s with retransmissions; full tables for I = 60 / 30 / 15 / 10 s: `n1_overshoot_model.py bound`.

| plan (up / down) | worst-case bytes (CoA healthy) | with CoA retransmissions | % of a 5 GB quota | 20 GB | 100 GB |
|---|---|---|---|---|---|
| 10 / 10 Mbit/s | 115 MB | 125 MB | 2.3 % | 0.6 % | 0.1 % |
| 25 / 25 Mbit/s | 288 MB | 312 MB | 5.8 % | 1.4 % | 0.3 % |
| 50 / 50 Mbit/s | 575 MB | 625 MB | 11.5 % | 2.9 % | 0.6 % |
| 100 / 100 Mbit/s | 1.15 GB | 1.25 GB | 23 % | 5.8 % | 1.1 % |

The percentage is a property of *quota size versus line rate*, not of the mechanism: a small quota on a fast line is the weak case.

## 8. Alternatives evaluated

| Option | Guarantee | Cost / risk |
|---|---|---|
| **A. Native per-direction caps + accounting enforcer (implemented)** | overshoot <= min(R, r x (I+P+c)); <= R if the enforcer is down | none beyond section 6; simple; no forced reconnects |
| B. Directional split (give the NAS caps `R x w_in`, `R x w_out`, so that the sum is R) | **native hard total**, overshoot ~ 1 - 2 s of line rate | A direction that reaches its cap ends the session although quota remains: **5 - 60 forced reconnects per period** depending on the traffic mix (`n1_overshoot_model.py split`; download-only subscribers are the worst); each reconnect is a customer-visible drop; weights must be guessed per plan |
| C. Adaptive interim near the end of the quota | would shrink I as R shrinks | **not possible**: CoA does not change the interim (measured) |
| D. CoA tightening of byte limits | would make the NAS enforce the remainder | **not possible**: ACKed, not applied (measured) |
| E. Shorter interim and pass only (lever, part of A) | linear in I + P | accounting load (section 6) |
| F. Hotspot-only strict quotas | native hard total | removes PPPoE data plans |
| G. Do not charge or carry over the overshoot ("soft cap") | quota semantics: the subscriber simply gets what the cap allows; next period starts from zero | commercial policy, not an engineering fix |

## 9. Recommendation (for the consolidated owner decision)

1. Keep **A** as the mechanism. B is a hard-total alternative with a real subscriber-experience cost; recommend it **only** if the owner
   requires a hard total for PPPoE.
2. Set `nuxr_quota_interim_seconds` = **30** and the enforcer pass `--every=5` on installations with up to ~2 000 simultaneously
   online quota subscribers (67 Interim-Update/s); keep 60 above that unless the database sizing test (TA-09) shows headroom.
   The shipped default was 60 s when this was written; the owner approved 30 s on 2026-10-18 and it is now the default (E-N1-3).
3. Customer-facing wording: *"Data quota is enforced by the network equipment and checked continuously; usage can exceed the quota by a
   few seconds of line speed"*. Never "exactly X GB". Choose the plan-level policy for the overshoot (G or counted in the period).
4. Do **not** approve a percentage; if a figure is required, state it per plan as seconds of line rate (I + P + c), which is what the
   mechanism can actually bound.

## 10. Reproducing on a target

On the approved staging copy: set the interim; run a PPPoE subscriber with a quota crossed 40 s after login and mixed upload + download at
the line rate (several rates); run `quota.php enforce --loop=55 --every=5`; record from `radacct`: `t_cross = quota / rate`, `t_stop =
acctsessiontime`, `overshoot = octets - quota`, `acctterminatecause`. The test source is `tests/Integration/Wp8/V1OvershootLabTest.php`
(`measure()`); the procedure is TA-05 in `docs/V1_TARGET_ACCEPTANCE.md`. A different RouterOS version or model must repeat the capability
probes of section 2 before this analysis is relied on.

## 11. Limitations

* One crossing phase per case; the worst case is the model bound, not a measurement of every phase.
* Lab rates are ~1 Mbit/s per direction; larger plans are extrapolated by the model (seconds carry over, bytes scale with the rate).
* RouterOS 7.24.5 only. The CoA findings (limits and interim not applied) may differ on other versions.
* The Disconnect-time term c was <= 1 s in every run.
