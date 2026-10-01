<?php
namespace Reseller\Tests\Integration;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Migration\Outcome;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;

/** T1, T8, T9 and owner additions 1–2: preflight recognition and fail-closed behaviour. */
final class PreflightTest extends IntegrationTestCase
{
    #[DataProvider('variants')]
    public function testRecognisesVerifiedVariantWithZeroMutation(string $variant): void
    {
        $db = $this->newDatabase($variant);
        $before = $db->snapshot();
        $r = $this->run_($db, 'preflight');
        $this->assertOutcome(Outcome::OK, $r);
        $this->assertSame($variant, $r['report']['variant']);
        $this->assertSame($before, $db->snapshot(), 'preflight must not mutate anything');
        $this->assertNotContains('tbl_project_migrations', $db->tables(), 'preflight must not create the registry');
    }

    public function testExtraUnrelatedTablePassesAndIsReported(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V2);
        // unrelated deployment extensions: a plugin table and the FreeRADIUS schema in the same DB
        $db->exec('CREATE TABLE `tbl_plugin_example` (`id` INT NOT NULL PRIMARY KEY, `note` VARCHAR(20)) ENGINE=InnoDB');
        (new FixtureBuilder(dirname(__DIR__, 2)))->loadSqlFile($db->pdo, dirname(__DIR__, 2) . '/install/radius.sql');
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $before = $db->snapshot();
        $r = $this->run_($db, 'preflight');
        $this->assertOutcome(Outcome::OK, $r);
        $this->assertSame(FixtureBuilder::V2, $r['report']['variant']);
        $this->assertContains('tbl_plugin_example', $r['report']['extra_tables']);
        $this->assertContains('radacct', $r['report']['extra_tables']);
        $this->assertSame($before, $db->snapshot());
        // and migration proceeds with them present
        $m = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::OK, $m);
        $this->assertSame($before['tbl_plugin_example'], $db->snapshot()['tbl_plugin_example'], 'unrelated table untouched');
        $this->assertSame($before['radacct'], $db->snapshot()['radacct'], 'FreeRADIUS table untouched');
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function incompatibleCoreChanges(): array
    {
        return [
            'tbl_users.id signed' => ['ALTER TABLE `tbl_users` MODIFY `id` INT NOT NULL AUTO_INCREMENT', 'tbl_users.id'],
            'tbl_customers MyISAM' => ['ALTER TABLE `tbl_customers` ENGINE=MyISAM', 'engine'],
            'tbl_customers utf8mb3' => ['ALTER TABLE `tbl_customers` CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci', 'collation'],
            'unknown column on required core table' => ['ALTER TABLE `tbl_customers` ADD `custom_flag` TINYINT NULL', 'custom_flag'],
            'required column type changed' => ['ALTER TABLE `tbl_plans` MODIFY `price` VARCHAR(80) NOT NULL', 'tbl_plans.price'],
            'required column dropped' => ['ALTER TABLE `tbl_users` DROP COLUMN `root`', 'tbl_users.root'],
            'tbl_plans.id bigint' => ['ALTER TABLE `tbl_plans` MODIFY `id` BIGINT NOT NULL AUTO_INCREMENT', 'tbl_plans.id'],
            'extra index on core table' => ['ALTER TABLE `tbl_customers` ADD INDEX `ix_some_plugin` (`username`)', 'ix_some_plugin'],
            'required core table missing' => ['DROP TABLE `tbl_plans`', 'tbl_plans'],
        ];
    }

    #[DataProvider('incompatibleCoreChanges')]
    public function testIncompatibleRequiredCoreChangeFailsBeforeMutation(string $ddl, string $expectInReport): void
    {
        $db = $this->newDatabase(FixtureBuilder::V2);
        $db->exec($ddl);
        $before = $db->snapshot();
        $pre = $this->run_($db, 'preflight');
        $this->assertOutcome(Outcome::NOT_READY, $pre);
        $this->assertStringContainsString($expectInReport, implode("\n", $pre['report']['problems']));
        $mig = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::NOT_READY, $mig);
        $this->assertSame($before, $db->snapshot(), 'nothing may be created or altered');
        $this->assertNotContains('tbl_project_migrations', $db->tables());
    }

    public function testEmptyDatabaseIsNotReady(): void
    {
        $db = $this->newDatabase();
        $r = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::NOT_READY, $r);
        $this->assertSame([], $db->tables());
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function collisions(): array
    {
        return [
            'unregistered project table' => ['CREATE TABLE `tbl_manager_ledger` (`id` INT PRIMARY KEY) ENGINE=InnoDB', 'tbl_manager_ledger'],
            'pre-existing manager_id column' => ['ALTER TABLE `tbl_customers` ADD `manager_id` INT UNSIGNED NULL', 'manager_id'],
            'foreign key name already used' => [
                'CREATE TABLE `tbl_plugin_fk` (`id` INT PRIMARY KEY, `u` INT UNSIGNED, CONSTRAINT `fk_tree_ancestor` FOREIGN KEY (`u`) REFERENCES `tbl_users` (`id`)) ENGINE=InnoDB',
                'fk_tree_ancestor',
            ],
        ];
    }

    #[DataProvider('collisions')]
    public function testCollisionsAreNeverAdopted(string $ddl, string $expect): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $db->exec($ddl);
        $before = $db->snapshot();
        $r = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::NOT_READY, $r);
        $this->assertStringContainsString($expect, implode("\n", $r['report']['problems']));
        $this->assertSame($before, $db->snapshot());
    }

    public function testMissingPrivilegeIsNotReady(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $user = 'wp1lim_' . bin2hex(random_bytes(3));
        $pw = bin2hex(random_bytes(8));
        $db->exec("CREATE USER '{$user}'@'%' IDENTIFIED BY '{$pw}'");
        try {
            $db->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER ON `{$db->name}`.* TO '{$user}'@'%'"); // no REFERENCES
            $before = $db->snapshot();
            $r = \Reseller\Tests\Support\RunnerProcess::exec(
                [PHP_BINARY, dirname(__DIR__, 2) . '/system/reseller/migrate.php', 'migrate', '--json', '--db-host=' . $db->host, '--db-name=' . $db->name, '--db-user=' . $user, '--db-password-env=WP1_LIMITED_PW'],
                ['WP1_LIMITED_PW' => $pw]
            );
            $this->assertOutcome(Outcome::NOT_READY, $r);
            $this->assertStringContainsString('REFERENCES', implode("\n", $r['report']['problems']));
            $this->assertSame($before, $db->snapshot());
        } finally {
            $db->exec("DROP USER '{$user}'@'%'");
        }
    }
}
