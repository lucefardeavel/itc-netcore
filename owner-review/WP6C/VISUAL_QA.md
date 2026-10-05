# WP6C Visual QA (candidate)

Curated captures of the candidate implementation `cd7b43c0`, taken in the lab (`nuxbill-reseller-test-uxlab`, emulated Chromium devices), with heavy Arabic RTL. There are 37 captures, against an upper target of about 36.

## Method

**Viewports:**
- phone: 360, 390 and 430 px;
- tablet: 768 px;
- landscape: 844×390;
- desktop: 1440 px.

**Roles:** Admin (`ad`), Agent (`A`), Sales (`A1`), Report (`rp`).

**Automated probe per capture:**
- horizontal overflow against the configured device width, not `innerWidth`;
- touch targets under 40 px for interactive controls in the content area (phone / tablet);
- bottom navigation fully inside the viewport;
- whether the sticky action row overlaps the navigation;
- whether any page control lies under the bottom navigation after scrolling to the end;
- axe-core WCAG 2 A/AA serious / critical violations;
- external requests;
- console errors.

**Safe areas:** captures 36–37 simulate a notched device (top 47 px, bottom 34 px), which is what `env(safe-area-inset-*)` feeds into the `--nx-safe-*` tokens. The top bar, the bottom navigation and the sticky action row are re-measured.

**Keyboard:** captures 11 and 12 focus a text field. While a text field has focus the bottom navigation is hidden and the action row sits on the bottom edge (`bar` = top/bottom px).

