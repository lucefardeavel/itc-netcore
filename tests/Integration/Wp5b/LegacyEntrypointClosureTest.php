<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * WP5B §1 controller / route closure over HTTP: E1 admin recharge (reseller-owned -> activation/create, zero /
 * balance / API refused; unassigned zero needs service.recharge_free, balance is exactly-once), E8 / E9 / E18
 * through the locked balance journal (POST + CSRF + nonce, replay-safe), POST-only payment state changes,
 * E19-E21 re-provision through LegacyReprovisionService, the activation reverse route.
 */
final class LegacyEntrypointClosureTest extends Wp2HttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (['enable_balance' => 'yes', 'allow_balance_transfer' => 'yes', 'minimum_transfer' => '0'] as $k => $v) {
            if ((int) static::$db->value('SELECT COUNT(*) FROM tbl_appconfig WHERE setting = ?', [$k]) === 0) {
                static::$db->insertRow('tbl_appconfig', ['setting' => $k, 'value' => $v]);
            } else {
                static::$db->pdo->prepare('UPDATE tbl_appconfig SET value = ? WHERE setting = ?')->execute([$v, $k]);
            }
        }
    }

    private function balance(string $customer): string
    {
        return (string) static::$db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$this->cid($customer)]);
    }

    private function grants(string $customer): int
    {
        return (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid($customer)]);
    }

    private static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function testAdminRechargeOfAResellerOwnedCustomerContinuesAtTheActivation(): void
    {
        $c = $this->staff('ad');
        $before = $this->snapshot();
        static::$app->clearProbes();
        foreach (['plan/recharge-confirm', 'plan/recharge-post'] as $route) {
            $r = $c->postWithToken($route, ['id_customer' => $this->cid('cA1'), 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => 'cash']);
            $this->assertSame(302, $r->status, $route);
            $this->assertStringContainsString('activation/create', (string) $r->location(), $route);
            $this->assertStringContainsString('customer=' . $this->cid('cA1'), (string) $r->location());
        }
        $r = $c->get('customers/recharge/' . $this->cid('cA1') . '/' . static::$seed->planId, ['token' => $c->token()]);
        $this->assertStringContainsString('activation/create', (string) $r->location());
        $this->assertUnchanged($before, 'the legacy action writes nothing');
        $this->assertSame([], static::$app->probe('device'));
        $refusals = count($this->auditRows('service.refused'));
        foreach (['zero', 'balance'] as $using) {
            $r = $c->postWithToken('plan/recharge-post', ['id_customer' => $this->cid('cA1'), 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => $using]);
            $this->assertStringNotContainsString('activation/create', (string) $r->location(), "{$using} is refused, not redirected");
        }
        $this->assertSame($refusals + 2, count($this->auditRows('service.refused')));
        $token = $this->apiToken('ad');
        $before = $this->snapshot();
        $r = $this->client()->api('POST', 'plan/recharge-post', ['id_customer' => $this->cid('cA1'), 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => 'cash'], $token);
        $this->assertDenied($r, 'API recharge of a reseller-owned customer');
        $this->assertUnchanged($before, 'refusals write nothing (audit excluded)');
    }

    public function testAdminBalanceRechargeOfAnUnassignedCustomerIsExactlyOnce(): void
    {
        $c = $this->staff('ad');
        $before = [$this->balance('cNULL'), $this->grants('cNULL')];
        $nonce = self::nonce();
        $post = ['id_customer' => $this->cid('cNULL'), 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => 'balance', 'nuxr_nonce' => $nonce];
        $c->postWithToken('plan/recharge-post', $post);
        $c->postWithToken('plan/recharge-post', $post);
        $this->assertSame(number_format((float) $before[0] - 1000, 2, '.', ''), $this->balance('cNULL'), 'debited once');
        $this->assertSame($before[1] + 1, $this->grants('cNULL'), 'granted once');
        $this->assertCount(1, static::$db->rows("SELECT id FROM tbl_payment_gateway WHERE gateway_trx_id = ?", ['balance:admin:' . $this->sid('ad') . ':' . $nonce]));
        // a missing nonce is refused before any debit
        $c->postWithToken('plan/recharge-post', ['nuxr_nonce' => ''] + $post);
        $this->assertSame([number_format((float) $before[0] - 1000, 2, '.', ''), $before[1] + 1], [$this->balance('cNULL'), $this->grants('cNULL')]);
    }

    public function testCustomerBalancePurchaseGiftAndTransferArePostOnlyAndReplaySafe(): void
    {
        $plan = static::$seed->planId;
        $c = $this->customer('cNULL');
        // order/pay and order/buy are POST only
        $this->assertSame(405, $c->get('order/pay/0/' . $plan)->status);
        $this->assertSame(405, $c->get('order/buy/0/' . $plan)->status);
        // E8: buy with the balance "gateway" is the balance purchase itself
        $b0 = $this->balance('cNULL');
        $g0 = $this->grants('cNULL');
        $nonce = self::nonce();
        $c->postWithToken('order/buy/0/' . $plan, ['gateway' => 'balance', 'nuxr_nonce' => $nonce], [], 'order/package');
        $c->postWithToken('order/pay/0/' . $plan, ['nuxr_nonce' => $nonce], [], 'order/package');
        $this->assertSame(number_format((float) $b0 - 1000, 2, '.', ''), $this->balance('cNULL'), 'E8 debited once');
        $this->assertSame($g0 + 1, $this->grants('cNULL'));
        // E9: gift to an unassigned friend, replay-safe
        $b1 = $this->balance('cNULL');
        $f0 = $this->grants('cNULL2');
        $nonce = self::nonce();
        foreach ([1, 2] as $i) {
            $c->postWithToken('order/send/0/' . $plan, ['send' => 'plan', 'username' => 'cNULL2', 'nuxr_nonce' => $nonce], [], 'order/package');
        }
        $this->assertSame(number_format((float) $b1 - 1000, 2, '.', ''), $this->balance('cNULL'), 'E9 debited once');
        $this->assertSame($f0 + 1, $this->grants('cNULL2'), 'the friend was granted once');
        // E9 to a reseller-owned friend: refused, nothing written
        $b2 = $this->balance('cNULL');
        $c->postWithToken('order/send/0/' . $plan, ['send' => 'plan', 'username' => 'cA1', 'nuxr_nonce' => self::nonce()], [], 'order/package');
        $this->assertSame($b2, $this->balance('cNULL'));
        // E18: transfer, replay-safe; negative / zero / reseller-owned refused
        $t0 = $this->balance('cNULL2');
        $nonce = self::nonce();
        foreach ([1, 2] as $i) {
            $c->postWithToken('home', ['send' => 'balance', 'username' => 'cNULL2', 'balance' => '25', 'nuxr_nonce' => $nonce], [], 'home');
        }
        $this->assertSame(number_format((float) $b2 - 25, 2, '.', ''), $this->balance('cNULL'), 'E18 once');
        $this->assertSame(number_format((float) $t0 + 25, 2, '.', ''), $this->balance('cNULL2'));
        foreach (['-50', '0', '1e2'] as $bad) {
            $c->postWithToken('home', ['send' => 'balance', 'username' => 'cNULL2', 'balance' => $bad, 'nuxr_nonce' => self::nonce()], [], 'home');
        }
        $c->postWithToken('home', ['send' => 'balance', 'username' => 'cA1', 'balance' => '5', 'nuxr_nonce' => self::nonce()], [], 'home');
        $this->assertSame([number_format((float) $b2 - 25, 2, '.', ''), number_format((float) $t0 + 25, 2, '.', '')], [$this->balance('cNULL'), $this->balance('cNULL2')]);
    }

    public function testPaymentCheckAndCancelArePostOnly(): void
    {
        $id = static::$db->insertRow('tbl_payment_gateway', ['username' => 'cNULL', 'user_id' => $this->cid('cNULL'), 'gateway' => 'wp5bnone', 'plan_id' => static::$seed->planId,
            'plan_name' => 'p', 'routers_id' => 0, 'routers' => 'wp2_router', 'price' => '1000', 'pg_url_payment' => 'http://pay.invalid/x', 'created_date' => date('Y-m-d H:i:s'), 'status' => 1]);
        $c = $this->customer('cNULL');
        foreach (['check', 'cancel'] as $step) {
            $this->assertSame(405, $c->get('order/view/' . $id . '/' . $step)->status, $step);
        }
        $this->assertSame('1', (string) static::$db->value('SELECT status FROM tbl_payment_gateway WHERE id = ?', [$id]));
        $this->assertSame(200, $c->get('order/view/' . $id)->status, 'viewing stays GET');
        $c->postWithToken('order/view/' . $id . '/cancel', [], [], 'order/package');
        $this->assertSame('4', (string) static::$db->value('SELECT status FROM tbl_payment_gateway WHERE id = ?', [$id]));
    }

    public function testResellerOwnedReprovisionGoesThroughTheService(): void
    {
        $c = $this->staff('ad');
        static::$app->clearProbes();
        $billing = static::$db->rows('SELECT id, plan_id, expiration, time, status FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id', [$this->cid('cA1')]);
        $r = $c->postWithToken('customers/sync/' . $this->cid('cA1'), [], [], 'customers/view/' . $this->cid('cA1'));
        $this->assertSame(302, $r->status);
        $this->assertNotSame([], static::$app->probe('device'), 'E19b: the device sync ran (service permit)');
        $audit = $this->auditRows('reprovision.admin_sync');
        $this->assertStringContainsString('"outcome":"DONE"', (string) end($audit)['after_json']);
        $this->assertSame($billing, static::$db->rows('SELECT id, plan_id, expiration, time, status FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id', [$this->cid('cA1')]));
        // the owner Agent still has no sync capability
        $this->assertSame(403, $this->staff('A')->postWithToken('customers/sync/' . $this->cid('cA1'), [], [], 'customers/view/' . $this->cid('cA1'))->status);
        // E21: identity edit by the Admin; re-activation refused
        static::$db->pdo->prepare("UPDATE tbl_customers SET status = 'Inactive' WHERE id = ?")->execute([$this->cid('cA1')]);
        $row = static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$this->cid('cA1')])[0];
        $edit = ['id' => $row['id'], 'username' => $row['username'], 'fullname' => $row['fullname'], 'account_type' => $row['account_type'], 'password' => 'edited-pass-1',
            'pppoe_username' => (string) $row['pppoe_username'], 'pppoe_password' => (string) $row['pppoe_password'], 'pppoe_ip' => (string) $row['pppoe_ip'], 'email' => $row['email'],
            'address' => $row['address'], 'phonenumber' => $row['phonenumber'], 'service_type' => $row['service_type'], 'coordinates' => $row['coordinates'], 'status' => 'Active'];
        $c->postWithToken('customers/edit-post', $edit, [], 'customers/edit/' . $row['id']);
        $this->assertSame([$row['password'], 'Inactive'], array_values(static::$db->rows('SELECT password, status FROM tbl_customers WHERE id = ?', [$row['id']])[0]), 're-activation refused, nothing saved');
        $c->postWithToken('customers/edit-post', ['status' => 'Inactive'] + $edit, [], 'customers/edit/' . $row['id']);
        $this->assertSame('edited-pass-1', (string) static::$db->value('SELECT password FROM tbl_customers WHERE id = ?', [$row['id']]), 'E21 through the service');
        $this->assertNotSame([], $this->auditRows('reprovision.identity_edit'));
        static::$db->pdo->prepare("UPDATE tbl_customers SET status = 'Active', password = ? WHERE id = ?")->execute([Wp2Seed::customerPassword('cA1'), $row['id']]);
    }

    public function testTheActivationReverseRouteIsAdminOnlyAndPostOnly(): void
    {
        $ref = 'ACT-0000000000000001';
        $this->assertSame(405, $this->staff('ad')->get('activation/reverse/' . $ref)->status);
        foreach (['A', 'A1', 'rp'] as $staff) {
            $r = $this->staff($staff)->postWithToken('activation/reverse/' . $ref, ['reason' => 'not allowed here'], [], 'dashboard');
            $this->assertSame(403, $r->status, $staff);
        }
        $r = $this->staff('ad')->postWithToken('activation/reverse/' . $ref, ['reason' => 'unknown activation'], [], 'dashboard');
        $this->assertSame(302, $r->status, 'Admin reaches the service (NOT_FOUND -> redirect)');
        $token = $this->apiToken('ad');
        $this->assertDenied($this->client()->api('POST', 'activation/reverse/' . $ref, ['reason' => 'via the API'], $token), 'no API reversal');
    }
}
