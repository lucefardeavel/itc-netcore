# WP8 A3 — Handoff to the authorized implementation team (`R-WP8-CARRY-02` + rename integrity)

**Status:** `A3_IMPLEMENTATION_BLOCKED — DESIGN_REVISION_REQUIRED` (owner, 2026-10-12). Implementation starts only after
the owner approves the updated plan (`WP8_A3_PREFLIGHT_AND_IMPLEMENTATION_PLAN.md` rev. 3, decisions OD-A3R-9 … 14).

## 1. Baseline and state

- Branch `wp8/integration-poc` @ `32a162d6e91012ca2638b13332b129ece29331c7` (= origin).
- **Production code is at the baseline:** the assistant applied no production change that remains. One applied change was
  restored from the backup.
- Untracked, prepared by the assistant:
  - `tests/Unit/Wp5b/RenameOldSessionUnitTest.php`;
  - `tests/Integration/Wp5b/RenameOldSessionTest.php`;
  - `owner-review/WP8/evidence/a3/baseline-new-tests-fail.out` (both exit 2 on the baseline; review in plan §R1).
- Backup of the touched files: `/srv/phpnuxbill/wp8-a3-prefix-backup-20261012T130010Z` (SHA256SUMS 7/7).

## 2. Requirements (summary; details in the plan)

1. **Rename integrity first** (plan §R2, F-1 … F-5):
   - exact-byte source / target;
   - an equivalent rename is an in-place update;
   - refuse before any write when another equivalent key exists (if OD-A3R-9 is approved);
   - one rename per `from → to` per run, in both R1 (`LegacyReprovisionService`) and R2 (`customers.php`);
   - verification covers every active plan and leaves every other key unchanged.
2. **Old-identity sessions** (plan rev. 2 §4, owner amendments; approved OD-A3R-5 … 8):
   - capture the old identity's open accounting rows (snapshot before the rename for an equivalent rename);
   - send one Disconnect per captured row, **after the tree lock is released** and outside any transaction;
   - Accounting-Stop confirmation within 6 s in total;
   - `old_session` = `CONFIRMED` (ACK + Stop, or NAK 503 for that session only; NAK 406 and others are not proof) /
     `UNCONFIRMED` / `NO_ACCOUNTING_EVIDENCE` / `NOT_APPLICABLE`. It is kept separate from the re-provision status;
   - the audit event `rename.old_session`. Its name must stay outside `reprovision.%`, which the
     `REPROVISION_UNCONFIRMED` drift reads;
   - a `PENDING` marker in the main audit, also on the `REPROVISION_UNCONFIRMED` path;
   - drift kind `OLD_SESSION_UNCONFIRMED`, also when the confirmation event is missing.
3. **R2 (legacy unassigned customers) is included** (OD-A3R-1): the old sessions are ended; the legacy flow is otherwise
   unchanged and never throws because of the NAS.
4. **Not to change:**
   - `install/radius.sql`, FreeRADIUS configuration;
   - A1 / A2-R / H1-A / H-2B / Option G;
   - schema, finance, accounting history;
   - MikroTik devices; `RadiusRest` (out of scope, an open limit before production).

## 3. Tests

- **Prepared tests:** use them as the specification.
  - Adjust a name only where the implementation chooses another API, event or hook name, without weakening any
    assertion (OD-A3R-14), and list every change.
  - Fix **H-T1**: `testPppoeUsernameEmptyToSetAndSetToEmptyTargetTheFormerPppoeKey` must fail with a message, not a
    `TypeError`, when no confirmation exists.
- **To add for rename integrity** (plan §R5):
  - case-only rename keeps the policy with no gap;
  - another equivalent key is refused, with its rows byte-identical;
  - multi-plan rename touching one key (Hotspot on the username, PPPoE on its own key): every plan verified, R1 `DONE`, R2 no loss. *Corrected 2026-10-13 (owner D-2):* two Radius plans on the **same** key cannot verify even in ADMIN_SYNC. That is a pre-existing residual, unchanged by A3 and not an A3 acceptance criterion; `REPROVISION_UNCONFIRMED` is never a success;
  - a checksum of every other key before and after.
- **Required runs:**
  - unit + integration on **MariaDB and MySQL**;
  - real CHR (PPPoE, Hotspot on CHR1 + CHR2, PPPoE username transitions, equivalent rename, lost Accounting-Start,
    CoA blocked);
  - flip the `RealLifecycleTest` expectation only after the CHR proof;
  - full harness.
  - Keep the actual exit codes; skipped and incomplete tests are not passes.

## 4. Acceptance and stop conditions

**Acceptance:** plan §R5 (1–7) and rev. 2 §9.

**Stop and report, no commit:**
- a new-name or another subscriber's session disconnected;
- `CONFIRMED` while the old session is live;
- any change to another key's rows;
- the tree lock or a transaction held during the wait;
- any need to touch the protected components;
- a secret in a log or the audit.

## 5. Constraints

- Development and lab only.
- No commit, push or merge without owner acceptance.
- No production queries without separate authorization (OD-A3R-12).
- A4, A5 and V-1 are not part of this work.

---

## Owner decisions — planning closure (2026-10-12, additive)

**Official status: `A3_PLAN_APPROVED — IMPLEMENTATION_BLOCKED`.** Plan rev. 3 and the handoff are approved; the study and
planning stage is complete. Code implementation of A3 stays stopped in this session. Any independent implementation is
subject to the authorized engineering team's review and the usual acceptance procedure. WP8 stays open.

| ID | Decision |
|---|---|
| OD-A3R-9 | **APPROVED.** An ambiguous rename is refused **before any write**. Do **not** report it as `FAILED_REVERTED` when no revert actually happened (use a distinct refusal outcome) |
| OD-A3R-10 | **APPROVED.** The extended file review of plan §R3 (`RadiusRepository.php`, `RadiusProvisioner.php`, `customers.php`, plus the rev. 2 files) |
| OD-A3R-11 | **APPROVED WITH CONDITIONS.** An equivalent (case / accent-only) rename is allowed only with guaranteed data and identity integrity (F-1, F-2, F-5) |
| OD-A3R-12 | **DEFERRED.** No production read without separate authorization |
| OD-A3R-13 | **APPROVED WITH AMENDMENT.** MEDIUM / PRODUCTION BLOCKER, recorded as a **sub-defect of `R-WP8-CARRY-02`**, not as a new carry-forward |
| OD-A3R-14 | **APPROVED.** Fix H-T1 and the API / event names without weakening any assertion; **re-run the tests before their results are relied on** |

**Unchanged:**
- the tests, the evidence and their SHA-256;
- no retry of the edits stopped by the safety classifier, in any form;
- no change to production code or tests in this session;
- no commit, push, merge or deploy;
- A4, A5 and V-1 not started.
