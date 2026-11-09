<?php
namespace Reseller\Tests\Integration;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Migration\MigrationSet;
use Reseller\Migration\Outcome;
use Reseller\Migration\Runner;
use Reseller\Migration\SchemaInspector;
use Reseller\Migration\VariantRegistry;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP5B R0007 (owner decision OD-14 option B; owner-approved 'redefines' runner primitive): the three R0003
 * activation state columns gain REVERSAL_PENDING / REVERSED, strictly through the WP1 runner contract.
 */
final class R0007ReversalStatesMigrationTest extends IntegrationTestCase
{
    private const ID = 'R0007_activation_reversal_states';
    private const HOOK = ['NUXR_MIGRATION_TESTING' => '1'];
    private const COLUMNS = ['tbl_reseller_activations' => ['status'], 'tbl_reseller_activation_events' => ['from_state', 'to_state']];

    private static function set(): MigrationSet
    {
        return MigrationSet::fromDirectory(dirname(__DIR__, 2) . '/system/reseller/migrations');
    }

    /** @return array<string,string> "table.column" => live canonical definition */
    private function definitions(TestDatabase $db): array
    {
        $inspector = new SchemaInspector($db->db());
        $out = [];
        foreach (self::COLUMNS as $t => $cols) {
            foreach ($cols as $c) {
                $out["{$t}.{$c}"] = $inspector->table($t)['columns'][$c] ?? null;
            }
        }
        return $out;
    }

    /** @return array<string,string> "table.column" => the definition in the R0003 contract ('from') or R0007 ('to') */
    private function expected(string $which): array
    {
        $out = [];
        foreach (self::set()->get(self::ID)->redefinitions() as $t => $cols) {
            foreach ($cols as $c => $r) {
                $out["{$t}.{$c}"] = $r[$which];
            }
        }
        return $out;
    }

