# WP8 A2-R — Owner Final Acceptance Candidate

Date: 2026-10-10. Branch `wp8/integration-poc` at `cd09a5893fa10b4650cc2d410cc618c200900687`. Lab only. Nothing committed,
pushed, merged or deployed. H-1 and A3 were not started.

Owner decisions applied (2026-10-10):
- **D-AV1 = D1:** AV-1 accepted as a documented Strict Mode availability limitation. It remains an OPEN PRODUCTION RISK.
- **D-AV4 = AV4-B:** conditional 5000 ms experiment and verification.

**Verdict: `A2_R_READY_FOR_OWNER_ACCEPTANCE_WITH_NOTES`** (§10). This is not production-ready.

Summary:
- **Security:** every A2-R security gate passes, with no double PPPoE session in any run.
- **AV-4:** resolved by the 5000 ms budget, measured on the real NAS.
- **AV-1:** the only remaining A2-R availability limit, accepted for development.
- **Drill 6:** now verifies the Strict Mode contract.
- **WP8 suite:** 0 failures. PHPUnit still exits with **1**, only because of three pre-existing PHP warnings under
  `failOnWarning="true"` (§4).

Backup of this round: `/srv/phpnuxbill/wp8-a2r-final-backup-20261010T063516Z` (mode 0700, `SHA256SUMS` verified). The
earlier backups (`…prototype-backup-20261009T172236Z`, `…closure-backup-20261009T210332Z`) and all earlier evidence
directories are untouched.

---

## 1. AV-1 — accepted limitation and remaining production risk

**What remains.** FreeRADIUS 3.2.8 stamps a request when it reads it from the socket (`process.c` `request_receive`),
there are no kernel timestamps, and RouterOS sends no `Event-Timestamp` in an Access-Request. A stall of the whole
FreeRADIUS process longer than the NAS retry window therefore ends like this:
1. The NAS gives up on the attempt.
2. FreeRADIUS later reads the queued packet with an age of a few ms.
3. It claims and sends an Accept that the NAS ignores.
4. The claim stays `PENDING`, and the subscriber is refused until the audited release.

The 5000 ms budget does **not** fix this: the wait happens before FreeRADIUS can measure it.

**Re-measured this round** (`AV-1.stall_timeline`, real CHR, ms from the first Access-Request):

| Run | FreeRADIUS read | Policy age | Reply on wire | NAS | Claim |
|---|---|---|---|---|---|
| Paused 2 s | 2089 | 13 / 14 | Accept 2102 | session UP | ACTIVE |
| Paused 5 s (resumed late; unpause command took 1.35 s) | 6265 | 33 / 36 | Accept 6283, after the 3rd transmission at 6072 | session UP | ACTIVE |
| Drill-6 length | 10701 | 34 / 38 | Accept 10740 | gave up by 9326 (`authentication failed - radius timeout`) | **PENDING (orphan)** |

In the drill-6 run, the next login was refused (`pending-other`). After the release on NAS-log proof, the subscriber was
served again. Kernel queueing is harmless while the Accept still reaches the NAS before about 9.3 s.

**Required behaviour, verified.**
- **No unsafe acceptance:** the late Accept produced no session (NAS 0 sessions, radacct 0 rows).
- **No automatic expiry:** the claim was still `PENDING` after the refused login.
- **No SNMP unlock:** the release script never reads SNMP; the README states that "no session in SNMP / `/ppp active`"
  is not proof.
- **Audited recovery only on NAS-side invalidation proof:** the RouterOS log line `user … authentication failed - radius
  timeout`.
- **Detection and runbook:** in the README ("Known limitation - FreeRADIUS stall", orphan-candidate query, operator
  recovery, operations controls).

**Remaining production risk (OPEN).**
- An orphan locks a subscriber out until an operator acts. MTTR depends on monitoring and the operations team.
- Frequency in production is unmeasured; it depends on host / VM stalls.
- Required before production: stall monitoring, an alert on the orphan query, and a staffed runbook.

## 2. AV-4 — measured results and final decision

