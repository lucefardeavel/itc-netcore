# jQuery 1.11.1 security assessment and launch decision (D9, R-JQ-1)

Owner decision 2026-10-18: assess jQuery 1.11.1 for security and decide whether changing it is needed for launch; if the need is proven, make
the smallest safe update with visual-regression and functional testing and without redesigning the interfaces.

**Result: replacing jQuery is not required for launch; the exploitable paths that exist in this application were in the application's own
templates (not in jQuery) and have been fixed; the sink inventory is now pinned by a test so no new one can appear unreviewed.** A jQuery
upgrade stays a recommended hardening step (not a launch condition), with the cost stated below.

## 1. What ships

| Where | Version | Loaded by |
|---|---|---|
| `ui/ui/scripts/jquery.min.js` (+ `vendors.js`) | **1.11.1** | the staff shell (`admin/footer.tpl`) and the customer portal (`customer/footer.tpl`) |
| CDN, SRI-pinned | 3.6.0 | the coupon list and the bulk-message page only (`jquery-3.6.0.min.js`) |

## 2. Known issues of 1.11.1 and what each needs

| CVE | Class | Needs | Present here? |
|---|---|---|---|
| CVE-2015-9251 | a cross-domain `$.ajax` / `$.get` without `dataType` evaluates a `text/javascript` response | a cross-origin request made by jQuery without a data type | **No.** The only cross-origin call is `$.getJSON` to the upstream version file (`dataType: json`: never evaluated as script). Every other request is same-origin |
| CVE-2019-11358 | prototype pollution through `$.extend(true, {}, <attacker JSON>)` | a deep `extend` of attacker-controlled data | **No** call of `$.extend(true, ...)` exists in any template; the bundled plugins (DataTables, Select2, Summernote) are initialised with literal options |
| CVE-2020-11022 / CVE-2020-11023 | XSS through `htmlPrefilter` when untrusted HTML containing `<option>` / `<style>` / `<script>` forms goes to `.html()`, `.append()`, `.after()` ... | untrusted HTML reaching a jQuery HTML sink | **Was present in two places, both application bugs, both fixed** (section 3). Remaining sinks take static markup or same-origin server HTML |

## 3. Defects found by the audit and fixed (independent of the jQuery version)

| # | Where | Problem | Fix |
|---|---|---|---|
| 1 | `admin/dashboard.tpl`, `admin/community.tpl` | the remote `version.json` of the **upstream project** (third-party data fetched by the admin's browser) was written with `.html()` and into a SweetAlert **title** (which renders HTML) | `.text()` and a restricted token (`[0-9A-Za-z._-]`, 32 characters, otherwise "unknown") |
| 2 | `admin/message/bulk.tpl` | the bulk-message **history table** (DataTables cells are HTML) received customer names, phone numbers, message text and gateway statuses unescaped: a customer or Sales user could store markup that ran in the Agent's / Admin's browser (**stored XSS across privilege levels**; the XSS-1 crawl does not execute scripts or AJAX history) | every value escaped (`nuxrEsc`) before `row.add` |

## 4. Remaining HTML sinks (reviewed, pinned)

`ClientSideSinkInventoryTest` pins the count of `.html(<value>)` calls and `innerHTML =` assignments per template; a new one fails the test
until it is reviewed.

| Sinks | Input | Verdict |
|---|---|---|
| `validity_unit` option lists (hotspot / pppoe / vpn add / edit) | string literals built in the page | static |
| `#pool_name` (`pppoe/add.tpl`), customer login status (`active_internet_plan.tpl`), balance (`plan/deposit.tpl`), `api-get-text` cells (`admin/footer.tpl`, `customer/footer.tpl`) | HTML or text produced by this application's own `autoload` endpoints (pool / router names are entered by SuperAdmin, balances and badges are numbers) | same-origin, application-controlled |
| spinner markup (`customer/footer.tpl`), custom-field row templates (`customers/add.tpl`, `customers/edit.tpl`), invoice / voucher print copy (`innerHTML` of the page's own content) | page content already rendered (and escaped) by Smarty | not a new input |
| `admin/alert.tpl` countdown | the translated label | static |
| bulk-message status banner | literals and counters | static |

## 5. Why not upgrade now

- The only supported fixes are in 3.x (3.4.0 for CVE-2019-11358, 3.5.0 for CVE-2020-11022 / 11023). There is **no minimal 1.x update** that fixes
  them: 1.12.4 fixes only CVE-2015-9251, which is not reachable here.
- AdminLTE 2.4.18, Bootstrap 3.4.1 and the legacy plugins were written against jQuery 1.x/2.x; a jump to 3.x needs `jquery-migrate` and a full
  visual and functional regression of every staff and customer page (removed APIs, changed `.on`/`.ready` timing). The owner asked for the
  least intrusive change and no redesign.
- With the two application sinks fixed, no path was found where attacker-influenced data reaches a jQuery sink; the pinned inventory keeps it so.

## 6. If the owner wants the upgrade anyway (post-launch hardening)

Plan: jQuery 3.7.1 + jquery-migrate 3.4.0 (log mode) on the staging copy, UI audit (`dev/wp10/ui_audit.py`, 136 loads, three layouts), PWA suite
(32 tests), the bulk-message / coupon / dashboard flows by hand, then remove jquery-migrate. Cost: 1 - 2 days of regression; risk: legacy plugin
breakage. Not started; not authorized as a launch condition.

## 7. Evidence

`tests/Unit/Wp10/ClientSideSinkInventoryTest.php` (3 tests); the templates diff in the commit `feat(ui): D8 voucher-code masking, D9 sink fixes ...`;
UI audit and PWA suite on the final candidate (`evidence/release-candidate-v2/`).
