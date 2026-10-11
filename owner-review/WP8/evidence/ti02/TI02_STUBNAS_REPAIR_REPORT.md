# WP8 TI-02 — StubNas Repair & Final Acceptance Candidate

Date: 2026-10-11. Baseline: `wp8/integration-poc@51e9915d388c2306f58d3ac6d830e70bc05fc8ab`. Test infrastructure
only; no commit or push.

**Verdict: `STUBNAS_READY_FOR_OWNER_ACCEPTANCE`.**

Summary:
- The race is gone:
  - 0 lost records in **7,000** probe runs after the fix, against 6 lost in 7,000 before (same probe, same image);
  - 12,000 more exchanges inside the 20 repeated reliability runs, all recorded.
- 20 / 20 focused repetitions passed on both engines.
- **Both full dual-engine harness runs exited 0** (994 tests, 67305 assertions per engine, 0 failures, errors and
  warnings).
- Two focused runs during development failed. They are kept below; they exposed a real second defect (an undetectable
  UDP port collision), which is now fixed.

## 1. Root cause

`tests/Support/stub_nas.php` and `tests/Support/StubNas.php` (WP4, `e39f6f11`):
1. The stub answers (ACK / NAK), **then** writes its JSON record with `file_put_contents($log)`. That is not atomic: the
   file exists before its content does.
2. `StubNas::received()` waited only until the log file **existed**, then `proc_terminate()`d the stub and read the
   file. It could read an empty or partial document, or kill the stub mid-write. It then returned `[]`. The client had
   its ACK, but the recorded request was lost (TI-02).

**Second defect, found by the new reliability test.** The constructor waited 10 s for `.ready` and then continued
**silently**. Moreover, PHP binds every stream socket with `SO_REUSEADDR`, so a stub started on a UDP port that is
already in use **binds successfully**: a port collision was never even a bind failure (`udp-reuseaddr-probe.out`).
`ext/sockets` is not installed in the harness image.

## 2. Exact files changed (uncommitted)

| File | Change |
|---|---|
| `tests/Support/stub_nas.php` | +40 / −3 |
| `tests/Support/StubNas.php` | +141 / −19 (rewritten around the same public use: constructor, `port`, `received()`) |
| `tests/Integration/Wp4/StubNasReliabilityTest.php` | new, 8 tests |
| `owner-review/WP8/evidence/ti02/` | this report and its evidence |

**`stub_nas.php`:**
- **Atomic publication:** `publish()` writes `$log.tmp.<pid>` and then `rename()`s it to `$log` (same directory, same
  filesystem). A reader sees no log or the complete document. Used for the record and for errors.
- **The protocol order is unchanged:** answer first, then record (no technical need to change it). The modes,
  timeouts (8 s silent, 20 s answer window), packets and record content are unchanged.
- **Bind failure** (including an out-of-range port, which PHP would otherwise silently map to another port) →
  `{"error":"bind"}`.
- **Port exclusivity:** after binding, the stub counts the UDP sockets on its port in `/proc/net/udp` and
  `/proc/net/udp6`. Anything other than exactly 1 → `port_in_use` (or `port_not_bound`, or `cannot verify port`), and it
  exits.

**`StubNas.php`:**
- **Constructor:** tries up to 5 random ports (or the given test ports). It waits for `.ready` **or** stub exit. On a
  failure it reads the reason, cleans that attempt (its own process and its files) and tries the next port. If all
  attempts fail it **throws** with every `port:reason`.
- **`received(float $timeout = 25.0)`:**
  - waits for the stub to **exit by itself** (it publishes its log just before exiting), bounded by the timeout;
  - then reads and **validates** the document (`parseLog`): a complete JSON list of records with `code` int, `id` int,
    `auth` string, `valid` bool, `attributes` array;
  - an empty, partial, non-JSON, error or wrongly shaped log, or a timeout, **throws** with a clear message (it used to
    return `[]`);
  - idempotent: a second call returns the same data.
- **Ownership:** only this instance's own child is ever stopped, through its own `proc_open` handle (`release()`). No
  group, name or pid guessing.
- **Cleanup:** `release()` (also from `__destruct`) stops and reaps the child if it is still running and removes `$log`,
  `$log.ready` and `$log.tmp.*`. It is idempotent.

**Not changed (0 diff lines against `51e9915d`):**
- `CoAClientTest`, `ActivationFailureTest` and every other existing test and assertion;
- `system/` (including the production `CoAClient`), `install/`, `dev/`;
- A2-R, H1-A, AV-5, `phpunit.xml`;
- no new service.

## 3. Before / after race evidence

