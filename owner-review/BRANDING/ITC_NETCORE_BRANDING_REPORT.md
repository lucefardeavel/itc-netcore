# ITC NetCore: product identity applied (report)

Owner decision 2026-10-18: `PRODUCT_NAME_APPROVED = ITC NetCore`, `BRAND = ITC`, `TAGLINE = ISP Management & Billing Platform`,
`VERSION = V1.0`. Executed autonomously on the approved development tree (`/srv/phpnuxbill/dev`, branch `wp9/red-team-rc`,
local only).

**Not done, by instruction:**
- the repository was not renamed or moved;
- nothing was pushed or merged, and nothing was deployed;
- WP8, WP9 and WP10 were not re-executed;
- no database, table, API, RADIUS setting or internal path was renamed;
- no licence was changed, and LIC-1 stays open.

Identity rules: `docs/BRAND_IDENTITY.md`.

## 1. Candidate

| | |
|---|---|
| Previous product candidate | `9da2015e` |
| New product candidate | the commit that carries this report (product files changed: 45, see section 3). The earlier test results are **not** taken as proof for it; the gates in section 5 were rerun on the new tree |
| Business code changed | **none**: no file under `system/autoload/Reseller/{Finance,Radius,Activation,Security,Domain}`, `system/reseller/`, `install/freeradius/`, `install/*.sql`, `radius.php` differs from `9da2015e` |

## 2. Approach (Ali Design System: plan → execute → test → review → record)

