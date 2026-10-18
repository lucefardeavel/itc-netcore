<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME) or the operator CLI; never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Option G (owner V1_OWNER_DECISIONS_APPROVED, 2026-10-18): actual network usage and billable usage are different numbers.
 *
 * The accounting enforcer and the PPPoE per-direction caps cannot stop a session at the exact byte (N1), so the usage of a
 * period can end above its quota. That overshoot is reported, never charged and never carried over:
 *  - actual   = every octet / second the accounting rows OWNED by the current period recorded (raw accounting, unchanged);
 *  - billable = min(actual, quota): what the plan covers; no wallet, ledger, invoice or later period reads anything above it;
 *  - overshoot = max(actual - quota, 0): informational only (operators, support, capacity analysis).
 * The next period owns only the rows that arrive after it starts (PeriodBoundary), so the overshoot of the previous period
 * is not part of it. This class only READS radacct / radcheck; no finance class may use it (SourceGuard test).
 */
final class QuotaUsage
{
    public function __construct(private readonly \PDO $radius)
    {
        $this->radius->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * The current-period quota position of one subscriber: one entry per quota row (Max-Data in octets, Max-All-Session in
     * seconds). Empty = no quota row (unlimited plan, or not provisioned). Numbers are decimal strings (64-bit exact).
     *
     * @return list<array{quota:string,limit:string,actual:string,billable:string,overshoot:string,over:bool,period_start:int,period_acct_id:?int}>
     */
    public function current(string $username): array
    {
        $maxId = (int) $this->radius->query('SELECT IFNULL(MAX(radacctid), 0) FROM radacct')->fetchColumn();
        $st = $this->radius->prepare("SELECT q.attribute AS a, q.value AS v, IFNULL(p.value, '0') AS t, i.value AS i FROM radcheck q
            LEFT JOIN radcheck p ON p.username = q.username AND p.attribute = 'Nuxr-Period-Start'
            LEFT JOIN radcheck i ON i.username = q.username AND i.attribute = 'Nuxr-Period-Acct-Id'
            WHERE q.username = ? AND q.attribute IN ('Max-Data', 'Max-All-Session') ORDER BY q.attribute");
        $st->execute([$username]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (!preg_match('/^[0-9]{1,19}$/D', (string) $r['v']) || !preg_match('/^[0-9]{1,12}$/D', (string) $r['t'])) {
                continue; // malformed quota row: the login is refused (fail closed); there is nothing to report
            }
            $expr = $r['a'] === 'Max-Data' ? 'IFNULL(acctinputoctets, 0) + IFNULL(acctoutputoctets, 0)' : 'IFNULL(acctsessiontime, 0)';
            $acctId = $r['i'] !== null && preg_match('/^[0-9]{1,18}$/D', (string) $r['i']) === 1 && (int) $r['i'] <= $maxId ? (int) $r['i'] : null;
            [$owned, $arg] = $acctId !== null ? ['radacctid > ?', $acctId] : ['acctstarttime >= FROM_UNIXTIME(?)', (int) $r['t']];
            $q = $this->radius->prepare("SELECT CAST(u.s AS CHAR) AS actual, CAST(LEAST(u.s, CAST(? AS DECIMAL(20,0))) AS CHAR) AS billable,
                CAST(GREATEST(u.s - CAST(? AS DECIMAL(20,0)), 0) AS CHAR) AS overshoot FROM (SELECT IFNULL(SUM({$expr}), 0) AS s FROM radacct
                WHERE username = ? AND {$owned}) AS u");
            $q->execute([(string) $r['v'], (string) $r['v'], $username, $arg]);
            $m = $q->fetch(\PDO::FETCH_ASSOC);
            $out[] = ['quota' => (string) $r['a'], 'limit' => (string) $r['v'], 'actual' => (string) $m['actual'], 'billable' => (string) $m['billable'],
                'overshoot' => (string) $m['overshoot'], 'over' => (string) $m['overshoot'] !== '0', 'period_start' => (int) $r['t'], 'period_acct_id' => $acctId];
        }
        return $out;
    }
}
