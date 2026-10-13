<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Read-only access to the AUTHORITATIVE accounting history: the FreeRADIUS `radacct` table on the 'radius'
 * connection (06_RADIUS_CONTRACT "AccountingService"; D018). This class never writes, updates or deletes
 * radacct; open/stale sessions are reported, never "cleaned" (owner decision Q8: reconciliation actions
 * belong to WP5A).
 *
 * The local PHPNuxBill table `rad_acct` (default connection) is NOT accounting history: it is the per-session
 * counter state of the rlm_rest mode (see RestCounter) and is never used here.
 */
final class AccountingService
{
    /**
     * WP8 A4 accounting time contract (R-WP8-CARRY-03): FreeRADIUS writes acctstarttime / acctupdatetime / acctstoptime with
     * FROM_UNIXTIME() in its SQL session zone, and DATETIME keeps no zone. They are therefore read only as epoch seconds,
     * UNIX_TIMESTAMP() in this connection's session (same zone: neither side sets one, so both use the RADIUS database
     * server zone; UTC by the deployment contract), and never parsed as PHP-local strings. The *_epoch columns are
     * additive; the DATETIME columns stay as they were.
     */
    private const COLUMNS = 'radacctid, acctsessionid, acctuniqueid, username, nasipaddress, framedipaddress, acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets, callingstationid, '
        . 'UNIX_TIMESTAMP(acctstarttime) AS acctstart_epoch, UNIX_TIMESTAMP(acctupdatetime) AS acctupdate_epoch, UNIX_TIMESTAMP(acctstoptime) AS acctstop_epoch';

    /** A last update further in the future than this breaks the time contract (zone mismatch, clock skew): never fresh. */
    public const CLOCK_TOLERANCE_SECONDS = 300;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public static function connection(): self
    {
        return new self(\ORM::get_db('radius'));
    }

    /** @return list<array<string,mixed>> open sessions (no stop time) of one subscriber, oldest first */
    public function openSessions(string $username): array
    {
        return $this->rows('SELECT ' . self::COLUMNS . ' FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY radacctid', [$username]);
    }

    /** @return list<array<string,mixed>> newest sessions first */
    public function history(string $username, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return $this->rows('SELECT ' . self::COLUMNS . " FROM radacct WHERE username = ? ORDER BY radacctid DESC LIMIT {$limit}", [$username]);
    }

    /**
     * WP6A subscriber search (read only): usernames of OPEN sessions whose framed IP starts with $prefix (bounded).
     * The caller still restricts the matching subscribers by DataScope.
     *
     * @return list<string>
     */
    public function openSessionUsernamesByIp(string $prefix, int $limit = 50): array
    {
        if (!preg_match('/^\d{1,3}(\.\d{1,3}){0,3}\.?$/D', $prefix)) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        return array_map(static fn(array $r): string => (string) $r['username'],
            $this->rows("SELECT DISTINCT username FROM radacct WHERE acctstoptime IS NULL AND framedipaddress LIKE ? LIMIT {$limit}", [$prefix . '%']));
    }

    /**
     * WP6A Subscriber 360 (read only): the newest session of any of the subscriber's RADIUS usernames, or null.
     *
     * @param list<string> $usernames
     */
    public function lastSession(array $usernames): ?array
    {
        $usernames = array_values(array_unique(array_filter($usernames, static fn($u): bool => is_string($u) && $u !== '')));
        if ($usernames === []) {
            return null;
        }
        $in = implode(', ', array_fill(0, count($usernames), '?'));
        return $this->rows('SELECT ' . self::COLUMNS . " FROM radacct WHERE username IN ({$in}) ORDER BY radacctid DESC LIMIT 1", $usernames)[0] ?? null;
    }

    public function sessionCount(string $username): int
    {
        return (int) ($this->rows('SELECT COUNT(*) AS n FROM radacct WHERE username = ?', [$username])[0]['n'] ?? 0);
    }

    /** @return array{seconds:int,input_octets:int,output_octets:int} totals over the whole retained history */
    public function usage(string $username): array
    {
        $r = $this->rows('SELECT COALESCE(SUM(acctsessiontime), 0) AS s, COALESCE(SUM(acctinputoctets), 0) AS i, COALESCE(SUM(acctoutputoctets), 0) AS o FROM radacct WHERE username = ?', [$username])[0];
        return ['seconds' => (int) $r['s'], 'input_octets' => (int) $r['i'], 'output_octets' => (int) $r['o']];
    }

    /**
     * Open sessions whose last update (or start) is older than $staleAfterSeconds before the instant $now (its zone does
     * not matter): candidates for operator review or WP5A reconciliation. Report only. A missing or invalid time, or one
     * more than CLOCK_TOLERANCE_SECONDS in the future, is reported too (never treated as fresh).
     *
     * @return list<array<string,mixed>>
     */
    public function staleSessions(\DateTimeImmutable $now, int $staleAfterSeconds): array
    {
        $at = $now->getTimestamp();
        return $this->rows('SELECT ' . self::COLUMNS . ' FROM radacct WHERE acctstoptime IS NULL'
            . ' AND COALESCE(UNIX_TIMESTAMP(COALESCE(acctupdatetime, acctstarttime)), 0) NOT BETWEEN ? AND ? ORDER BY radacctid',
            [$at - max(60, $staleAfterSeconds), $at + self::CLOCK_TOLERANCE_SECONDS]);
    }

    /** a UNIX_TIMESTAMP() result as epoch seconds, or null when missing / invalid (NULL, 0 for a zero or out-of-range date) */
    public static function epoch(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^\d+(\.\d+)?$/D', $value)) {
            return null;
        }
        $e = (int) $value;
        return $e > 0 ? $e : null;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params): array
    {
        $st = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
