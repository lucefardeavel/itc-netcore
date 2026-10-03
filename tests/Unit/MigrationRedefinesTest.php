<?php
namespace Reseller\Tests\Unit;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reseller\Migration\MigrationDefinition;
use Reseller\Migration\Outcome;
use Reseller\Migration\StopException;

/** WP5B: the owner-approved 'redefines' contract primitive is narrow and fails closed on any malformed entry (no database). */
final class MigrationRedefinesTest extends TestCase
{
    private const GOOD = ['from' => 'int not null', 'to' => 'bigint not null', 'data_guard' => 'SELECT 1 FROM `tbl_reseller_x` WHERE `c` > 2147483647 LIMIT 1'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/redef_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.php'));
        rmdir($this->dir);
    }

    private function load(array $contract): MigrationDefinition
    {
        $file = $this->dir . '/R0001_redef.php';
        file_put_contents($file, '<?php return ' . var_export(['id' => 'R0001_redef', 'description' => 'x', 'requires' => null,
            'up' => [['id' => 'a', 'sql' => 'ALTER TABLE `tbl_reseller_x` MODIFY `c` BIGINT NOT NULL']],
            'down' => [['id' => 'b', 'sql' => 'ALTER TABLE `tbl_reseller_x` MODIFY `c` INT NOT NULL', 'if_exists' => ['table' => 'tbl_reseller_x']]],
            'contract' => $contract], true) . ";\n");
        return MigrationDefinition::load($file);
    }

    public function testWellFormedRedefinitionLoads(): void
    {
        $m = $this->load(['tables' => [], 'table_parts' => [], 'redefines' => ['tbl_reseller_x' => ['c' => self::GOOD]]]);
        $this->assertSame(['tbl_reseller_x' => ['c' => self::GOOD]], $m->redefinitions());
        $this->assertSame([], $this->load(['tables' => [], 'table_parts' => []])->redefinitions(), 'absent redefines = none');
    }

    /** @return array<string,array{0:mixed}> */
    public static function malformed(): array
    {
        $g = self::GOOD;
        return [
            'redefines not an array' => ['x'],
            'table without columns' => [['tbl_reseller_x' => []]],
            'columns not an array' => [['tbl_reseller_x' => 'c']],
            'numeric table key' => [[0 => ['c' => $g]]],
            'invalid table identifier' => [['tbl-x' => ['c' => $g]]],
            'quoted table identifier' => [['`tbl_x`' => ['c' => $g]]],
            'upper-case table identifier' => [['TBL_X' => ['c' => $g]]],
            'numeric column key' => [['tbl_reseller_x' => [0 => $g]]],
            'invalid column identifier' => [['tbl_reseller_x' => ['c d' => $g]]],
            'entry not an array' => [['tbl_reseller_x' => ['c' => 'int']]],
            'missing from' => [['tbl_reseller_x' => ['c' => ['to' => $g['to'], 'data_guard' => $g['data_guard']]]]],
            'missing to' => [['tbl_reseller_x' => ['c' => ['from' => $g['from'], 'data_guard' => $g['data_guard']]]]],
            'missing data_guard' => [['tbl_reseller_x' => ['c' => ['from' => $g['from'], 'to' => $g['to']]]]],
            'extra key' => [['tbl_reseller_x' => ['c' => $g + ['sql' => 'x']]]],
            'empty from' => [['tbl_reseller_x' => ['c' => ['from' => ' '] + $g]]],
            'empty to' => [['tbl_reseller_x' => ['c' => ['to' => ''] + $g]]],
            'non-string to' => [['tbl_reseller_x' => ['c' => ['to' => 5] + $g]]],
            'from equals to' => [['tbl_reseller_x' => ['c' => ['to' => $g['from']] + $g]]],
            'empty data_guard' => [['tbl_reseller_x' => ['c' => ['data_guard' => ''] + $g]]],
        ];
    }

