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
 * A subscriber whose usage in the CURRENT period (the same ownership rule as the FreeRADIUS counters: the rows that arrived
 * after Nuxr-Period-Acct-Id - exact, independent of the NAS clock - or, without a usable id, the sessions started since
 * Nuxr-Period-Start) has reached its Max-Data / Max-All-Session gets every open session of that period ended
 * (SessionTermination: Disconnect + confirmed Accounting-Stop). Overshoot is bounded by the interim interval
 * (QuotaPolicy::interim()), the pass interval and the Disconnect time - this is NOT a byte-exact cap.
 * Accounting history and quota rows are only read; nothing is deleted.
 */
final class QuotaEnforcer
{
    /**
     * A session whose Disconnect got a definite answer that did not end it (a NAK: the NAS no longer has the session - a lost
     * Stop) is sent another one only after this many seconds, so a vanished session is not asked about every pass.
     */
    public const RESEND_SECONDS = 60;

    /**
     * N1: a Disconnect that got no answer / no Accounting-Stop (a transient loss) is retried after this many seconds: the retry
     * delay is part of the overshoot bound under a fault.
     */
    public const RESEND_AFTER_NO_ANSWER = 15;

    /** outcomes treated as a transient loss (short retry) */
    private const TRANSIENT = ['COA_NO_RESPONSE', 'ACK_WITHOUT_STOP', 'COA_EXCEPTION', 'COA_NAS_SKIPPED'];

    /** @var array<int,array{0:float,1:int}> radacctid => [time of the last Disconnect (this process), seconds before the next] */
    private array $recent = [];

