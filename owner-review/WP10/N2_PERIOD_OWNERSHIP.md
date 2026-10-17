# N2 — session boundary and router clock skew

Owner instruction `CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED` (2026-10-17), item N2. Engineering decisions are recorded in `DECISIONS.md` (E-N2-1 … E-N2-4).

## 1. The question

The previous round attributed a session to a billing period by `acctstarttime >= Nuxr-Period-Start`, where `acctstarttime` is the
NAS's own `Event-Timestamp` and the stored boundary was the period start **minus 10 s** (`QuotaPolicy::PERIOD_SKEW_SECONDS`).
The 10 s allowance was introduced because RouterOS stamps Accounting-Start 1.1 – 1.74 s *earlier* than the host receives it
(4 captures), so a login within ~2 s of the renewal (the PPPoE redial after the Option B Disconnect) was booked to the previous
period and escaped the new quota. Owner question: can the allowance also attribute consumption from **before** the renewal to
the new period, and is there an exact alternative?

## 2. Review of the time rule (measured, not assumed)

| Direction of the error | Cause | Effect | Bound |
|---|---|---|---|
| **Under-count** (a new-period session booked to the old period) | NAS clock behind the host by more than the allowance, or the integer-second stamp plus lag | the traffic of the first session(s) after a renewal escapes the new quota | sessions that start within `lag − 10 s` after the renewal |
| **Over-count** (an old-period session booked to the new period) | a session that started up to 10 s **before** the renewal passes `acctstarttime >= start − 10 s` | its traffic until the renewal Disconnect is charged to the new period | ≤ 10 s of that session's rate, only for sessions opened in that window; reproduced by `V1PeriodOwnershipTest::testTheTimeRuleAlone…` (400 MB session opened 3 s before the renewal is counted in the new period) |

So **yes**: the allowance can attribute pre-renewal consumption to the new period (rare: it needs a session opened in the last 10 s
before the renewal, which Option B then ends). And the rule depends on the NAS clock in both directions.

## 3. Options evaluated

| Option | Exact? | NAS clock needed | Cost / risk | Verdict |
|---|---|---|---|---|
| A. Keep the time rule + allowance (previous round) | no (±10 s windows above) | yes (NTP) | none | fallback only |
| B. FreeRADIUS writes its own receive time (`event_timestamp_epoch = "%l"` in `queries.conf`) | yes (server clock) | no | changes the accepted accounting time contract (WP8 A4 / lock C-05: `acctstarttime` is the NAS `Event-Timestamp`); a delayed Start (FreeRADIUS outage) gets a late time; needs an owner decision on the contract | **not taken** (owner decision) |
| C. **Ownership by accounting-row arrival order** (`radacct.radacctid`) | **yes, for every row** | **no** | one new check attribute, four readers, a resolver; no schema change | **taken** |

## 4. The implementation (option C)

* A period that starts now owns every accounting row whose id is **larger than the table's highest id at that moment**
  (`Nuxr-Period-Acct-Id`, `AccountingService::maxRowId()`), whatever the NAS clock says. The time boundary stays beside it as the
  fallback.
* The id is **paired with its period** (`PeriodBoundary::resolve`): a re-provision of the same period (password change, rename,
  re-sync — the time boundary is unchanged) keeps the stored value, so usage is never reset; a new period (renewal, plan change,
  reactivation) takes the table's current end; a period provisioned before this change has no id and keeps the time rule until it
  ends (no in-place migration, no reset).
* Activations: `capture()` records the exact target (including the id) before the first write; `provision()` writes that same
  target and verification compares with it, so accounting rows that arrive in between cannot cause a verification mismatch.
* Readers, one rule: the FreeRADIUS counters (`nuxr_timecounter`, `nuxr_datacounter`), the in-session share
  (`Nuxr-Quota-Share`) and the enforcer use the id when it is well-formed and **not beyond the table's end**, otherwise the time
  rule. A malformed id fails closed at login. An id beyond the table's end (an accounting table restored from an older backup)
  cannot be trusted and falls back to the time rule instead of ignoring every new row.
* A late Interim, a duplicate Start or a retransmitted Stop updates **its own row** (`acctuniqueid`): a row never moves between
  periods after it was opened.

Conservative edge, by design: a login accepted just before the renewal whose Start arrives just after it is counted in the new
period (it can only add usage). Option B ends it with the other sessions of the previous policy when it is listed; if it is not
listed yet it is the pre-existing in-flight case (P1), unchanged.

## 5. Test matrix (real FreeRADIUS 3.2.8 harness, both engines; `V1PeriodOwnershipTest`)

| Case | Result |
|---|---|
| NAS clock −300, −30, −5, 0, +5, +30, +300 s | the session that started 3 s before the renewal is **not** counted; the first session of the new period **is**, for every skew |
| delayed Start / late Interim / duplicate Start / retransmitted Stop for an old-period session | the row keeps its place: its later traffic is never counted in the new period; one row per session |
| re-provision of the same period; renewal | boundary kept (usage not reset); renewal moves it |
| period provisioned before this change | stays on the time rule after a password change (no reset) |
| id beyond the table's end; malformed id | time-rule fallback; login refused |
| enforcer | same ownership: the previous-period session is not enforced, the new-period session stamped 60 s early is |
| characterisation of the fallback | the time rule alone counts a 3-s-old session (documented limitation) |

## 6. What is still required of the deployment

Attribution no longer depends on the NAS clock. The clocks still matter elsewhere, so the requirements are explicit
(checklist H8, preflight `time`, health `nas_clock`):

* every database / PHP / FreeRADIUS host: **UTC, NTP-synchronised**; the preflight fails when the SQL session zone is not UTC
  (P-A4-1) or the database-vs-PHP skew is 5 s or more (P-A4-2);
* every NAS: NTP. Accounting display times, the freshness checks (±300 s tolerance) and the fallback time rule use the NAS stamp;
  the health check reports a NAS whose interim stamps are in the future by more than 120 s;
* maximum permitted NAS drift: **60 s** for operations (a larger drift is reported); **no longer an attribution correctness
  requirement** for rows that carry the id.
