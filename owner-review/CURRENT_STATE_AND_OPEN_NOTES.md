# ITC NetCore: current state and open notes (resume point)

Last updated: 2026-10-24. Read this first when resuming. It lists what is done, what is open, and where each record lives, so work can continue without guessing. It is an index: the linked documents are authoritative. The owner-controlled ledger (`docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md`) wins over this file.

## ملخص بالعربية

- **المنتج:** ITC NetCore V1.0 (العلامة ITC). الكود الأحدث على الفرع `wp9/red-team-rc`، والمرشّح الحالي هو آخر commit فيه.
- **حالة الإصدار:** `RELEASE_BLOCKED_WITH_EVIDENCE`. لا تشغيل إنتاجي قبل:
  - اختبارات القبول TA-01 إلى TA-10 على بيئة staging معتمدة، وكلها `NOT_RUN` حالياً؛
  - قرارات المالك المفتوحة؛
  - المراجعة القانونية LIC-1.
- **سجل القبول:** WP0 إلى WP8 مقبولة، وWP9 وWP10 بحالة `PENDING`. عمل WP9 الهندسي مقبول للتطوير مع ملاحظات (قرار D1)، دون تغيير صف السجل.
- **الفروع:** بقرار المالك في 2026-10-24 صار `feat/reseller-system-v1` مطابقاً لآخر نسخة (تقديم fast-forward من `704031c6`). `main` (`500d3a2d`) لم يتغير، وأي تغيير عليه قرار منفصل. تقديم `feat` ليس قبولاً لـWP9 أو WP10 ولا تصريحاً بالتشغيل.
- **المفتوح:** كل البنود في الأقسام 4 إلى 8 أدناه.

## 1. Where the code is

| Item | Value |
|---|---|
| Repository | `lucefardeavel/phpnuxbill-reseller` (private) |
| Latest code (the version under development) | branch **`wp9/red-team-rc`** (pushed to GitHub 2026-10-24 together with every earlier local commit; the HEAD it carries is the current product candidate) |
| Product candidate history | `9da2015e` (V1 round 2) → `6abba9fc` (ITC NetCore identity) → `fe2d2fbc` (decimal quota units, complete Arabic interface). The later commits change documents, evidence and tooling only |
| Canonical integration branch | `feat/reseller-system-v1` **fast-forwarded to the latest version** by owner decision 2026-10-24 ("خلي feat يطابق آخر نسخة"; was `704031c6`, WP8 metadata). This is a branch decision only: it is **not** a WP9 / WP10 acceptance, it does not change the ledger rows (still `PENDING`), and it authorizes no deploy |
| `main` | `500d3a2d` (upstream baseline line); untouched |
| Other branches | frozen WP branches `wp0` … `wp8`, `hotfix/pace-xhr-csrf`; `docs/owner-architecture-lock-2026-10-09` (its content is already contained in `wp9/red-team-rc`, verified file by file) |
| Working tree | `/srv/phpnuxbill/dev` (dev host). Scratch worktrees `/srv/phpnuxbill/wp9-prefix`, `/srv/phpnuxbill/wp9-snap`, `/srv/phpnuxbill/docs-sync` hold nothing that is not in the branch (an early test draft superseded by the committed test; the A/B load logs, now under `owner-review/WP10/evidence/final-v2/ab-deadlock-investigation/`) |

Deliberately **not** in Git (rules: no secrets, images, packet captures, customer data):
- raw InnoDB deadlock dumps (`/srv/phpnuxbill/dlstudy-*`; summaries and tables are in `owner-review/WP10/evidence/odl/`);
- the CHR lab state and its credentials (`/srv/phpnuxbill/wp8state`, `wp8lab`);
- disposable lab application copies (`pwalab`, `uxlab`);
- the product-guide images and PDFs (build output of `dev/guide/`).

## 2. How to resume

1. `git fetch && git checkout wp9/red-team-rc`; read this file, then `CLAUDE.md`, the ledger, `owner-review/WP10/HANDOFF_V1_CONTINUOUS.md` (checkpoint, rounds 2 – 4b), `owner-review/WP10/DECISIONS.md` and the last rows of `owner-review/WP9/AUTONOMOUS_PROGRESS.md`.
2. Rules that always apply:
   - the owner stage workflow (plan → approval → execution → review → acceptance);
   - no push / merge to protected branches, no deploy, no production data, no live NAS, no secret rotation without explicit owner authorization;
   - never run the A2-R SQL-exhaustion reproduction (`a2r_sql_gate.py`, `a2r_gate.py pool|fault`);
   - only the `nuxbill-reseller-test` compose project on the shared host (never touch AVAIA);
   - never mark a ledger row accepted.
3. Test tooling:

