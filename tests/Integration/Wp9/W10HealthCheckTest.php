<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP10 minimum operational telemetry (AD-21 / REL-05, N-09): system/reseller/health.php
 *  - a consistent instance reports OK (exit 0) on every check;
 *  - a stuck activation (CRITICAL), its held reservation (WARNING), old PENDING PPPoE / Hotspot admission claims
 *    (WARNING) are reported as counts / ages only: no username, token or secret appears in the output;
 *  - --finance reports a wallet changed outside the ledger (CRITICAL);
 *  - the script is CLI only (404 through the web server).
 */
final class W10HealthCheckTest extends ActivationTestCase
{
    private static ?AppServer $app = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $fx = new FixtureBuilder(dirname(__DIR__, 3));
        $fx->loadSqlFile(static::$db->pdo, dirname(__DIR__, 3) . '/install/freeradius/nuxr_admission.sql');
        $fx->loadSqlFile(static::$db->pdo, dirname(__DIR__, 3) . '/install/freeradius/nuxr_hs_admission.sql');
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

    /** @return array{exit:int, raw:string, by:array<string, array>} */
    private function health(string ...$args): array
    {
        $r = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', self::$app->dir . '/system/reseller/health.php', '--json', ...$args]);
        $j = json_decode(trim((string) $r['raw']), true);
        $this->assertIsArray($j, (string) $r['raw']);
        return ['exit' => $r['exit'], 'raw' => (string) $r['raw'], 'by' => array_column($j['checks'], null, 'check')];
    }

    public function testHealthReportsConsistentAndDegradedStatesWithoutRecordContent(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $this->assertSame('COMPLETED', $this->activate($leaf, $this->customer($leaf), $plan, 'NEW')['status']);
        $ok = $this->health('--finance');
        $this->assertSame(0, $ok['exit'], $ok['raw']);
        foreach (['db', 'schema', 'activations_stale', 'reconciliation', 'reservations', 'admission_pending', 'vouchers_review', 'balance_held', 'accounting', 'coa_failures', 'finance'] as $check) {
            $this->assertSame('OK', $ok['by'][$check]['status'] ?? null, "{$check}: " . $ok['raw']);
        }

        // a stuck activation (crash before provisioning) with its ACTIVE reservation
        $c = $this->customer($leaf);
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.before_provision') {
                throw new \RuntimeException('crash');
            }
        };
        try {
            $this->activate($leaf, $c, $plan, 'NEW');
            $this->fail('no crash');
        } catch (\RuntimeException) {
        } finally {
            ActivationFaults::$hook = null;
        }
        static::$db->exec("UPDATE tbl_reseller_activations SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 1 HOUR WHERE customer_id = {$c}");
        static::$db->exec("UPDATE tbl_manager_wallet_reservations SET created_at = UTC_TIMESTAMP(6) - INTERVAL 2 HOUR WHERE status = 'ACTIVE'");
        // old PENDING admission claims (PPPoE and Hotspot)
        $user = 'w10hc' . bin2hex(random_bytes(3));
        $token = bin2hex(random_bytes(16));
        $q = static fn(string $v): string => static::$db->db()->pdo->quote($v);
        static::$db->exec("INSERT INTO nuxr_admission (username, state, token, nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator, session_user, attach_count, created_at, updated_at)
            VALUES ({$q($user)}, 'PENDING', '{$token}', '10.0.0.1', '81a0', 'aa:bb', 'ether1', '" . str_repeat('c', 32) . "', {$q($user)}, 0, UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE, UTC_TIMESTAMP(6))");
        $hsToken = bin2hex(random_bytes(16));
        static::$db->exec("INSERT INTO nuxr_hs_admission (username, slot, state, token, nas, acct_session_id, calling_station_id, request_authenticator, session_user, created_at, updated_at)
            VALUES ({$q($user)}, 1, 'PENDING', '{$hsToken}', '10.0.0.1', '81a1', 'aa:bc', '" . str_repeat('d', 32) . "', {$q($user)}, UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE, UTC_TIMESTAMP(6))");
        $leafWallet = (string) static::$db->value('SELECT balance FROM tbl_manager_wallets WHERE manager_id = ?', [$leaf]);
        static::$db->exec("UPDATE tbl_manager_wallets SET balance = balance + 5 WHERE manager_id = {$leaf}");
        try {
            $bad = $this->health('--finance');
            $this->assertSame(2, $bad['exit'], $bad['raw']);
            $this->assertSame('CRITICAL', $bad['by']['activations_stale']['status'], $bad['raw']);
            $this->assertSame('WARNING', $bad['by']['reservations']['status'], $bad['raw']);
            $this->assertSame('WARNING', $bad['by']['admission_pending']['status'], $bad['raw']);
            $this->assertSame(2.0, (float) $bad['by']['admission_pending']['value'], $bad['raw']);
            $this->assertSame('CRITICAL', $bad['by']['finance']['status'], $bad['raw']);
            $this->assertSame('OK', $bad['by']['db']['status'], $bad['raw']);
            foreach ([$user, $token, $hsToken, TestDatabase::rootPassword(), (string) self::$app->appSecret()] as $secret) {
                $this->assertStringNotContainsString($secret, $bad['raw'], 'counts and ages only');
            }
            $text = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', self::$app->dir . '/system/reseller/health.php']);
            $this->assertSame(2, $text['exit']);
            $this->assertMatchesRegularExpression('/^CRITICAL\s+activations_stale\s/m', (string) $text['raw']);
        } finally {
            static::$db->exec('UPDATE tbl_manager_wallets SET balance = ' . static::$db->db()->pdo->quote($leafWallet) . " WHERE manager_id = {$leaf}");
        }
    }

    public function testHealthIsCliOnly(): void
    {
        self::$app->start();
        try {
            $this->assertSame(404, self::$app->client()->raw('GET', 'system/reseller/health.php')->status);
        } finally {
            self::$app->stop();
        }
    }
}
