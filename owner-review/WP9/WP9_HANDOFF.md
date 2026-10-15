# WP9 — Execution handoff (revision 3)

This is a short checklist for the session that executes WP9 after the next owner approval. Read `owner-review/WP9/PLAN.md` (revision 3) first. This file contains no results.

## State at handoff (2026-10-15)
- **Canonical baseline:** `feat/reseller-system-v1@704031c61296f4975f1d4e51531973d67e5f0a9b`.
- **Local branch:** `wp9/red-team-rc` at `704031c6`, with **no upstream** (OD-W9-1). `owner-review/WP9/PLAN.md` and this file are committed locally in one commit (D-W9-R13, approved in rev 3); not pushed. T0 evidence and `REPORT.md` are not part of that commit.
- **Protected refs that must not move:**
  - `main` `500d3a2d`
  - `wp7/staff-pwa` `8e7f0e1c`
  - `wp6c/mobile-adaptive-ux` `757d8de9`
  - `hotfix/pace-xhr-csrf` `6d4f3f96`
  - `feat` (moves only on an owner integration decision)
- **Git identity:** `-c user.name="ALI HENDI" -c user.email=ali.hahuba3@gmail.com`. Trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Unchanged in WP9 so far:** product code, databases, RADIUS configuration and RouterOS.

## Owner decisions in force (PLAN §0)
| ID | Decision |
|---|---|
| OD-W9-1 | Local branch only; no push |
| OD-W9-2 | A2-R design only; no schema change |
| OD-W9-3 | Static / inert only: no reproduction, pressure, fault injection or simulation of SQL exhaustion. Unit tests without services are allowed in the plan, but are not yet executed |
| OD-W9-4 | OBS-1 application-level protection: proposal only |
| OD-W9-5 | Test-first, then owner-approved bounded waves |
| OD-W9-6 | Owner policy pending: no permission change |
| OD-W9-7 / 8 | Remediation required before production; options first |
| OD-W9-9 | Plugin trust-boundary study |
| OD-W9-10 | CHR test planning only |
| OD-W9-11 | WP9 = bounded security concurrency; WP10 = capacity, load and long-duration testing |

## Rev 3 owner decision (2026-10-15)
`WP9_PLAN_REV2_ACCEPTED_WITH_AMENDMENTS`, `WP9_T0_STATIC_TESTING_AUTHORIZED`, `PRODUCTION_NOT_AUTHORIZED`.
- **AM-W9-1:** the identity-lock design is corrected (PLAN §7.3). Global order: tree lock → voucher username lock → identity lock → `BEGIN`. Named locks are only taken outside a transaction, and the identity lock is never held across device, network or RADIUS-database calls. `Tx.php` is not changed (P1 would use `TreeSerialization::hold` + `CustomerIdentity::hold` + `Tx::run`). **U0 is not implemented.**
- **AM-W9-2:** the test criterion is **NO UNAUTHORIZED BUSINESS / FINANCE / IDENTITY / SERVICE STATE CHANGE**. Expected refusal records are allowed and must be listed.
- **AM-W9-3:** vouchers stay outside I-1 for the study only. V1–V4 are studied separately (PLAN §7.7). OBS-1 is not closable for production while a collidable RADIUS identity remains.
- **T0:**
  - Runs as Python 3 standard-library static checks (`python3 -I -B`). The host has no PHP, and Docker is not allowed in T0.
  - No DB, container, FreeRADIUS, CHR or HTTP / PWA lab. No product-code, schema or pin change.
  - PHP-executing checks are NOT TESTED until an alternative is approved.
- **Commit:** one local commit of `PLAN.md` + `WP9_HANDOFF.md`. T0 evidence (`owner-review/WP9/evidence/t0/`) and `REPORT.md` (T0 section only) stay uncommitted.

## Next approvals expected (PLAN §18)
1. **D-W9-R1:** T0 static / inert tests — **approved (rev 3), executed and accepted** (`WP9_T0_STATIC_ACCEPTED_WITH_NOT_TESTED`). Afterwards T0+ (PHP) and T1-A were authorized and executed; T1-A stopped at the V1 stop trigger (see `REPORT.md`). *Status note 2026-10-15; the rev-3 text is otherwise unchanged.*
2. **D-W9-R2:** T1–T3 harness, HTTP and PWA lab.
3. **D-W9-R3 … R13:** A2-R implementation scope, the OBS-1 invariant and U0, the policies P1–P5, NEW-7 / NEW-8 options, the plugin policy, the re-pins, CHR execution, and the commit of the plan.

## Never
- Do not run the halted A2-R SQL-exhaustion reproduction, under any name or method. Never run:
  - `dev/wp8/a2r_sql_gate.py`
  - `a2r_gate.py pool …`
  - `a2r_gate.py fault on`
- No change of `pool.max`, no connection holding, no burst or load against FreeRADIUS SQL, and no SQL-connection fault tool.
- No production, real MikroTik, AVAIA resources, `docker prune` or name-based kills.
- Never run `dev/test/run-tests.sh` while the CHR lab is up.
- Never put secrets on a command line or in logs or evidence. Run the secret sweep before every commit.
- No schema, SourceGuard-pin, permission-policy or architecture change unless an approval names it.
- Do not edit the Architecture Lock folder, and do not change the ledger without an owner instruction.
- Do not start WP10.

## Tools to reuse (PLAN §14)
- **Harness:** `dev/test/run-tests.sh` (MariaDB 10.11 and MySQL 8.0).
- **Test helpers in `tests/Support`:** `Wp2Seed`, `Wp2HttpTestCase`, `HttpClient`, `FinanceSeed`, `FinanceWorkers`, `FinanceInvariants`, `ActivationTestCase`, `StubNas`, `RouteInventory`.
- **Fault hooks:** `ActivationFaults`, `RadiusFaults`.
- **PWA browser lab:** `dev/pwa-lab/`.
- **New suites:** `wp9-unit` and `wp9-integration`.

## Evidence and stop rules (PLAN §16)
- Characterise before any fix.
- Incomplete, skipped or aborted runs are never passes; keep every failed run.
- Each negative case has a positive control, and a refusal causes no unauthorized business / finance / identity / service state change (AM-W9-2); list the expected refusal records.
- Fingerprint the tree before and after each validation run.
- Manifest must be 42/42.
- On any stop criterion in PLAN §16.4: stop, keep the evidence and report.
- `owner-review/WP9/REPORT.md` holds actual results only, with the A–M evidence packet.
- End every stage with `STOPPED_WAITING_OWNER_REVIEW`.
