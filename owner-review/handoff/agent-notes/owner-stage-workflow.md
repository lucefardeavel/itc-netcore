---
name: owner-stage-workflow
description: Owner's standing per-stage workflow for phpnuxbill-reseller (plan → approval → full execution → review → commit/push only after acceptance)
metadata:
  type: feedback
---

From 2026-10-09, every independent project stage (e.g. WP8 A1, A2 …) follows this sequence: PLAN (read-only) → OWNER APPROVAL → FULL EXECUTION → TESTING → OWNER REVIEW → ACCEPTANCE → COMMIT → PUSH → VERIFY → NEXT STAGE.

**Why:** the owner wants a stable, safe V1 with minimal changes, no repeated micro-approvals, and nothing committed or pushed before acceptance.

**How to apply:**
- Once a stage plan is approved, execute the whole stage without asking between sub-steps.
- Stop only for a real stop condition or a new owner decision.
- At the end, do not commit. Report files, diff, tests, evidence, residual risks and the proposed commit.
- Commit and push only on "ثبت المرحلة وارفعها إلى GitHub", to the stage branch only. Never force-push, merge to feat/main, or deploy.
- Never start the next stage automatically.

Related: [[wp8-planning-state]], [[architecture-owner-lock-2026-10-09]].
