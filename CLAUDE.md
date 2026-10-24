# CLAUDE.md — phpnuxbill-reseller

Keep this file short. The details live in the documents linked below.

## Sources of truth (in this order)
1. **Owner architecture decisions:** [`docs/architecture/owner-lock-2026-10-09/OWNER_ARCHITECTURE_LOCK.md`](docs/architecture/owner-lock-2026-10-09/OWNER_ARCHITECTURE_LOCK.md).
   - Details are in `ARCHITECTURE_DECISIONS.md` (AD-00 … AD-24) in the same folder.
   - The folder contents are verbatim; verify them with its `SHA256` manifest. Never edit them.
2. **Development rules and specification:** [`docs/claude-development-pack/START_HERE.md`](docs/claude-development-pack/START_HERE.md) and `docs/15_CLAUDE_RULES.md`.
   - The pack is manifest-pinned (42 files). Do not edit pack files.
3. **Implementation status:** the code, the tests, git, and the owner-controlled [`ACCEPTANCE_LEDGER.md`](docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md).
   - The audit of 2026-10-09 is [`docs/architecture/DEVELOPMENT_STATUS_REPORT.md`](docs/architecture/DEVELOPMENT_STATUS_REPORT.md) (a dated snapshot; newer status is in `owner-review/WP9/`).

## Rules for decisions
- Approving an architecture is not approval of an implementation. A locked decision is not a passing test, an accepted WP, or permission to change code or deploy.
- Never mark a WP accepted. Never close a risk or carry-forward without technical evidence and an owner decision.
- SAS4 is a non-authoritative reference (AD-00). Copy no code from it, and adopt nothing just because SAS4 has it.
- Out of V1 unless the owner approves it separately:
  - shared-DB multi-tenancy / `tenant_id` (the deployment model is **Option A, one isolated instance per ISP**);
  - Redis, brokers, or an outbox/worker;
  - microservices, Kubernetes, or DB/RADIUS HA;
  - advanced FUP or an automatic next-profile engine;
  - a rewrite.
- When a newer owner instruction conflicts with a recorded decision or the ledger, stop and ask. Do not pick a side.

## Before any work
- Check the WP status in the ledger and in `owner-review/<WP>/`, and the branch, HEAD and working tree.
  - The execution base is the owner-accepted HEAD (`feat/reseller-system-v1`).
  - Status 2026-10-24: the product is **ITC NetCore V1.0** (rules: `docs/BRAND_IDENTITY.md`). The latest code is on `wp9/red-team-rc`, and `feat/reseller-system-v1` was fast-forwarded to it by owner decision 2026-10-24. This is a branch decision, not a WP9 / WP10 acceptance; the ledger rows stay `PENDING`. `main` is unchanged. Release state: `RELEASE_BLOCKED_WITH_EVIDENCE`.
  - **Resume point:** [`owner-review/CURRENT_STATE_AND_OPEN_NOTES.md`](owner-review/CURRENT_STATE_AND_OPEN_NOTES.md) (what is done, what is open, where each record is), then `owner-review/WP10/HANDOFF_V1_CONTINUOUS.md`.
- Default is no push, merge, tag, deploy, or production action without explicit owner authorization (D038).
- Never commit secrets, credentials, RouterOS images, or customer data.
