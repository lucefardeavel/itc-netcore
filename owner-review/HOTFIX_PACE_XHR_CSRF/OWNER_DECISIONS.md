# Pre-WP8 Pace / XHR CSRF Hotfix — Owner Review Decision and Owner Decisions

Recorded 2026-10-06 from the owner's explicit decisions. The verdict was "PRE-WP8 PACE/XHR CSRF HOTFIX — OWNER ACCEPTANCE": status **ACCEPTED_WITH_NOTES**. The accepted, frozen hotfix state is `hotfix/pace-xhr-csrf` = `6d4f3f962482b6e125429da7ae459ae8dfd4214c`.

**This is not WP8.** WP8 is not started, planned or researched.

The owner instructed:
- documentation / integration closeout only;
- fast-forward `feat/reseller-system-v1` through the accepted hotfix, plus one docs-only acceptance commit whose direct parent is `6d4f3f96`;
- push only `feat/reseller-system-v1`;
- keep `hotfix/pace-xhr-csrf` permanently frozen at `6d4f3f96`;
- do not move `wp7/staff-pwa`, `wp6c/mobile-adaptive-ux` or `main`;
- no deployment, no production tag, no suite rerun after this docs-only commit;
- do not fix or plan R-HF-CARRY-01;
- stop after the integration.

Technical detail is in `owner-review/HOTFIX_PACE_XHR_CSRF/PLAN.md` (locked plan) and `owner-review/HOTFIX_PACE_XHR_CSRF/REPORT.md` (candidate report).

## Hotfix acceptance

| Item | Value |
|---|---|
| Owner status | **ACCEPTED_WITH_NOTES** |
| Scope | Closes `R-WP7-CARRY-01 — Legacy Pace / XHR CSRF header compatibility` (pre-existing since WP2; carried forward by the WP7 acceptance) |
| Accepted runtime commit | `349b864a3abe388f3b716542d7bb122a6e659681` (`ui/ui/nuxr-csrf.tpl`, XHR section only) |
| Accepted test commit | `b57876f5e71beae7ed3d48f909cda84a12922fb2` |
| Accepted evidence / frozen candidate | `6d4f3f962482b6e125429da7ae459ae8dfd4214c`. Branch `hotfix/pace-xhr-csrf` stays frozen there. |
| Locked plan | `ae4eaeac27c042e70ffac02c866d369b49f27c4f` |
| Canonical base before the hotfix | `7e3a557c89d3d3f85a404b2ec82255e7c3b2af45` (WP7 acceptance recorded) |
| `feat/reseller-system-v1` | fast-forwarded to `6d4f3f96`, plus this docs-only acceptance metadata commit. No squash, rebase, cherry-pick or rewrite. |
| `wp7/staff-pwa` / `wp6c/mobile-adaptive-ux` / `main` | `8e7f0e1c` / `757d8de9` / `500d3a2d`, all unchanged |
| Contracts | Client-side CSRF transport only. No PHP runtime, server authority, RouteMap, schema, migration, capability, DataScope, Finance, Activation, wallet / pricing, RADIUS / MikroTik, API or PWA-caching change. Pace, jQuery and `vendors.js` are byte-identical. Server-side CSRF stays mandatory. |

## Accepted technical design

- Modifies only the XHR section of `ui/ui/nuxr-csrf.tpl`.
- Resolves the prototype inherited by a real `XMLHttpRequest` instance, using a disposable instance that is never opened or sent.
- Patches only `open` and `send`, once per document (a non-enumerable, identity-free marker).
- Does not replace `window.XMLHttpRequest` and does not wrap or replace `setRequestHeader`.
- Leaves unchanged: the same-origin CSRF rule, the WP7 mutation gate, the WP7 heartbeat, Pace, jQuery, customer-portal behaviour and server-side CSRF.

## Owner decisions

