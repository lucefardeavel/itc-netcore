# WP0 Owner Review Decision and Owner Decisions

Recorded: 2026-10-01, from the owner's explicit review decision. Decision text below is recorded as given by the owner.

## Owner review decision
- Owner status: **ACCEPTED_WITH_NOTES**
- Accepted WP0 implementation HEAD: `c2582368d0111046e39c353ca70b782d8fbb78c4`
- No WP0 rework is required.
- The accepted implementation HEAD remains `c2582368` even though an owner-metadata commit follows it.

### Accepted non-blocking WP0 notes
These documented verification limitations are accepted as non-blocking WP0 notes:
- PDF/gd runtime path was not exercised
- authenticated UI screens were reviewed statically rather than end-to-end
- external payment-gateway/plugin repositories were outside WP0 repository scope

These limitations must remain visible in later relevant work packages.

## Owner decisions on WP0 conflicts

### C1
500d3a2 is accepted as baseline-equivalent because its application tree is identical to d3e0596 and its only added content is the approved specification pack.

### C13
WP1 must use a project-owned strict migration mechanism.
Do not use system/updates.json as the authoritative project migration runner.
The runner must:
- preflight the actual schema
- support the baseline-compatible schema variants verified by WP0
- fail closed on an unknown or incompatible schema
- never silently swallow DDL errors
- record successful project migrations durably
- perform post-migration verification
- provide a rollback path
- not normalize unrelated legacy schema drift during WP1

### C19
New references to tbl_users.id must use INT UNSIGNED.
Approved customer fields:
- manager_id nullable
- assigned_sales_id nullable

Do not change signedness of existing legacy reference columns merely for cleanup.

### MONEY PRECISION
All new reseller-domain monetary storage uses DECIMAL(18,2).
Legacy tbl_customers.balance remains unchanged.

### TEST ENVIRONMENT
PHPUnit may be added as a development-only testing dependency if needed.
A dedicated PHP 8.2 test/dev container environment is approved.
Do not replace or modernize the production Dockerfile as part of WP1.

### C20
Do not automatically import tbl_users.root relationships into the closure tree in WP1.
WP1 creates schema only.
WP2 may implement an audited dry-run/import/rebuild operation with cycle validation.
Actual import requires explicit owner authorization.

### C8 / D027
Do not create speculative reseller voucher-commerce schema in WP1.
Existing Agent/Sales free voucher generation must be closed by the permission work in WP2 until an approved financial settlement model exists.

### C7 / C9b
Do not create a separate hardening work package.
Expand the existing work packages as follows:
- WP2 owns authorization, legacy-route scoping, privilege escalation, unsafe staff updater access, applicable installer/auth guards, CSRF posture and API authorization.
- WP3A owns financial concurrency/locking.
- WP4 owns RADIUS endpoint hardening, CoA command hardening, RADIUS/NAS credential handling and accounting-history preservation.
- WP5B owns service-granting and money-path bypasses including voucher/payment/customer-balance paths.

Critical/High issues may not remain open when the responsible WP is accepted unless the owner explicitly risk-accepts them.

### C10
The complete WP0 service-entrypoint inventory is binding input to WP5B, not only the paths originally named in the specification.

### C11
Security and financial gates must be enforced at authoritative core call sites.
Hooks/plugins are not a security boundary.

### C14
New ActivationService renewal semantics:
- same-plan renewal while still active extends from current expiry
- same-plan renewal after expiry starts from current time
- change-plan starts from current time
- no proration in V1

Preserve plan validity-unit calculations unless a later verified conflict requires owner review.

### C16
WP4 must provide and test the project-owned FreeRADIUS configuration/dictionary required by the application.
Do not assume stock FreeRADIUS configuration is sufficient.

### C17
WP4 must eliminate destructive radacct history deletion for every lifecycle path that reaches the RADIUS provisioning flow.

### C18
New-domain timestamps use UTC storage semantics.
Presentation uses the configured application timezone.
Default deployment timezone for this project is Asia/Baghdad.
Legacy date/time behavior must be tested before modification.

### C21
The intended V1 behavior is accepted:
Sales sees only explicitly assigned customers.

### C22
Installer/default-credential/exposure hardening must be closed before production through the appropriate security/deployment work.

### C23
tbl_odps latin1 conversion is not part of WP1.

## Previously owner-resolved during WP0 (for completeness)
- C2: WP0 branch `wp0/reconnaissance` from `feat/reseller-system-v1` at `500d3a2`.
- C3: normal push allowed; no PR.
- C4: WP0 analysis documents at top-level `docs/*.md`.
- C5: target PHP version for V1 is PHP 8.2.
- C9 (WP0 scope): verify and document existing security findings only; no fixes; no upstream contact.

## Not decided by this record
Conflicts C6, C12 and C15 from `docs/GAP_ANALYSIS.md` §15 received no explicit owner decision here. Their WP0 recommendations remain proposals, not owner decisions:
- C6: external gateways/plugins, contract-level gating.
- C12: VARCHAR legacy prices.
- C15: transactions/locks. Related ownership is assigned to WP3A under C7/C9b.

## Integration record
- `feat/reseller-system-v1` fast-forwarded (`--ff-only`) from `500d3a2` to `c2582368` (`origin/wp0/reconnaissance`).
- `main` not changed. No PR. `wp0/reconnaissance` retained.
- WP1 not started.
