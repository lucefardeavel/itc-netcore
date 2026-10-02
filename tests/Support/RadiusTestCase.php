<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\RadiusFaults;
use Reseller\Radius\RadiusRepository;

/**
 * WP4 RADIUS tests: a disposable application database (V2 fixture: tbl_customers, tbl_plans, ...) and the
 * FreeRADIUS schema (install/radius.sql), either in the same database (the installer's layout) or in a
 * separate one (separateRadiusDatabase()). The ORM default connection is the application database and the
 * ORM 'radius' connection is the RADIUS database, as init.php configures them.
 */
abstract class RadiusTestCase extends TestCase
{
    public const RADIUS_TABLES = ['nas', 'radacct', 'radcheck', 'radgroupcheck', 'radgroupreply', 'radpostauth', 'radreply', 'radusergroup', 'nasreload'];

    protected static ?TestDatabase $db = null;
    protected static ?TestDatabase $radiusDb = null;
    protected static ?\PDO $app = null;
    protected static ?\PDO $radius = null;
    private static string|false $errorLog = false;

    protected static function separateRadiusDatabase(): bool
    {
        return false;
    }

    public static function setUpBeforeClass(): void
    {
        $db = new TestDatabase('r');
        try {
            $db->build(FixtureBuilder::V2);
            $radiusDb = static::separateRadiusDatabase() ? new TestDatabase('rr') : $db;
            (new FixtureBuilder(dirname(__DIR__, 2)))->loadSqlFile($radiusDb->pdo, dirname(__DIR__, 2) . '/install/radius.sql');
        } catch (\Throwable $e) {
            $db->drop();
            throw $e;
        }
        static::$db = $db;
        static::$radiusDb = $radiusDb;
        require_once dirname(__DIR__, 2) . '/system/orm.php';
        static::$app = FixtureBuilder::connect($db->host, TestDatabase::rootPassword(), $db->name);
        static::$radius = $radiusDb === $db ? static::$app : FixtureBuilder::connect($radiusDb->host, TestDatabase::rootPassword(), $radiusDb->name);
        \ORM::reset_db();
        \ORM::set_db(static::$app);
        \ORM::set_db(static::$radius, 'radius');
        RadiusFaults::$hook = null;
        // the sanitized ErrorReporter records of injected failures stay out of the test output
        self::$errorLog = ini_set('error_log', '/dev/null');
    }

    public static function tearDownAfterClass(): void
    {
        RadiusFaults::$hook = null;
        if (self::$errorLog !== false) {
            ini_set('error_log', (string) self::$errorLog);
        }
        \ORM::reset_db();
        if (static::$radiusDb !== null && static::$radiusDb !== static::$db) {
            static::$radiusDb->drop();
        }
        static::$db?->drop();
        static::$db = static::$radiusDb = null;
        static::$app = static::$radius = null;
    }

    protected function tearDown(): void
    {
        RadiusFaults::$hook = null;
    }

    protected function repo(): RadiusRepository
    {
        return new RadiusRepository(static::$radius);
    }

    /** @return list<array<string,mixed>> */
    protected static function rows(string $sql, array $params = []): array
    {
        $st = static::$radius->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Content checksum of one RADIUS table (order-independent). */
    protected static function checksum(string $table): string
    {
        $rows = array_map('json_encode', static::rows("SELECT * FROM {$table}"));
        sort($rows);
        return hash('sha256', implode("\n", $rows));
    }

    /** A FreeRADIUS accounting row (as rlm_sql writes it). */
    protected static function acct(string $username, string $session, ?string $stop, array $extra = []): int
    {
        $row = $extra + [
            'acctsessionid' => $session, 'acctuniqueid' => substr(md5($username . $session), 0, 32), 'username' => $username,
            'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 3600), 'acctstoptime' => $stop,
            'acctsessiontime' => 60, 'acctinputoctets' => 1000, 'acctoutputoctets' => 2000, 'framedipaddress' => '10.0.0.9',
            'calledstationid' => '', 'callingstationid' => '', 'acctterminatecause' => '', 'acctauthentic' => '', 'connectinfo_start' => '',
            'connectinfo_stop' => '', 'servicetype' => '', 'framedprotocol' => '', 'nasporttype' => '', 'realm' => '',
        ];
        $cols = array_keys($row);
        static::$radius->prepare('INSERT INTO radacct (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($row));
        return (int) static::$radius->lastInsertId();
    }

    /** Inserts an application row (V2 fixture tables) and returns its id. */
    protected static function appInsert(string $table, array $values): int
    {
        return static::$db->insertRow($table, $values);
    }
}
