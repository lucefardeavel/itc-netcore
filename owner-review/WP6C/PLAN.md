# WP6C — System-wide Mobile-First Adaptive UX Hardening — PLAN (revision 1, planning only)

Status: **ACCEPTED WITH DECISIONS — IMPLEMENTATION GO** ("WP6C OWNER REVIEW — PLAN ACCEPTED WITH DECISIONS / IMPLEMENTATION GO", 2026-10-05). The owner decisions and binding amendments are recorded in §24 and supersede any conflicting text above.
Canonical base: `feat/reseller-system-v1@1148559d2954b19c4db42bc7ffc23cbb0af1f3a5` (WP6B accepted). `main` = `500d3a2d` (untouched).
Planned branch (after GO): `wp6c/mobile-adaptive-ux` from `1148559d`; first commit records this plan as `owner-review/WP6C/PLAN.md`.

Governing standard: **Mobile-First Adaptive Application UI — One System, Two UX Modes.**
- Shared across modes: backend, database, authentication, RBAC, DataScope, domain logic, queries, mutations, validation, authoritative services and routes.
- May differ by mode: navigation, layout, density, interaction pattern, presentation, dialogs, action placement, forms, list representation.

WP6C does **not** start WP7 (no service worker, offline, push, installability or PWA expansion).

---

## 0. How this plan was produced (evidence)

**Read-only audit of the running accepted system, as real users.** Disposable UX lab (`nuxbill-reseller-test` project, lab container on a disposable database, the accepted tree synced read-only). Seeded data: subscribers, activations in every operational state, wallets, transfers, commissions, a stale activation.

**Roles:** Admin (`ad`), Agent (`A`), Sales (`A1`), Report (`rp`); SuperAdmin shares the Admin surface.

**Discovery:**
- every destination the shell actually renders per role (sidebar, More and Quick sheets, bottom navigation and rail): Admin 60, Agent 20, Sales 9, Report 1;
- every first-level detail / form link followed from the list pages, plus targeted pages: forms, stale / operator states, empty states, permission probes.

**Measured (745 page views):**
- 149 page views per viewport;
- viewports: 360 px Arabic RTL, 390 px, 430 px, 768 px, 1440 px;
- per page: page overflow against the device width; inner horizontal scrollers; tables visible on phone; touch targets (< 44 px and < 24 px); unlabelled inputs; money-input keyboards; fixed elements; bottom-navigation overlap and safe-area padding; legacy vs. shell components; legacy modals; Back affordance; console errors; external requests; axe-core WCAG 2 A/AA (390 px).

**Also checked:**
- screenshot contact-sheet review of phone, tablet and RTL captures;
- a link-permission probe: every action link rendered on the main operational pages, requested as that role;
- probes: login, narrow landscape (844×390) and a keyboard-open viewport (390×480).

**Source audit:** templates, `nuxr-shell.css` / `nuxr-rtl-legacy.css` / `nuxr-shell.js`, header / footer, tokens, breakpoints, JS viewport logic.

Lab-only artifacts: scratchpad `wp6c/` (`routes.json`, `shots/measure.json`, screenshots, contact sheets). In the implementation, the curated evidence will be committed under `owner-review/WP6C/`.

---

## 1. Current architecture findings

| Area | Finding |
|---|---|
| Rendering | Server-rendered PHP / Smarty 4, AdminLTE 2 / Bootstrap 3 / jQuery for legacy pages, plus the WP6A shell (`nuxr-shell.css` 1787 lines, `nuxr-rtl-legacy.css` 129, `nuxr-shell.js` 302 vanilla JS). No framework. Correct, and kept. |
| Template inventory | 138 staff templates:<br>• **41 shell-only** (WP6A / WP6B);<br>• **77 legacy-only** (AdminLTE `.box` / `.panel` / `.table-responsive`), 29 of them with tables;<br>• 4 mixed (`customers/edit`, `odp/add`, `odp/edit`, `routers/edit`);<br>• 16 other (print, partials).<br>On phone, 64 of 149 measured page views are legacy-only pages. |
| Breakpoints | Viewport media queries only: `max-width: 767px`, `min-width: 768px`, plus 560 / 991 / 992 / 1200. **No container queries.** The phone table→card switch is `@media (max-width: 767px)`. |
| Tokens | Good base: colours (light + dark), `--nx-radius`, `--nx-touch: 44px`, `--nx-top-h: 56px`, `--nx-bottom-h: 64px`, `--nx-rail-w: 80px`, `--nx-side-w`, shadows, fonts. Missing: page padding, sticky-action height / offset, sheet spacing, typography scale, safe-area tokens. |
| Safe areas | `env(safe-area-inset-bottom)` is used for the bottom navigation and sticky actions, but the viewport meta has **no `viewport-fit=cover`**, so `env()` resolves to 0: effective safe-area padding is 0 px on all 141 measured shell pages. There is no top / side inset handling. |
| JS viewport logic | One `matchMedia('(max-width: 767px)')`, for the create-subscriber phone stepper. That is a genuine interaction-model change and is acceptable. No `isMobile` / user-agent logic anywhere. |
| Back / Up | No shell-level Up / Back affordance. Detail and form pages rely on the browser Back or ad-hoc in-page links: **35 detail / form page views have no Back affordance**, including activation, finance entry / transfer / wallet, pricing, reseller detail and all legacy edits. The topbar title duplicates the in-page `h1`. |
| Dialogs | The shell uses native `<dialog class="nuxr-sheet">` (focus-trapped, Escape, backdrop). Legacy Bootstrap modals remain only in the static-pages editor (Summernote, 4 modals). |
| Pending states | Global POST pending / double-submit protection (shell JS) already covers legacy forms. |
| Pickers | 20 legacy templates use select2 customer / plan pickers. |
| Data / queries | Shell pages render the table and the phone card list from **one** server data set (no duplicate query, no client fetch). Legacy pages render a single table. |

## 2. Adaptive architecture already supplied by WP6A / WP6B (kept and reused)

| Primitive | Status |
|---|---|
| Adaptive shell: topbar; desktop sidebar (≥ 992 px); tablet rail (768–991 px, and phone landscape); phone bottom navigation; More and Quick Action sheets | **Compliant.** The bottom navigation is fully visible at 360 / 390 / 430 on every page. |
| Role-aware navigation: RouteMap + `PermissionService` (`Navigation::allowed`); Agent primary Wallet (OD-3 WP6B) | **Compliant** for navigation. Every nav link is authorized (WP6A `ShellNavigationTest`). |
| Shell components: cards (`nuxr-card`), card lists (`nuxr-mlist` / `nuxr-mcard`), stat tiles, badges (icon + text), empty states, money blocks, timelines, tabs / chips, pager, sheets / dialogs, typed confirmations, stepper, search typeahead / sheet | **Compliant on phone.** Reused as the basis of WP6C's primitives. |
| Arabic-first RTL: `dir` / `lang`, RTL CSS, LTR technical tokens (`<bdi dir="ltr">`), Latin digits, directional icon flip (`.nuxr-dir-icon`) | **Compliant on shell pages** (360 px Arabic captures). Broken on legacy tables (§3). |
| Touch-target baseline 44 px | **Compliant on shell pages.** Not applied to legacy controls. |

