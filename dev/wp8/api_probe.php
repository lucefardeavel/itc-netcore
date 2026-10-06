<?php
// WP8 lab diagnostic (development/test only): read-only RouterOS API probe through the APPLICATION's vendored
// PEAR2\Net\RouterOS client (plain API 8728, as system/autoload/Mikrotik.php and the device drivers use it).
// dev/wp8/lab.sh php dev/wp8/api_probe.php [host] [port]   (password from NUXR_WP8_CHR_PASSWORD; never printed)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$deprecations = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$deprecations): bool {
    if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) { $deprecations[basename($file) . ':' . $line] = $msg; return true; }
    return false;
});
require '/repo/system/autoload/PEAR2/Autoload.php';
use PEAR2\Net\RouterOS;
$host = $argv[1] ?? '10.88.0.2';
$t = microtime(true);
$c = new RouterOS\Client($host, 'admin', (string) getenv('NUXR_WP8_CHR_PASSWORD'), (int) ($argv[2] ?? 8728), false, 10);
$out = ['host' => $host, 'login_s' => round(microtime(true) - $t, 3), 'transport' => 'plain API (CRYPTO_OFF)'];
foreach (['/system/resource/print', '/system/license/print', '/radius/print', '/radius/incoming/print', '/ppp/aaa/print'] as $cmd) {
    $t1 = microtime(true); $rows = [];
    foreach ($c->sendSync(new RouterOS\Request($cmd)) as $r) {
        if ($r->getType() === RouterOS\Response::TYPE_DATA) {
            $row = [];
            foreach ($r as $k => $v) { $row[$k] = preg_match('/secret|password/i', (string) $k) ? '[REDACTED]' : $v; }
            $rows[] = $row;
        }
    }
    $out[$cmd] = ['elapsed_s' => round(microtime(true) - $t1, 3), 'rows' => $rows];
}
$out['php_deprecations'] = $deprecations;
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
