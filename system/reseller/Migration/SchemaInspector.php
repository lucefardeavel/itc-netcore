<?php
namespace Reseller\Migration;

// CLI-only: this file must never execute through the web server (the web root is the repo root).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Reads information_schema and produces an engine-neutral canonical form of a table.
 *
 * Canonical table = [
 *   'engine'       => 'innodb',
 *   'collation'    => 'utf8mb4_general_ci',
 *   'columns'      => [name => canonical column string, ...]   (ordinal order)
 *   'indexes'      => [sorted canonical index strings]
 *   'foreign_keys' => [sorted canonical FK strings]
 *   'checks'       => [sorted CHECK constraint names]
 * ]
 *
 * Normalisation rules (verified on MariaDB 10.11 and MySQL 8.0, see tests):
 *  - integer display width is ignored (MySQL 8.0.19+ does not report it); signedness is kept;
 *  - default values: MariaDB quotes literals and prints NULL / functions unquoted, MySQL prints
 *    raw literals and flags expression defaults with DEFAULT_GENERATED; both are mapped to
 *    `default '<literal>'` or `default <expression>`; "no default" and "DEFAULT NULL" are both
 *    rendered as no default;
 *  - EXTRA: lower-cased, DEFAULT_GENERATED removed, current_timestamp() -> current_timestamp;
 *  - JSON snapshots are stored as LONGTEXT + named CHECK (JSON_VALID(...)), which both engines
 *    report identically; CHECK constraints are compared by name only (expression text differs).
 */
