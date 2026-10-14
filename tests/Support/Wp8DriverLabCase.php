<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp8LabHelpers.php';

/**
 * WP8 S7 lab base (direct MikroTik driver contract / fail-closed tests on the isolated RouterOS CHR 7.24.5).
 *
 * - The application runs in a disposable app copy and database (Wp2HttpTestCase); the REAL drivers
 *   (system/devices/Mikrotik*.php, vendored PEAR2 client, plain API 8728 inside the lab, OD-W8-5) are called inside the
 *   application runtime (AppServer::exec, full init.php).
 * - OD-S7-1 / AM-S7-2: a temporary least-privilege API user `nuxr_wp8_s7api` (own group, policy api,read,write, address
 *   10.88.0.10/32 = the lab runner) with a random password held only in this process and, for the drivers, in the
 *   disposable `tbl_routers` row of the test database. The lab admin account is never used by the application.
 *   Created before and removed (with its group and the router row) after the class; the removal is verified.
 * - Router state is read back through the separate lab agent (Wp8Lab::ros, admin, management path), never through the
 *   driver under test. Every local object is named nuxr_wp8_local_* (RADIUS subscribers are never touched).
 * - Evidence never contains the API password (checked before every write).
 */
abstract class Wp8DriverLabCase extends Wp2HttpTestCase
{
    use Wp8LabHelpers;

    public const ROUTER = 'nuxr_wp8_local_router';
    public const API_USER = 'nuxr_wp8_s7api';
    public const API_GROUP = 'nuxr_wp8_s7api_grp';
    public const PREFIX = 'nuxr_wp8_local_';
    private const PATHS = [
        '/ppp/secret' => ['.id', 'name', 'service', 'profile', 'remote-address', 'local-address', 'disabled', 'comment'],
        '/ip/hotspot/user' => ['.id', 'name', 'profile', 'server', 'disabled', 'comment', 'limit-uptime', 'limit-bytes-total', 'email'],
        '/ppp/profile' => ['.id', 'name', 'local-address', 'remote-address', 'rate-limit'],
        '/ip/hotspot/user/profile' => ['.id', 'name', 'rate-limit', 'shared-users'],
        '/ip/pool' => ['.id', 'name', 'ranges'],
        '/ip/firewall/nat' => ['.id', 'chain', 'action', 'protocol', 'dst-address', 'dst-port', 'to-addresses', 'to-ports', 'comment', 'dynamic', 'disabled'],
        '/ip/firewall/filter' => ['.id', 'chain', 'action', 'protocol', 'dst-port', 'src-address', 'comment', 'dynamic', 'disabled'],
        '/ppp/active' => ['.id', 'name', 'service', 'address', 'caller-id'],
        '/ip/hotspot/active' => ['.id', 'user', 'address', 'mac-address', 'server'],
        '/user' => ['.id', 'name', 'group', 'address', 'disabled'],
        '/user/group' => ['.id', 'name', 'policy'],
    ];

    private static ?string $apiPassword = null;
    protected static array $classInventoryBefore = [];
    protected static int $bw = 0;

