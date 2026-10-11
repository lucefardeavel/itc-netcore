# WP8 AppServer — Test Infrastructure Repair & Acceptance Report

Date: 2026-10-10. Baseline: `wp8/integration-poc@fcf54a7a763d185079b0e1b92654069851f9c757`. Test infrastructure
only; no commit or push.

**Verdict: `APPSERVER_FIX_READY_FOR_OWNER_ACCEPTANCE`.**

Summary:
- The AppServer defect is fixed:
  - the master and its 4 workers run in their own process group and are all removed on `stop()`;
  - a busy port is never reused;
  - only the server that answers this copy's random sentinel is accepted.
- Two full dual-engine harness runs: **no AppServer failure in either**.
  - **Run 2:** exit **0** on both engines (986 tests, 67263 assertions each).
  - **Run 1:** exit **1** because of **one different, pre-existing test-infrastructure race in `StubNas`**
    (`CoAClientTest`, MySQL). It is diagnosed and reproduced below, and it is NOT fixed here (outside the allowed scope).

## 1. Root cause

`tests/Support/AppServer.php` (unchanged since WP2, `8cf402ab`):
- starts `php -S 127.0.0.1:<random 20000–29999>` with `PHP_CLI_SERVER_WORKERS=4`, i.e. one master and 4 forked
  workers that all hold the listening socket;
- `stop()` sends `proc_terminate()` (SIGTERM) to the master only;
- `start()` returns as soon as *anything* accepts on the port.

Proof (`process-group-probe.*`, harness image, network none):

| Action | Processes left | Port accepting |
|---|---|---|
| SIGTERM to the master only (old behaviour) | **4 workers** | **YES** |
| SIGTERM to the server's own process group | 0 | no |

The earlier proof (`evidence/av5/harness-appserver-orphan-probe.*`) showed that after the docroot is deleted the orphans
answer `HTTP/1.1 404 Not Found`. A later test class that drew such a port talked to the orphan. That produced the
earlier harness failures: `Http\ErrorHandlingTest` (4) on MariaDB and `Wp6b\FinanceCorrectionTest` (5) on MySQL.

## 2. Minimal fix (one support file; product code untouched)

**`start(array $candidatePorts = [], float $timeout = 10.0)`:**
1. Requires `ext-posix`, `/usr/bin/setsid` and `/proc` (all present in the harness image); otherwise it throws.
2. For each attempt (the candidate ports first, then random 20000–29999, at least 8 attempts) it skips a port that
   **already accepts or cannot be bound** (`portFree`).
3. It writes a per-start random sentinel `wp2_probe/server-<128-bit token>.txt`. `wp2_probe/` is excluded from
   `treeFiles()`, so the "no filesystem change" proofs are unaffected.
4. It launches `setsid php -S …` with **`PHP_CLI_SERVER_WORKERS=4` (unchanged)**. `proc_open`'s child is not a group
   leader, so `setsid` makes **pid = process group = session** without forking. That pid is the group to signal.
5. The server is ready only when **all** of these hold, within the timeout:
   - the child is the leader of its own group and session;
   - the group has **1 master + 4 workers** alive;
   - `GET /<sentinel>` returns **this start's token**.
6. If the child exits early, the timeout passes, or a wrong server answers, the attempt is stopped (step 7) before the
   next one. After the last attempt it throws with every port and its reason.

**`stop()`, idempotent:**
1. If processes exist whose **process group AND session** equal the server's pgid (`/proc/<pid>/stat`, zombies
   excluded): SIGTERM to `-pgid`; after 3 s SIGKILL to `-pgid`.
2. Otherwise, while `setsid()` has not yet taken effect, only the direct child (still unreaped, so it is ours) gets
   SIGKILL.
3. Then a final sweep: any member of that group still alive gets SIGKILL to `-pgid`. This closes the window where a
   child calls `setsid()` and forks between the checks.
4. `proc_close()` reaps the master.
5. It throws if a group member survives or the port still answers the sentinel. The sentinel is removed.

**Never signalled:** a process outside the server's own session (the test runner, a foreign listener). No `pkill`, no
kill by name.

**Also:**
- `destroy()` = `stop()` in `try/finally`, then the copy is removed (idempotent; tolerates a removed directory).
- `__destruct()` = best-effort `stop()`, so a failing test or a fatal path still frees the server.
- Read-only helpers: `port()`, `processGroup()`, `processes()`.
- The `port` property became private (set at start). Nothing outside the class used it.

