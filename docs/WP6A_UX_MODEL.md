# WP6A — Adaptive Shell and Subscriber UX: model and contracts

Status: implemented on `wp6a/adaptive-shell-subscriber-ux` (base `feat/reseller-system-v1` = `f806a618`), waiting for the
owner's code review. Plan: `WP6A_PLAN.md`, accepted by "OWNER REVIEW — WP6A PLAN ACCEPTED WITH DECISIONS / IMPLEMENTATION
GO" (OD-A … OD-L). Evidence: `owner-review/WP6A/REPORT.md`, `TESTDOX.txt`, `VISUAL_QA.md`, `visual/`.

WP6A is presentation plus two read-only domain services. It adds **no schema, no mutation, no new capability, no new
business rule** and no framework (server-rendered Smarty, AdminLTE 2 / Bootstrap 3 / jQuery kept, one small vanilla
script). Authorization stays in the accepted backend (RouteMap + PermissionService + DataScope + the activation
services); the UI only hides what the backend would refuse anyway.

## 1. Components

| Component | Kind | Purpose |
|---|---|---|
| `Reseller\Activation\ActivationReadService` (OD-A) | read-only | Scoped activation list / counts / detail / per-customer flags for the UI |
| `Reseller\Activation\ActivationPreviewService` (OD-B) | read-only | Live server-calculated confirmation quote + fingerprint |
| `Reseller\Activation\PreviewBinding` (OD-B) | value | The confirmed fingerprint, checked inside `ActivationService::open` |
| `ActivationStore::displayPage / displayCount / statusCounts / displayById` | read-only SQL | Display columns only (no snapshot / pricing JSON); the activation table stays inside the allow-listed files |
| `Reseller\Domain\CustomerSearch` | read-only | Bounded, scoped server-side search predicate |
| `Reseller\Ui\Navigation` | presentation | Role-aware destinations for sidebar / rail / bottom nav / sheets |
| `Reseller\Ui\Format` | presentation | Money / state / date / direction helpers (registered in Smarty as `NuxrUi`) |
| `Reseller\Ui\Subscriber` | read-only | Plan names, RADIUS session summary, activatable-customer picker, plan cards |
| `Reseller\Ui\Dashboard` (OD-L) | read-only | Scoped dashboard foundation |
| `ui/ui/admin/header.tpl`, `footer.tpl`, `ui/ui/admin/nuxr/*.tpl` | templates | The adaptive shell (OD-G: every staff page) |
| `ui/ui/styles/nuxr-shell.css`, `nuxr-rtl-legacy.css`, `ui/ui/scripts/nuxr-shell.js` | assets | Tokens, components, RTL, dialogs, search, stepper, pending-submit, poll, lazy map |

The `Reseller\Ui` classes are guarded (`Wp6aSourceGuardTest`): `NUXR_RUNTIME` only, no finance namespace, no activation /
manager tables, no INSERT / UPDATE / DELETE / `->save()` / `->delete()`.

## 2. Activation read model (OD-A)

- `list(actor, {group, customer}, page)`: 30 per page, newest first. Groups: `processing` (in flight), `attention`
  (RECONCILIATION_REQUIRED and the pending rollback / reversal states), `completed`, `failed`, `reversed` (rolled back /
  reversed). Visibility is the accepted `activation.read` scope: G all; T `manager_id` in the actor's subtree; A customers
  assigned to the Sales account. Out-of-scope references are `NOT_FOUND`.
- `counts(actor)`: open groups all-time; terminal groups over the last 7 days.
- `view(actor, reference)`: `ActivationService::status` (accepted) + display fields: retail; **wholesale only for G or the
  paying Agent**; the reservation outcome (reserved / charged / released / refunded) as words, never a wallet balance;
  period start / expiry; terminal flag; events without metadata.
- `customerFlags(actor, customer)`: the in-flight activation (if any) and `REPROVISION_UNCONFIRMED` from the latest
  re-provision audit row, so the Subscriber 360 can block / explain instead of offering a dead action.
- Route `activation/list` (OD-C): `GET` only, `activation.read`, web only (not API-allowed). This is the only RouteMap
  change (re-pinned in `SourceGuardTest`).

## 3. Activation confirmation and staleness guarantee (OD-B)

1. `activation/create&customer=&plan=` renders `ActivationPreviewService::quote`: the accepted `ActivationService::preview`
   gate first (eligibility, scope, saleability, redacted refusals), then one tree-locked `FinanceTx` that resolves the
   pricing snapshot exactly as the activation would seal it and reads the paying owner's locked available credit.
2. Visibility: G and the paying Agent (S) see wholesale, retail, available credit before / after, current and new expiry,
   the paying owner. Sales (A) sees customer, plan, action, expiries, retail and **"credit sufficient: yes / no"** only.
3. The quote carries `fingerprint = HMAC-SHA256(per-session key, material)`. Material = `{v, customer, plan, payer, action,
   wholesale, retail, current recharge (id, plan_id, expiration, time, status), plan type / validity / unit, expired day}`.
   Wall-clock time and the available credit are deliberately excluded (the reservation re-checks credit authoritatively).
   The key is `$_SESSION['nuxr_activation_preview_key']` (64 hex, server-side, never rendered).
4. The web confirm (`activation/create-post`) posts `preview`; `ActivationService::create(..., PreviewBinding)` re-derives
   the material inside `open()` — after the authoritative `resolveInTx`, before the activation INSERT, the reservation, any
   wallet change, provisioning or legacy write — and fails closed with **`ACTIVATION_PREVIEW_CHANGED`** on any difference
   or a missing / malformed fingerprint. The user is sent back to a fresh confirmation for the same customer and plan.
