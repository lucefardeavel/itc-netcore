# WP6C Owner Review Decision and Owner Decisions

Recorded 2026-10-05 from the owner's explicit decisions. The verdict was "WP6C OWNER VERDICT": status **ACCEPTED_WITH_NOTES**, accepted runtime implementation `44505d59`, accepted evidence / WP6C branch HEAD `757d8de9`.

The owner instructed:
- documentation / integration closeout only;
- fast-forward `feat/reseller-system-v1` (`--ff-only`) to the accepted evidence HEAD and push it;
- leave `wp6c/mobile-adaptive-ux` permanently at `757d8de9`;
- change nothing in `main`;
- run no further test suite, route matrix, visual QA, migration or deployment.

WP7 is not started.

This file records that verdict, the WP6C owner decisions given during planning and review, and the accepted notes. Technical detail is in `docs/WP6C_ADAPTIVE_UX_MODEL.md` and `owner-review/WP6C/REPORT.md` (sections A–J are the candidate report; section K is fix round 1).

## WP6C acceptance

| Item | Value |
|---|---|
| Owner status | **ACCEPTED_WITH_NOTES** |
| Accepted runtime implementation | `44505d59cedeaa3cccd492f623bb0473a96625d4` |
| Accepted evidence / branch HEAD | `757d8de969e98787a270facb956a4c543fec7eaa`. Branch `wp6c/mobile-adaptive-ux` stays frozen there. |
| Base | `1148559d2954b19c4db42bc7ffc23cbb0af1f3a5` (WP6B acceptance recorded) |
| `feat/reseller-system-v1` | fast-forwarded (`--ff-only`) to `757d8de9`, plus this docs-only acceptance metadata commit. No squash, rebase, cherry-pick or rewrite. |
| `main` | `500d3a2d15003efeed6e0aad639c13d08101c3b1`, unchanged |
| Contracts | No schema or migration change, and `install/*` unchanged. RouteMap, capabilities, DataScope authority, API authority, Finance, Activation, Security, Domain and Backoffice unchanged. The only legacy-controller runtime changes are presentation metadata (`settings.php`, `plan.php`, `reports.php`) and the DB-status PDO fix (Amendment H). |

## Owner decisions applied in WP6C
From "WP6C OWNER REVIEW — PLAN ACCEPTED WITH DECISIONS / IMPLEMENTATION GO", recorded in `owner-review/WP6C/PLAN.md` §24 (the full binding text).

| Decision | Content |
|---|---|
| OD-1 | **Option A, Adaptive Layer** for legacy admin / configuration pages. There is no full redesign. The required outcomes:<br>• no unintended phone / tablet overflow;<br>• usable Arabic RTL;<br>• touch-friendly, labelled, accessible forms;<br>• single-column phone forms;<br>• adaptive tables / cards;<br>• usable filters and sheets;<br>• a sticky Save where it materially helps;<br>• no hover-only required interaction;<br>• nothing hidden behind the bottom navigation;<br>• no desktop regression.<br>Daily-use pages get the deeper conversion. No brand redesign. |
| OD-2 | **Option A, pricing.** One row-level Edit. On a phone it opens a full-screen adaptive editing workflow; on desktop, a structured side panel or editing surface. `PricingService` semantics, `PricingReadModel` redaction and capability checks are preserved. There is no pricing logic in templates or JS. |
| OD-3 | **Customer portal out of scope.** Staff application only. Any equivalent hardening later is a separate owner-scoped package. |
| A | Permission / UI mismatches: visibility comes from the central permission / navigation machinery, not hard-coded role names. The backend stays authoritative, and both layers must agree. |
| B | Back / Up: the registry lives in the UI layer and does not repurpose the RouteMap. Up never reveals an inaccessible parent. Browser Back is not the only mechanism. |
| C | 403 / 404 inside the shell: navigation is kept, the state is explained, no unauthorized object details are shown, and a recovery path is offered. There are no fake success or empty states. |
| D | 768 px is a blocker. It is fixed by component-width / container-query adaptation, not another global breakpoint. |
| E | Safe areas: `viewport-fit=cover`, verified against the insets, with no blanket padding. |
| F | Keyboard: behaviour is driven by focus / interaction, with no user-agent or device logic. At least one long form was tested with the keyboard open. |
| G | Hover-only reveals get accessible touch / keyboard equivalents. No data is newly exposed, and server visibility is not expanded. |
| H | Audit defects fixed in WP6C: the router-edit comment leak and the ODP search markup. The DB-status 500 was investigated first, with a stop rule for any Domain / Finance / Activation / Security change. The fix needed no such change (the PDO connection is used instead of the absent mysqli extension). |
| Cadence | Focused tests → ONE candidate validation → owner review → focused fixes → at most one final complete run. Real journeys are mandatory, and visual QA is curated. |

## Owner code-review fix round

| Round | Reviewed | Findings | Fix | Outcome |
|---|---|---|---|---|
| Candidate validation | `8ffbf54a` | 2 failures per engine in the accepted WP6B `FinanceCorrectionTest`: the footer confirmation sheet's markup collided with a no-Confirm assertion | `cd7b43c0`: the sheet is built on first use, so pages carry no markup | **The lazy confirmation-sheet fix was accepted** in the fix-round-1 review |
| Fix round 1 ("WP6C OWNER CODE REVIEW — FIX_REQUIRED ROUND 1") | `cd7b43c0`, evidence `9cb22105` | **BLOCKER 1:** target-aware UI permission parity. Route-level checks offered target links / actions that the authoritative policies refuse: Admin Delete on Sales, the Sales parent-Agent link, the non-viewable voucher generator. Also, `ActionParityTest` was partly circular. **BLOCKER 2:** duplicate PHPUnit suite registration, which produced warnings and a non-zero exit. | `105d567b`, `44505d59` (voucher checkbox names), evidence `757d8de9` | **Accepted** ("FIX ROUND 1 CODE ACCEPTED / FINISH EVIDENCE ONLY", then this verdict) |

