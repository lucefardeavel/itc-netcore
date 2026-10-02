<?php
namespace Reseller\Tests\Unit\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Activation\ActivationService;
use Reseller\Activation\ActivationState as S;
use Reseller\Activation\ServicePeriod;
use Reseller\Domain\DomainError;

/** The state machine (A3), the C14 service period with legacy parity and C18 time zone, the derived action (A2). */
final class ActivationStateAndPeriodTest extends TestCase
{
    public function testTransitionTableOverTheFullMatrix(): void
    {
        $this->assertCount(15, S::ALL);
        $states = S::ALL;
        $rows = array_keys(S::TRANSITIONS);
        sort($states);
        sort($rows);
        $this->assertSame($states, $rows, 'every state has exactly one row');
        $allowed = 0;
        foreach (S::ALL as $from) {
            foreach (S::ALL as $to) {
                $this->assertSame(in_array($to, S::TRANSITIONS[$from], true), S::allowed($from, $to), "{$from} -> {$to}");
                $allowed += S::allowed($from, $to) ? 1 : 0;
            }
        }
        $this->assertSame(24, $allowed, 'exactly the approved edges');
        foreach (S::TERMINAL as $t) {
            $this->assertSame([], S::TRANSITIONS[$t], "{$t} is terminal");
        }
        $path = [S::CREATED, S::VALIDATED, S::FUNDS_RESERVED, S::PROVISIONING, S::PROVISIONED, S::VERIFYING, S::VERIFIED, S::FINANCIAL_COMMITTING, S::COMPLETED];
        for ($i = 1; $i < count($path); $i++) {
            $this->assertTrue(S::allowed($path[$i - 1], $path[$i]), 'nominal path');
        }
        // money safety: nothing reaches COMPLETED except the settlement step; once provisioning may have happened
        // the activation can only be reconciled (never failed / cancelled without a read-back)
        foreach (S::ALL as $from) {
            $this->assertSame($from === S::FINANCIAL_COMMITTING, S::allowed($from, S::COMPLETED), "{$from} -> COMPLETED");
        }
        foreach ([S::PROVISIONED, S::VERIFYING, S::VERIFIED, S::FINANCIAL_COMMITTING] as $applied) {
            $this->assertFalse(S::allowed($applied, S::FAILED), "{$applied} -> FAILED would release funds for a provisioned service");
            $this->assertFalse(S::allowed($applied, S::CANCELLED));
        }
        try {
            S::assertTransition(S::COMPLETED, S::FAILED);
            $this->fail('terminal');
        } catch (DomainError $e) {
            $this->assertSame('ACTIVATION_STATE_CONFLICT', $e->reason);
        }
    }

