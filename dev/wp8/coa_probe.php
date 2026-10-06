<?php
// WP8 lab diagnostic (development/test only): sends a Disconnect-Request with the APPLICATION's own
// Reseller\Radius\CoAClient from the runner (dev/wp8/lab.sh php dev/wp8/coa_probe.php ...), i.e. from the
// FreeRADIUS-shared source address 10.88.0.10. Application code is used unchanged.
//   coa_probe.php disconnect <username>                      nas lookup radacct.nasipaddress == nas.nasname
//   coa_probe.php send <nasIp> <port> <username> [sessionId] explicit target
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('NUXR_RUNTIME', true);
spl_autoload_register(static function (string $class): void {
    if (preg_match('/^Reseller\\\\(Security|Radius)\\\\([A-Za-z0-9]+)$/', $class, $m)) {
        require '/repo/system/autoload/Reseller/' . $m[1] . '/' . $m[2] . '.php';
    }
});
use Reseller\Radius\{AccountingService, CoAClient, RadiusRepository};
$pdo = new PDO('mysql:host=mariadb;dbname=nuxr_radius;charset=utf8mb4', 'root', (string) getenv('WP1_DB_ROOT_PASSWORD'));
$client = new CoAClient(new AccountingService($pdo), new RadiusRepository($pdo), 2.0, 2);
$t = microtime(true);
if (($argv[1] ?? '') === 'send') {
    $attrs = [['User-Name', $argv[4]]];
    if (!empty($argv[5])) { $attrs[] = ['Acct-Session-Id', $argv[5]]; }
    $res = [$client->send(['id' => 'probe', 'nasname' => $argv[2], 'ports' => $argv[3], 'secret' => (string) getenv('NUXR_RADIUS_SECRET')], 40, $attrs)];
} else {
    $res = $client->disconnect((string) ($argv[2] ?? ''));
}
echo json_encode(['elapsed_s' => round(microtime(true) - $t, 3), 'results' => array_map(static fn($r) => $r->toArray(), $res)], JSON_PRETTY_PRINT), "\n";