    private function migratedToR0006(string $variant = FixtureBuilder::V1): TestDatabase
    {
        $db = $this->newDatabase($variant);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'rollback', ['--to=R0006_reseller_audit']));
        $this->assertSame('ROLLED_BACK', $this->statuses($db)[self::ID]);
        $this->assertSame($this->expected('from'), $this->definitions($db));
        return $db;
    }

    /** One activation (and its initial event) written with the given states; FK parents are irrelevant here. */
    private function activation(TestDatabase $db, string $status, ?string $eventFrom = null, ?string $eventTo = null): int
    {
        $db->exec('SET SESSION foreign_key_checks = 0');
        $id = $db->insertRow('tbl_reseller_activations', [
            'reference' => 'r7-' . bin2hex(random_bytes(4)), 'idempotency_key' => bin2hex(random_bytes(8)), 'action' => 'NEW',
            'status' => $status, 'channel' => 'SYSTEM', 'manager_id' => 1, 'actor_type' => 'SYSTEM', 'customer_id' => 1, 'plan_id' => 1,
            'created_at' => '2026-10-03 00:00:00', 'updated_at' => '2026-10-03 00:00:00',
        ]);
        $db->insertRow('tbl_reseller_activation_events', ['activation_id' => $id, 'sequence_no' => 1, 'event_type' => 'STATE_TRANSITION',
            'from_state' => null, 'to_state' => 'CREATED', 'actor_type' => 'SYSTEM', 'created_at' => '2026-10-03 00:00:00']);
        if ($eventTo !== null) {
            $db->insertRow('tbl_reseller_activation_events', ['activation_id' => $id, 'sequence_no' => 2, 'event_type' => 'STATE_TRANSITION',
                'from_state' => $eventFrom, 'to_state' => $eventTo, 'actor_type' => 'SYSTEM', 'created_at' => '2026-10-03 00:00:00']);
        }
        $db->exec('SET SESSION foreign_key_checks = 1');
        return $id;
    }

    #[DataProvider('variants')]
    public function testCleanApplyAndVerifyOnEveryVariant(string $variant): void
    {
        $db = $this->newDatabase($variant);
        $r = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::OK, $r);
        $this->assertSame('APPLIED', $this->statuses($db)[self::ID]);
        $this->assertSame($this->expected('to'), $this->definitions($db));
        $row = $db->rows('SELECT checksum, steps_total, steps_completed FROM tbl_project_migrations WHERE migration_id = ?', [self::ID])[0];
        $this->assertSame(hash_file('sha256', dirname(__DIR__, 2) . '/system/reseller/migrations/' . self::ID . '.php'), $row['checksum']);
        $this->assertSame(['2', '2'], [(string) $row['steps_total'], (string) $row['steps_completed']]);

        // verify / status: OK, and no false R0003 drift although R0003's own contract still names the 15 states
        foreach (['verify', 'status', 'preflight'] as $cmd) {
            $v = $this->run_($db, $cmd);
            $this->assertOutcome(Outcome::OK, $v, $cmd);
            $this->assertSame([], $v['report']['drift'] ?? [], "{$cmd}: no drift");
        }
        // R0007 never touches a legacy core table: its rollback and re-apply leave the (R0005-extended) core identical
        $core = $this->coreFingerprint($db);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'rollback', ['--to=R0006_reseller_audit']));
        $this->assertSame($core, $this->coreFingerprint($db));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $this->assertSame($core, $this->coreFingerprint($db));
        // the new values are storable; the old ones still are
        $this->activation($db, 'REVERSED', 'REVERSAL_PENDING', 'REVERSED');
        $this->activation($db, 'COMPLETED');
        $this->assertSame(2, (int) $db->value("SELECT COUNT(*) FROM tbl_reseller_activations"));
    }

    public function testR0003DriftIsStillDetectedOutsideTheRedefinedColumns(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        // a foreign change of another R0003 ENUM is drift, the redefinition covers only the three approved columns
        $db->exec("ALTER TABLE tbl_reseller_activations MODIFY `action` ENUM('NEW','RENEW','CHANGE_PLAN','REACTIVATE','X') NOT NULL");
        $v = $this->run_($db, 'verify');
        $this->assertOutcome(Outcome::DRIFT, $v);
        $this->assertStringContainsString('tbl_reseller_activations', implode("\n", $v['report']['drift']));
    }

    public function testUnknownThirdDefinitionWhileAppliedIsDrift(): void
    {
        $db = $this->newDatabase(FixtureBuilder::V2);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $db->exec("ALTER TABLE tbl_reseller_activations MODIFY `status` ENUM(" . self::stateList(true) . ",'OTHER') NOT NULL");
        $third = $this->definitions($db)['tbl_reseller_activations.status'];
        $this->assertNotContains($third, [$this->expected('from')['tbl_reseller_activations.status'], $this->expected('to')['tbl_reseller_activations.status']]);
        $v = $this->run_($db, 'verify');
        $this->assertOutcome(Outcome::DRIFT, $v);
        $this->assertStringContainsString('tbl_reseller_activations.status', implode("\n", $v['report']['drift']));
        // and rollback refuses to touch an unknown definition
        $snap = $db->snapshot();
        $this->assertNotSame(Outcome::OK, $this->run_($db, 'rollback', ['--to=R0006_reseller_audit'])['exit']);
        $this->assertSame($snap, $db->snapshot());
    }

    public function testToDefinitionPresentWhileR0007NotAppliedIsACollision(): void
    {
        $db = $this->migratedToR0006();
        // someone extended the ENUM by hand (exactly the R0007 'to' definition) without the runner
        $db->exec("ALTER TABLE tbl_reseller_activations MODIFY `status` ENUM(" . self::stateList(true) . ") NOT NULL");
        $this->assertSame($this->expected('to')['tbl_reseller_activations.status'], $this->definitions($db)['tbl_reseller_activations.status']);
        $snap = $db->snapshot();
        foreach (['preflight', 'migrate'] as $cmd) {
            $r = $this->run_($db, $cmd);
            // fail closed twice over: a collision for R0007 and drift of the R0003 contract (DRIFT takes precedence)
            $this->assertOutcome(Outcome::DRIFT, $r, $cmd);
            $this->assertStringContainsString('collision: column tbl_reseller_activations.status already has the definition of migration ' . self::ID, implode("\n", $r['report']['problems']));
            $this->assertStringContainsString('R0003_activation_foundation: tbl_reseller_activations.status', implode("\n", $r['report']['drift']));
        }
        $this->assertSame($snap, $db->snapshot(), 'nothing applied');
        $this->assertSame('ROLLED_BACK', $this->statuses($db)[self::ID], 'never APPLIED');
    }

    public function testUnknownThirdDefinitionWhileNotAppliedFailsClosed(): void
    {
        $db = $this->migratedToR0006(FixtureBuilder::V3);
        $db->exec("ALTER TABLE tbl_reseller_activation_events MODIFY `to_state` ENUM(" . self::stateList(false) . ",'X') NOT NULL COMMENT 'state after this event'");
        $snap = $db->snapshot();
        $r = $this->run_($db, 'migrate');
        $this->assertNotSame(Outcome::OK, $r['exit'], $r['raw']);
        $this->assertStringContainsString('tbl_reseller_activation_events.to_state', implode("\n", array_merge($r['report']['problems'], $r['report']['drift'] ?? [])));
        $this->assertSame($snap, $db->snapshot(), 'nothing applied');
        $this->assertSame('ROLLED_BACK', $this->statuses($db)[self::ID]);
    }

    public function testInterruptedApplyAfterFirstAlterBlocksAndIsRecoverable(): void
    {
        $db = $this->migratedToR0006(FixtureBuilder::V2);
        $preCore = $this->coreFingerprint($db);
        // the process dies right after the first ALTER succeeded, before its completion is journaled
        $r = $this->run_($db, 'migrate', [], self::HOOK + ['NUXR_MIGRATION_TEST_CRASH' => 'UP:' . self::ID . ':1']);
        $this->assertSame(9, $r['exit'], $r['raw']);
        $this->assertSame('APPLYING', $this->statuses($db)[self::ID]);
        $defs = $this->definitions($db);
        $this->assertSame($this->expected('to')['tbl_reseller_activations.status'], $defs['tbl_reseller_activations.status'], 'first ALTER ran');
        $this->assertSame($this->expected('from')['tbl_reseller_activation_events.to_state'], $defs['tbl_reseller_activation_events.to_state'], 'second ALTER did not');

        foreach (['status', 'verify'] as $cmd) {
            $this->assertOutcome(Outcome::NOT_READY, $this->run_($db, $cmd), $cmd);
        }
        $m = $this->run_($db, 'migrate');
        $this->assertOutcome(Outcome::NOT_READY, $m);
        $this->assertSame('ROLLBACK_REQUIRED', $this->statuses($db)[self::ID], 'never APPLIED');
        $this->assertSame($this->expected('from')['tbl_reseller_activation_events.to_state'], $this->definitions($db)['tbl_reseller_activation_events.to_state']);

        $rb = $this->run_($db, 'rollback', ['--to=R0006_reseller_audit']);
        $this->assertOutcome(Outcome::OK, $rb);
        $this->assertSame($this->expected('from'), $this->definitions($db), 'R0003 definitions restored');
        $this->assertSame($preCore, $this->coreFingerprint($db));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $this->assertSame($this->expected('to'), $this->definitions($db));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'verify'));
    }

    public function testFailedSecondAlterIsPartialAndRollbackRestores(): void
    {
        $db = $this->migratedToR0006();
        $r = $this->run_($db, 'migrate', [], self::HOOK + ['NUXR_MIGRATION_TEST_FAIL' => 'UP:' . self::ID . ':2']);
        $this->assertOutcome(Outcome::FAILED, $r);
        $this->assertSame('FAILED_PARTIAL', $this->statuses($db)[self::ID]);
        $this->assertSame('1', (string) $db->value('SELECT steps_completed FROM tbl_project_migrations WHERE migration_id = ?', [self::ID]));
        $this->assertOutcome(Outcome::NOT_READY, $this->run_($db, 'migrate'), 'blocked until rolled back');
        $rb = $this->run_($db, 'rollback', ['--to=R0006_reseller_audit']);
        $this->assertOutcome(Outcome::OK, $rb);
        $this->assertSame($this->expected('from'), $this->definitions($db));
        $skipped = array_map('intval', array_column($db->rows(
            "SELECT step_no FROM tbl_project_migration_journal WHERE migration_id = ? AND direction = 'DOWN' AND event = 'STEP_SKIPPED_ABSENT' ORDER BY id", [self::ID]), 'step_no'));
        $this->assertSame([1], $skipped, 'the never-extended event columns are skipped, the status column is restored');
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
    }

    #[DataProvider('variants')]
    public function testRollbackRestoresExactR0003Definitions(string $variant): void
    {
        $db = $this->newDatabase($variant);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $this->activation($db, 'COMPLETED', 'CREATED', 'VALIDATED'); // old values: rollback is allowed and keeps them
        $inspector = new SchemaInspector($db->db());
        $r0003 = self::set()->get('R0003_activation_foundation')->contract['tables'];
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'rollback', ['--to=R0006_reseller_audit']));
        foreach (array_keys(self::COLUMNS) as $t) {
            $this->assertSame([], SchemaInspector::diff($t, SchemaInspector::normalise($r0003[$t]), SchemaInspector::normalise($inspector->table($t))), "{$t} equals its R0003 contract exactly");
        }
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM tbl_reseller_activations'), 'data kept');
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'verify'));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'verify'));
        // full rollback and forward again
        $db->exec('SET SESSION foreign_key_checks = 0');
        $db->exec('DELETE FROM tbl_reseller_activation_events');
        $db->exec('DELETE FROM tbl_reseller_activations');
        $db->exec('SET SESSION foreign_key_checks = 1');
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'rollback', ['--all']));
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        $this->assertSame(['APPLIED'], array_values(array_unique($this->statuses($db))));
    }

    /** @return array<string,array{0:string}> */
    public static function newStateUses(): array
    {
        return [
            'activation REVERSAL_PENDING' => ['activation:REVERSAL_PENDING'],
            'activation REVERSED' => ['activation:REVERSED'],
            'event to_state REVERSAL_PENDING' => ['to:REVERSAL_PENDING'],
            'event to_state REVERSED' => ['to:REVERSED'],
            'event from_state REVERSAL_PENDING' => ['from:REVERSAL_PENDING'],
            'event from_state REVERSED' => ['from:REVERSED'],
        ];
    }

    #[DataProvider('newStateUses')]
    public function testRollbackRefusedWhileANewStateIsUsed(string $use): void
    {
        $db = $this->newDatabase(FixtureBuilder::V1);
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
        [$where, $state] = explode(':', $use);
        $id = match ($where) {
            'activation' => $this->activation($db, $state),
            'to' => $this->activation($db, 'COMPLETED', 'COMPLETED', $state),
            'from' => $this->activation($db, 'COMPLETED', $state, 'COMPLETED'),
        };
        $snap = $db->snapshot();
        $r = $this->run_($db, 'rollback', ['--to=R0006_reseller_audit']);
        $this->assertNotSame(Outcome::OK, $r['exit'], $r['raw']);
        $this->assertStringContainsString('contains values of the redefined definition', implode("\n", $r['report']['problems']));
        $this->assertSame($snap, $db->snapshot(), 'nothing rolled back');
        $this->assertSame('APPLIED', $this->statuses($db)[self::ID]);
        $this->assertSame($this->expected('to'), $this->definitions($db));
        // once the rows are gone the rollback proceeds
        $db->exec("DELETE FROM tbl_reseller_activation_events WHERE activation_id = {$id}");
        $db->exec("DELETE FROM tbl_reseller_activations WHERE id = {$id}");
        $this->assertOutcome(Outcome::OK, $this->run_($db, 'rollback', ['--to=R0006_reseller_audit']));
        $this->assertSame($this->expected('from'), $this->definitions($db));
    }

    public function testChecksumAndContractFingerprintAreDeterministic(): void
    {
        $rows = [];
        foreach ([FixtureBuilder::V1, FixtureBuilder::V3] as $v) {
            $db = $this->newDatabase($v);
            $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
            $rows[] = $db->rows('SELECT migration_id, checksum, contract_fingerprint FROM tbl_project_migrations ORDER BY migration_id');
            // rollback and re-apply: the same fingerprint again
            $this->assertOutcome(Outcome::OK, $this->run_($db, 'rollback', ['--to=R0006_reseller_audit']));
            $this->assertOutcome(Outcome::OK, $this->run_($db, 'migrate'));
            $rows[] = $db->rows('SELECT migration_id, checksum, contract_fingerprint FROM tbl_project_migrations ORDER BY migration_id');
        }
        $this->assertCount(8, $rows[0]);
        foreach ($rows as $r) {
            $this->assertSame($rows[0], $r);
        }
        $r7 = $rows[0][6];
        $this->assertSame(self::ID, $r7['migration_id']);
        $this->assertSame(self::set()->get(self::ID)->checksum, $r7['checksum']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $r7['contract_fingerprint']);
        $this->assertNotSame($rows[0][2]['contract_fingerprint'], $r7['contract_fingerprint']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function badTargets(): array
    {
        return [
            'legacy core table' => ["'tbl_customers' => ['status' => ['from' => \"enum('Active','Banned','Disabled','Inactive','Limited','Suspended') collate utf8mb4_general_ci not null\", 'to' => \"enum('Active','X') collate utf8mb4_general_ci not null\", 'data_guard' => 'SELECT 1 FROM `tbl_customers` LIMIT 1']]", 'may only target a project table'],
            'legacy non-core table' => ["'tbl_voucher' => ['status' => ['from' => 'varchar(25) collate utf8mb4_general_ci not null', 'to' => 'varchar(30) collate utf8mb4_general_ci not null', 'data_guard' => 'SELECT 1 FROM `tbl_voucher` LIMIT 1']]", 'may only target a project table'],
            'wrong from' => ["'tbl_reseller_activations' => ['status' => ['from' => 'varchar(20) not null', 'to' => 'varchar(30) not null', 'data_guard' => 'SELECT 1 FROM `tbl_reseller_activations` LIMIT 1']]", "'from' is not the column's current contract definition"],
            'unknown column' => ["'tbl_reseller_activations' => ['nope' => ['from' => 'int null', 'to' => 'bigint null', 'data_guard' => 'SELECT 1 FROM `tbl_reseller_activations` LIMIT 1']]", "'from' is not the column's current contract definition"],
        ];
    }

    /** The set-level check refuses any redefinition that is not of a column of a project table created earlier, before touching the database. */
    #[DataProvider('badTargets')]
    public function testRedefinitionOfANonProjectTargetIsRefusedBeforeAnyWork(string $redefines, string $message): void
    {
        $dir = sys_get_temp_dir() . '/r7set_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (glob(dirname(__DIR__, 2) . '/system/reseller/migrations/R000[1-6]_*.php') as $f) {
                copy($f, $dir . '/' . basename($f));
            }
            file_put_contents($dir . '/R0007_bad_target.php', "<?php\nreturn ['id' => 'R0007_bad_target', 'description' => 'x', 'requires' => 'R0006_reseller_audit',"
                . " 'up' => [['id' => 'a', 'sql' => 'ALTER TABLE `x` MODIFY `y` INT']], 'down' => [['id' => 'b', 'sql' => 'ALTER TABLE `x` MODIFY `y` INT', 'if_exists' => ['table' => 'x']]],"
                . " 'contract' => ['tables' => [], 'table_parts' => [], 'redefines' => [{$redefines}]]];\n");
            $db = $this->newDatabase(FixtureBuilder::V1);
            $snap = $db->snapshot();
            $runner = new Runner($db->db(), MigrationSet::fromDirectory($dir), VariantRegistry::load(dirname(__DIR__, 2) . '/system/reseller/Migration/variants.php'), 'test');
            foreach (['status', 'preflight', 'migrate', 'verify'] as $cmd) {
                $this->assertSame(Outcome::USAGE, $runner->run($cmd, []), $cmd);
                $this->assertStringContainsString($message, implode("\n", $runner->report()['problems']));
            }
            $this->assertSame($snap, $db->snapshot(), 'nothing written, not even the registry');
        } finally {
            array_map('unlink', glob($dir . '/*.php'));
            rmdir($dir);
        }
    }

    private static function stateList(bool $extended): string
    {
        $s = "'CREATED','VALIDATED','FUNDS_RESERVED','PROVISIONING','PROVISIONED','VERIFYING','VERIFIED','FINANCIAL_COMMITTING','COMPLETED','FAILED','RETRY_PENDING','RECONCILIATION_REQUIRED','ROLLBACK_PENDING','ROLLED_BACK','CANCELLED'";
        return $extended ? $s . ",'REVERSAL_PENDING','REVERSED'" : $s;
    }
}
