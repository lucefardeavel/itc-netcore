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
    private const COLUMNS = 'radacctid, acctsessionid, acctuniqueid, username, nasipaddress, framedipaddress, acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets';

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
     * Open sessions whose last update (or start) is older than $staleAfterSeconds before $now: candidates for
     * operator review or WP5A reconciliation. Report only.
     *
     * @return list<array<string,mixed>>
     */
    public function staleSessions(\DateTimeImmutable $now, int $staleAfterSeconds): array
    {
        $cutoff = $now->modify('-' . max(60, $staleAfterSeconds) . ' seconds')->format('Y-m-d H:i:s');
        return $this->rows('SELECT ' . self::COLUMNS . ' FROM radacct WHERE acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) < ? ORDER BY radacctid', [$cutoff]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
