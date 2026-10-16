# WP9 — Red-Team / Release-Candidate Report

| Item | Value |
|---|---|
| **Status of this report** | **Opened for the T0 section only.** WP9 is **not complete**. T1–T4, every remediation wave, U0, the A2-R hardening, the P1–P5 policies, NEW-7 and NEW-8 are **not started**. Nothing below claims more than T0 |
| **Status update (2026-10-15, cumulative)** | The row above is the T0-time status, kept. Since then: T0+ / T1-A (§T0+, §T1-A), WP9-A1 V1 interim fix (§A1, `V1_MITIGATED_NOT_CLOSED` at that time) and the **U0 shared identity lock, implemented and verified end-to-end (§U0)**. WP9 as a whole is **not complete** (A2-R, P1–P5 policies, NEW-7, NEW-8, plugins, WP1-CHECK and the other T1-B…T4 rows remain); `PRODUCTION_NOT_AUTHORIZED` |
| Owner decision | `WP9_PLAN_REV2_ACCEPTED_WITH_AMENDMENTS`, `WP9_T0_STATIC_TESTING_AUTHORIZED`, `PRODUCTION_NOT_AUTHORIZED` (2026-10-15) |
| Canonical baseline | `feat/reseller-system-v1@704031c61296f4975f1d4e51531973d67e5f0a9b` |
| Branch | `wp9/red-team-rc` (local only, no upstream). HEAD `143c4cd10ae7cc93d42e150b5641f6976e3342a9`: the plan documents' commit, whose parent is the baseline |
| Plan | `owner-review/WP9/PLAN.md` revision 3 (committed in `143c4cd1`) |
| T0 state | **`WP9_T0_EVIDENCE_READY_FOR_OWNER_REVIEW`** (§T0.9) |
| T0 owner decision | `WP9_T0_STATIC_ACCEPTED_WITH_NOT_TESTED` (2026-10-15); static checks are not proof that any finding is closed |
| T0+ / T1-A (added 2026-10-15) | Owner `WP9_T0_PHP_COMPLETION_AUTHORIZED`, `WP9_T1A_CHARACTERIZATION_AUTHORIZED_CONDITIONALLY`. **T0+ complete** (§T0+). **T1-A stopped at an owner stop trigger** (V1, §T1-A): **`WP9_T1A_BLOCKED_WITH_EVIDENCE`** |
| Status note | This report now holds T0, T0+ and T1-A results only. WP9 is still not complete; nothing was fixed |
| Owner decisions on T0+ / T1-A (2026-10-15) | `WP9_T0PLUS_ACCEPTED` (SourceGuard 51 / 37 766 exit 0; CSV 23 / 27 with 4 DEFECT_REPRODUCED). `WP9_T1A_SECURITY_STOP_CONFIRMED`. **V1 classified `OBS_W9_V1_HIGH_PRODUCTION_BLOCKER`**: tracking ID **OBS-W9-V1**, E4 TEST-PROVEN on MariaDB 10.11, HIGH, PRODUCTION BLOCKER, belongs to OBS-1 / L-A5-2; **not** a contradiction of the WP8 protection of reseller-owned customers; **MySQL 8.0: NOT TESTED** at T1-A; **no real NAS login was demonstrated** (the evidence is the RADIUS-table change only). `WP9_A1_V1_BOUNDED_REMEDIATION_AUTHORIZED` (next wave, §A1) |

---

## T0 — Static / inert testing (2026-10-15)

### T0.1 Conditions honoured
Every T0 condition was honoured:
- no database connection;
- no Docker container;
- no FreeRADIUS process;
- no MikroTik / CHR;
- no HTTP / PWA lab;
- no SQL exhaustion, pressure, fault injection or simulation;
- no product-code change;
- no schema / migration change;
- no SourceGuard re-pin.

The T0 checker uses only the Python standard library. It reads repository files and writes evidence files, and it contains no process, socket or HTTP primitive. The only matches of "shell_exec / eval" in it are data strings: the allow-list text and synthetic samples.

### T0.2 Can PHPUnit run T0 on its own? (the owner's first question)
- **PHPUnit bootstrap** (`tests/bootstrap.php`): it only `require`s class files behind a CLI guard (`PHP_SAPI !== 'cli'` → 404) and registers an autoloader. No connection is made at bootstrap.
- **Support files:** every `tests/Support/*.php` file starts with the same CLI guard and otherwise defines classes only.
- **Where databases come in:** database access starts in test setup. `tests/Support/TestDatabase.php:21-24` reads `WP1_DB_HOST` / `WP1_DB_ROOT_PASSWORD` and refuses without them.
- **Blocker:** the host has **no PHP runtime** (`/usr/bin/php*` and `/usr/local/bin/php*` absent; `command -v php` returns nothing). PHP 8.2 exists only in the Docker image `nuxbill-reseller-test-php82`, and Docker is not allowed in T0. **PHPUnit therefore cannot run T0 without Docker.**
- **What was done instead:** the static items were run as Python static checks (allowed: no services, no PHP execution). The two items that must **execute PHP** are reported **NOT TESTED**, and an alternative is proposed for approval (§T0.8).

### T0.3 Commands
| Run | Exact command (cwd `/srv/phpnuxbill/dev`) | Exit | Result | Evidence |
|---|---|---|---|---|
| 1 | `python3 -I -B dev/wp9/t0_static.py --out owner-review/WP9/evidence/t0` (script sha256 `8f189880…2a39`) | **1** | 7 PASS, **1 FAIL** (T0-01 positive control), 141 assertions | `evidence/t0/run1-FAILED-t0-run.out`, `run1-FAILED-t0-results.json` (kept) |
| 2 | same command (script sha256 `a9bdf467202a655c67bde2c0a8690ede76ec8ce2629893889c73e1cc682384e7`) | **0** | **8 PASS, 0 FAIL, 146 assertions**; 2 NOT TESTED | `evidence/t0/t0-run.out`, `evidence/t0/t0-results.json` |

**Why run 1 failed** (a test-design defect, not a product finding):
- T0-01 has a positive control: "the echo detector finds the H-2B echoed parameter rows".
- In run 1 the detector pattern was `FROM (SELECT '%{`. That misses the real H-2B form `FROM (SELECT UNHEX('%{Tmp-String-3}') AS k, …) AS p … HEX(p.k)` at site `:694`.
- The pattern was widened to "a derived parameter row `AS p` built from an expansion, or an echoed `HEX(p.`". It now matches only `:694` and `:769`, both H-2B and outside the PPPoE block.
- No product file was touched. Run 1 is kept.

**Tree fingerprint, run 2:** 1802 files of `system/`, `install/`, `ui/`, `tests/` (without `tests/vendor`) and the root `*.php` files. sha256 before = after = `93c1b2753e4db4e1e1ceb6a62d922d08add1d89980901f3d5fcb47f238dde333`, so the tree is **unchanged**.

**Per-check source fingerprints:** `t0-results.json` (`source_fingerprint`) records the sha256 of every file each check read. Key files:

| File | sha256 (first 12) |
|---|---|
| `sites-available/phpnuxbill` | `5737ecbd36a5` |
| `nuxr_admission.sql` | `9ee294ba2a6f` |
| `nuxr_hs_admission.sql` | `ecb1bf38a38a` |
| `Tx.php` | `223cfb618ee7` (= its pin) |
| `TreeSerialization.php` | `6260b067aacc` |
| `CustomerService.php` | `65da76fa214c` |
| `customers.php` | `1d677449877d` |
| `Format.php` | `b656f968f332` |
| `User.php` | `cb30ede1845f` |
| `boot.php` | `2e318d755b71` |
| `pages.php` | `fb35ff0f7d33` |

### T0.4 Results (run 2)

**Common to every row:**
- Command: as in §T0.3.
- Exit code: 0.
- Evidence path: `owner-review/WP9/evidence/t0/t0-results.json` (the per-check `details`) and `t0-run.out`.
- **PASS** means the static characterisation holds on the fingerprinted tree. It is **not** a statement that the code is secure.

| ID | Check | Finding reference | Tests / assertions | Controls | Status |
|---|---|---|---|---|---|
| **T0-01** | **A2-R static statement inventory.**<br>• the PPPoE block (site `:203-494`) has exactly 11 `%{sql:}` statements (`:209, 240, 259, 310, 311, 416, 425, 434, 444, 451, 478`), and accounting has 2 A2-R statements (`:96, :101`);<br>• the 7 writes on `nuxr_admission` (`:416, 425, 434, 451, 478, 96, 101`) each interpolate 2–10 escaped values;<br>• there is no echoed parameter row on the PPPoE path;<br>• `nuxr_admission.sql` has 0 CHECK constraints, plus PK(username), UNIQUE(token), and no ALTER | N-13, PLAN §6.1 | 1 / 24 | positive: the echo detector finds the H-2B rows (`:694, :769`); positive: the CHECK detector finds the 2 H-2B constraints | **PASS** |
| **T0-02** | **A2-R sentinel / rejection decision table.**<br>• the condition text is confirmed at `:211, 242, 261, 266, 313, 323, 326, 441, 446, 458`;<br>• reject branches are confirmed;<br>• the `n=0;` branch (`:323`) is empty, so the guard is skipped;<br>• the release result (`:478`) and the accounting results (`:96, :101`) are **not checked**, and the late-claim path rejects regardless;<br>• the site's own regex literals, evaluated on 20 synthetic results:<br>  – an empty result is **rejected** by S0 / S1 / S2 / S3a / S3b;<br>  – `n=;` is rejected;<br>  – `n=0;` skips the guard;<br>  – well-formed results are accepted;<br>  – a failed claim → re-read | N-13, PLAN §6.1 items 4–6, F2 premise | 1 / 41 | negative: an empty result is rejected by every guarded read; positive: well-formed results take the accepting path | **PASS** |
| **T0-03** | **SourceGuard-oriented analysis and lock-ordering premises.**<br>• **pins:** all 23 SHA pins in `tests/Unit/Wp3a/SourceGuardTest.php` match the files. `Tx`, `SchemaInspector`, `Runner`, `RouteMap`, `Package` and `ServiceGate` are pinned. The 10 files planned for OBS-1 / N-01 / N-04 / NEW-7 / NEW-8 are **not** pinned;<br>• **AM-W9-1 premises:**<br>  – `withTreeLock` = `GET_LOCK` then `Tx::run`;<br>  – `Tx::run` calls `beginTransaction()` before the callback;<br>  – `TreeSerialization::hold` refuses an open transaction;<br>  – P1 runs inside `withTreeLock` and checks username only;<br>  – no test couples `CustomerService` to `withTreeLock`;<br>• `AdmissionSourceTest` pins an additive `nuxr_admission.sql` | N-08, PLAN §7.3, §15 | 1 / 50 | negative: a one-byte mutation of a pinned file changes its digest | **PASS** |
| **T0-04** | **No-shell static scan** of 310 PHP files (`system/` without `system/vendor`, `install/`, `ui/`, root):<br>• 0 unknown process / eval sinks;<br>• exactly the 8 reviewed sinks: PEAR2 `stty` ×2, PHPMailer `popen` ×2, `settings.php:173 shell_exec('which php')`, `html_php` widgets `eval` ×3 (SEC-22);<br>• 0 sinks in the RADIUS / CoA code | matrix #14, SEC-11 / SEC-22 | 1 / 5 | negative: synthetic `shell_exec($x)`, `eval($y)` and a backtick are detected; positive: method calls and declarations are not reported | **PASS** |
| **T0-05** | **Ledger / audit writer scan** of 303 files (incl. the CLI under `system/reseller/`; `system/plugin` and `system/paymentgateway` hold no PHP plugin at HEAD):<br>• 0 UPDATE / DELETE / REPLACE / TRUNCATE / ALTER / DROP on `tbl_manager_ledger` or `tbl_reseller_audit_events`;<br>• the only ledger INSERT is in `LedgerService.php`; the only audit INSERT is in `AuditLog.php`;<br>• 0 ORM access to finance / audit tables | matrix #11, TAMPER | 1 / 5 | positive: the LedgerService INSERT is found; negative: a synthetic ledger UPDATE is detected | **PASS** (static only: the database itself does not reject UPDATE / DELETE — LPDB, WP10) |
| **T0-06** | **CSV escaping, static characterisation:**<br>• `customers/csv` (`:26`) and `csv-prepaid` (`:87`) build rows with `implode('","', $row)`, with no quote escaping and no formula neutralisation;<br>• the list export uses `fputcsv` without neutralisation;<br>• `Format::csvCell` checks only the first character (`/^[=+\-@\t\r]/`): no leading-space / newline handling | N-07 | 1 / 7 | n/a (static text); the executable controls belong to T0-06b | **PASS** |
| **T0-06b** | CSV `Format::csvCell` pure-unit execution (`" =1"`, `"\n=1"`, `"-12.5"`, `"=1+1"`, `"@SUM"`) | N-07 | 0 / 0 | — | **NOT TESTED** (needs PHP; §T0.8) |
| **T0-07** | **NEW-7 key-use inventory:** 50 lines across 16 files mention `db_pass`:<br>• `hash_hmac` 2 (Admin staff fingerprint + remember cookie);<br>• `sha1` 15 (customer cookie ×2, OTP file names ×13);<br>• `md5` 5 (voucher / invoice signatures);<br>• the rest are config / connection / redaction / ApiToken.<br>Also confirmed: customer cookie compared with loose `==`; no lower bound on its age (a future time is accepted); `?uid=` copied into the cookie (`boot.php`); no template references `db_pass` | NEW-7, N-03 | 1 / 8 | positive: `ApiToken` refuses `api_secret == db_pass` | **PASS** |
| **T0-08** | **Smarty inventory** (227 templates in `ui/`, `system/plugin`, `system/paymentgateway`):<br>• **185** templates call static classes (`Lang::T` 3531, `Text::url` 602, `Lang::moneyFormat` 97, `NuxrUi::money` 78, …, `App::getToken` 3);<br>• **67** templates use `$_c` (93 distinct keys);<br>• `boot.php` assigns the whole `$config` as `_c`;<br>• there is no `enableSecurity` / `Smarty_Security` / `escape_html` anywhere in runtime PHP;<br>• `pages.php` fetches from `raw.githubusercontent.com` | NEW-8 | 1 / 6 | positive: the static-call detector finds `{Lang::T` in a synthetic template | **PASS** |
| **T0-09** | PHPUnit execution of the existing source-guard suites (`Unit/Wp3a`, `Unit/Wp4`, `Unit/Wp8`) | all | 0 / 0 | — | **NOT TESTED** (needs PHP; §T0.8) |

**Totals:** 8 checks executed, 8 PASS, 0 FAIL, **146 assertions**, 2 NOT TESTED; run 1 is kept as a FAIL. **No test proved a new exploitable vulnerability, and none contradicts a WP8 contract**, so the stop rule was not triggered.

### T0.5 New observations (static, E1; none exploited)
| ID | Observation | Evidence | Assessed severity | Effect |
|---|---|---|---|---|
| O-T0-1 | Admin settings render secrets into HTML `value=` attributes: `smtp_pass`, `tawkto_api_key`, `github_token` | `ui/ui/admin/settings/app.tpl:829, 977, 1141` | LOW-MEDIUM (Admin-only screen; leaks through the page source / cache / screenshots) | extends **N-11**; add to the N-11 remediation and matrix row #23 |
| O-T0-2 | The installer seeds the application's RADIUS-database connection with the application DB credentials (`$radius_pass = $db_pass`, same host / user / name) | `install/step4.php:54-57` | LOW (deployment default: a single database) | WP10 KEY-SEP / LPDB note |
| O-T0-3 | OTP file names are `sha1(username . $db_pass)` in 13 places | `accounts.php:223-381`, `forgot.php:31, 70, 128`, `register.php:55, 206` | inside NEW-7 | widens the NEW-7 O7-A scope (OTP purpose key) |
| O-T0-4 | `csvCell` neutralises only a first character in `= + - @ \t \r` | `Format.php:117-122` | inside N-07 | N-07 remediation must handle a leading space / newline |
| O-T0-5 | The audit table has one INSERT writer and no UPDATE / DELETE in code; the database does not enforce this | T0-05 | inside TAMPER | unchanged: LPDB (WP10) |

