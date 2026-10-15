<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp9Evidence.php';

use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP9 U0 (owner WP9_U0_FULL_IMPLEMENTATION_AND_TESTING_AUTHORIZED; D-U0-1 … D-U0-8) - shared fixture of the U0 tests.
 *
 * Disposable application copy + database only (RadiusHttpTestCase), synthetic names (prefix w9u0), RADIUS tables only
 * (no FreeRADIUS process, no NAS / CHR), no SQL exhaustion, pool pressure or connection-fault injection.
 *
 * Real writers (the application code, not raw SQL): P1 CustomerService::create, P2 register/post (HTTP), P3
 * redeemForNewUsername, P4 customers/edit-post (HTTP), P5 LegacyReprovisionService::run IDENTITY_EDIT, P6 voucher-only
 * redemption; the key writer Package::rechargeUser provisions a customer's RADIUS policy.
 *
 * Barriers (test-only; the app copy only):
 *  - file barriers in a test plugin: 'recharge' (hook recharge_user, inside Package::rechargeUser before the device),
 *    'identity' (ActivationFaults point identity.locked, inside the identity lock - post-fix only), 'rename' (the test
 *    device W9Radius = Radius with a barrier before change_username, i.e. after the customer row was saved and before
 *    the RADIUS rename);
 *  - table-lock barriers held by a second test connection (owner D-U0-7): LOCK TABLES tbl_customers READ pauses every
 *    customer writer at its write statement (after its checks); LOCK TABLES tbl_reseller_audit_events WRITE pauses P1
 *    after its INSERT, before COMMIT.
 */
trait U0Fixture
{
    use Wp9Evidence;

    protected static int $hotspot = 0;
    protected static int $pppoe = 0;
    protected static int $barrierHotspot = 0;

    private const BARRIER_PLUGIN = <<<'PHP'
<?php
// test-only (disposable app copy): WP9 U0 barriers. A barrier pauses ONE process once while wp2_probe/u0_pause_<point>
// exists, signals wp2_probe/u0_arrived_<point> and waits (at most 40 s) for wp2_probe/u0_go_<point>.
function wp9u0_barrier($point) {
    $dir = __DIR__ . '/../../wp2_probe/';
    if (!is_file($dir . 'u0_pause_' . $point) || !@rename($dir . 'u0_pause_' . $point, $dir . 'u0_arrived_' . $point)) { return; }
    $deadline = microtime(true) + 40;
    while (!is_file($dir . 'u0_go_' . $point) && microtime(true) < $deadline) { usleep(20000); }
    @unlink($dir . 'u0_go_' . $point);
    @unlink($dir . 'u0_arrived_' . $point);
}
function wp9u0_recharge_barrier() { wp9u0_barrier('recharge'); }
register_hook('recharge_user', 'wp9u0_recharge_barrier');
\Reseller\Activation\ActivationFaults::$hook = static function (string $point): void {
    if ($point === 'identity.locked') { wp9u0_barrier('identity'); }
};
PHP;

    private const BARRIER_DEVICE = <<<'PHP'
<?php
// test-only (disposable app copy): the Radius device with a barrier before the RADIUS rename (WP9 U0 gap G3)
require_once __DIR__ . '/Radius.php';
class W9Radius extends Radius
{
    public function change_username($plan, $from, $to)
    {
        wp9u0_barrier('rename');
        return parent::change_username($plan, $from, $to);
    }
}
PHP;

    private const FAIL_DEVICE = <<<'PHP'
<?php
// test-only (disposable app copy): a non-RADIUS device whose rename is a CONFIRMED failure (nothing applied), after a
// barrier - the P5 revert path (P5r) of the U0 identity contract
use Reseller\Radius\ProvisionFailure;
use Reseller\Radius\ProvisionResult;
class W9Fail
{
    public function description() { return ['title' => 'W9Fail', 'description' => 'test device', 'author' => 'test', 'url' => []]; }
    public function change_username($plan, $from, $to)
    {
        wp9u0_barrier('fail');
        throw new ProvisionFailure(ProvisionResult::failed('W9_DEVICE_FAILED', 'test device failure', false));
    }
    public function add_customer($customer, $plan) { throw new ProvisionFailure(ProvisionResult::failed('W9_DEVICE_FAILED', 'test device failure', false)); }
    public function __call($name, $args) { return null; }
}
PHP;

    protected static int $failPlan = 0;

