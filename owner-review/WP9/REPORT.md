# WP9 — Red-Team / Release-Candidate Report

| Item | Value |
|---|---|
| **Status of this report** | **Opened for the T0 section only.** WP9 is **not complete**. T1–T4, every remediation wave, U0, the A2-R hardening, the P1–P5 policies, NEW-7 and NEW-8 are **not started**. Nothing below claims more than T0 |
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
