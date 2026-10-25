<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\StubNas;
use Reseller\Tests\Support\TestDatabase;

/**
 * V1 quota operations (owner QUOTA_V1_GB_SUPPORT_APPROVED): system/reseller/quota.php and the health check
 *  - health quota_enforcer: CRITICAL while a quota subscriber is online and the enforcer never ran / is silent > 3 min;
 *    WARNING while an over-quota session is still open; OK otherwise;
 *  - quota.php report lists the over-quota subscriber (read-only); enforce ends its session (Disconnect, confirmed by the
 *    NAS), writes one 'quota.enforced' audit record for the customer and the heartbeat;
 *  - only one enforcer at a time (database lock): a second one does nothing;
 *  - wrong usage exits 5; the script is CLI only (404 through the web server).
 */
final class V1QuotaOperationsTest extends ActivationTestCase
{
    private const SECRET = 'v1-quota-operations-secret';

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

    /** @return array{exit:int, raw:string} */
    private function cli(string $script, string ...$args): array
    {
        $r = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', self::$app->dir . '/system/reseller/' . $script, ...$args]);
        return ['exit' => $r['exit'], 'raw' => (string) $r['raw']];
    }

    private function healthQuota(): array
    {
        $r = $this->cli('health.php', '--json');
        $j = json_decode(trim($r['raw']), true);
        $this->assertIsArray($j, $r['raw']);
        return array_column($j['checks'], null, 'check')['quota_enforcer'] ?? [];
    }