## 3. Screen-by-screen findings

Severity: **H** = workflow broken or unusable on phone / tablet, or an access / UI mismatch; **M** = usable but desktop-like or below standard; **L** = polish.

### 3.1 Authentication / shell

| Screen | Findings | Sev |
|---|---|---|
| Login (EN / AR, 360 / 390) | 44 px inputs, correct `autocomplete`, no overflow, RTL correct | compliant |
| Logout | Account menu → logout; works | compliant |
| Topbar | No Up / Back on detail pages; title duplicated with the in-page `h1` on phone (wasted ~70 px) | **M** |
| Bottom navigation | Fully visible; Wallet / Activations per role. Stays on screen while the soft keyboard is open: 64 px of a ~480 px visual viewport above the keyboard. Safe-area padding is effectively 0 (`viewport-fit` missing). | **M** |
| More / Quick sheets | Correct and role-aware; long Admin More list (14 groups) is scroll-only | L |
| Landscape phone (844×390) | Switches to the rail (no overflow, 334 px usable). The 56 px topbar is heavy at that height. | L |
| Permission-denied | Legacy `403.tpl` / `404.tpl` / `error.tpl`: big red "403 Forbidden", no shell navigation, generic "Back to Dashboard"; 364 px overflow at 360 px RTL | **M** |

### 3.2 Dashboard
| Role | Findings | Sev |
|---|---|---|
| Admin / Agent / Sales | Shell tiles, finance tiles, attention list, quick actions: compliant on phone and in RTL | compliant |
| Report | Honest empty state; Report's direct probes (`customers/list`, `reports`, `finance/overview`) answer the legacy 403 page (§3.1) | as §3.1 |

### 3.3 Customers / subscribers
| Screen | Findings | Sev |
|---|---|---|
| List, search, filters, cards | Compliant on phone. **Overflows at 768 px** (860–1035 px; the table + rail exceed the content width). | **H** (tablet) |
| Subscriber 360 | Compliant on phone. Tabs overflow horizontally (4th tab clipped), with no scroll affordance. | L |
| Create (stepper) | Compliant (phone stepper, review step) | compliant |
| **Edit** (`customers/edit`, mixed legacy) | One long legacy form: 15 fields; 14 / 15 controls fail the label heuristic (axe: `label` 8, `select-name` 3); photo / face-detection block first; small controls; Save at the very bottom, not sticky (≈ 130 px below the visible area with the keyboard open) | **H** |
| Validation / empty / error | Shell lists have empty states; legacy flash via SweetAlert toasts (works on phone) | compliant / L |

### 3.4 Activations
| Screen | Findings | Sev |
|---|---|---|
| List / create / preview / confirm / detail / stale / operator states (cancel, rollback, reverse dialogs) | Compliant on phone and in RTL (sheets, typed confirmations, sticky confirm) | compliant |
| List at 768 px | Overflows (1015 px) | **H** (tablet) |
| Detail | No Up affordance (back to the list or the subscriber) | M |

### 3.5 Reseller workspace
| Screen | Findings | Sev |
|---|---|---|
| Tree (drill-down), detail, child relations | Compliant on phone; tree overflows at 768 px (835 px); detail lacks Up | **H** (tablet) / M |

### 3.6 Finance
| Screen | Findings | Sev |
|---|---|---|
| Overview, wallets, wallet, ledger, entry, transfers, transfer, send / funding / withdrawal / debt limit / adjustment / reversal, review → confirm | Phone and RTL compliant (cards, money blocks, typed confirmations, `inputmode=decimal` on every money field). Gaps:<br>• no Up affordance on wallet / entry / transfer / forms;<br>• review / confirm buttons in normal flow, not sticky;<br>• date filters always expanded. | M |
| Finance pages at 768 px | **Overflow** on overview, wallets, wallet, ledger, transfer, sales, commissions, settlement (786–1106 px) | **H** (tablet) |

### 3.7 Pricing
| Screen | Findings | Sev |
|---|---|---|
| Plans / plan matrix / reseller catalog | Phone layout is a card stack, but every row expands **3–4 inline edit forms** (base cost, resale, child wholesale, retail, availability). This is a dense "action wall", not a mobile workflow: 15 / 30 inputs rely on placeholders; long reason texts are truncated. | **H** |
| Pricing pages | **Content under the bottom navigation at page end** (1–7 targets overlapped: the last edit controls are unreachable behind the fixed bar). Overflow at 768 px (902–1049 px). | **H** |

### 3.8 Reports (finance)
| Screen | Findings | Sev |
|---|---|---|
| Sales / commissions / settlement / performance (+ CSV) | Phone compliant (cards); overflow at 768 px; filters always expanded | **H** (tablet) / L |

### 3.9 Operations
| Screen | Findings | Sev |
|---|---|---|
| Overview / activations / stale / held / renewals / drift / CLI-only states | Compliant on phone and RTL; Up missing on drift / held | compliant / L |

### 3.10 Remaining system (legacy, still reachable in normal operation)

**Operational (reached by Agent / Sales / Admin daily):**

| Screen | Findings | Sev |
|---|---|---|
| Active Customers (`plan/list`) | Desktop table (736–738 px) in a scroller at 390 px. **Page-level overflow in Arabic at 360 px (751–753 px; the whole page zooms out).** 26 / 52 targets < 44 px (36 < 24 px). Filter row of 3 tiny selects. **Permission / UI mismatch:** "Edit" (`plan/edit`) and "Recharge Account" (`plan/recharge`) rendered to Agent / Sales, both 403. | **H** |
| Vouchers (`plan/voucher`, view, add) | 791 px table; voucher codes masked as black cells that **reveal only on hover** (unusable on touch); tiny checkboxes; "Delete > 3 Months" danger button at the top; view receipt 385 px at 360 RTL. "+ Vouchers" (`plan/add-voucher`) rendered to Agent / Sales → 403. | **H** |
| Recharge / refill / edit recharge (`plan/recharge`, `plan/refill`, `plan/edit`) (Admin) | Legacy single-column forms with small controls and radios; unlabelled selects (`id_customer`, `server`, `plan`, `using`); `plan/edit` 364 px at 360 RTL | M |
| Send message / bulk (`message/send`, `send_bulk`) | Usable single column, but the label / field pair stays side-by-side in RTL at 360 px; unlabelled `via` / `message`; bulk history uses DataTables ("Show N entries", 545 px table, CDN CSS) | M |
| Staff users (`settings/users`, view, add, edit) (Admin, Agent) | 701–703 px table, 30 / 36 targets < 44 px ("View" / "Edit" `btn-xs`); **page overflow at 360 RTL (857–863 px)**; "Add" rendered to Sales → 403 | **H** |
| Change password, maps (customer / routers / odp) | Maps usable (tiles external, WP6A residual); search unlabelled | L |