**Kept as found:** 4 workers, the random port range, the docroot copy, config, probes and every public method used by
the suites.

## 3. Changed files (uncommitted)

| File | Change |
|---|---|
| `tests/Support/AppServer.php` | lifecycle above (+216 / −24) |
| `tests/Http/AppServerLifecycleTest.php` | new, 6 tests (suite `wp2-http`, default matrix) |
| `owner-review/WP8/evidence/appserver/` | this report and its evidence |

Not touched (0 diff lines against `fcf54a7a`):
- `install/` (including `install/radius.sql` and `install/freeradius/`) and `system/`;
- H1-A, A2-R and production settings;
- `Wp2HttpTestCase.php`, `StubNas.php` and `stub_nas.php`.

## 4. Process ownership and cleanup evidence (`AppServerLifecycleTest`, real processes)

| Test | Proves |
|---|---|
| Starts a master and four workers in its own group … | pgid ≠ the test runner's group; 5 processes (the master is the runner's direct child, 4 workers are children of the master); THIS copy answers; after `stop()` every one of the 5 pids is gone, the port refuses, `stop()` / `destroy()` twice are no-ops, the directory is removed, no server process of the copy remains |
| Restart serves the same copy from a new process group | stop + start: 5 new pids, the old ones gone, the same copy served; `start()` while running is a no-op |
| A busy port is never reused and a foreign listener is never touched | a listener owned by the TEST process on the candidate port: skipped; after `stop()` that listener still accepts (never signalled) |
| Orphaned workers of an earlier server are neither reused nor left behind | **the master is killed before its workers** (SIGKILL to the master only): the 4 workers still listen (the old failure mode). A second server is offered that port: it is skipped, and the second server answers for its own copy. `stop()` of the first server still finds its group and removes all 4 orphaned workers. The second server is untouched (5 processes, still answering). |
| A failed start leaves no process and no listener | `start([], 0.0)`: every attempt times out, then a `RuntimeException` listing the ports. No attempted port accepts, no server process of the copy remains, no sentinel is left. The instance starts normally afterwards. |
| Four workers serve requests in parallel | 4 concurrent requests to a 1.5 s script finish in < 4 s (serial would be ≥ 6 s); at least 2 distinct worker pids, all members of THIS server's group |

**Runs** (`focused-runs.txt`):

| Run | MariaDB | MySQL | Exit |
|---|---|---|---|
| 1 (first version) | **1 failure** (restart: 3 processes instead of 5) | OK | **1** |
| 2, 3, 4 (final code) | OK (6 tests, 64 assertions) | OK (6 tests, 64 assertions) | 0 / 0 / 0 |

- **Focused run 1 failure, kept as evidence:** the first version accepted the server once ONE worker answered, before all
  4 workers had forked. The fix makes "1 master + 4 workers alive" a readiness condition.
- In the same step I added the post-kill sweep (`stop()` step 3).

**During full harness run 2** (`php-S-sampler-*`):
- 928 samples, 3 s apart, of the runner container's processes;
- **at most 5 live `php -S` processes and at most 1 docroot at any time**, so no accumulation.
- The sampler of run 1 is **invalid** (its pattern did not match the real command line) and is not counted.

**After all runs:**
- 0 `php -S` processes on the host;
- 0 harness or probe containers;
- the runner containers are removed by the harness.

## 5. Worker concurrency tests

| Run | Result per engine | Exit |
|---|---|---|
| `AppServerLifecycleTest::testFourWorkersServeRequestsInParallel` | pass in runs 2–4 and in both full runs | 0 |
| `wp2-http` suite (all HTTP classes, including `ErrorHandlingTest`) | OK (110 tests, 6192 assertions) | 0 |
| `FinanceCorrectionTest` + `HierarchyTest` (`execStart` concurrency) + `ErrorHandlingTest` | OK (16 tests, 300 assertions) | 0 |

## 6. Full MariaDB / MySQL harness results (`dev/test/run-tests.sh`, default matrix, run twice)

