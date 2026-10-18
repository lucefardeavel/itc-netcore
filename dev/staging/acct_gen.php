<?php
// TA-09 accounting generator for an APPROVED STAGING target (never production). CLI only, standalone (no PHPUnit, no Docker).
//
//   NUXR_STAGING_RADIUS_SECRET=... php dev/staging/acct_gen.php --host=<freeradius host> --subs=2000 --interim=30 --seconds=60 --out=acct_load.jsonl
//
// Sends Accounting-Start for N synthetic subscribers (tgt_NNNNN) and then Interim-Updates at subs / interim per second, with the same safeguards as the lab
// tool (dev/wp10/acct_load.sh): at most 8 requests in flight, a hard ceiling of 260 requests/s, accounting traffic only (no authentication, no admission SQL), a
// stop at the first 5 % loss. The secret is read from the ENVIRONMENT only (never an argument, never written). Output: one JSON line per window in the
// format dev/staging/ta_eval.py TA-09 reads (counters and timings only). Cleanup of the synthetic rows: dev/staging/seed_quota_rows.sql (section 3).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('NUXR_RUNTIME', true);
require dirname(__DIR__, 2) . '/system/autoload/Reseller/Radius/RadiusPacket.php';

use Reseller\Radius\RadiusPacket;

$o = getopt('', ['host:', 'port::', 'subs:', 'interim:', 'seconds:', 'out:', 'nas::']);
$secret = (string) getenv('NUXR_STAGING_RADIUS_SECRET');
foreach (['host', 'subs', 'interim', 'seconds', 'out'] as $k) {
    if (!isset($o[$k])) {
        fwrite(STDERR, "missing --{$k}\n");
        exit(5);
    }
}
if ($secret === '') {
    fwrite(STDERR, "NUXR_STAGING_RADIUS_SECRET must be set in the environment\n");
    exit(5);
}
$host = (string) $o['host'];
$port = (int) ($o['port'] ?? 1813);
$subs = max(1, min(20000, (int) $o['subs']));
$interim = max(5, (int) $o['interim']);
$seconds = max(10, min(600, (int) $o['seconds']));
$nas = (string) ($o['nas'] ?? '192.0.2.1');   // a documentation address: the NAS-IP of the synthetic sessions, never a real router
const SLOTS = 8;
const MAX_RATE = 260.0;
const TIMEOUT = 2.0;

function user(int $i): string { return sprintf('tgt_%05d', $i); }

/** @return array{sent:int,ok:int,lost:int,lat:list<float>,seconds:float} */
function pace(string $host, int $port, string $secret, string $nas, int $total, float $rate, Closure $make): array
{
    $rate = min($rate, MAX_RATE);
    $slots = [];
    for ($s = 0; $s < SLOTS; $s++) {
        $sock = stream_socket_client("udp://{$host}:{$port}", $e, $m, 2.0);
        if ($sock === false) {
            fwrite(STDERR, "cannot open a UDP socket to {$host}:{$port}\n");
            exit(3);
        }
        stream_set_blocking($sock, false);
        $slots[$s] = ['sock' => $sock, 'req' => null, 't' => 0.0];
    }
    $start = microtime(true);
    $sent = $ok = $lost = 0;
    $lat = [];
    while ($ok + $lost < $total) {
        $due = min($total, (int) floor((microtime(true) - $start) * $rate) + 1);
        foreach ($slots as &$slot) {
            if ($slot['req'] === null && $sent < $due) {
                $req = RadiusPacket::request(RadiusPacket::ACCOUNTING_REQUEST, $sent % 256, [['NAS-IP-Address', $nas], ...$make($sent)], $secret);
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
        if ($read === []) {
            usleep(1000);
            continue;
        }
        $w = $x = null;
        $r = $read;
        if (@stream_select($r, $w, $x, 0, 2000) > 0) {
            foreach ($r as $sock) {
                $s = array_search($sock, $read, true);
                $raw = (string) fread($sock, 4096);
                $resp = $raw !== '' ? RadiusPacket::decode($raw) : null;
                if ($resp !== null && $resp->code === RadiusPacket::ACCOUNTING_RESPONSE && RadiusPacket::verifyResponse($resp, $slots[$s]['req'], $secret)) {
                    $ok++;
                    $lat[] = microtime(true) - $slots[$s]['t'];
                } else {
                    $lost++;
                }
                $slots[$s]['req'] = null;
            }
        }
        foreach ($slots as &$slot) {
            if ($slot['req'] !== null && microtime(true) - $slot['t'] > TIMEOUT) {
                $lost++;
                $slot['req'] = null;
            }
        }
        unset($slot);
        if ($lost > max(5, 0.05 * $total)) {
            fwrite(STDERR, "STOP: more than 5 % of the requests were lost\n");
            break;
        }
    }
    return ['sent' => $sent, 'ok' => $ok, 'lost' => $lost + max(0, $total - $ok - $lost), 'lat' => $lat, 'seconds' => microtime(true) - $start];
}

function pct(array $v, float $p): float
{
    if ($v === []) {
        return 0.0;
    }
    sort($v);
    return round($v[(int) min(count($v) - 1, floor($p * count($v)))] * 1000, 1);
}

$ip = static fn(int $i): string => '10.99.' . (intdiv($i, 250) % 250) . '.' . (2 + $i % 250);
$start = pace($host, $port, $secret, $nas, $subs, 120.0, static fn(int $i): array => [['Acct-Status-Type', 1], ['User-Name', user($i)], ['Acct-Session-Id', 'tgs' . $i], ['NAS-Port', 1000 + $i],
    ['Framed-IP-Address', $ip($i)], ['Event-Timestamp', time()]]);
$rate = $subs / $interim;
$res = pace($host, $port, $secret, $nas, (int) round($rate * $seconds), $rate, static function (int $i) use ($subs, $interim, $ip): array {
    $u = $i % $subs;
    $time = (intdiv($i, $subs) + 1) * $interim;
    return [['Acct-Status-Type', 3], ['User-Name', user($u)], ['Acct-Session-Id', 'tgs' . $u], ['NAS-Port', 1000 + $u], ['Framed-IP-Address', $ip($u)], ['Acct-Session-Time', $time],
        ['Acct-Input-Octets', $time * 10000], ['Acct-Output-Octets', $time * 90000], ['Event-Timestamp', time()]];
});
$row = ['case' => 'acct-load', 'engine' => 'target', 'subscribers' => $subs, 'interim_s' => $interim, 'target_rate_per_s' => round($rate, 1), 'requests' => $res['sent'], 'ok' => $res['ok'], 'lost' => $res['lost'] + $start['lost'],
    'achieved_rate_per_s' => round($res['ok'] / max(0.001, $res['seconds']), 1), 'seconds' => round($res['seconds'], 1),
    'latency_ms' => ['p50' => pct($res['lat'], 0.5), 'p95' => pct($res['lat'], 0.95), 'p99' => pct($res['lat'], 0.99), 'max' => pct($res['lat'], 1.0)],
    // the enforcer pass time and the database counters are added by the operator (quota.php report timing; SHOW GLOBAL STATUS), see TA-09
    'enforcer_pass_s' => ['avg' => 0, 'max' => 0], 'db' => ['aborted_connects' => 0, 'questions_per_s' => 0, 'row_lock_waits' => 0, 'max_used_connections' => 0], 't' => gmdate('Y-m-d\TH:i:s\Z')];
file_put_contents((string) $o['out'], json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
exit($row['lost'] === 0 ? 0 : 1);
