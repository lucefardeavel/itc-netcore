<?php
// CLI-only WP4 test NAS (development/test only; never part of a deployment). Listens on a UDP port for one
// RADIUS Disconnect / CoA request and answers per mode, then logs what it received (JSON) and exits.
//   php stub_nas.php <port> <secret> <mode: ack|nak|nak503|silent|badauth> <logfile>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('NUXR_RUNTIME', true);
require dirname(__DIR__, 2) . '/system/autoload/Reseller/Radius/RadiusPacket.php';

use Reseller\Radius\RadiusPacket;

[, $port, $secret, $mode, $log] = $argv;
$server = stream_socket_server('udp://127.0.0.1:' . (int) $port, $errno, $errstr, STREAM_SERVER_BIND);
if ($server === false) {
    file_put_contents($log, json_encode(['error' => 'bind']));
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
file_put_contents($log, json_encode($received));
