<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * SEC-06 / C21: object and list scoping. Agent = own subtree (closure), Sales = explicitly assigned
 * customers of its manager only, NULL-owned customers = SuperAdmin/Admin only, Report = nothing.
 * Out-of-scope and nonexistent ids produce the same response (no existence oracle).
 */
final class ScopeIsolationTest extends Wp2HttpTestCase
{
    /** staff => [visible customers, invisible customers] */
    private const SCOPES = [
        'A' => [['cA1', 'cA2', 'cA0', 'cB'], ['cC1', 'cNULL', 'cNULL2', 'cF']],
        'B' => [['cB'], ['cA1', 'cA2', 'cA0', 'cC1', 'cNULL', 'cF']],
        'C' => [['cC1'], ['cA1', 'cA2', 'cA0', 'cB', 'cNULL', 'cF']],
        'A1' => [['cA1'], ['cA2', 'cA0', 'cB', 'cC1', 'cNULL', 'cF']],
        'A2' => [['cA2'], ['cA1', 'cA0', 'cB', 'cC1', 'cNULL', 'cF']],
        'C1' => [['cC1'], ['cA1', 'cA2', 'cA0', 'cB', 'cNULL', 'cF']],
        'E' => [[], ['cA1', 'cC1', 'cNULL']],
        'E1' => [[], ['cA1', 'cC1', 'cNULL']],
        'sa' => [['cA1', 'cB', 'cC1', 'cNULL', 'cF'], []],
        'ad' => [['cA1', 'cB', 'cC1', 'cNULL', 'cF'], []],
    ];

    public function testCustomerObjectScopeWithoutExistenceOracle(): void
    {
        foreach (self::SCOPES as $staff => [$visible, $invisible]) {
            $c = $this->staff($staff);
            $missing = $c->get('customers/view/9999999');
            foreach ($visible as $cust) {
                $r = $c->get('customers/view/' . $this->cid($cust));
                $this->assertSame(200, $r->status, "{$staff} → {$cust}");
                $this->assertStringContainsString('Customer ' . $cust, $r->body);
                $this->assertSame(200, $c->get('customers/viewu/' . $cust)->status, "{$staff} viewu {$cust}");
            }
            foreach ($invisible as $cust) {
                foreach (['customers/view/' . $this->cid($cust), 'customers/viewu/' . $cust] as $route) {
                    $r = $c->get($route);
                    $this->assertNotSame(200, $r->status, "{$staff} must not see {$cust} ({$route})");
                    $this->assertSame([$missing->status, $missing->location()], [$r->status, $r->location()], "{$staff} {$route}: same response as a nonexistent id");
                    $this->assertStringNotContainsString('Customer ' . $cust, $r->body);
                }
            }
        }
    }

    public function testCustomerListsSearchAndPickersAreScoped(): void
    {
        foreach (self::SCOPES as $staff => [$visible, $invisible]) {
            if (in_array($staff, ['E', 'E1'], true)) {
                continue; // no customer.read scope at all (403), covered by the route matrix
            }
            $c = $this->staff($staff);
            foreach (['Active'] as $filter) {
                $list = $c->get('customers/list', ['filter' => $filter]);
                $search = $c->get('search_user', ['query' => 'cA']);
                $select = $c->get('autoload/customer_select2');
                $maps = $c->get('maps/customer');
                foreach ($visible as $cust) {
                    $this->assertStringContainsString('>' . $cust . '<', $list->body, "{$staff} list shows {$cust}");
                    $this->assertStringContainsString('"' . $cust . ' - ', $select->body, "{$staff} select2 shows {$cust}");
                }
                foreach ($invisible as $cust) {
                    $this->assertStringNotContainsString('>' . $cust . '<', $list->body, "{$staff} list must not show {$cust}");
                    $this->assertStringNotContainsString('>' . $cust . '<', $search->body, "{$staff} search must not show {$cust}");
                    $this->assertStringNotContainsString('"' . $cust . ' - ', $select->body, "{$staff} select2 must not show {$cust}");
                    $this->assertStringNotContainsString('Customer ' . $cust, $maps->body);
                }
            }
            // LIKE wildcards do not widen the search
            $this->assertStringNotContainsString('<li>', preg_replace('/<li><a[^>]*>(' . implode('|', array_map('preg_quote', $visible ?: ['__none__'])) . ')<\/a><\/li>/', '', $c->get('search_user', ['query' => '%%'])->body));
        }
    }