The A2-R characterisation (T0-01 / T0-02) **confirms PLAN §6.1 and adds nothing new**. In particular, the `n=0;` guard skip (the F2 premise) and the unchecked release / accounting results are the only paths that do not end in a sentinel reject. **F1–F3 remain hypotheses; the exploitability of A2-R remains UNVERIFIED.** The production classification is unchanged.

### T0.6 Effect on the remediation plans (PLAN §6, §7, §9, §15)
- **A2-R:**
  - The decision table supports the layered design. L1 (CHECKs) alone cannot address the `n=0;` path, which needs L2 (echo) or L3 (SAFE flag).
  - The unchecked L / accounting results stay an availability residual (orphan PENDING).
  - No change to the official classification.
- **OBS-1 (AM-W9-1):**
  - T0-03 confirms the premises of the corrected lock design:
    - `Tx.php` is pinned, so it must stay unchanged;
    - P1 can move from `Tx::withTreeLock` to `TreeSerialization::hold` → `CustomerIdentity::hold` → `Tx::run` with no test or pin impact;
    - none of the five OBS-1 writer files is pinned.
- **NEW-7:** the O7-A key-purpose list is now complete. It covers the staff fingerprint and remember cookie, the customer cookie, invoice / voucher signatures, OTP file names (13 uses), and links. The connection, config and redaction uses of `db_pass` are not key uses.
- **NEW-8:**
  - O8-A (a policy scoped to page templates with a static-class allow-list) is confirmed as the compatible option.
  - O8-B (global) would need an allow-list of at least 12 static classes / methods and would touch 185 templates.
  - `$_c` exposes secret-like keys to templates (see O-T0-1).
- **N-07:** fix all three legacy exports (quote escaping + formula neutralisation), and harden `csvCell` for a leading space / newline.
- **N-11:** add the settings secrets (O-T0-1).
- **SEC-22:** the only `eval` sinks are the three `html_php` widgets (SuperAdmin `SYSTEM_CODE`). The decision is unchanged.

### T0.7 Proof of the lock-ordering correction (AM-W9-1)
- **The corrected design** is PLAN §7.3:
  - global order tree → voucher username → identity → `BEGIN`;
  - named locks only outside a transaction;
  - the identity lock never held across a device, network or RADIUS-DB call;
  - commit before releasing the identity lock;
  - the P5 revert path takes the identity lock and does not revert if an old name was taken.
- **The premises were verified statically by T0-03** (assertions on `Tx.php`, `TreeSerialization.php`, `CustomerService.php` and the test tree, all PASS).
- **The design was not executed.** Its concurrency proof is the T1 plan in PLAN §7.3: parallel P1×P2 / P1×P3 / P2×P3 / P4×P1 / P5×P1, lock timeout, nested lock, `hold()` inside a transaction, under RR and RC. That needs approval. **U0 is not implemented.**

### T0.8 NOT TESTED items and the alternative for approval
The two items that execute PHP (T0-06b and T0-09) were not run. Options:

| Option | What | Environment |
|---|---|---|
| **A (recommended)** | Run `tests/vendor/bin/phpunit` for the pure-unit / source-guard suites only (new `tests/Unit/Wp9` csvCell cases + existing `Unit/Wp3a`, `Unit/Wp4`, `Unit/Wp8` source guards), inside the existing image `nuxbill-reseller-test-php82` | **one** `docker run --rm --network none` container, repository mounted **read-only**, no compose project, no DB / FreeRADIUS container, `--do-not-cache-result` |
| B | Install a PHP 8.2 CLI on the host | **not recommended**: a package change on the shared AVAIA host |
| C | Leave both items to T1 (which runs inside the dual-engine harness anyway) | harness containers |

### T0.9 Git and workspace state
- **Branch and commits:**
  - branch `wp9/red-team-rc`, HEAD `143c4cd1` (one local commit: `PLAN.md` + `WP9_HANDOFF.md`), parent `704031c6`;
  - **no upstream; nothing pushed;** the remote has no `wp9` branch;
  - remote refs unchanged: `feat` and `wp8` `704031c6`, `main` `500d3a2d`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `hotfix` `6d4f3f96`.
- **Uncommitted (by instruction):**
  - `dev/wp9/t0_static.py` (the T0 checker);
  - `owner-review/WP9/evidence/t0/` (4 files: run 2 output and results, run 1 kept);
  - `owner-review/WP9/REPORT.md` (this file).
- **Unchanged:**
  - no product code, schema, migration, SourceGuard pin, test file, database, RADIUS or RouterOS change;
  - the tree fingerprint is identical before and after the run;
  - manifest 42/42;
  - secret sweep of the evidence and the checker: 0 hits;
  - no `__pycache__` left.

**`WP9_T0_EVIDENCE_READY_FOR_OWNER_REVIEW`** — `PRODUCTION_NOT_AUTHORIZED` — `STOPPED_WAITING_OWNER_REVIEW`.

---

## T0+ — PHP pure-unit / source-guard completion (2026-10-15)

Owner decision: `WP9_T0_PHP_COMPLETION_AUTHORIZED` (Option A of §T0.8).

### T0+.1 Environment (one container, locked down)
`docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=64m --cap-drop ALL --security-opt no-new-privileges --pids-limit 64 --memory 512m --user 65534:65534 -v /srv/phpnuxbill/dev:/repo:ro -w /repo nuxbill-reseller-test-php82:latest php tests/vendor/bin/phpunit -c tests/phpunit.xml --do-not-cache-result --testdox <files>`

- Existing image `nuxbill-reseller-test-php82` (`sha256:2fa4fbfe…6ade`): no network; root filesystem read-only; repository mounted read-only; all capabilities dropped; non-root user `nobody`; no Docker socket; no host credentials or service mounts; no Compose; no DB / FreeRADIUS container; only a 64 MB tmpfs `/tmp` writable. No container was left (`docker ps -a` = 0) and no result cache was written.
- **Test discovery:** the 15 files of `tests/Unit/Wp3a`, `Unit/Wp4`, `Unit/Wp8` all extend `PHPUnit\Framework\TestCase`; every service-like match (`ORM::`, `curl_`, `exec(`) in them is a string literal inside a source pattern, not a call; none writes files. **Only the 8 source-guard files were run** (scope: "pure source-guard tests"); the 7 other pure unit files (`MoneyTest`, `RadiusPacketTest`, …) were not part of the authorization and were not run.
- Tree fingerprint (`system`, `install`, `ui`, `tests` without vendor, `docs/claude-development-pack`, root `*.php`): before = after = `4ef70037f62767d830babcacca0d7f4aeab688af628819993d65f6a4016372f0` (1847 files) — `evidence/t0plus/fingerprint-{before,after}.txt`.

### T0+.2 Results
| ID | Files | Exit | Tests / assertions | Controls | Result | Evidence |
|---|---|---|---|---|---|---|
| **T0-09** | `Unit/Wp3a/SourceGuardTest`, `Unit/Wp4/{AdmissionSourceTest, HotspotAdmissionSourceTest, HotspotSpellingSourceTest, RadiusSourceGuardTest, SessionGuardSourceTest}`, `Unit/Wp8/{DriverClassificationSourceTest, LegacyRemovalSourceTest}` | **0** | **51 / 37 766** | the guards' own mutation / pin controls (e.g. "Accepted files are byte identical") | **PASS** | `evidence/t0plus/t0-09-sourceguards.out` |
| **T0-06b** | new `tests/Unit/Wp9/CsvCellCharacterizationTest.php` (characterisation of `Format::csvCell`, `Format.php` sha256 `b656f968…28bd6`, **unchanged**) | **0** | **23 / 27** | 9 neutralisation controls (`=`, `+`, `-`, `@`, TAB, CR, DDE, hyperlink, `+5`) and 10 unchanged-value controls (numbers, text, Arabic, empty, null, booleans, formula character later) | **19 controls PASS; 4 DEFECT_REPRODUCED** | `evidence/t0plus/t0-06b-csvcell.out` |

**DEFECT_REPRODUCED (N-07 / O-T0-4), characterisation only — not a security acceptance:** `Format::csvCell` leaves a formula unneutralised when it is preceded by whitespace: `" =1+1"`, `"\n=1+1"`, `" @SUM(1)"`, `" =1+1"` are returned unchanged. Whether a spreadsheet then evaluates them depends on the spreadsheet (exploitability unverified). The test asserts the current output explicitly; `Format.php` was not modified.

No new security violation and no unexplained failure → the condition for T1-A was met.

---

## OBS-1 design check before T1-A (owner §3)

The rev-3 `assertAvailable` sketch compares stored names with the trimmed new name by column equality (`username IN (?,?) …`). The question was whether stored names that begin with whitespace are covered. T1-A file 1 answers it on both engines (§T1-A.3): **they are not**. Under `utf8mb4_general_ci` a stored `" wpq"` is **not** equal to `"wpq"` (leading space is significant; trailing spaces are ignored by PAD SPACE), and SQL `TRIM()` removes spaces only, while `RadiusPolicyBuilder::key()` uses PHP `trim()` (also TAB, LF, CR, NUL, VT). So a correct check must compare **canonical keys** (the PHP `trim()` form, under the RADIUS collation) of both the new and every stored name, not column equality. Design consequence for U0 (not implemented): canonicalise stored names in the comparison (e.g. a canonical key maintained by the application, or a read of candidate rows compared in PHP with the same `key()` rule), or clean legacy whitespace names first (survey + operator plan); U3 (identity table holding canonical keys) is the structural form. The case set of the owner (leading / trailing whitespace, case, accent, cross-field, historical duplicates, other collations) is implemented in `tests/Integration/Wp9/T1aIdentityCharacterizationTest.php`.

---

## T1-A — OBS-1 / L-A5-2 / voucher-namespace characterisation (2026-10-15)

Owner decision: `WP9_T1A_CHARACTERIZATION_AUTHORIZED_CONDITIONALLY`. **Result: `WP9_T1A_BLOCKED_WITH_EVIDENCE`** — the run stopped at the first proven owner stop condition (V1, §T1-A.5).

### T1-A.1 Environment
- `dev/wp9/t1a_run.sh` (new, test-only): **only** `mariadb` (10.11) and `mysql` (8.0) of the `nuxbill-reseller-test` compose project (tmpfs storage, no published ports), throwaway credentials generated into a 0600 file in the session scratchpad and passed through the environment (never on a command line or in evidence; file deleted at teardown). Tests run with the existing harness runner image (`docker compose run php82 … phpunit --do-not-cache-result --testdox <one file>`), one file and one engine at a time. **No FreeRADIUS container, no CHR / MikroTik, no load, no SQL fault / pool / connection-failure injection.**
- Tests (new, uncommitted): `tests/Integration/Wp9/Wp9Evidence.php` (evidence helper: JSON lines to `$WP9_EVIDENCE`; state deltas split into **business** = every table except `tbl_logs` / `tbl_reseller_audit_events`, and **audit**), `T1aIdentityCharacterizationTest.php` (file 1), `T1aLegacyPathsTest.php` (file 2), `T1aVoucherV1Test.php` (file 3). Synthetic data only (disposable databases built by the harness).
- Product fingerprint (`system`, `install`, `ui`, `docs/claude-development-pack`, `dev/test`, root `*.php`): before = after = `c37ad1f85c3b87966738e0e0f1c22d56912f2278da1132bc0e279c25a360372e` (1633 files) — `evidence/t1a/product-fingerprint-{before,after}.txt`.

