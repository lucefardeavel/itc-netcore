<?php
namespace Reseller\Migration;

// CLI-only: this file must never execute through the web server (the web root is the repo root).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Strict project migration runner (owner decision C13 / C28).
 *
 *  - preflight is read-only and runs before any mutation;
 *  - every DDL step is journaled before and after execution; any error stops the run;
 *  - a migration is APPLIED only after all steps and the postflight contract check pass;
 *  - partial states block every later migration until the defined rollback flow is executed;
 *  - rollback removes only objects of the migration being rolled back, refuses when the new
 *    structures hold data, and must restore the recognised pre-migration core fingerprint.
 */
final class Runner
{
    public const VERSION = '1.0.0';
    public const MIN_MARIADB = '10.11.0';
    public const MIN_MYSQL = '8.0.16';

    private SchemaInspector $inspector;
    private Registry $registry;
    /** @var array<string,mixed> */
    private array $report = ['messages' => [], 'problems' => [], 'drift' => []];

    public function __construct(
        private readonly Db $db,
        private readonly MigrationSet $set,
        private readonly VariantRegistry $variants,
        private readonly string $executedBy,
    ) {
        $this->inspector = new SchemaInspector($db);
        $this->registry = new Registry($db, $this->inspector, self::VERSION, $executedBy);
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        return $this->report;
    }

    public function run(string $command, array $opts): int
    {
        $this->report['command'] = $command;
        $this->report['runner_version'] = self::VERSION;
        $this->report['host'] = $this->db->host;
        $this->report['database'] = $this->db->database;
        $this->report['engine'] = $this->db->engineFamily;
        $this->report['server_version'] = $this->db->serverVersion;
        $this->registry->setCommand($command);
        try {
            $this->assertRedefinitionTargets();
            return match ($command) {
                'status' => $this->cmdStatus(),
                'verify' => $this->cmdVerify(),
                'preflight' => $this->cmdPreflight(),
                'migrate' => $this->cmdMigrate(),
                'rollback' => $this->cmdRollback($opts),
                default => throw new StopException(Outcome::USAGE, "unknown command '{$command}'"),
            };
        } catch (StopException $e) {
            $this->report['problems'][] = $e->getMessage();
            foreach ($e->details as $d) {
                $this->report['problems'][] = $d;
            }
            return $e->outcome;
        }
    }

    // ------------------------------------------------------------------ analysis (read-only)

