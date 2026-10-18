<?php
namespace Reseller\Tests\Lab;

// WP10 ACCOUNTING LOAD (test tooling only). Run explicitly by dev/wp10/acct_load.sh on the disposable FreeRADIUS + database
// of the isolated nuxbill-reseller-test compose project. Never against a production service.
// Bounded by design: at most SLOTS requests in flight (one UDP socket each, far below the FreeRADIUS thread / SQL pool
// sizes), a hard rate ceiling, a short window, Accounting-Start / Interim-Update only (single-statement SQL, no
// admission or authentication traffic). It is NOT a pool-exhaustion test (the forbidden A2-R reproduction is never run);
// the run stops escalating as soon as a window loses more than MAX_LOSS of its requests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\QuotaEnforcer;
use Reseller\Radius\RadiusPacket;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RadiusClient;
use Reseller\Tests\Support\TestDatabase;

/**
 * N1 / TA-09 (lab stand-in): the accounting cost of a shorter Interim interval, measured end to end through the real
 * FreeRADIUS 3.2.8 (accounting -> SQL) on both engines, and the cost of the quota enforcer pass at the same scale.
 *  - online quota subscribers S, interim interval I: Interim-Updates per second = S / I (paced);
 *  - per window: request latency (p50 / p95 / p99 / max), losses, achieved rate, database counters, enforcer pass time.
 * Parameters (environment): ACCTLOAD_SUBS (comma list), ACCTLOAD_INTERIMS (comma list), ACCTLOAD_SECONDS, ACCTLOAD_PASS_EVERY.
 */
final class AcctLoadTest extends TestCase
{
    private const DB = 'nuxr_radius';
    private const SLOTS = 8;
    private const MAX_RATE = 260.0;          // requests / second, hard ceiling of the generator
    private const MAX_LOSS = 0.05;           // a window above this stops the escalation
    private const TIMEOUT = 2.0;
    private static \PDO $pdo;
    private static string $out;

    public static function setUpBeforeClass(): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        $admin = FixtureBuilder::connect($host, TestDatabase::rootPassword());
        $admin->exec('CREATE DATABASE IF NOT EXISTS `' . self::DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$pdo = FixtureBuilder::connect($host, TestDatabase::rootPassword(), self::DB);
        (new FixtureBuilder(dirname(__DIR__, 2)))->loadSqlFile(self::$pdo, dirname(__DIR__, 2) . '/install/radius.sql');
        RadiusClient::waitReady();
        self::$out = (getenv('ACCTLOAD_OUT') ?: '/tmp') . '/acct_load.jsonl';
    }

    private static function log(array $row): void
    {
        $line = json_encode($row + ['t' => gmdate('Y-m-d\TH:i:s\Z')], JSON_UNESCAPED_SLASHES);
        file_put_contents(self::$out, $line . "\n", FILE_APPEND);
        fwrite(STDERR, $line . "\n");
    }

    /** @return array<string,string> selected global status counters */
    private static function dbStatus(): array
    {
        $out = [];
        foreach (self::$pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Threads_connected','Max_used_connections','Innodb_row_lock_waits','Innodb_row_lock_time','Slow_queries','Aborted_connects')")->fetchAll(\PDO::FETCH_NUM) as [$k, $v]) {
            $out[$k] = (string) $v;
        }
        return $out;
    }

