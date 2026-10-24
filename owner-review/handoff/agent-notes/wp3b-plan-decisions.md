---
name: wp3b-plan-decisions
description: "WP3B (pricing/commission/settlement) on wp3b/pricing-settlement @ 460714de, pushed after owner fix round 2 (plan-delete TOCTOU), all checks complete, waiting owner review; owner decisions summary"
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-02T08:11:49.337Z
---

WP3B was implemented on 2026-10-02 on branch `wp3b/pricing-settlement` (from `d4ba4514`).
- Original candidate `2c543a63`. Owner review: FIX REQUIRED, because assertion totals varied with race order.
- Fix commit `e7d2e7a3` (test-only, `PricingConcurrencyTest.php`), then evidence commit `ae636d14`.
- Branch **pushed** (only this branch, non-force) on 2026-10-02 after each round. No PR, merge or deploy.
- Fix round 2 (owner code review of `ae636d14`): plan-delete TOCTOU race.
  - Fix `9978311a`: `PlanGuard::deleteSerialized` = tree lock + plan row FOR UPDATE + check + legacy effects; `PlanDeleteRaceTest`.
  - Evidence commits `0e8ab4f6`, then `460714de` (skipped heavy checks completed) = `origin/wp3b/pricing-settlement`.
  - WP5A contract: activation/plan references only inside the tree-locked transaction.
- Status: owner ACCEPTED 2026-10-02 at 460714de (ledger recording pending, see [[wp4-planning-state]]).
- Authoritative descriptions: `owner-review/WP3B/REPORT.md` (§L.1 = fix round 1, §L.2 = fix round 2) and `docs/WP3B_PRICING_MODEL.md`.

Owner decisions that were implemented:
- F-1: WP3B never creates wallets (`COMMISSION_RECIPIENT_NO_WALLET`).
- F-2: `postActivationSettlement` replaces `postActivationDebit`.
- F-3: `ZERO_WHOLESALE_NOT_SUPPORTED`.
- F-4: `BALANCE_PLAN_NOT_RESELLABLE`.
- F-5: an upstream raise makes affected resellers unsaleable instead of being rejected.
- F-6: a typed `SettlementFailure`.
- F-7: canonical lock order.
- OD-3 / OD-4 / OD-5.

Stable totals: 470 tests / 43219 assertions per engine (371 accepted + 99 WP3B). Testdox 940/0. Route matrix 1442/1164/278.

Known harness flakes (do not "fix" without owner approval):
- the `CliOnlyTest` healthcheck race;
- `AppServer` orphaned `php -S` workers. A stopped server's workers keep the port, so a later HTTP class drawing that port gets a 404 at login (seen twice: `StaffManagementTest` on MySQL, `RouteMatrixTest` on MariaDB).

**Why:** the next session must not redo or contradict this work, and must not start WP4/WP5A/WP5B without explicit approval.

**How to apply:**
- Wait for the owner's code-review verdict. No merge, PR or further push unless told.
- Environment notes: [[phpnuxbill-dev-env]]. Host rules: [[shared-host-avaia-boundary]].
