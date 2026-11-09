# ITC NetCore: product identity

Owner decision 2026-10-18:

| Item | Value |
|---|---|
| `PRODUCT_NAME_APPROVED` | **ITC NetCore** |
| `BRAND` | **ITC** |
| `TAGLINE` | **ISP Management & Billing Platform** |
| `VERSION` | **V1.0** |

This file covers how the name is written, shown and isolated from internal names, and the visual identity (section 3).
Visual identity: Brand Direction B "Core Infrastructure" and Color Direction B "Network Operations" (owner, 2026-10-24);
the L1 mark, outlined wordmark, palette and typefaces of the v0.7 / BR-RC1 candidate, **approved by delegation on
2026-10-26** (owner instruction of 2026-10-25; Brand Approval Board on the project Drive). This is not a trademark or
name clearance: LIC-1 stays open.

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
`install/phpnuxbill.sql`), the product name is shown in its place, and the mark becomes the product's L1 mark. The setting
itself is never rewritten. Invoices, invoice e-mails and notification messages print the **stored** setting, so a printed
financial document always matches what was configured. The production checklist should therefore set `CompanyName`
before go-live.

## 3. Visual identity

| Element | Rule | Implementation |
|---|---|---|
| Colour tokens | one source for every surface: brand primitives `--nc-*` (navy #193768, core blue #245D99, interactive #3275B5, signal teal #117C88) -> the existing semantic contract `--nx-*` (light and dark); every text / surface pair >= 4.5:1, focus >= 3:1 (guard-tested from the CSS) | `ui/ui/styles/nuxr-brand.css` (loaded first on the staff shell, sign-in, customer portal, public and error pages); `nuxr-shell.css` holds no colour value |
| Status semantics | SAS4-inspired meaning only (green active, blue online / info, orange expired, red disabled); the existing tones `success / info / warning / danger / neutral / processing` | `.nuxr-badge.is-*`, `Format::STATE_TONE` |
| Mark (L1 "Structural N") | full-colour on light surfaces, reversed white on dark surfaces (never the navy / full-colour artwork on dark); the micro variant at 16-32 px | `ui/ui/images/brand/netcore-mark*.svg` (byte-identical v0.7 sources, `ASSETS.txt`) |
| Where the mark shows | product places only: staff sign-in, About, favicon, PWA icons, installer; in the shell / portal header ONLY when the company is the product (an operator keeps its name and initial) | `$_brand.company_is_product` |
| Wordmark | the outlined vector wordmark (no live text), light and reversed artwork; never re-typed | `ui/ui/images/brand/netcore-wordmark*.svg` |
| Typefaces | Inter (Latin) and Noto Sans Arabic, self-hosted WOFF2 subsets (OFL-1.1, licences next to the files); no external font request | `ui/ui/fonts/brand/`, `--nx-font` |
| App icons / favicon | white tile + the full-colour mark (maskable / apple-touch full bleed, mark inside the 80 % safe zone); favicon SVG + ICO from the micro mark | `dev/brand/make_brand_assets.py` -> `ui/ui/images/pwa/icon-*.png`, `ui/ui/images/brand/favicon.svg`, `favicon.ico` |

An operator that wants its own app icon regenerates the four PWA PNGs (same names and sizes, e.g. `dev/pwa-lab/make_icons.py
--text "ش"`) and bumps `Pwa::VERSION` together with the service worker `VERSION` and the `nuxr-pwa.js` asset query
(guard-tested). The ITC parent logo is not used in the product UI; its source file stays byte-identical on the project Drive.

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

## 5. What the interface shows, and what the distribution keeps

- Owner request 2026-11-09 (maintenance branch `maint/v1.0.1-itc-only`): the interface shows **ITC only**. The footers show
  the product line; the About page shows the product, version, ITC, website and phone numbers. No original-project,
  licence, theme or donation credit is shown, and no test or trial wording.
- The distribution keeps the legal files: `LICENSE` unchanged, `NOTICE` naming the original project and the open legal
  points, the copyright headers in the source files and the installer's copyright line. Whether the interface must also
  show the attribution is part of LIC-1 (counsel).

## 6. GitHub repository profile (suggested text; not applied)

The repository is **not** renamed or moved. The text below is a suggestion for the owner to apply manually:

- **Description:** `ITC NetCore — ISP Management & Billing Platform: reseller wallets and ledger, FreeRADIUS + MikroTik (PPPoE / Hotspot), data quotas, vouchers, Arabic-first RTL staff PWA. Based on PHPNuxBill (GPL-2.0).`
- **Topics:** `isp-billing`, `radius`, `freeradius`, `mikrotik`, `pppoe`, `hotspot`, `reseller`, `wallet`, `php`, `pwa`, `rtl`, `arabic`
- **Website:** none until a public product page exists.
- **Social preview:** the L1 mark on white (from `ui/ui/images/pwa/icon-512.png`) once the owner applies the profile.

## 7. Guard

`tests/Unit/Wp10/BrandIdentityTest.php` covers the following:

- the approved constants;
- the operator fallback;
- page frames that use `$_brand` and not the raw setting;
- the ITC-only footers and About page, and the original project's notice kept in the installer, README and NOTICE;
- `LICENSE` and `install/phpnuxbill.sql` byte-unchanged, and the internal names kept;
- no business, security, RADIUS or finance code reading `Brand`;
- no `PHPNuxBill`, donation, original-project support link or test / trial wording in the interface;
- an Arabic entry for every new string, and `<bdi>` isolation in the footers.

`tests/Unit/Wp10/BrandIdentityAssetsTest.php` covers the visual identity: the asset hashes and their vector-only content,
the self-hosted fonts and licences, the single token source loaded before the shell, the WCAG AA matrix computed from the
CSS, and the rule that the product mark never replaces an operator's identity.