    /**
     * Read-only analysis of the database. Never writes.
     * @return array<string,mixed>
     */
    private function analyse(bool $checkPrivileges): array
    {
        $a = [
            'version_ok' => $this->versionSupported(),
            'privileges_missing' => [],
            'registry' => $this->registry->inspect(),
            'states' => [],
            'present' => [],          // migration ids whose objects may exist (registry says not ROLLED_BACK)
            'blocking' => [],
            'checksum_drift' => [],
            'order_problems' => [],
            'unknown_registry_ids' => [],
            'variant' => null,
            'core_fingerprint_raw' => null,
            'core_diff' => [],
            'anchor_problems' => [],
            'collisions' => [],
            'contract_drift' => [],
            'extra_tables' => [],
            'pending' => [],
        ];
        if ($checkPrivileges) {
            $lines = array_map(static fn(array $r): string => (string) reset($r), $this->db->rows('SHOW GRANTS FOR CURRENT_USER()'));
            $a['privileges_missing'] = Grants::missing($lines, $this->db->database);
        }
        $states = $a['registry']['present'] && $a['registry']['problems'] === [] ? $this->registry->states() : [];
        $a['states'] = $states;
        foreach (array_keys($states) as $id) {
            if ($this->set->get($id) === null) {
                $a['unknown_registry_ids'][] = $id;
            }
        }
        $seenNotApplied = false;
        foreach ($this->set->migrations as $m) {
            $st = $states[$m->id]['status'] ?? null;
            if ($st !== null && $st !== 'ROLLED_BACK') {
                $a['present'][] = $m->id;
                if ($st !== 'APPLIED' && (string) $states[$m->id]['checksum'] !== $m->checksum) {
                    $a['checksum_drift'][] = "{$m->id}: file checksum differs from the checksum recorded for its {$st} state";
                }
                if ($st === 'APPLIED' && (string) $states[$m->id]['checksum'] !== $m->checksum) {
                    $a['checksum_drift'][] = "{$m->id}: applied migration file was modified after it was applied";
                }
                if ($seenNotApplied) {
                    $a['order_problems'][] = "{$m->id} is {$st} although an earlier migration is not APPLIED";
                }
            }
            if (in_array($st, Registry::BLOCKING, true)) {
                $a['blocking'][] = "{$m->id} is {$st}" . ($st === 'APPLYING' ? ' (in progress, or interrupted)' : '')
                    . ' after ' . ($states[$m->id]['steps_completed'] ?? 0) . '/' . ($states[$m->id]['steps_total'] ?? count($m->up)) . ' steps';
            }
            if ($st !== 'APPLIED') {
                $seenNotApplied = true;
            }
            if ($st === null || $st === 'ROLLED_BACK') {
                $a['pending'][] = $m->id;
            }
        }

        // core contract (legacy tables WP1 depends on), with project-owned additions subtracted
        $raw = [];
        foreach (VariantRegistry::CORE_TABLES as $t) {
            $raw[$t] = $this->inspector->table($t);
        }
        $a['core_fingerprint_raw'] = SchemaInspector::fingerprint($raw);
        $core = $this->subtractPresent($raw, $a['present']);
        $a['anchor_problems'] = $this->anchorProblems($core);
        $match = $this->variants->match($this->db->engineFamily, $core);
        $a['variant'] = $match['id'];
        $a['core_diff'] = $match['id'] === null ? array_merge(["nearest registered variant: " . ($match['nearest'] ?? 'none')], $match['diff']) : [];

        // collisions: objects of not-present migrations must not exist
        foreach ($this->set->migrations as $m) {
            if (in_array($m->id, $a['present'], true)) {
                continue;
            }
            foreach ($m->createdTables() as $t) {
                if ($this->db->tableExists($t)) {
                    $a['collisions'][] = "table {$t} exists but migration {$m->id} is not applied";
                }
            }
            foreach ($m->contract['table_parts'] as $t => $part) {
                foreach (array_keys($part['columns'] ?? []) as $c) {
                    if ($this->db->columnExists($t, $c)) {
                        $a['collisions'][] = "column {$t}.{$c} exists but migration {$m->id} is not applied";
                    }
                }
                foreach ($part['indexes'] ?? [] as $i) {
                    $name = MigrationDefinition::indexName($i);
                    if ($this->indexExists($t, $name)) {
                        $a['collisions'][] = "index {$t}.{$name} exists but migration {$m->id} is not applied";
                    }
                }
            }
            foreach ($m->foreignKeyNames() as $fk) {
                if ($this->db->foreignKeyNameExistsAnywhere($fk)) {
                    $a['collisions'][] = "foreign key name {$fk} already used but migration {$m->id} is not applied";
                }
            }
            foreach ($m->redefinitions() as $t => $columns) {
                foreach ($columns as $c => $r) {
                    $have = $this->columnDefinition($t, $c);
                    if ($have === null) {
                        continue; // the table (created by an earlier migration) is absent: nothing to collide with
                    }
                    if ($have === $r['to']) {
                        $a['collisions'][] = "column {$t}.{$c} already has the definition of migration {$m->id}, which is not applied";
                    } elseif ($have !== $r['from']) {
                        $a['collisions'][] = "column {$t}.{$c} has an unexpected definition [{$have}] for migration {$m->id}";
                    }
                }
            }
        }

        // contracts of APPLIED migrations (drift detection)
        foreach ($this->set->migrations as $m) {
            if (($states[$m->id]['status'] ?? null) === 'APPLIED') {
                foreach ($this->contractDiff($m) as $d) {
                    $a['contract_drift'][] = "{$m->id}: {$d}";
                }
            }
        }

        $known = array_merge(VariantRegistry::CORE_TABLES, [Registry::STATE_TABLE, Registry::JOURNAL_TABLE]);
        foreach ($this->set->migrations as $m) {
            $known = array_merge($known, $m->createdTables());
        }
        $a['extra_tables'] = array_values(array_diff($this->db->tableNames(), $known));
        return $a;
    }

    /** @param array<string,mixed> $a */
    private function reportAnalysis(array $a): void
    {
        $this->report['variant'] = $a['variant'];
        $this->report['core_fingerprint'] = $a['core_fingerprint_raw'];
        $this->report['extra_tables'] = $a['extra_tables'];
        $this->report['migrations'] = [];
        foreach ($this->set->migrations as $m) {
            $st = $a['states'][$m->id] ?? null;
            $this->report['migrations'][] = [
                'id' => $m->id,
                'status' => $st['status'] ?? 'PENDING',
                'steps_completed' => isset($st['steps_completed']) ? (int) $st['steps_completed'] : 0,
                'steps_total' => count($m->up),
                'checksum' => $m->checksum,
                'recorded_checksum' => $st['checksum'] ?? null,
            ];
        }
        $this->report['blocking'] = $a['blocking'];
        $this->report['pending'] = $a['pending'];
    }

