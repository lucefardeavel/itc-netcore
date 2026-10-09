# Development Status Report — evidence-based audit (2026-10-09)

This is a docs-only audit. **No test suite, lab gate or real-NAS check was run on 2026-10-09.** Running them would write
to the disposable test databases or the WP8 lab, and this task forbids database writes. Every test result below is
therefore a *historical recorded result* with its commit and date. None of them is a fresh result.
Architecture decisions come from [`owner-lock-2026-10-09/OWNER_ARCHITECTURE_LOCK.md`](owner-lock-2026-10-09/OWNER_ARCHITECTURE_LOCK.md).
Acceptance comes only from [`ACCEPTANCE_LEDGER.md`](../claude-development-pack/review/ACCEPTANCE_LEDGER.md) and the
WP8 owner verdicts recorded in `owner-review/WP8/OWNER_DECISIONS.md` on `wp8/integration-poc`.

## 1. Repository state (read-only checks, 2026-10-09)

| Ref | SHA | Notes |
|---|---|---|
| `origin/main` | `500d3a2d15003efeed6e0aad639c13d08101c3b1` | Preserved baseline (spec v1.1). It is an ancestor of `feat`. |
| `origin/feat/reseller-system-v1` | `5bdc57459a64dd53e32e5abb2ef6efa3815e387a` | Canonical accepted branch. It contains every accepted WP0–WP7 HEAD and the hotfix (verified with `merge-base --is-ancestor`). |
| `origin/wp8/integration-poc` | `47a3a02cb0d469d324393515186f2d1497641b31` | 12 commits ahead of `feat`, 0 behind. **Not merged.** |
| `origin/wp7/staff-pwa`, `wp6c/…`, `hotfix/pace-xhr-csrf` | `8e7f0e1c`, `757d8de9`, `6d4f3f96` | Frozen. |
| WP9 / WP10 branches | none | No ref on origin or locally. |

The working tree of the main checkout (`/srv/phpnuxbill/dev`, branch `wp8/integration-poc`) holds **uncommitted WP8 work**:
- three modified files: `install/freeradius/sites-available/phpnuxbill`, `install/freeradius/mods-available/sql.example`, `dev/test/freeradius/sql`;
- two new files: `install/freeradius/bin/nuxr_pppoe_session_guard`, `install/freeradius/mods-available/nuxr_pppoe_session_guard`.

That work is the start of the permanent Option G integration (§4.4). This audit did not touch it; it used a separate worktree.
The disposable WP8 lab containers (`nuxbill-reseller-test-freeradius-wp8-1`, `-mariadb-1`) are still running from the gate run, and the audit left them as they were.

## 2. Summary

- **Last owner-accepted WP:** WP7, `ACCEPTED_WITH_NOTES` at `8e7f0e1c` (2026-10-06).
  - The pre-WP8 Pace / XHR CSRF hotfix was accepted afterwards (`6d4f3f96`). Canonical `feat` = `5bdc5745`.
- **Current development WP:** WP8, the real FreeRADIUS + MikroTik integration POC. Status **PENDING**.
  - S1–S6 have owner verdicts.
  - The session-admission blocker OD-W8-16 is still OPEN: the Option G gate passed in the lab, but the integration is not productionized.
  - S7 and S8 have **not started**.
- **WP9 / WP10:** PENDING, **not started**. Only the pack prompts exist; there is no branch, report, test or acceptance.
- **Architecture:** the ten axes are owner-approved as design direction (2026-10-09). That approval is not implementation acceptance (lock §8).

## 3. WP0–WP10

"Tests" are the recorded results at acceptance. None were re-run today.

