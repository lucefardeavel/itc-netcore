<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 related: the READ-ONLY canonical identity survey dev/wp9/identity_survey.sql (grandfathered duplicates) on
 * seeded synthetic duplicates of every class the T1-A survey missed or did not cover: stored leading space, trailing TAB,
 * case, accent, cross-field, live voucher-only identity, voucher-only recharge row. Control: a distinct name is not
 * reported. The survey only reads (state checksums unchanged).
 */
final class U0SurveyTest extends RadiusHttpTestCase
{
    use U0Fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
    }

    public function testCanonicalSurveyReportsEveryDuplicateClassAndOnlyReads(): void
    {
        $t = self::tag();
        $seed = ['leading space' => [' ' . $t . 'a', $t . 'a'], 'trailing TAB' => [$t . "b\t", $t . 'b'], 'case' => [$t . 'c', strtoupper($t . 'c')],
            'accent' => [$t . 'dé', $t . 'de']];
        foreach ($seed as [$one, $two]) {
            $this->person($one);
            $this->person($two);
        }
        $this->person($t . 'e');
        $this->person(self::tag(), $t . 'e');                     // cross-field
        $this->voucher($t . 'f');
        $this->assertSame('GRANTED', (string) static::$app->exec(self::p6Code($t . 'f')));
        $this->person(strtoupper($t . 'f'));                     // customer over a live voucher identity (grandfathered)
        $this->person($t . 'z');                                 // control: unique
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/dev/wp9/identity_survey.sql');
        $statements = array_values(array_filter(array_map('trim', explode(';', preg_replace('/^--[^\n]*$/m', '', $sql))), static fn(string $s): bool => $s !== ''));
        $before = self::state(static::$db);
        $results = array_map(static fn(string $s): array => static::$db->rows($s), $statements);
        $after = self::state(static::$db);
        $keys = array_map(static fn(array $r): string => strtolower((string) $r['k']), $results[1]);
        $expect = ['leading space' => $t . 'a', 'trailing TAB' => $t . 'b', 'case' => $t . 'c', 'accent' => $t . 'd?', 'cross-field' => $t . 'e', 'voucher identity' => $t . 'f'];
        $found = [];
        foreach ($expect as $case => $k) {
            $found[$case] = $case === 'accent' ? (bool) array_filter($keys, static fn(string $x): bool => str_starts_with($x, $t . 'd')) : in_array($k, $keys, true);
        }
        $control = in_array($t . 'z', $keys, true);
        self::record('U0 canonical survey', !in_array(false, $found, true) && !$control && $before === $after ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED',
            ['found' => $found, 'unique_name_reported' => $control, 'edge_whitespace_rows' => count($results[2]), 'read_only' => $before === $after]);
        $this->assertSame(array_fill_keys(array_keys($expect), true), $found);
        $this->assertFalse($control, 'a unique name is not reported');
        $this->assertGreaterThanOrEqual(2, count($results[2]), 'edge-whitespace rows listed');
        $this->assertSame($before, $after, 'the survey only reads');
    }
}