| Run | Engine | Tests | Assertions | Failures | Errors | Warnings | Skipped | Incomplete | Exit |
|---|---|---|---|---|---|---|---|---|---|
| 1 (20:11–21:05Z) | MariaDB | 986 | 67263 | 0 | 0 | 0 | 0 | 79 | |
| 1 | MySQL | 986 | 67262 | **1** | 0 | **2** | 0 | 79 | **1** (whole run) |
| 2 (21:05–21:57Z) | MariaDB | 986 | 67263 | 0 | 0 | 0 | 0 | 79 | |
| 2 | MySQL | 986 | 67263 | 0 | 0 | 0 | 0 | 79 | **0** (whole run) |

- 986 = the earlier 980 plus the 6 new tests.
- The 79 incomplete tests are the lab-only tests, marked incomplete when the lab is down (by design).
- **No HTTP 404 / AppServer failure in either full run.** Before the fix there was one in each full run.

**Run 1 failure, diagnosed, not incidental:**
- **Test:** `Wp4\CoAClientTest::testCoaRequestChangesTheRate`. `CoA_ACK` was received (line 120 passed), but the stub's
  record of the request was empty. Line 121 failed with `null !== 43`, plus 2 warnings ("Undefined array key 0").
- **Cause:** a race in `tests/Support/StubNas.php` / `stub_nas.php` (WP4, `e39f6f11`, unchanged):
  - the stub sends the ACK, then writes its JSON log with `file_put_contents`;
  - `StubNas::received()` waits only until the log file *exists* and then terminates the stub;
  - it can read the file between its creation and its content (or kill the stub mid-write), so the data is empty.
- **Reproduced in isolation** (`stubnas-race-probe.*`, harness image, network none):
  - 300 runs: 2 lost after ACK;
  - 600 runs (captured): **5 lost after ACK**;
  - 0 bind failures, 0 missing replies (rules out port collision).
- **Independent of this change:** `CoAClientTest` does not use AppServer.
- **Proposed separate fix, not applied:**
  - the stub writes the log atomically (temp file + `rename`);
  - `received()` waits for the stub to exit (or for a complete JSON document) before terminating it.

## 7. Regression results

- **Before the fix:** two full runs on the code that became `fcf54a7a` (AppServer unchanged), each failing from the AppServer orphan (4 issues on
  MariaDB in run A, 5 errors on MySQL in run B; `evidence/av5/harness-summaries.txt`).
- **After the fix:** 0 AppServer failures in 2 full runs (4 engine passes, 3,944 test executions) plus the focused
  runs.
- **Every other test** in the matrix passed in both runs, except the one `StubNas` race above.

## 8. Remaining risks

1. **`StubNas` race** (pre-existing, proven, about 1 % per CoA / Disconnect stub use). It can still make a full harness
   run exit 1 intermittently; it needs its own approval to fix.
2. **The fix relies on Linux `/proc`, `ext-posix` and `setsid`** (present in the harness image). Elsewhere `start()`
   fails loudly instead of running unsafely.
3. **If the PHPUnit process itself is SIGKILLed**, `stop()` / `__destruct` cannot run, so the server group could
   outlive it. The harness container is removed at the end of the run, which bounds this.
4. **A failed start inside `Wp2HttpTestCase::setUpBeforeClass`** leaves its temporary app copy and test database (the
   server processes are cleaned). `Wp2HttpTestCase` was not in the allowed scope.
5. **Workers that become zombies** after their master dies are reaped only by the container's PID 1. They hold no
   socket and do not count as live (excluded by state Z).

## 9. Git and backup status

| Item | Value |
|---|---|
| `HEAD` / `origin/wp8/integration-poc` | `fcf54a7a` / `fcf54a7a` (no commit, no push) |
| Remote `feat` / `main` | `5bdc5745` / `500d3a2d` (unchanged) |
| Working tree | `M tests/Support/AppServer.php`; `?? tests/Http/AppServerLifecycleTest.php`; `?? owner-review/WP8/evidence/appserver/` |
| Backup | `/srv/phpnuxbill/wp8-appserver-prefix-backup-20261010T195044Z` (0700; HEAD, status, `AppServer.php`, `Wp2HttpTestCase.php`), `SHA256SUMS` OK (also re-verified at the end) |
| Secret sweep | 11 changed or new files, 4 lab secret values, **0 hits** |
| Manifest | 42/42 |
| Leftovers | 0 `php -S` processes on the host; 0 harness or probe containers |

No reset, stash or clean. H-2, V-1 and A3 were not started; the H-2 / V-1 plans are unchanged.