| WP | Goal | Implemented | Accepted HEAD | Branch | Tests (recorded) | Owner status | Open items | Next |
|---|---|---|---|---|---|---|---|---|
| WP0 | Reconnaissance & baseline verification | Baseline report, gap analysis | `c2582368` | `wp0/reconnaissance` → feat | report-only WP | ACCEPTED_WITH_NOTES (2026-10-01) | PDF/gd path; static UI review; external plugins | — |
| WP1 | Database foundation | R0001–R0006, migration runner | `1f36bfa1` | `wp1/…` → feat | tested MariaDB + MySQL (no count in ledger) | ACCEPTED_WITH_NOTES (2026-10-01) | CHECK constraints compared by name → **must close in WP9** | WP9 input |
| WP2 | Hierarchy & permissions | ActorContext, capabilities, DataScope, ServiceGate | `1c66ec5b` | `wp2/…` → feat | 207 / 9315 per engine | ACCEPTED_WITH_NOTES (2026-10-01) | NEW-7, NEW-8, plugin bypass red-team → WP9; backup runbook → WP10 | WP9 / WP10 input |
| WP3A | Wallet & ledger | Finance core, reservations | `c329145a` | `wp3a/…` → feat | 371 / 24904 per engine | ACCEPTED_WITH_NOTES (2026-10-01) | App-level ledger immutability (WP9 / WP10) | WP9 / WP10 input |
| WP3B | Pricing & settlement | PricingService, snapshots, commissions | `460714de` | `wp3b/…` → feat | 470 / 43219 per engine | ACCEPTED (2026-10-02) | — | — |
| WP4 | RADIUS hardening | RadiusRepository / Provisioner / CoAClient / AccountingService, `install/freeradius` | `58e3e458` | `wp4/…` → feat | 539 / 43804 per engine | ACCEPTED_WITH_NOTES (2026-10-02) | NAS secret plaintext; FreeRADIUS TZ; no real NAS (→ WP8) | covered by WP8 |
| WP5A | Activation core | ActivationService, reconciliation | `8e3c6ec9` | `wp5a/…` → feat | 618 / 46587 per engine | ACCEPTED_WITH_NOTES (2026-10-03) | Held credit on ambiguity; disconnect best effort | — |
| WP5B | Legacy entry-point closure | ServiceGate closure, reversal (R0007) | `cdaebba2` | `wp5b/…` (`cfef0129`) → feat | 778 / 50026 per engine | ACCEPTED_WITH_NOTES (2026-10-04) | Plugins with direct DB access (WP9 / WP10) | WP9 input |
| WP6A | Adaptive shell & subscriber UX | Activation preview / fingerprint | `f71e239f` | `wp6a/…` (`ed014525`) → feat | 828 / ~51576 per engine | ACCEPTED_WITH_NOTE (2026-10-04) | **R-15** bandwidth read live at provisioning (lock gate P3) | P3 gate |
| WP6B | Finance / reseller / reports UX | Finance UI, exports, ops center | `bd3f6cf2` | `wp6b/…` (`20bcfcaf`) → feat | 866 / 64626 per engine | ACCEPTED_WITH_NOTES (2026-10-05) | Load / concurrency → WP9 | WP9 input |
| WP6C | Mobile adaptive UX | Adaptive layer, parity | `44505d59` | `wp6c/…` (`757d8de9`, frozen) → feat | MariaDB 903 / 66053 PASS; MySQL known harness flake (owner-classified) | ACCEPTED_WITH_NOTES (2026-10-05) | Emulation-only device QA | — |
| WP7 | Staff PWA | Worker, heartbeat, offline gate | `8e7f0e1c` | `wp7/staff-pwa` (frozen) → feat | 927 / 66382 MariaDB, 927 / 66381 MySQL; browser 28/28 | ACCEPTED_WITH_NOTES (2026-10-06) | R-WP7-CARRY-01 → closed by hotfix | — |
| Hotfix | Pace / XHR CSRF | `nuxr-csrf.tpl` XHR section | `6d4f3f96` | `hotfix/pace-xhr-csrf` (frozen) → feat | focused PHP + browser 32/32 | ACCEPTED_WITH_NOTES (2026-10-06) | **R-HF-CARRY-01 OPEN** | WP9 / release |
| **WP8** | Real FreeRADIUS + MikroTik POC | S1–S6 + OD-W8-16 gates (§4) | — | `wp8/integration-poc` `47a3a02c` (not merged) | per-slice PASS recorded; Option G gate PASS (lab) | **PENDING** | OD-W8-16 not productionized; concurrency gate NOT RUN; S7 / S8 not started; 4 carry-forwards OPEN | §8 |
| **WP9** | Red team & release candidate | **not started** | — | none | NOT RUN | **PENDING** | requires WP8 accepted | after WP8 |
| **WP10** | Release / handover | **not started** | — | none | NOT RUN | **PENDING** | requires WP9 accepted with zero blockers | after WP9 |

## 4. WP8 in detail (`wp8/integration-poc`)

### 4.1 Slices and owner verdicts

