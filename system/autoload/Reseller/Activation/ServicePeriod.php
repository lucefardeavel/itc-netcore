<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;

/**
 * The service period of an activation (owner decision C14; A2). Pure: no database, no clock.
 *
 *  - RENEW (same plan id, still active) extends from the current expiry;
 *  - NEW, REACTIVATE and CHANGE_PLAN start now;
 *  - no proration;
 *  - the legacy validity-unit arithmetic of Package::rechargeUser is kept unit for unit (Months, Days, Hrs,
 *    Mins, Period with the "Expired Date" day). The legacy `extend_expiry` setting does not apply.
 *
 * Dates are wall-clock values in the application time zone (the legacy recharge rows and the RADIUS
 * Expiration use it); the zone of $now is used throughout. The caller freezes the result in the activation's
 * VALIDATED event, so a retry or a reconciliation never recomputes it.
 */
final class ServicePeriod
{
    public const UNITS = ['Months', 'Period', 'Days', 'Hrs', 'Mins'];

    /**
     * @param array<string,mixed>      $plan    tbl_plans row (validity, validity_unit)
     * @param array<string,mixed>|null $current the current recharge (expiration, time) for a RENEW
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} [start, expiry]
     */
    public static function compute(string $action, array $plan, ?array $current, \DateTimeImmutable $now, int $dayExp = 20): array
    {
        $unit = (string) ($plan['validity_unit'] ?? '');
        $n = (int) ($plan['validity'] ?? 0);
        if (!in_array($unit, self::UNITS, true) || $n < 1 || $n > 10000) {
            throw new DomainError('PLAN_VALIDITY_INVALID');
        }
        $dayExp = $dayExp >= 1 && $dayExp <= 31 ? $dayExp : 20;
        if ($action === 'RENEW') {
            if ($current === null) {
                throw new DomainError('ACTIVATION_STATE_CONFLICT');
            }
            $old = self::at((string) $current['expiration'], (string) ($current['time'] ?? ''), $now->getTimezone());
            return [$now, self::extend($unit, $n, $old, $dayExp)];
        }
        return [$now, self::fresh($unit, $n, $now, $dayExp)];
    }

    /** Legacy fresh-period arithmetic (Package::rechargeUser, new recharge). */
    private static function fresh(string $unit, int $n, \DateTimeImmutable $now, int $dayExp): \DateTimeImmutable
    {
        switch ($unit) {
            case 'Months':
                return $now->modify('+' . $n . ' month');
            case 'Days':
                return $now->modify('+' . $n . ' day');
            case 'Hrs':
                return $now->modify('+' . $n . ' hour');
            case 'Mins':
                return $now->modify('+' . $n . ' minute');
        }
        // Period: the "Expired Date" day of the next month, kept between 7n and 35n days away
        $current = $now->setTime(0, 0, 0);
        $exp = $current->modify('first day of next month');
        $exp = $exp->setDate((int) $exp->format('Y'), (int) $exp->format('m'), $dayExp);
        $min = 7 * $n;
        $max = 35 * $n;
        while ($exp->diff($current)->days < $min) {
            $exp = $exp->modify('+1 month');
        }
        while ($exp->diff($current)->days > $max) {
            $exp = $exp->modify('-1 month');
        }
        if ($exp->diff($current)->days < $min || $exp <= $current) {
            $exp = $exp->modify('+1 month');
        }
        if ($n > 1) {
            $exp = $exp->modify('+' . ($n - 1) . ' months');
        }
        return $exp->setTime(23, 59, 59);
    }

    /** Legacy same-plan extension arithmetic (Package::rechargeUser, active recharge of the same plan). */
    private static function extend(string $unit, int $n, \DateTimeImmutable $old, int $dayExp): \DateTimeImmutable
    {
        return match ($unit) {
            'Months' => $old->modify('+' . $n . ' months'),
            'Period' => (static function () use ($old, $n, $dayExp): \DateTimeImmutable {
                $m = $old->setTime(0, 0, 0)->modify('+' . $n . ' months');
                $day = min($dayExp, (int) $m->format('t'));
                return $m->setDate((int) $m->format('Y'), (int) $m->format('m'), $day)->setTime(23, 59, 0);
            })(),
            'Days' => $old->modify('+' . $n . ' days'),
            'Hrs' => $old->modify('+' . $n . ' hours'),
            'Mins' => $old->modify('+' . $n . ' minutes'),
        };
    }

    /** A legacy (expiration, time) pair in the given zone. */
    public static function at(string $date, string $time, \DateTimeZone $zone): \DateTimeImmutable
    {
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . ($time === '' ? '23:59:59' : $time), $zone);
        if ($at === false) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        return $at;
    }
}
