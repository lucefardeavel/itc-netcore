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

---

## Owner resolution (2026-10-07) — OD-W8-16 = OPTION E, conditional on a Port-Limit gate — GATE FAILED (PPPoE)

Owner decision: **OD-W8-16 = OPTION E** — freshness-aware Simultaneous-Use (180 s window, 60 s interim) **plus**
NAS-side `Port-Limit` as a required second enforcement layer; implementation (`RadiusPolicyBuilder` Port-Limit reply,
project-owned `simul_count_query` / `simul_verify_query`) conditionally authorized **only if** a lab-only gate proves
on the real RouterOS 7.24.5 that Port-Limit enforces the live-session limit for **both** PPPoE and Hotspot.
Status recorded per instruction: **IMPLEMENTATION CANDIDATE -> gate run -> NOT IMPLEMENTED (gate failed).**

### Gate (lab only; Port-Limit reply injected into disposable subscriber policy, Simultaneous-Use removed so the NAS is
### the only enforcer; NAS active table sampled every 0.5 s while the competing client tried) — `PortLimitGateTest`

| Case | RouterOS 7.24.5 behaviour | Gate |
|---|---|---|
| PPPoE, Port-Limit 1, C1 live, C2 same username | FreeRADIUS Access-Accept to C2 **carries Port-Limit = 1** (debug reply + capture: attribute 62 on the wire in both Access-Accepts); RouterOS logs `logged in` twice and creates `<pppoe-USER>` **and** `<pppoe-USER-1>`; both clients keep an address (10.88.10.149 / .148), `/ppp active` shows **2 live sessions** (`radius=true`) for the whole 35 s window | **FAILED** |
| PPPoE, Port-Limit 1, accounting (UDP 1813) lost 190 s, SQL count bypassed | same: Access-Accept with Port-Limit 1, second session admitted, **2 live sessions** | **FAILED** |
| Hotspot, Port-Limit 1 (`shared_users 1`) | 1st client logged in; 2nd: RADIUS Access-Accept, **RouterOS refuses** the login (back to the login page); never more than 1 active | PASSED |
| Hotspot, Port-Limit 2 (`shared_users 2`) | 1st and 2nd logged in; 3rd: Access-Accept, **refused by RouterOS**; never more than 2 active | PASSED |
| Hotspot, Port-Limit 1, accounting lost 190 s, SQL count bypassed | 2nd device refused by RouterOS; never more than 1 active | PASSED |

Why Option E fails: on RouterOS 7.24.5 the RADIUS `Port-Limit` reply is enforced by the **Hotspot** service but
**ignored by the PPPoE server**. With the freshness query, a live PPPoE session whose accounting is lost for > 180 s
would no longer be counted and a second real PPPoE session would be admitted — Option E would trade the permanent
lockout for duplicate PPPoE sessions. Per the owner instruction the freshness query and the RadiusPolicyBuilder change
were **not implemented**.

### Additional lab observation (not implemented; for the owner decision)

With RouterOS' own NAS-side PPP control **`/ppp profile only-one=yes`** on the PPPoE server profile (lab profile
`nuxr_wp8_pppoe`, restored to `default` afterwards), Simultaneous-Use removed and accounting lost: FreeRADIUS accepted
the second client and **RouterOS tore the newcomer down within ~1-2 s**; C1 kept its session and the second client
never obtained an IPv4 (`testObservationPppProfileOnlyOne`, three runs). In one of the three runs the 0.5 s sampler saw
a **transient second `/ppp active` entry with an address (10.88.10.136) for one sample** before the teardown, so
`only-one=yes` is **not** a strict "never two live sessions" guarantee - it is a fast newcomer rejection. PPPoE
subscribers always have a limit of 1 in the accepted policy, so this NAS setting is a candidate PPPoE second layer (a
NAS-configuration requirement rather than a RADIUS attribute) — owner decision required, e.g. Option E' = freshness +
Port-Limit for Hotspot + `only-one=yes` on every PPPoE server profile, accepting the observed ~1-2 s transient.

Evidence: `evidence/od-w8-16-gate/` (redacted), `tests/Integration/Wp8/PortLimitGateTest.php`.
