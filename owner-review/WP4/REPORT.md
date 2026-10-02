# WP4 — RADIUS Hardening — Owner review report

**Status: IMPLEMENTED_NOT_ACCEPTED** (waiting for owner review). Final state: `STOPPED_WAITING_OWNER_REVIEW`.

Detailed model: `docs/WP4_RADIUS_MODEL.md`. Deployment configuration: `install/freeradius/README.md`. Test evidence: `owner-review/WP4/TESTDOX.txt`.

## A. Baseline ancestry / accepted prior HEAD / current HEAD / branch / worktree

| Item | Value |
|---|---|
| Upstream ancestry | `d3e0596287322944ee21fcc14dc1798158463140` |
| Accepted prior WP | WP3B **ACCEPTED** at `460714def5c4d9814fc80b18acf400a3b79a9029` (owner decision 2026-10-02) |
| Execution base | `feat/reseller-system-v1` = `f2269a069420912b63731f98308d63f93fc1dd30` (`460714de` + owner metadata commit "docs: record WP3B owner acceptance and decisions", local, not pushed) |
| Branch | `wp4/radius-hardening` (from `f2269a06`; nothing reset or rewritten) |
| Candidate HEAD | the evidence commit `docs(wp4): owner-review report and testdox` (SHA in the final response) |
| Worktree | clean after the evidence commit (`tests/vendor/` ignored) |
| Push / PR / merge / deploy | none |

## B. Scope implemented (prompt `WP4_RADIUS_HARDENING.md` + owner decisions C7/C9b, C16, C17, WP3A binding input, Q1–Q12)

| Required | Done |
|---|---|
| Verify legacy RADIUS behaviour | Read-only inventory (plan `WP4_PLAN.md`); each defect reproduced by the new tests on the pre-WP4 code (§G) |
| RadiusRepository, RadiusPolicyBuilder, RadiusProvisioner, AccountingService, hardened CoAClient | `system/autoload/Reseller/Radius/*` (model §1) |
| Structured ProvisionResult SUCCESS/FAILED/UNKNOWN | `ProvisionResult` (1:1 with the R0003 `provisioning_result` enum; UNKNOWN never retryable) |
| radacct vs local rad_acct distinguished | `AccountingService` (radacct, read-only, authoritative) vs `RestCounter` (rad_acct, per-session REST counters); model §4 |
| Preserve accounting history; no destructive radacct deletion in lifecycle paths (C17) | Purge removed; no radacct write anywhere (source guard); radacct byte-identical across every device operation and the admin flows (tests) |
| PPPoE + Hotspot policy contract tests: dynamic pool, static IPv4, simultaneous use, rate, expiration | `FreeRadiusContractTest` against a real FreeRADIUS 3.2.8 with the project configuration |
| Project-owned FreeRADIUS configuration / dictionary, tested (C16, Q2 option a) | `install/freeradius/*`; quotas per billing period (`Nuxr-Period-Start`) instead of resetting by deleting history |
| SEC-01 (Critical) rlm_rest endpoint | off unless configured; secret + source allowlist; all SQL parameterized (Q3) |
| SEC-11 (High) CoA shell injection | native RFC 5176 client, no shell / process (Q6) |
| SEC-17 NAS part | secret never rendered; validated input (Q10) |
| E16 voucher authenticate | only unused vouchers or active recharges |
| NEW finding: inverted CHAP verification (Critical) | proven and fixed (Q4) |
| Expiry drift (RADIUS vs billing) | RADIUS Expiration = saved billing expiry on every path (Q5); pinned `Package.php` change owner-approved |

Forbidden scope respected: no activation saga (WP5A), no reseller UI / PWA, no production NAS change, no direct reseller-finance write (source guard), no schema change.

## C. Files changed (`git diff --name-status f2269a06..HEAD`, 55 files before the evidence commit)

