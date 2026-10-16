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
