# TI-02 — StubNas logging race (test infrastructure finding, OPEN)

Recorded 2026-10-11 (owner decision on the AppServer repair). Status: **OPEN**. This is a test-infrastructure defect.
It is **not** evidence of a defect in the production CoA / Disconnect client (`system/autoload/Reseller/Radius/CoAClient.php`).

**Where.** `tests/Support/StubNas.php` and `tests/Support/stub_nas.php`. Both are unchanged since WP4 (`e39f6f11`).

**What happens.**
1. `stub_nas.php` sends the ACK / NAK first.
2. Only then does it write the JSON record of what it received, with `file_put_contents($log, …)`. That is not atomic:
   the file exists before its content is written.
3. `StubNas::received()` waits only until the log file **exists**. It then terminates the stub with `proc_terminate`
   and reads the file.
4. So the test can read an empty or partial file, or kill the stub mid-write. The client has its ACK, but the recorded
   request is empty.

**Observed.** Full harness run 1 of the AppServer repair (MySQL), `Wp4\CoAClientTest::testCoaRequestChangesTheRate`:
- `COA_ACK` was received (line 120 passed);
- line 121 failed with `Failed asserting that null is identical to 43`, plus 2 warnings ("Undefined array key 0");
- the whole run exited **1**. That failure is kept and not counted as a pass.

**Reproduced in isolation** (`stubnas-race-probe.php` / `.out`, harness image, network none):

| Runs | Lost after ACK | Bind failures | Missing replies |
|---|---|---|---|
| 300 | 2 | 0 | 0 |
| 600 (captured) | **5** | 0 | 0 |

There were no bind failures and no missing replies, which rules out a port collision.

**Impact.**
- Any test that reads `StubNas::received()` can fail intermittently, at about 1 % per stub use.
- The same race applies to every stub mode (ack / nak / nak503 / silent / badauth).

**Fix.** Not applied. It needs owner approval; the plan was delivered with the AppServer GitHub finalization report (outside the repository).

## Related cleanup notes (kept, not in the AppServer fix scope)

- **N-1:** if the PHPUnit process is killed with SIGKILL, `AppServer::stop()` and `__destruct()` cannot run, so the
  server's process group may outlive it. This is bounded by the harness: the runner container is removed at the end of
  the run.
- **N-2:** a failed start inside `Wp2HttpTestCase::setUpBeforeClass()` leaves its temporary app copy and test database.
  The server processes are cleaned by `AppServer`. `Wp2HttpTestCase` was outside the allowed scope.