- **Runtime, added:** `system/autoload/Reseller/Radius/{ProvisionResult, ProvisionFailure, RadiusPolicy, RadiusPolicyBuilder, RadiusRepository, RadiusProvisioner, RadiusCommitUncertain, RadiusFaults, AccountingService, RadiusPacket, CoAClient, RestGate, RestCounter}.php` (NUXR_RUNTIME-guarded).
- **Runtime, modified:** `system/devices/Radius.php` (delegates), `system/devices/RadiusRest.php`, `radius.php`, `system/autoload/Password.php` (CHAP), `system/autoload/Package.php` (**pinned; owner-approved Q5**: the two device-call blocks moved after the recharge save, unchanged otherwise), `system/controllers/plan.php` (edit-post / extend save first; expiry edit syncs RADIUS), `system/controllers/home.php` (self-extend saves first), `system/controllers/radius.php` (NAS validation), `system/cron.php` (reaper), `ui/ui/admin/radius/nas-edit.tpl` (secret write-only), `config.sample.php` (commented REST settings).
- **FreeRADIUS configuration:** `install/freeradius/{README.md, dictionary.phpnuxbill, mods-available/nuxr_counters, mods-available/sql.example, sites-available/phpnuxbill}`.
- **Test environment (Q1):** `dev/test/compose.yml` (`freeradius-mariadb`, `freeradius-mysql`: `freeradius/freeradius-server:3.2.8@sha256:af6fd34a5b7826b6245008732f43fbc0432eb23d23e5de7106371b2941e70a4b`, no published ports), `dev/test/run-tests.sh` (throwaway `NUXR_RADIUS_SECRET`), `dev/test/freeradius/{start.sh, sql, clients.conf}`.
- **Tests:** `tests/Unit/Wp4/*` (4 classes), `tests/Integration/Wp4/*` (8 classes), `tests/Support/{RadiusTestCase, RadiusHttpTestCase, RadiusClient, StubNas, stub_nas}.php`, `tests/bootstrap.php` (autoload `Radius`, Q12), `tests/phpunit.xml` (suites `wp4-unit`, `wp4-integration`).
- **Accepted test changed:** `tests/Unit/Wp3a/SourceGuardTest.php`: only the `Package.php` hash pin (owner-approved Q5), with a comment. No other accepted test changed.
- **Docs:** `docs/WP4_RADIUS_MODEL.md`, this report, `TESTDOX.txt`.
- **Not changed:** R0001–R0006, the runner, `ServiceGate`, `RouteMap`, `Tx`, `Balance`, the FreeRADIUS schema `install/radius.sql`, the spec pack / MANIFEST, routes and capabilities.

Commits (author `ALI HENDI <ali.hahuba3@gmail.com>`, `Co-Authored-By` trailer):

| Commit | Content |
|---|---|
| `09ee662d` feat(wp4): RADIUS policy, repository, provisioner, accounting and CoA client | `Reseller\Radius` |
| `eedab6a9` fix(wp4): harden the legacy RADIUS paths | device, endpoint, CHAP, Package / controllers order, NAS UI, cron, pin |
| `1bf04aab` feat(wp4): project FreeRADIUS configuration and test environment | `install/freeradius`, `dev/test` |
| `e39f6f11` test(wp4): RADIUS contract, repository, device, CoA, endpoint and guards | tests |
| `e04e2329` docs(wp4): RADIUS model, contracts and WP5A handoff | model |
| evidence commit | this report, `TESTDOX.txt` |

## D. Important design notes

