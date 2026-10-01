# PWA Requirements

## V1 capabilities
- installable manifest
- standalone display mode
- application icons supplied by implementer/project owner as assets
- service worker
- offline app shell
- cached read-only last-known data where safe
- install UX
- controlled update UX
- deep links
- notification-ready architecture
- responsive/adaptive shell

## Offline policy
Offline/read-only allowed:
- open shell
- view safe cached last-known data
- open previously loaded customer summary
- drafts/notes only if clearly marked local and safe

Online required:
- activation
- renewal
- change plan
- wallet transfer/funding
- suspend/reactivate
- any authoritative finance/network mutation

## Connectivity states
UI distinguishes:
- ONLINE
- SLOW/UNSTABLE
- OFFLINE
- RECONNECTING

## Cache safety
- cache only data explicitly classified safe for last-known read-only use
- never use cached price, wallet, permission or activation state as mutation authority
- do not cache login/authentication responses or state-changing request responses for replay
- avoid caching raw credentials, secrets, full sensitive finance exports or other unnecessary sensitive payloads
- prevent cross-account cache leakage on shared devices
- clear/rotate user-specific caches on logout/account switch and incompatible client update
- visibly mark offline data as last-known/stale

## Update behavior
Never force a service-worker reload during an active critical flow. Allow controlled update after safe point. Support a minimum-compatible-client mechanism if API changes later require it.

## Push
Request permission only after meaningful user action. Keep notifications operational and actionable, not noisy. Deep-link to the relevant activation/customer/wallet item.

## Browser/PWA parity
Core online business operations must remain available in supported browser mode. Installing the PWA may improve launch, offline shell and notifications but must not be required for essential service management.
