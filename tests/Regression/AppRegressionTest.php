<?php
namespace Reseller\Tests\Regression;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Migration\Outcome;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;

/**
 * Application regression: the unchanged legacy application behaves identically on an unmigrated
 * and on a WP1-migrated database (functional flows only; no security probing).
 */
final class AppRegressionTest extends IntegrationTestCase
{
    private const HARNESS = <<<'PHP'
<?php
// test-only harness (copied into a disposable app copy, never into the repository)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$step = $argv[1];
chdir(__DIR__);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require __DIR__ . '/init.php';
$admin = ORM::for_table('tbl_users')->where('user_type', 'SuperAdmin')->find_one()->as_array();
$out = [];
switch ($step) {
    case 'customer-add': // same ORM path as customers/add-post
        $d = ORM::for_table('tbl_customers')->create();
        $d->username = 'reg_cust'; $d->password = 'secret1'; $d->fullname = 'Regression Customer';
        $d->address = ''; $d->created_by = $admin['id']; $d->phonenumber = '0700'; $d->email = 'r@example.invalid';
        $d->service_type = 'Hotspot'; $d->account_type = 'Personal'; $d->coordinates = '';
        $d->save();
        $row = ORM::for_table('tbl_customers')->find_one($d->id())->as_array();
        $out = [
            'manager_id' => array_key_exists('manager_id', $row) ? $row['manager_id'] : 'COLUMN_ABSENT',
            'assigned_sales_id' => array_key_exists('assigned_sales_id', $row) ? $row['assigned_sales_id'] : 'COLUMN_ABSENT',
            'created_by' => (int) $row['created_by'],
        ];
        break;
    case 'recharge': // Package::rechargeUser with the Dummy device (no network)
        $r = ORM::for_table('tbl_routers')->create();
        $r->name = 'reg_router'; $r->ip_address = '127.0.0.1:9'; $r->username = 'u'; $r->password = 'p'; $r->description = ''; $r->enabled = 1; $r->save();
        $bw = ORM::for_table('tbl_bandwidth')->create();
        $bw->name_bw = 'reg_bw'; $bw->rate_down = 10; $bw->rate_down_unit = 'Mbps'; $bw->rate_up = 5; $bw->rate_up_unit = 'Mbps'; $bw->save();
        $p = ORM::for_table('tbl_plans')->create();
        $p->name_plan = 'reg_plan'; $p->id_bw = $bw->id(); $p->price = '1000'; $p->type = 'Hotspot'; $p->typebp = 'Unlimited';
        $p->limit_type = 'Time_Limit'; $p->time_limit = 0; $p->time_unit = 'Hrs'; $p->data_limit = 0; $p->data_unit = 'MB';
        $p->validity = 30; $p->validity_unit = 'Days'; $p->shared_users = 1; $p->routers = 'reg_router'; $p->is_radius = 0;
        $p->pool = ''; $p->enabled = 1; $p->prepaid = 'yes'; $p->plan_type = 'Personal'; $p->device = 'Dummy'; $p->save();
        $c = ORM::for_table('tbl_customers')->where('username', 'reg_cust')->find_one();
        $inv = Package::rechargeUser($c['id'], 'reg_router', $p->id(), 'Recharge', 'Admin');
        $rc = ORM::for_table('tbl_user_recharges')->where('customer_id', $c['id'])->find_one();
        $out = [
            'invoice_prefix' => substr((string) $inv, 0, 4),
            'recharge_status' => $rc['status'], 'recharge_plan' => $rc['namebp'],
            'transactions' => ORM::for_table('tbl_transactions')->where('user_id', $c['id'])->count(),
            'balance' => ORM::for_table('tbl_customers')->find_one($c['id'])['balance'],
        ];
        break;
    case 'delete-paths': // legacy deletes of an unreferenced user and plan still succeed
        $u = ORM::for_table('tbl_users')->create();
        $u->username = 'reg_del_user'; $u->fullname = 'x'; $u->password = sha1('x'); $u->user_type = 'Agent'; $u->status = 'Active';
        $u->creationdate = date('Y-m-d H:i:s'); $u->last_login = date('Y-m-d H:i:s'); $u->save();
        $uid = $u->id();
        ORM::for_table('tbl_users')->find_one($uid)->delete();
        $pl = ORM::for_table('tbl_plans')->where('name_plan', 'reg_plan')->find_one();
        $plan = ORM::for_table('tbl_plans')->create();
        foreach ($pl->as_array() as $k => $v) { if ($k !== 'id') { $plan->$k = $v; } }
        $plan->name_plan = 'reg_plan_del'; $plan->save();
        $pid = $plan->id();
        ORM::for_table('tbl_plans')->find_one($pid)->delete();
        $out = ['user_deleted' => ORM::for_table('tbl_users')->find_one($uid) === false, 'plan_deleted' => ORM::for_table('tbl_plans')->find_one($pid) === false];
        break;
}
echo json_encode($out);
PHP;