    /**
     * Decides the outcome of the read-only analysis.
     * @param array<string,mixed> $a
     */
    private function analysisOutcome(array $a, bool $forMigrate): int
    {
        $notReady = [];
        if (!$a['version_ok']) {
            $notReady[] = 'unsupported database server ' . $this->db->serverVersion . ' (requires MariaDB >= ' . self::MIN_MARIADB . ' or MySQL >= ' . self::MIN_MYSQL . ')';
        }
        if ($a['privileges_missing'] !== []) {
            $notReady[] = 'missing privileges on ' . $this->db->database . ': ' . implode(', ', $a['privileges_missing']);
        }
        foreach ($a['registry']['problems'] as $p) {
            $notReady[] = 'migration registry does not match its contract: ' . $p;
        }
        if ($a['registry']['present'] && !$a['registry']['complete'] && $a['registry']['problems'] === []) {
            // half-created registry (only possible if the very first bootstrap was interrupted)
            if (!$forMigrate) {
                $notReady[] = 'migration registry is incomplete (bootstrap interrupted); run migrate to complete it';
            }
        }
        foreach ($a['anchor_problems'] as $p) {
            $notReady[] = 'core anchor: ' . $p;
        }
        if ($a['variant'] === null) {
            $notReady[] = 'core schema does not match any explicitly verified baseline variant';
            foreach ($a['core_diff'] as $d) {
                $notReady[] = '  ' . $d;
            }
        }
        foreach ($a['collisions'] as $c) {
            $notReady[] = 'collision: ' . $c;
        }
        foreach ($a['unknown_registry_ids'] as $id) {
            $notReady[] = "registry contains unknown migration {$id}";
        }
        foreach ($a['order_problems'] as $p) {
            $notReady[] = 'order: ' . $p;
        }
        foreach ($a['blocking'] as $b) {
            $notReady[] = 'partial migration state: ' . $b . ' — only `rollback` may continue';
        }
        $drift = array_merge($a['checksum_drift'], $a['contract_drift']);
        $this->report['drift'] = array_merge($this->report['drift'], $drift);
        $this->report['problems'] = array_merge($this->report['problems'], $notReady);
        if ($drift !== []) {
            return Outcome::DRIFT;
        }
        return $notReady === [] ? Outcome::OK : Outcome::NOT_READY;
    }

    // ------------------------------------------------------------------ commands

    private function cmdStatus(): int
    {
        $a = $this->analyse(false);
        $this->reportAnalysis($a);
        return $this->analysisOutcome($a, false);
    }

    private function cmdVerify(): int
    {
        $a = $this->analyse(false);
        $this->reportAnalysis($a);
        $outcome = $this->analysisOutcome($a, false);
        if ($outcome === Outcome::OK) {
            $applied = array_filter($this->report['migrations'], static fn(array $m): bool => $m['status'] === 'APPLIED');
            $this->report['messages'][] = count($applied) . ' applied migration(s) verified against their contracts';
        }
        return $outcome;
    }

    private function cmdPreflight(): int
    {
        $a = $this->analyse(true);
        $this->reportAnalysis($a);
        $outcome = $this->analysisOutcome($a, true);
        if ($outcome === Outcome::OK) {
            $this->report['messages'][] = 'preflight passed: variant ' . $a['variant'] . ', ' . count($a['pending']) . ' pending migration(s)';
        }
        return $outcome;
    }

    private function cmdMigrate(): int
    {
        $this->acquireLock();
        try {
            $this->convertInterruptedRuns();
            $a = $this->analyse(true);
            $this->reportAnalysis($a);
            $outcome = $this->analysisOutcome($a, true);
            if ($outcome !== Outcome::OK) {
                return $outcome;
            }
            if ($a['pending'] === []) {
                $this->report['messages'][] = 'nothing to do: all migrations are applied and verified';
                return Outcome::OK;
            }
            $this->registry->bootstrap();
            $this->registry->journal('RUN_STARTED', null, null, null, null, 'migrate; pending: ' . implode(',', $a['pending']));
            $this->registry->journal('PREFLIGHT_PASSED', null, null, null, null, json_encode([
                'variant' => $a['variant'],
                'core_fingerprint' => $a['core_fingerprint_raw'],
                'extra_tables' => $a['extra_tables'],
            ], JSON_UNESCAPED_SLASHES));
            $present = $a['present'];
            foreach ($this->set->migrations as $m) {
                if (!in_array($m->id, $a['pending'], true)) {
                    continue;
                }
                $this->applyMigration($m, (string) $a['variant'], $present);
                $present[] = $m->id;
            }
            $this->registry->journal('RUN_FINISHED', null, null, null, null, 'migrate completed');
            $this->report['messages'][] = 'applied: ' . implode(', ', $a['pending']);
            $after = $this->analyse(false);
            $this->reportAnalysis($after);
            return $this->analysisOutcome($after, false);
        } finally {
            $this->releaseLock();
        }
    }

