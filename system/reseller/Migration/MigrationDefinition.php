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
     * @param array{tables:array<string,array<string,mixed>>,table_parts:array<string,array<string,mixed>>} $contract
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
        $contract = $def['contract'] + ['tables' => [], 'table_parts' => []];
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

    /** @return list<string> tables created by this migration */
    public function createdTables(): array
    {
        return array_keys($this->contract['tables']);
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
