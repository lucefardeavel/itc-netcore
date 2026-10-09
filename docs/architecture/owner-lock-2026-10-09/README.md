# Owner Architecture Lock — 2026-10-09 (verbatim copies)

This folder holds the owner-approved architecture documents of 2026-10-09 **byte-for-byte as delivered**. Do not edit them.
A change to a locked decision needs a named amendment with its reason and impact, plus owner review
(`OWNER_ARCHITECTURE_LOCK.md` §8). This README is the only file here that is not part of the delivered set.

- **Source:** the owner's Google Drive folder `12ntphbk990IE64TyYOd3MJjB67whEaYb`, current files only (the `archive/` subfolder was not used).
  - Package: `SaaS_RADIUS_ARCHITECTURE_OWNER_LOCK_2026-10-09.zip`, sha256 `066f18013c7613fe52944f233ed141644259d248ad33623768730d8984403d57`.
  - The ZIP itself is not committed: `*.zip` is git-ignored, and the files below are enough to re-verify.
- **Integrity:** run `sha256sum -c SaaS_RADIUS_vs_SAS4_AUDIT_MANIFEST.sha256` in this folder. All 10 files must report `OK`.
  - Verified on 2026-10-09 against both the ZIP members and the individually downloaded files.
  - The manifest inside the ZIP is identical to the one delivered separately.
- **Precedence:** `OWNER_ARCHITECTURE_LOCK.md` is the highest authority for **architecture decisions** as of 2026-10-09.
  - **Implementation status** comes from the code, the tests, git and the owner acceptance ledger, never from design approval alone.
  - The ledger is `docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md`.
- **SAS4** (`lucefardeavel/sas4-reference-intelligence`) is a non-authoritative clean-room reference (AD-00).

| File | Role |
|---|---|
| `OWNER_ARCHITECTURE_LOCK.md` | Top-level owner register: ten axes, Option A, release gates, WP authority, execution order |
| `ARCHITECTURE_DECISIONS.md` | AD-00 … AD-24 and the canonical owner decision state |
| `TARGET_ARCHITECTURE.md` | Mandatory V1 target vs conditional modules |
| `IMPLEMENTATION_ROADMAP.md` | Bounded sequence (Phase A–F); not implementation authorization |
| `IMPROVEMENT_BACKLOG.md` | P0/P1 work items and conditional discovery |
| `RISK_REGISTER.md` | R-000 … R-021, C-R01 … C-R10, O-R01 … O-R08 |
| `GAP_ANALYSIS.md` | GAP-01 … GAP-15, CQ-01 … CQ-09 (not the same file as the WP0 `docs/GAP_ANALYSIS.md`) |
| `KEEP_ADOPT_REPLACE_MATRIX.md` | K00 … K67 |
| `CURRENT_SYSTEM_BASELINE.md` | Evidence baseline as of 2026-10-07 (`wp8/integration-poc@47a3a02c`) |
| `SAS4_COMPARISON_MATRIX.md` | Reference comparison (non-normative) |

Sync and status reports for this lock:
- [`../DOCUMENTATION_SYNC_REPORT.md`](../DOCUMENTATION_SYNC_REPORT.md)
- [`../DEVELOPMENT_STATUS_REPORT.md`](../DEVELOPMENT_STATUS_REPORT.md)
