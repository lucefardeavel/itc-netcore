---
name: phpnuxbill-dev-env
description: "How the PHPNuxBill dev environment on vmi3577555 was bootstrapped, plus known harness quirks (CliOnlyTest flake, composer)"
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-02T08:11:58.045Z
---

Bootstrapped 2026-10-01 at /srv/phpnuxbill/dev (root-owned clone of the private lucefardeavel/phpnuxbill-reseller). The repo's governance (docs/claude-development-pack/review/ACCEPTANCE_LEDGER.md, owner-review/) is authoritative over this note.

- Only root has GitHub credentials (`gh` as lucefardeavel). The `ubuntu` user (uid 1000, NOPASSWD sudo) has none, so non-root dev needs the owner to set up its own auth. Don't copy root's token.
- No host PHP or Composer. tests/vendor was installed with `docker run --rm --name nuxbill-reseller-test-composer -v /srv/phpnuxbill/dev/tests:/app -w /app composer:2 install --prefer-source`. It takes about 11 min (full git clones) and yields about 3 GB, matching tests/composer.lock exactly (PHPUnit 11.5.56).
- PHP 8.3 lint observation: `php:8.3-cli` one-shot with `--network none` and a read-only mount.
- Full `dev/test/run-tests.sh` takes about 27–34 min wall time. MariaDB about 10 min, MySQL about 17–22 min.
- Known intermittent failure: `CliOnlyTest::testNewPhpFilesRefuseHttpExecution` compares the server-global `Connections` counter. The compose healthcheck adds 2 connections every 3 s on MariaDB (1 on MySQL), so the test sometimes fails with +1/+2 (seen 1 of 2 full MariaDB runs and 1 of 20 isolated runs). Classified ENVIRONMENT/test-harness timing race, not a product regression. Fixing it needs owner approval (test code change).
- Second known intermittent failure: `tests/Support/AppServer.php` stop() only terminates the `php -S` master. Its `PHP_CLI_SERVER_WORKERS` children keep the random port (20000–29999) and serve a 404. A later `Wp2HttpTestCase` class that draws the same port fails every test with "no CSRF token on admin: 404". Seen once (StaffManagementTest, MySQL, 2026-10-02); rerunning passes. Same classification and the same owner-approval rule as above.
- Long runs: launch detached with `setsid nohup`, because a tool call is killed at 30 min. Never `pkill -f` a pattern that also appears in your own command line.
- Route evidence: run RouteMatrixTest with `-e WP2_EVIDENCE=1` via `docker compose run` (run-tests.sh does not forward it); rows go to stderr.

Related: [[shared-host-avaia-boundary]]