    /**
     * Sends $total accounting requests built by $make(i) at $rate / s with at most SLOTS in flight.
     *
     * @param \Closure(int):array $make attribute list of request i
     * @param (\Closure():void)|null $tick called about every 100 ms (the enforcer passes)
     * @return array{sent:int,ok:int,lost:int,lat:list<float>,seconds:float}
     */
    private static function pace(int $total, float $rate, \Closure $make, ?\Closure $tick = null): array
    {
        $rate = min($rate, self::MAX_RATE);
        $host = RadiusClient::host();
        $secret = RadiusClient::secret();
        $slots = [];
        for ($s = 0; $s < self::SLOTS; $s++) {
            $sock = stream_socket_client('udp://' . $host . ':1813', $e, $m, 2.0);
            stream_set_blocking($sock, false);
            $slots[$s] = ['sock' => $sock, 'req' => null, 't' => 0.0];
        }
        $start = microtime(true);
        $sent = $ok = $lost = 0;
        $lat = [];
        $nextTick = $start;
        while ($ok + $lost < $total) {
            $now = microtime(true);
            // due: how many requests should have been sent by now
            $due = min($total, (int) floor(($now - $start) * $rate) + 1);
            foreach ($slots as $s => &$slot) {
                if ($slot['req'] === null && $sent < $due) {
                    $req = RadiusPacket::request(RadiusPacket::ACCOUNTING_REQUEST, $sent % 256, [['NAS-IP-Address', '127.0.0.1'], ...$make($sent)], $secret);
                    fwrite($slot['sock'], $req->raw);
                    $slot['req'] = $req;
                    $slot['t'] = microtime(true);
                    $sent++;
                }
            }
            unset($slot);
            $read = [];
            foreach ($slots as $s => $slot) {
                if ($slot['req'] !== null) {
                    $read[$s] = $slot['sock'];
                }
            }
            if ($read !== []) {
                $w = null;
                $x = null;
                $r = $read;
                if (@stream_select($r, $w, $x, 0, 2000) > 0) {
                    foreach ($r as $sock) {
                        $s = array_search($sock, $read, true);
                        $raw = (string) fread($sock, 4096);
                        $dt = microtime(true) - $slots[$s]['t'];
                        $resp = $raw !== '' ? RadiusPacket::decode($raw) : null;
                        if ($resp !== null && $resp->code === RadiusPacket::ACCOUNTING_RESPONSE && RadiusPacket::verifyResponse($resp, $slots[$s]['req'], $secret)) {
                            $ok++;
                            $lat[] = $dt;
                        } else {
                            $lost++;
                        }
                        $slots[$s]['req'] = null;
                    }
                }
            } else {
                usleep(1000);
            }
            foreach ($slots as $s => &$slot) {
                if ($slot['req'] !== null && microtime(true) - $slot['t'] > self::TIMEOUT) {
                    $lost++;
                    $slot['req'] = null;
                }
            }
            unset($slot);
            if ($tick !== null && microtime(true) >= $nextTick) {
                $tick();
                $nextTick = microtime(true) + 0.1;
            }
        }
        foreach ($slots as $slot) {
            fclose($slot['sock']);
        }
        return ['sent' => $sent, 'ok' => $ok, 'lost' => $lost, 'lat' => $lat, 'seconds' => microtime(true) - $start];
    }

    private static function percentile(array $v, float $p): float
    {
        if ($v === []) {
            return 0.0;
        }
        sort($v);
        return round($v[(int) min(count($v) - 1, floor($p * count($v)))] * 1000, 1);
    }

    private static function user(int $i): string
    {
        return sprintf('lq%05d', $i);
    }

