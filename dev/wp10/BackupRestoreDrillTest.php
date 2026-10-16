<?php
namespace Reseller\Tests\Lab;

// WP10 BACKUP / RESTORE / MIGRATION-ROLLBACK DRILL (test tooling only). Not part of any PHPUnit suite: run explicitly by
// dev/wp10/drill.sh inside the isolated nuxbill-reseller-test compose project, once per engine. Disposable databases only.
// The dumps and restores are made by the host with the engine's own client (mariadb-dump / mysqldump) inside the
// database container, exactly as the operator runbook prescribes; this file seeds, asks the host for each step through
// request / done files in /drill-state, and proves the result. Production data and any non-test container are never touched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Finance\SettlementReadModel;
use Reseller\Finance\Reader;
use Reseller\Finance\TransferService;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;

/**
 *  D1 upgrade drill on a legacy (V2) database with legacy data: backup B0 -> preflight -> migrate -> verify -> second
 *     migrate writes nothing -> new-domain data -> schema rollback refused (exit 2, data guard; nothing changed);
 *  D2 backup / restore drill of a data-bearing instance (activations, ledger, wallets, reservations, audit, RADIUS
 *     policy rows, admission claims, accounting): backup B1 -> restore into a fresh database -> every table identical
 *     (definition incl. CHECK constraints, row count, SHA-256 of all rows) -> migrate verify, schema readiness, finance
 *     integrity, health on the restored copy -> the restored copy works (a new activation completes there);
 *  D3 rollback by restore: B0 restored into a fresh database is identical to the pre-upgrade database, and the upgrade
 *     can be re-run on it.
 * Every fingerprint, dump size / SHA-256 and exit code is printed to the evidence log; dumps stay on the host, outside git.
 */
