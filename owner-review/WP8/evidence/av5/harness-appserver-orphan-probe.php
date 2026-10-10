<?php
$dir = sys_get_temp_dir() . '/srvprobe'; @mkdir($dir); file_put_contents("$dir/index.php", "<?php echo 'APP';");
$port = 23456;
$p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
usleep(800000);
echo "before stop: ", @file_get_contents("http://127.0.0.1:$port/"), "\n";
proc_terminate($p); proc_close($p);
unlink("$dir/index.php"); rmdir($dir);
usleep(500000);
$s = @fsockopen('127.0.0.1', $port);
echo "port still accepting after stop+delete: ", $s ? 'YES' : 'no', "\n";
if ($s) { fclose($s); $r = @file_get_contents("http://127.0.0.1:$port/?_route=admin"); echo "response: ", var_export(substr((string)$r, 0, 40), true), " ", ($http_response_header[0] ?? ''), "\n"; }
echo "php -S processes left: ", trim(shell_exec("ps -eo args | grep -c '[p]hp -S 127.0.0.1:$port'") ?? '?'), "\n";
