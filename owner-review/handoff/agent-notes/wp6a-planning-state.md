---
name: wp6a-planning-state
description: "WP6A ACCEPTED_WITH_NOTE (impl f71e239f, evidence ed014525); feat = 81d49243 (acceptance recorded, pushed); main 500d3a2d untouched; WP6B awaits owner go"
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-04T15:55:37.145Z
---

**ACCEPTED (2026-10-04):** owner verdict ACCEPTED_WITH_NOTE (note R-15: bandwidth re-read live at provisioning, future hardening, do not change in WP6A). feat fast-forwarded --ff-only to ed014525 + acceptance commit 81d49243 (ledger row + owner-review/WP6A/OWNER_DECISIONS.md), pushed. Ledger file is under a gitignored dir: stage it with `git add -f`. Next: WP6B only on explicit owner go.

**Fix round 1 (2026-10-04):** owner FIX_REQUIRED on 4722fd53 — preview fingerprint did not bind plan service terms / bandwidth rate. Fixed in `f71e239f` (`ActivationPreviewService::serviceTerms`, material v2 = sealed planJson minus name_plan/price + RATE_COLUMNS). Final full run 828/828 per engine, 1656/0. Evidence `ed014525` pushed. **Both full runs are now used** — any further owner fix needs owner direction on validation.

**Earlier status (2026-10-04):** WP6A is implemented and pushed to `origin/wp6a/adaptive-shell-subscriber-ux` from base `f806a618`. Nothing else was pushed: `feat` is still `f806a618` and `main` is still `500d3a2d`. No PR. Awaiting the owner's code review; next step is reading their verdict.

**Heads:**
- Full-validation candidate: `e21ff687`. Testdox 812/812 per engine, 1624 ✔ / 0 ✘. Route matrix 1477/1185/292 (+7 `activation/list` GET rows). Lint 456/456.
- Implementation HEAD: `4722fd53`. The visual-QA fix on top of the candidate (CSS/JS/markup only; covered by focused wp6a tests 34/34 on both engines).
- Evidence HEAD: `9812ad7b`. Contains:
  - `owner-review/WP6A/{REPORT, TESTDOX, VISUAL_QA}.md`, plus `visual/` with 18 PNGs;
  - `docs/WP6A_UX_MODEL.md`;
  - amendments to the WP5A and WP2 docs.

**Cadence:** one full run has been used, so at most one more is allowed, for an owner-fix round.

**Why:** the owner caps full runs at two per WP, so the second must be saved for their fix round.

**How to apply:** for owner fixes, reproduce → focused fix → focused regression, then ONE final full run because the candidate changed.

**Owner decisions applied:** OD-A … OD-L, as accepted on 2026-10-04.

**Visual lab (reuse):**
- Snapshot: `/srv/phpnuxbill/uxlab` with `tests/Lab/UxLabTest.php`.
- Start sequence:
  1. `devloop.sh up`;
  2. `docker run nuxbill-reseller-test-uxlab` on network `nuxbill-reseller-test_default`, mounting `/srv/phpnuxbill/dev:/live:ro`;
  3. `scratchpad/wp6a/labsync.sh`.
- QA script: `owner-review/WP6A/visual/visualqa.py <ip> <out>` (axe-core 4.10.2 injected from the scratchpad).
- Stop: touch `uxlab-stop`, then `devloop.sh down`.
- Playwright clicks need `>> visible=true`, because nav buttons are duplicated across the rail and bottom nav.

Related: [[wp5b-planning-state]], [[shared-host-avaia-boundary]], [[phpnuxbill-dev-env]].