**Admin configuration and administration (Admin / SuperAdmin):**

| Screens | Findings | Sev |
|---|---|---|
| Plans: services hotspot / pppoe / vpn (+ edit), bandwidth, balance plans | 698–857 px tables; filter grids of 7–9 tiny unlabelled selects (axe `select-name` 8); edit forms with 20 unlabelled radios / inputs (`label` 16), `?` help icons < 24 px, CodeMirror via CDN; **page overflow at 360 RTL (719–942 px)** | **H** |
| Network: routers (+ edit), IP pool, port pool, ODP, RADIUS NAS | 384–570 px tables, overflow at 360 RTL. Hover-masked router credentials. **Defects:**<br>• `routers/edit` prints a raw Smarty comment as page text (`{* WP6A (OD-H) … *}`);<br>• the ODP list search box contains broken markup (`>` / `<div clas…` inside the input). | **H** |
| Reports (`reports`, `reports/activation`), logs (system / RADIUS / message) | 717 / 1315 px tables; overflow at 360 RTL (745 / 1391 px); unlabelled filters; Chart.js via CDN (WP6A residual R-1) | **H** |
| Settings (13 pages: general, localisation, misc, notifications, maintenance, devices, users, DB status, payment gateway, plugin manager, widgets, custom fields, docs) | Long forms with unlabelled controls (localisation 11, misc 10, notifications 7, widgets 12); axe `nested-interactive` 18 / `aria-required-parent` 16 on the settings index; DB status lacks `lang` / viewport and loads a 500 resource; the docs page is a static external HTML (980 px, not part of the app shell); plugin manager 42 contrast / 26 scrollable-region findings | M |
| Static pages editor (`pages/*`, 8) | Summernote rich-text editor: 4 Bootstrap modals, 16 icon buttons without names; overflow at 360 RTL on 3 pages | M |
| Community | External images / version JSON (legacy residual) | L |

**Cross-cutting:**
- axe at 390 px (149 page views): `color-contrast` 67, `select-name` 21, `button-name` 18, `label` 15, `link-name` 12, `scrollable-region-focusable` 12, `aria-input-field-name` 9, `link-in-text-block` 8, `aria-prohibited-attr` 8, others ≤ 3. Almost all are on legacy pages; shell pages are clean.
- Console errors: 403 / 404 resources on denied pages, a 404 avatar on staff view / customer edit, a 500 resource on DB status.

**Root causes:**
- **R-A:** the 768–~900 px "tablet band": viewport-based table → card switch while the rail reduces the content width;
- **R-B:** legacy `.table-responsive` containment fails in RTL (page overflow);
- **R-C:** legacy markup without the shell primitives (forms, row actions, filters, labels, targets);
- **R-D:** no shell-level Up / sticky-action / keyboard / safe-area conventions;
- **R-E:** legacy pages render actions without consulting the route authority.

## 4. Screens already compliant — no change planned

Login / logout; the adaptive shell (sidebar, rail, bottom navigation, More / Quick sheets); dashboards for all roles at phone width; subscriber list / search / cards / Subscriber 360 / create stepper (phone); activation list / create / preview / confirm / detail / operator dialogs (phone); reseller tree drill-down and detail (phone); finance overview / wallets / wallet / ledger / entry / transfers / transfer / send / funding / withdrawal / debt limit / adjustment / reversal / review → confirm (phone); finance reports (phone); the Operations Center (phone); Arabic RTL on all shell pages; money inputs (`inputmode=decimal`, LTR); the create-subscriber stepper JS.

These receive only shared-primitive effects: the tablet-band fix, Up affordance, sticky actions and safe areas. Their markup and behaviour are otherwise untouched.

## 5. CSS-only changes

1. **Tokens.** `--nx-page-pad` (16 px phone / 24 px tablet), `--nx-sticky-h`, `--nx-sheet-pad`, `--nx-gap-*`, a typography scale (`--nx-fs-*`), safe-area tokens `--nx-safe-{top,right,bottom,left}: env(safe-area-inset-*, 0px)`. Arbitrary page values are replaced by tokens where touched.
2. **Safe areas.** Applied to the topbar (top / sides), bottom navigation, sticky action bar, sheets (bottom), full-screen sheets and phone page padding (sides). This needs `viewport-fit=cover` in the viewport meta (§6.1).
3. **Tablet band (R-A) — container queries.** `.nuxr-adaptive` (the existing table + card pair wrapper) becomes an `inline-size` container. The table shows only when the **component** is ≥ its token width (default 720 px); otherwise the existing card list shows. Fallback for engines without container queries: today's viewport rule (`@supports not (container-type: inline-size)`). This fixes all 29 tablet overflows **without new markup or queries** (both representations are already rendered from one data set).
4. **Bottom-navigation clearance.** One shell rule guarantees `padding-bottom: calc(var(--nx-bottom-h) + var(--nx-safe-bottom) + gap)` for every page's main region. It fixes the pricing overlap and makes the per-page `!important` padding unnecessary.
5. **Keyboard.** On coarse pointers below 768 px, hide the bottom navigation while a form control is focused: `body:has(#nuxr-main :is(input, textarea, select):focus)`. It returns on blur, and the sticky action bar moves to the bottom.
6. **Legacy containment (R-B).**
   - In `nuxr-rtl-legacy.css` and the bridge layer: `.table-responsive`, `.box`, `.panel` and `.content` are contained (`max-width: 100%`, `overflow-x: auto` on the wrapper, `min-width: 0` on flex / grid children, RTL-correct scroller direction).
   - No legacy page may widen the page at 360 px. Remaining wide tables scroll inside a labelled, focusable region (`role=region`, `tabindex=0`, `aria-label`; this also closes axe `scrollable-region-focusable`).
7. **Legacy controls on coarse pointers.**
   - `.btn-xs` / `.btn-sm`, radios, checkboxes and `.form-control` reach 44 px hit areas (`min-height`, padding, label hit area) inside `#nuxr-main`.
   - `.form-horizontal` label / field columns stack below the container width (fixes the RTL side-by-side message form).
   - Filter grids stack in a 2-column phone grid.
8. **Subscriber 360 tabs.** Scroll-snap with visible edge fade and a full-width segmented layout when it fits.
9. **Typography / density.** Phone topbar title only (the in-page `h1` visually hidden on phone, kept for assistive tech and desktop); tightened phone card spacing via tokens.
10. **Contrast.** Legacy AdminLTE colour classes used on buttons and labels (`.btn-info`, `.btn-warning`, `.label-*`, links) are mapped to shell tokens with AA contrast.

## 6. Behaviour / interaction changes

