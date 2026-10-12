# WP8 H-2B — Verification gaps closed (2026-10-12)

The owner asked for two checks before the commit/push authorization ("Owner Acceptance Record Review"). This folder
holds their evidence only. The original H-2B evidence (`../h2b-impl/`) is unchanged. No CHR gate or SQL stress was
re-run. No code, test or configuration was changed.

## 1. FreeRADIUS 3.2.8 configuration validation (`freeradius -XC`)

| Where | Configuration | Result | Exit |
|---|---|---|---|
| Harness `freeradius-mariadb` | installed by `dev/test/freeradius/start.sh` from `install/freeradius` | `Configuration appears to be OK` | **0** |
| Harness `freeradius-mysql` | same | `Configuration appears to be OK` | **0** |
| WP8 lab `freeradius-wp8` (CHR1 + CHR2 up, Option G `shipped`) | installed by `dev/wp8/freeradius/start.sh` | `Configuration appears to be OK` | **0** |

**Files:**
- `frconfig-XC-harness-freeradius-mariadb.out`, `frconfig-XC-harness-freeradius-mysql.out`, `frconfig-XC-lab.out`.
  Each is redacted; secrets show as FreeRADIUS's own `<<< secret >>>` mask. The only match for "warning" in them is the
  configuration key `warnings = "auto"`. None contains an error.
- `frconfig-installed-sha256.txt`: the files actually loaded are byte-identical to the repository in all three
  containers. The five files checked are:
  - `sites-available/phpnuxbill`;
  - `nuxr_hotspot_slot_verifier` (module and program);
  - `nuxr_pppoe_session_guard` (module and program).

## 2. WP8 PHPUnit integration re-run after the Option G test fix

Command: `dev/wp8/lab.sh test --testsuite wp8-integration` with all `--display-*` flags.
- Lab: CHR1 + CHR2, RouterOS 7.24.5, Option G `shipped`, project site.
- Run time: 2026-10-12 09:33:53Z to 10:16:57Z.
- Full output: `lab-phpunit-wp8-integration.out`.

```
OK, but there were issues!
Tests: 69, Assertions: 1203, Warnings: 3, Deprecations: 1, Skipped: 13.
exit=1
```

| Category | Count | Assessment |
|---|---|---|
| Failures | **0** | The earlier failure (`OptionGGateTest::testShippedHotspotShared2`, 2026-10-11) is gone |
| Errors | **0** | — |
| PHP warnings | 3 | **Pre-existing, not a real failure.** `system/autoload/Message.php:13-15` "Undefined variable $root_path", from `RealLifecycleTest::testLegacyReprovisionPasswordAndUsernameOnTheRealNas`. The same 3 warnings are in the A1, A2-R, AV-5, H1-A and H-2B evidence. `system/` is unchanged |
| PHP deprecation | 1 | **Pre-existing.** `system/autoload/PEAR2/Autoload.php:206` `is_file(null)`, from `FailureDrillsTest::testDrill8RouterOsApiUnavailable` |
| Skipped | 13 | Other lab modes: 10 checkrad-gate mode tests, 1 drill-7 baseline (NAS type `other`), 2 Option G `functional`. These are **not passes** |

**Why the exit code is 1.** `tests/phpunit.xml` sets `failOnWarning="true"`, so the 3 pre-existing `system/` warnings
make PHPUnit exit 1 even though every executed test passed.
- The run is **not** a clean exit 0.
- No H-2B, H1-A, A2-R or Option G test failed.
- The previous run (`../h2b-impl/lab-phpunit-wp8-integration.out`) had the same 3 warnings, the same deprecation and the
  same 13 skips, plus 1 failure. Both runs: 69 tests and 1203 assertions.

## 3. Lab

The lab was brought up for these checks and taken down afterwards (`lab.sh down`, exit 0). Final state: 0 links,
0 netns, 0 QEMU, 0 agent, 0 containers, 0 networks. The harness containers are removed.
