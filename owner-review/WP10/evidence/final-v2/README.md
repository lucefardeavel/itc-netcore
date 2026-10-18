# Final-candidate evidence, round 2 (candidate `9da2015e`; 2026-10-18)

| Gate | Result | Evidence |
|---|---|---|
| Full dual-engine harness | MariaDB 1561 tests / 76805 assertions, MySQL 1561 / 76807, 0 failures, 125 incomplete (CHR lab classes) each | `full-run-9da2015e.log` |
| Closure matrix | 44 items, exit 0 | `closure-matrix.md` |
| CHR security gates | OK 10 / 216 | `chr/security-gates.out` |
| CHR integration | 122 tests / 2383 assertions, 13 mode skips, 1 failure (lab-test timing precondition, kept) -> fixed, V1 lab classes rerun OK 10 / 224 | `chr/` |
| UI audit / PWA / history | 136 loads, only expected 403 flags / 32 of 32 / no leak; XSS negative control | `ui/` |
| Drill | OK both engines, 128 assertions each | `drill/` |
| Bounded load | PASS both engines (4 final runs); the first MariaDB run failed its strict L2 assertion: kept (`load/...FAILED...`), investigated (`load-repeat*`, `load-contract-hunt*`), assertion restated to the accepted contract with a recovery proof (`load-final/`) | `load*/` |
| Manifest / lint / sweep | 42 of 42 / 1398 files, 0 errors / 0 hits | - |
| Capacity | `../capacity/` (baseline, set-based, kept failed and superseded runs) | `../../CAPACITY_AND_ACCOUNTING_LOAD.md` |
| TA lab stand-in (not the target) | `ta-lab/` | all TA rows stay `NOT_RUN` |
