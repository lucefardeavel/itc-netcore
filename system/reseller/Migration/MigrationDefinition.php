<?php
namespace Reseller\Migration;

// CLI-only: this file must never execute through the web server (the web root is the repo root).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * One project migration, loaded from system/reseller/migrations/RNNNN_name.php.
 * A definition file only returns data; it has no side effects.
 */
final class MigrationDefinition
{
    /**
     * @param list<array{id:string,sql:string}> $up
     * @param list<array{id:string,sql:string,if_exists:array<string,mixed>}> $down
     * @param array{tables:array<string,array<string,mixed>>,table_parts:array<string,array<string,mixed>>,redefines:array<string,array<string,array{from:string,to:string,data_guard:string}>>} $contract
     */
    public function __construct(
        public readonly string $id,
        public readonly int $sequence,
        public readonly string $description,
        public readonly ?string $requires,
        public readonly array $up,
        public readonly array $down,
        public readonly array $contract,
        public readonly string $checksum,
        public readonly string $file,
    ) {
    }

    public static function load(string $file): self
    {
        $bytes = file_get_contents($file);
        if ($bytes === false) {
            throw new StopException(Outcome::USAGE, "cannot read migration file {$file}");
        }
        $def = (static function (string $f) {
            return require $f;
        })($file);
        if (!is_array($def)) {
            throw new StopException(Outcome::USAGE, "migration file {$file} must return an array");
        }
        foreach (['id', 'description', 'requires', 'up', 'down', 'contract'] as $k) {
            if (!array_key_exists($k, $def)) {
                throw new StopException(Outcome::USAGE, "migration file {$file} lacks key '{$k}'");
            }
        }
        $id = (string) $def['id'];
        if (!preg_match('/^R(\d{4})_[a-z0-9_]+$/', $id, $m) || basename($file, '.php') !== $id) {
            throw new StopException(Outcome::USAGE, "migration id '{$id}' must match R<NNNN>_<snake_name> and its file name");
        }
        foreach ($def['up'] as $step) {
            if (!isset($step['id'], $step['sql'])) {
                throw new StopException(Outcome::USAGE, "migration {$id}: every up step needs id and sql");
            }
        }
        foreach ($def['down'] as $step) {
            if (!isset($step['id'], $step['sql'], $step['if_exists'])) {
                throw new StopException(Outcome::USAGE, "migration {$id}: every down step needs id, sql and if_exists");
            }
        }
        if (!is_array($def['contract'])) {
            throw new StopException(Outcome::USAGE, "migration {$id}: contract must be an array");
        }
        $contract = $def['contract'] + ['tables' => [], 'table_parts' => [], 'redefines' => []];
        self::validateRedefinitions($id, $contract);
        return new self(
            $id,
            (int) $m[1],
            (string) $def['description'],
            $def['requires'] === null ? null : (string) $def['requires'],
            array_values($def['up']),
            array_values($def['down']),
            $contract,
            hash('sha256', $bytes),
            $file,
        );
    }

    /**
     * WP5B (R0007, owner-approved): a column of a project table created by an EARLIER migration may be
     * redefined (e.g. an ENUM extended). The primitive is deliberately narrow and fails closed:
     *  - a redefining migration only redefines (no tables, no table_parts);
     *  - every entry is table => column => {from, to, data_guard} with valid identifiers, non-empty and
     *    distinct canonical definitions and nothing else;
     *  - data_guard is one read-only SELECT (no DDL / DML, no locking read, no comment, no second statement)
     *    that returns a row when a rollback would lose data written in the new definition.
     * That the table is a project table created by an earlier migration (never a legacy / core table) and that
     * 'from' is its current contract definition is checked against the whole set by the Runner.
     */
    private static function validateRedefinitions(string $id, array $contract): void
    {
        $redefines = $contract['redefines'];
        if (!is_array($redefines)) {
            throw new StopException(Outcome::USAGE, "migration {$id}: redefines must be an array");
        }
        if ($redefines === []) {
            return;
        }
        if ($contract['tables'] !== [] || $contract['table_parts'] !== []) {
            throw new StopException(Outcome::USAGE, "migration {$id}: a redefining migration must not also create tables or table parts");
        }
        foreach ($redefines as $table => $columns) {
            if (!is_string($table) || !self::identifier($table) || !is_array($columns) || $columns === []) {
                throw new StopException(Outcome::USAGE, "migration {$id}: redefines needs a valid table identifier with at least one column");
            }
            foreach ($columns as $column => $r) {
                $where = "migration {$id}: redefines {$table}." . (is_string($column) ? $column : '?');
                if (!is_string($column) || !self::identifier($column)) {
                    throw new StopException(Outcome::USAGE, "{$where} needs a valid column identifier");
                }
                if (!is_array($r) || array_keys($r) !== ['from', 'to', 'data_guard']) {
                    throw new StopException(Outcome::USAGE, "{$where} needs exactly from, to and data_guard");
                }
                foreach (['from', 'to', 'data_guard'] as $k) {
                    if (!is_string($r[$k]) || trim($r[$k]) === '') {
                        throw new StopException(Outcome::USAGE, "{$where}: {$k} must be a non-empty string");
                    }
                }
                if ($r['from'] === $r['to']) {
                    throw new StopException(Outcome::USAGE, "{$where}: from and to must differ");
                }
                if (!self::readOnlySelect($r['data_guard'])) {
                    throw new StopException(Outcome::USAGE, "{$where}: data_guard must be a single read-only SELECT");
                }
            }
        }
    }