| Slice | Commit | Content | Owner verdict |
|---|---|---|---|
| Plan / feasibility | `5de6d8e6`, `ad314341` | Plan; local CHR feasibility `PASS_LOCAL_CHR` | Feasibility ACCEPTED; S1–S8 authorized (2026-10-06) |
| S1 | `ab6c9dfa` (stop), `c7811e50` | Reproducible real-NAS lab; Message-Authenticator first in `authorize` (OD-W8-13) | **ACCEPTED** |
| S2 | `b4347279` | Real PPPoE + Hotspot AAA / accounting on RouterOS CHR 7.24.5 | **ACCEPTED** |
| S3 | `cc29b505` | Real Disconnect / CoA; 406 → FAILED (OD-W8-14 = A) | **ACCEPTED_WITH_NOTES** |
| S4 | `67b79099` | A → B → C → Sales → customer, conservation from ledger rows | **ACCEPTED** |
| S5 | `cb29ac4f` | RENEW / CHANGE_PLAN / EXPIRY / REACTIVATE / reprovision on the real NAS | **ACCEPTED_WITH_NOTE** (R-WP8-CARRY-02) |
| S6 | `729dbf93` | Drills 1–6 and 8–10 PASS; drill 7 (lost Accounting-Stop) → **stop condition 9** | **ACCEPTED_WITH_BLOCKER** (OD-W8-16) |
| S6R Option E | `8e7d5567` | Port-Limit gate: PPPoE **FAILED**, Hotspot PASSED | Option E **REJECTED** |
| S6R Option F | `65b96dd3` | Stock checkrad gate: Gate D **fails open** | Option F **REJECTED** |
| S6R Option G | `47a3a02c` | Fail-closed PPPoE post-auth session guard gate: **PASSED** (lab) | Gate result recorded; **no owner verdict on productionization yet** |
| S7 | — | Legacy driver contract, fail-closed legacy paths | **NOT STARTED** |
| S8 | — | Final report / model / evidence | **NOT STARTED** |

### 4.2 Lost Accounting-Stop (OD-W8-16)

- **Defect.** When a PPPoE session's Accounting-Stop is lost, the open `radacct` row outlives the real session. `Simultaneous-Use` then locks the subscriber out (S6 drill 7; lock GAP-01 / R-002).
- **Option E (Port-Limit) rejected.** RouterOS 7.24.5 ignores Port-Limit for PPPoE, though it enforces it for Hotspot.
- **Option F (stock checkrad + SNMP) rejected.**
  - When `rlm_sql` checksimul fails, `process_checksimul` allows the login.
  - Stock MikroTik checkrad maps an SNMP failure to "not logged in".
  - Result: Gate D failed open (lock R-003).
- **Option G (lab result, recorded 2026-10-07).** Design:
  - `Simultaneous-Use` stays as is.
  - A project-owned post-auth guard (PPPoE only) verifies the open rows against the live NAS over SNMP.
  - Tri-state result SAFE / LIVE / ERROR; only SAFE accepts.
  - Health probe first, a 1.8 s hard deadline, multi-NAS fail-closed.
  - No secret in argv or logs; no fake Stop; `delete_stale_sessions = no`.

  Gate results:
  - Gates A–I and hostile usernames: PASS.
  - Hotspot with 1 / 2 shared users and with accounting lost: PASS (RouterOS Port-Limit).
  - 17 tests PASS (15 shipped-mode, 2 functional-mode).
  - In-guard median 354 ms, max 1104 ms.
  - Contract: RouterOS `/radius timeout` ≥ 3 s.

### 4.3 Session Authority, concurrency and lock status

- **AD-04 / AD-05 / AD-06.** Option G is the behavioural V1 Session Authority candidate. Its packaging is still subject to review.
- **Concurrent first-login gate: NOT RUN.** The scenario is two same-subscriber PPPoE Access-Requests before the first Accounting-Start (AD-07, GAP-04, R-007, roadmap A2, backlog AUD-P0-02).
  - No WP8 evidence covers it. The Option G matrix has no concurrent case.
  - Lock §5 lists it as a mandatory release gate.

### 4.4 Permanent integration of Option G

- **Not committed and not verified.** The lock's statement that Option G is "only proved in disposable lab candidate, `dev/wp8/`" is accurate for every committed state.
- Under the 2026-10-07 conditional authorization, a partial permanent integration was started: the five uncommitted files in §1.
  - Still missing: harness / lab wiring; README; the Hotspot Port-Limit change in `RadiusPolicyBuilder`; tests; the permanent rerun of the critical matrix on MariaDB and MySQL; latency measurement.
  - On `feat`, `install/freeradius/mods-available/sql.example` still has `delete_stale_sessions = yes`, and `RadiusPolicyBuilder` writes no Port-Limit.
- **Ordering.** The 2026-10-09 roadmap (A1 → A2 → A3–A5 → A6) puts the concurrency gate and carry-forward closure **before** S7 / S8. See conflict C-01 in the sync report.

### 4.5 Carry-forward risks (all OPEN)