| Tool | What it runs |
|---|---|
| `dev/test/run-tests.sh` | full dual-engine harness, about 65 – 75 min |
| `dev/wp9/t1a_run.sh up\|test\|down` and `dev/wp9/regress.sh "mariadb mysql" <paths>` | focused tests on both engines |
| `dev/pwa-lab/lab.sh up\|sync\|down` | the browser lab |
| `pwa_suite.py` | PWA checks (32) |
| `brand_visual.py` | brand visual check |
| `dev/wp8/` + `scratchpad/chr_*` | CHR gates (lab only) |
| `dev/guide/build.sh` | product guide |

4. Never run the full harness while the CHR lab is up. Never `pkill -f` a pattern contained in your own command.

## 3. Last verification (on the current candidate tree)

| Gate | Result | Evidence |
|---|---|---|
| Whole `tests/Unit` | 429 tests, 0 failures | `owner-review/BRANDING/evidence/round2/unit-all.txt` |
| Full harness MariaDB 10.11 / MySQL 8.0 | 1574 tests per engine; the only failure, `Subscriber360Test`, pinned the old binary units and was updated → 5/5 on both engines; 125 incomplete = the real-NAS lab classes (run on the CHR) | `owner-review/BRANDING/evidence/round2/` |
| PWA browser suite | 32 / 32 | same |
| Brand visual check (Arabic / English, desktop / phone) | clean | same |
| CHR gates (last run on `9da2015e`; no RADIUS / quota / activation code changed since) | `wp8-security-gates` 10 / 216, integration 122 / 2383 | `owner-review/WP10/evidence/final-v2/chr/` |
| Drill, load, capacity, O-DL study | as recorded for `9da2015e` | `owner-review/WP10/evidence/final-v2/`, `evidence/odl/`, `CAPACITY_AND_ACCOUNTING_LOAD.md` |

## 4. Release blockers and target acceptance

| Item | State | Record |
|---|---|---|
| TA-01 … TA-10 on an approved isolated staging | all **`NOT_RUN`**; sheets, criteria, evaluators and generator prepared | `docs/V1_TARGET_ACCEPTANCE.md` (section 2A), `dev/staging/` |
| Staging authorization (P1 – P5, R1 – R10, boundaries, window) | **waiting for the owner** | `owner-review/WP10/TARGET_AUTHORIZATION_REQUEST.md`, `docs/STAGING_DEPLOYMENT_PACKAGE.md` |
| Deploy to production | not authorized; separate decision after the gates | — |

## 5. Open owner decisions

| ID | Decision | Record |
|---|---|---|
| O-DL | Record the MariaDB concurrency refusal (existing limit, fail-safe, about 1 in 400 under a 12-way single-wallet stampede) as an accepted production residual, or authorize variant A (jittered backoff, same retry bound). No automatic cancellation of held operations (owner direction) | `owner-review/WP10/O_DL_CLASSIFICATION.md` |
| A2-R | Option **A** (adopt, launch under C1 – C6), **B** (adopt after a staged pilot, C7 second form) or **C** (do not adopt: stays an open P0). The SQL-exhaustion effect is unverified, and its reproduction is forbidden | `owner-review/WP10/A2R_OWNER_DECISION_EVIDENCE.md`, `A2R_RESIDUAL_RISK_CLASSIFICATION.md` |
| OBS-1 | Separate authorization for the read-only identity survey on an isolated copy of real data; the owner then decides which holder keeps each colliding key | `docs/OBS1_SURVEY_RUNBOOK.md`, `owner-review/WP10/OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md` |
| Ledger | WP9 and WP10 rows stay `PENDING` until the owner records otherwise (D1 note recorded separately) | the ledger |
| `main` | whether and when to advance `main` (`feat` was advanced on 2026-10-24) | owner |
| Brand B-1 … B-8 | final logo; brand colour; the original project's donation / support boxes on the About page; the "Stand with Palestine" banner; `phpnuxbill_*` download file names; default artwork (`favicon.ico`, `logo.png`, `system/uploads/*.default.png`); GitHub description text (suggested in `docs/BRAND_IDENTITY.md` §6); set `CompanyName` before go-live | `owner-review/BRANDING/ITC_NETCORE_BRANDING_REPORT.md` §6 |
| Guide | the release-status sentence in the guide's last chapter stays unless the owner removes it | `dev/guide/gen.py` |

## 6. Legal (LIC-1: OPEN, with counsel; nothing here is legal advice)

The questions are Q1 – Q14 in `owner-review/WP10/LEGAL_REVIEW_PACKAGE.md`. The ones the owner asked to keep visible:
- **Q1:** GPL-2.0-only or -or-later. New fact: the original README says "version 2 or later" (`docs/UPSTREAM_PHPNUXBILL_README.md`).
- **Q2:** Smarty LGPL-3.0 in this combination.
- **Q3:** delivery model (operate vs deliver).
- **Q6:** the `lena512color.jpg` photograph.
- **Q8 / Q12:** names. "ITC NetCore" / "ITC" name clearance has not been searched.
- **Q13:** the authorship line for ITC's modifications.
- **Q14:** the Terms / Privacy templates still name PHPNuxBill.

