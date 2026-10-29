<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Fresh-install proof 2026-10-29, gap G-4: when the runtime database account of config.php cannot connect, the release
 * preflight died with a PHP fatal error (uncaught PDOException in init.php, exit 255, stack trace) instead of reporting.
 *
 * Required: a FAIL check line "db.connect" (text and --json), preflight=FAIL and the documented failing exit code 4; the
 * output names neither the account nor its password (no driver message, no trace). With a working account there is no
 * db.connect line and the other checks run as before.
 */
final class W10PreflightDbConnectTest extends Wp2HttpTestCase
{
    /** @return array{exit:int,raw:string,report:array} raw = stdout + stderr; report = the JSON document on stdout */
    private function preflight(bool $json): array
    {
        // stderr is captured too: nothing of the account may appear in either stream
        $r = RunnerProcess::exec(array_merge([PHP_BINARY, static::$app->dir . '/system/reseller/preflight.php', '--setup'], $json ? ['--json'] : []));
        return ['exit' => $r['exit'], 'raw' => $r['raw'], 'report' => $r['report']];
    }

    public function testAnUnusableRuntimeAccountIsAFailLineNotACrash(): void
    {
        $user = 'w10nouser_' . bin2hex(random_bytes(3));
        $pass = 'w10-wrong-' . bin2hex(random_bytes(8));
        $cases = [
            'unknown account' => ['$db_user = ' . var_export($user, true) . ";\n\$db_pass = " . var_export($pass, true) . ";\n", [$user, $pass]],
            'wrong password' => ['$db_pass = ' . var_export($pass, true) . ";\n", [$pass]],
            'unknown database' => ['$db_name = ' . var_export('w10_nodb_' . bin2hex(random_bytes(3)), true) . ";\n", [TestDatabase::rootPassword()]],
        ];
        try {
            foreach ($cases as $case => [$extra, $secrets]) {
                static::$app->writeConfig(static::$app->apiSecret(), $extra);
                $t = $this->preflight(false);
                $this->assertSame(4, $t['exit'], "{$case}: the documented failing exit code, not a crash: " . $t['raw']);
                $this->assertMatchesRegularExpression('/^FAIL\s+db\.connect\s+\S/m', $t['raw'], "{$case}: a db.connect FAIL line");
                $this->assertStringContainsString('preflight=FAIL', $t['raw'], $case);
                $j = $this->preflight(true);
                $this->assertSame(4, $j['exit'], "{$case} --json: " . $j['raw']);
                $doc = $j['report'];
                $this->assertNotSame([], $doc, "{$case} --json: a JSON report on stdout: " . $j['raw']);
                $this->assertSame('FAIL', $doc['preflight'] ?? null);
                $this->assertSame('FAIL', array_column($doc['results'] ?? [], 'status', 'check')['db.connect'] ?? null, "{$case} --json: db.connect FAIL");
                foreach ([$t['raw'], $j['raw']] as $raw) {
                    foreach (['Fatal error', 'Uncaught', 'Stack trace', 'PDOException', 'Access denied'] as $crash) {
                        $this->assertStringNotContainsString($crash, $raw, "{$case}: no crash output / driver message");
                    }
                    foreach ($secrets as $s) {
                        $this->assertStringNotContainsString($s, $raw, "{$case}: no credential in the output");
                    }
                }
            }
        } finally {
            static::$app->writeConfig(static::$app->apiSecret());
        }
        // positive control: a working account reports no db.connect line and runs the checks
        $t = $this->preflight(true);
        $doc = $t['report'];
        $this->assertNotSame([], $doc, $t['raw']);
        $by = array_column($doc['results'] ?? [], 'status', 'check');
        $this->assertArrayNotHasKey('db.connect', $by);
        $this->assertSame('PASS', $by['isolation'] ?? null, $t['raw']);
        $this->assertSame('PASS', $by['schema'] ?? null, $t['raw']);
    }
}