    #[DataProvider('malformed')]
    public function testMalformedRedefinitionIsRejected(mixed $redefines): void
    {
        $this->expectRejected(['tables' => [], 'table_parts' => [], 'redefines' => $redefines]);
    }

    public function testRedefiningMigrationMayNotAlsoCreateTablesOrParts(): void
    {
        $this->expectRejected(['tables' => ['tbl_reseller_y' => ['columns' => []]], 'table_parts' => [], 'redefines' => ['tbl_reseller_x' => ['c' => self::GOOD]]]);
        $this->expectRejected(['tables' => [], 'table_parts' => ['tbl_customers' => ['columns' => []]], 'redefines' => ['tbl_reseller_x' => ['c' => self::GOOD]]]);
    }

    /** @return array<string,array{0:string}> */
    public static function nonReadOnlyGuards(): array
    {
        return [
            'not a select' => ['SHOW TABLES'],
            'leading space' => [' SELECT 1'],
            'update' => ['UPDATE `tbl_reseller_x` SET `c` = 1'],
            'delete' => ['DELETE FROM `tbl_reseller_x`'],
            'insert' => ['INSERT INTO `tbl_reseller_x` VALUES (1)'],
            'drop' => ['DROP TABLE `tbl_reseller_x`'],
            'alter' => ['ALTER TABLE `tbl_reseller_x` DROP `c`'],
            'truncate' => ['TRUNCATE `tbl_reseller_x`'],
            'second statement' => ['SELECT 1; DROP TABLE `tbl_reseller_x`'],
            'trailing separator' => ['SELECT 1 FROM `tbl_reseller_x`;'],
            'select into outfile' => ["SELECT `c` FROM `tbl_reseller_x` INTO OUTFILE '/tmp/x'"],
            'select into variable' => ['SELECT `c` INTO @v FROM `tbl_reseller_x`'],
            'locking read' => ['SELECT `c` FROM `tbl_reseller_x` FOR UPDATE'],
            'share lock' => ['SELECT `c` FROM `tbl_reseller_x` LOCK IN SHARE MODE'],
            'sleep' => ['SELECT SLEEP(10)'],
            'benchmark' => ['SELECT BENCHMARK(1000000, MD5(1))'],
            'named lock' => ["SELECT GET_LOCK('x', 10)"],
            'line comment' => ['SELECT 1 -- x'],
            'hash comment' => ['SELECT 1 # x'],
            'block comment' => ['SELECT 1 /* x */'],
            'executable comment' => ['SELECT 1 /*!50000 , SLEEP(1) */'],
            'backslash escape' => ["SELECT 1 FROM `tbl_reseller_x` WHERE `c` = '\\' OR 1"],
            'replace' => ['REPLACE INTO `tbl_reseller_x` VALUES (1)'],
            'set' => ['SELECT 1 FROM `tbl_reseller_x` WHERE @a := 1 SET'],
        ];
    }

    #[DataProvider('nonReadOnlyGuards')]
    public function testMutatingOrNonSelectDataGuardIsRejected(string $guard): void
    {
        $this->assertFalse(MigrationDefinition::readOnlySelect($guard), $guard);
        $this->expectRejected(['tables' => [], 'table_parts' => [], 'redefines' => ['tbl_reseller_x' => ['c' => ['data_guard' => $guard] + self::GOOD]]]);
    }

    public function testReadOnlyGuardsAccepted(): void
    {
        foreach ([self::GOOD['data_guard'],
            "SELECT 1 FROM `tbl_reseller_activations` WHERE `status` IN ('REVERSAL_PENDING','REVERSED') LIMIT 1",
            "SELECT 1 FROM `t` WHERE `c` = 'update drop' LIMIT 1", // keywords inside a string literal are data
        ] as $g) {
            $this->assertTrue(MigrationDefinition::readOnlySelect($g), $g);
        }
    }

    private function expectRejected(array $contract): void
    {
        try {
            $this->load($contract);
            $this->fail('malformed redefinition accepted');
        } catch (StopException $e) {
            $this->assertSame(Outcome::USAGE, $e->outcome);
        }
    }
}
