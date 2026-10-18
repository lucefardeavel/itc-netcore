# Legal review package for counsel (D10, LIC-1)

Owner decision 2026-10-18: prepare the licence files, the legal obligations and a package for the legal adviser. **The legal review is not
finished and is not declared finished by this file.** Status: **`COUNSEL` (open)**. Nothing here is legal advice.

## 1. What counsel receives

| Item | Location |
|---|---|
| Inventory of every third-party component with its licence, version and the evidence | `docs/THIRD_PARTY_LICENSES.md` (kept in step with `system/composer.lock` by `LicenseInventoryTest`) |
| The application licence text | `LICENSE` (GNU GPL version 2) |
| The questions that need a legal answer | section 3 below |
| The release-artifact checklist (what must be shipped / removed before any delivery) | section 4 below |
| The source of every component | the repository; vendored libraries under `system/vendor/`, `system/autoload/PEAR2/`, `system/autoload/mail/`, `ui/ui/` |

## 2. Facts established (verifiable in the repository)

| Fact | Evidence |
|---|---|
| The product is a fork of PHPNuxBill, itself GPL-2.0. `LICENSE` is the GPLv2 text; no source file states "or any later version" | `LICENSE`, file headers |
| The server-side dependency set is nine Composer packages: 5 MIT, 1 LGPL-3.0 (Smarty), 2 GPL-2.0 (mPDF `GPL-2.0-only`, php-facedetection `GPL-2.0`) and one MIT without a licence file in its package | `system/composer.lock` |
| Packages that ship **no licence file** in `system/vendor/`: `mpdf/mpdf`, `mpdf/psr-log-aware-trait`, `myclabs/deep-copy`, `setasign/fpdi` | directory listing of `system/vendor/*/*/` |
| `yosiazwan/php-facedetection` contains `lena512color.jpg`, a third-party test photograph | `system/vendor/yosiazwan/php-facedetection/` |
| PEAR2 Net_RouterOS (LGPL-2.1) and PHPMailer (LGPL-2.1) are vendored outside Composer; Parsedown (MIT) refers to a LICENSE file that is not included | `system/autoload/` |
| Browser assets: jQuery 1.11.1, Bootstrap 3.4.1, AdminLTE 2.4.18, SweetAlert2, PACE, Select2, nicEdit, Summernote, md5.js, Font Awesome, Ionicons - MIT/BSD/OFL; CDN assets are loaded by the visitor's browser with SRI, not distributed by us | `docs/THIRD_PARTY_LICENSES.md` sections 4 - 5 |
| FreeRADIUS (GPL-2.0), MariaDB (GPL-2.0), MySQL (GPL-2.0 + FOSS exception, or commercial), PHP (PHP licence) are separate programs, not linked into the application | section 6 of the same file |
| MikroTik RouterOS CHR is proprietary and was used only in the isolated lab under the free CHR licence level | same |
| No AGPL component was found | inventory |
| The original project's README (kept verbatim in `docs/UPSTREAM_PHPNUXBILL_README.md`) says "GNU General Public License version 2 **or later**"; `LICENSE` and the source headers carry no version statement (relevant to Q1; found during the 2026-10-18 rebrand) | `docs/UPSTREAM_PHPNUXBILL_README.md` section "License" |
| Since 2026-10-18 the visible product name is **ITC NetCore** (brand **ITC**, owner decision). Internal identifiers keep the PHPNuxBill names; footers, About page, installer, README and `NOTICE` credit PHPNuxBill (iBNuX and contributors, GNU GPL v2); `LICENSE` is byte-unchanged (guard `BrandIdentityTest`) | `docs/BRAND_IDENTITY.md`, `NOTICE` |
| `pages_template/Terms_and_Conditions.html` and `Privacy_Policy.html` (templates an operator publishes to its customers) name "PHPNuxBill" as the contracting party and owner of the website; they were **not** changed by the rebrand | the two files |

## 3. Questions for counsel (each needs a written answer)

| # | Question | Why it matters |
|---|---|---|
| Q1 | Is the application licensed **GPL-2.0-only** or **GPL-2.0-or-later**? (No version statement exists; GPLv2 section 9 then allows any version) | decides whether the LGPL-3.0 Smarty can be combined (LGPLv3 + GPLv2-only is a known incompatibility for a combined work) |
| Q2 | Is Smarty 4.5.3 (LGPL-3.0) acceptable in this combination, or must it be replaced / the combination re-structured? | the whole templating layer depends on it |
| Q3 | Delivery model: (A) the vendor **operates** the SaaS for ISPs, (B) the software is **delivered** to ISPs to run on their servers. Which obligations apply under each (GPLv2 has no network clause)? | (B) is distribution: source / offer, notices, GPL licensing of the whole derivative work |
| Q4 | May proprietary add-ons (closed-source modules) be loaded by the application? | they would be part of the derivative work |
| Q5 | mPDF `GPL-2.0-only` and php-facedetection `GPL-2.0` - any conflict with the chosen outbound licence? | outbound licence choice |
| Q6 | The photograph `lena512color.jpg` in a vendored package: remove from the release artefact? | third-party copyright / model rights |
| Q7 | Delivering the FreeRADIUS container image or configuration to an ISP: what must accompany it (source offer, notices)? | FreeRADIUS is GPL-2.0 |
| Q8 | Trademarks and naming: "PHPNuxBill", "MikroTik", "RouterOS" in the product and documentation | brand use |
| Q9 | MikroTik licence terms for using CHR in a test lab and for documentation of RouterOS behaviour | lab evidence |
| Q10 | Fonts and icon sets (SIL OFL 1.1) and the CDN assets: are the notices sufficient? | notice duties |
| Q11 | Data-protection and telecom-regulatory obligations for subscriber data and accounting records (retention of `radacct`, vouchers, ledger) in the target jurisdictions | outside software licensing, but part of launch |
| Q12 | Name clearance for **"ITC NetCore"** and **"ITC"** in the target markets (classes for software and telecom services). "ITC" is used by other companies as a mark; no search was made by the engineering side | the owner's chosen name must not infringe an earlier mark; no registration or clearance is claimed anywhere in the product |
| Q13 | How to state authorship of the modifications: which entity holds the copyright in ITC's changes (employment / contractor terms), the wording of a copyright line for ITC, and whether GPLv2 section 2(a) needs per-file change notices beyond the Git history and `NOTICE` | the product only says "developed and maintained by ITC"; no copyright line for ITC was added |
| Q14 | The Terms and Conditions / Privacy Policy templates name PHPNuxBill as the party: replace them with operator-neutral templates (the ISP is the party to its customers), with ITC text, or remove them from the release artifact | customer-facing legal text; not changed without counsel |

