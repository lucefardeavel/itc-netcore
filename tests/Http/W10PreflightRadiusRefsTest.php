<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Fresh-install proof 2026-10-29, R3-6: preflight radius_checks reads the expected admission CHECK definitions from
 * install/freeradius/. With those files removed (the runbook used to say "remove install/") there was nothing to compare
 * and the comparison passed vacuously. Required: a missing reference file makes radius_checks FAIL and names the file;
 * with the files present no such problem is reported. Runs against the disposable app copy only (the copy's admission
 * tables may be absent, so only the reference-file problem is asserted, not an overall PASS).
 */
final class W10PreflightRadiusRefsTest extends Wp2HttpTestCase
{
    /** @return array{status:?string,detail:string} the radius_checks result */
    private function radiusCheck(): array
    {
        $r = RunnerProcess::exec([PHP_BINARY, static::$app->dir . '/system/reseller/preflight.php', '--setup', '--json']);
        $this->assertNotSame([], $r['report'], $r['raw']);
        foreach ($r['report']['results'] ?? [] as $row) {
            if (($row['check'] ?? null) === 'radius_checks') {
                return ['status' => $row['status'] ?? null, 'detail' => (string) ($row['detail'] ?? ''), 'raw' => $r['raw']];
            }
        }
        $this->fail('no radius_checks result: ' . $r['raw']);
    }

    public function testMissingReferenceDefinitionsFailTheRadiusCheck(): void
    {
        $dir = static::$app->dir . '/install/freeradius/';
        $this->assertStringNotContainsString('reference definitions', $this->radiusCheck()['detail'], 'control: references present');
        foreach (['nuxr_admission.sql', 'nuxr_hs_admission.sql'] as $f) {
            rename($dir . $f, $dir . $f . '.away');
            try {
                $c = $this->radiusCheck();
                $this->assertSame('FAIL', $c['status'], "{$f} removed: radius_checks must fail");
                $this->assertStringContainsString("reference definitions install/freeradius/{$f}", $c['detail'], 'the missing file is named; preflight output: ' . substr($c['raw'], 0, 2000));
            } finally {
                rename($dir . $f . '.away', $dir . $f);
            }
        }
        $this->assertStringNotContainsString('reference definitions', $this->radiusCheck()['detail'], 'restored');
    }
}
