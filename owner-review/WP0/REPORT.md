# Owner Review — WP0 Repository Reconnaissance & Baseline Verification

Final state: `STOPPED_WAITING_OWNER_REVIEW`
WP status: `IMPLEMENTED_NOT_ACCEPTED`. This report does not self-accept; acceptance is recorded only by the owner in `review/ACCEPTANCE_LEDGER.md`.

**Detailed evidence**
- [`docs/BASELINE_VERIFICATION.md`](../../docs/BASELINE_VERIFICATION.md): identity, runtime, sandbox record, schema drift, the 18 reference findings, licensing.
- [`docs/GAP_ANALYSIS.md`](../../docs/GAP_ANALYSIS.md): role matrix, IDOR, entrypoint inventory, security register, UI/PWA, conflicts, WP1 prerequisites.

> **Sensitive content notice.** The repository is private. GAP_ANALYSIS §13 lists unfixed upstream vulnerabilities at file/line level, without exploit payloads. Re-review that section before any change of repository visibility or external sharing. Upstream disclosure needs a separate owner decision; nothing was sent upstream.

---

## A. Baseline ancestry / accepted prior HEAD / current HEAD / branch / worktree
| Item | Value |
|---|---|
| Upstream baseline (ancestry) | `d3e0596287322944ee21fcc14dc1798158463140` |
| Accepted prior-WP HEAD | n/a (WP0 is the first WP) |
| Starting HEAD | `500d3a2d15003efeed6e0aad639c13d08101c3b1` (`d3e0596` + docs-only spec-pack commit) |
| Branch | `wp0/reconnaissance`, created from `origin/feat/reseller-system-v1` @ `500d3a2`. Upstream tracking was removed on purpose so a bare `git push` cannot target the feature branch. |
| Ending HEAD | the single WP0 commit on `wp0/reconnaissance` (a commit cannot contain its own hash; the SHA is stated in the final chat response and is `git rev-parse wp0/reconnaissance`) |
| App tree vs baseline | identical (`git diff --name-only d3e0596 HEAD -- . ':!docs/claude-development-pack' ':!docs/*.md' ':!owner-review'` → empty) |
| Worktree | clean at start; clean after commit |
| Other branches | `claude/hopeful-lamport-e78x4m`, `main`, `feat/reseller-system-v1` untouched |

## B. Scope implemented
Inspection, sandbox verification and documentation only, per `prompts/WP0_RECONNAISSANCE.md` and the owner's approved plan and amendments:
- **Baseline:** pinned and proven (app tree identical to `d3e0596`); spec-pack manifest verified (43/43).
- **Runtime:** PHP 8.2.34 (owner decision C5) used to boot and run the application in a disposable sandbox; PHP 8.3 used for comparison and lint only.
- **Schema drift:** fresh install vs upgraded install vs long-lived install vs `install/radius.sql` vs official FreeRADIUS 3.2.5 schema vs local `rad_acct`. Built and compared in the sandbox; not resolved.
- **Reference findings:** all 18 classified (18 CONFIRMED; several broader than stated).
- **Entrypoints:** complete service-granting / money-moving inventory (24 rows), explicit candidate bypass list, destructive-path list.
- **Security:** register of 25 pre-existing findings with severity, trust boundary, file/line evidence, earliest WP, production blocker and feature-blocker flags. Verified statically; **nothing fixed** (owner decision C9).
- **Roles:** Agent/Sales/Report behaviour, voucher exposure (reseller voucher generation EXISTS), `tbl_plans.price` usage map, `radacct` vs `rad_acct` map.
- **Network:** FreeRADIUS, CoA and MikroTik findings.
- **UI:** RTL, responsive and PWA assessment, with the smallest compliant approach recommended.
- **Licensing:** conflict classified with history; no notices changed.
- **Decisions:** 23 spec↔repo conflicts recorded with evidence, impact, options and recommendation.
- **Proposals:** WP1+ dev/test environment and WP1 prerequisites.

**Not done (forbidden or out of scope):** no reseller feature, no migration, no app behaviour change, no dependency change, no UI change, no upstream merge, no production contact, no PR, no WP1 work.

## C. Files changed
| File | Change |
|---|---|
| `docs/BASELINE_VERIFICATION.md` | new |
| `docs/GAP_ANALYSIS.md` | new |
| `owner-review/WP0/REPORT.md` | new (this file) |

Nothing else in the repository was modified. Sandbox files (app copy, config, harnesses, logs, screenshots) live only in the session scratchpad and are not committed.

## D. Important design notes
- **Verification method:**
  - High-risk *security* findings were classified from static code evidence only. No attacker-style requests or exploit payloads were run (owner rule: minimum safe proof).
  - Dynamic checks ran the application's own code paths: recharge, RADIUS provisioning, cron, device rename, and `radtest` on loopback.
- **Execution base:** `500d3a2` (owner instruction) rather than a detached `d3e0596`; proven equivalent (conflict C1).
- **Sandbox network safety:**
  - Router/NAS endpoints were 127.0.0.1 with nothing listening.
  - `radclient` was replaced by a logging stub inside the PHP container, so no CoA/Disconnect packet was sent.
  - FreeRADIUS listened on 127.0.0.1 only.
