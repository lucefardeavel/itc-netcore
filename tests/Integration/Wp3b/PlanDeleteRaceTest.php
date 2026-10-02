<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\FinanceWorkers;
use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Owner code review (WP3B fix round 2): the legacy plan delete versus a concurrent pricing writer that
 * creates a plan reference (provider base cost). The real HTTP delete path runs in its own request; the
 * real PricingService::setBaseCost runs in a worker process. Only two outcomes are possible:
 *  - delete wins the serialization: the pricing writer waits, then finds no plan; the plan is gone from
 *    the database and the device (hook and device removal ran once);
 *  - pricing wins the serialization: the reference exists before the delete's final check; the delete is
 *    refused before the hook and the device call; the plan stays in the database and on the device.
 * Never: device removal succeeded while the database plan was kept (the reviewed TOCTOU split).
 */
final class PlanDeleteRaceTest extends Wp2HttpTestCase
{
    private const ROUNDS = 8;

    private const DELETE_WINS = ['http' => 302, 'plan_in_db' => false, 'hook_ran' => true, 'device_removed' => true, 'base_cost_row' => false, 'pricing' => 'NOT_FOUND', 'refusals_audited' => 0];
    private const PRICING_WINS = ['http' => 302, 'plan_in_db' => true, 'hook_ran' => false, 'device_removed' => false, 'base_cost_row' => true, 'pricing' => 'OK', 'refusals_audited' => 1];

    /** Test-only plugin (disposable app copy only): marks the delete_plan hook and can hold it on a barrier file. */
    private const PLUGIN = <<<'PHP'
<?php
// test-only plugin (disposable app copy only)
register_hook('delete_plan', 'wp3b_race_delete_plan');
function wp3b_race_delete_plan() {
    $dir = __DIR__ . '/../../wp2_probe/';
    @file_put_contents($dir . 'race_hook.log', json_encode('delete_plan') . "\n", FILE_APPEND | LOCK_EX);
    $deadline = microtime(true) + 30;
    while (is_file($dir . 'race_hold') && microtime(true) < $deadline) {
        usleep(5000);
        clearstatcache();
    }
}
PHP;

    /** The delete request, sent from its own process; optionally waits for a go file first. */
    private const REQUEST = <<<'PHP'
$go = getenv('RACE_GO');
if ($go !== false && $go !== '') {
    $deadline = microtime(true) + 30;
    while (!is_file($go) && microtime(true) < $deadline) { usleep(1000); }
}
$ch = curl_init(getenv('RACE_URL'));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => getenv('RACE_BODY'), CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Cookie: ' . getenv('RACE_COOKIE')]]);
curl_exec($ch);
echo '@@HTTP@@', (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
PHP;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        file_put_contents(static::$app->dir . '/system/plugin/wp3b_delete_race.php', self::PLUGIN);
    }

