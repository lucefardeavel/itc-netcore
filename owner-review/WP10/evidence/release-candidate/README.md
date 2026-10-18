# Release-candidate evidence (frozen product candidate `e34906d6`; later commits are tests / evidence only)

Verified on 2026-10-18 UTC. `git diff e34906d6 HEAD -- system install ui index.php` is empty at the time of writing.

| Gate | Result | Evidence |
|---|---|---|
| CHR security gates (RouterOS 7.24.5 x2, FreeRADIUS 3.2.8) | OK, 10 tests / 216 assertions | `chr/security-gates.out` |
| CHR wp8-integration (incl. V1 quota, N1 overshoot, N2 renewal, A3 rename) | OK, 121 tests / 2323 assertions, 13 mode skips | `chr/wp8-integration.out`, `chr/lab-evidence-full.jsonl` |
| Full dual-engine harness, MariaDB 10.11.19 | OK, 1534 tests / 76558 assertions, 124 incomplete (real-NAS lab classes, run on the CHR) | `full-run-17da628a.log` |
| Full dual-engine harness, MySQL 8.0.46 | OK, 1534 tests / 76560 assertions, 124 incomplete | `full-run-17da628a.log` |
| Variant registry / schema fingerprints | identical on both engines and to the previous round (V1 `3ca25964...`, V2 `6c2f04ea...`, V3 `e1a66aa3...`) | `full-run-17da628a.log` |
| Closure matrix (36 items) | exit 0 | `closure-matrix-17da628a.md` |
| UI audit (desktop English, phone / tablet Arabic RTL, 6 staff roles + customer login) | 136 page loads, 11 flagged = the documented set (expected 403 denials, customer-login theme contrast / RTL) | `ui/ui_audit.json`, `ui/ui_audit.out` |
| PWA suite T00 - T24 | 32 / 32 PASS | `ui/pwa_suite.out` |
| History after logout (red-team row 15) | no leak | `ui/pwa_history.out` |
| Backup / restore / rollback drill | OK on both engines, 1 test / 128 assertions each | `drill/` |
| Bounded load | PASS on both engines (L1 96/96, L2 96/96, L3 8 completed + 40 refused `ACTIVATION_IN_PROGRESS`; peak 15 of 151 connections) | `load/` |
| Manifest `docs/claude-development-pack/MANIFEST.sha256` | 42 / 42 | - |
| SourceGuard pins | inside the full harness (Source Guard classes of Wp3a, Wp3b, Wp4, Wp5a, Wp9 / U0): 0 failures | `full-run-17da628a.log` |
| PHP lint | 1386 tracked PHP files, 0 errors | - |
| Secret sweep (7 known secret values) | 0 hits over every tracked and untracked file | - |

Not run here (cannot be): everything in `docs/V1_TARGET_ACCEPTANCE.md` (TA-01 .. TA-10, all `NOT_RUN`).
