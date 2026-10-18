# V1 owner decision record - `V1_OWNER_DECISIONS_APPROVED` / `AUTONOMOUS_CLOSURE_AUTHORIZED` (2026-10-18)

This file records what the owner decided on 2026-10-18 and where each decision is implemented or evidenced. It records **decisions**, not
acceptances: the owner-controlled ledger (`docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md`) keeps WP9 and WP10 at `PENDING`;
the separate dated note for D1 is at the end of this file and in the ledger's "Recorded owner decisions" section.

| ID | Owner decision | Where it lands |
|---|---|---|
| **N1** | **Option A + G** for PPPoE GB quota: native per-direction NAS limits + accounting quota enforcer (A); any usage above the quota caused by detection or Disconnect delay is **not charged and not carried into a renewal / new period** (G). Raw accounting kept whole; actual network usage and billable usage are separate; no extra debt or wallet movement; no change to ledger history; every boundary case and finance case tested; never sold as a hard byte-exact cap. Provisional: `nuxr_quota_interim_seconds = 30`, `quota.php enforce --loop=55 --every=5`; the final setting is fixed by TA-05 / TA-09 results; if capacity shows it unsafe, improve within the approved contract or record a specific release blocker | `owner-review/WP10/N1_G_COMMERCIAL_QUOTA_CONTRACT.md`; `Reseller\Radius\QuotaUsage`; tests `V1QuotaUsageTest`, `Subscriber360Test`; capacity `owner-review/WP10/CAPACITY_AND_ACCOUNTING_LOAD.md` |
| **N2** | Period ownership by accounting arrival order approved; UTC + NTP mandatory; verify clock discipline (app / DB / FreeRADIUS / RouterOS), renewal boundaries, delayed accounting, Interim / Stop ordering, reconnect and session identity, duplicates and late arrival; TA-02 and TA-06 before the time contract is production-ready | `docs/TIME_CONTRACT.md`; `V1PeriodOwnershipTest` (11 tests); TA-02 / TA-06 stay `NOT_RUN` on a target |
| **D1** | WP9 accepted **developmentally with notes** on the present evidence; **not** production acceptance; documented separately; the register row stays `PENDING` while WP9 has open blockers | note below + ledger section |
| **D2** | A2-R: the defensive treatment was implemented and tested in the allowed scope; the SQL-exhaustion reproduction stays forbidden and the effect unverified experimentally; **not** closed absolutely; no production risk accepted on the owner's behalf; classify the residual risk, the defensive evidence and the production conditions that close the gate without the forbidden test | `owner-review/WP10/A2R_RESIDUAL_RISK_CLASSIFICATION.md` |
| **D4** | AV-1: monitoring, alerting and audited manual recovery per the current safe model; **no automatic release** of an unconfirmed PENDING claim | `docs/OPERATOR_RECOVERY_RUNBOOK.md` section 3; guard test `V1OwnerDecisionGuardsTest` |
| **D5** | Canonical time contract: UTC, NTP, period ownership; tests mandatory on the target | `docs/TIME_CONTRACT.md`; TA-02 / TA-06 |
| **D6** | NAS secret boundary as documented: least privilege, redaction, access restrictions, secret-rotation runbook; real NAS secrets are not changed without a separate authorization | `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md`; no NAS secret was touched |
| **D7** | OBS-1: survey of legacy data on an isolated, authorized copy only; prepare the collision report, remediation options and a backup / rollback plan; the engineer does not choose which customer keeps the key and does not change real subscriber data | `owner-review/WP10/OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md`; tool `system/reseller/identity_report.php` |
| **D8** | Keep original voucher codes in the financial records needed for audit; **mask** them in interfaces that do not need the full code; keep Renewal Option B | `Format::method` / `holder` / `maskCode` and the report / receipt / print templates; `W9RedTeamHttpTest` (D8), `VoucherMaskTest` |
| **D9** | Customer-UI RTL and contrast fixes inside the existing visual identity; assess jQuery 1.11.1; change it only if the need is proven, minimal, with visual regression and functional tests | `owner-review/WP10/JQUERY_ASSESSMENT.md`; `nuxr-customer-a11y.css`; `ClientSideSinkInventoryTest` |
| **D10** | Prepare licence files, legal obligations and a package for counsel; do **not** declare the legal review finished | `owner-review/WP10/LEGAL_REVIEW_PACKAGE.md`; `docs/THIRD_PARTY_LICENSES.md`; status stays `COUNSEL` |

Authorization boundaries restated (unchanged): no production deployment or migration, no real NAS change, no customer-data change, no live
secret rotation, no push / merge of a protected branch, no force push, no unapproved risk acceptance, no SQL-exhaustion reproduction.
Target-environment tests are never marked `PASS` unless run on the approved target (`docs/V1_TARGET_ACCEPTANCE.md`).

---

## D1 - dated note: WP9 development acceptance with notes (2026-10-18)

- **Decision (owner, 2026-10-18):** the engineering work of WP9 is accepted for **development**, with notes, on the evidence recorded in
  `owner-review/WP9/` and `owner-review/WP10/`. This is **not** a production acceptance and **not** a WP10 decision.
- **Register handling:** the ledger row `WP9` stays `PENDING`. The register is owner-controlled and WP9 still carries production blockers
  (OBS-1 existing data, A2-R unverified effect, target-environment tests, legal review). The decision is recorded as a separate note and
  does not change any row status.
- **Notes that travel with the decision:** (1) A2-R reproduction forbidden, effect unverified (D2); (2) OBS-1 existing-data resolution is the
  owner's (D7); (3) N1 overshoot is bounded, not byte-exact (N1 = A + G); (4) all `docs/V1_TARGET_ACCEPTANCE.md` rows are `NOT_RUN`;
  (5) licence review is open (D10).
- **What this note does not do:** it does not mark WP9 `ACCEPTED`, does not open WP10, does not authorize a deployment, and does not
  close any item of the WP8 / WP9 production-blocker register.