## 4. Release-artifact checklist (the engineering side prepares; counsel confirms)

| # | Item | State |
|---|---|---|
| 1 | Ship `LICENSE`, `docs/THIRD_PARTY_LICENSES.md` and every licence file found in `system/vendor/*/` with every artifact | prepared (list in the inventory) |
| 2 | Obtain and add the **missing licence files** for `mpdf/mpdf`, `mpdf/psr-log-aware-trait`, `myclabs/deep-copy`, `setasign/fpdi`, and the MIT notice of Parsedown, **from the upstream projects** (the copyright holders' names must come from upstream; they are not invented here) | **open** (needs network access to upstream and counsel's confirmation) |
| 3 | Remove `lena512color.jpg` from the release artifact (or obtain clearance, Q6) | **open** - decision after Q6 |
| 4 | Written source offer / source package for GPL components when the delivery model is (B) | **open** - depends on Q3 |
| 5 | State the outbound licence version in the repository (`LICENSE` header / `composer.json`) | **open** - depends on Q1 |
| 6 | Third-party notices page in the product (About / footer) if counsel requires | **partly prepared** (2026-10-18): the About page names the product, ITC, the original project and its contributors, the licence and the notice files; footers credit PHPNuxBill and AdminLTE; counsel decides whether this is sufficient |

### 4.1 Where the four missing licence files come from (the maintainers fetch them; nothing is invented here)

| Package | Upstream project to take the licence file and copyright notice from | Composer-declared licence |
|---|---|---|
| `mpdf/mpdf` v8.1.6 | the mPDF project repository (`mpdf/mpdf`, tag `v8.1.6`) | GPL-2.0-only |
| `mpdf/psr-log-aware-trait` v2.0.0 | the mPDF project (`mpdf/psr-log-aware-trait`, tag `v2.0.0`) | MIT |
| `myclabs/deep-copy` 1.11.1 | the DeepCopy project (`myclabs/DeepCopy`, tag `1.11.1`) | MIT |
| `setasign/fpdi` v2.3.7 | the FPDI project (`Setasign/FPDI`, tag `v2.3.7`) | MIT |

This session has no network access to those projects; the files are **not** produced or paraphrased by the engineering side.

### 4.2 Tooling for the artifact

`python3 dev/wp10/notices_bundle.py build/THIRD_PARTY_NOTICES.txt` collects every licence text that exists in the packages plus `LICENSE` and the Summernote notice, and exits 1
while any package is without a licence file (today: the four above). Re-run it after the missing files are added; the exit code 0 closes checklist item 2 (counsel still confirms the content).

## 4.3 Counsel answer sheet (to be completed by counsel; the engineering side leaves it empty)

| Q | Answer | Conditions / follow-up | Reviewer / date |
|---|---|---|---|
| Q1 GPL-2.0-only or or-later | | | |
| Q2 Smarty LGPL-3.0 | | | |
| Q3 delivery model A / B | | | |
| Q4 proprietary add-ons | | | |
| Q5 mPDF / php-facedetection | | | |
| Q6 `lena512color.jpg` | | | |
| Q7 FreeRADIUS image / configuration | | | |
| Q8 trademarks | | | |
| Q9 MikroTik CHR terms | | | |
| Q10 fonts / CDN assets | | | |
| Q11 data protection and telecom regulation | | | |
| Q12 name clearance "ITC NetCore" / "ITC" | | | |
| Q13 authorship statement for ITC's modifications, GPLv2 2(a) notices | | | |
| Q14 Terms / Privacy templates naming PHPNuxBill | | | |

## 5. Mechanical guard

`tests/Unit/Wp10/LicenseInventoryTest.php` fails when a package of `system/composer.lock` is missing from `docs/THIRD_PARTY_LICENSES.md`, has another
version or another licence there, or when a package without a licence file is no longer listed in the missing-files table - so the inventory cannot drift
silently. It states a fact check, not a legal conclusion.

## 6. Status

**LIC-1: OPEN - `COUNSEL`.** The review needs a qualified person. The product must not be delivered to an ISP (model B) before Q1 - Q7 are answered. Operating it as a
vendor-run service (model A) still needs Q1 / Q2 for the outbound licence statement.
