<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;

/**
 * Owner decision TD-2 (2026-10-28): the V1 target configuration requires the WP8 Option G contract on every NAS (type
 * mikrotik_snmp + guard credentials). A NAS of another type (default "other") reproduces the Option G-off lockout of
 * FailureDrills Drill 7: a subscriber whose Accounting-Stop was lost stays locked out, even after the NAS's
 * Accounting-On. The release preflight must detect it (FAIL, naming the NAS) before launch; all mikrotik_snmp PASS.
 * PF-0 (owner decision 2026-10-28): no NAS at all is NOT_CONFIGURED - FAIL in the release preflight, WARN only with
 * --setup (initial installation); never an unconditional PASS. Read-only: the preflight never writes.
 */
final class W10PreflightNasVerificationTest extends ActivationTestCase
{
    private static ?AppServer $app = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$app = AppServer::create(static::$db, []);
        self::$app->writeConfig(self::$app->apiSecret(), '$radius_host = ' . var_export(static::$db->host, true) . ";\n\$radius_user = 'root';\n"
            . '$radius_pass = ' . var_export(TestDatabase::rootPassword(), true) . ";\n\$radius_name = " . var_export(static::$db->name, true) . ";\n");
        static::$db->exec("DELETE FROM tbl_appconfig WHERE setting = 'radius_enable'");
        static::$db->insertRow('tbl_appconfig', ['setting' => 'radius_enable', 'value' => '1']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$app?->destroy();
        self::$app = null;
        parent::tearDownAfterClass();
    }

    /** the nas.verification row of one preflight run */
    private function nasCheck(bool $setup = false): array
    {
        $r = RunnerProcess::exec(array_merge([PHP_BINARY, '-d', 'error_log=/dev/null', self::$app->dir . '/system/reseller/preflight.php', '--json'],
            $setup ? ['--setup'] : []));
        $j = json_decode(substr((string) $r['raw'], (int) strpos((string) $r['raw'], '{')), true);
        $this->assertIsArray($j, (string) $r['raw']);
        $this->assertSame($setup ? 'setup' : 'release', $j['mode'] ?? null);
        return array_column($j['results'], null, 'check')['nas.verification'] ?? [];
    }

    private function nas(string $ip, string $type): void
    {
        static::$db->insertRow('nas', ['nasname' => $ip, 'shortname' => 'pf' . substr(md5($ip), 0, 6), 'type' => $type, 'ports' => 3799, 'secret' => 'td2-preflight-secret', 'description' => 'TD-2 preflight']);
    }

    public function testPreflightFailsForANasWithoutLiveSessionVerification(): void
    {
        static::$db->exec('DELETE FROM nas');
        $none = $this->nasCheck();
        $this->assertSame('FAIL', $none['status'] ?? null, 'PF-0: the release preflight never passes without a NAS ' . json_encode($none));
        $this->assertStringStartsWith('NOT_CONFIGURED: no NAS configured', (string) $none['detail']);
        $setup = $this->nasCheck(true);
        $this->assertSame('WARN', $setup['status'] ?? null, 'PF-0: initial setup reports it, without failing ' . json_encode($setup));
        $this->assertStringStartsWith('NOT_CONFIGURED: no NAS configured', (string) $setup['detail']);

        $this->nas('10.99.0.1', 'mikrotik_snmp');
        $ok = $this->nasCheck();
        $this->assertSame('PASS', $ok['status'] ?? null, json_encode($ok));
        $this->assertStringContainsString('1 NAS, all of type mikrotik_snmp', (string) $ok['detail']);
        $this->assertSame('PASS', $this->nasCheck(true)['status'] ?? null, 'a verified NAS passes in setup mode too');

        $this->nas('10.99.0.2', 'other');
        $before = static::$db->rows('SELECT * FROM nas ORDER BY id');
        $bad = $this->nasCheck();
        $this->assertSame('FAIL', $bad['status'] ?? null, 'the Option G-off configuration is detected');
        $this->assertSame('FAIL', $this->nasCheck(true)['status'] ?? null, '--setup never excuses an unverified NAS');
        $this->assertStringContainsString('1 of 2 NAS not of type mikrotik_snmp: 10.99.0.2 (other)', (string) $bad['detail']);
        $this->assertStringNotContainsString('td2-preflight-secret', json_encode($bad), 'never the NAS secret');
        $this->assertSame($before, static::$db->rows('SELECT * FROM nas ORDER BY id'), 'read-only');
        static::$db->exec('DELETE FROM nas');
    }
}