    protected static function u0Setup(): void
    {
        $seed = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seed['id']);
        self::$hotspot = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9u0_radius_hotspot', 'type' => 'Hotspot', 'device' => 'Radius',
            'routers' => 'radius', 'is_radius' => 1] + $seed);
        self::$pppoe = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9u0_radius_pppoe', 'type' => 'PPPOE', 'device' => 'Radius',
            'routers' => 'radius', 'is_radius' => 1] + $seed);
        self::$barrierHotspot = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9u0_barrier_hotspot', 'type' => 'Hotspot', 'device' => 'W9Radius',
            'routers' => 'radius', 'is_radius' => 1] + $seed);
        file_put_contents(static::$app->dir . '/system/plugin/wp9u0_barrier.php', self::BARRIER_PLUGIN);
        file_put_contents(static::$app->dir . '/system/devices/W9Radius.php', self::BARRIER_DEVICE);
        self::$failPlan = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9u0_fail', 'type' => 'Hotspot', 'device' => 'W9Fail',
            'routers' => 'radius', 'is_radius' => 0] + $seed);
        file_put_contents(static::$app->dir . '/system/devices/W9Fail.php', self::FAIL_DEVICE);
    }

    protected function tearDown(): void
    {
        foreach (glob(static::$app->dir . '/wp2_probe/u0_*') ?: [] as $f) {
            @unlink($f);
        }
    }

    // ------------------------------------------------------------------ fixtures

    protected static function tag(): string
    {
        return 'w9u0' . bin2hex(random_bytes(3));
    }

    /** an unassigned (or owned) customer row inserted as fixture data (the starting state, not a writer under test) */
    protected function person(string $username, string $pppoe = '', ?string $manager = null): array
    {
        $row = ['username' => $username, 'password' => 'Pw-' . bin2hex(random_bytes(4)), 'pppoe_username' => $pppoe,
            'pppoe_password' => $pppoe !== '' ? 'Pp-' . bin2hex(random_bytes(4)) : '', 'pppoe_ip' => '', 'fullname' => 'W9 U0', 'address' => '',
            'phonenumber' => '0700', 'email' => 'w9u0@example.invalid', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '',
            'status' => 'Active', 'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0, 'manager_id' => $manager !== null ? $this->sid($manager) : null];
        $row['id'] = static::$db->insertRow('tbl_customers', $row);
        return $row;
    }

    /** the customer's own legitimate grant (key writer): RADIUS policy under its key, active recharge */
    protected function provision(int $customerId, ?int $plan = null): string
    {
        return (string) static::$app->exec(sprintf('$result = Package::rechargeUser(%d, "radius", %d, "Recharge", "admin");', $customerId, $plan ?? self::$hotspot));
    }

    protected function voucher(string $code, ?int $plan = null): int
    {
        return static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => 'radius', 'id_plan' => $plan ?? self::$hotspot, 'code' => $code,
            'user' => '0', 'status' => '0', 'generated_by' => $this->sid('ad')]);
    }

    protected static function vstatus(int $id): string
    {
        return (string) static::$db->value('SELECT status FROM tbl_voucher WHERE id = ?', [$id]);
    }

    /** exact-byte RADIUS check rows of one key */
    protected static function exact(string $key): array
    {
        return static::$db->rows('SELECT attribute, op, value FROM radcheck WHERE BINARY username = ? ORDER BY attribute, id', [$key]);
    }

    protected static function password(string $key): ?string
    {
        foreach (self::exact($key) as $r) {
            if ($r['attribute'] === 'Cleartext-Password') {
                return (string) $r['value'];
            }
        }
        return null;
    }

    /** customers holding $name as username or PPPoE username under the column comparison */
    protected static function holders(string $name): array
    {
        return array_map('intval', array_column(static::$db->rows('SELECT id FROM tbl_customers WHERE username = ? OR pppoe_username = ?', [$name, $name]), 'id'));
    }

    protected static function customerRow(int $id): ?array
    {
        return static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$id])[0] ?? null;
    }

    // ------------------------------------------------------------------ writers (application code)

    protected static function p1Code(string $actor, string $username, string $pppoe = ''): string
    {
        return sprintf('$u = ORM::for_table("tbl_users")->where("username", %s)->find_one()->as_array(); try { $result = "CREATED " . \Reseller\Domain\CustomerService::create(\Reseller\Security\ActorContext::forStaff($u, "WEB"), ["username" => %s, "pppoe_username" => %s, "password" => %s, "fullname" => "W9 U0 P1"]); } catch (\Reseller\Domain\DomainError $e) { $result = "REFUSED " . $e->reason; }',
            var_export($actor, true), var_export($username, true), var_export($pppoe, true), var_export('Pw-' . bin2hex(random_bytes(4)), true));
    }

    protected static function p3Code(string $code, string $username): string
    {
        return sprintf('try { $result = \Reseller\Domain\VoucherRedemptionService::redeemForNewUsername(%s, %s, \Reseller\Domain\RedemptionSource::public())["status"]; } catch (\Reseller\Domain\DomainError $e) { $result = "REFUSED " . $e->reason; }',
            var_export($code, true), var_export($username, true));
    }

    protected static function p5Code(int $customerId, array $requested): string
    {
        return sprintf('$u = ORM::for_table("tbl_users")->where("username", "ad")->find_one()->as_array(); try { $r = \Reseller\Domain\LegacyReprovisionService::run(\Reseller\Security\ActorContext::forStaff($u, "WEB"), %d, \Reseller\Domain\LegacyReprovisionService::IDENTITY_EDIT, %s); $result = "ACCEPTED " . $r["status"]; } catch (\Reseller\Domain\DomainError $e) { $result = "REFUSED " . $e->reason; }',
            $customerId, var_export($requested, true));
    }

    protected static function p6Code(string $code, bool $radius = false): string
    {
        return sprintf('try { $result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, \Reseller\Domain\RedemptionTarget::voucherOnly(%s), %s)["status"]; } catch (\Throwable $e) { $result = "ERROR " . get_class($e) . " " . $e->getMessage(); }',
            var_export($code, true), var_export($code, true), $radius ? '\Reseller\Domain\RedemptionSource::radius("127.0.0.1")' : '\Reseller\Domain\RedemptionSource::public()');
    }

    protected static function provisionCode(int $customerId, ?int $plan = null): string
    {
        return sprintf('$result = Package::rechargeUser(%d, "radius", %d, "Recharge", "admin");', $customerId, $plan ?? self::$hotspot);
    }

    protected function p2Data(string $username): array
    {
        return ['username' => $username, 'fullname' => 'W9 U0 Reg', 'email' => 'w9u0reg@example.invalid', 'password' => 'Pw-w9u0reg',
            'cpassword' => 'Pw-w9u0reg', 'address' => 'x', 'phone_number' => '0700'];
    }

    protected function p4Data(array $c, array $change): array
    {
        return $change + ['id' => $c['id'], 'username' => $c['username'], 'fullname' => $c['fullname'], 'account_type' => 'Personal', 'password' => '',
            'pppoe_username' => $c['pppoe_username'], 'pppoe_password' => $c['pppoe_password'], 'pppoe_ip' => '', 'email' => $c['email'], 'address' => '',
            'phonenumber' => $c['phonenumber'], 'service_type' => 'Hotspot', 'coordinates' => '', 'status' => 'Active'];
    }

    /** P2 public registration (synchronous); returns the HTTP status */
    protected function p2(string $username): int
    {
        return $this->client()->postWithToken('register/post', $this->p2Data($username), [], 'register')->status;
    }

    /** P4 Admin edit (synchronous) */
    protected function p4(array $c, array $change, ?HttpClient $sa = null): int
    {
        return ($sa ?? $this->staff('sa'))->postWithToken('customers/edit-post', $this->p4Data($c, $change))->status;
    }

    /** an HTTP POST in a separate process (concurrency), with the session cookies and CSRF token of $client */
    protected function bgPost(HttpClient $client, string $route, array $data, string $tokenPage): array
    {
        $data['csrf_token'] ??= $client->token($tokenPage);
        $cookie = implode('; ', array_map(static fn($k, $v): string => $k . '=' . $v, array_keys($client->cookies), $client->cookies));
        return static::$app->execStart(sprintf('$ch = curl_init(%s); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => %s, CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded", "Cookie: " . %s], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90]); curl_exec($ch); $result = "HTTP " . curl_getinfo($ch, CURLINFO_RESPONSE_CODE);',
            var_export($client->url($route), true), var_export(http_build_query($data), true), var_export($cookie, true)));
    }

    // ------------------------------------------------------------------ barriers

    protected static function pause(string $point): void
    {
        touch(static::$app->dir . '/wp2_probe/u0_pause_' . $point);
    }

    protected static function arrived(string $point): bool
    {
        return is_file(static::$app->dir . '/wp2_probe/u0_arrived_' . $point);
    }

    protected static function go(string $point): void
    {
        @unlink(static::$app->dir . '/wp2_probe/u0_pause_' . $point);
        touch(static::$app->dir . '/wp2_probe/u0_go_' . $point);
    }

    protected static function waitUntil(callable $cond, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($cond()) {
                return true;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        return (bool) $cond();
    }

    protected static function running(array $h): bool
    {
        return (bool) proc_get_status($h['proc'])['running'];
    }

    /** a second connection to the disposable database (owner D-U0-7) */
    protected static function secondConnection(): \PDO
    {
        $pdo = FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword(), static::$db->name);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** sessions of the disposable database waiting on a lock (metadata / table / user lock) */
    protected static function lockWaiters(string $like = '%lock%'): int
    {
        return (int) static::$db->value("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ? AND STATE LIKE ?", [static::$db->name, $like]);
    }

    protected static function userLockWaiters(): int
    {
        return (int) static::$db->value("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ? AND (STATE LIKE '%user lock%' OR STATE = 'User lock')", [static::$db->name]);
    }

    protected static function identityLockName(): string
    {
        return 'nuxr_identity_' . substr(sha1(static::$db->name), 0, 20);
    }
}