    private static function identifier(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) === 1;
    }

    /** One SELECT statement, no statement separator, comment, DDL / DML / locking or side-effect keyword. */
    public static function readOnlySelect(string $sql): bool
    {
        if (!preg_match('/^SELECT\s/i', $sql) || preg_match('/[;#]|--|\/\*|\\\\/', $sql)) {
            return false;
        }
        $code = preg_replace("/'[^'\\\\]*'/", "''", $sql); // keywords inside plain string literals are data
        return !preg_match('/\b(insert|update|delete|replace|merge|upsert|create|alter|drop|truncate|rename|grant|revoke|lock|unlock|call|load|handler|set|into|outfile|dumpfile|do|execute|prepare|deallocate|flush|kill|shutdown|install|uninstall|sleep|benchmark|get_lock|release_lock|release_all_locks|share|nowait)\b/i', (string) $code);
    }

    /** @return list<string> tables created by this migration */
    public function createdTables(): array
    {
        return array_keys($this->contract['tables']);
    }

    /** @return array<string,array<string,array{from:string,to:string,data_guard:string}>> table => column => redefinition */
    public function redefinitions(): array
    {
        return $this->contract['redefines'];
    }

    /** @return list<string> FK constraint names this migration creates (schema-scoped names) */
    public function foreignKeyNames(): array
    {
        $names = [];
        foreach ($this->contract['tables'] as $t) {
            foreach ($t['foreign_keys'] ?? [] as $fk) {
                $names[] = strtok($fk, ' ');
            }
        }
        foreach ($this->contract['table_parts'] as $p) {
            foreach ($p['foreign_keys'] ?? [] as $fk) {
                $names[] = strtok($fk, ' ');
            }
        }
        return $names;
    }

    /**
     * Removes this migration's objects from a canonical legacy table (used to compare the
     * legacy core contract independently of project-owned additions).
     * @param array<string,mixed>|null $canonical
     * @return array<string,mixed>|null
     */
    public function subtractFrom(string $table, ?array $canonical): ?array
    {
        if ($canonical === null || !isset($this->contract['table_parts'][$table])) {
            return $canonical;
        }
        $part = $this->contract['table_parts'][$table];
        foreach (array_keys($part['columns'] ?? []) as $c) {
            unset($canonical['columns'][$c]);
        }
        $indexNames = array_map(static fn(string $i): string => self::indexName($i), $part['indexes'] ?? []);
        $canonical['indexes'] = array_values(array_filter(
            $canonical['indexes'],
            static fn(string $i): bool => !in_array(self::indexName($i), $indexNames, true)
        ));
        $fkNames = array_map(static fn(string $f): string => (string) strtok($f, ' '), $part['foreign_keys'] ?? []);
        $canonical['foreign_keys'] = array_values(array_filter(
            $canonical['foreign_keys'],
            static fn(string $f): bool => !in_array((string) strtok($f, ' '), $fkNames, true)
        ));
        return $canonical;
    }

    public static function indexName(string $canonicalIndex): string
    {
        if (str_starts_with($canonicalIndex, 'PRIMARY KEY')) {
            return 'PRIMARY';
        }
        $parts = explode(' ', $canonicalIndex);
        return $parts[1] ?? '';
    }
}
