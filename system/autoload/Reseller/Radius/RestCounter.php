<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * The local PHPNuxBill `rad_acct` table (application database, default connection): per-session counter
 * state of the rlm_rest mode (radius.php). It is NOT the authoritative accounting history; that is the
 * FreeRADIUS `radacct` table (AccountingService). Owner decision Q9: keep, fix, document.
 *
 * One row per session (username + nasid + macaddr + acctsessionid). RFC 2866 Interim-Update and Stop carry
 * CUMULATIVE session counters, so a report SETS the session's values (the legacy code added every report,
 * counting the same traffic again and again). Usage = the sum of the subscriber's session rows; a plan
 * period ends with resetUsage() (zero, never delete). Status: Start and Interim-Update -> 'Start' (open),
 * Stop -> 'Stop'.
 */
final class RestCounter
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public static function connection(): self
    {
        return new self(\ORM::get_db());
    }

    /** @param array<string,string|int|null> $r accounting report fields (raw request values) */
    public function record(string $username, array $r, string $now): void
    {
        $key = [$username, (string) ($r['nasid'] ?? ''), (string) ($r['macaddr'] ?? ''), (string) ($r['acctsessionid'] ?? '')];
        $status = (string) ($r['acctstatustype'] ?? '');
        $values = [
            'realm' => (string) ($r['realm'] ?? ''),
            'nasipaddress' => substr((string) ($r['nasipaddress'] ?? ''), 0, 15),
            'nasportid' => $r['nasportid'] ?? null,
            'nasporttype' => $r['nasporttype'] ?? null,
            'framedipaddress' => substr((string) ($r['framedipaddress'] ?? ''), 0, 15),
            'acctsessiontime' => max(0, (int) ($r['acctsessiontime'] ?? 0)),
            'acctinputoctets' => max(0, (int) ($r['acctinputoctets'] ?? 0)),
            'acctoutputoctets' => max(0, (int) ($r['acctoutputoctets'] ?? 0)),
            'dateAdded' => $now,
        ];
        $st = $this->pdo->prepare('SELECT id FROM rad_acct WHERE BINARY username = ? AND nasid = ? AND macaddr = ? AND acctsessionid = ? ORDER BY id LIMIT 1');
        $st->execute($key);
        $id = $st->fetchColumn();
        $values['acctstatustype'] = $status === 'Stop' ? 'Stop' : 'Start';
        if ($id === false) {
            $cols = ['username', 'nasid', 'macaddr', 'acctsessionid', ...array_keys($values)];
            $this->pdo->prepare('INSERT INTO rad_acct (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute([...$key, ...array_values($values)]);
            return;
        }
        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($values)));
        $this->pdo->prepare("UPDATE rad_acct SET {$set} WHERE id = ?")->execute([...array_values($values), (int) $id]);
    }

    /** Bytes used in the current plan period (all session rows of the subscriber). */
    public function usage(string $username): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(acctinputoctets + acctoutputoctets), 0) FROM rad_acct WHERE BINARY username = ?');
        $st->execute([$username]);
        return (int) $st->fetchColumn();
    }

    /** @return list<string> framed addresses of the subscriber's open sessions */
    public function onlineAddresses(string $username): array
    {
        $st = $this->pdo->prepare("SELECT framedipaddress FROM rad_acct WHERE BINARY username = ? AND acctstatustype = 'Start' ORDER BY id");
        $st->execute([$username]);
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Plan period end: usage back to zero (rows are kept). */
    public function resetUsage(string $username): int
    {
        $st = $this->pdo->prepare('UPDATE rad_acct SET acctinputoctets = 0, acctoutputoctets = 0 WHERE BINARY username = ?');
        $st->execute([$username]);
        return $st->rowCount();
    }

    /** Marks open sessions without a report for $minutes as stopped (the legacy cron reaper, fixed). */
    public function reapStale(int $minutes, \DateTimeImmutable $now): int
    {
        $st = $this->pdo->prepare("UPDATE rad_acct SET acctstatustype = 'Stop' WHERE acctstatustype = 'Start' AND dateAdded <= ?");
        $st->execute([$now->modify('-' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s')]);
        return $st->rowCount();
    }
}