final class SchemaInspector
{
    private const INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];
    private const LENGTH_TYPES = ['char', 'varchar', 'binary', 'varbinary'];
    private const TIME_TYPES = ['datetime', 'timestamp', 'time'];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null canonical table or null if absent */
    public function table(string $table): ?array
    {
        $t = $this->db->rows(
            "SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'",
            [$this->db->database, $table]
        );
        if ($t === []) {
            return null;
        }
        return [
            'engine' => strtolower((string) $t[0]['ENGINE']),
            'collation' => (string) $t[0]['TABLE_COLLATION'],
            'columns' => $this->columns($table),
            'indexes' => $this->indexes($table),
            'foreign_keys' => $this->foreignKeys($table),
            'checks' => $this->checks($table),
        ];
    }

    /** @return array<string,string> */
    public function columns(string $table): array
    {
        $rows = $this->db->rows(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME,
                    CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, DATETIME_PRECISION
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$this->db->database, $table]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['COLUMN_NAME']] = $this->canonicalColumn($r);
        }
        return $out;
    }

    /** @param array<string,mixed> $r */
    public function canonicalColumn(array $r): string
    {
        $dataType = strtolower((string) $r['DATA_TYPE']);
        $columnType = strtolower((string) $r['COLUMN_TYPE']);
        if (in_array($dataType, self::LENGTH_TYPES, true)) {
            $type = $dataType . '(' . $r['CHARACTER_MAXIMUM_LENGTH'] . ')';
        } elseif ($dataType === 'decimal' || $dataType === 'numeric') {
            $type = 'decimal(' . $r['NUMERIC_PRECISION'] . ',' . $r['NUMERIC_SCALE'] . ')';
        } elseif ($dataType === 'enum' || $dataType === 'set') {
            // keep the value list exactly (values are case-sensitive), normalise only the keyword
            $raw = (string) $r['COLUMN_TYPE'];
            $type = $dataType . substr($raw, strlen($dataType));
        } elseif (in_array($dataType, self::TIME_TYPES, true)) {
            $p = (int) ($r['DATETIME_PRECISION'] ?? 0);
            $type = $p > 0 ? "{$dataType}({$p})" : $dataType;
        } elseif ($dataType === 'bit') {
            $type = 'bit(' . $r['NUMERIC_PRECISION'] . ')';
        } else {
            $type = $dataType;
        }
        if (in_array($dataType, self::INTEGER_TYPES, true) || in_array($dataType, ['float', 'double', 'decimal'], true)) {
            if (str_contains($columnType, 'unsigned')) {
                $type .= ' unsigned';
            }
            if (str_contains($columnType, 'zerofill')) {
                $type .= ' zerofill';
            }
        }
        $s = $type;
        if ($r['COLLATION_NAME'] !== null && $r['COLLATION_NAME'] !== '') {
            $s .= ' collate ' . $r['COLLATION_NAME'];
        }
        $s .= $r['IS_NULLABLE'] === 'YES' ? ' null' : ' not null';

        $extraRaw = strtolower((string) ($r['EXTRA'] ?? ''));
        $default = $this->canonicalDefault($r['COLUMN_DEFAULT'], $extraRaw);
        if ($default !== null) {
            $s .= ' default ' . $default;
        }
        $extra = str_replace('default_generated', '', $extraRaw);
        $extra = str_replace('current_timestamp()', 'current_timestamp', $extra);
        $extra = str_replace('persistent generated', 'stored generated', $extra);
        $extra = trim(preg_replace('/\s+/', ' ', $extra) ?? '');
        if ($extra !== '') {
            $s .= ' ' . $extra;
        }
        return $s;
    }

    private function canonicalDefault(mixed $raw, string $extraRaw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = (string) $raw;
        if ($this->db->engineFamily === 'mariadb') {
            if ($raw === 'NULL') {
                return null;
            }
            if (strlen($raw) >= 2 && $raw[0] === "'" && substr($raw, -1) === "'") {
                $lit = str_replace("''", "'", substr($raw, 1, -1));
                return $this->quoteLiteral($lit);
            }
            if (preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
                return $this->quoteLiteral($raw);
            }
            return $this->canonicalExpression($raw);
        }
        // MySQL 8: raw literal text; expression defaults carry DEFAULT_GENERATED in EXTRA
        if (str_contains($extraRaw, 'default_generated')) {
            return $this->canonicalExpression($raw);
        }
        return $this->quoteLiteral($raw);
    }

    private function quoteLiteral(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }

    private function canonicalExpression(string $expr): string
    {
        $e = strtolower(trim($expr));
        return (string) preg_replace('/\(\)$/', '', $e);
    }

    /** @return list<string> */
    public function indexes(string $table): array
    {
        $rows = $this->db->rows(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
              ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->database, $table]
        );
        $idx = [];
        foreach ($rows as $r) {
            $name = (string) $r['INDEX_NAME'];
            $col = (string) $r['COLUMN_NAME'] . ($r['SUB_PART'] !== null ? '(' . $r['SUB_PART'] . ')' : '');
            $idx[$name]['cols'][(int) $r['SEQ_IN_INDEX']] = $col;
            $idx[$name]['unique'] = ((string) $r['NON_UNIQUE']) === '0';
            $idx[$name]['type'] = strtoupper((string) $r['INDEX_TYPE']);
        }
        $out = [];
        foreach ($idx as $name => $i) {
            ksort($i['cols']);
            $cols = implode(',', $i['cols']);
            if ($name === 'PRIMARY') {
                $out[] = "PRIMARY KEY ({$cols})";
            } elseif ($i['type'] === 'FULLTEXT' || $i['type'] === 'SPATIAL') {
                $out[] = "{$i['type']} {$name} ({$cols})";
            } elseif ($i['unique']) {
                $out[] = "UNIQUE {$name} ({$cols})";
            } else {
                $out[] = "INDEX {$name} ({$cols})";
            }
        }
        sort($out);
        return $out;
    }

    /** @return list<string> */
    public function foreignKeys(string $table): array
    {
        $fks = $this->db->rows(
            'SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE
               FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->database, $table]
        );
        $out = [];
        foreach ($fks as $fk) {
            $cols = $this->db->rows(
                'SELECT COLUMN_NAME, REFERENCED_COLUMN_NAME
                   FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
                  ORDER BY ORDINAL_POSITION',
                [$this->db->database, $table, $fk['CONSTRAINT_NAME']]
            );
            $local = implode(',', array_column($cols, 'COLUMN_NAME'));
            $ref = implode(',', array_column($cols, 'REFERENCED_COLUMN_NAME'));
            $out[] = sprintf(
                '%s (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
                $fk['CONSTRAINT_NAME'],
                $local,
                $fk['REFERENCED_TABLE_NAME'],
                $ref,
                strtoupper((string) $fk['DELETE_RULE']),
                strtoupper((string) $fk['UPDATE_RULE'])
            );
        }
        sort($out);
        return $out;
    }

    /** @return list<string> */
    public function checks(string $table): array
    {
        $rows = $this->db->rows(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$this->db->database, $table]
        );
        $out = array_map(static fn(array $r): string => (string) $r['CONSTRAINT_NAME'], $rows);
        sort($out);
        return $out;
    }

    /**
     * Normalises a canonical table into a fixed key order (columns keep ordinal order).
     * @param array<string,mixed>|null $t
     * @return array<string,mixed>|null
     */
    public static function normalise(?array $t): ?array
    {
        if ($t === null) {
            return null;
        }
        $lists = [];
        foreach (['indexes', 'foreign_keys', 'checks'] as $k) {
            $l = array_values($t[$k] ?? []);
            sort($l);
            $lists[$k] = $l;
        }
        return [
            'engine' => $t['engine'] ?? null,
            'collation' => $t['collation'] ?? null,
            'columns' => $t['columns'] ?? [],
            'indexes' => $lists['indexes'],
            'foreign_keys' => $lists['foreign_keys'],
            'checks' => $lists['checks'],
        ];
    }

    /**
     * Stable fingerprint of a set of canonical tables keyed by table name.
     * @param array<string,array<string,mixed>|null> $tables
     */
    public static function fingerprint(array $tables): string
    {
        ksort($tables);
        $norm = [];
        foreach ($tables as $name => $t) {
            $norm[$name] = self::normalise($t);
        }
        return hash('sha256', json_encode($norm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Human-readable differences between two canonical tables (for NOT_READY / DRIFT reports).
     * @return list<string>
     */
    public static function diff(string $table, ?array $expected, ?array $actual): array
    {
        if ($expected === null && $actual === null) {
            return [];
        }
        if ($expected === null) {
            return ["{$table}: unexpected table present"];
        }
        if ($actual === null) {
            return ["{$table}: table missing"];
        }
        $d = [];
        foreach (['engine', 'collation'] as $k) {
            if (($expected[$k] ?? null) !== ($actual[$k] ?? null)) {
                $d[] = "{$table}: {$k} expected '" . ($expected[$k] ?? '') . "' actual '" . ($actual[$k] ?? '') . "'";
            }
        }
        $ec = $expected['columns'] ?? [];
        $ac = $actual['columns'] ?? [];
        foreach ($ec as $c => $def) {
            if (!array_key_exists($c, $ac)) {
                $d[] = "{$table}.{$c}: column missing (expected {$def})";
            } elseif ($ac[$c] !== $def) {
                $d[] = "{$table}.{$c}: expected [{$def}] actual [{$ac[$c]}]";
            }
        }
        foreach ($ac as $c => $def) {
            if (!array_key_exists($c, $ec)) {
                $d[] = "{$table}.{$c}: unexpected column [{$def}]";
            }
        }
        $commonExpected = array_values(array_intersect(array_keys($ec), array_keys($ac)));
        $commonActual = array_values(array_intersect(array_keys($ac), array_keys($ec)));
        if ($commonExpected !== $commonActual) {
            $d[] = "{$table}: column order differs";
        }
        foreach (['indexes', 'foreign_keys', 'checks'] as $k) {
            $e = $expected[$k] ?? [];
            $a = $actual[$k] ?? [];
            foreach (array_diff($e, $a) as $m) {
                $d[] = "{$table}: missing {$k} entry [{$m}]";
            }
            foreach (array_diff($a, $e) as $m) {
                $d[] = "{$table}: unexpected {$k} entry [{$m}]";
            }
        }
        return $d;
    }
}
