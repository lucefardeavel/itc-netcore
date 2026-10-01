<?php
namespace Reseller\Migration;

// CLI-only: this file must never execute through the web server (the web root is the repo root).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PDO;

/**
 * Dedicated PDO connection for the project migration runner.
 * Independent of the legacy ORM/init.php. Strict session settings, UTC, exceptions on every error.
 */
final class Db
{
    public readonly PDO $pdo;
    public readonly string $database;
    public readonly string $host;
    public readonly string $serverVersion;
    /** 'mariadb' or 'mysql' */
    public readonly string $engineFamily;
    /** numeric version, e.g. "10.11.19" */
    public readonly string $numericVersion;

    public function __construct(string $host, string $database, string $user, string $password)
    {
        // Mirrors the legacy DSN shape (init.php: "mysql:host=$db_host;dbname=$db_name") so the
        // runner targets exactly the database the application uses.
        $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $this->pdo->exec("SET SESSION time_zone = '+00:00'");
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec('SET SESSION foreign_key_checks = 1');
        $this->database = $database;
        $this->host = $host;
        $this->serverVersion = (string) $this->value('SELECT VERSION()');
        $this->engineFamily = stripos($this->serverVersion, 'mariadb') !== false ? 'mariadb' : 'mysql';
        $this->numericVersion = preg_match('/^(\d+\.\d+\.\d+)/', $this->serverVersion, $m) ? $m[1] : '0.0.0';
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Executes one statement. Any error throws; nothing is ever swallowed. */
    public function exec(string $sql, array $params = []): void
    {
        if ($params === []) {
            $this->pdo->exec($sql);
            return;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    public function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    public function tableExists(string $table): bool
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->database, $table]
        ) === 1;
    }

    public function columnExists(string $table, string $column): bool
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->database, $table, $column]
        ) === 1;
    }

    public function foreignKeyExists(string $table, string $name): bool
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$this->database, $table, $name]
        ) === 1;
    }

    /** Foreign-key constraint names are schema-scoped in InnoDB. */
    public function foreignKeyNameExistsAnywhere(string $name): bool
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME = ?',
            [$this->database, $name]
        ) > 0;
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        return array_map(
            static fn(array $r): string => (string) $r['TABLE_NAME'],
            $this->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$this->database])
        );
    }

    public function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