    /** @param list<string> $presentBefore */
    private function applyMigration(MigrationDefinition $m, string $variant, array $presentBefore): void
    {
        $prior = $this->registry->states()[$m->id]['status'] ?? null;
        $rawCore = [];
        foreach (VariantRegistry::CORE_TABLES as $t) {
            $rawCore[$t] = $this->inspector->table($t);
        }
        $this->registry->changeState($m->id, $prior, 'APPLYING', [
            'checksum' => $m->checksum,
            'steps_total' => count($m->up),
            'steps_completed' => 0,
            'base_variant' => $variant,
            'core_fingerprint_before' => SchemaInspector::fingerprint($rawCore),
            'contract_fingerprint' => null,
            'last_error' => null,
            'applied_at' => null,
        ]);
        foreach ($m->up as $i => $step) {
            $no = $i + 1;
            $sha = hash('sha256', $step['sql']);
            $this->registry->journal('STEP_STARTED', $m->id, 'UP', $no, $step['id'], null, null, null, $sha);
            try {
                $this->db->exec($this->testHookSql('UP', $m->id, $no) ?? $step['sql']);
            } catch (\Throwable $e) {
                $msg = self::sanitize($e->getMessage());
                $this->registry->journal('STEP_FAILED', $m->id, 'UP', $no, $step['id'], $msg, null, null, $sha);
                $this->registry->changeState($m->id, 'APPLYING', 'FAILED_PARTIAL', ['last_error' => "step {$no} ({$step['id']}): {$msg}"]);
                $this->registry->journal('RUN_ABORTED', $m->id, 'UP', $no, $step['id'], 'stopped after step failure');
                throw new StopException(Outcome::FAILED, "{$m->id} step {$no} ({$step['id']}) failed: {$msg}", ["{$m->id} is FAILED_PARTIAL after " . ($no - 1) . '/' . count($m->up) . ' steps; run `rollback` before any further migrate']);
            }
            $this->testHookCrash('UP', $m->id, $no);
            $this->registry->journal('STEP_COMPLETED', $m->id, 'UP', $no, $step['id'], null, null, null, $sha);
            $this->registry->upsertState($m->id, ['steps_completed' => $no]);
        }
        // postflight: contract of created objects + legacy core still the same recognised variant
        $diff = $this->contractDiff($m);
        $raw = [];
        foreach (VariantRegistry::CORE_TABLES as $t) {
            $raw[$t] = $this->inspector->table($t);
        }
        $core = $this->subtractPresent($raw, [...$presentBefore, $m->id]);
        $match = $this->variants->match($this->db->engineFamily, $core);
        if ($match['id'] !== $variant) {
            $diff[] = 'legacy core schema no longer matches variant ' . $variant;
            $diff = array_merge($diff, $match['diff']);
        }
        if ($diff !== []) {
            $detail = implode("\n", $diff);
            $this->registry->journal('POSTFLIGHT_FAILED', $m->id, 'UP', null, null, $detail);
            $this->registry->changeState($m->id, 'APPLYING', 'FAILED_PARTIAL', ['last_error' => 'postflight: ' . mb_substr($detail, 0, 2000)]);
            $this->registry->journal('RUN_ABORTED', $m->id, 'UP', null, null, 'stopped after postflight failure');
            throw new StopException(Outcome::FAILED, "{$m->id} postflight contract check failed", $diff);
        }
        $this->registry->journal('POSTFLIGHT_PASSED', $m->id, 'UP', null, null, 'contract verified');
        $this->registry->changeState($m->id, 'APPLYING', 'APPLIED', [
            'contract_fingerprint' => $this->contractFingerprint($m),
            'applied_at' => $this->db->utcNow(),
            'last_error' => null,
        ]);
    }

