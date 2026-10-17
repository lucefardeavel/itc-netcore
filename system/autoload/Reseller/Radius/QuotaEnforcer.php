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
     * @return list<array{username:string,quota:string,limit:string,usage:string,period_start:int,sessions:list<array<string,mixed>>}>
     */
    public function overQuota(): array
    {
        // N2: an accounting-row id beyond the table's end cannot have been produced by it (a restore of an older table): ignored
        $maxId = (int) $this->radius->query('SELECT IFNULL(MAX(radacctid), 0) FROM radacct')->fetchColumn();
        $rows = $this->radius->query("SELECT q.username AS u, q.attribute AS a, q.value AS v, IFNULL(p.value, '0') AS t, i.value AS i FROM radcheck q
            LEFT JOIN radcheck p ON p.username = q.username AND p.attribute = 'Nuxr-Period-Start'
            LEFT JOIN radcheck i ON i.username = q.username AND i.attribute = 'Nuxr-Period-Acct-Id'
            WHERE q.attribute IN ('Max-Data', 'Max-All-Session') AND EXISTS (SELECT 1 FROM radacct a WHERE a.username = q.username AND a.acctstoptime IS NULL)
            ORDER BY q.username, q.attribute")->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            if (!preg_match('/^[0-9]{1,19}$/D', (string) $r['v']) || !preg_match('/^[0-9]{1,12}$/D', (string) $r['t'])) {
                continue; // a malformed quota row: FreeRADIUS refuses the login (fail closed); nothing to measure here
            }
            $expr = $r['a'] === 'Max-Data' ? 'IFNULL(acctinputoctets, 0) + IFNULL(acctoutputoctets, 0)' : 'IFNULL(acctsessiontime, 0)';
            $acctId = $r['i'] !== null && preg_match('/^[0-9]{1,18}$/D', (string) $r['i']) === 1 && (int) $r['i'] <= $maxId ? (int) $r['i'] : null;
            [$owned, $ownedArg] = $acctId !== null ? ['radacctid > ?', $acctId] : ['acctstarttime >= FROM_UNIXTIME(?)', (int) $r['t']];
            // 64-bit arithmetic and the comparison in the database (no PHP integer / float rounding)
            $st = $this->radius->prepare("SELECT CAST(u.s AS CHAR) AS s, u.s >= CAST(? AS DECIMAL(20,0)) AS reached FROM (SELECT IFNULL(SUM({$expr}), 0) AS s FROM radacct
                WHERE username = ? AND {$owned}) AS u");
            $st->execute([(string) $r['v'], $r['u'], $ownedArg]);
            $m = $st->fetch(\PDO::FETCH_ASSOC);
            $usage = (string) $m['s'];
            if ((int) $m['reached'] !== 1) {
                continue;
            }
            $open = $this->radius->prepare("SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NULL AND {$owned} ORDER BY radacctid");
            $open->execute([$r['u'], $ownedArg]);
            $sessions = $open->fetchAll(\PDO::FETCH_ASSOC);
            if ($sessions !== []) {
                $out[] = ['username' => (string) $r['u'], 'quota' => (string) $r['a'], 'limit' => (string) $r['v'], 'usage' => $usage, 'period_start' => (int) $r['t'], 'sessions' => $sessions];
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
