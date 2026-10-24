---
name: wp6c-planning-state
description: WP6C ACCEPTED_WITH_NOTES (impl 44505d59, evidence 757d8de9 frozen); origin feat = 8becd6b9 (acceptance recorded, canonical baseline); main 500d3a2d untouched; WP7 awaits owner go
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-05T08:33:29.668Z
---

**WP6C status (2026-10-05).** The owner GO "PLAN ACCEPTED WITH DECISIONS" set:
- OD-1 = A: Adaptive Layer for admin / config pages;
- OD-2 = A: pricing row-level editing surface;
- OD-3: the customer portal is out of scope;
- amendments A–H.

**Candidate (2026-10-05).** Branch pushed (origin `wp6c/mobile-adaptive-ux` = `9cb22105`, evidence commit). Implementation `cd7b43c0`. The ONE full validation on `8ffbf54a` gave 897 tests per engine with 2 failures each: the WP6B `FinanceCorrectionTest` asserts no `fa-check…Confirm` markup, and that collided with the footer confirm dialog. The fix builds the dialog lazily in JS; focused runs are green on both engines. Route matrix 1727/1354/373, byte-identical to WP6B. AVAIA prod-api/web/reconciler restarted mid-run (RestartCount 0, likely an AVAIA deploy; recorded as observed). The owner may request the final full run.

**Accepted 2026-10-05: ACCEPTED_WITH_NOTES.**
- `feat` was fast-forwarded to `757d8de9`, then the docs-only acceptance commit `8becd6b9` (ledger row + `OWNER_DECISIONS.md` + REPORT provenance note) was pushed.
- The ledger lives under the gitignored `docs/claude-development-pack`, so it needs `git add -f`.

**Fix round 1 (2026-10-05).**
- `105d567b`: target-aware row links/actions via `nuxr_staff_visible`, `StaffService::canTarget` and `DataScope::findCustomerByUsername`; plain text otherwise. Also excludes Wp6c from the generic phpunit suites.
- `44505d59`: voucher checkbox names.
- Evidence `757d8de9`, pushed.
- Final full run on `44505d59`: MariaDB 903 PASS; MySQL 7E+1F, all ServiceGateTest. The owner classified it as the AppServer/php-S flake after a 10/10 isolated rerun on both engines.
- No more complete runs.

**Repository state.**
- Branch `wp6c/mobile-adaptive-ux` from `1148559d`:
  - plan commit `ed709a4a`;
  - slices 0–6;
  - two fix commits; implementation HEAD `8ffbf54a`.
- `feat` stays `1148559d` and `main` stays `500d3a2d`.
- Push only the WP6C branch.

**Key implementation facts (reuse).**
- Container-query tables: `.nuxr-adaptive` for WP6A/WP6B pairs; `.nuxr-stack` with `data-label` for legacy admin lists.
- Shared action row: `admin/nuxr/actionbar.tpl` (sticky on phones), plus a confirmation sheet `#nuxr-ask-sheet` (`data-nuxr-ask`, replaces `window.confirm`).
- Other new pieces: `secret.tpl` reveal; `data-nuxr-peek`; Up registry `Navigation::UP`; `deniedShell`; `state-page.tpl`.
- Guard: `tests/Unit/Wp6c/Wp6cGuardTest.php`.

**Gotchas.**
- `body.nuxr-shell :where(summary)` reset outranks single-class components, so `summary.nuxr-icon-btn` needs its own `min-height`.
- The keyboard rule must delay the nav / row return (0.4 s transition), otherwise a tap on Save misses.
- The modern-AdminLTE theme pins button colours with `!important`.
- The lab lacks `user.default.jpg`, which is untracked upstream.
- `bandwidth add-post` with an empty rate 500s (legacy, out of scope).

**Scratchpad tools:** in `wp6c/`: `cfgaudit.py`, `vqa.py` (37 captures), `journeys.py` (J01–J20), `stackfix.py`, `barfix.py`, `labelfix.py`, `radiofix.py`. The validation runner is `wp6cval/run.sh`.

Related: [[wp6b-planning-state]], [[phpnuxbill-dev-env]], [[shared-host-avaia-boundary]].
