<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\TestDatabase;

/**
 * Lab finding B-L09 (ITC NetCore lab 2026-10-25, live FreeRADIUS + CHR): install/radius.sql created `nasreload` without a
 * collation, so it took the database default while every other RADIUS table is utf8mb4_general_ci. On a database whose
 * default is not utf8mb4_general_ci (a MySQL 8 default database is utf8mb4_0900_ai_ci; the lab used utf8mb4_unicode_ci)
 * the stock FreeRADIUS 3.2.x simultaneous-use queries (queries.conf simul_count_query / simul_verify_query, identical in
 * the pinned 3.2.8 image) failed with ERROR 1267 "Illegal mix of collations" on the nasreload join, on every request.
 * The shipped RADIUS schema must work whatever the database default collation is.
 */
final class W10NasreloadCollationTest extends TestCase
{
    /** the two stock queries of mods-config/sql/main/mysql/queries.conf that join nasreload */
    private const SIMUL_COUNT = "SELECT COUNT(*) FROM radacct a LEFT OUTER JOIN nasreload n USING (nasipaddress)"
        . " WHERE username = 'w10-collation' AND acctstoptime IS NULL AND (a.acctstarttime > n.reloadtime OR n.reloadtime IS NULL)";
    private const SIMUL_VERIFY = "SELECT radacctid, acctsessionid, username, nasipaddress, nasportid, framedipaddress, callingstationid, framedprotocol"
        . " FROM radacct a LEFT OUTER JOIN nasreload n USING (nasipaddress)"
        . " WHERE username = 'w10-collation' AND acctstoptime IS NULL AND (a.acctstarttime > n.reloadtime OR n.reloadtime IS NULL)";

    /** @return array<string, array{0: ?string}> database default collation; null = the engine's own default */
    public static function defaults(): array
    {
        return ['utf8mb4_unicode_ci default' => ['utf8mb4_unicode_ci'], 'engine default' => [null]];
    }

    #[DataProvider('defaults')]
    public function testStockSessionQueriesRunWhateverTheDatabaseDefaultCollation(?string $collation): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        $password = TestDatabase::rootPassword();
        $name = 'wp1_bl09_' . bin2hex(random_bytes(5));
        $admin = FixtureBuilder::connect($host, $password);
        $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4" . ($collation !== null ? " COLLATE {$collation}" : ''));
        try {
            $pdo = FixtureBuilder::connect($host, $password, $name);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            (new FixtureBuilder(dirname(__DIR__, 3)))->loadSqlFile($pdo, dirname(__DIR__, 3) . '/install/radius.sql');
            $pdo->exec("INSERT INTO radacct (radacctid, acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime)"
                . " VALUES (1, 'w10-s1', 'w10-u1', 'w10-collation', '192.0.2.10', NOW())");
            $pdo->exec("INSERT INTO nasreload (nasipaddress, reloadtime) VALUES ('192.0.2.10', NOW() - INTERVAL 1 HOUR)");

            $this->assertSame(1, (int) $pdo->query(self::SIMUL_COUNT)->fetchColumn(), 'stock simul_count_query runs and counts the open session');
            $this->assertCount(1, $pdo->query(self::SIMUL_VERIFY)->fetchAll(), 'stock simul_verify_query runs');
            $this->assertSame('utf8mb4_general_ci', (string) $pdo->query("SELECT collation_name FROM information_schema.COLUMNS"
                . " WHERE table_schema = DATABASE() AND table_name = 'nasreload' AND column_name = 'nasipaddress'")->fetchColumn(),
                'nasreload.nasipaddress has the collation of radacct.nasipaddress');
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
        }
    }
}