1. **`viewport-fit=cover`** (header, login, error pages) with the safe-area tokens of §5.2.
2. **Up / Back (AdaptiveHeader).**
   - A deterministic parent ("Up") per route from one central map in `Ui\Navigation::up()`. Examples: `customers/view/*` → `customers/list`; `finance/entry/*` → the owner's ledger; `activation/view/*` → `activation/list` (or the subscriber when reached from it, via an allow-listed `back` parameter); `pricing/plan/*` → `pricing/plans`; legacy `*/edit/*` → its list.
   - Rendered as a topbar Back button on phone / tablet (RTL-flipped icon, 44 px, labelled) and as a breadcrumb-lite link on desktop.
   - Server-side and progressive: no `history.back()` dependency, so it works from deep links and never leaves the app; the browser Back keeps working because every page is a GET.
3. **AdaptiveActions — sticky action bar** (`nuxr/actionbar.tpl`). On phone, the primary / secondary actions of forms and review pages sit in a bar above the bottom navigation (or at the very bottom while typing), with safe-area padding. On desktop they render inline in the same DOM position.
   - Applied to: customer edit, legacy recharge / refill / edit, message send, staff add / edit, finance forms and reviews, pricing edit sheets, settings forms.
   - Typed-confirmation and pending semantics are unchanged.
4. **AdaptiveDialog for pricing edits.** Each pricing row shows one "Edit" action opening `<dialog class="nuxr-sheet">`: full-screen on phone, centred sheet / side panel on desktop. It holds the same forms, POSTing to the same accepted routes and setters; the inline forms are removed. Desktop keeps a dense row with one action instead of the inline form wall.
5. **AdaptiveFilters.** On phone, list filters (finance reports / ledger / transfers, Active Customers, vouchers, services lists, logs) collapse behind a "Filters (n)" button opening a bottom sheet with the same GET form. The active-filter count and chips stay visible. Desktop keeps the inline toolbar. Same query parameters: no server change.
6. **Row actions on legacy operational lists.**
   - On phone, the row is the tap target (→ detail / edit); secondary actions move into a row overflow menu (`<details>` menu, 44 px items) instead of `btn-xs` button clusters.
   - Destructive actions stay behind the accepted confirmations.
7. **Tap-to-reveal secrets.** Voucher codes and router credentials currently reveal on hover. They get a `data-nuxr-reveal` toggle button: keyboard and touch, `aria-pressed`, re-masked on blur. Masking intent is unchanged.
8. **Permission / UI parity (R-E).**
   - Legacy templates render an action only when `Navigation::allowed(route)` allows it (exposed to Smarty as a presentation helper).
   - Fixes: Active Customers "Edit" / "Recharge Account", Vouchers "+ Vouchers", staff "Add" for Sales.
   - Backend authorization is untouched and stays authoritative.
9. **Shell-native error pages.** 403 / 404 / generic error inside the shell (navigation present, `nuxr-empty` presentation, Up + Home, the role-appropriate message, same HTTP status, no detail leak).
10. **Defect fixes found in the audit:**
    - the `routers/edit` raw comment;
    - the ODP search-box markup;
    - DB status `lang` / viewport and its 500 resource;
    - the avatar 404 on staff view / customer edit (placeholder initial as in the shell);
    - static-pages editor icon buttons without names.

## 7. Shared adaptive components / tokens (proposed)

| Conceptual primitive | Implementation (reuses WP6A / WP6B) | Where |
|---|---|---|
| **AdaptiveNavigation** | Existing sidebar / rail / bottom navigation / sheets + Up / Back (§6.2) + safe areas + keyboard rule | shell |
| **AdaptiveDataView** | (a) Shell lists: existing table + `nuxr-mlist` pair under a `.nuxr-adaptive` container (container-query switch).<br>(b) Converted legacy operational lists: the same pair via a shared `nuxr/adaptive-list.tpl` include fed by the page's existing rows (one query).<br>(c) Admin configuration tables: `table.nuxr-stack`: CSS-only stacked label / value cards below the container width, using `data-label` on cells (one table, no duplicated DOM). | all lists |
| **AdaptiveDialog** | Existing `<dialog class="nuxr-sheet">` + new `nuxr-sheet-full@phone` modifier (full screen below 768 px, side panel / centred ≥ 768 px). Focus trap / restore and Escape as today. | pricing edits, filters, row menus |
| **AdaptiveActions** | `nuxr/actionbar.tpl` + `.nuxr-actionbar` (sticky on phone, inline on desktop) | forms, reviews, detail primaries |
| **AdaptiveFormLayout** | `.nuxr-form-adaptive`: CSS grid (multi-column ≥ container 720 px, single column below), grouped `fieldset` sections, 44 px controls, associated labels and errors (`aria-describedby`), correct `inputmode` / `type` / `autocomplete`. The existing stepper only where a flow is genuinely multi-step (subscriber create already). | customer edit, legacy forms, settings |
| **AdaptiveFilters** | Filter sheet + chip summary (§6.5) | lists / reports |
| **Tokens** | §5.1 | shell CSS |

Not abstracted (one-offs stay local): the Subscriber 360 tabs, the voucher receipt, the drift check.

## 8. Exact affected files (modified)

**Shell / shared:**
- `ui/ui/admin/header.tpl` (viewport-fit, Up / Back slot, title / h1);
- `ui/ui/admin/footer.tpl` (sheets);
- `ui/ui/styles/nuxr-shell.css` (tokens, safe areas, container queries, action bar, sheets, form layout, stack table, keyboard rule, clearance);
- `ui/ui/styles/nuxr-rtl-legacy.css` (legacy containment / RTL);
- `ui/ui/scripts/nuxr-shell.js` (filter-sheet wiring, reveal toggle, row menus; reuses the existing dialog code);
- `system/autoload/Reseller/Ui/Navigation.php` (`up()` map; `allowed()` exposed as a presentation helper);
- `system/autoload/Reseller/Ui/Format.php` (Smarty helper registration if needed);
- `ui/ui/admin/{403,404,error}.tpl`;
- `ui/ui/admin/admin/login.tpl` (viewport meta only);
- `system/lan/arabic.json` (new strings).

**Shell pages (wrapper class / action bar / filter sheet only; data and logic unchanged):**
- `ui/ui/admin/customers/{list,view,edit}.tpl`;
- `activation/{list,view}.tpl`;
- `reseller/{tree,detail}.tpl`;
- `finance/*.tpl`;
- `pricing/{plans,plan,catalog}.tpl` + `nuxr/pricing-row.tpl`;
- `nuxr/{activation-rows,ledger-rows,transfer-rows,list-pager}.tpl`;
- `operations/*.tpl` (Up only).

**Legacy operational (converted to the shell primitives; same controllers, variables, form fields, routes):**
- `ui/ui/admin/plan/{active,recharge,refill,edit}.tpl`;
- `voucher/{list,view,add}.tpl`;
- `message/{single,bulk}.tpl`;
- `admin/{list,view,add,edit}.tpl` (staff users);
- `change-password.tpl`;
- `reports/{list,activation}.tpl`;
- `logs/{system,radius,message}.tpl`.

