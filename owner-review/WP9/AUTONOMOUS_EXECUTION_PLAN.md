# WP8 / WP9 / WP10 — Autonomous Execution Plan (owner `WP8_WP9_WP10_AUTONOMOUS_EXECUTION_AUTHORIZED`, 2026-10-16)

**Base:** `wp9/red-team-rc` at `7e46758114545dd4a6d2b0179e9d5d7ceb60e643` (verified clean; protected remote refs unchanged: feat / wp8 `704031c6`, main `500d3a2d`, wp7 `8e7f0e1c`, wp6c `757d8de9`, hotfix `6d4f3f96`).
**Approved plan executed:** `WP8_WP9_UNIFIED_CLOSURE_AND_RELEASE_ROADMAP.md` (this folder).
**Progress / handoff ledger:** `AUTONOMOUS_PROGRESS.md` (updated at every checkpoint; the resume point after any context compaction).

**Hard boundaries kept:**
- no production, no push to protected branches, no merge, no force push;
- no live router;
- no SQL-exhaustion reproduction, no pool pressure, no SQL connection fault injection;
- no secret on a command line or in evidence;
- AVAIA untouched; only the `nuxbill-reseller-test` compose project and the isolated CHR lab;
- no risk acceptance on the owner's behalf;
- no WP9 / WP10 ledger acceptance without the owner (ledger rule: "never mark … unless the owner explicitly states that decision").

## 0. Acceptance-protocol source (D-S8-9)

The text of "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" was searched in:
- the repository;
- Google Drive (other projects record it as `NOT_LOCATED`);
- Basic Memory.

It was **not located**. The final audit therefore uses:
- `docs/claude-development-pack/docs/DEFINITION_OF_DONE.md`;
- the WP9 gates (`PLAN.md` §16);
- the WP10 prompt and the Architecture Lock release gates (Q1–Q4, P1–P3, N1–N7, REL-01–05, ISO-01, Session gate);
- the protocol's *known structure* as recorded on Drive (one integrated audit → one defect register → one corrective round → independent verification → owner acceptance).

No textual conformity to the protocol is claimed, and no requirement is invented from it.

## 1. W9-0 decision batch (owner pre-authorization §2: lowest risk, consistent with the lock)

| ID | Decision taken | Reason |
|---|---|---|
| OD-W9-2 | Implement the A2-R layered design L1–L5 (PLAN §6.5) + key echo. Evidence: static + DB-level CHECK tests on both engines + CHR security-gate regression in normal operation. No exhaustion reproduction | defensive closure without the forbidden test |
| OD-W9-6 | P1–P5 = option **B** each (conservative column of PLAN §10) | no progression after authority loss; tighter staff authority; no cross-owner exposure |
| OD-W9-7 | **O7-A** dedicated app key + purpose subkeys; customer cookie HMAC with expiry and password binding; cookie flags; no `?uid=` session token; purpose-bound link tokens | closes NEW-7, N-03, NEW-6, O-T0-3 |
| OD-W9-8 | **O8-A** Smarty security policy for page templates; bundled page templates replace the GitHub fetch | closes NEW-8 with low regression risk |
| OD-W9-9 | **B1′:** guard `Radius::customerAddPlan`; validate a caller-supplied `plan_expired`. **B2:** plugin load errors logged; plugin inventory (file + SHA-256) operator report; "plugins are fully trusted code" contract; `radius.php` authenticates before loading plugins. **B3:** least-privilege DB accounts (WP10) | default deny where application code can enforce it; documented trust where it cannot |
| OD-W9-10 | CHR execution authorized (owner §5) | — |
| WP1-CHECK | Fix by normalized-expression comparison + enforcement detection; re-pin SchemaInspector / Runner under the protected-file exception (test-first; old / new SHA recorded) | ledger-binding |
| D-S8-4 | Land the lock documents (`8b0ee336` tree, verbatim) on this branch as documentation; reconcile C-04 in text only | no locked decision changes |
| D-S8-6 | Correct stale or misleading test comments only | no assertion or result change |
| Feature scope (W8-9, W8-14, W8-15, W8-22, W8-23, W8-26, W8-8) | **Release profile `radius`:**<br>• only the `Radius` device is enabled;<br>• legacy MikroTik-API drivers (Hotspot / PPPoE / VPN) are refused by an explicit release-profile guard, with tests proving they cannot be reached;<br>• rlm_rest stays off unless `$radius_rest_secret` is configured (documented as not production-proven);<br>• MAC auth is a preflight item.<br>Each disabled feature is documented as a limitation; enabling one needs its open findings closed first | owner §5: an unsafe, conditional legacy driver may be disabled explicitly |
| R-FC-1 | Mask voucher codes in non-SuperAdmin display surfaces where they act as credentials; financial records unchanged | least exposure without a finance change |
| W9-OPS | A safety-gated operator CLI (dry-run default, explicit confirm, audited, idempotent, never deletes history) plus a runbook | owner §3 |
| P2 (lock) | Keep the RENEW contract (the existing session ends at its old Session-Timeout and re-authenticates with the new terms); prove it on CHR | no new network action |
| P3 (lock, R-15) | Provisioning verifies the sealed bandwidth terms; a mismatch fails before any network write | bind / verify gate |

Any decision that would contradict a locked decision is **not** taken here. It goes to the single owner question at the end.

## 2. Execution order (dependency-driven)

1. **Security fixes (W9-D / W9-B):**
   - WP1-CHECK;
   - NEW-7 (+ N-03, NEW-6, O-T0-3);
   - NEW-8;
   - N-07a;
   - N-11 / O-T0-1;
   - N-12 / R-HF-CARRY-01;
   - N-01 / N-02;
   - N-04 R-4;
   - P1–P5;
   - N-06;
   - AV-3;
   - A2-R + N-17;
   - release profile;
   - R-FC-1;
   - N-10.
2. **OBS-1:**
   - existing-data survey + operator resolution tool;
   - R0008 schema option only if needed and tested (migration / restore / rollback, both engines).
3. **W9-OPS:** recovery tool and runbook (PENDING, CLAIMED_REVIEW, DEVICE_UNKNOWN, UNCONFIRMED / RECONCILIATION_REQUIRED, REVERSAL_PENDING, REPROVISION_UNCONFIRMED, HELD, OLD_SESSION_UNCONFIRMED, orphans).
4. **W9-C red team:** the PLAN §12 matrix rows not yet executed, with re-tests of the fixes.
5. **W9-E:**
   - full dual-engine harness;
   - CHR final regression (`wp8-security-gates`, `wp8-integration`, the 104 lab tests, plus lock gates Q / P / N where the lab can prove them);
   - secret sweep, manifest, pins;
   - REPORT.
6. **WP9 gate:** ledger stays owner-controlled. With zero WP9 blockers, record `WP9_READY_FOR_OWNER_ACCEPTANCE` and continue with WP10 independent preparation.
7. **WP10 (isolated staging):**
   - deployment profile;
   - least-privilege accounts;
   - key provisioning;
   - UTC / NTP / isolation preflight;
   - backup + actual restore drill;
   - migration / rollback drill;
   - safe load tests (no SQL-pool pressure);
   - health / alerting;
   - runbooks;
   - license report;
   - UI / RTL / mobile browser audit.
8. **Final audit** → `SAAS_RADIUS_FINAL_PRODUCTION_READINESS_PACKAGE`.

## 3. Method per item

1. characterise (test-first; keep failing runs);
2. minimal fix;
3. focused tests on MariaDB + MySQL;
4. local commit (fix and evidence);
5. full harness at milestones.

Pins change only under the protected-file exception, with old and new SHA recorded.
