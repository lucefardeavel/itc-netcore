<?php
namespace Reseller\Tests\Integration;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Migration\Outcome;
use Reseller\Migration\SchemaInspector;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;
use Reseller\Tests\Support\TestDatabase;

/**
 * T10, T11 and owner additions 3–4 (C28): partial state is durably journaled, blocks every later
 * migrate, is reported by status/verify, and only the rollback flow continues; rollback restores
 * the prior recognised core schema.
 */
final class PartialFailureTest extends IntegrationTestCase
{
    private const HOOK = ['NUXR_MIGRATION_TESTING' => '1'];

    private function journalSteps(TestDatabase $db, string $id, string $direction, string $event): array
    {
        return array_map('intval', array_column($db->rows(
            'SELECT step_no FROM tbl_project_migration_journal WHERE migration_id = ? AND direction = ? AND event = ? ORDER BY id',
            [$id, $direction, $event]
        ), 'step_no'));
    }

    #[DataProvider('variants')]
    public function testFailedPartialBlocksAndRollbackRestoresRecognisedCore(string $variant): void
    {
        $db = $this->newDatabase($variant);
        $preCore = $this->coreFingerprint($db);
        $preSnap = $db->snapshot();

        $r = $this->run_($db, 'migrate', [], self::HOOK + ['NUXR_MIGRATION_TEST_FAIL' => 'UP:R0004_wallet_finance_foundation:3']);
        $this->assertOutcome(Outcome::FAILED, $r);
        $this->assertSame([
            'R0001_manager_tree' => 'APPLIED',
            'R0002_pricing_foundation' => 'APPLIED',
            'R0003_activation_foundation' => 'APPLIED',
            'R0004_wallet_finance_foundation' => 'FAILED_PARTIAL',
        ], $this->statuses($db), 'R0005 must not have started');
        // each successfully completed step is durably known
        $this->assertSame('2', (string) $db->value("SELECT steps_completed FROM tbl_project_migrations WHERE migration_id = 'R0004_wallet_finance_foundation'"));
        $this->assertSame([1, 2], $this->journalSteps($db, 'R0004_wallet_finance_foundation', 'UP', 'STEP_COMPLETED'));
        $this->assertSame([3], $this->journalSteps($db, 'R0004_wallet_finance_foundation', 'UP', 'STEP_FAILED'));
        $tables = $db->tables();
        $this->assertContains('tbl_manager_wallets', $tables);
        $this->assertContains('tbl_manager_transfers', $tables);
        $this->assertNotContains('tbl_manager_ledger', $tables);
        $this->assertNotContains('tbl_manager_wallet_reservations', $tables);
        $this->assertSame($preCore, $this->coreFingerprint($db), 'legacy core untouched (R0005 never ran)');

        // ordinary migrate fails closed; nothing later proceeds
        $snap = $db->snapshot();
        $again = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::NOT_READY, $again);
        $this->assertStringContainsString('R0004_wallet_finance_foundation is FAILED_PARTIAL', implode("\n", $again['report']['problems']));
        $this->assertSame($snap, $db->snapshot(), 'blocked migrate changes nothing');

        // status / verify clearly report the partial state
        foreach (['status', 'verify'] as $cmd) {
            $s = $this->run_($db, $cmd);
            $this->assertOutcome(Outcome::NOT_READY, $s);
            $this->assertNotEmpty($s['report']['blocking']);
            $this->assertStringContainsString('FAILED_PARTIAL after 2/4 steps', $s['report']['blocking'][0]);
        }

        // the defined recovery flow
        $rb = $this->run_($db, 'rollback', ['--all']);
        $this->assertOutcome(Outcome::OK, $rb);
        $this->assertSame($preCore, $this->coreFingerprint($db));
        $after = $db->snapshot();
        unset($after['tbl_project_migrations'], $after['tbl_project_migration_journal']);
        $this->assertSame($preSnap, $after, 'prior recognised schema restored exactly');
        $skipped = $this->journalSteps($db, 'R0004_wallet_finance_foundation', 'DOWN', 'STEP_SKIPPED_ABSENT');
        $this->assertSame([1, 2], $skipped, 'down steps for never-created ledger/reservations are skipped as absent');

