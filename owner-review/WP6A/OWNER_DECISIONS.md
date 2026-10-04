# WP6A Owner Review Decision and Owner Decisions

Recorded 2026-10-04 from the owner's explicit decisions in the implementation session. The verdict was "WP6A OWNER REVIEW — ACCEPTED WITH NOTE": status **ACCEPTED_WITH_NOTE**, accepted implementation `f71e239f`, accepted evidence / branch HEAD `ed014525`. The owner instructed: record the acceptance and the R-15 note, fast-forward `feat/reseller-system-v1` (`--ff-only`), push it, and change nothing in `main`. No further full validation was requested. WP6B and WP7 are not started.

This file records that verdict and the WP6A owner decisions given during planning and review. The scope and the note below are the owner's verdict. Their technical detail is in the WP6A documentation: `docs/WP6A_UX_MODEL.md` and `owner-review/WP6A/REPORT.md`.

## WP6A acceptance

| Item | Value |
|---|---|
| Owner status | **ACCEPTED_WITH_NOTE** |
| Accepted implementation | `f71e239f09b6288e83ecdf2753407c8badfdc339` |
| Accepted evidence / branch HEAD | `ed014525576028a9585e9c9b45937aad652fad8d`. Branch `wp6a/adaptive-shell-subscriber-ux`, on origin, unchanged. |
| Base | `f806a6186ee1ff2b6037e4af702245a59113c9da` (WP5B acceptance recorded) |
| `feat/reseller-system-v1` | fast-forwarded (`--ff-only`) to `ed014525`, plus this metadata commit. No squash or rewrite. `main` (`500d3a2d`) unchanged. |
| Schema | none. R0001–R0007 are byte-identical. `Package.php`, `Balance.php`, `Tx.php`, `ServiceGate.php`, `RadiusPolicyBuilder.php`, the migration runner and `install/radius.sql` are unchanged since `f806a618`. |
| Pinned files | the pinned `RouteMap` changed only for `activation/list` (OD-C) and was re-pinned. |

## Owner decisions applied in WP6A
"OWNER REVIEW — WP6A PLAN ACCEPTED WITH DECISIONS / IMPLEMENTATION GO", on `WP6A_PLAN.md`:

| Decision | Content |
|---|---|
| OD-A | Read-only `ActivationReadService` (list, counts, view, customer flags) with the accepted `activation.read` visibility. |
| OD-B | `ActivationPreviewService`. Admin and the paying Agent see wholesale, retail, available credit before / after and expiries. Sales sees customer, plan, retail, expiries and "credit sufficient yes / no" only. The preview fingerprint is re-checked on the server; a mismatch fails closed with `ACTIVATION_PREVIEW_CHANGED` before any reservation, wallet, provisioning or legacy mutation. No schema change. |
| OD-C | Read-only `activation/list` route (`GET`, `activation.read`). The pinned RouteMap change is authorized and re-pinned. |
| OD-D | Session data only from existing DB / RADIUS rows, with an honest "unknown". No live NAS call while rendering. |
| OD-E | Disconnect and Suspend hidden for all roles. |
| OD-F | Arabic-first RTL: local strings, hand-written RTL CSS, system font, Latin digits and LTR technical tokens. SEC-25 closed (no runtime external translation). |
| OD-G | The adaptive shell applies to all staff pages. |
| OD-H | Asset policy: no duplicate jQuery; Chart.js only where used; maps only when opened; no runtime translation; global CDN avoided where practical. The Google tiles residual is documented. |
| OD-J | Phone primary navigation: Home / Customers / Quick Action (+) / Activations / More; no Wallet. |
| OD-K | Curated 12–18 screenshot visual QA. The 214-shot sweep is not repeated. |
| OD-L | Scoped dashboard foundation with a 7-day expiring threshold. No WP6B finance. |
| Testing cadence | Focused tests per slice; one canonical full validation at the first complete candidate; owner fixes get a focused fix and regression plus one final full run. At most two full runs. |

## Owner code-review fix round

| Round | Reviewed | Blocker | Fix |
|---|---|---|---|
| Fix round 1 ("WP6A OWNER CODE REVIEW VERDICT: FIX_REQUIRED") | `4722fd53` (evidence `9812ad7b`) | The activation-preview fingerprint did not bind the full material service terms. Service-affecting plan columns and the bandwidth rate fields consumed by `RadiusPolicyBuilder` could change between preview and confirmation without invalidating the old fingerprint. | `f71e239f`, evidence `ed014525` |

The round was additive: no amend, squash or rebase. The defect was reproduced on the reviewed implementation before the fix: 14 / 14 service-term cases failed on MariaDB and on MySQL (`owner-review/WP6A/FIX_ROUND_1_REPRODUCTION.txt`).

The fix binds the following, derived by preview and confirmation through the same `ActivationPreviewService::serviceTerms()` from authoritative server state:
- the canonical sealed plan snapshot, excluding only the non-material `name_plan` and raw `price`;
- the effective wholesale and retail;
- payer, action and current service state;
- the expired-day / service-period inputs;
- the bandwidth fields consumed by `RadiusPolicyBuilder`.

## Accepted scope
- Adaptive shell for every staff page (desktop sidebar, tablet rail, phone bottom navigation, More and Quick Action sheets). Role-aware navigation and actions, with backend authorization authoritative.
- Activation read model (OD-A), money preview with the staleness guarantee (OD-B), activation list, status and confirmation screens.
- Scoped, bounded, Arabic-safe subscriber search (username, full name, phone, PPPoE username, IP, customer id).
- Adaptive subscriber list, Subscriber 360 and the four-step create-subscriber flow.
- Scoped dashboard foundation (OD-L).
- Arabic RTL catalog and the staff login. SEC-25 closed. Asset diet.

## Test evidence (accepted)

Final canonical full validation on `f71e239f`:

| Check | Result |
|---|---|
| MariaDB | 828 tests, 51 576 assertions |
| MySQL | 828 tests, 51 579 assertions |
| Total | 1656 passed, 0 failed |
| Variant registry | reproducible |
| Route matrix | 1477 / 1185 / 292, unchanged from the first candidate (all 1470 WP5B rows unchanged; +7 `GET activation/list` rows) |
| PHP 8.2 lint | 456 / 456 |
| Manifest | 42 / 42 |
| Migrations | unchanged |
| Secret sweep | clean |

The first candidate validation was on `e21ff687` (812 / 812 per engine). The curated visual QA (18 screenshots plus 3 tablet probes) finished with 0 axe serious / critical violations.

## Accepted note
- **R-15 (accepted documented technical limitation, not a WP6A blocker).** The activation seals the plan terms, but the WP5A provisioning architecture intentionally reads the bandwidth row live again after confirmation. A bandwidth edit committed after the confirmation transaction and before provisioning may therefore affect that activation. Closing the window would require changing the accepted WP5A provisioning contract and probably sealing bandwidth state (schema). It is not changed in WP6A and stays recorded for a future architectural hardening item.

The other documented residuals (R-1 … R-14 in `owner-review/WP6A/REPORT.md` §I) remain recorded there as part of the accepted evidence.

## Not authorized by this decision
- No deploy.
- No change to `main`.
- No PR or tag.
- No WP6B or WP7 implementation.
