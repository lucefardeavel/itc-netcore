<?php
namespace Reseller\Tests\Integration;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Migration\MigrationSet;
use Reseller\Migration\Outcome;
use Reseller\Migration\SchemaInspector;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;
use Reseller\Tests\Support\TestDatabase;

/** T2–T5, T14, T16–T18: forward migration, contracts, durability, idempotency, legacy safety. */
final class ForwardMigrationTest extends IntegrationTestCase
{
    private function seedLegacyData(TestDatabase $db): void
    {
        // legacy users with a root tree and customers created by agents: none of this may be used
        $admin = $db->insertRow('tbl_users', ['username' => 'sa_seed', 'user_type' => 'SuperAdmin', 'status' => 'Active']);
        $agent = $db->insertRow('tbl_users', ['username' => 'agent_seed', 'user_type' => 'Agent', 'status' => 'Active', 'root' => 0]);
        $sales = $db->insertRow('tbl_users', ['username' => 'sales_seed', 'user_type' => 'Sales', 'status' => 'Active', 'root' => $agent]);
        foreach ([['c_admin', $admin], ['c_agent', $agent], ['c_sales', $sales]] as [$u, $by]) {
            $db->insertRow('tbl_customers', ['username' => $u, 'password' => 'p', 'fullname' => $u, 'created_by' => $by, 'balance' => 5]);
        }
        $db->insertRow('tbl_plans', ['name_plan' => 'seed plan', 'price' => '1000', 'type' => 'Hotspot']);
    }

    #[DataProvider('variants')]
    public function testForwardMigrationProducesContractAndKeepsLegacyDataUntouched(string $variant): void
    {
        $db = $this->newDatabase($variant);
        $this->seedLegacyData($db);
        $customerCols = array_keys((new SchemaInspector($db->db()))->columns('tbl_customers'));
        $planCols = array_keys((new SchemaInspector($db->db()))->columns('tbl_plans'));
        $hashCustomers = $db->dataHash('tbl_customers', $customerCols);
        $hashPlans = $db->dataHash('tbl_plans', $planCols);
        $hashUsers = $db->dataHash('tbl_users', array_keys((new SchemaInspector($db->db()))->columns('tbl_users')));
        $voucherDef = (new SchemaInspector($db->db()))->table('tbl_voucher');
        $priceDefs = [];
        foreach (['tbl_plans' => ['price', 'price_old'], 'tbl_transactions' => ['price'], 'tbl_payment_gateway' => ['price']] as $t => $cols) {
            foreach ($cols as $c) {
                $priceDefs["{$t}.{$c}"] = (new SchemaInspector($db->db()))->columns($t)[$c] ?? null;
            }
        }

        $r = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::OK, $r);
        $this->assertSame($variant, $r['report']['variant']);

        // T3: every created object equals its contract (independent re-check here, not only the runner's postflight)
        $inspector = new SchemaInspector($db->db());
        foreach (MigrationSet::fromDirectory(dirname(__DIR__, 2) . '/system/reseller/migrations')->migrations as $m) {
            foreach ($m->contract['tables'] as $t => $expected) {
                $this->assertSame([], SchemaInspector::diff($t, SchemaInspector::normalise($expected), SchemaInspector::normalise($inspector->table($t))), "{$m->id} {$t}");
            }
        }
        foreach ($this->projectTables() as $t) {
            $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM `{$t}`"), "{$t} must be empty after WP1");
        }

        // T16: existing customers NULL-owned, no silent assignment, legacy columns byte-identical
        $this->assertSame(3, (int) $db->value('SELECT COUNT(*) FROM tbl_customers'));
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM tbl_customers WHERE manager_id IS NOT NULL OR assigned_sales_id IS NOT NULL'));
        $this->assertSame($hashCustomers, $db->dataHash('tbl_customers', $customerCols));
        $this->assertSame($hashUsers, $db->dataHash('tbl_users', array_keys($inspector->columns('tbl_users'))), 'tbl_users (incl. root) untouched');
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM tbl_manager_tree'), 'no import from tbl_users.root');

        // T17: legacy price columns unchanged (definition + data)
        foreach ($priceDefs as $k => $def) {
            [$t, $c] = explode('.', $k);
            $this->assertSame($def, $inspector->columns($t)[$c] ?? null, "{$k} definition unchanged");
        }
        $this->assertSame($hashPlans, $db->dataHash('tbl_plans', $planCols));

