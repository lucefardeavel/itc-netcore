# WP6A — Curated candidate visual QA

Owner decision OD-K: one curated set (18 screenshots), not a repeat of the planning sweep. Run once on the candidate,
in the isolated lab only (`nuxbill-reseller-test` compose project; lab container `nuxbill-reseller-test-uxlab` serving a
copy of the candidate tree on the test network; MariaDB 10.11.19 test container; lab fixture data, no real customer data).

**Method.** Host Playwright Chromium (`visual/visualqa.py`): log in as the role, open the route, perform the listed
steps, then record per capture: viewport screenshot; axe-core 4.10.2 with the `wcag2a` + `wcag2aa` tags (serious and
critical violations); page-level horizontal overflow; `<html lang / dir>`; requests to any host other than the lab;
console / JS errors; interactive targets inside the main content smaller than 44 × 44 px.
Viewports: desktop 1440 × 900; phone 390 × 844 (mobile, touch, DPR 2); tablet 820 × 1180 (touch, DPR 2, interactive
check, not in the curated set). Emulated devices only (no physical phone / tablet; residual).

## Result

| | First pass on `e21ff687` (`visualqa-found-e21ff687.json`) | Final pass on `4722fd53` (`visualqa-final.json`, the screenshots) |
|---|---|---|
| axe serious / critical | `color-contrast` (links, 9 of 18 captures), `link-in-text-block` (footer links, desktop), `aria-required-children` (search listbox) | **0 on all 18** |
| Horizontal overflow | 0 / 18 | 0 / 18 |
| External requests | 0 / 18 | 0 / 18 |
| Console / JS errors | 0 / 18 | 0 / 18 |
| `<html dir>` | `rtl` on all 5 Arabic captures, `ltr` otherwise | same |

The three first-pass defects were fixed in `4722fd53` (CSS / JS / template markup only):
- links used the vendored AdminLTE `#3c8dbc` (≈ 3.6 : 1 on white): now the shell primary token at bare-`a` specificity
  (legacy `.btn` / menu colours still win); footer links underlined;
- the typeahead box kept `role="listbox"` while holding a message or the "See all results" link: the link is now an
  option (reachable with the arrow keys), and the box is `role="status"` while it only shows a message;
- card-head "All" links had a 20 px touch height on phones: now 44 px.

## Curated set (`visual/`)

Columns: role, viewport, `lang / dir`, route, overflow, first-pass axe, final axe, targets < 44 px, external requests, errors.

| Screenshot | Role | Viewport | Lang | Route | Overflow | First pass axe | Final axe | < 44 px | Ext | Err |
|---|---|---|---|---|---|---|---|---|---|---|
| `01-desktop-admin-dashboard.png` | Admin | desktop | en / ltr | `dashboard` | no | color-contrast×4, link-in-text-block×2 | 0 | 0 | 0 | 0 |
| `02-desktop-agent-subscribers.png` | Agent | desktop | en / ltr | `customers/list` | no | color-contrast×33, link-in-text-block×2 | 0 | 31 | 0 | 0 |
| `03-desktop-admin-subscriber360.png` | Admin | desktop | en / ltr | `customers/view/10` | no | color-contrast×5, link-in-text-block×2 | 0 | 0 | 0 | 0 |
| `04-desktop-agent-activation-confirm.png` | Agent | desktop | en / ltr | `activation/create` | no | color-contrast×2, link-in-text-block×2 | 0 | 0 | 0 | 0 |
| `05-desktop-admin-activation-reconciliation.png` | Admin | desktop | en / ltr | `activation/view/ACT-52A5B5ED771B9645` | no | color-contrast×3, link-in-text-block×2 | 0 | 0 | 0 | 0 |
| `06-desktop-admin-360-arabic-rtl.png` | Admin | desktop | ar / rtl | `customers/view/12` | no | color-contrast×5, link-in-text-block×2 | 0 | 0 | 0 | 0 |
| `07-phone-agent-dashboard-arabic.png` | Agent | phone | ar / rtl | `dashboard` | no | 0 | 0 | 0 | 0 | 0 |
| `08-phone-sales-subscriber-cards.png` | Sales | phone | en / ltr | `customers/list` | no | 0 | 0 | 0 | 0 | 0 |
| `09-phone-agent-360-arabic-sticky-action.png` | Agent | phone | ar / rtl | `customers/view/10` | no | color-contrast×1 | 0 | 0 | 0 | 0 |
| `10-phone-sales-create-step1.png` | Sales | phone | en / ltr | `customers/add` | no | 0 | 0 | 0 | 0 | 0 |
| `11-phone-sales-create-review.png` | Sales | phone | en / ltr | `customers/add` | no | 0 | 0 | 1 | 0 | 0 |
| `12-phone-sales-confirm-retail-only.png` | Sales | phone | en / ltr | `activation/create` | no | 0 | 0 | 0 | 0 | 0 |
| `13-phone-agent-activation-status-arabic.png` | Agent | phone | ar / rtl | `activation/view/ACT-A60EA69A9D45BB3B` | no | color-contrast×1 | 0 | 0 | 0 | 0 |
| `14-phone-admin-danger-delete-dialog.png` | Admin | phone | en / ltr | `customers/view/14` | no | 0 | 0 | 0 | 0 | 0 |
| `15-phone-report-empty-state.png` | Report | phone | en / ltr | `dashboard` | no | 0 | 0 | 0 | 0 | 0 |
| `16-phone-agent-search-arabic-name.png` | Agent | phone | ar / rtl | `dashboard` | no | aria-required-children×1 | 0 | 0 | 0 | 0 |
| `17-phone-sales-more-sheet.png` | Sales | phone | en / ltr | `dashboard` | no | 0 | 0 | 0 | 0 | 0 |
| `18-desktop-agent-search-empty-state.png` | Agent | desktop | en / ltr | `customers/list` | no | color-contrast×2, link-in-text-block×2 | 0 | 0 | 0 | 0 |