    private function cmdRollback(array $opts): int
    {
        $target = $opts['to'] ?? null;
        $all = !empty($opts['all']);
        if (($target === null) === !$all) {
            throw new StopException(Outcome::USAGE, 'rollback requires exactly one of --to=<migration id> or --all');
        }
        if ($target !== null && $this->set->get($target) === null) {
            throw new StopException(Outcome::USAGE, "unknown migration '{$target}'");
        }
        $this->acquireLock();
        try {
            $this->convertInterruptedRuns();
            $a = $this->analyse(true);
            $this->reportAnalysis($a);
            // rollback is the defined recovery path: blocking partial states are expected here,
            // everything else must be sound
            $problems = [];
            if (!$a['version_ok']) {
                $problems[] = 'unsupported database server ' . $this->db->serverVersion;
            }
            if ($a['privileges_missing'] !== []) {
                $problems[] = 'missing privileges: ' . implode(', ', $a['privileges_missing']);
            }
            foreach ($a['registry']['problems'] as $p) {
                $problems[] = 'migration registry does not match its contract: ' . $p;
            }
            if (!$a['registry']['complete']) {
                $problems[] = 'no complete migration registry: nothing was migrated by this runner';
            }
            foreach ($a['unknown_registry_ids'] as $id) {
                $problems[] = "registry contains unknown migration {$id}";
            }
            if ($a['variant'] === null) {
                $problems[] = 'legacy core schema (excluding project-owned objects) does not match a recognised variant; the pre-migration schema cannot be restored safely';
                $problems = array_merge($problems, $a['core_diff']);
            }
            foreach ($a['anchor_problems'] as $p) {
                $problems[] = 'core anchor: ' . $p;
            }
            if ($a['checksum_drift'] !== []) {
                $this->report['drift'] = array_merge($this->report['drift'], $a['checksum_drift']);
                throw new StopException(Outcome::DRIFT, 'migration files differ from the versions recorded in the registry; refusing rollback');
            }
            if ($problems !== []) {
                throw new StopException(Outcome::NOT_READY, 'rollback preflight failed', $problems);
            }
            $targetSeq = $target === null ? 0 : $this->set->get($target)->sequence;
            $toRollBack = [];
            foreach (array_reverse($this->set->migrations) as $m) {
                if ($m->sequence > $targetSeq && in_array($m->id, $a['present'], true)) {
                    $toRollBack[] = $m;
                }
            }
            if ($toRollBack === []) {
                $this->report['messages'][] = 'nothing to roll back';
                return Outcome::OK;
            }
            // drift + data guard before any mutation
            $drift = [];
            $data = [];
            foreach ($toRollBack as $m) {
                foreach ($this->existingObjectDrift($m) as $d) {
                    $drift[] = "{$m->id}: {$d}";
                }
                foreach ($this->dataPresent($m) as $d) {
                    $data[] = "{$m->id}: {$d}";
                }
            }
            if ($drift !== []) {
                $this->report['drift'] = array_merge($this->report['drift'], $drift);
                throw new StopException(Outcome::DRIFT, 'project objects differ from their contracts; refusing to drop them');
            }
            if ($data !== []) {
                throw new StopException(Outcome::NOT_READY, 'rollback refused: new-domain structures contain data (restore from backup instead)', $data);
            }
            $this->registry->journal('RUN_STARTED', null, null, null, null, 'rollback ' . ($all ? '--all' : "--to={$target}"));
            $present = $a['present'];
            foreach ($toRollBack as $m) {
                $this->rollbackMigration($m, (string) ($a['states'][$m->id]['status'] ?? ''));
                $present = array_values(array_diff($present, [$m->id]));
            }
            // the legacy core must still be the recognised variant; after a full rollback it must
            // equal the fingerprint recorded before the first migration
            $raw = [];
            foreach (VariantRegistry::CORE_TABLES as $t) {
                $raw[$t] = $this->inspector->table($t);
            }
            $match = $this->variants->match($this->db->engineFamily, $this->subtractPresent($raw, $present));
            if ($match['id'] !== $a['variant']) {
                $this->registry->journal('RUN_ABORTED', null, null, null, null, 'core schema not restored to the recognised variant');
                throw new StopException(Outcome::DRIFT, 'after rollback the legacy core schema does not match variant ' . $a['variant'], $match['diff']);
            }
            if ($present === []) {
                $first = $this->set->migrations[0]->id;
                $expected = (string) ($this->registry->states()[$first]['core_fingerprint_before'] ?? '');
                $actual = SchemaInspector::fingerprint($raw);
                if ($expected !== $actual) {
                    $this->registry->journal('RUN_ABORTED', null, null, null, null, 'core fingerprint differs from pre-migration fingerprint');
                    throw new StopException(Outcome::DRIFT, "after full rollback the core fingerprint {$actual} differs from the pre-migration fingerprint {$expected}");
                }
                $this->report['messages'][] = "pre-migration core fingerprint restored: {$actual}";
            }
            $this->registry->journal('RUN_FINISHED', null, null, null, null, 'rollback completed');
            $this->report['messages'][] = 'rolled back: ' . implode(', ', array_map(static fn(MigrationDefinition $m): string => $m->id, $toRollBack));
            $after = $this->analyse(false);
            $this->reportAnalysis($after);
            return Outcome::OK;
        } finally {
            $this->releaseLock();
        }
    }

    private function rollbackMigration(MigrationDefinition $m, string $from): void
    {
        $this->registry->changeState($m->id, $from, 'ROLLING_BACK');
        foreach ($m->down as $i => $step) {
            $no = $i + 1;
            $sha = hash('sha256', $step['sql']);
            if (!$this->objectExists($step['if_exists'])) {
                $this->registry->journal('STEP_SKIPPED_ABSENT', $m->id, 'DOWN', $no, $step['id'], 'object absent: ' . json_encode($step['if_exists']), null, null, $sha);
                continue;
            }
            $this->registry->journal('STEP_STARTED', $m->id, 'DOWN', $no, $step['id'], null, null, null, $sha);
            try {
                $this->db->exec($this->testHookSql('DOWN', $m->id, $no) ?? $step['sql']);
            } catch (\Throwable $e) {
                $msg = self::sanitize($e->getMessage());
                $this->registry->journal('STEP_FAILED', $m->id, 'DOWN', $no, $step['id'], $msg, null, null, $sha);
                $this->registry->changeState($m->id, 'ROLLING_BACK', 'ROLLBACK_FAILED', ['last_error' => "down step {$no} ({$step['id']}): {$msg}"]);
                $this->registry->journal('RUN_ABORTED', $m->id, 'DOWN', $no, $step['id'], 'stopped after rollback step failure');
                throw new StopException(Outcome::FAILED, "rollback of {$m->id} failed at down step {$no} ({$step['id']}): {$msg}");
            }
            $this->registry->journal('STEP_COMPLETED', $m->id, 'DOWN', $no, $step['id'], null, null, null, $sha);
        }
        // every object of the migration must now be absent
        $left = $this->remainingObjects($m);
        if ($left !== []) {
            $this->registry->changeState($m->id, 'ROLLING_BACK', 'ROLLBACK_FAILED', ['last_error' => 'objects still present: ' . implode(', ', $left)]);
            throw new StopException(Outcome::FAILED, "rollback of {$m->id} left objects behind", $left);
        }
        $this->registry->changeState($m->id, 'ROLLING_BACK', 'ROLLED_BACK', ['steps_completed' => 0, 'contract_fingerprint' => null, 'applied_at' => null]);
    }