    /** @param (\Closure():float)|null $clock injectable time (tests) */
    public function __construct(private readonly \PDO $radius, private readonly ?\Closure $end = null, private readonly ?\Closure $clock = null)
    {
        $this->radius->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Subscribers with an open session whose current-period usage has reached a quota (read-only).
     *
     * Set-based (capacity, TA-09): one grouped query per quota kind and ownership rule, not one query per subscriber - the pass
     * time stays flat as the number of online quota subscribers grows. The rules are those of {@see QuotaUsage}: a quota row whose
     * value or period start is malformed is ignored here (FreeRADIUS refuses the login: fail closed); a boundary id that is
     * well-formed and not beyond the table's end owns the rows that arrived after it, otherwise the sessions started since the
     * time boundary; 64-bit sums and the comparison are done by the database (no PHP integer or float rounding).
     *
     * @return list<array{username:string,quota:string,limit:string,usage:string,period_start:int,sessions:list<array<string,mixed>>}>
     */
    public function overQuota(): array
    {
        // N2: an accounting-row id beyond the table's end cannot have been produced by it (a restore of an older table): ignored
        $maxId = (int) $this->radius->query('SELECT IFNULL(MAX(radacctid), 0) FROM radacct')->fetchColumn();
        $found = [];
        foreach (['Max-Data' => 'IFNULL(a.acctinputoctets, 0) + IFNULL(a.acctoutputoctets, 0)', 'Max-All-Session' => 'IFNULL(a.acctsessiontime, 0)'] as $attribute => $expr) {
            foreach ([true, false] as $byId) {
                // $byId: the period boundary is a usable row id; otherwise the time boundary decides
                // digits only: NOT REGEXP '[^0-9]' (a REGEXP '^[0-9]+$' would accept a trailing newline, the PHP readers do not)
                $usable = "(i.value IS NOT NULL AND CHAR_LENGTH(i.value) BETWEEN 1 AND 18 AND i.value NOT REGEXP '[^0-9]' AND CAST(i.value AS UNSIGNED) <= ?)";
                $owned = $byId ? 'a.radacctid > CAST(i.value AS UNSIGNED)' : 'a.acctstarttime >= FROM_UNIXTIME(CAST(IFNULL(p.value, \'0\') AS UNSIGNED))';
                $st = $this->radius->prepare("SELECT q.username AS u, q.value AS v, IFNULL(p.value, '0') AS t, i.value AS iv, CAST(SUM({$expr}) AS CHAR) AS s FROM radcheck q
                    LEFT JOIN (SELECT DISTINCT username, value FROM radcheck WHERE attribute = 'Nuxr-Period-Start') p ON p.username = q.username
                    LEFT JOIN (SELECT DISTINCT username, value FROM radcheck WHERE attribute = 'Nuxr-Period-Acct-Id') i ON i.username = q.username
                    JOIN radacct a ON a.username = q.username AND {$owned}
                    WHERE q.attribute = ? AND CHAR_LENGTH(q.value) BETWEEN 1 AND 19 AND q.value NOT REGEXP '[^0-9]'
                      AND CHAR_LENGTH(IFNULL(p.value, '0')) BETWEEN 1 AND 12 AND IFNULL(p.value, '0') NOT REGEXP '[^0-9]'
                      AND " . ($byId ? $usable : "NOT {$usable}") . "
                      AND EXISTS (SELECT 1 FROM radacct o WHERE o.username = q.username AND o.acctstoptime IS NULL)
                    GROUP BY q.id, q.username, q.value, p.value, i.value
                    HAVING SUM({$expr}) >= CAST(q.value AS DECIMAL(20,0))");
                $st->execute([$attribute, $maxId]);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $found[] = ['u' => (string) $r['u'], 'a' => $attribute, 'v' => (string) $r['v'], 't' => (int) $r['t'], 's' => (string) $r['s'], 'iv' => $byId ? (int) $r['iv'] : null];
                }
            }
        }
        usort($found, static fn(array $x, array $y): int => [$x['u'], $x['a'], $x['v']] <=> [$y['u'], $y['a'], $y['v']]);
        $out = [];
        foreach ($found as $r) {
            // the open sessions of the period (only subscribers over quota reach this point: a handful per pass)
            [$owned, $ownedArg] = $r['iv'] !== null ? ['radacctid > ?', $r['iv']] : ['acctstarttime >= FROM_UNIXTIME(?)', $r['t']];
            $open = $this->radius->prepare("SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NULL AND {$owned} ORDER BY radacctid");
            $open->execute([$r['u'], $ownedArg]);
            $sessions = $open->fetchAll(\PDO::FETCH_ASSOC);
            if ($sessions !== []) {
                $out[] = ['username' => $r['u'], 'quota' => $r['a'], 'limit' => $r['v'], 'usage' => $r['s'], 'period_start' => $r['t'], 'sessions' => $sessions];
            }
        }
        return $out;
    }

    /**
     * One enforcement pass: ends the open sessions of every over-quota subscriber in ONE termination call (the Stop wait is
     * shared, and a silent NAS is asked once); a session is re-sent a Disconnect after RESEND_AFTER_NO_ANSWER (no answer / no
     * Stop) or RESEND_SECONDS (any other outcome). Returns what was done.
     *
     * @return list<array{username:string,quota:string,limit:string,usage:string,ended:list<array<string,mixed>>}>
     */
    public function pass(): array
    {
        $now = $this->clock !== null ? ($this->clock)() : microtime(true);
        foreach ($this->recent as $id => [$t, $delay]) {
            if ($now - $t >= $delay) {
                unset($this->recent[$id]);
            }
        }
        $work = [];
        $all = [];
        foreach ($this->overQuota() as $o) {
            $todo = array_values(array_filter($o['sessions'], fn(array $s): bool => !isset($this->recent[(int) $s['radacctid']])));
            if ($todo === []) {
                continue;
            }
            foreach ($todo as $s) {
                $this->recent[(int) $s['radacctid']] = [$now, self::RESEND_SECONDS];
            }
            $work[] = [$o, $todo];
            array_push($all, ...$todo);
        }
        if ($all === []) {
            return [];
        }
        $results = [];
        foreach ($this->end !== null ? ($this->end)($all) : SessionTermination::end($all) as $e) {
            $id = (int) $e['radacctid'];
            $results[$id] = $e;
            $this->recent[$id] = [$now, in_array((string) ($e['state'] ?? ''), self::TRANSIENT, true) ? self::RESEND_AFTER_NO_ANSWER : self::RESEND_SECONDS];
        }
        $done = [];
        foreach ($work as [$o, $todo]) {
            $ended = [];
            foreach ($todo as $sess) {
                if (isset($results[(int) $sess['radacctid']])) {
                    $ended[] = $results[(int) $sess['radacctid']];
                }
            }
            $done[] = ['username' => $o['username'], 'quota' => $o['quota'], 'limit' => $o['limit'], 'usage' => $o['usage'], 'ended' => $ended];
        }
        return $done;
    }
}
