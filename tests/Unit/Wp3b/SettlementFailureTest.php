<?php
namespace Reseller\Tests\Unit\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Domain\DomainError;
use Reseller\Finance\SettlementFailure;

/** WP3B F-6: typed settlement failure classification for WP5A reconciliation. */
final class SettlementFailureTest extends TestCase
{
    private static function pdo(int $driver, string $state = 'HY000'): \PDOException
    {
        $e = new \PDOException("SQLSTATE[{$state}]: driver error {$driver}");
        $e->errorInfo = [$state, $driver, 'x'];
        return $e;
    }

    public function testClassification(): void
    {
        $cases = [
            [new DomainError('CONCURRENCY_RETRY'), SettlementFailure::TRANSIENT, 'CONCURRENCY_RETRY'],
            [new DomainError('LOCK_TIMEOUT'), SettlementFailure::TRANSIENT, 'LOCK_TIMEOUT'],
            [self::pdo(2006), SettlementFailure::TRANSIENT, 'DRIVER_2006'],
            [self::pdo(2013), SettlementFailure::TRANSIENT, 'DRIVER_2013'],
            [new DomainError('STORAGE_RANGE_EXCEEDED'), SettlementFailure::CAPACITY, 'STORAGE_RANGE_EXCEEDED'],
            [new DomainError('COMMISSION_RECIPIENT_CAPACITY'), SettlementFailure::CAPACITY, 'COMMISSION_RECIPIENT_CAPACITY'],
            [new DomainError('INSUFFICIENT_AVAILABLE_CREDIT'), SettlementFailure::CAPACITY, 'INSUFFICIENT_AVAILABLE_CREDIT'],
            [new DomainError('PRICING_SNAPSHOT_INVALID'), SettlementFailure::INTEGRITY, 'PRICING_SNAPSHOT_INVALID'],
            [new DomainError('LEDGER_STATE_MISMATCH'), SettlementFailure::INTEGRITY, 'LEDGER_STATE_MISMATCH'],
            [new DomainError('COMMISSION_RECIPIENT_NO_WALLET'), SettlementFailure::INTEGRITY, 'COMMISSION_RECIPIENT_NO_WALLET'],
            [new \LogicException('x'), SettlementFailure::INTEGRITY, 'UNEXPECTED'],
            [self::pdo(1452, '23000'), SettlementFailure::INTEGRITY, 'DRIVER_1452'],
            [new \RuntimeException('injected failure at commit.after_status'), SettlementFailure::UNKNOWN, 'UNEXPECTED'],
            [new DomainError('FORBIDDEN'), SettlementFailure::UNKNOWN, 'FORBIDDEN'],
        ];
        foreach ($cases as [$e, $kind, $reason]) {
            $f = SettlementFailure::wrap($e, 5, 9);
            $this->assertSame([$kind, $reason, 5, 9, SettlementFailure::EFFECTS_NONE], [$f->kind, $f->reason, $f->reservationId, $f->activationId, $f->effects], get_class($e));
            $this->assertSame($e, $f->getPrevious());
            $this->assertSame($kind, SettlementFailure::classify($e));
            $this->assertInstanceOf(\RuntimeException::class, $f);
            $this->assertSame($f, SettlementFailure::wrap($f, 1, 1), 'never double-wrapped');
        }
        $this->assertSame('injected failure at commit.after_status', SettlementFailure::wrap(new \RuntimeException('injected failure at commit.after_status'), 1, null)->getMessage(), 'the original message is kept');
    }

    public function testMessagesAreSanitized(): void
    {
        $f = SettlementFailure::wrap(new \RuntimeException('connect failed password=hunter2 token: abc'), 1, null);
        $this->assertStringNotContainsString('hunter2', $f->getMessage());
        $this->assertStringNotContainsString('abc', $f->getMessage());
    }
}