Probe: `race-probe-before.php` / `race-probe-after.php`.
- The probe drives the real `StubNas` (ack mode, a raw CoA-Request, then `received()`).
- It runs in the harness image with `--network none` and the repository mounted read-only.
- The "before" runs use the pre-fix `StubNas.php` / `stub_nas.php` from the backup.
- The after probe counts every exception (the new `received()` throws instead of returning `[]`).

| Run | Code | Runs | Lost after ACK | Exceptions | Bind failures | Missing replies |
|---|---|---|---|---|---|---|
| sequential ×3 | before | 3 × 1000 | **0, 0, 3** | — | 0 | 0 |
| 4 parallel containers | before | 4 × 1000 | **1, 1, 0, 1** | — | 0 | 0 |
| sequential ×3 | after | 3 × 1000 | **0, 0, 0** | 0 | — | 0 |
| 4 parallel containers | after | 4 × 1000 | **0, 0, 0, 0** | 0 | — | 0 |

- Before: **6 / 7,000** in this session (earlier: 2 / 300 and 5 / 600). After: **0 / 7,000**.
- As the owner noted, the old code does not fail every time. The failure is intermittent, and the files record every
  batch, including the batches with 0 losses.
- Files: `race-probe-before.out`, `race-probe-after.out`, `race-probe-parallel.out`.

## 4. Bind retry and timeout verification (`StubNasReliabilityTest`)

| Test | Proves |
|---|---|
| A bind failure and a port in use are retried on another port and cleaned | ports `[70000, <UDP port held by the test>, random]` → attempts `70000:bind` and `<port>:port_in_use`, then success on the third port. The ACK and the record still work, no file is left, and the test's own socket is untouched |
| No usable port fails loudly and leaves nothing | two held ports → `RuntimeException` "stub NAS did not start (p1:port_in_use; p2:port_in_use)"; no file left |
| A timeout fails loudly and stops only its own stub | silent stub, `received(0.5)` → "did not finish within 0.5 s"; its own stub is gone, an unrelated `php -r 'sleep(30)'` process is still alive, the UDP port is released |
| Malformed or incomplete logs are rejected | missing, empty, partial JSON, non-JSON, error document, non-list, missing fields, wrong types → each throws; `[]` and a valid record are accepted |
| `received()` is idempotent and cleans up | a second call returns the same data; the stub pid is gone; no `wp4_nas_*` files; the port can be bound again |
| Every answered request is recorded over many exchanges | 300 sequential stub lifecycles (CoA and Disconnect alternating): every ACK has its record (code, id, authenticator) |
| Silent mode records every retransmission | 3 identical packets → no answer, exactly 3 identical records |
| NAK modes and forged replies still answer as before | nak / nak503 → 45, badauth / ack → 44, one record each |

**Kept development failures** (`focused-runs.txt`):
1. **First focused run (both engines):** 2 failures. "Bind failure retried" and "no usable port" failed because the
   held port did **not** make the stub's bind fail (`SO_REUSEADDR`). This is the second defect above; the fix is the
   `/proc/net/udp` exclusivity check.
2. **Second focused run (both engines):** 1 failure. Port 70000 was reported as `port_in_use` instead of `bind`, because
   PHP mapped the out-of-range port to another port. The stub now rejects an out-of-range port as `bind` and reports
   0 sockets as `port_not_bound`.
3. **Third focused run:** OK (29 tests, 305 assertions) on both engines.

## 5. CoA protocol regression

Unchanged tests, in every focused run, all 20 repetitions and both full runs:
- `CoAClientTest` (7):
  - Disconnect ACK (1 request, valid RFC 5176 authenticator, attributes, accounting untouched);
  - NAK 502 → FAILED and NAK 503 → SESSION_NOT_FOUND;
  - silence → UNKNOWN with 3 identical packets (1 + 2 retransmissions);
  - forged reply ignored;
  - CoA rate change;
  - offline / unknown NAS;
  - no shell.
- `ActivationFailureTest` (14), including "Disconnect is best effort and recorded" (silent NAS, 3 identical packets).

All pass on MariaDB and MySQL. Production `CoAClient` is unchanged.

## 6. Repeated reliability tests

`dev/test/run-tests.sh --filter 'StubNasReliabilityTest|CoAClientTest|ActivationFailureTest'`, **20 times**, both
engines each run (`repeated-runs.txt`):
- **20 / 20 exit 0**;
- every engine pass: **OK (29 tests, 305 assertions)**, so 40 / 40;
- each pass includes the 300-exchange test, so **12,000 additional stub exchanges, all recorded**.

## 7. Full MariaDB / MySQL harness results (`dev/test/run-tests.sh`, default matrix, twice)