    private function appCopy(TestDatabase $db): string
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/wp1_app_' . bin2hex(random_bytes(4));
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($root) + 1);
            if (preg_match('#^(\.git|tests|dev)(/|$)#', $rel)) {
                continue;
            }
            $dest = $dir . '/' . $rel;
            if ($item->isDir()) {
                @mkdir($dest, 0777, true);
            } else {
                @mkdir(dirname($dest), 0777, true);
                copy($item->getPathname(), $dest);
            }
        }
        $pw = var_export(TestDatabase::rootPassword(), true);
        file_put_contents($dir . '/config.php', "<?php\n\$protocol = 'http://';\n\$host = \$_SERVER['HTTP_HOST'] ?? '127.0.0.1';\ndefine('APP_URL', \$protocol . \$host);\n\$_app_stage = 'Live';\n\$db_host = " . var_export($db->host, true) . ";\n\$db_user = 'root';\n\$db_pass = {$pw};\n\$db_name = " . var_export($db->name, true) . ";\nerror_reporting(E_ERROR);\nini_set('display_errors', 0);\n");
        file_put_contents($dir . '/wp1_harness.php', self::HARNESS);
        @mkdir($dir . '/ui/compiled', 0777, true);
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** @return array<string,mixed> */
    private function flows(TestDatabase $db): array
    {
        $dir = $this->appCopy($db);
        try {
            $res = [];
            foreach (['customer-add', 'recharge', 'delete-paths'] as $step) {
                $r = RunnerProcess::exec([PHP_BINARY, $dir . '/wp1_harness.php', $step]);
                $this->assertSame(0, $r['exit'], "{$step}: {$r['raw']}");
                $res[$step] = json_decode(trim(preg_replace('/^.*?(\{.*\})\s*$/s', '$1', $r['raw'])), true);
            }
            // cron (cwd system/, as documented); the reg_cust plan is not expired, so cron is a no-op run
            $cron = proc_open([PHP_BINARY, 'cron.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir . '/system');
            $cronOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $res['cron_exit'] = proc_close($cron);
            $res['cron_finished'] = str_contains($cronOut, 'Cron job finished');
            // HTTP boot of public pages
            $port = 19000 + random_int(0, 999);
            $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2);
            try {
                for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                    usleep(100000);
                }
                foreach (['admin', 'login', 'register'] as $route) {
                    $body = (string) @file_get_contents("http://127.0.0.1:{$port}/?_route={$route}", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]));
                    $code = 0;
                    foreach ($http_response_header ?? [] as $h) {
                        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                            $code = (int) $m[1];
                        }
                    }
                    $res['http'][$route] = ['status' => $code, 'html' => str_contains($body, '<html'), 'fatal' => str_contains($body, 'Fatal error')];
                }
            } finally {
                proc_terminate($srv);
                proc_close($srv);
            }
            return $res;
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testLegacyFlowsIdenticalBeforeAndAfterMigration(): void
    {
        $plain = $this->newDatabase(FixtureBuilder::V2, 'regplain');
        $migrated = $this->newDatabase(FixtureBuilder::V2, 'regmig');
        $this->assertOutcome(Outcome::OK, $this->run_($migrated, 'migrate'));

        $before = $this->flows($plain);
        $after = $this->flows($migrated);

        // legacy outcomes
        $this->assertSame('INV-', $after['recharge']['invoice_prefix']);
        $this->assertSame('on', $after['recharge']['recharge_status']);
        $this->assertSame(1, $after['recharge']['transactions']);
        $this->assertTrue($after['delete-paths']['user_deleted']);
        $this->assertTrue($after['delete-paths']['plan_deleted']);
        $this->assertSame(0, $after['cron_exit']);
        $this->assertTrue($after['cron_finished']);
        foreach ($after['http'] as $route => $h) {
            $this->assertSame(200, $h['status'], $route);
            $this->assertTrue($h['html'], $route);
            $this->assertFalse($h['fatal'], $route);
        }
        // new customers created by legacy code are NULL-owned
        $this->assertNull($after['customer-add']['manager_id']);
        $this->assertNull($after['customer-add']['assigned_sales_id']);
        $this->assertSame('COLUMN_ABSENT', $before['customer-add']['manager_id']);

        // identical behaviour apart from the (owner-accepted, C25) presence of the two NULL fields
        unset($before['customer-add']['manager_id'], $before['customer-add']['assigned_sales_id'], $after['customer-add']['manager_id'], $after['customer-add']['assigned_sales_id']);
        $this->assertSame($before, $after);
    }
}