    // ------------------------------------------------------------------ helpers

    private function versionSupported(): bool
    {
        $min = $this->db->engineFamily === 'mariadb' ? self::MIN_MARIADB : self::MIN_MYSQL;
        return version_compare($this->db->numericVersion, $min, '>=');
    }

    /**
     * @param array<string,array<string,mixed>|null> $raw
     * @param list<string> $presentIds
     * @return array<string,array<string,mixed>|null>
     */
    private function subtractPresent(array $raw, array $presentIds): array
    {
        foreach ($presentIds as $id) {
            $m = $this->set->get($id);
            if ($m === null) {
                continue;
            }
            foreach ($raw as $t => $canon) {
                $raw[$t] = $m->subtractFrom($t, $canon);
            }
        }
        return $raw;
    }

    /**
     * Explicit checks of the anchors every WP1 foreign key relies on (clear messages even when the
     * fingerprint already fails).
     * @param array<string,array<string,mixed>|null> $core
     * @return list<string>
     */
    private function anchorProblems(array $core): array
    {
        $expect = [
            'tbl_users' => 'int unsigned not null auto_increment',
            'tbl_customers' => 'int not null auto_increment',
            'tbl_plans' => 'int not null auto_increment',
        ];
        $p = [];
        foreach ($expect as $t => $idDef) {
            $c = $core[$t] ?? null;
            if ($c === null) {
                $p[] = "{$t} is missing";
                continue;
            }
            if ($c['engine'] !== 'innodb') {
                $p[] = "{$t} engine is {$c['engine']} (InnoDB required)";
            }
            if (!str_starts_with((string) $c['collation'], 'utf8mb4_')) {
                $p[] = "{$t} collation is {$c['collation']} (utf8mb4 required)";
            }
            if (($c['columns']['id'] ?? null) !== $idDef) {
                $p[] = "{$t}.id is [" . ($c['columns']['id'] ?? 'missing') . "] (expected [{$idDef}])";
            }
            if (!in_array('PRIMARY KEY (id)', $c['indexes'], true)) {
                $p[] = "{$t} primary key is not (id)";
            }
        }
        return $p;
    }

    /** @return list<string> */
    private function contractDiff(MigrationDefinition $m): array
    {
        $d = [];
        foreach ($m->contract['tables'] as $t => $expected) {
            $actual = $this->inspector->table($t);
            $d = array_merge($d, SchemaInspector::diff($t, SchemaInspector::normalise($this->withLaterRedefinitions($m, $t, $expected, $actual)), SchemaInspector::normalise($actual)));
        }
        foreach ($m->redefinitions() as $t => $columns) {
            foreach ($columns as $c => $r) {
                $have = $this->columnDefinition($t, $c);
                if ($have !== $r['to']) {
                    $d[] = "{$t}.{$c}: expected [{$r['to']}] actual [" . ($have ?? 'missing') . ']';
                }
            }
        }
        foreach ($m->contract['table_parts'] as $t => $part) {
            $actual = $this->inspector->table($t);
            if ($actual === null) {
                $d[] = "{$t}: table missing";
                continue;
            }
            foreach ($part['columns'] ?? [] as $c => $def) {
                $have = $actual['columns'][$c] ?? null;
                if ($have !== $def) {
                    $d[] = "{$t}.{$c}: expected [{$def}] actual [" . ($have ?? 'missing') . ']';
                }
            }
            foreach (['indexes', 'foreign_keys', 'checks'] as $k) {
                foreach ($part[$k] ?? [] as $entry) {
                    if (!in_array($entry, $actual[$k], true)) {
                        $d[] = "{$t}: missing {$k} entry [{$entry}]";
                    }
                }
            }
        }
        return $d;
    }

    private function contractFingerprint(MigrationDefinition $m): string
    {
        $parts = [];
        foreach ($m->contract['table_parts'] as $t => $p) {
            $parts[$t . '#part'] = SchemaInspector::normalise($p + ['engine' => null, 'collation' => null]);
        }
        foreach ($m->redefinitions() as $t => $columns) {
            ksort($columns);
            $parts[$t . '#redefines'] = array_map(static fn(array $r): array => ['from' => $r['from'], 'to' => $r['to']], $columns);
        }
        return SchemaInspector::fingerprint($m->contract['tables'] + $parts);
    }

