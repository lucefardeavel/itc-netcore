# Documentation Sync Report — Owner Architecture Lock 2026-10-09

This was a docs-only task. No PHP, SQL, migration, FreeRADIUS / MikroTik configuration, test or database was changed or run.
The work was done in a separate worktree, on branch `docs/owner-architecture-lock-2026-10-09` from
`feat/reseller-system-v1@5bdc5745`, so the uncommitted WP8 work in the main checkout was left untouched.

## 1. Source

- **Owner Google Drive folder:** `12ntphbk990IE64TyYOd3MJjB67whEaYb`, files dated 2026-10-09 (created 02:24–02:25 UTC).
  - Only the current files were used; the `archive/` subfolder was ignored.
  - The files were downloaded with direct public links and their sizes matched the Drive metadata.
- **ZIP package:** `SaaS_RADIUS_ARCHITECTURE_OWNER_LOCK_2026-10-09.zip`.
  - 48 698 bytes, sha256 `066f18013c7613fe52944f233ed141644259d248ad33623768730d8984403d57`.
  - Zip CRC test passed; 11 members: the 10 documents plus the manifest.
- **Manifest:** `SaaS_RADIUS_vs_SAS4_AUDIT_MANIFEST.sha256`.
  - 900 bytes, sha256 `757973b408390e4769381c58fa91c29f45d0c63820a0e8b091cf578e3066eb23`.
  - Byte-identical to the copy inside the ZIP.

## 2. SHA-256 verification

| File | Manifest sha256 | ZIP member | Individual Drive file |
|---|---|---|---|
| OWNER_ARCHITECTURE_LOCK.md | `2f35d7e0…c383f6` | OK | OK |
| ARCHITECTURE_DECISIONS.md | `b230630b…ec018a` | OK | OK |
| TARGET_ARCHITECTURE.md | `16186f5d…dff9d3` | OK | OK |
| IMPLEMENTATION_ROADMAP.md | `f98edf43…e531bb` | OK | OK |
| IMPROVEMENT_BACKLOG.md | `3e86fccb…a3f6c6` | OK | OK |
| RISK_REGISTER.md | `92518c80…bc6b09` | OK | OK |
| KEEP_ADOPT_REPLACE_MATRIX.md | `a38403b0…90e4a0` | OK | OK |
| CURRENT_SYSTEM_BASELINE.md | `4e2f7ccb…0ee5f0` | OK | OK |
| GAP_ANALYSIS.md | `b846b7b9…2c5d41` | OK | OK |
| SAS4_COMPARISON_MATRIX.md | `5162eeca…930289` | OK | OK |

**Result:** 10/10 OK against the ZIP and 10/10 OK against the individual files. The full digests are in the committed manifest.
The committed copies were re-verified in place (`sha256sum -c` in the folder: 10/10 OK).

## 3. Where the documents are stored

- `docs/architecture/owner-lock-2026-10-09/` holds the 10 documents and the manifest, byte-for-byte. Its `README.md` (index and provenance) is the only added file.
  - The `.gitignore` rule `docs/**` admits only `docs/*.md`, so these files are force-added. The project pack is tracked the same way.
  - The ZIP is not committed: `*.zip` is ignored, and the manifest is enough to re-verify.
- `docs/architecture/DOCUMENTATION_SYNC_REPORT.md` (this file) and `docs/architecture/DEVELOPMENT_STATUS_REPORT.md` sit next to the folder, not inside it, so the folder stays the delivered set.

## 4. Files changed