- **Sandbox-only configuration changes:** on the container OS only (none in the repository). To make the stock server accept app-provisioned users, `Max-All-Session` was defined in the local dictionary. That workaround is itself a finding (C16).
- **No owner decision was silently applied.** C2, C3, C4 (WP0 scope), C5 and C9 (WP0 scope) are owner-resolved; everything else is open.

## E. Migrations / data impact
None. No migration was written or run against any non-sandbox database. Repository schema files are unchanged.

## F. Test commands and exact results
| # | Command (summary) | Exact result |
|---|---|---|
| 1 | `git rev-parse HEAD` (start) | `500d3a2d15003efeed6e0aad639c13d08101c3b1` |
| 2 | `git merge-base --is-ancestor d3e0596 HEAD` | exit 0 (ancestor) |
| 3 | `git diff --name-only d3e0596 500d3a2 -- . ':!docs/claude-development-pack'` | 0 files |
| 4 | `sha256sum -c docs/claude-development-pack/MANIFEST.sha256` | 43 OK, 0 FAILED |
| 5 | `php -l` × 200 non-vendor tracked PHP files, PHP 8.2.34 (container) | 200 pass, 0 fail |
| 6 | same, PHP 8.3.6 (host) | 200 pass, 0 fail |
| 7 | `composer check-platform-reqs --lock -d system` on PHP 8.3.6 | `php` **failed** (mpdf 8.1.6 requires < 8.3); ext-gd, mbstring, zlib success |
| 8 | same on PHP 8.2.34 | `php` success; mbstring, zlib success; `ext-gd` missing (sandbox image only) |
| 9 | `composer validate --no-check-publish --no-check-all` | `./composer.json is valid` |
| 10 | Load `install/phpnuxbill.sql` → `nb_fresh` | 21 tables / 231 columns, all InnoDB |
| 11 | Fresh + all `system/updates.json` (update.php loop with error logging) → `nb_upgraded` | ok=35, **failed (silently ignored by update.php)=56** |
| 12 | 2023-08-09 install SQL + all updates → `nb_legacy` | ok=87, failed=4 (incl. `nas.routers` "table doesn't exist") |
| 13 | Schema diffs (information_schema column/index compare) | see BASELINE §4.4 |
| 14 | `install/radius.sql` vs official FreeRADIUS 3.2.5 schema | identical except `nas.routers`, `radgroupreply.plan_id` |
| 15 | App boot on PHP 8.2 (`php -S 127.0.0.1:8080`): GET `admin`, `login`, `register`, `login&do=forgot` | HTTP 200 ×4; PHP error log empty |
| 16 | F1 `rechargeUser` RADIUS plan (harness) | `radacct` rows 3 → **0**; recharge `on`; 1 transaction; disconnect stub called 1× |
| 17 | F2 `rechargeUser` Hotspot plan, unreachable router | recharge saved `on`, transaction `INV-2`; failure not surfaced |
| 18 | F3 `change_username` with stub RouterOS client | Hotspot: `print` only (no `set`). PPPoE: `print`, `set`, remove-active. |
| 19 | F4 `php cron.php` (cwd `system/`) | auto-renew success; balance 5000.00 → 4000.00; `INV-3` `Customer - Balance`; new period 2026-10-01→2026-10-31. First attempt from web root failed (`include ../init.php`), recorded. |
| 20 | R1–R3 `radtest` (stock FreeRADIUS 3.2.5) | provisioned user, wrong password, unprovisioned user: **all Access-Reject**; server: `Unknown name "Max-All-Session"` |
| 21 | R4–R5 `radtest` after local `Max-All-Session` definition | correct password **Access-Accept**; wrong password Access-Reject |
| 22 | U1 Playwright screenshots `admin`/`login` @1440/834/390 | no horizontal overflow; no `dir`/`lang` on these pages |
| 23 | Static sweeps (`git grep`): rechargeUser/Balance/add_customer/run_hook/gateway calls; transactions/locks; price reads; RTL/PWA markers | inventory per GAP §7. Transactions/locks in app PHP: **0**. Plan price read sites: **85** in 10 PHP files + 29 templates. `dir="rtl"`: **0**. Manifest/service worker: **0**. |
| 24 | Existing automated tests / CI | none exist; nothing to run |
| 25 | Secret sweep of the three artifacts (`grep -niE 'password|secret|token|api_key|db_pass'` and manual review) | see the final chat response. No credential values present; matches are descriptive words only. |
| 26 | `git diff --stat 500d3a2..HEAD` | exactly the 3 files in §C |

