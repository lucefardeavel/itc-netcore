<?php
namespace Reseller\Tests\Unit\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Domain\CheckConstraints;

/**
 * WP9-D / WP1-CHECK: the canonical CHECK-expression form. Every project CHECK declared in the migration
 * DDL must canonicalize to exactly the text MariaDB 10.11 and MySQL 8.0 report for it (recorded from a
 * freshly migrated database, tests/fixtures/wp9/check-clauses-*.json), and semantically different
 * expressions must never canonicalize to the same text.
 */
final class W9CheckExpressionTest extends TestCase
{
    /** @return array<string,array{table:string,expr:string}> */
    private static function declared(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 3) . '/system/reseller/migrations/R*.php') as $f) {
            $out = CheckConstraints::expectedFromSql((string) file_get_contents($f)) + $out;
        }
        return $out;
    }

    public function testEveryDeclaredCheckMatchesBothEnginesExactly(): void
    {
        $declared = self::declared();
        $this->assertCount(38, $declared);
        foreach (['mariadb', 'mysql'] as $engine) {
            $rows = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/fixtures/wp9/check-clauses-{$engine}.json"), true);
            $this->assertCount(38, $rows, $engine);
            foreach ($rows as $r) {
                $this->assertArrayHasKey($r['n'], $declared, "{$engine}: {$r['n']} not declared");
                $this->assertSame($declared[$r['n']]['table'], $r['t'], "{$engine}: {$r['n']} table");
                $want = CheckConstraints::canonical($declared[$r['n']]['expr']);
                $this->assertNotNull($want, $r['n']);
                $this->assertSame($want, CheckConstraints::canonical($r['c']), "{$engine}: {$r['n']}");
            }
        }
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function different(): array
    {
        return [
            'grouping' => ['`a` = 1 AND `b` = 1 OR `c` = 1', '`a` = 1 AND (`b` = 1 OR `c` = 1)'],
            'operator' => ['`amount` > 0', '`amount` >= 0'],
            'constant' => ['`balance` + `debt_limit` >= 0', '`balance` + `debt_limit` >= -1'],
            'negation' => ['`x` IS NULL', '`x` IS NOT NULL'],
            'in list' => ["`s` IN ('A','B')", "`s` IN ('A','B','C')"],
            'string' => ["`s` <> 'STAFF'", "`s` <> 'staff'"],
            'column' => ['`amount` > 0', '`amount2` > 0'],
            'arith order' => ['`a` - `b` - `c` = 0', '`a` - (`b` - `c`) = 0'],
            'not' => ['`a` = 1', 'NOT `a` = 1'],
            'tautology' => ['`a` = 1', '1 = 1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('different')]
    public function testDifferentExpressionsNeverCompareEqual(string $a, string $b): void
    {
        $this->assertNotNull(CheckConstraints::canonical($a));
        $this->assertNotNull(CheckConstraints::canonical($b));
        $this->assertNotSame(CheckConstraints::canonical($a), CheckConstraints::canonical($b));
    }

    public function testEquivalentRenderingsCompareEqual(): void
    {
        $this->assertSame(CheckConstraints::canonical('`a` = 1 AND `b` = 2 AND `c` = 3'), CheckConstraints::canonical('((`a` = 1) and ((`b` = 2) and (`c` = 3)))'));
        $this->assertSame(CheckConstraints::canonical("`s` <> 'X'"), CheckConstraints::canonical("(`s` <> _utf8mb4\\'X\\')"));
        $this->assertSame(CheckConstraints::canonical('`a` != 1'), CheckConstraints::canonical('`a` <> 1'));
    }

    public function testUnparseableExpressionIsNull(): void
    {
        foreach (['`a` = ', '(`a` = 1', '`a` @ 1', '`a` = 1)', "`a` = 'x", ''] as $bad) {
            $this->assertNull(CheckConstraints::canonical($bad), $bad);
        }
    }
}