**Change.** `install/freeradius/sites-available/phpnuxbill`: both late checks (before and after the claim) moved from
`> 2000` to `> 5000`, and the comment now gives the rationale and the AV-1 limit. `AdmissionSourceTest` pins 5000. The
RouterOS timeout was not raised, and Strict Mode is unchanged.

**Three clocks, kept separate in every run:**
- (a) the age the policy computed: from the moment FreeRADIUS read the packet;
- (b) wire time from the first Access-Request (pcap on the lab bridge);
- (c) the moment RouterOS abandons the attempt: about **9.3 s** (client failure observed by 9326 ms; NAS log `radius
  timeout`; 3 transmissions at 0 / ~3.0 / ~6.0–6.2 s, identical id and authenticator, `/radius timeout=3s`).

| # | Check | Result (real CHR 7.24.5 unless noted) | |
|---|---|---|---|
| 1 | Slow SNMP guard (lab wrapper, 2.5 s) | relogin after a lost Stop: session check 101 ms, guard done 2872, (a) 2890, (b) Accept 2890; **admitted**, ACTIVE claim taken over, 1 NAS session. At 2000 ms this request is refused. | pass |
| 1b | Guard past its 3 s module timeout (3.5 s) | guard killed at 3337 → (b) Reject 3338, no claim, 0 sessions (fail-closed) | pass |
| 2 | Lost Stop, then legitimate reconnect | `lost_stop_takeover`, Drill 7G in the suite, Option G gate C | pass |
| 3 | Stock `Simultaneous-Use` latency | 101–215 ms in this round's slow-guard runs; 1.7 s worst seen (AV-4 trigger, 2026-10-09); covered by the 5000 ms budget | measured |
| 4 | RouterOS retransmissions at `timeout=3s` | 0 / 3007 / 6017 ms, same id and authenticator; abandoned about 9.3 s | measured |
| 5 | Does the NAS consume the Accept? | claim held 3.5 s → (a) 3505, (b) Accept 3506 → session UP, claim ACTIVE. 4.5 s → (a) 4503, (b) 4504 → UP, ACTIVE. 5.5 s → (a) 5512, (b) **Reject** 5526 → claim released `late_claim`, no session. 6.5 s → (a) 6511, Reject 6516, released. | pass |
| 6 | Lost Access-Accept (NAS drops the replies) | 4 s → the 3rd transmission at 6044 gets the cached Accept → UP; 7 s → no session, claim `PENDING` (AV-3 by design, runbook) | pass |
| 7 | FreeRADIUS pause and resume | §1 table (2 s, 5 s → UP; drill length → AV-1); FreeRADIUS restart with `PENDING` kept | pass |
| 8 | SQL delays and DB interruptions | DB paused 4 s → (b) Accept 4141, UP, ACTIVE; 7 s → Reject 7075, no claim, next login OK. Paused before the claim (fault site) → Reject at 6940 ms, claim unchanged. Connection killed → handled. Missing table → Reject. | pass |
| 9 | Retransmission and reservation reuse | `retx_real_replay` (same token, `attach_count` 1), `retx_synthetic` (in-flight duplicate dropped, cached reply byte-identical, other source port and FreeRADIUS restart re-use the claim, new authenticator refused); lifecycle: creator held 6 s after its claim → released `late_claim`; creator late after an accepted attacher → claim kept `PENDING`, `attach_count` 1 | pass |
| 10 | No double PPPoE session | A2 gates: 48 race trials, 0 doubles (§5); every other case: at most 1 NAS session | pass |

**Decision.** **5000 ms is adopted and documented** (README step 6, site comment), including its limits:
- it covers delays FreeRADIUS can see, including the A1 path (guard up to 3 s);
- it leaves about 4.3 s to the NAS deadline at `timeout=3s`;
- it does **not** cover kernel queueing (AV-1) or a NAS with a shorter timeout (the RouterOS default of 1100 ms gives
  up after about 3.3 s), so `/radius timeout=3s` stays part of the NAS contract;
- **AV-1 trade-off:** an orphan now needs invisible queueing ≥ about 9.3 s − (visible time ≤ 5 s); in the typical case
  (visible time < 0.3 s) that is still about 9 s;
