# Third-party components and license metadata (WP10)

Status: inventory taken from the repository on 2026-10-16 (branch `wp9/red-team-rc`). It is a **metadata report, not legal advice**. Rows marked **LEGAL REVIEW** must be confirmed by counsel before the product is distributed.

## 1. The application itself

| Item | Evidence | Note |
|---|---|---|
| PHPNuxBill (upstream) and this fork | `LICENSE` = the GNU GPL version 2 text. No source file states "or (at your option) any later version" | The fork is a derivative work of a GPL program. **LEGAL REVIEW:** whether the version is "2 only" or "any version" (GPL §9 when no version is specified in the program). |

### Distribution obligations, as far as verified
- **Hosting the software for an ISP (Option A, operated by the vendor):** GPLv2 has no network-use clause, so running it as a service does not by itself trigger source distribution.
  - This assumes no AGPL component. None was found.
- **Delivering the software to an ISP to run on its own servers:** that is distribution. GPLv2 §1–3 then apply:
  - ship the complete corresponding source, or a written offer for it;
  - keep every copyright and license notice;
  - license the whole derivative work under the GPL.
- **Proprietary add-ons** linked into the PHP application would be part of the derivative work. **LEGAL REVIEW** before any closed-source module is shipped.

## 2. Server-side PHP libraries (`system/composer.lock`, shipped in `system/vendor/`)

| Package | Version | License (composer metadata) | Note |
|---|---|---|---|
| mpdf/mpdf | v8.1.6 | GPL-2.0-only | PDF invoices |
| mpdf/psr-log-aware-trait | v2.0.0 | MIT | |
| myclabs/deep-copy | 1.11.1 | MIT | |
| paragonie/random_compat | v9.99.100 | MIT | |
| psr/http-message | 1.1 | MIT | |
| psr/log | 1.1.4 | MIT | |
| setasign/fpdi | v2.3.7 | MIT | |
| smarty/smarty | v4.5.3 | LGPL-3.0 | **LEGAL REVIEW:** LGPL-3.0 used by a GPL-2.0 work (compatible only if the application is GPL-2.0-or-later, or under the LGPLv3 combined-work terms) |
| yosiazwan/php-facedetection | 0.1.0 | GPL-2.0 | Used by `customers.php` / `accounts.php` (photo crop). Its package contains `lena512color.jpg`, a third-party test photograph. Recommend removing that file from release artifacts. |

## 3. PHP code vendored outside composer (`system/autoload/`)

| Component | License (file header) |
|---|---|
| PEAR2 Net_RouterOS 1.0.0b6 (`PEAR2/`) | LGPL-2.1 |
| PHPMailer (`mail/`) | LGPL-2.1 |
| Parsedown (`Parsedown.php`) | MIT (refers to its LICENSE file, which is not included). Add the MIT notice to the release. |

## 4. Browser assets shipped in `ui/ui/`

| Asset | Version | License |
|---|---|---|
| jQuery (`scripts/jquery.min.js`, `vendors.js`) | 1.11.1 | MIT. **Security note:** an old version (see the readiness package, residual R-JQ-1) |
| Bootstrap (`scripts/bootstrap.min.js`, `styles/bootstrap.min.css`, Glyphicons fonts) | 3.4.1 | MIT |
| AdminLTE (`scripts/adminlte.min.js`, `styles/modern-AdminLTE.min.css`) | 2.4.18 | MIT |
| SweetAlert2 | 11.10.5 | MIT |
| PACE | 1.0.2 | MIT |
| Select2 (`scripts/plugins/select2.min.js`) | 4.x | MIT |
| nicEdit | — | MIT |
| Summernote (`summernote/`) | 0.8.18 | MIT (`summernote.min.js.LICENSE.txt`) |
| md5.js (Paul Johnston) | 1.1 | BSD (pajhome.org.uk/site/legal.html) |
| Font Awesome (`fonts/font-awesome`) | 4.x | font: SIL OFL 1.1; CSS: MIT |
| Ionicons (`fonts/ionicons`) | 2.x | MIT |

## 5. Assets loaded from CDNs at run time

All of them are version-pinned and carry Subresource Integrity (WP9 SC-1). They are fetched by the browser, not distributed by us.

| Asset | Version | License |
|---|---|---|
| Chart.js | 3.5.1 | MIT |
| chartjs-plugin-autocolors | 0.5.1 | MIT |
| jQuery (coupon list, bulk message) | 3.6.0 | MIT |
| DataTables | 1.11.3 | MIT |
| Font Awesome CSS | 6.0.0 / 4.7.0 | MIT (CSS), SIL OFL (fonts) |
| jsPDF | 1.3.4 | MIT |
| CodeMirror (cdnjs path 6.65.7; content is CodeMirror 5.65.x) | — | MIT |
| Bootstrap CSS | 4.5.2 | MIT |
| SweetAlert2 | 11.10.5 | MIT |
| Google Fonts (maintenance page) | — | the font's own license (OFL / Apache) |

## 6. Separate programs (not linked into the application)

| Program | License | Note |
|---|---|---|
| FreeRADIUS 3.2.8 (container image) | GPL-2.0 | Configuration files in `install/freeradius/` are this project's. Delivering the image to an ISP is distribution of FreeRADIUS: provide its source / offer. |
| MariaDB 10.11 server | GPL-2.0 | separate program |
| MySQL 8.0 server | GPL-2.0 (+ FOSS exception) or commercial | separate program |
| MikroTik RouterOS / CHR | proprietary (MikroTik license) | Used only in the isolated lab under its free CHR license level. Production NAS devices are the ISP's own licensed routers. |
| PHP 8.2 | PHP License 3.01 | runtime |

## 7. Development and test only (not shipped)

`tests/composer.lock`: PHPUnit 11.5.56 and its sebastian/*, phar-io/*, theseer/*, nikic/php-parser components (BSD-3-Clause), plus myclabs/deep-copy and staabm/side-effects-detector (MIT). Also: Playwright and axe-core on the QA host only.

## 8. Release actions
1. Ship this file, `LICENSE`, and every third-party license file found in `system/vendor/*/` with every release artifact.
2. Add the missing notices: Parsedown (MIT) and md5.js (BSD).
3. Remove `system/vendor/yosiazwan/php-facedetection/lena512color.jpg` from release artifacts (test data, third-party image).
4. **LEGAL REVIEW** before the first distribution to an ISP:
   - the GPL version question (§1);
   - Smarty LGPL-3.0 (§2);
   - the delivery model (hosted service or software delivered to the ISP).
