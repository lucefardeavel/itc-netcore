# Definition of Done

A Work Package is implementation-complete only when:
- requested scope implemented and nothing knowingly omitted
- no unauthorized adjacent WP work added
- relevant automated tests added/run and results reported exactly
- negative/security/failure cases run where required
- migrations/data impact documented where relevant
- changed files and diff summary provided
- docs updated for decisions introduced within the accepted scope
- durable `owner-review/WP<N>/REPORT.md` (or owner-approved equivalent) created with the evidence packet
- no secrets committed or copied into review artifacts
- no unexplained dirty worktree changes
- known risks/TODOs explicitly listed
- production remains untouched unless authorized
- final response ends `STOPPED_WAITING_OWNER_REVIEW`

A WP is not closed until the owner reviews and marks it ACCEPTED or ACCEPTED_WITH_NOTES.
