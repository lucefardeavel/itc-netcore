<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 related to U0 (owner §5, D-U0-6): V4 - two voucher codes that are one RADIUS identity (equal after trim() under
 * utf8mb4_general_ci: case, accent, trailing / leading whitespace, exact duplicate rows). Sequential: the first code is
 * granted (a live voucher-only identity); the second voucher-only redemption must be REFUSED before its claim, with no
 * business / finance / RADIUS change and the first identity's policy intact. The concurrent forms are U0RaceTest C1/C2.
 * Generation (observation only, no policy change): the WP5B batch uniqueness is byte-exact, so collation-equal UNUSED codes
 * can still be issued; after U0 only one of them can ever be granted.
 */
final class U0V4Test extends RadiusHttpTestCase
{
    use U0Fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
    }

    public static function variants(): array
    {
        return ['upper case' => ['{t}ab', '{T}AB'], 'accent' => ['{t}é', '{t}e'], 'trailing space' => ['{t}', '{t} '], 'leading space' => ['{t}', ' {t}'],
            'trailing TAB' => ['{t}', "{t}\t"], 'exact duplicate row' => ['{t}', '{t}']];
    }

    #[DataProvider('variants')]
    public function testASecondCodeOfTheSameRadiusIdentityIsRefused(string $a, string $b): void
    {
        $t = self::tag();
        $f = static fn(string $s): string => str_replace(['{t}', '{T}'], [$t, strtoupper($t)], $s);
        $this->voucher($f($a));
        $this->assertSame('GRANTED', (string) static::$app->exec(self::p6Code($f($a))), 'control: the first code is a live voucher-only identity');
        $policy = self::exact(trim($f($a)));
        $vid = $this->voucher($f($b));
        $before = self::state(static::$db);
        $second = (string) static::$app->exec(self::p6Code($f($b)));
        $d = self::delta($before, self::state(static::$db));
        $intact = self::exact(trim($f($a))) === $policy;
        $met = $second !== 'GRANTED' && $d['business'] === [] && $intact && self::vstatus($vid) === '0';
        self::record('U0 V4 ' . $this->dataName(), $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['second' => $second, 'state_delta' => $d, 'first_policy_intact' => $intact, 'second_voucher_status' => self::vstatus($vid)]);
        $this->assertNotSame('GRANTED', $second);
        $this->assertSame([], $d['business']);
        $this->assertTrue($intact);
        $this->assertSame('0', self::vstatus($vid), 'the second voucher is never claimed');
    }

    public function testGenerationOfCollationEqualUnusedCodesIsObserved(): void
    {
        $sa = $this->staff('sa');
        $px = 'w9v4g' . bin2hex(random_bytes(2));
        foreach (['up', 'low'] as $format) {
            $sa->postWithToken('plan/voucher-post', ['type' => 'Hotspot', 'plan' => self::$hotspot, 'server' => 'radius', 'voucher_format' => $format,
                'prefix' => $px, 'numbervoucher' => '40', 'lengthcode' => '2', 'print_now' => 'no']);
        }
        $codes = array_column(static::$db->rows('SELECT code FROM tbl_voucher WHERE code LIKE ?', [$px . '%']), 'code');
        $groups = [];
        foreach ($codes as $c) {
            $groups[strtolower(trim($c))][] = $c;
        }
        $equalGroups = array_values(array_filter($groups, static fn(array $g): bool => count($g) > 1));
        $granted = [];
        foreach (array_slice($equalGroups, 0, 3) as $g) {
            $granted[] = array_map(static fn(string $c): string => (string) static::$app->exec(self::p6Code($c)), $g);
        }
        self::record('U0 V4 generation observation', 'OBSERVED', ['codes' => count($codes), 'collation_equal_groups' => count($equalGroups), 'redemption_of_first_groups' => $granted,
            'detail' => 'WP5B batch uniqueness is byte-exact; after U0 at most one code of a group is granted (availability residual, no policy change)']);
        foreach ($granted as $g) {
            $this->assertLessThanOrEqual(1, count(array_filter($g, static fn(string $s): bool => $s === 'GRANTED')), 'one grant per RADIUS identity ' . json_encode($g));
        }
    }
}
