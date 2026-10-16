<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\SchemaReadiness;
use Reseller\Migration\Outcome;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP9-D / WP1-CHECK (N-08, ledger-binding): the migration verifier and the runtime schema readiness
 * must detect a CHECK constraint whose NAME is unchanged but whose EXPRESSION was weakened, a MySQL
 * CHECK switched to NOT ENFORCED, and a MariaDB connection that runs with check_constraint_checks = 0.
 * Positive control: a freshly migrated database verifies OK and is ready on both engines.
 */
final class W9CheckConstraintIntegrityTest extends IntegrationTestCase
{
    private ?\PDO $svc = null;

    protected function tearDown(): void
    {
        if ($this->svc !== null) {
            \ORM::reset_db();
            SchemaReadiness::reset();
            $this->svc = null;
        }
        parent::tearDown();
    }

    private function migrated(): TestDatabase
    {
        $db = $this->newDatabase(FixtureBuilder::V2);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        return $db;
    }

    /** Runtime readiness on a dedicated application-style connection to $db. */
    private function readiness(TestDatabase $db, ?string $sessionSql = null): array
    {
        require_once dirname(__DIR__, 3) . '/system/orm.php';
        if (!defined('NUXR_RUNTIME')) {
            define('NUXR_RUNTIME', true);
        }
        $this->svc = FixtureBuilder::connect($db->host, TestDatabase::rootPassword(), $db->name);
        if ($sessionSql !== null) {
            $this->svc->exec($sessionSql);
        }
        \ORM::reset_db();
        \ORM::set_db($this->svc);
        SchemaReadiness::reset();
        return SchemaReadiness::status();
    }

    private function replaceCheck(TestDatabase $db, string $table, string $name, string $expr): void
    {
        $db->exec("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        $db->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expr})");
    }

    public function testFreshlyMigratedDatabaseVerifiesAndIsReady(): void
    {
        $db = $this->migrated();
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'verify'));
        $st = $this->readiness($db);
        $this->assertTrue($st['ready'], implode("\n", $st['problems']));
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function weakenedChecks(): array
    {
        return [
            'wallet floor allows overdraft' => ['tbl_manager_wallets', 'ck_wallet_floor', '`balance` + `debt_limit` >= -1000000'],
            'ledger amount may be negative' => ['tbl_manager_ledger', 'ck_ledger_amount_pos', '`amount` > -1000000'],
            'tree depth rule removed' => ['tbl_manager_tree', 'ck_tree_depth_self', '1 = 1'],
            'base cost may be negative' => ['tbl_reseller_plan_base_costs', 'ck_basecost_nonneg', '`base_cost` >= -1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('weakenedChecks')]
    public function testSameNameWeakenedCheckIsDriftAndNotReady(string $table, string $name, string $expr): void
    {
        $db = $this->migrated();
        $this->replaceCheck($db, $table, $name, $expr);
        $v = $this->run_($db, 'verify');
        $this->assertOutcome(Outcome::DRIFT, $v, 'a same-name weakened CHECK must be reported as drift');
        $this->assertStringContainsString($name, implode("\n", $v['report']['drift'] ?? []));
        $st = $this->readiness($db);
        $this->assertFalse($st['ready'], 'runtime readiness must refuse a weakened CHECK');
        $this->assertStringContainsString($name, implode("\n", $st['problems']));
    }

    public function testRestoredExactCheckVerifiesAgain(): void
    {
        $db = $this->migrated();
        $this->replaceCheck($db, 'tbl_manager_wallets', 'ck_wallet_floor', '`balance` + `debt_limit` >= -1000000');
        $this->replaceCheck($db, 'tbl_manager_wallets', 'ck_wallet_floor', '`balance` + `debt_limit` >= 0');
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'verify'));
        $st = $this->readiness($db);
        $this->assertTrue($st['ready'], implode("\n", $st['problems']));
    }

    public function testMissingCheckIsNotReady(): void
    {
        $db = $this->migrated();
        $db->exec('ALTER TABLE `tbl_manager_ledger` DROP CONSTRAINT `ck_ledger_math`');
        $this->assertOutcome(Outcome::DRIFT, $this->run_($db, 'verify'));
        $st = $this->readiness($db);
        $this->assertFalse($st['ready']);
        $this->assertStringContainsString('ck_ledger_math', implode("\n", $st['problems']));
    }

    public function testMysqlNotEnforcedCheckIsDriftAndNotReady(): void
    {
        $db = $this->migrated();
        if ($db->engine() !== 'mysql') {
            $this->assertSame('mariadb', $db->engine());
            return; // MariaDB has no per-constraint NOT ENFORCED (covered by the session test below)
        }
        $db->exec('ALTER TABLE `tbl_manager_wallets` ALTER CHECK `ck_wallet_floor` NOT ENFORCED');
        $v = $this->run_($db, 'verify');
        $this->assertOutcome(Outcome::DRIFT, $v);
        $this->assertStringContainsString('ck_wallet_floor', implode("\n", $v['report']['drift'] ?? []));
        $st = $this->readiness($db);
        $this->assertFalse($st['ready']);
        $this->assertStringContainsString('ck_wallet_floor', implode("\n", $st['problems']));
    }

    public function testMariadbSessionWithoutCheckEnforcementIsNotReady(): void
    {
        $db = $this->migrated();
        if ($db->engine() !== 'mariadb') {
            $this->assertSame('mysql', $db->engine());
            return; // MySQL has no session switch for CHECK enforcement
        }
        $st = $this->readiness($db, 'SET SESSION check_constraint_checks = 0');
        $this->assertFalse($st['ready'], 'a connection that does not enforce CHECK constraints must not be ready');
        $this->assertStringContainsString('check_constraint_checks', implode("\n", $st['problems']));
    }

    /** Evidence only: the CHECK clause text each engine reports for every project constraint. */
    public function testRecordEngineCheckClauses(): void
    {
        $db = $this->migrated();
        $rows = $db->rows(
            "SELECT tc.TABLE_NAME t, cc.CONSTRAINT_NAME n, cc.CHECK_CLAUSE c
               FROM information_schema.CHECK_CONSTRAINTS cc
               JOIN information_schema.TABLE_CONSTRAINTS tc
                 ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME AND tc.CONSTRAINT_TYPE = 'CHECK'
              WHERE cc.CONSTRAINT_SCHEMA = DATABASE() ORDER BY 1, 2"
        );
        $this->assertNotEmpty($rows);
        $out = getenv('WP9_EVIDENCE');
        if (is_string($out) && $out !== '') {
            file_put_contents(dirname($out) . '/check-clauses-' . $db->engine() . '.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }
}
