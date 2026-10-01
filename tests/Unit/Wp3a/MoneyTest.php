<?php
namespace Reseller\Tests\Unit\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reseller\Domain\DomainError;
use Reseller\Finance\Money;

/**
 * WP3A money model (owner correction): integer minor units, string-only boundaries, no float; the
 * DECIMAL(18,2) storage range applies only to persisted values, intermediates use checked int64.
 */
final class MoneyTest extends TestCase
{
    private const MAX = '9999999999999999.99';

    private function reason(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainError $e) {
            return $e->reason;
        }
        return 'NO_ERROR';
    }

    public function testExactSmallestUnitAndCanonicalForms(): void
    {
        $this->assertSame(1, Money::parsePositive('0.01')->minor);
        $this->assertSame('0.01', Money::parsePositive('0.01')->toStorage());
        $this->assertSame(1250, Money::parsePositive('12.5')->minor);
        $this->assertSame('12.50', Money::parsePositive('12.50')->format());
        $this->assertSame('12.00', Money::parsePositive('12')->format());
        $this->assertSame('INVALID_AMOUNT', $this->reason(fn() => Money::parsePositive('0')));
        $this->assertSame('INVALID_AMOUNT', $this->reason(fn() => Money::parsePositive('0.00')));
        $this->assertSame(0, Money::parseNonNegative('0')->minor);
        $this->assertSame('0.00', Money::parseNonNegative('0.00')->toStorage());
    }

    public function testLargestDecimal1802ValueIsExact(): void
    {
        $max = Money::parsePositive(self::MAX);
        $this->assertSame(Money::STORAGE_MAX_MINOR, $max->minor);
        $this->assertSame(self::MAX, $max->toStorage());
        $this->assertTrue($max->isStorable());
        $this->assertSame(self::MAX, Money::fromDb(self::MAX)->toStorage());
        $this->assertSame('-' . self::MAX, Money::fromDb('-' . self::MAX)->toStorage());
        // +0.01 beyond the column range is a valid calculation but never storable
        $over = $max->plus(Money::parsePositive('0.01'));
        $this->assertSame('10000000000000000.00', $over->format());
        $this->assertFalse($over->isStorable());
        $this->assertSame('STORAGE_RANGE_EXCEEDED', $this->reason(fn() => $over->toStorage()));
        $this->assertSame('STORAGE_RANGE_EXCEEDED', $this->reason(fn() => Money::fromDb('-' . self::MAX)->minus(Money::parsePositive('0.01'))->toStorage()));
    }

    /** balance MAX + debt_limit MAX (≈ 2 × MAX) is a valid intermediate; available may be computed from it. */
    public function testIntermediateBeyondOneColumnIsValid(): void
    {
        $max = Money::fromDb(self::MAX);
        $sum = $max->plus($max);
        $this->assertSame('19999999999999999.98', $sum->format());
        $this->assertSame(2 * Money::STORAGE_MAX_MINOR, $sum->minor);
        $this->assertFalse($sum->isStorable());
        // available = balance + debt - reserved with SUM(reserved) = 2 × MAX
        $reserved = Money::fromDbAggregate('19999999999999999.98');
        $this->assertTrue($sum->minus($reserved)->isZero());
        $this->assertSame('0.00', $sum->minus($reserved)->toStorage());
        // and a negative available beyond one column is still exact
        $this->assertSame('-19999999999999999.98', Money::zero()->minus($sum)->format());
    }

    public function testCheckedInt64NeverOverflows(): void
    {
        $big = Money::ofMinor(PHP_INT_MAX);
        $this->assertSame('MONEY_OVERFLOW', $this->reason(fn() => $big->plus(Money::ofMinor(1))));
        $this->assertSame('MONEY_OVERFLOW', $this->reason(fn() => Money::ofMinor(-PHP_INT_MAX)->minus(Money::ofMinor(1))));
        $this->assertSame('MONEY_OVERFLOW', $this->reason(fn() => Money::ofMinor(PHP_INT_MIN)));
        $this->assertSame(PHP_INT_MAX, $big->plus(Money::zero())->minor);
        $this->assertSame(0, $big->minus($big)->minor);
        $this->assertSame('MONEY_OVERFLOW', $this->reason(fn() => $big->minus(Money::ofMinor(-1))), 'subtracting a negative is checked too');
        // aggregate parsing: int64 bound (92233720368547758.07) is the calculation limit
        $this->assertSame(PHP_INT_MAX, Money::fromDbAggregate('92233720368547758.07')->minor);
        $this->assertSame('MONEY_OVERFLOW', $this->reason(fn() => Money::fromDbAggregate('92233720368547758.08')));
        $this->assertSame('MONEY_OVERFLOW', $this->reason(fn() => Money::fromDbAggregate('1000000000000000000000.00')));
        $this->assertSame(0, Money::fromDbAggregate(null)->minor);
        $this->assertSame(0, Money::fromDbAggregate('0.00')->minor);
        $this->assertSame(5, Money::fromDbAggregate('0.05')->minor);
        $this->assertSame(250, Money::fromDbAggregate('2.5')->minor);
    }

    public function testNoFloatingPointDrift(): void
    {
        $sum = Money::zero();
        for ($i = 0; $i < 10; $i++) {
            $sum = $sum->plus(Money::parsePositive('0.10'));
        }
        $this->assertSame('1.00', $sum->toStorage());
        $this->assertTrue($sum->equals(Money::parsePositive('1')));
        $a = Money::parsePositive('0.1')->plus(Money::parsePositive('0.2'));
        $this->assertSame('0.30', $a->format(), '0.1 + 0.2 is exactly 0.30 (no binary floating point)');
        $this->assertSame(0, $a->compare(Money::parsePositive('0.3')));
        $x = Money::parsePositive('1234567890123456.78');
        $this->assertSame('1234567890123456.78', $x->plus(Money::parsePositive('0.01'))->minus(Money::parsePositive('0.01'))->format());
    }

    /** @return array<string,array{0:mixed}> */
    public static function malformed(): array
    {
        $cases = [
            'NaN' => 'NaN', 'nan' => 'nan', 'INF' => 'INF', 'Infinity' => 'Infinity', '-INF' => '-INF', 'exponent' => '1e3', 'exponent2' => '1E-2',
            'plus sign' => '+1', 'leading space' => ' 1', 'trailing space' => '1 ', 'trailing newline' => "1\n", 'dot only' => '.', 'trailing dot' => '1.',
            'leading dot' => '.5', 'leading zero' => '00.5', 'leading zero int' => '01', 'thousands' => '1,000', 'comma decimal' => '1,5',
            'three decimals' => '1.001', 'three decimals zero' => '1.000', '17 int digits' => '10000000000000000', '17 digits max' => '99999999999999999.99',
            'arabic digits' => '١٢', 'fullwidth' => '１', 'hex' => '0x10', 'empty' => '', 'negative' => '-1', 'negative zero' => '-0', 'underscore' => '1_000',
            'float' => 1.5, 'int' => 1, 'null' => null, 'bool' => true, 'array' => ['1'], 'nul byte' => "1\0", 'tab' => "\t1",
        ];
        $out = [];
        foreach ($cases as $k => $v) {
            $out[$k] = [$v];
        }
        return $out;
    }

    #[DataProvider('malformed')]
    public function testMalformedInputsAreRejected(mixed $value): void
    {
        $this->assertSame('INVALID_AMOUNT', $this->reason(fn() => Money::parsePositive($value)));
        $this->assertSame('INVALID_AMOUNT', $this->reason(fn() => Money::parseNonNegative($value)));
    }

    public function testColumnValuesAreStrict(): void
    {
        foreach (['1', '1.0', '1.000', '+1.00', ' 1.00', '01.00', '1e2', '10000000000000000.00', 'NaN', '', '1.00 '] as $bad) {
            try {
                Money::fromDb($bad);
                $this->fail("fromDb accepted {$bad}");
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([1.0, 100, null] as $bad) {
            try {
                Money::fromDb($bad);
                $this->fail('fromDb accepted a non-string');
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['-1.00', '1e3', 'x', ' 1.00'] as $bad) {
            try {
                Money::fromDbAggregate($bad);
                $this->fail("fromDbAggregate accepted {$bad}");
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(-1, Money::fromDb('-0.01')->minor);
        $this->assertSame('0.00', Money::fromDb('0.00')->format());
    }

    public function testComparisonsAndJson(): void
    {
        $a = Money::parsePositive('10.00');
        $b = Money::parsePositive('9.99');
        $this->assertSame(1, $a->compare($b));
        $this->assertSame(-1, $b->compare($a));
        $this->assertTrue($b->minus($a)->isNegative());
        $this->assertSame('"-0.01"', json_encode($b->minus($a)));
        $this->assertFalse((new \ReflectionClass(Money::class))->getConstructor()->isPublic(), 'Money is built only through checked factories');
    }
}
