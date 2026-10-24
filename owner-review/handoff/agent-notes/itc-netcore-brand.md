---
name: itc-netcore-brand
description: "Product renamed to ITC NetCore (brand ITC, V1.0) on 2026-10-18; candidate 6abba9fc supersedes 9da2015e; Brand class, rules for what is never renamed, open owner/legal points"
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-18T20:59:00.209Z
---

Owner decision 2026-10-18: PRODUCT_NAME_APPROVED = ITC NetCore, BRAND = ITC, TAGLINE = ISP Management & Billing Platform, VERSION = V1.0.
Applied in local commit 6abba9fc on wp9/red-team-rc (nothing pushed). It is the new product candidate, superseding 9da2015e, so TA-01..10 must use it.
Release state is unchanged: RELEASE_BLOCKED_WITH_EVIDENCE.

Gates on that tree:
- harness MariaDB 1569/77038 and MySQL 1569/77040, 0 failures (125 incomplete as before);
- whole tests/Unit 424;
- PWA 32/32;
- dev/pwa-lab/brand_visual.py clean in both phases.

Report: owner-review/BRANDING/ITC_NETCORE_BRANDING_REPORT.md. Rules: docs/BRAND_IDENTITY.md.

**Why:** the visible identity must come from one place, and the original PHPNuxBill credit and LICENSE must stay intact while LIC-1 is open.

**How to apply:**
- The visible name comes only from Reseller\Ui\Brand ($_brand, assigned in boot.php right after new Smarty).
- A CompanyName that is empty or the stock 'PHPNuxBill' shows "ITC NetCore". Invoices and messages print the raw setting.
- Never rename internal ids: DB, tables, routes (logs/phpnuxbill, community), FreeRADIUS phpnuxbill site/dictionary, nuxr-* namespaces.
- install/phpnuxbill.sql must stay byte-identical: the migration variant hash lives in variants.php.
- Pwa::VERSION must equal the nuxr-sw.js VERSION (now wp7.2). Bump both when the icons change. Icons come from `make_icons.py --text ITC`.
- `--testsuite unit` EXCLUDES the Wp2-Wp7 unit folders. Run `tests/Unit` as a directory, or the full harness; run 1 caught 2 failures only that way.
- Template guard: every `{$var}` must use `|escape`.
- ShellNavigationTest treats `route#fragment` links as routes, so never put a fragment in shell links.
- The previous session's scratchpad tools (commit.sh) are gone. Lint with `docker compose run --rm --no-deps -T php82 -l FILE` (entrypoint is php). axe-core is fetched from jsdelivr into the scratchpad.

Open owner points B-1..B-8: final logo, colour, upstream donation boxes, Palestine banner, phpnuxbill_* download names, default artwork, GitHub text, CompanyName before go-live.
Open counsel points: Q1 (the upstream README says "GPL v2 or later"), Q12 name clearance, Q13 authorship line, Q14 Terms/Privacy templates.

Related: [[v1-quota-renewal-state]], [[owner-stage-workflow]].

**Round 2 (2026-10-18), commit fe2d2fbc = current product candidate.**
- The quota card now uses decimal units (Format::bytes).
- 486 Arabic entries were added; `ArabicCatalogueTest` now fails on any `Lang::T` literal without an Arabic entry.
- Lang::T sanitises keys BYTE-wise: a non-ASCII character becomes several `_`. Generate keys from the utf-8 bytes.
- Gates: harness 1574 per engine; the only failure was `Subscriber360Test`, which had pinned the binary units, and it was updated.
- The product guide is the private Artifact https://claude.ai/artifact/8JQ8xt6D5y8cc1V5XjWPDy (23 chapters, 111 screenshots), plus `/root/Downloads/ITC-NetCore-Guide.pdf`.
- The owner asked that the guide's own text carry no PHPNuxBill / AdminLTE credit and no test-environment note. The product keeps its credits.
- The guide's demo data is rebuilt in the lab with `scratchpad/manual/demo_prep.sh` + `demo_flows.py`; screenshots come from `shots.py` with a JSON spec, and the page from `gen.py`.
