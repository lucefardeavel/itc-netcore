# Definition of Ready

A Work Package may start only when:
- previous required WP is owner-accepted
- exact upstream baseline ancestry is known
- for WP1+, current HEAD matches or descends from the owner-accepted prior-WP HEAD; verify against the latest explicit owner decision / acceptance ledger; no reset to upstream baseline
- scope and forbidden scope are written
- affected frozen decisions are identified
- acceptance tests are defined
- required environment/access exists
- no unresolved BLOCKER decision exists
- rollback/data-impact expectation is known where applicable
- push/deploy authorization is explicitly stated (default NO)
- current worktree/branch state is known and unrelated dirty changes are absent or explained

If any item is missing, Claude reports `NOT_READY` and the missing item instead of guessing.
