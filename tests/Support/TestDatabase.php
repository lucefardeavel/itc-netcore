<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PDO;
use Reseller\Migration\Db;
use Reseller\Migration\SchemaInspector;

/** A disposable database on the engine selected by WP1_DB_HOST (mariadb | mysql). */
final class TestDatabase
{
    public readonly string $name;
    public readonly string $host;
    private readonly string $password;
    public readonly PDO $pdo;

    public function __construct(string $prefix = 't')
    {
        $this->host = (string) getenv('WP1_DB_HOST');
        $this->password = (string) getenv('WP1_DB_ROOT_PASSWORD');
        if ($this->host === '' || $this->password === '') {
            throw new \RuntimeException('WP1_DB_HOST and WP1_DB_ROOT_PASSWORD must be set (use dev/test/run-tests.sh)');
        }
        $this->name = 'wp1_' . $prefix . '_' . bin2hex(random_bytes(5));
        $admin = FixtureBuilder::connect($this->host, $this->password);
        $admin->exec("CREATE DATABASE `{$this->name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $this->pdo = FixtureBuilder::connect($this->host, $this->password, $this->name);
    }

    public static function rootPassword(): string
    {
        return (string) getenv('WP1_DB_ROOT_PASSWORD');
    }

    public function drop(): void
    {
        FixtureBuilder::connect($this->host, $this->password)->exec("DROP DATABASE IF EXISTS `{$this->name}`");
    }

    public function build(string $variant): array
    {
        $stats = (new FixtureBuilder(dirname(__DIR__, 2)))->build($this->pdo, $variant);
        // the installer switches the session to non-strict NO_AUTO_VALUE_ON_ZERO; new-domain writers
        // use the same strict session mode as the runner
        $this->strictSession();
        return $stats;
    }

    public function strictSession(): void
    {
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET SESSION time_zone = '+00:00'");
        $this->pdo->exec('SET SESSION foreign_key_checks = 1');
    }

    public function db(): Db
    {
        return new Db($this->host, $this->name, 'root', $this->password);
    }

    public function engine(): string
    {
        return $this->db()->engineFamily;
    }

    /** Canonical definition of every table + row counts: used to prove "zero mutation". */
    public function snapshot(): array
    {
        $db = $this->db();
        $inspector = new SchemaInspector($db);
        $snap = [];
        foreach ($db->tableNames() as $t) {
            $snap[$t] = [
                'def' => SchemaInspector::normalise($inspector->table($t)),
                'rows' => (int) $db->value('SELECT COUNT(*) FROM `' . $t . '`'),
            ];
        }
        return $snap;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->db()->tableNames();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /**
     * Inserts a row filling every NOT NULL column without default with a type-appropriate value,
     * so the same seed works on every legacy schema variant.
     */
    public function insertRow(string $table, array $values): int
    {
        $cols = $this->rows(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->name, $table]
        );
        foreach ($cols as $c) {
            $n = $c['COLUMN_NAME'];
            if (array_key_exists($n, $values) || $c['IS_NULLABLE'] === 'YES' || $c['COLUMN_DEFAULT'] !== null || str_contains((string) $c['EXTRA'], 'auto_increment') || str_contains(strtolower((string) $c['EXTRA']), 'generated')) {
                continue;
            }
            $values[$n] = match ($c['DATA_TYPE']) {
                'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double' => 0,
                'date' => '2026-01-01',
                'datetime', 'timestamp' => '2026-01-01 00:00:00',
                'time' => '00:00:00',
                'enum' => preg_match("/^enum\\('([^']*)'/", (string) $c['COLUMN_TYPE'], $m) ? $m[1] : '',
                default => 'x',
            };
        }
        $names = array_keys($values);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn(string $n): string => "`{$n}`", $names)),
            implode(', ', array_fill(0, count($names), '?'))
        );
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($values));
        return (int) $this->pdo->lastInsertId();
    }

    /** SHA-256 over every row of the given columns, ordered by id. */
    public function dataHash(string $table, array $columns): string
    {
        $cols = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $columns));
        $rows = $this->rows("SELECT {$cols} FROM `{$table}` ORDER BY `id`");
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,int> */
    public function ddlCounters(): array
    {
        $out = [];
        foreach ($this->rows("SHOW GLOBAL STATUS WHERE Variable_name IN ('Com_create_table','Com_alter_table','Com_drop_table','Com_create_index','Com_drop_index','Com_rename_table')") as $r) {
            $out[(string) $r['Variable_name']] = (int) $r['Value'];
        }
        return $out;
    }
}