**Legacy admin configuration (bridge: `nuxr-stack` tables with `data-label`, `.nuxr-form-adaptive`, label / for / ids, contrast, defects):**
- `hotspot/*`, `pppoe/*`, `vpn/*`, `bandwidth/*`, `balance/*`;
- `routers/*`, `pool/*`, `port/*`, `odp/*`, `radius/*`, `coupons/*`;
- `settings/*` (13);
- `paymentgateway/*`, `customfield`, `widgets`, `community.tpl`, `maps/*` (labels only).

The exact list depends on owner decision OD-1 (§21).

**Controllers:** none expected. Display variables already exist. If a legacy template needs a missing display variable, it comes from the existing controller query only (no new query) and is listed in the report.

## 9. Expected new files

- `ui/ui/admin/nuxr/actionbar.tpl`, `nuxr/adaptive-list.tpl`, `nuxr/filter-sheet.tpl`, `nuxr/row-menu.tpl`, `nuxr/up.tpl`
- `tests/Unit/Wp6c/Wp6cGuardTest.php`: escaping of touched templates; no new external asset; no user-agent / `isMobile` / new `matchMedia` beyond the stepper; no inline style widths in converted templates; tokens used
- `tests/Integration/Wp6c/AdaptiveShellTest.php`: Up map coverage and correctness per role; shell error pages (status, no leak, navigation); viewport meta
- `tests/Integration/Wp6c/ActionParityTest.php`: for each role, every action link rendered on the operational pages answers non-403 / 404 (extends WP6A's navigation-link test to page content)
- `tests/Integration/Wp6c/LegacyAdaptiveTest.php`: converted legacy pages keep their form field names / routes and still post through the same controllers (no behaviour change); the reveal toggle keeps masking
- `owner-review/WP6C/{PLAN.md, REPORT.md, TESTDOX.txt, VISUAL_QA.md, JOURNEYS.md, visual/}`
- `docs/WP6C_ADAPTIVE_UX_MODEL.md` (primitives, tokens, conventions); WP6A-model amendment note

## 10. Files / contracts that must remain untouched

- All of `system/autoload/Reseller/{Finance,Activation,Domain,Security,Radius,Audit,Backoffice}/*` except the presentation helpers in `Ui/*`.
- `RouteMap.php` (pin unchanged), `Capability`, `RoleCapabilityMap`, `PermissionService`, `DataScope`.
- `Package.php`, `Balance.php`, `Tx.php`, `ServiceGate.php`, `RadiusPolicyBuilder.php`, the migration runner, R0001–R0007 (no R0008), `install/*`.
- All controllers' queries, mutations and validation; API contracts (`API_ALLOWED`); authentication; URLs / routes; form field names; POST endpoints.
- WP6A / WP6B accepted decisions: navigation per role (OD-3), Report gets nothing (OD-5), review → execute contracts, typed confirmations, export caps, Operations Center semantics, pricing projection. R-15 is not reopened.

**Accepted-test expectations:**
- the WP6A / WP6B guard tests keep passing;
- if a WP6A / WP6B integration test pins markup that WP6C changes (e.g. the pricing inline forms, the duplicated `h1`), the exact diff is listed and justified in the report;
- the route matrix must be byte-identical (no RouteMap change).

## 11. Desktop regression risks (and mitigations)

| Risk | Mitigation |
|---|---|
| Container-query switch shows cards on wide desktop panels | Thresholds from the measured table widths; desktop captures at 1440 / 1280 / 1024 in visual QA; `@supports` fallback = today's behaviour |
| Pricing edit moves into a dialog (one extra click on desktop) | Desktop dialog is a side panel with the row visible; keyboard flow (Enter submits, Escape closes, focus returns); owner sign-off in visual QA |
| Legacy bridge CSS alters AdminLTE desktop look | Bridge rules scoped to coarse pointer / container width and `#nuxr-main`; desktop captures of every converted family |
| `h1` hidden on phone | Only visually hidden below 768 px; kept for screen readers and desktop |
| Up / Back changes desktop header height | Desktop renders a compact breadcrumb-lite line inside the existing header area |

## 12. Role / RBAC / DataScope risks

| Risk | Mitigation |
|---|---|
| A converted legacy page shows an action a role cannot use (the current R-E defect class) | Every action gated by `Navigation::allowed`; `ActionParityTest` requests every rendered action link per role |
| Up map links to a parent the role cannot open (e.g. Sales → a wallet) | `up()` returns a parent only when `Navigation::allowed`, else the role's home; tested per role |
| Card views expose more columns than the table did | Card templates render only fields already rendered by the page for that role (same variables); no new data or query; guard + review |
| Filter sheet exposes filters a role cannot use | Same form as today, moved; nothing new |
| Cross-tenant leakage | No new query / data path at all (presentation only); DataScope untouched; existing scope tests stay green |

## 13. RTL risks
- Container-query and sticky layouts must use logical properties (`inset-inline-*`, `padding-inline-*`, `margin-inline-*`).
- Directional icons flip: Back, chevrons, drawer / sheet slide direction (sheets slide from the bottom, so they are direction-neutral).
- Stack tables: label / value alignment uses logical properties; LTR technical tokens stay `<bdi dir="ltr">` (money, references, IPs, usernames).
- The Up arrow points to the inline-start in RTL.
- Every converted page is audited at 360 px Arabic with the page-overflow check (the R-B class).
- Animations use `transform` on the block axis only.

## 14. Accessibility risks
- Sheets / dialogs: native `<dialog>` with focus trap / restore, `aria-labelledby`, Escape, 44 px close buttons; the full-screen phone variant keeps the heading and Back.
- Hiding the bottom navigation during typing must not trap focus: the navigation is display-hidden only while a control has focus.
- Visually hidden `h1`: keep a single `h1` per page.
- Reveal toggles: `aria-pressed`, label text, no hover dependency.
- Legacy label association: `for` / `id` pairs, `aria-describedby` for help and errors, select names; axe target 0 serious / critical on every touched page.
- Scroll regions: focusable and labelled.
- Contrast: tokens AA.
- Desktop keyboard order unchanged (action bar in DOM order).

## 15. Performance risks
- No new library and no CDN; JS additions are small vanilla handlers (filter sheet, reveal, row menu), re-using the dialog code. Budget: `nuxr-shell.js` < 400 lines, CSS growth < 25 %.
- No duplicated queries or fetching: dual representations come from one server data set (existing pattern). `nuxr-stack` uses one DOM; the adaptive list renders the card list only for converted operational lists.
- Container queries are native CSS, no JS resize listeners. The keyboard rule is CSS `:has()`, with no JS.
- No layout shift: the action-bar space is reserved; sheets animate with `transform` / `opacity` and `prefers-reduced-motion`.
- Residuals unchanged and recorded: CodeMirror (service edit), DataTables CSS (bulk message history), Chart.js (reports) and the Google tiles (maps) load from CDNs. Removing them would be a dependency / feature change and is out of scope; noted only.

## 16. User-journey test matrix (lab, seeded data, real flows)

Each journey runs end-to-end in Chromium (Playwright) with real clicks, typing and submits against the lab, asserting server outcomes (database state through the app) and UI states. Viewports: phone (360 or 390), plus the desktop variant where marked.

| # | Journey | Role | Lang / VP | Asserts |
|---|---|---|---|---|
| J1 | Login → dashboard → logout | Admin, Agent, Sales, Report | AR 360, EN 390, desktop | role navigation, no overflow, logout returns to login |
| J2 | Bottom navigation + More → every destination → Up back | Agent | AR 360 | every destination opens; Up returns to the parent; no 403 |
| J3 | Search (typeahead) → subscriber 360 → Up | Sales | EN 390 | scoped result; Up = list |
| J4 | Subscriber list → Filters sheet → apply → clear | Admin | AR 360 | same GET parameters; count chip |
| J5 | Create subscriber (stepper) with a validation failure → fix → save | Agent | AR 390 | inline errors, focus on the first error, sticky action, created |
| J6 | Edit subscriber → keyboard open (bottom navigation hidden) → save | Admin | EN 390×480 | action bar visible over the keyboard; saved |
| J7 | Activate / renew → preview → confirm → status → Up | Agent | AR 360 | the accepted flow unchanged; sticky confirm |
| J8 | Stale activation → operator cancel dialog → confirm | Admin | EN 390 | dialog accessible; state CANCELLED |
| J9 | Finance send review → confirm (typed if required) → ledger → entry → Up | Agent | AR 360 | review → execute contract unchanged; Up = ledger |
| J10 | Debt limit decrease → typed confirmation → saved; stale review → REVIEW_EXPIRED | Admin | EN 390 | accepted semantics unchanged |
| J11 | Pricing: plan → Edit (full-screen sheet) → save retail → validation error → fix | Admin, Agent | AR 360 + desktop | same POST; sheet focus; no bottom-nav overlap |
| J12 | Active Customers card list → row menu → permitted actions only | Agent, Sales | AR 360 | no Edit / Recharge for Agent / Sales; no page overflow |
| J13 | Vouchers → tap-to-reveal code → view receipt | Sales | EN 390 | reveal toggle; no hover |
| J14 | Staff list → view → (Admin) edit → save | Admin | AR 360 | stack / cards, action bar |
| J15 | Router edit (admin bridge form) → validation failure → save | Admin | EN 390 | labels, errors, sticky save |
| J16 | Empty states: filtered empty list, no wallet yet, no stale activations | Admin, Agent | AR 360 | the shell empty state |
| J17 | Error / permission: Report opens `customers/list`; Agent opens `plan/edit/x` | Report, Agent | AR 360 | shell 403 page with navigation and Up / Home; HTTP 403 |
| J18 | Operations → stale → held → drift (run) | Admin | EN 390 | operator states and information-only items |
| J19 | Tablet band: subscriber list / finance ledger / pricing at 768 and 800 | Admin, Agent | EN | no page overflow; cards or table by container |
| J20 | Landscape phone 844×390: dashboard → form | Admin | AR | rail usable; action bar; no overflow |

## 17. Visual QA matrix (curated, ~36 captures + automated checks)

| Family | 360 AR | 390 EN | 430 EN | 768 | Desktop 1440 |
|---|---|---|---|---|---|
| Shell: dashboard + More sheet + Up | Agent | Sales | Admin | Admin | Admin |
| Subscribers: list + filter sheet; edit + action bar | Admin | Agent | — | Admin | Admin |
| Activations: list; confirm | Agent | — | Admin | Admin | — |
| Finance: ledger; review → confirm | Agent | Admin | — | Admin | Admin |
| Pricing: plan matrix; edit sheet | Admin | Agent | — | Admin | Admin |
| Reports / operations | Admin | — | Admin | Admin | — |
| Legacy operational: Active Customers, vouchers (reveal), staff, message | Agent / Sales | Sales | — | Admin | Admin |
| Legacy admin bridge: services list, router edit, settings form, logs | Admin | Admin | — | Admin | Admin |
| Error / permission / empty states | Report | Agent | — | — | Admin |
| Landscape 844×390 | Admin | | | | |

**Automated on every capture and every journey step:**
- page overflow against the **device width** (the WP6C audit showed `innerWidth` alone misses zoomed-out pages);
- inner scrollers;
- touch targets < 44 px in main content (allow-list: inline text links);
- fixed-navigation overlap with focusable content;
- safe-area padding present;
- axe serious / critical = 0;
- console errors (allow-listed known residual resources only);
- external requests (documented residuals only);
- `dir` / `lang`.

The full 745-view metrics sweep is re-run **once** at the candidate (metrics only, no full screenshots) to prove that the R-A / R-B classes are gone.

## 18. Implementation slices

| Slice | Content | Focused tests |
|---|---|---|
| 0 | Record the plan; tokens; `viewport-fit` + safe areas; container-query AdaptiveDataView for shell lists (tablet band); bottom-navigation clearance (pricing overlap); keyboard rule; legacy RTL containment (R-B) | guard; WP6A / WP6B navigation / guards; metrics sweep subset (768, 360 AR) |
| 1 | AdaptiveHeader (`Navigation::up`, Back / breadcrumb-lite, `h1` on phone); shell error pages; permission / UI parity helper and legacy gating (Active Customers, vouchers, staff) | `AdaptiveShellTest`, `ActionParityTest`, J1 / J2 / J17 |
| 2 | AdaptiveActions + AdaptiveFormLayout; customer edit conversion; legacy recharge / refill / edit, message send / bulk, change password, staff add / edit; finance / review sticky actions | `LegacyAdaptiveTest` (fields, routes); J5 / J6 / J9 / J10 / J14 |
| 3 | AdaptiveDataView for legacy operational lists (Active Customers, vouchers + reveal, staff, reports, logs); AdaptiveFilters (lists, finance reports / ledger / transfers) | J4 / J12 / J13 |
| 4 | Pricing AdaptiveDialog (plans / plan / catalog); Subscriber 360 tabs; reseller / activation Up wiring | WP6B `PricingUxTest` (adjusted only if markup-pinned; diff reported); J11 |
| 5 | Admin configuration bridge (`nuxr-stack`, form layout, labels, contrast) for services / bandwidth / network / settings / payment gateway / custom fields / widgets / pages editor / maps; audit defects (router comment, ODP markup, DB status, avatars) | J15; axe on all touched pages |
| 6 | Arabic strings; a11y sweep to axe 0 on touched pages; guards; docs (`WP6C_ADAPTIVE_UX_MODEL.md`, WP6A amendment) | `Wp6cGuardTest`, catalog check |
| Candidate | ONE canonical validation (§19) + journeys + curated visual QA + the one-time metrics sweep → REPORT | — |

## 19. Testing cadence

1. **Focused while building.** The slice's tests on MariaDB (and MySQL for integration tests) plus the relevant WP6A / WP6B guards and integration tests.
2. **ONE complete WP6C candidate validation:**
   - `run-tests.sh --testdox` on both engines plus the registry;
   - route matrix (expected byte-identical to WP6B: 1727 / 1354 / 373);
   - PHP 8.2 lint;
   - manifest 42 / 42; migrations unchanged;
   - secret sweep; AVAIA isolation;
   - all journeys J1–J20 (lab); curated visual QA (§17); the one-time metrics sweep.
3. **Owner review.**
4. **Focused owner fixes** (affected tests + affected journeys / captures only).
5. **At most ONE final complete validation**, only if runtime changes justify it. No repeated giant screenshot runs; no full runs after documentation-only commits.

## 20. Definition of Ready

| Item | State |
|---|---|
| Base accepted and clean (`1148559d`, WP6B ACCEPTED_WITH_NOTES) | ✔ |
| Read-only audit done on the running system (4 roles, 5 viewports, AR / EN, 745 page views, journeys probed) | ✔ |
| Scope, non-goals and untouched contracts written (§10; WP7 excluded) | ✔ |
| Shared primitives defined from existing WP6A / WP6B parts; no new dependency | ✔ |
| No schema, capability, RouteMap, business-rule or API change needed | ✔ |
| Test strategy, journeys, visual matrix and cadence defined | ✔ |
| Owner decisions | OD-1 and OD-2 open (§21): they decide scope depth, not architecture |

**Verdict: READY**, once the owner answers OD-1 and OD-2 (recommended defaults given). Implementation starts only on the owner's explicit GO.

## 21. Owner decisions required (grouped)

**OD-1 — Depth for the legacy admin-configuration pages (Tier B: services / plans, bandwidth, network, settings, payment gateway, custom fields, widgets, plugin manager, static-pages editor, community, docs).**
- **(A) Recommended — adaptive bridge.**
  - Containment (no page overflow, any language);
  - `nuxr-stack` card tables;
  - adaptive single-column forms with 44 px controls, labels and AA contrast;
  - sticky save;
  - defect fixes and axe 0.

  These pages become comfortably usable on phone without being redesigned, which is proportionate to infrequent administrator configuration.
- **(B)** Full redesign of every Tier-B page into bespoke shell layouts. Roughly doubles WP6C; same backend.

Tier A (operational pages used by Agent / Sales / Admin daily: Active Customers, vouchers, recharge / refill, messaging, staff, reports, logs, customer edit) is fully converted in either case.

**OD-2 — Pricing edit interaction (accepted WP6B screen).**
- **(A) Recommended.** One "Edit" per row opening an AdaptiveDialog: full-screen form on phone, side panel on desktop. Same forms, routes and setters; the inline multi-form "action wall" is removed on all sizes for one consistent model.
- **(B)** The dialog on phone only; desktop keeps today's inline forms (two markups to maintain, as today on desktop).

**Recorded, no owner input needed** (resolved in this plan):
- `viewport-fit=cover` + safe areas;
- container queries with fallback;
- Up map;
- shell error pages;
- presentation-only permission / UI parity;
- tap-to-reveal masking;
- hiding the bottom navigation while typing;
- the defect fixes;
- CDN residuals unchanged;
- the customer portal (`ui/ui/customer/*`) is **out of WP6C scope** (staff application only, as the audit scope defines). It is recorded as a future item, and the owner may add it explicitly.

---

## 22. Adversarial review (performed; resolutions applied above)

| # | Challenge | Resolution |
|---|---|---|
| 1 | Duplicated domain / business logic | None: every change is template / CSS / JS / Ui-helper. Controllers, services, validation and routes are untouched (§10); `LegacyAdaptiveTest` proves field names and routes are unchanged. |
| 2 | Duplicated queries / data fetching | The dual table / card representation is rendered from the page's existing data set (no second query, no client fetch); `nuxr-stack` is single-DOM; no AJAX added. |
| 3 | Cross-tenant leakage | No data path changes; cards show only fields the role already saw; DataScope / scope tests stay green. |
| 4 | Permission / UI mismatch | Found today (R-E). Fixed by gating with `Navigation::allowed`, guarded by `ActionParityTest` (all rendered actions per role) and Up-map role checks. |
| 5 | Desktop UX regression | Desktop keeps tables (container width), dense rows and inline toolbars; desktop captures in every family; `@supports` fallback. |
| 6 | Mobile layout that only stacks desktop components | Rejected: operational lists become cards with primary-first content and row menus; filters become sheets; forms get grouped single-column layouts and sticky actions; pricing gets a dedicated edit flow. Plain stacking (`nuxr-stack`) is limited to infrequent admin-configuration tables (OD-1 A) and is still a card view, not a squeezed table. |
| 7 | Excessive horizontal scrolling | Page-level horizontal scroll is forbidden (automated check against the device width). In-component scroll is allowed only for genuinely wide data (the voucher receipt, the static-pages editor), labelled and focusable. |
| 8 | Responsive hacks scattered in templates | Shared primitives in `nuxr-shell.css`; the guard forbids inline width styles / ad-hoc breakpoints in converted templates; tokens only. |
| 9 | Unnecessary JS viewport logic | Layout by CSS media / container queries; JS only for interaction changes (sheets, reveal, row menu); the guard forbids user-agent sniffing and new `matchMedia` outside the stepper. |
| 10 | Unnecessary dependencies | None added; CDN residuals unchanged; any need → stop and raise an owner decision. |
| 11 | Incorrect RTL | Logical properties; every converted page measured at 360 AR; icon flips; LTR tokens kept. |
| 12 | Broken Back behaviour | Deterministic server-side Up map (role-checked) plus the browser Back (all GETs); no `history.back()`; J2 / J3 / J9 / J17 test it. |
| 13 | Fixed navigation / sticky-action overlap | One clearance rule for the main region; the action bar stacks above the bottom navigation; automated overlap check; the pricing overlap (found today) fixed. |
| 14 | Unsafe safe-area behaviour | `viewport-fit=cover` + insets on every fixed / full-screen element (top, bottom, sides); tested in landscape. |
| 15 | Keyboard / input obstruction | The bottom navigation hides while typing; the action bar is sticky; focused fields scroll into view; J6 at 390×480. |
| 16 | Inaccessible dialogs / sheets | Native `<dialog>` (focus trap / restore, Escape), labelled; axe 0; keyboard journeys on desktop. |
| 17 | Mobile tables that remain unusable | Operational tables become cards; admin tables become stacked cards; the only remaining scroll regions are labelled exceptions listed in the report. |
| 18 | Fake app-like chrome without better workflows | Chrome changes are minimal (Up, action bar). The work is in workflows: pricing edit, filters, row actions, forms, permission parity, error states. |
| 19 | PWA scope leaking into WP6C | No manifest / service-worker / offline / push / install work. `viewport-fit=cover` is a browser-UI concern and does not imply PWA. Guard: no new manifest / service-worker files. |
| 20 | Reopening accepted WP6A / WP6B decisions | No navigation-per-role, capability, export, review / confirm, operations or pricing-projection change. The pricing interaction change is presentation only and is put to the owner (OD-2). Accepted-surface defects found (tablet band, pricing overlap) are fixed as presentation defects and recorded like the WP6B slice-0 correction. |

---

## 23. Major findings — summary
1. **Tablet band (768–~900 px):** 29 page views of accepted WP6A / WP6B shell pages overflow, because the table / card switch is viewport-based while the rail narrows the content. Fix: container queries, CSS only.
2. **Legacy RTL page overflow:** 30 page views at 360 px Arabic, up to 1391 px wide, on Active Customers, staff, services, network, reports and logs. The page zooms out on phones.
3. **Permission / UI mismatch on legacy pages:** Agent / Sales see "Edit", "Recharge Account" and "+ Vouchers"; Sales sees "Add" staff. All answer 403.
4. **77 of 138 staff templates are still legacy AdminLTE**, with tiny targets (up to 36 controls < 24 px on a page), hover-only secret reveal, filter grids of 7–9 selects, unlabelled forms, and axe `color-contrast` 67 / `select-name` 21 / `label` 15.
5. **Pricing on phone is an inline form wall**, and its last controls sit under the bottom navigation.
6. **No Up / Back convention** (35 detail / form page views), **no sticky actions** on long forms, the **bottom navigation stays over the keyboard**, and **safe areas are ineffective** (`viewport-fit` missing).
7. **Defects:** a raw template comment on router edit, broken ODP search markup, DB status page metadata / 500 resource, a weak legacy 403 page.
8. **Compliant:** login, the shell navigation, dashboards and the WP6A / WP6B phone flows (subscribers, activations, finance, reports, operations, reseller workspace) in both languages.

---

## 24. Owner decisions and binding amendments (recorded 2026-10-05, before runtime implementation)

**OD-1 — Legacy admin / configuration pages: APPROVED, Option A (Adaptive Layer).**
- No full redesign of every configuration page.
- Lower-frequency administration pages reach the WP6C standard without redesigning their desktop product model:
  - no unintended phone / tablet overflow;
  - usable Arabic RTL;
  - readable hierarchy;
  - touch-friendly controls;
  - labelled accessible forms;
  - single-column phone forms;
  - adaptive tables / cards where appropriate;
  - usable filters;
  - appropriate sheets;
  - sticky Save where it materially helps a long form;
  - no hover-only required interaction;
  - nothing hidden behind the bottom navigation;
  - no desktop regression.
- The daily-use pages receive the deeper conversion. No visual-brand redesign.

**OD-2 — Pricing editing: APPROVED, Option A.** One clear row-level `Edit` action.
- Phone: row → Edit → a full-screen adaptive editing workflow, not multiple inline mini-forms.
- Desktop: a structured side panel / editing surface instead of the inline control wall.
- All `PricingService` semantics, `PricingReadModel` redaction and capability checks are preserved.
- No pricing logic in templates / JS; no business-rule change.

**OD-3 — Customer portal: OUT OF SCOPE.** WP6C covers the authenticated staff application only (SuperAdmin / Admin, Agent, Sales, Report). The exclusion is recorded in the model and report; any equivalent hardening later is a separate owner-scoped package.

**Binding amendments:**
- **A. Permission / UI mismatches.** Fix every action leading to 403 for roles that should never have been offered it. Visibility comes from the centralized permission / capability / navigation machinery, not hard-coded role names. Backend authorization stays authoritative; both layers must agree.
- **B. Back / Up.** A consistent mobile Back / Up for detail and form workflows.
  - It does not repurpose or weaken `Reseller\Security\RouteMap`; the relationship registry lives in the UI / presentation layer.
  - It never reveals an inaccessible parent.
  - The browser Back keeps working but is not the only mechanism.
- **C. 403 / 404 inside the shell.** Navigation preserved, the state explained clearly, no unauthorized object details, a recovery path. No fake success / empty states.
- **D. 768 px is a blocker.** Fixed by component-width / container-query adaptation, not another arbitrary global breakpoint. Verify subscribers, activations, wallet tables, pricing, the reseller tree and dense data views.
- **E. Safe areas.** `viewport-fit=cover` where compatible, then verify the bottom navigation, sticky action bars, full-screen surfaces, sheets and form submission areas against `env(safe-area-inset-top/bottom)`. No blanket padding.
- **F. Keyboard.** The bottom navigation must not make long forms unusable with the keyboard open. Interaction / focus / viewport based (CSS / focus state first, minimal JS only if needed); no user-agent / device logic. At least one long form tested keyboard-open.
- **G. Hover-only reveal.** Every required hover interaction gets an explicit, accessible touch / keyboard equivalent. Unauthorized roles never receive newly exposed data; server-side visibility is not expanded. Presentation correction only.
- **H. Audit defects in scope.**
  - The router-edit comment leak, the broken ODP search markup and related legacy markup defects are fixed in WP6C.
  - The DB-status 500 is investigated first. If its fix needs a Domain / Finance / Activation / Security contract or behaviour change → stop that item and return an owner decision.

**Structure and constraints:**
- Slices 0–6 as planned; internal order may adjust for test dependencies without broadening scope.
- Shared components (action bar, adaptive list, filter sheet, row menu, Up / Back) are approved conceptually. The existing WP6A / WP6B components are used first; a new abstraction only where there are multiple real consumers.
- Mobile daily-use screens: meaningful cards / compact lists / progressive disclosure / row menus / master-detail. Never just `overflow-x: auto` around a legacy table. Horizontal scroll only where column relationships genuinely matter.
- Desktop stays a purpose-built mode: efficient tables, keyboard workflows, multi-column layouts and toolbars are kept.
- Untouched without an explicit owner decision:
  - schema / migrations;
  - Finance authority;
  - the Activation state machine;
  - pricing rules;
  - customer ownership;
  - RBAC capabilities;
  - DataScope;
  - API authority;
  - RouteMap semantics;
  - accepted WP5 / WP6 domain behaviour;
  - R-15.
- No business decisions in JS; no presentation-side validation as authority.
- Branch `wp6c/mobile-adaptive-ux` from `1148559d`; this plan is the first commit; additive commits only.
- Cadence: focused → ONE candidate validation → owner review → focused fixes → at most one final full run.
- The real journeys (§16) are mandatory.
- Curated visual QA: ~36 is an upper target, not a quota. 360 / 390 / 430 / 768 / desktop, Arabic RTL heavily represented. Automated probes: configured-width overflow, touch targets, fixed-navigation overlap, safe areas, serious / critical axe, console errors, external requests.
- Candidate stop: validation + journeys + visual QA + report → push only `wp6c/mobile-adaptive-ux`; `feat` and `main` unchanged; no deploy; no WP7.

