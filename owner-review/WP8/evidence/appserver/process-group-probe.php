<?php
function stat_of(int $pid): ?array {
    $s = @file_get_contents("/proc/$pid/stat"); if ($s === false) return null;
    $r = substr($s, strrpos($s, ')') + 2); $f = explode(' ', $r);
    return ['state' => $f[0], 'ppid' => (int)$f[1], 'pgrp' => (int)$f[2], 'sid' => (int)$f[3]];
}
function group(int $pgid): array { $o = []; foreach (glob('/proc/[0-9]*') as $p) { $pid=(int)basename($p); $st = stat_of($pid); if ($st && $st['pgrp'] === $pgid && $st['state'] !== 'Z') $o[$pid] = $st; } return $o; }
$dir = sys_get_temp_dir() . '/sp'; @mkdir($dir); file_put_contents("$dir/s.txt", 'TOKEN');
$port = 24567;
$p = proc_open(['setsid', PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir], [0 => ['file','/dev/null','r'], 1 => ['file','/dev/null','w'], 2 => ['file','/dev/null','w']], $pipes, $dir, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
usleep(800000);
$pid = proc_get_status($p)['pid'];
echo "me pid=", getmypid(), " pgrp=", posix_getpgid(getmypid()), "\n";
echo "child pid=$pid stat=", json_encode(stat_of($pid)), "\n";
echo "group members (non-zombie): ", count(group($pid)), " ", json_encode(array_map(fn($s)=>$s['ppid'], group($pid))), "\n";
echo "served: ", @file_get_contents("http://127.0.0.1:$port/s.txt"), "\n";
posix_kill(-$pid, 15);
for ($i=0;$i<30 && group($pid);$i++) usleep(100000);
echo "after 15 to group: members=", count(group($pid)), " port accepting=", (@fsockopen('127.0.0.1',$port)?'YES':'no'), "\n";
echo "proc_close=", proc_close($p), "\n";
// control: 15 to master only (old behaviour)
$p = proc_open(['setsid', PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir], [0 => ['file','/dev/null','r'], 1 => ['file','/dev/null','w'], 2 => ['file','/dev/null','w']], $pipes, $dir, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
usleep(800000); $pid = proc_get_status($p)['pid'];
posix_kill($pid, 15); usleep(800000);
echo "control 15 master only: members left=", count(group($pid)), " port accepting=", (@fsockopen('127.0.0.1',$port)?'YES':'no'), "\n";
posix_kill(-$pid, 9); usleep(300000); proc_close($p);
echo "control cleanup: members=", count(group($pid)), "\n";
