<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP5B fix round 1, blocker 4 (no real NAS: a deterministic fake RouterOS client inside the application):
 *  4A MikrotikHotspot::change_username really renames the existing Hotspot user (the old name is not left behind);
 *  4B the allowed expiry path of remove_customer() (plan_expired) of MikrotikHotspot / MikrotikPppoe / MikrotikVpn
 *     applies the restricted plan for a reseller-owned customer without tripping the WP5B grant guard, while the
 *     public grant methods stay refused.
 */
final class MikrotikDeviceTest extends Wp2HttpTestCase
{
    /** The fake RouterOS client and the device test doubles (only info() / getClient() are replaced). */
    private const FAKE = <<<'PHP'
require_once $DEVICE_PATH . '/MikrotikHotspot.php';
require_once $DEVICE_PATH . '/MikrotikPppoe.php';
require_once $DEVICE_PATH . '/MikrotikVpn.php';
if (!class_exists('Wp5bFakeRos', false)) {
    class Wp5bFakeRosResponse { public function __construct(public array $p = []) {} public function getProperty($n) { return $this->p[$n] ?? null; } }
    class Wp5bFakeRos {
        public array $store = []; public array $log = []; private int $seq = 1;
        public function sendSync($r) {
            $cmd = $r->getCommand();
            $where = [];
            if (($q = $r->getQuery()) !== null) {
                $w = new ReflectionProperty($q, 'words');
                $w->setAccessible(true);
                foreach ($w->getValue($q) as $word) { $where[ltrim((string) $word[0], '?=')] = $word[1]; }
            }
            $args = [];
            foreach (['numbers', '.id', 'name', 'profile', 'password', 'remote-address', 'value-name', 'service'] as $a) {
                if (($v = $r->getArgument($a)) !== null) { $args[$a] = $v; }
            }
            $path = substr($cmd, 0, (int) strrpos($cmd, '/'));
            $verb = substr($cmd, (int) strrpos($cmd, '/') + 1);
            $this->log[] = [$cmd, $args];
            switch ($verb) {
                case 'print':
                    $name = $where['name'] ?? $where['user'] ?? null;
                    foreach ($this->store[$path] ?? [] as $id => $row) { if (($row['name'] ?? null) === $name) { return new Wp5bFakeRosResponse(['.id' => $id] + $row); } }
                    return new Wp5bFakeRosResponse();
                case 'add':
                    $id = '*' . $this->seq++; $this->store[$path][$id] = $args; return new Wp5bFakeRosResponse(['ret' => $id]);
                case 'set':
                    $id = $args['numbers'] ?? $args['.id'] ?? null;
                    if ($id !== null && isset($this->store[$path][$id])) { $this->store[$path][$id] = array_merge($this->store[$path][$id], array_diff_key($args, ['numbers' => 1, '.id' => 1])); }
                    return new Wp5bFakeRosResponse();
                case 'remove':
                    unset($this->store[$path][$args['numbers'] ?? '']); return new Wp5bFakeRosResponse();
            }
            return new Wp5bFakeRosResponse();
        }
        public function names(string $path): array { return array_values(array_map(fn($r) => $r['name'] ?? null, $this->store[$path] ?? [])); }
    }
    trait Wp5bFakeDevice {
        public Wp5bFakeRos $fake;
        public function info($name) { return ['ip_address' => '192.0.2.1', 'username' => 'u', 'password' => 'p']; }
        public function getClient($ip, $user, $pass) { return $this->fake; }
    }
    class Wp5bHotspot extends MikrotikHotspot { use Wp5bFakeDevice; }
    class Wp5bPppoe extends MikrotikPppoe { use Wp5bFakeDevice; }
    class Wp5bVpn extends MikrotikVpn { use Wp5bFakeDevice; }
}
PHP;

