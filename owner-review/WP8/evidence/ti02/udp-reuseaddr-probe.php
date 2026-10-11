<?php
$p = 34567;
$a = stream_socket_server("udp://127.0.0.1:$p", $e, $es, STREAM_SERVER_BIND);
$b = @stream_socket_server("udp://127.0.0.1:$p", $e, $es, STREAM_SERVER_BIND);
echo "stream + stream on same UDP port: second bind ", $b ? "SUCCEEDS (SO_REUSEADDR)" : "fails", "\n";
if (function_exists('socket_create')) {
    $s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    $ok = @socket_bind($s, '127.0.0.1', $p);
    echo "ext/sockets bind without SO_REUSEADDR on that port: ", $ok ? "succeeds" : "fails (" . socket_strerror(socket_last_error($s)) . ")", "\n";
    $s2 = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    echo "ext/sockets bind on a free port: ", @socket_bind($s2, '127.0.0.1', $p + 1) ? "succeeds" : "fails", "\n";
    $c = @stream_socket_server("udp://127.0.0.1:" . ($p + 1), $e, $es, STREAM_SERVER_BIND);
    echo "stream bind after an exclusive ext/sockets bind: ", $c ? "SUCCEEDS" : "fails", "\n";
}
