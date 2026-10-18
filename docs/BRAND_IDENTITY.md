# ITC NetCore: product identity (interim text identity)

Owner decision 2026-10-18:

| Item | Value |
|---|---|
| `PRODUCT_NAME_APPROVED` | **ITC NetCore** |
| `BRAND` | **ITC** |
| `TAGLINE` | **ISP Management & Billing Platform** |
| `VERSION` | **V1.0** |

This file covers how the name is written, shown and isolated from internal names. It is a **text identity**. No final logo
has been designed or decided, and the icons below are explicitly interim.

## 1. Writing the name

| Use | Write | Never |
|---|---|---|
| Product name | `ITC NetCore` (two words, `ITC` in capitals, `NetCore` in camel case) | `ITC Netcore`, `ITCNetCore`, `itc-netcore`, `Net Core` |
| Brand / company | `ITC` | a translated or expanded form of ITC |
| Tagline (English) | `ISP Management & Billing Platform` | other wording in the product |
| Tagline (Arabic) | `منصة إدارة وفوترة مزوّدي خدمة الإنترنت` | |
| Version | `V1.0` (capital V) | `v1`, `1.0.0` in visible text |
| Responsibility line | "Developed and maintained by ITC" / `تطوير وصيانة ITC` | statements of copyright ownership or trademark status (open legal points, LIC-1) |

The product name stays in Latin script inside Arabic text and is not translated or transliterated. Wherever it sits inside a
sentence it is wrapped in `<bdi>` (or the `.itc-wordmark` class) so the bidirectional algorithm keeps it whole in RTL.

## 2. Two identities: product and operator

| Identity | Source | Where it appears |
|---|---|---|
| **Product**: ITC NetCore by ITC | `Reseller\Ui\Brand` constants (`system/autoload/Reseller/Ui/Brand.php`), as `$_brand` in every template | staff footer and version link, staff sign-in page (under the card), About page, customer footers ("Powered by"), installer, README, NOTICE |
| **Operator**: the ISP's `CompanyName` setting | `Brand::company($config)` | page titles, staff shell brand, customer portal header, error pages, PWA manifest name, invoices and messages (raw setting) |

When the operator has not configured a name (an empty value, or the stock `PHPNuxBill` placeholder seeded by
`install/phpnuxbill.sql`), the product name is shown in its place, and the mark becomes the text mark `ITC`. The setting
itself is never rewritten. Invoices, invoice e-mails and notification messages print the **stored** setting, so a printed
financial document always matches what was configured. The production checklist should therefore set `CompanyName`
before go-live.

## 3. Interim visual identity (existing design tokens only)

| Element | Rule | Implementation |
|---|---|---|
| Colours | the existing WP6C tokens: primary `--nx-primary` (#3442c9 light / #8f9bff dark), text `--nx-text`, muted `--nx-muted` | `ui/ui/styles/nuxr-shell.css` |
| Wordmark | `ITC` bold (800, primary colour, +0.04em tracking) followed by `NetCore` regular in the text colour, always LTR | `.itc-wordmark`, `.itc-wm-brand`, `.itc-wm-product` |
| Mark | the shell's rounded primary square with the text `ITC` (12px, 800) when the product name is shown; the operator's initial otherwise | `.nuxr-brand-mark.is-word` |
| App icons / favicon | the same rounded primary square with `ITC` in white (DejaVu Sans Bold), generated deterministically; maskable artwork inside the 80 % safe zone | `dev/pwa-lab/make_icons.py --text ITC`, `ui/ui/images/pwa/icon-*.png` |
| Typeface | the shell font stack (`--nx-font`); no new font is shipped | |

To replace the interim identity when a final logo exists, replace the four PNGs under `ui/ui/images/pwa/` (same names and
sizes) and bump `Pwa::VERSION` together with the service worker `VERSION` (guard-tested), then adjust the
`.itc-wordmark` rules. Nothing else depends on them.

## 4. What is not renamed (and why)

| Identifier | Stays | Reason |
|---|---|---|
| Database names, tables (`tbl_*`, `radcheck` …), columns, settings keys (`CompanyName`) | yes | data compatibility with every existing installation and migration |
| `install/phpnuxbill.sql` | yes, byte-identical | the migration runner identifies the installed schema variant by its SHA-256 (`system/reseller/Migration/variants.php`) |
| Routes (`logs/phpnuxbill`, `community` …), API fields, CSRF / PWA cache namespaces (`nuxr-*`) | yes | bookmarks, integrations, guard tests |
| FreeRADIUS site `phpnuxbill`, `dictionary.phpnuxbill`, `Nuxr-*` attributes | yes | deployed RADIUS configuration and NAS behaviour |
| PHP namespaces (`Reseller\…`), file paths, CSS file names (`phpnuxbill.css`) | yes | code structure |
| Composer package name `hotspotbilling/phpnuxbill` | yes | internal metadata (the repository is not renamed or moved) |
| Download file names (`phpnuxbill_customers_*.csv`, `phpnuxbill_reports_*.pdf`, backup JSON) | yes for now | operators' scripts may match them; renaming is an owner decision |
| Source-file headers "PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/) by https://t.me/ibnux" | yes | attribution of the original authors |

## 5. Credits that must stay

- The footers credit the original project (`Based on the open-source project PHPNuxBill (iBNuX, GNU GPL v2)`) and the theme (AdminLTE).
- The About page carries "About ITC NetCore" first, then the original project's credits, contributors, community links and
  version, labelled **Original project** and marked as not being ITC support channels.
- The installer keeps "Copyright © 2021 PHPNuxBill. All Rights Reserved".
- `LICENSE` is unchanged. `NOTICE` names the original project and the open legal points. The original README is kept verbatim
  in `docs/UPSTREAM_PHPNUXBILL_README.md`.

## 6. GitHub repository profile (suggested text; not applied)

The repository is **not** renamed or moved. The text below is a suggestion for the owner to apply manually:

- **Description:** `ITC NetCore — ISP Management & Billing Platform: reseller wallets and ledger, FreeRADIUS + MikroTik (PPPoE / Hotspot), data quotas, vouchers, Arabic-first RTL staff PWA. Based on PHPNuxBill (GPL-2.0).`
- **Topics:** `isp-billing`, `radius`, `freeradius`, `mikrotik`, `pppoe`, `hotspot`, `reseller`, `wallet`, `php`, `pwa`, `rtl`, `arabic`
- **Website:** none until a public product page exists.
- **Social preview:** none until a final logo exists (the interim mark is not a logo).

## 7. Guard

`tests/Unit/Wp10/BrandIdentityTest.php` covers the following:

- the approved constants;
- the operator fallback;
- page frames that use `$_brand` and not the raw setting;
- the original project's credit in the footers, About page, installer, README and NOTICE;
- `LICENSE` and `install/phpnuxbill.sql` byte-unchanged, and the internal names kept;
- no business, security, RADIUS or finance code reading `Brand`;
- `PHPNuxBill` in templates only in the credits and the upstream-release notice;
- an Arabic entry for every new string, and `<bdi>` isolation in the footers.