    public function testEnforcerCliAuditHeartbeatLockAndHealth(): void
    {
        [, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $user = $this->username($c);
        static::$db->exec("DELETE FROM tbl_appconfig WHERE setting = 'nuxr_quota_enforcer_heartbeat'");
        static::$db->exec('DELETE FROM nas');
        foreach (['Max-Data' => '1000000', 'Nuxr-Period-Start' => (string) (time() - 3600)] as $a => $v) {
            static::$db->insertRow('radcheck', ['username' => $user, 'attribute' => $a, 'op' => ':=', 'value' => $v]);
        }
        $id = static::$db->insertRow('radacct', ['acctsessionid' => 'v1o' . bin2hex(random_bytes(3)), 'acctuniqueid' => bin2hex(random_bytes(16)), 'username' => $user,
            'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 600), 'acctupdatetime' => gmdate('Y-m-d H:i:s', time() - 30),
            'acctsessiontime' => 570, 'acctinputoctets' => 700000, 'acctoutputoctets' => 400000, 'framedipaddress' => '10.42.0.9']);

        // online quota subscriber, the enforcer never ran: CRITICAL
        $h = $this->healthQuota();
        $this->assertSame('CRITICAL', $h['status'] ?? null, json_encode($h));
        $this->assertStringNotContainsString($user, json_encode($h), 'counts only');

        $report = $this->cli('quota.php', 'report', '--json');
        $this->assertSame(0, $report['exit'], $report['raw']);
        $this->assertSame([['username' => $user, 'quota' => 'Max-Data', 'limit' => '1000000', 'usage' => '1100000', 'open_sessions' => [$id]]], json_decode(trim($report['raw']), true));

        // a second enforcer while one holds the lock does nothing
        $lock = 'nuxr_quota_' . substr(sha1(static::$db->name), 0, 20);
        $this->assertSame(1, (int) static::$db->value('SELECT GET_LOCK(?, 0)', [$lock]));
        try {
            $busy = $this->cli('quota.php', 'enforce', '--json');
            $this->assertSame(0, $busy['exit'], $busy['raw']);
            $this->assertSame(['enforce' => 'another enforcer is running'], json_decode(trim($busy['raw']), true));
        } finally {
            static::$db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        $this->assertSame(0, (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE event_type = 'quota.enforced'"));

        // the enforcer ends the session; the NAS answers "no such session" (confirmed)
        $stub = new StubNas(self::SECRET, 'nak503');
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'v1o', 'type' => 'other', 'ports' => $stub->port, 'secret' => self::SECRET, 'description' => 'V1 quota operations stub']);
        $before = static::$db->rows('SELECT * FROM radacct ORDER BY radacctid');
        $run = $this->cli('quota.php', 'enforce', '--json');
        $this->assertSame(0, $run['exit'], $run['raw']);
        $out = json_decode(trim($run['raw']), true);
        $this->assertSame('done', $out['enforce'] ?? null, $run['raw']);
        $this->assertSame([[$id, 'CONFIRMED', true]], array_map(static fn($e) => [$e['radacctid'], $e['state'], $e['confirmed']], $out['actions'][0]['ended']));
        $this->assertCount(1, $stub->received(), 'one Disconnect');
        $this->assertSame($before, static::$db->rows('SELECT * FROM radacct ORDER BY radacctid'), 'accounting is only read');
        $audit = static::$db->rows("SELECT actor_type, target_customer_id, after_json FROM tbl_reseller_audit_events WHERE event_type = 'quota.enforced'");
        $this->assertCount(1, $audit);
        $this->assertSame($c, (int) $audit[0]['target_customer_id']);
        $this->assertSame('1100000', json_decode((string) $audit[0]['after_json'], true)['usage'] ?? null);
        $this->assertNotFalse(static::$db->value("SELECT value FROM tbl_appconfig WHERE setting = 'nuxr_quota_enforcer_heartbeat'"), 'heartbeat written');

        // enforcer alive, the session still open in accounting (Stop not yet seen): WARNING; once stopped: OK
        $this->assertSame('WARNING', $this->healthQuota()['status'] ?? null);
        static::$db->exec("UPDATE radacct SET acctstoptime = UTC_TIMESTAMP() WHERE radacctid = {$id}");
        $this->assertSame('OK', $this->healthQuota()['status'] ?? null);
        // an enforcer silent for more than 3 minutes while a quota subscriber is online: CRITICAL again
        static::$db->exec("UPDATE radacct SET acctstoptime = NULL WHERE radacctid = {$id}");
        static::$db->exec("UPDATE radcheck SET value = '9000000' WHERE username = " . static::$db->db()->pdo->quote($user) . " AND attribute = 'Max-Data'");
        static::$db->exec("UPDATE tbl_appconfig SET value = DATE_FORMAT(UTC_TIMESTAMP() - INTERVAL 10 MINUTE, '%Y-%m-%d %H:%i:%s') WHERE setting = 'nuxr_quota_enforcer_heartbeat'");
        $this->assertSame('CRITICAL', $this->healthQuota()['status'] ?? null);
    }

    /**
     * Existing data: a Limited Radius plan saved before V1 may hold a zero / unusable quota. Such a plan can no longer be
     * saved, and a sale of it fails closed (never unlimited, never a 0 quota). The release preflight lists them (WARN,
     * read-only) so the operator edits or disables them before launch; valid plans (any of the four types) PASS.
     */
    public function testPreflightListsLimitedPlansWithAnInvalidQuota(): void
    {
        $plan = static fn(string $name, array $q): int => static::$db->insertRow('tbl_plans', $q + ['name_plan' => $name, 'price' => '5', 'type' => 'Hotspot', 'typebp' => 'Limited',
            'limit_type' => 'Data_Limit', 'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 1, 'data_unit' => 'GB', 'validity' => 30, 'validity_unit' => 'Days',
            'shared_users' => 1, 'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius']);
        $ids = [$plan('v1pf_ok_data', []), $plan('v1pf_ok_time', ['limit_type' => 'Time_Limit', 'time_limit' => 5, 'data_limit' => 0]),
            $plan('v1pf_ok_both', ['limit_type' => 'Both_Limit', 'time_limit' => 5, 'data_limit' => 2, 'data_unit' => 'MB'])];
        $run = function (): array {
            $r = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', self::$app->dir . '/system/reseller/preflight.php', '--json']);
            $j = json_decode(substr((string) $r['raw'], (int) strpos((string) $r['raw'], '{')), true);
            $this->assertIsArray($j, (string) $r['raw']);
            return array_column($j['results'], null, 'check')['quota.plans'] ?? [];
        };
        $count = static function (array $c): array {
            preg_match('/^(\d+) enabled .*?, (\d+) with an invalid quota/', (string) ($c['detail'] ?? ''), $m);
            self::assertCount(3, $m, json_encode($c));
            return [(int) $m[1], (int) $m[2]];
        };
        try {
            $ok = $run();
            $this->assertSame('PASS', $ok['status'] ?? null, json_encode($ok));
            [$n0, $b0] = $count($ok);
            $this->assertSame(0, $b0);
            $ids[] = $plan('v1pf_bad_zero', ['data_limit' => 0]);
            $ids[] = $plan('v1pf_bad_time', ['limit_type' => 'Time_Limit', 'time_limit' => 0, 'data_limit' => 0]);   // (the unit columns are ENUMs: a bad unit cannot be stored)
            $ids[] = $plan('v1pf_off_bad', ['data_limit' => 0, 'enabled' => 0]);   // disabled: not sold, not counted
            $bad = $run();
            $this->assertSame('WARN', $bad['status'] ?? null, json_encode($bad));
            $this->assertSame([$n0 + 2, 2], $count($bad), 'two enabled invalid plans; the disabled one is not counted');
        } finally {
            static::$db->exec('DELETE FROM tbl_plans WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        }
    }

    /** a health check by name */
    private function healthCheck(string $name): array
    {
        $r = $this->cli('health.php', '--json');
        $j = json_decode(trim($r['raw']), true);
        $this->assertIsArray($j, $r['raw']);
        return array_column($j['checks'], null, 'check')[$name] ?? [];
    }

    /**
     * N2: quota attribution no longer uses the NAS clock, but accounting times (display, freshness, the time-rule fallback) do.
     * A NAS whose interim stamps lie more than 10 s in the future (clock ahead; the B-L08 allowance, lab NET-4) is reported;
     * counts only, never a name.
     */
    public function testHealthReportsANasWhoseClockIsAhead(): void
    {
        $this->assertSame('OK', $this->healthCheck('nas_clock')['status'] ?? null, 'no open session: nothing to report');
        $user = 'v1nc' . bin2hex(random_bytes(3));
        $id = static::$db->insertRow('radacct', ['acctsessionid' => 'v1nc' . bin2hex(random_bytes(3)), 'acctuniqueid' => bin2hex(random_bytes(16)), 'username' => $user,
            'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 30), 'acctupdatetime' => gmdate('Y-m-d H:i:s', time() + 600),
            'framedipaddress' => '10.43.0.9']);
        try {
            $c = $this->healthCheck('nas_clock');
            $this->assertSame('WARNING', $c['status'] ?? null, json_encode($c));
            $this->assertStringContainsString('1 open session', (string) $c['detail']);
            $this->assertStringNotContainsString($user, json_encode($c), 'counts only');
            // lab NET-4: 30 s ahead is beyond the 10 s session-end allowance (it was silent below 120 s)
            static::$db->exec("UPDATE radacct SET acctupdatetime = UTC_TIMESTAMP() + INTERVAL 30 SECOND WHERE radacctid = {$id}");
            $this->assertSame('WARNING', $this->healthCheck('nas_clock')['status'] ?? null, 'a NAS 30 s ahead is reported');
            static::$db->exec("UPDATE radacct SET acctupdatetime = UTC_TIMESTAMP() + INTERVAL 8 SECOND WHERE radacctid = {$id}");
            $this->assertSame('WARNING', $this->healthCheck('nas_clock')['status'] ?? null, 'early warning beyond 5 s, before the 10 s hard limit');
            static::$db->exec("UPDATE radacct SET acctupdatetime = UTC_TIMESTAMP() + INTERVAL 2 SECOND WHERE radacctid = {$id}");
            $this->assertSame('OK', $this->healthCheck('nas_clock')['status'] ?? null, 'a 2 s stamp is normal');
            static::$db->exec("UPDATE radacct SET acctupdatetime = UTC_TIMESTAMP() WHERE radacctid = {$id}");
            $this->assertSame('OK', $this->healthCheck('nas_clock')['status'] ?? null, 'a normal stamp is fine');
        } finally {
            static::$db->exec("DELETE FROM radacct WHERE radacctid = {$id}");
        }
    }

    public function testUsageAndCliOnly(): void
    {
        $this->assertSame(5, $this->cli('quota.php')['exit']);
        $this->assertSame(5, $this->cli('quota.php', 'enforce', '--bogus')['exit']);
        $this->assertSame(5, $this->cli('quota.php', 'enforce', '--every=1')['exit']);
        self::$app->start();
        try {
            $this->assertSame(404, self::$app->client()->raw('GET', 'system/reseller/quota.php')->status);
        } finally {
            self::$app->stop();
        }
    }
}
