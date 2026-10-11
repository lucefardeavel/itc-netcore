<?php
// WP8 TI-02 probe (after the repair): same exchange as stubnas-race-probe.php; received() now throws instead of
// returning [] - every exception is counted, never hidden
require '/repo/tests/Support/StubNas.php';
use Reseller\Tests\Support\StubNas;
$n = (int) ($argv[1] ?? 300); $lost = 0; $exceptions = []; $ok = 0; $noReply = 0;
for ($i = 0; $i < $n; $i++) {
    try {
        $stub = new StubNas('probe-secret', 'ack');
        $s = stream_socket_client('udp://127.0.0.1:' . $stub->port, $e, $es);
        fwrite($s, chr(43) . chr($i % 256) . pack('n', 20) . random_bytes(16));
        stream_set_timeout($s, 3);
        $reply = fread($s, 4096);
        fclose($s);
        if ($reply === '' || $reply === false) { $noReply++; }
        $got = $stub->received();
        if (!isset($got[0])) { $lost++; } else { $ok++; }
    } catch (\Throwable $t) {
        $exceptions[] = $t->getMessage();
    }
}
echo json_encode(['runs' => $n, 'ok' => $ok, 'lost_after_ack' => $lost, 'exceptions' => count($exceptions), 'no_reply' => $noReply, 'exception_samples' => array_slice($exceptions, 0, 3)]), "\n";