| Decision | Content |
|---|---|
| OD-HF-1 | **Approved:** keep the WP2 XHR semantics. Every same-origin XHR may receive the CSRF header, regardless of method. No unsafe-method-only redesign; customer-portal behaviour unchanged. |
| OD-HF-2 | **Approved: Option D**, the central shim-only fix. Patch the `open` / `send` of the prototype a real instance inherits. No constructor wrapper, no Pace or jQuery change, no script reordering, no WP7 runtime change, no weakening of server CSRF. |
| HF-A1 (amendment) | **Do not patch `setRequestHeader`.** Instead: a source guard proves no runtime caller outside the central shim sets `X-CSRF-Token`; browser tests prove exactly one header value equal to `window.nuxrCsrfToken` on same-origin XHR and none on cross-origin XHR. **Satisfied.** |
| OD-HF-3 | **Approved:** focused PHP / security tests on both engines + the complete WP7 browser suite once + selected legacy AJAX journeys. No full 927-test suites (client-side CSRF transport only; no PHP or server authority changed). No route-matrix rerun (RouteMap unchanged). |
| OD-HF-4 | **Acknowledged:** the fix intentionally restores existing legacy staff functions broken by the missing header: recharge `autoload/server`, recharge `autoload/plan`, bulk message, coupon bulk delete, voucher bulk delete. They stay governed by existing RouteGuard capabilities and server-side CSRF. |

## Accepted validation

**Focused PHP:**

| Suite | MariaDB | MySQL |
|---|---|---|
| `hotfix-pace-unit` + `wp7-unit` + `wp7-integration` | 33 tests / 377 assertions PASS | 33 tests / 377 assertions PASS |
| `CsrfTest` + `AuthSessionTest` + `SecurityRoutesTest` | 27 tests / 330 assertions PASS | 27 tests / 330 assertions PASS |

**Browser:** the complete WP7 + hotfix browser suite, **32 / 32 PASS**, at `b57876f5` (`evidence/pwa_suite_hotfix_candidate.json`). It covered:
- the root and `/billing/` installations, with worker active / blocked parity;
- `autoload/server` and `autoload/plan` restored;
- jQuery POST; native XHR POST and GET; upload events; fetch unchanged;
- cross-origin requests without a CSRF token; customer portal unchanged;
- the WP7 OFFLINE gate, SLOW state and network-error heartbeat hint; heartbeat identity;
- Pace compatibility;
- coupon bulk delete, voucher bulk delete and bulk-message TEST mode on isolated lab fixtures;
- Agent / Sales still refused on the Admin-only mutation routes.

**Also accepted:**
- no duplicate `X-CSRF-Token`;
- no runtime manual CSRF-header caller;
- Pace / jQuery / vendors byte-identical;
- PHP test lint green and runtime JS syntax green;
- secret sweep clean; pack manifest 42 / 42;
- no RouteMap / schema / migration / server-authority change.

No full suite was required for this docs-only acceptance integration.

## Carry-forward status

**R-WP7-CARRY-01 — Legacy Pace / XHR CSRF header compatibility.**
**Status: CLOSED — ACCEPTED HOTFIX** (accepted implementation / evidence `6d4f3f962482b6e125429da7ae459ae8dfd4214c`).

**R-HF-CARRY-01 — Legacy autoload router / plan metadata authorization exposure.**
**Status: OPEN — PRE-EXISTING — NOT CAUSED BY HOTFIX. Severity: MEDIUM.**
- `autoload/server` is mapped to `CUSTOMER_READ`; Agent and Sales can receive the global enabled-router name list.
- `autoload/plan` is mapped to `CUSTOMER_READ`; Agent and Sales can receive unscoped legacy plan metadata, including names and prices, for the requested server / type.
- Agent and Sales do not hold `SERVICE_RECHARGE`; `plan/recharge` itself is refused to them. Report is refused.
- This reachability existed before the hotfix and was already possible through `fetch` (evidence: `evidence/obs_autoload_reachability.json`).
- **Owner classification:** an authorization / data-minimization inconsistency, but not a mutation or direct privilege-escalation path, and not caused by this hotfix.
- **Recorded for a later owner decision only.** Not fixed, not planned, no branch.

## Other non-blocking observations (documented, unchanged)

- Pre-existing DataTables JS errors on the legacy coupons page (`$j(...).DataTable is not a function`; upstream code that never loads DataTables there).
- Shared-host AVAIA container activity during validation (`avaia-wp2-pg-base` stop / start with `RestartCount` 0; a new `avaia-wp2-pg-rehearsal` container). This was AVAIA-side activity, observed only.

## Not done (by owner instruction)

- No deployment and no production tag.
- No WP8 work of any kind.
- No fix, plan or branch for R-HF-CARRY-01.
- No test-suite rerun after this docs-only commit.
