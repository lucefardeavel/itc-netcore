---
name: hotfix-pace-xhr-csrf-state
description: "Pace/XHR CSRF hotfix ACCEPTED_WITH_NOTES 2026-10-06; hotfix branch frozen 6d4f3f96; origin feat = 5bdc5745 (acceptance recorded, canonical baseline); R-HF-CARRY-01 OPEN; waiting owner, no WP8"
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-06T08:24:25.531Z
---

**Accepted 2026-10-06: ACCEPTED_WITH_NOTES.**
- `hotfix/pace-xhr-csrf` is frozen at `6d4f3f96`.
- `feat` was fast-forwarded to `6d4f3f96`, then the docs-only acceptance commit `5bdc5745` (parent `6d4f3f96`) was pushed:
  - `OWNER_DECISIONS.md` and a REPORT provenance note;
  - the WP7 doc with R-WP7-CARRY-01 CLOSED;
  - a new "Post-WP7 hotfixes" section in the ledger (`git add -f`).
- `wp7` stays `8e7f0e1c`, `wp6c` `757d8de9`, `main` `500d3a2d`.

**Open item.** R-HF-CARRY-01 (autoload router/plan metadata exposure to Agent/Sales): OPEN / MEDIUM / pre-existing, recorded only. Do not fix or plan it without the owner's instruction.

**Next.** WP8 planning was authorized and done on 2026-10-06 (see [[wp8-planning-state]]); waiting on the owner for OD-W8-1.

**Candidate (2026-10-06).** Pushed: origin `hotfix/pace-xhr-csrf` = `6d4f3f96` (evidence commit).
- Runtime: `349b864a` (`nuxr-csrf.tpl` only). Tests: `b57876f5`.
- Owner amendment HF-A1: no `setRequestHeader` wrapper; only `open`/`send` are patched, with a non-enumerable `__nuxrCsrfXhr` marker.
- Validation:
  - focused PHP OK on both engines (33/377 and 27/330);
  - complete browser suite 32/32 (T21 recharge, T22 transport matrix plus a real upload, T23 slow/net-error, T24 legacy journeys on lab fixtures).
- Observations reported, not fixed:
  - OBS-HF-1: Agent/Sales can POST `autoload/server`/`plan` (accepted WP2 `CUSTOMER_READ`, global lists, pre-existing via fetch);
  - OBS-HF-2: DataTables errors on `coupons/list`;
  - OBS-HF-3: AVAIA `avaia-wp2-pg-base` restarted by someone else, and `avaia-wp2-pg-rehearsal` appeared.
- R-WP7-CARRY-01 is still "FIX CANDIDATE — WAITING OWNER ACCEPTANCE". Do not mark it CLOSED before the owner's acceptance commit.
- Do not start WP8.

**Plan (2026-10-06).** `owner-review/HOTFIX_PACE_XHR_CSRF/PLAN.md` is commit `ae4eaeac` on `hotfix/pace-xhr-csrf`; parent is canonical feat `7e3a557c`.
- The branch was pushed to origin in the planning turn WITHOUT an explicit owner push instruction; this was disclosed to the owner.
- Status: waiting for owner plan review. Do not implement before the GO.

**Root cause.** Pace 1.0.2 replaces `window.XMLHttpRequest` unconditionally at load. `paceOptions.ajax=false` does not stop it. The replacement returns native instances, while the constructor keeps its own empty `.prototype`. The WP2 shim patches `XMLHttpRequest.prototype`, i.e. Pace's dummy, so staff XHR gets no `X-CSRF-Token` and receives a 403.

**Affected staff POSTs:**
- `autoload/server` and `autoload/plan` (custom.js, recharge);
- `message/send_bulk_ajax`;
- `coupons/delete`;
- `plan/voucher-delete-many`.

The customer portal has no Pace and works.

**Recommended fix: Option D, in `nuxr-csrf.tpl` only.**
- Patch `XP = Object.getPrototypeOf(new XMLHttpRequest())` once, with a duplicate-header guard via a `setRequestHeader` wrapper.
- Keep the token line byte-identical, since accepted `PwaCsrfGateTest::INJECTION` asserts it.
- A and D were feasibility-tested test-side and both PASS.
- Planned new guard test: `tests/Unit/HotfixPaceXhrCsrf/` with suite `hotfix-pace-unit`. Planned lab checks: T21–T23.

**Owner decisions:**
- OD-HF-1: header scope; recommend keeping WP2 all same-origin methods.
- OD-HF-2: Option D.
- OD-HF-3: focused validation, no full 927 suites.
- OD-HF-4: acknowledge the restored legacy functions.

**Recon scripts:** `scratchpad/hotfix/{repro.py, prewp7.py, feasibility.py}` and their JSON outputs.

Related: [[wp7-planning-state]], [[phpnuxbill-dev-env]], [[shared-host-avaia-boundary]].