    /** @return array<string,array{0:string,1:int,2:string,3:string}> unit, n, now, expected fresh expiry */
    public static function fresh(): array
    {
        return [
            'months' => ['Months', 1, '2026-01-31 10:15:00', '2026-03-03 10:15:00'], // PHP month overflow, as the legacy strtotime
            'days' => ['Days', 30, '2026-10-02 23:30:00', '2026-11-01 23:30:00'],
            'hours' => ['Hrs', 5, '2026-10-02 21:00:00', '2026-10-03 02:00:00'],
            'minutes' => ['Mins', 90, '2026-10-02 23:00:00', '2026-10-03 00:30:00'],
            'period' => ['Period', 1, '2026-10-02 08:00:00', '2026-10-20 23:59:59'], // next month's 20th is > 35 days away: this month
            'period-short' => ['Period', 1, '2026-10-25 08:00:00', '2026-11-20 23:59:59'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fresh')]
    public function testFreshPeriodMatchesTheLegacyArithmetic(string $unit, int $n, string $now, string $expected): void
    {
        $zone = new \DateTimeZone('Asia/Baghdad');
        $at = new \DateTimeImmutable($now, $zone);
        foreach (['NEW', 'REACTIVATE', 'CHANGE_PLAN'] as $action) {
            [$start, $exp] = ServicePeriod::compute($action, ['validity' => $n, 'validity_unit' => $unit], null, $at, 20);
            $this->assertSame($now, $start->format('Y-m-d H:i:s'), 'starts now');
            $this->assertSame($expected, $exp->format('Y-m-d H:i:s'), "{$action} {$unit}");
            $this->assertSame('Asia/Baghdad', $exp->getTimezone()->getName(), 'C18: application time zone wall clock');
        }
        $this->assertSame($expected, self::legacyFresh($unit, $n, $now, 'Asia/Baghdad'), 'parity with Package::rechargeUser');
    }

    /** @return array<string,array{0:string,1:int,2:string,3:string}> unit, n, current expiry, expected */
    public static function renewals(): array
    {
        return [
            'months' => ['Months', 1, '2031-01-31 12:00:00', '2031-03-03 12:00:00'],
            'days' => ['Days', 30, '2031-03-10 12:00:00', '2031-04-09 12:00:00'],
            'hours' => ['Hrs', 2, '2031-03-10 23:30:00', '2031-03-11 01:30:00'],
            'minutes' => ['Mins', 45, '2031-03-10 23:30:00', '2031-03-11 00:15:00'],
            'period' => ['Period', 1, '2031-03-20 23:59:59', '2031-04-20 23:59:00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('renewals')]
    public function testRenewalExtendsFromTheCurrentExpiry(string $unit, int $n, string $current, string $expected): void
    {
        $zone = new \DateTimeZone('Asia/Baghdad');
        [$start, $exp] = ServicePeriod::compute('RENEW', ['validity' => $n, 'validity_unit' => $unit],
            ['expiration' => substr($current, 0, 10), 'time' => substr($current, 11)], new \DateTimeImmutable('2026-10-02 10:00:00', $zone), 20);
        $this->assertSame($expected, $exp->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 10:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame($expected, self::legacyExtend($unit, $n, $current, 'Asia/Baghdad'), 'parity with the legacy same-plan extension');
    }

    public function testPeriodDayIsClampedAndInvalidValidityRefused(): void
    {
        $zone = new \DateTimeZone('UTC');
        [, $exp] = ServicePeriod::compute('RENEW', ['validity' => 1, 'validity_unit' => 'Period'], ['expiration' => '2031-01-15', 'time' => '23:59:59'], new \DateTimeImmutable('now', $zone), 31);
        $this->assertSame('2031-02-28 23:59:00', $exp->format('Y-m-d H:i:s'), 'the legacy code wrote 2031-02-31; WP5A clamps to the month');
        foreach ([['validity' => 0, 'validity_unit' => 'Days'], ['validity' => 1, 'validity_unit' => 'Weeks'], ['validity' => 1]] as $plan) {
            try {
                ServicePeriod::compute('NEW', $plan, null, new \DateTimeImmutable('now', $zone));
                $this->fail('invalid validity accepted');
            } catch (DomainError $e) {
                $this->assertSame('PLAN_VALIDITY_INVALID', $e->reason);
            }
        }
    }

    public function testDerivedAction(): void
    {
        $now = new \DateTimeImmutable('2026-10-02 10:00:00', new \DateTimeZone('Asia/Baghdad'));
        $row = static fn(int $plan, string $status, string $date, string $time = '00:00:00'): array => ['plan_id' => $plan, 'status' => $status, 'expiration' => $date, 'time' => $time];
        $this->assertSame('NEW', ActivationService::deriveAction(null, 7, $now));
        $this->assertSame('RENEW', ActivationService::deriveAction($row(7, 'on', '2026-10-02', '10:00:01'), 7, $now), 'same plan id, still active');
        $this->assertSame('REACTIVATE', ActivationService::deriveAction($row(7, 'on', '2026-10-02', '10:00:00'), 7, $now), 'expired at this instant');
        $this->assertSame('REACTIVATE', ActivationService::deriveAction($row(7, 'off', '2030-01-01'), 7, $now), 'deactivated');
        $this->assertSame('CHANGE_PLAN', ActivationService::deriveAction($row(8, 'on', '2030-01-01'), 7, $now));
        $this->assertSame('CHANGE_PLAN', ActivationService::deriveAction($row(8, 'off', '2020-01-01'), 7, $now), 'another plan, whatever its state');
    }

    /** The legacy fresh-period expressions of Package::rechargeUser (copied for parity). */
    private static function legacyFresh(string $unit, int $n, string $now, string $tz): string
    {
        $prev = date_default_timezone_get();
        date_default_timezone_set($tz);
        try {
            $t = strtotime($now);
            if ($unit === 'Months') {
                return date('Y-m-d', strtotime('+' . $n . ' month', $t)) . ' ' . date('H:i:s', $t);
            }
            if ($unit === 'Period') {
                $current = new \DateTime(date('Y-m-d', $t));
                $exp = clone $current;
                $exp->modify('first day of next month');
                $exp->setDate((int) $exp->format('Y'), (int) $exp->format('m'), 20);
                while ($exp->diff($current)->days < 7 * $n) { $exp->modify('+1 month'); }
                while ($exp->diff($current)->days > 35 * $n) { $exp->modify('-1 month'); }
                if ($exp->diff($current)->days < 7 * $n || $exp <= $current) { $exp->modify('+1 month'); }
                if ($n > 1) { $exp->modify('+' . ($n - 1) . ' months'); }
                return $exp->format('Y-m-d') . ' 23:59:59';
            }
            $word = ['Days' => 'day', 'Hrs' => 'hour', 'Mins' => 'minute'][$unit];
            return date('Y-m-d H:i:s', strtotime('+' . $n . ' ' . $word, $t));
        } finally {
            date_default_timezone_set($prev);
        }
    }

    /** The legacy same-plan extension expressions of Package::rechargeUser (copied for parity). */
    private static function legacyExtend(string $unit, int $n, string $current, string $tz): string
    {
        $prev = date_default_timezone_get();
        date_default_timezone_set($tz);
        try {
            [$date, $time] = explode(' ', $current);
            return match ($unit) {
                'Months' => date('Y-m-d', strtotime($date . ' +' . $n . ' months')) . ' ' . $time,
                'Period' => date('Y-m-20', strtotime($date . ' +' . $n . ' months')) . ' 23:59:00',
                'Days' => date('Y-m-d', strtotime($date . ' +' . $n . ' days')) . ' ' . $time,
                'Hrs' => date('Y-m-d H:i:s', strtotime($date . ' ' . $time . ' +' . $n . ' hours')),
                'Mins' => date('Y-m-d H:i:s', strtotime($date . ' ' . $time . ' +' . $n . ' minutes')),
            };
        } finally {
            date_default_timezone_set($prev);
        }
    }
}
