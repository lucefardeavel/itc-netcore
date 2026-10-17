<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * V1 plan quota contract (owner QUOTA_V1_GB_SUPPORT_APPROVED, 2026-10-17). Pure: no database, no clock.
 *
 * A Limited plan (Hotspot or PPPoE) carries a time quota (minutes / hours of session time per billing period), a data
 * quota (MB / GB, decimal, download + upload per billing period) or both. A Limited plan whose limit is zero, negative,
 * non-numeric or in an unknown unit is invalid: it is refused when saved and never provisioned (a 0 quota would lock the
 * subscriber out; a dropped quota would sell an unlimited service). Unlimited plans ignore the limit fields.
 */
final class QuotaPolicy
{
    public const LIMIT_TYPES = ['Time_Limit', 'Data_Limit', 'Both_Limit'];

    /** default interim accounting requested from the NAS for a data-quota session (input of the accounting enforcer) */
    public const INTERIM_SECONDS = 60;
    public const INTERIM_MIN = 10;
    public const INTERIM_MAX = 300;

    /**
     * N1: the interim interval of a data-quota session in seconds - the setting `nuxr_quota_interim_seconds` (10 - 300) or the
     * default 60. A shorter interval lowers the overshoot bound (detection latency = interval + enforcer pass + Disconnect) and
     * raises the accounting write rate (one Interim-Update per online quota subscriber per interval). Applies to sessions
     * that authenticate after the policy was written; a running session keeps its interval.
     */
    public static function interim(): int
    {
        $v = $GLOBALS['config']['nuxr_quota_interim_seconds'] ?? null;
        return (is_int($v) || (is_string($v) && preg_match('/^[0-9]{1,3}$/D', $v) === 1)) && (int) $v >= self::INTERIM_MIN && (int) $v <= self::INTERIM_MAX
            ? (int) $v : self::INTERIM_SECONDS;
    }

    /**
     * Allowance for the NAS clock when a session is attributed to a billing period (acctstarttime >= boundary).
     * acctstarttime is the NAS's integer-second Event-Timestamp; measured on RouterOS CHR 7.24.5 it is 1.1 - 1.7 s EARLIER
     * than the host's receive time, so a login within ~2 s of the period start (a PPPoE client redials right after the
     * renewal Disconnect) would be booked to the PREVIOUS period and escape the new quota. The stored boundary
     * (Nuxr-Period-Start) is the period start minus this many seconds. Cost: a session that started up to this many
     * seconds BEFORE a renewal is also counted in the new period (its end is forced by the renewal, so at most this many
     * seconds of its traffic). The NAS clock must be within this allowance (NTP): production checklist H8.
     */
    public const PERIOD_SKEW_SECONDS = 10;

    /** the stored attribution boundary (UNIX seconds) for a billing period starting at $periodStart; 0 = since the beginning */
    public static function boundary(?int $periodStart): int
    {
        return $periodStart === null ? 0 : max(0, $periodStart - self::PERIOD_SKEW_SECONDS);
    }

    public static function limited(array $plan): bool
    {
        return ($plan['typebp'] ?? '') === 'Limited';
    }

    public static function hasTime(array $plan): bool
    {
        return self::limited($plan) && in_array($plan['limit_type'] ?? '', ['Time_Limit', 'Both_Limit'], true);
    }

    public static function hasData(array $plan): bool
    {
        return self::limited($plan) && in_array($plan['limit_type'] ?? '', ['Data_Limit', 'Both_Limit'], true);
    }

    /** null when the plan's quota is valid (or the plan is unlimited), otherwise the problem code */
    public static function problem(array $plan): ?string
    {
        if (!self::limited($plan)) {
            return null;
        }
        if (!in_array($plan['limit_type'] ?? '', self::LIMIT_TYPES, true)) {
            return 'LIMIT_TYPE_INVALID';
        }
        // a missing unit keeps the legacy default (minutes / MB: the database columns are nullable); a wrong unit is refused
        if (self::hasTime($plan) && (!self::positive($plan['time_limit'] ?? null) || !in_array($plan['time_unit'] ?? '', ['', 'Mins', 'Hrs'], true))) {
            return 'TIME_LIMIT_INVALID';
        }
        if (self::hasData($plan) && (!self::positive($plan['data_limit'] ?? null) || !in_array($plan['data_unit'] ?? '', ['', 'MB', 'GB'], true))) {
            return 'DATA_LIMIT_INVALID';
        }
        return null;
    }

    /** the time quota in seconds (valid plans only) */
    public static function seconds(array $plan): int
    {
        return (int) $plan['time_limit'] * (($plan['time_unit'] ?? '') === 'Hrs' ? 3600 : 60);
    }

    /** the data quota in bytes, decimal units as the legacy device used them (valid plans only) */
    public static function bytes(array $plan): int
    {
        return (int) $plan['data_limit'] * (($plan['data_unit'] ?? '') === 'GB' ? 1_000_000_000 : 1_000_000);
    }

    private static function positive(mixed $v): bool
    {
        return (is_int($v) && $v > 0 && $v < 1_000_000_000) || (is_string($v) && preg_match('/^[1-9][0-9]{0,8}$/D', $v) === 1);
    }
}
