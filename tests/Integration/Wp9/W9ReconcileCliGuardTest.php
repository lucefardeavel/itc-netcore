<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP9-B / N-06: the operator CLI (system/reseller/reconcile.php) never acts on a LIVE activation:
 * `reconcile --stale-seconds` below the 300 s floor is refused, and `resume` requires a stale activation
 * (the web operator rule). Positive control: a stale activation is resumed to COMPLETED.
 */
final class W9ReconcileCliGuardTest extends ActivationTestCase
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

    private function cli(array $args): array
    {
        return RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', self::$app->dir . '/system/reseller/reconcile.php', ...$args]);
    }

    /** a fresh activation stopped at $point (crash) */
    private function stopped(string $point): string
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        ActivationFaults::$hook = static function (string $p) use ($point): void {
            if ($p === $point) {
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
        return (string) static::$db->value('SELECT reference FROM tbl_reseller_activations WHERE customer_id = ?', [$c]);
    }

    private function actStatus(string $ref): string
    {
        return (string) static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]);
    }

    public function testLiveInFlightActivationIsNotReconciledThroughALoweredFloor(): void
    {
        $ref = $this->stopped('activation.before_provision');
        $this->assertSame('PROVISIONING', $this->actStatus($ref));
        $r = $this->cli(['reconcile', $ref, '--stale-seconds=0']);
        $this->assertSame(5, $r['exit'], $r['raw']);
        $this->assertSame('PROVISIONING', $this->actStatus($ref), 'a live in-flight activation is not touched');
        $this->assertSame(2, $this->cli(['reconcile', $ref])['exit'], 'positive: the default floor still refuses a fresh in-flight activation');
        static::$db->exec("UPDATE tbl_reseller_activations SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 1 HOUR WHERE reference = " . static::$db->db()->pdo->quote($ref));
    }

    public function testResumeRequiresAStaleActivation(): void
    {
        $ref = $this->stopped('activation.before_execute');
        $this->assertSame('FUNDS_RESERVED', $this->actStatus($ref));
        $r = $this->cli(['resume', $ref]);
        $this->assertSame(2, $r['exit'], $r['raw']);
        $this->assertSame('FUNDS_RESERVED', $this->actStatus($ref), 'a fresh activation is not driven from the CLI');
        static::$db->exec("UPDATE tbl_reseller_activations SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 1 HOUR WHERE reference = " . static::$db->db()->pdo->quote($ref));
        $r = $this->cli(['resume', $ref]);
        $this->assertSame(0, $r['exit'], $r['raw']);
        $this->assertSame('COMPLETED', $this->actStatus($ref), 'positive: a stale activation is resumed: ' . $r['raw'] . json_encode(static::$db->rows('SELECT e.event_type, e.from_state, e.to_state, e.error_code FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ?', [$ref])));
    }
}