**Fix round 1 summary (accepted).**
- **Scope:** `9cb22105..44505d59` is additive and limited to the expected 9 runtime / test files.
- **Staff rows** reuse the accepted target policies: the settings staff-visibility rule and `StaffService::canTarget`, plus the explicit V1 Agent no-delete rule of `StaffService::delete`.
- **Customer links:** voucher and activation-report customer links reuse `DataScope::findCustomerByUsername`.
- **Unprovable targets** (non-viewable or unprovable) are rendered as plain text or omitted. Backend authorization remains authoritative.
- **Pre-fix reproduction (accepted):** five defects, on both engines, against the unfixed runtime:
  - Admin Delete offered for Sales;
  - the Sales parent-Agent link leading to a denial;
  - an Admin link to a non-viewable SuperAdmin voucher generator;
  - an Agent link to an out-of-scope voucher customer;
  - a rendered-object-link crawl finding real denied links.
- **Parity coverage (accepted):** route-level parity remains; target-level GET object links are followed over HTTP; target-specific POST behaviour is covered by `TargetParityTest`; positive allowed cases are retained.
- **Suites:** `tests/phpunit.xml` excludes WP6C from the generic unit / integration suites and keeps the dedicated WP6C suites.

## Validation (accepted)

| Check | Result |
|---|---|
| Focused fix-round-1 validation | accepted on both database engines (`FIX_ROUND_1_TESTDOX.txt`) |
| Final complete run on `44505d59`, MariaDB | **PASS**, 903 tests / 66,053 assertions, zero PHPUnit warnings |
| Final complete run on `44505d59`, MySQL | **recorded as NOT GREEN**: 7 errors + 1 failure, all confined to `ServiceGateTest` |
| Isolated `ServiceGateTest`, both engines | 10 / 10, 264 assertions, exit 0, on MariaDB and MySQL |
| Variant registry | reproducible on both engines |
| Route matrix | 1727 requests / 1354 denials / 373 allowed, unchanged from WP6B and identical between the engines |
| Lint / manifest / static boundary / secret checks | accepted: PHP 8.2 lint 482 / 482; manifest 42 / 42; migration / install and boundary diffs empty; secret sweep clean |
| Targeted browser checks (fix round 1) | accepted: 9 checks, 67 rendered object links followed, 0 denied, 0 overflow, 0 axe serious / critical, 0 JS errors. Admin Delete only for Report; Sales only gets its permitted staff target; the generator is plain text; scoped customer links remain usable. |
| Candidate visual QA / journeys | 37 curated captures and 20 / 20 real journeys (`VISUAL_QA.md`, `JOURNEYS.md`) |

**MySQL `ServiceGateTest` classification (this verdict).**
- The owner independently inspected the saved MySQL failure block. All eight failing cases show the known stale AppServer / `php -S` worker signature: HTTP 404 responses for the expected admin / login / API application endpoints.
- The complete `ServiceGateTest` then passed cleanly in isolation on both engines.
- **The owner formally classifies this as the known test-harness infrastructure flake, not a WP6C product regression.**
- No further complete suite is to be run.

**Provenance clarification.**
- The evidence `REPORT.md` (section K7) was written before this final owner verdict. It used the phrase "owner decision" for the ServiceGate flake classification prematurely.
- At that time the owner had stated, in "FIX ROUND 1 CODE ACCEPTED / FINISH EVIDENCE ONLY", how the failures were to be classified in the evidence. The formal acceptance of that classification is **this closeout verdict**.
- History is not rewritten; the frozen evidence branch `757d8de9` is unchanged. A docs-only clarification note is added to `REPORT.md` on `feat` in the acceptance commit.

## Accepted notes / carry-forward (notes, not blockers)
- Physical-device testing was not performed; Chromium device emulation was used.
- The Arabic copy remains drafted for owner language review.
- The documented legacy / third-party accessibility and CDN residuals remain as recorded (`REPORT.md` §I):
  - Chart.js, CodeMirror, DataTables and map tiles;
  - the page-editor toolbars and `settings/devices`;
  - the raw description in the system-log table (escaped in the cards);
  - per-section Save on `settings/app`;
  - radio groups named per option.
- The deviations recorded in `REPORT.md` §I stand as recorded:
  - filters use a disclosure, not a bottom sheet;
  - confirmations on converted forms use the shell sheet instead of `window.confirm`.
- The customer portal remains outside WP6C scope.
- WP6A R-15 remains unchanged.
- **Incidental out-of-scope legacy findings, not changed:**
  - the bandwidth empty-rate TypeError (`bandwidth.php` add-post, reachable only with browser validation bypassed);
  - the missing default avatar `/user.default.jpg` (not shipped in the repository).
- **AVAIA:** start-time changes of AVAIA production containers seen during the validation runs are recorded only as shared-host observations. There is no evidence that WP6C commands targeted AVAIA, no OOM, and RestartCount remained 0. No AVAIA remediation is authorized here.

## Not authorized by this decision
- No deploy.
- No change to `main`.
- No further commit on `wp6c/mobile-adaptive-ux`.
- No PR or tag.
- No WP7 implementation.