    public function testDerivedObjectsInvoicesRechargesVouchersAreScoped(): void
    {
        $tr = static::$seed->transactions;
        foreach (['A' => [['cA1', 'cB'], ['cC1', 'cNULL', 'voucher0']], 'A1' => [['cA1'], ['cB', 'cC1', 'cNULL', 'voucher0']], 'C1' => [['cC1'], ['cA1', 'cNULL']]] as $staff => [$ok, $no]) {
            $c = $this->staff($staff);
            foreach ($ok as $k) {
                $this->assertSame(200, $c->get('plan/view/' . $tr[$k])->status, "{$staff} invoice {$k}");
            }
            $missing = $c->get('plan/view/9999999');
            foreach ($no as $k) {
                $r = $c->get('plan/view/' . $tr[$k]);
                $this->assertSame([$missing->status, $missing->location()], [$r->status, $r->location()], "{$staff} invoice {$k} indistinguishable from missing");
                $r = $c->postWithToken('plan/print', ['id' => $tr[$k]]);
                $this->assertNotSame(200, $r->status, "{$staff} print {$k}");
                $r = $c->postWithToken('plan/view/' . $tr[$k] . '/send');
                $this->assertNotSame(200, $r->status);
            }
            $active = $c->get('plan/list');
            foreach ($ok as $k) {
                if (isset(static::$seed->recharges[$k])) {
                    $this->assertStringContainsString('>' . $k . '<', $active->body, "{$staff} active list shows {$k}");
                }
            }
            foreach ($no as $k) {
                if (isset(static::$seed->recharges[$k])) {
                    $this->assertStringNotContainsString('>' . $k . '<', $active->body, "{$staff} active list hides {$k}");
                }
            }
        }
        // vouchers: Agent sees vouchers generated in its subtree (closure), Sales its own; never the legacy root
        $v = static::$seed->vouchers;
        $cases = ['A' => [['A', 'B', 'A1'], ['C', 'ad']], 'B' => [['B'], ['A', 'C', 'ad', 'A1']], 'A1' => [['A1'], ['A', 'B', 'C', 'ad']]];
        foreach ($cases as $staff => [$ok, $no]) {
            $c = $this->staff($staff);
            $list = $c->get('plan/voucher');
            foreach ($ok as $g) {
                $this->assertStringContainsString(static::$seed->voucherCode($g), $list->body, "{$staff} sees voucher of {$g}");
                $this->assertSame(200, $c->get('plan/voucher-view/' . $v[$g])->status);
            }
            foreach ($no as $g) {
                $this->assertStringNotContainsString(static::$seed->voucherCode($g), $list->body, "{$staff} must not see voucher of {$g}");
                $this->assertNotSame(200, $c->get('plan/voucher-view/' . $v[$g])->status, "{$staff} voucher-view {$g}");
            }
        }
    }

    public function testMessagingIsScopedAllOrNothing(): void
    {
        $a1 = $this->staff('A1');
        static::$app->clearProbes();
        $r = $a1->postWithToken('message/send-post', ['id_customer' => $this->cid('cA2'), 'message' => 'hi', 'via' => 'sms']);
        $this->assertSame(302, $r->status);
        $this->assertSame([], static::$app->probe('sms'), 'no SMS to an out-of-scope customer');
        $inbox = (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers_inbox');
        $r = $a1->postWithToken('message/send_bulk_selected', ['customer_ids' => [$this->cid('cA1'), $this->cid('cC1')], 'message' => 'x', 'message_type' => 'inbox']);
        $this->assertStringContainsString('"status":"error"', $r->body, 'mixed scope selection rejected entirely');
        $this->assertSame($inbox, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers_inbox'));
        $r = $a1->postWithToken('message/send_bulk_selected', ['customer_ids' => [$this->cid('cA1')], 'message' => 'x', 'message_type' => 'inbox']);
        $this->assertStringContainsString('"status":"success"', $r->body);
        $this->assertSame($inbox + 1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers_inbox'));
    }

    public function testStaffDirectoryIsScoped(): void
    {
        $cases = [
            'A' => [['A', 'B', 'A1', 'A2'], ['C', 'C1', 'sa', 'ad', 'D', 'F', 'E']],
            'A1' => [['A1'], ['A', 'A2', 'C', 'sa']],
            'rp' => [['rp'], ['sa', 'A', 'ad']],
            'ad' => [['ad', 'A', 'C1', 'rp'], ['sa']],
        ];
        foreach ($cases as $staff => [$ok, $no]) {
            $c = $this->staff($staff);
            $list = $c->get('settings/users');
            $missing = $c->get('settings/users-view/9999999');
            foreach ($ok as $u) {
                $this->assertStringContainsString('Staff ' . $u . '<', $list->body . '<', "{$staff} users list shows {$u}") ;
                $this->assertSame(200, $c->get('settings/users-view/' . $this->sid($u))->status, "{$staff} views {$u}");
            }
            foreach ($no as $u) {
                $r = $c->get('settings/users-view/' . $this->sid($u));
                $this->assertSame([$missing->status, $missing->location()], [$r->status, $r->location()], "{$staff} users-view {$u}");
                $this->assertNotSame(200, $c->get('settings/users-edit/' . $this->sid($u))->status, "{$staff} users-edit {$u}");
            }
        }
    }
}