    private function plan(): int
    {
        $seed = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seed['id']);
        $seed['name_plan'] = 'race_' . bin2hex(random_bytes(4));
        $seed['type'] = 'Hotspot';
        return static::$db->insertRow('tbl_plans', $seed);
    }

    /** @return array{proc:resource,pipes:array} */
    private function startDelete(HttpClient $c, int $planId, string $go = ''): array
    {
        $token = $c->token();
        $cookies = [];
        foreach ($c->cookies as $k => $v) {
            $cookies[] = $k . '=' . $v;
        }
        $env = ['RACE_URL' => $c->url('services/delete/' . $planId, ['token' => $token]), 'RACE_BODY' => http_build_query(['csrf_token' => $token]),
            'RACE_COOKIE' => implode('; ', $cookies), 'RACE_GO' => $go] + getenv();
        $proc = proc_open([PHP_BINARY, '-r', self::REQUEST], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('delete request did not start');
        }
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function finishDelete(array $h): int
    {
        $out = stream_get_contents($h['pipes'][1]) . stream_get_contents($h['pipes'][2]);
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        proc_close($h['proc']);
        $pos = strrpos($out, '@@HTTP@@');
        if ($pos === false) {
            throw new \RuntimeException('delete request failed: ' . $out);
        }
        return (int) substr($out, $pos + 8);
    }

    private static function waitFor(callable $condition, string $what): void
    {
        $deadline = microtime(true) + 30;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('barrier: ' . $what);
            }
            usleep(5000);
        }
    }

    /** True when one connection is blocked on a lock (the tree lock here) for a stable period. */
    private static function oneBlocked(): bool
    {
        try {
            FinanceWorkers::waitBlocked(static::$db, 1, 10.0);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function setBase(int $planId, array $extra = []): array
    {
        return ['op' => 'set_base', 'actor' => $this->sid('ad'), 'plan' => $planId, 'cost' => '10'] + $extra;
    }

    /** One outcome record, whatever the order. */
    private function outcome(int $planId, int $http, array $pricing, int $auditsBefore): array
    {
        return [
            'http' => $http,
            'plan_in_db' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_plans WHERE id = ?', [$planId]) === 1,
            'hook_ran' => static::$app->probe('race_hook') !== [],
            'device_removed' => in_array('remove_plan', array_column(static::$app->probe('device'), 'call'), true),
            'base_cost_row' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_plan_base_costs WHERE plan_id = ?', [$planId]) === 1,
            'pricing' => $pricing['ok'] ? 'OK' : ($pricing['reason'] . (isset($pricing['error']) ? ': ' . $pricing['error'] : '')),
            'refusals_audited' => count($this->auditRows('plan.delete_refused')) - $auditsBefore,
        ];
    }

    private function assertNoSplit(array $o, string $label): void
    {
        $this->assertFalse($o['device_removed'] && $o['plan_in_db'], "{$label}: device removal with the database plan kept (TOCTOU split)");
    }

    /**
     * Delete wins: the delete has passed its final check and is inside its effects (held in the
     * delete_plan hook) when the pricing writer starts; the writer must wait and then find no plan.
     */
    public function testDeleteWinsSerialization(): void
    {
        $client = $this->staff('sa');
        $hold = static::$app->dir . '/wp2_probe/race_hold';
        $id = $this->plan();
        static::$app->clearProbes();
        $audits = count($this->auditRows('plan.delete_refused'));
        touch($hold);
        try {
            $delete = $this->startDelete($client, $id);
            self::waitFor(static fn() => static::$app->probe('race_hook') !== [], 'delete reached its hook');
            $worker = FinanceWorkers::start(static::$db, $this->setBase($id));
            $waited = self::oneBlocked();
        } finally {
            @unlink($hold);
        }
        $http = $this->finishDelete($delete);
        $pricing = FinanceWorkers::finish($worker);
        $o = $this->outcome($id, $http, $pricing, $audits);
        $this->assertNoSplit($o, 'delete first');
        $this->assertTrue($waited, 'the pricing writer waited for the whole delete unit (tree lock)');
        $this->assertSame(self::DELETE_WINS, $o);
    }

    /**
     * Pricing wins: the writer has inserted the base-cost reference (tree lock held, not committed) when
     * the delete arrives; the delete must wait and then refuse before its hook and device call.
     */
    public function testPricingWinsSerialization(): void
    {
        $client = $this->staff('sa');
        $pause = sys_get_temp_dir() . '/wp3b_pause_' . bin2hex(random_bytes(6));
        $id = $this->plan();
        static::$app->clearProbes();
        $audits = count($this->auditRows('plan.delete_refused'));
        touch($pause);
        try {
            $worker = FinanceWorkers::start(static::$db, $this->setBase($id, ['pause_at' => 'pricing.after_write', 'pause_file' => $pause]));
            self::waitFor(static fn() => is_file($pause . '.entered'), 'pricing wrote its reference');
            $delete = $this->startDelete($client, $id);
            $waited = self::oneBlocked();
        } finally {
            @unlink($pause);
        }
        $pricing = FinanceWorkers::finish($worker);
        $http = $this->finishDelete($delete);
        @unlink($pause . '.entered');
        $o = $this->outcome($id, $http, $pricing, $audits);
        $this->assertNoSplit($o, 'pricing first');
        $this->assertTrue($waited, 'the delete waited for the pricing writer before its final check (tree lock)');
        $this->assertSame(self::PRICING_WINS, $o);
    }

    /**
     * Timeout policy: when the tree lock stays busy past its 10 s timeout, the delete fails closed BEFORE its
     * final check and every effect (no hook, no device call, no database change) and asks to retry.
     */
    public function testLockTimeoutRefusesBeforeAnyEffect(): void
    {
        $client = $this->staff('sa');
        $id = $this->plan();
        static::$app->clearProbes();
        $audits = count($this->auditRows('plan.delete_refused'));
        $busy = FinanceWorkers::holdTree(static::$db);
        try {
            $http = $this->finishDelete($this->startDelete($client, $id));
        } finally {
            FinanceWorkers::release($busy);
        }
        $o = $this->outcome($id, $http, ['ok' => false, 'reason' => 'NONE'], $audits);
        $this->assertNoSplit($o, 'lock timeout');
        $this->assertSame(['http' => 302, 'plan_in_db' => true, 'hook_ran' => false, 'device_removed' => false, 'base_cost_row' => false, 'pricing' => 'NONE', 'refusals_audited' => 0], $o);
        $this->assertStringContainsString('The hierarchy is busy; please try again.', $client->get('services/hotspot')->body, 'retry message shown');
    }

    /** Free-running race (both start on one go signal): only the two coherent outcomes ever occur. */
    public function testConcurrentDeleteAndPricingNeverSplit(): void
    {
        $client = $this->staff('sa');
        $winners = [];
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $id = $this->plan();
            static::$app->clearProbes();
            $audits = count($this->auditRows('plan.delete_refused'));
            $go = sys_get_temp_dir() . '/wp3b_go_' . bin2hex(random_bytes(6));
            $delete = $this->startDelete($client, $id, $go);
            $worker = FinanceWorkers::start(static::$db, $this->setBase($id, ['go_file' => $go]));
            usleep(300000);
            touch($go);
            try {
                $http = $this->finishDelete($delete);
                $pricing = FinanceWorkers::finish($worker);
            } finally {
                @unlink($go);
            }
            $o = $this->outcome($id, $http, $pricing, $audits);
            $this->assertNoSplit($o, "round {$i}");
            $this->assertContains($o, [self::DELETE_WINS, self::PRICING_WINS], "round {$i}: coherent outcome");
            $key = $o === self::DELETE_WINS ? 'delete-first' : 'pricing-first';
            $winners[$key] = ($winners[$key] ?? 0) + 1;
        }
        if (getenv('WP3B_EVIDENCE')) {
            fwrite(STDERR, 'PLAN-DELETE RACE WINNERS: ' . json_encode($winners) . "\n");
        }
    }
}