## G. Security / failure cases tested
**Exercised dynamically**
- Provisioning failure with an unreachable router (F2).
- RADIUS accounting-history preservation (F1, fails as baseline behaviour).
- Device rename correctness (F3).
- Cron auto-renew finance path (F4).
- RADIUS reject on wrong password and unprovisioned user (R2, R3, R5).
- Stock-server incompatibility (R1).
- Migration error swallowing (#11–12).

**Classified statically only (deliberately not exploited)**
- SEC-01 … SEC-25 (GAP_ANALYSIS §13): authorization bypass, privilege escalation, unauthenticated endpoints, injection surfaces, CSRF posture, credential storage, installer/updater exposure.

## H. Git diff summary
One commit adding 3 Markdown files under `docs/` and `owner-review/WP0/`. No deletions, no modifications to existing files, no binary files.

## I. Known risks / TODOs (for owner decision; nothing fixed in WP0)
1. **Critical/High pre-existing security findings** (GAP §13) make the baseline unsuitable for production as-is. Proposed WP mapping needs owner confirmation (C9b).
2. **`radacct` history** is destroyed by almost every provisioning path (C17), so historical accounting in existing deployments may already be incomplete.
3. **Stock FreeRADIUS rejects** app-provisioned users without extra configuration (C16).
4. **No transactions or locks** in any money or service path (SEC-24, C15).
5. **Reseller voucher generation already exists**, free of charge (C8).
6. **Schema drift** between install paths; the updater hides failures (C13).
7. **Licensing conflict** (README GPL-2.0-or-later / LICENSE GPLv2 text / composer MIT) plus an LGPL-3.0 ↔ GPL-2.0-only bundling question needs owner/legal review.
8. **Unverified items:**
   - PDF/gd paths on PHP 8.2;
   - rate-limit delivery via `radgroupreply` (plan seeded by SQL, not via UI);
   - authenticated UI screens (static review only);
   - external gateway/plugin code (not in repo).

## J. Production status
Untouched. No production system, database, RADIUS server, MikroTik or external network target was contacted.

## K. Push status
Normal push of `wp0/reconnaissance` to `origin`, authorized by the owner; no force, no PR, no merge, no tag, no deploy. The outcome is stated in the final chat response.

## L. Owner-review artifact path
`owner-review/WP0/REPORT.md` (this file)

## M. Final state
`STOPPED_WAITING_OWNER_REVIEW`

---

## Unresolved conflicts (summary; full table in GAP_ANALYSIS §15)
**Open, requiring owner decision**
- C1: accept 500d3a2 as baseline-equivalent.
- C6: external gateways/plugins, contract-level gating.
- C7: WP2 must scope legacy routes.
- C8: gate Agent/Sales voucher generation early.
- C9b: confirm security finding → WP mapping, or a dedicated hardening WP.
- C10: extra entrypoints into WP5B.
- C11: core gates, not hooks.
- C12: VARCHAR prices.
- C13: WP1 base schema and strict runner.
- C14: renewal expiry semantics.
- C15: transactions/locks in WP3A.
- C16: FreeRADIUS server configuration dependency.
- C17: global `radacct` purge removal in WP4.
- C18: timezone.
- C19: FK signedness.
- C20: closure import from `root`.
- C21: Sales visibility change.
- C22: installer/default-credential runbook.
- C23: `tbl_odps` latin1.

**Owner-resolved:**
- C2: branch `wp0/reconnaissance`.
- C3: push, no PR.
- C4: top-level `docs/*.md` for WP0.
- C5: PHP 8.2.
- C9: verify and document only in WP0.

## WP1 blockers (GAP_ANALYSIS §17)
WP1 may start only after:
1. Owner acceptance of WP0 with a ledger entry.
2. C13 decided: base schema + strict migration runner.
3. C19 decided: FK/signedness policy.
4. Column names and money precision approved.
5. Test-environment dependency change approved (PHPUnit dev-only, PHP 8.2 Docker files).
6. C20 decided: optional audited closure import.
7. D027/C8 confirmed: no voucher schema in WP1.

## Definition of Done assessment (WP0)
| DoD item | Status | Evidence |
|---|---|---|
| Requested scope implemented, nothing knowingly omitted | Met, with documented limits (BASELINE §8) | B |
| No unauthorized adjacent WP work | Met | C, H |
| Relevant tests run and results reported exactly | Met (no repo test suite exists; sandbox checks reported verbatim) | F |
| Negative / security / failure cases where required | Met within owner's "minimum safe proof" rule (static for security, dynamic for functional) | G |
| Migrations / data impact documented | Met (none) | E |
| Changed files and diff summary | Met | C, H |
| Docs for decisions introduced in scope | Met (no decisions introduced; conflicts listed) | GAP §15 |
| Durable `owner-review/WP0/REPORT.md` | Met | L |
| No secrets committed or copied | Met (secret sweep #25) | F |
| No unexplained dirty worktree | Met | A |
| Known risks / TODOs listed | Met | I |
| Production untouched | Met | J |
| Ends with `STOPPED_WAITING_OWNER_REVIEW` | Met | M |
| **WP0 acceptance criteria** (prompt): every reference finding classified with file/function evidence; spec conflicts listed; complete entrypoint inventory and candidate bypass list | Met | BASELINE §5; GAP §7, §15 |
| Owner amendment: schema drift comparison | Met | BASELINE §4 |
| Owner amendment: security findings verified safely, severity, boundary, WP mapping, blockers | Met | GAP §13 |

Overall: **`IMPLEMENTED_NOT_ACCEPTED` — WP0 is complete from the implementer's side and waits for owner review.** WP1 has not been started.
