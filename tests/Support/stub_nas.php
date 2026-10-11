<?php
// CLI-only WP4 test NAS (development/test only; never part of a deployment). Listens on a UDP port for one
// RADIUS Disconnect / CoA request and answers per mode, then logs what it received (JSON) and exits.
// The log is PUBLISHED ATOMICALLY (temporary file + rename() in the same directory, WP8 TI-02): a reader sees either
// no log or the complete JSON document, never a partial one. The protocol order is unchanged: answer, then record.
//   php stub_nas.php <port> <secret> <mode: ack|nak|nak503|silent|badauth> <logfile>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('NUXR_RUNTIME', true);
require dirname(__DIR__, 2) . '/system/autoload/Reseller/Radius/RadiusPacket.php';

use Reseller\Radius\RadiusPacket;

[, $port, $secret, $mode, $log] = $argv;
function publish(string $log, mixed $doc): void
{
    $tmp = $log . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($doc));
    rename($tmp, $log);
}
/** UDP sockets bound to $port in this network namespace (/proc/net/udp + udp6), -1 if that cannot be read */
function udpSocketsOn(int $port): int
{
    $n = 0;
    foreach (['/proc/net/udp', '/proc/net/udp6'] as $i => $f) {
        $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            if ($i === 0) {
                return -1;
            }
            continue;
        }
        foreach (array_slice($lines, 1) as $l) {
            $local = preg_split('/\s+/', trim($l))[1] ?? '';
            if (hexdec(substr($local, strrpos($local, ':') + 1)) === $port) {
                $n++;
            }
        }
    }
    return $n;
}
// PHP does not reject an out-of-range port (it binds another one): refuse it as a bind failure
$server = (int) $port >= 1 && (int) $port <= 65535 ? @stream_socket_server('udp://127.0.0.1:' . (int) $port, $errno, $errstr, STREAM_SERVER_BIND) : false;
if ($server === false) {
    publish($log, ['error' => 'bind', 'errno' => $errno ?? 0]);
    exit(1);
}
// PHP sets SO_REUSEADDR on every stream socket, so a UDP port already bound by another such socket does NOT make the
// bind fail; require that this stub's socket is the only one on the port (WP8 TI-02), otherwise refuse it
if (($n = udpSocketsOn((int) $port)) !== 1) {
    fclose($server);
    publish($log, ['error' => $n < 0 ? 'cannot verify port' : ($n === 0 ? 'port_not_bound' : 'port_in_use'), 'sockets' => $n]);
    exit(1);
}
file_put_contents($log . '.ready', '1');
$received = [];
// silent: keep listening (and recording retransmissions) for 8 s; otherwise answer the first request
$deadline = microtime(true) + ($mode === 'silent' ? 8 : 20);
while (microtime(true) < $deadline) {
    $read = [$server];
    $write = $except = null;
    if (stream_select($read, $write, $except, 0, 200000) < 1) {
        continue;
    }
    $raw = stream_socket_recvfrom($server, 4096, 0, $peer);
    if ($raw === false || $raw === '') {
        continue;
    }
    $req = RadiusPacket::decode($raw);
    // request authenticator check (RFC 5176): MD5(code+id+len+16 zero+attrs+secret)
    $valid = hash_equals(md5(substr($raw, 0, 4) . str_repeat("\0", 16) . substr($raw, 20) . $secret, true), $req->authenticator);
    $received[] = ['code' => $req->code, 'id' => $req->identifier, 'auth' => bin2hex($req->authenticator), 'valid' => $valid, 'attributes' => array_map(static fn($a) => [$a[0], is_string($a[1]) && !mb_check_encoding($a[1], 'UTF-8') ? bin2hex($a[1]) : $a[1]], $req->attributes)];
    if ($mode === 'silent') {
        continue;
    }
    $ack = $req->code === RadiusPacket::DISCONNECT_REQUEST ? RadiusPacket::DISCONNECT_ACK : RadiusPacket::COA_ACK;
    $nak = $req->code === RadiusPacket::DISCONNECT_REQUEST ? RadiusPacket::DISCONNECT_NAK : RadiusPacket::COA_NAK;
    $code = in_array($mode, ['nak', 'nak503'], true) ? $nak : $ack;
    $attrs = $mode === 'nak503' ? chr(101) . chr(6) . pack('N', 503) : ($mode === 'nak' ? chr(101) . chr(6) . pack('N', 502) : '');
    $head = chr($code) . chr($req->identifier) . pack('n', 20 + strlen($attrs));
    $auth = md5($head . $req->authenticator . $attrs . ($mode === 'badauth' ? 'wrong-secret' : $secret), true);
    stream_socket_sendto($server, $head . $auth . $attrs, 0, $peer);
    break;
}
publish($log, $received);