- **One writer, no history writes.** `RadiusRepository` is the only writer of the policy tables (managed attributes only, idempotent, one transaction per change). FreeRADIUS alone writes `radacct`.
- **Quotas per billing period.** Removing the radacct purge would make the stock counters sum all past sessions, rejecting renewed time / data-limited subscribers. The project counters count only sessions since `Nuxr-Period-Start` (the recharge's start), so a renewal gives a fresh quota while history is kept. Proven against FreeRADIUS.
- **Expiry = billing.** The device reads the saved active recharge; every caller saves first (`Package.php`, plan edit / extend, self-extend). No active recharge → `NO_ACTIVE_RECHARGE`, nothing written.
- **Outcome semantics.** Rollback → FAILED (retryable for transient DB errors); lost commit / read-back mismatch → UNKNOWN; CoA silence → UNKNOWN after identical retransmissions; never a blind retry.
- **REST endpoint off by default.** rlm_rest deployments must add `$radius_rest_secret` (deliberate breaking change for SEC-01). The allowlist names the FreeRADIUS servers (rlm_rest requests come from the RADIUS server, not the NAS) — a correction of the planning wording "NAS source allowlist".
- **Legacy device contract kept.** Same public methods; non-SUCCESS raises `ProvisionFailure`, so `Package::rechargeUser` keeps its report + "sync manually" notification.

## E. Migrations / data impact

- No migration, no DDL, no R0007; R0001–R0006 byte-identical (R0006 `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`).
- On first provisioning after upgrade, a subscriber's managed RADIUS rows are rewritten to the exact policy (legacy reply attributes removed from radcheck; Expiration set to the billing expiry; quotas restored to the plan quota; `Nuxr-Period-Start` added).
- Accounting history is no longer deleted. History lost before WP4 cannot be recovered.
- Deployments must install the FreeRADIUS configuration (README) for time / data quota plans; the REST mode stays off until configured.
- Rollback: code only (revert the branch); RADIUS rows are re-provisionable by a sync.

## F. Test commands + exact results

| Command (PHP 8.2.34, PHPUnit 11.5.56; FreeRADIUS 3.2.8 test containers) | MariaDB 10.11.19 | MySQL 8.0.46 | Registry | Exit |
|---|---|---|---|---|
| `dev/test/run-tests.sh` (15:22–16:03 UTC, 41m01s) | **OK 539 / 43804** | **OK 539 / 43804** | matches | 0 |
| `dev/test/run-tests.sh --testdox` (16:03–16:58, 54m49s) | **OK 539 / 43804** | 538 OK + 1 error (`Wp3a\AppSurfaceTest`, AppServer port-reuse harness flake, §L): 539 / 43549 | matches | 1 |
| `phpunit --testdox` MySQL leg rerun (17:09–17:46, 36m22s) | — | **OK 539 / 43804** (539 passed / 0 failed) | — | 0 |
| `--testsuite wp4-unit,wp4-integration` (development runs) | OK 69 / 567 | OK 69 / 567 | — | 0 |

- **Totals per engine: 539 tests / 43804 assertions**, the same on both engines in every complete leg.
  - 539 = 470 (accepted WP3B baseline) + 69 WP4 tests (4 unit classes, 8 integration classes).
  - The accepted suites gain assertions only from the source guards scanning the new files.
- **Testdox (`TESTDOX.txt`):** 1078 passed / 0 failed, from the MariaDB leg of the canonical run plus the clean MySQL rerun. All 940 WP3B testdox lines are present unchanged, plus 138 new lines (69 × 2).
- **Route / security matrix** (`RouteMatrixTest`, `WP2_EVIDENCE=1`, 16:58–17:07): **1442 requests, 1164 denials (all 403), 278 allowed**. The rows are byte-identical on both engines and to the accepted WP2 rows. WP4 adds no route and changes no decision.
- **PHP 8.2.34 lint:** 385 tracked non-vendor PHP files (+30 WP4), 385 pass, 0 fail.
- **Manifest:** 42/42 OK.
- **Migrations:** R0001–R0006 only; `system/reseller/` and `install/radius.sql` are unchanged against `f2269a06`. R0006 is `f1a761196fcb9e0850888971d009d5e8a09514ffb10f605d78023fdb88327abd`.
- **Secret sweep** over `f2269a06..HEAD`: no private-key / cloud / VCS / chat / payment / JWT pattern. The only secret-shaped text is:
  - test-only literals (`coa-test-secret`, `wp4-rest-secret-…`, a random `Nas-Secret-…`);
  - documentation placeholders (`change-me`, `...at least 32 random characters...`);
  - test-runner secrets generated at runtime.

## G. Security / failure cases tested

**Reproduction on the pre-WP4 code.** The new WP4 tests were run with the ten pre-WP4 legacy files restored: `radius.php`, `Radius.php`, `Password.php`, `Package.php`, `plan.php`, `home.php`, `controllers/radius.php`, `nas-edit.tpl`, `RadiusRest.php`, `cron.php`.

Result on MySQL 8.0.46: **69 tests, 16 failures, 5 errors**. The failures prove these defects:
- **CHAP bypass:** the old verifier returned `right=false wrong=true`, confirmed by a separate run of the old and new `Password.php`.
- **SQL injection effect:** injection payloads changed the database.
- **Unauthenticated endpoint:** answered 200 when disabled or without a secret.
- **E16:** a used voucher authenticated (204).
- **History purge:** an admin activation purged the radacct history.
- **NAS UI:** invalid NAS input was accepted, and the secret was rendered.
- **Shell:** `shell_exec` in the device.
- **Package order:** `Package.php` called the device before saving.
- **Accounting:** double-counted cumulative counters.

The five errors are the old device needing the full-app `Timezone` class.

After the fix, every one of these passes on both engines.

**Tests and what they cover:**

| Area | Tests |
|---|---|
| FreeRADIUS contract | **FreeRADIUS 3.2.8:**<br>• PAP and CHAP accept, wrong-password reject;<br>• the account username is not the PPPoE key;<br>• static IPv4, and pool without a static IP;<br>• user and group rate;<br>• Simultaneous-Use with an open session;<br>• expiration;<br>• time and data quota used, then a fresh quota after renewal, with history kept;<br>• suspend and remove are rejected and history is kept. |
| Failure injection | rollback → FAILED, nothing changed; transient → retryable; lost commit → UNKNOWN; read-back mismatch → UNKNOWN |
| History (C17) | radacct byte-identical across activation, renewal, password and static-IP re-provision, expiry to the expired plan, deactivation, username change, plan update, disconnect, delete and plan removal, and across the HTTP recharge, renewal and expiry edit |
| CoA (SEC-11) | ACK, NAK (502), NAK 503, silence (3 identical packets → UNKNOWN), forged reply ignored, unknown NAS, shell metacharacters sent as bytes (no file created) |
| Endpoint (SEC-01, E16, Q4, Q9) | disabled → 401; wrong or missing secret → 401; Basic and header secret accepted; injection payloads leave the database unchanged; used and unused voucher handling; CHAP; per-session counters |
| NAS (SEC-17) | invalid address, port and secret refused; secret not in the edit page; empty secret keeps it; rotation works |
| Guards | no radacct write anywhere; no shell in RADIUS paths; no interpolated raw SQL; no request input in the RADIUS classes; no finance or application-table writes; NAS secret not rendered; the endpoint gate runs first; Package saves before calling the device |

## H. Git diff summary

`f2269a06..e04e2329`: 55 files, +4024 / −650 (the legacy `Radius.php` shrank from 534 to about 260 lines; `radius.php` was rewritten with the same behaviour plus the gate).

## I. Known risks / TODOs

See `docs/WP4_RADIUS_MODEL.md` §12:
1. Pre-WP4 PPPoE rows keyed by `username` remain until re-provisioned or removed.
2. History lost before WP4.
3. rlm_rest deployments must configure the secret.
4. Expiration depends on the FreeRADIUS time zone.
5. Sessions spanning a period boundary are not counted in the new period.
6. No real-NAS / MikroTik verification (WP8).
7. Device failures are now explicit exceptions.
8. NAS and customer secrets are plaintext at rest, because FreeRADIUS needs them.
9. MikroTik devices, the `.htaccess_firewall` default (SEC-20) and the radpostauth export (R11) are unchanged.

Carried forward: the WP0 notes (PDF/gd, static UI review, external plugins) and the harness flakes (`CliOnlyTest` healthcheck race, `AppServer` port reuse).

## J. Production status

Not touched. No real NAS, RADIUS server or MikroTik was contacted. Everything ran in the isolated `nuxbill-reseller-test` compose project on loopback.

## K. Push status

None. `feat/reseller-system-v1` holds the local WP3B metadata commit `f2269a06` (not pushed); `origin/feat/reseller-system-v1` = `d4ba4514`, `main` = `500d3a2d` (unchanged).

## L. Validation notes

- **AppServer port-reuse flake (third observation: after WP3B StaffManagementTest on MySQL and RouteMatrixTest on MariaDB).** The first `--testdox` MySQL leg had one error, `Wp3a\AppSurfaceTest::testNoFinanceRoutesOrApi`: `no CSRF token on admin: 404 … The requested resource /?_route=admin was not found`.
  - This is the pre-existing WP2 `AppServer` defect classified in the WP3B report (§L.1 / §N). A stopped `php -S` app server leaves its worker processes holding the port, and a later HTTP test class that draws the same random port reaches the orphan.
  - WP4 adds 3 HTTP test classes, so a leg now starts 27 app servers, and the collision chance grows to roughly 3–4 % per leg.
  - The class passed 3/3 on MySQL in isolation, and the complete MySQL testdox rerun is clean.
  - The harness is not patched (accepted WP2 code, owner approval needed). **Recommendation:** an owner-approved harness fix (terminate the server's process group, or verify the server's identity at start).
- **Shared host (AVAIA), 15:22–17:07 UTC, 525 host samples, 87 AVAIA samples:**
  - host load1 max 8.61, mean 5.99; CPU max 36 %, mean 20 %; MemAvailable min 41308 MiB;
  - **swap used up to 126 MiB** at one point (previous rounds: 0);
  - test containers: MySQL 169 % / 771 MiB, PHP runner 156 % / 561 MiB, MariaDB 141 % / 461 MiB, FreeRADIUS ≤ 46 MiB each;
  - the 19 AVAIA containers (the 18 baseline ones plus the owner's `avaia-v3-prod-publish-worker`) were `Up` in every sample, except the one-shot `avaia-v3-minio-init` (`Exited (0)`, as always);
  - only the `nuxbill-reseller-test` compose project and one-shot `nuxbill-reseller-test-*` containers were used; no global prune, no daemon change, no AVAIA access; nothing left behind (no `nuxbill` container, network or volume).
- **Image.** One new image was pulled (Q1): `freeradius/freeradius-server:3.2.8@sha256:af6fd34a5b7826b6245008732f43fbc0432eb23d23e5de7106371b2941e70a4b`, about 365 MB.

## M. Final state

`STOPPED_WAITING_OWNER_REVIEW`