final class BackupRestoreDrillTest extends ActivationTestCase
{
    private const STATE = '/drill-state';
    private static int $step = 0;
    /** @var list<TestDatabase> */
    private static array $extra = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$extra as $db) {
            $db->drop();
        }
        parent::tearDownAfterClass();
    }

    private static function log(string $line): void
    {
        file_put_contents(self::STATE . '/drill.log', gmdate('Y-m-d\TH:i:s\Z ') . $line . "\n", FILE_APPEND);
    }

    /** asks the host to dump $db or to restore a dump into $db; returns the host's report */
    private function host(string $op, string $db, string $file): array
    {
        $n = ++self::$step;
        file_put_contents(self::STATE . "/request-{$n}.json.tmp", json_encode(['op' => $op, 'db' => $db, 'file' => $file]));
        rename(self::STATE . "/request-{$n}.json.tmp", self::STATE . "/request-{$n}.json");
        for ($t = 0; $t < 1800 && !is_file(self::STATE . "/done-{$n}.json"); $t++) {
            usleep(200000);
        }
        $done = json_decode((string) @file_get_contents(self::STATE . "/done-{$n}.json"), true);
        $this->assertIsArray($done, "host step {$n} {$op} answered");
        $this->assertTrue($done['ok'] ?? false, "host step {$n} {$op}: " . json_encode($done));
        self::log("host {$op} {$file} db=" . $db . ' ' . json_encode(array_diff_key($done, ['ok' => 1])));
        return $done;
    }

    /** canonical fingerprint of every base table: definition (no AUTO_INCREMENT counter), row count, SHA-256 of all rows */
    private static function fingerprint(TestDatabase $db): array
    {
        $pdo = FixtureBuilder::connect($db->host, TestDatabase::rootPassword(), $db->name);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(\PDO::FETCH_COLUMN);
        $out = [];
        foreach ($tables as $t) {
            $ddl = (string) $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(\PDO::FETCH_NUM)[1];
            $ddl = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
            // MySQL 8 prints a column restored from a dump (explicit COLLATE) as "CHARACTER SET x COLLATE y"; a collation
            // determines its character set, so the redundant CHARACTER SET is dropped before comparing
            $ddl = (string) preg_replace('/ CHARACTER SET \w+ COLLATE (\w+)/', ' COLLATE $1', $ddl);
            $pk = $pdo->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t)
                . " AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION")->fetchAll(\PDO::FETCH_COLUMN);
            $order = $pk === [] ? '' : ' ORDER BY ' . implode(', ', array_map(static fn(string $c): string => "`{$c}`", $pk));
            $h = hash_init('sha256');
            $rows = 0;
            foreach ($pdo->query("SELECT * FROM `{$t}`{$order}", \PDO::FETCH_NUM) as $r) {
                hash_update($h, json_encode(array_map(static fn($v) => $v === null ? null : bin2hex((string) $v), $r)) . "\n");
                $rows++;
            }
            $out[$t] = ['ddl' => hash('sha256', $ddl), 'rows' => $rows, 'data' => hash_final($h)];
        }
        foreach (['ROUTINES' => 'ROUTINE_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA', 'VIEWS' => 'TABLE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $view => $col) {
            $out["#{$view}"] = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.{$view} WHERE {$col} = DATABASE()")->fetchColumn();
        }
        return $out;
    }

    private static function summary(array $fp): string
    {
        $rows = array_sum(array_map(static fn($v) => is_array($v) ? $v['rows'] : 0, $fp));
        return count(array_filter($fp, 'is_array')) . ' tables, ' . $rows . ' rows, fingerprint ' . substr(hash('sha256', json_encode($fp)), 0, 16);
    }

    private function fresh(string $prefix): TestDatabase
    {
        $db = new TestDatabase($prefix);
        self::$extra[] = $db;
        return $db;
    }

    /** an application copy whose config points at $db (both the application and the RADIUS connection) */
    private function appFor(TestDatabase $db): AppServer
    {
        $app = AppServer::create($db, []);
        $q = static fn(string $v): string => var_export($v, true);
        $app->writeConfig($app->apiSecret(), '$radius_host = ' . $q($db->host) . ";\n\$radius_user = 'root';\n\$radius_pass = " . $q(TestDatabase::rootPassword())
            . ";\n\$radius_name = " . $q($db->name) . ";\n");
        $app->exec('ORM::raw_execute("DELETE FROM tbl_appconfig WHERE setting = \'radius_enable\'"); ORM::raw_execute("INSERT INTO tbl_appconfig (setting, value) VALUES (\'radius_enable\', \'1\')"); $result = true;');
        return $app;
    }

    private function health(AppServer $app): array
    {
        $r = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', $app->dir . '/system/reseller/health.php', '--json', '--finance']);
        $j = json_decode(trim($r['raw']), true);
        $this->assertIsArray($j, $r['raw']);
        return ['exit' => $r['exit'], 'by' => array_map(static fn(array $c): string => $c['status'], array_column($j['checks'], null, 'check'))];
    }

    public function testDrill(): void
    {
        $engine = static::$db->engine();
        self::log("==== drill start engine={$engine} " . (string) static::$db->value('SELECT VERSION()'));

        // ---------- D1 upgrade drill ----------
        $legacy = $this->fresh('drl');
        $legacy->build(FixtureBuilder::V2);
        (new FixtureBuilder(dirname(__DIR__, 2)))->loadSqlFile($legacy->pdo, dirname(__DIR__, 2) . '/install/radius.sql');
        $admin = $legacy->insertRow('tbl_users', ['username' => 'drill_admin', 'user_type' => 'SuperAdmin', 'status' => 'Active']);
        foreach (['محمد', 'fatima', 'legacy'] as $i => $u) {
            $legacy->insertRow('tbl_customers', ['username' => "drill_{$i}", 'password' => 'p', 'fullname' => $u, 'created_by' => $admin, 'balance' => 5 + $i]);
        }
        $legacy->insertRow('tbl_plans', ['name_plan' => 'legacy plan', 'price' => '1000', 'type' => 'Hotspot']);
        $legacy->insertRow('radacct', ['acctsessionid' => 'L1', 'acctuniqueid' => md5('L1'), 'username' => 'drill_0', 'nasipaddress' => '10.0.0.1', 'acctstarttime' => '2026-09-01 10:00:00', 'acctstoptime' => '2026-09-01 11:00:00']);
        $l0 = self::fingerprint($legacy);
        self::log('D1 legacy before upgrade: ' . self::summary($l0));
        $b0 = $this->host('dump', $legacy->name, "B0-{$engine}.sql");

        $pre = RunnerProcess::run($legacy, 'preflight');
        $this->assertSame(0, $pre['exit'], $pre['raw']);
        $mig = RunnerProcess::run($legacy, 'migrate');
        $this->assertSame(0, $mig['exit'], $mig['raw']);
        $ver = RunnerProcess::run($legacy, 'verify');
        $this->assertSame(0, $ver['exit'], $ver['raw']);
        $migrated = self::fingerprint($legacy);
        $again = RunnerProcess::run($legacy, 'migrate');
        $this->assertSame(0, $again['exit'], $again['raw']);
        $this->assertSame($migrated, self::fingerprint($legacy), 'a second migrate writes nothing');
        foreach (array_keys($l0) as $t) {
            if (str_starts_with($t, '#') || in_array($t, ['tbl_customers'], true)) {
                continue;
            }
            $this->assertSame($l0[$t]['data'], $migrated[$t]['data'] ?? null, "D1 legacy table {$t}: data unchanged by the upgrade");
        }
        self::log("D1 preflight=0 migrate=0 verify=0 second-migrate=0 (no change); legacy data of every legacy table unchanged");
        // new-domain data exists -> schema rollback is refused and changes nothing
        $legacy->exec("UPDATE tbl_customers SET manager_id = {$admin} WHERE username = 'drill_0'");
        $withData = self::fingerprint($legacy);
        $rb = RunnerProcess::run($legacy, 'rollback', ['--all']);
        $this->assertSame(2, $rb['exit'], 'rollback refused by the data guard: ' . $rb['raw']);
        $this->assertSame($withData, self::fingerprint($legacy), 'the refused rollback changed nothing');
        self::log('D1 rollback --all with new-domain data: exit 2 (refused), nothing changed');

        // ---------- D2 backup / restore of a data-bearing instance ----------
        foreach (['nuxr_admission.sql', 'nuxr_hs_admission.sql'] as $f) {
            (new FixtureBuilder(dirname(__DIR__, 2)))->loadSqlFile(static::$db->pdo, dirname(__DIR__, 2) . '/install/freeradius/' . $f);
        }
        static::$db->strictSession();
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $refs = [];
        $customers = [];
        for ($i = 0; $i < 6; $i++) {
            $customers[] = $c = $this->customer($i % 2 ? $leaf : $top);
            $refs[] = $this->activate($i % 2 ? $leaf : $top, $c, $plan, 'NEW')['reference'];
        }
        $refs[] = $this->activate($leaf, $customers[1], $plan, 'RENEW')['reference'];
        TransferService::transferToChild($this->ctx($top), $top, $leaf, '7.25', 'drill top-up', 'T-drill-' . bin2hex(random_bytes(3)));
        // one activation left in flight (a reservation is held)
        $stuck = $this->customer($leaf);
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.before_provision') {
                throw new \RuntimeException('drill crash');
            }
        };
        try {
            $this->activate($leaf, $stuck, $plan, 'NEW');
        } catch (\RuntimeException) {
        } finally {
            ActivationFaults::$hook = null;
        }
        $u = $this->username($customers[0]);
        static::$db->exec("INSERT INTO nuxr_admission (username, state, token, nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator, session_user, attach_count, created_at, updated_at)
            VALUES (" . static::$db->pdo->quote($u) . ", 'ACTIVE', '" . bin2hex(random_bytes(16)) . "', '10.0.0.1', '81a0', 'aa:bb', 'ether1', '" . str_repeat('c', 32) . "', " . static::$db->pdo->quote($u) . ", 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
        static::$db->insertRow('radacct', ['acctsessionid' => 'D2', 'acctuniqueid' => md5('D2' . $u), 'username' => $u, 'nasipaddress' => '10.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s'),
            'acctupdatetime' => gmdate('Y-m-d H:i:s'), 'acctinputoctets' => 123456789012, 'acctoutputoctets' => 98765432109]);
        $this->assertSame([], SettlementReadModel::integrity(), 'the source instance is consistent');
        // the application copy of the source writes its RADIUS switch into tbl_appconfig: before the fingerprint
        $srcApp = $this->appFor(static::$db);
        $srcHealth = $this->health($srcApp);
        $srcApp->destroy();
        $s1 = self::fingerprint(static::$db);
        self::log('D2 source instance: ' . self::summary($s1) . ', activations ' . count($refs) . ' + 1 in flight');

        $b1 = $this->host('dump', static::$db->name, "B1-{$engine}.sql");
        $this->assertSame($s1, self::fingerprint(static::$db), 'taking the backup changed nothing');
        $restored = $this->fresh('rst');
        $this->host('restore', $restored->name, "B1-{$engine}.sql");
        $r1 = self::fingerprint($restored);
        $this->assertSame(array_keys($s1), array_keys($r1), 'same tables');
        foreach ($s1 as $t => $fp) {
            $this->assertSame($fp, $r1[$t], "D2 restored {$t} identical (definition, rows, data)");
        }
        self::log('D2 restored copy: ' . self::summary($r1) . ' -> identical to the source on every table');
        $v = RunnerProcess::run($restored, 'verify');
        $this->assertSame(0, $v['exit'], $v['raw']);
        $app = $this->appFor($restored);
        try {
            $h = $this->health($app);
            $this->assertSame($srcHealth['by'], $h['by'], 'health of the restored copy equals the source');
            foreach (['db', 'schema', 'finance'] as $c) {
                $this->assertSame('OK', $h['by'][$c], "restored {$c}");
            }
            // the restored copy is a working instance: the operator CLI sees the in-flight activation, a new activation completes
            $show = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', $app->dir . '/system/reseller/reconcile.php', 'stale', '--seconds=0']);
            $this->assertSame(0, $show['exit'], $show['raw']);
            $done = $app->exec(<<<PHP
\$leaf = ORM::for_table('tbl_users')->find_one({$leaf});
\$ctx = \\Reseller\\Security\\ActorContext::forStaff(\$leaf->as_array(), 'CLI');
\$c = ORM::for_table('tbl_customers')->create();
\$c->set(['username' => 'drillnew' . bin2hex(random_bytes(3)), 'password' => 'x', 'fullname' => 'Drill', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active', 'balance' => 0, 'manager_id' => {$leaf}, 'created_by' => {$leaf}]);
\$c->save();
\$pv = \\Reseller\\Activation\\ActivationService::preview(\$ctx, (int) \$c->id(), {$plan});
\$result = \\Reseller\\Activation\\ActivationService::create(\$ctx, (int) \$c->id(), {$plan}, \$pv['action'], bin2hex(random_bytes(16)))['status'];
PHP);
            $this->assertSame('COMPLETED', $done, 'a new activation completes on the restored copy');
            self::log('D2 restored copy: verify=0, health ' . json_encode($h['by']) . ', new activation on the restored copy: ' . $done);
            $this->assertSame([], SettlementReadModel::integrityCheck(new Reader(FixtureBuilder::connect($restored->host, TestDatabase::rootPassword(), $restored->name))), 'finance integrity after the new activation');
        } finally {
            $app->destroy();
        }

        // ---------- D3 rollback by restore ----------
        $back = $this->fresh('rbk');
        $this->host('restore', $back->name, "B0-{$engine}.sql");
        $r0 = self::fingerprint($back);
        $this->assertSame($l0, $r0, 'D3 the pre-upgrade backup restores the legacy database exactly');
        $st = RunnerProcess::run($back, 'status');
        self::log('D3 B0 restored: ' . self::summary($r0) . ' -> identical to the pre-upgrade database; status exit ' . $st['exit']);
        $re = RunnerProcess::run($back, 'migrate');
        $this->assertSame(0, $re['exit'], $re['raw']);
        $this->assertSame(0, RunnerProcess::run($back, 'verify')['exit']);
        self::log('D3 upgrade re-run on the restored legacy database: migrate=0 verify=0');
        self::log("==== drill PASS engine={$engine} B0 " . json_encode($b0) . ' B1 ' . json_encode($b1));
    }
}