| File | Change |
|---|---|
| `CLAUDE.md` (new, root) | A short pointer file:<br>- source-of-truth order;<br>- decision-handling rules;<br>- out-of-V1 list from the lock;<br>- check WP status before work. |
| `docs/architecture/owner-lock-2026-10-09/*` (new) | The verbatim documents, the manifest and the index README. |
| `docs/architecture/DOCUMENTATION_SYNC_REPORT.md` (new) | This report. |
| `docs/architecture/DEVELOPMENT_STATUS_REPORT.md` (new) | WP0–WP10 status audit. |
| `docs/GAP_ANALYSIS.md` | A dated 2026-10-09 note only:<br>- this is the WP0 gap analysis;<br>- it is distinct from the lock's `GAP_ANALYSIS.md` (GAP-01…15).<br>The historical content is unchanged. |
| `docs/WP4_RADIUS_MODEL.md` | A dated 2026-10-09 note only:<br>- AD-03 wording on `radacct`;<br>- AD-04 / 06 / 07 Session Authority direction, not implemented;<br>- time-contract open item.<br>The accepted model is unchanged. |

## 5. Files reviewed and left unchanged

- **`docs/claude-development-pack/**` (42 files pinned by `MANIFEST.sha256`).** These cannot be edited without breaking the 42/42 pack manifest check that every WP validates.
  - The pack's frozen decisions agree with the lock: D001 ↔ AD-01, D002 ↔ AD-02, D017 ↔ AD-22, D018 ↔ AD-03, D028 ↔ AD-11.
  - Its differences are recorded as C-06 and C-07 below. Precedence is stated in `CLAUDE.md`.
- **`docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md`.** The ledger is owner-controlled. No status was changed, and WP8/WP9/WP10 stay PENDING.
  - Recording the architecture lock in the ledger is left to the owner.
- **`owner-review/WP0…WP7/**`, `owner-review/HOTFIX_PACE_XHR_CSRF/**` and the WP8 records on `wp8/integration-poc`.** These are historical acceptance and evidence records, preserved as they are.
- **The other `docs/WP*_MODEL.md`, `docs/BASELINE_VERIFICATION.md`, `README.md` and `install/freeradius/README.md`.** No contradiction with the lock was found.
  - The WP6A "stale > 30 min" session label already matches AD-06, where stale means unverified rather than offline.
- **`.claude/rules/`.** Not created: one short `CLAUDE.md` plus the pack's `15_CLAUDE_RULES.md` cover it without duplicating anything.

## 6. Conflicts

None of these were resolved in code. Only C-06 and C-07 are documentation-only, and both are recorded rather than "fixed" because the pack is manifest-pinned.