    /** @return array<string,mixed> the window's result row */
    private function window(int $subs, int $interim, int $seconds, int $passEvery): array
    {
        self::$pdo->exec("DELETE FROM radacct WHERE username LIKE 'lq%'");
        self::$pdo->exec("DELETE FROM radcheck WHERE username LIKE 'lq%'");
        // quota rows: 1% of the subscribers are already over their (tiny) quota, so the enforcer pass has work to do
        $over = max(1, (int) ($subs / 100));
        for ($o = 0; $o < $subs; $o += 500) {
            $rows = [];
            $args = [];
            for ($i = $o; $i < min($subs, $o + 500); $i++) {
                $rows[] = "(?, 'Max-Data', ':=', ?), (?, 'Nuxr-Period-Start', ':=', '1')";
                array_push($args, self::user($i), $i < $over ? '1000' : '50000000000', self::user($i));
            }
            self::$pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES ' . implode(',', $rows))->execute($args);
        }
        $sid = static fn(int $i): string => 'lqs' . $i;
        // Accounting-Start for every subscriber (paced), the sessions stay open
        $startRate = 120.0;
        $start = self::pace($subs, $startRate, static fn(int $i): array => [['Acct-Status-Type', 1], ['User-Name', self::user($i)], ['Acct-Session-Id', $sid($i)], ['NAS-Port', 1000 + $i],
            ['Framed-IP-Address', '10.50.' . intdiv($i, 250) % 250 . '.' . (2 + $i % 250)], ['Event-Timestamp', time()]]);
        $this->assertSame(0, $start['lost'], 'the Accounting-Start phase lost requests (the generator stops here)');
        $this->assertSame($subs, (int) self::$pdo->query("SELECT COUNT(*) FROM radacct WHERE username LIKE 'lq%' AND acctstoptime IS NULL")->fetchColumn());

        // the interim phase: S / I updates per second for $seconds
        $rate = $subs / $interim;
        $total = (int) round($rate * $seconds);
        $before = self::dbStatus();
        $enforcer = new QuotaEnforcer(self::$pdo, static fn(array $sessions): array => []);
        $pass = [];
        $nextPass = microtime(true);
        $tick = static function () use (&$pass, &$nextPass, $enforcer, $passEvery): void {
            if (microtime(true) >= $nextPass) {
                $t = microtime(true);
                $enforcer->pass();
                $pass[] = microtime(true) - $t;
                $nextPass = microtime(true) + $passEvery;
            }
        };
        $n = 0;
        $res = self::pace($total, $rate, static function (int $i) use ($subs, $interim, $sid): array {
            $u = $i % $subs;
            $round = intdiv($i, $subs) + 1;
            $time = $round * $interim;
            return [['Acct-Status-Type', 3], ['User-Name', self::user($u)], ['Acct-Session-Id', $sid($u)], ['NAS-Port', 1000 + $u],
                ['Framed-IP-Address', '10.50.' . intdiv($u, 250) % 250 . '.' . (2 + $u % 250)], ['Acct-Session-Time', $time], ['Acct-Input-Octets', $time * 10_000],
                ['Acct-Output-Octets', $time * 90_000], ['Event-Timestamp', time()]];
        }, $tick);
        $after = self::dbStatus();
        $delta = static fn(string $k): int => (int) $after[$k] - (int) $before[$k];
        $row = [
            'case' => 'acct-load', 'engine' => (string) getenv('WP1_DB_HOST'), 'subscribers' => $subs, 'interim_s' => $interim, 'target_rate_per_s' => round($rate, 1),
            'requests' => $res['sent'], 'ok' => $res['ok'], 'lost' => $res['lost'], 'loss' => $res['sent'] > 0 ? round($res['lost'] / $res['sent'], 4) : 0,
            'achieved_rate_per_s' => round($res['ok'] / max(0.001, $res['seconds']), 1), 'seconds' => round($res['seconds'], 1),
            'latency_ms' => ['p50' => self::percentile($res['lat'], 0.50), 'p95' => self::percentile($res['lat'], 0.95), 'p99' => self::percentile($res['lat'], 0.99), 'max' => self::percentile($res['lat'], 1.0)],
            'enforcer_passes' => count($pass), 'enforcer_pass_s' => ['avg' => $pass === [] ? 0 : round(array_sum($pass) / count($pass), 3), 'max' => $pass === [] ? 0 : round(max($pass), 3)],
            'enforcer_pass_every_s' => $passEvery,
            'db' => ['questions_per_s' => round($delta('Questions') / max(0.001, $res['seconds']), 1), 'row_lock_waits' => $delta('Innodb_row_lock_waits'), 'row_lock_time_ms' => $delta('Innodb_row_lock_time'),
                'slow_queries' => $delta('Slow_queries'), 'aborted_connects' => $delta('Aborted_connects'), 'threads_connected' => (int) $after['Threads_connected'], 'max_used_connections' => (int) $after['Max_used_connections']],
        ];
        // data is written: the last interim of each subscriber is the stored figure (spot check of 20 users)
        $chk = self::$pdo->prepare('SELECT acctsessiontime, acctoutputoctets FROM radacct WHERE username = ? AND acctstoptime IS NULL');
        $okRows = 0;
        for ($k = 0; $k < 20; $k++) {
            $chk->execute([self::user(intdiv($subs * $k, 20))]);
            $r = $chk->fetch(\PDO::FETCH_ASSOC);
            $okRows += $r !== false && (int) $r['acctsessiontime'] >= $interim ? 1 : 0;
        }
        $row['spot_check_updated'] = $okRows . '/20';
        self::log($row);
        return $row;
    }

    public function testBoundedAccountingLoad(): void
    {
        $subs = array_map('intval', explode(',', (string) (getenv('ACCTLOAD_SUBS') ?: '500,2000')));
        $interims = array_map('intval', explode(',', (string) (getenv('ACCTLOAD_INTERIMS') ?: '60,30')));
        $seconds = (int) (getenv('ACCTLOAD_SECONDS') ?: 40);
        $passEvery = (int) (getenv('ACCTLOAD_PASS_EVERY') ?: 5);
        self::log(['case' => 'start', 'engine' => (string) getenv('WP1_DB_HOST'), 'subs' => $subs, 'interims' => $interims, 'seconds' => $seconds, 'slots' => self::SLOTS, 'max_rate' => self::MAX_RATE]);
        $stopped = false;
        foreach ($subs as $s) {
            foreach ($interims as $i) {
                if ($stopped) {
                    self::log(['case' => 'skipped', 'subscribers' => $s, 'interim_s' => $i, 'reason' => 'an earlier window lost more than ' . self::MAX_LOSS]);
                    continue;
                }
                $r = $this->window($s, $i, $seconds, $passEvery);
                $this->assertSame(0, $r['db']['aborted_connects'], 'no refused connection');
                if ($r['loss'] > self::MAX_LOSS) {
                    $stopped = true;
                }
            }
        }
        self::$pdo->exec("DELETE FROM radacct WHERE username LIKE 'lq%'");
        self::$pdo->exec("DELETE FROM radcheck WHERE username LIKE 'lq%'");
        $this->assertTrue(true);
    }
}
