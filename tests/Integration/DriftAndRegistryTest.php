<?php
namespace Reseller\Tests\Integration;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Migration\Outcome;
use Reseller\Migration\SchemaInspector;
use Reseller\Migration\VariantRegistry;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;
use Reseller\Tests\Support\RunnerProcess;

/** T12, T13, registry integrity, lock (T20) and variant-registry reproducibility. */
final class DriftAndRegistryTest extends IntegrationTestCase
{
    public function testManualChangeToProjectTableIsDrift(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $db->exec('ALTER TABLE `tbl_manager_ledger` ADD `hand_added` INT NULL');
        $v = $this->run_($db, 'verify');
        $this->assertOutcome(Outcome::DRIFT, $v);
        $this->assertStringContainsString('hand_added', implode("\n", $v['report']['drift']));
        $this->assertOutcome(Outcome::DRIFT, $this->run_($db, 'migrate'));
        $this->assertOutcome(Outcome::DRIFT, $this->run_($db, 'rollback', ['--all']), 'drifted objects are never dropped blindly');
        $this->assertContains('tbl_manager_ledger', $db->tables());
    }

    public function testEditedAppliedMigrationFileIsDrift(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        // run a copy of the runner whose R0002 file differs by one comment byte
        $copy = sys_get_temp_dir() . '/wp1_runner_copy_' . bin2hex(random_bytes(4));
        mkdir($copy . '/Migration', 0777, true);
        mkdir($copy . '/migrations', 0777, true);
        $src = dirname(__DIR__, 2) . '/system/reseller';
        copy($src . '/migrate.php', $copy . '/migrate.php');
        foreach (glob($src . '/Migration/*.php') as $f) {
            copy($f, $copy . '/Migration/' . basename($f));
        }
        foreach (glob($src . '/migrations/*.php') as $f) {
            copy($f, $copy . '/migrations/' . basename($f));
        }
        file_put_contents($copy . '/migrations/R0002_pricing_foundation.php', "\n// edited after apply\n", FILE_APPEND);
        $r = RunnerProcess::run($db, 'verify', [], [], $copy . '/migrate.php');
        $this->assertOutcome(Outcome::DRIFT, $r);
        $this->assertStringContainsString('R0002_pricing_foundation: applied migration file was modified', implode("\n", $r['report']['drift']));
        $this->assertOutcome(Outcome::DRIFT, RunnerProcess::run($db, 'migrate', [], [], $copy . '/migrate.php'));
        array_map('unlink', array_merge(glob($copy . '/*/*.php'), [$copy . '/migrate.php']));
        rmdir($copy . '/Migration');
        rmdir($copy . '/migrations');
        rmdir($copy);
    }

    public function testTamperedRegistryIsNotReady(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $db->exec('ALTER TABLE `tbl_project_migrations` DROP COLUMN `last_error`');
        $r = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::NOT_READY, $r);
        $this->assertStringContainsString('migration registry does not match its contract', implode("\n", $r['report']['problems']));
    }

    public function testUnknownMigrationInRegistryIsNotReady(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $db->exec("INSERT INTO tbl_project_migrations (migration_id, status, checksum, steps_total, steps_completed, base_variant, core_fingerprint_before, last_run_id, updated_at) VALUES ('R0099_future', 'APPLIED', REPEAT('0',64), 1, 1, 'x', REPEAT('0',64), REPEAT('0',32), UTC_TIMESTAMP(6))");
        $r = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::NOT_READY, $r);
        $this->assertStringContainsString('unknown migration R0099_future', implode("\n", $r['report']['problems']));
    }

    public function testConcurrentRunnerIsRefusedByLock(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $lock = 'nuxr_mig_' . substr(sha1($db->name), 0, 20);
        $this->assertSame('1', (string) $db->value('SELECT GET_LOCK(?, 0)', [$lock]));
        try {
            $snap = $db->snapshot();
            $r = $this->run_($db, 'migrate');
            $this->assertOutcome(Outcome::NOT_READY, $r);
            $this->assertStringContainsString('another migration runner holds the lock', implode("\n", $r['report']['problems']));
            $this->assertSame($snap, $db->snapshot());
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
    }

    public function testCommittedVariantRegistryEqualsFreshlyBuiltFixtures(): void
    {
        $registry = VariantRegistry::load(dirname(__DIR__, 2) . '/system/reseller/Migration/variants.php');
        foreach (FixtureBuilder::ALL as $variant) {
            $db = $this->newDatabase($variant);
            $inspector = new SchemaInspector($db->db());
            $core = [];
            foreach (VariantRegistry::CORE_TABLES as $t) {
                $core[$t] = SchemaInspector::normalise($inspector->table($t));
            }
            $engine = $db->engine();
            $this->assertSame($registry->variant($engine, $variant), $core, "{$engine} {$variant}");
            $this->assertSame($variant, $registry->match($engine, $core)['id']);
        }
        $this->assertSame(FixtureBuilder::ALL, array_values(array_intersect(FixtureBuilder::ALL, $registry->variantIds('mariadb'))));
        $this->assertSame(FixtureBuilder::ALL, array_values(array_intersect(FixtureBuilder::ALL, $registry->variantIds('mysql'))));
        $this->assertCount(3, $registry->variantIds('mariadb'), 'only explicitly verified variants are supported');
        $this->assertCount(3, $registry->variantIds('mysql'));
    }
}
