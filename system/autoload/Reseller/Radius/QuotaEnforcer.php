<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME) or the operator CLI; never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * V1 accounting quota enforcer (owner QUOTA_V1_GB_SUPPORT_APPROVED, 2026-10-17).
 *
 * The NAS limits every session at authentication (FreeRADIUS replies the remaining share: Hotspot total, PPPoE per
 * direction). What the NAS cannot see is closed here, from accounting:
 *  - PPPoE upload + download together reaching the data quota (RouterOS enforces only per direction);
 *  - several concurrent Hotspot sessions of one subscriber together reaching the data or time quota;
 * A subscriber whose usage in the CURRENT period (sessions started since Nuxr-Period-Start, the same rule as the
 * FreeRADIUS counters) has reached its Max-Data / Max-All-Session gets every open session of that period ended
 * (SessionTermination: Disconnect + confirmed Accounting-Stop). Overshoot is bounded by the interim interval
 * (QuotaPolicy::INTERIM_SECONDS), the pass interval and the Disconnect time - this is NOT a byte-exact cap.
 * Accounting history and quota rows are only read; nothing is deleted.
 */
final class QuotaEnforcer
{
    /** a session already sent a Disconnect is sent another one only after this many seconds */
    public const RESEND_SECONDS = 60;

    /** @var array<int,float> radacctid => last Disconnect time (this process) */
    private array $recent = [];

    public function __construct(private readonly \PDO $radius, private readonly ?\Closure $end = null)
    {
        $this->radius->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Subscribers with an open session whose current-period usage has reached a quota (read-only).
     *
     * @return list<array{username:string,quota:string,limit:string,usage:string,period_start:int,sessions:list<array<string,mixed>>}>
     */
    public function overQuota(): array
    {
        $rows = $this->radius->query("SELECT q.username AS u, q.attribute AS a, q.value AS v, IFNULL(p.value, '0') AS t FROM radcheck q
            LEFT JOIN radcheck p ON p.username = q.username AND p.attribute = 'Nuxr-Period-Start'
            WHERE q.attribute IN ('Max-Data', 'Max-All-Session') AND EXISTS (SELECT 1 FROM radacct a WHERE a.username = q.username AND a.acctstoptime IS NULL)
            ORDER BY q.username, q.attribute")->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            if (!preg_match('/^[0-9]{1,19}$/D', (string) $r['v']) || !preg_match('/^[0-9]{1,12}$/D', (string) $r['t'])) {
                continue; // a malformed quota row: FreeRADIUS refuses the login (fail closed); nothing to measure here
            }
            $expr = $r['a'] === 'Max-Data' ? 'IFNULL(acctinputoctets, 0) + IFNULL(acctoutputoctets, 0)' : 'IFNULL(acctsessiontime, 0)';
            // 64-bit arithmetic and the comparison in the database (no PHP integer / float rounding)
            $st = $this->radius->prepare("SELECT CAST(u.s AS CHAR) AS s, u.s >= CAST(? AS DECIMAL(20,0)) AS reached FROM (SELECT IFNULL(SUM({$expr}), 0) AS s FROM radacct
                WHERE username = ? AND acctstarttime >= FROM_UNIXTIME(?)) AS u");
            $st->execute([(string) $r['v'], $r['u'], (int) $r['t']]);
            $m = $st->fetch(\PDO::FETCH_ASSOC);
            $usage = (string) $m['s'];
            if ((int) $m['reached'] !== 1) {
                continue;
            }
            $open = $this->radius->prepare('SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NULL AND acctstarttime >= FROM_UNIXTIME(?) ORDER BY radacctid');
            $open->execute([$r['u'], (int) $r['t']]);
            $sessions = $open->fetchAll(\PDO::FETCH_ASSOC);
            if ($sessions !== []) {
                $out[] = ['username' => (string) $r['u'], 'quota' => (string) $r['a'], 'limit' => (string) $r['v'], 'usage' => $usage, 'period_start' => (int) $r['t'], 'sessions' => $sessions];
            }
        }
        return $out;
    }

    /**
     * One enforcement pass: ends the open sessions of every over-quota subscriber (a session is re-sent a Disconnect
     * only after RESEND_SECONDS). Returns what was done.
     *
     * @return list<array{username:string,quota:string,limit:string,usage:string,ended:list<array<string,mixed>>}>
     */
    public function pass(): array
    {
        $done = [];
        $now = microtime(true);
        foreach ($this->recent as $id => $t) {
            if ($now - $t >= self::RESEND_SECONDS) {
                unset($this->recent[$id]);
            }
        }
        foreach ($this->overQuota() as $o) {
            $todo = array_values(array_filter($o['sessions'], fn(array $s): bool => !isset($this->recent[(int) $s['radacctid']])));
            if ($todo === []) {
                continue;
            }
            foreach ($todo as $s) {
                $this->recent[(int) $s['radacctid']] = $now;
            }
            $ended = $this->end !== null ? ($this->end)($todo) : SessionTermination::end($todo);
            $done[] = ['username' => $o['username'], 'quota' => $o['quota'], 'limit' => $o['limit'], 'usage' => $o['usage'], 'ended' => $ended];
        }
        return $done;
    }
}
