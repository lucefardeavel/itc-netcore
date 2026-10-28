<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RadiusHttpTestCase;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP9 TAMPER / LPDB + release preflight (WP10 inputs), on a disposable instance:
 *  - preflight FAILs for an administrative runtime account (root) and for a weakened admission CHECK;
 *  - grants.php produces least-privilege GRANTs; with them the runtime account cannot UPDATE / DELETE the ledger,
 *    the audit table or the activation timeline (the database refuses: error 1142), while the application works;
 *  - with the least-privilege account and production settings the preflight PASSes.
 */
final class W9PreflightGrantsTest extends RadiusHttpTestCase
{
    private static string $appUser = '';
    private static string $appPass = '';

    protected static function appOptions(): array
    {
        return ['legacy_devices' => false];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $fx = new FixtureBuilder(dirname(__DIR__, 2));
        $fx->loadSqlFile(static::$db->pdo, dirname(__DIR__, 2) . '/install/freeradius/nuxr_admission.sql');
        $fx->loadSqlFile(static::$db->pdo, dirname(__DIR__, 2) . '/install/freeradius/nuxr_hs_admission.sql');
        static::$db->strictSession();
        self::$appUser = 'w9app' . bin2hex(random_bytes(3));
        self::$appPass = bin2hex(random_bytes(16));
    }

    public static function tearDownAfterClass(): void
    {
        try {
            FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword())->exec("DROP USER IF EXISTS '" . self::$appUser . "'@'%'");
        } finally {
            parent::tearDownAfterClass();
        }
    }

    private function preflight(): array
    {
        $r = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', static::$app->dir . '/system/reseller/preflight.php', '--json']);
        $j = json_decode(substr($r['raw'], (int) strpos($r['raw'], '{')), true);
        $this->assertIsArray($j, $r['raw']);
        return ['exit' => $r['exit'], 'by' => array_column($j['results'], null, 'check'), 'raw' => $r['raw']];
    }

    private function radiusConfig(string $user, string $pass, bool $production): void
    {
        $db = static::$db;
        static::$app->writeConfig(static::$app->apiSecret(), '$db_user = ' . var_export($user, true) . ";\n\$db_pass = " . var_export($pass, true) . ";\n"
            . '$radius_host = ' . var_export($db->host, true) . ";\n\$radius_user = " . var_export($user, true) . ";\n\$radius_pass = " . var_export($pass, true) . ";\n\$radius_name = " . var_export($db->name, true) . ";\n"
            . ($production ? "ini_set('display_errors', '0');\n" : ''));
    }

    public function testPreflightAndLeastPrivilegeAccount(): void
    {
        // 1. administrative (root) runtime account: grants FAIL; everything else of the instance is checked too
        $this->radiusConfig('root', TestDatabase::rootPassword(), true);
        $p = $this->preflight();
        $this->assertSame(4, $p['exit'], $p['raw']);
        $this->assertSame('FAIL', $p['by']['grants']['status']);
        foreach (['keys.app_secret', 'isolation', 'time.sql_session_zone', 'schema', 'radius_checks', 'identity.collisions', 'profile', 'mac'] as $c) {
            $this->assertSame('PASS', $p['by'][$c]['status'] ?? null, $c . ': ' . json_encode($p['by'][$c] ?? null));
        }

        // 2. a weakened admission CHECK (same name) is a radius_checks FAIL (A2-R hardening L4, exact expression)
        static::$db->exec('ALTER TABLE nuxr_admission DROP CONSTRAINT ck_nuxr_admission_token');
        static::$db->exec("ALTER TABLE nuxr_admission ADD CONSTRAINT ck_nuxr_admission_token CHECK (username <> '')");
        try {
            $this->assertSame('FAIL', $this->preflight()['by']['radius_checks']['status']);
        } finally {
            static::$db->exec('ALTER TABLE nuxr_admission DROP CONSTRAINT ck_nuxr_admission_token');
            static::$db->exec("ALTER TABLE nuxr_admission ADD CONSTRAINT ck_nuxr_admission_token CHECK (token REGEXP '^[0-9a-f]{32}$' AND username <> '' AND session_user <> '')");
        }

        // 3. least-privilege runtime account from grants.php
        $g = RunnerProcess::exec([PHP_BINARY, static::$app->dir . '/system/reseller/grants.php', "--app='" . self::$appUser . "'@'%'"]);
        $this->assertSame(0, $g['exit'], $g['raw']);
        $this->assertStringContainsString('GRANT SELECT, INSERT ON `' . static::$db->name . '`.`tbl_manager_ledger`', $g['raw']);
        $this->assertStringNotContainsString('IDENTIFIED', $g['raw'], 'no password in the generated grants');
        $admin = FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword());
        $admin->exec("CREATE USER '" . self::$appUser . "'@'%' IDENTIFIED BY " . $admin->quote(self::$appPass));
        foreach (array_filter(explode("\n", $g['raw']), static fn(string $l): bool => str_starts_with($l, 'GRANT ')) as $stmt) {
            $admin->exec(rtrim($stmt, ';'));
        }

        // 4. TAMPER: the database itself refuses rewriting append-only history for the runtime account
        $lpdo = new \PDO('mysql:host=' . static::$db->host . ';dbname=' . static::$db->name . ';charset=utf8mb4', self::$appUser, self::$appPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach (['UPDATE tbl_manager_ledger SET amount = amount WHERE id = 0', 'DELETE FROM tbl_manager_ledger WHERE id = 0',
                  'UPDATE tbl_reseller_audit_events SET reason = reason WHERE id = 0', 'DELETE FROM tbl_reseller_audit_events WHERE id = 0',
                  'DELETE FROM tbl_reseller_activation_events WHERE id = 0', 'UPDATE radacct SET acctstoptime = NULL WHERE radacctid = 0',
                  'DROP TABLE tbl_manager_ledger', 'ALTER TABLE tbl_manager_wallets DROP CONSTRAINT ck_wallet_floor'] as $sql) {
            try {
                $lpdo->exec($sql);
                $this->fail('the runtime account was allowed: ' . $sql);
            } catch (\PDOException $e) {
                $this->assertContains((int) ($e->errorInfo[1] ?? 0), [1142, 1143, 1044, 1045], $sql . ': ' . $e->getMessage());
            }
        }
        $this->assertSame(0, (int) $lpdo->query('SELECT COUNT(*) FROM tbl_manager_ledger WHERE id = 0')->fetchColumn(), 'positive: it can read');

        // 5. the application runs with it and the preflight passes. PF-0 (owner decision 2026-10-28): the release
        // preflight requires a verified NAS, so this production-settings step has one (no NAS is NOT_CONFIGURED: FAIL)
        $this->radiusConfig(self::$appUser, self::$appPass, true);
        static::$db->insertRow('nas', ['nasname' => '10.99.9.1', 'shortname' => 'w9rel', 'type' => 'mikrotik_snmp', 'ports' => 3799,
            'secret' => 'w9-release-nas', 'description' => 'W9 release NAS']);
        $r = $this->staff('A')->get('dashboard');
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertSame(200, $this->staff('ad')->get('customers/list')->status);
        $p = $this->preflight();
        $this->assertSame('PASS', $p['by']['grants']['status'], json_encode($p['by']['grants']));
        $this->assertSame(0, $p['exit'], $p['raw']);
        static::$db->exec("DELETE FROM nas WHERE description = 'W9 release NAS'");
    }
}
