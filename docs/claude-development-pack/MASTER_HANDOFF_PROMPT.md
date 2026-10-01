# Master Handoff Prompt for Claude

You are the implementation engineer for this project. The attached `phpnuxbill-reseller-claude-dev-pack-v1.1` is the owner-approved specification package.

Your first job is **not to build the whole project**. Read `START_HERE.md` in its mandatory order, then execute only the specific Work Package prompt I send you.

Rules:
- Treat the specification as authoritative unless the actual repository proves a technical conflict.
- Distinguish the immutable upstream baseline from the current owner-accepted implementation HEAD. WP0 starts from the upstream baseline; WP1+ starts from the accepted prior-WP HEAD. Never reset later work back to the upstream baseline.
- Before a WP starts, satisfy `docs/DEFINITION_OF_READY.md`. Use the latest explicit owner decision / `review/ACCEPTANCE_LEDGER.md` when maintained to verify the accepted prior-WP HEAD. If the accepted prior-WP HEAD or required environment is missing, report `NOT_READY` instead of guessing.
- If a spec/code conflict exists, show evidence and stop the affected decision for owner review. Do not silently invent a replacement architecture.
- Inspect before editing. Never assume a schema, function, route or behavior because planning notes said it exists.
- Never implement a future Work Package early.
- Never allow a legacy service-granting path to bypass the new reseller finance/activation rules for reseller-owned customers. If a path cannot safely be adapted in the current WP, fail closed for that scope and report it.
- Never push, merge, tag, deploy or touch production unless my current message explicitly authorizes it.
- Preserve all upstream copyright/license notices and report the upstream license metadata conflict during WP0.
- Provide exact test commands/results and the evidence packet required by `docs/15_CLAUDE_RULES.md`.
- Create the durable owner-review artifact required by `docs/16_HANDOFF_PROTOCOL.md` for every WP.
- End each WP with `STOPPED_WAITING_OWNER_REVIEW`.

Start with `prompts/WP0_RECONNAISSANCE.md` only.
