<?php
namespace Reseller\Migration;

// CLI-only: this file must never execute through the web server (the web root is the repo root).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Durable migration registry, owned by the runner (never by a migration file).
 *
 *  tbl_project_migrations          current state per migration (one row per migration id)
 *  tbl_project_migration_journal   append-only history of every run, step, state change and result
 *
 * States:
 *   APPLYING           a forward run is executing (or died; converted to ROLLBACK_REQUIRED under the lock)
 *   APPLIED            every step + postflight succeeded
 *   FAILED_PARTIAL     a step or the postflight failed; completed steps are known from the journal
 *   ROLLBACK_REQUIRED  an interrupted forward run was detected
 *   ROLLING_BACK       a rollback is executing
 *   ROLLBACK_FAILED    a rollback step failed (manual review required)
 *   ROLLED_BACK        all of this migration's objects were removed and verified absent
 *
 * Blocking states (any of them stops `migrate` and every later migration):
 *   APPLYING, FAILED_PARTIAL, ROLLBACK_REQUIRED, ROLLING_BACK, ROLLBACK_FAILED.
 * The registry and its history are never deleted by the runner.
 */
final class Registry
{
    public const STATE_TABLE = 'tbl_project_migrations';
    public const JOURNAL_TABLE = 'tbl_project_migration_journal';
    public const BLOCKING = ['APPLYING', 'FAILED_PARTIAL', 'ROLLBACK_REQUIRED', 'ROLLING_BACK', 'ROLLBACK_FAILED'];

