# Test and Acceptance Matrix

No WP is accepted because code “looks right”. Evidence is required.

## Baseline/repository chain
- WP0 exact upstream baseline verified
- WP1+ current HEAD descends from owner-accepted prior-WP HEAD
- no later WP silently reset to baseline
- upstream license metadata conflict documented

## Hierarchy/scoping
- create multi-level A -> B -> C
- reject self-parent
- reject cycle
- authorized parent sees descendant data
- sibling/unrelated tree blocked
- Sales sees only explicitly assigned customers
- customer owner transfer clears/validates Sales assignment
- unassigned legacy customer blocked from Agent/Sales
- Report role denied on new domain unless explicitly mapped
- direct URL IDOR blocked
- forged POST object ID blocked
- CSV/report scopes match UI

## Wallet/finance
- provider/Admin funding audited
- provider withdrawal boundary enforced
- credit/debit path through service only
- debt limit exact boundary
- insufficient available credit rejected
- debt-limit reduction cannot invalidate active exposure
- reservation created/released/committed once
- duplicate request cannot double debit
- two concurrent requests cannot overspend
- parent -> direct-child transfer produces balanced auditable entries
- reversal creates compensating history
- ledger mutation/delete path absent/rejected

## Pricing/commission
- legacy `tbl_plans.price` remains retail/default compatibility price
- wholesale base cost comes from new-domain storage
- parent/Admin controls child wholesale cost
- child cannot modify own wholesale cost
- child wholesale cost below parent rejected
- inheritance resolves deterministically
- ancestor-disabled plan cannot be re-enabled below
- explicit retail below own wholesale rejected in V1
- snapshots immutable after future price edits
- one-level and multi-level margin chain correct
- provider/system settlement + intermediary margins = leaf wholesale debit
- provider settlement is not accidentally credited as normal reseller wallet value
- browser-forged retail/wholesale price ignored

## RADIUS
- PPPoE authenticate
- Hotspot authenticate
- rate policy
- simultaneous use
- pool assignment
- static IPv4
- expiry
- disconnect/CoA
- authoritative FreeRADIUS `radacct` history retained
- local `rad_acct` vs FreeRADIUS `radacct` responsibilities verified
- stale/open session handling

## Activation
- NEW
- RENEW
- CHANGE_PLAN
- REACTIVATE
- success state sequence
- confirmed failure releases reservation
- UNKNOWN routes to reconciliation
- verified provisioning + transient finance-commit failure cannot become free service
- duplicate HTTP request returns existing operation outcome
- page reload/back does not duplicate operation

## Legacy service-entrypoint closure
For reseller-owned customers, verify no wholesale/orchestration bypass through any enabled path discovered in WP0, including as applicable:
- manual legacy recharge
- cron auto-renew
- customer balance/self-service order
- payment-gateway callback/replay
- API/service endpoint
- voucher redemption
- plugin/hook caller

If a path is not adapted in V1, prove it fails closed for reseller-owned customers while documented legacy scope remains safe.

## Customer retail balance
Where customer-balance purchase/auto-renew is enabled for reseller-owned customers:
- insufficient retail balance blocks
- insufficient reseller wholesale credit blocks
- retail and wholesale commits are idempotent
- duplicate callback/cron execution cannot double-debit either domain

## Failure injection
- RADIUS unavailable
- DB failure at safe points
- WireGuard/network loss
- MikroTik timeout
- response lost after external mutation
- two devices same account/operation attempts

## UX/PWA
- desktop/tablet/mobile layouts
- RTL technical token handling
- offline mutations disabled
- cached balance cannot authorize
- user-specific cache cleared/isolated on logout/account switch
- no cross-user cached customer/finance data on shared device
- update does not interrupt critical operation
- deep-link to activation/customer works
- browser and installed PWA support same core online operations

## Release/operations
- staging backup created
- staging restore actually tested
- rollback runbook verified against release candidate
- no secrets in owner-review artifacts

## Required evidence in Claude handoff
- exact commands
- exact test counts/results
- changed files
- git diff summary
- unresolved risks
- accepted prior-WP base SHA for WP1+
- durable owner-review artifact path
- no push/deploy unless owner asked
