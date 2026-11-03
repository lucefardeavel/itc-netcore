# Owner decision register: rev 5 (2026-11-03, directive "ITC NETCORE V1.0 — FINAL OWNER DECISIONS & DELIVERY AUTHORIZATION")

**Baseline:** `release/itc-netcore-v1-final-prep` @ **`0a8b9d58b2745b81d75297b4195fab66b0916a3c`**, pre-release `v1.0.0-rc.1`, Draft PR #8.

The owner gave the decisions below in this session's chat on 2026-11-03, and they are recorded as given. Rev 1–4 and their evidence stay unchanged as history. These rows **supersede** the open A1–A8 items of `OWNER_FINAL_ACTION_SHEET_20261030.md` §A. This is a non-runtime administrative record: no source, tag, release asset or package file was changed.

| ID | Decision subject | Owner decision (2026-11-03) | Conditions that still apply | Evidence status that stays as it was |
|---|---|---|---|---|
| **A1** | A2-R C7: PPPoE admission residual (SEC-E2, R-A2R-1, R-A2R-3) | **APPROVED WITH DOCUMENTED RESIDUAL RISK.** Accepted as a V1.0 business and operational risk | C1–C6 must be implemented and verified on the actual deployment target. The SQL-pool exhaustion experiment stays **prohibited** | `MITIGATED — EFFECT UNVERIFIED`. The owner states that this approval does not prove the behavior safe |
| **A2** | O-DL: bounded financial retry contract | **APPROVED AS DOCUMENTED**: max 2 retries, no jitter, no automatic release of HELD reservations, audited operator reconciliation and recovery | O-DL-1 acknowledged: under MariaDB contention the service may be provisioned while settlement is pending (funds reserved, no ledger debit yet). Reconciliation is mandatory | The O-DL study stays **NOT PASSED** (`FINAL_ACCEPTANCE_0a8b9d58.md` §2.3; D-68). Its result is not rewritten |
| **A3** | RTO / RPO and recovery option | **OPTION 2 APPROVED**: encrypted daily backups, off-host storage, binlog PITR, recovery verification and periodic restore drills. **Target RTO 4 h, target RPO 15 min** | These are approved operational **targets, not measured guarantees**. They must be validated on the customer target (TA-08) before compliance is claimed | Lab drill only (`BACKUP_PITR_DRILL_20261029.md`). TA-08 `NOT_RUN` |
| **A4** | R-HF-CARRY-01 remainder: R3-1, R3-2, unauthenticated enumeration on forgot / register | **ACCEPTED AS DOCUMENTED V1 RESIDUAL** | Keep the existing protective controls. Record the residual in the handover and operating instructions. A correction ships only as a separately versioned hotfix | Already in the release notes and known limitations of the frozen package |
| **A5** | Staff / sub-account username probing residual | **ACCEPTED FOR V1** | Keep the existing RBAC boundaries; the limitation stays recorded | Known limitations (self-rename closed in `5ad4101c`) |
| **A6** | Lab delegation records D-30 / D-2 and the identity approval of 2026-10-26 | **RATIFIED**, to the extent recorded in the authoritative register | Previously accepted engineering work is not reopened | Consolidation §0 |
| **A7** | Brand B-3, B-4, B-5 | **APPROVED AS IMPLEMENTED** | No redesign and no change to brand assets. The ITC NetCore identity, upstream credits and compatibility identifiers stay | Brand assets match their recorded SHA-256 (7/7) |
| **A8** | Go-live identity values | **INSTANCE-SPECIFIC**. Each installation configures its real CompanyName, operator logo, administrator identity, domain / HTTPS, and MikroTik / FreeRADIUS settings | No values are invented. Placeholders and default credentials are not acceptable production configuration | No customer values exist yet. Note: the frozen V1.0 installer is unchanged; it leaves `CompanyName` at its upstream default (fresh-install proof §5) and forces a password change at first login. These values belong to the installation checklist, not to a code change |

## LIC-1

**`LIC-1 — COUNSEL SIGN-OFF PENDING`.** No authentic counsel approval exists in the records:
- `reports/legal/LIC1_LEGAL_READINESS_PACKAGE.md` states that no written sign-off was found;
- the counsel answer sheet (`owner-review/WP10/LEGAL_REVIEW_PACKAGE.md` §4.3) is empty;
- `reports/legal/LIC1_COUNSEL_DECISION_SHEET.md` D1–D8 is unanswered.

No approval, reviewer, date or finding is recorded or invented. Following the owner's instruction, the package is prepared for **internal authorized handover**. External commercial distribution is **not** represented as legally cleared.

## Owner acceptance recorded

`V1.0_OWNER_ENGINEERING_ACCEPTED` · `V1.0_CODE_FROZEN` · `V1.0_SOFTWARE_HANDOVER_PACKAGE_ACCEPTED`

Owner verdict: **ITC NETCORE V1.0 — OWNER ACCEPTED · ENGINEERING COMPLETE · CODE FROZEN · SOFTWARE HANDOVER PACKAGE READY · FUTURE MODIFICATIONS BY SEPARATE HOTFIX ONLY.**

## Still open for a live commercial deployment (installation-specific, not development)

| Item | State |
|---|---|
| LIC-1 counsel sign-off (D1–D8) | Pending |
| Staging / target authorization, then TA-01 … TA-10, X-1 … X-5, UI-1 on the customer target. This includes C1–C6 (A1), the RTO / RPO validation (A3) and the session-guard deadline at the customer's NAS count | `NOT_RUN`. No target test is marked PASS |
| Installation on a new environment with an empty database (`install/fresh/`), with the customer's real identity and credentials (A8) | Not started. No customer values yet |
| WP9 / WP10 formal acceptance against the ledger, G8 | Pending; to be signed after the target tests |
| Production tag `v1.0.0`, production deployment (target, window, rollback contact), customer signed acceptance | Not authorized yet |