    public const STATE_DDL = <<<'SQL'
CREATE TABLE `tbl_project_migrations` (
  `migration_id` VARCHAR(100) NOT NULL,
  `status` ENUM('APPLYING','APPLIED','FAILED_PARTIAL','ROLLBACK_REQUIRED','ROLLING_BACK','ROLLBACK_FAILED','ROLLED_BACK') NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `steps_total` SMALLINT UNSIGNED NOT NULL,
  `steps_completed` SMALLINT UNSIGNED NOT NULL,
  `base_variant` VARCHAR(64) NOT NULL,
  `core_fingerprint_before` CHAR(64) NOT NULL,
  `contract_fingerprint` CHAR(64) NULL,
  `last_run_id` CHAR(32) NOT NULL,
  `last_error` TEXT NULL,
  `applied_at` DATETIME(6) NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Project migration state (runner-owned; timestamps UTC)'
SQL;

    public const JOURNAL_DDL = <<<'SQL'
CREATE TABLE `tbl_project_migration_journal` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` CHAR(32) NOT NULL,
  `command` VARCHAR(20) NOT NULL,
  `migration_id` VARCHAR(100) NULL,
  `direction` ENUM('UP','DOWN') NULL,
  `step_no` SMALLINT UNSIGNED NULL,
  `step_id` VARCHAR(100) NULL,
  `event` ENUM('RUN_STARTED','REGISTRY_BOOTSTRAPPED','PREFLIGHT_PASSED','STATE_CHANGED','STEP_STARTED','STEP_COMPLETED','STEP_FAILED','STEP_SKIPPED_ABSENT','POSTFLIGHT_PASSED','POSTFLIGHT_FAILED','RUN_FINISHED','RUN_ABORTED') NOT NULL,
  `state_from` VARCHAR(32) NULL,
  `state_to` VARCHAR(32) NULL,
  `sql_sha256` CHAR(64) NULL,
  `detail` TEXT NULL,
  `runner_version` VARCHAR(20) NOT NULL,
  `server_version` VARCHAR(100) NOT NULL,
  `executed_by` VARCHAR(190) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_journal_migration` (`migration_id`,`id`),
  KEY `ix_journal_run` (`run_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Append-only project migration journal (runner-owned; timestamps UTC)'
SQL;

    private const C = ' collate utf8mb4_general_ci';

    /** @return array<string,array<string,mixed>> canonical contract of the registry tables */
    public static function contract(): array
    {
        $c = self::C;
        return [
            self::STATE_TABLE => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'migration_id' => "varchar(100){$c} not null",
                    'status' => "enum('APPLYING','APPLIED','FAILED_PARTIAL','ROLLBACK_REQUIRED','ROLLING_BACK','ROLLBACK_FAILED','ROLLED_BACK'){$c} not null",
                    'checksum' => "char(64){$c} not null",
                    'steps_total' => 'smallint unsigned not null',
                    'steps_completed' => 'smallint unsigned not null',
                    'base_variant' => "varchar(64){$c} not null",
                    'core_fingerprint_before' => "char(64){$c} not null",
                    'contract_fingerprint' => "char(64){$c} null",
                    'last_run_id' => "char(32){$c} not null",
                    'last_error' => "text{$c} null",
                    'applied_at' => 'datetime(6) null',
                    'updated_at' => 'datetime(6) not null',
                ],
                'indexes' => ['PRIMARY KEY (migration_id)'],
                'foreign_keys' => [],
                'checks' => [],
            ],
            self::JOURNAL_TABLE => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'id' => 'bigint unsigned not null auto_increment',
                    'run_id' => "char(32){$c} not null",
                    'command' => "varchar(20){$c} not null",
                    'migration_id' => "varchar(100){$c} null",
                    'direction' => "enum('UP','DOWN'){$c} null",
                    'step_no' => 'smallint unsigned null',
                    'step_id' => "varchar(100){$c} null",
                    'event' => "enum('RUN_STARTED','REGISTRY_BOOTSTRAPPED','PREFLIGHT_PASSED','STATE_CHANGED','STEP_STARTED','STEP_COMPLETED','STEP_FAILED','STEP_SKIPPED_ABSENT','POSTFLIGHT_PASSED','POSTFLIGHT_FAILED','RUN_FINISHED','RUN_ABORTED'){$c} not null",
                    'state_from' => "varchar(32){$c} null",
                    'state_to' => "varchar(32){$c} null",
                    'sql_sha256' => "char(64){$c} null",
                    'detail' => "text{$c} null",
                    'runner_version' => "varchar(20){$c} not null",
                    'server_version' => "varchar(100){$c} not null",
                    'executed_by' => "varchar(190){$c} not null",
                    'created_at' => 'datetime(6) not null',
                ],
                'indexes' => [
                    'INDEX ix_journal_migration (migration_id,id)',
                    'INDEX ix_journal_run (run_id,id)',
                    'PRIMARY KEY (id)',
                ],
                'foreign_keys' => [],
                'checks' => [],
            ],
        ];
    }

    private string $runId;
    private string $command = '';

    public function __construct(
        private readonly Db $db,
        private readonly SchemaInspector $inspector,
        private readonly string $runnerVersion,
        private readonly string $executedBy,
    ) {
        $this->runId = bin2hex(random_bytes(16));
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function setCommand(string $command): void
    {
        $this->command = $command;
    }

    /** @return array{present:bool,complete:bool,problems:list<string>} */
    public function inspect(): array
    {
        $problems = [];
        $present = 0;
        foreach (self::contract() as $table => $expected) {
            $actual = $this->inspector->table($table);
            if ($actual === null) {
                continue;
            }
            $present++;
            $problems = array_merge($problems, SchemaInspector::diff($table, SchemaInspector::normalise($expected), SchemaInspector::normalise($actual)));
        }
        return ['present' => $present > 0, 'complete' => $present === 2, 'problems' => $problems];
    }

    /** Creates any missing registry table. Caller must have verified existing ones first. */
    public function bootstrap(): bool
    {
        $created = false;
        if (!$this->db->tableExists(self::JOURNAL_TABLE)) {
            $this->db->exec(self::JOURNAL_DDL);
            $created = true;
        }
        if (!$this->db->tableExists(self::STATE_TABLE)) {
            $this->db->exec(self::STATE_DDL);
            $created = true;
        }
        if ($created) {
            $this->journal('REGISTRY_BOOTSTRAPPED', null, null, null, null, 'registry tables created');
        }
        return $created;
    }

    /** @return array<string,array<string,mixed>> state rows keyed by migration id */
    public function states(): array
    {
        if (!$this->db->tableExists(self::STATE_TABLE)) {
            return [];
        }
        $out = [];
        foreach ($this->db->rows('SELECT * FROM `' . self::STATE_TABLE . '` ORDER BY migration_id') as $r) {
            $out[(string) $r['migration_id']] = $r;
        }
        return $out;
    }

    /** @param array<string,mixed> $fields */
    public function upsertState(string $migrationId, array $fields): void
    {
        $fields['updated_at'] = $this->db->utcNow();
        $fields['last_run_id'] = $this->runId;
        $exists = (int) $this->db->value('SELECT COUNT(*) FROM `' . self::STATE_TABLE . '` WHERE migration_id = ?', [$migrationId]) === 1;
        if ($exists) {
            $sets = implode(', ', array_map(static fn(string $k): string => "`{$k}` = ?", array_keys($fields)));
            $this->db->exec('UPDATE `' . self::STATE_TABLE . "` SET {$sets} WHERE migration_id = ?", [...array_values($fields), $migrationId]);
        } else {
            $fields['migration_id'] = $migrationId;
            $cols = implode(', ', array_map(static fn(string $k): string => "`{$k}`", array_keys($fields)));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $this->db->exec('INSERT INTO `' . self::STATE_TABLE . "` ({$cols}) VALUES ({$ph})", array_values($fields));
        }
    }

    public function changeState(string $migrationId, ?string $from, string $to, array $extra = [], ?string $detail = null): void
    {
        $this->upsertState($migrationId, ['status' => $to] + $extra);
        $this->journal('STATE_CHANGED', $migrationId, null, null, null, $detail, $from, $to);
    }

    public function journal(
        string $event,
        ?string $migrationId,
        ?string $direction,
        ?int $stepNo,
        ?string $stepId,
        ?string $detail = null,
        ?string $stateFrom = null,
        ?string $stateTo = null,
        ?string $sqlSha = null,
    ): void {
        $this->db->exec(
            'INSERT INTO `' . self::JOURNAL_TABLE . '` (run_id, command, migration_id, direction, step_no, step_id, event, state_from, state_to, sql_sha256, detail, runner_version, server_version, executed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->runId, $this->command, $migrationId, $direction, $stepNo, $stepId, $event, $stateFrom, $stateTo, $sqlSha,
                $detail === null ? null : mb_substr($detail, 0, 4000),
                $this->runnerVersion, mb_substr($this->db->serverVersion, 0, 100), mb_substr($this->executedBy, 0, 190), $this->db->utcNow(),
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public function journalFor(string $migrationId): array
    {
        if (!$this->db->tableExists(self::JOURNAL_TABLE)) {
            return [];
        }
        return $this->db->rows('SELECT * FROM `' . self::JOURNAL_TABLE . '` WHERE migration_id = ? ORDER BY id', [$migrationId]);
    }
}
