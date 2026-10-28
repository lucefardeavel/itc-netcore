<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationFaults;
use Reseller\Activation\ReconciliationService;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\FinanceWorkers;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP9-B / R-4 (N-04, PLAN matrix #18): the real `php system/cron.php` expiry against a reactivation of the same
 * reseller-owned customer, in both orders. Secure result (owner criterion): never a paid COMPLETED activation
 * whose subscriber is suspended in RADIUS or whose recharge row is 'off'.
 *
 *  order A: the reactivation has provisioned and verified (paused right before its settlement) when cron runs;
 *  order B: cron expires the period first, then the reactivation runs (positive control: service restored).
 */
final class W9CronRenewalRaceTest extends ActivationTestCase
{
    private static ?AppServer $cron = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // an installed copy of the application on the same database, used only to run system/cron.php
        self::$cron = AppServer::create(static::$db, []);
        self::$cron->writeConfig(self::$cron->apiSecret(), '$radius_host = ' . var_export(static::$db->host, true) . ";\n\$radius_user = 'root';\n"
            . '$radius_pass = ' . var_export(TestDatabase::rootPassword(), true) . ";\n\$radius_name = " . var_export(static::$db->name, true) . ";\n");
        static::$db->exec("DELETE FROM tbl_appconfig WHERE setting IN ('radius_enable', 'enable_balance')");
        static::$db->insertRow('tbl_appconfig', ['setting' => 'radius_enable', 'value' => '1']);
        static::$db->insertRow('tbl_appconfig', ['setting' => 'enable_balance', 'value' => 'no']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$cron?->destroy();
        self::$cron = null;
        parent::tearDownAfterClass();
    }

    /** an expired period of a fresh reseller-owned customer: [customer id, plan id, leaf agent] */
    private function expiredCustomer(): array
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        static::$db->exec('UPDATE tbl_user_recharges SET expiration = ' . static::$db->db()->pdo->quote(gmdate('Y-m-d', time() - 86400 * 2)) . ", time = '00:00:00' WHERE customer_id = {$c}");
        return [$c, $plan, $leaf];
    }

    /** @return resource */
    private function startCron()
    {
        $p = proc_open([PHP_BINARY, '-d', 'error_log=' . self::$cron->dir . '/wp2_probe/php_error.log', 'cron.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$cron->dir . '/system');
        return [$p, $pipes];
    }

    private function finishCron(array $h): string
    {
        $out = stream_get_contents($h[1][1]) . stream_get_contents($h[1][2]);
        proc_close($h[0]);
        return $out;
    }

    private function assertSecure(int $c, array $act, string $label): void
    {
        $status = (string) $act['status'];
        $row = static::$db->rows('SELECT status, expiration, time FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$c])[0];
        $pw = (string) ($this->radcheck($this->username($c))['Cleartext-Password'] ?? '');
        $customerPw = (string) static::$db->value('SELECT password FROM tbl_customers WHERE id = ?', [$c]);
        if ($status === 'COMPLETED') {
            $this->assertSame('on', $row['status'], "{$label}: a paid COMPLETED period must not be 'off'");
            $this->assertGreaterThan(time(), strtotime($row['expiration'] . ' ' . $row['time']), "{$label}: the paid period is current");
            $this->assertSame($customerPw, $pw, "{$label}: a paid COMPLETED period must not be suspended in RADIUS");
        } else {
            $this->assertContains($status, ['RECONCILIATION_REQUIRED', 'FUNDS_RESERVED', 'RETRY_PENDING', 'FAILED'], "{$label}: unexpected state");
        }
    }

    public function testOrderAReactivationInFlightWhileCronExpires(): void
    {
        [$c, $plan, $leaf] = $this->expiredCustomer();
        $pause = sys_get_temp_dir() . '/w9r4_' . bin2hex(random_bytes(5));
        touch($pause);
        $w = FinanceWorkers::start(static::$db, ['op' => 'activate', 'actor' => $leaf, 'customer' => $c, 'plan' => $plan, 'action' => 'REACTIVATE',
            'nonce' => self::nonce(), 'pause_activation_at' => 'activation.before_commit', 'pause_file' => $pause]);
        $deadline = microtime(true) + 30;
        while (!is_file($pause . '.entered') && microtime(true) < $deadline) {
            usleep(20000);
            clearstatcache();
        }
        $this->assertFileExists($pause . '.entered', 'the reactivation reached its settlement point');
        $cron = $this->startCron();
        usleep(1500000); // cron has selected the expired row and reached it
        unlink($pause);
        $res = FinanceWorkers::finish($w);
        $out = $this->finishCron($cron);
        @unlink($pause . '.entered');
        $this->assertTrue($res['ok'], json_encode($res) . "\n" . $out);
        $this->assertSecure($c, $res['result'], 'order A');
    }

    /**
     * order C (release audit 2026-10-28): the reactivation is provisioned and verified but its settlement failed, so it
     * waits for the operator (RECONCILIATION_REQUIRED, reservation ACTIVE, service lock released) while the legacy
     * recharge row still shows the old, expired period. Cron runs, then the operator reconciles: the paid COMPLETED
     * period must not be suspended in RADIUS.
     */
    public function testOrderCParkedReactivationIsNotSuspendedByCronBeforeReconciliation(): void
    {
        [$c, $plan, $leaf] = $this->expiredCustomer();
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.after_settlement') {
                throw new \RuntimeException('injected at ' . $p);
            }
        };
        try {
            $act = $this->activate($leaf, $c, $plan, 'REACTIVATE');
        } finally {
            ActivationFaults::$hook = null;
        }
        $this->assertSame(['RECONCILIATION_REQUIRED', 'SETTLEMENT_UNKNOWN'], [$act['status'], $act['error_code']]);
        $out = $this->finishCron($this->startCron());
        $row = ReconciliationService::reconcile(ActivationActor::system('w9-r4-c'), $act['reference']);
        $this->assertSame('COMPLETED', $row['status'], $out);
        $this->assertSecure($c, $row, 'order C');
    }

    public function testOrderBCronFirstThenReactivation(): void
    {
        [$c, $plan, $leaf] = $this->expiredCustomer();
        $out = $this->finishCron($this->startCron());
        $this->assertStringContainsString('EXPIRED', $out);
        $this->assertSame('off', static::$db->value('SELECT status FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$c]));
        $act = $this->activate($leaf, $c, $plan, 'REACTIVATE');
        $this->assertSame('COMPLETED', $act['status']);
        $this->assertSecure($c, $act, 'order B');
    }
}
