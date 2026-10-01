<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;

/**
 * Exact reseller money (WP3A money model, owner decision): an immutable count of integer minor units
 * (1 = 0.01). Binary floating point is never used.
 *
 * Two ranges are deliberately separate:
 *   storage range      one DECIMAL(18,2) column: |minor| <= STORAGE_MAX_MINOR (9,999,999,999,999,999.99).
 *                      Enforced by toStorage() on every value that is written to a column.
 *   calculation range  checked signed int64 (symmetric, ±PHP_INT_MAX). Intermediates such as
 *                      balance + debt_limit, SUM(ACTIVE reservations) or available credit may exceed one
 *                      column's range and remain valid; every plus/minus is overflow-checked.
 *
 * Input is accepted as strings only: user/caller amounts through parsePositive()/parseNonNegative(),
 * column values through fromDb(), SQL SUM() results through fromDbAggregate().
 */
final class Money implements \JsonSerializable
{
    public const STORAGE_MAX_MINOR = 999_999_999_999_999_999;

    /** caller input: at most 16 integer digits (the DECIMAL(18,2) integer part) and 2 decimals */
    private const INPUT = '/^(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,2}))?$/D';
    /** exactly what a DECIMAL(18,2) column returns */
    private const COLUMN = '/^(-?)(0|[1-9][0-9]{0,15})\.([0-9]{2})$/D';
    /** SUM() of DECIMAL(18,2) columns: non-negative, any number of integer digits */
    private const AGGREGATE = '/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D';

    private function __construct(public readonly int $minor)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** Calculation-range value (PHP_INT_MIN is excluded so negation can never overflow). */
    public static function ofMinor(int $minor): self
    {
        if ($minor === PHP_INT_MIN) {
            throw new DomainError('MONEY_OVERFLOW');
        }
        return new self($minor);
    }

    /** A strictly positive caller amount ("12", "12.5", "12.50"); anything else is INVALID_AMOUNT. */
    public static function parsePositive(mixed $value): self
    {
        $m = self::parseInput($value);
        if ($m->minor <= 0) {
            throw new DomainError('INVALID_AMOUNT');
        }
        return $m;
    }

    /** A non-negative caller amount (debt limit). */
    public static function parseNonNegative(mixed $value): self
    {
        return self::parseInput($value);
    }

    /** One DECIMAL(18,2) column value as returned by the driver; any other shape is an internal fault. */
    public static function fromDb(mixed $value): self
    {
        if (!is_string($value) || !preg_match(self::COLUMN, $value, $m)) {
            throw new \UnexpectedValueException('not a DECIMAL(18,2) column value');
        }
        $minor = self::digitsToMinor($m[2], $m[3]);
        return new self($m[1] === '-' ? -$minor : $minor);
    }

    /**
     * SUM() over DECIMAL(18,2) columns (NULL for no rows). The total may exceed one column's storage
     * range and is converted into the checked calculation range (MONEY_OVERFLOW beyond int64).
     */
    public static function fromDbAggregate(mixed $value): self
    {
        if ($value === null) {
            return new self(0);
        }
        if (!is_string($value) || !preg_match(self::AGGREGATE, $value, $m)) {
            throw new \UnexpectedValueException('not a non-negative DECIMAL aggregate');
        }
        $digits = ltrim($m[1] . str_pad($m[2] ?? '', 2, '0'), '0');
        if ($digits === '') {
            return new self(0);
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new DomainError('MONEY_OVERFLOW');
        }
        return new self((int) $digits);
    }

    public function plus(self $other): self
    {
        $a = $this->minor;
        $b = $other->minor;
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < -PHP_INT_MAX - $b)) {
            throw new DomainError('MONEY_OVERFLOW');
        }
        return new self($a + $b);
    }

    public function minus(self $other): self
    {
        return $this->plus(new self(-$other->minor));
    }

    public function compare(self $other): int
    {
        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    /** Fits one DECIMAL(18,2) column. */
    public function isStorable(): bool
    {
        return abs($this->minor) <= self::STORAGE_MAX_MINOR;
    }

    /** The exact DECIMAL(18,2) literal for a column write; STORAGE_RANGE_EXCEEDED before any SQL. */
    public function toStorage(): string
    {
        if (!$this->isStorable()) {
            throw new DomainError('STORAGE_RANGE_EXCEEDED');
        }
        return $this->format();
    }

    /** Canonical "-?d+.dd" text of any calculation-range value (display, audit, comparison). */
    public function format(): string
    {
        $abs = abs($this->minor);
        return ($this->minor < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function jsonSerialize(): string
    {
        return $this->format();
    }

    private static function parseInput(mixed $value): self
    {
        if (!is_string($value) || !preg_match(self::INPUT, $value, $m)) {
            throw new DomainError('INVALID_AMOUNT');
        }
        return new self(self::digitsToMinor($m[1], $m[2] ?? ''));
    }

    /** At most 16 integer digits (validated by the caller's pattern): the result always fits int64. */
    private static function digitsToMinor(string $int, string $frac): int
    {
        return (int) $int * 100 + (int) str_pad($frac, 2, '0');
    }
}