    public static function setUpBeforeClass(): void
    {
        if (!Wp8Lab::enabled()) {
            return;
        }
        parent::setUpBeforeClass();
        self::$classInventoryBefore = static::inventory();
        static::cleanupPrefixed();                                  // a crashed earlier run must not leak into this one
        self::$apiPassword = 'S7' . bin2hex(random_bytes(14));
        Wp8Lab::ros('/user/group/add', '=name=' . self::API_GROUP, '=policy=api,read,write');
        Wp8Lab::ros('/user/add', '=name=' . self::API_USER, '=group=' . self::API_GROUP, '=address=10.88.0.10/32', '=password=' . self::$apiPassword);
        static::$db->insertRow('tbl_routers', ['name' => self::ROUTER, 'ip_address' => Wp8Lab::CHR . ':8728', 'username' => self::API_USER,
            'password' => self::$apiPassword, 'description' => 'WP8 S7 disposable lab router', 'enabled' => 1]);
        static::$bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => self::PREFIX . 'bw', 'rate_down' => 2, 'rate_down_unit' => 'Mbps', 'rate_up' => 1,
            'rate_up_unit' => 'Mbps', 'burst' => '']);
    }

    public static function tearDownAfterClass(): void
    {
        if (!Wp8Lab::enabled()) {
            return;
        }
        try {
            foreach (['c1' => 'pppoe_down', 'c2' => 'hs_logout'] as $c => $op) {
                Wp8Lab::agent($op, ['client' => $c]);
            }
            static::cleanupPrefixed();
            static::$db?->exec("DELETE FROM tbl_routers WHERE name = '" . self::ROUTER . "'");
            foreach (Wp8Lab::ros('/user/print', '?name=' . self::API_USER) as $u) {
                Wp8Lab::ros('/user/remove', '=.id=' . $u['.id']);
            }
            foreach (Wp8Lab::ros('/user/group/print', '?name=' . self::API_GROUP) as $g) {
                Wp8Lab::ros('/user/group/remove', '=.id=' . $g['.id']);
            }
            $after = static::inventory();
            $removed = ['api_user' => Wp8Lab::ros('/user/print', '?name=' . self::API_USER) === [],
                'api_group' => Wp8Lab::ros('/user/group/print', '?name=' . self::API_GROUP) === [],
                'router_row' => static::$db === null ? true : (int) static::$db->value("SELECT COUNT(*) FROM tbl_routers WHERE name = ?", [self::ROUTER]) === 0];
            static::evidence('class-cleanup.' . (new \ReflectionClass(static::class))->getShortName(), ['removed' => $removed,
                'inventory_unchanged' => $after === self::$classInventoryBefore, 'inventory_before' => self::$classInventoryBefore, 'inventory_after' => $after]);
        } finally {
            self::$apiPassword = null;
            parent::tearDownAfterClass();
        }
    }

    protected function setUp(): void
    {
        if (!Wp8Lab::enabled()) {
            $this->markTestIncomplete('WP8 real-NAS lab not running (dev/wp8/lab.sh up; dev/wp8/lab.sh test)');
        }
    }

    // ------------------------------------------------------------------------------------------ RouterOS (agent)

    /** @return array<string,list<array<string,string>>> the inventory of the classified paths (stable fields only) */
    protected static function inventory(): array
    {
        $out = [];
        foreach (self::PATHS as $path => $fields) {
            $rows = [];
            foreach (Wp8Lab::ros($path . '/print') as $r) {
                $rows[] = array_intersect_key($r, array_flip($fields));
            }
            $out[$path] = $rows;
        }
        return $out;
    }

    /** the inventory without the S7 test objects (names / comments with the local prefix, the S7 API user and group) */
    protected static function others(?array $inv = null): array
    {
        $inv ??= static::inventory();
        foreach ($inv as $path => $rows) {
            $inv[$path] = array_values(array_filter($rows, static fn(array $r): bool => !static::isTestObject($r)));
        }
        return $inv;
    }

    protected static function isTestObject(array $r): bool
    {
        foreach (['name', 'user', 'comment', 'profile', 'remote-address'] as $k) {
            $v = (string) ($r[$k] ?? '');
            if (str_contains($v, 'nuxr_wp8_local') || str_starts_with($v, self::API_USER)) {
                return true;
            }
        }
        foreach (['to-addresses', 'dst-address'] as $k) {
            if (str_starts_with((string) ($r[$k] ?? ''), '10.88.25')) {       // the S7 test address block 10.88.250-251.x
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,string>> rows of one path matching name */
    protected static function find(string $path, string $name, string $field = 'name'): array
    {
        return Wp8Lab::ros($path . '/print', '?' . $field . '=' . $name);
    }

    /** read-then-delete of every S7 object (prefix / comment / test address block only); returns what was removed */
    protected static function cleanupPrefixed(): array
    {
        $removed = [];
        foreach (['/ppp/active', '/ip/hotspot/active', '/ppp/secret', '/ip/hotspot/user', '/ip/firewall/nat', '/ip/firewall/filter', '/ppp/profile', '/ip/hotspot/user/profile', '/ip/pool'] as $path) {
            foreach (Wp8Lab::ros($path . '/print') as $r) {
                if (static::isTestObject($r) && ($r['dynamic'] ?? 'false') !== 'true') {
                    Wp8Lab::ros($path . '/remove', '=.id=' . $r['.id']);
                    $removed[] = [$path, $r['name'] ?? $r['user'] ?? $r['comment'] ?? $r['.id']];
                }
            }
        }
        return $removed;
    }

    // ---------------------------------------------------------------------------------------- application side

    /**
     * Runs PHP inside the application runtime (init.php) and returns ['ok', 'class', 'code', 'msg', 'warnings', 'ms'].
     * $call may use $c(id) (customer ORM row), $p(id) (plan ORM row) and must not contain secrets.
     */
    protected function app(string $call): array
    {
        // unique names: legacy code assigns globals such as $t (Package::rechargeUser), $c and $p
        $code = <<<'PHP'
foreach (['MikrotikHotspot', 'MikrotikPppoe', 'MikrotikVpn'] as $__s7d) { require_once 'system/devices/' . $__s7d . '.php'; }
$__s7c = static fn($id) => ORM::for_table('tbl_customers')->find_one($id);
$__s7p = static fn($id) => ORM::for_table('tbl_plans')->find_one($id);
$__s7warn = [];
set_error_handler(static function ($no, $msg, $file, $line) use (&$__s7warn) { $__s7warn[] = basename($file) . ':' . $line . ' ' . $msg; return true; }, E_WARNING);
$__s7t = microtime(true);
$__s7value = null;
try {
    $__s7value = (static function () use ($__s7c, $__s7p) { $c = $__s7c; $p = $__s7p; %CALL%; })();
    $__s7out = ['ok' => true];
} catch (\Reseller\Radius\ProvisionFailure $e) {
    $__s7out = ['ok' => false, 'class' => 'ProvisionFailure', 'code' => $e->result->code];
} catch (\Reseller\Domain\DomainError $e) {
    $__s7out = ['ok' => false, 'class' => 'DomainError', 'code' => $e->reason];
} catch (\Throwable $e) {
    $__s7out = ['ok' => false, 'class' => get_class($e), 'code' => null, 'msg' => substr($e->getMessage(), 0, 160)];
}
restore_error_handler();
$result = $__s7out + ['value' => is_scalar($__s7value) || is_array($__s7value) || $__s7value === null ? $__s7value : get_class($__s7value), 'warnings' => $__s7warn,
    'ms' => (int) round((microtime(true) - $__s7t) * 1000)];
PHP;
        $log = strlen(static::$app->serverLog());
        try {
            $out = static::$app->exec(str_replace('%CALL%', $call, $code));
        } catch (\RuntimeException $e) {
            // the runtime ended without a result (exit / fatal): report the application's own error log of this call
            $tail = substr(static::$app->serverLog(), $log, 3000);
            $this->assertFalse(self::$apiPassword !== null && str_contains($tail, self::$apiPassword), 'the API password never reaches the error log');
            throw new \RuntimeException($e->getMessage() . ' | app error log: ' . $tail, 0, $e);
        }
        $this->assertIsArray($out, 'app runtime call');
        $this->assertFalse(self::$apiPassword !== null && str_contains(json_encode($out), self::$apiPassword), 'the API password never leaves the runtime');
        return $out;
    }

    /** the application's error-log lines written since offset $from that contain $needle (refusals in CLI are logged, not audited) */
    protected static function logged(int $from, string $needle): int
    {
        return substr_count(substr(static::$app->serverLog(), $from), $needle);
    }

    protected function localCustomer(string $tag, ?int $manager, array $extra = []): array
    {
        $u = self::PREFIX . $tag . '_' . bin2hex(random_bytes(2));
        $pw = 'Lc' . bin2hex(random_bytes(4));
        $id = static::$db->insertRow('tbl_customers', $extra + [
            'username' => $u, 'password' => $pw, 'fullname' => 'S7 ' . $tag, 'address' => '', 'phonenumber' => '0799' . random_int(100000, 999999),
            // RouterOS rejects the reserved .invalid TLD in the driver comments
            'email' => str_replace('_', '-', $u) . '@example.com', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active',
            'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0, 'manager_id' => $manager, 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '',
        ]);
        return ['id' => $id, 'username' => $extra['username'] ?? $u, 'password' => $extra['password'] ?? $pw];
    }

    /** a legacy MikroTik-device plan on the S7 router */
    protected function plan(string $type, string $tag, array $extra = []): array
    {
        $device = ['Hotspot' => 'MikrotikHotspot', 'PPPOE' => 'MikrotikPppoe', 'VPN' => 'MikrotikVpn'][$type];
        $name = self::PREFIX . 'prof_' . $tag . '_' . bin2hex(random_bytes(2));
        $id = static::$db->insertRow('tbl_plans', $extra + [
            'name_plan' => $name, 'id_bw' => static::$bw, 'price' => '10', 'type' => $type, 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => self::ROUTER, 'is_radius' => 0, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => $device,
            'plan_expired' => 0, 'on_login' => '', 'on_logout' => '',
        ]);
        return ['id' => $id, 'name' => $name, 'device' => $device];
    }

    protected function recharge(array $customer, array $plan, string $type, string $expiration = '+10 days'): int
    {
        return static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => $customer['id'], 'username' => $customer['username'], 'plan_id' => $plan['id'], 'namebp' => $plan['name'],
            'recharged_on' => gmdate('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => gmdate('Y-m-d', strtotime($expiration)), 'time' => '23:59:59',
            'status' => 'on', 'method' => 'Admin - S7', 'routers' => self::ROUTER, 'type' => $type, 'admin_id' => $this->sid('ad'),
        ]);
    }

    /** checksums of the RADIUS tables of the lab RADIUS database (AM-S7-1) */
    protected static function radiusChecksums(): array
    {
        $out = [];
        foreach (['radcheck', 'radreply', 'radusergroup', 'radgroupcheck', 'radgroupreply', 'radacct', 'nas'] as $t) {
            $rows = array_map('json_encode', static::labRadius()->query("SELECT * FROM {$t} ORDER BY 1")->fetchAll(\PDO::FETCH_ASSOC));
            $out[$t] = hash('sha256', implode("\n", $rows));
        }
        return $out;
    }

    /** no S7 local identity exists in RADIUS, and no RADIUS identity carries the local prefix */
    protected function assertNoRadiusOverlap(array $localNames): void
    {
        $pdo = static::labRadius();
        $n = (int) $pdo->query("SELECT COUNT(*) FROM radcheck WHERE username LIKE 'nuxr\\_wp8\\_local\\_%'")->fetchColumn()
            + (int) $pdo->query("SELECT COUNT(*) FROM radusergroup WHERE username LIKE 'nuxr\\_wp8\\_local\\_%'")->fetchColumn();
        $this->assertSame(0, $n, 'no RADIUS identity with the local prefix');
        foreach ($localNames as $name) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ?');
            $st->execute([$name]);
            $this->assertSame(0, (int) $st->fetchColumn(), "{$name}: not a RADIUS identity");
        }
    }

    /** FreeRADIUS debug-log lines that mention $name (an Access-Request or accounting for that identity) */
    protected static function radiusSaw(string $name): array
    {
        return Wp8Lab::frLog(preg_quote($name, '/'), 50);
    }

    protected static function evidence(string $case, array $data): void
    {
        $json = json_encode($data);
        if (self::$apiPassword !== null && str_contains((string) $json, self::$apiPassword)) {
            throw new \LogicException('evidence would contain the API password');
        }
        Wp8Lab::evidence('S7.' . $case, $data);
    }
}
