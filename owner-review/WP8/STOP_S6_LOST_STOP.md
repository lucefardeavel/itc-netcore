# WP8 — STOP after S6: a lost accounting Stop locks the subscriber out (owner stop condition 9) — 2026-10-06

Status: **WP8 STOPPED after S6 — OWNER DECISION OD-W8-16 REQUIRED**. S7 / S8 not started. No runtime, schema,
FreeRADIUS-configuration or authority change was made; no radacct row was deleted or edited.

## Blocker

When the NAS -> FreeRADIUS accounting path is interrupted while a subscriber disconnects, RouterOS 7.24.5 does not resend
the lost Accounting-Stop. The `radacct` row stays open; FreeRADIUS counts it for `Simultaneous-Use` and, because it
cannot verify the session against the NAS (`checkrad`: NAS type `other` / no SNMP tooling in the pinned image),
rejects every re-login of that subscriber. Nothing in the accepted system closes the row (WP4 decision Q8: stale
sessions are reported, never cleaned). The only existing recovery is NAS-wide: a RouterOS restart sends Accounting-On
and the stock query closes all open rows of that NAS.

Reproducible evidence: `tests/Integration/Wp8/FailureDrillsTest::testDrill7LostAccountingStopAndSimultaneousUse`
(real CHR 7.24.5, real FreeRADIUS 3.2.8), `evidence/s6/lab-evidence.jsonl` case `S6.D7.lost-stop`, `S6_RESULTS.md`.

Affected invariant: a paying subscriber must be able to re-authenticate after a session ended; Simultaneous-Use must
count only live sessions. Real-world triggers: any FreeRADIUS / network / database outage during a disconnect.

## OD-W8-16 — options

| Option | Mechanism | Change | Blast radius / risk |
|---|---|---|---|
| **A (recommended)** — staleness-bounded Simultaneous-Use | override `simul_count_query` / `simul_verify_query` in the project SQL configuration so only rows with a recent `acctupdatetime` (e.g. within 3 x the NAS Interim-Update interval) count; every NAS must send Interim-Updates (RouterOS `/ppp aaa interim-update`, Hotspot `radius-interim-update`) | FreeRADIUS project configuration (`install/freeradius`) + WP4/WP8 tests + README; no application runtime, no schema, history untouched (counting rule only) | all NAS; a locked subscriber recovers automatically after ~3 interim periods; a genuinely live session whose interims are all lost for that long would no longer be counted (bounded duplicate window) |
| B — FreeRADIUS verifies against the NAS (`checkrad` MikroTik) | `nas.type = mikrotik`, SNMP (v1, community) enabled on RouterOS for the FreeRADIUS host, net-snmp added to the FreeRADIUS image | image + NAS configuration; no application runtime | SNMPv1 exposure; checkrad's MikroTik method is documented as limited ("simple only-one user limit"); image no longer stock |
| C — operator reconciliation in the application | an Admin action that proves on the NAS that the session is gone (RouterOS API / 406) and then closes the row (acctstoptime + audit, never delete) | application runtime + capability (reverses WP4 Q8 "never cleaned") | needs design + approval; manual per subscriber |
| D — status quo + runbook | NAS restart (Accounting-On) to unlock | none | drops every subscriber on the NAS; lockout until then |

Recommendation: **A** now (automatic, configuration-only, no authority change), optionally **C** later for operator
visibility. Any option requires owner approval before implementation; WP8 resumes at S6 closure / S7 afterwards.