    /**
     * For rollback: objects of this migration that exist must still match the contract.
     * @return list<string>
     */
    private function existingObjectDrift(MigrationDefinition $m): array
    {
        $d = [];
        foreach ($m->contract['tables'] as $t => $expected) {
            $actual = $this->inspector->table($t);
            if ($actual !== null) {
                $d = array_merge($d, SchemaInspector::diff($t, SchemaInspector::normalise($this->withLaterRedefinitions($m, $t, $expected, $actual)), SchemaInspector::normalise($actual)));
            }
        }
        foreach ($m->redefinitions() as $t => $columns) {
            foreach ($columns as $c => $r) {
                $have = $this->columnDefinition($t, $c);
                if ($have !== null && $have !== $r['to'] && $have !== $r['from']) {
                    $d[] = "{$t}.{$c}: expected [{$r['to']}] or [{$r['from']}] actual [{$have}]";
                }
            }
        }
        foreach ($m->contract['table_parts'] as $t => $part) {
            $actual = $this->inspector->table($t);
            if ($actual === null) {
                continue;
            }
            foreach ($part['columns'] ?? [] as $c => $def) {
                if (isset($actual['columns'][$c]) && $actual['columns'][$c] !== $def) {
                    $d[] = "{$t}.{$c}: expected [{$def}] actual [{$actual['columns'][$c]}]";
                }
            }
        }
        return $d;
    }

    /** @return list<string> */
    private function dataPresent(MigrationDefinition $m): array
    {
        $d = [];
        foreach ($m->createdTables() as $t) {
            if ($this->db->tableExists($t) && $this->db->value('SELECT 1 FROM ' . $this->db->quoteIdent($t) . ' LIMIT 1') !== null) {
                $d[] = "table {$t} contains rows";
            }
        }
        foreach ($m->contract['table_parts'] as $t => $part) {
            foreach (array_keys($part['columns'] ?? []) as $c) {
                if ($this->db->columnExists($t, $c)
                    && $this->db->value('SELECT 1 FROM ' . $this->db->quoteIdent($t) . ' WHERE ' . $this->db->quoteIdent($c) . ' IS NOT NULL LIMIT 1') !== null) {
                    $d[] = "column {$t}.{$c} contains non-NULL values";
                }
            }
        }
        foreach ($m->redefinitions() as $t => $columns) {
            foreach ($columns as $c => $r) {
                if ($this->columnDefinition($t, $c) === $r['to'] && $this->db->value($r['data_guard']) !== null) {
                    $d[] = "column {$t}.{$c} contains values of the redefined definition";
                }
            }
        }
        return $d;
    }

    /** @return list<string> */
    private function remainingObjects(MigrationDefinition $m): array
    {
        $left = [];
        foreach ($m->createdTables() as $t) {
            if ($this->db->tableExists($t)) {
                $left[] = "table {$t}";
            }
        }
        foreach ($m->contract['table_parts'] as $t => $part) {
            foreach (array_keys($part['columns'] ?? []) as $c) {
                if ($this->db->columnExists($t, $c)) {
                    $left[] = "column {$t}.{$c}";
                }
            }
            foreach ($part['indexes'] ?? [] as $i) {
                $n = MigrationDefinition::indexName($i);
                if ($this->indexExists($t, $n)) {
                    $left[] = "index {$t}.{$n}";
                }
            }
        }
        foreach ($m->foreignKeyNames() as $fk) {
            if ($this->db->foreignKeyNameExistsAnywhere($fk)) {
                $left[] = "foreign key {$fk}";
            }
        }
        foreach ($m->redefinitions() as $t => $columns) {
            foreach ($columns as $c => $r) {
                $have = $this->columnDefinition($t, $c);
                if ($have !== null && $have !== $r['from']) {
                    $left[] = "column definition {$t}.{$c}";
                }
            }
        }
        return $left;
    }

    /** @param array<string,mixed> $spec */
    private function objectExists(array $spec): bool
    {
        if (isset($spec['table'])) {
            return $this->db->tableExists((string) $spec['table']);
        }
        if (isset($spec['column'])) {
            return $this->db->columnExists((string) $spec['column'][0], (string) $spec['column'][1]);
        }
        if (isset($spec['foreign_key'])) {
            return $this->db->foreignKeyExists((string) $spec['foreign_key'][0], (string) $spec['foreign_key'][1]);
        }
        if (isset($spec['index'])) {
            return $this->indexExists((string) $spec['index'][0], (string) $spec['index'][1]);
        }
        if (isset($spec['column_definition'])) {
            return $this->columnDefinition((string) $spec['column_definition'][0], (string) $spec['column_definition'][1]) === (string) $spec['column_definition'][2];
        }
        throw new StopException(Outcome::USAGE, 'invalid if_exists specification ' . json_encode($spec));
    }

    /**
     * Set-level check of the redefines primitive (WP5B, R0007), before any command touches the database:
     * a redefinition may only target a column of a PROJECT table created ('tables' contract) by an earlier
     * migration, never a legacy / core table, and its 'from' must be that column's definition as established
     * by the earlier contracts (the creating migration, or a still earlier redefinition).
     */
    private function assertRedefinitionTargets(): void
    {
        $columns = [];
        foreach ($this->set->migrations as $m) {
            foreach ($m->redefinitions() as $t => $redefined) {
                if (in_array($t, VariantRegistry::CORE_TABLES, true) || !isset($columns[$t])) {
                    throw new StopException(Outcome::USAGE, "migration {$m->id}: redefines may only target a project table created by an earlier migration ({$t})");
                }
                foreach ($redefined as $c => $r) {
                    if (($columns[$t][$c] ?? null) !== $r['from']) {
                        throw new StopException(Outcome::USAGE, "migration {$m->id}: redefines {$t}.{$c} 'from' is not the column's current contract definition");
                    }
                    $columns[$t][$c] = $r['to'];
                }
            }
            foreach ($m->contract['tables'] as $t => $def) {
                $columns[$t] = $def['columns'] ?? [];
            }
        }
    }

