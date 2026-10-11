<?php
// WP8 diagnosis probe: does StubNas::received() lose what the stub received? (ack mode, repeated)
require '/repo/tests/Support/StubNas.php';
use Reseller\Tests\Support\StubNas;
$n = (int) ($argv[1] ?? 300); $lost = 0; $bindFail = 0; $ok = 0; $noReply = 0;
for ($i = 0; $i < $n; $i++) {
    $stub = new StubNas('probe-secret', 'ack');
    $s = stream_socket_client('udp://127.0.0.1:' . $stub->port, $e, $es);
    $auth = random_bytes(16);
    fwrite($s, chr(43) . chr($i % 256) . pack('n', 20) . $auth);       // a CoA-Request without attributes
    stream_set_timeout($s, 3);
    $reply = fread($s, 4096);
    fclose($s);
    if ($reply === '' || $reply === false) { $noReply++; }
    $got = $stub->received();
    if (isset($got['error'])) { $bindFail++; }
    elseif (!isset($got[0])) { $lost++; }
    else { $ok++; }
}
echo json_encode(['runs' => $n, 'ok' => $ok, 'lost_after_ack' => $lost, 'bind_fail' => $bindFail, 'no_reply' => $noReply]), "\n";