| ID | Current file / record | New lock decision | Kind | Severity | Evidence | Fixable in docs now? | Needs owner decision before code? |
|---|---|---|---|---|---|---|---|
| C-01 | The WP8 OD-W8-16 third-round directive (2026-10-07). It authorized, if the gate passed:<br>- the permanent Option G integration;<br>- continuing S7 → S8 automatically;<br>- "Do not fix" R-WP8-CARRY-01/02/03. | Roadmap A1 → A2 (concurrent-first-login gate) → A3–A5 (close CARRY-02 / 03 / 01) → A6 (S7 / S8 *only after* the blocking session-admission issue and required gates are closed). Lock §7.1. | Execution order | HIGH | `IMPLEMENTATION_ROADMAP.md` Phase A; `owner-review/WP8/OWNER_DECISIONS.md` third round | Recorded here | **YES.** Which order applies, and whether the carry-forwards are now authorized to be fixed inside WP8. |
| C-02 | The third-round directive named exact permanent paths: `install/freeradius/bin/nuxr_pppoe_session_guard`, the exec module, the site, `sql.example`, and the `RadiusPolicyBuilder` Hotspot Port-Limit. | AD-05: preserve the Option G *semantics*; "the current lab script is not automatically the permanent architecture; packaging remains subject to review". | Implementation | MEDIUM | `ARCHITECTURE_DECISIONS.md` AD-05 | Recorded | **YES.** Confirm or re-review the packaging. |
| C-03 | Uncommitted partial permanent Option G integration in the `/srv/phpnuxbill/dev` working tree: 5 files under `install/freeradius` and `dev/test`. | The lock (evidence `47a3a02c`) describes Option G as `dev/wp8/` only. That is accurate for every committed state. | Implementation state | MEDIUM | `git status` on `wp8/integration-poc` | Recorded (not touched) | **YES.** Continue, revise or discard it after C-01 / C-02. |
| C-04 | The WP8 records classify R-WP8-CARRY-01 / 02 / 03 as MEDIUM. | `RISK_REGISTER.md` rates R-008 / R-009 / R-010 as HIGH, and lock §6 calls them production blockers. | Documentation | LOW | both files | Both recorded; no rewrite of history | Optional (classification) |
| C-05 | WP4 owner decision 4: run FreeRADIUS in the application time zone (`Expiration` is parsed in local time). | GAP-05 / AUD-P0-05: one tested accounting time contract, "preferably UTC across FreeRADIUS SQL session / application interpretation". | Implementation | MEDIUM | `owner-review/WP4/OWNER_DECISIONS.md` line 36; lock `GAP_ANALYSIS.md` GAP-05 | Recorded (WP4 model note) | **YES**, when R-WP8-CARRY-03 is closed. |
| C-06 | Pack `START_HERE.md` product goal: "SAS4-style business domain". | AD-00: SAS4 is non-authoritative and is never a justification. | Documentation | LOW | `START_HERE.md` "Product goal" | Not editable (42/42 manifest); precedence in `CLAUDE.md` | No |
| C-07 | The pack's `14_WORK_PACKAGES.md` and the WP9 / WP10 prompts do not list the new gates (concurrency, Q1–Q4, P1–P3, N1–N7, REL-01–05, ISO-01). | The lock and roadmap make these mandatory release gates, "integrate as input acceptance checks; do not duplicate / rewrite WP9 or WP10". | Documentation / scope input | MEDIUM | `OWNER_ARCHITECTURE_LOCK.md` §5; roadmap "Mandatory Release Verification Gates" | Recorded as WP9 / WP10 inputs in the status report | **YES**, when the WP9 / WP10 prompts are issued. |
| C-08 | `feat` still has `delete_stale_sessions = yes` (`install/freeradius/mods-available/sql.example`, `dev/test/freeradius/sql`), and `RadiusPolicyBuilder` writes no Port-Limit. | AD-06 / AD-07 and the Option G contract: `delete_stale_sessions = no`, plus Hotspot Port-Limit as defense-in-depth. | Implementation (pending WP8 productionization) | MEDIUM | the files on `feat` | Recorded | Covered by C-01 / C-02 |

No conflict was found on the following points:
- Option A per-ISP isolation: there is no `tenant_id` or shared tenancy anywhere in the code or docs.
- Finance and the ledger (AD-13).
- SUCCESS / FAILED / UNKNOWN (AD-22).
- CoA (AD-09), which matches the WP8 S3 binding rules.
- The global tree lock (AD-23).
- The conditional modules (Redis / broker / HA / microservices / advanced FUP): none exists, and the pack already defers the FUP engine (D028).

## 7. Git diff summary

The only changes are documentation: new Markdown files plus the manifest copy, and two notes appended to existing Markdown files.
`git diff --name-only feat/reseller-system-v1` lists only `CLAUDE.md` and paths under `docs/`.
Nothing changed in `system/`, `ui/`, `install/`, `tests/`, `dev/`, the root `*.php` files, `owner-review/` or `docs/claude-development-pack/`.
The pack manifest still checks 42/42.

**Local commit:** the SHA is given in the final chat report. It cannot appear in this file, because a commit cannot contain its own SHA.
- Branch: `docs/owner-architecture-lock-2026-10-09` (local only).
- Worktree: `/srv/phpnuxbill/docs-sync`.
- Not pushed and not merged.

## 8. Result

- All 10 documents and the manifest were verified and stored.
- `CLAUDE.md` points to the lock.
- Two historical documents carry dated cross-reference notes.
- Eight conflicts are recorded, five of which need an owner decision (C-01, C-02, C-03, C-05, C-07). None was resolved by choice.

**Documentation integrity: OK.**