### T1-A.2 Runs (every run kept)
| # | File | Engine | Exit | Tests / assertions | Note | Evidence |
|---|---|---|---|---|---|---|
| 1 | file 1 | MariaDB | 0 | 7 / 66 | **test-design defect**: 3 P1 cases refused `INVALID_INPUT` because the generated `fullname` exceeded 45 characters → those cases were invalid; fixed (short fullname) | `run1-file1-mariadb-INVALID-fullname.jsonl` |
| 2 | file 1 | MariaDB | 0 | 7 / 66 | superseded by run 5 (same code as the MySQL error run) | `run2-file1-mariadb-pass-superseded.jsonl` |
| 3 | file 1 | MySQL | 2 | 7 / 57, 1 error | **test-design defect**: MySQL error 1137 (a TEMPORARY table referenced twice in one query); console of this first run not kept | `run1-file1-mysql-ERROR-console-not-kept.jsonl` |
| 4 | file 1 | MySQL | 2 | 7 / 57, 1 error | same error, console kept; fixed (two statements) | `console-mysql-T1aIdentityCharacterizationTest-20261015T163118Z.out`, `run2-file1-mysql-ERROR-1137.jsonl` |
| 5 | file 1 | MariaDB | **0** | **7 / 66** | final | `console-mariadb-T1aIdentityCharacterizationTest-20261015T163137Z.out`, `obs-mariadb.jsonl` |
| 6 | file 1 | MySQL | **0** | **7 / 66** | final | `console-mysql-T1aIdentityCharacterizationTest-20261015T163144Z.out`, `obs-mysql.jsonl` |
| 7 | file 2 | MariaDB | **0** | **6 / 35** | final | `console-mariadb-T1aLegacyPathsTest-20261015T163311Z.out`, `obs-mariadb.jsonl` |
| 8 | file 2 | MySQL | **0** | **6 / 35** | final | `console-mysql-T1aLegacyPathsTest-20261015T163332Z.out`, `obs-mysql.jsonl` |
| 9 | file 3 (V1) | MariaDB | **0** | **1 / 3** | **STOP_TRIGGER recorded → T1-A stopped** | `console-mariadb-T1aVoucherV1Test-20261015T163416Z.out`, `obs-mariadb.jsonl` |
| — | file 3 (V1) | MySQL | — | — | **NOT RUN** (owner stop rule) | — |
| — | V4 (two voucher codes equal under the collation); reverse direction (an owned colliding customer activated over another tree's key) | both | — | — | **NOT RUN** (owner stop rule; tests not written) | — |

Totals of the final runs: MariaDB 14 tests / 104 assertions (files 1–3), MySQL 13 tests / 101 assertions (files 1–2). Classifications (final runs): **MariaDB** 24 CONTROL_PASS, 10 OBSERVED, 16 DEFECT_REPRODUCED, **1 STOP_TRIGGER**; **MySQL** 23 CONTROL_PASS, 10 OBSERVED, 16 DEFECT_REPRODUCED (identical to MariaDB for files 1–2).

### T1-A.3 Identity semantics (file 1, both engines identical)
| Case (stored vs probe) | Column `=` (general_ci) | Note |
|---|---|---|
| exact, case, trailing space (stored or probe), accent (`é`/`e`) | equal | collation: case-, accent-insensitive, PAD SPACE |
| **leading space stored** (`" wpq"` vs `"wpq"`) | **not equal** | **DEFECT_REPRODUCED** on `tbl_customers.username`, `pppoe_username` and `radcheck.username`: equal RADIUS key after `trim()`, unequal for a column check |
| trailing TAB / LF / NBSP stored | not equal | TAB / LF also removed by PHP `trim()` (NBSP is not) |
| distinct names (negative control) | not equal | CONTROL_PASS |
- Deployed collation of all three identity columns: `utf8mb4_general_ci` (harness schema).
- Other collations (temporary tables): `utf8mb4_unicode_ci`, `utf8mb4_unicode_520_ci`, `utf8mb3_general_ci` and MariaDB `utf8mb4_uca1400_ai_ci` behave like general_ci on these cases (leading space significant, trailing space ignored). `utf8mb4_bin` is case- and accent-sensitive but still PAD SPACE (control). **MySQL `utf8mb4_0900_ai_ci` (NO PAD) treats a trailing space as significant** — an upgraded MySQL installation whose identity columns took that default would compare identities differently from `radcheck` (general_ci). `utf8mb4_0900_ai_ci` is unsupported on MariaDB 10.11 and `utf8mb4_uca1400_ai_ci` on MySQL 8.0 (error 1273, recorded). Full matrix in `obs-*.jsonl` ("equality under other collations").
- **SQL `TRIM()` vs PHP `trim()`**: identical for spaces; SQL keeps TAB, LF, CR, NUL, VT → **DEFECT_REPRODUCED** ("trim mismatch").
- **Read-only historical survey (PLAN §7.4) on synthetic duplicates**: no state change (control); finds cross-field case, trailing-space, leading-space and accent duplicates; **misses the trailing-TAB duplicate** (DEFECT_REPRODUCED); its whitespace query finds the leading-space row but not trailing-space rows (PAD SPACE).

### T1-A.4 Creation / rename paths and voucher namespace (files 1–2, both engines identical)
| Path / case | Current behaviour | Classification | Business delta | Audit delta |
|---|---|---|---|---|
| P1 Admin create, username = existing username (upper case) | refused `USERNAME_TAKEN` | CONTROL_PASS | none | none |
| P1 Admin create, pppoe = another customer's username | **created** | DEFECT_REPRODUCED | `tbl_customers` | audit row |
| P1 Admin create, username = another customer's pppoe | **created** | DEFECT_REPRODUCED | `tbl_customers` | audit row |
| P1 Admin create, pppoe = another's pppoe (upper case) | **created** | DEFECT_REPRODUCED | `tbl_customers` | audit row |
| P1 **Agent A**, pppoe = username of a customer of **tree C** | **created** (cross-tree name collision; no write to the other customer) | DEFECT_REPRODUCED | `tbl_customers` | audit row |
| P1 Sales A1, username = that pppoe | refused `USERNAME_TAKEN` — because an earlier case had already created a customer with that username (sequence effect) | OBSERVED | none | none |
| P1 distinct names | created | CONTROL_PASS | `tbl_customers` | audit row |
| P2 public `register/post`, username = another's pppoe | **created** | DEFECT_REPRODUCED | `tbl_customers` | **none** (registration writes no audit row) |
| P2 username = existing username (upper case) / fresh name | refused / created | CONTROL_PASS | none / `tbl_customers` | none |
| P3 voucher + new username = another's pppoe or username (upper case) | refused `USERNAME_TAKEN` | CONTROL_PASS | none | none |
| P3 new username = trimmed form of a stored leading-space username | **GRANTED** | DEFECT_REPRODUCED | `tbl_customers`, `tbl_transactions`, `tbl_voucher` (Probe plan, no RADIUS) | none |
| P4 Admin edit (unassigned): pppoe = another's username; username = another's pppoe | refused (unchanged) | CONTROL_PASS | none | none |
| P4 pppoe = trimmed form of a stored leading-space username | **changed** | DEFECT_REPRODUCED | `tbl_customers` | none |
| P4 case-only rename of the customer's own username | refused | OBSERVED — **false refusal** (no self-exclusion, `customers.php:716-722`) | none | none |
| P5 Admin IDENTITY_EDIT (owned): rename to another's username / pppoe (incl. upper case) | refused `USERNAME_TAKEN` / `PPPOE_USERNAME_TAKEN` | CONTROL_PASS | none | none |
| P5 pppoe = trimmed form of a stored leading-space / trailing-TAB username | **accepted** (no active plan, so no device step) | DEFECT_REPRODUCED | `tbl_customers` | audit row |
| V3 voucher-only code = an **owned** customer's pppoe (exact / upper) or username | refused | CONTROL_PASS | none | none (refusal is logged, not audited) |
| V3 fresh voucher-only code | granted, RADIUS rows written | CONTROL_PASS | `radcheck`, `radreply`, `radusergroup`, `tbl_transactions`, `tbl_user_recharges`, `tbl_voucher` | none |
| V2 Admin creates a customer whose username (upper case) or pppoe equals a **live voucher-only identity** | **created**; the voucher's RADIUS rows unchanged by the creation | DEFECT_REPRODUCED | `tbl_customers` | audit row |
| Concurrency P1 × P3 (2 processes, 3 rounds, crossing case) | one creation per round (P3 granted, P1 `USERNAME_TAKEN`) | OBSERVED — not a proof of safety (different locks) | — | — |

No refusal in these cases changed business state; expected audit writes were only those of accepted creations / renames.

### T1-A.5 STOP_TRIGGER — V1 (MariaDB, file 3) — **TEST-PROVEN (E4)**
**Case:** an **unassigned** (legacy) customer `w9v1…` holds its own RADIUS policy (granted through `Package::rechargeUser(customer id, …)`; control: `Cleartext-Password` = the customer password). A voucher row whose code **equals that username** (fixture) is redeemed through the public voucher-only path (`VoucherRedemptionService::redeem(code, RedemptionTarget::voucherOnly(code), RedemptionSource::public())`, the path of `login.php:135` and `radius.php:163`).
**Observed:** status `GRANTED`; the customer's exact-key `radcheck` row `Cleartext-Password` changed from **the customer password** to **the voucher code**; `Expiration` and `Simultaneous-Use` rows kept their values (same plan and day in this fixture); the `tbl_customers` row is unchanged. Business delta: `radcheck`, `tbl_transactions`, `tbl_user_recharges`, `tbl_voucher`; audit delta: none.
**Why:** `ServiceGate::isBlockedUsername()` (`ServiceGate.php:48-66`) refuses voucher codes equal to names of **reseller-owned** customers only; the voucher-only grant (`Package::rechargeUser(0, …, 'Voucher', code)` → Radius device) provisions the RADIUS key `code`, which the RADIUS tables treat as the same identity as the customer's username.
**Stop conditions met:** unauthorized RADIUS policy mutation; cross-identity overwrite (an existing subscriber's credential replaced by a voucher grant). **Not** a contradiction of a WP8 guarantee: WP8 protects reseller-owned customers (V3 confirms that protection) and its register already records that unassigned colliders overwrite each other (WP8 REPORT §5 #1); V1 is the voucher-namespace instance of that residual, now **proven** on MariaDB.
**Exploitability (static reading, not tested):** the code must equal an existing username under the collation. Codes are generated by Admins only (`VOUCHER_GENERATE` = G) as prefix + CSPRNG characters; the generator checks uniqueness against other vouchers only (`plan.php:871-880`, BINARY), never against customer names. Paths: an Admin choosing a prefix that yields an existing username, a random collision (e.g. numeric vouchers vs numeric / phone usernames), legacy codes on upgraded installations, or a customer registered (P2) / created (P1) with a name equal to an unused voucher code (V2). No unprivileged way to choose a code was found.
**Impact:** customer — locked out (its RADIUS password replaced) until its own grant re-provisions it; the voucher holder authenticates under the customer's RADIUS identity with the voucher code. RADIUS — one shared key, policy flapping between the two grants. Finance — no reseller-wallet effect (the customer is unassigned; owned customers are refused, V3); a legitimate voucher transaction and a voucher-only recharge row with the customer's username (customer id 0) are written, which legacy username-based lookups may mix with the customer's own rows (not tested).

### T1-A.6 Confirmed vs hypothetical
- **TEST-PROVEN (E4), both engines:** cross-field collisions accepted at creation (P1 Admin and Agent, P2 public registration); leading-space / trailing-TAB collisions accepted by P3, P4, P5; creation over a live voucher identity (V2); SQL `TRIM` ≠ PHP `trim`; the leading-space equality gap; survey misses TAB duplicates; P4 false refusal of a case-only self-rename.
- **TEST-PROVEN (E4), MariaDB only (MySQL not run under the stop rule):** **V1 RADIUS credential overwrite** of an unassigned customer by a voucher-only grant.
- **Not tested (hypotheses, E3):** the reverse direction (an owned colliding customer's activation overwriting another tree's RADIUS key — recorded in the A3 report §6); V4 (two voucher codes equal under the collation); the concurrency race between P1 and P3 (3 rounds showed none); the effect of the voucher-only recharge row on legacy username lookups.

### T1-A.7 Exact affected files (no change made)
- `system/autoload/Reseller/Domain/VoucherRedemptionService.php` (voucher-only grant path; the gate it uses)
- `system/autoload/Reseller/Domain/ServiceGate.php` (`isBlockedUsername`, owned-only; **SHA-pinned**)
- `system/autoload/Package.php` (voucher-mode `rechargeUser`; **SHA-pinned**)
- `system/devices/Radius.php` (provisions the key it is given)
- `system/controllers/plan.php` (`voucher-post`: code uniqueness against vouchers only)
- `system/autoload/Reseller/Domain/CustomerService.php` (P1), `system/controllers/register.php` (P2), `VoucherRedemptionService::redeemForNewUsername` (P3), `system/controllers/customers.php` edit-post (P4), `system/autoload/Reseller/Domain/LegacyReprovisionService.php` (P5)
- `system/autoload/Reseller/Radius/RadiusPolicyBuilder.php` (`key()`: PHP `trim()` canonical form)

### T1-A.8 Proposed minimal remediation scope (for owner decision; nothing implemented)
1. **Voucher-only grant guard (closes V1):** in `VoucherRedemptionService` (not pinned), refuse a voucher-only redemption when the code equals — by the canonical key rule — the `username` or `pppoe_username` of **any** customer (not only reseller-owned), before claim and grant; covers `login.php:135` and `radius.php:163`. No change to the pinned `ServiceGate` / `Package` needed.
2. **Voucher generation:** in `plan.php voucher-post`, also regenerate a code that equals a customer name (canonical key).
3. **Creation / rename (OBS-1, U0 with the AM-W9-1 lock order):** the shared `CustomerIdentity` check must compare **canonical keys** (PHP `trim()` + RADIUS collation) of new and stored names, cross-field, and also against live voucher-only identities (V2); applied to P1–P5; P4 self-exclusion fix.
4. **Legacy data:** read-only survey with a canonical comparison (the §7.4 queries miss TAB / other whitespace) before any uniqueness option (U1–U4).
5. **Re-test** (both engines): V1 (MariaDB + MySQL), V4, the reverse direction, and the full T1-A set after the fix.

### T1-A.9 Evidence, integrity and cleanup
- Evidence: `owner-review/WP9/evidence/t0plus/` (4 files), `owner-review/WP9/evidence/t1a/` (14 files: 6 console logs, 2 final observation files, 4 kept defective-run observation files, 2 product fingerprints). Observation files never contain fixture passwords (V1 values are redacted to their role).
- Product fingerprint identical before / after; manifest 42/42; all 23 SourceGuard pins match; `git diff HEAD` on tracked product / test files: empty (only `PLAN.md` / `WP9_HANDOFF.md` carry dated status notes); secret sweep over all 30 new files: 0 hits; no generated credential in the console logs.
- Cleanup: `dev/wp9/t1a_run.sh down` → 0 containers, 0 networks, 0 volumes of `nuxbill-reseller-test`; throwaway credential file deleted; no T0+ container left; no CHR / QEMU.
- Git: branch `wp9/red-team-rc`, HEAD `143c4cd1` (unchanged), no upstream; nothing committed in this round; protected remote refs unchanged (`feat` / `wp8` `704031c6`, `main` `500d3a2d`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `hotfix` `6d4f3f96`); no remote `wp9` branch.

**`WP9_T1A_BLOCKED_WITH_EVIDENCE`** — `PRODUCTION_NOT_AUTHORIZED` — `STOPPED_WAITING_OWNER_REVIEW`.

---

## A1 — WP9-A1 bounded remediation of OBS-W9-V1 (2026-10-15)

Owner decision: `WP9_A1_V1_BOUNDED_REMEDIATION_AUTHORIZED` (with `OBS_W9_V1_HIGH_PRODUCTION_BLOCKER`). **Result: `WP9_A1_V1_REMEDIATION_READY_FOR_OWNER_REVIEW` — V1 is LOCALLY MITIGATED; the security invariant is NOT fully demonstrated** (a check-to-grant window remains, §A1.6).

### A1.1 Evidence checkpoint (before any product change)
Local commit **`c9469f9ef938d79aef8e67c6e025d01b5c83c9a5`** ("test(wp9): record T0, T0+ and T1-A characterisation evidence"; parent `143c4cd1`; 32 files: report, plan / handoff status notes, WP9 tests, `dev/wp9` tooling, `evidence/{t0,t0plus,t1a}` including the kept failed / invalid runs). Audit before it: no unexpected file; no product path; secret sweep 0 hits (32 files); manifest 42/42; 23 SourceGuard pins match; raw console evidence kept byte-for-byte (its trailing whitespace is the captured output). Not pushed.

### A1.2 Confirmed BEFORE the fix
| Evidence | Engine | Result |
|---|---|---|
| T1-A file 3 (V1), 2026-10-15 16:34Z | MariaDB 10.11 | voucher-only `GRANTED`; the unassigned customer's `Cleartext-Password` replaced by the code (T1-A §T1-A.5) |
| **Authorized one-time reproduction**, `T1aVoucherV1Test` | **MySQL 8.0** | **`GRANTED`; customer RADIUS policy changed** (business delta `radcheck`, `tbl_transactions`, `tbl_user_recharges`, `tbl_voucher`) — `evidence/a1/console-mysql-T1aVoucherV1Test-20261015T164944Z.out`. V1 is therefore TEST-PROVEN on **both** engines (RADIUS-table effect; **no real NAS login demonstrated**) |
| Test-first run of the new regression `A1VoucherIdentityGuardTest` (before the fix) | MariaDB | 16 tests / 47 assertions, **12 failures**, exit 1: all 10 collision variants `GRANTED` with the customer policy overwritten (exact, case, stored leading / trailing space, stored TAB, accent, code with trailing space, PPPoE cross-field exact / case / stored leading space); replay test and generation test failed (a colliding voucher was issued); the 3 controls passed; the race characterisation showed RACE_REMAINS — `console-mariadb-A1VoucherIdentityGuardTest-20261015T165010Z.out` |
| First attempt of that run | MariaDB | fatal before any test (`customer()` helper name clashed with the base class — test-design defect, renamed `person()`), kept: `…T164958Z.out` |

### A1.3 What the fix changed (only the authorized files)
`system/autoload/Reseller/Domain/VoucherRedemptionService.php` (+83 lines) and `system/controllers/plan.php` (+25 lines); diff in `evidence/a1/a1-fix.diff`.
1. **Redemption guard** (`redeem()`): for a **voucher-only** target (`customerId === 0`), after the existing reseller-owned check and **before the claim**, `identityInUse()` refuses (`REFUSED`, logged as `REFUSED:IDENTITY_IN_USE`) when the code's RADIUS identity is in use. The voucher is never claimed, so it stays redeemable; no recharge, invoice, RADIUS or device write happens. Existing-customer redemptions (`customerId > 0`), `redeemForNewUsername()`, the claim / grant / `CLAIMED_REVIEW` / release semantics and the single-path guards are unchanged.
2. **`customerIdentityCollisions(array $names): ?array`** (public, new): the canonical RADIUS identity rule — PHP `trim()` (space, TAB, LF, CR, NUL, VT; exactly `RadiusPolicyBuilder::key()`) compared under `utf8mb4_general_ci` (the `radcheck.username` collation), against **both** `username` and `pppoe_username` of **every** customer (owned or unassigned). Two statements, no schema change: one scan of `tbl_customers` returning (as HEX, so no value crosses the connection character set) the canonical form of rows whose column equals a name under the RADIUS collation or starts / ends with a trimmed character; then a collation comparison of the names with those canonical forms. **Fails closed** (returns null → refusal) on any error, an empty canonical name, or more than 1000 candidate rows.
3. **Voucher generation** (`plan/voucher-post`): after the existing CSPRNG uniqueness loop, the batch is checked in one call; colliding codes are regenerated with the same CSPRNG expressions (unique within the batch and among vouchers, bounded: 20 rounds × 20 tries); if a collision remains or the check cannot run, **no voucher of the batch is created** (error redirect). CSPRNG, batch uniqueness and the existing bounded retry are kept.
- **Not changed:** `ServiceGate.php`, `Package.php`, `Tx.php`, every SHA-pinned file, schema / migrations, `phpunit.xml`, the Architecture Lock.
- **Performance justification (for review):** `tbl_customers` has no index on `username` / `pppoe_username` (`install/phpnuxbill.sql`), so the existing `ServiceGate::isBlockedUsername()` already scans the table on every voucher-only redemption; the fix adds **one more scan** per voucher-only redemption (plus a small comparison over the matched candidates) and one scan per generation round. No load test was run (WP10, OD-W9-11).

### A1.4 What was tested after the fix, per engine
| Test | MariaDB 10.11 | MySQL 8.0 | Evidence |
|---|---|---|---|
| `A1VoucherIdentityGuardTest` (16 tests) | **16 / 78, exit 0** | **16 / 78, exit 0** | `console-*-A1VoucherIdentityGuardTest-20261015T1651{24,33}Z.out`, `obs-*.jsonl` |
| — 10 collision variants (listed in §A1.2) | all `REFUSED`, voucher `0`, **no business / finance / identity / RADIUS delta**, customer policy unchanged (CONTRACT_MET) | same | |
| — legitimate voucher-only grant | `GRANTED`, voucher `1`, RADIUS rows written (CONTROL_PASS) | same | |
| — existing-customer voucher path with a code equal to **another** customer's name | `GRANTED` to the target; the other customer's policy unchanged (CONTROL_PASS, no over-blocking) | same | |
| — reseller-owned V3 refusal | `REFUSED`, no business delta (CONTROL_PASS) | same | |
| — refused voucher stays redeemable; replay | `REFUSED`, `REFUSED`, then `GRANTED` once the colliding name is renamed away, then `NOT_VALID` (CONTRACT_MET) | same | |
| — voucher generation | control batch of 3 unique codes created; a batch whose every possible code equals a customer username → **0 vouchers** (CONTRACT_MET) | same | |
| — check-to-grant window (barrier inside `Package::rechargeUser`, hook `recharge_user`) | **RACE_REMAINS**: a customer + RADIUS password committed after the check is overwritten by the voucher grant | same | |
| `T1aVoucherV1Test` (the original V1 reproduction) | `REFUSED`, policy unchanged, no business / audit delta (exit 0) | same | `console-*-T1aVoucherV1Test-20261015T1652*.out` |
| Existing suites via the standard harness `dev/test/run-tests.sh --do-not-cache-result --testsuite unit,wp3a-unit,wp4-unit,wp5b-unit,regression,wp2-http,wp4-integration,wp5b-integration,wp8-integration` (incl. `VoucherRedemptionTest`, `Wp5bSourceGuardTest`, `ServiceGateTest`, the WP4 RADIUS suites, `RechargePreWriteRefusalTest`, `DeviceGuardKeyCollisionTest`, SourceGuard pins) | **603 tests / 49 703 assertions, 0 failures / errors, 0 warnings, 94 incomplete** | **603 / 49 703, same** | `evidence/a1/regression-run-tests.out` (exit 0; variant registry reproducible) |
- The 94 incomplete tests per engine are **not passes**; they are consistent with the lab-gated WP8 real-NAS tests, but this non-testdox run does not list them individually.
- Not run (not warranted by the two-file change, or not authorized): the other WP suites (`wp2/3a-integration`, `wp3b`, `wp5a`, `wp6*`, `wp7`), the full harness, `wp8-security-gates`, CHR, browser lab, load.
- The WP8 guarantees for reseller-owned customers remain intact (V3 control; `ServiceGateTest`, `RechargePreWriteRefusalTest`, `DeviceGuardKeyCollisionTest` green on both engines).
- Images: the A1 runner runs used `nuxbill-reseller-test-php82` `sha256:2fa4fbfe…6ade`; the standard harness rebuilt it during the regression run (`sha256:888a6ebc6c04…84a`; `dev/test` unchanged, PHP 8.2.34 in both).

### A1.5 Fingerprints and SHA
| Item | Value |
|---|---|
| Product fingerprint before the fix (`system`, `install`, `ui`, `docs/claude-development-pack`, `dev/test`, root `*.php`) | `c37ad1f85c3b87966738e0e0f1c22d56912f2278da1132bc0e279c25a360372e` (1633 files) |
| Product fingerprint after the fix | `6ff47e19aa406375d9d8c600501878d8470f68e4f777ad3f9715a28143f74828` (1633 files; differs only by the two files below) |
| `VoucherRedemptionService.php` before → after | `a2f342b392ceda4fefa1a35fdf6fd01bfc97e188064f20dbe9464ac56b8ef0aa` → `58d49940f9a76029b8675695bca2e146408177a0aadb8c4f06768816a75fbd14` |
| `plan.php` before → after | `2a7e99df97de8a05865d3604af3765be126574bace6d1f2adaf59b81069e54bc` → `c431414c0ddf1402089da49a0f922c396f4f4818dcee2cb53fee99e622dd4ed5` |
| Manifest / SourceGuard | 42/42; 23 pins all match (no re-pin) |

### A1.6 What remains unresolved
- **Check-to-grant window (TOCTOU) — V1 is only locally mitigated.** The identity check runs before the claim and grant without a lock shared with customer creation (P1–P5 take the tree lock, the voucher user lock or none). A customer that is created **and** provisioned between the check and the voucher grant is still overwritten (RACE_REMAINS on both engines, deterministic barrier). Closing it needs the shared identity lock of AM-W9-1 (U0, not authorized here) taken by both the voucher-only grant and every customer writer — a wider change than this wave, so it was not implemented.
- **V2** (a customer created over a live voucher-only identity) is unchanged: creation paths are not part of this wave (U0).
- **Voucher codes in logs (pre-existing, LOW):** for PUBLIC / RADIUS sources the existing `record()` log line includes `target=<username>`, which for voucher-only targets is the code in clear (the `voucher=` field is hashed); the new refusal uses the same line. Recorded, not changed.
- **Fail-closed operational notes:** more than 1000 customers with leading / trailing whitespace in their names, or a database without `REGEXP_REPLACE`, would refuse every voucher-only redemption and every voucher batch (MariaDB 10.11 / MySQL 8.0 support it).
- Deferred as instructed: V4, the reverse-direction overwrite, U0 across P1–P5; OBS-1 stays a **PRODUCTION BLOCKER**; the 4 csvCell defects join N-07; A2-R stays `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`; P1–P5 policies, NEW-7, NEW-8, plugins, WP1-CHECK unchanged; T1-B, T2, T3, T4, WP10 not authorized.

### A1.7 Cleanup, secret sweep, Git
- Teardown: 0 containers / networks / volumes of `nuxbill-reseller-test`, no QEMU; the A1 runner credential file deleted; the standard harness removed its own containers.
- Secret sweep over all new / changed files (18): 0 hits; no generated credential in any evidence file.
- Git: branch `wp9/red-team-rc`, HEAD **`c9469f9e`** (the evidence checkpoint); the A1 fix, the new regression test, the runner change and `evidence/a1/` are **uncommitted** (no commit authorized for them); no upstream; nothing pushed; protected remote refs unchanged (`feat` / `wp8` `704031c6`, `main` `500d3a2d`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `hotfix` `6d4f3f96`); no remote `wp9` branch.

**`WP9_A1_V1_REMEDIATION_READY_FOR_OWNER_REVIEW`** — V1 locally mitigated, invariant not fully demonstrated — `PRODUCTION_NOT_AUTHORIZED` — `STOPPED_WAITING_OWNER_REVIEW`.


### A1.8 Owner interim decision and final pre-commit audit (2026-10-15)
Owner decisions: **OD-W9-A1-1 `V1_INTERIM_MITIGATION_ACCEPTED — RACE_OPEN`** (one local commit of the fix and its evidence after a final diff audit); **OD-W9-A1-2 `U0_SHARED_IDENTITY_LOCK_DESIGN_AUTHORIZED — IMPLEMENTATION_BLOCKED`**; **OD-W9-A1-3** voucher-code logging recorded under N-07 (severity to be re-assessed against log read access and voucher state; logging unchanged); **OD-W9-A1-4** V4, the reverse direction and T1-B…T4 stay deferred (read-only path analysis for U0 allowed).

Final audit (from HEAD `c9469f9e`, before the commit):
- **Diff identity:** `git diff system/` is byte-identical to the tested `evidence/a1/a1-fix.diff`; working-tree SHA-256 of the two product files equal `changed-files-sha256-after.txt` (`58d49940…`, `c431414c…`). Only the authorized files changed (two product files, `dev/wp9/t1a_run.sh` evidence-directory override, the new test, the report, `evidence/a1/`).
- **Unchanged paths (code read):** the guard is conditional on `customerId === 0` and runs after the unchanged reseller-owned check (V3) and before `claim()`; existing-customer redemption (`customerId > 0`) does not reach it; `redeemForNewUsername()` does not call `redeem()` and is unchanged; `claim()` / `grant()` / `CLAIMED_REVIEW` / release are unchanged.
- **Refusal semantics:** refusal returns before `claim()` (voucher stays `0`, test-proven with replay `REFUSED, REFUSED, GRANTED, NOT_VALID`); only the existing redemption log line is written; every error / empty name / bound overflow → `null` → refusal (fail closed); generation: `null` or a remaining collision → no voucher of the batch is inserted (the check precedes the first insert).
- **Availability limit (OPEN, not a solution):** the 1000-candidate-row bound is an availability limit, not a performance or concurrency solution; both lookups scan the unindexed `tbl_customers.username` / `pppoe_username`; no performance figure is claimed (no measurement).
- **Pre-existing, not introduced (recorded):** after 20 regeneration tries a code may keep a batch / table duplicate — identical to the WP5B (E6) loop it follows; the regenerated codes use the upper-case / numeric expressions also for `low` / `rand` formats — identical to WP5B.
- **Results:** MariaDB and MySQL A1 16 / 78 exit 0; `T1aVoucherV1Test` refused on both; harness 603 / 49 703 / 0 failures / **94 incomplete per engine — not counted as passes**.
- **Integrity:** `docs/claude-development-pack/MANIFEST.sha256` 42/42 OK; SourceGuard pins 23/23 match (neither changed file is pinned); no re-pin.
- **Secrets / artifacts:** secret sweep over the 20 files to be committed (known lab secret values from the WP8 state files + private-key markers): 0 hits; no fixture password in the evidence; all evidence files are text (96 KiB); no credential file, pcap, image or lab artifact staged.

---

## A1.9 V1 interim acceptance record and U0 design (2026-10-15, after the A1 commit)

| Item | Record |
|---|---|
| Owner decision | OD-W9-A1-1 `V1_INTERIM_MITIGATION_ACCEPTED — RACE_OPEN` |
| **Official V1 status** | **`V1_MITIGATED_NOT_CLOSED`** (OBS-W9-V1, HIGH) |
| Local commit | **`0f181323ad12220dcd6e584f98d3a6652ee2d824`** "wp9(a1): mitigate voucher-only identity collisions (race open)" (parent `c9469f9e`; 20 files; fix byte-identical to the tested `a1-fix.diff`; product fingerprint `6ff47e19…` = the tested after-fix fingerprint). Not pushed |
| What is mitigated | voucher-only redemption refused before the claim when the code's canonical RADIUS identity equals any customer's `username` / `pppoe_username` (both engines); colliding generated codes regenerated or the batch refused |
| What is open | the check-to-grant race (RACE_REMAINS, both engines); V2 (creation over a live voucher identity); the rename-away transition gap G3 (found by the U0 audit, not tested); plugins / direct DB writers (U3 / U4 only) |
| Production blockers (unchanged) | **OBS-1** (incl. OBS-W9-V1 and the race) — **PRODUCTION BLOCKER**; A2-R `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER` |
| Availability limit (open) | the 1000-candidate-row bound fails closed (every voucher-only redemption / voucher batch refused) when exceeded; it is not a performance or concurrency solution. The identity lookups scan the unindexed `tbl_customers.username` / `pppoe_username` (and, under U0, `tbl_voucher.code`, `tbl_user_recharges.username`); no performance figure is claimed (not measured; WP10) |
| N-07 addition (OD-W9-A1-3) | **Voucher code in logs.** For PUBLIC / RADIUS voucher-only redemptions `VoucherRedemptionService::record()` writes `target=<code>` in clear to the PHP server error log (`ErrorReporter::log` → `error_log`; not shown in the application UI, readable by whoever reads the server / shipped logs); the `voucher=` field is a hash. **Severity by voucher state:** `REFUSED` / `REFUSED:IDENTITY_IN_USE` → the code is still **unused and redeemable**; `GRANTED` → the code is a **live RADIUS credential** (voucher-only username = password = code); `NOT_VALID` → used / invalid / mistyped input. Re-assessed **LOW-MEDIUM** where server logs are readable beyond the host operators, LOW otherwise. Logging unchanged (no change authorized); fix belongs to the N-07 / secrets-in-logs remediation (WP9-D) |
| Unchanged | V2, V4, the reverse direction, SQL exhaustion (A2-R), P1–P5 policies, NEW-7, NEW-8, plugins, WP1-CHECK; T1-B…T4, WP10 not authorized |

**U0 (OD-W9-A1-2, design only):** `owner-review/WP9/U0_DESIGN.md` — identity writer / authority matrix (P1–P5, P5r, **P6 voucher-only redemption**, P7 generation; releasers; key writers; non-coverable writers), gaps G1–G8 of the approved AM-W9-1 design, the `CustomerIdentity` contract (L-3 global identity lock, canonical `assertAvailable` over customers + live voucher identities + RADIUS presence), interleaving argument for races A / B / C, timeout / deadlock / rollback rules, option comparison, phased test-first plan U0-0…U0-5 with stop points, impact (no protected file, schema or finance change) and decisions D-U0-1…8. **U0 is not implemented; no test was run in this round.**

**`WP9_U0_DESIGN_READY_FOR_OWNER_REVIEW`** — `PRODUCTION_NOT_AUTHORIZED` — `STOPPED_WAITING_OWNER_REVIEW`.

---

## U0 — Shared identity lock: end-to-end implementation and security verification (2026-10-15)

Owner decision: `WP9_U0_FULL_IMPLEMENTATION_AND_TESTING_AUTHORIZED` (autonomous plan → implement → test → fix → regression → final audit), D-U0-1 … D-U0-8 **APPROVED**. Branch `wp9/red-team-rc`, local commits only; nothing pushed, merged or deployed. **Result label: see §U0.14.** `PRODUCTION_NOT_AUTHORIZED`.

### U0.1 Architecture consistency matrix (mandatory pre-implementation audit)
Read before any code change: PLAN rev 3 (§7.1 I-1…I-5, §7.3 AM-W9-1, §7.7 AM-W9-3, §13, §16), `U0_DESIGN.md`, WP8 `OWNER_DECISIONS.md` / `REPORT.md` / `docs/WP8_INTEGRATION_MODEL.md` §8–§9, the Architecture Lock (`OWNER_ARCHITECTURE_LOCK.md`, `ARCHITECTURE_DECISIONS.md` AD-14, `TARGET_ARCHITECTURE.md` §8), `ACCEPTANCE_LEDGER.md`, the SourceGuard pins and the code of every identity / voucher / RADIUS path.

| Check | Finding | Resolution |
|---|---|---|
| Lock vs transaction rules | `Tx.php` pinned; `TreeSerialization::hold` refuses an open transaction; P1 used `Tx::withTreeLock` (lock → BEGIN) | `CustomerIdentity::hold` enforces R2 in code (`LogicException`), R4 by rolling back anything left open; P1 moved to `TreeSerialization::hold → CustomerIdentity::hold → Tx::run` (same tree lock, `Tx.php` unchanged) |
| Lock order vs tree lock, voucher claim, rename | L-1 tree (activation, finance, P1, P5 incl. device steps), L-2 `nuxr_user_*` (P3), pre-existing `nuxr_bop_` / `nuxr_mig_` | order L-1 → L-2 → L-3 → BEGIN; P6 takes L-3 only; `nuxr_bop_` / `nuxr_mig_` never call an acquirer (static guard) |
| Writer matrix completeness | P1–P6 + P5r acquirers; P7 generation; releasers; key writers; plugins / direct DB not coverable | unchanged from the approved matrix (§U0.5) |
| Grant outside the lock + durable reservation | the claim (`0 → CLAIMED`) is autocommitted inside L-3; `CLAIMED` / voucher-only `1` / `customer_id = 0` recharge rows are identities for every acquirer (D-U0-1 / -3) | as designed; refinement: no redeemable voucher → `NOT_VALID` without a check (WP5B contract) |
| RADIUS reads inside the lock (D-U0-2 bounds) | separate ORM `radius` connection | connection opened before `GET_LOCK`; indexed joins only; per-statement time bound 2 s; unreachable when configured → fail closed |
| Bypass inside the application | none found besides plugins / direct DB (out of reach of application locks) | documented residual |
| Schema / protected files / finance | U0 needs no schema; pinned files untouched | confirmed by the final diff (§U0.11) |
| Engines / isolation | named locks, `REGEXP_REPLACE`, `MAX_EXECUTION_TIME` / `max_statement_time` differ by engine | every test on MariaDB 10.11 and MySQL 8.0; the race matrix also under READ COMMITTED |
| Architecture Lock | Option A per-ISP isolation; "finer hierarchy lock restructuring" not approved; a global lock is acceptable until metrics show contention (TARGET §236) | one global per-database identity lock (LG); no per-key locks |

No locked decision was changed. The Owner's "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" was **not found** in any available source (as in WP8 C-26); §U0.13 applies the pack's `DEFINITION_OF_DONE.md` and PLAN §16 gates instead.

### U0.2 Phases, results and local commits
| Phase | Commit | Result |
|---|---|---|
| Baseline docs (V1 interim acceptance, N-07 note, U0 design) | `85a5d4efaecf4eb738a451f9e45f48b22a81fdd3` | — |
| U0-0 test-first characterisation | `61f164870f83a6818fe24506a66c603470163c42` | **22/22 race scenarios reproduced on both engines under RR and RC**; identity matrix 47 defects per engine (18 cases already refused by existing guards / A1; 1 control failure = the known P4 case-only false refusal) |
| U0-1 lock primitive | `8cbe61e793193310b2fe708f33329e8faae7dd61` | primitive tests 5/18 per engine |
| U0-2 P6 + P7 | `31bf8d2a5e3445018409cb830e4899f9d7e61fbd` | A1 16/78, T1aVoucherV1 1/3, WP5B VoucherRedemption 10/66, race C/G 6/26, matrix P6 16/42 per engine |
| U0-3 P1 / P2 / P3 | `55c56126e057b967ede17ac7aa547e9228e49f26` | race A1–A3/B1–B3/D1–D3 10/37, matrix 44/117, CustomerOwnership 9/75, Audit 2/391, Csrf 9/75, CreateFlow 3/53, Hierarchy 7/72 per engine |
| U0-4 P4 / P5 / P5r | `ececbdf549a891956ebea6fe1e1e3e3e92e956c0` | race 9/44, matrix 30/97, ServiceGate 10/264, RadiusLegacyRename 5/48, LegacyEntrypointClosure 6/62, LegacyReprovision 14/260, RenameIntegrity 4/67, RenameOldSession 20/325, RenameRemediation 15/242, LegacyAdaptive 5/266, DeviceGuardKeyCollision 4/67, RechargePreWriteRefusal 3/28 per engine |
| U0-5 closure + related (V4, reverse, survey) | `773b77d840fffb53d473d0c22a2c21bcfb98ea32` | §U0.6 |
| EOL restoration (final diff audit) | `d64cb359a70858f5f3f5d0abcb0ccd96363a8f05` | §U0.4 #10 |

### U0.3 What changed (product)
`system/autoload/Reseller/Domain/CustomerIdentity.php` (new), `VoucherRedemptionService.php` (P3, P6; the A1 helper moved into `CustomerIdentity`), `CustomerService.php` (P1, I-5), `LegacyReprovisionService.php` (P5, P5r, I-5), `DomainError.php` (2 messages), `system/controllers/customers.php` (P4, I-4 self-exclusion, I-5), `register.php` (P2), `plan.php` (P7 call site). Implementation record: `U0_DESIGN.md` §9. No schema, migration, SourceGuard pin, `Tx.php`, `ServiceGate.php`, `Package.php`, `RouteMap.php`, `Balance.php`, finance / pricing / activation / RADIUS-write code, UI template or route change.

### U0.4 Problems found and how they were fixed
| # | Problem | Found by | Fix |
|---|---|---|---|
| 1 | P6 check-to-grant race (A1 residual) | U0-0 A-family, both engines | check + claim under L-3; claim = reservation (U0-2) |
| 2 | V2: creation / rename over a live voucher identity | U0-0 matrix | (b) in every acquirer |
| 3 | G3 rename-away: an acquirer writes the old key before the pending RADIUS rename moves it to the renamed customer (TEST-PROVEN: G1 overwrote the renamed customer; G2 / G3 destroyed the other identity's rows) | U0-0 G-family | (c) RADIUS presence inside L-3 (D-U0-2) |
| 4 | P1 / P2 / P3 / P4 / P5 cross-field, canonical (leading space / TAB) and concurrent collisions | U0-0 D-family + matrix | shared canonical check under L-3 |
| 5 | P5r revert wrote the old name back over a customer created meanwhile (two holders) | U0-0 P5r | revert re-checks under L-3, else `REPROVISION_UNCONFIRMED` |
| 6 | P2 refusal of a voucher identity differed from a customer refusal (302 vs form) | U0-0 generic-message test | same form, "Account already exists" (D-U0-4) |
| 7 | P4 false refusal of a case-only self-rename | T1-A, U0-0 control | I-4 self-exclusion |
| 8 | Names longer than their column could be truncated on a non-strict server after being checked (I-5) | code reading | refused, never truncated (P1, P4, P5) |
| 9 | First P6 wiring answered `REFUSED` for the code's own `CLAIMED` row (WP5B test failed) | U0-2 regression | candidate-first; `NOT_VALID` kept |
| 10 | The edit tool normalised CRLF line endings of `customers.php` / `register.php` (whole-file diff) | final diff audit | separate restoration commit; harness re-run on the final bytes |
| — | Test-side defects (kept runs): base-class `customer()` clash; G2/G3 judged one side only; staff login inside the snapshot; array-to-string warning; missing `Text` in the in-process test; perf timer clobbered by the `Package::rechargeUser` global `$t`; PHPUnit final `run()`; SQL-literal regex escapes in the survey | own runs | fixed in the tests; every failed run kept under `evidence/u0/**/run1-*` or similar |

### U0.5 Identity writers — final protection
| Writer | Lock | Check (under the lock) | Refusal |
|---|---|---|---|
| P1 `CustomerService::create` (Admin / Agent / Sales, web / API / PWA) | L-1 → L-3 → BEGIN | username + PPPoE, canonical, (a)(b)(c) | `IDENTITY_TAKEN` / `USERNAME_TAKEN` / `LOCK_TIMEOUT`, rolled back |
| P2 `register.php` | L-3 | username | generic form message, no row |
| P3 voucher + new username | L-2 → L-3 | username; then claim + insert | `USERNAME_TAKEN` / `REFUSED`, voucher untouched |
| P4 legacy edit (unassigned) | L-3 around the save of an acquiring edit | changed names, self + own keys excluded | message, nothing saved |
| P5 reseller-owned rename | L-1 → L-3 → BEGIN | changed names, self + own keys excluded | `IDENTITY_TAKEN`, nothing written |
| P5r revert | L-1 → L-3 | old names | no revert → `REPROVISION_UNCONFIRMED` |
| P6 voucher-only redemption (E12 public, E15 RADIUS) | L-3 (10 s / 3 s) | code; then claim (= reservation) | `REFUSED`, voucher stays `0`; grant outside |
| P7 voucher generation | none (unused codes are not identities) | best-effort (a)(b)(c) | regenerate or no batch |
| Key writers (recharge, activation, voucher grant) | unchanged | — | — |
| Plugins / direct DB | **not coverable** by application locks | — | residual (U3 / U4) |

### U0.6 Results per engine (final U0-5 runs; `evidence/u0/u0-5/`, `evidence/u0/related/`)
| Suite | MariaDB 10.11 | MySQL 8.0 |
|---|---|---|
| U0RaceTest, REPEATABLE READ | 23 tests / 96 assertions, exit 0 | 23 / 96, exit 0 |
| U0RaceTest, READ COMMITTED (isolation proven per session) | 23 / 96, exit 0 | 23 / 96, exit 0 |
| U0IdentityTest (9 acquirers × 9 identity classes + controls) | 86 / 244, exit 0 | 86 / 244, exit 0 |
| U0LockTest (exclusion, nesting, exceptions, exit / fatal release, timeouts, inverted order, parallel, bounds) | 9 / 46, exit 0 | 9 / 46, exit 0 |
| U0PerfTest (observation) | 1 / 4, exit 0 | 1 / 4, exit 0 |
| A1VoucherIdentityGuardTest | 16 / 78 | 16 / 78 |
| T1aVoucherV1 / T1aLegacyPaths / T1aIdentityCharacterization | 1/3, 6/35, 7/66 | same |
| Unit/Wp9 (U0SourceGuardTest 5 + CsvCell characterisation) | 28 / 280 | 28 / 280 |
| U0V4Test / U0ReverseDirectionTest / U0SurveyTest | 7/37, 2/29, 1/6 | same |

Race closure detail (both engines, both isolation levels): every B / D case shows the second writer **waiting on the identity lock** (`p6_finished_while_…_paused = false`) and then refused; A-family customer writers refused with the voucher reserved; G-family voucher / creator refused by the RADIUS presence of the old key; C-family second equal code refused. Timeouts: RADIUS authorization ≈3 s, web ≈10 s, nothing written, one retry after release = one grant and one transaction; inverted order (test-only): MySQL raised the user-lock deadlock error, MariaDB timed out, the writer then completed.

### U0.7 Status of V1, V2, V4 and the reverse direction
| Item | Status | Evidence | What is NOT closed |
|---|---|---|---|
| **V1** voucher-only grant over a customer identity | **CLOSED for application writers** — direct, canonical variants and every race interleaving (A / B / G) TEST-PROVEN on both engines, RR and RC | U0RaceTest, U0IdentityTest P6 rows, A1, T1aVoucherV1 | plugins / direct DB writers (the A1 raw-SQL barrier still records `RACE_REMAINS` by design); grandfathered data (a code already granted over an existing customer before U0) |
| **V2** customer over a live voucher identity | **CLOSED for application writers** (P1 Admin / Agent / Sales, P2, P3, P4, P5 vs granted, CLAIMED and recharge-row identities) | U0IdentityTest, A-family | plugins / direct DB; pre-existing data (survey) |
| **V4** two codes, one RADIUS identity | **security effect CLOSED**: a second equal code (case, accent, trailing / leading space, TAB, exact duplicate) is refused sequentially and concurrently | U0V4Test, U0RaceTest C1 / C2 | generation still issues collation-equal **unused** codes (byte-exact WP5B uniqueness; observation: equal groups exist) — availability only, no policy change made (D-U0-6) |
| **Reverse direction** (owned activation over another identity's key) | **new colliders: CLOSED** (P1 Agent / P5 refused, incl. canonical variant). **Grandfathered collider: OPEN — TEST-PROVEN (E4) on both engines**: the reseller activation (COMPLETED) overwrote the unassigned customer's `Cleartext-Password` | U0ReverseDirectionTest | needs a decision (D-U0-R1) |

### U0.8 Performance and operational limits (observation only — not a load test, WP10)
| Customers | Availability check (median, ms) MariaDB / MySQL | Voucher-only redemption | P1 creation |
|---|---|---|---|
| 1 000 | 12.1 / 14.7 | 74.7 / 88.4 | 39.2 / 62.4 |
| 10 000 | 18.4 / 28.4 | 89.9 / 127.2 | 61.3 / 91.6 |
| 50 000 (+10 000 used vouchers) | 55.5 / 94.2 | 164.8 / 245.8 | 154.8 / 219.2 |
| 8 parallel voucher-only redemptions at 50 000 | wall 877 / 1 044 ms; slowest 843 / 989 ms | | |

Single host, disposable containers, one run; numbers show the cost grows roughly linearly with the unindexed scans and that one global lock serialises acquirers. **Open availability limits (unchanged decisions, documented):** (1) every acquisition scans `tbl_customers` (2 statements), `tbl_voucher` and `tbl_user_recharges` (`customer_id = 0`) — no index on these columns, no schema change made; (2) one global lock per database serialises all acquirers incl. RADIUS voucher authorization (3 s bound); (3) more than 1 000 candidate rows (e.g. legacy names with edge whitespace) or a statement over 2 s → **every** acquisition fails closed (proven with 1 001 rows); (4) a name equal to a used voucher-only code or to a key with leftover RADIUS rows (e.g. a deleted customer's suspended rows) stays unavailable (D-U0-2 / -3) until an operator removes the rows.

### U0.9 Regression and full harness
- **Full dual-engine harness** `dev/test/run-tests.sh --do-not-cache-result` (all suites, incl. `integration` → `Integration/Wp9`, `wp8-security-gates`; FreeRADIUS 3.2.8 test containers) on the final tree `d64cb359` (product fingerprint `d3136afb…`, identical before and after the run): **MariaDB 10.11: 1 316 tests / 70 589 assertions, 0 failures, 0 errors, 0 warnings, 104 incomplete; MySQL 8.0: identical; variant registry reproducible; exit 0** (`evidence/u0/harness/run-tests.out`).
- The **104 incomplete tests per engine are NOT passes**: all are WP8 real-NAS lab tests that report "WP8 real-NAS lab not running" (17 classes, itemised in `harness/incomplete-itemised-mariadb.out`: OptionGGate 17, CheckradGate 10, DriverFailClosedLab 10, FailureDrills 10, DriverContractLab 8, FreeRadiusSqlClient 8, ConcurrentFirstLoginGate 7, RealAaa 7, CoaDisconnect 6, PortLimitGate 6, RenameOldSessionLab 6, RealLifecycle 3, HotspotEquivalenceGate 2, AccountingTimeLab / ResellerBusinessFlow / RouterOsClosure / UsernameEquivalenceGate 1 each). The CHR lab was **not** started: U0 changes no RADIUS write, NAS or device contract (RADIUS reads only).
- PHP lint: 534 runtime / test files, 0 errors (`harness/lint.out`). A first harness launch on the LF-normalised bytes was stopped after ≈3 min by its process-group id (kept: `harness/run1-aborted-lf-endings/`).

### U0.10 Files and evidence
- Tests (new): `tests/Integration/Wp9/{U0Fixture, U0RaceTest, U0IdentityTest, U0LockTest, U0PerfTest, U0V4Test, U0ReverseDirectionTest, U0SurveyTest}.php`, `tests/Unit/Wp9/U0SourceGuardTest.php`; changed: `A1VoucherIdentityGuardTest.php` (replay fixture renames all three policy tables — required by D-U0-2, intent unchanged).
- Tooling: `dev/wp9/t1a_run.sh` (`WP9_ISOLATION`, `WP9_FILTER`), `dev/wp9/identity_survey.sql` (read-only canonical survey, corrects PLAN §7.4).
- Docs: `U0_DESIGN.md` §9, `U0_PROGRESS.md`, `PLAN.md` status notes, `docs/WP8_INTEGRATION_MODEL.md` §9 dated note, this section.
- Evidence: `evidence/u0/{pre, pre/rc, u0-1, u0-2, u0-3, u0-4, u0-5, u0-5/rc, related, harness}` (consoles, JSON observations, fingerprints; kept failed runs in `run1-*` / `*-noisolationproof` / `matrix-run1-login-noise` / `perf-run1-timer-clobbered` / `harness/run1-aborted-lf-endings`).

### U0.11 Integrity
- Product files changed since the A1 commit `0f181323` (exactly): `CustomerIdentity.php` (new), `CustomerService.php`, `DomainError.php`, `LegacyReprovisionService.php`, `VoucherRedemptionService.php`, `customers.php`, `plan.php`, `register.php` — 8 files, 469 insertions / 107 deletions; mixed CRLF endings of the two controllers preserved.
- SHA-256: `CustomerIdentity.php` `2b690615…e216`; `VoucherRedemptionService.php` `4f4972b6…a2b2`; `CustomerService.php` `112c1328…4d08`; `DomainError.php` `4acdc8b0…91ea`; `LegacyReprovisionService.php` `09d01d74…4952`; `plan.php` `91b8ae11…38ea`; `customers.php` / `register.php`: see `harness/integrity.txt` (after the EOL restoration).
- Product fingerprint (system, install, ui, docs pack, dev/test, root *.php): A1 `6ff47e19…` → final `d3136afb33684562d421e6faffc2dd31f689e116662457b37f6326beb96b5b38` (1 634 files).
- Manifest 42/42 OK; SourceGuard pins 23/23 match, no re-pin; no schema / migration change.
- Secret sweep (known lab secret values + private-key markers) over every committed file of the U0 work: 0 hits; no fixture password in any evidence file.
- Cleanup: 0 containers / networks / volumes of `nuxbill-reseller-test`, no QEMU, throwaway credential file deleted.
- Git: branch `wp9/red-team-rc`, no upstream, nothing pushed / merged / deployed; protected remote refs unchanged (`feat` / `wp8` `704031c6`, `main` `500d3a2d`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `hotfix` `6d4f3f96`); no remote `wp9` branch.

### U0.12 Remaining blockers (classified)
| ID | Blocker | Class | Needs |
|---|---|---|---|
| OBS-1 (overall) | identity collisions | **PRODUCTION BLOCKER** until the owner rules on this evidence | owner review of §U0 |
| D-U0-R1 | grandfathered collisions: reverse-direction activation overwrite TEST-PROVEN; legacy unassigned colliders overwrite each other (upstream) | **HIGH, PRODUCTION BLOCKER** (part of OBS-1 / L-A5-2) | decision: run the read-only survey (`dev/wp9/identity_survey.sql`) on an approved copy + operator clean-up plan, and/or an activation-time collision refusal (WP5A behaviour change) and/or U1–U4 |
| D-U0-R2 | plugins / direct DB writers bypass every application lock | known (PLAN §8, OD-W9-9) | plugin boundary decision / U3–U4 |
| D-U0-R3 | availability limits §U0.8 (global lock, unindexed scans, 1 000-row bound, leftover RADIUS rows) | operational | WP10 load evidence; any index is a schema decision |
| N-07 | voucher code in clear in the server log (`target=`) incl. the new refusal reasons | LOW-MEDIUM (§A1.9) | WP9-D |
| O-U0-1 | pre-fix A5: the voucher-only grant reported `GRANTED` (voucher consumed) while the owned customer renamed into the code kept its own policy — consistent with the WP8 device guard refusing the voucher's device write for an owned name; whether the voucher received any policy rows was **not measured**. After U0 this interleaving is refused through application writers | observation, pre-existing, unverified | owner classification |
| — | A2-R `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`; P1–P5 policies; NEW-7; NEW-8; plugins; WP1-CHECK; T1-B…T4 rows outside identity | unchanged | as before |

T1-B … T4: only the identity-related rows were in scope (§12 #25 OBS-1, #13 voucher bypass parts, HTTP P2 / P4) and are covered above; T3 (PWA) and T4 (CHR) are not needed — no UI and no RADIUS-write / NAS-contract change (U0 adds RADIUS **reads** only). No other T1-B…T4 row was executed.

### U0.13 Final acceptance audit (Definition of Done + PLAN §16; protocol text unavailable)
| DoD / PLAN §16 item | Status |
|---|---|
| Requested scope (U0-0…U0-5, related V1 / V2 / V4 / reverse) implemented, nothing knowingly omitted | DONE — except items that need a new decision (§U0.12) |
| No unauthorised adjacent work | DONE — no WP10, no CHR, no schema, no policy change, no V4 generation-policy change, no activation change |
| Test-first; failed / aborted runs kept | DONE (`evidence/u0/pre`, kept run folders) |
| Both engines, counts per engine; no silent pass | DONE; incomplete itemised, not counted |
| Negative cases with positive controls; AM-W9-2 no-unauthorised-change criterion | DONE (state checksums, policy integrity per identity) |
| Concurrency with real parallel processes, RR and RC | DONE |
| Docs updated (design §9, PLAN notes, integration model §9, this report, progress ledger) | DONE |
| Secrets / dirty tree / protected refs / cleanup | DONE (§U0.11) |
| Known risks listed | DONE (§U0.12, §U0.8) |
| Production untouched | DONE — `PRODUCTION_NOT_AUTHORIZED` |
| Owner-named protocol text | NOT AVAILABLE — not claimed |

### U0.14 Result
**`WP9_U0_END_TO_END_FINAL_ACCEPTANCE_CANDIDATE`** — U0-0…U0-5 complete and test-proven on MariaDB and MySQL (RR and RC); V1 and V2 **closed for application writers**, V4 security effect closed; open, owner-decision blockers: grandfathered collisions incl. the TEST-PROVEN reverse-direction overwrite (D-U0-R1), plugins / direct DB (D-U0-R2), availability limits (D-U0-R3), N-07. **OBS-1 remains a PRODUCTION BLOCKER**; A2-R unchanged. `PRODUCTION_NOT_AUTHORIZED` — `STOPPED_WAITING_OWNER_REVIEW`.

---

## FH — U0 final hardening and residual security closure (2026-10-16)

Owner decision: `WP9_U0_IMPLEMENTED — FINAL_HARDENING_AUTHORIZED — PRODUCTION_BLOCKED`; D-U0-R1 **APPROVED: READ-ONLY SURVEY + TARGETED FAIL-CLOSED PROTECTION**; O-U0-1, N-07 fixes allowed in scope; D-U0-R2 / D-U0-R3 read-only reviews. Mode: audit → test-first → implement → verify → fix → full regression. Baseline `aebba8bff60f11e2bf96b57b0cd42bd4f852facd` verified (clean tree, 9 commits since the A1 fix, nothing on the remote). Local commits only; nothing pushed, merged or deployed. **Result: §FH.11.**

### FH.1 Source audit (claims re-checked against the code, not the report)
Re-read: PLAN, U0_DESIGN (incl. §9), REPORT §U0, U0 evidence, Architecture Lock, AM-W9-1…3, WP5A / WP5B / WP8 contracts, the writer matrix and `dev/wp9/identity_survey.sql`. Every subscriber RADIUS policy write was traced to its code path (FH.3). Findings of the audit that changed the plan: (1) `Package::rechargeUser` (pinned) already runs `LegacyReprovisionService::assertDeviceAllowed()` **before** any write (WP8 S7-FC), so a check placed there refuses legacy grants with nothing written; (2) every subscriber-key write goes through `RadiusProvisioner`, called only by the Radius device and `ActivationService`; (3) `Package::rechargeUser` catches every device failure and still writes the invoice (basis of O-U0-1); (4) `run_hook()` runs only the first registered function per hook (R2-3).

### FH.2 D-U0-R1 — read-only survey (synthetic data only)
`dev/wp9/identity_survey.sql` hardened: statement 0 aborts when the session would mis-parse the regex escapes (`NO_BACKSLASH_ESCAPES`); ownership labels (owned / unassigned); edge **and interior** control characters; RADIUS keys with no live owner (old / suspended / orphaned) or with more than one owner; RADIUS column collations. `U0SurveyTest` seeds every owner-listed class — username vs PPPoE, case, accent, leading / trailing whitespace, TAB, LF, CR, NUL, VT, interior control character, orphaned / suspended RADIUS key, two-owner RADIUS key, customer vs customer, voucher vs customer, unassigned vs owned — and runs the survey through a utf8mb4 **and** a utf8 (utf8mb3) connection: **1 test / 12 assertions, exit 0 on MariaDB and MySQL**; same answers on both connections; read-only (state checksums unchanged). Kept: run 1 (`survey-run1-mariadb-missed-voucher`) — a **test** defect: MariaDB returned the group's upper-case representative and the test compared hex case-sensitively (run 2 debug evidence kept). Not run on production or real data.

### FH.3 D-U0-R1 — targeted fail-closed protection (implemented)
**Rule:** a subscriber's RADIUS login policy is never written, suspended, removed or renamed while its canonical key has **more than one logical owner** (customers holding it as username / PPPoE username, canonical; live voucher identities). `CustomerIdentity::owners()` counts them (bounded, time-limited reads; null = cannot check); `CustomerIdentity::assertSingleOwner()` runs the count **under the shared identity lock** (≤ 3 s wait; in place when the caller already holds it; a caller inside an open transaction cannot take the lock — the same read-only count runs and is logged, not observed in any run; *superseded 2026-10-16: proven racy and now refused, §FC.4*). Refusal = nothing written, no disconnect, no recharge / invoice, no settlement; legacy data untouched (no clean-up).

**Policy writer matrix (final):**
| Writer (all subscriber-key writes in the application) | Guard | Refusal |
|---|---|---|
| `Package::rechargeUser` non-voucher grants (admin recharge, customer purchase, gateway, balance purchase, customer-path voucher) | pre-write `assertDeviceAllowed()` → ownership check **after** the WP8 owned-name refusal (its reason / record unchanged), on the row's username **and** PPPoE username | returns `false` before any hook, recharge, invoice or device call (existing callers refund / release) |
| Radius device `add_customer` / `sync_customer` (legacy controllers, `LegacyReprovisionService` incl. its permit), `customerAddPlan` (public, N-01), `remove_customer` with `plan_expired` | `assertDeviceAllowed()` + `Radius::provision()` → `singleOwner(policy key)` | `ProvisionFailure RADIUS_IDENTITY_AMBIGUOUS` (non-retryable) |
| Radius device `customerDeactivate` (suspend: deactivate, delete, cron expiry) / `customerDelete` | `singleOwner(key)` before the write and before any disconnect | same; **the other owner keeps its policy and session** |
| Rename (`change_username`) — P4 legacy edit, P5 owned edit | `renameCheck()` refuses a source key with another owner, **before** the customer row is saved / written | `AMBIGUOUS_IDENTITY` message (P4) / `RENAME_AMBIGUOUS_IDENTITY` (P5) |
| `ActivationService::provision` (reseller activation, reconciliation retries) | `assertSingleOwner(policy key)` before the plan-group and subscriber writes | `FAILED RADIUS_IDENTITY_AMBIGUOUS` → WP5A FAILED: reservation **released**, no debit, no legacy recharge; a check that cannot run is retryable (WP5A RETRY_PENDING) |
| Voucher-only grant | P6 (U0) + the device guard + post-grant service check (FH.4) | `REFUSED` / `CLAIMED_REVIEW` |
| Reversal / reconciliation **restore** of a captured prior state | not guarded (restores the activation's own captured snapshot) | — documented |
| RadiusRest device | writes no RADIUS policy rows (rlm_rest reads the application tables) | — |
| Plan group rows (`applyPlan`) | not a subscriber identity | — |
| MikroTik devices | the shared pre-write device guard also refuses multi-owner rows (router-user namespace) | conservative |
| Plugins / direct DB / `RadiusRepository` used directly | **not coverable** by application controls (D-U0-R2) | — |

**Evidence (test-first; `evidence/fh/guard-pre`, `guard`):** before the fix, on both engines: a legacy recharge of a grandfathered duplicate (case, leading space, TAB, accent, cross-field) and of a customer over a live voucher identity returned an invoice and **overwrote** the other owner's policy; deactivation **suspended the other owner and sent a disconnect**; `customerAddPlan` overwrote; the legacy rename **moved the other owner's rows**; the owned activation over an unassigned key **COMPLETED with a debit and 2 ledger rows**; the owned rename moved the victim's rows. After: `U0OwnershipGuardTest` **11 / 45** and `U0ReverseDirectionTest` **4 / 60**, exit 0 on both engines — every case refused with no business delta, the protected policy unchanged, **no disconnect attempt** (an open accounting session of the protected owner was present), accounting history unchanged; activation FAILED `RADIUS_IDENTITY_AMBIGUOUS`, reservation `RELEASED`, wallet unchanged, 0 ledger rows, 0 recharge rows; controls: single owners recharge / deactivate / username = own PPPoE / voucher-only grant / non-colliding owned activation unchanged; the WP8 owned-name refusal unchanged; with the identity lock held elsewhere the check waits ≈3 s and refuses (fail closed) and succeeds once the lock is free.

**Contract change flagged for the owner (D-FH-1):** `tests/Integration/Wp5b/RenameOldSessionTest::testAnotherCustomerHoldingTheOldNameMeansNothingIsSent` (WP8 A3) expected the rename of a key that a legacy customer also holds (equivalent PPPoE name) to **run** with the old session left UNCONFIRMED. Under D-U0-R1 that rename is a policy move of a multi-owner key and is now **refused before any write**; the test's "nothing is sent" assertion is unchanged and it now also asserts the refusal and that nothing was written. Kept: the first regression run that found it (`guard-regression-run1-stopped`).

**Fix found by the regression (kept: run 1):** the first version ran the ownership check **before** the WP8 owned-name refusal, which changed WP8's refusal reason and skipped its refusal record (`DeviceGuardKeyCollisionTest`, `RechargePreWriteRefusalTest` failed); reordered so WP8's refusal keeps precedence.

### FH.4 O-U0-1 — voucher granted without service (characterised and fixed)
| State | Finding |
|---|---|
| Historical (before U0) | the mechanism existed unchanged: `Package::rechargeUser` (pinned) catches every device failure, reports it and still writes the invoice; the redemption then finalised `GRANTED` (voucher `1`). The U0-0 A5 run showed such a grant while the owned rename held the name (rows not measured then) |
| Current, before this fix (`evidence/fh/o-u0-1-pre`, both engines) | **TEST-PROVEN** phantom grants: (a) a code the RADIUS key rules refuse (interior control character — not reachable through `login.php` / `radius.php`, whose filters strip it); (b) a direct-DB customer inserted during the grant (the new ownership guard correctly refused the write); (c) **found during validation: concurrent grants for different keys deadlock in the RADIUS write (InnoDB 1213) — 5 of 20 parallel runs on each engine** — a real, no-bypass trigger. In every case: `GRANTED`, voucher `1`, invoice written, **0 RADIUS rows** |
| Confirmed failure vs UNKNOWN | all three are CONFIRMED failures (nothing applied: refused before the write, or rolled back by the engine); an UNKNOWN RADIUS outcome inside `Package` is equally swallowed and was not exercised (no fault injection) |
| Fix 1 (voucher state machine, `VoucherRedemptionService::grant`) | a voucher-only grant on a Radius plan is finalised `GRANTED` only if the code's policy rows exist (exact key) (*superseded 2026-10-16: rows alone were proven insufficient — now this grant's own confirmed write, §FC.5*); otherwise `CLAIMED_REVIEW:NO_SERVICE`: the voucher stays `CLAIMED` (never redeemable, listed by the drift report), no second grant, replay `NOT_VALID` |
| Fix 2 (legacy device path, `Radius::provision`) | a deadlocked policy write (1213 only — not lock-wait or connection errors) is run again, at most 3 attempts; WP5A keeps its own accepted RETRY_PENDING handling (a repository-level retry was tried first and reverted because it hid WP5A's retry timeline — `ActivationFailureTest` caught it) |
| After | `U0VoucherServiceTest` **3 / 16**, `A1VoucherIdentityGuardTest` 16 / 78, WP5B `VoucherRedemptionTest` 10 / 66 — exit 0 on both engines; parallel mixed acquirers: **0 of 20 runs per engine** end in review (2 / 7 deadlocks transparently retried), `ActivationFailureTest` 14 / 241 and `RadiusRepositoryTest` 8 / 55 unchanged |
| Not covered | customer-path vouchers (an existing customer's key may already hold rows, so absence cannot be proven) and non-Radius plans keep the WP5B behaviour; the recharge row / invoice written by `Package` before a failed device step remain for the operator (pinned file) |

### FH.5 N-07 — voucher code logging
| Location | Before (`evidence/fh/n07-pre`, both engines) | After |
|---|---|---|
| PHP error log `voucher.redeem` | `target=<code>` in clear for public / RADIUS voucher-only redemptions; `voucher=<sha256(code)[0..16]>` for all (an unsalted short hash of a short code is easy to brute-force) | `voucher_id=<row id or none> target=voucher-only|customer:<id>|new-customer outcome=<reason>` — no code, no hash |
| Reseller audit (`voucher.redeem`, staff / customer sources) | `target_snapshot.voucher = sha256 prefix` | `target_snapshot.voucher_id` |
| HTTP responses | no code found | unchanged (verified) |
| Pinned `Package.php` operator Telegram on a device failure | contains `Customer: u<username>` — for a voucher-only grant that is the code | **unchanged (pinned file)** — residual, needs a pinned-file approval (*2026-10-16: the complete audit found every voucher-only Telegram message and the invoice notification affected; closed under the owner's limited exception, §FC.3*) |
| `login.php` `_log` of failed customer logins | logs the typed username (a typed code would appear) | unchanged — observation (*2026-10-16 correction: it logs only names of existing customers, §FC.3 row 11*) |
`U0VoucherLogTest` (public HTTP voucher-only login, service PUBLIC / RADIUS granted, refused identity in use, not valid, reseller-owned, new username, customer portal): before — code in the PHP log and the hash in log and audit, both engines; after — **1 / 4, exit 0 on both engines**, no code or hash in the PHP log, `tbl_logs`, the audit table or the responses, while the outcome / reason diagnostics remain. Historical logs were not rewritten or deleted.

### FH.6 D-U0-R2 / D-U0-R3 (read-only reviews)
`owner-review/WP9/U0_HARDENING_REVIEWS.md`: R2 — plugins (`init.php` include, errors swallowed), hooks inside identity flows, plugin install (`SYSTEM_CODE`, SuperAdmin), direct DB accounts and direct `RadiusRepository` use **bypass** the application lock; mitigated (detection + write refusal) but **OPEN**; closure plan = plugin boundary decision, U3 / U4 schema options, least-privilege DB accounts (WP10), periodic survey. R3 — global lock, unindexed scans, 1 000-row bound, 2 s statement budget, legitimate customers refused by fail-closed rules (incl. the new multi-owner refusals: expiry of a multi-owner key is refused so the other owner keeps service); **OPEN for WP10**; U0 perf figures remain observational.

### FH.7 Validation
| Gate | MariaDB 10.11 | MySQL 8.0 |
|---|---|---|
| **Full dual-engine harness** `dev/test/run-tests.sh --do-not-cache-result` (all suites incl. `Integration/Wp9`, WP4 FreeRADIUS contract suites, `wp8-security-gates`), final tree `9e4d8156` + docs, fingerprint `f10011ec…` identical before / after | **1 334 tests / 70 703 assertions, 0 failures, 0 errors, 0 warnings, 104 incomplete** | **identical** |
| Variant registry reproducibility | matches | — |
| Race matrix READ COMMITTED (`U0RaceTest`, isolation proven per session) | 23 / 96, exit 0 | 23 / 96, exit 0 |
| Race matrix REPEATABLE READ, identity matrix, lock suite (timeouts, inverted order, parallel, bounds), perf, survey, guard, reverse direction, voucher service, voucher log, A1, T1-A | inside the harness (`Integration/Wp9`), all passed | same |
| Parallel mixed acquirers × 20 (deadlock evidence) | 0 / 20 in review (2 deadlocks retried) | 0 / 20 (7 retried) |
| Static source guard `U0SourceGuardTest` | 6 / 266 (in the harness) | same |
| PHP lint | 537 files, 0 errors | — |
| Finance / ledger invariants | ActivationTestCase post-conditions on every WP5A / WP5B / reverse-direction test (in the harness); no settlement or debit on any refusal | same |
- The **104 incomplete tests per engine are not passes**: they are the WP8 real-NAS lab tests ("WP8 real-NAS lab not running"), itemised in `evidence/u0/harness/incomplete-itemised-mariadb.out`; the CHR lab was **not** started — no NAS / RouterOS contract changed (the only RADIUS-side change is a read-only ownership count and a retry of a rolled-back write).
- Kept failed / stopped runs of this round: `survey-run1-mariadb-missed-voucher`, `survey-run2-debug`, `guard-pre/run1-no-open-session`, `guard-regression-run1-stopped` (WP8 precedence + D-FH-1 found), `o-u0-1-pre/run1-bypass-insert-failed`, `o-u0-1-pre/run2-hook-not-first`, `focused-regression-run1-stopped-deadlock-found`, `parallel-diag`, `deadlock-pre-*`.

### FH.8 Local commits (this round)
`081a23e5` survey hardening; `0067bb22` multi-owner write refusal + deadlock retry; `9e4d8156` voucher service (O-U0-1) + log redaction (N-07); final docs commit (see git log). Product files changed in this round: `CustomerIdentity.php` (`72b47b7d…`), `VoucherRedemptionService.php` (`893bad04…`), `LegacyReprovisionService.php` (`c7919166…`), `DomainError.php` (`048d1024…`), `ActivationService.php` (`cfc8ba0d…`, WP5A — 10 lines, provision guard only), `system/devices/Radius.php` (`e8117845…`), `RadiusProvisioner.php` (`3f39d0ac…`, metadata only) — 7 files, 190 insertions / 18 deletions. No pinned file, schema, migration, finance / ledger / pricing code, RBAC or route change.

### FH.9 Open production blockers
| ID | Status |
|---|---|
| OBS-1 (identity collisions) | **PRODUCTION BLOCKER** — application writers closed (U0) and multi-owner writes refused (FH.3); open: existing duplicates must be resolved by an operator plan (survey) before production; D-U0-R2 bypass |
| D-U0-R2 plugins / direct DB | OPEN (FH.6) |
| D-U0-R3 availability | OPEN for WP10 (FH.6) |
| N-07 residual | pinned `Package.php` Telegram message (code for voucher-only device failures) |
| D-FH-1 | owner confirmation of the superseded WP8 A3 rename behaviour |
| A2-R | `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER` (unchanged) |
| Others | P1–P5 policies, NEW-7, NEW-8, WP1-CHECK, remaining T1-B…T4 rows, WP10 — unchanged |

### FH.10 Rollback and cleanup
Rollback: each unit is one local commit (`git revert` of `9e4d8156`, `0067bb22`, `081a23e5` restores the U0 state; no schema or data migration involved). Cleanup: 0 containers / networks / volumes of `nuxbill-reseller-test`, no QEMU, the throwaway credential file deleted; manifest 42/42; SourceGuard pins 23/23 (no re-pin); line endings of every changed file preserved; secret sweep of every committed file of this round: 0 hits (no fixture password in the evidence); protected remote refs unchanged (`feat` / `wp8` `704031c6`, `main` `500d3a2d`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `hotfix` `6d4f3f96`), no remote `wp9` branch.

### FH.11 Final acceptance audit and result
Applied: the pack's `DEFINITION_OF_DONE.md` and PLAN §16 gates (the owner-named "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" text is still not available and is not claimed). Scope implemented and nothing knowingly omitted within the authority; no unauthorised adjacent work (no schema, index, plugin architecture, WP10, CHR, production or real data); test-first with kept failures; both engines; negative cases with positive controls and the AM-W9-2 criterion; zero unexpected failures / errors / new warnings; incomplete itemised; no accounting-history change, no unauthorised policy overwrite, no phantom grant, no double settlement in any test; docs cumulative; secrets / cleanup / refs verified; risks listed (FH.9).

**`WP9_U0_FINAL_HARDENING_READY_FOR_OWNER_REVIEW`** — `PRODUCTION_NOT_AUTHORIZED`; OBS-1 remains a **production blocker** (existing duplicates need an operator plan; plugins / direct DB uncovered), with owner decisions D-FH-1 (superseded WP8 A3 rename behaviour) and the N-07 pinned-file residual. `STOPPED_WAITING_OWNER_REVIEW`.

## FC — U0 final owner review findings and closure (2026-10-16)

Owner decision: `WP9_U0_HARDENING_CONDITIONALLY_ACCEPTED — TARGETED_FINAL_CLOSURE_AUTHORIZED` (U0 hardening accepted as a development acceptance candidate; a focused closure round before U0 is declared final). Scope: D-FH-1 record, N-07 complete credential exposure audit (with a limited `Package.php` pin exception for messages / notifications), the identity guard under open transactions, voucher grant confirmation; operational residuals kept open. Mode: test-first → minimal fix → focused → race → full regression → final audit. Local commits only; nothing pushed, merged or deployed; no schema change, no SQL exhaustion work, no WP10. **Result: §FC.12.**

### FC.1 Baseline and audit
Branch `wp9/red-team-rc`, HEAD `94bfd7b936eb49606908207506e643bf6f7853a0`, clean tree; protected remote refs unchanged (`feat` / `wp8` `704031c6`, `main` `500d3a2d`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `hotfix` `6d4f3f96`), no remote `wp9` branch. Re-read against the code (not the report): §FH, `U0_PROGRESS.md`, `U0_HARDENING_REVIEWS.md`, `U0_DESIGN.md`, `PLAN.md`, the WP8 S7 / A3 contracts, the SourceGuard pins and the Architecture Lock. Audit facts that shaped this round: (1) the `run_hook()` "first function only" rule also applies to the notification hooks — the harness probe plugin owns `send_telegram` / `send_sms` / `send_whatsapp` in every app copy and records their text, which is the capture point used below; (2) `Message::sendInvoice()` puts `[[user_name]]` and `[[payment_channel]]` into the invoice text and, for a voucher-only subscriber (no phone, no e-mail), still runs the SMS / WhatsApp hooks and writes `tbl_message_logs`; (3) `init.php:78` sets `zend.exception_ignore_args=1`, so exception traces carry no argument values; (4) no application RADIUS writer runs inside a database transaction (the tree lock and the identity lock are taken before BEGIN; device steps run after COMMIT; `ActivationService` provisions between its own short transactions); (5) `RadiusProvisioner::provision()` reports SUCCESS only after the committed rows were read back equal to the requested policy.

### FC.2 D-FH-1 acceptance record
`D-FH-1 = APPROVED — FAIL_CLOSED_RENAME_PRECEDENCE` (owner, 2026-10-16). Recorded as a dated clarification in `PLAN.md` §18; the WP8 acceptance record and its results are not changed. The modified WP8 test `tests/Integration/Wp5b/RenameOldSessionTest::testAnotherCustomerHoldingTheOldNameMeansNothingIsSent` now proves, in addition to `RENAME_AMBIGUOUS_IDENTITY` and "no Disconnect sent": the old key's RADIUS policy rows in `radcheck`, `radreply` and `radusergroup` (exact bytes) are identical before and after, the customer row is identical, the open accounting session is still open (`acctstoptime` NULL), and the stub NAS received nothing. `RenameOldSessionTest` **20 / 329, exit 0 on MariaDB and MySQL** (`evidence/fc/post`).

### FC.3 N-07 — full credential exposure matrix
A voucher-only subscriber's username, password and channel are the voucher code. Test: `U0NotificationCredentialTest` (every `Package::rechargeUser` notification path, captured at the notification hooks, plus the PHP log, `tbl_logs`, `tbl_message_logs`, the audit table) and `U0VoucherLogTest` (redemption sources, HTTP responses, the voucher-only login gate). Before (`evidence/fc/pre-run2`, `pre-login`, both engines): the code was in every voucher-only Telegram message (`#buy`, `#recharge`, System Error), in the invoice text handed to the SMS / WhatsApp hooks and in `tbl_message_logs`, and in the voucher-only login gate's refusal record (PHP log and audit). After (`evidence/fc/post`, `post-login`): none, both engines.

| # | Location | Before | After | Evidence |
|---|---|---|---|---|
| 1 | Telegram "System Error … sync manually" (device failure) | `Customer: u<code>` | `Customer: voucher_only` (`ref=` kept) | E4 |
| 2 | Telegram `#recharge` | `#u<code>`, `Channel: <code>` | `#voucher_only`, `Channel: voucher INV-…` (the invoice number identifies the grant) | E4 |
| 3 | Telegram `#buy` | same | same | E4 |
| 4 | Invoice notification (`Message::sendInvoice` → SMS / WhatsApp / e-mail hooks, `tbl_message_logs`; with an admin session also the `Http::getData` error Telegram that repeats the gateway URL with the text) | username and channel = code; sent to an empty recipient | **not sent for voucher-only** (it has no recipient) | E4 (hooks, `tbl_message_logs`) |
| 5 | PHP log `voucher.redeem` | fixed in §FH.5 | unchanged (row id + outcome) | E4 |
| 6 | PHP log + audit `service.refused op=loginVoucherOnly` (`login.php` E12 gate, WP2 / OD-11) | the typed code, when it equals a reseller-owned name — and for **every** voucher-only attempt while the reseller schema is not ready | refusal still logged and audited, without the name | E4 (owned-name case), E1 (schema-not-ready: same line) |
| 7 | Reseller audit `voucher.redeem` | fixed in §FH.5 | unchanged | E4 |
| 8 | `tbl_logs` | none | none | E4 |
| 9 | HTTP responses (login, flash pages, customer portal) | none | none | E4 |
| 10 | Exception traces (`ErrorReporter::report`) | no argument values (`zend.exception_ignore_args=1`); messages generic | unchanged | E1 + E4 (device-failure path: no code in the PHP log) |
| 11 | `login.php` `_log` Successful / Failed Login | logs the typed username **only when a customer with that username exists**; a voucher-only code is no customer's username (U0 canonical uniqueness; grandfathered duplicates excepted, where the name is that customer's username anyway) | unchanged — the §FH.5 observation is corrected: not a voucher credential exposure | E1 |
| 12 | `Package.php` voucher-mode gate `ServiceGate::refused('rechargeUser', null, $channel)` (pinned) | the code, only when it equals a reseller-owned name **and** `Package` is called directly (the redemption service refuses earlier without logging) | **unchanged — residual** (a log record, not a notification: outside the N-07 exception) | E1 |
| 13 | `LegacyReprovisionService` `deviceGrant` refusal (`names[0]`) | a voucher-only row reaches it only for grandfathered owned-name collisions (`login.php` re-activation) | **unchanged — residual** (grandfathered data only) | E1 |
| 14 | `tbl_transactions.username` / `.method`, `tbl_user_recharges.username` / `.method` | `username = <code>`, `method = "Voucher - <code>"` | **unchanged by decision** (financial / compatibility records; no schema or ledger change, no history rewrite); readable by staff with `CUSTOMER_READ` (plan/list), `INVOICE_READ` (plan/view, plan/print), `REPORT_READ` (reports, export) and the API on those routes — the same audience that holds `VOUCHER_READ` sees codes in the voucher list by design; voucher-only subscribers have no portal account. **Separate proposal (not done):** mask voucher-only usernames and `Voucher - <code>` in these views for roles without `VOUCHER_READ`, or a voucher-only scheme whose RADIUS username is not the code (design change) | E1 + E4 (finance controls) |
| 15 | RADIUS `radcheck` (username + Cleartext-Password), `radacct.username` | the code, by design of voucher-only | unchanged (DB least privilege: WP10 LPDB) | E1 |
| 16 | Hooks / plugins (`recharge_user`, `recharge_user_finish`, notification hooks; globals `$c`) | a plugin can read the code | plugin boundary (D-U0-R2), OPEN | E1 |
| 17 | Customer-path voucher (an existing customer redeems a code): Telegram channel, the customer's own invoice (`payment_channel`), its `tbl_message_logs` row, the transaction method | the code — **spent** (status `1`, user = customer; never redeemable again; not a login credential) | recorded, unchanged (receipt to its redeemer) — owner may decide otherwise | E4 (recorded) |

Positive controls (E4, both engines): an ordinary customer's `#buy` / `#recharge` messages keep `#u<username>` and `Channel: admin`; an ordinary customer's device failure keeps `Customer: u<username>`; the customer invoice is still sent; the redemption outcomes are unchanged. Finance controls: every path returns its invoice (`INV-…`); the voucher-only and customer-voucher `tbl_transactions` / `tbl_user_recharges` rows (including the compatibility fields) are exactly as before.

### FC.4 Identity guard under open transactions (`CustomerIdentity::assertSingleOwner`)
**Finding (TEST-PROVEN before the fix, `evidence/fc/pre-run2`, `pre-rc`).** The count ran without the lock inside a caller's open transaction:
| Case | REPEATABLE READ (both engines) | READ COMMITTED (both engines) |
|---|---|---|
| S1 stale read view: the caller's read view predates a duplicate owner committed by a direct-DB writer | **policy written** for a key with 2 owners (`APPLIED`) | refused `RADIUS_IDENTITY_AMBIGUOUS` (the count saw the duplicate) |
| S2 the caller inserts its owner row and writes the policy in ONE open transaction; the application acquirer P4 (Admin edit, PPPoE name under L-3) cannot see the uncommitted owner | **policy written, the acquirer took an equivalent name, 2 owners at the end** | **same defect** |
| S3 accepted order: the caller holds L-3 across BEGIN, count and write | contract met (the acquirer waited for L-3, then was refused); the count still went through the "in transaction" branch | same |
| Control: single owner inside a transaction without the lock | `APPLIED` (unlocked count) | same |

Note: `CustomerService::create` (P1) was not usable as the S2 acquirer: its legacy `SELECT … FOR UPDATE` scan of `tbl_customers` waits for every uncommitted customer row (observed in the first pre run: it blocked until the barrier timeout and then refused). That is a serialization side effect of P1, not of the identity lock.

**Fix (minimal, `CustomerIdentity.php` only):** the lock-held check comes first (a caller holding L-3 on this connection is counted in place, order L-3 → BEGIN); inside an open transaction **without** the lock the check is refused — `IDENTITY_CHECK_UNAVAILABLE`, nothing counted or written, event `identity.owner_check_refused reason=open_transaction`; a lock-status read error is also refused. No lock-order change, no new lock, no RADIUS write under L-3 added by the application.

**After (`evidence/fc/post`, `post-rc`):** `U0OpenTransactionGuardTest` **4 / 24, exit 0, MariaDB and MySQL, REPEATABLE READ and READ COMMITTED**: S1 / S2 refused `RADIUS_IDENTITY_CHECK_UNAVAILABLE` before any RADIUS write (S2: the writer never reached the write; the acquirer was then refused by the committed owner; 1 owner); S3 unchanged (writer `APPLIED` with its own password, acquirer waited on L-3 and was refused, 0 open-transaction events); controls: single owner outside a transaction `APPLIED`, two owners `REFUSED RADIUS_IDENTITY_AMBIGUOUS`, single owner inside a transaction without the lock `REFUSED RADIUS_IDENTITY_CHECK_UNAVAILABLE` with nothing written. 0 deadlock / lock-wait lines in every case; no partial write (the refused paths never opened the RADIUS transaction). Availability effect: a plugin that writes RADIUS inside its own transaction without L-3 is now refused (fail closed); no application path does that (FC.1 (4); full harness, FC.8).

Residual (documented, not a defect of this check): between the count (under L-3) and the RADIUS write the lock is released (D-U0-2: no RADIUS write under L-3). An application acquirer cannot add a second owner in that window because the existing owner blocks it (customers / live voucher identities / RADIUS rows are all "taken"); only writers outside the application can, which no application lock covers (D-U0-R2).

### FC.5 Voucher grant confirmation (`VoucherRedemptionService::grant`)
**Finding (TEST-PROVEN before the fix, both engines, both isolation levels, `evidence/fc/pre`, `pre-rc`):** "some RADIUS row exists under the code's exact name" was accepted as proof of service. With rows written outside the application during the grant (after the claim, before the device step):
| Case | Before | After |
|---|---|---|
| C1 stale foreign rows of a colliding identity (foreign password) + the device refuses (a duplicate owner appeared) | **GRANTED** (voucher `1`), policy password ≠ code | `CLAIMED_REVIEW:NOT_CONFIRMED`, voucher `CLAIMED` |
| C2 partial rows (group row only) + the device refuses | **GRANTED**, no password row | `CLAIMED_REVIEW:NOT_CONFIRMED` |
| C3 confirmed device failure (RADIUS write rolled back) while foreign rows exist | **GRANTED**, foreign password | `CLAIMED_REVIEW:DEVICE_FAILED` (only the foreign row remains) |
| C4 UNKNOWN device outcome (commit acknowledgement lost) | GRANTED (rows actually right, but unconfirmed) | `CLAIMED_REVIEW:DEVICE_UNKNOWN` (review, not release) |
| C0 foreign rows (exact or equivalent key) existing before the claim | `REFUSED`, voucher `0`, rows untouched | unchanged |
| C5 parallel grants of distinct codes, 3 rounds × 6, deadlock retry | 18 / 18 `GRANTED` with their own policy (1–3 retried deadlocks) | unchanged (18 / 18) |
| Replay / settlement | replay `NOT_VALID`; one transaction row per grant | unchanged in every case; reviewed vouchers are listed by the drift report |
| Controls | normal grant `GRANTED`; customer-path voucher `GRANTED` with the customer's own policy | unchanged |

**Fix:** `RadiusProvisioner::provision()` keeps the last result per exact key for the request (`lastProvision()` / `forgetProvision()`); `grant()` forgets the code's key before `Package::rechargeUser` and, for a voucher-only Radius plan, finalises `GRANTED` only when **this grant's own write** returned SUCCESS (committed and read back equal to the requested policy) **and** the rows are still present; otherwise `CLAIMED_REVIEW:<reason>` (NOT_CONFIRMED / DEVICE_FAILED / DEVICE_UNKNOWN / NO_SERVICE). The WP5B state machine is unchanged (same states; `CLAIMED_REVIEW` keeps the voucher `CLAIMED`, never redeemable, listed for review). `Package.php` was not changed for this point.
**After:** `U0GrantConfirmationTest` **7 / 41, exit 0, MariaDB and MySQL, REPEATABLE READ and READ COMMITTED**; `U0VoucherServiceTest` (O-U0-1) and WP5B voucher suites inside the full harness. Not covered (unchanged, documented): customer-path vouchers and non-Radius plans keep the WP5B behaviour; the recharge row / invoice that `Package` writes before a failed device step remain for operator reconciliation (CLAIMED_REVIEW recovery is an open operational item).

### FC.6 Code changes (local commits: §FC.11)
| File | Change | Pinned |
|---|---|---|
| `system/autoload/Package.php` | N-07: `$nuxrTg` name for Telegram; voucher-only channel → `voucher INV-…`; no invoice notification for voucher-only (+14 / −7) | **yes — limited exception, re-pinned (FC.9)** |
| `system/controllers/login.php` | N-07: the voucher-only gate records its refusal without the typed code (CRLF preserved: 299 CRLF / 299 LF) | no |
| `system/autoload/Reseller/Domain/CustomerIdentity.php` | §4: lock-held first; open transaction without the lock → refused | no |
| `system/autoload/Reseller/Radius/RadiusProvisioner.php` | §5: per-request last provision result per key | no |
| `system/autoload/Reseller/Domain/VoucherRedemptionService.php` | §5: grant confirmed by this grant's verified write | no |
| Tests | new `U0NotificationCredentialTest`, `U0OpenTransactionGuardTest`, `U0GrantConfirmationTest`; `U0VoucherLogTest` (+1 test); `U0SourceGuardTest` (+4 static guards, mutation-checked); `RenameOldSessionTest` (D-FH-1 proof); `Wp3a/SourceGuardTest` (Package pin); `Wp8/DriverClassificationSourceTest` (mutation anchor of the voucher-only gate follows the new one-line gate; the gate-removed mutation is still detected) | — |

### FC.7 Results — MariaDB 10.11 and MySQL 8.0 (focused, `evidence/fc/post*`)
| Suite | MariaDB | MySQL |
|---|---|---|
| `U0NotificationCredentialTest` | 1 / 11 | 1 / 11 |
| `U0VoucherLogTest` | 2 / 10 | 2 / 10 |
| `U0OpenTransactionGuardTest` RR / RC | 4 / 24 · 4 / 24 | 4 / 24 · 4 / 24 |
| `U0GrantConfirmationTest` RR / RC | 7 / 41 · 7 / 41 | 7 / 41 · 7 / 41 |
| `U0RaceTest` READ COMMITTED | 23 / 96 | 23 / 96 |
| `RenameOldSessionTest` | 20 / 329 | 20 / 329 |
| `U0SourceGuardTest` | 10 / 284 | 10 / 284 |
| `Wp3a/SourceGuardTest` · `Wp8/DriverClassificationSourceTest` · `Wp4/RadiusSourceGuardTest` | 10 / 37 057 · 7 / 44 · 8 / 154 | same |
All exit 0. Every pre-fix run is kept (`fc/pre`, `fc/pre-run2`, `fc/pre-rc`, `fc/pre-login`), including the first N-07 pre run whose capture plugin was shadowed by the harness probe plugin (`run_hook` first-only rule; nothing captured — a test defect) and the first S2 design whose P1 acquirer was serialized by its own `FOR UPDATE` scan.

### FC.8 Full harness and security regression
| Gate | MariaDB 10.11 | MySQL 8.0 |
|---|---|---|
| **Full dual-engine harness, final run (run 2)** `dev/test/run-tests.sh --do-not-cache-result`, HEAD `21858d4c` (product / test files = the committed code), fingerprint `4e62cacd…` identical before / after | **1 351 tests / 70 804 assertions, 0 failures, 0 errors, 0 warnings, 104 incomplete** | **1 351 tests / 70 808 assertions, 0 failures, 0 errors, 0 warnings, 104 incomplete** |
| Variant registry reproducibility | matches | matches |
| Exit code | 0 | |
| Race matrix READ COMMITTED (`U0RaceTest`) | 23 / 96 | 23 / 96 |
| Open-transaction guard / grant confirmation, READ COMMITTED | 4 / 24 · 7 / 41 | 4 / 24 · 7 / 41 |
| PHP lint (every tracked or new non-vendor `.php`, php82 image, no network) | 581 files, 0 errors | — |
- **The 104 incomplete tests per engine are not passes**: the WP8 real-NAS lab tests (lab not running), the same set itemised in `evidence/u0/harness/incomplete-itemised-mariadb.out`. The CHR lab was **not** started — no NAS / RouterOS contract changed (RADIUS-side changes: a refusal before any write, a per-request result record, a stricter voucher finalisation).
- The MariaDB assertion count (70 804) differs from MySQL (70 808) and from MariaDB run 1 (70 808 with one failing test): some suites assert per observed row / retry; the count is data-dependent, not a skipped test (tests: 1 351 on both). Not investigated per test.
- **Kept runs and their causes:** `fc/full-run1-stopped-scope-extended` — stopped by process group after ≈3 min because the N-07 audit extended the scope to `login.php` (a run must cover the final code); `fc/final/run-tests-run1-mariadb-clionly-flake.out` — complete run on the same final code: MySQL clean, **MariaDB 1 failure** `CliOnlyTest::testNewPhpFilesRefuseHttpExecution` ("15 is identical to 14": the test compares the server-global `Connections` counter while the MariaDB compose healthcheck connects every 3 s; the paths it requests are a fixed list none of which changed) — the known ENVIRONMENT / harness timing race recorded since WP1; classification evidence `fc/clionly-classification`: **10 / 10 isolated passes** on MariaDB; the test was not modified (that needs owner approval). Run 2 is the clean final run.
- **Security regression (in the harness, both engines):** U0 race / identity / lock / survey / ownership-guard / reverse-direction / voucher service / voucher log / A1 / T1-A suites; WP5A activation + reversal (finance post-conditions on every test: no double settlement, no debit on refusal), WP5B voucher + legacy re-provision, WP8 device-guard / rename / fail-closed suites, WP2 route / CSRF / ServiceGate, WP3A finance source guards. No phantom GRANTED (O-U0-1, C1–C4), no unauthorized RADIUS mutation (S1 / S2, ownership guard), negative cases with positive controls in every new test.

### FC.9 Scope and pin audit
- **Diff since `94bfd7b9`:** product 5 files (+85 / −30): `Package.php` (`9759fca7…`), `login.php` (`6cdc1220…`), `CustomerIdentity.php` (`d256bb99…`), `RadiusProvisioner.php` (`0d30a6d0…`), `VoucherRedemptionService.php` (`982ef006…`); tests 8 files (+727 / −9). No schema, migration, index, finance / ledger / pricing / wallet code, RBAC, RouteMap, capability, plugin architecture, CHR or WP10 change. Line endings preserved (`login.php` CRLF 298 → 299 lines, all CRLF).
- **SourceGuard Pin Exception (owner N-07 limited exception):** file `system/autoload/Package.php`; old SHA-256 `d4567f663193e16b6db7397ff94ed37c268274ebc5f6ce8a645af3c79fca2b85`; new SHA-256 `9759fca72b5f33236d5a7a00ceaf4ffb9f2dc76b86b16a03a70c6ac145e4aa54`; reason: voucher-only credentials in the `rechargeUser` notifications (Telegram System Error / `#recharge` / `#buy`; the invoice notification without a recipient). Intended diff only (5 hunks: the `$nuxrTg` name, two `Customer:` lines, two hashtag + `Channel:` lines, the `sendInvoice` guard); recharge order, invoice, finance, device call and return values unchanged — proven by the finance controls of `U0NotificationCredentialTest` and the full harness. The pin comment records the previous pin. **Pins: 23 / 23 match; no other pin changed.** Manifest 42 / 42.
- **Protected refs:** remote `feat` / `wp8` `704031c6`, `main` `500d3a2d`, `wp7` `8e7f0e1c`, `wp6c` `757d8de9`, `hotfix` `6d4f3f96` unchanged; no remote `wp9` branch; nothing pushed or merged.
- **Secret sweep:** every committed file of this round (code, tests, documents, evidence): 0 hits.

### FC.10 Remaining production blockers (all OPEN; U0 success does not close them)
| Item | Status |
|---|---|
| OBS-1 existing duplicate identities | **PRODUCTION BLOCKER** — survey on an approved copy + operator resolution |
| Plugin / direct-DB bypass (D-U0-R2) | OPEN |
| Global lock capacity, unindexed scans, 1 000-row bound (D-U0-R3) | OPEN for WP10 |
| A2-R SQL exhaustion | `UNVERIFIED_SECURITY_RISK — PRODUCTION BLOCKER`; no reproduction attempted |
| CLAIMED_REVIEW operator recovery and legacy invoice reconciliation | OPEN (operational; this round adds review reasons, not a recovery tool) |
| N-07 residuals | `ServiceGate::refused` in the pinned `Package.php` voucher-mode gate (direct callers only) and the `deviceGrant` record for grandfathered owned-name collisions; financial / compatibility fields keep the code (FC.3 rows 12–14, separate proposal) |
| P1–P5 policies, NEW-7, NEW-8, WP1-CHECK, remaining T1-B…T4, WP10 release gates | unchanged |

### FC.11 Local commits, rollback and cleanup
Commits (local only): `fa0b4483` D-FH-1 proof; `64f617be` open-transaction guard; `fc6190a6` grant confirmation; `21858d4c` N-07 (Package re-pin, login gate, static guards); final docs + evidence commit (see git log). Rollback: `git revert` of `21858d4c`, `fc6190a6`, `64f617be`, `fa0b4483` (in that order) restores `94bfd7b9` behaviour; no schema or data migration. Cleanup: 0 containers / networks / volumes of `nuxbill-reseller-test`, no QEMU, the throwaway credential file deleted; no production access, no real data.

### FC.12 Final risk and acceptance recommendation
Applied: the pack's `DEFINITION_OF_DONE.md` and PLAN §16 gates (the "MASTER FINAL SYSTEM ACCEPTANCE AUDIT PROTOCOL v1.0" text is still not available and is not claimed). All four owner items were executed test-first, every defect was reproduced before its fix on both engines (and both isolation levels where relevant), fixed minimally inside U0 / non-protected files plus the one authorised pin exception, and verified by focused, race and full dual-engine regression; failed and stopped runs are kept with their causes. Risk after this round: the application identity contract (U0) holds for application writers, multi-owner writes are refused, voucher-only grants are confirmed by their own write, and no live voucher-only credential appears in notifications, logs or audit records written by the application; what remains is operational / architectural (FC.10).
**Recommendation:** accept U0 (with this closure) as the **final development acceptance** of the identity work; **do not** authorise production while the FC.10 blockers are open.

**`WP9_U0_FINAL_OWNER_ACCEPTANCE_PACKAGE`** — `PRODUCTION_NOT_AUTHORIZED` — `STOPPED_WAITING_OWNER_REVIEW`.

