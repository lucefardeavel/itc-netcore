<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * N2 (owner CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED): which accounting rows belong to a billing period.
 *
 * By the NAS clock they cannot be told exactly: the NAS stamps Accounting-Start up to ~2 s early (measured on RouterOS
 * 7.24.5) and its clock may drift, so a time boundary either misses the first session after a renewal or, with an allowance,
 * also counts a session that started just before it. The server-side arrival order is exact: a period that starts now owns
 * every accounting row whose id (radacct.radacctid) is larger than the table's highest id at that moment. That boundary is
 * stored as Nuxr-Period-Acct-Id next to the time boundary (Nuxr-Period-Start, the fallback for rows without an id).
 *
 * The id is paired with its period: a re-provision of the SAME period (password change, rename, re-sync - the time boundary
 * is unchanged) keeps the stored value and never resets the usage; a NEW period (renewal, plan change, reactivation) takes
 * the table's current end. A period provisioned before this change has no id and keeps the time rule until it ends.
 *
 * A session whose login was accepted just before the renewal but whose Start arrives just after it is counted in the new
 * period (conservative: it can only add usage); a row never moves between periods afterwards (a late Interim updates its own
 * row). Readers (the FreeRADIUS counters and share, the enforcer) use the id only when it is well-formed and not beyond the
 * table's end - an accounting table restored from an older backup falls back to the time rule instead of ignoring new rows.
 */
final class PeriodBoundary
{
    /** @return \Closure(string,int):?int for RadiusPolicyBuilder::build() */
    public static function resolver(RadiusRepository $repo, AccountingService $acct): \Closure
    {
        return static fn(string $username, int $timeBoundary): ?int => self::resolve($repo, $acct, $username, $timeBoundary);
    }

    /**
     * A NEW period (an activation: NEW, RENEW, CHANGE_PLAN, REACTIVATE) always takes the table's current end, even when its
     * start second equals the previous period's (two activations inside one second must not merge their periods: the
     * overshoot of the first would be carried into the second - owner decision N1 = A + G).
     *
     * @return \Closure(string,int):?int
     */
    public static function newPeriod(AccountingService $acct): \Closure
    {
        return static fn(string $username, int $timeBoundary): ?int => $acct->maxRowId();
    }

    /** {@see newPeriod()} on the configured 'radius' connection */
    public static function newPeriodForConnection(): \Closure
    {
        return static fn(string $username, int $timeBoundary): ?int => AccountingService::connection()->maxRowId();
    }

    /** the resolver on the configured 'radius' connection */
    public static function forConnection(): \Closure
    {
        return static fn(string $username, int $timeBoundary): ?int => self::resolve(RadiusRepository::connection(), AccountingService::connection(), $username, $timeBoundary);
    }

    /**
     * @param int $timeBoundary the time boundary the new policy will carry (QuotaPolicy::boundary of the period start)
     * @return ?int the id to store; null = store none (an existing period without one keeps the time rule)
     */
    public static function resolve(RadiusRepository $repo, AccountingService $acct, string $username, int $timeBoundary): ?int
    {
        $state = $repo->periodState($username);
        if ($state['start'] !== null && $state['start'] === (string) $timeBoundary) {
            // the same period: the boundary never moves
            return $state['acct'] !== null && preg_match('/^[0-9]{1,18}$/D', $state['acct']) === 1 ? (int) $state['acct'] : null;
        }
        return $acct->maxRowId();
    }
}