| ID | Description | WP8 record | Lock mapping |
|---|---|---|---|
| R-WP8-CARRY-01 | Legacy direct removal / deactivation bypasses activation / reversal authority | OPEN / pre-existing / MEDIUM / must close before production | R-010 (HIGH), GAP-07, AUD-P0-04, roadmap A5 |
| R-WP8-CARRY-02 | Old RADIUS session survives a username reprovision | OPEN / pre-existing / MEDIUM / must close before production | R-008 / R-014 (HIGH), GAP-06, AUD-P0-03, A3 |
| R-WP8-CARRY-03 | `AccountingService::staleSessions` time-zone mismatch | OPEN / pre-existing / MEDIUM / must close before production | R-009 (HIGH), GAP-05, AUD-P0-05, A4 |
| R-HF-CARRY-01 | Legacy autoload router / plan metadata authorization exposure | OPEN / pre-existing / MEDIUM | R-011, GAP-08, AUD-P0-06 |

Observations recorded but not fixed:
- OBS-W8-1 … OBS-W8-5: PEAR2 / driver observations.
- OBS-W8-8: became CARRY-03.
- OBS-W8-9: stock SQL username escaping; such subscribers fail safe.
- WP1 note: CHECK constraint verification must close in WP9.
- WP6A R-15: lock gate P3.

## 5. WP9 and WP10

| | WP9 — Red team / release candidate | WP10 — Release / handover |
|---|---|---|
| Started? | No | No |
| Reports / tests | None (the prompt exists in the pack only) | None (the prompt exists in the pack only) |
| Owner acceptance | No (PENDING) | No (PENDING) |
| Prerequisite | WP8 owner-accepted | WP9 owner-accepted with zero blockers |
| Recorded inputs | WP1 CHECK-by-name; WP2 NEW-7 / NEW-8 / plugin bypass; WP3A ledger tamper; WP5B plugins; WP6B load / concurrency; R-HF-CARRY-01 | WP2 backup runbook / key separation; WP3A least-privilege DB; WP4 FreeRADIUS deployment / TZ; actual staging restore drill |
| Lock inputs (2026-10-09; inputs, **not** rewrites) | Fail-open review, concurrent-first-login regression, old-identity rename regression, secret / redaction review, plugin / direct authority bypass, accounting time / freshness, CoA failure matrix | Q1–Q4, P1–P3, N1–N7, REL-01–05, ISO-01 per-ISP isolation, owner-set RTO / RPO; REL-06 only if HA is required |

A WP9/WP10 *plan* exists only as the pack prompts plus the lock's input lists. There has been **no execution**.

## 6. Blocking issues

1. OD-W8-16: Option G is not productionized (R-001, CRITICAL).
2. Concurrent-first-login gate not run (R-007).
3. R-WP8-CARRY-01 / -02 / -03 and R-HF-CARRY-01 are open, and each is a production blocker.
4. S7 / S8 not started, so WP8 cannot be owner-accepted.
5. Ordering conflict C-01 (sync report) must be decided before WP8 resumes.

## 7. Remaining work

**Before a release candidate (end of WP9):**
- WP8 complete and owner-accepted:
  - Option G productionized, with a real-NAS rerun on MariaDB and MySQL;
  - concurrency gate passed, plus a minimal reservation only if the gate proves a race;
  - carry-forwards closed or explicitly owner-risk-accepted;
  - S7, S8.
- Minimum session / NAS telemetry (AD-21, Phase D).
- Secret boundary for the verifier (AD-18).
- WP9 red team with every recorded input, including the WP1 CHECK-by-name closure, and WP9 owner acceptance.

**Before production (WP10 and beyond):**
- Gates: P1–P3, N1–N7, REL-01–05, ISO-01, and Q1–Q4 if the quota offering ships.
- A real isolated restore (Core + RADIUS + secrets / config).
- Least-privilege DB / runtime grants.
- RouterOS firmware / timeout contract.
- Verifier secret provisioning / rotation.
- Rollback runbook; owner-set RTO / RPO.
- WP10 acceptance, then a separate, explicit deployment authorization (D038).

## 8. Next steps (order taken from `IMPLEMENTATION_ROADMAP.md`; each needs owner authorization)

1. Owner decides conflict C-01 (and C-02 / C-03): the order of A1 / A2 / A3–A5 / A6, and what to do with the uncommitted Option G integration.
2. **A1:** productionize Option G (reviewed packaging), then rerun the critical real-NAS matrix (MariaDB; MySQL for SQL / xlat / session).
3. **A2:** concurrent first-login real-NAS gate. Add the smallest bounded admission reservation only if a race is proven.
4. **A3–A5:** close R-WP8-CARRY-02, -03 and -01. This needs explicit owner authorization, because earlier WP8 directives said "do not fix".
5. **A6:** S7 (legacy driver contract, fail-closed legacy paths), then S8 (report, model, evidence).
6. **A7:** owner WP8 acceptance and canonical integration decision. Then WP9 (Phase E), then WP10 (Phase F).
