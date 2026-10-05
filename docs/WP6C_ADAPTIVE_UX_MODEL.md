# WP6C — Adaptive UX Model ("One System, Two UX Modes")

WP6C is system-wide mobile-first adaptive hardening of the **staff application**. It is presentation only: no schema, migration, finance, activation, pricing rule, ownership, RBAC capability, DataScope, API or RouteMap change. The one legacy controller line changed is the DB-status fix (Amendment H, below).

- Base: `feat/reseller-system-v1` @ `1148559d` (WP6B accepted).
- Branch: `wp6c/mobile-adaptive-ux`.
- Plan and owner decisions: `owner-review/WP6C/PLAN.md` §24.

## 1. Scope decisions

| Decision | Applied as |
|---|---|
| OD-1 = A, Adaptive Layer | Admin / config pages get a layer: no overflow, usable RTL, labelled 44 px controls, single-column phone forms, stacked tables, a sticky Save on long forms, no hover-only interaction. Daily-use pages get the deeper conversion: cards, filter disclosure, row menus. There is no brand redesign. |
| OD-2 = A, pricing | One row-level **Edit** opens one editing surface per row: full screen on a phone, a side panel docked to the inline end from 768 px. The surface holds exactly the accepted forms the `PricingReadModel` projection offers (same routes, fields, CSRF and capability checks). Pricing logic stays out of templates and JS. |
| OD-3 | **The customer portal (`ui/ui/customer/*`) is out of WP6C scope.** The guards cover staff templates only. |

## 2. Two UX modes, one system
The same routes, controllers, authority and markup serve every size. The mode is chosen by **component width** (container queries), never by device detection.

| Mode | Data | Actions | Forms |
|---|---|---|---|
| Phone / narrow component | cards (`.nuxr-mlist`) or stacked rows (`.nuxr-stack`) | row menu, or labelled 44 px buttons | one column; the action row sticks above the bottom navigation |
| Tablet / desktop | efficient tables, toolbars, multi-column layouts | inline buttons | legacy horizontal forms; the action row sits in normal flow |

## 3. Components (existing WP6A / WP6B components first; new ones only with several consumers)

| Component | Where | Rule |
|---|---|---|
| `.nuxr-adaptive` (+ `.is-lg` / `.is-xl`) | WP6A / WP6B tables, daily-use legacy lists | table + card pair switched by `@container`. Tiers: default ≥ 800 px, `.is-lg` ≥ 980 px, `.is-xl` ≥ 1100 px. Applied below 1200 px viewports only; `@supports not` falls back to the viewport rule. |
| `.nuxr-stack` (new) | 12 legacy admin / config lists | `td[data-label]` from the column header. Below 760 px of container width every row becomes a "label: value" card; no hidden columns, same links and actions. Without container-query support the table keeps its horizontal scroll. |
| `admin/nuxr/actionbar.tpl` + `form > .nuxr-step-nav` | 9 daily forms (slice 2) and 24 admin / config forms (slice 5) | a direct child of the form. On a phone it sticks above the bottom navigation; while a text field has focus (keyboard open) the navigation hides and the row drops to the bottom edge. The submit keeps its name and value. |
| Confirmation sheet (`#nuxr-ask-sheet`, `data-nuxr-ask`) | the converted forms that had a legacy `ask()` (which is `window.confirm`) | the same question in a shell sheet (Cancel / Confirm, focus on Confirm, Escape closes). Confirm re-submits the same form with the same button (`requestSubmit`), so the pending / double-submit guard applies. Without JS the form submits as before. |
| `admin/nuxr/secret.tpl` (new) | voucher codes, RADIUS passwords, router IP / username, coupon codes | masked until revealed by a real button (`aria-pressed`, tap / keyboard), re-masked on focus loss. Hover reveal only for `(hover:hover) and (pointer:fine)`. It renders only values the page already rendered for that role. |
| `input[data-nuxr-peek]` | 9 legacy password fields that revealed on mouse hover | JS adds a Show toggle beside the field (progressive enhancement). |
| Editing surface (`dialog.nuxr-sheet-edit`) | pricing catalog / plan / plans | one per row, rendered once (no duplicate ids). It opens from the row's Edit in the table and in the card. A no-JS `:target` fallback is provided. |
| `admin/nuxr/state-page.tpl` (new) | 403 / 404 / 405 inside the shell | explains the state, offers Up and Home, and shows no object details. Navigation stays (Amendment C). |
| Up (`.nuxr-up`, `.nuxr-upline`) | detail / edit / child pages | from `Navigation::UP` (Amendment B). |