        // and forward again
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $this->assertSame(['APPLIED'], array_values(array_unique($this->statuses($db))));
    }

    public function testInterruptedRunBecomesRollbackRequired(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V2);
        $preCore = $this->coreFingerprint($db);
        // the process dies right after step 1's DDL succeeded and before its completion is journaled
        $r = $this->run_($db, 'migrate', [], self::HOOK + ['NUXR_MIGRATION_TEST_CRASH' => 'UP:R0002_pricing_foundation:1']);
        $this->assertSame(9, $r['exit'], $r['raw']);
        $this->assertSame('APPLYING', $this->statuses($db)['R0002_pricing_foundation']);
        $this->assertContains('tbl_reseller_plan_base_costs', $db->tables());
        $this->assertSame([1], $this->journalSteps($db, 'R0002_pricing_foundation', 'UP', 'STEP_STARTED'));
        $this->assertSame([], $this->journalSteps($db, 'R0002_pricing_foundation', 'UP', 'STEP_COMPLETED'));

        $st = $this->run_($db, 'status');
        $this->assertOutcome(Outcome::NOT_READY, $st);
        $this->assertStringContainsString('APPLYING (in progress, or interrupted)', $st['report']['blocking'][0]);

        $m = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::NOT_READY, $m);
        $this->assertSame('ROLLBACK_REQUIRED', $this->statuses($db)['R0002_pricing_foundation'], 'interrupted run recorded durably');

        $this->assertOutcome(Outcome::OK, $this->run_($db, 'rollback', ['--all']));
        $this->assertSame($preCore, $this->coreFingerprint($db));
        $this->assertNotContains('tbl_reseller_plan_base_costs', $db->tables());
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
    }

    public function testFailureOfAtomicCustomerAlterLeavesTblCustomersUntouched(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V3);
        $before = SchemaInspector::normalise((new SchemaInspector($db->db()))->table('tbl_customers'));
        $r = $this->run_($db, 'migrate', [], self::HOOK + ['NUXR_MIGRATION_TEST_FAIL' => 'UP:R0005_customer_ownership:1']);
        $this->assertOutcome(Outcome::FAILED, $r);
        $this->assertSame('FAILED_PARTIAL', $this->statuses($db)['R0005_customer_ownership']);
        $this->assertSame($before, SchemaInspector::normalise((new SchemaInspector($db->db()))->table('tbl_customers')));
        $this->assertOutcome(Outcome::NOT_READY, $this->run_($db, 'migrate'));
        // rollback of only the failed migration keeps the earlier ones
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'rollback', ['--to=R0004_wallet_finance_foundation']));
        $this->assertSame('ROLLED_BACK', $this->statuses($db)['R0005_customer_ownership']);
        $this->assertSame('APPLIED', $this->statuses($db)['R0004_wallet_finance_foundation']);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
    }

    /** Negative control: the postflight contract check is real — a contract the database does not satisfy fails closed. */
    public function testPostflightContractMismatchFailsClosed(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        // the copy mirrors system/ (reseller/ + the shared WP9 CHECK verifier in autoload/Reseller/Domain/)
        $root = sys_get_temp_dir() . '/wp1_runner_badcontract_' . bin2hex(random_bytes(4));
        $copy = $root . '/reseller';
        $src = dirname(__DIR__, 2) . '/system/reseller';
        mkdir($copy . '/Migration', 0777, true);
        mkdir($copy . '/migrations', 0777, true);
        mkdir($root . '/autoload/Reseller/Domain', 0777, true);
        copy(dirname(__DIR__, 2) . '/system/autoload/Reseller/Domain/CheckConstraints.php', $root . '/autoload/Reseller/Domain/CheckConstraints.php');
        copy($src . '/migrate.php', $copy . '/migrate.php');
        foreach (glob($src . '/Migration/*.php') as $f) {
            copy($f, $copy . '/Migration/' . basename($f));
        }
        foreach (glob($src . '/migrations/*.php') as $f) {
            copy($f, $copy . '/migrations/' . basename($f));
        }
        $file = $copy . '/migrations/R0001_manager_tree.php';
        file_put_contents($file, str_replace("'depth' => 'smallint unsigned not null'", "'depth' => 'int unsigned not null'", (string) file_get_contents($file)));
        try {
            $r = \Reseller\Tests\Support\RunnerProcess::run($db, 'migrate', [], [], $copy . '/migrate.php');
            $this->assertOutcome(Outcome::FAILED, $r);
            $this->assertStringContainsString('postflight contract check failed', implode("\n", $r['report']['problems']));
            $this->assertSame(['R0001_manager_tree' => 'FAILED_PARTIAL'], $this->statuses($db));
            $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM tbl_project_migration_journal WHERE migration_id = 'R0001_manager_tree' AND event = 'POSTFLIGHT_FAILED'"));
            $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM tbl_project_migrations WHERE status = 'APPLIED'"), 'never marked applied');
            $this->assertOutcome(Outcome::NOT_READY, \Reseller\Tests\Support\RunnerProcess::run($db, 'migrate', [], [], $copy . '/migrate.php'), 'blocked afterwards');
        } finally {
            array_map('unlink', array_merge(glob($copy . '/*/*.php'), [$copy . '/migrate.php', $root . '/autoload/Reseller/Domain/CheckConstraints.php']));
            rmdir($copy . '/Migration');
            rmdir($copy . '/migrations');
            rmdir($copy);
            rmdir($root . '/autoload/Reseller/Domain');
            rmdir($root . '/autoload/Reseller');
            rmdir($root . '/autoload');
            rmdir($root);
        }
    }

    public function testFailedRollbackBlocksAndCanBeResumed(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $preCore = $this->coreFingerprint($db);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $r = $this->run_($db, 'rollback', ['--all'], self::HOOK + ['NUXR_MIGRATION_TEST_FAIL' => 'DOWN:R0004_wallet_finance_foundation:2']);
        $this->assertOutcome(Outcome::FAILED, $r);
        $this->assertSame('ROLLBACK_FAILED', $this->statuses($db)['R0004_wallet_finance_foundation']);
        $this->assertSame('ROLLED_BACK', $this->statuses($db)['R0005_customer_ownership']);
        $this->assertOutcome(Outcome::NOT_READY, $this->run_($db, 'migrate'));
        $this->assertOutcome(Outcome::NOT_READY, $this->run_($db, 'verify'));
        // resume the defined rollback flow: already-dropped objects are skipped as absent
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'rollback', ['--all']));
        $this->assertSame($preCore, $this->coreFingerprint($db));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
    }
}
