# RAD-3a option (d): decide session ends on the server's clock — architecture proposal (NOT IMPLEMENTED)

Status: **proposal only** (owner decision 2026-10-28). Nothing here is implemented. Implementation needs a new owner decision because it
changes the locked Option B renewal contract (`V1RenewalOptionBTest`) and the accounting path in an architecture-locked area.

## Problem
`ActivationService::endPeriodSessions` selects the sessions to end after a plan change / renewal by `radacct.acctstarttime`, which is the
NAS's own `Event-Timestamp`. A NAS clock more than the 10 s allowance ahead stamps a session that was open before the change *after* the
cut-off, so it is neither ended nor reported (V1 relies on NTP + `time.nas_clock` + health `nas_clock` + TA-02 instead).

## Proposal
Use an authoritative server-side time for the decision:
1. **Preferred:** the A2-R admission reservation already records `confirmed_at` on the **database clock** for every PPPoE session that
   was admitted (`nuxr_admission`, fresh-session hold). The session-end selection would compare that value (per username / session
   token) with the cut-off instead of the NAS stamp. No new column; Hotspot uses its own reservation table (`nuxr_hs_admission`).
2. **Alternative:** record the FreeRADIUS arrival time of the Accounting-Start (`NOW()` of the accounting query) in a new
   `radacct` column; compare that. Requires a schema migration and a change to the shipped accounting queries.

## Impact
| Area | Change |
|---|---|
| Option B contract | `V1RenewalOptionBTest` must be updated deliberately (the selection rule changes) — owner decision |
| FreeRADIUS | (1) none; (2) accounting query change + migration, re-gated on CHR with both engines |
| Finance / ownership | none: only which sessions receive the Disconnect changes |
| Rollback | code-only for (1); (2) needs the column kept (harmless) |

## Tests to add before any implementation
- NAS clock +300 s / -300 s: the session open before the change is ended in both cases (CHR, both engines).
- A reconnect inside the allowance after the change is **not** ended (avoids option (c)'s false drops).
- A session without a reservation row (pre-A2-R) falls back to today's rule, reported.
- TA-06 on the target NAS.

## Effort and risk
Medium: selection query + tests + CHR gate (1); add a migration and FreeRADIUS query review for (2). Main risk: sessions admitted before
the reservation table existed (fallback rule needed).
