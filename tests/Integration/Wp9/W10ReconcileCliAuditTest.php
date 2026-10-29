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
 * Final QA F-1 (owner rule: HELD funds are released only by an audited operator decision): the reconciliation CLI
 * `cancel` releases a reservation only with --operator and --reason, only for a STALE activation (the web rule
 * OD-7, re-checked on the locked row), and the audit record (operator, reason) is committed with the release.
 */
final class W10ReconcileCliAuditTest extends ActivationTestCase
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

    /** a fresh activation stopped after the funds were reserved (crash before execute): FUNDS_RESERVED */
    private function heldActivation(): string
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.before_execute') {
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

    /** @return array{0:string,1:string} activation status, reservation status */
    private function state(string $ref): array
    {
        $r = static::$db->rows('SELECT a.status, r.status AS rs FROM tbl_reseller_activations a JOIN tbl_manager_wallet_reservations r ON r.activation_id = a.id WHERE a.reference = ?', [$ref]);
        return [(string) $r[0]['status'], (string) $r[0]['rs']];
    }

    private function audits(string $ref): array
    {
        return static::$db->rows("SELECT event_type, after_json, reason, channel FROM tbl_reseller_audit_events WHERE event_type = 'activation.cancel' AND target_snapshot LIKE ?", ['%' . $ref . '%']);
    }

    public function testCancelReleasesOnlyWithAnAuditedOperatorDecisionOnAStaleActivation(): void
    {
        $ref = $this->heldActivation();
        $this->assertSame(['FUNDS_RESERVED', 'ACTIVE'], $this->state($ref), 'positive control: funds are held');

        // 1. no operator / reason: usage refusal, nothing changes
        foreach ([[], ['--operator=qa'], ['--operator=qa', '--reason=short'], ['--reason=checked on the NAS console']] as $extra) {
            $r = $this->cli(['cancel', $ref, ...$extra]);
            $this->assertSame(5, $r['exit'], json_encode($extra) . ' ' . $r['raw']);
        }
        $this->assertSame(['FUNDS_RESERVED', 'ACTIVE'], $this->state($ref), 'an anonymous cancel releases nothing');

        // 2. operator given, but the activation is fresh (not stale): refused, nothing changes
        $r = $this->cli(['cancel', $ref, '--operator=qa-operator', '--reason=customer asked to cancel']);
        $this->assertSame(2, $r['exit'], $r['raw']);
        $this->assertStringContainsString('ACTIVATION_IN_PROGRESS', $r['raw']);
        $this->assertSame(['FUNDS_RESERVED', 'ACTIVE'], $this->state($ref), 'a live activation is never cancelled from the CLI');
        $this->assertSame([], $this->audits($ref));

        // 3. stale + operator + reason: released, CANCELLED, audited with who and why
        static::$db->exec('UPDATE tbl_reseller_activations SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 1 HOUR WHERE reference = ' . static::$db->db()->pdo->quote($ref));
        $r = $this->cli(['cancel', $ref, '--operator=qa-operator', '--reason=customer asked to cancel']);
        $this->assertSame(0, $r['exit'], $r['raw']);
        $this->assertSame(['CANCELLED', 'RELEASED'], $this->state($ref));
        $a = $this->audits($ref);
        $this->assertCount(1, $a, 'exactly one audit record for the release');
        $this->assertStringContainsString('"operator":"qa-operator"', (string) $a[0]['after_json']);
        $this->assertSame('customer asked to cancel', $a[0]['reason']);
        $this->assertSame('CLI', $a[0]['channel']);

        // 4. a repeat is refused (terminal) and records nothing new
        $this->assertSame(2, $this->cli(['cancel', $ref, '--operator=qa-operator', '--reason=customer asked to cancel'])['exit']);
        $this->assertCount(1, $this->audits($ref));
    }
}