What each capture shows:
- 01 Admin dashboard foundation: subscriber and activation counts in scope, "Needs attention" list, empty "Expiring soon", the legacy widgets tab.
- 02 Agent subscriber list (desktop table): service / expiry / account state, phone links, Open.
- 03 Admin Subscriber 360: identity, service with days left, RADIUS "no session yet" (stored data only), recent activations, ownership assignment, more actions.
- 04 Agent confirmation: wholesale (charged to the reseller wallet), retail, available credit now / after, current expiry and new period, paying owner.
- 05 Admin activation status in RECONCILIATION_REQUIRED: explanation, persisted timeline, money cards, Reconcile / Roll back (Admin only).
- 06 Admin 360 in Arabic RTL: in-flight activation banner, "Renew / change plan" blocked with the reason, LTR technical tokens.
- 07 Agent phone dashboard in Arabic: bottom navigation Home / Customers / + / Activations / More (OD-J).
- 08 Sales phone subscriber cards.
- 09 Agent phone 360 in Arabic with the sticky primary action.
- 10–11 Sales four-step create: step 1 and the review step (Arabic name, LTR username).
- 12 Sales phone confirmation: **retail and "credit sufficient" only** — no wholesale, balance or available amount (OD-B).
- 13 Agent phone activation status in Arabic (rollback pending, operator review message).
- 14 Admin phone danger zone: delete dialog with the consequence text and typed-username confirmation (button disabled until it matches).
- 15 Report phone: honest empty state (no data views for the role; no dead tiles).
- 16 Agent phone search sheet: Arabic name query, scoped result with status, "See all results".
- 17 Sales phone More sheet: only destinations the role may open (lab plugin entries "WP2 …" are test-fixture plugins).
- 18 Agent desktop search with no match: empty state.

## Tablet (interactive check, `visualqa-tablet.json`)

| Probe | Role | Lang | Route | Overflow | axe | < 44 px | Ext | Err |
|---|---|---|---|---|---|---|---|---|
| `t1-tablet-agent-subscribers-arabic` | Agent | ar / rtl | `customers/list` | no | 0 | 31 | 0 | 0 |
| `t2-tablet-admin-360` | Admin | en / ltr | `customers/view/10` | no | 0 | 0 | 0 | 0 |
| `t3-tablet-agent-activation-confirm` | Agent | en / ltr | `activation/create` | no | 0 | 0 | 0 | 0 |

Tablet uses the icon rail and the desktop table. The only sub-44 px targets (here and on 02) are the bulk-select row
checkboxes (≈ 18 px; each sits alone in a row far taller than 24 px, so WCAG 2.5.8 (AA, 24 px) is met through its spacing exception; the 44 px shell target is not) and the labelled
"Send welcome message" checkbox on 11 (the label is the target). Recorded as a residual, not changed.