- **One source of truth.** `system/autoload/Reseller/Ui/Brand.php` holds the name, brand, tagline, version, the vendor (ITC) and the upstream credit. `system/boot.php` passes it to every template as `$_brand`, before anything can render (the error page included). Nothing outside the UI reads it, and a guard test enforces that.
- **Two identities kept apart:**
  - The **product** (ITC NetCore by ITC) appears in the footers, the sign-in page, the About page, the phone "More" sheet, the installer, README and NOTICE.
  - The **operator** (the ISP's `CompanyName`) appears in titles, the shell and the customer portal.
  - An unconfigured operator name (empty, or the stock `PHPNuxBill` placeholder) shows the product name and the text mark `ITC`. The stored setting is never rewritten.
  - Invoices and messages keep printing the stored setting, so a printed financial document matches the configuration.
- **Text identity only.** The wordmark is "**ITC** NetCore" and the mark is the shell's primary rounded square with "ITC", using the existing design tokens. The PWA icons and the favicon are regenerated deterministically with "ITC". No logo was invented.
- **Credit kept.** Every footer credits PHPNuxBill (iBNuX, GNU GPL v2) and AdminLTE. The About page has "About ITC NetCore" first, then the original project's contributors, links, donations and credits. Those are labelled as the original project's and "not ITC support channels". The installer keeps "Copyright © 2021 PHPNuxBill. All Rights Reserved". The original README is kept verbatim in `docs/UPSTREAM_PHPNUXBILL_README.md`. `LICENSE` is byte-unchanged.

## 3. Where the identity was applied

| Surface | Change |
|---|---|
| Staff shell (desktop / tablet / phone) | title `<page> - <operator or ITC NetCore>`; brand mark (operator initial, or `ITC`); footer: "ITC NetCore · ISP Management & Billing Platform · developed and maintained by ITC" + upstream / theme credit + version link "ITC NetCore V1.0" to About; phone / tablet "More" sheet ends with "ITC NetCore V1.0" (the footer is hidden on phones by design) |
| Staff sign-in | operator name + mark; under the card the wordmark, tagline and V1.0 |
| About (`community` route, menu label "About") | new "About ITC NetCore" block: product, version, developed and maintained by ITC, based on PHPNuxBill (iBNuX and contributors), base version, licence (GNU GPL v2; see LICENSE and NOTICE), statement that original notices and licences are kept; then the original project's section, labelled. Pre-existing accessibility faults of that page fixed on the way: two images without `alt`, links not distinguishable in text, muted text below AA contrast |
| Dashboard | the upstream-release toast is relabelled "PHPNuxBill (upstream) <version>" + "Updates are installed only through the reviewed release process" + "Details" (it no longer says "New Version Available / Update Now"); the footer always shows the product version |
| Customer portal | header / title / mini mark from `$_brand`; default footer "Powered by ITC NetCore · based on PHPNuxBill (iBNuX, GNU GPL v2) · theme AdminLTE" (an operator `CompanyFooter` still replaces it); public pages (login, register, forgot): "Powered by ITC NetCore"; moon login themes: "© year <operator>. All rights reserved. Powered by ITC NetCore." |
| Error / alert pages | operator name from `$_brand` (escaped); favicon |
| PWA | manifest name = operator name, product name when unconfigured; interim icons "ITC" (`dev/pwa-lab/make_icons.py --text ITC`, byte-reproducible); `Pwa::VERSION` = service-worker `VERSION` = `wp7.2` so installed apps refresh the icons |
| Settings → General | field "Company Name" with help text (shown to customers and on invoices; the product name is shown while it is empty) |
| Device popovers (Hotspot / PPPoE / VPN plan forms), device descriptions, logs page title / menu label, test messages (e-mail / SMS / WhatsApp / Telegram) | "ITC NetCore" instead of "PHPNuxBill" |
| Installer (`install/*.php`) | "ITC NetCore Installer / Updater", application name and responsibility line; the upstream copyright line kept |
| Arabic | 22 new entries in `system/lan/arabic.json` (one older About sentence lacked Arabic: found by the new guard, added); the Latin name is isolated with `<bdi>` inside Arabic text |
| Documents | `README.md` (new product README with status, credits and the licence situation), `NOTICE` (new), `docs/BRAND_IDENTITY.md` (new: naming rules, the two identities, interim visual rules, what is not renamed, suggested GitHub description and topics), `docs/UPSTREAM_PHPNUXBILL_README.md` (verbatim), `owner-review/WP10/LEGAL_REVIEW_PACKAGE.md` (facts + Q12 - Q14) |

**Kept on purpose (internal or financial):** database, tables, settings keys, `install/phpnuxbill.sql` (byte-identical: the migration runner identifies the schema variant by its hash), routes (`logs/phpnuxbill`, `community`), API fields, FreeRADIUS site / dictionary `phpnuxbill`, `Nuxr-*` attributes, cache namespaces `nuxr-*`, CSS / file names, Composer package name, download file names (`phpnuxbill_*.csv/pdf/json`), source-file headers crediting the original authors, the invoice template (prints the stored operator name), `CHANGELOG.md` (the original project's history).

## 4. Guard

`tests/Unit/Wp10/BrandIdentityTest.php` (8 tests) checks the following:
- the approved constants;
- the operator fallback, including an Arabic name;
- every page frame uses `$_brand` and not the raw setting;
- `_brand` is assigned before any display;
- the original project is credited in the footers, About page, installer, NOTICE and README;
- `LICENSE` and `install/phpnuxbill.sql` are byte-unchanged, and the internal names are kept;
- no business, security, RADIUS or finance class reads `Brand`;
- `PHPNuxBill` appears in templates only in the credits and the upstream notice;
- every brand string has an Arabic entry, with `<bdi>` isolation;
- the PWA uses the brand fallback and the "ITC" icon recipe.

`PwaManifestTest` was updated for the new fallback name (`PHPNuxBill` → `ITC NetCore`) by owner decision, not to relax it. It now also asserts the stock-placeholder case.

## 5. Tests on the new tree

| Gate | Result | Evidence (`owner-review/BRANDING/evidence/`) |
|---|---|---|
| PHP lint of every changed PHP file (18 + lab test) | 0 errors | (console) |
| `BrandIdentityTest`: first run | **1 failure** (an older About sentence had no Arabic entry), kept | `brand-test-run1-FAIL.txt` |
| `BrandIdentityTest` + Wp7 unit after the fix | OK 25 / 394 | `brand-test-run2.txt` |
| `--testsuite unit` (final tree) | OK 150 / 1972. **Note:** this suite excludes the WP2 - WP7 unit folders, so it did not cover the WP6A / WP7 guards | `unit-suite.txt` |
| Whole `tests/Unit` directory (after the fixes below) | OK 424 / 50567 | `unit-all.txt` |
| PWA browser suite (secure context, root + `/billing/` subdirectory, offline, update, CSRF, axe), rerun on the final tree after run 2 | **32 / 32 PASS** (cache now `nuxr-pwa:…:wp7.2`) | `pwa-suite-32.txt` |
| Brand visual / functional check (`dev/pwa-lab/brand_visual.py`), rerun on the final tree after run 2 | both phases **CLEAN**. Phase *operator* (Arabic operator name) and phase *product* (stock placeholder → ITC NetCore). Each covers staff sign-in, customer sign-in, dashboard footer, About, and the phone More sheet, in Arabic (RTL) and English, on desktop 1366 × 900 and phone 390 × 844: 18 captures plus the manifest per phase. Checks: required / forbidden texts, title, mark, page overflow, clipped identity elements, and axe-core WCAG 2 A/AA serious + critical on the whole page | `brand_visual-operator.json`, `brand_visual-product.json` |
| Brand visual: first runs (findings, kept) | 8 findings: AA contrast of muted text (About and the customer "Powered by" line), a link not distinguishable from text, and pre-existing missing `alt` / link names on the About page. A clipped About table on phones (values cut off inside `table-responsive`) was found by looking at the screenshot; the probe then gained a clipping check. All fixed before the final runs | `visual-run1-findings/` |
| Full dual-engine harness, run 1 | **MariaDB: 1569 tests, 2 failures** (stopped before MySQL). (1) `Wp6aSourceGuardTest`: `{$_brand.upstream_url}` printed without `escape` (a constant URL, but every template variable must be escaped or allow-listed). (2) `ShellNavigationTest`: the new footer link `community#about` was read as a route and answered 404. Fixed by escaping and by linking `community` without a fragment (the About block is at the top of the page). Kept | `full-run1-mariadb-2FAIL-stopped.log` |
| `ShellNavigationTest` + `PwaRoutesTest` after the fix, both engines | OK 4 / 230 and 6 / 117 on each engine | `focused/` |
| Full dual-engine harness, run 2 (`dev/test/run-tests.sh`, MariaDB 10.11 + MySQL 8.0, real FreeRADIUS) on the final tree | **MariaDB 10.11: 1569 tests / 77038 assertions, 0 failures; MySQL 8.0: 1569 / 77040, 0 failures**; 125 incomplete (the real-NAS lab classes, as on `9da2015e`: 1561 + the 8 new guard tests) and the same 1 PHPUnit deprecation as before; variant registry reproducible; exit 0. The product files are byte-identical to the tested tree (`tested-tree.txt`) | `full-run2.log` |

**Not rerun:** CHR gates, failure drill, load and capacity, OBS-1. No file they exercise changed (section 1): no RADIUS, quota, activation, finance or schema code. Screenshots stay outside Git (rule: no images in evidence) and the JSON results record each capture.

## 6. Points that need an owner decision (visual / product)

| # | Point | Current state |
|---|---|---|
| B-1 | **Final logo**: the identity is a text mark ("ITC" in the primary square). Replace the four PNGs in `ui/ui/images/pwa/` and bump `Pwa::VERSION` / service-worker `VERSION` when a logo exists | interim |
| B-2 | **Brand colour**: the existing WP6C primary `#3442c9` is kept. Should ITC have its own colour? | unchanged |
| B-3 | The original project's **donation, paid-support and WhatsApp-gateway boxes** on the About page are kept, labelled as the original project's. Keep, collapse, or remove from the product? | kept, labelled |
| B-4 | The **"Stand with Palestine" banner** of the original project (About page top, and the top of the original README) is kept on the About page. It is not in the new README, but it is kept verbatim in `docs/UPSTREAM_PHPNUXBILL_README.md` | owner's choice |
| B-5 | **Download file names** `phpnuxbill_*.csv / .pdf / .json`: keep for compatibility of operators' scripts, or rename to `itc-netcore_*` | kept |
| B-6 | The root `favicon.ico` and `ui/ui/images/logo.png`, `system/uploads/*.default.png` (original project artwork used as default invoice / login logos) are still in the tree. The templates no longer use `logo.png` as favicon. Replace the defaults when a logo exists | kept |
| B-7 | **GitHub profile text** (`docs/BRAND_IDENTITY.md` section 6) to be applied manually by the owner; the repository is not renamed | suggestion |
| B-8 | Production checklist: set `CompanyName` before go-live (invoices print the stored value) | recommended |

## 7. Points that need a legal decision (LIC-1 stays OPEN)

Recorded in `owner-review/WP10/LEGAL_REVIEW_PACKAGE.md`:
- **New fact for Q1:** the original README says "GNU General Public License version 2 **or later**"; LICENSE / headers carry no version statement.
- **Q12:** name clearance for "ITC NetCore" / "ITC" (other companies use "ITC" as a mark; no search was made; no registration or clearance is claimed anywhere).
- **Q13:** authorship / copyright line for ITC's modifications and GPLv2 2(a) change notices (the product only says "developed and maintained by ITC").
- **Q14:** the Terms and Conditions / Privacy Policy templates (`pages_template/`) still name PHPNuxBill as the party. They were **not** changed, because they are customer-facing legal text.
- **Q8** (use of "PHPNuxBill", "MikroTik", "RouterOS" names) stands.
- Checklist item 6 (notices page) is now partly prepared (About + footers); counsel decides whether that is sufficient.

## 8. Release state

Unchanged: **`RELEASE_BLOCKED_WITH_EVIDENCE`**. The target acceptance TA-01 … TA-10 must run on the **new** candidate SHA (the staging authorization request names "the candidate SHA in the acceptance package"; that is now this commit). Nothing was pushed.

## 9. Round 2 (2026-10-18): fixes found while writing the product guide

The owner asked for fixes to the faults found while photographing the system for the product guide, and for a guide that explains every part of the system.

| Fault | Fix | Guard |
|---|---|---|
| The quota card showed a 50 GB plan as **46.6 GB**: plans define MB / GB in decimal units (`QuotaPolicy::bytes`, 1 GB = 10^9 bytes) while the card divided by 1024 | `Format::bytes` uses decimal units (and drops a trailing ".0"), so the card reads "50 GB"; enforcement was never affected | `QuotaDisplayUnitsTest` |
| The quota card labels, and **486** other interface strings (templates and application PHP), had no Arabic entry, so parts of the Arabic interface showed English | 486 Arabic entries added to `system/lan/arabic.json`. Three keys with non-ASCII characters are keyed byte-wise, as `Lang::T` sanitises them | `ArabicCatalogueTest`: every `Lang::T` literal in every template and in the application PHP (vendor, plugins, payment gateways excluded) has an Arabic entry; no duplicate or empty key |
| Page titles written as English literals (NAS, invoice, plan edit, order, payment gateway, plugin manager, pages) and raw labels on the NAS forms and the customer pages (Secret, Ports, Community, Privacy, T & C, Remember me) | wrapped in `Lang::T` with Arabic entries; the NAS "Ports" field is labelled "CoA port", "Community" "SNMP community" | the same catalogue test |
| The activation result notice showed the raw state code ("ACT-…: COMPLETED") in the Arabic interface | the four notices use the translated state label (`Format::stateLabel`), as one already did | `ArabicCatalogueTest::testActivationNoticesShowTheTranslatedState` |

The guide (an Arabic web page and a PDF, kept outside the repository) covers the 23 areas of the system with 111 screenshots. As the owner asked, the guide's own text carries no credit or test-environment wording. The product itself is unchanged in that respect: footers, the About page, `NOTICE` and `LICENSE` keep the credits.

Tests of this round: see `evidence/round2/`.

| Gate (round 2, final tree) | Result | Evidence (`evidence/round2/`) |
|---|---|---|
| Whole `tests/Unit` directory | OK 429 / 54709 | `unit-all.txt` |
| Full dual-engine harness | MariaDB 1574 / 81148 and MySQL 1574 / 81150. **One failure on each engine, the same test:** `Subscriber360Test` still pinned the old binary display ("11.0 MB", and a 5 MB quota shown as "4.8 MB"). Its expectations were moved to the decimal units of the fix ("11.5 MB", "5 MB"), with a comment; nothing else failed. 125 incomplete and the one PHPUnit deprecation are as before | `full-run-1FAIL-Subscriber360-binary-units.log` |
| `Subscriber360Test` after the expectation change, both engines | OK 5 / 175 on each | `focused/` |
| PWA browser suite | 32 / 32 PASS | `pwa-suite.txt` |
| Brand visual check, both phases | CLEAN | `brand_visual-*.json` |

The product files are byte-identical to the tree the harness ran on (`tested-tree.txt`); only the test file changed after it.
