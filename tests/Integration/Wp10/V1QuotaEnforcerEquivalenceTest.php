<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\QuotaEnforcer;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * Capacity (TA-09): QuotaEnforcer::overQuota() became set-based (one grouped query per quota kind and ownership rule instead of one
 * query per subscriber). Differential test: on randomised data - valid, malformed, duplicate, missing and impossible boundary rows,
 * quota rows of every shape, open / closed sessions inside and outside the period, 64-bit values - it returns exactly what the previous
 * per-subscriber implementation (kept below as the reference) returns.
 */
final class V1QuotaEnforcerEquivalenceTest extends ActivationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        static::$db->exec("DELETE FROM radcheck WHERE username LIKE 'eqv%'");
        static::$db->exec("DELETE FROM radacct WHERE username LIKE 'eqv%'");
    }

    /** the implementation before the set-based rewrite (verbatim apart from being static) */
    private static function referenceOverQuota(\PDO $radius): array
    {
        // N2: an accounting-row id beyond the table's end cannot have been produced by it (a restore of an older table): ignored
        $maxId = (int) $radius->query('SELECT IFNULL(MAX(radacctid), 0) FROM radacct')->fetchColumn();
        $rows = $radius->query("SELECT q.username AS u, q.attribute AS a, q.value AS v, IFNULL(p.value, '0') AS t, i.value AS i FROM radcheck q
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
            $st = $radius->prepare("SELECT CAST(u.s AS CHAR) AS s, u.s >= CAST(? AS DECIMAL(20,0)) AS reached FROM (SELECT IFNULL(SUM({$expr}), 0) AS s FROM radacct
                WHERE username = ? AND {$owned}) AS u");
            $st->execute([(string) $r['v'], $r['u'], $ownedArg]);
            $m = $st->fetch(\PDO::FETCH_ASSOC);
            $usage = (string) $m['s'];
            if ((int) $m['reached'] !== 1) {
                continue;
            }
            $open = $radius->prepare("SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NULL AND {$owned} ORDER BY radacctid");
            $open->execute([$r['u'], $ownedArg]);
            $sessions = $open->fetchAll(\PDO::FETCH_ASSOC);
            if ($sessions !== []) {
                $out[] = ['username' => (string) $r['u'], 'quota' => (string) $r['a'], 'limit' => (string) $r['v'], 'usage' => $usage, 'period_start' => (int) $r['t'], 'sessions' => $sessions];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> comparable form */
    private static function normal(array $over): array
    {
        $out = array_map(static fn(array $o): array => ['u' => $o['username'], 'q' => $o['quota'], 'l' => $o['limit'], 'use' => $o['usage'], 'p' => $o['period_start'],
            's' => array_map(static fn($s) => (int) $s['radacctid'], $o['sessions'])], $over);
        // the previous implementation listed an identical duplicate boundary row twice; the set is what matters
        $out = array_values(array_map('unserialize', array_unique(array_map('serialize', $out))));
        usort($out, static fn($a, $b) => [$a['u'], $a['q'], $a['l'], $a['p'], $a['use']] <=> [$b['u'], $b['q'], $b['l'], $b['p'], $b['use']]);
        return $out;
    }

    private function seed(int $seed, int $users): void
    {
        mt_srand($seed);
        $pick = static fn(array $a) => $a[mt_rand(0, count($a) - 1)];
        $maxBefore = (int) static::$db->value('SELECT IFNULL(MAX(radacctid), 0) FROM radacct');
        for ($n = 0; $n < $users; $n++) {
            $u = sprintf('eqv%d_%03d', $seed, $n);
            // accounting rows: 0-4 sessions, some open, some old (before the boundary), mixed octets / times
            $ids = [];
            for ($r = 0, $rows = mt_rand(0, 4); $r < $rows; $r++) {
                $open = mt_rand(0, 2) === 0;
                $ids[] = static::$db->insertRow('radacct', ['acctsessionid' => 'eq' . bin2hex(random_bytes(3)), 'acctuniqueid' => bin2hex(random_bytes(16)), 'username' => $u,
                    'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - mt_rand(0, 7200)), 'acctupdatetime' => gmdate('Y-m-d H:i:s', time() - 5),
                    'acctstoptime' => $open ? null : gmdate('Y-m-d H:i:s', time() - 1), 'acctsessiontime' => $pick([0, 30, 600, 3600, 4000000000]),
                    'acctinputoctets' => $pick([0, 1, 500, 100000, 3000000000]), 'acctoutputoctets' => $pick([0, 5, 700, 200000, 4000000000]), 'framedipaddress' => '10.60.0.1']);
            }
            // quota rows (0-2, so duplicates occur), of every shape
            for ($q = 0, $nq = mt_rand(0, 2); $q < $nq; $q++) {
                static::$db->insertRow('radcheck', ['username' => $u, 'attribute' => $pick(['Max-Data', 'Max-Data', 'Max-All-Session']), 'op' => ':=',
                    'value' => (string) $pick(['0', '1', '1000', '400000', '5000000000', '99999999999999999999', '18446744073709551615', 'abc', '12x', '', "123\n", '007', '1e3'])]);
            }
            // boundary rows: start (0-2), id (0-2); valid, malformed, impossible (beyond the table), duplicate
            for ($b = 0, $nb = mt_rand(0, 2); $b < $nb; $b++) {
                static::$db->insertRow('radcheck', ['username' => $u, 'attribute' => 'Nuxr-Period-Start', 'op' => ':=', 'value' => (string) $pick([(string) (time() - 3600), '1', '0', (string) (time() + 3600), 'x1', '', "55\n", '99999999999999'])]);
            }
            for ($b = 0, $nb = mt_rand(0, 2); $b < $nb; $b++) {
                $value = $ids !== [] && mt_rand(0, 2) === 0 ? (string) ($ids[mt_rand(0, count($ids) - 1)] - 1) : (string) $pick([(string) $maxBefore, '0', '9000000000', '12x', '', "7\n", '1234567890123456789', (string) ($maxBefore + 100000)]);
                static::$db->insertRow('radcheck', ['username' => $u, 'attribute' => 'Nuxr-Period-Acct-Id', 'op' => ':=', 'value' => $value]);
            }
        }
    }

    public function testSetBasedOverQuotaEqualsTheReferenceOnRandomData(): void
    {
        $pdo = static::$db->pdo;
        $total = 0;
        foreach ([11, 23, 37, 41, 59, 71] as $seed) {
            $this->seed($seed, 160);
            $ref = self::normal(self::referenceOverQuota($pdo));
            $new = self::normal((new QuotaEnforcer($pdo, static fn(array $r): array => []))->overQuota());
            $this->assertSame($ref, $new, "seed {$seed}: the set-based enforcer reports exactly what the per-subscriber one did");
            $total += count($ref);
        }
        $this->assertGreaterThan(40, $total, 'the random data produced over-quota subscribers (the comparison is not vacuous)');
    }
}