    private function plans(): array
    {
        $db = static::$db;
        $seed = $db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seed['id']);
        $expired = $db->insertRow('tbl_plans', ['name_plan' => 'wp5b_expired_' . bin2hex(random_bytes(2))] + $seed);
        $main = $db->insertRow('tbl_plans', ['name_plan' => 'wp5b_main_' . bin2hex(random_bytes(2)), 'plan_expired' => $expired] + $seed);
        return [$main, $expired];
    }

    public function testHotspotRenameReallyRenamesTheExistingUser(): void
    {
        $res = static::$app->exec(sprintf(self::FAKE . <<<'PHP'
$d = new Wp5bHotspot(); $d->fake = new Wp5bFakeRos();
$d->fake->store['/ip/hotspot/user']['*7'] = ['name' => 'old-hotspot-name', 'profile' => 'p'];
$p = ORM::for_table('tbl_plans')->find_one(%d);
$d->change_username($p, 'old-hotspot-name', 'new-hotspot-name');
$result = ['names' => $d->fake->names('/ip/hotspot/user'), 'ids' => array_keys($d->fake->store['/ip/hotspot/user'])];
PHP, static::$seed->planId));
        $this->assertSame(['names' => ['new-hotspot-name'], 'ids' => ['*7']], $res, 'the same router user, renamed; the old name is gone');
    }

    public function testExpiryOfAResellerOwnedCustomerAppliesTheRestrictedPlanWithoutTheGrantGuard(): void
    {
        [$main, $expired] = $this->plans();
        $code = sprintf(self::FAKE . <<<'PHP'
$c = ORM::for_table('tbl_customers')->find_one(%d);
$p = ORM::for_table('tbl_plans')->find_one(%d);
$x = ORM::for_table('tbl_plans')->find_one(%d);
$out = [];
foreach (['Wp5bHotspot' => '/ip/hotspot/user', 'Wp5bPppoe' => '/ppp/secret', 'Wp5bVpn' => '/ppp/secret'] as $cls => $path) {
    $d = new $cls(); $d->fake = new Wp5bFakeRos();
    $d->fake->store[$path]['*1'] = ['name' => $c['username'], 'profile' => $p['name_plan']];
    try {
        $d->remove_customer($c, $p);
        $profiles = array_values(array_map(fn($r) => $r['profile'] ?? null, $d->fake->store[$path] ?? []));
        $out[$cls]['expiry'] = $profiles;
    } catch (\Throwable $e) {
        $out[$cls]['expiry'] = get_class($e) . ':' . ($e instanceof \Reseller\Radius\ProvisionFailure ? $e->result->code : $e->getMessage());
    }
    try {
        $d->add_customer($c, $p);
        $out[$cls]['grant'] = 'ran';
    } catch (\Reseller\Radius\ProvisionFailure $e) {
        $out[$cls]['grant'] = $e->result->code;
    }
}
$result = $out;
PHP, $this->cid('cA1'), $main, $expired);
        $res = static::$app->exec($code);
        $name = (string) static::$db->value('SELECT name_plan FROM tbl_plans WHERE id = ?', [$expired]);
        foreach (['Wp5bHotspot', 'Wp5bPppoe', 'Wp5bVpn'] as $cls) {
            $this->assertSame([$name], $res[$cls]['expiry'], "{$cls}: the expiry applied the restricted plan (no RESELLER_OWNED_REFUSED)");
            $this->assertSame('RESELLER_OWNED_REFUSED', $res[$cls]['grant'], "{$cls}: the public grant stays refused");
        }
    }

    public function testThePrimitiveIsPrivateAndNotABypass(): void
    {
        foreach (['MikrotikHotspot', 'MikrotikPppoe', 'MikrotikVpn'] as $d) {
            $src = (string) file_get_contents(dirname(__DIR__, 3) . "/system/devices/{$d}.php");
            $this->assertMatchesRegularExpression('/private function provisionCustomer\(\$customer, \$plan\)/', $src, $d);
            $this->assertDoesNotMatchRegularExpression('/remove_customer[^{]*\{(?:(?!function ).)*\$this->add_customer\(/s', $src, "{$d}: expiry never calls the guarded grant");
        }
    }
}
