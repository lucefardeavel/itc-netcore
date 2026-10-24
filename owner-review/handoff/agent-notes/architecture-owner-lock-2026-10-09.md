---
name: architecture-owner-lock-2026-10-09
description: Owner architecture lock of 2026-10-09 (ten axes, Option A per-ISP isolation) synced docs-only on a local branch; conflicts C-01..C-08 await the owner
metadata:
  type: project
---

On 2026-10-09 the owner locked ten architecture axes. Key points:
- Option A: one isolated instance per ISP, with no tenant_id.
- Session Authority with PROVEN_SAFE/LIVE/UNKNOWN, based on Option G semantics.
- A concurrent-first-login gate is mandatory.
- New release gates: Q1–Q4, P1–P3, N1–N7, REL-01–05, ISO-01.
- Not approved for V1: Redis, broker, HA, microservices, advanced FUP.
- SAS4 is non-authoritative.

Source: the Drive folder 12ntphbk990IE64TyYOd3MJjB67whEaYb. All 10 docs and the manifest verified.

Docs-only sync:
- Local branch `docs/owner-architecture-lock-2026-10-09` @ `8b0ee336`, based on feat `5bdc5745`.
- Worktree at /srv/phpnuxbill/docs-sync. NOT pushed.
- It adds `docs/architecture/owner-lock-2026-10-09/` (verbatim), a root `CLAUDE.md`, `docs/architecture/{DOCUMENTATION_SYNC_REPORT,DEVELOPMENT_STATUS_REPORT}.md`, and dated notes in docs/GAP_ANALYSIS.md and docs/WP4_RADIUS_MODEL.md.

**Why:** the lock governs architecture decisions. Implementation status still comes from the code, the tests and ACCEPTANCE_LEDGER.

**How to apply:**
- Pack files are manifest-pinned (42/42); never edit them.
- docs/** is git-ignored except docs/*.md, so nested docs need `git add -f`.
- The owner must decide the open conflicts (C-01 execution order of WP8, C-02 packaging, C-03 uncommitted G work, C-05 time contract, C-07 WP9/WP10 gate inputs) before code resumes.

Related: [[wp8-planning-state]].
