<?php
namespace Reseller\Tests\Integration;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Migration\Outcome;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;

/** T6, T7, T19: rollback restores the recognised core schema; forward after rollback; data guard. */
final class RollbackTest extends IntegrationTestCase
{
    #[DataProvider('variants')]
    public function testFullRollbackRestoresPreMigrationCoreFingerprintAndForwardAgain(string $variant): void
    {
        $db = $this->newDatabase($variant);
        $beforeSnap = $db->snapshot();
        $beforeCore = $this->coreFingerprint($db);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $this->assertNotSame($beforeCore, $this->coreFingerprint($db), 'tbl_customers gained the ownership columns');

        $rb = $this->run_($db, 'rollback', ['--all']);
        $this->assertOutcome(Outcome::OK, $rb);
        $this->assertSame($beforeCore, $this->coreFingerprint($db), 'pre-migration core fingerprint restored');
        $after = $db->snapshot();
        // everything except the runner registry equals the pre-migration state
        unset($after['tbl_project_migrations'], $after['tbl_project_migration_journal']);
        $this->assertSame($beforeSnap, $after, 'every legacy table restored exactly; no project object left');
        foreach ($this->statuses($db) as $id => $st) {
            $this->assertSame('ROLLED_BACK', $st, $id);
        }
        // history is retained, not discarded
        $this->assertGreaterThan(0, (int) $db->value("SELECT COUNT(*) FROM tbl_project_migration_journal WHERE direction = 'DOWN' AND event = 'STEP_COMPLETED'"));
        $this->assertGreaterThan(0, (int) $db->value("SELECT COUNT(*) FROM tbl_project_migration_journal WHERE direction = 'UP' AND event = 'STEP_COMPLETED'"));

        // T7: forward after rollback
        $fw = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::OK, $fw);
        foreach ($this->statuses($db) as $id => $st) {
            $this->assertSame('APPLIED', $st, $id);
        }
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'verify'));
    }

    public function testPartialRollbackToTarget(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V2);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $rb = $this->run_($db, 'rollback', ['--to=R0003_activation_foundation']);
        $this->assertOutcome(Outcome::OK, $rb);
        $this->assertSame([
            'R0001_manager_tree' => 'APPLIED',
            'R0002_pricing_foundation' => 'APPLIED',
            'R0003_activation_foundation' => 'APPLIED',
            'R0004_wallet_finance_foundation' => 'ROLLED_BACK',
            'R0005_customer_ownership' => 'ROLLED_BACK',
        ], $this->statuses($db));
        $this->assertFalse(in_array('tbl_manager_ledger', $db->tables(), true));
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_customers' AND COLUMN_NAME IN ('manager_id','assigned_sales_id')"));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'verify'));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $this->assertSame(['APPLIED'], array_values(array_unique($this->statuses($db))));
    }

    public function testRollbackRefusesWhenNewDomainHoldsData(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $uid = $db->insertRow('tbl_users', ['username' => 'mgr', 'user_type' => 'Agent', 'status' => 'Active']);
        $db->exec("INSERT INTO tbl_manager_wallets (manager_id, balance, debt_limit, created_at, updated_at) VALUES ({$uid}, 0, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
        $snap = $db->snapshot();
        $r = $this->run_($db, 'rollback', ['--all']);
        $this->assertOutcome(Outcome::NOT_READY, $r);
        $this->assertStringContainsString('tbl_manager_wallets contains rows', implode("\n", $r['report']['problems']));
        $this->assertSame($snap, $db->snapshot(), 'nothing dropped');

        // a non-NULL ownership value also blocks rollback of R0005
        $db->exec('DELETE FROM tbl_manager_wallets');
        $cid = $db->insertRow('tbl_customers', ['username' => 'owned', 'password' => 'p', 'fullname' => 'o', 'balance' => 0]);
        $db->exec("UPDATE tbl_customers SET manager_id = {$uid} WHERE id = {$cid}");
        $r2 = $this->run_($db, 'rollback', ['--all']);
        $this->assertOutcome(Outcome::NOT_READY, $r2);
        $this->assertStringContainsString('tbl_customers.manager_id contains non-NULL values', implode("\n", $r2['report']['problems']));
    }

    public function testRollbackRequiresExplicitScope(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $this->assertOutcome(Outcome::USAGE, $this->run_($db, 'rollback'));
    }
}
