<?php
namespace Reseller\Tests\Unit\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP8 S7 (direct MikroTik driver classification), source side, both engines (no lab):
 *   T-S1 Class A stays empty: the accepted reseller activation never instantiates or calls a device driver
 *   T-S2 every grant method of the three MikroTik drivers (add_customer / sync_customer / change_username) starts with
 *        LegacyReprovisionService::assertDeviceAllowed (the WP5B default deny); WP8 S7-FC: Package::rechargeUser() runs
 *        that guard before any write of the recharge cycle
 *   T-S3 the device call-site inventory of system/ and radius.php is pinned (a new call site fails until classified)
 *        and every grant / removal call site has its authority gate earlier in its own branch
 *   T-S4 the static Mikrotik class (system/autoload/Mikrotik.php) has no caller (Class D)
 * Every check is mutation-checked (the checker is run on mutated sources and must fail).
 */
final class DriverClassificationSourceTest extends TestCase
{
    private const DRIVERS = ['MikrotikHotspot', 'MikrotikPppoe', 'MikrotikVpn'];
    private const METHODS = 'add_customer|sync_customer|change_username|remove_customer|connect_customer|disconnect_customer|online_customer|add_plan|update_plan|remove_plan|add_pool|update_pool|remove_pool|getClient';

    /**
     * The classified call sites: file => list of [method, branch anchor, gate needle (null = no gate by design), class /
     * reason]. Order = order of appearance of that method in the file.
     */
    private const SITES = [
        'system/autoload/Package.php' => [
            ['add_customer', 'public static function rechargeUser', 'ServiceGate::isBlocked', 'C: core grant funnel (OD-11)'],
            ['add_customer', 'public static function rechargeUser', 'ServiceGate::isBlocked', 'C: core grant funnel (OD-11)'],
        ],
        'system/controllers/plan.php' => [
            ['sync_customer', "case 'sync':", 'ServiceGate::isBlockedCustomerId(', 'C / B: bulk sync (owned -> service)'],
            ['add_customer', "case 'sync':", 'ServiceGate::isBlockedCustomerId(', 'C / B: bulk sync (owned -> service)'],
            ['remove_customer', "case 'delete':", "guardCustomer(\$d['customer_id'], 'planDelete'", 'C: P1 (A5 + D-A5-1)'],
            ['remove_customer', "case 'edit-post':", "'planEditPost'", 'C: P5'],
            ['add_customer', "case 'edit-post':", "'planEditPost'", 'C: P5'],
            ['sync_customer', "case 'edit-post':", "'planEditPost'", 'C: P5 (WP4 expiry sync)'],
            ['add_customer', "case 'extend':", "'planExtend'", 'C: extend'],
        ],
        'system/controllers/customers.php' => [
            ['remove_customer', "case 'deactivate':", "'customerDeactivate'", 'C: P2 (A5 + D-A5-1)'],
            ['sync_customer', "case 'sync':", "'customerSync'", 'C / B: sync (owned -> service)'],
            ['add_customer', "case 'sync':", "'customerSync'", 'C / B: sync (owned -> service)'],
            ['remove_customer', "case 'delete':", 'CustomerService::assertDeletable(', 'C: P6'],
            ['change_username', "case 'edit-post':", 'ServiceGate::isBlockedCustomerId(', 'C: R2 unassigned identity edit (owned -> service)'],
            ['add_customer', "case 'edit-post':", 'ServiceGate::isBlockedCustomerId(', 'C: R2 unassigned identity edit (owned -> service)'],
        ],
        'system/controllers/home.php' => [
            ['sync_customer', "if (isset(\$_GET['sync'])", "'homeSync'", 'C / B: portal sync (owned -> service)'],
            ['add_customer', "if (isset(\$_GET['sync'])", "'homeSync'", 'C / B: portal sync (owned -> service)'],
            ['add_customer', "} else if (!empty(_get('extend')))", "'homeExtend'", 'C: portal extend'],
            ['remove_customer', "} else if (isset(\$_GET['deactivate'])", "guardCustomer(\$bill['customer_id'], 'portalDeactivate'", 'C: P3 (A5)'],
            ['connect_customer', "if (!empty(\$_SESSION['nux-mac'])", null, 'C: Hotspot session helper, no grant (OBS-W8-3)'],
            ['disconnect_customer', "if (!empty(\$_SESSION['nux-mac'])", null, 'C: Hotspot session helper, no grant (OBS-W8-3)'],
        ],
        'system/controllers/accounts.php' => [
            ['add_customer', "case 'change-password-post':", "'accountsChangePassword'", 'C / B: password change (owned -> service)'],
        ],
        'system/controllers/login.php' => [
            ['add_customer', "case 'activation':", "'loginVoucherOnly'", 'C: voucher-only login'],
            ['add_customer', "case 'activation':", "'loginVoucherOnly'", 'C: voucher-only login'],
            ['connect_customer', "case 'activation':", "'loginVoucherActivation'", 'C: voucher activation session'],
        ],
        'system/cron.php' => [
            ['remove_customer', 'Fetch user recharge details', null, 'P4: canonical expiry (reseller auto-renew refused separately)'],
        ],
        'system/controllers/autoload.php' => [
            ['online_customer', '', null, 'C: read'],
            ['online_customer', '', null, 'C: read'],
        ],
        'system/controllers/autoload_user.php' => [
            ['online_customer', '', null, 'C: read'],
        ],
        'system/controllers/services.php' => [
            ['add_plan', '', null, 'C: admin plan definition'], ['add_plan', '', null, 'C'], ['remove_plan', '', null, 'C'], ['add_plan', '', null, 'C'],
            ['update_plan', '', null, 'C'], ['remove_plan', '', null, 'C'], ['add_plan', '', null, 'C'], ['update_plan', '', null, 'C'],
            ['remove_plan', '', null, 'C'], ['add_plan', '', null, 'C'], ['update_plan', '', null, 'C'],
        ],
        'system/controllers/pool.php' => [
            ['remove_pool', '', null, 'C: admin pool'], ['update_pool', '', null, 'C'], ['add_pool', '', null, 'C'], ['update_pool', '', null, 'C'], ['update_pool', '', null, 'C'],
        ],
        'system/controllers/routers.php' => [
            ['getClient', '', null, 'C: admin test connection'], ['getClient', '', null, 'C: admin test connection'],
        ],
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function src(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::root() . '/' . $rel));
    }

    /** @return array<string,string> every PHP file of system/ (drivers excluded) and radius.php */
    private static function sources(): array
    {
        $out = ['radius.php' => self::src('radius.php')];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/system', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen(self::root()) + 1);
            if (str_ends_with($rel, '.php') && !str_starts_with($rel, 'system/devices/') && !str_starts_with($rel, 'system/vendor/')) {
                $out[$rel] = self::src($rel);
            }
        }
        ksort($out);
        return $out;
    }

    /** @return list<array{0:string,1:int}> [method, offset] of the device method calls in one source, in order */
    private static function calls(string $src): array
    {
        preg_match_all('/\)->(' . self::METHODS . ')\(/', $src, $m, PREG_OFFSET_CAPTURE);
        return array_map(static fn(array $x): array => [$x[0], $x[1]], $m[1]);
    }

    /** @return list<string> inventory / gate failures for the given sources */
    public static function inventory(array $sources): array
    {
        $f = [];
        foreach ($sources as $rel => $src) {
            $calls = self::calls($src);
            $expected = self::SITES[$rel] ?? [];
            $got = array_column($calls, 0);
            $want = array_column($expected, 0);
            if ($got !== $want) {
                $f[] = "{$rel}: device call sites " . json_encode($got) . ' != classified ' . json_encode($want);
                continue;
            }
            foreach ($expected as $i => [$method, $anchor, $gate, $why]) {
                if ($gate === null) {
                    continue;
                }
                $pos = $calls[$i][1];
                $a = $anchor === '' ? 0 : strrpos(substr($src, 0, $pos), $anchor);
                if ($a === false) {
                    $f[] = "{$rel}: {$method} #{$i} is not inside its branch '{$anchor}'";
                    continue;
                }
                if (!str_contains(substr($src, $a, $pos - $a), $gate)) {
                    $f[] = "{$rel}: {$method} #{$i} ({$why}) has no gate '{$gate}' before it in its branch";
                }
            }
        }
        foreach (array_diff(array_keys(self::SITES), array_keys($sources)) as $missing) {
            $f[] = "{$missing}: classified file missing";
        }
        return $f;
    }

    /** @return list<string> T-S2 failures for the given driver sources */
    public static function grantGuards(array $drivers): array
    {
        $f = [];
        foreach ($drivers as $name => $src) {
            foreach (['add_customer', 'sync_customer', 'change_username'] as $m) {
                if (!preg_match('/function ' . $m . '\([^)]*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*([^\n]+)/', $src, $x)) {
                    $f[] = "{$name}::{$m} missing";
                    continue;
                }
                if (!str_starts_with(trim($x[1]), '\\Reseller\\Domain\\LegacyReprovisionService::assertDeviceAllowed(')) {
                    $f[] = "{$name}::{$m} does not start with the default-deny guard";
                }
            }
        }
        return $f;
    }

    /** @return list<string> T-S1 failures: a device reached from the reseller activation code */
    public static function classA(array $sources): array
    {
        $f = [];
        foreach ($sources as $rel => $src) {
            if (!str_starts_with($rel, 'system/autoload/Reseller/Activation/')) {
                continue;
            }
            if (preg_match('/Package::getDevice\(|new \$[a-zA-Z_]+\[.device.\]|system\/devices\/|' . '->(add_customer|sync_customer|remove_customer|change_username)\(/', $src, $m)) {
                $f[] = "{$rel}: device access in the activation code ({$m[0]})";
            }
        }
        return $f;
    }

    /** @return list<string> T-S4 failures: a caller of the static Mikrotik class */
    public static function staticMikrotik(array $sources): array
    {
        $f = [];
        foreach ($sources as $rel => $src) {
            if ($rel !== 'system/autoload/Mikrotik.php' && preg_match('/\bMikrotik::[a-zA-Z_]+\(|new Mikrotik\(/', $src)) {
                $f[] = "{$rel}: calls the static Mikrotik class";
            }
        }
        return $f;
    }

    private static function drivers(): array
    {
        $out = [];
        foreach (self::DRIVERS as $d) {
            $out[$d] = self::src("system/devices/{$d}.php");
        }
        return $out;
    }

    public function testClassAStaysEmpty(): void
    {
        $this->assertSame([], self::classA(self::sources()));
        $svc = self::src('system/autoload/Reseller/Activation/ActivationService.php');
        $this->assertGreaterThanOrEqual(2, substr_count($svc, "\$plan['device'] !== 'Radius'"), 'activation requires the Radius device');
    }

    public function testEveryDriverGrantMethodStartsWithTheDefaultDenyGuard(): void
    {
        $this->assertSame([], self::grantGuards(self::drivers()));
    }

    public function testTheCallSiteInventoryIsPinnedAndEveryGrantOrRemovalIsGated(): void
    {
        $this->assertSame([], self::inventory(self::sources()));
        $n = array_sum(array_map('count', self::SITES));
        $this->assertSame(47, $n, 'classified call sites');
        // Class B: the only driver construction outside the controllers / core is the re-provision service, and it sets
        // the private per-call permit before any device call and clears it in finally
        $lrs = self::src('system/autoload/Reseller/Domain/LegacyReprovisionService.php');
        $devices = substr($lrs, (int) strpos($lrs, 'private static function devices('));
        $permit = strpos($devices, 'self::$permit = [');
        $new = strpos($devices, "new \$plan['device']()");
        $clear = strpos($devices, "} finally {\n            self::\$permit = null;");
        $this->assertNotFalse($permit);
        $this->assertNotFalse($new);
        $this->assertNotFalse($clear);
        $this->assertLessThan($new, $permit, 'the permit is set before the driver is built');
        $this->assertGreaterThan($new, $clear, 'the permit is cleared in finally after the device calls');
        $this->assertMatchesRegularExpression('/private static \?array \$permit = null;/', $lrs, 'private, never a public permit API');
    }

    /**
     * WP8 S7-FC (OD-S7-FC-1): rechargeUser() runs the device default deny once, for non-voucher grants, after the
     * balance returns and BEFORE every write of the recharge cycle (hook, recharge rows, transaction, device, messages),
     * and a refusal returns false (never swallowed). @return list<string> violations
     */
    public static function preWrite(string $package): array
    {
        $start = strpos($package, 'public static function rechargeUser(');
        $body = substr($package, (int) $start, (int) strpos($package, 'public static function', (int) $start + 30) - (int) $start);
        $guard = "/if \\(!\\\$isVoucher\\) \\{\\s*try \\{\\s*\\\\Reseller\\\\Domain\\\\LegacyReprovisionService::assertDeviceAllowed\\(\\\$c\\);\\s*\\} catch \\(\\\\Reseller\\\\Radius\\\\ProvisionFailure \\\$e\\) \\{\\s*return false;\\s*\\}\\s*\\}/";
        if (preg_match_all($guard, $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return ['the pre-write refusal (non-voucher assertDeviceAllowed, catch -> return false) is not present exactly once'];
        }
        $at = $m[0][0][1];
        $out = [];
        if ($at < (int) strpos($body, 'return self::rechargeCustomBalance(')) {
            $out[] = 'the pre-write refusal runs before the balance branches';
        }
        foreach (['run_hook("recharge_user")', '$b->save();', '$d->save();', "ORM::for_table('tbl_transactions')->create()", '->add_customer($c, $p)', 'Message::send'] as $write) {
            $first = strpos($body, $write);
            if ($first === false || $first < $at) {
                $out[] = "the pre-write refusal is not before {$write}";
            }
        }
        return $out;
    }

    public function testTheRechargeFunnelRefusesBeforeAnyWrite(): void
    {
        $this->assertSame([], self::preWrite(self::src('system/autoload/Package.php')));
    }

    public function testThePreWriteCheckerDetectsMutations(): void
    {
        $pkg = self::src('system/autoload/Package.php');
        $guard = "        if (!\$isVoucher) {\n            try {\n                \\Reseller\\Domain\\LegacyReprovisionService::assertDeviceAllowed(\$c);\n            } catch (\\Reseller\\Radius\\ProvisionFailure \$e) {\n                return false;\n            }\n        }\n";
        $this->assertSame(1, substr_count($pkg, $guard), 'the pinned pre-write block');
        $hook = "        run_hook(\"recharge_user\");\n";
        $cases = [
            'removed' => str_replace($guard, '', $pkg),
            'refusal swallowed' => str_replace("                return false;\n            }\n        }\n", "            }\n        }\n", $pkg),
            'moved after the hook' => str_replace($hook, $hook . $guard, str_replace($guard, '', $pkg)),
            'voucher-only judged too (scope widened)' => str_replace('if (!$isVoucher) {' . "\n            try {", "if (true) {\n            try {", $pkg),
        ];
        foreach ($cases as $name => $mut) {
            $this->assertNotSame($pkg, $mut, "{$name}: mutation applied");
            $this->assertNotSame([], self::preWrite($mut), "{$name}: detected");
        }
    }

    public function testTheStaticMikrotikClassHasNoCaller(): void
    {
        $this->assertFileExists(self::root() . '/system/autoload/Mikrotik.php');
        $this->assertSame([], self::staticMikrotik(self::sources()));
    }

    /** mutation check: each checker detects a removed gate, a new call site, a missing guard and a Class A / D breach */
    public function testTheCheckersDetectMutations(): void
    {
        $src = self::sources();
        $cases = [
            'P1 gate removed' => ['system/controllers/plan.php', "/\\\\Reseller\\\\Domain\\\\ServiceGate::guardCustomer\\(\\\$d\\['customer_id'\\], 'planDelete'[^\\n]*\\n/", ''],
            'P2 gate removed' => ['system/controllers/customers.php', "/[^\\n]*'customerDeactivate', 'customers[^\\n]*\\n/", ''],
            'portal sync gate removed' => ['system/controllers/home.php', "/[^\\n]*'homeSync', 'home'\\);\\n/", ''],
            'core funnel gate removed' => ['system/autoload/Package.php', '/ServiceGate::isBlockedUsername\(\(string\) \$channel\) : \\\\Reseller\\\\Domain\\\\ServiceGate::isBlockedCustomerId\(\$id_customer\)/', 'false : false'],
            // WP9 U0 N-07: the voucher-only gate records its refusal without the typed code (one line: removing it removes the gate)
            'voucher-only gate removed' => ['system/controllers/login.php', "/[^\\n]*ServiceGate::refused\\('loginVoucherOnly'\\);[^\\n]*\\n/", ''],
            'new unclassified call site' => ['system/controllers/plan.php', "/case 'view':/", "case 'view':\n        (new \$p['device'])->add_customer(\$c, \$p);"],
        ];
        foreach ($cases as $name => [$file, $re, $to]) {
            $mut = $src;
            $mut[$file] = preg_replace($re, $to, $src[$file], 1, $n);
            $this->assertSame(1, $n, "{$name}: mutation applied");
            $this->assertNotSame([], self::inventory($mut), "{$name}: detected");
        }
        $drv = self::drivers();
        foreach (self::DRIVERS as $d) {
            $mut = $drv;
            $mut[$d] = preg_replace('/\\\\Reseller\\\\Domain\\\\LegacyReprovisionService::assertDeviceAllowed\(\$customer\);/', '', $drv[$d], 1, $n);
            $this->assertSame(1, $n, "{$d} guard removed: applied");
            $this->assertNotSame([], self::grantGuards($mut), "{$d} guard removed: detected");
        }
        $mut = $src;
        $mut['system/autoload/Reseller/Activation/ActivationService.php'] .= "\n// \$dvc = Package::getDevice(\$plan);\n";
        $this->assertNotSame([], self::classA($mut), 'Class A breach detected');
        $mut = $src;
        $mut['system/controllers/plan.php'] .= "\nMikrotik::getClient('a', 'b', 'c');\n";
        $this->assertNotSame([], self::staticMikrotik($mut), 'static Mikrotik caller detected');
    }
}
