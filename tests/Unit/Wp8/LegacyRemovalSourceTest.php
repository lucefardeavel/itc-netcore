<?php
namespace Reseller\Tests\Unit\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP8 A5 (R-WP8-CARRY-01) T9 source guard: every direct device removal in system/controllers is reached only after an
 * ownership gate in the same branch, ServiceGate::guardCustomer (OD-11) or CustomerService::assertDeletable (P6), and
 * that gate comes before every effect of the branch (hook, device call, row write / delete, notification):
 *   P1 plan.php "delete"          guard on the recharge row's customer before run_hook('delete_customer_active_plan')
 *   P2 customers.php "deactivate" guard on the route customer before the recharge lookup
 *   P3 home.php deactivate        guard on the logged-in customer AND on the matched recharge row's customer
 * P4 (cron expiry) is outside system/controllers; P5 / P6 keep their existing gates. The checker is mutation-checked:
 * removing or moving any of the gates is detected.
 */
final class LegacyRemovalSourceTest extends TestCase
{
    private const GATE = '/ServiceGate::guardCustomer\(|CustomerService::assertDeletable\(/';
    private const EFFECT = '/run_hook\(|->remove_customer\(|->save\(\)|->delete\(\)|Message::send|_log\(/';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function src(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::root() . '/' . $rel));
    }

    /** the branch (switch case or else-if arm) that contains offset $pos: [start, text up to $pos] */
    private static function branch(string $src, int $pos): string
    {
        $start = 0;
        if (preg_match_all('/\n\s*case \'[^\']+\':|\}\s*else\s*if\s*\(/', substr($src, 0, $pos), $m, PREG_OFFSET_CAPTURE)) {
            $start = (int) end($m[0])[1];
        }
        return substr($src, $start, $pos - $start);
    }

    /** @return list<string> violations of one controller source */
    public static function violations(string $rel, string $src): array
    {
        $out = [];
        preg_match_all('/->remove_customer\(/', $src, $calls, PREG_OFFSET_CAPTURE);
        foreach ($calls[0] as [, $pos]) {
            $line = substr_count(substr($src, 0, $pos), "\n") + 1;
            $branch = self::branch($src, $pos);
            if (!preg_match(self::GATE, $branch, $g, PREG_OFFSET_CAPTURE)) {
                $out[] = "{$rel}:{$line} remove_customer without an ownership gate in its branch";
                continue;
            }
            if (preg_match(self::EFFECT, $branch, $e, PREG_OFFSET_CAPTURE) && $e[0][1] < $g[0][1]) {
                $out[] = "{$rel}:{$line} an effect ({$e[0][0]}) precedes the ownership gate";
            }
        }
        return $out;
    }

    /** @return array<string,string> controller => source */
    private static function controllers(): array
    {
        $out = [];
        foreach (glob(self::root() . '/system/controllers/*.php') as $f) {
            $out['system/controllers/' . basename($f)] = self::src('system/controllers/' . basename($f));
        }
        return $out;
    }

    /** the source of one named branch, or null when it is missing */
    private static function arm(string $src, string $start, string $end): ?string
    {
        $a = strpos($src, $start);
        if ($a === false) {
            return null;
        }
        $b = strpos($src, $end, $a + strlen($start));
        return substr($src, $a, ($b === false ? strlen($src) : $b) - $a);
    }

    /** position of $needle in $hay, or -1 */
    private static function at(?string $hay, string $needle): int
    {
        $p = $hay === null ? false : strpos($hay, $needle);
        return $p === false ? -1 : $p;
    }

    /** @return list<string> placement failures of the three A5 gates in the given sources (exact plan placement) */
    public static function placement(string $plan, string $customers, string $home): array
    {
        $f = [];
        $p1 = self::arm($plan, "case 'delete':", "case 'edit-post':");
        $g = self::at($p1, "ServiceGate::guardCustomer(\$d['customer_id'], 'planDelete', 'plan/list')");
        if ($g < 0 || $g > self::at($p1, 'run_hook(') || $g < self::at($p1, 'find_one($id)')) {
            $f[] = 'P1: gate on the recharge row customer, after the row lookup, before the hook';
        }
        // D-A5-1: the device target is exactly the row's customer (no User::_info() session fallback), checked before the
        // hook; and the resolved customer is that customer right before the device call
        $id1 = self::at($p1, "if ((int) \$d['customer_id'] <= 0 || !ORM::for_table('tbl_customers')->find_one((int) \$d['customer_id'])) {");
        if ($id1 < 0 || $id1 > self::at($p1, 'run_hook(')) {
            $f[] = 'P1: customer id must be positive and existing, before the hook';
        }
        $same1 = self::at($p1, "if (!\$c || (int) \$c['id'] !== (int) \$d['customer_id']) {");
        if ($same1 < 0 || $same1 < self::at($p1, 'User::_info(') || $same1 > self::at($p1, '->remove_customer(')) {
            $f[] = 'P1: the resolved device target is the row customer, before the device';
        }
        $p2 = self::arm($customers, "case 'deactivate':", "case 'sync':");
        $g = self::at($p2, "ServiceGate::guardCustomer(\$id_customer, 'customerDeactivate', 'customers/view/' . (int) \$id_customer)");
        if ($g < 0 || $g < self::at($p2, 'Csrf::check(') || $g > self::at($p2, "ORM::for_table('tbl_user_recharges')")) {
            $f[] = 'P2: gate on the route customer, after CSRF, before the recharge lookup';
        }
        $id2 = self::at($p2, "if (!ctype_digit((string) \$id_customer) || (int) \$id_customer <= 0 || !ORM::for_table('tbl_customers')->find_one((int) \$id_customer)) {");
        if ($id2 < 0 || $id2 > self::at($p2, "ORM::for_table('tbl_user_recharges')")) {
            $f[] = 'P2: canonical, positive, existing customer id, before the recharge lookup';
        }
        $same2 = self::at($p2, "if (!\$c || (int) \$c['id'] !== (int) \$id_customer || (int) \$b['customer_id'] !== (int) \$id_customer) {");
        if ($same2 < 0 || $same2 < self::at($p2, 'User::_info(') || $same2 > self::at($p2, '->remove_customer(')) {
            $f[] = 'P2: the resolved device target and the recharge row are the route customer, before the device';
        }
        $p3 = self::arm($home, "isset(\$_GET['deactivate'])", "\n}\n");
        $own = self::at($p3, "ServiceGate::guardCustomer(\$user['id'], 'portalDeactivate', 'home')");
        $row = self::at($p3, "ServiceGate::guardCustomer(\$bill['customer_id'], 'portalDeactivate', 'home')");
        if ($own < 0 || $row < 0 || max($own, $row) > self::at($p3, 'Package::getDevice(') || min($own, $row) < self::at($p3, 'findOne()')) {
            $f[] = 'P3: gates on the logged-in customer and on the matched row customer, after the row lookup, before the device';
        }
        return $f;
    }

    public function testEveryControllerRemovalIsGatedBeforeAnyEffect(): void
    {
        $all = [];
        $calls = 0;
        foreach (self::controllers() as $rel => $src) {
            $calls += preg_match_all('/->remove_customer\(/', $src);
            $all = array_merge($all, self::violations($rel, $src));
        }
        $this->assertSame(5, $calls, 'P1, P2, P3, P5, P6 (P4 is cron)');
        $this->assertSame([], $all);
    }

    public function testTheThreeA5GatesAreExactlyWhereThePlanSays(): void
    {
        $this->assertSame([], self::placement(self::src('system/controllers/plan.php'), self::src('system/controllers/customers.php'), self::src('system/controllers/home.php')));
        // P3 stays POST-only (RouteMap dashboard#deactivate) and reads the recharge id from the query as before
        $home = self::arm(self::src('system/controllers/home.php'), "isset(\$_GET['deactivate'])", "\n}\n");
        $this->assertStringContainsString("'dashboard#deactivate' => ['POST' => 'customer']", self::src('system/autoload/Reseller/Security/RouteMap.php'));
        $this->assertStringContainsString("where('id', \$_GET['deactivate'])->where('username', \$user['username'])", (string) $home);
    }

    /** mutation check: both checkers are run on every mutated source; each removed or late gate is detected */
    public function testTheCheckersDetectMutations(): void
    {
        $src = ['plan' => self::src('system/controllers/plan.php'), 'customers' => self::src('system/controllers/customers.php'), 'home' => self::src('system/controllers/home.php')];
        $rel = ['plan' => 'system/controllers/plan.php', 'customers' => 'system/controllers/customers.php', 'home' => 'system/controllers/home.php'];
        $g1 = '[^\n]*ServiceGate::guardCustomer\(\$d\[\'customer_id\'\], \'planDelete\'[^\n]*\n';
        $g2 = '[^\n]*ServiceGate::guardCustomer\(\$id_customer, \'customerDeactivate\'[^\n]*\n';
        $mutations = [
            'P1 removed' => ['plan', "/{$g1}/", ''],
            'P1 after the hook' => ['plan', "/({$g1})((?:[^\n]*\n)*?\s*run_hook\(\'delete_customer_active_plan\'\);[^\n]*\n)/", '$2$1'],
            'P2 removed' => ['customers', "/{$g2}/", ''],
            'P2 after the lookup' => ['customers', "/({$g2})((?:[^\n]*\n)*?\s*\\\$b = ORM::for_table\(\'tbl_user_recharges\'\)[^\n]*\n)/", '$2$1'],
            'P1 id check removed' => ['plan', '/[^\n]*\(int\) \$d\[\'customer_id\'\] <= 0 \|\|[^\n]*\n/', "if (false) {\n"],
            'P1 identity check removed' => ['plan', '/[^\n]*if \(!\$c \|\| \(int\) \$c\[\'id\'\] !== \(int\) \$d\[\'customer_id\'\]\) \{\n/', "if (false) {\n"],
            'P2 id check removed' => ['customers', '/[^\n]*if \(!ctype_digit\(\(string\) \$id_customer\)[^\n]*\n/', "if (false) {\n"],
            'P2 identity check removed' => ['customers', '/[^\n]*if \(!\$c \|\| \(int\) \$c\[\'id\'\] !== \(int\) \$id_customer[^\n]*\n/', "if (false) {\n"],
            'P3 own gate removed' => ['home', '/[^\n]*ServiceGate::guardCustomer\(\$user\[\'id\'\], \'portalDeactivate\'[^\n]*\n/', ''],
            'P3 row gate removed' => ['home', '/[^\n]*ServiceGate::guardCustomer\(\$bill\[\'customer_id\'\], \'portalDeactivate\'[^\n]*\n/', ''],
            'P3 both removed' => ['home', '/[^\n]*ServiceGate::guardCustomer\([^\n]*\'portalDeactivate\'[^\n]*\n/', ''],
        ];
        $this->assertSame([], self::placement($src['plan'], $src['customers'], $src['home']), 'clean before the mutations');
        foreach ($mutations as $name => [$file, $re, $to]) {
            $mut = $src;
            $mut[$file] = preg_replace($re, $to, $src[$file], -1, $n);
            $this->assertGreaterThanOrEqual(1, $n, "{$name}: mutation applied");
            $found = array_merge(self::placement($mut['plan'], $mut['customers'], $mut['home']), self::violations($rel[$file], $mut[$file]));
            $this->assertNotSame([], $found, "{$name}: detected");
        }
    }
}