| Run | Engine | Tests | Assertions | Failures | Errors | Warnings | Skipped | Incomplete |
|---|---|---|---|---|---|---|---|---|
| 1 (06:32–07:16Z) | MariaDB | 994 | 67305 | 0 | 0 | 0 | 0 | 79 |
| 1 | MySQL | 994 | 67305 | 0 | 0 | 0 | 0 | 79 |
| 2 (07:16–07:58Z) | MariaDB | 994 | 67305 | 0 | 0 | 0 | 0 | 79 |
| 2 | MySQL | 994 | 67305 | 0 | 0 | 0 | 0 | 79 |

- 994 = the earlier 986 plus the 8 new tests.
- The 79 incomplete tests are the lab-only tests, marked incomplete when the lab is down (by design).

**Process sampler** during both runs (`process-sampler-summary.txt`, every 3 s, runner container):
- at most **1** live `stub_nas.php` (797 and 766 samples; a live stub in 25 and 23 samples);
- at most **5** live `php -S` processes (the AppServer fix holds);
- no accumulation.

## 8. Actual exit codes

| Run | Exit |
|---|---|
| Focused run 1 (development) | **1** |
| Focused run 2 (development) | **1** |
| Focused run 3 | 0 |
| Repetitions 1–20 | 0 each |
| Full harness run 1 | **0** |
| Full harness run 2 | **0** |

## 9. Cleanup and backup verification

| Item | Value |
|---|---|
| Backup | `/srv/phpnuxbill/wp8-stubnas-prefix-backup-20261011T053050Z` (0700): HEAD, status, `StubNas.php`, `stub_nas.php`, `CoAClientTest.php`. `SHA256SUMS` OK at creation and at the end |
| Tested = final code | the last edit was 05:41Z, before focused run 3, the after-probe (which records the same hashes `5b640c01…` / `b690ffbd…`), the 20 repetitions and both full runs |
| Leftovers | 0 harness / php82 containers; 0 `stub_nas.php` or `php -S` processes on the host; 0 `/tmp/wp4_nas_*` files |
| Secret sweep | 15 changed / new files, 4 lab secret values, **0 hits** |
| Manifest | 42/42 |
| Scope | 0 diff lines in `install/`, `system/`, `dev/` and the existing tests |
| Git | `HEAD` = `origin/wp8/integration-poc` = `51e9915d`; working tree: `M` `StubNas.php`, `M` `stub_nas.php`, `??` `StubNasReliabilityTest.php`, `??` `evidence/ti02/`. No commit, push, reset, stash or clean |

## 10. Remaining risks

1. **The port-exclusivity check relies on Linux `/proc/net/udp`**, which the harness image has; without it the stub
   refuses to start ("cannot verify port"), so it fails loudly.
2. **Two stubs binding the same port at the same instant** both see 2 sockets and both refuse. Each retries another
   port (up to 5). This is availability only, never a shared port.
3. **`received()` now throws** where it used to return `[]`. A future stub misuse becomes a clear error instead of a
   count mismatch. That is intended.
4. **Still open from the AppServer round:** N-1 (a SIGKILLed PHPUnit cannot stop its server group; the same applies to
   an unfinished stub, bounded by the stub's own 8 / 20 s deadline and the container removal) and N-2 (a failed
   `Wp2HttpTestCase::setUpBeforeClass()` leaves its app copy and test database).
5. **Still open from the WP8 notes:** AV-1, AV-2, AV-3, MAC-H1A, H-2, V-1, the `Message.php` warnings of the WP8 suite,
   R-WP8-CARRY-01/02/03, R-HF-CARRY-01. H-2, V-1 and A3 are not started.

## Post-acceptance clarification (2026-10-11, owner final check item 6; earlier text kept unchanged)

Remaining risk §10.2 above says "never a shared port". **That is too strong.** The `/proc/net/udp(6)` check is a
**post-bind detection, not an atomic exclusivity guarantee.**

**Between two stubs, collisions are excluded.** Each stub binds first and only then counts the sockets on its port, and
it keeps its socket until it exits. If stub A counted 1, stub B was not bound at that moment. When B binds later, it
counts A as well and refuses. Two stubs that bind the same port at the same moment may both refuse, then retry; that
costs availability only.

**Not covered:**
- a process that is **not** a stub and binds the same UDP port with `SO_REUSEADDR` **after** the stub's check (PHP
  stream sockets do this by default);
- any process whose socket is not visible in this network namespace's `/proc/net/udp(6)`.

In the harness runner container no such process exists. The sampler saw at most 1 live stub, and the stubs use ports
30000–39999. The limitation stays recorded as a test-infrastructure note.
