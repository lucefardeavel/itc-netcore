---
name: wp4-planning-state
description: "WP4 RADIUS hardening implemented on local wp4/radius-hardening @ 58e3e458 (from feat f2269a06), IMPLEMENTED_NOT_ACCEPTED, not pushed; owner decisions Q1-Q12"
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-02T17:47:35.157Z
---

The owner accepted WP3B on 2026-10-02 at `460714de`.
- Recorded locally: `feat/reseller-system-v1` was fast-forwarded and metadata commit `f2269a06` added (ledger row plus `owner-review/WP3B/OWNER_DECISIONS.md`). **Not pushed.**

WP4 was implemented on 2026-10-02.
- Branch `wp4/radius-hardening` from `f2269a06`, HEAD `58e3e458`, local only.
- Owner verdict: **ACCEPTED_WITH_NOTES** at `58e3e458`. Recorded locally: `feat` fast-forwarded plus metadata commit `0c080a7a` (ledger row and `owner-review/WP4/OWNER_DECISIONS.md`).
- Nothing is pushed: `origin/feat` is still `d4ba4514`.
- Authoritative descriptions: `docs/WP4_RADIUS_MODEL.md`, `owner-review/WP4/REPORT.md`, `install/freeradius/README.md`.

Owner decisions (in the model §11):
- Q1: FreeRADIUS 3.2.8 test containers, pinned by digest.
- Q2a: project dictionary and counters, with period-scoped quotas via `Nuxr-Period-Start`.
- Q3: `radius.php` off unless `$radius_rest_secret` is set, plus a source allowlist.
- Q4: inverted CHAP fixed (proven: the old code gave right=false, wrong=true).
- Q5: RADIUS expiry = saved billing expiry. Owner approved the pinned `Package.php` reorder and the hash pin update.
- Q6–Q12: as recommended.

Totals: 539 tests / 43804 assertions per engine; WP4 suites 69 / 567; route matrix 1442/1164/278 unchanged.

The AppServer port-reuse flake has now been seen 3 times. A harness fix needs owner approval (recommended in the report).

**Why:** the next session must not redo WP4 and must not push without approval. WP5A starts only on the owner's explicit go-ahead, from `feat` `0c080a7a`.

**How to apply:**
- WP5A must follow the WP4 model §10 (ProvisionResult → `provisioning_result`; UNKNOWN → reconciliation; never write radacct) and the WP3B §9 contract.
- Related: [[wp3b-plan-decisions]], [[phpnuxbill-dev-env]], [[shared-host-avaia-boundary]].
