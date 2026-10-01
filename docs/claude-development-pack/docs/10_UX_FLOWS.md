# UX Flows

## Login
Minimal sign-in. Route user to role-appropriate dashboard. Do not push installation prompts immediately.

## Search-first operations
Subscriber search supports at least:
- username
- full name
- phone
- PPPoE username
- IP where available
- customer ID/reference

## Create customer
Mobile uses short steps:
1. identity/contact/service type
2. credentials/network options
3. review
After create, offer Activate now or Later.

## Subscriber 360
Expose quickly:
- identity/username/service type
- Online/Offline/Last seen
- plan/speed/expiry
- IP/NAS/session/usage
- owner/creator
- Renew
- Change Plan
- Disconnect Session
- Suspend

## Activation confirmation
Before confirm, fetch live server-calculated:
- customer
- plan
- manager cost
- retail price
- available balance before
- projected after

## Activation progress
Reflect persisted state machine, e.g.:
- request created
- funds reserved
- provisioning
- verifying
- completed

## Confirmed failure
Say no money charged/released only if the server has proven that state.

## Unknown outcome
Show checking/reconciliation state. Explicitly tell user not to blind-retry. Offer status view, not duplicate execution.

## Renew
Short flow showing current plan, current expiry, computed new expiry, cost.

## Change plan
Show current/new plan, immediate-effect rule for V1, expiry behavior, full cost. No V1 proration unless later approved.

## Disconnect vs Suspend
Disconnect: current session ends, subscription stays active.
Suspend: internet access blocked until reactivated. Require reason where appropriate.

## Wallet
Show balance, reserved, debt limit, and especially available credit. Admin/SuperAdmin funding/withdrawal is visually distinct from reseller parent-child transfer and always asks for a reason/reference.

Wholesale cost and retail/customer price must use distinct labels so the operator cannot confuse them.

## Transfer
Choose allowed direct child, amount, live available credit, projected balance, confirm.

## Error UX
Ordinary users get concise message + reference ID, never raw SQL/stack trace.