## 4. Amendments

**A — Permission-aware presentation.** Legacy row actions and toolbar buttons are gated through `NuxrUi::allowed($route, $method)` → `Navigation::allowed(ActorContext, …)`. That is the central RouteGuard / RouteMap verdict, with no hardcoded role names. The server stays authoritative: every route still refuses on its own. `ActionParityTest` proves that every action the shell renders is allowed for that actor.

**B — Up / Back.**
- The registry is the `Navigation::UP` const, in the UI layer. The RouteMap is not repurposed.
- The first candidate the actor may open wins; otherwise Up goes to the dashboard. Up therefore never reveals an inaccessible parent.
- Browser Back is not the only way back: the Up button is in the top bar on phones and above the heading on desktop.

**C — 403 / 404 in the shell.** `RouteGuard::deny` renders `403.tpl`, which builds the actor's own shell with `Navigation::deniedShell()`; RouteGuard itself is unchanged. 404 uses the same state page. There are no fake success or empty states.

**D — the 768 px blocker.** Fixed by component-width switching, not by a new global breakpoint.

**E — Safe areas.**
- `viewport-fit=cover` is set on the shell and login pages.
- Tokens `--nx-safe-*` come from `env(safe-area-inset-*)` and are applied to the top bar, rail, sidebar, bottom navigation (exact height plus inset), sheets, full-screen surfaces and sticky action rows. There is no blind padding.

**F — Keyboard.**
- A CSS `:has(:focus)` rule on text-entry controls hides the bottom navigation and moves sticky rows to the bottom edge.
- There is no user-agent or device detection, and no new `matchMedia`.

**G — Hover reveals.**
- Tap / keyboard equivalents are provided (`secret.tpl`, `data-nuxr-peek`); help popovers are named and focus-triggered.
- No value is newly rendered and no server visibility changes.

**H — Audit defects.**
- The Smarty comment leak is fixed in router / ODP / customer edit: a `{* *}` inside `{literal}` was printed as page text.
- The ODP search markup is fixed (its value attribute was never closed).
- **DB status 500.** The root cause: `settings/dbstatus` used `new mysqli(...)`, and the mysqli extension is absent from both the shipped `Dockerfile` and the test image.
  - Fix: the page uses the application's existing PDO connection, `ORM::get_db()->query('SHOW TABLE STATUS')`. This is one legacy-controller change, with no Domain / Finance / Activation / Security change.
  - It also stops opening a second connection from raw credentials.
- Settings accordion: the misused ARIA tab roles were removed, and nested links were unnested.

## 5. Legacy template conversion (summary)
- **Daily use:**
  - lists: active customers, vouchers, staff, system / RADIUS / message logs, transaction and activation reports;
  - forms: customer edit, recharge, refill, plan edit, message, change password, staff add / edit, voucher add.
- **Admin / config:**
  - hotspot / PPPoE / VPN / balance plans (lists and forms), bandwidth, routers, IP pools, ports, ODP, RADIUS NAS, coupons, widgets, custom fields, DB status;
  - settings: app, localisation, maintenance, miscellaneous, notifications.

## 6. Guards
- `tests/Unit/Wp6c/Wp6cGuardTest.php` checks:
  - no device detection and no new `matchMedia`;
  - no new external asset; no zoom lock; `viewport-fit=cover`;
  - container queries with a fallback;
  - no hover-only reveal without an equivalent;
  - no comment inside a literal block;
  - the Up registry is in the UI layer;
  - WP6C components escape their output and use no inline widths.
- `tests/Integration/Wp6c/*` covers action parity, Up, the denied shell, the form contract plus the adaptive layer, the pricing editor, and the audit defects.

## 7. Residuals (recorded, not hidden)
- Third-party CDN assets stay as documented WP6A residuals: DataTables, CodeMirror (now named via `screenReaderLabel`), Chart.js, map tiles.
- Filters use the WP6A `<details>` disclosure, not a bottom sheet (a deviation from the plan wording; same function, no JS).
- The legacy logs table still prints its description raw; the card view escapes it.
- `settings/app` keeps per-section Save buttons (70 controls in collapsible sections), not one sticky Save.
- Radio groups in legacy plan forms are named per option; there is no fieldset / legend group name.
- `settings/devices` (host device files) and the page editors' third-party toolbars are outside the Adaptive Layer.
- Arabic copy is a drafted translation for owner language review. Visual QA uses emulated devices.