    /** The canonical definition of one column (SchemaInspector format), or null when table or column is absent. */
    private function columnDefinition(string $table, string $column): ?string
    {
        $t = $this->inspector->table($table);
        return $t['columns'][$column] ?? null;
    }

    /**
     * The contract of a table created by $m, with the columns that a LATER migration redefined replaced by the
     * definition that migration establishes (WP5B, R0007): APPLIED -> its 'to'; present but not APPLIED (a run in
     * progress, failed or rolling back) -> whichever of 'from' / 'to' is actually there, anything else stays drift.
     *
     * @param array<string,mixed> $expected
     * @param array<string,mixed>|null $actual
     * @return array<string,mixed>
     */
    private function withLaterRedefinitions(MigrationDefinition $m, string $table, array $expected, ?array $actual): array
    {
        $states = null;
        foreach ($this->set->migrations as $later) {
            if ($later->sequence <= $m->sequence || !isset($later->redefinitions()[$table])) {
                continue;
            }
            $states ??= $this->registry->states();
            $status = $states[$later->id]['status'] ?? null;
            if ($status === null || $status === 'ROLLED_BACK') {
                continue;
            }
            foreach ($later->redefinitions()[$table] as $c => $r) {
                $have = $actual['columns'][$c] ?? null;
                $expected['columns'][$c] = $status !== 'APPLIED' && ($have === $r['from'] || $have === $r['to']) ? $have : $r['to'];
            }
        }
        return $expected;
    }

    private function indexExists(string $table, string $name): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->database, $table, $name]
        ) > 0;
    }

    /** Under the lock, an APPLYING state can only belong to a run that died: record ROLLBACK_REQUIRED. */
    private function convertInterruptedRuns(): void
    {
        $reg = $this->registry->inspect();
        if (!$reg['complete'] || $reg['problems'] !== []) {
            return;
        }
        foreach ($this->registry->states() as $id => $row) {
            if ($row['status'] === 'APPLYING') {
                $this->registry->changeState($id, 'APPLYING', 'ROLLBACK_REQUIRED', [], 'interrupted forward run detected (no runner holds the lock)');
            } elseif ($row['status'] === 'ROLLING_BACK') {
                $this->registry->changeState($id, 'ROLLING_BACK', 'ROLLBACK_FAILED', [], 'interrupted rollback detected (no runner holds the lock)');
            }
        }
    }

    private function lockName(): string
    {
        return 'nuxr_mig_' . substr(sha1($this->db->database), 0, 20);
    }

    private function acquireLock(): void
    {
        $got = $this->db->value('SELECT GET_LOCK(?, 0)', [$this->lockName()]);
        if ((string) $got !== '1') {
            throw new StopException(Outcome::NOT_READY, 'another migration runner holds the lock for database ' . $this->db->database);
        }
    }

    private function releaseLock(): void
    {
        try {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
        } catch (\Throwable) {
            // the lock is released with the connection anyway
        }
    }

    public static function sanitize(string $message): string
    {
        $m = preg_replace('/\s+/', ' ', $message) ?? $message;
        return mb_substr($m, 0, 1000);
    }

    // ------------------------------------------------------------------ test hooks (inert unless enabled)

    /**
     * Test-only failure injection. Active only when NUXR_MIGRATION_TESTING=1 AND
     * NUXR_MIGRATION_TEST_FAIL="<UP|DOWN>:<migration id>:<step no>". Replaces that step's SQL with a
     * statement that the server rejects, so the real error path is exercised. It can only cause a
     * failure; it cannot skip or weaken any check.
     */
    private function testHookSql(string $direction, string $id, int $step): ?string
    {
        if (getenv('NUXR_MIGRATION_TESTING') !== '1') {
            return null;
        }
        if (getenv('NUXR_MIGRATION_TEST_FAIL') === "{$direction}:{$id}:{$step}") {
            return 'SELECT * FROM `__nuxr_injected_failure_table_does_not_exist__`';
        }
        return null;
    }

    /**
     * Test-only crash injection (NUXR_MIGRATION_TEST_CRASH="<UP|DOWN>:<id>:<step>"): terminates the
     * process right after the step's DDL succeeded and before its completion is journaled, exactly
     * like a killed process.
     */
    private function testHookCrash(string $direction, string $id, int $step): void
    {
        if (getenv('NUXR_MIGRATION_TESTING') === '1' && getenv('NUXR_MIGRATION_TEST_CRASH') === "{$direction}:{$id}:{$step}") {
            exit(9);
        }
    }
}
