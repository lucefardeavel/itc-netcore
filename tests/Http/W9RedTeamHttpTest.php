<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9-C red team over HTTP / API (PLAN §12; every negative case has a positive control and must cause no
 * unauthorized business / finance / identity / service state change - AM-W9-2):
 *  #1  cross-reseller IDOR: a foreign id answers exactly like a nonexistent one (no existence oracle, no data)
 *  #2  an unassigned legacy customer is unreachable for reseller staff (activation)
 *  #4  forged / malformed POST ids on activation/create-post
 *  #5  privilege escalation through the staff edit form; Sales on finance / tree mutations
 *  #6  forged money fields on activation/create-post are ignored (the server price is debited)
 *  #8  idempotency: one nonce, a different plan -> refused; another user's nonce never returns that activation
 *  #9  hierarchy move by an Agent (web + API)
 *  #10 / N-10 the API never returns configuration secrets
 *  #13 legacy recharge with junk ids writes nothing
 *  R-FC-1 voucher codes in recharge methods are not visible to reseller staff
 */
final class W9RedTeamHttpTest extends RadiusHttpTestCase
{
    private static int $plan = 0;
    private static int $plan2 = 0;
    private const SECRET = 'w9-red-smtp-secret-61b0';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'w9rt_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        $row = ['id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit', 'time_limit' => 0, 'time_unit' => 'Hrs',
            'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1, 'routers' => 'radius', 'is_radius' => 1, 'pool' => '',
            'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius'];
        self::$plan = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9rt_plan'] + $row);
        self::$plan2 = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9rt_plan2'] + $row);
        $a = static::$seed->staff['A'];
        $p1 = self::$plan;
        $p2 = self::$plan2;
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
foreach ([{$p1}, {$p2}] as \$p) {
    \\Reseller\\Finance\\PricingService::setBaseCost(\$ad, \$p, '10');
    \\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, \$p, true);
}
\\Reseller\\Finance\\TransferService::fund(\$ad, {$a}, '100', 'initial provider funding', 'F-w9rt-fund');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
        static::$db->exec("DELETE FROM tbl_appconfig WHERE setting = 'smtp_pass'");
        static::$db->insertRow('tbl_appconfig', ['setting' => 'smtp_pass', 'value' => self::SECRET]);
    }

    private function form(HttpClient $c, int $customer, int $plan = 0): array
    {
        $plan = $plan ?: self::$plan;
        $page = $c->get('activation/create', ['customer' => $customer, 'plan' => $plan]);
        preg_match('/name="expected_action" value="([A-Z_]+)"/', $page->body, $a);
        preg_match('/name="nonce" value="([0-9a-f]{32})"/', $page->body, $n);
        preg_match('/name="preview" value="([0-9a-f]{64})"/', $page->body, $f);
        $this->assertNotEmpty($f, 'preview: ' . $page->excerpt());
        return ['customer_id' => $customer, 'plan_id' => $plan, 'expected_action' => $a[1], 'nonce' => $n[1], 'preview' => $f[1]];
    }

    private function money(): array
    {
        return $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events']);
    }

    public function testR1ForeignIdAnswersExactlyLikeANonexistentOne(): void
    {
        $a = $this->staff('A');
        $foreign = static::$seed->customers['cC1'];
        $foreignStaff = static::$seed->staff['C'];
        $name = (string) static::$db->value('SELECT fullname FROM tbl_customers WHERE id = ?', [$foreign]);
        foreach (['customers/view/%d', 'customers/edit/%d', 'reseller/detail/%d'] as $route) {
            $id = str_starts_with($route, 'reseller') ? $foreignStaff : $foreign;
            $f = $a->get(sprintf($route, $id));
            $n = $a->get(sprintf($route, 999999));
            $this->assertSame($n->status, $f->status, "{$route}: same status for foreign and nonexistent");
            $this->assertStringNotContainsString($name, $f->body, "{$route}: no foreign data");
            $this->assertStringNotContainsString('cC1', $f->body, "{$route}: no foreign username");
        }
        $own = $a->get(sprintf('customers/view/%d', static::$seed->customers['cA1']));
        $this->assertSame(200, $own->status, 'positive control: own customer');
    }

    public function testR2UnassignedLegacyCustomerIsUnreachableForResellers(): void
    {
        $a = $this->staff('A');
        $before = $this->money();
        $page = $a->get('activation/create', ['customer' => static::$seed->customers['cNULL'], 'plan' => self::$plan]);
        $this->assertDoesNotMatchRegularExpression('/name="preview" value="[0-9a-f]{64}"/', $page->body, 'no confirmation for an unassigned customer');
        $a->postWithToken('activation/create-post', ['customer_id' => static::$seed->customers['cNULL'], 'plan_id' => self::$plan, 'expected_action' => 'NEW', 'nonce' => bin2hex(random_bytes(16))]);
        $this->assertUnchanged($before, 'unassigned legacy customer', ['tbl_logs', 'tbl_reseller_audit_events']);
        $this->assertSame('', trim($a->get('autoload/plan_is_active/' . static::$seed->customers['cNULL'])->body), 'no service metadata');
    }

    public function testR4MalformedAndForeignIdsAreRefusedWithoutChange(): void
    {
        $a = $this->staff('A');
        $valid = $this->form($a, static::$seed->customers['cA1']);
        $before = $this->money();
        foreach ([static::$seed->customers['cC1'], 0, -1, '1e3', '0' . static::$seed->customers['cA1'], '9999999999999999999', ' ' . static::$seed->customers['cC1'], 'abc', '1 OR 1=1'] as $bad) {
            $a->postWithToken('activation/create-post', ['customer_id' => $bad, 'nonce' => bin2hex(random_bytes(16))] + $valid);
        }
        $r = $a->postWithToken('activation/create-post', ['customer_id' => [static::$seed->customers['cA1']], 'nonce' => bin2hex(random_bytes(16))] + $valid);
        $this->assertNotSame(500, $r->status, 'an array id is not an error page');
        foreach ([0, -1, '1e3', 'x', '9999999999999999999'] as $bad) {
            $a->postWithToken('activation/create-post', ['plan_id' => $bad, 'nonce' => bin2hex(random_bytes(16))] + $valid);
        }
        $this->assertUnchanged($before, 'forged ids', ['tbl_logs', 'tbl_reseller_audit_events']);
    }

    public function testR5PrivilegeEscalationThroughStaffEditIsRefused(): void
    {
        $a = $this->staff('A');
        $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events', 'tbl_users']);
        $users = static::$db->rows('SELECT id, username, user_type, root, status, password FROM tbl_users ORDER BY id');
        foreach (['ad', 'sa', 'C', 'A'] as $target) {
            $id = static::$seed->staff[$target];
            $a->postWithToken('settings/users-edit-post', ['id' => $id, 'username' => $target, 'fullname' => 'Staff ' . $target, 'user_type' => 'SuperAdmin', 'status' => 'Active', 'root' => 0], [], 'settings/users');
        }
        $after = static::$db->rows('SELECT id, username, user_type, root, status, password FROM tbl_users ORDER BY id');
        $this->assertSame(array_map(static fn($u) => [$u['user_type'], $u['root'], $u['status'], $u['password']], $users),
            array_map(static fn($u) => [$u['user_type'], $u['root'], $u['status'], $u['password']], $after), 'no role, tree, status or password change');
        $this->assertUnchanged($before, 'staff edit escalation', ['tbl_logs', 'tbl_reseller_audit_events', 'tbl_users']);
        $s = $this->staff('A1');
        foreach (['finance/fund-post', 'finance/withdraw-post', 'finance/debt-limit-post', 'reseller/move-post', 'reseller/status-post'] as $route) {
            $this->assertDenied($s->postWithToken($route, ['manager_id' => static::$seed->staff['A'], 'amount' => '5', 'node_id' => static::$seed->staff['B'], 'parent_id' => '', 'user_id' => static::$seed->staff['B'], 'status' => 'Inactive']), "Sales {$route}");
        }
        $this->assertUnchanged($before, 'Sales mutations', ['tbl_logs', 'tbl_reseller_audit_events', 'tbl_users']);
    }

    public function testR6ForgedMoneyFieldsAreIgnored(): void
    {
        $a = $this->staff('A');
        $customer = static::$seed->customers['cA2'];
        $post = $this->form($a, $customer) + ['price' => '0.01', 'retail_price' => '0', 'wholesale_cost' => '0', 'amount' => '0', 'base_cost' => '0', 'manager_id' => static::$seed->staff['C']];
        $balance = (string) static::$db->value('SELECT balance FROM tbl_manager_wallets WHERE manager_id = ?', [static::$seed->staff['A']]);
        $r = $a->postWithToken('activation/create-post', $post);
        $this->assertSame(302, $r->status, $r->excerpt());
        $act = static::$db->rows('SELECT * FROM tbl_reseller_activations WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$customer])[0];
        $this->assertSame('COMPLETED', $act['status']);
        $this->assertSame(static::$seed->staff['A'], (int) $act['manager_id'], 'the payer is server-derived');
        $this->assertSame(number_format((float) $balance - 10, 2, '.', ''), (string) static::$db->value('SELECT balance FROM tbl_manager_wallets WHERE manager_id = ?', [static::$seed->staff['A']]), 'the server price (base cost 10) is debited');
    }

    public function testR8IdempotencyConflictsAndUserScopedNonces(): void
    {
        $a = $this->staff('A');
        $customer = static::$seed->customers['cA0'];
        $post = $this->form($a, $customer);
        $first = $a->postWithToken('activation/create-post', $post);
        preg_match('#(ACT-[0-9A-F]{16})#', $first->headers['location'][0] ?? '', $m);
        $this->assertNotEmpty($m, $first->excerpt());
        $before = $this->money();
        $a->postWithToken('activation/create-post', ['plan_id' => self::$plan2] + $post); // same nonce, other plan
        $this->assertUnchanged($before, 'same nonce, different plan', ['tbl_logs', 'tbl_reseller_audit_events']);
        $b = $this->staff('B');
        $other = $b->postWithToken('activation/create-post', ['customer_id' => static::$seed->customers['cB']] + $post);
        $this->assertStringNotContainsString($m[1], implode(' ', $other->headers['location'] ?? []) . $other->body, "another user's nonce never returns that activation");
    }

    public function testR9HierarchyMoveByAgentIsRefusedOnWebAndApi(): void
    {
        $web = $this->staff('A');
        $token = $this->apiToken('A'); // (the logins update tbl_users.last_login: taken before the snapshot)
        $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events']);
        $this->assertDenied($web->postWithToken('reseller/move-post', ['node_id' => static::$seed->staff['B'], 'parent_id' => static::$seed->staff['C']]), 'web move');
        $this->assertDenied($this->client()->api('POST', 'reseller/move-post', ['node_id' => static::$seed->staff['B'], 'parent_id' => static::$seed->staff['C']], $token), 'API move');
        $this->assertUnchanged($before, 'move by an Agent');
    }

    public function testN10ApiNeverReturnsConfigurationSecrets(): void
    {
        $dbPass = \Reseller\Tests\Support\TestDatabase::rootPassword();
        foreach (['A', 'ad'] as $who) {
            $token = $this->apiToken($who);
            foreach (['dashboard/view', 'customers/list', 'plan/list', 'reports/by-date', 'settings/users', 'autoload/server', 'routers/list'] as $route) {
                $r = $this->client()->api('GET', $route, [], $token);
                $this->assertStringNotContainsString(self::SECRET, $r->body, "{$who} {$route}: SMTP password");
                $this->assertStringNotContainsString($dbPass, $r->body, "{$who} {$route}: database password");
                $this->assertStringNotContainsString((string) static::$app->appSecret(), $r->body, "{$who} {$route}: application key");
            }
        }
    }

    public function testR13LegacyRechargeWithJunkIdsWritesNothing(): void
    {
        $before = $this->snapshot();
        foreach (['"5abc"', '0', '-1', '"1 OR 1=1"', 'null'] as $id) {
            try {
                $r = static::$app->exec('$result = Package::rechargeUser(' . $id . ', "radius", ' . self::$plan . ', "Recharge", "admin");');
                $this->assertNotSame(true, $r, $id);
                $this->assertFalse(is_string($r) && str_starts_with($r, 'INV'), $id . ': no invoice');
            } catch (\RuntimeException $e) {
                // the legacy path ends the request (alert / redirect) for an unknown customer: also a refusal
                $this->assertStringContainsString('exec failed', $e->getMessage(), $id);
            }
        }
        $this->assertUnchanged($before, 'legacy recharge with junk ids');
    }

    public function testRFC1VoucherCodesInMethodsAreNotVisibleToResellerStaff(): void
    {
        $code = 'W9RFC1' . strtoupper(bin2hex(random_bytes(4)));
        static::$db->insertRow('tbl_user_recharges', ['customer_id' => 0, 'username' => $code, 'plan_id' => self::$plan, 'namebp' => 'w9rt_plan', 'recharged_on' => date('Y-m-d'),
            'recharged_time' => '00:00:00', 'expiration' => date('Y-m-d', time() + 86400), 'time' => '00:00:00', 'status' => 'on', 'method' => 'Voucher - ' . $code, 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 0]);
        static::$db->insertRow('tbl_transactions', ['invoice' => 'INV-W9RFC1', 'username' => $code, 'user_id' => 0, 'plan_name' => 'w9rt_plan', 'price' => '20', 'recharged_on' => date('Y-m-d'),
            'recharged_time' => '00:00:00', 'expiration' => date('Y-m-d', time() + 86400), 'time' => '00:00:00', 'method' => 'Voucher - ' . $code, 'routers' => 'radius', 'type' => 'Hotspot']);
        foreach (['A', 'A1', 'B'] as $who) {
            $c = $this->staff($who);
            foreach (['plan/list', 'reports/by-date', 'reports/daily-report', 'customers/list', 'dashboard'] as $route) {
                $this->assertStringNotContainsString($code, $c->get($route)->body, "{$who} {$route}");
            }
        }
        $this->assertStringContainsString($code, $this->staff('ad')->get('plan/list')->body, 'by design: global staff (who manage vouchers) see it');
    }
}