- no unsafe late Accept and no security regression appeared in any run, so the change was **not reverted**.

**Test conditions retuned with the parameter, each keeping both sides of the boundary:**
- harness late-claim holds: 3 s → 6.5 s, plus a new 3 s in-budget case that must be admitted;
- lab fault creator delay: 3 s → 6 s;
- `late_claim_real`: lock held to 6.5 s after the first Access-Request (pcap-anchored);
- `db_interruption_real`: 4 s → now expects UP; a new 7 s variant expects refusal.

The 2000 ms-era records and failures are kept unchanged in `a2r-implementation/` and `a2r-closure/`.

## 3. Drill 6 — contract verification

`FailureDrillsTest::testDrill1and6FreeRadiusUnreachableAndAuthenticationTimeout` keeps its name and every outage
assertion: activation COMPLETED while FreeRADIUS is frozen, no session during the outage, RouterOS timeouts counted, no
application state change. The A1-era expectation "service right after FreeRADIUS returned" is replaced by the Strict
Mode contract under D-AV1. The docblock records the decision and where the earlier failures are kept.

Assertions are split into two groups.

**SECURITY**
- 0 NAS sessions after the late Accept;
- 0 radacct rows for the abandoned attempt.

**AVAILABILITY (AV-1)**
1. A residual `PENDING` claim exists: NAS 10.88.0.2, no accounting, `attach_count` 0, `created_at` after the resume.
2. The NAS-side proof line is present.
3. The next login is refused (`pending-other`).
4. The claim is still `PENDING` with the same token (no automatic release).
5. The audited release prints `RELEASED`, with audit row `release_pending / RELEASED / wp8-drill-operator` plus reason and
   evidence.
6. Service is restored after the release, with an `ACTIVE` claim.

Evidence is written **before** the assertions.

Run result (07:14Z): pass. Residual claim `81000033`, created 07:14:04.97 UTC after the resume at 07:14:04, refused
`pending-other`, released, then served (10.88.10.121).

## 4. Full WP8 regression results

`wp8-integration`, production-contract lab, FreeRADIUS threaded, 07:12:29Z–07:58:38Z:

**Tests 69, assertions 1203, failures 0, errors 0, skipped 13, warnings 3, deprecations 1. "OK, but there were
issues!" — PHPUnit exit code 1.**

- **Exit code 1 has one cause:** the 3 PHP warnings with `failOnWarning="true"` in `tests/phpunit.xml`. No test failed.
  The warnings are `system/autoload/Message.php:13-15` "Undefined variable `$root_path`" (upstream file, untouched since
  `c63545d3`, 2025-03-17), triggered by `RealLifecycleTest::testLegacyReprovisionPasswordAndUsernameOnTheRealNas`.
- **Pre-existing, not a regression:** the WP8 run of the A1 acceptance round, before A2-R, reports exactly "Tests: 69,
  Assertions: 1190, Warnings: 3, Deprecations: 1, Skipped: 13".
- **Deprecation:** the one deprecation also predates A2-R. A probe of `RealLifecycleTest` alone with
  `--display-deprecations` passed with exit 0 and no warnings, so the warnings depend on suite order. The test that
  emits the deprecation was not isolated.
- **Skipped 13 (not passes):** the same lab modes as every earlier round: `CheckradGateTest` ×10 (Option F gate mode),
  `OptionGGateTest` functional mode ×2, and the Drill 7 baseline for NAS type `other` ×1.

| Area | Result |
|---|---|
| CoA / Disconnect (6) | pass |
| Option G gates A–I, hostile usernames, Hotspot shared-1/2/accounting-lost (15 run) | pass |
| Lost Stop: Drill 7G, Option G gate C | pass |
| Drill 1&6 (Strict Mode contract) | pass |
| RealAaa (7) | pass, again on a session id with a hex letter (`0x8100005D`) |
| All other WP8 tests | pass |

## 5. A2 / S-1 security gates

`wp8-security-gates` (07:58Z–08:34Z): **OK (8 tests, 194 assertions), exit code 0.**