        // T18: no voucher-commerce schema
        $this->assertSame(SchemaInspector::normalise($voucherDef), SchemaInspector::normalise($inspector->table('tbl_voucher')));
        foreach ($db->tables() as $t) {
            if (!in_array($t, ['tbl_voucher'], true)) {
                $this->assertStringNotContainsStringIgnoringCase('voucher', $t);
            }
        }
    }

    public function testRegistryIsDurableAndJournalComplete(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        // a fresh connection / process sees the same durable state
        $fresh = new \PDO("mysql:host={$db->host};dbname={$db->name}", 'root', TestDatabase::rootPassword());
        $rows = $fresh->query('SELECT migration_id, status, checksum, steps_total, steps_completed, base_variant, core_fingerprint_before, contract_fingerprint FROM tbl_project_migrations ORDER BY migration_id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(5, $rows);
        $set = MigrationSet::fromDirectory(dirname(__DIR__, 2) . '/system/reseller/migrations');
        foreach ($rows as $row) {
            $m = $set->get($row['migration_id']);
            $this->assertSame('APPLIED', $row['status']);
            $this->assertSame($m->checksum, $row['checksum'], 'checksum equals file hash');
            $this->assertSame((string) count($m->up), (string) $row['steps_total']);
            $this->assertSame((string) count($m->up), (string) $row['steps_completed']);
            $this->assertSame(FixtureBuilder::V1, $row['base_variant']);
            $this->assertNotEmpty($row['contract_fingerprint']);
        }
        // every step has STEP_STARTED + STEP_COMPLETED, every migration a POSTFLIGHT_PASSED
        $events = $fresh->query("SELECT migration_id, event, COUNT(*) n FROM tbl_project_migration_journal WHERE direction='UP' OR event='POSTFLIGHT_PASSED' GROUP BY migration_id, event")->fetchAll(\PDO::FETCH_ASSOC);
        $by = [];
        foreach ($events as $e) {
            $by[$e['migration_id']][$e['event']] = (int) $e['n'];
        }
        foreach ($set->migrations as $m) {
            $this->assertSame(count($m->up), $by[$m->id]['STEP_STARTED'] ?? 0);
            $this->assertSame(count($m->up), $by[$m->id]['STEP_COMPLETED'] ?? 0);
            $this->assertSame(1, $by[$m->id]['POSTFLIGHT_PASSED'] ?? 0);
            $this->assertArrayNotHasKey('STEP_FAILED', $by[$m->id]);
        }
    }

    #[DataProvider('variants')]
    public function testSecondRunExecutesZeroDdlAndWritesNothing(string $variant): void
    {
        $db = $this->newDatabase($variant);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $snap = $db->snapshot();
        $journalRows = (int) $db->value('SELECT COUNT(*) FROM tbl_project_migration_journal');
        $counters = $db->ddlCounters();
        $r = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::OK, $r);
        $this->assertContains('nothing to do: all migrations are applied and verified', $r['report']['messages']);
        $this->assertSame($counters, $db->ddlCounters(), 'second run must execute zero DDL statements');
        $this->assertSame($snap, $db->snapshot());
        $this->assertSame($journalRows, (int) $db->value('SELECT COUNT(*) FROM tbl_project_migration_journal'));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'verify'));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'status'));
    }

    public function testForeignKeySignednessAndRulesFromCatalog(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V2);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $fks = $db->rows(
            "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE, c.COLUMN_TYPE child_type, pc.COLUMN_TYPE parent_type
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
               JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME
               JOIN information_schema.COLUMNS pc ON pc.TABLE_SCHEMA = k.TABLE_SCHEMA AND pc.TABLE_NAME = k.REFERENCED_TABLE_NAME AND pc.COLUMN_NAME = k.REFERENCED_COLUMN_NAME
              WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL",
            [$db->name]
        );
        $this->assertGreaterThanOrEqual(33, count($fks));
        foreach ($fks as $fk) {
            $this->assertSame('RESTRICT', $fk['DELETE_RULE'], "{$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']}");
            $this->assertSame('RESTRICT', $fk['UPDATE_RULE'], "{$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']}");
            $this->assertSame(
                str_contains(strtolower($fk['parent_type']), 'unsigned'),
                str_contains(strtolower($fk['child_type']), 'unsigned'),
                "{$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']} signedness must match {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}"
            );
            if ($fk['REFERENCED_TABLE_NAME'] === 'tbl_users') {
                $this->assertStringContainsStringIgnoringCase('unsigned', $fk['child_type']);
            }
        }
        // legacy reference columns keep their (signed) types (C19)
        $cols = (new SchemaInspector($db->db()));
        $this->assertSame("int not null default '0'", $cols->columns('tbl_customers')['created_by']);
        $this->assertSame("int not null default '0'", $cols->columns('tbl_users')['root']);
    }
}
