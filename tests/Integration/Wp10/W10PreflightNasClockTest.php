<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;

/**
 * RAD-3a owner decision (2026-10-28): V1 keeps the session-end cut-off on the NAS accounting stamp (10 s allowance,
 * B-L08) subject to operational controls. The release preflight's clock readiness check time.nas_clock reports a NAS
 * clock that is ahead: PASS within 5 s, WARN above 5 s, FAIL above the 10 s hard limit, naming the NAS (never a
 * subscriber). Read-only. A NAS clock that is behind is not visible to it (NTP + TA-02, documented).
 */
final class W10PreflightNasClockTest extends ActivationTestCase
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

    private function clockCheck(): array
    {
        $r = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', self::$app->dir . '/system/reseller/preflight.php', '--json']);
        $j = json_decode(substr((string) $r['raw'], (int) strpos((string) $r['raw'], '{')), true);
        $this->assertIsArray($j, (string) $r['raw']);
        return array_column($j['results'], null, 'check')['time.nas_clock'] ?? [];
    }

    private function openSession(string $nas, int $aheadSeconds): void
    {
        static::$db->insertRow('radacct', ['acctsessionid' => 'clk' . bin2hex(random_bytes(3)), 'acctuniqueid' => bin2hex(random_bytes(16)),
            'username' => 'rad3a-clock-user', 'nasipaddress' => $nas, 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 120),
            'acctupdatetime' => gmdate('Y-m-d H:i:s', time() + $aheadSeconds), 'acctsessiontime' => 120, 'framedipaddress' => '10.42.7.9']);
    }

    public function testNasClockAheadIsReportedByTheReleasePreflight(): void
    {
        static::$db->exec("DELETE FROM radacct WHERE username = 'rad3a-clock-user'");
        try {
            $none = $this->clockCheck();
            $this->assertSame('PASS', $none['status'] ?? null, 'no open session ahead ' . json_encode($none));
            $this->openSession('10.99.1.1', 2);
            $this->assertSame('PASS', $this->clockCheck()['status'] ?? null, 'a 2 s stamp is normal (NTP)');
            $this->openSession('10.99.1.2', 9);
            $warn = $this->clockCheck();
            $this->assertSame('WARN', $warn['status'] ?? null, json_encode($warn));
            $this->assertStringContainsString('10.99.1.2 +', (string) $warn['detail'], 'the NAS is named');
            // FAIL case set right before its run, with margins (a preflight run takes seconds): +30 s and +120 s
            static::$db->exec("UPDATE radacct SET acctupdatetime = UTC_TIMESTAMP() + INTERVAL 30 SECOND WHERE username = 'rad3a-clock-user' AND nasipaddress = '10.99.1.2'");
            $this->openSession('10.99.1.3', 120);
            $before = static::$db->rows("SELECT * FROM radacct WHERE username = 'rad3a-clock-user' ORDER BY radacctid");
            $fail = $this->clockCheck();
            $this->assertSame('FAIL', $fail['status'] ?? null, 'beyond the 10 s cut-off allowance the release is not ready');
            $this->assertMatchesRegularExpression('/^NAS clock ahead: 10\.99\.1\.3 \+\d+s, 10\.99\.1\.2 \+\d+s /', (string) $fail['detail'], 'both NAS named, the furthest ahead first');
            $this->assertStringNotContainsString('rad3a-clock-user', json_encode($fail), 'counts NAS, never subscribers');
            $this->assertSame($before, static::$db->rows("SELECT * FROM radacct WHERE username = 'rad3a-clock-user' ORDER BY radacctid"), 'read-only');
        } finally {
            static::$db->exec("DELETE FROM radacct WHERE username = 'rad3a-clock-user'");
        }
    }
}