Four vendored packages lack licence files (to be fetched from upstream). `LICENSE` is byte-unchanged (guard `BrandIdentityTest`).

## 7. Technical residuals and carry-forwards (open)

| Item | State | Record |
|---|---|---|
| WP8 production blocker register (31 rows), Architecture-Lock release gates | open | `owner-review/WP8/REPORT.md` §5 |
| R-HF-CARRY-01: legacy autoload router / plan metadata authorization exposure (MEDIUM, pre-existing) | OPEN | `owner-review/HOTFIX_PACE_XHR_CSRF/OWNER_DECISIONS.md` |
| N1 (quota overshoot) | bounded, not byte-exact. Normal operation: `min(R, r × (I+P+c))`. A failed enforcer can allow up to the whole remaining quota again on PPPoE. Never advertised as a hard cap; the overshoot is not charged or carried over (Option G) | `owner-review/WP10/N1_G_COMMERCIAL_QUOTA_CONTRACT.md` |
| N2 period ownership | needs NTP on every host and NAS (TA-02) | `docs/TIME_CONTRACT.md` |
| jQuery 1.11.1 | retained; no known exploit path in use | `owner-review/WP10/JQUERY_ASSESSMENT.md` |
| Harness flakes (environment, not product) | `CliOnlyTest` connection counter; `AppServer` stale `php -S` workers | `owner-review/WP*/` reports, ledger notes |
| Legacy interface texts stored as data (default widget titles, seeded settings) | stay as entered (data, not interface strings) | — |
| Fixed in round 4b | quota card units (50 GB showed as 46.6 GB); 486 missing Arabic strings (guard `ArabicCatalogueTest`); translated titles / labels; activation notices without raw state codes | `owner-review/BRANDING/ITC_NETCORE_BRANDING_REPORT.md` §9 |

## 8. Product identity and guide

- **Name:** ITC NetCore, brand ITC, tagline "ISP Management & Billing Platform", V1.0. Single source `system/autoload/Reseller/Ui/Brand.php`. Rules: `docs/BRAND_IDENTITY.md`.
- **Internal names never renamed:** database, tables, routes, APIs, FreeRADIUS site / dictionary, paths, `install/phpnuxbill.sql` (its hash identifies the schema variant).
- **Product guide:** 23 chapters, 111 screenshots, Arabic. Rebuilt by `dev/guide/build.sh` (isolated lab, synthetic data). Published as a private Artifact (https://claude.ai/artifact/8JQ8xt6D5y8cc1V5XjWPDy) and as two PDFs (full and light). The images and PDFs are not committed.

## 9. Record index

| Area | Location |
|---|---|
| Architecture decisions (owner lock 2026-10-09, verbatim + SHA-256) | `docs/architecture/owner-lock-2026-10-09/` |
| Specification and rules (manifest-pinned pack) | `docs/claude-development-pack/` |
| Domain / design models | `docs/WP1_SCHEMA_CONTRACT.md` … `docs/WP8_INTEGRATION_MODEL.md`, `docs/TIME_CONTRACT.md` |
| Operations | `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md`, `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md`, `docs/BACKUP_RESTORE_RUNBOOK.md`, `docs/OPERATOR_RECOVERY_RUNBOOK.md`, `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` |
| Per-WP plans, reports, owner decisions, evidence | `owner-review/WP0` … `owner-review/WP10`, `owner-review/HOTFIX_PACE_XHR_CSRF`, `owner-review/BRANDING` |
| WP8 (real FreeRADIUS + MikroTik POC) | `owner-review/WP8/` (PLAN, REPORT incl. blocker register, OWNER_DECISIONS, evidence) |
| WP9 (red team / release candidate) | `owner-review/WP9/` (PLAN, REPORT, AUTONOMOUS_PROGRESS, WP8_WP9_UNIFIED_CLOSURE_AND_RELEASE_ROADMAP, evidence) |
| WP10 (V1 completion, acceptance packages, decisions, handoff) | `owner-review/WP10/` (HANDOFF_V1_CONTINUOUS, DECISIONS, V1_OWNER_DECISION_RECORD, SAAS_RADIUS_V1_* packages, TARGET_AUTHORIZATION_REQUEST, evidence) |
| Test plans and results | the WP PLAN / REPORT files; `docs/V1_TARGET_ACCEPTANCE.md`; `tests/` (unit, integration, HTTP, regression; real-NAS classes are `tests/Integration/Wp8`, `Wp10/V1*Lab*`) |
| Bugs found and fixed (with the failing run kept) | the REPORT files and the `evidence/` folders; `owner-review/BRANDING/evidence/` (round 1 and round 2 failures) |
| Product identity | `owner-review/BRANDING/ITC_NETCORE_BRANDING_REPORT.md`, `docs/BRAND_IDENTITY.md`, `NOTICE`, `README.md` |
| Agent working notes (snapshot) | `owner-review/handoff/agent-notes/` (index `MEMORY_INDEX.md`) |
