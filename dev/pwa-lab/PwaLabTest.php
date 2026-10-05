<?php
namespace Reseller\Tests\Lab;

// WP7 PWA LAB ONLY (test tooling, owner decision OD-W7-4). Not part of any PHPUnit suite: run explicitly by
// dev/pwa-lab/lab.sh inside the isolated nuxbill-reseller-test compose project. It seeds a disposable database,
// builds the disposable application copy (tests/Support/AppServer) and serves it twice with `php -S`:
//   root installation:         0.0.0.0:8099  (docroot = the app copy)                -> http://localhost:18099/
//   subdirectory installation: 0.0.0.0:8098  (docroot = a parent with billing/ link) -> http://localhost:18098/billing/
// The host publishes both ports on 127.0.0.1 only, so the browser origin is http://localhost:<port> - a secure context.
// Production TLS, production data and any non-test container are never touched. Stops when <state>/pwalab-stop exists.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

final class PwaLabTest extends RadiusHttpTestCase
{
    private const STATE = '/pwalab-state';

    private function plan(string $name, string $type, string $price, int $down, int $up): int
    {
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => "bw {$down}M/{$up}M", 'rate_down' => $down, 'rate_down_unit' => 'Mbps', 'rate_up' => $up, 'rate_up_unit' => 'Mbps']);
        return static::$db->insertRow('tbl_plans', [
            'name_plan' => $name, 'id_bw' => $bw, 'price' => $price, 'type' => $type, 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
    }

    private function cust(string $u, string $name, string $type, ?string $owner, ?string $sales): int
    {
        return static::$db->insertRow('tbl_customers', [
            'username' => $u, 'password' => 'lab-' . $u, 'fullname' => $name, 'address' => 'بغداد', 'phonenumber' => '07701' . substr((string) crc32($u), 0, 6),
            'email' => $u . '@example.invalid', 'service_type' => $type, 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active',
            'balance' => 0, 'created_by' => static::$seed->staff['ad'], 'auto_renewal' => 0,
            'manager_id' => $owner === null ? null : static::$seed->staff[$owner], 'assigned_sales_id' => $sales === null ? null : static::$seed->staff[$sales],
        ]);
    }

    private function activate(string $who, int $customer, int $plan): string
    {
        $c = $this->staff($who);
        $page = $c->get('activation/create', ['customer' => $customer, 'plan' => $plan]);
        preg_match('/name="expected_action" value="([A-Z_]+)"/', $page->body, $a);
        preg_match('/name="nonce" value="([0-9a-f]{32})"/', $page->body, $n);
        preg_match('/name="preview" value="([0-9a-f]{64})"/', $page->body, $f);
        $this->assertNotEmpty($a, "preview for {$customer}: " . $page->excerpt());
        $r = $c->postWithToken('activation/create-post', ['customer_id' => $customer, 'plan_id' => $plan, 'expected_action' => $a[1], 'nonce' => $n[1], 'preview' => $f[1] ?? '']);
        preg_match('#(ACT-[0-9A-F]{16})#', $r->headers['location'][0] ?? '', $m);
        $this->assertNotEmpty($m, 'activation created: ' . $r->excerpt());
        return $m[1];
    }

    public function testServePwaLab(): void
    {
        $db = static::$db;
        $db->exec("DELETE FROM tbl_appconfig WHERE setting IN ('new_version_notify', 'CompanyName')");
        $db->insertRow('tbl_appconfig', ['setting' => 'new_version_notify', 'value' => 'disable']);
        $db->insertRow('tbl_appconfig', ['setting' => 'CompanyName', 'value' => 'شبكة النور للإنترنت']);
        $p1 = $this->plan('Hotspot 4M - 30 يوم', 'Hotspot', '20', 4, 1);
        $p2 = $this->plan('PPPoE Home 10M', 'PPPOE', '35', 10, 2);
        $a = static::$seed->staff['A'];
        $b = static::$seed->staff['B'];
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\$A = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'A')->find_one()->as_array(), 'CLI');
foreach ([[{$p1}, '10'], [{$p2}, '25']] as [\$p, \$c]) {
    \\Reseller\\Finance\\PricingService::setBaseCost(\$ad, \$p, \$c);
    \\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, \$p, true);
}
\\Reseller\\Finance\\PricingService::setChildWholesale(\$ad, {$a}, {$p1}, '12');
\\Reseller\\Finance\\PricingService::setChildWholesale(\$A, {$b}, {$p1}, '15');
\\Reseller\\Finance\\TransferService::fund(\$ad, {$a}, '750', 'initial provider funding', 'F-pwalab-a');
\\Reseller\\Finance\\TransferService::transferToChild(\$A, {$a}, {$b}, '100', 'top-up', 'T-pwalab-b');
\$result = 'ok';
PHP);
        $this->assertSame('ok', $out, json_encode($out));
        $ids = [
            'mohammed' => $this->cust('mohammed.h', 'محمد عبد الله الحسيني', 'Hotspot', 'A', null),
            'fatima' => $this->cust('fatima.z', 'فاطمة الزهراء', 'PPPoE', 'A', 'A1'),
            'karrar' => $this->cust('karrar.b', 'كرار باسم', 'Hotspot', 'B', null),
            'legacy' => $this->cust('legacy.user', 'Legacy Unassigned User', 'Hotspot', null, null),
        ];
        $refs = ['mohammed' => $this->activate('A', $ids['mohammed'], $p1)];

        // production-like APP_URL (config.sample.php): protocol + Host + dirname(SCRIPT_NAME), so the same copy serves
        // the root installation and the /billing/ subdirectory installation correctly (lab copy only)
        $dir = static::$app->dir;
        $cfg = (string) file_get_contents($dir . '/config.php');
        $derive = '$baseDir = rtrim(dirname($_SERVER[\'SCRIPT_NAME\'] ?? \'/\'), \'/\\\\\');' . "\n" . 'define(\'APP_URL\', $protocol . $host . $baseDir);';
        $cfg = (string) preg_replace_callback("/define\\('APP_URL', [^;]+;/", static fn(): string => $derive, $cfg, 1);
        file_put_contents($dir . '/config.php', $cfg);
        $parent = $dir . '-sub';
        @mkdir($parent, 0777, true);
        @symlink($dir, $parent . '/billing');

        $staff = [];
        foreach (['sa', 'ad', 'A', 'B', 'A1', 'rp'] as $u) {
            $staff[$u] = Wp2Seed::password($u);
        }
        $procs = [];
        foreach ([['0.0.0.0:8099', $dir], ['0.0.0.0:8098', $parent]] as [$listen, $root]) {
            $procs[] = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $dir . '/wp2_sessions', '-d', 'error_log=' . $dir . '/wp2_probe/php_error.log', '-d', 'log_errors=1',
                '-S', $listen, '-t', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/wp2_probe/lab_server.log', 'a']],
                $pipes, $root, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
        }
        file_put_contents(self::STATE . '/pwalab-ready.json', json_encode([
            'root' => 'http://localhost:18099/', 'sub' => 'http://localhost:18098/billing/', 'dir' => $dir,
            'staff' => $staff, 'customers' => $ids, 'refs' => $refs, 'plans' => [$p1, $p2], 'staff_ids' => static::$seed->staff,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        for ($t = 0; $t < 6 * 3600 && !is_file(self::STATE . '/pwalab-stop'); $t++) {
            sleep(1);
        }
        foreach ($procs as $p) {
            proc_terminate($p);
            proc_close($p);
        }
        $this->assertTrue(true);
    }
}
