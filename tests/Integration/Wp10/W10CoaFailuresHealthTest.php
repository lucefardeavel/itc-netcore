<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;

/**
 * Lab closure RAD-2b (independent review 2026-10-26): health `coa_failures` judged a session end by words in the annotation
 * text. A Disconnect NAK to a retransmission that the Accounting-Stop confirmed reads "UNKNOWN:COA_RETRY_NAK" yet is
 * confirmed (false alarm); an ACK with no Stop reads "SUCCESS:COA_ACK" yet is NOT confirmed (missed); a failure that a
 * later operator session-end confirmed kept alarming for an hour. The check now counts activations whose LATEST
 * session-end annotation has confirmed = false (SessionTermination / ActivationService outcome contract).
 */
final class W10CoaFailuresHealthTest extends ActivationTestCase
{
    private static ?AppServer $app = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$app = AppServer::create(static::$db, []);
        self::$app->writeConfig(self::$app->apiSecret(), '$radius_host = ' . var_export(static::$db->host, true) . ";\n\$radius_user = 'root';\n"
            . '$radius_pass = ' . var_export(TestDatabase::rootPassword(), true) . ";\n\$radius_name = " . var_export(static::$db->name, true) . ";\n");
    }

    public static function tearDownAfterClass(): void
    {
        self::$app?->destroy();
        self::$app = null;
        parent::tearDownAfterClass();
    }

    private function annotate(string $reference, array $metadata): void
    {
        $a = static::$db->rows('SELECT id, status FROM tbl_reseller_activations WHERE reference = ?', [$reference])[0];
        $seq = (int) static::$db->value('SELECT MAX(sequence_no) FROM tbl_reseller_activation_events WHERE activation_id = ?', [$a['id']]) + 1;
        static::$db->insertRow('tbl_reseller_activation_events', ['activation_id' => $a['id'], 'sequence_no' => $seq, 'event_type' => 'ANNOTATION',
            'from_state' => $a['status'], 'to_state' => $a['status'], 'actor_type' => 'SYSTEM', 'metadata' => json_encode($metadata),
            'created_at' => gmdate('Y-m-d H:i:s.u')]);
    }

    private static function end(string $result, string $state): array
    {
        $confirmed = $state === 'CONFIRMED';
        return ['disconnect' => [$result], 'sessions' => [['radacctid' => 1, 'result' => $result, 'state' => $state, 'confirmed' => $confirmed]], 'confirmed' => $confirmed];
    }

    public function testOnlyActivationsWhoseLatestSessionEndIsUnconfirmedAreCounted(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $refs = [];
        for ($i = 0; $i < 5; $i++) {
            $r = $this->activate($leaf, $this->customer($leaf), $plan, 'NEW');
            $this->assertSame('COMPLETED', $r['status']);
            $refs[] = $r['reference'];
        }
        $this->annotate($refs[0], self::end('UNKNOWN:COA_RETRY_NAK', 'CONFIRMED'));        // confirmed by the Stop: not a failure
        $this->annotate($refs[1], self::end('SUCCESS:COA_ACK', 'ACK_WITHOUT_STOP'));        // ACK, no Stop: NOT confirmed
        $this->annotate($refs[2], self::end('UNKNOWN:COA_NO_RESPONSE', 'COA_NO_RESPONSE'));  // failed, then ...
        $this->annotate($refs[2], self::end('SUCCESS:COA_ACK', 'CONFIRMED') + ['operator' => 'session-end']);   // ... confirmed by the operator retry
        $this->annotate($refs[3], self::end('UNKNOWN:COA_NO_RESPONSE', 'COA_NO_RESPONSE'));  // still failed
        $this->annotate($refs[4], ['disconnect' => ['ERROR'], 'sessions' => [], 'confirmed' => false]);   // radacct unreadable

        $r = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', self::$app->dir . '/system/reseller/health.php', '--json']);
        $j = json_decode(trim((string) $r['raw']), true);
        $this->assertIsArray($j, (string) $r['raw']);
        $c = array_column($j['checks'], null, 'check')['coa_failures'];
        $this->assertSame('WARNING', $c['status'], (string) $r['raw']);
        $this->assertSame(3.0, (float) $c['value'], 'the ACK without Stop, the still-failed end and the unreadable radacct only: ' . $r['raw']);
    }
}