| # | Capture | Role | Viewport | Lang | HTTP | dir | Overflow | Bottom nav | Action row (top–bottom px) | Under nav | Small targets | axe S/C | External | Console |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | [01-phone360-admin-dashboard-ar](visual/01-phone360-admin-dashboard-ar.png) | ad | phone360 360 | ar | 200 | rtl | no | ok | - | none | none | none | none | none |
| 2 | [02-phone-agent-customers-ar](visual/02-phone-agent-customers-ar.png) | A | phone 390 | ar | 200 | rtl | no | ok | - | none | none | none | none | none |
| 3 | [03-phone-agent-subscriber360-ar](visual/03-phone-agent-subscriber360-ar.png) | A | phone 390 | ar | 200 | rtl | no | ok | - | none | none | none | none | none |
| 4 | [04-tablet-admin-subscriber360-activations-ar](visual/04-tablet-admin-subscriber360-activations-ar.png) | ad | tablet 768 | ar | 200 | rtl | no | n/a (rail) | - | - | none | none | none | none |
| 5 | [05-phone360-admin-active-customers-ar](visual/05-phone360-admin-active-customers-ar.png) | ad | phone360 360 | ar | 200 | rtl | no | ok | - | none | none | none | none | none |
| 6 | [06-phone-admin-active-rowmenu](visual/06-phone-admin-active-rowmenu.png) | ad | phone 390 | en | 200 | ltr | no | ok | - | - | none | none | none | none |
| 7 | [07-phone-admin-vouchers-filter-ar](visual/07-phone-admin-vouchers-filter-ar.png) | ad | phone 390 | ar | 200 | rtl | no | ok | - | - | none | none | none | none |
| 8 | [08-phone-admin-voucher-reveal](visual/08-phone-admin-voucher-reveal.png) | ad | phone 390 | en | 200 | ltr | no | ok | - | - | none | none | none | none |
| 9 | [09-phone-agent-vouchers-restricted](visual/09-phone-agent-vouchers-restricted.png) | A | phone 390 | en | 200 | ltr | no | ok | - | none | none | none | none | none |
| 10 | [10-tablet-admin-reports-ar](visual/10-tablet-admin-reports-ar.png) | ad | tablet 768 | ar | 200 | rtl | no | n/a (rail) | - | - | none | none | cdn.jsdelivr.net | none |
| 11 | [11-phone-admin-recharge-keyboard](visual/11-phone-admin-recharge-keyboard.png) | ad | phone 390 | en | 200 | ltr | no | ok | 659–728 sticky | - | none | none | none | none |
| 12 | [12-phone-admin-customer-edit-keyboard-ar](visual/12-phone-admin-customer-edit-keyboard-ar.png) | ad | phone 390 | ar | 200 | rtl | no | hidden (typing / sheet) | 775–844 sticky | - | none | none | none | status line 404 |
| 13 | [13-phone-agent-activation-create](visual/13-phone-agent-activation-create.png) | A | phone 390 | en | 200 | ltr | no | ok | - | none | none | none | none | none |
| 14 | [14-phone-agent-activation-view-up-ar](visual/14-phone-agent-activation-view-up-ar.png) | A | phone 390 | ar | 200 | rtl | no | ok | - | none | none | none | none | none |
| 15 | [15-phone-agent-send-review](visual/15-phone-agent-send-review.png) | A | phone 390 | en | 200 | ltr | no | ok | 490–559 sticky | - | none | none | none | none |
| 16 | [16-tablet-admin-wallets-ar](visual/16-tablet-admin-wallets-ar.png) | ad | tablet 768 | ar | 200 | rtl | no | n/a (rail) | - | - | none | none | none | none |
| 17 | [17-landscape-agent-ledger](visual/17-landscape-agent-ledger.png) | A | landscape 844 | en | 200 | ltr | no | n/a (rail) | - | - | none | none | none | none |
| 18 | [18-phone-admin-pricing-editor-ar](visual/18-phone-admin-pricing-editor-ar.png) | ad | phone 390 | ar | 200 | rtl | no | hidden (typing / sheet) | - | - | none | none | none | none |
| 19 | [19-desktop-admin-pricing-sidepanel](visual/19-desktop-admin-pricing-sidepanel.png) | ad | desktop 1440 | en | 200 | ltr | no | n/a (rail) | - | - | none | none | none | none |
| 20 | [20-desktop-admin-pricing-sidepanel-ar](visual/20-desktop-admin-pricing-sidepanel-ar.png) | ad | desktop 1440 | ar | 200 | rtl | no | n/a (rail) | - | - | none | none | none | none |
| 21 | [21-phone-agent-pricing-editor](visual/21-phone-agent-pricing-editor.png) | A | phone 390 | en | 200 | ltr | no | hidden (typing / sheet) | - | - | none | none | none | none |
| 22 | [22-tablet-agent-reseller-tree-ar](visual/22-tablet-agent-reseller-tree-ar.png) | A | tablet 768 | ar | 200 | rtl | no | n/a (rail) | - | - | none | none | none | none |
| 23 | [23-phone-agent-403-in-shell-ar](visual/23-phone-agent-403-in-shell-ar.png) | A | phone 390 | ar | 403 | rtl | no | ok | - | none | none | none | none | status line 403 |
| 24 | [24-phone-admin-404-in-shell](visual/24-phone-admin-404-in-shell.png) | ad | phone 390 | en | 404 | ltr | no | ok | - | none | none | none | none | status line 404 |
| 25 | [25-desktop-sales-403-in-shell](visual/25-desktop-sales-403-in-shell.png) | A1 | desktop 1440 | en | 403 | ltr | no | n/a (rail) | - | - | none | none | none | status line 403 |
| 26 | [26-phone360-admin-hotspot-plans-ar](visual/26-phone360-admin-hotspot-plans-ar.png) | ad | phone360 360 | ar | 200 | rtl | no | ok | - | none | none | none | none | none |
| 27 | [27-phone-admin-hotspot-edit-sticky](visual/27-phone-admin-hotspot-edit-sticky.png) | ad | phone 390 | en | 200 | ltr | no | ok | 711–780 sticky | none | TEXTAREA.:14, TEXTAREA.:14 | none | cdnjs.cloudflare.com | none |
| 28 | [28-tablet-admin-routers-ar](visual/28-tablet-admin-routers-ar.png) | ad | tablet 768 | ar | 200 | rtl | no | n/a (rail) | - | - | none | none | none | none |
| 29 | [29-phone-admin-router-edit-peek](visual/29-phone-admin-router-edit-peek.png) | ad | phone 390 | en | 200 | ltr | no | ok | 711–780 sticky | - | none | none | none | none |
| 30 | [30-phone-admin-settings-misc-ar](visual/30-phone-admin-settings-misc-ar.png) | ad | phone 390 | ar | 200 | rtl | no | ok | 711–780 sticky | none | none | none | none | none |
| 31 | [31-desktop-admin-dbstatus](visual/31-desktop-admin-dbstatus.png) | ad | desktop 1440 | en | 200 | ltr | no | n/a (rail) | - | - | none | none | none | none |
| 32 | [32-desktop-admin-hotspot-plans](visual/32-desktop-admin-hotspot-plans.png) | ad | desktop 1440 | ar | 200 | rtl | no | n/a (rail) | - | - | none | none | none | none |
| 33 | [33-desktop-admin-operations](visual/33-desktop-admin-operations.png) | ad | desktop 1440 | en | 200 | ltr | no | n/a (rail) | - | - | none | none | none | none |
| 34 | [34-phone-sales-more-sheet-ar](visual/34-phone-sales-more-sheet-ar.png) | A1 | phone 390 | ar | 200 | rtl | no | ok | - | - | none | none | none | none |
| 35 | [35-phone-report-dashboard](visual/35-phone-report-dashboard.png) | rp | phone430 430 | en | 200 | ltr | no | ok | - | none | none | none | none | none |
| 36 | [36-inset-phone-agent-dashboard](visual/36-inset-phone-agent-dashboard.png) | A | phone 390 | en | 200 | ltr | no | ok | - | none | none | none | none | none |
| 37 | [37-inset-phone-admin-recharge-sticky](visual/37-inset-phone-admin-recharge-sticky.png) | ad | phone 390 | en | 200 | ltr | no | ok | 591–660 sticky | none | none | none | none | none |

## Summary

| Check | Result |
|---|---|
| Horizontal overflow (configured width) | **0 / 37** |
| Bottom navigation clipped, or sticky row over the navigation | **0 / 37** |
| Page controls under the bottom navigation | **0** |
| axe-core serious / critical violations | **0 / 37** |
| Touch targets under 40 px | 0, apart from CodeMirror's internal 14 px input textarea in capture 27 (third-party editor, not a target) |
| External requests | captures 10 (Chart.js, cdn.jsdelivr.net) and 27 (CodeMirror, cdnjs): documented WP6A residuals; none elsewhere |
| Console entries | the status line of the intended 403 / 404 pages (23–25), and capture 12's upstream default avatar 404 (`/user.default.jpg` is not shipped in the repository; REPORT §I); no JavaScript errors |
| Safe areas (36–37, simulated notch 47 / 34 px) | the top bar, bottom navigation and sticky row move by the insets; no overlap |
| Keyboard (11–12) | while a text field has focus, the navigation is hidden and the row sits on the bottom edge; the focused field is not covered |

