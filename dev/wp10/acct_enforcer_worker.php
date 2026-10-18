<?php
// WP10 accounting-load helper (test tooling only): the quota enforcer's pass loop as its OWN process, the way production runs it
// (cron `quota.php enforce --loop=55 --every=N`), so the load generator and the enforcer compete for the database as they do live.
// The termination is a stub (no NAS): only the scan and the bookkeeping of a pass are timed. Usage: php acct_enforcer_worker.php SECONDS EVERY OUTFILE
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/tests/bootstrap.php';

use Reseller\Radius\QuotaEnforcer;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\TestDatabase;

[, $seconds, $every, $out] = $argv + [null, 40, 5, '/tmp/enforcer.jsonl'];
$pdo = FixtureBuilder::connect((string) getenv('WP1_DB_HOST'), TestDatabase::rootPassword(), 'nuxr_radius');
$enforcer = new QuotaEnforcer($pdo, static fn(array $sessions): array => []);
$end = microtime(true) + (float) $seconds;
$next = microtime(true);
while (microtime(true) < $end) {
    if (microtime(true) >= $next) {
        $t = microtime(true);
        $enforcer->pass();
        $d = microtime(true) - $t;
        file_put_contents($out, json_encode(['pass_s' => round($d, 3)]) . "\n", FILE_APPEND);
        $next = microtime(true) + (float) $every;   // `--every`: the interval between the END of a pass and the next
    }
    usleep(20000);
}
