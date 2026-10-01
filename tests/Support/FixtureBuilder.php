<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PDO;
use PDOException;

/**
 * Builds the WP0-verified baseline schema variants in disposable databases.
 *
 *  V1_FRESH_INSTALL          current install/phpnuxbill.sql (fresh install, installer path)
 *  V2_FRESH_INSTALL_UPDATED  V1 + every system/updates.json version applied the way the legacy
 *                            update.php does it (errors swallowed) — the realistic shape of a fresh
 *                            install after its first web update
 *  V3_LONGLIVED_2023_UPDATED install/phpnuxbill.sql as of 56cea36a (2023-08-09, when
 *                            updates.json was introduced) + every updates.json version
 *
 * LegacyUpdaterReplica semantics (applyLegacyUpdates) exist ONLY to reproduce real legacy
 * deployments as fixtures. The project migration runner never ignores an error.
 */
final class FixtureBuilder
{
    public const V1 = 'V1_FRESH_INSTALL';
    public const V2 = 'V2_FRESH_INSTALL_UPDATED';
    public const V3 = 'V3_LONGLIVED_2023_UPDATED';
    public const ALL = [self::V1, self::V2, self::V3];

    public function __construct(private readonly string $repoRoot)
    {
    }

    public static function connect(string $host, string $password, ?string $database = null): PDO
    {
        $dsn = "mysql:host={$host};charset=utf8mb4" . ($database !== null ? ";dbname={$database}" : '');
        return new PDO($dsn, 'root', $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    }

    /** @return array{statements:int,update_ok:int,update_failed:int} */
    public function build(PDO $pdo, string $variant): array
    {
        $stats = ['statements' => 0, 'update_ok' => 0, 'update_failed' => 0];
        switch ($variant) {
            case self::V1:
                $stats['statements'] = $this->loadSqlFile($pdo, $this->repoRoot . '/install/phpnuxbill.sql');
                break;
            case self::V2:
                $stats['statements'] = $this->loadSqlFile($pdo, $this->repoRoot . '/install/phpnuxbill.sql');
                [$stats['update_ok'], $stats['update_failed']] = $this->applyLegacyUpdates($pdo);
                break;
            case self::V3:
                $stats['statements'] = $this->loadSqlFile($pdo, $this->repoRoot . '/tests/fixtures/schema/phpnuxbill.2023-08-09.sql');
                [$stats['update_ok'], $stats['update_failed']] = $this->applyLegacyUpdates($pdo);
                break;
            default:
                throw new \InvalidArgumentException("unknown variant {$variant}");
        }
        // the installer ends with session-level settings; restore a neutral session
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $stats;
    }

    /** Executes every statement of an installer SQL file; any error aborts (fixtures must be complete). */
    public function loadSqlFile(PDO $pdo, string $file): int
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("cannot read {$file}");
        }
        $n = 0;
        foreach (self::splitSql($sql) as $stmt) {
            $pdo->exec($stmt);
            $n++;
        }
        return $n;
    }

    /**
     * Replica of update.php step 4 (system/updates.json loop): every statement executed, every
     * PDOException ignored (exactly the legacy behaviour this project must NOT reuse).
     * @return array{0:int,1:int} [ok, failed]
     */
    public function applyLegacyUpdates(PDO $pdo): array
    {
        $updates = json_decode((string) file_get_contents($this->repoRoot . '/system/updates.json'), true, 512, JSON_THROW_ON_ERROR);
        $ok = 0;
        $failed = 0;
        foreach ($updates as $queries) {
            foreach ($queries as $q) {
                try {
                    $pdo->exec($q);
                    $ok++;
                } catch (PDOException) {
                    $failed++; // legacy: "//ignore, it exists already"
                }
            }
        }
        return [$ok, $failed];
    }

    /**
     * Splits a MySQL dump into statements, honouring quotes, backticks and comments.
     * Executable comments (/*! ... *\/) are kept as part of their statement.
     * @return list<string>
     */
    public static function splitSql(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($ch === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($ch === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                if ($i + 2 < $len && $sql[$i + 2] === '!') {
                    $buf .= substr($sql, $i, $end - $i);
                }
                $i = $end;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $j = $i + 1;
                while ($j < $len) {
                    if ($sql[$j] === '\\' && $quote !== '`') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $quote) {
                        if ($j + 1 < $len && $sql[$j + 1] === $quote) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $buf .= substr($sql, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if ($ch === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $ch;
            $i++;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