5. WP5A transaction semantics are unchanged: the check is one extra comparison inside the existing transaction; callers
   without a binding (API, CLI, tests) behave exactly as before. No schema.

## 4. Search

`CustomerSearch::term()` (2–64 characters, trimmed) and `predicate()` match: `#id`, username, full name, PPPoE username,
email, phone (`LIKE`, Arabic text works because the comparison is on the stored UTF-8 string), PPPoE IP prefix, a numeric
customer id, phone digits / `Lang::phoneFormat`, and usernames of open RADIUS sessions for an IP (`radacct`, read-only).
It is always applied **after** `DataScope` (customer.read), server-side, with a row limit (typeahead 8, list paginated).
Used by the topbar / sheet typeahead (`search_user`), the subscriber list and the activation customer picker.

## 5. Shell and navigation (OD-G, OD-J)

- Breakpoints: phone `< 768` bottom navigation; tablet `768–1199` icon rail; desktop `≥ 1200` sidebar.
- Phone primary navigation (OD-J): **Home / Customers / Quick Action (+) / Activations / More**. No Wallet. Quick Action
  offers "Add subscriber" and "Activate / Renew" only when the actor could do them (`customers/add` allowed;
  `ActivationService::eligible`).
- `Navigation::for` lists a destination only when `RouteGuard::resolve` + `PermissionService::can` allow the route and the
  legacy menu condition holds (feature flags, role). The More sheet / sidebar groups mirror the legacy menu; plugin menu
  HTML is the WP2 OD-9-filtered output. No placeholder or future destinations.
- Dialogs are native `<dialog>` with labelled titles; dangerous actions (delete subscriber, rollback / reverse activation)
  require a reason where the backend needs one and a typed confirmation (username / reference). `window.confirm` is gone
  from the new templates; legacy `ask()` remains a plain confirm.
- Search typeahead: a `listbox` only while it holds options (each result and "See all results"); a message (too short,
  searching, none found, failed) is a `status`. Links use an AA-contrast token; footer links are underlined.
- Pending-submit protection disables a submitted form's buttons until the response (re-enabled on `pageshow`).
- No `maximum-scale` (zoom allowed); 44 px targets; visible focus; `prefers-reduced-motion`; light / dark tokens (the existing dark-mode toggle).

## 6. Subscriber UX

- List (`customers/list`): status filter incl. "All", service filter (active / expiring ≤ 7 days / expired / none),
  table on desktop, cards on phone, plan names, total, activate shortcut only when allowed.
- Subscriber 360 (`customers/view/{id}`): tabs `overview | activations | invoices | order` (`activation` maps to
  invoices for old links); identity, ownership, service, RADIUS session, recent activations; actions by `$_can`; sticky
  action bar on phone; danger zone with typed username.
- Session data (OD-D): only stored DB / RADIUS accounting rows; states online / stale (> 30 min without update) / offline /
  never / unavailable. No live NAS call while rendering.
- Disconnect and Suspend are not shown for any role (OD-E).
- Create (`customers/add`): four steps (identity, account, ownership, review); Sales options filtered by the chosen
  owner; password generator; map only when opened; on success redirects to the new subscriber's 360 (`&created=1`).

## 7. Arabic / RTL (OD-F)

- `dir`/`lang` on `<html>` from the selected language; Arabic-first strings in `system/lan/arabic.json` (WP2–WP6A strings
  used by the new screens, about 400 added); hand-written logical-property CSS plus `nuxr-rtl-legacy.css` for legacy
  pages; system font stack (no Google Fonts).
- Technical tokens (usernames, IPs, references, money, dates, ids) are rendered LTR (`dir="ltr"` / `<bdi>`), Latin digits.
- **SEC-25 closed:** `Lang::T` never calls an external translation service and never writes the language file at runtime.
  A missing key is kept in memory for the request and the source text is shown.

## 8. Asset policy (OD-H)

- One jQuery (1.11.1, vendored); Bootstrap 3.4.1 vendored; no duplicate libraries.
- Chart.js (CDN) only on `reports` or when a page sets `$nuxr_charts` (dashboard "widgets" tab).
- Summernote only on community / pages / widgets.
- Leaflet (unpkg) and the Google tile source load only when a map is explicitly opened (`data-nuxr-map`).
- Google Fonts `@import`s removed. `7.css` dropped from the shell.
- Documented residuals: Chart.js CDN; Leaflet CDN + Google tiles on an opened map; the legacy widgets tab's optional
  version check.

## 9. Dashboard (OD-L)

Scoped foundation by default: customers (total / active / expiring within 7 days / expired), activations (open groups,
terminal last 7 days), the 5 next expiring subscribers and up to 5 activations that need attention or are processing.
The legacy widget grid moves to `dashboard&widgets=1` (global scope only). No wallet / ledger / finance figures (WP6B).

## 10. Compatibility impact

| Change | Effect |
|---|---|
| Web activation confirm requires the fingerprint | A stale or forged confirm is refused before any write (`ACTIVATION_PREVIEW_CHANGED`) |
| `customers/add-post` success redirect | Goes to `customers/view/{id}&created=1` (was the list) |
| Dashboard legacy widgets | Moved to `dashboard&widgets=1` (global scope) |
| Subscriber list filters | "All" status and the service filter added; search moved to the scoped predicate |
| Maps | Load on demand in customer / router / ODP edit and create |
| Full-name validation | Counts characters (`mb_strlen`, 1–45), so Arabic names are not cut by byte length |
| Subscriber 360 tab keys | `overview / activations / invoices / order`; `activation` still lands on invoices |
| Lang | No runtime translation, no runtime writes to `system/lan/*.json` |

## 11. Residuals

See `owner-review/WP6A/REPORT.md` §I.