| Scenario | Trials | Result |
|---|---|---|
| A2.A baseline | — | pass |
| B0 | 10 | SINGLE_SESSION |
| Bscan (100–5000 ms) | 21 | SINGLE_SESSION |
| C (5 s / 30 s) | 6 | SINGLE_SESSION |
| E1 | 3 | NO_SESSION (fail-closed) |
| E2 | 5 | SINGLE_SESSION |
| E3 | 3 | SINGLE_SESSION |
| **Total race trials** | **48** | **0 double sessions** |

Proof flags `two_accepts`, `nas_two_distinct_sessions`, `clients_up_together` and `traffic_on_both_links` were false in
every trial.

**S-1:** the `UsernameEquivalenceGateTest` case and accent trials refused the second spelling, with at most 1 live
session. The lab gates `S-1.real_spellings`, `S-1.synthetic` and `collision_precheck` also pass.

**Harness** WP4 + WP5A + WP5B (unit + integration), 06:39Z–06:55Z:
- MariaDB: **OK (274 tests, 5791 assertions)**;
- MySQL 8.0: **OK (274 tests, 5791 assertions)**;
- exit code **0**. The 3 extra assertions come from the in-budget case.

## 6. AV-2 and AV-3 notes

**AV-2 (lab evidence, not a per-ISP value).**
- `pool.max` 8 → exhaustion under 40 simultaneous requests: 166 pool errors on 2026-10-09 and 161 this round.
- `pool.max` 32 with `max_servers` 32 → no exhaustion observed.
- The README has a new section "Capacity sizing" (per ISP): load (reconnect storm of the largest NAS, accounting and
  interim rate), per-request cost, `thread pool max_servers`, `pool.max` ≥ `max_servers`, database `max_connections` ≥
  Σ pools + application + margin, resource limits, and failure behaviour (Reject or no answer, never an Accept without
  a claim).
- No production setting was changed.

**AV-3.**
- Recovery stays limited to a `PENDING` claim with that exact token, on NAS-side invalidation proof. Every decision is
  audited (action, outcome, user, token, operator, reason, evidence).
- The README now states that `@nuxr_operator` is free text and does **not** prove identity.
- Simplest pre-production mechanism, proposed only (no UI, no permission system): one database account per operator,
  least privilege on the three tables, and `CURRENT_USER()` recorded in the audit row (one audit column).

## 7. H-1 — open blocker

**"H-1 — HIGH — VERIFIED HOTSPOT USERNAME-EQUIVALENCE BYPASS"**, adopted.
- Evidence kept (`a2r-implementation/lab-evidence.jsonl`, `HOTSPOT.username_variants`): Port-Limit=1 → 2 live sessions
  for case and accent variants, 1 for the same spelling.
- Not fixed in A2-R; Hotspot is unchanged.
- H1-A (an exact-spelling rule in Hotspot post-auth) remains a **candidate**, not an accepted fix.
- The later H-1 plan must cover: canonical username with case and accent variants, `shared_users` 1 and 2, concurrent
  logins, lost accounting, real RouterOS enforcement, and backward compatibility for existing subscribers (a production
  spelling-mismatch impact query).
- H-1 must close **before Production and before the final WP8 closure**.

## 8. Changed files and evidence

Changed **this round** (compared with the round backup):

| File | Change |
|---|---|
| `install/freeradius/sites-available/phpnuxbill` | late budget 2000 → 5000 (2 checks) and comment |
| `install/freeradius/README.md` | late guard (measured boundary, NAS contract), AV-1 known limitation and operations, capacity sizing, operator identity |
| `tests/Unit/Wp4/AdmissionSourceTest.php` | pins 5000 |
| `tests/Integration/Wp4/AdmissionReservationContractTest.php` | late holds 6.5 s, plus the 3 s in-budget case |
| `tests/Integration/Wp8/FailureDrillsTest.php` | Drill 1&6 contract (§3) |
| `dev/wp8/a2r_gate.py` | `late_budget_real`, `slow_guard_lost_stop`, `RowHold`, `fr_ages`, `slow_guard()` (restored by cleanup); `late_claim_real` / `db_interruption_real` re-anchored on both sides of the budget; creator delay 6 s; AV-1 5 s variant; failure-time capture fix |
| `owner-review/WP8/evidence/a2r-final/*` | this report; `lab-evidence.jsonl` (22 records, all gate cases of this round); `lab-phpunit-evidence.jsonl` (suite plus security gates); `phpunit-summaries.txt`; gate console outputs |

