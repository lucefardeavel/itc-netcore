# WP6B — curated visual QA

Lab only (disposable snapshot `/srv/phpnuxbill/uxlab`, isolated `nuxbill-reseller-test` network, disposable MariaDB database; runtime files synced from the candidate). Chromium (Playwright), emulated devices: desktop 1440×900, tablet 820×1180, phone 390×844 (every phone page re-measured at 360×780). axe-core 4.10.2, WCAG 2 A/AA, serious / critical only.

Lab data: the WP6A lab seed plus a sale by B (A earns a commission) and a stale FUNDS_RESERVED activation (WP5A fault hook). Review pages are only reviewed, never executed (no lab write). Capture 26 (Sales) was taken in a second lab session on the same runtime after the final validation, to cover the Sales role: no Finance / Resellers / Pricing / Operations destination.

| # | Capture | Role | Viewport | Lang | Final route | dir | Overflow | Bottom nav | 360 px overflow / nav | axe serious+critical | External | Console errors |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `01-desktop-admin-finance-overview` | ad | desktop | en | `finance/overview` | ltr | no | — | — | 0 | 0 | 0 |
| 2 | `02-desktop-admin-wallets` | ad | desktop | en | `finance/wallets` | ltr | no | — | — | 0 | 0 | 0 |
| 3 | `03-desktop-admin-wallet-detail` | ad | desktop | en | `finance/wallet/5` | ltr | no | — | — | 0 | 0 | 0 |
| 4 | `04-desktop-admin-funding-review` | ad | desktop | en | `finance/funding-review` | ltr | no | — | — | 0 | 0 | 0 |
| 5 | `05-phone-admin-wallet-corrections-arabic` | ad | phone | ar | `finance/wallet/5` | rtl | no | visible | no / visible | 0 | 0 | 0 |
| 6 | `06-desktop-admin-ledger` | ad | desktop | en | `finance/ledger/5` | ltr | no | — | — | 0 | 0 | 0 |
| 7 | `07-phone-agent-wallet-home-arabic` | A | phone | ar | `finance/overview` | rtl | no | visible | no / visible | 0 | 0 | 0 |
| 8 | `08-phone-agent-send-review` | A | phone | en | `finance/send-review` | ltr | no | visible | no / visible | 0 | 0 | 0 |
| 9 | `09-phone-agent-ledger-arabic` | A | phone | ar | `finance/ledger` | rtl | no | visible | no / visible | 0 | 0 | 0 |
| 10 | `10-desktop-admin-transfer-detail` | ad | desktop | en | `finance/transfer/2` | ltr | no | — | — | 0 | 0 | 0 |
| 11 | `11-desktop-admin-transfer-reverse-form` | ad | desktop | en | `finance/reverse/2` | ltr | no | — | — | 0 | 0 | 0 |
| 12 | `12-desktop-admin-pricing-plans` | ad | desktop | en | `pricing/plans` | ltr | no | — | — | 0 | 0 | 0 |
| 13 | `13-desktop-admin-pricing-matrix` | ad | desktop | en | `pricing/plan/2` | ltr | no | — | — | 0 | 0 | 0 |
| 14 | `14-phone-agent-pricing-arabic` | A | phone | ar | `pricing/plans` | rtl | no | visible | no / visible | 0 | 0 | 0 |
| 15 | `15-desktop-agent-commissions` | A | desktop | en | `finance/commissions` | ltr | no | — | — | 0 | 0 | 0 |
| 16 | `16-desktop-admin-provider-settlement` | ad | desktop | en | `finance/settlement` | ltr | no | — | — | 0 | 0 | 0 |
| 17 | `17-phone-agent-settlement-card-arabic` | A | phone | ar | `activation/view/ACT-A0C5F916589A867F` | rtl | no | visible | no / visible | 0 | 0 | 0 |
| 18 | `18-desktop-admin-operations-overview` | ad | desktop | en | `operations/overview` | ltr | no | — | — | 0 | 0 | 0 |
| 19 | `19-desktop-admin-operations-activations` | ad | desktop | en | `operations/activations` | ltr | no | — | — | 0 | 0 | 0 |
| 20 | `20-phone-admin-stale-cancel-dialog` | ad | phone | en | `activation/view/ACT-B2BD4AF74AC89E1E` | ltr | no | visible | no / visible | 0 | 0 | 0 |
| 21 | `21-phone-agent-reseller-tree-arabic` | A | phone | ar | `reseller/tree` | rtl | no | visible | no / visible | 0 | 0 | 0 |
| 22 | `22-desktop-admin-reseller-detail` | ad | desktop | en | `reseller/detail/5` | ltr | no | — | — | 0 | 0 | 0 |
| 23 | `23-phone-admin-dashboard-arabic` | ad | phone | ar | `dashboard` | rtl | no | visible | no / visible | 0 | 0 | 0 |
| 24 | `24-phone-agent-dashboard-quick-sheet` | A | phone | en | `dashboard` | ltr | no | visible | no / visible | 0 | 0 | 0 |
| 25 | `25-phone-report-dashboard` | rp | phone | en | `dashboard` | ltr | no | visible | no / visible | 0 | 0 | 0 |
| 26 | `26-phone-sales-more-sheet-arabic` | A1 | phone | ar | `dashboard` | rtl | no | visible | no / visible | 0 | 0 | 0 |
| 27 | `t1-tablet-agent-wallet-arabic` | A | tablet | ar | `finance/overview` | rtl | no | — | — | 0 | 0 | 0 |
| 28 | `t2-tablet-admin-pricing-matrix` | ad | tablet | en | `pricing/plan/2` | ltr | no | — | — | 0 | 0 | 0 |
| 29 | `t3-tablet-admin-operations` | ad | tablet | en | `operations/activations` | ltr | no | — | — | 0 | 0 | 0 |

**Totals:** 29 captures (14 desktop, 12 phone, 3 tablet; roles: Admin, Agent, Sales, Report); axe serious/critical: 0; page overflow: 0 (390 px) / 0 (360 px); bottom navigation clipped: 0; external requests: 0; console errors: 0; step failures: 0.

## Found and fixed during the pre-validation smoke (commit `a78ef1c3`)

| Finding | Fix |
|---|---|
| Agent "Ledger" destination (`finance/ledger`, no wallet id) redirected to Finance | A bare ledger is the Agent's own; Admin opens ledgers from a wallet (no bare item) |
| Operations Center listed "needs attention" items again under "stale" | Stale list excludes the attention states |
| Operator-review item (PRIOR_SECRET_UNRESTORABLE) showed "No action available" next to a Roll back button | The list offers only "Open" for operator-review items (the activation page still applies the accepted primitive table) |
| Empty "Your margin" column for a global viewer on the pricing matrix | Column shown only to a non-global viewer |
| Legacy `custom.js` posted `autoload/server` (403, no CSRF) on any radio change, e.g. choosing the child reseller on the send form | Handler runs only on the legacy recharge form (`#server` present) |
| axe `link-in-text-block` on the ledger page sub-line | Sub-line links underlined |
| Wallet tab not active on the send review page | Match list extended |

## Limits

Emulated devices (no physical phones); Arabic copy is a drafted translation for owner review; the lab uses the test locale ("Rp." money format).
