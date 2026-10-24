# Autonomous execution — progress and handoff ledger

Resume rule: read `AUTONOMOUS_EXECUTION_PLAN.md`, then the last rows here. Never redo a row marked DONE unless new evidence shows a defect.

| # | Item | State | Commit(s) | Evidence / notes |
|---|---|---|---|---|
| 0 | Baseline verification + plan | DONE | (this file) | HEAD 7e467581 clean; protocol text NOT_LOCATED (repo, Drive, Basic Memory) |
| 1 | WP1-CHECK / N-08 (ledger-binding) | DONE (dev evidence) | 2ecfdc2f, af752926 | pre-fix 6/10 fail both engines; post 10/37-39; runner + readiness; pins migrate.php / Runner.php re-pinned |
| 2 | NEW-7 + N-03 + NEW-6 + O-T0-3 | DONE | 2dea76d7 | AppKey ($app_secret), c3/l3 tokens; pre-fix forged db_pass cookie/link/staff/invoice ACCEPTED; post 10/27 + 2/5 both engines |
| 3 | NEW-8 | DONE | f8bf19fc | PageTemplate sandbox; pre-fix page template ran `id`, leaked staff hash; post 12/65 both engines |
| 4 | N-07a CSV | DONE | 6af1e8e8 | csvLine/csvCell; csv-prepaid ambiguous status 500 fixed |
| 5 | N-11 + O-T0-1 secrets in UI | DONE | 0da0a4ed | write-only settings / router password; post-auth passwords masked |
| 6 | R-HF-CARRY-01 / N-12 | DONE | 7e4919c8 | tree-scoped staff: no router/plan/bw metadata; customer_is_active only held plan |
| 7 | NEW-7 follow-up | DONE | 4bad1ca3 | SecurityRoutesTest positive control = AppKey signature |
| 8 | Phase B N-01/N-02 | DONE | a6aa0011 | customerAddPlan guard; stored plan_expired; plugin errors reported; radius.php plugins after RestGate; plugins.php inventory |
| 9 | R-4 / N-04 cron vs renewal | DONE | 39c7aed5 | ServiceLock L-S outermost; pre-fix paid COMPLETED + RADIUS suspended (both engines) |
| tools | prefix worktree /srv/phpnuxbill/wp9-prefix @7e467581 (vendor hardlinked) for test-first; scratchpad tools/prefix.sh, precommit.sh, sub.py (CRLF aware) | | | |
| 10 | P1-P5 policies (option B) | DONE | 614bcae0 | replay re-auth; admin-disabled reactivation; direct-child password; owner-only Sales assign; Sales sees current-owner activations |
| 11 | N-06 CLI guard | DONE | 43007e35 | reconcile floor 300 s; resume stale only |
| 12 | A2-R hardening L1-L5 + N-17 | DONE (dev + FreeRADIUS harness); CHR regression pending (W9-E) | b4223961 | CHECKs, echo, SAFE flag, README; enforcement check -> app preflight (TODO system/reseller/preflight.php in WP10) |
| next | AV-3, release profile (legacy drivers), R-FC-1, N-10, OBS-1 tools, W9-OPS recovery CLI, P3 R-15, P1 lock gate, N6/N7, TAMPER/LPDB, W8-14, W8-17, OBS-S7-4, red team, full harness, CHR | | | |
| 13 | AV-3 attested release audit | DONE | 2c7f0b4d | [db_account=CURRENT_USER() connection=..] |
| 14 | Release profile (legacy MikroTik-API + rlm_rest disabled) | DONE | cf19e04e | Package.php re-pinned 9759fca7 -> 51e8c47b; harness opts in ($legacy_devices_enabled) |
| 15 | W9-OPS recovery tools + runbook | DONE | 558acddc | finance-check (IntegrityCheck), voucher-review/resolve, admission-pending/release; docs/OPERATOR_RECOVERY_RUNBOOK.md |
| 16 | LPDB grants + preflight + CHECK via SHOW CREATE TABLE | DONE | 58f5eadf | MariaDB hides CHECK_CONSTRAINTS from table grants (finding fixed) |
| 17 | P3 R-15 sealed bandwidth terms | DONE | 27a3a81f | BANDWIDTH_TERMS_CHANGED |
| 18 | P1 lock gate (stale policy session) | DONE | c0d7f8ab | drift SESSION_POLICY_STALE + session-end CLI |
| 19 | W8-14 same-key Hotspot + PPPoE | DONE | ca168b7d | RADIUS_KEY_SHARED (forbid the configuration) |
| 20 | Red team HTTP / API batch | DONE | d75a07a5, 7ab1e52f | arrays in safedata; no-shell scan; OBS-S7-4 re-test (held at base) |
| 21 | N6 / N7 | DONE | 95ae997f | DB-stored secrets redacted; NAS onboarding / rotation runbook |
| 22 | D-S8-4 lock docs sync | DONE | dc4b0176, e918be9d | cherry-pick 8b0ee336, manifest 10/10 OK; dated status lines refreshed |
| 23 | D-S8-6 stale comments | DONE AT BASE | 704031c6 | phpunit.xml + ConcurrentFirstLoginGateTest docblock already corrected; nothing to change |
| 24 | SC-1 CDN Subresource Integrity | DONE | 9a6c12e5 | 30 tags; pre-fix 30 findings, post 0 |
| 25 | Full harness run 1 (95ae997f) | FAILED (kept) | 1dc37552 (log) | MariaDB 1476 / 8 failures (source guards, deterministic); MySQL not completed (stopped, superseded) |
| 26 | Source-guard regressions fixed in code (no list / assertion change) | DONE | 1dc37552 | IntegrityCheck -> SettlementReadModel; DriftReport via AccountingService; execute switch in body; CLI guards first |
| 27 | SEC-17 staff hashes, INST-1 installer, SESS-1/HDR-1 cookies + headers, W8-17 legacy rename audit | DONE | ad3b6bf3, b0184aaa, cf1bdfec, 13182b37 | all test-first (pre-fix failing both engines); 5 format-bound assertions restated (SEC-17 contract change, listed in the commit) |
| 28 | WP10 prep: health, drill, load, runbooks, licenses | DONE (dev) | 29d374ea | drill PASS both engines; load PASS both engines (peak 15/151) |
| 29 | XSS-1 stored XSS across roles (final red team) | DONE | b5b441d4 | 249 outputs escaped; pre-fix 13 renderings both engines, post 0 |
| 30 | UI audit + customer a11y; PWA suite; row #15 history | DONE | c298b21d | 130 loads clean (staff); PWA 32/32; history PASS |
| 31 | CHR final regression (W9-E2) | DONE | 46624cd8 | security gates 10/216; integration 87 pass / 13 mode skips / 1 parser failure fixed, re-run 15/15 |
| 32 | Final full harness (W9-E1) | DONE | 46624cd8 | MariaDB 1493/75946, MySQL 1493/75949, 0 failures, 104 incomplete (lab classes) |
| 33 | Final package | DONE | (this commit) | owner-review/WP10/SAAS_RADIUS_FINAL_PRODUCTION_READINESS_PACKAGE.md: RELEASE_BLOCKED_WITH_EVIDENCE; one decision requested (WP9 acceptance + D3 quota contract) |
| 34 | V1 GB quota (Q2) + Option B renewal (Q1/P2): plan, capability probe, policy, FreeRADIUS in-session share, enforcer, SessionTermination, plan forms | DONE | 4fe1b190, 2279a787, 9cca32dd, 02860410, e3d1b693 | owner QUOTA_V1_GB_SUPPORT_APPROVED / RENEWAL_OPTION_B_APPROVED (2026-10-17); plan owner-review/WP10/V1_QUOTA_RENEWAL_PLAN.md |
| 35 | Period attribution NAS-clock allowance (first session after a renewal escaped the new quota; DB time-zone hypothesis refuted) | DONE | e4162092 | NAS Event-Timestamp 1.1-1.74 s early; 3 tests failing both engines before; boundary = start - 10 s |
| 36 | A3 case-only rename regression (device termination vs A3 Disconnect) | DONE | 8b6f5baa | real-NAS wp8-integration found it; harness failing-first both engines; CHR A3 6/141 |
| 37 | Voucher GB path; preflight quota.plans; lab determinism + Hotspot renewal | DONE | 37c1aead, 9a869280, 3bf52486 | V1VoucherQuotaTest 2/14; preflight WARN for pre-V1 invalid plans (checklist E8) |
| 38 | Drill, load, UI audit (136 loads), PWA 32/32, final CHR (gates 10/216; integration 109/2055, 13 skipped) | DONE | a4ca655c, 32cdc92d | evidence/quota/{final,chr-final} |
| 39 | Final full harness on the final commit (32cdc92d: MariaDB 1521/76375, MySQL 1521/76381, 0 failures, 112 lab-incomplete) + acceptance package | DONE | (this commit) | owner-review/WP10/SAAS_RADIUS_V1_GB_QUOTA_AND_RENEWAL_FINAL_ACCEPTANCE_PACKAGE.md: READY_FOR_PRODUCTION_OWNER_APPROVAL (not Production Ready); decisions N1, N2, D1, D2, D4-D10 |
| 40 | N2 exact period ownership (accounting-row arrival order) + N1 levers (configurable interim, outcome-dependent retry, batched termination) | DONE | 0ec84def, ae99c89e | CoA cannot change PPPoE byte limits or interim (measured); owner-review/WP10/N1_PPPOE_OVERSHOOT.md, N2_PERIOD_OWNERSHIP.md, DECISIONS.md |
| 41 | N1 measured on the CHR (10 cases), target acceptance procedure TA-01..10 | DONE | d59fb61f, e34906d6 | V1OvershootLabTest 10/219; docs/V1_TARGET_ACCEPTANCE.md all NOT_RUN; frozen product candidate e34906d6 |
| 42 | Frozen-candidate verification: CHR gates 10/216 + 121/2323; full harness MariaDB 1534/76558, MySQL 1534/76560 (0 failures); closure matrix 36 items; UI 136 loads, PWA 32/32; drill 128; load PASS; manifest 42/42; lint; secret sweep 0 hits | DONE | 17da628a, fcb6d8bb, fe2c4424, 94d1d3d1 | evidence/release-candidate/ |
| 43 | Final package: owner-review/WP10/SAAS_RADIUS_V1_FINAL_OWNER_RELEASE_DECISION_PACKAGE.md | DONE | (this commit) | RELEASE_BLOCKED_WITH_EVIDENCE (target-environment TA-01..10 NOT_RUN; owner decisions N1, N2, D1, D2, D4-D10 open); nothing pushed |
| 44 | Owner decisions of 2026-10-18 (N1 = A + G, N2, D1, D2, D4 - D10): Option G read model + new-period fix, N2 matrix, D8 masking, D9 sink fixes + customer UI, set-based enforcer + 30 s default after the capacity measurement, OBS-1 report, licence guard, decision documents | DONE | 32f0bfd8, 4f0481d6, f0a883bf, a655d8c5, fb279e03, 9da2015e | owner-review/WP10/V1_OWNER_DECISION_RECORD.md, CAPACITY_AND_ACCOUNTING_LOAD.md |
| 45 | Final gates on 9da2015e: harness MariaDB 1561/76805 + MySQL 1561/76807 (0 failures), CHR 10/216 + 122/2383, UI 136 / PWA 32/32, drill 128, load PASS, closure 44, manifest 42, lint 1398, sweep 0; load finding (deadlock refusal, pre-existing) disclosed | DONE | (this commit) | evidence/final-v2/ |
| 46 | Final package SAAS_RADIUS_V1_TARGET_RELEASE_ACCEPTANCE_PACKAGE.md | DONE | (this commit) | RELEASE_BLOCKED_WITH_EVIDENCE (TA-01..10 NOT_RUN; OBS-1 real data, legal review, A2-R adoption, deployment authorization open); nothing pushed |
| 47 | Final operational closure: O-DL study (existing MariaDB contention limit, not a regression, fail-safe verified), N1 + G verification, staging package + authorization request + TA execution sheets + evaluators, OBS-1 runbook, A2-R evidence, legal package | DONE | (this commit) | owner-review/WP10/SAAS_RADIUS_V1_FINAL_OPERATIONAL_CLOSURE.md; no product change after 9da2015e |
| 48 | ITC NetCore product identity (owner decision 2026-10-18): Brand source, all visible surfaces (desktop / phone / PWA, Arabic + English, RTL), credits of the original project kept, LICENSE unchanged, internal names unchanged; README / NOTICE / BRAND_IDENTITY; legal Q12 - Q14 | DONE | (this commit) | owner-review/BRANDING/ITC_NETCORE_BRANDING_REPORT.md; new product candidate = this commit |
| 49 | Product guide round: quota card decimal units (50 GB read as 46.6 GB), 486 missing Arabic entries + guard, translated titles / labels / activation notices; illustrated guide of all 23 areas (published outside the repository) | DONE | (this commit) | owner-review/BRANDING/ITC_NETCORE_BRANDING_REPORT.md section 9 |
| 50 | Repository sync (owner 2026-10-24): resume file `owner-review/CURRENT_STATE_AND_OPEN_NOTES.md`, guide tooling `dev/guide/`, the remaining A/B load logs, agent-notes snapshot; branch `wp9/red-team-rc` pushed to GitHub with every local commit; `feat` / `main` not moved | DONE | (this commit) | owner-review/CURRENT_STATE_AND_OPEN_NOTES.md |
| sync | Basic Memory: trial expired, NOT synced. Google Drive: the previous round's package was uploaded; the V1 acceptance package (row 39) was NOT uploaded (nothing was sent to an external service this round) | | | |