Unchanged: `nuxr_admission.sql`, `nuxr_admission_check.sql`, `nuxr_admission_release.sql`, `docs/WP4_RADIUS_MODEL.md`,
A1 files, Hotspot.

Not touched: `install/radius.sql`, `system/`, `docs/claude-development-pack` (manifest **42/42 OK**), production and
AVAIA.

Preserved:
- `a2r-feasibility/`, `a2r-implementation/` and `a2r-closure/`, including the failed and superseded records (the
  invalid cross-NAS run, the AV-1 correction record, the 2000 ms Drill 6 and Drill 7G failures);
- the A1 historical evidence.

## 9. Clean lab and Git status

**Lab.** `lab.sh down` reports qemu 0, netns 0, links 0, agent 0, containers 0, networks 0.
- CHR1's working disk is removed; CHR2 was not started this round and nothing of it remains.
- Only the pinned original image remains (`chr-7.24.5.img`, `.zip`).
- No `pppd.opts`, `.pid` or work images; the guard wrapper was restored inside the container before shutdown.
- **SNMP:** this round's community was randomly generated at lab start (the file had been deleted on 2026-10-09) and its
  files (`snmp.env`, `radius-secrets/nas_snmp`) were deleted after lab down. The value disclosed on 2026-10-09 is in no
  file.
- **Secret sweep:** 40 changed or new files against the 3 lab secrets, the disclosed community and key or community
  patterns: **0 hits**.
- `secrets.env` (mode 600, outside Git) is the lab's persistent secret file and was kept.

**Git.**

| Ref | Value |
|---|---|
| HEAD | `cd09a5893fa10b4650cc2d410cc618c200900687` (= `origin/wp8/integration-poc`) |
| `origin/feat/reseller-system-v1` | `5bdc57459a64dd53e32e5abb2ef6efa3815e387a` |
| `origin/main` | `500d3a2d15003efeed6e0aad639c13d08101c3b1` |
| Uncommitted | 14 modified, 26 new (all A2-R scope) |

No `reset`, `stash` or `clean` was used. Nothing was committed or pushed.

## 10. Production blockers remaining

| # | Blocker | Status |
|---|---|---|
| 1 | **H-1** Hotspot username-equivalence bypass (HIGH) | OPEN: separate security phase before production and before the final WP8 closure |
| 2 | **AV-1** FreeRADIUS stall → orphan `PENDING` → lock-out until the audited release | accepted for development (D-AV1); OPEN PRODUCTION AVAILABILITY RISK; needs production monitoring, alerting and a staffed runbook |
| 3 | **AV-2** capacity per ISP | sizing method documented; per-ISP measurement and settings outstanding |
| 4 | **AV-3** operator identity | free-text name only; per-operator DB accounts plus `CURRENT_USER()` audit proposed, not implemented |
| 5 | NAS contract | `/radius timeout=3s` required for the 5000 ms budget (a shorter NAS timeout is not covered) |
| 6 | PHPUnit exit code 1 from pre-existing upstream warnings (`Message.php`) under `failOnWarning` | not an A2-R regression; a CI policy decision |
| 7 | R-WP8-CARRY-01/02/03, R-HF-CARRY-01 | unchanged |

**A2-R status.** Every A2-R security gate passes. AV-4 is resolved and measured. AV-1 is the only remaining A2-R
availability limitation, documented and accepted for development. A2-R is therefore submitted for final owner review
with notes. It is **not** production-ready and WP8 is **not** closed (H-1 outstanding).

**`A2_R_READY_FOR_OWNER_ACCEPTANCE_WITH_NOTES`**

No commit or push before final owner approval. H-1 and A3 not started.
