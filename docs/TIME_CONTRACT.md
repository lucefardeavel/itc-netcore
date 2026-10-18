# Canonical time and period-ownership contract (N2 / D5)

Owner decision 2026-10-18: UTC and NTP are mandatory; billing periods own accounting rows by **arrival order**. This is the canonical text
(supersedes the scattered statements in `install/freeradius/README.md`, `N2_PERIOD_OWNERSHIP.md` and the WP8 A4 report; those remain as the
analysis and the measurements). It is **not** production-ready until TA-02 and TA-06 of `docs/V1_TARGET_ACCEPTANCE.md` pass on the approved target.

## 1. Clocks (requirements, checked by the deployment gates)

| Host | Requirement | Check |
|---|---|---|
| Application (PHP), database, FreeRADIUS | UTC zone, NTP-synchronised; the database session zone is UTC; maximum drift between them **5 s** | `php system/reseller/preflight.php` (`time.sql_session_zone`, `time.clock_skew`); TA-01 / TA-02 |
| Every NAS (RouterOS) | NTP client enabled, clock zone UTC or a fixed offset known to the operator; maximum drift **60 s** for operations | health `nas_clock` (WARNING when open sessions carry stamps more than 120 s in the future); TA-02 |
| Browser / operator | not part of the contract (display only: times are shown in the application zone with the UTC offset) | - |

The application never parses accounting times as PHP-local strings: it reads epoch seconds through the RADIUS database session
(`AccountingService`, `UNIX_TIMESTAMP`).

## 2. What the NAS clock may and may not decide

| Use | Source | Depends on the NAS clock? |
|---|---|---|
| **Which billing period owns an accounting row** (quota, enforcement, renewal boundary) | **arrival order**: the period that starts now owns every `radacct` row whose `radacctid` is larger than the table's highest id at that moment (`Nuxr-Period-Acct-Id`) | **No** |
| Fallback for a period without the id (provisioned before V1.1, or an id the table cannot have produced) | `acctstarttime >= Nuxr-Period-Start` (period start minus the 10 s allowance) | Yes |
| Display of session start / last seen; freshness (stale after 300 s) | `acctstarttime` / `acctupdatetime` converted with the database zone | Yes |
| Expiry (`Expiration`, `WISPr-Session-Terminate-Time`, `Session-Timeout`) | application time, written as the FreeRADIUS server-zone string | No (application clock, UTC contract) |

## 3. Period rules

1. A period starts at an activation (NEW, RENEW, CHANGE_PLAN, REACTIVATE). **An activation always opens a new period**, even if its start second
   equals the previous period's (fixed 2026-10-18).
2. A re-provision of the **same** period (password change, rename, re-sync) keeps the stored boundary: usage is never reset by it.
3. A row never moves between periods after it is written: a late Interim or a retransmitted Stop updates its own row.
4. Usage never decreases: a stale (smaller) Interim after a Stop is acknowledged and not written; duplicate Start / Stop make one row.
5. A Start that is **delivered after** the renewal (stamped before it by the NAS) belongs to the new period: conservative, at most seconds of
   traffic, and its session was ended by Renewal Option B.
6. The previous period's overshoot (N1 = A + G) is not part of the new period.

## 4. Test matrix (harness on the real FreeRADIUS 3.2.8, both engines unless noted)

| Item | Test |
|---|---|
| NAS clock lag / lead of -300 .. +300 s | `V1PeriodOwnershipTest::testClockSkewNeverMovesASessionBetweenPeriods` |
| Renewal boundary, session opened seconds before | same test; `testTheTimeRuleAloneOverCountsASessionStartedJustBeforeTheRenewal` (the fallback) |
| Activation in the same second as the previous period | `testAnActivationInTheSameSecondStillOpensANewPeriod`; `V1QuotaUsageTest` |
| Delayed accounting (Start delivered after the renewal) | `testADelayedStartAfterTheRenewalBelongsToTheNewPeriod` |
| Interim / Stop ordering, Interim before Start | `testOutOfOrderInterimStopAndStartKeepOneRowAndNeverLowerTheUsage` |
| Reconnect (new session identity), retransmitted Stop | `testReconnectsAddTheirUsageAndARetransmittedStopAddsNothing` |
| Duplicate Start, late Interim never move a row | `testLateInterimAndDuplicateStartNeverMoveARow` |
| Re-provision keeps the boundary, renewal moves it | `testReprovisionOfTheSamePeriodKeepsTheBoundaryAndARenewalMovesIt` |
| Period provisioned before the id existed | `testAPeriodProvisionedBeforeThisChangeKeepsTheTimeRule` |
| Impossible id (table restored from an older backup) / malformed id | `testAnImpossibleIdFallsBackToTheTimeRuleAndAMalformedIdFailsClosed` |
| Enforcer uses the same ownership | `testEnforcerUsesTheSameOwnership` |
| Real NAS (RouterOS 7.24.5 CHR): renewal exact even when the old session started seconds before | `V1QuotaLabTest::testRenewalIsExactEvenWhenTheOldSessionStartedSecondsBefore` (CHR) |
| Preflight / health time checks | `AccountingTimeContractTest`, `AccountingTimeUnitTest`, `W10HealthCheckTest` |

## 5. Target acceptance (not run here)

- **TA-02** clock discipline on the real hosts and the real NAS (`chronyc tracking` / `timedatectl`, `/system ntp client print`, comparison with
  the Accounting-Start arrival; more than 60 s is a FAIL).
- **TA-06** renewal on the target NAS, including a re-dial inside 10 s of the renewal and an accounting delay.
- Until both pass the time contract is **documented and lab-verified**, not production-ready.
